<?php
/**
 * Node.js Code Executor
 * Executes JavaScript/Node.js code in a sandboxed environment
 */

/**
 * Execute JavaScript/Node.js code
 * @param string $code JavaScript code to execute
 * @param array $context Context variables (dbContext, request, method, headers, response)
 * @return array Result array with 'success', 'data', 'message', 'error'
 */
function executeNodeCode($code, $context = []) {
    // Check if node is available
    $node_path = trim(shell_exec('which node 2>/dev/null'));
    if (empty($node_path)) {
        return [
            'success' => false,
            'message' => 'Node.js is not installed or not found in PATH',
            'error' => 'Node.js runtime not available'
        ];
    }
    
    // Get database path from settings
    $db_path = null;
    try {
        require_once __DIR__ . '/../../config/config.php';
        $settings = getSettings();
        
        if ($settings['db_type'] === 'sqlite' && !empty($settings['db_config']['sqlite']['path'])) {
            $db_path = $settings['db_config']['sqlite']['path'];
            // Convert relative path to absolute
            if (!file_exists($db_path) || !is_file($db_path)) {
                $db_path = __DIR__ . '/../../' . $db_path;
            }
            // Ensure absolute path
            $db_path = realpath($db_path);
        }
    } catch (Exception $e) {
        // Could not get database path
    }
    
    // Get project root directory
    $project_root = realpath(__DIR__ . '/../..');
    
    // Check and install npm dependencies if needed
    $package_json = $project_root . '/package.json';
    $node_modules = $project_root . '/node_modules';
    $better_sqlite3 = $node_modules . '/better-sqlite3';
    $sqlite3 = $node_modules . '/sqlite3';
    
    // Check if database module is installed
    $db_module_installed = file_exists($better_sqlite3) || file_exists($sqlite3);
    
    if (!$db_module_installed) {
        // Create package.json if it doesn't exist
        if (!file_exists($package_json)) {
            $default_package_json = [
                'name' => 'stockcount-web',
                'version' => '1.0.0',
                'description' => 'StockCount Web Application',
                'dependencies' => []
            ];
            file_put_contents($package_json, json_encode($default_package_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
        // Try to install better-sqlite3 first
        $npm_path = trim(shell_exec('which npm 2>/dev/null'));
        if (!empty($npm_path)) {
            try {
                // Try better-sqlite3 first
                $install_command = sprintf(
                    'cd %s && %s install better-sqlite3 --save --no-audit --no-fund 2>&1',
                    escapeshellarg($project_root),
                    escapeshellarg($npm_path)
                );
                exec($install_command, $output, $return_var);
                
                // If better-sqlite3 failed, try sqlite3
                if ($return_var !== 0 || !file_exists($better_sqlite3)) {
                    $install_command = sprintf(
                        'cd %s && %s install sqlite3 --save --no-audit --no-fund 2>&1',
                        escapeshellarg($project_root),
                        escapeshellarg($npm_path)
                    );
                    exec($install_command, $output, $return_var);
                }
            } catch (Exception $e) {
                // Installation failed, will be handled in Node.js code
            }
        }
    }
    
    // Prepare context as JSON
    $context_data = [
        'dbContext' => serialize($context['dbContext'] ?? null), // Serialize PDO connection info
        'dbPath' => $db_path, // SQLite database file path
        'projectRoot' => $project_root, // Project root directory for npm install
        'request' => $context['request'] ?? [],
        'method' => $context['method'] ?? 'POST',
        'headers' => $context['headers'] ?? [],
        'response' => $context['response'] ?? ['success' => false, 'data' => null, 'message' => '', 'error' => null]
    ];
    
    // Create temporary file with Node.js code
    $temp_file = tempnam(sys_get_temp_dir(), 'node_exec_');
    $js_file = $temp_file . '.js';
    $context_file = $temp_file . '_context.json';
    $result_file = $temp_file . '_result.json';
    
    // Write context to JSON file
    file_put_contents($context_file, json_encode($context_data, JSON_UNESCAPED_UNICODE));
    
    // Create Node.js wrapper code
    // Note: We need to provide database connection through a PHP helper endpoint
    // For now, we'll use a simplified approach with JSON context
    $wrapped_code = <<<JS
const fs = require('fs');
const path = require('path');

// Load context
const contextFile = '{$context_file}';
const resultFile = '{$result_file}';

let context = {};
try {
    context = JSON.parse(fs.readFileSync(contextFile, 'utf8'));
} catch (e) {
    fs.writeFileSync(resultFile, JSON.stringify({
        success: false,
        message: 'Failed to load context: ' + e.message,
        error: e.message
    }));
    process.exit(1);
}

// Initialize response
const response = context.response || {
    success: false,
    data: null,
    message: '',
    error: null
};

// Make context variables available
const request = context.request || {};
const method = context.method || 'POST';
const headers = context.headers || {};

// Database connection info (serialized)
// Note: In production, you might want to use a database connection pool or helper
const dbContextInfo = context.dbContext || null;

// Database helper function
// Automatically installs better-sqlite3 if not available
let dbHelper = null;

function installDbModule() {
    try {
        const { execSync } = require('child_process');
        const path = require('path');
        const projectRoot = context.projectRoot || process.cwd();
        const packageJsonPath = path.join(projectRoot, 'package.json');
        
        // Create package.json if it doesn't exist
        if (!fs.existsSync(packageJsonPath)) {
            const defaultPackageJson = {
                "name": "stockcount-web",
                "version": "1.0.0",
                "description": "StockCount Web Application",
                "dependencies": {}
            };
            fs.writeFileSync(packageJsonPath, JSON.stringify(defaultPackageJson, null, 2));
        }
        
        // Try to install better-sqlite3 first (synchronous, recommended)
        try {
            execSync('npm install better-sqlite3 --save --no-audit --no-fund', {
                stdio: 'pipe',
                timeout: 60000,
                cwd: projectRoot,
                env: { ...process.env, NODE_ENV: 'production' }
            });
            return true;
        } catch (e) {
            // If better-sqlite3 fails, try sqlite3
            try {
                execSync('npm install sqlite3 --save --no-audit --no-fund', {
                    stdio: 'pipe',
                    timeout: 60000,
                    cwd: projectRoot,
                    env: { ...process.env, NODE_ENV: 'production' }
                });
                return true;
            } catch (e2) {
                console.error('Failed to install database modules. Please install manually: npm install better-sqlite3');
                return false;
            }
        }
    } catch (err) {
        console.error('Error installing database modules:', err.message);
        return false;
    }
}

function initDatabase() {
    // Try better-sqlite3 first (synchronous, recommended)
    try {
        const Database = require('better-sqlite3');
        const dbPath = context.dbPath || null;
        if (dbPath && fs.existsSync(dbPath)) {
            dbHelper = new Database(dbPath);
            return true;
        }
    } catch (e) {
        // If better-sqlite3 is not available, try sqlite3
        try {
            const sqlite3 = require('sqlite3');
            const dbPath = context.dbPath || null;
            if (dbPath && fs.existsSync(dbPath)) {
                dbHelper = new sqlite3.Database(dbPath, (err) => {
                    if (err) {
                        console.error('Database connection error:', err);
                        dbHelper = null;
                    }
                });
                return dbHelper !== null;
            }
        } catch (e2) {
            // Module not found - try to install
            if (installDbModule()) {
                // Try again after installation
                try {
                    const Database = require('better-sqlite3');
                    const dbPath = context.dbPath || null;
                    if (dbPath && fs.existsSync(dbPath)) {
                        dbHelper = new Database(dbPath);
                        return true;
                    }
                } catch (e3) {
                    try {
                        const sqlite3 = require('sqlite3');
                        const dbPath = context.dbPath || null;
                        if (dbPath && fs.existsSync(dbPath)) {
                            dbHelper = new sqlite3.Database(dbPath, (err) => {
                                if (err) {
                                    console.error('Database connection error:', err);
                                    dbHelper = null;
                                }
                            });
                            return dbHelper !== null;
                        }
                    } catch (e4) {
                        // Still no module available
                        return false;
                    }
                }
            }
            return false;
        }
    }
    return false;
}

// Initialize database connection
initDatabase();

// Helper function for database queries (async wrapper for sqlite3)
function dbQuery(sql, params = []) {
    return new Promise((resolve, reject) => {
        if (!dbHelper) {
            reject(new Error('Database module not available. Install: npm install better-sqlite3 or npm install sqlite3'));
            return;
        }
        
        // Check if it's better-sqlite3 (synchronous)
        if (dbHelper.prepare) {
            try {
                const stmt = dbHelper.prepare(sql);
                const result = stmt.all(params);
                resolve(result);
            } catch (err) {
                reject(err);
            }
        } else {
            // sqlite3 (async)
            dbHelper.all(sql, params, (err, rows) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(rows);
                }
            });
        }
    });
}

// Helper function for single row query
function dbQueryOne(sql, params = []) {
    return new Promise((resolve, reject) => {
        if (!dbHelper) {
            reject(new Error('Database module not available. Install: npm install better-sqlite3 or npm install sqlite3'));
            return;
        }
        
        if (dbHelper.prepare) {
            try {
                const stmt = dbHelper.prepare(sql);
                const result = stmt.get(params);
                resolve(result);
            } catch (err) {
                reject(err);
            }
        } else {
            dbHelper.get(sql, params, (err, row) => {
                if (err) {
                    reject(err);
                } else {
                    resolve(row);
                }
            });
        }
    });
}

// Helper function for execute (INSERT, UPDATE, DELETE)
function dbExecute(sql, params = []) {
    return new Promise((resolve, reject) => {
        if (!dbHelper) {
            reject(new Error('Database module not available. Install: npm install better-sqlite3 or npm install sqlite3'));
            return;
        }
        
        if (dbHelper.prepare) {
            try {
                const stmt = dbHelper.prepare(sql);
                const result = stmt.run(params);
                resolve({ changes: result.changes, lastInsertRowid: result.lastInsertRowid });
            } catch (err) {
                reject(err);
            }
        } else {
            dbHelper.run(sql, params, function(err) {
                if (err) {
                    reject(err);
                } else {
                    resolve({ changes: this.changes, lastInsertRowid: this.lastInsertRowid });
                }
            });
        }
    });
}

// Execute user code in async context
(async function() {
    try {
        // Execute user code
        {$code}
        
        // Close database connection if opened
        if (dbHelper && dbHelper.close) {
            if (dbHelper.prepare) {
                // better-sqlite3
                dbHelper.close();
            } else {
                // sqlite3 (async close)
                await new Promise((resolve) => {
                    dbHelper.close((err) => {
                        if (err) console.error('Error closing database:', err);
                        resolve();
                    });
                });
            }
        }
        
        // Write result
        fs.writeFileSync(resultFile, JSON.stringify(response, null, 2));
        process.exit(0);
    } catch (error) {
        // Write error result
        fs.writeFileSync(resultFile, JSON.stringify({
            success: false,
            message: 'Error executing code: ' + error.message,
            error: error.message,
            stack: error.stack
        }, null, 2));
        process.exit(1);
    }
})();
JS;
    
    file_put_contents($js_file, $wrapped_code);
    
    // Execute Node.js code with timeout
    $timeout = 30; // seconds
    $command = escapeshellarg($node_path) . ' ' . escapeshellarg($js_file) . ' 2>&1';
    
    // Use proc_open for better control
    $descriptorspec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w']   // stderr
    ];
    
    $process = proc_open($command, $descriptorspec, $pipes);
    
    if (!is_resource($process)) {
        @unlink($js_file);
        @unlink($context_file);
        @unlink($result_file);
        return [
            'success' => false,
            'message' => 'Failed to start Node.js process',
            'error' => 'Process execution failed'
        ];
    }
    
    // Set timeout
    $start_time = time();
    $timed_out = false;
    
    // Wait for process to complete
    while (proc_get_status($process)['running']) {
        if (time() - $start_time > $timeout) {
            proc_terminate($process, SIGTERM);
            sleep(1);
            if (proc_get_status($process)['running']) {
                proc_terminate($process, SIGKILL);
            }
            $timed_out = true;
            break;
        }
        usleep(100000); // 0.1 second
    }
    
    // Close pipes
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $return_value = proc_close($process);
    
    // Read result file
    $result = [
        'success' => false,
        'data' => null,
        'message' => '',
        'error' => null
    ];
    
    if (file_exists($result_file)) {
        try {
            $result_data = json_decode(file_get_contents($result_file), true);
            if (is_array($result_data)) {
                $result = $result_data;
            }
        } catch (Exception $e) {
            $result['message'] = 'Failed to parse result: ' . $e->getMessage();
            $result['error'] = $e->getMessage();
        }
    } else {
        if ($timed_out) {
            $result['message'] = 'Execution timeout (exceeded ' . $timeout . ' seconds)';
            $result['error'] = 'Timeout';
        } elseif ($return_value !== 0) {
            $result['message'] = 'Node.js execution failed';
            $result['error'] = $stderr ?: 'Exit code: ' . $return_value;
        }
    }
    
    // Clean up temporary files
    @unlink($js_file);
    @unlink($context_file);
    @unlink($result_file);
    
    return $result;
}
?>


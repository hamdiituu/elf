/**
 * Node.js Code Execution Server
 * Runs JavaScript code for Cloud Functions and Middlewares
 */

const http = require('http');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

// Load config helper (reads PHP settings.json)
function loadConfig() {
    try {
        const configPath = path.join(__dirname, '../config/settings.json');
        if (fs.existsSync(configPath)) {
            const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
            return {
                dbType: config.db_type || 'sqlite',
                dbPath: config.db_config?.sqlite?.path || null,
                dbConfig: config.db_config?.mysql || null,
                projectRoot: path.join(__dirname, '..')
            };
        }
    } catch (e) {
        // Config file not found or invalid - use defaults
    }
    return {
        dbType: 'sqlite',
        dbPath: path.join(__dirname, '../database/stockcount.db'),
        dbConfig: null,
        projectRoot: path.join(__dirname, '..')
    };
}

// Initialize database connection
let dbHelper = null;
let dbType = 'sqlite';
const config = loadConfig();
dbType = config.dbType;

function initDatabase() {
    if (dbType === 'mysql' && config.dbConfig) {
        try {
            const mysql = require('mysql2/promise');
            dbHelper = mysql.createPool({
                host: config.dbConfig.host || 'localhost',
                port: config.dbConfig.port || 3306,
                database: config.dbConfig.database,
                user: config.dbConfig.username,
                password: config.dbConfig.password || '',
                waitForConnections: true,
                connectionLimit: 5,
                queueLimit: 0
            });
            return true;
        } catch (e) {
            console.error('MySQL init error:', e.message);
            return false;
        }
    } else if (dbType === 'sqlite' && config.dbPath) {
        try {
            const Database = require('better-sqlite3');
            const dbPath = path.isAbsolute(config.dbPath) ? config.dbPath : path.join(config.projectRoot, config.dbPath);
            if (fs.existsSync(dbPath)) {
                dbHelper = new Database(dbPath);
                return true;
            }
        } catch (e) {
            console.error('SQLite init error:', e.message);
            return false;
        }
    }
    return false;
}

// Try to install database module if missing
function installDbModule(moduleName) {
    try {
        const packageJsonPath = path.join(config.projectRoot, 'package.json');
        if (!fs.existsSync(packageJsonPath)) {
            fs.writeFileSync(packageJsonPath, JSON.stringify({
                name: 'stockcount-web',
                version: '1.0.0',
                description: 'StockCount Web Application',
                dependencies: {}
            }, null, 2));
        }
        
        execSync(`npm install ${moduleName} --save --no-audit --no-fund`, {
            stdio: 'pipe',
            timeout: 60000,
            cwd: config.projectRoot
        });
        return true;
    } catch (e) {
        return false;
    }
}

// Initialize database on startup
try {
    initDatabase();
} catch (e) {
    // Try to install modules
    if (dbType === 'mysql') {
        if (installDbModule('mysql2')) {
            initDatabase();
        }
    } else {
        if (installDbModule('better-sqlite3')) {
            initDatabase();
        }
    }
}

// Database helper functions
async function dbQuery(sql, params = []) {
    if (!dbHelper) {
        throw new Error('Database not available');
    }
    
    if (dbType === 'mysql') {
        const [rows] = await dbHelper.execute(sql, params);
        return rows;
    } else {
        const stmt = dbHelper.prepare(sql);
        return stmt.all(params);
    }
}

async function dbQueryOne(sql, params = []) {
    if (!dbHelper) {
        throw new Error('Database not available');
    }
    
    if (dbType === 'mysql') {
        const [rows] = await dbHelper.execute(sql, params);
        return rows.length > 0 ? rows[0] : null;
    } else {
        const stmt = dbHelper.prepare(sql);
        return stmt.get(params) || null;
    }
}

async function dbExecute(sql, params = []) {
    if (!dbHelper) {
        throw new Error('Database not available');
    }
    
    if (dbType === 'mysql') {
        const [result] = await dbHelper.execute(sql, params);
        return {
            changes: result.affectedRows,
            lastInsertRowid: result.insertId
        };
    } else {
        const stmt = dbHelper.prepare(sql);
        const result = stmt.run(params);
        return {
            changes: result.changes,
            lastInsertRowid: result.lastInsertRowid
        };
    }
}

// HTTP Server
const server = http.createServer(async (req, res) => {
    // CORS headers
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');
    
    if (req.method === 'OPTIONS') {
        res.writeHead(200);
        res.end();
        return;
    }
    
    if (req.method !== 'POST') {
        res.writeHead(405, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ success: false, message: 'Method not allowed' }));
        return;
    }
    
    let body = '';
    req.on('data', chunk => {
        body += chunk.toString();
    });
    
    req.on('end', async () => {
        try {
            const requestData = JSON.parse(body);
            const { code, context } = requestData;
            
            if (!code) {
                res.writeHead(400, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ success: false, message: 'Code is required' }));
                return;
            }
            
            // Initialize response object
            const response = context?.response || {
                success: false,
                data: null,
                message: '',
                error: null
            };
            
            // Make context available
            const request = context?.request || {};
            const method = context?.method || 'POST';
            const headers = context?.headers || {};
            
            // Execute user code
            try {
                // Wrap code in async IIFE to support await
                // User code can use await directly
                await (async function() {
                    // Make database functions available
                    // User code can use dbQuery, dbQueryOne, dbExecute
                    // Note: We can't pass them as parameters to eval, so we'll make them global
                    global.dbQuery = dbQuery;
                    global.dbQueryOne = dbQueryOne;
                    global.dbExecute = dbExecute;
                    
                    // Execute user code
                    eval(code);
                })();
                
                // Ensure response has all required fields
                if (typeof response.success === 'undefined') {
                    response.success = true;
                }
                if (typeof response.data === 'undefined') {
                    response.data = null;
                }
                if (typeof response.message === 'undefined' || !response.message) {
                    response.message = response.success ? 'Code executed successfully' : 'Code execution completed';
                }
                if (typeof response.error === 'undefined') {
                    response.error = null;
                }
                
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify(response));
                
            } catch (error) {
                // Clean error message
                let errorMessage = error.message || 'Unknown error';
                let lineInfo = '';
                
                // Extract line number from error
                const lineMatch = errorMessage.match(/:(\d+)(?::\d+)?(?:\s|$)/);
                if (lineMatch && lineMatch[1]) {
                    lineInfo = ' (satır ' + lineMatch[1] + ')';
                }
                
                // Remove file paths from error message
                errorMessage = errorMessage.replace(/\/[^\s]+\.js:\d+:?\d*/g, '');
                errorMessage = errorMessage.replace(/\/.*?\/node_exec_[^:]+:\d+:?\d*/g, '');
                errorMessage = errorMessage.trim();
                
                if (!errorMessage || errorMessage.length < 3) {
                    errorMessage = error.name || 'JavaScript hatası';
                }
                
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({
                    success: false,
                    message: errorMessage + lineInfo,
                    error: errorMessage,
                    error_type: error.name || 'Error'
                }));
            }
        } catch (parseError) {
            res.writeHead(400, { 'Content-Type': 'application/json' });
            res.end(JSON.stringify({
                success: false,
                message: 'Invalid JSON: ' + parseError.message
            }));
        }
    });
});

// Get port from environment or default to 3001
const PORT = process.env.PORT || 3001;

server.listen(PORT, '127.0.0.1', () => {
    console.log(`Node.js Code Execution Server running on port ${PORT}`);
    
    // Write PID file
    const pidFile = path.join(__dirname, 'nodejs-server.pid');
    fs.writeFileSync(pidFile, process.pid.toString());
});

// Graceful shutdown
process.on('SIGTERM', () => {
    if (dbHelper) {
        if (dbType === 'mysql') {
            dbHelper.end();
        } else if (dbHelper.close) {
            dbHelper.close();
        }
    }
    server.close(() => {
        const pidFile = path.join(__dirname, 'nodejs-server.pid');
        if (fs.existsSync(pidFile)) {
            fs.unlinkSync(pidFile);
        }
        process.exit(0);
    });
});

process.on('SIGINT', () => {
    if (dbHelper) {
        if (dbType === 'mysql') {
            dbHelper.end();
        } else if (dbHelper.close) {
            dbHelper.close();
        }
    }
    server.close(() => {
        const pidFile = path.join(__dirname, 'nodejs-server.pid');
        if (fs.existsSync(pidFile)) {
            fs.unlinkSync(pidFile);
        }
        process.exit(0);
    });
});


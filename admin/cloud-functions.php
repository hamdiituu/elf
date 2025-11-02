<?php
require_once '../config/config.php';
requireDeveloper();

$page_title = 'Cloud Functions';

$db = getDB();
$error_message = null;
$success_message = null;

// Ensure cloud_middlewares table exists first (for foreign key reference)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS cloud_middlewares (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        description TEXT,
        code TEXT NOT NULL,
        language TEXT DEFAULT 'php',
        enabled INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INTEGER,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");
} catch (PDOException $e) {
    // Table might already exist
}

// Ensure cloud_functions table exists
try {
    $settings = getSettings();
    $dbType = $settings['db_type'] ?? 'sqlite';
    
    if ($dbType === 'mysql') {
        // MySQL syntax
        $db->exec("CREATE TABLE IF NOT EXISTS cloud_functions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            description TEXT,
            code TEXT NOT NULL,
            language VARCHAR(50) DEFAULT 'php',
            http_method VARCHAR(10) NOT NULL DEFAULT 'POST',
            endpoint VARCHAR(255) NOT NULL UNIQUE,
            enabled TINYINT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by INT,
            middleware_id INT,
            FOREIGN KEY (created_by) REFERENCES users(id),
            FOREIGN KEY (middleware_id) REFERENCES cloud_middlewares(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        // SQLite syntax
        $db->exec("CREATE TABLE IF NOT EXISTS cloud_functions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT,
            code TEXT NOT NULL,
            language TEXT DEFAULT 'php',
            http_method TEXT NOT NULL DEFAULT 'POST',
            endpoint TEXT NOT NULL UNIQUE,
            enabled INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_by INTEGER,
            middleware_id INTEGER,
            FOREIGN KEY (created_by) REFERENCES users(id),
            FOREIGN KEY (middleware_id) REFERENCES cloud_middlewares(id)
        )");
    }
    
    // Add missing columns if they don't exist (for existing tables)
    try {
        // Check which columns exist
        $settings = getSettings();
        $dbType = $settings['db_type'] ?? 'sqlite';
        
        if ($dbType === 'sqlite') {
            $stmt = $db->query("PRAGMA table_info(cloud_functions)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $column_names = array_column($columns, 'name');
            
            if (!in_array('language', $column_names)) {
                $db->exec("ALTER TABLE cloud_functions ADD COLUMN language TEXT DEFAULT 'php'");
            }
            if (!in_array('middleware_id', $column_names)) {
                $db->exec("ALTER TABLE cloud_functions ADD COLUMN middleware_id INTEGER");
            }
            if (!in_array('function_group', $column_names)) {
                $db->exec("ALTER TABLE cloud_functions ADD COLUMN function_group TEXT");
            }
        } else {
            // MySQL - check columns differently
            try {
                $db->exec("ALTER TABLE cloud_functions ADD COLUMN language VARCHAR(50) DEFAULT 'php'");
            } catch (PDOException $e) {
                // Column might already exist
            }
            try {
                $db->exec("ALTER TABLE cloud_functions ADD COLUMN middleware_id INT");
            } catch (PDOException $e) {
                // Column might already exist
            }
            try {
                $db->exec("ALTER TABLE cloud_functions ADD COLUMN function_group VARCHAR(255)");
            } catch (PDOException $e) {
                // Column might already exist
            }
        }
    } catch (PDOException $e) {
        // Ignore errors
    }
} catch (PDOException $e) {
    // Table might already exist
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'save_function':
            $function_id = intval($_POST['function_id'] ?? 0);
            $function_name = trim($_POST['function_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $code = $_POST['code'] ?? '';
            $language = $_POST['language'] ?? 'php';
            $http_method = 'POST'; // Always POST
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $middleware_id = !empty($_POST['middleware_id']) ? intval($_POST['middleware_id']) : null;
            $function_group = trim($_POST['function_group'] ?? '');
            
            // Validate language - only PHP is supported
            if ($language !== 'php') {
                $language = 'php';
            }
            
            if (empty($function_name) || empty($code)) {
                $error_message = "Function name and code are required!";
            } else {
                try {
                    // Generate endpoint from name
                    $endpoint = strtolower(preg_replace('/[^a-z0-9]+/', '-', $function_name));
                    $endpoint = trim($endpoint, '-');
                    
                    // Check if function name already exists (for both create and update)
                    $check_stmt = $db->prepare("SELECT id, name FROM cloud_functions WHERE name = ?");
                    $check_stmt->execute([$function_name]);
                    $existing_function = $check_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing_function) {
                        // If updating, allow same name for same function
                        if ($function_id > 0 && $existing_function['id'] == $function_id) {
                            // Same function, name can stay the same
                        } else {
                            // Name exists for different function
                            $error_message = "Function name '{$function_name}' is already in use! Please choose a different name.";
                            // Redirect with error
                            header('Location: cloud-functions.php?error=' . urlencode($error_message));
                            exit;
                        }
                    }
                    
                    // Check if endpoint already exists
                    $endpoint_check_stmt = $db->prepare("SELECT id, name, endpoint FROM cloud_functions WHERE endpoint = ?");
                    $endpoint_check_stmt->execute([$endpoint]);
                    $existing_endpoint = $endpoint_check_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing_endpoint) {
                        // If updating, allow same endpoint for same function
                        if ($function_id > 0 && $existing_endpoint['id'] == $function_id) {
                            // Same function, endpoint can stay the same
                        } else {
                            // Endpoint exists for different function
                            $error_message = "Endpoint '{$endpoint}' is already in use by function '{$existing_endpoint['name']}'! Please choose a different function name.";
                            // Redirect with error
                            header('Location: cloud-functions.php?error=' . urlencode($error_message));
                            exit;
                        }
                    }
                    
                    if ($function_id > 0) {
                        // Update existing function
                        $stmt = $db->prepare("
                            UPDATE cloud_functions 
                            SET name = ?, description = ?, code = ?, language = ?, http_method = ?, endpoint = ?, enabled = ?, middleware_id = ?, function_group = ?, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([$function_name, $description, $code, $language, $http_method, $endpoint, $enabled, $middleware_id, $function_group ?: null, $function_id]);
                        $success_message = "Function updated successfully!";
                    } else {
                        // Create new function
                        $stmt = $db->prepare("
                            INSERT INTO cloud_functions (name, description, code, language, http_method, endpoint, enabled, middleware_id, function_group, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$function_name, $description, $code, $language, $http_method, $endpoint, $enabled, $middleware_id, $function_group ?: null, $_SESSION['user_id']]);
                        $success_message = "Function created successfully!";
                    }
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'UNIQUE constraint') !== false || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                        $error_message = "This function name or endpoint is already in use! Please choose a different name.";
                    } else {
                        $error_message = "Error: " . $e->getMessage();
                    }
                }
            }
            break;
            
        case 'delete_function':
            $function_id = intval($_POST['function_id'] ?? 0);
            if ($function_id > 0) {
                try {
                    $stmt = $db->prepare("DELETE FROM cloud_functions WHERE id = ?");
                    $stmt->execute([$function_id]);
                    $success_message = "Function deleted successfully!";
                } catch (PDOException $e) {
                    $error_message = "Error deleting function: " . $e->getMessage();
                }
            }
            break;
    }
    
    // Redirect to prevent form resubmission
    if ($error_message || $success_message) {
        header('Location: cloud-functions.php' . ($success_message ? '?success=' . urlencode($success_message) : '') . ($error_message ? '&error=' . urlencode($error_message) : ''));
        exit;
    }
}

// Get success/error messages from URL
if (isset($_GET['success'])) {
    $success_message = urldecode($_GET['success']);
}
if (isset($_GET['error'])) {
    $error_message = urldecode($_GET['error']);
}

// Get function to edit
$edit_function = null;
$edit_id = $_GET['edit'] ?? null;
if ($edit_id) {
    $stmt = $db->prepare("SELECT * FROM cloud_functions WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_function = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get filter for functions (my functions or all functions)
$show_my_functions = isset($_GET['filter']) && $_GET['filter'] === 'my';
$user_id_filter = $_SESSION['user_id'] ?? null;

// Get all tables for function builder
$all_tables = [];
try {
    $settings = getSettings();
    $dbType = $settings['db_type'] ?? 'sqlite';
    
    if ($dbType === 'mysql') {
        $all_tables = $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $all_tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }
    // Filter out system tables
    $all_tables = array_filter($all_tables, function($table) {
        return !in_array($table, ['sqlite_sequence', 'sqlite_master', 'relation_metadata']);
    });
} catch (PDOException $e) {
    $all_tables = [];
}

// Get relation metadata for all tables
$table_relations = [];
try {
    $relations_stmt = $db->query("SELECT table_name, column_name, target_table FROM relation_metadata ORDER BY table_name, column_name");
    $relations = $relations_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($relations as $rel) {
        $table_name = $rel['table_name'];
        if (!isset($table_relations[$table_name])) {
            $table_relations[$table_name] = [];
        }
        $table_relations[$table_name][$rel['column_name']] = $rel['target_table'];
    }
} catch (PDOException $e) {
    // relation_metadata table might not exist
    $table_relations = [];
}

// Get all function groups
$function_groups = [];
try {
    $group_stmt = $db->query("SELECT DISTINCT function_group FROM cloud_functions WHERE function_group IS NOT NULL AND function_group != '' ORDER BY function_group");
    $function_groups = $group_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $function_groups = [];
}

// Get filter for groups
$selected_group = isset($_GET['group']) ? $_GET['group'] : null;

// Get all functions (handle middleware_id column safely)
$functions = [];
try {
    // Build WHERE clause for filters
    $where_clauses = [];
    $params = [];
    
    if ($show_my_functions && $user_id_filter) {
        $where_clauses[] = "cf.created_by = ?";
        $params[] = $user_id_filter;
    }
    
    if ($selected_group !== null && $selected_group !== '') {
        $where_clauses[] = "cf.function_group = ?";
        $params[] = $selected_group;
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    if ($show_my_functions && $user_id_filter) {
        // Show only user's functions
        try {
            $sql = "SELECT cf.*, u.username as created_by_name, cm.name as middleware_name FROM cloud_functions cf LEFT JOIN users u ON cf.created_by = u.id LEFT JOIN cloud_middlewares cm ON cf.middleware_id = cm.id $where_sql ORDER BY cf.function_group ASC, cf.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $functions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // If join fails, try simpler query
            $sql = "SELECT cf.*, u.username as created_by_name FROM cloud_functions cf LEFT JOIN users u ON cf.created_by = u.id $where_sql ORDER BY cf.function_group ASC, cf.created_at DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $functions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($functions as &$func) {
                $func['middleware_name'] = null;
                $func['middleware_id'] = null;
            }
        }
    } else {
        // Show all functions
        try {
            $sql = "SELECT cf.*, u.username as created_by_name, cm.name as middleware_name FROM cloud_functions cf LEFT JOIN users u ON cf.created_by = u.id LEFT JOIN cloud_middlewares cm ON cf.middleware_id = cm.id $where_sql ORDER BY cf.function_group ASC, cf.created_at DESC";
            if (!empty($params)) {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $functions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $functions = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (PDOException $e) {
            // If join fails, try simpler query
            try {
                $sql = "SELECT cf.*, u.username as created_by_name FROM cloud_functions cf LEFT JOIN users u ON cf.created_by = u.id $where_sql ORDER BY cf.function_group ASC, cf.created_at DESC";
                if (!empty($params)) {
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                    $functions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $functions = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                }
                foreach ($functions as &$func) {
                    $func['middleware_name'] = null;
                    $func['middleware_id'] = null;
                }
            } catch (PDOException $e2) {
                // If even simpler query fails, try basic query
                try {
                    $sql = "SELECT * FROM cloud_functions $where_sql ORDER BY function_group ASC, created_at DESC";
                    if (!empty($params)) {
                        $stmt = $db->prepare($sql);
                        $stmt->execute($params);
                        $functions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } else {
                        $functions = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
                    }
                    foreach ($functions as &$func) {
                        $func['created_by_name'] = null;
                        $func['middleware_name'] = null;
                        $func['middleware_id'] = null;
                    }
                } catch (PDOException $e3) {
                    error_log("Error fetching cloud functions: " . $e3->getMessage());
                    $functions = [];
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching cloud functions: " . $e->getMessage());
    $functions = [];
}

// Get all middlewares for dropdown (both enabled and disabled)
try {
    $middlewares = $db->query("SELECT id, name, description, enabled FROM cloud_middlewares ORDER BY enabled DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If table doesn't exist or query fails, set empty array
    $middlewares = [];
}

// Handle AJAX request for table columns (before including header)
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_table_columns' && isset($_GET['table'])) {
    header('Content-Type: application/json');
    $table_name = $_GET['table'] ?? '';
    
    if (empty($table_name)) {
        echo json_encode(['success' => false, 'message' => 'Table name is required']);
        exit;
    }
    
    try {
        $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
        $settings = getSettings();
        $dbType = $settings['db_type'] ?? 'sqlite';
        
        if ($dbType === 'mysql') {
            $table_info = $db->query("
                SELECT 
                    COLUMN_NAME as name,
                    DATA_TYPE as type,
                    IS_NULLABLE as nullable,
                    COLUMN_DEFAULT as dflt_value,
                    COLUMN_KEY as pk_indicator,
                    ORDINAL_POSITION as cid
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$escaped_table_name'
                ORDER BY ORDINAL_POSITION
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            // Normalize MySQL result to match SQLite PRAGMA format
            foreach ($table_info as &$col) {
                $col['type'] = strtoupper($col['type']);
                $col['notnull'] = (strtoupper(trim($col['nullable'] ?? 'YES')) === 'NO') ? 1 : 0;
                $col['dflt_value'] = $col['dflt_value'];
                $col['pk'] = (strtoupper(trim($col['pk_indicator'] ?? '')) === 'PRI') ? 1 : 0;
            }
            unset($col);
        } else {
            $table_info = $db->query("PRAGMA table_info(\"$escaped_table_name\")")->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode(['success' => true, 'columns' => $table_info]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Error fetching table columns: ' . $e->getMessage()]);
    }
    exit;
}

include '../includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/clike/clike.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/closebrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/matchbrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/selection/active-line.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/anyword-hint.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.css">

<style>
    .CodeMirror {
        border: 1px solid hsl(var(--input));
        border-radius: 0.375rem;
        height: 500px !important;
        font-size: 14px;
        font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', 'Consolas', monospace;
    }
    
    /* CodeMirror will automatically replace textarea, no need to hide it in CSS */
    
    .CodeMirror-activeline-background {
        background: hsl(var(--muted) / 0.3);
    }
    
    /* Fullscreen styles */
    #code-editor-wrapper.fullscreen {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 9999;
        background: hsl(var(--background));
        padding: 20px;
    }
    
    #code-editor-wrapper.fullscreen .CodeMirror {
        height: calc(100vh - 100px);
        border-radius: 0.5rem;
    }
    
    #code-editor-wrapper.fullscreen #fullscreen-btn {
        position: absolute;
        top: 20px;
        right: 20px;
        z-index: 10000;
    }
</style>

<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto bg-muted/30">
        <div class="py-6">
            <div class="mx-auto max-w-[1600px] px-4 sm:px-6 md:px-8">
                <!-- Header -->
                <div class="mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h1 class="text-3xl font-bold text-foreground">Cloud Functions</h1>
                            <p class="mt-2 text-sm text-muted-foreground">Create and manage serverless functions</p>
                        </div>
                        <div class="flex items-center gap-2">
                            <button
                                onclick="showFunctionBuilder()"
                                class="inline-flex items-center justify-center rounded-md text-sm font-semibold bg-green-600 text-white hover:bg-green-700 px-4 py-2.5 transition-colors shadow-sm"
                            >
                                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                                </svg>
                                Function Builder
                            </button>
                            <button
                                onclick="showCreateForm()"
                                class="inline-flex items-center justify-center rounded-md text-sm font-semibold bg-primary text-primary-foreground hover:bg-primary/90 px-4 py-2.5 transition-colors shadow-sm"
                            >
                                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                </svg>
                                New Function
                            </button>
                        </div>
                    </div>
                    
                    <!-- Stats Dashboard -->
                    <div class="grid gap-4 md:grid-cols-4 mb-6">
                        <?php 
                        $total_funcs = count($functions);
                        $active_funcs = count(array_filter($functions, fn($f) => $f['enabled']));
                        $php_funcs = count(array_filter($functions, fn($f) => ($f['language'] ?? 'php') === 'php'));
                        ?>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Total</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $total_funcs; ?></p>
                                </div>
                                <div class="rounded-full bg-blue-100 p-3">
                                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Active</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $active_funcs; ?></p>
                                </div>
                                <div class="rounded-full bg-green-100 p-3">
                                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">PHP</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $php_funcs; ?></p>
                                </div>
                                <div class="rounded-full bg-purple-100 p-3">
                                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4">
                        <div class="flex">
                            <svg class="h-5 w-5 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <p class="text-sm text-red-800"><?php echo htmlspecialchars($error_message); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-4">
                        <div class="flex">
                            <svg class="h-5 w-5 text-green-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <p class="text-sm text-green-800"><?php echo htmlspecialchars($success_message); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- Functions List -->
                    <div class="lg:col-span-1">
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm sticky top-6">
                            <div class="p-4 border-b border-border bg-muted/30">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-lg font-semibold leading-none tracking-tight">Functions</h3>
                                    <span class="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                                        <?php echo count($functions); ?>
                                    </span>
                                </div>
                                <div class="flex items-center gap-2 mb-2 flex-wrap">
                                    <a
                                        href="?filter=my<?php echo $selected_group ? '&group=' . urlencode($selected_group) : ''; ?>"
                                        class="px-2 py-1 text-xs font-medium rounded-md border transition-colors <?php echo $show_my_functions ? 'bg-primary text-primary-foreground border-primary' : 'bg-background text-foreground border-input hover:bg-accent'; ?>"
                                        title="Show my functions"
                                    >
                                        My Functions
                                    </a>
                                    <a
                                        href="cloud-functions.php<?php echo $selected_group ? '?group=' . urlencode($selected_group) : ''; ?>"
                                        class="px-2 py-1 text-xs font-medium rounded-md border transition-colors <?php echo !$show_my_functions ? 'bg-primary text-primary-foreground border-primary' : 'bg-background text-foreground border-input hover:bg-accent'; ?>"
                                        title="Show all functions"
                                    >
                                        All
                                    </a>
                                    <?php if (!empty($function_groups)): ?>
                                        <select
                                            onchange="if(this.value) window.location.href='?group=' + encodeURIComponent(this.value) + '<?php echo $show_my_functions ? '&filter=my' : ''; ?>'; else window.location.href='cloud-functions.php<?php echo $show_my_functions ? '?filter=my' : ''; ?>';"
                                            class="px-2 py-1 text-xs font-medium rounded-md border border-input bg-background text-foreground focus:outline-none focus:ring-1 focus:ring-ring"
                                            value="<?php echo htmlspecialchars($selected_group ?? ''); ?>"
                                        >
                                            <option value="">All Groups</option>
                                            <?php foreach ($function_groups as $group): ?>
                                                <option value="<?php echo htmlspecialchars($group); ?>" <?php echo $selected_group === $group ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($group); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                                <input 
                                    type="text" 
                                    id="function-search"
                                    placeholder="Search functions..."
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    oninput="filterFunctions(this.value)"
                                >
                            </div>
                            <div class="p-4">
                                <div class="space-y-3 max-h-[calc(100vh-400px)] overflow-y-auto" id="functions-list">
                                    <?php 
                                    // Debug: Check if functions array is set and not empty
                                    if (!isset($functions)) {
                                        $functions = [];
                                    }
                                    if (empty($functions)): 
                                    ?>
                                        <div class="text-center py-12 text-muted-foreground">
                                            <svg class="mx-auto h-12 w-12 mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                            </svg>
                                            <p class="text-sm font-medium">No functions yet</p>
                                            <p class="text-xs mt-1">Create your first cloud function to get started</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($functions as $func): 
                                            $lang = 'php';
                                            $langColor = 'text-purple-600 bg-purple-100';
                                        ?>
                                            <div class="rounded-lg border border-border p-4 bg-gradient-to-br from-background to-muted/30 hover:border-primary/50 hover:shadow-md transition-all function-item group <?php echo (isset($edit_id) && $edit_id == $func['id']) ? 'border-primary bg-primary/5 shadow-md' : ''; ?>" 
                                                 data-function-id="<?php echo $func['id']; ?>"
                                                 data-function-name="<?php echo strtolower(htmlspecialchars($func['name'])); ?>"
                                                 onclick="selectFunction(<?php echo $func['id']; ?>)"
                                            >
                                                <div class="flex items-start justify-between gap-3 mb-3">
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-2 mb-2 flex-wrap">
                                                            <h4 class="font-semibold text-sm text-foreground group-hover:text-primary transition-colors truncate">
                                                                <?php echo htmlspecialchars($func['name']); ?>
                                                            </h4>
                                                            <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-semibold <?php echo $langColor; ?>">
                                                                PHP
                                                            </span>
                                                            <?php if (!$func['enabled']): ?>
                                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-800">
                                                                    Inactive
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($func['description']): ?>
                                                            <p class="text-xs text-muted-foreground line-clamp-2"><?php echo htmlspecialchars($func['description']); ?></p>
                                                        <?php endif; ?>
                                                        <?php if (!empty($func['middleware_name'])): ?>
                                                            <div class="flex items-center gap-1 mt-2 text-xs text-purple-600 font-medium">
                                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                                                </svg>
                                                                <?php echo htmlspecialchars($func['middleware_name']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <p class="text-xs text-muted-foreground mt-2 font-mono truncate opacity-75">
                                                            /api/cloud-functions/execute.php?function=<?php echo htmlspecialchars($func['name']); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-2 pt-3 border-t border-border">
                                                    <a
                                                        href="?edit=<?php echo $func['id']; ?>"
                                                        class="flex-1 inline-flex items-center justify-center rounded-md text-xs font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-3 py-1.5 transition-colors"
                                                        onclick="event.stopPropagation()"
                                                    >
                                                        Edit
                                                    </a>
                                                    <a
                                                        href="api-playground.php?api_id=cloud-function-<?php echo $func['id']; ?>"
                                                        class="flex-1 inline-flex items-center justify-center rounded-md text-xs font-semibold bg-primary text-primary-foreground hover:bg-primary/90 px-3 py-1.5 transition-colors"
                                                        onclick="event.stopPropagation()"
                                                    >
                                                        Test
                                                    </a>
                                                    <button
                                                        onclick="event.stopPropagation(); window.deleteFunction(<?php echo $func['id']; ?>, '<?php echo htmlspecialchars(addslashes($func['name'])); ?>')"
                                                        class="flex-1 inline-flex items-center justify-center rounded-md text-xs font-medium bg-red-600 text-white hover:bg-red-700 px-3 py-1.5 transition-colors"
                                                        title="Delete function"
                                                    >
                                                        <svg class="mr-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                        Delete
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Function Editor -->
                    <div class="lg:col-span-2">
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">
                                    <?php echo $edit_function ? 'Edit Function' : 'New Function'; ?>
                                </h3>
                                
                                <form method="POST" id="function-form">
                                    <input type="hidden" name="action" value="save_function">
                                    <input type="hidden" name="function_id" value="<?php echo $edit_function ? $edit_function['id'] : '0'; ?>">
                                    
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium text-foreground mb-1.5">
                                                Function Name *
                                            </label>
                                            <input
                                                type="text"
                                                name="function_name"
                                                id="function_name"
                                                value="<?php echo htmlspecialchars($edit_function['name'] ?? ''); ?>"
                                                required
                                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                placeholder="my-function"
                                            />
                                            <input type="hidden" name="http_method" value="POST">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-foreground mb-1.5">
                                                Description
                                            </label>
                                            <input
                                                type="text"
                                                name="description"
                                                id="description"
                                                value="<?php echo htmlspecialchars($edit_function['description'] ?? ''); ?>"
                                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                placeholder="Function description"
                                            />
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-foreground mb-1.5">
                                                Language *
                                            </label>
                                            <select
                                                name="language"
                                                id="language"
                                                required
                                                onchange="updateCodeEditorMode()"
                                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                            >
                                                <option value="php" selected>PHP</option>
                                            </select>
                                            <p class="mt-1 text-xs text-muted-foreground">
                                                Programming language for function code
                                            </p>
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-foreground mb-1.5">
                                                Group (Optional)
                                            </label>
                                            <input
                                                type="text"
                                                name="function_group"
                                                id="function_group"
                                                value="<?php echo htmlspecialchars($edit_function['function_group'] ?? ''); ?>"
                                                list="function-groups-list"
                                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                placeholder="e.g. Users, Orders, API"
                                            />
                                            <datalist id="function-groups-list">
                                                <?php foreach ($function_groups as $group): ?>
                                                    <option value="<?php echo htmlspecialchars($group); ?>">
                                                <?php endforeach; ?>
                                            </datalist>
                                            <p class="mt-1 text-xs text-muted-foreground">
                                                Group functions together for better organization
                                            </p>
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-foreground mb-1.5">
                                                Middleware (Optional)
                                            </label>
                                            <select
                                                name="middleware_id"
                                                id="middleware_id"
                                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                            >
                                                <option value="">Select middleware...</option>
                                                <?php foreach ($middlewares as $mw): ?>
                                                    <option value="<?php echo $mw['id']; ?>" <?php echo (isset($edit_function['middleware_id']) && $edit_function['middleware_id'] == $mw['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($mw['name']); ?>
                                                        <?php if ($mw['description']): ?>
                                                            - <?php echo htmlspecialchars($mw['description']); ?>
                                                        <?php endif; ?>
                                                        <?php if (!$mw['enabled']): ?>
                                                            (Inactive)
                                                        <?php endif; ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="mt-1 text-xs text-muted-foreground">
                                                Middleware is executed before the function runs. If middleware returns <code class="text-xs bg-muted px-1 py-0.5 rounded">success = false</code>, the function will not run.
                                            </p>
                                        </div>
                                        
                                        <div>
                                            <div class="flex items-center justify-between mb-1.5">
                                                <label class="block text-sm font-medium text-foreground">
                                                    Code *
                                                </label>
                                                <button
                                                    type="button"
                                                    onclick="toggleFullscreen()"
                                                    class="inline-flex items-center justify-center rounded-md text-xs font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-2 py-1 transition-colors"
                                                    id="fullscreen-btn"
                                                >
                                                    <svg class="mr-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                                                    </svg>
                                                    Fullscreen
                                                </button>
                                            </div>
                                            <div id="code-editor-wrapper" class="relative">
                                            <textarea
                                                name="code"
                                                id="code"
                                                required
                                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                rows="20"
                                            ><?php 
                                            $defaultCode = "// Cloud Function Code\n// Available variables:\n// \$dbContext - Database connection (PDO object)\n// \$request - Request body data (array)\n// \$method - HTTP method (string: GET, POST, PUT, DELETE)\n// \$headers - Request headers (array)\n// \$response - Response array (must set this)\n\n// Example: Get users\n\$stmt = \$dbContext->query(\"SELECT * FROM users LIMIT 10\");\n\$users = \$stmt->fetchAll(PDO::FETCH_ASSOC);\n\n// Set response\n\$response['success'] = true;\n\$response['data'] = \$users;\n\$response['message'] = 'Users retrieved successfully';\n\n// Example: With parameters from request\n// \$limit = isset(\$request['limit']) ? intval(\$request['limit']) : 10;\n// \$stmt = \$dbContext->prepare(\"SELECT * FROM users LIMIT ?\");\n// \$stmt->execute([\$limit]);\n// \$users = \$stmt->fetchAll(PDO::FETCH_ASSOC);\n// \$response['success'] = true;\n// \$response['data'] = \$users;\n\n// Example: Check if record exists\n// \$stmt = \$dbContext->prepare(\"SELECT * FROM table WHERE id = ?\");\n// \$stmt->execute([\$id]);\n// \$record = \$stmt->fetch(PDO::FETCH_ASSOC);\n// if (!\$record) {\n//     \$response['success'] = false;\n//     \$response['message'] = 'Record not found';\n//     return;\n// }\n";
                                            
                                            $selectedLanguage = $edit_function['language'] ?? 'php';
                                            $codeToShow = $defaultCode;
                                            echo htmlspecialchars($edit_function['code'] ?? $codeToShow); 
                                            ?></textarea>
                                            </div>
                                        </div>
                                        
                                        <div class="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                name="enabled"
                                                id="enabled"
                                                <?php echo (!$edit_function || $edit_function['enabled']) ? 'checked' : ''; ?>
                                                class="rounded border-input"
                                            />
                                            <label for="enabled" class="text-sm font-medium text-foreground">
                                                Active
                                            </label>
                                        </div>
                                        
                                        <div class="flex items-center gap-2">
                                            <button
                                                type="submit"
                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 px-4 py-2 transition-colors"
                                            >
                                                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                                <?php echo $edit_function ? 'Update' : 'Create'; ?>
                                            </button>
                                            <?php if ($edit_function): ?>
                                                <a
                                                    href="cloud-functions.php"
                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-4 py-2 transition-colors"
                                                >
                                                    Cancel
                                                </a>
                                                <button
                                                    type="button"
                                                    onclick="window.deleteFunction(<?php echo $edit_function['id']; ?>, '<?php echo htmlspecialchars(addslashes($edit_function['name'])); ?>')"
                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-red-600 text-white hover:bg-red-700 px-4 py-2 transition-colors"
                                                >
                                                    <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                    Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Function Builder Modal -->
<div id="function-builder-modal" class="fixed inset-0 hidden items-center justify-center z-50" onclick="if(event.target === this && typeof window.hideFunctionBuilder === 'function') window.hideFunctionBuilder()" style="background-color: rgba(0, 0, 0, 0.3) !important;">
    <div class="border border-border rounded-lg shadow-lg p-6 max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()" style="background-color: hsl(var(--background)) !important; z-index: 51;">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-semibold">Function Builder</h3>
            <button
                type="button"
                onclick="if(typeof window.hideFunctionBuilder === 'function') window.hideFunctionBuilder()"
                class="text-muted-foreground hover:text-foreground transition-colors"
            >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        
        <form id="builder-form" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-foreground mb-1.5">
                    Function Name *
                </label>
                <input
                    type="text"
                    id="builder_function_name"
                    required
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    placeholder="e.g. get-users"
                />
            </div>
            
            <div>
                <label class="block text-sm font-medium text-foreground mb-1.5">
                    Table *
                </label>
                <select
                    id="builder_table_name"
                    required
                    onchange="updateBuilderPreview()"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="">-- Select Table --</option>
                    <?php foreach ($all_tables as $table): ?>
                        <option value="<?php echo htmlspecialchars($table); ?>"><?php echo htmlspecialchars($table); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-foreground mb-1.5">
                    Operation Type *
                </label>
                <select
                    id="builder_operation"
                    required
                    onchange="updateBuilderPreview()"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="list">List (SELECT * FROM table)</option>
                    <option value="get">Get Single (SELECT * WHERE id)</option>
                    <option value="create">Create (INSERT)</option>
                    <option value="update">Update (UPDATE WHERE id)</option>
                    <option value="delete">Delete (DELETE WHERE id)</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-foreground mb-1.5">
                    Language *
                </label>
                <select
                    id="builder_language"
                    required
                    onchange="updateBuilderPreview()"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                >
                    <option value="php">PHP</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-foreground mb-1.5">
                    Description
                </label>
                <input
                    type="text"
                    id="builder_description"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    placeholder="Function description"
                />
            </div>
            
            <div>
                <label class="block text-sm font-medium text-foreground mb-1.5">
                    Group (Optional)
                </label>
                <input
                    type="text"
                    id="builder_group"
                    list="builder-groups-list"
                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                    placeholder="e.g. Users, Orders, API"
                />
                <datalist id="builder-groups-list">
                    <?php foreach ($function_groups as $group): ?>
                        <option value="<?php echo htmlspecialchars($group); ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-foreground mb-1.5">
                    Generated Code Preview
                </label>
                <pre id="builder_preview" class="bg-muted p-4 rounded-md text-xs font-mono text-foreground overflow-x-auto max-h-60 overflow-y-auto"></pre>
            </div>
            
            <div class="flex items-center gap-2 justify-end pt-4 border-t border-border">
                <button
                    type="button"
                    onclick="if(typeof window.hideFunctionBuilder === 'function') window.hideFunctionBuilder()"
                    class="px-4 py-2 text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 rounded-md transition-colors"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    onclick="generateAndCreateFunction()"
                    class="px-4 py-2 text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 rounded-md transition-colors"
                >
                    Generate & Create
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Delete function - must be defined early
    window.deleteFunction = function(id, name) {
        if (confirm('Are you sure you want to delete "' + name + '"? This action cannot be undone.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="delete_function"><input type="hidden" name="function_id" value="' + id + '">';
            document.body.appendChild(form);
            form.submit();
        }
    };
    
    // Table relations metadata (for function builder)
    const tableRelations = <?php echo json_encode($table_relations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE); ?>;
    
    // Initialize CodeMirror
    const codeTextarea = document.getElementById('code');
    if (!codeTextarea) {
        console.error('Code textarea not found');
    }
    
    // Determine initial mode based on language
    const languageSelect = document.getElementById('language');
    const initialLanguage = languageSelect ? languageSelect.value : 'php';
    
    window.codeEditor = CodeMirror.fromTextArea(codeTextarea, {
        mode: {
            name: 'php',
            startOpen: true
        },
        theme: 'monokai',
        lineNumbers: true,
        autoCloseBrackets: true,
        matchBrackets: true,
        styleActiveLine: true,
        indentUnit: 4,
        indentWithTabs: false,
        lineWrapping: true,
        extraKeys: {
            "Ctrl-Space": function(cm) {
                CodeMirror.commands.autocomplete(cm, CodeMirror.hint.anyword);
            },
            "Ctrl-F": "findPersistent"
        },
        hintOptions: {
            hint: function(editor) {
                const cursor = editor.getCursor();
                const token = editor.getTokenAt(cursor);
                const word = token.string;
                
                // Custom hints for dbContext and common functions
                const hints = [
                    'dbContext', 'request', 'method', 'headers', 'response',
                    'query', 'prepare', 'execute', 'fetchAll', 'fetch', 'fetchColumn',
                    'PDO::FETCH_ASSOC', 'PDO::FETCH_OBJ', 'PDO::FETCH_NUM',
                    'success', 'data', 'message', 'error',
                    'array', 'count', 'isset', 'empty', 'trim', 'htmlspecialchars',
                    'json_encode', 'json_decode', 'date', 'time'
                ];
                
                const filtered = hints.filter(h => h.toLowerCase().startsWith(word.toLowerCase()));
                
                return {
                    list: filtered,
                    from: CodeMirror.Pos(cursor.line, token.start),
                    to: CodeMirror.Pos(cursor.line, token.end)
                };
            },
            completeSingle: false
        }
    });
    
    // Auto-trigger autocomplete
    window.codeEditor.on('inputRead', function(cm, change) {
        if (change.text[0].length > 0 && change.text[0][0].match(/[a-zA-Z]/)) {
            setTimeout(function() {
                CodeMirror.commands.autocomplete(cm);
            }, 100);
        }
    });
    
    // Sync editor with textarea
    window.codeEditor.on('change', function(cm) {
        cm.save();
    });
    
    // Function to update editor mode when language changes
    window.updateCodeEditorMode = function() {
        if (!window.codeEditor) return;
        const languageSelect = document.getElementById('language');
        const selectedLanguage = languageSelect ? languageSelect.value : 'php';
        const newMode = 'php';
        
        // Change mode
        window.codeEditor.setOption('mode', {
            name: 'php',
            startOpen: true
        });
        
        // Update example code if editor is empty or contains default code
        const currentValue = window.codeEditor.getValue().trim();
        if (!currentValue || currentValue.startsWith('// Cloud Function Code') || currentValue === '') {
            const exampleCode = `// Cloud Function Code
// Available variables:
// \$dbContext - Database connection (PDO object)
// \$request - Request body data (array)
// \$method - HTTP method (string: GET, POST, PUT, DELETE)
// \$headers - Request headers (array)
// \$response - Response array (must set this)
// \$_FILES - Uploaded files array (if any files uploaded)

// Example: Get users
\$stmt = \$dbContext->query("SELECT * FROM users LIMIT 10");
\$users = \$stmt->fetchAll(PDO::FETCH_ASSOC);

// Set response
\$response['success'] = true;
\$response['data'] = \$users;
\$response['message'] = 'Users retrieved successfully';

// Example: With parameters from request
// \$limit = isset(\$request['limit']) ? intval(\$request['limit']) : 10;
// \$stmt = \$dbContext->prepare("SELECT * FROM users LIMIT ?");
// \$stmt->execute([\$limit]);
// \$users = \$stmt->fetchAll(PDO::FETCH_ASSOC);
// \$response['success'] = true;
// \$response['data'] = \$users;

// Example: Image Upload
// Note: Use multipart/form-data when calling the API with file upload
// if (isset(\$_FILES['image']) && \$_FILES['image']['error'] === UPLOAD_ERR_OK) {
//     \$file = \$_FILES['image'];
//     \$file_ext = strtolower(pathinfo(\$file['name'], PATHINFO_EXTENSION));
//     \$allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
//     
//     if (in_array(\$file_ext, \$allowed_exts)) {
//         // Get uploads directory (relative to project root)
//         \$uploads_dir = dirname(__DIR__, 2) . '/uploads';
//         if (!is_dir(\$uploads_dir)) {
//             mkdir(\$uploads_dir, 0755, true);
//         }
//         
//         \$filename = 'image_' . time() . '_' . uniqid() . '.' . \$file_ext;
//         \$file_path = \$uploads_dir . '/' . \$filename;
//         
//         if (move_uploaded_file(\$file['tmp_name'], \$file_path)) {
//             \$image_path = 'uploads/' . \$filename;
//             
//             // Optional: Save to database
//             // \$stmt = \$dbContext->prepare("INSERT INTO images (image_path, created_at) VALUES (?, CURRENT_TIMESTAMP)");
//             // \$stmt->execute([\$image_path]);
//             
//             \$response['success'] = true;
//             \$response['data'] = ['image_path' => \$image_path, 'url' => '/' . \$image_path];
//             \$response['message'] = 'Image uploaded successfully';
//         } else {
//             \$response['success'] = false;
//             \$response['message'] = 'Failed to move uploaded file';
//         }
//     } else {
//         \$response['success'] = false;
//         \$response['message'] = 'Invalid file type. Allowed: ' . implode(', ', \$allowed_exts);
//     }
// } else {
//     \$error_msg = isset(\$_FILES['image']) ? 'Upload error code: ' . \$_FILES['image']['error'] : 'No image file uploaded';
//     \$response['success'] = false;
//     \$response['message'] = \$error_msg;
// }

// Example: Check if record exists
// \$stmt = \$dbContext->prepare("SELECT * FROM table WHERE id = ?");
// \$stmt->execute([\$id]);
// \$record = \$stmt->fetch(PDO::FETCH_ASSOC);
// if (!\$record) {
//     \$response['success'] = false;
//     \$response['message'] = 'Record not found';
//     return;
// }`;
            window.codeEditor.setValue(exampleCode);
        }
    };
    
    let isFullscreen = false;
    
    // Initialize fullscreen functionality
    window.toggleFullscreen = function() {
        if (!window.codeEditor) {
            console.error('Code editor not initialized');
            return;
        }
        
        const wrapper = document.getElementById('code-editor-wrapper');
        const btn = document.getElementById('fullscreen-btn');
        
        if (!wrapper || !btn) {
            console.error('Fullscreen elements not found');
            return;
        }
        
        if (!isFullscreen) {
            wrapper.classList.add('fullscreen');
            btn.innerHTML = `
                <svg class="mr-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                Exit
            `;
            isFullscreen = true;
            window.codeEditor.refresh();
            window.codeEditor.focus();
        } else {
            wrapper.classList.remove('fullscreen');
            btn.innerHTML = `
                <svg class="mr-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                </svg>
                Fullscreen
            `;
            isFullscreen = false;
            window.codeEditor.refresh();
        }
    };
    
    // ESC key to exit fullscreen
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && isFullscreen && window.codeEditor) {
            window.toggleFullscreen();
        }
    });
    
    function showCreateForm() {
        window.location.href = 'cloud-functions.php';
    }
    
    // Function Builder functions
    window.showFunctionBuilder = function() {
        const modal = document.getElementById('function-builder-modal');
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            updateBuilderPreview();
        }
    };
    
    window.hideFunctionBuilder = function() {
        const modal = document.getElementById('function-builder-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    };
    
    // Cache for table columns
    const tableColumnsCache = {};
    
    function getTableColumns(tableName) {
        return new Promise((resolve, reject) => {
            if (tableColumnsCache[tableName]) {
                resolve(tableColumnsCache[tableName]);
                return;
            }
            
            fetch(`?ajax=get_table_columns&table=${encodeURIComponent(tableName)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.columns) {
                        tableColumnsCache[tableName] = data.columns;
                        resolve(data.columns);
                    } else {
                        reject(new Error(data.message || 'Failed to fetch columns'));
                    }
                })
                .catch(error => reject(error));
        });
    }
    
    async function generateFunctionCode() {
        const functionName = document.getElementById('builder_function_name')?.value || '';
        const tableName = document.getElementById('builder_table_name')?.value || '';
        const operation = document.getElementById('builder_operation')?.value || 'list';
        
        if (!functionName || !tableName || !operation) {
            return '';
        }
        
        try {
            const columns = await getTableColumns(tableName);
            return generatePHPCode(functionName, tableName, operation, columns);
        } catch (error) {
            console.error('Error fetching table columns:', error);
            // Fallback to basic code without columns
            return generatePHPCode(functionName, tableName, operation, null);
        }
    }
    
    function generatePHPCode(functionName, tableName, operation, columns = null) {
        const escapedTable = tableName.replace(/[^a-zA-Z0-9_]/g, '');
        
        // Get relations for this table
        const relations = tableRelations[tableName] || {};
        const hasRelations = Object.keys(relations).length > 0;
        
        // Build JOIN clauses if relations exist
        let joinClauses = '';
        let selectFields = `\`${escapedTable}\`.*`;
        
        if (hasRelations && (operation === 'list' || operation === 'get')) {
            const joins = [];
            const aliases = {};
            let aliasIndex = 1;
            
            for (const [column, targetTable] of Object.entries(relations)) {
                const escapedColumn = column.replace(/[^a-zA-Z0-9_]/g, '');
                const escapedTarget = targetTable.replace(/[^a-zA-Z0-9_]/g, '');
                const alias = `r${aliasIndex++}`;
                aliases[targetTable] = alias;
                
                joins.push(`LEFT JOIN \`${escapedTarget}\` AS \`${alias}\` ON \`${escapedTable}\`.\`${escapedColumn}\` = \`${alias}\`.\`id\``);
                
                // Add related table fields (id, name)
                // Note: Only adding name field, title field may not exist in all tables
                selectFields += `, \`${alias}\`.\`id\` AS \`${column}_id\``;
                selectFields += `, \`${alias}\`.\`name\` AS \`${column}_name\``;
            }
            
            if (joins.length > 0) {
                joinClauses = ' ' + joins.join(' ');
            }
        }
        
        let code = '';
        
        switch(operation) {
            case 'list':
                if (hasRelations && joinClauses) {
                    code = `// List all records from ${tableName} with relations
\$stmt = \$dbContext->query("SELECT ${selectFields} FROM \`${escapedTable}\`${joinClauses} ORDER BY \`${escapedTable}\`.\`id\` DESC");
\$records = \$stmt->fetchAll(PDO::FETCH_ASSOC);

\$response['success'] = true;
\$response['data'] = \$records;
\$response['message'] = 'Records retrieved successfully';
\$response['count'] = count(\$records);`;
                } else {
                    code = `// List all records from ${tableName}
\$stmt = \$dbContext->query("SELECT * FROM \`${escapedTable}\` ORDER BY id DESC");
\$records = \$stmt->fetchAll(PDO::FETCH_ASSOC);

\$response['success'] = true;
\$response['data'] = \$records;
\$response['message'] = 'Records retrieved successfully';
\$response['count'] = count(\$records);`;
                }
                break;
            case 'get':
                if (hasRelations && joinClauses) {
                    code = `// Get single record by ID with relations
\$id = isset(\$request['id']) ? intval(\$request['id']) : 0;
if (\$id <= 0) {
    \$response['success'] = false;
    \$response['message'] = 'Invalid ID';
    return;
}

\$stmt = \$dbContext->prepare("SELECT ${selectFields} FROM \`${escapedTable}\`${joinClauses} WHERE \`${escapedTable}\`.\`id\` = ?");
\$stmt->execute([\$id]);
\$record = \$stmt->fetch(PDO::FETCH_ASSOC);

if (!\$record) {
    \$response['success'] = false;
    \$response['message'] = 'Record not found';
    return;
}

\$response['success'] = true;
\$response['data'] = \$record;
\$response['message'] = 'Record retrieved successfully';`;
                } else {
                    code = `// Get single record by ID
\$id = isset(\$request['id']) ? intval(\$request['id']) : 0;
if (\$id <= 0) {
    \$response['success'] = false;
    \$response['message'] = 'Invalid ID';
    return;
}

\$stmt = \$dbContext->prepare("SELECT * FROM \`${escapedTable}\` WHERE id = ?");
\$stmt->execute([\$id]);
\$record = \$stmt->fetch(PDO::FETCH_ASSOC);

if (!\$record) {
    \$response['success'] = false;
    \$response['message'] = 'Record not found';
    return;
}

\$response['success'] = true;
\$response['data'] = \$record;
\$response['message'] = 'Record retrieved successfully';`;
                }
                break;
            case 'create':
                if (columns && columns.length > 0) {
                    // Filter columns: skip primary key (auto-increment) and timestamps
                    const insertColumns = columns.filter(col => {
                        return col.pk !== 1 && 
                               col.name.toLowerCase() !== 'created_at' && 
                               col.name.toLowerCase() !== 'updated_at';
                    });
                    
                    if (insertColumns.length > 0) {
                        const columnNames = insertColumns.map(col => `\`${col.name}\``).join(', ');
                        const placeholders = insertColumns.map(() => '?').join(', ');
                        const paramNames = insertColumns.map(col => col.name).join(', ');
                        const valueAssignments = insertColumns.map(col => {
                            const colName = col.name;
                            const isBool = col.type === 'INTEGER' && (
                                col.dflt_value === '0' || col.dflt_value === '1' ||
                                /^(is_|has_|can_|should_|must_|.*_(mi|mu|mi_durum|durum)$)/i.test(colName)
                            );
                            
                            if (isBool) {
                                return `isset(\$request['${colName}']) ? 1 : 0`;
                            } else if (col.type === 'INTEGER' || col.type === 'REAL' || col.type === 'NUMERIC') {
                                return `isset(\$request['${colName}']) ? intval(\$request['${colName}']) : null`;
                            } else {
                                return `isset(\$request['${colName}']) ? trim(\$request['${colName}']) : null`;
                            }
                        }).join(',\n    ');
                        
                        // Check for timestamp columns
                        const hasCreatedAt = columns.some(col => col.name.toLowerCase() === 'created_at');
                        const hasUpdatedAt = columns.some(col => col.name.toLowerCase() === 'updated_at');
                        
                        let timestampCols = '';
                        let timestampVals = '';
                        if (hasCreatedAt) {
                            timestampCols += ', `created_at`';
                            timestampVals += ', CURRENT_TIMESTAMP';
                        }
                        if (hasUpdatedAt) {
                            timestampCols += ', `updated_at`';
                            timestampVals += ', CURRENT_TIMESTAMP';
                        }
                        
                        code = `// Create new record
\$columns = [${paramNames}];
\$values = [
    ${valueAssignments}
];

// Prepare SQL with columns
\$stmt = \$dbContext->prepare("INSERT INTO \`${escapedTable}\` (${columnNames}${timestampCols}) VALUES (${placeholders}${timestampVals})");
\$stmt->execute(\$values);

\$response['success'] = true;
\$response['message'] = 'Record created successfully';
\$response['data'] = ['id' => \$dbContext->lastInsertId()];`;
                    } else {
                        code = `// Create new record
// No insertable columns found (only primary key and timestamps)

\$response['success'] = false;
\$response['message'] = 'No columns available for insert';`;
                    }
                } else {
                    code = `// Create new record
// Expected fields in request
\$stmt = \$dbContext->prepare("INSERT INTO ${escapedTable} (/* columns */) VALUES (/* values */)");
// \$stmt->execute([/* values */]);

\$response['success'] = true;
\$response['message'] = 'Record created successfully';
// \$response['data'] = ['id' => \$dbContext->lastInsertId()];`;
                }
                break;
            case 'update':
                if (columns && columns.length > 0) {
                    // Filter columns: skip primary key and created_at, but include updated_at
                    const updateColumns = columns.filter(col => {
                        return col.pk !== 1 && col.name.toLowerCase() !== 'created_at';
                    });
                    
                    if (updateColumns.length > 0) {
                        const setParts = [];
                        const valueAssignments = [];
                        updateColumns.forEach(col => {
                            const colName = col.name;
                            const isBool = col.type === 'INTEGER' && (
                                col.dflt_value === '0' || col.dflt_value === '1' ||
                                /^(is_|has_|can_|should_|must_|.*_(mi|mu|mi_durum|durum)$)/i.test(colName)
                            );
                            
                            if (colName.toLowerCase() === 'updated_at') {
                                setParts.push(`\`${colName}\` = CURRENT_TIMESTAMP`);
                            } else {
                                setParts.push(`\`${colName}\` = ?`);
                                if (isBool) {
                                    valueAssignments.push(`isset(\$request['${colName}']) ? 1 : 0`);
                                } else if (col.type === 'INTEGER' || col.type === 'REAL' || col.type === 'NUMERIC') {
                                    valueAssignments.push(`isset(\$request['${colName}']) ? intval(\$request['${colName}']) : null`);
                                } else {
                                    valueAssignments.push(`isset(\$request['${colName}']) ? trim(\$request['${colName}']) : null`);
                                }
                            }
                        });
                        
                        const setClause = setParts.join(', ');
                        const valuesList = valueAssignments.join(',\n    ');
                        
                        code = `// Update record by ID
\$id = isset(\$request['id']) ? intval(\$request['id']) : 0;
if (\$id <= 0) {
    \$response['success'] = false;
    \$response['message'] = 'Invalid ID';
    return;
}

// Check if record exists
\$stmt = \$dbContext->prepare("SELECT * FROM \`${escapedTable}\` WHERE id = ?");
\$stmt->execute([\$id]);
\$record = \$stmt->fetch(PDO::FETCH_ASSOC);

if (!\$record) {
    \$response['success'] = false;
    \$response['message'] = 'Record not found';
    return;
}

// Update record
\$values = [
    ${valuesList}
];
\$values[] = \$id; // Add ID for WHERE clause

\$stmt = \$dbContext->prepare("UPDATE \`${escapedTable}\` SET ${setClause} WHERE id = ?");
\$stmt->execute(\$values);

\$response['success'] = true;
\$response['message'] = 'Record updated successfully';`;
                    } else {
                        code = `// Update record by ID
\$id = isset(\$request['id']) ? intval(\$request['id']) : 0;
if (\$id <= 0) {
    \$response['success'] = false;
    \$response['message'] = 'Invalid ID';
    return;
}

// Check if record exists
\$stmt = \$dbContext->prepare("SELECT * FROM \`${escapedTable}\` WHERE id = ?");
\$stmt->execute([\$id]);
\$record = \$stmt->fetch(PDO::FETCH_ASSOC);

if (!\$record) {
    \$response['success'] = false;
    \$response['message'] = 'Record not found';
    return;
}

// No columns available for update
\$response['success'] = false;
\$response['message'] = 'No columns available for update';`;
                    }
                } else {
                    code = `// Update record by ID
\$id = isset(\$request['id']) ? intval(\$request['id']) : 0;
if (\$id <= 0) {
    \$response['success'] = false;
    \$response['message'] = 'Invalid ID';
    return;
}

// Check if record exists
\$stmt = \$dbContext->prepare("SELECT * FROM ${escapedTable} WHERE id = ?");
\$stmt->execute([\$id]);
\$record = \$stmt->fetch(PDO::FETCH_ASSOC);

if (!\$record) {
    \$response['success'] = false;
    \$response['message'] = 'Record not found';
    return;
}

// Update record
// \$stmt = \$dbContext->prepare("UPDATE ${escapedTable} SET /* columns */ WHERE id = ?");
// \$stmt->execute([/* values, id */]);

\$response['success'] = true;
\$response['message'] = 'Record updated successfully';`;
                }
                break;
            case 'delete':
                code = `// Delete record by ID
\$id = isset(\$request['id']) ? intval(\$request['id']) : 0;
if (\$id <= 0) {
    \$response['success'] = false;
    \$response['message'] = 'Invalid ID';
    return;
}

// Check if record exists
\$stmt = \$dbContext->prepare("SELECT * FROM ${escapedTable} WHERE id = ?");
\$stmt->execute([\$id]);
\$record = \$stmt->fetch(PDO::FETCH_ASSOC);

if (!\$record) {
    \$response['success'] = false;
    \$response['message'] = 'Record not found';
    return;
}

// Delete record
\$stmt = \$dbContext->prepare("DELETE FROM ${escapedTable} WHERE id = ?");
\$stmt->execute([\$id]);

\$response['success'] = true;
\$response['message'] = 'Record deleted successfully';`;
                break;
        }
        
        return code;
    }
    
    window.updateBuilderPreview = async function() {
        const preview = document.getElementById('builder_preview');
        if (preview) {
            preview.textContent = 'Loading preview...';
            try {
                const code = await generateFunctionCode();
                preview.textContent = code || 'Select table and operation to preview code...';
            } catch (error) {
                preview.textContent = `Error: ${error.message}`;
            }
        }
    };
    
    window.generateAndCreateFunction = async function() {
        const form = document.getElementById('builder-form');
        if (!form || !form.checkValidity()) {
            form?.reportValidity();
            return;
        }
        
        const functionName = document.getElementById('builder_function_name')?.value || '';
        const tableName = document.getElementById('builder_table_name')?.value || '';
        const operation = document.getElementById('builder_operation')?.value || '';
        const language = document.getElementById('builder_language')?.value || 'php';
        const description = document.getElementById('builder_description')?.value || '';
        const group = document.getElementById('builder_group')?.value || '';
        const code = await generateFunctionCode();
        
        // Populate the main form
        document.getElementById('function_name').value = functionName;
        document.getElementById('description').value = description;
        document.getElementById('function_group').value = group;
        document.getElementById('language').value = language;
        
        // Update code editor
        if (window.codeEditor) {
            window.codeEditor.setValue(code);
        } else {
            document.getElementById('code').value = code;
        }
        
        // Update language mode
        if (window.updateCodeEditorMode) {
            window.updateCodeEditorMode();
        }
        
        // Hide builder modal
        window.hideFunctionBuilder();
        
        // Scroll to form
        document.getElementById('function-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    
    function filterFunctions(searchTerm) {
        const term = searchTerm.toLowerCase().trim();
        const items = document.querySelectorAll('.function-item');
        
        items.forEach(item => {
            const name = item.dataset.functionName || '';
            
            if (term === '' || name.includes(term)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }
    
    function selectFunction(id) {
        window.location.href = '?edit=' + id;
    }
    
    // Update form submission to sync editor
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('function-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                if (window.codeEditor) {
                    window.codeEditor.save();
                }
            });
        }
    });
</script>
<?php include '../includes/footer.php'; ?>


<?php
require_once '../config/config.php';
requireDeveloper();

$page_title = 'Cloud Middlewares';

$db = getDB();
$error_message = null;
$success_message = null;

// Ensure cloud_middlewares table exists
try {
    $settings = getSettings();
    $dbType = $settings['db_type'] ?? 'sqlite';
    
    if ($dbType === 'mysql') {
        // MySQL syntax
        $db->exec("CREATE TABLE IF NOT EXISTS cloud_middlewares (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL UNIQUE,
            description TEXT,
            code TEXT NOT NULL,
            language VARCHAR(50) DEFAULT 'php',
            enabled TINYINT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by INT,
            FOREIGN KEY (created_by) REFERENCES users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        // SQLite syntax
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
    }
    
    // Add missing columns if they don't exist (for existing tables)
    try {
        $settings = getSettings();
        $dbType = $settings['db_type'] ?? 'sqlite';
        
        if ($dbType === 'sqlite') {
            $stmt = $db->query("PRAGMA table_info(cloud_middlewares)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $column_names = array_column($columns, 'name');
            
            if (!in_array('language', $column_names)) {
                $db->exec("ALTER TABLE cloud_middlewares ADD COLUMN language TEXT DEFAULT 'php'");
            }
        } else {
            // MySQL
            try {
                $db->exec("ALTER TABLE cloud_middlewares ADD COLUMN language VARCHAR(50) DEFAULT 'php'");
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
        case 'save_middleware':
            $middleware_id = intval($_POST['middleware_id'] ?? 0);
            $middleware_name = trim($_POST['middleware_name'] ?? '');
            $middleware_description = trim($_POST['middleware_description'] ?? '');
            $middleware_code = $_POST['middleware_code'] ?? '';
            $language = $_POST['language'] ?? 'php';
            $middleware_enabled = isset($_POST['middleware_enabled']) ? 1 : 0;
            
            // Validate language
            if (!in_array($language, ['php', 'js', 'javascript'])) {
                $language = 'php';
            }
            if ($language === 'javascript') {
                $language = 'js';
            }
            
            if (empty($middleware_name) || empty($middleware_code)) {
                $error_message = "Middleware name and code are required!";
            } else {
                try {
                    if ($middleware_id > 0) {
                        // Update existing middleware
                        $stmt = $db->prepare("
                            UPDATE cloud_middlewares 
                            SET name = ?, description = ?, code = ?, language = ?, enabled = ?, updated_at = CURRENT_TIMESTAMP
                            WHERE id = ?
                        ");
                        $stmt->execute([$middleware_name, $middleware_description, $middleware_code, $language, $middleware_enabled, $middleware_id]);
                        $success_message = "Middleware updated successfully!";
                    } else {
                        // Create new middleware
                        $stmt = $db->prepare("
                            INSERT INTO cloud_middlewares (name, description, code, language, enabled, created_by)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([$middleware_name, $middleware_description, $middleware_code, $language, $middleware_enabled, $_SESSION['user_id']]);
                        $success_message = "Middleware created successfully!";
                    }
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
                        $error_message = "This middleware name is already in use!";
                    } else {
                        $error_message = "Error: " . $e->getMessage();
                    }
                }
            }
            break;
            
        case 'delete_middleware':
            $middleware_id = intval($_POST['middleware_id'] ?? 0);
            if ($middleware_id > 0) {
                try {
                    // Check if middleware is used by any function
                    $stmt = $db->prepare("SELECT COUNT(*) FROM cloud_functions WHERE middleware_id = ?");
                    $stmt->execute([$middleware_id]);
                    $usage_count = $stmt->fetchColumn();
                    
                    if ($usage_count > 0) {
                        $error_message = "This middleware is used by {$usage_count} function(s). Please remove it from functions first!";
                    } else {
                        $stmt = $db->prepare("DELETE FROM cloud_middlewares WHERE id = ?");
                        $stmt->execute([$middleware_id]);
                        $success_message = "Middleware deleted successfully!";
                    }
                } catch (PDOException $e) {
                    $error_message = "Error deleting middleware: " . $e->getMessage();
                }
            }
            break;
    }
    
    // Redirect to prevent form resubmission
    if ($error_message || $success_message) {
        header('Location: cloud-middlewares.php' . ($success_message ? '?success=' . urlencode($success_message) : '') . ($error_message ? '&error=' . urlencode($error_message) : ''));
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

// Get middleware to edit
$edit_middleware = null;
$edit_id = $_GET['edit'] ?? null;
if ($edit_id) {
    $stmt = $db->prepare("SELECT * FROM cloud_middlewares WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_middleware = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all middlewares (handle middleware_id column safely)
try {
    // First check if middleware_id column exists in cloud_functions table
    $settings = getSettings();
    $dbType = $settings['db_type'] ?? 'sqlite';
    $has_middleware_id = false;
    
    if ($dbType === 'sqlite') {
        try {
            $stmt = $db->query("PRAGMA table_info(cloud_functions)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $column_names = array_column($columns, 'name');
            $has_middleware_id = in_array('middleware_id', $column_names);
        } catch (PDOException $e) {
            $has_middleware_id = false;
        }
    } else {
        // MySQL - try to query with middleware_id, if it fails, column doesn't exist
        try {
            $test = $db->query("SELECT middleware_id FROM cloud_functions LIMIT 1");
            $has_middleware_id = true;
        } catch (PDOException $e) {
            $has_middleware_id = false;
        }
    }
    
    if ($has_middleware_id) {
        $middlewares = $db->query("SELECT cm.*, u.username as created_by_name, 
            (SELECT COUNT(*) FROM cloud_functions WHERE middleware_id = cm.id) as usage_count
            FROM cloud_middlewares cm 
            LEFT JOIN users u ON cm.created_by = u.id 
            ORDER BY cm.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // middleware_id column doesn't exist, use simpler query
        $middlewares = $db->query("SELECT cm.*, u.username as created_by_name, 0 as usage_count
            FROM cloud_middlewares cm 
            LEFT JOIN users u ON cm.created_by = u.id 
            ORDER BY cm.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // If query fails, return empty array
    $middlewares = [];
}

include '../includes/header.php';
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/javascript/javascript.min.js"></script>
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
        height: 500px;
        font-size: 14px;
        font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', 'Consolas', monospace;
    }
    
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
                            <h1 class="text-3xl font-bold text-foreground">Cloud Middlewares</h1>
                            <p class="mt-2 text-sm text-muted-foreground">Create and manage request middlewares</p>
                        </div>
                        <button
                            onclick="showCreateForm()"
                            class="inline-flex items-center justify-center rounded-md text-sm font-semibold bg-primary text-primary-foreground hover:bg-primary/90 px-4 py-2.5 transition-colors shadow-sm"
                        >
                            <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                            </svg>
                            New Middleware
                        </button>
                    </div>
                    
                    <!-- Stats Dashboard -->
                    <div class="grid gap-4 md:grid-cols-4 mb-6">
                        <?php 
                        $total_mw = count($middlewares);
                        $active_mw = count(array_filter($middlewares, fn($m) => $m['enabled']));
                        $php_mw = count(array_filter($middlewares, fn($m) => ($m['language'] ?? 'php') === 'php'));
                        $js_mw = count(array_filter($middlewares, fn($m) => ($m['language'] ?? 'php') === 'js'));
                        $used_mw = count(array_filter($middlewares, fn($m) => ($m['usage_count'] ?? 0) > 0));
                        ?>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Total</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $total_mw; ?></p>
                                </div>
                                <div class="rounded-full bg-blue-100 p-3">
                                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Active</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $active_mw; ?></p>
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
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">In Use</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $used_mw; ?></p>
                                </div>
                                <div class="rounded-full bg-purple-100 p-3">
                                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">JS/Total</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $js_mw; ?>/<?php echo $total_mw; ?></p>
                                </div>
                                <div class="rounded-full bg-yellow-100 p-3">
                                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
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
                    <!-- Middlewares List -->
                    <div class="lg:col-span-1">
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm sticky top-6">
                            <div class="p-4 border-b border-border bg-muted/30">
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-lg font-semibold leading-none tracking-tight">Middlewares</h3>
                                    <span class="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                                        <?php echo count($middlewares); ?>
                                    </span>
                                </div>
                                <input 
                                    type="text" 
                                    id="middleware-search"
                                    placeholder="Search middlewares..."
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    oninput="filterMiddlewares(this.value)"
                                >
                            </div>
                            <div class="p-4">
                                <div class="space-y-3 max-h-[calc(100vh-400px)] overflow-y-auto" id="middlewares-list">
                                    <?php if (empty($middlewares)): ?>
                                        <div class="text-center py-12 text-muted-foreground">
                                            <svg class="mx-auto h-12 w-12 mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                                            </svg>
                                            <p class="text-sm font-medium">No middlewares yet</p>
                                            <p class="text-xs mt-1">Create your first middleware to get started</p>
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($middlewares as $mw): 
                                            $lang = ($mw['language'] ?? 'php') === 'js' ? 'js' : 'php';
                                            $langColor = $lang === 'js' ? 'text-yellow-600 bg-yellow-100' : 'text-purple-600 bg-purple-100';
                                        ?>
                                            <div class="rounded-lg border border-border p-4 bg-gradient-to-br from-background to-muted/30 hover:border-primary/50 hover:shadow-md transition-all middleware-item group <?php echo (isset($edit_id) && $edit_id == $mw['id']) ? 'border-primary bg-primary/5 shadow-md' : ''; ?>" 
                                                 data-middleware-id="<?php echo $mw['id']; ?>"
                                                 data-middleware-name="<?php echo strtolower(htmlspecialchars($mw['name'])); ?>"
                                                 onclick="selectMiddleware(<?php echo $mw['id']; ?>)"
                                            >
                                                <div class="flex items-start justify-between gap-3 mb-3">
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-2 mb-2 flex-wrap">
                                                            <h4 class="font-semibold text-sm text-foreground group-hover:text-primary transition-colors truncate">
                                                                <?php echo htmlspecialchars($mw['name']); ?>
                                                            </h4>
                                                            <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-xs font-semibold <?php echo $langColor; ?>">
                                                                <?php echo strtoupper($lang); ?>
                                                            </span>
                                                            <?php if (!$mw['enabled']): ?>
                                                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-800">
                                                                    Inactive
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <?php if ($mw['description']): ?>
                                                            <p class="text-xs text-muted-foreground line-clamp-2"><?php echo htmlspecialchars($mw['description']); ?></p>
                                                        <?php endif; ?>
                                                        <?php if (($mw['usage_count'] ?? 0) > 0): ?>
                                                            <div class="flex items-center gap-1 mt-2 text-xs text-purple-600 font-medium">
                                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                                                </svg>
                                                                Used by <?php echo $mw['usage_count']; ?> function<?php echo $mw['usage_count'] > 1 ? 's' : ''; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-2 pt-3 border-t border-border">
                                                    <a
                                                        href="?edit=<?php echo $mw['id']; ?>"
                                                        class="flex-1 inline-flex items-center justify-center rounded-md text-xs font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-3 py-1.5 transition-colors"
                                                        onclick="event.stopPropagation()"
                                                    >
                                                        Edit
                                                    </a>
                                                    <button
                                                        onclick="event.stopPropagation(); deleteMiddleware(<?php echo $mw['id']; ?>, '<?php echo htmlspecialchars(addslashes($mw['name'])); ?>')"
                                                        class="inline-flex items-center justify-center rounded-md text-xs font-medium bg-red-100 text-red-800 hover:bg-red-200 px-3 py-1.5 transition-colors"
                                                    >
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Middleware Editor -->
                    <div class="lg:col-span-2">
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">
                                    <?php echo $edit_middleware ? 'Edit Middleware' : 'New Middleware'; ?>
                                </h3>
                                
                                <form method="POST" id="middleware-form">
                                    <input type="hidden" name="action" value="save_middleware">
                                    <input type="hidden" name="middleware_id" value="<?php echo $edit_middleware ? $edit_middleware['id'] : '0'; ?>">
                                    
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-medium text-foreground mb-1.5">
                                                Middleware Name *
                                            </label>
                                            <input
                                                type="text"
                                                name="middleware_name"
                                                id="middleware_name"
                                                value="<?php echo htmlspecialchars($edit_middleware['name'] ?? ''); ?>"
                                                required
                                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                placeholder="auth-check"
                                            />
                                        </div>
                                        
                                        <div>
                                            <label class="block text-sm font-medium text-foreground mb-1.5">
                                                Description
                                            </label>
                                            <input
                                                type="text"
                                                name="middleware_description"
                                                id="middleware_description"
                                                value="<?php echo htmlspecialchars($edit_middleware['description'] ?? ''); ?>"
                                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                placeholder="User authentication check"
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
                                                <option value="php" <?php echo (!isset($edit_middleware['language']) || $edit_middleware['language'] === 'php') ? 'selected' : ''; ?>>PHP</option>
                                                <option value="js" <?php echo (isset($edit_middleware['language']) && ($edit_middleware['language'] === 'js' || $edit_middleware['language'] === 'javascript')) ? 'selected' : ''; ?>>JavaScript (Node.js)</option>
                                            </select>
                                            <p class="mt-1 text-xs text-muted-foreground">
                                                Programming language for middleware code
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
                                                name="middleware_code"
                                                id="middleware_code"
                                                required
                                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono text-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                                                rows="20"
                                            ><?php 
                                            $defaultCode = "// Middleware Code\n// Available variables:\n// \$dbContext - Database connection (PDO object)\n// \$request - Request body data (array)\n// \$method - HTTP method (string)\n// \$headers - Request headers (array)\n// \$response - Response array (must set this)\n\n// Example: Authentication check\n// if (!isset(\$headers['Authorization'])) {\n//     \$response['success'] = false;\n//     \$response['message'] = 'Unauthorized: Missing Authorization header';\n//     return;\n// }\n\n// Example: API Key validation\n// \$api_key = \$headers['X-API-Key'] ?? null;\n// if (!\$api_key) {\n//     \$response['success'] = false;\n//     \$response['message'] = 'API key required';\n//     return;\n// }\n// \$stmt = \$dbContext->prepare(\"SELECT * FROM api_keys WHERE key = ? AND enabled = 1\");\n// \$stmt->execute([\$api_key]);\n// if (!\$stmt->fetch()) {\n//     \$response['success'] = false;\n//     \$response['message'] = 'Invalid API key';\n//     return;\n// }\n\n// If middleware passes, don't set response (or set success = true)\n// \$response['success'] = true;\n";
                                            
                                            $defaultJsCode = "// Middleware Code (JavaScript/Node.js)\n// Available variables:\n// request - Request body data (object)\n// method - HTTP method (string)\n// headers - Request headers (object)\n// response - Response object (must set this)\n// dbQuery(sql, params) - Execute SELECT query (returns array) - Works with SQLite & MySQL\n// dbQueryOne(sql, params) - Execute SELECT query (returns single row) - Works with SQLite & MySQL\n// dbExecute(sql, params) - Execute INSERT/UPDATE/DELETE (returns {changes, lastInsertRowid}) - Works with SQLite & MySQL\n\n// Example: Authentication check\n// if (!headers.authorization) {\n//     response.success = false;\n//     response.message = 'Unauthorized: Missing Authorization header';\n//     return;\n// }\n\n// Example: API Key validation with database\n// try {\n//     const apiKey = headers['x-api-key'] || headers['X-API-Key'] || null;\n//     if (!apiKey) {\n//         response.success = false;\n//         response.message = 'API key required';\n//         return;\n//     }\n//     const keyRecord = await dbQueryOne('SELECT * FROM api_keys WHERE key = ? AND enabled = 1', [apiKey]);\n//     if (!keyRecord) {\n//         response.success = false;\n//         response.message = 'Invalid API key';\n//         return;\n//     }\n//     // API key is valid, continue\n// } catch (error) {\n//     response.success = false;\n//     response.message = 'Database error: ' + error.message;\n//     return;\n// }\n\n// Example: User authentication\n// try {\n//     const token = headers.authorization || headers['Authorization'] || null;\n//     if (!token) {\n//         response.success = false;\n//         response.message = 'Authorization token required';\n//         return;\n//     }\n//     const user = await dbQueryOne('SELECT * FROM users WHERE api_token = ?', [token]);\n//     if (!user || !user.enabled) {\n//         response.success = false;\n//         response.message = 'Invalid or disabled user';\n//         return;\n//     }\n//     // User is authenticated, continue\n// } catch (error) {\n//     response.success = false;\n//     response.message = 'Authentication error: ' + error.message;\n//     return;\n// }\n\n// Example: Method validation\n// if (method !== 'POST') {\n//     response.success = false;\n//     response.message = 'Only POST method allowed';\n//     return;\n// }\n\n// If middleware passes, don't set response (or set success = true)\n// response.success = true;\n";
                                            
                                            $selectedLanguage = $edit_middleware['language'] ?? 'php';
                                            $codeToShow = ($selectedLanguage === 'js' || $selectedLanguage === 'javascript') ? $defaultJsCode : $defaultCode;
                                            echo htmlspecialchars($edit_middleware['code'] ?? $codeToShow); 
                                            ?></textarea>
                                            </div>
                                        </div>
                                        
                                        <div class="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                name="middleware_enabled"
                                                id="middleware_enabled"
                                                <?php echo (!$edit_middleware || $edit_middleware['enabled']) ? 'checked' : ''; ?>
                                                class="rounded border-input"
                                            />
                                            <label for="middleware_enabled" class="text-sm font-medium text-foreground">
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
                                                <?php echo $edit_middleware ? 'Update' : 'Create'; ?>
                                            </button>
                                            <?php if ($edit_middleware): ?>
                                                <a
                                                    href="cloud-middlewares.php"
                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-4 py-2 transition-colors"
                                                >
                                                    Cancel
                                                </a>
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

<script>
    // Initialize CodeMirror
    const middlewareCodeTextarea = document.getElementById('middleware_code');
    if (!middlewareCodeTextarea) {
        console.error('Middleware code textarea not found');
    }
    
    // Determine initial mode based on language
    const middlewareLanguageSelect = document.getElementById('language');
    const initialMiddlewareLanguage = middlewareLanguageSelect ? middlewareLanguageSelect.value : 'php';
    const initialMiddlewareMode = initialMiddlewareLanguage === 'js' ? 'javascript' : 'php';
    
    const middlewareEditor = CodeMirror.fromTextArea(middlewareCodeTextarea, {
        mode: initialMiddlewareMode === 'javascript' ? 'javascript' : {
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
                
                // Custom hints for middleware context
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
    middlewareEditor.on('inputRead', function(cm, change) {
        if (change.text[0].length > 0 && change.text[0][0].match(/[a-zA-Z]/)) {
            setTimeout(function() {
                CodeMirror.commands.autocomplete(cm);
            }, 100);
        }
    });
    
    // Sync editor with textarea
    middlewareEditor.on('change', function(cm) {
        cm.save();
    });
    
    // Function to update editor mode when language changes
    window.updateCodeEditorMode = function() {
        if (!middlewareEditor) return;
        const middlewareLanguageSelect = document.getElementById('language');
        const selectedLanguage = middlewareLanguageSelect ? middlewareLanguageSelect.value : 'php';
        const newMode = selectedLanguage === 'js' ? 'javascript' : 'php';
        
        // Change mode
        middlewareEditor.setOption('mode', newMode === 'javascript' ? 'javascript' : {
            name: 'php',
            startOpen: true
        });
        
        // Update example code if editor is empty or contains default code
        const currentValue = middlewareEditor.getValue().trim();
        if (!currentValue || currentValue.startsWith('// Middleware Code') || currentValue === '') {
            let exampleCode = '';
            if (selectedLanguage === 'js') {
                exampleCode = `// Middleware Code (JavaScript/Node.js)
// Available variables:
// request - Request body data (object)
// method - HTTP method (string)
// headers - Request headers (object)
// response - Response object (must set this)
// dbQuery(sql, params) - Execute SELECT query (returns array) - Works with SQLite & MySQL
// dbQueryOne(sql, params) - Execute SELECT query (returns single row) - Works with SQLite & MySQL
// dbExecute(sql, params) - Execute INSERT/UPDATE/DELETE (returns {changes, lastInsertRowid}) - Works with SQLite & MySQL

// Example: Authentication check
// if (!headers.authorization) {
//     response.success = false;
//     response.message = 'Unauthorized: Missing Authorization header';
//     return;
// }

// Example: API Key validation with database
// try {
//     const apiKey = headers['x-api-key'] || headers['X-API-Key'] || null;
//     if (!apiKey) {
//         response.success = false;
//         response.message = 'API key required';
//         return;
//     }
//     const keyRecord = await dbQueryOne('SELECT * FROM api_keys WHERE key = ? AND enabled = 1', [apiKey]);
//     if (!keyRecord) {
//         response.success = false;
//         response.message = 'Invalid API key';
//         return;
//     }
//     // API key is valid, continue
// } catch (error) {
//     response.success = false;
//     response.message = 'Database error: ' + error.message;
//     return;
// }

// Example: User authentication
// try {
//     const token = headers.authorization || headers['Authorization'] || null;
//     if (!token) {
//         response.success = false;
//         response.message = 'Authorization token required';
//         return;
//     }
//     const user = await dbQueryOne('SELECT * FROM users WHERE api_token = ?', [token]);
//     if (!user || !user.enabled) {
//         response.success = false;
//         response.message = 'Invalid or disabled user';
//         return;
//     }
//     // User is authenticated, continue
// } catch (error) {
//     response.success = false;
//     response.message = 'Authentication error: ' + error.message;
//     return;
// }

// Example: Method validation
// if (method !== 'POST') {
//     response.success = false;
//     response.message = 'Only POST method allowed';
//     return;
// }

// If middleware passes, don't set response (or set success = true)
// response.success = true;`;
            } else {
                exampleCode = `// Middleware Code
// Available variables:
// \$dbContext - Database connection (PDO object)
// \$request - Request body data (array)
// \$method - HTTP method (string)
// \$headers - Request headers (array)
// \$response - Response array (must set this)

// Example: Authentication check
// if (!isset(\$headers['Authorization'])) {
//     \$response['success'] = false;
//     \$response['message'] = 'Unauthorized: Missing Authorization header';
//     return;
// }

// Example: API Key validation
// \$api_key = \$headers['X-API-Key'] ?? null;
// if (!\$api_key) {
//     \$response['success'] = false;
//     \$response['message'] = 'API key required';
//     return;
// }
// \$stmt = \$dbContext->prepare("SELECT * FROM api_keys WHERE key = ? AND enabled = 1");
// \$stmt->execute([\$api_key]);
// if (!\$stmt->fetch()) {
//     \$response['success'] = false;
//     \$response['message'] = 'Invalid API key';
//     return;
// }

// If middleware passes, don't set response (or set success = true)
// \$response['success'] = true;`;
            }
            middlewareEditor.setValue(exampleCode);
        }
    };
    
    let isFullscreen = false;
    
    function toggleFullscreen() {
        const wrapper = document.getElementById('code-editor-wrapper');
        const btn = document.getElementById('fullscreen-btn');
        
        if (!isFullscreen) {
            wrapper.classList.add('fullscreen');
            btn.innerHTML = `
                <svg class="mr-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                Exit
            `;
            isFullscreen = true;
            middlewareEditor.refresh();
            middlewareEditor.focus();
        } else {
            wrapper.classList.remove('fullscreen');
            btn.innerHTML = `
                <svg class="mr-1 h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                </svg>
                Fullscreen
            `;
            isFullscreen = false;
            middlewareEditor.refresh();
        }
    }
    
    // ESC key to exit fullscreen
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && isFullscreen) {
            toggleFullscreen();
        }
    });
    
    function showCreateForm() {
        window.location.href = 'cloud-middlewares.php';
    }
    
    function filterMiddlewares(searchTerm) {
        const term = searchTerm.toLowerCase().trim();
        const items = document.querySelectorAll('.middleware-item');
        
        items.forEach(item => {
            const name = item.dataset.middlewareName || '';
            
            if (term === '' || name.includes(term)) {
                item.style.display = 'block';
            } else {
                item.style.display = 'none';
            }
        });
    }
    
    function selectMiddleware(id) {
        window.location.href = '?edit=' + id;
    }
    
    function deleteMiddleware(id, name) {
        if (confirm('Are you sure you want to delete "' + name + '"? This action cannot be undone.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="action" value="delete_middleware"><input type="hidden" name="middleware_id" value="' + id + '">';
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

<?php include '../includes/footer.php'; ?>

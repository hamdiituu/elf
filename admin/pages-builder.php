<?php
require_once __DIR__ . '/../config/config.php';
requireDeveloper();

$page_title = 'Pages Builder';
$db = getDB();

// Ensure dynamic_pages table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS dynamic_pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_name TEXT NOT NULL UNIQUE,
        page_title TEXT NOT NULL,
        table_name TEXT NOT NULL,
        group_name TEXT,
        enable_list INTEGER DEFAULT 1,
        enable_create INTEGER DEFAULT 1,
        enable_update INTEGER DEFAULT 1,
        enable_delete INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add group_name column if it doesn't exist (migration)
    try {
        $db->exec("ALTER TABLE dynamic_pages ADD COLUMN group_name TEXT");
    } catch (PDOException $e) {
        // Column might already exist
    }
    
    // Add rule columns if they don't exist (migration)
    $rule_columns = ['create_rule', 'update_rule', 'delete_rule'];
    foreach ($rule_columns as $col) {
        try {
            $db->exec("ALTER TABLE dynamic_pages ADD COLUMN $col TEXT");
        } catch (PDOException $e) {
            // Column might already exist
        }
    }
} catch (PDOException $e) {
    // Table might already exist
}

$error_message = null;
$success_message = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_page':
                $page_name = trim($_POST['page_name'] ?? '');
                $page_title = trim($_POST['page_title'] ?? '');
                $table_name = trim($_POST['table_name'] ?? '');
                $group_name = trim($_POST['group_name'] ?? '');
                $enable_list = isset($_POST['enable_list']) ? 1 : 0;
                $enable_create = isset($_POST['enable_create']) ? 1 : 0;
                $enable_update = isset($_POST['enable_update']) ? 1 : 0;
                $enable_delete = isset($_POST['enable_delete']) ? 1 : 0;
                $create_rule = trim($_POST['create_rule'] ?? '');
                $update_rule = trim($_POST['update_rule'] ?? '');
                $delete_rule = trim($_POST['delete_rule'] ?? '');
                
                if (empty($page_name) || empty($page_title) || empty($table_name)) {
                    $error_message = "Page name, title and table name are required!";
                } else {
                    // Validate page_name (for filename)
                    if (!preg_match('/^[a-z0-9_-]+$/', strtolower($page_name))) {
                        $error_message = "Page name can only contain lowercase letters, numbers, underscore and dash!";
                    } else {
                        try {
                            // Validate and escape table name
                            $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
                            if ($escaped_table_name !== $table_name) {
                                $error_message = "Invalid table name!";
                            } else {
                                // Check if table exists
                                $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
                                $stmt->execute([$table_name]);
                                if (!$stmt->fetch()) {
                                    $error_message = "Selected table not found!";
                                } else {
                                    // Check if page_name already exists
                                    $stmt = $db->prepare("SELECT id FROM dynamic_pages WHERE page_name = ?");
                                    $stmt->execute([$page_name]);
                                    if ($stmt->fetch()) {
                                        $error_message = "This page name is already in use!";
                                    } else {
                                        // Insert into dynamic_pages
                                        $stmt = $db->prepare("INSERT INTO dynamic_pages (page_name, page_title, table_name, group_name, enable_list, enable_create, enable_update, enable_delete, create_rule, update_rule, delete_rule) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                        $stmt->execute([$page_name, $page_title, $table_name, $group_name ?: null, $enable_list, $enable_create, $enable_update, $enable_delete, $create_rule ?: null, $update_rule ?: null, $delete_rule ?: null]);
                                        $success_message = "Page created successfully: $page_name";
                                    }
                                }
                            }
                        } catch (PDOException $e) {
                            $error_message = "Error creating page: " . $e->getMessage();
                        }
                    }
                }
                break;
                
            case 'update_page':
                $page_id = intval($_POST['page_id'] ?? 0);
                $page_name = trim($_POST['page_name'] ?? '');
                $page_title = trim($_POST['page_title'] ?? '');
                $table_name = trim($_POST['table_name'] ?? '');
                $group_name = trim($_POST['group_name'] ?? '');
                $enable_list = isset($_POST['enable_list']) ? 1 : 0;
                $enable_create = isset($_POST['enable_create']) ? 1 : 0;
                $enable_update = isset($_POST['enable_update']) ? 1 : 0;
                $enable_delete = isset($_POST['enable_delete']) ? 1 : 0;
                $create_rule = trim($_POST['create_rule'] ?? '');
                $update_rule = trim($_POST['update_rule'] ?? '');
                $delete_rule = trim($_POST['delete_rule'] ?? '');
                
                if ($page_id > 0 && !empty($page_name) && !empty($page_title) && !empty($table_name)) {
                    // Validate page_name (for filename)
                    if (!preg_match('/^[a-z0-9_-]+$/', strtolower($page_name))) {
                        $error_message = "Page name can only contain lowercase letters, numbers, underscore and dash!";
                    } else {
                        try {
                            // Check if page_name already exists (excluding current page)
                            $stmt = $db->prepare("SELECT id FROM dynamic_pages WHERE page_name = ? AND id != ?");
                            $stmt->execute([$page_name, $page_id]);
                            if ($stmt->fetch()) {
                                $error_message = "Bu sayfa adı zaten kullanılıyor!";
                            } else {
                                // Validate and escape table name
                                $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
                                if ($escaped_table_name !== $table_name) {
                                    $error_message = "Invalid table name!";
                                } else {
                                    // Check if table exists
                                    $stmt = $db->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
                                    $stmt->execute([$table_name]);
                                    if (!$stmt->fetch()) {
                                        $error_message = "Selected table not found!";
                                    } else {
                                        // Update dynamic_pages
                                        $stmt = $db->prepare("UPDATE dynamic_pages SET page_name = ?, page_title = ?, table_name = ?, group_name = ?, enable_list = ?, enable_create = ?, enable_update = ?, enable_delete = ?, create_rule = ?, update_rule = ?, delete_rule = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                                        $stmt->execute([$page_name, $page_title, $table_name, $group_name ?: null, $enable_list, $enable_create, $enable_update, $enable_delete, $create_rule ?: null, $update_rule ?: null, $delete_rule ?: null, $page_id]);
                                        $success_message = "Page updated successfully: $page_name";
                                    }
                                }
                            }
                        } catch (PDOException $e) {
                            $error_message = "Error updating page: " . $e->getMessage();
                        }
                    }
                }
                break;
                
            case 'delete_page':
                $page_id = intval($_POST['page_id'] ?? 0);
                if ($page_id > 0) {
                    try {
                        $stmt = $db->prepare("SELECT page_name FROM dynamic_pages WHERE id = ?");
                        $stmt->execute([$page_id]);
                        $page = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($page) {
                            // Delete from database
                            $stmt = $db->prepare("DELETE FROM dynamic_pages WHERE id = ?");
                            $stmt->execute([$page_id]);
                            
                            $success_message = "Page deleted successfully!";
                        }
                    } catch (PDOException $e) {
                        $error_message = "Error deleting page: " . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Function removed - pages are now stored in database and rendered via dynamic-page.php
// Pages are no longer generated as physical files - they are rendered from database configuration

// Get all tables
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Get page to edit
$edit_page = null;
$edit_id = $_GET['edit'] ?? null;
if ($edit_id) {
    $stmt = $db->prepare("SELECT * FROM dynamic_pages WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_page = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all dynamic pages
$dynamic_pages = $db->query("SELECT * FROM dynamic_pages ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

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
        height: 300px !important;
        font-size: 14px;
        font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', 'Consolas', monospace;
    }
    
    .CodeMirror-activeline-background {
        background: hsl(var(--muted) / 0.3);
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
                            <h1 class="text-3xl font-bold text-foreground">Pages Builder</h1>
                            <p class="mt-2 text-sm text-muted-foreground">Create dynamic CRUD pages from database tables</p>
                        </div>
                    </div>
                    
                    <!-- Stats Dashboard -->
                    <div class="grid gap-4 md:grid-cols-4 mb-6">
                        <?php 
                        $total_pages = count($dynamic_pages);
                        $with_list = count(array_filter($dynamic_pages, fn($p) => $p['enable_list']));
                        $with_create = count(array_filter($dynamic_pages, fn($p) => $p['enable_create']));
                        $with_update = count(array_filter($dynamic_pages, fn($p) => $p['enable_update']));
                        $with_delete = count(array_filter($dynamic_pages, fn($p) => $p['enable_delete']));
                        $grouped_pages = array_filter($dynamic_pages, fn($p) => !empty($p['group_name']));
                        ?>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Total Pages</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $total_pages; ?></p>
                                </div>
                                <div class="rounded-full bg-blue-100 p-3">
                                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">List Enabled</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $with_list; ?></p>
                                </div>
                                <div class="rounded-full bg-green-100 p-3">
                                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Create Enabled</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $with_create; ?></p>
                                </div>
                                <div class="rounded-full bg-purple-100 p-3">
                                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Grouped</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo count($grouped_pages); ?></p>
                                </div>
                                <div class="rounded-full bg-yellow-100 p-3">
                                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="mb-6 rounded-md bg-red-50 p-4 border border-red-200">
                        <div class="text-sm text-red-800">
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="mb-6 rounded-md bg-green-50 p-4 border border-green-200">
                        <div class="text-sm text-green-800">
                            <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Create New Page Form -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 border-b border-border bg-muted/30">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold leading-none tracking-tight">
                                <?php echo $edit_page ? 'Edit Page' : 'Create New Page'; ?>
                            </h3>
                            <?php if ($edit_page): ?>
                                <a
                                    href="pages-builder.php"
                                    class="inline-flex items-center justify-center rounded-md text-xs font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-3 py-1.5 transition-colors"
                                >
                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                    Cancel
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="p-6">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="<?php echo $edit_page ? 'update_page' : 'create_page'; ?>">
                            <?php if ($edit_page): ?>
                                <input type="hidden" name="page_id" value="<?php echo $edit_page['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="grid gap-4 md:grid-cols-2 mb-6">
                                <div>
                                    <label for="page_name" class="block text-sm font-medium text-foreground mb-2">
                                        Page Name (URL) <span class="text-red-500">*</span>
                                    </label>
                                <input
                                    type="text"
                                    id="page_name"
                                    name="page_name"
                                    required
                                    pattern="[a-z0-9_-]+"
                                    title="Only lowercase letters, numbers, underscore and dash allowed"
                                    value="<?php echo htmlspecialchars($edit_page['page_name'] ?? ''); ?>"
                                    <?php echo $edit_page ? 'readonly' : ''; ?>
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent <?php echo $edit_page ? 'bg-muted cursor-not-allowed' : ''; ?>"
                                    placeholder="ornek-sayfa"
                                >
                                    <p class="mt-1 text-xs text-muted-foreground">Only lowercase letters, numbers, underscore (_) and dash (-)</p>
                                </div>
                                
                                <div>
                                    <label for="page_title" class="block text-sm font-medium text-foreground mb-2">
                                        Page Title <span class="text-red-500">*</span>
                                    </label>
                                <input
                                    type="text"
                                    id="page_title"
                                    name="page_title"
                                    required
                                    value="<?php echo htmlspecialchars($edit_page['page_title'] ?? ''); ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="Example Page"
                                    >
                                </div>
                            </div>
                            
                            <div class="grid gap-4 md:grid-cols-2 mb-6">
                                <div>
                                    <label for="table_name" class="block text-sm font-medium text-foreground mb-2">
                                        Table <span class="text-red-500">*</span>
                                    </label>
                                <select
                                    id="table_name"
                                    name="table_name"
                                    required
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent <?php echo $edit_page ? 'bg-muted cursor-not-allowed' : ''; ?>"
                                    <?php echo $edit_page ? 'disabled' : ''; ?>
                                >
                                    <option value="">-- Select Table --</option>
                                    <?php foreach ($tables as $table): ?>
                                        <option value="<?php echo htmlspecialchars($table); ?>" <?php echo ($edit_page && $edit_page['table_name'] === $table) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($table); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($edit_page): ?>
                                    <input type="hidden" name="table_name" value="<?php echo htmlspecialchars($edit_page['table_name']); ?>">
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-4">
                                <label for="group_name" class="block text-sm font-medium text-foreground mb-1.5">
                                    Group Name
                                </label>
                                <input
                                    type="text"
                                    id="group_name"
                                    name="group_name"
                                    value="<?php echo htmlspecialchars($edit_page['group_name'] ?? ''); ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="e.g., Order Management"
                                    >
                                    <p class="mt-1 text-xs text-muted-foreground">Page will be listed under this group in sidebar. Leave empty to show without a group.</p>
                                </div>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-foreground mb-3">
                                    Enabled Operations
                                </label>
                                <div class="grid gap-3 md:grid-cols-2">
                                    <label class="flex items-center p-3 rounded-lg border border-border hover:bg-muted/50 transition-colors cursor-pointer">
                                        <input
                                            type="checkbox"
                                            name="enable_list"
                                            value="1"
                                            <?php echo ($edit_page && $edit_page['enable_list']) || !$edit_page ? 'checked' : ''; ?>
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <div class="ml-3 flex-1">
                                            <span class="text-sm font-medium text-foreground">List</span>
                                            <p class="text-xs text-muted-foreground">View and browse records</p>
                                        </div>
                                    </label>
                                    <label class="flex items-center p-3 rounded-lg border border-border hover:bg-muted/50 transition-colors cursor-pointer">
                                        <input
                                            type="checkbox"
                                            name="enable_create"
                                            value="1"
                                            <?php echo ($edit_page && $edit_page['enable_create']) || !$edit_page ? 'checked' : ''; ?>
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <div class="ml-3 flex-1">
                                            <span class="text-sm font-medium text-foreground">Create</span>
                                            <p class="text-xs text-muted-foreground">Add new records</p>
                                        </div>
                                    </label>
                                    <label class="flex items-center p-3 rounded-lg border border-border hover:bg-muted/50 transition-colors cursor-pointer">
                                        <input
                                            type="checkbox"
                                            name="enable_update"
                                            value="1"
                                            <?php echo ($edit_page && $edit_page['enable_update']) || !$edit_page ? 'checked' : ''; ?>
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <div class="ml-3 flex-1">
                                            <span class="text-sm font-medium text-foreground">Update</span>
                                            <p class="text-xs text-muted-foreground">Edit existing records</p>
                                        </div>
                                    </label>
                                    <label class="flex items-center p-3 rounded-lg border border-border hover:bg-muted/50 transition-colors cursor-pointer">
                                        <input
                                            type="checkbox"
                                            name="enable_delete"
                                            value="1"
                                            <?php echo ($edit_page && $edit_page['enable_delete']) || !$edit_page ? 'checked' : ''; ?>
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <div class="ml-3 flex-1">
                                            <span class="text-sm font-medium text-foreground">Delete</span>
                                            <p class="text-xs text-muted-foreground">Remove records</p>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-foreground mb-3">
                                    Rules (PHP Code)
                                </label>
                                <div class="rounded-lg bg-blue-50 border border-blue-200 p-4 mb-4">
                                    <p class="text-xs text-blue-800 mb-2">
                                        Write PHP code rules for each operation. These rules will be executed before the operation. Available variables:
                                    </p>
                                    <div class="grid gap-2 text-xs text-blue-700">
                                        <div><code class="bg-blue-100 px-1.5 py-0.5 rounded">$record</code> - Current record data (array)</div>
                                        <div><code class="bg-blue-100 px-1.5 py-0.5 rounded">$columns</code> - Column information (array)</div>
                                        <div><code class="bg-blue-100 px-1.5 py-0.5 rounded">$is_edit</code> - Is edit mode? (boolean, for create/update)</div>
                                        <div><code class="bg-blue-100 px-1.5 py-0.5 rounded">$db</code> - Database connection (PDO)</div>
                                        <div><code class="bg-blue-100 px-1.5 py-0.5 rounded">$dbContext</code> - Database connection (PDO, compatible with cloud-functions)</div>
                                    </div>
                                </div>
                                
                                <div class="space-y-4">
                                    <div class="mb-4">
                                        <label for="create_rule" class="block text-sm font-medium text-foreground mb-2">
                                            Create Rule
                                        </label>
                                        <textarea
                                            id="create_rule"
                                            name="create_rule"
                                            rows="4"
                                            class="hidden"
                                        ></textarea>
                                        <div class="mb-2">
                                            <details class="text-xs">
                                                <summary class="cursor-pointer text-muted-foreground hover:text-foreground mb-2">Show example code</summary>
                                                <div class="bg-muted p-3 rounded-md font-mono text-xs overflow-x-auto">
                                                    <div class="mb-2"><strong>Example 1 - Simple validation:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
if (empty($record[\'name\'])) {
    throw new Exception(\'İsim alanı boş olamaz!\');
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Example 2 - Database query:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Email kontrolü
$stmt = $dbContext->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
$stmt->execute([$record[\'email\'] ?? \'\']);
$exists = $stmt->fetchColumn();

if ($exists > 0) {
    throw new Exception(\'Bu email adresi zaten kullanılıyor!\');
}

// İlişkili kayıt kontrolü
$stmt = $dbContext->prepare("SELECT * FROM categories WHERE id = ? AND status = \'active\'");
$stmt->execute([$record[\'category_id\'] ?? 0]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$category) {
    throw new Exception(\'Geçersiz kategori seçildi!\');
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Example 3 - Multiple validations:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Birden fazla alan kontrolü
$errors = [];

if (empty($record[\'name\'])) {
    $errors[] = \'İsim gereklidir\';
}

if (empty($record[\'email\'])) {
    $errors[] = \'Email gereklidir\';
} elseif (!filter_var($record[\'email\'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = \'Geçersiz email formatı\';
}

// Veritabanından kontrol
$stmt = $dbContext->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
$stmt->execute([$record[\'username\'] ?? \'\']);
if ($stmt->fetchColumn() > 0) {
    $errors[] = \'Bu kullanıcı adı zaten kullanılıyor\';
}

if (!empty($errors)) {
    throw new Exception(implode(\', \', $errors));
}
?>'); ?></pre>
                                                </div>
                                            </details>
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground">You can write PHP code. You can stop the operation by throwing an Exception.</p>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label for="update_rule" class="block text-sm font-medium text-foreground mb-2">
                                            Update Rule
                                        </label>
                                        <textarea
                                            id="update_rule"
                                            name="update_rule"
                                            rows="4"
                                            class="hidden"
                                        ><?php echo htmlspecialchars($edit_page['update_rule'] ?? ''); ?></textarea>
                                        <div class="mb-2">
                                            <details class="text-xs">
                                                <summary class="cursor-pointer text-muted-foreground hover:text-foreground mb-2">Show example code</summary>
                                                <div class="bg-muted p-3 rounded-md font-mono text-xs overflow-x-auto">
                                                    <div class="mb-2"><strong>Example 1 - Current record validation:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Mevcut kaydın durumunu kontrol et
$stmt = $dbContext->prepare("SELECT status FROM " . $table_name . " WHERE id = ?");
$stmt->execute([$record[\'id\']]);
$current_status = $stmt->fetchColumn();

if ($current_status === \'locked\') {
    throw new Exception(\'Kilitli kayıt güncellenemez!\');
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Example 2 - Change validation:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Mevcut kaydı al
$stmt = $dbContext->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$record[\'id\']]);
$current_record = $stmt->fetch(PDO::FETCH_ASSOC);

// Kritik alanlar değiştirilmişse kontrol et
if ($current_record[\'email\'] !== $record[\'email\']) {
    // Yeni email başka bir kullanıcıda var mı?
    $stmt = $dbContext->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
    $stmt->execute([$record[\'email\'], $record[\'id\']]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception(\'Bu email adresi başka bir kullanıcı tarafından kullanılıyor!\');
    }
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Example 3 - Permission check:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Kullanıcının bu kaydı güncelleme yetkisi var mı?
$stmt = $dbContext->prepare("SELECT created_by FROM posts WHERE id = ?");
$stmt->execute([$record[\'id\']]);
$post = $stmt->fetch(PDO::FETCH_ASSOC);

if ($post && $post[\'created_by\'] != $_SESSION[\'user_id\']) {
    throw new Exception(\'Bu kaydı güncelleme yetkiniz yok!\');
}
?>'); ?></pre>
                                                </div>
                                            </details>
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground">You can write PHP code. <code>$record</code> contains the record data to be updated, <code>$current_record</code> contains the current record.</p>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label for="delete_rule" class="block text-sm font-medium text-foreground mb-2">
                                            Delete Rule
                                        </label>
                                        <textarea
                                            id="delete_rule"
                                            name="delete_rule"
                                            rows="4"
                                            class="hidden"
                                        ><?php echo htmlspecialchars($edit_page['delete_rule'] ?? ''); ?></textarea>
                                        <div class="mb-2">
                                            <details class="text-xs">
                                                <summary class="cursor-pointer text-muted-foreground hover:text-foreground mb-2">Show example code</summary>
                                                <div class="bg-muted p-3 rounded-md font-mono text-xs overflow-x-auto">
                                                    <div class="mb-2"><strong>Example 1 - Related record check:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Silinecek kayıt başka tablolarda kullanılıyor mu?
$stmt = $dbContext->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = ?");
$stmt->execute([$record[\'id\']]);
$related_count = $stmt->fetchColumn();

if ($related_count > 0) {
    throw new Exception(\'Bu ürün \' . $related_count . \' siparişte kullanıldığı için silinemez!\');
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Example 2 - Status check:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Sadece belirli durumdaki kayıtlar silinebilir
if ($record[\'status\'] === \'active\') {
    throw new Exception(\'Aktif kayıtlar silinemez! Önce pasif hale getirin.\');
}

if ($record[\'status\'] === \'locked\') {
    throw new Exception(\'Kilitli kayıtlar silinemez!\');
}
?>'); ?></pre>
                                                    
                                                    <div class="mt-3 mb-2"><strong>Example 3 - Cascade delete check:</strong></div>
                                                    <pre><?php echo htmlspecialchars('<?php
// Alt kayıtları kontrol et
$stmt = $dbContext->prepare("SELECT COUNT(*) FROM child_table WHERE parent_id = ?");
$stmt->execute([$record[\'id\']]);
$children_count = $stmt->fetchColumn();

if ($children_count > 0) {
    // Önce alt kayıtları sil
    $stmt = $dbContext->prepare("DELETE FROM child_table WHERE parent_id = ?");
    $stmt->execute([$record[\'id\']]);
    
    // Veya hata ver
    // throw new Exception(\'Önce alt kayıtları silmelisiniz!\');
}
?>'); ?></pre>
                                                </div>
                                            </details>
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground">You can write PHP code. <code>$record</code> contains the record data to be deleted.</p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-3 pt-4 border-t border-border">
                                <button
                                    type="submit"
                                    class="inline-flex items-center justify-center rounded-md text-sm font-semibold bg-primary text-primary-foreground hover:bg-primary/90 px-6 py-2.5 transition-colors shadow-sm"
                                >
                                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                    </svg>
                                    <?php echo $edit_page ? 'Update Page' : 'Create Page'; ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Existing Dynamic Pages -->
                <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 border-b border-border bg-muted/30">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold leading-none tracking-tight">Created Pages</h3>
                            <span class="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                                <?php echo count($dynamic_pages); ?> pages
                            </span>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (empty($dynamic_pages)): ?>
                            <div class="text-center py-16 text-muted-foreground">
                                <svg class="mx-auto h-16 w-16 mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                                <p class="text-base font-medium">No pages created yet</p>
                                <p class="text-xs mt-1">Create your first dynamic page above</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border bg-muted/50">
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Page Name</th>
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Page Title</th>
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Group</th>
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Table</th>
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Operations</th>
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Created</th>
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dynamic_pages as $page): ?>
                                            <tr class="border-b border-border transition-colors hover:bg-muted/30">
                                                <td class="p-4 align-middle">
                                                    <a href="dynamic-page.php?page=<?php echo urlencode($page['page_name']); ?>" class="text-sm font-semibold text-primary hover:underline flex items-center gap-2">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                        </svg>
                                                        <?php echo htmlspecialchars($page['page_name']); ?>
                                                    </a>
                                                </td>
                                                <td class="p-4 align-middle text-sm font-medium"><?php echo htmlspecialchars($page['page_title']); ?></td>
                                                <td class="p-4 align-middle">
                                                    <?php if (!empty($page['group_name'])): ?>
                                                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-semibold text-blue-800">
                                                            <?php echo htmlspecialchars($page['group_name']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-xs text-muted-foreground">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <code class="text-xs bg-muted px-2 py-1 rounded text-muted-foreground font-mono"><?php echo htmlspecialchars($page['table_name']); ?></code>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <div class="flex flex-wrap gap-1.5">
                                                        <?php if ($page['enable_list']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-800">List</span>
                                                        <?php endif; ?>
                                                        <?php if ($page['enable_create']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800">Create</span>
                                                        <?php endif; ?>
                                                        <?php if ($page['enable_update']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-semibold text-yellow-800">Update</span>
                                                        <?php endif; ?>
                                                        <?php if ($page['enable_delete']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800">Delete</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="p-4 align-middle text-xs text-muted-foreground">
                                                    <?php echo date('M d, Y', strtotime($page['created_at'])); ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <div class="flex gap-2">
                                                        <a
                                                            href="pages-builder.php?edit=<?php echo $page['id']; ?>"
                                                            class="inline-flex items-center justify-center rounded-md text-xs font-semibold bg-blue-100 text-blue-800 hover:bg-blue-200 px-3 py-1.5 transition-colors"
                                                            title="Edit page"
                                                        >
                                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                            </svg>
                                                            Edit
                                                        </a>
                                                        <a
                                                            href="dynamic-page.php?page=<?php echo urlencode($page['page_name']); ?>"
                                                            class="inline-flex items-center justify-center rounded-md text-xs font-semibold bg-primary text-primary-foreground hover:bg-primary/90 px-3 py-1.5 transition-colors"
                                                            title="View page"
                                                            target="_blank"
                                                        >
                                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                            </svg>
                                                            View
                                                        </a>
                                                        <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this page? This action cannot be undone.');">
                                                            <input type="hidden" name="action" value="delete_page">
                                                            <input type="hidden" name="page_id" value="<?php echo $page['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                class="inline-flex items-center justify-center rounded-md text-xs font-semibold bg-red-100 text-red-800 hover:bg-red-200 px-3 py-1.5 transition-colors"
                                                                title="Delete page"
                                                            >
                                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                                </svg>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
// Initialize CodeMirror editors for rule fields
document.addEventListener('DOMContentLoaded', function() {
    // CodeMirror configuration
    const editorConfig = {
        lineNumbers: true,
        mode: 'application/x-httpd-php',
        theme: 'monokai',
        indentUnit: 4,
        indentWithTabs: false,
        autoCloseBrackets: true,
        matchBrackets: true,
        styleActiveLine: true,
        lineWrapping: true,
        extraKeys: {
            "Ctrl-Space": "autocomplete",
            "Ctrl-Enter": function(cm) {
                // Allow form submission with Ctrl+Enter
                return true;
            }
        },
        hintOptions: {
            completeSingle: false
        }
    };
    
    // Initialize editors (only if textareas exist)
    const createRuleTextarea = document.getElementById('create_rule');
    const updateRuleTextarea = document.getElementById('update_rule');
    const deleteRuleTextarea = document.getElementById('delete_rule');
    
    const createRuleEditor = createRuleTextarea ? CodeMirror.fromTextArea(createRuleTextarea, editorConfig) : null;
    const updateRuleEditor = updateRuleTextarea ? CodeMirror.fromTextArea(updateRuleTextarea, editorConfig) : null;
    const deleteRuleEditor = deleteRuleTextarea ? CodeMirror.fromTextArea(deleteRuleTextarea, editorConfig) : null;
    
    // Sync editor content with textarea before form submission
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function() {
            if (createRuleEditor) createRuleEditor.save();
            if (updateRuleEditor) updateRuleEditor.save();
            if (deleteRuleEditor) deleteRuleEditor.save();
        });
    }
    
    // Store editors for potential future use
    window.ruleEditors = {
        create: createRuleEditor,
        update: updateRuleEditor,
        delete: deleteRuleEditor
    };
});
</script>

<?php include '../includes/footer.php'; ?>

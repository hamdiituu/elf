<?php
require_once __DIR__ . '/../config/config.php';
requireDeveloper();

$page_title = 'Dashboard Widgets';
$db = getDB();

// Ensure dashboard_widgets table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS dashboard_widgets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        widget_type TEXT NOT NULL,
        widget_config TEXT NOT NULL,
        position INTEGER DEFAULT 0,
        width TEXT DEFAULT 'md:col-span-1',
        enabled INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
} catch (PDOException $e) {
    // Table might already exist
}

$error_message = null;
$success_message = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_widget':
                $title = trim($_POST['title'] ?? '');
                $widget_type = trim($_POST['widget_type'] ?? '');
                $widget_config = trim($_POST['widget_config'] ?? '');
                $width = trim($_POST['width'] ?? 'md:col-span-1');
                
                if (empty($title) || empty($widget_type) || empty($widget_config)) {
                    $error_message = "Başlık, widget tipi ve konfigürasyon gereklidir!";
                } else {
                    try {
                        // Get max position
                        $stmt = $db->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM dashboard_widgets WHERE user_id = ?");
                        $stmt->execute([$_SESSION['user_id']]);
                        $position = $stmt->fetchColumn();
                        
                        $stmt = $db->prepare("INSERT INTO dashboard_widgets (user_id, title, widget_type, widget_config, position, width) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$_SESSION['user_id'], $title, $widget_type, $widget_config, $position, $width]);
                        $success_message = "Widget başarıyla oluşturuldu!";
                    } catch (PDOException $e) {
                        $error_message = "Widget oluşturulurken hata: " . $e->getMessage();
                    }
                }
                break;
                
            case 'update_widget':
                $widget_id = intval($_POST['widget_id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $widget_type = trim($_POST['widget_type'] ?? '');
                $widget_config = trim($_POST['widget_config'] ?? '');
                $width = trim($_POST['width'] ?? 'md:col-span-1');
                
                if ($widget_id > 0 && !empty($title) && !empty($widget_type) && !empty($widget_config)) {
                    try {
                        $stmt = $db->prepare("UPDATE dashboard_widgets SET title = ?, widget_type = ?, widget_config = ?, width = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
                        $stmt->execute([$title, $widget_type, $widget_config, $width, $widget_id, $_SESSION['user_id']]);
                        $success_message = "Widget başarıyla güncellendi!";
                    } catch (PDOException $e) {
                        $error_message = "Widget güncellenirken hata: " . $e->getMessage();
                    }
                }
                break;
                
            case 'delete_widget':
                $widget_id = intval($_POST['widget_id'] ?? 0);
                if ($widget_id > 0) {
                    try {
                        $stmt = $db->prepare("DELETE FROM dashboard_widgets WHERE id = ? AND user_id = ?");
                        $stmt->execute([$widget_id, $_SESSION['user_id']]);
                        $success_message = "Widget başarıyla silindi!";
                    } catch (PDOException $e) {
                        $error_message = "Widget silinirken hata: " . $e->getMessage();
                    }
                }
                break;
                
            case 'toggle_widget':
                $widget_id = intval($_POST['widget_id'] ?? 0);
                if ($widget_id > 0) {
                    try {
                        $stmt = $db->prepare("UPDATE dashboard_widgets SET enabled = NOT enabled, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
                        $stmt->execute([$widget_id, $_SESSION['user_id']]);
                        $success_message = "Widget durumu güncellendi!";
                    } catch (PDOException $e) {
                        $error_message = "Widget durumu güncellenirken hata: " . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Get all widgets for current user
$widgets = $db->prepare("SELECT * FROM dashboard_widgets WHERE user_id = ? ORDER BY position ASC, created_at ASC");
$widgets->execute([$_SESSION['user_id']]);
$widgets = $widgets->fetchAll(PDO::FETCH_ASSOC);

// Get widget for editing
$edit_widget = null;
$edit_id = $_GET['edit'] ?? null;
if ($edit_id) {
    $stmt = $db->prepare("SELECT * FROM dashboard_widgets WHERE id = ? AND user_id = ?");
    $stmt->execute([$edit_id, $_SESSION['user_id']]);
    $edit_widget = $stmt->fetch(PDO::FETCH_ASSOC);
}

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto bg-muted/30">
        <div class="py-6">
            <div class="mx-auto max-w-[1600px] px-4 sm:px-6 md:px-8">
                <!-- Header -->
                <div class="mb-8">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h1 class="text-3xl font-bold text-foreground">Dashboard Widgets</h1>
                            <p class="mt-2 text-sm text-muted-foreground">Create custom widgets for your dashboard</p>
                        </div>
                    </div>
                    
                    <!-- Stats Dashboard -->
                    <div class="grid gap-4 md:grid-cols-4 mb-6">
                        <?php 
                        $total_widgets = count($widgets);
                        $active_widgets = count(array_filter($widgets, fn($w) => $w['enabled']));
                        $sql_count = count(array_filter($widgets, fn($w) => $w['widget_type'] === 'sql_count'));
                        $sql_query = count(array_filter($widgets, fn($w) => $w['widget_type'] === 'sql_query'));
                        $sql_single = count(array_filter($widgets, fn($w) => $w['widget_type'] === 'sql_single'));
                        ?>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Total Widgets</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $total_widgets; ?></p>
                                </div>
                                <div class="rounded-full bg-blue-100 p-3">
                                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">Active</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $active_widgets; ?></p>
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
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">SQL Count</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $sql_count; ?></p>
                                </div>
                                <div class="rounded-full bg-purple-100 p-3">
                                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-xs font-medium text-muted-foreground uppercase tracking-wider">SQL Query</p>
                                    <p class="text-2xl font-bold text-foreground mt-1"><?php echo $sql_query + $sql_single; ?></p>
                                </div>
                                <div class="rounded-full bg-yellow-100 p-3">
                                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
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
                
                <!-- Create/Edit Widget Form -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 border-b border-border bg-muted/30">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold leading-none tracking-tight">
                                <?php echo $edit_widget ? 'Edit Widget' : 'Create New Widget'; ?>
                            </h3>
                            <?php if ($edit_widget): ?>
                                <a
                                    href="dashboard-widgets.php"
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
                            <input type="hidden" name="action" value="<?php echo $edit_widget ? 'update_widget' : 'create_widget'; ?>">
                            <?php if ($edit_widget): ?>
                                <input type="hidden" name="widget_id" value="<?php echo $edit_widget['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="space-y-6">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <label for="title" class="block text-sm font-medium text-foreground mb-2">
                                            Widget Title <span class="text-red-500">*</span>
                                        </label>
                                        <input
                                            type="text"
                                            id="title"
                                            name="title"
                                            required
                                            value="<?php echo htmlspecialchars($edit_widget['title'] ?? ''); ?>"
                                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                            placeholder="Widget Title"
                                        >
                                    </div>
                                    
                                    <div>
                                        <label for="width" class="block text-sm font-medium text-foreground mb-2">
                                            Width
                                        </label>
                                        <select
                                            id="width"
                                            name="width"
                                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        >
                                            <option value="md:col-span-1" <?php echo ($edit_widget['width'] ?? 'md:col-span-1') === 'md:col-span-1' ? 'selected' : ''; ?>>1 Column</option>
                                            <option value="md:col-span-2" <?php echo ($edit_widget['width'] ?? '') === 'md:col-span-2' ? 'selected' : ''; ?>>2 Columns</option>
                                            <option value="md:col-span-3" <?php echo ($edit_widget['width'] ?? '') === 'md:col-span-3' ? 'selected' : ''; ?>>3 Columns</option>
                                            <option value="md:col-span-4" <?php echo ($edit_widget['width'] ?? '') === 'md:col-span-4' ? 'selected' : ''; ?>>4 Columns</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div>
                                    <label for="widget_type" class="block text-sm font-medium text-foreground mb-2">
                                        Widget Type <span class="text-red-500">*</span>
                                    </label>
                                    <select
                                        id="widget_type"
                                        name="widget_type"
                                        required
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        onchange="updateWidgetConfigPlaceholder()"
                                    >
                                        <option value="">Select Type</option>
                                        <option value="sql_count" <?php echo ($edit_widget['widget_type'] ?? '') === 'sql_count' ? 'selected' : ''; ?>>SQL COUNT Query</option>
                                        <option value="sql_query" <?php echo ($edit_widget['widget_type'] ?? '') === 'sql_query' ? 'selected' : ''; ?>>SQL Query (List)</option>
                                        <option value="sql_single" <?php echo ($edit_widget['widget_type'] ?? '') === 'sql_single' ? 'selected' : ''; ?>>SQL Query (Single Value)</option>
                                    </select>
                                    <div class="mt-2 rounded-lg bg-blue-50 border border-blue-200 p-3">
                                        <p class="text-xs text-blue-800 font-medium mb-2">Widget Types:</p>
                                        <ul class="text-xs text-blue-700 space-y-1">
                                            <li><code class="bg-blue-100 px-1.5 py-0.5 rounded">sql_count</code> - Shows COUNT result (e.g., SELECT COUNT(*) FROM table)</li>
                                            <li><code class="bg-blue-100 px-1.5 py-0.5 rounded">sql_query</code> - Shows multiple rows (e.g., SELECT * FROM table LIMIT 5)</li>
                                            <li><code class="bg-blue-100 px-1.5 py-0.5 rounded">sql_single</code> - Shows single value (e.g., SELECT column FROM table LIMIT 1)</li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div>
                                    <label for="widget_config" class="block text-sm font-medium text-foreground mb-2">
                                        SQL Query <span class="text-red-500">*</span>
                                    </label>
                                    <textarea
                                        id="widget_config"
                                        name="widget_config"
                                        required
                                        rows="8"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm font-mono text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="SELECT COUNT(*) FROM sayimlar WHERE aktif = 1"
                                    ><?php echo htmlspecialchars($edit_widget['widget_config'] ?? ''); ?></textarea>
                                    <p class="text-xs text-muted-foreground mt-2">
                                        Enter your SQL query. The result will be displayed in the dashboard widget.
                                    </p>
                                </div>
                                
                                <div class="flex items-center gap-3 pt-4 border-t border-border">
                                    <button
                                        type="submit"
                                        class="inline-flex items-center justify-center rounded-md text-sm font-semibold bg-primary text-primary-foreground hover:bg-primary/90 px-6 py-2.5 transition-colors shadow-sm"
                                    >
                                        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                        </svg>
                                        <?php echo $edit_widget ? 'Update Widget' : 'Create Widget'; ?>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Existing Widgets -->
                <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 border-b border-border bg-muted/30">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold leading-none tracking-tight">My Widgets</h3>
                            <span class="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-0.5 text-xs font-medium text-primary">
                                <?php echo count($widgets); ?> widgets
                            </span>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (empty($widgets)): ?>
                            <div class="text-center py-16 text-muted-foreground">
                                <svg class="mx-auto h-16 w-16 mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                </svg>
                                <p class="text-base font-medium">No widgets created yet</p>
                                <p class="text-xs mt-1">Create your first dashboard widget above</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border bg-muted/50">
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Title</th>
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Type</th>
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Width</th>
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Status</th>
                                            <th class="h-12 px-4 text-left align-middle font-semibold text-muted-foreground text-sm">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($widgets as $widget): ?>
                                            <tr class="border-b border-border transition-colors hover:bg-muted/30">
                                                <td class="p-4 align-middle">
                                                    <div class="flex items-center gap-2">
                                                        <div class="rounded-full bg-primary/10 p-2">
                                                            <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                                            </svg>
                                                        </div>
                                                        <div>
                                                            <span class="text-sm font-semibold text-foreground"><?php echo htmlspecialchars($widget['title']); ?></span>
                                                            <p class="text-xs text-muted-foreground mt-0.5 truncate max-w-xs">
                                                                <?php echo htmlspecialchars(substr($widget['widget_config'], 0, 50)); ?><?php echo strlen($widget['widget_config']) > 50 ? '...' : ''; ?>
                                                            </p>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <?php
                                                    $type_labels = [
                                                        'sql_count' => ['label' => 'SQL COUNT', 'color' => 'bg-blue-100 text-blue-800'],
                                                        'sql_query' => ['label' => 'SQL List', 'color' => 'bg-purple-100 text-purple-800'],
                                                        'sql_single' => ['label' => 'SQL Single', 'color' => 'bg-yellow-100 text-yellow-800']
                                                    ];
                                                    $type = $type_labels[$widget['widget_type']] ?? ['label' => $widget['widget_type'], 'color' => 'bg-gray-100 text-gray-800'];
                                                    ?>
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold <?php echo $type['color']; ?>">
                                                        <?php echo htmlspecialchars($type['label']); ?>
                                                    </span>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <?php
                                                    $width_labels = [
                                                        'md:col-span-1' => ['label' => '1 Column', 'color' => 'text-gray-600'],
                                                        'md:col-span-2' => ['label' => '2 Columns', 'color' => 'text-blue-600'],
                                                        'md:col-span-3' => ['label' => '3 Columns', 'color' => 'text-purple-600'],
                                                        'md:col-span-4' => ['label' => '4 Columns', 'color' => 'text-yellow-600']
                                                    ];
                                                    $width = $width_labels[$widget['width']] ?? ['label' => $widget['width'], 'color' => 'text-gray-600'];
                                                    ?>
                                                    <span class="text-xs font-medium <?php echo $width['color']; ?>">
                                                        <?php echo htmlspecialchars($width['label']); ?>
                                                    </span>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <?php if ($widget['enabled']): ?>
                                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800">
                                                            <span class="relative flex h-1.5 w-1.5 mr-1.5">
                                                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                                                <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-green-500"></span>
                                                            </span>
                                                            Active
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-800">
                                                            Inactive
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <div class="flex gap-2">
                                                        <a
                                                            href="?edit=<?php echo $widget['id']; ?>"
                                                            class="inline-flex items-center justify-center rounded-md text-xs font-semibold bg-blue-100 text-blue-800 hover:bg-blue-200 px-3 py-1.5 transition-colors"
                                                            title="Edit widget"
                                                        >
                                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                            </svg>
                                                            Edit
                                                        </a>
                                                        <form method="POST" action="" class="inline">
                                                            <input type="hidden" name="action" value="toggle_widget">
                                                            <input type="hidden" name="widget_id" value="<?php echo $widget['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                class="inline-flex items-center justify-center rounded-md text-xs font-semibold bg-gray-100 text-gray-800 hover:bg-gray-200 px-3 py-1.5 transition-colors"
                                                                title="<?php echo $widget['enabled'] ? 'Disable widget' : 'Enable widget'; ?>"
                                                            >
                                                                <?php if ($widget['enabled']): ?>
                                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                                                    </svg>
                                                                    Disable
                                                                <?php else: ?>
                                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                                    </svg>
                                                                    Enable
                                                                <?php endif; ?>
                                                            </button>
                                                        </form>
                                                        <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this widget? This action cannot be undone.');">
                                                            <input type="hidden" name="action" value="delete_widget">
                                                            <input type="hidden" name="widget_id" value="<?php echo $widget['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                class="inline-flex items-center justify-center rounded-md text-xs font-semibold bg-red-100 text-red-800 hover:bg-red-200 px-3 py-1.5 transition-colors"
                                                                title="Delete widget"
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
function updateWidgetConfigPlaceholder() {
    const type = document.getElementById('widget_type').value;
    const config = document.getElementById('widget_config');
    
    switch(type) {
        case 'sql_count':
            config.placeholder = 'SELECT COUNT(*) FROM sayimlar WHERE aktif = 1';
            break;
        case 'sql_query':
            config.placeholder = 'SELECT * FROM sayimlar ORDER BY created_at DESC LIMIT 5';
            break;
        case 'sql_single':
            config.placeholder = 'SELECT sayim_no FROM sayimlar WHERE aktif = 1 LIMIT 1';
            break;
        default:
            config.placeholder = 'Enter your SQL query here...';
    }
}
</script>
<?php include '../includes/footer.php'; ?>

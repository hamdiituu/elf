<?php
require_once '../config/config.php';
requireLogin();

$page_title = 'Dashboard';
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

// Get user's enabled widgets
$widgets_stmt = $db->prepare("SELECT * FROM dashboard_widgets WHERE user_id = ? AND enabled = 1 ORDER BY position ASC, created_at ASC");
$widgets_stmt->execute([$_SESSION['user_id']]);
$user_widgets = $widgets_stmt->fetchAll(PDO::FETCH_ASSOC);

// Process widgets and execute queries
$processed_widgets = [];
foreach ($user_widgets as $widget) {
    try {
        $config = $widget['widget_config'];
        $type = $widget['widget_type'];
        $result = null;
        $error = null;
        
        switch ($type) {
            case 'sql_count':
                $stmt = $db->prepare($config);
                $stmt->execute();
                $result = $stmt->fetchColumn();
                break;
                
            case 'sql_query':
                $stmt = $db->prepare($config);
                $stmt->execute();
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            case 'sql_single':
                $stmt = $db->prepare($config);
                $stmt->execute();
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    // Get first column value
                    $result = reset($row);
                }
                break;
        }
        
        $processed_widgets[] = [
            'id' => $widget['id'],
            'title' => $widget['title'],
            'type' => $type,
            'width' => $widget['width'],
            'result' => $result,
            'error' => $error
        ];
    } catch (PDOException $e) {
        $processed_widgets[] = [
            'id' => $widget['id'],
            'title' => $widget['title'],
            'type' => $type,
            'width' => $widget['width'],
            'result' => null,
            'error' => $e->getMessage()
        ];
    }
}

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <div class="flex items-center justify-between mb-8">
                    <h1 class="text-3xl font-bold text-foreground">Dashboard</h1>
                    <a
                        href="dashboard-widgets.php"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                    >
                        Manage Widgets
                    </a>
                </div>
                
                <?php if (empty($processed_widgets)): ?>
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm p-8">
                        <div class="text-center">
                            <svg class="mx-auto h-12 w-12 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                            <h3 class="mt-4 text-lg font-semibold text-foreground">No widgets found</h3>
                            <p class="mt-2 text-sm text-muted-foreground">
                                Click the "Manage Widgets" button to add widgets to your dashboard.
                            </p>
                            <div class="mt-6">
                                <a
                                    href="dashboard-widgets.php"
                                    class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                                >
                                    Create First Widget
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- Dynamic Widgets Grid -->
                    <div class="grid gap-4 md:grid-cols-4">
                        <?php foreach ($processed_widgets as $widget): ?>
                            <div class="<?php echo htmlspecialchars($widget['width']); ?>">
                                <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                                    <div class="p-6 pb-0">
                                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">
                                            <?php echo htmlspecialchars($widget['title']); ?>
                                        </h3>
                                    </div>
                                    <div class="p-6 pt-0">
                                        <?php if ($widget['error']): ?>
                                            <div class="rounded-md bg-red-50 p-3 border border-red-200">
                                                <p class="text-sm text-red-800">
                                                    <strong>Error:</strong> <?php echo htmlspecialchars($widget['error']); ?>
                                                </p>
                                            </div>
                                        <?php else: ?>
                                            <?php if ($widget['type'] === 'sql_count'): ?>
                                                <p class="text-3xl font-bold text-foreground"><?php echo number_format($widget['result']); ?></p>
                                            <?php elseif ($widget['type'] === 'sql_single'): ?>
                                                <p class="text-2xl font-semibold text-foreground"><?php echo htmlspecialchars($widget['result'] ?? 'N/A'); ?></p>
                                            <?php elseif ($widget['type'] === 'sql_query' && is_array($widget['result'])): ?>
                                                <?php if (empty($widget['result'])): ?>
                                                    <div class="text-center py-4 text-muted-foreground">
                                                        No results found.
                                                    </div>
                                                <?php else: ?>
                                                    <div class="space-y-2 max-h-96 overflow-y-auto">
                                                        <?php foreach ($widget['result'] as $row): ?>
                                                            <div class="p-3 rounded-md border border-border hover:bg-muted/50 transition-colors">
                                                                <?php if (count($row) == 1): ?>
                                                                    <p class="text-sm font-medium text-foreground"><?php echo htmlspecialchars(reset($row)); ?></p>
                                                                <?php else: ?>
                                                                    <?php foreach ($row as $key => $value): ?>
                                                                        <div class="flex justify-between items-start mb-1 last:mb-0">
                                                                            <span class="text-xs font-medium text-muted-foreground mr-2"><?php echo htmlspecialchars($key); ?>:</span>
                                                                            <span class="text-sm text-foreground text-right"><?php echo htmlspecialchars($value ?? 'NULL'); ?></span>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php include '../includes/footer.php'; ?>

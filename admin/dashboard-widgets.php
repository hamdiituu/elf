<?php
require_once __DIR__ . '/../config/config.php';
requireDeveloper();

$page_title = 'Dashboard Widgets - Vira Stok Sistemi';
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

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Dashboard Widgets</h1>
                
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
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">
                            <?php echo $edit_widget ? 'Widget Düzenle' : 'Yeni Widget Ekle'; ?>
                        </h3>
                    </div>
                    <div class="p-6 pt-0">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="<?php echo $edit_widget ? 'update_widget' : 'create_widget'; ?>">
                            <?php if ($edit_widget): ?>
                                <input type="hidden" name="widget_id" value="<?php echo $edit_widget['id']; ?>">
                            <?php endif; ?>
                            
                            <div class="space-y-4">
                                <div>
                                    <label for="title" class="block text-sm font-medium text-foreground mb-1.5">
                                        Başlık <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        id="title"
                                        name="title"
                                        required
                                        value="<?php echo htmlspecialchars($edit_widget['title'] ?? ''); ?>"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="Widget Başlığı"
                                    >
                                </div>
                                
                                <div>
                                    <label for="widget_type" class="block text-sm font-medium text-foreground mb-1.5">
                                        Widget Tipi <span class="text-red-500">*</span>
                                    </label>
                                    <select
                                        id="widget_type"
                                        name="widget_type"
                                        required
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        onchange="updateWidgetConfigPlaceholder()"
                                    >
                                        <option value="">Seçiniz</option>
                                        <option value="sql_count" <?php echo ($edit_widget['widget_type'] ?? '') === 'sql_count' ? 'selected' : ''; ?>>SQL COUNT Sorgusu</option>
                                        <option value="sql_query" <?php echo ($edit_widget['widget_type'] ?? '') === 'sql_query' ? 'selected' : ''; ?>>SQL Sorgusu (Liste)</option>
                                        <option value="sql_single" <?php echo ($edit_widget['widget_type'] ?? '') === 'sql_single' ? 'selected' : ''; ?>>SQL Sorgusu (Tek Değer)</option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="widget_config" class="block text-sm font-medium text-foreground mb-1.5">
                                        SQL Sorgusu <span class="text-red-500">*</span>
                                    </label>
                                    <textarea
                                        id="widget_config"
                                        name="widget_config"
                                        required
                                        rows="6"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground font-mono placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                        placeholder="SELECT COUNT(*) FROM sayimlar WHERE aktif = 1"
                                    ><?php echo htmlspecialchars($edit_widget['widget_config'] ?? ''); ?></textarea>
                                    <p class="text-xs text-muted-foreground mt-1">
                                        <strong>sql_count:</strong> COUNT sonucunu gösterir (örn: SELECT COUNT(*) FROM sayimlar)<br>
                                        <strong>sql_query:</strong> Birden fazla satır gösterir (örn: SELECT * FROM sayimlar LIMIT 5)<br>
                                        <strong>sql_single:</strong> Tek bir değer gösterir (örn: SELECT sayim_no FROM sayimlar WHERE aktif = 1 LIMIT 1)
                                    </p>
                                </div>
                                
                                <div>
                                    <label for="width" class="block text-sm font-medium text-foreground mb-1.5">
                                        Genişlik
                                    </label>
                                    <select
                                        id="width"
                                        name="width"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                        <option value="md:col-span-1" <?php echo ($edit_widget['width'] ?? 'md:col-span-1') === 'md:col-span-1' ? 'selected' : ''; ?>>1 Sütun</option>
                                        <option value="md:col-span-2" <?php echo ($edit_widget['width'] ?? '') === 'md:col-span-2' ? 'selected' : ''; ?>>2 Sütun</option>
                                        <option value="md:col-span-3" <?php echo ($edit_widget['width'] ?? '') === 'md:col-span-3' ? 'selected' : ''; ?>>3 Sütun</option>
                                        <option value="md:col-span-4" <?php echo ($edit_widget['width'] ?? '') === 'md:col-span-4' ? 'selected' : ''; ?>>4 Sütun</option>
                                    </select>
                                </div>
                                
                                <div class="flex gap-2">
                                    <button
                                        type="submit"
                                        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                                    >
                                        <?php echo $edit_widget ? 'Güncelle' : 'Oluştur'; ?>
                                    </button>
                                    <?php if ($edit_widget): ?>
                                        <a
                                            href="dashboard-widgets.php"
                                            class="rounded-md bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-500 transition-all"
                                        >
                                            İptal
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Existing Widgets -->
                <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Widget'larım</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <?php if (empty($widgets)): ?>
                            <div class="text-center py-8 text-muted-foreground">
                                Henüz widget eklenmemiş.
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border">
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Başlık</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Tip</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Genişlik</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Durum</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">İşlemler</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($widgets as $widget): ?>
                                            <tr class="border-b border-border transition-colors hover:bg-muted/50">
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($widget['title']); ?></td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground">
                                                    <?php
                                                    $type_labels = [
                                                        'sql_count' => 'SQL COUNT',
                                                        'sql_query' => 'SQL Liste',
                                                        'sql_single' => 'SQL Tek Değer'
                                                    ];
                                                    echo htmlspecialchars($type_labels[$widget['widget_type']] ?? $widget['widget_type']);
                                                    ?>
                                                </td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground">
                                                    <?php
                                                    $width_labels = [
                                                        'md:col-span-1' => '1 Sütun',
                                                        'md:col-span-2' => '2 Sütun',
                                                        'md:col-span-3' => '3 Sütun',
                                                        'md:col-span-4' => '4 Sütun'
                                                    ];
                                                    echo htmlspecialchars($width_labels[$widget['width']] ?? $widget['width']);
                                                    ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <?php if ($widget['enabled']): ?>
                                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">Aktif</span>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-800">Pasif</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <div class="flex gap-2">
                                                        <a
                                                            href="?edit=<?php echo $widget['id']; ?>"
                                                            class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-blue-100 text-blue-800 hover:bg-blue-200 h-9 px-3"
                                                        >
                                                            Düzenle
                                                        </a>
                                                        <form method="POST" action="" class="inline" onsubmit="return confirm('Bu widget\'ı silmek istediğinizden emin misiniz?');">
                                                            <input type="hidden" name="action" value="delete_widget">
                                                            <input type="hidden" name="widget_id" value="<?php echo $widget['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-red-100 text-red-800 hover:bg-red-200 h-9 px-3"
                                                            >
                                                                Sil
                                                            </button>
                                                        </form>
                                                        <form method="POST" action="" class="inline">
                                                            <input type="hidden" name="action" value="toggle_widget">
                                                            <input type="hidden" name="widget_id" value="<?php echo $widget['id']; ?>">
                                                            <button
                                                                type="submit"
                                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-gray-100 text-gray-800 hover:bg-gray-200 h-9 px-3"
                                                            >
                                                                <?php echo $widget['enabled'] ? 'Pasif Yap' : 'Aktif Yap'; ?>
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
            config.placeholder = 'SQL sorgunuzu buraya yazın...';
    }
}
</script>
<?php include '../includes/footer.php'; ?>

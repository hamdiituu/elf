<?php
require_once '../config/config.php';
requireLogin();

$page_title = 'Database Explorer - Stok Sayım Sistemi';

$db = getDB();
$error_message = null;
$success_message = null;
$table_name = $_GET['table'] ?? null;
$query_result = null;
$selected_table = null;
$table_columns = [];
$table_data = [];
$custom_query = $_POST['custom_query'] ?? null;
$saved_query_name = $_POST['saved_query_name'] ?? null;
$load_query_id = $_GET['load_query'] ?? null;
$delete_query_id = $_GET['delete_query'] ?? null;

// Ensure saved_queries table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS saved_queries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        query TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (PDOException $e) {
    // Table might already exist, ignore error
}

// Handle save query
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_query' && $custom_query && $saved_query_name) {
    try {
        $stmt = $db->prepare("INSERT INTO saved_queries (name, query) VALUES (?, ?)");
        $stmt->execute([$saved_query_name, $custom_query]);
        $success_message = "Query başarıyla kaydedildi!";
    } catch (PDOException $e) {
        $error_message = "Query kaydedilirken hata: " . $e->getMessage();
    }
}

// Handle delete query
if ($delete_query_id) {
    try {
        $stmt = $db->prepare("DELETE FROM saved_queries WHERE id = ?");
        $stmt->execute([$delete_query_id]);
        $success_message = "Query başarıyla silindi!";
    } catch (PDOException $e) {
        $error_message = "Query silinirken hata: " . $e->getMessage();
    }
}

// Handle load query
if ($load_query_id) {
    try {
        $stmt = $db->prepare("SELECT query FROM saved_queries WHERE id = ?");
        $stmt->execute([$load_query_id]);
        $saved_query = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($saved_query) {
            $custom_query = $saved_query['query'];
        }
    } catch (PDOException $e) {
        $error_message = "Query yüklenirken hata: " . $e->getMessage();
    }
}

// Get saved queries
$saved_queries = [];
try {
    $saved_queries = $db->query("SELECT * FROM saved_queries ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
}

// Get all tables
try {
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $tables = [];
    $error_message = "Tablolar yüklenirken hata: " . $e->getMessage();
}

// Handle custom query
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $custom_query) {
    try {
        // Basic security: Remove dangerous keywords for read-only queries
        // For full functionality, we'll allow all queries but warn the user
        $trimmed_query = trim($custom_query);
        $query_upper = strtoupper($trimmed_query);
        
        // Execute query
        $stmt = $db->prepare($custom_query);
        $stmt->execute();
        
        // Check if it's a SELECT query
        if (strpos($query_upper, 'SELECT') === 0) {
            $query_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get column names from first row if available
            if (!empty($query_result)) {
                $table_columns = array_keys($query_result[0]);
            } else {
                // Try to get column info from statement
                $stmt2 = $db->prepare($custom_query);
                $stmt2->execute();
                $table_columns = [];
                for ($i = 0; $i < $stmt2->columnCount(); $i++) {
                    $col = $stmt2->getColumnMeta($i);
                    $table_columns[] = $col['name'] ?? "Column " . ($i + 1);
                }
            }
        } else {
            // For non-SELECT queries, show affected rows
            $affected_rows = $stmt->rowCount();
            $success_message = "Sorgu başarıyla çalıştırıldı. Etkilenen satır sayısı: " . $affected_rows;
        }
    } catch (PDOException $e) {
        $error_message = "Sorgu hatası: " . $e->getMessage();
    }
}

// Get table structure and data
if ($table_name && in_array($table_name, $tables)) {
    $selected_table = $table_name;
    
    try {
        // Get table info (columns)
        $table_info = $db->query("PRAGMA table_info($table_name)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($table_info as $col) {
            $table_columns[] = $col['name'];
        }
        
        // Get table data
        $stmt = $db->prepare("SELECT * FROM $table_name LIMIT 1000");
        $stmt->execute();
        $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message = "Tablo verileri yüklenirken hata: " . $e->getMessage();
    }
}

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Database Explorer</h1>
                
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
                
                <div class="grid gap-6 lg:grid-cols-12">
                    <!-- Tables List -->
                    <div class="lg:col-span-3 transition-all duration-300" id="tables-panel">
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-4 pb-0 flex items-center justify-between">
                                <h3 class="text-lg font-semibold leading-none tracking-tight" id="tables-title">Tablolar</h3>
                                <button
                                    type="button"
                                    id="toggle-tables"
                                    class="p-1 rounded hover:bg-muted transition-colors"
                                    title="Küçült / Büyüt"
                                >
                                    <svg class="h-5 w-5 text-muted-foreground transition-transform" id="toggle-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V5l7 7-7 7z" />
                                    </svg>
                                </button>
                            </div>
                            <div class="p-4 pt-2" id="tables-content">
                                <div class="space-y-2 max-h-[600px] overflow-y-auto">
                                    <?php foreach ($tables as $table): ?>
                                        <a
                                            href="?table=<?php echo htmlspecialchars($table); ?>"
                                            class="block p-2 rounded-md border border-border hover:bg-accent transition-colors <?php echo $selected_table === $table ? 'bg-accent border-primary' : ''; ?>"
                                        >
                                            <div class="flex items-center justify-between">
                                                <span class="font-medium text-xs"><?php echo htmlspecialchars($table); ?></span>
                                                <svg class="h-3 w-3 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                                </svg>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Table Content / Query Result -->
                    <div class="lg:col-span-9 transition-all duration-300" id="query-panel">
                        <!-- Saved Queries Sidebar -->
                        <?php if (!empty($saved_queries)): ?>
                        <div class="mb-6 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-4 pb-0">
                                <h3 class="text-sm font-semibold leading-none tracking-tight mb-3">Kaydedilmiş Query'ler</h3>
                            </div>
                            <div class="p-4 pt-2">
                                <div class="space-y-2 max-h-[200px] overflow-y-auto">
                                    <?php foreach ($saved_queries as $sq): ?>
                                        <div class="flex items-center justify-between bg-muted/50 rounded p-2 hover:bg-muted transition-colors">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-sm font-medium text-foreground truncate"><?php echo htmlspecialchars($sq['name']); ?></span>
                                                    <span class="text-xs text-muted-foreground"><?php echo date('d.m.Y H:i', strtotime($sq['created_at'])); ?></span>
                                                </div>
                                                <p class="text-xs text-muted-foreground truncate mt-1"><?php echo htmlspecialchars(substr($sq['query'], 0, 60)); ?>...</p>
                                            </div>
                                            <div class="flex items-center gap-1 ml-2 flex-shrink-0">
                                                <a
                                                    href="?load_query=<?php echo $sq['id']; ?><?php echo $selected_table ? '&table=' . htmlspecialchars($selected_table) : ''; ?>"
                                                    class="p-1.5 rounded hover:bg-primary hover:text-primary-foreground transition-colors"
                                                    title="Yükle"
                                                >
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                                    </svg>
                                                </a>
                                                <a
                                                    href="?delete_query=<?php echo $sq['id']; ?><?php echo $selected_table ? '&table=' . htmlspecialchars($selected_table) : ''; ?>"
                                                    class="p-1.5 rounded hover:bg-red-500 hover:text-white transition-colors"
                                                    title="Sil"
                                                    onclick="return confirm('Bu query'yi silmek istediğinizden emin misiniz?')"
                                                >
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Custom Query Section -->
                        <div class="mb-6 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-6 pb-0">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-semibold leading-none tracking-tight">SQL Sorgusu</h3>
                                    <?php if (!empty($saved_queries)): ?>
                                        <select
                                            id="load-saved-query"
                                            class="text-sm px-3 py-1.5 border border-input bg-background text-foreground rounded-md hover:bg-accent transition-colors"
                                            onchange="if(this.value) { window.location.href='?load_query=' + this.value + '<?php echo $selected_table ? '&table=' . htmlspecialchars($selected_table) : ''; ?>'; }"
                                        >
                                            <option value="">Kaydedilmiş Query Seç...</option>
                                            <?php foreach ($saved_queries as $sq): ?>
                                                <option value="<?php echo $sq['id']; ?>"><?php echo htmlspecialchars($sq['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="p-6 pt-0">
                                <form method="POST" action="" id="query-form">
                                    <input type="hidden" name="action" value="run_query">
                                    <div class="space-y-4">
                                        <div>
                                            <textarea
                                                name="custom_query"
                                                id="custom_query"
                                                rows="6"
                                                class="w-full px-3 py-2 border border-input bg-background text-foreground rounded-md font-mono text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                                placeholder="SELECT * FROM users LIMIT 10;"><?php echo htmlspecialchars($custom_query ?? ''); ?></textarea>
                                        </div>
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <button
                                                type="submit"
                                                name="action"
                                                value="run_query"
                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 px-4 py-2 transition-colors"
                                            >
                                                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                                Sorguyu Çalıştır
                                            </button>
                                            <?php if ($custom_query): ?>
                                                <button
                                                    type="button"
                                                    onclick="showSaveDialog()"
                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-secondary text-secondary-foreground hover:bg-secondary/80 px-4 py-2 transition-colors"
                                                >
                                                    <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                                                    </svg>
                                                    Kaydet
                                                </button>
                                                <a
                                                    href="database-explorer.php<?php echo $selected_table ? '?table=' . htmlspecialchars($selected_table) : ''; ?>"
                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-4 py-2 transition-colors"
                                                >
                                                    Temizle
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="rounded-md bg-yellow-50 border border-yellow-200 p-3">
                                            <p class="text-xs text-yellow-800">
                                                <strong>Uyarı:</strong> Tüm SQL sorguları çalıştırılabilir. UPDATE, DELETE, DROP gibi sorgular veritabanını değiştirebilir. Dikkatli olun!
                                            </p>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Table Content -->
                        <?php if ($selected_table || $query_result !== null): ?>
                            <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                                <div class="p-6 pb-0">
                                    <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">
                                        <?php 
                                        if ($query_result !== null) {
                                            echo 'Sorgu Sonuçları';
                                        } else {
                                            echo htmlspecialchars($selected_table) . ' Tablosu';
                                        }
                                        ?>
                                    </h3>
                                </div>
                                <div class="p-6 pt-0">
                                    <?php 
                                    $display_data = $query_result !== null ? $query_result : $table_data;
                                    $display_columns = !empty($display_data) ? array_keys($display_data[0]) : $table_columns;
                                    ?>
                                    
                                    <?php if (empty($display_data)): ?>
                                        <div class="text-center py-8 text-muted-foreground">
                                            Veri bulunamadı.
                                        </div>
                                    <?php else: ?>
                                        <div class="overflow-x-auto -mx-6 px-6">
                                            <div class="inline-block min-w-full align-middle">
                                                <table class="min-w-full border-collapse">
                                                    <thead>
                                                        <tr class="border-b border-border">
                                                            <?php foreach ($display_columns as $col): ?>
                                                                <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm bg-muted/50">
                                                                    <?php echo htmlspecialchars($col); ?>
                                                                </th>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($display_data as $row): ?>
                                                            <tr class="border-b border-border hover:bg-muted/50 transition-colors">
                                                                <?php foreach ($display_columns as $col): ?>
                                                                    <td class="px-4 py-3 text-sm">
                                                                        <?php 
                                                                        $value = $row[$col] ?? null;
                                                                        if ($value === null) {
                                                                            echo '<span class="text-muted-foreground italic">NULL</span>';
                                                                        } else {
                                                                            // Truncate long values
                                                                            $display_value = htmlspecialchars($value);
                                                                            if (strlen($display_value) > 100) {
                                                                                echo '<span title="' . htmlspecialchars($value) . '">' . htmlspecialchars(substr($value, 0, 100)) . '...</span>';
                                                                            } else {
                                                                                echo $display_value;
                                                                            }
                                                                        }
                                                                        ?>
                                                                    </td>
                                                                <?php endforeach; ?>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="mt-4 text-sm text-muted-foreground">
                                            Toplam <?php echo count($display_data); ?> satır gösteriliyor
                                            <?php if ($selected_table && count($table_data) >= 1000): ?>
                                                (İlk 1000 satır)
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                                <div class="p-12 text-center">
                                    <svg class="mx-auto h-12 w-12 text-muted-foreground mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                                    </svg>
                                    <p class="text-muted-foreground">Bir tablo seçin veya SQL sorgusu çalıştırın.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>

<!-- Save Query Dialog -->
<div id="save-dialog" class="fixed inset-0 hidden items-center justify-center z-50" onclick="if(event.target === this) hideSaveDialog()" style="background-color: rgba(0, 0, 0, 0.9) !important; backdrop-filter: blur(2px);">
    <div class="border border-border rounded-lg shadow-lg p-6 max-w-md w-full mx-4" onclick="event.stopPropagation()" style="background-color: hsl(var(--background)) !important; z-index: 51;">
        <h3 class="text-lg font-semibold mb-4">Query Kaydet</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_query">
            <input type="hidden" name="custom_query" id="save_query_text">
            <div class="space-y-4">
                <div>
                    <label for="saved_query_name" class="block text-sm font-medium mb-2">Query Adı:</label>
                    <input
                        type="text"
                        name="saved_query_name"
                        id="saved_query_name"
                        required
                        class="w-full px-3 py-2 border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-2 focus:ring-ring"
                        placeholder="Örn: Kullanıcı Listesi"
                    >
                </div>
                <div class="flex items-center gap-2 justify-end">
                    <button
                        type="button"
                        onclick="hideSaveDialog()"
                        class="px-4 py-2 text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 rounded-md transition-colors"
                    >
                        İptal
                    </button>
                    <button
                        type="submit"
                        class="px-4 py-2 text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 rounded-md transition-colors"
                    >
                        Kaydet
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Toggle tables panel
let tablesCollapsed = localStorage.getItem('tablesCollapsed') === 'true';
const panel = document.getElementById('tables-panel');
const content = document.getElementById('tables-content');
const queryPanel = document.getElementById('query-panel');
const toggleBtn = document.getElementById('toggle-tables');
const toggleIcon = document.getElementById('toggle-icon');
const tablesTitle = document.getElementById('tables-title');

function updateTablesPanel() {
    if (tablesCollapsed) {
        // Collapse: hide content, minimize panel
        panel.classList.remove('lg:col-span-3');
        panel.classList.add('lg:col-span-1');
        panel.style.width = 'auto';
        panel.style.minWidth = '60px';
        content.style.display = 'none';
        tablesTitle.style.display = 'none';
        queryPanel.classList.remove('lg:col-span-9');
        queryPanel.classList.add('lg:col-span-11');
        toggleIcon.style.transform = 'rotate(180deg)';
        // Make panel just show toggle button
        panel.querySelector('.rounded-lg').classList.add('p-2');
        panel.querySelector('.rounded-lg').classList.remove('p-4');
    } else {
        // Expand: show content, restore panel
        panel.classList.remove('lg:col-span-1');
        panel.classList.add('lg:col-span-3');
        panel.style.width = '';
        panel.style.minWidth = '';
        content.style.display = 'block';
        tablesTitle.style.display = 'block';
        queryPanel.classList.remove('lg:col-span-11');
        queryPanel.classList.add('lg:col-span-9');
        toggleIcon.style.transform = 'rotate(0deg)';
        // Restore padding
        panel.querySelector('.rounded-lg').classList.remove('p-2');
        panel.querySelector('.rounded-lg').classList.add('p-4');
    }
    localStorage.setItem('tablesCollapsed', tablesCollapsed);
}

// Initialize on page load
updateTablesPanel();

toggleBtn.addEventListener('click', function() {
    tablesCollapsed = !tablesCollapsed;
    updateTablesPanel();
});

// Save dialog functions
function showSaveDialog() {
    const query = document.getElementById('custom_query').value;
    document.getElementById('save_query_text').value = query;
    document.getElementById('save-dialog').classList.remove('hidden');
    document.getElementById('save-dialog').classList.add('flex');
    document.getElementById('saved_query_name').focus();
}

function hideSaveDialog() {
    document.getElementById('save-dialog').classList.add('hidden');
    document.getElementById('save-dialog').classList.remove('flex');
    document.getElementById('saved_query_name').value = '';
}

// Close dialog on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideSaveDialog();
    }
});
</script>

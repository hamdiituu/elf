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
                
                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- Tables List -->
                    <div class="lg:col-span-1">
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-6 pb-0">
                                <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Tablolar</h3>
                            </div>
                            <div class="p-6 pt-0">
                                <div class="space-y-2 max-h-[600px] overflow-y-auto">
                                    <?php foreach ($tables as $table): ?>
                                        <a
                                            href="?table=<?php echo htmlspecialchars($table); ?>"
                                            class="block p-3 rounded-md border border-border hover:bg-accent transition-colors <?php echo $selected_table === $table ? 'bg-accent border-primary' : ''; ?>"
                                        >
                                            <div class="flex items-center justify-between">
                                                <span class="font-medium text-sm"><?php echo htmlspecialchars($table); ?></span>
                                                <svg class="h-4 w-4 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
                    <div class="lg:col-span-2">
                        <!-- Custom Query Section -->
                        <div class="mb-6 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-6 pb-0">
                                <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">SQL Sorgusu</h3>
                            </div>
                            <div class="p-6 pt-0">
                                <form method="POST" action="">
                                    <div class="space-y-4">
                                        <div>
                                            <textarea
                                                name="custom_query"
                                                rows="4"
                                                class="w-full px-3 py-2 border border-input bg-background text-foreground rounded-md font-mono text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                                                placeholder="SELECT * FROM users LIMIT 10;"><?php echo htmlspecialchars($custom_query ?? ''); ?></textarea>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <button
                                                type="submit"
                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 px-4 py-2 transition-colors"
                                            >
                                                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                                Sorguyu Çalıştır
                                            </button>
                                            <?php if ($custom_query): ?>
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


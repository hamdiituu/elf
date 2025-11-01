<?php
require_once '../config/config.php';
requireDeveloper();

$page_title = 'Database Explorer - Vira Stok Sistemi';

$db = getDB();
$error_message = $_GET['error'] ?? null;
$success_message = $_GET['success'] ?? null;
$table_name = $_GET['table'] ?? null;
$query_result = null;
$selected_table = null;
$table_columns = [];
$table_data = [];
$custom_query = $_POST['custom_query'] ?? null;
$saved_query_name = $_POST['saved_query_name'] ?? null;
$load_query_id = $_GET['load_query'] ?? null;
$delete_query_id = $_GET['delete_query'] ?? null;
$export_excel = $_GET['export_excel'] ?? null;

// Ensure saved_queries table exists
try {
    $db->exec("CREATE TABLE IF NOT EXISTS saved_queries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        query TEXT NOT NULL,
        user_id INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
    
    // Migrate existing queries (add user_id column if it doesn't exist)
    try {
        $db->exec("ALTER TABLE saved_queries ADD COLUMN user_id INTEGER");
    } catch (PDOException $e) {
        // Column might already exist
    }
} catch (PDOException $e) {
    // Table might already exist, ignore error
}

// Handle save query
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_query' && $custom_query && $saved_query_name) {
    try {
        $stmt = $db->prepare("INSERT INTO saved_queries (name, query, user_id) VALUES (?, ?, ?)");
        $stmt->execute([$saved_query_name, $custom_query, $_SESSION['user_id']]);
        $success_message = "Query başarıyla kaydedildi!";
        
        // Redirect to prevent form resubmission
        header('Location: database-explorer.php?success=' . urlencode($success_message));
        exit;
    } catch (PDOException $e) {
        $error_message = "Query kaydedilirken hata: " . $e->getMessage();
    }
}

// Handle delete query
if ($delete_query_id) {
    try {
        // Only allow deletion of user's own queries or queries with no user_id
        $stmt = $db->prepare("DELETE FROM saved_queries WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
        $stmt->execute([$delete_query_id, $_SESSION['user_id']]);
        if ($stmt->rowCount() > 0) {
            $success_message = "Query başarıyla silindi!";
            header('Location: database-explorer.php?success=' . urlencode($success_message));
            exit;
        } else {
            $error_message = "Query bulunamadı veya silme izniniz yok!";
        }
    } catch (PDOException $e) {
        $error_message = "Query silinirken hata: " . $e->getMessage();
    }
}

// Handle create table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_table') {
    $table_name = trim($_POST['table_name'] ?? '');
    $table_sql = trim($_POST['table_sql'] ?? '');
    
    // Check if form-based or SQL-based creation
    if (isset($_POST['fields']) && is_array($_POST['fields'])) {
        // Form-based creation
        $fields = $_POST['fields'];
        $field_names = $_POST['field_names'] ?? [];
        $field_types = $_POST['field_types'] ?? [];
        $field_nullable = $_POST['field_nullable'] ?? [];
        $field_primary = $_POST['field_primary'] ?? [];
        
        if (empty($table_name)) {
            $error_message = "Tablo adı gereklidir!";
        } elseif (empty($fields)) {
            $error_message = "En az bir alan eklemelisiniz!";
        } else {
            try {
                // Validate table name (SQLite identifier rules)
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table_name)) {
                    throw new Exception("Geçersiz tablo adı! Sadece harf, rakam ve alt çizgi kullanılabilir.");
                }
                
                // Check if table already exists
                $existing_tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = " . $db->quote($table_name))->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($existing_tables)) {
                    throw new Exception("Tablo '{$table_name}' zaten mevcut!");
                }
                
                // Build SQL from form data
                $columns = [];
                $primary_keys = [];
                
                foreach ($fields as $index => $field_id) {
                    if (!isset($field_names[$index])) continue;
                    
                    $field_name = trim($field_names[$index] ?? '');
                    $field_type = $field_types[$index] ?? 'TEXT';
                    $is_nullable = isset($field_nullable[$index]) && $field_nullable[$index] === '1';
                    $is_primary = isset($field_primary[$index]) && $field_primary[$index] === '1';
                    
                    if (empty($field_name)) {
                        continue; // Skip empty fields
                    }
                    
                    // Validate field name
                    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field_name)) {
                        throw new Exception("Geçersiz alan adı: '$field_name'");
                    }
                    
                    // Convert BOOLEAN to INTEGER for SQLite
                    $sql_type = $field_type === 'BOOLEAN' ? 'INTEGER' : $field_type;
                    
                    $column_def = "`$field_name` $sql_type";
                    
                    // For BOOLEAN, add DEFAULT 0 if nullable, or NOT NULL DEFAULT 0
                    if ($field_type === 'BOOLEAN') {
                        if ($is_nullable) {
                            $column_def .= " DEFAULT 0";
                        } else {
                            $column_def .= " NOT NULL DEFAULT 0";
                        }
                    } elseif ($is_primary) {
                        $primary_keys[] = $field_name;
                        if ($field_type === 'INTEGER') {
                            $column_def .= " PRIMARY KEY AUTOINCREMENT";
                        }
                    } elseif (!$is_nullable) {
                        $column_def .= " NOT NULL";
                    }
                    
                    $columns[] = $column_def;
                }
                
                if (empty($columns)) {
                    throw new Exception("En az bir geçerli alan eklemelisiniz!");
                }
                
                // Build SQL statement
                $sql = "CREATE TABLE `$table_name` (\n  " . implode(",\n  ", $columns);
                
                // Add PRIMARY KEY constraint for non-INTEGER primary keys
                $non_integer_primary_keys = [];
                foreach ($primary_keys as $pk) {
                    $pk_index = array_search($pk, $field_names);
                    if ($pk_index !== false && ($field_types[$pk_index] ?? 'TEXT') !== 'INTEGER') {
                        $non_integer_primary_keys[] = $pk;
                    }
                }
                
                if (!empty($non_integer_primary_keys)) {
                    $sql .= ",\n  PRIMARY KEY (`" . implode("`, `", $non_integer_primary_keys) . "`)";
                }
                
                $sql .= "\n);";
                
                // Execute CREATE TABLE
                $db->exec($sql);
                $success_message = "Tablo '{$table_name}' başarıyla oluşturuldu!";
                header('Location: database-explorer.php?table=' . urlencode($table_name) . '&success=' . urlencode($success_message));
                exit;
            } catch (PDOException $e) {
                $error_message = "Tablo oluşturulurken hata: " . $e->getMessage();
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
    } else {
        // SQL-based creation (backward compatibility)
        if (empty($table_name)) {
            $error_message = "Tablo adı gereklidir!";
        } elseif (empty($table_sql)) {
            $error_message = "SQL sorgusu gereklidir!";
        } else {
            try {
                // Validate table name (SQLite identifier rules)
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table_name)) {
                    throw new Exception("Geçersiz tablo adı. Sadece harf, rakam ve alt çizgi kullanılabilir.");
                }
                
                // Check if table already exists
                $existing_tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = " . $db->quote($table_name))->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($existing_tables)) {
                    throw new Exception("Tablo '{$table_name}' zaten mevcut!");
                }
                
                // Execute CREATE TABLE statement
                $db->exec($table_sql);
                $success_message = "Tablo '{$table_name}' başarıyla oluşturuldu!";
                
                // Redirect to avoid form resubmission
                header('Location: database-explorer.php?table=' . urlencode($table_name) . '&success=' . urlencode($success_message));
                exit;
            } catch (Exception $e) {
                $error_message = "Tablo oluşturulurken hata: " . $e->getMessage();
            }
        }
    }
}

// Handle delete table
$delete_table = $_GET['delete_table'] ?? null;
if ($delete_table) {
    try {
        // Validate table name
        $all_tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array($delete_table, $all_tables)) {
            // Prevent deletion of system tables
            $system_tables = ['sqlite_sequence', 'sqlite_master'];
            if (in_array($delete_table, $system_tables)) {
                $error_message = "Sistem tabloları silinemez!";
            } else {
                $db->exec("DROP TABLE IF EXISTS " . $db->quote($delete_table));
                $success_message = "Tablo '{$delete_table}' başarıyla silindi!";
                header('Location: database-explorer.php?success=' . urlencode($success_message));
                exit;
            }
        } else {
            $error_message = "Tablo bulunamadı!";
        }
    } catch (PDOException $e) {
        $error_message = "Tablo silinirken hata: " . $e->getMessage();
    }
}

// Handle load query
$run_query_id = $_GET['run_query'] ?? null;
if ($load_query_id || $run_query_id) {
    $query_id = $run_query_id ? $run_query_id : $load_query_id;
    try {
        // Check if user_id column exists
        $table_info = $db->query("PRAGMA table_info(saved_queries)")->fetchAll(PDO::FETCH_ASSOC);
        $has_user_id = false;
        foreach ($table_info as $col) {
            if ($col['name'] === 'user_id') {
                $has_user_id = true;
                break;
            }
        }
        
        if ($has_user_id) {
            $stmt = $db->prepare("SELECT query FROM saved_queries WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
            $stmt->execute([$query_id, $_SESSION['user_id']]);
        } else {
            $stmt = $db->prepare("SELECT query FROM saved_queries WHERE id = ?");
            $stmt->execute([$query_id]);
        }
        $saved_query = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($saved_query) {
            $custom_query = $saved_query['query'];
            
            // If run_query is set, also execute it
            if ($run_query_id) {
                try {
                    $trimmed_query = trim($custom_query);
                    $query_upper = strtoupper($trimmed_query);
                    
                    // Execute query
                    $stmt = $db->prepare($trimmed_query);
                    $stmt->execute();
                    
                    // Check if it's a SELECT query
                    if (strpos($query_upper, 'SELECT') === 0) {
                        $query_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Get column names from first row if available
                        if (!empty($query_result)) {
                            $table_columns = array_keys($query_result[0]);
                        } else {
                            // Try to get column info from statement
                            $stmt2 = $db->prepare($trimmed_query);
                            $stmt2->execute();
                            $table_columns = [];
                            for ($i = 0; $i < $stmt2->columnCount(); $i++) {
                                $col = $stmt2->getColumnMeta($i);
                                $table_columns[] = $col['name'] ?? "Column " . ($i + 1);
                            }
                        }
                        $success_message = "Query başarıyla çalıştırıldı! " . count($query_result) . " satır bulundu.";
                    } else {
                        // For non-SELECT queries, show affected rows
                        $affected_rows = $stmt->rowCount();
                        $success_message = "Query başarıyla çalıştırıldı! Etkilenen satır sayısı: " . $affected_rows;
                    }
                } catch (PDOException $e) {
                    $error_message = "Query çalıştırılırken hata: " . $e->getMessage();
                }
            }
        } else {
            $error_message = "Query bulunamadı veya erişim izniniz yok!";
        }
    } catch (PDOException $e) {
        $error_message = "Query yüklenirken hata: " . $e->getMessage();
    }
}

// Handle Excel export (must be early, before page rendering)
if ($export_excel) {
    $export_data = null;
    $export_columns = [];
    $export_name = 'query_results';
    
    // Get table name from GET if not set yet
    $export_table_name = $_GET['table'] ?? $table_name ?? null;
    
    if ($export_excel === 'query') {
        // Get query from GET parameter
        $export_query = $_GET['custom_query'] ?? null;
        
        if ($export_query) {
            try {
                $trimmed_query = trim($export_query);
                $stmt = $db->prepare($trimmed_query);
                $stmt->execute();
                
                // Check if it's a SELECT query
                $query_upper = strtoupper($trimmed_query);
                if (strpos($query_upper, 'SELECT') === 0) {
                    $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get column names even if data is empty
                    if (!empty($export_data)) {
                        $export_columns = array_keys($export_data[0]);
                    } else {
                        // Try to get column info from statement
                        $export_columns = [];
                        for ($i = 0; $i < $stmt->columnCount(); $i++) {
                            $col = $stmt->getColumnMeta($i);
                            $export_columns[] = $col['name'] ?? "Column " . ($i + 1);
                        }
                    }
                    $export_name = 'query_results';
                }
            } catch (PDOException $e) {
                die("Query hatası: " . $e->getMessage());
            }
        } else {
            die("Export için query bulunamadı.");
        }
    } elseif ($export_excel === 'table') {
        if ($export_table_name) {
            try {
                // Get all tables to validate
                $all_tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                
                // Validate table name to prevent SQL injection
                if (in_array($export_table_name, $all_tables)) {
                    // Use quoted table name for safety
                    $quoted_table = '"' . str_replace('"', '""', $export_table_name) . '"';
                    $stmt = $db->query("SELECT * FROM $quoted_table LIMIT 10000");
                    $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($export_data)) {
                        $export_columns = array_keys($export_data[0]);
                    } else {
                        // Get column info from table structure
                        $table_info = $db->query("PRAGMA table_info($quoted_table)")->fetchAll(PDO::FETCH_ASSOC);
                        $export_columns = array_column($table_info, 'name');
                    }
                    $export_name = $export_table_name;
                } else {
                    die("Geçersiz tablo adı: " . htmlspecialchars($export_table_name));
                }
            } catch (PDOException $e) {
                die("Veri yüklenirken hata: " . $e->getMessage());
            }
        } else {
            die("Export için tablo adı belirtilmedi.");
        }
    }
    
    if ($export_data !== null) {
        // Ensure we have columns even if data is empty
        if (empty($export_columns) && !empty($export_data)) {
            $export_columns = array_keys($export_data[0]);
        }
        
        if (!empty($export_columns)) {
        // Set headers for CSV download
        $filename = $export_name . '_' . date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Add BOM for UTF-8 Excel compatibility
        echo "\xEF\xBB\xBF";
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Write column headers
        fputcsv($output, $export_columns, ';');
        
        // Write data rows
        foreach ($export_data as $row) {
            $csv_row = [];
            foreach ($export_columns as $col) {
                $value = $row[$col] ?? '';
                // Convert null to empty string
                if ($value === null) {
                    $value = '';
                }
                $csv_row[] = $value;
            }
            fputcsv($output, $csv_row, ';');
        }
        
            fclose($output);
            exit;
        } else {
            die("Export için kolon bilgisi bulunamadı.");
        }
    } else {
        die("Export için veri bulunamadı.");
    }
}

// Get saved queries for current user
$saved_queries = [];
try {
    // Check if table exists first
    $table_check = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='saved_queries'")->fetch();
    
    if ($table_check) {
        // Check if user_id column exists
        $table_info = $db->query("PRAGMA table_info(saved_queries)")->fetchAll(PDO::FETCH_ASSOC);
        $has_user_id = false;
        foreach ($table_info as $col) {
            if ($col['name'] === 'user_id') {
                $has_user_id = true;
                break;
            }
        }
        
        if ($has_user_id) {
            $stmt = $db->prepare("SELECT * FROM saved_queries WHERE user_id = ? OR user_id IS NULL ORDER BY updated_at DESC");
            $stmt->execute([$_SESSION['user_id']]);
            $saved_queries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // If user_id column doesn't exist, get all queries
            $saved_queries = $db->query("SELECT * FROM saved_queries ORDER BY updated_at DESC")->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    // Table might not exist yet or other error
    error_log("Error loading saved queries: " . $e->getMessage());
    $saved_queries = [];
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
                                <div class="mb-3">
                                    <button
                                        onclick="showCreateTableModal()"
                                        class="w-full inline-flex items-center justify-center rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 px-3 py-2 transition-colors mb-2"
                                    >
                                        <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                        </svg>
                                        Yeni Tablo
                                    </button>
                                </div>
                                <div class="space-y-2 max-h-[600px] overflow-y-auto">
                                    <?php foreach ($tables as $table): ?>
                                        <div class="group relative flex items-center">
                                            <a
                                                href="?table=<?php echo htmlspecialchars($table); ?>"
                                                class="flex-1 block p-2 rounded-md border border-border hover:bg-accent transition-colors <?php echo $selected_table === $table ? 'bg-accent border-primary' : ''; ?>"
                                            >
                                                <div class="flex items-center justify-between">
                                                    <span class="font-medium text-xs"><?php echo htmlspecialchars($table); ?></span>
                                                    <svg class="h-3 w-3 text-muted-foreground" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                                    </svg>
                                                </div>
                                            </a>
                                            <?php if (!in_array($table, ['sqlite_sequence', 'sqlite_master'])): ?>
                                                <button
                                                    onclick="deleteTable('<?php echo htmlspecialchars(addslashes($table)); ?>')"
                                                    class="ml-1 p-1 opacity-0 group-hover:opacity-100 transition-opacity text-red-600 hover:bg-red-50 rounded"
                                                    title="Tablo Sil"
                                                >
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Table Content / Query Result -->
                    <div class="lg:col-span-9 transition-all duration-300" id="query-panel">
                        <!-- Saved Queries Sidebar -->
                        <div class="mb-6 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-4 pb-0">
                                <h3 class="text-sm font-semibold leading-none tracking-tight mb-3">Kaydedilmiş Query'ler</h3>
                            </div>
                            <div class="p-4 pt-2">
                                <?php if (isset($saved_queries) && !empty($saved_queries)): ?>
                                    <div class="space-y-2 max-h-[200px] overflow-y-auto">
                                        <?php foreach ($saved_queries as $sq): ?>
                                            <div class="flex items-center justify-between bg-muted/50 rounded p-2 hover:bg-muted transition-colors">
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-sm font-medium text-foreground truncate"><?php echo htmlspecialchars($sq['name']); ?></span>
                                                        <span class="text-xs text-muted-foreground"><?php echo date('d.m.Y H:i', strtotime($sq['created_at'] ?? $sq['updated_at'] ?? '')); ?></span>
                                                    </div>
                                                    <p class="text-xs text-muted-foreground truncate mt-1"><?php echo htmlspecialchars(substr($sq['query'], 0, 60)); ?>...</p>
                                                </div>
                                                <div class="flex items-center gap-1 ml-2 flex-shrink-0">
                                                    <a
                                                        href="?run_query=<?php echo $sq['id']; ?><?php echo $selected_table ? '&table=' . htmlspecialchars($selected_table) : ''; ?>"
                                                        class="p-1.5 rounded hover:bg-green-600 hover:text-white transition-colors"
                                                        title="Çalıştır"
                                                    >
                                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                    </a>
                                                    <a
                                                        href="?load_query=<?php echo $sq['id']; ?><?php echo $selected_table ? '&table=' . htmlspecialchars($selected_table) : ''; ?>"
                                                        class="p-1.5 rounded hover:bg-primary hover:text-primary-foreground transition-colors"
                                                        title="Yükle"
                                                    >
                                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                                        </svg>
                                                    </a>
                                                    <?php if (!isset($sq['user_id']) || $sq['user_id'] == $_SESSION['user_id'] || $sq['user_id'] === null): ?>
                                                        <a
                                                            href="?delete_query=<?php echo $sq['id']; ?><?php echo $selected_table ? '&table=' . htmlspecialchars($selected_table) : ''; ?>"
                                                            class="p-1.5 rounded hover:bg-red-500 hover:text-white transition-colors"
                                                            title="Sil"
                                                            onclick="return confirm('Bu sorguyu silmek istediğinizden emin misiniz?');"
                                                        >
                                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                            </svg>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-sm text-muted-foreground">Henüz kayıtlı sorgu yok. Sorgunuzu yazıp "Kaydet" butonuna tıklayarak kaydedebilirsiniz.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Custom Query Section -->
                        <div class="mb-6 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-6 pb-0">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-semibold leading-none tracking-tight">SQL Sorgusu</h3>
                                    <?php if (isset($saved_queries) && !empty($saved_queries)): ?>
                                        <div class="flex items-center gap-2">
                                            <select
                                                id="load-saved-query"
                                                class="text-sm px-3 py-1.5 border border-input bg-background text-foreground rounded-md hover:bg-accent transition-colors flex-1"
                                                onchange="if(this.value) { handleSavedQuery(this.value); }"
                                            >
                                                <option value="">Kaydedilmiş Query Seç...</option>
                                                <?php foreach ($saved_queries as $sq): ?>
                                                    <option value="<?php echo $sq['id']; ?>" data-action="load"><?php echo htmlspecialchars($sq['name']); ?> (Yükle)</option>
                                                    <option value="<?php echo $sq['id']; ?>" data-action="run"><?php echo htmlspecialchars($sq['name']); ?> (Çalıştır)</option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button
                                                type="button"
                                                onclick="const select = document.getElementById('load-saved-query'); if(select.value) { handleSavedQuery(select.value); }"
                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-green-600 text-white hover:bg-green-700 px-3 py-1.5 transition-colors"
                                                title="Seçili Query'i Çalıştır"
                                            >
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                            </button>
                                        </div>
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
                                            <button
                                                type="button"
                                                onclick="showSaveDialog()"
                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-secondary text-secondary-foreground hover:bg-secondary/80 px-4 py-2 transition-colors"
                                                title="Sorguyu Kaydet"
                                            >
                                                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                                                </svg>
                                                Kaydet
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

                        <!-- Table Structure -->
                        <?php if ($selected_table && !empty($table_info)): ?>
                            <div class="mb-4 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                                <div class="p-4 pb-0">
                                    <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Tablo Yapısı: <?php echo htmlspecialchars($selected_table); ?></h3>
                                </div>
                                <div class="p-4 pt-0">
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-border">
                                            <thead class="bg-muted/50">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-foreground">Kolon Adı</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-foreground">Tip</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-foreground">NULL</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-foreground">Default</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-foreground">PK</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-border bg-background">
                                                <?php foreach ($table_info as $col): ?>
                                                    <tr class="hover:bg-muted/30">
                                                        <td class="px-3 py-2 text-xs font-mono text-foreground"><?php echo htmlspecialchars($col['name']); ?></td>
                                                        <td class="px-3 py-2 text-xs text-foreground"><?php echo htmlspecialchars($col['type']); ?></td>
                                                        <td class="px-3 py-2 text-xs text-foreground"><?php echo $col['notnull'] ? 'NO' : 'YES'; ?></td>
                                                        <td class="px-3 py-2 text-xs text-muted-foreground font-mono"><?php echo $col['dflt_value'] !== null ? htmlspecialchars($col['dflt_value']) : '-'; ?></td>
                                                        <td class="px-3 py-2 text-xs text-foreground"><?php echo $col['pk'] ? '✓' : '-'; ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Table Content -->
                        <?php 
                        // Prepare display data before rendering
                        $display_data = null;
                        $display_columns = [];
                        
                        if ($selected_table || $query_result !== null) {
                            $display_data = $query_result !== null ? $query_result : $table_data;
                            $display_columns = !empty($display_data) ? array_keys($display_data[0]) : $table_columns;
                        }
                        ?>
                        <?php if ($selected_table || $query_result !== null): ?>
                            <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                                <div class="p-6 pb-0">
                                    <div class="flex items-center justify-between mb-4">
                                        <h3 class="text-lg font-semibold leading-none tracking-tight">
                                            <?php 
                                            if ($query_result !== null) {
                                                echo 'Sorgu Sonuçları';
                                            } else {
                                                echo htmlspecialchars($selected_table) . ' Tablosu';
                                            }
                                            ?>
                                        </h3>
                                        <?php if (!empty($display_data)): ?>
                                            <a
                                                href="?export_excel=<?php echo $query_result !== null ? 'query' : 'table'; ?><?php echo $selected_table ? '&table=' . htmlspecialchars($selected_table) : ''; ?><?php echo $custom_query ? '&custom_query=' . urlencode($custom_query) : ''; ?>"
                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-green-600 text-white hover:bg-green-700 px-4 py-2 transition-colors"
                                            >
                                                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                </svg>
                                                Excel'e Aktar
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="p-6 pt-0">
                                    
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
<!-- Save Query Modal -->
<div id="save-dialog" class="fixed inset-0 hidden items-center justify-center z-50" onclick="if(event.target === this) hideSaveDialog()" style="background-color: rgba(0, 0, 0, 0.3) !important;">
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

<!-- Create Table Modal -->
<div id="create-table-dialog" class="fixed inset-0 hidden items-center justify-center z-50" onclick="if(event.target === this) hideCreateTableModal()" style="background-color: rgba(0, 0, 0, 0.3) !important;">
    <div class="border border-border rounded-lg shadow-lg p-6 max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()" style="background-color: hsl(var(--background)) !important; z-index: 51;">
        <h3 class="text-lg font-semibold mb-4">Yeni Tablo Oluştur</h3>
        <form method="POST" action="" id="create-table-form">
            <input type="hidden" name="action" value="create_table">
            <div class="space-y-4">
                <div>
                    <label for="table_name" class="block text-sm font-medium mb-2">Tablo Adı:</label>
                    <input
                        type="text"
                        name="table_name"
                        id="table_name"
                        required
                        pattern="[a-zA-Z_][a-zA-Z0-9_]*"
                        class="w-full px-3 py-2 border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-2 focus:ring-ring font-mono"
                        placeholder="ornek_tablo"
                        title="Sadece harf, rakam ve alt çizgi kullanılabilir. İlk karakter harf veya alt çizgi olmalıdır."
                    >
                    <p class="text-xs text-muted-foreground mt-1">Sadece harf, rakam ve alt çizgi kullanılabilir</p>
                </div>
                
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium">Alanlar:</label>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                onclick="addTimestamps()"
                                class="px-3 py-1 text-xs font-medium bg-blue-500 text-white hover:bg-blue-600 rounded-md transition-colors"
                                title="created_at ve updated_at alanlarını otomatik ekle"
                            >
                                + Timestamps
                            </button>
                            <button
                                type="button"
                                onclick="addTableField()"
                                class="px-3 py-1 text-xs font-medium bg-primary text-primary-foreground hover:bg-primary/90 rounded-md transition-colors"
                            >
                                + Alan Ekle
                            </button>
                        </div>
                    </div>
                    <div id="table-fields-container" class="space-y-3">
                        <!-- Fields will be added here -->
                    </div>
                </div>
                
                <div class="flex items-center gap-2 justify-end">
                    <button
                        type="button"
                        onclick="hideCreateTableModal()"
                        class="px-4 py-2 text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 rounded-md transition-colors"
                    >
                        İptal
                    </button>
                    <button
                        type="submit"
                        class="px-4 py-2 text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 rounded-md transition-colors"
                    >
                        Oluştur
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

// Create Table Modal functions
let fieldCounter = 0;

function addTableField(fieldName = '', fieldType = 'TEXT', isNullable = true, isPrimary = false) {
    const container = document.getElementById('table-fields-container');
    const fieldId = 'field_' + (fieldCounter++);
    
    const fieldHtml = `
        <div class="border border-input rounded-md p-3 bg-muted/30" data-field-id="${fieldId}">
            <div class="grid grid-cols-12 gap-2 items-end">
                <div class="col-span-4">
                    <label class="block text-xs font-medium mb-1">Alan Adı:</label>
                    <input
                        type="text"
                        name="field_names[]"
                        value="${fieldName}"
                        required
                        pattern="[a-zA-Z_][a-zA-Z0-9_]*"
                        class="w-full px-2 py-1.5 text-sm border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-1 focus:ring-ring font-mono"
                        placeholder="alan_adi"
                    >
                </div>
                <div class="col-span-3">
                    <label class="block text-xs font-medium mb-1">Tip:</label>
                    <select
                        name="field_types[]"
                        class="w-full px-2 py-1.5 text-sm border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-1 focus:ring-ring"
                    >
                        <option value="TEXT" ${fieldType === 'TEXT' ? 'selected' : ''}>TEXT</option>
                        <option value="INTEGER" ${fieldType === 'INTEGER' ? 'selected' : ''}>INTEGER</option>
                        <option value="REAL" ${fieldType === 'REAL' ? 'selected' : ''}>REAL</option>
                        <option value="BLOB" ${fieldType === 'BLOB' ? 'selected' : ''}>BLOB</option>
                        <option value="NUMERIC" ${fieldType === 'NUMERIC' ? 'selected' : ''}>NUMERIC</option>
                        <option value="BOOLEAN" ${fieldType === 'BOOLEAN' ? 'selected' : ''}>BOOLEAN</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="flex items-center gap-1 text-xs font-medium cursor-pointer">
                        <input
                            type="checkbox"
                            name="field_nullable[]"
                            value="1"
                            ${isNullable ? 'checked' : ''}
                            class="rounded border-input"
                            onchange="updateNullableCheckbox(this)"
                        >
                        <span>Nullable</span>
                    </label>
                </div>
                <div class="col-span-2">
                    <label class="flex items-center gap-1 text-xs font-medium cursor-pointer">
                        <input
                            type="checkbox"
                            name="field_primary[]"
                            value="1"
                            ${isPrimary ? 'checked' : ''}
                            class="rounded border-input"
                        >
                        <span>Primary Key</span>
                    </label>
                </div>
                <div class="col-span-1">
                    <button
                        type="button"
                        onclick="removeTableField('${fieldId}')"
                        class="w-full px-2 py-1.5 text-xs font-medium bg-red-500 text-white hover:bg-red-600 rounded-md transition-colors"
                        title="Alanı Sil"
                    >
                        ✕
                    </button>
                </div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', fieldHtml);
    
    // Update field IDs for form submission
    updateFieldIds();
}

function removeTableField(fieldId) {
    const field = document.querySelector(`[data-field-id="${fieldId}"]`);
    if (field) {
        field.remove();
        updateFieldIds();
    }
}

function updateFieldIds() {
    const container = document.getElementById('table-fields-container');
    const fields = container.querySelectorAll('[data-field-id]');
    fields.forEach((field, index) => {
        field.setAttribute('data-field-id', 'field_' + index);
        const hiddenInput = field.querySelector('input[type="hidden"][name="fields[]"]');
        if (hiddenInput) {
            hiddenInput.value = 'field_' + index;
        } else {
            const hiddenField = document.createElement('input');
            hiddenField.type = 'hidden';
            hiddenField.name = 'fields[]';
            hiddenField.value = 'field_' + index;
            field.appendChild(hiddenField);
        }
    });
}

function updateNullableCheckbox(checkbox) {
    // Update hidden field for nullable
    const field = checkbox.closest('[data-field-id]');
    if (field) {
        // The checkbox value is already in the form, no need for hidden field
    }
}

function showCreateTableModal() {
    document.getElementById('create-table-dialog').classList.remove('hidden');
    document.getElementById('create-table-dialog').classList.add('flex');
    
    // Reset form and add first field
    document.getElementById('table_name').value = '';
    const container = document.getElementById('table-fields-container');
    container.innerHTML = '';
    fieldCounter = 0;
    addTableField(); // Add first empty field
    
    document.getElementById('table_name').focus();
}

function hideCreateTableModal() {
    document.getElementById('create-table-dialog').classList.add('hidden');
    document.getElementById('create-table-dialog').classList.remove('flex');
    // Reset form
    document.getElementById('table_name').value = '';
    const container = document.getElementById('table-fields-container');
    container.innerHTML = '';
    fieldCounter = 0;
}

function addTimestamps() {
    // Check if timestamps already exist
    const container = document.getElementById('table-fields-container');
    const existingFields = container.querySelectorAll('input[name="field_names[]"]');
    const existingNames = Array.from(existingFields).map(input => input.value.toLowerCase());
    
    if (!existingNames.includes('created_at')) {
        addTableField('created_at', 'TEXT', false, false);
        // Update the field type to use DATETIME/TIMESTAMP pattern
        const lastField = container.lastElementChild;
        const typeSelect = lastField.querySelector('select[name="field_types[]"]');
        if (typeSelect) {
            typeSelect.value = 'TEXT';
        }
    }
    
    if (!existingNames.includes('updated_at')) {
        addTableField('updated_at', 'TEXT', false, false);
        // Update the field type to use DATETIME/TIMESTAMP pattern
        const lastField = container.lastElementChild;
        const typeSelect = lastField.querySelector('select[name="field_types[]"]');
        if (typeSelect) {
            typeSelect.value = 'TEXT';
        }
    }
}

// Delete Table function
function deleteTable(tableName) {
    if (confirm('Tablo "' + tableName + '" silinecek. Bu işlem geri alınamaz. Emin misiniz?')) {
        window.location.href = '?delete_table=' + encodeURIComponent(tableName);
    }
}

// Handle saved query selection
function handleSavedQuery(queryId) {
    const select = document.getElementById('load-saved-query');
    const selectedOption = select.options[select.selectedIndex];
    const action = selectedOption ? selectedOption.getAttribute('data-action') : 'run';
    const tableParam = '<?php echo $selected_table ? '&table=' . htmlspecialchars($selected_table) : ''; ?>';
    
    if (action === 'run') {
        window.location.href = '?run_query=' + queryId + tableParam;
    } else {
        window.location.href = '?load_query=' + queryId + tableParam;
    }
}

// Close dialogs on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const createDialog = document.getElementById('create-table-dialog');
        const saveDialog = document.getElementById('save-dialog');
        
        if (!createDialog.classList.contains('hidden')) {
            hideCreateTableModal();
        } else if (!saveDialog.classList.contains('hidden')) {
            hideSaveDialog();
        }
    }
});
</script>

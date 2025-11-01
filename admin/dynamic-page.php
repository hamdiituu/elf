<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$page_name = $_GET['page'] ?? null;

if (!$page_name) {
    header('Location: admin.php');
    exit;
}

$db = getDB();

// Get page configuration from database
$stmt = $db->prepare("SELECT * FROM dynamic_pages WHERE page_name = ?");
$stmt->execute([$page_name]);
$page_config = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$page_config) {
    header('Location: admin.php');
    exit;
}

$page_title = $page_config['page_title'] . '';
$table_name = $page_config['table_name'];
$enable_list = $page_config['enable_list'];
$enable_create = $page_config['enable_create'];
$enable_update = $page_config['enable_update'];
$enable_delete = $page_config['enable_delete'];

// Get table structure with error handling
$columns = [];
$primary_key = 'id'; // Default
try {
    // Escape table name to prevent SQL injection
    $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
    $stmt = $db->query("PRAGMA table_info(\"$escaped_table_name\")");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($columns)) {
        throw new PDOException("Tablo bulunamadı: $table_name");
    }
    
    foreach ($columns as $col) {
        if ($col['pk']) {
            $primary_key = $col['name'];
            break;
        }
    }
} catch (PDOException $e) {
    $error_message = "Tablo bulunamadı: <strong>" . htmlspecialchars($table_name) . "</strong><br>" . 
                     "Lütfen tablo adının doğru olduğundan ve veritabanında mevcut olduğundan emin olun.";
    include '../includes/header.php';
    ?>
    <div class="flex h-screen overflow-hidden">
        <?php include '../includes/admin-sidebar.php'; ?>
        <main class="flex-1 overflow-y-auto">
            <div class="py-6">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                    <div class="rounded-md bg-red-50 p-4 border border-red-200">
                        <div class="text-sm text-red-800">
                            <?php echo $error_message; ?>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="admin.php" class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90">
                            Ana Sayfaya Dön
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>
    <?php include '../includes/footer.php'; ?>
    <?php
    exit;
}

/**
 * Execute rule as PHP code
 * @param string $rule PHP code to execute
 * @param array $context Variables available in rule
 * @return mixed Rule return value or true if no return
 * @throws Exception if rule throws exception or returns false
 */
function executeRule($rule, $context = []) {
    if (empty($rule)) {
        return true;
    }
    
    // Extract context variables
    extract($context);
    
    // Execute rule as PHP code
    ob_start();
    try {
        $result = eval('?>' . $rule);
        ob_end_clean();
        
        // If rule returns false, throw exception to stop execution
        if ($result === false) {
            throw new Exception('Rule validation failed');
        }
        
        // Return result or true if no return value
        return $result !== null ? $result : true;
    } catch (ParseError $e) {
        ob_end_clean();
        throw new Exception('Rule syntax hatası: ' . $e->getMessage());
    } catch (Exception $e) {
        ob_end_clean();
        throw $e;
    }
}

// Handle operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                if ($enable_create) {
                    // Execute create rule before insert
                    if (!empty($page_config['create_rule'])) {
                        try {
                            // Prepare POST data as record for rule context
                            $rule_record = [];
                            foreach ($columns as $col) {
                                $col_name = $col['name'];
                                if (isset($_POST[$col_name])) {
                                    if ($col['type'] === 'integer' || $col['type'] === 'real') {
                                        $rule_record[$col_name] = intval($_POST[$col_name]);
                                    } else {
                                        $rule_record[$col_name] = trim($_POST[$col_name]);
                                    }
                                }
                            }
                            
                            $rule_context = [
                                'record' => $rule_record,
                                'columns' => $columns,
                                'is_edit' => false,
                                'db' => $db
                            ];
                            
                            executeRule($page_config['create_rule'], $rule_context);
                        } catch (Exception $e) {
                            $error_message = "Kural hatası: " . $e->getMessage();
                            break; // Stop execution
                        }
                    }
                    
                    try {
                        $insert_columns = [];
                        $insert_placeholders = [];
                        $values = [];
                        
                        // Check if table has created_at and updated_at columns
                        $has_created_at = false;
                        $has_updated_at = false;
                        foreach ($columns as $col) {
                            if (strtolower($col['name']) === 'created_at') {
                                $has_created_at = true;
                            }
                            if (strtolower($col['name']) === 'updated_at') {
                                $has_updated_at = true;
                            }
                        }
                        
                        foreach ($columns as $col) {
                            // Skip auto-increment primary key
                            if ($col['pk'] == 1 && strtolower($col['type']) === 'integer') {
                                continue;
                            }
                            // Skip timestamps (will be added automatically if they exist)
                            if (strtolower($col['name']) === 'created_at' || strtolower($col['name']) === 'updated_at') {
                                continue;
                            }
                            
                            $col_name = $col['name'];
                            $insert_columns[] = $col_name;
                            $insert_placeholders[] = "?";
                            
                            // Check if field is boolean (INTEGER with default 0/1)
                            $is_boolean = false;
                            if (strtolower($col['type']) === 'integer') {
                                $dflt_val = $col['dflt_value'] ?? '';
                                if ($dflt_val === '0' || $dflt_val === '1' || 
                                    preg_match('/^(is_|has_|can_|should_|must_|.*_(mi|mu|mi_durum|durum)$)/i', $col_name)) {
                                    $is_boolean = true;
                                }
                            }
                            
                            if ($is_boolean) {
                                // For boolean fields, checkbox sends value "1" if checked, or nothing if unchecked
                                $values[] = isset($_POST[$col_name]) ? 1 : 0;
                            } elseif (isset($_POST[$col_name])) {
                                $col_type_lower = strtolower($col['type']);
                                if ($col_type_lower === 'integer' || $col_type_lower === 'real') {
                                    $values[] = intval($_POST[$col_name]);
                                } else {
                                    $values[] = trim($_POST[$col_name]);
                                }
                            } else {
                                if ($col['notnull'] == 1 && $col['dflt_value'] === null) {
                                    $values[] = '';
                                } else {
                                    $values[] = null;
                                }
                            }
                        }
                        
                        // Add created_at and updated_at to columns if they exist
                        $timestamp_columns = [];
                        $timestamp_values = [];
                        if ($has_created_at) {
                            $timestamp_columns[] = 'created_at';
                            $timestamp_values[] = 'CURRENT_TIMESTAMP';
                        }
                        if ($has_updated_at) {
                            $timestamp_columns[] = 'updated_at';
                            $timestamp_values[] = 'CURRENT_TIMESTAMP';
                        }
                        
                        // Combine regular columns with timestamp columns
                        $all_columns = array_merge($insert_columns, $timestamp_columns);
                        // For regular columns use ?, for timestamps use CURRENT_TIMESTAMP directly
                        $all_values_sql = array_merge($insert_placeholders, $timestamp_values);
                        
                        // Build SQL - escape table name for security (SQLite uses double quotes)
                        $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
                        $sql = "INSERT INTO \"$escaped_table_name\" (" . implode(", ", $all_columns) . ") VALUES (" . implode(", ", $all_values_sql) . ")";
                        $stmt = $db->prepare($sql);
                        $stmt->execute($values);
                        $success_message = "Kayıt başarıyla eklendi!";
                    } catch (PDOException $e) {
                        $error_message = "Kayıt eklenirken hata: " . $e->getMessage();
                    }
                }
                break;
                
            case 'update':
                if ($enable_update) {
                    $record_id = intval($_POST['record_id'] ?? 0);
                    if ($record_id > 0) {
                        // Execute update rule before update
                        if (!empty($page_config['update_rule'])) {
                            try {
                                // Get current record from database
                                $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
                                $escaped_primary_key = preg_replace('/[^a-zA-Z0-9_]/', '', $primary_key);
                                $stmt = $db->prepare("SELECT * FROM \"$escaped_table_name\" WHERE \"$escaped_primary_key\" = ?");
                                $stmt->execute([$record_id]);
                                $current_record = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if (!$current_record) {
                                    $error_message = "Kayıt bulunamadı!";
                                    break;
                                }
                                
                                // Prepare updated record data
                                $rule_record = $current_record;
                                foreach ($columns as $col) {
                                    $col_name = $col['name'];
                                    if (isset($_POST[$col_name])) {
                                        if ($col['type'] === 'integer' || $col['type'] === 'real') {
                                            $rule_record[$col_name] = intval($_POST[$col_name]);
                                        } else {
                                            $rule_record[$col_name] = trim($_POST[$col_name]);
                                        }
                                    }
                                }
                                
                                $rule_context = [
                                    'record' => $rule_record,
                                    'columns' => $columns,
                                    'is_edit' => true,
                                    'db' => $db,
                                    'current_record' => $current_record
                                ];
                                
                                executeRule($page_config['update_rule'], $rule_context);
                            } catch (Exception $e) {
                                $error_message = "Kural hatası: " . $e->getMessage();
                                break; // Stop execution
                            }
                        }
                        
                        try {
                            $set_parts = [];
                            $values = [];
                            
                            foreach ($columns as $col) {
                                if ($col['pk']) continue; // Skip primary key
                                if (strtolower($col['name']) === 'created_at') continue; // Skip created_at
                                
                                $col_name = $col['name'];
                                
                                // Check if field is boolean
                                $is_boolean = false;
                                if (strtolower($col['type']) === 'integer') {
                                    $dflt_val = $col['dflt_value'] ?? '';
                                    if ($dflt_val === '0' || $dflt_val === '1' || 
                                        preg_match('/^(is_|has_|can_|should_|must_|.*_(mi|mu|mi_durum|durum)$)/i', $col_name)) {
                                        $is_boolean = true;
                                    }
                                }
                                
                                if ($is_boolean) {
                                    // For boolean fields, always update (checkbox sends "1" if checked, nothing if unchecked)
                                    $set_parts[] = "$col_name = ?";
                                    $values[] = isset($_POST[$col_name]) ? 1 : 0;
                                } elseif (isset($_POST[$col_name])) {
                                    $set_parts[] = "$col_name = ?";
                                    $col_type_lower = strtolower($col['type']);
                                    if ($col_type_lower === 'integer' || $col_type_lower === 'real') {
                                        $values[] = intval($_POST[$col_name]);
                                    } else {
                                        $values[] = trim($_POST[$col_name]);
                                    }
                                }
                            }
                            
                            // Always update updated_at if column exists
                            $has_updated_at = false;
                            foreach ($columns as $col) {
                                if (strtolower($col['name']) === 'updated_at') {
                                    $has_updated_at = true;
                                    break;
                                }
                            }
                            if ($has_updated_at) {
                                $set_parts[] = "updated_at = CURRENT_TIMESTAMP";
                            }
                            
                            $values[] = $record_id;
                            $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
                            $escaped_primary_key = preg_replace('/[^a-zA-Z0-9_]/', '', $primary_key);
                            $stmt = $db->prepare("UPDATE \"$escaped_table_name\" SET " . implode(", ", $set_parts) . " WHERE \"$escaped_primary_key\" = ?");
                            $stmt->execute($values);
                            $success_message = "Kayıt başarıyla güncellendi!";
                        } catch (PDOException $e) {
                            $error_message = "Kayıt güncellenirken hata: " . $e->getMessage();
                        }
                    }
                }
                break;
                
            case 'delete':
                if ($enable_delete) {
                    $record_id = intval($_POST['record_id'] ?? 0);
                    if ($record_id > 0) {
                        // Execute delete rule before delete
                        if (!empty($page_config['delete_rule'])) {
                            try {
                                // Get record from database before delete
                                $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
                                $escaped_primary_key = preg_replace('/[^a-zA-Z0-9_]/', '', $primary_key);
                                $stmt = $db->prepare("SELECT * FROM \"$escaped_table_name\" WHERE \"$escaped_primary_key\" = ?");
                                $stmt->execute([$record_id]);
                                $record = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if (!$record) {
                                    $error_message = "Kayıt bulunamadı!";
                                } else {
                                    $rule_context = [
                                        'record' => $record,
                                        'columns' => $columns,
                                        'is_edit' => false,
                                        'db' => $db
                                    ];
                                    
                                    executeRule($page_config['delete_rule'], $rule_context);
                                    
                                    // If rule passes, proceed with delete
                                    try {
                                        $stmt = $db->prepare("DELETE FROM \"$escaped_table_name\" WHERE \"$escaped_primary_key\" = ?");
                                        $stmt->execute([$record_id]);
                                        $success_message = "Kayıt başarıyla silindi!";
                                        header('Location: dynamic-page.php?page=' . urlencode($page_name));
                                        exit;
                                    } catch (PDOException $e) {
                                        $error_message = "Kayıt silinirken hata: " . $e->getMessage();
                                    }
                                }
                            } catch (Exception $e) {
                                $error_message = "Kural hatası: " . $e->getMessage();
                                // Don't redirect, show error on page
                            }
                        } else {
                            // No rule, proceed with normal delete
                            try {
                                $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
                                $escaped_primary_key = preg_replace('/[^a-zA-Z0-9_]/', '', $primary_key);
                                $stmt = $db->prepare("DELETE FROM \"$escaped_table_name\" WHERE \"$escaped_primary_key\" = ?");
                                $stmt->execute([$record_id]);
                                $success_message = "Kayıt başarıyla silindi!";
                                header('Location: dynamic-page.php?page=' . urlencode($page_name));
                                exit;
                            } catch (PDOException $e) {
                                $error_message = "Kayıt silinirken hata: " . $e->getMessage();
                            }
                        }
                    }
                }
                break;
        }
    }
}

// Get filter parameters
if ($enable_list) {
    $filters = [];
    $where_conditions = [];
    $where_values = [];
    
    foreach ($columns as $col) {
        $col_name = $col['name'];
        $col_type_lower = strtolower($col['type']);
        
        // Skip primary key and timestamps for basic filters
        if ($col['pk'] == 1) continue;
        if (strtolower($col_name) === 'created_at' || strtolower($col_name) === 'updated_at') continue;
        
        // Check if field is boolean
        $is_boolean = false;
        if ($col_type_lower === 'integer') {
            $dflt_val = $col['dflt_value'] ?? '';
            if ($dflt_val === '0' || $dflt_val === '1' || 
                preg_match('/^(is_|has_|can_|should_|must_|.*_(mi|mu|mi_durum|durum)$)/i', $col_name)) {
                $is_boolean = true;
            }
        }
        
        // Filter for each column
        if ($is_boolean) {
            // Boolean filter (exact match: 0 or 1)
            if (isset($_GET['filter_' . $col_name]) && $_GET['filter_' . $col_name] !== '') {
                $filters[$col_name] = trim($_GET['filter_' . $col_name]);
                $where_conditions[] = "$col_name = ?";
                $where_values[] = intval($filters[$col_name]);
            }
        } elseif ($col_type_lower === 'text') {
            // Text search (LIKE)
            if (!empty($_GET['filter_' . $col_name])) {
                $filters[$col_name] = trim($_GET['filter_' . $col_name]);
                $where_conditions[] = "$col_name LIKE ?";
                $where_values[] = '%' . $filters[$col_name] . '%';
            }
        } else if ($col_type_lower === 'integer' || $col_type_lower === 'real') {
            // Exact match for numbers
            if (isset($_GET['filter_' . $col_name]) && $_GET['filter_' . $col_name] !== '') {
                $filters[$col_name] = trim($_GET['filter_' . $col_name]);
                $where_conditions[] = "$col_name = ?";
                $where_values[] = $filters[$col_name];
            }
            // Range filters
            if (isset($_GET['filter_' . $col_name . '_min']) && $_GET['filter_' . $col_name . '_min'] !== '') {
                $where_conditions[] = "$col_name >= ?";
                $where_values[] = intval($_GET['filter_' . $col_name . '_min']);
            }
            if (isset($_GET['filter_' . $col_name . '_max']) && $_GET['filter_' . $col_name . '_max'] !== '') {
                $where_conditions[] = "$col_name <= ?";
                $where_values[] = intval($_GET['filter_' . $col_name . '_max']);
            }
        }
    }
    
    // Pagination (use page_num to avoid conflict with page parameter)
    $current_page_num = isset($_GET['page_num']) ? max(1, intval($_GET['page_num'])) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 20;
    $offset = ($current_page_num - 1) * $per_page;
    
    // Build WHERE clause
    $where_sql = '';
    if (!empty($where_conditions)) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_conditions);
    }
    
    // Initialize variables for pagination
    $records = [];
    $total_records = 0;
    $total_pages = 0;
    
    // Get total count with error handling
    try {
        // Escape table name to prevent SQL injection
        $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM \"$escaped_table_name\" $where_sql");
        if (!empty($where_values)) {
            $count_stmt->execute($where_values);
        } else {
            $count_stmt->execute();
        }
        $total_records = $count_stmt->fetchColumn();
        $total_pages = ceil($total_records / $per_page);
        
        // Sorting
        $sort_column = $_GET['sort'] ?? $primary_key;
        $sort_order = strtoupper($_GET['order'] ?? 'DESC');
        if (!in_array($sort_order, ['ASC', 'DESC'])) {
            $sort_order = 'DESC';
        }
        // Validate sort_column against actual columns
        $valid_columns = [];
        foreach ($columns as $col) {
            $valid_columns[] = $col['name'];
        }
        if (!in_array($sort_column, $valid_columns)) {
            $sort_column = $primary_key;
        }
        
        // Get records with pagination
        $escaped_sort_column = preg_replace('/[^a-zA-Z0-9_]/', '', $sort_column);
        $sql = "SELECT * FROM \"$escaped_table_name\" $where_sql ORDER BY \"$escaped_sort_column\" $sort_order LIMIT $per_page OFFSET $offset";
        $stmt = $db->prepare($sql);
        if (!empty($where_values)) {
            $stmt->execute($where_values);
        } else {
            $stmt->execute();
        }
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message = "Veritabanı hatası: <strong>" . htmlspecialchars($table_name) . "</strong> tablosuna erişilemiyor.<br>" . 
                         "Hata: " . htmlspecialchars($e->getMessage()) . "<br>" .
                         "Lütfen tablo adının doğru olduğundan ve veritabanında mevcut olduğundan emin olun.";
    }
}

// Get record for editing with error handling
$edit_record = null;
$edit_id = $_GET['edit'] ?? null;
if ($edit_id && $enable_update) {
    try {
        $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
        $escaped_primary_key = preg_replace('/[^a-zA-Z0-9_]/', '', $primary_key);
        $stmt = $db->prepare("SELECT * FROM \"$escaped_table_name\" WHERE \"$escaped_primary_key\" = ?");
        $stmt->execute([$edit_id]);
        $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        if (!isset($error_message)) {
            $error_message = "Kayıt getirilirken hata oluştu: " . htmlspecialchars($e->getMessage());
        }
    }
}

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8"><?php echo htmlspecialchars($page_config['page_title']); ?></h1>
                
                <?php if (isset($success_message)): ?>
                    <div class="mb-6 rounded-md bg-green-50 p-4 border border-green-200">
                        <div class="text-sm text-green-800">
                            <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="mb-6 rounded-md bg-red-50 p-4 border border-red-200">
                        <div class="text-sm text-red-800">
                            <?php echo $error_message; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php
                // Include the page template rendering
                // For now, we'll use a helper function to render the page
                require_once __DIR__ . '/../includes/dynamic-page-renderer.php';
                renderDynamicPage($db, $page_config, $columns, $primary_key, $enable_list, $enable_create, $enable_update, $enable_delete, $edit_record, $records ?? [], $total_records ?? 0, $total_pages ?? 0, $current_page_num ?? 1, $per_page ?? 20, $offset ?? 0, $sort_column ?? $primary_key, $sort_order ?? 'DESC', $page_name, $page_config);
                ?>
            </div>
        </div>
    </main>
</div>
<?php include '../includes/footer.php'; ?>

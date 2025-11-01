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

$page_title = $page_config['page_title'] . ' - Vira Stok Sistemi';
$table_name = $page_config['table_name'];
$enable_list = $page_config['enable_list'];
$enable_create = $page_config['enable_create'];
$enable_update = $page_config['enable_update'];
$enable_delete = $page_config['enable_delete'];

// Get table structure
$stmt = $db->query("PRAGMA table_info($table_name)");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$primary_key = null;
foreach ($columns as $col) {
    if ($col['pk']) {
        $primary_key = $col['name'];
        break;
    }
}
if (!$primary_key) {
    $primary_key = 'id'; // Default
}

// Handle operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                if ($enable_create) {
                    try {
                        $insert_columns = [];
                        $insert_placeholders = [];
                        $values = [];
                        
                        foreach ($columns as $col) {
                            // Skip auto-increment primary key
                            if ($col['pk'] == 1 && strtolower($col['type']) === 'integer') {
                                continue;
                            }
                            // Skip timestamps
                            if (strtolower($col['name']) === 'created_at' || strtolower($col['name']) === 'updated_at') {
                                continue;
                            }
                            
                            $col_name = $col['name'];
                            $insert_columns[] = $col_name;
                            $insert_placeholders[] = "?";
                            
                            if (isset($_POST[$col_name])) {
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
                        
                        $sql = "INSERT INTO $table_name (" . implode(", ", $insert_columns) . ") VALUES (" . implode(", ", $insert_placeholders) . ")";
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
                        try {
                            $set_parts = [];
                            $values = [];
                            
                            foreach ($columns as $col) {
                                if ($col['pk']) continue; // Skip primary key
                                if (strtolower($col['name']) === 'created_at') continue; // Skip created_at
                                
                                $col_name = $col['name'];
                                if (isset($_POST[$col_name])) {
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
                            $stmt = $db->prepare("UPDATE $table_name SET " . implode(", ", $set_parts) . " WHERE $primary_key = ?");
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
                        try {
                            $stmt = $db->prepare("DELETE FROM $table_name WHERE $primary_key = ?");
                            $stmt->execute([$record_id]);
                            $success_message = "Kayıt başarıyla silindi!";
                        } catch (PDOException $e) {
                            $error_message = "Kayıt silinirken hata: " . $e->getMessage();
                        }
                    }
                    header('Location: dynamic-page.php?page=' . urlencode($page_name));
                    exit;
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
        
        // Filter for each column
        if ($col_type_lower === 'text') {
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
    
    // Get total count
    $count_stmt = $db->prepare("SELECT COUNT(*) FROM $table_name $where_sql");
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
    $sql = "SELECT * FROM $table_name $where_sql ORDER BY $sort_column $sort_order LIMIT $per_page OFFSET $offset";
    $stmt = $db->prepare($sql);
    if (!empty($where_values)) {
        $stmt->execute($where_values);
    } else {
        $stmt->execute();
    }
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get record for editing
$edit_record = null;
$edit_id = $_GET['edit'] ?? null;
if ($edit_id && $enable_update) {
    $stmt = $db->prepare("SELECT * FROM $table_name WHERE $primary_key = ?");
    $stmt->execute([$edit_id]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
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
                            <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php
                // Include the page template rendering
                // For now, we'll use a helper function to render the page
                require_once __DIR__ . '/../includes/dynamic-page-renderer.php';
                renderDynamicPage($db, $page_config, $columns, $primary_key, $enable_list, $enable_create, $enable_update, $enable_delete, $edit_record, $records ?? [], $total_records ?? 0, $total_pages ?? 0, $current_page_num ?? 1, $per_page ?? 20, $offset ?? 0, $sort_column ?? $primary_key, $sort_order ?? 'DESC', $page_name);
                ?>
            </div>
        </div>
    </main>
</div>
<?php include '../includes/footer.php'; ?>

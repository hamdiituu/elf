<?php
require_once '../config/config.php';
requireDeveloper();

$page_title = 'Database Explorer';

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
        $success_message = "Query saved successfully!";
        
        // Redirect to prevent form resubmission
        header('Location: database-explorer.php?success=' . urlencode($success_message));
        exit;
    } catch (PDOException $e) {
        $error_message = "Error saving query: " . $e->getMessage();
    }
}

// Handle delete query
if ($delete_query_id) {
    try {
        // Only allow deletion of user's own queries or queries with no user_id
        $stmt = $db->prepare("DELETE FROM saved_queries WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
        $stmt->execute([$delete_query_id, $_SESSION['user_id']]);
        if ($stmt->rowCount() > 0) {
            $success_message = "Query deleted successfully!";
            header('Location: database-explorer.php?success=' . urlencode($success_message));
            exit;
        } else {
            $error_message = "Query not found or you don't have permission to delete it!";
        }
    } catch (PDOException $e) {
        $error_message = "Error deleting query: " . $e->getMessage();
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
        $field_relation_target = $_POST['field_relation_target'] ?? [];
        
        if (empty($table_name)) {
            $error_message = "Table name is required!";
        } elseif (empty($fields)) {
            $error_message = "At least one field must be added!";
        } else {
            try {
                // Validate table name (SQLite identifier rules)
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table_name)) {
                    throw new Exception("Invalid table name! Only letters, numbers and underscore allowed.");
                }
                
                // Check if table already exists
                $settings = getSettings();
                $dbType = $settings['db_type'] ?? 'sqlite';
                
                if ($dbType === 'mysql') {
                    $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
                    $existing_tables = $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$escaped_table_name'")->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $existing_tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = " . $db->quote($table_name))->fetchAll(PDO::FETCH_COLUMN);
                }
                
                if (!empty($existing_tables)) {
                    throw new Exception("Table '{$table_name}' already exists!");
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
                        throw new Exception("Invalid field name: '$field_name'");
                    }
                    
                    // Convert BOOLEAN to INTEGER for SQLite
                    // Convert IMAGE to TEXT for SQLite (stores file path)
                    // Convert RELATION to INTEGER (stores foreign key ID)
                    if ($field_type === 'BOOLEAN') {
                        $sql_type = 'INTEGER';
                    } elseif ($field_type === 'IMAGE') {
                        $sql_type = 'TEXT';
                    } elseif ($field_type === 'RELATION') {
                        $sql_type = 'INTEGER';
                    } else {
                        $sql_type = $field_type;
                    }
                    
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
                        $settings = getSettings();
                        $dbType = $settings['db_type'] ?? 'sqlite';
                        if ($field_type === 'INTEGER' && $dbType === 'sqlite') {
                            $column_def .= " PRIMARY KEY AUTOINCREMENT";
                        } elseif ($field_type === 'INTEGER' && $dbType === 'mysql') {
                            $column_def .= " PRIMARY KEY AUTO_INCREMENT";
                        }
                    } elseif (!$is_nullable) {
                        $column_def .= " NOT NULL";
                    }
                    
                    $columns[] = $column_def;
                }
                
                if (empty($columns)) {
                    throw new Exception("At least one valid field must be added!");
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
                
                // Store relation metadata if any RELATION fields exist
                try {
                    // Create relation_metadata table if it doesn't exist
                    $db->exec("CREATE TABLE IF NOT EXISTS relation_metadata (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        table_name TEXT NOT NULL,
                        column_name TEXT NOT NULL,
                        target_table TEXT NOT NULL,
                        UNIQUE(table_name, column_name)
                    )");
                    
                    // Save relation metadata
                    foreach ($fields as $index => $field_id) {
                        if (!isset($field_names[$index])) continue;
                        
                        $field_name = trim($field_names[$index] ?? '');
                        $field_type = $field_types[$index] ?? 'TEXT';
                        $relation_target = trim($field_relation_target[$index] ?? '');
                        
                        if ($field_type === 'RELATION' && !empty($relation_target)) {
                            // Validate target table exists
                            $settings = getSettings();
                            $dbType = $settings['db_type'] ?? 'sqlite';
                            
                            $target_exists = false;
                            if ($dbType === 'mysql') {
                                $escaped_target = preg_replace('/[^a-zA-Z0-9_]/', '', $relation_target);
                                $result = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$escaped_target'")->fetchColumn();
                                $target_exists = ($result > 0);
                            } else {
                                $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = " . $db->quote($relation_target))->fetchAll(PDO::FETCH_COLUMN);
                                $target_exists = !empty($result);
                            }
                            
                            if ($target_exists) {
                                $stmt = $db->prepare("INSERT OR REPLACE INTO relation_metadata (table_name, column_name, target_table) VALUES (?, ?, ?)");
                                $stmt->execute([$table_name, $field_name, $relation_target]);
                            }
                        }
                    }
                } catch (PDOException $e) {
                    // Metadata save failed, but table creation succeeded - log error but continue
                    error_log("Failed to save relation metadata: " . $e->getMessage());
                }
                
                $success_message = "Table '{$table_name}' created successfully!";
                header('Location: database-explorer.php?table=' . urlencode($table_name) . '&success=' . urlencode($success_message));
                exit;
            } catch (PDOException $e) {
                $error_message = "Error creating table: " . $e->getMessage();
            } catch (Exception $e) {
                $error_message = $e->getMessage();
            }
        }
    } else {
        // SQL-based creation (backward compatibility)
        if (empty($table_name)) {
            $error_message = "Table name is required!";
        } elseif (empty($table_sql)) {
            $error_message = "SQL query is required!";
        } else {
            try {
                // Validate table name (SQLite identifier rules)
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table_name)) {
                    throw new Exception("Invalid table name. Only letters, numbers and underscore allowed.");
                }
                
                // Check if table already exists
                $settings = getSettings();
                $dbType = $settings['db_type'] ?? 'sqlite';
                
                if ($dbType === 'mysql') {
                    $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
                    $existing_tables = $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$escaped_table_name'")->fetchAll(PDO::FETCH_COLUMN);
                } else {
                    $existing_tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name = " . $db->quote($table_name))->fetchAll(PDO::FETCH_COLUMN);
                }
                
                if (!empty($existing_tables)) {
                    throw new Exception("Table '{$table_name}' already exists!");
                }
                
                // Execute CREATE TABLE statement
                $db->exec($table_sql);
                $success_message = "Table '{$table_name}' created successfully!";
                
                // Redirect to avoid form resubmission
                header('Location: database-explorer.php?table=' . urlencode($table_name) . '&success=' . urlencode($success_message));
                exit;
            } catch (Exception $e) {
                $error_message = "Error creating table: " . $e->getMessage();
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
                $error_message = "System tables cannot be deleted!";
            } else {
                $db->exec("DROP TABLE IF EXISTS " . $db->quote($delete_table));
                $success_message = "Table '{$delete_table}' deleted successfully!";
                header('Location: database-explorer.php?success=' . urlencode($success_message));
                exit;
            }
        } else {
            $error_message = "Table not found!";
        }
    } catch (PDOException $e) {
        $error_message = "Error deleting table: " . $e->getMessage();
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
                        $success_message = "Query executed successfully! " . count($query_result) . " rows found.";
                    } else {
                        // For non-SELECT queries, show affected rows
                        $affected_rows = $stmt->rowCount();
                        $success_message = "Query executed successfully! Affected rows: " . $affected_rows;
                    }
                } catch (PDOException $e) {
                    $error_message = "Error executing query: " . $e->getMessage();
                }
            }
        } else {
            $error_message = "Query not found or you don't have access permission!";
        }
    } catch (PDOException $e) {
        $error_message = "Error loading query: " . $e->getMessage();
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
                die("Query error: " . $e->getMessage());
            }
        } else {
            die("Query not found for export.");
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
                    die("Invalid table name: " . htmlspecialchars($export_table_name));
                }
            } catch (PDOException $e) {
                die("Error loading data: " . $e->getMessage());
            }
        } else {
            die("Table name not specified for export.");
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
            die("Column information not found for export.");
        }
    } else {
        die("Data not found for export.");
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
    $settings = getSettings();
    $dbType = $settings['db_type'] ?? 'sqlite';
    
    if ($dbType === 'mysql') {
        // MySQL: Get tables from INFORMATION_SCHEMA
        $tables = $db->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // SQLite: Get tables from sqlite_master
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    $tables = [];
    $error_message = "Error loading tables: " . $e->getMessage();
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
            $success_message = "Query executed successfully. Affected rows: " . $affected_rows;
        }
    } catch (PDOException $e) {
        $error_message = "Query error: " . $e->getMessage();
    }
}

// Get table statistics for all tables
$table_stats = [];
$settings = getSettings();
$dbType = $settings['db_type'] ?? 'sqlite';

foreach ($tables as $tbl) {
    try {
        $escaped_tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $tbl);
        $row_count = $db->query("SELECT COUNT(*) FROM `$escaped_tbl`")->fetchColumn();
        
        if ($dbType === 'mysql') {
            $col_count = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$escaped_tbl'")->fetchColumn();
        } else {
            $table_info_temp = $db->query("PRAGMA table_info(\"$escaped_tbl\")")->fetchAll(PDO::FETCH_ASSOC);
            $col_count = count($table_info_temp);
        }
        
        $table_stats[$tbl] = [
            'row_count' => $row_count,
            'col_count' => $col_count
        ];
    } catch (PDOException $e) {
        $table_stats[$tbl] = [
            'row_count' => 0,
            'col_count' => 0
        ];
    }
}

// Get table structure and data
$table_row_count = 0;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = isset($_GET['per_page']) ? max(1, min(500, intval($_GET['per_page']))) : 50;
$sort_col = $_GET['sort'] ?? null;
$sort_order = strtoupper($_GET['order'] ?? 'ASC');
if (!in_array($sort_order, ['ASC', 'DESC'])) {
    $sort_order = 'ASC';
}
$search_term = $_GET['search'] ?? '';

if ($table_name && in_array($table_name, $tables)) {
    $selected_table = $table_name;
    
    try {
        // Get table info (columns)
        $settings = getSettings();
        $dbType = $settings['db_type'] ?? 'sqlite';
        
        if ($dbType === 'mysql') {
            // MySQL: Get column info from INFORMATION_SCHEMA
            $escaped_table_name = preg_replace('/[^a-zA-Z0-9_]/', '', $table_name);
            $table_info = $db->query("
                SELECT 
                    COLUMN_NAME as name,
                    DATA_TYPE as type,
                    IS_NULLABLE as nullable,
                    COLUMN_DEFAULT as dflt_value,
                    COLUMN_KEY as pk_indicator,
                    ORDINAL_POSITION as cid
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$escaped_table_name'
                ORDER BY ORDINAL_POSITION
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            // Normalize MySQL result to match SQLite PRAGMA format
            foreach ($table_info as &$col) {
                $col['type'] = strtoupper($col['type']);
                // Case-insensitive check: IS_NULLABLE 'NO' = NOT NULL = notnull = 1
                $col['notnull'] = (strtoupper(trim($col['nullable'] ?? 'YES')) === 'NO') ? 1 : 0;
                $col['dflt_value'] = $col['dflt_value'];
                // Case-insensitive check: COLUMN_KEY 'PRI' = PRIMARY KEY = pk = 1
                $col['pk'] = (strtoupper(trim($col['pk_indicator'] ?? '')) === 'PRI') ? 1 : 0;
                $table_columns[] = $col['name'];
            }
            unset($col); // Break reference
        } else {
            // SQLite: Use PRAGMA table_info
            $table_info = $db->query("PRAGMA table_info(\"$table_name\")")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($table_info as $col) {
                $table_columns[] = $col['name'];
            }
        }
        
        // Build WHERE clause for search
        $where_clause = '';
        $where_params = [];
        if (!empty($search_term) && !empty($table_columns)) {
            $search_conditions = [];
            foreach ($table_columns as $col) {
                $search_conditions[] = "\"$col\" LIKE ?";
                $where_params[] = '%' . $search_term . '%';
            }
            if (!empty($search_conditions)) {
                $where_clause = 'WHERE ' . implode(' OR ', $search_conditions);
            }
        }
        
        // Get total count
        $count_query = "SELECT COUNT(*) FROM \"$table_name\" $where_clause";
        $count_stmt = $db->prepare($count_query);
        $count_stmt->execute($where_params);
        $table_row_count = $count_stmt->fetchColumn();
        $total_pages = max(1, ceil($table_row_count / $per_page));
        
        // Build ORDER BY clause
        $order_clause = '';
        if ($sort_col && in_array($sort_col, $table_columns)) {
            $order_clause = "ORDER BY \"$sort_col\" $sort_order";
        } else {
            // Default sort by first column
            $order_clause = "ORDER BY \"{$table_columns[0]}\" ASC";
        }
        
        // Get paginated data
        $offset = ($page - 1) * $per_page;
        $limit_clause = "LIMIT $per_page OFFSET $offset";
        $query = "SELECT * FROM \"$table_name\" $where_clause $order_clause $limit_clause";
        $stmt = $db->prepare($query);
        $stmt->execute($where_params);
        $table_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message = "Error loading table data: " . $e->getMessage();
    }
}

// Get database statistics
$total_tables = count($tables);
$total_rows = 0;
foreach ($table_stats as $stats) {
    $total_rows += $stats['row_count'];
}

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-foreground mb-2">Database Explorer</h1>
                    <p class="text-sm text-muted-foreground">Database management and query tool</p>
                </div>
                
                <!-- Database Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="rounded-lg border border-border bg-card text-card-foreground p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-muted-foreground mb-1">Total Tables</p>
                                <p class="text-2xl font-bold text-foreground"><?php echo $total_tables; ?></p>
                            </div>
                            <div class="h-12 w-12 rounded-full bg-blue-100 dark:bg-blue-900/20 flex items-center justify-center">
                                <svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="rounded-lg border border-border bg-card text-card-foreground p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-muted-foreground mb-1">Total Records</p>
                                <p class="text-2xl font-bold text-foreground"><?php echo number_format($total_rows); ?></p>
                            </div>
                            <div class="h-12 w-12 rounded-full bg-green-100 dark:bg-green-900/20 flex items-center justify-center">
                                <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="rounded-lg border border-border bg-card text-card-foreground p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-muted-foreground mb-1">Active Table</p>
                                <p class="text-2xl font-bold text-foreground"><?php echo $selected_table ? '1' : '0'; ?></p>
                            </div>
                            <div class="h-12 w-12 rounded-full bg-purple-100 dark:bg-purple-900/20 flex items-center justify-center">
                                <svg class="h-6 w-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="rounded-lg border border-border bg-card text-card-foreground p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-muted-foreground mb-1">Saved Queries</p>
                                <p class="text-2xl font-bold text-foreground"><?php echo count($saved_queries); ?></p>
                            </div>
                            <div class="h-12 w-12 rounded-full bg-orange-100 dark:bg-orange-900/20 flex items-center justify-center">
                                <svg class="h-6 w-6 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                </svg>
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
                
                <div class="grid gap-6 lg:grid-cols-12">
                    <!-- Tables List -->
                    <div class="lg:col-span-3 transition-all duration-300" id="tables-panel">
                        <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-4 pb-0 flex items-center justify-between">
                                <h3 class="text-lg font-semibold leading-none tracking-tight" id="tables-title">Tables</h3>
                                <button
                                    type="button"
                                    id="toggle-tables"
                                    class="p-1 rounded hover:bg-muted transition-colors"
                                    title="Minimize / Maximize"
                                >
                                    <svg class="h-5 w-5 text-muted-foreground transition-transform" id="toggle-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19V5l7 7-7 7z" />
                                    </svg>
                                </button>
                            </div>
                            <div class="p-4 pt-2" id="tables-content">
                                <div class="mb-3">
                                    <button
                                        type="button"
                                        id="btn-create-table"
                                        class="w-full inline-flex items-center justify-center rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 px-3 py-2 transition-colors mb-2"
                                    >
                                        <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                                        </svg>
                                        New Table
                                    </button>
                                </div>
                                <div class="space-y-2 max-h-[600px] overflow-y-auto">
                                    <?php foreach ($tables as $table): 
                                        $stats = $table_stats[$table] ?? ['row_count' => 0, 'col_count' => 0];
                                    ?>
                                        <div class="group relative">
                                            <a
                                                href="?table=<?php echo htmlspecialchars($table); ?>"
                                                class="flex-1 block p-3 rounded-md border border-border hover:bg-accent hover:border-primary transition-all <?php echo $selected_table === $table ? 'bg-accent border-primary shadow-sm' : ''; ?>"
                                            >
                                                <div class="flex items-start justify-between">
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-2 mb-1">
                                                            <svg class="h-4 w-4 text-primary flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                                                            </svg>
                                                            <span class="font-semibold text-sm truncate"><?php echo htmlspecialchars($table); ?></span>
                                                        </div>
                                                        <div class="flex items-center gap-3 text-xs text-muted-foreground">
                                                            <span class="flex items-center gap-1">
                                                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                                </svg>
                                                                <?php echo number_format($stats['row_count']); ?> records
                                                            </span>
                                                            <span class="flex items-center gap-1">
                                                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                                                                </svg>
                                                                <?php echo $stats['col_count']; ?> columns
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <svg class="h-4 w-4 text-muted-foreground flex-shrink-0 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                                    </svg>
                                                </div>
                                            </a>
                                            <?php if (!in_array($table, ['sqlite_sequence', 'sqlite_master'])): ?>
                                                <button
                                                    onclick="deleteTable('<?php echo htmlspecialchars(addslashes($table)); ?>')"
                                                    class="ml-1 p-1 opacity-0 group-hover:opacity-100 transition-opacity text-red-600 hover:bg-red-50 rounded"
                                                    title="Delete Table"
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
                                <h3 class="text-sm font-semibold leading-none tracking-tight mb-3">Saved Queries</h3>
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
                                                        title="Run"
                                                    >
                                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                    </a>
                                                    <a
                                                        href="?load_query=<?php echo $sq['id']; ?><?php echo $selected_table ? '&table=' . htmlspecialchars($selected_table) : ''; ?>"
                                                        class="p-1.5 rounded hover:bg-primary hover:text-primary-foreground transition-colors"
                                                        title="Load"
                                                    >
                                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
                                                        </svg>
                                                    </a>
                                                    <?php if (!isset($sq['user_id']) || $sq['user_id'] == $_SESSION['user_id'] || $sq['user_id'] === null): ?>
                                                        <a
                                                            href="?delete_query=<?php echo $sq['id']; ?><?php echo $selected_table ? '&table=' . htmlspecialchars($selected_table) : ''; ?>"
                                                            class="p-1.5 rounded hover:bg-red-500 hover:text-white transition-colors"
                                                            title="Delete"
                                                            onclick="return confirm('Are you sure you want to delete this query?');"
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
                                    <p class="text-sm text-muted-foreground">No saved queries yet. You can save your query by writing it and clicking the "Save" button.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Custom Query Section -->
                        <div class="mb-6 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                            <div class="p-6 pb-0">
                                <div class="flex items-center justify-between mb-4">
                                    <h3 class="text-lg font-semibold leading-none tracking-tight">SQL Query</h3>
                                    <?php if (isset($saved_queries) && !empty($saved_queries)): ?>
                                        <div class="flex items-center gap-2">
                                            <select
                                                id="load-saved-query"
                                                class="text-sm px-3 py-1.5 border border-input bg-background text-foreground rounded-md hover:bg-accent transition-colors flex-1"
                                                onchange="if(this.value) { handleSavedQuery(this.value); }"
                                            >
                                                <option value="">Select Saved Query...</option>
                                                <?php foreach ($saved_queries as $sq): ?>
                                                    <option value="<?php echo $sq['id']; ?>" data-action="load"><?php echo htmlspecialchars($sq['name']); ?> (Load)</option>
                                                    <option value="<?php echo $sq['id']; ?>" data-action="run"><?php echo htmlspecialchars($sq['name']); ?> (Run)</option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button
                                                type="button"
                                                onclick="const select = document.getElementById('load-saved-query'); if(select.value) { handleSavedQuery(select.value); }"
                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-green-600 text-white hover:bg-green-700 px-3 py-1.5 transition-colors"
                                                title="Run Selected Query"
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
                                                Execute Query
                                            </button>
                                            <button
                                                type="button"
                                                onclick="showSaveDialog()"
                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-secondary text-secondary-foreground hover:bg-secondary/80 px-4 py-2 transition-colors"
                                                title="Save Query"
                                            >
                                                <svg class="mr-2 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                                                </svg>
                                                Save
                                            </button>
                                            <?php if ($custom_query): ?>
                                                <a
                                                    href="database-explorer.php<?php echo $selected_table ? '?table=' . htmlspecialchars($selected_table) : ''; ?>"
                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-4 py-2 transition-colors"
                                                >
                                                    Clear
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="rounded-md bg-yellow-50 border border-yellow-200 p-3">
                                            <p class="text-xs text-yellow-800">
                                                <strong>Warning:</strong> All SQL queries can be executed. Queries like UPDATE, DELETE, DROP can modify the database. Be careful!
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
                                    <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Table Structure: <?php echo htmlspecialchars($selected_table); ?></h3>
                                </div>
                                <div class="p-4 pt-0">
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-border">
                                            <thead class="bg-muted/50">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-foreground">Column Name</th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium text-foreground">Type</th>
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
                                                        <td class="px-3 py-2 text-xs text-foreground"><?php 
                                                            // Type-safe check: notnull is 1 (integer or string "1") = NOT NULL = "NO"
                                                            // notnull is 0 (integer or string "0") = NULLABLE = "YES"
                                                            $notnull = isset($col['notnull']) ? (int)$col['notnull'] : 0;
                                                            echo $notnull === 1 ? 'NO' : 'YES'; 
                                                        ?></td>
                                                        <td class="px-3 py-2 text-xs text-muted-foreground font-mono"><?php echo $col['dflt_value'] !== null ? htmlspecialchars($col['dflt_value']) : '-'; ?></td>
                                                        <td class="px-3 py-2 text-xs text-foreground"><?php 
                                                            // Type-safe check for primary key
                                                            $pk = isset($col['pk']) ? (int)$col['pk'] : 0;
                                                            echo $pk === 1 ? '✓' : '-'; 
                                                        ?></td>
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
                                                echo 'Query Results';
                                            } else {
                                                echo htmlspecialchars($selected_table) . ' Table';
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
                                                Export to Excel
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="p-6 pt-0">
                                    
                                    <?php if (empty($display_data)): ?>
                                        <div class="text-center py-8 text-muted-foreground">
                                            No data found.
                                        </div>
                                    <?php else: ?>
                                        <div class="overflow-x-auto -mx-6 px-6">
                                            <div class="inline-block min-w-full align-middle">
                                                <table class="min-w-full border-collapse">
                                                    <thead class="sticky top-0 z-10 bg-muted/50 backdrop-blur-sm">
                                                        <tr class="border-b border-border">
                                                            <?php foreach ($display_columns as $col): 
                                                                $is_sorted = ($sort_col === $col);
                                                                $new_order = ($is_sorted && $sort_order === 'ASC') ? 'DESC' : 'ASC';
                                                                $sort_url = '?';
                                                                $params = [];
                                                                if ($selected_table) {
                                                                    $params['table'] = $selected_table;
                                                                }
                                                                if (!empty($search_term)) {
                                                                    $params['search'] = $search_term;
                                                                }
                                                                $params['sort'] = $col;
                                                                $params['order'] = $new_order;
                                                                $params['page'] = 1;
                                                                $sort_url .= http_build_query($params);
                                                            ?>
                                                                <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm cursor-pointer hover:bg-muted/70 transition-colors group">
                                                                    <div class="flex items-center gap-2">
                                                                        <?php if ($selected_table && !$query_result): ?>
                                                                            <a href="<?php echo htmlspecialchars($sort_url); ?>" class="flex items-center gap-1 flex-1">
                                                                                <span><?php echo htmlspecialchars($col); ?></span>
                                                                                <?php if ($is_sorted): ?>
                                                                                    <svg class="h-4 w-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?php echo $sort_order === 'ASC' ? 'M5 15l7-7 7 7' : 'M19 9l-7 7-7-7'; ?>" />
                                                                                    </svg>
                                                                                <?php else: ?>
                                                                                    <svg class="h-4 w-4 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                                                                                    </svg>
                                                                                <?php endif; ?>
                                                                            </a>
                                                                        <?php else: ?>
                                                                            <span><?php echo htmlspecialchars($col); ?></span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </th>
                                                            <?php endforeach; ?>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($display_data as $idx => $row): ?>
                                                            <tr class="border-b border-border hover:bg-muted/30 transition-colors <?php echo $idx % 2 === 0 ? 'bg-background' : 'bg-muted/10'; ?>">
                                                                <?php foreach ($display_columns as $col): ?>
                                                                    <td class="px-4 py-3 text-sm">
                                                                        <?php 
                                                                        $value = $row[$col] ?? null;
                                                                        
                                                                        // Check if column is image field
                                                                        $is_image_col = preg_match('/\b(image|img|photo|picture|resim|foto)\b/i', $col);
                                                                        
                                                                        if ($value === null) {
                                                                            echo '<span class="text-muted-foreground italic">NULL</span>';
                                                                        } elseif ($is_image_col && !empty($value) && file_exists(__DIR__ . '/../' . $value)) {
                                                                            // Display image with thumbnail
                                                                            echo '<div class="flex items-center gap-2">';
                                                                            echo '<img src="../' . htmlspecialchars($value) . '" alt="' . htmlspecialchars($col) . '" class="w-16 h-16 object-cover rounded border border-input" onerror="this.style.display=\'none\'">';
                                                                            echo '<a href="../' . htmlspecialchars($value) . '" target="_blank" class="text-xs text-primary hover:underline">View</a>';
                                                                            echo '</div>';
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
                                        <!-- Pagination -->
                                        <?php if ($selected_table && !$query_result && $total_pages > 1): ?>
                                            <div class="mt-4 flex items-center justify-between border-t border-border pt-4">
                                                <div class="text-sm text-muted-foreground">
                                                    Page <?php echo $page; ?> / <?php echo $total_pages; ?> · 
                                                    Total <?php echo number_format($table_row_count); ?> records · 
                                                    Showing: <?php echo number_format(min($per_page, $table_row_count - ($page - 1) * $per_page)); ?>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <!-- Per Page Selector -->
                                                    <form method="GET" action="" class="flex items-center gap-2">
                                                        <input type="hidden" name="table" value="<?php echo htmlspecialchars($selected_table); ?>">
                                                        <?php if (!empty($search_term)): ?>
                                                            <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_term); ?>">
                                                        <?php endif; ?>
                                                        <?php if ($sort_col): ?>
                                                            <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort_col); ?>">
                                                            <input type="hidden" name="order" value="<?php echo htmlspecialchars($sort_order); ?>">
                                                        <?php endif; ?>
                                                        <label class="text-xs text-muted-foreground">Per page:</label>
                                                        <select name="per_page" onchange="this.form.submit()" class="px-2 py-1 text-xs border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-1 focus:ring-ring">
                                                            <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                                                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                                                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                                                            <option value="250" <?php echo $per_page == 250 ? 'selected' : ''; ?>>250</option>
                                                            <option value="500" <?php echo $per_page == 500 ? 'selected' : ''; ?>>500</option>
                                                        </select>
                                                    </form>
                                                    
                                                    <!-- Page Navigation -->
                                                    <div class="flex items-center gap-1">
                                                        <?php
                                                        $prev_url = '?';
                                                        $next_url = '?';
                                                        $params_prev = ['table' => $selected_table, 'page' => max(1, $page - 1)];
                                                        $params_next = ['table' => $selected_table, 'page' => min($total_pages, $page + 1)];
                                                        if (!empty($search_term)) {
                                                            $params_prev['search'] = $search_term;
                                                            $params_next['search'] = $search_term;
                                                        }
                                                        if ($sort_col) {
                                                            $params_prev['sort'] = $sort_col;
                                                            $params_prev['order'] = $sort_order;
                                                            $params_next['sort'] = $sort_col;
                                                            $params_next['order'] = $sort_order;
                                                        }
                                                        $params_prev['per_page'] = $per_page;
                                                        $params_next['per_page'] = $per_page;
                                                        $prev_url .= http_build_query($params_prev);
                                                        $next_url .= http_build_query($params_next);
                                                        ?>
                                                        
                                                        <a href="<?php echo htmlspecialchars($prev_url); ?>" class="px-3 py-1.5 text-xs font-medium border border-input bg-background text-foreground hover:bg-accent rounded-md transition-colors <?php echo $page <= 1 ? 'opacity-50 cursor-not-allowed pointer-events-none' : ''; ?>">
                                                            Previous
                                                        </a>
                                                        
                                                        <?php
                                                        $start_page = max(1, $page - 2);
                                                        $end_page = min($total_pages, $page + 2);
                                                        
                                                        if ($start_page > 1): ?>
                                                            <a href="?<?php echo http_build_query(array_merge(['table' => $selected_table, 'page' => 1], array_filter(['search' => $search_term, 'sort' => $sort_col, 'order' => $sort_order, 'per_page' => $per_page]))); ?>" class="px-2 py-1.5 text-xs font-medium border border-input bg-background text-foreground hover:bg-accent rounded-md transition-colors">
                                                                1
                                                            </a>
                                                            <?php if ($start_page > 2): ?>
                                                                <span class="px-2 text-xs text-muted-foreground">...</span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                        
                                                        <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                                            <?php
                                                            $page_params = ['table' => $selected_table, 'page' => $i];
                                                            if (!empty($search_term)) $page_params['search'] = $search_term;
                                                            if ($sort_col) {
                                                                $page_params['sort'] = $sort_col;
                                                                $page_params['order'] = $sort_order;
                                                            }
                                                            $page_params['per_page'] = $per_page;
                                                            ?>
                                                            <a href="?<?php echo http_build_query($page_params); ?>" class="px-2 py-1.5 text-xs font-medium border border-input rounded-md transition-colors <?php echo $page == $i ? 'bg-primary text-primary-foreground border-primary' : 'bg-background text-foreground hover:bg-accent'; ?>">
                                                                <?php echo $i; ?>
                                                            </a>
                                                        <?php endfor; ?>
                                                        
                                                        <?php if ($end_page < $total_pages): ?>
                                                            <?php if ($end_page < $total_pages - 1): ?>
                                                                <span class="px-2 text-xs text-muted-foreground">...</span>
                                                            <?php endif; ?>
                                                            <a href="?<?php echo http_build_query(array_merge(['table' => $selected_table, 'page' => $total_pages], array_filter(['search' => $search_term, 'sort' => $sort_col, 'order' => $sort_order, 'per_page' => $per_page]))); ?>" class="px-2 py-1.5 text-xs font-medium border border-input bg-background text-foreground hover:bg-accent rounded-md transition-colors">
                                                                <?php echo $total_pages; ?>
                                                            </a>
                                                        <?php endif; ?>
                                                        
                                                        <a href="<?php echo htmlspecialchars($next_url); ?>" class="px-3 py-1.5 text-xs font-medium border border-input bg-background text-foreground hover:bg-accent rounded-md transition-colors <?php echo $page >= $total_pages ? 'opacity-50 cursor-not-allowed pointer-events-none' : ''; ?>">
                                                            Next
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php elseif ($selected_table && !$query_result): ?>
                                            <div class="mt-4 text-sm text-muted-foreground border-t border-border pt-4">
                                                Total <?php echo number_format($table_row_count); ?> records displayed
                                            </div>
                                        <?php else: ?>
                                            <div class="mt-4 text-sm text-muted-foreground border-t border-border pt-4">
                                                Total <?php echo count($display_data); ?> rows displayed
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                                <div class="p-12 text-center">
                                    <svg class="mx-auto h-12 w-12 text-muted-foreground mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                                    </svg>
                                    <p class="text-muted-foreground">Select a table or run an SQL query.</p>
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
        <h3 class="text-lg font-semibold mb-4">Save Query</h3>
        <form method="POST" action="">
            <input type="hidden" name="action" value="save_query">
            <input type="hidden" name="custom_query" id="save_query_text">
            <div class="space-y-4">
                <div>
                    <label for="saved_query_name" class="block text-sm font-medium mb-2">Query Name:</label>
                    <input
                        type="text"
                        name="saved_query_name"
                        id="saved_query_name"
                        required
                        class="w-full px-3 py-2 border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-2 focus:ring-ring"
                        placeholder="e.g: User List"
                    >
                </div>
                <div class="flex items-center gap-2 justify-end">
                    <button
                        type="button"
                        onclick="hideSaveDialog()"
                        class="px-4 py-2 text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 rounded-md transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="px-4 py-2 text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 rounded-md transition-colors"
                    >
                        Save
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Create Table Modal -->
<div id="create-table-dialog" class="fixed inset-0 hidden items-center justify-center z-50" onclick="if(event.target === this && typeof window.hideCreateTableModal === 'function') window.hideCreateTableModal()" style="background-color: rgba(0, 0, 0, 0.3) !important;">
    <div class="border border-border rounded-lg shadow-lg p-6 max-w-3xl w-full mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()" style="background-color: hsl(var(--background)) !important; z-index: 51;">
        <h3 class="text-lg font-semibold mb-4">Create New Table</h3>
        <form method="POST" action="" id="create-table-form">
            <input type="hidden" name="action" value="create_table">
            <div class="space-y-4">
                <div>
                    <label for="table_name" class="block text-sm font-medium mb-2">Table Name:</label>
                    <input
                        type="text"
                        name="table_name"
                        id="table_name"
                        required
                        pattern="[a-zA-Z_][a-zA-Z0-9_]*"
                        class="w-full px-3 py-2 border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-2 focus:ring-ring font-mono"
                        placeholder="example_table"
                        title="Only letters, numbers and underscore can be used. First character must be a letter or underscore."
                    >
                    <p class="text-xs text-muted-foreground mt-1">Only letters, numbers and underscore can be used</p>
                </div>
                
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <label class="block text-sm font-medium">Fields:</label>
                        <div class="flex gap-2">
                            <button
                                type="button"
                                onclick="addTimestamps()"
                                class="px-3 py-1 text-xs font-medium bg-blue-500 text-white hover:bg-blue-600 rounded-md transition-colors"
                                title="Automatically add created_at and updated_at fields"
                            >
                                + Timestamps
                            </button>
                            <button
                                type="button"
                                onclick="addTableField()"
                                class="px-3 py-1 text-xs font-medium bg-primary text-primary-foreground hover:bg-primary/90 rounded-md transition-colors"
                            >
                                + Add Field
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
                        id="btn-cancel-create-table"
                        class="px-4 py-2 text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 rounded-md transition-colors"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="px-4 py-2 text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 rounded-md transition-colors"
                    >
                        Create
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Create Table Modal functions - Define early so they're available when button is clicked
let fieldCounter = 0;

// Define functions globally immediately
window.showCreateTableModal = function() {
    try {
        console.log('showCreateTableModal called');
        const dialog = document.getElementById('create-table-dialog');
        if (!dialog) {
            console.error('create-table-dialog element not found');
            alert('Modal element not found. Please refresh the page.');
            return;
        }
        
        console.log('Dialog found, showing modal');
        dialog.classList.remove('hidden');
        dialog.classList.add('flex');
        
        // Reset form and add first field
        const tableNameInput = document.getElementById('table_name');
        if (tableNameInput) {
            tableNameInput.value = '';
        }
        
        const container = document.getElementById('table-fields-container');
        if (container) {
            container.innerHTML = '';
            fieldCounter = 0;
            
            if (typeof addTableField === 'function') {
                addTableField(); // Add first empty field
            } else {
                console.error('addTableField function not found');
            }
            
            if (tableNameInput) {
                setTimeout(() => tableNameInput.focus(), 100);
            }
        } else {
            console.error('table-fields-container not found');
        }
    } catch (error) {
        console.error('Error in showCreateTableModal:', error);
        alert('Error opening modal: ' + error.message);
    }
};

window.hideCreateTableModal = function() {
    const dialog = document.getElementById('create-table-dialog');
    if (dialog) {
        dialog.classList.add('hidden');
        dialog.classList.remove('flex');
        // Reset form
        const tableNameInput = document.getElementById('table_name');
        if (tableNameInput) {
            tableNameInput.value = '';
        }
        const container = document.getElementById('table-fields-container');
        if (container) {
            container.innerHTML = '';
            fieldCounter = 0;
        }
    }
}

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
function addTableField(fieldName = '', fieldType = 'TEXT', isNullable = true, isPrimary = false) {
    const container = document.getElementById('table-fields-container');
    const fieldId = 'field_' + (fieldCounter++);
    
    const fieldHtml = `
        <div class="border border-input rounded-md p-3 bg-muted/30" data-field-id="${fieldId}">
            <div class="grid grid-cols-12 gap-2 items-end">
                <div class="col-span-4">
                    <label class="block text-xs font-medium mb-1">Field Name:</label>
                    <input
                        type="text"
                        name="field_names[]"
                        value="${fieldName}"
                        required
                        pattern="[a-zA-Z_][a-zA-Z0-9_]*"
                        class="w-full px-2 py-1.5 text-sm border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-1 focus:ring-ring font-mono"
                        placeholder="field_name"
                    >
                </div>
                <div class="col-span-3">
                    <label class="block text-xs font-medium mb-1">Type:</label>
                    <select
                        name="field_types[]"
                        class="w-full px-2 py-1.5 text-sm border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-1 focus:ring-ring"
                        onchange="updateFieldTypeHint(this)"
                    >
                        <option value="TEXT" ${fieldType === 'TEXT' ? 'selected' : ''}>TEXT</option>
                        <option value="INTEGER" ${fieldType === 'INTEGER' ? 'selected' : ''}>INTEGER</option>
                        <option value="REAL" ${fieldType === 'REAL' ? 'selected' : ''}>REAL</option>
                        <option value="BLOB" ${fieldType === 'BLOB' ? 'selected' : ''}>BLOB</option>
                        <option value="NUMERIC" ${fieldType === 'NUMERIC' ? 'selected' : ''}>NUMERIC</option>
                        <option value="BOOLEAN" ${fieldType === 'BOOLEAN' ? 'selected' : ''}>BOOLEAN</option>
                        <option value="IMAGE" ${fieldType === 'IMAGE' ? 'selected' : ''}>IMAGE (Image - TEXT)</option>
                        <option value="RELATION" ${fieldType === 'RELATION' ? 'selected' : ''}>RELATION (Foreign Key - INTEGER)</option>
                    </select>
                    <span class="field-type-hint text-xs text-muted-foreground mt-1 hidden"></span>
                    <div class="field-relation-target hidden mt-2">
                        <label class="block text-xs font-medium mb-1">Reference Table:</label>
                        <select
                            name="field_relation_target[]"
                            class="w-full px-2 py-1.5 text-sm border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-1 focus:ring-ring"
                        >
                            <option value="">-- Select Table --</option>
                        </select>
                    </div>
                </div>
                <div class="col-span-2">
                    <label class="flex items-center gap-1 text-xs font-medium cursor-pointer">
                        <input
                            type="hidden"
                            name="field_nullable[]"
                            value="0"
                            class="field-nullable-hidden"
                        >
                        <input
                            type="checkbox"
                            name="field_nullable_checkbox[]"
                            value="1"
                            ${isNullable ? 'checked' : ''}
                            class="rounded border-input field-nullable-checkbox"
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
                        title="Delete Field"
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
    
    // Initialize nullable checkbox state for the new field
    const newField = container.querySelector('[data-field-id="' + fieldId + '"]');
    if (newField) {
        const typeSelect = newField.querySelector('select[name="field_types[]"]');
        if (typeSelect) {
            updateFieldTypeHint(typeSelect);
            
            // Initialize relation target dropdown if field type is RELATION
            if (typeSelect.value === 'RELATION') {
                updateFieldTypeHint(typeSelect);
            }
        }
        
        // Initialize nullable hidden input based on checkbox state
        const nullableCheckbox = newField.querySelector('.field-nullable-checkbox');
        const nullableHidden = newField.querySelector('.field-nullable-hidden');
        if (nullableCheckbox && nullableHidden) {
            nullableHidden.value = nullableCheckbox.checked ? '1' : '0';
        }
    }
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
    // Update hidden field for nullable based on checkbox state
    const field = checkbox.closest('[data-field-id]');
    if (field) {
        const hiddenInput = field.querySelector('.field-nullable-hidden');
        if (hiddenInput) {
            // If checkbox is checked, set hidden input to "1" (nullable), else "0" (NOT NULL)
            hiddenInput.value = checkbox.checked ? '1' : '0';
        }
    }
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
    if (confirm('Table "' + tableName + '" will be deleted. This action cannot be undone. Are you sure?')) {
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

// Global variable to store available tables for relation dropdowns
const availableTables = <?php 
    if (isset($tables) && is_array($tables)) {
        echo json_encode($tables, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    } else {
        echo '[]';
    }
?>;

// Update field type hint based on selection
function updateFieldTypeHint(select) {
    const fieldContainer = select.closest('[data-field-id]');
    const hintSpan = fieldContainer ? fieldContainer.querySelector('.field-type-hint') : null;
    const relationTargetDiv = fieldContainer ? fieldContainer.querySelector('.field-relation-target') : null;
    const relationTargetSelect = fieldContainer ? fieldContainer.querySelector('select[name="field_relation_target[]"]') : null;
    const fieldNameInput = fieldContainer ? fieldContainer.querySelector('input[name="field_names[]"]') : null;
    const fieldName = fieldNameInput ? fieldNameInput.value.toLowerCase() : '';
    
    if (hintSpan) {
        const fieldType = select.value;
        
        // Check if field name suggests image
        const isImageField = /(image|img|photo|picture|resim|foto)/i.test(fieldName);
        
        if (fieldType === 'IMAGE') {
            hintSpan.textContent = 'This field will store image path (saved as TEXT)';
            hintSpan.classList.remove('hidden');
            // Hide relation target dropdown
            if (relationTargetDiv) {
                relationTargetDiv.classList.add('hidden');
            }
        } else if (fieldType === 'RELATION') {
            hintSpan.textContent = 'This field will store foreign key ID (saved as INTEGER)';
            hintSpan.classList.remove('hidden');
            
            // Show relation target dropdown
            if (relationTargetDiv && relationTargetSelect) {
                relationTargetDiv.classList.remove('hidden');
                
                // Populate dropdown with available tables
                relationTargetSelect.innerHTML = '<option value="">-- Select Table --</option>';
                availableTables.forEach(function(table) {
                    // Skip system tables and current table if creating new table
                    if (table === 'sqlite_sequence' || table === 'sqlite_master' || table === 'relation_metadata') {
                        return;
                    }
                    const option = document.createElement('option');
                    option.value = table;
                    option.textContent = table;
                    relationTargetSelect.appendChild(option);
                });
            }
        } else if (isImageField && fieldType !== 'IMAGE') {
            hintSpan.textContent = '💡 Tip: This field name looks like an image. You can select IMAGE type!';
            hintSpan.classList.remove('hidden');
            // Hide relation target dropdown
            if (relationTargetDiv) {
                relationTargetDiv.classList.add('hidden');
            }
        } else {
            hintSpan.classList.add('hidden');
            // Hide relation target dropdown for non-RELATION types
            if (relationTargetDiv) {
                relationTargetDiv.classList.add('hidden');
            }
        }
    }
}

// Update all nullable hidden inputs before form submission
function updateAllNullableInputs() {
    const container = document.getElementById('table-fields-container');
    if (container) {
        const fields = container.querySelectorAll('[data-field-id]');
        fields.forEach(function(field) {
            const nullableCheckbox = field.querySelector('.field-nullable-checkbox');
            const nullableHidden = field.querySelector('.field-nullable-hidden');
            if (nullableCheckbox && nullableHidden) {
                nullableHidden.value = nullableCheckbox.checked ? '1' : '0';
            }
        });
    }
}

// Auto-detect image fields when name is entered
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('table-fields-container');
    if (container) {
        container.addEventListener('input', function(e) {
            if (e.target.name === 'field_names[]') {
                const fieldContainer = e.target.closest('[data-field-id]');
                const typeSelect = fieldContainer ? fieldContainer.querySelector('select[name="field_types[]"]') : null;
                if (typeSelect && /(image|img|photo|picture|resim|foto)/i.test(e.target.value)) {
                    // Auto-select IMAGE type
                    typeSelect.value = 'IMAGE';
                    updateFieldTypeHint(typeSelect);
                } else if (typeSelect) {
                    updateFieldTypeHint(typeSelect);
                }
            }
        });
        
        // Update hints on initial load
        container.querySelectorAll('select[name="field_types[]"]').forEach(function(select) {
            updateFieldTypeHint(select);
        });
    }
    
    // Update all nullable inputs before form submission
    const createTableForm = document.getElementById('create-table-form');
    if (createTableForm) {
        createTableForm.addEventListener('submit', function(e) {
            updateAllNullableInputs();
        });
    }
    
    // Add event listener to New Table button
    const newTableBtn = document.getElementById('btn-create-table');
    if (newTableBtn) {
        console.log('New Table button found');
        newTableBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('New Table button clicked');
            if (typeof window.showCreateTableModal === 'function') {
                window.showCreateTableModal();
            } else {
                console.error('showCreateTableModal function not found');
                alert('Modal function not loaded. Please refresh the page.');
            }
        });
    } else {
        console.warn('New Table button not found');
    }
    
    // Add event listener to Cancel button in modal
    const cancelBtn = document.getElementById('btn-cancel-create-table');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof window.hideCreateTableModal === 'function') {
                window.hideCreateTableModal();
            }
        });
    }
    
    // Add event listener to modal backdrop (click outside to close)
    const modalDialog = document.getElementById('create-table-dialog');
    if (modalDialog) {
        modalDialog.addEventListener('click', function(e) {
            if (e.target === modalDialog && typeof window.hideCreateTableModal === 'function') {
                window.hideCreateTableModal();
            }
        });
    }
});
</script>

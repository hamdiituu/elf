<?php
require_once __DIR__ . '/../config/config.php';
requireLogin();

$page_title = 'Pages Builder - Vira Stok Sistemi';
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
                
                if (empty($page_name) || empty($page_title) || empty($table_name)) {
                    $error_message = "Sayfa adı, başlık ve tablo adı gereklidir!";
                } else {
                    // Validate page_name (for filename)
                    if (!preg_match('/^[a-z0-9_-]+$/', strtolower($page_name))) {
                        $error_message = "Sayfa adı sadece küçük harf, rakam, alt çizgi ve tire içerebilir!";
                    } else {
                        try {
                            // Check if table exists
                            $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table_name'");
                            if (!$stmt->fetch()) {
                                $error_message = "Seçilen tablo bulunamadı!";
                            } else {
                                // Check if page_name already exists
                                $stmt = $db->prepare("SELECT id FROM dynamic_pages WHERE page_name = ?");
                                $stmt->execute([$page_name]);
                                if ($stmt->fetch()) {
                                    $error_message = "Bu sayfa adı zaten kullanılıyor!";
                                } else {
                                    // Insert into dynamic_pages
                                    $stmt = $db->prepare("INSERT INTO dynamic_pages (page_name, page_title, table_name, group_name, enable_list, enable_create, enable_update, enable_delete) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                                    $stmt->execute([$page_name, $page_title, $table_name, $group_name ?: null, $enable_list, $enable_create, $enable_update, $enable_delete]);
                                    
                                    // Generate PHP file
                                    if (generateDynamicPage($db, $page_name, $page_title, $table_name, $enable_list, $enable_create, $enable_update, $enable_delete)) {
                                        $success_message = "Sayfa başarıyla oluşturuldu: $page_name";
                                    } else {
                                        $error_message = "Sayfa kaydı oluşturuldu ancak PHP dosyası oluşturulurken hata oluştu!";
                                    }
                                }
                            }
                        } catch (PDOException $e) {
                            $error_message = "Sayfa oluşturulurken hata: " . $e->getMessage();
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
                            $page_name = $page['page_name'];
                            $page_file = __DIR__ . "/$page_name.php";
                            
                            // Delete from database
                            $stmt = $db->prepare("DELETE FROM dynamic_pages WHERE id = ?");
                            $stmt->execute([$page_id]);
                            
                            // Delete PHP file if exists
                            if (file_exists($page_file)) {
                                @unlink($page_file);
                            }
                            
                            $success_message = "Sayfa başarıyla silindi!";
                        }
                    } catch (PDOException $e) {
                        $error_message = "Sayfa silinirken hata: " . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Function to generate dynamic page PHP file
function generateDynamicPage($db, $page_name, $page_title, $table_name, $enable_list, $enable_create, $enable_update, $enable_delete) {
    // Get table structure
    $stmt = $db->query("PRAGMA table_info($table_name)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $column_names = [];
    $primary_key = null;
    foreach ($columns as $col) {
        $column_names[] = $col['name'];
        if ($col['pk']) {
            $primary_key = $col['name'];
        }
    }
    
    if (!$primary_key) {
        $primary_key = 'id'; // Default
    }
    
    // Generate PHP code
    $php_code = "<?php\n";
    $php_code .= "require_once __DIR__ . '/../config/config.php';\n";
    $php_code .= "requireLogin();\n\n";
    $php_code .= "\$page_title = '$page_title - Vira Stok Sistemi';\n";
    $php_code .= "\$db = getDB();\n\n";
    
    // Handle operations
    $php_code .= "// Handle operations\n";
    $php_code .= "if (\$_SERVER['REQUEST_METHOD'] == 'POST') {\n";
    $php_code .= "    if (isset(\$_POST['action'])) {\n";
    $php_code .= "        switch (\$_POST['action']) {\n";
    
    if ($enable_create) {
        $php_code .= "            case 'add':\n";
        $php_code .= "                try {\n";
        $php_code .= "                    \$stmt = \$db->prepare(\"INSERT INTO $table_name (";
        
        $insert_columns = [];
        $insert_placeholders = [];
        foreach ($columns as $col) {
            // Skip auto-increment primary key (if pk = 1 and type is INTEGER)
            if ($col['pk'] == 1 && strtolower($col['type']) === 'integer') {
                continue;
            }
            // Skip timestamps, will use CURRENT_TIMESTAMP
            if (strtolower($col['name']) === 'created_at' || strtolower($col['name']) === 'updated_at') {
                continue;
            }
            $insert_columns[] = $col['name'];
            $insert_placeholders[] = "?";
        }
        
        $php_code .= implode(", ", $insert_columns);
        $php_code .= ") VALUES (" . implode(", ", $insert_placeholders) . ")\");\n";
        $php_code .= "                    \$values = [];\n";
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
            $php_code .= "                    if (isset(\$_POST['$col_name'])) {\n";
            $col_type_lower = strtolower($col['type']);
            if ($col_type_lower === 'integer' || $col_type_lower === 'real') {
                $php_code .= "                        \$values[] = intval(\$_POST['$col_name']);\n";
            } else {
                $php_code .= "                        \$values[] = trim(\$_POST['$col_name']);\n";
            }
            $php_code .= "                    } else {\n";
            if ($col['notnull'] == 1 && $col['dflt_value'] === null) {
                $php_code .= "                        \$values[] = '';\n";
            } else {
                $php_code .= "                        \$values[] = null;\n";
            }
            $php_code .= "                    }\n";
        }
        $php_code .= "                    \$stmt->execute(\$values);\n";
        $php_code .= "                    \$success_message = \"Kayıt başarıyla eklendi!\";\n";
        $php_code .= "                } catch (PDOException \$e) {\n";
        $php_code .= "                    \$error_message = \"Kayıt eklenirken hata: \" . \$e->getMessage();\n";
        $php_code .= "                }\n";
        $php_code .= "                break;\n\n";
    }
    
    if ($enable_update) {
        $php_code .= "            case 'update':\n";
        $php_code .= "                \$record_id = intval(\$_POST['record_id'] ?? 0);\n";
        $php_code .= "                if (\$record_id > 0) {\n";
        $php_code .= "                    try {\n";
        $php_code .= "                        \$set_parts = [];\n";
        $php_code .= "                        \$values = [];\n";
        
        foreach ($columns as $col) {
            if ($col['pk']) continue; // Skip primary key
            if (strtolower($col['name']) === 'created_at') continue; // Skip created_at
            $col_name = $col['name'];
            $php_code .= "                        if (isset(\$_POST['$col_name'])) {\n";
            $php_code .= "                            \$set_parts[] = \"$col_name = ?\";\n";
            if (strtolower($col['type']) === 'integer' || strtolower($col['type']) === 'real') {
                $php_code .= "                            \$values[] = intval(\$_POST['$col_name']);\n";
            } else {
                $php_code .= "                            \$values[] = trim(\$_POST['$col_name']);\n";
            }
            $php_code .= "                        }\n";
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
            $php_code .= "                        \$set_parts[] = \"updated_at = CURRENT_TIMESTAMP\";\n";
        }
        
        $php_code .= "                        \$values[] = \$record_id;\n";
        $php_code .= "                        \$stmt = \$db->prepare(\"UPDATE $table_name SET \" . implode(\", \", \$set_parts) . \" WHERE $primary_key = ?\");\n";
        $php_code .= "                        \$stmt->execute(\$values);\n";
        $php_code .= "                        \$success_message = \"Kayıt başarıyla güncellendi!\";\n";
        $php_code .= "                    } catch (PDOException \$e) {\n";
        $php_code .= "                        \$error_message = \"Kayıt güncellenirken hata: \" . \$e->getMessage();\n";
        $php_code .= "                    }\n";
        $php_code .= "                }\n";
        $php_code .= "                break;\n\n";
    }
    
    if ($enable_delete) {
        $php_code .= "            case 'delete':\n";
        $php_code .= "                \$record_id = intval(\$_POST['record_id'] ?? 0);\n";
        $php_code .= "                if (\$record_id > 0) {\n";
        $php_code .= "                    try {\n";
        $php_code .= "                        \$stmt = \$db->prepare(\"DELETE FROM $table_name WHERE $primary_key = ?\");\n";
        $php_code .= "                        \$stmt->execute([\$record_id]);\n";
        $php_code .= "                        \$success_message = \"Kayıt başarıyla silindi!\";\n";
        $php_code .= "                    } catch (PDOException \$e) {\n";
        $php_code .= "                        \$error_message = \"Kayıt silinirken hata: \" . \$e->getMessage();\n";
        $php_code .= "                    }\n";
        $php_code .= "                }\n";
        $php_code .= "                header('Location: $page_name.php');\n";
        $php_code .= "                exit;\n";
        $php_code .= "                break;\n\n";
    }
    
    $php_code .= "        }\n";
    $php_code .= "    }\n";
    $php_code .= "}\n\n";
    
    // Get filter parameters
    if ($enable_list) {
        $php_code .= "// Get filter parameters\n";
        $php_code .= "\$filters = [];\n";
        $php_code .= "\$where_conditions = [];\n";
        $php_code .= "\$where_values = [];\n\n";
        
        foreach ($columns as $col) {
            $col_name = $col['name'];
            $col_type_lower = strtolower($col['type']);
            
            // Skip primary key and timestamps for basic filters
            if ($col['pk'] == 1) continue;
            if (strtolower($col_name) === 'created_at' || strtolower($col_name) === 'updated_at') continue;
            
            // Add filter for each column
            $php_code .= "// Filter for $col_name\n";
            if ($col_type_lower === 'text') {
                // Text search (LIKE)
                $php_code .= "if (!empty(\$_GET['filter_$col_name'])) {\n";
                $php_code .= "    \$filters['$col_name'] = trim(\$_GET['filter_$col_name']);\n";
                $php_code .= "    \$where_conditions[] = \"$col_name LIKE ?\";\n";
                $php_code .= "    \$where_values[] = '%' . \$filters['$col_name'] . '%';\n";
                $php_code .= "}\n";
            } else if ($col_type_lower === 'integer' || $col_type_lower === 'real') {
                // Exact match for numbers
                $php_code .= "if (isset(\$_GET['filter_$col_name']) && \$_GET['filter_$col_name'] !== '') {\n";
                $php_code .= "    \$filters['$col_name'] = trim(\$_GET['filter_$col_name']);\n";
                $php_code .= "    \$where_conditions[] = \"$col_name = ?\";\n";
                $php_code .= "    \$where_values[] = \$filters['$col_name'];\n";
                $php_code .= "}\n";
                // Range filters
                $php_code .= "if (isset(\$_GET['filter_{$col_name}_min']) && \$_GET['filter_{$col_name}_min'] !== '') {\n";
                $php_code .= "    \$where_conditions[] = \"$col_name >= ?\";\n";
                $php_code .= "    \$where_values[] = intval(\$_GET['filter_{$col_name}_min']);\n";
                $php_code .= "}\n";
                $php_code .= "if (isset(\$_GET['filter_{$col_name}_max']) && \$_GET['filter_{$col_name}_max'] !== '') {\n";
                $php_code .= "    \$where_conditions[] = \"$col_name <= ?\";\n";
                $php_code .= "    \$where_values[] = intval(\$_GET['filter_{$col_name}_max']);\n";
                $php_code .= "}\n";
            }
            $php_code .= "\n";
        }
        
        // Pagination
        $php_code .= "// Pagination\n";
        $php_code .= "\$page = isset(\$_GET['page']) ? max(1, intval(\$_GET['page'])) : 1;\n";
        $php_code .= "\$per_page = isset(\$_GET['per_page']) ? max(1, min(100, intval(\$_GET['per_page']))) : 20;\n";
        $php_code .= "\$offset = (\$page - 1) * \$per_page;\n\n";
        
        // Build WHERE clause
        $php_code .= "// Build WHERE clause\n";
        $php_code .= "\$where_sql = '';\n";
        $php_code .= "if (!empty(\$where_conditions)) {\n";
        $php_code .= "    \$where_sql = 'WHERE ' . implode(' AND ', \$where_conditions);\n";
        $php_code .= "}\n\n";
        
        // Get total count
        $php_code .= "// Get total count\n";
        $php_code .= "\$count_stmt = \$db->prepare(\"SELECT COUNT(*) FROM $table_name \$where_sql\");\n";
        $php_code .= "if (!empty(\$where_values)) {\n";
        $php_code .= "    \$count_stmt->execute(\$where_values);\n";
        $php_code .= "} else {\n";
        $php_code .= "    \$count_stmt->execute();\n";
        $php_code .= "}\n";
        $php_code .= "\$total_records = \$count_stmt->fetchColumn();\n";
        $php_code .= "\$total_pages = ceil(\$total_records / \$per_page);\n\n";
        
        // Sorting
        $php_code .= "// Sorting\n";
        $php_code .= "\$sort_column = \$_GET['sort'] ?? '$primary_key';\n";
        $php_code .= "\$sort_order = strtoupper(\$_GET['order'] ?? 'DESC');\n";
        $php_code .= "if (!in_array(\$sort_order, ['ASC', 'DESC'])) {\n";
        $php_code .= "    \$sort_order = 'DESC';\n";
        $php_code .= "}\n";
        // Validate sort_column against actual columns
        $php_code .= "\$valid_columns = [";
        $valid_cols = [];
        foreach ($columns as $col) {
            $valid_cols[] = "'" . $col['name'] . "'";
        }
        $php_code .= implode(", ", $valid_cols);
        $php_code .= "];\n";
        $php_code .= "if (!in_array(\$sort_column, \$valid_columns)) {\n";
        $php_code .= "    \$sort_column = '$primary_key';\n";
        $php_code .= "}\n\n";
        
        // Get records with pagination
        $php_code .= "// Get records with pagination\n";
        $php_code .= "\$sql = \"SELECT * FROM $table_name \$where_sql ORDER BY \$sort_column \$sort_order LIMIT \$per_page OFFSET \$offset\";\n";
        $php_code .= "\$stmt = \$db->prepare(\$sql);\n";
        $php_code .= "if (!empty(\$where_values)) {\n";
        $php_code .= "    \$stmt->execute(\$where_values);\n";
        $php_code .= "} else {\n";
        $php_code .= "    \$stmt->execute();\n";
        $php_code .= "}\n";
        $php_code .= "\$records = \$stmt->fetchAll(PDO::FETCH_ASSOC);\n\n";
    }
    
    // Get record for editing
    $php_code .= "// Get record for editing\n";
    $php_code .= "\$edit_record = null;\n";
    $php_code .= "\$edit_id = \$_GET['edit'] ?? null;\n";
    $php_code .= "if (\$edit_id && $enable_update) {\n";
    $php_code .= "    \$stmt = \$db->prepare(\"SELECT * FROM $table_name WHERE $primary_key = ?\");\n";
    $php_code .= "    \$stmt->execute([\$edit_id]);\n";
    $php_code .= "    \$edit_record = \$stmt->fetch(PDO::FETCH_ASSOC);\n";
    $php_code .= "}\n\n";
    
    $php_code .= "include '../includes/header.php';\n";
    $php_code .= "?>\n";
    $php_code .= "<div class=\"flex h-screen overflow-hidden\">\n";
    $php_code .= "    <?php include '../includes/admin-sidebar.php'; ?>\n\n";
    $php_code .= "    <main class=\"flex-1 overflow-y-auto\">\n";
    $php_code .= "        <div class=\"py-6\">\n";
    $php_code .= "            <div class=\"mx-auto max-w-7xl px-4 sm:px-6 md:px-8\">\n";
    $php_code .= "                <h1 class=\"text-3xl font-bold text-foreground mb-8\">$page_title</h1>\n\n";
    
    // Messages
    $php_code .= "                <?php if (isset(\$success_message)): ?>\n";
    $php_code .= "                    <div class=\"mb-6 rounded-md bg-green-50 p-4 border border-green-200\">\n";
    $php_code .= "                        <div class=\"text-sm text-green-800\">\n";
    $php_code .= "                            <?php echo htmlspecialchars(\$success_message); ?>\n";
    $php_code .= "                        </div>\n";
    $php_code .= "                    </div>\n";
    $php_code .= "                <?php endif; ?>\n\n";
    
    $php_code .= "                <?php if (isset(\$error_message)): ?>\n";
    $php_code .= "                    <div class=\"mb-6 rounded-md bg-red-50 p-4 border border-red-200\">\n";
    $php_code .= "                        <div class=\"text-sm text-red-800\">\n";
    $php_code .= "                            <?php echo htmlspecialchars(\$error_message); ?>\n";
    $php_code .= "                        </div>\n";
    $php_code .= "                    </div>\n";
    $php_code .= "                <?php endif; ?>\n\n";
    
    // Add/Edit form
    if ($enable_create || $enable_update) {
        $php_code .= "                <!-- Add/Edit Form -->\n";
        $php_code .= "                <div class=\"mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm\">\n";
        $php_code .= "                    <div class=\"p-6 pb-0\">\n";
        $php_code .= "                        <h3 class=\"text-lg font-semibold leading-none tracking-tight mb-4\">\n";
        $php_code .= "                            <?php echo \$edit_record ? 'Kayıt Düzenle' : 'Yeni Kayıt Ekle'; ?>\n";
        $php_code .= "                        </h3>\n";
        $php_code .= "                    </div>\n";
        $php_code .= "                    <div class=\"p-6 pt-0\">\n";
        $php_code .= "                        <form method=\"POST\" action=\"\">\n";
        $php_code .= "                            <input type=\"hidden\" name=\"action\" value=\"<?php echo \$edit_record ? 'update' : 'add'; ?>\">\n";
        $php_code .= "                            <?php if (\$edit_record): ?>\n";
        $php_code .= "                                <input type=\"hidden\" name=\"record_id\" value=\"<?php echo \$edit_record['$primary_key']; ?>\">\n";
        $php_code .= "                            <?php endif; ?>\n\n";
        
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
            $col_label = ucfirst(str_replace('_', ' ', $col_name));
            $col_type = strtolower($col['type']);
            
            $php_code .= "                            <div class=\"mb-4\">\n";
            $php_code .= "                                <label for=\"$col_name\" class=\"block text-sm font-medium text-foreground mb-1.5\">\n";
            $php_code .= "                                    $col_label\n";
            if ($col['notnull'] == 1 && $col['dflt_value'] === null) {
                $php_code .= "                                    <span class=\"text-red-500\">*</span>\n";
            }
            $php_code .= "                                </label>\n";
            
            if ($col_type === 'text' && (strlen($col['dflt_value'] ?? '') > 50 || strpos($col_name, 'description') !== false || strpos($col_name, 'aciklama') !== false)) {
                $php_code .= "                                <textarea\n";
                $php_code .= "                                    id=\"$col_name\"\n";
                $php_code .= "                                    name=\"$col_name\"\n";
                if ($col['notnull'] == 1 && $col['dflt_value'] === null) {
                    $php_code .= "                                    required\n";
                }
                $php_code .= "                                    rows=\"3\"\n";
                $php_code .= "                                    class=\"w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent\"\n";
                $php_code .= "                                    placeholder=\"$col_label giriniz\"\n";
                $php_code .= "                                ><?php echo \$edit_record ? htmlspecialchars(\$edit_record['$col_name']) : ''; ?></textarea>\n";
            } else {
                $input_type = 'text';
                if ($col_type === 'integer' || $col_type === 'real') {
                    $input_type = 'number';
                }
                
                $php_code .= "                                <input\n";
                $php_code .= "                                    type=\"$input_type\"\n";
                $php_code .= "                                    id=\"$col_name\"\n";
                $php_code .= "                                    name=\"$col_name\"\n";
                if ($col['notnull'] == 1 && $col['dflt_value'] === null) {
                    $php_code .= "                                    required\n";
                }
                $php_code .= "                                    value=\"<?php echo \$edit_record ? htmlspecialchars(\$edit_record['$col_name']) : ''; ?>\"\n";
                $php_code .= "                                    class=\"w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent\"\n";
                $php_code .= "                                    placeholder=\"$col_label giriniz\"\n";
                $php_code .= "                                >\n";
            }
            
            $php_code .= "                            </div>\n\n";
        }
        
        $php_code .= "                            <div class=\"flex gap-2\">\n";
        $php_code .= "                                <button\n";
        $php_code .= "                                    type=\"submit\"\n";
        $php_code .= "                                    class=\"rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all\"\n";
        $php_code .= "                                >\n";
        $php_code .= "                                    <?php echo \$edit_record ? 'Güncelle' : 'Ekle'; ?>\n";
        $php_code .= "                                </button>\n";
        $php_code .= "                                <?php if (\$edit_record): ?>\n";
        $php_code .= "                                    <a\n";
        $php_code .= "                                        href=\"$page_name.php\"\n";
        $php_code .= "                                        class=\"rounded-md bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-500 transition-all\"\n";
        $php_code .= "                                    >\n";
        $php_code .= "                                        İptal\n";
        $php_code .= "                                    </a>\n";
        $php_code .= "                                <?php endif; ?>\n";
        $php_code .= "                            </div>\n";
        $php_code .= "                        </form>\n";
        $php_code .= "                    </div>\n";
        $php_code .= "                </div>\n\n";
    }
    
    // List table
    if ($enable_list) {
        $php_code .= "                <!-- Filter Form -->\n";
        $php_code .= "                <div class=\"mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm\">\n";
        $php_code .= "                    <div class=\"p-6 pb-0\">\n";
        $php_code .= "                        <h3 class=\"text-lg font-semibold leading-none tracking-tight mb-4\">Filtreleme</h3>\n";
        $php_code .= "                    </div>\n";
        $php_code .= "                    <div class=\"p-6 pt-0\">\n";
        $php_code .= "                        <form method=\"GET\" action=\"\" class=\"space-y-4\">\n";
        $php_code .= "                            <?php if (isset(\$_GET['edit'])): ?>\n";
        $php_code .= "                                <input type=\"hidden\" name=\"edit\" value=\"<?php echo htmlspecialchars(\$_GET['edit']); ?>\">\n";
        $php_code .= "                            <?php endif; ?>\n\n";
        $php_code .= "                            <div class=\"grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4\">\n";
        
        // Generate filter fields for each column
        foreach ($columns as $col) {
            $col_name = $col['name'];
            $col_label = ucfirst(str_replace('_', ' ', $col_name));
            $col_type_lower = strtolower($col['type']);
            
            // Skip primary key and timestamps
            if ($col['pk'] == 1) continue;
            if (strtolower($col_name) === 'created_at' || strtolower($col_name) === 'updated_at') continue;
            
            if ($col_type_lower === 'text') {
                $php_code .= "                                <div>\n";
                $php_code .= "                                    <label for=\"filter_$col_name\" class=\"block text-sm font-medium text-foreground mb-1.5\">$col_label</label>\n";
                $php_code .= "                                    <input\n";
                $php_code .= "                                        type=\"text\"\n";
                $php_code .= "                                        id=\"filter_$col_name\"\n";
                $php_code .= "                                        name=\"filter_$col_name\"\n";
                $php_code .= "                                        value=\"<?php echo htmlspecialchars(\$_GET['filter_$col_name'] ?? ''); ?>\"\n";
                $php_code .= "                                        placeholder=\"$col_label ara...\"\n";
                $php_code .= "                                        class=\"w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent\"\n";
                $php_code .= "                                    >\n";
                $php_code .= "                                </div>\n";
            } else if ($col_type_lower === 'integer' || $col_type_lower === 'real') {
                $php_code .= "                                <div>\n";
                $php_code .= "                                    <label for=\"filter_$col_name\" class=\"block text-sm font-medium text-foreground mb-1.5\">$col_label (Eşit)</label>\n";
                $php_code .= "                                    <input\n";
                $php_code .= "                                        type=\"number\"\n";
                $php_code .= "                                        id=\"filter_$col_name\"\n";
                $php_code .= "                                        name=\"filter_$col_name\"\n";
                $php_code .= "                                        value=\"<?php echo htmlspecialchars(\$_GET['filter_$col_name'] ?? ''); ?>\"\n";
                $php_code .= "                                        placeholder=\"$col_label\"\n";
                $php_code .= "                                        class=\"w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent\"\n";
                $php_code .= "                                    >\n";
                $php_code .= "                                </div>\n";
                $php_code .= "                                <div>\n";
                $php_code .= "                                    <label for=\"filter_{$col_name}_min\" class=\"block text-sm font-medium text-foreground mb-1.5\">$col_label (Min)</label>\n";
                $php_code .= "                                    <input\n";
                $php_code .= "                                        type=\"number\"\n";
                $php_code .= "                                        id=\"filter_{$col_name}_min\"\n";
                $php_code .= "                                        name=\"filter_{$col_name}_min\"\n";
                $php_code .= "                                        value=\"<?php echo htmlspecialchars(\$_GET['filter_{$col_name}_min'] ?? ''); ?>\"\n";
                $php_code .= "                                        placeholder=\"Min\"\n";
                $php_code .= "                                        class=\"w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent\"\n";
                $php_code .= "                                    >\n";
                $php_code .= "                                </div>\n";
                $php_code .= "                                <div>\n";
                $php_code .= "                                    <label for=\"filter_{$col_name}_max\" class=\"block text-sm font-medium text-foreground mb-1.5\">$col_label (Max)</label>\n";
                $php_code .= "                                    <input\n";
                $php_code .= "                                        type=\"number\"\n";
                $php_code .= "                                        id=\"filter_{$col_name}_max\"\n";
                $php_code .= "                                        name=\"filter_{$col_name}_max\"\n";
                $php_code .= "                                        value=\"<?php echo htmlspecialchars(\$_GET['filter_{$col_name}_max'] ?? ''); ?>\"\n";
                $php_code .= "                                        placeholder=\"Max\"\n";
                $php_code .= "                                        class=\"w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent\"\n";
                $php_code .= "                                    >\n";
                $php_code .= "                                </div>\n";
            }
        }
        
        // Per page selector
        $php_code .= "                                <div>\n";
        $php_code .= "                                    <label for=\"per_page\" class=\"block text-sm font-medium text-foreground mb-1.5\">Sayfa Başına Kayıt</label>\n";
        $php_code .= "                                    <select\n";
        $php_code .= "                                        id=\"per_page\"\n";
        $php_code .= "                                        name=\"per_page\"\n";
        $php_code .= "                                        class=\"w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent\"\n";
        $php_code .= "                                        onchange=\"this.form.submit()\"\n";
        $php_code .= "                                    >\n";
        $php_code .= "                                        <option value=\"10\" <?php echo (\$per_page == 10) ? 'selected' : ''; ?>>10</option>\n";
        $php_code .= "                                        <option value=\"20\" <?php echo (\$per_page == 20) ? 'selected' : ''; ?>>20</option>\n";
        $php_code .= "                                        <option value=\"50\" <?php echo (\$per_page == 50) ? 'selected' : ''; ?>>50</option>\n";
        $php_code .= "                                        <option value=\"100\" <?php echo (\$per_page == 100) ? 'selected' : ''; ?>>100</option>\n";
        $php_code .= "                                    </select>\n";
        $php_code .= "                                </div>\n";
        
        $php_code .= "                            </div>\n\n";
        $php_code .= "                            <div class=\"flex gap-2 pt-2\">\n";
        $php_code .= "                                <button\n";
        $php_code .= "                                    type=\"submit\"\n";
        $php_code .= "                                    class=\"rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all\"\n";
        $php_code .= "                                >\n";
        $php_code .= "                                    Filtrele\n";
        $php_code .= "                                </button>\n";
        $php_code .= "                                <a\n";
        $php_code .= "                                    href=\"$page_name.php\"\n";
        $php_code .= "                                    class=\"rounded-md bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-500 transition-all\"\n";
        $php_code .= "                                >\n";
        $php_code .= "                                    Temizle\n";
        $php_code .= "                                </a>\n";
        $php_code .= "                            </div>\n";
        $php_code .= "                        </form>\n";
        $php_code .= "                    </div>\n";
        $php_code .= "                </div>\n\n";
        
        $php_code .= "                <!-- Records List -->\n";
        $php_code .= "                <div class=\"mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm\">\n";
        $php_code .= "                    <div class=\"p-6 pb-0\">\n";
        $php_code .= "                        <div class=\"flex items-center justify-between mb-4\">\n";
        $php_code .= "                            <h3 class=\"text-lg font-semibold leading-none tracking-tight\">Tüm Kayıtlar</h3>\n";
        $php_code .= "                            <div class=\"text-sm text-muted-foreground\">\n";
        $php_code .= "                                Toplam: <span class=\"font-medium text-foreground\"><?php echo \$total_records; ?></span> kayıt\n";
        $php_code .= "                            </div>\n";
        $php_code .= "                        </div>\n";
        $php_code .= "                    </div>\n";
        $php_code .= "                    <div class=\"p-6 pt-0\">\n";
        $php_code .= "                        <?php if (empty(\$records)): ?>\n";
        $php_code .= "                            <div class=\"text-center py-8 text-muted-foreground\">\n";
        $php_code .= "                                Henüz kayıt eklenmemiş veya filtre sonucu bulunamadı.\n";
        $php_code .= "                            </div>\n";
        $php_code .= "                        <?php else: ?>\n";
        $php_code .= "                            <div class=\"overflow-x-auto\">\n";
        $php_code .= "                                <table class=\"w-full border-collapse\">\n";
        $php_code .= "                                    <thead>\n";
        $php_code .= "                                        <tr class=\"border-b border-border\">\n";
        
        foreach ($columns as $col) {
            $col_name = $col['name'];
            $col_label = ucfirst(str_replace('_', ' ', $col_name));
            
            // Get current sort parameters
            $php_code .= "                                            <th class=\"h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm\">\n";
            $php_code .= "                                                <a href=\"?";
            $php_code .= "<?php\n";
            $php_code .= "                                                    \$params = \$_GET;\n";
            $php_code .= "                                                    \$params['sort'] = '$col_name';\n";
            $php_code .= "                                                    if (isset(\$params['sort']) && \$params['sort'] == '$col_name' && isset(\$params['order']) && \$params['order'] == 'ASC') {\n";
            $php_code .= "                                                        \$params['order'] = 'DESC';\n";
            $php_code .= "                                                    } else {\n";
            $php_code .= "                                                        \$params['order'] = 'ASC';\n";
            $php_code .= "                                                    }\n";
            $php_code .= "                                                    echo http_build_query(\$params);\n";
            $php_code .= "                                                ?>\n";
            $php_code .= "\" class=\"flex items-center gap-1 hover:text-foreground\">\n";
            $php_code .= "                                                    <span>$col_label</span>\n";
            $php_code .= "                                                    <?php if (\$sort_column == '$col_name'): ?>\n";
            $php_code .= "                                                        <span class=\"text-xs\"><?php echo \$sort_order == 'ASC' ? '↑' : '↓'; ?></span>\n";
            $php_code .= "                                                    <?php endif; ?>\n";
            $php_code .= "                                                </a>\n";
            $php_code .= "                                            </th>\n";
        }
        
        if ($enable_update || $enable_delete) {
            $php_code .= "                                            <th class=\"h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm\">İşlemler</th>\n";
        }
        
        $php_code .= "                                        </tr>\n";
        $php_code .= "                                    </thead>\n";
        $php_code .= "                                    <tbody>\n";
        $php_code .= "                                        <?php foreach (\$records as \$record): ?>\n";
        $php_code .= "                                            <tr class=\"border-b border-border transition-colors hover:bg-muted/50\">\n";
        
        foreach ($columns as $col) {
            $col_name = $col['name'];
            $php_code .= "                                                <td class=\"p-4 align-middle text-sm\"><?php echo htmlspecialchars(\$record['$col_name'] ?? ''); ?></td>\n";
        }
        
        if ($enable_update || $enable_delete) {
            $php_code .= "                                                <td class=\"p-4 align-middle\">\n";
            $php_code .= "                                                    <div class=\"flex gap-2\">\n";
            
            if ($enable_update) {
                $php_code .= "                                                        <a\n";
                $php_code .= "                                                            href=\"?edit=<?php echo \$record['$primary_key']; ?>\"\n";
                $php_code .= "                                                            class=\"inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-blue-100 text-blue-800 hover:bg-blue-200 h-9 px-3\"\n";
                $php_code .= "                                                        >\n";
                $php_code .= "                                                            Düzenle\n";
                $php_code .= "                                                        </a>\n";
            }
            
            if ($enable_delete) {
                $php_code .= "                                                        <form method=\"POST\" action=\"\" class=\"inline\" onsubmit=\"return confirm('Bu kaydı silmek istediğinizden emin misiniz?');\">\n";
                $php_code .= "                                                            <input type=\"hidden\" name=\"action\" value=\"delete\">\n";
                $php_code .= "                                                            <input type=\"hidden\" name=\"record_id\" value=\"<?php echo \$record['$primary_key']; ?>\">\n";
                $php_code .= "                                                            <button\n";
                $php_code .= "                                                                type=\"submit\"\n";
                $php_code .= "                                                                class=\"inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-red-100 text-red-800 hover:bg-red-200 h-9 px-3\"\n";
                $php_code .= "                                                            >\n";
                $php_code .= "                                                                Sil\n";
                $php_code .= "                                                            </button>\n";
                $php_code .= "                                                        </form>\n";
            }
            
            $php_code .= "                                                    </div>\n";
            $php_code .= "                                                </td>\n";
        }
        
        $php_code .= "                                            </tr>\n";
        $php_code .= "                                        <?php endforeach; ?>\n";
        $php_code .= "                                    </tbody>\n";
        $php_code .= "                                </table>\n";
        $php_code .= "                            </div>\n\n";
        
        // Pagination
        $php_code .= "                            <!-- Pagination -->\n";
        $php_code .= "                            <?php if (\$total_pages > 1): ?>\n";
        $php_code .= "                                <div class=\"mt-6 flex items-center justify-between border-t border-border pt-4\">\n";
        $php_code .= "                                    <div class=\"text-sm text-muted-foreground\">\n";
        $php_code .= "                                        Sayfa <span class=\"font-medium text-foreground\"><?php echo \$page; ?></span> / <span class=\"font-medium text-foreground\"><?php echo \$total_pages; ?></span>\n";
        $php_code .= "                                        (Gösterilen: <?php echo min(\$offset + 1, \$total_records); ?> - <?php echo min(\$offset + \$per_page, \$total_records); ?> / <?php echo \$total_records; ?>)\n";
        $php_code .= "                                    </div>\n";
        $php_code .= "                                    <div class=\"flex gap-2\">\n";
        
        // Previous button
        $php_code .= "                                        <?php if (\$page > 1): ?>\n";
        $php_code .= "                                            <a\n";
        $php_code .= "                                                href=\"?";
        $php_code .= "<?php\n";
        $php_code .= "                                                    \$params = \$_GET;\n";
        $php_code .= "                                                    \$params['page'] = \$page - 1;\n";
        $php_code .= "                                                    echo http_build_query(\$params);\n";
        $php_code .= "                                                ?>\n";
        $php_code .= "\"\n";
        $php_code .= "                                                class=\"inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-foreground hover:bg-accent hover:text-accent-foreground transition-colors\"\n";
        $php_code .= "                                            >\n";
        $php_code .= "                                                Önceki\n";
        $php_code .= "                                            </a>\n";
        $php_code .= "                                        <?php else: ?>\n";
        $php_code .= "                                            <span class=\"inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-muted-foreground opacity-50 cursor-not-allowed\">\n";
        $php_code .= "                                                Önceki\n";
        $php_code .= "                                            </span>\n";
        $php_code .= "                                        <?php endif; ?>\n\n";
        
        // Page numbers
        $php_code .= "                                        <?php\n";
        $php_code .= "                                            \$start_page = max(1, \$page - 2);\n";
        $php_code .= "                                            \$end_page = min(\$total_pages, \$page + 2);\n";
        $php_code .= "                                            for (\$i = \$start_page; \$i <= \$end_page; \$i++):\n";
        $php_code .= "                                        ?>\n";
        $php_code .= "                                            <a\n";
        $php_code .= "                                                href=\"?";
        $php_code .= "<?php\n";
        $php_code .= "                                                    \$params = \$_GET;\n";
        $php_code .= "                                                    \$params['page'] = \$i;\n";
        $php_code .= "                                                    echo http_build_query(\$params);\n";
        $php_code .= "                                                ?>\n";
        $php_code .= "\"\n";
        $php_code .= "                                                class=\"inline-flex items-center justify-center rounded-md border <?php echo \$i == \$page ? 'border-primary bg-primary text-primary-foreground' : 'border-input bg-background text-foreground hover:bg-accent'; ?> px-3 py-2 text-sm font-medium transition-colors\"\n";
        $php_code .= "                                            >\n";
        $php_code .= "                                                <?php echo \$i; ?>\n";
        $php_code .= "                                            </a>\n";
        $php_code .= "                                        <?php endfor; ?>\n\n";
        
        // Next button
        $php_code .= "                                        <?php if (\$page < \$total_pages): ?>\n";
        $php_code .= "                                            <a\n";
        $php_code .= "                                                href=\"?";
        $php_code .= "<?php\n";
        $php_code .= "                                                    \$params = \$_GET;\n";
        $php_code .= "                                                    \$params['page'] = \$page + 1;\n";
        $php_code .= "                                                    echo http_build_query(\$params);\n";
        $php_code .= "                                                ?>\n";
        $php_code .= "\"\n";
        $php_code .= "                                                class=\"inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-foreground hover:bg-accent hover:text-accent-foreground transition-colors\"\n";
        $php_code .= "                                            >\n";
        $php_code .= "                                                Sonraki\n";
        $php_code .= "                                            </a>\n";
        $php_code .= "                                        <?php else: ?>\n";
        $php_code .= "                                            <span class=\"inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-muted-foreground opacity-50 cursor-not-allowed\">\n";
        $php_code .= "                                                Sonraki\n";
        $php_code .= "                                            </span>\n";
        $php_code .= "                                        <?php endif; ?>\n";
        
        $php_code .= "                                    </div>\n";
        $php_code .= "                                </div>\n";
        $php_code .= "                            <?php endif; ?>\n";
        
        $php_code .= "                        <?php endif; ?>\n";
        $php_code .= "                    </div>\n";
        $php_code .= "                </div>\n";
    }
    
    $php_code .= "            </div>\n";
    $php_code .= "        </div>\n";
    $php_code .= "    </main>\n";
    $php_code .= "</div>\n";
    $php_code .= "<?php include '../includes/footer.php'; ?>\n";
    
    // Write to file
    $page_file = __DIR__ . "/$page_name.php";
    return file_put_contents($page_file, $php_code) !== false;
}

// Get all tables
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Get all dynamic pages
$dynamic_pages = $db->query("SELECT * FROM dynamic_pages ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
?>

<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Pages Builder</h1>
                
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
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Yeni Sayfa Oluştur</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <form method="POST" action="">
                            <input type="hidden" name="action" value="create_page">
                            
                            <div class="mb-4">
                                <label for="page_name" class="block text-sm font-medium text-foreground mb-1.5">
                                    Sayfa Adı (URL) <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="page_name"
                                    name="page_name"
                                    required
                                    pattern="[a-z0-9_-]+"
                                    title="Sadece küçük harf, rakam, alt çizgi ve tire kullanılabilir"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="ornek-sayfa"
                                >
                                <p class="mt-1 text-xs text-muted-foreground">Sadece küçük harf, rakam, alt çizgi (_) ve tire (-) kullanılabilir</p>
                            </div>
                            
                            <div class="mb-4">
                                <label for="page_title" class="block text-sm font-medium text-foreground mb-1.5">
                                    Sayfa Başlığı <span class="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    id="page_title"
                                    name="page_title"
                                    required
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Örnek Sayfa"
                                >
                            </div>
                            
                            <div class="mb-4">
                                <label for="table_name" class="block text-sm font-medium text-foreground mb-1.5">
                                    Tablo Seçimi <span class="text-red-500">*</span>
                                </label>
                                <select
                                    id="table_name"
                                    name="table_name"
                                    required
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                >
                                    <option value="">-- Tablo Seçin --</option>
                                    <?php foreach ($tables as $table): ?>
                                        <option value="<?php echo htmlspecialchars($table); ?>">
                                            <?php echo htmlspecialchars($table); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-4">
                                <label for="group_name" class="block text-sm font-medium text-foreground mb-1.5">
                                    Grup Adı
                                </label>
                                <input
                                    type="text"
                                    id="group_name"
                                    name="group_name"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Örn: Sipariş Yönetimi"
                                >
                                <p class="mt-1 text-xs text-muted-foreground">Sayfa bu grup altında sidebar'da listelenecek. Boş bırakılırsa herhangi bir grup altında gösterilmez.</p>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-foreground mb-2">
                                    Aktif İşlemler
                                </label>
                                <div class="space-y-2">
                                    <label class="flex items-center">
                                        <input
                                            type="checkbox"
                                            name="enable_list"
                                            value="1"
                                            checked
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <span class="ml-2 text-sm text-foreground">Listeleme (List)</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input
                                            type="checkbox"
                                            name="enable_create"
                                            value="1"
                                            checked
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <span class="ml-2 text-sm text-foreground">Oluşturma (Create)</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input
                                            type="checkbox"
                                            name="enable_update"
                                            value="1"
                                            checked
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <span class="ml-2 text-sm text-foreground">Güncelleme (Update)</span>
                                    </label>
                                    <label class="flex items-center">
                                        <input
                                            type="checkbox"
                                            name="enable_delete"
                                            value="1"
                                            checked
                                            class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                        >
                                        <span class="ml-2 text-sm text-foreground">Silme (Delete)</span>
                                    </label>
                                </div>
                            </div>
                            
                            <button
                                type="submit"
                                class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                            >
                                Sayfa Oluştur
                            </button>
                        </form>
                    </div>
                </div>
                
                <!-- Existing Dynamic Pages -->
                <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                    <div class="p-6 pb-0">
                        <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Oluşturulmuş Sayfalar</h3>
                    </div>
                    <div class="p-6 pt-0">
                        <?php if (empty($dynamic_pages)): ?>
                            <div class="text-center py-8 text-muted-foreground">
                                Henüz sayfa oluşturulmamış.
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full border-collapse">
                                    <thead>
                                        <tr class="border-b border-border">
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Sayfa Adı</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Sayfa Başlığı</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Grup</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Tablo</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">İşlemler</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Oluşturulma</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Aksiyon</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dynamic_pages as $page): ?>
                                            <tr class="border-b border-border transition-colors hover:bg-muted/50">
                                                <td class="p-4 align-middle text-sm font-medium">
                                                    <a href="<?php echo htmlspecialchars($page['page_name']); ?>.php" class="text-primary hover:underline">
                                                        <?php echo htmlspecialchars($page['page_name']); ?>.php
                                                    </a>
                                                </td>
                                                <td class="p-4 align-middle text-sm"><?php echo htmlspecialchars($page['page_title']); ?></td>
                                                <td class="p-4 align-middle text-sm">
                                                    <?php if (!empty($page['group_name'])): ?>
                                                        <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                                                            <?php echo htmlspecialchars($page['group_name']); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted-foreground">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground"><?php echo htmlspecialchars($page['table_name']); ?></td>
                                                <td class="p-4 align-middle">
                                                    <div class="flex flex-wrap gap-1">
                                                        <?php if ($page['enable_list']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">List</span>
                                                        <?php endif; ?>
                                                        <?php if ($page['enable_create']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Create</span>
                                                        <?php endif; ?>
                                                        <?php if ($page['enable_update']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-medium text-yellow-800">Update</span>
                                                        <?php endif; ?>
                                                        <?php if ($page['enable_delete']): ?>
                                                            <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">Delete</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="p-4 align-middle text-sm text-muted-foreground">
                                                    <?php echo date('d.m.Y H:i', strtotime($page['created_at'])); ?>
                                                </td>
                                                <td class="p-4 align-middle">
                                                    <form method="POST" action="" class="inline" onsubmit="return confirm('Bu sayfayı silmek istediğinizden emin misiniz?');">
                                                        <input type="hidden" name="action" value="delete_page">
                                                        <input type="hidden" name="page_id" value="<?php echo $page['id']; ?>">
                                                        <button
                                                            type="submit"
                                                            class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-red-100 text-red-800 hover:bg-red-200 h-9 px-3"
                                                        >
                                                            Sil
                                                        </button>
                                                    </form>
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
<?php include '../includes/footer.php'; ?>

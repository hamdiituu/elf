<?php
// Dynamic page renderer helper
// This function renders the UI for dynamic pages based on configuration

function renderDynamicPage($db, $page_config, $columns, $primary_key, $enable_list, $enable_create, $enable_update, $enable_delete, $edit_record, $records, $total_records, $total_pages, $current_page_num, $per_page, $offset, $sort_column, $sort_order, $page_name, $relation_metadata = []) {
    // Add/Edit form
    if ($enable_create || $enable_update) {
        $is_edit_mode = !empty($edit_record);
        ?>
        <!-- Add/Edit Form -->
        <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
            <button
                type="button"
                onclick="toggleCollapse('create-form-collapse')"
                class="w-full p-6 pb-0 text-left flex items-center justify-between hover:bg-muted/50 transition-colors rounded-t-lg"
            >
                <h3 class="text-lg font-semibold leading-none tracking-tight mb-5">
                    <?php echo $edit_record ? 'Edit Record' : 'Add New Record'; ?>
                </h3>
                <svg
                    id="create-form-chevron"
                    class="h-5 w-5 text-muted-foreground transition-transform <?php echo $is_edit_mode ? '' : 'rotate-180'; ?>"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
            <div
                id="create-form-collapse"
                class="overflow-hidden transition-all duration-300 <?php echo $is_edit_mode ? 'max-h-[9999px]' : 'max-h-0'; ?>"
            >
                <div class="p-6 pt-0">
                <?php
                // Check if form has image fields (need multipart/form-data)
                $has_image_fields = false;
                foreach ($columns as $col) {
                    $col_name = $col['name'];
                    if (preg_match('/\b(image|img|photo|picture|resim|foto)\b/i', $col_name)) {
                        $has_image_fields = true;
                        break;
                    }
                }
                ?>
                <form method="POST" action="" <?php echo $has_image_fields ? 'enctype="multipart/form-data"' : ''; ?>>
                    <input type="hidden" name="action" value="<?php echo $edit_record ? 'update' : 'add'; ?>">
                    <?php if ($edit_record): ?>
                        <input type="hidden" name="record_id" value="<?php echo $edit_record[$primary_key]; ?>">
                    <?php endif; ?>

                    <?php
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
                        
                        // Check if this column has a relation
                        $is_relation = isset($relation_metadata[$col_name]) && !empty($relation_metadata[$col_name]);
                        $relation_target_table = $is_relation ? $relation_metadata[$col_name] : null;
                        ?>
                        <div class="mb-4">
                            <label for="<?php echo $col_name; ?>" class="block text-sm font-medium text-foreground mb-1.5">
                                <?php echo $col_label; ?>
                                <?php if ($col['notnull'] == 1 && $col['dflt_value'] === null): ?>
                                    <span class="text-red-500">*</span>
                                <?php endif; ?>
                            </label>
                            
                            <?php 
                            // Check if field is relation (foreign key)
                            if ($is_relation && $relation_target_table):
                                // Get relation target table records
                                $relation_records = [];
                                $relation_display_column = 'name'; // Default display column
                                try {
                                    // Try to find a display column (name, title, label, etc.)
                                    $escaped_target = preg_replace('/[^a-zA-Z0-9_]/', '', $relation_target_table);
                                    $target_stmt = $db->query("PRAGMA table_info(\"$escaped_target\")");
                                    $target_columns = $target_stmt->fetchAll(PDO::FETCH_ASSOC);
                                    
                                    // Find suitable display column
                                    $display_candidates = ['name', 'title', 'label', 'title_en', 'name_en'];
                                    foreach ($display_candidates as $candidate) {
                                        foreach ($target_columns as $target_col) {
                                            if (strtolower($target_col['name']) === $candidate) {
                                                $relation_display_column = $target_col['name'];
                                                break 2;
                                            }
                                        }
                                    }
                                    
                                    // Get primary key of target table
                                    $target_pk = 'id';
                                    foreach ($target_columns as $target_col) {
                                        if ($target_col['pk']) {
                                            $target_pk = $target_col['name'];
                                            break;
                                        }
                                    }
                                    
                                    // Fetch all records from target table
                                    $escaped_display = preg_replace('/[^a-zA-Z0-9_]/', '', $relation_display_column);
                                    $escaped_pk = preg_replace('/[^a-zA-Z0-9_]/', '', $target_pk);
                                    $relation_query = $db->query("SELECT `$escaped_pk`, `$escaped_display` FROM `$escaped_target` ORDER BY `$escaped_display`");
                                    $relation_records = $relation_query->fetchAll(PDO::FETCH_ASSOC);
                                } catch (PDOException $e) {
                                    // Target table might not exist, show empty dropdown
                                    error_log("Failed to load relation records: " . $e->getMessage());
                                }
                                ?>
                                <select
                                    id="<?php echo $col_name; ?>"
                                    name="<?php echo $col_name; ?>"
                                    <?php if ($col['notnull'] == 1 && $col['dflt_value'] === null): ?>required<?php endif; ?>
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                >
                                    <option value="">-- Select <?php echo ucfirst(str_replace('_', ' ', $relation_target_table)); ?> --</option>
                                    <?php foreach ($relation_records as $rel_record): 
                                        $rel_id = $rel_record[$target_pk] ?? null;
                                        $rel_display = $rel_record[$relation_display_column] ?? 'ID: ' . $rel_id;
                                        $selected = ($edit_record && isset($edit_record[$col_name]) && intval($edit_record[$col_name]) === intval($rel_id)) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo htmlspecialchars($rel_id); ?>" <?php echo $selected; ?>>
                                            <?php echo htmlspecialchars($rel_display); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else:
                            // Check if field is image (column name contains 'image' or 'img' or 'photo' or 'picture' or 'avatar' or 'thumbnail')
                            $is_image = false;
                            if (preg_match('/\b(image|img|photo|picture|resim|foto|avatar|thumbnail|profile_picture|profile_image)\b/i', $col_name)) {
                                $is_image = true;
                            }
                            
                            // Also check column type for TEXT fields that might be images (stored as file paths)
                            if (!$is_image && $col_type === 'text' && preg_match('/(picture|image|photo|avatar)/i', $col_name)) {
                                $is_image = true;
                            }
                            
                            // Check if field is boolean (INTEGER with default 0/1 or field name suggests boolean)
                            $is_boolean = false;
                            if (!$is_image && $col_type === 'integer') {
                                $dflt_val = $col['dflt_value'] ?? '';
                                if ($dflt_val === '0' || $dflt_val === '1' || 
                                    preg_match('/^(is_|has_|can_|should_|must_|.*_(mi|mu|mi_durum|durum)$)/i', $col_name)) {
                                    $is_boolean = true;
                                }
                            }
                            
                            if ($is_image):
                                $current_image = $edit_record ? ($edit_record[$col_name] ?? '') : '';
                                ?>
                                <div class="space-y-2">
                                    <?php if (!empty($current_image) && file_exists(__DIR__ . '/../' . $current_image)): ?>
                                        <div class="mb-2">
                                            <label class="block text-xs text-muted-foreground mb-1">Current Image:</label>
                                            <img 
                                                src="../<?php echo htmlspecialchars($current_image); ?>" 
                                                alt="<?php echo $col_label; ?>"
                                                class="max-w-xs max-h-48 rounded-md border border-input object-contain"
                                            >
                                            <input type="hidden" name="existing_<?php echo $col_name; ?>" value="<?php echo htmlspecialchars($current_image); ?>">
                                        </div>
                                    <?php endif; ?>
                                    <input
                                        type="file"
                                        id="<?php echo $col_name; ?>" 
                                        name="<?php echo $col_name; ?>"
                                        accept="image/*"
                                        <?php if ($col['notnull'] == 1 && $col['dflt_value'] === null && empty($current_image)): ?>required<?php endif; ?>
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-primary-foreground hover:file:opacity-90 focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                    <p class="text-xs text-muted-foreground">Maximum file size: 5MB. Supported formats: JPG, PNG, GIF, WEBP, SVG</p>
                                </div>
                            <?php elseif ($is_boolean): 
                                $checked = false;
                                if ($edit_record && isset($edit_record[$col_name])) {
                                    $checked = (intval($edit_record[$col_name]) === 1);
                                } elseif (!$edit_record && isset($col['dflt_value']) && $col['dflt_value'] === '1') {
                                    $checked = true;
                                }
                                ?>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        id="<?php echo $col_name; ?>"
                                        name="<?php echo $col_name; ?>"
                                        value="1"
                                        <?php echo $checked ? 'checked' : ''; ?>
                                        class="rounded border-input text-primary focus:ring-2 focus:ring-ring"
                                    >
                                    <span class="text-sm text-foreground"><?php echo $col_label; ?></span>
                                </label>
                            <?php elseif ($col_type === 'text' && (strlen($col['dflt_value'] ?? '') > 50 || strpos($col_name, 'description') !== false || strpos($col_name, 'aciklama') !== false)): ?>
                                <textarea
                                    id="<?php echo $col_name; ?>"
                                    name="<?php echo $col_name; ?>"
                                    <?php if ($col['notnull'] == 1 && $col['dflt_value'] === null): ?>required<?php endif; ?>
                                    rows="3"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Enter <?php echo $col_label; ?>"
                                ><?php echo $edit_record ? htmlspecialchars($edit_record[$col_name] ?? '') : ''; ?></textarea>
                            <?php else: ?>
                                <?php
                                $input_type = 'text';
                                if ($col_type === 'integer' || $col_type === 'real') {
                                    $input_type = 'number';
                                }
                                ?>
                                <input
                                    type="<?php echo $input_type; ?>"
                                    id="<?php echo $col_name; ?>"
                                    name="<?php echo $col_name; ?>"
                                    <?php if ($col['notnull'] == 1 && $col['dflt_value'] === null): ?>required<?php endif; ?>
                                    value="<?php echo $edit_record ? htmlspecialchars($edit_record[$col_name] ?? '') : ''; ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    placeholder="Enter <?php echo $col_label; ?>"
                                >
                            <?php endif; // End of if-elseif-else for non-relation fields ?>
                            <?php endif; // End of if-else block (relation vs non-relation) ?>
                        </div>
                        <?php
                    } // End of foreach columns
                    ?>

                    <div class="flex gap-2">
                        <div class="flex-1">
                            <button
                                type="submit"
                                class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                            >
                                <?php echo $edit_record ? 'Update' : 'Add'; ?>
                            </button>
                            <?php if ($edit_record): ?>
                                <a
                                    href="dynamic-page.php?page=<?php echo urlencode($page_name); ?>"
                                    class="rounded-md bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-500 transition-all ml-2"
                                >
                                    Cancel
                                </a>
                            <?php endif; ?>
                            <?php
                            // Show rule under button (can contain PHP code)
                            if ($edit_record) {
                                $rule = $page_config['update_rule'] ?? '';
                            } else {
                                $rule = $page_config['create_rule'] ?? '';
                            }
                            if (!empty($rule)):
                                // Prepare context for PHP execution
                                $rule_context = [
                                    'record' => $edit_record ?? [],
                                    'columns' => $columns,
                                    'is_edit' => !empty($edit_record),
                                    'db' => $db,
                                    'dbContext' => $db  // Alias for dbContext like in cloud-functions
                                ];
                                
                                // Execute rule as PHP code
                                ob_start();
                                try {
                                    extract($rule_context);
                                    eval('?>' . $rule);
                                    $rule_output = ob_get_clean();
                                } catch (Exception $e) {
                                    ob_end_clean();
                                    $rule_output = '<span class="text-red-600">' . htmlspecialchars($e->getMessage()) . '</span>';
                                } catch (ParseError $e) {
                                    ob_end_clean();
                                    $rule_output = '<span class="text-red-600">' . htmlspecialchars($e->getMessage()) . '</span>';
                                }
                            ?>
                                <div class="mt-2">
                                    <div class="text-xs text-muted-foreground italic"><?php echo $rule_output; ?></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
                </div>
            </div>
        </div>
        <?php
    }
    
    // List table with filters
    if ($enable_list) {
        $has_filters = false;
        foreach ($columns as $col) {
            $col_name = $col['name'];
            if (isset($_GET['filter_' . $col_name]) && $_GET['filter_' . $col_name] !== '') {
                $has_filters = true;
                break;
            }
            if (isset($_GET['filter_' . $col_name . '_min']) && $_GET['filter_' . $col_name . '_min'] !== '') {
                $has_filters = true;
                break;
            }
            if (isset($_GET['filter_' . $col_name . '_max']) && $_GET['filter_' . $col_name . '_max'] !== '') {
                $has_filters = true;
                break;
            }
        }
        ?>
        <!-- Filter Form -->
        <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
            <button
                type="button"
                onclick="toggleCollapse('filter-form-collapse')"
                class="w-full p-6 pb-0 text-left flex items-center justify-between hover:bg-muted/50 transition-colors rounded-t-lg"
            >
                <h3 class="text-lg font-semibold leading-none tracking-tight mb-5">Filtering</h3>
                <svg
                    id="filter-form-chevron"
                    class="h-5 w-5 text-muted-foreground transition-transform <?php echo $has_filters ? '' : 'rotate-180'; ?>"
                    fill="none"
                    stroke="currentColor"
                    viewBox="0 0 24 24"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
            <div
                id="filter-form-collapse"
                class="overflow-hidden transition-all duration-300 <?php echo $has_filters ? 'max-h-[9999px]' : 'max-h-0'; ?>"
            >
                <div class="p-6 pt-0">
                <form method="GET" action="" class="space-y-4">
                    <input type="hidden" name="page" value="<?php echo htmlspecialchars($page_name); ?>">
                    <?php if (isset($_GET['edit'])): ?>
                        <input type="hidden" name="edit" value="<?php echo htmlspecialchars($_GET['edit']); ?>">
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php
                        foreach ($columns as $col) {
                            $col_name = $col['name'];
                            $col_label = ucfirst(str_replace('_', ' ', $col_name));
                            $col_type_lower = strtolower($col['type']);
                            
                            // Skip primary key and timestamps
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
                            
                            if ($is_boolean) {
                                ?>
                                <div>
                                    <label for="filter_<?php echo $col_name; ?>" class="block text-sm font-medium text-foreground mb-1.5"><?php echo $col_label; ?></label>
                                    <select
                                        id="filter_<?php echo $col_name; ?>"
                                        name="filter_<?php echo $col_name; ?>"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                        <option value="">All</option>
                                        <option value="1" <?php echo (isset($_GET['filter_' . $col_name]) && $_GET['filter_' . $col_name] === '1') ? 'selected' : ''; ?>>Yes</option>
                                        <option value="0" <?php echo (isset($_GET['filter_' . $col_name]) && $_GET['filter_' . $col_name] === '0') ? 'selected' : ''; ?>>No</option>
                                    </select>
                                </div>
                                <?php
                            } elseif ($col_type_lower === 'text') {
                                ?>
                                <div>
                                    <label for="filter_<?php echo $col_name; ?>" class="block text-sm font-medium text-foreground mb-1.5"><?php echo $col_label; ?></label>
                                    <input
                                        type="text"
                                        id="filter_<?php echo $col_name; ?>"
                                        name="filter_<?php echo $col_name; ?>"
                                        value="<?php echo htmlspecialchars($_GET['filter_' . $col_name] ?? ''); ?>"
                                        placeholder="Search <?php echo $col_label; ?>..."
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <?php
                            } else if ($col_type_lower === 'integer' || $col_type_lower === 'real') {
                                ?>
                                <div>
                                    <label for="filter_<?php echo $col_name; ?>" class="block text-sm font-medium text-foreground mb-1.5"><?php echo $col_label; ?> (Equal)</label>
                                    <input
                                        type="number"
                                        id="filter_<?php echo $col_name; ?>"
                                        name="filter_<?php echo $col_name; ?>"
                                        value="<?php echo htmlspecialchars($_GET['filter_' . $col_name] ?? ''); ?>"
                                        placeholder="<?php echo $col_label; ?>"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="filter_<?php echo $col_name; ?>_min" class="block text-sm font-medium text-foreground mb-1.5"><?php echo $col_label; ?> (Min)</label>
                                    <input
                                        type="number"
                                        id="filter_<?php echo $col_name; ?>_min"
                                        name="filter_<?php echo $col_name; ?>_min"
                                        value="<?php echo htmlspecialchars($_GET['filter_' . $col_name . '_min'] ?? ''); ?>"
                                        placeholder="Min"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <div>
                                    <label for="filter_<?php echo $col_name; ?>_max" class="block text-sm font-medium text-foreground mb-1.5"><?php echo $col_label; ?> (Max)</label>
                                    <input
                                        type="number"
                                        id="filter_<?php echo $col_name; ?>_max"
                                        name="filter_<?php echo $col_name; ?>_max"
                                        value="<?php echo htmlspecialchars($_GET['filter_' . $col_name . '_max'] ?? ''); ?>"
                                        placeholder="Max"
                                        class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                    >
                                </div>
                                <?php
                            }
                        }
                        ?>
                        
                        <div>
                            <label for="per_page" class="block text-sm font-medium text-foreground mb-1.5">Records Per Page</label>
                            <select
                                id="per_page"
                                name="per_page"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent"
                                onchange="this.form.submit()"
                            >
                                <option value="10" <?php echo ($per_page == 10) ? 'selected' : ''; ?>>10</option>
                                <option value="20" <?php echo ($per_page == 20) ? 'selected' : ''; ?>>20</option>
                                <option value="50" <?php echo ($per_page == 50) ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo ($per_page == 100) ? 'selected' : ''; ?>>100</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex gap-2 pt-2">
                        <button
                            type="submit"
                            class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                        >
                            Filter
                        </button>
                        <a
                            href="dynamic-page.php?page=<?php echo urlencode($page_name); ?>"
                            class="rounded-md bg-gray-100 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-500 transition-all"
                        >
                            Clear
                        </a>
                    </div>
                </form>
                </div>
            </div>
        </div>

        <!-- Records List -->
        <div class="mb-8 rounded-lg border border-border bg-card text-card-foreground shadow-sm">
            <div class="p-6 pb-0">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold leading-none tracking-tight">All Records</h3>
                    <div class="text-sm text-muted-foreground">
                        Total: <span class="font-medium text-foreground"><?php echo $total_records; ?></span> records
                    </div>
                </div>
            </div>
            <div class="p-6 pt-0">
                <?php if (empty($records)): ?>
                    <div class="text-center py-8 text-muted-foreground">
                        No records added yet or no results found with filters.
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full border-collapse">
                            <thead>
                                <tr class="border-b border-border">
                                    <?php
                                    foreach ($columns as $col) {
                                        $col_name = $col['name'];
                                        $col_label = ucfirst(str_replace('_', ' ', $col_name));
                                        ?>
                                        <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">
                                            <a href="?<?php
                                                $params = $_GET;
                                                $params['page'] = $page_name;
                                                $params['sort'] = $col_name;
                                                if (isset($params['sort']) && $params['sort'] == $col_name && isset($params['order']) && $params['order'] == 'ASC') {
                                                    $params['order'] = 'DESC';
                                                } else {
                                                    $params['order'] = 'ASC';
                                                }
                                                echo http_build_query($params);
                                            ?>" class="flex items-center gap-1 hover:text-foreground">
                                                <span><?php echo $col_label; ?></span>
                                                <?php if ($sort_column == $col_name): ?>
                                                    <span class="text-xs"><?php echo $sort_order == 'ASC' ? '↑' : '↓'; ?></span>
                                                <?php endif; ?>
                                            </a>
                                        </th>
                                        <?php
                                    }
                                    ?>
                                    
                                    <?php if ($enable_update || $enable_delete): ?>
                                        <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground text-sm">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                    <?php foreach ($records as $record): ?>
                                        <tr class="border-b border-border transition-colors hover:bg-muted/50">
                                            <?php foreach ($columns as $col): 
                                                $col_name = $col['name'];
                                                $col_type = strtolower($col['type']);
                                                $value = $record[$col_name] ?? null;
                                                
                                                // Check if this column has a relation
                                                $is_relation = isset($relation_metadata[$col_name]) && !empty($relation_metadata[$col_name]);
                                                $relation_target_table = $is_relation ? $relation_metadata[$col_name] : null;
                                                
                                                // Check if field is boolean
                                                $is_boolean = false;
                                                if ($col_type === 'integer') {
                                                    $dflt_val = $col['dflt_value'] ?? '';
                                                    if ($dflt_val === '0' || $dflt_val === '1' || 
                                                        preg_match('/^(is_|has_|can_|should_|must_|.*_(mi|mu|mi_durum|durum)$)/i', $col_name)) {
                                                        $is_boolean = true;
                                                    }
                                                }
                                                ?>
                                                <td class="p-4 align-middle text-sm">
                                                    <?php 
                                                    // Check if field is relation - show related record name
                                                    if ($is_relation && $relation_target_table && $value !== null):
                                                        try {
                                                            $escaped_target = preg_replace('/[^a-zA-Z0-9_]/', '', $relation_target_table);
                                                            $target_stmt = $db->query("PRAGMA table_info(\"$escaped_target\")");
                                                            $target_columns = $target_stmt->fetchAll(PDO::FETCH_ASSOC);
                                                            
                                                            // Find display column
                                                            $relation_display_column = 'name';
                                                            $display_candidates = ['name', 'title', 'label', 'title_en', 'name_en'];
                                                            foreach ($display_candidates as $candidate) {
                                                                foreach ($target_columns as $target_col) {
                                                                    if (strtolower($target_col['name']) === $candidate) {
                                                                        $relation_display_column = $target_col['name'];
                                                                        break 2;
                                                                    }
                                                                }
                                                            }
                                                            
                                                            // Get primary key
                                                            $target_pk = 'id';
                                                            foreach ($target_columns as $target_col) {
                                                                if ($target_col['pk']) {
                                                                    $target_pk = $target_col['name'];
                                                                    break;
                                                                }
                                                            }
                                                            
                                                            // Fetch related record
                                                            $escaped_pk = preg_replace('/[^a-zA-Z0-9_]/', '', $target_pk);
                                                            $escaped_display = preg_replace('/[^a-zA-Z0-9_]/', '', $relation_display_column);
                                                            $rel_stmt = $db->prepare("SELECT `$escaped_display` FROM `$escaped_target` WHERE `$escaped_pk` = ?");
                                                            $rel_stmt->execute([$value]);
                                                            $rel_record = $rel_stmt->fetch(PDO::FETCH_ASSOC);
                                                            
                                                            if ($rel_record) {
                                                                echo '<span class="text-primary font-medium">' . htmlspecialchars($rel_record[$relation_display_column] ?? 'ID: ' . $value) . '</span>';
                                                                echo '<span class="text-muted-foreground text-xs ml-2">(ID: ' . htmlspecialchars($value) . ')</span>';
                                                            } else {
                                                                echo '<span class="text-muted-foreground italic">ID: ' . htmlspecialchars($value) . ' (not found)</span>';
                                                            }
                                                        } catch (PDOException $e) {
                                                            echo '<span class="text-muted-foreground italic">ID: ' . htmlspecialchars($value) . '</span>';
                                                        }
                                                    // Check if field is image (support profile_picture, avatar, thumbnail, etc.)
                                                    elseif (!$is_relation):
                                                        $is_image_col = preg_match('/\b(image|img|photo|picture|resim|foto|avatar|thumbnail|profile_picture|profile_image)\b/i', $col_name);
                                                        
                                                        if ($is_boolean && $value !== null): ?>
                                                            <?php if (intval($value) === 1): ?>
                                                                <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800">Yes</span>
                                                            <?php else: ?>
                                                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-800">No</span>
                                                            <?php endif; ?>
                                                        <?php elseif ($is_image_col && !empty($value) && file_exists(__DIR__ . '/../' . $value)): ?>
                                                            <div class="flex items-center gap-2">
                                                                <img 
                                                                    src="../<?php echo htmlspecialchars($value); ?>" 
                                                                    alt="<?php echo htmlspecialchars($col_name); ?>"
                                                                    class="w-16 h-16 object-cover rounded border border-input"
                                                                    onerror="this.style.display='none'"
                                                                >
                                                                <a 
                                                                    href="../<?php echo htmlspecialchars($value); ?>" 
                                                                    target="_blank" 
                                                                    class="text-xs text-primary hover:underline"
                                                                >
                                                                    View
                                                                </a>
                                                            </div>
                                                        <?php elseif ($value === null): ?>
                                                            <span class="text-muted-foreground italic">NULL</span>
                                                        <?php else: ?>
                                                            <?php 
                                                            // Truncate long values
                                                            $display_value = htmlspecialchars($value);
                                                            if (strlen($display_value) > 50) {
                                                                echo '<span title="' . htmlspecialchars($value) . '">' . htmlspecialchars(substr($value, 0, 50)) . '...</span>';
                                                            } else {
                                                                echo $display_value;
                                                            }
                                                            ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        
                                        <?php if ($enable_update || $enable_delete): ?>
                                            <td class="p-4 align-middle">
                                                <div class="flex gap-2">
                                                    <?php if ($enable_update): ?>
                                                        <div class="inline-block">
                                                            <a
                                                                href="?page=<?php echo urlencode($page_name); ?>&edit=<?php echo $record[$primary_key]; ?>"
                                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-blue-100 text-blue-800 hover:bg-blue-200 h-9 px-3"
                                                            >
                                                                Edit
                                                            </a>
                                                            <?php if (!empty($page_config['update_rule'])): 
                                                                // Execute rule as PHP code
                                                                $rule_context = [
                                                                    'record' => $record,
                                                                    'columns' => $columns,
                                                                    'is_edit' => false,
                                                                    'db' => $db,
                                                                    'dbContext' => $db  // Alias for dbContext like in cloud-functions
                                                                ];
                                                                
                                                                ob_start();
                                                                try {
                                                                    extract($rule_context);
                                                                    eval('?>' . $page_config['update_rule']);
                                                                    $rule_output = ob_get_clean();
                                                                } catch (Exception $e) {
                                                                    ob_end_clean();
                                                                    $rule_output = '<span class="text-red-600"> ' . htmlspecialchars($e->getMessage()) . '</span>';
                                                                } catch (ParseError $e) {
                                                                    ob_end_clean();
                                                                    $rule_output = '<span class="text-red-600">Rule syntax error: ' . htmlspecialchars($e->getMessage()) . '</span>';
                                                                }
                                                            ?>
                                                                <div class="mt-1">
                                                                    <div class="text-xs text-muted-foreground italic"><?php echo $rule_output; ?></div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($enable_delete): ?>
                                                        <div class="inline-block">
                                                            <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to delete this record?');">
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="record_id" value="<?php echo $record[$primary_key]; ?>">
                                                                <button
                                                                    type="submit"
                                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-red-100 text-red-800 hover:bg-red-200 h-9 px-3"
                                                                >
                                                                    Delete
                                                                </button>
                                                            </form>
                                                            <?php if (!empty($page_config['delete_rule'])): 
                                                                // Execute rule as PHP code
                                                                $rule_context = [
                                                                    'record' => $record,
                                                                    'columns' => $columns,
                                                                    'is_edit' => false,
                                                                    'db' => $db,
                                                                    'dbContext' => $db  // Alias for dbContext like in cloud-functions
                                                                ];
                                                                
                                                                ob_start();
                                                                try {
                                                                    extract($rule_context);
                                                                    eval('?>' . $page_config['delete_rule']);
                                                                    $rule_output = ob_get_clean();
                                                                } catch (Exception $e) {
                                                                    ob_end_clean();
                                                                    $rule_output = '<span class="text-red-600"> ' . htmlspecialchars($e->getMessage()) . '</span>';
                                                                } catch (ParseError $e) {
                                                                    ob_end_clean();
                                                                    $rule_output = '<span class="text-red-600">Rule syntax error: ' . htmlspecialchars($e->getMessage()) . '</span>';
                                                                }
                                                            ?>
                                                                <div class="mt-1">
                                                                    <div class="text-xs text-muted-foreground italic"><?php echo $rule_output; ?></div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_records > 0): ?>
                        <div class="mt-6 flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-border pt-4">
                            <div class="text-sm text-muted-foreground">
                                <span class="font-medium text-foreground"><?php echo $total_records; ?></span> records,
                                showing
                                <span class="font-medium text-foreground"><?php echo min($offset + 1, $total_records); ?> - <?php echo min($offset + $per_page, $total_records); ?></span>
                                <?php if ($total_pages > 1): ?>
                                    (Page <span class="font-medium text-foreground"><?php echo $current_page_num; ?></span> / <span class="font-medium text-foreground"><?php echo $total_pages; ?></span>)
                                <?php endif; ?>
                            </div>
                            <?php if ($total_pages > 1): ?>
                                <div class="flex items-center gap-1 flex-wrap justify-center">
                                    <!-- First Page -->
                                    <?php if ($current_page_num > 3): ?>
                                        <a
                                            href="?<?php
                                                $params = $_GET;
                                                $params['page'] = $page_name;
                                                $params['page_num'] = 1;
                                                echo http_build_query($params);
                                            ?>"
                                            class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-foreground hover:bg-accent hover:text-accent-foreground transition-colors"
                                            title="First Page"
                                        >
                                            1
                                        </a>
                                        <?php if ($current_page_num > 4): ?>
                                            <span class="px-2 text-muted-foreground">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <!-- Previous Button -->
                                    <?php if ($current_page_num > 1): ?>
                                        <a
                                            href="?<?php
                                                $params = $_GET;
                                                $params['page'] = $page_name;
                                                $params['page_num'] = $current_page_num - 1;
                                                echo http_build_query($params);
                                            ?>"
                                            class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-foreground hover:bg-accent hover:text-accent-foreground transition-colors"
                                            title="Previous Page"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                                            </svg>
                                        </a>
                                    <?php else: ?>
                                        <span class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-muted-foreground opacity-50 cursor-not-allowed">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                                            </svg>
                                        </span>
                                    <?php endif; ?>

                                    <!-- Page Numbers -->
                                    <?php
                                    $start_page = max(1, $current_page_num - 2);
                                    $end_page = min($total_pages, $current_page_num + 2);
                                    
                                    // Ensure we show at least 5 pages if possible
                                    if ($end_page - $start_page < 4 && $total_pages > 5) {
                                        if ($start_page == 1) {
                                            $end_page = min($total_pages, 5);
                                        } elseif ($end_page == $total_pages) {
                                            $start_page = max(1, $total_pages - 4);
                                        }
                                    }
                                    
                                    for ($i = $start_page; $i <= $end_page; $i++):
                                    ?>
                                        <a
                                            href="?<?php
                                                $params = $_GET;
                                                $params['page'] = $page_name;
                                                $params['page_num'] = $i;
                                                echo http_build_query($params);
                                            ?>"
                                            class="inline-flex items-center justify-center rounded-md min-w-[40px] border <?php echo $i == $current_page_num ? 'border-primary bg-primary text-primary-foreground font-semibold' : 'border-input bg-background text-foreground hover:bg-accent'; ?> px-3 py-2 text-sm font-medium transition-colors"
                                        >
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>

                                    <!-- Next Button -->
                                    <?php if ($current_page_num < $total_pages): ?>
                                        <a
                                            href="?<?php
                                                $params = $_GET;
                                                $params['page'] = $page_name;
                                                $params['page_num'] = $current_page_num + 1;
                                                echo http_build_query($params);
                                            ?>"
                                            class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-foreground hover:bg-accent hover:text-accent-foreground transition-colors"
                                            title="Next Page"
                                        >
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                            </svg>
                                        </a>
                                    <?php else: ?>
                                        <span class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-muted-foreground opacity-50 cursor-not-allowed">
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                            </svg>
                                        </span>
                                    <?php endif; ?>

                                    <!-- Last Page -->
                                    <?php if ($current_page_num < $total_pages - 2): ?>
                                        <?php if ($current_page_num < $total_pages - 3): ?>
                                            <span class="px-2 text-muted-foreground">...</span>
                                        <?php endif; ?>
                                        <a
                                            href="?<?php
                                                $params = $_GET;
                                                $params['page'] = $page_name;
                                                $params['page_num'] = $total_pages;
                                                echo http_build_query($params);
                                            ?>"
                                            class="inline-flex items-center justify-center rounded-md border border-input bg-background px-3 py-2 text-sm font-medium text-foreground hover:bg-accent hover:text-accent-foreground transition-colors"
                                            title="Last Page"
                                        >
                                            <?php echo $total_pages; ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
?>

<script>
function toggleCollapse(elementId) {
    const element = document.getElementById(elementId);
    const chevron = document.getElementById(elementId.replace('-collapse', '-chevron'));
    
    if (element.classList.contains('max-h-0')) {
        // Expand
        element.classList.remove('max-h-0');
        element.classList.add('max-h-[9999px]');
        if (chevron) {
            chevron.classList.remove('rotate-180');
        }
    } else {
        // Collapse
        element.classList.remove('max-h-[9999px]');
        element.classList.add('max-h-0');
        if (chevron) {
            chevron.classList.add('rotate-180');
        }
    }
}
</script>

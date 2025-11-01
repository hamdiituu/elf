<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();

    // Check if group_name column exists
    $stmt = $db->query("PRAGMA table_info(dynamic_pages)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $has_group_name = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'group_name') {
            $has_group_name = true;
            break;
        }
    }

    // Add group_name column if it doesn't exist
    if (!$has_group_name) {
        $db->exec("ALTER TABLE dynamic_pages ADD COLUMN group_name TEXT");
        echo "Added 'group_name' column to dynamic_pages table.\n";
    } else {
        echo "Column 'group_name' already exists.\n";
    }

    echo "Migration completed.\n";

} catch (PDOException $e) {
    die("Error running migration: " . $e->getMessage());
}
?>

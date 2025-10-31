<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();
    
    // Check if deleted_at column exists
    $stmt = $db->query("PRAGMA table_info(sayim_icerikleri)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasDeletedAt = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'deleted_at') {
            $hasDeletedAt = true;
            break;
        }
    }
    
    if (!$hasDeletedAt) {
        // Add deleted_at column to sayim_icerikleri table
        $db->exec("ALTER TABLE sayim_icerikleri ADD COLUMN deleted_at DATETIME NULL");
        echo "deleted_at column added successfully!\n";
    } else {
        echo "deleted_at column already exists.\n";
    }
    
    echo "Migration completed successfully!\n";
    
} catch (PDOException $e) {
    die("Error running migration: " . $e->getMessage());
}
?>


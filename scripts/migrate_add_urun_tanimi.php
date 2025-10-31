<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();
    
    // Create urun_tanimi table (product definitions)
    $db->exec("CREATE TABLE IF NOT EXISTS urun_tanimi (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        barkod TEXT NOT NULL UNIQUE,
        urun_aciklamasi TEXT,
        deleted_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create index for better performance
    $db->exec("CREATE INDEX IF NOT EXISTS idx_urun_barkod ON urun_tanimi(barkod)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_urun_deleted_at ON urun_tanimi(deleted_at)");
    
    echo "urun_tanimi table created successfully!\n";
    
} catch (PDOException $e) {
    die("Error running migration: " . $e->getMessage());
}
?>


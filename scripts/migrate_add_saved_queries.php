<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();
    
    // Create saved_queries table
    $db->exec("CREATE TABLE IF NOT EXISTS saved_queries (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        query TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create index for better performance
    $db->exec("CREATE INDEX IF NOT EXISTS idx_saved_queries_name ON saved_queries(name)");
    
    echo "saved_queries table created successfully!\n";
    
} catch (PDOException $e) {
    die("Error running migration: " . $e->getMessage());
}
?>


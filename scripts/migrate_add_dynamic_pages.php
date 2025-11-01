<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();

    // Create dynamic_pages table
    $db->exec("CREATE TABLE IF NOT EXISTS dynamic_pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_name TEXT NOT NULL UNIQUE,
        page_title TEXT NOT NULL,
        table_name TEXT NOT NULL,
        enable_list INTEGER DEFAULT 1,
        enable_create INTEGER DEFAULT 1,
        enable_update INTEGER DEFAULT 1,
        enable_delete INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Add index for faster lookups
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dynamic_pages_table ON dynamic_pages(table_name)");

    echo "dynamic_pages table ensured.\n";

} catch (PDOException $e) {
    die("Error running migration: " . $e->getMessage());
}
?>

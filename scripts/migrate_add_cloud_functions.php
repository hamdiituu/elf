<?php
/**
 * Migration: Add cloud_functions table
 */

require_once __DIR__ . '/../config/config.php';

$db = getDB();

try {
    $db->exec("CREATE TABLE IF NOT EXISTS cloud_functions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        description TEXT,
        code TEXT NOT NULL,
        http_method TEXT NOT NULL DEFAULT 'POST',
        endpoint TEXT NOT NULL UNIQUE,
        enabled INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INTEGER,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");
    
    // Create index for endpoint
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cloud_functions_endpoint ON cloud_functions(endpoint)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cloud_functions_enabled ON cloud_functions(enabled)");
    
    echo "Migration completed successfully: cloud_functions table created.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>


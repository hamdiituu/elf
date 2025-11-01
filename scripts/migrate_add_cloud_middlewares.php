<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();

    // Create cloud_middlewares table
    $db->exec("CREATE TABLE IF NOT EXISTS cloud_middlewares (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        description TEXT,
        code TEXT NOT NULL,
        enabled INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INTEGER,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");

    // Add middleware_id column to cloud_functions if it doesn't exist
    try {
        $db->exec("ALTER TABLE cloud_functions ADD COLUMN middleware_id INTEGER REFERENCES cloud_middlewares(id)");
        echo "Added 'middleware_id' column to cloud_functions table.\n";
    } catch (PDOException $e) {
        // Column might already exist, check
        $stmt = $db->query("PRAGMA table_info(cloud_functions)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasMiddlewareId = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'middleware_id') {
                $hasMiddlewareId = true;
                break;
            }
        }
        if (!$hasMiddlewareId) {
            throw $e;
        }
        echo "'middleware_id' column already exists in cloud_functions table.\n";
    }

    echo "Migration completed successfully.\n";

} catch (PDOException $e) {
    die("Error running migration: " . $e->getMessage());
}
?>

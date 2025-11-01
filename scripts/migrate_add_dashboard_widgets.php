<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS dashboard_widgets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        widget_type TEXT NOT NULL,
        widget_config TEXT NOT NULL,
        position INTEGER DEFAULT 0,
        width TEXT DEFAULT 'md:col-span-1',
        enabled INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    echo "dashboard_widgets table ensured.\n";

} catch (PDOException $e) {
    die("Error running migration: " . $e->getMessage());
}
?>

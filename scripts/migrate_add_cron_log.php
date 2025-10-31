<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();
    
    // Create cron_log table
    $db->exec("CREATE TABLE IF NOT EXISTS cron_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cron_name TEXT NOT NULL,
        status TEXT NOT NULL,
        message TEXT,
        execution_time_ms REAL,
        started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL,
        error_message TEXT NULL
    )");
    
    // Create indexes for better performance
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_log_name ON cron_log(cron_name)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_log_status ON cron_log(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_log_started_at ON cron_log(started_at)");
    
    echo "cron_log table created successfully!\n";
    
} catch (PDOException $e) {
    die("Error running migration: " . $e->getMessage());
}
?>


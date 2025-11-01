<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();
    
    // Create cron_jobs table
    $db->exec("CREATE TABLE IF NOT EXISTS cron_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        description TEXT,
        code TEXT NOT NULL,
        schedule TEXT NOT NULL,
        enabled INTEGER DEFAULT 1,
        last_run_at DATETIME NULL,
        next_run_at DATETIME NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create indexes for better performance
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_jobs_enabled ON cron_jobs(enabled)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_jobs_next_run_at ON cron_jobs(next_run_at)");
    
    echo "cron_jobs table created successfully!\n";
    
} catch (PDOException $e) {
    die("Error running migration: " . $e->getMessage());
}
?>


<?php
/**
 * Cron Helper Functions
 * Provides reusable functions for cron job logging
 */

require_once __DIR__ . '/../config/config.php';

// Ensure cron_log table exists
function ensureCronLogTable() {
    try {
        $db = getDB();
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
    } catch (PDOException $e) {
        // Table might already exist, ignore error
    }
}

/**
 * Log cron job status to database
 * @param string $cron_name Name of the cron job
 * @param string $status Status (started, success, failed)
 * @param string|null $message Message
 * @param float|null $execution_time_ms Execution time in milliseconds
 * @param string|null $error_message Error message if failed
 * @return int|null Log entry ID (only for 'started' status)
 */
function cronLog($cron_name, $status, $message = null, $execution_time_ms = null, $error_message = null) {
    ensureCronLogTable();
    
    try {
        $db = getDB();
        
        if ($status === 'started') {
            // Insert new log entry
            $stmt = $db->prepare("INSERT INTO cron_log (cron_name, status, message, started_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$cron_name, $status, $message]);
            return (int)$db->lastInsertId();
        } else {
            // Update existing log entry (find latest started entry for this cron)
            $stmt = $db->prepare("
                UPDATE cron_log 
                SET status = ?, 
                    message = ?, 
                    execution_time_ms = ?, 
                    finished_at = CURRENT_TIMESTAMP,
                    error_message = ?
                WHERE id = (
                    SELECT id FROM cron_log 
                    WHERE cron_name = ? AND status = 'started' 
                    ORDER BY started_at DESC 
                    LIMIT 1
                )
            ");
            $stmt->execute([$status, $message, $execution_time_ms, $error_message, $cron_name]);
            return null;
        }
    } catch (PDOException $e) {
        // If database logging fails, continue (don't break cron)
        error_log("Failed to log cron to database: " . $e->getMessage());
        return null;
    }
}

/**
 * Get cron job logs
 * @param string|null $cron_name Filter by cron name (optional)
 * @param string|null $status Filter by status (optional)
 * @param int $limit Limit number of results (default: 100)
 * @return array Array of cron log entries
 */
function getCronLogs($cron_name = null, $status = null, $limit = 100) {
    ensureCronLogTable();
    
    try {
        $db = getDB();
        
        $query = "SELECT * FROM cron_log WHERE 1=1";
        $params = [];
        
        if ($cron_name !== null) {
            $query .= " AND cron_name = ?";
            $params[] = $cron_name;
        }
        
        if ($status !== null) {
            $query .= " AND status = ?";
            $params[] = $status;
        }
        
        $query .= " ORDER BY started_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to get cron logs: " . $e->getMessage());
        return [];
    }
}

/**
 * Get latest cron log entry for a specific cron job
 * @param string $cron_name Name of the cron job
 * @return array|null Latest log entry or null
 */
function getLatestCronLog($cron_name) {
    $logs = getCronLogs($cron_name, null, 1);
    return !empty($logs) ? $logs[0] : null;
}

/**
 * Log message to file (optional file logging)
 * @param string $message Message to log
 * @param string|null $log_file Log file path (optional)
 */
function cronLogToFile($message, $log_file = null) {
    if ($log_file === null) {
        // Default log file in cron directory
        $log_file = __DIR__ . '/cron.log';
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[{$timestamp}] {$message}" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

?>


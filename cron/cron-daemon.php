<?php
/**
 * Cron Daemon - Automated Cron Job Runner
 * 
 * This script runs continuously in the background, checking for cron jobs
 * that need to be executed and running them automatically.
 * 
 * To run this daemon:
 * nohup php cron-daemon.php > cron-daemon.log 2>&1 &
 * 
 * Or use a process manager like supervisor/pm2
 */

// Prevent multiple instances
$lock_file = __DIR__ . '/cron-daemon.lock';
$pid_file = __DIR__ . '/cron-daemon.pid';

// Check if another instance is running
if (file_exists($lock_file)) {
    $old_pid = file_get_contents($lock_file);
    if (posix_getpgid($old_pid)) {
        // Process is still running
        echo "Cron daemon is already running (PID: $old_pid)\n";
        exit(1);
    } else {
        // Stale lock file, remove it
        @unlink($lock_file);
    }
}

// Create lock file with current PID
$pid = getmypid();
file_put_contents($lock_file, $pid);
file_put_contents($pid_file, $pid);

// Register shutdown function to clean up lock file
register_shutdown_function(function() use ($lock_file, $pid_file) {
    @unlink($lock_file);
    @unlink($pid_file);
});

// Signal handlers for graceful shutdown
if (function_exists('pcntl_signal')) {
    declare(ticks = 1);
    
    pcntl_signal(SIGTERM, function() use ($lock_file, $pid_file) {
        echo "Received SIGTERM, shutting down gracefully...\n";
        @unlink($lock_file);
        @unlink($pid_file);
        exit(0);
    });
    
    pcntl_signal(SIGINT, function() use ($lock_file, $pid_file) {
        echo "Received SIGINT, shutting down gracefully...\n";
        @unlink($lock_file);
        @unlink($pid_file);
        exit(0);
    });
}

// Set time limit to unlimited
set_time_limit(0);
ignore_user_abort(true);

// Load dependencies
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/common/cron-helper.php';

echo "Cron Daemon started at " . date('Y-m-d H:i:s') . " (PID: $pid)\n";

// Main loop
$iteration = 0;
$check_interval = 5; // Check every 5 seconds for jobs that need to run
$last_log_time = 0;

while (true) {
    try {
        $db = getDB();
        
        // Get current time
        $now = new DateTime();
        $now_str = $now->format('Y-m-d H:i:s');
        
        // Get all enabled cron jobs that are due to run
        $jobs = $db->query("
            SELECT * FROM cron_jobs 
            WHERE enabled = 1 
            AND (
                next_run_at IS NULL 
                OR next_run_at <= '" . $now_str . "'
                OR (last_run_at IS NULL AND created_at <= datetime('now', '-1 minute'))
            )
            ORDER BY next_run_at ASC, created_at ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($jobs as $job) {
            try {
                $should_run = false;
                
                // Check if job should run based on next_run_at
                if ($job['next_run_at'] && strtotime($job['next_run_at']) <= time()) {
                    $should_run = true;
                } elseif (!$job['last_run_at'] && strtotime($job['created_at']) <= time() - 60) {
                    // First run, wait 1 minute after creation
                    $should_run = true;
                } elseif (!$job['next_run_at']) {
                    // No next_run_at set, calculate it
                    $next_run = calculateNextRunFromSchedule($job['schedule']);
                    if ($next_run && strtotime($next_run) <= time()) {
                        $should_run = true;
                    }
                }
                
                if ($should_run) {
                    echo "[" . date('Y-m-d H:i:s') . "] Running cron job: {$job['name']}\n";
                    
                    // Execute cron job in background
                    executeCronJob($job, $db);
                    
                    // Update next_run_at
                    $next_run = calculateNextRunFromSchedule($job['schedule']);
                    if ($next_run) {
                        $stmt = $db->prepare("UPDATE cron_jobs SET next_run_at = ? WHERE id = ?");
                        $stmt->execute([$next_run, $job['id']]);
                    }
                }
            } catch (Exception $e) {
                error_log("Error running cron job '{$job['name']}': " . $e->getMessage());
                echo "[" . date('Y-m-d H:i:s') . "] Error running cron job '{$job['name']}': " . $e->getMessage() . "\n";
                
                // Update last_run_at even on error
                try {
                    $stmt = $db->prepare("UPDATE cron_jobs SET last_run_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$job['id']]);
                } catch (Exception $e2) {
                    // Ignore
                }
            }
        }
        
        // Calculate next_run_at for jobs that don't have it set
        $jobs_without_next = $db->query("
            SELECT id, schedule FROM cron_jobs 
            WHERE enabled = 1 AND next_run_at IS NULL
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($jobs_without_next as $job) {
            $next_run = calculateNextRunFromSchedule($job['schedule']);
            if ($next_run) {
                $stmt = $db->prepare("UPDATE cron_jobs SET next_run_at = ? WHERE id = ?");
                $stmt->execute([$next_run, $job['id']]);
            }
        }
        
        // Log every 60 seconds
        if (time() - $last_log_time >= 60) {
            $last_log_time = time();
            $running_jobs = $db->query("SELECT COUNT(*) FROM cron_jobs WHERE enabled = 1")->fetchColumn();
            echo "[" . date('Y-m-d H:i:s') . "] Daemon running - Active jobs: $running_jobs\n";
        }
        
    } catch (Exception $e) {
        error_log("Cron daemon error: " . $e->getMessage());
        echo "[" . date('Y-m-d H:i:s') . "] Daemon error: " . $e->getMessage() . "\n";
    }
    
    // Sleep before next check
    sleep($check_interval);
    $iteration++;
    
    // Prevent memory leaks (optional, reconnect every 1000 iterations)
    if ($iteration % 1000 === 0) {
        if (isset($db)) {
            $db = null;
        }
        gc_collect_cycles();
    }
}

/**
 * Execute a cron job in background
 */
function executeCronJob($job, $db) {
    $cron_name = $job['name'];
    $start_time = microtime(true);
    $language = $job['language'] ?? 'php';
    
    // Log start
    $log_id = cronLog($cron_name, 'started', "Scheduled run by daemon");
    
    try {
        // Create temporary file with wrapped code
        $temp_file = tempnam(sys_get_temp_dir(), 'cron_daemon_' . $cron_name . '_');
        
        // PHP execution only
        // Clean user code
        $user_code = trim($job['code']);
        if (!empty($user_code) && substr($user_code, -1) !== ';' && substr($user_code, -1) !== '}') {
            $user_code .= ';';
        }
        $user_code .= PHP_EOL;
        
        // Wrap code
        $wrapped_code = '<?php
require_once "' . __DIR__ . '/../config/config.php";
require_once "' . __DIR__ . '/common/cron-helper.php";

$cron_name = "' . addslashes($cron_name) . '";
$start_time = microtime(true);
$log_id = cronLog($cron_name, "started", "Scheduled run by daemon");

try {
    $db = getDB();
    
' . $user_code . '
    
    $execution_time = (microtime(true) - $start_time) * 1000;
    cronLog($cron_name, "success", "Cron job completed successfully", $execution_time);
    
    // Update last run time
    $stmt = $db->prepare("UPDATE cron_jobs SET last_run_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([' . $job['id'] . ']);
} catch (Exception $e) {
    $execution_time = (microtime(true) - $start_time) * 1000;
    cronLog($cron_name, "failed", "Cron job failed: " . $e->getMessage(), $execution_time, $e->getMessage());
    
    // Still update last run time even on failure
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE cron_jobs SET last_run_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([' . $job['id'] . ']);
    } catch (Exception $e2) {
        // Ignore
    }
    
    error_log("Cron job failed: " . $e->getMessage());
    exit(1);
}
?>';
        
        file_put_contents($temp_file, $wrapped_code);
        
        // Execute in background (non-blocking)
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            // Windows
            pclose(popen("start /B php " . escapeshellarg($temp_file) . " > NUL 2>&1", "r"));
        } else {
            // Unix/Linux - non-blocking
            exec("php " . escapeshellarg($temp_file) . " > /dev/null 2>&1 &", $output, $return_var);
        }
        
        // Clean up temp file after a delay
        // Use a background process to delete after execution
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            exec("(sleep 30 && rm -f " . escapeshellarg($temp_file) . ") > /dev/null 2>&1 &");
        } else {
            // Windows - delete after 30 seconds
            @unlink($temp_file);
        }
        
    } catch (Exception $e) {
        $execution_time = (microtime(true) - $start_time) * 1000;
        cronLog($cron_name, 'failed', "Cron job failed to start", $execution_time, $e->getMessage());
        error_log("Failed to execute cron job '{$cron_name}': " . $e->getMessage());
    }
}

/**
 * Calculate next run time from cron schedule
 */
function calculateNextRunFromSchedule($cron_expr) {
    $parts = explode(' ', trim($cron_expr));
    if (count($parts) !== 5) {
        return null;
    }
    
    list($minute, $hour, $day, $month, $weekday) = $parts;
    
    $now = new DateTime();
    $next = clone $now;
    
    // If all are *, run every minute
    if ($minute === '*' && $hour === '*' && $day === '*' && $month === '*' && $weekday === '*') {
        $next->modify('+1 minute');
        return $next->format('Y-m-d H:i:s');
    }
    
    // For specific schedules, calculate next run time
    // Simple implementation - add 1 minute for now
    // In production, you'd want a proper cron parser
    $next->modify('+1 minute');
    
    // Try to find next matching time (simplified)
    $attempts = 0;
    while ($attempts < 1440) { // Max 24 hours
        $current_minute = (int)$next->format('i');
        $current_hour = (int)$next->format('H');
        $current_day = (int)$next->format('d');
        $current_month = (int)$next->format('m');
        $current_weekday = (int)$next->format('w');
        
        $matches = true;
        
        // Match minute
        if ($minute !== '*' && !matchesCronValue($minute, $current_minute)) {
            $matches = false;
        }
        
        // Match hour
        if ($hour !== '*' && !matchesCronValue($hour, $current_hour)) {
            $matches = false;
        }
        
        // Match day
        if ($day !== '*' && !matchesCronValue($day, $current_day)) {
            $matches = false;
        }
        
        // Match month
        if ($month !== '*' && !matchesCronValue($month, $current_month)) {
            $matches = false;
        }
        
        // Match weekday
        if ($weekday !== '*' && !matchesCronValue($weekday, $current_weekday)) {
            $matches = false;
        }
        
        if ($matches) {
            return $next->format('Y-m-d H:i:s');
        }
        
        $next->modify('+1 minute');
        $attempts++;
    }
    
    // If no match found in 24 hours, return next hour
    $next = clone $now;
    $next->modify('+1 hour');
    $next->setTime((int)$next->format('H'), 0, 0);
    return $next->format('Y-m-d H:i:s');
}

/**
 * Check if a value matches cron pattern
 */
function matchesCronValue($pattern, $value) {
    // Exact match
    if ($pattern === (string)$value) {
        return true;
    }
    
    // Wildcard
    if ($pattern === '*') {
        return true;
    }
    
    // Range (e.g., 1-5)
    if (preg_match('/^(\d+)-(\d+)$/', $pattern, $matches)) {
        $min = (int)$matches[1];
        $max = (int)$matches[2];
        return $value >= $min && $value <= $max;
    }
    
    // List (e.g., 1,3,5)
    if (strpos($pattern, ',') !== false) {
        $values = explode(',', $pattern);
        return in_array((string)$value, $values);
    }
    
    // Step (e.g., */5)
    if (preg_match('/^\*\/(\d+)$/', $pattern, $matches)) {
        $step = (int)$matches[1];
        return $value % $step === 0;
    }
    
    return false;
}
?>


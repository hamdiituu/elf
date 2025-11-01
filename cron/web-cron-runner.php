<?php
/**
 * Web-Based Cron Runner
 * 
 * This script checks the cron_jobs table and runs enabled cron jobs
 * that are scheduled to run now.
 * 
 * This should be called every minute via system crontab:
 * * * * * * /usr/bin/php /path/to/your/project/cron/web-cron-runner.php
 */

set_time_limit(300); // 5 minutes max

// Load dependencies
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/common/cron-helper.php';

try {
    $db = getDB();
    
    // Get enabled cron jobs that are due to run
    $now = date('Y-m-d H:i:00'); // Round to minute
    $now_timestamp = time();
    
    // Get all enabled cron jobs
    $jobs = $db->query("SELECT * FROM cron_jobs WHERE enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($jobs as $job) {
        // Check if this job should run now based on cron expression
        if (shouldRunNow($job['schedule'], $job['last_run_at'], $job['next_run_at'])) {
            $cron_name = $job['name'];
            $start_time = microtime(true);
            $log_id = cronLog($cron_name, 'started', "Scheduled run");
            
            try {
                // Create temporary file with wrapped code
                $temp_file = tempnam(sys_get_temp_dir(), 'web_cron_' . $cron_name . '_');
                
                // Clean user code - ensure it ends properly
                $user_code = trim($job['code']);
                if (!empty($user_code) && substr($user_code, -1) !== ';' && substr($user_code, -1) !== '}') {
                    $user_code .= ';';
                }
                $user_code .= PHP_EOL;
                
                $wrapped_code = '<?php
require_once "' . __DIR__ . '/../config/config.php";
require_once "' . __DIR__ . '/common/cron-helper.php";

$cron_name = "' . addslashes($cron_name) . '";
$start_time = microtime(true);
$log_id = cronLog($cron_name, "started", "Scheduled run");

function calculateNextRun($cron_expr) {
    $parts = explode(" ", trim($cron_expr));
    if (count($parts) !== 5) return null;
    
    list($minute, $hour, $day, $month, $weekday) = $parts;
    $now = new DateTime();
    $next = clone $now;
    
    if ($minute === "*" && $hour === "*" && $day === "*" && $month === "*" && $weekday === "*") {
        $next->modify("+1 minute");
        return $next->format("Y-m-d H:i:s");
    }
    
    $next->modify("+1 minute");
    return $next->format("Y-m-d H:i:s");
}

try {
    $db = getDB();
    
' . $user_code . '
    
    $execution_time = (microtime(true) - $start_time) * 1000;
    cronLog($cron_name, "success", "Cron job completed successfully", $execution_time);
    
    // Update last run time and calculate next run
    $stmt = $db->prepare("UPDATE cron_jobs SET last_run_at = CURRENT_TIMESTAMP, next_run_at = ? WHERE id = ?");
    $next_run = calculateNextRun("' . addslashes($job['schedule']) . '");
    $stmt->execute([$next_run, ' . $job['id'] . ']);
} catch (Exception $e) {
    $execution_time = (microtime(true) - $start_time) * 1000;
    cronLog($cron_name, "failed", "Cron job failed", $execution_time, $e->getMessage());
    
    // Still update last run time even on failure
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE cron_jobs SET last_run_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([' . $job['id'] . ']);
    } catch (Exception $e2) {
        // Ignore
    }
    
    throw $e;
}
?>';
                
                file_put_contents($temp_file, $wrapped_code);
                
                // Execute in background
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    // Windows
                    pclose(popen("start /B php " . escapeshellarg($temp_file) . " > NUL 2>&1", "r"));
                } else {
                    // Unix/Linux
                    exec("php " . escapeshellarg($temp_file) . " > /dev/null 2>&1 &");
                }
                
                // Clean up temp file after a delay (give it time to execute)
                // Note: In production, you might want to handle this differently
                
            } catch (Exception $e) {
                $execution_time = (microtime(true) - $start_time) * 1000;
                cronLog($cron_name, 'failed', "Cron job failed to start", $execution_time, $e->getMessage());
                error_log("Failed to run cron job '{$cron_name}': " . $e->getMessage());
            }
        }
    }
    
} catch (Exception $e) {
    error_log("Cron runner error: " . $e->getMessage());
    exit(1);
}

/**
 * Check if cron job should run now based on schedule
 */
function shouldRunNow($schedule, $last_run_at, $next_run_at) {
    // If next_run_at is set and we've passed it, run
    if ($next_run_at) {
        if (strtotime($next_run_at) <= time()) {
            return true;
        }
        return false;
    }
    
    // Otherwise, check cron expression
    $parts = explode(' ', trim($schedule));
    if (count($parts) !== 5) {
        return false;
    }
    
    list($minute, $hour, $day, $month, $weekday) = $parts;
    
    // Simple check: if all are *, run every minute
    if ($minute === '*' && $hour === '*' && $day === '*' && $month === '*' && $weekday === '*') {
        // If never run, or last run was more than 1 minute ago
        if (!$last_run_at || (time() - strtotime($last_run_at)) >= 60) {
            return true;
        }
    }
    
    // For other schedules, we'll calculate next_run_at on create/update
    // and use that for scheduling
    return false;
}

/**
 * Calculate next run time from cron expression
 */
function calculateNextRun($cron_expr) {
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
    
    // For more complex expressions, calculate properly
    // This is a simplified version - for production, use a proper cron parser
    
    // Try to increment by 1 minute for now (will be improved)
    $next->modify('+1 minute');
    
    // Check if this time matches the schedule
    $current_minute = (int)$next->format('i');
    $current_hour = (int)$next->format('H');
    $current_day = (int)$next->format('d');
    $current_month = (int)$next->format('m');
    $current_weekday = (int)$next->format('w');
    
    // Simple matching (for production, use proper cron parser)
    $matches = true;
    
    if ($minute !== '*' && (int)$minute !== $current_minute) {
        $matches = false;
    }
    if ($hour !== '*' && (int)$hour !== $current_hour) {
        $matches = false;
    }
    if ($day !== '*' && (int)$day !== $current_day) {
        $matches = false;
    }
    if ($month !== '*' && (int)$month !== $current_month) {
        $matches = false;
    }
    if ($weekday !== '*' && (int)$weekday !== $current_weekday) {
        $matches = false;
    }
    
    if ($matches) {
        return $next->format('Y-m-d H:i:s');
    }
    
    // If doesn't match, add another minute (simplified)
    $next->modify('+1 minute');
    return $next->format('Y-m-d H:i:s');
}
?>


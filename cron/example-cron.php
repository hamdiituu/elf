<?php
/**
 * Example Cron Job - Runs every 1 minute
 * 
 * This is a template for creating cron jobs that run every minute.
 * 
 * To set up this cron job:
 * 1. Make this file executable: chmod +x example-cron.php
 * 2. Add to crontab: crontab -e
 * 3. Add this line: * * * * * /usr/bin/php /path/to/your/project/cron/example-cron.php >> /path/to/your/project/cron/example-cron.log 2>&1
 */

// Set execution time limit (optional, remove if not needed)
set_time_limit(300); // 5 minutes max

// Load cron helper
require_once __DIR__ . '/common/cron-helper.php';

// Cron job name (automatically detected from filename)
$cron_name = basename(__FILE__, '.php'); // 'example-cron'

// Log file path (optional, for file logging)
$log_file = __DIR__ . '/example-cron.log';

/**
 * Main cron job function
 */
function runCronJob() {
    global $log_file;
    
    try {
        $db = getDB();
        
        // Example: Count active sayımlar
        $stmt = $db->query("SELECT COUNT(*) FROM sayimlar WHERE aktif = 1");
        $active_count = $stmt->fetchColumn();
        
        // Example: Count total products
        $stmt = $db->query("SELECT COUNT(*) FROM urun_tanimi WHERE deleted_at IS NULL");
        $product_count = $stmt->fetchColumn();
        
        // Example: Log statistics
        $message = "Cron job executed successfully - Active sayımlar: {$active_count}, Total products: {$product_count}";
        //cronLogToFile($message, $log_file);
        
        // Add your custom logic here
        // Example: Clean up old data, send notifications, etc.
        
        return ['success' => true, 'message' => $message];
        
    } catch (Exception $e) {
        $error_msg = "Error in cron job: " . $e->getMessage();
        //cronLogToFile($error_msg, $log_file);
        return ['success' => false, 'message' => $error_msg, 'error' => $e->getMessage()];
    }
}

// Run the cron job
$start_time = microtime(true);

// Log start to database
cronLog($cron_name, 'started', 'Cron job başlatıldı');
//cronLogToFile("Starting cron job execution...", $log_file);

// Execute cron job
$result = runCronJob();

$end_time = microtime(true);
$execution_time = round(($end_time - $start_time) * 1000, 2); // milliseconds

// Log finish to database
if ($result['success']) {
    cronLog($cron_name, 'success', $result['message'], $execution_time);
    //cronLogToFile("Cron job completed successfully in {$execution_time}ms", $log_file);
} else {
    cronLog($cron_name, 'failed', $result['message'], $execution_time, $result['error'] ?? null);
    //cronLogToFile("Cron job completed with errors in {$execution_time}ms", $log_file);
}

// Exit with appropriate code
exit($result['success'] ? 0 : 1);
?>


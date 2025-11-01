<?php
require_once '../config/config.php';
requireDeveloper();

$page_title = 'Cron Manager';

$db = getDB();
$error_message = null;
$success_message = null;

// Load cron helper
require_once __DIR__ . '/../cron/common/cron-helper.php';

// Ensure cron_jobs table exists
try {
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
    
    // Create indexes
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_jobs_enabled ON cron_jobs(enabled)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_jobs_next_run_at ON cron_jobs(next_run_at)");
} catch (PDOException $e) {
    // Table might already exist
}

// Handle CRUD operations for web-based cron jobs
$cron_id = $_GET['edit'] ?? null;
$delete_id = $_GET['delete'] ?? null;
$toggle_id = $_GET['toggle'] ?? null;
$edit_cron = null;

// Handle delete
if ($delete_id) {
    try {
        $stmt = $db->prepare("DELETE FROM cron_jobs WHERE id = ?");
        $stmt->execute([$delete_id]);
        $success_message = "Cron job başarıyla silindi!";
        header('Location: cron-manager.php?success=' . urlencode($success_message));
        exit;
    } catch (PDOException $e) {
        $error_message = "Cron job silinirken hata: " . $e->getMessage();
    }
}

// Handle toggle enabled
if ($toggle_id) {
    try {
        $stmt = $db->prepare("UPDATE cron_jobs SET enabled = NOT enabled, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$toggle_id]);
        $current = $db->prepare("SELECT enabled FROM cron_jobs WHERE id = ?");
        $current->execute([$toggle_id]);
        $enabled = $current->fetchColumn();
        $status = $enabled ? 'aktif' : 'pasif';
        $success_message = "Cron job başarıyla {$status} hale getirildi!";
        header('Location: cron-manager.php?success=' . urlencode($success_message));
        exit;
    } catch (PDOException $e) {
        $error_message = "Cron job durumu değiştirilirken hata: " . $e->getMessage();
    }
}

// Handle create/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $code = $_POST['code'] ?? '';
    $schedule = trim($_POST['schedule'] ?? '');
    $enabled = isset($_POST['enabled']) ? 1 : 0;
    
    if (empty($name)) {
        $error_message = "Cron job adı gereklidir!";
    } elseif (empty($code)) {
        $error_message = "Cron job kodu gereklidir!";
    } elseif (empty($schedule)) {
        $error_message = "Zamanlama (schedule) gereklidir!";
    } else {
        try {
            // Validate schedule format (basic cron expression: * * * * *)
            $schedule_parts = explode(' ', $schedule);
            if (count($schedule_parts) !== 5) {
                throw new Exception("Geçersiz zamanlama formatı! Örnek: * * * * * (dakika saat gün ay hafta)");
            }
            
            if ($action === 'create') {
                // Check if name already exists
                $check = $db->prepare("SELECT id FROM cron_jobs WHERE name = ?");
                $check->execute([$name]);
                if ($check->fetch()) {
                    throw new Exception("Bu isimde bir cron job zaten mevcut!");
                }
                
                $stmt = $db->prepare("INSERT INTO cron_jobs (name, description, code, schedule, enabled) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $description, $code, $schedule, $enabled]);
                $success_message = "Cron job başarıyla oluşturuldu!";
            } elseif ($action === 'update') {
                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception("Geçersiz cron job ID!");
                }
                
                // Check if name already exists (excluding current)
                $check = $db->prepare("SELECT id FROM cron_jobs WHERE name = ? AND id != ?");
                $check->execute([$name, $id]);
                if ($check->fetch()) {
                    throw new Exception("Bu isimde bir cron job zaten mevcut!");
                }
                
                $stmt = $db->prepare("UPDATE cron_jobs SET name = ?, description = ?, code = ?, schedule = ?, enabled = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$name, $description, $code, $schedule, $enabled, $id]);
                $success_message = "Cron job başarıyla güncellendi!";
            }
            
            // Calculate next run time for all enabled jobs
            calculateNextRunTime($db);
            
            header('Location: cron-manager.php?success=' . urlencode($success_message));
            exit;
        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
    
    // If there's an error, keep form data for editing
    if ($action === 'update') {
        $cron_id = intval($_POST['id'] ?? 0);
    }
}

// Get cron to edit
if ($cron_id) {
    try {
        $stmt = $db->prepare("SELECT * FROM cron_jobs WHERE id = ?");
        $stmt->execute([$cron_id]);
        $edit_cron = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message = "Cron job yüklenirken hata: " . $e->getMessage();
    }
}

// Get web-based cron jobs from database
$web_cron_jobs = [];
try {
    $stmt = $db->query("SELECT * FROM cron_jobs ORDER BY created_at DESC");
    $web_cron_jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table might not exist yet
}

// File-based cron jobs are no longer supported - all cron jobs are web-based
$file_cron_jobs = [];

// Function to calculate next run time from cron expression
function calculateNextRunTime($db) {
    try {
        $jobs = $db->query("SELECT id, schedule FROM cron_jobs WHERE enabled = 1")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jobs as $job) {
            $next_run = calculateNextRun($job['schedule']);
            if ($next_run) {
                $stmt = $db->prepare("UPDATE cron_jobs SET next_run_at = ? WHERE id = ?");
                $stmt->execute([$next_run, $job['id']]);
            }
        }
    } catch (PDOException $e) {
        error_log("Failed to calculate next run times: " . $e->getMessage());
    }
}

// Simple cron expression parser (returns next run datetime)
function calculateNextRun($cron_expr) {
    $parts = explode(' ', trim($cron_expr));
    if (count($parts) !== 5) {
        return null;
    }
    
    list($minute, $hour, $day, $month, $weekday) = $parts;
    
    $now = new DateTime();
    $next = clone $now;
    
    // Simple implementation: if all are *, run in 1 minute
    if ($minute === '*' && $hour === '*' && $day === '*' && $month === '*' && $weekday === '*') {
        $next->modify('+1 minute');
        return $next->format('Y-m-d H:i:s');
    }
    
    // For more complex expressions, we'll use a simple approach
    // In production, you might want to use a proper cron parser library
    $next->modify('+1 minute'); // Default to next minute
    return $next->format('Y-m-d H:i:s');
}

// Handle manual run for web-based cron
$run_web_cron = $_GET['run_web'] ?? null;
if ($run_web_cron) {
    try {
        $stmt = $db->prepare("SELECT * FROM cron_jobs WHERE id = ?");
        $stmt->execute([$run_web_cron]);
        $cron_job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cron_job) {
            // Create temporary file and execute
            $temp_file = tempnam(sys_get_temp_dir(), 'cron_' . $cron_job['name'] . '_');
            file_put_contents($temp_file, $cron_job['code']);
            
            // Wrap code in proper PHP structure with cron logging
            // Clean user code - ensure it ends with newline
            $user_code = trim($cron_job['code']);
            if (!empty($user_code) && substr($user_code, -1) !== ';' && substr($user_code, -1) !== '}') {
                $user_code .= ';';
            }
            $user_code .= PHP_EOL;
            
            $wrapped_code = '<?php
require_once "' . __DIR__ . '/../config/config.php";
require_once "' . __DIR__ . '/../cron/common/cron-helper.php";

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

$cron_name = "' . addslashes($cron_job['name']) . '";
$start_time = microtime(true);
$log_id = cronLog($cron_name, "started", "Manually triggered");

try {
    $db = getDB();
    
' . $user_code . '
    
    $execution_time = (microtime(true) - $start_time) * 1000;
    cronLog($cron_name, "success", "Cron job completed successfully", $execution_time);
    
    // Update last run time
    $next_run = calculateNextRun("' . addslashes($cron_job['schedule']) . '");
    $stmt = $db->prepare("UPDATE cron_jobs SET last_run_at = CURRENT_TIMESTAMP, next_run_at = ? WHERE id = ?");
    $stmt->execute([$next_run, ' . $cron_job['id'] . ']);
} catch (Exception $e) {
    $execution_time = (microtime(true) - $start_time) * 1000;
    cronLog($cron_name, "failed", "Cron job failed", $execution_time, $e->getMessage());
    
    // Still update last run time even on failure
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE cron_jobs SET last_run_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([' . $cron_job['id'] . ']);
    } catch (Exception $e2) {
        // Ignore update errors
    }
    
    // Do not rethrow - just log the error
    error_log("Cron job failed: " . $e->getMessage());
}
?>';
            
            file_put_contents($temp_file, $wrapped_code);
            
            // Execute in background to prevent site crash
            $output = [];
            $return_var = 0;
            
            // Execute in background (non-blocking)
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows
                pclose(popen("start /B php " . escapeshellarg($temp_file) . " > NUL 2>&1", "r"));
                $success_message = "Cron job '{$cron_job['name']}' arka planda çalıştırılıyor...";
            } else {
                // Unix/Linux - non-blocking
                exec("php " . escapeshellarg($temp_file) . " > /dev/null 2>&1 &", $output, $return_var);
                $success_message = "Cron job '{$cron_job['name']}' arka planda çalıştırılıyor...";
            }
            
            // Clean up temp file after a delay (let it execute first)
            // Note: In production, you might want to handle this differently
            usleep(100000); // Wait 100ms before cleanup
            @unlink($temp_file);
        } else {
            $error_message = "Cron job bulunamadı!";
        }
    } catch (Exception $e) {
        $error_message = "Cron job çalıştırılırken hata: " . $e->getMessage();
    }
}

// Handle daemon start/stop
$daemon_action = $_GET['daemon_action'] ?? null;
if ($daemon_action === 'start') {
    $daemon_path = __DIR__ . '/../cron/cron-daemon.php';
    $lock_file = __DIR__ . '/../cron/cron-daemon.lock';
    
    // Quick check if already running
    if (file_exists($lock_file)) {
        $pid = trim(@file_get_contents($lock_file));
        if ($pid && function_exists('posix_getpgid') && @posix_getpgid($pid)) {
            // Already running
            $success_message = "Cron daemon zaten çalışıyor!";
            header('Location: cron-manager.php?success=' . urlencode($success_message));
            exit;
        } else {
            // Stale lock file
            @unlink($lock_file);
        }
    }
    
    // Start daemon in background (completely non-blocking)
    $log_file = __DIR__ . '/../cron/cron-daemon.log';
    $php_path = PHP_BINARY ?: 'php';
    $cron_dir = dirname($daemon_path);
    $daemon_name = basename($daemon_path);
    $log_name = basename($log_file);
    
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Windows
        $command = sprintf(
            'cd /d %s && start /B "" %s %s >> %s 2>&1',
            escapeshellarg($cron_dir),
            escapeshellarg($php_path),
            escapeshellarg($daemon_name),
            escapeshellarg($log_name)
        );
        pclose(popen($command, 'r'));
    } else {
        // Unix/Linux - run in background completely detached
        $command = sprintf(
            'cd %s && nohup %s %s >> %s 2>&1 &',
            escapeshellarg($cron_dir),
            escapeshellarg($php_path),
            escapeshellarg($daemon_name),
            escapeshellarg($log_name)
        );
        // Execute in background - use shell_exec for truly non-blocking execution
        // Wrap in parentheses and redirect to /dev/null, then background with &
        shell_exec('(' . $command . ') > /dev/null 2>&1 &');
    }
    
    // Redirect immediately - don't wait for daemon
    $success_message = "Cron daemon başlatıldı!";
    header('Location: cron-manager.php?success=' . urlencode($success_message));
    
    // Close connection and continue in background if possible
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    
    exit;
} elseif ($daemon_action === 'stop') {
    $lock_file = __DIR__ . '/../cron/cron-daemon.lock';
    $pid_file = __DIR__ . '/../cron/cron-daemon.pid';
    
    if (file_exists($lock_file)) {
        $pid = trim(@file_get_contents($lock_file));
        if ($pid) {
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows - kill process in background (non-blocking)
                $command = "start /B \"\" taskkill /F /PID $pid > NUL 2>&1";
                $handle = popen($command, 'r');
                if ($handle) {
                    pclose($handle);
                }
            } else {
                // Unix/Linux - send signal in background (non-blocking)
                if (function_exists('posix_kill')) {
                    @posix_kill($pid, SIGTERM);
                } else {
                    // Fallback to kill command in background
                    exec("kill -TERM $pid 2>/dev/null > /dev/null 2>&1 &");
                }
                
                // Force kill after a delay (in background) - don't wait
                exec("(sleep 2 && kill -KILL $pid 2>/dev/null) > /dev/null 2>&1 &");
            }
        }
        @unlink($lock_file);
        @unlink($pid_file);
        
        // Immediately redirect - don't wait for process to stop
        $success_message = "Cron daemon durduruldu!";
        header('Location: cron-manager.php?success=' . urlencode($success_message));
        exit;
    } else {
        $error_message = "Daemon çalışmıyor!";
    }
}

// File-based cron execution is no longer supported

// Get cron logs
$cron_logs = [];
try {
    $cron_logs = getCronLogs(null, null, 50);
} catch (Exception $e) {
    $error_message = "Cron log'ları yüklenirken hata: " . $e->getMessage();
}

// Get latest logs for web-based cron jobs
$latest_logs = [];
foreach ($web_cron_jobs as $cron) {
    $latest = getLatestCronLog($cron['name']);
    if ($latest) {
        $latest_logs[$cron['name']] = $latest;
    }
}

include '../includes/header.php';
?>

<!-- CodeMirror CSS and JS -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/clike/clike.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/closebrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/edit/matchbrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/selection/active-line.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/anyword-hint.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/addon/hint/show-hint.min.css">

<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <div class="mb-8">
                    <h1 class="text-3xl font-bold text-foreground mb-2">Cron Manager</h1>
                    <p class="text-sm text-muted-foreground">Otomatik görev yöneticisi ve zamanlayıcı</p>
                </div>
                
                <?php
                // Check if daemon is running
                $daemon_pid_file = __DIR__ . '/../cron/cron-daemon.pid';
                $daemon_lock_file = __DIR__ . '/../cron/cron-daemon.lock';
                $daemon_running = false;
                $daemon_pid = null;
                
                if (file_exists($daemon_lock_file)) {
                    $pid = trim(file_get_contents($daemon_lock_file));
                    if ($pid && function_exists('posix_getpgid')) {
                        if (posix_getpgid($pid)) {
                            $daemon_running = true;
                            $daemon_pid = $pid;
                        } else {
                            // Stale lock file
                            @unlink($daemon_lock_file);
                            @unlink($daemon_pid_file);
                        }
                    } elseif ($pid) {
                        // On Windows or without posix, assume running if file exists
                        $daemon_running = true;
                        $daemon_pid = $pid;
                    }
                }
                
                // Get cron statistics
                $total_jobs = count($web_cron_jobs);
                $enabled_jobs = 0;
                $disabled_jobs = 0;
                $success_count = 0;
                $failed_count = 0;
                
                foreach ($web_cron_jobs as $job) {
                    if ($job['enabled']) {
                        $enabled_jobs++;
                    } else {
                        $disabled_jobs++;
                    }
                    
                    $latest = $latest_logs[$job['name']] ?? null;
                    if ($latest) {
                        if ($latest['status'] === 'success') {
                            $success_count++;
                        } elseif ($latest['status'] === 'failed') {
                            $failed_count++;
                        }
                    }
                }
                ?>
                
                <!-- Daemon Status -->
                <div class="mb-6 rounded-lg border border-border bg-card text-card-foreground shadow-sm p-4">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="h-12 w-12 rounded-full <?php echo $daemon_running ? 'bg-green-100 dark:bg-green-900/20' : 'bg-red-100 dark:bg-red-900/20'; ?> flex items-center justify-center">
                                <?php if ($daemon_running): ?>
                                    <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                <?php else: ?>
                                    <svg class="h-6 w-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h3 class="text-sm font-semibold text-foreground">Cron Daemon</h3>
                                <p class="text-xs text-muted-foreground">
                                    <?php if ($daemon_running): ?>
                                        Çalışıyor <?php if ($daemon_pid): ?>(PID: <?php echo $daemon_pid; ?>)<?php endif; ?>
                                    <?php else: ?>
                                        Çalışmıyor
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <?php if ($daemon_running): ?>
                                <button
                                    onclick="stopDaemon()"
                                    class="px-4 py-2 text-sm font-medium bg-red-600 text-white hover:bg-red-700 rounded-md transition-colors"
                                >
                                    <svg class="h-4 w-4 mr-1.5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
                                    </svg>
                                    Durdur
                                </button>
                            <?php else: ?>
                                <button
                                    onclick="startDaemon()"
                                    class="px-4 py-2 text-sm font-medium bg-green-600 text-white hover:bg-green-700 rounded-md transition-colors"
                                >
                                    <svg class="h-4 w-4 mr-1.5 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    Başlat
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="rounded-lg border border-border bg-card text-card-foreground p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-muted-foreground mb-1">Toplam Job</p>
                                <p class="text-2xl font-bold text-foreground"><?php echo $total_jobs; ?></p>
                            </div>
                            <div class="h-12 w-12 rounded-full bg-blue-100 dark:bg-blue-900/20 flex items-center justify-center">
                                <svg class="h-6 w-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="rounded-lg border border-border bg-card text-card-foreground p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-muted-foreground mb-1">Aktif Job</p>
                                <p class="text-2xl font-bold text-foreground"><?php echo $enabled_jobs; ?></p>
                            </div>
                            <div class="h-12 w-12 rounded-full bg-green-100 dark:bg-green-900/20 flex items-center justify-center">
                                <svg class="h-6 w-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="rounded-lg border border-border bg-card text-card-foreground p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-muted-foreground mb-1">Başarılı</p>
                                <p class="text-2xl font-bold text-foreground"><?php echo $success_count; ?></p>
                            </div>
                            <div class="h-12 w-12 rounded-full bg-purple-100 dark:bg-purple-900/20 flex items-center justify-center">
                                <svg class="h-6 w-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                        </div>
                    </div>
                    
                    <div class="rounded-lg border border-border bg-card text-card-foreground p-4 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-medium text-muted-foreground mb-1">Başarısız</p>
                                <p class="text-2xl font-bold text-foreground"><?php echo $failed_count; ?></p>
                            </div>
                            <div class="h-12 w-12 rounded-full bg-orange-100 dark:bg-orange-900/20 flex items-center justify-center">
                                <svg class="h-6 w-6 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($error_message): ?>
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 p-4">
                        <div class="flex">
                            <svg class="h-5 w-5 text-red-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <p class="text-sm text-red-800"><?php echo nl2br(htmlspecialchars($error_message)); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php 
                $get_success = $_GET['success'] ?? null;
                if ($success_message || $get_success): ?>
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-4">
                        <div class="flex">
                            <svg class="h-5 w-5 text-green-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <p class="text-sm text-green-800"><?php echo htmlspecialchars($success_message ?? $get_success ?? ''); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="grid gap-6 lg:grid-cols-1">
                    <!-- Web-Based Cron Jobs -->
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                        <div class="p-6 pb-0">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <h3 class="text-lg font-semibold leading-none tracking-tight mb-1">Web Cron Job'ları</h3>
                                    <p class="text-xs text-muted-foreground">Veritabanında kayıtlı otomatik görevler</p>
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    Toplam: <span class="font-semibold text-foreground"><?php echo $total_jobs; ?></span> · 
                                    Aktif: <span class="font-semibold text-green-600"><?php echo $enabled_jobs; ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="p-6 pt-0">
                            <?php if (empty($web_cron_jobs)): ?>
                                <div class="text-center py-12 text-muted-foreground">
                                    <svg class="mx-auto h-12 w-12 text-muted-foreground mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <p class="text-sm font-medium mb-1">Henüz cron job yok</p>
                                    <p class="text-xs">"Yeni Cron Job" butonuna tıklayarak oluşturun.</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-4">
                                    <?php foreach ($web_cron_jobs as $cron): ?>
                                        <?php 
                                        $latest = $latest_logs[$cron['name']] ?? null;
                                        $status_bg_class = 'bg-gray-100 dark:bg-gray-900/30';
                                        $status_text_class = 'text-gray-800 dark:text-gray-200';
                                        $status_text = 'Bilinmiyor';
                                        
                                        if ($latest) {
                                            switch ($latest['status']) {
                                                case 'success':
                                                    $status_bg_class = 'bg-green-100 dark:bg-green-900/30';
                                                    $status_text_class = 'text-green-800 dark:text-green-200';
                                                    $status_text = 'Başarılı';
                                                    break;
                                                case 'failed':
                                                    $status_bg_class = 'bg-red-100 dark:bg-red-900/30';
                                                    $status_text_class = 'text-red-800 dark:text-red-200';
                                                    $status_text = 'Başarısız';
                                                    break;
                                                case 'started':
                                                    $status_bg_class = 'bg-yellow-100 dark:bg-yellow-900/30';
                                                    $status_text_class = 'text-yellow-800 dark:text-yellow-200';
                                                    $status_text = 'Çalışıyor';
                                                    break;
                                            }
                                        }
                                        ?>
                                        <div class="rounded-lg border border-border p-5 bg-card shadow-sm hover:shadow-md transition-all">
                                            <div class="flex items-start justify-between mb-4">
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-3 mb-2">
                                                        <div class="h-10 w-10 rounded-full <?php echo $cron['enabled'] ? 'bg-primary/10' : 'bg-muted'; ?> flex items-center justify-center flex-shrink-0">
                                                            <svg class="h-5 w-5 <?php echo $cron['enabled'] ? 'text-primary' : 'text-muted-foreground'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                            </svg>
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                                                <h4 class="font-semibold text-base text-foreground truncate"><?php echo htmlspecialchars($cron['name']); ?></h4>
                                                                <?php if ($cron['enabled']): ?>
                                                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200 border border-green-200 dark:border-green-800">Aktif</span>
                                                                <?php else: ?>
                                                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-gray-100 dark:bg-gray-900/30 text-gray-800 dark:text-gray-200 border border-gray-200 dark:border-gray-800">Pasif</span>
                                                                <?php endif; ?>
                                                                <?php if ($latest): ?>
                                                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?php echo $status_bg_class . ' ' . $status_text_class; ?> border">
                                                                        <?php echo $status_text; ?>
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if ($cron['description']): ?>
                                                                <p class="text-sm text-muted-foreground mb-2"><?php echo htmlspecialchars($cron['description']); ?></p>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-3">
                                                        <div class="bg-muted/50 dark:bg-muted/30 rounded-md p-2 border border-border">
                                                            <p class="text-xs text-muted-foreground mb-0.5">Zamanlama</p>
                                                            <p class="text-xs font-mono font-semibold text-foreground"><?php echo htmlspecialchars($cron['schedule']); ?></p>
                                                        </div>
                                                        <?php if ($cron['next_run_at']): ?>
                                                            <div class="bg-muted/50 dark:bg-muted/30 rounded-md p-2 border border-border">
                                                                <p class="text-xs text-muted-foreground mb-0.5">Sonraki Çalışma</p>
                                                                <p class="text-xs font-semibold text-foreground"><?php echo date('d.m.Y H:i', strtotime($cron['next_run_at'])); ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($cron['last_run_at']): ?>
                                                            <div class="bg-muted/50 dark:bg-muted/30 rounded-md p-2 border border-border">
                                                                <p class="text-xs text-muted-foreground mb-0.5">Son Çalışma</p>
                                                                <p class="text-xs font-semibold text-foreground"><?php echo date('d.m.Y H:i', strtotime($cron['last_run_at'])); ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($latest && isset($latest['execution_time_ms'])): ?>
                                                            <div class="bg-muted/50 dark:bg-muted/30 rounded-md p-2 border border-border">
                                                                <p class="text-xs text-muted-foreground mb-0.5">Çalışma Süresi</p>
                                                                <p class="text-xs font-semibold text-foreground"><?php echo number_format($latest['execution_time_ms'], 2); ?> ms</p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <?php if ($latest && $latest['message']): ?>
                                                        <div class="mt-3 p-2 bg-muted/30 rounded-md border border-border">
                                                            <p class="text-xs text-muted-foreground mb-1">Son Mesaj:</p>
                                                            <p class="text-xs text-foreground truncate" title="<?php echo htmlspecialchars($latest['message']); ?>">
                                                                <?php echo htmlspecialchars($latest['message']); ?>
                                                            </p>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <div class="flex items-center gap-2 flex-wrap pt-4 border-t border-border">
                                                <a
                                                    href="?run_web=<?php echo $cron['id']; ?>"
                                                    onclick="return confirm('Bu cron job\'ı şimdi çalıştırmak istediğinizden emin misiniz?');"
                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 px-3 py-1.5 transition-colors"
                                                >
                                                    <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    Çalıştır
                                                </a>
                                                <a
                                                    href="?edit=<?php echo $cron['id']; ?>"
                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-secondary text-secondary-foreground hover:bg-secondary/80 px-3 py-1.5 transition-colors"
                                                >
                                                    <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                                    </svg>
                                                    Düzenle
                                                </a>
                                                <a
                                                    href="?toggle=<?php echo $cron['id']; ?>"
                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-3 py-1.5 transition-colors"
                                                >
                                                    <?php if ($cron['enabled']): ?>
                                                        Pasif Yap
                                                    <?php else: ?>
                                                        Aktif Yap
                                                    <?php endif; ?>
                                                </a>
                                                <a
                                                    href="?delete=<?php echo $cron['id']; ?>"
                                                    onclick="return confirm('Bu cron job\'ı silmek istediğinizden emin misiniz?');"
                                                    class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-red-600 text-white hover:bg-red-700 px-3 py-1.5 transition-colors"
                                                >
                                                    <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                    </svg>
                                                    Sil
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- File-Based Cron Jobs -->
                    <?php if (!empty($file_cron_jobs)): ?>
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                        <div class="p-6 pb-0">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <h3 class="text-lg font-semibold leading-none tracking-tight mb-1">Dosya Tabanlı Cron Job'lar</h3>
                                    <p class="text-xs text-muted-foreground">Dosya sistemi tabanlı cron job'lar</p>
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    Toplam: <span class="font-semibold text-foreground"><?php echo count($file_cron_jobs); ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="p-6 pt-0">
                            <div class="space-y-4">
                                <?php foreach ($file_cron_jobs as $cron): ?>
                                    <?php 
                                    $latest = $latest_logs[$cron['name']] ?? null;
                                    $status_bg_class = 'bg-gray-100 dark:bg-gray-900/30';
                                    $status_text_class = 'text-gray-800 dark:text-gray-200';
                                    $status_text = 'Bilinmiyor';
                                    
                                    if ($latest) {
                                        switch ($latest['status']) {
                                            case 'success':
                                                $status_bg_class = 'bg-green-100 dark:bg-green-900/30';
                                                $status_text_class = 'text-green-800 dark:text-green-200';
                                                $status_text = 'Başarılı';
                                                break;
                                            case 'failed':
                                                $status_bg_class = 'bg-red-100 dark:bg-red-900/30';
                                                $status_text_class = 'text-red-800 dark:text-red-200';
                                                $status_text = 'Başarısız';
                                                break;
                                            case 'started':
                                                $status_bg_class = 'bg-yellow-100 dark:bg-yellow-900/30';
                                                $status_text_class = 'text-yellow-800 dark:text-yellow-200';
                                                $status_text = 'Çalışıyor';
                                                break;
                                        }
                                    }
                                    ?>
                                    <div class="rounded-lg border border-border p-5 bg-card shadow-sm hover:shadow-md transition-all">
                                        <div class="flex items-start justify-between mb-4">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-3 mb-2">
                                                    <div class="h-10 w-10 rounded-full bg-blue-100 dark:bg-blue-900/20 flex items-center justify-center flex-shrink-0">
                                                        <svg class="h-5 w-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                        </svg>
                                                    </div>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                                                            <h4 class="font-semibold text-base text-foreground truncate"><?php echo htmlspecialchars($cron['name']); ?></h4>
                                                            <?php if ($latest): ?>
                                                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?php echo $status_bg_class . ' ' . $status_text_class; ?> border">
                                                                    <?php echo $status_text; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <p class="text-xs text-muted-foreground font-mono truncate" title="<?php echo htmlspecialchars($cron['file']); ?>">
                                                            <?php echo htmlspecialchars($cron['file']); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                                
                                                <?php if ($latest): ?>
                                                    <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mt-3">
                                                        <div class="bg-muted/50 dark:bg-muted/30 rounded-md p-2 border border-border">
                                                            <p class="text-xs text-muted-foreground mb-0.5">Son Çalışma</p>
                                                            <p class="text-xs font-semibold text-foreground"><?php echo date('d.m.Y H:i', strtotime($latest['started_at'])); ?></p>
                                                        </div>
                                                        <?php if ($latest['execution_time_ms']): ?>
                                                            <div class="bg-muted/50 dark:bg-muted/30 rounded-md p-2 border border-border">
                                                                <p class="text-xs text-muted-foreground mb-0.5">Çalışma Süresi</p>
                                                                <p class="text-xs font-semibold text-foreground"><?php echo number_format($latest['execution_time_ms'], 2); ?> ms</p>
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($latest['message']): ?>
                                                            <div class="bg-muted/50 dark:bg-muted/30 rounded-md p-2 border border-border">
                                                                <p class="text-xs text-muted-foreground mb-0.5">Mesaj</p>
                                                                <p class="text-xs font-semibold text-foreground truncate" title="<?php echo htmlspecialchars($latest['message']); ?>">
                                                                    <?php echo htmlspecialchars(substr($latest['message'], 0, 30)); ?>...
                                                                </p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="flex items-center gap-2 flex-wrap pt-4 border-t border-border">
                                            <a
                                                href="?run=<?php echo urlencode($cron['name']); ?>"
                                                onclick="return confirm('Bu cron job\'ı şimdi çalıştırmak istediğinizden emin misiniz?');"
                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 px-3 py-1.5 transition-colors"
                                            >
                                                <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                Çalıştır
                                            </a>
                                            <a
                                                href="database-explorer.php?table=cron_log&search=<?php echo urlencode($cron['name']); ?>"
                                                class="inline-flex items-center justify-center rounded-md text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 px-3 py-1.5 transition-colors"
                                            >
                                                <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                </svg>
                                                Loglar
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Cron Logs -->
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                        <div class="p-6 pb-0">
                            <div class="flex items-center justify-between mb-4">
                                <div>
                                    <h3 class="text-lg font-semibold leading-none tracking-tight mb-1">Son Cron Log'ları</h3>
                                    <p class="text-xs text-muted-foreground">Son 50 cron job çalıştırma kaydı</p>
                                </div>
                                <a
                                    href="database-explorer.php?table=cron_log"
                                    class="text-xs font-medium text-primary hover:underline"
                                >
                                    Tümünü Gör →
                                </a>
                            </div>
                        </div>
                        <div class="p-6 pt-0">
                            <?php if (empty($cron_logs)): ?>
                                <div class="text-center py-12 text-muted-foreground">
                                    <svg class="mx-auto h-12 w-12 text-muted-foreground mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                    </svg>
                                    <p class="text-sm font-medium mb-1">Henüz cron log kaydı yok</p>
                                    <p class="text-xs">Cron job'lar çalıştıkça burada görünecektir.</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-3 max-h-[600px] overflow-y-auto">
                                    <?php foreach ($cron_logs as $log): ?>
                                        <?php
                                        $status_bg_class = 'bg-gray-100 dark:bg-gray-900/30';
                                        $status_text_class = 'text-gray-800 dark:text-gray-200';
                                        switch ($log['status']) {
                                            case 'success':
                                                $status_bg_class = 'bg-green-100 dark:bg-green-900/30';
                                                $status_text_class = 'text-green-800 dark:text-green-200';
                                                break;
                                            case 'failed':
                                                $status_bg_class = 'bg-red-100 dark:bg-red-900/30';
                                                $status_text_class = 'text-red-800 dark:text-red-200';
                                                break;
                                            case 'started':
                                                $status_bg_class = 'bg-yellow-100 dark:bg-yellow-900/30';
                                                $status_text_class = 'text-yellow-800 dark:text-yellow-200';
                                                break;
                                        }
                                        ?>
                                        <div class="rounded-lg border border-border p-4 bg-card shadow-sm hover:shadow-md transition-all">
                                            <div class="flex items-center justify-between mb-2">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-semibold text-sm text-foreground"><?php echo htmlspecialchars($log['cron_name']); ?></span>
                                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium <?php echo $status_bg_class . ' ' . $status_text_class; ?> border">
                                                        <?php 
                                                        switch ($log['status']) {
                                                            case 'success': echo 'Başarılı'; break;
                                                            case 'failed': echo 'Başarısız'; break;
                                                            case 'started': echo 'Başlatıldı'; break;
                                                            default: echo $log['status'];
                                                        }
                                                        ?>
                                                    </span>
                                                </div>
                                                <span class="text-xs text-muted-foreground font-medium">
                                                    <?php echo date('d.m.Y H:i:s', strtotime($log['started_at'])); ?>
                                                </span>
                                            </div>
                                            <?php if ($log['message']): ?>
                                                <p class="text-xs text-foreground mb-2"><?php echo htmlspecialchars($log['message']); ?></p>
                                            <?php endif; ?>
                                            <div class="flex items-center gap-4 text-xs text-muted-foreground">
                                                <?php if ($log['execution_time_ms']): ?>
                                                    <span class="flex items-center gap-1">
                                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                        <?php echo number_format($log['execution_time_ms'], 2); ?> ms
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($log['finished_at']): ?>
                                                    <span class="flex items-center gap-1">
                                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                        </svg>
                                                        Bitti: <?php echo date('H:i:s', strtotime($log['finished_at'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($log['error_message']): ?>
                                                    <span class="flex items-center gap-1 text-red-600 dark:text-red-400">
                                                        <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                        Hata: <?php echo htmlspecialchars(substr($log['error_message'], 0, 50)); ?>...
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>

<!-- Create/Edit Cron Job Modal -->
<div id="create-cron-dialog" class="fixed inset-0 hidden items-center justify-center z-50" onclick="if(event.target === this) hideCreateCronModal()" style="background-color: rgba(0, 0, 0, 0.3) !important;">
    <div class="border border-border rounded-lg shadow-lg p-6 max-w-4xl w-full mx-4 max-h-[90vh] overflow-y-auto" onclick="event.stopPropagation()" style="background-color: hsl(var(--background)) !important; z-index: 51;">
        <h3 class="text-lg font-semibold mb-4" id="cron-modal-title"><?php echo $edit_cron ? 'Cron Job Düzenle' : 'Yeni Cron Job Oluştur'; ?></h3>
        <form method="POST" action="" id="cron-form">
            <input type="hidden" name="action" value="<?php echo $edit_cron ? 'update' : 'create'; ?>">
            <?php if ($edit_cron): ?>
                <input type="hidden" name="id" value="<?php echo $edit_cron['id']; ?>">
            <?php endif; ?>
            <div class="space-y-4">
                <div>
                    <label for="cron_name" class="block text-sm font-medium mb-2">Cron Job Adı:</label>
                    <input
                        type="text"
                        name="name"
                        id="cron_name"
                        value="<?php echo htmlspecialchars($edit_cron['name'] ?? ''); ?>"
                        required
                        pattern="[a-zA-Z_][a-zA-Z0-9_]*"
                        class="w-full px-3 py-2 border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-2 focus:ring-ring font-mono"
                        placeholder="ornek_cron"
                    >
                    <p class="text-xs text-muted-foreground mt-1">Sadece harf, rakam ve alt çizgi kullanılabilir</p>
                </div>
                
                <div>
                    <label for="cron_description" class="block text-sm font-medium mb-2">Açıklama (Opsiyonel):</label>
                    <input
                        type="text"
                        name="description"
                        id="cron_description"
                        value="<?php echo htmlspecialchars($edit_cron['description'] ?? ''); ?>"
                        class="w-full px-3 py-2 border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-2 focus:ring-ring"
                        placeholder="Bu cron job'ın ne yaptığını açıklayın"
                    >
                </div>
                
                <div>
                    <label for="cron_schedule" class="block text-sm font-medium mb-2">Zamanlama (Cron Expression):</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            name="schedule"
                            id="cron_schedule"
                            value="<?php echo htmlspecialchars($edit_cron['schedule'] ?? '* * * * *'); ?>"
                            required
                            pattern="[0-9*\/\-, ]+"
                            class="flex-1 px-3 py-2 border border-input bg-background text-foreground rounded-md focus:outline-none focus:ring-2 focus:ring-ring font-mono text-sm"
                            placeholder="* * * * *"
                        >
                        <button
                            type="button"
                            onclick="showScheduleHelper()"
                            class="px-3 py-2 text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 rounded-md transition-colors"
                            title="Zamanlama Yardımcısı"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </button>
                    </div>
                    <p class="text-xs text-muted-foreground mt-1">
                        Format: <code class="font-mono">dakika saat gün ay hafta</code><br>
                        Örnekler: <code class="font-mono">* * * * *</code> (her dakika), <code class="font-mono">0 * * * *</code> (her saat), <code class="font-mono">0 0 * * *</code> (her gün)
                    </p>
                    <div id="schedule-helper" class="hidden mt-2 rounded-md bg-blue-50 border border-blue-200 p-3">
                        <p class="text-xs text-blue-800 font-semibold mb-2">Önceden Tanımlı Zamanlamalar:</p>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <button type="button" onclick="setSchedule('* * * * *')" class="text-left px-2 py-1 bg-white rounded hover:bg-blue-100 transition-colors">
                                <strong>* * * * *</strong> - Her dakika
                            </button>
                            <button type="button" onclick="setSchedule('*/5 * * * *')" class="text-left px-2 py-1 bg-white rounded hover:bg-blue-100 transition-colors">
                                <strong>*/5 * * * *</strong> - Her 5 dakika
                            </button>
                            <button type="button" onclick="setSchedule('0 * * * *')" class="text-left px-2 py-1 bg-white rounded hover:bg-blue-100 transition-colors">
                                <strong>0 * * * *</strong> - Her saat başı
                            </button>
                            <button type="button" onclick="setSchedule('0 0 * * *')" class="text-left px-2 py-1 bg-white rounded hover:bg-blue-100 transition-colors">
                                <strong>0 0 * * *</strong> - Her gün gece yarısı
                            </button>
                            <button type="button" onclick="setSchedule('0 0 * * 0')" class="text-left px-2 py-1 bg-white rounded hover:bg-blue-100 transition-colors">
                                <strong>0 0 * * 0</strong> - Her pazar gece yarısı
                            </button>
                            <button type="button" onclick="setSchedule('0 9 * * 1-5')" class="text-left px-2 py-1 bg-white rounded hover:bg-blue-100 transition-colors">
                                <strong>0 9 * * 1-5</strong> - Hafta içi her gün 09:00
                            </button>
                        </div>
                    </div>
                </div>
                
                <div>
                    <label for="cron_code" class="block text-sm font-medium mb-2">Cron Job Kodu (PHP):</label>
                    <div id="code-editor-wrapper" class="relative rounded-md border border-input bg-background">
                        <div class="bg-muted/50 px-3 py-2 border-b border-input flex items-center justify-between">
                            <span class="text-xs font-medium text-foreground">PHP Kodu</span>
                            <button
                                type="button"
                                onclick="toggleFullscreenCron()"
                                class="px-2 py-1 text-xs font-medium bg-primary text-primary-foreground hover:bg-primary/90 rounded transition-colors"
                                id="fullscreen-cron-btn"
                            >
                                <svg class="h-3 w-3 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                                </svg>
                                Tam Ekran
                            </button>
                        </div>
                        <textarea
                            name="code"
                            id="cron_code"
                            required
                            rows="15"
                            class="w-full px-3 py-2 border-0 bg-background text-foreground font-mono text-sm focus:outline-none focus:ring-0 resize-none"
                            placeholder="PHP kodunuzu buraya yazın..."
                        ><?php echo htmlspecialchars($edit_cron['code'] ?? ''); ?></textarea>
                    </div>
                    <p class="text-xs text-muted-foreground mt-1">
                        Not: Kod otomatik olarak cron logging ile sarılacaktır. <code>$db</code> değişkeni hazır olacaktır.
                    </p>
                </div>
                
                <div class="flex items-center gap-2">
                    <input
                        type="checkbox"
                        name="enabled"
                        id="cron_enabled"
                        <?php echo (!$edit_cron || $edit_cron['enabled']) ? 'checked' : ''; ?>
                        class="rounded border-input"
                    >
                    <label for="cron_enabled" class="text-sm font-medium text-foreground">
                        Aktif
                    </label>
                </div>
                
                <div class="flex items-center gap-2 justify-end">
                    <button
                        type="button"
                        onclick="hideCreateCronModal()"
                        class="px-4 py-2 text-sm font-medium bg-muted text-muted-foreground hover:bg-muted/80 rounded-md transition-colors"
                    >
                        İptal
                    </button>
                    <button
                        type="submit"
                        class="px-4 py-2 text-sm font-medium bg-primary text-primary-foreground hover:bg-primary/90 rounded-md transition-colors"
                    >
                        <?php echo $edit_cron ? 'Güncelle' : 'Oluştur'; ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Cron modal functions
let cronEditor = null;
let isCronFullscreen = false;

function showCreateCronModal() {
    document.getElementById('create-cron-dialog').classList.remove('hidden');
    document.getElementById('create-cron-dialog').classList.add('flex');
    
    // Initialize CodeMirror if not already initialized
    if (!cronEditor) {
        const codeTextarea = document.getElementById('cron_code');
        const originalValue = codeTextarea.value;
        
        // Default example code if empty
        const exampleCode = `// Veritabanı bağlantısı (otomatik hazır)
$db = getDB();

// Örnek 1: SELECT sorgusu ile veri çekme
$stmt = $db->query('SELECT COUNT(*) as total FROM sayimlar WHERE aktif = 1');
$result = $stmt->fetch(PDO::FETCH_ASSOC);
$active_count = $result['total'];
echo "Aktif sayım sayısı: " . $active_count . PHP_EOL;

// Örnek 2: INSERT ile veritabanına kayıt ekleme
$stmt = $db->prepare('INSERT INTO sayim_icerikleri (sayim_no, barkod, okutma_zamani) VALUES (?, ?, CURRENT_TIMESTAMP)');
$stmt->execute([1, '1234567890']);
$inserted_id = $db->lastInsertId();
echo "Kayıt eklendi. ID: " . $inserted_id . PHP_EOL;

// Örnek 3: UPDATE ile veri güncelleme
$stmt = $db->prepare('UPDATE sayimlar SET aktif = 0 WHERE id = ?');
$stmt->execute([1]);
$affected = $stmt->rowCount();
echo "Güncellenen kayıt sayısı: " . $affected . PHP_EOL;

// Örnek 4: Çoklu kayıt sorgulama ve işleme
$stmt = $db->query('SELECT * FROM urun_tanimi WHERE deleted_at IS NULL LIMIT 10');
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($products as $product) {
    echo "Ürün: " . $product['barkod'] . " - " . $product['urun_aciklamasi'] . PHP_EOL;
}

// Örnek 5: DELETE ile kayıt silme (soft delete için)
$stmt = $db->prepare('UPDATE urun_tanimi SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?');
$stmt->execute([1]);
$deleted = $stmt->rowCount();
echo "Silinen kayıt sayısı: " . $deleted . PHP_EOL;`;
        
        if (codeTextarea) {
            cronEditor = CodeMirror.fromTextArea(codeTextarea, {
                mode: {
                    name: 'php',
                    startOpen: true
                },
                theme: 'monokai',
                lineNumbers: true,
                lineWrapping: true,
                autoCloseBrackets: true,
                matchBrackets: true,
                styleActiveLine: true,
                indentUnit: 4,
                indentWithTabs: false,
                value: originalValue || exampleCode, // Set initial value
                extraKeys: {
                    "Ctrl-Space": function(cm) {
                        CodeMirror.commands.autocomplete(cm, CodeMirror.hint.anyword);
                    },
                    "Ctrl-F": "findPersistent",
                    "Tab": function(cm) {
                        if (cm.somethingSelected()) {
                            cm.indentSelection("add");
                        } else {
                            cm.replaceSelection(Array(cm.getOption("indentUnit") + 1).join(" "), "end", "+input");
                        }
                    }
                },
                hintOptions: {
                    hint: function(editor) {
                        const cursor = editor.getCursor();
                        const token = editor.getTokenAt(cursor);
                        const word = token.string;
                        
                        // Custom hints for cron job context
                        const hints = [
                            'getDB', 'db', '$db',
                            'query', 'prepare', 'execute', 'fetchAll', 'fetch', 'fetchColumn',
                            'PDO::FETCH_ASSOC', 'PDO::FETCH_OBJ', 'PDO::FETCH_NUM',
                            'array', 'count', 'isset', 'empty', 'trim', 'htmlspecialchars',
                            'json_encode', 'json_decode', 'date', 'time', 'strtotime',
                            'echo', 'print', 'var_dump', 'error_log',
                            'foreach', 'for', 'while', 'if', 'else', 'switch', 'case',
                            'try', 'catch', 'Exception', 'PDOException'
                        ];
                        
                        const filtered = hints.filter(h => h.toLowerCase().startsWith(word.toLowerCase()));
                        
                        return {
                            list: filtered,
                            from: CodeMirror.Pos(cursor.line, token.start),
                            to: CodeMirror.Pos(cursor.line, token.end)
                        };
                    },
                    completeSingle: false
                }
            });
            
            // Set initial value if empty (for new cron jobs)
            if (!originalValue || originalValue.trim() === '') {
                cronEditor.setValue(exampleCode);
                cronEditor.save(); // Sync with textarea
            }
            
            // Sync with textarea on change
            cronEditor.on('change', function(cm) {
                cm.save();
            });
            
            // Auto-trigger autocomplete
            cronEditor.on('inputRead', function(cm, change) {
                if (change.text[0].length > 0 && change.text[0][0].match(/[a-zA-Z]/)) {
                    setTimeout(function() {
                        CodeMirror.commands.autocomplete(cm);
                    }, 100);
                }
            });
        }
    } else {
        cronEditor.refresh();
    }
    
    document.getElementById('cron_name').focus();
}

function hideCreateCronModal() {
    document.getElementById('create-cron-dialog').classList.add('hidden');
    document.getElementById('create-cron-dialog').classList.remove('flex');
    
    if (cronEditor) {
        cronEditor.save();
    }
    
    // Clear edit parameter from URL
    if (window.location.search.includes('edit=')) {
        window.history.replaceState({}, '', window.location.pathname + (window.location.search.replace(/[?&]edit=[^&]*/, '').replace(/^&/, '?')));
    }
}

function showScheduleHelper() {
    const helper = document.getElementById('schedule-helper');
    helper.classList.toggle('hidden');
}

function setSchedule(schedule) {
    document.getElementById('cron_schedule').value = schedule;
    document.getElementById('schedule-helper').classList.add('hidden');
}

function toggleFullscreenCron() {
    const wrapper = document.getElementById('code-editor-wrapper');
    const btn = document.getElementById('fullscreen-cron-btn');
    
    if (!isCronFullscreen) {
        wrapper.classList.add('fullscreen');
        btn.innerHTML = `
            <svg class="h-3 w-3 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
            Çık
        `;
        isCronFullscreen = true;
        if (cronEditor) {
            setTimeout(() => cronEditor.refresh(), 100);
            cronEditor.focus();
        }
    } else {
        wrapper.classList.remove('fullscreen');
        btn.innerHTML = `
            <svg class="h-3 w-3 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
            </svg>
            Tam Ekran
        `;
        isCronFullscreen = false;
        if (cronEditor) {
            setTimeout(() => cronEditor.refresh(), 100);
        }
    }
}

// Initialize modal if edit parameter exists
<?php if ($edit_cron): ?>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() {
        showCreateCronModal();
    }, 100);
});
<?php endif; ?>

// Close modal on Escape key (if not in fullscreen)
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        if (isCronFullscreen) {
            toggleFullscreenCron();
        } else {
            const dialog = document.getElementById('create-cron-dialog');
            if (dialog && !dialog.classList.contains('hidden')) {
                hideCreateCronModal();
            }
        }
    }
});

// Daemon control functions
function startDaemon() {
    if (confirm('Cron daemon başlatılsın mı? Bu işlem arka planda çalışacak bir process başlatır ve zamanı gelen cron job\'ları otomatik çalıştırır.')) {
        // Show loading indicator
        const btn = event.target.closest('button');
        if (btn) {
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="animate-spin">⏳</span> Başlatılıyor...';
            
            // Redirect immediately - server will handle background process
            window.location.href = '?daemon_action=start';
        } else {
            window.location.href = '?daemon_action=start';
        }
    }
}

function stopDaemon() {
    if (confirm('Cron daemon durdurulsun mu? Tüm otomatik cron job\'ları durur.')) {
        // Show loading indicator
        const btn = event.target.closest('button');
        if (btn) {
            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="animate-spin">⏳</span> Durduruluyor...';
            
            // Redirect immediately - server will handle background process
            window.location.href = '?daemon_action=stop';
        } else {
            window.location.href = '?daemon_action=stop';
        }
    }
}
</script>

<style>
.CodeMirror {
    border: 1px solid hsl(var(--input));
    border-radius: 0.375rem;
    height: 500px;
    font-size: 14px;
    font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', 'Consolas', monospace;
}

.CodeMirror-activeline-background {
    background: hsl(var(--muted) / 0.3);
}

/* Fullscreen styles */
#code-editor-wrapper.fullscreen {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 9999;
    background: hsl(var(--background));
    padding: 20px;
    border-radius: 0;
}

#code-editor-wrapper.fullscreen .CodeMirror {
    height: calc(100vh - 100px);
    border-radius: 0.5rem;
}

#code-editor-wrapper.fullscreen #fullscreen-cron-btn {
    position: absolute;
    top: 20px;
    right: 20px;
    z-index: 10000;
}
</style>


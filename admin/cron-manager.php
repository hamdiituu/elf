<?php
require_once '../config/config.php';
requireLogin();

$page_title = 'Cron Manager - Vira Stok Sistemi';

$db = getDB();
$error_message = null;
$success_message = null;

// Load cron helper
require_once __DIR__ . '/../cron/common/cron-helper.php';

// Get available cron jobs from cron directory
$cron_dir = __DIR__ . '/../cron';
$cron_jobs = [];

if (is_dir($cron_dir)) {
    $files = scandir($cron_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && $file !== 'common' && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
            $cron_jobs[] = [
                'name' => basename($file, '.php'),
                'file' => $file,
                'path' => realpath($cron_dir . '/' . $file)
            ];
        }
    }
}

// Handle cron execution
$run_cron = $_GET['run'] ?? null;
if ($run_cron) {
    $cron_file = $cron_dir . '/' . $run_cron . '.php';
    
    if (file_exists($cron_file)) {
        // Execute cron job in background or capture output
        $output = [];
        $return_var = 0;
        
        // Run cron and capture output
        exec("php " . escapeshellarg($cron_file) . " 2>&1", $output, $return_var);
        
        if ($return_var === 0) {
            $success_message = "Cron job '{$run_cron}' başarıyla çalıştırıldı!";
        } else {
            $error_message = "Cron job '{$run_cron}' çalıştırılırken hata oluştu: " . implode("\n", $output);
        }
    } else {
        $error_message = "Cron job dosyası bulunamadı: {$run_cron}";
    }
}

// Get cron logs
$cron_logs = [];
try {
    $cron_logs = getCronLogs(null, null, 50);
} catch (Exception $e) {
    $error_message = "Cron log'ları yüklenirken hata: " . $e->getMessage();
}

// Get latest log for each cron
$latest_logs = [];
foreach ($cron_jobs as $cron) {
    $latest = getLatestCronLog($cron['name']);
    if ($latest) {
        $latest_logs[$cron['name']] = $latest;
    }
}

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 md:px-8">
                <h1 class="text-3xl font-bold text-foreground mb-8">Cron Manager</h1>
                
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
                
                <?php if ($success_message): ?>
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 p-4">
                        <div class="flex">
                            <svg class="h-5 w-5 text-green-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                            </svg>
                            <p class="text-sm text-green-800"><?php echo htmlspecialchars($success_message); ?></p>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="grid gap-6 lg:grid-cols-2">
                    <!-- Cron Jobs List -->
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                        <div class="p-6 pb-0">
                            <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Cron Job'lar</h3>
                        </div>
                        <div class="p-6 pt-0">
                            <?php if (empty($cron_jobs)): ?>
                                <div class="text-center py-8 text-muted-foreground">
                                    Cron job bulunamadı.
                                </div>
                            <?php else: ?>
                                <div class="space-y-3">
                                    <?php foreach ($cron_jobs as $cron): ?>
                                        <?php 
                                        $latest = $latest_logs[$cron['name']] ?? null;
                                        $status_color = 'gray';
                                        $status_text = 'Bilinmiyor';
                                        
                                        if ($latest) {
                                            switch ($latest['status']) {
                                                case 'success':
                                                    $status_color = 'green';
                                                    $status_text = 'Başarılı';
                                                    break;
                                                case 'failed':
                                                    $status_color = 'red';
                                                    $status_text = 'Başarısız';
                                                    break;
                                                case 'started':
                                                    $status_color = 'yellow';
                                                    $status_text = 'Çalışıyor';
                                                    break;
                                            }
                                        }
                                        ?>
                                        <div class="rounded-md border border-border p-4 bg-muted/30">
                                            <div class="flex items-center justify-between mb-3">
                                                <div class="flex-1">
                                                    <h4 class="font-medium text-sm text-foreground"><?php echo htmlspecialchars($cron['name']); ?></h4>
                                                    <p class="text-xs text-muted-foreground font-mono mt-1"><?php echo htmlspecialchars($cron['file']); ?></p>
                                                </div>
                                                <div class="ml-4">
                                                    <?php if ($latest): ?>
                                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-<?php echo $status_color; ?>-100 text-<?php echo $status_color; ?>-800">
                                                            <?php echo $status_text; ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            
                                            <?php if ($latest): ?>
                                                <div class="mb-3 text-xs text-muted-foreground space-y-1">
                                                    <div>Son çalışma: <?php echo date('d.m.Y H:i:s', strtotime($latest['started_at'])); ?></div>
                                                    <?php if ($latest['execution_time_ms']): ?>
                                                        <div>Süre: <?php echo number_format($latest['execution_time_ms'], 2); ?> ms</div>
                                                    <?php endif; ?>
                                                    <?php if ($latest['message']): ?>
                                                        <div class="truncate" title="<?php echo htmlspecialchars($latest['message']); ?>">
                                                            Mesaj: <?php echo htmlspecialchars(substr($latest['message'], 0, 60)); ?>...
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="flex items-center gap-2">
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
                                                    href="database-explorer.php?table=cron_log&filter_cron_name=<?php echo urlencode($cron['name']); ?>"
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
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Cron Logs -->
                    <div class="rounded-lg border border-border bg-card text-card-foreground shadow-sm">
                        <div class="p-6 pb-0">
                            <h3 class="text-lg font-semibold leading-none tracking-tight mb-4">Son Cron Log'ları</h3>
                        </div>
                        <div class="p-6 pt-0">
                            <?php if (empty($cron_logs)): ?>
                                <div class="text-center py-8 text-muted-foreground">
                                    Henüz cron log kaydı yok.
                                </div>
                            <?php else: ?>
                                <div class="space-y-3 max-h-[600px] overflow-y-auto">
                                    <?php foreach ($cron_logs as $log): ?>
                                        <?php
                                        $status_color = 'gray';
                                        switch ($log['status']) {
                                            case 'success':
                                                $status_color = 'green';
                                                break;
                                            case 'failed':
                                                $status_color = 'red';
                                                break;
                                            case 'started':
                                                $status_color = 'yellow';
                                                break;
                                        }
                                        ?>
                                        <div class="rounded-md border border-border p-3 bg-muted/20">
                                            <div class="flex items-center justify-between mb-2">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-medium text-sm"><?php echo htmlspecialchars($log['cron_name']); ?></span>
                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-<?php echo $status_color; ?>-100 text-<?php echo $status_color; ?>-800">
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
                                                <span class="text-xs text-muted-foreground">
                                                    <?php echo date('d.m.Y H:i:s', strtotime($log['started_at'])); ?>
                                                </span>
                                            </div>
                                            <?php if ($log['message']): ?>
                                                <p class="text-xs text-muted-foreground mb-1"><?php echo htmlspecialchars($log['message']); ?></p>
                                            <?php endif; ?>
                                            <div class="flex items-center gap-4 text-xs text-muted-foreground">
                                                <?php if ($log['execution_time_ms']): ?>
                                                    <span>Süre: <?php echo number_format($log['execution_time_ms'], 2); ?> ms</span>
                                                <?php endif; ?>
                                                <?php if ($log['finished_at']): ?>
                                                    <span>Bitti: <?php echo date('H:i:s', strtotime($log['finished_at'])); ?></span>
                                                <?php endif; ?>
                                                <?php if ($log['error_message']): ?>
                                                    <span class="text-red-600">Hata: <?php echo htmlspecialchars(substr($log['error_message'], 0, 50)); ?>...</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-4">
                                    <a
                                        href="database-explorer.php?table=cron_log"
                                        class="inline-flex items-center justify-center rounded-md text-sm font-medium text-primary hover:underline"
                                    >
                                        Tüm logları görüntüle →
                                    </a>
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


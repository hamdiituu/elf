<?php
// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Get dynamic pages from database
$dynamic_pages_list = [];
try {
    require_once __DIR__ . '/../config/config.php';
    $db = getDB();
    // Ensure dynamic_pages table exists
    $db->exec("CREATE TABLE IF NOT EXISTS dynamic_pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_name TEXT NOT NULL UNIQUE,
        page_title TEXT NOT NULL,
        table_name TEXT NOT NULL,
        enable_list INTEGER DEFAULT 1,
        enable_create INTEGER DEFAULT 1,
        enable_update INTEGER DEFAULT 1,
        enable_delete INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    $stmt = $db->query("SELECT page_name, page_title FROM dynamic_pages ORDER BY created_at ASC");
    $dynamic_pages_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Ignore errors if table doesn't exist or DB not available
}
?>
<aside class="hidden md:flex md:flex-shrink-0">
    <div class="flex flex-col w-64 border-r border-border bg-background">
        <div class="flex h-16 shrink-0 items-center px-6 border-b border-border">
            <h1 class="text-lg font-semibold text-foreground">Vira Stok</h1>
        </div>
        <nav class="flex-1 space-y-1 px-3 py-4 overflow-y-auto">
            <!-- Dashboard -->
            <a
                href="admin.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'admin.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                </svg>
                Ana Sayfa
            </a>
            
            <!-- Divider - Sayım Yönetimi -->
            <div class="px-3 py-2">
                <div class="border-t border-border"></div>
                <div class="mt-2 px-2">
                    <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Sayım Yönetimi</span>
                </div>
            </div>
            
            <a
                href="sayimlar.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'sayimlar.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                </svg>
                Sayımlar
            </a>
            <a
                href="sayim-icerikleri.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'sayim-icerikleri.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                Sayım İçerikleri
            </a>
            
            <!-- Divider - Ürün Yönetimi -->
            <div class="px-3 py-2">
                <div class="border-t border-border"></div>
                <div class="mt-2 px-2">
                    <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Ürün Yönetimi</span>
                </div>
            </div>
            
            <a
                href="urun-tanimi.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'urun-tanimi.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                </svg>
                Ürün Tanımları
            </a>
            
            <!-- Divider - Sistem Yönetimi -->
            <div class="px-3 py-2">
                <div class="border-t border-border"></div>
                <div class="mt-2 px-2">
                    <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Sistem Yönetimi</span>
                </div>
            </div>
            
            <a
                href="kullanicilar.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'kullanicilar.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                </svg>
                Kullanıcılar
            </a>
            
            <!-- Divider - Developer -->
            <div class="px-3 py-2">
                <div class="border-t border-border"></div>
                <div class="mt-2 px-2">
                    <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Developer</span>
                </div>
            </div>
            
            <a
                href="api-playground.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'api-playground.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                API Playground
            </a>
            <a
                href="database-explorer.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'database-explorer.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                </svg>
                Database Explorer
            </a>
            <a
                href="cron-manager.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'cron-manager.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Cron Manager
            </a>
            <a
                href="cloud-functions.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'cloud-functions.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                </svg>
                Cloud Functions
            </a>
            <a
                href="pages-builder.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'pages-builder.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
                Pages Builder
            </a>
            
            <?php if (!empty($dynamic_pages_list)): ?>
                <!-- Divider - Dinamik Sayfalar -->
                <div class="px-3 py-2">
                    <div class="border-t border-border"></div>
                    <div class="mt-2 px-2">
                        <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Dinamik Sayfalar</span>
                    </div>
                </div>
                
                <?php foreach ($dynamic_pages_list as $page): ?>
                    <a
                        href="<?php echo htmlspecialchars($page['page_name']); ?>.php"
                        class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == $page['page_name'] . '.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
                    >
                        <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                        <?php echo htmlspecialchars($page['page_title']); ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </nav>
        <div class="border-t border-border px-3 py-4 mt-auto">
            <div class="flex items-center justify-between">
                <div class="text-xs text-muted-foreground">
                    <?php echo htmlspecialchars($_SESSION['username']); ?>
                </div>
                <a
                    href="../logout.php"
                    class="inline-flex items-center justify-center rounded-md text-xs font-medium text-white bg-red-600 hover:bg-red-700 px-3 py-1.5 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                >
                    <svg class="mr-1.5 h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                    Çıkış Yap
                </a>
            </div>
        </div>
    </div>
</aside>

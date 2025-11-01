<?php
// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Get dynamic pages from database grouped by group_name
$dynamic_pages_by_group = [];
$db = null;
try {
    require_once __DIR__ . '/../config/config.php';
    $db = getDB();
    // Ensure dynamic_pages table exists
    $db->exec("CREATE TABLE IF NOT EXISTS dynamic_pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        page_name TEXT NOT NULL UNIQUE,
        page_title TEXT NOT NULL,
        table_name TEXT NOT NULL,
        group_name TEXT,
        enable_list INTEGER DEFAULT 1,
        enable_create INTEGER DEFAULT 1,
        enable_update INTEGER DEFAULT 1,
        enable_delete INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add group_name column if it doesn't exist (migration)
    try {
        $db->exec("ALTER TABLE dynamic_pages ADD COLUMN group_name TEXT");
    } catch (PDOException $e) {
        // Column might already exist
    }
    
    $stmt = $db->query("SELECT page_name, page_title, group_name FROM dynamic_pages ORDER BY group_name ASC, page_title ASC");
    $all_pages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group pages by group_name
    foreach ($all_pages as $page) {
        $group = $page['group_name'] ?: '_ungrouped';
        if (!isset($dynamic_pages_by_group[$group])) {
            $dynamic_pages_by_group[$group] = [];
        }
        $dynamic_pages_by_group[$group][] = $page;
    }
} catch (Exception $e) {
    // Ignore errors if table doesn't exist or DB not available
}
?>
<aside class="hidden md:flex md:flex-shrink-0">
    <div class="flex flex-col w-64 border-r border-border bg-background">
        <div class="flex h-16 shrink-0 items-center px-6 border-b border-border gap-3">
            <?php
            $logo = getLogo();
            if (!empty($logo) && file_exists(__DIR__ . '/../' . $logo)):
            ?>
                <img src="../<?php echo htmlspecialchars($logo); ?>" alt="<?php echo htmlspecialchars(getAppName()); ?>" class="h-10 object-contain">
            <?php endif; ?>
            <h1 class="text-lg font-semibold text-foreground"><?php echo htmlspecialchars(getAppName()); ?></h1>
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
            
            <?php
            // Check if user is developer for settings access
            $is_developer_for_settings = false;
            if (isset($_SESSION['user_id'])) {
                if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'developer') {
                    $is_developer_for_settings = true;
                } elseif ($db !== null) {
                    try {
                        try {
                            $db->exec("ALTER TABLE users ADD COLUMN user_type TEXT DEFAULT 'user'");
                        } catch (PDOException $e) {
                            // Column might already exist, ignore
                        }
                        $stmt = $db->prepare("SELECT user_type FROM users WHERE id = ?");
                        $stmt->execute([$_SESSION['user_id']]);
                        $user_type = $stmt->fetchColumn();
                        $_SESSION['user_type'] = $user_type ?: 'user';
                        $is_developer_for_settings = ($user_type === 'developer');
                    } catch (PDOException $e) {
                        $is_developer_for_settings = false;
                    }
                }
            }
            // If settings are not configured, allow access to settings page for all logged in users
            if (!isSettingsConfigured()) {
                $is_developer_for_settings = true;
            }
            if ($is_developer_for_settings):
            ?>
            <a
                href="settings.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'settings.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Ayarlar
            </a>
            <?php endif; ?>
            
            <?php
            // Check if user is developer
            $is_developer = false;
            if (isset($_SESSION['user_id'])) {
                // Check session first
                if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'developer') {
                    $is_developer = true;
                } else {
                    // Check database
                    try {
                        // Ensure user_type column exists
                        try {
                            $db->exec("ALTER TABLE users ADD COLUMN user_type TEXT DEFAULT 'user'");
                        } catch (PDOException $e) {
                            // Column might already exist, ignore
                        }
                        
                        $stmt = $db->prepare("SELECT user_type FROM users WHERE id = ?");
                        $stmt->execute([$_SESSION['user_id']]);
                        $user_type = $stmt->fetchColumn();
                        $_SESSION['user_type'] = $user_type ?: 'user';
                        $is_developer = ($user_type === 'developer');
                    } catch (PDOException $e) {
                        $is_developer = false;
                    }
                }
            }
            
            if ($is_developer):
            ?>
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
                href="cloud-middlewares.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'cloud-middlewares.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
                Cloud Middlewares
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
            <a
                href="dashboard-widgets.php"
                class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $current_page == 'dashboard-widgets.php' ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
            >
                <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                Dashboard Widgets
            </a>
            <?php endif; ?>
            
            <?php if (!empty($dynamic_pages_by_group)): ?>
                <?php 
                // Sort groups: named groups first, then ungrouped
                $sorted_groups = [];
                $ungrouped = null;
                foreach ($dynamic_pages_by_group as $group_name => $pages) {
                    if ($group_name === '_ungrouped') {
                        $ungrouped = $pages;
                    } else {
                        $sorted_groups[$group_name] = $pages;
                    }
                }
                ksort($sorted_groups);
                ?>
                
                <?php foreach ($sorted_groups as $group_name => $pages): ?>
                    <!-- Divider - Group -->
                    <div class="px-3 py-2">
                        <div class="border-t border-border"></div>
                        <div class="mt-2 px-2">
                            <span class="text-xs font-semibold text-muted-foreground uppercase tracking-wider"><?php echo htmlspecialchars($group_name); ?></span>
                        </div>
                    </div>
                    
                    <?php foreach ($pages as $page): ?>
                        <?php
                        $is_active = ($current_page == 'dynamic-page.php' && isset($_GET['page']) && $_GET['page'] == $page['page_name']);
                        ?>
                        <a
                            href="dynamic-page.php?page=<?php echo urlencode($page['page_name']); ?>"
                            class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $is_active ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
                        >
                            <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <?php echo htmlspecialchars($page['page_title']); ?>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                
                <?php if ($ungrouped && !empty($ungrouped)): ?>
                    <?php foreach ($ungrouped as $page): ?>
                        <?php
                        $is_active = ($current_page == 'dynamic-page.php' && isset($_GET['page']) && $_GET['page'] == $page['page_name']);
                        ?>
                        <a
                            href="dynamic-page.php?page=<?php echo urlencode($page['page_name']); ?>"
                            class="flex items-center px-3 py-2 text-sm font-medium rounded-md transition-colors <?php echo $is_active ? 'text-foreground bg-accent' : 'text-muted-foreground hover:bg-accent hover:text-foreground'; ?>"
                        >
                            <svg class="mr-3 h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                            <?php echo htmlspecialchars($page['page_title']); ?>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
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

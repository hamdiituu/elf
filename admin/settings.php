<?php
require_once '../config/config.php';
requireLogin();

// Only require developer if settings are configured (database connection works)
// Otherwise, allow access to configure settings
if (isSettingsConfigured()) {
    requireDeveloper();
}

$page_title = 'Settings - ' . getAppName();
$current_settings = getSettings();
$success_message = '';
$error_message = '';

// Handle file uploads
$uploads_dir = __DIR__ . '/../uploads';
if (!is_dir($uploads_dir)) {
    mkdir($uploads_dir, 0755, true);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $app_name = trim($_POST['app_name'] ?? '');
    $db_type = $_POST['db_type'] ?? 'sqlite';
    
    if (empty($app_name)) {
        $error_message = 'Application name cannot be empty!';
    } else {
        // Handle logo upload
        $logo_path = $current_settings['logo'] ?? '';
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $logo_file = $_FILES['logo'];
            $logo_ext = strtolower(pathinfo($logo_file['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
            
            if (in_array($logo_ext, $allowed_exts)) {
                $logo_filename = 'logo_' . time() . '.' . $logo_ext;
                $logo_path = 'uploads/' . $logo_filename;
                $logo_full_path = __DIR__ . '/../' . $logo_path;
                
                if (move_uploaded_file($logo_file['tmp_name'], $logo_full_path)) {
                    // Delete old logo if exists
                    if (!empty($current_settings['logo']) && file_exists(__DIR__ . '/../' . $current_settings['logo'])) {
                        @unlink(__DIR__ . '/../' . $current_settings['logo']);
                    }
                } else {
                    $error_message = 'Error uploading logo!';
                }
            } else {
                $error_message = 'Logo supports only JPG, PNG, GIF, SVG or WEBP formats!';
            }
        }
        
        // Handle favicon upload
        $favicon_path = $current_settings['favicon'] ?? '';
        if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK && empty($error_message)) {
            $favicon_file = $_FILES['favicon'];
            $favicon_ext = strtolower(pathinfo($favicon_file['name'], PATHINFO_EXTENSION));
            $allowed_exts = ['ico', 'png', 'jpg', 'jpeg', 'svg'];
            
            if (in_array($favicon_ext, $allowed_exts)) {
                $favicon_filename = 'favicon_' . time() . '.' . $favicon_ext;
                $favicon_path = 'uploads/' . $favicon_filename;
                $favicon_full_path = __DIR__ . '/../' . $favicon_path;
                
                if (move_uploaded_file($favicon_file['tmp_name'], $favicon_full_path)) {
                    // Delete old favicon if exists
                    if (!empty($current_settings['favicon']) && file_exists(__DIR__ . '/../' . $current_settings['favicon'])) {
                        @unlink(__DIR__ . '/../' . $current_settings['favicon']);
                    }
                } else {
                    $error_message = 'Error uploading favicon!';
                }
            } else {
                $error_message = 'Favicon supports only ICO, PNG, JPG, JPEG or SVG formats!';
            }
        }
        
        if (empty($error_message)) {
            // Preserve existing logo and favicon if not uploaded
            $final_logo = $logo_path ?: ($current_settings['logo'] ?? '');
            $final_favicon = $favicon_path ?: ($current_settings['favicon'] ?? '');
            
            $settings = [
                'app_name' => $app_name,
                'logo' => $final_logo,
                'favicon' => $final_favicon,
                'db_type' => $db_type,
                'db_config' => [
                    'sqlite' => [
                        'path' => $_POST['sqlite_path'] ?? DB_PATH
                    ],
                    'mysql' => [
                        'host' => trim($_POST['mysql_host'] ?? ''),
                        'port' => trim($_POST['mysql_port'] ?? '3306'),
                        'database' => trim($_POST['mysql_database'] ?? ''),
                        'username' => trim($_POST['mysql_username'] ?? ''),
                        'password' => trim($_POST['mysql_password'] ?? '')
                    ]
                ]
            ];
            
            // Validate database configuration based on type
            if ($db_type === 'mysql') {
                if (empty($settings['db_config']['mysql']['host']) || 
                    empty($settings['db_config']['mysql']['database']) || 
                    empty($settings['db_config']['mysql']['username'])) {
                    $error_message = 'MySQL requires host, database name and username!';
                } else {
                    // Test MySQL connection
                    try {
                        $config = $settings['db_config']['mysql'];
                        $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
                        $testDb = new PDO($dsn, $config['username'], $config['password']);
                        $testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        
                        // Test database access
                        $dsnWithDb = "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";
                        $testDb = new PDO($dsnWithDb, $config['username'], $config['password']);
                        $testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        
                        if (saveSettings($settings)) {
                            $success_message = 'Settings saved successfully!';
                            $current_settings = getSettings();
                        } else {
                            $error_message = 'Error saving settings!';
                        }
                    } catch (PDOException $e) {
                        $error_message = 'MySQL connection error: ' . $e->getMessage();
                    }
                }
            } else {
                // SQLite
                if (empty($settings['db_config']['sqlite']['path'])) {
                    $error_message = 'SQLite requires database path!';
                } else {
                    // Test SQLite connection
                    try {
                        $dbPath = $settings['db_config']['sqlite']['path'];
                        $dbDir = dirname($dbPath);
                        if (!is_dir($dbDir)) {
                            mkdir($dbDir, 0755, true);
                        }
                        
                        $testDb = new PDO('sqlite:' . $dbPath);
                        $testDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                        
                        if (saveSettings($settings)) {
                            $success_message = 'Settings saved successfully!';
                            $current_settings = getSettings();
                        } else {
                            $error_message = 'Error saving settings!';
                        }
                    } catch (PDOException $e) {
                        $error_message = 'SQLite connection error: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

include '../includes/header.php';
?>
<div class="flex h-screen overflow-hidden">
    <?php include '../includes/admin-sidebar.php'; ?>

    <main class="flex-1 overflow-y-auto">
        <div class="py-6">
            <div class="mx-auto max-w-4xl px-4 sm:px-6 md:px-8">
            <div class="mb-6">
                <h1 class="text-3xl font-bold text-foreground">Settings</h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    Configure application and database settings
                </p>
            </div>
            
            <?php if ($success_message): ?>
                <div class="mb-4 rounded-md bg-green-50 p-4 border border-green-200">
                    <div class="text-sm text-green-800">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="mb-4 rounded-md bg-red-50 p-4 border border-red-200">
                    <div class="text-sm text-red-800">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data" class="space-y-6">
                <!-- Application Name -->
                <div class="rounded-lg border border-border bg-background p-6">
                    <h2 class="text-lg font-semibold text-foreground mb-4">Application Information</h2>
                    
                    <div class="space-y-4">
                        <div>
                            <label for="app_name" class="block text-sm font-medium text-foreground mb-2">
                                Application Name
                            </label>
                            <input
                                type="text"
                                id="app_name"
                                name="app_name"
                                value="<?php echo htmlspecialchars($current_settings['app_name'] ?? 'ELF'); ?>"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent sm:text-sm"
                                placeholder="Enter application name"
                            >
                            <p class="mt-1 text-xs text-muted-foreground">
                                This name will be displayed on the login page and sidebar.
                            </p>
                        </div>
                        
                        <div>
                            <label for="logo" class="block text-sm font-medium text-foreground mb-2">
                                Logo
                            </label>
                            <?php if (!empty($current_settings['logo']) && file_exists(__DIR__ . '/../' . $current_settings['logo'])): ?>
                                <div class="mb-2">
                                    <img src="../<?php echo htmlspecialchars($current_settings['logo']); ?>" alt="Logo" class="h-16 object-contain">
                                </div>
                            <?php endif; ?>
                            <input
                                type="file"
                                id="logo"
                                name="logo"
                                accept="image/jpeg,image/jpg,image/png,image/gif,image/svg+xml,image/webp"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-foreground file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-primary-foreground hover:file:opacity-90 focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent sm:text-sm"
                            >
                            <p class="mt-1 text-xs text-muted-foreground">
                                You can upload logo in JPG, PNG, GIF, SVG or WEBP format. Maximum file size: 5MB
                            </p>
                        </div>
                        
                        <div>
                            <label for="favicon" class="block text-sm font-medium text-foreground mb-2">
                                Favicon
                            </label>
                            <?php if (!empty($current_settings['favicon']) && file_exists(__DIR__ . '/../' . $current_settings['favicon'])): ?>
                                <div class="mb-2">
                                    <img src="../<?php echo htmlspecialchars($current_settings['favicon']); ?>" alt="Favicon" class="h-8 w-8 object-contain">
                                </div>
                            <?php endif; ?>
                            <input
                                type="file"
                                id="favicon"
                                name="favicon"
                                accept="image/x-icon,image/png,image/jpeg,image/jpg,image/svg+xml"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-foreground file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-primary-foreground hover:file:opacity-90 focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent sm:text-sm"
                            >
                            <p class="mt-1 text-xs text-muted-foreground">
                                You can upload favicon in ICO, PNG, JPG, JPEG or SVG format. Maximum file size: 1MB
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Database Type -->
                <div class="rounded-lg border border-border bg-background p-6">
                    <h2 class="text-lg font-semibold text-foreground mb-4">Database Settings</h2>
                    
                    <div class="mb-4">
                        <label for="db_type" class="block text-sm font-medium text-foreground mb-2">
                            Database Type
                        </label>
                        <select
                            id="db_type"
                            name="db_type"
                            class="w-full rounded-md border border-input bg-background px-3 py-2 text-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent sm:text-sm"
                            onchange="toggleDbConfig()"
                        >
                            <option value="sqlite" <?php echo ($current_settings['db_type'] ?? 'sqlite') === 'sqlite' ? 'selected' : ''; ?>>SQLite</option>
                            <option value="mysql" <?php echo ($current_settings['db_type'] ?? 'sqlite') === 'mysql' ? 'selected' : ''; ?>>MySQL</option>
                        </select>
                    </div>
                    
                    <!-- SQLite Configuration -->
                    <div id="sqlite_config" style="display: <?php echo ($current_settings['db_type'] ?? 'sqlite') === 'sqlite' ? 'block' : 'none'; ?>;">
                        <div>
                            <label for="sqlite_path" class="block text-sm font-medium text-foreground mb-2">
                                Database File Path
                            </label>
                            <input
                                type="text"
                                id="sqlite_path"
                                name="sqlite_path"
                                value="<?php echo htmlspecialchars($current_settings['db_config']['sqlite']['path'] ?? DB_PATH); ?>"
                                required
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent sm:text-sm"
                                placeholder="/path/to/database.db"
                            >
                            <p class="mt-1 text-xs text-muted-foreground">
                                Full path to the SQLite database file
                            </p>
                        </div>
                    </div>
                    
                    <!-- MySQL Configuration -->
                    <div id="mysql_config" style="display: <?php echo ($current_settings['db_type'] ?? 'sqlite') === 'mysql' ? 'block' : 'none'; ?>;">
                        <div class="space-y-4">
                            <div>
                                <label for="mysql_host" class="block text-sm font-medium text-foreground mb-2">
                                    Host
                                </label>
                                <input
                                    type="text"
                                    id="mysql_host"
                                    name="mysql_host"
                                    value="<?php echo htmlspecialchars($current_settings['db_config']['mysql']['host'] ?? 'localhost'); ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent sm:text-sm"
                                    placeholder="localhost"
                                >
                            </div>
                            
                            <div>
                                <label for="mysql_port" class="block text-sm font-medium text-foreground mb-2">
                                    Port
                                </label>
                                <input
                                    type="text"
                                    id="mysql_port"
                                    name="mysql_port"
                                    value="<?php echo htmlspecialchars($current_settings['db_config']['mysql']['port'] ?? '3306'); ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent sm:text-sm"
                                    placeholder="3306"
                                >
                            </div>
                            
                            <div>
                                <label for="mysql_database" class="block text-sm font-medium text-foreground mb-2">
                                    Database Name
                                </label>
                                <input
                                    type="text"
                                    id="mysql_database"
                                    name="mysql_database"
                                    value="<?php echo htmlspecialchars($current_settings['db_config']['mysql']['database'] ?? ''); ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent sm:text-sm"
                                    placeholder="database_name"
                                >
                            </div>
                            
                            <div>
                                <label for="mysql_username" class="block text-sm font-medium text-foreground mb-2">
                                    Username
                                </label>
                                <input
                                    type="text"
                                    id="mysql_username"
                                    name="mysql_username"
                                    value="<?php echo htmlspecialchars($current_settings['db_config']['mysql']['username'] ?? ''); ?>"
                                    class="w-full rounded-md border border-input bg-background px-3 py-2 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent sm:text-sm"
                                    placeholder="username"
                                >
                            </div>
                            
                            <div>
                                <label for="mysql_password" class="block text-sm font-medium text-foreground mb-2">
                                    Password
                            </label>
                            <input
                                type="password"
                                id="mysql_password"
                                name="mysql_password"
                                value="<?php echo htmlspecialchars($current_settings['db_config']['mysql']['password'] ?? ''); ?>"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring focus:border-transparent sm:text-sm"
                                placeholder="Enter your password"
                                >
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Submit Button -->
                <div class="flex justify-end">
                    <button
                        type="submit"
                        class="inline-flex items-center justify-center rounded-md bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary transition-all"
                    >
                        Save Settings
                    </button>
                </div>
            </form>
            </div>
        </div>
    </main>
</div>

<script>
function toggleDbConfig() {
    const dbType = document.getElementById('db_type').value;
    const sqliteConfig = document.getElementById('sqlite_config');
    const mysqlConfig = document.getElementById('mysql_config');
    
    if (dbType === 'sqlite') {
        sqliteConfig.style.display = 'block';
        mysqlConfig.style.display = 'none';
        document.getElementById('sqlite_path').required = true;
        document.getElementById('mysql_host').required = false;
        document.getElementById('mysql_database').required = false;
        document.getElementById('mysql_username').required = false;
    } else {
        sqliteConfig.style.display = 'none';
        mysqlConfig.style.display = 'block';
        document.getElementById('sqlite_path').required = false;
        document.getElementById('mysql_host').required = true;
        document.getElementById('mysql_database').required = true;
        document.getElementById('mysql_username').required = true;
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    toggleDbConfig();
});
</script>

<?php include '../includes/footer.php'; ?>


<?php
// Settings file path
define('SETTINGS_FILE', __DIR__ . '/settings.json');
define('DB_PATH', __DIR__ . '/../database/elf.db');

// Session configuration
session_start();

/**
 * Get application settings
 * @return array Settings array with default values
 */
function getSettings() {
    $defaultSettings = [
        'app_name' => 'ELF',
        'logo' => '', // Path to logo image
        'favicon' => '', // Path to favicon image
        'db_type' => 'sqlite', // 'sqlite' or 'mysql'
        'db_config' => [
            'sqlite' => [
                'path' => DB_PATH
            ],
            'mysql' => [
                'host' => 'localhost',
                'port' => '3306',
                'database' => '',
                'username' => '',
                'password' => ''
            ]
        ]
    ];
    
    if (file_exists(SETTINGS_FILE)) {
        $settingsJson = file_get_contents(SETTINGS_FILE);
        $settings = json_decode($settingsJson, true);
        if ($settings && is_array($settings)) {
            return array_merge($defaultSettings, $settings);
        }
    }
    
    return $defaultSettings;
}

/**
 * Save application settings
 * @param array $settings Settings array to save
 * @return bool True on success, false on failure
 */
function saveSettings($settings) {
    try {
        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return file_put_contents(SETTINGS_FILE, $json) !== false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Get application name
 * @return string Application name
 */
function getAppName() {
    $settings = getSettings();
    return $settings['app_name'] ?? 'ELF';
}

/**
 * Get logo path
 * @return string Logo path or empty string
 */
function getLogo() {
    $settings = getSettings();
    return $settings['logo'] ?? '';
}

/**
 * Get favicon path
 * @return string Favicon path or empty string
 */
function getFavicon() {
    $settings = getSettings();
    return $settings['favicon'] ?? '';
}

/**
 * Check if settings are configured
 * @return bool True if settings are configured
 */
function isSettingsConfigured() {
    // If settings file doesn't exist, settings are not configured
    if (!file_exists(SETTINGS_FILE)) {
        return false;
    }
    
    $settings = getSettings();
    
    // Check if app name is set and not just default
    if (empty($settings['app_name'])) {
        return false;
    }
    
    // Check database configuration based on type
    if ($settings['db_type'] === 'mysql') {
        $mysqlConfig = $settings['db_config']['mysql'] ?? [];
        if (empty($mysqlConfig['host']) || empty($mysqlConfig['database']) || 
            empty($mysqlConfig['username'])) {
            return false;
        }
    } else {
        // For SQLite, check if path is set
        $sqliteConfig = $settings['db_config']['sqlite'] ?? [];
        if (empty($sqliteConfig['path'])) {
            return false;
        }
    }
    
    return true;
}

// Database connection function
function getDB() {
    $settings = getSettings();
    $dbType = $settings['db_type'] ?? 'sqlite';
    
    try {
        if ($dbType === 'mysql') {
            $config = $settings['db_config']['mysql'] ?? [];
            $host = $config['host'] ?? 'localhost';
            $port = $config['port'] ?? '3306';
            $database = $config['database'] ?? '';
            $username = $config['username'] ?? '';
            $password = $config['password'] ?? '';
            
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $db = new PDO($dsn, $username, $password);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            return $db;
        } else {
            // SQLite (default)
            $config = $settings['db_config']['sqlite'] ?? [];
            $dbPath = $config['path'] ?? DB_PATH;
            
            // Create directory if it doesn't exist
            $dbDir = dirname($dbPath);
            if (!is_dir($dbDir)) {
                mkdir($dbDir, 0755, true);
            }
            
            $db = new PDO('sqlite:' . $dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            return $db;
        }
    } catch (PDOException $e) {
        // Don't die in API context, throw exception instead
        if (php_sapi_name() === 'cli' || strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            throw new PDOException("Database connection failed: " . $e->getMessage());
        }
        die("Database connection failed: " . $e->getMessage());
    }
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

// Require login
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../index.php');
        exit;
    }
    
    // Check if settings are configured (except for settings page itself)
    $current_page = basename($_SERVER['PHP_SELF'] ?? '');
    if ($current_page !== 'settings.php' && !isSettingsConfigured()) {
        // Redirect to settings page if not already there
        header('Location: settings.php');
        exit;
    }
}

// Check if user is developer
function isDeveloper() {
    if (!isLoggedIn()) {
        return false;
    }
    
    // Check if user_type is set in session
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'developer') {
        return true;
    }
    
    // If not in session, check database
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT user_type FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user_type = $stmt->fetchColumn();
        
        // Update session
        $_SESSION['user_type'] = $user_type ?: 'user';
        
        return ($user_type === 'developer');
    } catch (PDOException $e) {
        return false;
    }
}

// Require developer access
function requireDeveloper() {
    requireLogin();
    if (!isDeveloper()) {
        header('Location: admin.php');
        exit;
    }
}
?>


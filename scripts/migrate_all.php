<?php
/**
 * Unified Migration Script
 * This script runs all necessary database migrations for the application
 */

require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();
    
    echo "Starting migrations...\n\n";
    
    // 1. Add user_type column to users table
    echo "1. Checking user_type column...\n";
    $stmt = $db->query("PRAGMA table_info(users)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasUserType = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'user_type') {
            $hasUserType = true;
            break;
        }
    }
    
    if (!$hasUserType) {
        $db->exec("ALTER TABLE users ADD COLUMN user_type TEXT DEFAULT 'user'");
        $stmt = $db->prepare("UPDATE users SET user_type = 'developer' WHERE username = 'admin'");
        $stmt->execute();
        echo "   ✓ Added 'user_type' column to users table.\n";
    } else {
        echo "   ✓ 'user_type' column already exists.\n";
    }
    
    // 2. Create dynamic_pages table
    echo "2. Checking dynamic_pages table...\n";
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
        create_rule TEXT,
        update_rule TEXT,
        delete_rule TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Add group_name column if it doesn't exist
    $stmt = $db->query("PRAGMA table_info(dynamic_pages)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasGroupName = false;
    $hasCreateRule = false;
    $hasUpdateRule = false;
    $hasDeleteRule = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'group_name') $hasGroupName = true;
        if ($col['name'] === 'create_rule') $hasCreateRule = true;
        if ($col['name'] === 'update_rule') $hasUpdateRule = true;
        if ($col['name'] === 'delete_rule') $hasDeleteRule = true;
    }
    
    if (!$hasGroupName) {
        $db->exec("ALTER TABLE dynamic_pages ADD COLUMN group_name TEXT");
        echo "   ✓ Added 'group_name' column.\n";
    }
    if (!$hasCreateRule) {
        $db->exec("ALTER TABLE dynamic_pages ADD COLUMN create_rule TEXT");
        echo "   ✓ Added 'create_rule' column.\n";
    }
    if (!$hasUpdateRule) {
        $db->exec("ALTER TABLE dynamic_pages ADD COLUMN update_rule TEXT");
        echo "   ✓ Added 'update_rule' column.\n";
    }
    if (!$hasDeleteRule) {
        $db->exec("ALTER TABLE dynamic_pages ADD COLUMN delete_rule TEXT");
        echo "   ✓ Added 'delete_rule' column.\n";
    }
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_dynamic_pages_table ON dynamic_pages(table_name)");
    echo "   ✓ dynamic_pages table ensured.\n";
    
    // 3. Create cloud_functions table
    echo "3. Checking cloud_functions table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS cloud_functions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        description TEXT,
        code TEXT NOT NULL,
        language TEXT DEFAULT 'php',
        http_method TEXT NOT NULL DEFAULT 'POST',
        endpoint TEXT NOT NULL UNIQUE,
        enabled INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INTEGER,
        middleware_id INTEGER,
        FOREIGN KEY (created_by) REFERENCES users(id),
        FOREIGN KEY (middleware_id) REFERENCES cloud_middlewares(id)
    )");
    
    // Add middleware_id column if it doesn't exist
    $stmt = $db->query("PRAGMA table_info(cloud_functions)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasMiddlewareId = false;
    $hasLanguage = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'middleware_id') {
            $hasMiddlewareId = true;
        }
        if ($col['name'] === 'language') {
            $hasLanguage = true;
        }
    }
    if (!$hasMiddlewareId) {
        $db->exec("ALTER TABLE cloud_functions ADD COLUMN middleware_id INTEGER REFERENCES cloud_middlewares(id)");
        echo "   ✓ Added 'middleware_id' column.\n";
    }
    if (!$hasLanguage) {
        $db->exec("ALTER TABLE cloud_functions ADD COLUMN language TEXT DEFAULT 'php'");
        echo "   ✓ Added 'language' column.\n";
    }
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cloud_functions_endpoint ON cloud_functions(endpoint)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cloud_functions_enabled ON cloud_functions(enabled)");
    echo "   ✓ cloud_functions table ensured.\n";
    
    // 4. Create cloud_middlewares table
    echo "4. Checking cloud_middlewares table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS cloud_middlewares (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        description TEXT,
        code TEXT NOT NULL,
        language TEXT DEFAULT 'php',
        enabled INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_by INTEGER,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");
    
    // Add language column if it doesn't exist
    $stmt = $db->query("PRAGMA table_info(cloud_middlewares)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasLanguage = false;
    foreach ($columns as $col) {
        if ($col['name'] === 'language') {
            $hasLanguage = true;
            break;
        }
    }
    if (!$hasLanguage) {
        $db->exec("ALTER TABLE cloud_middlewares ADD COLUMN language TEXT DEFAULT 'php'");
        echo "   ✓ Added 'language' column.\n";
    }
    
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cloud_middlewares_enabled ON cloud_middlewares(enabled)");
    echo "   ✓ cloud_middlewares table ensured.\n";
    
    // 5. Create cron_jobs table
    echo "5. Checking cron_jobs table...\n";
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
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_jobs_enabled ON cron_jobs(enabled)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_jobs_next_run_at ON cron_jobs(next_run_at)");
    echo "   ✓ cron_jobs table ensured.\n";
    
    // 6. Create cron_log table
    echo "6. Checking cron_log table...\n";
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
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_log_name ON cron_log(cron_name)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_log_status ON cron_log(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_cron_log_started_at ON cron_log(started_at)");
    echo "   ✓ cron_log table ensured.\n";
    
    // 7. Create dashboard_widgets table
    echo "7. Checking dashboard_widgets table...\n";
    $db->exec("CREATE TABLE IF NOT EXISTS dashboard_widgets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        widget_type TEXT NOT NULL,
        widget_config TEXT NOT NULL,
        position INTEGER DEFAULT 0,
        width TEXT DEFAULT 'md:col-span-1',
        enabled INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
    echo "   ✓ dashboard_widgets table ensured.\n";
    
    // 8. Create saved_queries table
    echo "8. Checking saved_queries table...\n";
    $stmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='saved_queries'");
    if (!$stmt->fetch()) {
        $db->exec("CREATE TABLE saved_queries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            query TEXT NOT NULL,
            user_id INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )");
        
        // Add user_id column if table exists but column doesn't
        $stmt = $db->query("PRAGMA table_info(saved_queries)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $hasUserId = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'user_id') {
                $hasUserId = true;
                break;
            }
        }
        if (!$hasUserId) {
            $db->exec("ALTER TABLE saved_queries ADD COLUMN user_id INTEGER");
        }
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_saved_queries_name ON saved_queries(name)");
        echo "   ✓ saved_queries table created.\n";
    } else {
        echo "   ✓ saved_queries table already exists.\n";
    }
    
    
    echo "\n✓ All migrations completed successfully!\n";
    
} catch (PDOException $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>


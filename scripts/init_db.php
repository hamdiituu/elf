<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();
    $settings = getSettings();
    $dbType = $settings['db_type'] ?? 'sqlite';
    
    // SQL syntax differs between SQLite and MySQL
    if ($dbType === 'mysql') {
        // MySQL syntax
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(255) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            user_type VARCHAR(50) DEFAULT 'user',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $db->exec("CREATE TABLE IF NOT EXISTS sayimlar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sayim_no VARCHAR(255) NOT NULL UNIQUE,
            aktif TINYINT DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $db->exec("CREATE TABLE IF NOT EXISTS sayim_icerikleri (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sayim_id INT NOT NULL,
            barkod VARCHAR(255) NOT NULL,
            urun_adi VARCHAR(255),
            okutulma_zamani DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sayim_id) REFERENCES sayimlar(id) ON DELETE CASCADE,
            INDEX idx_sayim_id (sayim_id),
            INDEX idx_barkod (barkod)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } else {
        // SQLite syntax
        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            user_type TEXT DEFAULT 'user',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $db->exec("CREATE TABLE IF NOT EXISTS sayimlar (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sayim_no TEXT NOT NULL UNIQUE,
            aktif INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $db->exec("CREATE TABLE IF NOT EXISTS sayim_icerikleri (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sayim_id INTEGER NOT NULL,
            barkod TEXT NOT NULL,
            urun_adi TEXT,
            okutulma_zamani DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sayim_id) REFERENCES sayimlar(id) ON DELETE CASCADE
        )");
        
        // Create indexes for SQLite
        $db->exec("CREATE INDEX IF NOT EXISTS idx_sayim_id ON sayim_icerikleri(sayim_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_barkod ON sayim_icerikleri(barkod)");
    }
    
    // Create default admin user (username: admin, password: admin123)
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password, user_type) VALUES (?, ?, 'developer')");
        $stmt->execute(['admin', $password]);
        echo "Default admin user created: username='admin', password='admin123'\n";
    }
    
    echo "Database initialized successfully!\n";
    
} catch (PDOException $e) {
    die("Error initializing database: " . $e->getMessage());
}
?>


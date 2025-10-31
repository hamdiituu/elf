<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();
    
    // Create users table
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create sayimlar table (counts/inventories)
    $db->exec("CREATE TABLE IF NOT EXISTS sayimlar (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sayim_no TEXT NOT NULL UNIQUE,
        aktif INTEGER DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    
    // Create sayim_icerikleri table (inventory contents)
    $db->exec("CREATE TABLE IF NOT EXISTS sayim_icerikleri (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sayim_id INTEGER NOT NULL,
        barkod TEXT NOT NULL,
        urun_adi TEXT,
        okutulma_zamani DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sayim_id) REFERENCES sayimlar(id) ON DELETE CASCADE
    )");
    
    // Create index for better performance
    $db->exec("CREATE INDEX IF NOT EXISTS idx_sayim_id ON sayim_icerikleri(sayim_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_barkod ON sayim_icerikleri(barkod)");
    
    // Create default admin user (username: admin, password: admin123)
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
        $stmt->execute(['admin', $password]);
        echo "Default admin user created: username='admin', password='admin123'\n";
    }
    
    echo "Database initialized successfully!\n";
    
} catch (PDOException $e) {
    die("Error initializing database: " . $e->getMessage());
}
?>


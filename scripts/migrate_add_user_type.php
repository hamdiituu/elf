<?php
require_once __DIR__ . '/../config/config.php';

try {
    $db = getDB();

    // Check if 'user_type' column exists in 'users'
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
        
        // Set existing admin user as developer (if exists)
        $stmt = $db->prepare("UPDATE users SET user_type = 'developer' WHERE username = 'admin'");
        $stmt->execute();
        
        echo "Added 'user_type' column to users table.\n";
        echo "Set existing 'admin' user as developer.\n";
    } else {
        echo "'user_type' column already exists in users table.\n";
    }

    echo "Migration completed.\n";

} catch (PDOException $e) {
    die("Error running migration: " . $e->getMessage());
}
?>

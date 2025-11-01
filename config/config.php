<?php
// Database configuration
define('DB_PATH', __DIR__ . '/../database/stockcount.db');

// Session configuration
session_start();

// Database connection function
function getDB() {
    try {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $db;
    } catch (PDOException $e) {
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


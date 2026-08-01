<?php
require_once __DIR__ . '/../includes/session_config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Log logout activity if user is logged in
if (isset($_SESSION['admin_id'])) {
    logUserActivity(
        $_SESSION['admin_id'],
        $_SESSION['username'],
        $_SESSION['full_name'] ?? $_SESSION['username'],
        'logout',
        'تسجيل خروج'
    );
    
    // Update session status
    $session_id = session_id();
    $stmt = $pdo->prepare("
        UPDATE user_sessions 
        SET status = 'ended', ended_at = NOW() 
        WHERE session_id = ? AND status = 'active'
    ");
    $stmt->execute([$session_id]);
}

session_destroy();
header('Location: login.php');
exit();
?>

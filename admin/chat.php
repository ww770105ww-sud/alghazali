<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$filter = $_GET['filter'] ?? null;

if ($filter) {
    // إذا كان هناك فلتر، نوجه المستخدم لصفحة التقارير
    header("Location: message_reports.php?filter=" . urlencode($filter));
} else {
    // إذا لم يكن هناك فلتر، نوجه المستخدم للمحادثات الداخلية
    header("Location: internal_messages.php");
}
exit();
?>

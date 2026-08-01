<?php
/**
 * تشغيل إصلاح account_balances_unified من المتصفح
 * الرابط: http://localhost:8000/ghazali/admin/run_fix_balances.php?confirm=1
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$roleName = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
$roleId = (int)($_SESSION['role_id'] ?? 0);
$isDeveloper = isset($_SESSION['admin_id']) && ($roleId === 2 || $roleName === 'developer');

if (!$isDeveloper) {
    http_response_code(403);
    exit('غير مصرح لك بتشغيل أدوات إصلاح الأرصدة.');
}

$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === '1';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إصلاح أرصدة الحسابات</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body { background: #f5f5f7; padding: 2rem; }
        pre { background: #1e1e1e; color: #d4d4d4; padding: 1.25rem; border-radius: 8px; white-space: pre-wrap; direction: ltr; text-align: left; font-size: 0.9rem; }
        .warn-box { background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 1rem 1.25rem; }
    </style>
</head>
<body>
<div class="container" style="max-width: 900px;">
    <h1 class="mb-3">إصلاح جدول account_balances_unified</h1>

    <?php if (!$confirmed): ?>
        <div class="warn-box mb-4">
            <strong>تنبيه:</strong> خذ نسخة احتياطية من قاعدة البيانات قبل المتابعة.
            هذا الإجراء يعيد بناء الأرصدة ويعدّل الإجراءات المخزنة والـ Triggers.
        </div>
        <p class="text-muted mb-4">
            ملاحظة: مجلد <code>tools/</code> محظور من الويب لأسباب أمنية — لذلك يظهر
            <strong>Forbidden</strong> عند فتح <code>run_fix.bat</code> من المتصفح.
            استخدم هذه الصفحة بدلاً من ذلك.
        </p>
        <a href="?confirm=1" class="btn btn-danger btn-lg"
           onclick="return confirm('هل أخذت نسخة احتياطية وتريد المتابعة؟');">
            تشغيل الإصلاح الآن
        </a>
        <a href="manage_currency_balances.php" class="btn btn-outline-secondary btn-lg ms-2">إلغاء</a>
    <?php else: ?>
        <div class="alert alert-info">جاري تنفيذ الإصلاح...</div>
        <pre><?php
            ob_start();
            try {
                define('FIX_BALANCES_EMBEDDED', true);
                include __DIR__ . '/../tools/run_fix_account_balances.php';
            } catch (Throwable $e) {
                echo "FATAL ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
            }
            echo htmlspecialchars(ob_get_clean(), ENT_QUOTES, 'UTF-8');
        ?></pre>
        <a href="manage_currency_balances.php" class="btn btn-primary mt-3">العودة لإدارة الأرصدة</a>
        <a href="check_account_balance_table.php" class="btn btn-outline-secondary mt-3">فحص بنية الجدول</a>
    <?php endif; ?>
</div>
</body>
</html>

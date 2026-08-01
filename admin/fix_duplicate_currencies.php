<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>أداة قديمة متقاعدة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <style>
        body { background: #f5f5f7; padding: 2rem; font-family: Arial, sans-serif; }
        .card-box { max-width: 900px; margin: 0 auto; background: #fff; border-radius: 16px; padding: 2rem; box-shadow: 0 10px 30px rgba(0,0,0,.08); }
    </style>
</head>
<body>
<div class="card-box">
    <h1 class="h3 mb-3">تم تعطيل هذه الأداة القديمة</h1>
    <div class="alert alert-warning">
        هذا الملف كان يحتوي منطق إصلاح قديم ومتعارض مع محرك الأرصدة الحالي، لذلك تم إيقافه حتى لا يعيد إنشاء
        `procedures` و`triggers` بصيغة قديمة.
    </div>
    <p class="mb-3">
        البديل المعتمد الآن هو أداة الإصلاح الحالية:
        <a href="run_fix_balances.php">`run_fix_balances.php`</a>
    </p>
    <p class="mb-3">
        وسكربت SQL المرجعي:
        <code>tools/database/20260616_finance_balance_engine_fix.sql</code>
    </p>
    <div class="d-flex gap-2 flex-wrap">
        <a class="btn btn-primary" href="run_fix_balances.php">فتح أداة الإصلاح المعتمدة</a>
        <a class="btn btn-outline-secondary" href="manage_currency_balances.php">العودة إلى إدارة الأرصدة</a>
    </div>
</div>
</body>
</html>

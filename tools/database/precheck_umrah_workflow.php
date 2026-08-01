<?php
/**
 * فحص مسبق قبل تطوير تبويب سير العمل للعمرة
 * 1) هل يوجد عمود workflow_step_id في passports؟
 * 2) هل يوجد جدول workflow_checklist؟
 * 3) هل توجد الدالة get_step_dynamic_fields()؟
 * 4) هل يوجد أعمدة workflow_logs ومتطلبات أخرى؟
 */
$DB_HOST='127.0.0.1';$DB_USER='root';$DB_PASS='738155';$DB_NAME='ghazali';
$pdo=new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
header('Content-Type: text/html; charset=utf-8');
echo "<h3>الفحص المسبق لتبويب سير العمل</h3><pre style='background:#f6f8fa;padding:15px;border-radius:8px;'>";

// 1. فحص أعمدة passports
echo "<b>1) أعمدة passports المطلوبة:</b>\n";
$colsNeeded=['workflow_step_id','workflow_id','status_changed_at','status_changed_by','closed_at','closed_by'];
try {
    $existingCols=[];
    $q=$pdo->query("SHOW COLUMNS FROM passports");
    foreach($q->fetchAll() as $r) $existingCols[strtolower($r['Field'])]=true;
    foreach($colsNeeded as $c) {
        $ok=isset($existingCols[strtolower($c)]);
        echo "   • ".str_pad($c,28)." ".($ok?"✅ موجود":"❌ <span style='color:red'>مفقود</span>")."\n";
    }
} catch (Throwable $e) { echo "   ❌ خطأ: ".$e->getMessage()."\n"; }

// 2. فحص الجداول المطلوبة
echo "\n<b>2) الجداول المطلوبة:</b>\n";
$tablesNeeded=['workflow_checklist','workflow_logs','workflow_step_fields','document_requirements'];
foreach ($tablesNeeded as $t) {
    try {
        $c=$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "   • ".str_pad($t,28)."✅ موجود ($c صفوف)\n";
    } catch (Throwable $e) {
        echo "   • ".str_pad($t,28)."❌ <span style='color:red'>مفقود</span> - ".$e->getMessage()."\n";
    }
}

// 3. فحص وجود الدوال في functions.php
echo "\n<b>3) الدوال المطلوبة في functions.php:</b>\n";
$funcs=['get_step_dynamic_fields','change_transaction_status','get_allowed_transitions','get_workflow_fields_by_type','get_step_fields'];
require_once __DIR__.'/../../includes/db.php';
require_once __DIR__.'/../../includes/functions.php';
foreach ($funcs as $fn) {
    $ok=function_exists($fn);
    echo "   • ".str_pad($fn,32)." ".($ok?"✅ موجودة":"❌ <span style='color:red'>مفقودة</span>")."\n";
}

echo "\n<b>4) التحقق من سير العمل المخصص للعمرة (workflows):</b>\n";
$workflows=$pdo->query("SELECT id,name,transaction_type,default_status_id,is_active FROM workflows WHERE transaction_type IN ('umrah','all') OR transaction_type IS NULL OR transaction_type='' ORDER BY transaction_type,id")->fetchAll();
foreach ($workflows as $wf) {
    $cntSteps=$pdo->prepare("SELECT COUNT(*) FROM workflow_steps WHERE workflow_id=?");$cntSteps->execute([$wf['id']]);
    $cntTrans=$pdo->prepare("SELECT COUNT(*) FROM workflow_transitions WHERE workflow_id=?");$cntTrans->execute([$wf['id']]);
    echo "   • ID #{$wf['id']} {$wf['name']} | نوع: ".($wf['transaction_type']?:'عام')." | مراحل: ".$cntSteps->fetchColumn()." | انتقالات: ".$cntTrans->fetchColumn()."\n";
}

echo "</pre>";

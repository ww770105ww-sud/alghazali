<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';

session_start();

echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='utf-8'><title>فحص تكامل سير عمل العمرة</title>";
echo "<link rel='stylesheet' href='../../assets/css/bootstrap.min.css'>";
echo "<style>body{font-family:Tahoma;padding:20px;} .ok{color:#16a34a;font-weight:bold;} .bad{color:#dc2626;font-weight:bold;} table{font-size:0.85rem;}</style>";
echo "</head><body class='bg-light'><div class='container'>";

echo "<h3 class='mb-4 text-primary'><i class='fas fa-tasks me-2'></i> فحص تكامل سير عمل العمرة (Umrah Workflow)</h3>";

$allPass = true;

// 1) فحص الأعمدة والجداول المطلوبة
echo "<div class='card mb-3 shadow-sm'><div class='card-header bg-white fw-bold'>١. فحص البنية التحتية (Schema)</div><div class='card-body'>";
$tests = [
    ['passports.workflow_step_id', "SHOW COLUMNS FROM passports LIKE 'workflow_step_id'", 1],
    ['جدول workflow_checklist', "SHOW TABLES LIKE 'workflow_checklist'", 1],
    ['جدول workflow_logs',      "SHOW TABLES LIKE 'workflow_logs'", 1],
    ['جدول document_requirements', "SHOW TABLES LIKE 'document_requirements'", 1],
    ['جدول workflow_field_values', "SHOW TABLES LIKE 'workflow_field_values'", 1],
];
foreach ($tests as $t) {
    try {
        $r = $pdo->query($t[1])->rowCount();
        $pass = $r >= $t[2];
        if (!$pass) $allPass = false;
        echo "<div>" . ($pass?"<span class='ok'>✓</span>":"<span class='bad'>✗</span>") . " {$t[0]}: " . ($pass?'موجود':'<b class="text-danger">غير موجود</b>') . "</div>";
    } catch (Throwable $e) {
        echo "<div><span class='bad'>✗</span> {$t[0]}: خطأ - {$e->getMessage()}</div>";
        $allPass = false;
    }
}
echo "</div></div>";

// 2) فحص وجود سير عمل للعمرة + مراحله + انتقالات
echo "<div class='card mb-3 shadow-sm'><div class='card-header bg-white fw-bold'>٢. سير العمل (Workflow Data)</div><div class='card-body'>";

try {
    $wf = $pdo->query("SELECT * FROM workflows WHERE transaction_type IN ('umrah','all') ORDER BY transaction_type='umrah' DESC, id ASC LIMIT 5")->fetchAll();
    $wfCount = count($wf);
    echo ($wfCount>0?"<span class='ok'>✓</span>":"<span class='bad'>✗</span>") . " عدد سير العمل المتوافق مع العمرة: <b>$wfCount</b><br>";
    if ($wfCount == 0) $allPass = false;

    foreach ($wf as $w) {
        $wid = (int)$w['id'];
        echo "<div class='ms-3 mt-2 bg-light p-2 rounded'>";
        echo "🎯 سير عمل #" . $wid . " - <b>" . htmlspecialchars($w['name']) . "</b> (type: ".htmlspecialchars($w['transaction_type']).")";
        $steps = $pdo->prepare("SELECT * FROM workflow_steps WHERE workflow_id = ? ORDER BY COALESCE(sort_order, id) ASC, id ASC");
        $steps->execute([$wid]);
        $steps = $steps->fetchAll();
        echo "<div class='ms-4 mt-1'><small class='text-muted'>المراحل (".count($steps)."): ";
        $stepNames = [];
        foreach ($steps as $s) $stepNames[] = htmlspecialchars($s['step_name']);
        echo implode(" <i class='fas fa-arrow-left text-muted'></i> ", $stepNames);
        echo "</small></div>";

        $tr = $pdo->prepare("SELECT t.*, fs.step_name as from_name, ts.step_name as to_name FROM workflow_transitions t LEFT JOIN workflow_steps fs ON t.from_step_id=fs.id LEFT JOIN workflow_steps ts ON t.to_step_id=ts.id WHERE t.workflow_id = ?");
        $tr->execute([$wid]);
        $tr = $tr->fetchAll();
        echo "<div class='ms-4 mt-1'><small class='text-muted'>الانتقالات (".count($tr)."): ";
        $trNames = [];
        foreach ($tr as $t) $trNames[] = ($t['from_name']??'?')."→".($t['to_name']??'?');
        echo count($trNames) ? implode(", ", $trNames) : "لا يوجد";
        echo "</small></div>";
        echo "</div>";
    }
} catch (Throwable $e) {
    echo "<div><span class='bad'>✗</span> خطأ في فحص سير العمل: {$e->getMessage()}</div>";
    $allPass = false;
}
echo "</div></div>";

// 3) فحص متطلبات المستندات (document_requirements)
echo "<div class='card mb-3 shadow-sm'><div class='card-header bg-white fw-bold'>٣. قائمة التحقق (Document Requirements للعمرة)</div><div class='card-body'>";
try {
    $dr = $pdo->query("SELECT * FROM document_requirements WHERE transaction_type IN ('umrah','all') ORDER BY id ASC")->fetchAll();
    echo (count($dr)>0?"<span class='ok'>✓</span>":"<span class='bad'>⚠️</span>") . " عدد متطلبات المستندات للعمرة: <b>" . count($dr) . "</b>";
    if (count($dr)) {
        echo "<div class='mt-2'><table class='table table-sm table-bordered small'><thead class='bg-light text-muted'><tr><th>الاسم</th><th>النوع</th><th>إجباري</th></tr></thead><tbody>";
        foreach ($dr as $d) {
            echo "<tr><td>" . htmlspecialchars($d['requirement_name']) . "</td><td>" . htmlspecialchars($d['requirement_type']) . "</td><td>" . ($d['is_required']?'<span class="badge bg-success">نعم</span>':'<span class="badge bg-secondary">لا</span>') . "</td></tr>";
        }
        echo "</tbody></table></div>";
    }
} catch (Throwable $e) {
    echo "<div><span class='bad'>✗</span> خطأ: {$e->getMessage()}</div>";
    $allPass = false;
}
echo "</div></div>";

// 4) فحص معاملات عمرة حديثة مع حقول سير العمل
echo "<div class='card mb-3 shadow-sm'><div class='card-header bg-white fw-bold'>٤. عينة من معاملات العمرة الحديثة</div><div class='card-body'>";
try {
    $pps = $pdo->query("SELECT p.id, p.full_name, p.passport_number, p.status_id, p.workflow_id, p.workflow_step_id, s.status_name, ws.step_name as wf_step FROM passports p LEFT JOIN statuses s ON p.status_id=s.id LEFT JOIN workflow_steps ws ON p.workflow_step_id=ws.id WHERE p.transaction_type='umrah' AND p.deleted_at IS NULL ORDER BY p.id DESC LIMIT 5")->fetchAll();
    if (count($pps) == 0) {
        echo "<div class='text-muted small'>⚠️ لا توجد معاملات عمرة حالياً (سيتم إنشاؤها من واجهة المستخدم).</div>";
    } else {
        echo "<table class='table table-sm table-bordered small'><thead class='bg-light text-muted'><tr><th>ID</th><th>الاسم</th><th>الجواز</th><th>الحالة</th><th>workflow_id</th><th>workflow_step_id</th><th>اسم المرحلة</th></tr></thead><tbody>";
        foreach ($pps as $pp) {
            echo "<tr>";
            echo "<td>".$pp['id']."</td>";
            echo "<td>".htmlspecialchars($pp['full_name'] ?? '')."</td>";
            echo "<td>".htmlspecialchars($pp['passport_number'] ?? '')."</td>";
            echo "<td>".htmlspecialchars($pp['status_name'] ?? '')."</td>";
            echo "<td>".($pp['workflow_id']??'<span class="text-muted">-</span>')."</td>";
            echo "<td>".($pp['workflow_step_id']??'<span class="text-muted">-</span>')."</td>";
            echo "<td>".htmlspecialchars($pp['wf_step'] ?? '<span class="text-muted">-</span>')."</td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }
} catch (Throwable $e) {
    echo "<div><span class='bad'>✗</span> خطأ: {$e->getMessage()}</div>";
}
echo "</div></div>";

// 5) فحص الحقول الديناميكية (workflow_fields و step_fields للعمرة)
echo "<div class='card mb-3 shadow-sm'><div class='card-header bg-white fw-bold'>٥. الحقول الديناميكية (workflow_fields + step_fields)</div><div class='card-body'>";
try {
    $fcount = (int)$pdo->query("SELECT COUNT(*) FROM workflow_fields WHERE is_active=1")->fetchColumn();
    echo "<div>" . ($fcount>0?"<span class='ok'>✓</span>":"<span class='bad'>⚠️</span>") . " الحقول المفعلة في workflow_fields: <b>$fcount</b></div>";

    // حقول مراحل سير العمل الأول
    $wf2 = $pdo->query("SELECT id FROM workflows WHERE transaction_type IN ('umrah','all') ORDER BY transaction_type='umrah' DESC, id ASC LIMIT 1")->fetchColumn();
    if ($wf2) {
        $sf = $pdo->prepare("SELECT COUNT(*) FROM workflow_step_fields sf JOIN workflow_steps s ON sf.step_id=s.id WHERE s.workflow_id=?");
        $sf->execute([$wf2]);
        $sfCount = (int)$sf->fetchColumn();
        echo "<div class='mt-1 ms-3'>" . ($sfCount>0?"<span class='ok'>✓</span>":"<span class='text-muted'>ℹ️</span>") . " تعيينات step_fields لهذا السير: <b>$sfCount</b></div>";
    }
} catch (Throwable $e) {
    echo "<div><span class='bad'>✗</span> خطأ: {$e->getMessage()}</div>";
}
echo "</div></div>";

// 6) النتيجة النهائية
echo "<div class='alert " . ($allPass?"alert-success":"alert-warning") . " border-0 shadow-sm'>";
echo "<h5 class='fw-bold mb-1'><i class='fas " . ($allPass?"fa-circle-check":"fa-triangle-exclamation") . " me-2'></i>" . ($allPass?"نجاح الفحص الشامل":"تحذيرات");
echo "</h5><div class='small'>";
if ($allPass) echo "✅ جميع الفحوصات نجحت! البنية التحتية وسير العمل والجداول والحقول جاهزة للاستخدام في صفحة العمرة.";
else echo "⚠️ هناك بعض العناصر الناقصة. مراجعة الأيقونات الحمراء أعلاه وإصلاحها.";
echo "</div></div>";

echo "</div></body></html>";
?>

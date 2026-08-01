<?php
/**
 * فحص جداول workflow في قاعدة البيانات
 * يعرض: بنية الجداول + عدد السجلات + بيانات عينة
 */
$DB_HOST='127.0.0.1';$DB_USER='root';$DB_PASS='738155';$DB_NAME='ghazali';
$pdo=new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
header('Content-Type: text/html; charset=utf-8');

$wf_tables=['workflows','workflow_steps','workflow_transitions','workflow_approval_requests','workflow_checklists','workflow_fields','workflow_field_values'];
echo "<h3>📊 فحص جداول سير العمل (Workflow Tables)</h3>";
echo "<pre style='background:#f6f8fa;padding:15px;border-radius:8px;border:1px solid #d0d7de'>";

foreach ($wf_tables as $tbl) {
    echo str_repeat('=',80)."\n📋 جدول: $tbl\n".str_repeat('-',80)."\n";
    try {
        // عدد السجلات
        $c=$pdo->query("SELECT COUNT(*) AS c FROM `$tbl`")->fetchColumn();
        echo "• عدد السجلات: $c\n";

        // بنية الأعمدة
        echo "• بنية الجدول:\n";
        $cols=$pdo->query("DESCRIBE `$tbl`")->fetchAll();
        foreach ($cols as $col) {
            echo "    - " . str_pad($col['Field'], 28) . " " . str_pad($col['Type'], 22) . " " .
                 ($col['Null']=='YES'?'NULL':'NOT NULL') . " " .
                 (($col['Key']=='PRI')?'🔑 PK':(($col['Key']=='MUL')?'🔍 INDEX':'')) . " " .
                 (($col['Default']!==null && $col['Default']!=='')?"DEFAULT={$col['Default']}":'') . "\n";
        }

        // عينة من البيانات (إذا وجدت)
        if ($c > 0) {
            echo "• عينة من البيانات (أولى 3 صفوف):\n";
            $rows=$pdo->query("SELECT * FROM `$tbl` LIMIT 3")->fetchAll();
            foreach ($rows as $ri => $row) {
                $vals=[];
                foreach ($row as $k=>$v) {
                    if ($v===null) $v='NULL';
                    elseif (strlen((string)$v)>38) $v=mb_substr((string)$v,0,38).'...';
                    $vals[]="$k=$v";
                }
                echo "    صف #".($ri+1).": | ".implode(' | ',$vals)." |\n";
            }
        } else {
            echo "• ⚠️  لا توجد بيانات حالياً في هذا الجدول\n";
        }
    } catch (Throwable $e) {
        echo "    ❌ تعذر القراءة: ".$e->getMessage()."\n";
    }
    echo "\n";
}

echo str_repeat('=',80)."\n🔗 العلاقات بين الجداول (البصري):\n\n";
echo "  workflows (id) ──┬──> workflow_steps.workflow_id\n";
echo "                  └──> workflow_transitions.workflow_id\n\n";
echo "  workflow_steps (id) ─┬──> workflow_transitions.from_step_id\n";
echo "                       └──> workflow_transitions.to_step_id\n\n";
echo "  workflow_transitions.require_approval  → workflow_approval_requests\n";
echo "  workflow_steps.show_checklist = 1      → workflow_checklists\n";
echo "  workflow_steps.show_fields[]           → workflow_fields → workflow_field_values\n";
echo "\n".str_repeat('=',80)."\n";

echo "\n✅ ملخص آلية البيانات:\n";
echo "  1. workflows:       سير العمل الرئيسي لكل نوع معاملة + فرع\n";
echo "  2. workflow_steps:  المراحل داخل كل سير عمل (مرتبطة بجدول statuses)\n";
echo "  3. workflow_transitions: قواعد الانتقال بين مراحل + صلاحيات الدور/المستخدم\n";
echo "  4. workflow_approval_requests: طلبات الاعتماد للانتقالات التي تتطلب ذلك\n";
echo "  5. workflow_checklists: قوائم التحقق (Checklist) لكل مرحلة\n";
echo "  6. workflow_fields + values: الحقول الإضافية المخصصة لكل نوع معاملة\n";
echo "</pre>";

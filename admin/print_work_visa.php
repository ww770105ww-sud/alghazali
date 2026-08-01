<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

// 1. التحقق من الصلاحية
if (!has_permission('work_visa_print')) {
    // يمكنك عرض رسالة خطأ أو إعادة التوجيه
    die("ليس لديك صلاحية لطباعة هذه الصفحة.");
}

// 2. جلب معرف المعاملة والتحقق منه
$passport_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($passport_id === 0) {
    die("معرف المعاملة غير صحيح.");
}

// 3. جلب بيانات المعاملة الرئيسية
$stmt = $pdo->prepare("
    SELECT p.*, pr.name_ar as profession_name, s.status_name, s.status_color as status_color, a.agent_name, b.branch_name
    FROM passports p
    LEFT JOIN professions pr ON p.profession_id = pr.id
    LEFT JOIN statuses s ON p.status_id = s.id
    LEFT JOIN agents a ON p.agent_id = a.id
    LEFT JOIN branches b ON p.branch_id = b.id
    WHERE p.id = ? AND (p.transaction_type = 'work_visa' OR p.transaction_type = '6')
");
$stmt->execute([$passport_id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    die("المعاملة غير موجودة.");
}

// 4. جلب البيانات المالية المرتبطة (إذا وجدت)
$financial_data = [];
if (has_permission('work_visa_financial_view')) {
    // جلب إجمالي المدفوعات (استخدام جدول المستندات الجديد مباشرة لضمان الاستقرار)
    $paid_stmt = $pdo->prepare("SELECT SUM(total_amount) FROM documents WHERE reference_id = ? AND reference_type = 'work_visa' AND document_type = 'Receipt_Voucher'");
    $paid_stmt->execute([$passport_id]);
    $paid_amount = $paid_stmt->fetchColumn() ?: 0;

    // جلب الحساب المالي المرتبط (تعديل ليتناسب مع شجرة الحسابات الجديدة)
    $linked_account = null;
    if ($transaction['agent_id']) {
        $acc_stmt = $pdo->prepare("SELECT account_name_ar as account_name FROM unified_accounts ua JOIN agents a ON ua.id = a.account_id WHERE a.id = ? LIMIT 1");
        $acc_stmt->execute([$transaction['agent_id']]);
        $linked_account = $acc_stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($transaction['branch_id']) {
        $acc_stmt = $pdo->prepare("SELECT account_name_ar as account_name FROM unified_accounts ua JOIN branches b ON ua.id = b.account_id WHERE b.id = ? LIMIT 1");
        $acc_stmt->execute([$transaction['branch_id']]);
        $linked_account = $acc_stmt->fetch(PDO::FETCH_ASSOC);
    }

    $financial_data['paid_amount'] = $paid_amount;
    $financial_data['linked_account'] = $linked_account;
}

// 5. جلب سجل الحركات (Audit Log)
$logs_stmt = $pdo->prepare("
    SELECT tsl.*, u.full_name as changer_name, r.display_name as role_name, ts.status_name as new_status
    FROM transaction_status_logs tsl
    JOIN users u ON tsl.changed_by = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    LEFT JOIN statuses ts ON tsl.new_status_id = ts.id
    WHERE tsl.transaction_id = ?
    ORDER BY tsl.changed_at DESC
");
$logs_stmt->execute([$passport_id]);
$audit_logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>طباعة ملخص المعاملة #<?php echo $transaction['id']; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Tajawal', sans-serif;
            /* Recommended for Arabic */
        }

        .print-container {
            max-width: 800px;
            margin: 20px auto;
            background: #fff;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.05);
        }

        .print-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #eee;
            padding-bottom: 20px;
        }

        .print-header h2 {
            margin: 0;
            font-weight: 700;
            color: #0056b3;
        }

        .print-header p {
            margin: 5px 0 0;
            color: #6c757d;
        }

        .section-title {
            font-weight: 600;
            color: #0056b3;
            border-bottom: 2px solid #0056b3;
            padding-bottom: 5px;
            margin-top: 25px;
            margin-bottom: 15px;
            display: inline-block;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }

        .info-item {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 8px;
            border-left: 4px solid #0056b3;
        }

        .info-item label {
            display: block;
            font-size: 0.8rem;
            color: #6c757d;
            margin-bottom: 2px;
        }

        .info-item span {
            font-weight: 600;
            color: #343a40;
        }

        .attachments-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }

        .attachment-item img {
            width: 100%;
            height: 100px;
            object-fit: contain;
            border: 1px solid #dee2e6;
            border-radius: 5px;
            padding: 5px;
        }

        .timeline {
            border-right: 3px solid #dee2e6;
            padding-right: 20px;
            position: relative;
        }

        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }

        .timeline-item:before {
            content: '';
            position: absolute;
            right: -11px;
            top: 5px;
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background: #fff;
            border: 3px solid #0056b3;
        }

        @media print {
            body {
                background-color: #fff;
            }

            .print-container {
                box-shadow: none;
                margin: 0;
                max-width: 100%;
                border-radius: 0;
            }

            .no-print {
                display: none;
            }
        }
    </style>
</head>

<body>
    <div class="print-container">
        <div class="print-header">
            <h2>ملخص معاملة تأشيرة عمل</h2>
            <p>رقم المعاملة: #<?php echo $transaction['id']; ?> | تاريخ الطباعة: <?php echo date('Y-m-d H:i'); ?></p>
        </div>

        <button class="btn btn-primary no-print mb-3" onclick="window.print()"><i class="fas fa-print me-2"></i> طباعة</button>

        <h5 class="section-title">البيانات الأساسية</h5>
        <div class="info-grid">
            <div class="info-item"><label>الاسم الكامل</label><span><?php echo htmlspecialchars($transaction['full_name']); ?></span></div>
            <div class="info-item"><label>الاسم (إنجليزي)</label><span><?php echo htmlspecialchars($transaction['full_name_en'] ?? '---'); ?></span></div>
            <div class="info-item"><label>رقم الجواز</label><span><?php echo htmlspecialchars($transaction['passport_number']); ?></span></div>
            <div class="info-item"><label>الجنسية</label><span><?php echo htmlspecialchars($transaction['nationality'] ?? '---'); ?></span></div>
            <div class="info-item"><label>المهنة</label><span><?php echo htmlspecialchars($transaction['profession_name'] ?? '---'); ?></span></div>
            <div class="info-item"><label>الجهة</label><span><?php echo htmlspecialchars($transaction['agent_name'] ?? $transaction['branch_name'] ?? '---'); ?></span></div>
            <div class="info-item"><label>الحالة الحالية</label><span style="color: <?php echo $transaction['status_color']; ?>;"><?php echo htmlspecialchars($transaction['status_name']); ?></span></div>
            <div class="info-item"><label>تاريخ الإنشاء</label><span><?php echo date('Y-m-d', strtotime($transaction['created_at'])); ?></span></div>
        </div>

        <?php if (has_permission('work_visa_financial_view') && !empty($financial_data)): ?>
            <h5 class="section-title">البيانات المالية</h5>
            <div class="info-grid">
                <div class="info-item"><label>سعر البيع</label><span><?php echo number_format($transaction['sale_price'], 2); ?></span></div>
                <div class="info-item"><label>سعر الوكيل/الفرع</label><span><?php echo number_format($transaction['agent_price'] ?: $transaction['branch_price'], 2); ?></span></div>
                <div class="info-item"><label>المبلغ المدفوع</label><span class="text-success"><?php echo number_format($financial_data['paid_amount'], 2); ?></span></div>
                <div class="info-item"><label>المبلغ المتبقي</label><span class="text-danger"><?php echo number_format(max(0, ($transaction['agent_price'] ?: $transaction['branch_price']) - $financial_data['paid_amount']), 2); ?></span></div>
                <div class="info-item"><label>حالة الدفع</label><span><?php echo $transaction['payment_status']; ?></span></div>
            </div>
        <?php endif; ?>

        <h5 class="section-title">المرفقات</h5>
        <div class="attachments-grid">
            <?php
            $attachments = ['passport_image', 'personal_photo', 'exit_image', 'authorization_image', 'deportation_image', 'letter_image', 'print_image'];
            foreach ($attachments as $key):
                if (!empty($transaction[$key])):
            ?>
                    <div class="attachment-item">
                        <a href="../assets/uploads/<?php echo $transaction[$key]; ?>" target="_blank">
                            <img src="../assets/uploads/<?php echo $transaction[$key]; ?>" alt="<?php echo $key; ?>">
                        </a>
                    </div>
            <?php endif;
            endforeach; ?>
        </div>

        <h5 class="section-title">سجل الحركات</h5>
        <div class="timeline">
            <?php foreach ($audit_logs as $log): ?>
                <div class="timeline-item">
                    <div class="fw-bold"><?php echo htmlspecialchars($log['new_status']); ?></div>
                    <div class="small text-muted"><?php echo date('Y-m-d H:i', strtotime($log['changed_at'])); ?> بواسطة <?php echo htmlspecialchars($log['changer_name']); ?></div>
                    <?php if (!empty($log['notes'])): ?><p class="small fst-italic bg-light p-2 rounded mt-1">"<?php echo htmlspecialchars($log['notes']); ?>"</p><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

    </div>
</body>

</html>

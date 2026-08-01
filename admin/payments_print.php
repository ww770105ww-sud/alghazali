<?php
ob_start();
require_once 'header.php';

// التحقق من الصلاحية
if (!$is_admin && !in_array($user_role, ['accountant', 'branch_manager', 'branch_user'])) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$id = $_GET['id'] ?? null;
if (!$id) {
    echo "خطأ: لم يتم تحديد السند.";
    exit();
}

// جلب بيانات السند من الجدول الموحد
$stmt = $pdo->prepare("
    SELECT t.id, t.transaction_number as voucher_number, t.transaction_date as date,
           t.amount, t.description, t.reference_number,
           t.entity_type as payee_type, t.entity_id as payee_id,
           c.currency_name, c.currency_symbol,
           ua.account_name_ar as account_name,
           CASE 
               WHEN t.entity_type = 'agent' THEN (SELECT agent_name FROM agents WHERE id = t.entity_id)
               WHEN t.entity_type = 'branch' THEN (SELECT branch_name FROM branches WHERE id = t.entity_id)
               WHEN t.entity_type = 'customer' THEN (SELECT full_name FROM customers WHERE id = t.entity_id)
               WHEN t.entity_type = 'supplier' THEN (SELECT supplier_name FROM suppliers WHERE id = t.entity_id)
               ELSE 'جهة غير معروفة'
           END as payee_name,
           u.username as creator_name
    FROM financial_transactions t
    LEFT JOIN currencies c ON t.currency_id = c.id
    LEFT JOIN unified_accounts ua ON t.cash_bank_account_id = ua.id
    LEFT JOIN users u ON t.created_by = u.id
    WHERE t.id = ?
");
$stmt->execute([$id]);
$payment = $stmt->fetch();

if (!$payment) {
    // محاولة جلب الفاتورة إذا كان المعرف لفاتورة
    $stmt_inv = $pdo->prepare("SELECT i.*, c.currency_name, c.currency_symbol, b.branch_name FROM invoices i JOIN currencies c ON i.currency_id = c.id JOIN branches b ON i.branch_id = b.id WHERE i.id = ?");
    $stmt_inv->execute([$id]);
    $payment = $stmt_inv->fetch();
    if ($payment) {
         $payment['voucher_number'] = $payment['invoice_number'];
         $payment['date'] = $payment['invoice_date'];
         $payment['amount'] = $payment['net_amount'];
         
         // تحديد اسم الجهة للفاتورة
         $payee_name = "فاتورة عامة";
         if ($payment['invoice_category'] == 'purchase' && !empty($payment['supplier_id'])) {
             $stmt_s = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
             $stmt_s->execute([$payment['supplier_id']]);
             $payee_name = "مورد: " . ($stmt_s->fetchColumn() ?: "غير موجود");
         } elseif ($payment['invoice_category'] == 'sales' && !empty($payment['account_id'])) {
             $stmt_u = $pdo->prepare("SELECT account_name_ar FROM unified_accounts WHERE id = ?");
             $stmt_u->execute([$payment['account_id']]);
             $payee_name = $stmt_u->fetchColumn() ?: "حساب غير معرف";
         }
         
         $payment['payee_name'] = $payee_name;
         $payment['account_name'] = "حساب الفاتورة";
         $payment['creator_name'] = "النظام";
     }
}

if (!$payment) {
    echo "خطأ: السند غير موجود.";
    exit();
}

$settings = getSettings($pdo);

// دالة التفقيط بالعربي المطورة
function tafkeet($number, $currency = "") {
    if ($number == 0) return "صفر";
    
    $hyphen      = ' و';
    $conjunction = ' و';
    $separator   = ' ';
    $negative    = 'سالب ';
    $decimal     = ' فاصلة ';
    $dictionary  = array(
        0                   => 'صفر',
        1                   => 'واحد',
        2                   => 'اثنان',
        3                   => 'ثلاثة',
        4                   => 'أربعة',
        5                   => 'خمسة',
        6                   => 'ستة',
        7                   => 'سبعة',
        8                   => 'ثمانية',
        9                   => 'تسعة',
        10                  => 'عشرة',
        11                  => 'أحد عشر',
        12                  => 'اثنا عشر',
        13                  => 'ثلاثة عشر',
        14                  => 'أربعة عشر',
        15                  => 'خمسة عشر',
        16                  => 'ستة عشر',
        17                  => 'سبعة عشر',
        18                  => 'ثمانية عشر',
        19                  => 'تسعة عشر',
        20                  => 'عشرون',
        30                  => 'ثلاثون',
        40                  => 'أربعون',
        50                  => 'خمسون',
        60                  => 'ستون',
        70                  => 'سبعون',
        80                  => 'ثمانون',
        90                  => 'تسعون',
        100                 => 'مائة',
        200                 => 'مئتان',
        300                 => 'ثلاثمائة',
        400                 => 'أربعمائة',
        500                 => 'خمسمائة',
        600                 => 'ستمائة',
        700                 => 'سبعمائة',
        800                 => 'ثمانمائة',
        900                 => 'تسعة مائة',
        1000                => 'ألف',
        2000                => 'ألفين',
        3000                => 'ثلاثة آلاف',
        4000                => 'أربعة آلاف',
        5000                => 'خمسة آلاف',
        6000                => 'ستة آلاف',
        7000                => 'سبعة آلاف',
        8000                => 'ثمانية آلاف',
        9000                => 'تسعة آلاف',
        10000               => 'عشرة آلاف',
        1000000             => 'مليون',
        1000000000          => 'مليار'
    );
    
    if (!is_numeric($number)) return false;
    
    if ($number < 0) return $negative . tafkeet(abs($number), $currency);
    
    $string = $fraction = null;
    
    if (strpos($number, '.') !== false) {
        list($number, $fraction) = explode('.', $number);
    }
    
    switch (true) {
        case $number < 21:
            $string = $dictionary[$number];
            break;
        case $number < 100:
            $tens   = ((int) ($number / 10)) * 10;
            $units  = $number % 10;
            $string = $dictionary[$tens];
            if ($units) {
                $string = $dictionary[$units] . $hyphen . $string;
            }
            break;
        case $number < 1000:
            $hundreds  = ((int) ($number / 100)) * 100;
            $remainder = $number % 100;
            $string = $dictionary[$hundreds];
            if ($remainder) {
                $string .= $conjunction . tafkeet($remainder);
            }
            break;
        case $number < 1000000:
            $thousands = ((int) ($number / 1000));
            $remainder = $number % 1000;
            
            if ($thousands == 1) $string = "ألف";
            elseif ($thousands == 2) $string = "ألفين";
            elseif ($thousands >= 3 && $thousands <= 10) $string = $dictionary[$thousands * 1000];
            else $string = tafkeet($thousands) . " ألف";
            
            if ($remainder) {
                $string .= $conjunction . tafkeet($remainder);
            }
            break;
        default:
            $baseUnit = pow(1000, floor(log($number, 1000)));
            $numBaseUnits = (int) ($number / $baseUnit);
            $remainder = $number % $baseUnit;
            $string = tafkeet($numBaseUnits) . ' ' . $dictionary[$baseUnit];
            if ($remainder) {
                $string .= $conjunction . tafkeet($remainder);
            }
            break;
    }
    
    if (null !== $fraction && is_numeric($fraction) && (int)$fraction > 0) {
        $string .= $decimal . tafkeet($fraction);
    }
    
    return $string . ' ' . $currency;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طباعة سند صرف رقم <?php echo $payment['payment_number']; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; }
            .container { width: 100% !important; max-width: 100% !important; }
        }
        body { background-color: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .print-card { background: white; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.1); padding: 40px; margin-top: 30px; border: 1px solid #dee2e6; position: relative; }
        .print-header { border-bottom: 3px solid #dc3545; padding-bottom: 20px; margin-bottom: 30px; }
        .receipt-title { border: 2px solid #000; display: inline-block; padding: 5px 30px; border-radius: 10px; font-weight: bold; background: #f8f9fa; }
        .info-box { border: 1px solid #dee2e6; border-radius: 10px; padding: 15px; background: #fff; height: 100%; }
        .amount-badge { font-size: 1.5rem; font-weight: bold; color: #dc3545; border: 2px solid #dc3545; padding: 5px 20px; border-radius: 10px; display: inline-block; }
        .content-row { margin-bottom: 20px; font-size: 1.2rem; }
        .content-label { color: #6c757d; min-width: 120px; display: inline-block; }
        .content-value { border-bottom: 1px dashed #000; flex-grow: 1; padding-bottom: 2px; font-weight: bold; }
        .signature-section { margin-top: 50px; }
        .signature-box { border: 1px solid #dee2e6; border-radius: 10px; padding: 20px; text-align: center; background: #f8f9fa; }
    </style>
</head>
<body>

<div class="container no-print mt-3 text-center">
    <button onclick="window.print()" class="btn btn-primary btn-lg rounded-pill px-5 shadow">
        <i class="fas fa-print me-2"></i> طباعة السند الآن
    </button>
    <button onclick="window.close()" class="btn btn-secondary btn-lg rounded-pill px-5 shadow ms-2">
        <i class="fas fa-times me-2"></i> إغلاق النافذة
    </button>
</div>

<div class="container">
    <div class="print-card mx-auto" style="max-width: 900px;">
        <!-- Header -->
        <div class="print-header">
            <div class="row align-items-center">
                <div class="col-4 text-end">
                    <h4 class="fw-bold text-danger mb-1"><?php echo htmlspecialchars($settings['header_company_name'] ?? $settings['site_name']); ?></h4>
                    <small class="text-muted d-block"><?php echo htmlspecialchars($settings['header_address_1'] ?? ''); ?></small>
                    <small class="text-muted d-block"><?php echo htmlspecialchars($settings['header_phone_1'] ?? ''); ?></small>
                </div>
                <div class="col-4 text-center">
                    <?php if (!empty($settings['site_logo'])): ?>
                        <img src="../assets/uploads/<?php echo $settings['site_logo']; ?>" alt="Logo" style="max-height: 100px;">
                    <?php endif; ?>
                </div>
                <div class="col-4 text-start">
                    <div class="info-box small">
                        <div>رقم السند: <strong><?php echo $payment['payment_number']; ?></strong></div>
                        <div>التاريخ: <strong><?php echo $payment['date']; ?></strong></div>
                        <div>وقت الطباعة: <?php echo date('Y-m-d H:i'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Title -->
        <div class="text-center mb-5">
            <div class="receipt-title fs-3">
                <?php echo $payment['booking_id'] ? 'سند إلغاء حجز' : 'سند صرف مالي'; ?>
            </div>
        </div>

        <!-- Amount -->
        <div class="row mb-4">
            <div class="col-12 text-center">
                <div class="amount-badge">
                    <?php echo number_format($payment['amount'], 2); ?> <?php echo $payment['currency_name']; ?>
                </div>
                <?php if ($payment['booking_id']): ?>
                    <div class="mt-2 text-success fw-bold small">
                        (<?php echo tafkeet($payment['amount'], $payment['currency_name']); ?>)
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Content -->
        <div class="px-4">
            <div class="content-row d-flex align-items-center">
                <span class="content-label">صرفنا للسيد:</span>
                <span class="content-value"><?php echo htmlspecialchars($payment['traveler_name'] ?: $payment['payee_name']); ?></span>
            </div>
            
            <?php if ($payment['booking_id']): ?>
                <div class="content-row">
                    <div class="d-flex align-items-center mb-1">
                        <span class="content-label">إجمالي مبلغ الحجز:</span>
                        <span class="content-value"><?php echo number_format($payment['booking_total'], 2); ?> <?php echo $payment['currency_name']; ?></span>
                    </div>
                    <div class="text-muted extra-small ms-5">
                        (<?php echo tafkeet($payment['booking_total'], $payment['currency_name']); ?>)
                    </div>
                </div>

                <div class="content-row">
                    <div class="d-flex align-items-center mb-1 text-danger">
                        <span class="content-label">رسوم إلغاء حجز:</span>
                        <span class="content-value">- <?php echo number_format($payment['booking_fees'], 2); ?> <?php echo $payment['currency_name']; ?></span>
                    </div>
                    <div class="text-danger extra-small ms-5 opacity-75">
                        (<?php echo tafkeet($payment['booking_fees'], $payment['currency_name']); ?>)
                    </div>
                </div>

                <div class="content-row border-top pt-3 mt-3">
                    <div class="d-flex align-items-center mb-1 text-success">
                        <span class="content-label fw-bold">المبلغ المرتجع:</span>
                        <span class="content-value fw-bold fs-4">
                            <?php echo number_format($payment['amount'], 2); ?> <?php echo $payment['currency_name']; ?>
                            <span class="fs-6 ms-2 text-dark opacity-75">(<?php echo tafkeet($payment['amount'], $payment['currency_name']); ?> لا غير)</span>
                        </span>
                    </div>
                </div>
            <?php else: ?>
                <div class="content-row d-flex align-items-center">
                    <span class="content-label">مبلغ وقدره:</span>
                    <span class="content-value"><?php echo number_format($payment['amount'], 2); ?> <?php echo $payment['currency_name']; ?></span>
                </div>
                <div class="text-muted small ms-5 mb-3">
                    (<?php echo tafkeet($payment['amount'], $payment['currency_name']); ?>)
                </div>
            <?php endif; ?>

            <div class="content-row d-flex align-items-center mt-4">
                <span class="content-label">وذلك مقابل:</span>
                <span class="content-value"><?php echo htmlspecialchars($payment['description']); ?></span>
            </div>
            <div class="content-row d-flex align-items-center">
                <span class="content-label">طريقة الصرف:</span>
                <span class="content-value"><?php echo ($payment['payment_method'] == 'cash' ? 'نقد' : 'تحويل بنكي'); ?> (من حساب: <?php echo $payment['account_name']; ?>)</span>
            </div>
        </div>

        <!-- Signatures -->
        <div class="row signature-section g-4">
            <div class="col-4">
                <div class="signature-box">
                    <p class="fw-bold mb-4">المستلم</p>
                    <div style="height: 50px;"></div>
                    <small class="text-muted">(..................................)</small>
                </div>
            </div>
            <div class="col-4 text-center d-flex align-items-center justify-content-center">
                <div class="rounded-circle border border-danger text-danger opacity-25 d-flex align-items-center justify-content-center fw-bold" style="width: 100px; height: 100px; border-style: dashed !important;">
                    الختم
                </div>
            </div>
            <div class="col-4">
                <div class="signature-box">
                    <p class="fw-bold mb-4">المحاسب</p>
                    <div style="height: 50px;"></div>
                    <small class="text-muted"><?php echo htmlspecialchars($payment['creator_name']); ?></small>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-5 pt-3 border-top text-center text-muted small">
            <?php echo $settings['copyright_text'] ?? 'جميع الحقوق محفوظة © ' . date('Y'); ?>
        </div>
    </div>
</div>

<script>
    // التوجيه للطباعة تلقائياً
    window.onload = function() {
        // window.print(); 
    }
</script>

</body>
</html>
<?php ob_end_flush(); ?>

<?php
$page_title = "مركز التحكم المالي";
require_once 'header.php';

// Stats for the hub
try {
    // Stats for the hub using unified system
    $total_invoices = $pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_status = 'posted'")->fetchColumn();
    $total_invoices_amount = $pdo->query("SELECT SUM(net_amount) FROM invoices WHERE invoice_status = 'posted'")->fetchColumn() ?: 0;

    $total_payments = $pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE transaction_type IN ('receipt', 'payment') AND status = 'posted'")->fetchColumn();
    $total_payments_amount = $pdo->query("SELECT SUM(amount) FROM financial_transactions WHERE transaction_type IN ('receipt', 'payment') AND status = 'posted'")->fetchColumn() ?: 0;

    $pending_posting = $pdo->query("SELECT COUNT(*) FROM invoices WHERE invoice_status = 'draft'")->fetchColumn();
    $draft_documents = $pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE status = 'draft'")->fetchColumn();
    $unapproved_journal = $pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE transaction_type = 'journal' AND status = 'draft'")->fetchColumn();

    // Get total expenses for the current month
    $current_month_expenses = $pdo->query("
        SELECT SUM(amount)
        FROM financial_transactions
        WHERE transaction_type = 'payment'
        AND entity_type = 'other'
        AND MONTH(transaction_date) = MONTH(CURRENT_DATE())
        AND YEAR(transaction_date) = YEAR(CURRENT_DATE())
        AND status = 'posted'
    ")->fetchColumn() ?: 0;

    // Current month sales
    $current_month_sales = $pdo->query("
        SELECT SUM(net_amount)
        FROM invoices
        WHERE invoice_category = 'sales'
        AND invoice_status = 'posted'
        AND MONTH(invoice_date) = MONTH(CURRENT_DATE())
        AND YEAR(invoice_date) = YEAR(CURRENT_DATE())
    ")->fetchColumn() ?: 0;

    // Current month purchases
    $current_month_purchases = $pdo->query("
        SELECT SUM(net_amount)
        FROM invoices
        WHERE invoice_category = 'purchase'
        AND invoice_status = 'posted'
        AND MONTH(invoice_date) = MONTH(CURRENT_DATE())
        AND YEAR(invoice_date) = YEAR(CURRENT_DATE())
    ")->fetchColumn() ?: 0;

    // Unpaid invoices (receivables)
    $unpaid_invoices = $pdo->query("
        SELECT COUNT(*)
        FROM invoices
        WHERE invoice_status = 'posted'
        AND payment_status != 'fully_paid'
    ")->fetchColumn();
    $unpaid_invoices_amount = $pdo->query("
        SELECT SUM(net_amount - amount_received)
        FROM invoices
        WHERE invoice_status = 'posted'
        AND payment_status != 'fully_paid'
    ")->fetchColumn() ?: 0;

    // Total customers
    $total_customers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();

    // Total suppliers
    $total_suppliers = $pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();

    // Current month receipts
    $current_month_receipts = $pdo->query("
        SELECT SUM(amount)
        FROM financial_transactions
        WHERE transaction_type = 'receipt'
        AND status = 'posted'
        AND MONTH(transaction_date) = MONTH(CURRENT_DATE())
        AND YEAR(transaction_date) = YEAR(CURRENT_DATE())
    ")->fetchColumn() ?: 0;

    // Current month payments (all)
    $current_month_all_payments = $pdo->query("
        SELECT SUM(amount)
        FROM financial_transactions
        WHERE transaction_type = 'payment'
        AND status = 'posted'
        AND MONTH(transaction_date) = MONTH(CURRENT_DATE())
        AND YEAR(transaction_date) = YEAR(CURRENT_DATE())
    ")->fetchColumn() ?: 0;

    // Recent activities (Last 12 activities for more detail)
    $recent_activities = $pdo->query("
        (SELECT
            i.id, i.invoice_number as doc_number, i.invoice_date as doc_date, i.currency_id, i.net_amount as amount,
            CASE WHEN i.invoice_category = 'sales' THEN 'فاتورة مبيعات' ELSE 'فاتورة مشتريات' END as doc_type_ar,
            'invoice' as doc_origin,
            i.invoice_category as category,
            CASE
                WHEN i.invoice_category = 'sales' THEN (SELECT full_name FROM customers WHERE id = i.customer_id)
                ELSE (SELECT supplier_name FROM suppliers WHERE id = i.supplier_id)
            END as party_name,
            i.description,
            i.created_at,
            c.currency_symbol
        FROM invoices i
        JOIN currencies c ON i.currency_id = c.id)
        UNION ALL
        (SELECT
            t.id, t.transaction_number as doc_number, t.transaction_date as doc_date, t.currency_id, t.amount,
            CASE
                WHEN t.transaction_type = 'receipt' THEN 'سند قبض'
                WHEN t.transaction_type = 'payment' THEN 'سند صرف'
                WHEN t.transaction_type = 'transfer' THEN 'تحويل مالي'
                ELSE 'قيد محاسبي'
            END as doc_type_ar,
            'transaction' as doc_origin,
            t.transaction_type as category,
            CASE
                WHEN t.entity_type = 'customer' THEN (SELECT full_name FROM customers WHERE id = t.entity_id)
                WHEN t.entity_type = 'supplier' THEN (SELECT supplier_name FROM suppliers WHERE id = t.entity_id)
                WHEN t.entity_type = 'agent' THEN (SELECT agent_name FROM agents WHERE id = t.entity_id)
                WHEN t.entity_type = 'employee' THEN (SELECT full_name FROM employees WHERE id = t.entity_id)
                ELSE 'حساب عام'
            END as party_name,
            t.description,
            t.created_at,
            c.currency_symbol
        FROM financial_transactions t
        JOIN currencies c ON t.currency_id = c.id)
        ORDER BY created_at DESC LIMIT 12
    ")->fetchAll();

    // Balances summary by currency
    $currency_balances = $pdo->query("
        SELECT c.currency_name, c.currency_symbol, SUM(ab.current_balance) as total_balance
        FROM account_balances_unified ab
        JOIN currencies c ON ab.currency_id = c.id
        GROUP BY c.id
    ")->fetchAll();
} catch (PDOException $e) {
    // If we're here, some tables might be missing
    $error_message = $e->getMessage();
    $total_invoices = 0;
    $total_invoices_amount = 0;
    $total_payments = 0;
    $total_payments_amount = 0;
    $pending_posting = 0;
    $draft_documents = 0;
    $unapproved_journal = 0;
    $current_month_expenses = 0;
    $current_month_sales = 0;
    $current_month_purchases = 0;
    $unpaid_invoices = 0;
    $unpaid_invoices_amount = 0;
    $total_customers = 0;
    $total_suppliers = 0;
    $current_month_receipts = 0;
    $current_month_all_payments = 0;
    $recent_activities = [];
    $currency_balances = [];
    $migration_needed = true;
}
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
    :root {
        --apple-bg: #f5f5f7;
        --apple-card-bg: rgba(255, 255, 255, 0.72);
        --apple-blue: #007aff;
        --apple-green: #34c759;
        --apple-red: #ff3b30;
        --apple-orange: #ff9500;
        --apple-purple: #5856d6;
        --apple-teal: #5ac8fa;
        --apple-gray: #8e8e93;
        --apple-gray-light: #c7c7cc;
        --apple-radius: 20px;
        --apple-radius-sm: 12px;
        --apple-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
        --apple-shadow-hover: 0 10px 40px rgba(0, 0, 0, 0.12);
        --transition-base: all 0.3s cubic-bezier(0.25, 0.1, 0.25, 1);
    }

    body {
        background-color: var(--apple-bg);
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        color: #1d1d1f;
        -webkit-font-smoothing: antialiased;
    }

    .apple-container {
        max-width: 1400px;
        margin: 0 auto;
        padding: 2.5rem 2rem;
    }

    .apple-card {
        background: var(--apple-card-bg);
        backdrop-filter: saturate(180%) blur(20px);
        -webkit-backdrop-filter: saturate(180%) blur(20px);
        border-radius: var(--apple-radius);
        border: 1px solid rgba(255, 255, 255, 0.4);
        box-shadow: var(--apple-shadow);
        transition: var(--transition-base);
        overflow: hidden;
    }

    .apple-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--apple-shadow-hover);
        background: rgba(255, 255, 255, 0.85);
    }

    .apple-header {
        margin-bottom: 3rem;
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
    }

    .apple-title {
        font-weight: 700;
        letter-spacing: -0.022em;
        color: #1d1d1f;
        margin-bottom: 0.2rem;
    }

    .apple-subtitle {
        color: var(--apple-gray);
        font-weight: 500;
        letter-spacing: -0.01em;
    }

    .stat-pill {
        padding: 1.5rem;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        height: 100%;
        min-height: 160px;
    }

    .stat-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 1.5rem;
    }

    .stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }

    .icon-blue {
        background: var(--apple-blue);
        color: white;
    }

    .icon-green {
        background: var(--apple-green);
        color: white;
    }

    .icon-orange {
        background: var(--apple-orange);
        color: white;
    }

    .icon-red {
        background: var(--apple-red);
        color: white;
    }

    .icon-purple {
        background: var(--apple-purple);
        color: white;
    }

    .icon-teal {
        background: var(--apple-teal);
        color: white;
    }

    .text-teal {
        color: var(--apple-teal);
    }

    .text-purple {
        color: var(--apple-purple);
    }

    .text-orange {
        color: var(--apple-orange);
    }

    .stat-value {
        font-size: 1.8rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        line-height: 1.2;
    }

    .stat-label {
        font-size: 0.9rem;
        font-weight: 500;
        color: var(--apple-gray);
        margin-top: 0.2rem;
    }

    .nav-item-apple {
        display: flex;
        align-items: center;
        padding: 0.8rem 1rem;
        border-radius: var(--apple-radius-sm);
        text-decoration: none !important;
        color: #1d1d1f;
        margin-bottom: 0.3rem;
        transition: var(--transition-base);
        font-weight: 500;
    }

    .nav-item-apple:hover {
        background: rgba(0, 122, 255, 0.08);
        color: var(--apple-blue);
        transform: translateX(5px);
    }

    [dir="rtl"] .nav-item-apple:hover {
        transform: translateX(-5px);
    }

    .nav-item-apple i {
        width: 28px;
        font-size: 1rem;
        margin-right: 0.5rem;
    }

    [dir="rtl"] .nav-item-apple i {
        margin-right: 0;
        margin-left: 0.5rem;
    }

    .section-label {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--apple-gray);
        margin: 2rem 0 0.8rem 0.5rem;
        letter-spacing: 0.05em;
    }

    .activity-list {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }

    .recent-activity-item {
        padding: 0.8rem 1rem;
        border-radius: var(--apple-radius-sm);
        transition: var(--transition-base);
        background: rgba(0, 0, 0, 0.01);
    }

    .recent-activity-item:hover {
        background: rgba(0, 0, 0, 0.03);
        transform: scale(1.01);
    }

    .balance-card {
        padding: 1.2rem;
        border-radius: var(--apple-radius-sm);
        background: white;
        border: 1px solid rgba(0, 0, 0, 0.04);
        flex: 1;
        min-width: 180px;
    }

    .migration-alert {
        background: #fff;
        border: none;
        border-radius: var(--apple-radius);
        padding: 1.5rem;
        box-shadow: 0 4px 15px rgba(255, 149, 0, 0.15);
    }

    /* Animation */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .fade-in {
        animation: fadeInUp 0.6s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
    }

    .delay-1 {
        animation-delay: 0.1s;
    }

    .delay-2 {
        animation-delay: 0.2s;
    }

    .delay-3 {
        animation-delay: 0.3s;
    }

    /* ===== Dark Mode Support ===== */
    body.theme-dark {
        background-color: #0b1220 !important;
        color: #f8fafc !important;
    }

    body.theme-dark .apple-title,
    body.theme-dark .fw-bold {
        color: #f8fafc !important;
    }

    body.theme-dark .apple-subtitle,
    body.theme-dark .section-label,
    body.theme-dark .stat-label {
        color: #94a3b8 !important;
    }

    body.theme-dark .apple-card {
        background: rgba(30, 41, 59, 0.7) !important;
        border-color: rgba(255, 255, 255, 0.05) !important;
    }

    body.theme-dark .apple-card:hover {
        background: rgba(30, 41, 59, 0.95) !important;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.4) !important;
    }

    body.theme-dark .nav-item-apple {
        color: #e2e8f0 !important;
    }

    body.theme-dark .nav-item-apple:hover {
        background: rgba(59, 130, 246, 0.15) !important;
        color: #60a5fa !important;
    }

    body.theme-dark .stat-value {
        color: #f8fafc;
    }

    body.theme-dark .stat-value.text-danger {
        color: #f87171 !important;
    }

    body.theme-dark .stat-value.text-success {
        color: #4ade80 !important;
    }

    body.theme-dark .stat-value.text-teal {
        color: #22d3ee !important;
    }

    body.theme-dark .stat-value.text-purple {
        color: #a78bfa !important;
    }

    body.theme-dark .stat-value.text-orange {
        color: #fb923c !important;
    }

    body.theme-dark .stat-value.text-blue {
        color: #60a5fa !important;
    }

    body.theme-dark .recent-activity-item {
        background: rgba(255, 255, 255, 0.02) !important;
        border-bottom-color: rgba(255, 255, 255, 0.05) !important;
    }

    body.theme-dark .recent-activity-item:hover {
        background: rgba(255, 255, 255, 0.05) !important;
    }

    body.theme-dark .balance-card {
        background: rgba(15, 23, 42, 0.8) !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }

    body.theme-dark .bg-white {
        background-color: #1e293b !important;
        border-color: #334155 !important;
        color: #f8fafc !important;
    }

    body.theme-dark .text-dark {
        color: #f8fafc !important;
    }

    body.theme-dark .text-muted {
        color: #94a3b8 !important;
    }

    body.theme-dark .badge.bg-light {
        background-color: #334155 !important;
        color: #e2e8f0 !important;
        border-color: #475569 !important;
    }
</style>

<div class="apple-container">
    <?php if (isset($migration_needed)): ?>
        <div class="migration-alert mb-4 d-flex align-items-center justify-content-between">
            <div>
                <h5 class="fw-bold text-warning mb-1"><i class="fas fa-exclamation-triangle me-2"></i> النظام المالي غير مكتمل</h5>
                <p class="mb-0 text-muted">يرجى تفعيل الجداول الجديدة لضمان عمل كافة المميزات.</p>
                <?php if (isset($error_message)): ?>
                    <p class="mb-0 text-danger small mt-1"><strong>خطأ:</strong> <?php echo htmlspecialchars($error_message); ?></p>
                <?php endif; ?>
            </div>
            <a href="tools/run_migration.php" class="btn btn-warning rounded-pill px-4 fw-bold shadow-sm">تفعيل الآن</a>
        </div>
    <?php endif; ?>

    <!-- Header -->
    <div class="apple-header fade-in">
        <div>
            <h1 class="apple-title display-5">المركز المالي</h1>
            <p class="apple-subtitle h5">نظرة شاملة على أداء مكتب الغزالي اليوم</p>
        </div>
        <div class="d-none d-md-flex gap-2">
            <a href="currencies.php" class="btn bg-white border rounded-pill px-3 py-2 shadow-sm text-dark text-decoration-none small fw-bold">
                <i class="fas fa-coins me-2 text-success"></i>
                إدارة العملات
            </a>
            <span class="badge bg-white text-dark border rounded-pill px-3 py-2 shadow-sm d-flex align-items-center">
                <i class="far fa-calendar-alt me-2 text-primary"></i>
                <?php echo date('d M, Y'); ?>
            </span>
        </div>
    </div>

    <!-- Quick Stats Row 1 -->
    <div class="row g-4 mb-4 fade-in delay-1">
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-blue"><i class="fas fa-file-invoice"></i></div>
                    <div class="small text-muted fw-bold"><?php echo $total_invoices; ?> مستند</div>
                </div>
                <div>
                    <div class="stat-value"><?php echo number_format($total_invoices_amount, 2); ?></div>
                    <div class="stat-label">إجمالي الفواتير</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-green"><i class="fas fa-money-bill-transfer"></i></div>
                    <div class="small text-muted fw-bold"><?php echo $total_payments; ?> مستند</div>
                </div>
                <div>
                    <div class="stat-value"><?php echo number_format($total_payments_amount, 2); ?></div>
                    <div class="stat-label">إجمالي السندات</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-red"><i class="fas fa-wallet"></i></div>
                    <div class="small text-danger fw-bold">هذا الشهر</div>
                </div>
                <div>
                    <div class="stat-value text-danger"><?php echo number_format($current_month_expenses, 2); ?></div>
                    <div class="stat-label">المصاريف التشغيلية</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-orange"><i class="fas fa-clock"></i></div>
                    <div class="small text-warning fw-bold">تحتاج ترحيل</div>
                </div>
                <div>
                    <div class="stat-value"><?php echo $pending_posting; ?></div>
                    <div class="stat-label">عمليات معلقة</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Stats Row 2 -->
    <div class="row g-4 mb-5 fade-in delay-2">
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-green"><i class="fas fa-chart-line"></i></div>
                    <div class="small text-success fw-bold">هذا الشهر</div>
                </div>
                <div>
                    <div class="stat-value text-success"><?php echo number_format($current_month_sales, 2); ?></div>
                    <div class="stat-label">مبيعات الشهر</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-orange"><i class="fas fa-shopping-cart"></i></div>
                    <div class="small text-orange fw-bold">هذا الشهر</div>
                </div>
                <div>
                    <div class="stat-value text-orange"><?php echo number_format($current_month_purchases, 2); ?></div>
                    <div class="stat-label">مشتريات الشهر</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-purple"><i class="fas fa-hand-holding-usd"></i></div>
                    <div class="small text-purple fw-bold"><?php echo $unpaid_invoices; ?> فاتورة</div>
                </div>
                <div>
                    <div class="stat-value text-purple"><?php echo number_format($unpaid_invoices_amount, 2); ?></div>
                    <div class="stat-label">المستحقات</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-teal"><i class="fas fa-users"></i></div>
                    <div class="small text-teal fw-bold"><?php echo $total_customers; ?> عميل</div>
                </div>
                <div>
                    <div class="stat-value text-teal"><?php echo $total_suppliers; ?></div>
                    <div class="stat-label">عدد الموردين</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Stats Row 3 -->
    <div class="row g-4 mb-5 fade-in delay-3">
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-green"><i class="fas fa-hand-holding-dollar"></i></div>
                    <div class="small text-success fw-bold">هذا الشهر</div>
                </div>
                <div>
                    <div class="stat-value text-success"><?php echo number_format($current_month_receipts, 2); ?></div>
                    <div class="stat-label">القبوض الشهرية</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-red"><i class="fas fa-money-bill-wave"></i></div>
                    <div class="small text-danger fw-bold">هذا الشهر</div>
                </div>
                <div>
                    <div class="stat-value text-danger"><?php echo number_format($current_month_all_payments, 2); ?></div>
                    <div class="stat-label">الصرفات الشهرية</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-blue"><i class="fas fa-user"></i></div>
                    <div class="small text-blue fw-bold"><?php echo $total_customers; ?> عميل</div>
                </div>
                <div>
                    <div class="stat-value text-blue"><?php echo $total_customers; ?></div>
                    <div class="stat-label">عدد العملاء</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="apple-card stat-pill">
                <div class="stat-header">
                    <div class="stat-icon icon-orange"><i class="fas fa-file-alt"></i></div>
                    <div class="small text-warning fw-bold"><?php echo $draft_documents; ?> مستند</div>
                </div>
                <div>
                    <div class="stat-value"><?php echo $draft_documents; ?></div>
                    <div class="stat-label">مستندات مسودة</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 fade-in delay-2">
        <!-- Sidebar Navigation -->
        <div class="col-lg-4">
            <div class="apple-card p-4 h-100">
                <div class="section-label mt-0">إجراءات سريعة</div>
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <a href="receipts.php" class="btn btn-outline-primary w-100 rounded-pill py-2 small fw-bold">
                            <i class="fas fa-plus me-1"></i> قبض
                        </a>
                    </div>
                    <div class="col-6">
                        <a href="payments.php" class="btn btn-outline-danger w-100 rounded-pill py-2 small fw-bold">
                            <i class="fas fa-minus me-1"></i> صرف
                        </a>
                    </div>
                </div>

                <div class="section-label">إدارة المستندات</div>
                <a href="invoices.php" class="nav-item-apple">
                    <i class="fas fa-file-invoice-dollar text-primary"></i>
                    <div class="flex-grow-1">الفواتير الموحدة</div>
                    <?php if ($pending_posting > 0): ?>
                        <span class="badge rounded-pill bg-warning text-dark px-2"><?php echo $pending_posting; ?></span>
                    <?php endif; ?>
                </a>
                <a href="unified_payments.php" class="nav-item-apple">
                    <i class="fas fa-receipt text-success"></i>
                    <div class="flex-grow-1">السندات الموحدة</div>
                    <?php if ($draft_documents > 0): ?>
                        <span class="badge rounded-pill bg-danger px-2"><?php echo $draft_documents; ?></span>
                    <?php endif; ?>
                </a>
                <a href="expenses.php" class="nav-item-apple">
                    <i class="fas fa-wallet text-warning"></i>
                    <span>المصاريف العامة</span>
                </a>

                <div class="section-label">إدارة الحسابات والأطراف</div>
                <a href="customers.php" class="nav-item-apple">
                    <i class="fas fa-users text-primary"></i>
                    <span>إدارة العملاء</span>
                </a>
                <a href="suppliers.php" class="nav-item-apple">
                    <i class="fas fa-truck-loading text-success"></i>
                    <span>إدارة الموردين</span>
                </a>
                <a href="branches.php" class="nav-item-apple">
                    <i class="fas fa-code-branch text-info"></i>
                    <span>إدارة الفروع</span>
                </a>
                <a href="manage_banks.php" class="nav-item-apple">
                    <i class="fas fa-university text-info"></i>
                    <span>إدارة البنوك</span>
                </a>
                <a href="manage_boxes.php" class="nav-item-apple">
                    <i class="fas fa-cash-register text-warning"></i>
                    <span>إدارة الخزائن</span>
                </a>
                <a href="agents.php" class="nav-item-apple">
                    <i class="fas fa-user-tie text-secondary"></i>
                    <span>إدارة الوكلاء</span>
                </a>
                <a href="employees.php" class="nav-item-apple">
                    <i class="fas fa-id-card text-info"></i>
                    <span>إدارة الموظفين</span>
                </a>
                <a href="manage_expenses.php" class="nav-item-apple">
                    <i class="fas fa-hand-holding-dollar text-danger"></i>
                    <span>إدارة المصروفات</span>
                </a>
                <a href="manage_cost_centers.php" class="nav-item-apple">
                    <i class="fas fa-project-diagram text-primary"></i>
                    <span>إدارة مراكز التكلفة</span>
                </a>
                <a href="manage_currency_balances.php" class="nav-item-apple">
                    <i class="fas fa-coins text-warning"></i>
                    <div class="flex-grow-1">أرصدة العملات والحدود</div>
                    <span class="badge rounded-pill bg-primary px-2">جديد</span>
                </a>

                <div class="section-label">التقارير والمراجعة</div>
                <a href="account_statement.php" class="nav-item-apple">
                    <i class="fas fa-file-contract text-info"></i>
                    <span>كشف حساب تفصيلي</span>
                </a>
                <a href="general_ledger.php" class="nav-item-apple">
                    <i class="fas fa-book text-secondary"></i>
                    <div class="flex-grow-1">دفتر اليومية / مركز التكلفة</div>
                    <?php if ($unapproved_journal > 0): ?>
                        <span class="badge rounded-pill bg-info px-2"><?php echo $unapproved_journal; ?></span>
                    <?php endif; ?>
                </a>
                <a href="trial_balance.php" class="nav-item-apple">
                    <i class="fas fa-balance-scale text-warning"></i>
                    <span>ميزان المراجعة</span>
                </a>
                <a href="report_cost_centers.php" class="nav-item-apple">
                    <i class="fas fa-chart-pie text-success"></i>
                    <span>تقرير مراكز التكلفة</span>
                </a>
                <a href="income_statement.php" class="nav-item-apple">
                    <i class="fas fa-chart-line text-success"></i>
                    <span>قائمة الأرباح والخسائر</span>
                </a>
                <a href="profit_cost_revenue.php" class="nav-item-apple">
                    <i class="fas fa-chart-bar text-primary"></i>
                    <span>ملخص الأرباح والتكاليف والإيرادات</span>
                    <span class="badge rounded-pill bg-primary px-2">جديد</span>
                </a>
                <a href="balance_sheet.php" class="nav-item-apple">
                    <i class="fas fa-balance-scale-right text-info"></i>
                    <span>الميزانيات العمومية</span>
                </a>
                <a href="exchange_reports.php" class="nav-item-apple">
                    <i class="fas fa-exchange-alt text-purple"></i>
                    <span>تقارير الصرافة</span>
                </a>
                <a href="bus_flight_bookings_reports.php" class="nav-item-apple">
                    <i class="fas fa-plane-departure text-orange"></i>
                    <span>تقارير الحجوزات</span>
                </a>

                <div class="section-label">النظام المالي</div>
                <a href="financial_accounts.php" class="nav-item-apple">
                    <i class="fas fa-sitemap text-primary"></i>
                    <span>شجرة الحسابات</span>
                </a>
                <a href="currencies.php" class="nav-item-apple">
                    <i class="fas fa-coins text-success"></i>
                    <span>إدارة العملات</span>
                </a>
                <a href="currency_exchange_new.php" class="nav-item-apple">
                    <i class="fas fa-random text-info"></i>
                    <div class="flex-grow-1">تصريف العملات</div>
                    <span class="badge rounded-pill bg-success px-2">جديد</span>
                </a>
                <a href="../currency_exchange.php" class="nav-item-apple">
                    <i class="fas fa-external-link-alt text-secondary"></i>
                    <div class="flex-grow-1">صفحة التصريف العامة</div>
                    <span class="badge rounded-pill bg-light text-dark px-2">عام</span>
                </a>
            </div>
        </div>

        <!-- Main Content Area -->
        <div class="col-lg-8">
            <!-- Balances Summary -->
            <div class="apple-card p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0"><i class="fas fa-university me-2 text-primary"></i> أرصدة الخزائن والبنوك</h5>
                    <div class="d-flex gap-2">
                        <a href="manage_banks.php" class="small text-primary text-decoration-none">إدارة البنوك</a>
                        <span class="text-muted opacity-25">|</span>
                        <a href="manage_boxes.php" class="small text-primary text-decoration-none">إدارة الخزائن</a>
                        <span class="text-muted opacity-25">|</span>
                        <a href="currencies.php" class="small text-primary text-decoration-none">إدارة العملات</a>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-3">
                    <?php foreach ($currency_balances as $balance): ?>
                        <div class="balance-card">
                            <div class="stat-label mb-1"><?php echo $balance['currency_name']; ?></div>
                            <div class="h4 fw-bold mb-0 <?php echo $balance['total_balance'] >= 0 ? 'text-dark' : 'text-danger'; ?>">
                                <?php echo number_format($balance['total_balance'], 2); ?>
                                <small class="text-muted" style="font-size: 0.6em;"><?php echo $balance['currency_symbol']; ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="apple-card p-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0"><i class="fas fa-history me-2 text-secondary"></i> النشاطات الأخيرة</h5>
                    <div class="d-flex gap-2">
                        <a href="journal.php" class="btn btn-light btn-sm rounded-pill px-3">السجل الكامل</a>
                    </div>
                </div>
                <div class="activity-list">
                    <?php foreach ($recent_activities as $act):
                        $is_income = ($act['category'] == 'receipt' || $act['category'] == 'sales');
                        $icon_color = $is_income ? 'icon-green' : 'icon-red';
                        $amount_color = $is_income ? 'text-success' : 'text-danger';

                        if ($act['category'] == 'receipt') $icon_class = 'fa-hand-holding-dollar';
                        elseif ($act['category'] == 'payment') $icon_class = 'fa-money-bill-wave';
                        elseif ($act['category'] == 'transfer') $icon_class = 'fa-exchange-alt';
                        else $icon_class = 'fa-file-invoice-dollar';
                    ?>
                        <div class="recent-activity-item d-flex align-items-center p-2 rounded-3 border-bottom border-light">
                            <div class="stat-icon <?php echo $icon_color; ?> me-3 shadow-sm" style="width: 42px; height: 42px; font-size: 1rem; border-radius: 12px;">
                                <i class="fas <?php echo $icon_class; ?>"></i>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($act['party_name'] ?? 'بدون اسم'); ?></span>
                                        <span class="badge bg-light text-muted border" style="font-size: 0.65rem;"><?php echo $act['doc_type_ar']; ?></span>
                                        <span class="text-muted small">#<?php echo $act['doc_number']; ?></span>
                                    </div>
                                    <span class="fw-bold <?php echo $amount_color; ?>">
                                        <?php echo $is_income ? '+' : '-'; ?><?php echo number_format($act['amount'], 2); ?>
                                        <small class="text-muted" style="font-size: 0.7em;"><?php echo $act['currency_symbol']; ?></small>
                                    </span>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <span class="small text-muted text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($act['description']); ?>">
                                        <?php echo $act['description'] ?: 'لا يوجد بيان'; ?>
                                    </span>
                                    <span class="small text-muted" style="font-size: 0.7rem;">
                                        <i class="far fa-clock me-1"></i><?php echo date('H:i - Y/m/d', strtotime($act['created_at'])); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($recent_activities)): ?>
                        <div class="text-center py-4 text-muted">لا توجد نشاطات مالية حديثة</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Final polish for smoothness */
    .btn-warning {
        background-color: var(--apple-orange);
        border: none;
        color: white;
    }

    .btn-warning:hover {
        background-color: #e68600;
        color: white;
    }
</style>

<?php require_once 'footer.php'; ?>
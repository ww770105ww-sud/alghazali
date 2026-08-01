<?php
require_once __DIR__ . '/../includes/session_config.php';
require_once '../includes/db.php';

$current_admin_id = $_SESSION['admin_id'] ?? null;
$user_role = $_SESSION['role'] ?? 'employee';

// جلب بيانات المستخدم الحالي مع الفرع والوكيل
$stmt_user = $pdo->prepare("SELECT u.*, r.name as role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?");
$stmt_user->execute([$current_admin_id]);
$currentUser = $stmt_user->fetch();

$is_admin_or_dev = ($currentUser && isset($currentUser['role_name']) && ($currentUser['role_name'] === 'admin' || $currentUser['role_name'] === 'developer'));

require_once 'header.php';

// 1. الإحصائيات المالية (حسب الصلاحيات - تصفية الحركات المالية الموحدة)
$doc_where = "WHERE status = 'posted'";
$doc_params = [];
if (!$is_admin_or_dev) {
    if ($currentUser['role_name'] === 'branch_manager' && !empty($currentUser['branch_id'])) {
        $doc_where .= " AND (branch_id = ? OR (entity_type = 'branch' AND entity_id = ?))";
        $doc_params[] = $currentUser['branch_id'];
        $doc_params[] = $currentUser['branch_id'];
    } elseif ($currentUser['role_name'] === 'agent' && !empty($currentUser['agent_id'])) {
        $doc_where .= " AND (entity_type = 'agent' AND entity_id = ?)";
        $doc_params[] = $currentUser['agent_id'];
    }
}

// 3. أرصدة الصناديق والبنوك (Unified System - Per Currency)
$bal_where = "WHERE coa.parent_id IN (4, 5)";
$bal_params = [];

$total_balance_stmt = $pdo->prepare("
    SELECT SUM(ab.current_balance) as total, c.currency_name, c.currency_symbol
    FROM account_balances_unified ab
    JOIN unified_accounts coa ON ab.account_id = coa.id
    JOIN currencies c ON ab.currency_id = c.id
    $bal_where
    GROUP BY ab.currency_id, c.currency_name, c.currency_symbol
");
$total_balance_stmt->execute($bal_params);
$total_balances = $total_balance_stmt->fetchAll();

// 2. إحصائيات المعاملات (حسب الصلاحيات)
$trans_where = "WHERE 1=1";
$trans_params = [];
if (!$is_admin_or_dev) {
    if ($currentUser['role_name'] === 'branch_manager' && !empty($currentUser['branch_id'])) {
        $trans_where .= " AND p.branch_id = ?";
        $trans_params[] = $currentUser['branch_id'];
    } elseif ($currentUser['role_name'] === 'agent' && !empty($currentUser['agent_id'])) {
        $trans_where .= " AND p.agent_id = ?";
        $trans_params[] = $currentUser['agent_id'];
    }
}

// دالة مساعدة لجلب إحصائيات خدمة معينة (اليوم، الشهر، الإجمالي، و مقارنة الشهر السابق)
function getServiceStats($pdo, $table, $where_clause, $params, $date_col = 'created_at')
{
    // إجمالي
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM $table p $where_clause");
    $total_stmt->execute($params);
    $total = $total_stmt->fetchColumn();

    // اليوم
    $today_stmt = $pdo->prepare("SELECT COUNT(*) FROM $table p $where_clause AND DATE(p.$date_col) = CURDATE()");
    $today_stmt->execute($params);
    $today = $today_stmt->fetchColumn();

    // الشهر الحالي
    $month_stmt = $pdo->prepare("SELECT COUNT(*) FROM $table p $where_clause AND MONTH(p.$date_col) = MONTH(CURDATE()) AND YEAR(p.$date_col) = YEAR(CURDATE())");
    $month_stmt->execute($params);
    $month = $month_stmt->fetchColumn();

    // الشهر السابق
    $prev_month_stmt = $pdo->prepare("SELECT COUNT(*) FROM $table p $where_clause AND MONTH(p.$date_col) = MONTH(CURDATE() - INTERVAL 1 MONTH) AND YEAR(p.$date_col) = YEAR(CURDATE() - INTERVAL 1 MONTH)");
    $prev_month_stmt->execute($params);
    $prev_month = $prev_month_stmt->fetchColumn();

    // حساب النسبة المئوية للتغيير
    $change_percent = 0;
    if ($prev_month > 0) {
        $change_percent = (($month - $prev_month) / $prev_month) * 100;
    } elseif ($month > 0) {
        $change_percent = 100; // زيادة 100% إذا كان الشهر السابق صفر
    }

    return ['total' => $total, 'today' => $today, 'month' => $month, 'prev_month' => $prev_month, 'change_percent' => $change_percent];
}

// دالة مساعدة لجلب إحصائيات المرحل وغير المرحل لكل خدمة
function getPostedUnpostedStats($pdo, $currentUser, $is_admin_or_dev)
{
    // تعريف الخدمات مع معلومات الجدول والشروط
    $services = [
        [
            'name' => 'معاملات الجوازات',
            'table' => 'passport_transactions',
            'extra_where' => null
        ],
        [
            'name' => 'تأشيرات العمل',
            'table' => 'passports',
            'extra_where' => "transaction_type IN ('work_visa', '6')"
        ],
        [
            'name' => 'العمرة',
            'table' => 'passports',
            'extra_where' => "transaction_type = 'umrah'"
        ],
        [
            'name' => 'الحج',
            'table' => 'passports',
            'extra_where' => "transaction_type = 'hajj'"
        ],
        [
            'name' => 'تذاكر الطيران',
            'table' => 'bus_flight_bookings',
            'extra_where' => "service_type = 'flight'"
        ],
        [
            'name' => 'حجوزات الباصات',
            'table' => 'bus_flight_bookings',
            'extra_where' => "service_type = 'bus'"
        ],
        [
            'name' => 'خدمات البريد',
            'table' => 'postal_shipments',
            'extra_where' => null
        ],
        [
            'name' => 'زيارة عائلية',
            'table' => 'family_visit_requests',
            'extra_where' => null
        ]
    ];

    $stats = [];
    foreach ($services as $service) {
        // بناء شروط الفلاتر
        $where_conds = [];
        $params = [];

        // شرط إضافي للخدمة
        if ($service['extra_where']) {
            $where_conds[] = $service['extra_where'];
        }

        // صلاحيات المستخدم
        if (!$is_admin_or_dev) {
            if ($currentUser['role_name'] === 'branch_manager' && !empty($currentUser['branch_id'])) {
                $where_conds[] = "s.branch_id = ?";
                $params[] = $currentUser['branch_id'];
            } elseif ($currentUser['role_name'] === 'agent' && !empty($currentUser['agent_id'])) {
                $where_conds[] = "s.agent_id = ?";
                $params[] = $currentUser['agent_id'];
            }
        }

        // 1. عد المرحل: المعاملات التي لها فاتورة بيع مرحلة
        $all_conds = [];
        if (!empty($where_conds)) {
            $all_conds = $where_conds;
        }
        $all_conds[] = "i.invoice_status = 'posted'";
        $posted_where_sql = implode(' AND ', $all_conds);
        if (!empty($posted_where_sql)) {
            $posted_where_sql = "WHERE " . $posted_where_sql;
        }
        $posted_sql = "
            SELECT COUNT(DISTINCT s.id)
            FROM " . $service['table'] . " s
            LEFT JOIN invoices i ON i.source_id = s.id 
                AND i.invoice_category = 'sales'
            $posted_where_sql
        ";
        $posted_stmt = $pdo->prepare($posted_sql);
        $posted_stmt->execute($params);
        $posted = $posted_stmt->fetchColumn();

        // 2. عد غير المرحل: المعاملات التي ليس لديها فاتورة بيع مرحلة (إما مسودة أو بدون فاتورة)
        $draft_all_conds = [];
        if (!empty($where_conds)) {
            $draft_all_conds = $where_conds;
        }
        $draft_all_conds[] = "(i.id IS NULL OR i.invoice_status = 'draft')";
        $draft_where_sql = implode(' AND ', $draft_all_conds);
        if (!empty($draft_where_sql)) {
            $draft_where_sql = "WHERE " . $draft_where_sql;
        }
        $draft_sql = "
            SELECT COUNT(DISTINCT s.id)
            FROM " . $service['table'] . " s
            LEFT JOIN invoices i ON i.source_id = s.id 
                AND i.invoice_category = 'sales'
            $draft_where_sql
        ";
        $draft_stmt = $pdo->prepare($draft_sql);
        $draft_stmt->execute($params);
        $draft = $draft_stmt->fetchColumn();

        $stats[] = [
            'name' => $service['name'],
            'posted' => $posted,
            'draft' => $draft
        ];
    }

    return $stats;
}

// 1. إحصائيات معاملات الجوازات (من passport_transactions)
$passport_stats = getServiceStats($pdo, 'passport_transactions', $trans_where, $trans_params, 'created_at');

// 2. إحصائيات تأشيرات العمل
$work_visa_where = $trans_where . " AND (p.transaction_type = 'work_visa' OR p.transaction_type = '6')";
$work_visa_stats = getServiceStats($pdo, 'passports', $work_visa_where, $trans_params);

// 3. إحصائيات العمرة
$umrah_where = $trans_where . " AND p.transaction_type = 'umrah'";
$umrah_stats = getServiceStats($pdo, 'passports', $umrah_where, $trans_params);

// 4. إحصائيات الزيارة العائلية
$family_visit_enabled = get_module_status($pdo, 'enable_family_visit');
$family_stats = ['total' => 0, 'today' => 0, 'month' => 0];
if ($family_visit_enabled) {
    $family_where = "WHERE 1=1";
    $family_params = [];
    if (!$is_admin_or_dev) {
        if ($currentUser['role_name'] === 'branch_manager' && !empty($currentUser['branch_id'])) {
            $family_where .= " AND branch_id = ?";
            $family_params[] = $currentUser['branch_id'];
        } elseif ($currentUser['role_name'] === 'agent' && !empty($currentUser['agent_id'])) {
            $family_where .= " AND agent_id = ?";
            $family_params[] = $currentUser['agent_id'];
        }
    }
    $family_stats = getServiceStats($pdo, 'family_visit_requests', $family_where, $family_params);
}

// 5. إحصائيات الطيران فقط
$flight_where = "WHERE 1=1 AND service_type = 'flight'";
$flight_params = [];
if (!$is_admin_or_dev) {
    if ($currentUser['role_name'] === 'branch_manager' && !empty($currentUser['branch_id'])) {
        $flight_where .= " AND branch_id = ?";
        $flight_params[] = $currentUser['branch_id'];
    } elseif ($currentUser['role_name'] === 'agent' && !empty($currentUser['agent_id'])) {
        $flight_where .= " AND agent_id = ?";
        $flight_params[] = $currentUser['agent_id'];
    }
}
$flight_stats = getServiceStats($pdo, 'bus_flight_bookings', $flight_where, $flight_params);

// 6. إحصائيات الحج
$hajj_where = $trans_where . " AND p.transaction_type = 'hajj'";
$hajj_stats = getServiceStats($pdo, 'passports', $hajj_where, $trans_params);

// 7. إحصائيات خدمات البريد
$postal_where = "WHERE deleted_at IS NULL";
$postal_params = [];
if (!$is_admin_or_dev) {
    if ($currentUser['role_name'] === 'branch_manager' && !empty($currentUser['branch_id'])) {
        $postal_where .= " AND branch_id = ?";
        $postal_params[] = $currentUser['branch_id'];
    } elseif ($currentUser['role_name'] === 'agent' && !empty($currentUser['agent_id'])) {
        $postal_where .= " AND agent_id = ?";
        $postal_params[] = $currentUser['agent_id'];
    }
}
$postal_stats = getServiceStats($pdo, 'postal_shipments', $postal_where, $postal_params);

// 8. إحصائيات حجوزات الباصات
$bus_where = "WHERE 1=1 AND service_type = 'bus'";
$bus_params = [];
if (!$is_admin_or_dev) {
    if ($currentUser['role_name'] === 'branch_manager' && !empty($currentUser['branch_id'])) {
        $bus_where .= " AND branch_id = ?";
        $bus_params[] = $currentUser['branch_id'];
    } elseif ($currentUser['role_name'] === 'agent' && !empty($currentUser['agent_id'])) {
        $bus_where .= " AND agent_id = ?";
        $bus_params[] = $currentUser['agent_id'];
    }
}
$bus_stats = getServiceStats($pdo, 'bus_flight_bookings', $bus_where, $bus_params);

// 9. إحصائيات المرحل وغير المرحل لكل خدمة
$posted_unposted_stats = getPostedUnpostedStats($pdo, $currentUser, $is_admin_or_dev);

// إحصائيات اليوم (للمقارنة السريعة في الواجهة)
$today_passports = $passport_stats['today'];
$month_passports = $passport_stats['month'];
$total_passports = $passport_stats['total'];

// Data for monthly transactions chart
$monthly_chart_data_stmt = $pdo->prepare("
    SELECT DATE(p.created_at) as date, COUNT(*) as count
    FROM passports p
    $trans_where AND p.created_at >= CURDATE() - INTERVAL 30 DAY
    GROUP BY DATE(p.created_at)
    ORDER BY DATE(p.created_at) ASC
");
$monthly_chart_data_stmt->execute($trans_params);
$monthly_chart_data = $monthly_chart_data_stmt->fetchAll(PDO::FETCH_ASSOC);

// Kanban Board Data
$kanban_statuses = $pdo->query("SELECT id, status_name, status_color FROM statuses ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
$kanban_passports_stmt = $pdo->prepare("
    SELECT p.id, p.passport_number, p.full_name, p.status_id, prof.name_ar as profession_name, ag.agent_name, p.created_at
    FROM passports p
    LEFT JOIN professions prof ON p.profession_id = prof.id
    LEFT JOIN agents ag ON p.agent_id = ag.id
    $trans_where
    ORDER BY p.created_at DESC
    LIMIT 100
");
$kanban_passports_stmt->execute($trans_params);
$kanban_passports = $kanban_passports_stmt->fetchAll(PDO::FETCH_ASSOC);

$kanban_counts = array_fill_keys(array_column($kanban_statuses, 'id'), 0);
foreach ($kanban_passports as $passport) {
    if (isset($kanban_counts[$passport['status_id']])) {
        $kanban_counts[$passport['status_id']]++;
    }
}

// Financial Dashboard Data (Unified System)
$total_receipts_stmt = $pdo->prepare("SELECT SUM(amount) as total, currency_id FROM financial_transactions $doc_where AND transaction_type = 'receipt' AND MONTH(transaction_date) = MONTH(CURDATE()) AND YEAR(transaction_date) = YEAR(CURDATE()) GROUP BY currency_id");
$total_receipts_stmt->execute($doc_params);
$total_receipts = $total_receipts_stmt->fetchAll(PDO::FETCH_ASSOC);

$total_expenses_stmt = $pdo->prepare("SELECT SUM(amount) as total, currency_id FROM financial_transactions $doc_where AND transaction_type = 'payment' AND MONTH(transaction_date) = MONTH(CURDATE()) AND YEAR(transaction_date) = YEAR(CURDATE()) GROUP BY currency_id");
$total_expenses_stmt->execute($doc_params);
$total_expenses = $total_expenses_stmt->fetchAll(PDO::FETCH_ASSOC);

$currencies_stmt = $pdo->query("SELECT id, currency_name FROM currencies");
$currencies = $currencies_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Data for monthly income/expense chart (Unified System)
$monthly_income_chart_data_stmt = $pdo->prepare("
    SELECT transaction_date as date, SUM(amount) as total
    FROM financial_transactions
    $doc_where AND transaction_type = 'receipt' AND transaction_date >= CURDATE() - INTERVAL 30 DAY
    GROUP BY transaction_date
    ORDER BY transaction_date ASC
");
$monthly_income_chart_data_stmt->execute($doc_params);
$monthly_income_chart_data = $monthly_income_chart_data_stmt->fetchAll(PDO::FETCH_ASSOC);

$monthly_expense_chart_data_stmt = $pdo->prepare("
    SELECT transaction_date as date, SUM(amount) as total
    FROM financial_transactions
    $doc_where AND transaction_type = 'payment' AND transaction_date >= CURDATE() - INTERVAL 30 DAY
    GROUP BY transaction_date
    ORDER BY transaction_date ASC
");
$monthly_expense_chart_data_stmt->execute($doc_params);
$monthly_expense_chart_data = $monthly_expense_chart_data_stmt->fetchAll(PDO::FETCH_ASSOC);

// Service Type Stats (Robust query)
$service_stats_stmt = $pdo->prepare("
    SELECT COALESCE(s.service_name, p.transaction_type) as service_name, COUNT(p.id) as count
    FROM passports p
    LEFT JOIN services s ON p.transaction_type = BINARY CAST(s.id AS CHAR)
    $trans_where
    GROUP BY COALESCE(s.service_name, p.transaction_type)
");
$service_stats_stmt->execute($trans_params);
$service_stats = $service_stats_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Agent Activity
$agent_activity_stmt = $pdo->prepare("SELECT ag.agent_name, COUNT(p.id) as count FROM passports p JOIN agents ag ON p.agent_id = ag.id $trans_where GROUP BY p.agent_id ORDER BY count DESC LIMIT 5");
$agent_activity_stmt->execute($trans_params);
$agent_activity = $agent_activity_stmt->fetchAll(PDO::FETCH_ASSOC);

// Branch & Agent Balances (Unified System - Per Currency)
$agent_balances_stmt = $pdo->query("
    SELECT c.currency_name, SUM(ab.current_balance) as total
    FROM agents a
    JOIN account_balances_unified ab ON a.account_id = ab.account_id
    JOIN currencies c ON ab.currency_id = c.id
    GROUP BY ab.currency_id
");
$agent_balances = $agent_balances_stmt->fetchAll(PDO::FETCH_ASSOC);

$branch_balances_stmt = $pdo->query("
    SELECT c.currency_name, SUM(ab.current_balance) as total
    FROM branches b
    JOIN account_balances_unified ab ON b.account_id = ab.account_id
    JOIN currencies c ON ab.currency_id = c.id
    GROUP BY ab.currency_id
");
$branch_balances = $branch_balances_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. إحصائيات النظام
$batches_count = $pdo->query("SELECT COUNT(*) FROM batches WHERE is_closed = 0")->fetchColumn();
$unread_msg_count = $pdo->query("SELECT COUNT(*) FROM contact_messages WHERE is_read = 0")->fetchColumn();
$unread_subs_count = $pdo->query("SELECT COUNT(*) FROM subscribers WHERE is_read = 0")->fetchColumn();
$branches_count = $pdo->query("SELECT COUNT(*) FROM branches WHERE deleted_at IS NULL")->fetchColumn();
$agents_count = $pdo->query("SELECT COUNT(*) FROM agents WHERE deleted_at IS NULL")->fetchColumn();
$customers_count = $pdo->query("SELECT COUNT(*) FROM customers WHERE deleted_at IS NULL")->fetchColumn();
$workflows_count = $pdo->query("SELECT COUNT(*) FROM workflows WHERE is_active = 1")->fetchColumn();

// إحصائيات الرسائل الداخلية
$new_internal_count = $pdo->prepare("
    SELECT COUNT(*) FROM internal_messages
    WHERE receiver_id = ? AND is_read = 0
    AND (is_deleted_for_all = 0 OR is_deleted_for_all IS NULL)
    AND (is_deleted_by_receiver = 0 OR is_deleted_by_receiver IS NULL)
");
$new_internal_count->execute([$current_admin_id]);
$new_internal_count = $new_internal_count->fetchColumn();

$edited_internal_count = $pdo->prepare("
    SELECT COUNT(*) FROM internal_messages
    WHERE (receiver_id = ? OR sender_id = ?) AND is_edited = 1
    AND (is_deleted_for_all = 0 OR is_deleted_for_all IS NULL)
    AND ((sender_id = ? AND (is_deleted_by_sender = 0 OR is_deleted_by_sender IS NULL)) OR (receiver_id = ? AND (is_deleted_by_receiver = 0 OR is_deleted_by_receiver IS NULL)))
");
$edited_internal_count->execute([$current_admin_id, $current_admin_id, $current_admin_id, $current_admin_id]);
$edited_internal_count = $edited_internal_count->fetchColumn();

$deleted_internal_count = $pdo->prepare("
    SELECT COUNT(*) FROM internal_messages
    WHERE (receiver_id = ? OR sender_id = ?)
    AND (is_deleted_for_all = 1 OR (sender_id = ? AND is_deleted_by_sender = 1) OR (receiver_id = ? AND is_deleted_by_receiver = 1))
");
$deleted_internal_count->execute([$current_admin_id, $current_admin_id, $current_admin_id, $current_admin_id]);
$deleted_internal_count = $deleted_internal_count->fetchColumn();

// جلب تفاصيل الرسائل للـ Modals
$new_messages_list = $pdo->prepare("
    SELECT im.*, u_s.full_name as sender_name, u_r.full_name as receiver_name
    FROM internal_messages im
    JOIN users u_s ON im.sender_id = u_s.id
    JOIN users u_r ON im.receiver_id = u_r.id
    WHERE im.receiver_id = ? AND im.is_read = 0
    AND (im.is_deleted_for_all = 0 OR im.is_deleted_for_all IS NULL)
    AND (im.is_deleted_by_receiver = 0 OR im.is_deleted_by_receiver IS NULL)
    ORDER BY im.created_at DESC LIMIT 10
");
$new_messages_list->execute([$current_admin_id]);
$new_messages_list = $new_messages_list->fetchAll();

$edited_messages_list = $pdo->prepare("
    SELECT im.*, u_s.full_name as sender_name, u_r.full_name as receiver_name
    FROM internal_messages im
    JOIN users u_s ON im.sender_id = u_s.id
    JOIN users u_r ON im.receiver_id = u_r.id
    WHERE (im.receiver_id = ? OR im.sender_id = ?) AND im.is_edited = 1
    AND (im.is_deleted_for_all = 0 OR im.is_deleted_for_all IS NULL)
    AND ((im.sender_id = ? AND (im.is_deleted_by_sender = 0 OR im.is_deleted_by_sender IS NULL)) OR (im.receiver_id = ? AND (im.is_deleted_by_receiver = 0 OR im.is_deleted_by_receiver IS NULL)))
    ORDER BY im.updated_at DESC LIMIT 10
");
$edited_messages_list->execute([$current_admin_id, $current_admin_id, $current_admin_id, $current_admin_id]);
$edited_messages_list = $edited_messages_list->fetchAll();

$deleted_messages_list = $pdo->prepare("
    SELECT im.*, u_s.full_name as sender_name, u_r.full_name as receiver_name
    FROM internal_messages im
    JOIN users u_s ON im.sender_id = u_s.id
    JOIN users u_r ON im.receiver_id = u_r.id
    WHERE (im.receiver_id = ? OR im.sender_id = ?)
    AND (im.is_deleted_for_all = 1 OR (im.sender_id = ? AND im.is_deleted_by_sender = 1) OR (im.receiver_id = ? AND im.is_deleted_by_receiver = 1))
    ORDER BY im.updated_at DESC LIMIT 10
");
$deleted_messages_list->execute([$current_admin_id, $current_admin_id, $current_admin_id, $current_admin_id]);
$deleted_messages_list = $deleted_messages_list->fetchAll();

// جلب تفاصيل إضافية للمحتوى (Modals)
$recent_branches = $pdo->query("SELECT * FROM branches WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5")->fetchAll();
$recent_contacts = $pdo->query("SELECT * FROM contact_messages ORDER BY created_at DESC LIMIT 5")->fetchAll();
$recent_subscribers = $pdo->query("SELECT * FROM subscribers ORDER BY created_at DESC LIMIT 5")->fetchAll();
$recent_workflows = $pdo->query("SELECT * FROM workflows WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5")->fetchAll();

// 4. المقبوضات والمصروفات اليوم (للمدير والمحاسب) (Unified System)
$today_receipts_stmt = $pdo->prepare("SELECT SUM(amount) as total, c.currency_name FROM financial_transactions d JOIN currencies c ON d.currency_id = c.id WHERE d.transaction_type = 'receipt' AND d.status = 'posted' AND d.transaction_date = CURDATE() GROUP BY d.currency_id");
$today_receipts_stmt->execute();
$today_receipts_data = $today_receipts_stmt->fetchAll();

$today_expenses_stmt = $pdo->prepare("SELECT SUM(amount) as total, c.currency_name FROM financial_transactions d JOIN currencies c ON d.currency_id = c.id WHERE d.transaction_type = 'payment' AND d.status = 'posted' AND d.transaction_date = CURDATE() GROUP BY d.currency_id");
$today_expenses_stmt->execute();
$today_expenses_data = $today_expenses_stmt->fetchAll();

// 5. الحالات النشطة
$status_stats = $pdo->prepare("
    SELECT s.status_name, s.status_color as color, COUNT(p.id) as count
    FROM statuses s
    LEFT JOIN passports p ON s.id = p.status_id
    $trans_where
    GROUP BY s.id
");
$status_stats->execute($trans_params);
$status_stats = $status_stats->fetchAll();

// 7. إحصائيات تأشيرات العمل (Work Visa)
$work_visa_where = $trans_where . " AND (p.transaction_type = 'work_visa' OR p.transaction_type = '6')";
$work_visa_params = $trans_params;

$total_work_visas = $pdo->prepare("SELECT COUNT(*) FROM passports p $work_visa_where");
$total_work_visas->execute($work_visa_params);
$total_work_visas = $total_work_visas->fetchColumn();

$pending_relayer_work_visas = $pdo->prepare("SELECT COUNT(*) FROM passports p $work_visa_where AND p.status_id = 1");
$pending_relayer_work_visas->execute($work_visa_params);
$pending_relayer_work_visas = $pending_relayer_work_visas->fetchColumn();

$unresolved_work_visas = $pdo->prepare("SELECT COUNT(*) FROM passports p $work_visa_where AND p.is_resolved = 0");
$unresolved_work_visas->execute($work_visa_params);
$unresolved_work_visas = $unresolved_work_visas->fetchColumn();

// 8. إحصائيات الزيارة العائلية (Family Visit)
$family_visit_enabled = get_module_status($pdo, 'enable_family_visit');
$total_family_requests = 0;
$total_family_individuals = 0;

if ($family_visit_enabled) {
    $family_where = "WHERE 1=1";
    $family_params = [];
    if (!$is_admin_or_dev) {
        if ($currentUser['role_name'] === 'branch_manager' && !empty($currentUser['branch_id'])) {
            $family_where .= " AND branch_id = ?";
            $family_params[] = $currentUser['branch_id'];
        } elseif ($currentUser['role_name'] === 'agent' && !empty($currentUser['agent_id'])) {
            $family_where .= " AND agent_id = ?";
            $family_params[] = $currentUser['agent_id'];
        }
    }

    $total_family_requests = $pdo->prepare("SELECT COUNT(*) FROM family_visit_requests $family_where");
    $total_family_requests->execute($family_params);
    $total_family_requests = $total_family_requests->fetchColumn();

    // Check if family_visit_individuals table exists
    $table_check = $pdo->query("SHOW TABLES LIKE 'family_visit_individuals'")->fetch();
    if ($table_check) {
        $total_family_individuals_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM family_visit_individuals i
            JOIN family_visit_requests r ON i.request_id = r.id
            $family_where
        ");
        $total_family_individuals_stmt->execute($family_params);
        $total_family_individuals = $total_family_individuals_stmt->fetchColumn();
    } else {
        $total_family_individuals = 0;
    }
}

// 6. آخر المعاملات (دمج تأشيرات العمل والزيارات العائلية في قائمة واحدة إذا لزم الأمر، لكن حالياً نبقيها للجوازات)
$recent_transactions = $pdo->prepare("
    SELECT p.*, s.status_name, s.status_color
    FROM passports p
    LEFT JOIN statuses s ON p.status_id = s.id
    $trans_where
    ORDER BY p.created_at DESC LIMIT 5
");
$recent_transactions->execute($trans_params);
$recent_transactions = $recent_transactions->fetchAll();
?>

<div class="container-fluid px-4 py-4">
    <style>
        @media (max-width: 768px) {
            .dashboard-card { margin-bottom: 15px !important; }
            .dashboard-card .display-5 { font-size: 1.5rem !important; }
            .dashboard-card .card-title { font-size: 0.85rem !important; }
        }

        /* الزجاجية (Glassmorphism) مع لمسة 3D */
        .dashboard-card {
            background: rgba(255, 255, 255, 0.65);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 18px;
            box-shadow: 0 8px 24px rgba(149, 157, 165, 0.15), 
                        inset 0 1px 2px rgba(255, 255, 255, 0.9),
                        inset 0 -1px 2px rgba(0, 0, 0, 0.02);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            position: relative;
            overflow: hidden;
        }

        .dashboard-card:hover {
            transform: translateY(-5px) scale(1.01);
            box-shadow: 0 15px 35px rgba(149, 157, 165, 0.2), 
                        inset 0 1px 2px rgba(255, 255, 255, 1);
            background: rgba(255, 255, 255, 0.85);
            border-color: rgba(255, 255, 255, 1);
        }

        /* Dark mode */
        body.theme-dark .dashboard-card,
        body.theme-dark .dashboard-card.bg-light,
        .dashboard-card.bg-dark {
            background: rgba(15, 23, 42, 0.75) !important;
            border-color: rgba(255, 255, 255, 0.1) !important;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.6), 
                        inset 0 1px 1px rgba(255, 255, 255, 0.08);
            color: #f8fafc !important; /* لون نص فاتح أساسي */
        }
        
        /* إزالة الخلفيات البيضاء تماماً داخل البطاقات في الوضع الليلي (لتكون شفافة) */
        body.theme-dark .dashboard-card .bg-white,
        body.theme-dark .dashboard-card .bg-light {
            background: transparent !important;
            border-color: rgba(255, 255, 255, 0.05) !important;
        }

        body.theme-dark .dashboard-card:hover {
            background: rgba(30, 41, 59, 0.85) !important;
            border-color: rgba(255, 255, 255, 0.2) !important;
        }

        /* جعل لون النصوص نفسه مضيئاً (Neon Colors) */
        body.theme-dark .dashboard-card .text-muted {
            color: #94a3b8 !important;
        }
        body.theme-dark .dashboard-card h1,
        body.theme-dark .dashboard-card h2,
        body.theme-dark .dashboard-card h3,
        body.theme-dark .dashboard-card h4,
        body.theme-dark .dashboard-card h5,
        body.theme-dark .dashboard-card h6,
        body.theme-dark .dashboard-card .fw-bold {
            color: #38bdf8 !important; /* لون سماوي مضيء كالنص نفسه */
            text-shadow: 0 0 5px rgba(56, 189, 248, 0.5) !important; /* توهج خفيف لتعزيز الإضاءة */
        }
        
        /* ألوان مضيئة للنصوص الملونة (أخضر/أحمر) */
        body.theme-dark .dashboard-card .text-success {
            color: #4ade80 !important; /* أخضر نيون */
            text-shadow: 0 0 5px rgba(74, 222, 128, 0.5) !important;
        }
        body.theme-dark .dashboard-card .text-danger {
            color: #f87171 !important; /* أحمر نيون */
            text-shadow: 0 0 5px rgba(248, 113, 113, 0.5) !important;
        }

        /* تأثير إضاءة علوي للبطاقة لتعزيز البعد الثالث */
        .dashboard-card::before {
            content: '';
            position: absolute;
            top: -20px;
            right: -20px;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(255,255,255,0.7) 0%, rgba(255,255,255,0) 70%);
            border-radius: 50%;
            z-index: 1;
            pointer-events: none;
        }
        body.theme-dark .dashboard-card::before {
            background: radial-gradient(circle, rgba(255,255,255,0.05) 0%, rgba(255,255,255,0) 70%);
        }
        
        .dashboard-card > div {
            position: relative;
            z-index: 2;
        }

        /* تصغير الأيقونات وجعلها بارزة (3D) */
        .icon-box {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-size: 1rem;
            margin-bottom: 0.25rem;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08), inset 0 2px 4px rgba(255,255,255,0.6);
            border: 1px solid rgba(255,255,255,0.8);
        }

        .icon-box-primary { background: linear-gradient(135deg, #e0f2fe, #bae6fd); color: #0284c7; }
        .icon-box-success { background: linear-gradient(135deg, #dcfce7, #bbf7d0); color: #16a34a; }
        .icon-box-info { background: linear-gradient(135deg, #ccfbf1, #99f6e4); color: #0d9488; }
        .icon-box-warning { background: linear-gradient(135deg, #fef3c7, #fde68a); color: #d97706; }
        .icon-box-danger { background: linear-gradient(135deg, #fee2e2, #fecaca); color: #dc2626; }
        .icon-box-dark { background: linear-gradient(135deg, #f1f5f9, #e2e8f0); color: #475569; }

        body.theme-dark .icon-box { border-color: rgba(255,255,255,0.1); box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        body.theme-dark .icon-box-primary { background: linear-gradient(135deg, #0284c7, #0369a1); color: #e0f2fe; }
        body.theme-dark .icon-box-success { background: linear-gradient(135deg, #16a34a, #15803d); color: #dcfce7; }
        body.theme-dark .icon-box-info { background: linear-gradient(135deg, #0d9488, #0f766e); color: #ccfbf1; }
        body.theme-dark .icon-box-warning { background: linear-gradient(135deg, #d97706, #b45309); color: #fef3c7; }
        body.theme-dark .icon-box-danger { background: linear-gradient(135deg, #dc2626, #b91c1c); color: #fee2e2; }

        /* تصغير الشارات (Badges) مع تأثير زجاجي */
        .stat-badge {
            font-size: 0.7rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.6);
            color: #334155;
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            backdrop-filter: blur(4px);
        }
        body.theme-dark .stat-badge {
            background: rgba(15, 23, 42, 0.5);
            color: #bae6fd;
            border-color: rgba(56, 189, 248, 0.4);
            text-shadow: 0 0 6px rgba(56, 189, 248, 0.6); /* نص مضيء في الشارة */
            box-shadow: 0 0 10px rgba(56, 189, 248, 0.15); /* إطار الشارة مضيء قليلاً */
        }

        /* تصغير الـ padding وتنسيق الأرقام */
        .card-body {
            padding: 1rem;
        }

        .card-body h2 {
            font-size: 1.35rem !important;
            font-weight: 800 !important;
            letter-spacing: -0.5px;
            margin-bottom: 0 !important;
            text-shadow: 1px 1px 1px rgba(255,255,255,0.8);
        }
        /* إزالة نص الظل الافتراضي هنا لأن التوهج تمت إضافته في القاعدة العامة فوق */
        body.theme-dark .card-body h2 {
            /* يتم التحكم بالتوهج من القاعدة السابقة */
        }

        .chart-container {
            background: rgba(255, 255, 255, 0.65);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.8);
            border-radius: 18px;
            padding: 1.25rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 24px rgba(149, 157, 165, 0.15), inset 0 1px 2px rgba(255, 255, 255, 0.9);
        }
        body.theme-dark .chart-container {
            background: rgba(30, 41, 59, 0.65);
            border-color: rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
        }
        
        /* تحسين المسافات أسفل البطاقة (اليوم/الشهر) */
        .card-body .border-top {
            border-top-color: rgba(0,0,0,0.05) !important;
            margin-top: 0.5rem !important;
            padding-top: 0.5rem !important;
        }
        body.theme-dark .card-body .border-top {
            border-top-color: rgba(255,255,255,0.05) !important;
        }
        .card-body .border-start {
            border-left-color: rgba(0,0,0,0.05) !important;
        }
        body.theme-dark .card-body .border-start {
            border-left-color: rgba(255,255,255,0.05) !important;
        }

        .container-fluid.px-4.py-4 {
            background: #f5f7fb;
            color: #172033;
        }

        .container-fluid.px-4.py-4 > .d-flex:first-of-type {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 1rem 1.25rem;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        #stats-dashboard > .d-flex,
        #financial-dashboard > .d-flex,
        #messages-dashboard > h4,
        #kanban-dashboard > h4,
        #recent-transactions-dashboard > .d-flex {
            padding: 0 0.15rem;
        }

        .dashboard-card,
        .chart-container {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
            backdrop-filter: none;
            -webkit-backdrop-filter: none;
        }

        .dashboard-card:hover {
            transform: translateY(-2px);
            background: #ffffff;
            border-color: #cbd5e1;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
        }

        .dashboard-card::before {
            display: none;
        }

        .card-body {
            padding: 1rem;
        }

        .dashboard-card .card-title,
        .chart-container h5,
        #stats-dashboard h4,
        #financial-dashboard h4,
        #messages-dashboard h4,
        #kanban-dashboard h4,
        #recent-transactions-dashboard h4 {
            color: #172033;
            line-height: 1.5;
        }

        .card-body h2,
        .card-body h3 {
            color: #0f172a;
            letter-spacing: 0;
            text-shadow: none;
            line-height: 1.25;
        }

        .stat-badge {
            background: #f8fafc;
            color: #334155;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            box-shadow: none;
            max-width: 100%;
            white-space: normal;
            text-align: start;
        }

        .icon-box {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: 0;
            box-shadow: none;
            flex: 0 0 36px;
        }

        .transaction-posting-card .card-body {
            padding: 0;
        }

        .transaction-posting-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .transaction-posting-legend {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            color: #64748b;
            font-size: 0.85rem;
        }

        .transaction-posting-legend span {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .transaction-posting-legend .posted i,
        .posting-count.posted {
            color: #15803d;
        }

        .transaction-posting-legend .pending i,
        .posting-count.pending {
            color: #b45309;
        }

        .transaction-posting-table {
            margin-bottom: 0;
        }

        .transaction-posting-table thead th {
            background: #f8fafc;
            color: #475569;
            font-size: 0.82rem;
            font-weight: 800;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.8rem 1rem;
        }

        .transaction-posting-table tbody td {
            padding: 0.85rem 1rem;
            border-bottom-color: #edf2f7;
        }

        .posting-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            height: 32px;
            border-radius: 6px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            font-weight: 800;
        }

        .posting-count.total {
            color: #1d4ed8;
            background: #eff6ff;
            border-color: #bfdbfe;
        }

        body.theme-dark .container-fluid.px-4.py-4 {
            background: #0f172a;
            color: #e5edf7;
        }

        body.theme-dark .container-fluid.px-4.py-4 > .d-flex:first-of-type,
        body.theme-dark .dashboard-card,
        body.theme-dark .dashboard-card.bg-light,
        body.theme-dark .chart-container {
            background: #111c2f !important;
            border-color: #26364f !important;
            box-shadow: none !important;
            color: #e5edf7 !important;
        }

        body.theme-dark .dashboard-card h1,
        body.theme-dark .dashboard-card h2,
        body.theme-dark .dashboard-card h3,
        body.theme-dark .dashboard-card h4,
        body.theme-dark .dashboard-card h5,
        body.theme-dark .dashboard-card h6,
        body.theme-dark .dashboard-card .fw-bold,
        body.theme-dark #stats-dashboard h4,
        body.theme-dark #financial-dashboard h4,
        body.theme-dark #messages-dashboard h4,
        body.theme-dark #kanban-dashboard h4,
        body.theme-dark #recent-transactions-dashboard h4 {
            color: #f8fafc !important;
            text-shadow: none !important;
        }

        body.theme-dark .stat-badge,
        body.theme-dark .posting-count {
            background: #16233a;
            border-color: #26364f;
            color: #dbeafe;
            text-shadow: none;
            box-shadow: none;
        }

        body.theme-dark .transaction-posting-header,
        body.theme-dark .transaction-posting-table thead th,
        body.theme-dark .transaction-posting-table tbody td {
            border-color: #26364f;
        }

        body.theme-dark .transaction-posting-table thead th {
            background: #16233a;
            color: #cbd5e1;
        }

        @media (max-width: 768px) {
            .container-fluid.px-4.py-4 {
                padding-inline: 0.85rem !important;
            }

            .container-fluid.px-4.py-4 > .d-flex:first-of-type,
            #stats-dashboard > .d-flex,
            #financial-dashboard > .d-flex,
            #recent-transactions-dashboard > .d-flex,
            .transaction-posting-header {
                align-items: flex-start !important;
                flex-direction: column;
            }

            .transaction-posting-table thead {
                display: none;
            }

            .transaction-posting-table tbody tr {
                display: grid;
                gap: 0.75rem;
                padding: 1rem;
                border-bottom: 1px solid #e2e8f0;
                background: #fff;
                border-radius: 0.5rem;
                margin-bottom: 0.5rem;
                box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            }

            .transaction-posting-table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 0.25rem 0;
                border: 0;
                text-align: start !important;
            }

            .transaction-posting-table tbody td.service-cell {
                font-size: 1.05rem;
                padding-bottom: 0.5rem;
                border-bottom: 1px dashed #e2e8f0;
                margin-bottom: 0.5rem;
            }

            .transaction-posting-table tbody td::before {
                content: attr(data-label);
                color: #64748b;
                font-size: 0.82rem;
                font-weight: 700;
            }

            .transaction-posting-table .posting-count {
                font-weight: 700;
                font-size: 1.1rem;
            }
        }
    </style>
    <!-- Welcome Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold mb-0">لوحة التحكم</h3>
            <p class="text-muted small">مرحباً بك، <?php echo htmlspecialchars($currentUser['full_name'] ?: $currentUser['username']); ?></p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge bg-dark text-white shadow-sm p-2 px-3 border rounded-pill small d-flex align-items-center"><i class="fas fa-calendar-alt me-2 text-primary"></i> <?php echo date('Y/m/d'); ?></span>
        </div>
    </div>

    <!-- 1. لوحة الإحصائيات -->
    <div id="stats-dashboard" class="mb-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="fw-bold mb-0">لوحة الإحصائيات الشاملة</h4>
            <div class="text-muted small">إحصائيات اليوم، الشهر، والإجمالي لكل خدمة</div>
        </div>

        <div class="row g-3 mb-4">
            <!-- البطاقة الشاملة للجوازات -->
            <div class="col-xl-2 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="icon-box icon-box-primary">
                                <i class="fas fa-passport"></i>
                            </div>
                            <span class="stat-badge">معاملة الجوازات</span>
                        </div>
                        <h2 class="fw-bold mb-1 mt-2"><?php echo number_format($passport_stats['total']); ?></h2>
                        <div class="d-flex gap-3 mt-3 pt-3 border-top">
                            <div>
                                <div class="text-muted extra-small">اليوم</div>
                                <div class="fw-bold small"><?php echo number_format($passport_stats['today']); ?></div>
                            </div>
                            <div class="border-start ps-3">
                                <div class="text-muted extra-small">هذا الشهر</div>
                                <div class="fw-bold small"><?php echo number_format($passport_stats['month']); ?></div>
                            </div>
                        </div>
                        <!-- مقارنة الشهر السابق -->
                        <div class="mt-2 pt-2 border-top">
                            <div class="text-muted extra-small">مقارنة الشهر السابق</div>
                            <div class="d-flex align-items-center gap-1">
                                <span class="fw-bold <?php echo $passport_stats['change_percent'] > 0 ? 'text-success' : ($passport_stats['change_percent'] < 0 ? 'text-danger' : 'text-muted'); ?>">
                                    <?php echo $passport_stats['change_percent'] > 0 ? '+' : ''; ?><?php echo number_format($passport_stats['change_percent'], 1); ?>%
                                </span>
                                <i class="fas <?php echo $passport_stats['change_percent'] > 0 ? 'fa-arrow-up text-success' : ($passport_stats['change_percent'] < 0 ? 'fa-arrow-down text-danger' : 'fa-minus text-muted'); ?> extra-small"></i>
                                <span class="text-muted extra-small">(<?php echo number_format($passport_stats['prev_month']); ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- بطاقة تأشيرات العمل -->
            <div class="col-xl-2 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="icon-box icon-box-success">
                                <i class="fas fa-id-card"></i>
                            </div>
                            <span class="stat-badge">تأشيرات العمل</span>
                        </div>
                        <h2 class="fw-bold mb-1 mt-2"><?php echo number_format($work_visa_stats['total']); ?></h2>
                        <div class="d-flex gap-3 mt-3 pt-3 border-top">
                            <div>
                                <div class="text-muted extra-small">اليوم</div>
                                <div class="fw-bold small"><?php echo number_format($work_visa_stats['today']); ?></div>
                            </div>
                            <div class="border-start ps-3">
                                <div class="text-muted extra-small">هذا الشهر</div>
                                <div class="fw-bold small"><?php echo number_format($work_visa_stats['month']); ?></div>
                            </div>
                        </div>
                        <!-- مقارنة الشهر السابق -->
                        <div class="mt-2 pt-2 border-top">
                            <div class="text-muted extra-small">مقارنة الشهر السابق</div>
                            <div class="d-flex align-items-center gap-1">
                                <span class="fw-bold <?php echo $work_visa_stats['change_percent'] > 0 ? 'text-success' : ($work_visa_stats['change_percent'] < 0 ? 'text-danger' : 'text-muted'); ?>">
                                    <?php echo $work_visa_stats['change_percent'] > 0 ? '+' : ''; ?><?php echo number_format($work_visa_stats['change_percent'], 1); ?>%
                                </span>
                                <i class="fas <?php echo $work_visa_stats['change_percent'] > 0 ? 'fa-arrow-up text-success' : ($work_visa_stats['change_percent'] < 0 ? 'fa-arrow-down text-danger' : 'fa-minus text-muted'); ?> extra-small"></i>
                                <span class="text-muted extra-small">(<?php echo number_format($work_visa_stats['prev_month']); ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- بطاقة العمرة -->
            <div class="col-xl-2 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="icon-box icon-box-info">
                                <i class="fas fa-kaaba"></i>
                            </div>
                            <span class="stat-badge">قسم العمرة</span>
                        </div>
                        <h2 class="fw-bold mb-1 mt-2"><?php echo number_format($umrah_stats['total']); ?></h2>
                        <div class="d-flex gap-3 mt-3 pt-3 border-top">
                            <div>
                                <div class="text-muted extra-small">اليوم</div>
                                <div class="fw-bold small"><?php echo number_format($umrah_stats['today']); ?></div>
                            </div>
                            <div class="border-start ps-3">
                                <div class="text-muted extra-small">هذا الشهر</div>
                                <div class="fw-bold small"><?php echo number_format($umrah_stats['month']); ?></div>
                            </div>
                        </div>
                        <!-- مقارنة الشهر السابق -->
                        <div class="mt-2 pt-2 border-top">
                            <div class="text-muted extra-small">مقارنة الشهر السابق</div>
                            <div class="d-flex align-items-center gap-1">
                                <span class="fw-bold <?php echo $umrah_stats['change_percent'] > 0 ? 'text-success' : ($umrah_stats['change_percent'] < 0 ? 'text-danger' : 'text-muted'); ?>">
                                    <?php echo $umrah_stats['change_percent'] > 0 ? '+' : ''; ?><?php echo number_format($umrah_stats['change_percent'], 1); ?>%
                                </span>
                                <i class="fas <?php echo $umrah_stats['change_percent'] > 0 ? 'fa-arrow-up text-success' : ($umrah_stats['change_percent'] < 0 ? 'fa-arrow-down text-danger' : 'fa-minus text-muted'); ?> extra-small"></i>
                                <span class="text-muted extra-small">(<?php echo number_format($umrah_stats['prev_month']); ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- بطاقة الحج -->
            <div class="col-xl-2 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="icon-box icon-box-warning">
                                <i class="fas fa-hotel"></i>
                            </div>
                            <span class="stat-badge">قسم الحج</span>
                        </div>
                        <h2 class="fw-bold mb-1 mt-2"><?php echo number_format($hajj_stats['total']); ?></h2>
                        <div class="d-flex gap-3 mt-3 pt-3 border-top">
                            <div>
                                <div class="text-muted extra-small">اليوم</div>
                                <div class="fw-bold small"><?php echo number_format($hajj_stats['today']); ?></div>
                            </div>
                            <div class="border-start ps-3">
                                <div class="text-muted extra-small">هذا الشهر</div>
                                <div class="fw-bold small"><?php echo number_format($hajj_stats['month']); ?></div>
                            </div>
                        </div>
                        <!-- مقارنة الشهر السابق -->
                        <div class="mt-2 pt-2 border-top">
                            <div class="text-muted extra-small">مقارنة الشهر السابق</div>
                            <div class="d-flex align-items-center gap-1">
                                <span class="fw-bold <?php echo $hajj_stats['change_percent'] > 0 ? 'text-success' : ($hajj_stats['change_percent'] < 0 ? 'text-danger' : 'text-muted'); ?>">
                                    <?php echo $hajj_stats['change_percent'] > 0 ? '+' : ''; ?><?php echo number_format($hajj_stats['change_percent'], 1); ?>%
                                </span>
                                <i class="fas <?php echo $hajj_stats['change_percent'] > 0 ? 'fa-arrow-up text-success' : ($hajj_stats['change_percent'] < 0 ? 'fa-arrow-down text-danger' : 'fa-minus text-muted'); ?> extra-small"></i>
                                <span class="text-muted extra-small">(<?php echo number_format($hajj_stats['prev_month']); ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- بطاقة الزيارة العائلية -->
            <?php if ($family_visit_enabled): ?>
                <div class="col-xl-2 col-md-6">
                    <div class="card dashboard-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="icon-box icon-box-danger">
                                    <i class="fas fa-users"></i>
                                </div>
                                <span class="stat-badge">الزيارة العائلية</span>
                            </div>
                            <h2 class="fw-bold mb-1 mt-2"><?php echo number_format($family_stats['total']); ?></h2>
                            <div class="d-flex gap-3 mt-3 pt-3 border-top">
                                <div>
                                    <div class="text-muted extra-small">اليوم</div>
                                    <div class="fw-bold small"><?php echo number_format($family_stats['today']); ?></div>
                                </div>
                                <div class="border-start ps-3">
                                    <div class="text-muted extra-small">هذا الشهر</div>
                                    <div class="fw-bold small"><?php echo number_format($family_stats['month']); ?></div>
                                </div>
                            </div>
                            <!-- مقارنة الشهر السابق -->
                            <div class="mt-2 pt-2 border-top">
                                <div class="text-muted extra-small">مقارنة الشهر السابق</div>
                                <div class="d-flex align-items-center gap-1">
                                    <span class="fw-bold <?php echo $family_stats['change_percent'] > 0 ? 'text-success' : ($family_stats['change_percent'] < 0 ? 'text-danger' : 'text-muted'); ?>">
                                        <?php echo $family_stats['change_percent'] > 0 ? '+' : ''; ?><?php echo number_format($family_stats['change_percent'], 1); ?>%
                                    </span>
                                    <i class="fas <?php echo $family_stats['change_percent'] > 0 ? 'fa-arrow-up text-success' : ($family_stats['change_percent'] < 0 ? 'fa-arrow-down text-danger' : 'fa-minus text-muted'); ?> extra-small"></i>
                                    <span class="text-muted extra-small">(<?php echo number_format($family_stats['prev_month']); ?>)</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- بطاقة تذاكر طيران فقط -->
            <div class="col-xl-2 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="icon-box icon-box-dark">
                                <i class="fas fa-plane"></i>
                            </div>
                            <span class="stat-badge">تذاكر طيران</span>
                        </div>
                        <h2 class="fw-bold mb-1 mt-2"><?php echo number_format($flight_stats['total']); ?></h2>
                        <div class="d-flex gap-3 mt-3 pt-3 border-top">
                            <div>
                                <div class="text-muted extra-small">اليوم</div>
                                <div class="fw-bold small"><?php echo number_format($flight_stats['today']); ?></div>
                            </div>
                            <div class="border-start ps-3">
                                <div class="text-muted extra-small">هذا الشهر</div>
                                <div class="fw-bold small"><?php echo number_format($flight_stats['month']); ?></div>
                            </div>
                        </div>
                        <!-- مقارنة الشهر السابق -->
                        <div class="mt-2 pt-2 border-top">
                            <div class="text-muted extra-small">مقارنة الشهر السابق</div>
                            <div class="d-flex align-items-center gap-1">
                                <span class="fw-bold <?php echo $flight_stats['change_percent'] > 0 ? 'text-success' : ($flight_stats['change_percent'] < 0 ? 'text-danger' : 'text-muted'); ?>">
                                    <?php echo $flight_stats['change_percent'] > 0 ? '+' : ''; ?><?php echo number_format($flight_stats['change_percent'], 1); ?>%
                                </span>
                                <i class="fas <?php echo $flight_stats['change_percent'] > 0 ? 'fa-arrow-up text-success' : ($flight_stats['change_percent'] < 0 ? 'fa-arrow-down text-danger' : 'fa-minus text-muted'); ?> extra-small"></i>
                                <span class="text-muted extra-small">(<?php echo number_format($flight_stats['prev_month']); ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- بطاقة خدمات البريد -->
            <div class="col-xl-2 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="icon-box icon-box-info">
                                <i class="fas fa-envelope"></i>
                            </div>
                            <span class="stat-badge">خدمات البريد</span>
                        </div>
                        <h2 class="fw-bold mb-1 mt-2"><?php echo number_format($postal_stats['total']); ?></h2>
                        <div class="d-flex gap-3 mt-3 pt-3 border-top">
                            <div>
                                <div class="text-muted extra-small">اليوم</div>
                                <div class="fw-bold small"><?php echo number_format($postal_stats['today']); ?></div>
                            </div>
                            <div class="border-start ps-3">
                                <div class="text-muted extra-small">هذا الشهر</div>
                                <div class="fw-bold small"><?php echo number_format($postal_stats['month']); ?></div>
                            </div>
                        </div>
                        <!-- مقارنة الشهر السابق -->
                        <div class="mt-2 pt-2 border-top">
                            <div class="text-muted extra-small">مقارنة الشهر السابق</div>
                            <div class="d-flex align-items-center gap-1">
                                <span class="fw-bold <?php echo $postal_stats['change_percent'] > 0 ? 'text-success' : ($postal_stats['change_percent'] < 0 ? 'text-danger' : 'text-muted'); ?>">
                                    <?php echo $postal_stats['change_percent'] > 0 ? '+' : ''; ?><?php echo number_format($postal_stats['change_percent'], 1); ?>%
                                </span>
                                <i class="fas <?php echo $postal_stats['change_percent'] > 0 ? 'fa-arrow-up text-success' : ($postal_stats['change_percent'] < 0 ? 'fa-arrow-down text-danger' : 'fa-minus text-muted'); ?> extra-small"></i>
                                <span class="text-muted extra-small">(<?php echo number_format($postal_stats['prev_month']); ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- بطاقة حجوزات الباصات -->
            <div class="col-xl-2 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="icon-box icon-box-warning">
                                <i class="fas fa-bus"></i>
                            </div>
                            <span class="stat-badge">حجوزات الباصات</span>
                        </div>
                        <h2 class="fw-bold mb-1 mt-2"><?php echo number_format($bus_stats['total']); ?></h2>
                        <div class="d-flex gap-3 mt-3 pt-3 border-top">
                            <div>
                                <div class="text-muted extra-small">اليوم</div>
                                <div class="fw-bold small"><?php echo number_format($bus_stats['today']); ?></div>
                            </div>
                            <div class="border-start ps-3">
                                <div class="text-muted extra-small">هذا الشهر</div>
                                <div class="fw-bold small"><?php echo number_format($bus_stats['month']); ?></div>
                            </div>
                        </div>
                        <!-- مقارنة الشهر السابق -->
                        <div class="mt-2 pt-2 border-top">
                            <div class="text-muted extra-small">مقارنة الشهر السابق</div>
                            <div class="d-flex align-items-center gap-1">
                                <span class="fw-bold <?php echo $bus_stats['change_percent'] > 0 ? 'text-success' : ($bus_stats['change_percent'] < 0 ? 'text-danger' : 'text-muted'); ?>">
                                    <?php echo $bus_stats['change_percent'] > 0 ? '+' : ''; ?><?php echo number_format($bus_stats['change_percent'], 1); ?>%
                                </span>
                                <i class="fas <?php echo $bus_stats['change_percent'] > 0 ? 'fa-arrow-up text-success' : ($bus_stats['change_percent'] < 0 ? 'fa-arrow-down text-danger' : 'fa-minus text-muted'); ?> extra-small"></i>
                                <span class="text-muted extra-small">(<?php echo number_format($bus_stats['prev_month']); ?>)</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- جدول المرحل وغير المرحل لكل خدمة -->
        <div class="row g-4 mt-4" id="transaction-posting-status">
            <div class="col-12">
                <div class="card dashboard-card transaction-posting-card">
                    <div class="card-body">
                        <div class="transaction-posting-header mb-4">
                            <h5 class="card-title fw-bold mb-0">حالة الترحيل</h5>
                            <div class="transaction-posting-legend">
                                <span class="posted"><i class="fas fa-check-circle"></i> مرحل</span>
                                <span class="pending"><i class="fas fa-clock"></i> غير مرحل</span>
                            </div>
                        </div>
                        <div class="table-responsive transaction-posting-table-wrap">
                            <table class="table table-hover align-middle transaction-posting-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>الخدمة</th>
                                        <th class="text-center">المرحل</th>
                                        <th class="text-center">غير المرحل</th>
                                        <th class="text-center">الإجمالي</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($posted_unposted_stats as $stat) : ?>
                                        <tr>
                                            <td class="fw-bold service-cell" data-label="الخدمة"><?php echo htmlspecialchars($stat['name']); ?></td>
                                            <td class="text-center" data-label="المرحل">
                                                <span class="posting-count posted"><?php echo number_format($stat['posted']); ?></span>
                                            </td>
                                            <td class="text-center" data-label="غير المرحل">
                                                <span class="posting-count pending"><?php echo number_format($stat['draft']); ?></span>
                                            </td>
                                            <td class="text-center" data-label="الإجمالي">
                                                <span class="posting-count total"><?php echo number_format($stat['posted'] + $stat['draft']); ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Monthly Transactions Chart -->
            <div class="col-12">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h5 class="card-title fw-bold">المعاملات خلال آخر 30 يوماً</h5>
                        <div id="monthly-transactions-chart"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var options = {
                    chart: {
                        type: 'area',
                        height: 350,
                        zoom: {
                            enabled: false
                        },
                        toolbar: {
                            show: false
                        },
                        foreColor: 'var(--text-color)'
                    },
                    series: [{
                        name: 'المعاملات',
                        data: <?php echo json_encode(array_column($monthly_chart_data, 'count')); ?>
                    }],
                    xaxis: {
                        categories: <?php echo json_encode(array_column($monthly_chart_data, 'date')); ?>,
                        labels: {
                            style: {
                                colors: 'var(--text-color)'
                            }
                        }
                    },
                    yaxis: {
                        labels: {
                            style: {
                                colors: 'var(--text-color)'
                            }
                        }
                    },
                    dataLabels: {
                        enabled: false
                    },
                    stroke: {
                        curve: 'smooth',
                        width: 2,
                        colors: ['var(--primary-accent)']
                    },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shade: 'dark',
                            type: 'vertical',
                            shadeIntensity: 0.5,
                            gradientToColors: ['var(--card-bg)'],
                            inverseColors: true,
                            opacityFrom: 0.7,
                            opacityTo: 0.3,
                            stops: [0, 90, 100]
                        },
                        colors: ['var(--primary-accent)']
                    },
                    grid: {
                        borderColor: 'var(--border-color)',
                        strokeDashArray: 5
                    },
                    tooltip: {
                        theme: 'dark'
                    }
                };

                var chart = new ApexCharts(document.querySelector("#monthly-transactions-chart"), options);
                chart.render();
            });
        </script>
    </div>

    <!-- 2. لوحة النظام المالي -->
    <div id="financial-dashboard" class="mb-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="fw-bold mb-0">لوحة النظام المالي</h4>
            <div class="d-flex gap-2">
                <a href="general_ledger.php" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="fas fa-book me-1"></i> اليومية العامة</a>
                <a href="trial_balance.php" class="btn btn-sm btn-outline-dark rounded-pill px-3"><i class="fas fa-balance-scale me-1"></i> ميزان المراجعة</a>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- Today's Cash Flow -->
            <div class="col-lg-12">
                <div class="card dashboard-card bg-light border-0">
                    <div class="card-body">
                        <h6 class="fw-bold text-muted mb-4"><i class="fas fa-history me-2"></i> حركة النقدية اليومية (<?php echo date('Y-m-d'); ?>)</h6>
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="p-3 bg-white rounded-3 border-start border-success border-4 shadow-sm">
                                    <div class="text-muted extra-small mb-1">إجمالي المقبوضات اليوم</div>
                                    <?php if (empty($today_receipts_data)): ?>
                                        <div class="fw-bold text-success h4 mb-0">0.00</div>
                                    <?php else: ?>
                                        <?php foreach ($today_receipts_data as $tr): ?>
                                            <div class="fw-bold text-success h4 mb-0"><?php echo number_format($tr['total'], 2); ?> <span class="small fw-normal text-muted"><?php echo $tr['currency_name']; ?></span></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="p-3 bg-white rounded-3 border-start border-danger border-4 shadow-sm">
                                    <div class="text-muted extra-small mb-1">إجمالي المصروفات اليوم</div>
                                    <?php if (empty($today_expenses_data)): ?>
                                        <div class="fw-bold text-danger h4 mb-0">0.00</div>
                                    <?php else: ?>
                                        <?php foreach ($today_expenses_data as $te): ?>
                                            <div class="fw-bold text-danger h4 mb-0"><?php echo number_format($te['total'], 2); ?> <span class="small fw-normal text-muted"><?php echo $te['currency_name']; ?></span></div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Total Receipts -->
            <div class="col-lg-4 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-4">
                            <div class="icon-box icon-box-success mb-0 me-3">
                                <i class="fas fa-arrow-down"></i>
                            </div>
                            <h6 class="fw-bold mb-0">مقبوضات الشهر</h6>
                        </div>
                        <?php if (empty($total_receipts)): ?>
                            <p class="text-muted small">لا توجد مقبوضات مسجلة.</p>
                        <?php else: ?>
                            <?php foreach ($total_receipts as $receipt): ?>
                                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-light">
                                    <span class="text-muted small"><?php echo htmlspecialchars($currencies[$receipt['currency_id']] ?? '---'); ?></span>
                                    <span class="fw-bold text-success"><?php echo number_format($receipt['total'], 2); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Total Expenses -->
            <div class="col-lg-4 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-4">
                            <div class="icon-box icon-box-danger mb-0 me-3">
                                <i class="fas fa-arrow-up"></i>
                            </div>
                            <h6 class="fw-bold mb-0">مصروفات الشهر</h6>
                        </div>
                        <?php if (empty($total_expenses)): ?>
                            <p class="text-muted small">لا توجد مصروفات مسجلة.</p>
                        <?php else: ?>
                            <?php foreach ($total_expenses as $expense): ?>
                                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-light">
                                    <span class="text-muted small"><?php echo htmlspecialchars($currencies[$expense['currency_id']] ?? '---'); ?></span>
                                    <span class="fw-bold text-danger"><?php echo number_format($expense['total'], 2); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Total Balances (Cash & Bank) -->
            <div class="col-lg-4 col-md-6">
                <div class="card dashboard-card h-100 bg-dark">
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-4">
                            <div class="icon-box icon-box-dark mb-0 me-3 bg-white bg-opacity-10 text-white">
                                <i class="fas fa-vault"></i>
                            </div>
                            <h6 class="fw-bold mb-0 text-white">أرصدة الصناديق والبنوك</h6>
                        </div>
                        <?php if (empty($total_balances)): ?>
                            <p class="text-white opacity-50 small">لا توجد أرصدة متاحة.</p>
                        <?php else: ?>
                            <?php foreach ($total_balances as $tb): ?>
                                <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom border-secondary border-opacity-25">
                                    <span class="text-white opacity-75 small"><?php echo htmlspecialchars($tb['currency_name']); ?></span>
                                    <span class="fw-bold text-white"><?php echo number_format($tb['total'], 2); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Monthly Income/Expense Chart -->
            <div class="col-12">
                <div class="chart-container">
                    <h5 class="fw-bold mb-4">مقارنة المقبوضات والمصروفات (آخر 30 يوماً)</h5>
                    <div id="monthly-income-expense-chart"></div>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener("DOMContentLoaded", function() {
                var income_expense_options = {
                    chart: {
                        type: 'bar',
                        height: 350,
                        stacked: true,
                        toolbar: {
                            show: false
                        },
                        foreColor: 'var(--text-color)'
                    },
                    series: [{
                            name: 'المقبوضات',
                            data: <?php echo json_encode(array_column($monthly_income_chart_data, 'total')); ?>
                        },
                        {
                            name: 'المصروفات',
                            data: <?php echo json_encode(array_column($monthly_expense_chart_data, 'total')); ?>
                        }
                    ],
                    xaxis: {
                        categories: <?php echo json_encode(array_column($monthly_income_chart_data, 'date')); ?>,
                        labels: {
                            style: {
                                colors: 'var(--text-color)'
                            }
                        }
                    },
                    yaxis: {
                        labels: {
                            style: {
                                colors: 'var(--text-color)'
                            }
                        }
                    },
                    colors: ['var(--primary-accent)', '#dc3545'],
                    plotOptions: {
                        bar: {
                            horizontal: false,
                        },
                    },
                    grid: {
                        borderColor: 'var(--border-color)',
                        strokeDashArray: 5
                    },
                    tooltip: {
                        theme: 'dark'
                    },
                    legend: {
                        position: 'top',
                        horizontalAlign: 'left'
                    }
                };

                var income_expense_chart = new ApexCharts(document.querySelector("#monthly-income-expense-chart"), income_expense_options);
                income_expense_chart.render();
            });
        </script>
    </div>

    <!-- 3. لوحة الرسائل والموقع -->
    <div id="messages-dashboard" class="mb-5">
        <h4 class="mb-4 fw-bold">الرسائل والموقع</h4>
        <div class="row g-4">
            <!-- New Internal Messages -->
            <div class="col-lg-3 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body text-center d-flex flex-column align-items-center">
                        <div class="icon-box icon-box-primary">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo $new_internal_count; ?></h3>
                        <p class="text-muted extra-small mb-3">الرسائل الجديدة</p>
                        <button class="btn btn-sm btn-primary w-100 rounded-pill" data-bs-toggle="modal" data-bs-target="#newMessagesModal">
                            عرض التفاصيل
                        </button>
                    </div>
                </div>
            </div>

            <!-- Edited Internal Messages -->
            <div class="col-lg-3 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body text-center d-flex flex-column align-items-center">
                        <div class="icon-box icon-box-info">
                            <i class="fas fa-edit"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo $edited_internal_count; ?></h3>
                        <p class="text-muted extra-small mb-3">رسائل معدلة</p>
                        <button class="btn btn-sm btn-info w-100 rounded-pill text-white" data-bs-toggle="modal" data-bs-target="#editedMessagesModal">
                            عرض التفاصيل
                        </button>
                    </div>
                </div>
            </div>

            <!-- Contact Messages -->
            <div class="col-lg-3 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body text-center d-flex flex-column align-items-center">
                        <div class="icon-box icon-box-warning">
                            <i class="fas fa-comment-dots"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo $unread_msg_count; ?></h3>
                        <p class="text-muted extra-small mb-3">رسائل الموقع</p>
                        <button class="btn btn-sm btn-warning w-100 rounded-pill text-white" data-bs-toggle="modal" data-bs-target="#contactsModal">
                            عرض التفاصيل
                        </button>
                    </div>
                </div>
            </div>

            <!-- New Subscribers -->
            <div class="col-lg-3 col-md-6">
                <div class="card dashboard-card h-100">
                    <div class="card-body text-center d-flex flex-column align-items-center">
                        <div class="icon-box icon-box-success">
                            <i class="fas fa-users"></i>
                        </div>
                        <h3 class="fw-bold mb-1"><?php echo $unread_subs_count; ?></h3>
                        <p class="text-muted extra-small mb-3">المشتركين الجدد</p>
                        <button class="btn btn-sm btn-success w-100 rounded-pill" data-bs-toggle="modal" data-bs-target="#subscribersModal">
                            عرض التفاصيل
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 4. لوحة متابعة المعاملات -->
    <div id="kanban-dashboard" class="mb-5">
        <h4 class="mb-3 fw-bold">متابعة المعاملات (Kanban)</h4>
        <div class="horizontal-scroll-container">
            <?php foreach ($kanban_statuses as $status): ?>
                <div class="kanban-column">
                    <h6 class="kanban-column-title" style="border-color: <?php echo $status['status_color']; ?>;">
                        <span><?php echo htmlspecialchars($status['status_name']); ?></span>
                        <span class="badge bg-white text-dark border small"><?php echo $kanban_counts[$status['id']] ?? 0; ?></span>
                    </h6>
                    <div class="kanban-cards-container">
                        <?php foreach ($kanban_passports as $passport): ?>
                            <?php if ($passport['status_id'] == $status['id']): ?>
                                <div class="kanban-card">
                                    <div class="kanban-card-header">
                                        <span class="badge bg-light text-dark border">#<?php echo htmlspecialchars($passport['passport_number']); ?></span>
                                    </div>
                                    <div class="kanban-card-body">
                                        <p class="fw-bold mb-1"><?php echo htmlspecialchars($passport['full_name']); ?></p>
                                        <p class="small text-muted mb-1"><?php echo htmlspecialchars($passport['profession_name'] ?: '---'); ?></p>
                                    </div>
                                    <div class="kanban-card-footer d-flex justify-content-between align-items-center">
                                        <div class="d-flex flex-column">
                                            <span class="small text-muted"><i class="fas fa-user-tie me-1"></i> <?php echo htmlspecialchars($passport['agent_name'] ?: '---'); ?></span>
                                            <span class="small text-muted"><?php echo date('Y-m-d', strtotime($passport['created_at'])); ?></span>
                                        </div>
                                        <div class="kanban-actions">
                                            <a href="passports.php?search=<?php echo urlencode($passport['passport_number']); ?>" class="btn btn-xs btn-outline-primary p-1 px-2 rounded-pill shadow-sm transition-hover" title="عرض وتعديل">
                                                <i class="fas fa-external-link-alt fa-xs"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 5. جدول آخر المعاملات -->
    <div id="recent-transactions-dashboard" class="mb-5">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="fw-bold mb-0">آخر المعاملات المضافة</h4>
            <a href="passports.php" class="btn btn-sm btn-primary rounded-pill px-3">عرض كل المعاملات</a>
        </div>
        <div class="card dashboard-card border-0 rounded-4 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">رقم الجواز / الاسم</th>
                            <th>النوع</th>
                            <th>الحالة</th>
                            <th>التاريخ</th>
                            <th class="text-center pe-4">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_transactions)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-4 text-muted">لا توجد معاملات حديثة</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_transactions as $trans): ?>
                                <tr>
                                    <td class="ps-4">
                                        <div class="fw-bold text-dark">#<?php echo htmlspecialchars($trans['passport_number']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($trans['full_name']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark border rounded-pill small">
                                            <?php
                                            $types = ['visa' => 'تأشيرة', 'work_visa' => 'تأشيرة عمل', 'umrah' => 'عمرة', 'family_visit' => 'زيارة عائلية'];
                                            echo $types[$trans['transaction_type']] ?? $trans['transaction_type'];
                                            ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge rounded-pill small" style="background-color: <?php echo $trans['status_color']; ?>20; color: <?php echo $trans['status_color']; ?>; border: 1px solid <?php echo $trans['status_color']; ?>40;">
                                            <?php echo htmlspecialchars($trans['status_name']); ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted"><?php echo date('Y-m-d', strtotime($trans['created_at'])); ?></td>
                                    <td class="text-center pe-4">
                                        <div class="d-flex justify-content-center gap-1">
                                            <a href="passports.php?search=<?php echo urlencode($trans['passport_number']); ?>" class="btn btn-sm btn-outline-primary rounded-pill p-1 px-2" title="تعديل">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button class="btn btn-sm btn-outline-info rounded-pill p-1 px-2" title="تفاصيل سريعة" onclick="alert('تفاصيل المعاملة: <?php echo addslashes($trans['full_name']); ?>')">
                                                <i class="fas fa-info-circle"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<style>
@media (max-width: 768px) {
    .dashboard-card { margin-bottom: 15px !important; }
    .dashboard-card .display-5 { font-size: 1.8rem !important; }
    .dashboard-card .card-title { font-size: 0.9rem !important; }
}
    .transition-hover:hover {
        transform: translateY(0);
        background-color: #f8f9fa !important;
    }

    .btn-xs {
        padding: 0.18rem 0.45rem;
        font-size: 0.75rem;
    }

    .kanban-actions .btn:hover {
        transform: scale(1.03);
    }

    .modal-body-scroll {
        max-height: 400px;
        overflow-y: auto;
    }

    /* Clean UI overrides for dashboard sections (Replaces legacy hardcoded white colors) */
    #stats-dashboard .card,
    #financial-dashboard .card,
    #messages-dashboard .card,
    #recent-transactions-dashboard .card {
        border-radius: 1rem;
        border: none;
    }
    
    /* Light Mode specific fallback for text colors just in case */
    body:not(.theme-dark) #stats-dashboard .card h2,
    body:not(.theme-dark) #stats-dashboard .card h3,
    body:not(.theme-dark) #stats-dashboard .card h6,
    body:not(.theme-dark) #financial-dashboard .card h2,
    body:not(.theme-dark) #financial-dashboard .card h6 {
        color: #1e293b;
    }

    body:not(.theme-dark) #stats-dashboard .card .badge,
    body:not(.theme-dark) #financial-dashboard .card .badge,
    body:not(.theme-dark) #messages-dashboard .card .badge {
        background-color: #f1f5f9;
        color: #334155;
        border: 1px solid #e2e8f0;
    }

    /* Kanban column style */
    .horizontal-scroll-container {
        gap: 1rem;
        display: flex;
        overflow-x: auto;
        padding-bottom: 0.5rem;
    }

    .horizontal-scroll-container {
        gap: 1.25rem;
        display: flex;
        overflow-x: auto;
        padding: 0.75rem 0.25rem 0.5rem;
        scroll-behavior: smooth;
    }

    .kanban-column {
        min-width: 300px;
        max-width: 320px;
        background: #f3f6f9;
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        padding: 1rem;
        flex: 0 0 auto;
        box-shadow: 0 10px 25px rgba(15, 23, 42, 0.05);
    }

    .kanban-column-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        font-size: 0.96rem;
        font-weight: 800;
        color: #111827;
        margin-bottom: 1rem;
        border-bottom: 2px solid;
        padding-bottom: 0.8rem;
    }

    .kanban-column-title .badge {
        background: #ffffff;
        border: 1px solid #d1d5db;
        color: #111827;
        font-size: 0.78rem;
        font-weight: 700;
        padding: 0.25rem 0.6rem;
    }

    .kanban-cards-container {
        display: grid;
        gap: 0.85rem;
    }

    .kanban-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 1rem;
        padding: 1rem;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.05);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .kanban-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 16px 32px rgba(15, 23, 42, 0.12);
    }

    .kanban-card-header,
    .kanban-card-footer {
        margin-bottom: 0.75rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.5rem;
    }

    .kanban-card-header .badge {
        background: #eef2ff;
        color: #1f2937;
        border: none;
        font-weight: 600;
    }

    .kanban-card-body p {
        margin-bottom: 0.35rem;
    }

    .kanban-card-body .fw-bold {
        font-size: 1rem;
    }

    .kanban-card-body .small {
        color: #6b7280;
    }

    .kanban-card-footer span {
        color: #6b7280;
        font-size: 0.82rem;
    }

    .kanban-actions .btn {
        box-shadow: none !important;
        min-width: 2.6rem;
        height: 2.6rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
    }

    .kanban-actions .btn i {
        font-size: 0.95rem;
    }

    /* Kanban Dark Mode */
    body.theme-dark #kanban-dashboard .kanban-card,
    body.theme-dark .kanban-card {
        background: rgba(30, 41, 59, 0.7) !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
        color: #f8fafc !important;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.3) !important;
    }
    body.theme-dark .kanban-card:hover {
        background: rgba(30, 41, 59, 0.95) !important;
        border-color: rgba(255, 255, 255, 0.25) !important;
        box-shadow: 0 8px 15px rgba(0, 0, 0, 0.5) !important;
    }
    body.theme-dark .kanban-column {
        background: rgba(15, 23, 42, 0.5) !important;
        border-color: rgba(255, 255, 255, 0.05) !important;
    }
    body.theme-dark .kanban-column-title {
        color: #f8fafc !important;
        border-bottom-color: rgba(255, 255, 255, 0.1) !important;
    }
    body.theme-dark .kanban-card-body .fw-bold {
        color: #ffffff !important;
    }
    body.theme-dark .kanban-column-title .badge {
        background: rgba(255, 255, 255, 0.1) !important;
        border-color: rgba(255, 255, 255, 0.2) !important;
        color: #e2e8f0 !important;
    }
    body.theme-dark .kanban-card-header .badge {
        background: rgba(56, 189, 248, 0.15) !important;
        color: #7dd3fc !important;
    }
    body.theme-dark .kanban-card-body .small,
    body.theme-dark .kanban-card-footer span,
    body.theme-dark .kanban-card-body p {
        color: #94a3b8 !important;
    }

    /* Table and metric cards */
    .card.table-responsive {
        border-top-left-radius: 0.75rem;
        border-top-right-radius: 0.75rem;
    }

    .table thead th {
        background: #f8fafc;
        color: #111827;
        border-bottom: 2px solid #e2e8f0;
    }

    .table tbody tr:hover {
        background: #f8fafc;
    }

    .badge.rounded-pill,
    .btn.rounded-pill {
        border-radius: 0.55rem !important;
    }

    /* Dashboard sidebar tone */
    .sidebar {
        border-color: #e2e8f0 !important;
        box-shadow: none !important;
        background: #ffffff !important;
    }

    .sidebar-header,
    .user-panel,
    .sidebar-menu a {
        background: transparent !important;
    }

    .sidebar-menu a.active {
        background: rgba(37, 99, 235, 0.08) !important;
        color: #111827 !important;
    }
</style>

<!-- Modal الرسائل الجديدة -->
<div class="modal fade" id="newMessagesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold">الرسائل الجديدة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="modal-body-scroll">
                    <?php if (empty($new_messages_list)): ?>
                        <div class="p-4 text-center text-muted">لا توجد رسائل جديدة</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($new_messages_list as $msg): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <div>
                                            <span class="small text-muted">من:</span> <span class="fw-bold text-primary"><?php echo htmlspecialchars($msg['sender_name']); ?></span>
                                            <span class="small text-muted ms-2">إلى:</span> <span class="fw-bold text-dark"><?php echo htmlspecialchars($msg['receiver_name']); ?></span>
                                        </div>
                                        <div class="text-end">
                                            <small class="badge bg-light text-muted border fw-normal">تاريخ الإرسال: <?php echo date('Y-m-d H:i', strtotime($msg['created_at'])); ?></small>
                                        </div>
                                    </div>
                                    <div class="p-2 bg-light rounded-3 small border-start border-3 border-primary">
                                        <?php echo htmlspecialchars($msg['message']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer border-0">
                <a href="message_reports.php?filter=new" class="btn btn-primary rounded-pill px-4">كل الرسائل الجديدة</a>
            </div>
        </div>
    </div>
</div>

<!-- Modal الرسائل المعدلة -->
<div class="modal fade" id="editedMessagesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-info text-white border-0">
                <h5 class="modal-title fw-bold">الرسائل المعدلة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="modal-body-scroll">
                    <?php if (empty($edited_messages_list)): ?>
                        <div class="p-4 text-center text-muted">لا توجد رسائل معدلة</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($edited_messages_list as $msg): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <div>
                                            <span class="small text-muted">من:</span> <span class="fw-bold text-info"><?php echo htmlspecialchars($msg['sender_name']); ?></span>
                                            <span class="small text-muted ms-2">إلى:</span> <span class="fw-bold text-dark"><?php echo htmlspecialchars($msg['receiver_name']); ?></span>
                                        </div>
                                        <div class="text-end">
                                            <small class="badge bg-info-subtle text-info border border-info-subtle fw-normal">تاريخ التعديل: <?php echo date('Y-m-d H:i', strtotime($msg['updated_at'])); ?></small>
                                        </div>
                                    </div>
                                    <div class="mb-2">
                                        <div class="x-small text-muted mb-1"><i class="fas fa-history me-1"></i> النص الأصلي:</div>
                                        <div class="p-2 bg-light rounded-3 small text-muted text-decoration-line-through border-start border-3 border-secondary opacity-75">
                                            <?php echo htmlspecialchars($msg['original_message'] ?? '---'); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="x-small text-info mb-1"><i class="fas fa-check-circle me-1"></i> النص الجديد:</div>
                                        <div class="p-2 bg-info-subtle rounded-3 small border-start border-3 border-info">
                                            <?php echo htmlspecialchars($msg['message']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer border-0">
                <a href="message_reports.php?filter=edited" class="btn btn-info text-white rounded-pill px-4">كل الرسائل المعدلة</a>
            </div>
        </div>
    </div>
</div>

<!-- Modal الرسائل المحذوفة -->
<div class="modal fade" id="deletedMessagesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title fw-bold">الرسائل المحذوفة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="modal-body-scroll">
                    <?php if (empty($deleted_messages_list)): ?>
                        <div class="p-4 text-center text-muted">لا توجد رسائل محذوفة</div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($deleted_messages_list as $msg): ?>
                                <div class="list-group-item p-3">
                                    <div class="d-flex justify-content-between mb-2">
                                        <div>
                                            <span class="small text-muted">من:</span> <span class="fw-bold text-danger"><?php echo htmlspecialchars($msg['sender_name']); ?></span>
                                            <span class="small text-muted ms-2">إلى:</span> <span class="fw-bold text-dark"><?php echo htmlspecialchars($msg['receiver_name']); ?></span>
                                        </div>
                                        <div class="text-end">
                                            <small class="badge bg-danger-subtle text-danger border border-danger-subtle fw-normal">تاريخ الحذف: <?php echo date('Y-m-d H:i', strtotime($msg['updated_at'])); ?></small>
                                        </div>
                                    </div>
                                    <div class="p-2 bg-danger-subtle rounded-3 small border-start border-3 border-danger opacity-75 italic">
                                        <i class="fas fa-trash-alt me-1"></i> <?php echo htmlspecialchars($msg['message']); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer border-0">
                <a href="message_reports.php?filter=deleted" class="btn btn-danger rounded-pill px-4">كل الرسائل المحذوفة</a>
            </div>
        </div>
    </div>
</div>

</div>

<!-- Modal الفروع -->
<div class="modal fade" id="branchesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-warning text-dark border-0">
                <h5 class="modal-title fw-bold">آخر الفروع المضافة</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="modal-body-scroll">
                    <div class="list-group list-group-flush">
                        <?php foreach ($recent_branches as $br): ?>
                            <div class="list-group-item p-3">
                                <div class="fw-bold"><?php echo htmlspecialchars($br['branch_name']); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($br['branch_code']); ?> - <?php echo htmlspecialchars($br['phone']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <a href="branches.php" class="btn btn-warning rounded-pill px-4">إدارة الفروع</a>
            </div>
        </div>
    </div>
</div>

<!-- Modal رسائل الموقع -->
<div class="modal fade" id="contactsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-primary text-white border-0">
                <h5 class="modal-title fw-bold">آخر رسائل الموقع</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="modal-body-scroll">
                    <div class="list-group list-group-flush">
                        <?php foreach ($recent_contacts as $con): ?>
                            <div class="list-group-item p-3">
                                <div class="d-flex justify-content-between">
                                    <span class="fw-bold"><?php echo htmlspecialchars($con['name']); ?></span>
                                    <small class="text-muted"><?php echo date('Y-m-d', strtotime($con['created_at'])); ?></small>
                                </div>
                                <div class="small fw-bold text-primary"><?php echo htmlspecialchars($con['subject']); ?></div>
                                <p class="mb-0 small text-truncate"><?php echo htmlspecialchars($con['message']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <a href="contact_messages.php" class="btn btn-primary rounded-pill px-4">كل الرسائل</a>
            </div>
        </div>
    </div>
</div>

<!-- Modal المشتركين -->
<div class="modal fade" id="subscribersModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-success text-white border-0">
                <h5 class="modal-title fw-bold">آخر المشتركين</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="modal-body-scroll">
                    <div class="list-group list-group-flush">
                        <?php foreach ($recent_subscribers as $sub): ?>
                            <div class="list-group-item p-3 d-flex justify-content-between align-items-center">
                                <span><?php echo htmlspecialchars($sub['email']); ?></span>
                                <small class="text-muted"><?php echo date('Y-m-d', strtotime($sub['created_at'])); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <a href="subscribers.php" class="btn btn-success rounded-pill px-4">إدارة المشتركين</a>
            </div>
        </div>
    </div>
</div>

<!-- Modal سير العمل -->
<div class="modal fade" id="workflowsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header bg-info text-white border-0">
                <h5 class="modal-title fw-bold">سير العمل النشط</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="modal-body-scroll">
                    <div class="list-group list-group-flush">
                        <?php foreach ($recent_workflows as $wf): ?>
                            <div class="list-group-item p-3">
                                <div class="fw-bold"><?php echo htmlspecialchars($wf['workflow_name'] ?? $wf['name'] ?? ''); ?></div>
                                <div class="small text-muted"><?php echo htmlspecialchars($wf['description'] ?? ''); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <a href="workflows.php" class="btn btn-info text-white rounded-pill px-4">إدارة سير العمل</a>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>


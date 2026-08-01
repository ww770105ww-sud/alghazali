<?php
/**
 * سكربت إصلاح أسماء الصلاحيات العربية في جدول unified_permissions
 * يعيد تعيين display_name لكل permission_code بالترميز الصحيح utf8mb4
 */
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: text/html; charset=utf-8');

$map = [
    // ===== Roles =====
    'roles_view'                          => 'عرض قائمة الأدوار',
    'roles_create'                        => 'إنشاء دور جديد',
    'roles_edit'                          => 'تعديل بيانات الدور',
    'roles_delete'                        => 'حذف الدور',

    // ===== Users =====
    'users_view'                          => 'عرض قائمة المستخدمين',
    'users_create'                        => 'إضافة مستخدم جديد',
    'users_edit'                          => 'تعديل بيانات المستخدم',
    'users_delete'                        => 'حذف المستخدم',

    // ===== Generic Vouchers =====
    'voucher_create'                      => 'إنشاء سند مالي',
    'voucher_edit'                        => 'تعديل سند مالي',
    'voucher_delete'                      => 'حذف سند مالي',
    'voucher_post'                        => 'ترحيل سند مالي',
    'voucher_reverse'                     => 'عكس سند مالي مرحل',
    'voucher_edit_posted'                 => 'تعديل سند مالي مرحل',
    'vouchers_unpost'                     => 'إلغاء ترحيل سند مالي',

    // ===== Receipts =====
    'receipts_view'                       => 'عرض سندات القبض',
    'receipt_reverse'                     => 'عكس سند قبض مرحل',
    'receipt_delete_original'             => 'حذف سند قبض أصلي (مسودة/ملغي)',
    'receipt_delete_reversal'             => 'حذف سند قبض عكسي',

    // ===== Payments =====
    'payments_view'                       => 'عرض سندات الصرف',
    'payment_reverse'                     => 'عكس سند صرف مرحل',
    'payment_delete_original'             => 'حذف سند صرف أصلي (مسودة/ملغي)',
    'payment_delete_reversal'             => 'حذف سند صرف عكسي',

    // ===== Invoices =====
    'invoices_view'                       => 'عرض الفواتير',

    // ===== Unified Hub =====
    'unified_payments_view'               => 'عرض مركز المدفوعات الموحد',
    'expenses_view'                       => 'عرض قائمة المصروفات',
    'col_amount_view'                     => 'رؤية عمود المبلغ',
    'col_balance_view'                    => 'رؤية عمود الرصيد',
    'transactions_edit_amount'            => 'تعديل مبلغ المعاملة',
    'financial_hub_view'                  => 'عرض المركز المالي',
    'view_all_transactions'               => 'عرض جميع المعاملات المالية',
    'view_financial_data'                 => 'عرض البيانات المالية',
    'edit_financial_prices'               => 'تعديل الأسعار المالية',

    // ===== Accounts =====
    'accounts_view'                       => 'عرض قائمة الحسابات',
    'accounts_create'                     => 'إنشاء حساب جديد',
    'accounts_edit'                       => 'تعديل الحساب',
    'accounts_delete'                     => 'حذف الحساب',
    'account_statement_view'              => 'عرض كشف حساب',
    'general_ledger_view'                 => 'عرض دفتر الأستاذ العام',
    'trial_balance_view'                  => 'عرض ميزان المراجعة',
    'income_statement_view'               => 'عرض قائمة الدخل والتكاليف',

    // ===== Cost Centers =====
    'manage_cost_centers'                 => 'إدارة مراكز التكلفة',
    'view_cost_center_reports'            => 'تقارير مركز التكلفة',

    // ===== Financial Management =====
    'manage_financial_accounts'           => 'إدارة الحسابات المالية (صناديق/بنوك)',
    'manage_expenses'                     => 'إدارة أنواع المصروفات',
    'banks_view'                          => 'عرض حسابات البنوك',
    'boxes_view'                          => 'عرض حسابات الصناديق',
    'currencies_view'                     => 'عرض قائمة العملات',
    'currency_exchange_view'              => 'عرض أسعار الصرف',
    'financial_reports_view'              => 'عرض التقارير المالية',
    'view_reports'                        => 'عرض قائمة التقارير',

    // ===== Umrah =====
    'umrah_view'                          => 'عرض قسم العمرة',
    'umrah_create'                        => 'إضافة طلب عمرة جديد',
    'umrah_edit'                          => 'تعديل بيانات طلب عمرة',
    'umrah_delete'                        => 'حذف طلب عمرة',
    'umrah_financial_view'                => 'عرض البيانات المالية للعمرة',
    'umrah_financial_post'                => 'ترحيل القيود المالية للعمرة',
    'umrah_accounts_approve'              => 'موافقة حسابات قسم العمرة',
    'umrah_edit_purchase_price'           => 'تعديل سعر شراء طلب العمرة',
    'umrah_show_sale_price'               => 'عرض سعر بيع طلب العمرة',

    // ===== Work Visa =====
    'work_visa_view'                      => 'عرض تأشيرات العمل',
    'work_visa_create'                    => 'إضافة تأشيرة عمل جديدة',
    'work_visa_edit'                      => 'تعديل تأشيرة عمل',
    'work_visa_delete'                    => 'حذف تأشيرة عمل',
    'work_visa_approve'                   => 'موافقة تأشيرة عمل',
    'work_visa_print'                     => 'طباعة تأشيرة عمل',
    'work_visa_financial_view'            => 'عرض البيانات المالية لتأشيرات العمل',
    'work_visa_financial_post'            => 'ترحيل القيود المالية لتأشيرات العمل',
    'work_visa_accounts_approve'          => 'موافقة حسابات تأشيرات العمل',
    'work_visa_edit_amount'               => 'تعديل مبلغ تأشيرة عمل',
    'work_visa_edit_purchase_price'       => 'تعديل سعر شراء تأشيرة عمل',
    'work_visa_show_sale_price'           => 'عرض سعر بيع تأشيرة عمل',
    'work_visa_manage_settings'           => 'إعدادات قسم تأشيرات العمل',
    'work_visa_scan_passport'             => 'مسح بيانات جواز السفر (OCR)',

    // ===== Bookings =====
    'bookings_view'                       => 'عرض قائمة الحجوزات',
    'bookings_create'                     => 'إضافة حجز جديد',
    'bookings_edit'                       => 'تعديل بيانات الحجز',
    'bookings_delete'                     => 'حذف الحجز',
    'bookings_confirm'                    => 'تأكيد الحجز',
    'bookings_cancel'                     => 'إلغاء الحجز',
    'bookings_view_all'                   => 'عرض جميع الحجوزات',
    'bookings_receive_payment'            => 'استلام دفعة للحجز',
    'bookings_edit_purchase_price'        => 'تعديل سعر شراء الحجز',

    // ===== Passports =====
    'view_passports'                      => 'عرض الجوازات',
    'edit_passports'                      => 'تعديل بيانات الجوازات',
    'delete_passport'                     => 'حذف جواز سفر',
    'view_all_passports'                  => 'عرض جميع الجوازات',
    'change_passport_status'              => 'تغيير حالة جواز السفر',
    'passport_transactions_view'          => 'عرض معاملات الجوازات',
    'passport_transactions_create'        => 'إضافة معاملة جوازات',
    'passport_transactions_edit'          => 'تعديل معاملة جوازات',
    'passport_transactions_print'         => 'طباعة معاملة جوازات',

    // ===== Entities =====
    'customers_view'                      => 'عرض قائمة العملاء',
    'customers_create'                    => 'إضافة عميل جديد',
    'customers_edit'                      => 'تعديل بيانات العميل',
    'customers_delete'                    => 'حذف العميل',

    'suppliers_view'                      => 'عرض قائمة الموردين',
    'suppliers_create'                    => 'إضافة مورد جديد',
    'suppliers_edit'                      => 'تعديل بيانات المورد',
    'suppliers_delete'                    => 'حذف المورد',

    'branches_view'                       => 'عرض قائمة الفروع',
    'branches_create'                     => 'إضافة فرع جديد',
    'branches_edit'                       => 'تعديل بيانات الفرع',
    'branches_delete'                     => 'حذف الفرع',
    'view_all_branches'                   => 'عرض جميع الفروع',
    'view_all_agents_branches'            => 'عرض جميع الفروع والوكلاء',

    'agents_view'                         => 'عرض قائمة الوكلاء',
    'agents_create'                       => 'إضافة وكيل جديد',
    'agents_edit'                         => 'تعديل بيانات الوكيل',
    'agents_delete'                       => 'حذف الوكيل',

    'employees_view'                      => 'عرض قائمة الموظفين',
    'employees_create'                    => 'إضافة موظف جديد',
    'employees_edit'                      => 'تعديل بيانات الموظف',
    'employees_delete'                    => 'حذف الموظف',

    // ===== Family Visit =====
    'family_visit_view'                   => 'عرض قسم زيارة العائلة',
    'family_visit_create'                 => 'إضافة طلب زيارة عائلية',
    'family_visit_edit'                   => 'تعديل طلب زيارة عائلية',
    'family_visit_delete'                 => 'حذف طلب زيارة عائلية',

    // ===== Workflow =====
    'view_workflow'                       => 'عرض سير العمل',
    'create_workflow'                     => 'إنشاء سير عمل جديد',
    'edit_workflow'                       => 'تعديل سير العمل',
    'workflow_approvals_view'             => 'عرض موافقات سير العمل',
    'request_document_confirmation'       => 'طلب تأكيد مستند',

    // ===== Settings =====
    'settings_view'                       => 'عرض إعدادات النظام',
    'settings_edit'                       => 'تعديل إعدادات النظام',
    'db_migration_view'                   => 'عرض ترحيل قاعدة البيانات',
    'audit_log_view'                      => 'عرض سجل التدقيق',
    'internal_messages_view'              => 'عرض الرسائل الداخلية',

    // ===== Services =====
    'view_service_prices'                 => 'عرض أسعار الخدمات',
    'services_edit_sale_price'            => 'تعديل سعر بيع الخدمة',
    'services_edit_purchase_price'        => 'تعديل سعر شراء الخدمة',
    'services_edit_currency'              => 'تعديل عملة الخدمة',
];

try {
    $updated = 0;
    $skipped = 0;
    $pdo->beginTransaction();
    $stmt_update = $pdo->prepare("UPDATE unified_permissions SET display_name = ? WHERE permission_code = ?");

    foreach ($map as $code => $arabic_name) {
        $stmt_update->execute([$arabic_name, $code]);
        if ($stmt_update->rowCount() > 0) {
            $updated++;
        } else {
            $skipped++;
        }
    }

    $pdo->commit();

    // Verify by fetching 10 rows
    $verify = $pdo->query("SELECT permission_code, display_name FROM unified_permissions ORDER BY id LIMIT 12")->fetchAll();

    echo "<h2 style='color:green'>✅ تم بنجاح!</h2>";
    echo "<p><strong>العدد الذي تم تحديثه:</strong> $updated صلاحية</p>";
    echo "<p><strong>العدد الذي لم يتغير:</strong> $skipped صلاحية</p>";
    echo "<hr><h3>عينة بعد التحديث:</h3><table border='1' cellpadding='6' style='direction:rtl;text-align:right'>";
    echo "<tr><th>permission_code</th><th>display_name (العربي)</th></tr>";
    foreach ($verify as $row) {
        echo "<tr><td><code>" . htmlspecialchars($row['permission_code']) . "</code></td><td>" . htmlspecialchars($row['display_name']) . "</td></tr>";
    }
    echo "</table>";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "<h2 style='color:red'>❌ خطأ!</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}

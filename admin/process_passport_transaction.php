<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/accounting_functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$redirect_to = 'passport_transactions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // جلب إعدادات الموديول
    $settings = getSettings($pdo);

    // جلب بيانات المستخدم الحالي
    $stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt_user->execute([$_SESSION['admin_id']]);
    $currentUser = $stmt_user->fetch(PDO::FETCH_ASSOC);

    switch ($action) {
        case 'add':
            if (!has_permission('passport_transactions_create')) {
                $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'ليس لديك صلاحية لإضافة معاملة جوازات جديدة.'];
                header('Location: ' . $redirect_to);
                exit();
            }

            try {
                $pdo->beginTransaction();

                $transaction_number = htmlspecialchars($_POST['transaction_number'] ?? '');
                $full_name = htmlspecialchars($_POST['full_name'] ?? '');
                $phone_number = htmlspecialchars($_POST['phone_number'] ?? '');
                $place_of_birth = htmlspecialchars($_POST['place_of_birth'] ?? '');
                $date_of_birth = $_POST['date_of_birth'] ?? null;
                $id_type = htmlspecialchars($_POST['id_type'] ?? 'passport');
                $id_number = htmlspecialchars($_POST['id_number'] ?? '');
                $from_city_id = (int)$_POST['from_city_id'];
                $to_city_id = (int)$_POST['to_city_id'];
                if ($from_city_id === $to_city_id) {
                    throw new Exception('لا يمكن أن تكون المدينة المنطلق نفس المدينة المقصود.');
                }
                $transaction_type_ids = $_POST['transaction_type_id'] ?? [];
                if (!is_array($transaction_type_ids)) {
                    $transaction_type_ids = [$transaction_type_ids];
                }
                $transaction_type_ids = array_values(array_filter(array_map('intval', $transaction_type_ids)));
                $transaction_type_id = $transaction_type_ids[0] ?? 0;
                $transaction_type = htmlspecialchars($_POST['transaction_type'] ?? 'both');

                $card_transaction_number = htmlspecialchars($_POST['card_transaction_number'] ?? null);
                $card_transaction_date = !empty($_POST['card_transaction_date']) ? $_POST['card_transaction_date'] : null;
                $card_number = htmlspecialchars($_POST['card_number'] ?? null);
                $card_issue_date = !empty($_POST['card_issue_date']) ? $_POST['card_issue_date'] : null;

                $passport_transaction_number = htmlspecialchars($_POST['passport_transaction_number'] ?? null);
                $passport_transaction_date = !empty($_POST['passport_transaction_date']) ? $_POST['passport_transaction_date'] : null;
                $passport_number = htmlspecialchars($_POST['passport_number'] ?? null);
                $passport_issue_date = !empty($_POST['passport_issue_date']) ? $_POST['passport_issue_date'] : null;
                $travel_date = !empty($_POST['travel_date']) ? $_POST['travel_date'] : null;
                $today = date('Y-m-d');

                if (!empty($date_of_birth) && $date_of_birth > $today) {
                    throw new Exception('تاريخ الميلاد يجب أن يكون اليوم أو قبله.');
                }

                if (!empty($travel_date) && $travel_date < $today) {
                    throw new Exception('تاريخ السفر يجب أن يكون اليوم أو بعده.');
                }

                $delivery_receiver_name = htmlspecialchars($_POST['delivery_receiver_name'] ?? null);
                $operation_date = $_POST['operation_date'] ?? date('Y-m-d');
                
                // البيانات المالية المحسنة (من financial_fields.php)
                $sale_currency_id = (int)($_POST['sale_currency_id'] ?? $_POST['currency_id'] ?? 1);
                $purchase_currency_id = (int)($_POST['currency_id'] ?? $sale_currency_id ?: 1);
                $sale_price = (float)($_POST['total_amount'] ?? 0);
                $discount = (float)($_POST['discount'] ?? 0);
                $purchase_price = (float)($_POST['cost_amount'] ?? 0);
                $exchange_rate = (float)($_POST['exchange_rate'] ?? 1);
                $amount_received = (float)($_POST['received_amount'] ?? $_POST['amount_received'] ?? 0);
                $delivery_type = htmlspecialchars($_POST['delivery_type'] ?? 'draft');
                $record_purchase = isset($_POST['record_purchase']) && $_POST['record_purchase'] == '1';
                $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
                $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
                
                // معالجة العميل أو الوكيل
                $customer_raw = $_POST['customer_id'] ?? '';
                $customer_id = null;
                $agent_id_from_post = null;
                
                if (!empty($customer_raw)) {
                    if (strpos($customer_raw, 'cust_') === 0) {
                        $customer_id = (int)str_replace('cust_', '', $customer_raw);
                    } elseif (strpos($customer_raw, 'agent_') === 0) {
                        $agent_id_from_post = (int)str_replace('agent_', '', $customer_raw);
                    } else {
                        // في حالة إرسال الرقم مباشرة بدون بادئة (كما في النموذج الحالي)
                        $customer_id = (int)$customer_raw;
                    }
                }

                // التأكد من جلب وكيل من الحقل المخفي إذا لم يتم تحديده من الـ customer_id
                if (empty($agent_id_from_post) && !empty($_POST['agent_id'])) {
                    $agent_id_from_post = (int)$_POST['agent_id'];
                }

                $description = htmlspecialchars($_POST['description'] ?? null);
                $notes = htmlspecialchars($_POST['notes'] ?? null);
                $status_id = (int)$_POST['status_id'];
                $workflow_id = !empty($_POST['workflow_id']) ? (int)$_POST['workflow_id'] : null;
                $created_by = (int)$_POST['created_by'];
                $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
                $agent_id = !empty($agent_id_from_post) ? $agent_id_from_post : (!empty($_POST['agent_id']) ? (int)$_POST['agent_id'] : null);

                // حساب التكلفة بعملة البيع إذا اختلفتا
                $cost_in_sale_currency = $purchase_price;
                if ($sale_currency_id != $purchase_currency_id && $exchange_rate > 0) {
                    $cost_in_sale_currency = $purchase_price * $exchange_rate;
                }

                // Generate transaction number if auto-numbering is enabled and not provided
                if ($settings['passport_auto_numbering'] && empty($transaction_number)) {
                    $prefix = $settings['passport_number_prefix'];
                    $start_number = $settings['passport_start_number'];
                    $number_digits = $settings['passport_number_digits'];

                    $stmt_last_num = $pdo->query("SELECT transaction_number FROM passport_transactions ORDER BY id DESC LIMIT 1");
                    $last_transaction = $stmt_last_num->fetch(PDO::FETCH_ASSOC);

                    if ($last_transaction) {
                        $last_num = (int)substr($last_transaction['transaction_number'], strlen($prefix));
                        $next_num = $last_num + 1;
                    } else {
                        $next_num = $start_number;
                    }
                    $transaction_number = $prefix . str_pad($next_num, $number_digits, '0', STR_PAD_LEFT);
                } else if (empty($transaction_number)) {
                    throw new Exception('رقم المعاملة مطلوب.');
                }

                $service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
                if ($transaction_type_id <= 0) {
                    throw new Exception('يرجى اختيار نوع المعاملة (التسعيرة).');
                }
                if ($from_city_id === $to_city_id) {
                    throw new Exception('لا يمكن أن تكون المدينة المنطلق نفس المدينة المقصود.');
                }
                
                // Insert into passport_transactions
                $stmt = $pdo->prepare("
                    INSERT INTO `passport_transactions` (
                        `transaction_number`, `full_name`, `phone_number`, `place_of_birth`, `date_of_birth`, 
                        `id_type`, `id_number`, `from_city_id`, `to_city_id`, `travel_date`, `transaction_type_id`, `transaction_type`, 
                        `card_transaction_number`, `card_transaction_date`, `card_number`, `card_issue_date`, 
                        `passport_transaction_number`, `passport_transaction_date`, `passport_number`, `passport_issue_date`, 
                        `delivery_receiver_name`, `operation_date`, `customer_id`, `agent_id`,
                        `description`, `notes`, `status_id`, `workflow_id`, `created_by`, `branch_id`,
                        `service_id`
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                ");

                $stmt->execute([
                    $transaction_number, $full_name, $phone_number, $place_of_birth, $date_of_birth,
                    $id_type, $id_number, $from_city_id, $to_city_id, $travel_date, $transaction_type_id, $transaction_type,
                    $card_transaction_number, $card_transaction_date, $card_number, $card_issue_date,
                    $passport_transaction_number, $passport_transaction_date, $passport_number, $passport_issue_date,
                    $delivery_receiver_name, $operation_date, $customer_id, $agent_id,
                    $description, $notes, $status_id, $workflow_id, $created_by, $branch_id,
                    $service_id
                ]);

                $transaction_id = $pdo->lastInsertId();
                
                // استخدام المحرك المالي الموحد
                require_once '../includes/ServiceFinancialEngine.php';
                $financialEngine = new ServiceFinancialEngine($pdo, $created_by);
                $financeResults = $financialEngine->processServiceFinance([
                    'source_type'     => 'معاملات جوازات',
                    'service_type'    => 'passport_transaction',
                    'source_id'       => $transaction_id,
                    'source_number'   => $transaction_number,
                    'branch_id'       => $branch_id,
                    'customer_id'     => $customer_id,
                    'agent_id'        => $agent_id,
                    'supplier_id'     => $supplier_id,
                    'sale_price'      => $sale_price,
                    'discount'        => $discount,
                    'purchase_price'  => $purchase_price,
                    'sale_currency_id'=> $sale_currency_id,
                    'pur_currency_id' => $purchase_currency_id,
                    'exchange_rate'   => $exchange_rate,
                    'amount_received' => $amount_received,
                    'payment_account_id' => $account_id,
                    'delivery_type'   => $delivery_type,
                    'record_purchase' => $record_purchase ? '1' : '0',
                    'description'     => $_POST['description'] ?? "معاملة جواز رقم: " . $transaction_number . " للمسافر: " . $full_name,
                    'operation_date'  => normalize_datetime_db($_POST['invoice_date'] ?? $operation_date)
                ]);

                // ربط المعاملة بفاتورة البيع والشراء
                $update_stmt = $pdo->prepare("
                    UPDATE passport_transactions 
                    SET sales_invoice_id = ?, purchase_invoice_id = ?, auto_invoice_generated = 1 
                    WHERE id = ?
                ");
                $update_stmt->execute([
                    $financeResults['sales_invoice_id'], 
                    $financeResults['purchase_invoice_id'] ?? null, 
                    $transaction_id
                ]);

                $pdo->commit();
                $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم بنجاح', 'body' => 'تم إضافة معاملة الجوازات بنجاح.'];
                header('Location: ' . $redirect_to);
                exit();

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'خطأ أثناء إضافة المعاملة: ' . $e->getMessage()];
                header('Location: ' . $redirect_to);
                exit();
            }
            break;
        
        case 'edit':
            if (!has_permission('passport_transactions_edit')) {
                $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'ليس لديك صلاحية لتعديل معاملة جوازات.'];
                header('Location: ' . $redirect_to);
                exit();
            }

            try {
                $pdo->beginTransaction();

                $id = (int)$_POST['id'];
                $full_name = htmlspecialchars($_POST['full_name'] ?? '');
                $phone_number = htmlspecialchars($_POST['phone_number'] ?? '');
                $place_of_birth = htmlspecialchars($_POST['place_of_birth'] ?? '');
                $date_of_birth = $_POST['date_of_birth'] ?? null;
                $id_type = htmlspecialchars($_POST['id_type'] ?? 'passport');
                $id_number = htmlspecialchars($_POST['id_number'] ?? '');
                $from_city_id = (int)$_POST['from_city_id'];
                $to_city_id = (int)$_POST['to_city_id'];
                if ($from_city_id === $to_city_id) {
                    throw new Exception('لا يمكن أن تكون المدينة المنطلق نفس المدينة المقصود.');
                }
                $transaction_type_ids = $_POST['transaction_type_id'] ?? [];
                if (!is_array($transaction_type_ids)) {
                    $transaction_type_ids = [$transaction_type_ids];
                }
                $transaction_type_ids = array_values(array_filter(array_map('intval', $transaction_type_ids)));
                $transaction_type_id = $transaction_type_ids[0] ?? 0;
                $transaction_type = htmlspecialchars($_POST['transaction_type'] ?? 'both');

                $card_transaction_number = htmlspecialchars($_POST['card_transaction_number'] ?? null);
                $card_transaction_date = !empty($_POST['card_transaction_date']) ? $_POST['card_transaction_date'] : null;
                $card_number = htmlspecialchars($_POST['card_number'] ?? null);
                $card_issue_date = !empty($_POST['card_issue_date']) ? $_POST['card_issue_date'] : null;

                $passport_transaction_number = htmlspecialchars($_POST['passport_transaction_number'] ?? null);
                $passport_transaction_date = !empty($_POST['passport_transaction_date']) ? $_POST['passport_transaction_date'] : null;
                $passport_number = htmlspecialchars($_POST['passport_number'] ?? null);
                $passport_issue_date = !empty($_POST['passport_issue_date']) ? $_POST['passport_issue_date'] : null;
                $travel_date = !empty($_POST['travel_date']) ? $_POST['travel_date'] : null;
                $today = date('Y-m-d');

                if (!empty($date_of_birth) && $date_of_birth > $today) {
                    throw new Exception('تاريخ الميلاد يجب أن يكون اليوم أو قبله.');
                }

                if (!empty($travel_date) && $travel_date < $today) {
                    throw new Exception('تاريخ السفر يجب أن يكون اليوم أو بعده.');
                }

                $delivery_receiver_name = htmlspecialchars($_POST['delivery_receiver_name'] ?? null);
                $operation_date = $_POST['operation_date'] ?? date('Y-m-d');
                
                // البيانات المالية المحسنة للتعديل
                $sale_currency_id = (int)($_POST['sale_currency_id'] ?? $_POST['currency_id'] ?? 1);
                $purchase_currency_id = (int)($_POST['currency_id'] ?? $sale_currency_id ?: 1);
                $sale_price = (float)($_POST['total_amount'] ?? $_POST['sale_price'] ?? 0);
                $discount = (float)($_POST['discount'] ?? 0);
                $purchase_price = (float)($_POST['cost_amount'] ?? $_POST['purchase_price'] ?? 0);
                $exchange_rate = (float)($_POST['exchange_rate'] ?? 1);
                $amount_received = (float)($_POST['received_amount'] ?? $_POST['amount_received'] ?? 0);
                $delivery_type = htmlspecialchars($_POST['delivery_type'] ?? 'draft');
                $record_purchase = isset($_POST['record_purchase']) && $_POST['record_purchase'] == '1';
                $supplier_id = !empty($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
                $account_id = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
                $branch_id = !empty($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;
                
                // معالجة العميل أو الوكيل
                $customer_raw = $_POST['customer_id'] ?? '';
                $customer_id = null;
                $agent_id_from_post = null;
                
                if (!empty($customer_raw)) {
                    if (strpos($customer_raw, 'cust_') === 0) {
                        $customer_id = (int)str_replace('cust_', '', $customer_raw);
                    } elseif (strpos($customer_raw, 'agent_') === 0) {
                        $agent_id_from_post = (int)str_replace('agent_', '', $customer_raw);
                    } else {
                        $customer_id = (int)$customer_raw;
                    }
                }

                if (empty($agent_id_from_post) && !empty($_POST['agent_id'])) {
                    $agent_id_from_post = (int)$_POST['agent_id'];
                }

                $description = htmlspecialchars($_POST['description'] ?? null);
                $notes = htmlspecialchars($_POST['notes'] ?? null);
                $service_id = !empty($_POST['service_id']) ? (int)$_POST['service_id'] : null;
                $transaction_number = htmlspecialchars($_POST['transaction_number'] ?? '');
                if ($transaction_type_id <= 0) {
                    throw new Exception('يرجى اختيار نوع المعاملة (التسعيرة).');
                }
                
                // Update passport_transactions
                $stmt = $pdo->prepare("
                    UPDATE `passport_transactions` SET 
                        `transaction_number` = ?, `full_name` = ?, `phone_number` = ?, `place_of_birth` = ?, `date_of_birth` = ?, 
                        `id_type` = ?, `id_number` = ?, `from_city_id` = ?, `to_city_id` = ?, `travel_date` = ?, `transaction_type_id` = ?, `transaction_type` = ?, 
                        `card_transaction_number` = ?, `card_transaction_date` = ?, `card_number` = ?, `card_issue_date` = ?, 
                        `passport_transaction_number` = ?, `passport_transaction_date` = ?, `passport_number` = ?, `passport_issue_date` = ?, 
                        `delivery_receiver_name` = ?, `operation_date` = ?, `customer_id` = ?, `agent_id` = ?,
                        `description` = ?, `notes` = ?, `service_id` = ?
                    WHERE `id` = ?
                ");

                $stmt->execute([
                    $transaction_number, $full_name, $phone_number, $place_of_birth, $date_of_birth,
                    $id_type, $id_number, $from_city_id, $to_city_id, $travel_date, $transaction_type_id, $transaction_type,
                    $card_transaction_number, $card_transaction_date, $card_number, $card_issue_date,
                    $passport_transaction_number, $passport_transaction_date, $passport_number, $passport_issue_date,
                    $delivery_receiver_name, $operation_date, $customer_id, $agent_id_from_post,
                    $description, $notes, $service_id, $id
                ]);
                
                // حذف الفواتير القديمة والترحيلات المالية
                $stmt_old_invoices = $pdo->prepare("SELECT id FROM invoices WHERE source_type = 'passport_transaction' AND source_id = ?");
                $stmt_old_invoices->execute([$id]);
                $old_invoices = $stmt_old_invoices->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($old_invoices as $old_inv_id) {
                    // حذف التخصيصات
                    $pdo->prepare("DELETE FROM payment_allocations WHERE invoice_id = ?")->execute([$old_inv_id]);
                    // حذف القيود
                    $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id IN (SELECT id FROM financial_transactions WHERE invoice_id = ?)")->execute([$old_inv_id]);
                    $pdo->prepare("DELETE FROM journal_entries WHERE id IN (SELECT journal_entry_id FROM journal_lines WHERE invoice_id = ?)")->execute([$old_inv_id]);
                    // حذف المعاملات المالية
                    $pdo->prepare("DELETE FROM financial_transactions WHERE invoice_id = ?")->execute([$old_inv_id]);
                    // حذف الفاتورة
                    $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$old_inv_id]);
                }
                
                // تحديث أو إضافة الفواتير الجديدة باستخدام المحرك المالي
                require_once '../includes/ServiceFinancialEngine.php';
                $financialEngine = new ServiceFinancialEngine($pdo, $_SESSION['admin_id']);
                
                // إعداد supplier_id إذا كان record_purchase مفعلًا
                $final_supplier_id = $record_purchase ? $supplier_id : null;
                $final_purchase_price = $record_purchase ? $purchase_price : 0;
                
                $financeResults = $financialEngine->processServiceFinance([
                    'source_type'     => 'معاملات جوازات',
                    'service_type'    => 'passport_transaction',
                    'source_id'       => $id,
                    'source_number'   => $transaction_number,
                    'branch_id'       => $branch_id,
                    'customer_id'     => $customer_id,
                    'agent_id'        => $agent_id_from_post,
                    'supplier_id'     => $final_supplier_id,
                    'sale_price'      => $sale_price,
                    'discount'        => $discount,
                    'purchase_price'  => $final_purchase_price,
                    'sale_currency_id'=> $sale_currency_id,
                    'pur_currency_id' => $purchase_currency_id,
                    'exchange_rate'   => $exchange_rate,
                    'amount_received' => $amount_received,
                    'payment_account_id' => $account_id,
                    'delivery_type'   => $delivery_type,
                    'record_purchase' => $record_purchase ? '1' : '0',
                    'description'     => $description ?? "معاملة جواز رقم: " . $transaction_number . " للمسافر: " . $full_name,
                    'operation_date'  => normalize_datetime_db($_POST['invoice_date'] ?? $operation_date)
                ], true); // true = skip transaction because we already started one
                
                // ربط المعاملة بفاتورة البيع والشراء
                $update_stmt = $pdo->prepare("
                    UPDATE passport_transactions 
                    SET sales_invoice_id = ?, purchase_invoice_id = ?, auto_invoice_generated = 1 
                    WHERE id = ?
                ");
                $update_stmt->execute([
                    $financeResults['sales_invoice_id'], 
                    $financeResults['purchase_invoice_id'] ?? null, 
                    $id
                ]);

                $pdo->commit();
                $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم التحديث', 'body' => 'تم تحديث بيانات المعاملة بنجاح.'];
                header('Location: ' . $redirect_to);
                exit();

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'خطأ أثناء تحديث المعاملة: ' . $e->getMessage()];
                header('Location: ' . $redirect_to);
                exit();
            }
            break;

        case 'change_status':
            if (!has_permission('passport_transactions_change_status')) {
                $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'ليس لديك صلاحية لتغيير حالة المعاملة.'];
                header('Location: ' . $redirect_to);
                exit();
            }

            try {
                $pdo->beginTransaction();

                $id = (int)$_POST['id'];
                $new_status_id = (int)$_POST['status_id'];
                $notes = htmlspecialchars($_POST['status_notes'] ?? '');
                
                // جلب الحقول الإضافية من سير العمل إن وجدت
                $extra_fields = $_POST['extra_fields'] ?? [];

                // تحديث حالة المعاملة
                $stmt = $pdo->prepare("UPDATE passport_transactions SET status_id = ? WHERE id = ?");
                $stmt->execute([$new_status_id, $id]);

                // إذا كان هناك حقول إضافية، نقوم بتحديثها في المعاملة مباشرة (نفس أسلوب bus_flight_bookings)
                if (!empty($extra_fields)) {
                    $updates = [];
                    $params = [];
                    foreach ($extra_fields as $key => $val) {
                        $updates[] = "`$key` = ?";
                        $params[] = $val;
                    }
                    $params[] = $id;
                    $pdo->prepare("UPDATE passport_transactions SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);
                }

                // تسجيل في السجل
                $stmt_log = $pdo->prepare("INSERT INTO passport_transaction_logs (transaction_id, status_id, changed_by, notes) VALUES (?, ?, ?, ?)");
                $stmt_log->execute([$id, $new_status_id, $_SESSION['admin_id'], $notes]);

                // المسار المالي صار يعتمد على invoices مباشرة (تم الإنشاء/المزامنة في add/edit)

                $pdo->commit();
                $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم تغيير الحالة', 'body' => 'تم تحديث حالة المعاملة بنجاح.'];
                header('Location: ' . $redirect_to);
                exit();

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'خطأ أثناء تغيير الحالة: ' . $e->getMessage()];
                header('Location: ' . $redirect_to);
                exit();
            }
            break;

        case 'collect_payment':
            if (!has_permission('passport_transactions_collect_payment')) {
                $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'ليس لديك صلاحية لتسجيل الدفعات.'];
                header('Location: ' . $redirect_to);
                exit();
            }

            try {
                $pdo->beginTransaction();

                $id = (int)$_POST['transaction_id'];
                $amount = (float)$_POST['amount'];
                $account_id = (int)$_POST['account_id'];
                $payment_date = $_POST['payment_date'] ?: date('Y-m-d');
                $payment_type = $_POST['payment_type'] ?? 'cash';
                $notes = htmlspecialchars($_POST['notes'] ?? '');

                // جلب بيانات المعاملة مع الفاتورة الموحدة (مطلوبة)
                $stmt_trx = $pdo->prepare("
                    SELECT pt.*,
                           inv.id AS sales_invoice_id,
                           inv.currency_id AS currency_id,
                           inv.amount_received AS amount_received,
                           inv.total_amount AS sale_price
                    FROM passport_transactions pt
                    JOIN invoices inv
                        ON inv.source_type = 'passport_transaction'
                       AND inv.source_id = pt.id
                       AND inv.invoice_category = 'sales'
                    WHERE pt.id = ?
                ");
                $stmt_trx->execute([$id]);
                $trx = $stmt_trx->fetch();

                if (!$trx) throw new Exception("المعاملة أو الفاتورة المرتبطة غير موجودة.");

                $payer_type = $trx['agent_id'] ? 'agent' : 'customer';
                $payer_id = $trx['agent_id'] ?: $trx['customer_id'];

                // جلب حساب الطرف المالي
                $credit_account_id = null;
                if ($payer_type == 'customer') {
                    $stmt_p = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
                } elseif ($payer_type == 'agent') {
                    $stmt_p = $pdo->prepare("SELECT account_id FROM agents WHERE id = ?");
                }
                $stmt_p->execute([$payer_id]);
                $credit_account_id = $stmt_p->fetchColumn();

                if (!$credit_account_id) throw new Exception("حساب الطرف المالي غير موجود.");

                // تسجيل الدفعة والقيد المالي الموحد (نظام ERP الجديد)
                php_create_financial_entry(
                    $pdo,
                    $payment_date,
                    'receipt',
                    $payer_type,
                    $payer_id,
                    $account_id, // حساب الصندوق/البنك المختار كمدين
                    $credit_account_id, // حساب الطرف كدائن
                    $amount,
                    $trx['currency_id'],
                    "دفعة من معاملة جوازات رقم: " . $trx['transaction_number'] . " | " . $notes,
                    $_SESSION['admin_id'],
                    $trx['branch_id'],
                    null,
                    null,
                    'passport_transaction',
                    $id,
                    true // use_outer_transaction
                );

                // تحديث المبلغ المحصل وحالة الدفع على الفاتورة الموحدة (إن وجدت)
                $new_received = $trx['amount_received'] + $amount;
                $payment_status = 'partial';
                if ($new_received >= $trx['sale_price']) {
                    $payment_status = 'fully_paid';
                } else if ($new_received <= 0) {
                    $payment_status = 'unpaid';
                }

                if (!empty($trx['sales_invoice_id'])) {
                    $pdo->prepare("UPDATE invoices SET amount_received = ?, payment_status = ? WHERE id = ?")
                        ->execute([$new_received, $payment_status, (int)$trx['sales_invoice_id']]);
                }

                $pdo->commit();
                $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم تسجيل الدفعة', 'body' => 'تم تسجيل الدفعة بنجاح وتحديث الرصيد.'];
                header('Location: ' . $redirect_to);
                exit();

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'خطأ أثناء تسجيل الدفعة: ' . $e->getMessage()];
                header('Location: ' . $redirect_to);
                exit();
            }
            break;
    }
} else {
    header('Location: ' . $redirect_to);
    exit();
}
?>

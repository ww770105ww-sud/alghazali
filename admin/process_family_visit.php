<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$action = $_GET['action'] ?? '';

if ($action === 'add') {
    try {
        $pdo->beginTransaction();
        $auto_post = false;
        $settings = getSettings($pdo);
        $service_id = 5;
        $pricing_data = resolve_transaction_pricing(
            $pdo,
            $service_id,
            $_POST['agent_id'] ?? null,
            $_POST['branch_id'] ?? null,
            $_POST
        );
        $agent_id = $pricing_data['target']['agent_id'];
        $branch_id = $pricing_data['target']['branch_id'];
        $owner_type = $pricing_data['target']['owner_type'];
        $owner_id = $pricing_data['target']['owner_id'];

        // 1. Upload Main Files
        $upload_dir = '../assets/uploads/family_visits/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);

        $iqama_image = '';
        if (!empty($_FILES['iqama_image']['name'])) {
            $iqama_image = time() . '_iqama_' . basename($_FILES['iqama_image']['name']);
            move_uploaded_file($_FILES['iqama_image']['tmp_name'], $upload_dir . $iqama_image);
        }

        $document_pdf = '';
        if (!empty($_FILES['document_pdf']['name'])) {
            $document_pdf = time() . '_doc_' . basename($_FILES['document_pdf']['name']);
            move_uploaded_file($_FILES['document_pdf']['tmp_name'], $upload_dir . $document_pdf);
        }

        // 2. Insert Main Request
        $visit_duration_months = isset($_POST['visit_duration_months']) && $_POST['visit_duration_months'] !== '' ? (int)$_POST['visit_duration_months'] : (int)($settings['family_visit_default_validity_months'] ?? 1);
        if ($visit_duration_months < 1) {
            $visit_duration_months = 1;
        }
        $visit_expiry_date = null;
        if (!empty($_POST['issue_date'])) {
            $dtIssue = new DateTimeImmutable($_POST['issue_date']);
            $visit_expiry_date = $dtIssue->modify('+' . $visit_duration_months . ' months')->format('Y-m-d');
        }

        $hasDuration = !empty($pdo->query("SHOW COLUMNS FROM family_visit_requests LIKE 'visit_duration_months'")->fetchAll(PDO::FETCH_ASSOC));
        $hasExpiry = !empty($pdo->query("SHOW COLUMNS FROM family_visit_requests LIKE 'visit_expiry_date'")->fetchAll(PDO::FETCH_ASSOC));

        $columns = [
            'document_no',
            'issue_date',
            'date_type',
            'owner_name',
            'owner_id_no',
            'address',
            'phone_no',
            'iqama_image',
            'document_pdf',
            'agent_id',
            'branch_id',
            'user_id',
            'status_id',
            'notes',
            'operation_date',
            'description',
            'customer_id'
        ];
        $values = [
            $_POST['document_no'],
            $_POST['issue_date'],
            $_POST['date_type'],
            $_POST['owner_name'],
            $_POST['owner_id_no'],
            $_POST['address'],
            $_POST['phone_no'],
            $iqama_image,
            $document_pdf,
            $agent_id,
            $branch_id,
            $_SESSION['admin_id'],
            1,
            $_POST['notes'],
            $_POST['operation_date'] ?? date('Y-m-d'),
            $_POST['description'] ?? null,
            !empty($_POST['customer_id']) ? $_POST['customer_id'] : null
        ];

        if ($hasDuration) {
            array_splice($columns, 3, 0, ['visit_duration_months', 'visit_expiry_date']);
            array_splice($values, 3, 0, [$visit_duration_months, $hasExpiry ? $visit_expiry_date : null]);
        } elseif ($hasExpiry) {
            array_splice($columns, 3, 0, ['visit_expiry_date']);
            array_splice($values, 3, 0, [$visit_expiry_date]);
        }

        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare("INSERT INTO family_visit_requests (" . implode(',', $columns) . ") VALUES (" . $placeholders . ")");
        
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception("خطأ في التحقق من الطلب (CSRF).");
        }

        $stmt->execute($values);
        
        $request_id = $pdo->lastInsertId();

        // 3. Insert Individuals
        $total_sale = 0;
        $total_cost = 0;
        $seen_names = [];
        $seen_passports = [];

        $hasComingFromCity = !empty($pdo->query("SHOW COLUMNS FROM family_visit_individuals LIKE 'coming_from_city_id'")->fetchAll(PDO::FETCH_ASSOC));
        $hasReceivedDocs = !empty($pdo->query("SHOW COLUMNS FROM family_visit_individuals LIKE 'received_documents'")->fetchAll(PDO::FETCH_ASSOC));
        $hasAgentPrice = !empty($pdo->query("SHOW COLUMNS FROM family_visit_individuals LIKE 'agent_price'")->fetchAll(PDO::FETCH_ASSOC));
        $hasBranchPrice = !empty($pdo->query("SHOW COLUMNS FROM family_visit_individuals LIKE 'branch_price'")->fetchAll(PDO::FETCH_ASSOC));
        $hasSalePrice = !empty($pdo->query("SHOW COLUMNS FROM family_visit_individuals LIKE 'sale_price'")->fetchAll(PDO::FETCH_ASSOC));

        if (isset($_POST['ind_name'])) {
            foreach ($_POST['ind_name'] as $key => $name) {
                if (empty($name)) continue;

                $passport_no = $_POST['ind_passport'][$key];
                $name_trimmed = trim($name);
                $passport_trimmed = trim($passport_no);
                
                if (in_array($name_trimmed, $seen_names)) {
                    throw new Exception("الاسم \"$name_trimmed\" موجود مكرر في نفس الطلب.");
                }
                if (in_array($passport_trimmed, $seen_passports)) {
                    throw new Exception("رقم الجواز \"$passport_trimmed\" موجود مكرر في نفس الطلب.");
                }
                
                $seen_names[] = $name_trimmed;
                $seen_passports[] = $passport_trimmed;

                $rel_id = $_POST['ind_relationship'][$key];
                $gender = $_POST['ind_gender'][$key];
                $dob = $_POST['ind_dob'][$key] ?: null;
                $age = $_POST['ind_age'][$key] ?: null;
                $coming_from_city_id = isset($_POST['ind_coming_from_city_id'][$key]) && $_POST['ind_coming_from_city_id'][$key] !== '' ? (int)$_POST['ind_coming_from_city_id'][$key] : null;
                $received_documents = isset($_POST['ind_received_documents'][$key]) ? trim((string)$_POST['ind_received_documents'][$key]) : null;
                $purchase_raw = $_POST['ind_cost_amount'][$key] ?? $_POST['ind_purchase_price'][$key] ?? '';
                $sale_raw = $_POST['ind_line_total_amount'][$key] ?? $_POST['ind_sale_price'][$key] ?? '';

                $purchase_price = ($purchase_raw !== '' && $purchase_raw !== null)
                    ? (float) $purchase_raw
                    : (float) $pricing_data['purchase_price'];

                $sale_price = ($sale_raw !== '' && $sale_raw !== null)
                    ? (float) $sale_raw
                    : (float) $pricing_data['sale_price'];

                $agent_price = $owner_type === 'agent' ? $purchase_price : 0;
                $branch_price = $owner_type === 'branch' ? $purchase_price : 0;

                $columns = [
                    'request_id',
                    'full_name',
                    'passport_no',
                    'relationship_id',
                    'gender',
                    'birth_date',
                    'age',
                    'status_id'
                ];
                $values = [
                    $request_id,
                    $name,
                    $passport_no,
                    $rel_id,
                    $gender,
                    $dob,
                    $age,
                    1
                ];

                if ($hasAgentPrice) {
                    $columns[] = 'agent_price';
                    $values[] = $agent_price;
                }
                if ($hasBranchPrice) {
                    $columns[] = 'branch_price';
                    $values[] = $branch_price;
                }
                if ($hasSalePrice) {
                    $columns[] = 'sale_price';
                    $values[] = $sale_price;
                }
                if ($hasComingFromCity) {
                    $columns[] = 'coming_from_city_id';
                    $values[] = $coming_from_city_id;
                }
                if ($hasReceivedDocs) {
                    $columns[] = 'received_documents';
                    $values[] = $received_documents;
                }

                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $sql = "INSERT INTO family_visit_individuals (" . implode(',', $columns) . ") VALUES (" . $placeholders . ")";
                $stmt_ind = $pdo->prepare($sql);
                $stmt_ind->execute($values);

                $total_sale += $sale_price;
                $total_cost += $purchase_price;
            }
        }

        // استخدام المحرك المالي الموحد
        try {
            require_once '../includes/ServiceFinancialEngine.php';
            $finalSaleTotal = $total_sale;
            $finalCostTotal = $total_cost;
            $postedSaleTotal = isset($_POST['total_amount']) ? (float)$_POST['total_amount'] : $finalSaleTotal;
            $postedCostTotal = isset($_POST['cost_amount']) ? (float)$_POST['cost_amount'] : $finalCostTotal;

            if (abs($postedSaleTotal - $finalSaleTotal) > 0.01) {
                $postedSaleTotal = $finalSaleTotal;
            }
            if (abs($postedCostTotal - $finalCostTotal) > 0.01) {
                $postedCostTotal = $finalCostTotal;
            }

            $financialEngine = new ServiceFinancialEngine($pdo, $_SESSION['admin_id']);
            $financeResults = $financialEngine->processServiceFinance([
                'service_type'    => 'FamilyVisit',
                'source_id'       => $request_id,
                'source_number'   => $_POST['document_no'],
                'branch_id'       => $branch_id,
                'customer_id'     => !empty($_POST['customer_id']) ? $_POST['customer_id'] : null,
                'agent_id'        => $agent_id,
                'supplier_id'     => $_POST['supplier_id'] ?? null,
                'sale_total_amount' => $postedSaleTotal,
                'discount'        => $_POST['discount'] ?? 0,
                'purchase_total_amount' => $postedCostTotal,
                'sale_currency_id'=> $_POST['currency_id'] ?? $pricing_data['currency_id'],
                'pur_currency_id' => $_POST['currency_id'] ?? $pricing_data['currency_id'],
                'exchange_rate'   => 1,
                'amount_received' => $_POST['amount_received'] ?? 0,
                'payment_account_id' => $_POST['account_id'] ?? null,
                'delivery_type'   => $_POST['delivery_type'] ?? ($_POST['payment_type'] ?? ($settings['default_delivery_type'] ?? 'draft')),
                'description'     => $_POST['description'] ?? "طلب زيارة عائلية للمسافر: " . $_POST['owner_name'] . " - رقم المستند " . $_POST['document_no'],
                'operation_date'  => normalize_datetime_db($_POST['invoice_date'] ?? ($_POST['operation_date'] ?? null))
            ]);

            // ربط الطلب بفاتورة البيع والشراء
            $pdo->prepare("
                UPDATE family_visit_requests 
                SET sales_invoice_id = ?, purchase_invoice_id = ?, auto_invoice_generated = 1 
                WHERE id = ?
            ")->execute([
                $financeResults['sales_invoice_id'], 
                $financeResults['purchase_invoice_id'] ?? null, 
                $request_id
            ]);

        } catch (Exception $e) {
            error_log("Error in financial posting for family visit: " . $e->getMessage());
        }

        $pdo->commit();
        $_SESSION['success'] = "تم إضافة المعاملة بنجاح" . ($auto_post ? " وتم الترحيل المالي" : "");
        header("Location: family_visit.php");
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "خطأ أثناء الحفظ: " . $e->getMessage();
        header("Location: family_visit.php");
        exit();
    }
}
?>

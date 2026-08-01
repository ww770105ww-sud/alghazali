<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/ServiceFinancialEngine.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'غير مصرح']);
    exit();
}

function normalize_nullable_id($value): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_string($value)) {
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'null' || strtolower($value) === 'undefined') {
            return null;
        }
    }

    if (!is_numeric($value)) {
        return null;
    }

    $intValue = (int)$value;
    return $intValue > 0 ? $intValue : null;
}

function assert_reference_exists(PDO $pdo, string $table, string $label, ?int $id, string $extraWhere = ''): ?int
{
    if ($id === null) {
        return null;
    }

    $sql = "SELECT COUNT(*) FROM {$table} WHERE id = ?";
    if ($extraWhere !== '') {
        $sql .= " AND {$extraWhere}";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);

    if ((int)$stmt->fetchColumn() <= 0) {
        throw new Exception("القيمة المختارة في حقل {$label} غير موجودة أو لم تعد صالحة. يرجى إعادة اختيارها.");
    }

    return $id;
}

function get_request_csrf_token(): string
{
    $token = $_POST['csrf_token'] ?? '';
    if ($token !== '') return (string)$token;
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($hdr !== '') return (string)$hdr;
    $hdr2 = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return (string)$hdr2;
}

function check_host_capacity(PDO $pdo, int $host_id, ?int $exclude_passport_id = null): bool
{
    $settings = getSettings($pdo);
    $max = (int)($settings['umrah_default_max_muatamers'] ?? 5);
    
    $count_query = "SELECT COUNT(*) FROM passports WHERE host_id = ? AND transaction_type = 'umrah' AND deleted_at IS NULL";
    $params = [$host_id];
    
    if ($exclude_passport_id !== null) {
        $count_query .= " AND id != ?";
        $params[] = $exclude_passport_id;
    }
    
    $stmt_count = $pdo->prepare($count_query);
    $stmt_count->execute($params);
    $current = (int)$stmt_count->fetchColumn();
    
    return $current < $max;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$action = trim((string)($method === 'POST' ? ($_POST['action'] ?? ($_REQUEST['action'] ?? '')) : ($_GET['action'] ?? ($_REQUEST['action'] ?? ''))));
$requestId = (int)($_REQUEST['id'] ?? 0);

// اجعل طلب التفاصيل أكثر مرونة حتى لا يسقط إلى "طلب غير صالح" عند غياب action من المتصفح/الكاش.
if ($method === 'GET' && $action === '' && $requestId > 0) {
    $action = 'view_details';
}

if ($method === 'GET') {
    if ($action === 'view_details') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'طلب غير صالح';
            exit();
        }

        $stmt = $pdo->prepare("
            SELECT
                p.*,
                st.status_name,
                st.status_color,
                br.branch_name,
                ag.agent_name,
                sup.supplier_name,
                h.host_name,
                g.guarantor_name,
                s.service_name,
                curr_sale.currency_name AS sale_currency_name,
                curr_cost.currency_name AS cost_currency_name,
                inv.id AS sales_invoice_id,
                inv.invoice_number AS sales_invoice_number,
                inv.total_amount AS sales_amount,
                inv.discount AS sales_discount,
                inv.invoice_status AS sales_status,
                inv.invoice_date AS sales_invoice_date,
                (
                    IFNULL((
                        SELECT SUM(jl.debit)
                        FROM journal_lines jl
                        JOIN financial_transactions ft_i ON jl.financial_transaction_id = ft_i.id
                        WHERE ft_i.reference_id = inv.id AND ft_i.reference_type = 'invoice' AND ft_i.status = 'posted'
                        AND jl.account_id IN (
                            SELECT id FROM unified_accounts
                            WHERE account_type IN ('box', 'bank')
                        )
                    ), 0) +
                    IFNULL((
                        SELECT SUM(pa.allocated_amount)
                        FROM payment_allocations pa
                        JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
                        WHERE pa.invoice_id = inv.id AND ft.status = 'posted'
                        AND ft.id NOT IN (
                            SELECT id FROM financial_transactions
                            WHERE reference_id = inv.id AND reference_type = 'invoice'
                        )
                    ), 0)
                ) AS sales_received,
                pur.id AS purchase_invoice_id,
                pur.invoice_number AS purchase_invoice_number,
                pur.total_amount AS purchase_amount,
                pur.discount AS purchase_discount,
                pur.invoice_status AS purchase_status,
                pur.invoice_date AS purchase_invoice_date,
                (
                    IFNULL((
                        SELECT SUM(jl_p.credit)
                        FROM journal_lines jl_p
                        JOIN financial_transactions ft_ip ON jl_p.financial_transaction_id = ft_ip.id
                        WHERE ft_ip.reference_id = pur.id AND ft_ip.reference_type = 'invoice' AND ft_ip.status = 'posted'
                        AND jl_p.account_id IN (
                            SELECT id FROM unified_accounts
                            WHERE account_type IN ('box', 'bank')
                        )
                    ), 0) +
                    IFNULL((
                        SELECT SUM(pa_p.allocated_amount)
                        FROM payment_allocations pa_p
                        JOIN financial_transactions ft_p ON pa_p.financial_transaction_id = ft_p.id
                        WHERE pa_p.invoice_id = pur.id AND ft_p.status = 'posted'
                        AND ft_p.id NOT IN (
                            SELECT id FROM financial_transactions
                            WHERE reference_id = pur.id AND reference_type = 'invoice'
                        )
                    ), 0)
                ) AS purchase_paid
            FROM passports p
            LEFT JOIN statuses st ON p.status_id = st.id
            LEFT JOIN branches br ON p.branch_id = br.id
            LEFT JOIN agents ag ON p.agent_id = ag.id
            LEFT JOIN suppliers sup ON p.supplier_id = sup.id
            LEFT JOIN umrah_hosts h ON p.host_id = h.id
            LEFT JOIN umrah_guarantors g ON p.guarantor_id = g.id
            LEFT JOIN services s ON p.service_id = s.id
            LEFT JOIN invoices inv ON inv.id = p.sales_invoice_id
            LEFT JOIN currencies curr_sale ON inv.currency_id = curr_sale.id
            LEFT JOIN invoices pur ON pur.id = p.purchase_invoice_id
            LEFT JOIN currencies curr_cost ON pur.currency_id = curr_cost.id
            WHERE p.id = ? AND p.transaction_type = 'umrah' AND p.deleted_at IS NULL
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$p) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'المعاملة غير موجودة';
            exit();
        }

        header('Content-Type: text/html; charset=utf-8');

        $fullName = trim((string)($p['full_name'] ?? ''));
        $passportNumber = trim((string)($p['passport_number'] ?? ''));
        $visaNumber = trim((string)($p['visa_number'] ?? ''));
        $statusName = trim((string)($p['status_name'] ?? '---'));
        $statusColor = trim((string)($p['status_color'] ?? '#6c757d'));
        $salesNet = (float)($p['sales_amount'] ?? 0) - (float)($p['sales_discount'] ?? 0);
        $salesReceived = (float)($p['sales_received'] ?? 0);
        $salesRemaining = max(0, $salesNet - $salesReceived);
        $purchaseNet = (float)($p['purchase_amount'] ?? 0) - (float)($p['purchase_discount'] ?? 0);
        $purchasePaid = (float)($p['purchase_paid'] ?? 0);
        $purchaseRemaining = max(0, $purchaseNet - $purchasePaid);

        // ============================================================
        // BEGIN: بيانات سير العمل للتبويب الثالث
        // ============================================================
        $umrahWf = [
            'workflow' => null, 'currentStep' => null, 'currentStepId' => 0,
            'allSteps' => [], 'transitions' => [], 'history' => [],
            'checklist' => [], 'groupMembers' => [], 'allWorkflowSteps' => [],
            'canEditWf' => false, 'canViewHistory' => false,
            'isAdmin' => false
        ];

        $userId = (int)($_SESSION['admin_id'] ?? 0);
        $userRole = $_SESSION['role'] ?? '';
        $userRoleId = (int)($_SESSION['role_id'] ?? 0);
        $userRoleLower = mb_strtolower((string)$userRole, 'UTF-8');
        $umrahWf['isAdmin'] = in_array($userRoleLower, ['admin','developer','مدير','مبرمج','مطور'], true)
                              || ($userId > 0 && has_permission('umrah_edit_workflow'));
        $umrahWf['canEditWf'] = $umrahWf['isAdmin']
                                || ($userId > 0 && (has_permission('umrah_edit_workflow') || has_permission('request_document_confirmation')));
        $umrahWf['canViewHistory'] = $umrahWf['isAdmin']
                                     || ($userId > 0 && (has_permission('umrah_view_history')
                                                      || has_permission('umrah_view_workflow')
                                                      || has_permission('request_document_confirmation')));

        // جلب workflow_id للمعاملة (إن لم يكن موجود نستخدم سير العمل الافتراضي للعمرة)
        $workflowId = (int)($p['workflow_id'] ?? 0);
        if ($workflowId <= 0) {
            $stmtWfDef = $pdo->prepare("SELECT id, default_status_id FROM workflows WHERE transaction_type IN ('umrah','all') ORDER BY transaction_type='umrah' DESC, id ASC LIMIT 1");
            $stmtWfDef->execute();
            $wfDef = $stmtWfDef->fetch(PDO::FETCH_ASSOC);
            if ($wfDef) $workflowId = (int)$wfDef['id'];
        }

        if ($workflowId > 0) {
            // تفاصيل سير العمل
            $stmtWf = $pdo->prepare("SELECT * FROM workflows WHERE id = ?");
            $stmtWf->execute([$workflowId]);
            $umrahWf['workflow'] = $stmtWf->fetch(PDO::FETCH_ASSOC) ?: null;

            // جميع المراحل في سير العمل مرتبة
            $stmtAllSteps = $pdo->prepare("SELECT ws.*, s.status_name, s.status_color
                                           FROM workflow_steps ws
                                           LEFT JOIN statuses s ON s.id = ws.status_id
                                           WHERE ws.workflow_id = ?
                                           ORDER BY ws.sort_order ASC, ws.id ASC");
            $stmtAllSteps->execute([$workflowId]);
            $umrahWf['allSteps'] = $stmtAllSteps->fetchAll(PDO::FETCH_ASSOC);
            $umrahWf['allWorkflowSteps'] = $umrahWf['allSteps']; // لاستخدامه في القائمة المنسدلة للتعديل اليدوي

            // تحديد المرحلة الحالية (أولاً بالارتباط المباشر workflow_step_id، ثم مطابقة status_id)
            $currentStepId = 0;
            if (!empty($p['workflow_step_id'])) {
                $currentStepId = (int)$p['workflow_step_id'];
            }
            if ($currentStepId <= 0) {
                $curStatus = (int)($p['status_id'] ?? 0);
                foreach ($umrahWf['allSteps'] as $stp) {
                    if ((int)$stp['status_id'] === $curStatus) {
                        $currentStepId = (int)$stp['id'];
                        break;
                    }
                }
            }
            // تحديث العمود workflow_step_id إذا لم يكن موجوداً (للأرشفة)
            if ($currentStepId > 0 && empty($p['workflow_step_id'])) {
                try {
                    $pdo->prepare("UPDATE passports SET workflow_step_id = ? WHERE id = ?")->execute([$currentStepId, $id]);
                } catch (Throwable $e) { /* ignore */ }
            }
            $umrahWf['currentStepId'] = $currentStepId;
            foreach ($umrahWf['allSteps'] as $stp) {
                if ((int)$stp['id'] === $currentStepId) { $umrahWf['currentStep'] = $stp; break; }
            }

            // الانتقالات المسموح بها من المرحلة الحالية
            if ($currentStepId > 0) {
                $umrahWf['transitions'] = get_allowed_transitions($workflowId, $currentStepId, $userRoleId, $userId);
            }

            // سجل تغييرات الحالة (audit/history)
            try {
                $stmtHist = $pdo->prepare("
                    SELECT tsl.old_status_id, tsl.new_status_id, tsl.changed_at,
                           tsl.changed_by, tsl.changed_role_id, tsl.notes, tsl.updated_fields,
                           u.full_name AS changer_name, r.name AS role_name,
                           s_old.status_name AS old_name, s_new.status_name AS new_name
                    FROM transaction_status_logs tsl
                    LEFT JOIN users u ON u.id = tsl.changed_by
                    LEFT JOIN roles r ON r.id = tsl.changed_role_id
                    LEFT JOIN statuses s_old ON s_old.id = tsl.old_status_id
                    LEFT JOIN statuses s_new ON s_new.id = tsl.new_status_id
                    WHERE tsl.transaction_id = ?
                    ORDER BY tsl.changed_at DESC, tsl.id DESC
                    LIMIT 50
                ");
                $stmtHist->execute([$id]);
                $umrahWf['history'] = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $umrahWf['history'] = []; }

            // أفراد المجموعة (إن كانت هذه المعاملة أب)
            try {
                $stmtGrp = $pdo->prepare("SELECT id, full_name, passport_number, status_id
                                          FROM passports WHERE parent_id = ? AND deleted_at IS NULL ORDER BY id ASC");
                $stmtGrp->execute([$id]);
                $umrahWf['groupMembers'] = $stmtGrp->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $umrahWf['groupMembers'] = []; }

            // قائمة التحقق للمرحلة الحالية (document_requirements للعمرة) + الحالة السابقة المحفوظة
            $checklistItems = [];
            try {
                $stmtDocs = $pdo->prepare("SELECT * FROM document_requirements
                                            WHERE is_active = 1 AND (transaction_type = 'umrah' OR transaction_type = 'all' OR transaction_type IS NULL)
                                            ORDER BY sort_order ASC, id ASC");
                $stmtDocs->execute();
                $checklistItems = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $checklistItems = []; }

            // جلب ما تم حفظه سابقاً في workflow_checklist لهذه المعاملة
            $savedChecks = [];
            try {
                $stmtSC = $pdo->prepare("SELECT requirement_id, verified, verified_at, verified_by
                                         FROM workflow_checklist WHERE passport_id = ?");
                $stmtSC->execute([$id]);
                foreach ($stmtSC->fetchAll(PDO::FETCH_ASSOC) as $sv) {
                    $savedChecks[(int)$sv['requirement_id']] = $sv;
                }
            } catch (Throwable $e) { $savedChecks = []; }
            foreach ($checklistItems as &$chk) {
                $reqId = (int)$chk['id'];
                if (isset($savedChecks[$reqId])) {
                    $chk['is_completed'] = (int)($savedChecks[$reqId]['verified'] ?? 0);
                    $chk['verified_at'] = $savedChecks[$reqId]['verified_at'] ?? null;
                    $chk['verified_by'] = $savedChecks[$reqId]['verified_by'] ?? null;
                } else {
                    $chk['is_completed'] = 0;
                    $chk['verified_at'] = null;
                    $chk['verified_by'] = null;
                }
            }
            unset($chk);
            $umrahWf['checklist'] = $checklistItems;
        }

        // حقول المرحلة (show_fields + الحقول الديناميكية من workflow_step_fields)
        $umrahWf['currentStepFields'] = [];
        if ($umrahWf['currentStepId'] > 0) {
            try {
                $dynFlds = get_step_dynamic_fields($pdo, $umrahWf['currentStepId']);
                $legacyFields = get_step_fields($umrahWf['currentStepId']);
                // دمج show_fields مع بياناتها من workflow_fields
                $wfFieldsMap = [];
                try {
                    $wfFldsStmt = $pdo->query("SELECT field_key, field_label, field_type, is_required FROM workflow_fields WHERE is_active=1");
                    foreach ($wfFldsStmt->fetchAll(PDO::FETCH_ASSOC) as $fr) {
                        $wfFieldsMap[$fr['field_key']] = $fr;
                    }
                } catch (Throwable $e) {}
                foreach ($legacyFields as $fk) {
                    $fk = trim((string)$fk);
                    if ($fk === '') continue;
                    $meta = $wfFieldsMap[$fk] ?? null;
                    $umrahWf['currentStepFields'][$fk] = [
                        'key' => $fk,
                        'label' => $meta['field_label'] ?? $fk,
                        'type'  => $meta['field_type'] ?? 'text',
                        'is_required' => (bool)($meta['is_required'] ?? false)
                    ];
                }
                foreach ($dynFlds as $df) {
                    $dfk = trim((string)($df['field_key'] ?? ''));
                    if ($dfk === '') continue;
                    if (!isset($umrahWf['currentStepFields'][$dfk])) {
                        $umrahWf['currentStepFields'][$dfk] = [
                            'key' => $dfk,
                            'label' => $df['field_label'] ?? $dfk,
                            'type'  => $df['field_type'] ?? 'text',
                            'is_required' => (bool)($df['is_required'] ?? false)
                        ];
                    }
                }
            } catch (Throwable $e) { /* ignore */ }
        }

        // ============================================================
        // END: بيانات سير العمل للتبويب الثالث
        // ============================================================

        echo '<div class="p-3">';
        echo '<ul class="nav nav-tabs px-3 pt-3" role="tablist">';
        echo '  <li class="nav-item" role="presentation"><button class="nav-link active" id="info-tab" data-bs-toggle="tab" data-bs-target="#info" type="button" role="tab"><i class="fas fa-info-circle me-1"></i> البيانات</button></li>';
        echo '  <li class="nav-item" role="presentation"><button class="nav-link" id="workflow-tab" data-bs-toggle="tab" data-bs-target="#workflow" type="button" role="tab"><i class="fas fa-tasks me-1"></i> سير العمل';
        if (!empty($umrahWf['currentStep'])) {
            echo ' <span class="badge rounded-pill ms-1" style="background-color:'.h($umrahWf['currentStep']['color'] ?? '#6c757d').';color:#fff;">'.h($umrahWf['currentStep']['step_name'] ?? '').'</span>';
        }
        echo '</button></li>';
        echo '  <li class="nav-item" role="presentation"><button class="nav-link" id="financial-tab" data-bs-toggle="tab" data-bs-target="#financial" type="button" role="tab"><i class="fas fa-wallet me-1"></i> المالية</button></li>';
        echo '</ul>';

        echo '<div class="tab-content p-3">';

        echo '<div class="tab-pane fade show active" id="info" role="tabpanel">';
        echo '  <div class="row g-3">';
        
        // Header card
        echo '    <div class="col-12">';
        echo '      <div class="p-3 bg-primary bg-opacity-10 rounded-4 border border-primary border-opacity-25">';
        echo '        <div class="row g-3 align-items-center">';
        echo '          <div class="col-md-6">';
        echo '            <div class="fw-bold text-primary mb-1"><i class="fas fa-user-tie me-2"></i>' . h($fullName !== '' ? $fullName : '---') . '</div>';
        echo '            <div class="small text-muted">رقم الجواز: <span class="font-monospace">' . h($passportNumber !== '' ? $passportNumber : '---') . '</span></div>';
        echo '            <div><span class="badge rounded-pill px-3 py-2 shadow-sm" style="background-color:' . h($statusColor) . ';color:#fff;">' . h($statusName) . '</span></div>';
        echo '          </div>';
        echo '          <div class="col-md-6">';
        echo '            <div class="small text-muted">الفرع: <span class="fw-bold">' . h((string)($p['branch_name'] ?? '---')) . '</span></div>';
        echo '            <div class="small text-muted">الخدمة: <span class="fw-bold">' . h((string)($p['service_name'] ?? 'خدمات العمرة')) . '</span></div>';
        echo '            <div class="small text-muted">تاريخ الإنشاء: <span class="fw-bold">' . h((string)($p['created_at'] ?? '---')) . '</span></div>';
        echo '          </div>';
        echo '        </div>';
        echo '      </div>';
        echo '    </div>';

        // Passport info
        echo '    <div class="col-md-6">';
        echo '      <div class="p-3 bg-light rounded-4">';
        echo '        <h6 class="fw-bold mb-3"><i class="fas fa-passport text-primary me-2"></i>بيانات الجواز</h6>';
        echo '        <div class="row g-2">';
        echo '          <div class="col-6"><span class="text-muted small">الاسم (EN):</span> <span class="fw-bold">' . h((string)($p['full_name_en'] ?? '---')) . '</span></div>';
        echo '          <div class="col-6"><span class="text-muted small">الجنسية:</span> <span class="fw-bold">' . h((string)($p['nationality'] ?? '---')) . '</span></div>';
        echo '          <div class="col-6"><span class="text-muted small">الجنس:</span> <span class="fw-bold">' . h((string)($p['gender'] ?? '---')) . '</span></div>';
        echo '          <div class="col-6"><span class="text-muted small">تاريخ الميلاد:</span> <span class="fw-bold">' . h((string)($p['date_of_birth'] ?? '---')) . '</span></div>';
        echo '          <div class="col-6"><span class="text-muted small">تاريخ إصدار الجواز:</span> <span class="fw-bold">' . h((string)($p['passport_issue_date'] ?? '---')) . '</span></div>';
        echo '          <div class="col-6"><span class="text-muted small">انتهاء الجواز:</span> <span class="fw-bold text-danger">' . h((string)($p['passport_expiry_date'] ?? '---')) . '</span></div>';
        echo '          <div class="col-6"><span class="text-muted small">رقم الجوال:</span> <span class="fw-bold">' . h((string)($p['phone_number'] ?? '---')) . '</span></div>';
        echo '          <div class="col-6"><span class="text-muted small">خارج المملكة:</span> <span class="fw-bold">' . ((int)($p['is_outside_ksa'] ?? 0) === 1 ? 'نعم' : 'لا') . '</span></div>';
        echo '        </div>';
        echo '      </div>';
        echo '    </div>';

        // Visa info
        echo '    <div class="col-md-6">';
        echo '      <div class="p-3 bg-light rounded-4">';
        echo '        <h6 class="fw-bold mb-3"><i class="fas fa-file-invoice text-info me-2"></i>بيانات التأشيرة</h6>';
        echo '        <div class="row g-2">';
        echo '          <div class="col-12"><span class="text-muted small">رقم التأشيرة:</span> <span class="fw-bold font-monospace">' . h($visaNumber !== '' ? $visaNumber : '---') . '</span></div>';
        echo '          <div class="col-6"><span class="text-muted small">تاريخ إصدار التأشيرة:</span> <span class="fw-bold">' . h((string)($p['visa_issue_date'] ?? '---')) . '</span></div>';
        echo '          <div class="col-6"><span class="text-muted small">انتهاء التأشيرة:</span> <span class="fw-bold text-danger">' . h((string)($p['visa_expiry_date'] ?? '---')) . '</span></div>';
        echo '        </div>';
        echo '      </div>';
        echo '    </div>';

        // Host, Guarantor, Supplier
        echo '    <div class="col-12">';
        echo '      <div class="p-3 bg-light rounded-4">';
        echo '        <h6 class="fw-bold mb-3"><i class="fas fa-users text-warning me-2"></i>المستضيف والضامن والمورد</h6>';
        echo '        <div class="row g-3">';
        echo '          <div class="col-md-4">';
        echo '            <span class="text-muted small">المستضيف:</span> <div class="fw-bold">' . h((string)($p['host_name'] ?? '---')) . '</div>';
        echo '          </div>';
        echo '          <div class="col-md-4">';
        echo '            <span class="text-muted small">الضامن:</span> <div class="fw-bold">' . h((string)($p['guarantor_name'] ?? '---')) . '</div>';
        echo '          </div>';
        echo '          <div class="col-md-4">';
        echo '            <span class="text-muted small">المورد:</span> <div class="fw-bold">' . h((string)($p['supplier_name'] ?? '---')) . '</div>';
        echo '          </div>';
        echo '        </div>';
        echo '      </div>';
        echo '    </div>';

        // Notes
        if (!empty($p['notes']) || !empty($p['description'])) {
            echo '    <div class="col-12">';
            echo '      <div class="p-3 bg-light rounded-4">';
            if (!empty($p['description'])) {
                echo '        <div class="mb-2"><span class="text-muted small fw-bold">الوصف:</span> <div>' . h((string)$p['description']) . '</div></div>';
            }
            if (!empty($p['notes'])) {
                echo '        <div><span class="text-muted small fw-bold">ملاحظات:</span> <div>' . h((string)$p['notes']) . '</div></div>';
            }
            echo '      </div>';
            echo '    </div>';
        }

        echo '  </div>';
        echo '</div>';

        echo '<div class="tab-pane fade" id="financial" role="tabpanel">';
        echo '  <div class="row g-3">';
        
        // Financial details
        echo '    <div class="col-12">';
        echo '      <div class="p-3 bg-light rounded-4 border border-primary border-opacity-25">';
        echo '        <h6 class="fw-bold mb-3"><i class="fas fa-calculator text-success me-2"></i>تفاصيل الأسعار</h6>';
        echo '        <div class="row g-3">';
        echo '          <div class="col-md-3">';
        echo '            <span class="text-muted small">سعر البيع (' . h((string)($p['sale_currency_name'] ?? '')) . '):</span>';
        echo '            <div class="fw-bold text-primary">' . number_format((float)($p['total_amount'] ?? 0), 2) . '</div>';
        echo '          </div>';
        echo '          <div class="col-md-3">';
        echo '            <span class="text-muted small">الخصم:</span>';
        echo '            <div class="fw-bold text-danger">' . number_format((float)($p['discount'] ?? 0), 2) . '</div>';
        echo '          </div>';
        echo '          <div class="col-md-3">';
        echo '            <span class="text-muted small">سعر التكلفة (' . h((string)($p['cost_currency_name'] ?? '')) . '):</span>';
        echo '            <div class="fw-bold text-warning">' . number_format((float)($p['cost_amount'] ?? 0), 2) . '</div>';
        echo '          </div>';
        echo '          <div class="col-md-3">';
        echo '            <span class="text-muted small">المبلغ المقبوض:</span>';
        echo '            <div class="fw-bold text-info">' . number_format((float)($p['amount_received'] ?? 0), 2) . '</div>';
        echo '          </div>';
        echo '        </div>';
        echo '      </div>';
        echo '    </div>';

        echo '    <div class="col-lg-6">';
        echo '      <div class="p-3 border rounded-4 bg-white">';
        echo '        <div class="fw-bold mb-2">فاتورة البيع</div>';
        if (!empty($p['sales_invoice_id'])) {
            echo '        <div class="small text-muted mb-2"><a href="invoice_details.php?id=' . (int)$p['sales_invoice_id'] . '" target="_blank">' . h((string)($p['sales_invoice_number'] ?? '')) . '</a></div>';
            echo '        <div class="small text-muted mb-1">تاريخ الفاتورة: ' . h((string)($p['sales_invoice_date'] ?? '---')) . '</div>';
        } else {
            echo '        <div class="small text-muted mb-2">لا توجد</div>';
        }
        echo '        <div class="d-flex justify-content-between small"><span>الإجمالي</span><span class="fw-bold text-success">' . number_format($salesNet, 2) . ' ' . h((string)($p['sale_currency_name'] ?? '')) . '</span></div>';
        echo '        <div class="d-flex justify-content-between small"><span>المحصل</span><span class="fw-bold text-primary">' . number_format($salesReceived, 2) . ' ' . h((string)($p['sale_currency_name'] ?? '')) . '</span></div>';
        echo '        <div class="d-flex justify-content-between small"><span>المتبقي</span><span class="fw-bold text-danger">' . number_format($salesRemaining, 2) . ' ' . h((string)($p['sale_currency_name'] ?? '')) . '</span></div>';
        echo '      </div>';
        echo '    </div>';
        echo '    <div class="col-lg-6">';
        echo '      <div class="p-3 border rounded-4 bg-white">';
        echo '        <div class="fw-bold mb-2">فاتورة الشراء</div>';
        if (!empty($p['purchase_invoice_id'])) {
            echo '        <div class="small text-muted mb-2"><a href="invoice_details.php?id=' . (int)$p['purchase_invoice_id'] . '" target="_blank">' . h((string)($p['purchase_invoice_number'] ?? '')) . '</a></div>';
            echo '        <div class="small text-muted mb-1">تاريخ الفاتورة: ' . h((string)($p['purchase_invoice_date'] ?? '---')) . '</div>';
        } else {
            echo '        <div class="small text-muted mb-2">لا توجد</div>';
        }
        echo '        <div class="d-flex justify-content-between small"><span>الإجمالي</span><span class="fw-bold text-danger">' . number_format($purchaseNet, 2) . ' ' . h((string)($p['cost_currency_name'] ?? '')) . '</span></div>';
        echo '        <div class="d-flex justify-content-between small"><span>المدفوع</span><span class="fw-bold text-primary">' . number_format($purchasePaid, 2) . ' ' . h((string)($p['cost_currency_name'] ?? '')) . '</span></div>';
        echo '        <div class="d-flex justify-content-between small"><span>المتبقي</span><span class="fw-bold text-danger">' . number_format($purchaseRemaining, 2) . ' ' . h((string)($p['cost_currency_name'] ?? '')) . '</span></div>';
        echo '      </div>';
        echo '    </div>';
        echo '  </div>';
        echo '</div>';

        // ============================================================
        // تبويب سير العمل (WorkFlow Tab)
        // ============================================================
        echo '<div class="tab-pane fade" id="workflow" role="tabpanel">';
        echo '  <div id="umrahWfRoot" class="p-3 bg-light bg-opacity-25 rounded-4">';

        // بطاقة رأس الصفحة: اسم سير العمل + المرحلة الحالية
        $cur = $umrahWf['currentStep'];
        $curName = $cur['step_name'] ?? ($statusName ?: 'غير محدد');
        $curColor = $cur['color'] ?? $statusColor ?? '#6c757d';
        $curIsFinal = (int)($cur['is_final'] ?? 0) === 1;
        $curIsInitial = (int)($cur['is_initial'] ?? 0) === 1;
        $workflowName = $umrahWf['workflow']['name'] ?? 'سير العمل الافتراضي للعمرة';

        echo '    <div class="row g-3 mb-3">';
        echo '      <div class="col-12">';
        echo '        <div class="card border-0 shadow-sm rounded-4 h-100" style="border-right:5px solid '.h($curColor).';">';
        echo '          <div class="card-body p-4">';
        echo '            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">';
        echo '              <div>';
        echo '                <div class="small text-muted mb-1"><i class="fas fa-diagram-project me-1"></i> سير العمل: <span class="fw-bold text-dark">'.h($workflowName).'</span></div>';
        echo '                <div class="d-flex align-items-center gap-3 mt-1">';
        echo '                  <div class="h5 mb-0 fw-bold text-dark"><i class="fas fa-location-dot me-2" style="color:'.h($curColor).';"></i>المرحلة الحالية: ';
        echo '                    <span class="badge rounded-pill px-4 py-2 shadow-sm fs-6 text-white" style="background-color:'.h($curColor).';">'.h($curName).'</span>';
        echo '                  </div>';
        if ($curIsInitial) echo '<span class="badge bg-info rounded-pill px-3 py-2 extra-small"><i class="fas fa-play me-1"></i> نقطة البداية</span>';
        if ($curIsFinal)   echo '<span class="badge bg-success rounded-pill px-3 py-2 extra-small"><i class="fas fa-check-double me-1"></i> مرحلة نهائية</span>';
        if (!empty($cur['require_note']))  echo '<span class="badge bg-warning-subtle text-warning border border-warning rounded-pill px-3 py-2 extra-small"><i class="fas fa-sticky-note me-1"></i> ملاحظة إجبارية</span>';
        if (!empty($cur['require_reason'])) echo '<span class="badge bg-danger-subtle text-danger border border-danger rounded-pill px-3 py-2 extra-small"><i class="fas fa-circle-exclamation me-1"></i> سبب إجباري</span>';
        echo '                </div>';
        echo '              </div>';

        // قائمة التعديل اليدوي (للمدراء فقط)
        if ($umrahWf['isAdmin'] && !empty($umrahWf['allWorkflowSteps'])) {
            echo '<div class="dropdown">';
            echo '  <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" type="button" data-bs-toggle="dropdown" aria-expanded="false">';
            echo '    <i class="fas fa-pen-to-square me-1"></i> تعديل السير (يدوياً)';
            echo '  </button>';
            echo '  <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 small p-2">';
            echo '    <li><h6 class="dropdown-header text-muted extra-small border-bottom pb-1 mb-1">اختر المرحلة الجديدة:</h6></li>';
            foreach ($umrahWf['allWorkflowSteps'] as $st) {
                $active = (int)$st['id'] === (int)$umrahWf['currentStepId'];
                echo '<li><a class="dropdown-item rounded-3 py-2 d-flex align-items-center justify-content-between '.($active?'active bg-primary-subtle':'').'" href="javascript:void(0)"';
                echo '   onclick="UmrahWorkflow.manualTransition('.(int)$id.', '.(int)$st['id'].', \''.h($st['step_name'] ?? '').'\');">';
                echo '<span class="d-flex align-items-center gap-2"><span class="rounded-circle d-inline-block" style="width:10px;height:10px;background-color:'.h($st['color'] ?? '#6c757d').';"></span>'.h($st['step_name'] ?? '').'</span>';
                if ($active) echo '<i class="fas fa-check-circle ms-2 text-primary"></i>';
                echo '</a></li>';
            }
            echo '  </ul>';
            echo '</div>';
        }
        echo '            </div>';

        // أسماء الخصائص للمرحلة
        if (!empty($cur)) {
            $meta = [];
            if (!empty($cur['show_checklist'])) $meta[] = '<i class="fas fa-list-check me-1"></i> عرض قائمة التحقق';
            if (!empty($cur['is_editable']))   $meta[] = '<i class="fas fa-pen me-1"></i> الحقول قابلة للتعديل';
            if (!empty($umrahWf['currentStepFields'])) $meta[] = '<i class="fas fa-table-list me-1"></i> <span class="fw-bold">'.count($umrahWf['currentStepFields']).'</span> حقل/حقول ظاهرة للمرحلة';
            if (!empty($meta)) echo '<div class="mt-3 pt-3 border-top"><div class="small text-muted">'.implode(' &nbsp; • &nbsp; ', $meta).'</div></div>';
        }
        echo '          </div>';
        echo '        </div>';
        echo '      </div>';
        echo '    </div>';

        // مسار سير العمل (Timeline للمراحل كلها)
        if (!empty($umrahWf['allSteps'])) {
            $stepsTotal = count($umrahWf['allSteps']);
            $stepIndex = 0;
            $foundCurrentIdx = -1;
            foreach ($umrahWf['allSteps'] as $i => $s) if ((int)$s['id'] === (int)$umrahWf['currentStepId']) { $foundCurrentIdx = $i; break; }
            echo '  <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">';
            echo '    <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-route me-2"></i> مسار سير العمل</h6>';
            echo '    <div class="position-relative">';
            // خط أفقي واحد كبير أسفل الدوائر
            echo '      <div class="position-absolute top-0 start-0 end-0" style="height:4px;top:22px;background:#e9ecef;border-radius:3px;margin-inline:4%;"></div>';
            // نسبة إكمال الخط الملون
            if ($foundCurrentIdx >= 0 && $stepsTotal > 1) {
                $pct = round(($foundCurrentIdx / ($stepsTotal - 1)) * 100);
                echo '      <div class="position-absolute top-0 start-0" style="width:'.$pct.'%;left:4%;height:4px;top:22px;background:linear-gradient(90deg,#3b82f6,'.h($curColor).');border-radius:3px;"></div>';
            }
            echo '      <div class="row g-0" style="position:relative;z-index:2;">';
            foreach ($umrahWf['allSteps'] as $i => $stp) {
                $sid = (int)$stp['id'];
                $isCurrent = $sid === (int)$umrahWf['currentStepId'];
                $isDone = ($foundCurrentIdx >= 0 && $i < $foundCurrentIdx);
                $sc = $stp['color'] ?? '#6c757d';
                echo '      <div class="col text-center" style="flex:1;">';
                echo '        <div class="d-flex justify-content-center mb-2">';
                echo '          <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white shadow-sm '
                     .($isCurrent?'animate__animated animate__pulse animate__infinite':'').'"'
                     .' style="width:48px;height:48px;'
                     .($isCurrent ? 'background-color:'.h($sc).';outline:4px solid rgba('.($sc==='#ffffff'?'255,255,255':'59,130,246').',0.25);'
                                  : ($isDone ? 'background-color:'.h($sc).';' : 'background-color:#e9ecef;color:#6c757d;'))
                     .'">'
                     .($isDone?'<i class="fas fa-check"></i>':($isCurrent?'<i class="fas fa-bolt"></i>':($i+1)))
                     .'</div>';
                echo '        </div>';
                echo '        <div class="extra-small fw-bold '
                     .($isCurrent ? 'text-dark':'').($isDone ? 'text-muted':'text-muted').'">'
                     .h($stp['step_name'] ?? '')
                     .'</div>';
                if (!empty($stp['is_initial'])) echo '<div class="extra-small text-info"><i class="fas fa-play"></i> البداية</div>';
                elseif (!empty($stp['is_final'])) echo '<div class="extra-small text-success"><i class="fas fa-flag-checkered"></i> النهاية</div>';
                echo '      </div>';
            }
            echo '      </div>';
            echo '    </div>';
            echo '  </div>';
        }

        // أعضاء المجموعة (إن وجدت)
        if (!empty($umrahWf['groupMembers'])) {
            $grpCnt = count($umrahWf['groupMembers']);
            echo '  <div class="card border-0 shadow-sm rounded-4 p-4 mb-4">';
            echo '    <h6 class="fw-bold mb-3 text-info"><i class="fas fa-users me-2"></i> أفراد المجموعة / العائلة <span class="badge rounded-pill bg-light text-dark border extra-small ms-2">'.$grpCnt.'</span></h6>';
            echo '    <div class="list-group list-group-flush">';
            echo '      <div class="row g-2">';
            foreach ($umrahWf['groupMembers'] as $m) {
                echo '      <div class="col-md-4">';
                echo '        <label class="list-group-item d-flex align-items-center justify-content-between py-2 px-3 border rounded-4 bg-white mb-2 cursor-pointer">';
                echo '          <div class="d-flex align-items-center gap-2">';
                echo '            <input class="form-check-input umrahWfMember" type="checkbox" value="'.(int)$m['id'].'" checked id="umrahWfM_'.(int)$m['id'].'">';
                echo '            <div>';
                echo '              <div class="fw-bold small">'.h($m['full_name'] ?? '').'</div>';
                echo '              <div class="extra-small text-muted">'.h($m['passport_number'] ?? '').'</div>';
                echo '            </div>';
                echo '          </div>';
                echo '          <a href="javascript:void(0)" onclick="UmrahWorkflow.jumpTo('.(int)$m['id'].')" class="extra-small text-primary"><i class="fas fa-arrow-up-right-from-square me-1"></i> فتح</a>';
                echo '        </label>';
                echo '      </div>';
            }
            echo '      </div>';
            echo '    </div>';
            echo '    <div class="mt-2 text-end">';
            echo '      <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill extra-small px-3 me-1" onclick="UmrahWorkflow.toggleMembers(true);">تحديد الكل</button>';
            echo '      <button type="button" class="btn btn-xs btn-outline-secondary rounded-pill extra-small px-3" onclick="UmrahWorkflow.toggleMembers(false);">إلغاء الكل</button>';
            echo '      <div class="extra-small text-muted mt-1 text-start">ملاحظة: في حالة اختيار أفراد المجموعة، سيتم تطبيق الانتقال على المعاملة الرئيسية والأفراد المحددين معاً.</div>';
            echo '    </div>';
            echo '  </div>';
        }

        // العمودان الرئيسيان: اليسار = قائمة التحقق + حقول المرحلة | اليمين = الانتقالات + السجل
        echo '  <div class="row g-3">';
        echo '    <div class="col-md-6">';

        // قائمة التحقق
        echo '      <div class="card border-0 shadow-sm rounded-4 p-4 mb-3">';
        echo '        <h6 class="fw-bold mb-3 text-warning"><i class="fas fa-list-check me-2"></i> قائمة التحقق (Checklist)</h6>';
        if (!empty($umrahWf['checklist'])) {
            echo '        <div class="list-group list-group-flush" id="umrahWfChecklistBox">';
            foreach ($umrahWf['checklist'] as $ch) {
                $chId = (int)$ch['id'];
                $chName = $ch['requirement_name'] ?? '';
                $chType = $ch['requirement_type'] ?? 'document';
                $icons = ['document'=>'fa-file-image','check'=>'fa-square-check','payment'=>'fa-sack-dollar','approval'=>'fa-user-check'];
                $icon = $icons[$chType] ?? 'fa-circle-check';
                $done = (int)($ch['is_completed'] ?? 0) === 1;
                $vAt = $ch['verified_at'] ?? null;
                $vBy = $ch['verified_by'] ?? null;
                $vName = '';
                if ($vBy > 0) {
                    try {
                        $sName = $pdo->prepare("SELECT full_name FROM users WHERE id=?");$sName->execute([$vBy]);
                        $vName = (string)$sName->fetchColumn();
                    } catch (Throwable $e) {}
                }
                echo '        <div class="list-group-item d-flex align-items-center justify-content-between py-3 border-0 px-0 mb-2 rounded-3 '.($done?'bg-success-subtle':'bg-light').'">';
                echo '          <div class="ps-3 pe-2 flex-grow-1">';
                echo '            <div class="d-flex align-items-center gap-2 small fw-bold text-dark"><i class="fas '.$icon.' text-warning"></i>'.h($chName).'</div>';
                if ($vAt && $done) {
                    echo '<div class="extra-small text-success mt-1"><i class="fas fa-check-double me-1"></i> تم التحقق منه في '.h($vAt).($vName?' بواسطة '.h($vName):'').'</div>';
                } elseif ($vBy === null && $done) {
                    echo '<div class="extra-small text-info mt-1"><i class="fas fa-check me-1"></i> تم وضعه مكتملاً - ينتظر تأكيد الإدارة</div>';
                } else {
                    echo '<div class="extra-small text-muted mt-1"><i class="far fa-clock me-1"></i> لم يتم التحقق منه بعد</div>';
                }
                echo '          </div>';
                echo '          <div class="form-check form-switch pe-3 ps-0">';
                echo '            <input class="form-check-input umrahWfChkItem" type="checkbox" role="switch" '
                     .'data-req-id="'.$chId.'" data-req-name="'.h($chName).'" '
                     .($done?'checked ':'').($vAt?'disabled':'').'>';
                echo '          </div>';
                echo '        </div>';
            }
            echo '        </div>';
            if ($umrahWf['canEditWf']) {
                echo '        <div class="mt-3 text-end"><button type="button" class="btn btn-sm btn-outline-warning rounded-pill px-4" onclick="UmrahWorkflow.saveChecklist('.(int)$id.');"><i class="fas fa-save me-1"></i> حفظ قائمة التحقق</button></div>';
            }
        } else {
            echo '        <div class="text-center py-4 text-muted small">لا توجد قائمة تحقق محددة لهذا النوع من المعاملات.</div>';
        }
        echo '      </div>';

        // الحقول الظاهرة لهذه المرحلة
        if (!empty($umrahWf['currentStepFields'])) {
            $stepEditAllowed = (int)($cur['is_editable'] ?? 1) === 1;
            echo '      <div class="card border-0 shadow-sm rounded-4 p-4 mb-3">';
            echo '        <h6 class="fw-bold mb-3 text-indigo" style="color:#6366f1;"><i class="fas fa-table-list me-2"></i> الحقول المرتبطة بالمرحلة الحالية</h6>';
            echo '        <form id="umrahWfStepFieldsForm" class="row g-3">';
            foreach ($umrahWf['currentStepFields'] as $fld) {
                $fkey = $fld['key']; $flabel = $fld['label']; $ftype = $fld['type']; $freq = !empty($fld['is_required']);
                // جلب القيمة الحالية من passports أو workflow_field_values
                $curVal = '';
                if (array_key_exists($fkey, $p) && $p[$fkey] !== null) $curVal = $p[$fkey];
                elseif (isset($p['id'])) {
                    try {
                        $fvq = $pdo->prepare("SELECT field_value FROM workflow_field_values WHERE passport_id=? AND field_key=? LIMIT 1");
                        $fvq->execute([$id, $fkey]);
                        $res = $fvq->fetchColumn();
                        if ($res !== false && $res !== null) $curVal = $res;
                    } catch (Throwable $e) {}
                }
                $disabled = $stepEditAllowed ? '' : 'disabled';
                echo '          <div class="col-md-6">';
                echo '            <label class="form-label small fw-bold mb-1">'.h($flabel).($freq?' <span class="text-danger">*</span>':'').' <span class="extra-small text-muted ms-1">('.h($fkey).')</span></label>';
                if ($ftype === 'textarea') {
                    echo '            <textarea class="form-control form-control-sm rounded-3 wf-field" data-field="'.h($fkey).'" rows="2" '.$disabled.' placeholder="'.h($flabel).'">'.h((string)$curVal).'</textarea>';
                } elseif ($ftype === 'date') {
                    echo '            <input type="date" class="form-control form-control-sm rounded-3 wf-field" data-field="'.h($fkey).'" '.($freq?'required':'').' '.$disabled.' value="'.date('Y-m-d', @strtotime($curVal)?:time()).'">';
                } elseif ($ftype === 'datetime') {
                    echo '            <input type="datetime-local" class="form-control form-control-sm rounded-3 wf-field" data-field="'.h($fkey).'" '.($freq?'required':'').' '.$disabled.' value="'.($curVal?date('Y-m-d\TH:i', @strtotime($curVal)?:'').'':'').'">';
                } elseif ($ftype === 'number') {
                    echo '            <input type="number" step="any" class="form-control form-control-sm rounded-3 wf-field" data-field="'.h($fkey).'" '.($freq?'required':'').' '.$disabled.' value="'.h((string)$curVal).'">';
                } elseif ($ftype === 'checkbox') {
                    echo '            <div class="form-check form-switch pt-1"><input class="form-check-input wf-field" type="checkbox" data-field="'.h($fkey).'" '.($curVal?'checked':'').' '.$disabled.'></div>';
                } else {
                    echo '            <input type="text" class="form-control form-control-sm rounded-3 wf-field" data-field="'.h($fkey).'" '.($freq?'required':'').' '.$disabled.' value="'.h((string)$curVal).'" placeholder="'.h($flabel).'">';
                }
                echo '          </div>';
            }
            if ($stepEditAllowed && $umrahWf['canEditWf']) {
                echo '          <div class="col-12 text-end pt-2 border-top mt-2">';
                echo '            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-4" onclick="UmrahWorkflow.saveStepFields('.(int)$id.', '.(int)$umrahWf['currentStepId'].');"><i class="fas fa-save me-1"></i> حفظ حقول المرحلة</button>';
                echo '          </div>';
            }
            echo '        </form>';
            echo '      </div>';
        }

        echo '    </div>'; // نهاية العمود الأيسر

        // العمود الأيمن: الانتقالات المتاحة + نموذج التنفيذ + السجل
        echo '    <div class="col-md-6">';

        // نموذج الانتقال (يظهر/يختفي حسب الحاجة)
        echo '      <div id="umrahWfTransitionBox" class="card border-0 shadow-sm rounded-4 p-4 mb-3 d-none border border-2 border-primary" style="background: linear-gradient(180deg,rgba(59,130,246,0.05),transparent 60%);">';
        echo '        <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-forward me-2"></i> تنفيذ الانتقال إلى: <span id="umrahWfTargetName" class="text-dark"></span></h6>';
        echo '        <div id="umrahWfTransitionFields" class="row g-2 mb-3"></div>';
        echo '        <div class="mb-3">';
        echo '          <label class="form-label small fw-bold mb-1">ملاحظات الانتقال <span id="umrahWfNotesRequired" class="text-danger d-none">*</span></label>';
        echo '          <textarea id="umrahWfNotes" class="form-control form-control-sm rounded-3" rows="2" placeholder="اكتب ملاحظاتك حول هذا الانتقال..."></textarea>';
        echo '        </div>';
        echo '        <div class="d-flex gap-2 flex-wrap">';
        echo '          <button type="button" class="btn btn-primary rounded-pill px-4 flex-grow-1" onclick="UmrahWorkflow.confirmTransition('.(int)$id.', '.(int)$umrahWf['currentStepId'].');"><i class="fas fa-check me-1"></i> تأكيد النقل</button>';
        echo '          <button type="button" class="btn btn-outline-secondary rounded-pill px-4" onclick="UmrahWorkflow.cancelTransition();"><i class="fas fa-times me-1"></i> إلغاء</button>';
        echo '        </div>';
        echo '      </div>';

        // الانتقالات المتاحة
        echo '      <div class="card border-0 shadow-sm rounded-4 p-4 mb-3">';
        echo '        <h6 class="fw-bold mb-3 text-success d-flex justify-content-between align-items-center">';
        echo '          <span><i class="fas fa-bolt me-2"></i> الإجراءات والانتقالات التالية</span>';
        echo '          <span class="badge rounded-pill bg-light text-dark border extra-small">'.count($umrahWf['transitions']).'</span>';
        echo '        </h6>';

        if (!$umrahWf['canEditWf']) {
            echo '<div class="alert alert-light small py-3 text-center mb-0">لا تملك صلاحية تعديل سير العمل لهذه المعاملة.</div>';
        } elseif (empty($umrahWf['transitions']) && empty($umrahWf['currentStep']) && !$curIsFinal) {
            echo '<div class="alert alert-info small py-3 text-center mb-0">لا توجد سير عمل مخصص لهذه المعاملة. يمكنك استخدام <b>"تعديل السير يدوياً"</b> لتحديد المرحلة الحالية.</div>';
        } elseif ($curIsFinal && empty($umrahWf['transitions'])) {
            echo '<div class="alert alert-success small py-3 text-center mb-0"><i class="fas fa-check-circle me-2"></i> انتهت المعاملة (لا توجد انتقالات تالية - هذه مرحلة نهائية).</div>';
        } elseif (empty($umrahWf['transitions'])) {
            echo '<div class="alert alert-light small py-3 text-center mb-0">لا توجد انتقالات متاحة من هذه المرحلة حالياً. تواصل مع مدير النظام أو استخدم التعديل اليدوي.</div>';
        } else {
            echo '        <div class="d-grid gap-2" id="umrahWfTransitionsBox">';
            foreach ($umrahWf['transitions'] as $tr) {
                $tid = (int)$tr['transition_id'];
                $toStepId = (int)$tr['to_step_id'];
                $toName = $tr['to_step_name'] ?? '';
                $toColor = $tr['color'] ?? '#6c757d';
                $reqNote = (int)($tr['require_note'] ?? 0);
                $reqReason = (int)($tr['require_reason'] ?? 0);
                $reqApproval = (int)($tr['require_approval'] ?? 0);
                echo '          <button type="button" class="btn btn-lg btn-primary rounded-pill text-start fw-bold shadow-sm umrahWfTrBtn" style="background-color:'.h($toColor).';border-color:'.h($toColor).';"';
                echo '             onclick="UmrahWorkflow.prepareTransition('.$toStepId.', '.h($toName).', '.$reqNote.', '.$reqReason.', '.$reqApproval.', '.$tid.');">';
                echo '            <span class="d-flex align-items-center justify-content-between gap-3">';
                echo '              <span class="d-flex align-items-center gap-2"><i class="fas fa-arrow-right-to-bracket me-1"></i> تنفيذ: '.h($toName).'</span>';
                $badges = [];
                if ($reqNote)     $badges[] = '<span class="badge bg-warning-subtle text-warning border border-warning rounded-pill px-2 py-1 extra-small"><i class="fas fa-note-sticky"></i> ملاحظة</span>';
                if ($reqReason)   $badges[] = '<span class="badge bg-danger-subtle text-danger border border-danger rounded-pill px-2 py-1 extra-small"><i class="fas fa-circle-exclamation"></i> سبب</span>';
                if ($reqApproval) $badges[] = '<span class="badge bg-info-subtle text-info border border-info rounded-pill px-2 py-1 extra-small"><i class="fas fa-user-shield"></i> يتطلب اعتماد</span>';
                echo '              <span class="d-flex gap-1">'.implode(' ', $badges).'</span>';
                echo '            </span>';
                echo '          </button>';
            }
            echo '        </div>';
        }
        echo '      </div>';

        // سجل الحركات
        if ($umrahWf['canViewHistory']) {
            $histCount = count($umrahWf['history']);
            echo '      <div class="card border-0 shadow-sm rounded-4 p-4">';
            echo '        <h6 class="fw-bold mb-3 text-dark d-flex justify-content-between align-items-center border-bottom pb-2">';
            echo '          <span><i class="fas fa-clock-rotate-left me-2 text-info"></i> سجل الحركات</span>';
            echo '          <span class="badge rounded-pill bg-light text-dark border extra-small">'.$histCount.'</span>';
            echo '        </h6>';
            if ($histCount > 0) {
                echo '      <div style="max-height:260px;overflow-y:auto;" id="umrahWfTimeline">';
                $idx = 0;
                foreach ($umrahWf['history'] as $h) {
                    $idx++;
                    $oldN = $h['old_name'] ?? '—';
                    $newN = $h['new_name'] ?? '';
                    $changer = $h['changer_name'] ?? '---';
                    $rname = $h['role_name'] ?? null;
                    $at = $h['changed_at'] ?? '';
                    $notes = $h['notes'] ?? '';
                    $updated = $h['updated_fields'] ?? null;
                    $dots = [
                        1 => 'background:#3b82f6;', 2 => 'background:#10b981;', 3 => 'background:#f59e0b;',
                        4 => 'background:#8b5cf6;', 5 => 'background:#ef4444;', 6 => 'background:#06b6d4;'
                    ];
                    $dot = $dots[(($idx-1) % 6) + 1] ?? 'background:#6b7280;';
                    echo '        <div class="mb-3 ps-4 position-relative">';
                    echo '          <div class="position-absolute rounded-circle" style="left:0;top:6px;width:12px;height:12px;'.$dot.'border:2px solid #fff;box-shadow:0 0 0 2px #e5e7eb;"></div>';
                    echo '          <div class="fw-bold small text-dark mb-1">'.h($newN?:$oldN).'</div>';
                    if ($oldN && $newN && $oldN !== $newN) {
                        echo '          <div class="extra-small text-muted mb-1"><span class="text-decoration-line-through">'.h($oldN).'</span> <i class="fas fa-arrow-right mx-1 text-info"></i> <span class="fw-bold">'.h($newN).'</span></div>';
                    }
                    echo '          <div class="extra-small text-muted d-flex flex-wrap gap-2 mb-1">';
                    echo '            <span><i class="far fa-calendar me-1"></i>'.h($at).'</span>';
                    echo '            <span><i class="fas fa-user me-1"></i>'.h($changer).($rname?' <span class="text-muted">( '.h($rname).' )</span>':'').'</span>';
                    echo '          </div>';
                    if ($notes !== '' && $notes !== null) {
                        echo '          <div class="extra-small bg-light rounded-3 p-2 mt-1 text-dark">💬 '.h((string)$notes).'</div>';
                    }
                    if (!empty($updated)) {
                        $data = @json_decode($updated, true);
                        if (is_array($data) && !empty($data)) {
                            $parts = [];
                            foreach ($data as $kk=>$vv) {
                                if ($vv === null || $vv === '') continue;
                                if (is_array($vv)) $vv = json_encode($vv, JSON_UNESCAPED_UNICODE);
                                $parts[] = '<span class="badge bg-info-subtle text-info rounded-pill px-2 py-0 extra-small me-1 mb-1 d-inline-block">'.h($kk).': <b>'.h(mb_substr((string)$vv,0,25)).'</b></span>';
                            }
                            if (!empty($parts)) echo '          <div class="mt-1">'.implode('',$parts).'</div>';
                        }
                    }
                    echo '        </div>';
                }
                echo '      </div>';
            } else {
                echo '<div class="text-center py-4 text-muted small">لا توجد حركات مسجلة لهذه المعاملة حتى الآن.</div>';
            }
            echo '      </div>';
        }

        echo '    </div>'; // نهاية العمود الأيمن
        echo '  </div>'; // نهاية السطر
        echo '  </div>'; // نهاية #umrahWfRoot

        // CSRF token لسكريبتات الجافاسكربت المضمنة
        $csrfVal = (string)(function_exists('get_csrf_token') ? get_csrf_token() : ($_SESSION['csrf_token'] ?? ''));
        echo '  <script type="module" id="umrahWfInlineScript">';
        echo '(function(){';
        echo '  if (!window.UmrahWorkflow) { window.UmrahWorkflow = {}; }';
        echo '  var UW = window.UmrahWorkflow;';
        echo '  UW.CSRF = '.json_encode($csrfVal, JSON_UNESCAPED_UNICODE).';';
        echo '  UW.pending = { toStepId: 0, toStepName: "", requireNote: false, requireReason: false, requireApproval: false, transitionId: 0 };';
        echo '  UW.prepareTransition = function(toStepId, toStepName, reqNote, reqReason, reqApproval, transId) {';
        echo '    UW.pending = { toStepId: toStepId, toStepName: toStepName, requireNote: !!reqNote, requireReason: !!reqReason, requireApproval: !!reqApproval, transitionId: transId };';
        echo '    var box = document.getElementById("umrahWfTransitionBox");';
        echo '    var nameEl = document.getElementById("umrahWfTargetName");';
        echo '    var notesReq = document.getElementById("umrahWfNotesRequired");';
        echo '    if (box) box.classList.remove("d-none");';
        echo '    if (nameEl) nameEl.textContent = toStepName;';
        echo '    if (notesReq) notesReq.classList.toggle("d-none", !(reqNote || reqReason));';
        echo '    box && box.scrollIntoView({ behavior: "smooth", block: "nearest" });';
        // هنا نستدعي حقول المرحلة التالية ديناميكياً إن وجدت (عبر get_step_fields endpoint)
        echo '    var dyn = document.getElementById("umrahWfTransitionFields");';
        echo '    if (dyn) { dyn.innerHTML = \'<div class="col-12 small text-muted"><i class="fas fa-spinner fa-spin me-1"></i> جاري تحميل حقول المرحلة التالية...</div>\'; }';
        echo '    fetch("ajax_umrah.php?action=get_step_fields&step_id="+toStepId).then(function(r){return r.json();}).then(function(data){';
        echo '      if (!dyn) return; dyn.innerHTML = "";';
        echo '      var fields = (data && data.fields)?data.fields:[];';
        echo '      var legacy = (data && data.legacy_fields)?data.legacy_fields:[];';
        echo '      var all = fields.concat(legacy.filter(function(x){return x && typeof x === "string" && !fields.some(function(f){return f.key === x;});}).map(function(l){return {key:l,label:l,type:"text",is_required:false};}));';
        echo '      if (all.length === 0) { dyn.innerHTML = \'<div class="col-12 extra-small text-success"><i class="fas fa-circle-info me-1"></i> لا توجد حقول إضافية مطلوبة لهذه المرحلة (يمكنك إدخالها في حقول المرحلة بعد الانتهاء من الانتقال).</div>\'; return; }';
        echo '      all.forEach(function(f){';
        echo '        var wrap = document.createElement("div"); wrap.className = "col-md-6";';
        echo '        var lab = document.createElement("label"); lab.className = "form-label small fw-bold mb-1"; lab.textContent = f.label + (f.is_required?" *":"") + " ("+f.key+")";';
        echo '        var inp;';
        echo '        if (f.type === "textarea") { inp = document.createElement("textarea"); inp.rows = 2; inp.placeholder = f.label; inp.className = "form-control form-control-sm rounded-3 wf-tr-field"; }';
        echo '        else if (f.type === "date") { inp = document.createElement("input"); inp.type = "date"; inp.className = "form-control form-control-sm rounded-3 wf-tr-field"; }';
        echo '        else if (f.type === "datetime") { inp = document.createElement("input"); inp.type = "datetime-local"; inp.className = "form-control form-control-sm rounded-3 wf-tr-field"; }';
        echo '        else if (f.type === "number") { inp = document.createElement("input"); inp.type = "number"; inp.step = "any"; inp.className = "form-control form-control-sm rounded-3 wf-tr-field"; }';
        echo '        else if (f.type === "checkbox") { var sw = document.createElement("div"); sw.className = "form-check form-switch pt-1"; inp = document.createElement("input"); inp.type = "checkbox"; inp.className = "form-check-input wf-tr-field"; sw.appendChild(inp); }';
        echo '        else { inp = document.createElement("input"); inp.type = "text"; inp.placeholder = f.label; inp.className = "form-control form-control-sm rounded-3 wf-tr-field"; }';
        echo '        if (inp) { inp.setAttribute("data-field", f.key); if (f.is_required) inp.required = true; inp.setAttribute("autocomplete","off"); }';
        echo '        wrap.appendChild(lab); if (sw) wrap.appendChild(sw); else wrap.appendChild(inp);';
        echo '        dyn.appendChild(wrap);';
        echo '      });';
        echo '    }).catch(function(e){ if(dyn) dyn.innerHTML = \'<div class="col-12 text-danger small">⚠️ تعذر تحميل حقول المرحلة التالية: \'+e.message+\'</div>\'; });';
        echo '  };';
        echo '  UW.cancelTransition = function() {';
        echo '    var box = document.getElementById("umrahWfTransitionBox"); if (box) box.classList.add("d-none");';
        echo '    UW.pending = { toStepId: 0, toStepName: "", requireNote: false, requireReason: false, requireApproval: false, transitionId: 0 };';
        echo '    var notes = document.getElementById("umrahWfNotes"); if (notes) notes.value = "";';
        echo '  };';
        echo '  UW.collectStepFields = function(scopeBox) {';
        echo '    var scope = scopeBox?document.getElementById(scopeBox):document;';
        echo '    var out = {}; var list = scope && scope.querySelectorAll ? scope.querySelectorAll(".wf-field, .wf-tr-field") : [];';
        echo '    list.forEach(function(el){';
        echo '      var k = el.getAttribute("data-field"); if (!k) return;';
        echo '      if (el.type && el.type.toLowerCase() === "checkbox") out[k] = el.checked ? 1 : 0;';
        echo '      else out[k] = (el.value === null || el.value === undefined) ? "" : el.value;';
        echo '    });';
        echo '    return out;';
        echo '  };';
        echo '  UW.getSelectedPassportIds = function(mainId) {';
        echo '    var ids = [mainId];';
        echo '    document.querySelectorAll(".umrahWfMember:checked").forEach(function(cb){ var i = parseInt(cb.value||"0",10); if (i>0 && ids.indexOf(i)<0) ids.push(i); });';
        echo '    return ids;';
        echo '  };';
        echo '  UW.confirmTransition = function(mainId, currentStepId) {';
        echo '    if (!UW.pending.toStepId) { Swal.fire("تنبيه", "لم يتم اختيار الانتقال بعد", "warning"); return; }';
        echo '    if (UW.pending.requireNote || UW.pending.requireReason) {';
        echo '      var notesEl = document.getElementById("umrahWfNotes");';
        echo '      if (!notesEl || (notesEl.value || "").trim() === "") { Swal.fire("مطلوب", "يرجى كتابة سبب/ملاحظات لهذا الانتقال لأنه مطلوب في خصائص المرحلة", "warning"); return; }';
        echo '    }';
        echo '    var ids = UW.getSelectedPassportIds(mainId);';
        echo '    var extra = UW.collectStepFields("umrahWfTransitionBox");';
        echo '    var notesVal = (document.getElementById("umrahWfNotes") || {}).value || "";';
        echo '    Swal.fire({ title: "تأكيد الانتقال", html: \'سيتم نقل \'+ids.length+\' معاملة/معاملات إلى مرحلة: <b>\'+UW.pending.toStepName+\'</b><br>هل أنت متأكد؟\', icon: "question", showCancelButton: true, confirmButtonText: "نعم، انقل الآن", cancelButtonText: "إلغاء", confirmButtonColor: "#0ea5e9", cancelButtonColor: "#64748b" }).then(function(ok){ if (!ok || !ok.isConfirmed) return; UW._doSubmit(ids, UW.pending.toStepId, notesVal, extra); });';
        echo '  };';
        echo '  UW.saveStepFields = function(mainId, curStepId) {';
        echo '    var extra = UW.collectStepFields("umrahWfRoot");';
        echo '    UW._doSubmit([mainId], curStepId, (" حفظ حقول مرحلة من واجهة سير العمل "), extra, true);';
        echo '  };';
        echo '  UW.saveChecklist = function(mainId) {';
        echo '    var items = [];';
        echo '    document.querySelectorAll(".umrahWfChkItem").forEach(function(ch){';
        echo '      items.push({ id: parseInt(ch.getAttribute("data-req-id")||"0",10), requirement_name: ch.getAttribute("data-req-name")||"", verified: ch.checked?1:0 });';
        echo '    });';
        echo '    var ids = UW.getSelectedPassportIds(mainId);';
        echo '    var form = new FormData(); form.append("action", "process_umrah_transition"); form.append("csrf_token", UW.CSRF); form.append("to_step_id", parseInt('.(int)$umrahWf['currentStepId'].', 10)||0);';
        echo '    ids.forEach(function(i){ form.append("passport_id[]", i); });';
        echo '    items.forEach(function(it, idx){ form.append("checklist["+idx+"][id]", it.id); form.append("checklist["+idx+"][requirement_name]", it.requirement_name); form.append("checklist["+idx+"][verified]", it.verified); });';
        echo '    fetch("ajax_umrah.php", { method: "POST", body: form }).then(function(r){ return r.json(); }).then(function(res){';
        echo '      if (res.status === "success") { Swal.fire("تم الحفظ", res.message, "success").then(function(){ location.reload(); }); } else { Swal.fire("خطأ", res.message || "حدث خطأ أثناء الحفظ", "error"); }';
        echo '    }).catch(function(e){ Swal.fire("خطأ", e.message, "error"); });';
        echo '  };';
        echo '  UW.toggleMembers = function(checked) { document.querySelectorAll(".umrahWfMember").forEach(function(c){ c.checked = !!checked; }); };';
        echo '  UW.jumpTo = function(id) { window.parent && window.parent.location && (window.parent.location.href = "umrah.php?id="+id) || (location.search = "?id="+id); };';
        echo '  UW._doSubmit = function(passportIds, toStepId, notes, extra, keepCurrentStageOnFail) {';
        echo '    var form = new FormData();';
        echo '    form.append("action", "process_umrah_transition"); form.append("csrf_token", UW.CSRF); form.append("to_step_id", parseInt(toStepId,10)||0); form.append("notes", notes || "");';
        echo '    (passportIds || []).forEach(function(i){ form.append("passport_id[]", parseInt(i,10)); });';
        echo '    if (extra) { Object.keys(extra).forEach(function(k){ form.append("extra_data["+k+"]", extra[k]); }); }';
        echo '    Swal.fire({ title: "جاري التنفيذ", html: "<div class=\'spinner-border text-info\' role=\'status\'></div><div class=\'mt-2 small text-muted\'>يرجى الانتظار...</div>", showConfirmButton: false, allowOutsideClick: false, didOpen: function(){ Swal.showLoading(); } });';
        echo '    fetch("ajax_umrah.php", { method: "POST", body: form }).then(function(r){ return r.json(); }).then(function(res){';
        echo '      Swal.close();';
        echo '      if (res && res.status === "success") { Swal.fire("تم بنجاح", res.message, "success").then(function(){ location.reload(); }); }';
        echo '      else { Swal.fire("حدث خطأ", (res && res.message) || "حدث خطأ غير متوقع", "error"); }';
        echo '    }).catch(function(e){ Swal.close(); Swal.fire("خطأ في الشبكة", e.message, "error"); });';
        echo '  };';
        echo '  UW.manualTransition = function(id, newStep, stepName) {';
        echo '    Swal.fire({ title: "تغيير المرحلة يدوياً", html: \'هل أنت متأكد من نقل المعاملة إلى مرحلة: <b class="text-primary">\'+stepName+\'</b>؟<br><span class="small text-danger">سيتم تجاوز القواعد العادية!</span>\', icon: "warning", showCancelButton: true, confirmButtonText: "نعم، انقل الآن", cancelButtonText: "إلغاء", confirmButtonColor: "#ef4444", cancelButtonColor: "#64748b" }).then(function(ok){ if (!ok || !ok.isConfirmed) return; UW._doSubmit([id], newStep, "تغيير يدوي لمرحلة سير العمل بواسطة الإدارة", {}, false); });';
        echo '  };';
        echo '})();';
        echo '  </script>';
        echo '</div>'; // إغلاق تبويب سير العمل tab-pane

        echo '</div>';
        echo '</div>';
        exit();
    }

    if ($action === 'get_umrah_for_edit') {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'طلب غير صالح';
            exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM passports WHERE id = ? AND transaction_type = 'umrah' AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'المعاملة غير موجودة';
            exit();
        }

        // Get sales invoice data if exists
        $sales_invoice = null;
        if (!empty($p['sales_invoice_id'])) {
            $stmt_sales = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
            $stmt_sales->execute([$p['sales_invoice_id']]);
            $sales_invoice = $stmt_sales->fetch(PDO::FETCH_ASSOC);
        }

        // Get purchase invoice data if exists
        $purchase_invoice = null;
        if (!empty($p['purchase_invoice_id'])) {
            $stmt_purchase = $pdo->prepare("SELECT * FROM invoices WHERE id = ? LIMIT 1");
            $stmt_purchase->execute([$p['purchase_invoice_id']]);
            $purchase_invoice = $stmt_purchase->fetch(PDO::FETCH_ASSOC);
        }

        // Get all required data, just like umrah.php
        $settings = getSettings($pdo);
        $umrah_service_name = 'خدمات العمرة';
        
        // Get hosts and guarantors
        $stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt_user->execute([$_SESSION['admin_id']]);
        $currentUser = $stmt_user->fetch();
        
        // Get hosts
        $host_filter = get_entity_filter('h', 'branch_id', 'agent_id', null, null);
        $saved_hosts_stmt = $pdo->prepare("SELECT * FROM umrah_hosts h WHERE {$host_filter['clause']} ORDER BY host_name ASC");
        $saved_hosts_stmt->execute($host_filter['params']);
        $saved_hosts = $saved_hosts_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get guarantors
        $guarantor_filter = get_entity_filter('g', 'branch_id', 'agent_id', null, null);
        $saved_guarantors_stmt = $pdo->prepare("SELECT * FROM umrah_guarantors g WHERE {$guarantor_filter['clause']} ORDER BY guarantor_name ASC");
        $saved_guarantors_stmt->execute($guarantor_filter['params']);
        $saved_guarantors = $saved_guarantors_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get currencies, services, branches, agents
        $currencies = $pdo->query("SELECT * FROM currencies ORDER BY is_default DESC, currency_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $services = $pdo->query("SELECT * FROM services WHERE status = 'active' ORDER BY service_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $branches = $pdo->query("SELECT * FROM branches WHERE deleted_at IS NULL ORDER BY branch_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        $agents = $pdo->query("SELECT * FROM agents WHERE deleted_at IS NULL ORDER BY agent_name ASC")->fetchAll(PDO::FETCH_ASSOC);
        
        // Set current_invoice for financial_fields.php, using invoice data if available
        $current_invoice = [
            'invoice_date' => $p['operation_date'] ?? date('Y-m-d'),
            'branch_id' => $p['branch_id'] ?? null,
            'service_id' => $p['service_id'] ?? null,
            'customer_id' => $p['customer_id'] ?? null,
            'agent_id' => $p['agent_id'] ?? null,
            'supplier_id' => $p['supplier_id'] ?? null,
            'host_id' => $p['host_id'] ?? null,
            'guarantor_id' => $p['guarantor_id'] ?? null,
            'total_amount' => $sales_invoice['total_amount'] ?? 0,
            'discount' => $sales_invoice['discount'] ?? 0,
            'cost_amount' => $purchase_invoice['cost_amount'] ?? 0,
            'amount_received' => $sales_invoice['amount_received'] ?? 0,
            'currency_id' => $sales_invoice['currency_id'] ?? 1,
            'sale_currency_id' => $sales_invoice['currency_id'] ?? 1,
            'main_currency_id' => $purchase_invoice['currency_id'] ?? 1,
            'description' => $p['description'] ?? '',
            'notes' => $p['notes'] ?? '',
            'exchange_rate' => $sales_invoice['exchange_rate'] ?? 1.0,
            'invoice_number' => $sales_invoice['invoice_number'] ?? null,
            'invoice_status' => $sales_invoice['invoice_status'] ?? 'draft'
        ];
        
        // Set variables for financial_fields.php
        $financial_fields_prefix = 'edit_';
        $financial_fields_select2_parent = '#editUmrahModal';
        $financial_fields_show_service_select = false;
        $financial_fields_header_layout = 'split_rows';
        $financial_fields_hide_service_accounts = true;
        $financial_fields_show_host_guarantor = true;
        $financial_fields_hosts = $saved_hosts;
        $financial_fields_guarantors = $saved_guarantors;
        $financial_fields_show_supplier = has_permission('umrah_show_supplier');
        $financial_fields_show_cost_currency = has_permission('umrah_show_cost_currency');
        $financial_fields_show_cost_amount = has_permission('umrah_show_cost_amount');
        $financial_fields_show_discount = has_permission('umrah_show_discount');
        $financial_fields_show_notes_field = true;
        
        // Set selected values
        $financial_fields_selected_host = $p['host_id'] ?? null;
        $financial_fields_selected_guarantor = $p['guarantor_id'] ?? null;

        header('Content-Type: text/html; charset=utf-8');
        ?>
        <!-- إشعار نوع الخدمة -->
        <div class="alert alert-custom-info py-2 px-3 mb-2 d-flex align-items-center">
            <i class="fas fa-info-circle fa-lg me-2"></i>
            <div>
                <h6 class="fw-bold mb-0" style="font-size: 0.8rem;">نوع الخدمة: <?php echo htmlspecialchars($umrah_service_name); ?></h6>
            </div>
        </div>

        <input type="hidden" name="customer_id" id="edit_customer_id_hidden" value="<?php echo htmlspecialchars($p['customer_id'] ?? ''); ?>">
        <input type="hidden" name="agent_id" id="edit_agent_id_hidden" value="<?php echo htmlspecialchars($p['agent_id'] ?? ''); ?>">

        <!-- Passport Information Section -->
        <div class="form-section-card mb-3">
            <div class="form-section-header">
                <i class="fas fa-passport text-primary"></i>
                <h6>معلومات الجواز</h6>
            </div>
            <div class="form-section-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <button type="button" id="edit_scan_passport_btn" class="btn btn-outline-info w-100 mb-2">
                            <i class="fas fa-camera me-2"></i>مسح الجواز
                        </button>
                    </div>
                </div>
                <div class="row g-3 mt-2">
                    <div class="col-md-4">
                        <label class="form-label">رقم الجواز</label>
                        <input type="text" name="passport_number" id="edit_ocr_passport" class="form-control font-monospace" required value="<?php echo htmlspecialchars($p['passport_number'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">رقم الجوال</label>
                        <input type="text" name="phone_number" id="edit_ocr_phone" class="form-control" value="<?php echo htmlspecialchars($p['phone_number'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">الاسم (عربي)</label>
                        <input type="text" name="full_name" id="edit_ocr_name" class="form-control" required value="<?php echo htmlspecialchars($p['full_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">الاسم (EN)</label>
                        <input type="text" name="full_name_en" id="edit_ocr_name_en" class="form-control font-monospace" value="<?php echo htmlspecialchars($p['full_name_en'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">الجنس</label>
                        <select name="gender" id="edit_ocr_gender" class="form-select">
                            <option value="">اختر...</option>
                            <option value="Male" <?php echo ($p['gender'] ?? '') === 'Male' ? ' selected' : ''; ?>>ذكر</option>
                            <option value="Female" <?php echo ($p['gender'] ?? '') === 'Female' ? ' selected' : ''; ?>>أنثى</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">الجنسية</label>
                        <select name="nationality" id="edit_ocr_nationality" class="form-select">
                            <option value="">اختر...</option>
                            <?php
                            $nationalities = [
                                'سعودية', 'مصرية', 'إماراتية', 'قطرية', 'كويتية', 'بحرينية', 'عمانية', 'لبنانية', 'سورية', 'الأردنية',
                                'العراقية', 'اليمنية', 'السودانية', 'الليبية', 'التونسية', 'الجزائرية', 'المغربية', 'الموريتانية',
                                'البنجلاديشية', 'الباكستانية', 'الهندية', 'السيريلانكية', 'الفيلبينية', 'الإندونيسية', 'الماليزية',
                                'الأمريكية', 'البريطانية', 'الكندية', 'الأسترالية', 'الأوروبية', 'أخرى'
                            ];
                            foreach ($nationalities as $n) {
                                $selected = ($p['nationality'] ?? '') === $n ? ' selected' : '';
                                echo '<option value="' . htmlspecialchars($n) . '"' . $selected . '>' . htmlspecialchars($n) . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">تاريخ الميلاد</label>
                        <input type="date" name="date_of_birth" id="edit_ocr_dob" class="form-control" value="<?php echo htmlspecialchars($p['date_of_birth'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">تاريخ إصدار الجواز</label>
                        <input type="date" name="passport_issue_date" id="edit_ocr_issue_date" class="form-control" value="<?php echo htmlspecialchars($p['passport_issue_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">انتهاء الجواز</label>
                        <input type="date" name="passport_expiry_date" id="edit_ocr_expiry" class="form-control" value="<?php echo htmlspecialchars($p['passport_expiry_date'] ?? ''); ?>">
                        <div id="edit_passport_expiry_error" class="text-danger small mt-1"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Visa Information Section -->
        <div class="form-section-card mb-3">
            <div class="form-section-header">
                <i class="fas fa-ticket-alt text-success"></i>
                <h6>معلومات التأشيرة</h6>
            </div>
            <div class="form-section-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">رقم التأشيرة</label>
                        <input type="text" name="visa_number" class="form-control" value="<?php echo htmlspecialchars($p['visa_number'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">تاريخ إصدار التأشيرة</label>
                        <input type="date" name="visa_issue_date" class="form-control" value="<?php echo htmlspecialchars($p['visa_issue_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">تاريخ انتهاء التأشيرة</label>
                        <input type="date" name="visa_expiry_date" class="form-control" value="<?php echo htmlspecialchars($p['visa_expiry_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_outside_ksa" id="is_outside_ksa_check" <?php echo ($p['is_outside_ksa'] ?? 0) ? ' checked' : ''; ?>>
                            <label class="form-check-label fw-bold" for="is_outside_ksa_check">المعتمر خارج المملكة حالياً (إيقاف التنبيهات)</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Include Financial Fields -->
        <?php include '../includes/financial_fields.php'; ?>
        <?php
        exit();
    }

    if ($action === 'get_step_fields') {
        $step_id = (int)($_GET['step_id'] ?? 0);
        if ($step_id <= 0) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => 'Step ID is required']);
            exit();
        }
        
        // Get dynamic step fields from workflow_step_fields
        $dynamic_fields = get_step_dynamic_fields($pdo, $step_id);
        
        // Also get the show_fields from workflow_steps (legacy)
        $legacy_fields = get_step_fields($step_id);
        
        // Format for response
        $fields = [];
        foreach ($dynamic_fields as $f) {
            $fields[] = [
                'key' => $f['field_key'],
                'label' => $f['field_label'],
                'type' => $f['field_type'],
                'is_required' => (bool)$f['is_required'],
                'is_editable' => (bool)$f['is_editable']
            ];
        }
        
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'success', 'fields' => $fields, 'legacy_fields' => $legacy_fields]);
        exit();
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
    exit();
}

header('Content-Type: application/json; charset=utf-8');

if ($method === 'POST') {
    $csrf = get_request_csrf_token();
    if (!verify_csrf_token($csrf)) {
        echo json_encode(['success' => false, 'status' => 'error', 'message' => 'رمز الحماية غير صالح']);
        exit();
    }

    // Save Umrah Host
    if ($action === 'save_host') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $host_name = $_POST['host_name'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $address = $_POST['address'] ?? '';
            $national_address = $_POST['national_address'] ?? '';

            if (empty($host_name)) {
                echo json_encode(['status' => 'error', 'message' => 'اسم المستضيف مطلوب']);
                exit();
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE umrah_hosts SET host_name=?, phone=?, address=?, national_address=? WHERE id=?");
                $stmt->execute([$host_name, $phone, $address, $national_address, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO umrah_hosts (host_name, phone, address, national_address, agent_id, branch_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$host_name, $phone, $address, $national_address, $_SESSION['agent_id'] ?? null, $_SESSION['branch_id'] ?? null]);
            }

            echo json_encode(['status' => 'success', 'message' => 'تم الحفظ بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Delete Umrah Host
    if ($action === 'delete_host') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'معرف المستضيف مطلوب']);
                exit();
            }
            
            // Check number of mu'tamireen
            $check_count = $pdo->prepare("SELECT COUNT(*) as cnt FROM passports WHERE host_id = ? AND transaction_type = 'umrah' AND deleted_at IS NULL");
            $check_count->execute([$id]);
            $count_data = $check_count->fetch();
            
            if ($count_data['cnt'] > 0) {
                echo json_encode(['status' => 'error', 'message' => 'لا يمكن حذف المستضيف لأنه يحتوي على ' . $count_data['cnt'] . ' معتمر/معتمرين.']);
                exit();
            }
            
            $stmt = $pdo->prepare("DELETE FROM umrah_hosts WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'تم الحذف بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Save Umrah Guarantor
    if ($action === 'save_guarantor') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $guarantor_name = $_POST['guarantor_name'] ?? '';
            $identity_type = $_POST['identity_type'] ?? '';
            $identity_number = $_POST['identity_number'] ?? '';
            $phone = $_POST['phone'] ?? '';
            $guarantor_type = $_POST['guarantor_type'] ?? 'individual';
            $address = $_POST['address'] ?? '';

            if (empty($guarantor_name)) {
                echo json_encode(['status' => 'error', 'message' => 'اسم الضامن مطلوب']);
                exit();
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE umrah_guarantors SET guarantor_name=?, identity_type=?, identity_number=?, phone=?, guarantor_type=?, address=? WHERE id=?");
                $stmt->execute([$guarantor_name, $identity_type, $identity_number, $phone, $guarantor_type, $address, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO umrah_guarantors (guarantor_name, identity_type, identity_number, phone, guarantor_type, address) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$guarantor_name, $identity_type, $identity_number, $phone, $guarantor_type, $address]);
            }

            echo json_encode(['status' => 'success', 'message' => 'تم الحفظ بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Delete Umrah Guarantor
    if ($action === 'delete_guarantor') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'معرف الضامن مطلوب']);
                exit();
            }
            $stmt = $pdo->prepare("DELETE FROM umrah_guarantors WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'تم الحذف بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Add Umrah Transaction
    if ($action === 'add_umrah') {
        try {
            $pdo->beginTransaction();

            // Validate required fields
            $full_name = $_POST['full_name'] ?? '';
            $passport_number = $_POST['passport_number'] ?? '';
            if (empty($full_name) || empty($passport_number)) {
                throw new Exception('الاسم الكامل ورقم الجواز مطلوبين');
            }

            // Get settings
            $settings = getSettings($pdo);
            $umrah_service_name = 'خدمات العمرة';

            // Validate passport expiry
            $passport_expiry_date = $_POST['passport_expiry_date'] ?? '';
            if (!empty($passport_expiry_date)) {
                $min_validity_months = (int)($settings['min_passport_validity_months'] ?? 6);
                $expiry = new DateTime($passport_expiry_date);
                $now = new DateTime();
                $now->modify("+$min_validity_months months");
                if ($expiry < $now) {
                    throw new Exception("صلاحية الجواز لا يمكن أن تكون أقل من $min_validity_months أشهر.");
                }
            }

            // Get current user data
            $stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt_user->execute([$_SESSION['admin_id']]);
            $currentUser = $stmt_user->fetch(PDO::FETCH_ASSOC);

            // Get default status
            $default_status_id = $pdo->query("SELECT id FROM statuses WHERE status_name = 'معاملة جديدة' LIMIT 1")->fetchColumn();
            if (!$default_status_id) {
                $default_status_id = $pdo->query("SELECT id FROM statuses LIMIT 1")->fetchColumn();
            }

            $selected_branch_id = normalize_nullable_id($_POST['branch_id'] ?? ($_SESSION['branch_id'] ?? null));
            $selected_branch_id = assert_reference_exists($pdo, 'branches', 'الفرع', $selected_branch_id, "deleted_at IS NULL");

            // Extract customer_id and agent_id
            $customer_id = null;
            $agent_id = null;
            $account_id = normalize_nullable_id($_POST['account_id'] ?? null);
            $delivery_type = $_POST['delivery_type'] ?? $_POST['payment_type'] ?? 'cash';
            if ($delivery_type === 'credit') {
                $customer_id = normalize_nullable_id($_POST['customer_id'] ?? $_POST['customer_id_hidden'] ?? null);
            } elseif ($delivery_type === 'agent') {
                $agent_id = normalize_nullable_id($_POST['agent_id'] ?? $_POST['agent_id_hidden'] ?? null);
            }

            $customer_id = assert_reference_exists($pdo, 'customers', 'العميل', $customer_id, "deleted_at IS NULL");
            $agent_id = assert_reference_exists($pdo, 'agents', 'الوكيل', $agent_id, "deleted_at IS NULL");
            $supplier_id = normalize_nullable_id($_POST['supplier_id'] ?? null);
            $supplier_id = assert_reference_exists($pdo, 'suppliers', 'المورد', $supplier_id, "deleted_at IS NULL");
            $host_id = normalize_nullable_id($_POST['host_id'] ?? null);
            if ($host_id !== null) {
                $host_id = assert_reference_exists($pdo, 'umrah_hosts', 'المستضيف', $host_id);
                if (!check_host_capacity($pdo, $host_id, $action === 'update_traveler' ? $id : null)) {
                    throw new Exception('الحد الأقصى للمعتمرين لهذا المستضيف قد تم تجاوزه.');
                }
            }
            $guarantor_id = normalize_nullable_id($_POST['guarantor_id'] ?? null);
            $guarantor_id = assert_reference_exists($pdo, 'umrah_guarantors', 'الضامن', $guarantor_id);

            $record_purchase = (string)($_POST['record_purchase'] ?? '1');
            $purchase_price = (float)($_POST['cost_amount'] ?? 0);
            if ($record_purchase === '1' && $purchase_price > 0 && $supplier_id === null) {
                throw new Exception('يرجى اختيار المورد قبل إنشاء فاتورة الشراء.');
            }

            // Insert into passports
            $insert_passport = $pdo->prepare("
                INSERT INTO passports (
                    full_name, 
                    full_name_en, 
                    passport_number, 
                    passport_issue_date, 
                    passport_expiry_date, 
                    nationality, 
                    gender, 
                    date_of_birth, 
                    phone_number, 
                    operation_date, 
                    service_id, 
                    transaction_type, 
                    service_type, 
                    status_id, 
                    workflow_id, 
                    status_changed_by, 
                    created_by, 
                    branch_id, 
                    agent_id, 
                    customer_id, 
                    supplier_id, 
                    host_id, 
                    guarantor_id, 
                    is_outside_ksa, 
                    visa_number, 
                    visa_issue_date, 
                    visa_expiry_date, 
                    description, 
                    notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insert_passport->execute([
                $full_name,
                $_POST['full_name_en'] ?? '',
                $passport_number,
                $_POST['passport_issue_date'] ?? null,
                $_POST['passport_expiry_date'] ?? null,
                $_POST['nationality'] ?? '',
                $_POST['gender'] ?? null,
                $_POST['date_of_birth'] ?? null,
                $_POST['phone_number'] ?? '',
                normalize_datetime_db($_POST['invoice_date'] ?? null),
                $_POST['service_id'] ?? null,
                'umrah',
                'umrah',
                $default_status_id,
                isset($settings['umrah_workflow_enabled']) && $settings['umrah_workflow_enabled'] ? get_workflow_id_by_transaction_type($pdo, 'umrah') : null,
                $_SESSION['admin_id'],
                $_SESSION['admin_id'],
                $selected_branch_id,
                $agent_id,
                $customer_id,
                $supplier_id,
                $host_id,
                $guarantor_id,
                $_POST['is_outside_ksa'] ?? 0,
                $_POST['visa_number'] ?? '',
                $_POST['visa_issue_date'] ?? null,
                $_POST['visa_expiry_date'] ?? null,
                $_POST['description'] ?? '',
                $_POST['notes'] ?? ''
            ]);

            $passport_id = $pdo->lastInsertId();

            // Handle Financial Engine
            $service_id = $_POST['service_id'] ?? null;
            if ($service_id) {
                $financialEngine = new ServiceFinancialEngine($pdo, $_SESSION['admin_id']);
                $financeResults = $financialEngine->processServiceFinance([
                    'source_type'     => $umrah_service_name,
                    'service_type'    => 'umrah',
                    'source_id'       => $passport_id,
                    'source_number'   => 'UM-'.$passport_id,
                    'branch_id'       => $selected_branch_id,
                    'customer_id'     => $customer_id,
                    'agent_id'        => $agent_id,
                    'supplier_id'     => $supplier_id,
                    'sale_price'      => $_POST['total_amount'] ?? 0,
                    'discount'        => $_POST['discount'] ?? 0,
                    'purchase_price'  => $_POST['cost_amount'] ?? 0,
                    'sale_currency_id'=> $_POST['sale_currency_id'] ?? $_POST['currency_id'] ?? 1,
                    'pur_currency_id' => $_POST['main_currency_id'] ?? $_POST['currency_id'] ?? 1,
                    'exchange_rate'   => $_POST['invoice_exchange_rate'] ?? 1,
                    'amount_received' => $_POST['received_amount'] ?? $_POST['amount_received'] ?? 0,
                    'payment_account_id' => $account_id,
                    'delivery_type'   => $delivery_type,
                    'record_purchase' => $record_purchase,
                    'description'     => trim((string)($_POST['description'] ?? '')) !== '' ? trim((string)$_POST['description']) : ('معاملة عمرة رقم: ' . $passport_id . ' - ' . $full_name),
                    'operation_date'  => normalize_datetime_db($_POST['invoice_date'] ?? null)
                ]);

                // Update passports with invoice ids
                $update_passport = $pdo->prepare("
                    UPDATE passports 
                    SET sales_invoice_id = ?, purchase_invoice_id = ?, auto_invoice_generated = 1 
                    WHERE id = ?
                ");
                $update_passport->execute([
                    $financeResults['sales_invoice_id'],
                    $financeResults['purchase_invoice_id'] ?? null,
                    $passport_id
                ]);
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
            echo json_encode(['success' => true, 'message' => 'تم حفظ المعاملة بنجاح']);
            exit();

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($action === 'change_status') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $status_id = (int)($_POST['status_id'] ?? 0);
            if ($id <= 0 || $status_id <= 0) {
                throw new Exception('بيانات غير مكتملة');
            }

            $visa_number = $_POST['visa_number'] ?? null;
            $visa_issue_date = $_POST['visa_issue_date'] ?? null;
            $visa_expiry_date = $_POST['visa_expiry_date'] ?? null;
            $is_outside_ksa = isset($_POST['is_outside_ksa']) ? (int)$_POST['is_outside_ksa'] : null;

            $stmt = $pdo->prepare("
                UPDATE passports
                SET status_id = ?,
                    visa_number = ?,
                    visa_issue_date = ?,
                    visa_expiry_date = ?,
                    is_outside_ksa = COALESCE(?, is_outside_ksa),
                    status_changed_at = NOW(),
                    status_changed_by = ?,
                    updated_at = NOW()
                WHERE id = ? AND transaction_type = 'umrah' AND deleted_at IS NULL
            ");
            $stmt->execute([$status_id, $visa_number, $visa_issue_date, $visa_expiry_date, $is_outside_ksa, (int)$_SESSION['admin_id'], $id]);

            echo json_encode(['success' => true, 'status' => 'success', 'message' => 'تم تحديث الحالة بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($action === 'update_traveler') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('معرف غير صالح');
            }

            // Validate passport expiry
            $settings = getSettings($pdo);
            $passport_expiry_date = $_POST['passport_expiry_date'] ?? '';
            if (!empty($passport_expiry_date)) {
                $min_validity_months = (int)($settings['min_passport_validity_months'] ?? 6);
                $expiry = new DateTime($passport_expiry_date);
                $now = new DateTime();
                $now->modify("+$min_validity_months months");
                if ($expiry < $now) {
                    throw new Exception("صلاحية الجواز لا يمكن أن تكون أقل من $min_validity_months أشهر.");
                }
            }

            // Get current user data
            $stmt_user = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt_user->execute([$_SESSION['admin_id']]);
            $currentUser = $stmt_user->fetch(PDO::FETCH_ASSOC);

            $selected_branch_id = normalize_nullable_id($_POST['branch_id'] ?? ($_SESSION['branch_id'] ?? null));
            $selected_branch_id = assert_reference_exists($pdo, 'branches', 'الفرع', $selected_branch_id, "deleted_at IS NULL");

            // Extract customer_id and agent_id
            $customer_id = null;
            $agent_id = null;
            $account_id = normalize_nullable_id($_POST['account_id'] ?? null);
            $delivery_type = $_POST['delivery_type'] ?? $_POST['payment_type'] ?? 'cash';
            if ($delivery_type === 'credit') {
                $customer_id = normalize_nullable_id($_POST['customer_id'] ?? $_POST['customer_id_hidden'] ?? null);
            } elseif ($delivery_type === 'agent') {
                $agent_id = normalize_nullable_id($_POST['agent_id'] ?? $_POST['agent_id_hidden'] ?? null);
            }

            $customer_id = assert_reference_exists($pdo, 'customers', 'العميل', $customer_id, "deleted_at IS NULL");
            $agent_id = assert_reference_exists($pdo, 'agents', 'الوكيل', $agent_id, "deleted_at IS NULL");

            $supplier_id = normalize_nullable_id($_POST['supplier_id'] ?? null);
            $supplier_id = assert_reference_exists($pdo, 'suppliers', 'المورد', $supplier_id, "deleted_at IS NULL");
            $host_id = normalize_nullable_id($_POST['host_id'] ?? null);
            if ($host_id !== null) {
                $host_id = assert_reference_exists($pdo, 'umrah_hosts', 'المستضيف', $host_id);
                if (!check_host_capacity($pdo, $host_id, $action === 'update_traveler' ? $id : null)) {
                    throw new Exception('الحد الأقصى للمعتمرين لهذا المستضيف قد تم تجاوزه.');
                }
            }
            $guarantor_id = normalize_nullable_id($_POST['guarantor_id'] ?? null);
            $guarantor_id = assert_reference_exists($pdo, 'umrah_guarantors', 'الضامن', $guarantor_id);

            $record_purchase = (string)($_POST['record_purchase'] ?? '1');
            $purchase_price = (float)($_POST['cost_amount'] ?? 0);
            if ($record_purchase === '1' && $purchase_price > 0 && $supplier_id === null) {
                throw new Exception('يرجى اختيار المورد قبل إنشاء فاتورة الشراء.');
            }

            // Update passports
            $stmt = $pdo->prepare("
                UPDATE passports
                SET full_name = ?,
                    full_name_en = ?,
                    nationality = ?,
                    gender = ?,
                    date_of_birth = ?,
                    passport_number = ?,
                    passport_issue_date = ?,
                    passport_expiry_date = ?,
                    phone_number = ?,
                    operation_date = ?,
                    service_id = ?,
                    branch_id = ?,
                    agent_id = ?,
                    customer_id = ?,
                    supplier_id = ?,
                    host_id = ?,
                    guarantor_id = ?,
                    is_outside_ksa = ?,
                    visa_number = ?,
                    visa_issue_date = ?,
                    visa_expiry_date = ?,
                    description = ?,
                    notes = ?,
                    updated_at = NOW()
                WHERE id = ? AND transaction_type = 'umrah' AND deleted_at IS NULL
            ");
            $stmt->execute([
                $_POST['full_name'] ?? '',
                $_POST['full_name_en'] ?? null,
                $_POST['nationality'] ?? null,
                $_POST['gender'] ?? null,
                $_POST['date_of_birth'] ?? null,
                $_POST['passport_number'] ?? '',
                $_POST['passport_issue_date'] ?? null,
                $_POST['passport_expiry_date'] ?? null,
                $_POST['phone_number'] ?? null,
                normalize_datetime_db($_POST['invoice_date'] ?? null),
                $_POST['service_id'] ?? null,
                $selected_branch_id,
                $agent_id,
                $customer_id,
                $supplier_id,
                $host_id,
                $guarantor_id,
                isset($_POST['is_outside_ksa']) ? (int)$_POST['is_outside_ksa'] : 0,
                $_POST['visa_number'] ?? null,
                $_POST['visa_issue_date'] ?? null,
                $_POST['visa_expiry_date'] ?? null,
                $_POST['description'] ?? null,
                $_POST['notes'] ?? null,
                $id
            ]);

            // Handle Financial Engine
            $umrah_service_name = 'خدمات العمرة';
            $service_id = $_POST['service_id'] ?? null;
            if ($service_id) {
                $financialEngine = new ServiceFinancialEngine($pdo, $_SESSION['admin_id']);
                $financeResults = $financialEngine->processServiceFinance([
                    'source_type'     => $umrah_service_name,
                    'service_type'    => 'umrah',
                    'source_id'       => $id,
                    'source_number'   => 'UM-'.$id,
                    'branch_id'       => $selected_branch_id,
                    'customer_id'     => $customer_id,
                    'agent_id'        => $agent_id,
                    'supplier_id'     => $supplier_id,
                    'sale_price'      => $_POST['total_amount'] ?? 0,
                    'discount'        => $_POST['discount'] ?? 0,
                    'purchase_price'  => $_POST['cost_amount'] ?? 0,
                    'sale_currency_id'=> $_POST['sale_currency_id'] ?? $_POST['currency_id'] ?? 1,
                    'pur_currency_id' => $_POST['main_currency_id'] ?? $_POST['currency_id'] ?? 1,
                    'exchange_rate'   => $_POST['invoice_exchange_rate'] ?? 1,
                    'amount_received' => $_POST['received_amount'] ?? $_POST['amount_received'] ?? 0,
                    'payment_account_id' => $account_id,
                    'delivery_type'   => $delivery_type,
                    'record_purchase' => $record_purchase,
                    'description'     => trim((string)($_POST['description'] ?? '')) !== '' ? trim((string)$_POST['description']) : ('معاملة عمرة رقم: ' . $id . ' - ' . $_POST['full_name']),
                    'operation_date'  => normalize_datetime_db($_POST['invoice_date'] ?? null),
                    'update_existing' => true
                ]);

                // Update passports with invoice ids
                $update_passport = $pdo->prepare("
                    UPDATE passports 
                    SET sales_invoice_id = ?, purchase_invoice_id = ?, auto_invoice_generated = 1 
                    WHERE id = ?
                ");
                $update_passport->execute([
                    $financeResults['sales_invoice_id'],
                    $financeResults['purchase_invoice_id'] ?? null,
                    $id
                ]);
            }

            echo json_encode(['success' => true, 'status' => 'success', 'message' => 'تم حفظ التعديلات بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($action === 'delete_umrah') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                throw new Exception('معرف غير صالح');
            }

            $stmt = $pdo->prepare("SELECT sales_invoice_id, purchase_invoice_id FROM passports WHERE id = ? AND transaction_type = 'umrah' AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new Exception('المعاملة غير موجودة');
            }

            $invoiceIds = [];
            if (!empty($row['sales_invoice_id'])) $invoiceIds[] = (int)$row['sales_invoice_id'];
            if (!empty($row['purchase_invoice_id'])) $invoiceIds[] = (int)$row['purchase_invoice_id'];

            if (!empty($invoiceIds)) {
                $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
                $stmtInv = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE id IN ($placeholders) AND invoice_status = 'posted'");
                $stmtInv->execute($invoiceIds);
                if ((int)$stmtInv->fetchColumn() > 0) {
                    throw new Exception('لا يمكن حذف المعاملة لوجود فواتير مرحلة. قم بإلغاء الترحيل أولاً.');
                }
            }

            $stmtDel = $pdo->prepare("
                UPDATE passports
                SET deleted_at = NOW(),
                    cancellation_date = CURDATE(),
                    cancellation_reason = 'حذف بواسطة المستخدم',
                    updated_at = NOW()
                WHERE id = ? AND transaction_type = 'umrah' AND deleted_at IS NULL
            ");
            $stmtDel->execute([$id]);

            echo json_encode(['success' => true, 'status' => 'success', 'message' => 'تم حذف المعاملة']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    if ($action === 'process_umrah_transition') {
        try {
            $pdo->beginTransaction();

            $passportIds = $_POST['passport_id'] ?? [];
            if (!is_array($passportIds)) {
                $passportIds = [$passportIds];
            }
            $passportIds = array_map('intval', $passportIds);
            $passportIds = array_filter($passportIds, fn($id) => $id > 0);

            if (empty($passportIds)) {
                throw new Exception('Invalid passport IDs');
            }

            $toStepId = (int)($_POST['to_step_id'] ?? 0);
            if ($toStepId <= 0) {
                throw new Exception('Invalid step ID');
            }

            // جلب تفاصيل المرحلة المستهدفة من workflow_steps
            $stmtStep = $pdo->prepare("SELECT * FROM workflow_steps WHERE id = ?");
            $stmtStep->execute([$toStepId]);
            $step = $stmtStep->fetch(PDO::FETCH_ASSOC);
            if (!$step) {
                throw new Exception('Step not found');
            }

            $notes = $_POST['notes'] ?? '';
            $extraData = $_POST['extra_data'] ?? [];
            $checklist = $_POST['checklist'] ?? [];

            // جلب معلومات المستخدم الحالي
            $userId = (int)($_SESSION['admin_id'] ?? 0);
            $userRoleId = null;
            $stmtRole = $pdo->prepare("SELECT role_id FROM users WHERE id = ?");
            $stmtRole->execute([$userId]);
            $userRoleId = (int)$stmtRole->fetchColumn();
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $processedIds = [];
            $failedIds = [];

            // معالجة كل جواز على حدة
            foreach ($passportIds as $passportId) {
                try {
                    // جلب تفاصيل المعاملة الحالية قبل التعديل
                    $stmtPass = $pdo->prepare(
                        "SELECT id, status_id, workflow_id, workflow_step_id, transaction_type
                         FROM passports WHERE id = ? AND transaction_type = 'umrah' AND deleted_at IS NULL LIMIT 1"
                    );
                    $stmtPass->execute([$passportId]);
                    $passport = $stmtPass->fetch(PDO::FETCH_ASSOC);
                    if (!$passport) {
                        $failedIds[] = [$passportId, 'المعاملة غير موجودة'];
                        continue;
                    }

                    $oldStepId = (int)($passport['workflow_step_id'] ?? 0);
                    $oldStatusId = (int)($passport['status_id'] ?? 0);

                    // ==============================
                    // 1) استدعاء الدالة المركزية change_transaction_status
                    //    (تتعامل مع: تحديث status_id, سجل audit_logs, سجل transaction_status_logs,
                    //                 إشعارات للوكيل/الفرع, تحديث المعاملات الأبناء تلقائياً,
                    //                 حفظ حقول المرحلة في passports مباشرة)
                    // ==============================
                    $coreResult = change_transaction_status(
                        [$passportId],
                        $toStepId,
                        $userId,
                        (string)$notes,
                        is_array($extraData) ? $extraData : []
                    );
                    if (!$coreResult) {
                        $failedIds[] = [$passportId, 'فشل تحديث الحالة الأساسية (change_transaction_status)'];
                        continue;
                    }

                    // ==============================
                    // 2) حفظ عمود workflow_step_id في passports
                    // ==============================
                    $stmtStepUpdate = $pdo->prepare("UPDATE passports SET workflow_step_id = ? WHERE id = ?");
                    $stmtStepUpdate->execute([$toStepId, $passportId]);

                    // ==============================
                    // 3) حفظ الحقول الإضافية المخصصة في workflow_field_values
                    //    (هذه مستقلة عن الأعمدة المباشرة في passports)
                    // ==============================
                    if (!empty($extraData) && is_array($extraData)) {
                        foreach ($extraData as $key => $value) {
                            $keyStr = trim((string)$key);
                            if ($keyStr === '') continue;
                            $valueStr = ($value === null) ? null : (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string)$value);
                            $stmtExtra = $pdo->prepare("
                                INSERT INTO workflow_field_values (passport_id, field_key, field_value, created_at, updated_at)
                                VALUES (?, ?, ?, NOW(), NOW())
                                ON DUPLICATE KEY UPDATE field_value = VALUES(field_value), updated_at = NOW()
                            ");
                            $stmtExtra->execute([$passportId, $keyStr, $valueStr]);
                        }
                    }

                    // ==============================
                    // 4) حفظ قائمة التحقق (Checklist)
                    // ==============================
                    if (!empty($checklist) && is_array($checklist)) {
                        foreach ($checklist as $item) {
                            $requirementId = (int)($item['id'] ?? $item['requirement_id'] ?? 0);
                            if ($requirementId <= 0) continue;
                            $verified = (int)($item['verified'] ?? 0) > 0 ? 1 : 0;
                            $requirementName = trim((string)($item['requirement_name'] ?? ''));
                            if ($requirementName === '') {
                                $srn = $pdo->prepare("SELECT requirement_name FROM document_requirements WHERE id = ? LIMIT 1");
                                $srn->execute([$requirementId]);
                                $requirementName = (string)$srn->fetchColumn();
                            }
                            $stmtCheck = $pdo->prepare("
                                INSERT INTO workflow_checklist
                                    (passport_id, requirement_id, requirement_name, verified, verified_at, verified_by)
                                VALUES (?, ?, ?, ?, NOW(), ?)
                                ON DUPLICATE KEY UPDATE
                                    requirement_name = VALUES(requirement_name),
                                    verified = VALUES(verified),
                                    verified_at = VALUES(verified_at),
                                    verified_by = VALUES(verified_by)
                            ");
                            $stmtCheck->execute([
                                $passportId, $requirementId, $requirementName, $verified, $userId
                            ]);
                        }
                    }

                    // ==============================
                    // 5) سجل مفصل في workflow_logs (خاص بسير العمل)
                    // ==============================
                    $extraDataJson = (!empty($extraData) && is_array($extraData))
                        ? json_encode($extraData, JSON_UNESCAPED_UNICODE)
                        : null;
                    $stmtLog = $pdo->prepare("
                        INSERT INTO workflow_logs (
                            passport_id, from_step_id, to_step_id,
                            from_status_id, to_status_id,
                            notes, extra_data,
                            created_by, created_by_role, created_at,
                            ip_address, user_agent
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)
                    ");
                    $stmtLog->execute([
                        $passportId,
                        $oldStepId > 0 ? $oldStepId : null,
                        $toStepId,
                        $oldStatusId > 0 ? $oldStatusId : null,
                        (int)($step['status_id'] ?? 0) ?: null,
                        (string)$notes,
                        $extraDataJson,
                        $userId,
                        $userRoleId > 0 ? $userRoleId : null,
                        $ipAddress,
                        $userAgent
                    ]);

                    $processedIds[] = $passportId;
                } catch (Throwable $innerErr) {
                    $failedIds[] = [$passportId, $innerErr->getMessage()];
                }
            }

            if ($pdo->inTransaction()) {
                $pdo->commit();
            }

            if (empty($processedIds)) {
                echo json_encode([
                    'status' => 'error',
                    'message' => 'فشل تنفيذ الانتقال على جميع المعاملات: ' .
                                 (!empty($failedIds) ? $failedIds[0][1] : 'سبق غير معروف'),
                    'processed_count' => 0,
                    'failed' => $failedIds
                ], JSON_UNESCAPED_UNICODE);
                exit();
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'تم تغيير الحالة بنجاح على ' . count($processedIds) . ' معاملة' .
                             (!empty($failedIds) ? ' (فشل: ' . count($failedIds) . ')' : ''),
                'processed_count' => count($processedIds),
                'processed_ids' => $processedIds,
                'failed_count' => count($failedIds),
                'failed' => $failedIds
            ], JSON_UNESCAPED_UNICODE);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode([
                'status' => 'error',
                'message' => 'حدث خطأ: ' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit();
        }
    }
}

echo json_encode(['success' => false, 'message' => 'طلب غير صالح']);
?>

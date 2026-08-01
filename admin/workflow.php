<?php
require_once 'header.php';

// التحقق من الصلاحية
if (!$is_admin && !has_permission('view_workflow')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// جلب الحالات والأدوار والمستخدمين والفروع للنماذج
$statuses = $pdo->query("SELECT id, status_name FROM statuses")->fetchAll();
$roles = $pdo->query("SELECT id, display_name FROM roles")->fetchAll();
$users = $pdo->query("SELECT id, full_name, username FROM users WHERE status = 'active'")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll();
$services = $pdo->query("SELECT id, service_name FROM services ORDER BY service_name")->fetchAll();

// إضافة سير عمل جديد
if (isset($_POST['add_workflow'])) {
    if (!has_permission('create_workflow')) {
        $error = "ليس لديك صلاحية لإضافة سير عمل";
    } else {
        $name = $_POST['name'];
        $description = $_POST['description'];
        $transaction_type = $_POST['transaction_type'];
        $branch_id = !empty($_POST['branch_id']) ? $_POST['branch_id'] : null;

        try {
            $stmt = $pdo->prepare("INSERT INTO workflows (name, description, transaction_type, branch_id, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $description, $transaction_type, $branch_id, $_SESSION['user_id']]);
            echo "<script>location.href='workflow.php?success=1';</script>";
        } catch (PDOException $e) {
            $error = "حدث خطأ أثناء الإضافة: " . $e->getMessage();
        }
    }
}

// إضافة خطوة لسير العمل
if (isset($_POST['add_step'])) {
    if (!has_permission('edit_workflow')) {
        $error = "ليس لديك صلاحية لتعديل سير العمل";
    } else {
        $workflow_id = $_POST['workflow_id'];
        $step_name = $_POST['step_name'];
        $step_key = $_POST['step_key'];
        $sort_order = $_POST['sort_order'];
        $color = $_POST['color'];
        $is_initial = isset($_POST['is_initial']) ? 1 : 0;
        $is_final = isset($_POST['is_final']) ? 1 : 0;
        $is_editable = isset($_POST['is_editable']) ? 1 : 0;
        $require_note = isset($_POST['require_note']) ? 1 : 0;
        $require_reason = isset($_POST['require_reason']) ? 1 : 0;
        $show_checklist = isset($_POST['show_checklist']) ? 1 : 0;
        $show_fields = isset($_POST['show_fields']) ? implode(',', $_POST['show_fields']) : null;
        $display_status_name = $_POST['display_status_name'] ?? $step_name;

        try {
            // Find or create status in statuses table
            $stmt_check = $pdo->prepare("SELECT id FROM statuses WHERE status_name = ?");
            $stmt_check->execute([$display_status_name]);
            $status_id = $stmt_check->fetchColumn();
            
            if ($status_id === false) {
                $stmt_add_status = $pdo->prepare("INSERT INTO statuses (status_name, status_color) VALUES (?, ?)");
                $stmt_add_status->execute([$display_status_name, $color]);
                $status_id = $pdo->lastInsertId();
            }

            if ($is_initial) {
                $pdo->prepare("UPDATE workflow_steps SET is_initial = 0 WHERE workflow_id = ?")->execute([$workflow_id]);
            }

            $stmt = $pdo->prepare("INSERT INTO workflow_steps (workflow_id, status_id, step_name, step_key, sort_order, color, is_initial, is_final, is_editable, require_note, require_reason, show_fields, show_checklist) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$workflow_id, $status_id, $step_name, $step_key, $sort_order, $color, $is_initial, $is_final, $is_editable, $require_note, $require_reason, $show_fields, $show_checklist]);
            
            // تحديث الحالة الافتراضية لسير العمل إذا كانت هذه هي الحالة الأولية
            if ($is_initial) {
                $step_id = $pdo->lastInsertId();
                $pdo->prepare("UPDATE workflows SET default_status_id = ? WHERE id = ?")->execute([$step_id, $workflow_id]);
            }

            echo "<script>location.href='workflow.php?success=2';</script>";
        } catch (PDOException $e) {
            $error = "حدث خطأ أثناء إضافة الخطوة: " . $e->getMessage();
        }
    }
}

// إضافة انتقال
if (isset($_POST['add_transition'])) {
    if (!has_permission('edit_workflow')) {
        $error = "ليس لديك صلاحية لتعديل سير العمل";
    } else {
        $workflow_id = $_POST['workflow_id'];
        $from_step_id = $_POST['from_step_id'];
        $to_step_id = $_POST['to_step_id'];
        $role_id = !empty($_POST['role_id']) ? implode(',', $_POST['role_id']) : null;
        $user_id = !empty($_POST['user_id']) ? $_POST['user_id'] : null;
        $require_approval = isset($_POST['require_approval']) ? 1 : 0;
        $auto_action = !empty($_POST['auto_action']) ? $_POST['auto_action'] : null;

        try {
            $stmt = $pdo->prepare("INSERT INTO workflow_transitions (workflow_id, from_step_id, to_step_id, role_id, allow_by_user_id, require_approval, auto_action) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$workflow_id, $from_step_id, $to_step_id, $role_id, $user_id, $require_approval, $auto_action]);
            echo "<script>location.href='workflow.php?success=3';</script>";
        } catch (PDOException $e) {
            $error = "حدث خطأ أثناء إضافة الانتقال: " . $e->getMessage();
        }
    }
}

// حذف خطوة
if (isset($_GET['delete_step'])) {
    if (!has_permission('edit_workflow')) {
        $error = "ليس لديك صلاحية لتعديل سير العمل";
    } else {
        $step_id = $_GET['delete_step'];
        try {
            $pdo->prepare("DELETE FROM workflow_steps WHERE id = ?")->execute([$step_id]);
            echo "<script>location.href='workflow.php?success=4';</script>";
        } catch (PDOException $e) {
            $error = "لا يمكن حذف الخطوة لارتباطها ببيانات أخرى";
        }
    }
}

// حذف انتقال
if (isset($_GET['delete_trans'])) {
    if (!has_permission('edit_workflow')) {
        $error = "ليس لديك صلاحية لتعديل سير العمل";
    } else {
        $trans_id = $_GET['delete_trans'];
        try {
            $pdo->prepare("DELETE FROM workflow_transitions WHERE id = ?")->execute([$trans_id]);
            echo "<script>location.href='workflow.php?success=5';</script>";
        } catch (PDOException $e) {
            $error = "حدث خطأ أثناء الحذف";
        }
    }
}

// تحديث خطوة
if (isset($_POST['update_step'])) {
    if (!has_permission('edit_workflow')) {
        $error = "ليس لديك صلاحية لتعديل سير العمل";
    } else {
        $step_id = $_POST['step_id'];
        $step_name = $_POST['step_name'];
        $step_key = $_POST['step_key'];
        $sort_order = $_POST['sort_order'];
        $color = $_POST['color'];
        $is_initial = isset($_POST['is_initial']) ? 1 : 0;
        $is_final = isset($_POST['is_final']) ? 1 : 0;
        $is_editable = isset($_POST['is_editable']) ? 1 : 0;
        $require_note = isset($_POST['require_note']) ? 1 : 0;
        $require_reason = isset($_POST['require_reason']) ? 1 : 0;
        $show_checklist = isset($_POST['show_checklist']) ? 1 : 0;
        $show_fields = isset($_POST['show_fields']) ? implode(',', $_POST['show_fields']) : null;
        $display_status_name = $_POST['display_status_name'] ?? $step_name;

        try {
            $pdo->beginTransaction();

            // Find or create status in statuses table
            $stmt_check = $pdo->prepare("SELECT id FROM statuses WHERE status_name = ?");
            $stmt_check->execute([$display_status_name]);
            $status_id = $stmt_check->fetchColumn();
            
            if ($status_id === false) {
                $stmt_add_status = $pdo->prepare("INSERT INTO statuses (status_name, status_color) VALUES (?, ?)");
                $stmt_add_status->execute([$display_status_name, $color]);
                $status_id = $pdo->lastInsertId();
            }

            if ($is_initial) {
                // الحصول على workflow_id لهذه الخطوة
                $stmt_wf = $pdo->prepare("SELECT workflow_id FROM workflow_steps WHERE id = ?");
                $stmt_wf->execute([$step_id]);
                $workflow_id = $stmt_wf->fetchColumn();
                
                $pdo->prepare("UPDATE workflow_steps SET is_initial = 0 WHERE workflow_id = ?")->execute([$workflow_id]);
                $pdo->prepare("UPDATE workflows SET default_status_id = ? WHERE id = ?")->execute([$step_id, $workflow_id]);
            }

            $stmt = $pdo->prepare("UPDATE workflow_steps SET status_id = ?, step_name = ?, step_key = ?, sort_order = ?, color = ?, is_initial = ?, is_final = ?, is_editable = ?, require_note = ?, require_reason = ?, show_fields = ?, show_checklist = ? WHERE id = ?");
            $stmt->execute([$status_id, $step_name, $step_key, $sort_order, $color, $is_initial, $is_final, $is_editable, $require_note, $require_reason, $show_fields, $show_checklist, $step_id]);
            $pdo->commit();
            echo "<script>location.href='workflow.php?success=6';</script>";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = "حدث خطأ أثناء تحديث الخطوة: " . $e->getMessage();
        }
    }
}

// تحديث انتقال
if (isset($_POST['update_transition'])) {
    if (!has_permission('edit_workflow')) {
        $error = "ليس لديك صلاحية لتعديل سير العمل";
    } else {
        $trans_id = $_POST['trans_id'];
        $from_step_id = $_POST['from_step_id'];
        $to_step_id = $_POST['to_step_id'];
        $role_id = !empty($_POST['role_id']) ? implode(',', $_POST['role_id']) : null;
        $user_id = !empty($_POST['user_id']) ? $_POST['user_id'] : null;
        $require_approval = isset($_POST['require_approval']) ? 1 : 0;
        $auto_action = !empty($_POST['auto_action']) ? $_POST['auto_action'] : null;

        try {
            $stmt = $pdo->prepare("UPDATE workflow_transitions SET from_step_id = ?, to_step_id = ?, role_id = ?, allow_by_user_id = ?, require_approval = ?, auto_action = ? WHERE id = ?");
            $stmt->execute([$from_step_id, $to_step_id, $role_id, $user_id, $require_approval, $auto_action, $trans_id]);
            echo "<script>location.href='workflow.php?success=7';</script>";
        } catch (PDOException $e) {
            $error = "حدث خطأ أثناء تحديث الانتقال: " . $e->getMessage();
        }
    }
}

$workflows = $pdo->query("SELECT w.*, b.branch_name, s.service_name FROM workflows w LEFT JOIN branches b ON w.branch_id = b.id LEFT JOIN services s ON w.transaction_type = s.id ORDER BY w.id ASC")->fetchAll();
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1">نظام سير العمل (Workflow)</h3>
            <p class="text-muted small mb-0">إدارة مراحل المعاملات وقواعد الانتقال بين الحالات</p>
        </div>
        <?php if (has_permission('create_workflow')): ?>
        <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addWorkflowModal">
            <i class="fas fa-plus-circle me-2"></i> إنشاء سير عمل جديد
        </button>
        <?php endif; ?>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-check-circle me-2"></i>
            <?php
            if ($_GET['success'] == 1) echo "تم إنشاء سير العمل بنجاح.";
            if ($_GET['success'] == 2) echo "تمت إضافة الخطوة بنجاح.";
            if ($_GET['success'] == 3) echo "تمت إضافة الانتقال بنجاح.";
            if ($_GET['success'] == 4) echo "تم حذف الخطوة بنجاح.";
            if ($_GET['success'] == 5) echo "تم حذف الانتقال بنجاح.";
            if ($_GET['success'] == 6) echo "تم تحديث المرحلة بنجاح.";
            if ($_GET['success'] == 7) echo "تم تحديث قاعدة الانتقال بنجاح.";
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-exclamation-circle me-2"></i>
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <?php foreach ($workflows as $wf): ?>
            <div class="col-12 mb-5">
                <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                    <div class="card-header bg-white border-bottom-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="fw-bold mb-1 text-primary"><?php echo htmlspecialchars($wf['name']); ?></h5>
                            <div class="d-flex gap-2">
                                <span class="badge bg-light text-dark border small">
                                    <?php 
                                    if ($wf['transaction_type'] === 'all') echo 'جميع المعاملات';
                                    else echo htmlspecialchars($wf['service_name'] ?: $wf['transaction_type']); 
                                    ?>
                                </span>
                                <?php if ($wf['branch_name']): ?>
                                    <span class="badge bg-light text-dark border small"><?php echo htmlspecialchars($wf['branch_name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="btn-group shadow-sm rounded-pill overflow-hidden">
                            <?php if (has_permission('edit_workflow')): ?>
                            <button class="btn btn-success btn-sm px-3" data-bs-toggle="modal" data-bs-target="#addStepModal<?php echo $wf['id']; ?>">
                                <i class="fas fa-plus me-1"></i> إضافة مرحلة
                            </button>
                            <button class="btn btn-info btn-sm text-white px-3" data-bs-toggle="modal" data-bs-target="#addTransitionModal<?php echo $wf['id']; ?>">
                                <i class="fas fa-random me-1"></i> إضافة انتقال
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body px-4 pb-4">
                        <p class="text-muted small mb-4"><?php echo htmlspecialchars($wf['description']); ?></p>
                        
                        <div class="row g-4">
                            <!-- الخطوات -->
                            <div class="col-lg-7">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="fas fa-layer-group text-primary me-2"></i>
                                    <h6 class="fw-bold mb-0">مراحل سير العمل</h6>
                                </div>
                                <div class="table-responsive border rounded-4">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="bg-light">
                                            <tr class="small text-muted">
                                                <th class="border-0 text-center" style="width: 60px;">#</th>
                                                <th class="border-0">اسم المرحلة</th>
                                                <th class="border-0 text-center">خصائص</th>
                                                <th class="border-0 text-center" style="width: 80px;">إجراء</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $steps_stmt = $pdo->prepare("SELECT ws.*, s.status_name FROM workflow_steps ws LEFT JOIN statuses s ON ws.status_id = s.id WHERE ws.workflow_id = ? ORDER BY ws.sort_order");
                                            $steps_stmt->execute([$wf['id']]);
                                            $wf_steps = $steps_stmt->fetchAll();
                                            foreach ($wf_steps as $step):
                                            ?>
                                                <tr>
                                                    <td class="text-center"><span class="badge bg-secondary rounded-circle"><?php echo $step['sort_order']; ?></span></td>
                                                    <td>
                                                        <div class="fw-bold d-flex align-items-center">
                                                            <span class="me-2" style="width: 12px; height: 12px; border-radius: 50%; background-color: <?php echo $step['color']; ?>;"></span>
                                                            <?php echo htmlspecialchars($step['step_name']); ?>
                                                            <small class="text-muted ms-2">(<?php echo htmlspecialchars($step['status_name'] ?: 'بدون حالة'); ?>)</small>
                                                            <?php if ($step['is_initial']): ?>
                                                                <span class="badge bg-primary ms-2 extra-small" style="font-size: 10px;">بداية</span>
                                                            <?php endif; ?>
                                                            <?php if ($step['is_final']): ?>
                                                                <span class="badge bg-dark ms-1 extra-small" style="font-size: 10px;">نهاية</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                                            <small class="text-muted border-end pe-1 me-1"><?php echo $step['step_key']; ?></small>
                                                            <?php 
                                                            if (!empty($step['show_fields'])) {
                                                                $fields_map = get_all_workflow_fields();
                                                                $active_fields = explode(',', $step['show_fields']);
                                                                foreach ($active_fields as $f_key) {
                                                                    if (isset($fields_map[$f_key])) {
                                                                        echo '<span class="badge bg-light text-muted border-0 p-0 extra-small" style="font-size: 10px;">• ' . $fields_map[$f_key] . '</span>';
                                                                    }
                                                                }
                                                            }
                                                            ?>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="d-flex justify-content-center gap-1">
                                                            <i class="fas fa-edit <?php echo $step['is_editable'] ? 'text-success' : 'text-danger opacity-25'; ?>" title="قابلة للتعديل"></i>
                                                            <i class="fas fa-sticky-note <?php echo $step['require_note'] ? 'text-warning' : 'text-muted opacity-25'; ?>" title="ملاحظة إجبارية"></i>
                                                            <i class="fas fa-question-circle <?php echo $step['require_reason'] ? 'text-info' : 'text-muted opacity-25'; ?>" title="سبب إجباري"></i>
                                                        </div>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if (has_permission('edit_workflow')): ?>
                                                        <div class="btn-group shadow-sm rounded-pill overflow-hidden">
                                                            <button class="btn btn-sm btn-light text-primary border-0" data-bs-toggle="modal" data-bs-target="#editStepModal<?php echo $step['id']; ?>">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <a href="workflow.php?delete_step=<?php echo $step['id']; ?>" class="btn btn-sm btn-light text-danger border-0" onclick="return confirm('هل أنت متأكد من حذف هذه المرحلة؟')">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </a>
                                                        </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($wf_steps)): ?>
                                                <tr><td colspan="5" class="text-center py-4 text-muted">لا توجد مراحل مضافة لهذا المسار</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <!-- الانتقالات -->
                            <div class="col-lg-5">
                                <div class="d-flex align-items-center mb-3">
                                    <i class="fas fa-random text-info me-2"></i>
                                    <h6 class="fw-bold mb-0">قواعد الانتقال</h6>
                                </div>
                                <div class="list-group border rounded-4 overflow-hidden">
                                    <?php
                                    $trans_stmt = $pdo->prepare("
                                        SELECT wt.*, ws1.step_name as from_name, ws2.step_name as to_name, r.display_name as role_name, u.full_name as user_name
                                        FROM workflow_transitions wt 
                                        JOIN workflow_steps ws1 ON wt.from_step_id = ws1.id 
                                        JOIN workflow_steps ws2 ON wt.to_step_id = ws2.id
                                        LEFT JOIN roles r ON wt.role_id = r.id
                                        LEFT JOIN users u ON wt.allow_by_user_id = u.id
                                        WHERE wt.workflow_id = ?
                                    ");
                                    $trans_stmt->execute([$wf['id']]);
                                    $wf_trans = $trans_stmt->fetchAll();
                                    foreach ($wf_trans as $trans):
                                    ?>
                                        <div class="list-group-item list-group-item-action border-0 border-bottom p-3">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <div class="d-flex align-items-center mb-2">
                                                        <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($trans['from_name']); ?></span>
                                                        <i class="fas fa-long-arrow-alt-left mx-2 text-primary"></i>
                                                        <span class="badge bg-primary"><?php echo htmlspecialchars($trans['to_name']); ?></span>
                                                    </div>
                                                    <div class="small">
                                                        <span class="text-muted"><i class="fas fa-user-shield me-1"></i> الصلاحية:</span>
                                                        <span class="fw-bold"><?php 
                                                            if ($trans['role_name'] && $trans['user_name']) echo htmlspecialchars($trans['role_name'] . ' + ' . $trans['user_name']);
                                                            else if ($trans['role_name']) echo htmlspecialchars($trans['role_name']);
                                                            else if ($trans['user_name']) echo htmlspecialchars($trans['user_name']);
                                                            else echo 'الجميع';
                                                        ?></span>
                                                        <?php if ($trans['auto_action']): ?>
                                                            <div class="mt-1 text-success">
                                                                <i class="fas fa-magic me-1"></i> إجراء تلقائي: 
                                                                <?php 
                                                                    $actions = [
                                                                        'financial_posting' => 'ترحيل مالي (إنشاء قيود)',
                                                                        'supplier_credit_posting' => 'تسجيل سعر الشراء كأجل للمورد',
                                                                        'close_transaction' => 'إغلاق نهائي للمعاملة',
                                                                        'create_log' => 'تسجيل ملاحظة تلقائية',
                                                                        'create_sales_invoice' => 'إنشاء فاتورة بيع تلقائياً',
                                                                        'create_purchase_invoice' => 'إنشاء فاتورة شراء تلقائياً',
                                                                        'create_both_invoices' => 'إنشاء فاتورتي بيع وشراء معاً',
                                                                        'post_revenue_entry' => 'ترحيل قيد الإيراد',
                                                                        'post_cost_entry' => 'ترحيل قيد التكلفة',
                                                                        'reverse_invoices' => 'عكس الفواتير (إلغاء)',
                                                                        'update_payment_status' => 'تحديث حالة الدفع للفاتورة',
                                                                        'send_invoice_notification' => 'إرسال إشعار الفاتورة',
                                                                        'auto_currency_conversion' => 'تحويل العملة تلقائياً'
                                                                    ];
                                                                    echo $actions[$trans['auto_action']] ?? $trans['auto_action'];
                                                                ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <?php if (has_permission('edit_workflow')): ?>
                                                <div class="ms-2 d-flex gap-1">
                                                    <button class="btn btn-sm btn-light text-primary rounded-circle shadow-sm" style="width: 28px; height: 28px; padding: 0; line-height: 28px;" data-bs-toggle="modal" data-bs-target="#editTransitionModal<?php echo $trans['id']; ?>">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <a href="workflow.php?delete_trans=<?php echo $trans['id']; ?>" class="btn btn-sm btn-light text-danger rounded-circle shadow-sm" style="width: 28px; height: 28px; padding: 0; line-height: 28px;" onclick="return confirm('هل أنت متأكد من حذف هذا الانتقال؟')">
                                                        <i class="fas fa-times"></i>
                                                    </a>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($wf_trans)): ?>
                                        <div class="list-group-item text-center py-4 text-muted border-0">لا توجد قواعد انتقال مضافة</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal إضافة مرحلة -->
                <div class="modal fade" id="addStepModal<?php echo $wf['id']; ?>" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content border-0 shadow-lg rounded-4">
                            <form method="POST">
                                <div class="modal-header bg-success text-white border-0 py-3">
                                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة مرحلة جديدة لسير العمل</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4">
                                    <input type="hidden" name="workflow_id" value="<?php echo $wf['id']; ?>">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">اسم المرحلة (للعرض)</label>
                                            <input type="text" name="step_name" class="form-control rounded-pill" placeholder="مثلاً: قيد المراجعة" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">رمز المرحلة (Key)</label>
                                            <input type="text" name="step_key" class="form-control rounded-pill" placeholder="مثلاً: review" required>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-bold">اسم الحالة للعرض</label>
                                            <input type="text" name="display_status_name" class="form-control rounded-pill" placeholder="مثلاً: جديد، قيد المعالجة" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">الترتيب</label>
                                            <input type="number" name="sort_order" class="form-control rounded-pill" value="0" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label fw-bold">اللون</label>
                                            <input type="color" name="color" class="form-control form-control-color w-100 rounded-pill" value="#6c757d">
                                        </div>
                                        
                                        <div class="col-12"><hr class="my-2"></div>
                                        
                                        <div class="col-md-4">
                                            <div class="form-switch-container">
                                                <div class="form-switch">
                                                    <label class="form-check-label fw-bold small text-dark" for="is_initial_<?php echo $wf['id']; ?>">مرحلة البداية (جديدة)</label>
                                                    <input type="checkbox" name="is_initial" class="form-check-input" id="is_initial_<?php echo $wf['id']; ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-switch-container">
                                                <div class="form-switch">
                                                    <label class="form-check-label fw-bold small text-dark" for="is_final_<?php echo $wf['id']; ?>">مرحلة نهائية (مغلقة)</label>
                                                    <input type="checkbox" name="is_final" class="form-check-input" id="is_final_<?php echo $wf['id']; ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-switch-container">
                                                <div class="form-switch">
                                                    <label class="form-check-label fw-bold small text-dark" for="is_editable_<?php echo $wf['id']; ?>">قابلة للتعديل</label>
                                                    <input type="checkbox" name="is_editable" class="form-check-input" id="is_editable_<?php echo $wf['id']; ?>" checked>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-switch-container">
                                                <div class="form-switch">
                                                    <label class="form-check-label fw-bold small text-dark" for="require_note_<?php echo $wf['id']; ?>">ملاحظة إجبارية عند الدخول</label>
                                                    <input type="checkbox" name="require_note" class="form-check-input" id="require_note_<?php echo $wf['id']; ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-switch-container">
                                                <div class="form-switch">
                                                    <label class="form-check-label fw-bold small text-dark" for="require_reason_<?php echo $wf['id']; ?>">سبب إجباري عند الخروج</label>
                                                    <input type="checkbox" name="require_reason" class="form-check-input" id="require_reason_<?php echo $wf['id']; ?>">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-switch-container">
                                                <div class="form-switch">
                                                    <label class="form-check-label fw-bold small text-warning" for="show_checklist_<?php echo $wf['id']; ?>"><i class="fas fa-tasks me-1"></i> تفعيل قائمة التحقق (Checklist)</label>
                                                    <input type="checkbox" name="show_checklist" class="form-check-input" id="show_checklist_<?php echo $wf['id']; ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-12 mt-3">
                                            <label class="form-label fw-bold text-primary"><i class="fas fa-eye me-1"></i> الحقول التي تظهر في هذه المرحلة</label>
                                            <div class="row g-2 bg-light p-3 rounded-4 border">
                                            <?php 
                                            $available_fields = get_workflow_fields_by_type($wf['transaction_type']);
                                            if (empty($available_fields)): ?>
                                                <div class="col-12 text-center text-muted small">لا توجد حقول مرتبطة بهذا النوع</div>
                                            <?php else: ?>
                                                <?php foreach($available_fields as $key => $label): ?>
                                                    <div class="col-md-4">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" name="show_fields[]" value="<?= $key ?>" id="field_<?= $key ?>_<?= $wf['id'] ?>">
                                                            <label class="form-check-label small" for="field_<?= $key ?>_<?= $wf['id'] ?>"><?= $label ?></label>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                                    <button type="submit" name="add_step" class="btn btn-success rounded-pill px-4">إضافة المرحلة</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Modal إضافة انتقال -->
                <div class="modal fade" id="addTransitionModal<?php echo $wf['id']; ?>" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content border-0 shadow-lg rounded-4">
                            <form method="POST">
                                <div class="modal-header bg-info text-white border-0 py-3">
                                    <h5 class="modal-title fw-bold"><i class="fas fa-random me-2"></i> إضافة قاعدة انتقال</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body p-4">
                                    <input type="hidden" name="workflow_id" value="<?php echo $wf['id']; ?>">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label fw-bold">من المرحلة</label>
                                            <select name="from_step_id" class="form-select rounded-pill" required>
                                                <?php foreach ($wf_steps as $step): ?>
                                                    <option value="<?php echo $step['id']; ?>"><?php echo htmlspecialchars($step['step_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 text-center my-1">
                                            <i class="fas fa-arrow-down text-muted"></i>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-bold">إلى المرحلة</label>
                                            <select name="to_step_id" class="form-select rounded-pill" required>
                                                <?php foreach ($wf_steps as $step): ?>
                                                    <option value="<?php echo $step['id']; ?>"><?php echo htmlspecialchars($step['step_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12"><hr class="my-2"></div>
                                        <div class="col-12">
                                            <label class="form-label fw-bold">من يحق له هذا الانتقال؟ (الأدوار)</label>
                                            <select name="role_id[]" class="form-select rounded-4" multiple size="4">
                                                <?php foreach ($roles as $r): ?>
                                                    <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['display_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <small class="text-muted extra-small">اضغط Ctrl لاختيار أكثر من دور</small>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-bold">أو مستخدم محدد فقط</label>
                                            <select name="user_id" class="form-select rounded-pill">
                                                <option value="">لا يوجد</option>
                                                <?php foreach ($users as $u): ?>
                                                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-bold">إجراء تلقائي عند الانتقال</label>
                                            <select name="auto_action" class="form-select rounded-pill">
                                                <option value="">--- لا يوجد ---</option>
                                                <option value="financial_posting">ترحيل مالي (إنشاء قيود)</option>
                                                <option value="supplier_credit_posting">تسجيل سعر الشراء كأجل للمورد</option>
                                                <option value="close_transaction">إغلاق نهائي للمعاملة</option>
                                                <option value="create_log">تسجيل ملاحظة تلقائية</option>
                                                <option value="create_sales_invoice">إنشاء فاتورة بيع تلقائياً</option>
                                                <option value="create_purchase_invoice">إنشاء فاتورة شراء تلقائياً</option>
                                                <option value="create_both_invoices">إنشاء فاتورتي بيع وشراء معاً</option>
                                                <option value="post_revenue_entry">ترحيل قيد الإيراد</option>
                                                <option value="post_cost_entry">ترحيل قيد التكلفة</option>
                                                <option value="reverse_invoices">عكس الفواتير (إلغاء)</option>
                                                <option value="update_payment_status">تحديث حالة الدفع للفاتورة</option>
                                                <option value="send_invoice_notification">إرسال إشعار الفاتورة</option>
                                                <option value="auto_currency_conversion">تحويل العملة تلقائياً</option>
                                            </select>
                                        </div>
                                        <div class="col-12">
                                            <div class="form-check form-switch">
                                                <input type="checkbox" name="require_approval" class="form-check-input" id="req_app_<?php echo $wf['id']; ?>">
                                                <label class="form-check-label fw-bold" for="req_app_<?php echo $wf['id']; ?>">يتطلب اعتماد المدير</label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                                    <button type="submit" name="add_transition" class="btn btn-info text-white rounded-pill px-4">حفظ القاعدة</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($workflows)): ?>
            <div class="col-12 text-center py-5">
                <div class="mb-3"><i class="fas fa-project-diagram fa-4x text-light"></i></div>
                <h5 class="text-muted">لا يوجد أي سير عمل مضاف حالياً</h5>
                <button class="btn btn-primary rounded-pill px-4 mt-2" data-bs-toggle="modal" data-bs-target="#addWorkflowModal">إنشاء أول سير عمل</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal إضافة سير عمل -->
<div class="modal fade" id="addWorkflowModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <form method="POST">
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة سير عمل جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label fw-bold">اسم سير العمل</label>
                            <input type="text" name="name" class="form-control rounded-pill" placeholder="مثلاً: سير عمل التأشيرات السياحية" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">نوع المعاملة المرتبط بها</label>
                            <select name="transaction_type" class="form-select rounded-pill">
                                <option value="all">الكل (All Types)</option>
                                <?php foreach ($services as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['service_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">الفرع (اختياري)</label>
                            <select name="branch_id" class="form-select rounded-pill">
                                <option value="">جميع الفروع</option>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo htmlspecialchars($b['branch_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">الوصف</label>
                            <textarea name="description" class="form-control rounded-4" rows="3" placeholder="أدخل وصفاً توضيحياً للهدف من هذا السير"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_workflow" class="btn btn-primary rounded-pill px-4">إضافة وحفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modals للتعديل (خارج الحلقات لضمان العمل الصحيح) -->
<?php foreach ($workflows as $wf): ?>
    <?php
    $steps_stmt = $pdo->prepare("SELECT ws.*, s.status_name FROM workflow_steps ws LEFT JOIN statuses s ON ws.status_id = s.id WHERE ws.workflow_id = ? ORDER BY ws.sort_order");
    $steps_stmt->execute([$wf['id']]);
    $wf_steps = $steps_stmt->fetchAll();
    
    foreach ($wf_steps as $step): ?>
        <div class="modal fade" id="editStepModal<?php echo $step['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-lg text-start">
                <div class="modal-content border-0 shadow-lg rounded-4">
                    <form method="POST">
                        <div class="modal-header bg-primary text-white border-0 py-3">
                            <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل المرحلة: <?php echo htmlspecialchars($step['step_name']); ?></h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <input type="hidden" name="step_id" value="<?php echo $step['id']; ?>">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">اسم المرحلة (للعرض)</label>
                                    <input type="text" name="step_name" class="form-control rounded-pill" value="<?php echo htmlspecialchars($step['step_name']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">رمز المرحلة (Key)</label>
                                    <input type="text" name="step_key" class="form-control rounded-pill" value="<?php echo htmlspecialchars($step['step_key']); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">اسم الحالة للعرض</label>
                                    <input type="text" name="display_status_name" class="form-control rounded-pill" value="<?php echo htmlspecialchars($step['status_name'] ?: ''); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">الترتيب</label>
                                    <input type="number" name="sort_order" class="form-control rounded-pill" value="<?php echo $step['sort_order']; ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">اللون</label>
                                    <input type="color" name="color" class="form-control form-control-color w-100 rounded-pill" value="<?php echo $step['color']; ?>">
                                </div>
                                
                                <div class="col-12"><hr class="my-2"></div>
                                
                                <div class="col-md-4">
                                    <div class="form-switch-container">
                                        <div class="form-switch">
                                            <label class="form-check-label fw-bold small text-dark" for="edit_initial_<?php echo $step['id']; ?>">مرحلة البداية</label>
                                            <input type="checkbox" name="is_initial" class="form-check-input" id="edit_initial_<?php echo $step['id']; ?>" <?php echo $step['is_initial'] ? 'checked' : ''; ?>>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-switch-container">
                                        <div class="form-switch">
                                            <label class="form-check-label fw-bold small text-dark" for="edit_final_<?php echo $step['id']; ?>">مرحلة نهائية</label>
                                            <input type="checkbox" name="is_final" class="form-check-input" id="edit_final_<?php echo $step['id']; ?>" <?php echo $step['is_final'] ? 'checked' : ''; ?>>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-switch-container">
                                        <div class="form-switch">
                                            <label class="form-check-label fw-bold small text-dark" for="edit_editable_<?php echo $step['id']; ?>">قابلة للتعديل</label>
                                            <input type="checkbox" name="is_editable" class="form-check-input" id="edit_editable_<?php echo $step['id']; ?>" <?php echo $step['is_editable'] ? 'checked' : ''; ?>>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-switch-container">
                                        <div class="form-switch">
                                            <label class="form-check-label fw-bold small text-dark" for="edit_note_<?php echo $step['id']; ?>">ملاحظة إجبارية</label>
                                            <input type="checkbox" name="require_note" class="form-check-input" id="edit_note_<?php echo $step['id']; ?>" <?php echo $step['require_note'] ? 'checked' : ''; ?>>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-switch-container">
                                        <div class="form-switch">
                                            <label class="form-check-label fw-bold small text-dark" for="edit_reason_<?php echo $step['id']; ?>">سبب إجباري</label>
                                            <input type="checkbox" name="require_reason" class="form-check-input" id="edit_reason_<?php echo $step['id']; ?>" <?php echo $step['require_reason'] ? 'checked' : ''; ?>>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-switch-container">
                                        <div class="form-switch">
                                            <label class="form-check-label fw-bold small text-warning" for="edit_checklist_<?php echo $step['id']; ?>"><i class="fas fa-tasks me-1"></i> تفعيل قائمة التحقق (Checklist)</label>
                                            <input type="checkbox" name="show_checklist" class="form-check-input" id="edit_checklist_<?php echo $step['id']; ?>" <?php echo $step['show_checklist'] ? 'checked' : ''; ?>>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12 mt-3">
                                    <label class="form-label fw-bold text-primary"><i class="fas fa-eye me-1"></i> الحقول التي تظهر في هذه المرحلة</label>
                                    <div class="row g-2 bg-light p-3 rounded-4 border">
                                        <?php 
                                        $selected_fields = !empty($step['show_fields']) ? explode(',', $step['show_fields']) : [];
                                        $available_fields = get_workflow_fields_by_type($wf['transaction_type']);
                                        if (empty($available_fields)): ?>
                                            <div class="col-12 text-center text-muted small">لا توجد حقول مرتبطة بهذا النوع</div>
                                        <?php else: ?>
                                            <?php foreach($available_fields as $key => $label): ?>
                                                <div class="col-md-4">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" name="show_fields[]" value="<?= $key ?>" id="edit_field_<?= $key ?>_<?= $step['id'] ?>" <?= in_array($key, $selected_fields) ? 'checked' : '' ?>>
                                                        <label class="form-check-label small" for="edit_field_<?= $key ?>_<?= $step['id'] ?>"><?= $label ?></label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                            <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                            <button type="submit" name="update_step" class="btn btn-primary rounded-pill px-4">حفظ التغييرات</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php
    $trans_stmt = $pdo->prepare("SELECT * FROM workflow_transitions WHERE workflow_id = ?");
    $trans_stmt->execute([$wf['id']]);
    $wf_trans = $trans_stmt->fetchAll();
    
    foreach ($wf_trans as $trans): ?>
        <div class="modal fade" id="editTransitionModal<?php echo $trans['id']; ?>" tabindex="-1">
            <div class="modal-dialog text-start">
                <div class="modal-content border-0 shadow-lg rounded-4">
                    <form method="POST">
                        <div class="modal-header bg-info text-white border-0 py-3">
                            <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل قاعدة الانتقال</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body p-4">
                            <input type="hidden" name="trans_id" value="<?php echo $trans['id']; ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label fw-bold">من المرحلة</label>
                                    <select name="from_step_id" class="form-select rounded-pill" required>
                                        <?php foreach ($wf_steps as $s): ?>
                                            <option value="<?php echo $s['id']; ?>" <?php echo $s['id'] == $trans['from_step_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['step_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">إلى المرحلة</label>
                                    <select name="to_step_id" class="form-select rounded-pill" required>
                                        <?php foreach ($wf_steps as $s): ?>
                                            <option value="<?php echo $s['id']; ?>" <?php echo $s['id'] == $trans['to_step_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['step_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12"><hr class="my-2"></div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">من يحق له هذا الانتقال؟ (الأدوار)</label>
                                    <select name="role_id[]" class="form-select rounded-4" multiple size="4">
                                        <?php 
                                        $selected_roles = !empty($trans['role_id']) ? explode(',', $trans['role_id']) : [];
                                        foreach ($roles as $r): ?>
                                            <option value="<?php echo $r['id']; ?>" <?php echo in_array($r['id'], $selected_roles) ? 'selected' : ''; ?>><?php echo htmlspecialchars($r['display_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted extra-small">اضغط Ctrl لاختيار أكثر من دور</small>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">أو مستخدم محدد فقط</label>
                                    <select name="user_id" class="form-select rounded-pill">
                                        <option value="">لا يوجد</option>
                                        <?php foreach ($users as $u): ?>
                                            <option value="<?php echo $u['id']; ?>" <?php echo $u['id'] == $trans['allow_by_user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['full_name'] ?: $u['username']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">إجراء تلقائي عند الانتقال</label>
                                    <select name="auto_action" class="form-select rounded-pill">
                                        <option value="">بدون إجراء</option>
                                        <option value="financial_posting" <?php echo $trans['auto_action'] == 'financial_posting' ? 'selected' : ''; ?>>ترحيل مالي</option>
                                        <option value="supplier_credit_posting" <?php echo $trans['auto_action'] == 'supplier_credit_posting' ? 'selected' : ''; ?>>تسجيل سعر الشراء كأجل للمورد</option>
                                        <option value="close_transaction" <?php echo $trans['auto_action'] == 'close_transaction' ? 'selected' : ''; ?>>إغلاق نهائي</option>
                                        <option value="create_log" <?php echo $trans['auto_action'] == 'create_log' ? 'selected' : ''; ?>>تسجيل ملاحظة</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input type="checkbox" name="require_approval" class="form-check-input" id="edit_req_app_<?php echo $trans['id']; ?>" <?php echo $trans['require_approval'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label fw-bold" for="edit_req_app_<?php echo $trans['id']; ?>">يتطلب اعتماد المدير</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                            <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                            <button type="submit" name="update_transition" class="btn btn-info text-white rounded-pill px-4">حفظ التغييرات</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>

<style>
.card { transition: all 0.3s ease; }
.card:hover { transform: translateY(-5px); }
.badge { font-weight: 500; }
.list-group-item-action:hover { background-color: #f8f9fa; }

/* تحسين وضوح الخطوط في نوافذ التعديل */
.modal-content { color: #000000 !important; }
.modal-body label.form-label, 
.modal-body .form-check-label {
    color: #000000 !important;
    font-weight: 800 !important; 
    font-size: 0.95rem;
}
.modal-body .form-control, 
.modal-body .form-select {
    border: 2px solid #ced4da !important; 
    color: #000000 !important;
    font-weight: 600 !important;
}
.modal-body .bg-light {
    background-color: #f0f2f5 !important; 
    border: 1px solid #dee2e6 !important;
}
.modal-header .modal-title { font-weight: 900 !important; }
</style>

<?php require_once 'footer.php'; ?>

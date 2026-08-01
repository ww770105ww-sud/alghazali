<?php
ob_start();
require_once 'header.php';

// التحقق من الصلاحية
$user_role = $_SESSION['role'] ?? 'editor';
if($user_role === 'editor' && !$settings['allow_editor_currencies']) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$success_msg = "";
$error_msg = "";

// إضافة عملة جديدة
if(isset($_POST['add_currency'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        try {
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            
            // التحقق من وجود عملة افتراضية إذا كانت هذه هي الأولى
            $check_any = $pdo->query("SELECT COUNT(*) FROM currencies")->fetchColumn();
            if($check_any == 0) $is_default = 1;

            if($is_default) { 
                $pdo->query("UPDATE currencies SET is_default = 0"); 
                $rate = 1.000000;
            } else {
                $rate = $_POST['exchange_rate'];
            }

            $stmt = $pdo->prepare("INSERT INTO currencies (currency_name, currency_symbol, currency_code, exchange_rate, exchange_rate_sell, exchange_rate_buy, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['currency_name'], 
                $_POST['currency_symbol'], 
                $_POST['currency_code'], 
                $rate, 
                $_POST['exchange_rate_sell'] ?? $rate,
                $_POST['exchange_rate_buy'] ?? $rate,
                $is_default
            ]);
            $new_id = $pdo->lastInsertId();

            // سجل أسعار الصرف التاريخي
            $stmt_history = $pdo->prepare("INSERT INTO currency_exchange_rates_history (currency_id, exchange_rate, exchange_rate_sell, exchange_rate_buy, effective_date, notes, changed_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_history->execute([$new_id, $rate, $_POST['exchange_rate_sell'] ?? $rate, $_POST['exchange_rate_buy'] ?? $rate, date('Y-m-d'), "السعر الابتدائي", $_SESSION['admin_id'] ?? 1]);

            header("Location: currencies.php?success=1");
            exit;
        } catch (PDOException $e) {
            $error_msg = "خطأ في الإضافة: " . $e->getMessage();
        }
    }
}

// تحديث عملة
if(isset($_POST['update_currency'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = "خطأ في التحقق من الطلب (CSRF).";
    } else {
        try {
            $id = $_POST['currency_id'];
            $is_default = isset($_POST['is_default']) ? 1 : 0;
            
            if(!$is_default) {
                $check_other_default = $pdo->prepare("SELECT COUNT(*) FROM currencies WHERE is_default = 1 AND id != ?");
                $check_other_default->execute([$id]);
                if($check_other_default->fetchColumn() == 0) {
                    throw new Exception("يجب أن يكون للنظام عملة افتراضية واحدة على الأقل.");
                }
            }

            if($is_default) { 
                $pdo->query("UPDATE currencies SET is_default = 0"); 
                $rate = 1.000000;
            } else {
                $rate = $_POST['exchange_rate'];
            }

            $stmt = $pdo->prepare("UPDATE currencies SET currency_name = ?, currency_symbol = ?, currency_code = ?, exchange_rate = ?, exchange_rate_sell = ?, exchange_rate_buy = ?, is_default = ? WHERE id = ?");
            $stmt->execute([
                $_POST['currency_name'], 
                $_POST['currency_symbol'], 
                $_POST['currency_code'], 
                $rate, 
                $_POST['exchange_rate_sell'] ?? $rate, 
                $_POST['exchange_rate_buy'] ?? $rate, 
                $is_default, 
                $id
            ]);

            // سجل تاريخي
            $stmt_history = $pdo->prepare("INSERT INTO currency_exchange_rates_history (currency_id, exchange_rate, exchange_rate_sell, exchange_rate_buy, effective_date, notes, changed_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_history->execute([$id, $rate, $_POST['exchange_rate_sell'] ?? $rate, $_POST['exchange_rate_buy'] ?? $rate, date('Y-m-d'), "تحديث يدوي", $_SESSION['admin_id'] ?? 1]);

            header("Location: currencies.php?updated=1");
            exit;
        } catch (Exception $e) {
            $error_msg = "خطأ في التحديث: " . $e->getMessage();
        }
    }
}

// حذف عملة
if(isset($_GET['delete'])) {
    try {
        $id = (int)$_GET['delete'];
        $pdo->prepare("DELETE FROM currencies WHERE id = ? AND is_default = 0")->execute([$id]);
        header("Location: currencies.php?deleted=1");
        exit;
    } catch (PDOException $e) {
        $error_msg = "خطأ في الحذف: " . $e->getMessage();
    }
}

$currencies = $pdo->query("SELECT * FROM currencies ORDER BY is_default DESC, currency_name ASC")->fetchAll();
?>

<div class="container-fluid py-4">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h3 class="fw-bold text-dark mb-1"><i class="fas fa-money-bill-wave me-2 text-primary"></i> إدارة العملات</h3>
            <p class="text-muted small mb-0">ضبط أسعار الصرف والعملة الافتراضية للنظام المحاسبي</p>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary shadow-sm rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#addCurrencyModal">
                <i class="fas fa-plus me-2"></i> إضافة عملة
            </button>
        </div>
    </div>

    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4"><i class="fas fa-check-circle me-2"></i> تم إضافة العملة بنجاح!</div>
    <?php endif; ?>
    <?php if(isset($_GET['updated'])): ?>
        <div class="alert alert-info border-0 shadow-sm rounded-4 mb-4"><i class="fas fa-info-circle me-2"></i> تم تحديث البيانات بنجاح.</div>
    <?php endif; ?>
    <?php if(isset($_GET['deleted'])): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4"><i class="fas fa-trash-alt me-2"></i> تم حذف العملة بنجاح.</div>
    <?php endif; ?>
    <?php if($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <?php foreach($currencies as $c): ?>
        <div class="col-md-6 col-lg-4 col-xl-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 currency-card <?php echo $c['is_default'] ? 'border-start border-4 border-success' : ''; ?>">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div class="currency-icon bg-light rounded-circle d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                            <span class="fs-4 fw-bold text-primary"><?php echo htmlspecialchars($c['currency_symbol']); ?></span>
                        </div>
                        <?php if($c['is_default']): ?>
                            <span class="badge bg-success-subtle text-success rounded-pill px-3 py-2 small">الافتراضية</span>
                        <?php endif; ?>
                    </div>
                    
                    <h5 class="fw-bold mb-1"><?php echo htmlspecialchars($c['currency_name']); ?></h5>
                    <div class="text-muted small mb-3">كود العملة: <span class="badge bg-dark text-white"><?php echo htmlspecialchars($c['currency_code']); ?></span></div>
                    
                    <div class="bg-light rounded-3 p-3 mb-3">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="text-muted extra-small mb-1">سعر الشراء</div>
                                <div class="fw-bold text-dark"><?php echo number_format($c['exchange_rate_buy'] ?: $c['exchange_rate'], 2); ?></div>
                            </div>
                            <div class="col-6">
                                <div class="text-muted extra-small mb-1">سعر البيع</div>
                                <div class="fw-bold text-dark"><?php echo number_format($c['exchange_rate_sell'] ?: $c['exchange_rate'], 2); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-auto">
                        <button class="btn btn-outline-primary flex-grow-1 rounded-pill fw-bold" data-bs-toggle="modal" data-bs-target="#editCurrencyModal<?php echo $c['id']; ?>">
                            <i class="fas fa-edit me-1"></i> تعديل
                        </button>
                        <?php if(!$c['is_default']): ?>
                        <a href="?delete=<?php echo $c['id']; ?>" class="btn btn-outline-danger rounded-pill px-3" onclick="return confirm('هل أنت متأكد من حذف هذه العملة؟')">
                            <i class="fas fa-trash"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Modal إضافة عملة -->
<div class="modal fade" id="addCurrencyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form method="POST">
                <div class="modal-header border-0 bg-primary text-white py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة عملة جديدة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">اسم العملة</label>
                        <input type="text" name="currency_name" class="form-control rounded-3 border-light bg-light" placeholder="مثال: ريال سعودي" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold small text-muted">الرمز</label>
                            <input type="text" name="currency_symbol" class="form-control rounded-3 border-light bg-light text-center" placeholder="مثال: ر.س" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold small text-muted">الكود</label>
                            <input type="text" name="currency_code" class="form-control rounded-3 border-light bg-light text-center" placeholder="مثال: SAR" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3" id="rateFieldAdd">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">سعر الشراء</label>
                            <input type="number" step="0.000001" name="exchange_rate_buy" id="buyInputAdd" class="form-control border-light bg-light rounded-3" value="1.000000" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">سعر البيع</label>
                            <input type="number" step="0.000001" name="exchange_rate_sell" id="sellInputAdd" class="form-control border-light bg-light rounded-3" value="1.000000" required>
                        </div>
                        <input type="hidden" name="exchange_rate" id="rateInputAdd" value="1.000000">
                    </div>
                    <div class="form-check form-switch p-3 bg-light rounded-4">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="is_default" id="addDefCheck" onchange="toggleRateInput('Add')">
                        <label class="form-check-label fw-bold small" for="addDefCheck">تعيين كعملة افتراضية للنظام</label>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light py-3">
                    <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_currency" class="btn btn-primary px-5 shadow rounded-pill fw-bold">إضافة العملة</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modals تعديل العملات -->
<?php foreach($currencies as $c): ?>
<div class="modal fade" id="editCurrencyModal<?php echo $c['id']; ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form method="POST">
                <div class="modal-header border-0 bg-dark text-white py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل عملة: <?php echo htmlspecialchars($c['currency_name']); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="currency_id" value="<?php echo $c['id']; ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">اسم العملة</label>
                        <input type="text" name="currency_name" class="form-control rounded-3 border-light bg-light" value="<?php echo htmlspecialchars($c['currency_name']); ?>" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold small text-muted">الرمز</label>
                            <input type="text" name="currency_symbol" class="form-control rounded-3 border-light bg-light text-center" value="<?php echo htmlspecialchars($c['currency_symbol']); ?>" required>
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label fw-bold small text-muted">الكود</label>
                            <input type="text" name="currency_code" class="form-control rounded-3 border-light bg-light text-center" value="<?php echo htmlspecialchars($c['currency_code']); ?>" required>
                        </div>
                    </div>
                    <div class="row g-2 mb-3" id="rateField<?php echo $c['id']; ?>">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">سعر الشراء</label>
                            <input type="number" step="0.000001" name="exchange_rate_buy" id="buyInput<?php echo $c['id']; ?>" class="form-control border-light bg-light rounded-3" value="<?php echo $c['exchange_rate_buy'] ?: $c['exchange_rate']; ?>" <?php echo $c['is_default'] ? 'readonly' : ''; ?> required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">سعر البيع</label>
                            <input type="number" step="0.000001" name="exchange_rate_sell" id="sellInput<?php echo $c['id']; ?>" class="form-control border-light bg-light rounded-3" value="<?php echo $c['exchange_rate_sell'] ?: $c['exchange_rate']; ?>" <?php echo $c['is_default'] ? 'readonly' : ''; ?> required>
                        </div>
                        <input type="hidden" name="exchange_rate" id="rateInput<?php echo $c['id']; ?>" value="<?php echo $c['exchange_rate']; ?>">
                    </div>
                    <div class="form-check form-switch p-3 bg-light rounded-4 mb-3">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="is_default" id="defEdit<?php echo $c['id']; ?>" onchange="toggleRateInput(<?php echo $c['id']; ?>)" <?php echo $c['is_default'] ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold small" for="defEdit<?php echo $c['id']; ?>">تعيين كعملة افتراضية</label>
                    </div>

                    <div class="history-section p-3 border rounded-4 bg-white shadow-sm">
                        <h6 class="fw-bold small mb-2"><i class="fas fa-history me-1 text-muted"></i> آخر التحديثات</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-borderless mb-0 small">
                                <tbody>
                                    <?php 
                                    $history = $pdo->prepare("SELECT exchange_rate, effective_date FROM currency_exchange_rates_history WHERE currency_id = ? ORDER BY effective_date DESC LIMIT 3");
                                    $history->execute([$c['id']]);
                                    while($h = $history->fetch()): ?>
                                        <tr>
                                            <td class="text-muted"><?php echo $h['effective_date']; ?></td>
                                            <td class="fw-bold text-end"><?php echo number_format($h['exchange_rate'], 4); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light py-3">
                    <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_currency" class="btn btn-dark px-5 shadow rounded-pill fw-bold">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
function toggleRateInput(id) {
    const check = document.getElementById(id === 'Add' ? 'addDefCheck' : 'defEdit' + id);
    const buyInput = document.getElementById(id === 'Add' ? 'buyInputAdd' : 'buyInput' + id);
    const sellInput = document.getElementById(id === 'Add' ? 'sellInputAdd' : 'sellInput' + id);
    const rateInput = document.getElementById(id === 'Add' ? 'rateInputAdd' : 'rateInput' + id);
    
    if (check.checked) {
        buyInput.value = "1.000000";
        buyInput.readOnly = true;
        sellInput.value = "1.000000";
        sellInput.readOnly = true;
        rateInput.value = "1.000000";
    } else {
        buyInput.readOnly = false;
        sellInput.readOnly = false;
    }
}
</script>

<style>
    .currency-card { transition: transform 0.2s ease, shadow 0.2s ease; }
    .currency-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }
    .bg-success-subtle { background-color: #d1e7dd !important; }
</style>

<?php 
require_once 'footer.php';
ob_end_flush(); 
?>

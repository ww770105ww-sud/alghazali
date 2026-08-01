<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

function log_public_query(PDO $pdo, string $queryNumber, bool $found, ?string $resultTable = null, ?int $resultId = null): void
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO public_queries (query_number, found, result_table, result_id, user_ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $queryNumber,
            $found ? 1 : 0,
            $resultTable,
            $resultId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('Public query log error: ' . $e->getMessage());
    }
}

$queryNumber = trim((string)($_GET['passport'] ?? ''));
$queryResult = null;
$lookupPerformed = false;
$lookupMessage = '';

if ($queryNumber !== '') {
    $lookupPerformed = true;

    $sql = "
        SELECT
            p.id,
            p.transaction_type,
            p.passport_number,
            p.visa_number,
            p.visa_no,
            p.status_id,
            p.created_at,
            p.office_name,
            p.received_date,
            p.delivery_date,
            p.visa_issue_date,
            p.visa_expiry_date,
            p.branch_id,
            p.agent_id,
            p.sales_invoice_id,
            p.purchase_invoice_id,
            COALESCE(wvp.full_name, p.full_name) AS full_name,
            COALESCE(wvp.full_name_en, p.full_name_en) AS full_name_en,
            COALESCE(wvp.nationality, p.nationality) AS nationality,
            COALESCE(wvp.gender, p.gender) AS gender,
            COALESCE(wvp.date_of_birth, p.date_of_birth) AS date_of_birth,
            COALESCE(wvp.passport_issue_date, p.passport_issue_date) AS passport_issue_date,
            COALESCE(wvp.passport_expiry_date, p.passport_expiry_date) AS passport_expiry_date,
            COALESCE(wvp.profession_id, p.profession_id) AS profession_id,
            COALESCE(wvp.phone_number, p.phone_number) AS phone_number,
            s.status_name,
            s.status_color,
            pr.name_ar AS profession_name,
            b.branch_name,
            a.agent_name
        FROM passports p
        LEFT JOIN work_visa_profiles wvp ON wvp.passport_id = p.id
        LEFT JOIN statuses s ON s.id = p.status_id
        LEFT JOIN professions pr ON pr.id = COALESCE(wvp.profession_id, p.profession_id)
        LEFT JOIN branches b ON b.id = p.branch_id
        LEFT JOIN agents a ON a.id = p.agent_id
        WHERE p.deleted_at IS NULL
          AND (
                p.passport_number = ?
                OR p.visa_number = ?
                OR p.visa_no = ?
                OR COALESCE(wvp.passport_number, '') = ?
          )
        ORDER BY p.created_at DESC, p.id DESC
        LIMIT 1
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$queryNumber, $queryNumber, $queryNumber, $queryNumber]);
        $queryResult = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($queryResult) {
            log_public_query($pdo, $queryNumber, true, 'passports', (int)$queryResult['id']);
        } else {
            log_public_query($pdo, $queryNumber, false, null, null);
            $lookupMessage = 'لم يتم العثور على نتيجة مطابقة لهذا الرقم.';
        }
    } catch (Throwable $e) {
        $lookupMessage = 'تعذر تنفيذ الاستعلام حالياً. يرجى المحاولة لاحقاً.';
        error_log('Query result lookup error: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/includes/header.php';

$serviceLabel = '';
if ($queryResult) {
    $serviceLabel = in_array((string)$queryResult['transaction_type'], ['work_visa', '6'], true)
        ? 'تأشيرات العمل'
        : normalize_service_display_name((string)$queryResult['transaction_type']);
}
?>
<style>
    .query-result-wrap {
        min-height: 60vh;
        background: linear-gradient(180deg, rgba(130, 201, 30, 0.08), rgba(255, 255, 255, 0));
    }
    .query-card {
        border: 0;
        border-radius: 24px;
        box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
    }
    .query-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border-radius: 999px;
        padding: 10px 18px;
        font-weight: 700;
    }
    .query-label {
        color: #64748b;
        font-size: 0.9rem;
        margin-bottom: 6px;
    }
    .query-value {
        font-weight: 700;
        color: #0f172a;
        word-break: break-word;
    }
</style>

<main class="query-result-wrap py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9">
                <div class="query-card bg-white p-4 p-md-5">
                    <div class="text-center mb-4">
                        <h1 class="fw-bold mb-2">نتيجة الاستعلام</h1>
                        <p class="text-muted mb-0">يمكنك متابعة حالة معاملتك باستخدام رقم الجواز أو رقم التأشيرة.</p>
                    </div>

                    <form method="GET" class="row g-3 justify-content-center mb-4">
                        <div class="col-md-8">
                            <input type="text" name="passport" class="form-control form-control-lg rounded-pill" placeholder="أدخل رقم الجواز أو رقم التأشيرة" value="<?php echo h($queryNumber); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-success btn-lg rounded-pill w-100">
                                <i class="fas fa-search me-2"></i>استعلام
                            </button>
                        </div>
                    </form>

                    <?php if (!$lookupPerformed): ?>
                        <div class="alert alert-info rounded-4 border-0">
                            أدخل رقم الجواز أو رقم التأشيرة ثم اضغط على زر الاستعلام.
                        </div>
                    <?php elseif (!$queryResult): ?>
                        <div class="alert alert-warning rounded-4 border-0 mb-0">
                            <?php echo h($lookupMessage ?: 'لم يتم العثور على بيانات.'); ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center mb-4">
                            <span class="query-badge text-white" style="background-color: <?php echo h($queryResult['status_color'] ?: '#0d6efd'); ?>;">
                                <i class="fas fa-passport"></i>
                                <?php echo h($queryResult['status_name'] ?: 'قيد المتابعة'); ?>
                            </span>
                        </div>

                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="query-label">الخدمة</div>
                                <div class="query-value"><?php echo h($serviceLabel); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">الاسم الكامل</div>
                                <div class="query-value"><?php echo h($queryResult['full_name'] ?: '---'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">رقم الجواز</div>
                                <div class="query-value"><?php echo h($queryResult['passport_number'] ?: '---'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">رقم التأشيرة</div>
                                <div class="query-value"><?php echo h($queryResult['visa_number'] ?: ($queryResult['visa_no'] ?: '---')); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">المهنة</div>
                                <div class="query-value"><?php echo h($queryResult['profession_name'] ?: '---'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">الجنسية</div>
                                <div class="query-value"><?php echo h($queryResult['nationality'] ?: '---'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">الفرع</div>
                                <div class="query-value"><?php echo h($queryResult['branch_name'] ?: '---'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">الوكيل</div>
                                <div class="query-value"><?php echo h($queryResult['agent_name'] ?: '---'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">تاريخ الإدخال</div>
                                <div class="query-value"><?php echo h(format_datetime_display($queryResult['created_at'] ?? null)); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">تاريخ الاستلام</div>
                                <div class="query-value"><?php echo h($queryResult['received_date'] ?: '---'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">تاريخ الإصدار</div>
                                <div class="query-value"><?php echo h($queryResult['visa_issue_date'] ?: '---'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">تاريخ الانتهاء</div>
                                <div class="query-value"><?php echo h($queryResult['visa_expiry_date'] ?: '---'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">تاريخ التسليم</div>
                                <div class="query-value"><?php echo h($queryResult['delivery_date'] ?: '---'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="query-label">الجوال</div>
                                <div class="query-value"><?php echo h($queryResult['phone_number'] ?: '---'); ?></div>
                            </div>
                            <div class="col-12">
                                <div class="query-label">الجهة / المكتب</div>
                                <div class="query-value"><?php echo h($queryResult['office_name'] ?: '---'); ?></div>
                            </div>
                        </div>

                        <?php if (!empty($queryResult['sales_invoice_id']) || !empty($queryResult['purchase_invoice_id'])): ?>
                            <div class="alert alert-light border rounded-4 mt-4 mb-0">
                                <strong>الربط المالي:</strong>
                                بيع: <?php echo h($queryResult['sales_invoice_id'] ?: '---'); ?> |
                                شراء: <?php echo h($queryResult['purchase_invoice_id'] ?: '---'); ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

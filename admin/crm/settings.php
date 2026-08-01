
<?php
require_once __DIR__ . '/../header.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/crm_functions.php';

// Check permission
if (!has_permission_v3('crm_view') && !has_permission_v3('crm_edit')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// Handle save
if (isset($_POST['save_settings']) && has_permission_v3('crm_edit')) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $error_msg = 'خطأ في التحقق من الطلب';
    } else {
        try {
            $pdo->beginTransaction();
            
            // List of settings
            $settingsList = [
                'meta_app_id',
                'meta_app_secret',
                'verify_token',
                'webhook_verify_token',
                'access_token',
                'permanent_access_token',
                'phone_number_id',
                'whatsapp_business_account_id',
                'business_manager_id',
                'system_user_id',
                'app_webhook_secret',
                'webhook_url',
                'graph_api_version',
                'api_base_url',
                'business_display_name',
                'default_language',
                'default_country',
                'webhook_status',
                'openai_api_key',
                'ai_provider',
                'ai_model',
                'max_tokens',
                'temperature',
                'auto_reply',
                'typing_indicator',
                'read_receipts',
                'queues',
                'retry_attempts',
                'rate_limits',
                'message_delay',
                'media_storage',
                'backup',
                'logs'
            ];
            
            foreach ($settingsList as $key) {
                $value = $_POST[$key] ?? null;
                if ($value !== null) {
                    // Upsert
                    $stmt = $pdo->prepare("INSERT INTO crm_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                    $stmt->execute([$key, $value, $value]);
                }
            }
            
            $pdo->commit();
            $success_msg = 'تم حفظ الإعدادات بنجاح';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = $e->getMessage();
        }
    }
}

// Get current settings
function getCrmSettings($pdo) {
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM crm_settings");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        return [];
    }
}
$crmSettings = getCrmSettings($pdo);

// Generate Webhook URL
$webhookUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/../webhook.php';

?>

<div class="apple-container">
    <div class="apple-header">
        <div>
            <h1 class="h3 fw-bold mb-1">إعدادات CRM</h1>
            <p class="text-muted small mb-0">تكوين WhatsApp Business API والمزيد</p>
        </div>
        <a href="index.php" class="btn btn-light">
            <i class="fas fa-arrow-left me-2"></i> العودة
        </a>
    </div>

    <?php if (isset($success_msg)): ?>
        <div class="alert alert-success border-0 rounded-4 shadow-sm mb-4"><?= h($success_msg) ?></div>
    <?php endif; ?>
    <?php if (isset($error_msg)): ?>
        <div class="alert alert-danger border-0 rounded-4 shadow-sm mb-4"><?= h($error_msg) ?></div>
    <?php endif; ?>

    <form method="POST" id="settingsForm">
        <?= csrf_input() ?>
        <input type="hidden" name="save_settings" value="1">

        <!-- WhatsApp Business API Settings -->
        <div class="apple-card mb-4">
            <div class="card-header">
                <h5 class="fw-bold mb-0"><i class="fab fa-whatsapp me-2 text-success"></i> إعدادات WhatsApp Business</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Meta App ID</label>
                        <input type="text" class="form-control" name="meta_app_id" value="<?= h($crmSettings['meta_app_id'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Meta App Secret</label>
                        <input type="password" class="form-control" name="meta_app_secret" value="<?= h($crmSettings['meta_app_secret'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Access Token</label>
                        <input type="password" class="form-control" name="access_token" value="<?= h($crmSettings['access_token'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Permanent Access Token</label>
                        <input type="password" class="form-control" name="permanent_access_token" value="<?= h($crmSettings['permanent_access_token'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone Number ID</label>
                        <input type="text" class="form-control" name="phone_number_id" value="<?= h($crmSettings['phone_number_id'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">WhatsApp Business Account ID</label>
                        <input type="text" class="form-control" name="whatsapp_business_account_id" value="<?= h($crmSettings['whatsapp_business_account_id'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Business Manager ID</label>
                        <input type="text" class="form-control" name="business_manager_id" value="<?= h($crmSettings['business_manager_id'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">System User ID</label>
                        <input type="text" class="form-control" name="system_user_id" value="<?= h($crmSettings['system_user_id'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">App Webhook Secret</label>
                        <input type="password" class="form-control" name="app_webhook_secret" value="<?= h($crmSettings['app_webhook_secret'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Graph API Version</label>
                        <input type="text" class="form-control" name="graph_api_version" value="<?= h($crmSettings['graph_api_version'] ?? 'v19.0') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Business Display Name</label>
                        <input type="text" class="form-control" name="business_display_name" value="<?= h($crmSettings['business_display_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Default Language</label>
                        <input type="text" class="form-control" name="default_language" value="<?= h($crmSettings['default_language'] ?? 'ar') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Default Country</label>
                        <input type="text" class="form-control" name="default_country" value="<?= h($crmSettings['default_country'] ?? 'YE') ?>">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Webhook URL</label>
                        <div class="input-group">
                            <input type="text" class="form-control" value="<?= h($webhookUrl) ?>" readonly id="webhookUrlInput">
                            <button type="button" class="btn btn-light" onclick="copyToClipboard('webhookUrlInput')"><i class="fas fa-copy"></i> نسخ</button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Verify Token</label>
                        <input type="text" class="form-control" name="verify_token" value="<?= h($crmSettings['verify_token'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- AI Settings -->
        <div class="apple-card mb-4">
            <div class="card-header">
                <h5 class="fw-bold mb-0"><i class="fas fa-robot me-2 text-primary"></i> إعدادات الذكاء الاصطناعي</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">OpenAI API Key</label>
                        <input type="password" class="form-control" name="openai_api_key" value="<?= h($crmSettings['openai_api_key'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">AI Provider</label>
                        <select class="form-select" name="ai_provider">
                            <option value="openai" <?= (($crmSettings['ai_provider'] ?? '') === 'openai') ? 'selected' : '' ?>>OpenAI</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">AI Model</label>
                        <select class="form-select" name="ai_model">
                            <option value="gpt-4" <?= (($crmSettings['ai_model'] ?? '') === 'gpt-4') ? 'selected' : '' ?>>GPT-4</option>
                            <option value="gpt-3.5-turbo" <?= (($crmSettings['ai_model'] ?? '') === 'gpt-3.5-turbo') ? 'selected' : '' ?>>GPT-3.5 Turbo</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Max Tokens</label>
                        <input type="number" class="form-control" name="max_tokens" value="<?= h($crmSettings['max_tokens'] ?? 1000) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Temperature</label>
                        <input type="number" step="0.1" class="form-control" name="temperature" value="<?= h($crmSettings['temperature'] ?? 0.7) ?>">
                    </div>
                    <div class="col-md-6">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" name="auto_reply" value="1" id="autoReplyCheck" <?= (($crmSettings['auto_reply'] ?? '') === '1') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="autoReplyCheck"> تفعيل الرد الآلي بالذكاء الاصطناعي</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Other Settings -->
        <div class="apple-card mb-4">
            <div class="card-header">
                <h5 class="fw-bold mb-0"><i class="fas fa-sliders-h me-2"></i> إعدادات أخرى</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="typing_indicator" value="1" id="typingCheck" <?= (($crmSettings['typing_indicator'] ?? '') === '1') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="typingCheck"> إظهار مؤشر الكتابة</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="read_receipts" value="1" id="readReceiptCheck" <?= (($crmSettings['read_receipts'] ?? '') === '1') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="readReceiptCheck"> إرسال إيصالات القراءة</label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="logs" value="1" id="logsCheck" <?= (($crmSettings['logs'] ?? '') === '1') ? 'checked' : '' ?>>
                            <label class="form-check-label" for="logsCheck"> تفعيل السجلات</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <button type="reset" class="btn btn-light">إعادة تعيين</button>
            <button type="submit" class="btn-apple-primary">حفظ الإعدادات</button>
        </div>
    </form>
</div>

<script>
function copyToClipboard(inputId) {
    const input = document.getElementById(inputId);
    input.select();
    document.execCommand('copy');
    alert('تم نسخ الرابط');
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>


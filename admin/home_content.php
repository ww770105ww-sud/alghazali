<?php
require_once 'header.php';
?>
<script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<style>
    .accordion-button:not(.collapsed) {
        background-color: var(--primary-color-light);
        color: var(--primary-color);
    }
    .accordion-button:focus {
        box-shadow: 0 0 0 0.25rem rgba(var(--primary-color-rgb), 0.25);
    }
</style>

// التحقق من الصلاحية
$user_role = $_SESSION['role'] ?? 'editor';
if($user_role === 'editor' && !$settings['allow_editor_home_content']) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// تحديث المحتوى
if(isset($_POST['update_content'])) {
    foreach($_POST['sections'] as $key => $data) {
        $stmt = $pdo->prepare("UPDATE site_content SET section_title = ?, section_text = ?, meta_title = ?, meta_description = ?, meta_keywords = ? WHERE section_key = ?");
        $stmt->execute([
            $data['title'] ?? '', 
            $data['text'] ?? '', 
            $data['meta_title'] ?? '', 
            $data['meta_description'] ?? '', 
            $data['meta_keywords'] ?? '', 
            $key
        ]);
    }
    
    // معالجة رفع الصور إذا وجدت
    if(!empty($_FILES['section_images']['name'])) {
        foreach($_FILES['section_images']['name'] as $key => $name) {
            if(!empty($name)) {
                $tmp_name = $_FILES['section_images']['tmp_name'][$key];
                $new_name = time() . '_' . $name;
                if(move_uploaded_file($tmp_name, '../assets/uploads/' . $new_name)) {
                    $stmt = $pdo->prepare("UPDATE site_content SET section_image = ? WHERE section_key = ?");
                    $stmt->execute([$new_name, $key]);
                }
            }
        }
    }
    echo "<script>location.href='home_content.php?success=1';</script>";
    exit();
}

$contents = [];
$stmt = $pdo->query("SELECT * FROM site_content");
while($row = $stmt->fetch()) {
    $contents[$row['section_key']] = $row;
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold mb-0"><i class="fas fa-file-alt me-2 text-primary"></i> إدارة محتوى الصفحة الرئيسية</h2>
        <button type="submit" form="contentForm" name="update_content" class="btn btn-primary rounded-pill px-4 shadow-sm">
            <i class="fas fa-save me-2"></i> حفظ كل التغييرات
        </button>
    </div>

    <?php if(isset($_SESSION['flash_message'])): 
        $msg = $_SESSION['flash_message'];
        // ... (الكود الخاص بعرض رسائل الفلاش هنا)
        unset($_SESSION['flash_message']);
    endif; ?>

    <form method="POST" enctype="multipart/form-data" id="contentForm">
        <div class="accordion" id="contentAccordion">
            <?php 
            $sections = [
                'about_summary' => ['title' => 'ملخص من نحن', 'icon' => 'fa-info-circle'],
                'mission' => ['title' => 'الرسالة', 'icon' => 'fa-bullseye'],
                'vision' => ['title' => 'الرؤية', 'icon' => 'fa-eye'],
                'goal' => ['title' => 'الأهداف', 'icon' => 'fa-tasks'],
                'services_section' => ['title' => 'قسم الخدمات', 'icon' => 'fa-concierge-bell'],
                'cta_section' => ['title' => 'قسم دعوة لاتخاذ إجراء (CTA)', 'icon' => 'fa-hand-point-right']
            ];

            foreach($sections as $key => $details):
            $content = $contents[$key] ?? [];
            ?>
            <div class="accordion-item rounded-4 shadow-sm mb-3">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed rounded-top-4 fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-<?php echo $key; ?>">
                        <i class="fas <?php echo $details['icon']; ?> me-2 text-primary"></i> <?php echo $details['title']; ?>
                    </button>
                </h2>
                <div id="collapse-<?php echo $key; ?>" class="accordion-collapse collapse" data-bs-parent="#contentAccordion">
                    <div class="accordion-body p-4">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">العنوان الرئيسي للقسم</label>
                                    <input type="text" name="sections[<?php echo $key; ?>][title]" class="form-control" value="<?php echo htmlspecialchars($content['section_title'] ?? ''); ?>">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">النص</label>
                                    <textarea name="sections[<?php echo $key; ?>][text]" class="form-control editor" rows="8"><?php echo htmlspecialchars($content['section_text'] ?? ''); ?></textarea>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="bg-light p-3 rounded-3 border">
                                    <div class="mb-3">
                                        <label class="form-label fw-bold">صورة القسم</label>
                                        <input type="file" name="section_images[<?php echo $key; ?>]" class="form-control">
                                        <?php if(!empty($content['section_image'])): ?>
                                            <div class="mt-2">
                                                <img src="../assets/uploads/<?php echo $content['section_image']; ?>" class="img-fluid rounded shadow-sm">
                                                <small class="text-muted d-block mt-1">الصورة الحالية</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <h6 class="text-muted fw-bold"><i class="fab fa-google me-2"></i> إعدادات SEO (اختياري)</h6>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Meta Title</label>
                                <input type="text" name="sections[<?php echo $key; ?>][meta_title]" class="form-control" value="<?php echo htmlspecialchars($content['meta_title'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Meta Description</label>
                                <input type="text" name="sections[<?php echo $key; ?>][meta_description]" class="form-control" value="<?php echo htmlspecialchars($content['meta_description'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Meta Keywords</label>
                                <input type="text" name="sections[<?php echo $key; ?>][meta_keywords]" class="form-control" value="<?php echo htmlspecialchars($content['meta_keywords'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </form>
</div>

<?php require_once 'footer.php'; ?>
<script>
    tinymce.init({
        selector: 'textarea.editor',
        plugins: 'directionality advlist autolink lists link image charmap print preview anchor ',
        toolbar: 'undo redo | formatselect | bold italic backcolor | \
                  alignleft aligncenter alignright alignjustify | \
                  bullist numlist outdent indent | removeformat | help | ltr rtl',
        directionality: 'rtl',
        language: 'ar',
        height: 300
    });
</script>

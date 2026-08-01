<?php
require_once 'header.php';

// التحقق من الصلاحية
$user_role = $_SESSION['role'] ?? 'editor';
if($user_role === 'editor' && !$settings['allow_editor_pages']) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// تحديث المحتوى
if(isset($_POST['update_content'])) {
    $section_key = $_POST['section_key'];
    $section_title = $_POST['section_title'];
    $section_text = $_POST['section_text'];
    $upload_dir = '../assets/uploads/';

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $stmt = $pdo->prepare("SELECT section_image FROM site_content WHERE section_key = ?");
    $stmt->execute([$section_key]);
    $current_image = $stmt->fetchColumn();

    if(!empty($_FILES['section_image']['name'])) {
        $section_image = time() . '_' . basename($_FILES['section_image']['name']);
        move_uploaded_file($_FILES['section_image']['tmp_name'], $upload_dir . $section_image);
    } else {
        $section_image = $current_image;
    }

    $stmt = $pdo->prepare("UPDATE site_content SET section_title = ?, section_text = ?, section_image = ? WHERE section_key = ?");
    $stmt->execute([$section_title, $section_text, $section_image, $section_key]);
    header("Location: content.php?success=1");
    exit;
}

$contents = $pdo->query("SELECT * FROM site_content")->fetchAll();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold"><i class="fas fa-edit me-2 text-primary"></i> إدارة محتوى الصفحات</h3>
    </div>
    
    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3">تم تحديث المحتوى بنجاح!</div>
    <?php endif; ?>

    <div class="row">
        <?php foreach($contents as $content): ?>
        <div class="col-md-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-dark">قسم: <?php echo htmlspecialchars($content['section_title']); ?></h5>
                    <span class="badge bg-light text-muted border"><?php echo $content['section_key']; ?></span>
                </div>
                <div class="card-body p-4">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="section_key" value="<?php echo $content['section_key']; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">عنوان القسم</label>
                            <input type="text" name="section_title" class="form-control rounded-3" value="<?php echo htmlspecialchars($content['section_title']); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">محتوى النص</label>
                            <textarea name="section_text" class="form-control rounded-3" rows="6"><?php echo htmlspecialchars($content['section_text']); ?></textarea>
                        </div>
                        
                        <div class="mb-4">
                            <label class="form-label fw-bold">صورة القسم</label>
                            <div class="d-flex align-items-start gap-3">
                                <div class="flex-grow-1">
                                    <input type="file" name="section_image" class="form-control rounded-3 mb-2">
                                    <small class="text-muted">اتركها فارغة للحفاظ على الصورة الحالية.</small>
                                </div>
                                <?php if($content['section_image']): ?>
                                    <div class="text-center">
                                        <img src="../assets/uploads/<?php echo $content['section_image']; ?>" class="rounded-3 border shadow-sm" style="width: 100px; height: 100px; object-fit: cover;">
                                        <div class="small text-muted mt-1">المعاينة الحالية</div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <button type="submit" name="update_content" class="btn btn-primary w-100 rounded-pill shadow-sm py-2 fw-bold">
                            <i class="fas fa-save me-1"></i> حفظ التعديلات
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>

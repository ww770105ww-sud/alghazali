<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../includes/db.php';

// التحقق من الصلاحيات (Admin or Developer)
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$action = $_GET['action'] ?? 'list';
$template_id = $_GET['id'] ?? null;

// جلب الخدمات لربطها بالقوالب
$services = $pdo->query("SELECT id, service_name FROM services ORDER BY service_name ASC")->fetchAll();

// جلب إعدادات الموقع للشعارات
require_once '../includes/functions.php';
$site_settings = getSettings($pdo);
$logo_url = !empty($site_settings['print_logo']) ? '../assets/uploads/'.$site_settings['print_logo'] : (!empty($site_settings['site_logo']) ? '../assets/uploads/'.$site_settings['site_logo'] : '');

// معالجة الحفظ (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'])) {
    header('Content-Type: application/json');
    try {
        $name = $_POST['name'];
        $service_id = !empty($_POST['service_id']) ? $_POST['service_id'] : null;
        $template_type = $_POST['template_type'] ?? 'receipt';
        $paper_size = $_POST['paper_size'] ?? 'A5';
        $orientation = $_POST['orientation'] ?? 'portrait';
        $custom_width = !empty($_POST['custom_width']) ? $_POST['custom_width'] : null;
        $custom_height = !empty($_POST['custom_height']) ? $_POST['custom_height'] : null;
        $html_content = $_POST['html_content'] ?? '';
        $css_content = $_POST['css_content'] ?? '';
        $is_default = isset($_POST['is_default']) ? 1 : 0;
        $elements_json = $_POST['elements_json'] ?? '[]';

        if ($is_default && $service_id) {
            // إلغاء الافتراضي القديم لنفس الخدمة
            $stmt = $pdo->prepare("UPDATE voucher_templates SET is_default = 0 WHERE service_id = ?");
            $stmt->execute([$service_id]);
        }

        if ($template_id) {
            $stmt = $pdo->prepare("UPDATE voucher_templates SET name=?, service_id=?, template_type=?, paper_size=?, orientation=?, custom_width=?, custom_height=?, html_content=?, css_content=?, is_default=? WHERE id=?");
            $stmt->execute([$name, $service_id, $template_type, $paper_size, $orientation, $custom_width, $custom_height, $html_content, $css_content, $is_default, $template_id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO voucher_templates (name, service_id, template_type, paper_size, orientation, custom_width, custom_height, html_content, css_content, is_default, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $service_id, $template_type, $paper_size, $orientation, $custom_width, $custom_height, $html_content, $css_content, $is_default, $_SESSION['admin_id']]);
            $template_id = $pdo->lastInsertId();
        }

        // حفظ العناصر التفصيلية
        $pdo->prepare("DELETE FROM voucher_template_elements WHERE template_id = ?")->execute([$template_id]);
        $elements = json_decode($elements_json, true);
        if (is_array($elements)) {
            $stmt = $pdo->prepare("INSERT INTO voucher_template_elements (template_id, element_type, content, pos_x, pos_y, width, height, style_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($elements as $el) {
                $stmt->execute([
                    $template_id,
                    $el['type'],
                    $el['content'],
                    $el['x'],
                    $el['y'],
                    $el['width'] ?? null,
                    $el['height'] ?? null,
                    json_encode($el['style'] ?? [])
                ]);
            }
        }

        echo json_encode(['success' => true, 'id' => $template_id, 'message' => 'تم حفظ القالب بنجاح']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// جلب بيانات القالب للتعديل
$template = null;
$template_elements = [];
if ($template_id) {
    $stmt = $pdo->prepare("SELECT * FROM voucher_templates WHERE id = ?");
    $stmt->execute([$template_id]);
    $template = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT * FROM voucher_template_elements WHERE template_id = ?");
    $stmt->execute([$template_id]);
    $template_elements = $stmt->fetchAll();
}

require_once 'header.php';
?>

<!-- استدعاء مكتبة Interact.js للسحب والإفلات وتغيير الحجم -->
<script src="https://cdn.jsdelivr.net/npm/interactjs/dist/interact.min.js"></script>

<style>
    .designer-container { display: flex; height: calc(100vh - 120px); gap: 15px; background: #f4f6f9; padding: 15px; }
    .designer-sidebar { width: 350px; background: #fff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); display: flex; flex-direction: column; overflow: hidden; }
    .designer-main { flex: 1; background: #fff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); display: flex; flex-direction: column; position: relative; }
    
    .sidebar-body { flex: 1; overflow-y: auto; padding: 15px; }
    
    .canvas-area { flex: 1; background: #cbd5e1; padding: 50px; overflow: auto; display: flex; justify-content: center; align-items: flex-start; position: relative; }
    
    /* ورقة التصميم */
    .paper { 
        background: white; 
        box-shadow: 0 0 20px rgba(0,0,0,0.2); 
        position: relative; 
        transition: 0.3s; 
        transform-origin: top center;
        background-image: radial-gradient(#e2e8f0 1px, transparent 1px);
        background-size: 20px 20px;
    }
    
    .paper-A4.portrait { width: 210mm; height: 297mm; }
    .paper-A4.landscape { width: 297mm; height: 210mm; }
    .paper-A5.portrait { width: 148mm; height: 210mm; }
    .paper-A5.landscape { width: 210mm; height: 148mm; }
    .paper-Thermal { width: 80mm; min-height: 150mm; }
    .paper-Custom { border: 2px solid #2563eb; }

    /* عناصر السحب والإفلات */
    .draggable-element {
        position: absolute;
        padding: 5px 10px;
        border: 1px dashed transparent;
        cursor: move;
        user-select: none;
        touch-action: none;
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 20px;
        min-height: 10px;
        font-family: sans-serif;
        white-space: nowrap;
        box-sizing: border-box;
    }
    .draggable-element[data-type="line"] { padding: 0; min-width: 0; min-height: 0; }
    .draggable-element:hover { border-color: #3b82f6; background: rgba(59, 130, 246, 0.05); }
    .draggable-element.selected { border: 1.5px solid #2563eb; background: rgba(37, 99, 235, 0.1); box-shadow: 0 0 10px rgba(37, 99, 235, 0.2); }
    .draggable-element .resizer { width: 8px; height: 8px; background: #2563eb; position: absolute; right: -4px; bottom: -4px; cursor: nwse-resize; border-radius: 50%; display: none; }
    .draggable-element.selected .resizer { display: block; }
    .draggable-element .remove-el { position: absolute; top: -10px; left: -10px; background: #ef4444; color: white; width: 18px; height: 18px; border-radius: 50%; font-size: 10px; display: none; align-items: center; justify-content: center; cursor: pointer; }
    .draggable-element.selected .remove-el { display: flex; }

    .field-item { 
        cursor: grab; 
        padding: 10px 15px; 
        background: #f8fafc; 
        border: 1px solid #e2e8f0; 
        border-radius: 8px; 
        margin-bottom: 8px; 
        font-size: 13px; 
        font-weight: 600; 
        transition: 0.2s; 
        display: flex; 
        align-items: center; 
        gap: 10px;
    }
    .field-item:hover { background: #eff6ff; border-color: #3b82f6; color: #2563eb; }
    .field-item i { color: #94a3b8; font-size: 14px; }

    .control-tabs { display: flex; border-bottom: 1px solid #eee; background: #f8fafc; }
    .control-tab { flex: 1; padding: 12px 5px; text-align: center; cursor: pointer; font-weight: 700; font-size: 12px; color: #64748b; transition: 0.3s; border-bottom: 2px solid transparent; }
    .control-tab.active { color: #2563eb; border-bottom-color: #2563eb; background: #fff; }
    
    .designer-toolbar { padding: 12px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; background: #fff; border-radius: 12px 12px 0 0; }
    
    .zoom-controls { position: absolute; bottom: 25px; right: 25px; background: white; border-radius: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); display: flex; overflow: hidden; border: 1px solid #eee; z-index: 100; }
    .zoom-btn { padding: 10px 18px; border: none; background: transparent; cursor: pointer; transition: 0.2s; font-size: 14px; }
    .zoom-btn:hover { background: #f1f5f9; color: #2563eb; }

    /* Properties Panel */
    .prop-row { margin-bottom: 12px; }
    .prop-label { font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 5px; display: block; }
</style>

<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold m-0 text-primary"><i class="fas fa-drafting-compass me-2"></i> مصمم السحب والإفلات (Drag & Drop)</h4>
        <div class="d-flex gap-2">
            <button class="btn btn-primary px-4 fw-bold" onclick="saveTemplate()">
                <i class="fas fa-save me-2"></i> حفظ التصميم
            </button>
            <a href="settings.php" class="btn btn-outline-secondary px-3"><i class="fas fa-times"></i></a>
        </div>
    </div>

    <div class="designer-container">
        <!-- Sidebar: Items to Drag -->
        <div class="designer-sidebar">
            <div class="control-tabs">
                <div class="control-tab active" onclick="switchTab('items')">إضافة عناصر</div>
                <div class="control-tab" onclick="switchTab('props')">خصائص العنصر</div>
                <div class="control-tab" onclick="switchTab('page')">إعدادات الورقة</div>
            </div>
            
            <div class="sidebar-body">
                <!-- Tab: Items -->
                <div id="tab-items" class="tab-pane-custom">
                    <p class="text-muted small mb-3 text-center">اسحب العنصر وأفلته داخل الورقة</p>
                    
                    <div class="section-title small fw-bold mb-2 text-primary border-bottom pb-1">عناصر أساسية</div>
                    <div class="field-item" draggable="true" ondragstart="onDragStart(event, 'text', 'نص جديد')">
                        <i class="fas fa-font"></i> نص ثابت
                    </div>
                    <div class="field-item" draggable="true" ondragstart="onDragStart(event, 'image', '{{logo}}')">
                        <i class="fas fa-image"></i> الشعار
                    </div>
                    <div class="field-item" draggable="true" ondragstart="onDragStart(event, 'image', '{{stamp}}')">
                        <i class="fas fa-stamp"></i> الختم
                    </div>
                    <div class="field-item" draggable="true" ondragstart="onDragStart(event, 'line', 'line')">
                        <i class="fas fa-minus"></i> خط أفقي
                    </div>

                    <div class="section-title small fw-bold mt-4 mb-2 text-primary border-bottom pb-1">بيانات ديناميكية</div>
                    <div id="dynamic-fields">
                        <div class="field-item" draggable="true" ondragstart="onDragStart(event, 'field', '{{receipt_no}}')">
                            <i class="fas fa-hashtag"></i> رقم السند
                        </div>
                        <div class="field-item" draggable="true" ondragstart="onDragStart(event, 'field', '{{customer_name}}')">
                            <i class="fas fa-user"></i> اسم العميل
                        </div>
                        <div class="field-item" draggable="true" ondragstart="onDragStart(event, 'field', '{{amount}}')">
                            <i class="fas fa-money-bill-wave"></i> المبلغ
                        </div>
                        <div class="field-item" draggable="true" ondragstart="onDragStart(event, 'field', '{{receipt_date}}')">
                            <i class="fas fa-calendar-alt"></i> التاريخ
                        </div>
                        <div class="field-item" draggable="true" ondragstart="onDragStart(event, 'field', '{{service_name}}')">
                            <i class="fas fa-briefcase"></i> اسم الخدمة
                        </div>
                    </div>
                    
                    <div id="service-specific-fields" class="mt-3">
                        <!-- الحقول المرتبطة بالخدمة ستظهر هنا -->
                    </div>
                </div>

                <!-- Tab: Properties -->
                <div id="tab-props" class="tab-pane-custom d-none">
                    <div id="no-selection" class="text-center py-5 text-muted">
                        <i class="fas fa-mouse-pointer fa-2x mb-3"></i>
                        <p class="small">حدد عنصراً في الورقة لتعديل خصائصه</p>
                    </div>
                    <div id="props-panel" class="d-none">
                        <div class="prop-row">
                            <label class="prop-label">المحتوى / النص</label>
                            <input type="text" id="prop-text" class="form-control form-control-sm" oninput="updateSelectedElement()">
                        </div>
                        <div class="prop-row">
                            <label class="prop-label">حجم الخط (px)</label>
                            <input type="number" id="prop-font-size" class="form-control form-control-sm" value="16" oninput="updateSelectedElement()">
                        </div>
                        <div class="prop-row">
                            <label class="prop-label">لون النص</label>
                            <input type="color" id="prop-color" class="form-control form-control-color w-100" oninput="updateSelectedElement()">
                        </div>
                        <div class="prop-row">
                            <label class="prop-label">تنسيق الخط</label>
                            <div class="btn-group w-100">
                                <button class="btn btn-sm btn-outline-secondary" onclick="toggleStyle('bold')"><i class="fas fa-bold"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="toggleStyle('italic')"><i class="fas fa-italic"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="toggleStyle('underline')"><i class="fas fa-underline"></i></button>
                            </div>
                        </div>
                        <div class="prop-row">
                            <label class="prop-label">المحاذاة</label>
                            <div class="btn-group w-100">
                                <button class="btn btn-sm btn-outline-secondary" onclick="setAlignment('right')"><i class="fas fa-align-right"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="setAlignment('center')"><i class="fas fa-align-center"></i></button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="setAlignment('left')"><i class="fas fa-align-left"></i></button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Page Settings -->
                <div id="tab-page" class="tab-pane-custom d-none">
                    <div class="mb-3">
                        <label class="form-label small fw-bold">اسم القالب</label>
                        <input type="text" id="p_name" class="form-control" value="<?= htmlspecialchars($template['name'] ?? 'قالب سحب وإفلات') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">الخدمة المرتبطة</label>
                        <select id="p_service_id" class="form-select" onchange="loadServiceFields()">
                            <option value="">عام (لكافة الخدمات)</option>
                            <?php foreach($services as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= ($template['service_id'] ?? '') == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['service_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-bold">حجم الورق</label>
                            <select id="p_paper_size" class="form-select" onchange="updatePaper()">
                                <option value="A4" <?= ($template['paper_size'] ?? '') == 'A4' ? 'selected' : '' ?>>A4</option>
                                <option value="A5" <?= ($template['paper_size'] ?? 'A5') == 'A5' ? 'selected' : '' ?>>A5</option>
                                <option value="Thermal" <?= ($template['paper_size'] ?? '') == 'Thermal' ? 'selected' : '' ?>>حراري</option>
                                <option value="Custom" <?= ($template['paper_size'] ?? '') == 'Custom' ? 'selected' : '' ?>>مخصص</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-bold">الاتجاه</label>
                            <select id="p_orientation" class="form-select" onchange="updatePaper()">
                                <option value="portrait" <?= ($template['orientation'] ?? 'portrait') == 'portrait' ? 'selected' : '' ?>>عمودي</option>
                                <option value="landscape" <?= ($template['orientation'] ?? '') == 'landscape' ? 'selected' : '' ?>>أفقي</option>
                            </select>
                        </div>
                    </div>
                    <div id="custom-size-inputs" class="<?= ($template['paper_size'] ?? '') == 'Custom' ? '' : 'd-none' ?>">
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold">العرض (mm)</label>
                                <input type="number" id="p_custom_width" class="form-control" value="<?= $template['custom_width'] ?? '210' ?>" oninput="updatePaper()">
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold">الطول (mm)</label>
                                <input type="number" id="p_custom_height" class="form-control" value="<?= $template['custom_height'] ?? '297' ?>" oninput="updatePaper()">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Designer Main Area -->
        <div class="designer-main">
            <div class="canvas-area" id="drop-zone" ondragover="onDragOver(event)" ondrop="onDrop(event)">
                <div id="paper" class="paper paper-A5 portrait">
                    <!-- العناصر ستظهر هنا -->
                </div>
            </div>

            <div class="zoom-controls">
                <button class="zoom-btn" onclick="zoomCanvas(-0.1)"><i class="fas fa-search-minus"></i></button>
                <button class="zoom-btn fw-bold" id="zoom-level">80%</button>
                <button class="zoom-btn" onclick="zoomCanvas(0.1)"><i class="fas fa-search-plus"></i></button>
            </div>
        </div>
    </div>
</div>

<script>
    let currentZoom = 0.8;
    let selectedElement = null;

    function switchTab(tabId) {
        document.querySelectorAll('.control-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.tab-pane-custom').forEach(p => p.classList.add('d-none'));
        event.currentTarget.classList.add('active');
        document.getElementById('tab-' + tabId).classList.remove('d-none');
    }

    function zoomCanvas(delta) {
        currentZoom = Math.max(0.3, Math.min(1.5, currentZoom + delta));
        document.getElementById('paper').style.transform = `scale(${currentZoom})`;
        document.getElementById('zoom-level').innerText = Math.round(currentZoom * 100) + '%';
    }

    function updatePaper() {
        const size = document.getElementById('p_paper_size').value;
        const orient = document.getElementById('p_orientation').value;
        const paper = document.getElementById('paper');
        
        if (size === 'Custom') {
            document.getElementById('custom-size-inputs').classList.remove('d-none');
            const w = document.getElementById('p_custom_width').value || 210;
            const h = document.getElementById('p_custom_height').value || 297;
            paper.style.width = w + 'mm';
            paper.style.height = h + 'mm';
            paper.className = `paper paper-Custom`;
        } else {
            document.getElementById('custom-size-inputs').classList.add('d-none');
            paper.style.width = '';
            paper.style.height = '';
            paper.className = `paper paper-${size} ${orient}`;
        }
    }

    // Drag and Drop Logic
    function onDragStart(event, type, content) {
        event.dataTransfer.setData('type', type);
        event.dataTransfer.setData('content', content);
    }

    function onDragOver(event) {
        event.preventDefault();
    }

    function onDrop(event) {
        event.preventDefault();
        const type = event.dataTransfer.getData('type');
        const content = event.dataTransfer.getData('content');
        
        const paper = document.getElementById('paper');
        const rect = paper.getBoundingClientRect();
        
        // حساب الإحداثيات بالنسبة للورقة مع مراعاة الزووم
        const x = (event.clientX - rect.left) / currentZoom;
        const y = (event.clientY - rect.top) / currentZoom;

        addElement(type, content, x, y, null, null, {}, true);
    }

    function addElement(type, content, x, y, width = null, height = null, style = {}, shouldSelect = true) {
        const el = document.createElement('div');
        el.className = 'draggable-element';
        el.dataset.type = type;
        el.style.position = 'absolute';
        el.style.left = x + 'px';
        el.style.top = y + 'px';
        el.style.zIndex = document.querySelectorAll('.draggable-element').length + 1;
        
        if (width && !isNaN(width) && width > 0) el.style.width = width + 'px';
        if (height && !isNaN(height) && height > 0) el.style.height = height + 'px';
        
        // تطبيق التنسيقات المخزنة
        if (style && typeof style === 'object') {
            for (const [prop, value] of Object.entries(style)) {
                if (value !== undefined && value !== null && value !== '') {
                    el.style[prop] = value;
                }
            }
        }

        // المحتوى بناءً على النوع
        let contentHtml = '';
        const logoUrl = '<?= $logo_url ?>';
        
        if (content === '{{logo}}') {
            if (logoUrl) {
                contentHtml = `<img src="${logoUrl}" style="width:100%;height:100%;object-fit:contain;">`;
            } else {
                contentHtml = '<div style="width:100%;height:100%;background:#f1f5f9;border:1px solid #ddd;display:flex;align-items:center;justify-content:center;border-radius:50%;font-size:10px;">LOGO</div>';
            }
        } else if (content === '{{stamp}}') {
            contentHtml = '<div style="width:100%;height:100%;border:3px double #ef4444;color:#ef4444;display:flex;align-items:center;justify-content:center;border-radius:50%;opacity:0.4;font-weight:bold;transform:rotate(-15deg);">STAMP</div>';
        } else if (type === 'line') {
            el.style.width = el.style.width || '200px';
            el.style.height = el.style.height || '2px';
            el.style.background = el.style.background || '#000';
            contentHtml = '';
        } else {
            contentHtml = content;
        }

        el.innerHTML = contentHtml + '<div class="resizer"></div><div class="remove-el" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></div>';
        el.dataset.content = content;

        el.onclick = (e) => {
            e.stopPropagation();
            selectElement(el);
        };

        document.getElementById('paper').appendChild(el);
        try {
            makeDraggable(el);
        } catch (e) {
            console.error("Interact.js error:", e);
        }
        
        if (shouldSelect) selectElement(el);
        return el;
    }

    function makeDraggable(el) {
        interact(el)
            .draggable({
                inertia: true,
                modifiers: [
                    interact.modifiers.restrictRect({
                        restriction: 'parent',
                        endOnly: true
                    })
                ],
                listeners: {
                    move(event) {
                        const target = event.target;
                        const x = (parseFloat(target.style.left) || 0) + event.dx / currentZoom;
                        const y = (parseFloat(target.style.top) || 0) + event.dy / currentZoom;

                        target.style.left = x + 'px';
                        target.style.top = y + 'px';
                    }
                }
            })
            .resizable({
                edges: { right: true, bottom: true, left: true, top: true },
                listeners: {
                    move(event) {
                        const target = event.target;
                        let x = (parseFloat(target.style.left) || 0);
                        let y = (parseFloat(target.style.top) || 0);

                        target.style.width = event.rect.width / currentZoom + 'px';
                        target.style.height = event.rect.height / currentZoom + 'px';

                        x += event.deltaRect.left / currentZoom;
                        y += event.deltaRect.top / currentZoom;

                        target.style.left = x + 'px';
                        target.style.top = y + 'px';
                    }
                }
            });
    }

    function selectElement(el) {
        if (selectedElement) selectedElement.classList.remove('selected');
        selectedElement = el;
        selectedElement.classList.add('selected');

        // تحديث لوحة الخصائص
        document.getElementById('no-selection').classList.add('d-none');
        document.getElementById('props-panel').classList.remove('d-none');
        
        document.getElementById('prop-text').value = el.dataset.content;
        document.getElementById('prop-font-size').value = parseInt(window.getComputedStyle(el).fontSize);
        
        switchTab('props');
    }

    function updateSelectedElement() {
        if (!selectedElement) return;
        const text = document.getElementById('prop-text').value;
        const fontSize = document.getElementById('prop-font-size').value;
        const color = document.getElementById('prop-color').value;

        if (selectedElement.dataset.type !== 'image' && selectedElement.dataset.type !== 'line') {
            selectedElement.innerText = text;
            // إعادة إضافة المقابض لأن innerText يحذفها
            selectedElement.innerHTML += '<div class="resizer"></div><div class="remove-el" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></div>';
        }
        
        selectedElement.dataset.content = text;
        selectedElement.style.fontSize = fontSize + 'px';
        selectedElement.style.color = color;
    }

    function setAlignment(align) {
        if (selectedElement) selectedElement.style.textAlign = align;
    }

    function toggleStyle(style) {
        if (!selectedElement) return;
        if (style === 'bold') selectedElement.style.fontWeight = selectedElement.style.fontWeight === 'bold' ? 'normal' : 'bold';
        if (style === 'italic') selectedElement.style.fontStyle = selectedElement.style.fontStyle === 'italic' ? 'normal' : 'italic';
        if (style === 'underline') selectedElement.style.textDecoration = selectedElement.style.textDecoration === 'underline' ? 'none' : 'underline';
    }

    // تنظيف الورقة عند النقر على مساحة فارغة
    document.getElementById('drop-zone').onclick = () => {
        if (selectedElement) selectedElement.classList.remove('selected');
        selectedElement = null;
        document.getElementById('no-selection').classList.remove('d-none');
        document.getElementById('props-panel').classList.add('d-none');
    };

    function saveTemplate() {
        const paper = document.getElementById('paper');
        const elements = [];
        
        // التحقق من الحجم الفعلي للورقة بالبكسل للتحويل
        const paperRect = paper.getBoundingClientRect();
        const paperWidthPx = paperRect.width / currentZoom;
        const paperHeightPx = paperRect.height / currentZoom;
        
        paper.querySelectorAll('.draggable-element').forEach(el => {
            elements.push({
                type: el.dataset.type,
                content: el.dataset.content,
                x: parseFloat(el.style.left),
                y: parseFloat(el.style.top),
                width: parseFloat(el.style.width),
                height: parseFloat(el.style.height),
                style: {
                    fontSize: el.style.fontSize,
                    color: el.style.color,
                    fontWeight: el.style.fontWeight,
                    fontStyle: el.style.fontStyle,
                    textDecoration: el.style.textDecoration,
                    textAlign: el.style.textAlign,
                    background: el.style.background,
                    border: el.style.border,
                    borderRadius: el.style.borderRadius,
                    padding: el.style.padding,
                    whiteSpace: el.style.whiteSpace,
                    zIndex: el.style.zIndex
                }
            });
        });

        // تحويل العناصر إلى HTML نهائي للطباعة
        let finalHtml = `<div style="position:relative; width:${paperWidthPx}px; height:${paperHeightPx}px; direction:rtl; font-family:Arial, sans-serif; overflow:hidden;">`;
        elements.forEach(e => {
            let content = e.content;
            let styleStr = `position:absolute; left:${e.x}px; top:${e.y}px;`;
            
            if (e.width) styleStr += `width:${e.width}px;`;
            if (e.height) styleStr += `height:${e.height}px;`;
            
            for (const [prop, val] of Object.entries(e.style)) {
                if (val) {
                    // تحويل camelCase إلى kebab-case لـ CSS
                    const cssProp = prop.replace(/([A-Z])/g, "-$1").toLowerCase();
                    styleStr += `${cssProp}:${val};`;
                }
            }

            if (content === '{{logo}}') {
                finalHtml += `<div style="${styleStr}">{{logo}}</div>`;
            } else if (content === '{{stamp}}') {
                finalHtml += `<div style="${styleStr}">{{stamp}}</div>`;
            } else if (e.type === 'line') {
                finalHtml += `<div style="${styleStr}"></div>`;
            } else {
                finalHtml += `<div style="${styleStr}">${content}</div>`;
            }
        });
        finalHtml += `</div>`;

        const formData = new FormData();
        formData.append('save_template', '1');
        formData.append('name', document.getElementById('p_name').value);
        formData.append('service_id', document.getElementById('p_service_id').value);
        formData.append('paper_size', document.getElementById('p_paper_size').value);
        formData.append('orientation', document.getElementById('p_orientation').value);
        formData.append('custom_width', document.getElementById('p_custom_width').value);
        formData.append('custom_height', document.getElementById('p_custom_height').value);
        formData.append('html_content', finalHtml);
        formData.append('elements_json', JSON.stringify(elements));
        formData.append('css_content', '/* Drag & Drop Template */');
        formData.append('is_default', '1');

        fetch('voucher_designer.php' + (window.location.search || ''), {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                Swal.fire('تم الحفظ', 'تم حفظ تصميم السند بنجاح', 'success');
            }
        });
    }

    function loadServiceFields() {
        const serviceId = document.getElementById('p_service_id').value;
        const container = document.getElementById('service-specific-fields');
        container.innerHTML = '';

        if (!serviceId) return;

        // حقول افتراضية حسب نوع الخدمة (يمكن توسيعها من قاعدة البيانات لاحقاً)
        let fields = [
            { tag: '{{passport_no}}', label: 'رقم الجواز', icon: 'fa-id-card' },
            { tag: '{{visa_number}}', label: 'رقم التأشيرة', icon: 'fa-stamp' },
            { tag: '{{travel_date}}', label: 'تاريخ السفر', icon: 'fa-plane-departure' }
        ];

        // إضافة عنوان
        container.innerHTML = '<div class="section-title small fw-bold mb-2 text-success border-bottom pb-1">حقول الخدمة</div>';
        
        fields.forEach(f => {
            const div = document.createElement('div');
            div.className = 'field-item';
            div.draggable = true;
            div.innerHTML = `<i class="fas ${f.icon}"></i> ${f.label}`;
            div.ondragstart = (e) => onDragStart(e, 'field', f.tag);
            container.appendChild(div);
        });
    }

    window.onload = () => {
        updatePaper();
        zoomCanvas(0);
        loadServiceFields();

        // تحميل العناصر إذا كانت موجودة
        <?php if (!empty($template_elements)): ?>
            const savedElements = <?= json_encode($template_elements, JSON_UNESCAPED_UNICODE) ?>;
            console.log("Loading elements:", savedElements);
            savedElements.forEach(el => {
                try {
                    const style = JSON.parse(el.style_json || '{}');
                    const x = parseFloat(el.pos_x) || 0;
                    const y = parseFloat(el.pos_y) || 0;
                    const w = el.width ? parseFloat(el.width) : null;
                    const h = el.height ? parseFloat(el.height) : null;
                    
                    addElement(el.element_type, el.content, x, y, w, h, style, false);
                } catch (e) {
                    console.error("Error loading element:", el, e);
                }
            });
        <?php endif; ?>
    };
</script>

<?php require_once 'footer.php'; ?>

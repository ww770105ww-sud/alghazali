<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/functions.php';

function verify_request() {
    if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
        return false;
    }
    return true;
}

function normalize_requirement_entries($items): array
{
    if (!is_array($items)) {
        return [];
    }

    $normalized = [];
    foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item === '') {
            continue;
        }
        $normalized[] = $item;
    }

    return array_values(array_unique($normalized));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Get Profession Details (for edit modal)
if ($action === 'get_profession') {
    $id = !empty($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id) {
        echo json_encode(null);
        exit();
    }

    $prof = $pdo->prepare("SELECT * FROM professions WHERE id = ?");
    $prof->execute([$id]);
    $data = $prof->fetch(PDO::FETCH_ASSOC);

    if ($data) {
        $reqs = $pdo->prepare("SELECT id, requirement_name, COALESCE(gender, 'both') AS gender FROM profession_requirements WHERE profession_id = ? ORDER BY id ASC");
        $reqs->execute([$id]);
        $requirements = $reqs->fetchAll(PDO::FETCH_ASSOC);
        $data['requirements'] = $requirements;
        $data['requirements_general'] = array_values(array_map(
            static fn($row) => $row['requirement_name'],
            array_filter($requirements, static fn($row) => ($row['gender'] ?? 'both') === 'both')
        ));
        $data['requirements_male'] = array_values(array_map(
            static fn($row) => $row['requirement_name'],
            array_filter($requirements, static fn($row) => ($row['gender'] ?? 'both') === 'male')
        ));
        $data['requirements_female'] = array_values(array_map(
            static fn($row) => $row['requirement_name'],
            array_filter($requirements, static fn($row) => ($row['gender'] ?? 'both') === 'female')
        ));

        $rules = $pdo->prepare("SELECT * FROM work_visa_rules WHERE profession_id = ? LIMIT 1");
        $rules->execute([$id]);
        $data['rules'] = $rules->fetch(PDO::FETCH_ASSOC);
    }

    echo json_encode($data);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && verify_request()) {
    // Save Profession (with requirements and rules in transaction)
    if ($action === 'save_profession') {
        // Verify CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF token invalid']);
            exit();
        }

        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : null;
            $name_ar = $_POST['name_ar'] ?? '';
            $name_en = $_POST['name_en'] ?? '';
            $code = $_POST['code'] ?? '';
            $status = $_POST['status'] ?? 'active';
            $requirements_general = normalize_requirement_entries($_POST['requirements_general'] ?? ($_POST['requirements'] ?? []));
            $requirements_male = normalize_requirement_entries($_POST['requirements_male'] ?? []);
            $requirements_female = normalize_requirement_entries($_POST['requirements_female'] ?? []);
            $min_age = !empty($_POST['min_age']) ? intval($_POST['min_age']) : 18;
            $max_age = !empty($_POST['max_age']) ? intval($_POST['max_age']) : 60;
            $min_passport_validity = !empty($_POST['min_passport_validity']) ? intval($_POST['min_passport_validity']) : 6;
            $allowed_nationalities = $_POST['allowed_nationalities'] ?? '';

            if (empty($name_ar)) {
                echo json_encode(['status' => 'error', 'message' => 'اسم المهنة بالعربية مطلوب']);
                exit();
            }

            $pdo->beginTransaction();

            if ($id) {
                $stmt = $pdo->prepare("UPDATE professions SET name_ar=?, name_en=?, code=?, status=? WHERE id=?");
                $stmt->execute([$name_ar, $name_en, $code, $status, $id]);

                // Delete old requirements and rules
                $pdo->prepare("DELETE FROM profession_requirements WHERE profession_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM work_visa_rules WHERE profession_id = ?")->execute([$id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO professions (name_ar, name_en, code, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name_ar, $name_en, $code, $status]);
                $id = $pdo->lastInsertId();
            }

            // Insert new requirements
            $requirementSets = [
                'both' => $requirements_general,
                'male' => $requirements_male,
                'female' => $requirements_female,
            ];
            foreach ($requirementSets as $gender => $requirements) {
                foreach ($requirements as $req) {
                    $stmt = $pdo->prepare("INSERT INTO profession_requirements (profession_id, requirement_name, gender) VALUES (?, ?, ?)");
                    $stmt->execute([$id, $req, $gender]);
                }
            }

            // Insert/Update rules
            $stmt = $pdo->prepare("INSERT INTO work_visa_rules (profession_id, min_age, max_age, min_passport_validity_months, allowed_nationalities) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id, $min_age, $max_age, $min_passport_validity, $allowed_nationalities]);

            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => 'تم حفظ المهنة']);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit();
        }
    }

    // Delete Profession
    if ($action === 'delete_profession') {
        // Verify CSRF
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            echo json_encode(['status' => 'error', 'message' => 'CSRF token invalid']);
            exit();
        }

        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'معرف المهنة مطلوب']);
                exit();
            }

            // Check if profession has linked requirements first
            $check = $pdo->prepare("SELECT COUNT(*) FROM profession_requirements WHERE profession_id = ?");
            $check->execute([$id]);
            if ($check->fetchColumn() > 0) {
                echo json_encode(['status' => 'warning', 'message' => 'لا يمكن حذف المهنة لأنها تحتوي على متطلبات مرتبطة']);
                exit();
            }

            $stmt = $pdo->prepare("DELETE FROM professions WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'تم الحذف بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Save Profession Rule
    if ($action === 'save_profession_rule') {
        try {
            $profession_id = !empty($_POST['profession_id']) ? intval($_POST['profession_id']) : 0;
            $min_age = !empty($_POST['min_age']) ? intval($_POST['min_age']) : 18;
            $max_age = !empty($_POST['max_age']) ? intval($_POST['max_age']) : 65;
            $min_passport_validity = !empty($_POST['min_passport_validity']) ? intval($_POST['min_passport_validity']) : 6;

            if (!$profession_id) {
                echo json_encode(['status' => 'error', 'message' => 'معرف المهنة مطلوب']);
                exit();
            }

            $check = $pdo->prepare("SELECT id FROM work_visa_rules WHERE profession_id = ? LIMIT 1");
            $check->execute([$profession_id]);
            $existing = $check->fetch();

            if ($existing) {
                $stmt = $pdo->prepare("UPDATE work_visa_rules SET min_age=?, max_age=?, min_passport_validity_months=? WHERE profession_id=?");
                $stmt->execute([$min_age, $max_age, $min_passport_validity, $profession_id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO work_visa_rules (profession_id, min_age, max_age, min_passport_validity_months) VALUES (?, ?, ?, ?)");
                $stmt->execute([$profession_id, $min_age, $max_age, $min_passport_validity]);
            }

            echo json_encode(['status' => 'success', 'message' => 'تم حفظ القواعد بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Save Profession Requirement
    if ($action === 'save_profession_requirement') {
        try {
            $profession_id = !empty($_POST['profession_id']) ? intval($_POST['profession_id']) : 0;
            $requirement_name_ar = $_POST['requirement_name_ar'] ?? '';
            $requirement_name_en = $_POST['requirement_name_en'] ?? '';
            $is_required = isset($_POST['is_required']) ? 1 : 0;
            $gender = $_POST['gender'] ?? 'both';

            if (!$profession_id || empty($requirement_name_ar)) {
                echo json_encode(['status' => 'error', 'message' => 'معرف المهنة و اسم المتطلب مطلوب']);
                exit();
            }

            $stmt = $pdo->prepare("INSERT INTO profession_requirements (profession_id, requirement_name, gender) VALUES (?, ?, ?)");
            $stmt->execute([$profession_id, $requirement_name_ar, in_array($gender, ['both', 'male', 'female'], true) ? $gender : 'both']);
            echo json_encode(['status' => 'success', 'message' => 'تم إضافة المتطلب بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }

    // Delete Profession Requirement
    if ($action === 'delete_profession_requirement') {
        try {
            $id = !empty($_POST['id']) ? intval($_POST['id']) : 0;
            if (!$id) {
                echo json_encode(['status' => 'error', 'message' => 'معرف المتطلب مطلوب']);
                exit();
            }
            $stmt = $pdo->prepare("DELETE FROM profession_requirements WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['status' => 'success', 'message' => 'تم حذف المتطلب بنجاح']);
            exit();
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'حدث خطأ: ' . $e->getMessage()]);
            exit();
        }
    }
}

echo json_encode(['status' => 'error', 'message' => 'طلب غير صالح']);
?>

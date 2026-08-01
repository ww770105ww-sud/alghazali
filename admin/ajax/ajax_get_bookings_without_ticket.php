<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode([]); exit;
}

$is_admin  = in_array(($_SESSION['role'] ?? 'employee'), ['admin','developer'], true);
$branch_id = (int)($_SESSION['branch_id'] ?? 0);

try {
    $where = [
        "(bfb.cancel_datetime IS NULL OR bfb.id NOT IN (SELECT booking_id FROM booking_refunds WHERE status IN ('completed','pending','instant')))",
        "bfb.id NOT IN (SELECT booking_id FROM booking_tickets WHERE is_void = 0)"
    ];
    $params = [];
    if ($branch_id > 0 && !$is_admin) { $where[] = "bfb.branch_id = ?"; $params[] = $branch_id; }
    $whereSQL = implode(' AND ', $where);

    $rows = $pdo->prepare("
        SELECT bfb.id, bfb.booking_number, bfb.traveler_name, bfb.service_type, bfb.total_amount,
               fc.city_name AS from_city, tc.city_name AS to_city,
               DATE_FORMAT(bfb.departure_date, '%Y-%m-%d') AS departure_date
          FROM bus_flight_bookings bfb
          LEFT JOIN cities fc ON fc.id = bfb.from_city_id
          LEFT JOIN cities tc ON tc.id = bfb.to_city_id
         WHERE $whereSQL
         ORDER BY bfb.id DESC
         LIMIT 300
    ");
    $rows->execute($params);
    echo json_encode($rows->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

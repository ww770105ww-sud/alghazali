<?php

require_once __DIR__ . '/../FinanceService.php';

class BookingCreatorService
{
    /** @var PDO */
    private $pdo;

    /** @var int */
    private $userId;

    /** @var FinanceService */
    private $financeService;

    /** @var BaseBookingService */
    private $bookingModule;

    public function __construct(PDO $pdo, int $userId, BaseBookingService $bookingModule)
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->bookingModule = $bookingModule;
        $this->financeService = new FinanceService($pdo, $userId);
    }

    public function createBooking(array $bookingData, array $financeData): array
    {
        return $this->financeService->executeAtomically(function () use ($bookingData, $financeData) {
            $bookingNumber = generateBookingNumber($bookingData['service_type']);
            $initialStatusId = $this->resolveInitialStatusId();
            $description = $this->resolveDescription($bookingData);

            $branchId = $bookingData['branch_id'];
            if ($branchId === null) {
                $branchId = $this->pdo->query("SELECT id FROM branches LIMIT 1")->fetchColumn() ?: null;
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO bus_flight_bookings (
                    booking_number, traveler_name, mobile_number, date_of_birth, place_of_birth, gender, nationality_id,
                    id_type, id_number, id_issue_place, id_issue_date, booking_date, service_type,
                    bus_type, trip_type, from_city_id, to_city_id, departure_date, return_date,
                    supplier_type, supplier_id, customer_id, account_id, notes, created_by,
                    status_id, description, branch_id, agent_id, operation_date
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?
                )
            ");

            $stmt->execute([
                $bookingNumber,
                $bookingData['traveler_name'],
                $bookingData['mobile_number'],
                $bookingData['date_of_birth'],
                $bookingData['place_of_birth'],
                $bookingData['gender'],
                $bookingData['nationality_id'],
                $bookingData['id_type'],
                $bookingData['id_number'],
                $bookingData['id_issue_place'],
                $bookingData['id_issue_date'],
                $bookingData['booking_date'],
                $bookingData['service_type'],
                $bookingData['bus_type'],
                $bookingData['trip_type'],
                $bookingData['from_city_id'],
                $bookingData['to_city_id'],
                $bookingData['departure_date'],
                $bookingData['return_date'],
                'agent',
                $bookingData['supplier_id'],
                $bookingData['customer_id'],
                $bookingData['account_id'],
                $bookingData['notes'],
                $this->userId,
                $initialStatusId,
                $description,
                $branchId,
                $bookingData['agent_id'],
                $bookingData['operation_date'],
            ]);

            $bookingId = (int)$this->pdo->lastInsertId();

            $financeResults = $this->financeService->processServiceOperation([
                'source_type' => $this->bookingModule->getFinanceSourceType(),
                'source_id' => $bookingId,
                'source_number' => $bookingNumber,
                'branch_id' => $branchId,
                'customer_id' => $bookingData['customer_id'],
                'agent_id' => $bookingData['agent_id'],
                'supplier_id' => $bookingData['supplier_id'],
                'sale_price' => $financeData['sale_price'],
                'discount' => $financeData['discount'],
                'purchase_price' => $financeData['purchase_price'],
                'sale_currency_id' => $financeData['sale_currency_id'],
                'pur_currency_id' => $financeData['purchase_currency_id'],
                'exchange_rate' => $financeData['exchange_rate'],
                'amount_received' => $financeData['amount_received'],
                'payment_account_id' => $bookingData['account_id'],
                'delivery_type' => $financeData['delivery_type'],
                'description' => "حجز " . $this->bookingModule->getBookingLabel() . " للمسافر: " . $bookingData['traveler_name'] . " - رقم الحجز " . $bookingNumber,
                'operation_date' => $bookingData['operation_date'],
            ]);

            $this->pdo->prepare("
                UPDATE bus_flight_bookings
                SET invoice_id = ?, sales_invoice_id = ?, purchase_invoice_id = ?, auto_invoice_generated = 1
                WHERE id = ?
            ")->execute([
                $financeResults['sales_invoice_id'],
                $financeResults['sales_invoice_id'],
                $financeResults['purchase_invoice_id'] ?? null,
                $bookingId,
            ]);

            return [
                'booking_id' => $bookingId,
                'booking_number' => $bookingNumber,
                'description' => $description,
                'sales_invoice_id' => $financeResults['sales_invoice_id'] ?? null,
                'purchase_invoice_id' => $financeResults['purchase_invoice_id'] ?? null,
            ];
        });
    }

    private function resolveInitialStatusId(): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM statuses WHERE status_name = 'حجز جديد' LIMIT 1");
        $stmt->execute();
        $statusId = $stmt->fetchColumn();

        if ($statusId) {
            return (int)$statusId;
        }

        $stmt = $this->pdo->prepare("INSERT INTO statuses (status_name, status_color) VALUES ('حجز جديد', 'primary')");
        $stmt->execute();

        return (int)$this->pdo->lastInsertId();
    }

    private function resolveDescription(array $bookingData): string
    {
        if (!empty($bookingData['description'])) {
            return (string)$bookingData['description'];
        }

        $fromCityName = $this->fetchCityName((int)$bookingData['from_city_id']);
        $toCityName = $this->fetchCityName((int)$bookingData['to_city_id']);

        return "حجز تذكرة من {$fromCityName} إلى {$toCityName} للمسافر {$bookingData['traveler_name']}";
    }

    private function fetchCityName(int $cityId): string
    {
        $stmt = $this->pdo->prepare("SELECT city_name FROM cities WHERE id = ?");
        $stmt->execute([$cityId]);

        return (string)($stmt->fetchColumn() ?: '');
    }
}

<?php

class BookingValidator
{
    /** @var PDO */
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function validateCreate(array $data): array
    {
        $errors = [];
        $today = date('Y-m-d');

        if (($data['delivery_type'] ?? null) === 'credit' && empty($data['customer_id'])) {
            $errors[] = 'يجب اختيار العميل (الحساب) في حالة الدفع الآجل.';
        }

        if (($data['from_city_id'] ?? null) === ($data['to_city_id'] ?? null)) {
            $errors[] = 'يجب أن تكون مدينة المغادرة مختلفة عن مدينة الوصول.';
        }

        if (!empty($data['departure_date']) && $data['departure_date'] < date('Y-m-d')) {
            $errors[] = 'تاريخ المغادرة لا يمكن أن يكون في الماضي.';
        }
        if (!empty($data['booking_date']) && $data['booking_date'] < $today) {
            $errors[] = 'تاريخ الحجز لا يمكن أن يكون في الماضي.';
        }
        if (!empty($data['date_of_birth']) && $data['date_of_birth'] > $today) {
            $errors[] = 'تاريخ الميلاد لا يمكن أن يكون بعد تاريخ اليوم.';
        }
        if (!empty($data['id_issue_date']) && $data['id_issue_date'] < $today) {
            $errors[] = 'تاريخ الإصدار يجب أن يكون من تاريخ اليوم أو بعده.';
        }

        if (($data['purchase_price'] ?? 0) > ($data['sale_price'] ?? 0)) {
            $errors[] = 'تنبيه: سعر الشراء لا يمكن أن يكون أكبر من سعر البيع.';
        }

        if (($data['amount_received'] ?? 0) > ($data['sale_price'] ?? 0)) {
            $errors[] = 'المبلغ الموصل لا يمكن أن يكون أكبر من سعر البيع.';
        }

        if (empty($data['traveler_name'])) {
            $errors[] = 'اسم المسافر مطلوب.';
        }
        if (empty($data['mobile_number'])) {
            $errors[] = 'رقم الجوال مطلوب.';
        }
        if (empty($data['booking_date'])) {
            $errors[] = 'تاريخ الحجز مطلوب.';
        }
        if (empty($data['operation_date'])) {
            $errors[] = 'تاريخ العملية مطلوب.';
        }
        if (empty($data['gender'])) {
            $errors[] = 'الجنس مطلوب.';
        }
        if (empty($data['service_type'])) {
            $errors[] = 'نوع الخدمة مطلوب.';
        }
        if (empty($data['trip_type'])) {
            $errors[] = 'نوع الرحلة مطلوب.';
        }
        if (empty($data['from_city_id'])) {
            $errors[] = 'مدينة المغادرة مطلوبة.';
        }
        if (empty($data['to_city_id'])) {
            $errors[] = 'مدينة الوصول مطلوبة.';
        }
        if (empty($data['departure_date'])) {
            $errors[] = 'تاريخ المغادرة مطلوب.';
        }
        if (($data['trip_type'] ?? null) === 'round_trip' && empty($data['return_date'])) {
            $errors[] = 'تاريخ العودة مطلوب لرحلة الذهاب والعودة.';
        }
        if (empty($data['supplier_id'])) {
            $errors[] = 'المورد مطلوب.';
        }
        if (empty($data['branch_id'])) {
            $errors[] = 'الفرع مطلوب.';
        }
        // تم التحقق من العميل للدفع الآجل أعلاه
        if (empty($data['purchase_currency_id'])) {
            $errors[] = 'عملة الشراء مطلوبة.';
        }

        if (($data['sale_price'] ?? false) === false || (float)$data['sale_price'] < 0) {
            $errors[] = 'سعر البيع غير صحيح.';
        }
        if (($data['purchase_price'] ?? false) === false || (float)$data['purchase_price'] < 0) {
            $errors[] = 'سعر الشراء غير صحيح.';
        }

        $netSale = (float)($data['sale_price'] ?? 0) - (float)($data['discount'] ?? 0);
        if ($netSale < 0) {
            $errors[] = 'الخصم لا يمكن أن يكون أكبر من سعر البيع.';
        }

        if (($data['amount_received'] ?? false) === false || (float)$data['amount_received'] < 0) {
            $errors[] = 'المبلغ الموصل غير صحيح.';
        }
        if ((float)($data['amount_received'] ?? 0) > $netSale) {
            $errors[] = 'يجب أن يكون المبلغ الموصل أقل من أو يساوي صافي سعر البيع.';
        }

        if (!in_array($data['delivery_type'] ?? null, ['cash', 'bank_transfer'], true) && (float)($data['amount_received'] ?? 0) > 0) {
            $errors[] = 'المبلغ الموصل متاح فقط في حالة الدفع النقدي.';
        }

        if (empty($data['delivery_type'])) {
            $errors[] = 'نوع التوصيل مطلوب.';
        }
        if (in_array($data['delivery_type'] ?? null, ['cash', 'bank_transfer'], true) && empty($data['account_id'])) {
            $errors[] = 'الحساب مطلوب لنوع التوصيل المحدد.';
        }

        if (!empty($data['account_id']) && (float)($data['amount_received'] ?? 0) > 0) {
            $stmt = $this->pdo->prepare("SELECT id FROM unified_accounts WHERE id = ?");
            $stmt->execute([$data['account_id']]);
            $stmt->fetchColumn();
        }

        return $errors;
    }

    public function validateUpdate(array $data): array
    {
        $errors = [];
        $today = date('Y-m-d');

        if (!empty($data['booking_date']) && $data['booking_date'] < $today) {
            $errors[] = 'تاريخ الحجز لا يمكن أن يكون في الماضي.';
        }
        if (!empty($data['date_of_birth']) && $data['date_of_birth'] > $today) {
            $errors[] = 'تاريخ الميلاد لا يمكن أن يكون بعد تاريخ اليوم.';
        }
        if (!empty($data['id_issue_date']) && $data['id_issue_date'] < $today) {
            $errors[] = 'تاريخ الإصدار يجب أن يكون من تاريخ اليوم أو بعده.';
        }
        if (!empty($data['departure_date']) && $data['departure_date'] < $today) {
            $errors[] = 'تاريخ المغادرة لا يمكن أن يكون في الماضي.';
        }
        if (($data['from_city_id'] ?? null) === ($data['to_city_id'] ?? null)) {
            $errors[] = 'يجب أن تكون مدينة المغادرة مختلفة عن مدينة الوصول.';
        }

        return $errors;
    }
}

<?php
require_once __DIR__ . '/BaseBookingService.php';

class BusBookingService extends BaseBookingService
{
    public function getServiceType(): ?string
    {
        return 'bus';
    }

    public function getPageTitle(): string
    {
        return 'إدارة حجوزات الباصات';
    }

    public function getPageDescription(): string
    {
        return 'عرض وإدارة حجوزات الباصات فقط داخل النظام';
    }

    public function getFinanceSourceType(): string
    {
        return 'حجوزات الباصات';
    }

    public function getBookingLabel(): string
    {
        return 'باص';
    }

    public function getPageIcon(): string
    {
        return 'fas fa-bus';
    }
}

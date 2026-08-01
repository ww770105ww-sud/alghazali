<?php
require_once __DIR__ . '/BaseBookingService.php';

class FlightBookingService extends BaseBookingService
{
    public function getServiceType(): ?string
    {
        return 'flight';
    }

    public function getPageTitle(): string
    {
        return 'إدارة حجوزات الطيران';
    }

    public function getPageDescription(): string
    {
        return 'عرض وإدارة حجوزات الطيران فقط داخل النظام';
    }

    public function getFinanceSourceType(): string
    {
        return 'حجوزات الطيران';
    }

    public function getBookingLabel(): string
    {
        return 'طيران';
    }

    public function getPageIcon(): string
    {
        return 'fas fa-plane-departure';
    }
}

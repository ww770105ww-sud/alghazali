<?php
require_once __DIR__ . '/BaseBookingService.php';

class CombinedBookingService extends BaseBookingService
{
    public function getServiceType(): ?string
    {
        return null;
    }

    public function getPageTitle(): string
    {
        return 'إدارة حجوزات الباصات والطيران';
    }

    public function getPageDescription(): string
    {
        return 'عرض وإدارة جميع حجوزات الباصات والطيران في النظام';
    }

    public function getFinanceSourceType(): string
    {
        return 'حجوزات الباصات والطيران';
    }

    public function getBookingLabel(): string
    {
        return 'الباصات والطيران';
    }
}

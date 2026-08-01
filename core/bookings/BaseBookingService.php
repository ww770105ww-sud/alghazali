<?php

abstract class BaseBookingService
{
    abstract public function getServiceType(): ?string;
    abstract public function getPageTitle(): string;
    abstract public function getPageDescription(): string;
    abstract public function getFinanceSourceType(): string;
    abstract public function getBookingLabel(): string;

    public function getPageIcon(): string
    {
        return 'fas fa-route';
    }

    public function isScoped(): bool
    {
        return $this->getServiceType() !== null;
    }

    public function forceServiceType(?string $serviceType): ?string
    {
        if ($this->isScoped()) {
            return $this->getServiceType();
        }

        return $serviceType;
    }

    public function normalizeFormData(array $data): array
    {
        $data['service_type'] = $this->forceServiceType($data['service_type'] ?? null);

        if (!$this->isBusService($data['service_type'] ?? null)) {
            $data['bus_type'] = null;
        }

        return $data;
    }

    public function isBusService(?string $serviceType): bool
    {
        return strtolower((string)$serviceType) === 'bus';
    }

    public function getBookingLabelForServiceType(?string $serviceType): string
    {
        return $this->isBusService($serviceType) ? 'باص' : 'طيران';
    }

    public function getWorkflowTransactionType(): string
    {
        return 'bus_flight_bookings';
    }

    public function getFinancialSourceTypes(): array
    {
        $serviceType = $this->getServiceType();

        if ($serviceType === 'bus') {
            return ['حجوزات الباصات', 'حجوزات الباصات والطيران', 'تذاكر طيران وبصات', 'bus'];
        }

        if ($serviceType === 'flight') {
            return ['حجوزات الطيران', 'حجوزات الباصات والطيران', 'تذاكر طيران وبصات', 'flight', 'الطيران'];
        }

        return ['حجوزات الباصات والطيران', 'تذاكر طيران وبصات', 'حجوزات الباصات', 'حجوزات الطيران', 'bus', 'flight', 'الطيران'];
    }

    public function getAllowedServiceTypes(): array
    {
        if ($this->isScoped()) {
            return [$this->getServiceType() => $this->getBookingLabel()];
        }

        return [
            'bus' => 'باص',
            'flight' => 'طيران',
        ];
    }
}

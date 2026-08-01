<?php
require_once __DIR__ . '/CombinedBookingService.php';
require_once __DIR__ . '/BusBookingService.php';
require_once __DIR__ . '/FlightBookingService.php';

class BookingServiceFactory
{
    public static function make(?string $serviceType): BaseBookingService
    {
        switch ($serviceType) {
            case 'bus':
                return new BusBookingService();
            case 'flight':
                return new FlightBookingService();
            default:
                return new CombinedBookingService();
        }
    }
}

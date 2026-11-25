<?php
declare(strict_types=1);

namespace MiniErp\sales;

use MiniErp\shared\AppError;

/** Shared Invoice/Payment business clock; independent of the server OS timezone. */
final class BusinessDate
{
    public static function today(): string
    {
        $zone = $_ENV['APP_BUSINESS_TIMEZONE'] ?? (getenv('APP_BUSINESS_TIMEZONE') ?: 'Asia/Kolkata');
        try {
            return (new \DateTimeImmutable('now', new \DateTimeZone($zone)))->format('Y-m-d');
        } catch (\Exception) {
            throw new AppError('CONFIGURATION_ERROR', 'Configure a valid APP_BUSINESS_TIMEZONE.', 503);
        }
    }
}

<?php

namespace App\Domain\Bookings;

use RuntimeException;

final class StaleBooking extends RuntimeException
{
    public function __construct(public readonly int $bookingId, public readonly int $expectedVersion)
    {
        parent::__construct("Booking {$bookingId} changed since version {$expectedVersion}; reload and try again.");
    }
}

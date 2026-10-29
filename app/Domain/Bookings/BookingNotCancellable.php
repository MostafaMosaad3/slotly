<?php

namespace App\Domain\Bookings;

use RuntimeException;

final class BookingNotCancellable extends RuntimeException
{
    public function __construct(public readonly int $bookingId)
    {
        parent::__construct("Booking {$bookingId} is no longer confirmed and can't be cancelled.");
    }
}

<?php

namespace App\Domain\Bookings;

use Carbon\CarbonInterface;
use RuntimeException;

final class SlotTaken extends RuntimeException
{
    public function __construct(public readonly int $staffId, public readonly CarbonInterface $start)
    {
        parent::__construct("Staff {$staffId} is already booked at {$start->toDateTimeString()}.");
    }
}

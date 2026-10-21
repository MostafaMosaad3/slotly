<?php

namespace App\Domain\Scheduling;

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class StaffCalendar
{
    public function day(int $staffId, CarbonImmutable $dayStartUtc): Collection
    {
        return Booking::query()
            ->where('staff_id', $staffId)
            ->where('starts_at', '>=', $dayStartUtc)
            ->where('starts_at', '<', $dayStartUtc->addDay())
            ->orderBy('starts_at')
            ->get(['id', 'starts_at', 'ends_at', 'status']);
    }
}

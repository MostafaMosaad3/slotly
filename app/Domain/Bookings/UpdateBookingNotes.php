<?php

namespace App\Domain\Bookings;

use App\Models\Booking;
use Illuminate\Support\Facades\DB;

final class UpdateBookingNotes
{
    public function handle(int $bookingId, string $notes, int $expectedVersion): void
    {
        $affected = Booking::whereKey($bookingId)
            ->where('version', $expectedVersion)
            ->update(['notes' => $notes, 'version' => DB::raw('version + 1')]);

        if ($affected === 0) {
            throw new StaleBooking($bookingId, $expectedVersion);
        }
    }
}

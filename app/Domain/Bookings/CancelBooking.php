<?php

namespace App\Domain\Bookings;

use App\Models\Booking;
use App\Models\Staff;
use Illuminate\Support\Facades\DB;

final class CancelBooking
{
    public function __construct(private StaffLocks $locks) {}

    public function handle(Booking $booking): void
    {
        DB::transaction(function () use ($booking) {
            $this->locks->lock($booking->staff_id);

            // the expected status lives in the WHERE: the in-memory $booking may already be stale
            $affected = Booking::whereKey($booking->id)
                ->where('status', 'confirmed')
                ->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'version' => DB::raw('version + 1'),
                ]);

            if ($affected === 0) {
                throw new BookingNotCancellable($booking->id);
            }

            if ($booking->starts_at->isFuture()) {
                Staff::whereKey($booking->staff_id)
                    ->where('upcoming_bookings_count', '>', 0)
                    ->decrement('upcoming_bookings_count');
            }
        }, attempts: 3);
    }
}

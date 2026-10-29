<?php

namespace App\Domain\Bookings;

use App\Models\Booking;
use App\Models\Staff;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class RescheduleBooking
{
    public function __construct(private StaffLocks $locks) {}

    public function handle(Booking $booking, Staff $newStaff, CarbonInterface $newStart): Booking
    {
        $newEnd = $newStart->copy()->addMinutes($booking->starts_at->diffInMinutes($booking->ends_at));

        return DB::transaction(function () use ($booking, $newStaff, $newStart, $newEnd) {
            $this->locks->lock($booking->staff_id, $newStaff->id);

            $taken = Booking::overlapping($newStaff->id, $newStart, $newEnd)
                ->whereKeyNot($booking->id)
                ->lockForUpdate()
                ->exists();

            if ($taken) {
                throw new SlotTaken($newStaff->id, $newStart);
            }

            $booking->update(['staff_id' => $newStaff->id, 'starts_at' => $newStart, 'ends_at' => $newEnd]);

            return $booking;
        }, attempts: 3);
    }
}

<?php

namespace App\Domain\Bookings;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class BookSlot
{
    public function __construct(private StaffLocks $locks) {}

    public function handle(Staff $staff, Service $service, Customer $customer, CarbonInterface $start): Booking
    {
        $end = $start->copy()->addMinutes($service->duration_minutes);

        return DB::transaction(function () use ($staff, $service, $customer, $start, $end) {
            $this->locks->lock($staff->id);

            // current read on purpose: a plain read could reuse a snapshot taken before the staff lock
            if (Booking::overlapping($staff->id, $start, $end)->lockForUpdate()->exists()) {
                throw new SlotTaken($staff->id, $start);
            }

            $booking = Booking::create([
                'tenant_id' => $staff->tenant_id,
                'service_id' => $service->id,
                'staff_id' => $staff->id,
                'customer_id' => $customer->id,
                'starts_at' => $start,
                'ends_at' => $end,
                'status' => 'confirmed',
                'price_cents' => $service->price_cents,
            ]);

            // last statement: the X lock on the staff row is already held, so this adds no new wait
            Staff::whereKey($staff->id)->increment('upcoming_bookings_count');

            return $booking;
        }, attempts: 3);
    }
}

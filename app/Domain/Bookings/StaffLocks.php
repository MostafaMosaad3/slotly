<?php

namespace App\Domain\Bookings;

use App\Models\Staff;

final class StaffLocks
{
    public function lock(int ...$staffIds): void
    {
        $ids = array_values(array_unique($staffIds));
        sort($ids);

        // one global order (ascending id) makes a wait-for cycle between booking actions impossible
        Staff::whereKey($ids)->orderBy('id')->lockForUpdate()->get();
    }
}

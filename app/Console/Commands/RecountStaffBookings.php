<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class RecountStaffBookings extends Command
{
    protected $signature = 'staff:recount-bookings';

    protected $description = 'Recompute staff.upcoming_bookings_count from bookings';

    public function handle(): int
    {
        // UTC_TIMESTAMP, not NOW(): starts_at is UTC, NOW() follows the session time zone
        $changed = DB::update(<<<'SQL'
            UPDATE staff s
            LEFT JOIN (
                SELECT staff_id, COUNT(*) AS n
                FROM bookings
                WHERE status = 'confirmed' AND starts_at > UTC_TIMESTAMP()
                GROUP BY staff_id
            ) b ON b.staff_id = s.id
            SET s.upcoming_bookings_count = COALESCE(b.n, 0)
        SQL);

        $this->info("Recounted; {$changed} staff rows changed.");

        return self::SUCCESS;
    }
}

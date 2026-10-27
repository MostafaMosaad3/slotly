<?php

use App\Models\Booking;
use App\Models\Staff;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function connectionB(): Connection
{
    config(['database.connections.mysql_b' => config('database.connections.mysql')]);

    return DB::connection('mysql_b');
}

function slot(string $from, string $to): array
{
    return [Carbon::parse("2026-11-02 {$from}"), Carbon::parse("2026-11-02 {$to}")];
}

function overlappingCount(Connection $connection, Staff $staff, array $slot, bool $lock = false): int
{
    $query = Booking::on($connection->getName())->overlapping($staff->id, ...$slot);

    return $lock ? $query->lockForUpdate()->count() : $query->count();
}

function bookingRow(Staff $staff, array $slot): array
{
    return Booking::factory()->for($staff)->make([
        'tenant_id' => $staff->tenant_id,
        'starts_at' => $slot[0],
        'ends_at' => $slot[1],
        'status' => 'confirmed',
    ])->getAttributes();
}

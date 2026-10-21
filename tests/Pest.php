<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Database');

expect()->extend('toRunQueries', function (int $expected) {
    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    ($this->value)();

    expect($count)->toBe($expected);

    return $this;
});

function seedTenantWithBookings(int $bookings = 0): int
{
    $now = now();
    $slug = 'tenant-'.Str::lower(Str::random(8));

    $tenant = DB::table('tenants')->insertGetId([
        'name' => $slug,
        'slug' => $slug,
        'timezone' => 'Africa/Cairo',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $service = DB::table('services')->insertGetId([
        'tenant_id' => $tenant,
        'name' => 'Haircut',
        'duration_minutes' => 30,
        'price_cents' => 15_000,
        'active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $staff = DB::table('staff')->insertGetId([
        'tenant_id' => $tenant,
        'name' => 'Staff A',
        'active' => true,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $customer = DB::table('customers')->insertGetId([
        'tenant_id' => $tenant,
        'name' => 'Customer A',
        'email' => "{$slug}@example.test",
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $rows = [];
    for ($i = 0; $i < $bookings; $i++) {
        $startsAt = $now->copy()->startOfHour()->addHours($i + 1);

        $rows[] = [
            'tenant_id' => $tenant,
            'service_id' => $service,
            'staff_id' => $staff,
            'customer_id' => $customer,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => 'confirmed',
            'price_cents' => 15_000,
            'created_at' => $now->copy()->subMinutes($i),
            'updated_at' => $now->copy()->subMinutes($i),
        ];
    }

    if ($rows !== []) {
        DB::table('bookings')->insert($rows);
    }

    return $tenant;
}

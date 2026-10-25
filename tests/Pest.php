<?php

use App\Models\Booking;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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

function seedTenantWithTwoStaff(): array
{
    $tenant = seedTenantWithBookings(bookings: 0);
    $staff = (int) DB::table('staff')->where('tenant_id', $tenant)->value('id');

    $otherStaff = DB::table('staff')->insertGetId([
        'tenant_id' => $tenant,
        'name' => 'Staff B',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$tenant, $staff, $otherStaff];
}

function tenantBookingRow(int $tenant, array $overrides = []): array
{
    $startsAt = CarbonImmutable::now('UTC')->startOfHour()->addDay();

    return $overrides + [
        'tenant_id' => $tenant,
        'service_id' => DB::table('services')->where('tenant_id', $tenant)->value('id'),
        'staff_id' => DB::table('staff')->where('tenant_id', $tenant)->value('id'),
        'customer_id' => DB::table('customers')->where('tenant_id', $tenant)->value('id'),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(30),
        'status' => 'confirmed',
        'price_cents' => 15_000,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

function bookingAt(int $staffId, string $startsAtUtc, int $minutes = 30): int
{
    $tenant = (int) DB::table('staff')->where('id', $staffId)->value('tenant_id');
    $startsAt = CarbonImmutable::parse($startsAtUtc, 'UTC');

    return DB::table('bookings')->insertGetId(tenantBookingRow($tenant, [
        'staff_id' => $staffId,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes($minutes),
    ]));
}

function bookingWith(int $tenant, string $status, CarbonInterface $endsAt): Booking
{
    $id = DB::table('bookings')->insertGetId(tenantBookingRow($tenant, [
        'status' => $status,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
    ]));

    return Booking::findOrFail($id);
}

function pastConfirmedBookings(int $tenant, int $count): void
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $endsAt = now()->subDays(1)->subMinutes($i);

        $rows[] = tenantBookingRow($tenant, [
            'status' => 'confirmed',
            'starts_at' => $endsAt->copy()->subMinutes(30),
            'ends_at' => $endsAt,
        ]);
    }

    DB::table('bookings')->insert($rows);
}

function bookingsWithSameCreatedAt(int $tenant, int $count): array
{
    $createdAt = now()->startOfSecond();

    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $ids[] = DB::table('bookings')->insertGetId(tenantBookingRow($tenant, [
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]));
    }

    return $ids;
}

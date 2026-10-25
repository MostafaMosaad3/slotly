<?php

use App\Domain\Bookings\TenantBookingFeed;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('completes every past confirmed booking — none skipped', function () {
    $tenant = seedTenantWithBookings(bookings: 0);
    pastConfirmedBookings($tenant, count: 250);

    $this->artisan('bookings:complete-past', ['--chunk' => 100])->assertSuccessful();

    expect(Booking::where('status', 'confirmed')->count())->toBe(0)
        ->and(Booking::where('status', 'completed')->count())->toBe(250);
});

it('leaves future and cancelled bookings alone', function () {
    $tenant = seedTenantWithBookings(bookings: 0);
    $future = bookingWith($tenant, status: 'confirmed', endsAt: now()->addDay());
    $cancelled = bookingWith($tenant, status: 'cancelled', endsAt: now()->subDay());

    $this->artisan('bookings:complete-past')->assertSuccessful();

    expect($future->fresh()->status)->toBe('confirmed')
        ->and($cancelled->fresh()->status)->toBe('cancelled');
});

dataset('page sizes', [1, 2, 3, 7]);

it('walks the whole feed with no duplicates and no gaps', function (int $perPage) {
    $tenant = seedTenantWithBookings(bookings: 0);
    $ids = bookingsWithSameCreatedAt($tenant, count: 10);

    $seen = [];
    $cursor = null;
    do {
        $page = (new TenantBookingFeed)->latest($tenant, $perPage, $cursor);
        array_push($seen, ...$page->pluck('id')->all());
        $cursor = $page->nextCursor()?->encode();
    } while ($cursor !== null);

    expect($seen)->toHaveCount(10)
        ->and(array_unique($seen))->toHaveCount(10)
        ->and(collect($seen)->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());
})->with('page sizes');

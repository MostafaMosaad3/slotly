<?php

use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = seedTenantWithBookings(bookings: 30);
});

it('loads the dashboard page with a fixed number of queries', function () {
    expect(fn () => Booking::where('tenant_id', $this->tenant)
        ->with(['service', 'customer'])
        ->latest()
        ->paginate(15))
        ->toRunQueries(4);
});

it('drops the count query with simple pagination', function () {
    expect(fn () => Booking::where('tenant_id', $this->tenant)
        ->with(['service', 'customer'])
        ->latest()
        ->simplePaginate(15))
        ->toRunQueries(3);
});

it('returns only the requested tenant bookings', function () {
    seedTenantWithBookings(bookings: 5);

    $tenants = Booking::where('tenant_id', $this->tenant)->latest()
        ->paginate(15)->pluck('tenant_id')->unique();

    expect($tenants->all())->toBe([$this->tenant]);
});

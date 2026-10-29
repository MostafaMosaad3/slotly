<?php

use App\Models\Booking;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('mysql');

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('UPDATE ... JOIN is MySQL syntax.');
    }
});

it('recomputes each staff counter from confirmed future bookings', function () {
    [$busy, $cancelledOnly, $pastOnly] = Staff::factory()->count(3)->create(['upcoming_bookings_count' => 99])->all();
    $tomorrow = now()->addDay()->startOfHour();
    $yesterday = now()->subDay()->startOfHour();

    Booking::factory()->for($busy)->create(['status' => 'confirmed', 'starts_at' => $tomorrow, 'ends_at' => $tomorrow->copy()->addHour()]);
    Booking::factory()->for($busy)->create(['status' => 'confirmed', 'starts_at' => $tomorrow->copy()->addHours(2), 'ends_at' => $tomorrow->copy()->addHours(3)]);
    Booking::factory()->for($cancelledOnly)->create(['status' => 'cancelled', 'starts_at' => $tomorrow, 'ends_at' => $tomorrow->copy()->addHour()]);
    Booking::factory()->for($pastOnly)->create(['status' => 'confirmed', 'starts_at' => $yesterday, 'ends_at' => $yesterday->copy()->addHour()]);

    $this->artisan('staff:recount-bookings')
        ->expectsOutputToContain('3 staff rows changed')
        ->assertSuccessful();

    expect($busy->fresh()->upcoming_bookings_count)->toBe(2)
        ->and($cancelledOnly->fresh()->upcoming_bookings_count)->toBe(0)
        ->and($pastOnly->fresh()->upcoming_bookings_count)->toBe(0);
});

it('reports zero changes when every counter is already right', function () {
    Staff::factory()->count(2)->create(['upcoming_bookings_count' => 0]);

    $this->artisan('staff:recount-bookings')
        ->expectsOutputToContain('0 staff rows changed')
        ->assertSuccessful();
});

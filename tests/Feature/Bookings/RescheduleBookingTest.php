<?php

use App\Domain\Bookings\RescheduleBooking;
use App\Domain\Bookings\SlotTaken;
use App\Models\Booking;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->staff = Staff::factory()->create();
    $this->other = Staff::factory()->create(['tenant_id' => $this->staff->tenant_id]);
    $this->booking = Booking::factory()->for($this->staff)->create(['starts_at' => '2026-11-02 10:00', 'ends_at' => '2026-11-02 11:00']);
});

it('moves a booking to another staff member and keeps its length', function () {
    app(RescheduleBooking::class)->handle($this->booking, $this->other, Carbon::parse('2026-11-02 14:00'));

    $booking = $this->booking->fresh();

    expect($booking->staff_id)->toBe($this->other->id)
        ->and($booking->starts_at->toDateTimeString())->toBe('2026-11-02 14:00:00')
        ->and($booking->ends_at->toDateTimeString())->toBe('2026-11-02 15:00:00');
});

it('moves a booking within its own time for the same staff member', function () {
    app(RescheduleBooking::class)->handle($this->booking, $this->staff, Carbon::parse('2026-11-02 10:30'));

    expect($this->booking->fresh()->starts_at->toDateTimeString())->toBe('2026-11-02 10:30:00');
});

it('refuses a slot the new staff member already has', function () {
    Booking::factory()->for($this->other)->create(['starts_at' => '2026-11-02 14:30', 'ends_at' => '2026-11-02 15:30']);

    expect(fn () => app(RescheduleBooking::class)->handle($this->booking, $this->other, Carbon::parse('2026-11-02 14:00')))
        ->toThrow(SlotTaken::class)
        ->and($this->booking->fresh()->staff_id)->toBe($this->staff->id);
});

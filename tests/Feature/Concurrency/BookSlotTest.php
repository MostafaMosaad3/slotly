<?php

use App\Domain\Bookings\BookSlot;
use App\Domain\Bookings\SlotTaken;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->staff = Staff::factory()->create();
    $this->service = Service::where('tenant_id', $this->staff->tenant_id)->firstOrFail();
    $this->customer = Customer::where('tenant_id', $this->staff->tenant_id)->firstOrFail();
});

it('books the service duration and price', function () {
    $booking = app(BookSlot::class)->handle($this->staff, $this->service, $this->customer, Carbon::parse('2026-11-02 10:00'));

    expect($booking->ends_at->toDateTimeString())->toBe('2026-11-02 11:00:00')
        ->and($booking->price_cents)->toBe($this->service->price_cents)
        ->and($booking->status)->toBe('confirmed');
});

it('refuses an overlapping slot for the same staff member', function () {
    $book = fn (string $at) => app(BookSlot::class)->handle($this->staff, $this->service, $this->customer, Carbon::parse("2026-11-02 {$at}"));

    $book('10:00');

    expect(fn () => $book('10:30'))->toThrow(SlotTaken::class)
        ->and(Booking::count())->toBe(1);
});

it('counts the booking on the staff row', function () {
    app(BookSlot::class)->handle($this->staff, $this->service, $this->customer, Carbon::parse('2026-11-02 10:00'));
    app(BookSlot::class)->handle($this->staff, $this->service, $this->customer, Carbon::parse('2026-11-02 12:00'));

    expect($this->staff->fresh()->upcoming_bookings_count)->toBe(2);
});

it('does not count a refused booking', function () {
    $book = fn (string $at) => app(BookSlot::class)->handle($this->staff, $this->service, $this->customer, Carbon::parse("2026-11-02 {$at}"));

    $book('10:00');

    expect(fn () => $book('10:30'))->toThrow(SlotTaken::class)
        ->and($this->staff->fresh()->upcoming_bookings_count)->toBe(1);
});

it('misses a booking longer than max_booking_minutes, so longer bookings must be rejected', function () {
    Booking::factory()->for($this->staff)->create(['starts_at' => '2026-11-02 05:00', 'ends_at' => '2026-11-02 10:30']);

    expect(Booking::overlapping($this->staff->id, ...slot('10:00', '11:00'))->count())->toBe(0);
});

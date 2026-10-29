<?php

use App\Domain\Bookings\BookingNotCancellable;
use App\Domain\Bookings\CancelBooking;
use App\Models\Booking;
use App\Models\Staff;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class)->group('mysql');

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('The race test uses a second MySQL connection.');
    }
    $this->staff = Staff::factory()->create(['upcoming_bookings_count' => 1]);
    $this->booking = Booking::factory()->for($this->staff)->create([
        'status' => 'confirmed',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
    ]);
});

afterEach(function () {
    $this->truncateDatabaseTables();
});

it('cancels a confirmed booking', function () {
    app(CancelBooking::class)->handle($this->booking);

    $booking = $this->booking->fresh();

    expect($booking->status)->toBe('cancelled')
        ->and($booking->cancelled_at)->not->toBeNull()
        ->and($booking->version)->toBe(2);
});

it('decrements the staff counter once', function () {
    app(CancelBooking::class)->handle($this->booking);

    expect($this->staff->fresh()->upcoming_bookings_count)->toBe(0);
});

it('refuses to cancel a booking that is already cancelled', function () {
    app(CancelBooking::class)->handle($this->booking);

    expect(fn () => app(CancelBooking::class)->handle($this->booking))
        ->toThrow(BookingNotCancellable::class)
        ->and($this->staff->fresh()->upcoming_bookings_count)->toBe(0);
});

it('refuses to cancel a booking the nightly job completed first', function () {
    connectionB()->table('bookings')
        ->where('id', $this->booking->id)
        ->where('status', 'confirmed')
        ->update(['status' => 'completed']);

    expect(fn () => app(CancelBooking::class)->handle($this->booking))->toThrow(BookingNotCancellable::class)
        ->and($this->booking->fresh()->status)->toBe('completed')
        ->and($this->staff->fresh()->upcoming_bookings_count)->toBe(1);
});

it('does not touch the counter when the cancel is refused', function () {
    DB::table('bookings')->where('id', $this->booking->id)->update(['status' => 'no_show']);

    expect(fn () => app(CancelBooking::class)->handle($this->booking))->toThrow(BookingNotCancellable::class)
        ->and($this->staff->fresh()->upcoming_bookings_count)->toBe(1);
});

it('leaves the counter alone when the booking already started', function () {
    $started = Booking::factory()->for($this->staff)->create([
        'status' => 'confirmed',
        'starts_at' => now()->subMinutes(10),
        'ends_at' => now()->addMinutes(50),
    ]);

    app(CancelBooking::class)->handle($started);

    expect($started->fresh()->status)->toBe('cancelled')
        ->and($this->staff->fresh()->upcoming_bookings_count)->toBe(1);
});

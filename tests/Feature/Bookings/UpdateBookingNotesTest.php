<?php

use App\Domain\Bookings\StaleBooking;
use App\Domain\Bookings\UpdateBookingNotes;
use App\Models\Booking;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->booking = Booking::factory()->create();
    $this->notes = app(UpdateBookingNotes::class);
});

it('saves notes with the current version and bumps it', function () {
    $this->notes->handle($this->booking->id, 'Prefers the window seat', expectedVersion: 1);

    $booking = $this->booking->fresh();

    expect($booking->notes)->toBe('Prefers the window seat')
        ->and($booking->version)->toBe(2);
});

it('rejects the second save made from the same version', function () {
    $this->notes->handle($this->booking->id, 'First editor', expectedVersion: 1);

    expect(fn () => $this->notes->handle($this->booking->id, 'Second editor', expectedVersion: 1))
        ->toThrow(StaleBooking::class)
        ->and($this->booking->fresh()->notes)->toBe('First editor');
});

it('lets the second editor save after reloading the new version', function () {
    $this->notes->handle($this->booking->id, 'First editor', expectedVersion: 1);

    $reloaded = $this->booking->fresh();
    $this->notes->handle($reloaded->id, 'First editor + second editor', expectedVersion: $reloaded->version);

    expect($this->booking->fresh())
        ->notes->toBe('First editor + second editor')
        ->version->toBe(3);
});

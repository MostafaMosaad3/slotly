<?php

use App\Domain\Scheduling\StaffCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    [$this->tenant, $this->staff, $this->otherStaff] = seedTenantWithTwoStaff();
    $this->day = CarbonImmutable::parse('2026-10-05 00:00:00', 'UTC');
});

dataset('day boundaries', [
    'first minute of the day' => ['2026-10-05 00:00:00', true],
    'middle of the day' => ['2026-10-05 13:30:00', true],
    'last minute of the day' => ['2026-10-05 23:59:00', true],
    'last half-minute of the day' => ['2026-10-05 23:59:30', true],
    'next midnight' => ['2026-10-06 00:00:00', false],
    'previous evening' => ['2026-10-04 23:30:00', false],
]);

it('includes exactly the bookings that start inside the day', function (string $startsAt, bool $included) {
    $id = bookingAt($this->staff, $startsAt);

    $ids = (new StaffCalendar)->day($this->staff, $this->day)->pluck('id')->all();

    expect(in_array($id, $ids, true))->toBe($included);
})->with('day boundaries');

it('never returns another staff member bookings', function () {
    bookingAt($this->otherStaff, '2026-10-05 10:00:00');

    expect((new StaffCalendar)->day($this->staff, $this->day))->toBeEmpty();
});

it('returns the day in start order', function () {
    bookingAt($this->staff, '2026-10-05 15:00:00');
    bookingAt($this->staff, '2026-10-05 09:00:00');

    $starts = (new StaffCalendar)->day($this->staff, $this->day)
        ->map(fn ($b) => $b->starts_at->format('H:i'))->all();

    expect($starts)->toBe(['09:00', '15:00']);
});

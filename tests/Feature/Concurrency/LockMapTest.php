<?php

use App\Models\Booking;
use App\Models\Staff;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class)->group('mysql');

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Locking behaviour needs real MySQL.');
    }
    $this->staff = Staff::factory()->create();
    $this->slot = slot('10:00', '11:00');
    $this->a = DB::connection();
    $this->b = connectionB();
});

afterEach(function () {
    foreach ([$this->a, $this->b] as $connection) {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }

    $this->truncateDatabaseTables();
});

it('serializes two bookings for the same staff member on the staff row', function () {
    $this->a->beginTransaction();
    $this->a->table('staff')->where('id', $this->staff->id)->lockForUpdate()->first();

    $this->b->beginTransaction();
    expectLockWait($this->b, fn () => $this->b->table('staff')->where('id', $this->staff->id)->lockForUpdate()->first());

    $this->a->table('bookings')->insert(bookingRow($this->staff, $this->slot));
    $this->a->commit();

    $this->b->table('staff')->where('id', $this->staff->id)->lockForUpdate()->first();
    expect(overlappingCount($this->b, $this->staff, $this->slot, lock: true))->toBe(1);
    $this->b->rollBack();

    expect(overlappingCount(DB::connection(), $this->staff, $this->slot))->toBe(1);
});

it('lets two gap locks coexist but blocks the insert', function () {
    $this->a->beginTransaction();
    $this->b->beginTransaction();

    expect(overlappingCount($this->a, $this->staff, $this->slot, lock: true))->toBe(0)
        ->and(overlappingCount($this->b, $this->staff, $this->slot, lock: true))->toBe(0);

    expectLockWait($this->a, fn () => $this->a->table('bookings')->insert(bookingRow($this->staff, $this->slot)));
});

it('maps which inserts wait behind the range check', function (string $from, string $to, bool $otherStaff, bool $waits) {
    Booking::factory()->for($this->staff)->create(['starts_at' => '2026-11-02 09:00', 'ends_at' => '2026-11-02 09:45']);
    Booking::factory()->for($this->staff)->create(['starts_at' => '2026-11-02 13:00', 'ends_at' => '2026-11-02 14:00']);
    $staff = $otherStaff ? Staff::factory()->create() : $this->staff;

    $this->a->beginTransaction();
    overlappingCount($this->a, $this->staff, $this->slot, lock: true);

    $insert = fn () => $this->b->table('bookings')->insert(bookingRow($staff, slot($from, $to)));

    if ($waits) {
        expectLockWait($this->b, $insert);
    } else {
        $insert();
        expect(true)->toBeTrue();
    }
})->with([
    'overlapping 10:00' => ['10:00', '10:30', false, true],
    'not overlapping 12:00, same gap' => ['12:00', '12:30', false, true],
    'after the next entry 14:30' => ['14:30', '15:00', false, false],
    'other staff 10:00' => ['10:00', '10:30', true, false],
]);

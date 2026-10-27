<?php

use App\Models\Staff;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class)->group('mysql');

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('MVCC behaviour needs real MySQL.');
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

    // DatabaseTruncation only cleans before a test; committed rows would leak into RefreshDatabase tests
    $this->truncateDatabaseTables();
});

it('keeps a plain read on its snapshot, while a locking read sees the commit', function () {
    $this->a->beginTransaction();
    expect(overlappingCount($this->a, $this->staff, $this->slot))->toBe(0);

    $this->b->table('bookings')->insert(bookingRow($this->staff, $this->slot));

    expect(overlappingCount($this->a, $this->staff, $this->slot))->toBe(0)
        ->and(overlappingCount($this->a, $this->staff, $this->slot, lock: true))->toBe(1);
});

it('sees the commit on the next plain read under READ COMMITTED', function () {
    $this->a->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
    $this->a->beginTransaction();
    expect(overlappingCount($this->a, $this->staff, $this->slot))->toBe(0);

    $this->b->table('bookings')->insert(bookingRow($this->staff, $this->slot));

    expect(overlappingCount($this->a, $this->staff, $this->slot))->toBe(1);
});

it('lets an UPDATE change a row the transaction could not see', function () {
    $held = fn () => $this->a->table('bookings')->where('staff_id', $this->staff->id);

    $this->a->beginTransaction();
    expect($held()->where('status', 'held')->count())->toBe(0);

    $this->b->table('bookings')->insert(['status' => 'held'] + bookingRow($this->staff, $this->slot));

    $affected = $held()->where('status', 'held')->update(['status' => 'released']);

    expect($affected)->toBe(1)
        ->and($held()->where('status', 'released')->count())->toBe(1);
});

it('allows a double booking with a plain availability check (fixed on Day 2)', function () {
    $this->a->beginTransaction();
    $this->b->beginTransaction();

    $freeForA = overlappingCount($this->a, $this->staff, $this->slot) === 0;
    $freeForB = overlappingCount($this->b, $this->staff, $this->slot) === 0;

    if ($freeForA) {
        $this->a->table('bookings')->insert(bookingRow($this->staff, $this->slot));
    }
    if ($freeForB) {
        $this->b->table('bookings')->insert(bookingRow($this->staff, $this->slot));
    }

    $this->a->commit();
    $this->b->commit();

    expect(overlappingCount(DB::connection(), $this->staff, $this->slot))->toBe(2);
});

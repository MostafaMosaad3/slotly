<?php

use App\Models\Staff;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class)->group('mysql');

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Needs real MySQL.');
    }
    $this->staff = Staff::factory()->create(['upcoming_bookings_count' => 10]);
    $this->a = DB::connection();
    $this->b = connectionB();
});

afterEach(function () {
    $this->truncateDatabaseTables();
});

function readCount(Connection $connection, Staff $staff): int
{
    return (int) $connection->table('staff')->where('id', $staff->id)->value('upcoming_bookings_count');
}

it('loses an update with read-modify-write', function () {
    $fromA = readCount($this->a, $this->staff);
    $fromB = readCount($this->b, $this->staff);

    $this->a->table('staff')->where('id', $this->staff->id)->update(['upcoming_bookings_count' => $fromA + 1]);
    $this->b->table('staff')->where('id', $this->staff->id)->update(['upcoming_bookings_count' => $fromB + 1]);

    expect(readCount(DB::connection(), $this->staff))->toBe(11);
});

it('keeps both updates with an atomic increment', function () {
    $this->a->table('staff')->where('id', $this->staff->id)->increment('upcoming_bookings_count');
    $this->b->table('staff')->where('id', $this->staff->id)->increment('upcoming_bookings_count');

    expect(readCount(DB::connection(), $this->staff))->toBe(12);
});

it('never loses an atomic increment under real concurrency', function () {
    $this->staff->update(['upcoming_bookings_count' => 0]);

    $runs = [startCounterHammer($this->staff->id, 'atomic', 200), startCounterHammer($this->staff->id, 'atomic', 200)];
    foreach ($runs as $process) {
        $process->wait();
    }

    expect(readCount(DB::connection(), $this->staff))->toBe(400);
});

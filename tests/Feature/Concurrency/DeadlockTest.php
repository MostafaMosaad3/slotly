<?php

use App\Models\Staff;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class)->group('mysql');

beforeEach(function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Deadlocks need real MySQL.');
    }
    [$this->s1, $this->s2] = Staff::factory()->count(2)->create()->sortBy('id')->values()->all();
});

afterEach(function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
});

function lockStaff(int $id): string
{
    try {
        DB::table('staff')->where('id', $id)->lockForUpdate()->first();

        return 'ok';
    } catch (QueryException $e) {
        return $e->errorInfo[1] === 1213 ? 'deadlock' : 'error';
    }
}

it('deadlocks when two transactions lock staff in opposite orders', function () {
    DB::beginTransaction();
    lockStaff($this->s1->id);

    $child = startLockPair($this->s2->id, $this->s1->id);
    waitForOutput($child, "locked:{$this->s2->id}");

    $parent = lockStaff($this->s2->id);
    $parent === 'ok' ? DB::commit() : DB::rollBack();

    $child->wait();

    expect([$parent, lastLine($child)])->toContain('deadlock')->toContain('ok');
});

it('does not deadlock when both sides lock staff in id order', function () {
    DB::beginTransaction();
    lockStaff($this->s1->id);

    $child = startLockPair($this->s1->id, $this->s2->id);
    usleep(200_000);

    $parent = lockStaff($this->s2->id);
    DB::commit();

    $child->wait();

    expect($parent)->toBe('ok')->and(lastLine($child))->toBe('ok');
});

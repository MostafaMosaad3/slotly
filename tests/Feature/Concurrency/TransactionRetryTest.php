<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('retries the whole transaction after a deadlock', function () {
    $runs = 0;

    $result = DB::transaction(function () use (&$runs) {
        $runs++;
        if ($runs === 1) {
            throw new QueryException('mysql', 'select 1', [], new PDOException(
                'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'
            ));
        }

        return 'booked';
    }, attempts: 3);

    expect($runs)->toBe(2)->and($result)->toBe('booked');
});

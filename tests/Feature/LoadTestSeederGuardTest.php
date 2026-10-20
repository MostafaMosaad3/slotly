<?php

use Database\Seeders\LoadTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('refuses to load test data outside the loadtest environment', function () {
    expect(fn () => $this->seed(LoadTestSeeder::class))
        ->toThrow(RuntimeException::class, 'APP_ENV=loadtest');

    foreach (['tenants', 'services', 'staff', 'customers', 'bookings'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});

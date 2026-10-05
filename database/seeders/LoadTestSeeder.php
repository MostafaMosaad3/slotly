<?php

namespace Database\Seeders;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LoadTestSeeder extends Seeder
{
    private const TABLES = ['tenants', 'services', 'staff', 'customers', 'bookings'];

    // Fixed UTC anchor makes reruns reproducible even on a different day.
    private const ANCHOR = '2026-10-05 00:00:00';

    /** @var array<int, array<string, array{int, int}>> */
    private array $ranges = [];

    public function run(): void
    {
        if (! app()->environment('loadtest')) {
            throw new RuntimeException('LoadTestSeeder requires APP_ENV=loadtest.');
        }

        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'slotly_load_test'
            || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'slotly_load_test') {
            throw new RuntimeException('LoadTestSeeder requires the verified MySQL database slotly_load_test.');
        }

        foreach (self::TABLES as $table) {
            if ($connection->table($table)->exists()) {
                throw new RuntimeException("LoadTestSeeder refuses nonempty table: {$table}.");
            }
        }

        // UTC prevents local DST gaps in TIMESTAMP columns as well as booking dates.
        $connection->statement("SET time_zone = '+00:00'");

        mt_srand(42);
        $connection->disableQueryLog();
        $anchor = new DateTimeImmutable(self::ANCHOR, new DateTimeZone('UTC'));
        $first = $anchor->modify('-2 years')->getTimestamp();
        $last = $anchor->modify('+2 months')->getTimestamp();
        $now = $anchor->getTimestamp();
        $created = gmdate('Y-m-d H:i:s', $first - 90 * 86400);
        $totalStarted = microtime(true);

        $this->insertRows('tenants', $this->tenants($created));
        $this->insertRows('services', $this->ownedRows('services', $created));
        $this->insertRows('staff', $this->ownedRows('staff', $created));
        $this->insertRows('customers', $this->ownedRows('customers', $created));
        $this->insertRows('bookings', $this->bookings($first, $last, $now));

        foreach (self::TABLES as $table) {
            $connection->select("ANALYZE TABLE `{$table}`");
        }

        $this->command->info(sprintf('Total including ANALYZE TABLE: %.2fs; peak memory: %.1f MiB',
            microtime(true) - $totalStarted, memory_get_peak_usage(true) / 1048576));
    }

    /** @return Generator<int, array<string, int|string|bool>> */
    private function tenants(string $created): Generator
    {
        $timezones = ['Africa/Cairo', 'Europe/London', 'America/New_York', 'Asia/Dubai'];
        for ($tenant = 1; $tenant <= 1000; $tenant++) {
            yield [
                'id' => $tenant,
                'name' => "Business {$tenant}",
                'slug' => "business-{$tenant}",
                'timezone' => $timezones[($tenant - 1) % count($timezones)],
                'created_at' => $created,
                'updated_at' => $created,
            ];
        }
    }

    /** @return Generator<int, array<string, int|string|bool>> */
    private function ownedRows(string $table, string $created): Generator
    {
        $id = 1;
        for ($tenant = 1; $tenant <= 1000; $tenant++) {
            $count = match ($table) {
                'services' => 10,
                'staff' => $tenant <= 10 ? 50 : 5,
                'customers' => $tenant <= 10 ? 5000 : 200,
                default => throw new RuntimeException('Unknown owned table.'),
            };
            $this->ranges[$tenant][$table] = [$id, $id + $count - 1];
            for ($offset = 0; $offset < $count; $offset++, $id++) {
                $row = [
                    'id' => $id,
                    'tenant_id' => $tenant,
                    'name' => ucfirst($table)." {$id}",
                    'created_at' => $created,
                    'updated_at' => $created,
                ];
                if ($table === 'services') {
                    $row['duration_minutes'] = 30 + ($offset % 4) * 15;
                    $row['price_cents'] = 2000 + $offset * 500;
                    $row['active'] = $offset !== 9;
                } elseif ($table === 'staff') {
                    $row['active'] = $offset !== $count - 1;
                } else {
                    $row['email'] = "customer{$id}@example.test";
                }
                yield $row;
            }
        }
    }

    /** @return Generator<int, array<string, int|string>> */
    private function bookings(int $first, int $last, int $now): Generator
    {
        for ($tenant = 1; $tenant <= 1000; $tenant++) {
            $ranges = $this->ranges[$tenant];
            $count = $tenant <= 10 ? 50000 : 1000;
            for ($booking = 0; $booking < $count; $booking++) {
                $service = mt_rand(...$ranges['services']);
                $offset = $service - $ranges['services'][0];
                $start = mt_rand($first, $last);
                $end = $start + (30 + ($offset % 4) * 15) * 60;
                $created = $start - mt_rand(1, 30 * 86400);
                $roll = mt_rand(1, 100);
                $past = $end <= $now;
                $status = $past
                    ? ($roll <= 85 ? 'completed' : ($roll <= 95 ? 'cancelled' : 'no_show'))
                    : ($roll <= 95 ? 'confirmed' : 'cancelled');

                yield [
                    'tenant_id' => $tenant,
                    'service_id' => $service,
                    'staff_id' => mt_rand(...$ranges['staff']),
                    'customer_id' => mt_rand(...$ranges['customers']),
                    'starts_at' => gmdate('Y-m-d H:i:s', $start),
                    'ends_at' => gmdate('Y-m-d H:i:s', $end),
                    'status' => $status,
                    'price_cents' => 2000 + $offset * 500,
                    'created_at' => gmdate('Y-m-d H:i:s', $created),
                    'updated_at' => gmdate('Y-m-d H:i:s', $past ? $end : $created),
                ];
            }
        }
    }

    /** @param iterable<array<string, int|string|bool>> $rows */
    private function insertRows(string $table, iterable $rows): void
    {
        $started = microtime(true);
        $chunk = [];
        $count = 0;
        foreach ($rows as $row) {
            $chunk[] = $row;
            if (count($chunk) === 1000) {
                DB::table($table)->insert($chunk);
                $count += count($chunk);
                $chunk = [];
                if ($table === 'bookings' && $count % 100000 === 0) {
                    $this->command->line(sprintf('bookings: %s rows (%.2fs)', number_format($count), microtime(true) - $started));
                }
            }
        }
        if ($chunk !== []) {
            DB::table($table)->insert($chunk);
            $count += count($chunk);
        }
        $this->command->info(sprintf('%s: %s rows in %.2fs', $table, number_format($count), microtime(true) - $started));
    }
}

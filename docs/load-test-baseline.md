# Slotly load-test baseline

Run date: 2026-10-05. Database: `slotly_load_test` (MySQL).

## What these steps prepare

- Tooling: Pest checks application behavior, Pint checks PHP formatting, and
  Larastan/PHPStan checks types and common code errors. Establishing a passing
  skeleton first separates pre-existing problems from later changes.
- ADR-001: records why tenants share one database with explicit `tenant_id`.
  It is a draft to revisit in Week 5; it does not itself enforce tenant isolation.
- Separate environment: `.env.loadtest` is copied from development, ignored by
  Git, and selects MySQL with `APP_ENV=loadtest`,
  `DB_DATABASE=slotly_load_test`, and `DB_TIMEZONE="+00:00"`.
  Verify both the configured name and `SELECT DATABASE()` before destructive work.
- Schema: tenants own services, staff, customers and bookings. Auto-increment
  BIGINT keys establish the baseline. Foreign keys ensure references exist;
  tenant ownership is consistent in this dataset, but the simple foreign keys
  do not yet prevent cross-tenant references. Week 5 adds that enforcement.
- Money and time: unsigned integer cents avoid floating-point rounding.
  Bookings copy the price at booking time. Calendar dates are UTC DATETIME.
  The load-test MySQL session also uses UTC for TIMESTAMP audit columns.
- Seed data: enough rows and uneven tenant sizes to measure meaningful query
  plans before designing composite indexes in Day 3.

## Measured run

| Table | Rows | Insert time |
| --- | ---: | ---: |
| tenants | 1,000 | 0.19 s |
| services | 10,000 | 1.31 s |
| staff | 5,450 | 0.45 s |
| customers | 248,000 | 26.91 s |
| bookings | 1,490,000 | 256.08 s |

Total including ANALYZE TABLE: **285.09 s**. PHP peak memory: **54.0 MiB**.
Timings are specific to this local WAMP run.

Tenants 1–10 have 50,000 bookings each; tenants 11–1000 have 1,000 each.
The explicit counts total 1,490,000 bookings; the busy ten own 33.56%, rather
than the introductory text's approximate half.

The generator streams into query-builder inserts of 1,000 rows. There are
1,490 booking insert statements rather than approximately 1,490,000 individual
factory inserts. Factories would additionally construct Eloquent objects and
execute their lifecycle logic. Factory runtime was not benchmarked.

Random seed: 42. Fixed UTC anchor: 2026-10-05 00:00:00. Start range:
2024-10-05 through 2026-12-05. The fixed anchor makes reruns reproducible across
different days; change it deliberately when a new benchmark window is needed.

## Verification

- Exact counts and booking counts for all 1,000 tenants passed.
- Full-dataset checks found zero cross-tenant service/staff/customer references,
  zero invalid time intervals, zero bookings created at or after their start,
  and zero copied-price or service-duration mismatches.
- 1,375,237 past bookings: 1,168,844 completed (about 85%).
- 114,763 upcoming bookings: 109,216 confirmed (about 95%).
- Bookings has PRIMARY plus four single-column secondary indexes, created for
  tenant_id, service_id, staff_id and customer_id foreign keys.
  No custom composite indexes were added.
- Seeder refused the normal development environment, an incorrectly named
  database, and the populated load-test database before inserting.
- ANALYZE TABLE ran for all five tables.
- Pest, Pint and PHPStan passed; the new migration and seeder were also
  explicitly analyzed, because the default PHPStan paths cover only app/.

## Benchmark caveats

Bookings are inserted tenant by tenant. This makes each tenant's clustered
primary-key rows unusually contiguous compared with interleaved production
traffic; account for that when interpreting index performance.

created_at represents advance booking time, while starts_at represents the
appointment time. Latest-booking feeds and calendars therefore sort differently.

The first attempted run hit a Cairo daylight-saving gap in TIMESTAMP columns
because MySQL used the system timezone. The UTC connection/session fix resolved
it; only the verified, newly created load-test database was reset and reseeded.

## Running the seeder

From the Slotly project in PowerShell:

```powershell
php artisan tinker --env=loadtest --execute='dump(DB::connection()->getDatabaseName()); dump(DB::selectOne("SELECT DATABASE() AS name")->name);'
php artisan db:seed --class=LoadTestSeeder --env=loadtest
```

Both names must be slotly_load_test. The existing populated database will be
refused. Before any deliberate reset, check its name again; never reset the
development database to rerun this benchmark.
## Inside InnoDB (Day 1 Step 5)

Measured 2026-10-20 on MySQL 9.1.0 (WAMP), `innodb_buffer_pool_size` = 256 MB.

| Table | table_rows (estimate) | data MB | index MB |
| --- | ---: | ---: | ---: |
| bookings | 1,481,205 | 140.7 | 212.5 |
| customers | 246,758 | 22.6 | 5.5 |
| services | 9,974 | 1.5 | 0.2 |
| staff | 5,608 | 0.3 | 0.1 |
| tenants | 1,000 | 0.1 | 0.1 |

`bookings` leaf pages (`mysql.innodb_index_stats`):

| Index | n_leaf_pages | size (pages) |
| --- | ---: | ---: |
| PRIMARY | 8,977 | 9,004 |
| bookings_staff_id_foreign | 3,711 | 4,265 |
| bookings_customer_id_foreign | 3,270 | 3,752 |
| bookings_service_id_foreign | 3,089 | 3,560 |
| bookings_tenant_id_foreign | 1,975 | 2,021 |

1,490,000 rows / 8,977 leaves ≈ 166 rows per 16 KB leaf. PRIMARY plus four
FK-created secondary indexes, none written by hand. Bookings data + indexes
(≈ 353 MB) is larger than the 256 MB buffer pool.

## First measurements (Day 1 Step 6)

Two runs each, same connection. Run 1 is "first run", not a guaranteed cold
buffer pool (MySQL was not restarted). Busy tenant 1 owns staff 1–50 and
customers 1–5,000.

| Query | Run 1 ms | Run 2 ms | Rows |
| --- | ---: | ---: | ---: |
| Q0 primary key (`id = 750000`) | 1.69 | 0.27 | 1 |
| Q1 catalog (tenant 1, active, LIMIT 20) | 0.56 | 0.30 | 9 |
| Q2 staff calendar (staff 20, 2026-10-05) | 26.05 | 3.94 | 0 |
| Q3 customer history (customer 900) | 2.92 | 0.39 | 12 |
| Q4 tenant dashboard (tenant 1, latest 20) | 150.64 | 154.05 | 20 |
| Q5 `COUNT(*)` where status = completed | 2,596.42 | 854.26 | 1 (1,168,844) |

Q2 returned no rows for that day; how many rows it read to find that is a Day 2 question.
Q4 stays ~150 ms even warm, so I/O is not the cost (prediction: sorting tenant 1's 50,000 rows).
Day 2 reads the plans behind these numbers.

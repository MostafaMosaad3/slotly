# Slotly query audit

Dataset: `slotly_load_test` on MySQL 9.1.0 (WAMP), `innodb_buffer_pool_size` = 256 MB.
`bookings` = 1,490,000 rows (tenants 1–10: 50,000 each; tenants 11–1000: 1,000 each).

Method: SQL and bindings captured from Laravel's query log (`DB::enableQueryLog()`),
then each statement run three times as `FLUSH STATUS; EXPLAIN ANALYZE …;
SHOW SESSION STATUS LIKE 'Handler_read%';`. "First" is run 1 (not a guaranteed
cold buffer pool — MySQL was not restarted), "warm" is the median of runs 2–3.
Times are the root node's `actual time` end value.

# Day 2 — baseline (2026-10-21)

Indexes on `bookings` before any change (`SHOW INDEX`, `innodb_index_stats` size):

| Index | Columns | Pages |
| --- | --- | ---: |
| PRIMARY | id | 9,004 |
| bookings_tenant_id_foreign | tenant_id | 2,021 |
| bookings_service_id_foreign | service_id | 3,560 |
| bookings_staff_id_foreign | staff_id | 4,265 |
| bookings_customer_id_foreign | customer_id | 3,752 |

All four secondary indexes were created by MySQL for the foreign keys.

IDs: busy tenant 1 owns staff 1–50; small tenant 500 owns staff 2946–2950.
Staff 20 has 1,008 bookings, staff 2946 has 195. Days picked as the busiest
day each staff member has (Day 1's 2026-10-05 returned zero rows for staff 20).

## Statement counts (Step 1)

| Call | Statements | Notes |
| --- | ---: | --- |
| Q1 `paginate(20)` | 2 | `count(*)` + page |
| Q2 `get()` | 1 | |
| Q4 `paginate(15)` with `service`, `customer` | 4 | `count(*)`, page, `services … in (…)`, `customers … in (…)` |
| Q4 `simplePaginate(15)` | 3 | no `count(*)`; page asks for `limit 16` to know if a next page exists |

Prediction for Q4 was 4; it matched. The two eager loads are single `WHERE IN`
queries, so no N+1.

Product question — does the dashboard need "page 7 of 3,334"? No. An owner reads
the latest bookings and goes to "next"; a total belongs in a stats widget, not on
every page load. Answer for Day 4: `simplePaginate` (or keyset), total shown
elsewhere if needed.

## Q1 — Public catalog

- Laravel call: `Service::where('tenant_id', ?)->where('active', true)->orderBy('id')->paginate(20)`
- SQL: `select * from services where tenant_id = ? and active = ? order by id asc limit 20 offset 0`, bindings `[1, true]`; plus `select count(*) … where tenant_id = ? and active = ?`
- Indexes present: PRIMARY, `services_tenant_id_foreign (tenant_id)`
- Prediction: index lookup on the tenant FK, filter on `active`, no sort (PK order is the secondary index's tiebreaker).
- Plan (tenant 1):

```text
-> Limit: 20 row(s)  (cost=9.93 rows=1) (actual time=0.03..0.0463 rows=9 loops=1)
    -> Filter: (services.`active` = 1)  (cost=9.93 rows=1) (actual time=0.0297..0.0454 rows=9 loops=1)
        -> Index lookup on services using services_tenant_id_foreign (tenant_id = 1)  (cost=9.93 rows=10) (actual time=0.0285..0.0434 rows=10 loops=1)
```

- Plan (tenant 500): same shape, 10 examined → 9 returned.
- Examined / returned / loops: 10 / 9 / 1. Handlers: `read_key` 1, `read_next` 10 (count query identical).
- First / warm: tenant 1 page 0.05 / 0.06 ms; tenant 500 page 0.86 / 0.57 ms; tenant 1 count 0.18 / 0.05 ms.
- Finding: no `Sort` node — `(tenant_id)` entries are already in `id` order inside one tenant, so `order by id` is free. 10 rows per tenant; nothing to fix.
- Hypothesis for Day 3: none. Leave it.

## Q2 — Staff calendar, one day

- Laravel call: `Booking::where('staff_id', ?)->whereBetween('starts_at', [?, ?])->orderBy('starts_at')->get()`
- SQL: `select * from bookings where staff_id = ? and starts_at between ? and ? order by starts_at asc`, bindings `[20, "2025-08-21 00:00:00", "2025-08-21 23:59:59"]`
- Indexes present: see table above.
- Prediction: lookup on `bookings_staff_id_foreign`, read all the staff member's rows, filter the day, sort.
- Plan (busy tenant, staff 20):

```text
-> Sort: bookings.starts_at  (cost=263 rows=1008) (actual time=3.67..3.67 rows=6 loops=1)
    -> Filter: (bookings.starts_at between '2025-08-21 00:00:00' and '2025-08-21 23:59:59')  (cost=263 rows=1008) (actual time=0.926..3.6 rows=6 loops=1)
        -> Index lookup on bookings using bookings_staff_id_foreign (staff_id = 20)  (cost=263 rows=1008) (actual time=0.463..2.83 rows=1008 loops=1)
```

- Plan (small tenant, staff 2946, 2025-04-21):

```text
-> Sort: bookings.starts_at  (cost=50.9 rows=195) (actual time=0.859..0.86 rows=4 loops=1)
    -> Filter: (bookings.starts_at between '2025-04-21 00:00:00' and '2025-04-21 23:59:59')  (cost=50.9 rows=195) (actual time=0.27..0.822 rows=4 loops=1)
        -> Index lookup on bookings using bookings_staff_id_foreign (staff_id = 2946)  (cost=50.9 rows=195) (actual time=0.11..0.66 rows=195 loops=1)
```

- Examined / returned / loops: staff 20 1,008 → 6; staff 2946 195 → 4; loops 1. Handlers: `read_key` 1, `read_next` 1,008 / 195.
- First / warm: staff 20 3.67 / 3.29 ms; staff 2946 0.86 / 0.72 ms.
- Finding: the FK index finds the staff member, then every one of their bookings (two years) is fetched from the clustered index to keep one day — 168× more rows than returned for a busy staff member, and it grows with history.
- Hypothesis for Day 3: `(staff_id, starts_at)` makes the day one contiguous, ordered slice — removes the `Filter` and the `Sort`, examines ≈ returned.

Also noted: `whereBetween(…, '23:59:59')` is a closed interval and drops a booking at 23:59:30. Day 3 switches to `>= day start AND < next day start`.

## Q4 — Tenant dashboard

- Laravel call: `Booking::where('tenant_id', ?)->with(['service', 'customer'])->latest()->paginate(15)`
- SQL: `select * from bookings where tenant_id = ? order by created_at desc limit 15 offset 0`, bindings `[1]`; `select count(*) as aggregate from bookings where tenant_id = ?`; then `services … in (9 ids)`, `customers … in (15 ids)` (~1 ms and ~1–4 ms).
- Prediction: lookup on the tenant FK, read all 50,000 rows, top-N sort by `created_at`.
- Plan, page (tenant 1):

```text
-> Limit: 15 row(s)  (cost=16788 rows=15) (actual time=181..181 rows=15 loops=1)
    -> Sort: bookings.created_at DESC, limit input to 15 row(s) per chunk  (cost=16788 rows=100346) (actual time=181..181 rows=15 loops=1)
        -> Index lookup on bookings using bookings_tenant_id_foreign (tenant_id = 1)  (cost=16788 rows=100346) (actual time=0.28..159 rows=50000 loops=1)
```

- Plan, count (tenant 1):

```text
-> Aggregate: count(0)  (cost=33205 rows=1) (actual time=23.7..23.7 rows=1 loops=1)
    -> Covering index lookup on bookings using bookings_tenant_id_foreign (tenant_id = 1)  (cost=10084 rows=100346) (actual time=0.977..19.5 rows=50000 loops=1)
```

- Plan (tenant 500): same shapes, 1,000 examined.
- Examined / returned / loops: page 50,000 → 15 (tenant 1), 1,000 → 15 (tenant 500); count 50,000 / 1,000 index entries → 1. Handlers: `read_key` 1, `read_next` 50,000 / 1,000.
- First / warm: tenant 1 page 181 / 163.5 ms, count 23.7 / 23.2 ms; tenant 500 page 2.08 / 2.03 ms, count 0.32 / 0.33 ms.
- Finding: the page cost is the full-row fetch of every tenant row plus a top-N sort (159 ms of the 181 ms is reading the rows, the sort itself ~22 ms). The count is covering (secondary leaves only), so it's 7× cheaper than the page but still O(tenant rows). The busy tenant pays 80× the small one for the same 15 rows.
- Estimate vs actual: optimizer estimated 100,346 rows for tenant 1, actual 50,000 — sampled index statistics (cardinality 1,001 over 1.48M rows assumes uniform tenants, but the 10 busy tenants are 50× the rest).
- Hypothesis for Day 3: `(tenant_id, created_at)` lets MySQL read the tenant's slice backwards and stop after 15 rows — `Sort` disappears. The count stays O(tenant rows); Day 4 decides (`simplePaginate`).

## Open questions

- Q3 (customer history) not measured today.
- First-run numbers are not cold: MySQL was not restarted between runs.

# Day 3 — two measured indexes (2026-10-21)

## Derivation (written before the migration)

1. Q2 with `(staff_id, starts_at)` — sorted entries for staff 20:
   `(20, 08-20 16:00), (20, 08-21 09:00), (20, 08-21 11:30), (20, 08-21 15:00), (20, 08-22 10:00)`.
   One day of one staff member is one contiguous run, already in `starts_at` order.
   The `Filter` and the `Sort` both disappear; the plan becomes a range scan plus
   ~6 clustered lookups.
2. Not covering for `select *` (or for `StaffCalendar`'s `id, starts_at, ends_at, status`):
   `ends_at` and `status` live only in the clustered index. Six lookups on a hot
   path are cheap. A covering `(staff_id, starts_at, ends_at, status)` would rewrite
   a wider secondary entry on every insert and every status change
   (confirmed → cancelled → completed). Decision: don't cover; revisit only if
   lookups dominate a measurement.
3. `ORDER BY created_at DESC` doesn't need a `DESC` index — InnoDB walks the leaf
   chain backwards. `DESC` matters only for mixed directions.
4. Q4's `count(*)` still visits every matching entry (50,000 for tenant 1). The new
   index doesn't change that; Day 4 decides (`simplePaginate` or a cached total).

## Migration

`2026_10_07_000001_replace_bookings_fk_indexes_with_query_indexes`: adds
`bookings_staff_starts_idx (staff_id, starts_at)` and
`bookings_tenant_created_idx (tenant_id, created_at)`, then drops the old FK
indexes only if they still exist.

- Open transactions checked first (`information_schema.innodb_trx`): none.
- Build time on 1,490,000 rows: **13 s** (artisan), 17 s wall including boot.
- After migrating, `SHOW INDEX` lists the two composites and no
  `bookings_staff_id_foreign` / `bookings_tenant_id_foreign`: MySQL dropped its
  own FK indexes as soon as the composites could serve the FKs, so the
  `hasIndex` guard skipped both `dropIndex` calls.
- `down()` round-tripped on `slotly_testing`: rollback restores the two
  single-column indexes, migrate brings the composites back.

## Before / after

Same SQL, same IDs, same method (warm = median of runs 2–3). `ANALYZE TABLE bookings` run after the build.

| Query | Before plan | After plan | Before warm | After warm | Examined → returned | Trade-off |
|---|---|---|---:|---:|---|---|
| Q2 staff 20 (busy) | FK lookup + filter + sort | range scan | 3.29 ms | 0.04 ms | 1,008 → 6 ⇒ 6 → 6 | wider entry than the FK index |
| Q2 staff 2946 (small) | FK lookup + filter + sort | range scan | 0.72 ms | 0.03 ms | 195 → 4 ⇒ 4 → 4 | same |
| Q4 tenant 1 page | FK lookup + top-N sort | reverse index lookup | 163.5 ms | 0.28 ms | 50,000 → 15 ⇒ 15 → 15 | — |
| Q4 tenant 500 page | FK lookup + top-N sort | reverse index lookup | 2.03 ms | 0.21 ms | 1,000 → 15 ⇒ 15 → 15 | — |
| Q4 tenant 1 count | covering lookup | covering lookup | 23.2 ms | 20.3 ms | still 50,000 | Day 4 decides |
| Q4 tenant 500 count | covering lookup | covering lookup | 0.33 ms | 0.27 ms | still 1,000 | — |

Handler counters after: Q2 `read_key` 1 + `read_next` 6 (staff 20) / 4 (staff 2946);
Q4 page `read_key` 1 + `read_prev` 14 (15 rows read backwards, then `Limit` stops).

Plans after:

```text
Q2 staff 20
-> Index range scan on bookings using bookings_staff_starts_idx over (staff_id = 20 AND '2025-08-21 00:00:00' <= starts_at <= '2025-08-21 23:59:59'), with index condition: (...)  (cost=4.04 rows=6) (actual time=0.0416..0.051 rows=6 loops=1)

Q4 page tenant 1
-> Limit: 15 row(s)  (cost=21267 rows=15) (actual time=0.457..0.462 rows=15 loops=1)
    -> Index lookup on bookings using bookings_tenant_created_idx (tenant_id = 1) (reverse)  (cost=21267 rows=103616) (actual time=0.456..0.46 rows=15 loops=1)
```

`StaffCalendar::day()` (half-open `>= day AND < next day`, 4 columns) gets the
same range scan: `read_key` 1, `read_next` 6.

The busy/small gap is gone for the page and the calendar: both now cost the rows
they return. Only the count still scales with tenant size.

## Hygiene and write cost

| Index | Pages before | Pages after |
| --- | ---: | ---: |
| PRIMARY | 9,004 | 9,004 |
| bookings_staff_id_foreign → bookings_staff_starts_idx | 4,265 | 2,790 |
| bookings_tenant_id_foreign → bookings_tenant_created_idx | 2,021 | 2,790 |
| bookings_service_id_foreign | 3,560 | 3,560 |
| bookings_customer_id_foreign | 3,752 | 3,752 |

Total `index_length`: 212.5 MB → 201.4 MB. The 2-column entries are wider, but
the old staff index was fragmented by the seeder's tenant-by-tenant inserts
(random positions in `staff_id` order, page splits); the new ones were bulk-built
sorted and packed. Expect them to grow toward the old density under real inserts.

`sys.schema_redundant_indexes`: empty. Secondary index count stays at four.

Write cost: every booking insert still touches 5 trees. A reschedule (changing
`starts_at`, Week 9) now also moves an entry in `bookings_staff_starts_idx`;
`created_at` never changes, so `bookings_tenant_created_idx` costs nothing after insert.

Keep/remove decisions:

- `bookings_staff_starts_idx` — keep (Q2; also serves the `staff_id` FK).
- `bookings_tenant_created_idx` — keep (Q4; also serves the `tenant_id` FK).
- `bookings_service_id_foreign`, `bookings_customer_id_foreign` — keep for the FKs; Q3 (customer history) not audited yet.

## Code

`App\Domain\Scheduling\StaffCalendar::day()` holds Q2, so the app and the tests run
the same query. It uses a half-open day — `whereBetween(…, '23:59:59')` from the
Day 2 call drops a booking at 23:59:30; the dataset in `StaffCalendarTest` covers it.

## Open questions

- `$dayStartUtc` is a UTC instant chosen by the caller. A tenant's "5 October" in
  Cairo or Paris is a different UTC range, and DST days are 23 or 25 hours — Week 6.
- Q4 `count(*)` is still 20 ms for a busy tenant on every page load — Day 4.

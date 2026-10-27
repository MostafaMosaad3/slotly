# Concurrency notes — Week 2

## Snapshot vs current read (one staff member, 2026-11-02 10:00–11:00)

Session A runs the overlap `COUNT(*)` inside a transaction; session B inserts a confirmed 10:00–11:00 booking in autocommit between A's reads.

| Step | REPEATABLE READ | READ COMMITTED |
|---|---|---|
| A: first SELECT | 0 | 0 |
| B: insert + commit | — | — |
| A: same SELECT | 0 | 1 |
| A: same SELECT … FOR UPDATE | 1 | 1 |

Under REPEATABLE READ the read view is created by A's first SELECT and reused, so B's commit stays invisible to plain reads. `FOR UPDATE` is a current read and sees it. READ COMMITTED takes a new read view per statement, so the second plain read already sees it.

## The overlap query and its index

`Booking::overlapping()` is `staff_id = ? AND starts_at < :end AND ends_at > :start AND status != 'cancelled'`. It is served by `(staff_id, starts_at)` from Week 1 Day 3: equality on `staff_id`, range on `starts_at`. `ends_at` and `status` are filtered on the rows the range returns.

Limit: `ends_at > :start` can't use the index, so the scan covers every booking of that staff member starting before `:end`. Fine while bookings last minutes to hours; revisit in Week 7.

## The double booking

Two transactions both run the plain overlap check, both see 0, both insert, both commit → 2 bookings for the same slot. It's write skew: no row is written by both, so MVCC sees no conflict. Covered by `SnapshotReadTest` ("allows a double booking…"), which Day 2 flips.

What surprised me:

## Lock map — before (no lower bound)

Database `slotly_load_test` (1.49M bookings), MySQL 9.1.0, REPEATABLE READ. Staff 120 has 997 bookings; 418 of them start before 2025-09-04 11:00. That day: 01:36, 03:30, 03:44, 06:28 (cancelled), 10:01 (cancelled), 18:12.

Session A holds:

```sql
SELECT id FROM bookings
WHERE staff_id = 120 AND starts_at < '2025-09-04 11:00:00' AND ends_at > '2025-09-04 10:00:00'
  AND status != 'cancelled'
FOR UPDATE;
```

0 rows match, plan uses `bookings_staff_starts_idx`. `performance_schema.data_locks` for `bookings`: **839 rows**.

| index | lock_mode | count |
|---|---|---|
| (table) | IX | 1 |
| bookings_staff_starts_idx | X (next-key) | 420 |
| PRIMARY | X,REC_NOT_GAP | 418 |

418 next-key locks on every staff 120 entry scanned from the very first one, one on 18:12 (the first entry past `starts_at < 11:00`, which stops the scan), and one on a page's supremum pseudo-record that the scan crossed. 418 PRIMARY record locks: each scanned entry was looked up to check `ends_at`/`status`, so the clustered row got locked too, even though none matched.

Inserts from session B (`innodb_lock_wait_timeout = 2`):

| insert | result |
|---|---|
| staff 120, 2025-09-04 10:00 | waited (1205) |
| staff 120, 2025-09-04 14:00 (doesn't overlap, gap before 18:12) | waited (1205) |
| staff 120, 2025-09-04 20:00 (after 18:12) | went through |
| staff 120, 2025-06-01 10:00 (three months earlier) | waited (1205) |
| staff 101, 2025-09-04 10:00 | went through |

The June insert is the problem: a check on one hour locks the staff member's entire history.

## Lock map — after (`starts_at > :start - 240 min`)

Same query plus `AND starts_at > '2025-09-04 06:00:00'`. `data_locks`: **6 rows**.

| index | lock_mode | count |
|---|---|---|
| (table) | IX | 1 |
| bookings_staff_starts_idx | X (next-key) | 3 |
| PRIMARY | X,REC_NOT_GAP | 2 |

Next-key on 06:28 and 10:01 (scanned, both cancelled → filtered after the lock) and on 18:12 (stops the scan). Same inserts: 10:00 and 14:00 still wait, 20:00 and staff 101 go through, and **June now goes through**. 839 → 6.

## Business rule created by the lower bound

The check only looks back `scheduling.max_booking_minutes` (240). A booking longer than that, starting earlier, is invisible to it: a 05:00–10:30 booking doesn't block 10:00–11:00 (`BookSlotTest` pins this). So no booking may last over 240 minutes, and booking code must reject longer ones — validated in Week 7. Longest booking in the load-test data today: 75 minutes.

## BookSlot

`App\Domain\Bookings\BookSlot`: in `DB::transaction(attempts: 3)` lock the staff row, then the overlap check `FOR UPDATE` (current read), throw `SlotTaken` if busy, insert. Same-staff requests queue on the staff row while holding nothing, so they can't deadlock each other. Two range checks on an empty slot can: gap locks are compatible, inserts into a locked gap aren't (`LockMapTest`).

The Day 1 "allows a double booking" test was deleted; `LockMapTest` "serializes two bookings…" replaces it with final count 1.

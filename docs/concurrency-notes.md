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

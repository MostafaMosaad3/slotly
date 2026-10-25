# Slotly — Multi-Tenant Booking & Subscription Platform

> **Working name — rename before Week 5.**
> This README is written **before the code** (README-driven development). It's the target: every section marked 🚧 gets filled in as the project is built (Weeks 5–12). Copy this file into the new repo on Week 5 Day 1.

[![Tests](https://img.shields.io/badge/tests-🚧-lightgrey)](#) [![PHP](https://img.shields.io/badge/PHP-8.3-777BB4?logo=php&logoColor=white)](#) [![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)](#) [![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](#)

**Live demo:** 🚧 `https://…` · **API docs:** 🚧 · **Architecture decisions:** [`docs/adr/`](docs/adr)

---

## What It Is

Slotly lets service businesses — clinics, coaches, tutors, salons — run their bookings online. Each business (a **tenant**) signs up, publishes its services and staff availability, and takes **paid bookings** from customers. Businesses pay Slotly a **monthly subscription**.

It's built to show the parts of backend work that are easy to get wrong in production:

- **Two customers can never book the same slot**, even when they click at the same millisecond.
- **Payments reconcile**: every Stripe event is verified, processed exactly once, and safe to receive twice, late, or out of order.
- **Times are right in every timezone**, including daylight-saving changes.
- **One tenant can never see another tenant's data.**
- **It's deployed, monitored, and load-tested**, not just "works on my machine".

---

## Features

### For businesses (tenants)
- Sign up, onboard, and manage a subscription plan (Stripe via Laravel Cashier)
- Services with duration, price, buffer time, and capacity
- Staff members with weekly working hours and date exceptions (holidays, time off)
- Booking calendar with live updates when customers book or cancel
- Customer list and booking history
- Email notifications and configurable reminders

### For customers
- Browse a business's services and see **real** available slots in their own timezone
- Book and pay in one flow; a slot is **held** for a few minutes while paying
- Cancel or reschedule within the business's policy; refunds handled automatically
- Reminder emails before the appointment

### Platform
- REST API (versioned, `/api/v1`) with token auth (Sanctum)
- Real-time updates over WebSockets (Laravel Reverb)
- Admin panel (Filament) for platform operators
- Health check, error tracking (Sentry), structured logs, rate limiting

---

## Engineering Highlights

Each of these has a short Architecture Decision Record in [`docs/adr/`](docs/adr) explaining the options considered and the trade-offs.

### 1. No double-booking — enforced by the database, not by `if` statements
Checking "is this slot free?" and then inserting is a race condition. Slotly makes the database the referee:
- a **slot hold** is created inside a transaction that locks the staff member's slot range (`SELECT … FOR UPDATE`) before checking for overlaps;
- a **unique constraint** on active bookings is the last line of defence if application logic ever has a bug;
- a concurrency test fires parallel booking requests at the same slot and asserts exactly one succeeds.

→ `ADR-003`

### 2. Payments that survive retries, duplicates and reordering
- The card is **never charged inside a database transaction** — a retried transaction can't charge twice.
- Stripe webhooks are **signature-verified**, stored by event ID (**processed exactly once**), handled on a queue, and applied against the booking's current state (so an old event arriving late can't undo a newer one).
- Every payment and refund has an audit trail.

→ `ADR-004`

### 3. Time done right
- Everything is stored in **UTC**. Each tenant has an IANA timezone (`Africa/Cairo`, `Europe/Paris`); availability is defined in the tenant's local time and converted when slots are generated.
- Daylight-saving transitions are covered by tests (the skipped hour and the repeated hour).
- Customers see slots in their own timezone.

→ `ADR-002`

### 4. Tenant isolation
- 🚧 Strategy decided in `ADR-001` (single database with a `tenant_id` column + global scope, vs. a database per tenant).
- Whatever the strategy: automated tests that try to read and write another tenant's records and **must fail**.

### 5. Production-ready from the start
- Multi-stage Docker image, Nginx, zero-downtime deploys from CI on tagged releases
- Sentry for errors, structured JSON logs, `/health` endpoint
- Load test of the booking endpoint with published results (below)

---

## Architecture

🚧 Diagram added in Week 12.

```
                ┌──────────────┐        ┌───────────────┐
 Customer / ───►│  Nginx       │───────►│ Laravel API   │──► MySQL 8 (UTC, InnoDB)
 Business app   │  (TLS)       │        │ (PHP-FPM)     │──► Redis (cache, locks, queues)
                └──────────────┘        └──────┬────────┘
                                               │ events
                        ┌──────────────────────┼─────────────────────┐
                        ▼                      ▼                     ▼
                 Queue workers          Laravel Reverb          Scheduler
                 (webhooks, emails,     (live calendar          (reminders,
                  reminders)             updates)                expire holds)
                        │
                        ▼
                     Stripe  ◄── webhooks (signed) ──┐
                                                     │
                                          /api/v1/webhooks/stripe
```

### Domain model (first draft — finalised in Week 5)

```
Tenant ──< User (owner, staff)          Tenant ──1 Subscription (Cashier)
Tenant ──< Service                      Tenant ──< Customer
Tenant ──< StaffMember ──< WorkingHours
                       ──< AvailabilityException
StaffMember ──< Booking >── Service
Booking >── Customer
Booking ──< Payment ──< Refund
StripeEvent (id, type, processed_at)    ← webhook idempotency
```

Booking lifecycle:

```
held ──(payment succeeded)──► confirmed ──► completed
  │                               │   └───► no_show
  └──(hold expired)──► released   └──(cancel within policy)──► cancelled ──► refunded
```

---

## Tech Stack

| Area | Technology |
|---|---|
| Language / framework | PHP 8.3, Laravel 12 |
| Database | MySQL 8 (InnoDB) |
| Cache, locks, queues | Redis |
| Payments | Stripe, Laravel Cashier |
| Real-time | Laravel Reverb (WebSockets) |
| Admin | Filament |
| Auth | Laravel Sanctum |
| Testing | Pest, Larastan (static analysis), Laravel Pint (style) |
| Infrastructure | Docker (multi-stage), Nginx, GitHub Actions, VPS |
| Observability | Sentry, structured logs, health check |

---

## Getting Started

🚧 Finalised in Week 11 (Docker).

```bash
git clone https://github.com/MostafaMosaad3/slotly.git
cd slotly
cp .env.example .env
docker compose up -d
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

- API: `http://localhost:8080/api/v1`
- Admin panel: `http://localhost:8080/admin` (seeded admin: see `DatabaseSeeder`)
- Stripe webhooks locally: `stripe listen --forward-to localhost:8080/api/v1/webhooks/stripe`

### Running the tests

```bash
docker compose exec app php artisan test --parallel
docker compose exec app ./vendor/bin/phpstan analyse
docker compose exec app ./vendor/bin/pint --test
```

---

## Testing Strategy

- **Every feature ships with tests the same day** (Pest).
- **Concurrency tests** for booking and payment paths (parallel requests against a real MySQL, not SQLite).
- **Tenant-isolation tests** for every model that belongs to a tenant.
- **Timezone tests** around DST transitions.
- **Webhook tests** for duplicate, out-of-order and invalid-signature events.
- CI runs the suite against MySQL + Redis, plus Larastan and Pint, on every pull request.

---

## Performance

🚧 Filled in Week 12.

| Endpoint | Load | p50 | p95 | Errors |
|---|---|---|---|---|
| `GET /api/v1/{tenant}/slots` | 🚧 | 🚧 | 🚧 | 🚧 |
| `POST /api/v1/{tenant}/bookings` | 🚧 | 🚧 | 🚧 | 🚧 |

Tooling: k6. Script in `tests/load/`.

---

## Architecture Decision Records

| # | Decision | Status |
|---|---|---|
| [ADR-001](docs/adr/001-tenancy-strategy.md) | Tenancy strategy | 🚧 Week 5 |
| [ADR-002](docs/adr/002-time-and-timezones.md) | Storing and generating times across timezones | 🚧 Week 6 |
| [ADR-003](docs/adr/003-preventing-double-booking.md) | Preventing double-booking | 🚧 Week 7 |
| [ADR-004](docs/adr/004-payment-flow-and-webhooks.md) | Payment flow and webhook handling | 🚧 Week 8 |
| [ADR-005](docs/adr/005-real-time-updates.md) | Real-time updates | 🚧 Week 9 |
| [ADR-006](docs/adr/006-deployment.md) | Deployment and zero-downtime releases | 🚧 Week 11 |

Each ADR is half a page: **Context → Options → Decision → Consequences**.

---

## Roadmap

| Week | Milestone | Status |
|---|---|---|
| 5 | Domain model, ADR-001 tenancy, project skeleton, CI | ⬜ |
| 6 | Tenancy + auth + roles; services, staff, working hours; timezone model (ADR-002) | ⬜ |
| 7 | Slot generation; holds; no double-booking with concurrency tests (ADR-003) | ⬜ |
| 8 | Stripe: tenant subscriptions (Cashier) + paid bookings; webhooks (ADR-004) | ⬜ |
| 9 | Reverb live calendar; notifications; queued reminders (ADR-005) | ⬜ |
| 10 | Observability: Sentry, logs, health check, rate limiting | ⬜ |
| 11 | Docker multi-stage image, Docker Compose, Nginx (ADR-006) | ⬜ |
| 12 | Deploy to VPS with HTTPS; CD on tags; load test; architecture diagram; **live URL** | ⬜ |

---

## Project Structure

🚧 Filled in as the code takes shape.

```
app/
  Domain/            # Bookings, Availability, Billing, Tenancy — business logic, no HTTP
  Http/              # Controllers, Requests, Resources (thin)
  Jobs/ Listeners/   # Queued work: webhooks, emails, reminders
docs/
  adr/               # Architecture decision records
tests/
  Feature/           # API tests (incl. concurrency, tenancy, webhooks)
  Unit/              # Domain logic (slot generation, timezone math)
  load/              # k6 scripts
docker/              # Dockerfile, Nginx config
```

---

## About

Built by **Mostafa Mosaad**, backend developer (PHP / Laravel).
[LinkedIn](https://www.linkedin.com/in/mostafamosaad3/) · [GitHub](https://github.com/MostafaMosaad3)

Written from scratch as a portfolio project: the problems are the kind I solve at work (payments, concurrency, timezones, multi-tenant data), and the solutions here are my own.

## License

MIT

# ADR-001: Tenant data strategy
Status: draft (Week 1) — to be revised in Week 5 Day 5 from implemented code

## Context
Slotly serves independent businesses. Each business owns its catalog, staff,
customers and bookings. Users must only read or change data belonging to their
business. We need a tenancy strategy before designing migrations and indexes.
The initial application should be straightforward to operate and test.

## Options
1. One MySQL database with tenant_id on every tenant-owned row. This shares
   migrations and backups and makes cross-business administration simpler,
   but application queries must enforce isolation consistently.
2. One database per tenant, selected at request time. This provides a stronger
   storage boundary and easier individual tenant restores, but provisioning,
   connection routing and migrations become more complex as tenants grow.
   Selecting the wrong database is still a possible isolation failure.

## Decision
Use one MySQL database with an explicit tenant_id on every tenant-owned table.
This fits the initial operational needs and keeps schema changes manageable.
Revisit this draft against the actual implementation in Week 5 Day 5.

## Consequences
- A missing tenant scope on a read or write can expose or modify another
  business's data; tenant identity must be validated rather than trusted from input.
- Per-business uniqueness constraints must include tenant_id.
- Indexes for frequent queries within a business will usually start with tenant_id;
  confirm the remaining column order against actual query patterns.
- Cross-tenant read and write isolation tests are mandatory in Week 5.
- Shared backups and resources make individual restores and noisy tenants harder
  to handle than with a database per tenant.

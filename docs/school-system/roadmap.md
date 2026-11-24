# School Management System — Development Roadmap

## Phase 1 — Foundation (implementation after design review)

1. Finalize table-level schema, constraints, indexes, and migration/rollback policy from `database.md`.
2. Establish Composer PSR-4 namespaces, environment config, front controller, route registration, request/response objects, PDO transactions, and exception logging.
3. Add authentication hardening: session regeneration, password hashing, throttling, CSRF, secure cookies, password recovery design, and audit events.
4. Implement permission catalog, policies, middleware, and record-scope checks on HTML and JSON routes.
5. Implement local/testing-only `DemoUserSeeder` for parent, teacher, accountant, manager, and school owner; seed relational sample data and document credentials. No demo users in production.
6. Add role-specific dashboard shells using the loan system's sidebar, icons, palette treatment, typography, and modal/confirmation behavior, with school labels and no fake metrics.
7. Write setup and architecture documentation; show useful empty states until records exist.

Phase 1 acceptance: all five demo logins work with intended restrictions; production seed guard is verified; direct URL/API access is policy checked; UI shells share established visual structure; migrations apply and roll back safely.

## Phase 2 — School and student administration

School profile/branding, years/terms, classes/streams/subjects, student registration and history, guardian registration and verification, staff profiles and assignments, private document storage, and audit trails.

## Phase 3 — Academic operations

Assessment configuration, examinations, mark entry/import, validation, grading, review/approval/publication workflow, immutable result versions, grading history, academic monitoring and intervention records.

## Phase 4 — Finance

Fee structures, invoice generation/items, partial payments, allocations, receipts, statements, adjustments, refunds, reconciliation, financial audit and reports. Implement gateway abstraction; verify provider APIs, terms, callbacks and end-to-end behavior before claiming live integration.

## Phase 5 — Parent and student portals

Low-bandwidth responsive portals, linked-child switching, published records, attendance, homework, schedules, statements, receipts, notifications, announcements and meeting requests.

## Phase 6 — Intelligence

Authorized aggregate analytics services/endpoints, year/term/class/date filters, loading/error/empty states, indicator explanations, role scopes, and query/index tuning.

## Phase 7 — Extended services (after core stability)

Admissions, library, transport, boarding, health/welfare, inventory and internal O-Level clearance. Distinguish internal clearance from official NECTA eligibility.

## Phase 8 — Deployment and migration readiness

Ubuntu VPS, Nginx/PHP-FPM, HTTPS, secrets/environment config, backup and restore exercise, monitoring, retention/data-protection procedures, and Laravel migration roadmap.

## Verification strategy

At implementation, test domain rules and DB transactions, then feature-test authorization/workflows. Required coverage includes unrelated-child denial, assigned-class boundaries, accountant publication denial, max-mark validation, result version preservation, report snapshot consistency, idempotent payment callbacks, partial-payment balances, refund history, promotion history, real-data analytics, date filters, analytics authorization, empty-state behavior, and production demo-seed prevention. This planning deliverable has not run application tests.

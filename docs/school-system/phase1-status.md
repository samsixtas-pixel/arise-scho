# Phase 1 implementation status

## Implemented

- Isolated school portal entry points under `school/`, with a separate session cookie from the loan app.
- Environment-configured PDO connection requiring its own school database.
- Composer PSR-4 namespace plus local school autoloader.
- Core request/response/router primitives and web/API route registration files.
- Sign-in with password hashes, session ID regeneration, 8-failure/15-minute throttle keyed by email/IP hashes, CSRF form tokens, eight-hour idle expiry, audit events, and baseline security headers.
- Permission catalog and server-side authorization policy, including verified guardian-child checks and teacher class/year assignment checks.
- Foundation migration for identity, RBAC, school profile, academic calendar/structure, students/guardians/enrollment, staff assignments, assessments/results/version records, attendance, homework, fee structures/invoices/payments/allocations/refunds/adjustments/callbacks, report snapshots, notifications, audit logs, and login throttling.
- Environment-guarded role and permission seed data plus five required demo accounts and relational sample records.
- Role-aware dashboard shell reusing the loan app's Inter/Bootstrap/Bootstrap Icons/sidebar/topbar structure with a navy/gold school token layer. Current counts come from database queries.
- Local install and demo credentials documented in `installation.md`.

## Database changes

New schema files are under `database/school/migrations/`. Run them only against a dedicated school database through `php tools/school.php migrate`. The CLI migration ledger makes successful files repeatable; all DDL is `IF NOT EXISTS` and seed inserts are idempotent where applicable.

## Limitations and remaining work

- The connected school database has not been provisioned or migrated in this workspace; configure `SCHOOL_DB_*` first.
- The five demo accounts can be seeded only after migrations and only with `SCHOOL_APP_ENV=local` or `testing`. Never use that mode in production.
- Demo records are present in the seeder, not in the loan database.
- The dashboard shells are operational foundations. Student, finance, attendance, academic, and parent workflows are not yet implemented.
- No online payment provider, PDF renderer, QR verification endpoint, notification delivery provider, charts, imports, or production deployment configuration is claimed as complete.
- A schema rollback migration is not yet supplied; before applying to a production database, migration rollback/backup procedures need a reviewed deployment plan.
- The executable smoke checks passed (demo environment guard and password hash behavior); PHP syntax checks passed for school/app/route/CLI PHP files. The school schema migrations were applied to the dedicated local database, and repository username lookup plus password-hash verification passed for the four provisioned accounts. Web-session login, permission-scope integration, and module feature checks remain pending.

## Local database import and account provisioning

The foundation and RBAC migrations were imported into a fresh `school_management` database. A restricted `school_app` database account was created; connection details are stored in ignored `config/school.local.php` with mode 0600. The four requested user accounts were created with hashed passwords and verified role assignments: `mmari` → Subject Teacher, `shock` → School Manager, `tutu` → Accountant, `dejene` → School Owner. The named parent account was omitted per the supplied mapping; the Parent role remains in the role catalog. The running server reports MariaDB 10.4.32, and the migrations applied successfully there; the planned deployment baseline remains MySQL 8.

# School app local installation

The school portal requires PHP 8.3+ and MySQL 8. The school portal is isolated at `/school/` and uses a separate `SCHOOL_SESSION` cookie and database connection. It does not use or modify the loan application's database.

1. Create a dedicated MySQL 8 database and user for the school app.
2. Export `SCHOOL_DB_HOST`, `SCHOOL_DB_NAME`, `SCHOOL_DB_USER`, and `SCHOOL_DB_PASS` in the PHP process environment. Optionally set `SCHOOL_DB_SOCKET` and `SCHOOL_APP_ENV=local`.
3. From the project root, run `php tools/school.php migrate`.
4. For local demonstrations only, run `SCHOOL_APP_ENV=local php tools/school.php seed-demo`.
5. Open `/school/login.php`.

Demo accounts (local/testing only; all use `SchoolDemo@2026!`):

- Parent: `parent.demo@school.test`
- Teacher: `teacher.demo@school.test`
- Accountant: `accountant.demo@school.test`
- Manager: `manager.demo@school.test`
- School Owner: `owner.demo@school.test`

Never set the demo environment in production. The seeder refuses to run unless `SCHOOL_APP_ENV` is exactly `local` or `testing`. The current Phase 1 sample records include two linked children, one assigned teacher/class/subject, term/exam/results, attendance, homework, tuition invoices, and a partial payment. Payment gateway, PDF report generation, and later module workflows are not implemented in Phase 1.

## Locally provisioned accounts

The dedicated `school_management` database was provisioned separately from the loan and pre-existing school databases. A database-scoped `school_app` user connects through the ignored, mode-0600 `config/school.local.php` file. The local config is not committed.

The requested operational users are:

- `mmari` — Subject Teacher
- `shock` — School Manager
- `tutu` — Accountant
- `dejene` — School Owner

Their initial password was supplied by the administrator and stored only as PHP password hashes in the database. The Parent role remains available, with no named parent account provisioned.

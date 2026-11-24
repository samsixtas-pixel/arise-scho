# Weekly Loan Management System

A PHP and MySQL application for managing weekly loans, customer records,
repayments, arrears, user access, and operational reports.

## Features

- Customer records, documents, and officer assignments
- Loan products, applications, approvals, schedules, and disbursements
- Payment collection, allocation, reversals, and loan balance tracking
- Overdue-loan follow-up and daily reconciliation
- User roles, audit history, settings, and notifications
- Reports, customer statements, and workbook export
- Session protection, CSRF checks, login throttling, and hashed passwords

## Requirements

- PHP 8.0+ with PDO MySQL and mbstring
- MySQL or MariaDB
- Composer dependencies from `composer.json`

## Setup

1. Copy the project into the web server document root.
2. Create the `loan_ims` database in MySQL, or set `DB_NAME` to the database
   you want to use.
3. Configure `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, and optionally
   `DB_SOCKET` in the environment. The local XAMPP defaults are configured in
   `config/Database.php`.
4. Install dependencies with `composer install`.
5. Apply the schema with `/opt/lampp/bin/php tools/install.php`. The canonical
   schema is `database/schema.sql`; it creates missing tables and refreshes the
   reporting views without dropping existing tables or records.
6. Create the first administrator in the `users` table using a
   `password_hash()` value. Do not store plaintext passwords in project files.
7. Open the application's login page.

For detailed XAMPP instructions and the first-admin SQL template, see
[INSTALLATION.md](./INSTALLATION.md).

## Database source

`database/schema.sql` is the canonical loan-system schema. The root
`database.sql` file is kept as a matching import copy for phpMyAdmin workflows.
Both target the database selected by the connection configuration; neither
should be used to replace a populated database.

Running `tools/install.php` on an older database adds any missing
identity-capture columns and indexes in place, so existing customer, loan and
payment records are preserved.

## School management application (Phase 1)

A separate school-system foundation is available under `/school/`. It uses its own `SCHOOL_SESSION` and a dedicated database configured through `SCHOOL_DB_*`; it does not use the loan-management database. See [school installation](docs/school-system/installation.md), [architecture](docs/school-system/architecture.md), [ERD/data dictionary](docs/school-system/database.md), [permissions](docs/school-system/roles-permissions.md), and [roadmap](docs/school-system/roadmap.md). Do not configure demo seeding in production.

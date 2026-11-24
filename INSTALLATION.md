# Installation

These instructions use XAMPP on Linux. Adjust the paths for other platforms.

## Requirements

- PHP 8.0+ with `pdo_mysql` and `mbstring`
- MySQL or MariaDB
- Composer

## Configure XAMPP

1. Start Apache and MySQL.
2. Copy the project folder into `/opt/lampp/htdocs/loan`.
3. Create the database in phpMyAdmin:

   ```sql
   CREATE DATABASE loan_ims
     CHARACTER SET utf8mb4
     COLLATE utf8mb4_unicode_ci;
   ```

4. The default connection in `config/Database.php` targets `loan_ims` using
   XAMPP's local MySQL socket and the default local account. To use different
   settings, configure `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, or
   `DB_SOCKET` in the PHP process environment.
5. Install PHP dependencies:

   ```bash
   composer install
   ```

6. Apply the project schema:

   ```bash
   /opt/lampp/bin/php tools/install.php
   ```

   `database/schema.sql` is the canonical schema. It adds missing tables,
   missing identity-capture columns and refreshes views; it does not drop
   existing tables or records. The root `database.sql` is a matching import
   copy for phpMyAdmin. Upgrading an older install therefore only needs the
   same `tools/install.php` command.

## Create the first administrator

The installer does not create an account with a predictable password. Generate
a password hash with PHP, then insert an administrator using the hash. Replace
the placeholders locally; do not put real passwords in project files:

```bash
/opt/lampp/bin/php -r '$password = trim(fgets(STDIN)); echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;'
```

In phpMyAdmin, select `loan_ims` and run the following after replacing the
username and hash placeholders:

```sql
INSERT INTO users (full_name, username, password, role, status, preferred_language)
VALUES ('System Administrator', 'REPLACE_WITH_USERNAME',
        'REPLACE_WITH_PASSWORD_HASH', 'admin', 'active', 'en');
```

The application stores only the generated password hash.

## Open the application

With the folder at `/opt/lampp/htdocs/loan`, open
`http://localhost/loan/login.php`.

The base URL is defined in `config/config.php`; update `BASE_URL` if the folder
name or web-server path differs.

## Import workbook data

To import the supplied weekly-loan workbook after the schema and administrator
account are ready, run:

```bash
/opt/lampp/bin/php tools/import_legacy.php
```

## Troubleshooting

- **Database connection failed:** Confirm MySQL is running and that the
  database name and credentials match the configured environment variables.
- **Missing `mb_strlen()`:** Enable the PHP `mbstring` extension.
- **Missing Composer classes:** Run `composer install` from the project root.

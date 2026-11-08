# Database migrations

MPS uses a small PHP CLI migrator and PDO; no Composer package is required.
The migrator supports MySQL/MariaDB and SQLite. Set `MPS_DB_DRIVER=sqlite`
before running it for a local SQLite database; the default is MySQL.

```powershell
php bin/migrate.php status
php bin/migrate.php migrate
```

## Existing installations

Back up the database first. The first migration is a baseline snapshot of the
current schema. Record that baseline without replaying its DDL, then apply any
pending migrations:

```powershell
php bin/migrate.php baseline
php bin/migrate.php migrate
```

`baseline` only succeeds when all current MPS tables exist. It is intended for
databases previously created from `schema.sql`; it does not modify their data.
If the database is partial or has unknown schema changes, inspect and reconcile
it before baselining.

## Adding a migration

Add the next unused four-digit version in `migrations/`, for example
`0002_add_example_field.php`:

```php
<?php
declare(strict_types=1);

return [
    'up' => static function (PDO $pdo, MigrationRunner $runner): void {
        $pdo->exec('ALTER TABLE example ADD COLUMN example_field VARCHAR(80) NULL');
    },
];
```

Keep applied migrations immutable. Fix a migration before it is deployed; after
deployment, add a new migration for further changes. The runner checks checksums
for migration files and declared `checksum_files` dependencies. Migrations are
forward-only because MySQL DDL often commits implicitly; back up production data
before applying schema changes.

`schema.sql` is used for MySQL/MariaDB and `schema.sqlite.sql` for SQLite. The
initial migration only runs when the selected database has no existing MPS
tables. Post-baseline additions belong in versioned migrations, not in the
initial schema snapshot.

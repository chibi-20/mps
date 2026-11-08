<?php
declare(strict_types=1);

/**
 * Small forward-only migration runner for MySQL/MariaDB and SQLite.
 * Migrations are PHP files returning ['up' => callable(PDO, self): void].
 */
final class MigrationRunner
{
    private const HISTORY_TABLE = 'schema_migrations';

    /** Tables that make up the current legacy MPS schema. */
    private const APP_TABLES = [
        'users',
        'user_grade_levels',
        'user_subjects',
        'password_reset_requests',
        'school_years',
        'terms',
        'subjects',
        'sections',
        'teacher_assignments',
        'assessments',
        'score_frequencies',
        'item_correct_counts',
        'assessment_sections',
        'competencies',
        'assessment_item_competencies',
        'teacher_assessment_encodings',
        'grade_enrollment',
        'students',
        'student_enrollments',
    ];

    public function __construct(
        private PDO $pdo,
        private string $migrationsPath
    ) {
    }

    public function status(): void
    {
        $hasHistory = $this->historyTableExists();
        $applied = $hasHistory ? $this->appliedMigrations() : [];
        $files = $this->migrationFiles();

        if (!$hasHistory) {
            if ($this->hasAnyAppTables()) {
                echo "Legacy MPS schema found without migration history.\n";
                echo "Back up the database, then run: php bin/migrate.php baseline\n\n";
            } else {
                echo "Empty database; initial schema migration is pending.\n\n";
            }
        }

        foreach ($files as $version => $file) {
            $name = basename($file, '.php');
            if (!isset($applied[$version])) {
                echo "PENDING  {$name}\n";
                continue;
            }
            $checksum = $this->migrationChecksum($file);
            if (!hash_equals($applied[$version]['checksum'], $checksum)) {
                echo "CHANGED  {$name} (applied migration file was edited)\n";
                continue;
            }
            echo "APPLIED  {$name} at {$applied[$version]['applied_at']}\n";
        }
    }

    public function migrate(): void
    {
        $hasHistory = $this->historyTableExists();
        if (!$hasHistory && $this->hasAnyAppTables()) {
            throw new RuntimeException(
                "This database already contains MPS tables but has no migration history. " .
                "Back it up and run `php bin/migrate.php baseline` to adopt the current schema."
            );
        }

        $this->createHistoryTable();
        $applied = $this->appliedMigrations();
        $files = $this->migrationFiles();

        foreach ($applied as $version => $record) {
            if (!isset($files[$version])) {
                throw new RuntimeException(
                    "Applied migration {$version} ({$record['migration']}) has no corresponding file. Restore the file before migrating."
                );
            }
            $checksum = $this->migrationChecksum($files[$version]);
            if (!hash_equals($record['checksum'], $checksum)) {
                throw new RuntimeException(
                    "Applied migration {$record['migration']} was edited. Restore it and add a new migration instead."
                );
            }
        }

        $pending = array_diff_key($files, $applied);

        if ($pending && $this->hasAnyAppTables()) {
            $firstVersion = array_key_first($pending);
            if ($firstVersion === '0001') {
                throw new RuntimeException(
                    "The initial migration is pending, but MPS tables already exist. " .
                    "If this is an existing installation, run `php bin/migrate.php baseline`; " .
                    "if a fresh install was interrupted, inspect the database before continuing."
                );
            }
        }

        if (!$pending) {
            echo "Database is up to date.\n";
            return;
        }

        foreach ($pending as $version => $file) {
            $name = basename($file, '.php');
            $migration = require $file;
            if (!is_array($migration) || !isset($migration['up']) || !is_callable($migration['up'])) {
                throw new RuntimeException("Migration {$name} must return an array with a callable 'up'.");
            }

            echo "Applying {$name}... ";
            $migration['up']($this->pdo, $this);
            $checksum = $this->migrationChecksum($file);
            $stmt = $this->pdo->prepare(
                'INSERT INTO `' . self::HISTORY_TABLE . '` (version, migration, checksum) VALUES (?, ?, ?)'
            );
            $stmt->execute([$version, $name, $checksum]);
            echo "done.\n";
        }
    }

    /** Adopt a complete pre-migrator installation without replaying its DDL. */
    public function baseline(): void
    {
        $files = $this->migrationFiles();
        if (!isset($files['0001'])) {
            throw new RuntimeException('The initial schema migration (0001) is missing.');
        }
        if (!$this->hasAllAppTables()) {
            throw new RuntimeException(
                'Cannot baseline: the database is missing one or more current MPS tables. ' .
                'Compare it with schema.sql and resolve the schema before adopting it.'
            );
        }

        $this->createHistoryTable();
        if ($this->appliedMigrations()) {
            throw new RuntimeException('Migration history already contains entries; baseline is only for legacy databases.');
        }

        $file = $files['0001'];
        $stmt = $this->pdo->prepare(
            'INSERT INTO `' . self::HISTORY_TABLE . '` (version, migration, checksum) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            '0001',
            basename($file, '.php'),
            $this->migrationChecksum($file),
        ]);
        echo "Current MPS schema recorded as baseline (0001). No application tables were changed.\n";
    }

    /** Execute a SQL file as individual statements (PDO MySQL does not need multi-statement mode). */
    public function executeSqlFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Could not read SQL file: {$path}");
        }

        // Project SQL files terminate each statement with a semicolon at line end.
        // Keeping the split line-anchored avoids splitting semicolons in string values.
        $statements = preg_split('/;[ \t]*(?:\r?\n|$)/', $sql) ?: [];
        foreach ($statements as $statement) {
            $statement = preg_replace(
                '/\A(?:\s|--[^\r\n]*(?:\r?\n|\z)|#[^\r\n]*(?:\r?\n|\z)|\/\*.*?\*\/)*/s',
                '',
                $statement
            ) ?? trim($statement);
            if (trim($statement) !== '') {
                $this->pdo->exec($statement);
            }
        }
    }

    public function driver(): string
    {
        return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @return array<string, string> version => migration file */
    private function migrationFiles(): array
    {
        $paths = glob(rtrim($this->migrationsPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($paths, SORT_STRING);
        $files = [];

        foreach ($paths as $path) {
            $name = basename($path, '.php');
            if (!preg_match('/^(\d{4})_[a-z0-9_]+$/', $name, $matches)) {
                throw new RuntimeException("Invalid migration filename: {$name}. Expected 0001_description.php.");
            }
            if (isset($files[$matches[1]])) {
                throw new RuntimeException("Duplicate migration version: {$matches[1]}.");
            }
            $files[$matches[1]] = $path;
        }
        return $files;
    }

    private function migrationChecksum(string $file): string
    {
        $migration = require $file;
        if (!is_array($migration)) {
            throw new RuntimeException('Migration must return an array: ' . basename($file));
        }

        $context = hash_init('sha256');
        hash_update_file($context, $file);
        foreach (($migration['checksum_files'] ?? []) as $dependency) {
            if (!is_string($dependency) || !is_file($dependency)) {
                throw new RuntimeException('Missing migration checksum dependency in ' . basename($file));
            }
            hash_update($context, "\n-- dependency: " . basename($dependency) . " --\n");
            hash_update_file($context, $dependency);
        }
        return hash_final($context);
    }

    /** @return array<string, array{migration:string, checksum:string, applied_at:string}> */
    private function appliedMigrations(): array
    {
        if (!$this->historyTableExists()) {
            return [];
        }
        $rows = $this->pdo->query(
            'SELECT version, migration, checksum, applied_at FROM `' . self::HISTORY_TABLE . '` ORDER BY version'
        )->fetchAll();
        $applied = [];
        foreach ($rows as $row) {
            $applied[$row['version']] = $row;
        }
        return $applied;
    }

    private function createHistoryTable(): void
    {
        $definition =
            'CREATE TABLE IF NOT EXISTS `' . self::HISTORY_TABLE . '` (' .
            'version CHAR(4) NOT NULL PRIMARY KEY,' .
            'migration VARCHAR(190) NOT NULL UNIQUE,' .
            'checksum CHAR(64) NOT NULL,' .
            'applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP' .
            ')';
        $this->pdo->exec($definition . ($this->driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    }

    private function historyTableExists(): bool
    {
        return $this->tableExists(self::HISTORY_TABLE);
    }

    private function hasAnyAppTables(): bool
    {
        return count($this->existingAppTables()) > 0;
    }

    private function hasAllAppTables(): bool
    {
        return count($this->existingAppTables()) === count(self::APP_TABLES);
    }

    /** @return list<string> */
    private function existingAppTables(): array
    {
        if ($this->driver() === 'sqlite') {
            $placeholders = implode(',', array_fill(0, count(self::APP_TABLES), '?'));
            $stmt = $this->pdo->prepare(
                "SELECT name AS table_name FROM sqlite_master WHERE type = 'table' AND name IN ({$placeholders})"
            );
            $stmt->execute(self::APP_TABLES);
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'table_name');
        }
        $placeholders = implode(',', array_fill(0, count(self::APP_TABLES), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT table_name FROM information_schema.tables ' .
            'WHERE table_schema = DATABASE() AND table_name IN (' . $placeholders . ')'
        );
        $stmt->execute(self::APP_TABLES);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'table_name');
    }

    private function tableExists(string $table): bool
    {
        if ($this->driver() === 'sqlite') {
            $stmt = $this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1");
            $stmt->execute([$table]);
            return (bool)$stmt->fetchColumn();
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
}

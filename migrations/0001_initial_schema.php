<?php
declare(strict_types=1);

return [
    'checksum_files' => [__DIR__ . '/../schema.sql', __DIR__ . '/../schema.sqlite.sql'],
    'up' => static function (PDO $pdo, MigrationRunner $runner): void {
        // MigrationRunner verifies this is a fresh MPS database before running
        // the initial schema DDL.
        $schema = $runner->driver() === 'sqlite' ? 'schema.sqlite.sql' : 'schema.sql';
        $runner->executeSqlFile(__DIR__ . '/../' . $schema);
    },
];

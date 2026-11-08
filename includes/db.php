<?php
require_once __DIR__ . '/config.php';

function get_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        if (DB_DRIVER === 'sqlite') {
            $directory = dirname(SQLITE_PATH);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not create SQLite data directory: ' . $directory);
            }
            $dsn = 'sqlite:' . SQLITE_PATH;
            $user = null;
            $password = null;
        } elseif (DB_DRIVER === 'mysql') {
            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
            $user = DB_USER;
            $password = DB_PASS;
        } else {
            throw new RuntimeException('Unsupported MPS_DB_DRIVER: ' . DB_DRIVER);
        }
        $pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        if (DB_DRIVER === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }
    return $pdo;
}

function is_sqlite(): bool
{
    return DB_DRIVER === 'sqlite';
}

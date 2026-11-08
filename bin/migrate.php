<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/MigrationRunner.php';

$command = $argv[1] ?? 'status';
$commands = ['status', 'migrate', 'baseline'];
if (!in_array($command, $commands, true)) {
    fwrite(STDERR, "Usage: php bin/migrate.php [status|migrate|baseline]\n");
    exit(2);
}

try {
    $runner = new MigrationRunner(get_pdo(), __DIR__ . '/../migrations');
    switch ($command) {
        case 'status':
            $runner->status();
            break;
        case 'migrate':
            $runner->migrate();
            break;
        case 'baseline':
            $runner->baseline();
            break;
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Migration error: {$error->getMessage()}\n");
    exit(1);
}

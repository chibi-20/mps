<?php
declare(strict_types=1);

return [
    'up' => static function (PDO $pdo, MigrationRunner $runner): void {
        $sqlite = $runner->driver() === 'sqlite';

        // Reconstruct the catalog from the original numeric grade columns.
        // Existing grade IDs are retained; only missing levels are inserted.
        $pdo->exec($sqlite
            ? 'INSERT OR IGNORE INTO grade_levels (level) SELECT grade_level FROM sections UNION SELECT grade_level FROM subjects'
            : 'INSERT IGNORE INTO grade_levels (level) SELECT grade_level FROM sections UNION SELECT grade_level FROM subjects');

        foreach (['subjects', 'sections'] as $table) {
            if ($sqlite) {
                $pdo->exec("UPDATE {$table} SET grade_level_id=(SELECT id FROM grade_levels WHERE level={$table}.grade_level) WHERE grade_level_id IS NULL OR grade_level_id<>(SELECT id FROM grade_levels WHERE level={$table}.grade_level)");
            } else {
                $pdo->exec("UPDATE {$table} t JOIN grade_levels g ON g.level=t.grade_level SET t.grade_level_id=g.id WHERE t.grade_level_id IS NULL OR t.grade_level_id<>g.id");
            }
            $bad = $pdo->query("SELECT COUNT(*) FROM {$table} t LEFT JOIN grade_levels g ON g.id=t.grade_level_id WHERE t.grade_level_id IS NULL OR g.id IS NULL OR g.level<>t.grade_level")->fetchColumn();
            if ((int)$bad !== 0) {
                throw new RuntimeException("Could not restore grade catalog links for {$table}; {$bad} row(s) do not match the legacy grade value.");
            }
        }
    },
];

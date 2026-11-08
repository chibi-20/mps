<?php
declare(strict_types=1);

return [
    'up' => static function (PDO $pdo, MigrationRunner $runner): void {
        $sqlite = $runner->driver() === 'sqlite';
        $hasColumn = static function (string $table, string $column) use ($pdo, $sqlite): bool {
            if ($sqlite) {
                foreach ($pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC) as $info) {
                    if ($info['name'] === $column) return true;
                }
                return false;
            }
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
            $stmt->execute([$table, $column]);
            return (bool)$stmt->fetchColumn();
        };
        $hasIndex = static function (string $table, string $index) use ($pdo, $sqlite): bool {
            if ($sqlite) {
                $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='index' AND name=?");
                $stmt->execute([$index]);
                return (bool)$stmt->fetchColumn();
            }
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
            $stmt->execute([$table, $index]);
            return (bool)$stmt->fetchColumn();
        };
        $hasForeignKey = static function (string $table, string $column) use ($pdo, $sqlite): bool {
            if ($sqlite) return false; // SQLite adds the grade-level FK with the column.
            $stmt = $pdo->prepare('SELECT 1 FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name=? AND column_name=? AND referenced_table_name=\'grade_levels\' AND referenced_column_name=\'id\'');
            $stmt->execute([$table, $column]);
            return (bool)$stmt->fetchColumn();
        };

        $pdo->exec($sqlite
            ? 'CREATE TABLE IF NOT EXISTS grade_levels (id INTEGER PRIMARY KEY AUTOINCREMENT, level INTEGER NOT NULL UNIQUE)'
            : 'CREATE TABLE IF NOT EXISTS grade_levels (id INT AUTO_INCREMENT PRIMARY KEY, level TINYINT NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        if ($sqlite) {
            $pdo->exec('INSERT OR IGNORE INTO grade_levels (level) SELECT grade_level FROM sections UNION SELECT grade_level FROM subjects');
        } else {
            $pdo->exec('INSERT IGNORE INTO grade_levels (level) SELECT grade_level FROM sections UNION SELECT grade_level FROM subjects');
        }

        foreach (['subjects', 'sections'] as $table) {
            if (!$hasColumn($table, 'grade_level_id')) {
                $pdo->exec($sqlite
                    ? "ALTER TABLE {$table} ADD COLUMN grade_level_id INTEGER NULL REFERENCES grade_levels(id) ON DELETE RESTRICT"
                    : "ALTER TABLE {$table} ADD COLUMN grade_level_id INT NULL");
            }
            if ($sqlite) {
                $pdo->exec("UPDATE {$table} SET grade_level_id=(SELECT id FROM grade_levels WHERE level={$table}.grade_level) WHERE grade_level_id IS NULL OR grade_level_id<>(SELECT id FROM grade_levels WHERE level={$table}.grade_level)");
            } else {
                $pdo->exec("UPDATE {$table} t JOIN grade_levels g ON g.level=t.grade_level SET t.grade_level_id=g.id WHERE t.grade_level_id IS NULL OR t.grade_level_id<>g.id");
            }

            $index = $table === 'subjects' ? 'idx_subject_grade_level_id' : 'idx_section_grade_level_id';
            if (!$hasIndex($table, $index)) $pdo->exec("CREATE INDEX {$index} ON {$table}(grade_level_id)");
            if (!$sqlite && !$hasForeignKey($table, 'grade_level_id')) {
                $fk = $table === 'subjects' ? 'fk_subject_grade_id' : 'fk_section_grade_id';
                $pdo->exec("ALTER TABLE {$table} ADD CONSTRAINT {$fk} FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE RESTRICT");
            }
        }

        if (!$hasIndex('subjects', 'uq_subject_grade_id')) {
            $pdo->exec('CREATE UNIQUE INDEX uq_subject_grade_id ON subjects(name, grade_level_id)');
        }
        // The earlier SQLite rebuild attempt dropped this index before its
        // parent-table swaps and could fail before recreating it.
        if (!$hasIndex('terms', 'idx_terms_sy')) {
            $pdo->exec('CREATE INDEX idx_terms_sy ON terms(school_year_id)');
        }
    },
];

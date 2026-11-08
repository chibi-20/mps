<?php
declare(strict_types=1);

return [
    'up' => static function (PDO $pdo, MigrationRunner $runner): void {
        if ($runner->driver() === 'sqlite') {
            $pdo->exec("CREATE TABLE assessment_section_ingestion_modes (
                assessment_id INTEGER NOT NULL,
                section_id INTEGER NOT NULL,
                mode TEXT NOT NULL CHECK (mode IN ('zipgrade','scanner')),
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (assessment_id, section_id),
                FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
                FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
            )");
            $pdo->exec("INSERT INTO assessment_section_ingestion_modes (assessment_id,section_id,mode)
                SELECT assessment_id,section_id,'zipgrade' FROM score_frequencies
                UNION SELECT assessment_id,section_id,'zipgrade' FROM item_correct_counts");
            return;
        }
        $pdo->exec("CREATE TABLE assessment_section_ingestion_modes (
            assessment_id INT NOT NULL,
            section_id INT NOT NULL,
            mode ENUM('zipgrade','scanner') NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (assessment_id, section_id),
            FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
            FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("INSERT INTO assessment_section_ingestion_modes (assessment_id,section_id,mode)
            SELECT assessment_id,section_id,'zipgrade' FROM score_frequencies
            UNION SELECT assessment_id,section_id,'zipgrade' FROM item_correct_counts");
    },
];

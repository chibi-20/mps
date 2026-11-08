<?php
declare(strict_types=1);

return [
    'up' => static function (PDO $pdo, MigrationRunner $runner): void {
        if ($runner->driver() === 'sqlite') {
            $pdo->exec("CREATE TABLE scanner_api_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                teacher_id INTEGER NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                label VARCHAR(100) NOT NULL DEFAULT 'MPS Scanner',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NULL,
                revoked_at DATETIME NULL,
                FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
            )");
            $pdo->exec('CREATE INDEX idx_scanner_tokens_teacher ON scanner_api_tokens(teacher_id, revoked_at)');
            $pdo->exec("CREATE TABLE assessment_answer_keys (
                assessment_id INTEGER NOT NULL,
                item_no INTEGER NOT NULL,
                correct_choice CHAR(1) NOT NULL CHECK (correct_choice IN ('A','B','C','D')),
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (assessment_id, item_no),
                FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE
            )");
            $pdo->exec("CREATE TABLE scanner_submissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                assessment_id INTEGER NOT NULL,
                section_id INTEGER NOT NULL,
                student_identifier VARCHAR(100) NOT NULL,
                score INTEGER NOT NULL,
                total_items INTEGER NOT NULL,
                source_submission_id VARCHAR(64) NOT NULL,
                scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (assessment_id, section_id, student_identifier),
                UNIQUE (source_submission_id),
                FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
                FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
            )");
            $pdo->exec("CREATE TABLE scanner_submission_answers (
                submission_id INTEGER NOT NULL,
                item_no INTEGER NOT NULL,
                selected_choice CHAR(1) NULL CHECK (selected_choice IS NULL OR selected_choice IN ('A','B','C','D')),
                is_correct INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (submission_id, item_no),
                FOREIGN KEY (submission_id) REFERENCES scanner_submissions(id) ON DELETE CASCADE
            )");
            return;
        }

        $pdo->exec("CREATE TABLE scanner_api_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            label VARCHAR(100) NOT NULL DEFAULT 'MPS Scanner',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            INDEX idx_scanner_tokens_teacher (teacher_id, revoked_at),
            FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE assessment_answer_keys (
            assessment_id INT NOT NULL,
            item_no SMALLINT NOT NULL,
            correct_choice CHAR(1) NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (assessment_id, item_no),
            FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE scanner_submissions (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            assessment_id INT NOT NULL,
            section_id INT NOT NULL,
            student_identifier VARCHAR(100) NOT NULL,
            score INT NOT NULL,
            total_items INT NOT NULL,
            source_submission_id VARCHAR(64) NOT NULL UNIQUE,
            scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_scanner_student (assessment_id, section_id, student_identifier),
            FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
            FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE scanner_submission_answers (
            submission_id BIGINT NOT NULL,
            item_no SMALLINT NOT NULL,
            selected_choice CHAR(1) NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (submission_id, item_no),
            FOREIGN KEY (submission_id) REFERENCES scanner_submissions(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];

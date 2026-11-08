<?php
declare(strict_types=1);

return [
    'up' => static function (PDO $pdo, MigrationRunner $runner): void {
        $pdo->exec('ALTER TABLE scanner_api_tokens ADD COLUMN expires_at_epoch BIGINT NULL');
        // Retire the long-lived tokens from the first integration draft. The
        // sign-in endpoint now creates short-lived teacher sessions instead.
        $pdo->exec('UPDATE scanner_api_tokens SET revoked_at=CURRENT_TIMESTAMP WHERE revoked_at IS NULL');
        if ($runner->driver() === 'sqlite') {
            $pdo->exec("CREATE TABLE scanner_audit_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                event_type VARCHAR(24) NOT NULL,
                outcome VARCHAR(24) NOT NULL,
                response_status INTEGER NOT NULL,
                actor_id INTEGER NULL,
                actor_username VARCHAR(50) NULL,
                actor_display VARCHAR(200) NULL,
                scanner_token_id INTEGER NULL,
                submission_id VARCHAR(64) NULL,
                assessment_id INTEGER NULL,
                assessment_title VARCHAR(200) NULL,
                subject_name VARCHAR(100) NULL,
                section_id INTEGER NULL,
                section_name VARCHAR(120) NULL,
                student_identifier VARCHAR(100) NULL,
                score INTEGER NULL,
                total_items INTEGER NULL,
                payload_json TEXT NULL,
                payload_sha256 CHAR(64) NULL,
                remote_ip VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec('CREATE INDEX idx_scanner_audit_actor_time ON scanner_audit_events(actor_id, created_at)');
            $pdo->exec('CREATE INDEX idx_scanner_audit_assessment_student ON scanner_audit_events(assessment_id, student_identifier, created_at)');
            $pdo->exec('CREATE INDEX idx_scanner_audit_outcome_time ON scanner_audit_events(outcome, created_at)');
            return;
        }
        $pdo->exec("CREATE TABLE scanner_audit_events (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(24) NOT NULL,
            outcome VARCHAR(24) NOT NULL,
            response_status SMALLINT NOT NULL,
            actor_id INT NULL,
            actor_username VARCHAR(50) NULL,
            actor_display VARCHAR(200) NULL,
            scanner_token_id INT NULL,
            submission_id VARCHAR(64) NULL,
            assessment_id INT NULL,
            assessment_title VARCHAR(200) NULL,
            subject_name VARCHAR(100) NULL,
            section_id INT NULL,
            section_name VARCHAR(120) NULL,
            student_identifier VARCHAR(100) NULL,
            score INT NULL,
            total_items INT NULL,
            payload_json JSON NULL,
            payload_sha256 CHAR(64) NULL,
            remote_ip VARCHAR(45) NULL,
            user_agent VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_scanner_audit_actor_time (actor_id, created_at),
            INDEX idx_scanner_audit_assessment_student (assessment_id, student_identifier, created_at),
            INDEX idx_scanner_audit_outcome_time (outcome, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    },
];

-- Historical proposal retained for reference only.
-- Implemented in migrations/0002_scanner_submissions.php using
-- scanner_submissions and scanner_submission_answers; do not apply this file.

CREATE TABLE scan_import_receipts (
    scanner_submission_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    supersedes_submission_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    assessment_id INT NOT NULL,
    section_id INT NOT NULL,
    teacher_id INT NOT NULL,
    student_identifier VARCHAR(32) NOT NULL,
    attempt_no SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    score INT UNSIGNED NOT NULL,
    total_items INT UNSIGNED NOT NULL,
    correct_item_numbers JSON NOT NULL,
    payload_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    state ENUM('active', 'reversed') NOT NULL DEFAULT 'active',
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reversed_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_receipt_supersedes (supersedes_submission_id),
    KEY idx_receipt_section_state (assessment_id, section_id, state),
    KEY idx_receipt_student (assessment_id, section_id, student_identifier, attempt_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API transaction outline:
-- * If scanner_submission_id already exists with the same payload hash, return
--   the prior success without changing aggregates. Reject a different hash.
-- * Validate teacher access, assessment status, section attachment, question
--   count, score, and item numbers before inserting a receipt.
-- * On replacement, lock the old active receipt, reverse its score/item counts,
--   mark it reversed, then insert and apply the new contribution in one transaction.
-- * Update score_frequencies and item_correct_counts in that same transaction.

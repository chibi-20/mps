-- Draft standalone Scanner schema for the pictured 50-question A-D sheet.
-- Target: MySQL 8 / MariaDB. This is a proposal; it is not applied to a database.
-- MPS assessment_id, section_id, and teacher_id are external IDs. No MPS tables
-- are copied or modified by this standalone schema.

CREATE DATABASE IF NOT EXISTS scan_to_mps
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE scan_to_mps;

-- Versioned OMR layout. layout_json uses normalized coordinates in the rectified
-- page and describes fiducials/timing marks, 50 x 4 answer-bubble centers, and a
-- 5-column x 10-row ID grid. Handwritten header boxes are metadata only; they are
-- not used to select or authorize an MPS assessment.
CREATE TABLE IF NOT EXISTS scan_sheet_templates (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    template_code   VARCHAR(64) NOT NULL,
    version         SMALLINT UNSIGNED NOT NULL,
    display_name    VARCHAR(160) NOT NULL,
    question_count  SMALLINT UNSIGNED NOT NULL,
    answer_choices  JSON NOT NULL,
    student_id_digits TINYINT UNSIGNED NOT NULL,
    layout_json     JSON NOT NULL,
    active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sheet_template_version (template_code, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A published key is an immutable version tied to an MPS shared assessment.
-- The MPS assessment remains the source of truth for title, subject, and term.
CREATE TABLE IF NOT EXISTS scan_answer_keys (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    mps_assessment_id   BIGINT UNSIGNED NOT NULL,
    version             SMALLINT UNSIGNED NOT NULL,
    question_count      SMALLINT UNSIGNED NOT NULL,
    status              ENUM('draft', 'published', 'retired') NOT NULL DEFAULT 'draft',
    created_by_mps_user_id BIGINT UNSIGNED DEFAULT NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_at        DATETIME DEFAULT NULL,
    UNIQUE KEY uq_answer_key_assessment_version (mps_assessment_id, version),
    KEY idx_answer_key_lookup (mps_assessment_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scan_answer_key_items (
    answer_key_id   BIGINT UNSIGNED NOT NULL,
    item_no         SMALLINT UNSIGNED NOT NULL,
    correct_choice  CHAR(1) NOT NULL,
    PRIMARY KEY (answer_key_id, item_no),
    CONSTRAINT chk_key_choice CHECK (correct_choice IN ('A', 'B', 'C', 'D')),
    CONSTRAINT fk_key_item_key FOREIGN KEY (answer_key_id)
        REFERENCES scan_answer_keys(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per scan/attempt. The app supplies MPS IDs after teacher selection;
-- the student ID stays text so leading zeroes are preserved. Do not store the
-- source image by default; keep only its optional hash for diagnostics.
CREATE TABLE IF NOT EXISTS scan_submissions (
    id                      CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    sheet_template_id       BIGINT UNSIGNED NOT NULL,
    answer_key_id           BIGINT UNSIGNED NOT NULL,
    mps_assessment_id       BIGINT UNSIGNED NOT NULL,
    mps_section_id          BIGINT UNSIGNED NOT NULL,
    mps_teacher_user_id     BIGINT UNSIGNED NOT NULL,
    student_identifier      VARCHAR(32) DEFAULT NULL,
    student_name_snapshot   VARCHAR(160) DEFAULT NULL,
    attempt_no              SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    total_items             SMALLINT UNSIGNED NOT NULL,
    score                   SMALLINT UNSIGNED DEFAULT NULL,
    review_status           ENUM('needs_review', 'ready', 'committed', 'superseded')
                            NOT NULL DEFAULT 'needs_review',
    delivery_status         ENUM('not_sent', 'pending', 'sent', 'failed')
                            NOT NULL DEFAULT 'not_sent',
    replaces_submission_id  CHAR(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    source_image_sha256     CHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    delivery_error          TEXT DEFAULT NULL,
    captured_at             DATETIME NOT NULL,
    reviewed_at             DATETIME DEFAULT NULL,
    committed_at            DATETIME DEFAULT NULL,
    created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_submission_attempt
        (mps_assessment_id, mps_section_id, student_identifier, attempt_no),
    KEY idx_submission_queue (delivery_status, review_status, created_at),
    KEY idx_submission_student (mps_assessment_id, mps_section_id, student_identifier),
    CONSTRAINT fk_submission_template FOREIGN KEY (sheet_template_id)
        REFERENCES scan_sheet_templates(id),
    CONSTRAINT fk_submission_key FOREIGN KEY (answer_key_id)
        REFERENCES scan_answer_keys(id),
    CONSTRAINT fk_submission_replaces FOREIGN KEY (replaces_submission_id)
        REFERENCES scan_submissions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preserve the OMR reading and the teacher's accepted correction separately.
-- selected_marks_json examples: [], ["B"], or ["A", "C"].
CREATE TABLE IF NOT EXISTS scan_submission_answers (
    submission_id       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    item_no             SMALLINT UNSIGNED NOT NULL,
    selected_marks_json JSON NOT NULL,
    detected_status     ENUM('marked', 'blank', 'multiple', 'uncertain') NOT NULL,
    confidence          DECIMAL(5,4) DEFAULT NULL,
    accepted_choice     CHAR(1) DEFAULT NULL,
    is_correct          TINYINT(1) DEFAULT NULL,
    was_corrected       TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (submission_id, item_no),
    CONSTRAINT chk_answer_confidence CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),
    CONSTRAINT chk_accepted_choice CHECK (accepted_choice IS NULL OR accepted_choice IN ('A', 'B', 'C', 'D')),
    CONSTRAINT fk_submission_answer_parent FOREIGN KEY (submission_id)
        REFERENCES scan_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The photographed sheet has five ID columns, each with bubbles 0 through 9.
-- Keep each digit's raw read so ambiguous digits can be corrected in review.
CREATE TABLE IF NOT EXISTS scan_submission_id_digits (
    submission_id       CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    position_no         TINYINT UNSIGNED NOT NULL,
    selected_digits_json JSON NOT NULL,
    detected_status     ENUM('marked', 'blank', 'multiple', 'uncertain') NOT NULL,
    confidence          DECIMAL(5,4) DEFAULT NULL,
    accepted_digit      TINYINT UNSIGNED DEFAULT NULL,
    was_corrected       TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (submission_id, position_no),
    CONSTRAINT chk_student_id_position CHECK (position_no BETWEEN 1 AND 5),
    CONSTRAINT chk_student_id_digit CHECK (accepted_digit IS NULL OR accepted_digit BETWEEN 0 AND 9),
    CONSTRAINT chk_id_confidence CHECK (confidence IS NULL OR confidence BETWEEN 0 AND 1),
    CONSTRAINT fk_submission_id_parent FOREIGN KEY (submission_id)
        REFERENCES scan_submissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Integration contract notes:
-- 1. Scanner sends a committed submission id as the idempotency key.
-- 2. The MPS API validates the teacher's assessment/section access and item count.
-- 3. In one MPS transaction, record the submission id as processed and update
--    score_frequencies plus item_correct_counts. A repeated ID returns the
--    original result without applying counts twice.
-- 4. For post-commit corrections, the MPS side must retain an active per-student
--    contribution ledger (or equivalent) so the old contribution can be reversed
--    before the replacement is applied. Do not blindly add aggregate counts.
-- 5. The printed Name/Term/Grade & Section/Teacher/Score boxes are not trusted as
--    identifiers. Use the selected MPS assessment and section plus bubbled ID;
--    treat the handwritten name only as a review aid.

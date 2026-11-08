-- ============================================================
-- MPS & Item Analysis System - Jacobo Z. Gonzales MNH School
-- Initial schema for a new, empty database. This file no longer drops tables.
-- Prefer `php bin/migrate.php migrate` for new installations and upgrades.
-- Run setup.php after schema creation to seed demo/reference data.
-- ============================================================

-- ------------------------------------------------------------
-- USERS
-- ------------------------------------------------------------
CREATE TABLE users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    last_name     VARCHAR(100) NOT NULL,
    first_name    VARCHAR(100) NOT NULL,
    middle_name   VARCHAR(100) DEFAULT NULL,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','teacher') NOT NULL DEFAULT 'teacher',
    department    VARCHAR(100) DEFAULT NULL,
    is_active             TINYINT(1)  NOT NULL DEFAULT 0,
    must_change_password  TINYINT(1)  NOT NULL DEFAULT 0,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_users_role     ON users(role);
CREATE INDEX idx_users_is_active ON users(is_active);

-- ------------------------------------------------------------
-- TEACHER GRADE LEVELS (junction)
-- ------------------------------------------------------------
CREATE TABLE user_grade_levels (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    grade_level TINYINT NOT NULL,
    UNIQUE KEY uq_ugl (user_id, grade_level),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_ugl_user ON user_grade_levels(user_id);

-- ------------------------------------------------------------
-- TEACHER SUBJECTS (junction)
-- ------------------------------------------------------------
CREATE TABLE user_subjects (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    subject_name VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_us (user_id, subject_name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_us_user ON user_subjects(user_id);

-- ------------------------------------------------------------
-- PASSWORD RESET REQUESTS  (teacher self-service → admin inbox)
-- ------------------------------------------------------------
CREATE TABLE password_reset_requests (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    user_id            INT NULL,
    username_submitted VARCHAR(50) NOT NULL,
    status             ENUM('pending','done') NOT NULL DEFAULT 'pending',
    requested_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handled_by         INT NULL,
    handled_at         DATETIME NULL,
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_prr_status  (status),
    INDEX idx_prr_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- SCHOOL YEARS
-- ------------------------------------------------------------
CREATE TABLE school_years (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    name      VARCHAR(20) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TERMS  (DepEd Order No. 009, s. 2026 — 3-term calendar)
-- ------------------------------------------------------------
CREATE TABLE terms (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    school_year_id INT NOT NULL,
    term_no        TINYINT NOT NULL,
    name           VARCHAR(80) NOT NULL,
    start_date     DATE NULL,
    end_date       DATE NULL,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_terms_sy ON terms(school_year_id);

-- ------------------------------------------------------------
-- SUBJECTS
-- ------------------------------------------------------------
CREATE TABLE subjects (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    grade_level TINYINT NOT NULL,
    UNIQUE KEY uq_subj (name, grade_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_subj_grade ON subjects(grade_level);

-- ------------------------------------------------------------
-- SECTIONS
-- ------------------------------------------------------------
CREATE TABLE sections (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    school_year_id INT NOT NULL,
    grade_level    TINYINT NOT NULL,
    name           VARCHAR(120) NOT NULL,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sec_sy    ON sections(school_year_id);
CREATE INDEX idx_sec_grade ON sections(grade_level);

-- ------------------------------------------------------------
-- TEACHER ASSIGNMENTS
-- ------------------------------------------------------------
CREATE TABLE teacher_assignments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id     INT NOT NULL,
    subject_id     INT NOT NULL,
    section_id     INT NOT NULL,
    school_year_id INT NOT NULL,
    UNIQUE KEY uq_ta (teacher_id, subject_id, section_id, school_year_id),
    FOREIGN KEY (teacher_id)     REFERENCES users(id)        ON DELETE CASCADE,
    FOREIGN KEY (subject_id)     REFERENCES subjects(id)     ON DELETE CASCADE,
    FOREIGN KEY (section_id)     REFERENCES sections(id)     ON DELETE CASCADE,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_ta_teacher ON teacher_assignments(teacher_id);
CREATE INDEX idx_ta_subject ON teacher_assignments(subject_id);
CREATE INDEX idx_ta_section ON teacher_assignments(section_id);
CREATE INDEX idx_ta_sy      ON teacher_assignments(school_year_id);

-- ------------------------------------------------------------
-- ASSESSMENTS
-- ------------------------------------------------------------
CREATE TABLE assessments (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id        INT NULL,
    subject_id        INT NOT NULL,
    term_id           INT NOT NULL,
    type              ENUM('summative','periodic','term_exam') NOT NULL,
    title             VARCHAR(200) NOT NULL,
    total_items       INT NOT NULL CHECK (total_items > 0),
    date_given        DATE DEFAULT NULL,
    status            ENUM('draft','submitted','approved','returned') NOT NULL DEFAULT 'draft',
    is_shared         TINYINT(1) NOT NULL DEFAULT 0,
    created_by_admin  INT NULL,
    reviewed_by       INT DEFAULT NULL,
    remarks           TEXT DEFAULT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id)       REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (subject_id)       REFERENCES subjects(id)  ON DELETE CASCADE,
    FOREIGN KEY (term_id)          REFERENCES terms(id)     ON DELETE CASCADE,
    FOREIGN KEY (created_by_admin) REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by)      REFERENCES users(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_asmnt_teacher ON assessments(teacher_id);
CREATE INDEX idx_asmnt_subject ON assessments(subject_id);
CREATE INDEX idx_asmnt_term    ON assessments(term_id);
CREATE INDEX idx_asmnt_status  ON assessments(status);

-- ------------------------------------------------------------
-- SCORE FREQUENCIES  (MPS raw data)
-- ONE row per (assessment, section, score)
-- ------------------------------------------------------------
CREATE TABLE score_frequencies (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    section_id    INT NOT NULL,
    score         INT NOT NULL,
    frequency     INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_sf (assessment_id, section_id, score),
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id)    REFERENCES sections(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_sf_assessment ON score_frequencies(assessment_id);
CREATE INDEX idx_sf_section    ON score_frequencies(section_id);

-- ------------------------------------------------------------
-- ITEM CORRECT COUNTS  (Item Analysis raw data)
-- ONE row per (assessment, section, item_no)
-- ------------------------------------------------------------
CREATE TABLE item_correct_counts (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    section_id    INT NOT NULL,
    item_no       INT NOT NULL,
    correct_count INT NOT NULL DEFAULT 0,
    UNIQUE KEY uq_icc (assessment_id, section_id, item_no),
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id)    REFERENCES sections(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_icc_assessment ON item_correct_counts(assessment_id);
CREATE INDEX idx_icc_section    ON item_correct_counts(section_id);

-- ------------------------------------------------------------
-- ASSESSMENT SECTIONS  (sections chosen for each assessment)
-- Replaces teacher_assignments as the authoritative section
-- list per-assessment; teacher_assignments is now admin-managed
-- defaults that pre-check the checklist at creation time.
-- ------------------------------------------------------------
CREATE TABLE assessment_sections (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    section_id    INT NOT NULL,
    UNIQUE KEY uq_as (assessment_id, section_id),
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id)    REFERENCES sections(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_as_assessment ON assessment_sections(assessment_id);
CREATE INDEX idx_as_section    ON assessment_sections(section_id);

-- ------------------------------------------------------------
-- COMPETENCIES  (MELCs / MATATAG competencies per subject+term)
-- ------------------------------------------------------------
CREATE TABLE competencies (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    subject_id  INT NOT NULL,
    term_id     INT NULL,
    code        VARCHAR(60) DEFAULT NULL,
    description TEXT NOT NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id)    REFERENCES terms(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_comp_subject ON competencies(subject_id);
CREATE INDEX idx_comp_term    ON competencies(term_id);

-- ------------------------------------------------------------
-- ASSESSMENT ITEM → COMPETENCY MAP
-- One item maps to exactly one competency; one competency covers many items.
-- ------------------------------------------------------------
CREATE TABLE assessment_item_competencies (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    item_no       SMALLINT NOT NULL,
    competency_id INT NOT NULL,
    UNIQUE KEY uq_asmt_item (assessment_id, item_no),
    INDEX idx_aic_asmt (assessment_id),
    INDEX idx_aic_comp (competency_id),
    FOREIGN KEY (assessment_id) REFERENCES assessments(id)  ON DELETE CASCADE,
    FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- TEACHER ENCODING STATUS  (per-teacher progress on shared assessments)
-- ------------------------------------------------------------
CREATE TABLE teacher_assessment_encodings (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT NOT NULL,
    teacher_id    INT NOT NULL,
    status        ENUM('draft','submitted','approved','returned') NOT NULL DEFAULT 'draft',
    remarks       TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tae (assessment_id, teacher_id),
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id)    REFERENCES users(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_tae_teacher ON teacher_assessment_encodings(teacher_id);

-- ------------------------------------------------------------
-- GRADE ENROLLMENT
-- Manually-entered headcounts per grade level + term (matches DepEd's
-- Consolidated Proficiency report, which carries an Annual Enrollment
-- and a Monthly Enrollment figure per grade). Used to compute "Number
-- of Learners Who Did Not Take the Test" against actual examinees --
-- the system has no LIS integration, so these are admin-entered.
-- ============================================================
CREATE TABLE IF NOT EXISTS grade_enrollment (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    school_year_id INT NOT NULL,
    grade_level    TINYINT NOT NULL,
    term_id        INT NOT NULL,
    annual_male    INT NOT NULL DEFAULT 0,
    annual_female  INT NOT NULL DEFAULT 0,
    monthly_male   INT NOT NULL DEFAULT 0,
    monthly_female INT NOT NULL DEFAULT 0,
    updated_by     INT NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enrollment (school_year_id, grade_level, term_id),
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id)        REFERENCES terms(id)        ON DELETE CASCADE,
    FOREIGN KEY (updated_by)     REFERENCES users(id)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_enrollment_sy_grade ON grade_enrollment(school_year_id, grade_level);

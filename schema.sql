-- ============================================================
-- MPS & Item Analysis System - Jacobo Z. Gonzales MNH School
-- Schema: DDL only. Run setup.php after import to seed data.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS competencies;
DROP TABLE IF EXISTS item_correct_counts;
DROP TABLE IF EXISTS score_frequencies;
DROP TABLE IF EXISTS assessments;
DROP TABLE IF EXISTS teacher_assignments;
DROP TABLE IF EXISTS sections;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS terms;
DROP TABLE IF EXISTS school_years;
DROP TABLE IF EXISTS user_subjects;
DROP TABLE IF EXISTS user_grade_levels;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

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
    is_active     TINYINT(1)  NOT NULL DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
    id          INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id  INT NOT NULL,
    subject_id  INT NOT NULL,
    term_id     INT NOT NULL,
    type        ENUM('summative','periodic','term_exam') NOT NULL,
    title       VARCHAR(200) NOT NULL,
    total_items INT NOT NULL CHECK (total_items > 0),
    date_given  DATE DEFAULT NULL,
    status      ENUM('draft','submitted','approved','returned') NOT NULL DEFAULT 'draft',
    reviewed_by INT DEFAULT NULL,
    remarks     TEXT DEFAULT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id)  REFERENCES users(id)     ON DELETE CASCADE,
    FOREIGN KEY (subject_id)  REFERENCES subjects(id)  ON DELETE CASCADE,
    FOREIGN KEY (term_id)     REFERENCES terms(id)     ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id)     ON DELETE SET NULL
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
-- COMPETENCIES  (optional tagging of items)
-- ------------------------------------------------------------
CREATE TABLE competencies (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    subject_id  INT NOT NULL,
    code        VARCHAR(60) NOT NULL,
    description TEXT NOT NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_comp_subject ON competencies(subject_id);

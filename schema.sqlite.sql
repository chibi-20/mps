-- SQLite schema for local development. Generated from schema.sql; keep both in sync.


CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    last_name     VARCHAR(100) NOT NULL,
    first_name    VARCHAR(100) NOT NULL,
    middle_name   VARCHAR(100) DEFAULT NULL,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          TEXT NOT NULL DEFAULT 'teacher',
    department    VARCHAR(100) DEFAULT NULL,
    is_active             INTEGER  NOT NULL DEFAULT 0,
    must_change_password  INTEGER  NOT NULL DEFAULT 0,
    created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_role     ON users(role);
CREATE INDEX idx_users_is_active ON users(is_active);

CREATE TABLE user_grade_levels (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INT NOT NULL,
    grade_level INTEGER NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX uq_ugl ON user_grade_levels (user_id, grade_level);

CREATE INDEX idx_ugl_user ON user_grade_levels(user_id);

CREATE TABLE user_subjects (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id      INT NOT NULL,
    subject_name VARCHAR(100) NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX uq_us ON user_subjects (user_id, subject_name);

CREATE INDEX idx_us_user ON user_subjects(user_id);

CREATE TABLE password_reset_requests (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id            INT NULL,
    username_submitted VARCHAR(50) NOT NULL,
    status             TEXT NOT NULL DEFAULT 'pending',
    requested_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    handled_by         INT NULL,
    handled_at         DATETIME NULL,
    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX idx_prr_status ON password_reset_requests (status);
CREATE INDEX idx_prr_user_id ON password_reset_requests (user_id);

CREATE TABLE school_years (
    id        INTEGER PRIMARY KEY AUTOINCREMENT,
    name      VARCHAR(20) NOT NULL UNIQUE,
    is_active INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE terms (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    school_year_id INT NOT NULL,
    term_no        INTEGER NOT NULL,
    name           VARCHAR(80) NOT NULL,
    start_date     DATE NULL,
    end_date       DATE NULL,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE
);

CREATE INDEX idx_terms_sy ON terms(school_year_id);

CREATE TABLE subjects (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        VARCHAR(100) NOT NULL,
    grade_level INTEGER NOT NULL
);

CREATE UNIQUE INDEX uq_subj ON subjects (name, grade_level);

CREATE INDEX idx_subj_grade ON subjects(grade_level);

CREATE TABLE sections (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    school_year_id INT NOT NULL,
    grade_level    INTEGER NOT NULL,
    name           VARCHAR(120) NOT NULL,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE
);

CREATE INDEX idx_sec_sy    ON sections(school_year_id);
CREATE INDEX idx_sec_grade ON sections(grade_level);

CREATE TABLE teacher_assignments (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    teacher_id     INT NOT NULL,
    subject_id     INT NOT NULL,
    section_id     INT NOT NULL,
    school_year_id INT NOT NULL,
    FOREIGN KEY (teacher_id)     REFERENCES users(id)        ON DELETE CASCADE,
    FOREIGN KEY (subject_id)     REFERENCES subjects(id)     ON DELETE CASCADE,
    FOREIGN KEY (section_id)     REFERENCES sections(id)     ON DELETE CASCADE,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX uq_ta ON teacher_assignments (teacher_id, subject_id, section_id, school_year_id);

CREATE INDEX idx_ta_teacher ON teacher_assignments(teacher_id);
CREATE INDEX idx_ta_subject ON teacher_assignments(subject_id);
CREATE INDEX idx_ta_section ON teacher_assignments(section_id);
CREATE INDEX idx_ta_sy      ON teacher_assignments(school_year_id);

CREATE TABLE assessments (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    teacher_id        INT NULL,
    subject_id        INT NOT NULL,
    term_id           INT NOT NULL,
    type              TEXT NOT NULL,
    title             VARCHAR(200) NOT NULL,
    total_items       INT NOT NULL CHECK (total_items > 0),
    date_given        DATE DEFAULT NULL,
    status            TEXT NOT NULL DEFAULT 'draft',
    is_shared         INTEGER NOT NULL DEFAULT 0,
    created_by_admin  INT NULL,
    reviewed_by       INT DEFAULT NULL,
    remarks           TEXT DEFAULT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id)       REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (subject_id)       REFERENCES subjects(id)  ON DELETE CASCADE,
    FOREIGN KEY (term_id)          REFERENCES terms(id)     ON DELETE CASCADE,
    FOREIGN KEY (created_by_admin) REFERENCES users(id)     ON DELETE SET NULL,
    FOREIGN KEY (reviewed_by)      REFERENCES users(id)     ON DELETE SET NULL
);

CREATE INDEX idx_asmnt_teacher ON assessments(teacher_id);
CREATE INDEX idx_asmnt_subject ON assessments(subject_id);
CREATE INDEX idx_asmnt_term    ON assessments(term_id);
CREATE INDEX idx_asmnt_status  ON assessments(status);

CREATE TABLE score_frequencies (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INT NOT NULL,
    section_id    INT NOT NULL,
    score         INT NOT NULL,
    frequency     INT NOT NULL DEFAULT 0,
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id)    REFERENCES sections(id)    ON DELETE CASCADE
);

CREATE UNIQUE INDEX uq_sf ON score_frequencies (assessment_id, section_id, score);

CREATE INDEX idx_sf_assessment ON score_frequencies(assessment_id);
CREATE INDEX idx_sf_section    ON score_frequencies(section_id);

CREATE TABLE item_correct_counts (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INT NOT NULL,
    section_id    INT NOT NULL,
    item_no       INT NOT NULL,
    correct_count INT NOT NULL DEFAULT 0,
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id)    REFERENCES sections(id)    ON DELETE CASCADE
);

CREATE UNIQUE INDEX uq_icc ON item_correct_counts (assessment_id, section_id, item_no);

CREATE INDEX idx_icc_assessment ON item_correct_counts(assessment_id);
CREATE INDEX idx_icc_section    ON item_correct_counts(section_id);

CREATE TABLE assessment_sections (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INT NOT NULL,
    section_id    INT NOT NULL,
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id)    REFERENCES sections(id)    ON DELETE CASCADE
);

CREATE UNIQUE INDEX uq_as ON assessment_sections (assessment_id, section_id);

CREATE INDEX idx_as_assessment ON assessment_sections(assessment_id);
CREATE INDEX idx_as_section    ON assessment_sections(section_id);

CREATE TABLE competencies (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    subject_id  INT NOT NULL,
    term_id     INT NULL,
    code        VARCHAR(60) DEFAULT NULL,
    description TEXT NOT NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id)    REFERENCES terms(id)    ON DELETE SET NULL
);

CREATE INDEX idx_comp_subject ON competencies(subject_id);
CREATE INDEX idx_comp_term    ON competencies(term_id);

CREATE TABLE assessment_item_competencies (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INT NOT NULL,
    item_no       INTEGER NOT NULL,
    competency_id INT NOT NULL,
    FOREIGN KEY (assessment_id) REFERENCES assessments(id)  ON DELETE CASCADE,
    FOREIGN KEY (competency_id) REFERENCES competencies(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX uq_asmt_item ON assessment_item_competencies (assessment_id, item_no);
CREATE INDEX idx_aic_asmt ON assessment_item_competencies (assessment_id);
CREATE INDEX idx_aic_comp ON assessment_item_competencies (competency_id);

CREATE TABLE teacher_assessment_encodings (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    assessment_id INT NOT NULL,
    teacher_id    INT NOT NULL,
    status        TEXT NOT NULL DEFAULT 'draft',
    remarks       TEXT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id)    REFERENCES users(id)       ON DELETE CASCADE
);

CREATE UNIQUE INDEX uq_tae ON teacher_assessment_encodings (assessment_id, teacher_id);

CREATE INDEX idx_tae_teacher ON teacher_assessment_encodings(teacher_id);

CREATE TABLE grade_enrollment (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    school_year_id INT NOT NULL,
    grade_level    INTEGER NOT NULL,
    term_id        INT NOT NULL,
    annual_male    INT NOT NULL DEFAULT 0,
    annual_female  INT NOT NULL DEFAULT 0,
    monthly_male   INT NOT NULL DEFAULT 0,
    monthly_female INT NOT NULL DEFAULT 0,
    updated_by     INT NULL,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id)        REFERENCES terms(id)        ON DELETE CASCADE,
    FOREIGN KEY (updated_by)     REFERENCES users(id)        ON DELETE SET NULL
);

CREATE UNIQUE INDEX uq_enrollment ON grade_enrollment (school_year_id, grade_level, term_id);

CREATE INDEX idx_enrollment_sy_grade ON grade_enrollment(school_year_id, grade_level);

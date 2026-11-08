<?php
declare(strict_types=1);

return [
    'up' => static function (PDO $pdo, MigrationRunner $runner): void {
        $sqlite = $runner->driver() === 'sqlite';
        $pdo->exec($sqlite
            ? "CREATE TABLE students (id INTEGER PRIMARY KEY AUTOINCREMENT, lrn VARCHAR(20) NOT NULL UNIQUE, name VARCHAR(200) NOT NULL, sex TEXT NOT NULL CHECK (sex IN ('Male','Female')))"
            : "CREATE TABLE students (id INT AUTO_INCREMENT PRIMARY KEY, lrn VARCHAR(20) NOT NULL UNIQUE, name VARCHAR(200) NOT NULL, sex ENUM('Male','Female') NOT NULL, INDEX idx_students_name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec($sqlite
            ? 'CREATE TABLE student_enrollments (id INTEGER PRIMARY KEY AUTOINCREMENT, student_id INT NOT NULL, grade_level_id INT NOT NULL, section_id INT NOT NULL, school_year_id INT NOT NULL, UNIQUE (student_id, school_year_id), FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE, FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE RESTRICT, FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE, FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE)'
            : 'CREATE TABLE student_enrollments (id INT AUTO_INCREMENT PRIMARY KEY, student_id INT NOT NULL, grade_level_id INT NOT NULL, section_id INT NOT NULL, school_year_id INT NOT NULL, UNIQUE KEY uq_student_enrollment_year (student_id, school_year_id), INDEX idx_student_enrollment_section (section_id), CONSTRAINT fk_student_enrollment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE, CONSTRAINT fk_student_enrollment_grade FOREIGN KEY (grade_level_id) REFERENCES grade_levels(id) ON DELETE RESTRICT, CONSTRAINT fk_student_enrollment_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE, CONSTRAINT fk_student_enrollment_year FOREIGN KEY (school_year_id) REFERENCES school_years(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    },
];

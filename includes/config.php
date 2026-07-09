<?php
// ============================================================
// Database credentials — NEVER commit real values to VCS
// ============================================================
define('DB_HOST',    'localhost');
define('DB_NAME',    'mps_db');        // change to your DB name
define('DB_USER',    'root');           // change to your DB user
define('DB_PASS',    '');               // change to your DB password
define('DB_CHARSET', 'utf8mb4');

// ============================================================
// School / DepEd header constants
// ============================================================
define('REPUBLIC',       'Republic of the Philippines');
define('DEPED_HEADER',   'Department of Education');
define('REGION',         'Region IV-A (CALABARZON)');
define('DIVISION',       'Schools Division of Biñan City');
define('SCHOOL_NAME',    'Jacobo Z. Gonzales Memorial National High School');
define('SCHOOL_ADDRESS', 'Romana Subd. San Antonio, City of Biñan, Laguna');

// ============================================================
// Mastery / NPWRM threshold (% score to count as "reached mastery")
// Change here; JS mirrors this value via a data attribute.
// ============================================================
define('MASTERY_THRESHOLD', 75);   // percent

// ============================================================
// Mastery bands — used by PHP and mirrored in script.js
// Format: 'KEY' => ['label', min%, max%]
// ============================================================
define('MASTERY_BANDS', [
    'M'   => ['label' => 'Mastered',                       'min' => 96, 'max' => 100],
    'CAM' => ['label' => 'Closely Approximating Mastery',  'min' => 86, 'max' => 95],
    'MTM' => ['label' => 'Moving Towards Mastery',         'min' => 66, 'max' => 85],
    'AVR' => ['label' => 'Average',                        'min' => 35, 'max' => 65],
    'LM'  => ['label' => 'Low Mastery',                    'min' => 15, 'max' => 34],
    'VLM' => ['label' => 'Very Low Mastery',               'min' => 5,  'max' => 14],
    'ANM' => ['label' => 'Absolutely No Mastery',          'min' => 0,  'max' => 4],
]);

// Subjects available for teacher self-registration
define('AVAILABLE_SUBJECTS', [
    'Filipino', 'English', 'Math', 'Science', 'Araling Panlipunan',
    'ESP', 'TLE', 'MAPEH', 'Research', 'SPJ', 'SPS', 'SPA',
]);

define('AVAILABLE_GRADE_LEVELS', [7, 8, 9, 10]);

// ============================================================
// URL base — set once per deployment environment.
// '/'     when the app lives at the domain root (e.g. https://school.edu/)
// '/mps/' when it lives in a subfolder  (e.g. http://localhost/mps/)
// Trailing slash is required.
// ============================================================
define('BASE_URL', '/mps/');

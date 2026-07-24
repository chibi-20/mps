# MPS & Item Analysis System
**Jacobo Z. Gonzales Memorial National High School**
Schools Division of Biñan City · Region IV-A CALABARZON

---

## Tech Stack
- PHP 8.0+, PDO + prepared statements, MySQL / MariaDB
- Sessions, `password_hash` / `password_verify`, CSRF tokens
- Chart.js 4 via CDN
- **PhpSpreadsheet** via Composer (Excel export — server-side)
- No framework; portable to any cPanel shared host

---

## Quick Start (XAMPP / Local)

### 1. Place files
Copy the entire `mps/` folder to `C:\xampp\htdocs\mps\`.

### 2. Install PHP dependencies (PhpSpreadsheet)
Open a terminal in `C:\xampp\htdocs\mps\` and run:
```bash
composer install
```
This creates the `vendor/` directory (~50 MB). If you don't have Composer, download it from https://getcomposer.org/.

### 3. Create the database
1. Start XAMPP (Apache + MySQL).
2. Open **phpMyAdmin** → New database → name it `mps_db` → Collation: `utf8mb4_unicode_ci`.
3. Select `mps_db` → Import → choose `schema.sql` → Go.

### 4. Seed initial data
Browse to:
```
http://localhost/mps/setup.php
```
This inserts:
- Admin account: `admin` / `admin123`
- Teacher account: `jmcanturia` / `teacher123` (MR. JAY MAR V. CANTURIA)
- All 12 subjects × 4 grade levels
- 30 Grade 9 sections, 30 Grade 10 sections
- Teacher assignments (AP Grade 10, 7 sections)
- Sample periodic assessment with realistic MPS and item analysis data

**Delete `setup.php` after running it.** It will refuse to run a second time if the admin already exists.

### 5. Access the system
```
http://localhost/mps/
```

---

## Production Deployment (cPanel Shared Hosting)

### 1. PHP version
Ensure your host runs **PHP 8.0 or later**. Check via cPanel → PHP Selector.

### 2. Database
1. cPanel → MySQL Databases → create a new database and user.
2. Grant ALL PRIVILEGES to the user on that database.
3. phpMyAdmin → import `schema.sql`.
4. Visit `https://yourdomain.com/mps/setup.php` to seed data, then **delete it**.

### 3. Update credentials
Edit `includes/config.php`:
```php
define('DB_HOST', 'localhost');      // usually localhost
define('DB_NAME', 'cpanel_mps_db'); // your DB name (with cPanel prefix)
define('DB_USER', 'cpanel_user');   // your DB username
define('DB_PASS', 'yourpassword');  // your DB password
```

### 4. Upload files
Upload ALL files including the `vendor/` directory (run `composer install` locally first, then upload `vendor/`). The `vendor/` folder must be present for Excel export to work.

### 5. Update base path (if not in root)
If the app is NOT at the root of your domain (e.g., it's at `yourdomain.com/mps/`), the current `Location:` headers (`/mps/...`) already account for this. If your folder name differs, do a project-wide search-and-replace on `/mps/` with your actual path.

---

## Default Accounts

| Role    | Username     | Password     | Notes                             |
|---------|-------------|--------------|-----------------------------------|
| Admin   | `admin`     | `admin123`   | Change immediately after setup!   |
| Teacher | `jmcanturia`| `teacher123` | MR. JAY MAR V. CANTURIA           |

---

## File Structure
```
mps/
├── index.php               Login page
├── register.php            Teacher self-registration
├── teacher-dashboard.php   Teacher: encode MPS + Item Analysis
├── admin-dashboard.php     Admin: analytics, submissions, teacher accounts
├── styles.css              All CSS
├── script.js               Chart.js rendering + live table computation
├── schema.sql              MySQL DDL (tables + indexes)
├── setup.php               One-time seed script (delete after use!)
├── composer.json           PhpSpreadsheet dependency
├── vendor/                 Composer packages (generate with composer install)
├── includes/
│   ├── config.php          DB credentials + school constants + mastery bands
│   ├── db.php              PDO singleton
│   ├── auth.php            Session helpers, CSRF, role guards
│   └── functions.php       PHP compute helpers (MPS, bands, display_name)
└── api/
    ├── logout.php
    ├── create_assessment.php
    ├── save_assessment.php     Saves score_frequencies + item_correct_counts
    ├── get_assessment_data.php Returns full data for one assessment
    ├── get_dashboard_data.php  Returns computed metrics for charts
    ├── get_submissions.php     Admin: all assessments with status
    ├── get_teachers.php        Admin: pending + active teacher list
    ├── approve_assessment.php  Admin: approve or return with remarks
    ├── manage_teacher.php      Admin: approve or deactivate teacher
    └── export_excel.php        Download .xlsx (PhpSpreadsheet)
```

---

## Mastery Bands & NPWRM Threshold
All thresholds live in `includes/config.php` under `MASTERY_BANDS` and `MASTERY_THRESHOLD` (default 75%). Edit there; the server-side PHP and the JS data attributes both read from config.php at render time so they stay in sync.

---

## Security Notes
- All DB queries use PDO prepared statements — no string concatenation.
- CSRF token on every POST form and AJAX request.
- Session ID regenerated on login; session destroyed on logout.
- Role enforced server-side on every protected page and API endpoint.
- `config.php` contains only placeholders — never commit real credentials.
- `setup.php` self-guards (aborts if admin already exists) and should be deleted after first run.

---

## Excel Export
Uses **PhpSpreadsheet** (server-side). Requires the `vendor/` folder.
The exported `.xlsx` has three sheets:
- **MPS** — Frequency of Scores table + CASES/MEAN/MPS/bands/NPWRM summary
- **ITEM ANALYSIS** — % correct per item per section
- **COMPETENCY ANALYSIS** — per-competency mastery rates aggregated across sections

Both sheets include the full DepEd header block.

---

## Recent Updates (R6 — Admin-Created Assessment Blueprint)

### Admin: Learning Competencies Management
- New **Learning Competencies** tab in the admin dashboard.
- CRUD (add / edit / delete) per subject + term.
- **Bulk CSV import** (requires a specific term selected; blocked at both client and server when "All Terms" is active).

### Admin: Create Assessment (Blueprint)
- 4-step modal: choose type → set term/subject/title → map items to competencies → confirm.
- Admin-created assessments are flagged `is_shared = 1` and have no `teacher_id`.
- Item → competency mapping stored in `assessment_item_competencies`.

### Admin: Assessments Tab
- Lists all admin-created assessments with filter bar (SY / Term / Subject / Grade / Type / keyword).
- **Edit** — updates title, term, date, type (locked when data exists), total items (locked when data exists), and competency map.
- **Delete** — shows blast radius (sections, students, teachers) before confirming; requires typing `DELETE` when encoded data exists.

### Teacher: Select Shared Assessment
- Teachers no longer create assessments from scratch for admin-blueprinted tests.
- A **Select Assessment** panel lists available shared assessments; teacher picks one, selects their sections, and begins encoding.
- Per-teacher encoding status tracked in `teacher_assessment_encodings`.

### Analytics: Least-Mastered Learning Competencies
- New chart + drill-down table on the admin dashboard showing competency mastery rates.
- Drill-down table uses `table-layout: fixed` with named `<col>` widths to prevent text overflow.

### ZipGrade Import Fix
- Shared (admin-created) assessments now correctly pass the ownership check in `api/import_zipgrade.php` by verifying `teacher_assessment_encodings` instead of `teacher_id`.

### DB Tables Added
Run the following in phpMyAdmin after pulling these changes:
```sql
-- Per-competency definitions
CREATE TABLE IF NOT EXISTS learning_competencies (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_id  INT UNSIGNED NOT NULL,
    term_id     INT UNSIGNED NOT NULL,
    code        VARCHAR(50)  NOT NULL,
    description TEXT         NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subject_id) REFERENCES subjects(id)  ON DELETE CASCADE,
    FOREIGN KEY (term_id)    REFERENCES terms(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item → competency mapping per assessment
CREATE TABLE IF NOT EXISTS assessment_item_competencies (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_id  INT UNSIGNED NOT NULL,
    item_no        SMALLINT UNSIGNED NOT NULL,
    competency_id  INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_aic (assessment_id, item_no),
    FOREIGN KEY (assessment_id) REFERENCES assessments(id)            ON DELETE CASCADE,
    FOREIGN KEY (competency_id) REFERENCES learning_competencies(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-teacher encoding status for shared assessments
CREATE TABLE IF NOT EXISTS teacher_assessment_encodings (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_id  INT UNSIGNED NOT NULL,
    teacher_id     INT UNSIGNED NOT NULL,
    status         ENUM('pending','submitted','approved','returned') NOT NULL DEFAULT 'pending',
    remarks        TEXT,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tae (assessment_id, teacher_id),
    FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id)    REFERENCES teachers(id)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add is_shared flag to assessments (if not already present)
ALTER TABLE assessments ADD COLUMN IF NOT EXISTS is_shared TINYINT(1) NOT NULL DEFAULT 0;
```

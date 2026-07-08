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
The exported `.xlsx` has two sheets:
- **MPS** — Frequency of Scores table + CASES/MEAN/MPS/bands/NPWRM summary
- **ITEM ANALYSIS** — % correct per item per section

Both sheets include the full DepEd header block.

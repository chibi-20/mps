# MPS & Item Analysis System
**Jacobo Z. Gonzales Memorial National High School**
Schools Division of Biñan City · Region IV-A CALABARZON

---

## Tech Stack
- PHP 8.0+, PDO + prepared statements, MySQL / MariaDB
- Sessions, `password_hash` / `password_verify`, CSRF tokens
- Chart.js 4 via CDN
- **MiniXlsx** — a dependency-free, ZipArchive-based .xlsx writer built into `includes/MiniXlsx.php` (Excel export — server-side, no Composer/vendor needed)
- No framework; portable to any cPanel shared host

---

## Quick Start (SQLite, no XAMPP)

PHP 8.0+ with `pdo_sqlite` enabled is enough for local testing. From the project folder:

```powershell
$env:MPS_DB_DRIVER = 'sqlite'
php bin/migrate.php migrate
php setup.php
php -S 127.0.0.1:8000
```

Open `http://127.0.0.1:8000`. The SQLite database is created at `data/mps.sqlite` automatically and is ignored by Git. `setup.php` seeds the demo accounts and sample data; use it only for a fresh local database. To keep using the same database in later sessions, set `MPS_DB_DRIVER=sqlite` again before starting PHP. `MPS_SQLITE_PATH` can point to a different SQLite file.

For MySQL/XAMPP, use the setup below; MySQL remains the default database driver.

### 1. Place files
Copy the entire `mps/` folder to `C:\xampp\htdocs\mps\`.

### 2. Create and migrate the database
1. Start XAMPP (Apache + MySQL).
2. Open **phpMyAdmin** → New database → name it `mps_db` → Collation: `utf8mb4_unicode_ci`.
3. Set the credentials in `includes/config.php`.
4. From the project folder, run `php bin\migrate.php migrate` (use your XAMPP PHP executable if `php` is not on PATH).

### 3. Seed initial data
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

**Delete `setup.php` after running it.** It will refuse to run a second time if the admin already exists. The demo accounts use sample passwords; change them before using a real deployment.

### 4. Access the system
```
http://localhost/mps/
```

---

## Production Deployment (cPanel Shared Hosting)

### 1. PHP version
Ensure your host runs **PHP 8.0 or later**. Check via cPanel → PHP Selector.

### 2. Upload files
Upload the project files to the hosting account. Excel export uses the dependency-free `includes/MiniXlsx.php` writer — no `vendor/` directory or Composer step needed.

### 3. Database
1. cPanel → MySQL Databases → create a new database and user.
2. Grant the database user the privileges needed to create and alter tables.
3. Edit `includes/config.php`:
```php
define('DB_HOST', 'localhost');      // usually localhost
define('DB_NAME', 'cpanel_mps_db'); // your DB name (with cPanel prefix)
define('DB_USER', 'cpanel_user');   // your DB username
define('DB_PASS', 'yourpassword');  // your DB password
```
4. Run `php bin/migrate.php migrate` from the project folder using SSH/cPanel Terminal.
5. For a new demo installation only, visit `https://yourdomain.com/mps/setup.php` to seed sample data, then **delete it**. Existing installs should not run the demo seeder.

### 4. Update base path (if not in root)
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
├── schema.sql              Initial schema for an empty database
├── bin/migrate.php         CLI migration commands
├── migrations/             Ordered schema migrations
├── setup.php               One-time demo seed script (delete after use!)
├── includes/
│   ├── config.php          DB credentials + school constants + mastery bands
│   ├── db.php              PDO singleton
│   ├── MigrationRunner.php Database migration runner
│   ├── auth.php            Session helpers, CSRF, role guards
│   ├── functions.php       PHP compute helpers (MPS, bands, display_name)
│   └── MiniXlsx.php        Dependency-free .xlsx writer (used by every Excel export)
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
    ├── export_excel.php        Download .xlsx for one assessment (MiniXlsx)
    └── export_subject_report.php Admin: school-wide subject report (MiniXlsx)
```

---

## Database Migrations

Use `php bin/migrate.php status` to see migration state and `php bin/migrate.php migrate` to apply pending migrations. For an existing installation created before the migrator, make a database backup and run `php bin/migrate.php baseline` once; this records the current schema without replaying or changing it. See [`migrations/README.md`](migrations/README.md) for adding migrations and adoption details.

`schema.sql` is only for initializing a new empty database. It no longer drops tables, and the migrator refuses to run the initial migration if it detects an existing MPS schema without migration history.

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
Uses **MiniXlsx** (`includes/MiniXlsx.php`), a small ZipArchive-based writer with no external dependencies — nothing to install, on XAMPP or on a shared host.
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

---

# MPS Scanner

A mobile-first React + TypeScript PWA. Documents are processed locally using PDF.js and Tesseract.js; no document content is uploaded. The app includes image/PDF preview, camera capture, page navigation, editable fields, confidence indicators, unmatched text, JSON export, and a mock submission service.

## Run

Use Node.js 22.12+ (Node 24 recommended).

```sh
npm ci
npm run dev
npm test
npm run build
npm run preview
```

Development runs at http://localhost:5173. Production preview runs at http://localhost:4173. Copy `.env.example` to `.env` to change the file size limit or API settings. Restart Vite after changes.

## Architecture

See [docs/system-flow.md](docs/system-flow.md) for an end-to-end flow chart of assessment setup, teacher result entry, scanner and ZipGrade imports, admin review, and analytics.

- `src/App.tsx`: workflow and review UI; `src/components/`: camera and installation controls.
- `src/services/ocr.ts`: replaceable `OcrService`, PDF text extraction, PDF canvas rendering, local OCR.
- `src/services/parser.ts`: label/value heuristics and key validation.
- `src/services/payload.ts`: immutable payload construction, blocking invalid or duplicate keys.
- `src/services/files.ts`: input validation, download, and temporary export URL cleanup.
- `src/services/api.ts`: typed request (`Payload`), response, service interface and mock implementation.
- `src/types.ts`: shared document, field, text-line and payload types.
- `public/ocr`: bundled English language data, Tesseract worker and WASM resources. These are served from the app's own origin.
- `vite.config.ts`: install manifest, icons and generated service worker.

Document and review state lives only in memory, not local storage. Reloading discards the current document. The service worker caches static app/OCR resources; Tesseract may cache language data in IndexedDB. It does not persist document content. Browser cache eviction can require reconnecting to download assets again.

## Extraction and review

PDF pages with selectable text use PDF.js text and coordinates to group rows and identify column gaps. Pages without text are rendered to a canvas and OCR'd. Image OCR retains word positions and recognition confidence. Colon, spaced dash, pipe and spaced-column patterns are recognized; familiar labels above values use lower confidence. Other lines remain in `unmatchedText` with their page number. Keys normalize to camelCase; duplicate names across pages must be resolved by the reviewer. All nonempty JSON string property names without control characters are accepted, including keys with spaces. JSON is always serialized, never assembled by string concatenation. Export and mock submission use the same payload builder. Invalid keys show a valid JSON validation report instead of an exportable payload.

This is a heuristic printed-English extractor, not a general semantic model. Handwriting, rotated pages, multi-column layouts and arbitrary tables may need manual edits. PDFs containing both selectable text and scanned text on the same page use the selectable text path, so text inside embedded images on those pages may be missed. Large documents can be slow on phones; pages process sequentially, rendering is capped at 2400 pixels per dimension, and file size defaults to 25 MB. Confidence is a review hint, not a calibrated probability.

## PWA and camera testing

Run `npm run build`; the production PWA is emitted into the `scanner/` folder. Serve that folder as `/scanner/` from the MPS webroot (or `/mps/scanner/` when MPS is mounted at `/mps/`). Set `MPS_SCANNER_PWA_URL` if it is hosted at another URL. The Vite base and manifest scope are relative to this folder. Deploy it over HTTPS. The service worker is generated only for production. The initial static cache is about 41 MB because English OCR resources are bundled. Open the production app online and let service worker installation complete before testing airplane mode. Verify DevTools → Application → Manifest / Service Workers / Cache Storage. Reload offline and test both image OCR and PDFs.

Chrome/Edge desktop and Android: open the PWA URL and use **Install app** when the browser makes installation available, or the browser's install menu. The teacher dashboard link opens this PWA with the MPS URL configured; installation is confirmed by the browser. iPhone/iPad: Safari → Share → Add to Home Screen → Add. The app provides these instructions when no native install prompt exists. Manifest icons, standalone display, background and theme colors provide the installed app identity; splash appearance is generated by the operating system.

Camera permission is requested only after **Use your camera**. Test Allow and Deny, switch away/close the dialog, and confirm the camera indicator turns off. Camera requires HTTPS or localhost. An HTTP LAN address is not a secure context; use an HTTPS development tunnel or HTTPS hosting when testing a physical phone. Test desktop, Android and iOS standalone modes on physical devices before release; headless browser checks do not establish device-specific install or camera behavior.

## Scanner API and sign-in

Migrations `0002_scanner_submissions`, `0003_assessment_section_ingestion_mode`, and `0004_scanner_signin_audit` add scanner submissions, answer keys, short-lived teacher sessions, and the scan audit log. Apply them with `php bin/migrate.php migrate` (or baseline an existing install first, as described in `migrations/README.md`). The API preserves the existing ZipGrade CSV import and recomputes score and item aggregates from stored student answers.

### Sign-in and setup

Teachers open **Install MPS Scanner** from their MPS dashboard. This link opens the installable PWA; the browser presents its installation confirmation. The link passes the MPS base URL to the PWA; the PWA never receives a token in its URL. Teachers sign in with their MPS username and password. MPS issues a random eight-hour bearer session, stores only its SHA-256 hash, ties it to the teacher, and revokes it on sign-out. The PWA keeps that session on-device to resume offline use; do not use a shared device account. `POST /api/scanner_login.php` is the PWA sign-in endpoint and `POST /api/scanner_logout.php` revokes the current session.

Save each assessment answer key while logged into MPS using `PUT /api/scanner_answer_key.php` with the CSRF header and JSON `{ "assessment_id": 123, "answer_key": ["A", "B", "C", "D"] }`. The array must have exactly `total_items` choices. `GET` with `?assessment_id=123` reads it back. Use the scanner setup and audit pages to maintain answer keys and review submissions and rejected attempts; teachers see their own activity and administrators see all teachers. The log stores a timestamp, actor, request context, answer payload, and payload SHA-256 digest.

Set `MPS_SCANNER_PWA_URL` to the deployed PWA URL if it is not served at the default `/mps/scanner/`. Configure `MPS_SCANNER_CORS_ORIGINS` as a comma-separated list of exact trusted origins if the scanner runs on a different origin (for example, `http://localhost:5173`). Same-origin deployments need no CORS configuration. Never use `*` with bearer tokens.

### Scanner endpoints

- `POST /api/scanner_login.php` accepts `{ "username": "…", "password": "…" }` and returns a teacher-scoped expiring token, API base URL, and teacher display name. Login attempts are audited and rate limited by IP.
- `GET /api/scanner_assessments.php` with `Authorization: Bearer mps_…` returns assessments and linked sections the teacher can encode, including whether each answer key is complete.
- `POST /api/scanner_submit.php` records one student's answers. The PWA sends `submission_id` (UUID), `assessment_id`, `section_id`, opaque `student_id`, and `answers` as an ordered array of A–D choices or `null` for blank. Example: `{ "submission_id": "550e8400-e29b-41d4-a716-446655440000", "assessment_id": 123, "section_id": 9, "student_id": "00042", "answers": ["A", "C", null] }`.

MPS calculates scores from its stored key; the client cannot submit its own score or correctness flags. Repeating the same UUID is idempotent. A new scan for the same student, assessment, and section replaces the earlier scan and recomputes aggregates. Assessment and encoding locks still apply. Student IDs remain strings to preserve leading zeroes; MPS currently has no roster table, so the API validates assessment-section access but cannot confirm roster membership.

The Scanner PWA reads the four-subject sheet, decodes the combined assessment/student barcode and subject barcodes, and supports manual review. The single header barcode contains `assessmentId_studentId`; each subject box contains `subjectId_studentId_sectionId`. The assessment ID preselects its assessment in the matching subject box. Add and order one or more assessments in each subject box; their item ranges follow that order and must fit within 50 questions. Each assessment needs a complete key. MPS receives each assessment slice separately. If the device is offline or the API is temporarily unavailable, the PWA keeps submissions in an on-device outbox, shows the number pending, and lets the teacher use **Sync now** when connectivity returns. Synced accepted, duplicate, and rejected attempts are recorded in the audit trail. The older generic document workflow remains mock-only.

For local Vite development, allow the Vite origin in `MPS_SCANNER_CORS_ORIGINS` (normally `http://localhost:5173`). `VITE_API_BASE_URL` can prefill the MPS base URL; it is not a secret. Never put scanner tokens in Vite environment variables.
## Validation

`npm test` covers parser formats, confidence, page boundaries, unmatched text, normalization, duplicate and invalid keys, payload integrity, special property names and file validation.

With `npm run preview` running, `node tests/browser.mjs` uses locally installed Edge through Playwright to check upload validation, real image OCR, editing, duplicate detection, JSON, mock submission, mobile layout, offline shell and OCR. Screenshots are saved under `tests/`. `node tests/pdf-browser.mjs` checks multi-page text PDF extraction, scanned PDF OCR, page navigation and camera permission handling. No external OCR provider is involved.

OCR engine/worker: Tesseract.js (Apache-2.0); English trained data is from https://tessdata.projectnaptha.com/4.0.0/eng.traineddata.gz. PDF rendering/extraction uses Mozilla PDF.js (Apache-2.0). Preserve third-party license notices when redistributing bundled resources.

`node tests/camera-browser.mjs` also checks granted camera access using a synthetic browser camera, captured-image preview, and media stream cleanup.

## Answer sheets

Select **50-question answer sheet (A–D)** for the pictured 50-question layout with four outer black square registration marks, question groups 1–19 / 20–38 / 39–47 / 48–50, and five Student ID columns. Images, camera captures, and single-page PDFs are supported. Other sheet layouts are not interchangeable.

Automatic alignment finds the four outer markers. Check the green bubble-center dots against the photo before selecting **Read answer sheet**. Use **Adjust corners** to tap the four outside black squares clockwise from the top-left, or enter pixel coordinates with the keyboard. Keep the sheet upright, flat and fully visible; retake blurry, curved or badly lit photos.

The local OMR service compares ink coverage inside each bubble against surrounding paper brightness. Results include `question1` through `question50`, and `studentIdDigit1` through `studentIdDigit5`. Each field detail includes a `status`: `marked`, `blank`, `multiple`, or `uncertain`. Blank and uncertain values remain empty; multiple marks retain all selected letters. Editing a value changes its status to `reviewed`. Name, term, grade/section, teacher and score are manual-entry fields; handwriting is not transcribed and no grading is performed. Every result needs human review; thresholds have been checked against the supplied blank photo and synthetic marked variants, not a large collection of completed real-world sheets.

`npm test` includes OMR classification and geometry checks. `node tests/omr-browser.mjs` checks the provided reference photo and synthetic marks; set `ANSWER_SHEET_FIXTURE` to a local copy of the reference photo when running elsewhere. The reference photo and generated screenshots are not included in the published app or source repository.

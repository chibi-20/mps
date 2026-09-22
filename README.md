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

## Quick Start (XAMPP / Local)

### 1. Place files
Copy the entire `mps/` folder to `C:\xampp\htdocs\mps\`.

### 2. Create the database
1. Start XAMPP (Apache + MySQL).
2. Open **phpMyAdmin** → New database → name it `mps_db` → Collation: `utf8mb4_unicode_ci`.
3. Select `mps_db` → Import → choose `schema.sql` → Go.

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

**Delete `setup.php` after running it.** It will refuse to run a second time if the admin already exists.

### 4. Access the system
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
Upload all files as-is. Excel export uses the dependency-free `includes/MiniXlsx.php` writer — no `vendor/` directory or Composer step needed.

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
├── includes/
│   ├── config.php          DB credentials + school constants + mastery bands
│   ├── db.php              PDO singleton
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

Deploy `dist/` at the root of an HTTPS origin. The service worker is generated only for production. The initial static cache is about 41 MB because English OCR resources are bundled. Open the production app online and let service worker installation complete before testing airplane mode. Verify DevTools → Application → Manifest / Service Workers / Cache Storage. Reload offline and test both image OCR and PDFs.

Chrome/Edge desktop and Android: use **Install app** when the browser makes installation available, or the browser's install menu. iPhone/iPad: Safari → Share → Add to Home Screen → Add. The app provides these instructions when no native install prompt exists. Manifest icons, standalone display, background and theme colors provide the installed app identity; splash appearance is generated by the operating system.

Camera permission is requested only after **Use your camera**. Test Allow and Deny, switch away/close the dialog, and confirm the camera indicator turns off. Camera requires HTTPS or localhost. An HTTP LAN address is not a secure context; use an HTTPS development tunnel or HTTPS hosting when testing a physical phone. Test desktop, Android and iOS standalone modes on physical devices before release; headless browser checks do not establish device-specific install or camera behavior.

## Future API

`VITE_API_MODE=mock` (default) simulates acceptance without a network call. `VITE_API_MODE=disabled` disables submission. `VITE_API_BASE_URL` is reserved for a future provider and is not used to contact a server. Implement `DocumentApi.submitScannedDocument(payload: Payload): Promise<SubmitResponse>` and replace `documentApi` when ready. Add authentication server-side as appropriate; never put secrets in Vite variables. Keep `buildPayload` as the single payload source so preview, download, and submission remain identical.

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
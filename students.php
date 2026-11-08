<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$sess = require_login();
$isAdmin = ($sess['role'] ?? '') === 'admin';
if (!$isAdmin && ($sess['role'] ?? '') !== 'teacher') {
    http_response_code(403);
    exit('Access denied.');
}
$pdo = get_pdo();
$csrf = csrf_token();
$schoolYears = $pdo->query('SELECT id, name, is_active FROM school_years ORDER BY id DESC')->fetchAll();
$activeYear = null;
foreach ($schoolYears as $year)
    if ((int) $year['is_active'] === 1) {
        $activeYear = (int) $year['id'];
        break;
    }
if ($activeYear === null && $schoolYears)
    $activeYear = (int) $schoolYears[0]['id'];
$selectedYear = validate_int($_GET['school_year_id'] ?? $activeYear, 1) ?? 0;
$grades = $pdo->query('SELECT DISTINCT grade_level AS level FROM sections ORDER BY grade_level')->fetchAll();
$sectionSql = 'SELECT DISTINCT s.id, s.name, s.grade_level AS grade_level_id, s.school_year_id, s.grade_level AS level FROM sections s';
if (!$isAdmin)
    $sectionSql .= ' JOIN teacher_assignments ta ON ta.section_id=s.id AND ta.school_year_id=s.school_year_id WHERE ta.teacher_id=' . (int) $sess['user_id'];
$sections = $pdo->query($sectionSql . ' ORDER BY s.school_year_id DESC, s.grade_level, s.name')->fetchAll();
$hasImportSections = (bool) array_filter($sections, static fn($section) => (int)$section['school_year_id'] === $selectedYear);
$studentFilterGrades = array_values(array_unique(array_map(
    static fn($section) => (int)$section['level'],
    array_filter($sections, static fn($section) => (int)$section['school_year_id'] === $selectedYear)
)));
sort($studentFilterGrades, SORT_NUMERIC);
if (!$isAdmin) {
    $allowedSectionIds = array_values(array_unique(array_map(static fn($section) => (int) $section['id'], array_filter($sections, static fn($section) => (int) $section['school_year_id'] === $selectedYear))));
    if ($allowedSectionIds) {
        $placeholders = implode(',', array_fill(0, count($allowedSectionIds), '?'));
        $stmt = $pdo->prepare("SELECT st.id, st.lrn, st.name, st.sex, e.grade_level_id, e.section_id, e.school_year_id, sec.grade_level AS grade_level, sec.name AS section_name FROM students st JOIN student_enrollments e ON e.student_id=st.id AND e.school_year_id=? AND e.section_id IN ($placeholders) LEFT JOIN sections sec ON sec.id=e.section_id ORDER BY sec.name, st.name, st.lrn");
        $stmt->execute([$selectedYear, ...$allowedSectionIds]);
    } else {
        $stmt = $pdo->prepare('SELECT st.id, st.lrn, st.name, st.sex, e.grade_level_id, e.section_id, e.school_year_id, sec.grade_level AS grade_level, sec.name AS section_name FROM students st JOIN student_enrollments e ON e.student_id=st.id AND e.school_year_id=? LEFT JOIN sections sec ON sec.id=e.section_id WHERE 1=0');
        $stmt->execute([$selectedYear]);
    }
} else {
    $stmt = $pdo->prepare('SELECT st.id, st.lrn, st.name, st.sex, e.grade_level_id, e.section_id, e.school_year_id, sec.grade_level AS grade_level, sec.name AS section_name FROM students st LEFT JOIN student_enrollments e ON e.student_id=st.id AND e.school_year_id=? LEFT JOIN sections sec ON sec.id=e.section_id ORDER BY st.name, st.lrn');
    $stmt->execute([$selectedYear]);
}
$students = $stmt->fetchAll();
?>
<?php
$pageTitle = 'Students';
$activeNavPage = 'students';
$layoutBodyClass = 'admin-dashboard layout-fixed sidebar-expand-lg bg-body-tertiary';
$layoutHeadAssets = '<style>
    #studentFilterBar {
        display: grid !important;
        grid-template-columns: minmax(180px, 1fr) minmax(150px, .8fr) minmax(150px, .8fr) minmax(240px, 1.4fr) auto !important;
        align-items: end !important;
        gap: .75rem !important;
        width: 100%;
    }
    #studentFilterBar .form-group { min-width: 0; }
    #studentFilterBar .form-group select,
    #studentFilterBar .form-group input { width: 100%; max-width: none; }
    #studentFilterBar #clearStudentFilters { white-space: nowrap; }
    @media (max-width: 900px) {
        #studentFilterBar { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
        #studentFilterBar #clearStudentFilters { width: 100%; }
    }
    @media (max-width: 480px) {
        #studentFilterBar { grid-template-columns: minmax(0, 1fr) !important; }
    }
</style>';
$layoutFooterScripts = '<script src="' . asset_url('script.js') . '"></script><script src="' . asset_url('students.js') . '"></script><script src="' . asset_url('table-pagination.js') . '"></script>';
require __DIR__ . '/includes/master_layout_start.php';
?>
<div class="mps-page-heading">
    <div>
        <h1>Students</h1>
    </div>
    <div class="d-flex gap-2"><a class="btn btn-secondary" href="api/students_template.php">Download import
            template</a><button class="btn btn-success" <?= !$hasImportSections ? 'disabled title="No sections are available for this school year."' : '' ?>
            onclick="openStudentImport()">Import
            students</button><?php if ($isAdmin): ?><button class="btn btn-primary" onclick="openStudentForm()">Add
                student</button><?php endif; ?></div>
</div>
<div id="studentImportModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)cancelStudentImport()">
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="studentImportTitle">
        <h3 id="studentImportTitle" style="color:var(--maroon-dark)">Import students</h3>
        <p class="text-muted">First choose the section for these students in the selected school year.</p>
        <?php if ($isAdmin): ?><div class="form-group"><label for="importGrade">Grade</label><select id="importGrade" onchange="filterImportSections()">
            <option value="">Select grade</option><?php foreach ($grades as $grade): ?><option value="<?= (int) $grade['id'] ?>">Grade <?= (int) $grade['level'] ?></option><?php endforeach; ?>
        </select></div><?php endif; ?>
        <div class="form-group"><label for="importSection">Section</label><select id="importSection" onchange="updateImportFileStep()"><option value="">Select section</option></select></div>
        <div id="importFileStep" style="display:none;margin-top:1rem">
            <input id="studentImportFile" type="file" accept=".csv,text/csv" hidden onchange="importStudents(this)">
            <button class="btn btn-outline-secondary" type="button" onclick="document.getElementById('studentImportFile').click()">Choose CSV file</button>
            <span id="studentImportFileName" class="text-muted" style="margin-left:.5rem"></span>
        </div>
        <p id="importError" class="text-muted" role="alert"></p>
        <div class="form-actions"><button id="confirmStudentImport" class="btn btn-primary" onclick="confirmStudentImport()" disabled>Import CSV</button><button class="btn btn-outline-secondary" onclick="cancelStudentImport()">Cancel</button></div>
    </div>
</div>
<div id="studentNotice" class="alert" style="display:none" role="status"></div>
<form id="studentFilterBar" method="get" class="card mb-3 filter-grid student-filter-grid" style="padding:1rem">
        <div class="form-group" style="margin:0"><label for="studentSchoolYear">School year</label><select id="studentSchoolYear" name="school_year_id" onchange="this.form.submit()"><?php foreach ($schoolYears as $year): ?><option value="<?= (int) $year['id'] ?>" <?= (int) $year['id'] === $selectedYear ? 'selected' : '' ?>><?= h($year['name']) ?></option><?php endforeach; ?></select></div>
        <div class="form-group" style="margin:0"><label for="studentGradeFilter">Grade</label><select id="studentGradeFilter"><option value="">All grades</option><?php foreach ($studentFilterGrades as $grade): ?><option value="<?= (int)$grade ?>">Grade <?= (int)$grade ?></option><?php endforeach; ?></select></div>
        <div class="form-group" style="margin:0"><label for="studentSectionFilter">Section</label><select id="studentSectionFilter"><option value="">All sections</option></select></div>
        <div class="form-group" style="margin:0"><label for="studentSearchFilter">Search by name or LRN</label><input id="studentSearchFilter" type="search" placeholder="Enter a name or LRN" autocomplete="off"></div>
        <div class="form-group" style="margin:0;align-self:end"><button class="btn btn-outline-secondary" id="clearStudentFilters" type="button">Clear filters</button></div>
</form>
<div class="card">
    <div class="table-scroll table-responsive">
        <table id="studentsTable" class="data-table table align-middle">
            <thead>
                <tr>
                    <th>LRN</th>
                    <th>NAME</th>
                    <th>SEX</th>
                    <th>GRADE</th>
                    <th>SECTION</th>
                    <th>SCHOOL YEAR</th><?php if ($isAdmin): ?>
                        <th>ACTIONS</th><?php endif; ?>
                </tr>
            </thead>
            <tbody id="studentsBody">
                <?php foreach ($students as $student): ?>
                    <tr data-student-id="<?= (int) $student['id'] ?>" data-lrn="<?= h($student['lrn']) ?>"
                        data-name="<?= h($student['name']) ?>" data-sex="<?= h($student['sex']) ?>"
                        data-grade="<?= (int) ($student['grade_level_id'] ?? 0) ?>"
                        data-grade-level="<?= (int) ($student['grade_level'] ?? 0) ?>"
                        data-section="<?= (int) ($student['section_id'] ?? 0) ?>">
                        <td><?= h($student['lrn']) ?></td>
                        <td><?= h($student['name']) ?></td>
                        <td><?= h($student['sex']) ?></td>
                        <td><?= $student['grade_level'] === null ? '<span class="text-muted">Unassigned</span>' : 'Grade ' . (int) $student['grade_level'] ?>
                        </td>
                        <td><?= h((string) ($student['section_name'] ?? '—')) ?></td>
                        <td><?= h((string) ($schoolYears[array_search($selectedYear, array_column($schoolYears, 'id'))]['name'] ?? '—')) ?>
                        </td><?php if ($isAdmin): ?>
                            <td><button class="btn btn-sm btn-outline-secondary" onclick="editStudent(this)">Edit</button></td>
                        <?php endif; ?>
                    </tr><?php endforeach; ?>
                <?php if (!$students): ?>
                    <tr>
                        <td colspan="<?= $isAdmin ? 7 : 6 ?>" class="text-center text-muted">No student records in your
                            assigned sections for this school year. Import a CSV file to add students.</td>
                    </tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <p id="studentsEmptyState" class="text-center text-muted py-4 mb-0" style="display:none">No students match these filters.</p>
</div>

<?php if ($isAdmin): ?>
    <div id="studentModal" class="modal-overlay" style="display:none" onclick="if(event.target===this)closeStudentForm()">
        <div class="modal-box">
            <h3 id="studentModalTitle" style="color:var(--maroon-dark);margin-bottom:1rem">Add Student</h3>
            <input type="hidden" id="studentId">
            <div class="form-group"><label for="studentLrn">LRN</label><input id="studentLrn" maxlength="20"
                    inputmode="numeric" required></div>
            <div class="form-group"><label for="studentName">Name</label><input id="studentName" maxlength="200" required>
            </div>
            <div class="form-group"><label for="studentSex">Sex</label><select id="studentSex">
                    <option value="">Select sex</option>
                    <option>Female</option>
                    <option>Male</option>
                </select></div>
            <div class="form-group"><label for="studentGrade">Grade</label><select id="studentGrade"
                    onchange="filterStudentSections()">
                    <option value="">Unassigned</option><?php foreach ($grades as $grade): ?>
                        <option value="<?= (int) $grade['id'] ?>">Grade <?= (int) $grade['level'] ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="form-group"><label for="studentSection">Section</label><select id="studentSection">
                    <option value="">Unassigned</option>
                </select></div>
            <p class="text-muted">Assignment applies to school year
                <?= h((string) ($schoolYears[array_search($selectedYear, array_column($schoolYears, 'id'))]['name'] ?? '—')) ?>.
            </p>
            <p id="studentFormError" class="text-muted" role="alert"></p>
            <div class="form-actions"><button class="btn btn-primary" onclick="saveStudent()">Save</button><button
                    class="btn btn-outline-secondary" onclick="closeStudentForm()">Cancel</button></div>
        </div>
    </div><?php endif; ?>
<script>window.studentPageData = <?= json_encode(['csrf' => $csrf, 'schoolYearId' => $selectedYear, 'sections' => $sections, 'isAdmin' => $isAdmin], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
<?php require __DIR__ . '/includes/master_layout_end.php'; ?>

<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$sess = require_login();
$pdo = get_pdo();
$profileStmt = $pdo->prepare(
    'SELECT id, first_name, middle_name, last_name, username, role, department, created_at
     FROM users WHERE id = ? LIMIT 1'
);
$profileStmt->execute([(int)$sess['user_id']]);
$profile = $profileStmt->fetch();
if (!$profile) {
    http_response_code(404);
    exit('Profile not found.');
}
$profileName = trim(implode(' ', array_filter([
    $profile['first_name'], $profile['middle_name'], $profile['last_name'],
], static fn($part) => trim((string)$part) !== '')));
$sectionAssignments = [];
if (($profile['role'] ?? '') === 'teacher') {
    $assignmentsStmt = $pdo->prepare(
        "SELECT sy.name AS school_year, sub.name AS subject_name,
                sec.name AS section_name, sec.grade_level
         FROM teacher_assignments ta
         JOIN sections sec ON sec.id = ta.section_id
         JOIN school_years sy ON sy.id = ta.school_year_id
         JOIN subjects sub ON sub.id = ta.subject_id
         WHERE ta.teacher_id = ?
         ORDER BY sy.id DESC, sub.grade_level, sub.name, sec.grade_level, sec.name"
    );
    $assignmentsStmt->execute([(int)$sess['user_id']]);
    $sectionAssignments = $assignmentsStmt->fetchAll();
}
$activeNavPage = 'profile';
$pageTitle = 'My Profile';
$layoutBodyClass = 'profile-page layout-fixed sidebar-expand-lg bg-body-tertiary';
require __DIR__ . '/includes/master_layout_start.php';
?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <h1 class="h3 mb-1">My Profile</h1>
        <p class="text-muted mb-0">Your account information</p>
    </div>
</div>
<div class="d-flex justify-content-between gap-2 mb-3">
<section class="card w-50">
    <div class="card-header"><h2 class="card-title">Account details</h2></div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= h($profileName) ?></dd>
            <dt class="col-sm-4">Username</dt><dd class="col-sm-8"><?= h((string)$profile['username']) ?></dd>
            <dt class="col-sm-4">Role</dt><dd class="col-sm-8 text-capitalize"><?= h((string)$profile['role']) ?></dd>
            <?php if (!empty($profile['department'])): ?><dt class="col-sm-4">Department</dt><dd class="col-sm-8"><?= h((string)$profile['department']) ?></dd><?php endif; ?>
            <dt class="col-sm-4">Account created</dt><dd class="col-sm-8"><?= h((string)$profile['created_at']) ?></dd>
        </dl>
    </div>
</section>
<?php if (($profile['role'] ?? '') === 'teacher'): ?>
<section class="card w-50">
    <div class="card-header"><h2 class="card-title">Assigned Sections</h2></div>
    <div class="card-body">
        <?php if (!$sectionAssignments): ?>
            <p class="text-muted mb-0">No sections are currently assigned to your account.</p>
        <?php else: ?>
            <?php $assignmentsByYear = []; foreach ($sectionAssignments as $assignment) $assignmentsByYear[$assignment['school_year']][] = $assignment; ?>
            <?php foreach ($assignmentsByYear as $schoolYear => $assignments): ?>
                <h3 class="h6<?= $schoolYear === array_key_first($assignmentsByYear) ? ' mt-0' : ' mt-4' ?>"><?= h((string)$schoolYear) ?></h3>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Grade</th><th>Section</th><th>Subject</th></tr></thead><tbody>
                    <?php foreach ($assignments as $assignment): ?><tr><td>Grade <?= (int)$assignment['grade_level'] ?></td><td><?= h((string)$assignment['section_name']) ?></td><td><?= h((string)$assignment['subject_name']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>
</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/master_layout_end.php'; ?>

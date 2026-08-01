<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Already logged in → redirect
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . ($_SESSION['role'] === 'admin' ? 'admin-dashboard.php' : 'teacher-dashboard.php'));
    exit;
}

$pdo = get_pdo();

// Pre-load sections grouped by grade for the registration form (active school year)
$regSy   = $pdo->query("SELECT id FROM school_years WHERE is_active=1 LIMIT 1")->fetch();
$regSyId = $regSy ? (int)$regSy['id'] : 0;
$regSectionsByGrade = [];
if ($regSyId) {
    $regSecStmt = $pdo->prepare(
        "SELECT id, name, grade_level FROM sections WHERE school_year_id = ? ORDER BY grade_level, name"
    );
    $regSecStmt->execute([$regSyId]);
    foreach ($regSecStmt->fetchAll() as $s) {
        $regSectionsByGrade[(int)$s['grade_level']][] = $s;
    }
}

$success = false;
$errors  = [];
$form    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form = [
        'last_name'    => trim($_POST['last_name']    ?? ''),
        'first_name'   => trim($_POST['first_name']   ?? ''),
        'middle_name'  => trim($_POST['middle_name']  ?? ''),
        'username'     => trim($_POST['username']     ?? ''),
        'password'     => $_POST['password']          ?? '',
        'confirm_pass' => $_POST['confirm_pass']      ?? '',
        'grade_levels' => array_map('intval', (array)($_POST['grade_levels'] ?? [])),
        'subjects'     => (array)($_POST['subjects']  ?? []),
        'section_ids'  => array_values(array_unique(array_filter(
            array_map('intval', (array)($_POST['section_ids'] ?? [])),
            fn($id) => $id > 0
        ))),
    ];

    // Validation
    if ($form['last_name'] === '')  $errors[] = 'Last name is required.';
    if ($form['first_name'] === '') $errors[] = 'First name is required.';
    if ($form['username'] === '')   $errors[] = 'Username is required.';
    if (mb_strlen($form['password']) < 8)
        $errors[] = 'Password must be at least 8 characters.';
    if ($form['password'] !== $form['confirm_pass'])
        $errors[] = 'Passwords do not match.';

    // array_values so the array is 0-indexed (needed for splat in prepared statements)
    $validGrades = array_values(array_filter(
        $form['grade_levels'], fn($g) => in_array($g, AVAILABLE_GRADE_LEVELS, true)
    ));
    if (empty($validGrades)) $errors[] = 'Select at least one grade level.';

    $validSubjects = array_values(array_filter(
        $form['subjects'], fn($s) => in_array($s, AVAILABLE_SUBJECTS, true)
    ));
    if (empty($validSubjects)) $errors[] = 'Select at least one subject.';

    if (empty($errors)) {
        // Check username uniqueness
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$form['username']]);
        if ($stmt->fetch()) {
            $errors[] = 'That username is already taken.';
        } else {
            $hash = password_hash($form['password'], PASSWORD_DEFAULT);

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "INSERT INTO users
                        (last_name, first_name, middle_name, username, password_hash, role, is_active)
                     VALUES (?,?,?,?,?,'teacher',0)"
                )->execute([
                    $form['last_name'],
                    $form['first_name'],
                    $form['middle_name'] !== '' ? $form['middle_name'] : null,
                    $form['username'],
                    $hash,
                ]);
                $uid = (int)$pdo->lastInsertId();

                $glStmt = $pdo->prepare("INSERT INTO user_grade_levels (user_id, grade_level) VALUES (?,?)");
                foreach ($validGrades as $gl) {
                    $glStmt->execute([$uid, $gl]);
                }

                $usStmt = $pdo->prepare("INSERT INTO user_subjects (user_id, subject_name) VALUES (?,?)");
                foreach ($validSubjects as $subj) {
                    $usStmt->execute([$uid, $subj]);
                }

                // Populate teacher_assignments so the encoding flow can find this teacher's sections.
                // For each selected section: pair it with every grade-matching subject the teacher registered.
                // Sections from wrong grades are silently ignored (server validates school_year_id + grade).
                if (!empty($form['section_ids']) && $regSyId && !empty($validSubjects) && !empty($validGrades)) {
                    $snPh = implode(',', array_fill(0, count($validSubjects), '?'));
                    $grPh = implode(',', array_fill(0, count($validGrades),   '?'));

                    // Resolve subject IDs (only for the teacher's registered subject names × valid grades)
                    $subjIdStmt = $pdo->prepare(
                        "SELECT id, grade_level FROM subjects WHERE name IN ({$snPh}) AND grade_level IN ({$grPh})"
                    );
                    $subjIdStmt->execute([...$validSubjects, ...$validGrades]);
                    $subsByGrade = [];
                    foreach ($subjIdStmt->fetchAll() as $sr) {
                        $subsByGrade[(int)$sr['grade_level']][] = (int)$sr['id'];
                    }

                    // Validate section IDs: must belong to the active SY and a grade the teacher registered for
                    $secPh    = implode(',', array_fill(0, count($form['section_ids']), '?'));
                    $secChkSt = $pdo->prepare(
                        "SELECT id, grade_level FROM sections
                         WHERE id IN ({$secPh}) AND school_year_id = ? AND grade_level IN ({$grPh})"
                    );
                    $secChkSt->execute([...$form['section_ids'], $regSyId, ...$validGrades]);

                    $taStmt = $pdo->prepare(
                        "INSERT IGNORE INTO teacher_assignments (teacher_id, subject_id, section_id, school_year_id)
                         VALUES (?,?,?,?)"
                    );
                    foreach ($secChkSt->fetchAll() as $sec) {
                        foreach ($subsByGrade[(int)$sec['grade_level']] ?? [] as $subjId) {
                            $taStmt->execute([$uid, $subjId, (int)$sec['id'], $regSyId]);
                        }
                    }
                }

                $pdo->commit();
                $success = true;
                $form    = [];
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Registration failed. Please try again.';
            }
        }
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>MPS System – Teacher Registration</title>
<link rel="icon" href="<?= BASE_URL ?>assets/logo.png">
<link rel="stylesheet" href="<?= BASE_URL ?>styles.css">
</head>
<body class="auth-page">
<div class="login-split">

    <div class="login-brand">
        <img src="<?= BASE_URL ?>assets/logo.png" alt="Jacobo Z. Gonzales Memorial National High School" class="school-logo">
        <p class="login-brand-name">Jacobo Z. Gonzales Memorial National High School</p>
        <p class="login-brand-division">Schools Division of Biñan City &middot; Region IV-A CALABARZON</p>
        <hr class="login-brand-divider">
        <p class="login-brand-tagline">MPS &amp; Item Analysis System</p>
    </div>

    <div class="login-form-panel">
        <div class="login-card login-card--wide">
            <h2 class="login-card-title">Teacher Self-Registration</h2>
            <p class="login-card-subtitle">Create your teacher account — pending admin approval after signup.</p>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <strong>Registration submitted!</strong> Your account is <em>pending admin approval</em>.
        You will be able to log in once an administrator activates your account.
    </div>
    <p class="auth-footer"><a href="<?= BASE_URL ?>index.php">&larr; Back to Login</a></p>

    <?php else: ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <ul class="error-list">
            <?php foreach ($errors as $e): ?>
            <li><?= h($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">

        <fieldset class="form-fieldset">
            <legend>Personal Information</legend>
            <div class="form-row">
                <div class="form-group">
                    <label for="last_name">Last Name <span class="req">*</span></label>
                    <input type="text" id="last_name" name="last_name"
                           value="<?= h($form['last_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="first_name">First Name <span class="req">*</span></label>
                    <input type="text" id="first_name" name="first_name"
                           value="<?= h($form['first_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="middle_name">Middle Name</label>
                    <input type="text" id="middle_name" name="middle_name"
                           value="<?= h($form['middle_name'] ?? '') ?>">
                </div>
            </div>
        </fieldset>

        <fieldset class="form-fieldset">
            <legend>Account Credentials</legend>
            <div class="form-row">
                <div class="form-group">
                    <label for="username">Username <span class="req">*</span></label>
                    <input type="text" id="username" name="username"
                           value="<?= h($form['username'] ?? '') ?>" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Password <span class="req">*</span> <small>(min. 8 chars)</small></label>
                    <input type="password" id="password" name="password" required autocomplete="new-password">
                </div>
                <div class="form-group">
                    <label for="confirm_pass">Confirm Password <span class="req">*</span></label>
                    <input type="password" id="confirm_pass" name="confirm_pass" required autocomplete="new-password">
                </div>
            </div>
        </fieldset>

        <fieldset class="form-fieldset">
            <legend>Teaching Assignment</legend>
            <div class="form-row">
                <div class="form-group">
                    <label>Grade Level(s) Taught <span class="req">*</span></label>
                    <div class="checklist">
                        <?php foreach (AVAILABLE_GRADE_LEVELS as $gl): ?>
                        <label class="check-item">
                            <input type="checkbox" name="grade_levels[]" value="<?= $gl ?>"
                                   <?= in_array($gl, $form['grade_levels'] ?? [], true) ? 'checked' : '' ?>>
                            Grade <?= $gl ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="form-group">
                    <label>Subject(s) Taught <span class="req">*</span></label>
                    <div class="checklist checklist--grid">
                        <?php foreach (AVAILABLE_SUBJECTS as $subj): ?>
                        <label class="check-item">
                            <input type="checkbox" name="subjects[]" value="<?= h($subj) ?>"
                                   <?= in_array($subj, $form['subjects'] ?? [], true) ? 'checked' : '' ?>>
                            <?= h($subj) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </fieldset>

        <fieldset class="form-fieldset">
            <legend>Sections You Handle</legend>
            <p style="font-size:.85rem;color:var(--c-muted);margin:0 0 .75rem">
                Select the specific sections you teach. Only sections for your selected grade level(s) are shown.
                You can leave this blank — the admin can assign sections after approving your account.
            </p>
            <?php if (empty($regSectionsByGrade)): ?>
            <p style="font-size:.875rem;color:var(--c-muted)">No sections are configured in the system yet. The admin will assign your sections after approval.</p>
            <?php else: ?>
            <?php foreach ($regSectionsByGrade as $gl => $secs): ?>
            <div class="grade-secs-group" data-grade="<?= $gl ?>" style="display:none;margin-bottom:.75rem">
                <p style="font-weight:600;font-size:.85rem;color:var(--c-muted);margin:0 0 .35rem">Grade <?= $gl ?> sections:</p>
                <div class="checklist checklist--grid">
                    <?php foreach ($secs as $sec): ?>
                    <label class="check-item">
                        <input type="checkbox" name="section_ids[]" value="<?= $sec['id'] ?>"
                               <?= in_array($sec['id'], $form['section_ids'] ?? [], true) ? 'checked' : '' ?>>
                        <?= h($sec['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </fieldset>

        <button type="submit" class="btn btn-primary btn-full">Submit Registration</button>
    </form>
    <p class="auth-footer"><a href="<?= BASE_URL ?>index.php">&larr; Back to Login</a></p>
    <?php endif; ?>
        </div>
    </div>

</div>
<script>
function syncSectionGroups() {
    document.querySelectorAll('input[name="grade_levels[]"]').forEach(function(cb) {
        var grp = document.querySelector('.grade-secs-group[data-grade="' + cb.value + '"]');
        if (!grp) return;
        grp.style.display = cb.checked ? '' : 'none';
        if (!cb.checked) {
            grp.querySelectorAll('input[type="checkbox"]').forEach(function(s) { s.checked = false; });
        }
    });
}
document.querySelectorAll('input[name="grade_levels[]"]').forEach(function(cb) {
    cb.addEventListener('change', syncSectionGroups);
});
syncSectionGroups(); // restore state on validation-error page reload
</script>
</body>
</html>

<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Already logged in → redirect
if (!empty($_SESSION['user_id'])) {
    header('Location: /mps/' . ($_SESSION['role'] === 'admin' ? 'admin-dashboard.php' : 'teacher-dashboard.php'));
    exit;
}

$success = false;
$errors  = [];
$form    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $form = [
        'last_name'     => trim($_POST['last_name']     ?? ''),
        'first_name'    => trim($_POST['first_name']    ?? ''),
        'middle_name'   => trim($_POST['middle_name']   ?? ''),
        'username'      => trim($_POST['username']      ?? ''),
        'password'      => $_POST['password']           ?? '',
        'confirm_pass'  => $_POST['confirm_pass']       ?? '',
        'grade_levels'  => array_map('intval', (array)($_POST['grade_levels'] ?? [])),
        'subjects'      => (array)($_POST['subjects'] ?? []),
    ];

    // Validation
    if ($form['last_name'] === '')    $errors[] = 'Last name is required.';
    if ($form['first_name'] === '')   $errors[] = 'First name is required.';
    if ($form['username'] === '')     $errors[] = 'Username is required.';
    if (mb_strlen($form['password']) < 8)
        $errors[] = 'Password must be at least 8 characters.';
    if ($form['password'] !== $form['confirm_pass'])
        $errors[] = 'Passwords do not match.';

    // Sanitize grade levels (must be from allowed list)
    $validGrades = array_filter($form['grade_levels'], fn($g) => in_array($g, AVAILABLE_GRADE_LEVELS, true));
    if (empty($validGrades)) $errors[] = 'Select at least one grade level.';

    // Sanitize subjects (must be from allowed list)
    $validSubjects = array_filter($form['subjects'], fn($s) => in_array($s, AVAILABLE_SUBJECTS, true));
    if (empty($validSubjects)) $errors[] = 'Select at least one subject.';

    if (empty($errors)) {
        $pdo  = get_pdo();

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
<link rel="stylesheet" href="/mps/styles.css">
</head>
<body class="auth-page">
<div class="auth-card auth-card--wide">
    <div class="auth-logo">
        <div class="school-badge">JZG</div>
        <div>
            <p class="auth-school-name">Jacobo Z. Gonzales Memorial National High School</p>
            <p class="auth-division">Schools Division of Biñan City · Region IV-A CALABARZON</p>
        </div>
    </div>
    <h2 class="auth-title">Teacher Self-Registration</h2>

    <?php if ($success): ?>
    <div class="alert alert-success">
        <strong>Registration submitted!</strong> Your account is <em>pending admin approval</em>.
        You will be able to log in once an administrator activates your account.
    </div>
    <p class="auth-footer"><a href="/mps/index.php">&larr; Back to Login</a></p>

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

        <button type="submit" class="btn btn-primary btn-full">Submit Registration</button>
    </form>
    <p class="auth-footer"><a href="/mps/index.php">&larr; Back to Login</a></p>
    <?php endif; ?>
</div>
</body>
</html>

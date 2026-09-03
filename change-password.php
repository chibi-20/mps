<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$sess = require_login('teacher'); // guard also redirects here if must_change_password=1
$uid  = (int)$sess['user_id'];

$pdo = get_pdo();

// Re-read from DB (session flag could be stale if admin reset twice without a re-login)
$row = $pdo->prepare("SELECT must_change_password FROM users WHERE id = ?");
$row->execute([$uid]);
$user = $row->fetch();

if (!$user || !(int)$user['must_change_password']) {
    header('Location: ' . BASE_URL . 'teacher-dashboard.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $newPwd  = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (mb_strlen($newPwd) < 8) $errors[] = 'New password must be at least 8 characters.';
    if ($newPwd !== $confirm)   $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $hash = password_hash($newPwd, PASSWORD_DEFAULT);
        $pdo->prepare(
            "UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?"
        )->execute([$hash, $uid]);
        $_SESSION['must_change_password'] = 0;
        header('Location: ' . BASE_URL . 'teacher-dashboard.php');
        exit;
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set New Password – MPS System</title>
<link rel="icon" href="<?= BASE_URL ?>assets/logo.png">
<link rel="stylesheet" href="<?= asset_url('styles.css') ?>">
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
        <div class="login-card">
            <h2 class="login-card-title">Set New Password</h2>
            <p class="login-card-subtitle">
                Your password was reset by an administrator. You must choose a new password before continuing.
            </p>

            <div class="alert" style="background:rgba(199,154,58,.12);border:1.5px solid var(--gold);border-radius:.5rem;padding:.75rem 1rem;margin-bottom:1rem;font-size:.875rem">
                Signed in as <strong><?= h($sess['display']) ?></strong>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul class="error-list">
                    <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="post" action="" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <div class="form-group">
                    <label for="new_password">New Password <span class="req">*</span> <small>(min. 8 chars)</small></label>
                    <input type="password" id="new_password" name="new_password" required autocomplete="new-password" autofocus>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password <span class="req">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Save New Password</button>
            </form>
        </div>
    </div>

</div>
</body>
</html>

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
<?php
$layoutVariant = 'auth';
$pageTitle = 'Set New Password';
$layoutBodyClass = 'auth-page login-page';
require __DIR__ . '/includes/master_layout_start.php';
?>
<div class="login-box mps-auth-box">
    <div class="card card-outline card-primary">
        <div class="card-header text-center">
            <a class="h1 text-decoration-none" href="<?= BASE_URL ?>index.php"><img src="<?= BASE_URL ?>assets/logo.png" alt="" class="auth-logo"> MPS</a>
            <p class="text-secondary mb-0">Jacobo Z. Gonzales Memorial National High School</p>
        </div>
        <div class="card-body">
            <h2 class="login-card-title">Set New Password</h2>
            <p class="login-card-subtitle">
                Your password was reset by an administrator. You must choose a new password before continuing.
            </p>

            <div class="alert alert-info">
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
                    <input class="form-control" type="password" id="new_password" name="new_password" required autocomplete="new-password" autofocus>
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password <span class="req">*</span></label>
                    <input class="form-control" type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary w-100">Save New Password</button>
            </form>
        </div>
    </div>

</div>
<?php require __DIR__ . '/includes/master_layout_end.php'; ?>

<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Already logged in → redirect
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . ($_SESSION['role'] === 'admin' ? 'admin-dashboard.php' : 'teacher-dashboard.php'));
    exit;
}

$submitted = false;
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = mb_substr(trim($_POST['username'] ?? ''), 0, 50);

    if ($username === '') {
        $errors[] = 'Please enter your username.';
    } else {
        $pdo = get_pdo();

        // Look up active teacher by username — intentionally don't reveal whether found
        $stmt = $pdo->prepare(
            "SELECT id FROM users WHERE username = ? AND role = 'teacher' AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user) {
            // Rate limit: one pending request per user_id
            $chk = $pdo->prepare(
                "SELECT id FROM password_reset_requests WHERE user_id = ? AND status = 'pending' LIMIT 1"
            );
            $chk->execute([$user['id']]);
            if (!$chk->fetch()) {
                $pdo->prepare(
                    "INSERT INTO password_reset_requests (user_id, username_submitted, status, requested_at)
                     VALUES (?, ?, 'pending', CURRENT_TIMESTAMP)"
                )->execute([$user['id'], $username]);
            }
        } else {
            // Username not found or not a teacher: still rate-limit by username string to avoid flooding admin
            $chk2 = $pdo->prepare(
                "SELECT id FROM password_reset_requests WHERE username_submitted = ? AND status = 'pending' LIMIT 1"
            );
            $chk2->execute([$username]);
            if (!$chk2->fetch()) {
                // Insert with user_id=NULL so admin sees the attempted username but can't act on it
                $pdo->prepare(
                    "INSERT INTO password_reset_requests (user_id, username_submitted, status, requested_at)
                     VALUES (NULL, ?, 'pending', CURRENT_TIMESTAMP)"
                )->execute([$username]);
            }
        }

        $submitted = true;
    }
}

$csrf = csrf_token();
?>
<?php
$layoutVariant = 'auth';
$pageTitle = 'Forgot Password';
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
            <h2 class="login-card-title">Forgot Password?</h2>

            <?php if ($submitted): ?>
            <div class="alert alert-success">
                <strong>Request submitted.</strong><br>
                If that account exists, your request has been noted and an administrator will be notified.
                Please contact your school administrator directly to receive your temporary password.
            </div>
            <p class="auth-footer"><a href="<?= BASE_URL ?>index.php">&larr; Back to Login</a></p>

            <?php else: ?>
            <p class="login-card-subtitle">
                Enter your username below. An administrator will be notified and will provide you with a temporary password.
            </p>

            <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <?php foreach ($errors as $e): ?><?= h($e) ?><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input class="form-control" type="text" id="username" name="username"
                           value="<?= h($_POST['username'] ?? '') ?>"
                           required autofocus autocomplete="username">
                </div>
                <button type="submit" class="btn btn-primary w-100">Request Reset</button>
            </form>
            <p class="auth-footer"><a href="<?= BASE_URL ?>index.php">&larr; Back to Login</a></p>
            <?php endif; ?>
        </div>
    </div>

</div>
<?php require __DIR__ . '/includes/master_layout_end.php'; ?>

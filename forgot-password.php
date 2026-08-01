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
                     VALUES (?, ?, 'pending', NOW())"
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
                     VALUES (NULL, ?, 'pending', NOW())"
                )->execute([$username]);
            }
        }

        $submitted = true;
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password – MPS System</title>
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
        <div class="login-card">
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
                    <input type="text" id="username" name="username"
                           value="<?= h($_POST['username'] ?? '') ?>"
                           required autofocus autocomplete="username">
                </div>
                <button type="submit" class="btn btn-primary btn-full">Request Reset</button>
            </form>
            <p class="auth-footer"><a href="<?= BASE_URL ?>index.php">&larr; Back to Login</a></p>
            <?php endif; ?>
        </div>
    </div>

</div>
</body>
</html>

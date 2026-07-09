<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Already logged in → redirect
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . ($_SESSION['role'] === 'admin' ? 'admin-dashboard.php' : 'teacher-dashboard.php'));
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        $pdo  = get_pdo();
        $stmt = $pdo->prepare(
            'SELECT id, last_name, first_name, middle_name, password_hash, role, is_active
             FROM users WHERE username = ?'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            $error = 'Invalid username or password.';
        } elseif (!(int)$user['is_active']) {
            $error = 'Your account is pending admin approval. Please wait for activation.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role']    = $user['role'];
            $_SESSION['display'] = display_name($user);
            $_SESSION['last_name'] = $user['last_name'];

            $redirect = $user['role'] === 'admin' ? 'admin-dashboard.php' : 'teacher-dashboard.php';
            header('Location: ' . BASE_URL . $redirect);
            exit;
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
<title>MPS System – Login</title>
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
            <h2 class="login-card-title">Welcome Back</h2>
            <p class="login-card-subtitle">Sign in to your account</p>

            <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post" action="" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-icon-wrap">
                        <span class="input-icon">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <input type="text" id="username" name="username"
                               value="<?= h($_POST['username'] ?? '') ?>"
                               required autofocus autocomplete="username">
                    </div>
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-icon-wrap">
                        <span class="input-icon">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <input type="password" id="password" name="password"
                               required autocomplete="current-password">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary btn-full">Sign In</button>
            </form>
            <p class="auth-footer">
                New teacher? <a href="<?= BASE_URL ?>register.php">Create an account</a>
            </p>
        </div>
    </div>

</div>
</body>
</html>

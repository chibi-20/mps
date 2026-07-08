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
<link rel="stylesheet" href="<?= BASE_URL ?>styles.css">
</head>
<body class="auth-page">
<div class="auth-card">
    <div class="auth-logo">
        <div class="school-badge">JZG</div>
        <div>
            <p class="auth-school-name">Jacobo Z. Gonzales Memorial National High School</p>
            <p class="auth-division">Schools Division of Biñan City · Region IV-A CALABARZON</p>
        </div>
    </div>
    <h2 class="auth-title">MPS &amp; Item Analysis System</h2>

    <?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="post" action="" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username"
                   value="<?= h($_POST['username'] ?? '') ?>"
                   required autofocus autocomplete="username">
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password"
                   required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary btn-full">Sign In</button>
    </form>
    <p class="auth-footer">
        New teacher? <a href="<?= BASE_URL ?>register.php">Create an account</a>
    </p>
</div>
</body>
</html>

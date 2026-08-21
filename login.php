<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

if (current_user()) {
    header('Location: ' . BASE_PATH . '/index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (attempt_login($username, $password)) {
        header('Location: ' . BASE_PATH . '/index.php');
        exit;
    }
    $error = 'Incorrect username or password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= e(SITE_TITLE) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
<style>
  body { display:flex; align-items:center; justify-content:center; min-height:100vh; }
  .login-card { width: 360px; }
  .login-card h1 { text-align:center; }
  .login-sub { text-align:center; color: var(--slate); font-size:.85rem; margin-bottom: 24px; }
</style>
</head>
<body>
  <div class="card login-card">
    <h1><?= e(SITE_TITLE) ?></h1>
    <div class="login-sub"><?= e(SCHOOL_NAME) ?> admissions follow-up log</div>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="field">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">Sign in</button>
    </form>
  </div>
</body>
</html>

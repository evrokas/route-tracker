<?php
/**
 * login.php — Route Tracker v3
 * Session-based login with optional remember-me cookie.
 * Default password after fresh install: changeme (set via Settings → General).
 */

$baseDir = dirname(__DIR__);
require_once $baseDir . '/src/Config.php';
require_once $baseDir . '/src/auth.php';

Auth::startSession();

$error = '';

// ─── Load config ─────────────────────────────────────────────────────────────

try {
    $config       = Config::load($baseDir);
    $passwordHash = $config->getDashboardPasswordHash();
} catch (Exception $e) {
    $passwordHash = '';
    $error        = 'Configuration error: ' . $e->getMessage();
}

// ─── Already logged in → redirect to dashboard ───────────────────────────────

if (!empty($_SESSION['rt_authed'])) {
    header('Location: dashboard.php');
    exit;
}

// ─── Handle logout with remember-me cleanup ───────────────────────────────────

if (isset($_GET['logout'])) {
    Auth::logout($config ?? null);
    header('Location: login.php');
    exit;
}

// ─── Handle POST ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted  = $_POST['password']    ?? '';
    $rememberMe = !empty($_POST['remember_me']);

    if (empty($passwordHash)) {
        $error = 'No password hash found. Run: php schema.php --init';
    } elseif (password_verify($submitted, $passwordHash)) {
        session_regenerate_id(true);
        $_SESSION['rt_authed'] = true;
        if ($rememberMe && isset($config)) {
            Auth::setRememberMe($config);
        }
        header('Location: dashboard.php');
        exit;
    } else {
        sleep(1);
        $error = 'Incorrect password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Route Tracker — Login</title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Segoe UI', system-ui, sans-serif;
  background: #0f1117;
  color: #e2e8f0;
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
}
.box {
  background: #1a1d27;
  border: 1px solid #2e3350;
  border-radius: 14px;
  padding: 40px 36px;
  width: 100%;
  max-width: 380px;
}
h1 { font-size: 1.3rem; margin-bottom: 6px; }
h1 span { color: #5b7cf6; }
.subtitle { font-size: 12px; color: #64748b; margin-bottom: 28px; }
label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 6px; font-weight: 600; }
input[type=password] {
  width: 100%;
  background: #21253a;
  border: 1px solid #2e3350;
  color: #e2e8f0;
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 14px;
  font-family: inherit;
  margin-bottom: 16px;
  outline: none;
  transition: border-color .15s;
}
input[type=password]:focus { border-color: #5b7cf6; }
.remember-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 20px;
}
.remember-row input[type=checkbox] {
  width: 15px;
  height: 15px;
  accent-color: #5b7cf6;
  cursor: pointer;
  margin: 0;
}
.remember-row label {
  margin: 0;
  font-size: 13px;
  color: #94a3b8;
  font-weight: 400;
  cursor: pointer;
}
button {
  width: 100%;
  background: #5b7cf6;
  color: #fff;
  border: none;
  border-radius: 8px;
  padding: 10px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  transition: background .15s;
}
button:hover { background: #4a6ee0; }
.error {
  background: #450a0a;
  border: 1px solid #7f1d1d;
  color: #fca5a5;
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 13px;
  margin-bottom: 16px;
}
</style>
</head>
<body>
<div class="box">
  <h1>🗺️ Route <span>Tracker</span></h1>
  <p class="subtitle">Sign in to view your traffic dashboard</p>

  <?php if ($error): ?>
  <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="post" action="login.php">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autofocus autocomplete="current-password">
    <div class="remember-row">
      <input type="checkbox" id="remember_me" name="remember_me" value="1">
      <label for="remember_me">Remember me for 30 days</label>
    </div>
    <button type="submit">Sign In</button>
  </form>
</div>
</body>
</html>

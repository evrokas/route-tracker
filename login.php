<?php
/**
 * login.php — Route Tracker v2
 * Simple session-based login. Password is set in config.yaml → dashboard_password.
 * If no password is configured, access is denied entirely.
 */

$baseDir = __DIR__;
require_once $baseDir . '/Config.php';
require_once $baseDir . '/auth.php';

// Use Auth::startSession() — ensures cookie params (httponly, SameSite, etc.)
// are set consistently across login.php, dashboard.php, and api.php
Auth::startSession();

$error = '';

// ─── Load config ─────────────────────────────────────────────────────────────
try {
    $config   = Config::load($baseDir);
    $password = $config->get('dashboard_password', '');
} catch (Exception $e) {
    $password = '';
    $error    = 'Configuration error: ' . $e->getMessage();
}

// ─── Already logged in → redirect to dashboard ───────────────────────────────
if (!empty($_SESSION['rt_authed'])) {
    header('Location: dashboard.php');
    exit;
}

// ─── Handle POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = $_POST['password'] ?? '';

    if (empty($password)) {
        $error = 'No dashboard_password set in config.yaml. Access denied.';
    } elseif (hash_equals($password, $submitted)) {
        session_regenerate_id(true);
        $_SESSION['rt_authed'] = true;
        header('Location: dashboard.php');
        exit;
    } else {
        // Small delay to slow brute-force
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

```
<div class="error"><?= htmlspecialchars($error) ?></div>
```

  <?php endif; ?>

  <form method="post" action="login.php">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autofocus autocomplete="current-password">
    <button type="submit">Sign In</button>
  </form>
</div>
</body>
</html>

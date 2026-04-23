<?php
/**
 * login.php — Route Tracker v3
 * Session-based login with optional remember-me cookie.
 * Supports ErnsAuth SSO when configured.
 * Default password after fresh install: changeme (set via Settings -> General).
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

$ernsauthEnabled = !empty(Config::deploy('ernsauth_url', ''));

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
  max-width: 420px;
}
h1 { font-size: 1.3rem; margin-bottom: 6px; }
h1 span { color: #5b7cf6; }
.subtitle { font-size: 12px; color: #64748b; margin-bottom: 28px; }
label { display: block; font-size: 12px; color: #94a3b8; margin-bottom: 6px; font-weight: 600; }
input[type=password],
input[type=email],
input[type=text] {
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
input:focus { border-color: #5b7cf6; }
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
button, .btn {
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
  text-align: center;
  display: block;
}
button:hover, .btn:hover { background: #4a6ee0; }
button:disabled { opacity: 0.5; cursor: not-allowed; }
.error {
  background: #450a0a;
  border: 1px solid #7f1d1d;
  color: #fca5a5;
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 13px;
  margin-bottom: 16px;
}
.success {
  background: #052e16;
  border: 1px solid #166534;
  color: #86efac;
  border-radius: 8px;
  padding: 10px 14px;
  font-size: 13px;
  margin-bottom: 16px;
}
<?php if ($ernsauthEnabled): ?>
/* Tabs */
.login-tabs {
  display: flex;
  gap: 0;
  margin-bottom: 20px;
  border-bottom: 1px solid #2e3350;
}
.login-tab {
  flex: 1;
  padding: 8px 4px;
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
  cursor: pointer;
  border: none;
  border-bottom: 2px solid transparent;
  background: none;
  font-family: inherit;
  text-align: center;
  white-space: nowrap;
  width: auto;
}
.login-tab:hover { color: #94a3b8; background: none; }
.login-tab.active { color: #5b7cf6; border-bottom-color: #5b7cf6; background: none; }
.login-panel { display: none; }
.login-panel.active { display: block; }
/* SSO */
.sso-number {
  font-size: 48px;
  font-weight: 700;
  text-align: center;
  color: #5b7cf6;
  margin: 20px 0;
  letter-spacing: 8px;
}
.sso-status {
  text-align: center;
  font-size: 13px;
  color: #94a3b8;
  margin-bottom: 16px;
}
.sso-hint {
  text-align: center;
  font-size: 12px;
  color: #64748b;
  margin-bottom: 16px;
}
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }
.pulsing { animation: pulse 1.5s infinite; }
<?php endif; ?>
</style>
</head>
<body>
<div class="box">
  <h1>Route <span>Tracker</span></h1>
  <p class="subtitle">Sign in to view your traffic dashboard</p>

  <?php if ($error): ?>
  <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if ($ernsauthEnabled): ?>
  <!-- Tabbed login -->
  <div class="login-tabs">
    <button class="login-tab active" data-tab="password">Password</button>
    <button class="login-tab" data-tab="sso">ErnsAuth SSO</button>
    <button class="login-tab" data-tab="otp">One-Time Code</button>
    <button class="login-tab" data-tab="reset">Forgot Password</button>
  </div>

  <!-- Password tab -->
  <div class="login-panel active" id="tab-password">
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

  <!-- SSO tab -->
  <div class="login-panel" id="tab-sso">
    <div id="sso-start">
      <p style="font-size:13px;color:#94a3b8;margin-bottom:16px">Log in by approving from your phone. No password needed.</p>
      <button type="button" id="btn-start-sso">Login with ErnsAuth</button>
    </div>
    <div id="sso-pending" style="display:none">
      <p class="sso-hint">Match this number on your ErnsAuth dashboard:</p>
      <div class="sso-number" id="sso-number"></div>
      <div class="sso-status pulsing" id="sso-status">Waiting for approval...</div>
    </div>
    <div id="sso-result" style="display:none"></div>
  </div>

  <!-- OTP tab -->
  <div class="login-panel" id="tab-otp">
    <div id="otp-step1">
      <label for="otp-email">Email Address</label>
      <input type="email" id="otp-email" placeholder="your@email.com">
      <button type="button" id="btn-send-otp">Send Code</button>
    </div>
    <div id="otp-step2" style="display:none">
      <label for="otp-code">Verification Code</label>
      <input type="text" id="otp-code" inputmode="numeric" maxlength="6" placeholder="000000">
      <button type="button" id="btn-verify-otp">Verify</button>
    </div>
    <div id="otp-message"></div>
  </div>

  <!-- Reset tab -->
  <div class="login-panel" id="tab-reset">
    <div id="reset-step1">
      <label for="reset-email">Email Address</label>
      <input type="email" id="reset-email" placeholder="your@email.com">
      <button type="button" id="btn-send-reset">Send Reset Code</button>
    </div>
    <div id="reset-step2" style="display:none">
      <label for="reset-code">Reset Code</label>
      <input type="text" id="reset-code" inputmode="numeric" maxlength="6" placeholder="000000">
      <label for="reset-newpass">New Password</label>
      <input type="password" id="reset-newpass" minlength="8">
      <button type="button" id="btn-verify-reset">Reset Password</button>
    </div>
    <div id="reset-message"></div>
  </div>

  <script>
  (function() {
    // Tab switching
    document.querySelectorAll('.login-tab').forEach(function(tab) {
      tab.addEventListener('click', function() {
        document.querySelectorAll('.login-tab').forEach(function(t) { t.classList.remove('active'); });
        document.querySelectorAll('.login-panel').forEach(function(p) { p.classList.remove('active'); });
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).classList.add('active');
      });
    });

    function apiCall(action, opts) {
      opts = opts || {};
      var method = opts.method || 'GET';
      var body = opts.body || null;
      var url = 'api.php?action=' + encodeURIComponent(action);
      if (opts.params) {
        for (var k in opts.params) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(opts.params[k]);
      }
      var fetchOpts = { method: method, credentials: 'same-origin' };
      if (body && method === 'POST') {
        fetchOpts.headers = { 'Content-Type': 'application/json' };
        fetchOpts.body = JSON.stringify(body);
      }
      return fetch(url, fetchOpts).then(function(r) { return r.json(); });
    }

    function h(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    // SSO Flow
    var ssoPollTimer = null;
    document.getElementById('btn-start-sso').addEventListener('click', function() {
      this.disabled = true;
      apiCall('ernsauth_create_challenge', { method: 'POST' }).then(function(data) {
        if (data.error) {
          document.getElementById('sso-result').style.display = 'block';
          document.getElementById('sso-result').innerHTML = '<div class="error">' + h(data.error) + '</div>';
          return;
        }
        document.getElementById('sso-start').style.display = 'none';
        document.getElementById('sso-pending').style.display = 'block';
        document.getElementById('sso-number').textContent = data.challenge_number;

        // Poll
        ssoPollTimer = setInterval(function() {
          apiCall('ernsauth_poll_challenge', { params: { challenge_id: data.challenge_id } }).then(function(poll) {
            if (poll.authenticated) {
              clearInterval(ssoPollTimer);
              document.getElementById('sso-status').textContent = 'Approved! Redirecting...';
              document.getElementById('sso-status').classList.remove('pulsing');
              window.location.href = 'dashboard.php';
            } else if (poll.status === 'rejected') {
              clearInterval(ssoPollTimer);
              document.getElementById('sso-status').textContent = 'Login rejected.';
              document.getElementById('sso-status').classList.remove('pulsing');
            } else if (poll.status === 'expired') {
              clearInterval(ssoPollTimer);
              document.getElementById('sso-status').textContent = 'Expired. Please try again.';
              document.getElementById('sso-status').classList.remove('pulsing');
            }
          });
        }, 3000);

        // Auto-expire
        setTimeout(function() {
          if (ssoPollTimer) {
            clearInterval(ssoPollTimer);
            document.getElementById('sso-status').textContent = 'Expired. Please try again.';
            document.getElementById('sso-status').classList.remove('pulsing');
          }
        }, (data.expires_at - Math.floor(Date.now()/1000) + 5) * 1000);
      });
    });

    // OTP Flow
    var currentOtpId = null;
    document.getElementById('btn-send-otp').addEventListener('click', function() {
      var email = document.getElementById('otp-email').value.trim();
      if (!email) return;
      this.disabled = true;
      var btn = this;
      apiCall('ernsauth_send_otp', { method: 'POST', body: { email: email } }).then(function(data) {
        btn.disabled = false;
        if (data.error) {
          document.getElementById('otp-message').innerHTML = '<div class="error">' + h(data.error) + '</div>';
          return;
        }
        currentOtpId = data.otp_id;
        document.getElementById('otp-step1').style.display = 'none';
        document.getElementById('otp-step2').style.display = 'block';
        document.getElementById('otp-message').innerHTML = '<div class="success">Code sent to your email.</div>';
      });
    });

    document.getElementById('btn-verify-otp').addEventListener('click', function() {
      var code = document.getElementById('otp-code').value.trim();
      if (!code || !currentOtpId) return;
      this.disabled = true;
      var btn = this;
      apiCall('ernsauth_verify_otp', { method: 'POST', body: { otp_id: currentOtpId, code: code } }).then(function(data) {
        btn.disabled = false;
        if (data.error) {
          document.getElementById('otp-message').innerHTML = '<div class="error">' + h(data.error) + '</div>';
          return;
        }
        if (data.success) {
          window.location.href = 'dashboard.php';
        }
      });
    });

    // Reset Flow
    document.getElementById('btn-send-reset').addEventListener('click', function() {
      var email = document.getElementById('reset-email').value.trim();
      if (!email) return;
      this.disabled = true;
      var btn = this;
      apiCall('ernsauth_request_reset', { method: 'POST', body: { email: email } }).then(function(data) {
        btn.disabled = false;
        document.getElementById('reset-step1').style.display = 'none';
        document.getElementById('reset-step2').style.display = 'block';
        document.getElementById('reset-message').innerHTML = '<div class="success">If the email exists, a reset code has been sent.</div>';
      });
    });

    document.getElementById('btn-verify-reset').addEventListener('click', function() {
      var email = document.getElementById('reset-email').value.trim();
      var code = document.getElementById('reset-code').value.trim();
      var newPass = document.getElementById('reset-newpass').value;
      if (!code || !newPass) return;
      this.disabled = true;
      var btn = this;
      apiCall('ernsauth_verify_reset', { method: 'POST', body: { email: email, code: code, new_password: newPass } }).then(function(data) {
        btn.disabled = false;
        if (data.error) {
          document.getElementById('reset-message').innerHTML = '<div class="error">' + h(data.error) + '</div>';
          return;
        }
        document.getElementById('reset-message').innerHTML = '<div class="success">Password reset successful. You can now sign in.</div>';
      });
    });
  })();
  </script>

  <?php else: ?>
  <!-- Original password-only form -->
  <form method="post" action="login.php">
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autofocus autocomplete="current-password">
    <div class="remember-row">
      <input type="checkbox" id="remember_me" name="remember_me" value="1">
      <label for="remember_me">Remember me for 30 days</label>
    </div>
    <button type="submit">Sign In</button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>

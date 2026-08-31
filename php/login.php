<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

/** @var \mysqli $conn */
if (!isset($auth)) { $auth = new Auth($conn); }

if ($auth->is_logged_in()) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'superadmin') { header("Location: ../admin/dashboard.php"); exit(); }
    elseif ($role === 'admin')  { header("Location: ../client/dashboard.php"); exit(); }
    else {
        $auth->logout();
        header("Location: login.php?error=app_only");
        exit();
    }
}

$message     = '';
$login_error = '';

if (isset($_GET['error']) && $_GET['error'] === 'app_only') {
    $login_error = "Access Denied: Citizen & Responder accounts must authenticate through the mobile app.";
}
if (isset($_GET['signup']) && $_GET['signup'] === 'success') {
    $message = "Registration submitted! Account is pending CDRRMO verification.";
}
if (isset($_GET['reset']) && $_GET['reset'] === 'success') {
    $message = "Password successfully reset! Please log in with your new credentials.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    $username = trim($_POST['login_username'] ?? '');
    $password = $_POST['login_password'] ?? '';

    $result = $auth->login($username, $password);

    if ($result['success']) {
        $role = $result['role'];
        if ($role === 'superadmin') { header("Location: ../admin/dashboard.php"); exit(); }
        elseif ($role === 'admin')  { header("Location: ../client/dashboard.php"); exit(); }
        else {
            $auth->logout();
            $login_error = "Access Denied: Mobile accounts cannot access the administrative command portal.";
        }
    } else {
        $login_error = $result['message'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dasma Alert | Command Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,wght@0,600;0,700;0,800;1,700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/php/login.css">
</head>
<body>

<aside class="hero">
    <div class="hero-mesh"></div>
    <div class="hero-dots"></div>
    <div class="hero-deco-num">01</div>
    <div class="hero-top">
        <div class="brand">
            <img src="../uploads/system/DasmAlert.png" alt="Dasma Alert" class="brand-logo">
            <div class="brand-tag">Command Portal</div>
        </div>
    </div>
    <div class="hero-main">
        <div class="status-pill"><span class="pulse-dot"></span> System Operational</div>
        <h1 class="hero-title">Rapid<br>Response.<br><em>Smarter</em> City.</h1>
        <p class="hero-desc">Dasmariñas City CDRRMO command platform unifying field dispatch, incident triage, and real-time response operations.</p>
        <div class="feature-list">
            <div class="feature-item">
                <div class="feature-icon"><i class='bx bx-radar'></i></div>
                <div class="feature-text"><strong>Live Incident Monitoring</strong><span>Real-time tracking across all 75 barangays.</span></div>
            </div>
            <div class="feature-item">
                <div class="feature-icon"><i class='bx bx-group'></i></div>
                <div class="feature-text"><strong>Responder Coordination</strong><span>Dispatch and coordinate response units.</span></div>
            </div>
        </div>
    </div>
    <footer class="hero-footer"><i class='bx bxs-map-pin'></i> Dasmariñas City CDRRMO &nbsp;·&nbsp; <?= date('Y') ?></footer>
</aside>

<main class="form-side">
    <div class="form-wrapper">
        <div class="portal-label">
            <div class="portal-label-icon"><i class='bx bx-shield-quarter'></i></div>
            <span class="portal-label-text">Admin Command Access</span>
        </div>

        <div class="tab-bar">
            <button class="tab-btn active" id="tab-login" onclick="switchTab('login')">Sign In</button>
            <button class="tab-btn" id="tab-signup" onclick="switchTab('signup')">Register</button>
        </div>

        <!-- ── LOGIN PANEL ── -->
        <div class="form-panel active" id="panel-login">
            <div class="form-header">
                <h2>Welcome back</h2>
                <p>Enter your credentials to access the command portal.</p>
            </div>

            <?php if (!empty($login_error)): ?>
            <div class="alert alert-error">
                <i class='bx bx-error-circle'></i>
                <span><?= htmlspecialchars($login_error, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($message)): ?>
            <div class="alert alert-success">
                <i class='bx bx-check-circle'></i>
                <span><?= htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="field">
                    <label>Username</label>
                    <div class="input-wrap">
                        <i class='bx bxs-user lead'></i>
                        <input type="text" name="login_username" placeholder="Enter username" required autocomplete="username">
                    </div>
                </div>
                <div class="field">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <label style="margin-bottom: 0;">Password</label>
                        <a href="javascript:void(0)" onclick="switchTab('forgot')" style="font-size: 11.5px; color: var(--red); font-weight: 700; text-decoration: none;">Forgot Password?</a>
                    </div>
                    <div class="input-wrap">
                        <i class='bx bxs-lock lead'></i>
                        <input type="password" id="login_password" name="login_password" placeholder="Enter password" required autocomplete="current-password">
                        <i class='bx bx-hide toggle-eye' onclick="togglePwd('login_password', this)"></i>
                    </div>
                </div>
                <button type="submit" name="login_submit" class="btn-submit">
                    <i class='bx bx-log-in'></i> Sign In to Portal
                </button>
            </form>

            <div class="mobile-notice">
                <i class='bx bxs-mobile'></i>
                <span><strong>Citizens & Field Responders:</strong> Please use the <strong>Dasma Alert Mobile App</strong>. This portal is restricted to authorized CDRRMO personnel.</span>
            </div>
        </div>

        <!-- ── SIGNUP PANEL ── -->
        <div class="form-panel" id="panel-signup">
            <div class="form-header">
                <h2>Request Clearance</h2>
                <p>CDRRMO staff registration — restricted to authorized personnel.</p>
            </div>

            <div class="step-indicator">
                <div class="step-dot done active" id="step1-dot">1</div>
                <div class="step-line"></div>
                <div class="step-dot" id="step2-dot">2</div>
                <span style="font-size: 11.5px; color: var(--text-muted); font-weight: 600; margin-left: 8px;">Step <span id="step-label">1</span> of 2</span>
            </div>

            <div id="signup-alert" style="display:none;"></div>

            <div id="signup-step-1">
                <div class="field-row">
                    <div class="field">
                        <label>First Name</label>
                        <div class="input-wrap"><i class='bx bxs-id-card lead'></i><input type="text" id="first_name" placeholder="Juan"></div>
                    </div>
                    <div class="field">
                        <label>Last Name</label>
                        <div class="input-wrap no-icon"><input type="text" id="last_name" placeholder="dela Cruz"></div>
                    </div>
                </div>
                <div class="field">
                    <label>Username</label>
                    <div class="input-wrap"><i class='bx bxs-user lead'></i><input type="text" id="signup_username" placeholder="Choose a username"></div>
                </div>
                <div class="field">
                    <label>Official Email</label>
                    <div class="input-wrap"><i class='bx bx-envelope lead'></i><input type="email" id="signup_email" placeholder="you@cdrrmo.gov.ph"></div>
                </div>
                <div class="field">
                    <label>Mobile Number</label>
                    <div class="input-wrap"><i class='bx bxs-phone lead'></i><input type="text" id="phone_number" placeholder="+63 9XX XXX XXXX"></div>
                </div>
                <div class="field-row">
                    <div class="field">
                        <label>Password</label>
                        <div class="input-wrap">
                            <i class='bx bxs-lock lead'></i>
                            <input type="password" id="signup_password" placeholder="Min. 8 chars">
                            <i class='bx bx-hide toggle-eye' onclick="togglePwd('signup_password', this)"></i>
                        </div>
                    </div>
                    <div class="field">
                        <label>Confirm</label>
                        <div class="input-wrap no-icon"><input type="password" id="signup_password_confirm" placeholder="Re-enter"></div>
                    </div>
                </div>
                <button class="btn-submit" id="btn-otp" onclick="handleOTPRequest()"><i class='bx bx-send'></i> Request Clearance Code</button>
                <p class="switch-link">Already have access? <b onclick="switchTab('login')">Sign In</b></p>
            </div>

            <div id="signup-step-2" style="display:none;">
                <p class="otp-hint">A 6-digit verification code has been dispatched to your email. Enter it below to complete clearance registration.</p>
                <div class="field"><input type="text" id="otp_code" class="otp-input" placeholder="· · · · · ·" maxlength="6"></div>
                <button class="btn-submit" id="btn-verify" onclick="handleOTPVerify()"><i class='bx bx-check-shield'></i> Verify & Submit Account</button>
                <p class="switch-link"><b onclick="location.reload()">Cancel — Start Over</b></p>
            </div>
        </div>

        <!-- ── FORGOT PASSWORD PANEL ── -->
        <div class="form-panel" id="panel-forgot">
            <div class="form-header">
                <h2>Account Recovery</h2>
                <p>Recover access using a 6-digit recovery code.</p>
            </div>

            <div id="forgot-alert" style="display:none;"></div>

            <div id="forgot-step-1">
                <div class="field">
                    <label>Registered Username or Email</label>
                    <div class="input-wrap"><i class='bx bx-envelope lead'></i><input type="text" id="forgot_identity" placeholder="Enter username or email"></div>
                </div>
                <button class="btn-submit" id="btn-forgot-otp" onclick="handleResetOTPRequest()"><i class='bx bx-send'></i> Send Recovery Code</button>
                <p class="switch-link">Remembered password? <b onclick="switchTab('login')">Sign In</b></p>
            </div>

            <div id="forgot-step-2" style="display:none;">
                <p class="otp-hint">Enter the 6-digit code received via email and your new password.</p>
                <div class="field"><input type="text" id="forgot_otp_code" class="otp-input" placeholder="· · · · · ·" maxlength="6"></div>
                <div class="field">
                    <label>New Password</label>
                    <div class="input-wrap">
                        <i class='bx bxs-lock lead'></i>
                        <input type="password" id="forgot_new_password" placeholder="Min. 8 chars">
                        <i class='bx bx-hide toggle-eye' onclick="togglePwd('forgot_new_password', this)"></i>
                    </div>
                </div>
                <div class="field">
                    <label>Confirm Password</label>
                    <div class="input-wrap no-icon"><input type="password" id="forgot_confirm_password" placeholder="Re-enter new password"></div>
                </div>
                <button class="btn-submit" id="btn-reset-pass" onclick="handleResetPassword()"><i class='bx bx-key'></i> Update Password</button>
                <p class="switch-link"><b onclick="switchTab('login')">Cancel & Back to Sign In</b></p>
            </div>
        </div>
    </div>
</main>

<script src="../js/php/login.js?v=<?= filemtime('../js/php/login.js') ?>"></script>
<?php if (!empty($message)): ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        switchTab('login');
    });
</script>
<?php endif; ?>
</body>
</html>
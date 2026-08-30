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
    <style>
        :root {
            --red: #CC2222; --red-deep: #A51B1B; --red-pale: #FEF0F0; --red-soft: rgba(204,34,34,0.08); --red-border: rgba(204,34,34,0.18);
            --bg: #F7F5F2; --surface: #FFFFFF; --surface-2: #F2F0ED; --border: #E4E1DC; --border-dark: #D1CEC9;
            --text-primary: #1A1614; --text-secondary: #6B6560; --text-muted: #9E9890;
            --amber: #D97706; --amber-pale: #FFFBEB; --amber-border: rgba(217,119,6,0.2);
            --green: #15803D; --green-pale: #F0FDF4; --green-border: rgba(21,128,61,0.2);
            --shadow-sm: 0 1px 3px rgba(26,22,20,0.08); --shadow-md: 0 4px 12px rgba(26,22,20,0.10); --shadow-red: 0 6px 20px rgba(204,34,34,0.22);
        }
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text-primary); min-height: 100vh; display: flex; overflow: hidden; }
        .hero { width: 44%; min-height: 100vh; position: relative; display: flex; flex-direction: column; justify-content: space-between; padding: 48px 52px; overflow: hidden; flex-shrink: 0; background: #D03636; }
        .hero-mesh { position: absolute; inset: 0; z-index: 0; background: radial-gradient(ellipse 80% 60% at 20% 50%, rgba(255,255,255,0.08) 0%, transparent 60%), radial-gradient(ellipse 60% 80% at 90% 10%, rgba(0,0,0,0.15) 0%, transparent 60%), radial-gradient(ellipse 50% 50% at 50% 100%, rgba(0,0,0,0.2) 0%, transparent 60%); }
        .hero-dots { position: absolute; inset: 0; z-index: 0; background-image: radial-gradient(circle, rgba(255,255,255,0.12) 1px, transparent 1px); background-size: 28px 28px; }
        .hero-deco-num { position: absolute; bottom: -20px; right: -30px; font-family: 'Fraunces', serif; font-size: 280px; font-weight: 800; color: rgba(255,255,255,0.04); line-height: 1; z-index: 0; user-select: none; }
        .hero-top, .hero-main, .hero-footer { position: relative; z-index: 2; }
        .brand { display: flex; flex-direction: column; gap: 4px; }
        .brand-logo { height: 70px; width: 13%; }
        .brand-tag { font-size: 10px; letter-spacing: 3.5px; text-transform: uppercase; color: rgba(255,255,255,0.5); font-weight: 500; }
        .status-pill { display: inline-flex; align-items: center; gap: 8px; background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.2); border-radius: 40px; padding: 6px 14px 6px 10px; font-size: 11px; font-weight: 600; color: rgba(255,255,255,0.9); margin-bottom: 24px; }
        .pulse-dot { width: 8px; height: 8px; background: #4ADE80; border-radius: 50%; box-shadow: 0 0 0 0 rgba(74,222,128,0.6); animation: livePulse 2s ease-out infinite; }
        @keyframes livePulse { 0% { box-shadow: 0 0 0 0 rgba(74,222,128,0.6); } 70% { box-shadow: 0 0 0 8px rgba(74,222,128,0); } 100% { box-shadow: 0 0 0 0 rgba(74,222,128,0); } }
        .hero-title { font-family: 'Fraunces', serif; font-size: 50px; font-weight: 800; line-height: 1.0; color: #fff; margin-bottom: 18px; }
        .hero-title em { font-style: italic; color: rgba(255,255,255,0.6); }
        .hero-desc { font-size: 14.5px; color: rgba(255,255,255,0.65); line-height: 1.75; max-width: 360px; margin-bottom: 36px; }
        .feature-list { display: flex; flex-direction: column; gap: 10px; }
        .feature-item { display: flex; align-items: center; gap: 14px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 14px 16px; }
        .feature-icon { width: 36px; height: 36px; border-radius: 10px; background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 17px; color: #fff; flex-shrink: 0; }
        .feature-text strong { display: block; font-size: 13px; font-weight: 600; color: #fff; }
        .feature-text span { font-size: 12px; color: rgba(255,255,255,0.55); }
        .hero-footer { display: flex; align-items: center; gap: 8px; font-size: 11.5px; color: rgba(255,255,255,0.4); }
        .form-side { flex: 1; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 40px 48px; background: var(--bg); position: relative; overflow-y: auto; }
        .form-wrapper { width: 100%; max-width: 400px; animation: wrapperIn 0.5s cubic-bezier(0.22,1,0.36,1) both; }
        @keyframes wrapperIn { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        .portal-label { display: flex; align-items: center; gap: 8px; margin-bottom: 28px; }
        .portal-label-icon { width: 32px; height: 32px; background: var(--red-soft); border: 1px solid var(--red-border); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 15px; color: var(--red); }
        .portal-label-text { font-size: 12px; font-weight: 600; color: var(--text-muted); letter-spacing: 1px; text-transform: uppercase; }
        .tab-bar { display: flex; background: var(--surface-2); border: 1px solid var(--border); border-radius: 12px; padding: 4px; margin-bottom: 28px; gap: 4px; }
        .tab-btn { flex: 1; padding: 9px 12px; border: none; border-radius: 9px; background: transparent; color: var(--text-muted); font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13.5px; font-weight: 600; cursor: pointer; transition: all 0.22s ease; }
        .tab-btn.active { background: var(--surface); color: var(--red); box-shadow: var(--shadow-sm); }
        .form-panel { display: none; }
        .form-panel.active { display: block; animation: panelIn 0.3s cubic-bezier(0.22,1,0.36,1); }
        @keyframes panelIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .form-header { margin-bottom: 22px; }
        .form-header h2 { font-family: 'Fraunces', serif; font-size: 27px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.5px; margin-bottom: 5px; }
        .form-header p { font-size: 13.5px; color: var(--text-muted); }
        .alert { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: 10px; font-size: 13px; line-height: 1.5; margin-bottom: 16px; }
        .alert i { font-size: 16px; flex-shrink: 0; margin-top: 1px; }
        .alert-error { background: var(--red-pale); border: 1px solid var(--red-border); color: var(--red-deep); }
        .alert-success { background: var(--green-pale); border: 1px solid var(--green-border); color: var(--green); }
        .field-row { display: flex; gap: 12px; }
        .field { margin-bottom: 14px; position: relative; flex: 1; }
        .field label { display: block; font-size: 11.5px; font-weight: 700; letter-spacing: 0.6px; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 6px; }
        .input-wrap { position: relative; }
        .input-wrap i.lead { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); font-size: 17px; color: var(--text-muted); pointer-events: none; }
        .input-wrap:focus-within i.lead { color: var(--red); }
        .input-wrap input { width: 100%; padding: 11px 14px 11px 40px; background: var(--surface); border: 1.5px solid var(--border); border-radius: 10px; color: var(--text-primary); font-family: 'Plus Jakarta Sans', sans-serif; font-size: 14px; font-weight: 500; outline: none; transition: border-color 0.2s, box-shadow 0.2s; }
        .input-wrap input:focus { border-color: var(--red); box-shadow: 0 0 0 3px rgba(204,34,34,0.10); }
        .input-wrap.no-icon input { padding-left: 14px; }
        .toggle-eye { position: absolute; right: 13px; top: 50%; transform: translateY(-50%); font-size: 18px; color: var(--text-muted); cursor: pointer; }
        .otp-input { width: 100%; padding: 16px; text-align: center; font-family: 'Fraunces', serif; font-size: 28px; letter-spacing: 12px; background: var(--surface); border: 1.5px solid var(--border); border-radius: 10px; color: var(--red); outline: none; margin-bottom: 16px; }
        .otp-hint { font-size: 13px; color: var(--text-muted); line-height: 1.6; margin-bottom: 16px; padding: 12px 14px; background: var(--surface-2); border-radius: 10px; border-left: 3px solid var(--red); }
        .btn-submit { width: 100%; padding: 13px 20px; background: var(--red); border: none; border-radius: 10px; color: #fff; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 14.5px; font-weight: 700; cursor: pointer; margin-top: 6px; box-shadow: var(--shadow-red); display: flex; align-items: center; justify-content: center; gap: 8px; }
        .btn-submit:hover:not(:disabled) { background: var(--red-deep); transform: translateY(-1px); }
        .btn-submit:disabled { opacity: 0.55; cursor: not-allowed; }
        .mobile-notice { display: flex; align-items: flex-start; gap: 10px; background: var(--amber-pale); border: 1px solid var(--amber-border); border-radius: 10px; padding: 12px 14px; font-size: 12.5px; color: var(--amber); margin-top: 16px; }
        .switch-link { text-align: center; font-size: 13px; color: var(--text-muted); margin-top: 18px; }
        .switch-link b { color: var(--red); cursor: pointer; font-weight: 700; }
        .step-indicator { display: flex; align-items: center; gap: 8px; margin-bottom: 18px; }
        .step-dot { width: 26px; height: 26px; border-radius: 50%; border: 2px solid var(--border-dark); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; color: var(--text-muted); background: var(--surface); }
        .step-dot.done { background: var(--red); border-color: var(--red); color: #fff; }
        .step-dot.active { border-color: var(--red); color: var(--red); }
        .step-line { flex: 1; height: 1.5px; background: var(--border); }
        @media (max-width: 900px) { .hero { display: none; } .form-side { padding: 32px 24px; } }
    </style>
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

<script>
    function switchTab(tab) {
        document.getElementById('panel-login').classList.toggle('active', tab === 'login');
        document.getElementById('panel-signup').classList.toggle('active', tab === 'signup');
        document.getElementById('panel-forgot').classList.toggle('active', tab === 'forgot');
        document.getElementById('tab-login').classList.toggle('active', tab === 'login');
        document.getElementById('tab-signup').classList.toggle('active', tab === 'signup');
    }

    function togglePwd(id, icon) {
        const inp = document.getElementById(id);
        inp.type = inp.type === 'password' ? 'text' : 'password';
        icon.classList.toggle('bx-hide');
        icon.classList.toggle('bx-show');
    }

    function showAlert(msg, isError = true, alertId = 'signup-alert') {
        const box = document.getElementById(alertId);
        box.style.display = 'flex';
        box.className = `alert ${isError ? 'alert-error' : 'alert-success'}`;
        box.innerHTML = `<i class='bx ${isError ? 'bx-error-circle' : 'bx-check-circle'}'></i><span>${msg}</span>`;
    }

    /* ── SIGNUP: OTP REQUEST ── */
    function handleOTPRequest() {
        const fname   = document.getElementById('first_name').value.trim();
        const lname   = document.getElementById('last_name').value.trim();
        const uname   = document.getElementById('signup_username').value.trim();
        const email   = document.getElementById('signup_email').value.trim();
        const phone   = document.getElementById('phone_number').value.trim();
        const pass    = document.getElementById('signup_password').value;
        const confirm = document.getElementById('signup_password_confirm').value;
        const btn     = document.getElementById('btn-otp');

        if (!fname || !lname || !uname || !email || !phone || !pass) { showAlert("Please complete all fields.", true, 'signup-alert'); return; }
        if (pass !== confirm) { showAlert("Passwords do not match.", true, 'signup-alert'); return; }
        if (pass.length < 8) { showAlert("Password must be at least 8 characters long.", true, 'signup-alert'); return; }

        btn.disabled = true;
        btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Sending Code…";

        const fd = new FormData();
        fd.append('action', 'request_otp');
        fd.append('first_name', fname);
        fd.append('last_name', lname);
        fd.append('username', uname);
        fd.append('email', email);
        fd.append('phone_number', phone);
        fd.append('password', pass);

        fetch('register_action.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-send'></i> Request Clearance Code";
            if (data.trim() === 'otp_sent') {
                document.getElementById('signup-step-1').style.display = 'none';
                document.getElementById('signup-step-2').style.display = 'block';
                document.getElementById('step2-dot').classList.add('active');
                document.getElementById('step-label').textContent = '2';
                showAlert("Verification code sent! Please check your inbox.", false, 'signup-alert');
            } else {
                showAlert(data.trim(), true, 'signup-alert');
            }
        }).catch(() => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-send'></i> Request Clearance Code";
            showAlert("Network error. Please try again.", true, 'signup-alert');
        });
    }

    /* ── SIGNUP: OTP VERIFY ── */
    function handleOTPVerify() {
        const otp = document.getElementById('otp_code').value.trim();
        const btn = document.getElementById('btn-verify');
        if (!otp || otp.length < 6) { showAlert("Enter the full 6-character code.", true, 'signup-alert'); return; }

        btn.disabled = true;
        btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Authenticating…";

        const fd = new FormData();
        fd.append('action', 'verify_otp');
        fd.append('otp', otp);

        fetch('register_action.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-check-shield'></i> Verify & Submit Account";
            if (data.trim() === 'account_created') {
                showAlert("Account successfully submitted! Redirecting...", false, 'signup-alert');
                setTimeout(() => { location.href = "login.php?signup=success"; }, 1400);
            } else {
                showAlert(data.trim(), true, 'signup-alert');
            }
        }).catch(() => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-check-shield'></i> Verify & Submit Account";
            showAlert("Network error. Please try again.", true, 'signup-alert');
        });
    }

    /* ── FORGOT PASSWORD: REQUEST OTP ── */
    function handleResetOTPRequest() {
        const identity = document.getElementById('forgot_identity').value.trim();
        const btn      = document.getElementById('btn-forgot-otp');
        if (!identity) { showAlert("Please enter your username or email.", true, 'forgot-alert'); return; }

        btn.disabled = true;
        btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Sending Code…";

        const fd = new FormData();
        fd.append('action', 'request_reset_otp');
        fd.append('identity', identity);

        fetch('forgot_password_action.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-send'></i> Send Recovery Code";
            const clean = data.trim();
            if (clean === 'otp_sent') {
                document.getElementById('forgot-step-1').style.display = 'none';
                document.getElementById('forgot-step-2').style.display = 'block';
                showAlert("Recovery code sent! Please check your email inbox.", false, 'forgot-alert');
            } else if (clean === 'user_not_found') {
                showAlert("Account could not be found.", true, 'forgot-alert');
            } else {
                showAlert(clean, true, 'forgot-alert');
            }
        }).catch(() => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-send'></i> Send Recovery Code";
            showAlert("Network error. Please try again.", true, 'forgot-alert');
        });
    }

    /* ── FORGOT PASSWORD: RESET ACTION ── */
    function handleResetPassword() {
        const otp         = document.getElementById('forgot_otp_code').value.trim();
        const newPass     = document.getElementById('forgot_new_password').value;
        const confirmPass = document.getElementById('forgot_confirm_password').value;
        const btn         = document.getElementById('btn-reset-pass');

        if (!otp || otp.length < 6) { showAlert("Enter the 6-digit recovery code.", true, 'forgot-alert'); return; }
        if (!newPass || newPass.length < 8) { showAlert("Password must be at least 8 characters.", true, 'forgot-alert'); return; }
        if (newPass !== confirmPass) { showAlert("Passwords do not match.", true, 'forgot-alert'); return; }

        btn.disabled = true;
        btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Updating Password…";

        const fd = new FormData();
        fd.append('action', 'verify_reset_password');
        fd.append('otp', otp);
        fd.append('new_password', newPass);

        fetch('forgot_password_action.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-key'></i> Update Password";
            const clean = data.trim();
            if (clean === 'password_updated') {
                showAlert("Password updated! Redirecting to sign in...", false, 'forgot-alert');
                setTimeout(() => { location.href = "login.php?reset=success"; }, 1400);
            } else if (clean === 'invalid_otp') {
                showAlert("Invalid or expired recovery code.", true, 'forgot-alert');
            } else {
                showAlert(clean, true, 'forgot-alert');
            }
        }).catch(() => {
            btn.disabled = false;
            btn.innerHTML = "<i class='bx bx-key'></i> Update Password";
            showAlert("Network error. Please try again.", true, 'forgot-alert');
        });
    }

    <?php if ($message): ?>
    switchTab('login');
    <?php endif; ?>
</script>
</body>
</html>
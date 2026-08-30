<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/EmailService.php';

$action = $_POST['action'] ?? '';

// --- STEP 1: REQUEST OTP ---
if ($action === 'request_otp') {
    $first_name   = trim($_POST['first_name'] ?? '');
    $last_name    = trim($_POST['last_name'] ?? '');
    $username     = trim($_POST['username'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $password     = $_POST['password'] ?? '';

    if (empty($first_name) || empty($last_name) || empty($username) || empty($email) || empty($phone_number) || empty($password)) {
        die("Please complete all required fields.");
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die("Invalid email format.");
    }

    if (strlen($password) < 8) {
        die("Password must be at least 8 characters long.");
    }

    // Check if username or email is already registered
    /** @var \mysqli $conn */
    $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
    if ($check_stmt) {
        $check_stmt->bind_param("ss", $username, $email);
        $check_stmt->execute();
        $check_res = $check_stmt->get_result();
        $is_taken  = ($check_res && $check_res->num_rows > 0);
        $check_stmt->close();

        if ($is_taken) {
            die("Username or Email is already registered.");
        }
    }

    // 6-Character Cryptographically Secure Alphanumeric OTP
    $otp = strtoupper(bin2hex(random_bytes(3)));

    $_SESSION['pending_signup'] = [
        'first_name'   => $first_name,
        'last_name'    => $last_name,
        'username'     => $username,
        'email'        => $email,
        'phone_number' => $phone_number,
        'password'     => password_hash($password, PASSWORD_DEFAULT),
        'otp'          => $otp,
        'otp_expires'  => time() + 300, // 5-minute expiry
        'otp_attempts' => 0
    ];

    /** @var \EmailService $emailService */
    $emailService = new \EmailService();
    $sent = $emailService->sendSignupOTP($email, $otp);
    if ($sent === true) {
        echo "otp_sent";
    } else {
        echo "SMTP Error: " . (string)$sent;
    }
    exit();
}

// --- STEP 2: VERIFY OTP & CREATE ACCOUNT ---
if ($action === 'verify_otp') {
    $user_otp = strtoupper(trim($_POST['otp'] ?? ''));

    if (!isset($_SESSION['pending_signup'])) {
        die("Session expired. Please refresh and try again.");
    }

    $pending = &$_SESSION['pending_signup'];

    // Check OTP expiry
    if (time() > $pending['otp_expires']) {
        unset($_SESSION['pending_signup']);
        die("OTP expired. Please request a new code.");
    }

    // Max 5 attempts lockout
    if ($pending['otp_attempts'] >= 5) {
        unset($_SESSION['pending_signup']);
        die("Too many attempts. Please start registration again.");
    }

    $pending['otp_attempts']++;

    // Timing-safe OTP comparison
    if (!hash_equals($pending['otp'], $user_otp)) {
        $remaining = 5 - $pending['otp_attempts'];
        die("Invalid code. {$remaining} attempt(s) remaining.");
    }

    // OTP valid – insert user
    $data   = $pending;
    $role   = 'user';
    $status = 'pending';

    /** @var \mysqli $conn */
    $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, status, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        die("SQL Error: " . $conn->error);
    }

    $stmt->bind_param("sssssss",
        $data['username'],
        $data['email'],
        $data['password'],
        $role,
        $status,
        $data['first_name'],
        $data['last_name']
    );

    if ($stmt->execute()) {
        $new_user_id = $stmt->insert_id;
        $stmt->close();

        $profile_stmt = $conn->prepare("INSERT INTO user_profiles (user_id, phone_number, theme, font_size, sound_alert) VALUES (?, ?, 'light', '16px', 1)");
        if ($profile_stmt) {
            $profile_stmt->bind_param("is", $new_user_id, $data['phone_number']);
            $profile_stmt->execute();
            $profile_stmt->close();
        }

        unset($_SESSION['pending_signup']);
        echo "account_created";
    } else {
        echo "db_error: " . $stmt->error;
        $stmt->close();
    }
    exit();
}

echo "invalid_action";
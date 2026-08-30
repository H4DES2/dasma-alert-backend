<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/EmailService.php';

$action = $_POST['action'] ?? '';

// --- STEP 1: REQUEST RECOVERY OTP ---
if ($action === 'request_reset_otp') {
    $identity = trim($_POST['identity'] ?? '');

    if (empty($identity)) {
        die("Please provide your username or email.");
    }

    /** @var \mysqli $conn */
    $stmt = $conn->prepare("SELECT id, username, email FROM users WHERE (username = ? OR email = ?) LIMIT 1");
    if (!$stmt) {
        die("Database error: " . $conn->error);
    }
    $stmt->bind_param("ss", $identity, $identity);
    $stmt->execute();
    $res  = $stmt->get_result();
    $user = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($user) {
        $otp = (string)random_int(100000, 999999);

        $_SESSION['reset_session'] = [
            'user_id'  => $user['id'],
            'otp'      => $otp,
            'expires'  => time() + 900, // 15-minute expiry
            'attempts' => 0
        ];

        /** @var \EmailService $emailService */
        $emailService = new \EmailService();
        $sent = $emailService->sendPasswordResetOTP($user['email'], $user['username'], $otp);

        if ($sent === true) {
            echo "otp_sent";
        } else {
            echo "Email delivery failed: " . (string)$sent;
        }
    } else {
        usleep(150000); // Timing attack mitigation
        echo "user_not_found";
    }
    exit();
}

// --- STEP 2: VERIFY OTP & UPDATE PASSWORD ---
if ($action === 'verify_reset_password') {
    $otp         = trim($_POST['otp'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';

    if (!isset($_SESSION['reset_session'])) {
        die("invalid_otp");
    }

    $reset = &$_SESSION['reset_session'];

    if (time() > $reset['expires']) {
        unset($_SESSION['reset_session']);
        die("invalid_otp");
    }

    if ($reset['attempts'] >= 5) {
        unset($_SESSION['reset_session']);
        die("invalid_otp");
    }

    $reset['attempts']++;

    if (strlen($newPassword) < 8) {
        die("Password must be at least 8 characters long.");
    }

    if (!hash_equals($reset['otp'], $otp)) {
        die("invalid_otp");
    }

    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    /** @var \mysqli $conn */
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    if (!$stmt) {
        die("db_error");
    }
    $stmt->bind_param("si", $hashedPassword, $reset['user_id']);

    if ($stmt->execute()) {
        $stmt->close();
        unset($_SESSION['reset_session']);
        echo "password_updated";
    } else {
        $stmt->close();
        echo "db_error";
    }
    exit();
}

echo "invalid_action";
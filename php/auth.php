<?php
require_once __DIR__ . '/config.php';

class Auth {
    private $conn;

    public function __construct($database_connection) {
        $this->conn = $database_connection;
        $this->ensureTables();
    }
    // Auto-repair table for persistent server-side rate-limiting
    private function ensureTables() {
        $this->conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(64) NOT NULL UNIQUE,
            attempt_count INT NOT NULL DEFAULT 1,
            first_attempt INT NOT NULL,
            last_attempt INT NOT NULL,
            INDEX(identifier)
        )");
    }

    // PATCH: Minimum 8-char password length enforced
    public function signup($username, $email, $password, $password_confirm, $first_name, $last_name, $mobile) {
        if (empty($username) || empty($email) || empty($password) || empty($first_name) || empty($last_name) || empty($mobile)) {
            return ['success' => false, 'message' => 'All fields are required.'];
        }
        if ($password !== $password_confirm) {
            return ['success' => false, 'message' => 'Passwords do not match.'];
        }
        if (strlen($password) < 8) {
            return ['success' => false, 'message' => 'Password must be at least 8 characters long.'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        // Web registrations are 'user' role, 'pending' status
        $stmt = $this->conn->prepare("INSERT INTO users (username, email, password, role, status, first_name, last_name, phone_number) VALUES (?, ?, ?, 'user', 'pending', ?, ?, ?)");
        $stmt->bind_param("ssssss", $username, $email, $hash, $first_name, $last_name, $mobile);

        if ($stmt->execute()) {
            $stmt->close();
            return ['success' => true, 'message' => 'Account created! Pending approval.'];
        }
        $stmt->close();
        return ['success' => false, 'message' => 'DB Error or Username/Email already taken.'];
    }

    public function login($username, $password) {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $identifier = hash('sha256', $ip . '_' . strtolower(trim($username)));
        $now = time();
        $lockout_window = 900; // 15 minutes
        $max_attempts = 5;

        // 1. Check persistent server-side attempts
        $stmt = $this->conn->prepare("SELECT attempt_count, first_attempt FROM login_attempts WHERE identifier = ?");
        $stmt->bind_param("s", $identifier);
        $stmt->execute();
        $attempt_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($attempt_data) {
            if ($now - $attempt_data['first_attempt'] > $lockout_window) {
                // Window expired: Reset counter
                $del = $this->conn->prepare("DELETE FROM login_attempts WHERE identifier = ?");
                $del->bind_param("s", $identifier);
                $del->execute();
                $del->close();
            } elseif ($attempt_data['attempt_count'] >= $max_attempts) {
                $wait = $lockout_window - ($now - $attempt_data['first_attempt']);
                return ['success' => false, 'message' => "Too many failed attempts. Please try again in " . ceil($wait / 60) . " minute(s)."];
            }
        }

        // 2. Query user
        $stmt = $this->conn->prepare("SELECT id, username, password, role, status FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // 3. Timing Attack Mitigation: Always run bcrypt even if user doesn't exist
        $dummy_hash = '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUUUVWXYZ12345';
        $hash_to_verify = $user ? $user['password'] : $dummy_hash;
        $is_password_valid = password_verify($password, $hash_to_verify);

        if ($user && $is_password_valid) {
            // Clear attempts on success
            $del = $this->conn->prepare("DELETE FROM login_attempts WHERE identifier = ?");
            $del->bind_param("s", $identifier);
            $del->execute();
            $del->close();

            if (isset($user['status']) && $user['status'] === 'Suspended') {
                return ['success' => false, 'message' => 'This account has been suspended. Please contact CDRRMO Admin.'];
            }
            if (isset($user['status']) && $user['status'] === 'pending') {
                return ['success' => false, 'message' => 'Your account is pending approval by the Command Center.'];
            }

            // PATCH: Session Fixation Prevention
            session_regenerate_id(true);

            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];

            return ['success' => true, 'role' => $user['role']];
        }

        // 4. Increment persistent attempts on failure
        $upd = $this->conn->prepare("INSERT INTO login_attempts (identifier, attempt_count, first_attempt, last_attempt) 
                                     VALUES (?, 1, ?, ?) 
                                     ON DUPLICATE KEY UPDATE attempt_count = attempt_count + 1, last_attempt = VALUES(last_attempt)");
        $upd->bind_param("sii", $identifier, $now, $now);
        $upd->execute();
        $upd->close();

        return ['success' => false, 'message' => 'Invalid username or password'];
    }

    public function is_logged_in() { return isset($_SESSION['user_id']); }

    public function isSuperAdmin() { return $this->is_logged_in() && $_SESSION['role'] === 'superadmin'; }
    public function isAdmin()      { return $this->is_logged_in() && $_SESSION['role'] === 'admin'; }
    public function isResponder()  { return isset($_SESSION['role']) && $_SESSION['role'] === 'responder'; }
    public function isUser()       { return $this->is_logged_in() && $_SESSION['role'] === 'user'; }

    // PATCH: Comprehensive logout clearing session cookie from client
    public function logout() {
        if (session_status() === PHP_SESSION_NONE) { session_start(); }
        $_SESSION = [];
        session_unset();
        session_destroy();

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
    }

    // PATCH: Password reset with timing side-channel padding
    public function requestPasswordReset($email) {
        $stmt = $this->conn->prepare("SELECT id, username FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user) {
            $token   = bin2hex(random_bytes(32));
            $hash    = hash('sha256', $token);
            $expires = date('Y-m-d H:i:s', time() + (defined('TOKEN_EXPIRY') ? TOKEN_EXPIRY : 3600));

            $del = $this->conn->prepare("DELETE FROM password_resets WHERE user_id = ?");
            $del->bind_param("i", $user['id']);
            $del->execute();
            $del->close();

            $ins = $this->conn->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, ?)");
            $ins->bind_param("iss", $user['id'], $hash, $expires);
            $ins->execute();
            $ins->close();

            if (file_exists(__DIR__ . '/EmailService.php')) {
            require_once __DIR__ . '/EmailService.php';
            if (class_exists('EmailService')) {
                /** @var \EmailService $emailService */
                $emailService = new \EmailService();
                $emailService->sendPasswordResetEmail($email, $user['username'], $token);
            }
        }
        } else {
            // Timing mitigation: perform equivalent dummy operations
            $dummy_token = bin2hex(random_bytes(32));
            hash('sha256', $dummy_token);
            usleep(50000); // 50ms dummy padding
        }

        return ['success' => true, 'message' => 'If that email exists, a reset link has been sent.'];
    }

    public function resetPassword($token, $new_password, $confirm_password) {
        if (empty($token) || empty($new_password)) {
            return ['success' => false, 'message' => 'Invalid request.'];
        }
        if ($new_password !== $confirm_password) {
            return ['success' => false, 'message' => 'Passwords do not match.'];
        }
        if (strlen($new_password) < 8) {
            return ['success' => false, 'message' => 'Password must be at least 8 characters.'];
        }

        $hash = hash('sha256', $token);
        $now  = date('Y-m-d H:i:s');

        $stmt = $this->conn->prepare("SELECT user_id FROM password_resets WHERE token_hash = ? AND expires_at > ? AND used = 0");
        $stmt->bind_param("ss", $hash, $now);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['success' => false, 'message' => 'Invalid or expired reset link.'];
        }

        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $upd = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->bind_param("si", $new_hash, $row['user_id']);
        $upd->execute();
        $upd->close();

        $mark = $this->conn->prepare("UPDATE password_resets SET used = 1 WHERE token_hash = ?");
        $mark->bind_param("s", $hash);
        $mark->execute();
        $mark->close();

        return ['success' => true, 'message' => 'Password reset successfully! Redirecting to login...'];
    }
}
/** @var \mysqli $conn */
$auth = new Auth($conn);
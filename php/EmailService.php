<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

require_once __DIR__ . '/config.php';

class EmailService {
    private $mailer;

    public function __construct() {
        $this->mailer = new PHPMailer(true);

        try {
            $this->mailer->isSMTP();
            $this->mailer->Host       = SMTP_HOST;
            $this->mailer->SMTPAuth   = true;
            $this->mailer->Username   = SMTP_USER;
            $this->mailer->Password   = SMTP_PASS;
            $this->mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $this->mailer->Port       = SMTP_PORT;
            $this->mailer->SMTPDebug  = 0;

            $this->mailer->SMTPOptions = [
                'ssl' => [
                    'verify_peer'       => false,
                    'verify_peer_name'  => false,
                    'allow_self_signed' => true
                ]
            ];
        } catch (Exception $e) {
            error_log("Email config error: " . $e->getMessage());
            throw new Exception("Mail service is currently offline.");
        }
    }

    private function resetMailer() {
        $this->mailer->clearAddresses();
        $this->mailer->clearAttachments();
        $this->mailer->clearCustomHeaders();
        $this->mailer->Encoding = 'quoted-printable';
    }

    public function sendSignupOTP($email, $otp) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return "Invalid email address.";
        try {
            $this->resetMailer();
            $this->mailer->setFrom(FROM_EMAIL, FROM_NAME);
            $this->mailer->addAddress($email);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Dasma Alert — Account Verification Code';

            $safe_otp = htmlspecialchars($otp, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $this->mailer->Body = "
                <div style='font-family:sans-serif;max-width:480px;margin:auto;padding:30px;color:#1e293b;border:1px solid #e2e8f0;border-radius:12px;'>
                    <h2 style='color:#dc2626;'>CDRRMO Account Clearance</h2>
                    <p>Enter the 6-character clearance code below to verify your registration:</p>
                    <div style='background:#f8fafc;padding:16px 24px;letter-spacing:10px;text-align:center;border-radius:8px;border:1px solid #cbd5e1;color:#dc2626;font-size:30px;font-weight:800;margin:20px 0;'>
                        {$safe_otp}
                    </div>
                    <p style='color:#64748b;font-size:12px;'>This code will expire in <strong>5 minutes</strong>. If you did not request this, please ignore this email.</p>
                </div>";

            $this->mailer->AltBody = "Your Dasma Alert verification code is: {$otp}\n\nExpires in 5 minutes.";
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Signup OTP failed for {$email}: " . $this->mailer->ErrorInfo);
            return $this->mailer->ErrorInfo;
        }
    }

    public function sendPasswordResetOTP($email, $username, $otp) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return "Invalid email address.";
        try {
            $this->resetMailer();
            $this->mailer->setFrom(FROM_EMAIL, FROM_NAME);
            $this->mailer->addAddress($email, $username);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Dasma Alert — Password Recovery Code';

            $safe_username = htmlspecialchars($username, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $safe_otp      = htmlspecialchars($otp, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $this->mailer->Body = "
                <div style='font-family:sans-serif;max-width:480px;margin:auto;padding:30px;color:#1e293b;border:1px solid #e2e8f0;border-radius:12px;'>
                    <h2 style='color:#dc2626;'>Password Recovery Request</h2>
                    <p>Hello <strong>{$safe_username}</strong>,</p>
                    <p>Use the recovery code below to reset your password:</p>
                    <div style='background:#f8fafc;padding:16px 24px;letter-spacing:10px;text-align:center;border-radius:8px;border:1px solid #cbd5e1;color:#dc2626;font-size:30px;font-weight:800;margin:20px 0;'>
                        {$safe_otp}
                    </div>
                    <p style='color:#64748b;font-size:12px;'>This code will expire in <strong>15 minutes</strong>. If you did not initiate this request, contact CDRRMO immediately.</p>
                </div>";

            $this->mailer->AltBody = "Hello {$safe_username},\n\nYour recovery code is: {$otp}\n\nExpires in 15 minutes.";
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Reset OTP failed for {$email}: " . $this->mailer->ErrorInfo);
            return $this->mailer->ErrorInfo;
        }
    }
    // -------------------------------------------------------
    // Send password reset link (Token-based)
    // -------------------------------------------------------
    public function sendPasswordResetEmail($email, $username, $token) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return "Invalid email address.";
        try {
            $this->resetMailer();

            $this->mailer->setFrom(FROM_EMAIL, FROM_NAME);
            $this->mailer->addAddress($email, $username);

            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Dasma Alert — Password Reset Request';

            $safe_username = htmlspecialchars($username, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $reset_link    = BASE_URL . '/php/reset_password.php?token=' . urlencode($token);
            $safe_link     = htmlspecialchars($reset_link, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            $this->mailer->Body = "
                <div style='font-family:sans-serif;max-width:480px;margin:auto;padding:30px;color:#1e293b;border:1px solid #e2e8f0;border-radius:12px;'>
                    <h2 style='color:#dc2626;'>Password Reset Request</h2>
                    <p>Hi <strong>{$safe_username}</strong>,</p>
                    <p>Click the button below to reset your password. This link expires in <strong>1 hour</strong>.</p>
                    <p style='text-align:center;margin:30px 0;'>
                        <a href='{$safe_link}'
                           style='background:#dc2626;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;'>
                           Reset Password
                        </a>
                    </p>
                    <p style='color:#64748b;font-size:12px;'>If you did not request this, please ignore this email. Your password will remain unchanged.</p>
                </div>";

            $this->mailer->AltBody = "Hi {$safe_username},\n\nReset your password by visiting:\n{$reset_link}\n\nThis link expires in 1 hour.";

            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log("Password reset email failed for {$email}: " . $this->mailer->ErrorInfo);
            return $this->mailer->ErrorInfo;
        }
    }
}
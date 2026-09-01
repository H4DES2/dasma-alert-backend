<?php
session_start();
require_once '../php/config.php';
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) { $auth = new Auth($conn); }
if (!$auth->isSuperAdmin()) { header("Location: ../php/login.php"); exit(); }

$user_id = $_SESSION['user_id'];

// Fetch theme, font_size, and user profile data
$stmt = $conn->prepare("
    SELECT 
        u.first_name, u.last_name, u.username, u.email, u.department, u.barangay,
        p.profile_photo, p.phone_number, p.radio_callsign, p.position,
        p.theme, p.font_size
    FROM users u 
    LEFT JOIN user_profiles p ON u.id = p.user_id 
    WHERE u.id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Dynamic Theme & Typography Injection
$themeClass = ($user['theme'] ?? 'light') === 'dark' ? 'global-dark-mode' : '';
$fontSize = !empty($user['font_size']) ? $user['font_size'] : '16px';

// Robust Path Resolution for Profile Picture
$raw_photo = $user['profile_photo'] ?? '';
$display_photo = '';

if (!empty($raw_photo)) {
    if (file_exists(__DIR__ . '/../' . $raw_photo)) {
        $display_photo = '../' . htmlspecialchars($raw_photo, ENT_QUOTES, 'UTF-8');
    } elseif (file_exists(__DIR__ . '/../../dasma_api/' . $raw_photo)) {
        $display_photo = '../../dasma_api/' . htmlspecialchars($raw_photo, ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $themeClass; ?>" style="font-size: <?php echo htmlspecialchars($fontSize); ?>;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | Command Center</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../css/admin/navbar.css?v=<?= filemtime('../css/admin/navbar.css') ?>">
    <link rel="stylesheet" href="../css/admin/profile.css?v=<?= filemtime('../css/admin/profile.css') ?>">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'navbar.php'; ?>

        <main class="main-content">
            <div class="profile-card">
                
                <div class="profile-header">
                    <div class="current-photo-large" id="photoPreview">
                        <?php if (!empty($display_photo)): ?>
                            <img src="<?php echo $display_photo; ?>?v=<?php echo time(); ?>" alt="Profile">
                        <?php else: ?>
                            <i class='bx bxs-user'></i>
                        <?php endif; ?>
                    </div>
                    <input type="file" id="profileInput" name="profile_picture" style="display: none;" accept="image/*" onchange="previewImage(this)">
                    <label for="profileInput" class="change-photo-text"><i class='bx bx-camera'></i> UPDATE PROFILE PHOTO</label>
                </div>
                
                <div id="profile-alert-box" class="alert-box"></div>
                
                <div class="section-title">Accessibility & Preferences</div>
                <div class="profile-grid">
                    <div class="grid-cell">
                        <label>Theme Appearance</label>
                        <div style="font-size: 0.8rem; color: #888; margin-bottom: 12px; font-weight: 600;">Switch between light and high-contrast dark modes</div>
                        <button class="btn-font" id="btn-theme" onclick="toggleTheme()" style="width: 100%; text-align: center; justify-content: center; display: flex; gap: 10px;">
                            <i class='bx bx-moon'></i> ENABLE DARK MODE
                        </button>
                    </div>
                    
                    <div class="grid-cell">
                        <label>Global Typography</label>
                        <div style="font-size: 0.8rem; color: #888; margin-bottom: 12px; font-weight: 600;">Scale the system text size for better visibility</div>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn-font" onclick="changeFont('decrease')" style="flex: 1;">A-</button>
                            <button class="btn-font" onclick="changeFont('reset')" style="flex: 1;">RESET</button>
                            <button class="btn-font" onclick="changeFont('increase')" style="flex: 1;">A+</button>
                        </div>
                    </div>
                </div>

                <div class="section-title">Primary Information</div>
                <div class="profile-grid">
                    <div class="grid-cell"><label>First Name</label><input type="text" id="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" placeholder="Given Name"></div>
                    <div class="grid-cell"><label>Last Name</label><input type="text" id="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" placeholder="Surname"></div>
                    
                    <div class="grid-cell"><label>System Username</label><input type="text" id="username" value="<?php echo htmlspecialchars($user['username']); ?>"></div>
                    <div class="grid-cell"><label>Email Address</label><input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>"></div>
                    
                    <div class="grid-cell"><label>Mobile Contact</label><input type="text" id="phone_number" value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>" placeholder="+63 9XX XXX XXXX"></div>
                </div>

                <div class="section-title">Professional Designation</div>
                <div class="profile-grid">
                    <div class="grid-cell"><label>Assigned Department</label><input type="text" id="department" value="<?php echo htmlspecialchars($user['department'] ?? ''); ?>" placeholder="e.g., CDRRMO Ops"></div>
                    <div class="grid-cell"><label>Radio Callsign</label><input type="text" id="radio_callsign" value="<?php echo htmlspecialchars($user['radio_callsign'] ?? ''); ?>" placeholder="Unit ID / Callsign"></div>
                    <div class="grid-cell full-width"><label>Position / Authority</label><input type="text" id="position" value="<?php echo htmlspecialchars($user['position'] ?? ''); ?>" placeholder="Current Office Position"></div>
                </div>

                <div class="section-title">Security & Authentication</div>
                <div class="profile-grid">
                    <div class="grid-cell full-width">
                        <label>Confirm Current Password (Required for any changes)</label>
                        <div class="pwd-wrapper">
                            <input type="password" id="current_password" placeholder="Verify identity to save changes">
                            <i class='bx bx-show toggle-password' onclick="togglePwd('current_password', this)"></i>
                        </div>
                    </div>
                    <div class="grid-cell">
                        <label>Update New Password</label>
                        <div class="pwd-wrapper">
                            <input type="password" id="new_password" placeholder="Leave blank to keep current">
                            <i class='bx bx-show toggle-password' onclick="togglePwd('new_password', this)"></i>
                        </div>
                    </div>
                    <div class="grid-cell">
                        <label>Verify New Password</label>
                        <div class="pwd-wrapper">
                            <input type="password" id="confirm_password" placeholder="Re-type new password">
                            <i class='bx bx-show toggle-password' onclick="togglePwd('confirm_password', this)"></i>
                        </div>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 20px;">
                    <button class="btn-save" onclick="updateAccount()">
                        <i class='bx bxs-check-shield'></i> COMMIT PROFILE UPDATES
                    </button>
                </div>

            </div>
        </main>
    </div>
     <script src="../js/admin/profile.js?v=<?= filemtime('../js/admin/profile.js') ?>" defer></script>               
</body>
</html>
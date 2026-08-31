<?php
session_start();
require_once '../php/config.php';
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) { $auth = new Auth($conn); }
if (!$auth->isAdmin()) { header("Location: ../php/login.php"); exit(); }

$user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT u.*, p.profile_photo, p.phone_number, p.radio_callsign, p.position, p.theme, p.font_size
    FROM users u
    LEFT JOIN user_profiles p ON u.id = p.user_id
    WHERE u.id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$assigned_brgy = $user['barangay'] ?? '';
$my_profile_photo = $user['profile_photo'] ?? '';

if ($my_profile_photo === 'NULL' || trim($my_profile_photo) === '') { 
    $my_profile_photo = false; 
} else {
    // Strip any directory junk (../, uploads/, etc.) and rebuild cleanly
    $my_profile_photo = 'uploads/' . basename($my_profile_photo);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile | Command Center</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../css/client/navbar.css">
    <link rel="stylesheet" href="../css/client/profile.css">
</head>
<body>
    <div class="dashboard-container">
        <?php include 'navbar.php'; ?>

        <main class="main-content">
            <div class="profile-card">
                
                <div class="profile-header">
                    <div class="current-photo-large" id="photoPreview">
                    <?php if ($my_profile_photo && file_exists(__DIR__ . "/../" . $my_profile_photo)): ?>
    <img src="../<?php echo htmlspecialchars($my_profile_photo); ?>" alt="Profile">
<?php else: ?>
    <i class='bx bxs-user'></i>
<?php endif; ?>
                    </div>
                    <input type="file" id="profileInput" style="display: none;" accept="image/*" onchange="previewImage(this)">
                    <label for="profileInput" class="change-photo-text">CHANGE PHOTO</label>
                </div>
                
                <div id="profile-alert-box" class="alert-box"></div>
                
                <div class="section-title">Accessibility & Preferences</div>
                <div class="profile-grid">
                    <div class="grid-cell">
                        <label>Theme Mode</label>
                        <div style="font-size: 0.75rem; color: #888; margin-bottom: 15px;">Reverse system colors globally</div>
                        <button class="btn-save" id="btn-theme" onclick="toggleTheme()" style="background: #222; color: white; padding: 10px 20px; box-shadow: none;">
                            <i class='bx bx-moon'></i> ENABLE DARK MODE
                        </button>
                    </div>
                    
                    <div class="grid-cell">
                        <label>System Font Size</label>
                        <div style="font-size: 0.75rem; color: #888; margin-bottom: 15px;">Adjust global text size</div>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn-font" onclick="changeFont('decrease')">A-</button>
                            <button class="btn-font" onclick="changeFont('reset')">A</button>
                            <button class="btn-font" onclick="changeFont('increase')">A+</button>
                        </div>
                    </div>
                </div>

                <div class="section-title">Personal Details</div>
                <div class="profile-grid">
                    <div class="grid-cell"><label>First Name</label><input type="text" id="first_name" value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>" placeholder="Not set"></div>
                    <div class="grid-cell"><label>Last Name</label><input type="text" id="last_name" value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>" placeholder="Not set"></div>
                    
                    <div class="grid-cell"><label>Username</label><input type="text" id="username" value="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" disabled style="opacity:0.6;"></div>
                    <div class="grid-cell"><label>Email Address</label><input type="email" id="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" disabled style="opacity:0.6;"></div>
                    
                    <div class="grid-cell"><label>Mobile Number</label><input type="text" id="phone_number" value="<?php echo htmlspecialchars($user['phone_number'] ?? ''); ?>" placeholder="Not set"></div>
                    <div class="grid-cell"><label>Position / Role</label><input type="text" id="position" value="<?php echo htmlspecialchars($user['position'] ?? ''); ?>" placeholder="Not set"></div>
                    
                    <div class="grid-cell full-width">
                        <label>Assigned Barangay</label>
                        <select id="barangay">
                            <option value="" disabled <?php echo empty($assigned_brgy) ? 'selected' : ''; ?>>Select a Barangay</option>
                            <?php
                            $b_res = $conn->query("SELECT name FROM barangays WHERE status = 'active' ORDER BY name ASC");
if ($b_res) {
    while ($row = $b_res->fetch_assoc()) {
        $brgy = $row['name'];
        $selected = ($assigned_brgy === $brgy) ? 'selected' : '';
        echo "<option value=\"$brgy\" $selected>$brgy</option>";
    }
}
                            ?>
                        </select>
                    </div>
                </div>

                <div class="section-title">Security & Passwords</div>
                <div class="profile-grid">
                    <div class="grid-cell full-width">
                        <label>Current Password (Required for changes)</label>
                        <div class="pwd-wrapper">
                            <input type="password" id="current_password" placeholder="Enter current password">
                            <i class='bx bx-show toggle-password' onclick="togglePwd('current_password', this)"></i>
                        </div>
                    </div>
                    <div class="grid-cell">
                        <label>New Password</label>
                        <div class="pwd-wrapper">
                            <input type="password" id="new_password" placeholder="••••••••">
                            <i class='bx bx-show toggle-password' onclick="togglePwd('new_password', this)"></i>
                        </div>
                    </div>
                    <div class="grid-cell">
                        <label>Confirm New Password</label>
                        <div class="pwd-wrapper">
                            <input type="password" id="confirm_password" placeholder="••••••••">
                            <i class='bx bx-show toggle-password' onclick="togglePwd('confirm_password', this)"></i>
                        </div>
                    </div>
                </div>

                <button class="btn-save" id="btn-save" onclick="updateAccount()">
                    <i class='bx bxs-edit'></i> SAVE CHANGES
                </button>

            </div>
        </main>
    </div>

    <script src="../js/client/profile.js?v=<?= filemtime('../js/client/profile.js') ?>"></script>
</body>
</html>
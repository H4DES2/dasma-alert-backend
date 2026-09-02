<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../php/config.php';
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) { 
    $auth = new Auth($conn); 
}
// Allow barangay admins and general admins
$allowed_roles = ['admin', 'brgy_admin', 'barangay', 'secretary', 'staff'];
$current_role = strtolower($_SESSION['role'] ?? '');

if (empty($_SESSION['user_id']) || (!in_array($current_role, $allowed_roles) && !$auth->isAdmin())) {
    header("Location: ../php/login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// 🚀 AJAX PHOTO UPLOAD HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    header('Content-Type: application/json');
    
    if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Upload error code: ' . $_FILES['profile_photo']['error']]);
        exit();
    }

    $file = $_FILES['profile_photo'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed_types)) {
        echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, WEBP, and GIF images are allowed.']);
        exit();
    }

    // Target upload directory
    $upload_dir = __DIR__ . '/../uploads/profiles/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'profile_' . $user_id . '_' . time() . '.' . strtolower($ext);
    $target_file = $upload_dir . $filename;
    $db_path = 'uploads/profiles/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $target_file)) {
        // Upsert user_profiles record
        $check = $conn->prepare("SELECT user_id FROM user_profiles WHERE user_id = ?");
        $check->bind_param("i", $user_id);
        $check->execute();
        $has_profile = $check->get_result()->num_rows > 0;
        $check->close();

        if ($has_profile) {
            $up = $conn->prepare("UPDATE user_profiles SET profile_photo = ? WHERE user_id = ?");
            $up->bind_param("si", $db_path, $user_id);
            $up->execute();
            $up->close();
        } else {
            $ins = $conn->prepare("INSERT INTO user_profiles (user_id, profile_photo) VALUES (?, ?)");
            $ins->bind_param("is", $user_id, $db_path);
            $ins->execute();
            $ins->close();
        }

        $_SESSION['profile_photo'] = $db_path;
        echo json_encode(['success' => true, 'photo_url' => '../' . $db_path]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file.']);
    }
    exit();
}

// 🚀 AJAX PROFILE PREFERENCES UPDATE HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_preferences') {
    header('Content-Type: application/json');
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone_number'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $barangay = trim($_POST['barangay'] ?? '');

    // Update users table
    $u_stmt = $conn->prepare("UPDATE users SET first_name = ?, last_name = ?, barangay = ? WHERE id = ?");
    $u_stmt->bind_param("sssi", $first_name, $last_name, $barangay, $user_id);
    $u_stmt->execute();
    $u_stmt->close();

    // Upsert user_profiles table
    $p_stmt = $conn->prepare("
        INSERT INTO user_profiles (user_id, phone_number, position) 
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE phone_number = VALUES(phone_number), position = VALUES(position)
    ");
    $p_stmt->bind_param("iss", $user_id, $phone, $position);
    $p_stmt->execute();
    $p_stmt->close();

    echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    exit();
}

// Fetch user data
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
$raw_photo = trim($user['profile_photo'] ?? '');

// Generate photo path or fallback inline SVG
$default_avatar = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="90" height="90" viewBox="0 0 24 24" fill="%2394a3b8"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>');

$profile_img_src = $default_avatar;
if (!empty($raw_photo) && $raw_photo !== 'NULL') {
    $clean_path = ltrim($raw_photo, '/');
    if (file_exists(__DIR__ . '/../' . $clean_path)) {
        $profile_img_src = '../' . htmlspecialchars($clean_path);
    }
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
                    <div class="current-photo-large" id="photoPreview" style="overflow: hidden; border-radius: 50%; width: 110px; height: 110px; margin: 0 auto 15px auto; border: 3px solid #d32f2f; display: flex; align-items: center; justify-content: center; background: #f1f5f9;">
                        <img id="imgDisplay" src="<?= $profile_img_src ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                    </div>
                    <input type="file" id="profileInput" style="display: none;" accept="image/*" onchange="uploadProfilePhoto(this)">
                    <label for="profileInput" class="change-photo-text" id="changePhotoLabel" style="cursor: pointer;">CHANGE PHOTO</label>
                </div>
                
                <div id="profile-alert-box" class="alert-box" style="display: none; margin-bottom: 20px; padding: 12px 18px; border-radius: 12px; font-weight: 700; font-size: 0.85rem;"></div>
                
                <div class="section-title">Accessibility & Preferences</div>
                <div class="profile-grid">
                    <div class="grid-cell">
                        <label>Theme Mode</label>
                        <div style="font-size: 0.75rem; color: #888; margin-bottom: 15px;">Reverse system colors globally</div>
                        <button class="btn-save" id="btn-theme" onclick="toggleTheme()" style="background: #222; color: white; padding: 10px 20px; box-shadow: none;">
                            <i class='bx bx-moon'></i> TOGGLE THEME
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

    <script>
    function uploadProfilePhoto(input) {
        if (!input.files || !input.files[0]) return;
        const file = input.files[0];
        const label = document.getElementById('changePhotoLabel');
        const alertBox = document.getElementById('profile-alert-box');
        
        label.textContent = 'UPLOADING...';

        const formData = new FormData();
        formData.append('profile_photo', file);

        fetch('profile.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            label.textContent = 'CHANGE PHOTO';
            if (data.success) {
                // Update profile card image
                const img = document.getElementById('imgDisplay');
                if (img) img.src = data.photo_url + '?v=' + new Date().getTime();

                // Update navbar avatar
                const navAvatar = document.querySelector('.profile-toggle img');
                if (navAvatar) navAvatar.src = data.photo_url + '?v=' + new Date().getTime();

                alertBox.textContent = 'Profile photo updated successfully!';
                alertBox.style.background = '#d4edda';
                alertBox.style.color = '#155724';
                alertBox.style.display = 'block';
            } else {
                alertBox.textContent = data.message || 'Error updating photo.';
                alertBox.style.background = '#f8d7da';
                alertBox.style.color = '#721c24';
                alertBox.style.display = 'block';
            }
        })
        .catch(err => {
            label.textContent = 'CHANGE PHOTO';
            alertBox.textContent = 'Network error during upload.';
            alertBox.style.background = '#f8d7da';
            alertBox.style.color = '#721c24';
            alertBox.style.display = 'block';
        });
    }
    </script>
    <script src="../js/client/profile.js?v=<?= filemtime('../js/client/profile.js') ?>"></script>
</body>
</html>
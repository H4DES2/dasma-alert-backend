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
    <link rel="stylesheet" href="../css/admin/navbar.css">
    <link rel="stylesheet" href="../css/admin/profile.css">
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

   <script>
        function toggleTheme() {
            const html = document.documentElement;
            html.classList.toggle('global-dark-mode');
            const isDark = html.classList.contains('global-dark-mode');
            const newTheme = isDark ? 'dark' : 'light';
            const currentFont = html.style.fontSize || '16px';
            updateThemeButtonUI();
            savePreferencesToDB(newTheme, currentFont);
        }

        function changeFont(action) {
            const html = document.documentElement;
            let compSize = window.getComputedStyle(html).fontSize;
            let currentSize = parseInt(html.style.fontSize) || parseInt(compSize) || 16;
            
            if (action === 'increase' && currentSize < 24) currentSize += 2;
            else if (action === 'decrease' && currentSize > 12) currentSize -= 2;
            else if (action === 'reset') currentSize = 16;
            
            const newSize = currentSize + 'px';
            const currentTheme = html.classList.contains('global-dark-mode') ? 'dark' : 'light';
            
            html.style.fontSize = newSize;
            savePreferencesToDB(currentTheme, newSize);
        }

        function savePreferencesToDB(theme, fontSize) {
            let fd = new FormData();
            fd.append('action', 'save_preferences');
            fd.append('theme', theme);
            fd.append('font_size', fontSize);
            fetch('admin_actions.php', { method: 'POST', body: fd }).catch(err => console.error('Error saving preferences:', err));
        }

        function updateThemeButtonUI() {
            const isDark = document.documentElement.classList.contains('global-dark-mode');
            let btn = document.getElementById('btn-theme');
            if(btn) {
                btn.innerHTML = isDark ? "<i class='bx bx-sun'></i> ENABLE LIGHT MODE" : "<i class='bx bx-moon'></i> ENABLE DARK MODE";
            }
        }
        
        document.addEventListener('DOMContentLoaded', updateThemeButtonUI);

        function togglePwd(inputId, icon) {
            let input = document.getElementById(inputId);
            input.type = input.type === "password" ? "text" : "password";
            icon.classList.toggle('bx-show'); icon.classList.toggle('bx-hide');
        }

        function previewImage(input) {
            if (input.files && input.files[0]) {
                let reader = new FileReader();
                reader.onload = e => document.getElementById('photoPreview').innerHTML = `<img src="${e.target.result}" style="width: 100%; height: 100%; object-fit: cover;">`;
                reader.readAsDataURL(input.files[0]);
            }
        }

        function updateAccount() {
            const alertBox = document.getElementById('profile-alert-box');
            let currentPwd = document.getElementById('current_password').value.trim();
            let newPwd = document.getElementById('new_password').value;
            let confirmPwd = document.getElementById('confirm_password').value;

            if (currentPwd === "") {
                alertBox.className = 'alert-box alert-error';
                alertBox.textContent = "Please enter your Current Password to save changes.";
                alertBox.style.display = 'block';
                return;
            }

            if (newPwd !== "" && newPwd !== confirmPwd) {
                alertBox.className = 'alert-box alert-error';
                alertBox.textContent = "New passwords do not match!";
                alertBox.style.display = 'block';
                return;
            }

            let fd = new FormData();
            fd.append('action', 'update_admin_account');
            fd.append('first_name', document.getElementById('first_name').value);
            fd.append('last_name', document.getElementById('last_name').value);
            fd.append('username', document.getElementById('username').value);
            fd.append('email', document.getElementById('email').value);
            fd.append('phone_number', document.getElementById('phone_number').value);
            fd.append('department', document.getElementById('department').value);
            fd.append('radio_callsign', document.getElementById('radio_callsign').value);
            fd.append('position', document.getElementById('position').value);
            fd.append('current_password', currentPwd);
            fd.append('new_password', newPwd);
            
            let pic = document.getElementById('profileInput').files[0];
            if (pic) fd.append('profile_picture', pic);

            let saveBtn = document.querySelector('.btn-save');
            saveBtn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> SYNCHRONIZING...";
            saveBtn.disabled = true;

            fetch('admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch(e) {
                    console.error("Server raw response:", text);
                    alertBox.className = 'alert-box alert-error';
                    alertBox.textContent = "Server error occurred. Check browser console.";
                    alertBox.style.display = 'block';
                    saveBtn.innerHTML = "<i class='bx bxs-check-shield'></i> COMMIT PROFILE UPDATES";
                    saveBtn.disabled = false;
                    return;
                }

                alertBox.className = data.success ? 'alert-box alert-success' : 'alert-box alert-error';
                alertBox.textContent = data.message;
                alertBox.style.display = 'block';

                if (data.success) {
                    setTimeout(() => { location.reload(); }, 1200);
                } else {
                    saveBtn.innerHTML = "<i class='bx bxs-check-shield'></i> COMMIT PROFILE UPDATES";
                    saveBtn.disabled = false;
                }
            })
            .catch(err => {
                console.error("Fetch Error:", err);
                alertBox.className = 'alert-box alert-error';
                alertBox.textContent = "Network error. Please try again.";
                alertBox.style.display = 'block';
                saveBtn.innerHTML = "<i class='bx bxs-check-shield'></i> COMMIT PROFILE UPDATES"; 
                saveBtn.disabled = false;
            });
        }
    </script>
</body>
</html>
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
    <style>
        .dashboard-container { display: flex; min-height: 100vh; background: #cfd8dc; transition: 0.3s; }
        .main-content { flex: 1; margin-top: 80px; padding: 40px; display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; }
        
        .profile-card { background: #e0e5ec; padding: 40px; border-radius: 25px; box-shadow: 12px 12px 20px rgba(163, 177, 198, 0.9), -12px -12px 20px rgba(255, 255, 255, 0.7), inset 2px 2px 5px rgba(255, 255, 255, 0.4); width: 100%; max-width: 900px; transition: 0.3s; border: none; }
        
        .profile-header { text-align: center; margin-bottom: 30px; }
        .current-photo-large { width: 120px; height: 120px; border-radius: 50%; margin: 0 auto 15px; overflow: hidden; background: #e0e5ec; display: flex; align-items: center; justify-content: center; border: 4px solid #b10000; box-shadow: 6px 6px 12px rgba(163, 177, 198, 0.8), -6px -6px 12px rgba(255, 255, 255, 0.6); }
        .current-photo-large img { width: 100%; height: 100%; object-fit: cover; }
        .current-photo-large i { font-size: 80px; color: #888; }
        .change-photo-text { color: #b10000; font-size: 0.85rem; cursor: pointer; text-decoration: none; font-weight: 800; transition: 0.2s; display: inline-block; padding: 8px 16px; border-radius: 12px; background: #e0e5ec; box-shadow: 4px 4px 8px rgba(163, 177, 198, 0.6), -4px -4px 8px rgba(255, 255, 255, 0.5); }
        .change-photo-text:hover { transform: scale(0.95); box-shadow: inset 3px 3px 6px rgba(163, 177, 198, 0.6), inset -3px -3px 6px rgba(255, 255, 255, 0.5); }

        .profile-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 25px; transition: 0.3s; }
        .grid-cell { padding: 15px 20px; border-radius: 15px; background: #e0e5ec; box-shadow: inset 4px 4px 8px rgba(163, 177, 198, 0.6), inset -4px -4px 8px rgba(255, 255, 255, 0.5); transition: 0.3s; }
        .grid-cell.full-width { grid-column: span 2; }
        .grid-cell label { display: block; font-size: 0.7rem; color: #b10000; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 800; margin-bottom: 8px; }
        .grid-cell input, .grid-cell select { background: transparent; border: none; color: #333; width: 100%; font-size: 1rem; outline: none; padding: 5px 0; font-weight: 600; }
        .grid-cell select { cursor: pointer; }

        .pwd-wrapper { position: relative; display: flex; align-items: center; }
        .pwd-wrapper input { width: 100%; padding-right: 30px; }
        .toggle-password { position: absolute; right: 0; cursor: pointer; color: #888; font-size: 1.2rem; transition: 0.2s; }

        .btn-save { background: #b10000; color: white; border: none; padding: 12px 30px; border-radius: 12px; cursor: pointer; font-weight: 800; font-size: 0.95rem; display: inline-flex; align-items: center; gap: 8px; transition: 0.3s; box-shadow: 6px 6px 12px rgba(163, 177, 198, 0.8), -6px -6px 12px rgba(255, 255, 255, 0.6); }
        .btn-save:hover { transform: translateY(2px); box-shadow: 3px 3px 6px rgba(163, 177, 198, 0.8), -3px -3px 6px rgba(255, 255, 255, 0.6); }
        .section-title { color: #555; font-size: 0.9rem; text-transform: uppercase; letter-spacing: 1px; margin: 30px 0 15px 0; font-weight: 900; }
        
        .btn-font { background: #e0e5ec; color: #333; border: none; padding: 8px 18px; border-radius: 10px; cursor: pointer; font-weight: 800; transition: 0.2s; box-shadow: 4px 4px 8px rgba(163, 177, 198, 0.6), -4px -4px 8px rgba(255, 255, 255, 0.5); }
        .btn-font:hover { transform: scale(0.95); box-shadow: inset 3px 3px 6px rgba(163, 177, 198, 0.6), inset -3px -3px 6px rgba(255, 255, 255, 0.5); }

        html.global-dark-mode body, html.global-dark-mode .dashboard-container { background: #1a1d21 !important; }
        html.global-dark-mode .profile-card { background: #2b3035 !important; box-shadow: 15px 15px 35px rgba(0,0,0,0.6), -10px -10px 20px rgba(50,55,60,0.2), inset 2px 2px 5px rgba(255,255,255,0.05); }
        html.global-dark-mode .current-photo-large { border-color: #770000; background: #1a1d21; box-shadow: none; }
        html.global-dark-mode .change-photo-text { background: #2b3035; color: #f4f6f9; box-shadow: 4px 4px 8px rgba(0,0,0,0.5), -4px -4px 8px rgba(50,55,60,0.2); }
        html.global-dark-mode .grid-cell { background: #2b3035; box-shadow: inset 4px 4px 10px rgba(0,0,0,0.5), inset -4px -4px 10px rgba(50,55,60,0.2); }
        html.global-dark-mode .grid-cell label { color: #ff5252; }
        html.global-dark-mode .grid-cell input, html.global-dark-mode .grid-cell select { color: #f4f6f9; }
        html.global-dark-mode .grid-cell select option { background: #2b3035; color: #f4f6f9; }
        html.global-dark-mode .section-title { color: #b0bec5; }
        html.global-dark-mode .btn-save { background: #770000; color: #fff; box-shadow: 6px 6px 12px rgba(0,0,0,0.5), -6px -6px 12px rgba(50,55,60,0.2); }
        html.global-dark-mode .btn-font { background: #2b3035; color: white; box-shadow: 4px 4px 8px rgba(0,0,0,0.5), -4px -4px 8px rgba(50,55,60,0.2); }

        .alert-box { padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; font-weight: 700; font-size: 0.95rem; display: none; text-align: left; width: 100%; box-sizing: border-box; transition: opacity 0.5s ease; box-shadow: inset 3px 3px 6px rgba(0,0,0,0.1); }
        .alert-success { background-color: #e8f5e9; color: #1b5e20; border: 1px solid #c8e6c9; }
        .alert-error { background-color: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        
        html.global-dark-mode .alert-success { background: rgba(46, 125, 50, 0.2); color: #81c784; border: none; }
        html.global-dark-mode .alert-error { background: rgba(198, 40, 40, 0.2); color: #e57373; border: none; }
        /* 🚀 MOBILE RESPONSIVE OVERRIDES */
        @media (max-width: 768px) {
            .main-content { 
                padding: 15px !important; 
                margin-top: 100px !important; 
            }
            .profile-card { 
                padding: 25px 20px; 
            }
            .profile-grid { 
                grid-template-columns: 1fr; /* Stacks the 2 columns into 1 */
                gap: 15px; 
            }
            .grid-cell.full-width { 
                grid-column: 1; /* Resets the full-width span for mobile */
            }
            .btn-save { 
                width: 100%; 
                justify-content: center; 
            }
            .pwd-wrapper input {
                font-size: 0.9rem; /* Prevents input zooming on iOS Safari */
            }
            .section-title {
                text-align: center;
            }
        }
    </style>
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
            let currentSize = parseInt(html.style.fontSize) || 16;

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
            fetch('../admin/admin_actions.php', { method: 'POST', body: fd }).catch(err => console.error(err));
        }

        function updateThemeButtonUI() {
            const isDark = document.documentElement.classList.contains('global-dark-mode');
            let btn = document.getElementById('btn-theme');
            if(btn) {
                if(isDark) {
                    btn.innerHTML = "<i class='bx bx-sun'></i> ENABLE LIGHT MODE";
                    btn.style.background = "#f4f6f9"; btn.style.color = "#111";
                } else {
                    btn.innerHTML = "<i class='bx bx-moon'></i> ENABLE DARK MODE";
                    btn.style.background = "#222"; btn.style.color = "white";
                }
            }
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('global-dark-mode');
            const savedFont = localStorage.getItem('fontSize');
            if (savedFont) document.documentElement.style.fontSize = savedFont;
            updateThemeButtonUI();
        });

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
            let saveBtn = document.getElementById('btn-save');
            
            let currentPwd = document.getElementById('current_password').value;
            let newPwd = document.getElementById('new_password').value;
            let confirmPwd = document.getElementById('confirm_password').value;

            alertBox.style.display = 'none';

            // 🚀 THE FIX: Force the user to enter their current password, and cleanly validate new passwords!
            if (currentPwd === "") {
                alertBox.className = 'alert-box alert-error';
                alertBox.textContent = "Current password is required to save changes.";
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
            fd.append('phone_number', document.getElementById('phone_number').value);
            fd.append('position', document.getElementById('position').value);
            fd.append('barangay', document.getElementById('barangay').value);
            
            fd.append('current_password', currentPwd);
            fd.append('new_password', newPwd);
            
            let pic = document.getElementById('profileInput').files[0];
            if (pic) fd.append('profile_picture', pic);

            saveBtn.innerHTML = "<i class='bx bx-loader bx-spin'></i> SAVING...";
            saveBtn.disabled = true;

            fetch('../admin/admin_actions.php', { method: 'POST', body: fd })
            .then(async res => {
                const rawText = await res.text();
                try {
                    return JSON.parse(rawText);
                } catch (e) {
                    throw new Error(rawText);
                }
            })
            .then(data => {
                alertBox.className = data.success ? 'alert-box alert-success' : 'alert-box alert-error';
                alertBox.textContent = data.message;
                alertBox.style.display = 'block';
                alertBox.style.opacity = '1';

                if (data.success) {
                    setTimeout(() => { location.reload(); }, 1500);
                } else {
                    saveBtn.innerHTML = "<i class='bx bxs-edit'></i> SAVE CHANGES";
                    saveBtn.disabled = false;
                }
            })
            .catch(err => {
                alertBox.className = 'alert-box alert-error';
                alertBox.innerHTML = "<b>ERROR:</b> " + err.message;
                alertBox.style.display = 'block';
                saveBtn.innerHTML = "<i class='bx bxs-edit'></i> SAVE CHANGES"; 
                saveBtn.disabled = false;
            });
        }
    </script>
</body>
</html>
<?php
require_once '../php/config.php';

$s_user_id = (int)($_SESSION['user_id'] ?? 0);
$s_role    = $_SESSION['role'] ?? '';

$stmt = $conn->prepare("
    SELECT u.username, u.first_name, p.theme, p.font_size, p.profile_photo
    FROM users u
    LEFT JOIN user_profiles p ON u.id = p.user_id
    WHERE u.id = ?
");
$stmt->bind_param("i", $s_user_id);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc() ?? [];
$stmt->close();

$ALLOWED_THEMES = ['light', 'dark'];
$ALLOWED_FONTS  = ['12px', '14px', '16px', '18px', '20px', '22px', '24px'];

// Normalize case and strip whitespace; default to 'dark'
$raw_theme  = strtolower(trim($user_data['theme'] ?? 'dark'));
$raw_font   = trim($user_data['font_size'] ?? '16px');

$db_theme   = in_array($raw_theme, $ALLOWED_THEMES) ? $raw_theme : 'dark';
$db_font    = in_array($raw_font,  $ALLOWED_FONTS)  ? $raw_font  : '16px';

$js_theme   = json_encode($db_theme);
$js_font    = json_encode($db_font);

// Safe fallback avatar using inline SVG data URI to avoid 404 requests
// Base64 encode the fallback SVG so it never breaks HTML attributes
$default_avatar = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="#94a3b8"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>');
// Safe fallback avatar using inline SVG data URI to avoid 404 requests
$default_avatar = 'data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="%2394a3b8"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>';

$raw_photo     = trim($user_data['profile_photo'] ?? '');
$profile_photo = $default_avatar;

if (!empty($raw_photo)) {
    $clean_path = ltrim($raw_photo, '/');
    if (file_exists(__DIR__ . '/../' . $clean_path)) {
        $profile_photo = '../' . htmlspecialchars($clean_path, ENT_QUOTES, 'UTF-8');
    } elseif (file_exists(__DIR__ . '/../../' . $clean_path)) {
        $profile_photo = '../../' . htmlspecialchars($clean_path, ENT_QUOTES, 'UTF-8');
    } else {
        $profile_photo = '/' . htmlspecialchars($clean_path, ENT_QUOTES, 'UTF-8');
    }
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Inline theme applier to prevent flash of light mode -->
<script>
    (function() {
        const dbTheme  = <?= $js_theme ?>;
        const fontSize = <?= $js_font ?>;
        
        localStorage.setItem('dasma_theme', dbTheme);

        if (dbTheme === 'dark') {
            document.documentElement.classList.add('global-dark-mode');
        } else {
            document.documentElement.classList.remove('global-dark-mode');
        }
        document.documentElement.style.setProperty('font-size', fontSize, 'important');
    })();
</script>

<link rel="stylesheet" href="../css/admin/navbar.css?v=<?= filemtime('../css/admin/navbar.css') ?>">

<nav class="custom-gooey-navbar">
    <div class="navbar-brand">
        <div class="logo-container">
            <img src="../uploads/system/DasmAlert.png" alt="Logo" class="brand-logo" onerror="this.src='<?= $default_avatar ?>'">
        </div>
        <h2>DASMA ALERT</h2>
    </div>
    
    <div id="navbarSupportedContent">
        <ul>
            <div class="hori-selector">
                <div class="left"></div>
                <div class="right"></div>
            </div>
            <li class="<?= $current_page == 'dashboard.php' ? 'active' : '' ?>">
                <a href="dashboard.php" style="position: relative;">
                    <i class='bx bxs-map-alt'></i> <span>DASHBOARD</span>
                    <span id="nav-incident-badge" style="display:none; position:absolute; top:12px; right:8px; background:#d32f2f; color:white; font-size:0.6rem; padding:3px 6px; border-radius:50%; font-weight:bold; box-shadow: 0 2px 5px rgba(0,0,0,0.3);">0</span>
                </a>
            </li>
            <li class="<?= $current_page == 'resource_tracking.php' ? 'active' : '' ?>"><a href="resource_tracking.php"><i class='bx bxs-truck'></i> <span>RESOURCE TRACKING</span></a></li>
            <li class="<?= $current_page == 'evacuation_centers.php' ? 'active' : '' ?>"><a href="evacuation_centers.php"><i class='bx bxs-home-heart'></i> <span>EVACUATION CENTERS</span></a></li>
            <li class="<?= $current_page == 'analytics.php' ? 'active' : '' ?>"><a href="analytics.php"><i class='bx bxs-report'></i> <span>ANALYTICS & REPORTS</span></a></li>
            <?php if ($s_role === 'superadmin'): ?>
            <li class="<?= $current_page == 'user_management.php' ? 'active' : '' ?>"><a href="user_management.php"><i class='bx bxs-group'></i> <span>USER MANAGEMENT</span></a></li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="navbar-actions">
        <?php if ($s_role === 'superadmin'): ?>
        <button onclick="openGlobalBroadcastModal()" class="broadcast-btn"><i class='bx bx-broadcast'></i> <span>BROADCAST</span></button>
        <?php endif; ?>

        <div class="profile-dropdown" id="profileDropdown">
            <div class="profile-toggle" onclick="toggleDropdown(event)">
    <img src="<?= $profile_photo ?>" alt="Profile" onerror="this.src='<?= $default_avatar ?>';">
</div>
            <div class="dropdown-menu">
                <a href="profile.php" class="dropdown-item"><i class='bx bxs-user-detail'></i> MY PROFILE</a>
                <?php if ($s_role === 'superadmin'): ?>
                <button onclick="showCustomModal('backup')" class="dropdown-item"><i class='bx bxs-data'></i> BACKUP DATABASE</button>
                <?php endif; ?>
                <button onclick="showCustomModal('logout')" class="dropdown-item logout-btn"><i class='bx bx-log-out-circle'></i> LOGOUT SESSION</button>
            </div>
        </div>
    </div>
</nav>

<?php if ($s_role === 'superadmin'): ?>
<div id="globalBroadcastModal" class="custom-modal-overlay">
    <div class="custom-modal-box" style="text-align: left;">
        <div style="display:flex; justify-content:space-between; margin-bottom:25px;">
            <h3 style="margin:0; color:#d32f2f;"><i class='bx bx-broadcast'></i> GLOBAL ALERT</h3>
            <span onclick="closeGlobalBroadcastModal()" style="cursor:pointer; font-size:2rem; line-height: 1; color: #aaa;">&times;</span>
        </div>
        <input type="text" id="globalBroadcastTitle" placeholder="ALERT TITLE" class="nav-input-field">
        <textarea id="globalBroadcastMessage" rows="4" placeholder="MESSAGE..." class="nav-input-field"></textarea>
        <select id="globalBroadcastSeverity" class="nav-input-field">
            <option value="info">INFO</option><option value="warning">WARNING</option><option value="critical">CRITICAL</option>
        </select>
        <button onclick="submitGlobalBroadcast()" style="background:#d32f2f; color:white; width:100%; padding:16px; border:none; border-radius:15px; font-weight:900;">TRANSMIT ALERT</button>
    </div>
</div>
<?php endif; ?>

<div id="customConfirmModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <i id="modalIcon" class="bx" style="font-size: 5rem; margin-bottom: 20px; display: block;"></i>
        <h3 id="modalTitle"></h3>
        <p id="modalMessage"></p>
        <div style="display: flex; gap: 15px;">
            <button onclick="closeCustomModal()" style="padding:14px; border-radius:12px; flex:1; cursor:pointer; border:none; background:#f1f4f8; font-weight:800; color:#444;">CANCEL</button>
            <button id="modalConfirmBtn" style="padding:14px; border-radius:12px; border:none; color:white; flex:2; cursor:pointer; font-weight:900;">PROCEED</button>
        </div>
    </div>
</div>

<script src="../js/admin/navbar.js?v=<?= filemtime('../js/admin/navbar.js') ?>" defer></script>
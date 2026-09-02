<?php
// 1. Initialize session and buffer
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../php/config.php';

$s_user_id = (int)($_SESSION['user_id'] ?? 0);

// Fetching UI preferences from user_profiles table
$s_stmt = $conn->prepare("SELECT u.username, u.first_name, p.theme, p.font_size, p.profile_photo FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.id = ?");
$s_stmt->bind_param("i", $s_user_id);
$s_stmt->execute();
$s_prefs = $s_stmt->get_result()->fetch_assoc() ?? [];
$s_stmt->close();

$_allowed_themes = ['light','dark'];
$_allowed_fonts  = ['14px','16px','18px','20px'];
$raw_theme = $s_prefs['theme'] ?? 'light';
$raw_font  = $s_prefs['font_size'] ?? '16px';
$db_theme  = in_array($raw_theme, $_allowed_themes) ? $raw_theme : 'light';
$db_font   = in_array($raw_font, $_allowed_fonts)   ? $raw_font  : '16px';
$js_theme  = json_encode($db_theme);
$js_font   = json_encode($db_font);

// Inline base64 SVGs to ensure fallbacks never 404 on Render
$default_logo = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="%23d32f2f"><path d="M12 2L1 21h22L12 2zm0 3.45l8.27 14.3H3.73L12 5.45zM11 10h2v4h-2zm0 6h2v2h-2z"/></svg>');
$default_avatar = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="%2394a3b8"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3zm0 14.2c-2.5 0-4.71-1.28-6-3.22.03-1.99 4-3.08 6-3.08 1.99 0 5.97 1.09 6 3.08-1.29 1.94-3.5 3.22-6 3.22z"/></svg>');

$profile_photo = (!empty($s_prefs['profile_photo'])) ? htmlspecialchars('../' . ltrim($s_prefs['profile_photo'], '/'), ENT_QUOTES, 'UTF-8') : $default_avatar;

$raw_name = !empty($s_prefs['first_name']) ? $s_prefs['first_name'] : ($s_prefs['username'] ?? 'User');
$display_name = htmlspecialchars($raw_name); 
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!-- Minimal head script to prevent theme/font flashbang on load -->
<script>
    (function() {
        const theme    = <?= $js_theme ?>;
        const fontSize = <?= $js_font ?>;
        if (theme === 'dark') { document.documentElement.classList.add('global-dark-mode'); } 
        else { document.documentElement.classList.remove('global-dark-mode'); }
        document.documentElement.style.fontSize = fontSize.includes('px') ? fontSize : fontSize + 'px';
    })();
</script>

<link rel="stylesheet" href="../css/client/navbar.css?v=<?= filemtime('../css/client/navbar.css') ?>">

<nav class="custom-gooey-navbar">
    <div class="navbar-brand">
        <div class="logo-container">
            <img src="../uploads/system/DasmAlert.png" alt="Logo" class="brand-logo" onerror="this.onerror=null; this.src='<?= $default_logo ?>';">
        </div>
        <h2>Dasma Alert</h2>
    </div>
    
    <div id="navbarSupportedContent">
        <ul>
            <div class="hori-selector">
                <div class="left"></div>
                <div class="right"></div>
            </div>
            <li class="<?= ($current_page == 'dashboard.php') ? 'active' : '' ?>">
                <a href="dashboard.php"><i class='bx bxs-map-alt'></i> <span>Dashboard</span></a>
            </li>
            <li class="<?= ($current_page == 'resource_tracking.php') ? 'active' : '' ?>">
                <a href="resource_tracking.php"><i class='bx bxs-truck'></i> <span>Resource Tracking</span></a>
            </li>
            <li class="<?= ($current_page == 'evacuation_centers.php') ? 'active' : '' ?>">
                <a href="evacuation_centers.php"><i class='bx bxs-home-heart'></i> <span>Evacuation Center</span></a>
            </li>
            <li class="<?= ($current_page == 'analytics.php') ? 'active' : '' ?>">
                <a href="analytics.php"><i class='bx bxs-report'></i> <span>Analytics</span></a>
            </li>
        </ul>
    </div>

    <div class="navbar-actions">
        <div class="profile-dropdown" id="profileDropdown">
            <div class="profile-toggle" onclick="toggleDropdown(event)">
                <img src="<?= $profile_photo ?>" alt="Profile" onerror="this.onerror=null; this.src='<?= $default_avatar ?>';">
            </div>
            <div class="dropdown-menu">
                <a href="profile.php" class="dropdown-item"><i class='bx bxs-user-detail'></i> My Profile</a>
                <button onclick="showCustomModal('logout')" class="dropdown-item logout-btn"><i class='bx bx-log-out-circle'></i> Logout</button>
            </div>
        </div>
    </div>
</nav>

<div id="customConfirmModal" class="custom-modal-overlay">
    <div class="custom-modal-box">
        <i id="modalIcon" class="bx bx-log-out-circle" style="color: #d32f2f; font-size: 4rem; margin-bottom: 15px; display: block;"></i>
        <h3 id="modalTitle">End Session</h3>
        <p id="modalMessage">Are you sure you want to log out?</p>
        <div style="display: flex; gap: 10px;">
            <button onclick="closeCustomModal()" style="padding: 14px; border-radius: 12px; flex: 1; cursor: pointer; border: none; background: #f1f4f8; font-weight: 800; color: #444;">Cancel</button>
            <button id="modalConfirmBtn" style="padding: 14px; border-radius: 12px; border: none; color: white; background: #d32f2f; flex: 1; cursor: pointer; font-weight: 800;">Proceed</button>
        </div>
    </div>
</div>

<script src="../js/client/navbar.js?v=<?= filemtime('../js/client/navbar.js') ?>" defer></script>
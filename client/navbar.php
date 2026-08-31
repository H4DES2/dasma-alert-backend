<?php
// 1. Initialize session and buffer
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../php/config.php';

$s_user_id = $_SESSION['user_id'];

// 🚀 JOINED: Fetching UI preferences from the new user_profiles table
// PATCH: prepared stmt — no $s_user_id interpolation
$s_user_id = (int)$s_user_id;
$s_stmt = $conn->prepare("SELECT u.username, u.first_name, p.theme, p.font_size, p.profile_photo FROM users u LEFT JOIN user_profiles p ON u.id = p.user_id WHERE u.id = ?");
$s_stmt->bind_param("i", $s_user_id);
$s_stmt->execute();
$s_prefs = $s_stmt->get_result()->fetch_assoc() ?? [];
$s_stmt->close();

$_allowed_themes = ['light','dark'];
$_allowed_fonts  = ['14px','16px','18px','20px'];
$raw_theme = $s_prefs['theme'] ?? 'light';
$raw_font  = $s_prefs['font_size'] ?? '16px';
$db_theme  = in_array($raw_theme,$_allowed_themes) ? $raw_theme : 'light';
$db_font   = in_array($raw_font,$_allowed_fonts)   ? $raw_font  : '16px';
$js_theme  = json_encode($db_theme);
$js_font   = json_encode($db_font);

// 🚀 FIX: Handles the profile photo path correctly
$profile_photo = (!empty($s_prefs['profile_photo'])) ? htmlspecialchars('../'.$s_prefs['profile_photo'],ENT_QUOTES,'UTF-8') : '../assets/default.png';

$raw_name = !empty($s_prefs['first_name']) ? $s_prefs['first_name'] : ($s_prefs['username'] ?? 'User');
$display_name = htmlspecialchars($raw_name); 
$current_page = basename($_SERVER['PHP_SELF']);
?>

<script>
    (function() {
        const theme    = <?php echo $js_theme; ?>;
        const fontSize = <?php echo $js_font; ?>;
        if (theme === 'dark') { document.documentElement.classList.add('global-dark-mode'); } 
        else { document.documentElement.classList.remove('global-dark-mode'); }
        document.documentElement.style.fontSize = fontSize.includes('px') ? fontSize : fontSize + 'px';
    })();
</script>

<link rel="stylesheet" href="../css/client/navbar.css">

<nav class="custom-gooey-navbar">
    <div class="navbar-brand">
        <div class="logo-container"><img src="../uploads/system/logo.png" alt="CDRRMO Logo" class="brand-logo" onerror="this.src='../assets/default.png'"></div>
        <h2>Dasma Alert</h2>
    </div>
    
    <div id="navbarSupportedContent">
        <ul>
            <div class="hori-selector">
                <div class="left"></div>
                <div class="right"></div>
            </div>
            <li class="<?php echo ($current_page == 'dashboard.php') ? 'active' : ''; ?>">
                <a href="dashboard.php"><i class='bx bxs-map-alt'></i> <span>Dashboard</span></a>
            </li>
            <li class="<?php echo ($current_page == 'resource_tracking.php') ? 'active' : ''; ?>">
                <a href="resource_tracking.php"><i class='bx bxs-truck'></i> <span>Resource Tracking</span></a>
            </li>
            <li class="<?php echo ($current_page == 'evacuation_centers.php') ? 'active' : ''; ?>">
                <a href="evacuation_centers.php"><i class='bx bxs-home-heart'></i> <span>Evacuation Center</span></a>
            </li>
            <li class="<?php echo ($current_page == 'analytics.php') ? 'active' : ''; ?>">
                <a href="analytics.php"><i class='bx bxs-report'></i> <span>Analytics</span></a>
            </li>
        </ul>
    </div>

    <div class="navbar-actions">
        <div class="profile-dropdown" id="profileDropdown">
            <div class="profile-toggle" onclick="toggleDropdown(event)">
                <img src="<?php echo $profile_photo; ?>" alt="Profile">
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

<script>
function moveHoriSelector(isInitialLoad = false) {
    const nav = document.getElementById('navbarSupportedContent');
    if (!nav) return;
    const ul = nav.querySelector('ul');
    const activeItem = ul.querySelector('li.active');
    const selector = nav.querySelector('.hori-selector');

    if (activeItem && selector) {
        const activeWidth = activeItem.offsetWidth;
        const activeHeight = activeItem.offsetHeight;
        const topPos = activeItem.offsetTop;
        const leftPos = activeItem.offsetLeft;

        if (isInitialLoad) selector.style.transition = 'none';
        else selector.style.transition = 'all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55)';
        
        selector.style.top = topPos + "px";
        selector.style.left = leftPos + "px";
        selector.style.height = activeHeight + "px";
        selector.style.width = activeWidth + "px";

        if (isInitialLoad) {
            void selector.offsetWidth; 
            selector.classList.add('ready');
            selector.style.transition = 'all 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55)';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() { moveHoriSelector(true); });
window.addEventListener('load', function() { moveHoriSelector(true); });
window.addEventListener('resize', function() { moveHoriSelector(false); });

document.querySelectorAll('#navbarSupportedContent li').forEach(li => {
    li.addEventListener('click', function(e) {
        e.preventDefault(); 
        const targetLink = this.querySelector('a').getAttribute('href');
        document.querySelectorAll('#navbarSupportedContent li').forEach(el => el.classList.remove('active'));
        this.classList.add('active');
        moveHoriSelector(false);
        setTimeout(() => { window.location.href = targetLink; }, 350); 
    });
});

function toggleDropdown(event) { event.stopPropagation(); document.getElementById('profileDropdown').classList.toggle('active'); }
window.addEventListener('click', function(e) { const dropdown = document.getElementById('profileDropdown'); if (dropdown && !dropdown.contains(e.target)) { dropdown.classList.remove('active'); } });

let pendingAction = null;
function showCustomModal(type) {
    const modal = document.getElementById('customConfirmModal');
    const confirmBtn = document.getElementById('modalConfirmBtn');
    document.getElementById('profileDropdown').classList.remove('active');

    if (type === 'logout') {
        pendingAction = () => {
            const form = document.createElement('form'); form.method = 'POST'; form.action = 'dashboard.php';
            const input = document.createElement('input'); input.type = 'hidden'; input.name = 'logout'; input.value = '1';
            form.appendChild(input); document.body.appendChild(form); form.submit();
        };
    }
    modal.style.display = 'flex';
    confirmBtn.onclick = function() { if(pendingAction) pendingAction(); closeCustomModal(); };
}
function closeCustomModal() { document.getElementById('customConfirmModal').style.display = 'none'; pendingAction = null; }
</script>
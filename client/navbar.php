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

<style>
    /* ... [Keeping all your existing CSS exactly as it is] ... */
    .custom-gooey-navbar {
        box-sizing: border-box; position: fixed; 
        top: 15px; left: 25px; right: 25px; 
        height: 80px; z-index: 1000;
        display: flex; align-items: center; justify-content: space-between; padding: 0 35px 0 25px; 
        background-color: #ffffff; 
        font-family: 'Segoe UI', sans-serif;
        border-radius: 25px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.12), 0 5px 15px rgba(0, 0, 0, 0.06);
        border: 1px solid rgba(255, 255, 255, 0.8);
        transition: all 0.3s ease;
    }

    .navbar-brand { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
    .logo-container { width: 45px; height: 45px; display: flex; align-items: center; justify-content: center; }
    .brand-logo { width: 100%; height: 100%; object-fit: contain; }
    .navbar-brand h2 { margin: 0; font-size: 1rem; font-weight: 900; color: #222; letter-spacing: 1px; text-transform: uppercase; white-space: nowrap;}

    #navbarSupportedContent { overflow: hidden; position: relative; height: 100%; display: flex; align-items: flex-end; flex: 1; justify-content: center; }
    #navbarSupportedContent ul { padding: 0; margin: 0; display: flex; height: 100%; position: relative; }
    #navbarSupportedContent li { list-style-type: none; display: flex; align-items: center; z-index: 2; cursor: pointer; }
    
    #navbarSupportedContent ul li a { 
        color: #777; text-decoration: none; 
        font-size: 0.65rem; font-weight: 900; 
        display: flex; align-items: center; gap: 8px; 
        padding: 20px 18px; 
        text-transform: uppercase; letter-spacing: 1.2px;
        transition: color 0.4s ease;
    }
    #navbarSupportedContent ul li.active a { color: #b10000; }

    .hori-selector { 
        display: inline-block; position: absolute; height: 100%; 
        top: 0; left: 0;
        background-color: #f0f3f7; 
        border-top-left-radius: 25px; border-top-right-radius: 25px; 
        margin-top: 15px; z-index: 1; opacity: 0;
    }
    .hori-selector.ready { opacity: 1; }
    .hori-selector .right, .hori-selector .left { position: absolute; width: 25px; height: 25px; background-color: #f0f3f7; bottom: 0; }
    .hori-selector .right { right: -25px; } .hori-selector .left { left: -25px; }
    .hori-selector .right:before, .hori-selector .left:before { content: ''; position: absolute; width: 50px; height: 50px; border-radius: 50%; background-color: #ffffff; transition: background-color 0.3s; }
    .hori-selector .right:before { bottom: 0; right: -25px; } .hori-selector .left:before { bottom: 0; left: -25px; }

    .navbar-actions { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }
    .profile-dropdown { position: relative; }
    .profile-toggle { 
        width: 48px; height: 48px; border-radius: 15px; border: 2px solid #edf2f7; 
        cursor: pointer; overflow: hidden; display: flex; align-items: center; 
        justify-content: center; background: #f8f9fa; transition: 0.3s; 
    }
    .profile-toggle img { width: 100%; height: 100%; object-fit: cover; }

    .dropdown-menu { 
        position: absolute; top: 65px; right: 0; background: #ffffff; border-radius: 20px; 
        min-width: 250px; display: none; flex-direction: column; padding: 10px;
        box-shadow: 0 20px 50px rgba(0,0,0,0.15); border: 1px solid #edf2f7; z-index: 2000;
    }
    .profile-dropdown.active .dropdown-menu { display: flex; animation: dropIn 0.3s ease; }
    @keyframes dropIn { from { opacity: 0; transform: translateY(-100%); } to { opacity: 1; transform: translateY(0); } }

    .dropdown-item { 
        padding: 14px 18px; display: flex; align-items: center; gap: 12px; 
        font-weight: 800; font-size: 0.8rem; color: #444; text-decoration: none; 
        border-radius: 12px; transition: 0.2s; text-transform: uppercase; border: none; background: transparent; width: 100%; cursor: pointer;
    }
    .dropdown-item:hover { background: #f8f9fa; color: #b10000; padding-left: 22px; }
    .dropdown-item.logout-btn { color: #d32f2f; margin-top: 5px; border-top: 1px solid #f1f4f8; }

    .custom-modal-overlay { display: none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); align-items: center; justify-content: center; }
    .custom-modal-box { background-color: #ffffff; padding: 40px; border-radius: 30px; width: 100%; max-width: 450px; text-align: center; box-shadow: 0 30px 70px rgba(0,0,0,0.3); border: none; }
    
    html.global-dark-mode .custom-gooey-navbar, html.global-dark-mode .dropdown-menu { background-color: #161b22; border-color: #30363d; }
    html.global-dark-mode .navbar-brand h2 { color: #f0f6fc; }
    html.global-dark-mode .hori-selector { background-color: #0d1117; }
    html.global-dark-mode .hori-selector .right, html.global-dark-mode .hori-selector .left { background-color: #0d1117; }
    html.global-dark-mode .hori-selector .right:before, html.global-dark-mode .hori-selector .left:before { background-color: #161b22; }
    html.global-dark-mode #navbarSupportedContent ul li a { color: #8b949e; }
    html.global-dark-mode #navbarSupportedContent ul li.active a { color: #f4f6f9; }
    html.global-dark-mode .dropdown-item { color: #c9d1d9; }
    html.global-dark-mode .dropdown-item:hover { background: #21262d; }
    html.global-dark-mode .custom-modal-box { background-color: #161b22; border: 1px solid #30363d; }

    /* 🚀 APPLIED RESPONSIVENESS */
    @media (max-width: 1024px) {
        #navbarSupportedContent ul li a span { display: none; }
        #navbarSupportedContent ul li a { padding: 20px 15px; }
    }

    @media (max-width: 768px) {
        .navbar-brand h2 { display: none; }
        .custom-gooey-navbar { left: 15px; right: 15px; top: 15px; padding: 0 15px 0 15px; }
        .profile-toggle { width: 40px; height: 40px; }
        .logo-container { width: 38px; height: 38px; }
    }
    
    @media (max-width: 480px) {
        #navbarSupportedContent ul li a { padding: 20px 10px; font-size: 1.2rem; }
        .custom-modal-box { padding: 25px 20px; }
    }
</style>

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
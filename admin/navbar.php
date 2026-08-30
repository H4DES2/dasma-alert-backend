<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
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
$s_prefs = $stmt->get_result()->fetch_assoc();
$stmt->close();

$ALLOWED_THEMES = ['light', 'dark'];
$ALLOWED_FONTS  = ['12px', '14px', '16px', '18px', '20px', '22px', '24px'];

$raw_theme  = $s_prefs['theme']     ?? 'light';
$raw_font   = $s_prefs['font_size'] ?? '16px';

$db_theme   = in_array($raw_theme, $ALLOWED_THEMES) ? $raw_theme : 'light';
$db_font    = in_array($raw_font,  $ALLOWED_FONTS)  ? $raw_font  : '16px';

$js_theme   = json_encode($db_theme);
$js_font    = json_encode($db_font);

// 🚨 ROBUST PATH RESOLVER FOR NAVBAR AVATAR
$raw_photo     = $s_prefs['profile_photo'] ?? '';
$profile_photo = '../assets/default.png';

if (!empty($raw_photo)) {
    if (file_exists(__DIR__ . '/../' . $raw_photo)) {
        $profile_photo = '../' . htmlspecialchars($raw_photo, ENT_QUOTES, 'UTF-8');
    } elseif (file_exists(__DIR__ . '/../../dasma_api/' . $raw_photo)) {
        $profile_photo = '../../dasma_api/' . htmlspecialchars($raw_photo, ENT_QUOTES, 'UTF-8');
    }
}

$current_page = basename($_SERVER['PHP_SELF']);
?>

<script>
    (function() {
        const theme    = <?= $js_theme ?>;
        const fontSize = <?= $js_font ?>;
        if (theme === 'dark') {
            document.documentElement.classList.add('global-dark-mode');
        } else {
            document.documentElement.classList.remove('global-dark-mode');
        }
        document.documentElement.style.setProperty('font-size', fontSize, 'important');
    })();
</script>

<style>
    /* ============================================================
       🚀 PRESERVED: ORIGINAL GOOEY NAVBAR & BLOB STYLES
       ============================================================ */
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
    #navbarSupportedContent li { list-style-type: none; display: flex; align-items: center; z-index: 2; }
    
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
        margin-top: 15px; z-index: 1; 
        transition: all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
    }
    .hori-selector .right, .hori-selector .left { position: absolute; width: 25px; height: 25px; background-color: #f0f3f7; bottom: 0; }
    .hori-selector .right { right: -25px; } .hori-selector .left { left: -25px; }
    .hori-selector .right:before, .hori-selector .left:before { content: ''; position: absolute; width: 50px; height: 50px; border-radius: 50%; background-color: #ffffff; transition: background-color 0.3s; }
    .hori-selector .right:before { bottom: 0; right: -25px; } .hori-selector .left:before { bottom: 0; left: -25px; }

    .navbar-actions { display: flex; align-items: center; gap: 15px; flex-shrink: 0; }
    .broadcast-btn { 
        padding: 10px 18px; color: #b10000 !important; border-radius: 12px; 
        background: transparent; border: 2px solid #b10000; cursor: pointer; 
        font-weight: 900; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px;
        display: flex; align-items: center; gap: 8px; transition: 0.3s; white-space: nowrap;
    }
    .broadcast-btn:hover { background: #b10000; color: #ffffff !important; box-shadow: 0 5px 15px rgba(177, 0, 0, 0.2); }

    .profile-dropdown { position: relative; }
    .profile-toggle { 
        width: 48px; height: 48px; border-radius: 50%; border: 2px solid #edf2f7; 
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
    @keyframes dropIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }

    .dropdown-item { 
        padding: 14px 18px; display: flex; align-items: center; gap: 12px; 
        font-weight: 800; font-size: 0.8rem; color: #444; text-decoration: none; 
        border-radius: 12px; transition: 0.2s; text-transform: uppercase; border: none; background: transparent; width: 100%; cursor: pointer;
    }
    .dropdown-item:hover { background: #f8f9fa; color: #b10000; padding-left: 22px; }
    .dropdown-item i { font-size: 1.2rem; }
    .dropdown-item.logout-btn { color: #d32f2f; margin-top: 5px; border-top: 1px solid #f1f4f8; }

    .custom-modal-overlay { display: none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(15, 23, 42, 0.6); backdrop-filter: blur(8px); align-items: center; justify-content: center; }
    .custom-modal-box { background-color: #ffffff; padding: 40px; border-radius: 30px; width: 100%; max-width: 450px; text-align: center; box-shadow: 0 30px 70px rgba(0,0,0,0.3); border: none; }
    .nav-input-field { width: 100%; padding: 14px; margin-bottom: 15px; border-radius: 12px; border: 1px solid #e2e8f0; background: #f8f9fa; font-family: 'Segoe UI', sans-serif; font-weight: 700; outline: none; }

    /* Dark Mode Overrides */
    html.global-dark-mode .custom-gooey-navbar { background-color: #161b22; border-color: #30363d; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5); }
    html.global-dark-mode .navbar-brand h2 { color: #f0f6fc; }
    html.global-dark-mode #navbarSupportedContent ul li a { color: #8b949e; }
    html.global-dark-mode #navbarSupportedContent ul li.active a { color: #ff6b6b; } 

    html.global-dark-mode .hori-selector,
    html.global-dark-mode .hori-selector .left,
    html.global-dark-mode .hori-selector .right { background-color: #0d1117; }
    html.global-dark-mode .hori-selector .left:before,
    html.global-dark-mode .hori-selector .right:before { background-color: #161b22; }

    html.global-dark-mode .profile-toggle { background: #0d1117; border-color: #30363d; }
    html.global-dark-mode .dropdown-menu { background: #161b22; border-color: #30363d; box-shadow: 0 20px 50px rgba(0,0,0,0.6); }
    html.global-dark-mode .dropdown-item { color: #c9d1d9; }
    html.global-dark-mode .dropdown-item:hover { background: #21262d; color: #ff6b6b; }
    html.global-dark-mode .dropdown-item.logout-btn { border-top-color: #30363d; }

    html.global-dark-mode .custom-modal-box { background-color: #161b22; box-shadow: 0 30px 70px rgba(0,0,0,0.6); }
    html.global-dark-mode .nav-input-field { background: #0d1117; border-color: #30363d; color: #f0f6fc; }
    html.global-dark-mode #modalTitle, 
    html.global-dark-mode #modalMessage { color: #f0f6fc; }
    html.global-dark-mode #customConfirmModal button:first-child { background: #21262d !important; color: #c9d1d9 !important; }

    @media (max-width: 1024px) {
        #navbarSupportedContent ul li a span { display: none; }
        #navbarSupportedContent ul li a { padding: 20px 15px; }
        .broadcast-btn span { display: none; }
        .broadcast-btn { padding: 10px; border-radius: 50%; width: 42px; justify-content: center; }
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
        <div class="logo-container"><img src="../uploads/system/DasmAlert.png" alt="Logo" class="brand-logo" onerror="this.src='../assets/default.png'"></div>
        <h2>DASMA ALERT</h2>
    </div>
    
    <div id="navbarSupportedContent">
        <ul>
            <div class="hori-selector">
                <div class="left"></div>
                <div class="right"></div>
            </div>
            <li class="<?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                <a href="dashboard.php" style="position: relative;">
                    <i class='bx bxs-map-alt'></i> <span>DASHBOARD</span>
                    <span id="nav-incident-badge" style="display:none; position:absolute; top:12px; right:8px; background:#d32f2f; color:white; font-size:0.6rem; padding:3px 6px; border-radius:50%; font-weight:bold; box-shadow: 0 2px 5px rgba(0,0,0,0.3);">0</span>
                </a>
            </li>
            <li class="<?php echo $current_page == 'resource_tracking.php' ? 'active' : ''; ?>"><a href="resource_tracking.php"><i class='bx bxs-truck'></i> <span>RESOURCE TRACKING</span></a></li>
            <li class="<?php echo $current_page == 'evacuation_centers.php' ? 'active' : ''; ?>"><a href="evacuation_centers.php"><i class='bx bxs-home-heart'></i> <span>EVACUATION CENTERS</span></a></li>
            <li class="<?php echo $current_page == 'analytics.php' ? 'active' : ''; ?>"><a href="analytics.php"><i class='bx bxs-report'></i> <span>ANALYTICS & REPORTS</span></a></li>
            <?php if ($s_role === 'superadmin'): ?>
            <li class="<?php echo $current_page == 'user_management.php' ? 'active' : ''; ?>"><a href="user_management.php"><i class='bx bxs-group'></i> <span>USER MANAGEMENT</span></a></li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="navbar-actions">
        <?php if ($s_role === 'superadmin'): ?>
        <button onclick="openGlobalBroadcastModal()" class="broadcast-btn"><i class='bx bx-broadcast'></i> <span>BROADCAST</span></button>
        <?php endif; ?>

        <div class="profile-dropdown" id="profileDropdown">
            <div class="profile-toggle" onclick="toggleDropdown(event)">
                <img src="<?php echo $profile_photo; ?>?v=<?php echo time(); ?>" alt="Profile">
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

<script>
function toggleDropdown(event) { 
    event.stopPropagation(); 
    document.getElementById('profileDropdown').classList.toggle('active');
}

window.addEventListener('click', (e) => { 
    const prof = document.getElementById('profileDropdown');
    if (prof && !prof.contains(e.target)) prof.classList.remove('active'); 
});

function updateBlob(activeElement, animate = false) {
    const selector = document.querySelector('.hori-selector');
    if (!activeElement || !selector) return;
    if (!animate) selector.style.transition = 'none';
    else selector.style.transition = 'all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55)';
    selector.style.left = activeElement.offsetLeft + "px";
    selector.style.width = activeElement.offsetWidth + "px";
    selector.style.height = activeElement.offsetHeight + "px";
    selector.style.top = activeElement.offsetTop + "px";
}

document.querySelectorAll('#navbarSupportedContent li').forEach(li => {
    li.addEventListener('click', function(e) {
        const url = this.querySelector('a').getAttribute('href');
        e.preventDefault();
        document.querySelectorAll('#navbarSupportedContent li').forEach(el => el.classList.remove('active'));
        this.classList.add('active');
        updateBlob(this, true);
        setTimeout(() => { window.location.href = url; }, 400);
    });
});

window.addEventListener('load', () => updateBlob(document.querySelector('#navbarSupportedContent li.active'), false));
window.addEventListener('resize', () => { updateBlob(document.querySelector('#navbarSupportedContent li.active'), false); });

let pendingAction = null;
function showCustomModal(type) {
    const modal = document.getElementById('customConfirmModal');
    const confirmBtn = document.getElementById('modalConfirmBtn');
    document.getElementById('profileDropdown').classList.remove('active');

    if (type === 'backup') {
        document.getElementById('modalTitle').innerText = "BACKUP DATABASE";
        document.getElementById('modalMessage').innerText = "STARTING DOWNLOAD NOW?";
        document.getElementById('modalIcon').className = "bx bxs-data";
        document.getElementById('modalIcon').style.color = "#1976d2";
        confirmBtn.style.background = "#1976d2";
        pendingAction = () => window.location.href = 'backup_db.php';
    } else {
        document.getElementById('modalTitle').innerText = "LOGOUT";
        document.getElementById('modalMessage').innerText = "END CURRENT SESSION?";
        document.getElementById('modalIcon').className = "bx bx-log-out-circle";
        document.getElementById('modalIcon').style.color = "#d32f2f";
        confirmBtn.style.background = "#d32f2f";
        pendingAction = () => {
            const f = document.createElement('form'); f.method='POST'; f.action='dashboard.php';
            const i = document.createElement('input'); i.type='hidden'; i.name='logout'; i.value='1';
            f.appendChild(i); document.body.appendChild(f); f.submit();
        };
    }
    modal.style.display = 'flex';
    confirmBtn.onclick = function() { if(pendingAction) pendingAction(); closeCustomModal(); };
}

function closeCustomModal() { document.getElementById('customConfirmModal').style.display = 'none'; }

<?php if ($s_role === 'superadmin'): ?>
function openGlobalBroadcastModal() { document.getElementById('globalBroadcastModal').style.display = 'flex'; }
function closeGlobalBroadcastModal() { document.getElementById('globalBroadcastModal').style.display = 'none'; }
function submitGlobalBroadcast() {
    let fd = new FormData();
    fd.append('action', 'send_broadcast');
    fd.append('title', document.getElementById('globalBroadcastTitle').value);
    fd.append('message', document.getElementById('globalBroadcastMessage').value);
    fd.append('severity', document.getElementById('globalBroadcastSeverity').value);
    fetch('admin_actions.php', { method: 'POST', body: fd }).then(() => location.reload());
}
<?php endif; ?>
</script>
<?php
session_start();
require_once '../php/config.php';
require_once '../php/auth.php';
require_once '../php/ClientManager.php';

if (!isset($auth)) { $auth = new Auth($conn); }

// 🚀 LOGOUT LISTENER
if (isset($_POST['logout']) || isset($_GET['logout'])) {
    $auth->logout();
    session_destroy();
    header('Location: ../php/login.php');
    exit();
}

if (!$auth->isAdmin()) {
    header("Location: ../php/login.php");
    exit();
}

// 🚀 LIVE KPI API ENDPOINT
if (isset($_GET['ajax_kpi'])) {
    header('Content-Type: application/json');
    $brgy      = trim($_GET['ajax_kpi']);
    $like_brgy = '%' . $brgy . '%';

    // 1. Active Incidents & Details
    $s1 = $conn->prepare("SELECT incident_type, barangay FROM incidents WHERE status NOT IN ('archived','rejected','spam') AND (barangay = ? OR barangay LIKE ?)");
    $s1->bind_param("ss", $brgy, $like_brgy); $s1->execute();
    $res1 = $s1->get_result();
    $active_count = $res1->num_rows;
    $active_details = [];
    while($row = $res1->fetch_assoc()) { 
        $active_details[] = "<div style='display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border-color);'><span><b>{$row['incident_type']}</b></span> <span style='font-size:0.7rem; color:var(--text-muted);'>{$row['barangay']}</span></div>"; 
    }
    $s1->close();

    // 🚀 THE FIX: Checks current_incident_id instead of strictly checking status text to catch blank statuses
    $s2 = $conn->prepare("
        SELECT rt.team_name, i.incident_type 
        FROM response_teams rt 
        JOIN incidents i ON rt.current_incident_id = i.id 
        WHERE rt.current_incident_id IS NOT NULL AND rt.current_incident_id > 0
        AND (i.barangay = ? OR i.barangay LIKE ?)
    ");
    $s2->bind_param("ss", $brgy, $like_brgy); $s2->execute();
    $res2 = $s2->get_result();
    $deployed_count = $res2->num_rows;
    $deployed_details = [];
    while($row = $res2->fetch_assoc()) { 
        $deployed_details[] = "<div style='display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border-color);'><span><b>{$row['team_name']}</b></span> <span style='font-size:0.7rem; color:var(--color-info); font-weight:bold;'>{$row['incident_type']}</span></div>"; 
    }
    $s2->close();

    // 3. Evacuees & Details
    $s3 = $conn->prepare("SELECT name, current_occupants FROM evacuation_centers WHERE current_occupants > 0 AND (barangay = ? OR barangay LIKE ?)");
    $s3->bind_param("ss", $brgy, $like_brgy); $s3->execute();
    $res3 = $s3->get_result();
    $evac_count = 0;
    $evac_details = [];
    while($row = $res3->fetch_assoc()) { 
        $evac_count += (int)$row['current_occupants']; 
        $evac_details[] = "<div style='display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border-color);'><span><b>{$row['name']}</b></span> <span style='font-size:0.75rem; color:var(--color-warning); font-weight:bold;'>{$row['current_occupants']} PAX</span></div>"; 
    }
    $s3->close();
    
    echo json_encode([
        'active' => $active_count, 
        'deployed' => $deployed_count, 
        'evac' => $evac_count,
        'active_details' => $active_details,
        'deployed_details' => $deployed_details,
        'evac_details' => $evac_details
    ]);
    exit();
}

// 🚀 ROBUST JURISDICTION FETCH
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT barangay FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$u_data = $stmt->get_result()->fetch_assoc();
$raw_assigned_brgy = trim($u_data['barangay'] ?? '');

$locationAliases = [
    '6XWG+X37' => 'Biga I',
    'MANUELAVILLE' => 'San Agustin II',
    'THE COURTYARDS' => 'Salawag',
    'ORCHARD' => 'Salawag',
    'SUMMERWIND' => 'Burol Main'
];

$assigned_brgy = $raw_assigned_brgy;
if (array_key_exists(strtoupper($raw_assigned_brgy), $locationAliases)) {
    $assigned_brgy = $locationAliases[strtoupper($raw_assigned_brgy)];
}

// BROADCAST LOGIC
$broadcast_result = $conn->query("SELECT * FROM broadcasts WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
$active_broadcast = ($broadcast_result && $broadcast_result->num_rows > 0) ? $broadcast_result->fetch_assoc() : null;
$dismissed_id = isset($_COOKIE['dismissed_broadcast_id']) ? $_COOKIE['dismissed_broadcast_id'] : 0;
$show_banner = ($active_broadcast && $active_broadcast['id'] != $dismissed_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Barangay Command | CDRRMO</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="../css/client/navbar.css">
    <link rel="stylesheet" href="../css/client/dashboard.css">
</head>
<body>

    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <div>
                <h1 style="margin: 0; font-size: 2rem;">Barangay Command</h1>
                <p style="margin-top: 5px; font-weight: 800; color: #666;">
                    Jurisdiction: <span style="color: #d32f2f;"><?php echo htmlspecialchars($assigned_brgy ?: 'Unassigned'); ?></span>
                    <?php if($raw_assigned_brgy !== $assigned_brgy): ?>
                        <small style="color: #999;">(Mapped from: <?php echo htmlspecialchars($raw_assigned_brgy); ?>)</small>
                    <?php endif; ?>
                </p>
            </div>
        </header>

        <?php if ($show_banner): 
            $bg_color = '#1976d2'; 
            if ($active_broadcast['severity'] === 'warning') $bg_color = '#f57c00';
            if ($active_broadcast['severity'] === 'critical') $bg_color = '#d32f2f';
        ?>
            <div id="global-broadcast-banner" data-broadcast-id="<?php echo $active_broadcast['id']; ?>" style="background: <?php echo $bg_color; ?>; color: white; padding: 15px 25px; border-radius: 15px; margin-bottom: 25px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 15px rgba(0,0,0,0.2); position: relative; z-index: 2000;">
                <div>
                    <h3 style="margin: 0 0 5px 0; display: flex; align-items: center; gap: 10px; color: white !important;"><i class='bx bx-broadcast bx-tada'></i> <?php echo htmlspecialchars($active_broadcast['title']); ?></h3>
                    <p style="margin: 0; font-size: 0.95rem; font-weight: 600; color: white !important;"><?php echo nl2br(htmlspecialchars($active_broadcast['message'])); ?></p>
                </div>
                <button onclick="dismissBroadcast(<?php echo $active_broadcast['id']; ?>)" style="background: rgba(0,0,0,0.2); border: none; color: white; padding: 10px 18px; border-radius: 10px; cursor: pointer; font-weight: 800;">Dismiss</button>
            </div>
        <?php endif; ?>

        <div class="kpi-grid">
            <div class="kpi-card red">
                <div class="kpi-header-row"><i class='bx bxs-error-circle' style="font-size:3.5rem; color:#d32f2f;"></i><div class="kpi-card-content"><h3 id="live-kpi-active">0</h3><p>Active Local Incidents</p></div></div>
                <div class="kpi-details-container" id="kpi-active-details"><div style="background:none; border:none; text-align:center; opacity:0.6; padding:10px;">Loading details...</div></div>
            </div>
            
            <div class="kpi-card blue">
                <div class="kpi-header-row"><i class='bx bxs-ambulance' style="font-size:3.5rem; color:#1976d2;"></i><div class="kpi-card-content"><h3 id="live-kpi-deployed">0</h3><p>Responders In-Zone</p></div></div>
                <div class="kpi-details-container" id="kpi-deployed-details"><div style="background:none; border:none; text-align:center; opacity:0.6; padding:10px;">Loading details...</div></div>
            </div>

            <div class="kpi-card green">
                <div class="kpi-header-row"><i class='bx bxs-group' style="font-size:3.5rem; color:#388e3c;"></i><div class="kpi-card-content"><h3 id="live-kpi-evac">0</h3><p>Local Evacuees</p></div></div>
                <div class="kpi-details-container" id="kpi-evacuees-details"><div style="background:none; border:none; text-align:center; opacity:0.6; padding:10px;">Loading details...</div></div>
            </div>

            <div class="kpi-card yellow">
                <div class="kpi-header-row"><i class='bx bxs-cloud' style="font-size: 3.5rem; color: #fbc02d;"></i><div class="kpi-card-content"><h3 id="weather-label">Normal</h3><p id="weather-temp">Weather: 26°C</p></div></div>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="main-col">
                <div class="sitting-panel" style="padding: 20px;">
                    <div class="panel-header">
                        <h2 style="margin: 0; font-size: 1.4rem;"><i class='bx bxs-map-pin' style="color:#d32f2f;"></i> Sector Map</h2>
                    </div>
                    <div id="dasma-map"></div>
                </div>
            </div>

            <div class="side-col">
                <div class="sitting-panel">
                    <div class="panel-header">
                        <h2 style="margin: 0; font-size: 1.4rem;"><i class='bx bx-list-ul' style="color:#1976d2;"></i> Local Incident Feed</h2>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="triage-table">
                            <thead><tr>
                                <th style="width: 15%;">Time</th>
                                <th style="width: 25%;">Location</th>
                                <th style="width: 20%;">Incident Type</th>
                                <th style="width: 10%; text-align: center;">Evidence</th>
                                <th style="width: 15%; text-align: center;">Severity & Status</th>
                                <th style="width: 15%; text-align: center;">Actions</th>
                            </tr></thead>
                            <tbody id="local-incident-table">
                                <tr><td colspan="6" style="text-align:center; padding:40px; color:#888;"><i class='bx bx-loader-alt bx-spin'></i> Synchronizing Sector Data...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div id="verifyModal" class="modal">
            <div class="modal-content" style="max-width: 450px;">
                <div class="close-modal" onclick="closeModal('verifyModal')"><i class='bx bx-x'></i></div>
                <div class="modal-header">
                    <h3 style="margin:0;"><i class='bx bx-check-shield' style="color: #8e24aa;"></i> Verification & Severity</h3>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="verify_incident_ids">
                    <label style="display:block; margin-bottom:10px; font-weight:800; color:#555; text-transform:uppercase; font-size:0.8rem;">Set Severity Level</label>
                    <select id="verify_severity" class="filter-dropdown" style="width:100%; margin-bottom:15px;">
                        <option value="Critical">Critical</option>
                        <option value="Major">Major</option>
                        <option value="Minor">Minor</option>
                        <option value="Info">Info Only</option>
                    </select>
                    <label style="display:block; margin-bottom:10px; font-weight:800; color:#555; text-transform:uppercase; font-size:0.8rem;">Admin Remarks (Optional)</label>
                    <textarea id="verify_remarks" class="filter-dropdown" style="width:100%; height:80px; margin-bottom:20px; resize:none;" placeholder="Enter verification notes..."></textarea>
                    <button class="btn-sm" style="background:#8e24aa; width:100%; padding:14px; font-size:1rem;" onclick="submitVerify()">Update Severity</button>
                </div>
            </div>
        </div>

        <div id="dispatchModal" class="modal">
            <div class="modal-content" style="max-width: 450px;">
                <div class="close-modal" onclick="closeModal('dispatchModal')"><i class='bx bx-x'></i></div>
                <div class="modal-header">
                    <h3 style="margin:0;"><i class='bx bxs-truck' style="color: #388e3c;"></i> Dispatch Units</h3>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="dispatch_incident_id">
                    <p id="dispatch_incident_name" style="font-weight:900; font-size:1.2rem; margin-bottom:20px; color:#d32f2f;"></p>
                    <label style="display:block; margin-bottom:10px; font-weight:800; color:#555; text-transform:uppercase; font-size:0.8rem;">Available Response Teams</label>
                    <div id="available_teams_list" style="max-height:220px; overflow-y:auto; margin-bottom:25px; border:1px solid #edf2f7; border-radius:15px; padding:10px; background:#fdfdfd;">
                        <div style='text-align:center; padding:20px; color:#888;'><i class='bx bx-loader-alt bx-spin'></i> Loading units...</div>
                    </div>
                    <button class="btn-sm" style="background:#388e3c; padding:14px; font-size:1rem;" onclick="submitDispatch()">Deploy Selected Teams</button>
                </div>
            </div>
        </div>

        <div id="evidenceModal" class="modal">
            <div class="modal-content" style="text-align: center; max-width: 600px; padding: 40px 24px 24px;">
                <div class="close-modal" onclick="closeModal('evidenceModal')"><i class='bx bx-x'></i></div>
                <img id="evidenceImageFull" src="" style="max-width: 100%; max-height: 70vh; border-radius: 12px; border: 1px solid var(--border-color); display: inline-block;">
                <div id="evidenceCaption" style="color: var(--text-primary); margin-top: 15px; font-size: 1rem; font-weight: 700; line-height: 1.4;"></div>
            </div>
        </div>

        <div id="universalModal" class="modal">
            <div class="modal-content" style="text-align: center; width: 350px; padding: 40px;">
                <i id="uniModalIcon" class='bx bxs-help-circle' style="font-size: 4rem; margin-bottom: 15px;"></i>
                <h3 id="uniModalTitle" style="margin-bottom: 10px;">Confirm</h3>
                <p id="uniModalText" style="margin-bottom: 25px; color: #888; font-weight: 600;">Message</p>
                <div style="display: flex; gap: 12px;" id="uniModalButtons"></div>
            </div>
        </div>

        <div id="mobileIncidentModal" class="modal">
            <div class="modal-content" style="max-width: 90%; padding: 40px 24px 24px;">
                <div class="close-modal" onclick="closeModal('mobileIncidentModal')"><i class='bx bx-x'></i></div>
                <h3 id="m-incident-title" style="margin-bottom: 16px; font-weight: 900; font-size: 1.3rem; padding-right: 30px; color: var(--text-primary);">Incident Details</h3>
                <div id="m-incident-body" style="display: flex; flex-direction: column;"></div>
            </div>
        </div>
    </main>

<script>
    const assignedBrgy = "<?php echo addslashes($assigned_brgy ?? ''); ?>";
    let map, markerLayer;
    let lastTableHTML = "", lastMapHash = "";
    
    const API_PATH = '../admin/admin_actions.php';

    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    
    function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" class="btn-sm" style="flex: 1; padding: 12px; background: ${color}; justify-content: center; box-shadow: none;">OK</button>`;
        document.getElementById('universalModal').style.display = 'flex';
    }

    function customConfirm(title, message, iconClass, color, confirmCallback) {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" style="flex:1; padding:12px; border-radius:10px; cursor:pointer; border:1px solid #ccc; background:transparent; font-weight:800;">Cancel</button><button id="uniConfirmBtn" class="btn-sm" style="flex: 1; padding: 12px; background: ${color}; justify-content: center;">Proceed</button>`;
        document.getElementById('universalModal').style.display = 'flex';
        document.getElementById('uniConfirmBtn').onclick = function() { closeModal('universalModal'); confirmCallback(); };
    }

    function getIncidentIcon(type) {
        let iconClass = 'bxs-map-pin', iconColor = '#555555'; 
        let t = (type || '').toLowerCase();
        if (t.includes('fire')) { iconClass = 'bxs-flame'; iconColor = '#d32f2f'; } 
        else if (t.includes('accident')) { iconClass = 'bxs-car-crash'; iconColor = '#f57c00'; } 
        else if (t.includes('medical')) { iconClass = 'bx-plus-medical'; iconColor = '#388e3c'; }
        else if (t.includes('rescue')) { iconClass = 'bx-support'; iconColor = '#1976d2'; } 
        else if (t.includes('hazard')) { iconClass = 'bx-error'; iconColor = '#fbc02d'; } 
        else if (t.includes('crime') || t.includes('police')) { iconClass = 'bxs-shield'; iconColor = '#222222'; } 
        return L.divIcon({ html: `<i class='bx ${iconClass}' style='color: ${iconColor}; font-size: 32px;'></i>`, className: 'custom-leaflet-icon', iconSize: [32, 32], iconAnchor: [16, 32] });
    }

    function dismissBroadcast(id) {
        const date = new Date();
        date.setTime(date.getTime() + (24 * 60 * 60 * 1000));
        document.cookie = "dismissed_broadcast_id=" + id + "; expires=" + date.toUTCString() + "; path=/";
        const banner = document.getElementById('global-broadcast-banner');
        if (banner) banner.remove(); 
    }

    let hasAlertedHeat = sessionStorage.getItem('heat_alerted');

    function fetchWeather() {
        fetch('https://api.open-meteo.com/v1/forecast?latitude=14.3294&longitude=120.9368&current=temperature_2m,weather_code&timezone=Asia%2FManila')
            .then(res => res.json())
            .then(w => {
                if(w.current) { 
                    const tempEl = document.getElementById('weather-temp');
                    const labelEl = document.getElementById('weather-label');
                    const currentTemp = Math.round(w.current.temperature_2m);
                    if(tempEl) tempEl.innerText = `Weather: ${currentTemp}°C`; 
                    if(labelEl) {
                        const cardEl = labelEl.closest('.kpi-card');
                        const iconEl = cardEl.querySelector('i');
                        if (currentTemp >= 40) {
                            labelEl.innerText = "Extreme Heat Risk";
                            cardEl.className = "kpi-card red";
                            if(iconEl) { iconEl.className = "bx bxs-hot"; iconEl.style.color = "#d32f2f"; }
                        } 
                        else if (w.current.weather_code > 50) {
                            labelEl.innerText = "Rain / Storm Risk";
                            cardEl.className = "kpi-card blue"; 
                            if(iconEl) { iconEl.className = "bx bxs-cloud-rain"; iconEl.style.color = "#1976d2"; }
                        } 
                        else {
                            labelEl.innerText = "Normal Status";
                            cardEl.className = "kpi-card yellow";
                            if(iconEl) { iconEl.className = "bx bxs-cloud"; iconEl.style.color = "#fbc02d"; }
                        }
                    }
                }
            }).catch(e => console.log("Weather error:", e));
    }

    document.addEventListener('DOMContentLoaded', function() {
        const dasmaBounds = [ [14.2600, 120.9000], [14.3800, 120.9800] ];
        map = L.map('dasma-map', { maxBounds: dasmaBounds, maxBoundsViscosity: 1.0, minZoom: 13 }).setView([14.3294, 120.9368], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
        markerLayer = L.layerGroup().addTo(map);
        fetchWeather();
        fetchLocalData(); 
        setInterval(fetchLocalData, 5000); 
    });

    function fetchLocalData() {
        if (!assignedBrgy) {
            document.getElementById('local-incident-table').innerHTML = '<tr><td colspan="6" style="text-align:center; color:#d32f2f; padding: 40px; font-weight: bold;">No Barangay Assigned to this account.</td></tr>';
            return;
        }
        
        // 1. Fetch Table and Map from admin_actions.php
        fetch(`${API_PATH}?action=master_sync&brgy=${encodeURIComponent(assignedBrgy)}`) 
            .then(async res => {
                if (!res.ok) throw new Error("HTTP Error " + res.status);
                const rawText = await res.text();
                try { return JSON.parse(rawText); } 
                catch (e) { throw new Error("PHP Output was not JSON."); }
            })
            .then(data => {
                if (data.table) {
                    let newTableHTML = data.table;
                    newTableHTML = newTableHTML.replace(/<tr /g, '<tr class="clickable-row" onclick="openMobileModal(this)" ');
                    newTableHTML = newTableHTML.replace(/<td /g, '<td ');
                    
                    if (newTableHTML !== lastTableHTML) {
                        document.getElementById('local-incident-table').innerHTML = newTableHTML;
                        document.querySelectorAll('#local-incident-table tr.clickable-row').forEach(row => {
                            let firstCell = row.querySelector('td:first-child');
                            if(firstCell && !firstCell.querySelector('.mobile-expand-icon') && !row.innerHTML.includes('No active reports')) {
                                firstCell.innerHTML += "<i class='bx bx-chevron-right mobile-expand-icon'></i>";
                            }
                        });
                        lastTableHTML = newTableHTML;
                    }
                }
                if (data.map && JSON.stringify(data.map) !== lastMapHash) {
                    markerLayer.clearLayers();
                    data.map.forEach(inc => {
                        L.marker([inc.latitude, inc.longitude], { icon: getIncidentIcon(inc.incident_type) })
                         .addTo(markerLayer).bindPopup(`<b>${inc.incident_type}</b><br>${inc.barangay}`);
                    });
                    lastMapHash = JSON.stringify(data.map);
                }
            })
            .catch(err => { if (err.message !== "PHP Output was not JSON.") console.error("Sync Error:", err); });

        // 🚀 KPI AJAX FETCH
        fetch(`dashboard.php?ajax_kpi=${encodeURIComponent(assignedBrgy)}`)
            .then(async res => {
                if(!res.ok) throw new Error("KPI Network Error");
                return res.json();
            })
            .then(data => {
                document.getElementById('live-kpi-active').innerText = data.active;
                document.getElementById('live-kpi-deployed').innerText = data.deployed;
                document.getElementById('live-kpi-evac').innerText = data.evac;

                const actDet = document.getElementById('kpi-active-details');
                if(actDet) actDet.innerHTML = data.active_details.length ? data.active_details.join('') : '<div style="background:none; border:none; text-align:center; opacity:0.6; padding:10px;">All clear. No active incidents.</div>';
                
                const depDet = document.getElementById('kpi-deployed-details');
                if(depDet) depDet.innerHTML = data.deployed_details.length ? data.deployed_details.join('') : '<div style="background:none; border:none; text-align:center; opacity:0.6; padding:10px;">No response teams active.</div>';
                
                const evacDet = document.getElementById('kpi-evacuees-details');
                if(evacDet) evacDet.innerHTML = data.evac_details.length ? data.evac_details.join('') : '<div style="background:none; border:none; text-align:center; opacity:0.6; padding:10px;">All evacuation centers empty.</div>';
            }).catch(e => console.log("KPI Sync Error:", e));
    }

    function toggleVerifyDropdown(btn) {
        const wrapper = btn.closest('.verify-btn-wrapper');
        const dropdown = wrapper.querySelector('.verify-dropdown');
        const isHidden = dropdown.style.display === 'none';
        
        document.querySelectorAll('.verify-dropdown').forEach(d => d.style.display = 'none');
        dropdown.style.display = isHidden ? 'block' : 'none';
    }

    function hideVerifyDropdown(btn) { btn.closest('.verify-dropdown').style.display = 'none'; }

    document.addEventListener('click', function(event) {
        if (event.target.classList.contains('confirm-verify-btn')) {
            const ids = event.target.getAttribute('data-confirm-ids');
            const fd = new FormData();
            fd.append('action', 'confirm_verify');
            fd.append('incident_id', ids);

            fetch(API_PATH, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => { if (d.success) fetchLocalData(); })
                .catch(e => console.error(e));
        }
    });

    function openVerifyModal(ids) {
        document.getElementById('verify_incident_ids').value = ids;
        document.getElementById('verifyModal').style.display = 'flex';
    }

    function submitVerify() {
        let ids = document.getElementById('verify_incident_ids').value;
        let severity = document.getElementById('verify_severity').value;
        let remarks = document.getElementById('verify_remarks').value;
        let fd = new FormData();
        fd.append('action', 'verify_incident');
        fd.append('incident_id', ids);
        fd.append('severity', severity);
        fd.append('remarks', remarks);
        fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.text()).then(d=>{ closeModal('verifyModal'); fetchLocalData(); });
    }

    function openDeployModal(id, name) {
        document.getElementById('dispatch_incident_id').value = id; 
        document.getElementById('dispatch_incident_name').innerText = "Target: " + name;
        document.getElementById('available_teams_list').innerHTML = "<div style='text-align:center; padding:10px; color:#888;'><i class='bx bx-loader-alt bx-spin'></i> Loading units...</div>";
        document.getElementById('dispatchModal').style.display = 'flex';
        fetch(API_PATH + "?action=get_available_teams&incident_type=" + encodeURIComponent(name))
            .then(r=>r.json())
            .then(data => {
                let html = '';
                if(data.length === 0) { html = "<div style='text-align:center; color:#d32f2f; font-weight:bold; padding: 15px;'>No units currently available.</div>"; } 
                else {
                    data.forEach(t => {
                        let recBadge = t.is_recommended ? `<span style="background:#388e3c; color:white; padding: 2px 8px; border-radius: 8px; font-size: 0.65rem; font-weight: 900; margin-left: 8px;">⭐ RECOMMENDED</span>` : "";
                        let bgStyle = t.is_recommended ? "background: #f1f8e9; border-left: 4px solid #388e3c;" : "";
                        html += `<label style="display:flex; align-items:center; gap:12px; padding:12px; border-bottom:1px solid #edf2f7; cursor:pointer; ${bgStyle}">
                            <input type="checkbox" class="dispatch-team-cb" value="${t.id}" data-name="${t.team_name}" style="transform: scale(1.3);">
                            <span style="font-size:1.1rem; color:#333;"><b>${t.team_name}</b> <small style="color:#888;">(${t.team_type})</small> ${recBadge}<br><span style="color:#1976d2; font-size:0.8rem; font-weight:bold;">📍 ${t.assigned_barangay || 'City-Wide'}</span></span>
                        </label>`;
                    });
                }
                document.getElementById('available_teams_list').innerHTML = html;
            });
    }

    function submitDispatch() {
        let ids = document.getElementById('dispatch_incident_id').value;
        let cbs = document.querySelectorAll('.dispatch-team-cb:checked');
        if(cbs.length === 0) return customAlert("Selection Required", "Select units to deploy.", "bx-error", "#d32f2f");
        let teamIds = []; let teamNames = [];
        cbs.forEach(cb => { teamIds.push(cb.value); teamNames.push(cb.getAttribute('data-name')); });
        closeModal('dispatchModal');
        customConfirm("Confirm Dispatch", `Deploy ${teamNames.length} unit(s)?`, "bxs-truck", "#388e3c", function() {
            let fd = new FormData();
            fd.append('action', 'deploy_team');
            fd.append('incident_id', ids); 
            fd.append('team_ids', JSON.stringify(teamIds));
            fd.append('team_names', teamNames.join(", "));
            fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{ if(d.success) fetchLocalData(); else customAlert("Error", d.message, "bx-error", "#d32f2f"); });
        });
    }

    function cancelDispatch(ids) {
        customConfirm("Recall Units", "Recall units and revert status?", "bx-undo", "#d32f2f", function() {
            let fd = new FormData(); fd.append('action', 'cancel_dispatch'); fd.append('id', ids);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.text()).then(d=>fetchLocalData());
        });
    }

    function resolveIncident(id) {
        customConfirm("Mark Resolved?", "Close incident and recall units?", "bx-check-shield", "#388e3c", function() {
            let fd = new FormData(); fd.append('action', 'admin_resolve_incident'); fd.append('incident_id', id);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (d.success) fetchLocalData(); }).catch(e => fetchLocalData());
        });
    }

    function viewEvidence(imagePath, incidentType, brgy, date, time, reporter, logs, extra, backupRequested) { 
        const imgEl = document.getElementById('evidenceImageFull');
        if (imagePath && imagePath !== 'NULL' && imagePath !== '') {
            let cleanPath = imagePath.startsWith('/') ? imagePath.substring(1) : imagePath;
            imgEl.src = '/dasma_api/' + cleanPath;
            imgEl.style.display = 'inline-block';
        } else { imgEl.style.display = 'none'; }
        
        document.getElementById('evidenceCaption').innerHTML = `
            <div style="font-weight: 900; font-size: 1.1rem; margin-bottom: 5px;">${incidentType} in Brgy. ${brgy}</div>
            <div style="font-size: 0.85rem; font-weight: 800; margin-bottom: 5px;">Reported by ${reporter} at ${time}</div>
            <div style="font-style: italic; font-size: 0.95rem;">"${logs}"</div>
        `;
        document.getElementById('evidenceModal').style.display = 'flex'; 
        
        if (backupRequested == 1) { setTimeout(() => { customAlert("🚨 URGENT: BACKUP REQUESTED 🚨", "Immediate assistance requested!", "bxs-error", "#d32f2f"); }, 300); }
    }

    function rejectIncident(id) {
        customConfirm("Reject Incident?", "Is this a false alarm?", "bx-x-circle", "#555555", function() {
            let fd = new FormData(); fd.append('action', 'reject_incident'); fd.append('incident_id', id);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (d.success) fetchLocalData(); });
        });
    }

    function requestBackup(id) {
        customConfirm("Request Backup?", "Alert City Superadmin?", "bxs-error-circle", "#f57c00", function() {
            let fd = new FormData(); fd.append('action', 'request_backup'); fd.append('incident_id', id);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (d.success) fetchLocalData(); });
        });
    }

    document.querySelectorAll('.kpi-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const isExpanded = this.classList.contains('mobile-expanded');
                document.querySelectorAll('.kpi-card').forEach(c => c.classList.remove('mobile-expanded'));
                if (!isExpanded) this.classList.add('mobile-expanded');
            }
        });
    });

    function openMobileModal(row) {
        if (window.innerWidth > 768) return; 
        
        const cells = row.querySelectorAll('td');
        if (cells.length < 6) return;

        const bodyEl = document.getElementById('m-incident-body');
        
        let html = '';
        html += `<div class="mobile-detail-box"><small class="mobile-label">Time</small>${cells[0].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Location</small>${cells[1].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Incident Type</small>${cells[2].innerHTML}</div>`;
        html += `<div class="mobile-detail-box" style="text-align: center;"><small class="mobile-label">Evidence</small>${cells[3].innerHTML}</div>`;
        html += `<div class="mobile-detail-box" style="text-align: center;"><small class="mobile-label">Severity & Status</small>${cells[4].innerHTML}</div>`;
        html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; flex-direction:column; gap:10px; width:100%;">${cells[5].innerHTML}</div></div>`;

        bodyEl.innerHTML = html;
        
        let actionContainer = bodyEl.querySelector('.m-actions-container');
        if (actionContainer) {
            let actionBtnContainer = actionContainer.querySelector('.action-btn-container');
            if (actionBtnContainer) actionBtnContainer.style.width = '100%';
            
            actionContainer.querySelectorAll('button').forEach(b => { 
                b.style.width = '100%'; b.style.justifyContent = 'center'; 
            });
            
            let verifyWrapper = actionContainer.querySelector('.verify-btn-wrapper');
            if(verifyWrapper) verifyWrapper.style.width = '100%';
        }

        bodyEl.querySelectorAll('.mobile-expand-icon').forEach(icon => icon.style.display = 'none');
        document.getElementById('mobileIncidentModal').style.display = 'flex';
    }
</script>
</body>
</html>
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
    window.soundEnabled = <?= ($sound_saved === 1) ? 'true' : 'false' ?>;
</script>
<script src="../js/client/dashboard.js?v=<?= filemtime('../js/client/dashboard.js') ?>"></script>
</body>
</html>
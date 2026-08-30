<?php
session_start();
require_once '../php/config.php';
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) { $auth = new Auth($conn); }

if (!$auth->is_logged_in()) {
    header("Location: ../php/login.php");
    exit();
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// --- GET LOCAL BARANGAY ---
// PATCH: prepared stmt for user_id
$u_stmt = $conn->prepare("SELECT barangay FROM users WHERE id = ?");
$u_stmt->bind_param("i",$user_id);
$u_stmt->execute();
$u_data = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();
$my_brgy = $u_data['barangay'] ?? '';
$safe_brgy = $conn->real_escape_string($my_brgy);

// 🚀 THE FIX: Geofence the database queries based on the Admin's jurisdiction
$team_filter = "";
if ($role === 'admin' || $role === 'barangay_admin') {
    $team_filter = " AND (
        TRIM(assigned_barangay) = '$safe_brgy' 
        OR LOWER(TRIM(assigned_barangay)) = 'city-wide' 
        OR assigned_barangay IS NULL 
        OR TRIM(assigned_barangay) = ''
    )";
}

// --- FETCH KPI DATA (Now accurately filtered for Local Admins) ---
$res_total = $conn->query("SELECT COUNT(*) as count FROM response_teams WHERE 1=1 $team_filter");
$total_teams = $res_total->fetch_assoc()['count'] ?? 0;

$res_avail = $conn->query("SELECT COUNT(*) as count FROM response_teams WHERE LOWER(status) = 'available' $team_filter");
$avail_teams = $res_avail->fetch_assoc()['count'] ?? 0;

// 🚀 THE FIX: Catch 'deployed', 'on-scene', and 'dispatched' statuses
$res_dep = $conn->query("SELECT COUNT(*) as count FROM response_teams WHERE LOWER(status) IN ('deployed', 'on-scene', 'dispatched') $team_filter");
$dep_teams = $res_dep->fetch_assoc()['count'] ?? 0;

$res_maint = $conn->query("SELECT COUNT(*) as count FROM response_teams WHERE LOWER(status) = 'maintenance' $team_filter");
$maint_teams = $res_maint->fetch_assoc()['count'] ?? 0;

// --- FETCH ALL TEAMS FOR THE TABLE AND HOVER LISTS ---
$teams_query = "SELECT * FROM response_teams WHERE 1=1 $team_filter ORDER BY status ASC, team_type ASC";
$teams_result = $conn->query($teams_query);
$teams = [];
$avail_list = [];
$dep_list = [];
$maint_list = [];

if ($teams_result && $teams_result->num_rows > 0) {
    $teams = $teams_result->fetch_all(MYSQLI_ASSOC);
    foreach($teams as $t) {
        $stat = strtolower($t['status']);
        if($stat === 'available') $avail_list[] = $t;
        if($stat === 'deployed' || $stat === 'on-scene' || $stat === 'dispatched') $dep_list[] = $t;
        if($stat === 'maintenance') $maint_list[] = $t;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Resource Tracking | Command Center</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: 'Segoe UI', sans-serif; 
            background-color: #eef2f7; 
            color: #333; 
            transition: 0.3s; 
        }
        
        .main-content { 
            margin-left: 0 !important; 
            width: 100% !important; 
            margin-top: 95px !important; 
            padding: 10px 25px !important; 
            min-height: 100vh; 
        }

        .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 25px; margin-top: 10px; margin-bottom: 35px; align-items: start; }
        
        .kpi-card { 
            background: #ffffff; padding: 25px; border-radius: 20px; 
            display: flex; flex-direction: column; 
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1), 0 5px 15px rgba(0, 0, 0, 0.05); 
            border: none; border-top: 5px solid #ccc; 
            position: relative; 
            transition: all 0.3s ease;
            z-index: 10;
        }
        .kpi-card:hover { transform: translateY(-5px); z-index: 1001; }
        .kpi-card.blue { border-top-color: #1976d2; } .kpi-card.green { border-top-color: #388e3c; } .kpi-card.red { border-top-color: #d32f2f; } .kpi-card.gray { border-top-color: #777; }

        .kpi-header-row { display: flex; align-items: flex-start; gap: 15px; width: 100%; }
        .kpi-card-content { flex: 1; }
        .kpi-card h3 { color: #222; font-size: 1.8rem; font-weight: 900; line-height: 1.1; margin:0; } 
        .kpi-card p { color: #666; font-weight: 700; font-size: 0.85rem; text-transform: uppercase; margin-bottom: 5px; margin-top:0;}
        
        .kpi-details-container {
            position: absolute;
            top: 90%; 
            left: 0;
            right: 0;
            background: #ffffff;
            border-radius: 0 0 20px 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.15);
            max-height: 0;
            overflow-y: auto;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 0 20px;
            border: 1px solid #edf2f7;
            border-top: none;
            pointer-events: none; 
        }

        .kpi-card:hover .kpi-details-container {
            max-height: 280px; opacity: 1; visibility: visible; top: 100%; 
            padding: 15px 20px 20px 20px; pointer-events: auto;
        }

        .kpi-details-container div { background: #f8f9fa; padding: 10px 12px; border-radius: 12px; margin-bottom: 8px; border: 1px solid #edf2f7; font-size: 0.8rem; color: #444; font-weight: 600; }

        .table-container { 
            background: #ffffff; padding: 35px; border-radius: 30px; 
            box-shadow: 0 30px 60px -20px rgba(0,0,0,0.15), 0 15px 30px -10px rgba(0,0,0,0.05);
            border: 1px solid rgba(255,255,255,0.8);
        }

        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        .header-flex h2 { font-weight: 900; color: #333; letter-spacing: -0.5px; }

        .table-wrapper { background: #f8f9fa; border-radius: 20px; border: 1px solid #edf2f7; overflow: hidden; }

        .data-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .data-table th, .data-table td { padding: 18px 25px; text-align: left; border-bottom: 1px solid #f1f4f8; }
        .data-table th { background: #ffffff; position: sticky; top: 0; color: #888; font-weight: 800; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px; }
        .data-table td { font-weight: 600; color: #333; }
        
        .clickable-row { cursor: pointer; transition: background 0.2s; }
        .clickable-row:hover { background: #eef2f7; }
        
        .badge { padding: 8px 14px; border-radius: 12px; font-size: 0.7rem; font-weight: 900; color: white; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge.available { background: linear-gradient(135deg, #11998e, #388e3c); }
        .badge.deployed, .badge.on-scene { background: linear-gradient(135deg, #ff4b2b, #d32f2f); }
        .badge.maintenance { background: linear-gradient(135deg, #f89b29, #f57c00); }

        .btn-sm { 
            padding: 10px 18px; border: none; border-radius: 12px; cursor: pointer; 
            font-size: 0.8rem; font-weight: 800; transition: 0.3s; color: white; 
            display: inline-flex; align-items: center; gap: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }
        .btn-sm:hover { transform: translateY(-3px); box-shadow: 0 8px 15px rgba(0,0,0,0.15); }
        .btn-add { background: #228b22; padding: 12px 22px; font-size: 0.9rem; }

        .modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.6); align-items: center; justify-content: center; backdrop-filter: blur(8px); }
        .modal-content { background: #ffffff; padding: 40px; border-radius: 35px; width: 100%; max-width: 480px; box-shadow: 0 50px 100px -20px rgba(0,0,0,0.3); position: relative; border: none; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .modal-header h3 { font-weight: 900; font-size: 1.5rem; }
        /* 🚀 GLOBAL CLOSE BUTTON STYLING (Transparent Design) */
        .close-modal {
            position: absolute; 
            top: 18px; 
            right: 18px; 
            width: 30px; 
            height: 30px; 
            background: transparent; 
            border: none;
            display: flex; 
            align-items: center; 
            justify-content: center;
            font-size: 1.8rem; 
            color: #888; 
            cursor: pointer; 
            z-index: 1000;
            transition: 0.2s;
        }
        .close-modal:hover { color: #d32f2f; transform: scale(1.1); }
        
        .modal-cancel-btn { flex: 1; padding: 12px; border-radius: 12px; cursor: pointer; border: 1px solid #ddd; background: transparent; font-weight: 800; color: #333; transition: 0.2s; }
        .modal-cancel-btn:hover { background: #f8f9fa; }
        .modal-input, .modal-select { width: 100%; padding: 14px; margin-bottom: 25px; border-radius: 14px; border: 1px solid #e2e8f0; background: #f8f9fa; font-size: 1rem; font-weight: 700; color: #333; outline: none; transition: 0.3s; }
        .modal-input:focus, .modal-select:focus { border-color: #1976d2; background: #fff; box-shadow: 0 0 0 4px rgba(25, 118, 210, 0.1); }

        /* DARK MODE SYNC */
        html.global-dark-mode body, html.global-dark-mode .main-content { background: #0b0e14 !important; }
        html.global-dark-mode .kpi-card, html.global-dark-mode .table-container, html.global-dark-mode .modal-content { background: #161b22 !important; box-shadow: 0 30px 60px rgba(0,0,0,0.5) !important; border: 1px solid #30363d !important; }
        html.global-dark-mode .table-wrapper { background: #0d1117 !important; border-color: #30363d !important; }
        html.global-dark-mode .data-table th { background: #161b22 !important; color: #8b949e !important; border-bottom: 2px solid #30363d; }
        html.global-dark-mode .data-table td { color: #c9d1d9 !important; border-bottom-color: #21262d; }
        html.global-dark-mode .clickable-row:hover { background: #21262d !important; }
        html.global-dark-mode h1, html.global-dark-mode h2, html.global-dark-mode h3, html.global-dark-mode p, html.global-dark-mode label { color: #f0f6fc !important; }
        html.global-dark-mode .modal-cancel-btn { color: white; border-color: #444; }
        html.global-dark-mode .modal-cancel-btn:hover { background: #2a2e35; }
        html.global-dark-mode .modal-input, html.global-dark-mode .modal-select { background: #0d1117; color: white; border-color: #30363d; }
        html.global-dark-mode .kpi-details-container { background: #1c2128; border-color: #30363d; }
        html.global-dark-mode .kpi-details-container div { background: #0d1117; border-color: #30363d; color: #c9d1d9; }
        html.global-dark-mode .close-modal { color: #f0f6fc; }
        html.global-dark-mode .close-modal:hover { color: #ef4444; }

        /* 🚀 MOBILE RESPONSIVE OVERRIDES */
        .mobile-expand-icon { display: none; }
        .mobile-label { display: none; }
        .mobile-detail-box { background: #f8f9fa; padding: 16px; border-radius: 14px; border: 1px solid #edf2f7; margin-bottom: 12px; }
        html.global-dark-mode .mobile-detail-box { background: #0d1117; border-color: #30363d; }

        @media (max-width: 768px) {
            .main-content { padding: 15px 15px 30px 15px !important; margin-top: 110px !important; }
            .header-flex { flex-direction: column; align-items: stretch; gap: 15px; }
            .btn-add { width: 100%; justify-content: center; }
            
            /* KPI Adjustments */
            .kpi-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
            .kpi-card { padding: 12px; cursor: pointer; }
            .kpi-header-row { flex-direction: column; align-items: flex-start; gap: 8px; }
            .kpi-card h3 { font-size: 1.2rem; }
            .kpi-card p { font-size: 0.65rem; white-space: normal; line-height: 1.2; }
            .kpi-card:hover .kpi-details-container { max-height: 0; opacity: 0; visibility: hidden; padding: 0 20px; pointer-events: none; }
            .kpi-card.mobile-expanded { z-index: 1005; }
            .kpi-card.mobile-expanded .kpi-details-container { max-height: 300px; opacity: 1; visibility: visible; top: 100%; padding: 12px; pointer-events: auto; }

            /* Table Box Conversion */
            .table-container { padding: 20px 15px; }
            .table-wrapper { border: none !important; background: transparent !important; }
            .data-table, .data-table tbody { display: block; width: 100%; min-width: 100% !important; }
            .data-table thead { display: none; }
            
            .data-table tr.clickable-row {
                display: block; background: #ffffff; border: 1px solid #edf2f7; 
                border-radius: 16px; margin-bottom: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.03);
                cursor: pointer; position: relative; transition: transform 0.2s, background 0.2s;
            }
            .data-table tr.clickable-row:active { transform: scale(0.98); background: #f8f9fa; }
            html.global-dark-mode .data-table tr.clickable-row { background: #0d1117 !important; border-color: #30363d !important; }
            html.global-dark-mode .data-table tr.clickable-row:active { background: #21262d !important; }

            .data-table td:nth-child(n+2) { display: none; }
            .data-table td { display: block; width: 100%; padding: 18px 15px !important; border: none !important; }
            .data-table td > strong { padding-right: 35px; display: block; font-size: 1.1rem;}

            .mobile-expand-icon { display: block; position: absolute; right: 15px; top: 20px; color: #888; font-size: 1.5rem; }
            .mobile-label { display: block; font-size: 0.65rem; font-weight: 800; color: #888; margin-bottom: 6px; letter-spacing: 0.05em; text-transform: uppercase; }

            .close-modal {
                top: 12px !important; right: 12px !important;
                background: rgba(0, 0, 0, 0.6) !important; color: #fff !important;
                border: 1px solid rgba(255,255,255,0.2) !important;
            }
            .modal-content { padding: 24px; }
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header style="margin-bottom: 35px;">
            <h1 style="color: #333; margin: 0; font-weight: 900; letter-spacing: -1px; font-size: 2.2rem;">City Resource Tracking</h1>
        </header>

        <div class="kpi-grid">
            <div class="kpi-card blue">
                <div class="kpi-header-row">
                    <i class='bx bxs-truck' style="font-size:3.5rem; color:#1976d2;"></i>
                    <div class="kpi-card-content">
                        <h3><?php echo $total_teams; ?></h3><p>Total Units</p>
                    </div>
                </div>
                <div class="kpi-details-container">
                    <?php if(empty($teams)): ?><div style="background:none; border:none; text-align:center; opacity:0.6;">No units found.</div>
                    <?php else: foreach($teams as $t): ?>
                        <div><b><?php echo htmlspecialchars($t['team_name']); ?></b> • <?php echo htmlspecialchars($t['team_type']); ?></div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="kpi-card green">
                <div class="kpi-header-row">
                    <i class='bx bxs-check-shield' style="font-size:3.5rem; color:#388e3c;"></i>
                    <div class="kpi-card-content">
                        <h3><?php echo $avail_teams; ?></h3><p>Available</p>
                    </div>
                </div>
                <div class="kpi-details-container">
                    <?php if(empty($avail_list)): ?><div style="background:none; border:none; text-align:center; opacity:0.6;">No units available.</div>
                    <?php else: foreach($avail_list as $t): ?>
                        <div><b><?php echo htmlspecialchars($t['team_name']); ?></b> • <?php echo htmlspecialchars($t['team_type']); ?></div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="kpi-card red">
                <div class="kpi-header-row">
                    <i class='bx bxs-alarm-exclamation' style="font-size:3.5rem; color:#d32f2f;"></i>
                    <div class="kpi-card-content">
                        <h3><?php echo $dep_teams; ?></h3><p>Deployed (Local)</p>
                    </div>
                </div>
                <div class="kpi-details-container">
                    <?php if(empty($dep_list)): ?><div style="background:none; border:none; text-align:center; opacity:0.6;">No deployed units.</div>
                    <?php else: foreach($dep_list as $t): ?>
                        <div><b><?php echo htmlspecialchars($t['team_name']); ?></b> • <?php echo htmlspecialchars($t['team_type']); ?></div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="kpi-card gray">
                <div class="kpi-header-row">
                    <i class='bx bxs-wrench' style="font-size: 3.5rem; color: #777;"></i>
                    <div class="kpi-card-content">
                        <h3><?php echo $maint_teams; ?></h3><p>In Maintenance</p>
                    </div>
                </div>
                <div class="kpi-details-container">
                    <?php if(empty($maint_list)): ?><div style="background:none; border:none; text-align:center; opacity:0.6;">No units in maintenance.</div>
                    <?php else: foreach($maint_list as $t): ?>
                        <div><b><?php echo htmlspecialchars($t['team_name']); ?></b> • <?php echo htmlspecialchars($t['team_type']); ?></div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>

        <div class="table-container">
            <div class="header-flex">
                <h2 style="margin: 0;">Response Unit Directory</h2>
                </div>
            
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Unit Name</th>
                            <th>Unit Type</th>
                            <th>Status</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($teams)): ?>
                            <tr><td colspan="4" style="text-align: center; padding: 60px; color: #777;">No response teams found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($teams as $team): 
                                $type_icon = 'bx-car';
                                $t_lower = strtolower($team['team_type']);
                                if(strpos($t_lower, 'medic') !== false) $type_icon = 'bx-plus-medical';
                                elseif(strpos($t_lower, 'fire') !== false) $type_icon = 'bxs-flame';
                                elseif(strpos($t_lower, 'police') !== false) $type_icon = 'bxs-badge-check';
                                elseif(strpos($t_lower, 'rescue') !== false) $type_icon = 'bxs-ambulance';
                            ?>
                             <tr class="clickable-row" onclick="viewTeamMembers(<?php echo $team['id']; ?>, '<?php echo addslashes($team['team_name']); ?>')">
                                <td><strong><?php echo htmlspecialchars($team['team_name']); ?></strong> <i class='bx bx-chevron-right mobile-expand-icon'></i></td>
                                <td><i class='bx <?php echo $type_icon; ?>' style="font-size: 1.2rem; vertical-align: middle; margin-right: 8px; opacity: 0.7;"></i> <?php echo htmlspecialchars($team['team_type']); ?></td>
                                <td><span class="badge <?php echo $team['status']; ?>"><?php echo strtoupper($team['status']); ?></span></td>
                                
                                <td style="text-align: center;" onclick="event.stopPropagation();">
                                    <?php if (strtolower($team['status']) === 'available'): ?>
                                        <button class="btn-sm" style="background: #f57c00; margin: 0 auto;" onclick="updateStatus(<?php echo $team['id']; ?>, 'maintenance')">
                                            <i class='bx bxs-wrench'></i> Maintenance
                                        </button>
                                    <?php elseif (strtolower($team['status']) === 'maintenance'): ?>
                                        <button class="btn-sm" style="background: #388e3c; margin: 0 auto;" onclick="updateStatus(<?php echo $team['id']; ?>, 'available')">
                                            <i class='bx bx-check-circle'></i> Done
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #888; font-size: 0.8rem; font-weight: bold; font-style: italic;">Deployed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="addUnitModal" class="modal">
        <div class="modal-content" style="position: relative;">
            <div class="close-modal" onclick="closeModal('addUnitModal')"><i class='bx bx-x'></i></div>
            <div class="modal-header">
                <h3 style="margin: 0;"><i class='bx bx-plus-circle' style="color: #228b22;"></i> Register Unit</h3>
            </div>
            <div class="modal-body">
                <label style="font-weight: 800; color: #555; display: block; margin-bottom: 8px;">Unit Name:</label>
                <input type="text" id="new_team_name" class="modal-input" placeholder="e.g. Medic 1">
                
                <label style="font-weight: 800; color: #555; display: block; margin-bottom: 8px;">Unit Type:</label>
                <select id="new_team_type" class="modal-select">
                    <option value="Medic">Medic</option>
                    <option value="Fire">Fire</option>
                    <option value="Rescue">Rescue</option>
                    <option value="Police">Police</option>
                </select>
                
                <div style="display: flex; gap: 15px; margin-top: 10px;">
                    <button class="modal-cancel-btn" onclick="closeModal('addUnitModal')">Cancel</button>
                    <button class="btn-sm btn-add" onclick="submitNewUnit()" style="flex: 2; justify-content: center;">Register Unit</button>
                </div>
            </div>
        </div>
    </div>

    <div id="teamMembersModal" class="modal">
        <div class="modal-content" style="max-width: 450px; position: relative;">
            <div class="close-modal" onclick="closeModal('teamMembersModal')"><i class='bx bx-x'></i></div>
            <div class="modal-header">
                <h3 style="margin: 0;"><i class='bx bxs-group' style="color: #1976d2;"></i> <span id="tm_title">Unit Personnel</span></h3>
            </div>
            <div class="modal-body" id="tm_content" style="max-height: 350px; overflow-y: auto;">
                <div style="text-align:center; padding: 20px; opacity:0.6;"><i class="bx bx-loader-alt bx-spin"></i> Loading personnel...</div>
            </div>
        </div>
    </div>

    <!-- Mobile Unit Details Modal -->
    <div id="mobileUnitModal" class="modal" style="z-index: 10005;">
        <div class="modal-content" style="max-width: 90%; padding: 24px; position: relative;">
            <div class="close-modal" onclick="closeModal('mobileUnitModal')"><i class='bx bx-x'></i></div>
            <h3 id="m-unit-title" style="margin-bottom: 16px; font-weight: 900; font-size: 1.3rem; padding-right: 30px; color: var(--text-primary);">Unit Details</h3>
            <div id="m-unit-body" style="display: flex; flex-direction: column;"></div>
        </div>
    </div>
                                        
    <script>
    let currentDeployTeamId = null;
    let currentDeployTeamName = ""; 

    function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" class="btn-sm" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">OK</button>`;
        document.getElementById('universalModal').style.display = 'flex';
    }

    function customConfirm(title, message, iconClass, color, confirmCallback) {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        let cancelBtn = `<button onclick="closeModal('universalModal')" class="modal-cancel-btn" style="height: 50px;">Cancel</button>`;
        let confirmBtn = `<button id="uniConfirmBtn" class="btn-sm" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">Proceed</button>`;
        document.getElementById('uniModalButtons').innerHTML = cancelBtn + confirmBtn;
        document.getElementById('universalModal').style.display = 'flex';
        document.getElementById('uniConfirmBtn').onclick = function() { closeModal('universalModal'); confirmCallback(); };
    }

    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    function handleServerResponse(fetchPromise) {
        fetchPromise.then(res => res.text()).then(text => {
            let data = text.trim();
            if (!data) throw new Error("Empty response from server.");
            
            if (data.startsWith('{')) { 
                let json = JSON.parse(data);
                if (json.success) location.reload();
                else customAlert("Error", json.message || json.error || "Action failed.", "bx-x-circle", "#d32f2f");
            } else { 
                if (data === 'success') location.reload();
                else customAlert("Server Alert", data, "bx-info-circle", "#f57c00");
            }
        }).catch(err => {
            customAlert("System Error", err.toString(), "bx-error", "#d32f2f");
        });
    }

    function openAddUnitModal() {
        document.getElementById('new_team_name').value = "";
        document.getElementById('addUnitModal').style.display = 'flex';
    }

    function submitNewUnit() {
        let name = document.getElementById('new_team_name').value.trim();
        let type = document.getElementById('new_team_type').value;
        
        if(!name) return customAlert("Missing Name", "Please enter a name for the unit.", "bx-error-circle", "#d32f2f");
        
        customConfirm("Register Unit?", `Are you sure you want to officially register ${name} as a ${type} unit?`, "bx-check-shield", "#228b22", function() {
            let formData = new FormData();
            formData.append('action', 'add_team'); 
            formData.append('team_name', name); 
            formData.append('team_type', type);
            
            handleServerResponse(fetch('../admin/admin_actions.php', { method: 'POST', body: formData }));
        });
    }

    function viewTeamMembers(teamId, teamName) {
        document.getElementById('tm_title').innerText = teamName;
        document.getElementById('tm_content').innerHTML = '<div style="text-align:center; padding: 20px; opacity:0.6;"><i class="bx bx-loader-alt bx-spin"></i> Loading...</div>';
        document.getElementById('teamMembersModal').style.display = 'flex';

        fetch(`../admin/admin_actions.php?action=get_team_members&team_id=${teamId}`)
        .then(res => res.json())
        .then(data => {
            if(data.length === 0) {
                document.getElementById('tm_content').innerHTML = '<div style="text-align:center; padding: 20px; font-weight: bold; color: #888;">No responders assigned to this unit yet.</div>';
            } else {
                let html = '';
                data.forEach(user => {
                    html += `<div style="background: #f8f9fa; padding: 15px; border-radius: 15px; margin-bottom: 10px; border: 1px solid #edf2f7; display:flex; align-items:center; gap: 15px;">
                        <div style="background: #eef2f7; width: 45px; height: 45px; border-radius: 50%; display:flex; align-items:center; justify-content:center;">
                            <i class='bx bxs-user-badge' style="font-size:1.5rem; color:#1976d2;"></i>
                        </div>
                        <div>
                            <div style="font-weight: 800; color: #333; font-size:1.1rem;">${user.first_name} ${user.last_name}</div>
                            <div style="font-size: 0.8rem; color: #888; text-transform: uppercase; font-weight: 800; margin-top:2px;"><i class='bx bx-radio'></i> ${user.radio_callsign || 'No Callsign'}</div>
                        </div>
                    </div>`;
                });
                document.getElementById('tm_content').innerHTML = html;
            }
        }).catch(e => {
            document.getElementById('tm_content').innerHTML = '<div style="text-align:center; color: #d32f2f; font-weight:bold;">Failed to load personnel.</div>';
        });
    }

    function openDeployModal(teamId, teamName) {
        const incidentCount = document.getElementById('deploy_incident_id').options.length;
        if (incidentCount <= 1) { 
            customAlert("No Targets", "There are no active incidents on the map to deploy units to!", "bx-error-circle", "#fbc02d");
            return;
        }
        currentDeployTeamId = teamId;
        currentDeployTeamName = teamName; 
        document.getElementById('deployTeamName').innerText = "Deploying: " + teamName;
        document.getElementById('deployModal').style.display = 'flex';
    }

    function confirmDeployment() {
        let incidentId = document.getElementById('deploy_incident_id').value;
        if(!incidentId) return customAlert("Selection Required", "Please select a destination from the list.", "bx-error-circle", "#d32f2f");
        let formData = new FormData();
        formData.append('action', 'deploy_team'); 
        formData.append('team_ids', JSON.stringify([currentDeployTeamId])); 
        formData.append('incident_id', incidentId);
        formData.append('team_names', currentDeployTeamName); 
        
        handleServerResponse(fetch('../admin/admin_actions.php', { method: 'POST', body: formData }));
    }

    function updateStatus(id, newStatus) {
        customConfirm("Update Status?", `Mark this unit as ${newStatus.toUpperCase()}?`, "bx-refresh", "#1976d2", function() {
            let formData = new FormData();
            formData.append('action', 'update_team_status'); 
            formData.append('id', id); 
            formData.append('status', newStatus);
            
            handleServerResponse(fetch('../admin/admin_actions.php', { method: 'POST', body: formData }));
        });
    }
    // 🚀 Handle KPI Click for Mobile Expansion
    document.querySelectorAll('.kpi-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const isExpanded = this.classList.contains('mobile-expanded');
                document.querySelectorAll('.kpi-card').forEach(c => c.classList.remove('mobile-expanded'));
                if (!isExpanded) this.classList.add('mobile-expanded');
            }
        });
    });
    </script>
</body>
</html>
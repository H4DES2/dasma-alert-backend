<?php
require_once '../php/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) { 
    $auth = new Auth($conn); 
}

if (!$auth->isSuperAdmin() && !$auth->isAdmin()) {
    header('Location: ../php/login.php');
    exit();
}

$role    = $_SESSION['role'] ?? '';
$user_id = (int)($_SESSION['user_id'] ?? 0);

// Release session lock immediately so parallel requests and page switches are non-blocking
session_write_close();

// Use prepared statement for user barangay lookup
$u_stmt = $conn->prepare("SELECT barangay FROM users WHERE id = ?");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$u_data  = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();
$my_brgy = trim($u_data['barangay'] ?? '');

$barangays = [];
$b_res = $conn->query("SELECT name FROM barangays WHERE status = 'active' ORDER BY name ASC");
if ($b_res) {
    while ($row = $b_res->fetch_assoc()) {
        $barangays[] = $row['name'];
    }
}

// KPI queries with prepared statements
if ($role === 'admin' && !empty($my_brgy)) {
    $like_brgy = '%' . $my_brgy . '%';

    $s = $conn->prepare("SELECT COUNT(*) as count FROM response_teams WHERE (assigned_barangay = ? OR assigned_barangay LIKE ? OR LOWER(TRIM(assigned_barangay)) = 'city-wide' OR assigned_barangay IS NULL OR TRIM(assigned_barangay) = '')");
    $s->bind_param("ss", $my_brgy, $like_brgy); $s->execute();
    $total_teams = (int)$s->get_result()->fetch_assoc()['count']; $s->close();

    $s = $conn->prepare("SELECT COUNT(*) as count FROM response_teams WHERE status = 'available' AND (assigned_barangay = ? OR assigned_barangay LIKE ? OR LOWER(TRIM(assigned_barangay)) = 'city-wide' OR assigned_barangay IS NULL OR TRIM(assigned_barangay) = '')");
    $s->bind_param("ss", $my_brgy, $like_brgy); $s->execute();
    $avail_teams = (int)$s->get_result()->fetch_assoc()['count']; $s->close();

    $s = $conn->prepare("SELECT COUNT(*) as count FROM response_teams WHERE status IN ('deployed','on-scene') AND (assigned_barangay = ? OR assigned_barangay LIKE ? OR LOWER(TRIM(assigned_barangay)) = 'city-wide' OR assigned_barangay IS NULL OR TRIM(assigned_barangay) = '')");
    $s->bind_param("ss", $my_brgy, $like_brgy); $s->execute();
    $dep_teams = (int)$s->get_result()->fetch_assoc()['count']; $s->close();

    $s = $conn->prepare("SELECT COUNT(*) as count FROM response_teams WHERE status = 'maintenance' AND (assigned_barangay = ? OR assigned_barangay LIKE ? OR LOWER(TRIM(assigned_barangay)) = 'city-wide' OR assigned_barangay IS NULL OR TRIM(assigned_barangay) = '')");
    $s->bind_param("ss", $my_brgy, $like_brgy); $s->execute();
    $maint_teams = (int)$s->get_result()->fetch_assoc()['count']; $s->close();

    $s = $conn->prepare("SELECT * FROM response_teams WHERE (assigned_barangay = ? OR assigned_barangay LIKE ? OR LOWER(TRIM(assigned_barangay)) = 'city-wide' OR assigned_barangay IS NULL OR TRIM(assigned_barangay) = '') ORDER BY status ASC, team_type ASC");
    $s->bind_param("ss", $my_brgy, $like_brgy); $s->execute();
    $teams_result = $s->get_result(); $s->close();
} else {
    $total_teams  = (int)($conn->query("SELECT COUNT(*) as count FROM response_teams")->fetch_assoc()['count'] ?? 0);
    $avail_teams  = (int)($conn->query("SELECT COUNT(*) as count FROM response_teams WHERE status = 'available'")->fetch_assoc()['count'] ?? 0);
    $dep_teams    = (int)($conn->query("SELECT COUNT(*) as count FROM response_teams WHERE status IN ('deployed','on-scene')")->fetch_assoc()['count'] ?? 0);
    $maint_teams  = (int)($conn->query("SELECT COUNT(*) as count FROM response_teams WHERE status = 'maintenance'")->fetch_assoc()['count'] ?? 0);
    $teams_result = $conn->query("SELECT * FROM response_teams ORDER BY status ASC, team_type ASC");
}

$teams = [];
$avail_list = $dep_list = $maint_list = [];

if ($teams_result && $teams_result->num_rows > 0) {
    $teams = $teams_result->fetch_all(MYSQLI_ASSOC);
    foreach ($teams as $t) {
        if ($t['status'] === 'available')                                $avail_list[] = $t;
        if ($t['status'] === 'deployed' || $t['status'] === 'on-scene') $dep_list[]   = $t;
        if ($t['status'] === 'maintenance')                              $maint_list[] = $t;
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
    <link rel="stylesheet" href="../css/admin/navbar.css?v=<?= filemtime('../css/admin/navbar.css') ?>">
    <link rel="stylesheet" href="../css/admin/resource_tracking.css?v=<?= filemtime('../css/admin/resource_tracking.css') ?>">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header style="margin-bottom: 24px;">
            <h1 style="margin: 0; font-weight: 900; letter-spacing: -1px; font-size: clamp(1.4rem, 4vw, 2.2rem);">
                <?php echo ($role === 'admin') ? "Sector Resource Tracking - $my_brgy" : "City Resource Tracking"; ?>
            </h1>
        </header>

        <div class="kpi-grid">
            <div class="kpi-card blue">
                <div class="kpi-header-row">
                    <div class="kpi-icon-wrapper"><i class='bx bxs-truck' style="color:#1976d2;"></i></div>
                    <div class="kpi-card-content">
                        <h3><?php echo $total_teams; ?></h3>
                        <p>Total Units</p>
                    </div>
                </div>
                <div class="kpi-details-container">
                    <?php if(empty($teams)): ?><div style="background:none; border:none; text-align:center; opacity:0.6;">No units found.</div>
                    <?php else: foreach($teams as $t): ?>
                        <div>
                            <b><?php echo htmlspecialchars($t['team_name']); ?></b> • <?php echo htmlspecialchars($t['team_type']); ?>
                            <?php if($role === 'superadmin') echo "<br><small style='color:#1976d2;'>📍 " . htmlspecialchars($t['assigned_barangay'] ?: 'City-Wide') . "</small>"; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="kpi-card green">
                <div class="kpi-header-row">
                    <div class="kpi-icon-wrapper"><i class='bx bxs-check-shield' style="color:#388e3c;"></i></div>
                    <div class="kpi-card-content">
                        <h3><?php echo $avail_teams; ?></h3>
                        <p>Available</p>
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
                    <div class="kpi-icon-wrapper"><i class='bx bxs-alarm-exclamation' style="color:#d32f2f;"></i></div>
                    <div class="kpi-card-content">
                        <h3><?php echo $dep_teams; ?></h3>
                        <p>Deployed</p>
                    </div>
                </div>
                <div class="kpi-details-container">
                    <?php if(empty($dep_list)): ?><div style="background:none; border:none; text-align:center; opacity:0.6;">No active deployments.</div>
                    <?php else: foreach($dep_list as $t): ?>
                        <div><b><?php echo htmlspecialchars($t['team_name']); ?></b> • <?php echo htmlspecialchars($t['team_type']); ?></div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="kpi-card gray">
                <div class="kpi-header-row">
                    <div class="kpi-icon-wrapper"><i class='bx bxs-wrench' style="color:#777;"></i></div>
                    <div class="kpi-card-content">
                        <h3><?php echo $maint_teams; ?></h3>
                        <p>In Maintenance</p>
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
                <h2 style="margin: 0; font-size: clamp(1.1rem, 3.2vw, 1.4rem);">Response Unit Directory</h2>
                <?php if($role === 'superadmin'): ?>
                <button class="btn-add btn-sm" onclick="openAddUnitModal()"><i class='bx bx-plus-circle'></i> Register New Unit</button>
                <?php endif; ?>
            </div>
            
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Unit Name</th>
                            <th>Unit Type</th>
                            <th>Assigned Sector</th>
                            <th>Status</th>
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
                                
                                $assigned_to = !empty($team['assigned_barangay']) ? htmlspecialchars($team['assigned_barangay']) : 'City-Wide';
                            ?>
                            <tr class="clickable-row" onclick="viewTeamMembers(<?php echo $team['id']; ?>, '<?php echo addslashes($team['team_name']); ?>')">
                                <td><strong><?php echo htmlspecialchars($team['team_name']); ?></strong></td>
                                <td><i class='bx <?php echo $type_icon; ?>' style="font-size: 1.2rem; vertical-align: middle; margin-right: 8px; opacity: 0.7;"></i> <?php echo htmlspecialchars($team['team_type']); ?></td>
                                <td><strong style="color:#1976d2; font-size:0.85rem;"><i class='bx bxs-map-pin'></i> <?php echo $assigned_to; ?></strong></td>
                                <td><span class="badge <?php echo $team['status']; ?>"><?php echo strtoupper($team['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Registration Modal -->
    <div id="addUnitModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class='bx bx-plus-circle' style="color: #228b22;"></i> Register Unit</h3>
                <span class="close-modal" onclick="closeModal('addUnitModal')">&times;</span>
            </div>
            <div class="modal-body">
                <label style="font-weight: 800; display: block; margin-bottom: 8px;">Unit Name:</label>
                <input type="text" id="new_team_name" class="modal-input" placeholder="e.g. Medic 1">
                
                <label style="font-weight: 800; display: block; margin-bottom: 8px;">Unit Type:</label>
                <select id="new_team_type" class="modal-select">
                    <option value="Medic">Medic</option>
                    <option value="Fire">Fire</option>
                    <option value="Rescue">Rescue</option>
                    <option value="Police">Police</option>
                </select>

                <label style="font-weight: 800; display: block; margin-bottom: 8px;">Assigned Sector:</label>
                <select id="new_team_brgy" class="modal-select">
                    <option value="">🌍 City-Wide (Unassigned)</option>
                    <?php foreach($barangays as $b): ?>
                        <option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <div style="display: flex; gap: 15px; margin-top: 10px;">
                    <button class="modal-cancel-btn" onclick="closeModal('addUnitModal')">Cancel</button>
                    <button class="btn-sm btn-add" onclick="submitNewUnit()" style="flex: 2; justify-content: center;">Register Unit</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Team Members Modal -->
    <div id="teamMembersModal" class="modal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header">
                <h3><i class='bx bxs-group' style="color: #1976d2;"></i> <span id="tm_title">Unit Personnel</span></h3>
                <span class="close-modal" onclick="closeModal('teamMembersModal')">&times;</span>
            </div>
            <div class="modal-body" id="tm_content" style="max-height: 350px; overflow-y: auto;">
                <div style="text-align:center; padding: 20px; opacity:0.6;"><i class="bx bx-loader-alt bx-spin"></i> Loading personnel...</div>
            </div>
        </div>
    </div>

    <!-- Universal Confirmation Modal -->
    <div id="universalModal" class="modal">
        <div class="modal-content" style="text-align: center; width: 380px; padding: 35px;">
            <i id="uniModalIcon" class='bx bxs-help-circle' style="font-size: 4.5rem; margin-bottom: 16px;"></i>
            <h3 id="uniModalTitle" style="margin-bottom: 12px; font-size: 1.4rem;">Confirm</h3>
            <p id="uniModalText" style="margin-bottom: 24px; color: var(--text-secondary); font-weight: 700; line-height: 1.5;"></p>
            <div style="display: flex; gap: 12px;" id="uniModalButtons"></div>
        </div>
    </div>
<script src="../js/admin/resource_tracking.js?v=<?= filemtime('../js/admin/resource_tracking.js') ?>" defer></script>
</body>
</html>
<?php
session_start();
require_once '../php/config.php';
require_once '../php/auth.php';

if (!isset($auth)) { $auth = new Auth($conn); }

if (!$auth->isAdmin()) {
    header("Location: ../php/login.php");
    exit();
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// 🚀 ROBUST JURISDICTION FETCH WITH ALIASES
$stmt = $conn->prepare("SELECT barangay FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$u_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

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

// 🚀 ROLE-BASED QUERY FIX: Superadmins see all, Admins see their sector
if ($role === 'superadmin') {
    $evac_centers = $conn->query("SELECT * FROM evacuation_centers ORDER BY name ASC");
} else {
    $stmt = $conn->prepare("SELECT * FROM evacuation_centers WHERE TRIM(barangay) = ? ORDER BY name ASC");
    $stmt->bind_param("s", $assigned_brgy);
    $stmt->execute();
    $evac_centers = $stmt->get_result();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Evacuation Centers | Barangay Command</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../css/client/navbar.css">
    <link rel="stylesheet" href="../css/client/evacuation_centers.css">
</head>
<body>

    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
    <div>
        <h1>Barangay Command</h1>
        <p>Jurisdiction: <span style="color: var(--color-critical, #d32f2f); font-weight: 800;"><?php echo htmlspecialchars($assigned_brgy ?: 'Unassigned'); ?></span></p>
    </div>
</header>

        <div class="table-container">
            <div class="header-section">
                <div>
                    <h2>Evacuation Centers</h2>
                    <p>Manage local shelters and track live occupancy.</p>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Facility Name</th>
                            <th style="width: 300px;">Occupancy Tracker</th>
                            <th>Status</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($evac_centers && $evac_centers->num_rows > 0): ?>
                            <?php while($row = $evac_centers->fetch_assoc()): 
                                $capacity = max(1, $row['capacity']); 
                                $pct = min(100, max(0, ($row['current_occupants'] / $capacity) * 100));
                                
                                $status_class = 'badge-closed';
                                if (strtolower($row['status']) === 'open') $status_class = 'badge-open';
                                if (strtolower($row['status']) === 'full') $status_class = 'badge-full';

                                $bar_color = '#388e3c'; 
                                if ($pct >= 80) $bar_color = '#f57c00'; 
                                if ($pct >= 100) $bar_color = '#d32f2f'; 

                                $f_type = strtolower($row['facility_type'] ?? 'temporary');
                                $f_bg = ($f_type === 'permanent') ? '#1565c0' : '#7b1fa2';
                            ?>
                            <tr class="clickable-row" onclick="handleRowClick(this, '<?php echo $row['id']; ?>', '<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>', '<?php echo $row['current_occupants']; ?>', '<?php echo strtolower($row['status']); ?>')">
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                        <strong><?php echo htmlspecialchars($row['name']); ?></strong>
                                        <span class="badge" style="background: <?= $f_bg ?>; font-size: 0.65rem; padding: 2px 7px; text-transform: uppercase; color: #fff;">
                                            <?= htmlspecialchars($f_type) ?>
                                        </span>
                                    </div>
                                    <i class='bx bx-chevron-right mobile-expand-icon'></i>
                                </td>
                                <td>
                                    <div style="display: flex; justify-content: space-between; font-weight: 800; font-size: 0.85rem; color: #888;">
                                        <span><?php echo number_format($row['current_occupants']); ?> / <?php echo number_format($row['capacity']); ?> Pax</span>
                                        <span><?php echo round($pct); ?>%</span>
                                    </div>
                                    <div class="progress-wrapper">
                                        <div class="progress-fill" style="width: <?php echo $pct; ?>%; background-color: <?php echo $bar_color; ?>;"></div>
                                    </div>
                                </td>
                                <td><span class="badge <?php echo $status_class; ?>"><?php echo strtoupper($row['status']); ?></span></td>
                                <td style="text-align: center;">
                                    <button class="btn-action btn-manage" 
                                            data-id="<?php echo $row['id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>" 
                                            data-occupants="<?php echo $row['current_occupants']; ?>" 
                                            data-status="<?php echo strtolower($row['status']); ?>" 
                                            onclick="event.stopPropagation(); openManageModal(this)">
                                        <i class='bx bx-edit-alt'></i> Manage Data
                                    </button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php elseif ($role !== 'superadmin' && !$assigned_brgy): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 50px; color: #888; font-weight: 600;">Please set your Assigned Barangay in your Profile to view local shelters.</td></tr>
                        <?php else: ?>
                            <tr><td colspan="4" style="text-align:center; padding: 50px; color: #888; font-weight: 600;">No evacuation centers registered <?php echo ($role === 'superadmin') ? "in the city" : "in " . htmlspecialchars($assigned_brgy); ?> yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="manageModal" class="modal">
        <div class="modal-content" style="position: relative;">
            <div class="close-modal" onclick="closeModal('manageModal')"><i class='bx bx-x'></i></div>
            <div class="modal-header">
                <h3 id="manageTitle" style="color: #1976d2;"><i class='bx bx-edit-alt'></i> Manage</h3>
            </div>
            <div class="modal-body">
                <input type="hidden" id="manageId">
                <label>Current Occupants</label>
                <input type="number" id="manageOccupants" class="modal-input">
                <label>Facility Status</label>
                <select id="manageStatus" class="modal-select">
                    <option value="open">OPEN (Accepting)</option>
                    <option value="full">FULL (At Capacity)</option>
                    <option value="closed">CLOSED (Inactive)</option>
                </select>
                
                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button class="modal-cancel-btn" onclick="closeModal('manageModal')">Cancel</button>
                    <button class="btn-action btn-manage" style="flex: 2; justify-content: center;" onclick="submitManage()">Update Status</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Mobile Evacuation Detail Modal -->
    <div id="mobileEvacModal" class="modal" style="z-index: 10005;">
        <div class="modal-content" style="max-width: 90%; padding: 24px; position: relative;">
            <div class="close-modal" onclick="closeModal('mobileEvacModal')"><i class='bx bx-x'></i></div>
            <h3 id="m-evac-title" style="margin-bottom: 16px; font-weight: 900; font-size: 1.3rem; padding-right: 30px; color: var(--text-primary);">Evacuation Center</h3>
            <div id="m-evac-body" style="display: flex; flex-direction: column;"></div>
        </div>
    </div>

    <div id="universalModal" class="modal" style="z-index: 10010;">
        <div class="modal-content" style="text-align: center; width: 350px; padding: 40px; position: relative;">
            <i id="uniModalIcon" class='bx bxs-help-circle' style="font-size: 4rem; margin-bottom: 15px;"></i>
            <h3 id="uniModalTitle" style="margin-bottom: 10px;">Confirm</h3>
            <p id="uniModalText" style="margin-bottom: 25px; color: #888; font-weight: 600;">Message</p>
            <div style="display: flex; gap: 12px;" id="uniModalButtons"></div>
        </div>
    </div>

<script>
    window.APP_ROLE = <?= json_encode($role); ?>;
    window.ASSIGNED_BRGY = <?= json_encode($assigned_brgy); ?>;
</script>
<script src="../js/client/evacuation_centers.js?v=<?= filemtime('../js/client/evacuation_centers.js') ?>"></script>
</body>
</html>
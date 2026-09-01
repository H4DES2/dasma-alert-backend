<?php
session_start();
require_once '../php/config.php';
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) { 
    $auth = new Auth($conn); 
}

// 🚀 SECURITY: Allow both Superadmin and Admin
if (!$auth->isSuperAdmin() && (!$auth->isAdmin())) {
    header("Location: ../php/login.php");
    exit();
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// 🚨 GET ADMIN'S ASSIGNED BARANGAY
$u_data = ($res = $conn->query("SELECT barangay FROM users WHERE id = $user_id")) ? $res->fetch_assoc() : null;
$my_brgy = $u_data['barangay'] ?? '';

// 🚨 ROLE-BASED QUERY
if ($role === 'superadmin') {
    // Superadmin sees all centers
    $query = "SELECT * FROM evacuation_centers ORDER BY name ASC";
} else {
    // Barangay Admin sees only their sector
    $safe_brgy = $conn->real_escape_string($my_brgy);
    $query = "SELECT * FROM evacuation_centers WHERE barangay = '$safe_brgy' ORDER BY name ASC";
}

$result = $conn->query($query);
$centers = [];

// 🚀 Valid barangay list for the Add Facility dropdown (prevents fk_evac_brgy violations)
$brgy_list = [];
if ($bres = $conn->query("SELECT name FROM barangays ORDER BY name ASC")) {
    while ($b = $bres->fetch_assoc()) { $brgy_list[] = $b['name']; }
}
if ($result && $result->num_rows > 0) {
    $centers = $result->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Evacuation Centers | Command Center</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../css/admin/navbar.css?v=<?= filemtime('../css/admin/navbar.css') ?>">
    <link rel="stylesheet" href="../css/admin/evacuation_centers.css?v=<?= filemtime('../css/admin/evacuation_centers.css') ?>">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="header-flex">
            <div>
                <h1>Evacuation Centers</h1>
                <p style="color: #666; margin-top: 5px; font-weight: 700;">
                    <?php echo ($role === 'superadmin') ? "City-Wide Facility Management" : "Sectoral Facility Tracker: $my_brgy"; ?>
                </p>
            </div>
            <?php if ($role === 'superadmin'): ?>
            <button class="btn-action btn-add" onclick="openAddModal()">
                <i class='bx bx-plus-circle'></i> Register New Center
            </button>
            <?php endif; ?>
        </header>

        <div class="table-container">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Facility Name</th>
                            <th>Barangay</th>
                            <th style="width: 380px;">Occupancy & Tents</th>
                            <th>Status</th>
                            <th style="text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($centers)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 60px; color: #777; font-weight: 700;">
                                    No evacuation centers recorded in this jurisdiction.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($centers as $center): 
                                $occupants = (int)($center['current_occupants'] ?? 0);
                                $capacity = (int)($center['capacity'] ?? 1);
                                $percentage = ($capacity > 0) ? ($occupants / $capacity) * 100 : 0;
                                
                                // Standard 4 Pax per Modular Tent
                                $families_tents = ceil($occupants / 4);
                                $max_tents = ceil($capacity / 4);
                                
                                $bar_color = '#388e3c'; // Green
                                if ($percentage >= 80) $bar_color = '#fbc02d'; // Yellow
                                if ($percentage >= 100) $bar_color = '#d32f2f'; // Red
                            ?>
                            <tr class="clickable-row" onclick="openMobileModal(this)">
                                <td>
                                    <strong style="font-size: 1.1rem; color: #1976d2;"><?php echo htmlspecialchars($center['name']); ?></strong>
                                </td>
                                <td><i class='bx bxs-map-pin' style="color: #d32f2f; opacity: 0.7;"></i> <?php echo htmlspecialchars($center['barangay']); ?></td>
                                <td>
                                    <small class='mobile-label'>OCCUPANCY</small>
                                    <div style="display: flex; justify-content: space-between; font-size: 0.8rem; font-weight: 800; color: #555;">
                                        <span>
                                            <?php echo number_format($occupants); ?> PAX 
                                            <span style="color: #1976d2; font-weight: 900; font-size: 0.75rem;">(≈ <?php echo number_format($families_tents); ?> Tents)</span> 
                                            / <?php echo number_format($capacity); ?> PAX
                                        </span>
                                        <span style="color: <?php echo $bar_color; ?>;"><?php echo round($percentage); ?>%</span>
                                    </div>
                                    <div class="progress-bar-bg">
                                        <div class="progress-bar-fill" style="width: <?php echo min(100, $percentage); ?>%; background-color: <?php echo $bar_color; ?>;"></div>
                                    </div>
                                </td>
                                <td style="text-align: center;">
                                    <small class='mobile-label'>STATUS</small>
                                    <span class="badge <?php echo strtolower($center['status']); ?>" style="width: 100%; justify-content: center;"><?php echo strtoupper($center['status']); ?></span>
                                </td>
                                <td>
                                    <small class='mobile-label'>ACTIONS</small>
                                    <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                                        <button class="btn-action btn-manage" 
                                                data-id="<?php echo $center['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($center['name'], ENT_QUOTES); ?>" 
                                                data-occupants="<?php echo $occupants; ?>" 
                                                data-capacity="<?php echo $capacity; ?>" 
                                                data-status="<?php echo htmlspecialchars($center['status'], ENT_QUOTES); ?>" 
                                                onclick="event.stopPropagation(); openManageModal(this)">
                                            <i class='bx bx-edit-alt'></i> Manage
                                        </button>
                                        
                                        <?php if ($role === 'superadmin'): ?>
                                        <button class="btn-action btn-delete" onclick="event.stopPropagation(); handleDelete(<?php echo $center['id']; ?>)">
                                            <i class='bx bxs-trash'></i> Delete
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <div id="addModal" class="modal">
        <div class="modal-content" style="max-width: 550px;">
            <div class="modal-header">
                <h3><i class='bx bx-building-house' style="color: #228b22;"></i> New Facility</h3>
                <span class="close-modal" onclick="closeModal('addModal')">&times;</span>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto; padding-right: 10px;">
                <label>Facility Name</label>
                <input type="text" id="addName" class="modal-input" placeholder="e.g. Brgy. Hall Covered Court">
                
                <div style="display: flex; gap: 15px;">
                    <div style="flex: 1;">
                        <label>Barangay</label>
                        <select id="addBarangay" class="modal-input">
                            <option value="">-- Select Barangay --</option>
                            <?php foreach ($brgy_list as $b): ?>
                            <option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="flex: 1;">
                        <label>Max Capacity (PAX)</label>
                        <input type="number" id="addCapacity" class="modal-input" placeholder="e.g. 500">
                    </div>
                </div>
                
                <label>Pin Facility Location</label>
                <div id="modalMap" style="height: 250px; width: 100%; border-radius: 14px; border: 1px solid #e2e8f0; margin-bottom: 15px; z-index: 1;"></div>
                
                <div style="display: flex; gap: 15px;">
                    <div style="flex: 1;">
                        <label>Latitude</label>
                        <input type="text" id="addLat" class="modal-input readonly-input" placeholder="Auto-filled" readonly>
                    </div>
                    <div style="flex: 1;">
                        <label>Longitude</label>
                        <input type="text" id="addLng" class="modal-input readonly-input" placeholder="Auto-filled" readonly>
                    </div>
                </div>
                
                <div style="display: flex; gap: 15px; margin-top: 10px;">
                    <button class="modal-cancel-btn" onclick="closeModal('addModal')">Cancel</button>
                    <button class="btn-action btn-add" style="flex: 2; justify-content: center;" onclick="addCenter()">Save Facility</button>
                </div>
            </div>
        </div>
    </div>

    <div id="manageModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="manageTitle" style="color: #1976d2;">Manage</h3>
                <span class="close-modal" onclick="closeModal('manageModal')">&times;</span>
            </div>
            <div class="modal-body">
                <input type="hidden" id="manageId">
                <input type="hidden" id="manageCapacity">
                
                <label>Current Occupants (PAX)</label>
                <input type="number" id="manageOccupants" class="modal-input" oninput="checkCapacityStatus()">
                
                <label>Facility Status</label>
                <select id="manageStatus" class="modal-select">
                    <option value="open">OPEN (Accepting)</option>
                    <option value="full">FULL / CLOSED (At Capacity)</option>
                    <option value="closed">CLOSED (Inactive)</option>
                </select>
                
                <div style="display: flex; gap: 15px; margin-top: 10px;">
                    <button class="modal-cancel-btn" onclick="closeModal('manageModal')">Cancel</button>
                    <button class="btn-action btn-manage" style="flex: 2; justify-content: center;" onclick="saveManage()">Update Status</button>
                </div>
            </div>
        </div>
    </div>

    <div id="universalModal" class="modal" style="z-index: 10000;">
        <div class="modal-content" style="text-align: center; width: 380px; padding: 45px;">
            <i id="uniModalIcon" class='bx bxs-help-circle' style="font-size: 5rem; margin-bottom: 20px;"></i>
            <h3 id="uniModalTitle" style="margin-bottom: 10px; font-size: 1.6rem;">Confirm</h3>
            <p id="uniModalText" style="margin-bottom: 30px; color: #666; font-weight: 700;"></p>
            <div style="display: flex; gap: 15px;" id="uniModalButtons"></div>
        </div>
    </div>
     <!-- MODAL: Mobile Row Details -->
    <div id="mobileEvacModal" class="modal" style="z-index: 10005;">
        <div class="modal-content" style="max-width: 90%; padding: 24px;">
            <div class="close-modal" onclick="closeModal('mobileEvacModal')"><i class='bx bx-x'></i></div>
            <h3 id="m-evac-title" style="margin-bottom: 16px; font-weight: 900; font-size: 1.4rem; padding-right: 30px;"></h3>
            
            <div style="display: flex; flex-direction: column;">
                <div style="margin-bottom: 15px;">
                    <small style="color: #888; font-weight: 800; font-size: 0.7rem;">LOCATION</small>
                    <div id="m-evac-brgy" style="font-weight: 700; font-size: 1rem; margin-top: 4px;"></div>
                </div>
                
                <div class="mobile-detail-box">
                    <div id="m-evac-occ"></div>
                </div>

                <div style="margin-bottom: 20px;">
                    <div id="m-evac-status"></div>
                </div>

                <div>
                    <div id="m-evac-actions"></div>
                </div>
            </div>
        </div>
    </div>                                       
    <script src="../js/admin/evacuation_centers.js?v=<?= filemtime('../js/admin/evacuation_centers.js') ?>" defer></script>
</body>
</html>
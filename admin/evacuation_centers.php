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
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        /* 🎨 THE HIGH-DEPTH FLOATING THEME */
        body { 
            font-family: 'Segoe UI', sans-serif; 
            background-color: #f0f3f7; 
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

        /* 🧱 THE LARGE FLOATING PANEL */
        .table-container { 
            background: #ffffff; 
            padding: 35px; 
            border-radius: 25px; 
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12), 0 10px 20px rgba(0, 0, 0, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.8);
            margin-top: 20px;
        }

        .header-flex { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 30px; 
        }
        
        .header-flex h1 { 
            font-weight: 800; 
            color: #222; 
            font-size: 1.8rem; 
            letter-spacing: -0.5px; 
        }

        /* 🗺️ FLAT WRAPPER FOR TABLE */
        .table-wrapper { 
            background: #f8f9fa; 
            border-radius: 20px; 
            border: 1px solid #edf2f7; 
            overflow: hidden; 
        }

        .data-table { 
            width: 100%; 
            border-collapse: collapse; 
            min-width: 800px; 
        }
        
        .data-table th { 
            position: sticky; 
            top: 0; 
            background: #ffffff; 
            z-index: 2; 
            padding: 18px 20px; 
            text-align: left; 
            border-bottom: 2px solid #f1f4f8; 
            color: #888; 
            text-transform: uppercase; 
            font-size: 0.75rem; 
            letter-spacing: 0.5px; 
        }
        
        .data-table td { 
            padding: 18px 20px; 
            text-align: left; 
            border-bottom: 1px solid #f1f4f8; 
            vertical-align: middle; 
            color: #333; 
            font-weight: 600; 
        }
        
        /* 🚨 STATUS BADGES */
        .badge { 
            padding: 8px 14px; 
            border-radius: 12px; 
            font-size: 0.7rem; 
            font-weight: 900; 
            color: white; 
            display: inline-flex; 
            align-items: center; 
            gap: 6px; 
            text-transform: uppercase; 
        }
        
        .badge.open { background: linear-gradient(135deg, #11998e, #388e3c); } 
        .badge.full { background: linear-gradient(135deg, #ff4b2b, #dc3545); } 
        .badge.closed { background: #607d8b; }

        /* PROGRESS TRACKER */
        .progress-bar-bg { 
            width: 100%; 
            background-color: #eee; 
            border-radius: 10px; 
            height: 10px; 
            margin-top: 10px; 
            overflow: hidden; 
        }
        
        .progress-bar-fill { 
            height: 100%; 
            border-radius: 10px; 
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1); 
        }

        /* FLOATING BUTTONS */
        .btn-action { 
            padding: 10px 18px; 
            border: none; 
            border-radius: 12px; 
            cursor: pointer; 
            font-size: 0.85rem; 
            font-weight: 800; 
            transition: 0.3s; 
            color: white; 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            box-shadow: 0 4px 12px rgba(0,0,0,0.1); 
        }
        
        .btn-action:hover { 
            transform: translateY(-3px); 
            box-shadow: 0 8px 20px rgba(0,0,0,0.15); 
        }
        
        .btn-manage { background: #1976d2; } 
        .btn-delete { background: #e53935; } 
        .btn-add { background: #228b22; padding: 12px 22px; font-size: 0.95rem; }

        /* HIGH-DEPTH MODALS */
        .modal { 
            display: none; 
            position: fixed; 
            z-index: 9999; 
            left: 0; 
            top: 0; 
            width: 100vw; 
            height: 100vh; 
            background: rgba(15, 23, 42, 0.5); 
            align-items: center; 
            justify-content: center; 
            backdrop-filter: blur(8px); 
        }
        
        .modal-content { 
            background: #ffffff; 
            padding: 40px; 
            border-radius: 30px; 
            width: 100%; 
            max-width: 480px; 
            position: relative; 
            box-shadow: 0 30px 80px rgba(0,0,0,0.3); 
            border: none; 
        }
        
        .modal-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 25px; 
            border-bottom: 1px solid #f1f4f8; 
            padding-bottom: 15px; 
        }
        
        .close-modal { 
            color: #aaa; 
            font-size: 2rem; 
            cursor: pointer; 
            transition: 0.2s; 
        }
        
        .close-modal:hover { color: #222; }

        /* DENTED INPUTS */
        .modal-input, .modal-select { 
            width: 100%; 
            padding: 14px; 
            margin-bottom: 20px; 
            border-radius: 14px; 
            border: 1px solid #e2e8f0; 
            background: #f8f9fa; 
            font-size: 1rem; 
            font-weight: 700; 
            color: #333; 
            outline: none; 
            transition: 0.3s; 
        }
        
        .modal-input:focus, .modal-select:focus { 
            border-color: #1976d2; 
            background: #fff; 
            box-shadow: 0 0 0 4px rgba(25, 118, 210, 0.1); 
        }
        
        .modal-body label { 
            display: block; 
            margin-bottom: 8px; 
            font-weight: 800; 
            color: #555; 
            font-size: 0.85rem; 
            text-transform: uppercase; 
            letter-spacing: 0.5px; 
        }

        .modal-cancel-btn { 
            flex: 1; 
            padding: 12px; 
            border-radius: 12px; 
            cursor: pointer; 
            border: 1px solid #ddd; 
            background: transparent; 
            font-weight: 800; 
            color: #333; 
            transition: 0.2s; 
        }
        
        .modal-cancel-btn:hover { background: #f8f9fa; }

        /* 🌙 DARK MODE SYNC (Deep Floating Dark) */
        html.global-dark-mode body, 
        html.global-dark-mode .main-content { 
            background: #0d1117 !important; 
        }
        
        html.global-dark-mode .table-container, 
        html.global-dark-mode .modal-content { 
            background: #161b22 !important; 
            box-shadow: 0 25px 60px rgba(0,0,0,0.6) !important; 
            border: 1px solid #30363d !important; 
        }
        
        html.global-dark-mode .table-wrapper, 
        html.global-dark-mode .modal-input, 
        html.global-dark-mode .modal-select { 
            background: #0d1117 !important; 
            border-color: #30363d !important; 
            color: #fff; 
        }
        
        html.global-dark-mode .data-table th { 
            background: #161b22 !important; 
            color: #8b949e !important; 
            border-bottom-color: #30363d; 
        }
        
        html.global-dark-mode .data-table td { 
            color: #c9d1d9 !important; 
            border-bottom-color: #21262d; 
        }
        
        html.global-dark-mode h1, 
        html.global-dark-mode h2, 
        html.global-dark-mode h3, 
        html.global-dark-mode label, 
        html.global-dark-mode p { 
            color: #f0f6fc !important; 
        }
        
        html.global-dark-mode .modal-cancel-btn { 
            color: white; 
            border-color: #444; 
        }
        
        html.global-dark-mode .progress-bar-bg { 
            background-color: #30363d; 
        }
        
        html.global-dark-mode #modalMap { 
            border-color: #30363d !important; 
            filter: brightness(0.8) contrast(1.2); 
        }
        
        /* 🚀 READONLY INPUT STYLING */
        .readonly-input { 
            background: #e9ecef !important; 
            cursor: not-allowed; 
            color: #666 !important; 
        }
        
        html.global-dark-mode .readonly-input { 
            background: #0d1117 !important; 
            color: #888 !important; 
        }
        
        /* 🚀 RESPONSIVE OVERRIDES */
        .row-expand-icon { display: none; }
        .mobile-label { display: none; }

        @media (max-width: 768px) {
            .main-content { 
                padding: 15px 15px 30px 15px !important; 
                margin-top: 110px !important; 
            }
            .header-flex { flex-direction: column; align-items: flex-start; gap: 15px; }
            .btn-add { width: 100%; justify-content: center; }
            .table-container { padding: 20px; }
            
            .close-modal {
                position: absolute; top: 12px !important; right: 12px !important;
                background: rgba(0, 0, 0, 0.6) !important; color: #fff !important;
                border: 1px solid rgba(255,255,255,0.2) !important;
                width: 38px; height: 38px; border-radius: 50%;
                display: flex; align-items: center; justify-content: center;
                font-size: 1.4rem; z-index: 1000;
            }
            .modal-header { padding-right: 40px; }

            /* 🚀 TABLE COMPRESSION (Hide columns 3, 4, 5) */
            .data-table { min-width: 100%; }
            .data-table th:nth-child(n+3), 
            .data-table td:nth-child(n+3) { display: none; }
            .data-table td { padding: 15px; }

            .clickable-row { position: relative; transition: all 0.3s ease; cursor: pointer; }
            .clickable-row:active { background: #e2e8f0; }
            html.global-dark-mode .clickable-row:active { background: #21262d; }

            .mobile-label { 
                display: block; font-size: 0.65rem; font-weight: 800; 
                color: #888; margin-bottom: 4px; letter-spacing: 0.05em; 
            }
        }

        /* Mobile Box Detail Style */
        .mobile-detail-box { background: #f8f9fa; padding: 16px; border-radius: 14px; border: 1px solid #edf2f7; margin-bottom: 15px; }
        html.global-dark-mode .mobile-detail-box { background: #0d1117; border-color: #30363d; }
    </style>
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
    <script>
        const role = "<?php echo addslashes($role); ?>";
        const assignedBrgy = "<?php echo addslashes($my_brgy); ?>";
        let modalMap = null;
        let modalMarker = null;

        function closeModal(id) { 
            document.getElementById(id).style.display = 'none'; 
        }

        function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
            document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
            document.getElementById('uniModalIcon').style.color = color;
            document.getElementById('uniModalTitle').innerText = title;
            document.getElementById('uniModalText').innerText = message;
            document.getElementById('uniModalButtons').innerHTML = `
                <button onclick="closeModal('universalModal')" class="btn-action" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">OK</button>
            `;
            document.getElementById('universalModal').style.display = 'flex';
        }

        function customConfirm(title, message, iconClass, color, confirmCallback) {
            document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
            document.getElementById('uniModalIcon').style.color = color;
            document.getElementById('uniModalTitle').innerText = title;
            document.getElementById('uniModalText').innerText = message;
            
            let cancelBtn = `<button onclick="closeModal('universalModal')" class="modal-cancel-btn" style="height: 50px;">Cancel</button>`;
            let confirmBtn = `<button id="uniConfirmBtn" class="btn-action" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">Proceed</button>`;
            
            document.getElementById('uniModalButtons').innerHTML = cancelBtn + confirmBtn;
            document.getElementById('universalModal').style.display = 'flex';
            
            document.getElementById('uniConfirmBtn').onclick = function() { 
                closeModal('universalModal'); 
                confirmCallback(); 
            };
        }

        function openAddModal() {
            if(role !== 'superadmin' && !assignedBrgy) {
                return customAlert("No Barangay", "Set your jurisdiction in your profile first.", "bx-error-circle", "#d32f2f");
            }

            document.getElementById('addName').value = '';
            document.getElementById('addBarangay').value = role === 'superadmin' ? '' : assignedBrgy;
            
            if(role !== 'superadmin') {
                document.getElementById('addBarangay').disabled = true;
                document.getElementById('addBarangay').classList.add('readonly-input');
            } else {
                document.getElementById('addBarangay').disabled = false;
            }

            document.getElementById('addCapacity').value = '';
            document.getElementById('addLat').value = '';
            document.getElementById('addLng').value = '';
            
            document.getElementById('addModal').style.display = 'flex';

            setTimeout(() => {
                if (!modalMap) {
                    const dasmaBounds = [ [14.2600, 120.9000], [14.3800, 120.9800] ];
                    modalMap = L.map('modalMap', { 
                        maxBounds: dasmaBounds, 
                        maxBoundsViscosity: 1.0, 
                        minZoom: 13 
                    }).setView([14.3294, 120.9368], 13);
                    
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(modalMap);

                    modalMap.on('click', function(e) {
                        let lat = e.latlng.lat.toFixed(6);
                        let lng = e.latlng.lng.toFixed(6);

                        document.getElementById('addLat').value = lat;
                        document.getElementById('addLng').value = lng;

                        if (modalMarker) {
                            modalMarker.setLatLng(e.latlng);
                        } else {
                            let evacIcon = L.divIcon({ 
                                html: `<i class='bx bxs-home-heart' style='color: #d32f2f; font-size: 36px; text-shadow: 0 4px 8px rgba(0,0,0,0.4); margin-top: -30px; margin-left: -18px;'></i>`, 
                                className: 'custom-leaflet-icon'
                            });
                            modalMarker = L.marker(e.latlng, { icon: evacIcon }).addTo(modalMap);
                        }
                    });
                } else {
                    modalMap.invalidateSize();
                    if (modalMarker) {
                        modalMap.removeLayer(modalMarker);
                        modalMarker = null;
                    }
                    modalMap.setView([14.3294, 120.9368], 13);
                }
            }, 150);
        }

        function addCenter() {
            let name = document.getElementById('addName').value.trim();
            let brgy = document.getElementById('addBarangay').value.trim();
            let cap = document.getElementById('addCapacity').value;
            let lat = document.getElementById('addLat').value.trim();
            let lng = document.getElementById('addLng').value.trim();
            
            if(!name || !brgy || !cap || !lat || !lng) {
                return customAlert("Location Missing", "Please fill out all details and click on the map to pin the exact location.", "bx-map-pin", "#d32f2f");
            }
            
            customConfirm("Register Facility?", `Are you sure you want to register ${name} as an evacuation center?`, "bx-building-house", "#228b22", function() {
                let fd = new FormData();
                fd.append('action', 'add_center'); 
                fd.append('name', name); 
                fd.append('barangay', brgy); 
                fd.append('capacity', cap);
                fd.append('latitude', lat);
                fd.append('longitude', lng);
                
                fetch('admin_actions.php', { method: 'POST', body: fd })
                    .then(res => res.text())
                    .then(data => {
                        if(data.trim() === 'success') {
                            location.reload(); 
                        } else { 
                            customAlert("Error", data || "Failed to add facility.", "bx-x-circle", "#d32f2f");
                        }
                    })
                    .catch(err => {
                        customAlert("Network Error", err.message, "bx-x-circle", "#d32f2f");
                    });
            });
        }

        // 🚀 THE NEW AUTO-CAPACITY CHECKER FUNCTION
        function checkCapacityStatus() {
            let occupants = parseInt(document.getElementById('manageOccupants').value) || 0;
            let capacity = parseInt(document.getElementById('manageCapacity').value) || 1;
            let statusDropdown = document.getElementById('manageStatus');

            if (occupants >= capacity) {
                // Automatically set to 'full' when capacity is reached
                statusDropdown.value = 'full';
            } else if (occupants < capacity && statusDropdown.value === 'full') {
                // Automatically set back to 'open' if it drops below capacity and was previously marked full
                statusDropdown.value = 'open';
            }
        }

        function openManageModal(btn) {
            document.getElementById('manageTitle').innerHTML = `<i class='bx bx-edit-alt'></i> ${btn.getAttribute('data-name')}`;
            document.getElementById('manageId').value = btn.getAttribute('data-id');
            document.getElementById('manageCapacity').value = btn.getAttribute('data-capacity'); // Pass capacity
            document.getElementById('manageOccupants').value = btn.getAttribute('data-occupants');
            document.getElementById('manageStatus').value = btn.getAttribute('data-status').toLowerCase();
            
            checkCapacityStatus(); // Run check immediately upon opening just in case

            document.getElementById('manageModal').style.display = 'flex';
        }

        function saveManage() {
            let fd = new FormData();
            fd.append('action', 'update_evac_center'); 
            fd.append('id', document.getElementById('manageId').value);
            fd.append('occupants', document.getElementById('manageOccupants').value);
            fd.append('status', document.getElementById('manageStatus').value);
            
            fetch('admin_actions.php', { method: 'POST', body: fd })
                .then(res => res.text())
                .then(data => {
                    if (data.trim() === 'success') { 
                        location.reload(); 
                    } else { 
                        customAlert("Error", data || "Failed to update.", "bx-x-circle", "#d32f2f"); 
                    }
                })
                .catch(e => {
                    customAlert("Network Error", "Could not process request.", "bx-x-circle", "#d32f2f");
                });
        }

        function handleDelete(id) {
            customConfirm("Delete Facility?", "This action is permanent and cannot be undone.", "bxs-trash", "#d32f2f", function() {
                let fd = new FormData();
                fd.append('action', 'delete_center');
                fd.append('id', id);
                
                fetch('admin_actions.php', { method: 'POST', body: fd })
                    .then(res => res.text())
                    .then(data => {
                        if(data.trim() === 'success') {
                            location.reload(); 
                        } else { 
                            customAlert("Error", data || "Failed to delete.", "bx-x-circle", "#d32f2f");
                        }
                    })
                    .catch(e => {
                        customAlert("Network Error", "Could not process request.", "bx-x-circle", "#d32f2f");
                    });
            });
        }
        function openMobileModal(row) {
            // Only trigger on mobile screens
            if (window.innerWidth > 768) return; 

            const cells = row.querySelectorAll('td');
            if (cells.length < 5) return;

            // Extract data from the row
            document.getElementById('m-evac-title').innerHTML = cells[0].innerHTML;
            document.getElementById('m-evac-brgy').innerHTML = cells[1].innerHTML;
            document.getElementById('m-evac-occ').innerHTML = cells[2].innerHTML;
            document.getElementById('m-evac-status').innerHTML = cells[3].innerHTML;
            
            // Extract actions and force the flex container to stack vertically
            let actionsHtml = cells[4].innerHTML;
            let actionsContainer = document.getElementById('m-evac-actions');
            actionsContainer.innerHTML = actionsHtml;
            
            // Format action buttons for mobile modal specifically
            let divWrapper = actionsContainer.querySelector('div');
            if (divWrapper) {
                divWrapper.style.flexDirection = 'column';
                let btns = divWrapper.querySelectorAll('button');
                btns.forEach(b => {
                    b.style.width = '100%';
                    b.style.justifyContent = 'center';
                });
            }

            document.getElementById('mobileEvacModal').style.display = 'flex';
        }
    </script>
</body>
</html>
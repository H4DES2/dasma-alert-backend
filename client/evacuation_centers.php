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
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <link rel="stylesheet" href="../css/client/navbar.css">
    <link rel="stylesheet" href="../css/client/evacuation_centers.css">
</head>
<body>

    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header style="margin-bottom: 30px;">
            <h1 style="color: #333; margin: 0; font-size: 2rem;">Barangay Command</h1>
            <p style="color: #666; margin-top: 5px; font-weight: 800;">
                Jurisdiction: <span style="color: #d32f2f;"><?php echo htmlspecialchars($assigned_brgy ?: 'Unassigned'); ?></span>
            </p>
        </header>

        <div class="table-container">
            <div class="header-section">
                <div>
                    <h2>Evacuation Centers</h2>
                    <p>Manage local shelters and track live occupancy.</p>
                </div>
                <button class="btn-action btn-add" onclick="openAddModal()">
                    <i class='bx bx-plus-circle'></i> Add Facility
                </button>
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
                            ?>
                            <tr class="clickable-row" onclick="handleRowClick(this, '<?php echo $row['id']; ?>', '<?php echo htmlspecialchars($row['name'], ENT_QUOTES); ?>', '<?php echo $row['current_occupants']; ?>', '<?php echo strtolower($row['status']); ?>')">
                                <td>
                                    <strong><?php echo htmlspecialchars($row['name']); ?></strong>
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

    <div id="addModal" class="modal">
        <div class="modal-content" style="max-width: 550px; position: relative;">
            <div class="close-modal" onclick="closeModal('addModal')"><i class='bx bx-x'></i></div>
            <div class="modal-header">
                <h3 style="color: #228b22;"><i class='bx bx-building-house'></i> New Facility</h3>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto; padding-right: 10px;">
                <label>Facility Name</label>
                <input type="text" id="addName" class="modal-input" placeholder="e.g. Brgy. Hall Covered Court">
                
                <!-- 🚀 RESTORED: Barangay Input Field (Read-only for Admins, Editable for Superadmins) -->
                <div style="display: flex; gap: 15px;">
                    <div style="flex: 1;">
                        <label>Barangay</label>
                        <input type="text" id="addBarangay" class="modal-input" placeholder="e.g. San Agustin">
                    </div>
                    <div style="flex: 1;">
                        <label>Max Capacity (Pax)</label>
                        <input type="number" id="addCapacity" class="modal-input" placeholder="e.g. 500">
                    </div>
                </div>
                
                <label>Pin Location on Map (Required)</label>
                <div id="add-evac-map" style="height: 200px; width: 100%; border-radius: 10px; margin-bottom: 15px; border: 1px solid #ccc; z-index: 10;"></div>
                <input type="hidden" id="addLat">
                <input type="hidden" id="addLng">
                
                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button class="modal-cancel-btn" onclick="closeModal('addModal')">Cancel</button>
                    <button class="btn-action btn-add" style="flex: 2; justify-content: center;" onclick="submitAdd()">Save Facility</button>
                </div>
            </div>
        </div>
    </div>

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
        const role = "<?php echo addslashes($role); ?>";
        const assignedBrgy = "<?php echo addslashes($assigned_brgy); ?>";
        let evacMap, evacMarker;

        function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
            document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
            document.getElementById('uniModalIcon').style.color = color;
            document.getElementById('uniModalTitle').innerText = title;
            document.getElementById('uniModalText').innerText = message;
            document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" class="btn-action btn-manage" style="flex: 1; justify-content: center; box-shadow: none; background:${color};">OK</button>`;
            document.getElementById('universalModal').style.display = 'flex';
        }

        function closeModal(id) { document.getElementById(id).style.display = 'none'; }

        // Mobile Row Click Logic
        function handleRowClick(row, id, name, occupants, status) {
            if (window.innerWidth <= 768) {
                const cells = row.querySelectorAll('td');
                if (cells.length < 4) return;

                document.getElementById('m-evac-title').innerHTML = cells[0].innerHTML.replace(/<i.*<\/i>/, ''); 
                
                let bodyEl = document.getElementById('m-evac-body');
                let html = '';
                html += `<div class="mobile-detail-box"><small class="mobile-label">Occupancy Tracker</small>${cells[1].innerHTML}</div>`;
                html += `<div class="mobile-detail-box"><small class="mobile-label">Status</small><div style="margin-top:5px;">${cells[2].innerHTML}</div></div>`;
                html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; flex-direction:column; gap:10px; width:100%;">
                            <button class="btn-action btn-manage" style="width: 100%; justify-content: center; padding: 12px; margin: 0;" data-id="${id}" data-name="${name}" data-occupants="${occupants}" data-status="${status}" onclick="openManageModal(this); closeModal('mobileEvacModal');">
                                <i class='bx bx-edit-alt'></i> Manage Data
                            </button>
                        </div></div>`;

                bodyEl.innerHTML = html;
                document.getElementById('mobileEvacModal').style.display = 'flex';
            }
        }

        function openAddModal() {
            if(role !== 'superadmin' && !assignedBrgy) {
                return customAlert("No Barangay", "Set your jurisdiction in your profile first.", "bx-error-circle", "#d32f2f");
            }

            document.getElementById('addName').value = '';
            document.getElementById('addCapacity').value = '';
            document.getElementById('addLat').value = '';
            document.getElementById('addLng').value = '';
            
            // 🚀 POPULATE AND LOCK BARANGAY FIELD FOR ADMINS
            document.getElementById('addBarangay').value = role === 'superadmin' ? '' : assignedBrgy;
            if(role !== 'superadmin') {
                document.getElementById('addBarangay').readOnly = true;
                document.getElementById('addBarangay').classList.add('readonly-input');
            }

            document.getElementById('addModal').style.display = 'flex';

            setTimeout(() => {
                if(!evacMap) {
                    const dasmaBounds = [ [14.2600, 120.9000], [14.3800, 120.9800] ];
                    evacMap = L.map('add-evac-map', { 
                        maxBounds: dasmaBounds, 
                        maxBoundsViscosity: 1.0, 
                        minZoom: 13 
                    }).setView([14.3294, 120.9368], 13);
                    
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(evacMap);
                    
                    evacMap.on('click', function(e) {
                        let lat = e.latlng.lat;
                        let lng = e.latlng.lng;
                        
                        if(evacMarker) { 
                            evacMarker.setLatLng(e.latlng); 
                        } else { 
                            let eIcon = L.divIcon({ html: `<i class='bx bxs-home-heart' style='color: #388e3c; font-size: 32px;'></i>`, className: 'custom-leaflet-icon', iconSize: [32, 32], iconAnchor: [16, 32] });
                            evacMarker = L.marker(e.latlng, {icon: eIcon}).addTo(evacMap); 
                        }
                        document.getElementById('addLat').value = lat;
                        document.getElementById('addLng').value = lng;
                    });
                } else {
                    evacMap.invalidateSize();
                    if(evacMarker) { evacMap.removeLayer(evacMarker); evacMarker = null; }
                }
            }, 300);
        }

        function submitAdd() {
            let name = document.getElementById('addName').value.trim();
            let brgy = document.getElementById('addBarangay').value.trim();
            let cap = document.getElementById('addCapacity').value;
            let lat = document.getElementById('addLat').value.trim();
            let lng = document.getElementById('addLng').value.trim();
            
            if(!name || !brgy || !cap || !lat || !lng) return customAlert("Fields Required", "Please fill out all fields and tap the map to pin the location.", "bx-error-circle", "#d32f2f");
            
            let fd = new FormData();
            fd.append('action', 'add_center');
            fd.append('name', name);
            fd.append('barangay', brgy);
            fd.append('capacity', cap);
            fd.append('latitude', lat);
            fd.append('longitude', lng);

            fetch('../admin/admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(text => {
                try {
                    let data = JSON.parse(text);
                    if(data.success) location.reload(); 
                    else customAlert("Error", data.message || "Could not add facility.", "bx-x-circle", "#d32f2f");
                } catch(e) {
                    if (text.trim() === 'success') {
                        location.reload();
                    } else {
                        customAlert("Server Error", text, "bx-error", "#d32f2f");
                    }
                }
            }).catch(e => {
                customAlert("Network Error", "Could not process request.", "bx-x-circle", "#d32f2f");
            });
        }
                function openManageModal(btn) {
            document.getElementById('manageTitle').innerHTML = `<i class='bx bx-edit-alt'></i> ${btn.getAttribute('data-name')}`;
            document.getElementById('manageId').value = btn.getAttribute('data-id');
            document.getElementById('manageOccupants').value = btn.getAttribute('data-occupants');
            document.getElementById('manageStatus').value = btn.getAttribute('data-status').toLowerCase();
            document.getElementById('manageModal').style.display = 'flex';
        }                    
        function submitManage() {
            let fd = new FormData();
            fd.append('action', 'update_evac_center'); 
            fd.append('id', document.getElementById('manageId').value);
            fd.append('occupants', document.getElementById('manageOccupants').value);
            fd.append('status', document.getElementById('manageStatus').value);
            
            fetch('../admin/admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(text => {
                try {
                    let data = JSON.parse(text);
                    if(data.success) location.reload(); 
                    else customAlert("Error", data.message || "Could not update facility.", "bx-x-circle", "#d32f2f");
                } catch(e) {
                    if (text.trim() === 'success') {
                        location.reload();
                    } else {
                        customAlert("Server Error", text, "bx-error", "#d32f2f");
                    }
                }
            }).catch(e => {
                customAlert("Network Error", "Could not process request.", "bx-x-circle", "#d32f2f");
            });
        }

        function handleDelete(id) {
            customConfirm("Delete Facility?", "This action is permanent and cannot be undone.", "bxs-trash", "#d32f2f", function() {
                let fd = new FormData();
                fd.append('action', 'delete_center');
                fd.append('id', id);
                
                fetch('../admin/admin_actions.php', { method: 'POST', body: fd })
                    .then(res => res.text())
                    .then(text => {
                        try {
                            let data = JSON.parse(text);
                            if(data.success) location.reload(); 
                            else customAlert("Error", data.message || "Failed to delete.", "bx-x-circle", "#d32f2f");
                        } catch(e) {
                            if (text.trim() === 'success') {
                                location.reload();
                            } else {
                                customAlert("Server Error", text, "bx-error", "#d32f2f");
                            }
                        }
                    })
                    .catch(e => {
                        customAlert("Network Error", "Could not process request.", "bx-error", "#d32f2f");
                    });
            });
        }
    </script>
</body>
</html>
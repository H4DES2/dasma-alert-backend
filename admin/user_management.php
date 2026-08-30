<?php
session_start();
require_once '../php/config.php';
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) { $auth = new Auth($conn); }
if (!$auth->isSuperAdmin()) {
    header("Location: ../php/login.php");
    exit();
}

$users_result = $conn->query("SELECT id, username, email, created_at, status, role FROM users ORDER BY created_at DESC");

$pending_users = [];
$superadmin_users = [];
$admin_users = [];
$responder_users = [];
$citizen_users = [];

while ($row = $users_result->fetch_assoc()) {
    if ($row['status'] === 'pending') {
        $pending_users[] = $row;
    } elseif ($row['role'] === 'superadmin') {
        $superadmin_users[] = $row;
    } elseif ($row['role'] === 'admin') {
        $admin_users[] = $row;
    } elseif ($row['role'] === 'responder') {
        $responder_users[] = $row;
    } else {
        $citizen_users[] = $row;
    }
}

$role_sections = [
    [
        'id' => 'pending',
        'title' => 'Pending Approval Requests',
        'icon' => 'bx-time-five',
        'color' => '#fbc02d',
        'users' => $pending_users
    ],
    [
        'id' => 'superadmin',
        'title' => 'Super Administrators',
        'icon' => 'bxs-crown',
        'color' => '#8e24aa',
        'users' => $superadmin_users
    ],
    [
        'id' => 'admin',
        'title' => 'Officials (Admins)',
        'icon' => 'bxs-badge-check',
        'color' => '#1976d2',
        'users' => $admin_users
    ],
    [
        'id' => 'responder',
        'title' => 'Emergency Responders',
        'icon' => 'bxs-ambulance',
        'color' => '#f57c00',
        'users' => $responder_users
    ],
    [
        'id' => 'citizen',
        'title' => 'App Citizens',
        'icon' => 'bxs-user',
        'color' => '#757575',
        'users' => $citizen_users
    ]
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>User Management | Command Center</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
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
            padding: 10px 25px 40px !important; 
            min-height: 100vh; 
        }

        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .header-flex h1 { font-weight: 800; color: #222; font-size: 1.8rem; letter-spacing: -0.5px; }

        .search-wrapper { position: relative; width: 380px; }
        .search-wrapper i { position: absolute; left: 18px; top: 50%; transform: translateY(-50%); color: #1976d2; font-size: 1.3rem; z-index: 2; }
        .search-input { 
            width: 100%; padding: 14px 15px 14px 50px; border-radius: 15px; border: 1px solid #e2e8f0;
            background: #ffffff; font-size: 1rem; font-weight: 700;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03);
            outline: none; transition: 0.3s; color: #333;
        }
        .search-input:focus { border-color: #1976d2; box-shadow: 0 8px 20px rgba(25, 118, 210, 0.1); }

        .role-box { 
            background: #ffffff; 
            padding: 30px; 
            border-radius: 25px; 
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.8);
            margin-bottom: 30px;
        }

        .role-box-header { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            margin-bottom: 20px; 
        }
        
        .role-box-title { 
            display: flex; 
            align-items: center; 
            gap: 10px; 
            font-size: 1.25rem; 
            font-weight: 800; 
        }

        .count-badge {
            background: #f1f5f9;
            color: #475569;
            font-size: 0.85rem;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 800;
        }

        .table-wrapper { 
            background: #f8f9fa; 
            border-radius: 18px; 
            border: 1px solid #edf2f7; 
            overflow: hidden; 
        }

        .data-table { width: 100%; border-collapse: collapse; min-width: 900px; }
        .data-table th { 
            position: sticky; 
            top: 0; 
            background: #ffffff; 
            z-index: 2; 
            padding: 16px 20px; 
            text-align: left; 
            border-bottom: 2px solid #f1f4f8; 
            color: #888; 
            text-transform: uppercase; 
            font-size: 0.75rem; 
            letter-spacing: 0.5px; 
        }
        .data-table td { padding: 16px 20px; text-align: left; border-bottom: 1px solid #f1f4f8; vertical-align: middle; color: #333; font-weight: 600; }
        .data-table tbody tr:hover { background-color: rgba(255, 255, 255, 0.5); }

        .status-badge { font-weight: 900; text-transform: uppercase; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 6px; }
        .role-badge { font-weight: 800; font-size: 0.85rem; }

        .btn-status { 
            padding: 9px 16px; border: none; border-radius: 12px; cursor: pointer; 
            font-size: 0.8rem; font-weight: 800; transition: 0.3s; color: white; 
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-status:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(0,0,0,0.15); }

        .role-selector {
            padding: 9px 12px; border-radius: 12px; border: 1px solid #e2e8f0;
            background: #ffffff; cursor: pointer; font-weight: 700; color: #333;
            outline: none; transition: 0.2s; box-shadow: 0 2px 5px rgba(0,0,0,0.02);
        }
        .role-selector:hover { border-color: #cbd5e0; }

        .empty-state { text-align: center; padding: 25px; color: #888; font-weight: 700; }

        .modal { display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.5); align-items: center; justify-content: center; backdrop-filter: blur(8px); }
        .modal-content { 
            background: #ffffff; padding: 45px; border-radius: 35px; width: 100%; max-width: 420px; text-align: center;
            box-shadow: 0 40px 100px -20px rgba(0,0,0,0.3); border: none; position: relative;
        }
        .modal-cancel-btn { flex: 1; padding: 12px; border-radius: 12px; cursor: pointer; border: 1px solid #ddd; background: transparent; font-weight: 800; color: #333; transition: 0.2s; }
        .modal-cancel-btn:hover { background: #f8f9fa; }

        html.global-dark-mode body, html.global-dark-mode .main-content { background: #0d1117 !important; color: #f0f6fc; }
        html.global-dark-mode .role-box, html.global-dark-mode .modal-content { 
            background: #161b22 !important; 
            box-shadow: 0 20px 45px rgba(0,0,0,0.5) !important; 
            border: 1px solid #30363d !important; 
        }
        html.global-dark-mode .table-wrapper, html.global-dark-mode .search-input { background: #0d1117 !important; border-color: #30363d !important; color: #fff; }
        html.global-dark-mode .data-table th { background: #161b22 !important; color: #8b949e !important; border-bottom: 2px solid #30363d; }
        html.global-dark-mode .data-table td { color: #c9d1d9 !important; border-bottom-color: #21262d; }
        html.global-dark-mode .role-selector { background: #0d1117; color: #fff; border-color: #30363d; }
        html.global-dark-mode h1, html.global-dark-mode p, html.global-dark-mode .role-box-title { color: #ffffff !important; }
        html.global-dark-mode .count-badge { background: #21262d; color: #8b949e; }
        html.global-dark-mode .modal-cancel-btn { color: white; border-color: #444; }

        /* 🚀 MOBILE RESPONSIVE OVERRIDES */
        .mobile-expand-icon { display: none; }
        .mobile-label { display: none; }

        /* 🚀 MOBILE RESPONSIVE OVERRIDES */
        .mobile-expand-icon { display: none; }
        .mobile-label { display: none; }

        @media (max-width: 768px) {
            .main-content { padding: 15px 15px 30px 15px !important; margin-top: 110px !important; }
            .header-flex { flex-direction: column; align-items: stretch; gap: 15px; }
            .search-wrapper { width: 100%; }
            .role-box { padding: 20px 15px; }
            
            /* Table Box Conversion */
            .table-wrapper { border: none !important; background: transparent !important; overflow: visible !important; }
            
            /* 🚀 FIX: Reset min-width so it doesn't overlap the box */
            .data-table, .data-table tbody { display: block; width: 100%; min-width: 100% !important; }
            .data-table thead { display: none; }
            
            .data-table tr.user-row {
                display: block; background: #ffffff; border: 1px solid #edf2f7; 
                border-radius: 16px; margin-bottom: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.03);
                cursor: pointer; position: relative; transition: transform 0.2s, background 0.2s;
            }
            .data-table tr.user-row:active { transform: scale(0.98); background: #f8f9fa; }
            
            html.global-dark-mode .data-table tr.user-row { background: #0d1117 !important; border-color: #30363d !important; }
            html.global-dark-mode .data-table tr.user-row:active { background: #21262d !important; }

            /* Hide columns 3-7 on Mobile */
            .data-table td:nth-child(n+3) { display: none; }
            .data-table td { display: block; width: 100%; padding: 10px 15px !important; border: none !important; }
            .data-table td:first-child { padding-bottom: 0 !important; }
            .data-table td:nth-child(2) { padding-top: 5px !important; padding-right: 35px !important; font-size: 1.15rem; }

            .mobile-expand-icon { display: block; position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #888; font-size: 1.5rem; }
            .mobile-label { display: block; font-size: 0.65rem; font-weight: 800; color: #888; margin-bottom: 6px; letter-spacing: 0.05em; text-transform: uppercase; }

            /* Fixed Close Button for Modals */
            .close-modal {
                position: absolute; top: 12px !important; right: 12px !important;
                background: rgba(0, 0, 0, 0.6) !important; color: #fff !important;
                border: 1px solid rgba(255,255,255,0.2) !important; width: 38px; height: 38px; border-radius: 50%;
                display: flex; align-items: center; justify-content: center; font-size: 1.4rem; z-index: 1000; cursor: pointer;
            }
            .modal-content { padding: 24px; }
        }

        .mobile-detail-box { background: #f8f9fa; padding: 16px; border-radius: 14px; border: 1px solid #edf2f7; margin-bottom: 12px; }
        html.global-dark-mode .mobile-detail-box { background: #0d1117; border-color: #30363d; }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="header-flex">
            <div>
                <h1>User Management</h1>
                <p style="color: #666; margin-top: 5px; font-weight: 700;">Control system access levels and account status grouped by role.</p>
            </div>
            
            <div class="search-wrapper">
                <i class='bx bx-search'></i>
                <input type="text" id="userSearchInput" class="search-input" onkeyup="filterUsers()" placeholder="Search users, roles, or email...">
            </div>
        </header>

        <?php foreach ($role_sections as $sec): ?>
            <?php if ($sec['id'] === 'pending' && count($sec['users']) === 0) continue; ?>

            <div class="role-box" id="box-<?php echo $sec['id']; ?>">
                <div class="role-box-header">
                    <div class="role-box-title" style="color: <?php echo $sec['color']; ?>;">
                        <i class='bx <?php echo $sec['icon']; ?>'></i>
                        <span><?php echo $sec['title']; ?></span>
                    </div>
                    <span class="count-badge"><?php echo count($sec['users']); ?> Accounts</span>
                </div>

                <div class="table-wrapper">
                    <?php if (count($sec['users']) > 0): ?>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Account (Username)</th>
                                    <th>Current Role</th>
                                    <th>Email Contact</th>
                                    <th>Joined Date</th>
                                    <th>Status</th>
                                    <th style="text-align: center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sec['users'] as $row): ?>
                                <tr class="user-row clickable-row" onclick="openMobileModal(this)">
                                    <td><small style="font-weight: 800; color: #888;">#<?php echo $row['id']; ?></small></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($row['username']); ?></strong>
                                        <i class='bx bx-chevron-right mobile-expand-icon'></i>
                                    </td>
                                    
                                    <td>
                                        <?php if($row['role'] === 'superadmin'): ?>
                                            <span class="role-badge" style="color: #8e24aa;"><i class='bx bxs-crown'></i> Superadmin</span>
                                        <?php elseif($row['role'] === 'admin'): ?>
                                            <span class="role-badge" style="color: #1976d2;"><i class='bx bxs-badge-check'></i> Official (Admin)</span>
                                        <?php elseif($row['role'] === 'responder'): ?>
                                            <span class="role-badge" style="color: #f57c00;"><i class='bx bxs-ambulance'></i> App Responder</span>
                                        <?php else: ?>
                                            <span class="role-badge" style="color: #757575;"><i class='bx bxs-user'></i> App Citizen</span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <td><i class='bx bx-envelope' style="color: #888; margin-right: 5px;"></i> <?php echo htmlspecialchars($row['email'] ?? 'N/A'); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($row['created_at'])); ?></td>
                                    
                                    <td>
                                        <?php 
                                            $sColor = '#757575';
                                            if($row['status'] === 'Active') $sColor = '#388e3c';
                                            elseif($row['status'] === 'Suspended') $sColor = '#d32f2f';
                                            elseif($row['status'] === 'pending') $sColor = '#fbc02d'; 
                                        ?>
                                        <span class="status-badge" style="color: <?php echo $sColor; ?>;">
                                            <i class='bx bxs-circle' style="font-size: 0.6rem;"></i> <?php echo strtoupper($row['status']); ?>
                                        </span>
                                    </td>

                                    <td style="display: flex; gap: 10px; justify-content: center; align-items: center;">
                                        <?php if ((int)$row['id'] === (int)$_SESSION['user_id']): ?>
                                            <span style="color: #8e24aa; font-size: 0.8rem; font-weight: 700;"><i class='bx bxs-user-check'></i> Your Account</span>
                                        <?php elseif ($row['status'] === 'pending'): ?>
                                            <button onclick="event.stopPropagation(); toggleUserStatus(<?php echo $row['id']; ?>, 'pending')" class="btn-status" style="background: #2e7d32;">
                                                <i class='bx bx-check'></i> Approve
                                            </button>
                                            <button onclick="event.stopPropagation(); rejectUser(<?php echo $row['id']; ?>)" class="btn-status" style="background: #c62828;">
                                                <i class='bx bx-x'></i> Reject
                                            </button>
                                        <?php else: ?>
                                            <button onclick="event.stopPropagation(); toggleUserStatus(<?php echo $row['id']; ?>, '<?php echo $row['status']; ?>')" 
                                                    class="btn-status"
                                                    style="background: <?php echo $row['status'] === 'Active' ? '#d32f2f' : '#388e3c'; ?>;">
                                                <?php echo $row['status'] === 'Active' ? "<i class='bx bx-user-x'></i> Suspend" : "<i class='bx bx-user-check'></i> Activate"; ?>
                                            </button>

                                            <select onclick="event.stopPropagation()" onchange="if(this.value) handleRoleChange(<?php echo $row['id']; ?>, this.value, '<?php echo htmlspecialchars($row['username']); ?>')" class="role-selector">
                                                <option value="" disabled selected>Change Role...</option>
                                                <?php 
                                                $currentRole = strtolower(trim($row['role'])); 
                                                if ($currentRole === 'superadmin') {
                                                    echo '<option value="admin">Demote to Admin</option><option value="responder">Demote to Responder</option><option value="user">Demote to Citizen</option>';
                                                } elseif ($currentRole === 'admin') {
                                                    echo '<option value="user">Demote to Citizen</option><option value="responder">Change to Responder</option><option value="superadmin">Promote to Superadmin</option>';
                                                } elseif ($currentRole === 'responder') {
                                                    echo '<option value="user">Demote to Citizen</option><option value="admin">Promote to Admin</option><option value="superadmin">Promote to Superadmin</option>';
                                                } else {
                                                    echo '<option value="responder">Promote to Responder</option><option value="admin">Promote to Admin</option><option value="superadmin">Promote to Superadmin</option>';
                                                }
                                                ?>
                                            </select>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">No registered accounts in this role category.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </main>

    <!-- MODAL: Universal Confirmation -->
    <div id="universalModal" class="modal">
        <div class="modal-content">
            <i id="uniModalIcon" class='bx' style="font-size: 5rem; margin-bottom: 20px;"></i>
            <h3 id="uniModalTitle" style="margin-bottom: 15px; font-size: 1.6rem; font-weight: 900;">Confirm</h3>
            <p id="uniModalText" style="margin-bottom: 35px; color: #666; font-weight: 700; line-height: 1.5;"></p>
            <div style="display: flex; gap: 15px;" id="uniModalButtons"></div>
        </div>
    </div>

    <!-- MODAL: Mobile User Details -->
    <div id="mobileUserModal" class="modal" style="z-index: 10005;">
        <div class="modal-content" style="max-width: 90%; text-align: left;">
            <div class="close-modal" onclick="document.getElementById('mobileUserModal').style.display='none'"><i class='bx bx-x'></i></div>
            <h3 id="m-user-title" style="margin-bottom: 16px; font-weight: 900; font-size: 1.3rem; padding-right: 30px; color: var(--text-primary);"></h3>
            <div id="m-user-body" style="display: flex; flex-direction: column;"></div>
        </div>
    </div>

    <script>
    function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal()" class="btn-status" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">OK</button>`;
        document.getElementById('universalModal').style.display = 'flex';
    }

    function customConfirm(title, message, iconClass, color, confirmCallback, cancelCallback = null) {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        
        let cancelBtn = `<button id="uniCancelBtn" class="modal-cancel-btn" style="height: 50px;">Cancel</button>`;
        let confirmBtn = `<button id="uniConfirmBtn" class="btn-status" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">Proceed</button>`;
        
        document.getElementById('uniModalButtons').innerHTML = cancelBtn + confirmBtn;
        document.getElementById('universalModal').style.display = 'flex';
        
        document.getElementById('uniCancelBtn').onclick = function() { 
            closeModal(); 
            if (cancelCallback) cancelCallback(); 
        };
        
        document.getElementById('uniConfirmBtn').onclick = function() { 
            closeModal(); 
            confirmCallback(); 
        };
    }

    function closeModal() { 
        document.getElementById('universalModal').style.display = 'none'; 
    }

    function toggleUserStatus(userId, currentStatus) {
        const actionText = (currentStatus === 'Active') ? 'suspend' : 'activate/approve';
        const color = (currentStatus === 'Active') ? '#d32f2f' : '#228b22';
        const icon = (currentStatus === 'Active') ? 'bx-user-x' : 'bx-user-check';

        customConfirm("Confirm Action", `Are you sure you want to ${actionText} this user?`, icon, color, function() {
            let fd = new FormData();
            fd.append('action', 'toggle_user_status');
            fd.append('user_id', userId);
            fd.append('current_status', currentStatus);

            fetch('admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if(data.success) location.reload();
                else customAlert("Error", data.message, "bx-x-circle", "#d32f2f");
            });
        });
    }

    function handleRoleChange(userId, newRole, username) {
        let roleDisplay = newRole === 'user' ? 'CITIZEN' : newRole.toUpperCase();
        
        customConfirm(
            "Change User Role", 
            `Change ${username}'s access level to ${roleDisplay}?`, 
            "bx-shield-quarter", 
            "#1976d2", 
            function() {
                let fd = new FormData();
                fd.append('action', 'update_role');
                fd.append('user_id', userId);
                fd.append('role', newRole);

                fetch('admin_actions.php', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if(data.success) location.reload();
                    else {
                        customAlert("Error", data.message, "bx-x-circle", "#d32f2f");
                        setTimeout(() => location.reload(), 1500);
                    }
                });
            }, 
            function() { location.reload(); }
        );
    }

    function rejectUser(userId) {
        customConfirm("Reject Admin", "Are you sure you want to reject and delete this registration?", "bx-trash", "#d32f2f", function() {
            let fd = new FormData();
            fd.append('action', 'delete_user');
            fd.append('user_id', userId);

            fetch('admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if(data.success) location.reload();
                else customAlert("Error", data.message, "bx-x-circle", "#d32f2f");
            });
        });
    }

    function filterUsers() {
        let input = document.getElementById('userSearchInput');
        let filter = input.value.toLowerCase();
        let boxes = document.querySelectorAll('.role-box');

        boxes.forEach(box => {
            let rows = box.querySelectorAll('tbody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                let text = row.innerText.toLowerCase();
                let matches = text.includes(filter);
                row.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            box.style.display = (filter === '' || visibleCount > 0) ? '' : 'none';
        });
    }

    // -----------------------------------------------------
    // MOBILE MODAL LOGIC
    // -----------------------------------------------------
    function openMobileModal(row) {
        if (window.innerWidth > 768) return; 

        const cells = row.querySelectorAll('td');
        if (cells.length < 7) return;

        const titleEl = document.getElementById('m-user-title');
        const bodyEl = document.getElementById('m-user-body');

        // Extract Title from Username column (hide chevron)
        titleEl.innerHTML = cells[1].innerHTML; 
        
        let html = '';
        html += `<div class="mobile-detail-box"><small class="mobile-label">User ID</small>${cells[0].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Current Role</small>${cells[2].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Email Contact</small>${cells[3].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Joined Date</small>${cells[4].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Status</small>${cells[5].innerHTML}</div>`;
        html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; flex-direction:column; gap:10px; width:100%;">${cells[6].innerHTML}</div></div>`;

        bodyEl.innerHTML = html;

        // Force buttons and dropdowns inside modal to stretch
        let actionContainer = bodyEl.querySelector('.m-actions-container');
        if (actionContainer) {
            let buttons = actionContainer.querySelectorAll('button, select, span');
            buttons.forEach(el => {
                if(el.tagName === 'BUTTON' || el.tagName === 'SELECT') {
                    el.style.width = '100%';
                    if (el.tagName === 'BUTTON') el.style.justifyContent = 'center';
                }
            });
        }

        // Hide expanding arrows inside the modal copy
        titleEl.querySelectorAll('.mobile-expand-icon').forEach(icon => icon.style.display = 'none');
        bodyEl.querySelectorAll('.mobile-expand-icon').forEach(icon => icon.style.display = 'none');

        document.getElementById('mobileUserModal').style.display = 'flex';
    }
    </script>
</body>
</html>
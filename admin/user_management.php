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
    <link rel="stylesheet" href="../css/admin/navbar.css">
    <link rel="stylesheet" href="../css/admin/user_management.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="header-flex">
            <div>
                <h1>User Management</h1>
                <p style="color: var(--text-secondary); margin-top: 5px; font-weight: 700; font-size: clamp(0.8rem, 2vw, 0.95rem);">Control system access levels and account status grouped by role.</p>
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

                                    <td class="action-cell">
                                        <div class="action-btn-group">
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
                                        </div>
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
            <i id="uniModalIcon" class='bx' style="font-size: 4.5rem; margin-bottom: 16px;"></i>
            <h3 id="uniModalTitle" style="margin-bottom: 12px; font-size: 1.4rem; font-weight: 900;">Confirm</h3>
            <p id="uniModalText" style="margin-bottom: 24px; color: var(--text-secondary); font-weight: 700; line-height: 1.5; font-size: 0.9rem;"></p>
            <div style="display: flex; gap: 12px;" id="uniModalButtons"></div>
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
<script src="../js/admin/user_management.js?v=<?= filemtime('../js/admin/user_management.js') ?>"></script>
</body>
</html>
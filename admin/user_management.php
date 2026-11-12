<?php
require_once '../php/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) { 
    $auth = new Auth($conn); 
}

if (!$auth->isSuperAdmin()) {
    header("Location: ../php/login.php");
    exit();
}

$current_admin_id = (int)($_SESSION['user_id'] ?? 0);
session_write_close();

// Join user_profiles to retrieve full name and contact number
$users_result = $conn->query("
    SELECT 
        u.id, 
        u.username, 
        u.first_name, 
        u.last_name, 
        u.email, 
        u.barangay, 
        u.created_at, 
        u.status, 
        u.role,
        (SELECT p.phone_number FROM user_profiles p WHERE p.user_id = u.id ORDER BY p.id DESC LIMIT 1) AS phone_number
    FROM users u
    ORDER BY u.created_at DESC
");

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

$total_users_count = count($pending_users) + count($superadmin_users) + count($admin_users) + count($responder_users) + count($citizen_users);

$role_sections = [
    [
        'id' => 'pending',
        'tab_label' => 'Pending Approvals',
        'title' => 'Pending Approval Requests',
        'subtitle' => 'New official or responder registrations awaiting command verification.',
        'icon' => 'bx-time-five',
        'color' => '#d97706',
        'badge_bg' => 'rgba(245, 158, 11, 0.12)',
        'users' => $pending_users
    ],
    [
        'id' => 'superadmin',
        'tab_label' => 'Superadmins',
        'title' => 'Super Administrators',
        'subtitle' => 'Full-privilege system architects and command center administrators.',
        'icon' => 'bxs-crown',
        'color' => '#8b5cf6',
        'badge_bg' => 'rgba(139, 92, 246, 0.12)',
        'users' => $superadmin_users
    ],
    [
        'id' => 'admin',
        'tab_label' => 'Officials',
        'title' => 'Officials (Admins)',
        'subtitle' => 'Barangay and CDRRMO dispatchers managing active incidents.',
        'icon' => 'bxs-badge-check',
        'color' => '#2563eb',
        'badge_bg' => 'rgba(37, 99, 235, 0.12)',
        'users' => $admin_users
    ],
    [
        'id' => 'responder',
        'tab_label' => 'Responders',
        'title' => 'Emergency Responders',
        'subtitle' => 'Field response personnel connected to the Dasma Alert responder app.',
        'icon' => 'bxs-ambulance',
        'color' => '#ea580c',
        'badge_bg' => 'rgba(234, 88, 12, 0.12)',
        'users' => $responder_users
    ],
    [
        'id' => 'citizen',
        'tab_label' => 'Citizens',
        'title' => 'App Citizens',
        'subtitle' => 'Registered residents submitting verified emergency reports.',
        'icon' => 'bxs-user',
        'color' => '#475569',
        'badge_bg' => 'rgba(100, 116, 139, 0.12)',
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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../css/admin/dashboard.css">
    <link rel="stylesheet" href="../css/admin/navbar.css?v=<?= filemtime('../css/admin/navbar.css') ?>">
    <link rel="stylesheet" href="../css/admin/user_management.css?v=<?= filemtime('../css/admin/user_management.css') ?>">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content um-main">
        <!-- TOP COMMAND BAR (MATCHING CONTROLS & ANALYTICS) -->
        <header class="um-topbar">
            <div>
                <h1 class="um-title"><i class='bx bxs-user-account'></i> User Management</h1>
                <p class="um-subtitle">Control system access levels, role permissions, and account status across Dasma Alert.</p>
            </div>
            
            <div class="search-wrapper">
                <i class='bx bx-search'></i>
                <input type="text" id="userSearchInput" class="search-input" onkeyup="filterUsers(); syncUserTabCounts();" placeholder="Search username, full name, barangay, or email...">
            </div>
        </header>

        <!-- SEGMENTED WORKSPACE TABS -->
        <div class="um-tabs" role="tablist">
            <button type="button" class="ctrl-tab active" data-tab="all" onclick="switchUserTab('all', this)">
                <i class='bx bx-grid-alt'></i> All Accounts
                <span class="tab-badge" id="tab-badge-all"><?= $total_users_count ?></span>
            </button>
            <?php foreach ($role_sections as $sec): ?>
                <?php if ($sec['id'] === 'pending' && count($sec['users']) === 0) continue; ?>
                <button type="button" class="ctrl-tab <?= $sec['id'] === 'pending' ? 'tab-alert' : '' ?>" data-tab="<?= $sec['id'] ?>" onclick="switchUserTab('<?= $sec['id'] ?>', this)">
                    <i class='bx <?= $sec['icon'] ?>'></i> <?= $sec['tab_label'] ?>
                    <span class="tab-badge" id="tab-badge-<?= $sec['id'] ?>"><?= count($sec['users']) ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- ROLE PANELS -->
        <div id="userSectionsContainer" class="um-sections-stack view-all">
            <?php foreach ($role_sections as $sec): ?>
                <?php if ($sec['id'] === 'pending' && count($sec['users']) === 0) continue; ?>

                <section class="sitting-panel role-box" id="box-<?= $sec['id'] ?>" data-role-section="<?= $sec['id'] ?>">
                    <div class="role-box-header">
                        <div class="panel-title-group">
                            <div class="panel-icon-badge" style="background: <?= $sec['badge_bg'] ?>; color: <?= $sec['color'] ?>;">
                                <i class='bx <?= $sec['icon'] ?>'></i>
                            </div>
                            <div>
                                <h2 class="role-box-title" style="color: <?= $sec['color'] ?>;">
                                    <?= $sec['title'] ?>
                                </h2>
                                <small class="hdr-meta"><?= $sec['subtitle'] ?></small>
                            </div>
                        </div>
                        <span class="count-badge"><?= count($sec['users']) ?> Accounts</span>
                    </div>

                    <div class="table-wrapper um-table-box">
                        <?php if (count($sec['users']) > 0): ?>
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Account (Username)</th>
                                        <th>Full Name</th>
                                        <th>Contact Number</th>
                                        <th>Email Contact</th>
                                        <th>Current Role</th>
                                        <th>Joined Date</th>
                                        <th>Status</th>
                                        <th style="text-align: center;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sec['users'] as $row): 
                                        $full_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                                        $phone_num = trim($row['phone_number'] ?? '');
                                        $brgy_val  = trim($row['barangay'] ?? '');
                                    ?>
                                    <tr class="user-row clickable-row" onclick="openMobileModal(this)">
                                        <td><span class="id-chip">#<?= $row['id'] ?></span></td>
                                        <td>
                                            <strong class="username-text"><?= htmlspecialchars($row['username']) ?></strong>
                                            <i class='bx bx-chevron-right mobile-expand-icon'></i>
                                        </td>
                                        <td>
                                            <?php if (!empty($full_name)): ?>
                                                <strong class="fullname-text"><?= htmlspecialchars($full_name) ?></strong>
                                            <?php else: ?>
                                                <span class="muted-italic">Not Set</span>
                                            <?php endif; ?>
                                            <?php if (!empty($brgy_val)): ?>
                                                <small class="brgy-subtag">
                                                    <i class='bx bxs-map-pin'></i> Brgy. <?= htmlspecialchars($brgy_val) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($phone_num)): ?>
                                                <span class="contact-pill">
                                                    <i class='bx bx-phone'></i> <?= htmlspecialchars($phone_num) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="muted-italic">No Number</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="email-text">
                                                <i class='bx bx-envelope'></i> <?= htmlspecialchars($row['email'] ?? 'N/A') ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if($row['role'] === 'superadmin'): ?>
                                                <span class="role-badge role-superadmin"><i class='bx bxs-crown'></i> Superadmin</span>
                                            <?php elseif($row['role'] === 'admin'): ?>
                                                <span class="role-badge role-admin"><i class='bx bxs-badge-check'></i> Official (Admin)</span>
                                            <?php elseif($row['role'] === 'responder'): ?>
                                                <span class="role-badge role-responder"><i class='bx bxs-ambulance'></i> App Responder</span>
                                            <?php else: ?>
                                                <span class="role-badge role-citizen"><i class='bx bxs-user'></i> App Citizen</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="date-text"><?= date('M d, Y', strtotime($row['created_at'])) ?></span></td>
                                        <td>
                                            <?php 
                                                $st = strtolower(trim($row['status']));
                                                $statusCls = 'st-neutral';
                                                if ($st === 'active') $statusCls = 'st-active';
                                                elseif ($st === 'suspended') $statusCls = 'st-suspended';
                                                elseif ($st === 'pending') $statusCls = 'st-pending';
                                            ?>
                                            <span class="status-badge <?= $statusCls ?>">
                                                <i class='bx bxs-circle'></i> <?= strtoupper($row['status']) ?>
                                            </span>
                                        </td>
                                        <td class="action-cell">
                                            <div class="action-btn-group">
                                                <?php if ((int)$row['id'] === $current_admin_id): ?>
                                                    <span class="self-account-tag"><i class='bx bxs-user-check'></i> Your Account</span>
                                                <?php elseif ($row['status'] === 'pending'): ?>
                                                    <button onclick="event.stopPropagation(); toggleUserStatus(<?= $row['id'] ?>, 'pending')" class="btn-status bg-green">
                                                        <i class='bx bx-check'></i> Approve
                                                    </button>
                                                    <button onclick="event.stopPropagation(); deleteUserAccount(<?= $row['id'] ?>, '<?= htmlspecialchars($row['username'], ENT_QUOTES) ?>')" class="btn-status bg-red">
                                                        <i class='bx bx-trash'></i> Delete
                                                    </button>
                                                <?php else: ?>
                                                    <button onclick="event.stopPropagation(); toggleUserStatus(<?= $row['id'] ?>, '<?= $row['status'] ?>')" 
                                                            class="btn-status <?= $row['status'] === 'Active' ? 'bg-orange' : 'bg-green' ?>">
                                                        <?= $row['status'] === 'Active' ? "<i class='bx bx-user-x'></i> Suspend" : "<i class='bx bx-user-check'></i> Activate" ?>
                                                    </button>

                                                    <select onclick="event.stopPropagation()" onchange="if(this.value) handleRoleChange(<?= $row['id'] ?>, this.value, '<?= htmlspecialchars($row['username'], ENT_QUOTES) ?>')" class="role-selector">
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

                                                    <button onclick="event.stopPropagation(); deleteUserAccount(<?= $row['id'] ?>, '<?= htmlspecialchars($row['username'], ENT_QUOTES) ?>')" 
                                                            class="action-circle bg-red" 
                                                            title="Permanently Delete User">
                                                        <i class='bx bx-trash'></i>
                                                    </button>
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
                </section>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- MODAL: Universal Confirmation -->
    <div id="universalModal" class="modal">
        <div class="modal-content">
            <i id="uniModalIcon" class='bx' style="font-size: 4.2rem; margin-bottom: 14px;"></i>
            <h3 id="uniModalTitle" style="margin-bottom: 10px; font-size: 1.35rem; font-weight: 800;">Confirm</h3>
            <p id="uniModalText" style="margin-bottom: 22px; color: var(--text-secondary); font-weight: 600; line-height: 1.5; font-size: 0.9rem;"></p>
            <div style="display: flex; gap: 12px;" id="uniModalButtons"></div>
        </div>
    </div>

    <!-- MODAL: Mobile User Details -->
    <div id="mobileUserModal" class="modal" style="z-index: 10005;">
        <div class="modal-content" style="max-width: 92%; text-align: left;">
            <div class="close-modal" onclick="document.getElementById('mobileUserModal').style.display='none'"><i class='bx bx-x'></i></div>
            <h3 id="m-user-title" style="margin-bottom: 16px; font-weight: 800; font-size: 1.25rem; padding-right: 32px; color: var(--text-primary);"></h3>
            <div id="m-user-body" style="display: flex; flex-direction: column;"></div>
        </div>
    </div>

    <script>
        function switchUserTab(tabKey, btn) {
            document.querySelectorAll('.ctrl-tab').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');

            const container = document.getElementById('userSectionsContainer');
            const boxes = document.querySelectorAll('.role-box[data-role-section]');

            if (tabKey === 'all') {
                container.classList.add('view-all');
                container.classList.remove('view-single');
                boxes.forEach(b => b.classList.remove('tab-hidden'));
            } else {
                container.classList.remove('view-all');
                container.classList.add('view-single');
                boxes.forEach(b => {
                    b.classList.toggle('tab-hidden', b.getAttribute('data-role-section') !== tabKey);
                });
            }
            sessionStorage.setItem('dasma_um_tab', tabKey);
        }

        function syncUserTabCounts() {
            let totalVisible = 0;
            document.querySelectorAll('.role-box[data-role-section]').forEach(box => {
                const roleKey = box.getAttribute('data-role-section');
                const visibleRows = Array.from(box.querySelectorAll('tr.user-row')).filter(r => r.style.display !== 'none').length;
                totalVisible += visibleRows;
                const badge = document.getElementById('tab-badge-' + roleKey);
                if (badge) badge.textContent = visibleRows;
            });
            const allBadge = document.getElementById('tab-badge-all');
            if (allBadge) allBadge.textContent = totalVisible;
        }

        document.addEventListener('DOMContentLoaded', () => {
            const saved = sessionStorage.getItem('dasma_um_tab');
            if (saved) {
                const btn = document.querySelector(`.ctrl-tab[data-tab="${saved}"]`);
                if (btn) switchUserTab(saved, btn);
            }
        });
    </script>
    <script src="../js/admin/user_management.js?v=<?= filemtime('../js/admin/user_management.js') ?>" defer></script>
</body>
</html>
<?php
require_once '../php/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../php/auth.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit();
}

$s_user_id  = (int)($_SESSION['user_id'] ?? 0);
$s_barangay = $_SESSION['barangay'] ?? 'Barangay';
if (strcasecmp($s_barangay, 'Burol Main') === 0) { $s_barangay = 'Burol'; }

// Announcements
$ann_stmt = $conn->prepare("SELECT * FROM announcements WHERE author_id = ? OR author_id IS NULL ORDER BY created_at DESC");
$ann_stmt->bind_param("i", $s_user_id);
$ann_stmt->execute();
$announcements = $ann_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$ann_stmt->close();

// Geofenced Broadcast History for this Barangay Admin
$chk_t = $conn->query("SHOW COLUMNS FROM broadcasts LIKE 'target_barangay'");
if ($chk_t && $chk_t->num_rows === 0) {
    $conn->query("ALTER TABLE broadcasts ADD COLUMN target_barangay VARCHAR(100) NULL DEFAULT NULL");
}

$b_stmt = $conn->prepare("
    SELECT *, DATE_FORMAT(created_at, '%b %d, %Y - %h:%i %p') as date_str 
    FROM broadcasts 
    WHERE target_barangay IS NULL 
       OR LOWER(TRIM(target_barangay)) = LOWER(?)
       OR LOWER(TRIM(target_barangay)) = 'all'
    ORDER BY created_at DESC 
    LIMIT 30
");
$b_stmt->bind_param("s", $s_barangay);
$b_stmt->execute();
$broadcast_history = $b_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$b_stmt->close();

$current_page = 'controls.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Controls | <?= htmlspecialchars($s_barangay) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../css/client/dashboard.css">
    <link rel="stylesheet" href="../css/client/navbar.css">
    <link rel="stylesheet" href="../css/client/controls.css?v=<?= file_exists('../css/client/controls.css') ? filemtime('../css/client/controls.css') : time() ?>">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content" style="padding-top: 105px; max-width: 1300px; margin: 0 auto; padding-left: 20px; padding-right: 20px;">
        <h1 style="margin-bottom: 25px; font-weight: 800; display:flex; align-items:center; gap:8px;">
            <i class='bx bx-slider-alt'></i> Barangay Controls &mdash; <?= htmlspecialchars($s_barangay) ?>
        </h1>
        
        <div class="dashboard-split-layout" style="display: flex; gap: 24px; flex-wrap: wrap;">
            
            <!-- LEFT COLUMN: Broadcast Trigger & Broadcast History -->
            <div style="flex: 1; min-width: 320px; display: flex; flex-direction: column; gap: 24px;">
                
                <!-- Broadcast Form -->
                <div class="sitting-panel" style="background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); border: 1px solid #edf2f7;">
                    <div class="panel-header" style="margin-bottom: 15px;">
                        <h2 style="font-weight:800; font-size:1.15rem; margin:0; display:flex; align-items:center; gap:8px;">
                            <i class='bx bx-broadcast' style="color:#d32f2f;"></i> Geofenced Emergency Broadcast
                        </h2>
                        <small style="color:#64748b; font-weight:700;">Target: Citizens of <?= htmlspecialchars($s_barangay) ?></small>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:12px;">
                        <input type="text" id="globalBroadcastTitle" placeholder="ALERT TITLE" class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px; box-sizing: border-box;">
                        <textarea id="globalBroadcastMessage" rows="3" placeholder="Emergency message for <?= htmlspecialchars($s_barangay) ?> residents..." class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px; resize:none; box-sizing: border-box;"></textarea>
                        <select id="globalBroadcastSeverity" class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px; box-sizing: border-box;">
                            <option value="info">INFO</option>
                            <option value="warning">WARNING</option>
                            <option value="critical">CRITICAL</option>
                        </select>
                        <button onclick="submitGlobalBroadcast()" style="background:#d32f2f; color:white; width:100%; padding:14px; border:none; border-radius:8px; font-weight:800; cursor:pointer;">
                            TRANSMIT TO <?= strtoupper(htmlspecialchars($s_barangay)) ?>
                        </button>
                    </div>
                </div>

                <!-- Broadcast History -->
                <div class="sitting-panel" style="background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); border: 1px solid #edf2f7;">
                    <div class="panel-header" style="margin-bottom: 15px; display:flex; justify-content:space-between; align-items:center;">
                        <h2 style="font-weight:800; font-size:1.15rem; margin:0; display:flex; align-items:center; gap:8px;">
                            <i class='bx bx-history' style="color:#0284c7;"></i> Broadcast History[cite: 24]
                        </h2>
                    </div>
                    <div class="table-scroll-wrapper" style="max-height: 320px; overflow-y:auto; border: 1px solid #edf2f7; border-radius: 12px; padding: 0 10px;">
                        <table class="triage-table">
                            <thead>
                                <tr>
                                    <th>Title & Date</th>[cite: 24]
                                    <th>Scope</th>
                                    <th>Severity</th>[cite: 24]
                                    <th style="text-align: center;">Control</th>[cite: 24]
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($broadcast_history)): ?>
                                    <tr><td colspan="4" style="text-align:center; color:#94a3b8; padding: 20px;">No broadcast history found.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($broadcast_history as $b): ?>
                                <tr>
                                    <td>
                                        <strong style="color:#1e293b; font-size:0.9rem;"><?= htmlspecialchars($b['title']) ?></strong><br>
                                        <small style="color:#94a3b8; font-weight:600;"><?= $b['date_str'] ?></small>[cite: 24]
                                    </td>
                                    <td>
                                        <small style="font-weight:700; color: <?= empty($b['target_barangay']) ? '#0284c7' : '#d97706' ?>;">
                                            <?= empty($b['target_barangay']) ? 'City-Wide' : htmlspecialchars($b['target_barangay']) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= strtolower($b['severity']) ?>" style="font-size:0.75rem; padding: 4px 8px; border-radius: 6px; font-weight:800;">
                                            <?= strtoupper($b['severity']) ?>[cite: 24]
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($b['is_active']): ?>
                                            <button class="pill-btn bg-red" style="padding: 4px 10px; font-size: 0.75rem;" onclick="stopBroadcast(<?= $b['id'] ?>)">
                                                <i class='bx bx-stop-circle'></i> STOP[cite: 24]
                                            </button>
                                        <?php else: ?>
                                            <span style="color:#94a3b8; font-weight:700; font-size:0.75rem;">ENDED</span>[cite: 24]
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN: Announcements -->
            <div style="flex: 1.2; min-width: 340px;">
                <div class="sitting-panel" style="background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); border: 1px solid #edf2f7;">
                    <div class="panel-header" style="margin-bottom: 15px; display:flex; justify-content:space-between; align-items:center;">
                        <h2 style="font-weight:800; font-size:1.15rem; margin:0; display:flex; align-items:center; gap:8px;">
                            <i class='bx bxs-bell-ring' style="color:#f59e0b;"></i> Barangay Announcements[cite: 27]
                        </h2>
                        <button class="pill-btn" onclick="openAnnouncementModal()"><i class='bx bx-plus'></i> New Post</button>[cite: 27]
                    </div>
                    <div class="table-scroll-wrapper" style="max-height: 520px; overflow-y:auto; border: 1px solid #edf2f7; border-radius: 12px; padding: 0 10px;">
                        <table class="triage-table">
                            <thead>
                                <tr>
                                    <th>Details</th>[cite: 27]
                                    <th style="text-align: center; width: 100px;">Actions</th>[cite: 27]
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($announcements)): ?>
                                <tr>
                                    <td colspan="2" style="text-align:center; color:#94a3b8; padding: 20px;">No announcements published yet.</td>[cite: 27]
                                </tr>
                                <?php endif; ?>
                                <?php foreach($announcements as $ann): ?>
                                <tr>
                                    <td>
                                        <strong style="color:#3b82f6; font-size:0.95rem;"><?= htmlspecialchars($ann['title']) ?></strong><br>[cite: 27]
                                        <small style="color:#94a3b8; font-weight:600;"><?= date('M d, Y', strtotime($ann['created_at'])) ?></small>[cite: 27]
                                    </td>
                                    <td style="text-align: center; white-space: nowrap;">
                                        <?php if ($ann['author_id'] == $s_user_id || empty($ann['author_id'])): ?>
                                        <button class="action-circle bg-green" onclick="openAnnouncementModal(<?= $ann['id'] ?>, '<?= addslashes($ann['title']) ?>', '<?= addslashes(str_replace(["\r","\n"], ['\r','\n'], $ann['message'])) ?>')"><i class='bx bx-edit'></i></button>[cite: 27]
                                        <button class="action-circle bg-red" onclick="deleteAnnouncement(<?= $ann['id'] ?>)"><i class='bx bx-trash'></i></button>[cite: 27]
                                        <?php else: ?>
                                        <small style="color:#cbd5e1;">City-wide</small>[cite: 27]
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <!-- Announcement Create/Edit Modal -->
        <div id="announcementModal" class="modal">[cite: 27]
            <div class="modal-content" style="max-width: 500px;">[cite: 27]
                <div class="close-modal" onclick="closeModal('announcementModal')"><i class='bx bx-x'></i></div>[cite: 27]
                <h3 id="annModalTitle" style="margin-top:0; margin-bottom:15px; font-weight:800;">Announcement</h3>[cite: 27]
                <input type="hidden" id="ann_id">[cite: 27]
                <input type="text" id="ann_title" class="nav-input-field" style="width:100%; padding:12px; margin-bottom:10px; border: 1px solid #e2e8f0; border-radius:8px; box-sizing: border-box;" placeholder="Title">[cite: 27]
                <textarea id="ann_message" class="nav-input-field" rows="5" style="width:100%; padding:12px; margin-bottom:10px; border: 1px solid #e2e8f0; border-radius:8px; resize:none; box-sizing: border-box;" placeholder="Message content..."></textarea>[cite: 27]
                <input type="file" id="ann_image" style="margin-bottom: 20px;">[cite: 27]
                <button class="pill-btn" style="width:100%; justify-content:center; padding:12px; font-size:1rem;" onclick="saveAnnouncement()">Save Announcement</button>[cite: 27]
            </div>
        </div>
    </main>

    <script src="../js/client/controls.js"></script>[cite: 27]
</body>
</html>
<?php
require_once '../php/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../php/auth.php';

if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin', 'superadmin'])) {
    header('Location: dashboard.php');
    exit();
}

$s_user_id = (int)($_SESSION['user_id'] ?? 0);
$s_barangay = $_SESSION['barangay'] ?? 'Barangay';

// Fetch announcements authored by this admin or city-wide
$ann_stmt = $conn->prepare("SELECT * FROM announcements WHERE author_id = ? OR author_id IS NULL ORDER BY created_at DESC");
$ann_stmt->bind_param("i", $s_user_id);
$ann_stmt->execute();
$announcements = $ann_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$ann_stmt->close();

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
            <!-- Broadcast Section -->
            <div style="flex: 1; min-width: 320px;">
                <div class="sitting-panel" style="background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); border: 1px solid #edf2f7;">
                    <div class="panel-header" style="margin-bottom: 15px;">
                        <h2 style="font-weight:800; font-size:1.15rem; margin:0; display:flex; align-items:center; gap:8px;">
                            <i class='bx bx-broadcast' style="color:#d32f2f;"></i> Local Emergency Broadcast
                        </h2>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:12px;">
                        <input type="text" id="globalBroadcastTitle" placeholder="ALERT TITLE" class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px; box-sizing: border-box;">
                        <textarea id="globalBroadcastMessage" rows="4" placeholder="Emergency message for residents..." class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px; resize:none; box-sizing: border-box;"></textarea>
                        <select id="globalBroadcastSeverity" class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px; box-sizing: border-box;">
                            <option value="info">INFO</option>
                            <option value="warning">WARNING</option>
                            <option value="critical">CRITICAL</option>
                        </select>
                        <button onclick="submitGlobalBroadcast()" style="background:#d32f2f; color:white; width:100%; padding:14px; border:none; border-radius:8px; font-weight:800; cursor:pointer;">
                            TRANSMIT BARANGAY ALERT
                        </button>
                    </div>
                </div>
            </div>

            <!-- Announcements Section -->
            <div style="flex: 1.2; min-width: 340px;">
                <div class="sitting-panel" style="background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.04); border: 1px solid #edf2f7;">
                    <div class="panel-header" style="margin-bottom: 15px; display:flex; justify-content:space-between; align-items:center;">
                        <h2 style="font-weight:800; font-size:1.15rem; margin:0; display:flex; align-items:center; gap:8px;">
                            <i class='bx bxs-bell-ring' style="color:#f59e0b;"></i> Barangay Announcements
                        </h2>
                        <button class="pill-btn" onclick="openAnnouncementModal()"><i class='bx bx-plus'></i> New Post</button>
                    </div>
                    <div class="table-scroll-wrapper" style="max-height: 420px; overflow-y:auto; border: 1px solid #edf2f7; border-radius: 12px; padding: 0 10px;">
                        <table class="triage-table">
                            <thead>
                                <tr>
                                    <th>Details</th>
                                    <th style="text-align: center; width: 100px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($announcements)): ?>
                                <tr>
                                    <td colspan="2" style="text-align:center; color:#94a3b8; padding: 20px;">No announcements published yet.</td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach($announcements as $ann): ?>
                                <tr>
                                    <td>
                                        <strong style="color:#3b82f6; font-size:0.95rem;"><?= htmlspecialchars($ann['title']) ?></strong><br>
                                        <small style="color:#94a3b8; font-weight:600;"><?= date('M d, Y', strtotime($ann['created_at'])) ?></small>
                                    </td>
                                    <td style="text-align: center; white-space: nowrap;">
                                        <?php if ($ann['author_id'] == $s_user_id || empty($ann['author_id'])): ?>
                                        <button class="action-circle bg-green" onclick="openAnnouncementModal(<?= $ann['id'] ?>, '<?= addslashes($ann['title']) ?>', '<?= addslashes(str_replace(["\r","\n"], ['\r','\n'], $ann['message'])) ?>')"><i class='bx bx-edit'></i></button>
                                        <button class="action-circle bg-red" onclick="deleteAnnouncement(<?= $ann['id'] ?>)"><i class='bx bx-trash'></i></button>
                                        <?php else: ?>
                                        <small style="color:#cbd5e1;">City-wide</small>
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
        <div id="announcementModal" class="modal">
            <div class="modal-content" style="max-width: 500px;">
                <div class="close-modal" onclick="closeModal('announcementModal')"><i class='bx bx-x'></i></div>
                <h3 id="annModalTitle" style="margin-top:0; margin-bottom:15px; font-weight:800;">Announcement</h3>
                <input type="hidden" id="ann_id">
                <input type="text" id="ann_title" class="nav-input-field" style="width:100%; padding:12px; margin-bottom:10px; border: 1px solid #e2e8f0; border-radius:8px; box-sizing: border-box;" placeholder="Title">
                <textarea id="ann_message" class="nav-input-field" rows="5" style="width:100%; padding:12px; margin-bottom:10px; border: 1px solid #e2e8f0; border-radius:8px; resize:none; box-sizing: border-box;" placeholder="Message content..."></textarea>
                <input type="file" id="ann_image" style="margin-bottom: 20px;">
                <button class="pill-btn" style="width:100%; justify-content:center; padding:12px; font-size:1rem;" onclick="saveAnnouncement()">Save Announcement</button>
            </div>
        </div>
    </main>

    <script src="../js/client/controls.js"></script>
</body>
</html>
<?php
require_once '../php/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once '../php/auth.php';

$auth = new Auth($conn);
if (!$auth->isSuperAdmin()) {
    header('Location: dashboard.php');
    exit();
}

$s_role = $_SESSION['role'] ?? '';
$s_user_id = $_SESSION['user_id'] ?? 0;
session_write_close();

// Fetch Data
$announcements = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$guidelines = $conn->query("SELECT * FROM disaster_guidelines ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$emergency_types = $conn->query("SELECT * FROM emergency_types ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
$current_page = 'controls.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Controls | Command Center</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../css/admin/dashboard.css">
    <link rel="stylesheet" href="../css/admin/navbar.css">
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content" style="padding-top: 100px;">
        <h1 style="margin-bottom: 20px; font-weight: 800;"><i class='bx bx-slider-alt'></i> System Controls</h1>
        
        <div class="dashboard-split-layout">
            <!-- Left Column -->
            <div style="flex: 1; display: flex; flex-direction: column; gap: 20px;">
                
                <!-- BROADCAST -->
                <div class="sitting-panel">
                    <div class="panel-header">
                        <h2><i class='bx bx-broadcast' style="color:#d32f2f;"></i> Global Broadcast</h2>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:10px;">
                        <input type="text" id="globalBroadcastTitle" placeholder="ALERT TITLE" class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px;">
                        <textarea id="globalBroadcastMessage" rows="3" placeholder="MESSAGE..." class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px; resize:none;"></textarea>
                        <select id="globalBroadcastSeverity" class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid var(--border-color); border-radius: 8px;">
                            <option value="info">INFO</option>
                            <option value="warning">WARNING</option>
                            <option value="critical">CRITICAL</option>
                        </select>
                        <button onclick="submitGlobalBroadcast()" style="background:#d32f2f; color:white; width:100%; padding:14px; border:none; border-radius:8px; font-weight:900; cursor:pointer;">TRANSMIT ALERT</button>
                    </div>
                </div>

                <!-- EMERGENCY TYPES -->
                <div class="sitting-panel">
                    <div class="panel-header">
                        <h2><i class='bx bxs-error-circle' style="color:var(--color-critical);"></i> Emergency Types</h2>
                        <button class="btn-sm" style="background:var(--color-info);" onclick="openTypeModal()"><i class='bx bx-plus'></i> Add Type</button>
                    </div>
                    <div class="table-scroll-wrapper" style="max-height: 350px;">
                        <table class="triage-table">
                            <thead><tr><th>Icon & Name</th><th style="text-align: center;">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach($emergency_types as $et): ?>
                                <tr>
                                    <td><i class='bx <?php echo $et['icon']; ?>' style="font-size:1.2rem; color:var(--color-critical); vertical-align:middle; margin-right:8px;"></i> <b><?php echo htmlspecialchars($et['name']); ?></b></td>
                                    <td style="text-align: center;">
                                        <button class="btn-sm" style="background:var(--color-success); padding:6px;" onclick="openTypeModal(<?php echo $et['id']; ?>, '<?php echo addslashes($et['name']); ?>', '<?php echo $et['icon']; ?>')"><i class='bx bx-edit'></i></button>
                                        <button class="btn-sm" style="background:var(--color-critical); padding:6px;" onclick="deleteType(<?php echo $et['id']; ?>)"><i class='bx bx-trash'></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- Right Column -->
            <div style="flex: 1; display: flex; flex-direction: column; gap: 20px;">
                
                <!-- ANNOUNCEMENTS -->
                <div class="sitting-panel">
                    <div class="panel-header">
                        <h2><i class='bx bxs-bell-ring' style="color:var(--color-warning);"></i> App Announcements</h2>
                        <button class="btn-sm" style="background:var(--color-info);" onclick="openAnnouncementModal()"><i class='bx bx-plus'></i> Create</button>
                    </div>
                    <div class="table-scroll-wrapper" style="max-height: 300px;">
                        <table class="triage-table">
                            <thead><tr><th>Details</th><th style="text-align: center;">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach($announcements as $ann): ?>
                                <tr>
                                    <td><strong style="color:var(--color-info);"><?php echo htmlspecialchars($ann['title']); ?></strong><br><small style="color:var(--text-muted);"><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></small></td>
                                    <td style="text-align: center;">
                                        <button class="btn-sm" style="background:var(--color-success); padding:6px;" onclick="openAnnouncementModal(<?php echo $ann['id']; ?>, '<?php echo addslashes($ann['title']); ?>', '<?php echo addslashes(str_replace(["\r","\n"], ['\r','\n'], $ann['message'])); ?>')"><i class='bx bx-edit'></i></button>
                                        <button class="btn-sm" style="background:var(--color-critical); padding:6px;" onclick="deleteAnnouncement(<?php echo $ann['id']; ?>)"><i class='bx bx-trash'></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- GUIDELINES -->
                <div class="sitting-panel">
                    <div class="panel-header">
                        <h2><i class='bx bx-book-bookmark' style="color:var(--color-success);"></i> Disaster Guidelines</h2>
                        <button class="btn-sm" style="background:var(--color-info);" onclick="openGuidelineModal()"><i class='bx bx-plus'></i> Add Guide</button>
                    </div>
                    <div class="table-scroll-wrapper" style="max-height: 300px;">
                        <table class="triage-table">
                            <thead><tr><th>Title</th><th style="text-align: center;">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach($guidelines as $g): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($g['title']); ?></strong></td>
                                    <td style="text-align: center;">
                                        <button class="btn-sm" style="background:var(--color-success); padding:6px;" onclick="openGuidelineModal(<?php echo $g['id']; ?>, '<?php echo addslashes($g['title']); ?>', '<?php echo addslashes(str_replace(["\r","\n"], ['\r','\n'], $g['content'])); ?>')"><i class='bx bx-edit'></i></button>
                                        <button class="btn-sm" style="background:var(--color-critical); padding:6px;" onclick="deleteGuideline(<?php echo $g['id']; ?>)"><i class='bx bx-trash'></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>

        <!-- Modals -->
        <div id="announcementModal" class="modal">
            <div class="modal-content" style="max-width: 500px;">
                <div class="close-modal" onclick="closeModal('announcementModal')"><i class='bx bx-x'></i></div>
                <h3 id="annModalTitle" style="margin-bottom:15px;">Announcement</h3>
                <input type="hidden" id="ann_id">
                <input type="text" id="ann_title" style="width:100%; padding:10px; margin-bottom:10px;" placeholder="Title">
                <textarea id="ann_message" rows="4" style="width:100%; padding:10px; margin-bottom:10px;" placeholder="Message"></textarea>
                <input type="file" id="ann_image" style="margin-bottom: 15px;">
                <button class="btn-sm" style="background:var(--color-info); width:100%; justify-content:center; padding:12px;" onclick="saveAnnouncement()">Save</button>
            </div>
        </div>

        <div id="guidelineModal" class="modal">
            <div class="modal-content" style="max-width: 500px;">
                <div class="close-modal" onclick="closeModal('guidelineModal')"><i class='bx bx-x'></i></div>
                <h3 id="guideModalTitle" style="margin-bottom:15px;">Guideline</h3>
                <input type="hidden" id="guide_id">
                <input type="text" id="guide_title" style="width:100%; padding:10px; margin-bottom:10px;" placeholder="Title (e.g. Earthquake Safety)">
                <textarea id="guide_content" rows="6" style="width:100%; padding:10px; margin-bottom:15px;" placeholder="Instructions..."></textarea>
                <button class="btn-sm" style="background:var(--color-success); width:100%; justify-content:center; padding:12px;" onclick="saveGuideline()">Save</button>
            </div>
        </div>

        <div id="typeModal" class="modal">
            <div class="modal-content" style="max-width: 400px;">
                <div class="close-modal" onclick="closeModal('typeModal')"><i class='bx bx-x'></i></div>
                <h3 id="typeModalTitle" style="margin-bottom:15px;">Emergency Type</h3>
                <input type="hidden" id="type_id">
                <input type="text" id="type_name" style="width:100%; padding:10px; margin-bottom:10px;" placeholder="Name (e.g. Fire, Flood)">
                <input type="text" id="type_icon" style="width:100%; padding:10px; margin-bottom:15px;" placeholder="BoxIcon Class (e.g. bxs-flame)">
                <button class="btn-sm" style="background:var(--color-critical); width:100%; justify-content:center; padding:12px;" onclick="saveType()">Save</button>
            </div>
        </div>
    </main>

    <script src="../js/admin/controls.js"></script>
</body>
</html>
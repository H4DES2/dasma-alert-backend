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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../css/admin/dashboard.css">
    <link rel="stylesheet" href="../css/admin/navbar.css">
    <style>
        .pill-btn { background: #3b82f6; color: #fff; padding: 6px 16px; border-radius: 20px; font-weight: 700; font-size: 0.8rem; border: none; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; }
        .action-circle { width: 30px; height: 30px; border-radius: 50%; border: none; color: white; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.9rem; }
        .bg-green { background: #10b981; }
        .bg-red { background: #ef4444; }
        .triage-table th { background: transparent; color: #888; font-size: 0.75rem; text-transform: uppercase; padding-bottom: 10px; border-bottom: 1px solid #edf2f7; }
        .triage-table td { border-bottom: 1px solid #edf2f7; padding: 14px 10px; }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content" style="padding-top: 100px;">
        <h1 style="margin-bottom: 25px; font-weight: 800; display:flex; align-items:center; gap:8px;"><i class='bx bx-slider-alt'></i> System Controls</h1>
        
        <div class="dashboard-split-layout">
            <!-- Left Column -->
            <div style="flex: 1; display: flex; flex-direction: column; gap: 20px;">
                
                <!-- BROADCAST -->
                <div class="sitting-panel" style="border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
                    <div class="panel-header" style="margin-bottom: 15px; border:none;">
                        <h2 style="font-weight:800; font-size:1.1rem;"><i class='bx bx-broadcast' style="color:#d32f2f;"></i> Global Broadcast</h2>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:12px;">
                        <input type="text" id="globalBroadcastTitle" placeholder="ALERT TITLE" class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px;">
                        <textarea id="globalBroadcastMessage" rows="3" placeholder="MESSAGE..." class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px; resize:none;"></textarea>
                        <select id="globalBroadcastSeverity" class="nav-input-field" style="width: 100%; padding: 12px; border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 8px;">
                            <option value="info">INFO</option>
                            <option value="warning">WARNING</option>
                            <option value="critical">CRITICAL</option>
                        </select>
                        <button onclick="submitGlobalBroadcast()" style="background:#d32f2f; color:white; width:100%; padding:14px; border:none; border-radius:8px; font-weight:800; cursor:pointer;">TRANSMIT ALERT</button>
                    </div>
                </div>

                <!-- EMERGENCY TYPES -->
                <div class="sitting-panel" style="border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
                    <div class="panel-header" style="margin-bottom: 10px; border:none;">
                        <h2 style="font-weight:800; font-size:1.1rem;"><i class='bx bxs-error-circle' style="color:#ef4444;"></i> Emergency Types</h2>
                        <button class="pill-btn" onclick="openTypeModal()"><i class='bx bx-plus'></i> Add Type</button>
                    </div>
                    <div class="table-scroll-wrapper" style="max-height: 400px; border: 1px solid #edf2f7; border-radius: 12px; padding: 0 10px;">
                        <table class="triage-table">
                            <thead><tr><th>Icon & Name</th><th style="text-align: center;">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach($emergency_types as $et): ?>
                                <tr>
                                    <td><i class='bx <?php echo $et['icon']; ?>' style="font-size:1.1rem; color:#ef4444; vertical-align:middle; margin-right:8px;"></i> <b style="color:#334155; font-size:0.95rem;"><?php echo htmlspecialchars($et['name']); ?></b></td>
                                    <td style="text-align: center;">
                                        <button class="action-circle bg-green" onclick="openTypeModal(<?php echo $et['id']; ?>, '<?php echo addslashes($et['name']); ?>', '<?php echo $et['icon']; ?>', '<?php echo addslashes($et['incidents'] ?? ''); ?>')"><i class='bx bx-edit'></i></button>
                                        <button class="action-circle bg-red" onclick="deleteType(<?php echo $et['id']; ?>)"><i class='bx bx-trash'></i></button>
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
                <div class="sitting-panel" style="border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
                    <div class="panel-header" style="margin-bottom: 10px; border:none;">
                        <h2 style="font-weight:800; font-size:1.1rem;"><i class='bx bxs-bell-ring' style="color:#f59e0b;"></i> App Announcements</h2>
                        <button class="pill-btn" onclick="openAnnouncementModal()"><i class='bx bx-plus'></i> Create</button>
                    </div>
                    <div class="table-scroll-wrapper" style="max-height: 300px; border: 1px solid #edf2f7; border-radius: 12px; padding: 0 10px;">
                        <table class="triage-table">
                            <thead><tr><th>Details</th><th style="text-align: center;">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach($announcements as $ann): ?>
                                <tr>
                                    <td><strong style="color:#3b82f6; font-size:0.95rem;"><?php echo htmlspecialchars($ann['title']); ?></strong><br><small style="color:#94a3b8; font-weight:600;"><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></small></td>
                                    <td style="text-align: center;">
                                        <button class="action-circle bg-green" onclick="openAnnouncementModal(<?php echo $ann['id']; ?>, '<?php echo addslashes($ann['title']); ?>', '<?php echo addslashes(str_replace(["\r","\n"], ['\r','\n'], $ann['message'])); ?>')"><i class='bx bx-edit'></i></button>
                                        <button class="action-circle bg-red" onclick="deleteAnnouncement(<?php echo $ann['id']; ?>)"><i class='bx bx-trash'></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- GUIDELINES -->
                <div class="sitting-panel" style="border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
                    <div class="panel-header" style="margin-bottom: 10px; border:none;">
                        <h2 style="font-weight:800; font-size:1.1rem;"><i class='bx bx-book-bookmark' style="color:#10b981;"></i> Disaster Guidelines</h2>
                        <button class="pill-btn" onclick="openGuidelineModal()"><i class='bx bx-plus'></i> Add Guide</button>
                    </div>
                    <div class="table-scroll-wrapper" style="max-height: 300px; border: 1px solid #edf2f7; border-radius: 12px; padding: 0 10px;">
                        <table class="triage-table">
                            <thead><tr><th>Title</th><th style="text-align: center;">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach($guidelines as $g): ?>
                                <tr>
                                    <td><strong style="color:#334155; font-size:0.95rem;"><?php echo htmlspecialchars($g['title']); ?></strong></td>
                                    <td style="text-align: center;">
                                        <button class="action-circle bg-green" onclick="openGuidelineModal(<?php echo $g['id']; ?>, '<?php echo addslashes($g['title']); ?>', '<?php echo addslashes(str_replace(["\r","\n"], ['\r','\n'], $g['content'])); ?>')"><i class='bx bx-edit'></i></button>
                                        <button class="action-circle bg-red" onclick="deleteGuideline(<?php echo $g['id']; ?>)"><i class='bx bx-trash'></i></button>
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
            <div class="modal-content" style="max-width: 500px; border-radius: 16px;">
                <div class="close-modal" onclick="closeModal('announcementModal')"><i class='bx bx-x'></i></div>
                <h3 id="annModalTitle" style="margin-bottom:15px; font-weight:800;">Announcement</h3>
                <input type="hidden" id="ann_id">
                <input type="text" id="ann_title" class="nav-input-field" style="width:100%; padding:12px; margin-bottom:10px; border: 1px solid #e2e8f0; border-radius:8px;" placeholder="Title">
                <textarea id="ann_message" class="nav-input-field" rows="4" style="width:100%; padding:12px; margin-bottom:10px; border: 1px solid #e2e8f0; border-radius:8px; resize:none;" placeholder="Message"></textarea>
                <input type="file" id="ann_image" style="margin-bottom: 20px;">
                <button class="pill-btn" style="width:100%; justify-content:center; padding:12px; font-size:1rem;" onclick="saveAnnouncement()">Save</button>
            </div>
        </div>

        <div id="guidelineModal" class="modal">
            <div class="modal-content" style="max-width: 500px; border-radius: 16px;">
                <div class="close-modal" onclick="closeModal('guidelineModal')"><i class='bx bx-x'></i></div>
                <h3 id="guideModalTitle" style="margin-bottom:15px; font-weight:800;">Guideline</h3>
                <input type="hidden" id="guide_id">
                <input type="text" id="guide_title" class="nav-input-field" style="width:100%; padding:12px; margin-bottom:10px; border: 1px solid #e2e8f0; border-radius:8px;" placeholder="Title (e.g. Earthquake Safety)">
                <textarea id="guide_content" class="nav-input-field" rows="6" style="width:100%; padding:12px; margin-bottom:20px; border: 1px solid #e2e8f0; border-radius:8px; resize:none;" placeholder="Instructions..."></textarea>
                <button class="pill-btn" style="width:100%; justify-content:center; padding:12px; font-size:1rem; background:#10b981;" onclick="saveGuideline()">Save</button>
            </div>
        </div>

        <div id="typeModal" class="modal">
            <div class="modal-content" style="max-width: 450px; border-radius: 16px;">
                <div class="close-modal" onclick="closeModal('typeModal')"><i class='bx bx-x'></i></div>
                <h3 id="typeModalTitle" style="margin-bottom:15px; font-weight:800;">Emergency Type</h3>
                <input type="hidden" id="type_id">
                <label style="font-size: 0.75rem; font-weight: 800; color: #888;">Type Name</label>
                <input type="text" id="type_name" class="nav-input-field" style="width:100%; padding:12px; margin-bottom:10px; border: 1px solid #e2e8f0; border-radius:8px;" placeholder="e.g. Fire, Flood">
                <label style="font-size: 0.75rem; font-weight: 800; color: #888;">BoxIcon Class</label>
                <input type="text" id="type_icon" class="nav-input-field" style="width:100%; padding:12px; margin-bottom:10px; border: 1px solid #e2e8f0; border-radius:8px;" placeholder="e.g. bxs-flame">
                <label style="font-size: 0.75rem; font-weight: 800; color: #888;">Specific Incidents / Hazards (Comma Separated)</label>
                <textarea id="type_incidents" class="nav-input-field" rows="3" style="width:100%; padding:12px; margin-bottom:20px; border: 1px solid #e2e8f0; border-radius:8px; resize:none;" placeholder="e.g. Downed Power Lines, Oil Spill, Landslide"></textarea>
                <button class="pill-btn" style="width:100%; justify-content:center; padding:12px; font-size:1rem; background:#3b82f6;" onclick="saveType()">Save Settings</button>
            </div>
        </div>
    </main>

    <script src="../js/admin/controls.js"></script>
</body>
</html>
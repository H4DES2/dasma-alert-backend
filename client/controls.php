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
$alt_barangay = (strcasecmp($s_barangay, 'Burol') === 0) ? 'Burol Main' : $s_barangay;

// Fetch Announcements
$chk_ab = $conn->query("SHOW COLUMNS FROM announcements LIKE 'target_barangay'");
if ($chk_ab && $chk_ab->num_rows === 0) {
    $conn->query("ALTER TABLE announcements ADD COLUMN target_barangay VARCHAR(100) NULL DEFAULT NULL");
}

$ann_stmt = $conn->prepare("
    SELECT * FROM announcements 
    WHERE author_id = ? 
       OR LOWER(TRIM(target_barangay)) = LOWER(?) 
       OR LOWER(TRIM(target_barangay)) = LOWER(?)
    ORDER BY created_at DESC
");
$ann_stmt->bind_param("iss", $s_user_id, $s_barangay, $alt_barangay);
$ann_stmt->execute();
$announcements = $ann_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$ann_stmt->close();

// Fetch Geofenced Broadcast History for this Barangay
$chk_t = $conn->query("SHOW COLUMNS FROM broadcasts LIKE 'target_barangay'");
if ($chk_t && $chk_t->num_rows === 0) {
    $conn->query("ALTER TABLE broadcasts ADD COLUMN target_barangay VARCHAR(100) NULL DEFAULT NULL");
}

$b_stmt = $conn->prepare("
    SELECT *, DATE_FORMAT(created_at, '%M %d, %Y - %h:%i %p') as date_str 
    FROM broadcasts 
    WHERE LOWER(TRIM(target_barangay)) = LOWER(?) 
       OR LOWER(TRIM(target_barangay)) = LOWER(?)
    ORDER BY created_at DESC 
    LIMIT 30
");
$b_stmt->bind_param("ss", $s_barangay, $alt_barangay);
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

    <main class="main-content">
        <header class="controls-header">
            <h1 class="header-title">
                <i class='bx bx-slider-alt'></i> Barangay Controls &mdash; <?= htmlspecialchars($s_barangay) ?>
            </h1>
        </header>
        
        <div class="dashboard-split-layout">
            
            <!-- LEFT COLUMN: Broadcast & Broadcast History -->
            <div class="controls-col">
                
                <!-- Broadcast Form -->
                <div class="command-panel">
                    <div class="panel-header">
                        <div class="panel-title-group">
                            <div class="panel-icon-badge danger-badge">
                                <i class='bx bx-broadcast'></i>
                            </div>
                            <div>
                                <h2>Geofenced Broadcast</h2>
                                <p class="panel-subtitle">Immediate alert broadcast to <?= htmlspecialchars($s_barangay) ?> citizens</p>
                            </div>
                        </div>
                        <span class="sector-tag">Brgy. <?= htmlspecialchars($s_barangay) ?></span>
                    </div>

                    <div class="broadcast-form">
                        <input type="text" id="globalBroadcastTitle" placeholder="ALERT TITLE" class="nav-input-field">
                        <textarea id="globalBroadcastMessage" rows="3" placeholder="Emergency message for <?= htmlspecialchars($s_barangay) ?> residents..." class="nav-input-field"></textarea>
                        <select id="globalBroadcastSeverity" class="nav-input-field">
                            <option value="info">INFO ALERT</option>
                            <option value="warning">WARNING ADVISORY</option>
                            <option value="critical">CRITICAL EMERGENCY</option>
                        </select>
                        <button onclick="submitGlobalBroadcast()" class="btn-transmit">
                            <i class='bx bx-send'></i> TRANSMIT TO <?= strtoupper(htmlspecialchars($s_barangay)) ?>
                        </button>
                    </div>
                </div>

                <!-- Broadcast History (Superadmin Design) -->
                <div class="command-panel">
                    <div class="panel-header">
                        <div class="panel-title-group">
                            <div class="panel-icon-badge">
                                <i class='bx bx-broadcast'></i>
                            </div>
                            <div>
                                <h2>Broadcast History</h2>
                                <p class="panel-subtitle">Official emergency bulletins</p>
                            </div>
                        </div>
                    </div>

                    <div class="table-scroll-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th style="width: 60%;">TITLE & DATE</th>
                                    <th style="width: 20%; text-align: center;">SEVERITY</th>
                                    <th style="width: 20%; text-align: center;">CONTROL</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($broadcast_history)): ?>
                                    <tr>
                                        <td colspan="3" class="table-empty-row">No local broadcasts transmitted yet.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($broadcast_history as $b): 
                                    $sev = strtolower(trim($b['severity']));
                                    $sev_class = match($sev) {
                                        'critical' => 'badge-critical',
                                        'warning'  => 'badge-warning',
                                        default    => 'badge-info'
                                    };
                                ?>
                                <tr>
                                    <td>
                                        <strong class="broadcast-title"><?= htmlspecialchars($b['title']) ?></strong>
                                        <span class="broadcast-date"><?= $b['date_str'] ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="badge <?= $sev_class ?>">
                                            <?= strtoupper($b['severity']) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;">
                                        <?php if ($b['is_active']): ?>
                                            <button class="btn-stop" onclick="stopBroadcast(<?= $b['id'] ?>)">
                                                <i class='bx bx-stop-circle'></i> STOP
                                            </button>
                                        <?php else: ?>
                                            <span class="badge badge-gray">ENDED</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- RIGHT COLUMN: Barangay Announcements -->
            <div class="controls-col">
                <div class="command-panel h-100">
                    <div class="panel-header">
                        <div class="panel-title-group">
                            <div class="panel-icon-badge warning-badge">
                                <i class='bx bxs-bell-ring'></i>
                            </div>
                            <div>
                                <h2>Barangay Announcements</h2>
                                <p class="panel-subtitle">Public advisory feeds and information releases</p>
                            </div>
                        </div>
                        <button class="pill-btn" onclick="openAnnouncementModal()">
                            <i class='bx bx-plus'></i> New Post
                        </button>
                    </div>

                    <div class="table-scroll-wrapper flex-fill">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>DETAILS</th>
                                    <th style="text-align: center; width: 100px;">ACTIONS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($announcements)): ?>
                                <tr>
                                    <td colspan="2" class="table-empty-row">No announcements published yet.</td>
                                </tr>
                                <?php endif; ?>
                                <?php foreach($announcements as $ann): ?>
                                <tr>
                                    <td>
                                        <strong class="ann-title"><?= htmlspecialchars($ann['title']) ?></strong>
                                        <p class="ann-body"><?= nl2br(htmlspecialchars(substr($ann['message'], 0, 140))) ?><?= strlen($ann['message']) > 140 ? '...' : '' ?></p>
                                        <span class="broadcast-date">
                                            <i class='bx bx-calendar'></i> <?= date('M d, Y', strtotime($ann['created_at'])) ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center; white-space: nowrap;">
                                        <button class="action-circle bg-green" onclick="openAnnouncementModal(<?= $ann['id'] ?>, '<?= addslashes($ann['title']) ?>', '<?= addslashes(str_replace(["\r","\n"], ['\r','\n'], $ann['message'])) ?>')">
                                            <i class='bx bx-edit'></i>
                                        </button>
                                        <button class="action-circle bg-red" onclick="deleteAnnouncement(<?= $ann['id'] ?>)">
                                            <i class='bx bx-trash'></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>

        <!-- Announcement Modal -->
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
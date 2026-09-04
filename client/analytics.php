<?php
session_start();
require_once '../php/config.php';
require_once '../php/auth.php';

if (!isset($auth)) { 
    $auth = new Auth($conn); 
}

if (!$auth->is_logged_in()) {
    header("Location: ../php/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$my_brgy = $_SESSION['barangay'] ?? '';

// If session barangay is empty, fallback to DB fetch
if (empty($my_brgy)) {
    $stmt = $conn->prepare("SELECT barangay FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $my_brgy = $res['barangay'] ?? '';
    $_SESSION['barangay'] = $my_brgy;
    $stmt->close();
}

// Normalize / Handle Barangay Aliases
$target_brgy = trim($my_brgy);
$like_brgy   = '%' . $target_brgy . '%';

// Include both 'archived' and 'resolved' incidents, with alias support
$query = "
    SELECT i.id, i.barangay, i.incident_type, i.severity, i.latitude, i.longitude, i.created_at, i.image_path,
    DATE_FORMAT(i.created_at, '%M %d, %Y - %h:%i %p') as date_str,
    (SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(il.created_at, '%h:%i %p'), '|-|', IFNULL(u.username, 'System'), '|-|', il.log_message) SEPARATOR '|||') 
     FROM incident_logs il 
     LEFT JOIN users u ON il.user_id = u.id 
     WHERE il.incident_id = i.id ORDER BY il.created_at ASC) as all_logs
    FROM incidents i
    WHERE i.status IN ('archived', 'resolved') 
      AND (i.barangay = ? OR i.barangay LIKE ?)
    ORDER BY i.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("ss", $target_brgy, $like_brgy);
$stmt->execute();
$result = $stmt->get_result();

$incidents = [];
$type_counts = [];

while ($row = $result->fetch_assoc()) {
    $incidents[] = $row;
    $type = $row['incident_type'] ?? 'Other';
    $type_counts[$type] = ($type_counts[$type] ?? 0) + 1;
}
$stmt->close();

// Fix: Assign to $archived_incidents for the HTML table
$archived_incidents = $incidents;

// Prepare JSON arrays for JS & Chart.js
$js_incidents = json_encode($incidents);
$pie_labels   = json_encode(array_keys($type_counts));
$pie_values   = json_encode(array_values($type_counts));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Local Analytics | CDRRMO</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../css/client/navbar.css">
    <link rel="stylesheet" href="../css/client/analytics.css">
</head>
<body>

    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header style="margin-bottom: 30px;">
            <h1 style="color: #333; margin: 0; font-size: 2rem;">Sector Analytics</h1>
            <p style="color: #666; margin-top: 5px; font-weight: 800;">
                Sector: <span style="color: #d32f2f;"><?php echo htmlspecialchars($my_brgy ?: 'Unassigned'); ?></span>
            </p>
        </header>

        <div class="dashboard-split-layout">
            
            <div class="left-column">
                <div class="sitting-panel">
                    <div class="panel-header">
                        <h2 style="margin: 0;"><i class='bx bxs-pie-chart-alt-2' style="color:#f57c00;"></i> Breakdown</h2>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="breakdownPieChart"></canvas>
                    </div>
                </div>

                <div class="sitting-panel">
                    <div class="panel-header">
                        <h2 style="margin: 0;"><i class='bx bx-line-chart' style="color:#d32f2f;"></i> Disaster Seasonality</h2>
                        <select id="seasonalityFilter" class="filter-dropdown" onchange="renderSeasonality()">
                            <option value="all">∞ All Time</option>
                            <option value="year">📅 Past Year</option>
                            <option value="month">📆 Past Month</option>
                            <option value="week">🗓️ Past Week</option>
                        </select>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="seasonalityLineChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="right-column sitting-panel">
                <div class="panel-header">
                    <h2 style="margin: 0; display:flex; align-items:center; gap:10px;"><i class='bx bxs-archive' style="color:#607d8b;"></i> Incident Archive Vault</h2>
                    <div class="vault-controls">
                        <button class="icon-btn green"><i class='bx bx-table'></i></button>
                        <button class="icon-btn red"><i class='bx bxs-file-pdf'></i></button>
                        <select class="filter-dropdown"><option>∞ All Time</option></select>
                        <select class="filter-dropdown"><option>🌍 All Types</option></select>
                    </div>
                </div>
                
                <div class="table-scroll-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Incident / Date</th>
                                <th>Barangay</th>
                                <th style="text-align:center;">Severity</th>
                                <th style="text-align:center;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($archived_incidents)): ?>
                                <tr><td colspan="4" style="text-align: center; color: #888; padding: 40px; font-weight: bold;">No historical records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($archived_incidents as $inc): 
                                    $badge = strtolower($inc['severity']) === 'critical' ? 'critical' : (strtolower($inc['severity']) === 'minor' ? 'minor' : 'major');
                                    $logs_js = htmlspecialchars($inc['all_logs'] ?? '', ENT_QUOTES, 'UTF-8');
                                    $safe_img = addslashes($inc['image_path'] ?? '');
                                    $safe_type = addslashes($inc['incident_type']);
                                    $safe_brgy = addslashes($inc['barangay']);
                                ?>
                                <tr class="clickable-row" onclick="openMobileModal(this)">
                                    <td>
                                        <div class="incident-title-text" style="font-weight: 800; font-size: 1.1rem;"><?php echo htmlspecialchars($inc['incident_type']); ?></div>
                                        <div class="incident-date-text" style="font-size: 0.8rem; font-weight: 600;"><?php echo $inc['date_str']; ?></div>
                                        <i class='bx bx-chevron-right mobile-expand-icon'></i>
                                    </td>
                                    <td>
                                        <span class="incident-title-text" style="font-weight: 800; font-size: 1rem;"><?php echo htmlspecialchars($inc['barangay']); ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="badge <?php echo $badge; ?>"><?php echo strtoupper($inc['severity']); ?></span>
                                    </td>
                                    <td>
                                        <div class="btn-action-group">
                                            <button type="button" class="btn-table-icon bg-green" onclick="event.stopPropagation(); viewEvidence('<?php echo $safe_img; ?>', '<?php echo $safe_type; ?>', '<?php echo $safe_brgy; ?>')"><i class='bx bx-image'></i></button>
                                            <button type="button" class="btn-table-icon bg-blue" data-logs="<?= htmlspecialchars($inc['all_logs'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-type="<?= htmlspecialchars($inc['incident_type'], ENT_QUOTES, 'UTF-8') ?>" onclick="event.stopPropagation(); openLogModal(this)"><i class='bx bx-list-ul'></i></button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- MOBILE ROW DETAILS MODAL -->
    <div id="mobileDetailsModal" class="modal" style="z-index: 10005;">
        <div class="modal-content" style="max-width: 90%; padding: 24px; position: relative;">
            <div class="close-modal" onclick="document.getElementById('mobileDetailsModal').style.display='none'"><i class='bx bx-x'></i></div>
            <h3 id="m-inc-title" style="margin-bottom: 16px; font-weight: 900; font-size: 1.3rem; padding-right: 30px; color: var(--text-primary);">Incident Log</h3>
            <div id="m-inc-body" style="display: flex; flex-direction: column;"></div>
        </div>
    </div>

    <!-- EXISTING MODALS WITH UPDATED CLOSE BUTTONS -->
    <div id="viewLogsModal" class="modal">
        <div class="modal-content" style="position: relative;">
            <div class="close-modal" onclick="document.getElementById('viewLogsModal').style.display='none'"><i class='bx bx-x'></i></div>
            <div class="modal-header" style="border: none;">
                <h3 id="logTitle" style="margin:0; color:#1976d2; font-weight:800; display:flex; align-items:center; gap:8px;"></h3>
            </div>
            <div id="logContainer" style="max-height:400px; overflow-y:auto; padding-right:5px;"></div>
        </div>
    </div>

    <div id="evidenceModal" class="modal" style="background: rgba(0,0,0,0.85);">
        <div class="modal-content" style="background: transparent; box-shadow: none; text-align: center; max-width: 800px; border: none; position: relative;">
            <div class="close-modal" onclick="document.getElementById('evidenceModal').style.display='none'" style="color: white; font-size: 2.5rem; top: -40px; right: 0; background: none; border: none; box-shadow: none;"><i class='bx bx-x'></i></div>
            <img id="evidenceImageFull" src="" style="max-width: 100%; max-height: 80vh; border-radius: 12px; border: 3px solid #555;">
            <p id="evidenceCaption" style="color: white; margin-top: 15px; font-size: 1.2rem; font-weight: bold;"></p>
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
    window.allIncidents = <?= $js_incidents ?? '[]' ?>;
    window.pieLabels    = <?= $pie_labels ?? '[]' ?>;
    window.pieValues    = <?= $pie_values ?? '[]' ?>;
</script>
<script src="../js/client/analytics.js?v=<?= filemtime('../js/client/analytics.js') ?>"></script>
</body>
</html>
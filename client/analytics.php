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

if (empty($my_brgy)) {
    $stmt = $conn->prepare("SELECT barangay FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $my_brgy = $res['barangay'] ?? '';
    $_SESSION['barangay'] = $my_brgy;
    $stmt->close();
}

$target_brgy = trim($my_brgy);
$like_brgy   = '%' . $target_brgy . '%';

// 1. Fetch Archive Vault Incidents (with Timeline timestamps)
$query = "
    SELECT i.id, i.barangay, i.incident_type, i.severity, i.latitude, i.longitude, i.created_at, i.image_path,
    DATE_FORMAT(i.created_at, '%b %d, %Y - %h:%i %p') as date_str,
    DATE_FORMAT(i.created_at, '%h:%i %p') as reported_time,
    (SELECT DATE_FORMAT(il.created_at, '%h:%i %p') FROM incident_logs il WHERE il.incident_id = i.id AND (LOWER(il.log_message) LIKE '%on scene%' OR LOWER(il.log_message) LIKE '%on-scene%') ORDER BY il.created_at ASC LIMIT 1) as arrived_time,
    (SELECT DATE_FORMAT(il.created_at, '%h:%i %p') FROM incident_logs il WHERE il.incident_id = i.id AND LOWER(il.log_message) LIKE '%resolved%' ORDER BY il.created_at DESC LIMIT 1) as resolved_time,
    (SELECT TIMESTAMPDIFF(MINUTE, i.created_at, il.created_at) FROM incident_logs il WHERE il.incident_id = i.id AND LOWER(il.log_message) LIKE '%resolved%' ORDER BY il.created_at DESC LIMIT 1) as duration_minutes,
    (SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(il.created_at, '%h:%i %p'), '|-|', IFNULL(u.username, 'System'), '|-|', il.log_message) SEPARATOR '|||') 
     FROM incident_logs il 
     LEFT JOIN users u ON il.user_id = u.id 
     WHERE il.incident_id = i.id ORDER BY il.created_at ASC) as all_logs,
    (SELECT il.log_message FROM incident_logs il WHERE il.incident_id = i.id ORDER BY il.created_at ASC LIMIT 1) as initial_reporter_log
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

// 2. Fetch Rejection Audit Records & Category Breakdown
$bin_query = "
    SELECT i.id, i.barangay, i.incident_type, i.severity, i.latitude, i.longitude, i.created_at, i.image_path, i.admin_remarks,
    DATE_FORMAT(i.created_at, '%b %d, %Y - %h:%i %p') as date_str,
    sr.reason as spam_reason,
    (SELECT il.log_message FROM incident_logs il WHERE il.incident_id = i.id ORDER BY il.created_at ASC LIMIT 1) as initial_reporter_log,
    (SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(il.created_at, '%h:%i %p'), '|-|', IFNULL(u.username, 'System'), '|-|', il.log_message) SEPARATOR '|||') 
     FROM incident_logs il 
     LEFT JOIN users u ON il.user_id = u.id 
     WHERE il.incident_id = i.id ORDER BY il.created_at ASC) as all_logs
    FROM incidents i
    LEFT JOIN spam_reports sr ON sr.incident_id = i.id
    WHERE i.status = 'rejected'
      AND (i.barangay = ? OR i.barangay LIKE ?)
    ORDER BY i.created_at DESC";

$b_stmt = $conn->prepare($bin_query);
$b_stmt->bind_param("ss", $target_brgy, $like_brgy);
$b_stmt->execute();
$b_res = $b_stmt->get_result();

$rejected_incidents = [];
$rejection_categories = [
    'False Alarm' => 0,
    'Out of Jurisdiction' => 0,
    'Duplicate Report' => 0,
    'Prank / Spam' => 0,
    'Incomplete Information' => 0,
    'Other / Unspecified' => 0
];

while ($brow = $b_res->fetch_assoc()) {
    $rejected_incidents[] = $brow;
    $combined_reason = ($brow['admin_remarks'] ?? '') . ' ' . ($brow['spam_reason'] ?? '');
    
    $matched = false;
    foreach (array_keys($rejection_categories) as $cat) {
        if (stripos($combined_reason, $cat) !== false) {
            $rejection_categories[$cat]++;
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        $rejection_categories['Other / Unspecified']++;
    }
}
$b_stmt->close();

$total_rejections = count($rejected_incidents);
$calc_pct = function($count, $total) {
    return $total > 0 ? round(($count / $total) * 100) : 0;
};

$js_incidents = json_encode($incidents);
$js_rejected  = json_encode($rejected_incidents);
$pie_labels   = json_encode(array_keys($type_counts));
$pie_values   = json_encode(array_values($type_counts));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sector Analytics | Command Center</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="../css/client/navbar.css">
    <link rel="stylesheet" href="../css/client/analytics.css?v=<?= filemtime('../css/client/analytics.css') ?>">
</head>
<body>

    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <div>
                <h1>Sector Analytics</h1>
                <p>Sector: <span style="color: var(--color-critical, #d32f2f); font-weight: 800;"><?php echo htmlspecialchars($my_brgy ?: 'Unassigned'); ?></span></p>
            </div>
            <div class="header-actions">
                <button type="button" class="btn-generate-report" onclick="openReportModal()">
                    <i class='bx bxs-file-pdf'></i> Generate Report
                </button>
            </div>
        </header>

        <!-- TOP SECTION: Archive Vault & Breakdown -->
        <div class="analytics-grid-two">
            <!-- 1. Incident Archive Vault -->
            <div class="sitting-panel vault-panel">
                <div class="panel-header">
                    <h2><i class='bx bxs-archive' style="color:#607d8b;"></i> Incident Archive Vault</h2>
                    <div class="filter-group">
                        <select id="vaultTimeFilter" class="filter-dropdown" onchange="filterVaultData()">
                            <option value="all">All Time</option>
                            <option value="year">Past Year</option>
                            <option value="quarter">Past Quarter</option>
                            <option value="month">Past Month</option>
                            <option value="week">Past Week</option>
                            <option value="today">Today</option>
                        </select>
                        <select id="vaultTypeFilter" class="filter-dropdown" onchange="filterVaultData()">
                            <option value="all">All Types</option>
                        </select>
                    </div>
                </div>

                <div class="table-scroll-wrapper">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>INCIDENT & LOGS</th>
                                <th>LOCATION & STATUS</th>
                                <th>RESPONSE TIMELINE</th>
                                <th style="text-align:center;">ACTION</th>
                            </tr>
                        </thead>
                        <tbody id="vaultTableBody">
                            <?php if (empty($incidents)): ?>
                                <tr><td colspan="4" style="text-align: center; color: #888; padding: 40px; font-weight: bold;">No historical records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($incidents as $inc): 
                                    $badge = strtolower($inc['severity']) === 'critical' ? 'critical' : (strtolower($inc['severity']) === 'minor' ? 'minor' : 'major');
                                    $safe_img = addslashes($inc['image_path'] ?? '');
                                    $safe_type = addslashes($inc['incident_type']);
                                    $safe_brgy = addslashes($inc['barangay']);
                                    $rep_log = !empty($inc['initial_reporter_log']) ? '"' . htmlspecialchars(substr($inc['initial_reporter_log'], 0, 50)) . '..."' : 'No initial notes';
                                    $total_time = ($inc['duration_minutes'] !== null) ? $inc['duration_minutes'] . 'm' : 'N/A';
                                ?>
                                <tr class="clickable-row" onclick="openMobileModal(this)">
                                    <td>
                                        <div class="incident-title-text"><?php echo htmlspecialchars($inc['incident_type']); ?></div>
                                        <div class="incident-date-text"><?php echo $inc['date_str']; ?></div>
                                        <div class="reporter-log-snippet"><?php echo $rep_log; ?></div>
                                    </td>
                                    <td>
                                        <div style="font-weight: 800; font-size: 0.95rem;"><?php echo htmlspecialchars($inc['barangay']); ?></div>
                                        <span class="badge <?php echo $badge; ?>" style="margin-top: 5px;"><?php echo strtoupper($inc['severity']); ?></span>
                                    </td>
                                    <td>
                                        <div class="timeline-meta">
                                            <div><b>Reported:</b> <span><?php echo $inc['reported_time'] ?: 'Unknown'; ?></span></div>
                                            <div><b>Arrived:</b> <span><?php echo $inc['arrived_time'] ?: 'Unknown'; ?></span></div>
                                            <div><b>Resolved:</b> <span><?php echo $inc['resolved_time'] ?: 'Pending'; ?></span></div>
                                            <div style="color: var(--color-critical, #d32f2f); font-weight: 800;"><b>Total Time:</b> <span><?php echo $total_time; ?></span></div>
                                        </div>
                                    </td>
                                    <td style="text-align:center;">
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

            <!-- 2. Breakdown & Seasonality Column -->
            <div class="charts-column">
                <div class="sitting-panel">
                    <div class="panel-header">
                        <h2><i class='bx bxs-pie-chart-alt-2' style="color:#f57c00;"></i> Breakdown</h2>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="breakdownPieChart"></canvas>
                    </div>
                </div>

                <div class="sitting-panel" style="margin-top: 20px;">
                    <div class="panel-header">
                        <h2><i class='bx bx-line-chart' style="color:#d32f2f;"></i> Disaster Seasonality</h2>
                        <select id="seasonalityFilter" class="filter-dropdown" onchange="renderSeasonality()">
                            <option value="all">All Time</option>
                            <option value="year">Past Year</option>
                            <option value="month">Past Month</option>
                            <option value="week">Past Week</option>
                        </select>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="seasonalityLineChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- BOTTOM SECTION: Report Bin & Rejection Audit (6 Category Cards + Audit Directory) -->
        <div class="sitting-panel report-bin-panel">
            <div class="panel-header">
                <div>
                    <h2><i class='bx bxs-trash' style="color:#d32f2f;"></i> Report Bin & Rejection Audit</h2>
                    <p style="color: var(--text-secondary, #666); font-size: 0.82rem; font-weight: 700; margin: 4px 0 0 0;">
                        Granular breakdown of filtered false alarms, duplicates, and out-of-jurisdiction calls for <?php echo htmlspecialchars($my_brgy); ?>.
                    </p>
                </div>
                <span class="count-pill"><?php echo $total_rejections; ?> TOTAL REJECTIONS</span>
            </div>

            <!-- 6 KPI Rejection Category Cards -->
            <div class="audit-kpi-grid">
                <div class="audit-card red">
                    <span class="audit-title">FALSE ALARM</span>
                    <div class="audit-body">
                        <h3><?php echo $rejection_categories['False Alarm']; ?></h3>
                        <span class="audit-pct"><?php echo $calc_pct($rejection_categories['False Alarm'], $total_rejections); ?>%</span>
                    </div>
                </div>
                <div class="audit-card orange">
                    <span class="audit-title">OUT OF JURISDICTION</span>
                    <div class="audit-body">
                        <h3><?php echo $rejection_categories['Out of Jurisdiction']; ?></h3>
                        <span class="audit-pct"><?php echo $calc_pct($rejection_categories['Out of Jurisdiction'], $total_rejections); ?>%</span>
                    </div>
                </div>
                <div class="audit-card blue">
                    <span class="audit-title">DUPLICATE REPORT</span>
                    <div class="audit-body">
                        <h3><?php echo $rejection_categories['Duplicate Report']; ?></h3>
                        <span class="audit-pct"><?php echo $calc_pct($rejection_categories['Duplicate Report'], $total_rejections); ?>%</span>
                    </div>
                </div>
                <div class="audit-card purple">
                    <span class="audit-title">PRANK / SPAM</span>
                    <div class="audit-body">
                        <h3><?php echo $rejection_categories['Prank / Spam']; ?></h3>
                        <span class="audit-pct"><?php echo $calc_pct($rejection_categories['Prank / Spam'], $total_rejections); ?>%</span>
                    </div>
                </div>
                <div class="audit-card teal">
                    <span class="audit-title">INCOMPLETE INFO</span>
                    <div class="audit-body">
                        <h3><?php echo $rejection_categories['Incomplete Information']; ?></h3>
                        <span class="audit-pct"><?php echo $calc_pct($rejection_categories['Incomplete Information'], $total_rejections); ?>%</span>
                    </div>
                </div>
                <div class="audit-card gray">
                    <span class="audit-title">OTHER / UNSPECIFIED</span>
                    <div class="audit-body">
                        <h3><?php echo $rejection_categories['Other / Unspecified']; ?></h3>
                        <span class="audit-pct"><?php echo $calc_pct($rejection_categories['Other / Unspecified'], $total_rejections); ?>%</span>
                    </div>
                </div>
            </div>

            <!-- Rejection Audit Records Table -->
            <div class="table-scroll-wrapper" style="margin-top: 15px;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>INCIDENT & CITIZEN INPUT</th>
                            <th>JURISDICTION</th>
                            <th>REJECTION AUDIT & OFFICER REASON</th>
                            <th style="text-align:center;">EVIDENCE</th>
                        </tr>
                    </thead>
                    <tbody id="binTableBody">
                        <?php if (empty($rejected_incidents)): ?>
                            <tr><td colspan="4" style="text-align: center; color: #888; padding: 35px; font-weight: bold;">Report Bin is clean. No rejected incidents recorded.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rejected_incidents as $rinc): 
                                $safe_img = addslashes($rinc['image_path'] ?? '');
                                $safe_type = addslashes($rinc['incident_type']);
                                $safe_brgy = addslashes($rinc['barangay']);
                                $reject_reason = $rinc['admin_remarks'] ?: ($rinc['spam_reason'] ?: 'Rejected without note');
                                $r_log = !empty($rinc['initial_reporter_log']) ? '"' . htmlspecialchars(substr($rinc['initial_reporter_log'], 0, 50)) . '..."' : 'No citizen notes';
                            ?>
                            <tr class="clickable-row" onclick="openMobileModal(this)">
                                <td>
                                    <div class="incident-title-text" style="color: #d32f2f;"><?php echo htmlspecialchars($rinc['incident_type']); ?></div>
                                    <div class="incident-date-text"><?php echo $rinc['date_str']; ?></div>
                                    <div class="reporter-log-snippet"><?php echo $r_log; ?></div>
                                </td>
                                <td>
                                    <span style="font-weight: 800; font-size: 0.95rem;"><?php echo htmlspecialchars($rinc['barangay']); ?></span>
                                </td>
                                <td>
                                    <div style="font-size: 0.85rem; font-weight: 700; line-height: 1.4; color: var(--text-secondary, #475569);">
                                        <i class='bx bx-error-circle' style="color: #d32f2f; vertical-align: middle;"></i>
                                        <?php echo htmlspecialchars($reject_reason); ?>
                                    </div>
                                </td>
                                <td style="text-align:center;">
                                    <div class="btn-action-group">
                                        <button type="button" class="btn-table-icon bg-green" onclick="event.stopPropagation(); viewEvidence('<?php echo $safe_img; ?>', '<?php echo $safe_type; ?>', '<?php echo $safe_brgy; ?>')"><i class='bx bx-image'></i></button>
                                        <button type="button" class="btn-table-icon bg-blue" data-logs="<?= htmlspecialchars($rinc['all_logs'] ?? '', ENT_QUOTES, 'UTF-8') ?>" data-type="<?= htmlspecialchars($rinc['incident_type'], ENT_QUOTES, 'UTF-8') ?>" onclick="event.stopPropagation(); openLogModal(this)"><i class='bx bx-list-ul'></i></button>
                                        <button type="button" class="btn-table-icon bg-red" onclick="event.stopPropagation(); deleteArchived(<?php echo (int)$rinc['id']; ?>)" title="Delete record"><i class='bx bx-trash'></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- GENERATE REPORT MODAL (Matches Superadmin Analytics) -->
    <div id="reportModal" class="modal">
        <div class="modal-content" style="max-width: 480px; text-align: left; padding: 26px;">
            <div class="close-modal" onclick="closeModal('reportModal')"><i class='bx bx-x'></i></div>
            <h3 style="margin-bottom: 18px; font-weight: 900; font-size: 1.3rem; display:flex; align-items:center; gap:8px;">
                <i class='bx bxs-file-pdf' style="color: #d32f2f;"></i> Generate Sector Analytics Report
            </h3>
            
            <div style="display: flex; flex-direction: column; gap: 14px;">
                <div>
                    <label class="modal-field-label">DATA SOURCE SCOPE</label>
                    <select id="modalReportSource" class="filter-dropdown" style="width: 100%;">
                        <option value="vault">Incident Archive Vault (Resolved & Archived)</option>
                        <option value="bin">Report Bin & Rejection Audit Only</option>
                        <option value="all">Comprehensive Audit (Vault + Rejection Bin)</option>
                    </select>
                </div>

                <div>
                    <label class="modal-field-label">TIMEFRAME QUERY</label>
                    <select id="modalReportTime" class="filter-dropdown" style="width: 100%;">
                        <option value="all">∞ All Time</option>
                        <option value="year">📅 Past Year</option>
                        <option value="quarter">📊 Past Quarter</option>
                        <option value="month">📆 Past Month</option>
                        <option value="week">🗓️ Past Week</option>
                        <option value="today">⚡ Today</option>
                    </select>
                </div>

                <div>
                    <label class="modal-field-label">INCIDENT TYPE</label>
                    <select id="modalReportType" class="filter-dropdown" style="width: 100%;">
                        <option value="all">🌍 All Types</option>
                    </select>
                </div>

                <div style="background: var(--surface-subtle, #f1f5f9); padding: 12px 14px; border-radius: 10px; border: 1px solid var(--border-color, #e2e8f0);">
                    <label style="display: flex; align-items: center; gap: 10px; font-weight: 700; font-size: 0.85rem; cursor: pointer;">
                        <input type="checkbox" id="modalGroupCategories" style="width: 18px; height: 18px; accent-color: #d32f2f;" checked>
                        <span>Group results by Incident Type (Separate category sections)</span>
                    </label>
                </div>

                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button type="button" class="btn-generate-report" style="background: #2e7d32; flex: 1; justify-content: center;" onclick="runReportExport('csv')">
                        <i class='bx bx-table'></i> Export CSV
                    </button>
                    <button type="button" class="btn-generate-report" style="flex: 1; justify-content: center;" onclick="runReportExport('pdf')">
                        <i class='bx bxs-file-pdf'></i> Print / PDF
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- LOG MODAL -->
    <div id="viewLogsModal" class="modal">
        <div class="modal-content" style="position: relative;">
            <div class="close-modal" onclick="closeModal('viewLogsModal')"><i class='bx bx-x'></i></div>
            <div class="modal-header" style="border: none;">
                <h3 id="logTitle" style="margin:0; color:#1976d2; font-weight:800; display:flex; align-items:center; gap:8px;"></h3>
            </div>
            <div id="logContainer" style="max-height:400px; overflow-y:auto; padding-right:5px;"></div>
        </div>
    </div>

    <!-- EVIDENCE MODAL -->
    <div id="evidenceModal" class="modal" style="background: rgba(0,0,0,0.85);">
        <div class="modal-content" style="background: transparent; box-shadow: none; text-align: center; max-width: 800px; border: none; position: relative;">
            <div class="close-modal" onclick="closeModal('evidenceModal')" style="color: white; font-size: 2.5rem; top: -40px; right: 0; background: none; border: none;"><i class='bx bx-x'></i></div>
            <img id="evidenceImageFull" src="" style="max-width: 100%; max-height: 80vh; border-radius: 12px; border: 3px solid #555;">
            <p id="evidenceCaption" style="color: white; margin-top: 15px; font-size: 1.2rem; font-weight: bold;"></p>
        </div>
    </div>

    <!-- UNIVERSAL ALERT/CONFIRM MODAL -->
    <div id="universalModal" class="modal" style="z-index: 10010;">
        <div class="modal-content" style="text-align: center; width: 350px; padding: 40px; position: relative;">
            <i id="uniModalIcon" class='bx bxs-help-circle' style="font-size: 4rem; margin-bottom: 15px;"></i>
            <h3 id="uniModalTitle" style="margin-bottom: 10px;">Confirm</h3>
            <p id="uniModalText" style="margin-bottom: 25px; color: #888; font-weight: 600;">Message</p>
            <div style="display: flex; gap: 12px;" id="uniModalButtons"></div>
        </div>
    </div>

    <!-- MOBILE ROW DETAILS MODAL -->
    <div id="mobileDetailsModal" class="modal" style="z-index: 10005;">
        <div class="modal-content" style="max-width: 90%; padding: 24px; position: relative;">
            <div class="close-modal" onclick="closeModal('mobileDetailsModal')"><i class='bx bx-x'></i></div>
            <h3 id="m-inc-title" style="margin-bottom: 16px; font-weight: 900; font-size: 1.3rem; padding-right: 30px; color: var(--text-primary);">Incident Log</h3>
            <div id="m-inc-body" style="display: flex; flex-direction: column;"></div>
        </div>
    </div>

<script>
    window.allIncidents      = <?= $js_incidents ?? '[]' ?>;
    window.rejectedIncidents = <?= $js_rejected ?? '[]' ?>;
    window.pieLabels         = <?= $pie_labels ?? '[]' ?>;
    window.pieValues         = <?= $pie_values ?? '[]' ?>;
    window.currentSector     = <?= json_encode($my_brgy ?: 'Sector') ?>;
</script>
<script src="../js/client/analytics.js?v=<?= filemtime('../js/client/analytics.js') ?>"></script>
</body>
</html>
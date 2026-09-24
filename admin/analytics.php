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

session_write_close();

class AnalyticsQueryBuilder {
    public static function build(string $period, string $dateColumn = 'created_at'): array {
        switch (strtolower(trim($period))) {
            case 'today':
                return ['clause' => " AND DATE($dateColumn) = CURDATE() ", 'group' => "DATE_FORMAT($dateColumn, '%h:00 %p')"];
            case 'weekly':
            case 'week':
                return ['clause' => " AND $dateColumn >= DATE_SUB(NOW(), INTERVAL 1 WEEK) ", 'group' => "DATE_FORMAT($dateColumn, '%a (%b %d)')"];
            case 'monthly':
            case 'month':
                return ['clause' => " AND $dateColumn >= DATE_SUB(NOW(), INTERVAL 1 MONTH) ", 'group' => "DATE_FORMAT($dateColumn, 'Week %u (%b)')"];
            case 'yearly':
            case 'year':
                return ['clause' => " AND $dateColumn >= DATE_SUB(NOW(), INTERVAL 1 YEAR) ", 'group' => "DATE_FORMAT($dateColumn, '%b %Y')"];
            case 'all':
            default:
                return ['clause' => "", 'group' => "DATE_FORMAT($dateColumn, '%Y-%m')"];
        }
    }
}

function getCloudinaryUrl(?string $path): string {
    if (empty($path) || $path === 'NULL' || $path === 'null') return '';
    $clean = trim($path);
    if (str_starts_with($clean, '/http')) $clean = substr($clean, 1);
    if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://')) return $clean;
    $clean = ltrim(str_replace(['dasma_api/', 'dasma-api/'], '', $clean), '/');
    return 'https://res.cloudinary.com/wyxsiraw/image/upload/' . $clean;
}

function parseRejectionDetails(?string $rawReason): array {
    if (empty($rawReason)) {
        return ['officer' => 'Command Admin', 'category' => 'False Alarm', 'notes' => 'No specific notes recorded.'];
    }
    $officer = 'Officer';
    $category = 'False Alarm';
    $notes = '';

    if (preg_match('/Rejected by ([^\[\(]+)(?:\(([^\)]+)\))?\s*(?:\[([^\]]+)\])?(?:\s*:\s*(.*))?/i', $rawReason, $matches)) {
        $officerName = trim($matches[1] ?? '');
        $subBrgy = trim($matches[2] ?? '');
        $cat = trim($matches[3] ?? '');
        $extra = trim($matches[4] ?? '');

        if (!empty($subBrgy) && empty($cat)) {
            if (stripos($subBrgy, 'Alarm') !== false || stripos($subBrgy, 'Prank') !== false) {
                $category = $subBrgy;
                $officer = $officerName;
            } else {
                $officer = $officerName . " ({$subBrgy})";
            }
        } else {
            $officer = $officerName . (!empty($subBrgy) ? " ({$subBrgy})" : "");
            if (!empty($cat)) $category = $cat;
        }
        $notes = !empty($extra) ? $extra : $category;
    } else {
        $notes = $rawReason;
        if (stripos($rawReason, 'false alarm') !== false) $category = 'False Alarm';
        elseif (stripos($rawReason, 'prank') !== false || stripos($rawReason, 'spam') !== false) $category = 'Prank / Spam';
        elseif (stripos($rawReason, 'incomplete') !== false) $category = 'Incomplete Information';
    }

    return ['officer' => $officer ?: 'Command Officer', 'category' => $category ?: 'False Alarm', 'notes' => $notes ?: 'False alarm verification.'];
}

// 1. FILTER CONTROLS
$type_filter       = $_GET['type'] ?? 'all';
$time_filter       = $_GET['time'] ?? 'all'; 
$vault_time_filter = $_GET['vault_time'] ?? 'all';

$vault_query_cfg = AnalyticsQueryBuilder::build($vault_time_filter, 'i.created_at');
$chart_query_cfg = AnalyticsQueryBuilder::build($time_filter, 'created_at');

$where_clause = "WHERE i.status = 'archived' " . $vault_query_cfg['clause'];
$params = [];
$types = "";

if ($type_filter !== 'all') {
    $where_clause .= " AND i.incident_type LIKE ?";
    $types .= "s";
    $params[] = "%" . $type_filter . "%";
}

// 2. ARCHIVED INCIDENTS
$query = "
    SELECT i.id, i.barangay, i.incident_type, i.severity, i.latitude, i.longitude, i.image_path, i.created_at,
           DATE_FORMAT(i.created_at, '%b %d, %Y - %h:%i %p') as date_str,
           (SELECT log_message FROM incident_logs WHERE incident_id = i.id ORDER BY created_at ASC LIMIT 1) as initial_log,
           (SELECT MIN(created_at) FROM incident_logs WHERE incident_id = i.id AND LOWER(log_message) LIKE '%scene%') as arrived_at,
           (SELECT MAX(created_at) FROM incident_logs WHERE incident_id = i.id) as resolved_at,
           (SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(il.created_at, '%h:%i %p'), '|-|', IFNULL(u.username, 'System'), '|-|', il.log_message) ORDER BY il.created_at DESC, il.id DESC SEPARATOR '|||') 
            FROM incident_logs il LEFT JOIN users u ON il.user_id = u.id WHERE il.incident_id = i.id) as all_logs
    FROM incidents i 
    $where_clause
    ORDER BY i.created_at DESC
";
$stmt = $conn->prepare($query);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$archived_incidents = ($res = $stmt->get_result()) ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();
$js_incidents = json_encode($archived_incidents ?: []);

// 3. DETAILED REPORT BIN WITH REJECTION AUDIT
$bin_query = "
    SELECT i.id, i.barangay, i.incident_type, i.status, i.image_path, i.created_at, i.admin_remarks,
           DATE_FORMAT(i.created_at, '%b %d, %Y - %h:%i %p') as date_str,
           sr.reason as spam_reason,
           DATE_FORMAT(sr.created_at, '%b %d, %Y - %h:%i %p') as rejected_date_str,
           (SELECT log_message FROM incident_logs WHERE incident_id = i.id ORDER BY created_at ASC LIMIT 1) as initial_log,
           (SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(il.created_at, '%h:%i %p'), '|-|', IFNULL(u.username, 'System'), '|-|', il.log_message) ORDER BY il.created_at DESC, il.id DESC SEPARATOR '|||') 
            FROM incident_logs il LEFT JOIN users u ON il.user_id = u.id WHERE il.incident_id = i.id) as all_logs
    FROM incidents i 
    LEFT JOIN spam_reports sr ON i.id = sr.incident_id
    WHERE i.status IN ('rejected', 'spam', 'out_of_range')
    ORDER BY i.created_at DESC
";
$stmt_bin = $conn->prepare($bin_query);
$stmt_bin->execute();
$bin_incidents = ($bin_res = $stmt_bin->get_result()) ? $bin_res->fetch_all(MYSQLI_ASSOC) : [];
$stmt_bin->close();
$js_bin_incidents = json_encode($bin_incidents ?: []);

// 4. SUMMARY BREAKDOWN STATS
$reject_categories_count = [
    'False Alarm'             => 0,
    'Prank / Spam'            => 0,
    'Incomplete Information'  => 0,
    'Other / Unspecified'     => 0
];

foreach ($bin_incidents as &$bin_row) {
    $parsed = parseRejectionDetails($bin_row['spam_reason'] ?: $bin_row['admin_remarks']);
    $bin_row['parsed_officer']  = $parsed['officer'];
    $bin_row['parsed_category'] = $parsed['category'];
    $bin_row['parsed_notes']    = $parsed['notes'];

    $matched = false;
    foreach (array_keys($reject_categories_count) as $k) {
        if (stripos($parsed['category'], str_replace(' / Spam', '', $k)) !== false || stripos($parsed['category'], $k) !== false) {
            $reject_categories_count[$k]++;
            $matched = true;
            break;
        }
    }
    if (!$matched) {
        $reject_categories_count['Other / Unspecified']++;
    }
}
unset($bin_row);

$total_rejected = count($bin_incidents);

// 5. CHARTS & BROADCASTS
$broadcast_history = ($b_res = $conn->query("SELECT *, DATE_FORMAT(created_at, '%M %d, %Y - %h:%i %p') as date_str FROM broadcasts ORDER BY created_at DESC")) ? $b_res->fetch_all(MYSQLI_ASSOC) : [];
$types_res = $conn->query("SELECT DISTINCT incident_type FROM incidents WHERE status = 'archived'");
$unique_types = [];
while ($t = $types_res->fetch_assoc()) { $unique_types[] = $t['incident_type']; }

$chart_type_res = $conn->query("SELECT incident_type, COUNT(*) as count FROM incidents WHERE status = 'archived' {$chart_query_cfg['clause']} GROUP BY incident_type ORDER BY count DESC");
$type_labels = []; $type_data = []; $type_colors = [];
$palette = ['#1976d2', '#d32f2f', '#f57c00', '#388e3c', '#8e24aa', '#fbc02d', '#0097a7', '#0288d1'];
$color_idx = 0;
while ($row = $chart_type_res->fetch_assoc()) {
    $type_labels[] = strtoupper($row['incident_type']);
    $type_data[] = $row['count'];
    $type_colors[] = $palette[$color_idx % count($palette)];
    $color_idx++;
}

$dates_res = $conn->query("SELECT created_at FROM incidents WHERE status NOT IN ('rejected', 'spam', 'out_of_range')");
$seasonality_dates = [];
while ($row = $dates_res->fetch_assoc()) { $seasonality_dates[] = $row['created_at']; }

$evac_res = $conn->query("SELECT name, capacity, current_occupants FROM evacuation_centers ORDER BY current_occupants DESC LIMIT 10");
$evac_labels = []; $evac_capacity = []; $evac_occupants = [];
while ($row = $evac_res->fetch_assoc()) {
    $evac_labels[] = strlen($row['name']) > 15 ? substr($row['name'], 0, 15) . '...' : $row['name'];
    $evac_capacity[] = $row['capacity'];
    $evac_occupants[] = $row['current_occupants'];
}

$heat_coords = [];
foreach ($archived_incidents as $inc) {
    if (!empty($inc['latitude']) && !empty($inc['longitude'])) {
        $heat_coords[] = [(float)$inc['latitude'], (float)$inc['longitude'], 0.8];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Global Analytics | Command Center</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.heat/dist/leaflet-heat.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
    <link rel="stylesheet" href="../css/admin/navbar.css?v=<?= filemtime('../css/admin/navbar.css') ?>">
    <link rel="stylesheet" href="../css/admin/analytics.css?v=<?= filemtime('../css/admin/analytics.css') ?>">
</head>
<body>
    
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header style="margin-bottom: 30px; display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 15px;">
            <div>
                <h1 style="color: #333; margin: 0; font-size: 2.2rem;">Global Analytics</h1>
                <p style="color: #666; margin-top: 5px; font-weight: 800;">Command Center City-Wide Reports</p>
            </div>
            <div style="display: flex; gap: 12px; flex-wrap: wrap;">
    <button type="button" onclick="openReportModal()" class="btn-action" style="background: #d32f2f;">
        <i class='bx bxs-file-pdf' style="font-size: 1.2rem;"></i> Generate Report
    </button>
    <button type="button" onclick="backupAllReports()" class="btn-action" style="background: #455a64;">
        <i class='bx bx-archive-in' style="font-size: 1.2rem;"></i> Backup Reports
    </button>
</div>
        </header>

        <div class="dashboard-grid">
            <div class="main-col">
                <div class="sitting-panel" style="flex: none;">
                    <div class="panel-header">
                        <h2><i class='bx bxs-archive' style="color:#607d8b;"></i> Incident Archive Vault</h2>
                        <div style="display:flex; gap: 10px; align-items: center;">
                            <select id="vaultTimeFilter" class="filter-select" onchange="applyFilters()">
                                <option value="all" <?= ($vault_time_filter === 'all') ? 'selected' : '' ?>>All Time</option>
                                <option value="today" <?= ($vault_time_filter === 'today') ? 'selected' : '' ?>>Today</option>
                                <option value="week" <?= ($vault_time_filter === 'week') ? 'selected' : '' ?>>1 Week</option>
                                <option value="month" <?= ($vault_time_filter === 'month') ? 'selected' : '' ?>>1 Month</option>
                                <option value="year" <?= ($vault_time_filter === 'year') ? 'selected' : '' ?>>1 Year</option>
                            </select>
                            <select id="typeFilter" class="filter-select" onchange="applyFilters()">
                                <option value="all">All Types</option>
                                <?php foreach($unique_types as $type): ?>
                                    <option value="<?= htmlspecialchars($type) ?>" <?= ($type_filter === $type) ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table" id="archiveTable">
                            <thead>
                                <tr>
                                    <th style="width: 35%;">Incident & Logs</th>
                                    <th style="width: 25%;">Location & Status</th>
                                    <th style="width: 25%;">Response Timeline</th>
                                    <th style="width: 15%; text-align: center;" class="exclude-export">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($archived_incidents)): ?>
                                    <tr><td colspan="4" style="text-align: center; color: #777; padding: 60px; font-weight: 700;">No records found in the vault.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($archived_incidents as $inc): 
                                        $badge_class = strtolower($inc['severity']) === 'critical' ? 'critical' : (strtolower($inc['severity']) === 'minor' ? 'info' : 'major');
                                        $logs_js = htmlspecialchars($inc['all_logs'] ?? 'No logs recorded.', ENT_QUOTES, 'UTF-8');
                                        $created = strtotime($inc['created_at']);
                                        $arrived = $inc['arrived_at'] ? strtotime($inc['arrived_at']) : null;
                                        $resolved = $inc['resolved_at'] ? strtotime($inc['resolved_at']) : null;
                                        $duration_str = ($resolved && $created && $resolved >= $created) ? floor(($resolved - $created) / 60) . "m" : "N/A";
                                        $vaultImg = getCloudinaryUrl($inc['image_path'] ?? '');
                                    ?>
                                    <tr class="clickable-row" onclick="openMobileModal(this, 'archive')">
                                        <td>
                                            <div style="font-weight: 900; font-size: 1.15rem; color: #1976d2; margin-bottom: 2px;">
                                                <?= htmlspecialchars($inc['incident_type']) ?>
                                            </div>
                                            <div style="font-size: 0.8rem; color: #888; font-weight: 600; margin-bottom: 6px;"><?= $inc['date_str'] ?></div>
                                            <div class="user-log-box">"<?= htmlspecialchars($inc['initial_log'] ?? 'No user details provided.') ?>"</div>
                                        </td>
                                        <td>
                                            <span style="font-weight: 800; font-size: 0.95rem; color: #222; display:block; margin-bottom: 8px;"><?= htmlspecialchars($inc['barangay']) ?></span>
                                            <span class="badge <?= $badge_class ?>"><?= strtoupper($inc['severity']) ?></span>
                                        </td>
                                        <td>
                                            <div class="timeline-text">
                                                <div class="timeline-row"><strong>Reported:</strong> <span><?= date('h:i A', $created) ?></span></div>
                                                <div class="timeline-row"><strong style="color:#388e3c;">Arrived:</strong> <span><?= $arrived ? date('h:i A', $arrived) : 'Unknown' ?></span></div>
                                                <div class="timeline-row"><strong style="color:#1976d2;">Resolved:</strong> <span><?= $resolved ? date('h:i A', $resolved) : 'Unknown' ?></span></div>
                                                <div class="duration-text" style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #edf2f7; font-weight: 900; color: #d32f2f; display: flex; justify-content: space-between;">
                                                    <span>Total Time:</span> <span><?= $duration_str ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="text-align: center; vertical-align: middle;" class="exclude-export">
                                            <div class="btn-action-group">
                                                <?php if (!empty($vaultImg)): ?>
                                                    <button class="btn-table-icon bg-green" onclick="event.stopPropagation(); viewPhoto('<?= htmlspecialchars($vaultImg, ENT_QUOTES, 'UTF-8') ?>')" title="View Evidence"><i class='bx bx-image'></i></button>
                                                <?php endif; ?>
                                                <button class="btn-table-icon bg-blue" onclick="event.stopPropagation(); viewLogs('<?= $logs_js ?>', '<?= addslashes($inc['incident_type']) ?>')" title="View Logs"><i class='bx bx-list-ul'></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- REPORT BIN & REJECTION SUMMARY AUDIT -->
                <div class="sitting-panel" style="flex: none;">
                    <div class="panel-header" style="align-items: flex-start; flex-direction: column; gap: 8px;">
                        <div style="display:flex; justify-content:space-between; width:100%; align-items:center;">
                            <h2><i class='bx bxs-trash-alt' style="color:#d32f2f;"></i> Report Bin & Rejection Audit</h2>
                            <span class="badge" style="background:#212121; font-size:0.8rem; padding: 8px 14px;"><?= $total_rejected ?> Total Rejections</span>
                        </div>
                        <p style="font-size: 0.85rem; color: #777; margin: 0; font-weight: 600;">Granular breakdown of filtered false alarms, pranks, and incomplete incident reports</p>
                    </div>

                    <!-- SUMMARY STATS TILES -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 25px;">
                        <?php foreach ($reject_categories_count as $cat_name => $count): 
                            $accent_border = match($cat_name) {
                                'False Alarm'            => '#d32f2f',
                                'Prank / Spam'           => '#6a1b9a',
                                'Incomplete Information' => '#00838f',
                                default                  => '#616161'
                            };
                            $pct = $total_rejected > 0 ? round(($count / $total_rejected) * 100) : 0;
                        ?>
                            <div style="background: #ffffff; border: 1px solid #e0e6ed; border-left: 5px solid <?= $accent_border ?>; padding: 14px; border-radius: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.03);">
                                <div style="font-size: 0.72rem; color: #888; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;"><?= $cat_name ?></div>
                                <div style="display:flex; justify-content:space-between; align-items:baseline; margin-top: 6px;">
                                    <span style="font-size: 1.5rem; font-weight: 900; color: #222;"><?= $count ?></span>
                                    <span style="font-size: 0.75rem; font-weight: 800; color: <?= $accent_border ?>;"><?= $pct ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- DETAILED REJECTION TABLE -->
                    <div class="table-scroll-wrapper">
                        <table class="data-table" id="binTable">
                            <thead>
                                <tr>
                                    <th style="width: 28%;">Incident & Citizen Input</th>
                                    <th style="width: 20%;">Jurisdiction</th>
                                    <th style="width: 38%;">Rejection Audit & Officer Reason</th>
                                    <th style="width: 14%; text-align: center;" class="exclude-export">Evidence</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bin_incidents)): ?>
                                    <tr><td colspan="4" style="text-align: center; color: #777; padding: 40px; font-weight: 700;">Report bin is clean. No rejected incidents.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($bin_incidents as $bin): 
                                        $logs_js = htmlspecialchars($bin['all_logs'] ?? 'No logs recorded.', ENT_QUOTES, 'UTF-8');
                                        $binImg = getCloudinaryUrl($bin['image_path'] ?? '');
                                        $catColor = match($bin['parsed_category']) {
                                            'False Alarm'            => '#d32f2f',
                                            'Prank', 'Prank / Spam'  => '#6a1b9a',
                                            'Incomplete Information' => '#00838f',
                                            default                  => '#424242'
                                        };
                                    ?>
                                    <tr class="clickable-row" onclick="openMobileModal(this, 'bin')">
                                        <td>
                                            <div style="font-weight: 900; font-size: 1.05rem; color: #333; margin-bottom: 2px;">
                                                <?= htmlspecialchars($bin['incident_type']) ?>
                                            </div>
                                            <div style="font-size: 0.78rem; color: #888; font-weight: 600; margin-bottom: 6px;"><?= $bin['date_str'] ?></div>
                                            <div class="user-log-box" style="border-left-color: #90caf9;">
                                                <strong style="color:#1976d2; display:block; font-size: 0.7rem; text-transform: uppercase;">Citizen Description:</strong>
                                                "<?= htmlspecialchars($bin['initial_log'] ?? 'No user log entered.') ?>"
                                            </div>
                                        </td>
                                        <td>
                                            <span style="font-weight: 800; font-size: 0.95rem; color: #222; display:block; margin-bottom: 6px;"><?= htmlspecialchars($bin['barangay']) ?></span>
                                            <span class="badge" style="background: #424242;">REJECTED</span>
                                        </td>
                                        <td>
                                            <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                                <span class="badge" style="background: <?= $catColor ?>; padding: 4px 8px; font-size: 0.65rem;">
                                                    <?= strtoupper($bin['parsed_category']) ?>
                                                </span>
                                                <small style="color: #666; font-weight: 700;">
                                                    <i class='bx bx-user-check' style="color:#1976d2;"></i> <?= htmlspecialchars($bin['parsed_officer']) ?>
                                                </small>
                                            </div>
                                            <div class="user-log-box" style="border-left-color: <?= $catColor ?>; background: #fff; margin-top: 4px;">
                                                <strong style="display:block; font-size: 0.7rem; color: #555; text-transform:uppercase;">Official Explanation:</strong>
                                                <?= htmlspecialchars($bin['parsed_notes']) ?>
                                            </div>
                                        </td>
                                        <td style="text-align: center; vertical-align: middle;" class="exclude-export">
                                            <div class="btn-action-group">
                                                <?php if (!empty($binImg)): ?>
                                                    <button class="btn-table-icon bg-green" onclick="event.stopPropagation(); viewPhoto('<?= htmlspecialchars($binImg, ENT_QUOTES, 'UTF-8') ?>')" title="View Evidence"><i class='bx bx-image'></i></button>
                                                <?php endif; ?>
                                                <button class="btn-table-icon bg-dark" onclick="event.stopPropagation(); viewLogs('<?= $logs_js ?>', '<?= addslashes($bin['incident_type']) ?>')" title="Audit Trail"><i class='bx bx-list-ul'></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="sitting-panel" style="flex: none;">
                    <div class="panel-header">
                        <h2><i class='bx bxs-home-heart' style="color:#1976d2;"></i> Evac Center Capacity</h2>
                    </div>
                    <div class="chart-wrapper">
                        <?php if (empty($evac_labels)): ?>
                            <div class="empty-overlay">No evacuation centers active.</div>
                        <?php endif; ?>
                        <canvas id="evacOverflowChart"></canvas>
                    </div>
                </div>

                <div class="sitting-panel" style="flex: none;">
                    <div class="panel-header" style="margin-bottom: 5px;">
                        <h2><i class='bx bx-line-chart' style="color:#d32f2f;"></i> Disaster Seasonality</h2>
                        <select id="seasonalityFilter" class="filter-select" onchange="renderSeasonality()">
                            <option value="all">All Time</option>
                            <option value="day">Single Day</option>
                            <option value="weekly">Weekly Range (Up to 7 Days)</option>
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="yearly">Yearly</option>
                        </select>
                    </div>
                    <div class="chart-wrapper">
                        <?php if (empty($seasonality_dates)): ?>
                            <div class="empty-overlay" id="seasonalityOverlay">No seasonal data recorded.</div>
                        <?php endif; ?>
                        <canvas id="seasonalityLineChart"></canvas>
                    </div>
                </div>
            </div>

            <div class="side-col">
                <div class="sitting-panel" style="padding: 20px;">
                    <div class="panel-header" style="margin-bottom: 15px;">
                        <h2><i class='bx bxs-hot' style="color:#d32f2f;"></i> Spatial Hotspots</h2>
                    </div>
                    <div id="heatmap"></div>
                </div>

                <div class="sitting-panel" style="flex: none;">
                    <div class="panel-header" style="margin-bottom: 5px;">
                        <h2><i class='bx bxs-pie-chart-alt-2' style="color:#f57c00;"></i> Breakdown</h2>
                        <select id="timeFilter" class="filter-select" onchange="applyFilters()">
                            <option value="today" <?= ($time_filter === 'today') ? 'selected' : '' ?>>Today</option>
                            <option value="week" <?= ($time_filter === 'week') ? 'selected' : '' ?>>1 Week</option>
                            <option value="month" <?= ($time_filter === 'month') ? 'selected' : '' ?>>1 Month</option>
                            <option value="year" <?= ($time_filter === 'year') ? 'selected' : '' ?>>1 Year</option>
                            <option value="all" <?= ($time_filter === 'all') ? 'selected' : '' ?>>All Time</option>
                        </select>
                    </div>
                    <div class="chart-wrapper">
                        <?php if (empty($type_data)): ?>
                            <div class="empty-overlay">No data available.</div>
                        <?php else: ?>
                            <canvas id="typePieChart"></canvas>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="sitting-panel" style="flex: none;">
                    <div class="panel-header">
                        <h2><i class='bx bx-broadcast' style="color:#1976d2;"></i> Broadcast History</h2>
                    </div>
                    <div class="table-scroll-wrapper" style="max-height: 250px;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Alert Title & Date</th>
                                    <th>Severity</th>
                                    <th style="text-align: center;">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($broadcast_history)): ?>
                                    <tr><td colspan="3" style="text-align: center; color: #888; font-weight: bold; padding: 20px;">No broadcast history.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($broadcast_history as $b): ?>
                                <tr class="clickable-row" onclick="openMobileModal(this, 'broadcast')">
                                    <td>
                                        <b style="color: #222; font-size: 0.95rem; display:block;"><?= htmlspecialchars($b['title']) ?></b>
                                        <small style="color:#888; font-weight:600;"><?= $b['date_str'] ?></small>
                                    </td>
                                    <td><span class="badge <?= $b['severity'] ?>"><?= strtoupper($b['severity']) ?></span></td>
                                    <td style="text-align: center;">
                                        <?php if ($b['is_active']): ?>
                                            <button class="btn-action" style="background: #d32f2f; padding: 6px 12px; font-size: 0.75rem; border-radius: 8px;" onclick="event.stopPropagation(); stopBroadcast(<?= $b['id'] ?>)">
                                                <i class='bx bx-stop-circle'></i> STOP
                                            </button>
                                        <?php else: ?>
                                            <span class="badge ended">Ended</span>
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
        <!-- CUSTOM REPORT BUILDER MODAL -->
<div id="customReportModal" class="modal" style="z-index: 10007;">
    <div class="modal-content" style="max-width: 520px; padding: 30px;">
        <div class="close-modal" onclick="closeModal('customReportModal')"><i class='bx bx-x'></i></div>
        <div class="modal-header" style="margin-bottom: 20px;">
            <h3 style="margin: 0; color: #1976d2; display: flex; align-items: center; gap: 8px;">
                <i class='bx bxs-report'></i> Custom Report Generator
            </h3>
            <p style="margin: 4px 0 0; font-size: 0.8rem; color: #777;">Configure reporting timeframe, jurisdiction, and export format.</p>
        </div>

        <div class="modal-body">
            <!-- 1. Format Selection -->
            <label style="display:block; margin-bottom: 6px; font-weight: 800; font-size: 0.75rem; color: #555; text-transform: uppercase;">Export Format</label>
            <div style="display: flex; gap: 10px; margin-bottom: 16px;">
                <label style="flex: 1; display: flex; align-items: center; gap: 8px; border: 1px solid #ddd; padding: 10px; border-radius: 10px; cursor: pointer;">
                    <input type="radio" name="rep_format" value="pdf" checked>
                    <span style="font-weight: 700; font-size: 0.85rem;"><i class='bx bxs-file-pdf' style="color:#d32f2f;"></i> PDF Document</span>
                </label>
                <label style="flex: 1; display: flex; align-items: center; gap: 8px; border: 1px solid #ddd; padding: 10px; border-radius: 10px; cursor: pointer;">
                    <input type="radio" name="rep_format" value="csv">
                    <span style="font-weight: 700; font-size: 0.85rem;"><i class='bx bx-spreadsheet' style="color:#388e3c;"></i> CSV Spreadsheet</span>
                </label>
            </div>

            <!-- 2. Timeframe Selection -->
            <label style="display:block; margin-bottom: 6px; font-weight: 800; font-size: 0.75rem; color: #555; text-transform: uppercase;">Timeframe Interval</label>
            <select id="rep_period" class="filter-select" style="width: 100%; margin-bottom: 16px;" onchange="handlePeriodChange()">
                <option value="day">Single Day</option>
                <option value="weekly">Weekly Range (Up to 7 Days)</option>
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="yearly">Yearly</option>
            </select>

            <!-- 3. Dynamic Date Containers -->
            <div id="period_inputs_container" style="background: #f8f9fa; border: 1px solid #edf2f7; border-radius: 12px; padding: 14px; margin-bottom: 16px;">
                <!-- Day Input -->
                <div id="box_day">
                    <label style="display:block; margin-bottom: 4px; font-size: 0.75rem; font-weight: bold; color: #666;">Select Date</label>
                    <input type="date" id="rep_day" class="filter-select" style="width: 100%;">
                </div>

                <!-- Weekly Inputs -->
                <div id="box_weekly" style="display: none;">
                    <div style="display: flex; gap: 10px;">
                        <div style="flex: 1;">
                            <label style="display:block; margin-bottom: 4px; font-size: 0.75rem; font-weight: bold; color: #666;">Start Date (From)</label>
                            <input type="date" id="rep_week_start" class="filter-select" style="width: 100%;" onchange="validateWeekRange()">
                        </div>
                        <div style="flex: 1;">
                            <label style="display:block; margin-bottom: 4px; font-size: 0.75rem; font-weight: bold; color: #666;">End Date (To)</label>
                            <input type="date" id="rep_week_end" class="filter-select" style="width: 100%;" onchange="validateWeekRange()">
                        </div>
                    </div>
                    <small id="week_validation_msg" style="color: #d32f2f; font-weight: 700; display: block; margin-top: 6px; font-size: 0.72rem;"></small>
                </div>

                <!-- Monthly Input -->
                <div id="box_monthly" style="display: none;">
                    <label style="display:block; margin-bottom: 4px; font-size: 0.75rem; font-weight: bold; color: #666;">Select Month</label>
                    <input type="month" id="rep_month" class="filter-select" style="width: 100%;">
                </div>

                <!-- Quarterly Input -->
                <div id="box_quarterly" style="display: none;">
                    <div style="display: flex; gap: 10px;">
                        <div style="flex: 1;">
                            <label style="display:block; margin-bottom: 4px; font-size: 0.75rem; font-weight: bold; color: #666;">Year</label>
                            <select id="rep_quarter_year" class="filter-select" style="width: 100%;" onchange="updateQuarterOptions()"></select>
                        </div>
                        <div style="flex: 1;">
                            <label style="display:block; margin-bottom: 4px; font-size: 0.75rem; font-weight: bold; color: #666;">Quarter</label>
                            <select id="rep_quarter_q" class="filter-select" style="width: 100%;"></select>
                        </div>
                    </div>
                </div>

                <!-- Yearly Input -->
                <div id="box_yearly" style="display: none;">
                    <label style="display:block; margin-bottom: 4px; font-size: 0.75rem; font-weight: bold; color: #666;">Select Year</label>
                    <select id="rep_year" class="filter-select" style="width: 100%;"></select>
                </div>
            </div>

           <!-- 4. Incident / Accident Type Filter -->
            <label style="display:block; margin-bottom: 6px; font-weight: 800; font-size: 0.75rem; color: var(--text-muted, #555); text-transform: uppercase;">Incident / Accident Type</label>
            <select id="rep_incident_type" class="filter-select" style="width: 100%; margin-bottom: 16px;">
                <option value="all">All Incident & Accident Types</option>
                <?php foreach($unique_types as $type): ?>
                    <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                <?php endforeach; ?>
            </select>

            <!-- 5. Scope & Content -->
            <label style="display:block; margin-bottom: 6px; font-weight: 800; font-size: 0.75rem; color: var(--text-muted, #555); text-transform: uppercase;">Include Sections</label>
            <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px;">
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" id="inc_vault" checked> Incident Archive Vault (Resolved Emergencies)
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" id="inc_bin" checked> Report Bin (False Alarms & Rejections Audit)
                </label>
                <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer;">
                    <input type="checkbox" id="inc_group_type" checked> Categorize & Filter by Incident Type (Separate Tables per Emergency)
                </label>
            </div>

            <button type="button" class="btn-action" style="width: 100%; background: #1976d2; padding: 14px; font-size: 1rem;" onclick="processReportGeneration()">
                <i class='bx bx-download'></i> Generate & Download
            </button>
        </div>
    </div>
</div>
    </main>

    <div id="viewLogsModal" class="modal" style="z-index: 10006;">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; border-bottom:1px solid #f1f4f8; padding-bottom:15px;">
                <h3 id="logIncidentTitle" style="margin:0; font-weight:900; color:#1976d2; font-size:1.4rem;">Timeline</h3>
                <span style="cursor:pointer; font-size: 2.2rem; line-height:0.8; color:#aaa;" onclick="closeModal('viewLogsModal')">&times;</span>
            </div>
            <div id="logContainer" style="max-height: 450px; overflow-y: auto;"></div>
        </div>
    </div>

    <div id="viewPhotoModal" class="modal" style="background: rgba(0,0,0,0.85); z-index: 10006;">
        <div class="modal-content" style="background: transparent; box-shadow: none; text-align: center; max-width: 800px; border: none;">
            <span onclick="closeModal('viewPhotoModal')" style="color: white; font-size: 2.5rem; position: absolute; top: -40px; right: 0; cursor:pointer;">&times;</span>
            <img id="evidencePhotoViewer" src="" style="max-width: 100%; max-height: 80vh; object-fit: contain; border-radius:12px; border: 3px solid #555;">
        </div>
    </div>

    <div id="mobileAnalyticsModal" class="modal" style="z-index: 10005;">
        <div class="modal-content" style="max-width: 90%; padding: 24px;">
            <div class="close-modal" onclick="closeModal('mobileAnalyticsModal')" style="position: absolute; top: 12px; right: 12px; background: rgba(0,0,0,0.6); color: #fff; width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; z-index: 1000;"><i class='bx bx-x'></i></div>
            <h3 id="m-analytics-title" style="margin-bottom: 16px; font-weight: 900; font-size: 1.3rem; padding-right: 30px; color: var(--text-primary);"></h3>
            <div id="m-analytics-body" style="display: flex; flex-direction: column;"></div>
        </div>
    </div>                                       

<script>
    window.allIncidents      = <?= $js_incidents ?? '[]' ?>;
    window.binIncidents      = <?= $js_bin_incidents ?? '[]' ?>;
    window.allSeasonDates    = <?= json_encode($seasonality_dates ?? []) ?>;
    window.typeLabels        = <?= json_encode($type_labels ?? []) ?>;
    window.typeData          = <?= json_encode($type_data ?? []) ?>;
    window.typeColors        = <?= json_encode($type_colors ?? []) ?>;
    window.evacLabels        = <?= json_encode($evac_labels ?? []) ?>;
    window.evacOccupants     = <?= json_encode($evac_occupants ?? []) ?>;
    window.evacCapacity      = <?= json_encode($evac_capacity ?? []) ?>;
    window.heatData          = <?= json_encode($heat_coords) ?>;
</script>
<script src="../js/admin/analytics.js?v=<?= filemtime('../js/admin/analytics.js') ?>" defer></script>
</body>
</html>
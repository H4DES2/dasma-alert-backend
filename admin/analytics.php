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
function getAuthoritativeMonthName(mysqli $conn): string {
    $monthNames = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];

    $ch = curl_init('https://www.google.com');
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    $header = curl_exec($ch);
    curl_close($ch);

    if ($header && preg_match('/^Date:\s*(.*)$/mi', $header, $matches)) {
        $networkTime = strtotime(trim($matches[1]));
        if ($networkTime) {
            $m = (int)date('n', $networkTime);
            return $monthNames[$m] ?? 'October';
        }
    }

    $dbMax = $conn->query("SELECT MONTH(MAX(created_at)) AS m FROM incidents");
    if ($dbMax && $row = $dbMax->fetch_assoc()) {
        if (!empty($row['m'])) {
            $m = (int)$row['m'];
            return $monthNames[$m] ?? 'October';
        }
    }

    return $monthNames[(int)date('n')] ?? 'October';
}

$current_active_month = getAuthoritativeMonthName($conn);
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

// 2. OPTIMIZED ARCHIVED INCIDENTS
$query = "
    SELECT i.id, i.barangay, i.incident_type, i.severity, i.latitude, i.longitude, i.image_path, i.created_at,
           DATE_FORMAT(i.created_at, '%b %d, %Y - %h:%i %p') as date_str,
           COALESCE(log_sub.initial_log, 'No user details provided.') as initial_log,
           log_sub.arrived_at,
           log_sub.resolved_at,
           log_sub.all_logs
    FROM incidents i 
    LEFT JOIN (
        SELECT il.incident_id,
               SUBSTRING_INDEX(GROUP_CONCAT(il.log_message ORDER BY il.created_at ASC SEPARATOR '|||'), '|||', 1) as initial_log,
               MIN(CASE WHEN LOWER(il.log_message) LIKE '%scene%' THEN il.created_at END) as arrived_at,
               MAX(il.created_at) as resolved_at,
               GROUP_CONCAT(CONCAT(DATE_FORMAT(il.created_at, '%h:%i %p'), '|-|', IFNULL(u.username, 'System'), '|-|', il.log_message) 
                            ORDER BY il.created_at DESC, il.id DESC SEPARATOR '|||') as all_logs
        FROM incident_logs il
        LEFT JOIN users u ON il.user_id = u.id
        GROUP BY il.incident_id
    ) log_sub ON i.id = log_sub.incident_id
    $where_clause
    ORDER BY i.created_at DESC
";
$stmt = $conn->prepare($query);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$archived_incidents = ($res = $stmt->get_result()) ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();
$js_incidents = json_encode($archived_incidents ?: []);

// 3. OPTIMIZED DETAILED REPORT BIN
$bin_query = "
    SELECT i.id, i.barangay, i.incident_type, i.status, i.image_path, i.created_at, i.admin_remarks,
           DATE_FORMAT(i.created_at, '%b %d, %Y - %h:%i %p') as date_str,
           sr.reason as spam_reason,
           DATE_FORMAT(sr.created_at, '%b %d, %Y - %h:%i %p') as rejected_date_str,
           COALESCE(b_log.initial_log, 'No user log entered.') as initial_log,
           b_log.all_logs
    FROM incidents i 
    LEFT JOIN spam_reports sr ON i.id = sr.incident_id
    LEFT JOIN (
        SELECT il.incident_id,
               SUBSTRING_INDEX(GROUP_CONCAT(il.log_message ORDER BY il.created_at ASC SEPARATOR '|||'), '|||', 1) as initial_log,
               GROUP_CONCAT(CONCAT(DATE_FORMAT(il.created_at, '%h:%i %p'), '|-|', IFNULL(u.username, 'System'), '|-|', il.log_message) 
                            ORDER BY il.created_at DESC, il.id DESC SEPARATOR '|||') as all_logs
        FROM incident_logs il
        LEFT JOIN users u ON il.user_id = u.id
        GROUP BY il.incident_id
    ) b_log ON i.id = b_log.incident_id
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

// 5. SENTIMENT EVALUATION
function analyzeIncidentSentiment(?string $text): array {
    if (empty($text)) {
        return ['score' => 0, 'label' => 'Neutral', 'urgency' => 'Low'];
    }
    
    $clean = mb_strtolower(trim($text), 'UTF-8');
    
    $panicWords = [
        'help', 'saklolo', 'tulong', 'emergency', 'sunog', 'fire', 'trapped', 'nakulong',
        'dying', 'namamatay', 'unconscious', 'dugo', 'bleeding', 'baril', 'gun', 'patay',
        'dead', 'critical', 'severe', 'explosion', 'sabog', 'drown', 'nalulunod', 'collapse'
    ];
    $distressWords = [
        'injured', 'sugatan', 'accident', 'banggaan', 'flood', 'baha', 'hirap', 'pain',
        'sakit', 'attack', 'smoke', 'usok', 'leak', 'falling', 'danger', 'delikado',
        'broken', 'bali', 'crime', 'robbery', 'holdap', 'lost', 'nawawala'
    ];
    $calmWords = [
        'clear', 'safe', 'resolved', 'okay', 'stable', 'minor', 'controlled', 'ayos'
    ];

    $panicHits = 0;
    foreach ($panicWords as $w) { if (str_contains($clean, $w)) $panicHits++; }

    $distressHits = 0;
    foreach ($distressWords as $w) { if (str_contains($clean, $w)) $distressHits++; }

    $calmHits = 0;
    foreach ($calmWords as $w) { if (str_contains($clean, $w)) $calmHits++; }

    if ($panicHits >= 1) return ['score' => -0.85, 'label' => 'Panic / Severe Distress', 'urgency' => 'Critical'];
    if ($distressHits >= 1) return ['score' => -0.45, 'label' => 'Distress / Heightened Anxiety', 'urgency' => 'Elevated'];
    if ($calmHits >= 1) return ['score' => 0.40, 'label' => 'Calm / Controlled', 'urgency' => 'Low'];

    return ['score' => 0.0, 'label' => 'Neutral / Informative', 'urgency' => 'Normal'];
}

$sentiment_counts = [
    'Panic / Severe Distress'       => 0,
    'Distress / Heightened Anxiety' => 0,
    'Neutral / Informative'         => 0,
    'Calm / Controlled'             => 0
];

$all_evaluated_reports = array_merge($archived_incidents ?? [], $bin_incidents ?? []);
if (empty($all_evaluated_reports)) {
    $fallback_logs = $conn->query("SELECT log_message FROM incident_logs ORDER BY id DESC LIMIT 50");
    if ($fallback_logs) {
        while ($l = $fallback_logs->fetch_assoc()) {
            $all_evaluated_reports[] = ['initial_log' => $l['log_message']];
        }
    }
}

foreach ($all_evaluated_reports as $item) {
    $text = $item['initial_log'] ?? $item['user_logs'] ?? '';
    if (!empty($text)) {
        $s_eval = analyzeIncidentSentiment($text);
        if (isset($sentiment_counts[$s_eval['label']])) {
            $sentiment_counts[$s_eval['label']]++;
        }
    }
}

$has_sentiment_data = array_sum($sentiment_counts) > 0;

foreach ($archived_incidents as &$inc_item) {
    $s_eval = analyzeIncidentSentiment($inc_item['initial_log'] ?? '');
    $inc_item['sentiment'] = $s_eval;
}
unset($inc_item);

// 6. CHARTS & BROADCASTS
$broadcast_history = ($b_res = $conn->query("SELECT *, DATE_FORMAT(created_at, '%M %d, %Y - %h:%i %p') as date_str FROM broadcasts ORDER BY created_at DESC")) ? $b_res->fetch_all(MYSQLI_ASSOC) : [];
$types_res = $conn->query("SELECT DISTINCT incident_type FROM incidents WHERE status = 'archived'");
$unique_types = [];
while ($t = $types_res->fetch_assoc()) { $unique_types[] = $t['incident_type']; }

$chart_type_res = $conn->query("
    SELECT incident_type, COUNT(*) as count 
    FROM incidents 
    WHERE status NOT IN ('rejected', 'spam', 'out_of_range') {$chart_query_cfg['clause']} 
    GROUP BY incident_type 
    ORDER BY count DESC
");
$type_labels = []; $type_data = []; $type_colors = [];
$palette = ['#0284c7', '#dc2626', '#d97706', '#16a34a', '#7c3aed', '#ca8a04', '#0d9488', '#2563eb', '#9333ea', '#e11d48'];
$color_idx = 0;
while ($row = $chart_type_res->fetch_assoc()) {
    $type_labels[] = strtoupper($row['incident_type']);
    $type_data[] = (int)$row['count'];
    $type_colors[] = $palette[$color_idx % count($palette)];
    $color_idx++;
}

$dates_res = $conn->query("SELECT created_at FROM incidents WHERE status NOT IN ('rejected', 'spam', 'out_of_range')");
$seasonality_dates = [];
while ($row = $dates_res->fetch_assoc()) { $seasonality_dates[] = $row['created_at']; }

$evac_res = $conn->query("
    SELECT name, capacity, COALESCE(current_occupants, 0) as current_occupants 
    FROM evacuation_centers 
    ORDER BY capacity DESC 
    LIMIT 10
");
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
    <title>City Analytics & Early Warning | CDRRMO Command</title>
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
        <!-- COMMAND HEADER -->
        <header class="analytics-header">
            <div>
                <div class="header-badge"><i class='bx bx-pulse'></i> Real-Time Telemetry & Reports</div>
                <h1 class="header-title">City Global Analytics</h1>
                <p class="header-subtitle">Dasmariñas City Disaster Risk Reduction & Management Command Dashboard</p>
            </div>
            <div class="header-actions">
                <button type="button" onclick="openReportModal()" class="btn-action btn-danger-action">
                    <i class='bx bxs-file-pdf'></i> Generate Report
                </button>
                <button type="button" onclick="backupAllReports()" class="btn-action btn-secondary-action">
                    <i class='bx bx-archive-in'></i> Cold Backup
                </button>
            </div>
        </header>

        <!-- 1. AI PREDICTIVE RISK & EARLY WARNING -->
        <section class="command-panel ai-predictive-deck">
            <div class="panel-header">
                <div class="panel-title-group">
                    <div class="panel-icon-badge ai-icon-badge">
                        <i class='bx bx-brain'></i>
                    </div>
                    <div>
                        <h2>AI Predictive Risk & Early Warning Deck</h2>
                        <p class="panel-subtitle">Proactive monthly incident forecasting powered by Gemini analyzing historical clusters & seasonal recurrences</p>
                    </div>
                </div>
                <div class="panel-controls">
                    <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); padding: 8px 16px; border-radius: 10px;">
                        <i class='bx bxs-shield-check' style="color: #10b981; font-size: 1.2rem;"></i>
                        <span style="font-size: 0.85rem; font-weight: 700; color: #10b981; text-transform: uppercase; letter-spacing: 0.04em;">
                            Active Cycle: <span style="color: var(--text-primary, #0f172a);"><?= htmlspecialchars($current_active_month) ?></span>
                        </span>
                        <span style="background: #10b981; color: #fff; font-size: 0.65rem; font-weight: 800; padding: 2px 7px; border-radius: 6px;">AUTO</span>
                    </div>
                </div>
            </div>

            <!-- Executive Summary Alert Banner -->
            <div id="aiSummaryBanner" class="ai-summary-card" style="display: none;"></div>

            <div class="table-scroll-wrapper ai-table-scroll">
                <table class="data-table ai-table" id="aiForecastTable">
                    <thead>
                        <tr>
                            <th style="width: 18%;">Barangay Jurisdiction</th>
                            <th style="width: 25%;">Anticipated Hazard</th>
                            <th style="width: 12%;">Risk Assessment</th>
                            <th style="width: 17%;">Historical Confidence</th>
                            <th style="width: 28%;">DRRM Recommended Protocols</th>
                        </tr>
                    </thead>
                    <tbody id="aiForecastTableBody">
                        <tr>
                            <td colspan="5" class="table-empty-row text-primary font-weight-bold">
                                <i class='bx bx-loader-alt bx-spin' style="font-size: 1.6rem; display: block; margin-bottom: 8px;"></i>
                                Synthesizing historical patterns & spatial clusters with Gemini AI...
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- 2. MAIN SPLIT ANALYTICS GRID -->
        <div class="dashboard-grid">
            <!-- LEFT COLUMN: Historical Operations & Audit -->
            <div class="main-col">
                <!-- INCIDENT ARCHIVE VAULT -->
                <div class="command-panel">
                    <div class="panel-header">
                        <div class="panel-title-group">
                            <div class="panel-icon-badge">
                                <i class='bx bxs-archive'></i>
                            </div>
                            <div>
                                <h2>Incident Archive Vault</h2>
                                <p class="panel-subtitle">Audited record of resolved city emergencies and response timelines</p>
                            </div>
                        </div>
                        <div class="panel-controls">
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
                                    <th style="width: 25%;">Location & Severity</th>
                                    <th style="width: 25%;">Response Timeline</th>
                                    <th style="width: 15%; text-align: center;" class="exclude-export">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($archived_incidents)): ?>
                                    <tr><td colspan="4" class="table-empty-row">No records found in the archive vault.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($archived_incidents as $inc): 
                                        $badge_class = strtolower($inc['severity']) === 'critical' ? 'critical' : (strtolower($inc['severity']) === 'minor' ? 'info' : 'major');
                                        $logs_js = htmlspecialchars($inc['all_logs'] ?? 'No logs recorded.', ENT_QUOTES, 'UTF-8');
                                        $created = strtotime($inc['created_at']);
                                        $arrived = $inc['arrived_at'] ? strtotime($inc['arrived_at']) : null;
                                        $resolved = $inc['resolved_at'] ? strtotime($inc['resolved_at']) : null;

                                        // Convert minutes to hours and minutes if total >= 60 mins
                                        if ($resolved && $created && $resolved >= $created) {
                                            $total_mins = (int)floor(($resolved - $created) / 60);
                                            if ($total_mins >= 60) {
                                                $hrs = (int)floor($total_mins / 60);
                                                $mins_rem = $total_mins % 60;
                                                $duration_str = $mins_rem > 0 ? "{$hrs}h {$mins_rem}m" : "{$hrs}h";
                                            } else {
                                                $duration_str = "{$total_mins}m";
                                            }
                                        } else {
                                            $duration_str = "N/A";
                                        }

                                        $vaultImg = getCloudinaryUrl($inc['image_path'] ?? '');
                                    ?>
                                    <tr class="clickable-row" onclick="openMobileModal(this, 'archive')">
                                        <td>
                                            <div class="table-entity-title"><?= htmlspecialchars($inc['incident_type']) ?></div>
                                            <div class="table-entity-time"><i class='bx bx-time-five'></i> <?= $inc['date_str'] ?></div>
                                            <div class="user-log-box">"<?= htmlspecialchars($inc['initial_log'] ?? 'No user details provided.') ?>"</div>
                                        </td>
                                        <td>
                                            <span class="location-label"><?= htmlspecialchars($inc['barangay']) ?></span>
                                            <span class="badge <?= $badge_class ?>"><?= strtoupper($inc['severity']) ?></span>
                                        </td>
                                        <td>
                                            <div class="timeline-text">
                                                <div class="timeline-row"><strong>Reported:</strong> <span><?= date('h:i A', $created) ?></span></div>
                                                <div class="timeline-row"><strong class="text-arrival">Arrived:</strong> <span><?= $arrived ? date('h:i A', $arrived) : 'Pending' ?></span></div>
                                                <div class="timeline-row"><strong class="text-resolution">Resolved:</strong> <span><?= $resolved ? date('h:i A', $resolved) : 'Pending' ?></span></div>
                                                <div class="duration-text">
                                                    <span>Response Duration:</span> <span><?= $duration_str ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="text-align: center; vertical-align: middle;" class="exclude-export">
                                            <div class="btn-action-group">
                                                <?php if (!empty($vaultImg)): ?>
                                                    <button class="btn-table-icon bg-green" onclick="event.stopPropagation(); viewPhoto('<?= htmlspecialchars($vaultImg, ENT_QUOTES, 'UTF-8') ?>')" title="View Evidence"><i class='bx bx-image'></i></button>
                                                <?php endif; ?>
                                                <button class="btn-table-icon bg-blue" onclick="event.stopPropagation(); viewLogs('<?= $logs_js ?>', '<?= addslashes($inc['incident_type']) ?>')" title="View Timeline"><i class='bx bx-list-ul'></i></button>
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
                <div class="command-panel">
                    <div class="panel-header">
                        <div class="panel-title-group">
                            <div class="panel-icon-badge danger-icon-badge">
                                <i class='bx bxs-trash-alt'></i>
                            </div>
                            <div>
                                <h2>Report Bin & Rejection Audit</h2>
                                <p class="panel-subtitle">Audited breakdown of filtered false alarms, pranks, and incomplete citizen submissions</p>
                            </div>
                        </div>
                        <span class="badge badge-dark"><?= $total_rejected ?> Rejections Filtered</span>
                    </div>

                    <!-- SUMMARY STATS TILES -->
                    <div class="stats-metric-grid">
                        <?php foreach ($reject_categories_count as $cat_name => $count): 
                            $accent_border = match($cat_name) {
                                'False Alarm'            => 'border-red',
                                'Prank / Spam'           => 'border-purple',
                                'Incomplete Information' => 'border-cyan',
                                default                  => 'border-gray'
                            };
                        ?>
                            <div class="metric-card <?= $accent_border ?>">
                                <div class="metric-label"><?= $cat_name ?></div>
                                <div class="metric-val"><?= number_format($count) ?></div>
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
                                    <th style="width: 38%;">Rejection Audit & Reason</th>
                                    <th style="width: 14%; text-align: center;" class="exclude-export">Audit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bin_incidents)): ?>
                                    <tr><td colspan="4" class="table-empty-row">Report bin clean. No recorded rejections.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($bin_incidents as $bin): 
                                        $logs_js = htmlspecialchars($bin['all_logs'] ?? 'No logs recorded.', ENT_QUOTES, 'UTF-8');
                                        $binImg = getCloudinaryUrl($bin['image_path'] ?? '');
                                    ?>
                                    <tr class="clickable-row" onclick="openMobileModal(this, 'bin')">
                                        <td>
                                            <div class="table-entity-title text-muted-dark"><?= htmlspecialchars($bin['incident_type']) ?></div>
                                            <div class="table-entity-time"><?= $bin['date_str'] ?></div>
                                            <div class="user-log-box">
                                                <strong>Citizen Log:</strong> "<?= htmlspecialchars($bin['initial_log'] ?? 'No log entered.') ?>"
                                            </div>
                                        </td>
                                        <td>
                                            <span class="location-label"><?= htmlspecialchars($bin['barangay']) ?></span>
                                            <span class="badge badge-dark">REJECTED</span>
                                        </td>
                                        <td>
                                            <div class="rejection-meta">
                                                <span class="badge badge-tag"><?= strtoupper($bin['parsed_category']) ?></span>
                                                <small><i class='bx bx-user-check'></i> <?= htmlspecialchars($bin['parsed_officer']) ?></small>
                                            </div>
                                            <div class="user-log-box log-danger">
                                                <strong>Officer Note:</strong> <?= htmlspecialchars($bin['parsed_notes']) ?>
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

                <!-- DISASTER SEASONALITY -->
                <div class="command-panel">
                    <div class="panel-header">
                        <div class="panel-title-group">
                            <div class="panel-icon-badge">
                                <i class='bx bx-line-chart'></i>
                            </div>
                            <div>
                                <h2>Disaster Seasonality & Recurrence Timeline</h2>
                                <p class="panel-subtitle">Temporal frequency analysis across reporting periods</p>
                            </div>
                        </div>
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

                <!-- EVAC CENTER CAPACITY -->
                <div class="command-panel">
                    <div class="panel-header">
                        <div class="panel-title-group">
                            <div class="panel-icon-badge">
                                <i class='bx bxs-home-heart'></i>
                            </div>
                            <div>
                                <h2>Evacuation Center Occupancy & Headroom</h2>
                                <p class="panel-subtitle">Real-time capacity tracking across city shelter assets</p>
                            </div>
                        </div>
                    </div>
                    <div class="chart-wrapper">
                        <?php if (empty($evac_labels)): ?>
                            <div class="empty-overlay">No evacuation centers currently active.</div>
                        <?php endif; ?>
                        <canvas id="evacOverflowChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: Spatial Hotspots, Breakdown, Sentiment & Broadcasts -->
            <div class="side-col">
                <!-- SPATIAL HOTSPOTS HEATMAP -->
                <div class="command-panel">
                    <div class="panel-header">
                        <div class="panel-title-group">
                            <div class="panel-icon-badge danger-icon-badge">
                                <i class='bx bxs-hot'></i>
                            </div>
                            <div>
                                <h2>Spatial Hotspots</h2>
                                <p class="panel-subtitle">Kernel density geospatial map</p>
                            </div>
                        </div>
                    </div>
                    <div id="heatmap"></div>
                </div>

                <!-- INCIDENT BREAKDOWN -->
                <div class="command-panel">
                    <div class="panel-header">
                        <div class="panel-title-group">
                            <div class="panel-icon-badge">
                                <i class='bx bx-bar-chart-alt-2'></i>
                            </div>
                            <div>
                                <h2>Incident Type Distribution</h2>
                                <p class="panel-subtitle">Full rank of all historical non-rejected emergencies</p>
                            </div>
                        </div>
                        <select id="timeFilter" class="filter-select" onchange="applyFilters()">
                            <option value="today" <?= ($time_filter === 'today') ? 'selected' : '' ?>>Today</option>
                            <option value="week" <?= ($time_filter === 'week') ? 'selected' : '' ?>>1 Week</option>
                            <option value="month" <?= ($time_filter === 'month') ? 'selected' : '' ?>>1 Month</option>
                            <option value="year" <?= ($time_filter === 'year') ? 'selected' : '' ?>>1 Year</option>
                            <option value="all" <?= ($time_filter === 'all') ? 'selected' : '' ?>>All Time</option>
                        </select>
                    </div>
                    <div class="breakdown-container">
                        <?php if (empty($type_data)): ?>
                            <div class="empty-overlay">No incident data available.</div>
                        <?php else: ?>
                            <div class="breakdown-chart-scroll">
                                <canvas id="typePieChart"></canvas>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- CITIZEN SENTIMENT ANALYSIS -->
                <div class="command-panel">
                    <div class="panel-header">
                        <div class="panel-title-group">
                            <div class="panel-icon-badge purple-icon-badge">
                                <i class='bx bx-pulse'></i>
                            </div>
                            <div>
                                <h2>Citizen Sentiment Audit</h2>
                                <p class="panel-subtitle">Emotional distress & urgency detected in citizen submissions</p>
                            </div>
                        </div>
                    </div>
                    <div class="chart-wrapper sentiment-chart-wrapper">
                        <?php if (!$has_sentiment_data): ?>
                            <div class="empty-overlay">No citizen descriptions recorded.</div>
                        <?php endif; ?>
                        <canvas id="sentimentPieChart"></canvas>
                    </div>
                </div>

                <!-- BROADCAST HISTORY -->
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
                    <div class="table-scroll-wrapper" style="max-height: 280px;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Title & Date</th>
                                    <th>Severity</th>
                                    <th style="text-align: center;">Control</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($broadcast_history)): ?>
                                    <tr><td colspan="3" class="table-empty-row">No broadcast history logged.</td></tr>
                                <?php endif; ?>
                                <?php foreach ($broadcast_history as $b): ?>
                                <tr class="clickable-row" onclick="openMobileModal(this, 'broadcast')">
                                    <td>
                                        <b class="broadcast-title"><?= htmlspecialchars($b['title']) ?></b>
                                        <small class="table-entity-time"><?= $b['date_str'] ?></small>
                                    </td>
                                    <td><span class="badge <?= $b['severity'] ?>"><?= strtoupper($b['severity']) ?></span></td>
                                    <td style="text-align: center;">
                                        <?php if ($b['is_active']): ?>
                                            <button class="btn-action btn-stop" onclick="event.stopPropagation(); stopBroadcast(<?= $b['id'] ?>)">
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
        </div>

        <!-- RESTORED: CUSTOM REPORT BUILDER MODAL -->
        <div id="customReportModal" class="modal" style="z-index: 10007;">
            <div class="modal-content modal-report-generator">
                <div class="modal-header-top">
                    <div>
                        <h3 class="modal-title-header"><i class='bx bxs-report'></i> Custom Report Generator</h3>
                        <p class="modal-subtitle-header">Configure reporting timeframe, jurisdiction, and export format.</p>
                    </div>
                    <button type="button" class="modal-close-btn" onclick="closeModal('customReportModal')" aria-label="Close modal">
                        <i class='bx bx-x'></i>
                    </button>
                </div>

                <div class="modal-body">
                    <label class="form-section-label">Export Format</label>
                    <div class="format-choice-grid">
                        <label class="format-choice-card">
                            <input type="radio" name="rep_format" value="pdf" checked>
                            <span class="format-card-title"><i class='bx bxs-file-pdf text-red'></i> PDF Document</span>
                        </label>
                        <label class="format-choice-card">
                            <input type="radio" name="rep_format" value="csv">
                            <span class="format-card-title"><i class='bx bx-spreadsheet text-green'></i> CSV Spreadsheet</span>
                        </label>
                    </div>

                    <label class="form-section-label">Timeframe Interval</label>
                    <select id="rep_period" class="filter-select w-100 mb-3" onchange="handlePeriodChange()">
                        <option value="day">Single Day</option>
                        <option value="weekly">Weekly Range (Up to 7 Days)</option>
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly</option>
                        <option value="yearly">Yearly</option>
                    </select>

                    <div id="period_inputs_container" class="period-input-box mb-3">
                        <div id="box_day">
                            <label class="input-sub-label">Select Date</label>
                            <input type="date" id="rep_day" class="filter-select w-100">
                        </div>

                        <div id="box_weekly" style="display: none;">
                            <div class="input-row-flex">
                                <div>
                                    <label class="input-sub-label">Start Date (From)</label>
                                    <input type="date" id="rep_week_start" class="filter-select w-100" onchange="validateWeekRange()">
                                </div>
                                <div>
                                    <label class="input-sub-label">End Date (To)</label>
                                    <input type="date" id="rep_week_end" class="filter-select w-100" onchange="validateWeekRange()">
                                </div>
                            </div>
                            <small id="week_validation_msg" class="validation-msg"></small>
                        </div>

                        <div id="box_monthly" style="display: none;">
                            <label class="input-sub-label">Select Month</label>
                            <input type="month" id="rep_month" class="filter-select w-100">
                        </div>

                        <div id="box_quarterly" style="display: none;">
                            <div class="input-row-flex">
                                <div>
                                    <label class="input-sub-label">Year</label>
                                    <select id="rep_quarter_year" class="filter-select w-100" onchange="updateQuarterOptions()"></select>
                                </div>
                                <div>
                                    <label class="input-sub-label">Quarter</label>
                                    <select id="rep_quarter_q" class="filter-select w-100"></select>
                                </div>
                            </div>
                        </div>

                        <div id="box_yearly" style="display: none;">
                            <label class="input-sub-label">Select Year</label>
                            <select id="rep_year" class="filter-select w-100"></select>
                        </div>
                    </div>

                    <label class="form-section-label">Incident / Accident Type</label>
                    <select id="rep_incident_type" class="filter-select w-100 mb-3">
                        <option value="all">All Incident & Accident Types</option>
                        <?php foreach($unique_types as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label class="form-section-label">Include Sections</label>
                    <div class="checkbox-group mb-4">
                        <label class="checkbox-label">
                            <input type="checkbox" id="inc_vault" checked> Incident Archive Vault (Resolved Emergencies)
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="inc_bin" checked> Report Bin (False Alarms & Rejections Audit)
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="inc_group_type" checked> Categorize & Filter by Incident Type (Separate Tables)
                        </label>
                    </div>

                    <button type="button" class="btn-action btn-primary-action w-100 p-3" onclick="processReportGeneration()">
                        <i class='bx bx-download'></i> Generate & Download
                    </button>
                </div>
            </div>
        </div>
    </main>

    <!-- FIXED: TIMELINE LOG MODAL -->
    <div id="viewLogsModal" class="modal" style="z-index: 10006;">
        <div class="modal-content modal-timeline-modal">
            <div class="modal-inline-header">
                <h3 id="logIncidentTitle">Timeline</h3>
                <button type="button" class="modal-close-btn" onclick="closeModal('viewLogsModal')" aria-label="Close timeline">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <div id="logContainer" class="log-scroll-pane"></div>
        </div>
    </div>

    <!-- FIXED: EVIDENCE PHOTO MODAL -->
    <div id="viewPhotoModal" class="modal modal-photo-viewer" style="z-index: 10006;">
        <button type="button" class="photo-close-btn" onclick="closeModal('viewPhotoModal')" aria-label="Close evidence photo">
            <i class='bx bx-x'></i>
        </button>
        <div class="modal-content modal-photo-content">
            <img id="evidencePhotoViewer" src="" alt="Evidence photo viewer">
        </div>
    </div>

    <!-- MOBILE AUDIT MODAL -->
    <div id="mobileAnalyticsModal" class="modal" style="z-index: 10005;">
        <div class="modal-content modal-mobile-drawer">
            <div class="close-modal mobile-close-circle" onclick="closeModal('mobileAnalyticsModal')"><i class='bx bx-x'></i></div>
            <h3 id="m-analytics-title" class="mobile-drawer-title"></h3>
            <div id="m-analytics-body" class="mobile-drawer-body"></div>
        </div>
    </div>

<script nonce="<?= CSP_NONCE ?>">
    window.sentimentData    = <?= json_encode(array_values($sentiment_counts)) ?>;
    window.sentimentLabels  = <?= json_encode(array_keys($sentiment_counts)) ?>;
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
<?php
require_once '../php/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) { 
    $auth = new Auth($conn); 
}

// STRICT SUPERADMIN CHECK
if (!$auth->isSuperAdmin()) {
    header("Location: ../php/login.php");
    exit();
}

session_write_close();

// =========================================================================================
// AUTO-REPAIR: SPAM & REJECTED INCIDENTS RELATIONAL FIX
// =========================================================================================
$conn->query("ALTER TABLE incidents MODIFY COLUMN status ENUM('active','dispatched','on-scene','resolved','archived','rejected','spam','out_of_range') DEFAULT 'active'");

$conn->query("CREATE TABLE IF NOT EXISTS spam_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    incident_id INT NOT NULL,
    reason TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (incident_id) REFERENCES incidents(id) ON DELETE CASCADE
)");

$conn->query("INSERT IGNORE INTO spam_reports (incident_id, reason) 
              SELECT id, admin_remarks FROM incidents 
              WHERE status IN ('rejected', 'spam', 'out_of_range') 
              AND id NOT IN (SELECT incident_id FROM spam_reports)");

// 🚀 AUTO-DELETE REJECTED/SPAM REPORTS OLDER THAN 3 DAYS
$conn->query("DELETE FROM incidents WHERE status IN ('rejected', 'spam', 'out_of_range') AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)");
// =========================================================================================

// 1. GET THE FILTER VALUES
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';
$time_filter = isset($_GET['time']) ? $_GET['time'] : 'all'; 
$vault_time_filter = isset($_GET['vault_time']) ? $_GET['vault_time'] : 'all';

// SECURED: Dynamic parameter binding architecture
$where_clause = "WHERE i.status = 'archived'";
$params = [];
$types = "";

if ($type_filter !== 'all') {
    $where_clause .= " AND i.incident_type LIKE ?";
    $types .= "s";
    $params[] = "%" . $type_filter . "%";
}

if ($vault_time_filter === 'today') { $where_clause .= " AND DATE(i.created_at) = CURDATE() "; } 
elseif ($vault_time_filter === 'week') { $where_clause .= " AND i.created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK) "; } 
elseif ($vault_time_filter === 'month') { $where_clause .= " AND i.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) "; } 
elseif ($vault_time_filter === 'year') { $where_clause .= " AND i.created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR) "; }

$chart_time_clause = "";
if ($time_filter === 'today') { $chart_time_clause = " AND DATE(created_at) = CURDATE() "; } 
elseif ($time_filter === 'week') { $chart_time_clause = " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK) "; } 
elseif ($time_filter === 'month') { $chart_time_clause = " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH) "; } 
elseif ($time_filter === 'year') { $chart_time_clause = " AND created_at >= DATE_SUB(NOW(), INTERVAL 1 YEAR) "; }

// 2. SECURED FETCH: ARCHIVED INCIDENTS
$query = "
    SELECT i.id, i.barangay, i.incident_type, i.severity, i.latitude, i.longitude, i.image_path, i.created_at,
           DATE_FORMAT(i.created_at, '%b %d, %Y - %h:%i %p') as date_str,
           (SELECT log_message FROM incident_logs WHERE incident_id = i.id ORDER BY created_at ASC LIMIT 1) as initial_log,
           (SELECT MIN(created_at) FROM incident_logs WHERE incident_id = i.id AND LOWER(log_message) LIKE '%scene%') as arrived_at,
           (SELECT MAX(created_at) FROM incident_logs WHERE incident_id = i.id) as resolved_at,
           (SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(il.created_at, '%h:%i %p'), '|-|', IFNULL(u.username, 'System'), '|-|', il.log_message) SEPARATOR '|||') 
            FROM incident_logs il LEFT JOIN users u ON il.user_id = u.id WHERE il.incident_id = i.id ORDER BY il.created_at DESC) as all_logs
    FROM incidents i 
    $where_clause
    ORDER BY i.created_at DESC
";
$stmt = $conn->prepare($query);
if (!empty($params)) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$result = $stmt->get_result();
$archived_incidents = ($result && $result->num_rows > 0) ? $result->fetch_all(MYSQLI_ASSOC) : [];
$stmt->close();
$js_incidents = json_encode($archived_incidents ?: []);

// 3. SECURED FETCH: REPORT BIN INCIDENTS
$bin_query = "
    SELECT i.id, i.barangay, i.incident_type, i.status, i.image_path, i.created_at, i.admin_remarks,
           DATE_FORMAT(i.created_at, '%b %d, %Y - %h:%i %p') as date_str,
           sr.reason as spam_reason,
           (SELECT GROUP_CONCAT(CONCAT(DATE_FORMAT(il.created_at, '%h:%i %p'), '|-|', IFNULL(u.username, 'System'), '|-|', il.log_message) SEPARATOR '|||') 
            FROM incident_logs il LEFT JOIN users u ON il.user_id = u.id WHERE il.incident_id = i.id ORDER BY il.created_at DESC) as all_logs
    FROM incidents i 
    LEFT JOIN spam_reports sr ON i.id = sr.incident_id
    WHERE i.status IN ('rejected', 'spam', 'out_of_range')
    ORDER BY i.created_at DESC
";
$stmt_bin = $conn->prepare($bin_query);
$stmt_bin->execute();
$bin_result = $stmt_bin->get_result();
$bin_incidents = ($bin_result && $bin_result->num_rows > 0) ? $bin_result->fetch_all(MYSQLI_ASSOC) : [];
$stmt_bin->close();
$js_bin_incidents = json_encode($bin_incidents ?: []);

// 4. SECURED FETCH: BROADCAST HISTORY
$broadcast_query = "SELECT *, DATE_FORMAT(created_at, '%M %d, %Y - %h:%i %p') as date_str FROM broadcasts ORDER BY created_at DESC";
$stmt_bc = $conn->prepare($broadcast_query);
$stmt_bc->execute();
$broadcast_history = ($res = $stmt_bc->get_result()) ? $res->fetch_all(MYSQLI_ASSOC) : [];
$stmt_bc->close();

// 5. SECURED FETCH: UNIQUE TYPES
$stmt_t = $conn->prepare("SELECT DISTINCT incident_type FROM incidents WHERE status = 'archived'");
$stmt_t->execute();
$types_res = $stmt_t->get_result();
$unique_types = [];
while($t = $types_res->fetch_assoc()) { $unique_types[] = $t['incident_type']; }
$stmt_t->close();

// 6. SECURED FETCH: CHART DATA
$chart_type_query = "SELECT incident_type, COUNT(*) as count FROM incidents WHERE status = 'archived' $chart_time_clause GROUP BY incident_type ORDER BY count DESC";
$stmt_ct = $conn->prepare($chart_type_query);
$stmt_ct->execute();
$chart_type_res = $stmt_ct->get_result();
$type_labels = []; $type_data = []; $type_colors = [];
$palette = ['#1976d2', '#d32f2f', '#f57c00', '#388e3c', '#8e24aa', '#fbc02d', '#0097a7', '#0288d1'];
$color_idx = 0;
if ($chart_type_res) {
    while($row = $chart_type_res->fetch_assoc()) {
        $type_labels[] = strtoupper($row['incident_type']);
        $type_data[] = $row['count'];
        $type_colors[] = $palette[$color_idx % count($palette)];
        $color_idx++;
    }
}
$stmt_ct->close();

// 7. SECURED FETCH: SEASONALITY CHART
$dates_query = "SELECT created_at FROM incidents WHERE status NOT IN ('rejected', 'spam', 'out_of_range')";
$stmt_d = $conn->prepare($dates_query);
$stmt_d->execute();
$dates_res = $stmt_d->get_result();
$seasonality_dates = [];
if ($dates_res) {
    while($row = $dates_res->fetch_assoc()) {
        $seasonality_dates[] = $row['created_at'];
    }
}
$stmt_d->close();
$js_seasonality_dates = json_encode($seasonality_dates);

// 8. SECURED FETCH: EVACUATION CHART
$evac_query = "SELECT name, capacity, current_occupants FROM evacuation_centers ORDER BY current_occupants DESC LIMIT 10";
$stmt_e = $conn->prepare($evac_query);
$stmt_e->execute();
$evac_res = $stmt_e->get_result();
$evac_labels = []; $evac_capacity = []; $evac_occupants = [];
if ($evac_res) {
    while($row = $evac_res->fetch_assoc()) {
        $evac_labels[] = strlen($row['name']) > 15 ? substr($row['name'], 0, 15) . '...' : $row['name'];
        $evac_capacity[] = $row['capacity'];
        $evac_occupants[] = $row['current_occupants'];
    }
}
$stmt_e->close();
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
            <div style="display: flex; gap: 15px;">
                <button onclick="exportCSV()" class="btn-action" style="background: #388e3c;"><i class='bx bx-spreadsheet' style="font-size: 1.2rem;"></i> Export Data</button>
                <button onclick="exportPDF()" class="btn-action" style="background: #d32f2f;"><i class='bx bxs-file-pdf' style="font-size: 1.2rem;"></i> Generate Report</button>
            </div>
        </header>

        <div class="dashboard-grid">
            
            <div class="main-col">
                
                <div class="sitting-panel" style="flex: none;">
                    <div class="panel-header">
                        <h2><i class='bx bxs-archive' style="color:#607d8b;"></i> Incident Archive Vault</h2>
                        <div style="display:flex; gap: 10px; align-items: center;">
                            <select id="vaultTimeFilter" class="filter-select" onchange="applyFilters()">
                                <option value="all" <?php echo ($vault_time_filter === 'all') ? 'selected' : ''; ?>>All Time</option>
                                <option value="today" <?php echo ($vault_time_filter === 'today') ? 'selected' : ''; ?>>Today</option>
                                <option value="week" <?php echo ($vault_time_filter === 'week') ? 'selected' : ''; ?>>1 Week</option>
                                <option value="month" <?php echo ($vault_time_filter === 'month') ? 'selected' : ''; ?>>1 Month</option>
                                <option value="year" <?php echo ($vault_time_filter === 'year') ? 'selected' : ''; ?>>1 Year</option>
                            </select>
                            <select id="typeFilter" class="filter-select" onchange="applyFilters()">
                                <option value="all">All Types</option>
                                <?php foreach($unique_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo ($type_filter === $type) ? 'selected' : ''; ?>><?php echo htmlspecialchars($type); ?></option>
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
                                        $arr_str = $arrived ? date('h:i A', $arrived) : 'Unknown';
                                        $res_str = $resolved ? date('h:i A', $resolved) : 'Unknown';
                                        
                                        $duration_str = "N/A";
                                        if ($resolved && $created && $resolved >= $created) {
                                            $diff = $resolved - $created;
                                            $hours = floor($diff / 3600);
                                            $minutes = floor(($diff % 3600) / 60);
                                            $duration_str = ($hours > 0 ? "{$hours}h " : "") . "{$minutes}m";
                                        }
                                    ?>
                                    <tr class="clickable-row" onclick="openMobileModal(this, 'archive')">
                                        <td>
                                            <div style="font-weight: 900; font-size: 1.15rem; color: #1976d2; margin-bottom: 2px; padding-right: 25px;">
                                                <?php echo htmlspecialchars($inc['incident_type']); ?>
                                                <i class='bx bx-chevron-right mobile-expand-icon'></i>
                                            </div>
                                            <div style="font-size: 0.8rem; color: #888; font-weight: 600; margin-bottom: 6px;"><?php echo $inc['date_str']; ?></div>
                                            <div class="user-log-box">"<?php echo htmlspecialchars($inc['initial_log'] ?? 'No user details provided.'); ?>"</div>
                                        </td>
                                        <td>
                                            <span style="font-weight: 800; font-size: 0.95rem; color: #222; display:block; margin-bottom: 8px;"><?php echo htmlspecialchars($inc['barangay']); ?></span>
                                            <span class="badge <?php echo $badge_class; ?>"><?php echo strtoupper($inc['severity']); ?></span>
                                        </td>
                                        <td>
                                            <div class="timeline-text">
                                                <div class="timeline-row"><strong>Reported:</strong> <span><?php echo date('h:i A', $created); ?></span></div>
                                                <div class="timeline-row"><strong style="color:#388e3c;">Arrived:</strong> <span><?php echo $arr_str; ?></span></div>
                                                <div class="timeline-row"><strong style="color:#1976d2;">Resolved:</strong> <span><?php echo $res_str; ?></span></div>
                                                <div class="duration-text" style="margin-top: 8px; padding-top: 8px; border-top: 1px dashed #edf2f7; font-weight: 900; color: #d32f2f; display: flex; justify-content: space-between;">
                                                    <span>Total Time:</span> <span><?php echo $duration_str; ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td style="text-align: center; vertical-align: middle;" class="exclude-export">
                                            <div class="btn-action-group">
                                                <?php if (!empty($inc['image_path']) && $inc['image_path'] !== 'NULL'): ?>
                                                    <button class="btn-table-icon bg-green" onclick="event.stopPropagation(); viewPhoto('/<?php echo addslashes($inc['image_path']); ?>')" title="View Evidence"><i class='bx bx-image'></i></button>
                                                <?php endif; ?>
                                                <button class="btn-table-icon bg-blue" onclick="event.stopPropagation(); viewLogs('<?php echo $logs_js; ?>', '<?php echo addslashes($inc['incident_type']); ?>')" title="View Logs"><i class='bx bx-list-ul'></i></button>
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
                            <option value="year">Past Year</option>
                            <option value="month">Past Month</option>
                            <option value="week">Past Week</option>
                        </select>
                    </div>
                    <div class="chart-wrapper">
                        <?php if (empty($seasonality_dates)): ?>
                            <div class="empty-overlay" id="seasonalityOverlay">No seasonal data recorded.</div>
                        <?php endif; ?>
                        <canvas id="seasonalityLineChart"></canvas>
                    </div>
                </div>

                <div class="sitting-panel" style="flex: none;">
                    <div class="panel-header">
                        <h2><i class='bx bxs-trash-alt' style="color:#424242;"></i> Report Bin</h2>
                    </div>
                    <div class="table-scroll-wrapper">
                        <table class="data-table" id="binTable">
                            <thead>
                                <tr>
                                    <th style="width: 30%;">Incident & Logs</th>
                                    <th style="width: 25%;">Location</th>
                                    <th style="width: 30%;">Rejection Reason</th>
                                    <th style="width: 15%; text-align: center;" class="exclude-export">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($bin_incidents)): ?>
                                    <tr><td colspan="4" style="text-align: center; color: #777; padding: 40px; font-weight: 700;">Bin is empty.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($bin_incidents as $bin): 
                                        $b_status = strtoupper($bin['status']);
                                        $logs_js = htmlspecialchars($bin['all_logs'] ?? 'No logs recorded.', ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr class="clickable-row" onclick="openMobileModal(this, 'bin')">
                                        <td>
                                            <div style="font-weight: 900; font-size: 1.1rem; color: #424242; margin-bottom: 2px; padding-right: 25px;">
                                                <?php echo htmlspecialchars($bin['incident_type']); ?>
                                                <i class='bx bx-chevron-right mobile-expand-icon'></i>
                                            </div>
                                            <div style="font-size: 0.8rem; color: #888; font-weight: 600;"><?php echo $bin['date_str']; ?></div>
                                        </td>
                                        <td>
                                            <span style="font-weight: 800; font-size: 0.95rem; color: #222; display:block; margin-bottom: 8px;"><?php echo htmlspecialchars($bin['barangay']); ?></span>
                                            <span class="badge spam"><?php echo str_replace('_', ' ', $b_status); ?></span>
                                        </td>
                                        <td>
                                            <div class="user-log-box" style="border-left-color: #d32f2f;">
                                                "<?php echo htmlspecialchars($bin['spam_reason'] ?: ($bin['admin_remarks'] ?: 'No reason provided.')); ?>"
                                            </div>
                                        </td>
                                        <td style="text-align: center;" class="exclude-export">
                                            <div class="btn-action-group">
                                                <?php if (!empty($bin['image_path']) && $bin['image_path'] !== 'NULL'): ?>
                                                    <button class="btn-table-icon bg-green" onclick="event.stopPropagation(); viewPhoto('/<?php echo addslashes($bin['image_path']); ?>')" title="View Evidence"><i class='bx bx-image'></i></button>
                                                <?php endif; ?>
                                                <button class="btn-table-icon bg-dark" onclick="event.stopPropagation(); viewLogs('<?php echo $logs_js; ?>', '<?php echo addslashes($bin['incident_type']); ?>')" title="View Logs"><i class='bx bx-list-ul'></i></button>
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
                            <option value="today" <?php echo ($time_filter === 'today') ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo ($time_filter === 'week') ? 'selected' : ''; ?>>1 Week</option>
                            <option value="month" <?php echo ($time_filter === 'month') ? 'selected' : ''; ?>>1 Month</option>
                            <option value="year" <?php echo ($time_filter === 'year') ? 'selected' : ''; ?>>1 Year</option>
                            <option value="all" <?php echo ($time_filter === 'all') ? 'selected' : ''; ?>>All Time</option>
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
                                        <b style="color: #222; font-size: 0.95rem; display:block; padding-right:20px; position:relative;">
                                            <?php echo htmlspecialchars($b['title']); ?>
                                            <i class='bx bx-chevron-right mobile-expand-icon' style="top: 0;"></i>
                                        </b>
                                        <small style="color:#888; font-weight:600;"><?php echo $b['date_str']; ?></small>
                                    </td>
                                    <td><span class="badge <?php echo $b['severity']; ?>"><?php echo strtoupper($b['severity']); ?></span></td>
                                    <td style="text-align: center;">
                                        <?php if ($b['is_active']): ?>
                                            <button class="btn-action" style="background: #d32f2f; padding: 6px 12px; font-size: 0.75rem; border-radius: 8px;" onclick="event.stopPropagation(); stopBroadcast(<?php echo $b['id']; ?>)">
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

    <!-- MODAL: Mobile Analytics Details -->
    <div id="mobileAnalyticsModal" class="modal" style="z-index: 10005;">
        <div class="modal-content" style="max-width: 90%; padding: 24px;">
            <div class="close-modal" onclick="closeModal('mobileAnalyticsModal')" style="position: absolute; top: 12px; right: 12px; background: rgba(0,0,0,0.6); color: #fff; width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; z-index: 1000;"><i class='bx bx-x'></i></div>
            <h3 id="m-analytics-title" style="margin-bottom: 16px; font-weight: 900; font-size: 1.3rem; padding-right: 30px; color: var(--text-primary);"></h3>
            
            <div id="m-analytics-body" style="display: flex; flex-direction: column;">
                <!-- Dynamically Injected -->
            </div>
        </div>
    </div>                                       
<?php
// Prepare heatmap coordinates directly in PHP
$heat_coords = [];
if (!empty($archived_incidents)) {
    foreach ($archived_incidents as $inc) {
        if (!empty($inc['latitude']) && !empty($inc['longitude'])) {
            $heat_coords[] = [(float)$inc['latitude'], (float)$inc['longitude'], 0.8];
        }
    }
}
?>

<script>
    window.allIncidents      = <?= $js_incidents ?? '[]' ?>;
    window.binIncidents      = <?= $js_bin_incidents ?? '[]' ?>;
    window.allSeasonDates    = <?= $js_seasonality_dates ?? '[]' ?>;
    window.typeLabels        = <?= json_encode($type_labels ?? []) ?>;
    window.typeData          = <?= json_encode($type_data ?? []) ?>;
    window.typeColors        = <?= json_encode($type_colors ?? []) ?>;
    
    /* Evacuation & Heatmap Data */
    window.evacLabels        = <?= json_encode($evac_labels ?? []) ?>;
    window.evacOccupants     = <?= json_encode($evac_occupants ?? []) ?>;
    window.evacCapacity      = <?= json_encode($evac_capacity ?? []) ?>;
    window.heatData          = <?= json_encode($heat_coords) ?>;
</script>
<script src="../js/admin/analytics.js?v=<?= filemtime('../js/admin/analytics.js') ?>" defer></script>
</body>
</html>
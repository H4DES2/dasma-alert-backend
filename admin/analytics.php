<?php
session_start();
require_once '../php/config.php';
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) { $auth = new Auth($conn); }

// STRICT SUPERADMIN CHECK
if (!$auth->isSuperAdmin()) {
    header("Location: ../php/login.php");
    exit();
}

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

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background-color: #f0f3f7; color: #333; transition: 0.3s; }
        .main-content { margin-left: 0 !important; width: 100% !important; margin-top: 95px !important; padding: 10px 25px !important; min-height: 100vh; }

        /* MAIN HORIZONTAL GRID LAYOUT */
        .dashboard-grid { display: grid; grid-template-columns: 6.5fr 3.5fr; gap: 30px; margin-bottom: 40px; align-items: start; }
        .main-col { display: flex; flex-direction: column; gap: 30px; }
        .side-col { display: flex; flex-direction: column; gap: 30px; }

        .sitting-panel { 
            background: #ffffff; padding: 25px; border-radius: 30px; display: flex; flex-direction: column;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12), 0 10px 20px rgba(0, 0, 0, 0.04); border: 1px solid rgba(255, 255, 255, 0.8);
            flex: 1; min-width: 0; position: relative;
        }

        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .panel-header h2 { font-size: 1.4rem; font-weight: 800; color: #222; margin: 0; display: flex; align-items: center; gap: 10px; }

        .chart-wrapper { position: relative; flex-grow: 1; width: 100%; margin-top: 10px; min-height: 250px; display: flex; align-items: center; justify-content: center; }
        
        .empty-overlay {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; color: #888; background: rgba(255,255,255,0.85);
            z-index: 10; border-radius: 15px; font-size: 1.1rem;
        }

        /* MAP & TABLE WRAPPERS */
        #heatmap { flex-grow: 1; width: 100%; border-radius: 20px; z-index: 1; background: #f8f9fa; border: 1px solid #edf2f7; height: 100%; min-height: 400px; }
        .table-scroll-wrapper { flex-grow: 1; overflow-y: auto; overflow-x: auto; padding: 0; max-height: 350px; border: 1px solid #edf2f7; border-radius: 15px; }

        /* Tables (Designed with separator lines) */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th { 
            position: sticky; top: 0; background: #ffffff; z-index: 2; padding: 15px 18px; text-align: left; 
            border-bottom: 3px solid #e2e8f0; color: #888; font-weight: 800; font-size: 0.75rem; text-transform: uppercase; 
        }
        .data-table tr { transition: background 0.2s; }
        .data-table tr:hover { background-color: #f8f9fa; }
        .data-table td { padding: 20px 18px; text-align: left; border-bottom: 3px solid #edf2f7; vertical-align: top; color: #333; font-weight: 600; font-size: 0.88rem; }
        
        .badge { padding: 7px 12px; border-radius: 12px; font-size: 0.7rem; font-weight: 900; color: white; text-transform: uppercase; display: inline-flex; align-items: center; justify-content: center; }
        .badge.critical { background: #d32f2f; } .badge.major { background: #f57c00; } .badge.minor, .badge.info { background: #1976d2; } 
        .badge.spam, .badge.rejected, .badge.out_of_range { background: #424242; } .badge.ended { background: #607d8b; } .badge.active { background: #388e3c; }

        /* FLAT ACTION BUTTONS */
        .btn-action-group { display: flex; justify-content: center; gap: 8px; }
        .btn-table-icon { width: 35px; height: 35px; border-radius: 10px; border: none; color: white; font-size: 1.1rem; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .btn-table-icon:hover { transform: translateY(-2px); box-shadow: 0 8px 15px rgba(0,0,0,0.15); }
        .bg-green { background: #388e3c; } .bg-blue { background: #1976d2; } .bg-dark { background: #424242; }

        .btn-action { padding: 10px 18px; border: none; border-radius: 12px; cursor: pointer; transition: 0.3s; color: white !important; display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-size: 0.9rem; font-weight: 800; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        .btn-action:hover { transform: translateY(-2px); }
        .btn-stop { background: #212121; padding: 8px 12px; border-radius: 10px; font-size: 0.8rem; }

        .filter-select { padding: 10px 15px; border-radius: 12px; border: 1px solid #e2e8f0; background: #ffffff; font-weight: 700; color: #333; cursor: pointer; outline: none; font-size: 0.85rem; transition: 0.2s; }
        .filter-select:hover { border-color: #cbd5e0; }

        /* TIMELINE CSS */
        .user-log-box { background: #fdfdfd; padding: 12px 15px; border-radius: 10px; border-left: 4px solid #ccc; font-size: 0.8rem; color: #555; font-style: italic; margin-top: 8px; line-height: 1.4; border: 1px solid #edf2f7; }
        .timeline-text { font-size: 0.82rem; color: #444; }
        .timeline-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
        .timeline-row strong { color: #555; font-weight: 800; width: 75px; flex-shrink: 0; }
        
        /* TIMELINE LOG CARDS (LIGHT MODE) */
        .timeline-log-card { background: #f8f9fa; border: 1px solid #edf2f7; padding: 15px; border-radius: 15px; margin-bottom: 12px; border-left: 6px solid #1976d2; }
        .timeline-log-meta { color: #1976d2; font-weight: 900; }
        .timeline-log-msg { font-weight: 700; color: #333; }

        /* MODALS */
        .modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.5); align-items: center; justify-content: center; backdrop-filter: blur(8px); }
        .modal-content { background: #ffffff; padding: 40px; border-radius: 35px; width: 100%; max-width: 500px; box-shadow: 0 40px 80px rgba(0,0,0,0.3); position: relative; border: none; }

        /* DARK MODE SYNC */
        html.global-dark-mode body, html.global-dark-mode .main-content { background: #0d1117 !important; color: #f0f6fc; }
        html.global-dark-mode .sitting-panel, html.global-dark-mode .modal-content { background: #161b22 !important; box-shadow: 0 25px 60px rgba(0,0,0,0.6) !important; border: 1px solid #30363d !important; }
        html.global-dark-mode #heatmap, html.global-dark-mode .table-scroll-wrapper { background: #0d1117 !important; border-color: #30363d !important; }
        html.global-dark-mode .data-table th { background: #161b22 !important; color: #8b949e !important; border-bottom: 3px solid #30363d; }
        html.global-dark-mode .data-table td { color: #c9d1d9 !important; border-bottom-color: #30363d; }
        html.global-dark-mode .data-table tr:hover { background-color: #21262d !important; }
        html.global-dark-mode h1, html.global-dark-mode h2, html.global-dark-mode h3, html.global-dark-mode p, html.global-dark-mode span:not(.badge) { color: #ffffff !important; }
        html.global-dark-mode .filter-select { background: #21262d; color: white; border-color: #30363d; }
        html.global-dark-mode .user-log-box { background: #0d1117; border-color: #30363d; color: #8b949e; }
        html.global-dark-mode .timeline-row strong { color: #8b949e; }
        html.global-dark-mode .duration-text { color: #ff8a80 !important; border-top-color: #30363d !important; }
        html.global-dark-mode .empty-overlay { background: rgba(22, 27, 34, 0.85); color: #8b949e; }
        html.global-dark-mode .timeline-log-card { background: #0d1117 !important; border-color: #30363d !important; border-left-color: #58a6ff !important; }
        html.global-dark-mode .timeline-log-meta { color: #58a6ff !important; }
        html.global-dark-mode .timeline-log-msg { color: #f0f6fc !important; }
        html.global-dark-mode b { color: #f0f6fc !important; }

        @media (max-width: 1200px) { .dashboard-grid { grid-template-columns: 1fr; } }

        /* 🚀 MOBILE RESPONSIVE OVERRIDES */
        .mobile-expand-icon { display: none; }
        .mobile-label { display: none; }

        @media (max-width: 768px) {
            .main-content { padding: 15px 15px 30px 15px !important; margin-top: 110px !important; }
            
            /* Stack header and buttons */
            header { flex-direction: column !important; align-items: stretch !important; gap: 15px !important; }
            header > div:nth-child(2) { flex-direction: column; width: 100%; }
            .btn-action { width: 100%; justify-content: center; }

            /* Panel adjustments */
            .sitting-panel { padding: 20px 15px; }
            .panel-header { flex-direction: column; align-items: flex-start; gap: 12px; margin-bottom: 20px; }
            .panel-header > div, .panel-header select { width: 100%; }
            
            /* 🚀 Convert Tables to Boxes/Cards */
            .table-scroll-wrapper { 
                border: none !important; 
                background: transparent !important; 
                max-height: none !important; 
                overflow: visible !important; 
            }
            .data-table, .data-table tbody { 
                display: block; 
                width: 100%; 
            }
            .data-table thead { 
                display: none; /* Hide headers for a clean box layout */
            }

            .data-table tr.clickable-row { 
                display: block; 
                background: #ffffff; 
                border: 1px solid #edf2f7; 
                border-radius: 16px; 
                margin-bottom: 12px; 
                box-shadow: 0 4px 10px rgba(0,0,0,0.03);
                cursor: pointer; 
                position: relative;
                transition: transform 0.2s, background 0.2s;
            }
            .data-table tr.clickable-row:active { transform: scale(0.98); background: #f8f9fa; }
            
            html.global-dark-mode .data-table tr.clickable-row { 
                background: #0d1117 !important; 
                border-color: #30363d !important; 
            }
            html.global-dark-mode .data-table tr.clickable-row:active { 
                background: #21262d !important; 
            }

            /* Hide columns 2+ */
            .data-table td:nth-child(n+2) { display: none; }
            
            /* Style the visible cell */
            .data-table td { 
                display: block; 
                width: 100%; 
                padding: 18px 15px !important; 
                border: none !important; 
            }
            
            /* Ensure text doesn't overlap the arrow */
            .data-table td > div:first-child { 
                padding-right: 35px; 
            }

            .mobile-expand-icon { 
                display: block; position: absolute; right: 15px; top: 20px; 
                color: #888; font-size: 1.5rem; 
            }

            .mobile-label { 
                display: block; font-size: 0.65rem; font-weight: 800; 
                color: #888; margin-bottom: 6px; letter-spacing: 0.05em; text-transform: uppercase;
            }
        }

        /* Mobile Detail Box for Modal */
        .mobile-detail-box { background: #f8f9fa; padding: 16px; border-radius: 14px; border: 1px solid #edf2f7; margin-bottom: 12px; }
        html.global-dark-mode .mobile-detail-box { background: #0d1117; border-color: #30363d; }
    </style>
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

    <script>
    const allIncidents = <?php echo $js_incidents; ?>;
    const binIncidents = <?php echo $js_bin_incidents; ?>;
    const allSeasonDates = <?php echo $js_seasonality_dates; ?>;
    let lineChartInstance = null;

    // -----------------------------------------------------
    // INITIALIZE CHARTS
    // -----------------------------------------------------
    document.addEventListener("DOMContentLoaded", function() {
        
        Chart.register(ChartDataLabels);
        const isDarkMode = document.documentElement.classList.contains('global-dark-mode');
        const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
        const textColor = isDarkMode ? '#8b949e' : '#888';

        // 1. Pie Chart
        const pieCanvas = document.getElementById('typePieChart');
        if (pieCanvas) {
            new Chart(pieCanvas.getContext('2d'), {
                type: 'pie', 
                data: {
                    labels: <?php echo json_encode($type_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($type_data); ?>,
                        backgroundColor: <?php echo json_encode($type_colors); ?>,
                        borderWidth: 2, borderColor: isDarkMode ? '#161b22' : '#ffffff', hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'right', labels: { padding: 20, font: { weight: 'bold' }, color: textColor } },
                        datalabels: {
                            color: '#ffffff', font: { weight: 'bold', size: 16 },
                            formatter: (value, context) => {
                                let dataArr = context.chart.data.datasets[0].data;
                                let total = 0; dataArr.forEach(data => { total += parseInt(data); });
                                let percentage = Math.round((value / total) * 100);
                                return percentage >= 4 ? percentage + '%' : '';
                            }
                        }
                    }
                }
            });
        }

        // 2. Seasonality Line Chart Initializer
        renderSeasonality();

        // 3. Evacuation Overflow Bar Chart
        const evacCanvas = document.getElementById('evacOverflowChart');
        if (evacCanvas) {
            new Chart(evacCanvas.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($evac_labels); ?>,
                    datasets: [
                        { label: 'Current Occupants', data: <?php echo json_encode($evac_occupants); ?>, backgroundColor: '#f57c00', borderRadius: 4 },
                        { label: 'Total Capacity', data: <?php echo json_encode($evac_capacity); ?>, backgroundColor: '#1976d2', borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { datalabels: { display: false }, legend: { labels: { color: textColor } } },
                    scales: { y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, precision: 0 } }, x: { grid: { display: false }, ticks: { color: textColor } } }
                }
            });
        }

        // 4. Leaflet Heatmap
        const heatmapEl = document.getElementById('heatmap');
        if (heatmapEl) {
            let dasmaBounds = L.latLngBounds([14.2700, 120.9150], [14.3750, 121.0100]);
            const map = L.map('heatmap', { center: [14.3294, 120.9368], zoom: 14, minZoom: 13, maxBounds: dasmaBounds });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
            const heatData = [ <?php foreach ($archived_incidents as $inc): ?> [<?php echo $inc['latitude']; ?>, <?php echo $inc['longitude']; ?>, 0.8], <?php endforeach; ?> ];
            if (heatData.length > 0) { L.heatLayer(heatData, { radius: 25, blur: 15, maxZoom: 15 }).addTo(map); }
        }
    });

    // -----------------------------------------------------
    // DYNAMIC SEASONALITY FUNCTION
    // -----------------------------------------------------
    function renderSeasonality() {
        const canvasElement = document.getElementById('seasonalityLineChart');
        if (!canvasElement) return;

        const filter = document.getElementById('seasonalityFilter').value;
        const now = new Date();
        
        let filtered = allSeasonDates.filter(dateStr => {
            if (filter === 'all') return true;
            const incDate = new Date(dateStr.replace(' ', 'T'));
            const diffDays = (now - incDate) / (1000 * 60 * 60 * 24);
            if (filter === 'week') return diffDays <= 7;
            if (filter === 'month') return diffDays <= 30;
            if (filter === 'year') return diffDays <= 365;
            return true;
        });

        let timelineData = {};
        [...filtered].sort().forEach(dateStr => {
            let dateObj = new Date(dateStr.replace(' ', 'T'));
            let day = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            timelineData[day] = (timelineData[day] || 0) + 1;
        });

        const labels = Object.keys(timelineData);
        const data = Object.values(timelineData);

        const overlay = document.getElementById('seasonalityOverlay');
        if (overlay) {
            overlay.style.display = (labels.length === 0) ? 'flex' : 'none';
        }

        const ctx = canvasElement.getContext('2d');

        if (lineChartInstance) {
            lineChartInstance.data.labels = labels;
            lineChartInstance.data.datasets[0].data = data;
            lineChartInstance.update();
        } else {
            const isDarkMode = document.documentElement.classList.contains('global-dark-mode');
            const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
            const textColor = isDarkMode ? '#8b949e' : '#888';
            let gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(211, 47, 47, 0.5)'); 
            gradient.addColorStop(1, 'rgba(211, 47, 47, 0.0)'); 

            lineChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Incidents', data: data,
                        borderColor: '#d32f2f', backgroundColor: gradient,
                        borderWidth: 3, fill: true, tension: 0.4,
                        pointBackgroundColor: '#ffffff', pointBorderColor: '#d32f2f', pointRadius: 4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, datalabels: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: textColor, font: { weight: 'bold' } } },
                        y: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { stepSize: 1, color: textColor, font: { weight: 'bold' } } }
                    }
                }
            });
        }
    }

    // -----------------------------------------------------
    // MASTER EXPORT FUNCTIONS (CSV & PDF)
    // -----------------------------------------------------
    function exportCSV() {
        let csvContent = "data:text/csv;charset=utf-8,";
        
        // 1. Vault Data
        csvContent += "--- INCIDENT ARCHIVE VAULT ---\n";
        csvContent += "ID,Type,Severity,Barangay,Latitude,Longitude,Reported,Arrived,Resolved,Initial Log,Full Timeline Logs\n";
        allIncidents.forEach(inc => {
            let row = [
                inc.id, inc.incident_type, inc.severity, inc.barangay, inc.latitude, inc.longitude,
                inc.created_at, inc.arrived_at || 'N/A', inc.resolved_at || 'N/A',
                `"${(inc.initial_log || '').replace(/"/g, '""')}"`,
                `"${(inc.all_logs || '').replace(/\|\|\|/g, ' \n ').replace(/\|-\|/g, ': ').replace(/"/g, '""')}"`
            ];
            csvContent += row.join(",") + "\n";
        });

        // 2. Spam/Bin Data
        csvContent += "\n--- REPORT BIN (REJECTED/SPAM) ---\n";
        csvContent += "ID,Type,Status,Barangay,Reported Date,Reject Reason,Full Timeline Logs\n";
        binIncidents.forEach(bin => {
            let row = [
                bin.id, bin.incident_type, bin.status, bin.barangay, bin.created_at,
                `"${(bin.spam_reason || bin.admin_remarks || '').replace(/"/g, '""')}"`,
                `"${(bin.all_logs || '').replace(/\|\|\|/g, ' \n ').replace(/\|-\|/g, ': ').replace(/"/g, '""')}"`
            ];
            csvContent += row.join(",") + "\n";
        });

        let encodedUri = encodeURI(csvContent);
        let link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "Citywide_Comprehensive_Report.csv");
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function exportPDF() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF('landscape'); 
        
        doc.setFontSize(18); doc.setTextColor(25, 118, 210); doc.text("Citywide Comprehensive Report", 14, 15);
        doc.setFontSize(10); doc.setTextColor(100, 100, 100); doc.text("Generated on: " + new Date().toLocaleString(), 14, 22);

        // Vault Table
        doc.setFontSize(14); doc.setTextColor(50, 50, 50); doc.text("Incident Archive Vault", 14, 32);
        
        let vaultRows = allIncidents.map(inc => [
            inc.id, inc.incident_type, inc.severity, inc.barangay, 
            inc.created_at, inc.resolved_at || 'N/A', 
            (inc.initial_log || '').substring(0, 50) + "..."
        ]);

        doc.autoTable({
            startY: 36,
            head: [['ID', 'Type', 'Severity', 'Barangay', 'Reported', 'Resolved', 'Initial Log']],
            body: vaultRows,
            theme: 'grid',
            headStyles: { fillColor: [25, 118, 210] },
            styles: { fontSize: 8, overflow: 'linebreak' },
            columnStyles: { 6: { cellWidth: 60 } }
        });

        let finalY = doc.lastAutoTable.finalY || 36;

        // Bin Table
        doc.setFontSize(14); doc.setTextColor(50, 50, 50); doc.text("Report Bin (Rejected / Spam)", 14, finalY + 15);
        
        let binRows = binIncidents.map(bin => [
            bin.id, bin.incident_type, bin.status.toUpperCase(), bin.barangay, 
            bin.created_at, 
            (bin.spam_reason || bin.admin_remarks || 'No reason').substring(0, 60) + "..."
        ]);

        doc.autoTable({
            startY: finalY + 20,
            head: [['ID', 'Type', 'Status', 'Barangay', 'Reported Date', 'Reject Reason']],
            body: binRows,
            theme: 'grid',
            headStyles: { fillColor: [66, 66, 66] },
            styles: { fontSize: 8, overflow: 'linebreak' }
        });

        doc.save('Citywide_Comprehensive_Report.pdf');
    }

    function applyFilters() { 
        let typeVal = document.getElementById('typeFilter').value;
        let timeVal = document.getElementById('timeFilter').value;
        let vaultTimeVal = document.getElementById('vaultTimeFilter').value;
        window.location.href = `analytics.php?type=${encodeURIComponent(typeVal)}&time=${encodeURIComponent(timeVal)}&vault_time=${encodeURIComponent(vaultTimeVal)}`; 
    }

    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    function viewLogs(logsData, title) {
        document.getElementById('logIncidentTitle').innerText = title + " Timeline";
        let logContainer = document.getElementById('logContainer');
        logContainer.innerHTML = ''; 

        if (logsData === 'No logs recorded.') { 
            logContainer.innerHTML = '<p style="text-align:center; color:#777; padding:20px;">No logs found.</p>'; 
        } else {
            logsData.split('|||').forEach(line => {
                let p = line.split('|-|');
                if (p.length === 3) {
                    logContainer.innerHTML += `
                        <div class="timeline-log-card">
                            <small><b class="timeline-log-meta">${p[0]} - ${p[1]}</b></small><br>
                            <span class="timeline-log-msg">${p[2]}</span>
                        </div>`;
                }
            });
        }
        document.getElementById('viewLogsModal').style.display = 'flex';
    }

    function viewPhoto(rawPath) {
        let cleanPath = rawPath;
        if (cleanPath.startsWith('/')) { 
            cleanPath = cleanPath.substring(1); 
        }
        
        let finalUrl = '/dasma_api/' + cleanPath;
        
        document.getElementById('evidencePhotoViewer').src = finalUrl;
        document.getElementById('viewPhotoModal').style.display = 'flex';
    }

    function stopBroadcast(id) {
        if (confirm("Stop this active broadcast?")) {
            let fd = new FormData();
            fd.append('action', 'end_broadcast');
            fd.append('id', id);
            fetch('admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(data => {
                if (data.trim() === 'success') {
                    location.reload();
                } else {
                    alert("Failed to stop broadcast.");
                }
            });
        }
    }

    // -----------------------------------------------------
    // MOBILE MODAL LOGIC
    // -----------------------------------------------------
    function openMobileModal(row, type) {
        if (window.innerWidth > 768) return; 
        
        const cells = row.querySelectorAll('td');
        const titleEl = document.getElementById('m-analytics-title');
        const bodyEl = document.getElementById('m-analytics-body');
        
        let html = '';
        
        if (type === 'archive') {
            titleEl.innerHTML = "Archive Details";
            html += `<div class="mobile-detail-box"><small class="mobile-label">Incident & Logs</small>${cells[0].innerHTML}</div>`;
            html += `<div class="mobile-detail-box"><small class="mobile-label">Location & Status</small>${cells[1].innerHTML}</div>`;
            html += `<div class="mobile-detail-box"><small class="mobile-label">Response Timeline</small>${cells[2].innerHTML}</div>`;
            html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; gap:10px; width:100%;">${cells[3].innerHTML}</div></div>`;
        } 
        else if (type === 'bin') {
            titleEl.innerHTML = "Report Bin Details";
            html += `<div class="mobile-detail-box"><small class="mobile-label">Incident & Logs</small>${cells[0].innerHTML}</div>`;
            html += `<div class="mobile-detail-box"><small class="mobile-label">Location & Status</small>${cells[1].innerHTML}</div>`;
            html += `<div class="mobile-detail-box"><small class="mobile-label">Rejection Reason</small>${cells[2].innerHTML}</div>`;
            html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; gap:10px; width:100%;">${cells[3].innerHTML}</div></div>`;
        }
        else if (type === 'broadcast') {
            titleEl.innerHTML = "Broadcast Details";
            html += `<div class="mobile-detail-box"><small class="mobile-label">Alert Title & Date</small>${cells[0].innerHTML}</div>`;
            html += `<div class="mobile-detail-box"><small class="mobile-label">Severity</small><div style="margin-top: 5px;">${cells[1].innerHTML}</div></div>`;
            html += `<div style="margin-top: 5px;"><small class="mobile-label">Status & Actions</small><div class="m-actions-container" style="display:flex; flex-direction:column; gap:10px; width:100%;">${cells[2].innerHTML}</div></div>`;
        }

        bodyEl.innerHTML = html;
        
        // Stretch action buttons across the modal bottom
        let btnGroups = bodyEl.querySelectorAll('.btn-action-group, .btn-action');
        btnGroups.forEach(grp => {
            if(grp.classList.contains('btn-action-group')){
                grp.style.display = 'flex';
                grp.style.width = '100%';
                let btns = grp.querySelectorAll('button');
                btns.forEach(b => { b.style.flex = '1'; b.style.padding = '12px'; });
            } else {
                grp.style.width = '100%';
                grp.style.justifyContent = 'center';
            }
        });

        // Hide expanding arrows inside the modal copy
        bodyEl.querySelectorAll('.mobile-expand-icon').forEach(icon => icon.style.display = 'none');

        document.getElementById('mobileAnalyticsModal').style.display = 'flex';
    }
    </script>
</body>
</html>
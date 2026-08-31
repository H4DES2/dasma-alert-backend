<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../php/config.php';
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) { 
    $auth = new Auth($conn); 
}

if (isset($_POST['logout']) || isset($_GET['logout'])) {
    $auth->logout();
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_destroy();
    }
    header('Location: ../php/login.php');
    exit();
}

if (!$auth->isSuperAdmin() && !$auth->isAdmin()) {
    header('Location: ../php/login.php');
    exit();
}

$role    = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];

$barangays = [];
$b_res = $conn->query("SELECT name FROM barangays WHERE status = 'active' ORDER BY name ASC");
if ($b_res) {
    while ($row = $b_res->fetch_assoc()) {
        $barangays[] = $row['name'];
    }
}

$u_stmt = $conn->prepare("
    SELECT u.barangay, IFNULL(p.sound_alert, 0) as sound_alert 
    FROM users u 
    LEFT JOIN user_profiles p ON u.id = p.user_id 
    WHERE u.id = ?
");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$u_data      = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

$my_brgy     = $u_data['barangay'] ?? '';
$sound_saved = (int)($u_data['sound_alert'] ?? 0);

if ($role === 'admin') {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM incidents WHERE status != 'archived' AND barangay = ?");
    $stmt->bind_param("s", $my_brgy);
    $stmt->execute();
    $active_incidents = (int)$stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM response_teams rt JOIN incidents i ON rt.current_incident_id = i.id WHERE rt.status IN ('deployed','on-scene') AND i.barangay = ?");
    $stmt->bind_param("s", $my_brgy);
    $stmt->execute();
    $responders_deployed = (int)$stmt->get_result()->fetch_assoc()['count'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT SUM(current_occupants) as count FROM evacuation_centers WHERE barangay = ?");
    $stmt->bind_param("s", $my_brgy);
    $stmt->execute();
    $evacuees_count = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);
    $stmt->close();
} else {
    $active_incidents    = (int)($conn->query("SELECT COUNT(*) as count FROM incidents WHERE status != 'archived'")->fetch_assoc()['count'] ?? 0);
    $responders_deployed = (int)($conn->query("SELECT COUNT(*) as count FROM response_teams rt JOIN incidents i ON rt.current_incident_id = i.id WHERE rt.status IN ('deployed','on-scene')")->fetch_assoc()['count'] ?? 0);
    $evacuees_count      = (int)($conn->query("SELECT SUM(current_occupants) as count FROM evacuation_centers")->fetch_assoc()['count'] ?? 0);
}

$alert_level  = 'Normal Status';
$weather_temp = '--°C';

$active_broadcast = ($res = $conn->query("SELECT * FROM broadcasts WHERE is_active = 1 ORDER BY id DESC LIMIT 1")) ? $res->fetch_assoc() : null;
$show_banner      = ($role !== 'superadmin' && $active_broadcast && $active_broadcast['id'] != ($_COOKIE['dismissed_broadcast_id'] ?? 0));

$announcements = [];
try {
    $ann_res = $conn->query("SELECT * FROM announcements ORDER BY created_at DESC");
    if ($ann_res) {
        while ($row = $ann_res->fetch_assoc()) {
            $announcements[] = $row;
        }
    }
} catch (Exception $e) {}

function getReadableLocation($lat, $lng, $fallbackText) {
    if ($fallbackText !== "Locating..." && $fallbackText !== "Unknown Area" && !strpos($fallbackText, "+")) {
        return $fallbackText;
    }
    $url     = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&zoom=14";
    $options = ['http' => ['method' => "GET", 'header' => "User-Agent: DasmaAlertApp/1.0\r\n"]];
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    if ($response) {
        $data = json_decode($response, true);
        return $data['display_name'] ?? $fallbackText;
    }
    return $fallbackText;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo ucfirst($role); ?> | Command Center</title>
    <!-- Modern Typography & Icons -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../css/admin/navbar.css?v=<?= time() ?>">
    <link rel="stylesheet" href="../css/admin/dashboard.css?v=<?= time() ?>">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</head>
<body>
    <?php if ($show_banner): ?>
    <div id="broadcast-banner">
        <div style="display:flex; align-items:center; gap:16px;">
            <i class='bx bxs-megaphone bx-tada' style="font-size:1.8rem;"></i>
            <span style="font-size: 0.95rem;">[<?php echo strtoupper($active_broadcast['severity']); ?>] <?php echo htmlspecialchars($active_broadcast['title']); ?>: <?php echo htmlspecialchars($active_broadcast['message']); ?></span>
        </div>
        <button onclick="dismissBroadcast(<?php echo $active_broadcast['id']; ?>)" style="background:rgba(255,255,255,0.2); border:1.5px solid white; color:white; border-radius:var(--radius-md); cursor:pointer; padding:6px 16px; font-weight:800; font-family:var(--font-family); transition:0.2s;">DISMISS</button>
    </div>
    <script>document.body.classList.add('has-broadcast');</script>
    <?php endif; ?>

    <?php include 'navbar.php'; ?>
    <main class="main-content">

        <!-- KPI Summary Grid (Now Initialized with Skeletons) -->
        <div class="kpi-grid">
            <div class="kpi-card red">
                <div class="kpi-header-row">
                    <div class="kpi-icon-wrapper"><i class='bx bxs-error-circle'></i></div>
                    <div class="kpi-card-content">
                        <h3 id="kpi-active"><span class="skeleton skeleton-text" style="height: 36px; width: 60px; margin: 0;"></span></h3>
                        <p>Active Incidents</p>
                    </div>
                </div>
                <div class="kpi-details-container" id="kpi-active-details">
                    <div><div class="skeleton skeleton-text" style="margin: 0;"></div></div>
                    <div><div class="skeleton skeleton-text short" style="margin: 0;"></div></div>
                </div>
            </div>

            <div class="kpi-card blue">
                <div class="kpi-header-row">
                    <div class="kpi-icon-wrapper"><i class='bx bxs-ambulance'></i></div>
                    <div class="kpi-card-content">
                        <h3 id="kpi-deployed"><span class="skeleton skeleton-text" style="height: 36px; width: 60px; margin: 0;"></span></h3>
                        <p>Teams Deployed</p>
                    </div>
                </div>
                <div class="kpi-details-container" id="kpi-deployed-details">
                    <div><div class="skeleton skeleton-text" style="margin: 0;"></div></div>
                    <div><div class="skeleton skeleton-text short" style="margin: 0;"></div></div>
                </div>
            </div>

            <div class="kpi-card green">
                <div class="kpi-header-row">
                    <div class="kpi-icon-wrapper"><i class='bx bxs-group'></i></div>
                    <div class="kpi-card-content">
                        <h3 id="kpi-evacuees"><span class="skeleton skeleton-text" style="height: 36px; width: 60px; margin: 0;"></span></h3>
                        <p>Total Evacuees</p>
                    </div>
                </div>
                <div class="kpi-details-container" id="kpi-evacuees-details">
                    <div><div class="skeleton skeleton-text" style="margin: 0;"></div></div>
                    <div><div class="skeleton skeleton-text short" style="margin: 0;"></div></div>
                </div>
            </div>

            <div class="kpi-card yellow">
                <div class="kpi-header-row">
                    <div class="kpi-icon-wrapper"><i class='bx bxs-cloud'></i></div>
                    <div class="kpi-card-content">
                        <h3 id="weather-alert"><span class="skeleton skeleton-text" style="height: 28px; width: 80%; margin: 0;"></span></h3>
                        <p id="weather-temp"><span class="skeleton skeleton-text short" style="height: 14px; margin: 6px 0 0 0;"></span></p>
                    </div>
                </div>
            </div>
        </div>
        <!-- Main Dashboard Split Layout -->
        <div class="dashboard-split-layout">
            <div class="sitting-panel left-panel">
                <div class="panel-header">
                    <h2><i class='bx bx-map-alt' style="color:var(--color-info);"></i> Live Incident Map</h2>
                    <div style="display: flex; gap: 10px;">
                        <select id="map-filter-incident" class="filter-dropdown" onchange="syncDashboard()">
                            <option value="all">All Types</option>
                            <option value="Fire">Fire</option>
                            <option value="Medical">Medical</option>
                            <option value="Accident">Accident</option>
                            <option value="Rescue">Rescue</option>
                            <option value="Environmental">Environmental</option>
                            <option value="Crime">Crime</option>
                        </select>
                        <button id="evac-toggle-btn" class="filter-dropdown" onclick="toggleEvacLayer()"><i id="evac-icon" class='bx bxs-home-heart'></i> Evacs</button>
                    </div>
                </div>
                <div id="dasma-map"></div>
            </div>

            <div class="sitting-panel right-panel">
                <div class="panel-header">
                    <h2><i class='bx bx-list-ul' style="color:var(--color-critical);"></i> Incident Reports</h2>
                    <div style="display: flex; align-items: center; gap: 12px;">
                        
                        <div class="sound-toggle-wrapper">
                            <span style="display: flex; align-items: center; gap: 4px;"><i class='bx bx-volume-full'></i> Sound</span>
                            <label class="toggle-switch">
                        <input type="checkbox" id="soundToggleBtn" onchange="toggleSound()" <?php echo ($sound_saved === 1) ? 'checked' : ''; ?>>
                        <span class="toggle-slider"></span>
                        </label>
                        </div>

                        <?php if ($role === 'superadmin'): ?>
                            <select id="table-filter-brgy" class="filter-dropdown" onchange="syncDashboard()">
                                <option value="">🌍 All Barangays</option>
                                <?php foreach ($barangays as $brgy): ?><option value="<?php echo $brgy; ?>"><?php echo $brgy; ?></option><?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <span style="font-weight: 800; color: var(--color-critical); background: rgba(239, 68, 68, 0.1); padding: 8px 14px; border-radius: var(--radius-md); font-size: 0.82rem;">Sector: <?php echo $my_brgy; ?></span>
                            <input type="hidden" id="table-filter-brgy" value="<?php echo $my_brgy; ?>">
                        <?php endif; ?>
                    </div>
                </div>

                <div class="table-scroll-wrapper">
                    <table class="triage-table">
                        <thead>
                            <tr>
                                <th>Date & Time Reported</th>
                                <th>Location Details</th>
                                <th>Incident Info</th>
                                <th>Evidence</th>
                                <th>Status</th>
                                <th style="text-align: center;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="triage-table-body">
                            <!-- Skeleton Row 1 -->
                            <tr>
                                <td style="vertical-align: middle;">
                                    <div class="skeleton skeleton-text short"></div>
                                    <div class="skeleton skeleton-text"></div>
                                </td>
                                <td style="vertical-align: middle;">
                                    <div class="skeleton skeleton-text"></div>
                                    <div class="skeleton skeleton-text short"></div>
                                    <div class="skeleton skeleton-text" style="width: 60%; margin-top: 5px;"></div>
                                </td>
                                <td style="vertical-align: middle;">
                                    <div class="skeleton skeleton-text short"></div>
                                    <div class="skeleton skeleton-text long"></div>
                                </td>
                                <td style="text-align:center; vertical-align: middle;">
                                    <div class="skeleton skeleton-avatar"></div>
                                </td>
                                <td style="text-align:center; vertical-align: middle;">
                                    <div class="skeleton skeleton-badge"></div><br>
                                    <div class="skeleton skeleton-text short" style="margin: 6px auto 0;"></div>
                                </td>
                                <td style="vertical-align: middle; width: 150px; padding-right: 25px;">
                                    <div style="display:flex; flex-direction:column; gap:6px;">
                                        <div class="skeleton skeleton-button"></div>
                                        <div class="skeleton skeleton-button"></div>
                                    </div>
                                </td>
                            </tr>
                            <!-- Skeleton Row 2 -->
                            <tr>
                                <td style="vertical-align: middle;">
                                    <div class="skeleton skeleton-text short" style="width: 30%;"></div>
                                    <div class="skeleton skeleton-text" style="width: 70%;"></div>
                                </td>
                                <td style="vertical-align: middle;">
                                    <div class="skeleton skeleton-text" style="width: 90%;"></div>
                                    <div class="skeleton skeleton-text short"></div>
                                    <div class="skeleton skeleton-text" style="width: 50%; margin-top: 5px;"></div>
                                </td>
                                <td style="vertical-align: middle;">
                                    <div class="skeleton skeleton-text short" style="width: 50%;"></div>
                                    <div class="skeleton skeleton-text long" style="width: 80%;"></div>
                                </td>
                                <td style="text-align:center; vertical-align: middle;">
                                    <div class="skeleton skeleton-avatar"></div>
                                </td>
                                <td style="text-align:center; vertical-align: middle;">
                                    <div class="skeleton skeleton-badge"></div><br>
                                    <div class="skeleton skeleton-text short" style="margin: 6px auto 0;"></div>
                                </td>
                                <td style="vertical-align: middle; width: 150px; padding-right: 25px;">
                                    <div style="display:flex; flex-direction:column; gap:6px;">
                                        <div class="skeleton skeleton-button"></div>
                                        <div class="skeleton skeleton-button"></div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- App Announcements Management Panel -->
        <div class="sitting-panel" style="width: 100%; margin-bottom: 40px;">
            <div class="panel-header">
                <h2><i class='bx bxs-bell-ring' style="color:var(--color-warning);"></i> Manage App Announcements</h2>
                <button class="btn-sm" style="background:var(--color-info);" onclick="openAnnouncementModal()"><i class='bx bx-plus'></i> Create New</button>
            </div>
            
            <div class="table-scroll-wrapper" style="max-height: 400px;">
                <table class="triage-table">
                    <thead>
                        <tr>
                            <th style="width: 20%;">Date & Time</th>
                            <th style="width: 50%;">Announcement</th>
                            <th style="width: 15%; text-align:center;">Image</th>
                            <th style="width: 15%; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($announcements)): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 40px; color:var(--text-muted);">No announcements posted yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($announcements as $ann): ?>
                                <tr>
                                    <td>
                                        <b style="color: var(--text-primary);"><?php echo date('M d, Y', strtotime($ann['created_at'])); ?></b><br>
                                        <small style="color: var(--text-muted); font-weight: 700;"><?php echo date('h:i A', strtotime($ann['created_at'])); ?></small>
                                    </td>
                                    <td>
                                        <strong style="color:var(--color-info); font-size:1rem;"><?php echo htmlspecialchars($ann['title']); ?></strong><br>
                                        <span style="color:var(--text-secondary); font-size:0.88rem; line-height: 1.4; display:block; margin-top:4px;"><?php echo nl2br(htmlspecialchars($ann['message'])); ?></span>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php if(!empty($ann['image_path'])): ?>
                                            <img src="/dasma_api/<?php echo htmlspecialchars($ann['image_path']); ?>" style="height:56px; width:80px; border-radius:var(--radius-md); object-fit:cover; border: 1px solid var(--border-color);">
                                        <?php else: ?>
                                            <span style="color:var(--text-muted); font-style:italic; font-size: 0.8rem;">No Image</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <div style="display:flex; gap:8px; justify-content:center;">
                                            <button class="btn-sm" style="background:var(--color-success); padding:8px;" onclick="openAnnouncementModal(<?php echo $ann['id']; ?>, '<?php echo addslashes($ann['title']); ?>', '<?php echo addslashes(str_replace(array("\r", "\n"), array('\r', '\n'), $ann['message'])); ?>')"><i class='bx bx-edit' style="font-size: 1.1rem;"></i></button>
                                            <button class="btn-sm" style="background:var(--color-critical); padding:8px;" onclick="deleteAnnouncement(<?php echo $ann['id']; ?>)"><i class='bx bx-trash' style="font-size: 1.1rem;"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- MODAL 1: Dispatch Teams -->
        <div id="dispatchModal" class="modal">
            <div class="modal-content" style="max-width: 450px;">
                <div class="close-modal" onclick="closeModal('dispatchModal')"><i class='bx bx-x'></i></div>
                <div class="modal-header" style="margin-bottom: 20px;">
                    <h3 style="margin:0; font-weight:800; display:flex; align-items:center; gap:8px;"><i class='bx bxs-truck' style="color: var(--color-success);"></i> Dispatch Units</h3>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="dispatch_incident_id">
                    <p id="dispatch_incident_name" style="font-weight:800; font-size:1.1rem; margin-bottom:18px; color:var(--color-critical);"></p>
                    
                    <label style="display:block; margin-bottom:8px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; font-size:0.75rem; letter-spacing:0.04em;">Available Response Teams</label>
                    <div id="available_teams_list" class="team-list-container"></div>
                    <button class="btn-sm" style="background:var(--color-success); width:100%; padding:14px; font-size:0.95rem;" onclick="submitDispatch()">Deploy Selected Teams</button>
                </div>
            </div>
        </div>

        <!-- MODAL 2: Announcements -->
        <div id="announcementModal" class="modal">
            <div class="modal-content" style="max-width: 500px;">
                <div class="close-modal" onclick="closeModal('announcementModal')"><i class='bx bx-x'></i></div>
                <div class="modal-header" style="margin-bottom: 20px;">
                    <h3 id="annModalTitle" style="margin:0; font-weight:800;"><i class='bx bxs-bell-ring' style="color: var(--color-warning);"></i> Create Announcement</h3>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="ann_id">
                    
                    <label style="display:block; margin-bottom:6px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; font-size:0.75rem; letter-spacing:0.04em;">Announcement Title</label>
                    <input type="text" id="ann_title" class="filter-dropdown" style="width:100%; margin-bottom:15px;" placeholder="E.g., Relief Goods Distribution...">
                    
                    <label style="display:block; margin-bottom:6px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; font-size:0.75rem; letter-spacing:0.04em;">Message / Details</label>
                    <textarea id="ann_message" class="filter-dropdown" style="width:100%; height:120px; margin-bottom:15px; resize:none;" placeholder="Enter full details here..."></textarea>
                    
                    <label style="display:block; margin-bottom:6px; font-weight:800; color:var(--text-secondary); text-transform:uppercase; font-size:0.75rem; letter-spacing:0.04em;">Attach Image (Optional)</label>
                    <input type="file" id="ann_image" accept="image/*" style="margin-bottom: 24px; width: 100%; padding: 10px; border: 1px dashed var(--border-color); border-radius: var(--radius-md); background: var(--surface-subtle); color: var(--text-primary);">
                    
                    <button class="btn-sm" style="background:var(--color-info); width:100%; padding:14px; font-size:0.95rem;" onclick="saveAnnouncement()">Publish Announcement</button>
                </div>
            </div>
        </div>

        <!-- MODAL 3: Official Evidence -->
        <div id="evidenceModal" class="modal">
            <div class="modal-content" style="max-width: 750px; padding: 0; overflow: visible; border-radius: var(--radius-xl); border: none; box-shadow: var(--shadow-lg);">
                <div class="close-modal" onclick="closeModal('evidenceModal')"><i class='bx bx-x'></i></div>
                <div style="background: var(--surface-subtle); padding: 20px 28px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); border-radius: var(--radius-xl) var(--radius-xl) 0 0;" class="ev-header">
                    <h3 style="margin: 0; color: var(--text-primary); font-size: 1.25rem; font-weight:800; display: flex; align-items: center; gap: 10px;">
                        <i class='bx bx-photo-album' style="color: var(--color-info);"></i> Official Evidence
                    </h3>
                </div>

                <div style="padding: 28px; background: var(--surface-card); border-radius: 0 0 var(--radius-xl) var(--radius-xl);" class="ev-body">
                    <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                        <div style="flex: 1.5; min-width: 280px; display: flex; align-items: center; justify-content: center; background: #000000; border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-md);">
                            <img id="evidenceImageFull" src="" style="width: 100%; max-height: 380px; object-fit: contain;">
                        </div>
                        
                        <div style="flex: 1; min-width: 240px; display: flex; flex-direction: column; gap: 12px;">
                            <div style="background: var(--surface-subtle); padding: 14px; border-radius: var(--radius-md); border: 1px solid var(--border-color);" class="ev-box">
                                <small style="color: var(--text-muted); font-weight: 800; text-transform: uppercase; font-size: 0.68rem; letter-spacing:0.04em;">Reported By</small>
                                <div id="evReporter" class="val" style="font-weight: 800; font-size: 1.05rem; color: var(--color-info);"></div>
                            </div>
                            <div style="background: var(--surface-subtle); padding: 14px; border-radius: var(--radius-md); border: 1px solid var(--border-color);" class="ev-box">
                                <small style="color: var(--text-muted); font-weight: 800; text-transform: uppercase; font-size: 0.68rem; letter-spacing:0.04em;">Date & Time</small>
                                <div id="evDateTime" class="val" style="font-weight: 800; font-size: 0.95rem; color: var(--text-primary);"></div>
                            </div>
                            <div style="background: var(--surface-subtle); padding: 14px; border-radius: var(--radius-md); border: 1px solid var(--border-color);" class="ev-box">
                                <small style="color: var(--text-muted); font-weight: 800; text-transform: uppercase; font-size: 0.68rem; letter-spacing:0.04em;">Incident Type</small>
                                <div id="evType" class="val" style="font-weight: 800; font-size: 1.05rem; color: var(--text-primary);"></div>
                            </div>
                            <div style="background: var(--surface-subtle); padding: 14px; border-radius: var(--radius-md); border: 1px solid var(--border-color);" class="ev-box">
                                <small style="color: var(--text-muted); font-weight: 800; text-transform: uppercase; font-size: 0.68rem; letter-spacing:0.04em;">Location</small>
                                <div id="evBrgy" style="font-weight: 800; font-size: 0.95rem; color: var(--color-critical);"></div>
                            </div>
                            <div style="background: rgba(245, 158, 11, 0.12); padding: 14px; border-radius: var(--radius-md); border: 1px solid rgba(245, 158, 11, 0.3); margin-top: 4px;">
                                <small style="color: var(--color-warning); font-weight: 800; text-transform: uppercase; font-size: 0.68rem; letter-spacing:0.04em;">Reporter Logs & Details</small>
                                <div id="evLogs" style="font-weight: 600; font-size: 0.9rem; color: var(--text-primary); margin-top: 4px; font-style: italic;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- MODAL 4: Universal Alert/Confirm -->
        <div id="universalModal" class="modal">
            <div class="modal-content" style="text-align: center; width: 380px;">
                <i id="uniModalIcon" class='bx' style="font-size: 4rem; margin-bottom: 16px;"></i>
                <h3 id="uniModalTitle" style="margin-bottom: 12px; font-weight:800;"></h3>
                <p id="uniModalText" style="margin-bottom: 24px; color: var(--text-secondary); font-weight: 600; font-size: 0.9rem;"></p>
                <div style="display: flex; gap: 12px;" id="uniModalButtons"></div>
            </div>
        </div>

        <!-- MODAL 5: Mobile Incident Details -->
        <div id="mobileIncidentModal" class="modal">
            <div class="modal-content" style="max-width: 90%; padding: 24px;">
                <div class="close-modal" onclick="closeModal('mobileIncidentModal')"><i class='bx bx-x'></i></div>
                <h3 style="margin-bottom: 16px; font-weight: 800; color: var(--text-primary); border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">Incident Details</h3>
                
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <div>
                        <small style="color: var(--text-muted); font-weight: 800; font-size: 0.7rem;">TIME & LOCATION</small>
                        <div id="m-modal-time" style="display: flex; gap: 10px; align-items: center; margin-top: 4px;"></div>
                        <div id="m-modal-loc" style="margin-top: 4px;"></div>
                    </div>
                    
                    <div style="background: var(--surface-subtle); padding: 12px; border-radius: var(--radius-md);">
                        <small style="color: var(--text-muted); font-weight: 800; font-size: 0.7rem;">INCIDENT INFO</small>
                        <div id="m-modal-info" style="margin-top: 4px;"></div>
                    </div>

                    <div style="display: flex; gap: 10px; align-items: center;">
                        <div style="flex: 1;">
                            <small style="color: var(--text-muted); font-weight: 800; font-size: 0.7rem; display: block; margin-bottom: 4px; text-align: center;">EVIDENCE</small>
                            <div id="m-modal-ev" style="text-align: center;"></div>
                        </div>
                        <div style="flex: 1;">
                            <small style="color: var(--text-muted); font-weight: 800; font-size: 0.7rem; display: block; margin-bottom: 4px; text-align: center;">STATUS</small>
                            <div id="m-modal-status" style="text-align: center;"></div>
                        </div>
                    </div>

                    <div>
                        <small style="color: var(--text-muted); font-weight: 800; font-size: 0.7rem; display: block; margin-bottom: 8px; text-align: center;">ACTIONS</small>
                        <div id="m-modal-actions" style="display: flex; flex-direction: column; gap: 8px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

<?php
// Safe initial dashboard KPI query payload with error fallback
$kpi_active = 0;
$kpi_evac = 0;
$kpi_dep = 0;

try {
    $kpi_active_res = $conn->query("SELECT COUNT(*) AS total FROM incidents WHERE status NOT IN ('Resolved', 'Spam', 'False Alarm', 'archived')");
    $kpi_active = $kpi_active_res ? (int)$kpi_active_res->fetch_assoc()['total'] : 0;

    $kpi_evac_res = $conn->query("SELECT SUM(current_occupants) AS total FROM evacuation_centers WHERE status = 'Active'");
    $kpi_evac = $kpi_evac_res ? (int)$kpi_evac_res->fetch_assoc()['total'] : 0;

    $kpi_dep_res = $conn->query("SELECT COUNT(*) AS total FROM response_teams WHERE status IN ('deployed', 'on-scene')");
    $kpi_dep = $kpi_dep_res ? (int)$kpi_dep_res->fetch_assoc()['total'] : 0;
} catch (Exception $e) {
    // Keep defaults on query exception
}

$initial_payload = [
    'kpi' => [
        'active'   => $kpi_active,
        'deployed' => $kpi_dep,
        'evacuees' => $kpi_evac
    ]
];
?>

<script>
    window.soundEnabled = <?= ($sound_saved === 1) ? 'true' : 'false' ?>;
    window.initialDashboardData = <?= json_encode($initial_payload) ?>;
</script>
<script src="../js/admin/dashboard.js?v=<?= time() ?>"></script>
</body>
</html>
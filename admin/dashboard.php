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
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        :root {
            --font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --bg-main: #f8fafc;
            --surface-card: #ffffff;
            --surface-subtle: #f1f5f9;
            --border-color: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            
            --color-critical: #ef4444;
            --color-warning: #f59e0b;
            --color-success: #10b981;
            --color-info: #3b82f6;

            --shadow-sm: 0 2px 4px rgba(15, 23, 42, 0.04);
            --shadow-md: 0 10px 25px -5px rgba(15, 23, 42, 0.08), 0 8px 10px -6px rgba(15, 23, 42, 0.04);
            --shadow-lg: 0 20px 40px -15px rgba(15, 23, 42, 0.12);

            --radius-md: 12px;
            --radius-lg: 18px;
            --radius-xl: 24px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { 
            font-family: var(--font-family); 
            background-color: var(--bg-main); 
            color: var(--text-primary);
            transition: background-color 0.3s ease, color 0.3s ease;
            -webkit-font-smoothing: antialiased;
        }

        .main-content { 
            margin-left: 0 !important; 
            width: 100% !important; 
            margin-top: 100px !important; 
            padding: 15px 30px 40px 30px !important; 
            min-height: 100vh; 
        }

        /* 🚀 BROADCAST BANNER */
        #broadcast-banner {
            position: fixed; top: 90px; left: 30px; right: 30px; 
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); 
            color: white; padding: 16px 28px; z-index: 998; 
            display: flex; justify-content: space-between; align-items: center; 
            box-shadow: 0 12px 30px rgba(220, 38, 38, 0.3); 
            font-weight: 700; border-radius: var(--radius-lg); 
            animation: slideDown 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            backdrop-filter: blur(10px);
        }

        @keyframes slideDown { from { transform: translateY(-100%); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
        body.has-broadcast .main-content { margin-top: 185px !important; }

        /* 🚀 SKELETON LOADER / SHIMMER EFFECT */
        .skeleton {
            background: var(--surface-subtle);
            background-image: linear-gradient(
                90deg,
                rgba(255, 255, 255, 0) 0,
                rgba(255, 255, 255, 0.4) 20%,
                rgba(255, 255, 255, 0) 40%,
                rgba(255, 255, 255, 0) 100%
            );
            background-repeat: no-repeat;
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite linear;
            border-radius: var(--radius-md);
            display: inline-block;
        }

        html.global-dark-mode .skeleton {
            background-image: linear-gradient(
                90deg,
                rgba(255, 255, 255, 0) 0,
                rgba(255, 255, 255, 0.05) 20%,
                rgba(255, 255, 255, 0) 40%,
                rgba(255, 255, 255, 0) 100%
            );
        }

        @keyframes shimmer {
            0% { background-position: -200% 0; }
            100% { background-position: 200% 0; }
        }

        .skeleton-text { height: 14px; width: 80%; margin-bottom: 8px; }
        .skeleton-text.short { width: 40%; }
        .skeleton-text.long { width: 95%; }
        .skeleton-button { height: 34px; width: 100%; border-radius: var(--radius-md); }
        .skeleton-badge { height: 24px; width: 70px; border-radius: var(--radius-md); }
        .skeleton-avatar { height: 32px; width: 45px; border-radius: var(--radius-md); margin: 0 auto; }

        /* 🚀 KPI GRID DESIGN */
        .kpi-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
            gap: 20px; 
            margin-top: 10px; 
            margin-bottom: 30px; 
            align-items: start; 
        }
        
        .kpi-card { 
            background: var(--surface-card); 
            padding: 24px; 
            border-radius: var(--radius-lg); 
            display: flex; 
            flex-direction: column; 
            box-shadow: var(--shadow-md); 
            border: 1px solid var(--border-color); 
            position: relative; 
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            z-index: 10;
            overflow: visible;
        }
        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            background: var(--border-color);
        }
        .kpi-card.red::before { background: var(--color-critical); }
        .kpi-card.blue::before { background: var(--color-info); }
        .kpi-card.green::before { background: var(--color-success); }
        .kpi-card.yellow::before { background: var(--color-warning); }

        .kpi-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); z-index: 1001; }

        .kpi-header-row { display: flex; align-items: center; gap: 18px; width: 100%; }
        
        .kpi-icon-wrapper {
            width: 56px; height: 56px;
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
            font-size: 2rem; flex-shrink: 0;
            background: var(--surface-subtle);
        }
        .kpi-card.red .kpi-icon-wrapper { color: var(--color-critical); background: rgba(239, 68, 68, 0.1); }
        .kpi-card.blue .kpi-icon-wrapper { color: var(--color-info); background: rgba(59, 130, 246, 0.1); }
        .kpi-card.green .kpi-icon-wrapper { color: var(--color-success); background: rgba(16, 185, 129, 0.1); }
        .kpi-card.yellow .kpi-icon-wrapper { color: var(--color-warning); background: rgba(245, 158, 11, 0.1); }

        .kpi-card-content { flex: 1; min-width: 0; }
        .kpi-card h3 { 
            color: var(--text-primary); 
            font-size: 2rem; 
            font-weight: 800; 
            line-height: 1.1; 
            letter-spacing: -0.02em;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; 
        } 
        .kpi-card p { 
            color: var(--text-secondary); 
            font-weight: 700; 
            font-size: 0.78rem; 
            text-transform: uppercase; 
            letter-spacing: 0.05em;
            margin-top: 6px; 
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; 
        }
        #weather-alert { font-size: 1.25rem; font-weight: 800; } 
        
        .kpi-details-container {
            position: absolute; top: 90%; left: 0; right: 0; 
            background: var(--surface-card);
            border-radius: 0 0 var(--radius-lg) var(--radius-lg); 
            box-shadow: var(--shadow-lg);
            max-height: 0; overflow-y: auto; opacity: 0; visibility: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); padding: 0 20px;
            border: 1px solid var(--border-color); border-top: none; 
            pointer-events: none; 
        }

        .kpi-card:hover .kpi-details-container {
            max-height: 280px; opacity: 1; visibility: visible;
            top: 100%; padding: 16px 20px 20px 20px; pointer-events: auto;
        }

        .kpi-details-container div { 
            background: var(--surface-subtle); 
            padding: 10px 14px; 
            border-radius: var(--radius-md); 
            margin-bottom: 8px; 
            border: 1px solid var(--border-color); 
            font-size: 0.82rem; 
            color: var(--text-secondary); 
            font-weight: 600; 
        }

        /* 🚀 MAIN SPLIT LAYOUT */
        .dashboard-split-layout { 
            display: flex; gap: 24px; align-items: stretch; 
            height: 75vh; min-height: 720px; margin-bottom: 30px; 
        }
        .sitting-panel { 
            background: var(--surface-card); 
            border-radius: var(--radius-xl); 
            padding: 24px; 
            display: flex; flex-direction: column; 
            box-shadow: var(--shadow-md); 
            border: 1px solid var(--border-color); 
        }
        .left-panel { flex: 3.8; } 
        .right-panel { flex: 6.2; overflow: hidden; } 
        
        .panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }
        .panel-header h2 { font-weight: 800; color: var(--text-primary); font-size: 1.25rem; letter-spacing: -0.01em; display: flex; align-items: center; gap: 10px; }
        
        #dasma-map, .table-scroll-wrapper { 
            flex-grow: 1; width: 100%; border-radius: var(--radius-lg); 
            z-index: 1; background: var(--surface-subtle); 
            border: 1px solid var(--border-color); 
        }
        .table-scroll-wrapper { overflow-y: auto; overflow-x: auto; padding: 0; }
        
        /* 🚀 CONTROLS & DROPDOWNS */
        .filter-dropdown { 
            background: var(--surface-card); 
            padding: 9px 14px; 
            border-radius: var(--radius-md); 
            font-weight: 700; 
            border: 1px solid var(--border-color); 
            color: var(--text-primary); 
            box-shadow: var(--shadow-sm); 
            transition: all 0.2s ease; 
            cursor: pointer; 
            font-size: 0.85rem;
            outline: none;
        }
        .filter-dropdown:hover, .filter-dropdown:focus { border-color: var(--text-muted); }
        
        /* 🚀 TRIAGE TABLE STYLING */
        .triage-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 800px; }
        .triage-table th { 
            position: sticky; top: 0; background: var(--surface-card); z-index: 2; 
            padding: 14px 18px; text-align: left; 
            border-bottom: 2px solid var(--border-color); 
            color: var(--text-muted); text-transform: uppercase; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.06em;
        }
        .triage-table td { 
            padding: 16px 18px; text-align: left; 
            border-bottom: 1px solid var(--border-color); 
            vertical-align: middle; color: var(--text-primary); font-weight: 600; font-size: 0.88rem;
        }
        .triage-table tr:hover td { background: var(--surface-subtle); }
        
        /* 🚀 STATUS BADGES */
        .badge { 
            padding: 6px 12px; border-radius: var(--radius-md); font-size: 0.72rem; font-weight: 800; 
            color: white; display: inline-flex; align-items: center; gap: 6px; 
            text-transform: uppercase; letter-spacing: 0.04em; box-shadow: var(--shadow-sm); 
        }
        .badge.critical, .badge.major { background: var(--color-critical); } 
        .badge.warning { background: var(--color-warning); } 
        .badge.minor, .badge.info { background: var(--color-info); } 
        .badge.on-scene, .badge.active { background: var(--color-success); }
        
        /* 🚀 BUTTONS */
        .btn-sm { 
            padding: 8px 16px; border: none; border-radius: var(--radius-md); 
            cursor: pointer; font-weight: 700; color: white !important; font-size: 0.82rem; 
            display: inline-flex; align-items: center; justify-content: center; gap: 6px; 
            box-shadow: var(--shadow-sm); transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1); 
            font-family: var(--font-family);
        }
        .btn-sm:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }

        /* 🚀 MODALS DESIGN */
        .modal { 
            display: none; position: fixed; z-index: 9999; left: 0; top: 0; 
            width: 100vw; height: 100vh; background: rgba(15, 23, 42, 0.6); 
            align-items: center; justify-content: center; backdrop-filter: blur(8px); 
        }
        .modal-content { 
            background: var(--surface-card); 
            padding: 36px; border-radius: var(--radius-xl); width: 100%; max-width: 480px; 
            position: relative; box-shadow: var(--shadow-lg); border: 1px solid var(--border-color); 
            animation: modalPop 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes modalPop { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        .close-modal {
            position: absolute; top: -14px; right: -14px; width: 38px; height: 38px; border-radius: 50%;
            background: var(--surface-card); border: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.4rem; color: var(--text-secondary); cursor: pointer; z-index: 1000;
            transition: all 0.2s ease; box-shadow: var(--shadow-md);
        }
        .close-modal:hover { background: var(--color-critical); color: white; border-color: transparent; transform: scale(1.08); }

        .team-list-container {
            max-height: 220px; overflow-y: auto; margin-bottom: 24px; 
            border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 8px; background: var(--surface-subtle);
        }
        .team-label {
            display: flex; align-items: center; gap: 12px; padding: 12px; 
            border-bottom: 1px solid var(--border-color); cursor: pointer; transition: 0.2s; border-radius: var(--radius-md);
        }
        .team-label:hover { background: var(--surface-card); }
        .team-label.recommended { background: rgba(16, 185, 129, 0.12); border-left: 4px solid var(--color-success); }

        /* 🚀 AUDIO SWITCH */
        .sound-toggle-wrapper { display: flex; align-items: center; gap: 8px; font-weight: 800; color: var(--text-secondary); font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.05em; }
        .toggle-switch { position: relative; display: inline-block; width: 54px; height: 28px; margin-right: 10px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: var(--border-color); transition: .3s; border-radius: 30px; }
        .toggle-slider:before { position: absolute; content: ""; height: 22px; width: 22px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; box-shadow: var(--shadow-sm); z-index: 2; }
        .toggle-switch input:checked + .toggle-slider { background-color: var(--color-success); } 
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(26px); }
        .toggle-slider:after { content: 'OFF'; position: absolute; right: 8px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 9px; font-weight: 800; transition: .3s; z-index: 1; }
        .toggle-switch input:checked + .toggle-slider:after { content: 'ON'; left: 8px; right: auto; color: white; }

        /* 🚀 DARK MODE GLOBAL OVERRIDES */
        html.global-dark-mode {
            --bg-main: #09090b;
            --surface-card: #18181b;
            --surface-subtle: #27272a;
            --border-color: #27272a;
            --text-primary: #f4f4f5;
            --text-secondary: #a1a1aa;
            --text-muted: #71717a;
            --shadow-md: 0 10px 30px rgba(0, 0, 0, 0.5);
            --shadow-lg: 0 20px 50px rgba(0, 0, 0, 0.7);
        }

        html.global-dark-mode body, html.global-dark-mode .main-content { background-color: var(--bg-main) !important; color: var(--text-primary) !important; }
        html.global-dark-mode .sitting-panel, html.global-dark-mode .modal-content, html.global-dark-mode .kpi-card { 
            background: var(--surface-card) !important; border-color: var(--border-color) !important; 
        }
        html.global-dark-mode #dasma-map, html.global-dark-mode .table-scroll-wrapper { 
            background: var(--bg-main) !important; border-color: var(--border-color) !important; 
        }
        html.global-dark-mode h1, html.global-dark-mode h2, html.global-dark-mode h3, 
        html.global-dark-mode p, html.global-dark-mode label, html.global-dark-mode span:not(.badge) { color: var(--text-primary) !important; }
        html.global-dark-mode .triage-table th { background: var(--surface-card) !important; color: var(--text-muted) !important; border-bottom-color: var(--border-color); }
        html.global-dark-mode .triage-table td { color: var(--text-primary) !important; border-bottom-color: var(--border-color); }
        html.global-dark-mode .filter-dropdown { background: var(--surface-subtle); color: var(--text-primary); border-color: var(--border-color); }
        html.global-dark-mode .close-modal { background: var(--surface-subtle); border-color: var(--border-color); color: var(--text-primary); }
        html.global-dark-mode .team-list-container { background: var(--bg-main); border-color: var(--border-color); }
        html.global-dark-mode .team-label { border-bottom-color: var(--border-color); }
        html.global-dark-mode .team-label.recommended { background: rgba(16, 185, 129, 0.15); border-left-color: var(--color-success); }
        html.global-dark-mode .ev-header { background: var(--bg-main) !important; border-bottom-color: var(--border-color) !important; }
        html.global-dark-mode .ev-body { background: var(--surface-card) !important; }
        html.global-dark-mode .ev-box { background: var(--surface-subtle) !important; border-color: var(--border-color) !important; }
        html.global-dark-mode .ev-box div.val { color: var(--text-primary) !important; }

        /* 🚀 MAP PULSE BEACON */
        .custom-leaflet-icon { position: relative; }
        .marker-pulse-critical { position: relative; }
        .marker-pulse-critical::after {
            content: ''; position: absolute; top: 50%; left: 50%;
            width: 44px; height: 44px; margin-top: -22px; margin-left: -22px;
            background: rgba(239, 68, 68, 0.45); border: 2px solid var(--color-critical);
            border-radius: 50%; animation: mapBeaconPulse 1.4s infinite ease-out;
            z-index: -1; pointer-events: none;
        }
        @keyframes mapBeaconPulse { 0% { transform: scale(0.4); opacity: 1; } 100% { transform: scale(2.4); opacity: 0; } }

        /* LEAFLET CONTROLS STYLING */
        .leaflet-control-layers {
            background: var(--surface-card) !important; color: var(--text-primary) !important;
            border-radius: var(--radius-md) !important; box-shadow: var(--shadow-md) !important;
            border: 1px solid var(--border-color) !important; padding: 10px 14px !important;
            font-family: var(--font-family) !important;
        }
        .leaflet-control-layers label, .leaflet-control-layers span { color: var(--text-primary) !important; font-weight: 700 !important; font-size: 0.85rem !important; }
        html.global-dark-mode .leaflet-control-zoom { border: 1px solid var(--border-color) !important; border-radius: var(--radius-md) !important; overflow: hidden; }
        html.global-dark-mode .leaflet-control-zoom a { background-color: var(--surface-card) !important; color: var(--text-primary) !important; border-bottom: 1px solid var(--border-color) !important; }
        
        /* 🚀 RESPONSIVE DASHBOARD OVERRIDES */
        @media (max-width: 1024px) {
            .dashboard-split-layout { 
                flex-direction: column; 
                height: auto; 
                min-height: auto;
            }
            .left-panel { flex: none; height: 50vh; min-height: 400px; }
            .right-panel { flex: none; height: auto; min-height: 500px; }
            .panel-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .panel-header > div { width: 100%; display: flex; flex-wrap: wrap; justify-content: flex-start; }
            .sound-toggle-wrapper { width: 100%; justify-content: flex-start; margin-bottom: 8px; }
        }

        @media (max-width: 768px) {
            .main-content { 
                padding: 15px 15px 30px 15px !important; 
                margin-top: 110px !important; 
            }
            body.has-broadcast .main-content { margin-top: 220px !important; }
            
            #broadcast-banner { 
                top: 95px; left: 15px; right: 15px; 
                padding: 12px 16px; 
                flex-direction: column; 
                gap: 12px; 
                text-align: center; 
            }
            #broadcast-banner button { width: 100%; }
            
            .modal-content, .ev-body, .ev-header { padding: 20px; }
            #evidenceModal .modal-content { max-height: 90vh; overflow-y: auto; overflow-x: hidden; }
            
            /* Stack evidence layout on mobile */
            .ev-body > div { flex-direction: column; }
            .ev-body > div > div { width: 100%; min-width: unset; }
            #evidenceImageFull { max-height: 250px; }
        }
        
        /* 🚀 MOBILE KPI 2x2 GRID & CLICK-TO-EXPAND */
        @media (max-width: 768px) {
            .kpi-grid { 
                grid-template-columns: repeat(2, 1fr); 
                gap: 12px; 
            }
            .kpi-card { 
                padding: 12px; 
                cursor: pointer;
            }
            .kpi-header-row { 
                flex-direction: column; 
                align-items: flex-start; 
                gap: 8px; 
            }
            .kpi-icon-wrapper { 
                width: 32px; height: 32px; font-size: 1.2rem; 
            }
            .kpi-card h3 { 
                font-size: 1.2rem; 
            }
            .kpi-card p { 
                font-size: 0.65rem; 
                white-space: normal; 
                line-height: 1.2;
            }
            
            /* Disable native hover on mobile */
            .kpi-card:hover .kpi-details-container {
                max-height: 0; opacity: 0; visibility: hidden; padding: 0 20px; pointer-events: none;
            }
            
            /* Enable expansion via JS class */
            .kpi-card.mobile-expanded {
                z-index: 1005;
            }
            .kpi-card.mobile-expanded .kpi-details-container {
                max-height: 300px; opacity: 1; visibility: visible;
                top: 100%; padding: 12px; pointer-events: auto;
            }
        }
        
        /* 🚀 MOBILE TABLE COMPRESSION */
        @media (max-width: 768px) {
            .triage-table th:nth-child(n+3), 
            .triage-table td.mobile-hide { 
                display: none !important; 
            }
            .triage-table td {
                padding: 12px 10px;
            }
        }
        
        /* 🚀 FIX: MODAL STACKING AND CLOSE BUTTON CLIPPING */
        #evidenceModal { 
            z-index: 10005 !important; 
        }
        
        @media (max-width: 768px) {
            .close-modal {
                top: 12px !important;
                right: 12px !important;
                background: rgba(0, 0, 0, 0.6) !important;
                color: #fff !important;
                border: 1px solid rgba(255,255,255,0.2) !important;
            }
            
            /* Ensure the header handles the inset button gracefully */
            .ev-header {
                padding-right: 50px !important; 
            }
        }

    </style>
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
                    <div id="available_teams_list" class="team-list-container">
                        <!-- Filled dynamically with Skeletons or Units -->
                    </div>
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
<script>
    let map, incidentLayer, evacLayer; 
    let lastTableHTML = "", lastKpiHash = "", lastMapHash = ""; 
    let evacsVisible = false;
    let previousIncidentCount = -1; 
    let audioCtx = null;
    let soundEnabled = <?php echo ($sound_saved === 1) ? 'true' : 'false'; ?>;
    const API_PATH = 'admin_actions.php';

    function initAudio() {
        if (!audioCtx) {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        }
        if (audioCtx.state === 'suspended') {
            audioCtx.resume();
        }
    }

    function playSynthesizedSound(severity) {
        if (!soundEnabled) return;
        initAudio(); 
        
        const osc = audioCtx.createOscillator();
        const gainNode = audioCtx.createGain();
        
        osc.connect(gainNode);
        gainNode.connect(audioCtx.destination);
        
        if (severity === 'critical') {
            osc.type = 'square';
            osc.frequency.setValueAtTime(600, audioCtx.currentTime);
            osc.frequency.linearRampToValueAtTime(1000, audioCtx.currentTime + 0.3);
            osc.frequency.linearRampToValueAtTime(600, audioCtx.currentTime + 0.6);
            osc.frequency.linearRampToValueAtTime(1000, audioCtx.currentTime + 0.9);
            osc.frequency.linearRampToValueAtTime(600, audioCtx.currentTime + 1.2);
            gainNode.gain.setValueAtTime(0.5, audioCtx.currentTime);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + 1.5);
        } else if (severity === 'major') {
            osc.type = 'sawtooth';
            osc.frequency.setValueAtTime(800, audioCtx.currentTime);
            gainNode.gain.setValueAtTime(0, audioCtx.currentTime);
            gainNode.gain.linearRampToValueAtTime(0.5, audioCtx.currentTime + 0.05);
            gainNode.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.15);
            gainNode.gain.linearRampToValueAtTime(0.5, audioCtx.currentTime + 0.25);
            gainNode.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.35);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + 0.4);
        } else {
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime);
            gainNode.gain.setValueAtTime(0.5, audioCtx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.5);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + 0.5);
        }
    }

    function toggleSound() {
        const checkbox = document.getElementById('soundToggleBtn');
        soundEnabled = checkbox.checked;

        if (soundEnabled) {
            try {
                initAudio();
                playSynthesizedSound('minor'); 
            } catch (e) {
                console.error("Audio failed:", e);
                checkbox.checked = false; 
                soundEnabled = false;
                customAlert("Audio Error", "Your browser does not support the Web Audio API.", "bx-error", "#ef4444");
                return;
            }
        }

        // 🚀 Save state to database so it persists across refreshes
        let fd = new FormData();
        fd.append('action', 'save_preferences');
        fd.append('sound_alert', soundEnabled ? 1 : 0);

        fetch(API_PATH, { method: 'POST', body: fd })
            .catch(e => console.error("Could not save sound setting:", e));
    }

    function closeModal(id) { 
        const mod = document.getElementById(id);
        if(mod) mod.style.display = 'none'; 
    }
    
    function customAlert(title, message, iconClass = 'bx-info-circle', color = '#3b82f6') {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" class="btn-sm" style="flex: 1; background: ${color}; justify-content: center;">OK</button>`;
        document.getElementById('universalModal').style.display = 'flex';
    }

    function customConfirm(title, message, iconClass, color, confirmCallback) {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        document.getElementById('uniModalButtons').innerHTML = `
            <button onclick="closeModal('universalModal')" style="flex:1; padding:10px; border-radius:var(--radius-md); cursor:pointer; border:1px solid var(--border-color); background:transparent; color:var(--text-primary); font-weight:800; font-family:var(--font-family);">Cancel</button>
            <button id="uniConfirmBtn" class="btn-sm" style="flex: 1; padding: 10px; background: ${color}; justify-content: center;">Proceed</button>
        `;
        document.getElementById('universalModal').style.display = 'flex';
        document.getElementById('uniConfirmBtn').onclick = function() { closeModal('universalModal'); confirmCallback(); };
    }

    function toggleEvacLayer() { 
        const btn = document.getElementById('evac-toggle-btn'); 
        if (map.hasLayer(evacLayer)) {
            map.removeLayer(evacLayer);
            if (btn) { btn.style.background = "var(--surface-card)"; btn.style.color = "var(--text-primary)"; }
        } else {
            map.addLayer(evacLayer);
            if (btn) { btn.style.background = "rgba(16, 185, 129, 0.15)"; btn.style.color = "#10b981"; }
            syncDashboard();
        }
    }

    function toggleCluster(key) {
        let rows = document.querySelectorAll('.cluster-row-' + key);
        let icon = document.getElementById('icon_' + key);
        
        if (rows.length > 0) {
            let isHidden = rows[0].style.display === 'none';
            rows.forEach(r => r.style.display = isHidden ? 'table-row' : 'none');
            if (icon) icon.className = isHidden ? 'bx bx-folder-minus' : 'bx bx-folder-plus';
        }
    }

    function getIncidentIcon(type, severity, backupRequested) { 
        let iconClass = 'bxs-map-pin', iconColor = '#64748b'; 
        let t = (type || '').toLowerCase(); 
        let s = (severity || '').toLowerCase();
        
        let isCritical = (s === 'critical' || backupRequested == 1); 

        if (t.includes('fire')) { iconClass = 'bxs-flame'; iconColor = '#ef4444'; } 
        else if (t.includes('accident')) { iconClass = 'bxs-car-crash'; iconColor = '#f59e0b'; } 
        else if (t.includes('medical')) { iconClass = 'bx-plus-medical'; iconColor = '#10b981'; } 
        else if (t.includes('rescue')) { iconClass = 'bx-support'; iconColor = '#3b82f6'; } 
        else if (t.includes('hazard')) { iconClass = 'bx-error'; iconColor = '#f59e0b'; } 
        else if (t.includes('crime') || t.includes('police')) { iconClass = 'bxs-shield'; iconColor = '#1e293b'; } 
        
        let pulseClass = isCritical ? 'marker-pulse-critical' : '';

        return L.divIcon({ 
            html: `<i class='bx ${iconClass}' style='color: ${iconColor}; font-size: 32px; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.3));'></i>`, 
            className: `custom-leaflet-icon ${pulseClass}`, 
            iconSize: [32, 32], 
            iconAnchor: [16, 32] 
        }); 
    }
    
    document.addEventListener('DOMContentLoaded', function() { 
        const mapContainer = document.getElementById('dasma-map');
        if (mapContainer) {
            const osmStreet = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: 'OpenStreetMap'
            });

            const darkMatter = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
                maxZoom: 19, attribution: 'CartoDB'
            });

            const esriSatellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 18, attribution: 'Esri Satellite'
            });

            // Expanded bounds to include Paliparan on the East and Salawag on the North
            const dasmaBounds = L.latLngBounds(
                [14.2500, 120.8900], // SouthWest corner of Dasmariñas
                [14.3900, 121.0200]  // NorthEast corner of Dasmariñas
            );

            map = L.map('dasma-map', { 
                center: [14.3294, 120.9368], 
                zoom: 13,             // Relaxed zoom to fit the wider bounds
                minZoom: 13,          // Allows seeing all of Dasmariñas, but blocks zooming out to Cavite
                maxBounds: dasmaBounds,
                maxBoundsViscosity: 1.0, // Solid invisible wall at the borders
                layers: [osmStreet]
            });
            incidentLayer = L.layerGroup().addTo(map); 
            evacLayer = L.layerGroup().addTo(map); 

            const baseMaps = {
                "Street Map": osmStreet,
                "Dark Mode": darkMatter,
                "Satellite": esriSatellite
            };

            const overlayMaps = {
                "Active Incidents": incidentLayer,
                "Evacuation Centers": evacLayer
            };

            L.control.layers(baseMaps, overlayMaps, { position: 'topright' }).addTo(map);
        }
        
        fetchWeather();
        syncDashboard(); 
        setInterval(syncDashboard, 5000); 
    });
    
    let hasAlertedHeat = sessionStorage.getItem('heat_alerted');

    function applyWeatherData(currentTemp, weatherCode) {
        const tempEl = document.getElementById('weather-temp');
        const alertEl = document.getElementById('weather-alert'); 
        
        if (tempEl) tempEl.innerText = `WEATHER: ${currentTemp}°C`; 
        
        if (alertEl) {
            const cardEl = alertEl.closest('.kpi-card');
            let iconEl = cardEl.querySelector('i');
            
            if (currentTemp >= 40) {
                alertEl.innerText = "Extreme Heat Risk";
                cardEl.className = "kpi-card red";
                if (iconEl) iconEl.className = "bx bxs-hot";
                
                if (!hasAlertedHeat) {
                    sessionStorage.setItem('heat_alerted', 'true');
                    hasAlertedHeat = 'true';
                    
                    let fd = new FormData();
                    fd.append('action', 'send_broadcast');
                    fd.append('title', 'EXTREME HEAT ADVISORY');
                    fd.append('message', `The temperature is currently ${currentTemp}°C. Please stay indoors.`);
                    fd.append('severity', 'critical');
                    
                    fetch(API_PATH, { method: 'POST', body: fd });
                }
            } 
            else if (weatherCode > 50) {
                alertEl.innerText = "Rain / Storm Risk";
                cardEl.className = "kpi-card blue"; 
                if (iconEl) iconEl.className = "bx bxs-cloud-rain";
            } 
            else {
                alertEl.innerText = "Normal Status";
                cardEl.className = "kpi-card yellow";
                if (iconEl) iconEl.className = "bx bxs-cloud";
            }
        }
    }

    function fetchWeather() {
        const cached = localStorage.getItem('weather_cache');
        const cacheExpiry = localStorage.getItem('weather_cache_expiry');
        const now = Date.now();

        // 🚀 Load immediately from cache if valid (< 15 mins)
        if (cached && cacheExpiry && now < parseInt(cacheExpiry, 10)) {
            try {
                const parsed = JSON.parse(cached);
                applyWeatherData(parsed.temp, parsed.code);
                return;
            } catch(e) {}
        }

        fetch('https://api.open-meteo.com/v1/forecast?latitude=14.3294&longitude=120.9368&current_weather=true&timezone=Asia%2FManila')
            .then(res => {
                if (!res.ok) throw new Error("HTTP " + res.status);
                return res.json();
            })
            .then(w => {
                if (w.current_weather) { 
                    const currentTemp = Math.round(w.current_weather.temperature);
                    const weatherCode = w.current_weather.weathercode;
                    
                    // Save to cache for 15 minutes (900,000 ms)
                    localStorage.setItem('weather_cache', JSON.stringify({ temp: currentTemp, code: weatherCode }));
                    localStorage.setItem('weather_cache_expiry', (now + 900000).toString());

                    applyWeatherData(currentTemp, weatherCode);
                }
            }).catch(e => {
                console.log("Weather error:", e);
                const tempEl = document.getElementById('weather-temp');
                const alertEl = document.getElementById('weather-alert');
                if (tempEl) tempEl.innerText = `WEATHER: N/A`; 
                if (alertEl) alertEl.innerText = `Status Offline`;
            });
    }

    function syncDashboard() { 
        const brgyNode = document.getElementById('table-filter-brgy');
        const typeNode = document.getElementById('map-filter-incident');
        const brgyFilter = brgyNode ? brgyNode.value : ''; 
        const typeFilter = typeNode ? typeNode.value : 'all'; 

        fetch(`${API_PATH}?action=master_sync&brgy=${encodeURIComponent(brgyFilter)}&type=${typeFilter}`)
        .then(async res => {
            if(!res.ok) throw new Error(`Network Error: ${res.status} ${res.statusText}`);
            const rawText = await res.text(); 
            try { return JSON.parse(rawText); } 
            catch (err) { throw new Error("PHP Output was not JSON."); }
        })
        .then(data => {
            const kpiAct = document.getElementById('kpi-active'); 
            if(kpiAct) {
                let currentCount = parseInt(data.kpi.active) || 0;
                
                if (previousIncidentCount !== -1 && currentCount > previousIncidentCount && soundEnabled) {
                    let incidentSeverity = 'minor'; 
                    if (data.table) {
                        let tempDiv = document.createElement('div');
                        tempDiv.innerHTML = data.table;
                        let firstRow = tempDiv.querySelector('tr');
                        if (firstRow) {
                            let text = firstRow.innerHTML.toLowerCase();
                            if (text.includes('critical')) incidentSeverity = 'critical';
                            else if (text.includes('major') || text.includes('warning')) incidentSeverity = 'major';
                        }
                    }
                    playSynthesizedSound(incidentSeverity);
                }
                
                previousIncidentCount = currentCount; 
                kpiAct.innerText = data.kpi.active; 
            }

            const kpiDep = document.getElementById('kpi-deployed'); if(kpiDep) kpiDep.innerText = data.kpi.deployed; 
            const kpiEvac = document.getElementById('kpi-evacuees'); if(kpiEvac) kpiEvac.innerText = data.kpi.evacuees; 
            
            if (data.kpi_details) {
                const actDet = document.getElementById('kpi-active-details');
                if(actDet) actDet.innerHTML = data.kpi_details.active.length ? data.kpi_details.active.map(d => `<div>${d}</div>`).join('') : '<div>All clear.</div>';
                const depDet = document.getElementById('kpi-deployed-details');
                if(depDet) depDet.innerHTML = data.kpi_details.deployed.length ? data.kpi_details.deployed.map(d => `<div>${d}</div>`).join('') : '<div>No teams active.</div>';
                const evacDet = document.getElementById('kpi-evacuees-details');
                if(evacDet) evacDet.innerHTML = data.kpi_details.evacuees.length ? data.kpi_details.evacuees.map(d => `<div>${d}</div>`).join('') : '<div>All empty.</div>';
            }

            const tBody = document.getElementById('triage-table-body');
            if (tBody && data.table && data.table !== lastTableHTML) { 
                tBody.innerHTML = data.table; 
                lastTableHTML = data.table;
            } 
            
            if (typeof incidentLayer !== 'undefined' && data.map) {
                incidentLayer.clearLayers(); 
                data.map.forEach(inc => { 
                    let lat = parseFloat(inc.latitude);
                    let lng = parseFloat(inc.longitude);
                    
                    if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
                        L.marker([lat, lng], { 
                            icon: getIncidentIcon(inc.incident_type, inc.severity, inc.backup_requested) 
                        })
                        .addTo(incidentLayer)
                        .bindPopup(`<b>${inc.incident_type}</b><br>${inc.barangay}<br><small style="color:var(--color-critical); font-weight:bold;">Severity: ${inc.severity || 'Pending'}</small>`); 
                    }
                });
            }

            if (typeof evacLayer !== 'undefined' && data.evac_centers) {
                evacLayer.clearLayers();
                data.evac_centers.forEach(evac => {
                    let lat = parseFloat(evac.latitude);
                    let lng = parseFloat(evac.longitude);
                    
                    if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
                        let eIcon = L.divIcon({ 
                            html: `<i class='bx bxs-home-heart' style='color: #10b981; font-size: 28px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));'></i>`, 
                            className: 'custom-leaflet-icon', 
                            iconSize: [28, 28], 
                            iconAnchor: [14, 28] 
                        });
                        L.marker([lat, lng], { icon: eIcon })
                            .addTo(evacLayer)
                            .bindPopup(`<b>${evac.name}</b><br>Barangay: ${evac.barangay}<br>Occupants: ${evac.current_occupants} / ${evac.capacity}`);
                    }
                });
            }

        }).catch(e => {
            if(e.message !== "PHP Output was not JSON.") { console.error(e.message); }
        }); 
    }

    function openDeployModal(ids, name) {
        document.getElementById('dispatch_incident_id').value = ids; 
        document.getElementById('dispatch_incident_name').innerText = "Target: " + name;
        
        // Modal skeleton animation
        document.getElementById('available_teams_list').innerHTML = `
            <div style='padding:12px; border-bottom: 1px solid var(--border-color);'>
                <div class="skeleton skeleton-text long"></div>
                <div class="skeleton skeleton-text short"></div>
            </div>
            <div style='padding:12px;'>
                <div class="skeleton skeleton-text long" style="width: 70%;"></div>
                <div class="skeleton skeleton-text short" style="width: 30%;"></div>
            </div>
        `;
        document.getElementById('dispatchModal').style.display = 'flex';

        fetch(API_PATH + "?action=get_available_teams&incident_type=" + encodeURIComponent(name))
            .then(r=>r.json())
            .then(data => {
                let html = '';
                if(data.length === 0) {
                    html = "<div style='text-align:center; color:var(--color-critical); font-weight:bold; padding: 15px;'>No units currently available.</div>";
                } else {
                    data.forEach(t => {
                        let recBadge = t.is_recommended ? `<span style="background:var(--color-success); color:white; padding: 2px 8px; border-radius: 6px; font-size: 0.65rem; font-weight: 900; margin-left: 8px; vertical-align: middle;">⭐ RECOMMENDED</span>` : "";
                        let recClass = t.is_recommended ? "recommended" : "";
                        
                        html += `<label class="team-label ${recClass}">
                            <input type="checkbox" class="dispatch-team-cb" value="${t.id}" data-name="${t.team_name}" style="transform: scale(1.2);">
                            <span style="font-size:1rem;"><b>${t.team_name}</b> <small style="color:var(--text-muted); font-weight:bold;">(${t.team_type})</small> ${recBadge}<br><small style="color:var(--color-info); font-weight:bold;">📍 ${t.assigned_barangay || 'City-Wide'}</small></span>
                        </label>`;
                    });
                }
                document.getElementById('available_teams_list').innerHTML = html;
            });
    }

    function submitDispatch() {
        let ids = document.getElementById('dispatch_incident_id').value; 
        let cbs = document.querySelectorAll('.dispatch-team-cb:checked');
        if(cbs.length === 0) return customAlert("Selection Required", "Please select at least one unit to deploy.", "bx-error", "#ef4444");

        let teamIds = []; let teamNames = [];
        cbs.forEach(cb => { teamIds.push(cb.value); teamNames.push(cb.getAttribute('data-name')); });

        customConfirm("Confirm Dispatch", `Deploy ${teamNames.length} unit(s) to this incident?`, "bxs-truck", "#10b981", function() {
            let fd = new FormData();
            fd.append('action', 'deploy_team');
            fd.append('incident_id', ids); 
            fd.append('team_ids', JSON.stringify(teamIds));
            fd.append('team_names', teamNames.join(", "));

            fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{
                if(d.success) { closeModal('dispatchModal'); syncDashboard(); }
                else customAlert("Error", d.message, "bx-error", "#ef4444");
            });
        });
    }

    function cancelDispatch(ids) {
        customConfirm("Recall Units", "Are you sure you want to recall these units and revert the incident status?", "bx-undo", "#ef4444", function() {
            let fd = new FormData(); fd.append('action', 'cancel_dispatch'); fd.append('id', ids);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.text()).then(d=>syncDashboard());
        });
    }

    function rejectIncident(ids) {
        customConfirm("Reject Incident", "Are you sure you want to reject this incident as a False Alarm?", "bx-x-circle", "#ef4444", function() {
            let fd = new FormData(); fd.append('action', 'reject_incident'); fd.append('incident_id', ids);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.json()).then(d=>syncDashboard());
        });
    }

    function viewEvidence(imagePath, incidentType, brgy, date, time, reporter, logs, extra, backupRequested) { 
        const imgEl = document.getElementById('evidenceImageFull');
        if (imagePath && imagePath !== 'NULL' && imagePath !== '') {
            imgEl.src = '/dasma_api/' + imagePath;
            imgEl.parentElement.style.display = 'flex';
        } else {
            imgEl.parentElement.style.display = 'none';
        }
        
        const typeNode = document.getElementById('evType'); if(typeNode) typeNode.innerText = incidentType;
        const brgyNode = document.getElementById('evBrgy'); if(brgyNode) brgyNode.innerText = brgy;
        const dateNode = document.getElementById('evDateTime'); if(dateNode) dateNode.innerText = `${date} at ${time}`;
        
        const repNode = document.getElementById('evReporter'); 
        if(repNode) repNode.innerHTML = `${reporter} <br><small style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">${extra || ''}</small>`;
        
        const logNode = document.getElementById('evLogs'); 
        if(logNode) logNode.innerText = logs ? `"${logs}"` : "No reporter logs available.";

        const mod = document.getElementById('evidenceModal');
        if(mod) mod.style.display = 'flex'; 

        if (backupRequested == 1) {
            setTimeout(() => {
                customAlert("🚨 URGENT: BACKUP REQUESTED 🚨", "The responder at this location has requested immediate backup/assistance!", "bxs-error", "#ef4444");
            }, 300);
        }
    }

    function dismissBroadcast(id) {
        document.cookie = "dismissed_broadcast_id=" + id + "; path=/; max-age=" + (60 * 60 * 24);
        const banner = document.getElementById('broadcast-banner');
        if(banner) banner.style.display = 'none';
        document.body.classList.remove('has-broadcast');
    }
    document.querySelectorAll('.kpi-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                // Check if already expanded
                const isExpanded = this.classList.contains('mobile-expanded');
                
                // Close all cards first
                document.querySelectorAll('.kpi-card').forEach(c => {
                    c.classList.remove('mobile-expanded');
                });
                
                // If it wasn't expanded, open it now
                if (!isExpanded) {
                    this.classList.add('mobile-expanded');
                }
            }
        });
    });                                       
    function openAnnouncementModal(id = '', title = '', message = '') {
        document.getElementById('ann_id').value = id;
        document.getElementById('ann_title').value = title;
        document.getElementById('ann_message').value = message.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
        document.getElementById('ann_image').value = ''; 
        
        document.getElementById('annModalTitle').innerHTML = id ? `<i class='bx bx-edit' style='color:var(--color-info);'></i> Edit Announcement` : `<i class='bx bxs-bell-ring' style='color:var(--color-warning);'></i> Create Announcement`;
        
        document.getElementById('announcementModal').style.display = 'flex';
    }
    function openMobileModal(row) {
        if (window.innerWidth > 768) return; 

        const cells = row.querySelectorAll('td');
        if (cells.length < 6) return;

        document.getElementById('m-modal-time').innerHTML = cells[0].innerHTML;
        document.getElementById('m-modal-loc').innerHTML = cells[1].innerHTML;
        document.getElementById('m-modal-info').innerHTML = cells[2].innerHTML;
        document.getElementById('m-modal-ev').innerHTML = cells[3].innerHTML;
        document.getElementById('m-modal-status').innerHTML = cells[4].innerHTML;
        document.getElementById('m-modal-actions').innerHTML = cells[5].innerHTML;

        document.getElementById('mobileIncidentModal').style.display = 'flex';
    }
    function saveAnnouncement() {
        let id = document.getElementById('ann_id').value;
        let title = document.getElementById('ann_title').value.trim();
        let msg = document.getElementById('ann_message').value.trim();
        let img = document.getElementById('ann_image').files[0];

        if(!title || !msg) return customAlert("Required Fields", "Title and Message are required.", "bx-error", "#ef4444");

        let fd = new FormData();
        fd.append('action', 'save_announcement');
        if(id) fd.append('id', id);
        fd.append('title', title);
        fd.append('message', msg);
        if(img) fd.append('image', img);

        fetch('admin_actions.php', { method: 'POST', body: fd })
        .then(async r => {
            if (!r.ok) {
                let errText = await r.text();
                throw new Error("HTTP " + r.status + ": " + errText);
            }
            return r.text();
        })
        .then(text => {
            if(text.trim() === 'success') {
                closeModal('announcementModal');
                location.reload(); 
            } else {
                customAlert("Server Response", text, "bx-error", "#ef4444");
            }
        }).catch(e => {
            customAlert("Fetch Failed", e.message, "bx-error", "#ef4444");
        });
    }
    
    function resolveIncident(ids) {
        customConfirm(
            "Mark as Resolved?",
            "This will officially close the incident, automatically archive the connected backup request, and recall any deployed units. Proceed?",
            "bx-check-shield",
            "#10b981",
            function() {
                let fd = new FormData();
                fd.append('action', 'admin_resolve_incident');
                fd.append('incident_id', ids);

                fetch(API_PATH, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        syncDashboard(); 
                    } else {
                        customAlert("Error", d.message, "bx-error", "#ef4444");
                    }
                }).catch(e => {
                    console.error(e);
                    syncDashboard(); 
                });
            }
        );
    }
</script>
</body>
</html>
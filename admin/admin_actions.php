<?php
require_once '../php/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
ob_start();

require_once '../php/auth.php';

$auth = new Auth($conn);

if (!$auth->is_logged_in()) {
    ob_end_clean();
    header('Content-Type: application/json');
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Unauthorized']));
}

$action     = $_GET['action'] ?? $_POST['action'] ?? '';
$user_id    = $_SESSION['user_id'] ?? null;
$role       = $_SESSION['role'] ?? 'user';
$admin_brgy = $_SESSION['barangay'] ?? '';

session_write_close();
$ADMIN_TIER_ROLES = ['admin', 'barangay_admin', 'superadmin'];
function requireRole(array $allowedRoles, $role) {
    if (!in_array($role, $allowedRoles, true)) {
        if (ob_get_level() > 0) { ob_end_clean(); }
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden: insufficient role.']);
        exit();
    }
}

// =========================================================================================
// 🚀 AUTO-REPAIR SYSTEM
// =========================================================================================
$check_col = $conn->query("SHOW COLUMNS FROM incidents LIKE 'verified_by'");
if ($check_col && $check_col->num_rows === 0) {
    $conn->query("ALTER TABLE incidents ADD COLUMN backup_requested TINYINT(1) DEFAULT 0");
    $conn->query("ALTER TABLE incidents ADD COLUMN is_verified TINYINT(1) DEFAULT 0");
    $conn->query("ALTER TABLE incidents ADD COLUMN verified_by VARCHAR(255) DEFAULT NULL"); 
    $conn->query("ALTER TABLE incidents ADD COLUMN severity VARCHAR(50) DEFAULT 'Pending'");
    $conn->query("ALTER TABLE incidents ADD COLUMN admin_remarks TEXT DEFAULT NULL");
}
$conn->query("CREATE TABLE IF NOT EXISTS incident_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    incident_id INT NOT NULL,
    user_id INT NOT NULL,
    log_message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");

// 🚀 NEW: Ensure is_online column exists for responders
$check_col_up = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'is_online'");
if ($check_col_up && $check_col_up->num_rows === 0) {
    $conn->query("ALTER TABLE user_profiles ADD COLUMN is_online TINYINT(1) DEFAULT 0");
}
// =========================================================================================

// 🚀 SECURED: Save Announcement
if (isset($_POST['action']) && $_POST['action'] === 'save_announcement') {
    requireRole($ADMIN_TIER_ROLES, $role);
    ob_end_clean(); 
    $id = isset($_POST['id']) && !empty($_POST['id']) ? (int)$_POST['id'] : null;
    $title = $_POST['title'];
    $message = $_POST['message'];
    
    $author_id = $_SESSION['user_id']; 
    $image_path = null;

    if (isset($_FILES['image']) && $_FILES['image']['name'] !== '') {
        if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            echo "Upload Error Code: " . $_FILES['image']['error']; exit();
        }
        $target_dir = $_SERVER['DOCUMENT_ROOT'] . "/dasma_api/uploads/announcements/";
        if (!is_dir($target_dir)) { mkdir($target_dir, 0777, true); }
        
        // PATCH VULN-A04: MIME + extension whitelist
        $a_allowed_mime = ["image/jpeg","image/png","image/gif","image/webp"];
        $a_allowed_ext  = ["jpg","jpeg","png","gif","webp"];
        $a_finfo = finfo_open(FILEINFO_MIME_TYPE);
        $a_mime  = finfo_file($a_finfo, $_FILES["image"]["tmp_name"]);
        finfo_close($a_finfo);
        $a_ext   = strtolower(pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION));
        if (!in_array($a_mime, $a_allowed_mime) || !in_array($a_ext, $a_allowed_ext) || !getimagesize($_FILES["image"]["tmp_name"])) {
            echo "invalid_file_type"; exit();
        }
        $filename    = time() . "_" . bin2hex(random_bytes(4)) . "." . $a_ext;
        $target_file = $target_dir . $filename;
        if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
            $image_path = "uploads/announcements/" . $filename;
        }
    }

    if ($id) {
        if ($image_path) {
            $stmt = $conn->prepare("UPDATE announcements SET title=?, message=?, image_path=? WHERE id=?");
            $stmt->bind_param("sssi", $title, $message, $image_path, $id);
        } else {
            $stmt = $conn->prepare("UPDATE announcements SET title=?, message=? WHERE id=?");
            $stmt->bind_param("ssi", $title, $message, $id);
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO announcements (author_id, title, message, image_path) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $author_id, $title, $message, $image_path);
    }
    
    if ($stmt->execute()) { echo "success"; } else { echo "Database Error: " . $stmt->error; }
    $stmt->close();
    exit();
}

// 🚀 SECURED: Delete Announcement
if (isset($_POST['action']) && $_POST['action'] === 'delete_announcement') {
    requireRole($ADMIN_TIER_ROLES, $role);
    ob_end_clean();
    $id = (int)$_POST['id'];
    $stmt = $conn->prepare("DELETE FROM announcements WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    echo "success";
    exit();
}

// 🚀 MULTI-ID SYNC: Reject Incident
if (isset($_POST['action']) && $_POST['action'] === 'reject_incident') {
    requireRole($ADMIN_TIER_ROLES, $role);
    ob_end_clean();
    $ids_raw = $_POST['incident_id'] ?? '';
    $ids_array = array_filter(array_map('intval', explode(',', $ids_raw))); // Safe Int Cast
    if (!empty($ids_array)) {
        $id_list = implode(',', $ids_array);
        $conn->query("UPDATE incidents SET status = 'rejected', admin_remarks = 'Rejected by Admin (False Alarm)' WHERE id IN ($id_list)");
        $conn->query("UPDATE response_teams SET status = 'available', current_incident_id = NULL WHERE current_incident_id IN ($id_list)");
    }
    echo json_encode(['success' => true]);
    exit();
}

// 🚀 MULTI-ID SYNC: Request Backup
if (isset($_POST['action']) && $_POST['action'] === 'request_backup') {
    requireRole($ADMIN_TIER_ROLES, $role);
    ob_end_clean();
    $ids_raw = $_POST['incident_id'] ?? '';
    $ids_array = array_filter(array_map('intval', explode(',', $ids_raw))); // Safe Int Cast
    if (!empty($ids_array)) {
        $id_list = implode(',', $ids_array);
        $conn->query("UPDATE incidents SET backup_requested = 1 WHERE id IN ($id_list)");
    }
    echo json_encode(['success' => true]);
    exit();
}

// 🚀 MULTI-ID SYNC: Resolve Incident
if (isset($_POST['action']) && $_POST['action'] === 'admin_resolve_incident') {
    requireRole($ADMIN_TIER_ROLES, $role);
    ob_end_clean();
    $ids_raw = $_POST['incident_id'] ?? '';
    $ids_array = array_filter(array_map('intval', explode(',', $ids_raw))); // Safe Int Cast
    if (!empty($ids_array)) {
        $id_list = implode(',', $ids_array);
        $conn->query("UPDATE incidents SET status = 'resolved', backup_requested = 0 WHERE id IN ($id_list)");
        $conn->query("UPDATE response_teams SET status = 'available', current_incident_id = NULL WHERE current_incident_id IN ($id_list)");
    }
    echo json_encode(['success' => true]);
    exit();
}

// 🚀 SECURED MULTI-ID SYNC: Confirm Verification
if (isset($_POST['action']) && $_POST['action'] === 'confirm_verify') {
    requireRole($ADMIN_TIER_ROLES, $role);
    ob_end_clean();
    $ids_raw = $_POST['incident_id'] ?? '';
    $ids_array = array_filter(array_map('intval', explode(',', $ids_raw))); // Safe Int Cast
    if (!empty($ids_array)) {
        $id_list = implode(',', $ids_array);
        $v_name = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        if (empty($v_name)) { $v_name = $_SESSION['username']; }
        
        $stmt = $conn->prepare("UPDATE incidents SET is_verified = 1, verified_by = ? WHERE id IN ($id_list)");
        $stmt->bind_param("s", $v_name);
        $stmt->execute();
        $stmt->close();
    }
    echo json_encode(['success' => true]);
    exit();
}

// 🚀 SECURED MULTI-ID SYNC: Verify Severity Levels
if (isset($_POST['action']) && $_POST['action'] === 'verify_incident') {
    requireRole($ADMIN_TIER_ROLES, $role);
    ob_end_clean();
    $ids_raw = $_POST['incident_id'] ?? '';
    $ids_array = array_filter(array_map('intval', explode(',', $ids_raw))); // Safe Int Cast
    // PATCH VULN-A14: severity is rendered as HTML elsewhere via htmlspecialchars(),
    // but whitelisting it here also stops garbage/oversized values from ever
    // reaching the incidents table in the first place.
    $ALLOWED_SEVERITIES = ['Critical', 'Major', 'Minor', 'Info', 'Pending'];
    $posted_severity = $_POST['severity'] ?? '';
    $new_severity = in_array($posted_severity, $ALLOWED_SEVERITIES, true) ? $posted_severity : 'Pending';
    $remarks = $_POST['remarks'] ?? '';
    
    if (!empty($ids_array)) {
        $id_list = implode(',', $ids_array);
        $stmt = $conn->prepare("UPDATE incidents SET severity = ?, admin_remarks = ? WHERE id IN ($id_list)");
        $stmt->bind_param("ss", $new_severity, $remarks);
        $stmt->execute();
        $stmt->close();
    }
    echo "success"; 
    exit();
}

// PATCH VULN-A02+A03 / VULN-A13: $action/$user_id/$role/$admin_brgy and the
// requireRole() gate are now defined immediately after the login check above,
// so every handler in this file — including the ones before this line — is
// covered.

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    // 🚀 SECURED: Get Active Incidents
    if ($action === 'get_active_incidents') {
        requireRole($ADMIN_TIER_ROLES, $role);
        ob_end_clean();
        header('Content-Type: application/json');
        
        $params = [];
        $types = "";
        $brgy_filter = "";
        
        // 🚀 GEOFENCE: Restrict to Dasmariñas boundaries
        $city_limits = " AND (latitude BETWEEN 14.2500 AND 14.3900 AND longitude BETWEEN 120.8900 AND 121.0200) ";
        
        if ($role === 'admin' || $role === 'barangay_admin') {
            $brgy_filter = " AND (barangay = ? OR barangay LIKE ?) ";
            $types .= "ss";
            $params[] = $admin_brgy;
            $params[] = "%" . $admin_brgy . "%";
        }
        
        $query = "SELECT id, incident_type, barangay, backup_requested FROM incidents WHERE status NOT IN ('archived', 'resolved', 'rejected') $brgy_filter $city_limits ORDER BY CASE severity WHEN 'Critical' THEN 1 WHEN 'Major' THEN 2 WHEN 'Minor' THEN 3 WHEN 'Info' THEN 4 ELSE 5 END ASC, created_at DESC";
        $stmt = $conn->prepare($query);
        if (!empty($params)) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $res = $stmt->get_result();
        
        $incidents = [];
        if ($res) { while ($row = $res->fetch_assoc()) { $incidents[] = $row; } }
        $stmt->close();
        echo json_encode($incidents);
        exit();
    }

    // 🚀 SECURED: Master Sync API
    if ($action === 'master_sync') {
        requireRole($ADMIN_TIER_ROLES, $role);
        session_write_close(); 
        header('Content-Type: application/json');

        if (!function_exists('getStrictBarangay')) {
            function getStrictBarangay($lat, $lng, $fallbackText) {
                global $conn;
                static $official_barangays = [];

                if (empty($official_barangays)) {
                    $res = $conn->query("SELECT name FROM barangays WHERE status = 'active'");
                    if ($res) {
                        while ($row = $res->fetch_assoc()) {
                            $official_barangays[] = $row['name'];
                        }
                    }
                }

                $cleanText = trim(str_ireplace([', Dasmariñas', ', Cavite', 'Philippines'], '', $fallbackText));
                
                $aliases = [
                    'manuelaville' => 'San Agustin II', 
                    '6XWG+X37' => 'Biga I', 
                    'the courtyards' => 'Salawag',
                    'orchard' => 'Salawag'
                ];

                foreach ($aliases as $alias => $real_brgy) {
                    if (stripos($cleanText, $alias) !== false) return $real_brgy;
                }

                foreach ($official_barangays as $brgy) {
                    if (strcasecmp($cleanText, $brgy) === 0) return $brgy;
                }

                foreach ($official_barangays as $brgy) {
                    if (stripos($cleanText, $brgy) !== false) {
                        $pos = stripos($cleanText, $brgy);
                        $nextChar = substr($cleanText, $pos + strlen($brgy), 1);
                        if (strtoupper($nextChar) === 'I') continue; 
                        return $brgy;
                    }
                }
                return $cleanText;
            }
        }

        if (!function_exists('getDistanceMeters')) {
            function getDistanceMeters($lat1, $lon1, $lat2, $lon2) {
                $earth_radius = 6371000; 
                $dLat = deg2rad($lat2 - $lat1);
                $dLon = deg2rad($lon2 - $lon1);
                $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
                return $earth_radius * (2 * asin(sqrt($a)));
            }
        }

        $response = ['kpi' => [], 'kpi_details' => [], 'map' => [], 'evac_centers' => [], 'table' => ''];
        
        $params = []; $types = "";
        $evac_params = []; $evac_types = "";
        
        $brgy_filter = "";
        $evac_brgy_filter = ""; 
        $type_clause = "";

        $type = isset($_GET['type']) ? $_GET['type'] : 'all';
        if ($type !== 'all') {
            $type_clause = " AND i.incident_type LIKE ? ";
            $types .= "s"; $params[] = "%" . $type . "%";
        }

        // 🚀 THE FIX: Enforce a strict City Geofence so incidents outside Dasmariñas are completely ignored
        $type_clause .= " AND (i.latitude BETWEEN 14.2500 AND 14.3900 AND i.longitude BETWEEN 120.8900 AND 121.0200) ";

        if ($role === 'superadmin') {
            if (!empty($_GET['brgy'])) {
                $brgy_filter = " AND (i.barangay = ? OR i.barangay LIKE ?) ";
                $evac_brgy_filter = " AND (barangay = ? OR barangay LIKE ?) ";
                $types .= "ss"; $params[] = $_GET['brgy']; $params[] = "%" . $_GET['brgy'] . "%";
                $evac_types .= "ss"; $evac_params[] = $_GET['brgy']; $evac_params[] = "%" . $_GET['brgy'] . "%";
            }
        } elseif ($role === 'admin' || $role === 'barangay_admin') {
            $target_brgy = !empty($_GET['brgy']) ? $_GET['brgy'] : $admin_brgy;
            if (!empty($target_brgy)) {
                $brgy_filter = " AND (i.barangay = ? OR i.barangay LIKE ?) ";
                $evac_brgy_filter = " AND (barangay = ? OR barangay LIKE ?) ";
                $types .= "ss"; $params[] = $target_brgy; $params[] = "%" . $target_brgy . "%";
                $evac_types .= "ss"; $evac_params[] = $target_brgy; $evac_params[] = "%" . $target_brgy . "%";
            }
        }
        
        // Helper for Master Sync prepared statements
        if (!function_exists('executeSyncQuery')) {
            function executeSyncQuery($conn, $sql, $types, $params) {
                $stmt = $conn->prepare($sql);
                if (!empty($params)) { $stmt->bind_param($types, ...$params); }
                $stmt->execute();
                $res = $stmt->get_result();
                $stmt->close();
                return $res;
            }
        }
        
        // Active KPI
        $res1 = executeSyncQuery($conn, "SELECT COUNT(*) as c FROM incidents i WHERE i.status NOT IN ('archived', 'rejected') $brgy_filter $type_clause", $types, $params);
        $response['kpi']['active'] = $res1 ? (int)$res1->fetch_assoc()['c'] : 0;

        // Deployed KPI
        $res2 = executeSyncQuery($conn, "SELECT COUNT(*) as c FROM response_teams rt JOIN incidents i ON rt.current_incident_id = i.id WHERE rt.current_incident_id IS NOT NULL AND rt.current_incident_id > 0 $brgy_filter $type_clause", $types, $params);
        $response['kpi']['deployed'] = $res2 ? (int)$res2->fetch_assoc()['c'] : 0;

        // Evacuees KPI
        $res3 = executeSyncQuery($conn, "SELECT SUM(current_occupants) as total FROM evacuation_centers WHERE 1=1 $evac_brgy_filter", $evac_types, $evac_params);
        $response['kpi']['evacuees'] = $res3 ? (int)($res3->fetch_assoc()['total'] ?? 0) : 0;

        $act_details = [];
        $r1 = executeSyncQuery($conn, "SELECT incident_type, barangay FROM incidents i WHERE i.status NOT IN ('archived', 'rejected') $brgy_filter $type_clause ORDER BY CASE i.severity WHEN 'Critical' THEN 1 WHEN 'Major' THEN 2 WHEN 'Minor' THEN 3 WHEN 'Info' THEN 4 ELSE 5 END ASC, i.created_at DESC LIMIT 10", $types, $params);
        if ($r1) { while($row = $r1->fetch_assoc()) { $act_details[] = "<b>{$row['incident_type']}</b> • {$row['barangay']}"; } }
        
        $dep_details = [];
        $r2 = executeSyncQuery($conn, "SELECT rt.team_name, i.barangay FROM response_teams rt JOIN incidents i ON rt.current_incident_id = i.id WHERE rt.current_incident_id IS NOT NULL AND rt.current_incident_id > 0 $brgy_filter $type_clause", $types, $params);
        if ($r2) { while($row = $r2->fetch_assoc()) { $dep_details[] = "<b>{$row['team_name']}</b> • {$row['barangay']}"; } }
        
        $evac_details = [];
        $r3 = executeSyncQuery($conn, "SELECT name, current_occupants FROM evacuation_centers WHERE current_occupants > 0 $evac_brgy_filter", $evac_types, $evac_params);
        if ($r3) { while($row = $r3->fetch_assoc()) { $evac_details[] = "<b>{$row['current_occupants']} Pax</b> • {$row['name']}"; } }
        
        $response['kpi_details'] = ['active' => $act_details, 'deployed' => $dep_details, 'evacuees' => $evac_details];

        $resMap = executeSyncQuery($conn, "SELECT id, incident_type, barangay, latitude, longitude, status, severity, backup_requested FROM incidents i WHERE i.status NOT IN ('archived', 'rejected') $brgy_filter $type_clause", $types, $params);
        if ($resMap) { while ($row = $resMap->fetch_assoc()) { $response['map'][] = $row; } }

        $resEvac = executeSyncQuery($conn, "SELECT id, name, barangay, latitude, longitude, capacity, current_occupants, status FROM evacuation_centers WHERE 1=1 $evac_brgy_filter", $evac_types, $evac_params);
        if ($resEvac) { while ($row = $resEvac->fetch_assoc()) { $response['evac_centers'][] = $row; } }

        // Triage Table Data
        $query = "SELECT i.*, i.backup_requested, i.is_verified, i.verified_by, u.username, u.first_name, u.last_name, u.barangay as reporter_home,
                         (SELECT position FROM user_profiles WHERE user_id = u.id LIMIT 1) as reporter_pos, 
                         (SELECT phone_number FROM user_profiles WHERE user_id = u.id LIMIT 1) as reporter_phone,
                         (SELECT log_message FROM incident_logs WHERE incident_id = i.id ORDER BY created_at ASC LIMIT 1) as user_logs
                  FROM incidents i 
                  LEFT JOIN users u ON i.reported_by = u.id 
                  WHERE i.status NOT IN ('archived', 'rejected') $brgy_filter $type_clause
                  ORDER BY CASE i.severity WHEN 'Critical' THEN 1 WHEN 'Major' THEN 2 WHEN 'Minor' THEN 3 WHEN 'Info' THEN 4 ELSE 5 END ASC, i.created_at DESC LIMIT 50"; 
        
        $resTab = executeSyncQuery($conn, $query, $types, $params);
        $html = "";
        
        if ($resTab && $resTab->num_rows > 0) {
            $clustered_data = [];
            
            while ($inc = $resTab->fetch_assoc()) {
                $raw_brgy = trim((string)($inc['barangay'] ?? ''));
                $fallback_brgy = !empty($raw_brgy) ? $raw_brgy : ($inc['reporter_home'] ?? 'Unknown Location');
                $inc['display_brgy'] = getStrictBarangay($inc['latitude'], $inc['longitude'], $fallback_brgy);
                
                $lat = (float)$inc['latitude'];
                $lng = (float)$inc['longitude'];
                $found_cluster = false;

                foreach ($clustered_data as $key => $group) {
                    $main = $group[0];
                    if (trim($main['incident_type']) === trim($inc['incident_type'])) {
                        $dist = getDistanceMeters($lat, $lng, (float)$main['latitude'], (float)$main['longitude']);
                        if ($dist <= 100) {
                            $clustered_data[$key][] = $inc;
                            $found_cluster = true;
                            break;
                        }
                    }
                }

                if (!$found_cluster) {
                    $clustered_data["cluster_" . $inc['id']] = [$inc];
                }
            }

            $renderRow = function($inc, $role, $extraClass, $extraStyle, $targetIds = null, $isParent = false, $duplicateCount = 0, $clusterKey = '') {
                $status = $inc['status'] ?? 'active';
                $coords = $inc['latitude'] . ", " . $inc['longitude'];
                $exact_time = date('h:i A', strtotime($inc['created_at']));
                $exact_date = date('M d, Y', strtotime($inc['created_at']));
                $user_logs = $inc['user_logs'] ?? 'No additional details provided by the reporter.';
                
                $full_name = trim(($inc['first_name'] ?? '') . ' ' . ($inc['last_name'] ?? ''));
                $reporter_display = !empty($full_name) ? $full_name : ($inc['username'] ?? 'Anonymous');
                $extra_info = (!empty($inc['reporter_pos']) ? $inc['reporter_pos'] . " | " : "") . ($inc['reporter_phone'] ?? "No Contact");

                // PATCH VULN-A14: addslashes() alone only escapes for the JS-string
                // context; it does nothing to stop a raw ' or " from breaking out of
                // the surrounding HTML attribute (HTML doesn't honor backslash escapes).
                // Wrapping with htmlspecialchars(..., ENT_QUOTES) closes that gap.
                $safe_img = htmlspecialchars(addslashes($inc['image_path'] ?? ''), ENT_QUOTES);
                $safe_type = htmlspecialchars(addslashes($inc['incident_type']), ENT_QUOTES);
                $safe_brgy = htmlspecialchars(addslashes($inc['display_brgy']), ENT_QUOTES);
                $safe_rep = htmlspecialchars(addslashes($reporter_display), ENT_QUOTES);
                $safe_logs = htmlspecialchars(addslashes(preg_replace('/\s+/', ' ', $user_logs)), ENT_QUOTES);
                $safe_extra = htmlspecialchars(addslashes($extra_info), ENT_QUOTES);
                $safe_backup = (int)$inc['backup_requested'];

                $dispatch_id = $targetIds ?: $inc['id'];

                $btn_color = (!empty($safe_img) && $safe_img !== 'NULL') ? '#424242' : '#999999';
                $btn_icon = (!empty($safe_img) && $safe_img !== 'NULL') ? 'bx-camera' : 'bx-info-circle';
                $evidence_btn = "<button class='btn-sm' style='background:$btn_color; margin: 0 auto;' onclick='event.stopPropagation(); viewEvidence(\"$safe_img\", \"$safe_type\", \"$safe_brgy\", \"$exact_date\", \"$exact_time\", \"$safe_rep\", \"$safe_logs\", \"$safe_extra\", $safe_backup)'><i class='bx $btn_icon'></i></button>";
                    
                $sev_badge = ($inc['severity'] === 'Critical') ? 'critical' : (($inc['severity'] === 'Major') ? 'major' : 'warning');
                $display_sev = htmlspecialchars(strtoupper($inc['severity'] ?? 'PENDING'));
                
                $status_html = "";
                if ($inc['backup_requested'] == 1) { $status_html .= "<span class='badge' style='background:#b10000; animation: blink 1s infinite; width:100%; justify-content:center; margin-top:5px;'>🚨 BACKUP NEEDED</span><style>@keyframes blink { 50% { opacity: 0; } }</style>"; }
                
                $status_lower = strtolower($status);
                if ($status_lower === 'on-scene') { $status_html .= "<span class='badge on-scene' style='margin-top:6px; font-size:9px; width:100%; justify-content:center;'><i class='bx bx-check-circle'></i> ON SCENE</span>"; } 
                elseif ($status_lower === 'dispatched') { $status_html .= "<span class='badge info' style='margin-top:6px; font-size:9px; width:100%; justify-content:center;'><i class='bx bxs-truck'></i> EN ROUTE</span>"; } 
                else { $status_html .= "<small style='font-size:10px; display:block; margin-top:6px; font-weight:800; color:#666;'>Status: ".strtoupper($status)."</small>"; }

                $is_pending_verification = ($inc['is_verified'] == 0);
                $verified_by_name = htmlspecialchars($inc['verified_by'] ?? 'N/A');

                $action_btns = "<div class='action-btn-container' data-incident-ids='$dispatch_id' style='display:flex; flex-direction:column; gap:6px; align-items:center; justify-content:center;'>";
                if ($status_lower === 'active' || $status_lower === 'pending') {
                    if ($role === 'superadmin') {
                        if ($inc['backup_requested'] == 0) { 
                            $action_btns .= "<span style='color:#f57c00; font-size:0.75rem; font-weight:bold; font-style:italic; text-align:center;'><i class='bx bx-radar bx-burst'></i> Awaiting Local</span>"; 
                        } else { 
                            $action_btns .= "<span style='color:#d32f2f; font-size:0.75rem; font-weight:bold; font-style:italic; text-align:center;'><i class='bx bxs-error bx-flashing'></i> Backup Needed</span>"; 
                        }
                        
                        if (!$is_pending_verification) {
                            $action_btns .= "<div class='verified-by-text' style='color: #666; font-size: 0.75rem; font-weight: 700; margin-top: 4px; text-align: center;'>Verified by:<br><span style='color: #8e24aa;'>$verified_by_name</span></div>";
                        }
                    } else {
                        if ($is_pending_verification) {
                            $action_btns .= "
                                <div class='verify-btn-wrapper' style='width:100%; position: relative;'>
                                    <button class='btn-sm verify-btn' style='background:#8e24aa; width: 100%; justify-content: center;' onclick='event.stopPropagation(); toggleVerifyDropdown(this)'><i class='bx bx-check-shield' style='font-size: 1.1rem;'></i> Verify</button>
                                    <div class='verify-dropdown' style='display:none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); z-index: 100; width:150px; left:50%; transform:translateX(-50%); padding:5px;'>
                                        <button class='btn-sm confirm-verify-btn' style='background:#388e3c; color: white !important; width: 100%; justify-content: center; margin-bottom: 5px;' data-confirm-ids='$dispatch_id'>Confirm</button>
                                        <button class='btn-sm cancel-verify-btn' style='background:#555555; color: white !important; width: 100%; justify-content: center;' onclick='event.stopPropagation(); hideVerifyDropdown(this)'>Cancel</button>
                                    </div>
                                </div>
                            ";
                            $action_btns .= "<button class='btn-sm reject-btn' style='background:#555555; width: 100%; justify-content: center;' onclick='event.stopPropagation(); rejectIncident(\"$dispatch_id\")'><i class='bx bx-x-circle' style='font-size: 1.1rem;'></i> Reject</button>";
                        } else {
                            $action_btns .= "<button class='btn-sm sev-btn' style='background:#8e24aa; width: 100%; justify-content: center;' onclick='event.stopPropagation(); openVerifyModal(\"$dispatch_id\")'><i class='bx bx-slider' style='font-size: 1.1rem;'></i> Change Severity</button>";
                            $action_btns .= "<button class='btn-sm dispatch-btn' style='background:#388e3c; width: 100%; justify-content: center;' onclick='event.stopPropagation(); openDeployModal(\"$dispatch_id\", \"$safe_type\")'><i class='bx bxs-truck' style='font-size: 1.1rem;'></i> Dispatch</button>";
                        }
                    }
                } elseif ($status_lower === 'dispatched' || $status_lower === 'en route' || $status_lower === 'en_route' || $status_lower === 'on-scene') {
                    $action_btns .= "<button class='btn-sm' style='background:#d32f2f; padding: 10px 15px; font-size: 0.9rem; width: 100%; justify-content: center;' onclick='event.stopPropagation(); cancelDispatch(\"$dispatch_id\")'><i class='bx bx-undo' style='font-size: 1.1rem;'></i> Recall</button>";
                    if ($role !== 'superadmin' && $inc['backup_requested'] == 0) { $action_btns .= "<button class='btn-sm' style='background:#f57c00; padding: 10px 15px; width: 100%; justify-content: center;' onclick='event.stopPropagation(); requestBackup(\"$dispatch_id\")'><i class='bx bxs-error-circle'></i> Need Backup</button>"; }
                } else {
                    $action_btns .= "<span style='color:#888; font-size:0.85rem; font-weight:bold; font-style:italic;'>No Actions</span>";
                }
                $action_btns .= "</div>";

                if ($extraClass != "" && strpos($extraClass, 'cluster-row') !== false) {
                    $action_btns = "<span style='color: #888; font-size: 0.8rem; font-weight: bold; background: #eee; padding: 5px 10px; border-radius: 8px;'><i class='bx bx-link'></i> Merged to Primary</span>";
                }

                $action_td = "<td class='mobile-hidden action-td' style='vertical-align: middle; width: 150px; padding-right: 25px;'><small class='mobile-label'>ACTIONS</small>$action_btns</td>";

                $incident_info = "<span style='font-weight:700;'>".htmlspecialchars($inc['incident_type'])."</span><br>
                                  <small style='color:#666; font-style:italic;'>\"".htmlspecialchars(substr($user_logs, 0, 45))."...\"</small>";
                
                if ($isParent && $duplicateCount > 0) {
                    $incident_info .= "<div style='margin-top: 8px;'><span style='background: rgba(25,118,210,0.1); color: #1976d2; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; border: 1px solid rgba(255,255,255,0.3); display: inline-flex; align-items: center; gap: 4px;'><i id='icon_$clusterKey' class='bx bx-folder-plus' style='font-size: 1rem;'></i> +$duplicateCount DUPLICATE</span> <small style='color: #888; margin-left: 5px; font-weight: bold;'>Click to expand</small></div>";
                }

                $clusterCall = ($isParent && $duplicateCount > 0) ? "toggleCluster(\"$clusterKey\");" : "";
                $onclick = "onclick='openMobileModal(this); $clusterCall'";
                $hover = ($isParent && $duplicateCount > 0) ? "onmouseover='this.style.background=\"#e2e8f0\"' onmouseout='this.style.background=\"transparent\"'" : "";

                $rowHtml = "<tr class='$extraClass' style='cursor: pointer; $extraStyle' $onclick $hover>
                    <td style='vertical-align: middle;'><div style='font-weight: 800; font-size: 1.1rem; color: #d32f2f;'>{$exact_time}</div><div style='font-size: 0.85rem; color: #888; font-weight: 600;'>{$exact_date}</div></td>
                    <td style='vertical-align: middle;'><div><b>".htmlspecialchars($inc['display_brgy'])."</b><br><small style='color:#d32f2f; font-weight:700;'>$coords</small><br><small style='color:#555;'>Rep: ".htmlspecialchars($reporter_display)."</small></div></td>
                    <td class='mobile-hide' style='vertical-align: middle;'>$incident_info</td>
                    <td class='mobile-hide' style='text-align:center; vertical-align: middle;'>$evidence_btn</td>
                    <td class='mobile-hide' style='text-align:center; vertical-align: middle;'><span class='badge $sev_badge' style='width:100%; justify-content:center;'>$display_sev</span><br>$status_html</td>
                    <td class='mobile-hide' style='vertical-align: middle; width: 150px; padding-right: 25px;'>$action_btns</td>
                </tr>";

                if ($inc['backup_requested'] == 1 && (!strpos($extraClass, 'cluster-row'))) {
                    $safe_type_backup = htmlspecialchars(addslashes($inc['incident_type']), ENT_QUOTES);
                    $backup_action = ($role === 'superadmin') ? "<button class='btn-sm' style='background:#1976d2; flex: 1;' onclick='event.stopPropagation(); openDeployModal(\"$dispatch_id\", \"$safe_type_backup\")'><i class='bx bxs-truck'></i> Deploy City Backup</button>" : "<span style='color:#f57c00; font-weight:bold; font-size: 0.9rem;'><i class='bx bx-time-five bx-spin'></i> Awaiting City Dispatch...</span>";

                    $rowHtml .= "
                    <tr class='$extraClass' style='background: rgba(245, 124, 0, 0.03); $extraStyle'>
                        <td style='border-left: 4px solid #f57c00; border-bottom: 2px solid #f1f4f8;'></td>
                        <td colspan='2' style='border-bottom: 2px solid #f1f4f8; padding-top: 5px; padding-bottom: 15px;'>
                            <div style='background: #251e11; border: 1px dashed #ffb74d; padding: 12px 15px; border-radius: 10px;'>
                                <strong style='color: #d32f2f; font-size: 0.9rem;'><i class='bx bxs-error-alt bx-flashing'></i> BACKUP REQUESTED</strong><br>
                                <span style='font-size: 0.85rem; color: #555; font-style: italic;'>Local responder needs additional units.</span>
                            </div>
                        </td>
                        <td style='border-bottom: 2px solid #f1f4f8;'><span class='badge' style='background: #f57c00;'>PENDING BACKUP</span></td>
                        <td colspan='2' style='border-bottom: 2px solid #f1f4f8; text-align: center;'>
                            <div style='display: flex; gap: 8px; justify-content: center;'>$backup_action</div>
                        </td>
                    </tr>";
                }
                return $rowHtml;
            };

            foreach ($clustered_data as $key => $group) {
                $count = count($group);
                if ($count > 1) {
                    $cluster_ids = array_map(function($i) { return $i['id']; }, $group);
                    $cluster_ids_str = implode(",", $cluster_ids);

                    $html .= $renderRow($group[0], $role, "parent-row-$key", "cursor: pointer; transition: 0.2s;", $cluster_ids_str, true, $count - 1, $key);
                    for ($i = 1; $i < $count; $i++) {
                        $html .= $renderRow($group[$i], $role, "cluster-row-$key", "display: none; background: #fafafa; border-left: 4px solid #1976d2;", null, false, 0, "");
                    }
                } else {
                    $html .= $renderRow($group[0], $role, "", "", null, false, 0, "");
                }
            }

        } else {
            $html = "<tr><td colspan='6' style='text-align:center; padding:40px; color:#888; font-weight:600;'>No active reports.</td></tr>";
        }
        $response['table'] = $html;
        ob_end_clean();
        echo json_encode($response);
        exit();
    }

    // 🚀 NEW: Server-Sent Events (SSE) Stream Sync
    if ($action === 'stream_sync') {
        requireRole($ADMIN_TIER_ROLES, $role);
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        
        session_write_close();

        if (!function_exists('getStrictBarangay')) {
            function getStrictBarangay($lat, $lng, $fallbackText) {
                global $conn;
                static $official_barangays = [];

                if (empty($official_barangays)) {
                    $res = $conn->query("SELECT name FROM barangays WHERE status = 'active'");
                    if ($res) {
                        while ($row = $res->fetch_assoc()) {
                            $official_barangays[] = $row['name'];
                        }
                    }
                }

                $cleanText = trim(str_ireplace([', Dasmariñas', ', Cavite', 'Philippines'], '', $fallbackText));
                $aliases = ['manuelaville' => 'San Agustin II', '6XWG+X37' => 'Biga I', 'the courtyards' => 'Salawag', 'orchard' => 'Salawag'];
                
                foreach ($aliases as $alias => $real_brgy) { 
                    if (stripos($cleanText, $alias) !== false) return $real_brgy; 
                }
                foreach ($official_barangays as $brgy) { 
                    if (strcasecmp($cleanText, $brgy) === 0) return $brgy; 
                }
                foreach ($official_barangays as $brgy) {
                    if (stripos($cleanText, $brgy) !== false) {
                        $nextChar = substr($cleanText, stripos($cleanText, $brgy) + strlen($brgy), 1);
                        if (strtoupper($nextChar) === 'I') continue; 
                        return $brgy;
                    }
                }
                return $cleanText;
            }
        }

        if (!function_exists('getDistanceMeters')) {
            function getDistanceMeters($lat1, $lon1, $lat2, $lon2) {
                $dLat = deg2rad($lat2 - $lat1); $dLon = deg2rad($lon2 - $lon1);
                $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
                return 6371000 * (2 * asin(sqrt($a)));
            }
        }

        if (!function_exists('executeSyncQuery')) {
            function executeSyncQuery($conn, $sql, $types, $params) {
                $stmt = $conn->prepare($sql);
                if (!empty($params)) { $stmt->bind_param($types, ...$params); }
                $stmt->execute();
                $res = $stmt->get_result();
                $stmt->close();
                return $res;
            }
        }

        $renderRow = function($inc, $role, $extraClass, $extraStyle, $targetIds = null, $isParent = false, $duplicateCount = 0, $clusterKey = '') {
            $status = $inc['status'] ?? 'active';
            $coords = $inc['latitude'] . ", " . $inc['longitude'];
            $exact_time = date('h:i A', strtotime($inc['created_at']));
            $exact_date = date('M d, Y', strtotime($inc['created_at']));
            $user_logs = $inc['user_logs'] ?? 'No additional details provided by the reporter.';
            $reporter_display = !empty(trim(($inc['first_name'] ?? '') . ' ' . ($inc['last_name'] ?? ''))) ? trim(($inc['first_name'] ?? '') . ' ' . ($inc['last_name'] ?? '')) : ($inc['username'] ?? 'Anonymous');
            $extra_info = (!empty($inc['reporter_pos']) ? $inc['reporter_pos'] . " | " : "") . ($inc['reporter_phone'] ?? "No Contact");

            // PATCH VULN-A14: see comment on the equivalent block in the master_sync renderRow above.
            $safe_img = htmlspecialchars(addslashes($inc['image_path'] ?? ''), ENT_QUOTES); $safe_type = htmlspecialchars(addslashes($inc['incident_type']), ENT_QUOTES); $safe_brgy = htmlspecialchars(addslashes($inc['display_brgy']), ENT_QUOTES); $safe_rep = htmlspecialchars(addslashes($reporter_display), ENT_QUOTES); $safe_logs = htmlspecialchars(addslashes(preg_replace('/\s+/', ' ', $user_logs)), ENT_QUOTES); $safe_extra = htmlspecialchars(addslashes($extra_info), ENT_QUOTES); $safe_backup = (int)$inc['backup_requested'];
            $dispatch_id = $targetIds ?: $inc['id'];

            $btn_color = (!empty($safe_img) && $safe_img !== 'NULL') ? '#424242' : '#999999';
            $btn_icon = (!empty($safe_img) && $safe_img !== 'NULL') ? 'bx-camera' : 'bx-info-circle';
            $evidence_btn = "<button class='btn-sm' style='background:$btn_color; margin: 0 auto;' onclick='event.stopPropagation(); viewEvidence(\"$safe_img\", \"$safe_type\", \"$safe_brgy\", \"$exact_date\", \"$exact_time\", \"$safe_rep\", \"$safe_logs\", \"$safe_extra\", $safe_backup)'><i class='bx $btn_icon'></i></button>";
                
            $sev_badge = ($inc['severity'] === 'Critical') ? 'critical' : (($inc['severity'] === 'Major') ? 'major' : 'warning');
            $status_html = "";
            if ($inc['backup_requested'] == 1) { $status_html .= "<span class='badge' style='background:#b10000; animation: blink 1s infinite; width:100%; justify-content:center; margin-top:5px;'>🚨 BACKUP NEEDED</span><style>@keyframes blink { 50% { opacity: 0; } }</style>"; }
            
            $status_lower = strtolower($status);
            if ($status_lower === 'on-scene') { $status_html .= "<span class='badge on-scene' style='margin-top:6px; font-size:9px; width:100%; justify-content:center;'><i class='bx bx-check-circle'></i> ON SCENE</span>"; } 
            elseif ($status_lower === 'dispatched') { $status_html .= "<span class='badge info' style='margin-top:6px; font-size:9px; width:100%; justify-content:center;'><i class='bx bxs-truck'></i> EN ROUTE</span>"; } 
            else { $status_html .= "<small style='font-size:10px; display:block; margin-top:6px; font-weight:800; color:#666;'>Status: ".strtoupper($status)."</small>"; }

            $action_btns = "<div class='action-btn-container' data-incident-ids='$dispatch_id' style='display:flex; flex-direction:column; gap:6px; align-items:center; justify-content:center;'>";
            if ($status_lower === 'active' || $status_lower === 'pending') {
                if ($role === 'superadmin') {
                    $action_btns .= ($inc['backup_requested'] == 0) ? "<span style='color:#f57c00; font-size:0.75rem; font-weight:bold; font-style:italic;'><i class='bx bx-radar bx-burst'></i> Awaiting Local</span>" : "<span style='color:#d32f2f; font-size:0.75rem; font-weight:bold; font-style:italic;'><i class='bx bxs-error bx-flashing'></i> Backup Needed</span>";
                    if ($inc['is_verified'] != 0) $action_btns .= "<div style='color: #666; font-size: 0.75rem; font-weight: 700;'>Verified by:<br><span style='color: #8e24aa;'>".htmlspecialchars($inc['verified_by'] ?? 'N/A')."</span></div>";
                } else {
                    if ($inc['is_verified'] == 0) {
                        $action_btns .= "<div class='verify-btn-wrapper' style='width:100%; position: relative;'><button class='btn-sm verify-btn' style='background:#8e24aa; width: 100%; justify-content: center;' onclick='event.stopPropagation(); toggleVerifyDropdown(this)'><i class='bx bx-check-shield'></i> Verify</button><div class='verify-dropdown' style='display:none; position: absolute; background: white; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); z-index: 100; width:150px; left:50%; transform:translateX(-50%); padding:5px;'><button class='btn-sm confirm-verify-btn' style='background:#388e3c; color: white !important; width: 100%; margin-bottom: 5px;' data-confirm-ids='$dispatch_id'>Confirm</button><button class='btn-sm cancel-verify-btn' style='background:#555555; color: white !important; width: 100%;' onclick='event.stopPropagation(); hideVerifyDropdown(this)'>Cancel</button></div></div>";
                        $action_btns .= "<button class='btn-sm reject-btn' style='background:#555555; width: 100%; justify-content: center;' onclick='event.stopPropagation(); rejectIncident(\"$dispatch_id\")'><i class='bx bx-x-circle'></i> Reject</button>";
                    } else {
                        $action_btns .= "<button class='btn-sm sev-btn' style='background:#8e24aa; width: 100%; justify-content: center;' onclick='event.stopPropagation(); openVerifyModal(\"$dispatch_id\")'><i class='bx bx-slider'></i> Change Severity</button>";
                        $action_btns .= "<button class='btn-sm dispatch-btn' style='background:#388e3c; width: 100%; justify-content: center;' onclick='event.stopPropagation(); openDeployModal(\"$dispatch_id\", \"$safe_type\")'><i class='bx bxs-truck'></i> Dispatch</button>";
                    }
                }
            } elseif (in_array($status_lower, ['dispatched', 'en route', 'en_route', 'on-scene'])) {
                $action_btns .= "<button class='btn-sm' style='background:#d32f2f; padding: 10px 15px; font-size: 0.9rem; width: 100%; justify-content: center;' onclick='event.stopPropagation(); cancelDispatch(\"$dispatch_id\")'><i class='bx bx-undo'></i> Recall</button>";
                if ($role !== 'superadmin' && $inc['backup_requested'] == 0) { $action_btns .= "<button class='btn-sm' style='background:#f57c00; padding: 10px 15px; width: 100%; justify-content: center;' onclick='event.stopPropagation(); requestBackup(\"$dispatch_id\")'><i class='bx bxs-error-circle'></i> Need Backup</button>"; }
            } else { $action_btns .= "<span style='color:#888; font-size:0.85rem; font-weight:bold; font-style:italic;'>No Actions</span>"; }
            $action_btns .= "</div>";

            if (strpos($extraClass, 'cluster-row') !== false) { $action_btns = "<span style='color: #888; font-size: 0.8rem; font-weight: bold; background: #eee; padding: 5px 10px; border-radius: 8px;'><i class='bx bx-link'></i> Merged</span>"; }
            
            $incident_info = "<span style='font-weight:700;'>".htmlspecialchars($inc['incident_type'])."</span><br><small style='color:#666; font-style:italic;'>\"".htmlspecialchars(substr($user_logs, 0, 45))."...\"</small>";
            if ($isParent && $duplicateCount > 0) { $incident_info .= "<div style='margin-top: 8px;'><span style='background: rgba(25,118,210,0.1); color: #1976d2; padding: 4px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 800; border: 1px solid rgba(255,255,255,0.3);'><i class='bx bx-folder-plus'></i> +$duplicateCount DUPLICATE</span></div>"; }

            $clusterCall = ($isParent && $duplicateCount > 0) ? "toggleCluster(\"$clusterKey\");" : "";
            $onclick = "onclick='openMobileModal(this); $clusterCall'";
            $hover = ($isParent && $duplicateCount > 0) ? "onmouseover='this.style.background=\"#e2e8f0\"' onmouseout='this.style.background=\"transparent\"'" : "";
            
            $display_sev = htmlspecialchars(strtoupper($inc['severity'] ?? 'PENDING'));

            $rowHtml = "<tr class='$extraClass' style='cursor: pointer; $extraStyle' $onclick $hover>
                <td style='vertical-align: middle;'><div style='font-weight: 800; font-size: 1.1rem; color: #d32f2f;'>{$exact_time}</div><div style='font-size: 0.85rem; color: #888; font-weight: 600;'>{$exact_date}</div></td>
                <td style='vertical-align: middle;'><div><b>".htmlspecialchars($inc['display_brgy'])."</b><br><small style='color:#d32f2f; font-weight:700;'>$coords</small><br><small style='color:#555;'>Rep: ".htmlspecialchars($reporter_display)."</small></div></td>
                <td class='mobile-hide' style='vertical-align: middle;'>$incident_info</td>
                <td class='mobile-hide' style='text-align:center; vertical-align: middle;'>$evidence_btn</td>
                <td class='mobile-hide' style='text-align:center; vertical-align: middle;'><span class='badge $sev_badge' style='width:100%; justify-content:center;'>$display_sev</span><br>$status_html</td>
                <td class='mobile-hide' style='vertical-align: middle; width: 150px; padding-right: 25px;'>$action_btns</td>
            </tr>";
            return $rowHtml;
        };

        while (true) {
            if (connection_aborted()) { break; }

            $response = ['kpi' => [], 'kpi_details' => [], 'map' => [], 'evac_centers' => [], 'table' => ''];
            $params = []; $types = ""; $evac_params = []; $evac_types = "";
            $brgy_filter = ""; $evac_brgy_filter = ""; $type_clause = "";

            $type = isset($_GET['type']) ? $_GET['type'] : 'all';
            if ($type !== 'all') { $type_clause = " AND i.incident_type LIKE ? "; $types .= "s"; $params[] = "%" . $type . "%"; }

            // 🚀 THE FIX: Enforce a strict City Geofence for the live stream
            $type_clause .= " AND (i.latitude BETWEEN 14.2500 AND 14.3900 AND i.longitude BETWEEN 120.8900 AND 121.0200) ";

            if ($role === 'superadmin') {
                if (!empty($_GET['brgy'])) {
                    $brgy_filter = " AND (i.barangay = ? OR i.barangay LIKE ?) "; $evac_brgy_filter = " AND (barangay = ? OR barangay LIKE ?) ";
                    $types .= "ss"; $params[] = $_GET['brgy']; $params[] = "%" . $_GET['brgy'] . "%";
                    $evac_types .= "ss"; $evac_params[] = $_GET['brgy']; $evac_params[] = "%" . $_GET['brgy'] . "%";
                }
            } elseif ($role === 'admin' || $role === 'barangay_admin') {
                $target_brgy = !empty($_GET['brgy']) ? $_GET['brgy'] : $admin_brgy;
                if (!empty($target_brgy)) {
                    $brgy_filter = " AND (i.barangay = ? OR i.barangay LIKE ?) "; $evac_brgy_filter = " AND (barangay = ? OR barangay LIKE ?) ";
                    $types .= "ss"; $params[] = $target_brgy; $params[] = "%" . $target_brgy . "%";
                    $evac_types .= "ss"; $evac_params[] = $target_brgy; $evac_params[] = "%" . $target_brgy . "%";
                }
            }

            $res1 = executeSyncQuery($conn, "SELECT COUNT(*) as c FROM incidents i WHERE i.status NOT IN ('archived', 'rejected') $brgy_filter $type_clause", $types, $params);
            $response['kpi']['active'] = $res1 ? (int)$res1->fetch_assoc()['c'] : 0;

            $res2 = executeSyncQuery($conn, "SELECT COUNT(*) as c FROM response_teams rt JOIN incidents i ON rt.current_incident_id = i.id WHERE rt.current_incident_id IS NOT NULL AND rt.current_incident_id > 0 $brgy_filter $type_clause", $types, $params);
            $response['kpi']['deployed'] = $res2 ? (int)$res2->fetch_assoc()['c'] : 0;
            
            $res3 = executeSyncQuery($conn, "SELECT SUM(current_occupants) as total FROM evacuation_centers WHERE 1=1 $evac_brgy_filter", $evac_types, $evac_params);
            $response['kpi']['evacuees'] = $res3 ? (int)($res3->fetch_assoc()['total'] ?? 0) : 0;

            $resMap = executeSyncQuery($conn, "SELECT id, incident_type, barangay, latitude, longitude, status, severity, backup_requested FROM incidents i WHERE i.status NOT IN ('archived', 'rejected') $brgy_filter $type_clause", $types, $params);
            if ($resMap) { while ($row = $resMap->fetch_assoc()) { $response['map'][] = $row; } }

            $resEvac = executeSyncQuery($conn, "SELECT id, name, barangay, latitude, longitude, capacity, current_occupants, status FROM evacuation_centers WHERE 1=1 $evac_brgy_filter", $evac_types, $evac_params);
            if ($resEvac) { while ($row = $resEvac->fetch_assoc()) { $response['evac_centers'][] = $row; } }

            $query = "SELECT i.*, i.backup_requested, i.is_verified, i.verified_by, u.username, u.first_name, u.last_name, u.barangay as reporter_home,
                            (SELECT position FROM user_profiles WHERE user_id = u.id LIMIT 1) as reporter_pos, 
                            (SELECT phone_number FROM user_profiles WHERE user_id = u.id LIMIT 1) as reporter_phone,
                            (SELECT log_message FROM incident_logs WHERE incident_id = i.id ORDER BY created_at ASC LIMIT 1) as user_logs
                    FROM incidents i LEFT JOIN users u ON i.reported_by = u.id 
                    WHERE i.status NOT IN ('archived', 'rejected') $brgy_filter $type_clause
                    ORDER BY CASE i.severity WHEN 'Critical' THEN 1 WHEN 'Major' THEN 2 WHEN 'Minor' THEN 3 WHEN 'Info' THEN 4 ELSE 5 END ASC, i.created_at DESC LIMIT 50"; 
            
            $resTab = executeSyncQuery($conn, $query, $types, $params);
            $html = "";
            
            if ($resTab && $resTab->num_rows > 0) {
                $clustered_data = [];
                while ($inc = $resTab->fetch_assoc()) {
                    $raw_brgy = trim((string)($inc['barangay'] ?? ''));
                    $inc['display_brgy'] = getStrictBarangay($inc['latitude'], $inc['longitude'], !empty($raw_brgy) ? $raw_brgy : ($inc['reporter_home'] ?? 'Unknown Location'));
                    $found_cluster = false;
                    foreach ($clustered_data as $key => $group) {
                        if (trim($group[0]['incident_type']) === trim($inc['incident_type'])) {
                            if (getDistanceMeters((float)$inc['latitude'], (float)$inc['longitude'], (float)$group[0]['latitude'], (float)$group[0]['longitude']) <= 100) {
                                $clustered_data[$key][] = $inc; $found_cluster = true; break;
                            }
                        }
                    }
                    if (!$found_cluster) { $clustered_data["cluster_" . $inc['id']] = [$inc]; }
                }

                foreach ($clustered_data as $key => $group) {
                    $count = count($group);
                    if ($count > 1) {
                        $html .= $renderRow($group[0], $role, "parent-row-$key", "cursor: pointer; transition: 0.2s;", implode(",", array_map(function($i){return $i['id'];}, $group)), true, $count - 1, $key);
                        for ($i = 1; $i < $count; $i++) { $html .= $renderRow($group[$i], $role, "cluster-row-$key", "display: none; background: #fafafa; border-left: 4px solid #1976d2;", null, false, 0, ""); }
                    } else {
                        $html .= $renderRow($group[0], $role, "", "", null, false, 0, "");
                    }
                }
            } else {
                $html = "<tr><td colspan='6' style='text-align:center; padding:40px; color:#888; font-weight:600;'>No active reports.</td></tr>";
            }
            $response['table'] = $html;

            echo "data: " . json_encode($response) . "\n\n";
            if (ob_get_level() > 0) { ob_flush(); }
            flush();
            
            sleep(2);
        }
    }

    // 🚀 SECURED: Get Available Teams
    if ($action === 'get_available_teams') {
        requireRole($ADMIN_TIER_ROLES, $role);
        ob_end_clean();
        header('Content-Type: application/json');
        
        $params = []; $types = "";
        $team_brgy_filter = "";
        
        if ($role === 'admin' || $role === 'barangay_admin') {
            $team_brgy_filter = " AND (
                TRIM(assigned_barangay) = ? 
                OR assigned_barangay LIKE ?
                OR LOWER(TRIM(assigned_barangay)) = 'city-wide' 
                OR assigned_barangay IS NULL 
                OR TRIM(assigned_barangay) = ''
            )";
            $types .= "ss";
            $params[] = $admin_brgy;
            $params[] = "%" . $admin_brgy . "%";
        } 
        
        $query = "SELECT id, team_name, team_type, assigned_barangay 
                  FROM response_teams 
                  WHERE LOWER(TRIM(status)) = 'available' " . $team_brgy_filter;
                  
        $stmt = $conn->prepare($query);
        if (!empty($params)) { $stmt->bind_param($types, ...$params); }
        $stmt->execute();
        $res = $stmt->get_result();
        
        $inc_type = strtolower($_GET['incident_type'] ?? '');
        $recommended_teams = [];
        $other_teams = [];
        
        if ($res) { 
            while ($row = $res->fetch_assoc()) { 
                $t_type = strtolower($row['team_type']);
                $is_rec = false;

                if (strpos($inc_type, 'fire') !== false && strpos($t_type, 'fire') !== false) $is_rec = true;
                if (strpos($inc_type, 'medical') !== false && (strpos($t_type, 'medic') !== false || strpos($t_type, 'ambulance') !== false || strpos($t_type, 'rescue') !== false)) $is_rec = true;
                if (strpos($inc_type, 'rescue') !== false && strpos($t_type, 'rescue') !== false) $is_rec = true;
                if (strpos($inc_type, 'crime') !== false && strpos($t_type, 'police') !== false) $is_rec = true;

                $row['is_recommended'] = $is_rec;

                if ($is_rec) {
                    $recommended_teams[] = $row;
                } else {
                    $other_teams[] = $row;
                }
            } 
        }
        $stmt->close();
        echo json_encode(array_merge($recommended_teams, $other_teams));
        exit();
    }

    // 🚀 SECURED: Get Team Members
    if ($action === 'get_team_members') {
        requireRole($ADMIN_TIER_ROLES, $role);
        if (ob_get_length()) ob_end_clean();
        header('Content-Type: application/json');
        $team_id = (int)($_GET['team_id'] ?? 0);
        $members = [];
        
        $teamStmt = $conn->prepare("SELECT team_name, assigned_barangay FROM response_teams WHERE id = ?");
        $teamStmt->bind_param("i", $team_id);
        $teamStmt->execute();
        $teamData = $teamStmt->get_result()->fetch_assoc();
        $teamStmt->close();

        if ($teamData) {
            $team_name = $teamData['team_name'];
            $assigned_barangay = $teamData['assigned_barangay'] ?? '';

            $stmt = $conn->prepare("
                SELECT u.id, u.first_name, u.last_name, p.radio_callsign, IFNULL(u.is_online, 0) as is_online
                FROM users u 
                LEFT JOIN user_profiles p ON u.id = p.user_id 
                WHERE u.role = 'responder'
                AND u.department IS NOT NULL 
                AND u.department != ''
                AND (
                    u.department = ? 
                    OR (? LIKE CONCAT('%', u.department, '%') AND u.barangay = ?)
                )
                GROUP BY u.id
            ");
            if ($stmt) {
                $stmt->bind_param("sss", $team_name, $team_name, $assigned_barangay);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) { 
                    $members[] = $row; 
                }
                $stmt->close();
            }
        }
        echo json_encode($members);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 🚀 SECURED: Update Team Status
    if ($action === 'update_team_status') {
        requireRole($ADMIN_TIER_ROLES, $role);
        $id = (int)$_POST['id'];
        $status = $_POST['status'];
        if ($status === 'available' || $status === 'maintenance') {
            $stmt = $conn->prepare("UPDATE response_teams SET status = ?, current_incident_id = NULL WHERE id = ?");
        } else {
            $stmt = $conn->prepare("UPDATE response_teams SET status = ? WHERE id = ?");
        }
        $stmt->bind_param("si", $status, $id);
        if ($stmt->execute()) { ob_end_clean(); echo "success"; } else { ob_end_clean(); echo "error"; }
        $stmt->close();
        exit();
    }

    // 🚀 SECURED: Add Team
    if ($action === 'add_team') {
        requireRole($ADMIN_TIER_ROLES, $role);
        $name = $_POST['team_name'] ?? '';
        $type = $_POST['team_type'] ?? '';
        $assigned_brgy = $_POST['assigned_barangay'] ?? ''; 
        
        $stmt = $conn->prepare("INSERT INTO response_teams (team_name, team_type, assigned_barangay, status) VALUES (?, ?, ?, 'available')");
        $stmt->bind_param("sss", $name, $type, $assigned_brgy);
        if ($stmt->execute()) { ob_end_clean(); echo "success"; } else { ob_end_clean(); echo "error"; }
        $stmt->close();
        exit();
    }

    // 🚀 SECURED: Send Broadcast
    if ($action === 'send_broadcast') {
        requireRole(['superadmin'], $role);
        $title = $_POST['title'] ?? '';
        $message = $_POST['message'] ?? '';
        $severity = $_POST['severity'] ?? 'info';
        if (!empty($title) && !empty($message)) {
            $stmt = $conn->prepare("INSERT INTO broadcasts (title, message, severity, is_active) VALUES (?, ?, ?, 1)");
            $stmt->bind_param("sss", $title, $message, $severity);
            if ($stmt->execute()) { ob_end_clean(); echo json_encode(['success' => true]); } 
            else { ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Database error: ' . $stmt->error]); }
            $stmt->close();
        } else {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Missing fields']);
        }
        exit();
    }

    // 🚀 SECURED: Verify Single Incident
    if ($action === 'verify_incident_single') {
        requireRole($ADMIN_TIER_ROLES, $role);
        $id = (int)$_POST['id']; 
        $stmt = $conn->prepare("UPDATE incidents SET is_verified = 1, verified_by = ? WHERE id = ?"); 
        $stmt->bind_param("si", $user_id, $id); 
        $stmt->execute(); 
        $stmt->close(); 
        ob_end_clean(); echo "success"; exit();
    }

    // 🚀 SECURED MULTI-ID SYNC: Deploy Team
    if ($action === 'deploy_team') {
        requireRole($ADMIN_TIER_ROLES, $role);
        $ids_raw = $_POST['incident_id'] ?? '';
        $ids_array = array_filter(array_map('intval', explode(',', $ids_raw))); // Safe Int Cast
        $team_ids = json_decode($_POST['team_ids'], true);
        $new_team_names = $_POST['team_names'] ?? ''; 
        
        if (!empty($ids_array) && is_array($team_ids) && !empty($team_ids)) {
            $id_list = implode(',', $ids_array);
            $primary_id = $ids_array[0]; 

            $stmt_chk = $conn->prepare("SELECT assigned_to FROM incidents WHERE id = ?");
            $stmt_chk->bind_param("i", $primary_id);
            $stmt_chk->execute();
            $existing_assigned = $stmt_chk->get_result()->fetch_assoc()['assigned_to'] ?? '';
            $stmt_chk->close();
            
            if (!empty($existing_assigned) && $existing_assigned !== 'NULL') {
                $merged_teams = $existing_assigned . ", " . $new_team_names;
            } else {
                $merged_teams = $new_team_names;
            }

            $stmt = $conn->prepare("UPDATE incidents SET status = 'dispatched', assigned_to = ?, backup_requested = 0 WHERE id IN ($id_list)");
            $stmt->bind_param("s", $merged_teams);
            $stmt->execute();
            $stmt->close();
            
            $stmt_team = $conn->prepare("UPDATE response_teams SET current_incident_id = ?, status = 'deployed' WHERE id = ?");
            foreach ($team_ids as $tid) {
                $team_id = (int)$tid;
                $stmt_team->bind_param("ii", $primary_id, $team_id);
                $stmt_team->execute();
            }
            $stmt_team->close();
            
            ob_end_clean(); echo json_encode(['success' => true]);
        } else {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'No teams selected']);
        }
        exit();
    }

    // 🚀 MULTI-ID SYNC: Mark On-Scene
    if ($action === 'mark_on_scene') {
        requireRole($ADMIN_TIER_ROLES, $role);
        $ids_raw = $_POST['id'] ?? '';
        $ids_array = array_filter(array_map('intval', explode(',', $ids_raw))); // Safe Int Cast
        if (!empty($ids_array)) {
            $id_list = implode(',', $ids_array);
            $conn->query("UPDATE incidents SET status = 'on-scene' WHERE id IN ($id_list)");
            $conn->query("UPDATE response_teams SET status = 'on-scene' WHERE current_incident_id IN ($id_list)");
        }
        ob_end_clean(); echo "success"; exit();
    }

    // 🚀 MULTI-ID SYNC: Archive
    if ($action === 'archive') {
        requireRole($ADMIN_TIER_ROLES, $role);
        $ids_raw = $_POST['id'] ?? '';
        $ids_array = array_filter(array_map('intval', explode(',', $ids_raw))); // Safe Int Cast
        if (!empty($ids_array)) {
            $id_list = implode(',', $ids_array);
            $conn->query("UPDATE incidents SET status = 'archived' WHERE id IN ($id_list)");
            $conn->query("UPDATE response_teams SET current_incident_id = NULL, status = 'available' WHERE current_incident_id IN ($id_list)");
        }
        ob_end_clean(); echo "success"; exit();
    }

    // 🚀 MULTI-ID SYNC: Recall / Cancel Dispatch
    if ($action === 'cancel_dispatch') {
        requireRole($ADMIN_TIER_ROLES, $role);
        $ids_raw = $_POST['id'] ?? '';
        $ids_array = array_filter(array_map('intval', explode(',', $ids_raw))); // Safe Int Cast
        if (!empty($ids_array)) {
            $id_list = implode(',', $ids_array);
            $conn->query("UPDATE response_teams SET current_incident_id = NULL, status = 'available' WHERE current_incident_id IN ($id_list)");
            $conn->query("UPDATE incidents SET status = 'active', assigned_to = NULL WHERE id IN ($id_list)");
        }
        ob_end_clean(); echo "success"; exit();
    }

    // 🚀 SECURED: Add Log
    if ($action === 'add_log') {
        requireRole($ADMIN_TIER_ROLES, $role);
        $incident_id = (int)$_POST['incident_id'];
        $message = $_POST['message'];
        $stmt = $conn->prepare("INSERT INTO incident_logs (incident_id, user_id, log_message) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $incident_id, $user_id, $message);
        $stmt->execute(); $stmt->close();
        ob_end_clean(); echo "success"; exit();
    }

    // 🚀 SECURED: Save Preferences
    if ($action === 'save_preferences') {
        $theme       = $_POST['theme'] ?? 'light';
        $font_size   = $_POST['font_size'] ?? '16px';
        $sound_alert = isset($_POST['sound_alert']) ? (int)$_POST['sound_alert'] : 1;
        
        // 1. Auto-Repair: Ensure columns exist
        $check = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'sound_alert'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE user_profiles ADD COLUMN sound_alert TINYINT(1) DEFAULT 1");
        }
        $check_t = $conn->query("SHOW COLUMNS FROM user_profiles LIKE 'theme'");
        if ($check_t && $check_t->num_rows === 0) {
            $conn->query("ALTER TABLE user_profiles ADD COLUMN theme VARCHAR(20) DEFAULT 'light'");
            $conn->query("ALTER TABLE user_profiles ADD COLUMN font_size VARCHAR(20) DEFAULT '16px'");
        }
        
        // 2. Safely Update/Insert Profile
        $check_prof = $conn->query("SELECT user_id FROM user_profiles WHERE user_id = " . (int)$user_id);
        
        if ($check_prof && $check_prof->num_rows > 0) {
            $stmt = $conn->prepare("UPDATE user_profiles SET theme = ?, font_size = ?, sound_alert = ? WHERE user_id = ?");
            $stmt->bind_param("ssii", $theme, $font_size, $sound_alert, $user_id);
        } else {
            $stmt = $conn->prepare("INSERT INTO user_profiles (user_id, theme, font_size, sound_alert) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("issi", $user_id, $theme, $font_size, $sound_alert);
        }
        
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['sound_alert'] = $sound_alert;
        
        ob_end_clean(); 
        echo json_encode(['success' => true]);
        exit();
    }

    // 🚀 SECURED: Update Admin Account
    if ($action === 'update_admin_account') {
        ob_end_clean();
        header('Content-Type: application/json');

        $user_id      = $_SESSION['user_id'] ?? 0;
        $first_name   = $_POST['first_name'] ?? '';
        $last_name    = $_POST['last_name'] ?? '';
        $phone_number = $_POST['phone_number'] ?? '';
        $position     = $_POST['position'] ?? '';
        $radio_callsign = $_POST['radio_callsign'] ?? '';
        $department   = $_POST['department'] ?? '';
        $current_pwd  = $_POST['current_password'] ?? '';
        $new_pwd      = $_POST['new_password'] ?? '';

        // 1. Verify password & fetch existing user data
        $stmt = $conn->prepare("SELECT password, barangay FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($current_pwd, $user['password'])) {
            echo json_encode(['success' => false, 'message' => 'Incorrect current password!']);
            exit();
        }

        // 🔒 FOREIGN KEY SAFEGUARD: Validate barangay to prevent FK constraint crashes
        $barangay = !empty($barangay_raw) ? $barangay_raw : ($user['barangay'] ?? null);

        if (!empty($barangay)) {
            $chk_b = $conn->prepare("SELECT name FROM barangays WHERE name = ? LIMIT 1");
            $chk_b->bind_param("s", $barangay);
            $chk_b->execute();
            if ($chk_b->get_result()->num_rows === 0) {
                $barangay = null; // Set to NULL if name is not found in barangays table
            }
            $chk_b->close();
        }

        if (!empty($new_pwd)) {
    $hashed_pwd = password_hash($new_pwd, PASSWORD_DEFAULT);
    $stmt_u = $conn->prepare("UPDATE users SET first_name=?, last_name=?, barangay=?, department=?, password=? WHERE id=?");
    $stmt_u->bind_param("sssssi", $first_name, $last_name, $barangay, $department, $hashed_pwd, $user_id);
        } else {
    $stmt_u = $conn->prepare("UPDATE users SET first_name=?, last_name=?, barangay=?, department=? WHERE id=?");
    $stmt_u->bind_param("ssssi", $first_name, $last_name, $barangay, $department, $user_id);
        }
        $stmt_u->execute();
        $stmt_u->close();

        if (!empty($barangay)) { $_SESSION['barangay'] = $barangay; }
        $_SESSION['first_name'] = $first_name;
        $_SESSION['last_name']  = $last_name;

        // 3. Upsert user_profiles record
        $chk_prof = $conn->query("SELECT user_id FROM user_profiles WHERE user_id = " . (int)$user_id);
        if ($chk_prof && $chk_prof->num_rows === 0) {
            $stmt_ins = $conn->prepare("INSERT INTO user_profiles (user_id, phone_number, position, radio_callsign) VALUES (?, ?, ?, ?)");
            $stmt_ins->bind_param("isss", $user_id, $phone_number, $position, $radio_callsign);
            $stmt_ins->execute();
            $stmt_ins->close();
        } else {
            $stmt_p = $conn->prepare("UPDATE user_profiles SET phone_number=?, position=?, radio_callsign=? WHERE user_id=?");
            $stmt_p->bind_param("sssi", $phone_number, $position, $radio_callsign, $user_id);
            $stmt_p->execute();
            $stmt_p->close();
        }

        // 4. Handle File Upload (Space-free filename & reliable web-root pathing)
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $pp_allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $pp_ext_ok  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            $pp_fi   = finfo_open(FILEINFO_MIME_TYPE);
            $pp_mime = finfo_file($pp_fi, $_FILES['profile_picture']['tmp_name']);
            finfo_close($pp_fi);
            
            $pp_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));

            if (!in_array($pp_mime, $pp_allowed) || !in_array($pp_ext, $pp_ext_ok) || !@getimagesize($_FILES['profile_picture']['tmp_name'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid image format.']);
                exit();
            }

            $target_dir = __DIR__ . '/../uploads/';
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }

            $clean_filename = 'uploads/profile_' . (int)$user_id . '_' . time() . '.' . $pp_ext;
            $destination    = __DIR__ . '/../' . $clean_filename;

            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destination)) {
                $stmt_img = $conn->prepare("UPDATE user_profiles SET profile_photo=? WHERE user_id=?");
                $stmt_img->bind_param("si", $clean_filename, $user_id);
                $stmt_img->execute();
                $stmt_img->close();
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to save image file.']);
                exit();
            }
        }

        echo json_encode(['success' => true, 'message' => 'Profile completely updated!']);
        exit();
    }

    // 🚀 SECURED: Add Center
    if ($action === 'add_center') { 
        requireRole($ADMIN_TIER_ROLES, $role);
        $name = $_POST['name'] ?? '';
        $barangay = $_POST['barangay'] ?? '';
        $capacity = (int)($_POST['capacity'] ?? 0);
        $lat = (float)($_POST['latitude'] ?? 0.0);
        $lng = (float)($_POST['longitude'] ?? 0.0);
        
        $occupants = 0; $status = 'closed';
        $point_wkt = "POINT($lng $lat)";
        $stmt = $conn->prepare("INSERT INTO evacuation_centers (name, barangay, latitude, longitude, capacity, current_occupants, status, geo_point) VALUES (?, ?, ?, ?, ?, ?, ?, ST_PointFromText(?))");
        if ($stmt) {
            $stmt->bind_param("ssddiiss", $name, $barangay, $lat, $lng, $capacity, $occupants, $status, $point_wkt);
            if ($stmt->execute()) { ob_end_clean(); echo "success"; }
            else { ob_end_clean(); echo "error"; }
            $stmt->close();
        }
        exit();
    }
    
    // 🚀 SECURED: Update Evac Center
    if ($action === 'update_evac_center') {
        requireRole($ADMIN_TIER_ROLES, $role);
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $occupants = isset($_POST['occupants']) ? (int)$_POST['occupants'] : 0;
        $status = isset($_POST['status']) ? $_POST['status'] : 'closed';

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE evacuation_centers SET current_occupants = ?, status = ?, last_updated = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->bind_param("isi", $occupants, $status, $id);
            
            if ($stmt->execute()) { echo "success"; } else { echo "Database Error: " . $stmt->error; }
            $stmt->close();
        } else {
            echo "Invalid Facility ID.";
        }
        exit();
    }

    // 🚀 SECURED: Delete Center
    if ($action === 'delete_center') {
        requireRole($ADMIN_TIER_ROLES, $role);
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM evacuation_centers WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) { ob_end_clean(); echo "success"; }
            $stmt->close();
        }
        exit();
    }
    
    // 🚀 SECURED: End Broadcast
    if ($action === 'end_broadcast') {
        requireRole(['superadmin'], $role);
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("UPDATE broadcasts SET is_active = 0 WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) { ob_end_clean(); echo "success"; }
            $stmt->close();
        }
        exit();
    }

    // 🚀 SECURED: Delete Archived
    if ($action === 'delete_archived') {
        requireRole($ADMIN_TIER_ROLES, $role);
        $id = (int)$_POST['id'];
        $stmt = $conn->prepare("DELETE FROM incidents WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $id);
            if ($stmt->execute()) { ob_end_clean(); echo "success"; }
            $stmt->close();
        }
        exit();
    }

    // 🚀 SECURED: Update Role
    if ($action === 'update_role') {
        requireRole(['superadmin'], $role);
        $target_user   = (int)$_POST['user_id'];
        $new_role      = $_POST['role'] ?? '';
        $allowed_roles = ['admin', 'user', 'responder', 'superadmin'];
        
        if (!in_array($new_role, $allowed_roles)) {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Invalid role.']); exit();
        }
        if ($target_user === (int)$user_id) {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Cannot change your own role.']); exit();
        }
        
        $stmt = $conn->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->bind_param("si", $new_role, $target_user);
        
        if ($stmt->execute()) { 
            ob_end_clean(); echo json_encode(['success' => true]); 
        } else { 
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Database error updating role.']); 
        }
        $stmt->close();
        exit();
    }

    // 🚀 SECURED: Toggle User Status
    if ($action === 'toggle_user_status') {
        requireRole(['superadmin'], $role);
        $target_user = (int)$_POST['user_id'];
        $current_status = $_POST['current_status'];
        $new_status = ($current_status === 'Active') ? 'Suspended' : 'Active';
        $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $new_status, $target_user);
        if ($stmt->execute()) { ob_end_clean(); echo json_encode(['success' => true]); } 
        else { ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Database error updating status.']); }
        $stmt->close();
        exit();
    }

    // 🚀 SECURED: Delete User
    if ($action === 'delete_user') {
        requireRole(['superadmin'], $role);
        $target_user = (int)$_POST['user_id'];
        if ($target_user === (int)$user_id) {
            ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Cannot delete your own account.']); exit();
        }
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role != 'superadmin'");
        $stmt->bind_param("i", $target_user);
        if ($stmt->execute()) { ob_end_clean(); echo json_encode(['success' => true]); } 
        else { ob_end_clean(); echo json_encode(['success' => false, 'message' => 'Failed to delete user account.']); }
        $stmt->close();
        exit();
    }
    
    if ($action === 'get_active_count') {
        requireRole($ADMIN_TIER_ROLES, $role);
        ob_end_clean();
        header('Content-Type: application/json');
        $res = $conn->query("SELECT COUNT(*) as c FROM incidents WHERE status NOT IN ('archived', 'rejected') AND (latitude BETWEEN 14.2500 AND 14.3900 AND longitude BETWEEN 120.8900 AND 121.0200)");
        echo json_encode(['count' => $res->fetch_assoc()['c'] ?? 0]);
        exit();
    }
} 
?>
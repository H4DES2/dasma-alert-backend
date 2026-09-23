<?php
require_once '../php/config.php';

ini_set('max_execution_time', 180);
header('Content-Type: text/plain');

$TARGET_COUNT = 500;
$START_TIME = strtotime('2026-01-01 00:00:00');
$END_TIME   = strtotime('2026-09-23 13:42:00');

// 1. Fetch available barangays or fallback to official 75
$barangays = [];
$b_res = $conn->query("SELECT name FROM barangays ORDER BY name ASC");
if ($b_res && $b_res->num_rows > 0) {
    while ($r = $b_res->fetch_assoc()) {
        $barangays[] = trim($r['name']);
    }
}
if (empty($barangays)) {
    $barangays = [
        'Burol', 'Burol I', 'Burol II', 'Burol III', 'Fatima I', 'Fatima II', 'Fatima III',
        'Langkaan I', 'Langkaan II', 'Paliparan I', 'Paliparan II', 'Paliparan III',
        'Sabang', 'Salawag', 'Salitran I', 'Salitran II', 'Salitran III', 'Salitran IV',
        'Sampaloc I', 'Sampaloc II', 'Sampaloc III', 'Sampaloc IV', 'Sampaloc V',
        'San Agustin I', 'San Agustin II', 'San Agustin III', 'San Andres I', 'San Andres II',
        'San Antonio I', 'San Antonio II', 'San Esteban', 'San Francisco I', 'San Francisco II',
        'San Isidro Labrador I', 'San Isidro Labrador II', 'San Jose', 'San Juan', 'San Lorenzo Ruiz I',
        'San Lorenzo Ruiz II', 'San Luis I', 'San Luis II', 'San Manuel I', 'San Manuel II',
        'San Mateo', 'San Miguel', 'San Miguel II', 'San Nicolas I', 'San Nicolas II',
        'San Pedro I', 'San Pedro II', 'San Roque', 'San Simon', 'Santa Cristina I', 'Santa Cristina II',
        'Santa Cruz I', 'Santa Cruz II', 'Santa Fe', 'Santa Lucia', 'Santa Maria',
        'Santo Cristo', 'Santo Niño I', 'Santo Niño II', 'Zone I', 'Zone I-B', 'Zone II',
        'Zone III', 'Zone IV', 'Victoria Reyes', 'H-2', 'Datu Esmael', 'Emmanuel Bergado I',
        'Emmanuel Bergado II', 'San Dionisio', 'San Felipe I', 'San Felipe II'
    ];
}

// 2. Fetch reporter & officer IDs
$users = [];
$u_res = $conn->query("SELECT id, username, first_name FROM users LIMIT 30");
if ($u_res) {
    while ($u = $u_res->fetch_assoc()) { $users[] = $u; }
}
if (empty($users)) {
    $users = [['id' => 1, 'username' => 'admin', 'first_name' => 'Officer']];
}

$team_names = ['Bravo Rescue Unit', 'Alpha Medic 1', 'City Fire Rescue 1', 'Delta Patrol', 'Medic Alpha', 'Echo Response'];

$incident_catalog = [
    'Medical' => [
        'types' => [
            'Medical - Heart Attack / Cardiac Arrest',
            'Medical - Vehicular Accident Injury',
            'Medical - Heat Stroke / Exhaustion',
            'Medical - Unconscious / Fainted Person',
            'Medical - Severe Bleeding / Trauma',
            'Medical - Breathing Difficulty'
        ],
        'logs' => ['Patient collapsed on sidewalk', 'Vehicular collision victim needs immediate assistance', 'Elderly experiencing extreme shortness of breath', 'Unconscious individual found outside residence', 'Severe laceration sustained']
    ],
    'Hazard' => [
        'types' => [
            'Hazard - Downed Power Lines / Post',
            'Hazard - Fallen Tree Blocking Road',
            'Hazard - Open / Uncovered Manhole',
            'Hazard - Oil / Chemical Spill',
            'Hazard - Landslide / Soil Erosion',
            'Hazard - Other Hazard'
        ],
        'logs' => ['Live wire hanging across main street', 'Large acacia branch fell across road lane', 'Open drainage posing risk to motorists', 'Slippery chemical leakage on tarmac', 'Soil erosion near embankment']
    ],
    'Rescue' => [
        'types' => [
            'Rescue - Flood / Trapped in Water',
            'Rescue - Trapped in Vehicle (Extrication)',
            'Rescue - Collapsed Structure',
            'Rescue - Animal Rescue',
            'Rescue - Fell in Drainage / Manhole',
            'Rescue - Other Rescue'
        ],
        'logs' => ['Resident trapped due to sudden flash flood', 'Driver pinned inside vehicle cabin', 'Dog trapped inside drainage canal', 'Worker fell into deep canal construction', 'Structure roof caved in']
    ],
    'Crime' => [
        'types' => [
            'Crime - Robbery / Hold-up',
            'Crime - Physical Assault / Riot',
            'Crime - Theft / Pickpocket',
            'Crime - Domestic Violence',
            'Crime - Suspicious Person / Activity',
            'Crime - Vandalism / Property Damage'
        ],
        'logs' => ['Hold-up incident reported near convenience store', 'Street altercation involving several individuals', 'Suspicious individual loitering near vehicles', 'Snatching incident reported', 'Property disturbance in progress']
    ],
    'Fire' => [
        'types' => [
            'Fire - Residential / House Fire',
            'Fire - Commercial / Building Fire',
            'Fire - Grass / Brush Fire',
            'Fire - Electrical Post / Wire Fire',
            'Fire - Vehicle Fire',
            'Fire - LPG Leak / Explosion'
        ],
        'logs' => ['Thick black smoke coming from residential 2nd floor', 'Electrical transformer sparked and caught fire', 'Dry grass caught fire near vacant lot', 'Car engine smoking heavily after crash', 'LPG leak odor detected']
    ]
];

$severities = ['Critical', 'Major', 'Minor'];

echo "Inserting 500 incidents in bulk...\n";

$conn->begin_transaction();

try {
    $conn->query("DELETE FROM incident_logs WHERE incident_id IN (SELECT id FROM incidents WHERE status = 'archived')");
    $conn->query("DELETE FROM incidents WHERE status = 'archived'");
    for ($i = 0; $i < $TARGET_COUNT; $i++) {
        $created_ts = mt_rand($START_TIME, $END_TIME);
        $created_str = date('Y-m-d H:i:s', $created_ts);

        $cat_key = array_rand($incident_catalog);
        $cat = $incident_catalog[$cat_key];
        $type = $conn->real_escape_string($cat['types'][array_rand($cat['types'])]);
        $user_report_text = $conn->real_escape_string($cat['logs'][array_rand($cat['logs'])]);

        $barangay = $conn->real_escape_string($barangays[$i % count($barangays)]);
        $sev = $severities[mt_rand(0, count($severities) - 1)];

        $lat = 14.280000 + (mt_rand(0, 80000) / 1000000);
        $lng = 120.920000 + (mt_rand(0, 70000) / 1000000);

        $reporter_user = $users[array_rand($users)];
        $reporter_id = (int)$reporter_user['id'];

        $officer_user = $users[array_rand($users)];
        $officer_name = $conn->real_escape_string(!empty($officer_user['first_name']) ? $officer_user['first_name'] : $officer_user['username']);
        $officer_id = (int)$officer_user['id'];

        // 1. Insert single incident
        $sql_inc = "INSERT INTO incidents 
            (reported_by, incident_type, severity, barangay, latitude, longitude, status, is_verified, verified_by, backup_requested, created_at) 
            VALUES ($reporter_id, '$type', '$sev', '$barangay', $lat, $lng, 'archived', 1, '$officer_name', 0, '$created_str')";
        $conn->query($sql_inc);
        $inc_id = $conn->insert_id;

        // Timestamps for logs
        $v_str = date('Y-m-d H:i:s', $created_ts + mt_rand(60, 180));
        $a_str = date('Y-m-d H:i:s', $created_ts + mt_rand(240, 540));
        $r_str = date('Y-m-d H:i:s', $created_ts + mt_rand(900, 2400));
        $team  = $team_names[array_rand($team_names)];

        // 2. Insert all 4 timeline logs in one single SQL query per incident
        $sql_logs = "INSERT INTO incident_logs (incident_id, user_id, log_message, created_at) VALUES 
            ($inc_id, $reporter_id, 'REPORTER LOG: $user_report_text', '$created_str'),
            ($inc_id, $officer_id, 'Incident verified by $officer_name.', '$v_str'),
            ($inc_id, $officer_id, 'Unit arrived on scene.', '$a_str'),
            ($inc_id, $officer_id, 'Unit [$team] RESOLVED the incident. Remarks: Operation completed safely.', '$r_str')";
        $conn->query($sql_logs);
    }

    $conn->commit();
    echo "SUCCESS: 500 incidents and 2,000 logs inserted successfully!";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR: " . $e->getMessage();
}
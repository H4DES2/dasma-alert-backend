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

// 2. Fetch users
$users = [];
$u_res = $conn->query("SELECT id, username, first_name, last_name, barangay FROM users LIMIT 30");
if ($u_res) {
    while ($u = $u_res->fetch_assoc()) { $users[] = $u; }
}
if (empty($users)) {
    $users = [['id' => 1, 'username' => 'admin', 'first_name' => 'Drew', 'last_name' => 'Elona', 'barangay' => 'Burol']];
}

// 3. Incident types
$incident_types = [
    'Medical - Heart Attack / Cardiac Arrest',
    'Medical - Vehicular Accident Injury',
    'Medical - Heat Stroke / Exhaustion',
    'Medical - Unconscious / Fainted Person',
    'Medical - Other Medical Emergency',
    'Hazard - Downed Power Lines / Post',
    'Hazard - Fallen Tree Blocking Road',
    'Hazard - Open / Uncovered Manhole',
    'Hazard - Oil / Chemical Spill',
    'Rescue - Flood / Trapped in Water',
    'Rescue - Trapped in Vehicle (Extrication)',
    'Rescue - Animal Rescue',
    'Crime - Robbery / Hold-up',
    'Crime - Physical Assault / Riot',
    'Crime - Theft / Pickpocket',
    'Crime - Suspicious Person / Activity',
    'Fire - Residential / House Fire',
    'Fire - Grass / Brush Fire',
    'Fire - Electrical Post / Wire Fire',
    'Fire - LPG Leak / Explosion'
];

// 4. Rejection Categories & Realistic Notes for parsing
$rejection_templates = [
    [
        'category' => 'False Alarm',
        'citizen_logs' => [
            'Akala ko po may sunog, usok lang pala ng siga',
            'False alarm po naayos na ng kapitbahay',
            'Nagkamali lang po ng pindot pasensya na',
            'Narinig kong sumisigaw akala ko riot nagtatawanan lang pala',
            'Walang aksidente, nakatabi lang yung motor'
        ],
        'notes' => [
            'Verified on-site: Smoke caused by controlled backyard burning.',
            'Citizen confirmed accidental call.',
            'No emergency found upon verification.',
            'Dispute already settled by family members prior to arrival.',
            'Vehicle was only parked with hazard lights on.'
        ]
    ],
    [
        'category' => 'Prank / Spam',
        'citizen_logs' => [
            'trip trip lang po',
            'test test mic check',
            'asdfghjkl spam test',
            'wala lang gusto ko lang subukan yung app',
            'order po ng pizza delivery'
        ],
        'notes' => [
            'Intentional prank report. User flagged.',
            'Gibberish text submitted with random GPS coordinates.',
            'Non-emergency test report by citizen.',
            'Repeated spam submission without emergency basis.',
            'Troll submission.'
        ]
    ],
    [
        'category' => 'Incomplete Information',
        'citizen_logs' => [
            'tulong',
            'may aksidente dito bilis',
            'may nasusunog',
            'help emergency',
            'dito banda malapit sa tindahan'
        ],
        'notes' => [
            'No exact landmark provided and reporter cannot be reached.',
            'Unreachable contact number and coordinates outside road area.',
            'Insufficient location and caller hung up immediately.',
            'Incomplete description, reporter did not answer dispatch verification call.',
            'Missing essential location details.'
        ]
    ],
    [
        'category' => 'Other / Unspecified',
        'citizen_logs' => [
            'Nawawala po yung pusa ko',
            'Maingay po yung kapitbahay nagkakaraoke',
            'Brownout po sa area namin pakiayos',
            'Walang tubig sa gripo',
            'Naiwan yung susi sa loob ng bahay'
        ],
        'notes' => [
            'Non-emergency inquiry referred to Meralco hotline.',
            'Referred to local barangay tanod for noise ordinance complaint.',
            'Non-disaster concern referred to water utility.',
            'Civil concern outside emergency response jurisdiction.',
            'Referred to barangay peace and order unit.'
        ]
    ]
];

echo "Purging old rejected records and seeding 500 rejected incidents...\n";

$conn->begin_transaction();

try {
    // Clean old rejected incidents to ensure exact 500 count
    $conn->query("DELETE FROM spam_reports WHERE incident_id IN (SELECT id FROM incidents WHERE status IN ('rejected', 'spam', 'out_of_range'))");
    $conn->query("DELETE FROM incident_logs WHERE incident_id IN (SELECT id FROM incidents WHERE status IN ('rejected', 'spam', 'out_of_range'))");
    $conn->query("DELETE FROM incidents WHERE status IN ('rejected', 'spam', 'out_of_range')");

    for ($i = 0; $i < $TARGET_COUNT; $i++) {
        $created_ts = mt_rand($START_TIME, $END_TIME);
        $created_str = date('Y-m-d H:i:s', $created_ts);
        $rejected_ts = $created_ts + mt_rand(60, 300);
        $rejected_str = date('Y-m-d H:i:s', $rejected_ts);

        $type = $conn->real_escape_string($incident_types[array_rand($incident_types)]);
        $barangay = $conn->real_escape_string($barangays[$i % count($barangays)]);

        // Pick rejection category template
        $template = $rejection_templates[mt_rand(0, count($rejection_templates) - 1)];
        $category = $template['category'];
        $citizen_text = $conn->real_escape_string($template['citizen_logs'][array_rand($template['citizen_logs'])]);
        $custom_note  = $conn->real_escape_string($template['notes'][array_rand($template['notes'])]);

        $lat = 14.280000 + (mt_rand(0, 80000) / 1000000);
        $lng = 120.920000 + (mt_rand(0, 70000) / 1000000);

        $reporter_user = $users[array_rand($users)];
        $reporter_id = (int)$reporter_user['id'];

        $officer_user = $users[array_rand($users)];
        $officer_name = trim(($officer_user['first_name'] ?? '') . ' ' . ($officer_user['last_name'] ?? '')) ?: ($officer_user['username'] ?? 'Officer');
        $officer_brgy = !empty($officer_user['barangay']) ? "Brgy. " . $officer_user['barangay'] : "Command Center";
        $officer_name_clean = $conn->real_escape_string($officer_name);
        $officer_label = $conn->real_escape_string($officer_brgy);
        $officer_id = (int)$officer_user['id'];

        // Exact pattern parsed by analytics.php: "Rejected by {Officer} ({Brgy}) [{Category}]: {Notes}"
        $reject_summary = "Rejected by {$officer_name_clean} ({$officer_label}) [{$category}]: {$custom_note}";

        // 1. Insert Rejected Incident
        $sql_inc = "INSERT INTO incidents 
            (reported_by, incident_type, severity, barangay, latitude, longitude, status, is_verified, verified_by, admin_remarks, backup_requested, created_at) 
            VALUES ($reporter_id, '$type', 'Minor', '$barangay', $lat, $lng, 'rejected', 0, NULL, '$reject_summary', 0, '$created_str')";
        $conn->query($sql_inc);
        $inc_id = $conn->insert_id;

        // 2. Insert Spam Report entry
        $sql_spam = "INSERT INTO spam_reports (incident_id, reason, created_at) VALUES ($inc_id, '$reject_summary', '$rejected_str')";
        $conn->query($sql_spam);

        // 3. Insert Audit Timeline Logs
        $sql_logs = "INSERT INTO incident_logs (incident_id, user_id, log_message, created_at) VALUES 
            ($inc_id, $reporter_id, 'REPORTER LOG: $citizen_text', '$created_str'),
            ($inc_id, $officer_id, 'Incident rejected as $category by $officer_name_clean ($officer_label). Notes: $custom_note', '$rejected_str')";
        $conn->query($sql_logs);
    }

    $conn->commit();
    echo "SUCCESS: 500 rejected incidents, spam reports, and audit logs successfully inserted!\n";
} catch (Throwable $e) {
    $conn->rollback();
    echo "ERROR: " . $e->getMessage() . "\n";
}
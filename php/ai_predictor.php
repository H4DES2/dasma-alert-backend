<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------
// 1. RESOLVE GEMINI_API_KEY FIRST
// ---------------------------------------------------------
$geminiApiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');

if (empty($geminiApiKey)) {
    $envPath = __DIR__ . '/.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                if (trim($k) === 'GEMINI_API_KEY') {
                    $geminiApiKey = trim($v, " \t\n\r\0\x0B\"'");
                    break;
                }
            }
        }
    }
}

if (empty($geminiApiKey)) {
    echo json_encode([
        'success' => false,
        'message' => 'GEMINI_API_KEY is not defined in server environment or .env'
    ]);
    exit();
}

// ---------------------------------------------------------
// 2. AUTHORITATIVE MONTH RESOLUTION (IMMUNE TO OS CLOCK TAMPERING)
// ---------------------------------------------------------
function getAuthoritativeMonth(mysqli $conn): int {
    // 1. Check trusted public network date (immune to local Windows clock changes)
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
            return (int)date('n', $networkTime);
        }
    }

    // 2. Fallback: Anchor to the latest actual incident logged in the database
    $dbMax = $conn->query("SELECT MONTH(MAX(created_at)) AS m FROM incidents");
    if ($dbMax && $row = $dbMax->fetch_assoc()) {
        if (!empty($row['m'])) {
            return (int)$row['m'];
        }
    }

    // 3. Server fallback
    return (int)date('n');
}

// Strictly force the authoritative month — do not accept client $_GET['month']
$target_month = getAuthoritativeMonth($conn);
$target_brgy  = isset($_GET['barangay']) ? trim($_GET['barangay']) : '';

$whereClauses = [
    "barangay IS NOT NULL",
    "barangay != ''",
    "incident_type IS NOT NULL",
    "MONTH(created_at) = ?"
];
$params = [$target_month];
$types  = "i";

if (!empty($target_brgy)) {
    $whereClauses[] = "LOWER(TRIM(barangay)) = LOWER(?)";
    $params[] = $target_brgy;
    $types   .= "s";
}

$whereSql = implode(" AND ", $whereClauses);
// ---------------------------------------------------------
// 3. AUTO-DETECT ACTIVE GEMINI MODEL FOR YOUR API KEY
// ---------------------------------------------------------
$targetModel = getenv('GEMINI_MODEL') ?: ($_ENV['GEMINI_MODEL'] ?? '');

if (empty($targetModel)) {
    $modelsListUrl = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($geminiApiKey);
    $chModels = curl_init($modelsListUrl);
    curl_setopt($chModels, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chModels, CURLOPT_SSL_VERIFYPEER, false);
    $modelsRes = curl_exec($chModels);
    curl_close($chModels);

    $modelsData = json_decode($modelsRes, true);
    if (!empty($modelsData['models'])) {
        // Find first Flash or available model supporting generateContent
        foreach ($modelsData['models'] as $m) {
            $name = str_replace('models/', '', $m['name']);
            $methods = $m['supportedGenerationMethods'] ?? [];
            if (in_array('generateContent', $methods, true) && stripos($name, 'flash') !== false) {
                $targetModel = $name;
                break;
            }
        }
        // Fallback to any model that supports generateContent
        if (empty($targetModel)) {
            foreach ($modelsData['models'] as $m) {
                $name = str_replace('models/', '', $m['name']);
                $methods = $m['supportedGenerationMethods'] ?? [];
                if (in_array('generateContent', $methods, true)) {
                    $targetModel = $name;
                    break;
                }
            }
        }
    }
}

if (empty($targetModel)) {
    $targetModel = 'gemini-2.0-flash';
}
$query = "
    SELECT 
        TRIM(barangay) AS barangay,
        TRIM(incident_type) AS incident_type,
        MONTH(created_at) AS month,
        COUNT(*) AS occurrences,
        SUM(CASE WHEN LOWER(severity) = 'critical' THEN 1 ELSE 0 END) AS critical_count,
        ROUND(AVG(latitude), 6) AS lat,
        ROUND(AVG(longitude), 6) AS lng
    FROM incidents
    WHERE {$whereSql}
    GROUP BY TRIM(barangay), TRIM(incident_type), MONTH(created_at)
    ORDER BY occurrences DESC, critical_count DESC
    LIMIT 60
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$dbResult = $stmt->get_result();

$dataset = [];
while ($row = $dbResult->fetch_assoc()) {
    $dataset[] = [
        'barangay'       => $row['barangay'],
        'incident'       => $row['incident_type'],
        'month'          => (int)$row['month'],
        'occurrences'    => (int)$row['occurrences'],
        'critical_cases' => (int)$row['critical_count'],
        'lat'            => (float)$row['lat'],
        'lng'            => (float)$row['lng']
    ];
}
$stmt->close();

if (empty($dataset)) {
    echo json_encode([
        'success' => false,
        'message' => 'No historical records found for this month in the database.'
    ]);
    exit();
}
// ---------------------------------------------------------
// 4. BUILD PROMPT AND INVOKE API
// ---------------------------------------------------------
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];
$monthLabel = $monthNames[$target_month] ?? "Month {$target_month}";

$systemInstruction = "You are DasmaAlert's Senior Disaster Risk Reduction & Predictive AI for Dasmariñas City, Cavite. "
    . "Analyze the aggregated historical incident dataset provided. "
    . "Identify spatial clusters, recurrent incident patterns (e.g., flooding in rainy months, heat stroke, brush fires, power line hazards, road accidents), "
    . "and generate actionable hazard forecasts for administrators. "
    . "Output ONLY strict JSON conforming to this schema:\n"
    . "{\n"
    . '  "summary": "Brief executive assessment for ' . $monthLabel . ' in Dasmariñas",' . "\n"
    . '  "forecasts": [' . "\n"
    . '    {' . "\n"
    . '      "barangay": "Barangay Name",' . "\n"
    . '      "hazard_type": "Primary anticipated incident",' . "\n"
    . '      "risk_level": "CRITICAL" | "HIGH" | "MODERATE",' . "\n"
    . '      "pattern_confidence": "e.g., 85% - Recurrent Pattern",' . "\n"
    . '      "historical_basis": "Specific reason citing historical counts/patterns from the data",' . "\n"
    . '      "recommended_action": "Clear action for CDRRMO/Barangay responders (equipment pre-staging, advisories, patrols)",' . "\n"
    . '      "lat": float,' . "\n"
    . '      "lng": float' . "\n"
    . '    }' . "\n"
    . '  ]' . "\n"
    . "}";

$fullPrompt = $systemInstruction . "\n\nTarget Prediction Month: {$monthLabel} (Month {$target_month})\n\nHistorical Incident Clusters:\n" . json_encode($dataset);

$promptPayload = [
    "contents" => [
        [
            "parts" => [
                ["text" => $fullPrompt]
            ]
        ]
    ],
    "generationConfig" => [
        "temperature"      => 0.2,
        "responseMimeType" => "application/json"
    ]
];

$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($targetModel) . ":generateContent?key=" . urlencode($geminiApiKey);

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=UTF-8']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($promptPayload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$apiResponse = curl_exec($ch);
$httpStatus  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr     = curl_error($ch);
curl_close($ch);

if ($httpStatus !== 200 || !empty($curlErr)) {
    echo json_encode([
        'success'    => false,
        'http_code'  => $httpStatus,
        'model_used' => $targetModel,
        'error'      => 'Gemini API call failed',
        'curl_err'   => $curlErr,
        'details'    => json_decode($apiResponse, true) ?: $apiResponse
    ]);
    exit();
}

$decodedResult = json_decode($apiResponse, true);
$rawText = $decodedResult['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
$cleanJson = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($rawText));
$parsedPrediction = json_decode($cleanJson, true);

echo json_encode([
    'success'    => true,
    'model_used' => $targetModel,
    'month'      => $monthLabel,
    'prediction' => $parsedPrediction
]);
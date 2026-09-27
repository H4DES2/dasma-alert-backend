<?php
set_time_limit(0);
ini_set('memory_limit', '512M');

// Safe relative paths whether run from root or scripts_archive/
$config_paths = [
    __DIR__ . '/../php/config.php',
    __DIR__ . '/php/config.php',
    __DIR__ . '/config.php'
];

foreach ($config_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

if (!isset($conn)) {
    die("Database connection failed. Check config.php\n");
}

$json_paths = [
    __DIR__ . '/../dasmarinas_barangays.geojson',
    __DIR__ . '/dasmarinas_barangays.geojson'
];

$jsonFile = null;
foreach ($json_paths as $jp) {
    if (file_exists($jp)) {
        $jsonFile = $jp;
        break;
    }
}

if (!$jsonFile) {
    die("File dasmarinas_barangays.geojson not found in root or scripts_archive.\n");
}

$raw = file_get_contents($jsonFile);
$data = json_decode($raw, true);
$features = $data['features'] ?? [];

echo "Found " . count($features) . " features in GeoJSON.\n";

function normalizeName($name) {
    $name = preg_replace('/^(Barangay|Brgy\.?)\s+/i', '', $name);
    return strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
}

$dbBarangays = [];
$res = $conn->query("SELECT id, name FROM barangays");
while ($r = $res->fetch_assoc()) {
    $cleanKey = normalizeName($r['name']);
    $dbBarangays[$cleanKey] = [
        'id' => $r['id'],
        'real_name' => $r['name']
    ];
}
echo "Loaded " . count($dbBarangays) . " barangays from database.\n\n";

$stmt = $conn->prepare("UPDATE barangays SET boundary = ST_SRID(ST_GeomFromGeoJSON(?), 4326) WHERE id = ?");
if (!$stmt) {
    die("Database prepare error: " . $conn->error . "\n");
}

$updated = 0;
$unmatched = [];

foreach ($features as $f) {
    $props = $f['properties'] ?? [];
    
    // Check both standard HDX field variations
    $rawName = $props['ADM4_EN'] ?? $props['adm4_name'] ?? $props['adm4_ref_name'] ?? '';
    if (empty($rawName)) continue;

    $lookupKey = normalizeName($rawName);
    $geom = $f['geometry'] ?? null;
    if (!$geom) continue;

    if ($geom['type'] === 'Polygon') {
        $geom = [
            'type' => 'MultiPolygon',
            'coordinates' => [$geom['coordinates']]
        ];
    }

    $targetId = null;
    $targetName = '';

    if (isset($dbBarangays[$lookupKey])) {
        $targetId = $dbBarangays[$lookupKey]['id'];
        $targetName = $dbBarangays[$lookupKey]['real_name'];
    } else {
        foreach ($dbBarangays as $k => $info) {
            if ($k === $lookupKey || strpos($k, $lookupKey) !== false || strpos($lookupKey, $k) !== false) {
                $targetId = $info['id'];
                $targetName = $info['real_name'];
                break;
            }
        }
    }

    if ($targetId) {
        $geomJson = json_encode($geom);
        $stmt->bind_param("si", $geomJson, $targetId);
        if ($stmt->execute()) {
            echo "✓ Linked: '{$rawName}' -> DB: [{$targetName}] (ID: {$targetId})\n";
            $updated++;
        } else {
            echo "! SQL Error on {$targetName}: " . $stmt->error . "\n";
        }
    } else {
        $unmatched[] = $rawName;
    }
}

$stmt->close();
echo "\n====================================\n";
echo "Successfully updated boundaries for {$updated} barangays.\n";
if (!empty($unmatched)) {
    echo "Unmatched in DB (" . count($unmatched) . "): " . implode(', ', $unmatched) . "\n";
}
<?php
set_time_limit(0);
ini_set('memory_limit', '-1'); // Remove memory ceiling for CLI

$sourceFile = __DIR__ . '/phl_admin4.geojson';
$outputFile = __DIR__ . '/dasmarinas_barangays.geojson';

if (!file_exists($sourceFile)) {
    die("File phl_admin4.geojson not found.\n");
}

echo "Starting stream extraction for Dasmariñas...\n";

$inHandle = fopen($sourceFile, 'r');
if (!$inHandle) {
    die("Cannot open source file.\n");
}

$matched = [];
$chunkSize = 1048576; // Read in 1MB byte slices
$remainder = "";

while (!feof($inHandle)) {
    $chunk = fread($inHandle, $chunkSize);
    $data = $remainder . $chunk;
    
    // Split by feature boundaries
    $parts = explode('{"type":"Feature"', $data);
    $count = count($parts);
    
    // Hold the last incomplete fragment for next iteration
    $remainder = '{"type":"Feature"' . array_pop($parts);
    
    for ($i = 0; $i < $count - 1; $i++) {
        $featureStr = $parts[$i];
        if (empty(trim($featureStr))) continue;
        
        // Ensure valid opening bracket
        if ($featureStr[0] !== '{') {
            $featureStr = '{"type":"Feature"' . $featureStr;
        }
        
        // Fast string scan first before attempting json_decode
        if (stripos($featureStr, 'Dasmariñas') !== false || stripos($featureStr, 'Dasmarinas') !== false) {
            $cleanJson = rtrim(trim($featureStr), ",");
            $decoded = json_decode($cleanJson, true);
            if ($decoded && isset($decoded['properties'])) {
                $matched[] = $decoded;
                echo "Found: " . ($decoded['properties']['ADM4_EN'] ?? 'Barangay') . "\n";
            }
        }
    }
}

// Check final fragment
if (stripos($remainder, 'Dasmariñas') !== false || stripos($remainder, 'Dasmarinas') !== false) {
    $cleanJson = rtrim(trim($remainder), "]} \t\n\r\0\x0B");
    $decoded = json_decode($cleanJson, true);
    if ($decoded) {
        $matched[] = $decoded;
    }
}

fclose($inHandle);

$resultCollection = [
    "type" => "FeatureCollection",
    "features" => $matched
];

file_put_contents($outputFile, json_encode($resultCollection, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "\nCompleted! Extracted " . count($matched) . " barangays.\n";
echo "Saved to: dasmarinas_barangays.geojson\n";
<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");

$possiblePaths = [
    __DIR__ . '/../dasmarinas_barangays.geojson',
    __DIR__ . '/../../dasmarinas_barangays.geojson',
    $_SERVER['DOCUMENT_ROOT'] . '/dasmarinas_barangays.geojson',
    $_SERVER['DOCUMENT_ROOT'] . '/alert/dasmarinas_barangays.geojson'
];

foreach ($possiblePaths as $path) {
    if (file_exists($path)) {
        readfile($path);
        exit();
    }
}

http_response_code(404);
echo json_encode(["error" => "dasmarinas_barangays.geojson not found on server"]);
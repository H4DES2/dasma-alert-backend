<?php
require_once __DIR__ . '/php/config.php';

$conn->query("SET FOREIGN_KEY_CHECKS = 0");
$conn->query("UPDATE response_teams SET current_incident_id = NULL, status = 'available'");
$conn->query("TRUNCATE TABLE incident_logs");
$conn->query("TRUNCATE TABLE incidents");
$conn->query("SET FOREIGN_KEY_CHECKS = 1");

$res1 = $conn->query("SELECT COUNT(*) AS c FROM incidents")->fetch_assoc()['c'];
$res2 = $conn->query("SELECT COUNT(*) AS c FROM incident_logs")->fetch_assoc()['c'];

echo "Remaining incidents: " . $res1 . "\n";
echo "Remaining incident_logs: " . $res2 . "\n";
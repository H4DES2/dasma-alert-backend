<?php
session_start();
require_once '../php/config.php';
require_once '../php/auth.php';

if (!isset($auth) || !($auth instanceof Auth)) {
    $auth = new Auth($conn);
}
if (!$auth->isSuperAdmin()) {
    die("Unauthorized Access.");
}

$tables = [];
$result = $conn->query("SHOW TABLES");
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
}

$sqlScript  = "-- CDRRMO Command Center Database Backup\n";
$sqlScript .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
$sqlScript .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

foreach ($tables as $table) {
    // Structure
    $result = $conn->query("SHOW CREATE TABLE `$table`");
    $row = $result->fetch_row();
    $sqlScript .= "\n\nDROP TABLE IF EXISTS `$table`;\n";
    $sqlScript .= $row[1] . ";\n\n";

    // Data — PATCH VULN-A12: removed outer for($i) loop that was draining the result set
    $result = $conn->query("SELECT * FROM `$table`");
    if (!$result) continue;

    $columnCount = $result->field_count;

    while ($row = $result->fetch_row()) {
        $values = [];
        for ($j = 0; $j < $columnCount; $j++) {
            if ($row[$j] === null) {
                $values[] = 'NULL';
            } else {
                $values[] = '"' . $conn->real_escape_string($row[$j]) . '"';
            }
        }
        $sqlScript .= "INSERT INTO `$table` VALUES(" . implode(',', $values) . ");\n";
    }
    $sqlScript .= "\n";
}

$sqlScript .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

if (!empty($sqlScript)) {
    $backup_file_name = 'CDRRMO_Database_Backup_' . date('Y-m-d_H-i-s') . '.sql';
    header('Content-Type: application/octet-stream');
    header('Content-Transfer-Encoding: Binary');
    header('Content-Disposition: attachment; filename="' . $backup_file_name . '"');
    echo $sqlScript;
    exit;
} else {
    echo "Error generating backup.";
}

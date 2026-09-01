<?php
require_once '../php/config.php';

$queries = [
    "CREATE INDEX idx_user_profiles_user_id ON user_profiles(user_id)",
    "CREATE INDEX idx_incidents_status_brgy ON incidents(status, barangay)",
    "CREATE INDEX idx_response_teams_status ON response_teams(status)",
    "CREATE INDEX idx_evac_centers_status ON evacuation_centers(status)"
];

echo "<h2>Applying Indexes...</h2>";
foreach ($queries as $sql) {
    try {
        if ($conn->query($sql)) {
            echo "<p style='color: green;'>✓ Success: $sql</p>";
        } else {
            echo "<p style='color: orange;'>! Skipped/Notice: " . $conn->error . " ($sql)</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: orange;'>! Skipped: " . $e->getMessage() . "</p>";
    }
}
echo "<p><strong>Done! You can now delete this file.</strong></p>";
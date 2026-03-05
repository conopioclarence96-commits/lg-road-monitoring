<?php
require_once 'lgu_staff/includes/config.php';

if (!$conn) {
    die("Database connection failed");
}

echo "<h2>Checking Database Structure for Verification Functions</h2>";

// Check road_transportation_reports structure
echo "<h3>Road Transportation Reports Table:</h3>";
$stmt = $conn->prepare("DESCRIBE road_transportation_reports");
$stmt->execute();
$result = $stmt->get_result();

$transport_columns = [];
while ($row = $result->fetch_assoc()) {
    $transport_columns[] = $row['Field'];
    echo "- " . $row['Field'] . " (" . $row['Type'] . ")<br>";
}
$stmt->close();

// Check road_maintenance_reports structure  
echo "<h3>Road Maintenance Reports Table:</h3>";
$stmt = $conn->prepare("DESCRIBE road_maintenance_reports");
$stmt->execute();
$result = $stmt->get_result();

$maintenance_columns = [];
while ($row = $result->fetch_assoc()) {
    $maintenance_columns[] = $row['Field'];
    echo "- " . $row['Field'] . " (" . $row['Type'] . ")<br>";
}
$stmt->close();

// Check for missing columns that the functions expect
$expected_columns = ['report_id', 'title', 'description', 'attachments', 'latitude', 'longitude', 'created_date', 'due_date'];

echo "<h3>Missing Columns Analysis:</h3>";

foreach ($expected_columns as $col) {
    if (!in_array($col, $transport_columns)) {
        echo "⚠️ Missing in road_transportation_reports: $col<br>";
    }
    if (!in_array($col, $maintenance_columns)) {
        echo "⚠️ Missing in road_maintenance_reports: $col<br>";
    }
}

$conn->close();
?>

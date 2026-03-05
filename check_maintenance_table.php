<?php
require_once 'lgu_staff/includes/config.php';

if (!$conn) {
    die("Database connection failed");
}

echo "<h2>Road Maintenance Reports Table Structure</h2>";

// Get table structure
$stmt = $conn->prepare("DESCRIBE road_maintenance_reports");
$stmt->execute();
$result = $stmt->get_result();

echo "<h3>Columns in road_maintenance_reports table:</h3>";
echo "<table border='1'>";
echo "<tr><th>Column Name</th><th>Type</th><th>Null</th><th>Key</th></tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . $row['Field'] . "</td>";
    echo "<td>" . $row['Type'] . "</td>";
    echo "<td>" . $row['Null'] . "</td>";
    echo "<td>" . $row['Key'] . "</td>";
    echo "</tr>";
}
echo "</table>";

$stmt->close();

// Get sample data
$stmt = $conn->prepare("SELECT * FROM road_maintenance_reports LIMIT 3");
$stmt->execute();
$result = $stmt->get_result();

echo "<h3>Sample data:</h3>";
while ($row = $result->fetch_assoc()) {
    echo "<pre>" . json_encode($row, JSON_PRETTY_PRINT) . "</pre>";
}

$stmt->close();
$conn->close();
?>

<?php
require_once 'lgu_staff/includes/config.php';

if (!$conn) {
    die("Database connection failed");
}

echo "<h2>Database Structure</h2>";

// Get table structure
$stmt = $conn->prepare("DESCRIBE road_transportation_reports");
$stmt->execute();
$result = $stmt->get_result();

echo "<h3>Columns in road_transportation_reports table:</h3>";
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

// Check for any column that might contain image data
$stmt = $conn->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'road_transportation_reports' AND TABLE_SCHEMA = DATABASE()");
$stmt->execute();
$result = $stmt->get_result();

echo "<h3>All column names:</h3>";
while ($row = $result->fetch_assoc()) {
    echo "- " . $row['COLUMN_NAME'] . "<br>";
}

$stmt->close();
$conn->close();
?>

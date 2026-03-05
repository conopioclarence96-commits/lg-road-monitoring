<?php
require_once 'lgu_staff/includes/config.php';

if (!$conn) {
    die("Database connection failed");
}

echo "<h2>All Tables in Database</h2>";

// Get all tables
$stmt = $conn->prepare("SHOW TABLES");
$stmt->execute();
$result = $stmt->get_result();

echo "<ul>";
while ($row = $result->fetch_assoc()) {
    $table_name = array_values($row)[0];
    echo "<li><strong>" . $table_name . "</strong></li>";
    
    // Check if this table has image/attachment related columns
    $stmt2 = $conn->prepare("DESCRIBE `$table_name`");
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    
    $has_image = false;
    while ($col = $result2->fetch_assoc()) {
        if (stripos($col['Field'], 'image') !== false || 
            stripos($col['Field'], 'attachment') !== false || 
            stripos($col['Field'], 'file') !== false) {
            echo "  - Has column: " . $col['Field'] . " (" . $col['Type'] . ")<br>";
            $has_image = true;
        }
    }
    
    if ($has_image) {
        // Show sample data from this table
        $stmt3 = $conn->prepare("SELECT * FROM `$table_name` LIMIT 1");
        $stmt3->execute();
        $result3 = $stmt3->get_result();
        if ($row3 = $result3->fetch_assoc()) {
            echo "  - Sample data: " . json_encode($row3, JSON_PRETTY_PRINT) . "<br>";
        }
        $stmt3->close();
    }
    
    echo "</li>";
    $stmt2->close();
}
echo "</ul>";

$stmt->close();
$conn->close();
?>

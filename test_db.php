<?php
echo "Testing database connection...\n";
require_once 'lgu_staff/includes/config.php';

if ($conn) {
    echo "✓ Connection successful\n";
    
    // Check total reports
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports");
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_assoc()['count'];
    echo "Total reports in database: " . $count . "\n";
    $stmt->close();
    
    // Get latest 3 reports
    $stmt = $conn->prepare("SELECT id, title, status, reported_date FROM road_transportation_reports ORDER BY reported_date DESC LIMIT 3");
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo "\nLatest 3 reports:\n";
    while ($row = $result->fetch_assoc()) {
        echo "- ID: {$row['id']}, Title: {$row['title']}, Status: {$row['status']}, Date: {$row['reported_date']}\n";
    }
    $stmt->close();
    
    $conn->close();
} else {
    echo "✗ Connection failed\n";
}
?>

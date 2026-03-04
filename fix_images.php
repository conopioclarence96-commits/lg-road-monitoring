<?php
/**
 * Quick fix for missing images - update database to use existing files
 */
require_once 'lgu_staff/includes/config.php';

echo "<h2>Fixing Missing Images...</h2>";

if (!$conn) {
    die("Database connection failed");
}

// Get records with missing images
$stmt = $conn->prepare("SELECT id, attachments FROM road_transportation_reports WHERE attachments LIKE '%69a4a426c6ef5.jpg%' OR attachments LIKE '%69a407748f243.jpeg%'");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $attachments = json_decode($row['attachments'], true);
    
    if (strpos($row['attachments'], '69a4a426c6ef5.jpg') !== false) {
        // Update to use existing image
        $attachments[0]['filename'] = '699b4545a5c07.jpeg';
        $attachments[0]['file_path'] = 'uploads/report_images/699b4545a5c07.jpeg';
        echo "Updated record {$row['id']}: 69a4a426c6ef5.jpg → 699b4545a5c07.jpeg<br>";
    }
    
    if (strpos($row['attachments'], '69a407748f243.jpeg') !== false) {
        // Update to use existing image
        $attachments[0]['filename'] = '699ccf4de9be9.jpeg';
        $attachments[0]['file_path'] = 'uploads/report_images/699ccf4de9be9.jpeg';
        echo "Updated record {$row['id']}: 69a407748f243.jpeg → 699ccf4de9be9.jpeg<br>";
    }
    
    // Save updated attachments
    $new_json = json_encode($attachments);
    $update = $conn->prepare("UPDATE road_transportation_reports SET attachments = ? WHERE id = ?");
    $update->bind_param("si", $new_json, $row['id']);
    $update->execute();
    $update->close();
}

$stmt->close();
echo "<h3>✓ Fix completed!</h3>";
echo "<p><a href='index.php'>← Refresh main page to see images</a></p>";
?>

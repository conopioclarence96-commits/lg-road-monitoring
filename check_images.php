<?php
require_once 'lgu_staff/includes/config.php';
require_once 'lgu_staff/includes/functions.php';

$conn = new mysqli($host, $username, $password, $database);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$stmt = $conn->prepare("SELECT id, title, attachments FROM road_transportation_reports ORDER BY reported_date DESC LIMIT 3");
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    echo "ID: " . $row['id'] . ", Title: " . $row['title'] . "\n";
    echo "Attachments: " . $row['attachments'] . "\n";
    
    if (!empty($row['attachments'])) {
        $attachments = json_decode($row['attachments'], true);
        if (is_array($attachments)) {
            foreach ($attachments as $attachment) {
                if (isset($attachment['file_path'])) {
                    $path = $attachment['file_path'];
                    echo "Image path: " . $path . "\n";
                    echo "File exists: " . (file_exists($path) ? "YES" : "NO") . "\n";
                    if (file_exists($path)) {
                        echo "File size: " . filesize($path) . " bytes\n";
                    }
                }
            }
        }
    }
    echo "---\n";
}

$stmt->close();
$conn->close();
?>

<?php
// check_recent_reports.php - Check for reports with images
require_once '../config/database.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Get recent reports with image data
    $query = "
        SELECT report_id, location, images, created_at
        FROM damage_reports 
        ORDER BY created_at DESC 
        LIMIT 10
    ";
    
    $result = $conn->query($query);
    $reports = [];
    
    while ($row = $result->fetch_assoc()) {
        $images = [];
        if ($row['images']) {
            try {
                $images = json_decode($row['images'], true);
            } catch (Exception $e) {
                $images = [];
            }
        }
        
        $reports[] = [
            'report_id' => $row['report_id'],
            'location' => $row['location'],
            'images' => $images,
            'image_count' => count($images),
            'created_at' => $row['created_at']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'reports' => $reports,
        'total_reports' => count($reports),
        'reports_with_images' => count(array_filter($reports, fn($r) => $r['image_count'] > 0))
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

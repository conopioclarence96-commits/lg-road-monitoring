<?php
// Lightweight JSON search endpoint for the public navbar search bar.
// Searches public road reports (transportation + maintenance) by title,
// report id, location, or description. Returns a small capped result set.
require_once __DIR__ . '/../lgu_staff/includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim($_GET['q'] ?? '');
if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$results = [];
$like = '%' . $q . '%';

if ($conn) {
    $transport_sql = "SELECT id, report_id, title, description, location, status, 'report' AS type, 'transportation' AS source
                      FROM road_transportation_reports
                      WHERE title LIKE ? OR report_id LIKE ? OR location LIKE ? OR description LIKE ?
                      ORDER BY created_at DESC LIMIT 5";
    $stmt = $conn->prepare($transport_sql);
    $stmt->bind_param('ssss', $like, $like, $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $results[] = $row;
    }
    $stmt->close();

    $maintenance_sql = "SELECT id, report_id, title, description, location, status, 'report' AS type, 'maintenance' AS source
                        FROM road_maintenance_reports
                        WHERE title LIKE ? OR report_id LIKE ? OR location LIKE ? OR description LIKE ?
                        ORDER BY created_at DESC LIMIT 5";
    $stmt = $conn->prepare($maintenance_sql);
    $stmt->bind_param('ssss', $like, $like, $like, $like);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $results[] = $row;
    }
    $stmt->close();
}

echo json_encode($results);

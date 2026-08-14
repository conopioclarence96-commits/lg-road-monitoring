<?php
require_once __DIR__ . '/../lgu_staff/includes/config.php';
header('Content-Type: application/json; charset=utf-8');
$projectId = 32;
$projectCode = 'PRJ-028';
$nameLike = '%Test Road%';
$data = [];
try {
    $q1 = $conn->prepare("SELECT id, project_code, name, category, status, progress, budget, start_date, end_date FROM projects WHERE id = ? OR project_code = ? OR name LIKE ? LIMIT 1");
    $q1->bind_param('iss', $projectId, $projectCode, $nameLike);
    $q1->execute();
    $r1 = $q1->get_result()->fetch_assoc();
    $q1->close();
    $data['project_row'] = $r1 ?: null;

    $q2 = $conn->prepare("SELECT * FROM project_road_geometry WHERE project_id = ?");
    $q2->bind_param('i', $projectId);
    $q2->execute();
    $rows2 = $q2->get_result()->fetch_all(MYSQLI_ASSOC);
    $q2->close();
    $data['geometry_rows'] = $rows2;

    $q3 = $conn->prepare("SELECT epa.id, epa.project_id, epa.status, epa.engineer_id, u.full_name FROM engineer_project_assignments epa JOIN users u ON u.id = epa.engineer_id WHERE epa.project_id = ?");
    $q3->bind_param('i', $projectId);
    $q3->execute();
    $rows3 = $q3->get_result()->fetch_all(MYSQLI_ASSOC);
    $q3->close();
    $data['assignments'] = $rows3;

    echo json_encode(['success' => true, 'checked_project_id' => $projectId, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

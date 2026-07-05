<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!is_logged_in()) {
    json_error('Unauthorized', 401);
}

$user_id = $_SESSION['user_id'];
$report_id = intval($_POST['report_id'] ?? 0);
$report_type = sanitize_input($_POST['report_type'] ?? '');

if ($report_id <= 0 || empty($report_type)) {
    json_error('Invalid report data');
}

$table = ($report_type === 'transportation') ? 'road_transportation_reports' : 'road_maintenance_reports';

$report = fetch_one("SELECT id, image_path, attachments FROM {$table} WHERE id = ?", [$report_id], "i");
if (!$report) {
    json_error('Report not found', 404);
}

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    json_error('No photo uploaded or upload error');
}

$upload_dir = __DIR__ . '/../../uploads/report_images';
$result = handle_file_upload($_FILES['photo'], $upload_dir, ['jpg', 'jpeg', 'png', 'gif', 'webp']);

if (!$result['success']) {
    json_error($result['error']);
}

$relative_path = 'lgu_staff/uploads/report_images/' . $result['filename'];
$existing_attachments = [];
if (!empty($report['attachments'])) {
    $existing_attachments = json_decode($report['attachments'], true) ?: [];
}

$existing_attachments[] = [
    'type' => 'image',
    'filename' => $result['filename'],
    'original_name' => $_FILES['photo']['name'],
    'file_path' => $relative_path,
    'uploaded_at' => date('Y-m-d H:i:s'),
    'uploaded_by' => $user_id
];

$attachments_json = json_encode($existing_attachments);
$new_image_path = $report['image_path'] ?: $relative_path;

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("UPDATE {$table} SET image_path = ?, attachments = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("ssi", $new_image_path, $attachments_json, $report_id);
    $stmt->execute();
    $conn->commit();

    log_audit_action($user_id, "Uploaded photo to report", "Report ID: {$report_id}, File: {$result['filename']}");

    json_success([
        'photo_url' => $relative_path,
        'filename' => $result['filename'],
        'attachment_count' => count($existing_attachments)
    ], 'Photo uploaded successfully');
} catch (Exception $e) {
    $conn->rollback();
    json_error('Failed to save photo: ' . $e->getMessage());
}

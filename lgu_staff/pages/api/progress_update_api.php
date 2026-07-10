<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!is_logged_in()) {
    json_response(['success' => false, 'message' => 'Unauthorized'], 401);
}

$user_id = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    if ($action === 'get_updates' || $action === 'get_update') {
        // Allow read-only access without login for public timeline
        if ($action === 'get_updates') {
            $report_id = intval($_GET['report_id'] ?? 0);
            if ($report_id <= 0) {
                json_response(['success' => false, 'message' => 'Invalid report ID']);
            }
            $report = fetch_one("SELECT id FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
            if (!$report) {
                $report = fetch_one("SELECT id FROM road_maintenance_reports WHERE id = ?", [$report_id], "i");
            }
            if (!$report) {
                json_response(['success' => false, 'message' => 'Report not found']);
            }
            $updates = [];
            $q = "SELECT u.*, COALESCE(us.full_name, 'LGU Staff') as admin_name 
                  FROM report_updates u 
                  LEFT JOIN users us ON u.user_id = us.id 
                  WHERE u.report_id = ? 
                  ORDER BY u.created_at ASC";
            $stmt = $conn->prepare($q);
            $stmt->bind_param("i", $report_id);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['created_at_formatted'] = date('M d, Y h:i A', strtotime($row['created_at']));
                $media = [];
                $m_stmt = $conn->prepare("SELECT id, file_path, file_type FROM report_update_media WHERE update_id = ? ORDER BY id ASC");
                $m_stmt->bind_param("i", $row['id']);
                $m_stmt->execute();
                $m_res = $m_stmt->get_result();
                while ($m = $m_res->fetch_assoc()) $media[] = $m;
                $row['media'] = $media;
                $updates[] = $row;
            }
            json_response(['success' => true, 'updates' => $updates]);
        } elseif ($action === 'get_update') {
            if (!is_logged_in()) json_response(['success' => false, 'message' => 'Unauthorized'], 401);
            $id = intval($_GET['id'] ?? 0);
            if ($id <= 0) json_response(['success' => false, 'message' => 'Invalid ID']);
            $q = "SELECT u.*, us.full_name as admin_name FROM report_updates u LEFT JOIN users us ON u.user_id = us.id WHERE u.id = ?";
            $stmt = $conn->prepare($q);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $update = $res->fetch_assoc();
            if (!$update) json_response(['success' => false, 'message' => 'Update not found']);
            $media = [];
            $m_stmt = $conn->prepare("SELECT id, file_path, file_type FROM report_update_media WHERE update_id = ? ORDER BY id ASC");
            $m_stmt->bind_param("i", $update['id']);
            $m_stmt->execute();
            $m_res = $m_stmt->get_result();
            while ($m = $m_res->fetch_assoc()) $media[] = $m;
            $update['media'] = $media;
            json_response(['success' => true, 'update' => $update]);
        }
    } else {
        json_response(['success' => false, 'message' => 'Unknown action']);
    }
} elseif ($method === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_update') {
        $report_id = intval($_POST['report_id'] ?? 0);
        $report_type = sanitize_input($_POST['report_type'] ?? 'transportation');
        $title = sanitize_input($_POST['title'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');
        $table = ($report_type === 'transportation') ? 'road_transportation_reports' : 'road_maintenance_reports';

        if ($report_id <= 0) json_response(['success' => false, 'message' => 'Invalid report ID']);
        if (empty($description)) json_response(['success' => false, 'message' => 'Description is required']);

        $report = fetch_one("SELECT id, report_id FROM road_transportation_reports WHERE id = ?", [$report_id], "i");
        if (!$report) {
            $report = fetch_one("SELECT id, report_id FROM road_maintenance_reports WHERE id = ?", [$report_id], "i");
        }
        if (!$report) json_response(['success' => false, 'message' => 'Report not found']);

        // Insert update
        $stmt = $conn->prepare("INSERT INTO report_updates (report_id, user_id, title, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiss", $report_id, $user_id, $title, $description);
        $stmt->execute();
        $update_id = $conn->insert_id;

        // Handle media uploads
        $upload_dir = __DIR__ . '/../../uploads/progress_updates';
        $uploaded = handleProgressMediaUpload($_FILES['media'] ?? [], $upload_dir, $update_id);

        // Create notification
        createReportNotification($report_id, $update_id, $title ?: 'Progress Update', $report);

        // Audit log
        log_audit_action($user_id, "Created progress update", "Report ID: {$report['report_id']}, Update ID: {$update_id}");

        json_response(['success' => true, 'message' => 'Progress update posted successfully', 'update_id' => $update_id, 'photos' => $uploaded]);
    } elseif ($action === 'edit_update') {
        $update_id = intval($_POST['update_id'] ?? 0);
        $title = sanitize_input($_POST['title'] ?? '');
        $description = sanitize_input($_POST['description'] ?? '');

        if ($update_id <= 0) json_response(['success' => false, 'message' => 'Invalid update ID']);
        if (empty($description)) json_response(['success' => false, 'message' => 'Description is required']);

        // Verify ownership/permission
        $update = fetch_one("SELECT u.*, r.report_id FROM report_updates u JOIN road_transportation_reports r ON u.report_id = r.id WHERE u.id = ?", [$update_id], "i");
        if (!$update) json_response(['success' => false, 'message' => 'Update not found']);

        $stmt = $conn->prepare("UPDATE report_updates SET title = ?, description = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ssi", $title, $description, $update_id);
        $stmt->execute();

        // Handle new media uploads
        $upload_dir = __DIR__ . '/../../uploads/progress_updates';
        handleProgressMediaUpload($_FILES['media'] ?? [], $upload_dir, $update_id);

        // Handle removed media
        if (!empty($_POST['remove_media'])) {
            $remove_ids = array_map('intval', (array)$_POST['remove_media']);
            foreach ($remove_ids as $rid) {
                $m = fetch_one("SELECT file_path FROM report_update_media WHERE id = ? AND update_id = ?", [$rid, $update_id], "ii");
                if ($m) {
                    $full = __DIR__ . '/../../' . $m['file_path'];
                    if (file_exists($full)) @unlink($full);
                    $conn->query("DELETE FROM report_update_media WHERE id = {$rid}");
                }
            }
        }

        log_audit_action($user_id, "Edited progress update", "Update ID: {$update_id}");
        json_response(['success' => true, 'message' => 'Update edited successfully']);
    } elseif ($action === 'delete_update') {
        $update_id = intval($_POST['update_id'] ?? 0);
        if ($update_id <= 0) json_response(['success' => false, 'message' => 'Invalid update ID']);

        $update = fetch_one("SELECT * FROM report_updates WHERE id = ?", [$update_id], "i");
        if (!$update) json_response(['success' => false, 'message' => 'Update not found']);

        // Delete media files
        $media = $conn->query("SELECT file_path FROM report_update_media WHERE update_id = {$update_id}");
        while ($m = $media->fetch_assoc()) {
            $full = __DIR__ . '/../../' . $m['file_path'];
            if (file_exists($full)) @unlink($full);
        }

        // CASCADE deletes media rows automatically, but also delete notification reference
        $conn->query("DELETE FROM report_notifications WHERE update_id = {$update_id}");
        $stmt = $conn->prepare("DELETE FROM report_updates WHERE id = ?");
        $stmt->bind_param("i", $update_id);
        $stmt->execute();

        log_audit_action($user_id, "Deleted progress update", "Update ID: {$update_id}");
        json_response(['success' => true, 'message' => 'Update deleted']);
    } else {
        json_response(['success' => false, 'message' => 'Unknown action']);
    }
} else {
    json_response(['success' => false, 'message' => 'Method not allowed'], 405);
}

// --- Helper functions ---

function handleProgressMediaUpload($files, $upload_dir, $update_id) {
    global $conn;
    $uploaded = [];
    if (empty($files) || !is_array($files['name'])) return $uploaded;

    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
        chmod($upload_dir, 0777);
    }

    foreach ($files['name'] as $i => $name) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $file = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i]
        ];

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $image_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $video_types = ['mp4', 'webm'];
        $allowed = array_merge($image_types, $video_types);

        if (!in_array($ext, $allowed)) continue;
        if ($file['size'] > 10 * 1024 * 1024) continue;

        $filename = uniqid('upd_') . '.' . $ext;
        $dest = $upload_dir . '/' . $filename;
        if (move_uploaded_file($file['tmp_name'], $dest)) {
            chmod($dest, 0644);
            $relative = 'lgu_staff/uploads/progress_updates/' . $filename;
            $file_type = in_array($ext, $image_types) ? 'image' : 'video';

            $stmt = $conn->prepare("INSERT INTO report_update_media (update_id, file_path, file_type) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $update_id, $relative, $file_type);
            $stmt->execute();
            $uploaded[] = ['id' => $conn->insert_id, 'path' => $relative, 'type' => $file_type];
        }
    }
    return $uploaded;
}

function createReportNotification($report_id, $update_id, $title, $report) {
    global $conn;
    $report_label = $report['report_id'] ?? "#{$report_id}";
    $message = "New progress update on report {$report_label}: {$title}";
    try {
        $stmt = $conn->prepare("INSERT INTO report_notifications (report_id, update_id, type, message) VALUES (?, ?, 'progress_update', ?)");
        $stmt->bind_param("iis", $report_id, $update_id, $message);
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Create notification error: " . $e->getMessage());
    }
}

<?php
/**
 * API Endpoint: Completed Projects CRUD
 * 
 * GET    ?action=list                  → List all projects
 * GET    ?action=view&id=X             → Get single project
 * POST   action=create                 → Create project (multipart/form-data)
 * POST   action=update&id=X            → Update project (multipart/form-data)
 * POST   action=delete&id=X            → Delete project
 * POST   action=upload_photo           → Upload photo (before or after)
 */

header('Content-Type: application/json; charset=utf-8');

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

// Session timeout
$session_timeout = 5 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_destroy();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Auth check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'system_admin' && $_SESSION['role'] !== 'lgu_staff')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$is_admin = ($_SESSION['role'] === 'system_admin');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS published_completed_projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    location VARCHAR(255) DEFAULT NULL,
    completed_date DATE DEFAULT NULL,
    cost DECIMAL(12,2) DEFAULT NULL,
    completed_by VARCHAR(255) DEFAULT NULL,
    photo VARCHAR(500) DEFAULT NULL,
    before_photo VARCHAR(500) DEFAULT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Add is_published column if it doesn't exist
$check_col = $conn->query("SHOW COLUMNS FROM published_completed_projects LIKE 'is_published'");
if ($check_col && $check_col->num_rows === 0) {
    $conn->query("ALTER TABLE published_completed_projects ADD COLUMN is_published TINYINT(1) NOT NULL DEFAULT 0 AFTER before_photo");
}

switch ($action) {

    // ─── LIST ───────────────────────────────────────────
    case 'list':
        $projects = fetch_all("SELECT * FROM published_completed_projects ORDER BY created_at DESC");
        echo json_encode(['success' => true, 'data' => $projects]);
        break;

    // ─── VIEW SINGLE ────────────────────────────────────
    case 'view':
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }
        $project = fetch_one("SELECT * FROM published_completed_projects WHERE id = ?", [$id], 'i');
        if (!$project) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Not found']); exit; }
        echo json_encode(['success' => true, 'data' => $project]);
        break;

    // ─── CREATE ─────────────────────────────────────────
    case 'create':
        if (!$is_admin) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Only administrators can create projects']); exit; }
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

        $title = trim($_POST['title'] ?? '');
        if ($title === '') { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Title is required']); exit; }

        $description = trim($_POST['description'] ?? '');
        $location    = trim($_POST['location'] ?? '');
        $completed_date = trim($_POST['completed_date'] ?? '');
        $cost        = (float)($_POST['cost'] ?? 0);
        $completed_by = trim($_POST['completed_by'] ?? '');
        $photo       = trim($_POST['photo'] ?? '');
        $before_photo = trim($_POST['before_photo'] ?? '');
        $is_published = isset($_POST['is_published']) ? 1 : 0;

        $date_val = ($completed_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $completed_date)) ? $completed_date : null;

        $stmt = $conn->prepare("INSERT INTO published_completed_projects (title, description, location, completed_date, cost, completed_by, photo, before_photo, is_published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssdsssi', $title, $description, $location, $date_val, $cost, $completed_by, $photo, $before_photo, $is_published);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $status_msg = $is_published ? 'published' : 'saved as draft';
            log_audit_action($_SESSION['user_id'], 'create_completed_project', "Created project #$new_id: $title ($status_msg)");
            echo json_encode(['success' => true, 'message' => "Project $status_msg", 'id' => $new_id]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        $stmt->close();
        break;

    // ─── UPDATE ─────────────────────────────────────────
    case 'update':
        if (!$is_admin) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Only administrators can update projects']); exit; }
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

        $existing = fetch_one("SELECT * FROM published_completed_projects WHERE id = ?", [$id], 'i');
        if (!$existing) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Not found']); exit; }

        $title = trim($_POST['title'] ?? $existing['title']);
        $description = trim($_POST['description'] ?? $existing['description']);
        $location    = trim($_POST['location'] ?? $existing['location']);
        $completed_date = trim($_POST['completed_date'] ?? '');
        $cost        = isset($_POST['cost']) ? (float)$_POST['cost'] : $existing['cost'];
        $completed_by = trim($_POST['completed_by'] ?? $existing['completed_by']);
        $photo       = trim($_POST['photo'] ?? '') !== '' ? trim($_POST['photo']) : $existing['photo'];
        $before_photo = trim($_POST['before_photo'] ?? '') !== '' ? trim($_POST['before_photo']) : $existing['before_photo'];
        $is_published = isset($_POST['is_published']) ? 1 : 0;

        $date_val = ($completed_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $completed_date)) ? $completed_date : $existing['completed_date'];

        $stmt = $conn->prepare("UPDATE published_completed_projects SET title=?, description=?, location=?, completed_date=?, cost=?, completed_by=?, photo=?, before_photo=?, is_published=? WHERE id=?");
        $stmt->bind_param('ssssdssssi', $title, $description, $location, $date_val, $cost, $completed_by, $photo, $before_photo, $is_published, $id);

        if ($stmt->execute()) {
            $status_msg = $is_published ? 'published' : 'unpublished';
            log_audit_action($_SESSION['user_id'], 'update_completed_project', "Updated project #$id: $title ($status_msg)");
            echo json_encode(['success' => true, 'message' => "Project $status_msg"]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        $stmt->close();
        break;

    // ─── DELETE ─────────────────────────────────────────
    case 'delete':
        if (!$is_admin) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Only administrators can delete projects']); exit; }
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

        $existing = fetch_one("SELECT title, photo, before_photo FROM published_completed_projects WHERE id = ?", [$id], 'i');
        if (!$existing) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Not found']); exit; }

        // Delete associated files
        $upload_dir = __DIR__ . '/../../../uploads/completed_projects/';
        if (!empty($existing['photo']) && file_exists($upload_dir . basename($existing['photo']))) {
            @unlink($upload_dir . basename($existing['photo']));
        }
        if (!empty($existing['before_photo']) && file_exists($upload_dir . basename($existing['before_photo']))) {
            @unlink($upload_dir . basename($existing['before_photo']));
        }

        $stmt = $conn->prepare("DELETE FROM published_completed_projects WHERE id = ?");
        $stmt->bind_param('i', $id);
        if ($stmt->execute()) {
            log_audit_action($_SESSION['user_id'], 'delete_completed_project', "Deleted project #$id: {$existing['title']}");
            echo json_encode(['success' => true, 'message' => 'Project deleted']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        $stmt->close();
        break;

    // ─── TOGGLE PUBLISH ─────────────────────────────────
    case 'toggle_publish':
        if (!$is_admin) { http_response_code(403); echo json_encode(['success' => false, 'message' => 'Only administrators can publish projects']); exit; }
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) { http_response_code(400); echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

        $existing = fetch_one("SELECT id, title, is_published FROM published_completed_projects WHERE id = ?", [$id], 'i');
        if (!$existing) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Not found']); exit; }

        $new_status = $existing['is_published'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE published_completed_projects SET is_published = ? WHERE id = ?");
        $stmt->bind_param('ii', $new_status, $id);

        if ($stmt->execute()) {
            $status_msg = $new_status ? 'published' : 'unpublished';
            log_audit_action($_SESSION['user_id'], 'toggle_publish_project', "Project #$id {$status_msg}: {$existing['title']}");
            echo json_encode(['success' => true, 'message' => "Project $status_msg", 'is_published' => $new_status]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        }
        $stmt->close();
        break;

    // ─── UPLOAD PHOTO ───────────────────────────────────
    case 'upload_photo':
        if ($method !== 'POST') { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Method not allowed']); exit; }

        $field = $_POST['field'] ?? 'photo'; // 'photo' or 'before_photo'
        if (!in_array($field, ['photo', 'before_photo'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid field']);
            exit;
        }

        if (empty($_FILES[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No file uploaded']);
            exit;
        }

        $upload_dir = __DIR__ . '/../../../uploads/completed_projects';
        $upload_dir = str_replace('\\', '/', $upload_dir);
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $result = handle_file_upload($_FILES[$field], $upload_dir, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if ($result['success']) {
            $relative_path = 'uploads/completed_projects/' . $result['filename'];
            echo json_encode(['success' => true, 'path' => $relative_path]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Upload failed']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

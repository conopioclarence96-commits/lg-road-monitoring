<?php
/**
 * API: System Announcements CRUD (+ optional photo)
 *
 * Actions: list|view|create|update|delete|toggle_publish|upload_photo
 * Admin-only mutations; staff may list/view published only.
 */

header('Content-Type: application/json; charset=utf-8');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/announcements.php';

$session_timeout = 30 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    lgu_logout_current_session();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}
$_SESSION['last_activity'] = time();

if (!isset($_SESSION['user_id']) || !is_admin_or_staff_role($_SESSION['role'] ?? '')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$is_admin = (($_SESSION['role'] ?? '') === 'system_admin');
$user_id = (int)$_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

announcements_ensure_table($conn);

switch ($action) {
    case 'list':
        $rows = $is_admin ? announcements_fetch_all($conn) : announcements_fetch_published($conn, 50);
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    case 'view':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        $row = fetch_one('SELECT * FROM system_announcements WHERE id = ?', [$id], 'i');
        if (!$row || (!$is_admin && empty($row['is_published']))) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Announcement not found']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $row]);
        break;

    case 'create':
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can post announcements']);
            exit;
        }
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }

        $title = trim((string)($_POST['title'] ?? ''));
        $content = trim((string)($_POST['content'] ?? ''));
        $posted_at = trim((string)($_POST['posted_at'] ?? ''));
        $photo = trim((string)($_POST['photo'] ?? ''));
        $is_published = announcements_parse_published_flag(1);

        if ($title === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Title is required']);
            exit;
        }
        if ($content === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Message content is required']);
            exit;
        }
        if ($posted_at === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted_at)) {
            $posted_at = date('Y-m-d');
        }
        if ($photo !== '' && strpos($photo, 'uploads/announcements/') !== 0) {
            $photo = '';
        }

        $stmt = $conn->prepare(
            'INSERT INTO system_announcements (title, content, photo, posted_at, is_published, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $photo_val = ($photo !== '') ? $photo : null;
        $stmt->bind_param('ssssii', $title, $content, $photo_val, $posted_at, $is_published, $user_id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        $new_id = (int)$stmt->insert_id;
        $stmt->close();
        log_audit_action($user_id, 'create_announcement', "Created announcement #$new_id: $title");
        echo json_encode([
            'success' => true,
            'message' => $is_published ? 'Announcement published' : 'Announcement saved as draft',
            'id' => $new_id,
        ]);
        break;

    case 'update':
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can edit announcements']);
            exit;
        }
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }

        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        $existing = fetch_one('SELECT * FROM system_announcements WHERE id = ?', [$id], 'i');
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Announcement not found']);
            exit;
        }

        $title = trim((string)($_POST['title'] ?? $existing['title']));
        $content = trim((string)($_POST['content'] ?? $existing['content']));
        $posted_at = trim((string)($_POST['posted_at'] ?? $existing['posted_at']));
        $is_published = announcements_parse_published_flag((int)$existing['is_published']);

        if ($title === '' || $content === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Title and message are required']);
            exit;
        }
        if ($posted_at === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted_at)) {
            $posted_at = $existing['posted_at'] ?: date('Y-m-d');
        }

        $photo = array_key_exists('photo', $_POST)
            ? trim((string)$_POST['photo'])
            : (string)($existing['photo'] ?? '');
        if ($photo !== '' && strpos($photo, 'uploads/announcements/') !== 0) {
            $photo = (string)($existing['photo'] ?? '');
        }
        $old_photo = (string)($existing['photo'] ?? '');
        if ($photo === '' && $old_photo !== '') {
            announcements_delete_photo_file($old_photo);
        } elseif ($photo !== '' && $old_photo !== '' && $photo !== $old_photo) {
            announcements_delete_photo_file($old_photo);
        }
        $photo_val = ($photo !== '') ? $photo : null;

        $stmt = $conn->prepare(
            'UPDATE system_announcements
             SET title = ?, content = ?, photo = ?, posted_at = ?, is_published = ?
             WHERE id = ?'
        );
        $stmt->bind_param('ssssii', $title, $content, $photo_val, $posted_at, $is_published, $id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        $stmt->close();
        log_audit_action($user_id, 'update_announcement', "Updated announcement #$id: $title");
        echo json_encode(['success' => true, 'message' => 'Announcement updated']);
        break;

    case 'toggle_publish':
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can publish announcements']);
            exit;
        }
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        $existing = fetch_one('SELECT id, title, is_published FROM system_announcements WHERE id = ?', [$id], 'i');
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Announcement not found']);
            exit;
        }
        $new_status = !empty($existing['is_published']) ? 0 : 1;
        $stmt = $conn->prepare('UPDATE system_announcements SET is_published = ? WHERE id = ?');
        $stmt->bind_param('ii', $new_status, $id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        $stmt->close();
        $msg = $new_status ? 'published' : 'unpublished';
        log_audit_action($user_id, 'toggle_publish_announcement', "Announcement #$id $msg");
        echo json_encode(['success' => true, 'message' => "Announcement $msg", 'is_published' => $new_status]);
        break;

    case 'delete':
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can delete announcements']);
            exit;
        }
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }

        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        $existing = fetch_one('SELECT id, title, photo FROM system_announcements WHERE id = ?', [$id], 'i');
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Announcement not found']);
            exit;
        }

        $stmt = $conn->prepare('DELETE FROM system_announcements WHERE id = ?');
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        $stmt->close();
        announcements_delete_photo_file($existing['photo'] ?? '');
        log_audit_action($user_id, 'delete_announcement', 'Deleted announcement #' . $id . ': ' . ($existing['title'] ?? ''));
        echo json_encode(['success' => true, 'message' => 'Announcement deleted']);
        break;

    case 'upload_photo':
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can upload announcement photos']);
            exit;
        }
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }
        if (empty($_FILES['photo']) || !isset($_FILES['photo']['tmp_name'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'No file uploaded']);
            exit;
        }
        if (!empty($_FILES['photo']['error']) && (int)$_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $err = (int)$_FILES['photo']['error'];
            $msg = 'Upload failed';
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
                $msg = 'File is too large';
            } elseif ($err === UPLOAD_ERR_NO_FILE) {
                $msg = 'No file uploaded';
            }
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $msg]);
            exit;
        }

        $upload_dir = announcements_upload_dir();
        if (!is_dir($upload_dir)) {
            if (!@mkdir($upload_dir, 0777, true) && !is_dir($upload_dir)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Could not create upload folder']);
                exit;
            }
        }
        @chmod($upload_dir, 0777);

        $result = handle_file_upload($_FILES['photo'], $upload_dir, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        if (!empty($result['success'])) {
            $relative_path = announcements_photo_web_path($result['filename']);
            echo json_encode(['success' => true, 'path' => $relative_path, 'message' => 'Photo uploaded']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Upload failed']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
        break;
}

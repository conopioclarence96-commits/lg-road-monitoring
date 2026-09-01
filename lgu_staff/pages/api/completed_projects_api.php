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

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/transparency_import_helpers.php';

// Session timeout
$session_timeout = 30 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    lgu_logout_current_session();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired']);
    exit;
}
$_SESSION['last_activity'] = time();

transparency_ensure_request_tables($conn);

// Auth check
if (!isset($_SESSION['user_id']) || !is_admin_or_staff_role($_SESSION['role'] ?? '')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$is_admin = ($_SESSION['role'] === 'system_admin');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/**
 * True when before/after photo paths refer to the same image (same path or same file bytes).
 */
function transparency_before_after_photos_identical(string $before_photo, string $after_photo): bool {
    $before_photo = trim($before_photo);
    $after_photo = trim($after_photo);
    if ($before_photo === '' || $after_photo === '') {
        return false;
    }
    if ($before_photo === $after_photo) {
        return true;
    }
    $root = realpath(__DIR__ . '/../../..');
    if ($root === false) {
        return false;
    }
    $before_path = $root . '/' . ltrim(str_replace('\\', '/', $before_photo), '/');
    $after_path = $root . '/' . ltrim(str_replace('\\', '/', $after_photo), '/');
    if (!is_file($before_path) || !is_file($after_path)) {
        return false;
    }
    $before_size = filesize($before_path);
    $after_size = filesize($after_path);
    if ($before_size === false || $after_size === false || $before_size !== $after_size) {
        return false;
    }
    return hash_file('sha256', $before_path) === hash_file('sha256', $after_path);
}

// Ensure table exists
$conn->query("CREATE TABLE IF NOT EXISTS published_completed_projects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    location VARCHAR(255) DEFAULT NULL,
    completed_date DATE DEFAULT NULL,
    start_date DATE DEFAULT NULL,
    end_date DATE DEFAULT NULL,
    cost DECIMAL(12,2) DEFAULT NULL,
    completed_by VARCHAR(255) DEFAULT NULL,
    progress_conducted_by VARCHAR(255) DEFAULT NULL,
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

// Project schedule columns, filled from the first/last progress update on import
foreach (['start_date' => 'completed_date', 'end_date' => 'start_date'] as $date_col => $after_col) {
    $check_col = $conn->query("SHOW COLUMNS FROM published_completed_projects LIKE '$date_col'");
    if ($check_col && $check_col->num_rows === 0) {
        $conn->query("ALTER TABLE published_completed_projects ADD COLUMN $date_col DATE DEFAULT NULL AFTER $after_col");
    }
}

// Staff who posted the progress updates behind the project
$check_col = $conn->query("SHOW COLUMNS FROM published_completed_projects LIKE 'progress_conducted_by'");
if ($check_col && $check_col->num_rows === 0) {
    $conn->query("ALTER TABLE published_completed_projects ADD COLUMN progress_conducted_by VARCHAR(255) DEFAULT NULL AFTER completed_by");
}

// Citizen reporter contact + publish notification tracking
foreach ([
    'reporter_name' => "VARCHAR(100) DEFAULT NULL AFTER source_report_source",
    'reporter_email' => "VARCHAR(255) DEFAULT NULL AFTER reporter_name",
    'citizen_notified_at' => "DATETIME DEFAULT NULL AFTER reporter_email",
] as $col => $def) {
    $check_col = $conn->query("SHOW COLUMNS FROM published_completed_projects LIKE '$col'");
    if ($check_col && $check_col->num_rows === 0) {
        $conn->query("ALTER TABLE published_completed_projects ADD COLUMN $col $def");
    }
}

function transparency_public_url(): string {
    $url = trim((string)env_get('PUBLIC_TRANSPARENCY_URL'));
    return $url !== '' ? $url : 'https://rgmap.infragovservices.com/public_transparency_view.php';
}

function transparency_resolve_reporter_contact(mysqli $conn, array $project): array {
    $name = trim((string)($project['reporter_name'] ?? ''));
    $email = trim((string)($project['reporter_email'] ?? ''));

    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['name' => $name, 'email' => $email];
    }

    $source_id = (int)($project['source_report_id'] ?? 0);
    if ($source_id <= 0) {
        return ['name' => $name, 'email' => ''];
    }

    $row = fetch_one(
        "SELECT reporter_name, reporter_email
         FROM road_transportation_reports
         WHERE id = ? AND created_by = 0
         LIMIT 1",
        [$source_id],
        'i'
    );
    if (!$row) {
        return ['name' => $name, 'email' => ''];
    }

    $name = trim((string)($row['reporter_name'] ?? '')) ?: $name;
    $email = trim((string)($row['reporter_email'] ?? ''));
    return ['name' => $name, 'email' => $email];
}

function transparency_persist_reporter_contact(mysqli $conn, int $project_id, string $name, string $email): void {
    if ($project_id <= 0) {
        return;
    }
    $stmt = $conn->prepare(
        "UPDATE published_completed_projects SET reporter_name = ?, reporter_email = ? WHERE id = ?"
    );
    $stmt->bind_param('ssi', $name, $email, $project_id);
    $stmt->execute();
    $stmt->close();
}

/** @return array{sent:bool,reason:string,email:string} */
function maybe_notify_citizen_on_publish(mysqli $conn, array $project, bool $was_published, bool $now_published): array {
    if (!$now_published) {
        return ['sent' => false, 'reason' => 'not_published', 'email' => ''];
    }
    if (!empty($project['citizen_notified_at'])) {
        return ['sent' => false, 'reason' => 'already_notified', 'email' => trim((string)($project['reporter_email'] ?? ''))];
    }

    $contact = transparency_resolve_reporter_contact($conn, $project);
    $email = trim((string)($contact['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['sent' => false, 'reason' => 'no_reporter_email', 'email' => ''];
    }

    $name = trim((string)($contact['name'] ?? ''));
    $title = trim((string)($project['title'] ?? ''));
    $project_id = (int)($project['id'] ?? 0);

    transparency_persist_reporter_contact($conn, $project_id, $name, $email);

    $sent = send_transparency_published_email($email, $name, transparency_public_url(), $title);
    if (!$sent) {
        return ['sent' => false, 'reason' => 'email_failed', 'email' => $email];
    }

    $stmt = $conn->prepare("UPDATE published_completed_projects SET citizen_notified_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $project_id);
    $stmt->execute();
    $stmt->close();

    return ['sent' => true, 'reason' => 'sent', 'email' => $email];
}

function transparency_notify_result_message(array $notify): string {
    switch ($notify['reason'] ?? '') {
        case 'sent':
            return 'Citizen reporter notified at ' . ($notify['email'] ?? '');
        case 'no_reporter_email':
            return 'No citizen email on file — import a CIT report to link the reporter';
        case 'email_failed':
            return 'Could not send email to ' . ($notify['email'] ?? 'reporter') . ' (check Brevo config)';
        case 'already_notified':
            return 'Citizen was already notified by email';
        default:
            return '';
    }
}

function posted_optional_string($key, $fallback = '') {
    if (!array_key_exists($key, $_POST)) {
        return $fallback;
    }
    return trim((string)$_POST[$key]);
}

function posted_optional_int($key, $fallback = null) {
    if (!array_key_exists($key, $_POST)) {
        return $fallback;
    }
    $val = trim((string)$_POST[$key]);
    if ($val === '') {
        return null;
    }
    $n = (int)$val;
    return $n > 0 ? $n : null;
}

// Date inputs post YYYY-MM-DD; anything else is stored as NULL. Returns
// $fallback only when the field was not submitted at all.
function posted_date_value($key, $fallback = null) {
    if (!array_key_exists($key, $_POST)) return $fallback;
    $val = trim((string)$_POST[$key]);
    return ($val !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) ? $val : null;
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

    // ─── LOOKUP SOURCE REPORT (import from Progress Updates export) ───
    case 'lookup_citizen_report':
    case 'lookup_source_report':
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can import reports']);
            exit;
        }
        $report_code = trim($_GET['report_id'] ?? '');
        if ($report_code === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid report ID']);
            exit;
        }

        $row = null;
        $source = '';

        // Citizen exports: CIT-...
        if (preg_match('/^CIT-[A-Za-z0-9-]+$/i', $report_code)) {
            $row = fetch_one(
                "SELECT id, report_id, title, description, location, budget_allocation, engineer,
                        reporter_name, reporter_email, status, created_by
                 FROM road_transportation_reports
                 WHERE report_id = ? AND created_by = 0
                 LIMIT 1",
                [$report_code],
                's'
            );
            $source = 'citizen';
        }

        // LGU / staff road-transportation exports: RPT-...
        if (!$row && preg_match('/^RPT-[A-Za-z0-9-]+$/i', $report_code)) {
            $row = fetch_one(
                "SELECT id, report_id, title, description, location, budget_allocation, engineer,
                        reporter_name, reporter_email, status, created_by
                 FROM road_transportation_reports
                 WHERE report_id = ?
                 LIMIT 1",
                [$report_code],
                's'
            );
            if ($row) {
                $source = !empty($row['created_by']) ? 'lgu' : 'citizen';
            }
        }

        // CIMM exports: REQ-... / reference_code
        if (!$row) {
            $row = fetch_one(
                "SELECT id, reference_code AS report_id, infrastructure AS title, issue AS description,
                        location, budget_allocation, engineer, reporter_name, email AS reporter_email,
                        verification_status AS status
                 FROM cimm_verification_reports
                 WHERE reference_code = ?
                 LIMIT 1",
                [$report_code],
                's'
            );
            if ($row) {
                $source = 'cimm';
            }
        }

        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Report not found for this export']);
            exit;
        }

        $row['source'] = $source;
        echo json_encode(['success' => true, 'data' => $row]);
        break;

    // ─── FULL-RES PHOTOS FROM PROGRESS UPDATES (import helper) ───
    case 'import_report_photos':
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can import report photos']);
            exit;
        }
        $report_id = (int)($_GET['report_id'] ?? 0);
        if ($report_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid report ID']);
            exit;
        }

        $updates = transparency_fetch_updates_for_report($conn, $report_id);
        if (empty($updates)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No progress updates found for this report']);
            exit;
        }

        [$before_photo, $after_photo] = transparency_copy_timeline_before_after_photos($updates);

        if (!$before_photo && !$after_photo) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No photos found in progress updates']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'before_photo' => $before_photo,
                'photo' => $after_photo,
            ],
        ]);
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
        $conducted_by = trim($_POST['progress_conducted_by'] ?? '');
        $photo       = trim($_POST['photo'] ?? '');
        $before_photo = trim($_POST['before_photo'] ?? '');
        if (transparency_before_after_photos_identical($before_photo, $photo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'The same photo cannot be used for both Before and After. Please upload a different image for each field.']);
            exit;
        }
        $is_published = isset($_POST['is_published']) ? 1 : 0;
        $source_report_id = posted_optional_int('source_report_id');
        $source_report_source = posted_optional_string('source_report_source');
        $reporter_name = posted_optional_string('reporter_name');
        $reporter_email = posted_optional_string('reporter_email');

        $date_val = ($completed_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $completed_date)) ? $completed_date : null;
        $start_val = posted_date_value('start_date');
        $end_val   = posted_date_value('end_date');

        $stmt = $conn->prepare("INSERT INTO published_completed_projects (title, description, location, completed_date, start_date, end_date, cost, completed_by, progress_conducted_by, source_report_id, source_report_source, reporter_name, reporter_email, photo, before_photo, is_published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssssdssisssssi', $title, $description, $location, $date_val, $start_val, $end_val, $cost, $completed_by, $conducted_by, $source_report_id, $source_report_source, $reporter_name, $reporter_email, $photo, $before_photo, $is_published);

        if ($stmt->execute()) {
            $new_id = $stmt->insert_id;
            $status_msg = $is_published ? 'published' : 'saved as draft';
            log_audit_action($_SESSION['user_id'], 'create_completed_project', "Created project #$new_id: $title ($status_msg)");
            $notify = ['sent' => false, 'reason' => 'not_published', 'email' => ''];
            if ($is_published) {
                $created = fetch_one("SELECT * FROM published_completed_projects WHERE id = ?", [$new_id], 'i');
                if ($created) {
                    $notify = maybe_notify_citizen_on_publish($conn, $created, false, true);
                }
            }
            $response = ['success' => true, 'message' => "Project $status_msg", 'id' => $new_id, 'citizen_notify' => $notify];
            $notify_msg = transparency_notify_result_message($notify);
            if ($notify_msg !== '') {
                $response['message'] .= '. ' . $notify_msg;
            }
            echo json_encode($response);
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
        $conducted_by = trim($_POST['progress_conducted_by'] ?? ($existing['progress_conducted_by'] ?? ''));
        $photo       = trim($_POST['photo'] ?? '') !== '' ? trim($_POST['photo']) : $existing['photo'];
        $before_photo = trim($_POST['before_photo'] ?? '') !== '' ? trim($_POST['before_photo']) : $existing['before_photo'];
        if (transparency_before_after_photos_identical((string)$before_photo, (string)$photo)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'The same photo cannot be used for both Before and After. Please upload a different image for each field.']);
            exit;
        }
        $is_published = isset($_POST['is_published']) ? 1 : 0;
        $was_published = !empty($existing['is_published']);
        $existing_source_id = isset($existing['source_report_id']) ? (int)$existing['source_report_id'] : null;
        if ($existing_source_id <= 0) {
            $existing_source_id = null;
        }
        $source_report_id = posted_optional_int('source_report_id', $existing_source_id);
        $source_report_source = posted_optional_string('source_report_source', $existing['source_report_source'] ?? '');
        $reporter_name = posted_optional_string('reporter_name', $existing['reporter_name'] ?? '');
        $reporter_email = posted_optional_string('reporter_email', $existing['reporter_email'] ?? '');

        $date_val = ($completed_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $completed_date)) ? $completed_date : $existing['completed_date'];
        $start_val = posted_date_value('start_date', $existing['start_date'] ?? null);
        $end_val   = posted_date_value('end_date', $existing['end_date'] ?? null);

        $stmt = $conn->prepare("UPDATE published_completed_projects SET title=?, description=?, location=?, completed_date=?, start_date=?, end_date=?, cost=?, completed_by=?, progress_conducted_by=?, source_report_id=?, source_report_source=?, reporter_name=?, reporter_email=?, photo=?, before_photo=?, is_published=? WHERE id=?");
        $stmt->bind_param('ssssssdssisssssii', $title, $description, $location, $date_val, $start_val, $end_val, $cost, $completed_by, $conducted_by, $source_report_id, $source_report_source, $reporter_name, $reporter_email, $photo, $before_photo, $is_published, $id);

        if ($stmt->execute()) {
            $status_msg = $is_published ? 'published' : 'unpublished';
            log_audit_action($_SESSION['user_id'], 'update_completed_project', "Updated project #$id: $title ($status_msg)");
            $notify = ['sent' => false, 'reason' => 'not_published', 'email' => ''];
            if ($is_published) {
                $updated = fetch_one("SELECT * FROM published_completed_projects WHERE id = ?", [$id], 'i');
                if ($updated) {
                    $notify = maybe_notify_citizen_on_publish($conn, $updated, $was_published, true);
                }
            }
            $response = ['success' => true, 'message' => "Project $status_msg", 'citizen_notify' => $notify];
            $notify_msg = transparency_notify_result_message($notify);
            if ($notify_msg !== '') {
                $response['message'] .= '. ' . $notify_msg;
            }
            echo json_encode($response);
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

        $existing = fetch_one("SELECT * FROM published_completed_projects WHERE id = ?", [$id], 'i');
        if (!$existing) { http_response_code(404); echo json_encode(['success' => false, 'message' => 'Not found']); exit; }

        $was_published = !empty($existing['is_published']);
        $new_status = $existing['is_published'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE published_completed_projects SET is_published = ? WHERE id = ?");
        $stmt->bind_param('ii', $new_status, $id);

        if ($stmt->execute()) {
            $status_msg = $new_status ? 'published' : 'unpublished';
            log_audit_action($_SESSION['user_id'], 'toggle_publish_project', "Project #$id {$status_msg}: {$existing['title']}");
            $notify = ['sent' => false, 'reason' => 'not_published', 'email' => ''];
            if ($new_status) {
                $updated = fetch_one("SELECT * FROM published_completed_projects WHERE id = ?", [$id], 'i');
                if ($updated) {
                    $notify = maybe_notify_citizen_on_publish($conn, $updated, $was_published, true);
                }
            }
            $response = [
                'success' => true,
                'message' => "Project $status_msg",
                'is_published' => $new_status,
                'citizen_notify' => $notify,
            ];
            $notify_msg = transparency_notify_result_message($notify);
            if ($notify_msg !== '') {
                $response['message'] .= '. ' . $notify_msg;
            }
            echo json_encode($response);
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

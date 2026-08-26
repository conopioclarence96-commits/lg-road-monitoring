<?php
/**
 * Transparency upload request workflow API.
 *
 * GET  ?action=list|status
 * POST action=submit|approve|reject
 */
header('Content-Type: application/json; charset=utf-8');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);
session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/transparency_import_helpers.php';

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

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$is_admin = ($role === 'system_admin');
$is_road_supervisor = ($role === 'road_ops_supervisor');
$is_trans_supervisor = ($role === 'trans_ops_supervisor');

transparency_ensure_request_tables($conn);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

function transparency_latest_request($conn, int $report_id, string $source): ?array {
    $source = strtolower(trim($source));
    return fetch_one(
        "SELECT * FROM transparency_upload_requests
         WHERE report_id = ? AND report_source = ?
         ORDER BY id DESC LIMIT 1",
        [$report_id, $source],
        'is'
    );
}

function transparency_pending_request($conn, int $report_id, string $source): ?array {
    $row = transparency_latest_request($conn, $report_id, $source);
    return ($row && ($row['status'] ?? '') === 'pending') ? $row : null;
}

switch ($action) {
    case 'status':
        $report_id = (int)($_GET['report_id'] ?? 0);
        $source = sanitize_input($_GET['source'] ?? '');
        if ($report_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid report ID']);
            exit;
        }
        $latest = transparency_latest_request($conn, $report_id, $source);
        echo json_encode([
            'success' => true,
            'status' => $latest['status'] ?? 'none',
            'request_id' => (int)($latest['id'] ?? 0),
        ]);
        break;

    // Single request lookup used by the admin review panel. Returns the report
    // it is tied to so the UI can confirm it is acting on the right project.
    case 'get':
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can view transparency requests']);
            exit;
        }
        $request_id = (int)($_GET['request_id'] ?? 0);
        if ($request_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
            exit;
        }
        $row = fetch_one(
            "SELECT r.*, u.full_name AS requested_by_name
             FROM transparency_upload_requests r
             LEFT JOIN users u ON u.id = r.requested_by
             WHERE r.id = ?",
            [$request_id],
            'i'
        );
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Request not found']);
            exit;
        }
        echo json_encode(['success' => true, 'data' => $row]);
        break;

    // Import payload for an approved request, used by public_transparency.php to
    // fill the Add New Project form without the admin handling an export file.
    // Scoped to the request's own report id + source, so no other project's data
    // can be pulled in. Built on first call and cached on the request row so the
    // before/after photos are only ever copied once.
    case 'prefill':
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can import transparency data']);
            exit;
        }
        $request_id = (int)($_GET['request_id'] ?? 0);
        if ($request_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
            exit;
        }

        $row = fetch_one(
            "SELECT r.*, u.full_name AS requested_by_name
             FROM transparency_upload_requests r
             LEFT JOIN users u ON u.id = r.requested_by
             WHERE r.id = ?",
            [$request_id],
            'i'
        );
        if (!$row) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Request not found']);
            exit;
        }
        if (($row['status'] ?? '') !== 'approved') {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Only approved transparency requests can be imported',
            ]);
            exit;
        }

        $data = null;
        if (!empty($row['import_payload'])) {
            $cached = json_decode((string)$row['import_payload'], true);
            if (is_array($cached)) {
                $data = $cached;
            }
        }

        if ($data === null) {
            try {
                $data = transparency_build_import_data(
                    $conn,
                    (int)$row['report_id'],
                    (string)$row['report_source']
                );
            } catch (Exception $e) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit;
            }

            $payload_json = json_encode($data);
            $save = $conn->prepare("UPDATE transparency_upload_requests SET import_payload = ? WHERE id = ?");
            $save->bind_param('si', $payload_json, $request_id);
            $save->execute();
            $save->close();
        } elseif (
            !array_key_exists('reporter_name', $data)
            || !array_key_exists('reporter_email', $data)
            || !array_key_exists('source_report_id', $data)
        ) {
            // Older cached payloads predate reporter contact / source linkage.
            $contact = transparency_reporter_contact_from_report(
                $conn,
                (int)$row['report_id'],
                (string)$row['report_source']
            );
            $data['reporter_name'] = $contact['reporter_name'];
            $data['reporter_email'] = $contact['reporter_email'];
            $data['source_report_id'] = $contact['source_report_id'];
            $data['source_report_source'] = $contact['source_report_source'];
            $payload_json = json_encode($data);
            $save = $conn->prepare("UPDATE transparency_upload_requests SET import_payload = ? WHERE id = ?");
            $save->bind_param('si', $payload_json, $request_id);
            $save->execute();
            $save->close();
        }

        echo json_encode([
            'success' => true,
            'data' => $data,
            'request' => [
                'id' => (int)$row['id'],
                'report_id' => (int)$row['report_id'],
                'report_source' => (string)$row['report_source'],
                'report_title' => (string)($row['report_title'] ?? ''),
                'requested_by_name' => (string)($row['requested_by_name'] ?? ''),
                'reviewed_at' => (string)($row['reviewed_at'] ?? ''),
            ],
        ]);
        break;

    case 'list':
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can view transparency requests']);
            exit;
        }
        $rows = fetch_all(
            "SELECT r.*, u.full_name AS requested_by_name
             FROM transparency_upload_requests r
             LEFT JOIN users u ON u.id = r.requested_by
             WHERE r.status = 'pending'
             ORDER BY r.created_at ASC"
        );
        echo json_encode(['success' => true, 'data' => $rows]);
        break;

    case 'submit':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }
        if (!$is_road_supervisor && !$is_trans_supervisor) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only Operations Supervisors can request transparency uploads']);
            exit;
        }

        $report_id = (int)($_POST['report_id'] ?? 0);
        $source = sanitize_input($_POST['source'] ?? '');
        $report_type = sanitize_input($_POST['report_type'] ?? '');

        if ($report_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid report ID']);
            exit;
        }

        $report = transparency_fetch_request_report($conn, $report_id, $source);
        if (!$report) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Only completed road, transportation, or infrastructure projects can be sent for transparency review']);
            exit;
        }

        // Keeps each supervisor to the reports their own portal lists.
        if (!transparency_role_may_request($role, $report)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'This project is outside your transparency upload scope']);
            exit;
        }

        // Only the supervisor who first assigned this report may request transparency.
        $asg_type = rgmap_assignment_type_from_source($source);
        if (!rgmap_supervisor_can_manage_report($conn, $report_id, $asg_type)) {
            $owner = rgmap_get_report_owner_supervisor($conn, $report_id, $asg_type);
            $owner_name = trim((string)($owner['name'] ?? '')) ?: 'another supervisor';
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => "This report is managed by {$owner_name}. Only the supervisor who assigned it can request transparency upload.",
            ]);
            exit;
        }

        if (transparency_pending_request($conn, $report_id, $source)) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'A transparency upload request is already pending for this project']);
            exit;
        }

        $latest = transparency_latest_request($conn, $report_id, $source);
        if ($latest && ($latest['status'] ?? '') === 'approved') {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Transparency upload for this project was already approved']);
            exit;
        }

        $mgmt_source = transparency_mgmt_source_for_report($report);
        $title = trim((string)($report['title'] ?? ''));
        $location = trim((string)($report['location'] ?? ''));

        $stmt = $conn->prepare(
            "INSERT INTO transparency_upload_requests
                (report_id, report_source, report_type, report_mgmt_source, report_title, report_location, requested_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssssi', $report_id, $source, $report_type, $mgmt_source, $title, $location, $user_id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        $request_id = (int)$stmt->insert_id;
        $stmt->close();

        log_audit_action($user_id, 'transparency_upload_request', "Requested transparency upload for report #$report_id ($source)");

        echo json_encode([
            'success' => true,
            'message' => 'Transparency upload request sent to the administrator for review',
            'request_id' => $request_id,
        ]);
        break;

    case 'approve':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can approve transparency requests']);
            exit;
        }

        $request_id = (int)($_POST['request_id'] ?? 0);
        if ($request_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
            exit;
        }

        $request = fetch_one("SELECT * FROM transparency_upload_requests WHERE id = ?", [$request_id], 'i');
        if (!$request || ($request['status'] ?? '') !== 'pending') {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pending request not found']);
            exit;
        }

        $report_id = (int)$request['report_id'];

        // Approves the request only. The report keeps its Completed status and
        // no transparency import/publish happens at this stage.
        $stmt = $conn->prepare(
            "UPDATE transparency_upload_requests
             SET status = 'approved', reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->bind_param('ii', $user_id, $request_id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        if ($stmt->affected_rows === 0) {
            $stmt->close();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This request was already reviewed']);
            exit;
        }
        $stmt->close();

        log_audit_action($user_id, 'transparency_upload_approved', "Approved transparency request #$request_id for report #$report_id");

        // Let the supervisor who raised the request know the outcome.
        transparency_notify_requester($conn, $request, 'approve');

        // The admin continues in public_transparency.php, where the approved
        // project's data is imported into the form for review before publishing.
        echo json_encode([
            'success' => true,
            'message' => 'Transparency upload request approved',
            'redirect_url' => '../shared/public_transparency.php?transparency_request=' . $request_id,
        ]);
        break;

    case 'reject':
        if ($method !== 'POST') {
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            exit;
        }
        if (!$is_admin) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Only administrators can reject transparency requests']);
            exit;
        }

        $request_id = (int)($_POST['request_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($request_id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
            exit;
        }

        $request = fetch_one("SELECT * FROM transparency_upload_requests WHERE id = ?", [$request_id], 'i');
        if (!$request || ($request['status'] ?? '') !== 'pending') {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pending request not found']);
            exit;
        }

        $stmt = $conn->prepare(
            "UPDATE transparency_upload_requests
             SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ?
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->bind_param('isi', $user_id, $reason, $request_id);
        if (!$stmt->execute()) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
            exit;
        }
        $stmt->close();

        log_audit_action($user_id, 'transparency_upload_rejected', "Rejected transparency request #$request_id for report #{$request['report_id']}");

        // Let the supervisor who raised the request know the outcome.
        transparency_notify_requester($conn, $request, 'reject');

        echo json_encode(['success' => true, 'message' => 'Transparency upload request rejected']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

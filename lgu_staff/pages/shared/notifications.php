<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
}

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();
$session_timeout = 30 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ../../login.php?timeout=1');
    exit();
}
$_SESSION['last_activity'] = time();

require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../api/cimm_verification_data.php';

$user_role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;

if (!isset($_SESSION['user_id']) || !is_admin_or_staff_role($user_role)) {
    header('Location: ../../login.php');
    exit();
}

$user_email = $_SESSION['email'] ?? '';

// Transportation roles only ever receive notifications for transportation
// reports (report_category = 'transportation', not infrastructure/maintenance).
// Every report-reference existence check below is narrowed to transportation
// rows for these roles so CIMM, road or maintenance notifications never leak
// into their feed.
$is_trans_supervisor = ($user_role === 'trans_ops_supervisor');
$is_trans_officer = ($user_role === 'trans_monitoring_officer');
$is_trans_role = $is_trans_supervisor || $is_trans_officer;
$trans_exists = "SELECT 1 FROM road_transportation_reports
                 WHERE id = rn.report_id
                   AND report_category = 'transportation'
                   AND report_type != 'infrastructure_issue'";
$all_tables_exists = "SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                      UNION ALL
                      SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                      UNION ALL
                      SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id";
$ro_exists = $is_trans_officer ? $trans_exists : $all_tables_exists;

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $type = $_POST['type'] ?? '';
        $id = $_POST['id'] ?? 0;

        // Safety check: if a notification references a report that no longer
        // exists in any live table, delete the notification instead of showing
        // an error or marking it read.
        if ($type === 'report' || $type === 'report_outcome' || $type === 'project_assignment') {
            $check_stmt = $conn->prepare("
                SELECT rn.id FROM report_notifications rn
                WHERE rn.id = ?
                  AND NOT EXISTS (
                      SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                      UNION ALL
                      SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                      UNION ALL
                      SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id
                      LIMIT 1
                  )
                LIMIT 1
            ");
            $check_stmt->bind_param("i", $id);
            $check_stmt->execute();
            $orphan = $check_stmt->get_result()->fetch_assoc();
            $check_stmt->close();
            if ($orphan) {
                $del_stmt = $conn->prepare("DELETE FROM report_notifications WHERE id = ?");
                $del_stmt->bind_param("i", $id);
                $del_stmt->execute();
                $del_stmt->close();
                echo json_encode(['success' => true, 'deleted' => true]);
                exit;
            }
        }

        if ($type === 'report') {
            $stmt = $conn->prepare("UPDATE road_transportation_reports SET updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }

        if ($type === 'project_assignment') {
            $stmt = $conn->prepare("UPDATE report_assignments SET status = 'completed' WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }

        if ($type === 'report_outcome') {
            $stmt = $conn->prepare("UPDATE report_notifications SET is_read = 1 WHERE id = ? AND type IN ('approve_request','reject_request','complete_report','cancel_report')");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }

        if ($type === 'review') {
            $stmt = $conn->prepare("UPDATE report_notifications SET is_read = 1 WHERE id = ? AND recipient_role = ? AND type IN ('completion','cancellation')");
            $stmt->bind_param("is", $id, $user_role);
            $stmt->execute();
            $stmt->close();
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $conn->query("UPDATE road_transportation_reports SET updated_at = NOW() WHERE status = 'pending'");
        if ($user_email !== '') {
            $stmt = $conn->prepare("UPDATE report_notifications SET is_read = 1 WHERE recipient_email = ? AND type IN ('approve_request','reject_request','complete_report','cancel_report')");
            $stmt->bind_param("s", $user_email);
            $stmt->execute();
            $stmt->close();
        }
        if (in_array($user_role, ['road_ops_supervisor', 'trans_ops_supervisor'], true)) {
            // Supervisors: also mark role-targeted review requests
            // (completion/cancellation routed to their role) as read so the
            // badge fully resets instead of counting them again on reload.
            $stmt = $conn->prepare("UPDATE report_notifications SET is_read = 1 WHERE recipient_role = ? AND type IN ('completion','cancellation')");
            $stmt->bind_param("s", $user_role);
            $stmt->execute();
            $stmt->close();
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'mark_read_change') {
        $id = $_POST['id'] ?? 0;
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE change_requests SET admin_notes = CONCAT(COALESCE(admin_notes,''), '[Viewed]') WHERE id = ? AND status = 'pending'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        }
        echo json_encode(['success' => true]);
        exit;
    }
}

$is_admin = ($user_role === 'system_admin');
$is_supervisor = in_array($user_role, ['road_ops_supervisor', 'trans_ops_supervisor'], true);
$pending_reports = [];
$pending_changes = [];
$report_updates = [];
$staff_updates = [];
$assigned_projects = [];
$review_requests = [];
$supervisor_actions = [];

if ($is_admin) {
    // Admin: get pending reports
    try {
        $rstmt = $conn->prepare("
            SELECT id, report_id, title, department, priority, status, description, location, 
                   reporter_name, reporter_email, created_at,
                   report_type, report_category, report_source, created_by
            FROM road_transportation_reports 
            WHERE status = 'pending'
            ORDER BY 
                CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 END,
                created_at DESC
        ");
        $rstmt->execute();
        $pending_reports = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rstmt->close();
    } catch (Exception $e) {
        error_log("Pending reports query error: " . $e->getMessage());
    }

    // Admin: also include CIMM reports that are still awaiting review/approval.
    // These live in cimm_verification_reports (synced from the CIMM module via
    // the webhook/pull sync), so they are fetched from that table and flagged
    // with _source='cimm' so the source badge below stays accurate. Any row in
    // that table whose current status maps to "pending" is shown.
    try {
        $cimmPdo = rgmap_verification_pdo();
        $cimmRows = rgmap_fetch_cimm_verification_reports($cimmPdo, ['limit' => 500]);
        $cimmPendingStatus = ['Pending', 'Pending Review'];
        foreach ($cimmRows as $crow) {
            $verification = (string)($crow['verification_status'] ?? 'Pending Review');
            if (!in_array($verification, $cimmPendingStatus, true) && (string)($crow['approval_status'] ?? 'Pending') !== 'Pending') {
                continue;
            }
            $facility = (string)($crow['cprf_facility_name'] ?? '');
            $pending_reports[] = [
                '_source'       => 'cimm',
                'id'            => (int)($crow['id'] ?? $crow['cimm_req_id'] ?? 0),
                'report_id'     => $crow['reference_code'] ?? ('REQ-' . ($crow['cimm_req_id'] ?? '')),
                'title'         => (string)($crow['infrastructure'] ?? 'CIMM Report'),
                'department'    => $facility !== '' ? $facility : 'CIMM',
                'priority'      => strtolower((string)($crow['priority'] ?? 'medium')),
                'status'        => 'pending',
                'description'   => (string)($crow['issue'] ?? ''),
                'location'      => (string)($crow['location'] ?? ''),
                'reporter_name' => $crow['reporter_name'] ?? null,
                'reporter_email'=> $crow['email'] ?? null,
                'created_at'    => $crow['submitted_at'] ?? $crow['created_at'] ?? date('Y-m-d H:i:s'),
                'report_type'   => null,
                'report_category' => null,
                'report_source' => null,
                'created_by'    => null,
            ];
        }

        // Keep the same ordering as the transport query: high -> medium -> low,
        // then newest first.
        usort($pending_reports, function ($a, $b) {
            $prio = ['high' => 1, 'medium' => 2, 'low' => 3];
            $pa = $prio[strtolower((string)($a['priority'] ?? 'medium'))] ?? 2;
            $pb = $prio[strtolower((string)($b['priority'] ?? 'medium'))] ?? 2;
            if ($pa !== $pb) return $pa <=> $pb;
            $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
            $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
            return $tb <=> $ta;
        });
    } catch (Exception $e) {
        error_log("Pending CIMM reports query error: " . $e->getMessage());
    }

    // Admin: get all pending change requests
    try {
        $cstmt = $conn->prepare("
            SELECT cr.*, u.full_name as user_name
            FROM change_requests cr
            LEFT JOIN users u ON cr.user_id = u.id
            WHERE cr.status = 'pending'
            ORDER BY cr.created_at DESC
        ");
        $cstmt->execute();
        $pending_changes = $cstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cstmt->close();
    } catch (Exception $e) {
        error_log("Pending change requests query error: " . $e->getMessage());
    }

    // Admin: get progress update notifications
    try {
        $nstmt = $conn->prepare("
            SELECT rn.*, r.report_id as report_code, r.title as report_title,
                   r.report_type, r.report_category, r.report_source, r.created_by
            FROM report_notifications rn
            LEFT JOIN road_transportation_reports r ON rn.report_id = r.id
            WHERE rn.is_read = 0
              AND EXISTS (
                  SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                  UNION ALL
                  SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                  UNION ALL
                  SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id
                  LIMIT 1
              )
            ORDER BY rn.created_at DESC
            LIMIT 20
        ");
        $nstmt->execute();
        $progress_notifications = $nstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $nstmt->close();

        // The LEFT JOIN above only fills in report_code/report_title for
        // notifications that reference road_transportation_reports. Progress
        // updates can also attach to road_maintenance_reports or CIMM reports
        // (the FK on report_notifications.report_id was dropped for that), so
        // resolve each notification's actual originating table server-side.
        // This guarantees every View Report button passes the correct id and a
        // source hint so the destination page looks in the right table.
        $progress_notifications = array_map('resolve_progress_notification_source', $progress_notifications);
    } catch (Exception $e) {
        error_log("Progress notifications query error: " . $e->getMessage());
        $progress_notifications = [];
    }

    // Admin: get assigned project notifications (all project assignments)
    try {
        $astmt = $conn->prepare("
            SELECT ra.*, r.report_id as report_code, r.title as report_title,
                   r.report_type as transport_report_type, r.report_category, r.report_source, r.created_by
            FROM report_assignments ra
            LEFT JOIN road_transportation_reports r ON ra.report_id = r.id AND ra.report_type = 'road_transportation_reports'
            WHERE ra.status = 'active' AND ra.user_id = ?
            ORDER BY ra.assigned_at DESC
            LIMIT 20
        ");
        $astmt->bind_param("i", $user_id);
        $astmt->execute();
        $assigned_projects = $astmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $astmt->close();

        // Enrich assignments with report data from appropriate tables
        foreach ($assigned_projects as &$assignment) {
            if ($assignment['report_type'] === 'road_maintenance_reports') {
                $mstmt = $conn->prepare("SELECT report_id, title, report_type, report_category, report_source, created_by FROM road_maintenance_reports WHERE id = ?");
                $mstmt->bind_param("i", $assignment['report_id']);
                $mstmt->execute();
                $mresult = $mstmt->get_result()->fetch_assoc();
                if ($mresult) {
                    $assignment['report_code'] = $mresult['report_id'];
                    $assignment['report_title'] = $mresult['title'];
                    $assignment['transport_report_type'] = $mresult['report_type'];
                    $assignment['report_category'] = $mresult['report_category'];
                    $assignment['report_source'] = $mresult['report_source'];
                    $assignment['created_by'] = $mresult['created_by'];
                    $assignment['_source'] = 'maintenance';
                }
                $mstmt->close();
            } elseif ($assignment['report_type'] === 'cimm_verification_reports') {
                try {
                    $cimmPdo = rgmap_verification_pdo();
                    $cimmRows = rgmap_fetch_cimm_verification_reports($cimmPdo, ['limit' => 500]);
                    foreach ($cimmRows as $crow) {
                        if ((int)($crow['id'] ?? 0) === (int)$assignment['report_id']) {
                            $assignment['report_code'] = $crow['reference_code'] ?? ('REQ-' . ($crow['cimm_req_id'] ?? ''));
                            $assignment['report_title'] = $crow['infrastructure'] ?? 'CIMM Report';
                            $assignment['transport_report_type'] = 'infrastructure_issue';
                            $assignment['report_category'] = null;
                            $assignment['report_source'] = null;
                            $assignment['created_by'] = null;
                            $assignment['_source'] = 'cimm';
                            break;
                        }
                    }
                } catch (Exception $e) {
                    error_log("CIMM assignment lookup error: " . $e->getMessage());
                }
            } else {
                // Already got data from road_transportation_reports join
                $assignment['_source'] = 'transport';
            }
        }
    } catch (Exception $e) {
        error_log("Admin assigned projects query error: " . $e->getMessage());
        $assigned_projects = [];
    }
} else {
    // LGU Staff: get their own change request status updates
    try {
        $sstmt = $conn->prepare("
            SELECT id, status, admin_notes, created_at, reviewed_at
            FROM change_requests
            WHERE user_id = ? AND status != 'pending'
            ORDER BY reviewed_at DESC
            LIMIT 20
        ");
        $sstmt->bind_param("i", $user_id);
        $sstmt->execute();
        $staff_updates = $sstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $sstmt->close();
    } catch (Exception $e) {
        error_log("Staff updates query error: " . $e->getMessage());
    }

    // LGU Staff: get report status updates (approved = completed, rejected = cancelled)
    try {
        $rstmt = $conn->prepare("
            SELECT id, report_id, title, status, location,
                   approved_at, rejected_at, updated_at, created_at,
                   report_type, report_category, report_source, created_by
            FROM road_transportation_reports
            WHERE created_by = ? AND status IN ('completed', 'cancelled')
            " . ($is_trans_role ? "AND report_category = 'transportation' AND report_type != 'infrastructure_issue'" : '') . "
            ORDER BY GREATEST(COALESCE(approved_at, '1970-01-01'), COALESCE(rejected_at, '1970-01-01'), updated_at) DESC
            LIMIT 20
        ");
        $rstmt->bind_param("i", $user_id);
        $rstmt->execute();
        $report_updates = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rstmt->close();
    } catch (Exception $e) {
        error_log("Staff report updates query error: " . $e->getMessage());
    }

    // LGU Staff: get their review-request outcome notifications (approve/reject).
    // These notifications have recipient_email set to the officer's email and
    // type 'approve_request' or 'reject_request' — created by
    // rgmap_notify_requestor() after a supervisor processes a request.
    $user_email = $_SESSION['email'] ?? '';
    $request_outcomes = [];
    if ($user_email !== '') {
        try {
            $ro_stmt = $conn->prepare("
                SELECT rn.*, r.report_id AS report_code, r.title AS report_title
                FROM report_notifications rn
                LEFT JOIN road_transportation_reports r ON rn.report_id = r.id
                WHERE rn.recipient_email = ? AND rn.type IN ('approve_request','reject_request')
                  AND rn.is_read = 0
                  AND EXISTS (" . $ro_exists . "
                      LIMIT 1
                  )
                ORDER BY rn.created_at DESC
                LIMIT 20
            ");
            $ro_stmt->bind_param("s", $user_email);
            $ro_stmt->execute();
            $request_outcomes = $ro_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $ro_stmt->close();
            $request_outcomes = array_map('resolve_progress_notification_source', $request_outcomes);
        } catch (Exception $e) {
            error_log("Request outcomes query error: " . $e->getMessage());
        }
    }

    // LGU Staff: get assigned projects
    try {
        $astmt = $conn->prepare("
            SELECT ra.*, r.report_id as report_code, r.title as report_title,
                   r.report_type as transport_report_type, r.report_category, r.report_source, r.created_by
            FROM report_assignments ra
            LEFT JOIN road_transportation_reports r ON ra.report_id = r.id AND ra.report_type = 'road_transportation_reports'
            WHERE ra.user_id = ? AND ra.status = 'active'
            ORDER BY ra.assigned_at DESC
            LIMIT 20
        ");
        $astmt->bind_param("i", $user_id);
        $astmt->execute();
        $assigned_projects = $astmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $astmt->close();

        // Enrich assignments with report data from appropriate tables
        foreach ($assigned_projects as &$assignment) {
            if ($assignment['report_type'] === 'road_maintenance_reports') {
                $mstmt = $conn->prepare("SELECT report_id, title, report_type, report_category, report_source, created_by FROM road_maintenance_reports WHERE id = ?");
                $mstmt->bind_param("i", $assignment['report_id']);
                $mstmt->execute();
                $mresult = $mstmt->get_result()->fetch_assoc();
                if ($mresult) {
                    $assignment['report_code'] = $mresult['report_id'];
                    $assignment['report_title'] = $mresult['title'];
                    $assignment['transport_report_type'] = $mresult['report_type'];
                    $assignment['report_category'] = $mresult['report_category'];
                    $assignment['report_source'] = $mresult['report_source'];
                    $assignment['created_by'] = $mresult['created_by'];
                    $assignment['_source'] = 'maintenance';
                }
                $mstmt->close();
            } elseif ($assignment['report_type'] === 'cimm_verification_reports') {
                try {
                    $cimmPdo = rgmap_verification_pdo();
                    $cimmRows = rgmap_fetch_cimm_verification_reports($cimmPdo, ['limit' => 500]);
                    foreach ($cimmRows as $crow) {
                        if ((int)($crow['id'] ?? 0) === (int)$assignment['report_id']) {
                            $assignment['report_code'] = $crow['reference_code'] ?? ('REQ-' . ($crow['cimm_req_id'] ?? ''));
                            $assignment['report_title'] = $crow['infrastructure'] ?? 'CIMM Report';
                            $assignment['transport_report_type'] = 'infrastructure_issue';
                            $assignment['report_category'] = null;
                            $assignment['report_source'] = null;
                            $assignment['created_by'] = null;
                            $assignment['_source'] = 'cimm';
                            break;
                        }
                    }
                } catch (Exception $e) {
                    error_log("CIMM assignment lookup error: " . $e->getMessage());
                }
            } else {
                // Already got data from road_transportation_reports join
                $assignment['_source'] = 'transport';
            }
        }
    } catch (Exception $e) {
        error_log("Staff assigned projects query error: " . $e->getMessage());
        $assigned_projects = [];
    }

    // Supervisors: get completion/cancellation requests routed to their module.
    // Road Operations Supervisors only see road requests, Transportation
    // Operations Supervisors only see transportation requests (the request
    // notification's recipient_role matches the requesting officer's module).
    if ($is_supervisor) {
        try {
            $rrstmt = $conn->prepare("
                SELECT rn.*, r.report_id as report_code, r.title as report_title, r.report_category, r.location
                FROM report_notifications rn
                LEFT JOIN road_transportation_reports r ON rn.report_id = r.id
                WHERE rn.is_read = 0 AND rn.recipient_role = ? AND rn.type IN ('completion', 'cancellation')
                  AND EXISTS (" . ($is_trans_supervisor ? $trans_exists : $all_tables_exists) . "
                      LIMIT 1
                  )
                ORDER BY rn.created_at DESC
                LIMIT 20
            ");
            $rrstmt->bind_param("s", $user_role);
            $rrstmt->execute();
            $review_requests = $rrstmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $rrstmt->close();
        } catch (Exception $e) {
            error_log("Review requests query error: " . $e->getMessage());
            $review_requests = [];
        }

        // Confirmations for actions the supervisor performed themselves
        // (Complete/Cancel buttons on road_transportation_monitoring.php),
        // targeted by email to the acting supervisor.
        try {
            $sastmt = $conn->prepare("
                SELECT rn.*, r.report_id as report_code, r.title as report_title, r.report_category, r.location
                FROM report_notifications rn
                LEFT JOIN road_transportation_reports r ON rn.report_id = r.id
                WHERE rn.is_read = 0 AND rn.recipient_email = ? AND rn.type IN ('complete_report', 'cancel_report')
                  AND EXISTS (" . ($is_trans_supervisor ? $trans_exists : $all_tables_exists) . "
                      LIMIT 1
                  )
                ORDER BY rn.created_at DESC
                LIMIT 20
            ");
            $sastmt->bind_param("s", $user_email);
            $sastmt->execute();
            $supervisor_actions = $sastmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $sastmt->close();
        } catch (Exception $e) {
            error_log("Supervisor actions query error: " . $e->getMessage());
            $supervisor_actions = [];
        }
    }
}

$total_notifications = $is_admin ? (count($pending_reports) + count($pending_changes) + count($progress_notifications) + count($assigned_projects)) : (count($staff_updates) + count($report_updates) + count($assigned_projects) + count($request_outcomes));

// --- Notification deep-link helpers ----------------------------------------
// Every View/Review button must pass the report source together with the id
// so destination pages can look up the record in the correct table — ids are
// only unique within their own table. Returns one of:
//   'citizen'     -> verification_monitoring.php?source=citizen&id=...
//   'lgu'         -> report_management.php?source=lgu&id=...
//   'maintenance' -> report_management.php?source=maintenance&id=...
function notification_report_source(array $r): string {
    if (($r['_source'] ?? '') === 'cimm') return 'cimm';
    if (($r['report_type'] ?? '') === 'infrastructure_issue') return 'maintenance';
    if (!empty($r['created_by'])) return 'lgu';
    return 'citizen';
}

// Resolve the originating module of a report so the Pending Reports panel can
// show it as a colored badge. Determined server-side from the report's
// originating table (_source, set when appended from cimm_verification_reports)
// or its source fields (report_type / created_by) — never guessed or hardcoded
// per-row. Returns ['key' => .., 'label' => .., 'icon' => .., 'class' => ..].
function notification_source_badge(array $r): array {
    switch (notification_report_source($r)) {
        case 'cimm':
            return ['key' => 'cimm',        'label' => 'CIMM',                  'icon' => '🟣', 'class' => 'source-cimm'];
        case 'maintenance':
            return ['key' => 'maintenance', 'label' => 'Infrastructure Projects', 'icon' => '🟠', 'class' => 'source-maintenance'];
        case 'lgu':
            return ['key' => 'lgu',         'label' => 'LGU Monitoring',         'icon' => '🔵', 'class' => 'source-lgu'];
        case 'citizen':
        default:
            return ['key' => 'citizen',     'label' => 'Citizen Reports',        'icon' => '🟢', 'class' => 'source-citizen'];
    }
}

function notification_report_url_for(int $id, array $r): string {
    $type = rawurlencode((string)($r['report_type'] ?? ''));
    $src = notification_report_source($r);
    if ($src === 'citizen') {
        return '../admin/verification_monitoring.php?source=citizen&id=' . $id;
    }
    $url = '../admin/report_management.php?source=' . $src . '&id=' . $id;
    if ($type !== '') $url .= '&type=' . $type;
    return $url;
}

function notification_report_url(array $r): string {
    return notification_report_url_for((int)($r['id'] ?? 0), $r);
}

// Deep-link for the "Pending Reports from Departments" panel. Every pending
// report lives in road_transportation_reports, so the primary key alone is
// enough — verification_monitoring.php locates it across all sections via
// ?focus_report_id= (backend-verified, then scrolled + highlighted on load).
function notification_pending_report_focus_url(array $r): string {
    // CIMM reports live in cimm_verification_reports, not
    // road_transportation_reports, so they need the explicit source+id deep link
    // (verification_monitoring.php's ?focus_report_id= path only resolves
    // transport-table rows).
    if (($r['_source'] ?? '') === 'cimm') {
        return '../admin/verification_monitoring.php?source=cimm&id=' . (int)($r['id'] ?? 0);
    }
    return '../admin/verification_monitoring.php?focus_report_id=' . (int)($r['id'] ?? 0);
}

// Resolve which report table a Progress Update notification references.
// report_notifications.report_id is the numeric PK in the originating table
// (road_transportation_reports / road_maintenance_reports / cimm_verification_reports),
// and the FK is intentionally dropped so it can point at any of them. The
// notifications query only joins the transport table, so when that join yields
// no code, probe the other tables. Returns the row with '_source' set to
// 'transport' | 'maintenance' | 'cimm' plus the real report code/title.
function resolve_progress_notification_source(array $pn): array {
    global $conn;
    $id = (int)($pn['report_id'] ?? 0);
    if ($id <= 0) return $pn;

    // Transport rows already resolved by the LEFT JOIN in the query.
    if (!empty($pn['report_code'])) {
        $pn['_source'] = 'transport';
        return $pn;
    }

    try {
        // Maintenance reports.
        $stmt = $conn->prepare("SELECT id, report_id, title FROM road_maintenance_reports WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $pn['_source'] = 'maintenance';
            $pn['report_code'] = $row['report_id'];
            $pn['report_title'] = $row['title'];
            return $pn;
        }

        // CIMM reports (same database, PDO access via cimm_verification_data.php).
        $pdo = rgmap_verification_pdo();
        rgmap_ensure_cimm_verification_table($pdo);
        $stmt = $pdo->prepare("SELECT id, reference_code AS report_id, infrastructure AS title FROM cimm_verification_reports WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $pn['_source'] = 'cimm';
            $pn['report_code'] = $row['report_id'];
            $pn['report_title'] = $row['title'];
            return $pn;
        }
    } catch (Exception $e) {
        error_log("Progress notification source resolution error: " . $e->getMessage());
    }

    $pn['_source'] = 'transport';
    return $pn;
}

// Deep-link for the "Progress Updates" panel. Every View Report button must
// redirect to road_transportation_monitoring.php (Recent Submissions), passing
// the report's numeric PK plus a source hint so the page looks it up in the
// correct table even when ids collide across tables.
function notification_progress_focus_url(array $pn): string {
    $url = 'road_transportation_monitoring.php?focus_report_id=' . (int)($pn['report_id'] ?? 0);
    $src = (string)($pn['_source'] ?? '');
    if ($src !== '') {
        $url .= '&source=' . rawurlencode($src);
    }
    return $url;
}

// URL for assigned projects - always goes to road monitoring page
function notification_assignment_url(array $ap): string {
    $source = (string)($ap['_source'] ?? '');
    $report_id = (int)($ap['report_id'] ?? 0);

    // Always redirect to road monitoring page with focus_report_id
    $url = 'road_transportation_monitoring.php?focus_report_id=' . $report_id;
    if ($source !== '') {
        $url .= '&source=' . rawurlencode($source);
    }
    return $url;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        :root {
            --nc-bg: #f6f7fb;
            --nc-card: #ffffff;
            --nc-border: #e9edf3;
            --nc-text: #0f172a;
            --nc-muted: #64748b;
            --nc-primary: #4f46e5;
        }

        .main-content {
            max-width: 920px;
            margin: 0 auto;
            padding: 28px 24px 60px;
        }

        /* Header */
        .nc-header {
            background: var(--nc-card);
            border: 1px solid var(--nc-border);
            border-radius: 16px;
            padding: 18px 20px 16px;
            box-shadow: 0 4px 18px rgba(15, 23, 42, .06);
            margin-bottom: 6px;
        }
        .nc-header-top { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
        .nc-title { display: flex; align-items: center; gap: 12px; }
        .nc-title-icon {
            width: 40px; height: 40px; border-radius: 12px;
            background: #eef2ff; color: var(--nc-primary);
            display: flex; align-items: center; justify-content: center; font-size: 18px;
        }
        .nc-title h1 { font-size: 20px; font-weight: 700; color: var(--nc-text); margin: 0; }
        .nc-unread-badge {
            background: #ef4444; color: #fff; font-size: 12px; font-weight: 600;
            padding: 2px 10px; border-radius: 999px; line-height: 1.6;
        }
        .nc-header-actions { margin-left: auto; }
        .nc-header-sub { margin: 12px 0 0; font-size: 13px; color: var(--nc-muted); }
        .nc-toolbar { display: flex; gap: 10px; margin-top: 14px; flex-wrap: wrap; }
        .nc-filter {
            padding: 9px 12px; border-radius: 10px; border: 1px solid var(--nc-border);
            font-family: inherit; font-size: 13px; color: var(--nc-text); background: var(--nc-card);
            cursor: pointer; outline: none;
        }
        .nc-filter:focus, .nc-search:focus { border-color: var(--nc-primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, .12); }
        .nc-search {
            flex: 1; min-width: 220px; padding: 9px 14px; border-radius: 10px;
            border: 1px solid var(--nc-border); font-family: inherit; font-size: 13px;
            color: var(--nc-text); background: var(--nc-card); outline: none;
        }
        .nc-search::placeholder { color: #94a3b8; }

        /* Group labels */
        .nc-group {
            display: flex; align-items: center; gap: 8px;
            font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .08em;
            color: var(--nc-muted); margin: 24px 4px 10px;
        }
        .nc-group-count { background: #eef1f6; color: var(--nc-muted); border-radius: 999px; padding: 1px 9px; font-size: 11px; }

        /* Notification cards */
        .nc-card {
            display: flex; gap: 14px;
            background: var(--nc-card);
            border: 1px solid var(--nc-border);
            border-left: 4px solid #cbd5e1;
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(15, 23, 42, .05);
            transition: transform .18s ease, box-shadow .18s ease, opacity .2s ease;
            margin-bottom: 12px;
        }
        .nc-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(15, 23, 42, .10); }
        .nc-card.unread { background: #eff6ff; border-color: #dbeafe; }
        .nc-card.read { background: var(--nc-card); }
        .nc-card.hidden { display: none; }
        .nc-card[data-kind="report"]     { border-left-color: #f59e0b; }
        .nc-card[data-kind="progress"]   { border-left-color: #8b5cf6; }
        .nc-card[data-kind="assignment"] { border-left-color: #3b82f6; }
        .nc-card[data-kind="change"]     { border-left-color: #8b5cf6; }
        .nc-card[data-kind="approved"]   { border-left-color: #10b981; }
        .nc-card[data-kind="rejected"]   { border-left-color: #ef4444; }
        .nc-card[data-kind="review"]     { border-left-color: #f59e0b; }
        .nc-card[data-kind="request_outcome"] { border-left-color: #6366f1; }
        .nc-icon {
            width: 44px; height: 44px; border-radius: 12px; flex: 0 0 44px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: #fff; box-shadow: 0 3px 8px rgba(15, 23, 42, .18);
        }
        .nc-body { flex: 1; min-width: 0; }
        .nc-title-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .nc-card-title { font-weight: 600; font-size: 14.5px; color: var(--nc-text); line-height: 1.35; }
        .nc-time { font-size: 12px; color: var(--nc-muted); margin-top: 3px; }
        .nc-desc { font-size: 13px; color: #475569; margin-top: 6px; line-height: 1.5; }
        .nc-sub { font-size: 12.5px; color: var(--nc-muted); margin-top: 3px; line-height: 1.45; }
        .nc-meta { display: flex; align-items: center; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
        .nc-tag { font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 999px; white-space: nowrap; }

        .nc-report-tag { background: #f1f5f9; color: #475569; }
        .nc-st-pending   { background: #fef3c7; color: #b45309; }
        .nc-st-assigned  { background: #dbeafe; color: #1d4ed8; }
        .nc-st-progress  { background: #ffedd5; color: #c2410c; }
        .nc-st-completed { background: #d1fae5; color: #047857; }
        .nc-st-cancelled { background: #fee2e2; color: #b91c1c; }
        .nc-st-approved  { background: #d1fae5; color: #047857; }
        .nc-st-rejected  { background: #fee2e2; color: #b91c1c; }
        .nc-st-review    { background: #e0e7ff; color: #4338ca; }

        .nc-pr-high   { background: #fee2e2; color: #dc2626; }
        .nc-pr-critical { background: #fecaca; color: #b91c1c; }
        .nc-pr-medium { background: #ffedd5; color: #c2410c; }
        .nc-pr-low    { background: #d1fae5; color: #059669; }

        /* Actions */
        .nc-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .nc-btn {
            border: none; background: #f1f5f9; color: #475569; font-family: inherit;
            padding: 7px 13px; border-radius: 9px; font-size: 12.5px; font-weight: 500;
            cursor: pointer; transition: background .15s ease, color .15s ease;
            display: inline-flex; align-items: center; gap: 6px; text-decoration: none;
        }
        .nc-btn:hover { background: #e2e8f0; color: #1e293b; }
        .nc-btn:disabled { opacity: .5; cursor: not-allowed; }
        .nc-btn-primary { background: var(--nc-primary); color: #fff; }
        .nc-btn-primary:hover { background: #4338ca; color: #fff; }
        .nc-dismiss {
            background: none; border: none; color: #cbd5e1; cursor: pointer;
            font-size: 14px; padding: 6px; border-radius: 8px; line-height: 1;
        }
        .nc-dismiss:hover { color: #ef4444; background: #fee2e2; }

        /* Empty state */
        .nc-empty { text-align: center; padding: 72px 20px; color: #94a3b8; }
        .nc-empty > i { font-size: 64px; margin-bottom: 18px; color: #cbd5e1; }
        .nc-empty h2 { font-size: 18px; color: #334155; margin: 0 0 6px; font-weight: 600; }
        .nc-empty p { font-size: 13.5px; margin: 0 0 20px; }
        .nc-noresults { padding: 48px 20px; }
        .nc-noresults > i { font-size: 40px; }

        /* Dark mode */
        .dark-mode .nc-header, .dark-mode .nc-card, .dark-mode .nc-card.read,
        .dark-mode .nc-search, .dark-mode .nc-filter { background: #1e293b; border-color: #334155; }
        .dark-mode .nc-card.unread { background: #182338; border-color: #2b3a55; }
        .dark-mode .nc-card:hover { box-shadow: 0 10px 24px rgba(0, 0, 0, .45); }
        .dark-mode .nc-title h1, .dark-mode .nc-card-title { color: #e2e8f0; }
        .dark-mode .nc-desc { color: #94a3b8; }
        .dark-mode .nc-time, .dark-mode .nc-sub, .dark-mode .nc-header-sub,
        .dark-mode .nc-group, .dark-mode .nc-group-count { color: #94a3b8; }
        .dark-mode .nc-title-icon { background: #312e81; color: #c7d2fe; }
        .dark-mode .nc-btn { background: #334155; color: #cbd5e1; }
        .dark-mode .nc-btn:hover { background: #475569; color: #e2e8f0; }
        .dark-mode .nc-report-tag { background: #334155; color: #cbd5e1; }
        .dark-mode .nc-empty h2 { color: #e2e8f0; }
        .dark-mode .nc-empty > i { color: #475569; }
        .dark-mode .nc-dismiss { color: #64748b; }

        @media (max-width: 640px) {
            .main-content { padding: 18px 14px 50px; }
            .nc-actions { width: 100%; justify-content: flex-start; }
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <?php
    // ---- Presentation layer: assemble the unified notification feed from the
    //      data already fetched above. No queries or backend logic changed. ----
    $nc_today = date('Y-m-d');
    $nc_yesterday = date('Y-m-d', strtotime('-1 day'));

    function nc_group_key($dt, $today, $yesterday) {
        $t = strtotime($dt);
        $d = date('Y-m-d', $t);
        if ($d === $today) return 'today';
        if ($d === $yesterday) return 'yesterday';
        if ($t >= strtotime('-7 days')) return 'week';
        return 'older';
    }

    function nc_group_label($k) {
        return ['today' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This Week', 'older' => 'Older'][$k] ?? 'Older';
    }

    function nc_time_ago($datetime) {
        $diff = time() - strtotime($datetime);
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 7200) return '1 hr ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hrs ago';
        if ($diff < 172800) return 'Yesterday';
        if ($diff < 604800) return floor($diff / 86400) . ' days ago';
        return date('M d, Y', strtotime($datetime));
    }

    function nc_priority($p) {
        $p = strtolower(trim((string)$p));
        if ($p === '' || $p === 'none') return null;
        $class = in_array($p, ['high', 'medium', 'low', 'critical'], true) ? 'nc-pr-' . $p : 'nc-pr-medium';
        return ['label' => ucfirst($p), 'class' => $class];
    }

    $nc_feed = [];
    $nc_push = function ($item) use (&$nc_feed) { $nc_feed[] = $item; };

    if ($is_admin) {
        // Pending reports from departments
        foreach ($pending_reports as $report) {
            $src = notification_source_badge($report);
            $sub = trim(($report['location'] ?? '') . '  •  ' . ($report['reporter_name'] ?? ''));
            $nc_push([
                'id' => 'rep' . $report['id'],
                'ts' => $report['created_at'],
                'kind' => 'report',
                'icon' => 'fa-file',
                'color' => '#f59e0b',
                'title' => 'New report from ' . $src['label'],
                'desc' => $report['title'],
                'sub' => $sub,
                'report_id' => (string)($report['report_id'] ?? ''),
                'status' => ['label' => 'Pending', 'class' => 'nc-st-pending'],
                'priority' => nc_priority($report['priority'] ?? ''),
                'tags' => array_values(array_filter([$src['label'], !empty($report['department']) ? $report['department'] : null])),
                'unread' => true,
                'url' => notification_pending_report_focus_url($report),
                'url_label' => 'View Report',
                'mark' => ['url' => '', 'data' => ['action' => 'mark_read', 'type' => 'report', 'id' => (int)$report['id']]],
            ]);
        }

        // Pending change requests
        foreach ($pending_changes as $cr) {
            $req_data = json_decode($cr['requested_data'], true);
            $changes_list = [];
            if (!empty($req_data['email'])) $changes_list[] = 'Email: ' . $req_data['email'];
            if (!empty($req_data['address'])) $changes_list[] = 'Address: ' . $req_data['address'];
            if (!empty($req_data['civil_status'])) $changes_list[] = 'Status: ' . ucfirst($req_data['civil_status']);
            if (!empty($req_data['birthday'])) $changes_list[] = 'Birthday: ' . $req_data['birthday'];
            if (!empty($req_data['new_password'])) $changes_list[] = 'Password change requested';
            if (!empty($req_data['id_file_path'])) $changes_list[] = 'New ID photo uploaded';
            $nc_push([
                'id' => 'cr' . $cr['id'],
                'ts' => $cr['created_at'],
                'kind' => 'change',
                'icon' => 'fa-user-edit',
                'color' => '#8b5cf6',
                'title' => 'Change request from ' . ($cr['user_name'] ?? 'Staff'),
                'desc' => 'Requesting information update' . ($changes_list ? ': ' . implode(' • ', $changes_list) : ''),
                'sub' => !empty($cr['reason']) ? 'Reason: ' . $cr['reason'] : '',
                'report_id' => '',
                'status' => ['label' => 'Pending', 'class' => 'nc-st-pending'],
                'priority' => null,
                'tags' => ['Change Request'],
                'unread' => true,
                'url' => '../admin/account_approvals.php?cr_id=' . (int)$cr['id'],
                'url_label' => 'Review',
                'mark' => ['url' => '', 'data' => ['action' => 'mark_read_change', 'id' => (int)$cr['id']]],
            ]);
        }

        // Progress update notifications
        foreach ($progress_notifications as $pn) {
            $src = notification_source_badge($pn);
            $nc_push([
                'id' => 'pn' . $pn['id'],
                'ts' => $pn['created_at'],
                'kind' => 'progress',
                'icon' => 'fa-sync',
                'color' => '#8b5cf6',
                'title' => 'Status update · ' . ($pn['report_code'] ?? ('#' . $pn['report_id'])),
                'desc' => $pn['message'] . ($pn['report_title'] ? ' — ' . $pn['report_title'] : ''),
                'sub' => '',
                'report_id' => $pn['report_code'] ?? ('#' . $pn['report_id']),
                'status' => ['label' => 'In Progress', 'class' => 'nc-st-progress'],
                'priority' => null,
                'tags' => [$src['label']],
                'unread' => true,
                'url' => notification_progress_focus_url($pn),
                'url_label' => 'View Report',
                'mark' => null,
            ]);
        }

        // Assigned projects
        foreach ($assigned_projects as $ap) {
            $desc = $ap['report_title'] ?? '';
            if (!empty($ap['notes'])) $desc .= ($desc ? ' — ' : '') . 'Notes: ' . $ap['notes'];
            $nc_push([
                'id' => 'asg' . $ap['id'],
                'ts' => $ap['assigned_at'],
                'kind' => 'assignment',
                'icon' => 'fa-user',
                'color' => '#3b82f6',
                'title' => 'Project assigned · ' . ($ap['report_code'] ?? ('#' . $ap['report_id'])),
                'desc' => $desc ?: 'A project has been assigned to you.',
                'sub' => '',
                'report_id' => $ap['report_code'] ?? ('#' . $ap['report_id']),
                'status' => ['label' => 'Assigned', 'class' => 'nc-st-assigned'],
                'priority' => null,
                'tags' => ['User ID: ' . (int)($ap['user_id'] ?? 0)],
                'unread' => true,
                'url' => notification_assignment_url($ap),
                'url_label' => 'View Project',
                'mark' => null,
            ]);
        }
    } else {
        // Supervisors: completion/cancellation review requests
        if ($is_supervisor) {
            foreach ($review_requests as $rr) {
                // Resolve source for reports not found in road_transportation_reports
                // (e.g. CIMM reports live in cimm_verification_reports).
                if (empty($rr['report_code'])) {
                    $rr = resolve_progress_notification_source($rr);
                }
                $nc_push([
                    'id' => 'rq' . $rr['id'],
                    'ts' => $rr['created_at'],
                    'kind' => 'review',
                    'icon' => 'fa-clipboard-check',
                    'color' => '#f59e0b',
                    'title' => $rr['message'],
                    'desc' => ($rr['report_title'] ?? '') . (!empty($rr['report_category']) ? ' — Module: ' . ucfirst($rr['report_category']) : ''),
                    'sub' => '',
                    'report_id' => $rr['report_code'] ?? ('#' . $rr['report_id']),
                    'status' => ['label' => ucfirst($rr['type'] ?? 'request'), 'class' => ($rr['type'] ?? '') === 'completion' ? 'nc-st-completed' : 'nc-st-cancelled'],
                    'priority' => null,
                    'tags' => ['Review'],
                    'unread' => true,
                    'url' => notification_progress_focus_url($rr),
                    'url_label' => 'View Report',
                    'mark' => ['url' => '', 'data' => ['action' => 'mark_read', 'type' => 'review', 'id' => (int)$rr['id']]],
                ]);
            }

            // Supervisor action confirmations (Complete/Cancel results)
            foreach ($supervisor_actions as $sa) {
                // Resolve source for reports not found in road_transportation_reports.
                if (empty($sa['report_code'])) {
                    $sa = resolve_progress_notification_source($sa);
                }
                $nc_push([
                    'id' => 'sa' . $sa['id'],
                    'ts' => $sa['created_at'],
                    'kind' => 'action',
                    'icon' => 'fa-check-circle',
                    'color' => '#10b981',
                    'title' => $sa['message'],
                    'desc' => ($sa['report_title'] ?? '') . (!empty($sa['report_category']) ? ' — Module: ' . ucfirst($sa['report_category']) : ''),
                    'sub' => '',
                    'report_id' => $sa['report_code'] ?? ('#' . $sa['report_id']),
                    'status' => ['label' => ($sa['type'] === 'complete_report') ? 'Completed' : 'Cancelled', 'class' => ($sa['type'] === 'complete_report') ? 'nc-st-completed' : 'nc-st-cancelled'],
                    'priority' => null,
                    'tags' => ['Action Result'],
                    'unread' => true,
                    'url' => notification_progress_focus_url($sa),
                    'url_label' => 'View Report',
                    'mark' => ['url' => '', 'data' => ['action' => 'mark_read', 'type' => 'report_outcome', 'id' => (int)$sa['id']]],
                ]);
            }
        }

        // My report status updates
        foreach ($report_updates as $report) {
            $is_approved = ($report['status'] === 'approved' || $report['status'] === 'completed');
            $ts = $is_approved ? ($report['approved_at'] ?? $report['updated_at']) : ($report['rejected_at'] ?? $report['updated_at']);
            $nc_push([
                'id' => 'ru' . $report['id'],
                'ts' => $ts,
                'kind' => $is_approved ? 'approved' : 'rejected',
                'icon' => $is_approved ? 'fa-check-circle' : 'fa-times-circle',
                'color' => $is_approved ? '#10b981' : '#ef4444',
                'title' => 'Report ' . ($is_approved ? 'approved' : 'rejected') . ' · ' . $report['report_id'],
                'desc' => ($report['title'] ?? '') . ' was ' . ($is_approved ? 'approved' : 'rejected') . '.',
                'sub' => !empty($report['location']) ? 'Location: ' . $report['location'] : '',
                'report_id' => (string)$report['report_id'],
                'status' => ['label' => $is_approved ? 'Completed' : 'Cancelled', 'class' => $is_approved ? 'nc-st-completed' : 'nc-st-cancelled'],
                'priority' => null,
                'tags' => ['Submitted ' . date('M d, Y', strtotime($report['created_at']))],
                'unread' => true,
                'url' => '',
                'url_label' => '',
                'mark' => null,
            ]);
        }

        // Review request outcomes (approve/reject) — targeted to the officer by email
        foreach ($request_outcomes as $ro) {
            $is_approved = ($ro['type'] === 'approve_request');
            $nc_push([
                'id' => 'ro' . $ro['id'],
                'ts' => $ro['created_at'],
                'kind' => 'request_outcome',
                'icon' => $is_approved ? 'fa-check-circle' : 'fa-times-circle',
                'color' => $is_approved ? '#10b981' : '#ef4444',
                'title' => 'Request ' . ($is_approved ? 'approved' : 'rejected') . ' · ' . ($ro['report_code'] ?? ('#' . $ro['report_id'])),
                'desc' => $ro['message'] ?? '',
                'sub' => '',
                'report_id' => (string)($ro['report_code'] ?? ('#' . $ro['report_id'])),
                'status' => ['label' => $is_approved ? 'Approved' : 'Rejected', 'class' => $is_approved ? 'nc-st-completed' : 'nc-st-cancelled'],
                'priority' => null,
                'tags' => [$is_approved ? 'Approved' : 'Rejected'],
                'unread' => true,
                'url' => '../lgu/officer_archive.php?focus_report_id=' . (int)($ro['report_id'] ?? 0),
                'url_label' => 'View Report',
                'mark' => ['url' => '', 'data' => ['action' => 'mark_read', 'type' => 'report_outcome', 'id' => (int)$ro['id']]],
            ]);
        }

        // My assigned projects
        foreach ($assigned_projects as $ap) {
            $desc = $ap['report_title'] ?? '';
            if (!empty($ap['notes'])) $desc .= ($desc ? ' — ' : '') . 'Notes: ' . $ap['notes'];
            $nc_push([
                'id' => 'asg' . $ap['id'],
                'ts' => $ap['assigned_at'],
                'kind' => 'assignment',
                'icon' => 'fa-user',
                'color' => '#3b82f6',
                'title' => 'Project assigned · ' . ($ap['report_code'] ?? ('#' . $ap['report_id'])),
                'desc' => $desc ?: 'A project has been assigned to you.',
                'sub' => '',
                'report_id' => $ap['report_code'] ?? ('#' . $ap['report_id']),
                'status' => ['label' => 'Assigned', 'class' => 'nc-st-assigned'],
                'priority' => null,
                'tags' => ['Assigned ' . date('M d, Y', strtotime($ap['assigned_at']))],
                'unread' => true,
                'url' => notification_assignment_url($ap),
                'url_label' => 'View Project',
                'mark' => null,
            ]);
        }

        // My change request status updates
        foreach ($staff_updates as $su) {
            $ok = ($su['status'] === 'approved');
            $nc_push([
                'id' => 'su' . $su['id'],
                'ts' => $su['created_at'],
                'kind' => 'change',
                'icon' => $ok ? 'fa-check-circle' : 'fa-times-circle',
                'color' => $ok ? '#10b981' : '#ef4444',
                'title' => 'Change request ' . ($ok ? 'approved' : 'rejected'),
                'desc' => $su['admin_notes'] ?: 'No additional notes from the admin.',
                'sub' => '',
                'report_id' => '',
                'status' => ['label' => $ok ? 'Approved' : 'Rejected', 'class' => $ok ? 'nc-st-completed' : 'nc-st-cancelled'],
                'priority' => null,
                'tags' => ['Change Request'],
                'unread' => true,
                'url' => '',
                'url_label' => '',
                'mark' => null,
            ]);
        }
    }

    usort($nc_feed, function ($a, $b) { return strtotime($b['ts']) - strtotime($a['ts']); });

    $nc_groups = ['today' => [], 'yesterday' => [], 'week' => [], 'older' => []];
    foreach ($nc_feed as $item) {
        $nc_groups[nc_group_key($item['ts'], $nc_today, $nc_yesterday)][] = $item;
    }
    $nc_total = count($nc_feed);
    ?>

    <div class="main-content">
        <!-- Compact header -->
        <div class="nc-header">
            <div class="nc-header-top">
                <div class="nc-title">
                    <div class="nc-title-icon"><i class="fas fa-bell"></i></div>
                    <h1>Notifications</h1>
                    <span class="nc-unread-badge" id="ncUnreadBadge" <?php echo $nc_total ? '' : 'style="display:none;"'; ?>><?php echo $nc_total; ?></span>
                </div>
                <div class="nc-header-actions">
                    <button class="nc-btn nc-btn-primary" id="ncMarkAll" onclick="ncMarkAllRead()" <?php echo $nc_total ? '' : 'disabled'; ?>>
                        <i class="fas fa-check-double"></i> Mark All as Read
                    </button>
                </div>
            </div>
            <p class="nc-header-sub"><?php echo $is_admin ? 'Reports from other departments and staff change requests' : 'Updates on your submitted reports and change requests'; ?></p>
            <div class="nc-toolbar">
                <select id="ncFilter" class="nc-filter" onchange="ncApplyFilters()">
                    <option value="all">All</option>
                    <option value="unread">Unread</option>
                    <?php if ($is_trans_supervisor): ?>
                        <option value="change_request">Change Requests</option>
                        <option value="report_update">Report Updates</option>
                    <?php else: ?>
                        <option value="assigned">Assigned reports</option>
                        <option value="report_update">Report Updates</option>
                        <option value="request_outcome">Request Outcomes</option>
                        <option value="change_request">Change Requests</option>
                    <?php endif; ?>
                </select>
                <input type="text" id="ncSearch" class="nc-search" placeholder="Search notifications..." oninput="ncApplyFilters()">
            </div>
        </div>

        <?php if ($nc_total === 0): ?>
            <!-- Empty state -->
            <div class="nc-empty">
                <i class="fas fa-bell"></i>
                <h2>You're all caught up!</h2>
                <p>No new notifications.</p>
                <button class="nc-btn" onclick="location.reload()"><i class="fas fa-rotate-right"></i> Refresh</button>
            </div>
        <?php else: ?>
            <div class="nc-empty nc-noresults" id="ncNoResults" style="display:none;">
                <i class="fas fa-filter"></i>
                <h2>No matching notifications</h2>
                <p>Try a different filter or search term.</p>
            </div>

            <?php foreach (['today', 'yesterday', 'week', 'older'] as $gk): ?>
                <?php if (!empty($nc_groups[$gk])): ?>
                    <div class="nc-group-wrap">
                        <div class="nc-group">
                            <span><?php echo nc_group_label($gk); ?></span>
                            <span class="nc-group-count"><?php echo count($nc_groups[$gk]); ?></span>
                        </div>
                        <?php foreach ($nc_groups[$gk] as $item): ?>
                            <?php
                                $search_text = strtolower(trim(implode(' ', array_filter([$item['title'], $item['desc'], $item['sub'], $item['report_id']]))));
                                $mark_payload = $item['mark'] ? json_encode($item['mark']['data']) : '';
                            ?>
                            <div class="nc-card unread" data-kind="<?php echo $item['kind']; ?>" data-unread="true" data-search="<?php echo htmlspecialchars($search_text); ?>">
                                <div class="nc-icon" style="background: <?php echo $item['color']; ?>;"><i class="fas <?php echo $item['icon']; ?>"></i></div>
                                <div class="nc-body">
                                    <div class="nc-title-row">
                                        <div>
                                            <div class="nc-card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                                            <div class="nc-time"><?php echo nc_time_ago($item['ts']); ?></div>
                                        </div>
                                        <div class="nc-actions">
                                            <?php if ($item['url']): ?>
                                                <a class="nc-btn nc-btn-primary" href="<?php echo $item['url']; ?>" target="_parent"><i class="fas fa-external-link-alt"></i> <?php echo $item['url_label']; ?></a>
                                            <?php endif; ?>
                                            <?php if ($item['mark']): ?>
                                                <button class="nc-btn nc-mark" data-mark-url="<?php echo $item['mark']['url']; ?>" data-mark-payload='<?php echo $mark_payload; ?>' onclick="ncMarkRead(this)"><i class="fas fa-check"></i> Mark as read</button>
                                            <?php endif; ?>
                                            <button class="nc-dismiss" title="Dismiss" onclick="ncDismiss(this)"><i class="fas fa-xmark"></i></button>
                                        </div>
                                    </div>
                                    <div class="nc-desc"><?php echo htmlspecialchars($item['desc']); ?></div>
                                    <?php if ($item['sub']): ?><div class="nc-sub"><?php echo htmlspecialchars($item['sub']); ?></div><?php endif; ?>
                                    <div class="nc-meta">
                                        <?php if ($item['report_id'] !== ''): ?><span class="nc-tag nc-report-tag"># <?php echo htmlspecialchars($item['report_id']); ?></span><?php endif; ?>
                                        <?php if ($item['status']): ?><span class="nc-tag <?php echo $item['status']['class']; ?>"><?php echo $item['status']['label']; ?></span><?php endif; ?>
                                        <?php if ($item['priority']): ?>
                                            <span class="nc-tag <?php echo $item['priority']['class']; ?>"><?php echo strtolower($item['priority']['label']) === 'high' ? '<i class="fas fa-exclamation-triangle"></i> ' : ''; ?><?php echo $item['priority']['label']; ?></span>
                                        <?php endif; ?>
                                        <?php foreach ($item['tags'] as $t): ?><span class="nc-tag nc-report-tag"><?php echo htmlspecialchars($t); ?></span><?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script>
        const NC_IS_ADMIN = <?php echo $is_admin ? 'true' : 'false'; ?>;
        const NC_IS_TRANS_SUPERVISOR = <?php echo $is_trans_supervisor ? 'true' : 'false'; ?>;
        const NC_IS_TRANS_OFFICER = <?php echo $is_trans_officer ? 'true' : 'false'; ?>;
        // Role-specific filter maps so each filter shows exactly the kinds of
        // notifications that role can receive. Non-transportation roles keep
        // the existing definitions.
        let NC_FILTERS;
        if (NC_IS_TRANS_SUPERVISOR) {
            NC_FILTERS = {
                all: ['review', 'action', 'approved', 'rejected', 'assignment', 'change', 'request_outcome'],
                unread: null,
                report_update: ['review', 'action', 'approved', 'rejected'],
                change_request: ['change']
            };
        } else if (NC_IS_TRANS_OFFICER) {
            NC_FILTERS = {
                all: ['assignment', 'approved', 'rejected', 'change', 'request_outcome'],
                unread: null,
                assigned: ['assignment'],
                report_update: ['approved', 'rejected'],
                request_outcome: ['request_outcome'],
                change_request: ['change']
            };
        } else {
            NC_FILTERS = {
                all: ['report', 'progress', 'assignment', 'approved', 'rejected', 'review', 'change', 'request_outcome'],
                unread: null,
                assigned: ['assignment'],
                report_update: ['report', 'progress', 'approved', 'rejected', 'review'],
                request_outcome: ['request_outcome'],
                change_request: ['change']
            };
        }

        function ncRefreshBadge() {
            var n = document.querySelectorAll('.nc-card[data-unread="true"]').length;
            var badge = document.getElementById('ncUnreadBadge');
            var btn = document.getElementById('ncMarkAll');
            if (badge) { badge.textContent = n; badge.style.display = n ? '' : 'none'; }
            if (btn) btn.disabled = n === 0;
        }

        // Keep the sidebar notification badge in sync when a single
        // notification is marked as read: decrement it immediately instead of
        // waiting for the next page reload. Hidden entirely at zero.
        function ncRefreshSidebarBadge() {
            var badge = document.querySelector('.nav-link .notification-badge');
            if (!badge) return;
            var n = parseInt(badge.textContent, 10) || 0;
            n = Math.max(0, n - 1);
            if (n === 0) {
                badge.remove();
            } else {
                badge.textContent = n;
                badge.setAttribute('aria-label', n + ' unread notifications');
            }
        }

        function ncApplyFilters() {
            var f = document.getElementById('ncFilter').value;
            var q = document.getElementById('ncSearch').value.toLowerCase();
            var kinds = NC_FILTERS[f];
            var totalVisible = 0;
            document.querySelectorAll('.nc-card').forEach(function (card) {
                var visible = true;
                if (kinds && kinds.indexOf(card.dataset.kind) === -1) visible = false;
                if (visible && f === 'unread' && card.dataset.unread !== 'true') visible = false;
                if (visible && q && card.dataset.search.indexOf(q) === -1) visible = false;
                card.classList.toggle('hidden', !visible);
                if (visible) totalVisible++;
            });
            document.querySelectorAll('.nc-group-wrap').forEach(function (w) {
                w.style.display = w.querySelectorAll('.nc-card:not(.hidden)').length ? '' : 'none';
            });
            var nr = document.getElementById('ncNoResults');
            if (nr) nr.style.display = totalVisible ? 'none' : '';
        }

        function ncMarkRead(btn) {
            var card = btn.closest('.nc-card');
            var url = btn.dataset.markUrl || '';
            var payload = btn.dataset.markPayload || '';
            if (payload) {
                fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: payload
                }).catch(function () {});
            }
            card.dataset.unread = 'false';
            card.classList.remove('unread');
            card.classList.add('read');
            btn.remove();
            ncRefreshBadge();
            ncRefreshSidebarBadge();
            ncApplyFilters();
        }

        function ncMarkAllRead() {
            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            }).catch(function () {});
            document.querySelectorAll('.nc-card').forEach(function (card) {
                card.dataset.unread = 'false';
                card.classList.remove('unread');
                card.classList.add('read');
            });
            document.querySelectorAll('.nc-mark').forEach(function (b) { b.remove(); });
            ncRefreshBadge();
            ncApplyFilters();
            // Reset the sidebar notification badge as well.
            var sidebarBadge = document.querySelector('.nav-link .notification-badge');
            if (sidebarBadge) sidebarBadge.remove();
        }

        function ncDismiss(el) {
            var card = el.closest('.nc-card');
            card.style.transition = 'opacity .2s ease, transform .2s ease';
            card.style.opacity = '0';
            card.style.transform = 'translateX(12px)';
            setTimeout(function () {
                card.remove();
                ncRefreshBadge();
                ncApplyFilters();
            }, 200);
        }

        ncRefreshBadge();
    </script>
</body>
</html>

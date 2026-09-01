<?php
/**
 * Sidebar Navigation Include
 * 
 * This file is included directly in each page (replaces the iframe approach).
 * 
 * Required before include:
 *   - Session must be started
 *   - $conn must be available (DB connection)
 *   - $_SESSION['user_id'] must be set
 * 
 * Optional before include:
 *   - $current_page: filename of the current page (for active state detection)
 */

// Ensure session is available
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config and functions if not already loaded
if (!isset($conn)) {
    require_once __DIR__ . '/config.php';
}
if (!function_exists('is_logged_in')) {
    require_once __DIR__ . '/functions.php';
}
require_once __DIR__ . '/notification_badge.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . rgmap_url('login'));
    exit();
}

// Force password change before accessing any protected page. Users with
// must_change_password = 1 may only access change_password.php until they
// replace their temporary password.
if ($conn && isset($_SESSION['user_id'])) {
    $mcp = $conn->prepare("SELECT must_change_password FROM users WHERE id = ?");
    $mcp->bind_param("i", $_SESSION['user_id']);
    $mcp->execute();
    $mcp_row = $mcp->get_result()->fetch_assoc();
    $mcp->close();
    if ($mcp_row && !empty($mcp_row['must_change_password'])) {
        header('Location: ' . rgmap_url('change-password'));
        exit();
    }
}

// Get user info
function getSidebarUserInfo() {
    global $conn;
    if ($conn && isset($_SESSION['user_id'])) {
        $stmt = $conn->prepare("SELECT username, full_name, email, role, profile_picture FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    return [
        'username' => 'Staff User',
        'full_name' => 'LGU Staff',
        'email' => 'staff@lgu.gov.ph',
        'role' => 'lgu_staff',
        'profile_picture' => null
    ];
}

// Get portal title based on role
function getPortalTitle($role) {
    $portal_titles = [
        'system_admin' => 'Admin Portal',
        'road_ops_supervisor' => 'Road Supervisor Portal',
        'trans_ops_supervisor' => 'Transportation Supervisor Portal',
        'road_monitoring_officer' => 'Road Monitoring Portal',
        'trans_monitoring_officer' => 'Transportation Monitoring Portal'
    ];
    return $portal_titles[$role] ?? 'Portal';
}

// Mirrors the unread-count logic of pages/shared/notifications.php for the
// two transportation roles. That page builds its badge (count($nc_feed)) from
// several panels; this sums the same panels so the sidebar badge always matches
// what the Notifications page shows for the logged-in user.
function getNotificationsFeedCount($conn, $user_role, $user_id, $email) {
    $count = 0;

    $is_trans_supervisor = ($user_role === 'trans_ops_supervisor');
    $is_trans_role = $is_trans_supervisor || ($user_role === 'trans_monitoring_officer');

    // "Mark All as Read" records the ids of the always-on cards (assignments,
    // report status updates, change-request outcomes) as read in the session
    // (see pages/shared/notifications.php). Those stay visible on the page but
    // are not counted, so exclude them here to keep the badge in sync.
    $read = $_SESSION['nc_read'][(int)$user_id] ?? [];

    // Trans monitoring officers persist the always-on card keys (asg/ru/su) in
    // report_notifications (type 'always_on_read') so they survive logout/login.
    // Merge them here so the sidebar badge matches the Notifications page's
    // merged read-set after a fresh login, when the session is empty.
    if ($user_role === 'trans_monitoring_officer' && $email !== '') {
        try {
            $stmt = $conn->prepare("SELECT message FROM report_notifications WHERE type = 'always_on_read' AND recipient_email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($rows as $r) { $read[] = (string)$r['message']; }
        } catch (Exception $e) {}
    }

    // Cards dismissed with the X button (see notifications.php) are removed
    // from the page feed entirely, so mirror that here for the badge too.
    $dismissed_map = [];
    foreach (($_SESSION['nc_dismissed'][(int)$user_id] ?? []) as $k) { $dismissed_map[(string)$k] = true; }

    // Reports I submitted that were completed/cancelled (status updates).
    // The Notifications page narrows this to transportation for both trans
    // roles, so we always apply that scope here. Each panel caps at 20 items
    // (the page fetches LIMIT 20 per panel), so the count is capped too.
    $stmt = $conn->prepare("
        SELECT id FROM road_transportation_reports
        WHERE created_by = ? AND status IN ('completed', 'cancelled')
          AND report_category = 'transportation'
          AND report_type != 'infrastructure_issue'
        LIMIT 20
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $read_ru = [];
    foreach ($read as $k) { if (strncmp((string)$k, 'ru', 2) === 0) $read_ru[(int)substr((string)$k, 2)] = true; }
    foreach ($rows as $r) { if (!isset($read_ru[(int)$r['id']]) && !isset($dismissed_map['ru' . $r['id']])) $count++; }

    // Review-request outcomes (approve/reject) routed to my email. On the
    // Notifications page the existence check is narrowed to transportation
    // rows only for trans officers; trans supervisors use all tables.
    if ($email !== '') {
        if ($is_trans_supervisor) {
            $ro_exists = "SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                          UNION ALL
                          SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                          UNION ALL
                          SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id";
        } else {
            $ro_exists = "SELECT 1 FROM road_transportation_reports
                          WHERE id = rn.report_id
                            AND report_category = 'transportation'
                            AND report_type != 'infrastructure_issue'";
        }
        $stmt = $conn->prepare("
            SELECT rn.id FROM report_notifications rn
            WHERE rn.recipient_email = ? AND rn.type IN ('approve_request','reject_request')
              AND rn.is_read = 0
              AND EXISTS (" . $ro_exists . " LIMIT 1)
            LIMIT 20
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) { if (!isset($dismissed_map['ro' . $r['id']])) $count++; }
    }

    // My assigned projects
    $stmt = $conn->prepare("
        SELECT ra.id FROM report_assignments ra
        WHERE ra.user_id = ? AND ra.status = 'active'
        LIMIT 20
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $read_asg = [];
    foreach ($read as $k) { if (strncmp((string)$k, 'asg', 3) === 0) $read_asg[(int)substr((string)$k, 3)] = true; }
    foreach ($rows as $r) { if (!isset($read_asg[(int)$r['id']]) && !isset($dismissed_map['asg' . $r['id']])) $count++; }

    // My change-request status updates
    $stmt = $conn->prepare("
        SELECT id FROM change_requests
        WHERE user_id = ? AND status != 'pending'
        LIMIT 20
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $read_su = [];
    foreach ($read as $k) { if (strncmp((string)$k, 'su', 2) === 0) $read_su[(int)substr((string)$k, 2)] = true; }
    foreach ($rows as $r) { if (!isset($read_su[(int)$r['id']]) && !isset($dismissed_map['su' . $r['id']])) $count++; }

    // Supervisors: completion/cancellation requests routed to MY assignments
    // only, confirmations for actions I performed, and newly approved reports
    // for my module.
    if ($is_trans_supervisor) {
        $trans_exists = "SELECT 1 FROM road_transportation_reports
                         WHERE id = rn.report_id
                           AND report_category = 'transportation'
                           AND report_type != 'infrastructure_issue'";
        $assign_gate = "(
            rn.recipient_email = ?
            OR (
                rn.recipient_email REGEXP '^[0-9]+$'
                AND EXISTS (
                    SELECT 1 FROM report_assignments ra
                    WHERE ra.report_id = rn.report_id
                      AND ra.assigned_by = ?
                      AND ra.user_id = CAST(rn.recipient_email AS UNSIGNED)
                      AND ra.status = 'active'
                )
            )
            OR (
                COALESCE(rn.update_id, 0) > 0
                AND EXISTS (
                    SELECT 1 FROM report_assignments ra
                    WHERE ra.report_id = rn.report_id
                      AND ra.assigned_by = ?
                      AND ra.user_id = rn.update_id
                      AND ra.status = 'active'
                )
            )
        )";
        $stmt = $conn->prepare("
            SELECT rn.id FROM report_notifications rn
            WHERE rn.is_read = 0 AND rn.recipient_role = ?
              AND rn.type IN ('completion', 'cancellation')
              AND {$assign_gate}
              AND EXISTS (" . $trans_exists . " LIMIT 1)
            LIMIT 20
        ");
        $stmt->bind_param("ssii", $user_role, $email, $user_id, $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) { if (!isset($dismissed_map['rq' . $r['id']])) $count++; }

        if ($email !== '') {
            $stmt = $conn->prepare("
                SELECT rn.id FROM report_notifications rn
                WHERE rn.is_read = 0 AND rn.recipient_email = ?
                  AND rn.type IN ('complete_report', 'cancel_report')
                  AND EXISTS (" . $trans_exists . " LIMIT 1)
                LIMIT 20
            ");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($rows as $r) { if (!isset($dismissed_map['sa' . $r['id']])) $count++; }
        }

        $stmt = $conn->prepare("
            SELECT rn.id FROM report_notifications rn
            WHERE rn.is_read = 0 AND rn.recipient_role = ?
              AND rn.type = 'report_approved'
              AND EXISTS (" . $trans_exists . " LIMIT 1)
            LIMIT 20
        ");
        $stmt->bind_param("s", $user_role);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) { if (!isset($dismissed_map['ra' . $r['id']])) $count++; }

        // The admin's decision on a Transparency Upload Request this supervisor
        // submitted (see the 'tro' cards on the Notifications page). Matched
        // through the request id stored in update_id so only their own requests
        // count. A missing request table just means no cards.
        if ($email !== '') {
            try {
                $has_tur = $conn->query("SHOW TABLES LIKE 'transparency_upload_requests'");
                if ($has_tur && $has_tur->num_rows > 0) {
                    $stmt = $conn->prepare("
                        SELECT rn.id FROM report_notifications rn
                        JOIN transparency_upload_requests tr
                          ON tr.id = rn.update_id AND tr.requested_by = ?
                        WHERE rn.is_read = 0 AND rn.recipient_email = ?
                          AND rn.type IN ('transparency_approved', 'transparency_rejected')
                        LIMIT 20
                    ");
                    $stmt->bind_param("is", $user_id, $email);
                    $stmt->execute();
                    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    foreach ($rows as $r) { if (!isset($dismissed_map['tro' . $r['id']])) $count++; }
                }
            } catch (Exception $e) {}
        }
    }

    // 10-day no-progress-update alerts addressed to this transportation account.
    if ($email !== '' && $is_trans_role) {
        $stale_exists = "SELECT 1 FROM road_transportation_reports
                         WHERE id = rn.report_id
                           AND report_category = 'transportation'
                           AND report_type != 'infrastructure_issue'";
        try {
            $stmt = $conn->prepare("
                SELECT rn.id FROM report_notifications rn
                WHERE rn.is_read = 0 AND rn.recipient_email = ?
                  AND rn.type = 'no_update_stale'
                  AND EXISTS (" . $stale_exists . " LIMIT 1)
                LIMIT 20
            ");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($rows as $r) { if (!isset($dismissed_map['stu' . $r['id']])) $count++; }
        } catch (Exception $e) {}
    }

    return $count;
}

// Get notification count
function getSidebarNotificationCount($user_role = '', $user_id = 0) {
    global $conn;
    $count = 0;
    if ($conn) {
        dispatch_no_update_stale_notifications($conn);
        // Email of the logged-in user — used to match notifications that
        // are targeted to an individual (request outcomes, own action
        // results) rather than to a whole role.
        $email = '';
        if ($user_id > 0) {
            try {
                $estmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
                $estmt->bind_param("i", $user_id);
                $estmt->execute();
                $erow = $estmt->get_result()->fetch_assoc();
                $estmt->close();
                $email = $erow['email'] ?? '';
            } catch (Exception $e) {}
        }

        // Transportation Monitoring Officers and Transportation Operations
        // Supervisors: the sidebar badge must show exactly the same unread
        // count as their Notifications page (pages/shared/notifications.php),
        // which builds its badge from several panels. Mirror that feed count.
        if (in_array($user_role, ['trans_monitoring_officer', 'trans_ops_supervisor'], true)) {
            try {
                return getNotificationsFeedCount($conn, $user_role, $user_id, $email);
            } catch (Exception $e) {
                return 0;
            }
        }

        // Road Monitoring Officers: the sidebar badge must show exactly the
        // same unread count as their Notifications page. Panels: change-request
        // updates (su), review-request outcomes (ro), direct assigned
        // complete/cancel notices (sa), active assignments (asg), and stale
        // progress alerts — each capped at 20. Cards dismissed with the X
        // button or marked read are excluded.
        if ($user_role === 'road_monitoring_officer') {
            try {
                $dismissed = [];
                foreach (($_SESSION['nc_dismissed'][(int)$user_id] ?? []) as $k) {
                    $dismissed[(string)$k] = true;
                }
                $read = $_SESSION['nc_read'][(int)$user_id] ?? [];
                // Merge always_on_read markers so Mark All as Read survives
                // logout/login (same pattern as transportation officers).
                if ($email !== '') {
                    try {
                        $stmt = $conn->prepare("SELECT message FROM report_notifications WHERE type = 'always_on_read' AND recipient_email = ?");
                        $stmt->bind_param("s", $email);
                        $stmt->execute();
                        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $stmt->close();
                        foreach ($rows as $r) { $read[] = (string)$r['message']; }
                    } catch (Exception $e) {}
                }
                $read_map = [];
                foreach ($read as $k) { $read_map[(string)$k] = true; }

                // Review-request outcomes (approve/reject) routed to my email.
                if ($email !== '') {
                    $ro_exists = "SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM ipms_road_projects WHERE project_id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM road_transportation_reports_archive WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM cimm_verification_reports_archive WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM ipms_road_projects_archive WHERE project_id = rn.report_id";
                    $stmt = $conn->prepare("
                        SELECT rn.id FROM report_notifications rn
                        WHERE rn.recipient_email = ? AND rn.type IN ('approve_request','reject_request')
                          AND rn.is_read = 0
                          AND EXISTS (" . $ro_exists . " LIMIT 1)
                        LIMIT 20
                    ");
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    foreach ($rows as $r) { if (!isset($dismissed['ro' . $r['id']])) $count++; }
                }

                // Direct complete/cancel of reports assigned to me.
                if ($email !== '') {
                    $as_exists = "SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM ipms_road_projects WHERE project_id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM road_transportation_reports_archive WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM cimm_verification_reports_archive WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM ipms_road_projects_archive WHERE project_id = rn.report_id";
                    $stmt = $conn->prepare("
                        SELECT rn.id FROM report_notifications rn
                        WHERE rn.recipient_email = ?
                          AND rn.type IN ('complete_report', 'cancel_report')
                          AND rn.is_read = 0
                          AND rn.recipient_role = 'road_monitoring_officer'
                          AND EXISTS (" . $as_exists . " LIMIT 1)
                        LIMIT 20
                    ");
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    foreach ($rows as $r) { if (!isset($dismissed['sa' . $r['id']])) $count++; }
                }

                // My active project assignments.
                $stmt = $conn->prepare("
                    SELECT id FROM report_assignments
                    WHERE user_id = ? AND status = 'active'
                    LIMIT 20
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                foreach ($rows as $r) {
                    $key = 'asg' . $r['id'];
                    if (!isset($dismissed[$key]) && !isset($read_map[$key])) $count++;
                }

                // My change-request status updates.
                $stmt = $conn->prepare("
                    SELECT id FROM change_requests
                    WHERE user_id = ? AND status != 'pending'
                    LIMIT 20
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                foreach ($rows as $r) {
                    $key = 'su' . $r['id'];
                    if (!isset($dismissed[$key]) && !isset($read_map[$key])) $count++;
                }

                if ($email !== '') {
                    $stale_exists = "SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                                     UNION ALL
                                     SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                                     UNION ALL
                                     SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id";
                    $stmt = $conn->prepare("
                        SELECT rn.id FROM report_notifications rn
                        WHERE rn.is_read = 0 AND rn.recipient_email = ?
                          AND rn.type = 'no_update_stale'
                          AND EXISTS (" . $stale_exists . " LIMIT 1)
                        LIMIT 20
                    ");
                    $stmt->bind_param("s", $email);
                    $stmt->execute();
                    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    foreach ($rows as $r) { if (!isset($dismissed['stu' . $r['id']])) $count++; }
                }

                return $count;
            } catch (Exception $e) {
                return $count;
            }
        }

        // Only count unread report_notifications that reference a report
        // that still exists in one of the live tables.
        try {
            if (in_array($user_role, ['road_ops_supervisor', 'trans_ops_supervisor'], true)) {
                // Supervisors: count only the unread notifications that
                // actually appear in their notifications feed — review requests
                // for reports THEY assigned, newly approved module reports, and
                // results targeted to their email. Never count another peer
                // supervisor's completion/cancellation inbox.
                if ($user_role === 'trans_ops_supervisor') {
                    // Transportation supervisors only ever receive notifications
                    // for transportation reports, so the existence check is
                    // narrowed to transportation rows — never CIMM, road or
                    // maintenance reports.
                    $exists = "SELECT 1 FROM road_transportation_reports
                               WHERE id = rn.report_id
                                 AND report_category = 'transportation'
                                 AND report_type != 'infrastructure_issue'";
                } else {
                    $exists = "SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                               UNION ALL
                               SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                               UNION ALL
                               SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id
                               UNION ALL
                               SELECT 1 FROM ipms_road_projects WHERE project_id = rn.report_id";
                }
                $assign_gate = "(
                    rn.recipient_email = ?
                    OR (
                        rn.recipient_email REGEXP '^[0-9]+$'
                        AND EXISTS (
                            SELECT 1 FROM report_assignments ra
                            WHERE ra.report_id = rn.report_id
                              AND ra.assigned_by = ?
                              AND ra.user_id = CAST(rn.recipient_email AS UNSIGNED)
                              AND ra.status = 'active'
                        )
                    )
                    OR (
                        COALESCE(rn.update_id, 0) > 0
                        AND EXISTS (
                            SELECT 1 FROM report_assignments ra
                            WHERE ra.report_id = rn.report_id
                              AND ra.assigned_by = ?
                              AND ra.user_id = rn.update_id
                              AND ra.status = 'active'
                        )
                    )
                )";
                $stmt = $conn->prepare("
                    SELECT rn.id, rn.type FROM report_notifications rn
                    WHERE rn.is_read = 0
                      AND (
                          (rn.recipient_role = ? AND rn.type IN ('completion', 'cancellation') AND {$assign_gate})
                          OR (rn.recipient_role = ? AND rn.type = 'report_approved')
                          OR (rn.recipient_email = ? AND rn.type IN ('approve_request', 'reject_request', 'complete_report', 'cancel_report', 'no_update_stale'))
                      )
                      AND EXISTS (" . $exists . " LIMIT 1)
                    LIMIT 50
                ");
                $stmt->bind_param("ssiiss", $user_role, $email, $user_id, $user_id, $user_role, $email);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                // Cards this Road Operations Supervisor dismissed with the X
                // button stay hidden on the Notifications page for this session,
                // so exclude the same feed ids here to keep the sidebar badge in
                // sync.
                $dismissed = [];
                foreach (($_SESSION['nc_dismissed'][(int)$user_id] ?? []) as $k) {
                    $dismissed[(string)$k] = true;
                }
                $read_su = [];
                foreach (($_SESSION['nc_read'][(int)$user_id] ?? []) as $k) {
                    if (strncmp((string)$k, 'su', 2) === 0) {
                        $read_su[(int)substr((string)$k, 2)] = true;
                    }
                }
                foreach ($rows as $row) {
                    $id = $row['id'];
                    $type = (string)($row['type'] ?? '');
                    $prefix = 'rq';
                    if ($type === 'report_approved') $prefix = 'ra';
                    elseif (in_array($type, ['complete_report', 'cancel_report'], true)) $prefix = 'sa';
                    elseif (in_array($type, ['approve_request', 'reject_request'], true)) $prefix = 'ro';
                    elseif ($type === 'no_update_stale') $prefix = 'stu';
                    if (!isset($dismissed[$prefix . $id])) {
                        $count++;
                    }
                }

                // Staff information change request outcomes for this supervisor.
                try {
                    $stmt = $conn->prepare("
                        SELECT id FROM change_requests
                        WHERE user_id = ? AND status != 'pending'
                        LIMIT 20
                    ");
                    $stmt->bind_param("i", $user_id);
                    $stmt->execute();
                    $cr_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    foreach ($cr_rows as $cr) {
                        $sid = (int)$cr['id'];
                        if (!isset($read_su[$sid]) && !isset($dismissed['su' . $sid])) {
                            $count++;
                        }
                    }
                } catch (Exception $e) {}

                // The admin's decision on a Transparency Upload Request this
                // supervisor submitted ('tro' cards on the Notifications page).
                // Counted on its own because these notices belong to a request
                // rather than to a report row, so they are matched through the
                // request id kept in update_id.
                if ($email !== '') {
                    try {
                        $has_tur = $conn->query("SHOW TABLES LIKE 'transparency_upload_requests'");
                        if ($has_tur && $has_tur->num_rows > 0) {
                            $stmt = $conn->prepare("
                                SELECT rn.id FROM report_notifications rn
                                JOIN transparency_upload_requests tr
                                  ON tr.id = rn.update_id AND tr.requested_by = ?
                                WHERE rn.is_read = 0 AND rn.recipient_email = ?
                                  AND rn.type IN ('transparency_approved', 'transparency_rejected')
                                LIMIT 20
                            ");
                            $stmt->bind_param("is", $user_id, $email);
                            $stmt->execute();
                            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                            $stmt->close();
                            foreach ($rows as $row) {
                                if (!isset($dismissed['tro' . $row['id']])) $count++;
                            }
                        }
                    } catch (Exception $e) {}
                }
            } elseif ($user_role === 'trans_monitoring_officer') {
                // Transportation monitoring officers: their unread notifications
                // are the review-request outcomes (approve/reject) routed to
                // their email — and only ever for transportation reports.
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as count FROM report_notifications rn
                    WHERE rn.is_read = 0
                      AND rn.recipient_email = ?
                      AND rn.type IN ('approve_request', 'reject_request')
                      AND EXISTS (
                          SELECT 1 FROM road_transportation_reports
                          WHERE id = rn.report_id
                            AND report_category = 'transportation'
                            AND report_type != 'infrastructure_issue'
                          LIMIT 1
                      )
                ");
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $count += $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();
            } elseif ($user_role === 'system_admin') {
                // System admins: the badge mirrors the persistent admin feed
                // (see pages/shared/notifications.php). Every card the admin
                // sees is stored as a report_notifications snapshot (type
                // 'admin_keep'); a card counts as unread when it is neither in
                // the admin's read set (session + 'admin_read' markers) nor
                // dismissed with the X button. Live pending items that have not
                // been snapshotted yet count too, so a brand-new report or
                // change request bumps the badge immediately.
                return nc_admin_unread_count($conn, $user_id, $email);
            } else {
                $stmt = $conn->prepare("
                    SELECT COUNT(*) as count FROM report_notifications rn
                    WHERE rn.is_read = 0
                      AND EXISTS (
                          SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                          UNION ALL
                          SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                          UNION ALL
                          SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id
                          LIMIT 1
                      )
                ");
                $stmt->execute();
                $count += $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();
            }
        } catch (Exception $e) {}
    }
    return $count;
}

// Absolute asset/API base paths (clean browser URLs use flat routes; static
// files stay under /lgu_staff/…).
$nav_base = rgmap_lgu_base() . '/';

$user_info = getSidebarUserInfo();
$user_role = $_SESSION['role'] ?? $user_info['role'] ?? 'citizen';
$notification_count = getSidebarNotificationCount($user_role, $_SESSION['user_id'] ?? 0);

// Detect current route for sidebar active state.
$current_route = rgmap_current_route_key();

// Navigation items (route keys → clean public URLs via rgmap_url()).
$nav_items = [
    'main' => [
        ['route' => 'staff-dashboard', 'icon' => 'tachometer-alt', 'title' => 'Staff Dashboard', 'roles' => ['lgu_staff']],
        ['route' => 'admin-dashboard', 'icon' => 'tachometer-alt', 'title' => 'Admin Dashboard', 'roles' => ['system_admin']],
    ],
    'managing_accounts' => [
        ['route' => 'manage-accounts', 'icon' => 'users', 'title' => 'Manage Accounts', 'roles' => ['system_admin']],
        ['route' => 'account-approvals', 'icon' => 'clipboard-check', 'title' => 'Account Approvals', 'roles' => ['system_admin']],
        ['route' => 'create-staff', 'icon' => 'user-plus', 'title' => 'Create Staff Account', 'roles' => ['system_admin']],
        ['route' => 'send-registration', 'icon' => 'envelope-open-text', 'title' => 'Send Registration Link', 'roles' => ['system_admin']],
    ],
    'monitoring' => [
        ['route' => 'monitoring', 'icon' => 'map-marked-alt', 'title' => 'Road Monitoring', 'roles' => ['system_admin','road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer']],
        ['route' => 'verification', 'icon' => 'shield-alt', 'title' => 'Verification Reports', 'roles' => ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor']],
        ['route' => 'report-management', 'icon' => 'clipboard-list', 'title' => 'Report Management', 'roles' => ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor']],
        ['route' => 'completed-projects', 'icon' => 'check-circle', 'title' => 'Completed Projects', 'roles' => ['system_admin','road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer']],
    ],
    'transparency' => [
        ['route' => 'public-transparency', 'icon' => 'eye', 'title' => 'Public Transparency', 'roles' => ['system_admin', 'lgu_staff']],
        ['route' => 'announcements', 'icon' => 'bullhorn', 'title' => 'Announcements', 'roles' => ['system_admin']],
    ],
    'reports' => [
        ['route' => 'analytics', 'icon' => 'chart-line', 'title' => 'Analytics', 'roles' => ['system_admin', 'lgu_staff']],
        ['route' => 'audit-trail', 'icon' => 'history', 'title' => 'Audit Trail', 'roles' => ['system_admin']],
    ],
    'system' => [
        ['route' => 'notifications', 'icon' => 'bell', 'title' => 'Notifications', 'roles' => ['system_admin', 'lgu_staff']],
        ['route' => 'schedule-calendar', 'icon' => 'calendar-alt', 'title' => 'Schedule Calendar', 'roles' => ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor']],
        ['route' => 'archive', 'icon' => 'archive', 'title' => 'Archive', 'roles' => ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor', 'trans_monitoring_officer']],
        ['route' => 'officer-archive', 'icon' => 'archive', 'title' => 'Archive', 'roles' => ['road_monitoring_officer']],
        ['route' => 'settings', 'icon' => 'cog', 'title' => 'Settings', 'roles' => ['system_admin', 'lgu_staff']],
    ]
];

// Filter by role. The Road & Transportation staff roles share the same menu
// as 'lgu_staff'.
$filtered_items = [];
foreach ($nav_items as $section => $items) {
    $filtered_items[$section] = array_filter($items, function($item) use ($user_role) {
        $visible = in_array($user_role, $item['roles'])
            || (is_staff_role($user_role) && in_array('lgu_staff', $item['roles']));
        // Road & Transportation Operations Supervisors do not get the
        // Change Information menu item.
        if ($visible
            && in_array($user_role, ['trans_ops_supervisor', 'road_ops_supervisor'], true)
            && ($item['route'] ?? '') === 'change-info') {
            $visible = false;
        }
        return $visible;
    });
}
?>

<?php
// Non-admin roles: always load the same sidebar stylesheet the Admin UI uses.
// Admin pages already link this in <head>; do not alter Admin markup/appearance.
if ($user_role !== 'system_admin') {
    $__sidebar_css = __DIR__ . '/../css/sidebar.css';
    $__sidebar_v = is_file($__sidebar_css) ? (int)filemtime($__sidebar_css) : 6;
    echo '<link rel="stylesheet" href="' . htmlspecialchars(rgmap_asset('css/sidebar.css'), ENT_QUOTES, 'UTF-8') . '?v=' . $__sidebar_v . '">' . "\n";
}
?>
<aside class="sidebar" id="sidebar" role="complementary">
    <header class="sidebar-header">
        <div class="sidebar-brand">
            <img src="<?php echo htmlspecialchars(rgmap_asset('assets/img/infra-gov-logo-white.png')); ?>" alt="INFRA Gov Services" class="sidebar-logo">
            <div class="sidebar-brand-text">
                <p><?php echo htmlspecialchars(getPortalTitle($user_role)); ?></p>
            </div>
        </div>
        <div class="user-info">
            <?php
            $sidebar_display_name = trim((string)($user_info['full_name'] ?? 'User'));
            $sidebar_initials = '';
            foreach (preg_split('/\s+/', $sidebar_display_name) as $part) {
                if ($part !== '') {
                    $ch = function_exists('mb_substr') ? mb_substr($part, 0, 1) : substr($part, 0, 1);
                    $sidebar_initials .= function_exists('mb_strtoupper') ? mb_strtoupper($ch) : strtoupper($ch);
                    if (strlen($sidebar_initials) >= 2) break;
                }
            }
            if ($sidebar_initials === '') $sidebar_initials = 'U';
            // Use the same profile_picture saved by Settings → Edit Photo.
            // Reloaded from the DB on every page so the sidebar updates after a change.
            $sidebar_profile_picture = trim((string)($user_info['profile_picture'] ?? ''));
            $sidebar_profile_url = '';
            if ($sidebar_profile_picture !== '' && strpos($sidebar_profile_picture, '..') === false && strpos($sidebar_profile_picture, '/') === false) {
                $sidebar_profile_fs = __DIR__ . '/../uploads/profile_pictures/' . $sidebar_profile_picture;
                if (is_file($sidebar_profile_fs)) {
                    $sidebar_profile_url = rgmap_asset('uploads/profile_pictures/' . rawurlencode($sidebar_profile_picture));
                }
            }
            ?>
            <div class="user-avatar<?php echo $sidebar_profile_url !== '' ? ' has-photo' : ''; ?>" aria-hidden="true">
                <?php if ($sidebar_profile_url !== ''): ?>
                    <img class="user-avatar-photo" src="<?php echo htmlspecialchars($sidebar_profile_url); ?>" alt="" width="32" height="32">
                <?php else: ?>
                    <?php echo htmlspecialchars($sidebar_initials); ?>
                <?php endif; ?>
            </div>
            <div class="user-text">
                <div class="user-name"><?php echo htmlspecialchars($sidebar_display_name); ?></div>
            </div>
        </div>
    </header>

    <nav class="sidebar-menu" aria-label="Main navigation">
        <?php foreach ($filtered_items as $section => $items): ?>
            <?php if (!empty($items)): ?>
                <?php
                $is_managing_accounts = ($section === 'managing_accounts');
                $section_label = $is_managing_accounts ? 'Accounts' : ucfirst($section);
                $accounts_active = false;
                if ($is_managing_accounts) {
                    foreach ($items as $item) {
                        if ($current_route === ($item['route'] ?? '')) { $accounts_active = true; break; }
                    }
                }
                ?>
                <div class="menu-label<?php echo $is_managing_accounts ? ' managing-accounts-toggle' : ''; ?>" id="<?php echo $is_managing_accounts ? 'managingAccountsToggle' : 'menu-label-' . $section; ?>"<?php if ($is_managing_accounts): ?> role="button" tabindex="0" aria-expanded="<?php echo $accounts_active ? 'true' : 'false'; ?>" onclick="toggleManagingAccounts()"<?php endif; ?>><?php echo $section_label; ?><?php if ($is_managing_accounts): ?> <i class="fas fa-chevron-down managing-accounts-chevron" aria-hidden="true"></i><?php endif; ?></div>
                <ul role="list" aria-labelledby="<?php echo $is_managing_accounts ? 'managingAccountsToggle' : 'menu-label-' . $section; ?>"<?php if ($is_managing_accounts): ?> id="managingAccountsSubmenu" class="managing-accounts-submenu" style="display:<?php echo $accounts_active ? 'block' : 'none'; ?>;"<?php endif; ?>>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $item_route = (string)($item['route'] ?? '');
                        $item_href = rgmap_url($item_route);
                        $is_active = ($current_route === $item_route) ? ' active' : '';
                        $aria_current = ($current_route === $item_route) ? ' aria-current="page"' : '';
                        ?>
                        <li role="listitem">
                            <a href="<?php echo htmlspecialchars($item_href); ?>" class="nav-link<?php echo $is_active; ?>"<?php echo $aria_current; ?>>
                                <i class="fas fa-<?php echo $item['icon']; ?>" aria-hidden="true"></i>
                                <?php
                                $display_title = $item['title'];
                                if ($item['title'] === 'Road Monitoring') {
                                    if (in_array($user_role, ['trans_ops_supervisor', 'trans_monitoring_officer'], true)) {
                                        $display_title = 'Transportation Monitoring';
                                    } elseif ($user_role === 'system_admin') {
                                        $display_title = 'Transportation and Road Monitoring';
                                    }
                                } elseif ($item['title'] === 'Completed Projects' && in_array($user_role, ['trans_ops_supervisor', 'trans_monitoring_officer'], true)) {
                                    $display_title = 'Completed Transportation Projects';
                                } elseif ($item['title'] === 'Completed Projects' && $user_role === 'road_ops_supervisor') {
                                    $display_title = 'Completed Road Projects';
                                }
                                echo htmlspecialchars($display_title);
                                ?>
                                <?php if ($notification_count > 0 && $item['icon'] === 'bell'): ?>
                                    <span class="notification-badge" role="status" aria-label="<?php echo $notification_count; ?> unread notifications"><?php echo $notification_count; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endforeach; ?>

        <div class="menu-label">Account</div>
        <ul role="list">
            <li role="listitem">
                <a href="<?php echo htmlspecialchars(rgmap_url('logout')); ?>" class="nav-link nav-link-logout" id="logoutBtn" role="button">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i> Logout
                </a>
            </li>
        </ul>
    </nav>
</aside>
<script>
window.RGMAP = {
    base: <?php echo json_encode(rgmap_web_base()); ?>,
    api: function (script) {
        var b = this.base || '';
        return b + '/api/' + String(script || '').replace(/^\//, '');
    },
    asset: function (path) {
        var b = this.base || '';
        return b + '/lgu_staff/' + String(path || '').replace(/^\//, '');
    }
};
</script>

<?php if (in_array($user_role, ['system_admin', 'road_ops_supervisor', 'road_monitoring_officer', 'trans_ops_supervisor', 'trans_monitoring_officer'], true)): ?>
<!-- Mobile hamburger menu toggle (system_admin, road_ops_supervisor, road_monitoring_officer, trans_ops_supervisor, trans_monitoring_officer) -->
<button type="button" class="admin-menu-toggle" id="adminMenuToggle" aria-label="Open navigation menu" aria-controls="sidebar" aria-expanded="false">
    <i class="fas fa-bars" aria-hidden="true"></i>
</button>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop"></div>
<style>
    .admin-menu-toggle {
        display: none;
        position: fixed;
        top: 14px;
        left: 14px;
        z-index: 1100;
        width: 42px;
        height: 42px;
        align-items: center;
        justify-content: center;
        border: none;
        border-radius: 8px;
        background: linear-gradient(145deg, #1e3c72 0%, #3762c8 100%);
        color: #ffffff;
        font-size: 18px;
        cursor: pointer;
        box-shadow: 0 4px 14px rgba(30, 60, 114, 0.28);
        transition: background 0.2s ease, transform 0.2s ease;
        border-radius: 10px;
    }
    .admin-menu-toggle:hover { background: #1e3c72; }
    .admin-menu-toggle:focus-visible { outline: 2px solid #93c5fd; outline-offset: 2px; }

    .admin-sidebar-backdrop {
        position: fixed;
        inset: 0;
        z-index: 950;
        background: rgba(15, 23, 42, 0.55);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.3s ease, visibility 0.3s ease;
    }
    .admin-sidebar-backdrop.show { opacity: 1; visibility: visible; }

    @media (max-width: 768px) {
        .admin-menu-toggle { display: flex; }
        .main-content { padding-top: 76px !important; }
    }
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var toggle = document.getElementById('adminMenuToggle');
    var backdrop = document.getElementById('adminSidebarBackdrop');
    var sidebar = document.getElementById('sidebar');
    if (!toggle || !sidebar) return;

    function setSidebar(open) {
        sidebar.classList.toggle('open', open);
        if (backdrop) backdrop.classList.toggle('show', open);
        toggle.classList.toggle('active', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
        var icon = toggle.querySelector('i');
        if (icon) icon.className = open ? 'fas fa-times' : 'fas fa-bars';
        document.body.style.overflow = open ? 'hidden' : '';
        document.documentElement.style.overflow = open ? 'hidden' : '';
    }

    toggle.addEventListener('click', function() {
        setSidebar(!sidebar.classList.contains('open'));
    });

    if (backdrop) {
        backdrop.addEventListener('click', function() {
            setSidebar(false);
        });
    }

    sidebar.querySelectorAll('.nav-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (sidebar.classList.contains('open')) setSidebar(false);
        });
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) setSidebar(false);
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth > 768 && sidebar.classList.contains('open')) setSidebar(false);
    });
});
</script>
<?php endif; ?>

<script>
function toggleManagingAccounts() {
    var toggle = document.getElementById('managingAccountsToggle');
    var submenu = document.getElementById('managingAccountsSubmenu');
    if (!toggle || !submenu) return;
    var isHidden = submenu.style.display === 'none';
    submenu.style.display = isHidden ? 'block' : 'none';
    toggle.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
}

document.addEventListener('DOMContentLoaded', function() {
    var logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (!confirm('Are you sure you want to log out?')) return;
            window.location.href = logoutBtn.href;
        });
    }

    var maToggle = document.getElementById('managingAccountsToggle');
    if (maToggle) {
        maToggle.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleManagingAccounts();
            }
        });
    }

    // Animate the active sidebar link on page load
    var activeLink = document.querySelector('.sidebar-menu .nav-link.active');
    if (activeLink) {
        activeLink.classList.add('active-animate');
        activeLink.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
});
</script>

<?php if ($user_role === 'system_admin'): ?>
<script>
// Keep the admin sidebar notification badge in sync while the page is open.
// New notifications (pending reports, change requests, progress updates,
// assignments) and mark-as-read actions are picked up on a short interval so
// the badge updates without a manual refresh.
function ncSyncSidebarBadge(count) {
    var link = document.querySelector('.sidebar-menu .nav-link[href*="notifications.php"]');
    if (!link) return;
    var badge = link.querySelector('.notification-badge');
    if (count > 0) {
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'notification-badge';
            badge.setAttribute('role', 'status');
            link.appendChild(badge);
        }
        badge.textContent = count;
        badge.setAttribute('aria-label', count + ' unread notifications');
    } else if (badge) {
        badge.remove();
    }
}

function ncPollSidebarBadge() {
    fetch((window.RGMAP && typeof window.RGMAP.api === 'function') ? window.RGMAP.api('notifications_unread_count.php') : 'api/notifications_unread_count.php', {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin'
    })
    .then(function (r) { return r.json(); })
    .then(function (d) {
        if (d && typeof d.count === 'number') ncSyncSidebarBadge(d.count);
    })
    .catch(function () {});
}

document.addEventListener('DOMContentLoaded', function () {
    ncPollSidebarBadge();
    setInterval(ncPollSidebarBadge, 30000);
});
</script>
<?php endif; ?>

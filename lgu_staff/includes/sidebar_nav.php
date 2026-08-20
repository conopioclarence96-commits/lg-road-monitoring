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
    header('Location: /lg-road-monitoring/lgu_staff/login.php');
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
        header('Location: /lg-road-monitoring/lgu_staff/change_password.php');
        exit();
    }
}

// Get user info
function getSidebarUserInfo() {
    global $conn;
    if ($conn && isset($_SESSION['user_id'])) {
        $stmt = $conn->prepare("SELECT username, full_name, email, role FROM users WHERE id = ?");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    return [
        'username' => 'Staff User',
        'full_name' => 'LGU Staff',
        'email' => 'staff@lgu.gov.ph',
        'role' => 'lgu_staff'
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

    // Supervisors: completion/cancellation requests routed to my role, plus
    // confirmations for actions I performed (Complete/Cancel).
    if ($is_trans_supervisor) {
        $trans_exists = "SELECT 1 FROM road_transportation_reports
                         WHERE id = rn.report_id
                           AND report_category = 'transportation'
                           AND report_type != 'infrastructure_issue'";
        $stmt = $conn->prepare("
            SELECT rn.id FROM report_notifications rn
            WHERE rn.is_read = 0 AND rn.recipient_role = ?
              AND rn.type IN ('completion', 'cancellation')
              AND EXISTS (" . $trans_exists . " LIMIT 1)
            LIMIT 20
        ");
        $stmt->bind_param("s", $user_role);
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
        // same count as their Notifications page too. For this role that page
        // counts every card in the feed — report status updates (ru), review-
        // request outcomes (ro, is_read = 0 only), active project assignments
        // (asg) and change-request updates (su) — with each panel capped at 20,
        // so the count is capped the same way to stay in sync. Cards dismissed
        // with the X button are dropped from the feed, so they are excluded
        // here as well.
        if ($user_role === 'road_monitoring_officer') {
            try {
                $dismissed = [];
                foreach (($_SESSION['nc_dismissed'][(int)$user_id] ?? []) as $k) {
                    $dismissed[(string)$k] = true;
                }

                // Report status updates (my completed/cancelled reports).
                $stmt = $conn->prepare("
                    SELECT id FROM road_transportation_reports
                    WHERE created_by = ? AND status IN ('completed', 'cancelled')
                    LIMIT 20
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                foreach ($rows as $r) { if (!isset($dismissed['ru' . $r['id']])) $count++; }

                // Review-request outcomes (approve/reject) routed to my email.
                if ($email !== '') {
                    $ro_exists = "SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                                  UNION ALL
                                  SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id";
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
                foreach ($rows as $r) { if (!isset($dismissed['asg' . $r['id']])) $count++; }

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
                foreach ($rows as $r) { if (!isset($dismissed['su' . $r['id']])) $count++; }

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
                // routed to their role plus results targeted to their email.
                // (The generic count below would include notifications meant
                // for other roles or broadcast progress updates, inflating the
                // badge.)
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
                               SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id";
                }
                $stmt = $conn->prepare("
                    SELECT rn.id FROM report_notifications rn
                    WHERE rn.is_read = 0
                      AND (
                          (rn.recipient_role = ? AND rn.type IN ('completion', 'cancellation'))
                          OR (rn.recipient_email = ? AND rn.type IN ('approve_request', 'reject_request', 'complete_report', 'cancel_report', 'no_update_stale'))
                      )
                      AND EXISTS (" . $exists . " LIMIT 1)
                    LIMIT 50
                ");
                $stmt->bind_param("ss", $user_role, $email);
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
                foreach ($rows as $row) {
                    $id = $row['id'];
                    if (!isset($dismissed['rq' . $id])
                        && !isset($dismissed['sa' . $id])
                        && !isset($dismissed['ro' . $id])
                        && !isset($dismissed['stu' . $id])) {
                        $count++;
                    }
                }

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

// Determine base path: all pages are in lgu_staff/pages/{admin,lgu,shared}/
// So base path to lgu_staff/ is always ../../
$nav_base = isset($nav_base) ? $nav_base : '../../';

$user_info = getSidebarUserInfo();
$user_role = $_SESSION['role'] ?? $user_info['role'] ?? 'citizen';
$notification_count = getSidebarNotificationCount($user_role, $_SESSION['user_id'] ?? 0);

// Detect current page for active state
$current_page = basename($_SERVER['SCRIPT_NAME']);

// Navigation items
$nav_items = [
    'main' => [
        ['href' => $nav_base . 'pages/lgu/lgu_staff_dashboard.php', 'icon' => 'tachometer-alt', 'title' => 'Staff Dashboard', 'roles' => ['lgu_staff']],   
        ['href' => $nav_base . 'pages/admin/admin_dashboard.php', 'icon' => 'tachometer-alt', 'title' => 'Admin Dashboard', 'roles' => ['system_admin']],
    ],
    'managing_accounts' => [
        ['href' => $nav_base . 'pages/admin/manage_accounts.php', 'icon' => 'users', 'title' => 'Manage Accounts', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/admin/account_approvals.php', 'icon' => 'clipboard-check', 'title' => 'Account Approvals', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/admin/create_staff_account.php', 'icon' => 'user-plus', 'title' => 'Create Staff Account', 'roles' => ['system_admin']],
        ['href' => $nav_base . 'pages/admin/send_registration_link.php', 'icon' => 'envelope-open-text', 'title' => 'Send Registration Link', 'roles' => ['system_admin']],
    ],
    'monitoring' => [
        ['href' => $nav_base . 'pages/shared/road_transportation_monitoring.php', 'icon' => 'map-marked-alt', 'title' => 'Road Monitoring', 'roles' => ['system_admin','road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer']],
        ['href' => $nav_base . 'pages/admin/verification_monitoring.php', 'icon' => 'shield-alt', 'title' => 'Verification Reports', 'roles' => ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor']],
        ['href' => $nav_base . 'pages/admin/report_management.php', 'icon' => 'clipboard-list', 'title' => 'Report Management', 'roles' => ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor']],
        ['href' => $nav_base . 'pages/shared/completed_projects.php', 'icon' => 'check-circle', 'title' => 'Completed Projects', 'roles' => ['system_admin','road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer']],
    ],
    'transparency' => [
        ['href' => $nav_base . 'pages/shared/public_transparency.php', 'icon' => 'eye', 'title' => 'Public Transparency', 'roles' => ['system_admin', 'lgu_staff']],
    ],
    'reports' => [
        ['href' => $nav_base . 'pages/shared/analytics.php', 'icon' => 'chart-line', 'title' => 'Analytics', 'roles' => ['system_admin', 'lgu_staff']],
        ['href' => $nav_base . 'pages/admin/audit_trail.php', 'icon' => 'history', 'title' => 'Audit Trail', 'roles' => ['system_admin']],
    ],
    'system' => [
        ['href' => $nav_base . 'pages/shared/notifications.php', 'icon' => 'bell', 'title' => 'Notifications', 'roles' => ['system_admin', 'lgu_staff']],
        ['href' => $nav_base . 'pages/admin/archive.php', 'icon' => 'archive', 'title' => 'Archive', 'roles' => ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor', 'trans_monitoring_officer']],
        ['href' => $nav_base . 'pages/lgu/officer_archive.php', 'icon' => 'archive', 'title' => 'Archive', 'roles' => ['road_monitoring_officer']],
        ['href' => $nav_base . 'pages/shared/settings.php', 'icon' => 'cog', 'title' => 'Settings', 'roles' => ['system_admin', 'lgu_staff']],
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
            && basename($item['href']) === 'change_info.php') {
            $visible = false;
        }
        return $visible;
    });
}
?>

<aside class="sidebar" id="sidebar" role="complementary">
    <header class="sidebar-header">
        <h2><i class="fas fa-road"></i> <?php echo defined('SITE_NAME') ? SITE_NAME : 'LGU Portal'; ?></h2>
        <p><?php echo htmlspecialchars(getPortalTitle($user_role)); ?></p>
        <div class="user-info">
            <div class="user-name"><?php echo htmlspecialchars($user_info['full_name']); ?></div>
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
                        if ($current_page === basename($item['href'])) { $accounts_active = true; break; }
                    }
                }
                ?>
                <div class="menu-label<?php echo $is_managing_accounts ? ' managing-accounts-toggle' : ''; ?>" id="<?php echo $is_managing_accounts ? 'managingAccountsToggle' : 'menu-label-' . $section; ?>"<?php if ($is_managing_accounts): ?> role="button" tabindex="0" aria-expanded="<?php echo $accounts_active ? 'true' : 'false'; ?>" onclick="toggleManagingAccounts()"<?php endif; ?>><?php echo $section_label; ?><?php if ($is_managing_accounts): ?> <i class="fas fa-chevron-down managing-accounts-chevron" aria-hidden="true"></i><?php endif; ?></div>
                <ul role="list" aria-labelledby="<?php echo $is_managing_accounts ? 'managingAccountsToggle' : 'menu-label-' . $section; ?>"<?php if ($is_managing_accounts): ?> id="managingAccountsSubmenu" class="managing-accounts-submenu" style="display:<?php echo $accounts_active ? 'block' : 'none'; ?>;"<?php endif; ?>>
                    <?php foreach ($items as $item): ?>
                        <?php
                        $href_file = basename($item['href']);
                        $is_active = ($current_page === $href_file) ? ' active' : '';
                        $aria_current = ($current_page === $href_file) ? ' aria-current="page"' : '';
                        ?>
                        <li role="listitem">
                            <a href="<?php echo $item['href']; ?>" class="nav-link<?php echo $is_active; ?>"<?php echo $aria_current; ?>>
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
                <a href="<?php echo $nav_base; ?>logout.php" class="nav-link nav-link-logout" id="logoutBtn" role="button">
                    <i class="fas fa-sign-out-alt" aria-hidden="true"></i> Logout
                </a>
            </li>
        </ul>
    </nav>
</aside>

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
        background: linear-gradient(135deg, #2952b3 0%, #1a2f6b 100%);
        color: #ffffff;
        font-size: 18px;
        cursor: pointer;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.25);
        transition: background 0.2s ease, transform 0.2s ease;
    }
    .admin-menu-toggle:hover { background: #1e3a7a; }
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
    fetch('../../pages/api/notifications_unread_count.php', {
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

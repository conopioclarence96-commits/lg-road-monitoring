<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../api/cimm_verification_data.php';
require_once __DIR__ . '/../api/ipms_road_projects_data.php';

// Session timeout configuration
$session_timeout = 30 * 60; // 30 minutes in seconds
lgu_enforce_idle_timeout($session_timeout, '../../login.php?timeout=1');

// Ensure approved_at and rejected_at columns exist in users table
if ($conn->connect_error === null) {
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'approved_at'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    }
    $check2 = $conn->query("SHOW COLUMNS FROM users LIKE 'rejected_at'");
    if ($check2 && $check2->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN rejected_at TIMESTAMP NULL DEFAULT NULL AFTER approved_at");
    }
}

// Check if user is logged in and is system admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'system_admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: ../../login.php');
    exit();
}

// Get dashboard statistics
$stats = [];
try {
    // Pending user approvals
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'pending'");
    $stmt->execute();
    $stats['pending_users'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // Approved users
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'verified' AND is_active = 1");
    $stmt->execute();
    $stats['approved_users'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // Active reports
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status IN ('pending', 'in-progress')");
    $stmt->execute();
    $stats['active_reports'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // Deactivated users
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'deactivated'");
    $stmt->execute();
    $stats['deactivated_users'] = $stmt->get_result()->fetch_assoc()['count'];
    
    // Inactive users (logged in before but not in 2 weeks)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'verified' AND is_active = 1 AND last_login IS NOT NULL AND last_login < DATE_SUB(NOW(), INTERVAL 14 DAY)");
    $stmt->execute();
    $stats['inactive_2weeks'] = $stmt->get_result()->fetch_assoc()['count'];
    
} catch (Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $stats = [
        'pending_users' => 0,
        'approved_users' => 0,
        'active_reports' => 0,
        'deactivated_users' => 0,
        'inactive_2weeks' => 0
    ];
}

// Pending Approvals panel: same population as account_approvals.php (staff/
// citizen roles still waiting). Excludes already approved/rejected rows and
// roles that page does not review (e.g. system_admin). Chart stats above are
// left unchanged.
$pending_approval_where = "role IN ('lgu_staff', 'citizen', 'road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer')
    AND account_status = 'pending'
    AND approved_at IS NULL
    AND rejected_at IS NULL";
$pending_users_list = [];
$pending_approvals_count = 0;
try {
    $cnt = $conn->prepare("SELECT COUNT(*) AS count FROM users WHERE {$pending_approval_where}");
    $cnt->execute();
    $pending_approvals_count = (int)$cnt->get_result()->fetch_assoc()['count'];
    $cnt->close();

    $pu_stmt = $conn->prepare("SELECT id, full_name, role, created_at FROM users WHERE {$pending_approval_where} ORDER BY created_at DESC LIMIT 5");
    $pu_stmt->execute();
    $pending_users_list = $pu_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pu_stmt->close();
} catch (Exception $e) {
    error_log("Dashboard pending approvals error: " . $e->getMessage());
    $pending_users_list = [];
    $pending_approvals_count = 0;
}

// High Priority panel: the same live reports Report Management shows when
// Priority = High (LGU, Citizen, CIMM, Infrastructure). Not a separate copy.
$high_priority_reports = [];
$high_priority_panel_count = 0;
try {
    $hp_rows = [];
    $has_restored = false;
    try {
        $col = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'restored_from_archive'");
        $has_restored = $col && $col->num_rows > 0;
    } catch (Exception $e) {}
    $lgu_cancelled = $has_restored
        ? " OR (status = 'cancelled' AND restored_from_archive = 1)"
        : '';

    // LGU Monitoring (default Report Management status=all).
    $lgu_sql = "SELECT id, report_id, title, status, created_at, 'lgu_reports' AS rm_source
                FROM road_transportation_reports
                WHERE report_source = 'local'
                  AND created_by != 0
                  AND report_type != 'infrastructure_issue'
                  AND LOWER(priority) = 'high'
                  AND (
                        status IN ('approved', 'in-progress')
                        {$lgu_cancelled}
                  )";
    $res = $conn->query($lgu_sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) $hp_rows[] = $row;
    }

    // Citizen Reports (post-verification, not completed).
    $citizen_sql = "SELECT id, report_id, title, status, created_at, 'citizen' AS rm_source
                    FROM road_transportation_reports
                    WHERE created_by = 0
                      AND report_type != 'infrastructure_issue'
                      AND LOWER(priority) = 'high'
                      AND LOWER(status) NOT IN ('pending', 'awaiting verification', 'for verification', 'under review', 'submitted', 'new')
                      AND status != 'completed'";
    $res = $conn->query($citizen_sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) $hp_rows[] = $row;
    }

    // CIMM — same visibility as getCimmReportsForManagement(status=all).
    try {
        $pdo = rgmap_verification_pdo();
        $cimm_raw = rgmap_fetch_cimm_verification_reports($pdo, [
            'verification_status' => ['Approved', 'In Progress', 'Completed', 'Cancelled'],
            'infrastructure' => 'Roads',
        ]);
        $override = [
            'Pending' => 'pending',
            'Approved' => 'approved',
            'In Progress' => 'in-progress',
            'Completed' => 'completed',
            'Cancelled' => 'cancelled',
        ];
        foreach ($cimm_raw as $crow) {
            if (strtolower((string)($crow['priority'] ?? '')) !== 'high') continue;
            $verification = $crow['verification_status'] ?? 'Pending Review';
            if ($verification === 'Dismissed') {
                $st = 'cancelled';
            } elseif (isset($override[$verification])) {
                $st = $override[$verification];
            } else {
                $st = cimm_resolution_status_to_display($crow['resolution_status'] ?? null, $crow['approval_status'] ?? null);
            }
            $st_l = strtolower($st);
            if ($st_l === 'pending' || $st_l === 'completed') continue;
            if ($st_l === 'cancelled' && (int)($crow['restored_from_archive'] ?? 0) !== 1) continue;
            $hp_rows[] = [
                'id' => (int)($crow['id'] ?? $crow['cimm_req_id'] ?? 0),
                'report_id' => $crow['reference_code'] ?? ('REQ-' . ($crow['cimm_req_id'] ?? '')),
                'title' => $crow['infrastructure'] ?? 'CIMM Report',
                'status' => $st,
                'created_at' => $crow['submitted_at'] ?? $crow['created_at'] ?? '',
                'rm_source' => 'cimm',
            ];
        }
    } catch (Exception $e) {
        error_log('Dashboard high-priority CIMM: ' . $e->getMessage());
    }

    // Infrastructure Projects (approved IPMS rows on Report Management).
    try {
        foreach (rgmap_infra_panel_rows(null, 'approved') as $ir) {
            if (strtolower((string)($ir['priority'] ?? '')) !== 'high') continue;
            $hp_rows[] = [
                'id' => (int)($ir['id'] ?? 0),
                'report_id' => (string)($ir['report_id'] ?? ''),
                'title' => (string)($ir['title'] ?? ''),
                'status' => (string)($ir['status'] ?? 'approved'),
                'created_at' => $ir['created_at'] ?? '',
                'rm_source' => 'maintenance',
            ];
        }
    } catch (Exception $e) {
        error_log('Dashboard high-priority infra: ' . $e->getMessage());
    }

    usort($hp_rows, static function ($a, $b) {
        return (strtotime((string)($b['created_at'] ?? '')) ?: 0)
             <=> (strtotime((string)($a['created_at'] ?? '')) ?: 0);
    });
    $high_priority_panel_count = count($hp_rows);
    $high_priority_reports = array_slice($hp_rows, 0, 5);
} catch (Exception $e) {
    error_log('Dashboard high-priority panel: ' . $e->getMessage());
    $high_priority_reports = [];
    $high_priority_panel_count = 0;
}

// Report statistics for charts
$report_stats = [
    'by_status' => [],
    'by_type' => [],
    'by_month' => []
];

try {
    // Reports by status
    $rstmt = $conn->prepare("SELECT status, COUNT(*) as count FROM road_transportation_reports GROUP BY status ORDER BY count DESC");
    $rstmt->execute();
    $report_stats['by_status'] = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rstmt->close();

    // Reports by report_type
    $rstmt = $conn->prepare("SELECT report_type, COUNT(*) as count FROM road_transportation_reports GROUP BY report_type ORDER BY count DESC");
    $rstmt->execute();
    $report_stats['by_type'] = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rstmt->close();

    // Reports by month (last 6 months)
    $rstmt = $conn->prepare("
        SELECT DATE_FORMAT(created_at, '%Y-%m') as month_label, 
               DATE_FORMAT(created_at, '%b %Y') as month_name, 
               COUNT(*) as count 
        FROM road_transportation_reports 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_label, month_name 
        ORDER BY month_label ASC
    ");
    $rstmt->execute();
    $report_stats['by_month'] = $rstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rstmt->close();
} catch (Exception $e) {
    error_log("Report stats error: " . $e->getMessage());
}

// Awaiting for Assignments: live Unassigned reports from the same sets
// Report Management shows (status=all), using report_assignments.
$awaiting_assignment_reports = [];
try {
    $aa_rows = [];
    $has_restored = false;
    try {
        $col = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'restored_from_archive'");
        $has_restored = $col && $col->num_rows > 0;
    } catch (Exception $e) {}
    $lgu_cancelled = $has_restored
        ? " OR (status = 'cancelled' AND restored_from_archive = 1)"
        : '';

    $aa_unassigned = '';
    try {
        $chk = $conn->query("SHOW TABLES LIKE 'report_assignments'");
        if ($chk && $chk->num_rows > 0) {
            $aa_unassigned = " AND NOT EXISTS (
                SELECT 1 FROM report_assignments ra
                WHERE ra.report_id = r.id
                  AND ra.report_type = 'road_transportation_reports'
                  AND ra.status = 'active'
            )";
        }
    } catch (Exception $e) {}

    $lgu_sql = "SELECT r.id, r.report_id, r.title, r.priority, r.status, r.created_at,
                       'lgu_reports' AS rm_source, 'LGU Monitoring' AS source_label
                FROM road_transportation_reports r
                WHERE r.report_source = 'local'
                  AND r.created_by != 0
                  AND r.report_type != 'infrastructure_issue'
                  AND (
                        r.status IN ('approved', 'in-progress')
                        {$lgu_cancelled}
                  )
                  {$aa_unassigned}";
    $res = $conn->query($lgu_sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) $aa_rows[] = $row;
    }

    $citizen_sql = "SELECT r.id, r.report_id, r.title, r.priority, r.status, r.created_at,
                           'citizen' AS rm_source, 'Citizen' AS source_label
                    FROM road_transportation_reports r
                    WHERE r.created_by = 0
                      AND r.report_type != 'infrastructure_issue'
                      AND LOWER(r.status) NOT IN ('pending', 'awaiting verification', 'for verification', 'under review', 'submitted', 'new')
                      AND r.status != 'completed'
                      {$aa_unassigned}";
    $res = $conn->query($citizen_sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) $aa_rows[] = $row;
    }

    $assigned_keys = [];
    try {
        $chk = $conn->query("SHOW TABLES LIKE 'report_assignments'");
        if ($chk && $chk->num_rows > 0) {
            $ak = $conn->query("SELECT report_type, report_id FROM report_assignments WHERE status = 'active'");
            if ($ak) {
                while ($row = $ak->fetch_assoc()) {
                    $assigned_keys[(string)$row['report_type'] . ':' . (int)$row['report_id']] = true;
                }
            }
        }
    } catch (Exception $e) {}

    try {
        $pdo = rgmap_verification_pdo();
        $cimm_raw = rgmap_fetch_cimm_verification_reports($pdo, [
            'verification_status' => ['Approved', 'In Progress', 'Completed', 'Cancelled'],
            'infrastructure' => 'Roads',
        ]);
        $override = [
            'Pending' => 'pending',
            'Approved' => 'approved',
            'In Progress' => 'in-progress',
            'Completed' => 'completed',
            'Cancelled' => 'cancelled',
        ];
        foreach ($cimm_raw as $crow) {
            $cid = (int)($crow['id'] ?? $crow['cimm_req_id'] ?? 0);
            if (isset($assigned_keys['cimm_verification_reports:' . $cid])) continue;
            $verification = $crow['verification_status'] ?? 'Pending Review';
            if ($verification === 'Dismissed') {
                $st = 'cancelled';
            } elseif (isset($override[$verification])) {
                $st = $override[$verification];
            } else {
                $st = cimm_resolution_status_to_display($crow['resolution_status'] ?? null, $crow['approval_status'] ?? null);
            }
            $st_l = strtolower($st);
            if ($st_l === 'pending' || $st_l === 'completed') continue;
            if ($st_l === 'cancelled' && (int)($crow['restored_from_archive'] ?? 0) !== 1) continue;
            $aa_rows[] = [
                'id' => $cid,
                'report_id' => $crow['reference_code'] ?? ('REQ-' . ($crow['cimm_req_id'] ?? '')),
                'title' => $crow['infrastructure'] ?? 'CIMM Report',
                'priority' => strtolower((string)($crow['priority'] ?? 'medium')),
                'status' => $st,
                'created_at' => $crow['submitted_at'] ?? $crow['created_at'] ?? '',
                'rm_source' => 'cimm',
                'source_label' => 'CIMM',
            ];
        }
    } catch (Exception $e) {
        error_log('Dashboard awaiting-assignment CIMM: ' . $e->getMessage());
    }

    try {
        foreach (rgmap_infra_panel_rows(null, 'approved') as $ir) {
            $iid = (int)($ir['id'] ?? 0);
            $itable = !empty($ir['from_ipms']) ? 'ipms_road_projects' : 'road_maintenance_reports';
            if (isset($assigned_keys[$itable . ':' . $iid])) continue;
            $aa_rows[] = [
                'id' => $iid,
                'report_id' => (string)($ir['report_id'] ?? ''),
                'title' => (string)($ir['title'] ?? ''),
                'priority' => (string)($ir['priority'] ?? 'medium'),
                'status' => (string)($ir['status'] ?? 'approved'),
                'created_at' => $ir['created_at'] ?? '',
                'rm_source' => 'maintenance',
                'source_label' => 'Infrastructure',
            ];
        }
    } catch (Exception $e) {
        error_log('Dashboard awaiting-assignment infra: ' . $e->getMessage());
    }

    usort($aa_rows, static function ($a, $b) {
        return (strtotime((string)($b['created_at'] ?? '')) ?: 0)
             <=> (strtotime((string)($a['created_at'] ?? '')) ?: 0);
    });
    $awaiting_assignment_reports = array_slice($aa_rows, 0, 10);
} catch (Exception $e) {
    error_log('Dashboard awaiting-assignment panel: ' . $e->getMessage());
    $awaiting_assignment_reports = [];
}

// Recent activity from audit logs
$recent_activity = [];
try {
    $ractmt = $conn->prepare("
        SELECT al.*, u.full_name as user_name
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
        LIMIT 15
    ");
    $ractmt->execute();
    $recent_activity = $ractmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ractmt->close();
} catch (Exception $e) {
    error_log("Recent activity error: " . $e->getMessage());
}

// Quick insights data
$quick_insights = [
    'new_reports_today' => 0,
    'total_reports' => 0,
    'pending_reports' => 0,
    'in_progress_reports' => 0,
    'completed_reports' => 0,
    'cancelled_reports' => 0,
    'high_priority' => 0,
    'waiting_verification' => 0,
    'waiting_assignment' => 0,
    'overdue_reports' => 0,
    'completed_today' => 0,
    'active_officers' => 0,
];
try {
    // New reports today
    $qstmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE DATE(created_at) = CURDATE()");
    $qstmt->execute();
    $quick_insights['new_reports_today'] = (int)$qstmt->get_result()->fetch_assoc()['count'];
    $qstmt->close();

    // Total reports
    $qstmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports");
    $qstmt->execute();
    $quick_insights['total_reports'] = (int)$qstmt->get_result()->fetch_assoc()['count'];
    $qstmt->close();

    // Pending reports
    $qstmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'pending'");
    $qstmt->execute();
    $quick_insights['pending_reports'] = (int)$qstmt->get_result()->fetch_assoc()['count'];
    $qstmt->close();

    // In progress reports
    $qstmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status IN ('in-progress', 'approved')");
    $qstmt->execute();
    $quick_insights['in_progress_reports'] = (int)$qstmt->get_result()->fetch_assoc()['count'];
    $qstmt->close();

    // Completed reports
    $qstmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'completed'");
    $qstmt->execute();
    $quick_insights['completed_reports'] = (int)$qstmt->get_result()->fetch_assoc()['count'];
    $qstmt->close();

    // Cancelled reports
    $qstmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'cancelled'");
    $qstmt->execute();
    $quick_insights['cancelled_reports'] = (int)$qstmt->get_result()->fetch_assoc()['count'];
    $qstmt->close();

    // High priority reports
    $qstmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE priority = 'high' AND status NOT IN ('completed', 'cancelled')");
    $qstmt->execute();
    $quick_insights['high_priority'] = (int)$qstmt->get_result()->fetch_assoc()['count'];
    $qstmt->close();

    // Waiting for verification (pending status)
    $quick_insights['waiting_verification'] = $quick_insights['pending_reports'];

    // Waiting for assignment (pending and no active assignment)
    $qstmt = $conn->prepare("
        SELECT COUNT(*) as count FROM road_transportation_reports r
        WHERE r.status = 'pending'
        AND NOT EXISTS (SELECT 1 FROM report_assignments ra WHERE ra.report_id = r.id AND ra.status = 'active' LIMIT 1)
    ");
    $qstmt->execute();
    $quick_insights['waiting_assignment'] = (int)$qstmt->get_result()->fetch_assoc()['count'];
    $qstmt->close();

    // Overdue reports (pending/in-progress for more than 7 days)
    $qstmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status IN ('pending', 'in-progress') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $qstmt->execute();
    $quick_insights['overdue_reports'] = (int)$qstmt->get_result()->fetch_assoc()['count'];
    $qstmt->close();

    // Completed today
    $qstmt = $conn->prepare("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'completed' AND DATE(updated_at) = CURDATE()");
    $qstmt->execute();
    $quick_insights['completed_today'] = (int)$qstmt->get_result()->fetch_assoc()['count'];
    $qstmt->close();

    // Active officers (users with monitoring officer roles)
    $qstmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE role IN ('road_monitoring_officer', 'trans_monitoring_officer') AND is_active = 1");
    $qstmt->execute();
    $quick_insights['active_officers'] = (int)$qstmt->get_result()->fetch_assoc()['count'];
    $qstmt->close();
} catch (Exception $e) {
    error_log("Quick insights error: " . $e->getMessage());
}

// Reports by source (for pie chart)
$reports_by_source = [];
try {
    $src_stmt = $conn->prepare("
        SELECT
            CASE
                WHEN report_source = 'external' THEN 'CIMMI'
                WHEN report_type IN ('infrastructure_issue','maintenance','maintenance_request') THEN 'Infrastructure'
                WHEN report_source = 'local' AND COALESCE(created_by, 0) != 0 THEN 'LGU Monitoring'
                ELSE 'Citizen'
            END as source_label,
            COUNT(*) as count
        FROM road_transportation_reports
        GROUP BY source_label
        ORDER BY count DESC
    ");
    $src_stmt->execute();
    $reports_by_source = $src_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $src_stmt->close();
} catch (Exception $e) {
    error_log("Reports by source error: " . $e->getMessage());
}

// Reports by category (road vs transportation)
$reports_by_category = [];
try {
    $cat_stmt = $conn->prepare("SELECT report_category, COUNT(*) as count FROM road_transportation_reports GROUP BY report_category ORDER BY count DESC");
    $cat_stmt->execute();
    $reports_by_category = $cat_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cat_stmt->close();
} catch (Exception $e) {
    error_log("Reports by category error: " . $e->getMessage());
}

// Reports submitted last 30 days (for line chart)
$reports_last_30_days = [];
try {
    $days_stmt = $conn->prepare("
        SELECT DATE(created_at) as day, COUNT(*) as count
        FROM road_transportation_reports
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY day ASC
    ");
    $days_stmt->execute();
    $reports_last_30_days = $days_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $days_stmt->close();
} catch (Exception $e) {
    error_log("Reports last 30 days error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard - Account Management</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=3">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f5f3ee; min-height: 100vh; color: var(--text-primary); }
        body.dark-mode { background: var(--bg-page); }
        .admin-dash { margin-left: 250px; padding: 28px 32px; max-width: 100%; overflow-x: hidden; }

        .admin-dash .dashboard-header {
            background: #f4f7fb;
            border-radius: 14px;
            padding: 20px 26px;
            margin-bottom: 22px;
            border: 1px solid #d5dce8;
            box-shadow: var(--shadow-card);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }
        .admin-dash .welcome-text h1 {
            font-size: 22px; font-weight: 700; color: var(--text-primary);
            margin-bottom: 4px; display: flex; align-items: center; gap: 12px;
        }
        .admin-dash .header-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--color-primary-bg);
            color: var(--color-primary); font-size: 16px; box-shadow: none;
        }
        .admin-dash .welcome-text h1 i { color: inherit; margin-right: 0; }
        .admin-dash .welcome-text p { color: var(--text-secondary); font-size: 13px; }
        .admin-dash .date-time { color: var(--text-secondary); font-size: 13px; }
        .admin-dash .dt-chip {
            display: flex; align-items: center; gap: 10px;
            background: var(--color-primary-bg);
            border: 1px solid var(--border-default);
            border-radius: 14px; padding: 10px 14px;
        }
        .admin-dash .dt-chip i {
            color: var(--color-primary); font-size: 16px;
            width: 28px; height: 28px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: #f4f7fb;
        }
        .admin-dash #currentDate { font-weight: 600; color: var(--text-primary); font-size: 13px; }
        .admin-dash #currentTime { color: var(--text-secondary); font-size: 12px; margin-top: 1px; }

        .summary-row {
            display: grid; grid-template-columns: repeat(6, 1fr); gap: 16px; margin-bottom: 24px;
        }
        .summary-card {
            background: #f4f7fb; border-radius: 14px; padding: 18px 18px 16px;
            border: 1px solid #d5dce8; position: relative; overflow: hidden;
            box-shadow: var(--shadow-card);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .summary-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
        }
        .summary-card.blue::before { background: #1e3c72; }
        .summary-card.amber::before { background: var(--color-warning); }
        .summary-card.emerald::before { background: var(--color-success); }
        .summary-card.rose::before { background: var(--color-danger); }
        .summary-card.violet::before { background: #5a4e78; }
        .summary-card.cyan::before { background: #0e7490; }
        .summary-card.blue,
        .summary-card.amber,
        .summary-card.emerald,
        .summary-card.rose,
        .summary-card.violet,
        .summary-card.cyan { background: #f4f7fb; }
        .summary-card .card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .summary-card .card-icon {
            width: 42px; height: 42px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
        }
        .summary-card.blue .card-icon { background: rgba(59,130,246,0.14); color: #2563eb; }
        .summary-card.amber .card-icon { background: rgba(245,158,11,0.16); color: #d97706; }
        .summary-card.emerald .card-icon { background: rgba(16,185,129,0.16); color: #059669; }
        .summary-card.rose .card-icon { background: rgba(244,63,94,0.14); color: #e11d48; }
        .summary-card.violet .card-icon { background: rgba(139,92,246,0.16); color: #7c3aed; }
        .summary-card.cyan .card-icon { background: rgba(6,182,212,0.16); color: #0891b2; }
        .summary-card .card-value { font-size: 28px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.03em; }
        .summary-card .card-label { font-size: 12px; color: var(--text-secondary); font-weight: 600; margin-top: 2px; }

        .main-grid { display: grid; grid-template-columns: 1fr 380px; gap: 24px; margin-bottom: 24px; }
        .left-col, .right-col { min-width: 0; }

        .admin-dash .card {
            background: #f4f7fb;
            border-radius: 14px;
            padding: 20px;
            border: 1px solid #d5dce8;
            margin-bottom: 20px;
            box-shadow: var(--shadow-card);
            position: relative;
            overflow: hidden;
            transition: box-shadow 0.2s ease, transform 0.2s ease;
        }
        .admin-dash .card.card-flush { margin-bottom: 0; }
        .admin-dash .card::after {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
        }
        .admin-dash .panel-chart::after { background: var(--color-primary); }
        .admin-dash .panel-awaiting::after { background: var(--color-info); }
        .admin-dash .panel-activity::after { background: var(--color-purple); }
        .admin-dash .panel-approvals::after { background: var(--color-warning); }
        .admin-dash .panel-priority::after { background: var(--color-danger); }
        .admin-dash .card-header {
            display: flex; justify-content: space-between; align-items: center; gap: 10px;
            margin: -20px -20px 16px; padding: 14px 18px 14px 20px;
            border-bottom: 1px solid var(--border-light);
            background: var(--bg-hover);
        }
        .admin-dash .panel-approvals .card-header { background: var(--color-warning-bg); }
        .admin-dash .panel-priority .card-header { background: var(--color-danger-bg); }
        .admin-dash .panel-awaiting .card-header { background: var(--color-info-bg); }
        .admin-dash .panel-activity .card-header { background: var(--color-purple-bg); }
        .admin-dash .card-title {
            font-size: 14px; font-weight: 600; color: var(--text-primary);
            display: flex; align-items: center; gap: 10px;
        }
        .admin-dash .title-icon {
            width: 30px; height: 30px; border-radius: 9px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0;
        }
        .admin-dash .panel-chart .title-icon { background: var(--color-primary-bg); color: var(--color-primary); }
        .admin-dash .panel-awaiting .title-icon { background: var(--color-info-bg); color: var(--color-info); }
        .admin-dash .panel-activity .title-icon { background: var(--color-purple-bg); color: var(--color-purple); }
        .admin-dash .panel-approvals .title-icon { background: var(--color-warning-bg); color: var(--color-warning); }
        .admin-dash .panel-priority .title-icon { background: var(--color-danger-bg); color: var(--color-danger); }
        .admin-dash .card-title i { color: inherit; font-size: 13px; }

        .chart-container { position: relative; width: 100%; height: 260px; }
        .chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .chart-grid .chart-card:first-child { grid-column: 1 / -1; }

        .admin-dash .table-container {
            overflow-x: auto; border: 1px solid var(--border-light);
            border-radius: 12px; background: #f4f7fb;
        }
        .admin-dash table { width: 100%; border-collapse: collapse; }
        .admin-dash th, .admin-dash td {
            padding: 11px 12px; text-align: left; border-bottom: 1px solid var(--border-light); font-size: 13px;
        }
        .admin-dash th {
            background: var(--bg-hover); font-weight: 600; color: var(--text-secondary);
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
        }
        .admin-dash td { color: var(--text-primary); }
        .admin-dash tbody tr { transition: background 0.15s ease; }
        .admin-dash tbody tr:hover td { background: var(--bg-hover); }
        .admin-dash tbody tr:last-child td { border-bottom: none; }
        .admin-dash .empty-cell {
            text-align: center; color: var(--text-muted) !important; padding: 28px 16px !important;
        }
        .admin-dash .empty-state {
            text-align: center; color: var(--text-muted); padding: 22px 12px;
        }
        .admin-dash .empty-state i {
            display: block; font-size: 22px; margin-bottom: 8px; color: var(--text-muted); opacity: 0.8;
        }
        .admin-dash .mono-id { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
        .admin-dash .muted-date { font-size: 12px; color: var(--text-secondary) !important; }

        .admin-dash .badge {
            display: inline-block; padding: 3px 9px; border-radius: 999px;
            font-size: 11px; font-weight: 600; letter-spacing: 0.01em;
        }
        .admin-dash .badge-pending { background: var(--badge-pending-bg); color: var(--badge-pending-text); }
        .admin-dash .badge-in-progress { background: var(--badge-in-progress-bg); color: var(--badge-in-progress-text); }
        .admin-dash .badge-completed,
        .admin-dash .badge-approved { background: var(--badge-completed-bg); color: var(--badge-completed-text); }
        .admin-dash .badge-cancelled { background: var(--badge-cancelled-bg); color: var(--badge-cancelled-text); }
        .admin-dash .badge-high { background: var(--priority-high-bg); color: var(--priority-high-text); }
        .admin-dash .badge-medium { background: var(--priority-medium-bg); color: var(--priority-medium-text); }
        .admin-dash .badge-low { background: var(--priority-low-bg); color: var(--priority-low-text); }
        .admin-dash .badge-citizen { background: var(--badge-in-progress-bg); color: var(--badge-in-progress-text); }
        .admin-dash .badge-cimm { background: var(--color-cimm-bg); color: var(--color-cimm-text); }
        .admin-dash .badge-infrastructure { background: var(--color-purple-bg); color: var(--color-purple-text); }
        .admin-dash .badge-lgu { background: var(--badge-approved-bg); color: var(--badge-approved-text); }

        .admin-dash .btn-sm {
            padding: 6px 11px; font-size: 11px; border: none; border-radius: 8px;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            font-weight: 600; text-decoration: none; white-space: nowrap;
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease, filter 0.15s ease;
        }
        .admin-dash .btn-sm:hover { transform: translateY(-1px); }
        .admin-dash .btn-primary {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            color: #fff; box-shadow: 0 4px 12px rgba(55, 98, 200, 0.25);
        }
        .admin-dash .btn-primary:hover { filter: brightness(1.06); color: #fff; }
        .admin-dash .btn-review {
            background: linear-gradient(135deg, var(--color-warning-light), var(--color-warning-dark));
            color: #fff; box-shadow: 0 4px 12px rgba(217, 119, 6, 0.25);
        }
        .admin-dash .btn-review:hover { filter: brightness(1.06); color: #fff; }
        .admin-dash .btn-success { background: var(--color-success); color: #fff; }
        .admin-dash .btn-danger { background: var(--color-danger); color: #fff; }
        .admin-dash .btn-warning { background: var(--color-warning); color: #fff; }

        .activity-list { max-height: 320px; overflow-y: auto; padding-right: 4px; }
        .activity-item {
            display: flex; gap: 12px; padding: 10px 8px;
            border-bottom: 1px solid var(--border-light); border-radius: 10px;
            transition: background 0.15s ease;
        }
        .activity-item:hover { background: var(--bg-hover); }
        .activity-item:last-child { border-bottom: none; }
        .activity-dot {
            width: 10px; height: 10px; border-radius: 50%; margin-top: 5px; flex-shrink: 0;
            box-shadow: 0 0 0 4px rgba(55, 98, 200, 0.12);
        }
        .activity-dot.dot-info { background: var(--color-primary); box-shadow: 0 0 0 4px var(--color-primary-bg); }
        .activity-dot.dot-success { background: var(--color-success); box-shadow: 0 0 0 4px var(--color-success-bg); }
        .activity-dot.dot-danger { background: var(--color-danger); box-shadow: 0 0 0 4px var(--color-danger-bg); }
        .activity-dot.dot-warning { background: var(--color-warning); box-shadow: 0 0 0 4px var(--color-warning-bg); }
        .activity-content { flex: 1; min-width: 0; }
        .activity-action { font-size: 13px; color: var(--text-primary); font-weight: 500; }
        .activity-time { font-size: 11px; color: var(--text-muted) !important; margin-top: 2px; }

        .widget-item {
            display: flex; align-items: center; gap: 10px; padding: 10px 8px;
            border-bottom: 1px solid var(--border-light); border-radius: 10px;
            transition: background 0.15s ease;
        }
        .widget-item:hover { background: var(--bg-hover); }
        .widget-item:last-child { border-bottom: none; }
        .widget-avatar {
            width: 36px; height: 36px; border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 600; color: #fff; flex-shrink: 0;
        }
        .widget-avatar.avatar-warning { background: linear-gradient(135deg, #f59e0b, #d97706); }
        .widget-avatar.avatar-danger { background: linear-gradient(135deg, #f43f5e, #e11d48); }
        .widget-info { flex: 1; min-width: 0; }
        .widget-title {
            font-size: 13px; font-weight: 600; color: var(--text-primary);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .widget-meta { font-size: 11px; color: var(--text-muted) !important; }
        .widget-badge { flex-shrink: 0; }

        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0;
            width: 100%; height: 100%; background-color: var(--bg-overlay);
            align-items: center; justify-content: center;
        }
        .modal-content {
            background-color: #f4f7fb; padding: 28px; border-radius: 14px;
            width: 90%; max-width: 480px; border: 1px solid #d5dce8;
            box-shadow: var(--shadow-lg); color: var(--text-primary);
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-title { font-size: 16px; font-weight: 600; color: var(--text-primary); }
        .close { font-size: 24px; cursor: pointer; color: var(--text-muted); }
        .close:hover { color: var(--color-danger); }

        .workflow-container { margin-bottom: 24px; }
        .workflow-card {
            background: #f4f7fb; border-radius: 14px; padding: 20px;
            border: 1px solid #d5dce8; box-shadow: var(--shadow-card);
        }
        .workflow-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--border-light);
        }
        .workflow-title { font-size: 14px; font-weight: 600; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .workflow-title i { color: var(--color-danger); }
        .workflow-badge { background: var(--color-danger); color: #fff; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .workflow-content { max-height: 360px; overflow-y: auto; }

        @media (max-width: 1400px) {
            .summary-row { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 1100px) {
            .main-grid { grid-template-columns: 1fr; }
            .chart-grid { grid-template-columns: 1fr; }
            .chart-grid .chart-card:first-child { grid-column: 1 / -1; }
        }
        @media (max-width: 768px) {
            .admin-dash { margin-left: 0; padding: 16px; }
            .summary-row { grid-template-columns: repeat(2, 1fr); }
            .admin-dash .dashboard-header { flex-direction: column; align-items: flex-start; }
            .admin-dash .modal-content { max-width: 96vw; }
        }
        @media (max-width: 480px) {
            .summary-row { grid-template-columns: 1fr; }
            .admin-dash .header-icon { width: 36px; height: 36px; }
        }

        body.dark-mode .admin-dash .dashboard-header {
            background: #1c2432 !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .admin-dash .header-icon {
            background: var(--color-primary-bg);
            color: var(--color-primary);
            box-shadow: none;
        }
        body.dark-mode .admin-dash .dt-chip { background: var(--color-primary-bg); border-color: var(--border-default); }
        body.dark-mode .admin-dash .dt-chip i { background: #1c2432; }
        body.dark-mode .admin-dash .card,
        body.dark-mode .admin-dash .summary-card,
        body.dark-mode .admin-dash .workflow-card,
        body.dark-mode .admin-dash .table-container,
        body.dark-mode .admin-dash .modal-content {
            background: #1c2432 !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .admin-dash .card-header {
            background: rgba(255,255,255,0.03) !important;
            border-color: var(--border-default) !important;
        }
        body.dark-mode .admin-dash .panel-approvals .card-header { background: var(--color-warning-bg) !important; }
        body.dark-mode .admin-dash .panel-priority .card-header { background: var(--color-danger-bg) !important; }
        body.dark-mode .admin-dash .panel-awaiting .card-header { background: var(--color-info-bg) !important; }
        body.dark-mode .admin-dash .panel-activity .card-header { background: var(--color-purple-bg) !important; }
        body.dark-mode .admin-dash .card-title,
        body.dark-mode .admin-dash .card-title span,
        body.dark-mode .admin-dash .widget-title,
        body.dark-mode .admin-dash .activity-action,
        body.dark-mode .admin-dash th,
        body.dark-mode .admin-dash td { color: var(--text-primary); }
        body.dark-mode .admin-dash .card-label,
        body.dark-mode .admin-dash .welcome-text p,
        body.dark-mode .admin-dash .muted-date { color: var(--text-secondary) !important; }
        body.dark-mode .admin-dash .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
            color: #fff !important; border-color: transparent !important;
        }
        body.dark-mode .admin-dash .btn-review { color: #fff !important; }
        body.dark-mode .admin-dash .badge-pending { background: var(--badge-pending-bg) !important; color: var(--badge-pending-text) !important; }
        body.dark-mode .admin-dash .badge-in-progress { background: var(--badge-in-progress-bg) !important; color: var(--badge-in-progress-text) !important; }
        body.dark-mode .admin-dash .badge-completed,
        body.dark-mode .admin-dash .badge-approved { background: var(--badge-completed-bg) !important; color: var(--badge-completed-text) !important; }
        body.dark-mode .admin-dash .badge-cancelled { background: var(--badge-cancelled-bg) !important; color: var(--badge-cancelled-text) !important; }
        body.dark-mode .admin-dash .badge-high { background: var(--priority-high-bg) !important; color: var(--priority-high-text) !important; }
        body.dark-mode .admin-dash .badge-medium { background: var(--priority-medium-bg) !important; color: var(--priority-medium-text) !important; }
        body.dark-mode .admin-dash .badge-low { background: var(--priority-low-bg) !important; color: var(--priority-low-text) !important; }
        body.dark-mode .admin-dash .badge-citizen { background: var(--badge-in-progress-bg) !important; color: var(--badge-in-progress-text) !important; }
        body.dark-mode .admin-dash .badge-cimm { background: var(--color-cimm-bg) !important; color: var(--color-cimm-text) !important; }
        body.dark-mode .admin-dash .badge-infrastructure { background: var(--color-purple-bg) !important; color: var(--color-purple-text) !important; }
        body.dark-mode .admin-dash .badge-lgu { background: var(--badge-approved-bg) !important; color: var(--badge-approved-text) !important; }
        body.dark-mode .admin-dash .modal-content { background: #1c2432 !important; border-color: rgba(147, 179, 224, 0.22) !important; }
        body.dark-mode .admin-dash .modal-header { background: transparent !important; }

        <?php if (($_SESSION['role'] ?? '') === 'system_admin'): ?>
        .summary-card { cursor: pointer; }
        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }
        body.dark-mode .summary-card.blue,
        body.dark-mode .summary-card.amber,
        body.dark-mode .summary-card.emerald,
        body.dark-mode .summary-card.rose,
        body.dark-mode .summary-card.violet,
        body.dark-mode .summary-card.cyan { background: #1c2432 !important; border-color: rgba(147, 179, 224, 0.22) !important; }
        body.dark-mode .summary-card.blue .card-icon { background: rgba(96, 165, 250, 0.18); color: #60a5fa; }
        body.dark-mode .summary-card.amber .card-icon { background: rgba(251, 191, 36, 0.18); color: #fbbf24; }
        body.dark-mode .summary-card.emerald .card-icon { background: rgba(52, 211, 153, 0.18); color: #34d399; }
        body.dark-mode .summary-card.rose .card-icon { background: rgba(248, 113, 113, 0.18); color: #f87171; }
        body.dark-mode .summary-card.violet .card-icon { background: rgba(167, 139, 250, 0.18); color: #a78bfa; }
        body.dark-mode .summary-card.cyan .card-icon { background: rgba(56, 189, 248, 0.18); color: #38bdf8; }
        body.dark-mode .summary-card.blue .card-value { color: #93c5fd !important; }
        body.dark-mode .summary-card.amber .card-value { color: #fcd34d !important; }
        body.dark-mode .summary-card.emerald .card-value { color: #6ee7b7 !important; }
        body.dark-mode .summary-card.rose .card-value { color: #fca5a5 !important; }
        body.dark-mode .summary-card.violet .card-value { color: #c4b5fd !important; }
        body.dark-mode .summary-card.cyan .card-value { color: #7dd3fc !important; }
        body.dark-mode .summary-card.blue .card-label { color: #bfdbfe !important; }
        body.dark-mode .summary-card.amber .card-label { color: #fde68a !important; }
        body.dark-mode .summary-card.emerald .card-label { color: #a7f3d0 !important; }
        body.dark-mode .summary-card.rose .card-label { color: #fecaca !important; }
        body.dark-mode .summary-card.violet .card-label { color: #ddd6fe !important; }
        body.dark-mode .summary-card.cyan .card-label { color: #bae6fd !important; }
        body.dark-mode .summary-card:hover { box-shadow: 0 10px 28px rgba(0,0,0,0.45); }

        .ds-tooltip {
            position: fixed; z-index: 9999; pointer-events: none;
            background: var(--bg-tooltip); color: var(--text-inverse);
            padding: 7px 11px; border-radius: 8px;
            font-size: 12px; font-weight: 600; line-height: 1.4;
            box-shadow: var(--shadow-lg);
            white-space: nowrap; max-width: 340px;
            opacity: 0; visibility: hidden;
            transform: translateY(4px);
            transition: opacity 0.12s ease, transform 0.12s ease;
        }
        .ds-tooltip.show { opacity: 1; visibility: visible; transform: translateY(0); }
        .ds-tooltip .tip-dot {
            display: inline-block; width: 8px; height: 8px; border-radius: 50%;
            margin-right: 6px; vertical-align: middle;
        }
        .ds-tooltip .tip-label { color: inherit; }
        .ds-tooltip .tip-value { color: var(--color-primary-light); font-weight: 700; }
        body.dark-mode .ds-tooltip { background: #0f172a; color: #f1f5f9; }
        body.dark-mode .ds-tooltip .tip-value { color: #93c5fd; }
        <?php endif; ?>
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content admin-dash">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-text">
                <h1><span class="header-icon"><i class="fas fa-gauge-high"></i></span> Admin Dashboard</h1>
                <p>Road &amp; Transportation Monitoring System</p>
            </div>
            <div class="date-time">
                <div class="dt-chip">
                    <i class="fas fa-calendar-day"></i>
                    <div>
                        <div id="currentDate"></div>
                        <div id="currentTime"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-row">
            <div class="summary-card blue" data-source="road_transportation_reports">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-clipboard-list"></i></div>
                </div>
                <div class="card-value"><?php echo $quick_insights['total_reports']; ?></div>
                <div class="card-label">Total Reports</div>
            </div>
            <div class="summary-card amber" data-source="road_transportation_reports">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-hourglass-half"></i></div>
                </div>
                <div class="card-value"><?php echo $quick_insights['pending_reports']; ?></div>
                <div class="card-label">Pending</div>
            </div>
            <div class="summary-card cyan" data-source="road_transportation_reports">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-gears"></i></div>
                </div>
                <div class="card-value"><?php echo $quick_insights['in_progress_reports']; ?></div>
                <div class="card-label">In Progress</div>
            </div>
            <div class="summary-card emerald" data-source="road_transportation_reports">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-circle-check"></i></div>
                </div>
                <div class="card-value"><?php echo $quick_insights['completed_reports']; ?></div>
                <div class="card-label">Completed</div>
            </div>
            <div class="summary-card rose" data-source="road_transportation_reports">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-bolt"></i></div>
                </div>
                <div class="card-value"><?php echo $quick_insights['high_priority']; ?></div>
                <div class="card-label">High Priority</div>
            </div>
            <div class="summary-card violet" data-source="users">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-users"></i></div>
                </div>
                <div class="card-value"><?php echo $stats['approved_users']; ?></div>
                <div class="card-label">Total Users</div>
            </div>
        </div>

        <!-- Main Grid: 70/30 -->
        <div class="main-grid">
            <!-- Left Column (70%) -->
            <div class="left-col">
                <!-- Reports Submitted Last 30 Days -->
                <div class="card panel-chart" data-source="road_transportation_reports">
                    <div class="card-header">
                        <h3 class="card-title"><span class="title-icon"><i class="fas fa-chart-line"></i></span> Reports Submitted (Last 30 Days)</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="reportsTrend30DayChart"></canvas>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="chart-grid">
                    <div class="card card-flush panel-chart" data-source="road_transportation_reports">
                        <div class="card-header">
                            <h3 class="card-title"><span class="title-icon"><i class="fas fa-chart-column"></i></span> Reports by Status</h3>
                        </div>
                        <div class="chart-container" style="height: 200px; max-width: 320px; margin: 0 auto;">
                            <canvas id="reportsByStatusChart"></canvas>
                        </div>
                    </div>
                    <div class="card card-flush panel-chart" data-source="users">
                        <div class="card-header">
                            <h3 class="card-title"><span class="title-icon"><i class="fas fa-chart-pie"></i></span> User Accounts</h3>
                        </div>
                        <div class="chart-container" style="height: 240px; max-width: 320px; margin: 0 auto;">
                            <canvas id="userAccountsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Awaiting for Assignments -->
                <div class="card panel-awaiting" data-source="road_transportation_reports">
                    <div class="card-header">
                        <h3 class="card-title"><span class="title-icon"><i class="fas fa-user-plus"></i></span> Awaiting for Assignments</h3>
                        <a href="report_management.php?assignment=unassigned" class="btn-sm btn-primary"><i class="fas fa-arrow-right"></i> View All</a>
                    </div>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Report ID</th>
                                    <th>Title</th>
                                    <th>Source</th>
                                    <th>Priority</th>
                                    <th>Status</th>
                                    <th>Submitted</th>
                                    <th>View</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($awaiting_assignment_reports)): ?>
                                    <tr><td colspan="7" class="empty-cell"><i class="fas fa-inbox" style="margin-right:6px;"></i>No unassigned reports.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($awaiting_assignment_reports as $lr): ?>
                                    <?php
                                        $lr_source = (string)($lr['rm_source'] ?? 'lgu_reports');
                                        $lr_id = (int)($lr['id'] ?? 0);
                                        $lr_status = ucfirst(str_replace('-', ' ', (string)($lr['status'] ?? '')));
                                        $lr_priority = strtolower((string)($lr['priority'] ?? 'medium'));
                                        $lr_label = (string)($lr['source_label'] ?? 'Citizen');
                                        $lr_badge = 'citizen';
                                        if ($lr_source === 'lgu_reports') $lr_badge = 'lgu';
                                        elseif ($lr_source === 'cimm') $lr_badge = 'cimm';
                                        elseif ($lr_source === 'maintenance') $lr_badge = 'infrastructure';
                                        $lr_view = 'report_management.php?source=' . rawurlencode($lr_source) . '&amp;id=' . $lr_id . '&amp;open=1';
                                    ?>
                                    <tr>
                                        <td class="mono-id"><?php echo htmlspecialchars((string)($lr['report_id'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($lr['title'] ?? 'Untitled'); ?></td>
                                        <td><span class="badge badge-<?php echo htmlspecialchars($lr_badge); ?>"><?php echo htmlspecialchars($lr_label); ?></span></td>
                                        <td><span class="badge badge-<?php echo htmlspecialchars($lr_priority); ?>"><?php echo ucfirst(htmlspecialchars($lr_priority)); ?></span></td>
                                        <td><span class="badge badge-<?php echo htmlspecialchars(strtolower(str_replace(' ', '-', (string)($lr['status'] ?? '')))); ?>"><?php echo htmlspecialchars($lr_status); ?></span></td>
                                        <td class="muted-date"><?php echo !empty($lr['created_at']) ? date('M d, Y', strtotime($lr['created_at'])) : '—'; ?></td>
                                        <td><?php if ($lr_id > 0): ?><a href="<?php echo $lr_view; ?>" class="btn-sm btn-primary"><i class="fas fa-eye"></i> View</a><?php endif; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right Column (30%) -->
            <div class="right-col">
                <!-- Recent Activity -->
                <div class="card panel-activity" data-source="audit_logs, users">
                    <div class="card-header">
                        <h3 class="card-title"><span class="title-icon"><i class="fas fa-clock-rotate-left"></i></span> Recent Activity</h3>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($recent_activity)): ?>
                            <div class="empty-state"><i class="fas fa-bell-slash"></i>No recent activity.</div>
                        <?php else: ?>
                            <?php foreach (array_slice($recent_activity, 0, 8) as $ra): ?>
                                <?php
                                $dot_class = 'dot-info';
                                foreach (['approve' => 'dot-success', 'reject' => 'dot-danger', 'delete' => 'dot-danger', 'complete' => 'dot-success', 'cancel' => 'dot-warning'] as $k => $c) {
                                    if (stripos($ra['action'], $k) !== false) { $dot_class = $c; break; }
                                }
                                ?>
                                <div class="activity-item">
                                    <div class="activity-dot <?php echo $dot_class; ?>"></div>
                                    <div class="activity-content">
                                        <div class="activity-action"><?php echo htmlspecialchars($ra['action']); ?></div>
                                        <div class="activity-time"><?php echo htmlspecialchars($ra['user_name'] ?? 'System'); ?> &middot; <?php echo date('M d, g:ia', strtotime($ra['created_at'])); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Pending Approvals -->
                <div class="card panel-approvals" data-source="users">
                    <div class="card-header">
                        <h3 class="card-title"><span class="title-icon"><i class="fas fa-user-check"></i></span> Pending Approvals</h3>
                        <span class="badge badge-pending"><?php echo (int)$pending_approvals_count; ?></span>
                    </div>
                    <div class="activity-list">
                        <?php if ($pending_approvals_count === 0 || empty($pending_users_list)): ?>
                            <div class="empty-state"><i class="fas fa-user-clock"></i>No pending approvals.</div>
                        <?php else: ?>
                            <?php foreach ($pending_users_list as $pu): ?>
                                <div class="widget-item">
                                    <div class="widget-avatar avatar-warning"><i class="fas fa-user"></i></div>
                                    <div class="widget-info">
                                        <div class="widget-title"><?php echo htmlspecialchars($pu['full_name']); ?></div>
                                        <div class="widget-meta"><?php echo htmlspecialchars($pu['role']); ?> &middot; <?php echo date('M d', strtotime($pu['created_at'])); ?></div>
                                    </div>
                                    <a href="account_approvals.php" class="btn-sm btn-review"><i class="fas fa-clipboard-check"></i> Review</a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- High Priority Reports -->
                <div class="card panel-priority" data-source="road_transportation_reports">
                    <div class="card-header">
                        <h3 class="card-title"><span class="title-icon"><i class="fas fa-bolt"></i></span> High Priority</h3>
                        <span class="badge badge-high"><?php echo (int)$high_priority_panel_count; ?></span>
                    </div>
                    <div class="activity-list">
                        <?php if ($high_priority_panel_count === 0 || empty($high_priority_reports)): ?>
                            <div class="empty-state"><i class="fas fa-shield-halved"></i>No high priority reports.</div>
                        <?php else: ?>
                            <?php foreach ($high_priority_reports as $hp): ?>
                                <?php
                                    $hp_status = ucfirst(str_replace('-', ' ', (string)($hp['status'] ?? '')));
                                ?>
                                <div class="widget-item">
                                    <div class="widget-avatar avatar-danger"><i class="fas fa-triangle-exclamation"></i></div>
                                    <div class="widget-info">
                                        <div class="widget-title"><?php echo htmlspecialchars($hp['title'] ?? 'Untitled'); ?></div>
                                        <div class="widget-meta"><?php echo htmlspecialchars($hp['report_id'] ?? ''); ?> &middot; <?php echo htmlspecialchars($hp_status); ?></div>
                                    </div>
                                    <a href="report_management.php?focus_report_id=<?php echo (int)($hp['id'] ?? 0); ?>" class="btn-sm btn-primary"><i class="fas fa-eye"></i> View</a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Section: Charts -->
        <div class="chart-grid">
            <div class="card card-flush panel-chart" data-source="road_transportation_reports">
                <div class="card-header">
                    <h3 class="card-title"><span class="title-icon"><i class="fas fa-chart-pie"></i></span> Reports by Source</h3>
                </div>
                <div class="chart-container" style="height: 240px; max-width: 320px; margin: 0 auto;">
                    <canvas id="reportsBySourceChart"></canvas>
                </div>
            </div>
            <div class="card card-flush panel-chart" data-source="road_transportation_reports">
                <div class="card-header">
                    <h3 class="card-title"><span class="title-icon"><i class="fas fa-road"></i></span> Reports by Category</h3>
                </div>
                <div class="chart-container" style="height: 240px; max-width: 320px; margin: 0 auto;">
                    <canvas id="reportsByCategoryChart"></canvas>
                </div>
            </div>
        </div>
        <div class="card panel-chart" data-source="road_transportation_reports">
            <div class="card-header">
                <h3 class="card-title"><span class="title-icon"><i class="fas fa-calendar-alt"></i></span> Monthly Trend</h3>
            </div>
            <div class="chart-container">
                <canvas id="reportsTrendChart"></canvas>
            </div>
        </div>
    </div>

    <script>
        // Update date and time
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', dateOptions);
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        
        updateDateTime();
        setInterval(updateDateTime, 1000);

        const isDarkDash = document.body.classList.contains('dark-mode');
        const chartText = isDarkDash ? '#e4e6ea' : '#334155';
        const chartMuted = isDarkDash ? '#9ca3af' : '#64748b';
        const chartGrid = isDarkDash ? 'rgba(255,255,255,0.08)' : 'rgba(15, 23, 42, 0.06)';
        const chartCutoutBorder = isDarkDash ? '#1a1d24' : '#ffffff';
        if (window.Chart) {
            Chart.defaults.color = chartText;
            Chart.defaults.font.family = 'Poppins, sans-serif';
            Chart.defaults.borderColor = chartGrid;
        }

        // Chart Data from PHP
        const userStats = {
            pending: <?php echo $stats['pending_users']; ?>,
            approved: <?php echo $stats['approved_users']; ?>,
            inactive: <?php echo $stats['inactive_2weeks']; ?>,
            deactivated: <?php echo $stats['deactivated_users']; ?>
        };

        const reportsByStatus = <?php echo json_encode($report_stats['by_status']); ?>;
        const reportsByMonth = <?php echo json_encode($report_stats['by_month']); ?>;
        const reportsByType = <?php echo json_encode($report_stats['by_type']); ?>;
        const reportsBySource = <?php echo json_encode($reports_by_source); ?>;
        const reportsByCategory = <?php echo json_encode($reports_by_category); ?>;
        const reportsLast30Days = <?php echo json_encode($reports_last_30_days); ?>;

        // Color palette
        const chartColors = {
            pending: '#f59e0b',
            approved: '#10b981',
            inactive: '#6b7280',
            deactivated: '#ef4444',
            statusColors: ['#f59e0b', '#3b82f6', '#10b981', '#6b7280', '#ef4444', '#8b5cf6'],
            typeColors: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4', '#84cc16', '#f97316']
        };

        // User Accounts Doughnut Chart
        const userAccountsCtx = document.getElementById('userAccountsChart').getContext('2d');
        const userAccountsTotal = userStats.pending + userStats.approved + userStats.inactive + userStats.deactivated;
        new Chart(userAccountsCtx, {
            type: 'doughnut',
            data: {
                labels: ['Pending', 'Approved', 'Inactive (2+ Weeks)', 'Deactivated'],
                datasets: [{
                    data: [userStats.pending, userStats.approved, userStats.inactive, userStats.deactivated],
                    backgroundColor: [chartColors.pending, chartColors.approved, chartColors.inactive, chartColors.deactivated],
                    borderWidth: 2,
                    borderColor: chartCutoutBorder
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            padding: 6,
                            usePointStyle: true,
                            boxWidth: 10,
                            font: { size: 10 },
                            generateLabels: function(chart) {
                                const data = chart.data;
                                return data.labels.map((label, i) => ({
                                    text: label + ': ' + data.datasets[0].data[i],
                                    fillStyle: data.datasets[0].backgroundColor[i],
                                    hidden: false,
                                    index: i,
                                    pointStyle: 'circle'
                                }));
                            }
                        }
                    }
                },
                cutout: '65%'
            },
            plugins: [{
                id: 'centerText',
                afterDraw: function(chart) {
                    const ctx = chart.ctx;
                    const width = chart.width;
                    const height = chart.height;
                    ctx.restore();
                    // Draw total number
                    const fontSize = (height / 116).toFixed(2);
                    ctx.font = 'bold ' + fontSize + 'em Poppins';
                    ctx.textBaseline = 'middle';
                    ctx.fillStyle = chartText;
                    const text = userAccountsTotal;
                    const textX = Math.round((width - ctx.measureText(text).width) / 2);
                    const textY = height / 2 - 8;
                    ctx.fillText(text, textX, textY);
                    // Draw label
                    ctx.font = (fontSize * 0.4).toFixed(2) + 'em Poppins';
                    ctx.fillStyle = chartMuted;
                    const label = 'Total Users';
                    const labelX = Math.round((width - ctx.measureText(label).width) / 2);
                    ctx.fillText(label, labelX, textY + 18);
                    ctx.save();
                }
            }]
        });

        // Reports by Status Bar Chart
        const reportsByStatusCtx = document.getElementById('reportsByStatusChart').getContext('2d');
        const statusLabels = reportsByStatus.map(r => r.status.charAt(0).toUpperCase() + r.status.slice(1));
        const statusData = reportsByStatus.map(r => r.count);
        new Chart(reportsByStatusCtx, {
            type: 'bar',
            data: {
                labels: statusLabels,
                datasets: [{
                    label: 'Reports',
                    data: statusData,
                    backgroundColor: chartColors.statusColors.slice(0, statusLabels.length),
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, font: { size: 12 } },
                        grid: { color: chartGrid }
                    },
                    x: {
                        ticks: { font: { size: 11 } },
                        grid: { display: false }
                    }
                }
            }
        });

        // Reports Trend Line Chart
        const reportsTrendCtx = document.getElementById('reportsTrendChart').getContext('2d');
        const monthLabels = reportsByMonth.map(r => r.month_name);
        const monthData = reportsByMonth.map(r => r.count);
        new Chart(reportsTrendCtx, {
            type: 'line',
            data: {
                labels: monthLabels.length > 0 ? monthLabels : ['No Data'],
                datasets: [{
                    label: 'Reports Submitted',
                    data: monthData.length > 0 ? monthData : [0],
                    borderColor: '#3762c8',
                    backgroundColor: 'rgba(55, 98, 200, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#3762c8',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { usePointStyle: true, font: { size: 12 } }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, font: { size: 12 } },
                        grid: { color: chartGrid }
                    },
                    x: {
                        ticks: { font: { size: 12 } },
                        grid: { display: false }
                    }
                }
            }
        });

        // Reports Submitted Last 30 Days Line Chart
        const reports30DayCtx = document.getElementById('reportsTrend30DayChart').getContext('2d');
        const dayLabels = reportsLast30Days.map(r => { const d = new Date(r.day); return (d.getMonth()+1) + '/' + d.getDate(); });
        const dayData = reportsLast30Days.map(r => r.count);
        // Fill in missing days with 0
        const filledLabels = []; const filledData = [];
        for (let i = 29; i >= 0; i--) {
            const d = new Date(); d.setDate(d.getDate() - i);
            const key = d.toISOString().split('T')[0];
            const label = (d.getMonth()+1) + '/' + d.getDate();
            filledLabels.push(label);
            const found = reportsLast30Days.find(x => x.day === key);
            filledData.push(found ? found.count : 0);
        }
        new Chart(reports30DayCtx, {
            type: 'line',
            data: {
                labels: filledLabels,
                datasets: [{
                    label: 'Reports',
                    data: filledData,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59, 130, 246, 0.08)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: chartGrid } },
                    x: { ticks: { font: { size: 10 }, maxTicksLimit: 10 }, grid: { display: false } }
                }
            }
        });

        // Reports by Source Pie Chart
        const reportsSourceCtx = document.getElementById('reportsBySourceChart').getContext('2d');
        const sourceLabels = reportsBySource.map(r => r.source_label);
        const sourceData = reportsBySource.map(r => r.count);
        const sourceColors = ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ef4444'];
        new Chart(reportsSourceCtx, {
            type: 'doughnut',
            data: {
                labels: sourceLabels,
                datasets: [{
                    data: sourceData,
                    backgroundColor: sourceColors.slice(0, sourceLabels.length),
                    borderWidth: 2,
                    borderColor: chartCutoutBorder
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, font: { size: 11 } } }
                },
                cutout: '55%'
            }
        });

        // Reports by Category Doughnut Chart
        const reportsCategoryCtx = document.getElementById('reportsByCategoryChart').getContext('2d');
        const categoryLabels = reportsByCategory.map(r => r.report_category ? r.report_category.charAt(0).toUpperCase() + r.report_category.slice(1) : 'Unknown');
        const categoryData = reportsByCategory.map(r => r.count);
        new Chart(reportsCategoryCtx, {
            type: 'doughnut',
            data: {
                labels: categoryLabels,
                datasets: [{
                    data: categoryData,
                    backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6'],
                    borderWidth: 2,
                    borderColor: chartCutoutBorder
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { padding: 12, usePointStyle: true, font: { size: 11 } } }
                },
                cutout: '55%'
            }
        });

        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.display = 'none';
            });
        }, 5000);

        <?php if (($_SESSION['role'] ?? '') === 'system_admin'): ?>
        // Chart-style pop-up label only on summary-row cards (System Admin only)
        (function () {
            const tooltip = document.createElement('div');
            tooltip.className = 'ds-tooltip';
            tooltip.setAttribute('role', 'tooltip');
            document.body.appendChild(tooltip);

            const dotColors = {
                blue: '#3b82f6', amber: '#f59e0b', emerald: '#10b981',
                rose: '#f43f5e', violet: '#8b5cf6', cyan: '#06b6d4'
            };

            function getCardColor(el) {
                if (el.classList.contains('summary-card')) {
                    for (const c of ['blue', 'amber', 'emerald', 'rose', 'violet', 'cyan']) {
                        if (el.classList.contains(c)) return dotColors[c];
                    }
                }
                const icon = el.querySelector('.card-title i');
                return icon ? getComputedStyle(icon).color : '#3b82f6';
            }

            function positionTooltip(e) {
                const pad = 14;
                let x = e.clientX + pad;
                let y = e.clientY + pad;
                const tw = tooltip.offsetWidth;
                const th = tooltip.offsetHeight;
                if (x + tw > window.innerWidth - 8) x = e.clientX - tw - pad;
                if (y + th > window.innerHeight - 8) y = e.clientY - th - pad;
                tooltip.style.left = x + 'px';
                tooltip.style.top = y + 'px';
            }

            document.querySelectorAll('.summary-row .summary-card[data-source]').forEach(el => {
                el.addEventListener('mouseenter', (e) => {
                    const valueEl = el.querySelector('.card-value');
                    const labelEl = el.querySelector('.card-label');
                    const dot = '<span class="tip-dot" style="background:' + getCardColor(el) + '"></span>';
                    tooltip.innerHTML = dot + '<span class="tip-label">' + labelEl.textContent.trim() +
                        ': </span><span class="tip-value">' + valueEl.textContent.trim() + '</span>';
                    tooltip.classList.add('show');
                    positionTooltip(e);
                });
                el.addEventListener('mousemove', positionTooltip);
                el.addEventListener('mouseleave', () => tooltip.classList.remove('show'));
            });
        })();
        <?php endif; ?>

    </script>
    
 

</body>
</html>

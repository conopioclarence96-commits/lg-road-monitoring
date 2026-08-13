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

// Session timeout configuration
$session_timeout = 30 * 60; // 30 minutes in seconds

// Check if session has expired
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    // Session expired, destroy and redirect to login
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ../../login.php?timeout=1');
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();

require_once '../../includes/config.php';
require_once '../../includes/functions.php';

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

// Latest reports for the panel
$latest_reports = [];
try {
    $lrstmt = $conn->prepare("
        SELECT r.id, r.report_id, r.title, r.report_type, r.report_category, r.report_source,
               r.priority, r.status, r.created_at, r.created_by,
               u.full_name as reporter_name
        FROM road_transportation_reports r
        LEFT JOIN users u ON r.created_by = u.id
        ORDER BY r.created_at DESC
        LIMIT 10
    ");
    $lrstmt->execute();
    $latest_reports = $lrstmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $lrstmt->close();
} catch (Exception $e) {
    error_log("Latest reports error: " . $e->getMessage());
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
        body { background: #f8fafc; min-height: 100vh; color: #1e293b; }
        .main-content { margin-left: 250px; padding: 28px 32px; }

        /* Header */
        .dashboard-header {
            background: white; border-radius: 12px; padding: 20px 28px; margin-bottom: 24px;
            border: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center;
        }
        .welcome-text h1 { font-size: 20px; font-weight: 600; color: #1e293b; margin-bottom: 2px; }
        .welcome-text h1 i { color: #3b82f6; margin-right: 8px; }
        .welcome-text p { color: #64748b; font-size: 13px; }
        .date-time { text-align: right; color: #64748b; font-size: 13px; }

        /* Summary Cards */
        .summary-row {
            display: grid; grid-template-columns: repeat(6, 1fr); gap: 16px; margin-bottom: 24px;
        }
        .summary-card {
            background: white; border-radius: 12px; padding: 18px 20px;
            border: 1px solid #e2e8f0; position: relative; overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .summary-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
        }
        .summary-card.blue::before { background: #3b82f6; }
        .summary-card.amber::before { background: #f59e0b; }
        .summary-card.emerald::before { background: #10b981; }
        .summary-card.rose::before { background: #f43f5e; }
        .summary-card.violet::before { background: #8b5cf6; }
        .summary-card.cyan::before { background: #06b6d4; }
        .summary-card .card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px; }
        .summary-card .card-icon {
            width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center; font-size: 14px;
        }
        .summary-card.blue .card-icon { background: #eff6ff; color: #3b82f6; }
        .summary-card.amber .card-icon { background: #fffbeb; color: #f59e0b; }
        .summary-card.emerald .card-icon { background: #ecfdf5; color: #10b981; }
        .summary-card.rose .card-icon { background: #fff1f2; color: #f43f5e; }
        .summary-card.violet .card-icon { background: #f5f3ff; color: #8b5cf6; }
        .summary-card.cyan .card-icon { background: #ecfeff; color: #06b6d4; }
        .summary-card .card-value { font-size: 28px; font-weight: 700; color: #1e293b; }
        .summary-card .card-label { font-size: 12px; color: #64748b; font-weight: 500; }

        /* Main Layout 70/30 */
        .main-grid { display: grid; grid-template-columns: 1fr 380px; gap: 24px; margin-bottom: 24px; }
        .left-col { min-width: 0; }
        .right-col { min-width: 0; }

        /* Cards */
        .card {
            background: white; border-radius: 12px; padding: 20px;
            border: 1px solid #e2e8f0; margin-bottom: 20px;
        }
        .card-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;
        }
        .card-title { font-size: 14px; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 8px; }
        .card-title i { color: #3b82f6; font-size: 13px; }

        /* Charts */
        .chart-container { position: relative; width: 100%; height: 260px; }
        .chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .chart-grid .chart-card:first-child { grid-column: 1 / -1; }

        /* Table */
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        th { background: #f8fafc; font-weight: 600; color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; }
        td { color: #334155; }
        tr:hover td { background: #f8fafc; }

        /* Badges */
        .badge {
            display: inline-block; padding: 2px 8px; border-radius: 4px;
            font-size: 11px; font-weight: 500;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-in-progress { background: #dbeafe; color: #1e40af; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-high { background: #fee2e2; color: #991b1b; }
        .badge-medium { background: #fef3c7; color: #92400e; }
        .badge-low { background: #dcfce7; color: #166534; }
        .badge-citizen { background: #dbeafe; color: #1e40af; }
        .badge-cimm { background: #fef3c7; color: #92400e; }
        .badge-infrastructure { background: #e0e7ff; color: #3730a3; }
        .badge-lgu { background: #dcfce7; color: #166534; }

        /* Buttons */
        .btn-sm {
            padding: 5px 10px; font-size: 11px; border: none; border-radius: 6px;
            cursor: pointer; transition: all 0.15s; display: inline-flex; align-items: center; gap: 4px;
            font-weight: 500; text-decoration: none;
        }
        .btn-primary { background: #3b82f6; color: white; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: #10b981; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-warning { background: #f59e0b; color: white; }

        /* Activity Timeline */
        .activity-list { max-height: 320px; overflow-y: auto; }
        .activity-item { display: flex; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .activity-item:last-child { border-bottom: none; }
        .activity-dot {
            width: 8px; height: 8px; border-radius: 50%; margin-top: 6px; flex-shrink: 0;
        }
        .activity-content { flex: 1; }
        .activity-action { font-size: 13px; color: #334155; }
        .activity-time { font-size: 11px; color: #94a3b8; margin-top: 2px; }

        /* Sidebar Widgets */
        .widget-item { display: flex; align-items: center; gap: 10px; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .widget-item:last-child { border-bottom: none; }
        .widget-avatar {
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 600; color: white; flex-shrink: 0;
        }
        .widget-info { flex: 1; min-width: 0; }
        .widget-title { font-size: 13px; font-weight: 500; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .widget-meta { font-size: 11px; color: #94a3b8; }
        .widget-badge { flex-shrink: 0; }

        /* Modal */
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0;
            width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);
            align-items: center; justify-content: center;
        }
        .modal-content {
            background-color: white; padding: 28px; border-radius: 12px;
            width: 90%; max-width: 480px;
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-title { font-size: 16px; font-weight: 600; color: #1e293b; }
        .close { font-size: 24px; cursor: pointer; color: #94a3b8; }
        .close:hover { color: #f43f5e; }

        /* Responsive */
        @media (max-width: 1400px) {
            .summary-row { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 1100px) {
            .main-grid { grid-template-columns: 1fr; }
            .chart-grid { grid-template-columns: 1fr; }
            .chart-grid .chart-card:first-child { grid-column: 1 / -1; }
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 16px; }
            .summary-row { grid-template-columns: repeat(2, 1fr); }
        }

        /* Workflow Card (Inactive Users) */
        .workflow-container { margin-bottom: 24px; }
        .workflow-card {
            background: white; border-radius: 12px; padding: 20px;
            border: 1px solid #e2e8f0;
        }
        .workflow-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;
        }
        .workflow-title { font-size: 14px; font-weight: 600; color: #1e293b; display: flex; align-items: center; gap: 8px; }
        .workflow-title i { color: #f43f5e; }
        .workflow-badge { background: #f43f5e; color: white; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .workflow-content { max-height: 360px; overflow-y: auto; }

        <?php if (($_SESSION['role'] ?? '') === 'system_admin'): ?>
        /* Readable badge text in dark mode for System Admin only */
        .dark-mode .badge-pending       { background: rgba(180, 83, 9, 0.22); color: #fcd34d; }
        .dark-mode .badge-in-progress   { background: rgba(30, 64, 175, 0.35); color: #93c5fd; }
        .dark-mode .badge-completed     { background: rgba(4, 120, 87, 0.28); color: #6ee7b7; }
        .dark-mode .badge-cancelled     { background: rgba(185, 28, 28, 0.28); color: #fca5a5; }
        .dark-mode .badge-approved      { background: rgba(4, 120, 87, 0.28); color: #6ee7b7; }
        .dark-mode .badge-high          { background: rgba(220, 38, 38, 0.28); color: #fca5a5; }
        .dark-mode .badge-medium        { background: rgba(194, 65, 12, 0.25); color: #fdba74; }
        .dark-mode .badge-low           { background: rgba(5, 150, 105, 0.28); color: #6ee7b7; }
        .dark-mode .badge-citizen       { background: rgba(30, 64, 175, 0.35); color: #93c5fd; }
        .dark-mode .badge-cimm          { background: rgba(180, 83, 9, 0.22); color: #fcd34d; }
        .dark-mode .badge-infrastructure { background: rgba(67, 56, 202, 0.3); color: #c7d2fe; }
        .dark-mode .badge-lgu           { background: rgba(4, 120, 87, 0.28); color: #6ee7b7; }

        /* Dark mode summary cards */
        .dark-mode .summary-card.blue { background: rgba(96, 165, 250, 0.12); border-color: rgba(96, 165, 250, 0.35); }
        .dark-mode .summary-card.amber { background: rgba(251, 191, 36, 0.12); border-color: rgba(251, 191, 36, 0.35); }
        .dark-mode .summary-card.emerald { background: rgba(52, 211, 153, 0.12); border-color: rgba(52, 211, 153, 0.35); }
        .dark-mode .summary-card.rose { background: rgba(248, 113, 113, 0.12); border-color: rgba(248, 113, 113, 0.35); }
        .dark-mode .summary-card.violet { background: rgba(167, 139, 250, 0.12); border-color: rgba(167, 139, 250, 0.35); }
        .dark-mode .summary-card.cyan { background: rgba(56, 189, 248, 0.12); border-color: rgba(56, 189, 248, 0.35); }

        .dark-mode .summary-card.blue::before { background: #60a5fa; }
        .dark-mode .summary-card.amber::before { background: #fbbf24; }
        .dark-mode .summary-card.emerald::before { background: #34d399; }
        .dark-mode .summary-card.rose::before { background: #f87171; }
        .dark-mode .summary-card.violet::before { background: #a78bfa; }
        .dark-mode .summary-card.cyan::before { background: #38bdf8; }

        .dark-mode .summary-card.blue .card-icon { background: rgba(96, 165, 250, 0.18); color: #60a5fa; }
        .dark-mode .summary-card.amber .card-icon { background: rgba(251, 191, 36, 0.18); color: #fbbf24; }
        .dark-mode .summary-card.emerald .card-icon { background: rgba(52, 211, 153, 0.18); color: #34d399; }
        .dark-mode .summary-card.rose .card-icon { background: rgba(248, 113, 113, 0.18); color: #f87171; }
        .dark-mode .summary-card.violet .card-icon { background: rgba(167, 139, 250, 0.18); color: #a78bfa; }
        .dark-mode .summary-card.cyan .card-icon { background: rgba(56, 189, 248, 0.18); color: #38bdf8; }

        .dark-mode .summary-card.blue .card-value { color: #93c5fd; }
        .dark-mode .summary-card.amber .card-value { color: #fcd34d; }
        .dark-mode .summary-card.emerald .card-value { color: #6ee7b7; }
        .dark-mode .summary-card.rose .card-value { color: #fca5a5; }
        .dark-mode .summary-card.violet .card-value { color: #c4b5fd; }
        .dark-mode .summary-card.cyan .card-value { color: #7dd3fc; }

        .dark-mode .summary-card.blue .card-label { color: #bfdbfe; }
        .dark-mode .summary-card.amber .card-label { color: #fde68a; }
        .dark-mode .summary-card.emerald .card-label { color: #a7f3d0; }
        .dark-mode .summary-card.rose .card-label { color: #fecaca; }
        .dark-mode .summary-card.violet .card-label { color: #ddd6fe; }
        .dark-mode .summary-card.cyan .card-label { color: #bae6fd; }

        /* Moving cards on hover (System Admin only) - mirrors stat-card:hover on monitoring page */
        .summary-card { cursor: pointer; }
        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.10);
        }
        .dark-mode .summary-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,0.45); }

        /* Data source tooltip (System Admin only) - styled like Chart.js tooltips */
        .ds-tooltip {
            position: fixed; z-index: 9999; pointer-events: none;
            background: rgba(15, 23, 42, 0.92); color: #f1f5f9;
            padding: 6px 10px; border-radius: 6px;
            font-size: 12px; font-weight: 600; line-height: 1.4;
            box-shadow: 0 4px 16px rgba(0,0,0,0.4);
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
        .ds-tooltip .tip-label { color: #f1f5f9; }
        .ds-tooltip .tip-value { color: #93c5fd; font-weight: 700; }
        <?php endif; ?>
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-text">
                <h1><i class="fas fa-shield-alt"></i> Admin Dashboard</h1>
                <p>Road &amp; Transportation Monitoring System</p>
            </div>
            <div class="date-time">
                <div id="currentDate"></div>
                <div id="currentTime"></div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="summary-row">
            <div class="summary-card blue" data-source="road_transportation_reports">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-file-alt"></i></div>
                </div>
                <div class="card-value"><?php echo $quick_insights['total_reports']; ?></div>
                <div class="card-label">Total Reports</div>
            </div>
            <div class="summary-card amber" data-source="road_transportation_reports">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-clock"></i></div>
                </div>
                <div class="card-value"><?php echo $quick_insights['pending_reports']; ?></div>
                <div class="card-label">Pending</div>
            </div>
            <div class="summary-card emerald" data-source="road_transportation_reports">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-spinner"></i></div>
                </div>
                <div class="card-value"><?php echo $quick_insights['in_progress_reports']; ?></div>
                <div class="card-label">In Progress</div>
            </div>
            <div class="summary-card rose" data-source="road_transportation_reports">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-check-circle"></i></div>
                </div>
                <div class="card-value"><?php echo $quick_insights['completed_reports']; ?></div>
                <div class="card-label">Completed</div>
            </div>
            <div class="summary-card violet" data-source="road_transportation_reports">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-exclamation-triangle"></i></div>
                </div>
                <div class="card-value"><?php echo $quick_insights['high_priority']; ?></div>
                <div class="card-label">High Priority</div>
            </div>
            <div class="summary-card cyan" data-source="users">
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
                <div class="card" data-source="road_transportation_reports">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-line"></i> Reports Submitted (Last 30 Days)</h3>
                    </div>
                    <div class="chart-container">
                        <canvas id="reportsTrend30DayChart"></canvas>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="chart-grid">
                    <div class="card" style="margin-bottom:0;" data-source="road_transportation_reports">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-bar"></i> Reports by Status</h3>
                        </div>
                        <div class="chart-container" style="height: 200px; max-width: 320px; margin: 0 auto;">
                            <canvas id="reportsByStatusChart"></canvas>
                        </div>
                    </div>
                    <div class="card" style="margin-bottom:0;" data-source="users">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chart-pie"></i> User Accounts</h3>
                        </div>
                        <div class="chart-container" style="height: 240px; max-width: 320px; margin: 0 auto;">
                            <canvas id="userAccountsChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Latest Uploaded Reports Table -->
                <div class="card" data-source="road_transportation_reports, users">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-file-alt"></i> Latest Uploaded Reports</h3>
                        <a href="report_management.php" class="btn-sm btn-primary">View All</a>
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
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($latest_reports)): ?>
                                    <tr><td colspan="7" style="text-align:center; color:#94a3b8; padding:24px;">No reports found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($latest_reports as $lr): ?>
                                    <tr>
                                        <td style="font-family:monospace; font-size:12px;"><?php echo htmlspecialchars($lr['report_id']); ?></td>
                                        <td><?php echo htmlspecialchars($lr['title'] ?? 'Untitled'); ?></td>
                                        <td><span class="badge badge-<?php echo strtolower($lr['report_source'] ?? 'citizen'); ?>"><?php echo ucfirst($lr['report_source'] ?? 'Citizen'); ?></span></td>
                                        <td><span class="badge badge-<?php echo strtolower($lr['priority'] ?? 'medium'); ?>"><?php echo ucfirst($lr['priority'] ?? 'Medium'); ?></span></td>
                                        <td><span class="badge badge-<?php echo strtolower(str_replace(' ', '-', $lr['status'])); ?>"><?php echo ucfirst($lr['status']); ?></span></td>
                                        <td style="font-size:12px; color:#64748b;"><?php echo date('M d, Y', strtotime($lr['created_at'])); ?></td>
                                        <td><a href="report_management.php?focus_report_id=<?php echo $lr['id']; ?>" class="btn-sm btn-primary"><i class="fas fa-eye"></i> View</a></td>
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
                <div class="card" data-source="audit_logs, users">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-history"></i> Recent Activity</h3>
                    </div>
                    <div class="activity-list">
                        <?php if (empty($recent_activity)): ?>
                            <p style="text-align:center; color:#94a3b8; padding:16px;">No recent activity.</p>
                        <?php else: ?>
                            <?php foreach (array_slice($recent_activity, 0, 8) as $ra): ?>
                                <?php
                                $dot_color = '#3b82f6';
                                foreach (['approve' => '#10b981', 'reject' => '#ef4444', 'delete' => '#ef4444', 'complete' => '#10b981', 'cancel' => '#f59e0b'] as $k => $c) {
                                    if (stripos($ra['action'], $k) !== false) { $dot_color = $c; break; }
                                }
                                ?>
                                <div class="activity-item">
                                    <div class="activity-dot" style="background:<?php echo $dot_color; ?>;"></div>
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
                <div class="card" data-source="users">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-user-clock"></i> Pending Approvals</h3>
                        <span class="badge badge-pending"><?php echo $stats['pending_users']; ?></span>
                    </div>
                    <div class="activity-list">
                        <?php
                        $pending_users_list = [];
                        try {
                            $pu_stmt = $conn->prepare("SELECT id, full_name, role, created_at FROM users WHERE account_status = 'pending' ORDER BY created_at DESC LIMIT 5");
                            $pu_stmt->execute();
                            $pending_users_list = $pu_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                            $pu_stmt->close();
                        } catch (Exception $e) {}
                        ?>
                        <?php if (empty($pending_users_list)): ?>
                            <p style="text-align:center; color:#94a3b8; padding:16px;">No pending approvals.</p>
                        <?php else: ?>
                            <?php foreach ($pending_users_list as $pu): ?>
                                <div class="widget-item">
                                    <div class="widget-avatar" style="background:#f59e0b;"><i class="fas fa-user"></i></div>
                                    <div class="widget-info">
                                        <div class="widget-title"><?php echo htmlspecialchars($pu['full_name']); ?></div>
                                        <div class="widget-meta"><?php echo htmlspecialchars($pu['role']); ?> &middot; <?php echo date('M d', strtotime($pu['created_at'])); ?></div>
                                    </div>
                                    <a href="account_approvals.php" class="btn-sm btn-primary">Review</a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- High Priority Reports -->
                <div class="card" data-source="road_transportation_reports">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-exclamation-triangle"></i> High Priority</h3>
                        <span class="badge badge-high"><?php echo $quick_insights['high_priority']; ?></span>
                    </div>
                    <div class="activity-list">
                        <?php
                        $high_priority_reports = [];
                        try {
                            $hp_stmt = $conn->prepare("SELECT id, report_id, title, status, created_at FROM road_transportation_reports WHERE priority = 'high' AND status NOT IN ('completed','cancelled') ORDER BY created_at DESC LIMIT 5");
                            $hp_stmt->execute();
                            $high_priority_reports = $hp_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                            $hp_stmt->close();
                        } catch (Exception $e) {}
                        ?>
                        <?php if (empty($high_priority_reports)): ?>
                            <p style="text-align:center; color:#94a3b8; padding:16px;">No high priority reports.</p>
                        <?php else: ?>
                            <?php foreach ($high_priority_reports as $hp): ?>
                                <div class="widget-item">
                                    <div class="widget-avatar" style="background:#f43f5e;"><i class="fas fa-exclamation"></i></div>
                                    <div class="widget-info">
                                        <div class="widget-title"><?php echo htmlspecialchars($hp['title'] ?? 'Untitled'); ?></div>
                                        <div class="widget-meta"><?php echo htmlspecialchars($hp['report_id']); ?> &middot; <?php echo ucfirst($hp['status']); ?></div>
                                    </div>
                                    <a href="report_management.php?focus_report_id=<?php echo $hp['id']; ?>" class="btn-sm btn-primary"><i class="fas fa-eye"></i></a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bottom Section: Charts -->
        <div class="chart-grid">
            <div class="card" style="margin-bottom:0;" data-source="road_transportation_reports">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-chart-pie"></i> Reports by Source</h3>
                </div>
                <div class="chart-container" style="height: 240px; max-width: 320px; margin: 0 auto;">
                    <canvas id="reportsBySourceChart"></canvas>
                </div>
            </div>
            <div class="card" style="margin-bottom:0;" data-source="road_transportation_reports">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-road"></i> Reports by Category</h3>
                </div>
                <div class="chart-container" style="height: 240px; max-width: 320px; margin: 0 auto;">
                    <canvas id="reportsByCategoryChart"></canvas>
                </div>
            </div>
        </div>
        <div class="card" data-source="road_transportation_reports">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-calendar-alt"></i> Monthly Trend</h3>
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
                    borderColor: '#fff'
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
                    ctx.fillStyle = '#1e293b';
                    const text = userAccountsTotal;
                    const textX = Math.round((width - ctx.measureText(text).width) / 2);
                    const textY = height / 2 - 8;
                    ctx.fillText(text, textX, textY);
                    // Draw label
                    ctx.font = (fontSize * 0.4).toFixed(2) + 'em Poppins';
                    ctx.fillStyle = '#64748b';
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
                        grid: { color: 'rgba(0,0,0,0.05)' }
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
                        grid: { color: 'rgba(0,0,0,0.05)' }
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
                    y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: 'rgba(0,0,0,0.05)' } },
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
                    borderColor: '#fff'
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
                    borderColor: '#fff'
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

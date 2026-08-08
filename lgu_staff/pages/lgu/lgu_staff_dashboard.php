<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../api/cimm_verification_data.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !is_staff_role($_SESSION['role'] ?? '')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: ../../login.php');
    exit();
}

// Road Monitoring Officers see only Road reports (report_category = 'road')
// in every dashboard section. This flag is passed to all dashboard data-
// fetching functions so transport reports are hidden from the Road
// Monitoring Officer dashboard only.
$is_road_monitoring_officer = (($_SESSION['role'] ?? '') === 'road_monitoring_officer');
$is_supervisor = in_array($_SESSION['role'] ?? '', ['road_ops_supervisor', 'trans_ops_supervisor'], true);
$user_role = $_SESSION['role'] ?? '';
$is_road_supervisor = ($is_supervisor && $user_role === 'road_ops_supervisor');
// Transportation Monitoring Officers and Transportation Operations Supervisors
// see only Transportation reports (report_category = 'transportation') in the
// dashboard "Recent Activity" feed.
$is_transport_only_role = in_array($_SESSION['role'] ?? '', ['trans_ops_supervisor', 'trans_monitoring_officer'], true);
// The Weekly Reports chart is filtered to Transportation reports and labelled
// "Transportation Reports" for the Transportation Operations Supervisor only.
$is_trans_ops_supervisor = ($user_role === 'trans_ops_supervisor');

// Function to get dashboard statistics
function getDashboardStatistics($conn, $road_only = false, $supervisor = false, $role = '') {
    $stats = [];
    $cat_filter = $road_only ? " AND report_category = 'road'" : '';

    // Road supervisor portal: count reports by their actual status across the
    // scope managed in report_management.php — Road transport reports, all
    // maintenance reports and all CIMM reports (CIMM reports are counted by
    // their display status, and "completed this month" uses the date each
    // report was actually completed).
    if ($supervisor && $role === 'road_ops_supervisor') {
        try {
            $scope = " AND report_category = 'road'";
            $cimm_status = cimm_activity_status_sql();
            $cimm_created = "COALESCE(submitted_at, verified_at, synced_at, created_at)";

            // Today's reports
            $t = $conn->query("SELECT COUNT(*) AS c FROM road_transportation_reports WHERE DATE(created_at) = CURDATE()" . $scope)->fetch_assoc()['c'] ?? 0;
            $m = $conn->query("SELECT COUNT(*) AS c FROM road_maintenance_reports WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['c'] ?? 0;
            $c = $conn->query("SELECT COUNT(*) AS c FROM cimm_verification_reports WHERE DATE(" . $cimm_created . ") = CURDATE()")->fetch_assoc()['c'] ?? 0;
            $stats['today_reports'] = (int)$t + (int)$m + (int)$c;

            // Pending reports
            $t = $conn->query("SELECT COUNT(*) AS c FROM road_transportation_reports WHERE status = 'pending'" . $scope)->fetch_assoc()['c'] ?? 0;
            $m = $conn->query("SELECT COUNT(*) AS c FROM road_maintenance_reports WHERE status = 'pending'")->fetch_assoc()['c'] ?? 0;
            $c = $conn->query("SELECT COUNT(*) AS c FROM cimm_verification_reports WHERE " . $cimm_status . " = 'pending'")->fetch_assoc()['c'] ?? 0;
            $stats['pending_verifications'] = (int)$t + (int)$m + (int)$c;

            // In progress
            $t = $conn->query("SELECT COUNT(*) AS c FROM road_transportation_reports WHERE status = 'in-progress'" . $scope)->fetch_assoc()['c'] ?? 0;
            $m = $conn->query("SELECT COUNT(*) AS c FROM road_maintenance_reports WHERE status = 'in-progress'")->fetch_assoc()['c'] ?? 0;
            $c = $conn->query("SELECT COUNT(*) AS c FROM cimm_verification_reports WHERE " . $cimm_status . " = 'in-progress'")->fetch_assoc()['c'] ?? 0;
            $stats['under_maintenance'] = (int)$t + (int)$m + (int)$c;

            // Completed this month (by the date each report was completed)
            $t = $conn->query("SELECT COUNT(*) AS c FROM road_transportation_reports WHERE status = 'completed' AND MONTH(COALESCE(completed_at, resolved_date, updated_at)) = MONTH(CURDATE()) AND YEAR(COALESCE(completed_at, resolved_date, updated_at)) = YEAR(CURDATE())" . $scope)->fetch_assoc()['c'] ?? 0;
            $m = $conn->query("SELECT COUNT(*) AS c FROM road_maintenance_reports WHERE status = 'completed' AND MONTH(COALESCE(completed_at, updated_at)) = MONTH(CURDATE()) AND YEAR(COALESCE(completed_at, updated_at)) = YEAR(CURDATE())")->fetch_assoc()['c'] ?? 0;
            $c = $conn->query("SELECT COUNT(*) AS c FROM cimm_verification_reports WHERE " . $cimm_status . " = 'completed' AND MONTH(COALESCE(resolved_at, verified_at, updated_at)) = MONTH(CURDATE()) AND YEAR(COALESCE(resolved_at, verified_at, updated_at)) = YEAR(CURDATE())")->fetch_assoc()['c'] ?? 0;
            $stats['completed_month'] = (int)$t + (int)$m + (int)$c;

            return $stats;
        } catch (Exception $e) {
            error_log("Road supervisor dashboard stats error: " . $e->getMessage());
        }
    }

    // Today's road reports
    $result = $conn->query("SELECT COUNT(*) as today_reports FROM road_transportation_reports WHERE DATE(created_at) = CURDATE()" . $cat_filter);
    $transport_today = $result->fetch_assoc()['today_reports'];
    
    $result = $conn->query("SELECT COUNT(*) as today_reports FROM road_maintenance_reports WHERE DATE(created_at) = CURDATE()");
    $maintenance_today = $result->fetch_assoc()['today_reports'];
    $stats['today_reports'] = $transport_today + $maintenance_today;
    
    // Pending verifications
    $result = $conn->query("SELECT COUNT(*) as pending FROM road_transportation_reports WHERE status = 'pending'" . $cat_filter);
    $transport_pending = $result->fetch_assoc()['pending'];
    
    $result = $conn->query("SELECT COUNT(*) as pending FROM road_maintenance_reports WHERE status = 'pending'");
    $maintenance_pending = $result->fetch_assoc()['pending'];
    $stats['pending_verifications'] = $transport_pending + $maintenance_pending;
    
    // Under maintenance (in-progress)
    $result = $conn->query("SELECT COUNT(*) as in_progress FROM road_transportation_reports WHERE status = 'in-progress'" . $cat_filter);
    $transport_progress = $result->fetch_assoc()['in_progress'];
    
    $result = $conn->query("SELECT COUNT(*) as in_progress FROM road_maintenance_reports WHERE status = 'in-progress'");
    $maintenance_progress = $result->fetch_assoc()['in_progress'];
    $stats['under_maintenance'] = $transport_progress + $maintenance_progress;
    
    // Completed this month
    $result = $conn->query("SELECT COUNT(*) as completed FROM road_transportation_reports WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())" . $cat_filter);
    $transport_completed = $result->fetch_assoc()['completed'];
    
    $result = $conn->query("SELECT COUNT(*) as completed FROM road_maintenance_reports WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $maintenance_completed = $result->fetch_assoc()['completed'];
    $stats['completed_month'] = $transport_completed + $maintenance_completed;
    
    return $stats;
}

// Function to get recent activity
function getRecentActivity($conn, $road_only = false) {
    $cat_filter = $road_only ? " AND report_category = 'road'" : '';
    $query = "(SELECT 'transport' as source, 'road' as type, title, created_at FROM road_transportation_reports WHERE 1=1{$cat_filter} ORDER BY created_at DESC LIMIT 5)
              UNION ALL
              (SELECT 'maintenance' as source, 'maintenance' as type, title, created_at FROM road_maintenance_reports ORDER BY created_at DESC LIMIT 5)
              ORDER BY created_at DESC LIMIT 5";
    $result = $conn->query($query);
    return $result;
}

// Function to get priority tasks
function getPriorityTasks($conn, $road_only = false) {
    $cat_filter = $road_only ? " AND report_category = 'road'" : '';
    $query = "(SELECT 'High' as priority, title, created_at FROM road_transportation_reports WHERE status = 'pending' AND priority = 'high'" . $cat_filter . " ORDER BY created_at DESC LIMIT 3)
              UNION ALL
              (SELECT 'High' as priority, title, created_at FROM road_maintenance_reports WHERE status = 'pending' AND priority = 'high' ORDER BY created_at DESC LIMIT 3)
              UNION ALL
              (SELECT 'Medium' as priority, title, created_at FROM road_transportation_reports WHERE status = 'pending' AND priority = 'medium'" . $cat_filter . " ORDER BY created_at DESC LIMIT 2)
              UNION ALL
              (SELECT 'Medium' as priority, title, created_at FROM road_maintenance_reports WHERE status = 'pending' AND priority = 'medium' ORDER BY created_at DESC LIMIT 2)
              ORDER BY FIELD(priority, 'High', 'Medium'), created_at DESC LIMIT 5";
    $result = $conn->query($query);
    return $result;
}

// Function to get weekly chart data
function getWeeklyChartData($conn, $road_only = false, $transport_only = false) {
    $data = ['reports' => [], 'verifications' => []];
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    if ($road_only) {
        $cat_filter = " AND report_category = 'road'";
    } elseif ($transport_only) {
        $cat_filter = " AND report_category = 'transportation'";
    } else {
        $cat_filter = '';
    }
    
    // Get data for the current week
    $current_week = date('W');
    $current_year = date('Y');
    
    foreach ($days as $index => $day) {
        $day_of_week = ($index + 2); // MySQL DAYOFWEEK: 1=Sunday, 2=Monday, etc.
        
        // Get transportation reports for this day
        $transport_query = "SELECT COUNT(*) as count FROM road_transportation_reports 
                           WHERE DAYOFWEEK(created_at) = $day_of_week 
                           AND WEEK(created_at, 1) = WEEK(CURRENT_DATE, 1) 
                           AND YEAR(created_at) = $current_year" . $cat_filter;
        $result = $conn->query($transport_query);
        $transport_count = $result->fetch_assoc()['count'];
        
        $maintenance_count = 0;
        if (!$transport_only) {
            $maintenance_query = "SELECT COUNT(*) as count FROM road_maintenance_reports 
                                 WHERE DAYOFWEEK(created_at) = $day_of_week 
                                 AND WEEK(created_at, 1) = WEEK(CURRENT_DATE, 1) 
                                 AND YEAR(created_at) = $current_year";
            $result = $conn->query($maintenance_query);
            $maintenance_count = $result->fetch_assoc()['count'];
        }
        
        $data['reports'][] = (int)($transport_count + $maintenance_count);
        
        // Get verification activities for this day
        if ($transport_only) {
            // Transport-only roles: count completed/approved transportation reports
            $verification_query = "SELECT COUNT(*) as count FROM road_transportation_reports 
                                   WHERE status IN ('completed', 'approved') 
                                   AND DAYOFWEEK(updated_at) = $day_of_week 
                                   AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE, 1) 
                                   AND YEAR(updated_at) = $current_year" . $cat_filter;
            $result = $conn->query($verification_query);
            $verification_count = $result->fetch_assoc()['count'];
        } else {
            // Check if audit_trails table exists and has data
            $audit_check = $conn->query("SHOW TABLES LIKE 'audit_trails'");
            if ($audit_check->num_rows > 0) {
                $verification_query = "SELECT COUNT(*) as count FROM audit_trails 
                                     WHERE audit_type = 'verification' 
                                     AND DAYOFWEEK(created_at) = $day_of_week 
                                     AND WEEK(created_at, 1) = WEEK(CURRENT_DATE, 1) 
                                     AND YEAR(created_at) = $current_year";
                $result = $conn->query($verification_query);
                $verification_count = $result->fetch_assoc()['count'];
            } else {
                // Fallback: count status changes to 'completed' or 'approved' as verifications
                $verification_query = "(SELECT COUNT(*) as count FROM road_transportation_reports 
                                       WHERE status IN ('completed', 'approved') 
                                       AND DAYOFWEEK(updated_at) = $day_of_week 
                                       AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE, 1) 
                                       AND YEAR(updated_at) = $current_year" . $cat_filter . ")
                                       UNION ALL
                                       (SELECT COUNT(*) as count FROM road_maintenance_reports 
                                       WHERE status IN ('completed', 'approved') 
                                       AND DAYOFWEEK(updated_at) = $day_of_week 
                                       AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE, 1) 
                                       AND YEAR(updated_at) = $current_year)";
                $result = $conn->query($verification_query);
                $verification_count = 0;
                while ($row = $result->fetch_assoc()) {
                    $verification_count += $row['count'];
                }
            }
        }
        
        $data['verifications'][] = (int)$verification_count;
    }
    
    // If no data for current week, get last week's data as fallback
    if (array_sum($data['reports']) == 0) {
        foreach ($days as $index => $day) {
            $day_of_week = ($index + 2);
            
            // Get last week's data
            $transport_query = "SELECT COUNT(*) as count FROM road_transportation_reports 
                               WHERE DAYOFWEEK(created_at) = $day_of_week 
                               AND WEEK(created_at, 1) = WEEK(CURRENT_DATE - INTERVAL 1 WEEK, 1)" . $cat_filter;
            $result = $conn->query($transport_query);
            $transport_count = $result->fetch_assoc()['count'];
            
            $maintenance_count = 0;
            if (!$transport_only) {
                $maintenance_query = "SELECT COUNT(*) as count FROM road_maintenance_reports 
                                     WHERE DAYOFWEEK(created_at) = $day_of_week 
                                     AND WEEK(created_at, 1) = WEEK(CURRENT_DATE - INTERVAL 1 WEEK, 1)";
                $result = $conn->query($maintenance_query);
                $maintenance_count = $result->fetch_assoc()['count'];
            }
            
            $data['reports'][$index] = (int)($transport_count + $maintenance_count);
            
            // Get verifications for last week
            if ($transport_only) {
                $verification_query = "SELECT COUNT(*) as count FROM road_transportation_reports 
                                       WHERE status IN ('completed', 'approved') 
                                       AND DAYOFWEEK(updated_at) = $day_of_week 
                                       AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE - INTERVAL 1 WEEK, 1)" . $cat_filter;
                $result = $conn->query($verification_query);
                $verification_count = $result->fetch_assoc()['count'];
            } else {
                $verification_query = "(SELECT COUNT(*) as count FROM road_transportation_reports 
                                       WHERE status IN ('completed', 'approved') 
                                       AND DAYOFWEEK(updated_at) = $day_of_week 
                                       AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE - INTERVAL 1 WEEK, 1)" . $cat_filter . ")
                                       UNION ALL
                                       (SELECT COUNT(*) as count FROM road_maintenance_reports 
                                       WHERE status IN ('completed', 'approved') 
                                       AND DAYOFWEEK(updated_at) = $day_of_week 
                                       AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE - INTERVAL 1 WEEK, 1))";
                $result = $conn->query($verification_query);
                $verification_count = 0;
                while ($row = $result->fetch_assoc()) {
                    $verification_count += $row['count'];
                }
            }
            
            $data['verifications'][$index] = (int)$verification_count;
        }
    }
    
    return $data;
}

// Get data
$stats = getDashboardStatistics($conn, $is_road_monitoring_officer, $is_supervisor, $user_role);
$recent_activity = getRecentActivity($conn, $is_road_monitoring_officer);
$priority_tasks = getPriorityTasks($conn, $is_road_monitoring_officer);
$chart_data = getWeeklyChartData($conn, $is_road_monitoring_officer, $is_trans_ops_supervisor);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LGU Staff Dashboard | Road and Transportation Department</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --db-bg: #f5f7fb;
            --db-card: #ffffff;
            --db-border: #e9edf3;
            --db-text: #0f172a;
            --db-muted: #64748b;
            --db-faint: #94a3b8;
            --db-primary: #1e3c72;
            --db-accent: #3762c8;
        }

        body {
            background: var(--db-bg);
            min-height: 100vh;
        }

        .main-content {
            margin-left: 250px;
            padding: 28px 30px 60px;
            position: relative;
            z-index: 1;
        }

        @keyframes dbFadeUp {
            from { opacity: 0; transform: translateY(14px); }
            to { opacity: 1; transform: none; }
        }

        /* ---------- Header ---------- */
        .db-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
            background: linear-gradient(120deg, #1e3c72 0%, #3762c8 100%);
            padding: 26px 30px;
            border-radius: 18px;
            margin-bottom: 26px;
            color: #fff;
            box-shadow: 0 10px 30px rgba(30, 60, 114, .28);
            position: relative;
            overflow: hidden;
        }
        .db-header::after {
            content: '';
            position: absolute;
            right: -60px;
            top: -60px;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .08);
        }
        .db-header::before {
            content: '';
            position: absolute;
            right: 60px;
            bottom: -80px;
            width: 160px;
            height: 160px;
            border-radius: 50%;
            background: rgba(255, 255, 255, .06);
        }
        .db-welcome {
            display: flex;
            align-items: center;
            gap: 18px;
            position: relative;
            z-index: 1;
        }
        .db-logo {
            width: 64px;
            height: 64px;
            border-radius: 14px;
            overflow: hidden;
            border: 3px solid rgba(255, 255, 255, .9);
            box-shadow: 0 4px 12px rgba(0, 0, 0, .2);
            flex-shrink: 0;
            background: #fff;
        }
        .db-logo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .db-welcome h1 { font-size: 24px; font-weight: 700; line-height: 1.25; }
        .db-welcome p { font-size: 13.5px; opacity: .85; margin-top: 3px; }
        .db-role-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255, 255, 255, .18);
            border: 1px solid rgba(255, 255, 255, .25);
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
            margin-top: 8px;
        }
        .db-datetime { text-align: right; position: relative; z-index: 1; }
        .db-datetime #currentDate { font-size: 15px; font-weight: 600; }
        .db-datetime #currentTime { font-size: 13px; opacity: .85; margin-top: 2px; }

        /* ---------- Stats ---------- */
        .db-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 26px;
        }
        .stat-card {
            background: var(--db-card);
            border: 1px solid var(--db-border);
            border-radius: 16px;
            padding: 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 1px 3px rgba(16, 24, 40, .06);
            transition: transform .2s ease, box-shadow .2s ease;
            animation: dbFadeUp .45s ease both;
            position: relative;
            overflow: hidden;
        }
        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--sc);
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 14px 28px rgba(16, 24, 40, .12);
        }
        .stat-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: var(--sc);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            box-shadow: 0 6px 14px rgba(16, 24, 40, .18);
        }
        .stat-number { font-size: 25px; font-weight: 700; color: var(--db-text); line-height: 1.15; }
        .stat-label { font-size: 13px; font-weight: 600; color: #475569; }
        .stat-desc { font-size: 11.5px; color: var(--db-faint); margin-top: 2px; }

        /* ---------- Sections ---------- */
        .dash-section {
            background: var(--db-card);
            border: 1px solid var(--db-border);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 1px 3px rgba(16, 24, 40, .06);
            animation: dbFadeUp .45s ease both;
        }
        .dsh-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .dsh-left { display: flex; align-items: center; gap: 12px; }
        .dsh-icon {
            width: 40px;
            height: 40px;
            border-radius: 11px;
            background: color-mix(in srgb, var(--ds) 14%, transparent);
            color: var(--ds);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 17px;
            flex-shrink: 0;
        }
        .dsh-left h3 { font-size: 15.5px; font-weight: 600; color: var(--db-text); }
        .dsh-left p { font-size: 12px; color: var(--db-muted); }
        .dsh-link { font-size: 12.5px; font-weight: 600; color: var(--db-accent); text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .dsh-link:hover { text-decoration: underline; }
        .dsh-badge {
            background: #eef2ff;
            color: #4338ca;
            font-size: 12px;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 999px;
        }

        /* ---------- Grids ---------- */
        .db-grid-main {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .db-grid-full { grid-template-columns: 1fr; }
        .db-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }
        .chart-wrap { position: relative; height: 290px; }

        .period-select {
            padding: 8px 12px;
            border: 1px solid var(--db-border);
            border-radius: 10px;
            font-family: inherit;
            font-size: 12.5px;
            color: var(--db-text);
            background: var(--db-card);
            cursor: pointer;
            outline: none;
        }
        .period-select:focus { border-color: var(--db-accent); box-shadow: 0 0 0 3px rgba(55, 98, 200, .12); }

        /* ---------- Notifications widget ---------- */
        .nt-list { display: flex; flex-direction: column; gap: 8px; }
        .nt-item {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            padding: 11px;
            border-radius: 12px;
            border: 1px solid transparent;
            text-decoration: none;
            transition: background .15s ease, border-color .15s ease;
        }
        .nt-item:hover { background: #f8fafc; border-color: var(--db-border); }
        .nt-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(16, 24, 40, .14);
        }
        .nt-body { flex: 1; min-width: 0; }
        .nt-title { font-size: 13px; font-weight: 600; color: var(--db-text); line-height: 1.35; }
        .nt-desc {
            font-size: 12px;
            color: var(--db-muted);
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .nt-time { font-size: 11px; color: var(--db-faint); white-space: nowrap; }

        /* ---------- Badges ---------- */
        .db-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .db-st-pending   { background: #fef3c7; color: #b45309; }
        .db-st-active,
        .db-st-assigned  { background: #dbeafe; color: #1d4ed8; }
        .db-st-progress  { background: #ffedd5; color: #c2410c; }
        .db-st-completed,
        .db-st-approved  { background: #d1fae5; color: #047857; }
        .db-st-cancelled,
        .db-st-rejected  { background: #fee2e2; color: #b91c1c; }
        .db-pr-high   { background: #fee2e2; color: #dc2626; }
        .db-pr-medium { background: #ffedd5; color: #c2410c; }
        .db-pr-low    { background: #d1fae5; color: #059669; }

        /* ---------- Activity & tasks ---------- */
        .db-list { display: flex; flex-direction: column; }
        .act-scroll {
            max-height: 520px;
            overflow-y: auto;
            padding-right: 6px;
            overscroll-behavior: contain;
        }
        .act-scroll::-webkit-scrollbar { width: 6px; }
        .act-scroll::-webkit-scrollbar-track { background: transparent; }
        .act-scroll::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 999px; }
        .dark-mode .act-scroll::-webkit-scrollbar-thumb { background: #334155; }
        .act-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 8px;
            border-radius: 12px;
            text-decoration: none;
            transition: background .15s ease;
        }
        .act-item:hover { background: #f8fafc; }
        .act-item + .act-item { border-top: 1px solid var(--db-border); }
        .act-icon {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            color: #fff;
            flex-shrink: 0;
        }
        .act-body { flex: 1; min-width: 0; }
        .act-title { font-size: 13px; font-weight: 600; color: var(--db-text); line-height: 1.35; }
        .act-desc {
            font-size: 12px;
            color: var(--db-muted);
            margin-top: 2px;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .act-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap; }
        .act-time { font-size: 11.5px; color: var(--db-faint); }

        .task-card {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 8px;
            border-radius: 12px;
            text-decoration: none;
            transition: background .15s ease;
        }
        .task-card:hover { background: #f8fafc; }
        .task-card + .task-card { border-top: 1px solid var(--db-border); }
        .task-priority-icon {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            color: #fff;
            flex-shrink: 0;
            background: var(--ts);
        }
        .task-body { flex: 1; min-width: 0; }
        .task-title { font-size: 13px; font-weight: 600; color: var(--db-text); line-height: 1.35; }
        .task-meta { display: flex; align-items: center; gap: 8px; margin-top: 4px; flex-wrap: wrap; }
        .task-id { font-size: 11.5px; font-weight: 600; color: var(--db-faint); }

        /* ---------- Quick actions ---------- */
        .qa-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .qa-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 16px 10px;
            border-radius: 14px;
            border: 1px solid var(--db-border);
            background: #f8fafc;
            text-decoration: none;
            transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
        }
        .qa-btn:hover {
            transform: translateY(-3px);
            border-color: color-mix(in srgb, var(--qa) 50%, transparent);
            box-shadow: 0 10px 20px rgba(16, 24, 40, .10);
        }
        .qa-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: color-mix(in srgb, var(--qa) 16%, transparent);
            color: var(--qa);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
        }
        .qa-label { font-size: 12.5px; font-weight: 600; color: var(--db-text); }

        /* ---------- My Assignments ---------- */
        .asg-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 14px;
        }
        .asg-card {
            border: 1px solid var(--db-border);
            border-radius: 14px;
            padding: 15px;
            background: #fbfcfe;
            transition: transform .18s ease, box-shadow .18s ease;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .asg-card:hover { transform: translateY(-3px); box-shadow: 0 10px 22px rgba(16, 24, 40, .10); }
        .asg-top { display: flex; align-items: center; gap: 12px; }
        .asg-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: color-mix(in srgb, var(--as) 15%, transparent);
            color: var(--as);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .asg-title { font-size: 13.5px; font-weight: 600; color: var(--db-text); line-height: 1.35; }
        .asg-id { font-size: 12px; color: var(--db-faint); margin-top: 2px; }
        .asg-badges { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .asg-foot {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-top: auto;
            padding-top: 12px;
            border-top: 1px solid var(--db-border);
        }
        .asg-date { font-size: 11.5px; color: var(--db-muted); display: inline-flex; align-items: center; gap: 5px; }
        .btn-view {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--db-accent);
            color: #fff;
            border: none;
            padding: 7px 13px;
            border-radius: 9px;
            font-size: 12.5px;
            font-weight: 600;
            font-family: inherit;
            text-decoration: none;
            cursor: pointer;
            transition: background .15s ease, transform .15s ease;
        }
        .btn-view:hover { background: #2c52a8; transform: translateY(-1px); }

        .db-empty {
            text-align: center;
            padding: 30px 16px;
            color: var(--db-faint);
        }
        .db-empty i { font-size: 32px; margin-bottom: 10px; opacity: .55; }
        .db-empty p { font-size: 13px; }

        /* ---------- Responsive ---------- */
        @media (max-width: 1280px) {
            .db-grid-3 { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 1024px) {
            .db-grid-main { grid-template-columns: 1fr; }
        }
        @media (max-width: 900px) {
            .db-grid-3 { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 18px 14px 50px; }
            .db-header { padding: 20px; }
            .db-welcome h1 { font-size: 20px; }
            .db-datetime { text-align: left; }
        }

        /* ---------- Dark mode ---------- */
        .dark-mode body,
        body.dark-mode { background: #0f172a; }
        .dark-mode .stat-card,
        .dark-mode .dash-section,
        .dark-mode .asg-card { background: #1e293b; border-color: #334155; }
        .dark-mode .stat-number,
        .dark-mode .dsh-left h3,
        .dark-mode .act-title,
        .dark-mode .task-title,
        .dark-mode .qa-label,
        .dark-mode .asg-title,
        .dark-mode .nt-title { color: #e2e8f0; }
        .dark-mode .stat-label { color: #cbd5e1; }
        .dark-mode .stat-desc,
        .dark-mode .act-time,
        .dark-mode .task-id,
        .dark-mode .asg-id,
        .dark-mode .nt-time { color: #94a3b8; }
        .dark-mode .dsh-left p,
        .dark-mode .nt-desc,
        .dark-mode .act-desc,
        .dark-mode .asg-date { color: #94a3b8; }
        .dark-mode .nt-item:hover,
        .dark-mode .act-item:hover,
        .dark-mode .task-card:hover { background: #263449; }
        .dark-mode .period-select { background: #0f172a; color: #e2e8f0; border-color: #334155; }
        .dark-mode .qa-btn { background: #263449; border-color: #334155; }
        .dark-mode .qa-label { color: #e2e8f0; }
        .dark-mode .asg-card { background: #263449; }
        .dark-mode .act-item + .act-item,
        .dark-mode .task-card + .task-card,
        .dark-mode .asg-foot { border-color: #334155; }
        .dark-mode .dsh-badge { background: #312e81; color: #c7d2fe; }
        .dark-mode .db-empty { color: #64748b; }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <?php
    // ---- New read-only queries powering the enhanced dashboard UI. All
    //      existing queries above remain untouched. ----
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $user_role = $_SESSION['role'] ?? '';
    $user_email = $_SESSION['email'] ?? '';

    function getHighPriorityCount($conn, $road_only = false, $supervisor = false, $role = '') {
        $count = 0;
        $cat_filter = $road_only ? " AND report_category = 'road'" : '';
        try {
            if ($supervisor && $role === 'road_ops_supervisor') {
                // Road supervisor portal: all reports needing immediate attention
                // (high priority and not yet completed/cancelled) across Road
                // transport, maintenance and CIMM reports.
                $scope = " AND report_category = 'road'";
                $cimm_status = cimm_activity_status_sql();
                $t = $conn->query("SELECT COUNT(*) AS c FROM road_transportation_reports WHERE priority = 'high' AND status NOT IN ('completed','cancelled')" . $scope)->fetch_assoc()['c'] ?? 0;
                $m = $conn->query("SELECT COUNT(*) AS c FROM road_maintenance_reports WHERE priority = 'high' AND status NOT IN ('completed','cancelled')")->fetch_assoc()['c'] ?? 0;
                $c = $conn->query("SELECT COUNT(*) AS c FROM cimm_verification_reports WHERE priority = 'high' AND " . $cimm_status . " NOT IN ('completed','cancelled')")->fetch_assoc()['c'] ?? 0;
                return (int)$t + (int)$m + (int)$c;
            }
            $t = $conn->query("SELECT COUNT(*) AS c FROM road_transportation_reports WHERE status = 'pending' AND priority = 'high'" . $cat_filter)->fetch_assoc()['c'] ?? 0;
            $m = $conn->query("SELECT COUNT(*) AS c FROM road_maintenance_reports WHERE status = 'pending' AND priority = 'high'")->fetch_assoc()['c'] ?? 0;
            $count = (int)$t + (int)$m;
        } catch (Exception $e) {}
        return $count;
    }

    function getAwaitingAssignmentCount($conn, $supervisor = false, $role = '') {
        $count = 0;
        if (!$conn) return $count;
        try {
            if ($supervisor && $role === 'road_ops_supervisor') {
                // Road supervisor portal: reports that still need to be assigned
                // (no assignee yet, not completed/cancelled) across the scope
                // managed in report_management.php — Road transport reports,
                // all maintenance reports and all CIMM reports.
                $cimm_status = cimm_activity_status_sql();
                $t = $conn->query("SELECT COUNT(*) AS c FROM road_transportation_reports WHERE report_category = 'road' AND status NOT IN ('completed','cancelled') AND (assigned_to IS NULL OR TRIM(assigned_to) = '')")->fetch_assoc()['c'] ?? 0;
                $m = $conn->query("SELECT COUNT(*) AS c FROM road_maintenance_reports WHERE status NOT IN ('completed','cancelled') AND (maintenance_team IS NULL OR TRIM(maintenance_team) = '')")->fetch_assoc()['c'] ?? 0;
                $c = $conn->query("SELECT COUNT(*) AS c FROM cimm_verification_reports cimm WHERE " . $cimm_status . " NOT IN ('completed','cancelled') AND NOT EXISTS (SELECT 1 FROM report_assignments ra WHERE ra.report_id = cimm.id AND ra.report_type = 'cimm_verification_reports' AND ra.status = 'active')")->fetch_assoc()['c'] ?? 0;
                $count = (int)$t + (int)$m + (int)$c;
            }
        } catch (Exception $e) {
            $count = 0;
        }
        return $count;
    }

    function getMyAssignments($conn, $user_id, $road_only = false) {
        $rows = [];
        if (!$conn || $user_id <= 0) {
            return ['count' => 0, 'items' => $rows];
        }

        $road_only_filter = $road_only
            ? " AND (ra.report_type <> 'road_transportation_reports' OR r.report_category = 'road')"
            : '';

        $assignment_sql = "
            SELECT ra.*,
                   r.report_id AS transport_code,
                   r.title AS transport_title,
                   r.status AS transport_status,
                   r.priority AS transport_priority,
                   c.reference_code AS cimm_code,
                   c.cimm_req_id AS cimm_req_id,
                   c.infrastructure AS cimm_title,
                   c.priority AS cimm_priority,
                   c.resolution_status AS cimm_resolution_status,
                   c.approval_status AS cimm_approval_status,
                   m.report_id AS maintenance_code,
                   m.title AS maintenance_title,
                   m.status AS maintenance_status,
                   m.priority AS maintenance_priority
            FROM report_assignments ra
            LEFT JOIN road_transportation_reports r
                ON ra.report_id = r.id AND ra.report_type = 'road_transportation_reports'
            LEFT JOIN cimm_verification_reports c
                ON ra.report_id = c.id AND ra.report_type = 'cimm_verification_reports'
            LEFT JOIN road_maintenance_reports m
                ON ra.report_id = m.id AND ra.report_type = 'road_maintenance_reports'
            WHERE ra.user_id = ? AND ra.status = 'active'" . $road_only_filter . "
            ORDER BY ra.assigned_at DESC
            LIMIT 12
        ";

        try {
            $stmt = $conn->prepare($assignment_sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (Exception $e) {
            $rows = [];
        }

        $count = 0;
        try {
            $cstmt = $conn->prepare("
                SELECT COUNT(*) AS c
                FROM report_assignments ra
                LEFT JOIN road_transportation_reports r
                    ON ra.report_id = r.id AND ra.report_type = 'road_transportation_reports'
                WHERE ra.user_id = ? AND ra.status = 'active'" . $road_only_filter . "
            ");
            $cstmt->bind_param("i", $user_id);
            $cstmt->execute();
            $count = (int)$cstmt->get_result()->fetch_assoc()['c'];
            $cstmt->close();
        } catch (Exception $e) {}

        foreach ($rows as &$ra) {
            if ($ra['report_type'] === 'cimm_verification_reports') {
                $ra['report_code'] = $ra['cimm_code']
                    ?: (!empty($ra['cimm_req_id']) ? 'REQ-' . $ra['cimm_req_id'] : null);
                $ra['report_title'] = $ra['cimm_title'];
                $ra['priority'] = $ra['cimm_priority'];
                $ra['report_status'] = cimm_resolution_status_to_display(
                    $ra['cimm_resolution_status'] ?? null,
                    $ra['cimm_approval_status'] ?? null
                );
                $ra['_source'] = 'cimm';
            } elseif ($ra['report_type'] === 'road_maintenance_reports') {
                $ra['report_code'] = $ra['maintenance_code'];
                $ra['report_title'] = $ra['maintenance_title'];
                $ra['priority'] = $ra['maintenance_priority'];
                $ra['report_status'] = $ra['maintenance_status'];
                $ra['_source'] = 'maintenance';
            } else {
                $ra['report_code'] = $ra['transport_code'];
                $ra['report_title'] = $ra['transport_title'];
                $ra['priority'] = $ra['transport_priority'];
                $ra['report_status'] = $ra['transport_status'];
                $ra['_source'] = 'transport';
            }

            $ra['report_code'] = $ra['report_code'] ?? ('#' . $ra['report_id']);
            $ra['report_title'] = $ra['report_title'] ?? ('Assigned report #' . $ra['report_id']);
            $ra['priority'] = $ra['priority'] ?? 'medium';
            $ra['report_status'] = $ra['report_status'] ?? 'pending';
        }
        unset($ra);

        return ['count' => $count, 'items' => $rows];
    }

    // Reports that Road Operations Supervisors assigned to monitoring officers
    // in report_management.php (the road supervisor portal). Only active
    // assignments made by a user with the road_ops_supervisor role qualify.
    function getSupervisorAssignedReports($conn) {
        $rows = [];
        if (!$conn) return $rows;

        $query = "
            SELECT ra.id AS assignment_id, ra.report_id, ra.report_type, ra.user_id,
                   ra.assigned_at, ra.status AS assignment_status, ra.notes,
                   au.full_name AS assigner_name,
                   u.full_name AS officer_name, u.role AS officer_role,
                   r.report_id AS transport_code, r.title AS transport_title,
                   r.status AS transport_status, r.priority AS transport_priority,
                   m.report_id AS maintenance_code, m.title AS maintenance_title,
                   m.status AS maintenance_status, m.priority AS maintenance_priority,
                   c.reference_code AS cimm_code, c.infrastructure AS cimm_title,
                   c.priority AS cimm_priority,
                   c.resolution_status AS cimm_resolution_status,
                   c.approval_status AS cimm_approval_status
            FROM report_assignments ra
            JOIN users au ON au.id = ra.assigned_by
            JOIN users u ON u.id = ra.user_id
            LEFT JOIN road_transportation_reports r
                ON ra.report_id = r.id AND ra.report_type = 'road_transportation_reports'
            LEFT JOIN road_maintenance_reports m
                ON ra.report_id = m.id AND ra.report_type = 'road_maintenance_reports'
            LEFT JOIN cimm_verification_reports c
                ON ra.report_id = c.id AND ra.report_type = 'cimm_verification_reports'
            WHERE au.role = 'road_ops_supervisor'
              AND ra.status = 'active'
              AND (ra.report_type <> 'road_transportation_reports' OR r.report_category = 'road')
            ORDER BY ra.assigned_at DESC
            LIMIT 12
        ";

        try {
            $stmt = $conn->prepare($query);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        } catch (Exception $e) {
            $rows = [];
        }

        foreach ($rows as &$ra) {
            if ($ra['report_type'] === 'road_maintenance_reports') {
                $ra['report_code'] = $ra['maintenance_code'];
                $ra['report_title'] = $ra['maintenance_title'];
                $ra['priority'] = $ra['maintenance_priority'];
                $ra['report_status'] = $ra['maintenance_status'];
                $ra['_source'] = 'maintenance';
            } elseif ($ra['report_type'] === 'cimm_verification_reports') {
                $ra['report_code'] = $ra['cimm_code'];
                $ra['report_title'] = $ra['cimm_title'];
                $ra['priority'] = $ra['cimm_priority'];
                $ra['report_status'] = cimm_resolution_status_to_display(
                    $ra['cimm_resolution_status'] ?? null,
                    $ra['cimm_approval_status'] ?? null
                );
                $ra['_source'] = 'cimm';
            } else {
                $ra['report_code'] = $ra['transport_code'];
                $ra['report_title'] = $ra['transport_title'];
                $ra['priority'] = $ra['transport_priority'];
                $ra['report_status'] = $ra['transport_status'];
                $ra['_source'] = 'transport';
            }

            $ra['report_code'] = $ra['report_code'] ?? ('#' . $ra['report_id']);
            $ra['report_title'] = $ra['report_title'] ?? ('Assigned report #' . $ra['report_id']);
            $ra['priority'] = $ra['priority'] ?? 'medium';
            $ra['report_status'] = $ra['report_status'] ?? 'pending';
            $ra['officer_name'] = $ra['officer_name'] ?? 'Monitoring Officer';
        }
        unset($ra);

        return $rows;
    }

    function getDashboardNotifications($conn, $user_id, $user_email, $user_role, $assignments, $road_only = false) {
        $items = [];
        foreach ($assignments as $a) {
            $items[] = [
                'icon' => 'fa-user-plus',
                'color' => '#3b82f6',
                'title' => 'New assignment · ' . ($a['report_code'] ?? ('#' . $a['report_id'])),
                'desc' => $a['report_title'] ?? 'A report has been assigned to you.',
                'time' => $a['assigned_at'],
                'link' => '../shared/road_transportation_monitoring.php?focus_report_id=' . (int)($a['report_id'] ?? 0) . '&source=' . rawurlencode($a['_source'] ?? 'transport'),
            ];
        }
        if ($conn) {
            try {
                $cat_filter = $road_only ? " AND r.report_category = 'road'" : '';
                $subquery_cat = $road_only ? " AND report_category = 'road'" : '';
                $stmt = $conn->prepare("
                    SELECT rn.*, r.report_id AS report_code
                    FROM report_notifications rn
                    LEFT JOIN road_transportation_reports r ON rn.report_id = r.id
                    WHERE rn.is_read = 0" . $cat_filter . "
                      AND (rn.recipient_email = ? OR rn.recipient_role = ?
                           OR rn.report_id IN (SELECT id FROM road_transportation_reports WHERE created_by = ?" . $subquery_cat . "))
                      AND EXISTS (
                          SELECT 1 FROM road_transportation_reports WHERE id = rn.report_id
                          UNION ALL
                          SELECT 1 FROM road_maintenance_reports WHERE id = rn.report_id
                          UNION ALL
                          SELECT 1 FROM cimm_verification_reports WHERE id = rn.report_id
                          LIMIT 1
                      )
                    ORDER BY rn.created_at DESC
                    LIMIT 6
                ");
                $stmt->bind_param("ssi", $user_email, $user_role, $user_id);
                $stmt->execute();
                $rns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                foreach ($rns as $n) {
                    $t = $n['type'];
                    $icon = 'fa-sync'; $color = '#8b5cf6'; $label = 'Verification update';
                    if ($t === 'completion') { $icon = 'fa-check-circle'; $color = '#10b981'; $label = 'Completion request'; }
                    if ($t === 'cancellation') { $icon = 'fa-ban'; $color = '#ef4444'; $label = 'Cancellation request'; }
                    if ($t === 'approve_request') { $icon = 'fa-check-circle'; $color = '#10b981'; $label = 'Request approved'; }
                    if ($t === 'reject_request') { $icon = 'fa-times-circle'; $color = '#ef4444'; $label = 'Request rejected'; }
                    $items[] = [
                        'icon' => $icon,
                        'color' => $color,
                        'title' => $label . ' · ' . ($n['report_code'] ?? ('#' . $n['report_id'])),
                        'desc' => $n['message'],
                        'time' => $n['created_at'],
                        'link' => '../shared/road_transportation_monitoring.php?focus_report_id=' . (int)($n['report_id'] ?? 0),
                    ];
                }
            } catch (Exception $e) {}
            try {
                $stmt = $conn->prepare("SELECT status, admin_notes, created_at FROM change_requests WHERE user_id = ? AND status != 'pending' ORDER BY reviewed_at DESC LIMIT 5");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $cus = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();
                foreach ($cus as $c) {
                    $ok = ($c['status'] === 'approved');
                    $items[] = [
                        'icon' => $ok ? 'fa-check-circle' : 'fa-times-circle',
                        'color' => $ok ? '#10b981' : '#ef4444',
                        'title' => 'Change request ' . ($ok ? 'approved' : 'rejected'),
                        'desc' => $c['admin_notes'] ?: 'No additional notes from the admin.',
                        'time' => $c['created_at'],
                        'link' => '',
                    ];
                }
            } catch (Exception $e) {}
        }
        usort($items, function ($a, $b) { return strtotime($b['time']) - strtotime($a['time']); });
        return array_slice($items, 0, 8);
    }

    // Accurate CIMM display status for the supervisor feed/panels: honor the
    // verification_status overrides written by report_management / the monitoring
    // portal first, then fall back to CIMM's synced resolution_status mapping.
    function cimm_activity_status_sql(): string {
        return "CASE
                    WHEN verification_status = 'Dismissed' THEN 'resolved'
                    WHEN verification_status = 'Approved' THEN 'approved'
                    WHEN verification_status = 'In Progress' THEN 'in-progress'
                    WHEN verification_status = 'Completed' THEN 'completed'
                    WHEN verification_status = 'Cancelled' THEN 'cancelled'
                    WHEN verification_status = 'Pending' THEN 'pending'
                    ELSE " . cimm_status_case_sql() . "
                END";
    }

    function getRecentActivityFeed($conn, $road_only = false, $show_updates = false, $transport_only = false) {
        $rows = [];
        if (!$conn) return $rows;
        if ($road_only) {
            $cat_filter = " AND report_category = 'road'";
            $cat_filter_upd = " AND r.report_category = 'road'";
        } elseif ($transport_only) {
            $cat_filter = " AND report_category = 'transportation'";
            $cat_filter_upd = " AND r.report_category = 'transportation'";
        } else {
            $cat_filter = '';
            $cat_filter_upd = '';
        }
        try {
            rgmap_ensure_cimm_verification_table(rgmap_verification_pdo());
        } catch (\Throwable $e) {}
        try {
            if ($show_updates) {
                // Supervisors: the feed is driven by the progress updates logged
                // on report_management.php (report_updates), so "Recent Activity"
                // surfaces the reports that were most recently updated there —
                // status/priority/notes changes, photos and progress entries.
                $parts = [];
                $parts[] = "(SELECT ru.id AS update_id, r.id, r.report_id, r.title, r.status, r.priority, ru.created_at, ru.title AS update_title, ru.description AS update_desc, 'transport' AS src
                           FROM report_updates ru
                           JOIN road_transportation_reports r ON ru.report_id = r.id
                           WHERE 1=1{$cat_filter_upd}
                             AND r.status != 'completed'
                           ORDER BY ru.created_at DESC LIMIT 15)";
                if (!$transport_only) {
                    $parts[] = "(SELECT ru.id AS update_id, m.id, m.report_id, m.title, m.status, m.priority, ru.created_at, ru.title AS update_title, ru.description AS update_desc, 'maintenance' AS src
                               FROM report_updates ru
                               JOIN road_maintenance_reports m ON ru.report_id = m.id
                               WHERE NOT EXISTS (SELECT 1 FROM road_transportation_reports r2 WHERE r2.id = ru.report_id)
                                 AND m.status != 'completed'
                               ORDER BY ru.created_at DESC LIMIT 15)";
                    $parts[] = "(SELECT * FROM (
                                SELECT ru.id AS update_id, c.id, c.reference_code COLLATE utf8mb4_unicode_ci AS report_id, c.infrastructure COLLATE utf8mb4_unicode_ci AS title,
                                       " . cimm_activity_status_sql() . " COLLATE utf8mb4_unicode_ci AS status,
                                       COALESCE(c.priority COLLATE utf8mb4_unicode_ci, 'medium') AS priority, ru.created_at, ru.title AS update_title, ru.description AS update_desc, 'cimm' AS src
                                FROM report_updates ru
                                JOIN cimm_verification_reports c ON ru.report_id = c.id
                                WHERE NOT EXISTS (SELECT 1 FROM road_transportation_reports r2 WHERE r2.id = ru.report_id)
                                  AND NOT EXISTS (SELECT 1 FROM road_maintenance_reports m2 WHERE m2.id = ru.report_id)
                              ) AS cimm_feed
                               WHERE status != 'completed'
                               ORDER BY created_at DESC LIMIT 15)";
                }
                $query = implode("\n UNION ALL \n", $parts) . "\n ORDER BY created_at DESC LIMIT 15";
            } else {
                $parts = [];
                $parts[] = "(SELECT id, report_id, title, status, priority, created_at, 'transport' AS src FROM road_transportation_reports WHERE 1=1" . $cat_filter . " AND status != 'completed' ORDER BY created_at DESC LIMIT 6)";
                if (!$transport_only) {
                    $parts[] = "(SELECT id, report_id, title, status, priority, created_at, 'maintenance' AS src FROM road_maintenance_reports WHERE status != 'completed' ORDER BY created_at DESC LIMIT 6)";
                    $parts[] = "(SELECT * FROM (
                        SELECT id, reference_code COLLATE utf8mb4_unicode_ci AS report_id, infrastructure COLLATE utf8mb4_unicode_ci AS title, " . cimm_status_case_sql() . " COLLATE utf8mb4_unicode_ci AS status, COALESCE(priority COLLATE utf8mb4_unicode_ci, 'medium') AS priority, COALESCE(submitted_at, verified_at, synced_at, created_at) AS created_at, 'cimm' AS src
                        FROM cimm_verification_reports
                      ) AS cimm_feed
                      WHERE status != 'completed'
                      ORDER BY created_at DESC LIMIT 6)";
                }
                $query = implode("\n UNION ALL \n", $parts) . "\n ORDER BY created_at DESC LIMIT 6";
            }
            $rows = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            try {
                $parts = [];
                $parts[] = "(SELECT id, report_id, title, status, priority, created_at, 'transport' AS src FROM road_transportation_reports WHERE 1=1" . $cat_filter . " AND status != 'completed' ORDER BY created_at DESC LIMIT 6)";
                if (!$transport_only) {
                    $parts[] = "(SELECT id, report_id, title, status, priority, created_at, 'maintenance' AS src FROM road_maintenance_reports WHERE status != 'completed' ORDER BY created_at DESC LIMIT 6)";
                }
                $query = implode("\n UNION ALL \n", $parts) . "\n ORDER BY created_at DESC LIMIT 6";
                $rows = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
            } catch (Exception $e) {
                $rows = [];
            }
        }
        return $rows;
    }

    function getPriorityTaskCards($conn, $road_only = false, $supervisor = false, $role = '') {
        $rows = [];
        if (!$conn) return $rows;
        $is_transport_only = in_array($role, ['trans_ops_supervisor', 'trans_monitoring_officer'], true);
        $cat_filter = $road_only ? " AND report_category = 'road'" : '';
        try {
            if ($supervisor) {
                // Supervisors: the panel surfaces the reports that need the most
                // attention. Any report the supervisor manages in report
                // management and flagged as 'high' priority must show here
                // regardless of its current status (completed/cancelled
                // excluded), plus the pending medium/low tasks still needing
                // attention. Scope follows report_management.php's role rules:
                // Transport supervisors see only Transportation reports; Road
                // supervisors see Road transport, maintenance and CIMM reports.
                $is_transport_sup = $is_transport_only;
                $is_road_sup = in_array($role, ['road_ops_supervisor', 'road_monitoring_officer'], true);
                $active_cond = "((priority = 'high' AND status NOT IN ('completed','cancelled')) OR (status = 'pending' AND priority IN ('medium','low')))";
                if ($is_transport_sup) {
                    $transport_scope = " AND report_category = 'transportation'";
                    $show_maintenance = false;
                    $show_cimm = false;
                } elseif ($is_road_sup) {
                    $transport_scope = " AND report_category = 'road'";
                    $show_maintenance = true;
                    $show_cimm = true;
                } else {
                    $transport_scope = $cat_filter;
                    $show_maintenance = true;
                    $show_cimm = true;
                }
                $parts = [];
                $parts[] = "(SELECT id, report_id, title, status, priority, created_at, 'transport' AS src FROM road_transportation_reports WHERE " . $active_cond . $transport_scope . " ORDER BY FIELD(priority,'high','medium','low'), created_at DESC LIMIT 8)";
                if ($show_maintenance) {
                    $parts[] = "(SELECT id, report_id, title, status, priority, created_at, 'maintenance' AS src FROM road_maintenance_reports WHERE " . $active_cond . " ORDER BY FIELD(priority,'high','medium','low'), created_at DESC LIMIT 8)";
                }
                if ($show_cimm) {
                    $cimm_status = cimm_activity_status_sql();
                    $parts[] = "(SELECT * FROM (
                        SELECT id, reference_code COLLATE utf8mb4_unicode_ci AS report_id, infrastructure COLLATE utf8mb4_unicode_ci AS title, " . $cimm_status . " COLLATE utf8mb4_unicode_ci AS status, COALESCE(priority COLLATE utf8mb4_unicode_ci, 'medium') AS priority, COALESCE(submitted_at, verified_at, synced_at, created_at) AS created_at, 'cimm' AS src
                        FROM cimm_verification_reports
                      ) AS cimm_feed
                      WHERE " . $active_cond . "
                      ORDER BY FIELD(priority,'high','medium','low'), created_at DESC LIMIT 8)";
                }
                $query = implode("\n UNION ALL \n", $parts)
                    . "\n ORDER BY FIELD(priority,'high','medium','low'), created_at DESC LIMIT 8";
            } else {
                // Transport Monitoring Officers (non-supervisor) see only
                // Transportation reports, mirroring the supervisor scope.
                $non_sup_scope = $is_transport_only ? " AND report_category = 'transportation'" : $cat_filter;
                $parts = [];
                $parts[] = "(SELECT id, report_id, title, status, priority, created_at, 'transport' AS src FROM road_transportation_reports WHERE status = 'pending' AND priority IN ('high','medium','low')" . $non_sup_scope . " ORDER BY FIELD(priority,'high','medium','low'), created_at DESC LIMIT 6)";
                if (!$is_transport_only) {
                    $parts[] = "(SELECT id, report_id, title, status, priority, created_at, 'maintenance' AS src FROM road_maintenance_reports WHERE status = 'pending' AND priority IN ('high','medium','low') ORDER BY FIELD(priority,'high','medium','low'), created_at DESC LIMIT 6)";
                }
                $query = implode("\n UNION ALL \n", $parts)
                    . "\n ORDER BY FIELD(priority,'high','medium','low'), created_at DESC LIMIT 6";
            }
            $rows = $conn->query($query)->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            $rows = [];
        }
        return $rows;
    }

    function dbStatusBadge($status) {
        $map = [
            'pending' => ['Pending', 'db-st-pending'],
            'in-progress' => ['In Progress', 'db-st-progress'],
            'completed' => ['Completed', 'db-st-completed'],
            'approved' => ['Approved', 'db-st-completed'],
            'rejected' => ['Rejected', 'db-st-rejected'],
            'cancelled' => ['Cancelled', 'db-st-cancelled'],
            'active' => ['Active', 'db-st-active'],
            'assigned' => ['Assigned', 'db-st-assigned'],
        ];
        $key = strtolower((string)$status);
        return $map[$key] ?? [ucfirst($status ?: 'Pending'), 'db-st-pending'];
    }

    function dbPriorityBadge($priority) {
        $map = [
            'high' => ['High', 'db-pr-high', 'fa-exclamation-triangle'],
            'medium' => ['Medium', 'db-pr-medium', 'fa-exclamation'],
            'low' => ['Low', 'db-pr-low', 'fa-check'],
        ];
        $key = strtolower((string)$priority);
        return $map[$key] ?? [ucfirst($priority ?: 'Medium'), 'db-pr-medium', 'fa-exclamation'];
    }

    function dbActivityIcon($src) {
        if ($src === 'maintenance') return 'fa-tools';
        if ($src === 'cimm') return 'fa-building-circle-check';
        return 'fa-road';
    }

    $my_assign = getMyAssignments($conn, $user_id, $is_road_monitoring_officer);
    $my_assign_count = $my_assign['count'];
    $my_assign_items = $my_assign['items'];
    $sup_assigned_reports = $is_road_supervisor ? getSupervisorAssignedReports($conn) : [];
    $high_priority = getHighPriorityCount($conn, $is_road_monitoring_officer, $is_supervisor, $user_role);
    $awaiting_assign = getAwaitingAssignmentCount($conn, $is_supervisor, $user_role);
    $activity_feed = getRecentActivityFeed($conn, $is_road_monitoring_officer, $is_supervisor, $is_transport_only_role);
    $task_cards = getPriorityTaskCards($conn, $is_road_monitoring_officer, $is_supervisor, $user_role);
    $dash_notifs = getDashboardNotifications($conn, $user_id, $user_email, $user_role, $my_assign_items, $is_road_monitoring_officer);
    ?>

    <div class="main-content">
        <!-- Header -->
        <div class="db-header">
            <div class="db-welcome">
                <div class="db-logo">
                    <img src="../../../assets/img/logocityhall.png" alt="LGU Logo">
                </div>
                <div>
                    <h1>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Staff'); ?></h1>
                    <p>Here's what's happening with the Road and Transportation Department today</p>
                    <span class="db-role-chip"><i class="fas fa-id-badge"></i> <?php echo htmlspecialchars(ucfirst($user_role)); ?></span>
                </div>
            </div>
            <div class="db-datetime">
                <div id="currentDate"></div>
                <div id="currentTime"></div>
            </div>
        </div>

        <!-- Stats -->
        <div class="db-stats">
            <div class="stat-card" style="--sc:#f59e0b; animation-delay:.02s;">
                <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                <div>
                    <div class="stat-number"><?php echo number_format($stats['today_reports']); ?></div>
                    <div class="stat-label">Reports Today</div>
                    <div class="stat-desc">New submissions today</div>
                </div>
            </div>
            <div class="stat-card" style="--sc:#eab308; animation-delay:.06s;">
                <div class="stat-icon"><i class="fas fa-hourglass-half"></i></div>
                <div>
                    <div class="stat-number"><?php echo number_format($stats['pending_verifications']); ?></div>
                    <div class="stat-label">Pending Reports</div>
                    <div class="stat-desc">Awaiting verification</div>
                </div>
            </div>
            <div class="stat-card" style="--sc:#3b82f6; animation-delay:.10s;">
                <div class="stat-icon"><i class="fas fa-user-check"></i></div>
                <div>
                    <?php if ($is_road_supervisor): ?>
                        <div class="stat-number"><?php echo number_format($awaiting_assign); ?></div>
                        <div class="stat-label">Awaiting for Assignments</div>
                        <div class="stat-desc">Reports not yet assigned</div>
                    <?php else: ?>
                        <div class="stat-number"><?php echo number_format($my_assign_count); ?></div>
                        <div class="stat-label">My Assigned Reports</div>
                        <div class="stat-desc">Currently assigned to you</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="stat-card" style="--sc:#f97316; animation-delay:.14s;">
                <div class="stat-icon"><i class="fas fa-tools"></i></div>
                <div>
                    <div class="stat-number"><?php echo number_format($stats['under_maintenance']); ?></div>
                    <div class="stat-label">In Progress</div>
                    <div class="stat-desc">Being worked on now</div>
                </div>
            </div>
            <div class="stat-card" style="--sc:#10b981; animation-delay:.18s;">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div>
                    <div class="stat-number"><?php echo number_format($stats['completed_month']); ?></div>
                    <div class="stat-label">Completed This Month</div>
                    <div class="stat-desc">Finished work this month</div>
                </div>
            </div>
            <div class="stat-card" style="--sc:#ef4444; animation-delay:.22s;">
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div>
                    <div class="stat-number"><?php echo number_format($high_priority); ?></div>
                    <div class="stat-label">High Priority Reports</div>
                    <div class="stat-desc">Requires immediate attention</div>
                </div>
            </div>
        </div>

        <!-- Main grid: chart + notifications -->
        <div class="db-grid-main<?php echo $is_supervisor ? ' db-grid-full' : ''; ?>">
            <div class="dash-section" style="--ds:#3762c8;">
                <div class="dsh-header">
                    <div class="dsh-left">
                        <span class="dsh-icon"><i class="fas fa-chart-line"></i></span>
                        <div>
                            <h3>Weekly Reports</h3>
                            <p>Reports vs verifications over the selected period</p>
                        </div>
                    </div>
                    <select id="periodSelector" class="period-select">
                        <option value="7">Last 7 Days</option>
                        <option value="30">Last 30 Days</option>
                        <option value="90">Last 3 Months</option>
                    </select>
                </div>
                <div class="chart-wrap">
                    <canvas id="reportsChart"></canvas>
                </div>
            </div>

            <?php if (!$is_supervisor): ?>
                <div class="dash-section" style="--ds:#8b5cf6;">
                    <div class="dsh-header">
                        <div class="dsh-left">
                            <span class="dsh-icon"><i class="fas fa-bell"></i></span>
                            <div>
                                <h3>Notifications</h3>
                                <p>Your latest updates</p>
                            </div>
                        </div>
                        <a class="dsh-link" href="../shared/notifications.php">View all <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="nt-list">
                        <?php if (empty($dash_notifs)): ?>
                            <div class="db-empty">
                                <i class="fas fa-bell-slash"></i>
                                <p>No new notifications</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($dash_notifs as $n): ?>
                                <?php if ($n['link']): ?>
                                    <a class="nt-item" href="<?php echo $n['link']; ?>">
                                <?php else: ?>
                                    <div class="nt-item">
                                <?php endif; ?>
                                    <span class="nt-icon" style="background: <?php echo $n['color']; ?>;"><i class="fas <?php echo $n['icon']; ?>"></i></span>
                                    <div class="nt-body">
                                        <div class="nt-title"><?php echo htmlspecialchars($n['title']); ?></div>
                                        <div class="nt-desc"><?php echo htmlspecialchars($n['desc']); ?></div>
                                    </div>
                                    <span class="nt-time"><?php echo getTimeAgo($n['time']); ?></span>
                                <?php if ($n['link']): ?>
                                    </a>
                                <?php else: ?>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Secondary grid: activity + priority tasks + quick actions -->
        <div class="db-grid-3">
            <div class="dash-section" style="--ds:#f59e0b;">
                <div class="dsh-header">
                    <div class="dsh-left">
                        <span class="dsh-icon"><i class="fas fa-clock-rotate-left"></i></span>
                        <div>
                            <h3>Recent Activity</h3>
                            <p><?php echo $is_supervisor ? 'Latest report updates' : 'Latest reports across the department'; ?></p>
                        </div>
                    </div>
                    <?php if (!$is_transport_only_role): ?>
                        <select class="period-select" id="activityFilter" onchange="filterActivityFeed(this.value)">
                            <option value="all">All Reports</option>
                            <option value="road">LGU Monitoring</option>
                            <option value="cimm">CIMM Reports</option>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="db-list<?php echo $is_supervisor ? ' act-scroll' : ''; ?>" id="activityFeed">
                    <?php if (empty($activity_feed)): ?>
                        <div class="db-empty">
                            <i class="fas fa-clock"></i>
                            <p>No recent activity</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($activity_feed as $act): ?>
                            <?php
                                $act_link = '../shared/road_transportation_monitoring.php?focus_report_id=' . (int)($act['id'] ?? 0) . '&source=' . rawurlencode($act['src']);
                                $act_icon_color = '#3762c8';
                                if ($act['src'] === 'maintenance') { $act_icon_color = '#f97316'; }
                                if ($act['src'] === 'cimm') { $act_icon_color = '#8b5cf6'; }
                            ?>
                            <a class="act-item" data-src="<?php echo htmlspecialchars($act['src'] ?? 'transport'); ?>" href="<?php echo $act_link; ?>">
                                <span class="act-icon" style="background: <?php echo $act_icon_color; ?>;"><i class="fas <?php echo dbActivityIcon($act['src']); ?>"></i></span>
                                <div class="act-body">
                                    <div class="act-title"><?php echo htmlspecialchars($act['title']); ?></div>
                                    <?php if ($is_supervisor && !empty($act['update_desc'])): ?>
                                        <div class="act-desc"><?php echo htmlspecialchars($act['update_desc']); ?></div>
                                    <?php endif; ?>
                                    <div class="act-meta">
                                        <?php $sb = dbStatusBadge($act['status']); $pb = dbPriorityBadge($act['priority']); ?>
                                        <span class="db-badge <?php echo $sb[1]; ?>"><?php echo $sb[0]; ?></span>
                                        <span class="db-badge <?php echo $pb[1]; ?>"><i class="fas <?php echo $pb[2]; ?>"></i> <?php echo $pb[0]; ?></span>
                                        <span class="act-time"><i class="<?php echo $is_supervisor ? 'fas fa-pen-to-square' : 'far fa-clock'; ?>"></i> <?php echo $is_supervisor ? 'Updated ' : ''; ?><?php echo getTimeAgo($act['created_at']); ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                        <div class="db-empty" id="activityFilterEmpty" style="display:none;">
                            <i class="fas fa-filter"></i>
                            <p>No activity in this category</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dash-section" style="--ds:#ef4444;">
                <div class="dsh-header">
                    <div class="dsh-left">
                        <span class="dsh-icon"><i class="fas fa-list-check"></i></span>
                        <div>
                            <h3>Priority Tasks</h3>
                            <p>Reports that need attention first</p>
                        </div>
                    </div>
                </div>
                <div class="db-list">
                    <?php if (empty($task_cards)): ?>
                        <div class="db-empty">
                            <i class="fas fa-check-circle"></i>
                            <p>No priority tasks</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($task_cards as $task): ?>
                            <?php
                                $tsrc = $task['src'] === 'maintenance' ? '#f97316' : '#ef4444';
                                if (strtolower($task['priority']) === 'medium') $tsrc = '#f59e0b';
                                if (strtolower($task['priority']) === 'low') $tsrc = '#10b981';
                                $tsb = dbStatusBadge($task['status']);
                                $tpb = dbPriorityBadge($task['priority']);
                                // CIMM tasks open in report management (they are
                                // managed there); everything else opens the
                                // monitoring page.
                                if ($task['src'] === 'cimm') {
                                    $task_link = '../admin/report_management.php?source=cimm&id=' . (int)($task['id'] ?? 0);
                                } else {
                                    $task_link = '../shared/road_transportation_monitoring.php?focus_report_id=' . (int)($task['id'] ?? 0) . '&source=' . rawurlencode($task['src']);
                                }
                            ?>
                            <a class="task-card" href="<?php echo $task_link; ?>">
                                <span class="task-priority-icon" style="--ts:<?php echo $tsrc; ?>;"><i class="fas <?php echo $tpb[2]; ?>"></i></span>
                                <div class="task-body">
                                    <div class="task-title"><?php echo htmlspecialchars($task['title']); ?></div>
                                    <div class="task-meta">
                                        <span class="task-id"># <?php echo htmlspecialchars($task['report_id']); ?></span>
                                        <span class="db-badge <?php echo $tpb[1]; ?>"><i class="fas <?php echo $tpb[2]; ?>"></i> <?php echo $tpb[0]; ?></span>
                                        <span class="db-badge <?php echo $tsb[1]; ?>"><?php echo $tsb[0]; ?></span>
                                        <span class="act-time"><i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($task['created_at'])); ?></span>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="dash-section" style="--ds:#10b981;">
                <div class="dsh-header">
                    <div class="dsh-left">
                        <span class="dsh-icon"><i class="fas fa-bolt"></i></span>
                        <div>
                            <h3>Quick Actions</h3>
                            <p>Jump to your most-used tools</p>
                        </div>
                    </div>
                </div>
                <div class="qa-grid">
                    <a class="qa-btn" style="--qa:#3b82f6;" href="../shared/road_transportation_monitoring.php">
                        <span class="qa-icon"><i class="fas fa-map-location-dot"></i></span>
                        <span class="qa-label">View Map</span>
                    </a>
                    <?php if (!$is_supervisor): ?>
                        <a class="qa-btn" style="--qa:#8b5cf6;" href="../shared/notifications.php">
                            <span class="qa-icon"><i class="fas fa-clipboard-list"></i></span>
                            <span class="qa-label">My Assigned Reports</span>
                        </a>
                        <a class="qa-btn" style="--qa:#f59e0b;" href="../shared/notifications.php">
                            <span class="qa-icon"><i class="fas fa-bell"></i></span>
                            <span class="qa-label">Notifications</span>
                        </a>
                    <?php endif; ?>
                    <a class="qa-btn" style="--qa:#10b981;" href="../shared/analytics.php">
                        <span class="qa-icon"><i class="fas fa-chart-pie"></i></span>
                        <span class="qa-label">Analytics</span>
                    </a>
                </div>
            </div>
        </div>

        <?php if ($is_road_supervisor): ?>
            <!-- Reports Assigned by Road Supervisors -->
            <div class="dash-section" style="--ds:#7c3aed;">
                <div class="dsh-header">
                    <div class="dsh-left">
                        <span class="dsh-icon"><i class="fas fa-user-check"></i></span>
                        <div>
                            <h3>Awaiting for assignments</h3>
                            <p>Reports road supervisors assigned to monitoring officers</p>
                        </div>
                        <span class="dsh-badge"><?php echo count($sup_assigned_reports); ?></span>
                    </div>
                    <a class="dsh-link" href="../admin/report_management.php">Report Management <i class="fas fa-arrow-right"></i></a>
                </div>
                <?php if (empty($sup_assigned_reports)): ?>
                    <div class="db-empty">
                        <i class="fas fa-user-check"></i>
                        <p>No reports have been assigned to officers yet.</p>
                    </div>
                <?php else: ?>
                    <div class="asg-grid">
                        <?php foreach ($sup_assigned_reports as $sa): ?>
                            <?php
                                $sasb = dbStatusBadge($sa['report_status']);
                                $sapb = dbPriorityBadge($sa['priority']);
                                $sup_link = '../shared/road_transportation_monitoring.php?focus_report_id=' . (int)$sa['report_id'] . '&source=' . rawurlencode($sa['_source']);
                            ?>
                            <div class="asg-card" style="--as:#7c3aed;">
                                <div class="asg-top">
                                    <span class="asg-icon"><i class="fas fa-user-check"></i></span>
                                    <div>
                                        <div class="asg-title"><?php echo htmlspecialchars($sa['report_title']); ?></div>
                                        <div class="asg-id"># <?php echo htmlspecialchars($sa['report_code']); ?></div>
                                    </div>
                                </div>
                                <div class="asg-badges">
                                    <span class="db-badge db-st-assigned"><i class="fas fa-user"></i> <?php echo htmlspecialchars($sa['officer_name']); ?></span>
                                    <span class="db-badge <?php echo $sasb[1]; ?>"><?php echo $sasb[0]; ?></span>
                                    <span class="db-badge <?php echo $sapb[1]; ?>"><i class="fas <?php echo $sapb[2]; ?>"></i> <?php echo $sapb[0]; ?></span>
                                </div>
                                <div class="asg-foot">
                                    <span class="asg-date"><i class="fas fa-user-gear"></i> <?php echo htmlspecialchars($sa['assigner_name']); ?> · <i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($sa['assigned_at'])); ?></span>
                                    <a class="btn-view" href="<?php echo $sup_link; ?>"><i class="fas fa-eye"></i> View Report</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
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

        // Initialize Chart
        const ctx = document.getElementById('reportsChart').getContext('2d');
        const gradient1 = ctx.createLinearGradient(0, 0, 0, 300);
        gradient1.addColorStop(0, 'rgba(55, 98, 200, 0.22)');
        gradient1.addColorStop(1, 'rgba(55, 98, 200, 0)');
        const gradient2 = ctx.createLinearGradient(0, 0, 0, 300);
        gradient2.addColorStop(0, 'rgba(16, 185, 129, 0.20)');
        gradient2.addColorStop(1, 'rgba(16, 185, 129, 0)');

        const reportsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']); ?>,
                datasets: [{
                    label: <?php echo json_encode($is_trans_ops_supervisor ? 'Transportation Reports' : 'Road Reports'); ?>,
                    data: <?php echo json_encode($chart_data['reports']); ?>,
                    borderColor: '#3762c8',
                    backgroundColor: gradient1,
                    borderWidth: 2.5,
                    tension: 0.45,
                    fill: true,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: '#3762c8',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2
                }, {
                    label: 'Verifications',
                    data: <?php echo json_encode($chart_data['verifications']); ?>,
                    borderColor: '#10b981',
                    backgroundColor: gradient2,
                    borderWidth: 2.5,
                    tension: 0.45,
                    fill: true,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBackgroundColor: '#10b981',
                    pointHoverBorderColor: '#fff',
                    pointHoverBorderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 18,
                            color: '#64748b',
                            font: { family: 'Poppins', size: 12, weight: '600' }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#0f172a',
                        titleColor: '#e2e8f0',
                        bodyColor: '#cbd5e1',
                        padding: 12,
                        cornerRadius: 10,
                        displayColors: true,
                        boxPadding: 4,
                        titleFont: { family: 'Poppins', size: 13, weight: '600' },
                        bodyFont: { family: 'Poppins', size: 12 }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grace: '20%',
                        ticks: {
                            color: '#94a3b8',
                            font: { family: 'Poppins', size: 11 },
                            stepSize: 5
                        },
                        grid: {
                            color: 'rgba(148, 163, 184, 0.16)',
                            borderDash: [4, 4]
                        },
                        border: { display: false }
                    },
                    x: {
                        ticks: { color: '#94a3b8', font: { family: 'Poppins', size: 11 } },
                        grid: { display: false },
                        border: { color: 'rgba(148, 163, 184, 0.2)' }
                    }
                }
            }
        });

        // Handle period selector change
        document.getElementById('periodSelector').addEventListener('change', function() {
            updateChartData(this.value);
        });

        function updateChartData(period) {
            fetch(`../api/get_chart_data.php?period=${period}`)
                .then(response => response.json())
                .then(data => {
                    reportsChart.data.datasets[0].data = data.reports;
                    reportsChart.data.datasets[1].data = data.verifications;
                    reportsChart.update();
                })
                .catch(error => {
                    console.error('Error fetching chart data:', error);
                    showNotification('Unable to update chart data', 'error');
                });
        }

        // Filter recent activity feed by source (road vs CIMM)
        function filterActivityFeed(filter) {
            const list = document.getElementById('activityFeed');
            if (!list) return;
            const items = list.querySelectorAll('.act-item');
            const filterEmpty = document.getElementById('activityFilterEmpty');
            let visibleCount = 0;
            items.forEach(item => {
                const src = item.getAttribute('data-src') || '';
                let show = false;
                if (filter === 'all') {
                    show = true;
                } else if (filter === 'cimm') {
                    show = src === 'cimm';
                } else if (filter === 'road') {
                    show = src === 'transport' || src === 'maintenance';
                }
                item.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });
            if (filterEmpty) filterEmpty.style.display = visibleCount === 0 ? '' : 'none';
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'notification ' + type;
            notification.textContent = message;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 10px;
                color: white;
                font-weight: 500;
                z-index: 10000;
                animation: slideIn 0.3s ease;
                background: ${type === 'error' ? '#dc3545' : type === 'success' ? '#28a745' : '#17a2b8'};
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.25);
            `;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.remove();
            }, 3000);
        }
    </script>

</body>
</html>

<?php
// Helper functions
function getActivityIcon($type) {
    $icons = [
        'road' => 'road',
        'maintenance' => 'tools',
        'verification' => 'clipboard-check',
        'report' => 'file-alt'
    ];

    return $icons[$type] ?? 'file';
}

function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;

    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $time);
    }
}
?>

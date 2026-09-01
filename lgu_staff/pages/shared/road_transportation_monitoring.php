<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session settings (must be set before session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

session_start();
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../api/cimm_verification_data.php';
// Archive helpers (rgmap_archive_report, rgmap_archive_cimm_report,
// rgmap_notify_requestor, rgmap_auto_archive_completed, ...) — shared with
// progress_update_api.php.
require_once __DIR__ . '/../api/progress_archive_helpers.php';

// Defensive migration for the CIMM sync/verification columns — these are
// normally added by rgmap_cimm_ensure_schema() the first time a report gets
// pushed, but getRecentSubmissions() below selects them unconditionally
// on every load, so a fresh install (or a page load racing the very first
// async push) needs them to exist up front too.
$conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS cimm_sync_status VARCHAR(20) DEFAULT NULL");
$conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS cimm_pushed_at TIMESTAMP NULL DEFAULT NULL");
$conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS cimm_verified_at TIMESTAMP NULL DEFAULT NULL");
$conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS cimm_verified_by VARCHAR(150) DEFAULT NULL");

// report_type/department were ENUMs whose allowed values never actually
// matched what the form below submits (report_type: 'road_damage' etc. vs
// the real dropdown values like 'potholes'/'traffic_jam'/'cracks'/...;
// department: only 'engineering'/'planning'/'maintenance'/'finance' vs the
// hardcoded 'Road and Transportation'). Since sql_mode here doesn't include
// STRICT_TRANS_TABLES, every mismatched value has been silently saved as ''
// instead of erroring — every report's Type/Department has always been
// blank. Widen both to VARCHAR once so the real submitted value is kept
// instead of discarded.
$rtCol = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'report_type'")->fetch_assoc();
if ($rtCol && stripos($rtCol['Type'], 'enum') === 0) {
    $conn->query("ALTER TABLE road_transportation_reports MODIFY COLUMN report_type VARCHAR(50) NOT NULL");
}
$deptCol = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'department'")->fetch_assoc();
if ($deptCol && stripos($deptCol['Type'], 'enum') === 0) {
    $conn->query("ALTER TABLE road_transportation_reports MODIFY COLUMN department VARCHAR(100) NOT NULL");
}

// Session timeout configuration
$session_timeout = 30 * 60; // 30 minutes in seconds
lgu_enforce_idle_timeout($session_timeout, '../../login.php?timeout=1');

// Check if user is logged in
if (
    !isset($_SESSION['user_id']) ||
    !is_admin_or_staff_role($_SESSION['role'] ?? '')
) {
    header('Location: ../../login.php');
    exit();
}

// Transportation Operations Supervisors and Transportation Monitoring Officers
// are restricted to Transportation category reports only — the Roads option is
// hidden and road submissions are rejected server-side. Recent Submissions /
// map / stats filter report_category = 'transportation'.
$is_transport_supervisor = in_array($_SESSION['role'] ?? '', ['trans_ops_supervisor', 'trans_monitoring_officer'], true);

// Road Operations Supervisors and Road Monitoring Officers are restricted to
// Road-category reports only — Transportation reports (LGU or Citizen) are
// hidden from Recent Submissions.
$is_road_only_role = in_array($_SESSION['role'] ?? '', ['road_ops_supervisor', 'road_monitoring_officer'], true);

// Road/Transportation Monitoring Officers may not directly complete or cancel
// a project. For these roles the Complete/Cancel buttons are replaced with
// Request Completion / Request Cancellation, which do not change the status or
// archive the report (the request review workflow will be added later).
$is_officer_role = in_array($_SESSION['role'] ?? '', ['road_monitoring_officer', 'trans_monitoring_officer'], true);

// Road Operations Supervisors (the Road supervisor portal) get a trimmed Recent
// Submissions source filter: Citizen Reports and Infrastructure Projects are
// hidden from the Type dropdown (their reports still appear under All Types).
$is_road_supervisor = ($_SESSION['role'] ?? '') === 'road_ops_supervisor';

// Road Monitoring Officers see the assigned officer's name in the Assignment
// column of the Recent Submissions table (same as the supervisors do).
$is_road_monitoring_officer = ($_SESSION['role'] ?? '') === 'road_monitoring_officer';

// System Admin: dark-mode readable status badges only for this role.
$is_system_admin = ($_SESSION['role'] ?? '') === 'system_admin';

// Transportation Operations Supervisor: dark-mode readable status badges only
// for this role.
$is_trans_ops_supervisor = ($_SESSION['role'] ?? '') === 'trans_ops_supervisor';

// Transportation Monitoring Officer: dark-mode readable status badges only for
// this role.
$is_transport_monitoring_officer = ($_SESSION['role'] ?? '') === 'trans_monitoring_officer';

// db-badge class helpers — mirror lgu_staff_dashboard.php's dbStatusBadge() /
// dbPriorityBadge() so the Road Operations Supervisor's and Road Monitoring
// Officer's Recent Submissions badges (status / priority) match the dashboard
// exactly.
function rmo_db_status_class($status) {
    $map = [
        'pending' => 'db-st-pending',
        'in-progress' => 'db-st-progress',
        'completed' => 'db-st-completed',
        'approved' => 'db-st-completed',
        'rejected' => 'db-st-rejected',
        'cancelled' => 'db-st-cancelled',
        'active' => 'db-st-active',
        'assigned' => 'db-st-assigned',
    ];
    $key = strtolower((string)$status);
    return $map[$key] ?? 'db-st-pending';
}

function rmo_db_priority_badge($priority) {
    $map = [
        'high' => ['db-pr-high', 'fa-exclamation-triangle'],
        'medium' => ['db-pr-medium', 'fa-exclamation'],
        'low' => ['db-pr-low', 'fa-check'],
    ];
    $key = strtolower((string)$priority);
    return $map[$key] ?? ['db-pr-medium', 'fa-exclamation'];
}

// Function to get enhanced dashboard stats
function getEnhancedStats() {
    global $conn, $is_transport_supervisor, $is_road_only_role;
    $stats = ['total' => 0, 'active' => 0, 'critical' => 0, 'resolved_month' => 0];
    if ($conn) {
        try {
            // Transportation Operations Supervisors see only Transportation reports.
            // Road-only roles (Road Ops Supervisor, Road Monitoring Officer) see
            // only Road reports.
            if (($_SESSION['role'] ?? '') === 'trans_ops_supervisor') {
                // ONLY for the Transportation Operations Supervisor: the dashboard
                // cards mirror the transportation reports actually shown in this
                // page's Recent Submissions list (same WHERE as
                // getRecentSubmissions()): finalized transportation reports that
                // are not infrastructure issues. Active = Approved or In Progress;
                // High/Critical = only Critical reports (severity = 'critical';
                // the priority column only holds high/medium/low), and only when
                // that report is also listed in report_management.php
                // (approved/in-progress and visible there as Citizen or LGU
                // Monitoring).
                $shown_where = "report_type != 'infrastructure_issue'
                    AND status IN ('approved','in-progress','completed')
                    AND (created_by IS NULL OR created_by = 0
                         OR cimm_sync_status IS NULL OR cimm_sync_status <> 'pushed'
                         OR (report_category = 'transportation' AND report_source = 'local' AND created_by != 0))
                    AND report_category = 'transportation'";
                $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE {$shown_where}");
                if ($r) $stats['total'] = (int)$r->fetch_assoc()['c'];
                $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE {$shown_where} AND status IN ('approved','in-progress')");
                if ($r) $stats['active'] = (int)$r->fetch_assoc()['c'];
                $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE {$shown_where} AND status IN ('approved','in-progress') AND severity = 'critical' AND (created_by = 0 OR (created_by != 0 AND report_source = 'local'))");
                if ($r) $stats['critical'] = (int)$r->fetch_assoc()['c'];
                $r = $conn->query("SELECT COUNT(*) as c FROM (
                    SELECT report_id FROM road_transportation_reports
                    WHERE status='completed'
                      AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE())
                      AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE())
                      AND report_category = 'transportation'
                    UNION
                    SELECT report_id FROM road_transportation_reports_archive
                    WHERE status='completed'
                      AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE())
                      AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE())
                      AND report_category = 'transportation'
                ) AS resolved_this_month");
                if ($r) $stats['resolved_month'] = (int)$r->fetch_assoc()['c'];
                return $stats;
            } elseif ($is_transport_supervisor) {
                // Transportation Monitoring Officers: the dashboard cards mirror
                // the transportation reports actually shown in this page's Recent
                // Submissions list (same WHERE as getRecentSubmissions() with
                // $transport_only): finalized transportation reports that are not
                // infrastructure issues.
                $officer_where = "report_type != 'infrastructure_issue'
                    AND status IN ('approved','in-progress','completed')
                    AND (created_by IS NULL OR created_by = 0
                         OR cimm_sync_status IS NULL OR cimm_sync_status <> 'pushed'
                         OR (report_category = 'transportation' AND report_source = 'local' AND created_by != 0))
                    AND report_category = 'transportation'";
                $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE {$officer_where}");
                if ($r) $stats['total'] = (int)$r->fetch_assoc()['c'];
                $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE {$officer_where} AND status IN ('approved','in-progress')");
                if ($r) $stats['active'] = (int)$r->fetch_assoc()['c'];
                $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE {$officer_where} AND (severity IN ('high','critical') OR priority IN ('high','critical'))");
                if ($r) $stats['critical'] = (int)$r->fetch_assoc()['c'];
                $r = $conn->query("SELECT COUNT(*) as c FROM (
                    SELECT report_id FROM road_transportation_reports
                    WHERE report_type != 'infrastructure_issue'
                      AND status='completed'
                      AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE())
                      AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE())
                      AND report_category = 'transportation'
                    UNION
                    SELECT report_id FROM road_transportation_reports_archive
                    WHERE report_type != 'infrastructure_issue'
                      AND status='completed'
                      AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE())
                      AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE())
                      AND report_category = 'transportation'
                ) AS resolved_this_month");
                if ($r) $stats['resolved_month'] = (int)$r->fetch_assoc()['c'];
                return $stats;
            } elseif ($is_road_only_role) {
                $cat_filter = " AND report_category = 'road'";
            } else {
                $cat_filter = '';
            }
            $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE 1=1{$cat_filter}");
            if ($r) $stats['total'] = (int)$r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE status IN ('approved','in-progress'){$cat_filter}");
            if ($r) $stats['active'] = (int)$r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE severity IN ('high','critical') AND status != 'completed'{$cat_filter}");
            if ($r) $stats['critical'] = (int)$r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM (
                SELECT report_id FROM road_transportation_reports
                WHERE status='completed'
                  AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE())
                  AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE()){$cat_filter}
                UNION
                SELECT report_id FROM road_transportation_reports_archive
                WHERE status='completed'
                  AND MONTH(COALESCE(completed_at, updated_at))=MONTH(CURDATE())
                  AND YEAR(COALESCE(completed_at, updated_at))=YEAR(CURDATE()){$cat_filter}
            ) AS resolved_this_month");
            if ($r) $stats['resolved_month'] = (int)$r->fetch_assoc()['c'];
        } catch (Exception $e) { error_log("Enhanced stats error: ".$e->getMessage()); }
    }
    return $stats;
}

// Function to get recent submissions from all report sources managed by
// report_management.php. Only finalized reports are included:
//   - LGU Monitoring / Citizen reports (road_transportation_reports) that are
//     APPROVED or have been VERIFIED by CIMM. LGU ROAD reports appear once
//     approved/in-progress/completed (matching report_management.php's LGU
//     panel), and LGU Transportation reports
//     (report_category='transportation') do not require CIMM verification and
//     appear once approved
//   - Infrastructure Projects (ipms_road_projects): locally approved or
//     in-progress on Active Monitoring (same as transport/CIMM), or
//     status = 'completed' on Completed Projects
//   - CIMM reports whose verification_status is 'Verified'
function getRecentSubmissions($limit = 10, $status_filter = 'all', $type_filter = 'all', $transport_only = false, $road_only = false, $assigned_to_user_id = null, $completed_only = false, $skip_active_assignment_filter = false) {
    global $conn;
    $reports = [];
    if (!$conn) return $reports;

    if ($completed_only) {
        $status_filter = 'completed';
    }

    // Transportation Operations Supervisors see only Transportation reports.
    $transport_category_filter = $transport_only ? " AND report_category = 'transportation'" : '';

    // Road Operations Supervisors see only Road reports.
    $road_category_filter = $road_only ? " AND report_category = 'road'" : '';

    $transport_status_sql = $completed_only
        ? "t.status = 'completed'"
        : "t.status IN ('approved', 'in-progress')";

    $cimm_status_sql = $completed_only
        ? "verification_status = 'Completed'"
        : "verification_status IN ('Approved', 'In Progress')";

    $ipms_status_sql = $completed_only
        ? "status = 'completed'"
        : "status IN ('approved', 'in-progress')";

    // Helper to append shared WHERE/ORDER/LIMIT clauses and run a query
    $fetch = function ($sql, $status_filter, $type_filter, $limit) use ($conn, $completed_only) {
        $params = [];
        $types = '';
        if ($status_filter !== 'all') {
            // LOWER() so CIMM rows (verification_status stores 'Completed',
            // 'Approved', ... capitalized) match the lowercase dropdown values.
            $sql .= " AND LOWER(status) = LOWER(?)";
            $params[] = $status_filter;
            $types .= 's';
        }
        if ($type_filter !== 'all') {
            $sql .= " AND source = ?";
            $params[] = $type_filter;
            $types .= 's';
        }
        if ($completed_only) {
            // Display order only. No LIMIT so every completed row stays in the
            // result set; the page cap is applied after sorting.
            $sql .= " ORDER BY completed_at DESC";
        } else {
            $sql .= " ORDER BY created_at DESC LIMIT ?";
            $params[] = $limit;
            $types .= 'i';
        }
        $stmt = $conn->prepare($sql);
        if (!empty($params)) $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        $stmt->close();
        return $rows;
    };

    try {
        // 1. LGU Monitoring (Road & Transportation Monitoring) + Citizen reports.
        //    Staff-created rows are LGU monitoring; created_by 0/NULL are Citizen.
        //    LGU staff-created reports (Road AND Transportation, report_source =
        //    'local') appear once they are finalized regardless of CIMM sync
        //    state, matching report_management.php's LGU panel.
        $reports = array_merge($reports, $fetch(
            "SELECT t.id, t.report_id, t.title, t.report_type, t.report_category,
                    t.report_source, t.created_by,
                    CASE WHEN t.created_by IS NULL OR t.created_by = 0 THEN 'citizen' ELSE 'lgu' END AS source,
                    t.status, t.priority, t.severity, t.created_at, t.completed_at, t.description,
                    t.latitude, t.longitude, t.location, t.reporter_name, t.attachments, t.image_path,
                    t.cimm_status, t.cimm_sync_status, t.cimm_verified_at, t.cimm_verified_by,
                    t.engineer, t.budget_allocation, t.cimm_engineer_name, t.cimm_budget,
                    u.full_name AS creator_full_name, u.phone_number AS creator_phone, u.email AS creator_email,
                    NULL AS approval_status, NULL AS verification_status,
                    'road_transportation_reports' AS _source_table
              FROM road_transportation_reports t
              LEFT JOIN users u ON u.id = t.created_by
             WHERE {$transport_status_sql}
               AND (t.created_by IS NULL OR t.created_by = 0
                    OR t.cimm_sync_status IS NULL OR t.cimm_sync_status <> 'pushed'
                    OR (t.report_category IN ('transportation', 'road') AND t.report_source = 'local' AND t.created_by != 0))
                   $transport_category_filter{$road_category_filter}",
            $status_filter, $type_filter, $limit
        ));

        // 2. Infrastructure Projects (ipms_road_projects).
        //    Active Monitoring: locally approved. Completed Projects: status = completed.
        //    Excluded for Transportation Operations Supervisors.
        if (!$transport_only) {
            $reports = array_merge($reports, $fetch(
                "SELECT project_id AS id,
                        CAST(project_id AS CHAR) AS report_id,
                        project_name AS title,
                        COALESCE(NULLIF(road_type, ''), 'infrastructure_issue') AS report_type,
                        'road' AS report_category,
                        'infrastructure' AS source,
                        status,
                        'medium' AS priority,
                        NULL AS severity,
                        created_at,
                        NULL AS completed_at,
                        road_status AS description,
                        start_lat AS latitude,
                        start_lng AS longitude,
                        COALESCE(NULLIF(road_name, ''), project_name) AS location,
                        NULL AS reporter_name,
                        NULL AS attachments,
                        NULL AS image_path,
                        NULL AS cimm_status,
                        NULL AS cimm_sync_status,
                        NULL AS cimm_verified_at,
                        NULL AS cimm_verified_by,
                        'ipms_road_projects' AS _source_table
                 FROM ipms_road_projects
                 WHERE {$ipms_status_sql}",
                $status_filter, $type_filter, $limit
            ));
        }

        // 3. CIMM reports (finalized = verification_status 'Approved').
        //    Excluded for Transportation Operations Supervisors.
        if (!$transport_only) {
            try {
            $reports = array_merge($reports, $fetch(
                "SELECT * FROM (
                    SELECT id, reference_code AS report_id, infrastructure AS title,
                            'infrastructure_issue' AS report_type, 'road' AS report_category, 'cimm' AS source,
                            verification_status AS status, priority, NULL AS severity,
                            COALESCE(submitted_at, verified_at, synced_at, NOW()) AS created_at,
                            resolved_at AS completed_at,
                            issue AS description, coord_lat AS latitude, coord_lng AS longitude,
                            location, district, district AS detected_district, reporter_name, NULL AS attachments, NULL AS image_path,
                            verification_status AS cimm_status,
                            'verified' AS cimm_sync_status, verified_at AS cimm_verified_at,
                            NULL AS cimm_verified_by, approval_status,
                            engineer, budget_allocation,
                            'cimm_verification_reports' AS _source_table
                     FROM cimm_verification_reports
                     WHERE {$cimm_status_sql}
                       AND infrastructure = 'Roads'
                 ) AS cimm_mapped WHERE 1=1",
                $status_filter, $type_filter, $limit
            ));
        } catch (Exception $e) {
            error_log("Recent CIMM reports error: ".$e->getMessage());
        }
        }

        // "Your Reports" for officers: keep only rows assigned to this user_id.
        if ($assigned_to_user_id) {
            $reports = filter_reports_assigned_to_user($conn, $reports, $assigned_to_user_id);
        }

        // Live monitoring list: hide Unassigned reports until an officer is assigned.
        // Completed Projects keeps the full finalized set. Road Supervisor "Your
        // Reports" uses ownership filtering instead (matches report_management.php).
        if (!$completed_only && !$skip_active_assignment_filter) {
            $reports = filter_reports_with_active_assignment($conn, $reports);
        }

        // Completed Projects: reorder only (ORDER BY completed_at DESC).
        // Live monitoring stays by created_at. array_slice is display paging
        // and does not delete or archive rows.
        if ($completed_only) {
            usort($reports, function ($a, $b) {
                $ta = strtotime((string)($a['completed_at'] ?? '')) ?: 0;
                $tb = strtotime((string)($b['completed_at'] ?? '')) ?: 0;
                if ($tb === $ta) {
                    return (strtotime((string)($b['created_at'] ?? '')) ?: 0)
                         <=> (strtotime((string)($a['created_at'] ?? '')) ?: 0);
                }
                return $tb <=> $ta;
            });
        } else {
            usort($reports, function($a, $b) {
                return strtotime($b['created_at'] ?? 'now') - strtotime($a['created_at'] ?? 'now');
            });
        }
        $reports = array_slice($reports, 0, $limit);
    } catch (Exception $e) {
        error_log("Recent reports error: ".$e->getMessage());
    }
    return $reports;
}

// Function to get monitoring statistics
function getMonitoringStatistics() {
    global $conn, $is_transport_supervisor, $is_road_only_role;
    $stats = [];
    
    // Transportation Operations Supervisors see only Transportation reports.
    // Road-only roles (Road Ops Supervisor, Road Monitoring Officer) see only
    // Road reports.
    if ($is_transport_supervisor) {
        $cat_filter = " AND report_category = 'transportation'";
    } elseif ($is_road_only_role) {
        $cat_filter = " AND report_category = 'road'";
    } else {
        $cat_filter = '';
    }
    
    if ($conn) {
        try {
            // Get active roads count
            $result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status != 'completed'" . $cat_filter);
            if ($result) {
                $stats['active_roads'] = $result->fetch_assoc()['count'];
            } else {
                $stats['active_roads'] = 0;
            }
            
            // Get incident count
            $result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'pending' AND priority = 'high'" . $cat_filter);
            if ($result) {
                $stats['incidents'] = $result->fetch_assoc()['count'];
            } else {
                $stats['incidents'] = 0;
            }
            
            // Get under repair count
            $result = $conn->query("SELECT COUNT(*) as count FROM road_maintenance_reports WHERE status = 'in-progress'");
            if ($result) {
                $stats['under_repair'] = $result->fetch_assoc()['count'];
            } else {
                $stats['under_repair'] = 0;
            }
            
            // Calculate clear flow percentage
            $total_result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports" . $cat_filter);
            if ($total_result) {
                $total = $total_result->fetch_assoc()['count'];
                
                $clear_result = $conn->query("SELECT COUNT(*) as count FROM road_transportation_reports WHERE status = 'completed'" . $cat_filter);
                if ($clear_result) {
                    $clear = $clear_result->fetch_assoc()['count'];
                    $stats['clear_flow'] = $total > 0 ? round(($clear / $total) * 100, 0) : 94;
                } else {
                    $stats['clear_flow'] = 94;
                }
            } else {
                $stats['clear_flow'] = 94;
            }
            
        } catch (Exception $e) {
            error_log("Statistics query error: " . $e->getMessage());
            // Return sample data if database queries fail
            $stats = [
                'active_roads' => 142,
                'incidents' => 8,
                'under_repair' => 23,
                'clear_flow' => 94
            ];
        }
    } else {
        // Return sample data if database is not available
        $stats = [
            'active_roads' => 142,
            'incidents' => 8,
            'under_repair' => 23,
            'clear_flow' => 94
        ];
    }
    
    return $stats;
}

function getActiveAlerts() {
    global $conn, $is_transport_supervisor, $is_road_only_role;
    $alerts = [];
    
    // Transportation Operations Supervisors see only Transportation alerts.
    // Road-only roles (Road Ops Supervisor, Road Monitoring Officer) see only
    // Road alerts.
    if ($is_transport_supervisor) {
        $cat_filter = " AND report_category = 'transportation'";
    } elseif ($is_road_only_role) {
        $cat_filter = " AND report_category = 'road'";
    } else {
        $cat_filter = '';
    }
    
    if ($conn) {
        $query = "SELECT title, created_at, priority FROM road_transportation_reports 
                  WHERE status = 'pending' AND priority IN ('high', 'medium') 
                  {$cat_filter}ORDER BY created_at DESC LIMIT 5";
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            $alerts[] = [
                'title' => $row['title'],
                'time' => getTimeAgo($row['created_at']),
                'priority' => $row['priority']
            ];
        }
        
    } else {
        // Return sample alerts
        $alerts = [
            ['title' => 'Multi-vehicle accident on Highway 101', 'time' => '5 minutes ago', 'priority' => 'high'],
            ['title' => 'Road maintenance on Main Street', 'time' => '15 minutes ago', 'priority' => 'medium'],
            ['title' => 'Traffic light malfunction at Oak Avenue', 'time' => '30 minutes ago', 'priority' => 'high']
        ];
    }
    
    return $alerts;
}

// Function to get road status
function getRoadStatus() {
    global $conn, $is_transport_supervisor, $is_road_only_role;
    $roads = [];
    
    // Transportation Operations Supervisors see only Transportation reports.
    // Road-only roles (Road Ops Supervisor, Road Monitoring Officer) see only
    // Road reports.
    if ($is_transport_supervisor) {
        $cat_filter = " AND report_category = 'transportation'";
    } elseif ($is_road_only_role) {
        $cat_filter = " AND report_category = 'road'";
    } else {
        $cat_filter = '';
    }
    
    if ($conn) {
        $query = "SELECT title, status, description, created_at FROM road_transportation_reports 
                  WHERE status IN ('pending', 'in-progress', 'completed') 
                  {$cat_filter}ORDER BY created_at DESC LIMIT 10";
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            $roads[] = [
                'name' => $row['title'],
                'status' => $row['status'],
                'condition' => $row['description'] ?: 'No specific condition reported',
                'traffic' => getTrafficLevel($row['status'])
            ];
        }
        
    } else {
        // Return sample road data
        $roads = [
            ['name' => 'Highway 101', 'status' => 'completed', 'condition' => 'Clear - Normal traffic flow', 'traffic' => 'Light traffic'],
            ['name' => 'Main Street', 'status' => 'pending', 'condition' => 'Heavy congestion - Accident reported', 'traffic' => 'Heavy traffic'],
            ['name' => 'Oak Avenue', 'status' => 'in-progress', 'condition' => 'Moderate - Road maintenance', 'traffic' => 'Moderate traffic'],
            ['name' => 'Elm Street', 'status' => 'completed', 'condition' => 'Clear - No issues reported', 'traffic' => 'Light traffic']
        ];
    }
    
    return $roads;
}

// Helper function to get time ago
function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minutes ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hours ago';
    } else {
        $days = floor($diff / 86400);
        return $days . ' days ago';
    }
}

// Helper function to get traffic level based on status
function getTrafficLevel($status) {
    switch ($status) {
        case 'pending':
            return 'Heavy traffic';
        case 'in-progress':
            return 'Moderate traffic';
        case 'completed':
            return 'Light traffic';
        default:
            return 'Normal traffic';
    }
}

// Quezon City center for map
define('QC_LAT', 14.651417);
define('QC_LNG', 121.04917);

// Server-side point-in-polygon (ray casting) for GeoJSON coordinate rings
function point_in_polygon_server($lat, $lng, $coords) {
    $ring = $coords[0] ?? [];
    $n = count($ring);
    if ($n < 3) return false;
    $inside = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $ring[$i][1]; $yi = $ring[$i][0];
        $xj = $ring[$j][1]; $yj = $ring[$j][0];
        if ((($yi > $lng) !== ($yj > $lng)) && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi)) {
            $inside = !$inside;
        }
    }
    return $inside;
}

// Handle AJAX: get map markers (reports with lat/lng)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_markers') {
    header('Content-Type: application/json');
    $markers = [];
    if ($conn) {
        // Transportation Operations Supervisors see only Transportation markers.
        // Road-only roles (Road Ops Supervisor, Road Monitoring Officer) see only
        // Road markers.
        if ($is_transport_supervisor) {
            $cat_filter = " AND report_category = 'transportation'";
        } elseif ($is_road_only_role) {
            $cat_filter = " AND report_category = 'road'";
        } else {
            $cat_filter = '';
        }
        $sql = "SELECT id, report_id, title, report_type, description, status, priority, severity, latitude, longitude, detected_district, barangay, street_name, created_at 
                FROM road_transportation_reports 
                WHERE latitude IS NOT NULL AND longitude IS NOT NULL 
                  AND status IN ('approved', 'in-progress', 'completed')
                  {$cat_filter}
                ORDER BY created_at DESC";
        $res = $conn->query($sql);
        while ($row = $res->fetch_assoc()) {
            $markers[] = $row;
        }

        if (!$is_transport_supervisor) {
            $ipms_sql = "SELECT project_id AS id,
                                CAST(project_id AS CHAR) AS report_id,
                                project_name AS title,
                                COALESCE(NULLIF(road_type, ''), 'infrastructure_issue') AS report_type,
                                road_status AS description,
                                status,
                                'medium' AS priority,
                                NULL AS severity,
                                start_lat AS latitude,
                                start_lng AS longitude,
                                NULL AS detected_district,
                                NULL AS barangay,
                                road_name AS street_name,
                                created_at
                         FROM ipms_road_projects
                         WHERE start_lat IS NOT NULL AND start_lng IS NOT NULL
                           AND status IN ('approved', 'in-progress', 'completed')
                         ORDER BY created_at DESC";
            $ipms_res = $conn->query($ipms_sql);
            if ($ipms_res) {
                while ($row = $ipms_res->fetch_assoc()) {
                    $markers[] = $row;
                }
            }
        }
    }
    echo json_encode($markers);
    exit;
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'submit_report':
            // Start output buffering to catch any errors
            ob_start();
            header('Content-Type: application/json');
            try {
                
                $lat = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
                $lng = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;
                $issue_type = sanitize_input($_POST['issue_type'] ?? '');
                $specific_type = sanitize_input($_POST['specific_type'] ?? '');
                $severity = sanitize_input($_POST['severity'] ?? '');
                $description = sanitize_input($_POST['description'] ?? '');

                // GIS-detected location fields (from frontend)
                $detected_district = sanitize_input($_POST['detected_district'] ?? '');
                $barangay = sanitize_input($_POST['barangay'] ?? '');
                $street_name = sanitize_input($_POST['street_name'] ?? '');

                // Combine issue type and specific type for detailed reporting.
                // report_category (Road vs Transportation) is independent of the
                // specific issue type — any issue type may be paired with either.
                $full_issue_type = $specific_type ? $specific_type : $issue_type;
                $report_category = ($issue_type === 'roads') ? 'road' : 'transportation';
                $report_source = 'local';

                $allowed_categories = ['roads', 'transportation'];
                $allowed_issue_types = [
                    'traffic_jam', 'accident', 'road_closure', 'traffic_light_outage',
                    'congestion', 'parking_violation', 'public_transport_issue',
                    'vehicle_breakdown', 'traffic_sign_issue',
                    'potholes', 'road_damage', 'cracks', 'erosion', 'flooding',
                    'debris', 'shoulder_damage', 'marking_fade',
                ];
                if (!in_array($issue_type, $allowed_categories, true)) {
                    echo json_encode(['success' => false, 'message' => 'Please select Road or Transportation as the report type.']);
                    exit;
                }
                if ($specific_type === '' || !in_array($specific_type, $allowed_issue_types, true)) {
                    echo json_encode(['success' => false, 'message' => 'Please select a valid issue type.']);
                    exit;
                }

                if ($lat === null || $lng === null || $issue_type === '' || $description === '') {
                    echo json_encode(['success' => false, 'message' => 'Missing required fields.']);
                    exit;
                }
                
                // Server-side validation: ensure pin is within Quezon City using GeoJSON boundary
                $qcBoundaryPath = __DIR__ . '/../api/qc_boundary.json';
                $inside_qc = false;
                if (file_exists($qcBoundaryPath)) {
                    $qcGeoJson = json_decode(file_get_contents($qcBoundaryPath), true);
                    if ($qcGeoJson && isset($qcGeoJson['coordinates'])) {
                        // Get all polygon coordinate arrays (handles MultiPolygon)
                        $allPolygons = ($qcGeoJson['type'] ?? '') === 'MultiPolygon'
                            ? $qcGeoJson['coordinates']
                            : [ $qcGeoJson['coordinates'] ];
                        foreach ($allPolygons as $polygon) {
                            $rings = $polygon;
                            foreach ($rings as $ring) {
                                $n = count($ring);
                                $poly_inside = false;
                                for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
                                    // GeoJSON: [lng, lat] → xi = lat, yi = lng (matches vertical ray casting)
                                    $xi = $ring[$i][1]; $yi = $ring[$i][0];
                                    $xj = $ring[$j][1]; $yj = $ring[$j][0];
                                    if ((($yi > $lng) !== ($yj > $lng)) && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi)) {
                                        $poly_inside = !$poly_inside;
                                    }
                                }
                                // First ring is outer; subsequent rings are holes
                                if ($poly_inside && $rings[0] === $ring) {
                                    $inside_qc = true;
                                } elseif ($poly_inside) {
                                    // Point is inside a hole → not inside QC
                                    $inside_qc = false;
                                    break 2;
                                }
                            }
                            if ($inside_qc) break;
                        }
                    }
                }
                if (!$inside_qc) {
                    echo json_encode(['success' => false, 'message' => 'Location must be within Quezon City only.']);
                    exit;
                }
                
                // Server-side district detection (validate frontend detection)
                $districts_geojson_path = __DIR__ . '/../api/qc_districts.geojson';
                if (file_exists($districts_geojson_path)) {
                    $geojson_raw = file_get_contents($districts_geojson_path);
                    $geojson_data = json_decode($geojson_raw, true);
                    if ($geojson_data && isset($geojson_data['features'])) {
                        $server_detected_district = '';
                        $best_dist = INF;
                        $best_match = '';
                        foreach ($geojson_data['features'] as $feature) {
                            $coords = $feature['geometry']['coordinates'] ?? [];
                            $geom_type = $feature['geometry']['type'] ?? '';
                            $matched = false;
                            if ($geom_type === 'Polygon' && !empty($coords)) {
                                $matched = point_in_polygon_server($lat, $lng, $coords);
                            } elseif ($geom_type === 'MultiPolygon' && !empty($coords)) {
                                foreach ($coords as $poly) {
                                    if (point_in_polygon_server($lat, $lng, $poly)) {
                                        $matched = true;
                                        break;
                                    }
                                }
                            }
                            if ($matched) {
                                $server_detected_district = sanitize_input($feature['properties']['district'] ?? $feature['properties']['district_name'] ?? '');
                                break;
                            }
                            // Fallback: nearest centroid for gap areas
                            $ring = $geom_type === 'Polygon' ? ($coords[0] ?? []) : ($coords[0][0] ?? []);
                            $cnt = count($ring);
                            if ($cnt > 0) {
                                $slng = 0; $slat = 0;
                                foreach ($ring as $c) { $slng += $c[0]; $slat += $c[1]; }
                                $clng = $slng / $cnt; $clat = $slat / $cnt;
                                $dx = $lng - $clng; $dy = $lat - $clat;
                                $dist = $dx * $dx + $dy * $dy;
                                if ($dist < $best_dist) {
                                    $best_dist = $dist;
                                    $best_match = sanitize_input($feature['properties']['district'] ?? $feature['properties']['district_name'] ?? '');
                                }
                            }
                        }
                        if (empty($server_detected_district)) {
                            $server_detected_district = $best_match;
                        }
                        // Use server-detected district as authoritative, override if frontend mismatches
                        if (!empty($server_detected_district)) {
                            $detected_district = $server_detected_district;
                        } else {
                            $detected_district = '';
                        }
                    }
                }
                
                // Handle multiple image uploads
                $attachments = [];
                $upload_dir = __DIR__ . '/../../uploads/report_images';
                $upload_dir = str_replace('\\', '/', $upload_dir);
                
                $has_photo = false;
                if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
                    foreach ($_FILES['photos']['name'] as $i => $_n) {
                        if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                            $has_photo = true;
                            break;
                        }
                    }
                }
                if (!$has_photo) {
                    echo json_encode(['success' => false, 'message' => 'Please upload at least one photo before submitting.']);
                    exit;
                }
                
                if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
                    $file_count = count($_FILES['photos']['name']);
                    for ($i = 0; $i < $file_count; $i++) {
                        if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                        if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) {
                            $upload_errors = [
                                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize',
                                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE',
                                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                                UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                                UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
                            ];
                            $error_code = $_FILES['photos']['error'][$i];
                            $error_msg = $upload_errors[$error_code] ?? 'Unknown error (code: ' . $error_code . ')';
                            echo json_encode(['success' => false, 'message' => "Upload failed for '" . $_FILES['photos']['name'][$i] . "': " . $error_msg]);
                            exit;
                        }
                        
                        $file = [
                            'name' => $_FILES['photos']['name'][$i],
                            'type' => $_FILES['photos']['type'][$i],
                            'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                            'error' => $_FILES['photos']['error'][$i],
                            'size' => $_FILES['photos']['size'][$i]
                        ];
                        
                        $upload_result = handle_file_upload($file, $upload_dir, ['jpg', 'jpeg', 'png']);
                        
                        if ($upload_result['success']) {
                            $attachments[] = [
                                'type' => 'image',
                                'filename' => $upload_result['filename'],
                                'original_name' => $file['name'],
                                'file_path' => 'uploads/report_images/' . $upload_result['filename'],
                                'uploaded_at' => date('Y-m-d H:i:s')
                            ];
                        } else {
                            $error_msg = $upload_result['error'] ?? 'Unknown upload error';
                            echo json_encode(['success' => false, 'message' => "Upload failed for '" . $file['name'] . "': " . $error_msg]);
                            exit;
                        }
                    }
                }
                
                // Use the specific type if provided, otherwise use general type
                $report_type = $full_issue_type; // This contains the specific type from the form
                // Map severity: severe -> critical
                $severity_db = ($severity === 'severe') ? 'critical' : $severity;
                $priority = ($severity_db === 'critical' || $severity_db === 'high') ? 'high' : ($severity_db === 'medium' ? 'medium' : 'low');
                $report_id = 'RPT-' . date('Ymd-His') . '-' . substr(uniqid(), -5);
                $issue_type_titles = [
                    'traffic_jam' => 'Traffic Jam',
                    'accident' => 'Vehicle Accident',
                    'road_closure' => 'Road Closure',
                    'traffic_light_outage' => 'Traffic Light Outage',
                    'congestion' => 'Heavy Congestion',
                    'parking_violation' => 'Illegal Parking',
                    'public_transport_issue' => 'Public Transport Issue',
                    'vehicle_breakdown' => 'Vehicle Breakdown',
                    'traffic_sign_issue' => 'Traffic Sign Issue',
                    'potholes' => 'Potholes',
                    'road_damage' => 'Road Damage',
                    'cracks' => 'Road Cracks',
                    'erosion' => 'Road Erosion',
                    'flooding' => 'Street Flooding',
                    'debris' => 'Road Debris',
                    'shoulder_damage' => 'Shoulder Damage',
                    'marking_fade' => 'Faded Road Markings',
                ];
                $title = $issue_type_titles[$full_issue_type] ?? str_replace('_', ' ', ucfirst($full_issue_type));
                $user_id = $_SESSION['user_id'] ?? null;
                // Set department explicitly to prevent truncation
                $department = 'Road and Transportation';
                
                // Validate department is not empty
                if (empty($department)) {
                    $department = 'Road and Transportation';
                }
                $location_address = sanitize_input($_POST['location_address'] ?? '');
                $location_parts = array_filter([$street_name, $barangay, $detected_district]);
                $location_str = !empty($location_address) ? $location_address : (!empty($location_parts) ? implode(', ', $location_parts) . ', Quezon City' : $lat . ', ' . $lng . ', Quezon City');
                $attachments_json = !empty($attachments) ? json_encode($attachments) : null;
                // Extract image path for the new image_path column
                $image_path = !empty($attachments) ? $attachments[0]['file_path'] : null;
                
                $stmt = $conn->prepare("INSERT INTO road_transportation_reports 
                    (report_id, report_type, report_category, report_source, title, department, priority, status, created_date, description, location, latitude, longitude, detected_district, barangay, street_name, severity, attachments, image_path, created_by) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                
                if (!$stmt) {
                    echo json_encode(['success' => false, 'message' => 'Database prepare failed: ' . $conn->error]);
                    exit;
                }
                
                // Parameters: report_id, report_type, report_category, report_source, title, department, priority, description, location, lat, lng, district, barangay, street, severity, attachments, image_path, user_id
                $stmt->bind_param("sssssssssddssssssi", $report_id, $report_type, $report_category, $report_source, $title, $department, $priority, $description, $location_str, $lat, $lng, $detected_district, $barangay, $street_name, $severity_db, $attachments_json, $image_path, $user_id);
                
                if ($stmt->execute()) {
                    $new_id = $stmt->insert_id;
                    ob_end_clean(); // Clear any output before JSON
                    echo json_encode(['success' => true, 'message' => (!empty($attachments) ? 'Report submitted with image' : 'Report submitted') . '. It will appear in Verification and Monitoring.', 'report_id' => $report_id]);
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    // Push to CIMM after the response is already sent — a slow or
                    // failed push must never delay or break the staff member's
                    // own report submission.
                    try {
                        require_once __DIR__ . '/../api/rgmap_cimm_sync.php';
                        rgmap_cimm_sync_report_async($conn, (int)$new_id, 'created');
                    } catch (\Throwable $e) {
                        error_log('RGMAO->CIMM push (submit_report) error: ' . $e->getMessage());
                    }
                } else {
                    ob_end_clean();
                    $error_details = $stmt->error;
                    error_log("Statement execution failed: " . $error_details);
                    error_log("Bound parameters: report_id=$report_id, report_type=$report_type, title=$title, department=$department, priority=$priority, description=$description, location=$location_str, lat=$lat, lng=$lng, district=$detected_district, barangay=$barangay, street=$street_name, severity=$severity_db, attachments=$attachments_json, image_path=$image_path, user_id=$user_id");
                    echo json_encode(['success' => false, 'message' => 'Failed to save report: ' . $error_details]);
                }
            } catch (Exception $e) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            } catch (Error $e) {
                ob_end_clean();
                echo json_encode(['success' => false, 'message' => 'Fatal error: ' . $e->getMessage()]);
            }
            exit;
    }
}

// Resolve a report into the flat shape Recent Submissions renders, searching
// across the tables that feed it (transport / IPMS / CIMM).
// Returns null when the id does not exist in any of them.
function resolve_recent_focus_row(int $id, string $source_hint = ''): ?array {
    global $conn;

    $candidates = $source_hint !== ''
        ? [$source_hint]
        : ['transport', 'maintenance', 'infrastructure', 'cimm'];

    try {
        foreach ($candidates as $src) {
            if ($src === 'transport') {
                $stmt = $conn->prepare("SELECT t.id, t.report_id, t.title, t.report_type, t.report_category, t.status, t.priority, t.severity, t.created_at, t.completed_at, t.description, t.latitude, t.longitude, t.location, t.reporter_name, t.attachments, t.image_path, t.cimm_sync_status, t.cimm_verified_at, t.cimm_verified_by, t.created_by, u.full_name AS creator_full_name, u.phone_number AS creator_phone, u.email AS creator_email FROM road_transportation_reports t LEFT JOIN users u ON u.id = t.created_by WHERE t.id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($r) {
                    $r['_source_table'] = 'road_transportation_reports';
                    $r['source'] = (!empty($r['created_by'])) ? 'lgu' : 'citizen';
                    return $r;
                }
            } elseif ($src === 'maintenance') {
                $stmt = $conn->prepare("SELECT id, report_id, title, COALESCE(NULLIF(report_type, ''), 'maintenance') AS report_type, 'road' AS report_category, status, priority, NULL AS severity, created_at, completed_at, description, NULL AS latitude, NULL AS longitude, location, NULL AS reporter_name, NULL AS attachments, NULL AS image_path, NULL AS cimm_sync_status, NULL AS cimm_verified_at, NULL AS cimm_verified_by FROM road_maintenance_reports WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($r) {
                    $r['_source_table'] = 'road_maintenance_reports';
                    $r['source'] = 'maintenance';
                    return $r;
                }
                $src = 'infrastructure';
            }
            if ($src === 'infrastructure') {
                $stmt = $conn->prepare("SELECT project_id AS id, CAST(project_id AS CHAR) AS report_id, project_name AS title, COALESCE(NULLIF(road_type, ''), 'infrastructure_issue') AS report_type, 'road' AS report_category, status, 'medium' AS priority, NULL AS severity, created_at, NULL AS completed_at, road_status AS description, start_lat AS latitude, start_lng AS longitude, COALESCE(NULLIF(road_name, ''), project_name) AS location, NULL AS reporter_name, NULL AS attachments, NULL AS image_path, NULL AS cimm_sync_status, NULL AS cimm_verified_at, NULL AS cimm_verified_by FROM ipms_road_projects WHERE project_id = ? AND status IN ('approved', 'in-progress', 'completed')");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($r) {
                    $r['_source_table'] = 'ipms_road_projects';
                    $r['source'] = 'infrastructure';
                    return $r;
                }
            } elseif ($src === 'cimm') {
                require_once __DIR__ . '/../api/cimm_verification_data.php';
                $pdo = rgmap_verification_pdo();
                rgmap_ensure_cimm_verification_table($pdo);
                $stmt = $pdo->prepare("SELECT id, reference_code AS report_id, infrastructure AS title, 'infrastructure_issue' AS report_type, 'road' AS report_category, " . cimm_status_case_sql() . " AS status, priority, NULL AS severity, COALESCE(submitted_at, verified_at, synced_at, NOW()) AS created_at, resolved_at AS completed_at, issue AS description, coord_lat AS latitude, coord_lng AS longitude, location, district, district AS detected_district, reporter_name, NULL AS attachments, NULL AS image_path, 'verified' AS cimm_sync_status, verified_at AS cimm_verified_at, NULL AS cimm_verified_by FROM cimm_verification_reports WHERE id = ?");
                $stmt->execute([$id]);
                $r = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($r) {
                    $r['_source_table'] = 'cimm_verification_reports';
                    $r['source'] = 'cimm';
                    return $r;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Recent focus row resolution error: " . $e->getMessage());
    }
    return null;
}

// Get filter parameters
$is_completed_projects_view = defined('MONITORING_COMPLETED_VIEW') && MONITORING_COMPLETED_VIEW;

// Every row of the System Admin's Completed Projects table is Completed, so the
// Status column carries no information there and is dropped. The monitoring page
// and all other roles keep it.
$hide_status_column = $is_system_admin && $is_completed_projects_view;

// Completed Projects only: every row already carries report_category, so the
// table and View modal can label it as Category (Road / Transportation)
// without changing stored data. The live monitoring page is left as-is.
$show_category_column = $is_completed_projects_view;
$show_public_column = defined('COMPLETED_PROJECTS_SHOW_PUBLIC_COLUMN') && COMPLETED_PROJECTS_SHOW_PUBLIC_COLUMN
    && function_exists('completed_projects_public_column_header_html');
$table_colspan = 9
    + ($show_category_column ? 1 : 0)
    + ($show_public_column ? 1 : 0)
    - ($hide_status_column ? 1 : 0);

if (!function_exists('completed_project_category_label')) {
    function completed_project_category_label($category, $source = '') {
        $cat = strtolower(trim((string)$category));
        if ($cat === 'transportation') {
            return 'Transportation';
        }
        if ($cat === 'road') {
            return 'Road';
        }
        $src = strtolower(trim((string)$source));
        if (in_array($src, ['cimm', 'infrastructure', 'maintenance'], true)) {
            return 'Road';
        }
        return 'Road';
    }
}

$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$your_reports_default_roles = [
    'road_ops_supervisor',
    'trans_ops_supervisor',
    'road_monitoring_officer',
    'trans_monitoring_officer',
];
$your_reports_default = in_array($_SESSION['role'] ?? '', $your_reports_default_roles, true);
$your_reports_only = isset($_GET['mine'])
    ? ((string)$_GET['mine'] === '1')
    : $your_reports_default;
if ($is_completed_projects_view) {
    $status_filter = 'completed';
}

// Deep-link focus: ?focus_report_id= (numeric PK) from a Progress Update
// notification's "View Report" button. The report may live in any of the
// tables that feed Recent Submissions, so we locate it server-side, force the
// filters off so nothing hides it, and inject the row if Recent Submissions
// wouldn't normally show it (e.g. it is not yet in a finalized status).
$focus_report_id = isset($_GET['focus_report_id']) ? (int)$_GET['focus_report_id'] : 0;
$focus_source_hint = (string)($_GET['source'] ?? '');
$focus_report_type_hint = (string)($_GET['report_type'] ?? '');
$focus_target = ['found' => false, 'id' => $focus_report_id, 'source' => '', 'report_id' => ''];

if ($focus_report_id > 0 && !$is_completed_projects_view) {
    $status_filter = 'all';
    $type_filter = 'all';
}

// Get data for the page
$alerts = getActiveAlerts();
$roads = getRoadStatus();
$enhanced_stats = getEnhancedStats();
$recent_fetch_limit = ($your_reports_only && $is_road_supervisor) ? 500 : ($your_reports_only ? 200 : 10);
// Officers: "Your Reports" filters to reports assigned to this user_id.
// Default for officers/supervisors is Your Reports when mine is absent.
$officer_assigned_user_id = null;
$road_supervisor_your_reports = $your_reports_only && $is_road_supervisor;
if ($your_reports_only
    && ($is_road_monitoring_officer || $is_transport_monitoring_officer)) {
    $officer_assigned_user_id = (int)($_SESSION['user_id'] ?? 0);
}
$recent_reports = getRecentSubmissions(
    $recent_fetch_limit,
    $status_filter,
    'all',
    $is_transport_supervisor,
    $is_road_only_role,
    $officer_assigned_user_id,
    $is_completed_projects_view,
    $road_supervisor_your_reports
);

if ($focus_report_id > 0) {
    $focus_row = resolve_recent_focus_row($focus_report_id, $focus_source_hint);
    if ($focus_row && !$is_completed_projects_view && strtolower((string)($focus_row['status'] ?? '')) === 'completed') {
        $officer_assigned_focus = false;
        if ($is_road_monitoring_officer || $is_transport_monitoring_officer) {
            $assigned_keys = get_assigned_report_keys($conn, (int)($_SESSION['user_id'] ?? 0));
            $focus_key = ($focus_row['_source_table'] ?? 'road_transportation_reports') . ':' . ($focus_row['id'] ?? 0);
            $officer_assigned_focus = isset($assigned_keys[$focus_key]);
        }
        if (!$officer_assigned_focus) {
            $redirect = 'completed_projects.php?focus_report_id=' . (int)$focus_report_id;
            if ($focus_source_hint !== '') {
                $redirect .= '&source=' . urlencode($focus_source_hint);
            }
            header('Location: ' . $redirect);
            exit;
        }
    }
}

if ($focus_report_id > 0) {
    $focus_row = $focus_row ?? resolve_recent_focus_row($focus_report_id, $focus_source_hint);
    if ($focus_row) {
        $focus_target['source'] = $focus_row['source'] ?? '';
        $focus_target['report_id'] = $focus_row['report_id'] ?? '';

        // Respect role-based restrictions: transport supervisors never see
        // infrastructure or CIMM rows in Recent Submissions; road-only roles
        // (Road Operations Supervisor, Road Monitoring Officer) never see
        // non-Road reports.
        $restricted = ($is_transport_supervisor
                && in_array($focus_row['source'] ?? '', ['infrastructure', 'cimm'], true))
            || ($is_road_only_role && (($focus_row['report_category'] ?? '') !== 'road'));

        // Road / Transportation Monitoring Officers only see reports assigned
        // to them, so a deep-linked focus row must also carry an active assignment.
        if (!$restricted && ($is_road_monitoring_officer || $is_transport_monitoring_officer)) {
            $assigned_keys = get_assigned_report_keys($conn, (int)($_SESSION['user_id'] ?? 0));
            $focus_key = ($focus_row['_source_table'] ?? 'road_transportation_reports') . ':' . ($focus_row['id'] ?? 0);
            if (!isset($assigned_keys[$focus_key])) {
                $restricted = true;
            } elseif ($focus_report_type_hint !== ''
                && $focus_report_type_hint !== ($focus_row['_source_table'] ?? '')) {
                $restricted = true;
            }
        }

        // Transportation Monitoring Officers never deep-link into non-transportation rows.
        if (!$restricted && $is_transport_monitoring_officer
            && (($focus_row['report_category'] ?? '') !== 'transportation')) {
            $restricted = true;
        }

        // Monitoring page only: Unassigned reports stay off this list until
        // an officer is assigned. Completed Projects does not use this gate.
        // Supervisors bypass this gate so their notifications can deep-link
        // directly to any report without requiring an active assignment first.
        if (!$restricted && !$is_completed_projects_view && !$is_road_supervisor && !$is_transport_supervisor) {
            $any_assigned = get_active_assignment_keys($conn);
            $focus_key = ($focus_row['_source_table'] ?? 'road_transportation_reports') . ':' . ($focus_row['id'] ?? 0);
            if (!isset($any_assigned[$focus_key])) {
                $restricted = true;
            }
        }

        if (!$restricted) {
            $focus_target['found'] = true;
            $already_present = false;
            foreach ($recent_reports as $existing) {
                if ((int)($existing['id'] ?? 0) === $focus_report_id
                    && ($existing['source'] ?? '') === ($focus_row['source'] ?? '')) {
                    $already_present = true;
                    break;
                }
            }
            if (!$already_present) {
                $recent_reports[] = $focus_row;
                usort($recent_reports, function ($a, $b) use ($is_completed_projects_view) {
                    if ($is_completed_projects_view) {
                        $ta = strtotime((string)($a['completed_at'] ?? '')) ?: 0;
                        $tb = strtotime((string)($b['completed_at'] ?? '')) ?: 0;
                        if ($tb === $ta) {
                            return (strtotime((string)($b['created_at'] ?? '')) ?: 0)
                                 <=> (strtotime((string)($a['created_at'] ?? '')) ?: 0);
                        }
                        return $tb <=> $ta;
                    }
                    return strtotime($b['created_at'] ?? 'now') - strtotime($a['created_at'] ?? 'now');
                });
            }
        }
    }
}

// Display-only Assignment Status (Assigned / Unassigned) for every report row.
// Reads live from report_assignments so it reflects Assign/Unassign changes
// automatically. Never alters the report workflow or report statuses.
annotate_report_assignment_status($conn, $recent_reports);

if (!empty($your_reports_only)) {
    if ($is_road_monitoring_officer || $is_transport_monitoring_officer) {
        // Officers: only reports actively assigned to THIS logged-in user_id.
        $officer_uid = (int)($_SESSION['user_id'] ?? 0);
        foreach ($recent_reports as &$__orr) {
            if (empty($__orr['_source_table'])) {
                $__orr['_source_table'] = rgmap_report_row_source_table($__orr);
            }
        }
        unset($__orr);
        $recent_reports = filter_reports_assigned_to_user($conn, $recent_reports, $officer_uid);
    } elseif ($is_road_supervisor) {
        // Road Supervisor: same filter union as report_management.php.
        $recent_reports = rgmap_filter_road_supervisor_your_reports($conn, $recent_reports);
    } else {
        // Other supervisors / admin: ownership via first assigned_by (unchanged).
        $recent_reports = rgmap_filter_reports_you_handle($conn, $recent_reports);
    }
    $recent_reports = array_slice($recent_reports, 0, 10);
}

if (!$is_completed_projects_view) {
    annotate_last_progress_update($conn, $recent_reports);
}

// Transparency request + Public column status (display-only).
// Completed Projects needs Public for every role; system admin also needs the
// raw request status so awaiting-review rows can be flagged.
if ($is_completed_projects_view || $is_system_admin) {
    annotate_transparency_request_status($conn, $recent_reports);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_completed_projects_view ? 'Completed Projects' : 'Road and Transportation Monitoring'; ?> | LGU Staff</title>
    <link rel="icon" type="image/png" href="../../assets/img/infra-gov-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=6">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../../css/progress-updates.css?v=<?php echo @filemtime(__DIR__ . '/../../css/progress-updates.css') ?: time(); ?>">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../../js/progress-updates.js?v=<?php echo filemtime(__DIR__ . '/../../js/progress-updates.js'); ?>"></script>
    <script src="../../js/progress-updates-common.js?v=<?php echo filemtime(__DIR__ . '/../../js/progress-updates-common.js'); ?>"></script>
    <script src="../../js/tomtom-services.js?v=<?php echo filemtime(__DIR__ . '/../../js/tomtom-services.js'); ?>"></script>
    <script>
        const TOMTOM_API_KEY = '<?php echo TOMTOM_API_KEY; ?>';
        const TOMTOM_PROXY_URL = '<?php
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
            echo $protocol . '://' . $host . $scriptDir . '/../api/tomtom/proxy.php';
        ?>';
    </script>
    <style>
        body {
            background: #f7f5f0;
            min-height: 100vh;
        }
        
        .main-content {
            position: relative;
            z-index: 1;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .monitoring-header {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .header-title h1 {
            color: #1e3c72;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .header-title p {
            color: #666;
            font-size: 14px;
        }

        .dt-chip {
            display: flex; align-items: center; gap: 10px;
            background: var(--color-primary-bg, #eef2ff);
            border: 1px solid var(--border-default, #d5dce8);
            border-radius: 14px; padding: 10px 14px;
            flex-shrink: 0;
        }
        .dt-chip i {
            color: #fff; font-size: 16px; width: 28px; height: 28px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, #1e3c72, #0f274a);
        }
        .dt-chip #currentDate { font-weight: 600; color: var(--text-primary, #0f172a); font-size: 13px; }
        .dt-chip #currentTime { color: var(--text-secondary, #64748b); font-size: 12px; margin-top: 1px; }

        .btn-action {
            padding: 10px 20px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.3);
        }

        .btn-success-custom {
            padding: 10px 20px;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-success-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(16, 185, 129, 0.3);
        }

        .btn-danger-custom {
            padding: 10px 20px;
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-danger-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #495057);
        }

        .btn-secondary:hover {
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.3);
        }

        .monitoring-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 25px;
            margin-bottom: 25px;
        }

        .map-section {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .map-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .map-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
        }

        .map-filters {
            display: flex;
            gap: 10px;
        }

        .filter-btn {
            padding: 6px 12px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: 1px solid rgba(55, 98, 200, 0.3);
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-btn:hover,
        .filter-btn.active {
            background: #3762c8;
            color: white;
        }

        .map-hint {
            font-size: 13px;
            color: #666;
            margin-top: 4px;
        }
        #map {
            height: 500px;
            border-radius: 12px;
            overflow: hidden;
        }
        .report-form-panel {
            margin-top: 16px;
            padding: 20px;
            background: #f0f4fa;
            border-radius: 12px;
            border: 1px solid rgba(55, 98, 200, 0.2);
        }
        .report-form-panel h4 {
            color: #1e3c72;
            margin-bottom: 16px;
            font-size: 16px;
        }
        .report-form-panel label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #333;
            margin-top: 10px;
            margin-bottom: 4px;
        }
        .report-form-panel select,
        .report-form-panel textarea,
        .report-form-panel input[type="file"] {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid rgba(55, 98, 200, 0.3);
            border-radius: 8px;
            font-size: 14px;
        }
        .report-form-panel input[type="file"] {
            cursor: pointer;
        }
        .report-form-panel .form-actions {
            margin-top: 16px;
            display: flex;
            gap: 10px;
        }

        .sidebar-section {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }

        .info-card {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .info-card-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-list {
            max-height: 200px;
            overflow-y: auto;
        }

        .alert-item {
            display: flex;
            align-items: flex-start;
            padding: 12px;
            margin-bottom: 10px;
            background: rgba(220, 53, 69, 0.05);
            border-left: 3px solid #dc3545;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .alert-item:hover {
            background: rgba(220, 53, 69, 0.1);
        }

        .alert-item.warning {
            background: rgba(255, 193, 7, 0.05);
            border-left-color: #ffc107;
        }

        .alert-item.warning:hover {
            background: rgba(255, 193, 7, 0.1);
        }

        .alert-icon {
            margin-right: 12px;
            color: #dc3545;
            font-size: 16px;
        }

        .alert-item.warning .alert-icon {
            color: #ffc107;
        }

        .alert-content {
            flex: 1;
        }

        .alert-title {
            font-size: 13px;
            font-weight: 500;
            color: #333;
            margin-bottom: 3px;
        }

        .alert-time {
            font-size: 11px;
            color: #999;
        }

        .road-status-list {
            max-height: 250px;
            overflow-y: auto;
        }

        .road-item {
            display: flex;
            align-items: center;
            padding: 12px;
            margin-bottom: 10px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .road-item:hover {
            background: rgba(55, 98, 200, 0.1);
            transform: translateX(5px);
        }

        .road-status {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 12px;
            flex-shrink: 0;
        }

        .status-clear {
            background: #28a745;
        }

        .status-moderate {
            background: #ffc107;
        }

        .status-heavy {
            background: #dc3545;
        }

        .road-info {
            flex: 1;
        }

        .road-name {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            margin-bottom: 3px;
        }

        .road-condition {
            font-size: 12px;
            color: #666;
        }

        .traffic-indicator {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            color: #666;
        }


        /* ========== Enhanced Features ========== */
        body.completed-projects-view .map-section,
        body.completed-projects-view .stats-row,
        body.completed-projects-view .sidebar-section {
            display: none !important;
        }
        body.completed-projects-view .reports-table-section {
            margin-top: 0;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: #f0f4fa;
            border-radius: 14px;
            padding: 20px 18px;
            border: 1px solid rgba(55, 98, 200, 0.1);
            transition: all 0.25s ease;
        }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 24px rgba(0,0,0,0.08); }
        .stat-card .stat-icon {
            width: 44px; height: 44px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: #fff; margin-bottom: 12px;
        }
        .stat-card .stat-icon.blue { background: linear-gradient(135deg,#3762c8,#1e3c72); }
        .stat-card .stat-icon.orange { background: linear-gradient(135deg,#f59e0b,#d97706); }
        .stat-card .stat-icon.red { background: linear-gradient(135deg,#ef4444,#dc2626); }
        .stat-card .stat-icon.green { background: linear-gradient(135deg,#10b981,#059669); }
        .stat-card .stat-number { font-size: 26px; font-weight: 700; color: #1e3c72; }
        .stat-card .stat-label { font-size: 13px; color: #6b7280; font-weight: 500; margin-top: 2px; }

        .map-toolbar {
            display: flex;
            flex-direction: row;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            width: 100%;
            margin-bottom: 12px;
        }
        .map-toolbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            flex: 1 1 320px;
            min-width: 0;
        }
        .map-toolbar-left .map-search-box {
            display: flex;
            align-items: center;
            gap: 6px;
            flex: 1 1 180px;
            min-width: 160px;
            max-width: 320px;
        }
        .map-toolbar-left .map-search-box input {
            flex: 1 1 auto;
            width: 100%;
            min-width: 0;
            padding: 5px 10px;
            border: 1px solid rgba(55,98,200,0.3);
            border-radius: 6px;
            font-size: 12px;
        }
        .map-toolbar-right {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
        }
        .map-legend {
            display: flex; align-items: center; gap: 14px;
            font-size: 12px; color: #555; padding: 6px 12px;
            background: rgba(255,255,255,0.7); border-radius: 8px;
            flex: 1 1 auto;
            flex-wrap: wrap;
        }
        .map-legend-item { display: flex; align-items: center; gap: 5px; }
        .map-legend-dot {
            width: 12px; height: 12px; border-radius: 50%;
            border: 2px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .map-fullscreen-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 34px;
            padding: 6px 12px;
            background: rgba(55,98,200,0.1);
            color: #3762c8;
            border: 1px solid rgba(55,98,200,0.3);
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
            cursor: pointer;
            transition: background 0.2s, color 0.2s, border-color 0.2s, transform 0.15s;
            white-space: nowrap;
            box-sizing: border-box;
        }
        .map-fullscreen-btn:hover { background: #3762c8; color: #fff; }

        /* Map Tools dropdown */
        .map-tools {
            position: relative;
            display: inline-block;
        }
        .map-tools-toggle {
            min-width: 96px;
        }
        .map-tools-toggle-count {
            display: none;
            min-width: 18px;
            height: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: #3762c8;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            line-height: 18px;
            text-align: center;
        }
        .map-tools-toggle.has-active .map-tools-toggle-count { display: inline-block; }
        .map-tools-menu {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            right: 0;
            z-index: 1300;
            width: min(300px, calc(100vw - 32px));
            padding: 8px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid rgba(55,98,200,0.14);
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.16);
        }
        .map-tools-menu.is-open { display: block; }
        .map-tools-heading {
            padding: 6px 10px 4px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #9ca3af;
        }
        .map-tools-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            width: 100%;
            padding: 10px 12px;
            margin: 2px 0;
            border: 1px solid transparent;
            border-radius: 10px;
            background: transparent;
            color: #374151;
            font-size: 13px;
            font-weight: 500;
            font-family: inherit;
            text-align: left;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        .map-tools-item:hover {
            background: rgba(55,98,200,0.06);
            color: #1e3c72;
        }
        .map-tools-item-main {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .map-tools-item-main i {
            width: 18px;
            text-align: center;
            color: #3762c8;
        }
        .map-tools-item-state {
            flex: 0 0 auto;
            min-width: 36px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-align: center;
            background: rgba(107,114,128,0.14);
            color: #6b7280;
        }
        .map-tools-item.is-on {
            background: rgba(55,98,200,0.08);
            border-color: rgba(55,98,200,0.18);
            color: #1e3c72;
        }
        .map-tools-item.is-on .map-tools-item-state {
            background: rgba(16,185,129,0.16);
            color: #047857;
        }
        .map-tools-item.is-off .map-tools-item-state {
            background: rgba(107,114,128,0.14);
            color: #6b7280;
        }
        .map-tools-divider {
            height: 1px;
            margin: 8px 4px;
            background: rgba(15, 23, 42, 0.08);
        }
        .map-tools-item.map-tools-action {
            justify-content: center;
            margin-top: 2px;
            background: #3762c8;
            color: #fff;
            border-color: #3762c8;
            font-weight: 600;
        }
        .map-tools-item.map-tools-action:hover {
            background: #2f55b0;
            color: #fff;
        }
        .map-tools-item.map-tools-action i { color: #fff; }
        .map-tools-item.map-tools-action .map-tools-item-state { display: none; }
        .map-tools-item.is-loading {
            opacity: 0.75;
            pointer-events: none;
        }
        body.dark-mode .map-tools-menu {
            background: #1e2229;
            border-color: rgba(255,255,255,0.08);
            box-shadow: 0 12px 32px rgba(0,0,0,0.4);
        }
        body.dark-mode .map-tools-item { color: #e4e6ea; }
        body.dark-mode .map-tools-item:hover {
            background: rgba(55,98,200,0.16);
            color: #fff;
        }
        body.dark-mode .map-tools-item.is-on {
            background: rgba(55,98,200,0.2);
            border-color: rgba(96,165,250,0.25);
            color: #93c5fd;
        }
        body.dark-mode .map-tools-item-main i { color: #93c5fd; }
        body.dark-mode .map-tools-divider { background: rgba(255,255,255,0.08); }
        .incident-map-pin {
            color: #fff;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            border: 2px solid #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
        }
        .incident-map-pin.cat-accident {
            background: #dc2626;
            animation: accident-pin-pulse 1.8s ease-out infinite;
        }
        .incident-map-pin.cat-closed { background: #111827; }
        .incident-map-pin.cat-jam { background: #f59e0b; }
        .incident-map-pin.cat-works { background: #ca8a04; }
        .incident-map-pin.cat-other { background: #6b7280; }
        .transit-map-pin {
            color: #fff;
            border-radius: 50%;
            width: 26px;
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            border: 2px solid #fff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
        }
        .transit-map-pin.bus { background: #0284c7; }
        .transit-map-pin.rail { background: #475569; }
        .map-fullscreen-btn.is-loading {
            opacity: 0.85;
            cursor: wait;
            pointer-events: none;
        }
        .map-fullscreen-btn:disabled { cursor: wait; }

        .sync-layers-overlay {
            position: fixed;
            inset: 0;
            z-index: 10050;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(15, 23, 42, 0.55);
            padding: 20px;
        }
        .sync-layers-overlay.is-open { display: flex; }
        .sync-layers-modal {
            width: min(420px, 100%);
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.28);
            overflow: hidden;
        }
        .sync-layers-modal-header {
            padding: 18px 20px 12px;
            border-bottom: 1px solid #e5e7eb;
        }
        .sync-layers-modal-header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: 700;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sync-layers-modal-header p {
            margin: 8px 0 0;
            font-size: 12px;
            color: #6b7280;
            line-height: 1.4;
        }
        .sync-layers-modal-body {
            padding: 14px 20px 8px;
        }
        .sync-layers-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .sync-layers-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid #fdba74;
            background: #fff7ed;
            color: #c2410c;
            transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
        }
        .sync-layers-item.is-pending {
            border-color: #fdba74;
            background: #fff7ed;
            color: #c2410c;
        }
        .sync-layers-item.is-done {
            border-color: #86efac;
            background: #f0fdf4;
            color: #15803d;
        }
        .sync-layers-item.is-failed {
            border-color: #fca5a5;
            background: #fef2f2;
            color: #b91c1c;
        }
        .sync-layers-item-icon {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 13px;
            background: rgba(255, 255, 255, 0.7);
        }
        .sync-layers-item-meta {
            min-width: 0;
            flex: 1;
        }
        .sync-layers-item-label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.3;
        }
        .sync-layers-item-status {
            display: block;
            font-size: 11px;
            opacity: 0.9;
            margin-top: 2px;
        }
        .sync-layers-modal-footer {
            padding: 12px 20px 18px;
            display: none;
            justify-content: flex-end;
        }
        .sync-layers-modal-footer.is-visible { display: flex; }
        .sync-layers-close-btn {
            min-width: 110px;
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            background: #3762c8;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }
        .sync-layers-close-btn:hover { background: #1e3c72; }
        body.dark-mode .sync-layers-modal {
            background: var(--bg-card, #1f2937);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.55);
        }
        body.dark-mode .sync-layers-modal-header {
            border-bottom-color: var(--border-default, #374151);
        }
        body.dark-mode .sync-layers-modal-header h3 { color: var(--text-primary, #f3f4f6); }
        body.dark-mode .sync-layers-modal-header p { color: var(--text-secondary, #9ca3af); }

        @keyframes accident-pin-pulse {
            0% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0.55); }
            70% { box-shadow: 0 0 0 10px rgba(220, 38, 38, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 38, 38, 0); }
        }

        .reports-table-section {
            background: #f0f4fa;
            border-radius: 16px; padding: 20px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 25px;
        }
        .reports-table-section .table-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 16px; flex-wrap: wrap; gap: 10px;
        }
        .reports-table-section .table-header h3 {
            font-size: 18px; font-weight: 600; color: #1e3c72;
            display: flex; align-items: center; gap: 10px; margin: 0;
        }
        .reports-table-section .table-header a {
            font-size: 13px; color: #3762c8; text-decoration: none; font-weight: 500;
        }
        .reports-table-section .table-header a:hover { text-decoration: underline; }
        .reports-table-wrap { overflow-x: auto; }
        .reports-table-section table {
            width: 100%; border-collapse: collapse; font-size: 13px;
        }
        .reports-table-section th {
            background: rgba(55,98,200,0.08); padding: 10px 12px;
            text-align: left; font-weight: 600; color: #1e3c72;
            border-bottom: 2px solid rgba(55,98,200,0.15); white-space: nowrap;
        }
        .reports-table-section td {
            padding: 10px 12px; border-bottom: 1px solid rgba(55,98,200,0.08);
            color: #333;
        }
        .reports-table-section tr:hover td { background: rgba(55,98,200,0.03); }
        tr.focus-pulse {
            border-left: 4px solid #3762c8;
            background: #eef5ff;
            animation: recentFocusPulse 1s ease-in-out 4;
        }
        @keyframes recentFocusPulse {
            0%, 100% { border-left: 4px solid #3762c8; background: #eef5ff; }
            50%      { border-left: 4px solid #3762c8; background: #dbe9ff; }
        }
        .reports-table-section .badge {
            display: inline-block; padding: 2px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 600; text-transform: uppercase;
        }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-in-progress { background: #cce5ff; color: #004085; }
        .badge-approved { background: #10b981; color: #fff; }
        .badge-completed { background: #d4edda; color: #155724; }
        .badge-cancelled { background: #f8d7da; color: #721c24; }
        .badge-high, .badge-critical { background: #f8d7da; color: #721c24; }
        .badge-medium { background: #fff3cd; color: #856404; }
        .badge-low { background: #e2e3e5; color: #383d41; }
        .badge-source { background: #f8f9fa; color: #495057; border: 1px solid #dee2e6; }

        .assignment-badge { font-weight: 600; }
        .assignment-assigned { background: #d1fae5; color: #065f46; }
        .assignment-unassigned { background: #e2e3e5; color: #495057; }
        .category-badge {
            display: inline-block; padding: 2px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 600; white-space: nowrap;
        }
        .category-road { background: rgba(55,98,200,0.12); color: #1e3c72; }
        .category-transportation { background: rgba(14,165,233,0.16); color: #0369a1; }
        body.dark-mode .category-road { background: rgba(96,165,250,0.18); color: #93c5fd; }
        body.dark-mode .category-transportation { background: rgba(56,189,248,0.2); color: #7dd3fc; }
        .cimm-verify-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 12px;
            font-size: 11px; font-weight: 600; white-space: nowrap;
        }
        .cimm-verify-badge-verified { background: #d4edda; color: #155724; }
        .cimm-verify-badge-pending  { background: #fff3cd; color: #856404; }
        .cimm-verify-badge-none     { color: #9ca3af; }
        .table-action-btn {
            padding: 4px 10px; border-radius: 5px; border: none;
            font-size: 11px; cursor: pointer; transition: all 0.2s;
        }
        .table-action-btn.view-map {
            background: rgba(55,98,200,0.12); color: #3762c8;
        }
        .table-action-btn.view-map:hover { background: #3762c8; color: #fff; }

        /* View Details Modal (table-action-btn viewport — mirrors report_management rm modal) */
        .rm-modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            overflow-y: auto;
        }

        .rm-modal-overlay.active {
            display: flex;
        }

        .rm-modal-content {
            background: #f0f4fa;
            border-radius: 16px;
            max-width: 860px;
            width: 100%;
            max-height: calc(100vh - 40px);
            position: relative;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            margin: auto;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            border: 1px solid #c8d0e0;
        }

        .rm-modal-header {
            background: white;
            border-radius: 16px 16px 0 0;
            padding: 24px 28px 18px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.15);
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 1;
        }

        .rm-modal-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }

        .rm-modal-title-area {
            flex: 1;
            min-width: 0;
        }

        .rm-modal-report-id {
            font-size: 13px;
            color: #3762c8;
            font-weight: 600;
            letter-spacing: 0.3px;
            margin-bottom: 4px;
        }

        .rm-modal-title {
            font-size: 22px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0 0 10px 0;
            line-height: 1.3;
        }

        .rm-modal-badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .rm-modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: #666;
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
            flex-shrink: 0;
            margin-left: 15px;
        }

        .rm-modal-close:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .rm-modal-body {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding: 24px 28px;
        }

        .rm-modal-body::-webkit-scrollbar {
            width: 8px;
        }

        .rm-modal-body::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.08);
            border-radius: 4px;
        }

        .rm-modal-body::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.2);
            border-radius: 4px;
        }

        .rm-modal-body::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.35);
        }

        .rm-modal-section {
            background: white;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 16px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .rm-modal-section-title {
            font-size: 14px;
            font-weight: 700;
            color: #1e3c72;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(55, 98, 200, 0.1);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .rm-modal-section-title i {
            color: #3762c8;
            font-size: 15px;
        }

        .rm-view-map-btn {
            margin-left: auto;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            background: rgba(249, 115, 22, 0.1);
            color: #f97316;
            border: 1px solid rgba(249, 115, 22, 0.3);
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
        }

        .rm-view-map-btn:hover {
            background: rgba(249, 115, 22, 0.2);
        }

        .road-map-container {
            display: none;
            margin-top: 12px;
            height: 320px;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid rgba(249, 115, 22, 0.15);
        }

        .road-map-container.road-map-visible {
            display: block;
        }

        .rm-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .rm-info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 6px 0;
        }

        .rm-info-icon {
            width: 28px;
            height: 28px;
            background: rgba(55, 98, 200, 0.1);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #3762c8;
            font-size: 13px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .rm-info-label {
            font-size: 11px;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-bottom: 2px;
        }

        .rm-info-value {
            font-size: 14px;
            color: #1f2937;
            font-weight: 500;
            line-height: 1.4;
            word-break: break-word;
        }

        .rm-info-value-full {
            grid-column: 1 / -1;
        }

        .rm-description-text {
            font-size: 14px;
            color: #374151;
            line-height: 1.7;
            padding: 8px 0;
            white-space: pre-wrap;
        }

        .rm-modal-footer {
            background: white;
            border-radius: 0 0 16px 16px;
            padding: 16px 28px;
            border-top: 1px solid rgba(55, 98, 200, 0.1);
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .rm-modal-btn-transparency-approve,
        .rm-modal-btn-transparency-reject {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            color: #fff;
            cursor: pointer;
            transition: filter 0.2s;
        }

        .rm-modal-btn-transparency-approve {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .rm-modal-btn-transparency-reject {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .rm-modal-btn-transparency-approve:hover,
        .rm-modal-btn-transparency-reject:hover {
            filter: brightness(1.06);
        }

        .rm-modal-btn-transparency-approve:disabled,
        .rm-modal-btn-transparency-reject:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Completed Projects table, System Admin only: marks a project whose
           Transparency Upload Request is still waiting for a decision. Pinned to
           the top-left corner of the row's first cell, so the table keeps its
           existing columns and the cell just reserves room on its left. */
        .reports-table-section .report-table-row td:first-child { position: relative; }

        .reports-table-section .report-table-row.transparency-flagged td:first-child,
        .reports-table-section .report-table-row.no-update-flagged td:first-child {
            padding-left: 46px;
        }

        .transparency-await-icon {
            position: absolute;
            top: 4px;
            left: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fbbf24, #f97316);
            color: #fff;
            font-size: 14px;
            box-shadow: 0 3px 8px rgba(249, 115, 22, 0.45);
            transform-origin: 70% 70%;
            animation: transparencyHornRing 2.6s ease-in-out infinite;
        }

        /* Soft halo that keeps pulsing so the flag is hard to scroll past. */
        .transparency-await-icon::before {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            border: 2px solid rgba(249, 115, 22, 0.55);
            animation: transparencyHornPulse 2.6s ease-out infinite;
        }

        .transparency-await-icon i { pointer-events: none; }

        @keyframes transparencyHornRing {
            0%, 62%, 100% { transform: rotate(0deg); }
            68% { transform: rotate(-12deg); }
            74% { transform: rotate(10deg); }
            80% { transform: rotate(-7deg); }
            86% { transform: rotate(5deg); }
        }

        @keyframes transparencyHornPulse {
            0% { transform: scale(0.85); opacity: 0.85; }
            70% { transform: scale(1.25); opacity: 0; }
            100% { transform: scale(1.25); opacity: 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            .transparency-await-icon,
            .transparency-await-icon::before { animation: none; }
        }

        body.dark-mode .transparency-await-icon {
            box-shadow: 0 3px 10px rgba(249, 115, 22, 0.55);
        }

        /* Monitoring table: 10+ days without a progress update. Same corner as
           the transparency horn, on a different colour so the two never read as
           the same signal. */
        .no-update-flag {
            position: absolute;
            top: 4px;
            left: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fb7185, #e11d48);
            color: #fff;
            font-size: 13px;
            box-shadow: 0 3px 8px rgba(225, 29, 72, 0.45);
        }

        .no-update-flag::before {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            border: 2px solid rgba(225, 29, 72, 0.5);
            animation: noUpdateFlagPulse 2.6s ease-out infinite;
        }

        .no-update-flag i { pointer-events: none; }

        @keyframes noUpdateFlagPulse {
            0% { transform: scale(0.85); opacity: 0.85; }
            70% { transform: scale(1.25); opacity: 0; }
            100% { transform: scale(1.25); opacity: 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            .no-update-flag::before { animation: none; }
        }

        body.dark-mode .no-update-flag {
            box-shadow: 0 3px 10px rgba(251, 113, 133, 0.55);
        }

        .rm-transparency-panel {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 10px;
            border-left: 3px solid #3762c8;
            background: rgba(55, 98, 200, 0.08);
            font-size: 13px;
        }

        .rm-transparency-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 600;
        }

        .rm-transparency-actions {
            display: none;
            gap: 8px;
            margin-left: auto;
        }

        .rm-transparency-panel .rm-modal-btn-transparency-approve,
        .rm-transparency-panel .rm-modal-btn-transparency-reject {
            padding: 8px 16px;
            font-size: 13px;
        }

        .rm-modal-btn-close {
            padding: 10px 24px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.2s;
        }

        .rm-modal-btn-close:hover {
            background: rgba(55, 98, 200, 0.2);
        }

        @media (max-width: 640px) {
            .rm-info-grid {
                grid-template-columns: 1fr;
            }
            .rm-modal-header {
                padding: 18px 16px;
            }
            .rm-modal-body {
                padding: 16px;
            }
            .rm-modal-content {
                max-width: 100%;
                border-radius: 0;
            }
            .rm-modal-overlay {
                padding: 0;
            }
        }

        /* View Details Modal Dark Mode */
        body.dark-mode .rm-modal-content {
            background: #1a1d24 !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-header {
            background: #1a1d24 !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-title {
            color: #60a5fa !important;
        }
        body.dark-mode .rm-modal-report-id {
            color: #60a5fa !important;
        }
        body.dark-mode .rm-modal-close {
            color: #9ca3af !important;
        }
        body.dark-mode .rm-modal-section {
            background: #22262e !important;
            border-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-section-title {
            color: #60a5fa !important;
            border-bottom-color: #2d323b !important;
        }
        body.dark-mode .rm-info-icon {
            background: rgba(55,98,200,0.15) !important;
        }
        body.dark-mode .rm-info-label {
            color: #9ca3af !important;
        }
        body.dark-mode .rm-info-value {
            color: #e4e6ea !important;
        }
        body.dark-mode .rm-description-text {
            color: #c0c8d8 !important;
        }
        body.dark-mode .rm-modal-footer {
            background: #1a1d24 !important;
            border-top-color: #2d323b !important;
        }
        body.dark-mode .rm-modal-btn-close {
            background: rgba(55,98,200,0.15) !important;
            color: #60a5fa !important;
        }
        body.dark-mode .rm-modal-body::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05) !important;
        }
        body.dark-mode .rm-modal-body::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.15) !important;
        }

        .table-header-right {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
            font-size: 13px;
            min-width: 130px;
        }

        body.dark-mode .filter-select {
            background: #2d323b;
            border-color: rgba(255,255,255,0.12);
            color: #e4e6ea;
        }

        .btn-secondary-custom {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
            font-size: 13px;
            cursor: pointer;
            color: #64748b;
            transition: all 0.2s;
        }

        .btn-secondary-custom:hover {
            background: #f0f4fa;
            border-color: #3762c8;
            color: #3762c8;
        }

        .btn-your-reports {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 6px 14px;
            min-height: 34px;
            border: 1px solid #3762c8;
            border-radius: 8px;
            background: #3762c8;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.2;
            cursor: pointer;
            white-space: nowrap;
            box-sizing: border-box;
            transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .btn-your-reports:hover {
            background: #1e3c72;
            border-color: #1e3c72;
            color: #fff;
        }
        .btn-your-reports i { pointer-events: none; }
        body.dark-mode .btn-your-reports {
            background: #3762c8;
            border-color: #3762c8;
            color: #fff;
        }
        body.dark-mode .btn-your-reports:hover {
            background: #60a5fa;
            border-color: #60a5fa;
            color: #0f172a;
        }

        body.dark-mode .btn-secondary-custom {
            background: #2d323b;
            border-color: rgba(255,255,255,0.12);
            color: #9ca3af;
        }
        body.dark-mode .btn-secondary-custom:hover {
            border-color: #60a5fa;
            color: #60a5fa;
        }

        .road-search {
            padding: 6px 12px; border: 1px solid rgba(55,98,200,0.3);
            border-radius: 8px; font-size: 13px; width: 200px;
        }

        .map-fullscreen-active #map { height: 70vh; }
        .map-fullscreen-active .monitoring-layout { grid-template-columns: 1fr; }
        .map-fullscreen-active .sidebar-section { display: none; }

        body.dark-mode .stat-card { background: #1e2229; border-color: rgba(255,255,255,0.08); }
        body.dark-mode .stat-card .stat-number { color: #e4e6ea; }
        body.dark-mode .stat-card .stat-label { color: #9ca3af; }
        body.dark-mode .map-legend { background: rgba(30,34,41,0.85); color: #9ca3af; }

        #gis-location-info .gis-field-tag {
            display:inline-block;background:rgba(55,98,200,0.1);color:#1e3c72;
            padding:2px 8px;border-radius:4px;font-size:11px;font-weight:500;
            margin-right:6px;margin-bottom:3px;
        }
        #gis-location-info .gis-field-tag .gis-tag-label {
            color:#666;font-weight:400;
        }
        #gis-location-warning {
            display:none;background:rgba(220,53,69,0.06);
            border:1px solid rgba(220,53,69,0.25);border-radius:10px;
            padding:12px 14px;margin-bottom:14px;
        }
        #gis-location-warning i { color:#dc3545;margin-right:8px; }
        #gis-location-warning span { font-size:12px;color:#721c24; }

        .tomtom-panel {
            background:#f0f4fa;border-radius:12px;padding:16px;margin-top:12px;
            border:1px solid rgba(55,98,200,0.2);display:none;
        }
        .tomtom-panel h5 { color:#1e3c72;font-size:15px;margin-bottom:12px; }
        .tomtom-panel label { display:block;font-size:12px;font-weight:500;color:#333;margin-top:8px;margin-bottom:3px; }
        .tomtom-panel input,.tomtom-panel select { width:100%;padding:6px 10px;border:1px solid rgba(55,98,200,0.3);border-radius:6px;font-size:13px; }
        .tomtom-panel .btn-sm { padding:5px 12px;font-size:12px;border-radius:6px;margin-top:8px; }
        body.dark-mode .tomtom-panel { background:#1e2229;border-color:rgba(255,255,255,0.08); }
        body.dark-mode .tomtom-panel h5 { color:#e4e6ea; }
        body.dark-mode .tomtom-panel label { color:#9ca3af; }
        body.dark-mode .tomtom-panel input,body.dark-mode .tomtom-panel select { background:#1a1d23;color:#e4e6ea;border-color:#2d323b; }
        .pt-route-list {
            max-height: 280px;
            overflow-y: auto;
            margin-top: 8px;
            border: 1px solid rgba(55,98,200,0.15);
            border-radius: 8px;
            background: #fff;
        }
        .pt-route-item {
            display: block;
            width: 100%;
            text-align: left;
            padding: 10px 12px;
            border: 0;
            border-bottom: 1px solid rgba(0,0,0,0.06);
            background: transparent;
            cursor: pointer;
            font-size: 12px;
            color: #1e3c72;
        }
        .pt-route-item:last-child { border-bottom: 0; }
        .pt-route-item:hover { background: rgba(55,98,200,0.08); }
        .pt-route-item.is-selected {
            background: rgba(220,38,38,0.12);
            box-shadow: inset 3px 0 0 #dc2626;
        }
        .pt-route-item .pt-route-name { font-weight: 600; display: block; }
        .pt-route-item .pt-route-meta { color: #6b7280; font-size: 11px; margin-top: 2px; display: block; }
        .pt-route-badge {
            display: inline-block;
            margin-left: 6px;
            padding: 1px 6px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 600;
            background: rgba(234,88,12,0.12);
            color: #ea580c;
            vertical-align: middle;
        }
        .pt-route-empty, .pt-route-status {
            padding: 14px 12px;
            font-size: 12px;
            color: #6b7280;
            text-align: center;
        }
        body.dark-mode .pt-route-list { background: #1a1d23; border-color: #2d323b; }
        body.dark-mode .pt-route-item { color: #e4e6ea; border-bottom-color: rgba(255,255,255,0.06); }
        body.dark-mode .pt-route-item:hover { background: rgba(255,255,255,0.04); }
        body.dark-mode .pt-route-item.is-selected { background: rgba(220,38,38,0.18); }
        body.dark-mode .pt-route-item .pt-route-meta,
        body.dark-mode .pt-route-empty,
        body.dark-mode .pt-route-status { color: #9ca3af; }

        .route-info-box {
            margin-top:8px;padding:10px;background:rgba(55,98,200,0.06);border-radius:8px;font-size:12px;color:#333;
        }
        body.dark-mode .route-info-box { background:rgba(55,98,200,0.1);color:#d1d5db; }

        .search-results-dropdown {
            position:absolute;top:100%;left:0;right:0;background:#fff;border-radius:0 0 8px 8px;
            box-shadow:0 8px 24px rgba(0,0,0,0.15);z-index:1000;max-height:250px;overflow-y:auto;display:none;
        }
        .search-result-item {
            padding:8px 12px;cursor:pointer;font-size:12px;border-bottom:1px solid rgba(0,0,0,0.05);transition:background 0.2s;
        }
        .search-result-item:hover { background:rgba(55,98,200,0.08); }
        .search-result-item small { color:#666;display:block; }
        body.dark-mode .search-results-dropdown { background:#22262e; }
        body.dark-mode .search-result-item { color:#e4e6ea;border-color:rgba(255,255,255,0.05); }
        body.dark-mode .search-result-item small { color:#9ca3af; }
        body.dark-mode .reports-table-section { background: #1e2229; border-color: rgba(255,255,255,0.08); }
        body.dark-mode .reports-table-section th { background: rgba(30,34,41,0.8); color: #e4e6ea; }
        body.dark-mode .reports-table-section td { color: #d1d5db; border-bottom-color: rgba(255,255,255,0.06); }
        body.dark-mode .reports-table-section tr:hover td { background: rgba(255,255,255,0.03); }
        body.dark-mode .reports-table-section .table-header h3 { color: #e4e6ea; }
        body.dark-mode .road-search { background: #1a1d23; color: #e4e6ea; border-color: #2d323b; }

        @media (max-width: 1200px) {
            .monitoring-layout {
                grid-template-columns: 1fr;
            }
            
            .sidebar-section {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .sidebar-section {
                grid-template-columns: 1fr;
            }
            
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 3% auto;
            padding: 0;
            border-radius: 16px;
            width: 92%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            padding: 20px 25px;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            margin: 0;
        }

        .close {
            color: white;
            font-size: 24px;
            cursor: pointer;
            background: none;
            border: none;
        }

        .close:hover { opacity: 0.7; }

        .modal-body {
            padding: 25px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        #statusConfirmModal.status-confirm-modal {
            z-index: 10050;
        }

        #statusConfirmModal .status-confirm-content {
            max-width: 440px;
            margin: 18vh auto;
        }

        #statusConfirmModal .status-confirm-message {
            margin: 0;
            font-size: 15px;
            line-height: 1.5;
            color: #334155;
        }

        #statusConfirmModal .status-confirm-footer {
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }

        body.dark-mode #statusConfirmModal .status-confirm-message {
            color: var(--text-primary, #e4e6ea);
        }

        body.dark-mode #statusConfirmModal .modal-footer {
            border-top-color: rgba(255, 255, 255, 0.08);
        }

        /* Progress Updates modal: the timeline can run long and its footer
           carries several rows of actions, so the dialog is capped to the
           viewport and only the timeline scrolls. The header and the footer
           buttons stay in view. */
        #updatesModal {
            overflow-y: auto;
        }

        #updatesModal .modal-content {
            display: flex;
            flex-direction: column;
            margin: 4vh auto;
            max-height: 92vh;
        }

        #updatesModal .modal-header,
        #updatesModal .modal-footer {
            flex: 0 0 auto;
        }

        #updatesModal .modal-body {
            flex: 1 1 auto;
            min-height: 120px;
            max-height: none;
            overflow-y: auto;
        }

        /* Let the action rows wrap instead of pushing Export or the
           transparency decisions out of reach on narrow screens. */
        #updatesModal #actionButtons,
        #updatesModal #exportButtons,
        #updatesModal #actionButtons > div {
            flex-wrap: wrap;
            gap: 8px;
        }

        @media (max-width: 640px) {
            #updatesModal .modal-content {
                margin: 2vh auto;
                max-height: 96vh;
            }

            #updatesModal .modal-body {
                padding: 16px;
            }

            #updatesModal .modal-footer {
                padding: 14px 16px;
            }
        }

        .btn-secondary-custom {
            padding: 10px 20px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }

        .btn-secondary-custom:hover { background: #5a6268; }

        body.dark-mode .modal-content {
            background: #22262e;
        }

        body.dark-mode .modal-header {
            border-color: #2d323b;
        }

        body.dark-mode .modal-footer {
            border-color: #2d323b;
        }

        body.dark-mode .modal-title {
            color: #e4e6ea;
        }

        /* Add/Edit Update Modal form styles */
        #addUpdateModal .form-group { margin-bottom: 16px; }
        #addUpdateModal .completion-slider-track {
            background: linear-gradient(#d1d5db, #d1d5db) center / 100% 4px no-repeat !important;
        }
        #addUpdateModal .completion-slider-fill {
            height: 4px !important;
            background: #3762c8 !important;
        }
        #addUpdateModal .completion-slider-handle {
            width: 18px !important;
            height: 18px !important;
            border-radius: 50% !important;
            background: #3762c8 !important;
            border: 2px solid #fff !important;
            display: block !important;
            padding: 0 !important;
        }
        body.dark-mode #addUpdateModal .completion-slider-track {
            background: linear-gradient(rgba(255,255,255,0.22), rgba(255,255,255,0.22)) center / 100% 4px no-repeat !important;
        }
        body.dark-mode #addUpdateModal .completion-slider-fill,
        body.dark-mode #addUpdateModal .completion-slider-handle {
            background: #60a5fa !important;
        }
        #addUpdateModal .form-label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #333;
            margin-bottom: 5px;
        }
        #addUpdateModal .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid rgba(55,98,200,0.15);
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.2s;
            background: white;
            color: #333;
            box-sizing: border-box;
        }
        #addUpdateModal .form-control:focus {
            border-color: #3762c8;
            outline: none;
            box-shadow: 0 0 0 3px rgba(55,98,200,0.1);
        }
        #addUpdateModal textarea.form-control { resize: vertical; }
        #addUpdateModal .file-previews {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }
        #addUpdateModal .file-preview-item {
            width: 80px;
            height: 80px;
            border-radius: 6px;
            overflow: hidden;
            border: 1px solid #ddd;
            position: relative;
            background: #f8f9fa;
            flex-shrink: 0;
        }
        #addUpdateModal .file-preview-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        #addUpdateModal .file-preview-item .remove-preview {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 20px;
            height: 20px;
            background: rgba(220,53,69,0.9);
            color: #fff;
            border: none;
            border-radius: 50%;
            font-size: 14px;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        #addUpdateModal .file-preview-item .remove-preview:hover { background: #dc3545; }
        body.dark-mode #addUpdateModal .form-label { color: #e4e6ea; }
        body.dark-mode #addUpdateModal .form-control {
            background: #1a1d23;
            color: #e4e6ea;
            border-color: #3a3f4a;
        }
        body.dark-mode #addUpdateModal .file-preview-item { background: #2a2e36; border-color: #3a3f4a; }
        /* Dark-mode readable status badges (system_admin, trans_ops_supervisor and trans_monitoring_officer only) */
        <?php if ($is_system_admin || $is_trans_ops_supervisor || $is_transport_monitoring_officer): ?>
        body.dark-mode .badge-pending,
        body.dark-mode .badge-medium,
        body.dark-mode .cimm-verify-badge-pending { background: rgba(133, 100, 4, 0.25); color: #fde68a; }
        body.dark-mode .badge-in-progress { background: rgba(0, 64, 133, 0.35); color: #93c5fd; }
        body.dark-mode .badge-approved,
        body.dark-mode .badge-completed,
        body.dark-mode .cimm-verify-badge-verified { background: rgba(21, 87, 36, 0.30); color: #86efac; }
        body.dark-mode .badge-cancelled,
        body.dark-mode .badge-high,
        body.dark-mode .badge-critical { background: rgba(114, 28, 36, 0.30); color: #fca5a5; }
        body.dark-mode .badge-low,
        body.dark-mode .badge-source,
        body.dark-mode .assignment-unassigned { background: rgba(56, 61, 65, 0.30); color: #cbd5e1; }
        body.dark-mode .badge-source { border-color: #2d323b; }
        body.dark-mode .assignment-assigned { background: rgba(6, 95, 70, 0.30); color: #6ee7b7; }

        /* Chart-style pop-up label on stat cards (system_admin only) */
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

        /* db-badge — matches lgu_staff_dashboard.php's .db-badge / .db-st-* /
           .db-pr-* badges. Rendered in Recent Submissions for the Road
           Operations Supervisor and Road Monitoring Officer only. */
        <?php if ($is_road_supervisor || $is_road_monitoring_officer): ?>
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
        .db-pr-high      { background: #fee2e2; color: #dc2626; }
        .db-pr-medium    { background: #ffedd5; color: #c2410c; }
        .db-pr-low       { background: #d1fae5; color: #059669; }

        body.dark-mode .db-st-pending   { background: rgba(180, 83, 9, 0.22); color: #fcd34d; }
        body.dark-mode .db-st-active,
        body.dark-mode .db-st-assigned  { background: rgba(30, 64, 175, 0.35); color: #93c5fd; }
        body.dark-mode .db-st-progress  { background: rgba(194, 65, 12, 0.25); color: #fdba74; }
        body.dark-mode .db-st-completed,
        body.dark-mode .db-st-approved  { background: rgba(4, 120, 87, 0.28); color: #6ee7b7; }
        body.dark-mode .db-st-cancelled,
        body.dark-mode .db-st-rejected  { background: rgba(185, 28, 28, 0.28); color: #fca5a5; }
        body.dark-mode .db-pr-high      { background: rgba(220, 38, 38, 0.28); color: #fca5a5; }
        body.dark-mode .db-pr-medium    { background: rgba(194, 65, 12, 0.25); color: #fdba74; }
        body.dark-mode .db-pr-low       { background: rgba(5, 150, 105, 0.28); color: #6ee7b7; }

        /* Assignment / CIMM badges that are not part of the db-badge set but
           still appear in the Recent Submissions table — Road supervisor and
           Road Monitoring Officer only. */
        body.dark-mode .assignment-assigned    { background: rgba(30, 64, 175, 0.35); color: #93c5fd; }
        body.dark-mode .assignment-unassigned  { background: rgba(51, 65, 85, 0.30); color: #cbd5e1; }
        body.dark-mode .cimm-verify-badge-verified { background: rgba(4, 120, 87, 0.28); color: #6ee7b7; }
        body.dark-mode .cimm-verify-badge-pending  { background: rgba(180, 83, 9, 0.22); color: #fcd34d; }
        body.dark-mode .cimm-verify-badge-none     { color: #9ca3af; }
        <?php endif; ?>

        /* Dark-mode compatible GIS map district tooltip on hover
           (trans_ops_supervisor only) */
        <?php if ($is_trans_ops_supervisor): ?>
        body.dark-mode .district-tooltip {
            background: var(--bg-card) !important;
            border-color: var(--border-default) !important;
            color: var(--text-primary) !important;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.45) !important;
        }
        body.dark-mode .district-tooltip.leaflet-tooltip-top:before { border-top-color: var(--bg-card) !important; }
        body.dark-mode .district-tooltip.leaflet-tooltip-bottom:before { border-bottom-color: var(--bg-card) !important; }
        body.dark-mode .district-tooltip.leaflet-tooltip-left:before { border-left-color: var(--bg-card) !important; }
        body.dark-mode .district-tooltip.leaflet-tooltip-right:before { border-right-color: var(--bg-card) !important; }
        <?php endif; ?>

        /* ── Monitoring dashboard refresh (theme-aware, UI only) ── */
        body { background: #f5f3ee; color: var(--text-primary); }
        body.dark-mode { background: var(--bg-page); }
        .mon-dash { padding: 24px 28px; max-width: 100%; overflow-x: hidden; }

        .mon-dash .monitoring-header {
            background: #f4f7fb;
            border: 1px solid #d5dce8;
            border-radius: 14px;
            padding: 20px 24px;
            margin-bottom: 22px;
            box-shadow: var(--shadow-card);
        }
        .mon-dash .header-content { margin-bottom: 0; }
        .mon-dash .header-title h1 {
            color: var(--text-primary);
            font-size: 22px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 4px;
        }
        .mon-dash .header-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--color-primary-bg);
            color: var(--color-primary);
            font-size: 16px;
            box-shadow: none;
            flex-shrink: 0;
        }
        .mon-dash .header-title p { color: var(--text-secondary); font-size: 13px; }

        .mon-dash .stat-card {
            background: #f4f7fb;
            border: 1px solid #d5dce8;
            border-radius: 14px;
            padding: 18px 18px 16px;
            box-shadow: var(--shadow-card);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .mon-dash .stat-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
        }
        .mon-dash .stat-card.accent-blue::before { background: #1e3c72; }
        .mon-dash .stat-card.accent-amber::before { background: var(--color-warning); }
        .mon-dash .stat-card.accent-rose::before { background: var(--color-danger); }
        .mon-dash .stat-card.accent-emerald::before { background: var(--color-success); }
        .mon-dash .stat-card.accent-blue,
        .mon-dash .stat-card.accent-amber,
        .mon-dash .stat-card.accent-rose,
        .mon-dash .stat-card.accent-emerald { background: #f4f7fb; }
        .mon-dash .stat-card .stat-icon {
            width: 40px; height: 40px; border-radius: 10px; font-size: 15px; margin-bottom: 10px;
        }
        .mon-dash .stat-card .stat-icon.blue { background: var(--color-primary-bg); color: var(--color-primary); }
        .mon-dash .stat-card .stat-icon.orange { background: var(--color-warning-bg); color: var(--color-warning); }
        .mon-dash .stat-card .stat-icon.red { background: var(--color-danger-bg); color: var(--color-danger); }
        .mon-dash .stat-card .stat-icon.green { background: var(--color-success-bg); color: var(--color-success); }
        .mon-dash .stat-card .stat-number { color: var(--text-primary); letter-spacing: -0.03em; }
        .mon-dash .stat-card .stat-label { color: var(--text-secondary); font-weight: 600; }

        .mon-dash .map-section,
        .mon-dash .info-card,
        .mon-dash .reports-table-section,
        .mon-dash .report-form-panel {
            background: #f4f7fb;
            border: 1px solid #d5dce8;
            box-shadow: var(--shadow-card);
            border-radius: 14px;
        }
        .mon-dash .map-title,
        .mon-dash .info-card-title,
        .mon-dash .reports-table-section .table-header h3,
        .mon-dash .report-form-panel h4 {
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .mon-dash .title-icon {
            width: 30px; height: 30px; border-radius: 9px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0;
            background: var(--color-primary-bg); color: var(--color-primary);
        }
        .mon-dash .info-card .title-icon { background: var(--color-danger-bg); color: var(--color-danger); }
        .mon-dash .map-hint { color: var(--text-secondary); }
        .mon-dash .filter-btn {
            background: var(--color-primary-bg);
            color: var(--color-primary);
            border: 1px solid transparent;
            border-radius: 999px;
            font-weight: 600;
        }
        .mon-dash .filter-btn:hover,
        .mon-dash .filter-btn.active {
            background: var(--color-primary);
            color: #fff;
            border-color: var(--color-primary);
        }
        .mon-dash .map-legend {
            background: var(--bg-hover);
            color: var(--text-secondary);
            border-radius: 10px;
        }
        .mon-dash .map-fullscreen-btn {
            background: var(--color-primary-bg);
            color: var(--color-primary);
            border-color: transparent;
            border-radius: 8px;
            font-weight: 600;
            transition: transform 0.15s ease, background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
        }
        .mon-dash .map-fullscreen-btn:hover { transform: translateY(-1px); background: var(--color-primary); color: #fff; }
        .mon-dash .map-tools-menu {
            background: var(--bg-card, #fff);
            border-color: var(--border-light, rgba(55,98,200,0.14));
        }
        .mon-dash .map-tools-item { color: var(--text-primary, #374151); }
        .mon-dash .map-search-box input {
            background: var(--bg-input);
            color: var(--text-primary);
            border: 1px solid var(--border-input);
            border-radius: 8px;
        }

        .mon-dash .reports-table-section { padding: 0; overflow: hidden; }
        .mon-dash .reports-table-section .table-header {
            margin: 0;
            padding: 16px 18px;
            background: var(--bg-hover);
            border-bottom: 1px solid var(--border-light);
        }
        .mon-dash .filter-select,
        .mon-dash .road-search {
            background: var(--bg-input);
            color: var(--text-primary);
            border: 1px solid var(--border-input);
            border-radius: 10px;
            padding: 8px 12px;
        }
        .mon-dash .filter-select:focus,
        .mon-dash .road-search:focus {
            outline: none;
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px var(--color-primary-bg);
        }
        .mon-dash .reports-table-wrap { padding: 0 6px 6px; }
        .mon-dash .reports-table-section th {
            background: transparent;
            color: var(--text-secondary);
            font-size: 11px;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border-default);
        }
        .mon-dash .reports-table-section td {
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-light);
            padding: 12px;
            vertical-align: middle;
        }
        .mon-dash .reports-table-section tbody tr { transition: background 0.15s ease; }
        .mon-dash .reports-table-section tbody tr:hover td { background: var(--bg-hover); }
        .mon-dash .empty-cell {
            text-align: center !important;
            padding: 36px 16px !important;
            color: var(--text-muted) !important;
        }
        .mon-dash .muted-date { color: var(--text-secondary) !important; font-size: 12px; }
        .mon-dash .mono-id { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }

        .mon-dash .reports-table-section .badge {
            padding: 4px 10px;
            border-radius: 999px;
            font-weight: 600;
            border: none;
        }
        .mon-dash .badge-pending,
        .mon-dash .db-st-pending { background: #fef3c7; color: #b45309; }
        .mon-dash .badge-in-progress,
        .mon-dash .db-st-progress { background: #fae8ff; color: #a21caf; }
        .mon-dash .badge-approved,
        .mon-dash .db-st-approved { background: #fed7aa; color: #c2410c; }
        .mon-dash .badge-completed,
        .mon-dash .db-st-completed { background: #fce7f3; color: #9d174d; }
        .mon-dash .badge-cancelled,
        .mon-dash .badge-rejected,
        .mon-dash .db-st-cancelled,
        .mon-dash .db-st-rejected { background: #fee2e2; color: #b91c1c; }
        .mon-dash .badge-assigned,
        .mon-dash .db-st-assigned,
        .mon-dash .db-st-active { background: #ede9fe; color: #6d28d9; }
        .mon-dash .badge-high,
        .mon-dash .badge-critical { background: var(--priority-high-bg); color: var(--priority-high-text); }
        .mon-dash .badge-medium { background: var(--priority-medium-bg); color: var(--priority-medium-text); }
        .mon-dash .badge-low { background: var(--priority-low-bg); color: var(--priority-low-text); }
        .mon-dash .badge-source { background: #f3e8ff; color: #6b21a8; }
        .mon-dash .badge-source-lgu { background: #ede9fe; color: #6d28d9; }
        .mon-dash .badge-source-citizen { background: #fce7f3; color: #be185d; }
        .mon-dash .badge-source-cimm { background: #ffedd5; color: #c2410c; }
        .mon-dash .badge-source-infrastructure { background: #fde8d0; color: #9a3412; }
        .mon-dash .assignment-assigned { background: #ede9fe; color: #6d28d9; }
        .mon-dash .assignment-unassigned { background: #fef3c7; color: #b45309; }

        .mon-dash .action-cell {
            white-space: nowrap;
        }
        .mon-dash .action-cell .table-action-btn { margin: 2px 4px 2px 0; }
        .mon-dash .table-action-btn {
            padding: 6px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: var(--color-primary-bg);
            color: var(--color-primary);
            transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease, background 0.15s ease, color 0.15s ease;
        }
        .mon-dash .table-action-btn i { pointer-events: none; }
        .mon-dash .table-action-btn:hover { transform: translateY(-1px); }
        .mon-dash .table-action-btn.btn-view { background: var(--color-primary-bg); color: var(--color-primary); }
        .mon-dash .table-action-btn.btn-view:hover { background: var(--color-primary); color: #fff; }
        .mon-dash .table-action-btn.view-map { background: var(--color-info-bg); color: var(--color-info); }
        .mon-dash .table-action-btn.view-map:hover { background: var(--color-info); color: #fff; }
        .mon-dash .table-action-btn.btn-updates {
            background: linear-gradient(135deg, var(--color-success-light), var(--color-success-dark));
            color: #fff;
        }
        .mon-dash .table-action-btn.btn-archive {
            background: linear-gradient(135deg, #64748b, #475569);
            color: #fff;
        }

        .btn-action,
        .btn-success-custom,
        .btn-danger-custom {
            border-radius: 10px;
            font-weight: 600;
        }
        .btn-action i,
        .btn-success-custom i,
        .btn-danger-custom i,
        .btn-secondary-custom i { pointer-events: none; }
        .btn-action:hover,
        .btn-success-custom:hover,
        .btn-danger-custom:hover { transform: translateY(-1px); }

        .mon-dash .info-card .alert-item {
            border-radius: 10px;
            background: var(--color-danger-bg);
            border-left-color: var(--color-danger);
        }
        .mon-dash .info-card .alert-item.warning {
            background: var(--color-warning-bg);
            border-left-color: var(--color-warning);
        }
        .mon-dash .alert-title { color: var(--text-primary); }
        .mon-dash .alert-time { color: var(--text-muted) !important; }
        .mon-dash .alert-icon { color: var(--color-danger); }
        .mon-dash .alert-item.warning .alert-icon { color: var(--color-warning); }

        .no-update-flag {
            background: linear-gradient(135deg, #fb7185, #e11d48);
            color: #fff;
            box-shadow: 0 4px 12px rgba(225, 29, 72, 0.45);
        }

        .modal { background-color: var(--bg-overlay); }
        .modal-content {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border-default);
            max-width: min(750px, 94vw);
        }
        .modal-header {
            background: var(--bg-hover);
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-light);
        }
        .modal-header .modal-title,
        .modal-header .close { color: var(--text-primary) !important; }
        .modal-footer { border-top-color: var(--border-light); }
        #updatesModal .modal-content {
            width: min(820px, 94vw);
            max-height: 90vh;
        }
        #updatesModal .modal-footer {
            background: var(--bg-hover);
        }
        #updatesModal #actionButtons {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }
        #addUpdateModal .form-label { color: var(--text-secondary); }
        #addUpdateModal .form-control {
            background: var(--bg-input);
            color: var(--text-primary);
            border: 1px solid var(--border-input);
            border-radius: 10px;
        }
        .rm-modal-content {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border-default);
        }

        body.dark-mode .mon-dash .monitoring-header,
        body.dark-mode .mon-dash .map-section,
        body.dark-mode .mon-dash .info-card,
        body.dark-mode .mon-dash .reports-table-section,
        body.dark-mode .mon-dash .report-form-panel,
        body.dark-mode .mon-dash .stat-card {
            background: #1c2432 !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .mon-dash .header-title h1,
        body.dark-mode .mon-dash .map-title,
        body.dark-mode .mon-dash .info-card-title,
        body.dark-mode .mon-dash .reports-table-section .table-header h3 { color: var(--text-primary) !important; }
        body.dark-mode .mon-dash .header-title p,
        body.dark-mode .mon-dash .map-hint,
        body.dark-mode .mon-dash .stat-label { color: var(--text-secondary) !important; }
        body.dark-mode .mon-dash .stat-card.accent-blue,
        body.dark-mode .mon-dash .stat-card.accent-amber,
        body.dark-mode .mon-dash .stat-card.accent-rose,
        body.dark-mode .mon-dash .stat-card.accent-emerald {
            background: #1c2432 !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .mon-dash .stat-card .stat-icon.blue { background: var(--color-primary-bg) !important; color: var(--color-primary) !important; }
        body.dark-mode .mon-dash .stat-card .stat-icon.orange { background: var(--color-warning-bg) !important; color: var(--color-warning) !important; }
        body.dark-mode .mon-dash .stat-card .stat-icon.red { background: var(--color-danger-bg) !important; color: var(--color-danger) !important; }
        body.dark-mode .mon-dash .stat-card .stat-icon.green { background: var(--color-success-bg) !important; color: var(--color-success) !important; }
        body.dark-mode .mon-dash .stat-card .stat-number { color: var(--text-primary) !important; }
        body.dark-mode .mon-dash .reports-table-section .table-header { background: rgba(255,255,255,0.03) !important; }
        body.dark-mode .mon-dash .reports-table-section th { color: var(--text-secondary) !important; background: transparent !important; }
        body.dark-mode .mon-dash .reports-table-section td { color: var(--text-primary) !important; }
        body.dark-mode .mon-dash .badge-pending,
        body.dark-mode .mon-dash .db-st-pending { background: rgba(180, 83, 9, 0.28) !important; color: #fcd34d !important; }
        body.dark-mode .mon-dash .badge-in-progress,
        body.dark-mode .mon-dash .db-st-progress { background: rgba(162, 28, 175, 0.32) !important; color: #f0abfc !important; }
        body.dark-mode .mon-dash .badge-approved,
        body.dark-mode .mon-dash .db-st-approved { background: rgba(234, 88, 12, 0.30) !important; color: #fdba74 !important; }
        body.dark-mode .mon-dash .badge-completed,
        body.dark-mode .mon-dash .db-st-completed { background: rgba(157, 23, 77, 0.32) !important; color: #f9a8d4 !important; }
        body.dark-mode .mon-dash .badge-cancelled,
        body.dark-mode .mon-dash .badge-rejected,
        body.dark-mode .mon-dash .db-st-cancelled,
        body.dark-mode .mon-dash .db-st-rejected { background: rgba(185, 28, 28, 0.30) !important; color: #fca5a5 !important; }
        body.dark-mode .mon-dash .badge-assigned,
        body.dark-mode .mon-dash .db-st-assigned,
        body.dark-mode .mon-dash .db-st-active { background: rgba(109, 40, 217, 0.32) !important; color: #c4b5fd !important; }
        body.dark-mode .mon-dash .badge-high,
        body.dark-mode .mon-dash .badge-critical { background: var(--priority-high-bg) !important; color: var(--priority-high-text) !important; }
        body.dark-mode .mon-dash .badge-medium { background: var(--priority-medium-bg) !important; color: var(--priority-medium-text) !important; }
        body.dark-mode .mon-dash .badge-low { background: var(--priority-low-bg) !important; color: var(--priority-low-text) !important; }
        body.dark-mode .mon-dash .badge-source { background: rgba(109, 40, 217, 0.22) !important; color: #d8b4fe !important; }
        body.dark-mode .mon-dash .badge-source-lgu { background: rgba(109, 40, 217, 0.32) !important; color: #c4b5fd !important; }
        body.dark-mode .mon-dash .badge-source-citizen { background: rgba(190, 24, 93, 0.32) !important; color: #f9a8d4 !important; }
        body.dark-mode .mon-dash .badge-source-cimm { background: rgba(194, 65, 12, 0.30) !important; color: #fdba74 !important; }
        body.dark-mode .mon-dash .badge-source-infrastructure { background: rgba(154, 52, 18, 0.35) !important; color: #fdba74 !important; }
        body.dark-mode .mon-dash .assignment-assigned { background: rgba(109, 40, 217, 0.32) !important; color: #c4b5fd !important; }
        body.dark-mode .mon-dash .assignment-unassigned { background: rgba(180, 83, 9, 0.28) !important; color: #fcd34d !important; }
        body.dark-mode .mon-dash .cimm-verify-badge-verified { background: var(--badge-completed-bg) !important; color: var(--badge-completed-text) !important; }
        body.dark-mode .mon-dash .cimm-verify-badge-none { color: var(--text-muted) !important; }
        body.dark-mode .mon-dash .table-action-btn.btn-view { color: var(--color-primary) !important; }
        body.dark-mode .mon-dash .table-action-btn.btn-updates,
        body.dark-mode .mon-dash .table-action-btn.btn-archive { color: #fff !important; }
        body.dark-mode .modal-content { background: var(--bg-card) !important; }
        body.dark-mode .modal-header { background: linear-gradient(135deg, #2563eb, #1e3a8a) !important; }
        body.dark-mode .modal-header .modal-title,
        body.dark-mode .modal-header .close { color: #fff !important; }
        body.dark-mode .modal-footer { background: rgba(255,255,255,0.03); border-color: var(--border-default) !important; }
        body.dark-mode #gis-location-warning span,
        body.dark-mode #gis-warning-text { color: #fecaca !important; }
        body.dark-mode .mon-dash .report-form-panel label,
        body.dark-mode .mon-dash .report-form-panel h4 { color: var(--text-primary) !important; }
        body.dark-mode .mon-dash .report-form-panel select,
        body.dark-mode .mon-dash .report-form-panel textarea {
            background: var(--bg-input) !important;
            color: var(--text-primary) !important;
            border-color: var(--border-input) !important;
        }
        body.dark-mode .btn-primary,
        body.dark-mode .btn-action { color: #fff !important; }
        body.dark-mode .btn-success-custom,
        body.dark-mode .btn-danger-custom { color: #fff !important; }

        @media (max-width: 768px) {
            .mon-dash { padding: 16px; }
            .mon-dash .header-title h1 { font-size: 20px; flex-wrap: wrap; }
            .mon-dash .stats-row { grid-template-columns: 1fr 1fr; gap: 10px; }
            .mon-dash .action-cell { flex-wrap: wrap; }
            .mon-dash .reports-table-section .table-header { flex-direction: column; align-items: flex-start; }
            .mon-dash .table-header-right { width: 100%; }
            .mon-dash .filter-select,
            .mon-dash .road-search { width: 100%; }
            #updatesModal .modal-content,
            #addUpdateModal .modal-content,
            .rm-modal-content { width: 96vw; max-width: 96vw; max-height: 96vh; margin: 2vh auto; }
        }
        @media (max-width: 480px) {
            .mon-dash .stats-row { grid-template-columns: 1fr; }
            .mon-dash .header-icon { width: 36px; height: 36px; }
        }

        /* ── Completed Projects polish (UI only) ── */
        body.completed-projects-view { background: #f5f3ee; color: var(--text-primary); }
        body.completed-projects-view.dark-mode { background: var(--bg-page); }
        body.completed-projects-view .mon-dash { padding: 24px 28px; max-width: 100%; }

        body.completed-projects-view .mon-dash .monitoring-header,
        body.completed-projects-view .mon-dash .reports-table-section {
            background: #f4f7fb;
            border: 1px solid #d5dce8;
            border-radius: 14px;
            box-shadow: var(--shadow-card);
            margin-bottom: 16px;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
        }
        body.completed-projects-view .mon-dash .monitoring-header {
            overflow: hidden;
            padding: 20px 22px;
            backdrop-filter: none;
        }
        body.completed-projects-view .mon-dash .header-title h1 {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-primary);
        }
        body.completed-projects-view .mon-dash .header-icon {
            width: 40px; height: 40px; border-radius: 10px;
            background: var(--color-success-bg);
            color: var(--color-success);
            box-shadow: none;
            font-size: 16px;
        }
        body.completed-projects-view .mon-dash .header-title p {
            color: var(--text-secondary);
            font-size: 13px;
            margin: 0;
        }

        body.completed-projects-view .mon-dash .reports-table-section {
            border-left: 3px solid #1e3c72;
        }
        body.completed-projects-view .mon-dash .reports-table-section .table-header {
            background: transparent;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-light);
            margin-bottom: 0;
            gap: 12px;
        }
        body.completed-projects-view .mon-dash .reports-table-section .table-header h3 {
            color: var(--text-primary);
            font-size: 18px;
            font-weight: 700;
        }
        body.completed-projects-view .mon-dash .title-icon {
            width: 36px; height: 36px; border-radius: 10px;
            background: var(--color-primary-bg);
            color: var(--color-primary);
            font-size: 14px;
        }
        body.completed-projects-view .mon-dash .filter-select,
        body.completed-projects-view .mon-dash .road-search {
            background: var(--bg-input);
            color: var(--text-primary);
            border: 1px solid var(--border-input);
            border-radius: 8px;
            min-width: 0;
        }
        body.completed-projects-view .mon-dash .road-search { width: min(220px, 100%); }
        body.completed-projects-view .mon-dash .btn-secondary-custom {
            background: var(--bg-hover);
            color: var(--text-primary);
            border: 1px solid var(--border-default);
            border-radius: 8px;
            font-weight: 600;
        }
        body.completed-projects-view .mon-dash .btn-secondary-custom:hover {
            background: var(--color-primary-bg);
            color: var(--color-primary);
            border-color: var(--color-primary);
        }

        body.completed-projects-view .mon-dash .reports-table-wrap {
            overflow-x: hidden;
            max-width: 100%;
            padding: 0;
        }
        body.completed-projects-view .mon-dash .reports-table-section table { min-width: 0; width: 100%; }
        <?php if ($show_public_column && function_exists('completed_projects_public_column_css')): ?>
        <?php echo completed_projects_public_column_css(); ?>
        <?php endif; ?>
        body.completed-projects-view .mon-dash .reports-table-section th {
            background: var(--bg-hover) !important;
            color: var(--text-secondary) !important;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.4px;
            text-transform: uppercase;
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-default);
        }
        body.completed-projects-view .mon-dash .reports-table-section td {
            color: var(--text-primary);
            padding: 12px 16px;
            font-size: 13px;
            border-bottom: 1px solid var(--border-light);
        }
        body.completed-projects-view .mon-dash .reports-table-section tbody tr:hover td { background: var(--bg-hover); }
        body.completed-projects-view .mon-dash .reports-table-section td:nth-child(2) { white-space: normal; max-width: 240px; }
        body.completed-projects-view .mon-dash .mono-id { color: var(--text-secondary); }
        body.completed-projects-view .mon-dash .action-cell { white-space: normal; }

        body.completed-projects-view .mon-dash .badge,
        body.completed-projects-view .mon-dash .db-badge,
        body.completed-projects-view .mon-dash .category-badge,
        body.completed-projects-view .mon-dash .cimm-verify-badge,
        body.completed-projects-view .mon-dash .assignment-badge {
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 600;
            border: none;
        }
        body.completed-projects-view .mon-dash .badge-source-lgu {
            background: rgba(30, 60, 114, 0.10) !important;
            color: #1e3c72 !important;
        }
        body.completed-projects-view .mon-dash .badge-source-citizen {
            background: rgba(22, 163, 74, 0.10) !important;
            color: #15803d !important;
        }
        body.completed-projects-view .mon-dash .badge-source-cimm {
            background: rgba(90, 78, 120, 0.12) !important;
            color: #3f3658 !important;
        }
        body.completed-projects-view .mon-dash .badge-source-infrastructure {
            background: rgba(249, 115, 22, 0.10) !important;
            color: #c2410c !important;
        }
        body.completed-projects-view .mon-dash .category-road {
            background: rgba(30, 60, 114, 0.10) !important;
            color: #1e3c72 !important;
        }
        body.completed-projects-view .mon-dash .category-transportation {
            background: rgba(2, 132, 199, 0.10) !important;
            color: #0369a1 !important;
        }
        body.completed-projects-view .mon-dash .badge-pending,
        body.completed-projects-view .mon-dash .db-st-pending { background: var(--badge-pending-bg) !important; color: var(--badge-pending-text) !important; }
        body.completed-projects-view .mon-dash .badge-in-progress,
        body.completed-projects-view .mon-dash .db-st-progress { background: var(--badge-in-progress-bg) !important; color: var(--badge-in-progress-text) !important; }
        body.completed-projects-view .mon-dash .badge-approved,
        body.completed-projects-view .mon-dash .badge-completed,
        body.completed-projects-view .mon-dash .db-st-approved,
        body.completed-projects-view .mon-dash .db-st-completed { background: var(--badge-approved-bg) !important; color: var(--badge-approved-text) !important; }
        body.completed-projects-view .mon-dash .badge-cancelled,
        body.completed-projects-view .mon-dash .db-st-cancelled { background: var(--badge-cancelled-bg) !important; color: var(--badge-cancelled-text) !important; }
        body.completed-projects-view .mon-dash .badge-high,
        body.completed-projects-view .mon-dash .db-pr-high { background: var(--priority-high-bg) !important; color: var(--priority-high-text) !important; }
        body.completed-projects-view .mon-dash .badge-medium,
        body.completed-projects-view .mon-dash .db-pr-medium { background: var(--priority-medium-bg) !important; color: var(--priority-medium-text) !important; }
        body.completed-projects-view .mon-dash .badge-low,
        body.completed-projects-view .mon-dash .db-pr-low { background: var(--priority-low-bg) !important; color: var(--priority-low-text) !important; }
        body.completed-projects-view .mon-dash .assignment-assigned { background: var(--badge-approved-bg) !important; color: var(--badge-approved-text) !important; }
        body.completed-projects-view .mon-dash .assignment-unassigned { background: var(--bg-hover) !important; color: var(--text-secondary) !important; }
        body.completed-projects-view .mon-dash .cimm-verify-badge-verified { background: var(--badge-approved-bg) !important; color: var(--badge-approved-text) !important; }

        body.completed-projects-view .transparency-await-icon {
            width: 22px; height: 22px;
            top: 50%; left: 12px;
            transform: translateY(-50%);
            background: var(--color-warning-bg);
            color: var(--color-warning);
            font-size: 11px;
            box-shadow: none;
            animation: none;
            border: 1px solid rgba(217, 119, 6, 0.22);
        }
        body.completed-projects-view .transparency-await-icon::before { display: none; }
        body.completed-projects-view .reports-table-section .report-table-row.transparency-flagged td:first-child {
            padding-left: 42px;
        }
        body.completed-projects-view .mon-dash .table-action-btn {
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 12px;
            font-weight: 600;
            transform: none;
        }
        body.completed-projects-view .mon-dash .table-action-btn:hover { transform: none; }
        body.completed-projects-view .mon-dash .table-action-btn.btn-view {
            background: var(--color-primary-bg);
            color: var(--color-primary);
        }
        body.completed-projects-view .mon-dash .table-action-btn.btn-view:hover { background: var(--color-primary); color: #fff; }
        body.completed-projects-view .mon-dash .table-action-btn.btn-updates {
            background: var(--color-success-bg);
            color: var(--color-success-text);
        }
        body.completed-projects-view .mon-dash .table-action-btn.btn-updates:hover { background: var(--color-success); color: #fff; }
        body.completed-projects-view .mon-dash .table-action-btn.btn-archive {
            background: var(--bg-hover);
            color: var(--text-primary);
        }
        body.completed-projects-view .mon-dash .empty-cell { padding: 40px 16px !important; color: var(--text-secondary) !important; }

        body.completed-projects-view .rm-modal-content {
            background: var(--bg-card) !important;
            max-width: min(860px, 94vw);
            max-height: 86vh;
            box-shadow: var(--shadow-lg);
        }
        body.completed-projects-view .rm-modal-header {
            background: var(--bg-card) !important;
            padding: 16px 20px 14px !important;
            border-bottom: 1px solid var(--border-light) !important;
        }
        body.completed-projects-view .rm-modal-title { color: var(--text-primary) !important; font-size: 18px !important; }
        body.completed-projects-view .rm-modal-report-id { color: var(--text-secondary) !important; }
        body.completed-projects-view .rm-modal-section {
            background: var(--bg-hover) !important;
            border: 1px solid var(--border-light) !important;
            box-shadow: none;
        }
        body.completed-projects-view .rm-modal-btn-close {
            background: var(--color-primary-bg);
            color: var(--color-primary);
            border-radius: 8px;
            font-weight: 600;
        }
        body.completed-projects-view .rm-modal-btn-close:hover { background: var(--color-primary); color: #fff; }

        body.completed-projects-view #updatesModal .modal-content,
        body.completed-projects-view #addUpdateModal .modal-content {
            background: var(--bg-card);
            border: 1px solid var(--border-default);
            border-radius: 14px;
            box-shadow: var(--shadow-lg);
            max-width: min(820px, 94vw);
        }
        body.completed-projects-view #updatesModal .modal-header,
        body.completed-projects-view #addUpdateModal .modal-header {
            background: var(--bg-card) !important;
            color: var(--text-primary);
            border-bottom: 1px solid var(--border-light);
            padding: 16px 20px;
        }
        body.completed-projects-view #updatesModal .modal-title,
        body.completed-projects-view #addUpdateModal .modal-title,
        body.completed-projects-view #updatesModal .close,
        body.completed-projects-view #addUpdateModal .close { color: var(--text-primary) !important; }
        body.completed-projects-view #updatesModal .modal-body { padding: 16px 20px; }
        body.completed-projects-view #updatesModal .modal-footer {
            background: var(--bg-hover);
            border-top: 1px solid var(--border-light);
            padding: 14px 20px;
            gap: 12px;
        }
        body.completed-projects-view .rm-transparency-panel {
            background: var(--color-warning-bg);
            border: 1px solid rgba(217, 119, 6, 0.18);
            border-left: 3px solid var(--color-warning);
            border-radius: 10px;
            width: 100%;
        }
        body.completed-projects-view #updatesModal .btn-success-custom {
            background: var(--color-success-bg);
            color: var(--color-success-text);
            border-radius: 8px;
            font-weight: 600;
            transform: none;
            box-shadow: none;
        }
        body.completed-projects-view #updatesModal .btn-success-custom:hover { background: var(--color-success); color: #fff; transform: none; }
        body.completed-projects-view #updatesModal .btn-danger-custom {
            background: var(--color-danger-bg);
            color: var(--color-danger-text);
            border-radius: 8px;
            font-weight: 600;
            transform: none;
            box-shadow: none;
        }
        body.completed-projects-view #updatesModal .btn-danger-custom:hover { background: var(--color-danger); color: #fff; transform: none; }
        body.completed-projects-view #updatesModal .btn-action {
            background: var(--color-primary-bg);
            color: var(--color-primary);
            border-radius: 8px;
            font-weight: 600;
            transform: none;
            box-shadow: none;
        }
        body.completed-projects-view #updatesModal .btn-action:hover { background: var(--color-primary); color: #fff; transform: none; }
        body.completed-projects-view #requestTransparencyBtn {
            background: var(--color-primary-bg) !important;
            color: var(--color-primary) !important;
        }
        body.completed-projects-view .rm-modal-btn-transparency-approve {
            background: var(--color-success-bg);
            color: var(--color-success-text);
            border: none;
            border-radius: 8px;
            font-weight: 600;
        }
        body.completed-projects-view .rm-modal-btn-transparency-reject {
            background: var(--color-danger-bg);
            color: var(--color-danger-text);
            border: none;
            border-radius: 8px;
            font-weight: 600;
        }
        body.completed-projects-view .timeline-container { margin: 8px 0 4px; }
        body.completed-projects-view .timeline-container::before {
            background: var(--border-default);
            width: 2px;
        }
        body.completed-projects-view .timeline-dot {
            background: var(--color-primary);
            box-shadow: none;
            border-color: var(--bg-card);
        }
        body.completed-projects-view .timeline-card {
            background: var(--bg-hover);
            border: 1px solid var(--border-light);
            box-shadow: none;
        }
        body.completed-projects-view .timeline-title { color: var(--text-primary); }
        body.completed-projects-view .timeline-meta { color: var(--text-secondary); }

        body.completed-projects-view.dark-mode .mon-dash .monitoring-header,
        body.completed-projects-view.dark-mode .mon-dash .reports-table-section {
            background: #1c2432 !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.completed-projects-view.dark-mode #updatesModal .modal-content,
        body.completed-projects-view.dark-mode #addUpdateModal .modal-content,
        body.completed-projects-view.dark-mode .rm-modal-content { background: var(--bg-card) !important; }
        body.completed-projects-view.dark-mode .mon-dash .reports-table-section {
            border-color: var(--border-default) !important;
            border-left-color: #93b3e0 !important;
        }
        body.completed-projects-view.dark-mode .mon-dash .header-title h1,
        body.completed-projects-view.dark-mode .mon-dash .reports-table-section .table-header h3,
        body.completed-projects-view.dark-mode #updatesModal .modal-title,
        body.completed-projects-view.dark-mode #updatesModal .close { color: var(--text-primary) !important; }
        body.completed-projects-view.dark-mode .mon-dash .reports-table-section th {
            background: var(--bg-hover) !important;
            color: var(--text-secondary) !important;
        }
        body.completed-projects-view.dark-mode .mon-dash .reports-table-section td { color: var(--text-primary) !important; }
        body.completed-projects-view.dark-mode .mon-dash .badge-source-lgu { background: rgba(147, 179, 224, 0.16) !important; color: #93b3e0 !important; }
        body.completed-projects-view.dark-mode .mon-dash .badge-source-citizen { background: rgba(74, 222, 128, 0.16) !important; color: #86efac !important; }
        body.completed-projects-view.dark-mode .mon-dash .badge-source-cimm { background: rgba(167, 154, 196, 0.18) !important; color: #c5bdd8 !important; }
        body.completed-projects-view.dark-mode .mon-dash .badge-source-infrastructure { background: rgba(251, 146, 60, 0.16) !important; color: #fdba74 !important; }
        body.completed-projects-view.dark-mode .mon-dash .category-road { background: rgba(147, 179, 224, 0.16) !important; color: #93b3e0 !important; }
        body.completed-projects-view.dark-mode .mon-dash .category-transportation { background: rgba(56, 189, 248, 0.16) !important; color: #7dd3fc !important; }
        body.completed-projects-view.dark-mode .transparency-await-icon {
            background: var(--color-warning-bg);
            color: var(--color-warning-text);
            box-shadow: none;
        }
        body.completed-projects-view.dark-mode #updatesModal .modal-header,
        body.completed-projects-view.dark-mode #addUpdateModal .modal-header { background: var(--bg-card) !important; }
        body.completed-projects-view.dark-mode .timeline-card { background: var(--bg-hover); }
        body.completed-projects-view.dark-mode .timeline-title { color: var(--text-primary); }

        @media (max-width: 768px) {
            body.completed-projects-view .mon-dash { padding: 16px; }
            body.completed-projects-view .mon-dash .header-title h1 { font-size: 20px; flex-wrap: wrap; }
            body.completed-projects-view .mon-dash .reports-table-section .table-header { flex-direction: column; align-items: flex-start; }
            body.completed-projects-view .mon-dash .table-header-right { width: 100%; }
            body.completed-projects-view .mon-dash .filter-select,
            body.completed-projects-view .mon-dash .road-search { width: 100%; }
            body.completed-projects-view .rm-modal-overlay { padding: 8px; }
            body.completed-projects-view .rm-modal-content,
            body.completed-projects-view #updatesModal .modal-content,
            body.completed-projects-view #addUpdateModal .modal-content { max-width: 96vw; max-height: 96vh; }
            body.completed-projects-view #updatesModal #actionButtons,
            body.completed-projects-view #updatesModal #actionButtons > div { width: 100%; }
            body.completed-projects-view #updatesModal .btn-action,
            body.completed-projects-view #updatesModal .btn-success-custom,
            body.completed-projects-view #updatesModal .btn-danger-custom,
            body.completed-projects-view #updatesModal .btn-secondary-custom { justify-content: center; }
        }
        @media (max-width: 480px) {
            body.completed-projects-view .mon-dash .header-icon { width: 36px; height: 36px; }
        }
    </style>
<?php if ($is_road_supervisor): ?>
    <!-- Road Ops Supervisor only: mobile fit for monitoring-layout / sidebar-section.
         UI-only CSS scoping — other portals are unaffected and no behaviour changes. -->
    <style>
        @media (max-width: 768px) {
            .road-supervisor-view .mon-dash { padding: 16px 14px; }

            /* Grid/flex children must be allowed to shrink below their content
               width, otherwise wide inner rows force the track past the screen
               edge and get clipped ("half" visible). */
            .road-supervisor-view .monitoring-layout,
            .road-supervisor-view .monitoring-layout > *,
            .road-supervisor-view .sidebar-section,
            .road-supervisor-view .info-card {
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }

            .road-supervisor-view .monitoring-layout {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .road-supervisor-view .sidebar-section {
                display: flex;
                flex-direction: column;
                gap: 16px;
                width: 100%;
                overflow-x: hidden;
            }

            .road-supervisor-view .map-section {
                padding: 12px;
                min-width: 0;
                overflow-x: hidden;
            }

            /* Shorter map keeps header + toolbar + map inside one mobile view */
            .road-supervisor-view #map {
                height: 340px;
            }

            /* Toolbar button row is ~600px unwrapped — let it wrap instead of
               pushing past the screen edge */
            .road-supervisor-view .map-toolbar,
            .road-supervisor-view .map-toolbar-left {
                flex-wrap: wrap;
            }
            .road-supervisor-view .map-toolbar-right {
                width: auto;
            }
            .road-supervisor-view .map-search-box {
                max-width: none;
            }
            .road-supervisor-view .map-search-box input {
                width: 100%;
            }

            /* Filter buttons and legend chips are single non-wrapping flex rows,
               so on narrow screens their content runs past the screen edge and
               gets clipped. Let both wrap so every item stays visible. */
            .road-supervisor-view .map-filters {
                flex-wrap: wrap;
                row-gap: 8px;
            }
            .road-supervisor-view .map-filters .filter-btn {
                flex-shrink: 0;
            }
            .road-supervisor-view .map-legend {
                flex-wrap: wrap;
                row-gap: 6px;
            }

            /* Stats row: 2×2 grid on phones with compact cards */
            .road-supervisor-view .stats-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 10px;
                margin-bottom: 14px;
            }
            .road-supervisor-view .stat-card {
                padding: 10px 8px;
                border-radius: 10px;
            }
            .road-supervisor-view .stat-card::before { height: 3px; }
            .road-supervisor-view .stat-card .stat-icon {
                width: 28px;
                height: 28px;
                border-radius: 8px;
                font-size: 12px;
                margin-bottom: 6px;
            }
            .road-supervisor-view .stat-card .stat-number { font-size: 16px; }
            .road-supervisor-view .stat-card .stat-label {
                font-size: 9.5px;
                line-height: 1.25;
            }
            .road-supervisor-view .stat-card .stat-desc { display: none; }
        }
        @media (max-width: 480px) {
            .road-supervisor-view .stats-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }
        }
    </style>
<?php endif; ?>
<?php if ($is_system_admin): ?>
    <!-- System Admin only: mobile fit for monitoring-layout / sidebar-section.
         UI-only CSS scoping — other portals are unaffected and no behaviour changes. -->
    <style>
        @media (max-width: 768px) {
            /* Grid/flex children must be allowed to shrink below their content
               width, otherwise wide inner rows force the track past the screen
               edge and get clipped ("half" visible). */
            .system-admin-view .monitoring-layout,
            .system-admin-view .monitoring-layout > *,
            .system-admin-view .sidebar-section,
            .system-admin-view .sidebar-section > *,
            .system-admin-view .info-card {
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }

            .system-admin-view .monitoring-layout {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .system-admin-view .sidebar-section {
                display: flex;
                flex-direction: column;
                gap: 16px;
                width: 100%;
                overflow-x: hidden;
            }

            .system-admin-view .map-section {
                padding: 12px;
                min-width: 0;
                overflow-x: hidden;
            }

            /* Shorter map keeps header + toolbar + map inside one mobile view */
            .system-admin-view #map {
                height: 340px;
            }

            /* Toolbar button row is ~600px unwrapped — let it wrap instead of
               pushing past the screen edge */
            .system-admin-view .map-toolbar,
            .system-admin-view .map-toolbar-left {
                flex-wrap: wrap;
            }
            .system-admin-view .map-toolbar-right {
                width: auto;
            }
            .system-admin-view .map-search-box {
                max-width: none;
            }
            .system-admin-view .map-search-box input {
                width: 100%;
            }

            /* Filter buttons and legend chips are single non-wrapping flex rows,
               so on narrow screens their content runs past the screen edge and
               gets clipped. Let both wrap so every item stays visible. */
            .system-admin-view .map-filters {
                flex-wrap: wrap;
                row-gap: 8px;
            }
            .system-admin-view .map-filters .filter-btn {
                flex-shrink: 0;
            }
            .system-admin-view .map-legend {
                flex-wrap: wrap;
                row-gap: 6px;
            }
        }
        @media (max-width: 480px) {
            /* Very narrow screens stay 2x2 for stats-row (overrides the
               generic stack-to-one-column rule which has lower specificity) */
            .system-admin-view .stats-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
<?php endif; ?>
<?php if ($is_trans_ops_supervisor): ?>
    <!-- Transport Operations Supervisor only: mobile fit for monitoring-layout /
         sidebar-section. Mirrors the Road Ops Supervisor / System Admin fit
         blocks. UI-only CSS scoping — other portals are unaffected and no
         behaviour changes. -->
    <style>
        @media (max-width: 768px) {
            .trans-supervisor-view .mon-dash { padding: 16px 14px; }

            /* Grid/flex children must be allowed to shrink below their content
               width, otherwise wide inner rows force the track past the screen
               edge and get clipped ("half" visible). */
            .trans-supervisor-view .monitoring-layout,
            .trans-supervisor-view .monitoring-layout > *,
            .trans-supervisor-view .sidebar-section,
            .trans-supervisor-view .sidebar-section > *,
            .trans-supervisor-view .info-card {
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }

            .trans-supervisor-view .monitoring-layout {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .trans-supervisor-view .sidebar-section {
                display: flex;
                flex-direction: column;
                gap: 16px;
                width: 100%;
                overflow-x: hidden;
            }

            .trans-supervisor-view .map-section {
                padding: 12px;
                min-width: 0;
                overflow-x: hidden;
            }

            /* Shorter map keeps header + toolbar + map inside one mobile view */
            .trans-supervisor-view #map {
                height: 340px;
            }

            /* Toolbar button row is ~600px unwrapped — let it wrap instead of
               pushing past the screen edge */
            .trans-supervisor-view .map-toolbar,
            .trans-supervisor-view .map-toolbar-left {
                flex-wrap: wrap;
            }
            .trans-supervisor-view .map-toolbar-right {
                width: auto;
            }
            .trans-supervisor-view .map-search-box {
                max-width: none;
            }
            .trans-supervisor-view .map-search-box input {
                width: 100%;
            }

            /* Filter buttons and legend chips are single non-wrapping flex rows,
               so on narrow screens their content runs past the screen edge and
               gets clipped. Let both wrap so every item stays visible. */
            .trans-supervisor-view .map-filters {
                flex-wrap: wrap;
                row-gap: 8px;
            }
            .trans-supervisor-view .map-filters .filter-btn {
                flex-shrink: 0;
            }
            .trans-supervisor-view .map-legend {
                flex-wrap: wrap;
                row-gap: 6px;
            }

            /* Stats row: keep all four cards in ONE row on phones. Compact the
               cards (padding/icon/type sizes) so the 4-column grid actually
               fits instead of collapsing to 2x2 or stacking. */
            .trans-supervisor-view .stats-row {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 8px;
                margin-bottom: 14px;
            }
            .trans-supervisor-view .stat-card {
                padding: 10px 8px;
                border-radius: 10px;
            }
            .trans-supervisor-view .stat-card::before { height: 3px; }
            .trans-supervisor-view .stat-card .stat-icon {
                width: 28px;
                height: 28px;
                border-radius: 8px;
                font-size: 12px;
                margin-bottom: 6px;
            }
            .trans-supervisor-view .stat-card .stat-number { font-size: 16px; }
            .trans-supervisor-view .stat-card .stat-label {
                font-size: 9.5px;
                line-height: 1.25;
            }
        }
        @media (max-width: 480px) {
            /* Very narrow screens stay one row as well (overrides the generic
               stack-to-one-column rule which is scoped outside this block) */
            .trans-supervisor-view .stats-row {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }
    </style>
<?php endif; ?>
<?php if ($is_road_monitoring_officer): ?>
    <!-- Road Monitoring Officer only: keep all four dashboard stat cards in
         ONE row on phones inside this page's stats-row. The generic
         .mon-dash rules collapse them to 2x2 below 768px and to a single
         column below 480px; switch to a fixed 4-column grid with compact
         tiles instead so every card stays visible in one row.
         UI-only CSS scoping - other portals/pages are unaffected and no
         behaviour changes. -->
    <style>
        @media (max-width: 768px) {
            body.rmo-view .mon-dash .stats-row {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 8px;
                margin-bottom: 14px;
            }
            body.rmo-view .mon-dash .stats-row .stat-card {
                padding: 10px 8px;
                border-radius: 10px;
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }
            body.rmo-view .mon-dash .stats-row .stat-card::before { height: 3px; }
            body.rmo-view .mon-dash .stats-row .stat-card .stat-icon {
                width: 28px;
                height: 28px;
                border-radius: 8px;
                font-size: 12px;
                margin-bottom: 6px;
            }
            body.rmo-view .mon-dash .stats-row .stat-card .stat-number { font-size: 16px; }
            body.rmo-view .mon-dash .stats-row .stat-card .stat-label {
                font-size: 9.5px;
                line-height: 1.25;
                overflow-wrap: anywhere;
                word-break: break-word;
            }
        }
        @media (max-width: 480px) {
            /* Very narrow screens stay one row as well (overrides the generic
               stack-to-one-column rule which has lower specificity) */
            body.rmo-view .mon-dash .stats-row {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }
    </style>
<?php endif; ?>
<?php if ($is_road_monitoring_officer): ?>
    <!-- Road Monitoring Officer only: mobile fit for monitoring-layout /
         sidebar-section (map + side panels). Mirrors the Road Ops Supervisor
         fit: grid/flex children must be allowed to shrink below their content
         width, otherwise wide inner rows force the track past the screen edge
         and get clipped ("half" visible); the toolbar, filter buttons and
         legend chips wrap instead of overflowing.
         UI-only CSS scoping - other portals/pages are unaffected and no
         behaviour changes. -->
    <style>
        @media (max-width: 768px) {
            body.rmo-view .mon-dash { padding: 16px 14px; }

            /* Grid/flex children must be allowed to shrink below their content
               width, otherwise wide inner rows force the track past the screen
               edge and get clipped ("half" visible). */
            body.rmo-view .monitoring-layout,
            body.rmo-view .monitoring-layout > *,
            body.rmo-view .sidebar-section,
            body.rmo-view .info-card {
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }

            body.rmo-view .monitoring-layout {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            body.rmo-view .sidebar-section {
                display: flex;
                flex-direction: column;
                gap: 16px;
                width: 100%;
                overflow-x: hidden;
            }

            body.rmo-view .map-section {
                padding: 12px;
                min-width: 0;
                overflow-x: hidden;
            }

            /* Shorter map keeps header + toolbar + map inside one mobile view */
            body.rmo-view #map {
                height: 340px;
            }

            /* Toolbar button row is ~600px unwrapped - let it wrap instead of
               pushing past the screen edge */
            body.rmo-view .map-toolbar,
            body.rmo-view .map-toolbar-left {
                flex-wrap: wrap;
            }
            body.rmo-view .map-toolbar-right {
                width: auto;
            }
            body.rmo-view .map-search-box {
                max-width: none;
            }
            body.rmo-view .map-search-box input {
                width: 100%;
            }

            /* Filter buttons and legend chips are single non-wrapping flex rows,
               so on narrow screens their content runs past the screen edge and
               gets clipped. Let both wrap so every item stays visible. */
            body.rmo-view .map-filters {
                flex-wrap: wrap;
                row-gap: 8px;
            }
            body.rmo-view .map-filters .filter-btn {
                flex-shrink: 0;
            }
            body.rmo-view .map-legend {
                flex-wrap: wrap;
                row-gap: 6px;
            }
        }
        /* Road Monitoring Officer only: dark-mode compatible highlight for
           recentReportsTable rows when locating from notifications.
           The base focus-pulse uses light-mode colours (#eef5ff, #3762c8)
           that are invisible or harsh on dark backgrounds. */
        body.rmo-view.dark-mode tr.focus-pulse {
            border-left: 4px solid #60a5fa;
            background: rgba(96, 165, 250, 0.12);
            animation: rmoDarkFocusPulse 1s ease-in-out 4;
        }
        @keyframes rmoDarkFocusPulse {
            0%, 100% { border-left: 4px solid #60a5fa; background: rgba(96, 165, 250, 0.12); }
            50%      { border-left: 4px solid #93c5fd; background: rgba(96, 165, 250, 0.22); }
        }
    </style>
<?php endif; ?>
<?php if ($is_road_supervisor): ?>
    <!-- Road Ops Supervisor only: dark-mode compatible highlight for
         recentReportsTable rows when locating from notifications.
         The base focus-pulse uses light-mode colours (#eef5ff, #3762c8)
         that are invisible or harsh on dark backgrounds. Completed
         Projects deep-links land here, so the highlight must be readable. -->
    <style>
        body.road-supervisor-view.dark-mode tr.focus-pulse {
            border-left: 4px solid #60a5fa;
            background: rgba(96, 165, 250, 0.12);
            animation: rsDarkFocusPulse 1s ease-in-out 4;
        }
        @keyframes rsDarkFocusPulse {
            0%, 100% { border-left: 4px solid #60a5fa; background: rgba(96, 165, 250, 0.12); }
            50%      { border-left: 4px solid #93c5fd; background: rgba(96, 165, 250, 0.22); }
        }
    </style>
<?php endif; ?>
<?php if ($is_system_admin && $is_completed_projects_view): ?>
    <!-- System Admin + Completed Projects: badge sizing on phones.
         The base table CSS now handles scroll and column alignment for all
         users; this block only tweaks badge pill sizing on small screens. -->
    <style>
        body.completed-projects-view.system-admin-view .mon-dash .table-header-right {
            margin-left: auto;
        }
        @media (max-width: 768px) {
            body.completed-projects-view.system-admin-view .mon-dash .reports-table-section .badge,
            body.completed-projects-view.system-admin-view .mon-dash .reports-table-section .db-badge,
            body.completed-projects-view.system-admin-view .mon-dash .reports-table-section .category-badge,
            body.completed-projects-view.system-admin-view .mon-dash .reports-table-section .cimm-verify-badge,
            body.completed-projects-view.system-admin-view .mon-dash .reports-table-section .assignment-badge,
            body.completed-projects-view.system-admin-view .mon-dash .reports-table-section .pt-status-badge {
                font-size: 9px;
                padding: 2px 6px;
            }
            body.completed-projects-view.system-admin-view .mon-dash #recentReportsTable th,
            body.completed-projects-view.system-admin-view .mon-dash #recentReportsTable td {
                padding: 10px 6px !important;
            }
        }
    </style>
<?php endif; ?>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?><?php echo $is_completed_projects_view ? ' completed-projects-view' : ''; ?><?php echo $is_road_supervisor ? ' road-supervisor-view' : ''; ?><?php echo $is_trans_ops_supervisor ? ' trans-supervisor-view' : ''; ?><?php echo $is_road_monitoring_officer ? ' rmo-view' : ''; ?><?php echo $is_system_admin ? ' system-admin-view' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content mon-dash">
        <!-- Monitoring Header -->
        <div class="monitoring-header">
            <div class="header-content">
                <div class="header-title">
                    <h1><span class="header-icon"><i class="fas fa-<?php echo $is_completed_projects_view ? 'circle-check' : 'map-location-dot'; ?>"></i></span> <?php echo $is_completed_projects_view ? 'Completed Projects' : 'Road and Transportation Reporting'; ?></h1>
                    <p><?php echo $is_completed_projects_view ? 'Review completed projects, progress updates, and related actions.' : 'Real-time monitoring of road conditions and traffic flow'; ?></p>
                </div>
                <div class="dt-chip">
                    <i class="fas fa-calendar-day"></i>
                    <div>
                        <div id="currentDate"></div>
                        <div id="currentTime"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card accent-blue">
                <div class="stat-icon blue"><i class="fas fa-clipboard-list"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['total']); ?></div>
                <div class="stat-label">Total Reports</div>
            </div>
            <div class="stat-card accent-amber">
                <div class="stat-icon orange"><i class="fas fa-hourglass-half"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['active']); ?></div>
                <div class="stat-label">Active Issues</div>
            </div>
            <div class="stat-card accent-rose">
                <div class="stat-icon red"><i class="fas fa-bolt"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['critical']); ?></div>
                <div class="stat-label">High / Critical</div>
            </div>
            <div class="stat-card accent-emerald">
                <div class="stat-icon green"><i class="fas fa-circle-check"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['resolved_month']); ?></div>
                <div class="stat-label">Resolved This Month</div>
            </div>
        </div>

        <!-- Main Monitoring Layout -->
        <div class="monitoring-layout">
            <!-- Map Section -->
            <div class="map-section">
                <div class="map-header">
                    <h3 class="map-title"><span class="title-icon"><i class="fas fa-map"></i></span> Live Road Map — Quezon City</h3>
                    <p class="map-hint">Click on the map to pin a location, then fill the form and submit your report.</p>
                </div>
                <div class="map-toolbar">
                    <div class="map-toolbar-left">
                        <div class="map-filters">
                            <button class="filter-btn active" data-filter="all" onclick="filterMapMarkers('all')">All</button>
                            <button class="filter-btn" data-filter="pending" onclick="filterMapMarkers('pending')">Pending</button>
                            <button class="filter-btn" data-filter="in-progress" onclick="filterMapMarkers('in-progress')">In Progress</button>
                            <button class="filter-btn" data-filter="completed" onclick="filterMapMarkers('completed')">Completed</button>
                            <button class="filter-btn" data-filter="high" onclick="filterMapMarkers('high')"><i class="fas fa-exclamation"></i> Critical</button>
                        </div>
                        <div class="map-legend">
                            <span class="map-legend-item"><span class="map-legend-dot t-bg-danger"></span> High</span>
                            <span class="map-legend-item"><span class="map-legend-dot t-bg-warning"></span> Medium</span>
                            <span class="map-legend-item"><span class="map-legend-dot" style="background:#6c757d;"></span> Low</span>
                            <span class="map-legend-item"><span class="map-legend-dot" style="background:#dc2626;"></span> Accident</span>
                            <span class="map-legend-item"><span class="map-legend-dot" style="background:#111827;"></span> Closed</span>
                            <span class="map-legend-item"><span class="map-legend-dot" style="background:#f59e0b;"></span> Jam</span>
                            <span class="map-legend-item"><span class="map-legend-dot" style="background:#ca8a04;"></span> Works</span>
                            <span class="map-legend-item"><span class="map-legend-dot" style="background:#0284c7;"></span> Bus stop</span>
                            <span class="map-legend-item"><span class="map-legend-dot" style="background:#475569;"></span> Rail</span>
                            <span class="map-legend-item"><span class="map-legend-dot" style="background:#dc2626;"></span> PT route</span>
                        </div>
                        <div class="map-search-box">
                            <input type="text" id="mapSearchInput" placeholder="Search places...">
                            <button class="map-fullscreen-btn" onclick="doMapSearch()" title="Search" style="width:auto;min-width:38px;flex:0 0 auto;"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="map-toolbar-right">
                        <div class="map-tools" id="mapTools">
                            <button type="button" class="map-fullscreen-btn map-tools-toggle has-active" id="toolsDropdownBtn" onclick="toggleToolsDropdown()" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-tools"></i> Tools
                                <span class="map-tools-toggle-count" id="toolsActiveCount">1</span>
                            </button>
                            <div class="map-tools-menu" id="toolsDropdownMenu" role="menu" aria-label="Map tools">
                                <div class="map-tools-heading">Layers</div>
                                <button type="button" class="map-tools-item is-on" id="toggleTrafficBtn" onclick="toggleTrafficLayer()" role="menuitemcheckbox" aria-checked="true">
                                    <span class="map-tools-item-main"><i class="fas fa-car"></i> Traffic</span>
                                    <span class="map-tools-item-state">On</span>
                                </button>
                                <button type="button" class="map-tools-item is-off" id="toggleAccidentsBtn" onclick="toggleAccidentPins()" role="menuitemcheckbox" aria-checked="false">
                                    <span class="map-tools-item-main"><i class="fas fa-exclamation-triangle"></i> Incidents</span>
                                    <span class="map-tools-item-state">Off</span>
                                </button>
                                <button type="button" class="map-tools-item is-off" id="toggleBusStopsBtn" onclick="toggleBusStopPins()" role="menuitemcheckbox" aria-checked="false">
                                    <span class="map-tools-item-main"><i class="fas fa-bus"></i> Bus</span>
                                    <span class="map-tools-item-state">Off</span>
                                </button>
                                <button type="button" class="map-tools-item is-off" id="toggleRailStationsBtn" onclick="toggleRailStationPins()" role="menuitemcheckbox" aria-checked="false">
                                    <span class="map-tools-item-main"><i class="fas fa-train"></i> Rail</span>
                                    <span class="map-tools-item-state">Off</span>
                                </button>
                                <button type="button" class="map-tools-item is-off" id="togglePtRoutesBtn" onclick="showPtRoutesPanel()" role="menuitemcheckbox" aria-checked="false">
                                    <span class="map-tools-item-main"><i class="fas fa-route"></i> PT Routes</span>
                                    <span class="map-tools-item-state">Off</span>
                                </button>

                                <div class="map-tools-divider"></div>
                                <div class="map-tools-heading">Planners</div>
                                <button type="button" class="map-tools-item is-off" id="btnRoutePlanner" onclick="showRoutePlanner()" role="menuitemcheckbox" aria-checked="false">
                                    <span class="map-tools-item-main"><i class="fas fa-route"></i> Route Planner</span>
                                    <span class="map-tools-item-state">Off</span>
                                </button>
                                <button type="button" class="map-tools-item is-off" id="btnCommutePlanner" onclick="showCommutePlanner()" role="menuitemcheckbox" aria-checked="false">
                                    <span class="map-tools-item-main"><i class="fas fa-bus"></i> Commute Planner</span>
                                    <span class="map-tools-item-state">Off</span>
                                </button>
                                <button type="button" class="map-tools-item is-off" id="btnEVCharging" onclick="showEVCharging()" role="menuitemcheckbox" aria-checked="false">
                                    <span class="map-tools-item-main"><i class="fas fa-charging-station"></i> EV Stations</span>
                                    <span class="map-tools-item-state">Off</span>
                                </button>

                                <div class="map-tools-divider"></div>
                                <button type="button" class="map-tools-item map-tools-action" id="syncMapLayersBtn" onclick="syncMapLayers()" title="Re-download Incidents, Bus, Rail, and PT Routes" role="menuitem">
                                    <span class="map-tools-item-main"><i class="fas fa-sync-alt"></i> Sync Layers</span>
                                </button>
                            </div>
                        </div>
                        <button class="map-fullscreen-btn" onclick="toggleMapFullscreen()" id="fullscreenMapBtn">
                            <i class="fas fa-expand"></i> Fullscreen
                        </button>
                    </div>
                </div>
                <div id="map"></div>

                <div class="sync-layers-overlay" id="syncLayersOverlay" aria-hidden="true">
                    <div class="sync-layers-modal" role="dialog" aria-modal="true" aria-labelledby="syncLayersTitle">
                        <div class="sync-layers-modal-header">
                            <h3 id="syncLayersTitle"><i class="fas fa-sync-alt" id="syncLayersTitleIcon"></i> Syncing Map Layers</h3>
                            <p id="syncLayersSubtitle">Downloading fresh data. Please wait — this window cannot be closed until sync finishes.</p>
                        </div>
                        <div class="sync-layers-modal-body">
                            <ul class="sync-layers-list" id="syncLayersList">
                                <li class="sync-layers-item is-pending" data-layer="incidents">
                                    <span class="sync-layers-item-icon"><i class="fas fa-spinner fa-spin"></i></span>
                                    <span class="sync-layers-item-meta">
                                        <span class="sync-layers-item-label"><i class="fas fa-exclamation-triangle"></i> Incidents</span>
                                        <span class="sync-layers-item-status">Waiting…</span>
                                    </span>
                                </li>
                                <li class="sync-layers-item is-pending" data-layer="bus">
                                    <span class="sync-layers-item-icon"><i class="fas fa-spinner fa-spin"></i></span>
                                    <span class="sync-layers-item-meta">
                                        <span class="sync-layers-item-label"><i class="fas fa-bus"></i> Bus Stops</span>
                                        <span class="sync-layers-item-status">Waiting…</span>
                                    </span>
                                </li>
                                <li class="sync-layers-item is-pending" data-layer="rail">
                                    <span class="sync-layers-item-icon"><i class="fas fa-spinner fa-spin"></i></span>
                                    <span class="sync-layers-item-meta">
                                        <span class="sync-layers-item-label"><i class="fas fa-train"></i> Rail Stations</span>
                                        <span class="sync-layers-item-status">Waiting…</span>
                                    </span>
                                </li>
                                <li class="sync-layers-item is-pending" data-layer="routes">
                                    <span class="sync-layers-item-icon"><i class="fas fa-spinner fa-spin"></i></span>
                                    <span class="sync-layers-item-meta">
                                        <span class="sync-layers-item-label"><i class="fas fa-route"></i> PT Routes</span>
                                        <span class="sync-layers-item-status">Waiting…</span>
                                    </span>
                                </li>
                            </ul>
                        </div>
                        <div class="sync-layers-modal-footer" id="syncLayersFooter">
                            <button type="button" class="sync-layers-close-btn" id="syncLayersCloseBtn" onclick="closeSyncLayersModal()">Close</button>
                        </div>
                    </div>
                </div>
                <!-- Report form (shown after pinning) -->
                <div id="gis-location-warning" style="display:none;background:rgba(220,53,69,0.06);border:1px solid rgba(220,53,69,0.25);border-radius:10px;padding:12px 14px;margin-bottom:0;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <i class="fas fa-exclamation-triangle" style="color:#dc3545;font-size:15px;"></i>
                        <span style="font-size:12px;color:#721c24;" id="gis-warning-text">Pinned location is outside the covered LGU jurisdiction.</span>
                    </div>
                </div>
                <div id="report-form-panel" class="report-form-panel" style="display: none;">
                    <h4><i class="fas fa-map-pin"></i> Report issue at pinned location</h4>
                    <form id="report-form" enctype="multipart/form-data">
                        <input type="hidden" id="pin-lat" name="latitude">
                        <input type="hidden" id="pin-lng" name="longitude">
                        <input type="hidden" id="pin-district" name="detected_district">
                        <input type="hidden" id="pin-barangay" name="barangay">
                        <input type="hidden" id="pin-street" name="street_name">
                        <input type="hidden" id="pin-address" name="location_address">
                        
                        <div id="gis-location-info" style="display:none;background:linear-gradient(135deg,rgba(16,185,129,0.08),rgba(55,98,200,0.08));border:1px solid rgba(16,185,129,0.25);border-radius:10px;padding:12px 14px;margin-bottom:14px;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                                <i class="fas fa-map-marked-alt" style="color:#10b981;font-size:15px;"></i>
                                <strong style="font-size:13px;color:#1e3c72;">Detected Location</strong>
                                <span id="gis-loading-badge" style="display:none;margin-left:auto;font-size:11px;color:#666;"><i class="fas fa-spinner fa-spin"></i> Detecting...</span>
                            </div>
                            <div id="gis-location-details" style="font-size:12px;color:#555;line-height:1.7;"></div>
                        </div>
                        
                        <label for="issue-type">Report type</label>
                        <select id="issue-type" name="issue_type" required onchange="updateSpecificTypes()">
                            <option value="">— Select —</option>
                            <option value="transportation">Transportation</option>
                            <option value="roads">Road</option>
                        </select>
                        
                        <label id="specific-type-label" for="specific-type" style="display: none; margin-top: 10px;">Issue type</label>
                        <select id="specific-type" name="specific_type" style="display: none;" required>
                            <option value="">— Select issue type —</option>
                            <optgroup id="transportation-options" label="Transportation Issues">
                                <option value="traffic_jam">Traffic Jam</option>
                                <option value="accident">Vehicle Accident</option>
                                <option value="road_closure">Road Closure</option>
                                <option value="traffic_light_outage">Traffic Light Outage</option>
                                <option value="congestion">Heavy Congestion</option>
                                <option value="parking_violation">Illegal Parking</option>
                                <option value="public_transport_issue">Public Transport Issue</option>
                                <option value="vehicle_breakdown">Vehicle Breakdown</option>
                                <option value="traffic_sign_issue">Traffic Sign Issue</option>
                            </optgroup>
                            <optgroup id="roads-options" label="Road Issues">
                                <option value="potholes">Potholes</option>
                                <option value="road_damage">Road Damage</option>
                                <option value="cracks">Road Cracks</option>
                                <option value="erosion">Road Erosion</option>
                                <option value="flooding">Street Flooding</option>
                                <option value="debris">Road Debris</option>
                                <option value="shoulder_damage">Shoulder Damage</option>
                                <option value="marking_fade">Faded Road Markings</option>
                            </optgroup>
                        </select>
                        <label for="severity">Severity</label>
                        <select id="severity" name="severity" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="severe">Severe</option>
                        </select>
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="3" required placeholder="Describe the issue..."></textarea>
                        <label for="report-images">Upload Photos</label>
                        <button type="button" id="add-photos-btn" class="t-gradient-primary" style="padding:8px 16px;border:none;border-radius:6px;cursor:pointer;font-size:13px;"><i class="fas fa-camera"></i> Add Photos</button>
                        <input type="file" id="report-images" name="photos[]" multiple accept="image/jpeg,image/jpg,image/png" style="display:none;" />
                        <small class="t-text-secondary" style="font-size: 12px; display: block; margin-top: 4px;">Max size: 5MB each. Formats: JPG, PNG.</small>
                        <div id="image-preview" style="margin-top: 10px; display: none;">
                            <div id="image-gallery" style="display: flex; flex-wrap: wrap; gap: 8px;"></div>
                        </div>
                        <div class="form-actions">
                            <button type="button" class="btn-action btn-secondary" id="cancel-pin-btn">Cancel</button>
                            <button type="submit" class="btn-action" id="submit-report-btn"><i class="fas fa-paper-plane"></i> Send report</button>
                        </div>
                    </form>
                </div>

                <!-- Route Planner Panel -->
                <div id="routePlannerPanel" class="tomtom-panel">
                    <h5><i class="fas fa-route"></i> Route Planner</h5>
                    <label for="routeFrom">Start Location</label>
                    <input type="text" id="routeFrom" placeholder="Click map or type address..." onclick="routeFromClick()">
                    <label for="routeTo">Destination</label>
                    <input type="text" id="routeTo" placeholder="Click map or type address..." onclick="routeToClick()">
                    <label for="routeMode">Travel Mode</label>
                    <select id="routeMode">
                        <option value="car">Car</option>
                        <option value="truck">Truck</option>
                        <option value="pedestrian">Pedestrian</option>
                        <option value="bicycle">Bicycle</option>
                    </select>
                    <div style="display:flex;gap:8px;">
                        <button class="btn-action btn-sm" onclick="planRoute()"><i class="fas fa-route"></i> Calculate Route</button>
                        <button class="btn-action btn-sm btn-secondary" onclick="clearRoute()">Clear</button>
                        <button class="btn-action btn-sm btn-secondary" onclick="closePanel('routePlannerPanel')">Close</button>
                    </div>
                    <div id="routeInfo" class="route-info-box" style="display:none;"></div>
                </div>

                <!-- EV Charging Panel -->
                <div id="evChargingPanel" class="tomtom-panel">
                    <h5><i class="fas fa-charging-station"></i> EV Charging Stations</h5>
                    <p class="t-text-secondary" style="font-size:12px;">Search for EV charging stations near the map center.</p>
                    <div style="display:flex;gap:8px;">
                        <button class="btn-action btn-sm" onclick="findEVStations()"><i class="fas fa-search"></i> Find Nearby</button>
                        <button class="btn-action btn-sm btn-secondary" onclick="closePanel('evChargingPanel')">Close</button>
                    </div>
                    <div id="evResults" class="route-info-box" style="display:none;"></div>
                </div>

                <!-- OSM Public Transport Routes Panel -->
                <div id="ptRoutesPanel" class="tomtom-panel">
                    <h5><i class="fas fa-route"></i> Public Transport Routes (OSM)</h5>
                    <p class="t-text-secondary" style="font-size:12px;">Select a route to show it on the map. Data from OpenStreetMap.</p>
                    <label for="ptRouteSearch">Search routes</label>
                    <input type="text" id="ptRouteSearch" placeholder="Name, from, to, ref..." oninput="renderPtRouteList()">
                    <div id="ptRouteListMeta" class="t-text-secondary" style="font-size:11px;margin-top:6px;"></div>
                    <div id="ptRouteList" class="pt-route-list">
                        <div class="pt-route-status">Loading routes…</div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="btn-action btn-sm btn-secondary" type="button" onclick="clearSelectedOsmRoute()"><i class="fas fa-eraser"></i> Clear map</button>
                        <button class="btn-action btn-sm btn-secondary" type="button" onclick="closePanel('ptRoutesPanel')">Close</button>
                    </div>
                </div>

                <!-- Commute Planner (Sakay deep link) -->
                <div id="commutePlannerPanel" class="tomtom-panel">
                    <h5><i class="fas fa-bus"></i> Commute Planner</h5>
                    <p class="t-text-secondary" style="font-size:12px;">Pick origin and destination on the map, then open directions on Sakay.ph.</p>
                    <div id="commutePlannerStatus" class="route-info-box">Click the map to set the <strong>origin</strong>.</div>
                    <div id="commutePlannerCoords" class="t-text-secondary" style="font-size:11px;margin-top:8px;display:none;"></div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <button class="btn-action btn-sm" type="button" id="openSakayTripBtn" onclick="openSakayTrip()" disabled><i class="fas fa-external-link-alt"></i> Open in Sakay</button>
                        <button class="btn-action btn-sm btn-secondary" type="button" onclick="resetCommutePlanner()"><i class="fas fa-redo"></i> Reset</button>
                        <button class="btn-action btn-sm btn-secondary" type="button" onclick="closeCommutePlanner()"><i class="fas fa-times"></i> Close</button>
                    </div>
                </div>

                <div id="mapSearchResults" class="search-results-dropdown"></div>
            </div>

            <!-- Sidebar -->
            <div class="sidebar-section">
                <!-- Active Alerts -->
                <div class="info-card">
                    <h3 class="info-card-title">
                        <span class="title-icon"><i class="fas fa-bell"></i></span>
                        Active Alerts
                    </h3>
                    <div class="alert-list">
                        <?php foreach ($alerts as $alert): ?>
                        <div class="alert-item <?php echo $alert['priority'] == 'medium' ? 'warning' : ''; ?>">
                            <div class="alert-icon">
                                <i class="fas fa-<?php echo $alert['priority'] == 'high' ? 'car-crash' : 'tools'; ?>"></i>
                            </div>
                            <div class="alert-content">
                                <div class="alert-title"><?php echo htmlspecialchars($alert['title']); ?></div>
                                <div class="alert-time"><?php echo htmlspecialchars($alert['time']); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Reports Table -->
        <div class="reports-table-section">
            <div class="table-header">
                <h3><span class="title-icon"><i class="fas fa-<?php echo $is_completed_projects_view ? 'circle-check' : 'table-list'; ?>"></i></span> <?php echo $is_completed_projects_view ? 'Completed Projects' : 'Active Monitoring Reports'; ?></h3>
                <div class="table-header-right">
                    <?php if (!$is_completed_projects_view): ?>
                    <select class="filter-select" id="statusFilter" onchange="filterReports()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="in-progress" <?php echo $status_filter === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                    </select>
                    <?php endif; ?>
                    <select class="filter-select" id="typeFilter" onchange="filterReportsBySource()">
                        <option value="all">All Types</option>
                        <?php if (!$is_road_supervisor && !$is_road_monitoring_officer): ?>
                        <option value="citizen">Citizen Reports</option>
                        <?php endif; ?>
                        <?php if (!$is_transport_supervisor): ?>
                        <option value="cimm">CIMM Reports</option>
                        <?php endif; ?>
                        <?php if (!$is_transport_supervisor && !$is_road_supervisor): ?>
                        <option value="infrastructure">Infrastructure Projects</option>
                        <?php endif; ?>
                        <option value="lgu">LGU Monitoring Reports</option>
                    </select>
                    <button type="button"
                        class="btn-your-reports"
                        id="yourReportsBtn"
                        onclick="toggleYourReports()"
                        title="<?php echo !empty($your_reports_only) ? 'Show all reports' : 'Show only reports you are handling'; ?>">
                        <?php if (!empty($your_reports_only)): ?>
                        <i class="fas fa-list"></i> All Reports
                        <?php else: ?>
                        <i class="fas fa-user-check"></i> Your Reports
                        <?php endif; ?>
                    </button>
                    <button type="button" class="btn-your-reports" onclick="resetFilters()" title="Reset Filters">
                        <i class="fas fa-arrow-clockwise"></i> Reset
                    </button>
                    <input type="text" class="road-search" placeholder="Search by title or ID..." id="reportSearchInput" oninput="filterReportsTable(this.value)">
                </div>
            </div>
            <div class="reports-table-wrap<?php echo $show_public_column ? ' completed-reports-scroll' : ''; ?>">
                <table id="recentReportsTable">
                    <thead>
                        <tr>
                            <th>Report ID</th>
                            <th>Title</th>
                            <th>Source</th>
                            <?php if ($show_category_column): ?>
                            <th>Category</th>
                            <?php endif; ?>
                            <?php if (!$hide_status_column): ?>
                            <th>Status</th>
                            <?php endif; ?>
                            <th>Assignment</th>
                            <th>Priority</th>
                            <th>Date</th>
                            <th>CIMM Verification</th>
                            <?php if ($show_public_column): ?>
                            <?php echo completed_projects_public_column_header_html(); ?>
                            <?php endif; ?>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_reports)): ?>
                        <tr><td colspan="<?php echo $table_colspan; ?>" class="empty-cell"><i class="fas fa-inbox"></i> No reports yet.</td></tr>
                        <?php else: ?>
                        <?php $source_labels = [
                            'lgu' => 'LGU Monitoring',
                            'citizen' => 'Citizen',
                            'cimm' => 'CIMM',
                            'infrastructure' => 'Infrastructure Projects',
                        ]; ?>
                        <?php foreach ($recent_reports as $rr): ?>
                        <?php $rr_source_key = $rr['source'] ?? 'citizen'; ?>
                        <?php $rr_source_label = $source_labels[$rr_source_key] ?? ucfirst($rr_source_key); ?>
                        <?php $rr_details = [
                            'id' => $rr['id'],
                            'report_id' => $rr['report_id'],
                            'title' => $rr['title'],
                            'source' => $rr_source_label,
                            'report_type' => $rr['report_type'],
                            'report_category' => $rr['report_category'],
                            'status' => $rr['status'],
                            'assignment_status' => $rr['assignment_status'] ?? 'unassigned',
                            'assignment_officer' => $rr['assignment_officer'] ?? '',
                            'assigned_by' => $rr['assigned_by'] ?? '',
                            'assigned_by_id' => (int)($rr['assigned_by_id'] ?? 0),
                            'can_manage_as_supervisor' => !empty($rr['can_manage_as_supervisor']),
                            'priority' => $rr['priority'],
                            'severity' => $rr['severity'],
                            'created_at' => $rr['created_at'],
                            'description' => $rr['description'],
                            'latitude' => $rr['latitude'],
                            'longitude' => $rr['longitude'],
                            'location' => $rr['location'],
                            'reporter_name' => $rr['reporter_name'],
                            'created_by_name' => $rr['creator_full_name'] ?? '',
                            'attachments' => $rr['attachments'],
                            'image_path' => $rr['image_path'],
                            'cimm_status' => $rr['cimm_status'] ?? '',
                            'cimm_sync_status' => $rr['cimm_sync_status'] ?? '',
                            'cimm_verified_at' => $rr['cimm_verified_at'] ?? '',
                            'cimm_verified_by' => $rr['cimm_verified_by'] ?? '',
                            'approval_status' => $rr['approval_status'] ?? '',
                            'verification_status' => $rr['verification_status'] ?? '',
                            'engineer' => $rr['engineer'] ?? ($rr['cimm_engineer_name'] ?? ''),
                            'budget_allocation' => $rr['budget_allocation'] ?? ($rr['cimm_budget'] ?? ''),
                            'table' => $rr['_source_table'] ?? 'road_transportation_reports',
                        ];
                        if ($is_road_supervisor) {
                            // Report Creator Information — Road Supervisor portal only.
                            $rr_details['creator_full_name'] = $rr['creator_full_name'] ?? '';
                            $rr_details['creator_phone'] = $rr['creator_phone'] ?? '';
                            $rr_details['creator_email'] = $rr['creator_email'] ?? '';
                        }
                        $rr_details_json = htmlspecialchars(json_encode($rr_details), ENT_QUOTES, 'UTF-8');
                        // Read-only use of the request status already annotated above.
                        $rr_await_transparency = (($rr['transparency_request_status'] ?? '') === 'pending');
                        $rr_no_update = !$is_completed_projects_view && !empty($rr['no_update_stale']);
                        $rr_row_class = 'report-table-row'
                            . ($rr_await_transparency ? ' transparency-flagged' : '')
                            . ($rr_no_update ? ' no-update-flagged' : ''); ?>
                         <tr class="<?php echo $rr_row_class; ?>" data-id="<?php echo $rr['id']; ?>" data-title="<?php echo htmlspecialchars(strtolower($rr['title'] ?? '')); ?>" data-report-id="<?php echo htmlspecialchars(strtolower($rr['report_id'] ?? '')); ?>" data-status="<?php echo $rr['status'] ?? 'pending'; ?>" data-source="<?php echo $rr_source_key; ?>" data-details='<?php echo $rr_details_json; ?>'>
                            <td class="mono-id"><?php if ($rr_no_update): ?><span class="no-update-flag" title="No progress update for 10 days or more" role="img" aria-label="No progress update for 10 days or more"><i class="fas fa-clock"></i></span><?php endif; ?><?php if ($rr_await_transparency): ?><span class="transparency-await-icon" title="Awaiting your Transparency Upload decision" role="img" aria-label="Awaiting Transparency Upload decision"><i class="fas fa-bullhorn"></i></span><?php endif; ?><?php echo htmlspecialchars($rr['report_id'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($rr['title'] ?? 'Untitled'); ?></td>
                            <td><span class="badge badge-source badge-source-<?php echo htmlspecialchars(preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$rr_source_key))); ?>"><?php echo htmlspecialchars($rr_source_label); ?></span></td>
                            <?php if ($show_category_column): ?>
                            <?php $rr_category_label = completed_project_category_label($rr['report_category'] ?? '', $rr_source_key); ?>
                            <td><span class="category-badge <?php echo $rr_category_label === 'Transportation' ? 'category-transportation' : 'category-road'; ?>"><?php echo $rr_category_label; ?></span></td>
                            <?php endif; ?>
                            <?php if (!$hide_status_column): ?>
                            <td>
                                <?php if ($is_road_supervisor || $is_road_monitoring_officer): ?>
                                    <span class="db-badge <?php echo rmo_db_status_class($rr['status'] ?? 'pending'); ?>"><?php echo ucfirst(str_replace('-',' ',$rr['status'] ?? 'pending')); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-<?php echo strtolower(str_replace(' ', '-', $rr['status'] ?? 'pending')); ?>"><?php echo ucfirst(str_replace('-',' ',$rr['status'] ?? 'pending')); ?></span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><?php if (($rr['assignment_status'] ?? 'unassigned') === 'assigned'): ?>
                                <?php if (!empty($rr['assignment_officer'])): ?>
                                    <span class="badge assignment-badge assignment-assigned"><?php echo htmlspecialchars($rr['assignment_officer']); ?></span>
                                <?php else: ?>
                                    <span class="badge assignment-badge assignment-assigned">Assigned</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge assignment-badge assignment-unassigned">Unassigned</span>
                            <?php endif; ?></td>
                            <td>
                                <?php if ($is_road_supervisor || $is_road_monitoring_officer): ?>
                                    <?php $rmo_pb = rmo_db_priority_badge($rr['priority'] ?? 'low'); ?>
                                    <span class="db-badge <?php echo $rmo_pb[0]; ?>"><i class="fas <?php echo $rmo_pb[1]; ?>"></i> <?php echo ucfirst($rr['priority'] ?? 'low'); ?></span>
                                <?php else: ?>
                                    <span class="badge badge-<?php echo strtolower($rr['priority'] ?? 'low'); ?>"><?php echo ucfirst($rr['priority'] ?? 'low'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="muted-date"><?php echo date('M d, Y H:i', strtotime($rr['created_at'] ?? 'now')); ?></td>
                            <td>
                                <?php if (($rr['source'] ?? '') === 'cimm'): ?>
                                    <?php if (strtolower($rr['approval_status'] ?? '') === 'approved'): ?>
                                        <span class="cimm-verify-badge cimm-verify-badge-verified" title="Approved by CIMM">
                                            <i class="fas fa-check-circle"></i> Approved
                                        </span>
                                    <?php else: ?>
                                        <span class="cimm-verify-badge cimm-verify-badge-none">—</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <?php if (($rr['source'] ?? '') === 'lgu' && ($rr['report_category'] ?? '') === 'road' && strtolower(trim((string)($rr['cimm_status'] ?? ''))) === 'scheduled'): ?>
                                        <span class="cimm-verify-badge cimm-verify-badge-verified" title="Approved by CIMM">
                                            <i class="fas fa-check-circle"></i> Approved
                                        </span>
                                    <?php elseif (strtolower($rr['cimm_sync_status'] ?? '') === 'verified'): ?>
                                        <span class="cimm-verify-badge cimm-verify-badge-verified" title="Approved by CIMM">
                                            <i class="fas fa-check-circle"></i> Approved
                                        </span>
                                    <?php else: ?>
                                        <span class="cimm-verify-badge cimm-verify-badge-none">—</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <?php if ($show_public_column): ?>
                            <?php echo completed_projects_public_column_cell_html($rr); ?>
                            <?php endif; ?>
                            <td class="action-cell">
                                <button class="table-action-btn btn-view" title="View Details" onclick="viewReportDetails(<?php echo (int)$rr['id']; ?>, '<?php echo htmlspecialchars($rr['source'] ?? '', ENT_QUOTES); ?>')"><i class="fas fa-eye"></i> View</button>
                                <button class="table-action-btn view-map" onclick="focusReportOnMap(<?php echo (int)$rr['id']; ?>, '<?php echo htmlspecialchars($rr['source'] ?? '', ENT_QUOTES); ?>')"><i class="fas fa-map-pin"></i> Map</button>
                                <button class="table-action-btn btn-updates" onclick="viewReportUpdates(<?php echo (int)$rr['id']; ?>, '<?php echo htmlspecialchars($rr['report_type'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rr['source'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($rr['status'] ?? '', ENT_QUOTES); ?>')"><i class="fas fa-timeline"></i> Updates</button>
                                <?php
                                $rr_can_archive = strtolower((string)($rr['status'] ?? '')) === 'completed'
                                    && in_array($_SESSION['role'] ?? '', ['system_admin', 'road_ops_supervisor', 'trans_ops_supervisor'], true)
                                    && (!in_array($_SESSION['role'] ?? '', ['road_ops_supervisor', 'trans_ops_supervisor'], true)
                                        || !empty($rr['can_manage_as_supervisor']));
                                ?>
                                <?php if ($rr_can_archive): ?>
                                <button class="table-action-btn btn-archive" title="Archive" onclick="archiveReport(<?php echo (int)$rr['id']; ?>, '<?php echo htmlspecialchars($rr['source'] ?? '', ENT_QUOTES); ?>')"><i class="fas fa-archive"></i> Archive</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="text-align:center;padding:15px;">
                <button id="loadMoreReportsBtn" class="btn-secondary-custom" onclick="loadMoreReports()" style="padding:10px 20px;">
                    <i class="fas fa-plus"></i> Load More Reports
                </button>
            </div>
        </div>

    </div>

    <script>
        // Road Supervisor portal only: appends "(Near <landmark>)" to the
        // detected report location using the nearest TomTom point of interest.
        const IS_ROAD_SUPERVISOR = <?php echo $is_road_supervisor ? 'true' : 'false'; ?>;
        const IS_TRANS_SUPERVISOR = <?php echo $is_trans_ops_supervisor ? 'true' : 'false'; ?>;
        const IS_SYSTEM_ADMIN = <?php echo $is_system_admin ? 'true' : 'false'; ?>;
        const IS_COMPLETED_PROJECTS_VIEW = <?php echo $is_completed_projects_view ? 'true' : 'false'; ?>;
        const YOUR_REPORTS_ONLY = <?php echo !empty($your_reports_only) ? 'true' : 'false'; ?>;
        const HIDE_STATUS_COLUMN = <?php echo $hide_status_column ? 'true' : 'false'; ?>;
        const SHOW_CATEGORY_COLUMN = <?php echo $show_category_column ? 'true' : 'false'; ?>;
        const SHOW_PUBLIC_COLUMN = <?php echo $show_public_column ? 'true' : 'false'; ?>;
        const TABLE_COLSPAN = <?php echo $table_colspan; ?>;
        const COMPLETED_PROJECTS_PUBLIC_STATUS_MAP = <?php
            echo json_encode(
                function_exists('completed_projects_public_column_js_map')
                    ? completed_projects_public_column_js_map()
                    : new stdClass(),
                JSON_UNESCAPED_UNICODE
            );
        ?>;

        function completedProjectCategoryLabel(report) {
            var cat = String((report && (report.report_category
                || (report.details && report.details.report_category))) || '').toLowerCase();
            if (cat === 'transportation') return 'Transportation';
            if (cat === 'road') return 'Road';
            var src = String((report && report.source) || '').toLowerCase();
            if (src === 'cimm' || src === 'infrastructure' || src === 'maintenance') return 'Road';
            return 'Road';
        }

        function completedProjectCategoryBadge(report) {
            var label = completedProjectCategoryLabel(report);
            var cls = label === 'Transportation' ? 'category-transportation' : 'category-road';
            return '<span class="category-badge ' + cls + '">' + label + '</span>';
        }

        function publicStatusMeta(status) {
            var key = String(status || 'awaiting').toLowerCase();
            var map = COMPLETED_PROJECTS_PUBLIC_STATUS_MAP || {};
            var entry = map[key] || map.awaiting || {
                label: 'Awaiting',
                class: 'pt-status-awaiting',
                title: 'No public transparency request has been made yet.'
            };
            return {
                label: entry.label || 'Awaiting',
                cls: entry.class || entry.cls || 'pt-status-awaiting',
                title: entry.title || ''
            };
        }

        function publicStatusBadge(report) {
            var meta = publicStatusMeta(report && report.public_transparency_status);
            return '<span class="pt-status-badge ' + meta.cls + '" title="' + meta.title + '">' + meta.label + '</span>';
        }

        function isNoUpdateStale(report) {
            if (IS_COMPLETED_PROJECTS_VIEW) return false;
            return !!(report && report.no_update_stale);
        }

        function noUpdateFlagIcon(report) {
            if (!isNoUpdateStale(report)) return '';
            return '<span class="no-update-flag" title="No progress update for 10 days or more"'
                + ' role="img" aria-label="No progress update for 10 days or more">'
                + '<i class="fas fa-clock"></i></span>';
        }

        function clearNoUpdateFlagOnRow(reportId) {
            var row = document.querySelector('#recentReportsTable .report-table-row[data-id="' + reportId + '"]');
            if (!row) return;
            row.classList.remove('no-update-flagged');
            var icon = row.querySelector('.no-update-flag');
            if (icon) icon.remove();
        }

        function submissionsListApiUrl(offset, limit, status, type) {
            var statusVal = status || (IS_COMPLETED_PROJECTS_VIEW ? 'completed' : 'all');
            var url = '../api/get_recent_submissions_paginated.php?offset=' + encodeURIComponent(offset)
                + '&limit=' + encodeURIComponent(limit)
                + '&status=' + encodeURIComponent(statusVal)
                + '&type=' + encodeURIComponent(type || 'all');
            if (IS_COMPLETED_PROJECTS_VIEW) {
                url += '&completed_only=1';
            }
            if (typeof YOUR_REPORTS_ONLY !== 'undefined' && YOUR_REPORTS_ONLY) {
                url += '&mine=1';
            }
            return url;
        }

        function toggleYourReports() {
            var url = new URL(window.location.href);
            // Use the rendered filter state so officers/supervisors (default ON
            // with no mine param) correctly switch to All Reports (mine=0).
            if (typeof YOUR_REPORTS_ONLY !== 'undefined' && YOUR_REPORTS_ONLY) {
                url.searchParams.set('mine', '0');
            } else {
                url.searchParams.set('mine', '1');
            }
            window.location.href = url.toString();
        }

        // Quezon City center
        const QC_CENTER = [14.651417, 121.04917];
        const map = L.map('map').setView(QC_CENTER, 14);

        L.tileLayer('https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
            attribution: '© TomTom'
        }).addTo(map);

        const trafficLayer = L.tileLayer('https://api.tomtom.com/traffic/map/4/tile/flow/relative0/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
            attribution: '© TomTom Traffic',
            opacity: 0.7
        }).addTo(map);

        // Load Quezon City administrative boundary from GeoJSON
        const QC_BOUNDARY_DATA = (function() {
            var geojson = <?php
                $_qcPath = __DIR__ . '/../api/qc_boundary.json';
                echo file_exists($_qcPath) ? file_get_contents($_qcPath) : 'null';
            ?>;
            if (!geojson || !geojson.coordinates) return null;
            // Return array of polygon coordinate arrays (handles MultiPolygon)
            return (geojson.type === 'MultiPolygon') ? geojson.coordinates : [geojson.coordinates];
        })();
        // Leaflet display polygon (first polygon outer ring only)
        const QC_POLYGON_COORDS = QC_BOUNDARY_DATA && QC_BOUNDARY_DATA[0] && QC_BOUNDARY_DATA[0][0]
            ? QC_BOUNDARY_DATA[0][0].map(function(p) { return [p[1], p[0]]; })
            : null;
        const QC_POLYGON = QC_POLYGON_COORDS ? L.polygon(QC_POLYGON_COORDS, {
            color: '#3762c8',
            weight: 2,
            opacity: 0.8,
            fillOpacity: 0.08,
            fillColor: '#3762c8'
        }).addTo(map) : null;

        // Point-in-polygon check using ray casting (handles MultiPolygon and holes)
        function isInsideQCBounds(lat, lng) {
            if (!QC_BOUNDARY_DATA) return false;
            for (const rings of QC_BOUNDARY_DATA) {
                let polyInside = false;
                for (let r = 0; r < rings.length; r++) {
                    const ring = rings[r];
                    let ringInside = false;
                    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
                        const xi = ring[i][1], yi = ring[i][0];
                        const xj = ring[j][1], yj = ring[j][0];
                        if ((yi > lng) !== (yj > lng) && lat < (xj - xi) * (lng - yi) / (yj - yi) + xi) {
                            ringInside = !ringInside;
                        }
                    }
                    if (r === 0) {
                        polyInside = ringInside;
                    } else if (ringInside) {
                        polyInside = false;
                        break;
                    }
                }
                if (polyInside) return true;
            }
            return false;
        }

        // ====================================================================
        // GIS DISTRICT DETECTION ENGINE
        // ====================================================================
        let qcDistrictsGeoJSON = null;
        let districtsLayer = null;

        // Ray-casting point-in-polygon for GeoJSON coordinate rings
        function pointInPolygonCoords(lat, lng, coords) {
            let inside = false;
            const ring = coords[0];
            for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
                const xi = ring[i][1], yi = ring[i][0];
                const xj = ring[j][1], yj = ring[j][0];
                if ((yi > lng) !== (yj > lng) && lat < (xj - xi) * (lng - yi) / (yj - yi) + xi) {
                    inside = !inside;
                }
            }
            return inside;
        }

        function detectDistrict(lat, lng) {
            if (!qcDistrictsGeoJSON) return null;
            // Primary: try exact polygon containment
            for (const feature of qcDistrictsGeoJSON.features) {
                if (feature.geometry.type === 'Polygon') {
                    if (pointInPolygonCoords(lat, lng, feature.geometry.coordinates)) {
                        return feature.properties;
                    }
                } else if (feature.geometry.type === 'MultiPolygon') {
                    for (const poly of feature.geometry.coordinates) {
                        if (pointInPolygonCoords(lat, lng, poly)) {
                            return feature.properties;
                        }
                    }
                }
            }
            // Fallback: nearest centroid (covers gaps between district boundaries)
            let bestDist = Infinity, bestMatch = null;
            for (const feature of qcDistrictsGeoJSON.features) {
                if (!feature.properties._centroid) {
                    // Compute centroid from polygon coordinates
                    const coords = feature.geometry.type === 'Polygon'
                        ? feature.geometry.coordinates[0]
                        : feature.geometry.coordinates[0][0];
                    let slng = 0, slat = 0, cnt = 0;
                    for (const c of coords) { slng += c[0]; slat += c[1]; cnt++; }
                    feature.properties._centroid = { lng: slng / cnt, lat: slat / cnt };
                }
                const c = feature.properties._centroid;
                const dx = lng - c.lng, dy = lat - c.lat;
                const dist = dx * dx + dy * dy;
                if (dist < bestDist) { bestDist = dist; bestMatch = feature.properties; }
            }
            return bestMatch;
        }

        // Load QC Districts GeoJSON layer
        fetch('../../pages/api/qc_districts.geojson')
            .then(r => r.json())
            .then(data => {
                qcDistrictsGeoJSON = data;
                districtsLayer = L.geoJSON(data, {
                    style: function(feature) {
                        const colors = {
                            1: '#3b82f6', 2: '#8b5cf6', 3: '#10b981',
                            4: '#f59e0b', 5: '#ef4444', 6: '#06b6d4'
                        };
                        const dNum = parseInt((feature.properties.district_number || feature.properties.district || '').replace(/\D/g, '')) || 1;
                        return {
                            color: colors[dNum] || '#3762c8',
                            weight: 1.5,
                            opacity: 0.6,
                            fillOpacity: 0.04,
                            fillColor: colors[dNum] || '#3762c8',
                            dashArray: '5,5'
                        };
                    },
                    onEachFeature: function(feature, layer) {
                        layer.bindTooltip(feature.properties.district_name || feature.properties.district, {
                            sticky: true, className: 'district-tooltip'
                        });
                    }
                }).addTo(map);
                console.log('QC Districts layer loaded:', data.features.length, 'districts');
            })
            .catch(e => {
                console.warn('Could not load QC districts GeoJSON:', e);
            });

        // ====================================================================
        // REVERSE GEOCODING + FORM AUTOFILL ENGINE
        // ====================================================================
        function populateGISLocationInfo(lat, lng, districtProps, addressData, nearbyPoi) {
            const infoPanel = document.getElementById('gis-location-info');
            const detailsEl = document.getElementById('gis-location-details');
            const loadingBadge = document.getElementById('gis-loading-badge');

            let html = '';

            // District tag
            if (districtProps) {
                const dNum = districtProps.district_number || parseInt((districtProps.district || '').replace(/\D/g, '')) || '';
                const dName = districtProps.district_name || districtProps.district || '';
                document.getElementById('pin-district').value = dName;
                html += '<span class="gis-field-tag"><span class="gis-tag-label">District:</span> ' + dName + '</span>';
            } else {
                document.getElementById('pin-district').value = '';
                html += '<span class="gis-field-tag" style="background:rgba(220,53,69,0.1);color:#721c24;"><span class="gis-tag-label">District:</span> Not detected</span>';
            }

            // Nearest landmark (Road Supervisor portal only), e.g. "Near Lenie Sari-Sari Store"
            const poiName = nearbyPoi && (nearbyPoi.poi || {}).name ? nearbyPoi.poi.name : '';

            // Barangay from reverse geocoding
            let barangay = '';
            let street = '';
            let fullAddress = '';
            let municipality = '';
            if (addressData) {
                const addr = addressData.address || {};
                barangay = addr.subdivision || addr.municipalitySubdivision || addr.neighbourhood || '';
                street = addr.street || '';
                municipality = addr.municipality || '';
                const houseNum = addr.houseNumber || '';
                if (houseNum && street) {
                    fullAddress = houseNum + ' ' + street;
                } else if (street) {
                    fullAddress = street;
                } else if (addr.freeformAddress) {
                    fullAddress = addr.freeformAddress;
                }
            }
            document.getElementById('pin-barangay').value = barangay;
            document.getElementById('pin-street').value = street;
            var addressParts = [fullAddress, barangay, municipality, 'Quezon City'].filter(Boolean);
            if (poiName) {
                addressParts.push('(Near ' + poiName + ')');
            }
            document.getElementById('pin-address').value = addressParts.join(', ');

            if (barangay) {
                html += '<span class="gis-field-tag"><span class="gis-tag-label">Barangay:</span> ' + barangay + '</span>';
            }
            if (street) {
                html += '<span class="gis-field-tag"><span class="gis-tag-label">Street:</span> ' + street + '</span>';
            }
            if (municipality) {
                html += '<span class="gis-field-tag"><span class="gis-tag-label">Municipality:</span> ' + municipality + '</span>';
            }
            if (poiName) {
                html += '<span class="gis-field-tag" style="background:rgba(55,98,200,0.08);"><span class="gis-tag-label">Near:</span> ' + poiName + '</span>';
            }
            if (!fullAddress && !barangay && !street) {
                html += '<span style="font-size:11px;color:#999;">Address details unavailable for this pin location.</span>';
                document.getElementById('pin-address').value = lat.toFixed(5) + ', ' + lng.toFixed(5) + ', Quezon City' + (poiName ? ', (Near ' + poiName + ')' : '');
            }

            detailsEl.innerHTML = html;
            infoPanel.style.display = 'block';
            loadingBadge.style.display = 'none';
        }

        // Master function: detect district + geocode + populate
        function analyzePinnedLocation(lat, lng) {
            const infoPanel = document.getElementById('gis-location-info');
            const loadingBadge = document.getElementById('gis-loading-badge');
            const warningPanel = document.getElementById('gis-location-warning');

            // Check boundary first
            if (!isInsideQCBounds(lat, lng)) {
                infoPanel.style.display = 'none';
                warningPanel.style.display = 'block';
                document.getElementById('gis-warning-text').textContent = 'Pinned location is outside the covered LGU jurisdiction.';
                document.getElementById('pin-district').value = '';
                document.getElementById('pin-barangay').value = '';
                document.getElementById('pin-street').value = '';
                return;
            }
            warningPanel.style.display = 'none';
            infoPanel.style.display = 'block';
            loadingBadge.style.display = 'inline';

            // Step 1: District detection (instant, local data)
            const districtProps = detectDistrict(lat, lng);

            // Async lookups: reverse geocode + nearest landmark (Road Supervisor
            // portal only). Each resolves independently and re-renders the panel,
            // so the final render includes whatever data has arrived.
            let geocodeData = null;
            let nearbyPoi = null;

            function finalizeGISLocationInfo() {
                populateGISLocationInfo(lat, lng, districtProps, geocodeData, nearbyPoi);
            }

            // Step 2: Reverse geocode via TomTom (async)
            TomTomServices.reverseGeocodeOrbis(lat, lng).then(data => {
                geocodeData = data.data?.results?.[0] || null;
                finalizeGISLocationInfo();

                // Also update the marker popup with formatted address
                if (pinMarker && geocodeData) {
                    const addr = geocodeData.address || {};
                    const parts = [
                        addr.street && addr.houseNumber ? addr.houseNumber + ' ' + addr.street : addr.street || '',
                        addr.municipality || '',
                        addr.countrySubdivision || '',
                        addr.postalCode || ''
                    ].filter(Boolean);
                    const popupHtml = '<b>' + (parts.join(', ') || lat.toFixed(5) + ', ' + lng.toFixed(5)) + '</b>'
                        + (districtProps ? '<br><small style="color:#10b981;">' + (districtProps.district_name || districtProps.district || '') + '</small>' : '');
                    pinMarker.bindPopup(popupHtml).openPopup();
                }
            }).catch(() => {
                // Geocode failed, still show district if detected
                geocodeData = null;
                finalizeGISLocationInfo();
            });

            // Step 3 (Road Supervisor portal only): nearest landmark/POI so the
            // report location reads like "…Quezon City, (Near <landmark>)".
            if (IS_ROAD_SUPERVISOR) {
                TomTomServices.nearbySearch(lat, lng, { limit: 10, radius: 500 }).then(data => {
                    const results = data.data?.results || [];
                    nearbyPoi = results.find(function(r) {
                        return r.type === 'POI' && r.poi && r.poi.name;
                    }) || null;
                    finalizeGISLocationInfo();
                }).catch(() => {
                    nearbyPoi = null;
                    finalizeGISLocationInfo();
                });
            }
        }

        // Restrict map panning with a padded bounding box of QC
        const QC_BBOX = QC_POLYGON ? QC_POLYGON.getBounds().pad(0.15) : L.latLngBounds([[14.6, 120.97]], [[14.77, 121.16]]);
        map.setMaxBounds(QC_BBOX);
        map.setMinZoom(11);
        map.setMaxZoom(18);

        // Force map back to Quezon City if user tries to pan out
        map.on('moveend', function() {
            const center = map.getCenter();
            if (!QC_BBOX.contains(center)) {
                map.setView(QC_CENTER, 14);
                showNotification('Map view restricted to Quezon City area', 'info');
            }
        });

        let pinMarker = null;
        const reportMarkersLayer = L.layerGroup().addTo(map);
        const reportPanel = document.getElementById('report-form-panel');
        const form = document.getElementById('report-form');
        const pinLat = document.getElementById('pin-lat');
        const pinLng = document.getElementById('pin-lng');
        let allMarkerData = [];
        let allMarkerObjects = [];
        let mapFullscreen = false;
        let activeFilter = 'all';
        let autoRefreshInterval = null;


        // Load existing report markers
        function loadMarkers(filter, callback) {
            filter = filter || activeFilter;
            reportMarkersLayer.clearLayers();
            allMarkerObjects = [];
            fetch('?action=get_markers')
                .then(r => r.json())
                .then(markers => {
                    allMarkerData = markers;
                    markers.forEach(m => {
                        if (filter !== 'all') {
                            if (filter === 'high' && !['high','critical'].includes((m.severity || m.priority || '').toLowerCase())) return;
                            else if (filter !== 'high' && (m.status || '') !== filter) return;
                        }
                        const sev = (m.severity || m.priority || 'low').toLowerCase();
                        const color = (sev === 'critical' || sev === 'high') ? '#dc3545' : sev === 'medium' ? '#ffc107' : '#6c757d';
                        const icon = L.divIcon({
                            html: `<div style="background:${color};color:#fff;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;font-size:12px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);"><i class="fas fa-${m.report_type === 'road_damage' ? 'road' : 'traffic-light'}"></i></div>`,
                            className: '',
                            iconSize: [28, 28]
                        });
                        const sevLabel = m.severity || m.priority || 'low';
                        const locationTags = [];
                        if (m.detected_district) locationTags.push('<span style="color:#10b981;font-size:11px;"><i class="fas fa-map-pin"></i> ' + escapeHtml(m.detected_district) + '</span>');
                        if (m.barangay) locationTags.push('<span style="color:#3762c8;font-size:11px;">' + escapeHtml(m.barangay) + '</span>');
                        if (m.street_name) locationTags.push('<span style="color:#666;font-size:11px;">' + escapeHtml(m.street_name) + '</span>');
                        const marker = L.marker([parseFloat(m.latitude), parseFloat(m.longitude)], { icon })
                            .addTo(reportMarkersLayer)
                            .bindPopup('<b>' + escapeHtml(m.title) + '</b><br><small>' + escapeHtml(m.description || '') + '</small><br>' + locationTags.join(' &middot; ') + '<br><span style="color:' + color + '">' + sevLabel + ' &bull; ' + m.status + '</span>');
                        marker._reportId = m.id;
                        allMarkerObjects.push(marker);
                    });
                    if (callback) callback();
                })
                .catch(e => console.error('Load markers error', e));
        }
        function escapeHtml(t) { const d = document.createElement('div'); d.textContent = t || ''; return d.innerHTML; }

        // Filter map markers
        function filterMapMarkers(filter) {
            activeFilter = filter;
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            document.querySelector(`.filter-btn[data-filter="${filter}"]`).classList.add('active');
            loadMarkers(filter);
        }

        // Toggle traffic overlay layer
        let trafficVisible = true;
        function toggleTrafficLayer() {
            trafficVisible = !trafficVisible;
            if (trafficVisible) {
                trafficLayer.addTo(map);
            } else {
                map.removeLayer(trafficLayer);
            }
            if (typeof setMapToolBtnStyle === 'function') {
                setMapToolBtnStyle('toggleTrafficBtn', trafficVisible);
            } else {
                const btn = document.getElementById('toggleTrafficBtn');
                if (!btn) return;
                btn.classList.toggle('is-on', trafficVisible);
                btn.classList.toggle('is-off', !trafficVisible);
                const state = btn.querySelector('.map-tools-item-state');
                if (state) state.textContent = trafficVisible ? 'On' : 'Off';
            }
        }
        window.toggleTrafficLayer = toggleTrafficLayer;

        // Toggle map fullscreen
        function toggleMapFullscreen() {
            mapFullscreen = !mapFullscreen;
            document.body.classList.toggle('map-fullscreen-active', mapFullscreen);
            const btn = document.getElementById('fullscreenMapBtn');
            btn.innerHTML = mapFullscreen ? '<i class="fas fa-compress"></i> Exit' : '<i class="fas fa-expand"></i> Fullscreen';
            setTimeout(() => map.invalidateSize(), 300);
        }

        // Focus map on a specific report by ID (and optional source, so
        // infrastructure project_id does not collide with another table's id).
        function focusReportOnMap(reportId, source) {
            var rowSelector = '#recentReportsTable .report-table-row[data-id="' + reportId + '"]';
            if (source) rowSelector += '[data-source="' + source + '"]';
            var row = document.querySelector(rowSelector) || document.querySelector('#recentReportsTable .report-table-row[data-id="' + reportId + '"]');
            var lat = null;
            var lng = null;
            try {
                if (row && row.dataset.details) {
                    var d = JSON.parse(row.dataset.details);
                    lat = parseFloat(d.latitude);
                    lng = parseFloat(d.longitude);
                }
            } catch (e) {}

            function openAt(latVal, lngVal) {
                if (!latVal || !lngVal || isNaN(latVal) || isNaN(lngVal) || latVal === 0 || lngVal === 0) return false;
                map.setView([latVal, lngVal], 16);
                var found = allMarkerObjects.find(function(m) { return m._reportId == reportId; });
                if (found) found.openPopup();
                return true;
            }

            if (openAt(lat, lng)) return;

            const found = allMarkerObjects.find(function(m) { return m._reportId == reportId; });
            if (found) {
                map.setView(found.getLatLng(), 16);
                found.openPopup();
                return;
            }
            activeFilter = 'all';
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            const allBtn = document.querySelector('.filter-btn[data-filter="all"]');
            if (allBtn) allBtn.classList.add('active');
            fetch('?action=get_markers')
                .then(r => r.json())
                .then(markers => {
                    const report = markers.find(m => m.id == reportId);
                    if (report && report.latitude && report.longitude) {
                        const mlat = parseFloat(report.latitude);
                        const mlng = parseFloat(report.longitude);
                        if (!openAt(mlat, mlng)) {
                            showNotification('Report has no location data on the map.', 'info');
                        }
                        loadMarkers('all');
                    } else {
                        showNotification('Report has no location data on the map.', 'info');
                    }
                })
                .catch(() => showNotification('Could not load map data.', 'error'));
        }

        // Search reports table (respects source filter)
        function filterReportsTable(query) {
            const q = query.toLowerCase().trim();
            const source = document.getElementById('typeFilter').value;
            document.querySelectorAll('#recentReportsTable .report-table-row').forEach(row => {
                const title = row.dataset.title || '';
                const rid = row.dataset.reportId || '';
                const matchesSearch = !q || title.includes(q) || rid.includes(q);
                const matchesSource = source === 'all' || row.dataset.source === source;
                row.style.display = (matchesSearch && matchesSource) ? '' : 'none';
            });
        }

        function filterReports() {
            const status = document.getElementById('statusFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            if (typeof YOUR_REPORTS_ONLY !== 'undefined' && YOUR_REPORTS_ONLY) {
                url.searchParams.set('mine', '1');
            }
            window.location.href = url.toString();
        }

        function filterReportsBySource() {
            const source = document.getElementById('typeFilter').value;
            const statusFilter = IS_COMPLETED_PROJECTS_VIEW
                ? 'completed'
                : (document.getElementById('statusFilter') ? document.getElementById('statusFilter').value : 'all');
            const tableBody = document.querySelector('#recentReportsTable tbody');
            
            // Clear existing rows
            tableBody.innerHTML = '';
            
            // Show loading state
            const loadingRow = document.createElement('tr');
            loadingRow.innerHTML = '<td colspan="' + TABLE_COLSPAN + '" style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading reports...</td>';
            tableBody.appendChild(loadingRow);
            
            // Reset pagination state
            currentOffset = 0;
            hasMoreReports = true;
            isLoadingMore = false;
            
            // Fetch filtered data from API
            fetch(submissionsListApiUrl(0, 10, statusFilter, source))
                .then(response => response.json())
                .then(data => {
                    tableBody.innerHTML = ''; // Clear loading row
                    
                    if (data.success && data.reports.length > 0) {
                        data.reports.forEach(report => {
                            const row = createReportRow(report);
                            tableBody.appendChild(row);
                        });
                        
                        currentOffset = data.reports.length;
                        
                        // If we got fewer than 10 reports, hide load more button
                        if (data.reports.length < 10) {
                            hasMoreReports = false;
                            hideLoadMoreButton();
                        } else {
                            hasMoreReports = true;
                            showLoadMoreButton();
                        }
                    } else {
                        // No results
                        tableBody.innerHTML = '<tr><td colspan="' + TABLE_COLSPAN + '" style="text-align:center;padding:30px;color:#6b7280;">No reports found for this filter.</td></tr>';
                        hasMoreReports = false;
                        hideLoadMoreButton();
                    }
                })
                .catch(error => {
                    tableBody.innerHTML = '<tr><td colspan="' + TABLE_COLSPAN + '" style="text-align:center;padding:30px;color:#dc3545;">Error loading reports. Please try again.</td></tr>';
                    showNotification('Error loading filtered reports', 'error');
                });
        }

        function resetFilters() {
            if (document.getElementById('statusFilter')) {
                document.getElementById('statusFilter').value = 'all';
            }
            document.getElementById('typeFilter').value = 'all';
            document.querySelectorAll('#recentReportsTable .report-table-row').forEach(row => {
                row.style.display = '';
            });
            const url = new URL(window.location);
            url.searchParams.delete('status');
            url.searchParams.delete('mine');
            window.location.href = url.toString();
        }

        // View Details Modal helpers (mirrors report_management rm modal)
        function rmBadge(text, bg, color) {
            return '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:' + bg + ';color:' + color + ';">' + text + '</span>';
        }

        function rmInfoItem(icon, label, value) {
            var displayVal = (value && value !== '—' && value !== null) ? value : '—';
            return '<div class="rm-info-item"><div class="rm-info-icon"><i class="fas fa-' + icon + '"></i></div><div><div class="rm-info-label">' + label + '</div><div class="rm-info-value">' + displayVal + '</div></div></div>';
        }

        function openViewDetailsModal() {
            var modal = document.getElementById('viewDetailsModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeViewDetailsModal() {
            var modal = document.getElementById('viewDetailsModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
            hideTransparencyReviewActions();
        }

        // ===== ADMIN REVIEW OF A TRANSPARENCY UPLOAD REQUEST =====
        // Set only when the admin arrives from the notification deep link, and
        // scoped to the one request id / report id pair carried in the URL so a
        // decision can never land on a different project.
        var transparencyReview = null;

        function hideTransparencyReviewActions() {
            var approveBtn = document.getElementById('approveTransparencyBtn');
            var rejectBtn = document.getElementById('rejectTransparencyBtn');
            if (approveBtn) approveBtn.style.display = 'none';
            if (rejectBtn) rejectBtn.style.display = 'none';
        }

        function setTransparencyReviewBusy(busy) {
            var approveBtn = document.getElementById('approveTransparencyBtn');
            var rejectBtn = document.getElementById('rejectTransparencyBtn');
            if (approveBtn) approveBtn.disabled = busy;
            if (rejectBtn) rejectBtn.disabled = busy;
        }

        // Opens the project's details with the two review actions. Verifies the
        // request server-side first: it must still be pending and must belong to
        // the report being focused.
        function openTransparencyReview(requestId, reportId, source) {
            fetch('../api/transparency_request_api.php?action=get&request_id=' + encodeURIComponent(requestId))
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (!resp || !resp.success || !resp.data) {
                        showNotification((resp && resp.message) || 'Transparency request not found', 'error');
                        return;
                    }
                    var req = resp.data;
                    if (parseInt(req.report_id, 10) !== parseInt(reportId, 10)) {
                        showNotification('This request belongs to a different report.', 'error');
                        return;
                    }

                    viewReportDetails(reportId, source || req.report_source || '');

                    if (String(req.status) !== 'pending') {
                        showNotification('This transparency request was already '
                            + String(req.status) + '.', 'info');
                        return;
                    }

                    transparencyReview = {
                        requestId: parseInt(req.id, 10),
                        reportId: parseInt(req.report_id, 10),
                        source: req.report_source || ''
                    };
                    var approveBtn = document.getElementById('approveTransparencyBtn');
                    var rejectBtn = document.getElementById('rejectTransparencyBtn');
                    if (approveBtn) approveBtn.style.display = 'inline-flex';
                    if (rejectBtn) rejectBtn.style.display = 'inline-flex';
                    setTransparencyReviewBusy(false);
                    showNotification('Transparency upload request from '
                        + (req.requested_by_name || 'the Road Operations Supervisor')
                        + ' is awaiting your review.', 'info');
                })
                .catch(function() {
                    showNotification('Network error', 'error');
                });
        }

        function submitTransparencyDecision(decision, reason) {
            if (!transparencyReview) return;
            setTransparencyReviewBusy(true);

            var fd = new FormData();
            fd.append('action', decision);
            fd.append('request_id', transparencyReview.requestId);
            if (decision === 'reject' && reason) fd.append('reason', reason);

            fetch('../api/transparency_request_api.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        showNotification(data.message || 'Request updated', 'success');
                        transparencyReview = null;
                        hideTransparencyReviewActions();
                        // Approving continues in the transparency page, which
                        // imports this project's data for the admin to review.
                        if (data.redirect_url) {
                            setTimeout(function() { window.location.href = data.redirect_url; }, 700);
                        }
                    } else {
                        showNotification((data && data.message) || 'Failed to update the request', 'error');
                        setTransparencyReviewBusy(false);
                    }
                })
                .catch(function() {
                    showNotification('Network error', 'error');
                    setTransparencyReviewBusy(false);
                });
        }

        function approveTransparencyRequest() {
            if (!transparencyReview) return;
            if (!confirm('Approve the transparency upload request for this project? The project stays Completed.')) return;
            submitTransparencyDecision('approve');
        }

        function rejectTransparencyRequest() {
            if (!transparencyReview) return;
            var reason = prompt('Reject this transparency upload request? You may add a reason (optional):', '');
            if (reason === null) return;
            submitTransparencyDecision('reject', reason.trim());
        }

        // View Report Details Modal
        let currentRmPoint = null;
        let roadMapInstances = {};

        function openRoadPathMap(containerId, points, asLine) {
            var container = document.getElementById(containerId);
            if (!container || !points || points.length === 0) return;
            if (typeof L === 'undefined') {
                alert('Map library failed to load.');
                return;
            }

            // Make the container visible first so Leaflet measures the correct
            // size when the map is created (display:none containers report 0x0).
            container.classList.add('road-map-visible');

            var map = roadMapInstances[containerId];
            if (!map) {
                map = L.map(containerId, { zoomControl: true })
                    .setView([14.6760, 121.0437], 12);
                L.tileLayer('https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
                    attribution: '© TomTom',
                    maxZoom: 18
                }).addTo(map);
                roadMapInstances[containerId] = map;
            }

            // Remove any path/marker drawn for a previously-viewed report.
            map.eachLayer(function(layer) {
                if (layer instanceof L.Polyline || layer instanceof L.CircleMarker || layer instanceof L.Marker) {
                    map.removeLayer(layer);
                }
            });

            if (asLine && points.length >= 2) {
                L.polyline(points, { color: '#f97316', weight: 5, opacity: 0.9 }).addTo(map);
                map.fitBounds(L.latLngBounds(points).pad(0.25));
            } else {
                var pt = points[0];
                L.circleMarker(pt, { radius: 8, color: '#f97316', fillColor: '#f97316', fillOpacity: 0.85, weight: 2 }).addTo(map);
                map.setView(pt, 14);
            }

            // The modal animates open, which can leave the map with a stale
            // size; force a refresh once the transition has finished.
            setTimeout(function() {
                if (map) map.invalidateSize();
            }, 250);
        }

        function openRmMap() {
            var asLine = Array.isArray(currentRmPoint) && currentRmPoint.length >= 2;
            openRoadPathMap('rm-map-container', currentRmPoint, asLine);
        }

        function rmFormatDate(dateStr) {
            if (!dateStr) return '—';
            var d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        }

        // View Report Details Modal — fetch by source table:
        // Infrastructure Projects → ipms_road_projects
        // Citizen / LGU Monitoring → road_transportation_reports
        // CIMM → cimm_verification_reports
        function viewReportDetails(id, source) {
            const row = document.querySelector(`#recentReportsTable .report-table-row[data-id="${id}"][data-source="${source || ''}"]`)
                || document.querySelector(`#recentReportsTable .report-table-row[data-id="${id}"]`);
            if (!row || !row.dataset.details) {
                showNotification('Report details not available.', 'error');
                return;
            }
            let data;
            try { data = JSON.parse(row.dataset.details); } catch(e) {
                showNotification('Could not parse report details.', 'error');
                return;
            }

            var src = String(source || row.dataset.source || '').toLowerCase();
            var reportType = data.report_type || '';
            var table = 'road_transportation_reports';
            if (src === 'cimm' || src === 'external') {
                table = 'cimm_verification_reports';
            } else if (src === 'infrastructure' || src === 'maintenance') {
                table = 'ipms_road_projects';
                if (!reportType) reportType = 'infrastructure_issue';
            } else {
                // citizen, lgu, and any other transport-sourced rows
                table = 'road_transportation_reports';
            }

            var url = '../api/get_report_details.php?id=' + id + '&type=' + encodeURIComponent(reportType || 'transportation');
            url += '&table=' + encodeURIComponent(table);

            fetch(url)
                .then(response => response.json())
                .then(resp => {
                    if (!resp.success) {
                        showNotification('Failed to load report details', 'error');
                        return;
                    }
                    const r = resp.report;

                    var typeLabels = {
                        'traffic_jam': 'Traffic Jam',
                        'accident': 'Vehicle Accident',
                        'road_closure': 'Road Closure',
                        'traffic_light_outage': 'Traffic Light Outage',
                        'congestion': 'Heavy Congestion',
                        'parking_violation': 'Illegal Parking',
                        'public_transport_issue': 'Public Transport Issue',
                        'vehicle_breakdown': 'Vehicle Breakdown',
                        'traffic_sign_issue': 'Traffic Sign Issue',
                        'potholes': 'Potholes',
                        'road_damage': 'Road Damage',
                        'cracks': 'Road Cracks',
                        'erosion': 'Road Erosion',
                        'flooding': 'Street Flooding',
                        'debris': 'Road Debris',
                        'shoulder_damage': 'Shoulder Damage',
                        'marking_fade': 'Faded Road Markings',
                        'infrastructure_issue': 'Infrastructure Issue',
                        'street_light': 'Street Light',
                        'maintenance': 'Maintenance',
                        'other': 'Other'
                    };

                    var statusStyles = {
                        'pending':    {bg:'rgba(251,191,36,0.15)', color:'#f59e0b'},
                        'approved':   {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                        'completed':  {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                        'cancelled':  {bg:'rgba(220,53,69,0.15)',  color:'#ef4444'},
                        'in-progress':{bg:'rgba(59,130,246,0.15)', color:'#3b82f6'},
                        'resolved':   {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'}
                    };
                    var pStyles = {
                        'high':   {bg:'rgba(220,53,69,0.15)', color:'#ef4444'},
                        'medium': {bg:'rgba(251,191,36,0.15)', color:'#f59e0b'},
                        'low':    {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'}
                    };

                    // Header
                    document.getElementById('rm-report-id').textContent = 'Report #' + (r.report_id || '—');
                    document.getElementById('rm-title').textContent = r.title || '—';

                    var st = (r.status || 'pending').toLowerCase();
                    var ss = statusStyles[st] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};
                    var pp = (r.priority || 'medium').toLowerCase();
                    var ps = pStyles[pp] || {bg:'rgba(107,114,128,0.15)', color:'#6b7280'};

                    var badgesHtml = rmBadge(r.status || '—', ss.bg, ss.color);
                    badgesHtml += rmBadge(r.priority || '—', ps.bg, ps.color);
                    var reportTypeLabel = typeLabels[r.report_type] || r.report_type || '—';
                    var categoryLabel = completedProjectCategoryLabel({
                        report_category: r.report_category || data.report_category,
                        source: src || r.source || data.source
                    });
                    if (IS_COMPLETED_PROJECTS_VIEW) {
                        badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:'
                            + (categoryLabel === 'Transportation' ? 'rgba(14,165,233,0.16);color:#0369a1;' : 'rgba(55,98,200,0.12);color:#3762c8;')
                            + '">' + categoryLabel + '</span>';
                    } else if (reportTypeLabel !== '—') {
                        badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(55,98,200,0.12);color:#3762c8;">' + reportTypeLabel + '</span>';
                    }
                    document.getElementById('rm-badges').innerHTML = badgesHtml;

                    // Report Information
                    var reportGrid = '';
                    if (IS_COMPLETED_PROJECTS_VIEW) {
                        reportGrid += rmInfoItem('folder', 'Category', categoryLabel);
                    } else {
                        reportGrid += rmInfoItem('folder', 'Report Type', reportTypeLabel);
                    }
                    reportGrid += rmInfoItem('calendar-alt', 'Created Date', rmFormatDate(r.created_at));
                    reportGrid += rmInfoItem('sync-alt', 'Last Updated', rmFormatDate(r.updated_at));
                    if (r.due_date) {
                        reportGrid += rmInfoItem('clock', 'Due Date', rmFormatDate(r.due_date));
                    }
                    if (r.severity) {
                        reportGrid += rmInfoItem('exclamation-circle', 'Severity', r.severity);
                    }
                    document.getElementById('rm-report-grid').innerHTML = reportGrid;

                    // Source & Department
                    var sourceGrid = '';
                    var sourceLabels = {
                        'lgu': 'LGU Monitoring',
                        'citizen': 'Citizen',
                        'cimm': 'CIMM',
                        'infrastructure': 'Infrastructure Projects'
                    };
                    var sourceLabel = sourceLabels[r.source] || (r.report_source === 'local' ? 'LGU Monitoring' : 'Citizen');
                    sourceGrid += rmInfoItem('server', 'Source', sourceLabel);
                    sourceGrid += rmInfoItem('building', 'Department', r.department);
                    if (r.assignment_officer || r.assigned_to) {
                        sourceGrid += rmInfoItem('user-cog', 'Assigned To', r.assignment_officer || r.assigned_to);
                    }
                    if (r.assigned_by) {
                        sourceGrid += rmInfoItem('user-tie', 'Assigned By', r.assigned_by);
                    }
                    if (r.created_by_name) {
                        sourceGrid += rmInfoItem('user', 'Created By', r.created_by_name);
                    }
                    if (r.reporter_name) {
                        sourceGrid += rmInfoItem('user', 'Reported By', r.reporter_name);
                    }
                    if (r.approved_at) {
                        sourceGrid += rmInfoItem('thumbs-up', 'Approved At', rmFormatDate(r.approved_at));
                    }
                    if (r.rejected_at) {
                        sourceGrid += rmInfoItem('thumbs-down', 'Rejected At', rmFormatDate(r.rejected_at));
                    }
                    if (r.report_category === 'road') {
                        var engName = r.cimm_engineer_name || r.engineer || '';
                        if (engName) {
                            sourceGrid += rmInfoItem('hard-hat', 'CIMM Engineer', engName);
                        }
                        var budgetRaw = (r.cimm_budget && Number(r.cimm_budget) > 0)
                            ? r.cimm_budget
                            : (r.budget_allocation && Number(r.budget_allocation) > 0 ? r.budget_allocation : 0);
                        if (budgetRaw) {
                            sourceGrid += rmInfoItem('money-bill-wave', 'CIMM Budget Allocation', '₱ ' + Number(budgetRaw).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
                        }
                    }
                    document.getElementById('rm-source-grid').innerHTML = sourceGrid;

                    // Report Creator Information — Road Supervisor portal only.
                    var creatorSection = document.getElementById('rm-creator-section');
                    if (creatorSection) {
                        if (IS_ROAD_SUPERVISOR && (r.creator_full_name || data.creator_full_name)) {
                            var creatorGrid = '';
                            creatorGrid += rmInfoItem('user', 'Full Name', r.creator_full_name || data.creator_full_name);
                            creatorGrid += rmInfoItem('phone', 'Contact Number', r.creator_phone || data.creator_phone);
                            creatorGrid += rmInfoItem('envelope', 'Email', r.creator_email || data.creator_email);
                            document.getElementById('rm-creator-grid').innerHTML = creatorGrid;
                            creatorSection.style.display = '';
                        } else {
                            creatorSection.style.display = 'none';
                        }
                    }

                    // Location — Infrastructure Projects use start/end addresses
                    var locationGrid = '';
                    if (src === 'infrastructure' || src === 'maintenance') {
                        locationGrid += rmInfoItem('map-marker-alt', 'Start Address', r.start_address || '—');
                        locationGrid += rmInfoItem('map-marker', 'End Address', r.end_address || '—');
                        locationGrid += rmInfoItem('map-pin', 'District', r.district || r.detected_district);
                    } else {
                        var locVal = r.location || '—';
                        if (r.latitude && r.longitude && r.latitude != 0 && r.longitude != 0) {
                            locVal += '<br><a href="https://www.openstreetmap.org/?mlat=' + r.latitude + '&mlon=' + r.longitude + '&zoom=15" target="_blank" style="color:#3762c8;font-size:12px;text-decoration:none;"><i class="fas fa-external-link-alt" style="font-size:10px;"></i> View on Map</a>';
                        }
                        locationGrid += '<div class="rm-info-item rm-info-value-full"><div class="rm-info-icon"><i class="fas fa-map-marker-alt"></i></div><div><div class="rm-info-label">Location</div><div class="rm-info-value">' + locVal + '</div></div></div>';
                        locationGrid += rmInfoItem('map-pin', 'District', r.detected_district || r.district || r.cimm_district);
                    }
                    document.getElementById('rm-location-grid').innerHTML = locationGrid;

                    // View Map: polyline for IPMS projects, else single lat/lng point
                    currentRmPoint = null;
                    if ((src === 'infrastructure' || src === 'maintenance') && Array.isArray(r.polyline) && r.polyline.length >= 2) {
                        currentRmPoint = r.polyline.map(function(pt) { return [pt[0], pt[1]]; });
                    } else if (r.latitude && r.longitude && r.latitude != 0 && r.longitude != 0) {
                        currentRmPoint = [[parseFloat(r.latitude), parseFloat(r.longitude)]];
                    }
                    var rmMapBtn = document.getElementById('rm-view-map-btn');
                    if (rmMapBtn) {
                        rmMapBtn.style.display = currentRmPoint ? '' : 'none';
                        if (currentRmPoint && currentRmPoint.length >= 2 && (src === 'infrastructure' || src === 'maintenance')) {
                            rmMapBtn.onclick = function() { openRoadPathMap('rm-map-container', currentRmPoint, true); };
                        } else if (currentRmPoint) {
                            rmMapBtn.onclick = function() { openRoadPathMap('rm-map-container', currentRmPoint, false); };
                        }
                    }
                    var rmMapContainer = document.getElementById('rm-map-container');
                    if (rmMapContainer) rmMapContainer.classList.remove('road-map-visible');

                    // Description
                    document.getElementById('rm-description').textContent = r.description || 'No description provided.';

                    // Attachments
                    var images = [];
                    var seenPaths = new Set();
                    if (r.image_path && r.image_path !== '0' && r.image_path !== 'null') {
                        images.push('../../' + r.image_path);
                        seenPaths.add(r.image_path);
                    }
                    if (r.attachments && typeof r.attachments === 'string') {
                        try {
                            var parsed = JSON.parse(r.attachments);
                            if (Array.isArray(parsed)) {
                                parsed.forEach(function(a) {
                                    var p = a.file_path || a.file || '';
                                    if (p && (a.type === 'image' || !a.type) && !seenPaths.has(p)) {
                                        images.push('../../' + p);
                                        seenPaths.add(p);
                                    }
                                });
                            }
                        } catch(e) {}
                    }
                    if (r.update_media && Array.isArray(r.update_media)) {
                        r.update_media.forEach(function(m) {
                            var p = m.file_path || '';
                            if (p && !seenPaths.has(p) && m.file_type !== 'video') {
                                images.push('../../' + p);
                                seenPaths.add(p);
                            }
                        });
                    }
                    var attachHtml = '';
                    if (images.length > 0) {
                        attachHtml = '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
                        images.forEach(function(path) {
                            attachHtml += '<div style="border-radius:8px;overflow:hidden;max-width:200px;"><img src="' + path + '" alt="Report Photo" style="width:100%;height:auto;cursor:pointer;" onclick="openLightbox(this.src)" loading="lazy" onerror="this.style.display=\'none\'"></div>';
                        });
                        attachHtml += '</div>';
                    } else {
                        attachHtml = '<div style="padding:8px 0;color:#9ca3af;font-size:14px;">No attachments.</div>';
                    }
                    document.getElementById('rm-attachments').innerHTML = attachHtml;

                    // Timeline
                    var timelineGrid = '';
                    timelineGrid += rmInfoItem('calendar-check', 'Created', rmFormatDate(r.created_at));
                    if (r.approved_at) {
                        timelineGrid += rmInfoItem('thumbs-up', 'Approved', rmFormatDate(r.approved_at));
                    }
                    if (r.rejected_at) {
                        timelineGrid += rmInfoItem('thumbs-down', 'Rejected', rmFormatDate(r.rejected_at));
                    }
                    if (r.completed_at) {
                        timelineGrid += rmInfoItem('check-circle', 'Completed', rmFormatDate(r.completed_at));
                    }
                    if (r.updated_at) {
                        timelineGrid += rmInfoItem('edit', 'Last Updated', rmFormatDate(r.updated_at));
                    }
                    document.getElementById('rm-timeline-grid').innerHTML = timelineGrid;

                    openViewDetailsModal();
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error loading report details', 'error');
                });
        }

        function openLightbox(src) {
            const lb = document.getElementById('lightboxOverlay');
            document.getElementById('lightboxImage').src = src;
            lb.classList.add('show');
        }

        // Start auto-refresh
        function startAutoRefresh() {
            if (autoRefreshInterval) clearInterval(autoRefreshInterval);
            autoRefreshInterval = setInterval(() => {
                loadMarkers(activeFilter);
                if (typeof window.loadAccidentPins === 'function') window.loadAccidentPins(true);
            }, 30000);
        }

        // Show all road + transportation issue types once a report type is chosen.
        // Report type (Road / Transportation) does not filter the issue-type list.
        function updateSpecificTypes() {
            const issueType = document.getElementById('issue-type').value;
            const specificTypeLabel = document.getElementById('specific-type-label');
            const specificType = document.getElementById('specific-type');

            if (issueType === 'transportation' || issueType === 'roads') {
                specificTypeLabel.style.display = 'block';
                specificType.style.display = 'block';
                specificType.required = true;
            } else {
                specificTypeLabel.style.display = 'none';
                specificType.style.display = 'none';
                specificType.required = false;
                specificType.value = '';
            }
        }

        // Map click: place pin, show form, and run full GIS analysis
        // Skip while a tool (Route Planner / Commute Planner / etc.) owns map clicks.
        map.on('click', function(e) {
            if (typeof mapClickHandler === 'function' && mapClickHandler) return;
            if (window.suppressMapReportPin) return;

            const { lat, lng } = e.latlng;
            
            // Check if clicked location is within Quezon City polygon
            if (!isInsideQCBounds(lat, lng)) {
                showNotification('Please select a location within Quezon City only.', 'error');
                return;
            }
            
            if (pinMarker) map.removeLayer(pinMarker);
            pinMarker = L.marker([lat, lng], {
                draggable: true
            }).addTo(map);
            
            pinLat.value = lat;
            pinLng.value = lng;
            reportPanel.style.display = 'block';
            document.getElementById('gis-location-warning').style.display = 'none';
            form.reset();
            pinLat.value = lat;
            pinLng.value = lng;
            document.getElementById('severity').value = 'medium';
            updateSpecificTypes();
            
            // Run full GIS analysis: district detection + reverse geocoding
            analyzePinnedLocation(lat, lng);
            
            // Re-analyze on marker drag
            pinMarker.on('dragend', function() {
                const pos = pinMarker.getLatLng();
                
                // Validate dragged position is still within QC polygon
                if (!isInsideQCBounds(pos.lat, pos.lng)) {
                    showNotification('Please select a location within Quezon City only.', 'error');
                    pinMarker.setLatLng([lat, lng]);
                    return;
                }
                
                pinLat.value = pos.lat;
                pinLng.value = pos.lng;
                // Re-run full GIS analysis for new position
                analyzePinnedLocation(pos.lat, pos.lng);
            });
        });

        document.getElementById('cancel-pin-btn').addEventListener('click', function() {
            if (pinMarker) { map.removeLayer(pinMarker); pinMarker = null; }
            reportPanel.style.display = 'none';
            document.getElementById('gis-location-info').style.display = 'none';
            document.getElementById('gis-location-warning').style.display = 'none';
            document.getElementById('pin-district').value = '';
            document.getElementById('pin-barangay').value = '';
            document.getElementById('pin-street').value = '';
        });

        // Multi-photo upload with add button and per-image delete
        const imageInput = document.getElementById('report-images');
        const imagePreview = document.getElementById('image-preview');
        const imageGallery = document.getElementById('image-gallery');
        const addPhotosBtn = document.getElementById('add-photos-btn');
        let selectedFiles = [];
        let updateSelectedFiles = [];
        let updatePreviewCounter = 0;
        
        addPhotosBtn.addEventListener('click', function() {
            imageInput.click();
        });
        
        function renderGallery() {
            imageGallery.innerHTML = '';
            if (selectedFiles.length === 0) {
                imagePreview.style.display = 'none';
                return;
            }
            imagePreview.style.display = 'block';
            selectedFiles.forEach((file, index) => {
                const reader = new FileReader();
                reader.onload = function(ev) {
                    const wrapper = document.createElement('div');
                    wrapper.style.position = 'relative';
                    wrapper.style.display = 'inline-block';
                    const img = document.createElement('img');
                    img.src = ev.target.result;
                    img.style.width = '100px';
                    img.style.height = '100px';
                    img.style.objectFit = 'cover';
                    img.style.borderRadius = '8px';
                    img.style.border = '1px solid rgba(55, 98, 200, 0.3)';
                    wrapper.appendChild(img);
                    const del = document.createElement('button');
                    del.type = 'button';
                    del.innerHTML = '&times;';
                    del.style.position = 'absolute';
                    del.style.top = '-6px';
                    del.style.right = '-6px';
                    del.style.width = '22px';
                    del.style.height = '22px';
                    del.style.borderRadius = '50%';
                    del.style.border = 'none';
                    del.style.background = '#dc3545';
                    del.style.color = 'white';
                    del.style.fontSize = '14px';
                    del.style.lineHeight = '22px';
                    del.style.textAlign = 'center';
                    del.style.cursor = 'pointer';
                    del.style.padding = '0';
                    del.addEventListener('click', function(ev2) {
                        ev2.stopPropagation();
                        selectedFiles.splice(index, 1);
                        renderGallery();
                    });
                    wrapper.appendChild(del);
                    wrapper.dataset.index = index;
                    imageGallery.appendChild(wrapper);
                };
                reader.readAsDataURL(file);
            });
        }
        
        imageInput.addEventListener('change', function(e) {
            const newFiles = Array.from(e.target.files);
            const valid = [];
            newFiles.forEach(file => {
                if (file.size > 5 * 1024 * 1024) {
                    showNotification(`"${file.name}" exceeds 5MB limit.`, 'error');
                } else {
                    valid.push(file);
                }
            });
            selectedFiles = selectedFiles.concat(valid);
            renderGallery();
            imageInput.value = '';
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (selectedFiles.length === 0) {
                showNotification('Please upload at least one photo before submitting.', 'error');
                return;
            }
            const dt = new DataTransfer();
            selectedFiles.forEach(f => dt.items.add(f));
            imageInput.files = dt.files;
            const btn = document.getElementById('submit-report-btn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
            const fd = new FormData(form);
            fd.set('action', 'submit_report');
            fetch('', { method: 'POST', body: fd })
                .then(r => {
                    if (!r.ok) throw new Error('HTTP error: ' + r.status);
                    return r.text();
                })
                .then(text => {
                    try {
                        const data = JSON.parse(text);
                        if (data.success) {
                            showNotification(data.message, 'success');
                            if (pinMarker) { map.removeLayer(pinMarker); pinMarker = null; }
                            reportPanel.style.display = 'none';
                            document.getElementById('gis-location-info').style.display = 'none';
                            document.getElementById('gis-location-warning').style.display = 'none';
                            form.reset();
                            selectedFiles = [];
                            imageGallery.innerHTML = '';
                            imagePreview.style.display = 'none';
                            loadMarkers(activeFilter);
                        } else {
                            showNotification(data.message || 'Failed to submit.', 'error');
                        }
                    } catch (e) {
                        console.error('Response:', text);
                        showNotification('Server error. Check console for details.', 'error');
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                    showNotification('Network error: ' + error.message, 'error');
                })
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send report';
                });
        });

        loadMarkers(activeFilter);
        startAutoRefresh();

        function showNotification(message, type) {
            type = type || 'info';
            const colors = { success: '#10b981', error: '#ef4444', info: '#3762c8', warning: '#f59e0b' };
            const c = colors[type] || colors.info;
            const el = document.createElement('div');
            el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:99999;padding:14px 20px;border-radius:10px;color:#fff;font-size:14px;font-weight:500;max-width:380px;box-shadow:0 8px 30px rgba(0,0,0,0.2);transform:translateX(120%);transition:transform 0.35s ease;background:'+c;
            el.textContent = message;
            document.body.appendChild(el);
            requestAnimationFrame(() => { el.style.transform = 'translateX(0)'; });
            setTimeout(() => {
                el.style.transform = 'translateX(120%)';
                setTimeout(() => el.remove(), 400);
            }, 4000);
        }

        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
            if (modalId === 'addUpdateModal') {
                requestAnimationFrame(function() {
                    var hidden = document.getElementById('addUpdateCompletionPercentage');
                    var pct = parseInt(hidden && hidden.value, 10) || 0;
                    if (typeof setUpdateCompletionPercentage === 'function') {
                        setUpdateCompletionPercentage(pct);
                    }
                });
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function closeModalAndRefresh(modalId) {
            closeModal(modalId);
            location.reload();
        }

        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                if (event.target.id === 'addUpdateModal') {
                    cancelUpdateForm();
                } else if (event.target.id === 'statusConfirmModal') {
                    closeStatusConfirmModal();
                } else {
                    event.target.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            }
        };

        function closeLightbox() {
            document.getElementById('lightboxOverlay').classList.remove('show');
        }

        function isTerminalUpdatesStatus() {
            var s = String(currentUpdatesReportStatus || '').toLowerCase().replace(/_/g, ' ').trim();
            return s === 'completed' || s === 'cancelled' || s === 'canceled';
        }

        // The transparency workflow only accepts COMPLETED projects: LGU- and
        // citizen-sourced rows from road_transportation_reports, CIMM
        // verification reports, and Infrastructure Projects (ipms_road_projects).
        // Mirrors transparency_fetch_request_report().
        var TRANSPARENCY_SOURCES = ['lgu', 'citizen', 'cimm', 'infrastructure', 'maintenance'];

        function updatesReportCategory() {
            return String((currentUpdatesReportDetails && currentUpdatesReportDetails.report_category) || '').toLowerCase();
        }

        // Each supervisor may only request uploads for the reports their own
        // portal lists, which transparency_role_may_request() also enforces
        // server-side. A blank category means the details were not available on
        // the row, and each portal is already filtered to its own category.
        function canRequestTransparencyUpload() {
            if (!currentUpdatesReportId) return false;
            var status = String(currentUpdatesReportStatus || '').toLowerCase().replace(/_/g, ' ').trim();
            if (status !== 'completed') return false;
            var source = String(currentUpdatesReportSource || '').toLowerCase();
            if (TRANSPARENCY_SOURCES.indexOf(source) === -1) return false;
            var category = updatesReportCategory();

            if (IS_ROAD_SUPERVISOR) {
                return category === '' || category === 'road';
            }
            // Transportation Operations Supervisor: citizen reports and LGU
            // monitoring reports of the Transportation type only.
            if (IS_TRANS_SUPERVISOR) {
                return (category === '' || category === 'transportation')
                    && (source === 'lgu' || source === 'citizen');
            }
            return false;
        }

        function setTransparencyBtnState(state) {
            var btn = document.getElementById('requestTransparencyBtn');
            if (!btn) return;
            if (state === 'pending') {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-hourglass-half"></i> Transparency Upload Requested';
            } else if (state === 'approved') {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-check-circle"></i> Transparency Upload Approved';
            } else if (state === 'sending') {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending Request...';
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-bullhorn"></i> Request Transparency Upload';
            }
        }

        function refreshTransparencyRequestButton() {
            var btn = document.getElementById('requestTransparencyBtn');
            if (!btn) return;
            if (!canRequestTransparencyUpload()) {
                btn.style.display = 'none';
                return;
            }
            function loadTransparencyStatus() {
                btn.style.display = 'inline-flex';
                setTransparencyBtnState('idle');
                // Reflect an existing request so the supervisor is not invited to
                // submit a duplicate the API would reject.
                fetch('../api/transparency_request_api.php?action=status&report_id=' + encodeURIComponent(currentUpdatesReportId)
                    + '&source=' + encodeURIComponent(currentUpdatesReportSource || ''))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data && data.success) setTransparencyBtnState(data.status);
                    })
                    .catch(function() {});
            }
            // Ownership gate: only the assigning supervisor may request transparency.
            if ((typeof IS_ROAD_SUPERVISOR !== 'undefined' && IS_ROAD_SUPERVISOR)
                || (typeof IS_TRANS_SUPERVISOR !== 'undefined' && IS_TRANS_SUPERVISOR)) {
                btn.style.display = 'none';
                fetch('../api/progress_update_api.php?action=can_manage_report&report_id=' + encodeURIComponent(currentUpdatesReportId)
                    + '&source=' + encodeURIComponent(currentUpdatesReportSource || ''))
                    .then(function(r) { return r.json(); })
                    .then(function(own) {
                        if (!(own && own.success && own.can_manage)) {
                            btn.style.display = 'none';
                            return;
                        }
                        loadTransparencyStatus();
                    })
                    .catch(function() {});
                return;
            }
            loadTransparencyStatus();
        }

        function requestTransparencyUpload() {
            if (!currentUpdatesReportId || !canRequestTransparencyUpload()) return;
            if (!confirm('Send this completed project to the administrator for transparency upload review?')) return;

            setTransparencyBtnState('sending');

            var fd = new FormData();
            fd.append('action', 'submit');
            fd.append('report_id', currentUpdatesReportId);
            fd.append('source', currentUpdatesReportSource || '');
            fd.append('report_type', currentUpdatesReportType || '');

            fetch('../api/transparency_request_api.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        showNotification(data.message || 'Transparency upload request sent', 'success');
                        setTransparencyBtnState('pending');
                    } else {
                        showNotification((data && data.message) || 'Failed to send the transparency upload request', 'error');
                        setTransparencyBtnState('idle');
                    }
                })
                .catch(function() {
                    showNotification('Network error', 'error');
                    setTransparencyBtnState('idle');
                });
        }

        // ===== ADMIN REVIEW FROM THE PROGRESS UPDATES MODAL =====
        // The notification alert still goes out, but the admin can also see the
        // request's status and decide on it here, on the completed project itself.
        // Scoped to the request the status lookup returns for the open report, so
        // a decision can never land on another project.
        var updatesTransparencyRequestId = 0;

        function canReviewTransparencyUpload() {
            if (!IS_SYSTEM_ADMIN || !currentUpdatesReportId) return false;
            var status = String(currentUpdatesReportStatus || '').toLowerCase().replace(/_/g, ' ').trim();
            if (status !== 'completed') return false;
            var source = String(currentUpdatesReportSource || '').toLowerCase();
            if (TRANSPARENCY_SOURCES.indexOf(source) === -1) return false;
            // The admin reviews whatever either supervisor may request.
            var category = updatesReportCategory();
            return category === '' || category === 'road' || category === 'transportation';
        }

        function setTransparencyDecisionBusy(busy) {
            var approveBtn = document.getElementById('approveTransparencyUpdatesBtn');
            var rejectBtn = document.getElementById('rejectTransparencyUpdatesBtn');
            if (approveBtn) approveBtn.disabled = busy;
            if (rejectBtn) rejectBtn.disabled = busy;
        }

        function renderTransparencyStatus(status, requestId) {
            var panel = document.getElementById('transparencyStatusPanel');
            if (!panel) return;
            var valueEl = document.getElementById('transparencyStatusValue');
            var metaEl = document.getElementById('transparencyStatusMeta');
            var actions = document.getElementById('transparencyDecisionActions');

            updatesTransparencyRequestId = parseInt(requestId, 10) || 0;
            var s = String(status || 'none').toLowerCase();
            var label = 'Not requested';
            var color = '#6b7280';
            var meta = 'No transparency upload request has been submitted for this project.';

            if (s === 'pending') {
                label = 'Pending review';
                color = '#b45309';
                meta = 'Request #' + updatesTransparencyRequestId + ' from the Road Operations Supervisor is awaiting your decision.';
            } else if (s === 'approved') {
                label = 'Approved';
                color = '#047857';
                meta = 'Request #' + updatesTransparencyRequestId + ' was approved. The project data is imported in Public Transparency for review.';
            } else if (s === 'rejected') {
                label = 'Rejected';
                color = '#b91c1c';
                meta = 'Request #' + updatesTransparencyRequestId + ' was rejected. The project stays Completed.';
            }

            if (valueEl) {
                valueEl.textContent = label;
                valueEl.style.color = color;
            }
            if (metaEl) metaEl.textContent = meta;
            if (actions) {
                actions.style.display = (s === 'pending' && updatesTransparencyRequestId > 0) ? 'inline-flex' : 'none';
            }
            setTransparencyDecisionBusy(false);
            panel.style.display = 'flex';
        }

        function refreshTransparencyStatusPanel() {
            var panel = document.getElementById('transparencyStatusPanel');
            if (!panel) return;
            panel.style.display = 'none';
            updatesTransparencyRequestId = 0;
            if (!canReviewTransparencyUpload()) return;

            var reportId = currentUpdatesReportId;
            fetch('../api/transparency_request_api.php?action=status&report_id=' + encodeURIComponent(reportId)
                + '&source=' + encodeURIComponent(currentUpdatesReportSource || ''))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    // Ignore a late response for a project the admin already left.
                    if (currentUpdatesReportId !== reportId) return;
                    if (data && data.success) renderTransparencyStatus(data.status, data.request_id);
                })
                .catch(function() {});
        }

        // A decision taken here settles the request, so the row no longer needs
        // its awaiting-transparency marker.
        function clearTransparencyAwaitIcon(reportId) {
            var row = document.querySelector('#recentReportsTable .report-table-row[data-id="' + reportId + '"]');
            if (!row) return;
            row.classList.remove('transparency-flagged');
            var icon = row.querySelector('.transparency-await-icon');
            if (icon) icon.parentNode.removeChild(icon);
        }

        function submitTransparencyDecisionFromUpdates(decision, reason) {
            if (!updatesTransparencyRequestId) return;
            setTransparencyDecisionBusy(true);

            var fd = new FormData();
            fd.append('action', decision);
            fd.append('request_id', updatesTransparencyRequestId);
            if (decision === 'reject' && reason) fd.append('reason', reason);

            fetch('../api/transparency_request_api.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        showNotification(data.message || 'Request updated', 'success');
                        clearTransparencyAwaitIcon(currentUpdatesReportId);
                        renderTransparencyStatus(
                            decision === 'approve' ? 'approved' : 'rejected',
                            updatesTransparencyRequestId
                        );
                        // Approving continues in the transparency page, which imports
                        // this project's data for the admin to review.
                        if (data.redirect_url) {
                            setTimeout(function() { window.location.href = data.redirect_url; }, 900);
                        }
                    } else {
                        showNotification((data && data.message) || 'Failed to update the request', 'error');
                        refreshTransparencyStatusPanel();
                    }
                })
                .catch(function() {
                    showNotification('Network error', 'error');
                    setTransparencyDecisionBusy(false);
                });
        }

        function approveTransparencyFromUpdates() {
            if (!updatesTransparencyRequestId) return;
            if (!confirm('Approve the transparency upload request for this project? The project stays Completed and you will continue in Public Transparency.')) return;
            submitTransparencyDecisionFromUpdates('approve');
        }

        function rejectTransparencyFromUpdates() {
            if (!updatesTransparencyRequestId) return;
            var reason = prompt('Reject this transparency upload request? You may add a reason (optional):', '');
            if (reason === null) return;
            submitTransparencyDecisionFromUpdates('reject', reason.trim());
        }

        function applyUpdatesFooterMode() {
            var actionButtons = document.getElementById('actionButtons');
            var exportButtons = document.getElementById('exportButtons');
            var exportWordBtn = document.getElementById('exportWordBtn');
            var completeBtn = document.getElementById('completeBtn');
            var cancelBtn = document.getElementById('cancelBtn');
            var addUpdateBtn = document.getElementById('addUpdateBtn');
            if (actionButtons) actionButtons.style.display = 'flex';
            if (exportWordBtn) exportWordBtn.style.display = 'inline-flex';
            if (exportButtons) exportButtons.style.display = 'none';
            refreshTransparencyRequestButton();
            refreshTransparencyStatusPanel();
            if (isTerminalUpdatesStatus()) {
                // Completed / cancelled: hide Complete, Cancel, and Add Update
                if (completeBtn) completeBtn.style.display = 'none';
                if (cancelBtn) cancelBtn.style.display = 'none';
                if (addUpdateBtn) addUpdateBtn.style.display = 'none';
                return true;
            }
            // System Admin does not complete or cancel from this page.
            if (typeof IS_SYSTEM_ADMIN !== 'undefined' && IS_SYSTEM_ADMIN) {
                if (completeBtn) completeBtn.style.display = 'none';
                if (cancelBtn) cancelBtn.style.display = 'none';
                if (addUpdateBtn) addUpdateBtn.style.display = 'inline-flex';
                return false;
            }
            // Not terminal yet: show Complete, Cancel, and Add Update
            // (officer assignment checks may refine Complete/Cancel afterward)
            if (completeBtn) completeBtn.style.display = 'inline-flex';
            if (cancelBtn) cancelBtn.style.display = 'inline-flex';
            if (addUpdateBtn) addUpdateBtn.style.display = 'inline-flex';
            return false;
        }

        function viewReportUpdates(id, type, source, status) {
            currentUpdatesReportId = id;
            currentUpdatesReportType = type;
            currentUpdatesReportSource = source;
            currentUpdatesReportStatus = status || '';
            currentUpdatesReportDetails = null;
            if (typeof currentProjectCompletionPercentage !== 'undefined') {
                currentProjectCompletionPercentage = 0;
            }
            if (typeof currentProjectMinCompletionPercentage !== 'undefined') {
                currentProjectMinCompletionPercentage = 0;
            }
            if (typeof currentLatestUpdateId !== 'undefined') {
                currentLatestUpdateId = 0;
            }
            if (typeof setMainProjectCompletionDisplay === 'function') {
                setMainProjectCompletionDisplay(0);
            }
            try {
                var row = document.querySelector('#recentReportsTable .report-table-row[data-id="' + id + '"][data-source="' + (source || '') + '"]')
                    || document.querySelector('#recentReportsTable .report-table-row[data-id="' + id + '"]');
                if (row && row.dataset.details) {
                    currentUpdatesReportDetails = JSON.parse(row.dataset.details);
                }
            } catch (e) {}
            var infoId = (currentUpdatesReportDetails && currentUpdatesReportDetails.report_id) ? currentUpdatesReportDetails.report_id : id;
            var infoTitle = (currentUpdatesReportDetails && currentUpdatesReportDetails.title) ? ' — ' + currentUpdatesReportDetails.title : '';
            document.getElementById('updateReportInfo').textContent = 'Report #' + infoId + infoTitle;
            openModal('updatesModal');
            if (typeof loadUpdates === 'function') {
                loadUpdates(id, type);
            }
            if (applyUpdatesFooterMode()) return;
            // Check if user can add updates and show/hide the Add Update button accordingly
            checkUpdatePermission();
            // Check if the Request Completion / Request Cancellation buttons may
            // be shown (officers only get them for reports assigned to them).
            checkRequestPermission();
        }

        function checkRequestPermission() {
            var completeBtn = document.getElementById('completeBtn');
            var cancelBtn = document.getElementById('cancelBtn');
            if (!completeBtn) return;
            if (isTerminalUpdatesStatus()) {
                completeBtn.style.display = 'none';
                if (cancelBtn) cancelBtn.style.display = 'none';
                return;
            }
            var role = '';
            var tag = document.getElementById('sessionTimeoutData');
            if (tag) role = tag.getAttribute('data-role') || '';
            var isOfficer = (role === 'road_monitoring_officer' || role === 'trans_monitoring_officer');

            // System Admin does not complete or cancel from this page.
            if (typeof IS_SYSTEM_ADMIN !== 'undefined' && IS_SYSTEM_ADMIN) {
                completeBtn.style.display = 'none';
                if (cancelBtn) cancelBtn.style.display = 'none';
                return;
            }

            // Non-officers (supervisors, etc.) use direct Complete/Cancel —
            // but only when they own the report (first assigner).
            if (!isOfficer) {
                if ((typeof IS_ROAD_SUPERVISOR !== 'undefined' && IS_ROAD_SUPERVISOR)
                    || (typeof IS_TRANS_SUPERVISOR !== 'undefined' && IS_TRANS_SUPERVISOR)) {
                    completeBtn.style.display = 'none';
                    if (cancelBtn) cancelBtn.style.display = 'none';
                    if (!currentUpdatesReportId) return;
                    fetch('../api/progress_update_api.php?action=can_manage_report&report_id=' + currentUpdatesReportId + '&source=' + encodeURIComponent(currentUpdatesReportSource || ''))
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (data && data.success && data.can_manage) {
                                completeBtn.style.display = 'inline-flex';
                                if (cancelBtn) cancelBtn.style.display = 'inline-flex';
                            }
                        })
                        .catch(function() {});
                    return;
                }
                completeBtn.style.display = 'inline-flex';
                if (cancelBtn) cancelBtn.style.display = 'inline-flex';
                return;
            }
            if (!currentUpdatesReportId) {
                completeBtn.style.display = 'none';
                if (cancelBtn) cancelBtn.style.display = 'none';
                return;
            }

            // Server-authoritative check (role + assignment): Road/Transportation
            // Monitoring Officers can only request completion/cancellation for
            // reports assigned to them. The same rule is enforced server-side in
            // progress_update_api.php. Officers are fail-closed: the buttons stay
            // hidden until the server confirms an active assignment for this report.
            completeBtn.style.display = 'none';
            if (cancelBtn) cancelBtn.style.display = 'none';
            fetch('../api/progress_update_api.php?action=can_request_review&report_id=' + currentUpdatesReportId + '&source=' + encodeURIComponent(currentUpdatesReportSource || ''))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success && data.can_request) {
                        completeBtn.style.display = 'inline-flex';
                        if (cancelBtn) cancelBtn.style.display = 'inline-flex';
                    }
                })
                .catch(function() {});
        }

        function checkUpdatePermission() {
            // Server-authoritative check (role + assignment): Road/Transportation
            // Monitoring Officers can only post updates to reports assigned to
            // them. The same rule is enforced server-side in progress_update_api.php.
            // Officers are fail-closed: the button stays hidden until the server
            // confirms an active assignment for this report.
            var btn = document.getElementById('addUpdateBtn');
            if (!btn) return;
            if (isTerminalUpdatesStatus()) {
                btn.style.display = 'none';
                return;
            }
            var role = '';
            var tag = document.getElementById('sessionTimeoutData');
            if (tag) role = tag.getAttribute('data-role') || '';
            var isOfficer = (role === 'road_monitoring_officer' || role === 'trans_monitoring_officer');

            if (!currentUpdatesReportId) {
                btn.style.display = isOfficer ? 'none' : 'inline-flex';
                return;
            }
            if (isOfficer) {
                btn.style.display = 'none';
            } else {
                btn.style.display = 'inline-flex';
            }

            fetch('../api/progress_update_api.php?action=can_post_update&report_id=' + currentUpdatesReportId + '&source=' + encodeURIComponent(currentUpdatesReportSource || ''))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success && data.can_post) {
                        btn.style.display = 'inline-flex';
                    } else {
                        btn.style.display = 'none';
                    }
                })
                .catch(function() {});
        }

        var updateCompletionSliderBound = false;
        var completionSliderLocked = false;
        // New/edited (latest) updates cannot go below the previous saved %.
        var completionSliderMin = 0;

        function setCompletionSliderMin(minPct) {
            completionSliderMin = Math.max(0, Math.min(100, Math.round(Number(minPct) || 0)));
            var trackEl = document.getElementById('addUpdateCompletionTrack');
            var minHintEl = document.getElementById('addUpdateCompletionMinHint');
            if (trackEl) {
                trackEl.setAttribute('aria-valuemin', String(completionSliderMin));
            }
            if (minHintEl) {
                minHintEl.textContent = completionSliderMin + '%';
            }
            // Re-clamp current value if it is now below the floor.
            var hidden = document.getElementById('addUpdateCompletionPercentage');
            if (hidden) {
                var current = parseInt(hidden.value, 10);
                if (!isNaN(current) && current < completionSliderMin) {
                    setUpdateCompletionPercentage(completionSliderMin);
                }
            }
        }

        function setCompletionSliderEditable(editable) {
            completionSliderLocked = !editable;
            var slider = document.getElementById('addUpdateCompletionSlider');
            var trackEl = document.getElementById('addUpdateCompletionTrack');
            var handleEl = document.getElementById('addUpdateCompletionHandle');
            var noteEl = document.getElementById('addUpdateCompletionLockedNote');
            var groupEl = document.getElementById('addUpdateCompletionGroup');
            if (slider) {
                slider.classList.toggle('is-locked', !editable);
                slider.classList.remove('is-dragging');
            }
            if (trackEl) {
                trackEl.setAttribute('aria-disabled', editable ? 'false' : 'true');
                if (editable) {
                    trackEl.setAttribute('tabindex', '0');
                } else {
                    trackEl.setAttribute('tabindex', '-1');
                }
            }
            if (handleEl) {
                handleEl.style.pointerEvents = editable ? '' : 'none';
            }
            if (noteEl) {
                noteEl.style.display = editable ? 'none' : '';
            }
            if (groupEl) {
                groupEl.classList.toggle('completion-locked', !editable);
            }
        }

        function setUpdateCompletionPercentage(pct) {
            var floor = completionSliderLocked ? 0 : completionSliderMin;
            pct = Math.max(floor, Math.min(100, Math.round(Number(pct) || 0)));
            var slider = document.getElementById('addUpdateCompletionSlider');
            var hidden = document.getElementById('addUpdateCompletionPercentage');
            var valueEl = document.getElementById('addUpdateCompletionValue');
            var fillEl = document.getElementById('addUpdateCompletionFill');
            var handleEl = document.getElementById('addUpdateCompletionHandle');
            var trackEl = document.getElementById('addUpdateCompletionTrack');
            var railEl = slider ? slider.querySelector('.completion-slider-rail') : null;
            var hintEl = document.getElementById('addUpdateCompletionFullHint');
            if (!hidden || !valueEl || !fillEl || !handleEl || !trackEl) return;
            hidden.value = String(pct);
            handleEl.style.left = pct + '%';
            fillEl.style.width = pct + '%';
            valueEl.textContent = pct + '%';
            trackEl.setAttribute('aria-valuenow', String(pct));
            trackEl.setAttribute('aria-valuemin', String(completionSliderLocked ? 0 : completionSliderMin));
            if (hintEl) {
                hintEl.style.display = pct >= 100 ? '' : 'none';
            }
            positionUpdateCompletionLabel(railEl, trackEl, valueEl, pct);
            updateProjectCompletionDisplays(pct);
        }

        /** Keep the prominent "Project Completion: X%" banners in sync with the slider. */
        function updateProjectCompletionDisplays(pct) {
            pct = Math.max(0, Math.min(100, Math.round(Number(pct) || 0)));
            var text = pct + '%';
            var addEl = document.getElementById('addUpdateProjectCompletionValue');
            var mainEl = document.getElementById('updatesProjectCompletionValue');
            if (addEl) addEl.textContent = text;
            if (mainEl) {
                if (completionSliderLocked) {
                    // Editing an older update: do not overwrite the project's latest %.
                    var latest = parseCompletionPercentage(
                        typeof currentProjectCompletionPercentage !== 'undefined' ? currentProjectCompletionPercentage : 0
                    );
                    mainEl.textContent = latest + '%';
                } else {
                    mainEl.textContent = text;
                }
            }
        }

        /** Set the main Progress Updates banner from the latest DB-saved value. */
        function setMainProjectCompletionDisplay(pct) {
            pct = parseCompletionPercentage(pct);
            if (typeof currentProjectCompletionPercentage !== 'undefined') {
                currentProjectCompletionPercentage = pct;
            }
            var mainEl = document.getElementById('updatesProjectCompletionValue');
            if (mainEl) mainEl.textContent = pct + '%';
        }

        function positionUpdateCompletionLabel(railEl, trackEl, valueEl, pct) {
            if (!railEl || !trackEl || !valueEl) return;
            var railWidth = railEl.clientWidth;
            var trackWidth = trackEl.clientWidth;
            if (!railWidth || !trackWidth) return;
            var trackLeft = trackEl.offsetLeft;
            var labelWidth = valueEl.offsetWidth || 32;
            var half = labelWidth / 2;
            var center = trackLeft + ((pct / 100) * trackWidth);
            var clamped = Math.max(half, Math.min(railWidth - half, center));
            valueEl.style.left = clamped + 'px';
            valueEl.style.transform = 'translateX(-50%)';
        }

        function completionPctFromPointer(trackEl, clientX) {
            var rect = trackEl.getBoundingClientRect();
            if (!rect.width) return completionSliderMin;
            var ratio = (clientX - rect.left) / rect.width;
            var floor = completionSliderLocked ? 0 : completionSliderMin;
            return Math.max(floor, Math.min(100, Math.round(ratio * 100)));
        }

        function bindUpdateCompletionSlider() {
            if (updateCompletionSliderBound) return;
            var slider = document.getElementById('addUpdateCompletionSlider');
            var trackEl = document.getElementById('addUpdateCompletionTrack');
            var handleEl = document.getElementById('addUpdateCompletionHandle');
            var valueEl = document.getElementById('addUpdateCompletionValue');
            if (!slider || !trackEl || !handleEl || !valueEl) return;
            updateCompletionSliderBound = true;

            var dragging = false;

            function onPointerMove(clientX) {
                if (completionSliderLocked) return;
                setUpdateCompletionPercentage(completionPctFromPointer(trackEl, clientX));
            }

            function endDrag() {
                if (!dragging) return;
                dragging = false;
                slider.classList.remove('is-dragging');
                valueEl.classList.remove('is-dragging');
            }

            handleEl.addEventListener('pointerdown', function(e) {
                if (completionSliderLocked) return;
                e.preventDefault();
                dragging = true;
                slider.classList.add('is-dragging');
                valueEl.classList.add('is-dragging');
                if (handleEl.setPointerCapture && e.pointerId != null) {
                    try { handleEl.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
                }
                onPointerMove(e.clientX);
            });

            handleEl.addEventListener('pointermove', function(e) {
                if (!dragging || completionSliderLocked) return;
                e.preventDefault();
                onPointerMove(e.clientX);
            });

            handleEl.addEventListener('pointerup', endDrag);
            handleEl.addEventListener('pointercancel', endDrag);

            trackEl.addEventListener('pointerdown', function(e) {
                if (completionSliderLocked) return;
                if (e.target === handleEl) return;
                onPointerMove(e.clientX);
            });

            trackEl.addEventListener('keydown', function(e) {
                if (completionSliderLocked) return;
                var hidden = document.getElementById('addUpdateCompletionPercentage');
                var current = parseInt(hidden && hidden.value, 10) || 0;
                var step = e.shiftKey ? 10 : 1;
                if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    setUpdateCompletionPercentage(current + step);
                } else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
                    e.preventDefault();
                    setUpdateCompletionPercentage(current - step);
                } else if (e.key === 'Home') {
                    e.preventDefault();
                    setUpdateCompletionPercentage(completionSliderMin);
                } else if (e.key === 'End') {
                    e.preventDefault();
                    setUpdateCompletionPercentage(100);
                }
            });

            window.addEventListener('resize', function() {
                var hidden = document.getElementById('addUpdateCompletionPercentage');
                var pct = parseInt(hidden && hidden.value, 10) || 0;
                setUpdateCompletionPercentage(pct);
            });
        }

        document.addEventListener('DOMContentLoaded', bindUpdateCompletionSlider);

        function parseCompletionPercentage(raw) {
            var pct = parseInt(raw, 10);
            if (isNaN(pct)) return 0;
            return Math.max(0, Math.min(100, pct));
        }

        /**
         * Load the latest saved completion % for this project from the database.
         * Used so "Add Update" starts at the previous update's percentage (not 0).
         */
        function fetchLatestProjectCompletion(reportId, source, done) {
            if (!reportId) {
                if (typeof done === 'function') done(0, 0);
                return;
            }
            var url = '../api/progress_update_api.php?action=get_latest_completion'
                + '&report_id=' + encodeURIComponent(reportId)
                + '&source=' + encodeURIComponent(source || '');
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    var pct = (data && data.success)
                        ? parseCompletionPercentage(data.latest_completion_percentage)
                        : 0;
                    var minPct = (data && data.success && data.min_completion_percentage != null)
                        ? parseCompletionPercentage(data.min_completion_percentage)
                        : pct;
                    if (data && data.success && data.latest_update_id != null) {
                        currentLatestUpdateId = parseInt(data.latest_update_id, 10) || 0;
                    }
                    setMainProjectCompletionDisplay(pct);
                    if (typeof currentProjectMinCompletionPercentage !== 'undefined') {
                        currentProjectMinCompletionPercentage = minPct;
                    }
                    if (typeof done === 'function') done(pct, minPct);
                })
                .catch(function() {
                    var fallback = parseCompletionPercentage(
                        typeof currentProjectCompletionPercentage !== 'undefined' ? currentProjectCompletionPercentage : 0
                    );
                    setMainProjectCompletionDisplay(fallback);
                    if (typeof done === 'function') done(fallback, fallback);
                });
        }

        function showAddUpdateModal() {
            bindUpdateCompletionSlider();
            var reportId = currentUpdatesReportId;
            var source = currentUpdatesReportSource || '';
            document.getElementById('addUpdateAction').value = 'create_update';
            document.getElementById('addUpdateId').value = '';
            document.getElementById('addUpdateReportId').value = reportId;
            document.getElementById('addUpdateReportType').value = currentUpdatesReportType;
            document.getElementById('addUpdateSource').value = source;
            document.getElementById('addUpdateTitle').value = '';
            document.getElementById('addUpdateDescription').value = '';
            document.getElementById('updateFilePreviews').innerHTML = '';
            document.getElementById('existingUpdateMediaSection').style.display = 'none';
            document.getElementById('existingUpdateMedia').innerHTML = '';
            document.getElementById('addUpdateModalTitle').textContent = 'Add Progress Update';
            document.getElementById('addUpdateSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Post Update';
            updateSelectedFiles = [];
            updatePreviewCounter = 0;

            // New update: slider is editable and cannot go below highest prior %.
            setCompletionSliderEditable(true);

            // Start from last known DB value / floor (updated again after fetch).
            var initialPct = parseCompletionPercentage(
                typeof currentProjectCompletionPercentage !== 'undefined' ? currentProjectCompletionPercentage : 0
            );
            var initialMin = parseCompletionPercentage(
                typeof currentProjectMinCompletionPercentage !== 'undefined'
                    ? currentProjectMinCompletionPercentage
                    : initialPct
            );
            setCompletionSliderMin(initialMin);
            setUpdateCompletionPercentage(Math.max(initialPct, initialMin));
            closeModal('updatesModal');
            openModal('addUpdateModal');

            fetchLatestProjectCompletion(reportId, source, function(pct, minPct) {
                if ((document.getElementById('addUpdateAction') || {}).value !== 'create_update') return;
                if (String(document.getElementById('addUpdateReportId').value) !== String(reportId)) return;
                var floor = minPct != null ? minPct : pct;
                if (typeof currentProjectMinCompletionPercentage !== 'undefined') {
                    currentProjectMinCompletionPercentage = floor;
                }
                setCompletionSliderMin(floor);
                setUpdateCompletionPercentage(Math.max(pct, floor));
            });
        }

        function cancelUpdateForm() {
            closeModal('addUpdateModal');
            openModal('updatesModal');
            // Restore banner to last saved project % (ignore unsaved slider drag).
            setMainProjectCompletionDisplay(
                typeof currentProjectCompletionPercentage !== 'undefined' ? currentProjectCompletionPercentage : 0
            );
            if (typeof loadUpdates === 'function') {
                loadUpdates(currentUpdatesReportId, currentUpdatesReportType);
            }
        }

        // Override showUpdateForm from progress-updates.js to use modal
        function showUpdateForm(reportId, reportType, updateData) {
            bindUpdateCompletionSlider();
            const isEdit = updateData && updateData.id;
            document.getElementById('addUpdateAction').value = isEdit ? 'edit_update' : 'create_update';
            document.getElementById('addUpdateId').value = isEdit ? updateData.id : '';
            document.getElementById('addUpdateReportId').value = reportId;
            document.getElementById('addUpdateReportType').value = reportType;
            document.getElementById('addUpdateSource').value = currentUpdatesReportSource || '';
            document.getElementById('addUpdateTitle').value = isEdit ? (updateData.title || '') : '';
            document.getElementById('addUpdateDescription').value = isEdit ? (updateData.description || '') : '';
            document.getElementById('updateFilePreviews').innerHTML = '';
            document.getElementById('addUpdateModalTitle').textContent = isEdit ? 'Edit Update' : 'Add Progress Update';
            document.getElementById('addUpdateSubmitBtn').innerHTML = isEdit ? '<i class="fas fa-save"></i> Save Changes' : '<i class="fas fa-save"></i> Post Update';
            updateSelectedFiles = [];
            updatePreviewCounter = 0;

            if (isEdit) {
                var savedPct = parseCompletionPercentage(updateData.completion_percentage);
                var isLatest = !!(updateData.is_latest_completion
                    || (currentLatestUpdateId && String(updateData.id) === String(currentLatestUpdateId)));
                var editFloor = parseCompletionPercentage(updateData.min_completion_percentage);
                setCompletionSliderEditable(isLatest);
                setCompletionSliderMin(isLatest ? editFloor : savedPct);
                setUpdateCompletionPercentage(savedPct);
            } else {
                setCompletionSliderEditable(true);
                var createPct = parseCompletionPercentage(
                    typeof currentProjectCompletionPercentage !== 'undefined' ? currentProjectCompletionPercentage : 0
                );
                var createMin = parseCompletionPercentage(
                    typeof currentProjectMinCompletionPercentage !== 'undefined'
                        ? currentProjectMinCompletionPercentage
                        : createPct
                );
                setCompletionSliderMin(createMin);
                setUpdateCompletionPercentage(Math.max(createPct, createMin));
                fetchLatestProjectCompletion(reportId, currentUpdatesReportSource || '', function(pct, minPct) {
                    if ((document.getElementById('addUpdateAction') || {}).value !== 'create_update') return;
                    if (String(document.getElementById('addUpdateReportId').value) !== String(reportId)) return;
                    var floor = minPct != null ? minPct : pct;
                    setCompletionSliderMin(floor);
                    setUpdateCompletionPercentage(Math.max(pct, floor));
                });
            }

            var removedMediaIds = [];

            if (isEdit && updateData.media) {
                const mediaContainer = document.getElementById('existingUpdateMedia');
                mediaContainer.innerHTML = '';
                document.getElementById('existingUpdateMediaSection').style.display = '';
                updateData.media.forEach(function(m) {
                    const div = document.createElement('div');
                    div.style.cssText = 'position:relative;width:80px;height:60px;border-radius:6px;overflow:hidden;border:1px solid rgba(55,98,200,0.15);flex-shrink:0;';
                    const isVideo = m.file_type === 'video';
                    div.innerHTML = isVideo
                        ? '<i class="fas fa-video" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:20px;color:#3762c8;opacity:0.5;"></i>'
                        : '<img src="../../' + m.file_path.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') + '" style="width:100%;height:100%;object-fit:cover;">';
                    var removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.style.cssText = 'position:absolute;top:2px;right:2px;width:18px;height:18px;border-radius:50%;border:none;background:rgba(220,53,69,0.9);color:#fff;font-size:14px;line-height:1;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;z-index:2;';
                    removeBtn.innerHTML = '&times;';
                    removeBtn.title = 'Remove this photo';
                    removeBtn.addEventListener('click', function(mediaId) {
                        return function() {
                            if (removedMediaIds.indexOf(mediaId) === -1) {
                                removedMediaIds.push(mediaId);
                            }
                            div.style.transition = 'transform 0.2s ease, opacity 0.2s ease';
                            div.style.transform = 'scale(0.5)';
                            div.style.opacity = '0';
                            setTimeout(function() { div.remove(); }, 200);
                        };
                    }(m.id));
                    div.appendChild(removeBtn);
                    mediaContainer.appendChild(div);
                });
            } else {
                document.getElementById('existingUpdateMediaSection').style.display = 'none';
                document.getElementById('existingUpdateMedia').innerHTML = '';
            }

            // Store removedMediaIds on the form for later use during submit
            var form = document.getElementById('addUpdateForm');
            form._removedMediaIds = removedMediaIds;

            closeModal('updatesModal');
            openModal('addUpdateModal');
        }

        // Override handleUpdateFormSubmit from progress-updates.js for modal flow
        function handleUpdateFormSubmit(e) {
            e.preventDefault();
            var form = document.getElementById('addUpdateForm');
            var submitPct = parseCompletionPercentage(
                (document.getElementById('addUpdateCompletionPercentage') || {}).value
            );
            if (!completionSliderLocked && submitPct < completionSliderMin) {
                setUpdateCompletionPercentage(completionSliderMin);
                showNotification(
                    'Completion percentage cannot be lower than the previous update (' + completionSliderMin + '%).',
                    'error'
                );
                return;
            }

            const btn = document.getElementById('addUpdateSubmitBtn');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            var removedIds = form._removedMediaIds || [];
            document.querySelectorAll('#addUpdateForm input[name="remove_media[]"]').forEach(function(el) { el.remove(); });
            removedIds.forEach(function(id) {
                var h = document.createElement('input');
                h.type = 'hidden';
                h.name = 'remove_media[]';
                h.value = id;
                form.appendChild(h);
            });

            var dt = new DataTransfer();
            updateSelectedFiles.forEach(function(f) { dt.items.add(f); });
            var fileInput = form.querySelector('input[type="file"]');
            if (fileInput) fileInput.files = dt.files;

            const fd = new FormData(form);
            // Always send the clamped slider value (never a stale hidden field).
            fd.set('completion_percentage', String(Math.max(
                completionSliderLocked ? 0 : completionSliderMin,
                submitPct
            )));
            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: fd
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var savedPct = parseCompletionPercentage(
                        data.completion_percentage != null
                            ? data.completion_percentage
                            : (document.getElementById('addUpdateCompletionPercentage') || {}).value
                    );
                    var wasCreate = (document.getElementById('addUpdateAction') || {}).value === 'create_update';
                    if (wasCreate) {
                        setMainProjectCompletionDisplay(savedPct);
                        if (typeof currentProjectCompletionPercentage !== 'undefined') {
                            currentProjectCompletionPercentage = savedPct;
                        }
                        if (typeof currentProjectMinCompletionPercentage !== 'undefined') {
                            currentProjectMinCompletionPercentage = savedPct;
                        }
                        if (data.update_id) {
                            currentLatestUpdateId = parseInt(data.update_id, 10) || currentLatestUpdateId;
                        }
                    }
                    // Keep add-modal banner + slider on the saved value until reload refreshes from DB.
                    setCompletionSliderEditable(true);
                    setCompletionSliderMin(savedPct);
                    setUpdateCompletionPercentage(savedPct);
                    showNotification(data.message, 'success');
                    closeModal('addUpdateModal');
                    openModal('updatesModal');
                    if (typeof loadUpdates === 'function') {
                        loadUpdates(currentUpdatesReportId, currentUpdatesReportType);
                    }
                    if (wasCreate) {
                        clearNoUpdateFlagOnRow(currentUpdatesReportId);
                    }
                } else {
                    if (data.min_completion_percentage != null) {
                        var floorPct = parseCompletionPercentage(data.min_completion_percentage);
                        setCompletionSliderMin(floorPct);
                        setUpdateCompletionPercentage(Math.max(submitPct, floorPct));
                    }
                    showNotification(data.message || 'Failed to save update', 'error');
                }
            })
            .catch(function(e) {
                showNotification('Network error', 'error');
                console.error(e);
            })
            .finally(function() { btn.disabled = false; btn.innerHTML = orig; });
        }

        // Attach submit handler to add update form (event delegation, works even if form isn't in DOM yet)
        document.addEventListener('submit', function(e) {
            if (e.target && e.target.id === 'addUpdateForm') {
                handleUpdateFormSubmit(e);
            }
        });

        // Add Photos button triggers hidden file input
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'addUpdatePhotosBtn') {
                var fileInput = document.querySelector('#addUpdateForm input[type="file"]');
                if (fileInput) fileInput.click();
            }
        });

        // File preview for add update modal — maintains persistent upload queue
        document.addEventListener('change', function(e) {
            if (e.target && e.target.matches && e.target.matches('#addUpdateForm input[type="file"]')) {
                var newFiles = Array.from(e.target.files);
                newFiles.forEach(function(f) {
                    if (updateSelectedFiles.indexOf(f) === -1) {
                        updateSelectedFiles.push(f);
                    }
                });
                e.target.value = '';
                renderUpdateFilePreviews();
            }
        });

        // Complete button handler
        document.addEventListener('click', function(e) {
            if (e.target && e.target.closest && e.target.closest('#completeBtn')) {
                requestCompleteOrCancel('complete');
            }
        });

        // Cancel button handler
        document.addEventListener('click', function(e) {
            if (e.target && e.target.closest && e.target.closest('#cancelBtn')) {
                requestCompleteOrCancel('cancel');
            }
        });

        let isCompleting = false; // Flag to prevent multiple clicks
        var pendingStatusAction = null; // 'complete' | 'cancel' while confirm modal is open
        var COMPLETE_REQUIRES_100_MSG = 'This project is not at 100% completion. Unable to complete.';

        function parseProgressApiJson(r) {
            return r.json().then(function(data) {
                if (!data || typeof data !== 'object') {
                    throw new Error('Unexpected server response.');
                }
                return data;
            });
        }

        function progressApiErrorMessage(err, fallback) {
            var msg = (err && err.message) ? String(err.message) : '';
            if (!msg || msg === 'Failed to fetch') {
                return fallback || 'Network error';
            }
            return msg;
        }

        function isOfficerRole() {
            var tag = document.getElementById('sessionTimeoutData');
            if (!tag) return false;
            var role = tag.getAttribute('data-role') || '';
            return (role === 'road_monitoring_officer' || role === 'trans_monitoring_officer');
        }

        function isSupervisorRole() {
            return (typeof IS_ROAD_SUPERVISOR !== 'undefined' && IS_ROAD_SUPERVISOR)
                || (typeof IS_TRANS_SUPERVISOR !== 'undefined' && IS_TRANS_SUPERVISOR);
        }

        /**
         * Supervisors (road + transportation) must confirm before Complete/Cancel.
         * Officers keep the request-submission flow (no status change here).
         */
        function requestCompleteOrCancel(action) {
            if (!currentUpdatesReportId) return;
            if (typeof IS_SYSTEM_ADMIN !== 'undefined' && IS_SYSTEM_ADMIN) return;

            if (isOfficerRole()) {
                submitReviewRequest(action === 'complete' ? 'completion' : 'cancellation');
                return;
            }

            if (isSupervisorRole()) {
                openStatusConfirmModal(action);
                return;
            }

            // Other non-officer roles with Complete/Cancel: confirm as well for safety.
            openStatusConfirmModal(action);
        }

        function openStatusConfirmModal(action) {
            pendingStatusAction = action === 'cancel' ? 'cancel' : 'complete';
            var titleEl = document.getElementById('statusConfirmTitle');
            var msgEl = document.getElementById('statusConfirmMessage');
            var iconEl = document.getElementById('statusConfirmIcon');
            var confirmBtn = document.getElementById('statusConfirmSubmitBtn');
            if (pendingStatusAction === 'complete') {
                if (titleEl) titleEl.textContent = 'Confirm Completion';
                if (msgEl) msgEl.textContent = 'Are you sure you want to mark this project as completed?';
                if (iconEl) iconEl.innerHTML = '<i class="fas fa-circle-check"></i>';
                if (confirmBtn) {
                    confirmBtn.className = 'btn-success-custom';
                    confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm';
                }
            } else {
                if (titleEl) titleEl.textContent = 'Confirm Cancellation';
                if (msgEl) msgEl.textContent = 'Are you sure you want to cancel this project?';
                if (iconEl) iconEl.innerHTML = '<i class="fas fa-ban"></i>';
                if (confirmBtn) {
                    confirmBtn.className = 'btn-danger-custom';
                    confirmBtn.innerHTML = '<i class="fas fa-check"></i> Confirm';
                }
            }
            var modal = document.getElementById('statusConfirmModal');
            if (modal) modal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeStatusConfirmModal() {
            pendingStatusAction = null;
            var modal = document.getElementById('statusConfirmModal');
            if (modal) modal.style.display = 'none';
            var updates = document.getElementById('updatesModal');
            if (!updates || updates.style.display !== 'block') {
                document.body.style.overflow = 'auto';
            }
        }

        function confirmStatusAction() {
            var action = pendingStatusAction;
            closeStatusConfirmModal();
            if (action === 'complete') {
                executeCompleteReport();
            } else if (action === 'cancel') {
                executeCancelReport();
            }
        }

        // After completing/cancelling a report, reload the page in place.
        function afterStatusActionRedirect() {
            location.reload();
        }

        // Archive button. Only shown for reports whose status is COMPLETED —
        // Pending, Approved, In Progress, Cancelled, Rejected, and every other
        // status hide it. Moves the report into the archive keeping its current
        // status. Completed projects otherwise stay on Completed Projects until
        // this button is used.
        function archiveReport(id, source) {
            if (!id) return;
            if (!confirm('Archive this report? It will be moved out of Active Monitoring Reports into the Archive, keeping its current status.')) return;

            var fd = new FormData();
            fd.append('action', 'archive_report');
            fd.append('report_id', id);
            fd.append('source', source || '');

            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: fd
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showNotification(data.message, 'success');
                    setTimeout(function() { location.reload(); }, 700);
                } else {
                    showNotification(data.message || 'Failed to archive the report', 'error');
                }
            })
            .catch(function() {
                showNotification('Network error', 'error');
            });
        }

        function executeCompleteReport() {
            if (!currentUpdatesReportId) return;
            if (typeof IS_SYSTEM_ADMIN !== 'undefined' && IS_SYSTEM_ADMIN) return;
            if (isCompleting) return; // Prevent multiple clicks

            var completionPct = parseCompletionPercentage(
                typeof currentProjectCompletionPercentage !== 'undefined' ? currentProjectCompletionPercentage : 0
            );
            if (completionPct < 100) {
                showNotification(COMPLETE_REQUIRES_100_MSG, 'error');
                return;
            }

            isCompleting = true;
            
            var today = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
            
            // Check if there's already a "Completed" update to prevent duplicates
            const timelineEntries = document.querySelectorAll('.timeline-entry');
            var alreadyCompleted = false;
            timelineEntries.forEach(function(entry) {
                const title = entry.querySelector('.timeline-title')?.textContent.trim() || '';
                if (title.toLowerCase() === 'completed') {
                    alreadyCompleted = true;
                }
            });

            if (alreadyCompleted) {
                showNotification('Report is already marked as completed', 'info');
                // Just update the status and hide buttons
                updateStatusOnly();
                isCompleting = false;
                return;
            }
            
            // First add the completion update
            var updateFormData = new FormData();
            updateFormData.append('action', 'create_update');
            updateFormData.append('report_id', currentUpdatesReportId);
            updateFormData.append('report_type', currentUpdatesReportType);
            // Send the row's source so the backend resolves CIMM reports
            // (cimm_verification_reports) instead of only road_transportation_reports.
            // Without this the create_update step returns "Report not found" for
            // CIMM reports and surfaces an error in the upper-right notification.
            updateFormData.append('source', currentUpdatesReportSource);
            updateFormData.append('title', 'Completed');
            updateFormData.append('description', 'completed on ' + today);
            updateFormData.append('completion_percentage', '100');

            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: updateFormData
            })
            .then(parseProgressApiJson)
            .then(function(data) {
                if (data.success) {
                    // Now update the status
                    updateStatusOnly();
                } else {
                    throw new Error(data.message || 'Failed to add completion update');
                }
            })
            .catch(function(e) {
                showNotification(progressApiErrorMessage(e, 'Network error'), 'error');
                console.error(e);
                isCompleting = false;
            });
        }

        function completeReport() {
            requestCompleteOrCancel('complete');
        }

        function updateStatusOnly() {
            // complete_status marks the report completed and leaves it on
            // Completed Projects until Archive is clicked.
            var statusFormData = new FormData();
            statusFormData.append('action', 'complete_status');
            statusFormData.append('report_id', currentUpdatesReportId);
            statusFormData.append('source', currentUpdatesReportSource);

            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: statusFormData
            })
            .then(parseProgressApiJson)
            .then(function(data) {
                if (data.success) {
                    showNotification(data.message || 'Report completed successfully', 'success');
                    closeModal('updatesModal');
                    afterStatusActionRedirect();
                } else {
                    showNotification(data.message || 'Failed to update status', 'error');
                }
                isCompleting = false; // Reset flag
            })
            .catch(function(e) {
                showNotification(progressApiErrorMessage(e, 'Network error'), 'error');
                console.error(e);
                isCompleting = false; // Reset flag
            });
        }

        function executeCancelReport() {
            if (!currentUpdatesReportId) return;
            if (typeof IS_SYSTEM_ADMIN !== 'undefined' && IS_SYSTEM_ADMIN) return;
            
            var newStatus = (currentUpdatesReportSource === 'cimm') ? 'Cancelled' : 'cancelled';
            var formData = new FormData();
            formData.append('action', 'cancel_archive');
            formData.append('report_id', currentUpdatesReportId);
            formData.append('report_type', currentUpdatesReportType);
            formData.append('status', newStatus);
            formData.append('source', currentUpdatesReportSource);

            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showNotification(data.message, 'success');
                    closeModal('updatesModal');
                    afterStatusActionRedirect();
                } else {
                    showNotification(data.message || 'Failed to update status', 'error');
                }
            })
            .catch(function(e) {
                showNotification('Network error', 'error');
                console.error(e);
            });
        }

        function cancelReport() {
            requestCompleteOrCancel('cancel');
        }

        // Submit a completion/cancellation request (officers only). The backend
        // validates role + project category, notifies the matching supervisor,
        // and leaves the report status and archive untouched.
        function submitReviewRequest(requestType) {
            if (!currentUpdatesReportId) return;
            var btn = (requestType === 'completion') ? document.getElementById('completeBtn') : document.getElementById('cancelBtn');
            if (btn) btn.disabled = true;
            var orig = btn ? btn.innerHTML : '';
            if (btn) btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

            var fd = new FormData();
            fd.append('action', 'submit_review_request');
            fd.append('report_id', currentUpdatesReportId);
            fd.append('report_type', currentUpdatesReportType);
            fd.append('request_type', requestType);
            fd.append('source', currentUpdatesReportSource);

            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: fd
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showNotification(data.message, 'success');
                    if (btn) {
                        btn.disabled = true;
                        btn.innerHTML = (requestType === 'completion') ? 'Request Submitted' : 'Request Submitted';
                    }
                } else {
                    showNotification(data.message || 'Failed to submit request', 'error');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = orig;
                    }
                }
            })
            .catch(function(e) {
                showNotification('Network error', 'error');
                console.error(e);
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = orig;
                }
            });
        }

        // Self-contained Word export — lives on this page so a stale
        // progress-updates-common.js cache cannot drop Report Details.
        function exportUpdatesToExcel() {
            var timelineEntries = document.querySelectorAll('.timeline-entry');
            showNotification('Preparing document...', 'info');
            ensureExportReportDetails().then(function() {
                if (typeof fetchExportCompletionPercentages === 'function') {
                    return fetchExportCompletionPercentages();
                }
                return {};
            }).then(function(pctByUpdateId) {
                processImagesAndExport(timelineEntries, pctByUpdateId || {});
            });
        }

        function ensureExportReportDetails() {
            var existing = {};
            try {
                var row = document.querySelector('#recentReportsTable .report-table-row[data-id="' + currentUpdatesReportId + '"]');
                if (row && row.dataset.details) existing = JSON.parse(row.dataset.details) || {};
            } catch (e) {}
            if (currentUpdatesReportDetails && typeof currentUpdatesReportDetails === 'object') {
                existing = Object.assign({}, existing, currentUpdatesReportDetails);
            }
            var src = String(currentUpdatesReportSource || existing.source || '').toLowerCase();
            var type = currentUpdatesReportType || existing.report_type || 'transportation';
            var table = 'road_transportation_reports';
            if (src === 'cimm' || src === 'external') table = 'cimm_verification_reports';
            else if (src === 'infrastructure' || src === 'maintenance') {
                table = 'ipms_road_projects';
                if (!type || type === 'transportation') type = 'infrastructure_issue';
            } else {
                table = 'road_transportation_reports';
            }
            if (!currentUpdatesReportId) {
                currentUpdatesReportDetails = existing;
                return Promise.resolve();
            }
            var url = '../api/get_report_details.php?id=' + encodeURIComponent(currentUpdatesReportId)
                + '&type=' + encodeURIComponent(type)
                + '&table=' + encodeURIComponent(table);
            return fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success && data.report) {
                        currentUpdatesReportDetails = Object.assign({}, existing, data.report, {
                            source: existing.source || src || currentUpdatesReportSource
                        });
                    } else {
                        currentUpdatesReportDetails = existing;
                    }
                })
                .catch(function() {
                    currentUpdatesReportDetails = existing;
                });
        }

        function generateDocument(updates, firstDate, lastDate) {
            try {
                var totalUpdates = updates.length;
                var timeTaken = firstDate && lastDate ? calculateDaysBetween(firstDate, lastDate) : 0;
                var d = currentUpdatesReportDetails || {};
                function esc(val) {
                    var s = (val == null) ? '' : String(val);
                    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                }
                function pretty(val) {
                    if (val === null || val === undefined) return '';
                    var s = String(val).trim();
                    if (!s || s === '—' || s === '-' || s.toLowerCase() === 'null') return '';
                    return s;
                }
                function labelize(val) {
                    var s = pretty(val);
                    if (!s) return '';
                    return s.replace(/[-_]/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
                }
                function fmtBudget(val) {
                    if (val === null || val === undefined || String(val).trim() === '' || String(val).toLowerCase() === 'null') {
                        return '';
                    }
                    var n = parseFloat(val);
                    if (!isFinite(n)) return '';
                    return '₱ ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
                function fmtDate(val) {
                    var s = pretty(val);
                    if (!s) return '';
                    var dt = new Date(s);
                    if (isNaN(dt.getTime())) return s;
                    return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                }
                function pairRows(pairs) {
                    var html = '';
                    var buf = [];
                    function flush() {
                        if (!buf.length) return;
                        html += '<tr>';
                        buf.forEach(function(p) {
                            html += '<td class="lbl">' + esc(p[0]) + '</td><td>' + esc(p[1]).replace(/\r\n|\r|\n/g, '<br>') + '</td>';
                        });
                        if (buf.length === 1) html += '<td class="lbl"></td><td></td>';
                        html += '</tr>';
                        buf = [];
                    }
                    pairs.forEach(function(p) {
                        if (!p) return;
                        var always = (p[2] === 'always');
                        if (!always && !pretty(p[1])) return;
                        if (p[2] === 'full') {
                            flush();
                            html += '<tr><td class="lbl">' + esc(p[0]) + '</td><td colspan="3">' + esc(p[1]).replace(/\r\n|\r|\n/g, '<br>') + '</td></tr>';
                            return;
                        }
                        buf.push(p);
                        if (buf.length === 2) flush();
                    });
                    flush();
                    return html;
                }
                var sourceLabels = { lgu: 'LGU Monitoring', citizen: 'Citizen', cimm: 'CIMM', infrastructure: 'Infrastructure Projects', external: 'CIMM', maintenance: 'Maintenance' };
                var sourceRaw = pretty(d.source || d.source_system || d.report_source || currentUpdatesReportSource);
                var sourceLabel = sourceLabels[(sourceRaw || '').toLowerCase()] || labelize(sourceRaw);
                var assignment = pretty(d.assignment_officer) || pretty(d.assigned_to) || '';
                if (!assignment && pretty(d.assignment_status)) {
                    assignment = (String(d.assignment_status).toLowerCase() === 'assigned') ? 'Assigned' : 'Unassigned';
                }
                var lat = pretty(d.latitude || d.coord_lat);
                var lng = pretty(d.longitude || d.coord_lng);
                var coords = (lat && lng && lat !== '0' && lng !== '0') ? (lat + ', ' + lng) : '';
                var cimmVerify = pretty(d.cimm_status);
                var description = pretty(d.description || d.issue);
                var cat = String(d.report_category || '').toLowerCase();
                var type = String(d.report_type || currentUpdatesReportType || '').toLowerCase();
                var isTransportation = (cat === 'transportation') || (cat !== 'road' && type === 'transportation');
                var detailPairs = [
                    ['Source', sourceLabel],
                    ['Status', labelize(d.status || currentUpdatesReportStatus)],
                    ['Priority', labelize(d.priority)],
                    ['Severity', labelize(d.severity)],
                    ['Category', labelize(d.report_category)],
                    ['Type', labelize(d.report_type)],
                    ['Department', labelize(d.department)],
                    ['Assignment', assignment],
                    ['Engineer', pretty(d.engineer || d.cimm_engineer_name)],
                ];
                if (!isTransportation) {
                    detailPairs.push(['Budget Allocation', fmtBudget(
                        (d.budget_allocation !== null && d.budget_allocation !== undefined && d.budget_allocation !== '')
                            ? d.budget_allocation
                            : d.cimm_budget
                    ), 'always']);
                }
                var detailsRows = pairRows(detailPairs.concat([
                    ['Reported By', pretty(d.reporter_name)],
                    ['Created', fmtDate(d.created_at || d.created_date || d.submitted_at)],
                    ['CIMM Verification', cimmVerify],
                    ['Verified By', pretty(d.cimm_verified_by)],
                    ['Verified At', fmtDate(d.cimm_verified_at)],
                    ['Creator', pretty(d.creator_full_name)],
                    ['Contact', pretty(d.creator_phone)],
                    ['Email', pretty(d.creator_email)],
                    ['Location', pretty(d.location), 'full'],
                    ['Coordinates', coords, 'full'],
                    ['Description', description, 'full']
                ]));
                var displayId = pretty(d.report_id) || String(currentUpdatesReportId || '');
                var displayTitle = pretty(d.title);
                var exportedOn = new Date().toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });

                var htmlContent = `
                <html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word'>
                <head>
                <meta charset="utf-8">
                <title>Progress Updates Report</title>
                <style>
                    body { font-family: 'Calibri', Arial, sans-serif; font-size: 10pt; line-height: 1.25; margin: 12px 16px; }
                    h1 { color: #2E74B5; font-size: 16pt; text-align: center; margin: 0 0 4px 0; }
                    h2 { color: #2E74B5; font-size: 12pt; margin: 12px 0 6px 0; border-bottom: 1px solid #2E74B5; padding-bottom: 3px; }
                    .report-info { text-align: center; color: #666; margin: 0; font-size: 9pt; }
                    .report-title { text-align: center; font-size: 12pt; font-weight: bold; color: #1f2937; margin: 2px 0 8px 0; }
                    .details-table, .summary-table { border-collapse: collapse; width: 100%; margin: 0 0 8px 0; font-size: 10pt; }
                    .details-table td, .summary-table td { border: 1px solid #d0d7de; padding: 3px 8px; vertical-align: top; }
                    .details-table td.lbl, .summary-table td.lbl { background-color: #f3f6f9; font-weight: bold; width: 16%; color: #334155; white-space: nowrap; }
                    .update-entry { margin: 0 0 8px 0; padding: 8px 10px; background-color: #f8f9fa; border-left: 3px solid #2E74B5; }
                    .update-header { color: #2E74B5; font-weight: bold; font-size: 10.5pt; margin: 0 0 2px 0; }
                    .update-author { color: #666; font-style: italic; font-size: 9pt; margin: 0 0 4px 0; }
                    .update-description { margin: 0; }
                    .update-images { margin-top: 6px; }
                    .update-images img { width: 160px; height: auto; margin: 3px; border: 1px solid #ddd; }
                    .image-count { color: #666; font-style: italic; font-size: 9pt; }
                </style>
                </head>
                <body>
                    <h1>Progress Updates Report</h1>
                    <p class="report-info">Report #${esc(displayId)} &nbsp;&middot;&nbsp; Exported ${esc(exportedOn)}</p>
                    ${displayTitle ? `<p class="report-title">${esc(displayTitle)}</p>` : '<div style="height:6px;"></div>'}
                    ${detailsRows ? `<h2>Report Details</h2><table class="details-table">${detailsRows}</table>` : ''}
                    <h2>Project Summary</h2>
                    <table class="summary-table">
                        <tr>
                            <td class="lbl">Start</td><td>${esc(firstDate || 'N/A')}</td>
                            <td class="lbl">End</td><td>${esc(lastDate || 'N/A')}</td>
                        </tr>
                        <tr>
                            <td class="lbl">Updates</td><td>${totalUpdates}</td>
                            <td class="lbl">Duration</td><td>${timeTaken} days</td>
                        </tr>
                    </table>
                    <h2>Progress Timeline</h2>
                `;
                if (!updates.length) {
                    htmlContent += `<p class="image-count">No progress updates yet.</p>`;
                }
                updates.forEach(function(update) {
                    htmlContent += `
                    <div class="update-entry">
                        <div class="update-header">${esc(update.date)} - ${esc(update.title || 'Update')}</div>
                        <div class="update-author">By: ${esc(update.author)}</div>
                        ${update.completionPercentage ? `<div class="update-author"><strong>Completion Percentage:</strong> ${esc(update.completionPercentage)}</div>` : ''}
                        <div class="update-description">${esc(update.description || 'No description').replace(/\r\n|\r|\n/g, '<br>')}</div>
                        <div class="update-images">
                    `;
                    if (update.images.length > 0) {
                        update.images.forEach(function(imgData) {
                            if (imgData) htmlContent += `<img src="${imgData}" alt="Update image" />`;
                        });
                    } else {
                        htmlContent += `<div class="image-count">No images attached</div>`;
                    }
                    htmlContent += `</div></div>`;
                });
                htmlContent += `</body></html>`;

                var blob = new Blob(['\ufeff', htmlContent], { type: 'application/msword' });
                var fileLabel = pretty(d.report_id) || String(currentUpdatesReportId || 'Report');
                var fileName = ('Report ' + fileLabel).replace(/[<>:"/\\|?*\u0000-\u001f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120) + '.doc';
                var link = document.createElement('a');
                link.href = URL.createObjectURL(blob);
                link.download = fileName;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                showNotification('Document exported successfully', 'success');
            } catch (error) {
                console.error('Export error:', error);
                showNotification('Failed to export document', 'error');
            }
        }

        function calculateDaysBetween(dateStr1, dateStr2) {
            try {
                var date1 = new Date(dateStr1);
                var date2 = new Date(dateStr2);
                var diffTime = Math.abs(date2 - date1);
                return Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            } catch (e) {
                return 0;
            }
        }

        function renderUpdateFilePreviews() {
            var preview = document.getElementById('updateFilePreviews');
            if (!preview) return;
            updatePreviewCounter++;
            var currentRender = updatePreviewCounter;
            preview.innerHTML = '';
            if (updateSelectedFiles.length === 0) return;
            updateSelectedFiles.forEach(function(f, index) {
                var item = document.createElement('div');
                item.className = 'file-preview-item';
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'remove-preview';
                removeBtn.innerHTML = '&times;';
                removeBtn.addEventListener('click', function(idx) {
                    return function() {
                        if (idx >= 0 && idx < updateSelectedFiles.length) {
                            updateSelectedFiles.splice(idx, 1);
                        }
                        renderUpdateFilePreviews();
                    };
                }(index));
                if (f.type.startsWith('image/')) {
                    var reader = new FileReader();
                    reader.onload = function(ev) {
                        if (currentRender !== updatePreviewCounter) return;
                        item.innerHTML = '<img src="' + ev.target.result + '">';
                        item.appendChild(removeBtn);
                    };
                    reader.readAsDataURL(f);
                } else {
                    item.style.cssText = 'display:flex;align-items:center;justify-content:center;background:#f0f4fa;font-size:11px;color:#3762c8;';
                    item.innerHTML = '<i class="fas fa-video" style="font-size:20px;"></i>';
                    item.appendChild(removeBtn);
                }
                preview.appendChild(item);
            });
        }
    </script>
    <script>
    // ===== TOMTOM API FEATURES =====

    let routeFromPoint = null, routeToPoint = null;
    let routeLayer = null;
    let accidentsLayer = null;
    let accidentsVisible = false;
    let busStopsLayer = null;
    let busStopsVisible = false;
    let railStationsLayer = null;
    let railStationsVisible = false;
    let busRoutesLayer = null;
    let selectedOsmRouteId = null;
    let evMarkersLayer = null;
    let mapClickHandler = null;
    let commuteFrom = null, commuteTo = null;
    let commuteMarkersLayer = null;

    const MAP_PANEL_TOOL_BTNS = {
        routePlannerPanel: 'btnRoutePlanner',
        commutePlannerPanel: 'btnCommutePlanner',
        evChargingPanel: 'btnEVCharging'
    };

    function mapToolsItemLabelHtml(iconClass, label) {
        return '<span class="map-tools-item-main"><i class="fas fa-' + iconClass + '"></i> ' + label + '</span>'
            + '<span class="map-tools-item-state">Off</span>';
    }

    function updateToolsActiveBadge() {
        const menu = document.getElementById('toolsDropdownMenu');
        const toggle = document.getElementById('toolsDropdownBtn');
        const countEl = document.getElementById('toolsActiveCount');
        if (!menu || !toggle || !countEl) return;
        const count = menu.querySelectorAll('.map-tools-item.is-on:not(.map-tools-action)').length;
        countEl.textContent = String(count);
        toggle.classList.toggle('has-active', count > 0);
    }

    function setMapToolBtnStyle(btnId, active) {
        const btn = document.getElementById(btnId);
        if (!btn || btn.classList.contains('is-loading')) return;
        btn.classList.toggle('is-on', !!active);
        btn.classList.toggle('is-off', !active);
        btn.setAttribute('aria-checked', active ? 'true' : 'false');
        const state = btn.querySelector('.map-tools-item-state');
        if (state) state.textContent = active ? 'On' : 'Off';
        btn.style.background = '';
        btn.style.color = '';
        btn.style.borderColor = '';
        updateToolsActiveBadge();
    }

    function setAllMapPanelToolBtnsOff() {
        Object.keys(MAP_PANEL_TOOL_BTNS).forEach(function(panelId) {
            setMapToolBtnStyle(MAP_PANEL_TOOL_BTNS[panelId], false);
        });
    }

    let toolsDropdownOpen = false;
    function toggleToolsDropdown() {
        const menu = document.getElementById('toolsDropdownMenu');
        const btn = document.getElementById('toolsDropdownBtn');
        if (!menu || !btn) return;
        toolsDropdownOpen = !toolsDropdownOpen;
        menu.classList.toggle('is-open', toolsDropdownOpen);
        btn.setAttribute('aria-expanded', toolsDropdownOpen ? 'true' : 'false');
    }
    function closeToolsDropdown() {
        const menu = document.getElementById('toolsDropdownMenu');
        const btn = document.getElementById('toolsDropdownBtn');
        if (!menu || !btn) return;
        toolsDropdownOpen = false;
        menu.classList.remove('is-open');
        btn.setAttribute('aria-expanded', 'false');
    }
    window.toggleToolsDropdown = toggleToolsDropdown;
    window.closeToolsDropdown = closeToolsDropdown;
    document.addEventListener('click', function(e) {
        if (!e.target.closest('#mapTools')) closeToolsDropdown();
    });

    function closePanel(panelId) {
        document.getElementById(panelId).style.display = 'none';
        if (panelId === 'ptRoutesPanel') setPtRoutesBtnStyle(false);
        if (panelId === 'commutePlannerPanel') clearCommutePlannerState(false);
        if (panelId === 'evChargingPanel') clearEVCharging(false);
        if (panelId === 'routePlannerPanel') clearRoute();
        if (MAP_PANEL_TOOL_BTNS[panelId]) setMapToolBtnStyle(MAP_PANEL_TOOL_BTNS[panelId], false);
        if (mapClickHandler) {
            map.off('click', mapClickHandler);
            mapClickHandler = null;
        }
    }

    function isMapToolOn(btnId) {
        const btn = document.getElementById(btnId);
        return !!(btn && btn.classList.contains('is-on'));
    }

    // ===== SEARCH / GEOCODING =====
    function doMapSearch() {
        const q = document.getElementById('mapSearchInput').value.trim();
        if (!q) return;
        const resultsDiv = document.getElementById('mapSearchResults');

        TomTomServices.poiSearch(q, { limit: 10 }).then(data => {
            if (!data.success || !data.data || !data.data.results) {
                resultsDiv.style.display = 'none';
                return;
            }
            const results = data.data.results;
            if (results.length > 0 && results[0].position) {
                flyToLocation(results[0].position.lat, results[0].position.lon, 15);
            }
            resultsDiv.innerHTML = results.map(r => {
                const pos = r.position || {};
                return `<div class="search-result-item" onclick="flyToLocation(${pos.lat || 0}, ${pos.lon || 0}, 15)">
                    <i class="fas fa-map-pin" style="color:#3762c8;margin-right:6px;"></i>${r.poi?.name || r.address?.freeformAddress || 'Unknown'}
                    <small>${r.address?.freeformAddress || ''}</small>
                </div>`;
            }).join('');
            resultsDiv.style.display = 'block';
        });
    }

    document.addEventListener('click', function(e) {
        if (!e.target.closest('.map-search-box')) {
            document.getElementById('mapSearchResults').style.display = 'none';
        }
    });

    document.getElementById('mapSearchInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') doMapSearch();
    });

    function flyToLocation(lat, lng, zoom) {
        map.setView([lat, lng], zoom || 14);
        document.getElementById('mapSearchResults').style.display = 'none';
        if (pinMarker) map.removeLayer(pinMarker);
        pinMarker = L.marker([lat, lng], { draggable: true }).addTo(map);
        pinLat.value = lat;
        pinLng.value = lng;
        reportPanel.style.display = 'block';
        document.getElementById('gis-location-warning').style.display = 'none';
        
        // Run full GIS analysis
        analyzePinnedLocation(lat, lng);
        
        // Re-analyze on drag
        pinMarker.on('dragend', function() {
            const pos = pinMarker.getLatLng();
            if (!isInsideQCBounds(pos.lat, pos.lng)) {
                showNotification('Please select a location within Quezon City only.', 'error');
                pinMarker.setLatLng([lat, lng]);
                return;
            }
            pinLat.value = pos.lat;
            pinLng.value = pos.lng;
            analyzePinnedLocation(pos.lat, pos.lng);
        });
    }

    // ===== ROUTE PLANNER =====
    function showRoutePlanner() {
        if (isMapToolOn('btnRoutePlanner')) {
            closePanel('routePlannerPanel');
            return;
        }
        closeAllPanels();
        closeToolsDropdown();
        document.getElementById('routePlannerPanel').style.display = 'block';
        setMapToolBtnStyle('btnRoutePlanner', true);
        showNotification('Click on the map to set start point, then destination', 'info');
        routeFromPoint = null;
        routeToPoint = null;
        clearRoute();
        // Set map click to set start point
        if (mapClickHandler) map.off('click', mapClickHandler);
        mapClickHandler = function(e) {
            if (!routeFromPoint) {
                routeFromPoint = e.latlng;
                document.getElementById('routeFrom').value = e.latlng.lat.toFixed(5) + ', ' + e.latlng.lng.toFixed(5);
                L.circleMarker(e.latlng, { color: '#10b981', radius: 8, fillOpacity: 0.8 }).addTo(map).bindPopup('Start').openPopup();
                showNotification('Now click destination point', 'info');
            } else if (!routeToPoint) {
                routeToPoint = e.latlng;
                document.getElementById('routeTo').value = e.latlng.lat.toFixed(5) + ', ' + e.latlng.lng.toFixed(5);
                L.circleMarker(e.latlng, { color: '#ef4444', radius: 8, fillOpacity: 0.8 }).addTo(map).bindPopup('End').openPopup();
                map.off('click', mapClickHandler);
                mapClickHandler = null;
                planRoute();
            }
        };
        map.on('click', mapClickHandler);
    }

    function routeFromClick() {
        if (mapClickHandler) map.off('click', mapClickHandler);
        routeFromPoint = null;
        document.getElementById('routeFrom').value = '';
        mapClickHandler = function(e) {
            routeFromPoint = e.latlng;
            document.getElementById('routeFrom').value = e.latlng.lat.toFixed(5) + ', ' + e.latlng.lng.toFixed(5);
            L.circleMarker(e.latlng, { color: '#10b981', radius: 8, fillOpacity: 0.8 }).addTo(map).bindPopup('Start').openPopup();
            map.off('click', mapClickHandler);
            mapClickHandler = null;
        };
        map.on('click', mapClickHandler);
    }

    function routeToClick() {
        if (mapClickHandler) map.off('click', mapClickHandler);
        routeToPoint = null;
        document.getElementById('routeTo').value = '';
        mapClickHandler = function(e) {
            routeToPoint = e.latlng;
            document.getElementById('routeTo').value = e.latlng.lat.toFixed(5) + ', ' + e.latlng.lng.toFixed(5);
            L.circleMarker(e.latlng, { color: '#ef4444', radius: 8, fillOpacity: 0.8 }).addTo(map).bindPopup('End').openPopup();
            map.off('click', mapClickHandler);
            mapClickHandler = null;
        };
        map.on('click', mapClickHandler);
    }

    function planRoute() {
        const fromText = document.getElementById('routeFrom').value.trim();
        const toText = document.getElementById('routeTo').value.trim();
        const mode = document.getElementById('routeMode').value;

        // Try to parse lat,lng or geocode
        const fromMatch = fromText.match(/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/);
        const toMatch = toText.match(/^(-?\d+\.?\d*),\s*(-?\d+\.?\d*)$/);

        if (!fromMatch && !routeFromPoint) { showNotification('Please set a start location', 'error'); return; }
        if (!toMatch && !routeToPoint) { showNotification('Please set a destination', 'error'); return; }

        const fromLat = routeFromPoint ? routeFromPoint.lat : parseFloat(fromMatch[1]);
        const fromLng = routeFromPoint ? routeFromPoint.lng : parseFloat(fromMatch[2]);
        const toLat = routeToPoint ? routeToPoint.lat : parseFloat(toMatch[1]);
        const toLng = routeToPoint ? routeToPoint.lng : parseFloat(toMatch[2]);

        const routes = mode === 'truck' ? TomTomServices.extendedRoute(fromLat, fromLng, toLat, toLng, { vehicleCommercial: 'true' })
            : mode === 'pedestrian' ? TomTomServices.extendedRoute(fromLat, fromLng, toLat, toLng, { travelMode: 'pedestrian' })
            : mode === 'bicycle' ? TomTomServices.extendedRoute(fromLat, fromLng, toLat, toLng, { travelMode: 'bicycle' })
            : TomTomServices.calculateRoute(fromLat, fromLng, toLat, toLng);

        routes.then(data => {
            if (!data.success || !data.data) {
                showNotification('Route calculation failed', 'error');
                return;
            }
            const route = data.data;
            const summary = route.routes?.[0]?.summary;
            if (summary) {
                const distKm = (summary.lengthInMeters / 1000).toFixed(1);
                const timeMin = Math.round(summary.travelTimeInSeconds / 60);
                document.getElementById('routeInfo').style.display = 'block';
                document.getElementById('routeInfo').innerHTML =
                    `<strong>Route Summary</strong><br>
                    Distance: ${distKm} km<br>
                    Duration: ${timeMin} min<br>
                    Mode: ${mode}`;

                if (route.routes[0].legs) {
                    drawRoutePolyline(route.routes[0]);
                }
            } else {
                showNotification('No route found', 'info');
            }
        });
    }

    function drawRoutePolyline(routeData) {
        if (routeLayer) map.removeLayer(routeLayer);
        try {
            const points = [];
            if (routeData.legs) {
                routeData.legs.forEach(leg => {
                    // v3 API uses leg.path.coordinates [lng, lat] arrays
                    if (leg.path && leg.path.coordinates) {
                        leg.path.coordinates.forEach(c => points.push([c[1], c[0]]));
                    // v1 API uses leg.points[].latitude / .longitude
                    } else if (leg.points) {
                        leg.points.forEach(p => points.push([p.latitude, p.longitude]));
                    }
                });
            }
            // Fallback: check for path at route level
            if (points.length === 0 && routeData.path && routeData.path.coordinates) {
                routeData.path.coordinates.forEach(c => points.push([c[1], c[0]]));
            }
            if (points.length > 0) {
                routeLayer = L.polyline(points, { color: '#3762c8', weight: 4, opacity: 0.7 }).addTo(map);
                map.fitBounds(routeLayer.getBounds().pad(0.1));
            }
        } catch (e) {
            console.error('Draw route error:', e);
        }
    }

    function clearRoute() {
        if (routeLayer) { map.removeLayer(routeLayer); routeLayer = null; }
        document.getElementById('routeInfo').style.display = 'none';
        document.getElementById('routeFrom').value = '';
        document.getElementById('routeTo').value = '';
        routeFromPoint = null;
        routeToPoint = null;
    }

    // ===== TOMTOM INCIDENT HELPERS =====
    const INCIDENT_CATEGORY_LABELS = {
        0: 'Unknown', 1: 'Accident', 2: 'Fog', 3: 'Dangerous Conditions',
        4: 'Rain', 5: 'Ice', 6: 'Jam', 7: 'Lane Closed', 8: 'Road Closed',
        9: 'Road Works', 10: 'Wind', 11: 'Flooding', 14: 'Broken Down Vehicle'
    };

    function collectTomTomIncidents(payload) {
        if (!payload) return [];
        if (Array.isArray(payload.incidents)) return payload.incidents;
        if (payload.tm && Array.isArray(payload.tm.poi)) return payload.tm.poi;
        if (payload.data) return collectTomTomIncidents(payload.data);
        return [];
    }

    function incidentLatLng(inc) {
        const geom = inc.geometry || {};
        let coords = geom.coordinates;
        if (Array.isArray(coords) && coords.length) {
            if (typeof coords[0] === 'number') {
                return [coords[1], coords[0]];
            }
            if (Array.isArray(coords[0]) && typeof coords[0][0] === 'number') {
                const mid = coords[Math.floor(coords.length / 2)];
                return [mid[1], mid[0]];
            }
            if (Array.isArray(coords[0]) && Array.isArray(coords[0][0])) {
                const ring = coords[0];
                const mid = ring[Math.floor(ring.length / 2)];
                return [mid[1], mid[0]];
            }
        }
        if (inc.p && inc.p.x != null && inc.p.y != null) return [inc.p.y, inc.p.x];
        const point = geom.point || inc.properties?.geometryCoordinates;
        if (point) {
            const lat = point.lat || point.latitude;
            const lng = point.lon || point.lng || point.longitude;
            if (lat != null && lng != null) return [lat, lng];
        }
        return null;
    }

    function incidentCategory(inc) {
        const props = inc.properties || inc;
        const cat = props.iconCategory ?? props.ic;
        if (cat === 1 || cat === '1' || String(cat).toLowerCase() === 'accident') return 1;
        const events = props.events || [];
        for (let i = 0; i < events.length; i++) {
            if (events[i].iconCategory === 1 || /accident/i.test(events[i].description || '')) return 1;
        }
        const n = parseInt(cat, 10);
        return isNaN(n) ? 0 : n;
    }

    function incidentPopupHtml(inc) {
        const props = inc.properties || inc;
        const cat = incidentCategory(inc);
        const events = props.events || [];
        const desc = events.map(function(e) { return e.description; }).filter(Boolean).join(' — ')
            || INCIDENT_CATEGORY_LABELS[cat]
            || 'Traffic incident';
        const from = props.from || '';
        const to = props.to || '';
        const delayMin = props.delay ? Math.round(props.delay / 60) : null;
        let html = '<b>' + escapeHtml(desc) + '</b>';
        html += '<br><small>' + escapeHtml(INCIDENT_CATEGORY_LABELS[cat] || 'Incident') + '</small>';
        if (from || to) {
            html += '<br><small>' + escapeHtml([from, to].filter(Boolean).join(' → ')) + '</small>';
        }
        if (delayMin) html += '<br><small>Delay: ' + delayMin + ' min</small>';
        return html;
    }

    function incidentStyle(cat) {
        if (cat === 1) return { color: '#dc2626', css: 'cat-accident', icon: 'car-crash' };
        if (cat === 8 || cat === 7) return { color: '#111827', css: 'cat-closed', icon: 'ban' };
        if (cat === 6) return { color: '#f59e0b', css: 'cat-jam', icon: 'traffic-light' };
        if (cat === 9) return { color: '#ca8a04', css: 'cat-works', icon: 'helmet-safety' };
        return { color: '#6b7280', css: 'cat-other', icon: 'exclamation' };
    }

    function incidentLineLatLngs(inc) {
        const geom = inc.geometry || {};
        const coords = geom.coordinates;
        if (geom.type === 'LineString' && Array.isArray(coords) && coords.length) {
            return coords.map(function(c) { return [c[1], c[0]]; });
        }
        return null;
    }

    // Server file cache for Incidents / Bus / Rail / PT Routes (no TTL — Sync Layers refreshes)
    const MAP_LAYERS_CACHE_API = '../api/map_layers/cache.php';
    const LAYER_CACHE_STORAGE_KEY = 'qc_map_layer_cache_v2';
    const LAYER_RENDER_CHUNK = 35;
    const layerCaches = {
        incidents: { fetchedAt: 0, items: null, loading: false },
        bus: { fetchedAt: 0, items: null, loading: false },
        rail: { fetchedAt: 0, items: null, loading: false },
        osmRoutes: { fetchedAt: 0, items: null, loading: false }
    };
    const TOGGLE_BTN_LABELS = {
        toggleAccidentsBtn: mapToolsItemLabelHtml('exclamation-triangle', 'Incidents'),
        toggleBusStopsBtn: mapToolsItemLabelHtml('bus', 'Bus'),
        toggleRailStationsBtn: mapToolsItemLabelHtml('train', 'Rail'),
        togglePtRoutesBtn: mapToolsItemLabelHtml('route', 'PT Routes'),
        syncMapLayersBtn: '<span class="map-tools-item-main"><i class="fas fa-sync-alt"></i> Sync Layers</span>'
    };
    // Cancel mid-flight chunked paints when the user toggles a layer off
    let accidentRenderGen = 0;

    function hasLayerCache(cache) {
        return Array.isArray(cache.items);
    }
    function isLayerCacheFresh(cache) {
        // File-backed: memory/session copy is good until Sync Layers
        return hasLayerCache(cache);
    }

    // Yield so Leaflet pin paints / JSON work don't freeze the UI thread
    function yieldToMain() {
        return new Promise(function(resolve) {
            if (typeof requestAnimationFrame === 'function') {
                requestAnimationFrame(function() { setTimeout(resolve, 0); });
            } else {
                setTimeout(resolve, 0);
            }
        });
    }

    function mapOverChunks(items, eachFn, shouldContinue, chunkSize) {
        items = items || [];
        chunkSize = chunkSize || LAYER_RENDER_CHUNK;
        let i = 0;
        let count = 0;
        function step() {
            if (typeof shouldContinue === 'function' && !shouldContinue()) {
                return Promise.resolve(count);
            }
            const end = Math.min(i + chunkSize, items.length);
            for (; i < end; i++) {
                if (eachFn(items[i], i)) count++;
            }
            if (i >= items.length) return Promise.resolve(count);
            return yieldToMain().then(step);
        }
        return step();
    }

    function loadLayerCachesFromStorage() {
        try {
            const raw = localStorage.getItem(LAYER_CACHE_STORAGE_KEY);
            if (!raw) return;
            const stored = JSON.parse(raw);
            ['incidents', 'bus', 'rail'].forEach(function(key) {
                const entry = stored && stored[key];
                if (!entry || !Array.isArray(entry.items) || !entry.fetchedAt) return;
                layerCaches[key].items = entry.items;
                layerCaches[key].fetchedAt = entry.fetchedAt;
            });
            // osmRoutes uses IndexedDB — see loadOsmRoutesFromIdb / saveOsmRoutesToIdb
        } catch (e) { /* ignore corrupt/quota errors */ }
    }
    function saveLayerCacheToStorage(key) {
        if (key === 'osmRoutes') {
            saveOsmRoutesToIdb();
            return;
        }
        setTimeout(function() {
            try {
                let stored = {};
                try {
                    stored = JSON.parse(localStorage.getItem(LAYER_CACHE_STORAGE_KEY) || '{}') || {};
                } catch (e) {
                    stored = {};
                }
                const cache = layerCaches[key];
                if (!hasLayerCache(cache) || !cache.fetchedAt) return;
                stored[key] = { fetchedAt: cache.fetchedAt, items: cache.items };
                localStorage.setItem(LAYER_CACHE_STORAGE_KEY, JSON.stringify(stored));
            } catch (e) { /* ignore quota errors */ }
        }, 0);
    }

    function clearClientLayerCaches() {
        ['incidents', 'bus', 'rail', 'osmRoutes'].forEach(function(key) {
            layerCaches[key].items = null;
            layerCaches[key].fetchedAt = 0;
            layerCaches[key].loading = false;
        });
        try { localStorage.removeItem(LAYER_CACHE_STORAGE_KEY); } catch (e) { /* ignore */ }
        try { localStorage.removeItem('qc_map_layer_cache_v1'); } catch (e) { /* ignore */ }
        openOsmRoutesIdb().then(function(db) {
            return new Promise(function(resolve, reject) {
                const tx = db.transaction(OSM_ROUTES_IDB_STORE, 'readwrite');
                tx.objectStore(OSM_ROUTES_IDB_STORE).delete('osmRoutes');
                tx.oncomplete = function() { resolve(true); };
                tx.onerror = function() { reject(tx.error); };
            });
        }).catch(function() { /* ignore */ });
    }

    // OSM routes are too large for localStorage; IndexedDB keeps the 1h client cache across refresh
    const OSM_ROUTES_IDB_NAME = 'qc_map_osm_routes_v1';
    const OSM_ROUTES_IDB_STORE = 'layers';
    function openOsmRoutesIdb() {
        return new Promise(function(resolve, reject) {
            if (!window.indexedDB) {
                reject(new Error('IndexedDB unavailable'));
                return;
            }
            const req = indexedDB.open(OSM_ROUTES_IDB_NAME, 1);
            req.onupgradeneeded = function() {
                const db = req.result;
                if (!db.objectStoreNames.contains(OSM_ROUTES_IDB_STORE)) {
                    db.createObjectStore(OSM_ROUTES_IDB_STORE);
                }
            };
            req.onsuccess = function() { resolve(req.result); };
            req.onerror = function() { reject(req.error || new Error('IndexedDB open failed')); };
        });
    }
    function loadOsmRoutesFromIdb() {
        return openOsmRoutesIdb().then(function(db) {
            return new Promise(function(resolve, reject) {
                const tx = db.transaction(OSM_ROUTES_IDB_STORE, 'readonly');
                const req = tx.objectStore(OSM_ROUTES_IDB_STORE).get('osmRoutes');
                req.onsuccess = function() {
                    db.close();
                    resolve(req.result || null);
                };
                req.onerror = function() {
                    db.close();
                    reject(req.error);
                };
            });
        }).then(function(entry) {
            if (!entry || !Array.isArray(entry.items) || !entry.fetchedAt) return false;
            layerCaches.osmRoutes.items = entry.items;
            layerCaches.osmRoutes.fetchedAt = entry.fetchedAt;
            return true;
        }).catch(function() { return false; });
    }
    function saveOsmRoutesToIdb() {
        const cache = layerCaches.osmRoutes;
        if (!hasLayerCache(cache) || !cache.fetchedAt) return Promise.resolve();
        return openOsmRoutesIdb().then(function(db) {
            return new Promise(function(resolve, reject) {
                const tx = db.transaction(OSM_ROUTES_IDB_STORE, 'readwrite');
                tx.objectStore(OSM_ROUTES_IDB_STORE).put({
                    fetchedAt: cache.fetchedAt,
                    items: cache.items
                }, 'osmRoutes');
                tx.oncomplete = function() {
                    db.close();
                    resolve();
                };
                tx.onerror = function() {
                    db.close();
                    reject(tx.error);
                };
            });
        }).catch(function() { /* quota / private mode */ });
    }
    // Parse localStorage off the critical path so a large cache doesn't freeze first paint
    const layerCacheHydrated = new Promise(function(resolve) {
        setTimeout(function() {
            loadLayerCachesFromStorage();
            resolve();
        }, 0);
    });
    // Resolve before any OSM fetch so refresh can reuse IndexedDB within 1h
    const osmRoutesIdbReady = loadOsmRoutesFromIdb();

    function setToggleLoading(btnId, loading, restoreStyleFn) {
        const btn = document.getElementById(btnId);
        if (!btn) return;
        btn.classList.toggle('is-loading', !!loading);
        btn.disabled = !!loading;
        if (loading) {
            if (btn.classList.contains('map-tools-action')) {
                btn.innerHTML = '<span class="map-tools-item-main"><i class="fas fa-spinner fa-spin"></i> Loading</span>';
            } else {
                btn.innerHTML = '<span class="map-tools-item-main"><i class="fas fa-spinner fa-spin"></i> Loading</span>'
                    + '<span class="map-tools-item-state">…</span>';
            }
            return;
        }
        btn.innerHTML = TOGGLE_BTN_LABELS[btnId] || btn.innerHTML;
        if (typeof restoreStyleFn === 'function') restoreStyleFn();
    }

    function renderAccidentPinsFromData(incidents) {
        const gen = ++accidentRenderGen;
        if (accidentsLayer) {
            map.removeLayer(accidentsLayer);
            accidentsLayer = null;
        }
        accidentsLayer = L.layerGroup().addTo(map);
        const layer = accidentsLayer;

        return mapOverChunks(incidents, function(inc) {
            if (gen !== accidentRenderGen || !accidentsVisible || layer !== accidentsLayer) return false;
            const pos = incidentLatLng(inc);
            if (!pos || pos[0] == null || pos[1] == null) return false;
            if (typeof isInsideQCBounds === 'function' && !isInsideQCBounds(pos[0], pos[1])) return false;
            const cat = incidentCategory(inc);
            const style = incidentStyle(cat);
            const popup = incidentPopupHtml(inc);
            const line = incidentLineLatLngs(inc);
            if (line && line.length > 1) {
                L.polyline(line, { color: style.color, weight: 5, opacity: 0.85 })
                    .bindPopup(popup)
                    .addTo(layer);
            }
            const icon = L.divIcon({
                html: '<div class="incident-map-pin ' + style.css + '"><i class="fas fa-' + style.icon + '"></i></div>',
                className: '',
                iconSize: [28, 28],
                iconAnchor: [14, 14]
            });
            L.marker(pos, { icon: icon, zIndexOffset: 600 })
                .bindPopup(popup)
                .addTo(layer);
            return true;
        }, function() {
            return gen === accidentRenderGen && accidentsVisible && layer === accidentsLayer;
        });
    }

    function fetchAccidentIncidents() {
        return fetch(MAP_LAYERS_CACHE_API + '?layer=incidents', { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'Could not load traffic incidents');
                }
                const payload = data.data || {};
                if (Array.isArray(payload.items)) return payload.items;
                return collectTomTomIncidents(payload);
            });
    }

    function loadAccidentPins(silent) {
        const cache = layerCaches.incidents;
        let showedCache = false;
        let paintPromise = Promise.resolve(0);

        if (accidentsVisible && hasLayerCache(cache)) {
            setToggleLoading('toggleAccidentsBtn', true);
            paintPromise = renderAccidentPinsFromData(cache.items).then(function(count) {
                showedCache = true;
                setToggleLoading('toggleAccidentsBtn', false, function() {
                    setAccidentToggleStyle(accidentsVisible);
                });
                if (!silent && isLayerCacheFresh(cache)) {
                    showNotification(count ? (count + ' live incident' + (count === 1 ? '' : 's') + ' on the map') : 'No live traffic incidents in Quezon City', 'info');
                }
                return count;
            });
        }
        if (isLayerCacheFresh(cache) || cache.loading) return paintPromise;

        cache.loading = true;
        setToggleLoading('toggleAccidentsBtn', true);
        return paintPromise.then(function() {
            return fetchAccidentIncidents();
        }).then(function(incidents) {
            cache.items = incidents;
            cache.fetchedAt = Date.now();
            cache.loading = false;
            saveLayerCacheToStorage('incidents');
            if (!accidentsVisible) {
                setToggleLoading('toggleAccidentsBtn', false, function() {
                    setAccidentToggleStyle(accidentsVisible);
                });
                return 0;
            }
            return renderAccidentPinsFromData(incidents).then(function(count) {
                setToggleLoading('toggleAccidentsBtn', false, function() {
                    setAccidentToggleStyle(accidentsVisible);
                });
                if (!silent && !showedCache) {
                    showNotification(count ? (count + ' live incident' + (count === 1 ? '' : 's') + ' on the map') : 'No live traffic incidents in Quezon City', 'info');
                }
                return count;
            });
        }).catch(function(err) {
            cache.loading = false;
            setToggleLoading('toggleAccidentsBtn', false, function() {
                setAccidentToggleStyle(accidentsVisible);
            });
            if (!silent && !showedCache && accidentsVisible) {
                showNotification(err.message || 'Could not load traffic incidents', 'error');
            }
        });
    }
    window.loadAccidentPins = loadAccidentPins;

    function setAccidentToggleStyle(on) {
        setMapToolBtnStyle('toggleAccidentsBtn', on);
    }

    function toggleAccidentPins() {
        accidentsVisible = !accidentsVisible;
        setAccidentToggleStyle(accidentsVisible);
        if (!accidentsVisible) {
            accidentRenderGen++;
            if (accidentsLayer) {
                map.removeLayer(accidentsLayer);
                accidentsLayer = null;
            }
            showNotification('Incident pins hidden', 'info');
            return;
        }
        loadAccidentPins(false);
    }

    // ===== BUS / RAIL TRANSIT POIs (TomTom categorySet) =====
    const TOMTOM_BUS_CATEGORY = '9942002';
    const TOMTOM_RAIL_CATEGORY = '7380';
    // Kept in sync with map_layers/cache.php (server is source of truth on Sync).
    const TRANSIT_POI_CENTERS = [
        [14.590, 121.020], [14.590, 121.055], [14.590, 121.090],
        [14.625, 121.015], [14.625, 121.050], [14.625, 121.085], [14.625, 121.115],
        [14.660, 121.015], [14.660, 121.050], [14.660, 121.085], [14.660, 121.115],
        [14.700, 121.020], [14.700, 121.055], [14.700, 121.090], [14.700, 121.120],
        [14.740, 121.030], [14.740, 121.065], [14.740, 121.100],
        [14.770, 121.050], [14.770, 121.085]
    ];

    function transitPoiPosition(poi) {
        const pos = poi && poi.position;
        if (!pos || pos.lat == null || pos.lon == null) return null;
        return [pos.lat, pos.lon];
    }

    function transitPoiPopupHtml(poi, kindLabel) {
        const name = (poi.poi && poi.poi.name) || kindLabel;
        const addr = (poi.address && (poi.address.freeformAddress || poi.address.streetName)) || '';
        const cats = (poi.poi && poi.poi.categories) ? poi.poi.categories.join(', ') : '';
        return '<strong>' + name + '</strong><br>' +
            (addr ? addr + '<br>' : '') +
            (cats ? '<span style="color:#6b7280;font-size:11px;">' + cats + '</span><br>' : '') +
            '<span style="color:#6b7280;font-size:11px;">' + kindLabel + '</span>';
    }

    function fetchTransitPois(categorySet) {
        const layer = (categorySet === TOMTOM_RAIL_CATEGORY) ? 'rail' : 'bus';
        return fetch(MAP_LAYERS_CACHE_API + '?layer=' + encodeURIComponent(layer), { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'Could not load transit stops');
                }
                const payload = data.data || {};
                return Array.isArray(payload.items) ? payload.items : [];
            });
    }

    function renderTransitPins(pois, cssClass, iconName, kindLabel, opts) {
        opts = opts || {};
        const genRef = opts.genRef;
        const isVisible = opts.isVisible;
        const setLayer = opts.setLayer;
        const getLayer = opts.getLayer;
        const gen = genRef ? (++genRef.value) : 0;

        const existing = typeof getLayer === 'function' ? getLayer() : null;
        if (existing) {
            map.removeLayer(existing);
            if (typeof setLayer === 'function') setLayer(null);
        }
        const layer = L.layerGroup().addTo(map);
        if (typeof setLayer === 'function') setLayer(layer);

        return mapOverChunks(pois, function(poi) {
            if (genRef && gen !== genRef.value) return false;
            if (typeof isVisible === 'function' && !isVisible()) return false;
            if (typeof getLayer === 'function' && getLayer() !== layer) return false;
            const pos = transitPoiPosition(poi);
            if (!pos) return false;
            if (typeof isInsideQCBounds === 'function' && !isInsideQCBounds(pos[0], pos[1])) return false;
            const icon = L.divIcon({
                html: '<div class="transit-map-pin ' + cssClass + '"><i class="fas fa-' + iconName + '"></i></div>',
                className: '',
                iconSize: [26, 26],
                iconAnchor: [13, 13]
            });
            L.marker(pos, { icon: icon, zIndexOffset: 500 })
                .bindPopup(transitPoiPopupHtml(poi, kindLabel))
                .addTo(layer);
            return true;
        }, function() {
            if (genRef && gen !== genRef.value) return false;
            if (typeof isVisible === 'function' && !isVisible()) return false;
            if (typeof getLayer === 'function' && getLayer() !== layer) return false;
            return true;
        }).then(function(count) {
            return { layer: layer, count: count };
        });
    }

    const busRenderToken = { value: 0 };
    const railRenderToken = { value: 0 };

    function setBusToggleStyle(on) {
        setMapToolBtnStyle('toggleBusStopsBtn', on);
    }

    function setRailToggleStyle(on) {
        setMapToolBtnStyle('toggleRailStationsBtn', on);
    }

    function loadBusStopPins(silent) {
        const cache = layerCaches.bus;
        let showedCache = false;
        let paintPromise = Promise.resolve({ count: 0 });

        if (busStopsVisible && hasLayerCache(cache)) {
            setToggleLoading('toggleBusStopsBtn', true);
            paintPromise = renderTransitPins(cache.items, 'bus', 'bus', 'Bus stop', {
                genRef: busRenderToken,
                isVisible: function() { return busStopsVisible; },
                getLayer: function() { return busStopsLayer; },
                setLayer: function(l) { busStopsLayer = l; }
            }).then(function(rendered) {
                showedCache = true;
                setToggleLoading('toggleBusStopsBtn', false, function() {
                    setBusToggleStyle(busStopsVisible);
                });
                if (!silent && isLayerCacheFresh(cache)) {
                    showNotification(rendered.count ? (rendered.count + ' bus stop' + (rendered.count === 1 ? '' : 's') + ' on the map') : 'No bus stops found in Quezon City', 'info');
                }
                return rendered;
            });
        }
        if (isLayerCacheFresh(cache) || cache.loading) return paintPromise;

        cache.loading = true;
        setToggleLoading('toggleBusStopsBtn', true);
        return paintPromise.then(function() {
            return fetchTransitPois(TOMTOM_BUS_CATEGORY);
        }).then(function(pois) {
            cache.items = pois;
            cache.fetchedAt = Date.now();
            cache.loading = false;
            saveLayerCacheToStorage('bus');
            if (!busStopsVisible) {
                setToggleLoading('toggleBusStopsBtn', false, function() {
                    setBusToggleStyle(busStopsVisible);
                });
                return { count: 0 };
            }
            return renderTransitPins(pois, 'bus', 'bus', 'Bus stop', {
                genRef: busRenderToken,
                isVisible: function() { return busStopsVisible; },
                getLayer: function() { return busStopsLayer; },
                setLayer: function(l) { busStopsLayer = l; }
            }).then(function(rendered) {
                setToggleLoading('toggleBusStopsBtn', false, function() {
                    setBusToggleStyle(busStopsVisible);
                });
                if (!silent && !showedCache) {
                    showNotification(rendered.count ? (rendered.count + ' bus stop' + (rendered.count === 1 ? '' : 's') + ' on the map') : 'No bus stops found in Quezon City', 'info');
                }
                return rendered;
            });
        }).catch(function() {
            cache.loading = false;
            setToggleLoading('toggleBusStopsBtn', false, function() {
                setBusToggleStyle(busStopsVisible);
            });
            if (!silent && !showedCache && busStopsVisible) {
                showNotification('Could not load bus stops', 'error');
            }
        });
    }
    window.loadBusStopPins = loadBusStopPins;

    function loadRailStationPins(silent) {
        const cache = layerCaches.rail;
        let showedCache = false;
        let paintPromise = Promise.resolve({ count: 0 });

        if (railStationsVisible && hasLayerCache(cache)) {
            setToggleLoading('toggleRailStationsBtn', true);
            paintPromise = renderTransitPins(cache.items, 'rail', 'train', 'Railroad station', {
                genRef: railRenderToken,
                isVisible: function() { return railStationsVisible; },
                getLayer: function() { return railStationsLayer; },
                setLayer: function(l) { railStationsLayer = l; }
            }).then(function(rendered) {
                showedCache = true;
                setToggleLoading('toggleRailStationsBtn', false, function() {
                    setRailToggleStyle(railStationsVisible);
                });
                if (!silent && isLayerCacheFresh(cache)) {
                    showNotification(rendered.count ? (rendered.count + ' rail station' + (rendered.count === 1 ? '' : 's') + ' on the map') : 'No rail stations found in Quezon City', 'info');
                }
                return rendered;
            });
        }
        if (isLayerCacheFresh(cache) || cache.loading) return paintPromise;

        cache.loading = true;
        setToggleLoading('toggleRailStationsBtn', true);
        return paintPromise.then(function() {
            return fetchTransitPois(TOMTOM_RAIL_CATEGORY);
        }).then(function(pois) {
            cache.items = pois;
            cache.fetchedAt = Date.now();
            cache.loading = false;
            saveLayerCacheToStorage('rail');
            if (!railStationsVisible) {
                setToggleLoading('toggleRailStationsBtn', false, function() {
                    setRailToggleStyle(railStationsVisible);
                });
                return { count: 0 };
            }
            return renderTransitPins(pois, 'rail', 'train', 'Railroad station', {
                genRef: railRenderToken,
                isVisible: function() { return railStationsVisible; },
                getLayer: function() { return railStationsLayer; },
                setLayer: function(l) { railStationsLayer = l; }
            }).then(function(rendered) {
                setToggleLoading('toggleRailStationsBtn', false, function() {
                    setRailToggleStyle(railStationsVisible);
                });
                if (!silent && !showedCache) {
                    showNotification(rendered.count ? (rendered.count + ' rail station' + (rendered.count === 1 ? '' : 's') + ' on the map') : 'No rail stations found in Quezon City', 'info');
                }
                return rendered;
            });
        }).catch(function() {
            cache.loading = false;
            setToggleLoading('toggleRailStationsBtn', false, function() {
                setRailToggleStyle(railStationsVisible);
            });
            if (!silent && !showedCache && railStationsVisible) {
                showNotification('Could not load rail stations', 'error');
            }
        });
    }
    window.loadRailStationPins = loadRailStationPins;

    function toggleBusStopPins() {
        busStopsVisible = !busStopsVisible;
        setBusToggleStyle(busStopsVisible);
        if (!busStopsVisible) {
            busRenderToken.value++;
            if (busStopsLayer) {
                map.removeLayer(busStopsLayer);
                busStopsLayer = null;
            }
            showNotification('Bus stop pins hidden', 'info');
            return;
        }
        loadBusStopPins(false);
    }

    function toggleRailStationPins() {
        railStationsVisible = !railStationsVisible;
        setRailToggleStyle(railStationsVisible);
        if (!railStationsVisible) {
            railRenderToken.value++;
            if (railStationsLayer) {
                map.removeLayer(railStationsLayer);
                railStationsLayer = null;
            }
            showNotification('Rail station pins hidden', 'info');
            return;
        }
        loadRailStationPins(false);
    }

    // Prefetch after idle so map init / UI stay responsive; stagger layers
    function scheduleLayerPrefetch() {
        const run = function() {
            layerCacheHydrated.then(function() {
                loadAccidentPins(true);
                setTimeout(function() { loadBusStopPins(true); }, 400);
                setTimeout(function() { loadRailStationPins(true); }, 800);
            });
        };
        if (typeof requestIdleCallback === 'function') {
            requestIdleCallback(run, { timeout: 2500 });
        } else {
            setTimeout(run, 600);
        }
    }
    scheduleLayerPrefetch();

    const SYNC_LAYER_DEFS = [
        { key: 'incidents', label: 'Incidents' },
        { key: 'bus', label: 'Bus Stops' },
        { key: 'rail', label: 'Rail Stations' },
        { key: 'routes', label: 'PT Routes' }
    ];

    function openSyncLayersModal() {
        const overlay = document.getElementById('syncLayersOverlay');
        const footer = document.getElementById('syncLayersFooter');
        const titleIcon = document.getElementById('syncLayersTitleIcon');
        const subtitle = document.getElementById('syncLayersSubtitle');
        if (!overlay) return;
        SYNC_LAYER_DEFS.forEach(function(def) {
            setSyncLayerItemState(def.key, 'pending', 'Fetching…');
        });
        if (footer) footer.classList.remove('is-visible');
        if (titleIcon) titleIcon.className = 'fas fa-sync-alt fa-spin';
        if (subtitle) {
            subtitle.textContent = 'Downloading fresh data. Please wait — this window cannot be closed until sync finishes.';
        }
        overlay.classList.add('is-open');
        overlay.setAttribute('aria-hidden', 'false');
    }

    function closeSyncLayersModal() {
        const overlay = document.getElementById('syncLayersOverlay');
        const footer = document.getElementById('syncLayersFooter');
        if (footer && !footer.classList.contains('is-visible')) return;
        if (!overlay) return;
        overlay.classList.remove('is-open');
        overlay.setAttribute('aria-hidden', 'true');
    }

    function setSyncLayerItemState(layerKey, state, statusText) {
        const item = document.querySelector('#syncLayersList .sync-layers-item[data-layer="' + layerKey + '"]');
        if (!item) return;
        item.classList.remove('is-pending', 'is-done', 'is-failed');
        item.classList.add(state === 'done' ? 'is-done' : (state === 'failed' ? 'is-failed' : 'is-pending'));
        const icon = item.querySelector('.sync-layers-item-icon i');
        if (icon) {
            if (state === 'done') icon.className = 'fas fa-check';
            else if (state === 'failed') icon.className = 'fas fa-times';
            else icon.className = 'fas fa-spinner fa-spin';
        }
        const status = item.querySelector('.sync-layers-item-status');
        if (status) status.textContent = statusText || '';
    }

    function finishSyncLayersModal(results) {
        const footer = document.getElementById('syncLayersFooter');
        const titleIcon = document.getElementById('syncLayersTitleIcon');
        const subtitle = document.getElementById('syncLayersSubtitle');
        const okCount = results.filter(function(r) { return r.ok; }).length;
        const failCount = results.length - okCount;
        if (titleIcon) titleIcon.className = failCount ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
        if (subtitle) {
            if (failCount === 0) subtitle.textContent = 'All layers synced successfully. You can close this window.';
            else if (okCount === 0) subtitle.textContent = 'Sync finished, but every layer failed. You can close this window and try again.';
            else subtitle.textContent = 'Sync finished with ' + failCount + ' failed layer(s). You can close this window.';
        }
        if (footer) footer.classList.add('is-visible');
    }

    function fetchSyncLayer(layerKey) {
        return fetch(MAP_LAYERS_CACHE_API + '?layer=' + encodeURIComponent(layerKey) + '&refresh=1', {
            credentials: 'same-origin'
        })
            .then(function(r) { return r.json().then(function(data) { return { okHttp: r.ok, data: data }; }); })
            .then(function(res) {
                const data = res.data;
                if (!res.okHttp || !data || !data.success) {
                    throw new Error((data && (data.error || data.message)) || 'Request failed');
                }
                const payload = data.data || {};
                let count = null;
                if (layerKey === 'routes') {
                    count = Array.isArray(payload.routes) ? payload.routes.length : (payload.count != null ? payload.count : null);
                } else {
                    count = Array.isArray(payload.items) ? payload.items.length : (payload.count != null ? payload.count : null);
                }
                return { key: layerKey, ok: true, count: count, error: null };
            })
            .catch(function(err) {
                return { key: layerKey, ok: false, count: null, error: (err && err.message) ? err.message : 'Failed' };
            });
    }

    function syncMapLayers() {
        const btn = document.getElementById('syncMapLayersBtn');
        if (btn && btn.classList.contains('is-loading')) return;
        setToggleLoading('syncMapLayersBtn', true);
        openSyncLayersModal();
        clearClientLayerCaches();

        const jobs = SYNC_LAYER_DEFS.map(function(def) {
            return fetchSyncLayer(def.key).then(function(result) {
                if (result.ok) {
                    const detail = result.count != null ? ('Done · ' + result.count + ' item(s)') : 'Done';
                    setSyncLayerItemState(result.key, 'done', detail);
                } else {
                    setSyncLayerItemState(result.key, 'failed', result.error || 'Failed');
                }
                return result;
            });
        });

        Promise.all(jobs)
            .then(function(results) {
                const reloadJobs = [];
                results.forEach(function(result) {
                    if (!result.ok) return;
                    if (result.key === 'incidents') reloadJobs.push(loadAccidentPins(true));
                    else if (result.key === 'bus') reloadJobs.push(loadBusStopPins(true));
                    else if (result.key === 'rail') reloadJobs.push(loadRailStationPins(true));
                    else if (result.key === 'routes') reloadJobs.push(ensureOsmRoutesLoaded(true));
                });
                return Promise.all(reloadJobs).then(function() { return results; });
            })
            .then(function(results) {
                finishSyncLayersModal(results || []);
                const okCount = (results || []).filter(function(r) { return r.ok; }).length;
                const failCount = (results || []).length - okCount;
                if (failCount === 0) showNotification('All map layers synced', 'success');
                else if (okCount === 0) showNotification('Map layer sync failed', 'error');
                else showNotification('Synced ' + okCount + ' layer(s), ' + failCount + ' failed', 'error');
            })
            .catch(function(err) {
                SYNC_LAYER_DEFS.forEach(function(def) {
                    const item = document.querySelector('#syncLayersList .sync-layers-item[data-layer="' + def.key + '"]');
                    if (item && item.classList.contains('is-pending')) {
                        setSyncLayerItemState(def.key, 'failed', err.message || 'Failed');
                    }
                });
                finishSyncLayersModal(SYNC_LAYER_DEFS.map(function(def) {
                    return { key: def.key, ok: false };
                }));
                showNotification(err.message || 'Could not sync map layers', 'error');
            })
            .finally(function() {
                setToggleLoading('syncMapLayersBtn', false, function() {
                    const syncBtn = document.getElementById('syncMapLayersBtn');
                    if (!syncBtn) return;
                    syncBtn.style.background = '';
                    syncBtn.style.color = '';
                    syncBtn.style.borderColor = '';
                });
            });
    }
    window.syncMapLayers = syncMapLayers;
    window.closeSyncLayersModal = closeSyncLayersModal;

    // ===== OSM PT ROUTES (Overpass) — list first, map on select =====
    const OSM_ROUTES_API = MAP_LAYERS_CACHE_API + '?layer=routes';
    const OSM_ROUTE_COLORS = { bus: '#dc2626', jeep: '#dc2626' };

    function osmRoutePopupHtml(route) {
        const kindLabel = route.kind === 'jeep' ? 'Jeepney route' : 'Bus / PUV route';
        const bits = [];
        if (route.ref) bits.push('Ref: ' + route.ref);
        if (route.network) bits.push(route.network);
        if (route.from || route.to) bits.push([route.from, route.to].filter(Boolean).join(' → '));
        return '<strong>' + (route.name || kindLabel) + '</strong><br>' +
            '<span style="color:#6b7280;font-size:11px;">' + kindLabel + ' · OpenStreetMap</span>' +
            (bits.length ? '<br><span style="color:#6b7280;font-size:11px;">' + bits.join(' · ') + '</span>' : '');
    }

    function setPtRoutesBtnStyle(active) {
        setMapToolBtnStyle('togglePtRoutesBtn', active);
    }

    function setOsmRoutesLoading(loading) {
        setToggleLoading('togglePtRoutesBtn', loading, function() {
            const panel = document.getElementById('ptRoutesPanel');
            setPtRoutesBtnStyle(panel && panel.style.display === 'block');
        });
    }

    function fetchOsmRoutes() {
        return fetch(OSM_ROUTES_API, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'Could not load OSM routes');
                }
                const payload = data.data || {};
                return {
                    routes: Array.isArray(payload.routes) ? payload.routes : [],
                    fetchedAt: payload.fetchedAt || Date.now()
                };
            });
    }

    function ensureOsmRoutesLoaded(silent) {
        return osmRoutesIdbReady.then(function() {
            const cache = layerCaches.osmRoutes;
            if (isLayerCacheFresh(cache)) {
                renderPtRouteList();
                return cache.items;
            }
            if (cache.loading) {
                return new Promise(function(resolve) {
                    const timer = setInterval(function() {
                        if (!layerCaches.osmRoutes.loading) {
                            clearInterval(timer);
                            resolve(layerCaches.osmRoutes.items || []);
                        }
                    }, 200);
                });
            }
            cache.loading = true;
            setOsmRoutesLoading(true);
            const listEl = document.getElementById('ptRouteList');
            if (listEl && !hasLayerCache(cache)) {
                listEl.innerHTML = '<div class="pt-route-status"><i class="fas fa-spinner fa-spin"></i> Loading routes…</div>';
            }
            return fetchOsmRoutes().then(function(result) {
                cache.items = result.routes;
                cache.fetchedAt = result.fetchedAt || Date.now();
                cache.loading = false;
                setOsmRoutesLoading(false);
                saveOsmRoutesToIdb();
                renderPtRouteList();
                if (!silent) {
                    showNotification(result.routes.length
                        ? (result.routes.length + ' OSM routes ready')
                        : 'No OSM routes found for Quezon City', 'info');
                }
                return result.routes;
            }).catch(function(err) {
                cache.loading = false;
                setOsmRoutesLoading(false);
                if (listEl) {
                    listEl.innerHTML = '<div class="pt-route-empty">' + (err.message || 'Could not load OSM routes') + '</div>';
                }
                if (!silent) showNotification(err.message || 'Could not load OSM routes', 'error');
                return [];
            });
        });
    }
    window.loadOsmRouteLines = function(silent) { ensureOsmRoutesLoaded(!!silent); };

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderPtRouteList() {
        const listEl = document.getElementById('ptRouteList');
        const metaEl = document.getElementById('ptRouteListMeta');
        const searchEl = document.getElementById('ptRouteSearch');
        if (!listEl) return;
        const cache = layerCaches.osmRoutes;
        if (cache.loading && !hasLayerCache(cache)) {
            listEl.innerHTML = '<div class="pt-route-status"><i class="fas fa-spinner fa-spin"></i> Loading routes…</div>';
            if (metaEl) metaEl.textContent = '';
            return;
        }
        if (!hasLayerCache(cache)) {
            listEl.innerHTML = '<div class="pt-route-empty">No routes loaded yet.</div>';
            if (metaEl) metaEl.textContent = '';
            return;
        }
        const q = ((searchEl && searchEl.value) || '').trim().toLowerCase();
        const routes = cache.items.slice().sort(function(a, b) {
            return String(a.name || '').localeCompare(String(b.name || ''));
        });
        const filtered = !q ? routes : routes.filter(function(r) {
            const hay = [r.name, r.from, r.to, r.ref, r.network, r.kind].join(' ').toLowerCase();
            return hay.indexOf(q) !== -1;
        });
        if (metaEl) {
            metaEl.textContent = filtered.length + ' of ' + routes.length + ' route' + (routes.length === 1 ? '' : 's');
        }
        if (!filtered.length) {
            listEl.innerHTML = '<div class="pt-route-empty">No routes match your search.</div>';
            return;
        }
        listEl.innerHTML = filtered.map(function(r) {
            const selected = String(r.id) === String(selectedOsmRouteId);
            const ends = [r.from, r.to].filter(Boolean).join(' → ');
            const metaBits = [];
            if (ends) metaBits.push(ends);
            if (r.ref) metaBits.push('Ref ' + r.ref);
            if (r.network) metaBits.push(r.network);
            const badge = r.kind === 'jeep' ? '<span class="pt-route-badge">Jeep</span>' : '';
            return '<button type="button" class="pt-route-item' + (selected ? ' is-selected' : '') + '" data-route-id="' + escapeHtml(r.id) + '" onclick="selectOsmRoute(' + Number(r.id) + ')">' +
                '<span class="pt-route-name">' + escapeHtml(r.name || ('Route ' + r.id)) + badge + '</span>' +
                (metaBits.length ? '<span class="pt-route-meta">' + escapeHtml(metaBits.join(' · ')) + '</span>' : '') +
                '</button>';
        }).join('');
    }
    window.renderPtRouteList = renderPtRouteList;

    function clearSelectedOsmRoute(silent) {
        selectedOsmRouteId = null;
        if (busRoutesLayer) {
            map.removeLayer(busRoutesLayer);
            busRoutesLayer = null;
        }
        renderPtRouteList();
        if (!silent) showNotification('Route cleared from map', 'info');
    }
    window.clearSelectedOsmRoute = clearSelectedOsmRoute;

    function selectOsmRoute(routeId) {
        const cache = layerCaches.osmRoutes;
        if (!hasLayerCache(cache)) {
            showNotification('Routes are still loading', 'info');
            ensureOsmRoutesLoaded(false).then(function() { selectOsmRoute(routeId); });
            return;
        }
        if (String(selectedOsmRouteId) === String(routeId)) {
            clearSelectedOsmRoute(false);
            return;
        }
        const route = cache.items.find(function(r) { return String(r.id) === String(routeId); });
        if (!route) {
            showNotification('Route not found', 'error');
            return;
        }
        if (busRoutesLayer) {
            map.removeLayer(busRoutesLayer);
            busRoutesLayer = null;
        }
        const color = OSM_ROUTE_COLORS[route.kind] || OSM_ROUTE_COLORS.bus;
        const layer = L.layerGroup().addTo(map);
        const bounds = [];
        const popup = osmRoutePopupHtml(route);
        (route.lines || []).forEach(function(line) {
            if (!line || line.length < 2) return;
            L.polyline(line, {
                color: color,
                weight: 4,
                opacity: 0.9,
                lineJoin: 'round',
                lineCap: 'round'
            }).bindPopup(popup).addTo(layer);
            line.forEach(function(pt) {
                if (pt && pt.length >= 2) bounds.push(pt);
            });
        });
        busRoutesLayer = layer;
        selectedOsmRouteId = route.id;
        renderPtRouteList();
        if (bounds.length) {
            try {
                map.fitBounds(bounds, { padding: [40, 40], maxZoom: 15 });
            } catch (e) { /* ignore invalid bounds */ }
        }
        showNotification((route.name || 'Route') + ' shown on map', 'info');
    }
    window.selectOsmRoute = selectOsmRoute;

    function showPtRoutesPanel() {
        if (isMapToolOn('togglePtRoutesBtn')) {
            closePanel('ptRoutesPanel');
            return;
        }
        closeAllPanels();
        closeToolsDropdown();
        document.getElementById('ptRoutesPanel').style.display = 'block';
        setPtRoutesBtnStyle(true);
        ensureOsmRoutesLoaded(true).then(function() {
            renderPtRouteList();
        });
    }
    window.showPtRoutesPanel = showPtRoutesPanel;

    // Prefetch after IndexedDB hydrate (no network if client 1h cache is fresh)
    ensureOsmRoutesLoaded(true);

    // ===== EV CHARGING STATIONS =====
    let evMarkerObjects = [];
    function clearEVCharging(notify) {
        if (evMarkersLayer) {
            map.removeLayer(evMarkersLayer);
            evMarkersLayer = null;
        }
        const resultsDiv = document.getElementById('evResults');
        if (resultsDiv) {
            resultsDiv.style.display = 'none';
            resultsDiv.innerHTML = '';
        }
        if (notify) showNotification('EV stations hidden', 'info');
    }

    function showEVCharging() {
        if (isMapToolOn('btnEVCharging')) {
            closePanel('evChargingPanel');
            return;
        }
        closeAllPanels();
        closeToolsDropdown();
        document.getElementById('evChargingPanel').style.display = 'block';
        setMapToolBtnStyle('btnEVCharging', true);
        findEVStations();
    }

    function findEVStations() {
        const center = map.getCenter();
        if (evMarkersLayer) { map.removeLayer(evMarkersLayer); evMarkersLayer = null; }

        TomTomServices.evCharging(center.lat, center.lng, { limit: 20 }).then(data => {
            const resultsDiv = document.getElementById('evResults');
            if (!data.success || !data.data || !data.data.results) {
                resultsDiv.style.display = 'block';
                resultsDiv.innerHTML = 'No EV charging stations found nearby.';
                return;
            }
            const stations = data.data.results;
            evMarkersLayer = L.layerGroup().addTo(map);
            resultsDiv.style.display = 'block';
            resultsDiv.innerHTML = '<strong>' + stations.length + ' EV stations found</strong><br>';

            stations.forEach((s, i) => {
                const pos = s.position;
                if (pos) {
                    const icon = L.divIcon({
                        html: '<div style="background:#10b981;color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:12px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);"><i class="fas fa-charging-station"></i></div>',
                        className: '', iconSize: [24, 24]
                    });
                    L.marker([pos.lat, pos.lon], { icon })
                        .bindPopup(`<b>${s.poi?.name || 'EV Station'}</b><br>${s.address?.freeformAddress || ''}`)
                        .addTo(evMarkersLayer);
                    resultsDiv.innerHTML += `${i+1}. ${s.poi?.name || 'Station'} - ${s.address?.freeformAddress || ''}<br>`;
                }
            });
        });
    }

    // ===== UTILITY =====
    function closeAllPanels() {
        ['routePlannerPanel', 'evChargingPanel', 'ptRoutesPanel', 'commutePlannerPanel'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        });
        setPtRoutesBtnStyle(false);
        setAllMapPanelToolBtnsOff();
        clearCommutePlannerState(false);
        clearEVCharging(false);
        if (mapClickHandler) { map.off('click', mapClickHandler); mapClickHandler = null; }
    }

    // ===== COMMUTE PLANNER (Sakay deep link) =====
    function updateCommutePlannerUi() {
        const statusEl = document.getElementById('commutePlannerStatus');
        const coordsEl = document.getElementById('commutePlannerCoords');
        const openBtn = document.getElementById('openSakayTripBtn');
        if (!statusEl || !coordsEl || !openBtn) return;

        if (!commuteFrom) {
            statusEl.innerHTML = 'Click the map to set the <strong>origin</strong>.';
            coordsEl.style.display = 'none';
            openBtn.disabled = true;
            return;
        }
        if (!commuteTo) {
            statusEl.innerHTML = 'Origin set. Click the map to set the <strong>destination</strong>.';
            coordsEl.style.display = 'block';
            coordsEl.textContent = 'From: ' + commuteFrom.lat.toFixed(6) + ', ' + commuteFrom.lng.toFixed(6);
            openBtn.disabled = true;
            return;
        }
        statusEl.innerHTML = 'Origin and destination set. Open Sakay for transit directions.';
        coordsEl.style.display = 'block';
        coordsEl.innerHTML =
            'From: ' + commuteFrom.lat.toFixed(6) + ', ' + commuteFrom.lng.toFixed(6) + '<br>' +
            'To: ' + commuteTo.lat.toFixed(6) + ', ' + commuteTo.lng.toFixed(6);
        openBtn.disabled = false;
    }

    function clearCommutePlannerState(keepPanel) {
        commuteFrom = null;
        commuteTo = null;
        window.suppressMapReportPin = false;
        if (commuteMarkersLayer) {
            map.removeLayer(commuteMarkersLayer);
            commuteMarkersLayer = null;
        }
        if (!keepPanel) {
            const panel = document.getElementById('commutePlannerPanel');
            if (panel) panel.style.display = 'none';
        }
        updateCommutePlannerUi();
    }

    function resetCommutePlanner() {
        if (mapClickHandler) {
            map.off('click', mapClickHandler);
            mapClickHandler = null;
        }
        clearCommutePlannerState(true);
        bindCommuteMapClicks();
        showNotification('Click the map to set the origin', 'info');
    }
    window.resetCommutePlanner = resetCommutePlanner;

    function closeCommutePlanner() {
        if (mapClickHandler) {
            map.off('click', mapClickHandler);
            mapClickHandler = null;
        }
        clearCommutePlannerState(false);
    }
    window.closeCommutePlanner = closeCommutePlanner;

    function buildSakayTripUrl(from, to) {
        const fromParam = encodeURIComponent(from.lat + ',' + from.lng);
        const toParam = encodeURIComponent(to.lat + ',' + to.lng);
        return 'https://sakay.ph/app/trip?from=' + fromParam + '&to=' + toParam;
    }

    function openSakayTrip() {
        if (!commuteFrom || !commuteTo) {
            showNotification('Set both origin and destination first', 'error');
            return;
        }
        const url = buildSakayTripUrl(commuteFrom, commuteTo);
        window.open(url, '_blank', 'noopener,noreferrer');
        showNotification('Opening Sakay.ph commute directions…', 'info');
    }
    window.openSakayTrip = openSakayTrip;

    function bindCommuteMapClicks() {
        if (mapClickHandler) map.off('click', mapClickHandler);
        window.suppressMapReportPin = true;
        mapClickHandler = function(e) {
            if (typeof isInsideQCBounds === 'function' && !isInsideQCBounds(e.latlng.lat, e.latlng.lng)) {
                showNotification('Please select a location within Quezon City only.', 'error');
                return;
            }
            if (!commuteMarkersLayer) {
                commuteMarkersLayer = L.layerGroup().addTo(map);
            }
            if (!commuteFrom) {
                commuteFrom = e.latlng;
                L.circleMarker(e.latlng, {
                    color: '#10b981',
                    fillColor: '#10b981',
                    fillOpacity: 0.85,
                    radius: 9,
                    weight: 2
                }).bindPopup('Origin').addTo(commuteMarkersLayer).openPopup();
                updateCommutePlannerUi();
                showNotification('Now click the destination on the map', 'info');
                return;
            }
            if (!commuteTo) {
                commuteTo = e.latlng;
                L.circleMarker(e.latlng, {
                    color: '#ef4444',
                    fillColor: '#ef4444',
                    fillOpacity: 0.85,
                    radius: 9,
                    weight: 2
                }).bindPopup('Destination').addTo(commuteMarkersLayer).openPopup();
                map.off('click', mapClickHandler);
                mapClickHandler = null;
                window.suppressMapReportPin = false;
                updateCommutePlannerUi();
                const panel = document.getElementById('commutePlannerPanel');
                if (panel) {
                    panel.style.display = 'block';
                    panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                openSakayTrip();
            }
        };
        map.on('click', mapClickHandler);
    }

    function showCommutePlanner() {
        if (isMapToolOn('btnCommutePlanner')) {
            closePanel('commutePlannerPanel');
            return;
        }
        closeAllPanels();
        closeToolsDropdown();
        document.getElementById('commutePlannerPanel').style.display = 'block';
        setMapToolBtnStyle('btnCommutePlanner', true);
        clearCommutePlannerState(true);
        bindCommuteMapClicks();
        showNotification('Click the map to set the origin', 'info');
    }
    window.showCommutePlanner = showCommutePlanner;

    // ===== LOAD MORE BUTTON FOR RECENT SUBMISSIONS =====
    let currentOffset = 10;
    let isLoadingMore = false;
    let hasMoreReports = true;

    let currentUserRole = '';
    const sessionRoleTag = document.getElementById('sessionTimeoutData');
    if (sessionRoleTag) currentUserRole = sessionRoleTag.getAttribute('data-role') || '';
    const isRoadSupervisor = (currentUserRole === 'road_ops_supervisor');
    const isTransportSupervisor = (currentUserRole === 'trans_ops_supervisor' || currentUserRole === 'trans_monitoring_officer');
    const isTransportMonitoringOfficer = (currentUserRole === 'trans_monitoring_officer');
    const isRoadOfficer = (currentUserRole === 'road_monitoring_officer');
    const canArchiveCompleted = (currentUserRole === 'system_admin' || currentUserRole === 'road_ops_supervisor' || currentUserRole === 'trans_ops_supervisor');

    // Matches the server-rendered rows: only the admin is told which completed
    // projects are still waiting on a transparency decision.
    function isAwaitingTransparency(report) {
        return currentUserRole === 'system_admin'
            && (report.transparency_request_status || '') === 'pending';
    }
    function transparencyAwaitIcon(report) {
        if (!isAwaitingTransparency(report)) return '';
        return '<span class="transparency-await-icon" title="Awaiting your Transparency Upload decision"'
            + ' role="img" aria-label="Awaiting Transparency Upload decision">'
            + '<i class="fas fa-bullhorn"></i></span>';
    }

    // db-badge helpers — mirror the PHP rmo_db_status_class() /
    // rmo_db_priority_badge() (and lgu_staff_dashboard.php's db-badge) so the
    // Road supervisor's / Road Monitoring Officer's dynamically-loaded rows
    // match the dashboard badges.
    function dbStatusBadgeClass(status) {
        const map = {
            'pending': 'db-st-pending',
            'in-progress': 'db-st-progress',
            'completed': 'db-st-completed',
            'approved': 'db-st-completed',
            'rejected': 'db-st-rejected',
            'cancelled': 'db-st-cancelled',
            'active': 'db-st-active',
            'assigned': 'db-st-assigned',
        };
        return map[String(status || '').toLowerCase()] || 'db-st-pending';
    }
    function dbPriorityBadge(priority) {
        const map = {
            'high': ['db-pr-high', 'fa-exclamation-triangle'],
            'medium': ['db-pr-medium', 'fa-exclamation'],
            'low': ['db-pr-low', 'fa-check'],
        };
        return map[String(priority || '').toLowerCase()] || ['db-pr-medium', 'fa-exclamation'];
    }

    function loadMoreReports() {
        if (isLoadingMore || !hasMoreReports) return;
        
        isLoadingMore = true;
        const tableBody = document.querySelector('#recentReportsTable tbody');
        const loadMoreBtn = document.getElementById('loadMoreReportsBtn');
        
        if (loadMoreBtn) {
            loadMoreBtn.disabled = true;
            loadMoreBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        }
        
        const statusFilter = IS_COMPLETED_PROJECTS_VIEW
            ? 'completed'
            : (document.getElementById('statusFilter') ? document.getElementById('statusFilter').value : 'all');
        const typeFilter = document.getElementById('typeFilter').value;
        
        fetch(submissionsListApiUrl(currentOffset, 10, statusFilter, typeFilter))
            .then(response => response.json())
            .then(data => {
                if (data.success && data.reports.length > 0) {
                    data.reports.forEach(report => {
                        const row = createReportRow(report);
                        tableBody.appendChild(row);
                    });
                    
                    currentOffset += data.reports.length;
                    
                    // If we got fewer reports than requested, we've reached the end
                    if (data.reports.length < 10) {
                        hasMoreReports = false;
                        hideLoadMoreButton();
                    } else {
                        if (loadMoreBtn) {
                            loadMoreBtn.disabled = false;
                            loadMoreBtn.innerHTML = '<i class="fas fa-plus"></i> Load More Reports';
                        }
                    }
                } else {
                    hasMoreReports = false;
                    hideLoadMoreButton();
                }
                
                isLoadingMore = false;
            })
            .catch(error => {
                showNotification('Error loading more reports', 'error');
                if (loadMoreBtn) {
                    loadMoreBtn.disabled = false;
                    loadMoreBtn.innerHTML = '<i class="fas fa-plus"></i> Load More Reports';
                }
                isLoadingMore = false;
            });
    }

    function hideLoadMoreButton() {
        const loadMoreBtn = document.getElementById('loadMoreReportsBtn');
        if (loadMoreBtn) {
            loadMoreBtn.style.display = 'none';
        }
    }

    function showLoadMoreButton() {
        const loadMoreBtn = document.getElementById('loadMoreReportsBtn');
        if (loadMoreBtn) {
            loadMoreBtn.style.display = 'inline-block';
        }
    }

    function createReportRow(report) {
        const tr = document.createElement('tr');
        tr.className = 'report-table-row'
            + (isAwaitingTransparency(report) ? ' transparency-flagged' : '')
            + (isNoUpdateStale(report) ? ' no-update-flagged' : '');
        tr.dataset.id = report.id;
        tr.dataset.title = (report.title || '').toLowerCase();
        tr.dataset.reportId = (report.report_id || '').toLowerCase();
        tr.dataset.status = report.status;
        tr.dataset.source = report.source;
        tr.dataset.details = JSON.stringify(report.details);
        
        tr.innerHTML = `
            <td class="mono-id">${noUpdateFlagIcon(report)}${transparencyAwaitIcon(report)}${escapeHtml(report.report_id)}</td>
            <td>${escapeHtml(report.title)}</td>
            <td><span class="badge badge-source badge-source-${String(report.source || 'citizen').toLowerCase().replace(/[^a-z0-9_-]/g, '')}">${escapeHtml(report.source_label)}</span></td>
            ${SHOW_CATEGORY_COLUMN ? `<td>${completedProjectCategoryBadge(report)}</td>` : ''}
            ${HIDE_STATUS_COLUMN ? '' : `<td>${(isRoadSupervisor || isRoadOfficer)
                ? `<span class="db-badge ${dbStatusBadgeClass(report.status)}">${escapeHtml(ucfirst(report.status.replace('-', ' ')))}</span>`
                : `<span class="badge badge-${report.status.toLowerCase().replace(' ', '-')}">${escapeHtml(ucfirst(report.status.replace('-', ' ')))}</span>`}</td>`}
            <td>${report.assignment_status === 'assigned'
                ? (report.assignment_officer
                    ? `<span class="badge assignment-badge assignment-assigned">${escapeHtml(report.assignment_officer)}</span>`
                    : `<span class="badge assignment-badge assignment-assigned">Assigned</span>`)
                : `<span class="badge assignment-badge assignment-unassigned">Unassigned</span>`}</td>
            <td>${(isRoadSupervisor || isRoadOfficer)
                ? `<span class="db-badge ${dbPriorityBadge(report.priority)[0]}"><i class="fas ${dbPriorityBadge(report.priority)[1]}"></i> ${escapeHtml(ucfirst(report.priority))}</span>`
                : `<span class="badge badge-${report.priority.toLowerCase()}">${escapeHtml(ucfirst(report.priority))}</span>`}</td>
            <td class="muted-date">${formatDate(report.created_at)}</td>
            <td>
                ${report.source === 'cimm' ? 
                    (report.approval_status && report.approval_status.toLowerCase() === 'approved' ?
                        `<span class="cimm-verify-badge cimm-verify-badge-verified" title="Approved by CIMM">
                            <i class="fas fa-check-circle"></i> Approved
                        </span>` :
                        `<span class="cimm-verify-badge cimm-verify-badge-none">—</span>`) :
                    (report.cimm_sync_status && report.cimm_sync_status.toLowerCase() === 'verified' ?
                        `<span class="cimm-verify-badge cimm-verify-badge-verified" title="Approved by CIMM">
                            <i class="fas fa-check-circle"></i> Approved
                        </span>` :
                        `<span class="cimm-verify-badge cimm-verify-badge-none">—</span>`)
                }
            </td>
            ${SHOW_PUBLIC_COLUMN ? `<td class="pt-col">${publicStatusBadge(report)}</td>` : ''}
            <td class="action-cell">
                <button class="table-action-btn btn-view" title="View Details" onclick="viewReportDetails(${report.id}, '${report.source}')"><i class="fas fa-eye"></i> View</button>
                <button class="table-action-btn view-map" onclick="focusReportOnMap(${report.id}, '${report.source}')"><i class="fas fa-map-pin"></i> Map</button>
                <button class="table-action-btn btn-updates" onclick="viewReportUpdates(${report.id}, '${report.report_type}', '${report.source}', '${(report.status || '').replace(/'/g, "\\'")}')"><i class="fas fa-timeline"></i> Updates</button>
                ${(report.status || '').toLowerCase() === 'completed' && canArchiveCompleted && (report.can_manage_as_supervisor !== false) ?
                    `<button class="table-action-btn btn-archive" title="Archive" onclick="archiveReport(${report.id}, '${report.source}')"><i class="fas fa-archive"></i> Archive</button>` : ''}
            </td>
        `;
        
        return tr;
    }

    function formatDate(dateStr) {
        if (!dateStr) return '—';
        const date = new Date(dateStr);
        if (isNaN(date.getTime())) return dateStr;
        return date.toLocaleDateString('en-US', { 
            month: 'short', 
            day: 'numeric', 
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function ucfirst(str) {
        return str.charAt(0).toUpperCase() + str.slice(1);
    }

    // Initialize load more button on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Check if initial load has less than 10 reports, hide button
        const initialRows = document.querySelectorAll('#recentReportsTable .report-table-row');
        if (initialRows.length < 10) {
            hideLoadMoreButton();
        }
        focusRecentReport();
    });

    // Deep-link focus from a Progress Update notification's "View Report" button
    // (?focus_report_id=). The backend already located the report and injected
    // its row into Recent Submissions if needed, so this only reveals, scrolls
    // to and pulses the matching row — or shows a friendly message if the
    // report no longer exists.
    const FOCUS_TARGET = <?php echo json_encode($focus_target); ?>;
    function focusRecentReport() {
        const target = FOCUS_TARGET;
        if (!target || !target.id) return;
        const urlParams = new URLSearchParams(window.location.search);
        const transparencyDraft = urlParams.get('transparency_draft');
        const transparencyRequest = urlParams.get('transparency_request');
        setTimeout(function() {
            if (!target.found) {
                showNotification('The report referenced by this progress update could not be found.', 'error');
                return;
            }
            let row = null;
            const rows = document.querySelectorAll('#recentReportsTable .report-table-row[data-id="' + target.id + '"]');
            if (rows.length === 1) {
                row = rows[0];
            } else if (rows.length > 1) {
                // The same id can exist in more than one source table —
                // disambiguate by data-source when possible.
                rows.forEach(function(r) {
                    if (!row && target.source && r.dataset.source === target.source) row = r;
                });
                if (!row) row = rows[0];
            }
            if (!row) {
                showNotification('The report referenced by this progress update could not be found.', 'error');
                return;
            }
            // Clear any client-side search filter so the row is visible.
            const searchInput = document.getElementById('reportSearchInput');
            if (searchInput) searchInput.value = '';
            row.style.display = '';
            // Bring the Recent Submissions section into view, then the row.
            const section = document.querySelector('.reports-table-section');
            if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            setTimeout(function() {
                row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                row.classList.add('focus-pulse');
                setTimeout(function() { row.classList.remove('focus-pulse'); }, 5000);
                if (transparencyDraft && typeof viewReportUpdates === 'function') {
                    var details = {};
                    try { details = JSON.parse(row.dataset.details || '{}'); } catch (e) {}
                    viewReportUpdates(
                        target.id,
                        details.report_type || '',
                        row.dataset.source || target.source || '',
                        'completed'
                    );
                    showNotification(
                        'Transparency draft #' + transparencyDraft + ' created from this project\'s progress updates. '
                        + '<a href="../shared/public_transparency.php?edit=' + encodeURIComponent(transparencyDraft) + '" style="color:#fff;text-decoration:underline;">Review draft</a>',
                        'success'
                    );
                }
                if (transparencyRequest && typeof openTransparencyReview === 'function') {
                    openTransparencyReview(
                        transparencyRequest,
                        target.id,
                        row.dataset.source || target.source || ''
                    );
                }
            }, 350);
        }, 600);
    }

    </script>
    
    <!-- View Details Modal (table-action-btn viewport) -->
    <div id="viewDetailsModal" class="rm-modal-overlay" onclick="if(event.target===this)closeViewDetailsModal()">
        <div class="rm-modal-content">
            <div class="rm-modal-header">
                <div class="rm-modal-header-top">
                    <div class="rm-modal-title-area">
                        <div class="rm-modal-report-id" id="rm-report-id">—</div>
                        <h3 class="rm-modal-title" id="rm-title">—</h3>
                        <div class="rm-modal-badges" id="rm-badges"></div>
                    </div>
                    <button class="rm-modal-close" onclick="closeViewDetailsModal()">&times;</button>
                </div>
            </div>
            <div class="rm-modal-body">
                <!-- Report Information -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-info-circle"></i> Report Information</div>
                    <div class="rm-info-grid" id="rm-report-grid"></div>
                </div>
                <!-- Source & Assignment -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-building"></i> Source &amp; Assignment</div>
                    <div class="rm-info-grid" id="rm-source-grid"></div>
                </div>
                <!-- Report Creator (Road Supervisor portal only) -->
                <div class="rm-modal-section" id="rm-creator-section" style="display:none;">
                    <div class="rm-modal-section-title"><i class="fas fa-user-circle"></i> Report Creator Information</div>
                    <div class="rm-info-grid" id="rm-creator-grid"></div>
                </div>
                <!-- Location -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-map-marker-alt"></i> Location
                        <button type="button" id="rm-view-map-btn" class="rm-view-map-btn" style="display:none;" onclick="openRmMap()">
                            <i class="fas fa-map-marked-alt"></i> View Map
                        </button>
                    </div>
                    <div class="rm-info-grid" id="rm-location-grid"></div>
                    <div class="road-map-container" id="rm-map-container"></div>
                </div>
                <!-- Description -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-align-left"></i> Description</div>
                    <div class="rm-description-text" id="rm-description">—</div>
                </div>
                <!-- Attachments -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-paperclip"></i> Attachments</div>
                    <div id="rm-attachments"></div>
                </div>
                <!-- Timeline -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-clock"></i> Timeline &amp; Updates</div>
                    <div class="rm-info-grid" id="rm-timeline-grid"></div>
                </div>
            </div>
            <div class="rm-modal-footer">
                <?php if ($is_system_admin): ?>
                <button type="button" class="rm-modal-btn-transparency-approve" id="approveTransparencyBtn" style="display:none;" onclick="approveTransparencyRequest()">
                    <i class="fas fa-check-circle"></i> Approve Transparency Upload
                </button>
                <button type="button" class="rm-modal-btn-transparency-reject" id="rejectTransparencyBtn" style="display:none;" onclick="rejectTransparencyRequest()">
                    <i class="fas fa-times-circle"></i> Reject Request
                </button>
                <?php endif; ?>
                <button type="button" class="rm-modal-btn-close" onclick="closeViewDetailsModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Progress Updates Modal -->
    <div id="updatesModal" class="modal">
        <div class="modal-content updates-modal-card">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-timeline"></i> Progress Updates</h5>
                <button class="close" onclick="closeModal('updatesModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="project-completion-banner" id="updatesProjectCompletionBanner" aria-live="polite">
                    <span class="project-completion-banner-label">Project Completion:</span>
                    <span class="project-completion-banner-value" id="updatesProjectCompletionValue">0%</span>
                </div>
                <div class="timeline-container" id="updatesTimeline">
                    <div class="timeline-empty"><i class="fas fa-spinner fa-spin fa-2x t-text-link"></i></div>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; flex-direction: column; gap: 16px;">
                <span id="updateReportInfo" class="t-text-secondary" style="font-size: 13px;"></span>
                <?php if ($is_system_admin): ?>
                <!-- Transparency upload request for this completed project. Keeps the
                     request reachable here as well as from the admin notification. -->
                <div id="transparencyStatusPanel" class="rm-transparency-panel" style="display:none;">
                    <span class="rm-transparency-label t-text-primary">
                        <i class="fas fa-bullhorn"></i> Transparency Upload:
                        <strong id="transparencyStatusValue">&mdash;</strong>
                    </span>
                    <span id="transparencyStatusMeta" class="t-text-secondary"></span>
                    <span id="transparencyDecisionActions" class="rm-transparency-actions">
                        <button type="button" class="rm-modal-btn-transparency-approve" id="approveTransparencyUpdatesBtn" onclick="approveTransparencyFromUpdates()">
                            <i class="fas fa-check-circle"></i> Approve Transparency Upload
                        </button>
                        <button type="button" class="rm-modal-btn-transparency-reject" id="rejectTransparencyUpdatesBtn" onclick="rejectTransparencyFromUpdates()">
                            <i class="fas fa-times-circle"></i> Reject Request
                        </button>
                    </span>
                </div>
                <?php endif; ?>
                <div id="actionButtons" style="display: flex; justify-content: space-between;">
                    <div style="display: flex; gap: 8px;">
                        <?php if ($is_officer_role): ?>
                        <button type="button" class="btn-success-custom" id="completeBtn"><i class="fas fa-clipboard-check"></i> Request Completion</button>
                        <button type="button" class="btn-danger-custom" id="cancelBtn"><i class="fas fa-ban"></i> Request Cancellation</button>
                        <?php elseif (!$is_system_admin): ?>
                        <button type="button" class="btn-success-custom" id="completeBtn"><i class="fas fa-circle-check"></i> Complete</button>
                        <button type="button" class="btn-danger-custom" id="cancelBtn"><i class="fas fa-ban"></i> Cancel</button>
                        <?php endif; ?>
                        <button type="button" class="btn-action" id="exportWordBtn" onclick="exportUpdatesToExcel()"><i class="fas fa-file-word"></i> Export as Word</button>
                        <?php if ($is_road_supervisor || $is_trans_ops_supervisor): ?>
                        <button type="button" class="btn-action" id="requestTransparencyBtn" style="display:none;background:linear-gradient(135deg,#3762c8,#2748a0);color:#fff;" onclick="requestTransparencyUpload()"><i class="fas fa-bullhorn"></i> Request Transparency Upload</button>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-action" id="addUpdateBtn" onclick="showAddUpdateModal()"><i class="fas fa-plus"></i> Add Update</button>
                        <button type="button" class="btn-secondary-custom" onclick="closeModal('updatesModal')"><i class="fas fa-xmark"></i> Close</button>
                    </div>
                </div>
                <div id="exportButtons" style="display: none; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn-action" onclick="exportUpdatesToExcel()">
                        <i class="fas fa-file-excel"></i> Export
                    </button>
                    <button type="button" class="btn-secondary-custom" onclick="closeModal('updatesModal')">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm Complete / Cancel (supervisors) -->
    <div id="statusConfirmModal" class="modal status-confirm-modal" style="display:none;z-index:10050;">
        <div class="modal-content status-confirm-content">
            <div class="modal-header">
                <h5 class="modal-title"><span id="statusConfirmIcon"><i class="fas fa-circle-check"></i></span> <span id="statusConfirmTitle">Confirm</span></h5>
                <button type="button" class="close" onclick="closeStatusConfirmModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p id="statusConfirmMessage" class="status-confirm-message">Are you sure?</p>
            </div>
            <div class="modal-footer status-confirm-footer">
                <button type="button" class="btn-secondary-custom" onclick="closeStatusConfirmModal()">
                    <i class="fas fa-arrow-left"></i> Go Back
                </button>
                <button type="button" id="statusConfirmSubmitBtn" class="btn-success-custom" onclick="confirmStatusAction()">
                    <i class="fas fa-check"></i> Confirm
                </button>
            </div>
        </div>
    </div>

    <!-- Add/Edit Update Modal -->
    <div id="addUpdateModal" class="modal">
        <div class="modal-content" style="max-width: 600px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> <span id="addUpdateModalTitle">Add Progress Update</span></h5>
                <button class="close" onclick="cancelUpdateForm()">&times;</button>
            </div>
            <form id="addUpdateForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" id="addUpdateAction" value="create_update">
                    <input type="hidden" name="update_id" id="addUpdateId" value="">
                    <input type="hidden" name="report_id" id="addUpdateReportId" value="">
                    <input type="hidden" name="report_type" id="addUpdateReportType" value="">
                    <input type="hidden" name="source" id="addUpdateSource" value="">
                    <div class="project-completion-banner" id="addUpdateProjectCompletionBanner" aria-live="polite">
                        <span class="project-completion-banner-label">Project Completion:</span>
                        <span class="project-completion-banner-value" id="addUpdateProjectCompletionValue">0%</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="addUpdateTitle">Title *</label>
                        <input type="text" name="title" id="addUpdateTitle" class="form-control" placeholder="e.g., Inspection completed" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="addUpdateDescription">Description *</label>
                        <textarea name="description" id="addUpdateDescription" class="form-control" rows="4" placeholder="Describe the progress made..." required></textarea>
                    </div>
                    <div class="form-group" id="addUpdateCompletionGroup">
                        <label class="form-label" for="addUpdateCompletionTrack">Project completion</label>
                        <div class="completion-slider" id="addUpdateCompletionSlider">
                            <div class="completion-slider-rail">
                                <div class="completion-slider-value" id="addUpdateCompletionValue" aria-hidden="true">0%</div>
                                <div class="completion-slider-track" id="addUpdateCompletionTrack" role="slider" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" tabindex="0" aria-label="Project completion percentage">
                                    <div class="completion-slider-fill" id="addUpdateCompletionFill"></div>
                                    <span class="completion-slider-handle" id="addUpdateCompletionHandle" role="presentation" aria-hidden="true"></span>
                                </div>
                            </div>
                            <div class="completion-slider-hints">
                                <span id="addUpdateCompletionMinHint">0%</span>
                                <span id="addUpdateCompletionFullHint" class="completion-slider-full-hint" style="display:none;">Project fully completed</span>
                                <span>100%</span>
                            </div>
                        </div>
                        <p id="addUpdateCompletionLockedNote" class="completion-slider-locked-note" style="display:none;">
                            Only the latest progress update can change project completion. This older update’s percentage is read-only.
                        </p>
                        <input type="hidden" name="completion_percentage" id="addUpdateCompletionPercentage" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="addUpdateMedia">Photos / Video</label>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                            <button type="button" id="addUpdatePhotosBtn" style="padding:8px 16px;background:#3762c8;color:white;border:none;border-radius:6px;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-camera"></i> Add Photos</button>
                            <small class="t-text-secondary" style="font-size:11px;">Accepted: JPG, PNG, GIF, WebP, MP4, WebM</small>
                        </div>
                        <input type="file" name="media[]" id="addUpdateMedia" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm" multiple style="display:none;">
                        <div class="file-previews" id="updateFilePreviews"></div>
                    </div>
                    <div id="existingUpdateMediaSection" style="display:none;">
                        <div class="form-group">
                            <span class="form-label" id="existingUpdateMediaLabel">Current media (check to remove)</span>
                            <div id="existingUpdateMedia" role="group" aria-labelledby="existingUpdateMediaLabel" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="justify-content: space-between;">
                    <span class="t-text-secondary" style="font-size:12px;">Updates are visible to all staff</span>
                    <div style="display:flex;gap:10px;">
                        <button type="button" class="btn-secondary-custom" onclick="cancelUpdateForm()">Cancel</button>
                        <button type="submit" class="btn-action" id="addUpdateSubmitBtn"><i class="fas fa-save"></i> Post Update</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Lightbox -->
    <div class="lightbox-overlay" id="lightboxOverlay" onclick="closeLightbox()">
        <button class="lightbox-close" onclick="closeLightbox()">&times;</button>
        <img id="lightboxImage" src="" alt="Enlarged photo">
    </div>


    <!-- Session Timeout Modal -->
    <div id="sessionTimeoutOverlay" class="t-modal-overlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; z-index:10000;"></div>
    <div id="sessionTimeoutModal" class="t-card" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); border-radius:12px; padding:32px; z-index:10001; width:400px; max-width:90vw; box-shadow:0 16px 48px rgba(0,0,0,0.3); text-align:center;">
        <div style="font-size:48px; color:#e74c3c; margin-bottom:16px;">
            <i class="fas fa-clock"></i>
        </div>
        <h3 style="margin:0 0 8px; font-size:20px; color:#1a1a2e;">Session Expiring</h3>
        <p class="t-text-secondary" style="margin:0 0 20px; font-size:14px;">
            Your session will expire in <strong><span id="sessionCountdown">60</span></strong> seconds due to inactivity.
        </p>
        <div style="display:flex; gap:12px; justify-content:center;">
            <button id="extendSessionBtn" class="t-gradient-primary" style="padding:10px 24px; border:none; border-radius:8px; font-size:14px; cursor:pointer; font-weight:600;">Extend Session</button>
            <button id="logoutSessionBtn" style="padding:10px 24px; background:#e74c3c; color:#fff; border:none; border-radius:8px; font-size:14px; cursor:pointer; font-weight:600;">Log Out</button>
        </div>
    </div>

    <!-- Session timeout data -->
    <script id="sessionTimeoutData" data-timeout="<?php echo $session_timeout; ?>" data-role="<?php echo htmlspecialchars($_SESSION['role'] ?? ''); ?>"></script>
    <script src="../../js/session-timeout.js"></script>

    <?php if ($is_system_admin): ?>
    <script>
        // Chart-style pop-up label on stats-row cards (system_admin only) - follows cursor like Chart.js tooltips
        (function () {
            const tooltip = document.createElement('div');
            tooltip.className = 'ds-tooltip';
            tooltip.setAttribute('role', 'tooltip');
            document.body.appendChild(tooltip);

            const dotColors = { blue: '#3762c8', orange: '#f59e0b', red: '#ef4444', green: '#10b981' };

            function getCardColor(el) {
                const icon = el.querySelector('.stat-icon');
                if (icon) {
                    for (const c of ['blue', 'orange', 'red', 'green']) {
                        if (icon.classList.contains(c)) return dotColors[c];
                    }
                }
                return '#3762c8';
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

            document.querySelectorAll('.stats-row .stat-card').forEach(el => {
                el.addEventListener('mouseenter', (e) => {
                    const valueEl = el.querySelector('.stat-number');
                    const labelEl = el.querySelector('.stat-label');
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
    </script>
    <?php endif; ?>
    <script>
        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            const dateEl = document.getElementById('currentDate');
            const timeEl = document.getElementById('currentTime');
            if (dateEl) dateEl.textContent = now.toLocaleDateString('en-US', dateOptions);
            if (timeEl) timeEl.textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);
    </script>
</body>
</html>

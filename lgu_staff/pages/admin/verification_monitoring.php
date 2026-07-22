<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/../../api/cimm_verification_data.php';

// Session timeout configuration
$session_timeout = 5 * 60; // 5 minutes in seconds

// Check if session has expired
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ../../login.php?timeout=1');
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();

// NOTE: cimm_reports (the local mock table) has been retired. CIMM reports now
// come live from the real cimm_verification_reports table, populated by the
// webhook/pull sync (see /lgu_staff/pages/api/cimm-reports-webhook.php and
// cimm-reports-pull.php) and read through rgmap_fetch_cimm_verification_reports()
// in cimm_verification_data.php.

// Ensure required columns exist in report tables
foreach (['road_transportation_reports', 'road_maintenance_reports'] as $tbl) {
    $check = $conn->query("SHOW COLUMNS FROM $tbl LIKE 'approved_at'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE $tbl ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    }
    $check2 = $conn->query("SHOW COLUMNS FROM $tbl LIKE 'rejected_at'");
    if ($check2 && $check2->num_rows === 0) {
        $conn->query("ALTER TABLE $tbl ADD COLUMN rejected_at TIMESTAMP NULL DEFAULT NULL AFTER approved_at");
    }
}

// Ensure report_category and report_source columns exist in road_transportation_reports
$check = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'report_category'");
if ($check && $check->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN report_category ENUM('road','transportation') DEFAULT NULL AFTER report_type");
}
$check2 = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'report_source'");
if ($check2 && $check2->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN report_source ENUM('local','external') DEFAULT 'local' AFTER report_category");
}

// Ensure the archive table has the same columns
$check_arch = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'report_category'");
if ($check_arch && $check_arch->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN report_category ENUM('road','transportation') DEFAULT NULL AFTER report_type");
}
$check_arch2 = $conn->query("SHOW COLUMNS FROM road_transportation_reports_archive LIKE 'report_source'");
if ($check_arch2 && $check_arch2->num_rows === 0) {
    $conn->query("ALTER TABLE road_transportation_reports_archive ADD COLUMN report_source ENUM('local','external') DEFAULT 'local' AFTER report_category");
}

// Ensure reports table exists (from reports.sql)
$conn->query("CREATE TABLE IF NOT EXISTS reports (
    rep_id int(10) unsigned NOT NULL AUTO_INCREMENT,
    res_id int(10) unsigned NOT NULL,
    starting_date date NOT NULL,
    estimated_end_date date NOT NULL,
    engineer_id int(10) unsigned DEFAULT NULL,
    report_by int(10) unsigned NOT NULL,
    priority_lvl varchar(50) DEFAULT NULL,
    budget decimal(15,2) NOT NULL DEFAULT 0.00,
    created_at timestamp NOT NULL DEFAULT current_timestamp(),
    engineer_accepted tinyint(1) NOT NULL DEFAULT 0,
    decline_reason text DEFAULT NULL,
    decline_reviewed tinyint(1) DEFAULT NULL COMMENT '1=valid,0=invalid',
    decline_review_note text DEFAULT NULL,
    PRIMARY KEY (rep_id),
    KEY fk_report_res (res_id),
    KEY fk_report_engineer (engineer_id),
    KEY fk_report_reporter (report_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'system_admin') {
    header('Location: ../../login.php');
    exit();
}

// Function to get verification statistics
function getVerificationStatistics($conn) {
    $stats = [];
    
    // Pending verifications from both tables
    $result = $conn->query("SELECT COUNT(*) as pending FROM road_transportation_reports WHERE status = 'pending'");
    $transport_pending = $result->fetch_assoc()['pending'];
    
    $result = $conn->query("SELECT COUNT(*) as pending FROM road_maintenance_reports WHERE status = 'pending'");
    $maintenance_pending = $result->fetch_assoc()['pending'];
    $stats['pending'] = $transport_pending + $maintenance_pending;
    
    // Debug: Add individual counts for display
    $stats['transport_pending'] = $transport_pending;
    $stats['maintenance_pending'] = $maintenance_pending;
    
    // In progress from both tables
    $result = $conn->query("SELECT COUNT(*) as in_progress FROM road_transportation_reports WHERE status = 'in-progress'");
    $transport_progress = $result->fetch_assoc()['in_progress'];
    
    $result = $conn->query("SELECT COUNT(*) as in_progress FROM road_maintenance_reports WHERE status = 'in-progress'");
    $maintenance_progress = $result->fetch_assoc()['in_progress'];
    $stats['in_review'] = $transport_progress + $maintenance_progress;
    
    // Approved (completed) from both tables
    $result = $conn->query("SELECT COUNT(*) as approved FROM road_transportation_reports WHERE status = 'approved'");
    $transport_completed = $result->fetch_assoc()['approved'];
    
    $result = $conn->query("SELECT COUNT(*) as approved FROM road_maintenance_reports WHERE status = 'approved'");
    $maintenance_completed = $result->fetch_assoc()['approved'];
    $stats['approved'] = $transport_completed + $maintenance_completed;
    
    return $stats;
}

// Function to get pending verifications
function getPendingVerifications($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at 
              FROM road_transportation_reports WHERE status = 'pending')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports WHERE status = 'pending')
              ORDER BY created_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getPendingVerifications: " . $conn->error);
    }
    return $result;
}

// Function to get approved reports
function getApprovedReports($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at 
               FROM road_transportation_reports WHERE status = 'approved')
               UNION ALL
               (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports WHERE status = 'approved')
               ORDER BY updated_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getApprovedReports: " . $conn->error);
    }
    return $result;
}

// Function to get rejected reports
function getRejectedReports($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at 
              FROM road_transportation_reports WHERE status = 'cancelled')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports WHERE status = 'cancelled')
              ORDER BY updated_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getRejectedReports: " . $conn->error);
    }
    return $result;
}

// Function to get all reports (for filtering)
function getAllReports($conn, $status_filter = 'all', $source_filter = 'all') {
    $parts = [];
    $transport_where = '';
    $maintenance_where = '';
    if ($status_filter !== 'all') {
        if ($status_filter === 'pending') {
            $transport_where = " WHERE status IN ('pending','in-progress')";
            $maintenance_where = " WHERE status IN ('pending','in-progress')";
        } elseif ($status_filter === 'approved') {
            $transport_where = " WHERE status IN ('approved','completed')";
            $maintenance_where = " WHERE status IN ('approved','completed')";
        } elseif ($status_filter === 'rejected') {
            $transport_where = " WHERE status IN ('cancelled')";
            $maintenance_where = " WHERE status IN ('cancelled')";
        }
    }
    $transport_exclude = "report_type != 'infrastructure_issue' AND (report_source IS NULL OR report_category IS NULL OR report_source != 'local' OR report_category != 'transportation')";
    if ($source_filter === 'transport') {
        $where = $transport_where ? "{$transport_where} AND {$transport_exclude}" : " WHERE {$transport_exclude}";
        $q = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at FROM road_transportation_reports{$where})";
        $parts[] = $q;
    } elseif ($source_filter === 'maintenance') {
        $q = "(SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports{$maintenance_where})";
        $parts[] = $q;
    } else {
        $where = $transport_where ? "{$transport_where} AND {$transport_exclude}" : " WHERE {$transport_exclude}";
        $parts[] = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at FROM road_transportation_reports{$where})";
        $parts[] = "(SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports{$maintenance_where})";
    }
    $query = implode(' UNION ALL ', $parts) . " ORDER BY created_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getAllReports: " . $conn->error);
    }
    return $result;
}

// Function to get recent approvals (for timeline)
function getRecentApprovals($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at FROM road_transportation_reports WHERE status = 'approved')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports WHERE status = 'approved')
              ORDER BY updated_at DESC LIMIT 10";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getRecentApprovals: " . $conn->error);
    }
    return $result;
}

// Function to get activity timeline
function getActivityTimeline($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at 
              FROM road_transportation_reports)
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at FROM road_maintenance_reports)
              ORDER BY updated_at DESC LIMIT 5";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getActivityTimeline: " . $conn->error);
    }
    return $result;
}

// Map a synced cimm_verification_reports row (from cimm_verification_data.php)
// into the flat shape the CIMM/Dept tables on this page already render.
//
// Two known gaps vs. the old mock data, left explicit rather than guessed:
//  1) "engineer" — CIMM doesn't sync an assigned engineer name; shows '—'
//     until/unless CIMM starts sending one (e.g. via cprf_facility_name or a
//     future assigned_engineer field).
//  2) "report_type" (staff vs dept) — CIMM's sync payload has no staff/dept
//     category today, so every synced row is bucketed as 'staff' for now.
//     Update this mapping once CIMM adds a category field to the payload.
function rgmap_map_cimm_row_for_display(array $row): array {
    $verification = $row['verification_status'] ?? 'Pending Review';
    $statusMap = [
        'Pending Review' => 'pending',
        'Flagged'        => 'in-progress',
        'Verified'       => 'completed',
        'Dismissed'      => 'resolved',
        'Pending'        => 'pending',
        'Approved'       => 'approved',
        'In Progress'    => 'in-progress',
        'Completed'      => 'completed',
        'Cancelled'      => 'cancelled',
    ];

    return [
        'id'            => $row['id'] ?? $row['cimm_req_id'] ?? 0,
        'rep_number'    => $row['reference_code'] ?? ('REQ-' . ($row['cimm_req_id'] ?? '')),
        'infrastructure'=> $row['infrastructure'] ?? '',
        'location'      => $row['location'] ?? '',
        'issue_notes'   => $row['issue'] ?? '',
        'engineer'      => $row['cprf_facility_name'] ?? '—',
        'reported_by'   => $row['reporter_name'] ?? '—',
        'report_type'   => 'staff', // see gap #2 above
        'start_date'    => $row['starting_date'] ?? null,
        'end_date'      => $row['estimated_end_date'] ?? null,
        'priority'      => strtolower((string)($row['priority'] ?? 'medium')),
        'budget'        => $row['budget'] ?? null,
        'status'        => $statusMap[$verification] ?? 'pending',
        'approval_status'      => $row['approval_status'] ?? null,
        'verification_status'  => $verification,
        'cimm_req_id'          => $row['cimm_req_id'] ?? null,
    ];
}

// Function to get CIMM reports by filter (live data from CIMM via RGMAO sync)
function getCimmReports($filter = 'all') {
    $pdo = rgmap_verification_pdo();
    $rows = rgmap_fetch_cimm_verification_reports($pdo, ['limit' => 500]);

    $mapped = array_map('rgmap_map_cimm_row_for_display', $rows);

    if ($filter === 'staff' || $filter === 'dept') {
        $mapped = array_values(array_filter($mapped, function ($r) use ($filter) {
            return $r['report_type'] === $filter;
        }));
    }

    return $mapped;
}

// Function to get CIMM report counts by type (live data from CIMM via RGMAO sync)
function getCimmReportCounts() {
    $pdo = rgmap_verification_pdo();
    $rows = rgmap_fetch_cimm_verification_reports($pdo, ['limit' => 500]);
    $mapped = array_map('rgmap_map_cimm_row_for_display', $rows);

    $counts = ['all' => count($mapped), 'staff' => 0, 'dept' => 0];
    foreach ($mapped as $r) {
        $counts[$r['report_type']] = ($counts[$r['report_type']] ?? 0) + 1;
    }

    return $counts;
}

// Function to get reports from reports.sql table
function getSqlReports($conn) {
    $query = "SELECT r.rep_id, r.res_id, r.starting_date, r.estimated_end_date,
                     r.engineer_id, r.report_by, r.priority_lvl, r.budget, r.created_at,
                     r.engineer_accepted, r.decline_reason, r.decline_reviewed, r.decline_review_note,
                     u.full_name as reporter_name
              FROM reports r
              LEFT JOIN users u ON r.report_by = u.id
              ORDER BY r.created_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getSqlReports: " . $conn->error);
    }
    return $result;
}

// Function to get citizen-submitted reports (report_source=local, report_category=transportation)
function getCitizenReports($conn) {
    $query = "SELECT id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, 
                     attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at,
                     reporter_name, reporter_email, reporter_phone, image_path, created_by
              FROM road_transportation_reports 
              WHERE report_source = 'local' AND report_category = 'transportation'
              ORDER BY created_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getCitizenReports: " . $conn->error);
    }
    return $result;
}

// Function to get infrastructure-only reports (road_transportation_reports where report_type = 'infrastructure_issue' + road_maintenance_reports)
function getInfraReports($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, report_category, report_source,
                     department, priority, status, created_date, due_date, description, location, attachments,
                     latitude, longitude, created_at, updated_at, approved_at, rejected_at,
                     reporter_name, reporter_email
              FROM road_transportation_reports WHERE report_type = 'infrastructure_issue')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, NULL as report_category, NULL as report_source,
                     department, priority, status, created_date, due_date, description, location, NULL as attachments,
                     NULL as latitude, NULL as longitude, created_at, updated_at, approved_at, rejected_at,
                     NULL as reporter_name, NULL as reporter_email
              FROM road_maintenance_reports)
              ORDER BY created_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getInfraReports: " . $conn->error);
    }
    return $result;
}

// Handle verification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['report_id']) && isset($_POST['source'])) {
        $report_id = (int) $_POST['report_id'];
        $source = $_POST['source'];
        $action = $_POST['action'];
        $table = ($source === 'transport') ? 'road_transportation_reports' : 'road_maintenance_reports';
        
        // Archive report then remove from active table
        if ($action === 'delete') {
            $insert = "INSERT INTO road_transportation_reports_archive (id, report_id, title, report_type, report_category, report_source, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at) SELECT id, report_id, title, report_type, report_category, report_source, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at, approved_at, rejected_at FROM $table WHERE id = ?";
            $stmt = $conn->prepare($insert);
            $stmt->bind_param('i', $report_id);
            if (!$stmt->execute()) {
                $_SESSION['verification_message'] = 'Failed to archive report: ' . $conn->error;
                header('Location: ../admin/verification_monitoring.php');
                exit();
            }
            $query = "DELETE FROM $table WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('i', $report_id);
            if (!$stmt->execute()) {
                $_SESSION['verification_message'] = 'Failed to delete report after archiving: ' . $conn->error;
                header('Location: ../admin/verification_monitoring.php');
                exit();
            }
            $_SESSION['verification_message'] = 'Report archived successfully.';
            header('Location: verification_monitoring.php');
            exit();
        }
        
        // Check verification rules: block approve for road+local reports
        if ($action === 'approve' && $source === 'transport') {
            $check = $conn->prepare("SELECT report_category, report_source FROM road_transportation_reports WHERE id = ?");
            $check->bind_param('i', $report_id);
            $check->execute();
            $r = $check->get_result()->fetch_assoc();
            if ($r && !canVerifyReport($r['report_category'], $r['report_source'])) {
                $_SESSION['verification_message'] = 'Road reports created by your LGU cannot be approved here. They must be verified by the external Engineering Office.';
                header('Location: ../admin/verification_monitoring.php');
                exit();
            }
        }

        // Update report status
        $status = '';
        $audit_status = '';
        switch ($action) {
            case 'approve':
                $status = 'approved';
                $audit_status = 'approved';
                break;
            case 'reject':
                $status = 'cancelled';
                $audit_status = 'rejected';
                break;
            case 'review':
                $status = 'in-progress';
                $audit_status = 'pending';
                break;
        }
        
        if ($status) {
            if ($action === 'approve') {
                $query = "UPDATE $table SET status = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?";
            } elseif ($action === 'reject') {
                $query = "UPDATE $table SET status = ?, rejected_at = NOW(), updated_at = NOW() WHERE id = ?";
            } else {
                $query = "UPDATE $table SET status = ?, updated_at = NOW() WHERE id = ?";
            }
            $stmt = $conn->prepare($query);
            $stmt->bind_param('si', $status, $report_id);
            $stmt->execute();
            
            // Log the action
            $audit_query = "INSERT INTO audit_trails (audit_id, title, audit_type, status, auditor, description, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $audit_stmt = $conn->prepare($audit_query);
            $audit_id = 'VR-' . date('Y-m-d-His');
            $title = ucfirst($action) . ' Report #' . $report_id;
            $audit_type = 'compliance'; // verification actions logged under compliance
            $auditor = $_SESSION['username'] ?? 'Unknown';
            $description = "Report #$report_id from $source table has been " . $action . "ed by $auditor";
            
            $audit_stmt->bind_param('ssssss', $audit_id, $title, $audit_type, $audit_status, $auditor, $description);
            $audit_stmt->execute();
            
            // Return success message
            $_SESSION['verification_message'] = 'Report ' . $action . 'd successfully!';
        }
        
        header('Location: ../admin/verification_monitoring.php');
        exit();
    }
}

// Handle CIMM report verification/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['verify_cimm', 'reject_cimm']) && isset($_POST['cimm_req_id'])) {
    $cimm_req_id = (int) $_POST['cimm_req_id'];
    $action = $_POST['action'];
    $pdo = rgmap_verification_pdo();

    if ($action === 'verify_cimm') {
        $ok = rgmap_update_verification_status($pdo, $cimm_req_id, 'Verified', null, $_SESSION['user_id'] ?? null);
        if ($ok) {
            $_SESSION['verification_message'] = 'CIMM report #' . $cimm_req_id . ' verified successfully.';
        } else {
            $_SESSION['verification_message'] = 'Failed to verify CIMM report #' . $cimm_req_id . '.';
        }
    } else {
        $reason = trim($_POST['rejection_reason'] ?? '');
        $ok = rgmap_update_verification_status($pdo, $cimm_req_id, 'Dismissed', $reason ?: 'Rejected by admin', $_SESSION['user_id'] ?? null);
        if ($ok) {
            $_SESSION['verification_message'] = 'CIMM report #' . $cimm_req_id . ' rejected successfully.';
        } else {
            $_SESSION['verification_message'] = 'Failed to reject CIMM report #' . $cimm_req_id . '.';
        }
    }

    header('Location: ../admin/verification_monitoring.php');
    exit();
}

// Show success message if set
if (isset($_SESSION['verification_message'])) {
    $success_message = $_SESSION['verification_message'];
    unset($_SESSION['verification_message']);
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$source_filter = $_GET['source'] ?? 'all';

// Get data
$stats = getVerificationStatistics($conn);
$pending_verifications = getPendingVerifications($conn);
$approved_reports = getApprovedReports($conn);
$rejected_reports = getRejectedReports($conn);
$all_reports = getAllReports($conn, $status_filter, $source_filter);
$recent_approvals = getRecentApprovals($conn);
$activity_timeline = getActivityTimeline($conn);

// CIMM reports data (live, via RGMAO sync)
$cimm_filter = $_GET['cimm_filter'] ?? 'all';
$cimm_reports = getCimmReports($cimm_filter);
$cimm_counts = getCimmReportCounts();

// Reports from reports.sql table
$sql_reports = getSqlReports($conn);

// Citizen-submitted reports
$citizen_reports = getCitizenReports($conn);

// Infrastructure-specific reports
$infra_reports = getInfraReports($conn);

// Handle AJAX request for report details
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_report_details') {
    header('Content-Type: application/json');
    $report_id = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
    $source = isset($_GET['source']) ? $_GET['source'] : '';
    
    if ($report_id && $source) {
        $table = ($source === 'transport') ? 'road_transportation_reports' : 'road_maintenance_reports';
        $query = "SELECT * FROM $table WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $report_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $report = $result->fetch_assoc();
        
        if ($report) {
            echo json_encode(['success' => true, 'report' => $report]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Report not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    }
    exit;
}

// Publish completed project to Public Transparency (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_completed_project') {
    header('Content-Type: application/json');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $completed_date = trim($_POST['completed_date'] ?? '');
    $cost = isset($_POST['cost']) ? (float) $_POST['cost'] : 0;
    $completed_by = trim($_POST['completed_by'] ?? '');
    $photo = trim($_POST['photo'] ?? '');
    $before_photo = trim($_POST['before_photo'] ?? '');
    if ($title === '') {
        echo json_encode(['success' => false, 'message' => 'Title is required.']);
        exit;
    }
    // Ensure table exists
    $conn->query("CREATE TABLE IF NOT EXISTS published_completed_projects (
        id int(11) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        description text,
        location varchar(255) DEFAULT NULL,
        completed_date date DEFAULT NULL,
        cost decimal(12,2) DEFAULT NULL,
        completed_by varchar(255) DEFAULT NULL,
        photo varchar(500) DEFAULT NULL,
        before_photo varchar(500) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $conn->prepare("INSERT INTO published_completed_projects (title, description, location, completed_date, cost, completed_by, photo, before_photo) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $date_val = ($completed_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $completed_date)) ? $completed_date : null;
    $stmt->bind_param('ssssdsss', $title, $description, $location, $date_val, $cost, $completed_by, $photo, $before_photo);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Published to Public Transparency.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to publish: ' . $conn->error]);
    }
    exit;
}

// Upload photo for completed project (AJAX, multipart)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_completed_project_photo' && !empty($_FILES['photo'])) {
    header('Content-Type: application/json');
    $upload_dir = __DIR__ . '/../../../uploads/completed_projects';
    $upload_dir = str_replace('\\', '/', $upload_dir);
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $result = handle_file_upload($_FILES['photo'], $upload_dir, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if ($result['success']) {
        $relative_path = 'uploads/completed_projects/' . $result['filename'];
        echo json_encode(['success' => true, 'path' => $relative_path]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Upload failed']);
    }
    exit;
}

// Upload before photo for completed project (AJAX, multipart)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_before_photo' && !empty($_FILES['before_photo'])) {
    header('Content-Type: application/json');
    $upload_dir = __DIR__ . '/../../../uploads/completed_projects';
    $upload_dir = str_replace('\\', '/', $upload_dir);
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $result = handle_file_upload($_FILES['before_photo'], $upload_dir, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if ($result['success']) {
        $relative_path = 'uploads/completed_projects/' . $result['filename'];
        echo json_encode(['success' => true, 'path' => $relative_path]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Upload failed']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification & Monitoring Reports | LGU Staff</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f7f5f0;
            min-height: 100vh;
            margin: 0;
            padding: 0;
        }

        html { scroll-behavior: smooth; }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            position: relative;
            z-index: 1;
        }
        .verification-header {
            background: #f0f4fa;
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
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

        .workflow-stats {
            display: flex;
            gap: 20px;
        }

        .workflow-stat {
            text-align: center;
            padding: 15px 20px;
            background: rgba(55, 98, 200, 0.05);
            border-radius: 10px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .workflow-number {
            font-size: 24px;
            font-weight: 700;
            color: #3762c8;
            margin-bottom: 5px;
        }

        .workflow-label {
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }

        .workflow-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        .workflow-card {
            background: #f0f4fa;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
        }

        .workflow-content {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .workflow-content::-webkit-scrollbar {
            width: 6px;
        }

        .workflow-content::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.1);
            border-radius: 3px;
        }

        .workflow-content::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.3);
            border-radius: 3px;
        }

        .workflow-content::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.5);
        }

        .workflow-header {
            display: flex;
            justify-content: flex-start;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
            flex-wrap: wrap;
            gap: 15px;
        }

        .workflow-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .workflow-badge {
            background: #3762c8;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .workflow-badge.pending {
            background: #ffc107;
        }

        .workflow-badge.approved {
            background: #28a745;
        }

        .workflow-badge.rejected {
            background: #dc3545;
        }

        .btn-maintenance {
            padding: 8px 16px;
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-maintenance:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.4);
        }

        .verification-item {
            display: flex;
            align-items: flex-start;
            padding: 20px;
            margin-bottom: 15px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 12px;
            border: 1px solid rgba(55, 98, 200, 0.1);
            transition: all 0.3s ease;
            position: relative;
        }

        .verification-item:hover {
            background: rgba(55, 98, 200, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.1);
        }

        .verification-priority {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .priority-high {
            background: #dc3545;
        }

        .priority-medium {
            background: #ffc107;
        }

        .priority-low {
            background: #28a745;
        }

        .verification-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-right: 20px;
            flex-shrink: 0;
        }

        .verification-content {
            flex: 1;
        }

        .verification-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .verification-meta {
            display: flex;
            gap: 20px;
            margin-bottom: 12px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #666;
        }

        .meta-item i {
            color: #3762c8;
        }

        .verification-description {
            font-size: 14px;
            color: #666;
            line-height: 1.5;
            margin-bottom: 15px;
        }
        
        .expanded-details {
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                max-height: 0;
            }
            to {
                opacity: 1;
                max-height: 2000px;
            }
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .detail-item {
            padding: 12px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }
        
        .detail-item.full-width {
            grid-column: 1 / -1;
        }
        
        .detail-item strong {
            color: #1e3c72;
            display: block;
            margin-bottom: 5px;
            font-size: 13px;
        }

        body.dark-mode .detail-item {
            background: #1e2229;
            border-color: #2d323b;
            color: #d1d5db;
        }
        body.dark-mode .detail-item strong {
            color: #f0f2f5;
        }
        body.dark-mode .expanded-details {
            border-top-color: rgba(59, 130, 246, 0.2);
        }
        body.dark-mode .detail-item [style*="background: rgba(55, 98, 200, 0.05)"] {
            background: rgba(59, 130, 246, 0.1) !important;
        }
        body.dark-mode .detail-item a[style*="color: #3762c8"] {
            color: #93c5fd !important;
        }
        body.dark-mode .detail-item img {
            border-color: rgba(59, 130, 246, 0.3) !important;
        }

        .verification-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .verification-actions form {
            display: inline-flex;
            gap: 10px;
            margin: 0;
            padding: 0;
        }

        .btn-verify {
            padding: 8px 16px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-verify:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-reject {
            padding: 8px 16px;
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-reject:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .btn-review {
            padding: 8px 16px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-review:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }

        .btn-remove {
            padding: 8px 16px;
            background: rgba(108, 117, 125, 0.9);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-remove:hover {
            background: #dc3545;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .timeline-section {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .timeline-header {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: rgba(55, 98, 200, 0.2);
        }

        .timeline-item {
            position: relative;
            padding-bottom: 25px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-marker {
            position: absolute;
            left: -22px;
            top: 5px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #3762c8;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(55, 98, 200, 0.3);
        }

        .timeline-marker.approved {
            background: #28a745;
        }

        .timeline-marker.rejected {
            background: #dc3545;
        }

        .timeline-marker.pending {
            background: #ffc107;
        }

        .timeline-content {
            background: rgba(255, 255, 255, 0.7);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .timeline-title {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .timeline-description {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }

        .timeline-time {
            font-size: 11px;
            color: #999;
        }

        body.dark-mode .timeline-section {
            background: #1e2229;
            border-color: #2d323b;
        }
        body.dark-mode .timeline-header {
            color: #f0f2f5;
        }
        body.dark-mode .timeline::before {
            background: rgba(59, 130, 246, 0.25);
        }
        body.dark-mode .timeline-marker {
            border-color: #1a1d23;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2);
        }
        body.dark-mode .timeline-marker.approved {
            background: #059669;
        }
        body.dark-mode .timeline-marker.rejected {
            background: #dc2626;
        }
        body.dark-mode .timeline-marker.pending {
            background: #d97706;
        }
        body.dark-mode .timeline-content {
            background: #22262e;
            border-color: #2d323b;
        }
        body.dark-mode .timeline-title {
            color: #f0f2f5;
        }
        body.dark-mode .timeline-description {
            color: #d1d5db;
        }
        body.dark-mode .timeline-time {
            color: #6b7280;
        }

        .filters-section {
            background: #f0f4fa;
            backdrop-filter: blur(15px);
            padding: 20px 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .filter-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group .form-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background: white;
            font-size: 13px;
            min-width: 180px;
        }

        .btn-secondary-custom {
            padding: 8px 16px;
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

        body.dark-mode .filters-section {
            background: #1e2229;
            border-color: rgba(255,255,255,0.08);
        }
        body.dark-mode .filter-group .form-label {
            color: #9ca3af;
        }
        body.dark-mode .filter-select {
            background: #2d323b;
            border-color: rgba(255,255,255,0.12);
            color: #e4e6ea;
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

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            animation: slideIn 0.3s ease;
        }

        .notification.success {
            background: #28a745;
        }

        .notification.error {
            background: #dc3545;
        }

        .notification.info {
            background: #17a2b8;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @media (max-width: 1200px) {
            .workflow-container {
                grid-template-columns: 1fr;
            }
        }

        /* CIMM Received Reports Panel */

        .cimm-search-bar {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }

        .cimm-search-input {
            flex: 1;
            padding: 12px 16px;
            padding-left: 42px;
            background: #ffffff;
            border: 1px solid #ddd;
            border-radius: 10px;
            color: #333;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
            position: relative;
        }

        .cimm-search-wrapper {
            position: relative;
            flex: 1;
        }

        .cimm-search-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 14px;
        }

        .cimm-search-input::placeholder {
            color: #6b7280;
        }

        .cimm-search-input:focus {
            border-color: #3762c8;
        }

        .cimm-sort-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: #3762c8;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .cimm-sort-btn:hover {
            background: #2b4fa3;
        }

        .cimm-table-wrapper {
            overflow-x: auto;
        }

        .cimm-table {
            width: 100%;
            border-collapse: collapse;
        }

        .cimm-table thead th {
            background: #f97316;
            color: white;
            padding: 12px 14px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .cimm-table thead th:first-child {
            border-radius: 8px 0 0 0;
        }

        .cimm-table thead th:last-child {
            border-radius: 0 8px 0 0;
        }

        .cimm-table tbody tr {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            transition: background 0.2s;
        }

        .cimm-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.05);
        }

        .cimm-table tbody td {
            padding: 14px;
            color: #333;
            font-size: 13px;
            white-space: nowrap;
        }

        .cimm-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .cimm-empty-state .refresh-icon {
            width: 60px;
            height: 60px;
            background: rgba(55, 98, 200, 0.15);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .cimm-empty-state .refresh-icon i {
            font-size: 28px;
            color: #6b7280;
        }

        .cimm-empty-state p {
            font-size: 14px;
            font-weight: 500;
            color: #8892a4;
        }

        .cimm-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .cimm-status-badge.pending {
            background: rgba(251, 191, 36, 0.15);
            color: #fbbf24;
        }

        .cimm-status-badge.in-progress {
            background: rgba(59, 130, 246, 0.15);
            color: #60a5fa;
        }

        .cimm-status-badge.completed,
        .cimm-status-badge.resolved {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .cimm-action-btn {
            padding: 6px 12px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .cimm-action-btn:hover {
            background: rgba(55, 98, 200, 0.2);
        }

        body.dark-mode .cimm-search-input {
            background: #1a2332;
            border-color: rgba(255, 255, 255, 0.1);
            color: #f0f4fa;
        }

        body.dark-mode .cimm-table tbody tr {
            border-bottom-color: rgba(255, 255, 255, 0.05);
        }

        body.dark-mode .cimm-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.08);
        }

        body.dark-mode .cimm-table tbody td {
            color: #c0c8d8;
        }

        body.dark-mode .cimm-action-btn {
            background: rgba(55, 98, 200, 0.15);
            color: #60a5fa;
        }

        @media (max-width: 768px) {
            .cimm-search-bar {
                flex-direction: column;
            }
        }

        /* Section Panel Wrappers */
        .section-panel {
            background: #f0f4fa;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
            margin-bottom: 25px;
            overflow: hidden;
        }

        body.dark-mode .section-panel {
            background: #1e2229;
            border-color: #2d323b;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        /* Dept Reports Panel */
        .dept-reports-panel {
            background: #f0f4fa;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
            margin-bottom: 25px;
            overflow: hidden;
        }

        body.dark-mode .dept-reports-panel {
            background: #1e2229;
            border-color: #2d323b;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .dept-reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
        }

        .dept-reports-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .dept-reports-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .dept-reports-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .dept-reports-title {
            font-size: 20px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0;
        }

        body.dark-mode .dept-reports-title {
            color: #f0f4fa;
        }

        .dept-reports-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #3762c8;
            color: white;
        }

        .dept-reports-badge.pending {
            background: rgba(251, 191, 36, 0.15);
            color: #f59e0b;
        }

        .dept-reports-badge.in-progress {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .dept-reports-badge.completed {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .dept-reports-subtitle {
            font-size: 13px;
            color: #6b7280;
            margin: 2px 0 0 0;
        }

        body.dark-mode .dept-reports-subtitle {
            color: #9ca3af;
        }

        .dept-reports-search {
            display: flex;
            gap: 12px;
            padding: 18px 25px;
            border-bottom: 1px solid rgba(55, 98, 200, 0.08);
        }

        .dept-search-wrapper {
            position: relative;
            flex: 1;
        }

        .dept-search-wrapper i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 14px;
        }

        .dept-search-input {
            width: 100%;
            padding: 11px 16px 11px 40px;
            background: #ffffff;
            border: 1px solid #e0e0e0;
            border-radius: 10px;
            color: #333;
            font-size: 13px;
            font-family: 'Poppins', sans-serif;
            outline: none;
            transition: border-color 0.3s;
        }

        body.dark-mode .dept-search-input {
            background: #2d323b;
            border-color: rgba(255, 255, 255, 0.1);
            color: #e4e6ea;
        }

        .dept-search-input::placeholder {
            color: #9ca3af;
        }

        .dept-search-input:focus {
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.1);
        }

        .dept-sort-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .dept-sort-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }

        .dept-table-wrapper {
            overflow-x: auto;
            padding: 0;
        }

        .dept-table {
            width: 100%;
            border-collapse: collapse;
        }

        .dept-table thead th {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .dept-table thead th:first-child {
            border-radius: 0;
        }

        .dept-table thead th:last-child {
            border-radius: 0;
        }

        .dept-table tbody tr {
            border-bottom: 1px solid rgba(55, 98, 200, 0.08);
            transition: background 0.2s;
        }

        .dept-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.05);
        }

        .dept-table tbody td {
            padding: 14px 16px;
            color: #333;
            font-size: 13px;
            white-space: nowrap;
        }

        body.dark-mode .dept-table tbody td {
            color: #c0c8d8;
        }

        body.dark-mode .dept-table tbody tr {
            border-bottom-color: rgba(255, 255, 255, 0.05);
        }

        body.dark-mode .dept-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.08);
        }

        .dept-action-btn {
            padding: 6px 12px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .dept-action-btn:hover {
            background: rgba(55, 98, 200, 0.2);
        }

        body.dark-mode .dept-action-btn {
            background: rgba(55, 98, 200, 0.15);
            color: #60a5fa;
        }

        .dept-action-group {
            display: inline-flex;
            gap: 4px;
            align-items: center;
        }

        .dept-verify-btn {
            padding: 5px 10px;
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .dept-verify-btn:hover {
            background: rgba(34, 197, 94, 0.2);
        }

        body.dark-mode .dept-verify-btn {
            background: rgba(34, 197, 94, 0.15);
            color: #4ade80;
        }

        .dept-reject-btn {
            padding: 5px 10px;
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .dept-reject-btn:hover {
            background: rgba(220, 53, 69, 0.2);
        }

        body.dark-mode .dept-reject-btn {
            background: rgba(220, 53, 69, 0.15);
            color: #f87171;
        }

        .dept-action-form {
            display: inline;
        }

        .dept-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .dept-status-badge.pending {
            background: rgba(251, 191, 36, 0.15);
            color: #f59e0b;
        }

        .dept-status-badge.in-progress {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .dept-status-badge.completed,
        .dept-status-badge.resolved {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .dept-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .dept-empty-icon {
            width: 56px;
            height: 56px;
            background: rgba(55, 98, 200, 0.12);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .dept-empty-icon i {
            font-size: 26px;
            color: #3762c8;
        }

        body.dark-mode .dept-empty-icon {
            background: rgba(96, 165, 250, 0.12);
        }

        body.dark-mode .dept-empty-icon i {
            color: #60a5fa;
        }

        .dept-empty-state h4 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
        }

        body.dark-mode .dept-empty-state h4 {
            color: #e4e6ea;
        }

        .dept-empty-state p {
            font-size: 14px;
            color: #9ca3af;
            font-weight: 500;
        }

        /* Infra Reports Panel */
        .infra-reports-panel {
            background: #fff8f0;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #f0e0cc;
            margin-bottom: 25px;
            overflow: hidden;
        }

        body.dark-mode .infra-reports-panel {
            background: #1e2229;
            border-color: #3d3226;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .infra-reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 2px solid rgba(249, 115, 22, 0.15);
        }

        .infra-reports-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .infra-reports-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .infra-reports-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .infra-reports-title {
            font-size: 20px;
            font-weight: 700;
            color: #c2410c;
            margin: 0;
        }

        body.dark-mode .infra-reports-title {
            color: #fdba74;
        }

        .infra-reports-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #f97316;
            color: white;
        }

        .infra-reports-badge.pending {
            background: rgba(251, 191, 36, 0.15);
            color: #f59e0b;
        }

        .infra-reports-badge.in-progress {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .infra-reports-badge.completed {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .infra-reports-subtitle {
            font-size: 13px;
            color: #92400e;
            margin: 2px 0 0 0;
        }

        body.dark-mode .infra-reports-subtitle {
            color: #d6a564;
        }

        .infra-reports-search {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 25px;
            border-bottom: 1px solid rgba(249, 115, 22, 0.08);
        }

        .infra-search-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            border: 1px solid rgba(249, 115, 22, 0.2);
            border-radius: 10px;
            padding: 10px 16px;
            transition: border-color 0.2s;
        }

        body.dark-mode .infra-search-wrapper {
            background: #2a2e37;
            border-color: rgba(249, 115, 22, 0.3);
        }

        .infra-search-wrapper:focus-within {
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.1);
        }

        .infra-search-wrapper i {
            color: #9ca3af;
            font-size: 14px;
        }

        .infra-search-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 13px;
            color: #333;
            background: transparent;
        }

        body.dark-mode .infra-search-input {
            color: #e4e6ea;
        }

        .infra-search-input::placeholder {
            color: #9ca3af;
        }

        .infra-sort-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .infra-sort-btn:hover {
            background: linear-gradient(135deg, #ea580c, #c2410c);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(249, 115, 22, 0.3);
        }

        .infra-table-wrapper {
            overflow-x: auto;
            padding: 0;
        }

        .infra-table {
            width: 100%;
            border-collapse: collapse;
        }

        .infra-table thead th {
            background: linear-gradient(135deg, #f97316, #ea580c);
            color: white;
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .infra-table thead th:first-child {
            border-radius: 0;
        }

        .infra-table thead th:last-child {
            border-radius: 0;
        }

        .infra-table tbody tr {
            border-bottom: 1px solid rgba(249, 115, 22, 0.08);
            transition: background 0.2s;
        }

        .infra-table tbody tr:hover {
            background: rgba(249, 115, 22, 0.05);
        }

        .infra-table tbody td {
            padding: 14px 16px;
            color: #333;
            font-size: 13px;
            white-space: nowrap;
        }

        body.dark-mode .infra-table tbody td {
            color: #c0c8d8;
        }

        body.dark-mode .infra-table tbody tr {
            border-bottom-color: rgba(255, 255, 255, 0.05);
        }

        body.dark-mode .infra-table tbody tr:hover {
            background: rgba(249, 115, 22, 0.08);
        }

        .infra-action-btn {
            padding: 6px 12px;
            background: rgba(249, 115, 22, 0.1);
            color: #f97316;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .infra-action-btn:hover {
            background: rgba(249, 115, 22, 0.2);
        }

        body.dark-mode .infra-action-btn {
            background: rgba(249, 115, 22, 0.15);
            color: #fb923c;
        }

        .infra-action-group {
            display: inline-flex;
            gap: 4px;
            align-items: center;
        }

        .infra-verify-btn {
            padding: 5px 10px;
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .infra-verify-btn:hover {
            background: rgba(34, 197, 94, 0.2);
        }

        body.dark-mode .infra-verify-btn {
            background: rgba(34, 197, 94, 0.15);
            color: #4ade80;
        }

        .infra-reject-btn {
            padding: 5px 10px;
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .infra-reject-btn:hover {
            background: rgba(220, 53, 69, 0.2);
        }

        body.dark-mode .infra-reject-btn {
            background: rgba(220, 53, 69, 0.15);
            color: #f87171;
        }

        .infra-action-form {
            display: inline;
        }

        .infra-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .infra-status-badge.pending {
            background: rgba(251, 191, 36, 0.15);
            color: #f59e0b;
        }

        .infra-status-badge.in-progress {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
        }

        .infra-status-badge.completed,
        .infra-status-badge.approved,
        .infra-status-badge.resolved {
            background: rgba(34, 197, 94, 0.15);
            color: #22c55e;
        }

        .infra-status-badge.cancelled {
            background: rgba(220, 53, 69, 0.15);
            color: #ef4444;
        }

        .infra-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #92400e;
        }

        .infra-empty-icon {
            width: 56px;
            height: 56px;
            background: rgba(249, 115, 22, 0.12);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .infra-empty-icon i {
            font-size: 26px;
            color: #f97316;
        }

        body.dark-mode .infra-empty-icon {
            background: rgba(251, 146, 60, 0.12);
        }

        body.dark-mode .infra-empty-icon i {
            color: #fb923c;
        }

        .infra-empty-state h4 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
        }

        body.dark-mode .infra-empty-state h4 {
            color: #e4e6ea;
        }

        .infra-empty-state p {
            font-size: 14px;
            color: #9ca3af;
            font-weight: 500;
        }

        /* Citizen Reports Panel */
        .citizen-reports-panel {
            background: #f0f8f4;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #cce0d4;
            margin-bottom: 25px;
            overflow: hidden;
        }

        body.dark-mode .citizen-reports-panel {
            background: #1e2229;
            border-color: #1a3d2a;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }

        .citizen-reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 25px;
            border-bottom: 2px solid rgba(22, 163, 74, 0.15);
        }

        .citizen-reports-header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .citizen-reports-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .citizen-reports-title-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .citizen-reports-title {
            font-size: 20px;
            font-weight: 700;
            color: #15803d;
            margin: 0;
        }

        body.dark-mode .citizen-reports-title {
            color: #86efac;
        }

        .citizen-reports-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            background: #16a34a;
            color: white;
        }

        .citizen-reports-subtitle {
            font-size: 13px;
            color: #166534;
            margin: 2px 0 0 0;
        }

        body.dark-mode .citizen-reports-subtitle {
            color: #6ee7b7;
        }

        .citizen-reports-search {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px 25px;
            border-bottom: 1px solid rgba(22, 163, 74, 0.08);
        }

        .citizen-search-wrapper {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 10px;
            background: white;
            border: 1px solid rgba(22, 163, 74, 0.2);
            border-radius: 10px;
            padding: 10px 16px;
            transition: border-color 0.2s;
        }

        body.dark-mode .citizen-search-wrapper {
            background: #2a2e37;
            border-color: rgba(22, 163, 74, 0.3);
        }

        .citizen-search-wrapper:focus-within {
            border-color: #16a34a;
            box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.1);
        }

        .citizen-search-wrapper i {
            color: #9ca3af;
            font-size: 14px;
        }

        .citizen-search-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 13px;
            color: #333;
            background: transparent;
        }

        body.dark-mode .citizen-search-input {
            color: #e4e6ea;
        }

        .citizen-search-input::placeholder {
            color: #9ca3af;
        }

        .citizen-sort-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
        }

        .citizen-sort-btn:hover {
            background: linear-gradient(135deg, #15803d, #166534);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.3);
        }

        .citizen-table-wrapper {
            overflow-x: auto;
            padding: 0;
        }

        .citizen-table {
            width: 100%;
            border-collapse: collapse;
        }

        .citizen-table thead th {
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: white;
            padding: 14px 16px;
            font-size: 12px;
            font-weight: 700;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .citizen-table thead th:first-child { border-radius: 0; }
        .citizen-table thead th:last-child { border-radius: 0; }

        .citizen-table tbody tr {
            border-bottom: 1px solid rgba(22, 163, 74, 0.08);
            transition: background 0.2s;
        }

        .citizen-table tbody tr:hover {
            background: rgba(22, 163, 74, 0.05);
        }

        .citizen-table tbody td {
            padding: 14px 16px;
            color: #333;
            font-size: 13px;
            white-space: nowrap;
        }

        body.dark-mode .citizen-table tbody td { color: #c0c8d8; }
        body.dark-mode .citizen-table tbody tr { border-bottom-color: rgba(255,255,255,0.05); }
        body.dark-mode .citizen-table tbody tr:hover { background: rgba(22,163,74,0.08); }

        .citizen-action-btn {
            padding: 6px 12px;
            background: rgba(22, 163, 74, 0.1);
            color: #16a34a;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .citizen-action-btn:hover { background: rgba(22, 163, 74, 0.2); }
        body.dark-mode .citizen-action-btn { background: rgba(22,163,74,0.15); color: #4ade80; }

        .citizen-action-group {
            display: inline-flex;
            gap: 4px;
            align-items: center;
        }

        .citizen-verify-btn {
            padding: 5px 10px;
            background: rgba(34, 197, 94, 0.1);
            color: #16a34a;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .citizen-verify-btn:hover { background: rgba(34, 197, 94, 0.2); }
        body.dark-mode .citizen-verify-btn { background: rgba(34,197,94,0.15); color: #4ade80; }

        .citizen-reject-btn {
            padding: 5px 10px;
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .citizen-reject-btn:hover { background: rgba(220, 53, 69, 0.2); }
        body.dark-mode .citizen-reject-btn { background: rgba(220,53,69,0.15); color: #f87171; }

        .citizen-action-form { display: inline; }

        .citizen-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .citizen-status-badge.pending { background: rgba(251,191,36,0.15); color: #f59e0b; }
        .citizen-status-badge.in-progress { background: rgba(59,130,246,0.15); color: #3b82f6; }
        .citizen-status-badge.completed,
        .citizen-status-badge.approved,
        .citizen-status-badge.resolved { background: rgba(34,197,94,0.15); color: #22c55e; }
        .citizen-status-badge.cancelled { background: rgba(220,53,69,0.15); color: #ef4444; }

        .citizen-empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #166534;
        }

        .citizen-empty-icon {
            width: 56px;
            height: 56px;
            background: rgba(22, 163, 74, 0.12);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
        }

        .citizen-empty-icon i { font-size: 26px; color: #16a34a; }
        body.dark-mode .citizen-empty-icon { background: rgba(22,163,74,0.12); }
        body.dark-mode .citizen-empty-icon i { color: #4ade80; }

        @media (max-width: 768px) {
            .dept-reports-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .dept-reports-search {
                flex-direction: column;
            }

            .infra-reports-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .infra-reports-search {
                flex-direction: column;
            }

            .citizen-reports-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .citizen-reports-search {
                flex-direction: column;
            }
        }

        /* Modal Styles */
        .modal-overlay {
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
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 900px;
            width: 100%;
            max-height: calc(100vh - 40px);
            position: relative;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            margin: auto;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }
        
        .modal-body {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding-right: 10px;
            margin-right: -10px;
        }
        
        .modal-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.1);
            border-radius: 4px;
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.3);
            border-radius: 4px;
        }
        
        .modal-body::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.5);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
            flex-shrink: 0;
        }
        
        .modal-header h2 {
            color: #1e3c72;
            font-size: 24px;
            margin: 0;
            flex: 1;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: #666;
            cursor: pointer;
            padding: 0;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
            flex-shrink: 0;
            margin-left: 15px;
        }
        
        .modal-close:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .detail-label {
            font-weight: 600;
            color: #333;
            width: 150px;
            flex-shrink: 0;
        }
        
        .detail-value {
            color: #666;
            flex: 1;
        }
        
        .modal-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
            margin-top: 10px;
            cursor: pointer;
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid rgba(55, 98, 200, 0.1);
            flex-shrink: 0;
        }



        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .workflow-stats {
                width: 100%;
                justify-content: space-between;
            }
            
            .verification-actions {
                flex-wrap: wrap;
            }
            
            .verification-actions form {
                flex-wrap: wrap;
            }
            
            .modal-overlay {
                padding: 10px;
            }
            
            .modal-content {
                width: 100%;
                max-width: 100%;
                padding: 20px;
                max-height: calc(100vh - 20px);
            }
            
            .modal-header h2 {
                font-size: 20px;
            }
            
            .detail-row {
                flex-direction: column;
            }
            
            .detail-label {
                width: 100%;
                margin-bottom: 5px;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <!-- Verification Header Panel -->
        <div class="section-panel">
            <div class="verification-header" style="margin-bottom:0; box-shadow:none; border:none; border-radius:0;">
                <div class="header-content">
                    <div class="header-title">
                        <h1>Verification & Monitoring Reports</h1>
                        <p>Review and approve infrastructure Projects and monitoring data</p>
                    </div>
                    <div class="workflow-stats">
                        <div class="workflow-stat">
                            <div class="workflow-number"><?php echo number_format($stats['pending']); ?></div>
                            <div class="workflow-label">Pending</div>
                        </div>
                        <div class="workflow-stat">
                            <div class="workflow-number"><?php echo number_format($stats['in_review']); ?></div>
                            <div class="workflow-label">In Review</div>
                        </div>
                        <div class="workflow-stat">
                            <div class="workflow-number"><?php echo number_format($stats['approved']); ?></div>
                            <div class="workflow-label">Approved</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters Panel -->
        <div class="section-panel">
            <div class="filters-section" style="margin-bottom:0; box-shadow:none; border:none; border-radius:0;">
                <div class="filter-group">
                    <div>
                        <label class="form-label">Status Filter</label>
                        <select class="filter-select" id="statusFilter" onchange="filterReports()">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending / In Progress</option>
                            <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved / Completed</option>
                            <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Source System</label>
                        <select class="filter-select" id="sourceFilter" onchange="filterReports()">
                            <option value="all" <?php echo $source_filter === 'all' ? 'selected' : ''; ?>>All Sources</option>
                            <option value="transport" <?php echo $source_filter === 'transport' ? 'selected' : ''; ?>>Citizen Reports</option>
                            <option value="cimm" <?php echo $source_filter === 'cimm' ? 'selected' : ''; ?>>CIMM Reports</option>
                            <option value="maintenance" <?php echo $source_filter === 'maintenance' ? 'selected' : ''; ?>>Infrastructure Projects</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button class="btn-secondary-custom" onclick="resetFilters()">
                                <i class="fas fa-arrow-clockwise"></i> Reset
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Workflow Container Panel -->
        <div class="section-panel" id="citizenReportsPanel">
            <div class="workflow-container" style="margin-bottom:0;">
                <!-- All Reports (filterable) -->
                <div class="workflow-card" style="box-shadow:none; border:none; border-radius:0;">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-list"></i>
                        <span id="section-title">All Reports</span>
                        <span class="workflow-badge" id="section-badge"><?php echo $all_reports->num_rows; ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content" id="reports-container">
                    <?php 
                    // Reset pointer and display all reports
                    $all_reports->data_seek(0);
                    if ($all_reports->num_rows > 0): 
                    ?>
                        <?php while ($report = $all_reports->fetch_assoc()): 
                            $status_class = '';
                            if ($report['status'] === 'approved') $status_class = 'approved';
                            elseif ($report['status'] === 'cancelled') $status_class = 'rejected';
                            elseif ($report['status'] === 'pending') $status_class = 'pending';
                            elseif ($report['status'] === 'in-progress') $status_class = 'in-progress';
                            elseif ($report['status'] === 'completed') $status_class = 'completed';

                            // Check if this report can be verified locally
                            $report_category = $report['report_category'] ?? null;
                            $report_source = $report['report_source'] ?? null;
                            $can_verify = canVerifyReport($report_category, $report_source);
                            // Road+local reports that are pending show as awaiting external verification
                            $pending_ext_verify = ($report['status'] === 'pending' && !$can_verify);
                        ?>
                            <div class="verification-item" data-status="<?php echo htmlspecialchars($report['status']); ?>" data-source="<?php echo htmlspecialchars($report['source']); ?>" data-created-by="<?php echo htmlspecialchars($report['created_by'] ?? ''); ?>" data-reporter-name="<?php echo htmlspecialchars($report['reporter_name'] ?? ''); ?>">
                                <div class="verification-priority priority-<?php echo htmlspecialchars($report['priority'] ?? 'medium'); ?>"></div>
                                <div class="verification-icon">
                                    <i class="fas fa-<?php echo getReportIcon($report['report_type']); ?>"></i>
                                </div>
                                <div class="verification-content">
                                    <div class="verification-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                    <div class="verification-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-tag"></i>
                                            <?php 
                                            $report_type = $report['report_type'] ?? '';
                                            $specific_types = [
                                                'traffic_jam' => 'Traffic Jam',
                                                'accident' => 'Vehicle Accident',
                                                'road_closure' => 'Road Closure',
                                                'traffic_light_outage' => 'Traffic Light Outage',
                                                'congestion' => 'Heavy Congestion',
                                                'parking_violation' => 'Illegal Parking',
                                                'public_transport_issue' => 'Public Transport Issue',
                                                'potholes' => 'Potholes',
                                                'road_damage' => 'Road Damage',
                                                'cracks' => 'Road Cracks',
                                                'erosion' => 'Road Erosion',
                                                'flooding' => 'Street Flooding',
                                                'debris' => 'Road Debris',
                                                'shoulder_damage' => 'Shoulder Damage',
                                                'marking_fade' => 'Faded Road Markings'
                                            ];
                                            
                                            if (isset($specific_types[$report_type])) {
                                                echo $specific_types[$report_type];
                                            } else {
                                                echo ucfirst($report_type);
                                            }
                                            ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($report['department'] . ' Dept'); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo getTimeAgo($report['created_at']); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php 
                                            if (!empty($report['latitude']) && !empty($report['longitude'])) {
                                                echo '<a href="https://www.google.com/maps?q=' . htmlspecialchars($report['latitude']) . ',' . htmlspecialchars($report['longitude']) . '" target="_blank" style="color: #3762c8; text-decoration: none;">';
                                                echo htmlspecialchars($report['location'] ?? 'View on Map');
                                                echo ' <i class="fas fa-external-link-alt" style="font-size: 10px;"></i></a>';
                                            } else {
                                                echo htmlspecialchars($report['location'] ?? 'Not specified');
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="verification-description">
                                        <?php 
                                        $desc = $report['description'] ?? '';
                                        echo htmlspecialchars(strlen($desc) > 150 ? substr($desc, 0, 150) . '...' : $desc); 
                                        ?>
                                    </div>
                                    <?php
                                    // Display attached images if any
                                    if (!empty($report['attachments'])) {
                                        $attachments = json_decode($report['attachments'], true);
                                        if (is_array($attachments) && !empty($attachments)) {
                                            foreach ($attachments as $attachment) {
                                                if (isset($attachment['type']) && $attachment['type'] === 'image' && isset($attachment['file_path'])) {
                                                    // Path from pages/ directory: ../../ goes to project root
                                                    echo '<div style="margin-top: 12px;">';
                                                    echo '<img src="../../' . htmlspecialchars($attachment['file_path']) . '" alt="Report Image" style="max-width: 100%; max-height: 200px; border-radius: 8px; border: 1px solid rgba(55, 98, 200, 0.3); cursor: pointer;" onclick="window.open(this.src, \'_blank\')" title="Click to view full size" />';
                                                    echo '</div>';
                                                    break; // Show first image only
                                                }
                                            }
                                        }
                                    }
                                    ?>
                                    <!-- Expandable Details Section -->
                                    <div class="expanded-details" id="details-<?php echo $report['id']; ?>" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 2px solid rgba(55, 98, 200, 0.1);">
                                        <div class="detail-grid">
                                            <div class="detail-item">
                                                <strong>Report ID:</strong> <?php echo htmlspecialchars($report['report_id'] ?? 'N/A'); ?>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Type:</strong> 
                                                <?php 
                                                $report_type = $report['report_type'] ?? '';
                                                $specific_types = [
                                                    'traffic_jam' => 'Traffic Jam',
                                                    'accident' => 'Vehicle Accident',
                                                    'road_closure' => 'Road Closure',
                                                    'traffic_light_outage' => 'Traffic Light Outage',
                                                    'congestion' => 'Heavy Congestion',
                                                    'parking_violation' => 'Illegal Parking',
                                                    'public_transport_issue' => 'Public Transport Issue',
                                                    'potholes' => 'Potholes',
                                                    'road_damage' => 'Road Damage',
                                                    'cracks' => 'Road Cracks',
                                                    'erosion' => 'Road Erosion',
                                                    'flooding' => 'Street Flooding',
                                                    'debris' => 'Road Debris',
                                                    'shoulder_damage' => 'Shoulder Damage',
                                                    'marking_fade' => 'Faded Road Markings'
                                                ];
                                                
                                                if (isset($specific_types[$report_type])) {
                                                    echo $specific_types[$report_type];
                                                } else {
                                                    echo ucfirst($report_type);
                                                }
                                                ?>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Priority:</strong> <span class="workflow-badge priority-<?php echo htmlspecialchars($report['priority'] ?? 'medium'); ?>"><?php echo htmlspecialchars($report['priority'] ?? 'medium'); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Status:</strong> 
                                                <?php if ($pending_ext_verify): ?>
                                                <span class="workflow-badge" style="background:#fef3c7;color:#92400e;">Awaiting External Verification</span>
                                                <?php else: ?>
                                                <span class="workflow-badge <?php echo $report['status'] === 'approved' ? 'approved' : ($report['status'] === 'cancelled' ? 'rejected' : ($report['status'] === 'completed' ? 'completed' : 'pending')); ?>"><?php echo htmlspecialchars($report['status'] ?? 'N/A'); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="detail-item full-width">
                                                <strong>Full Description:</strong>
                                                <div style="margin-top: 8px; padding: 12px; background: rgba(55, 98, 200, 0.05); border-radius: 8px;">
                                                    <?php echo nl2br(htmlspecialchars($report['description'] ?? 'No description provided')); ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($report['latitude']) && !empty($report['longitude'])): ?>
                                            <div class="detail-item full-width">
                                                <strong>Location Coordinates:</strong>
                                                <div style="margin-top: 8px;">
                                                    Latitude: <?php echo htmlspecialchars($report['latitude']); ?>, 
                                                    Longitude: <?php echo htmlspecialchars($report['longitude']); ?>
                                                    <a href="https://www.google.com/maps?q=<?php echo htmlspecialchars($report['latitude']); ?>,<?php echo htmlspecialchars($report['longitude']); ?>" target="_blank" style="color: #3762c8; margin-left: 10px;">
                                                        <i class="fas fa-map-marker-alt"></i> View on Map
                                                    </a>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($report['attachments'])): 
                                                $attachments = json_decode($report['attachments'], true);
                                                if (is_array($attachments) && !empty($attachments)): ?>
                                            <div class="detail-item full-width">
                                                <strong>Attached Images:</strong>
                                                <div style="margin-top: 12px; display: flex; gap: 15px; flex-wrap: wrap;">
                                                    <?php foreach ($attachments as $attachment): 
                                                        if (isset($attachment['type']) && $attachment['type'] === 'image' && isset($attachment['file_path'])): ?>
                                                        <img src="../../<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                                                             alt="Report Image" 
                                                             style="max-width: 300px; max-height: 300px; border-radius: 8px; border: 1px solid rgba(55, 98, 200, 0.3); cursor: pointer;" 
                                                             onclick="window.open(this.src, '_blank')" 
                                                             title="Click to view full size" />
                                                    <?php endif; endforeach; ?>
                                                </div>
                                            </div>
                                            <?php endif; endif; ?>
                                            <div class="detail-item">
                                                <strong>Created:</strong> <?php echo htmlspecialchars($report['created_at'] ?? 'N/A'); ?>
                                            </div>
                                            <?php if (!empty($report['updated_at']) && $report['updated_at'] !== $report['created_at']): ?>
                                            <div class="detail-item">
                                                <strong>Last Updated:</strong> <?php echo htmlspecialchars($report['updated_at']); ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($report['approved_at'])): ?>
                                            <div class="detail-item">
                                                <strong>Approved At:</strong> <?php echo htmlspecialchars($report['approved_at']); ?>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($report['rejected_at'])): ?>
                                            <div class="detail-item">
                                                <strong>Rejected At:</strong> <?php echo htmlspecialchars($report['rejected_at']); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="verification-actions">
                                        <?php if ($pending_ext_verify): ?>
                                            <button type="button" onclick="toggleDetails(<?php echo $report['id']; ?>)" class="btn-review">
                                                <i class="fas fa-eye" id="icon-<?php echo $report['id']; ?>"></i>
                                                <span id="text-<?php echo $report['id']; ?>">View Details</span>
                                            </button>
                                            <span class="workflow-badge" style="background:#fef3c7;color:#92400e;font-size:12px;padding:4px 14px;border-radius:20px;display:inline-flex;align-items:center;gap:6px;">
                                                <i class="fas fa-external-link-alt" style="font-size:11px;"></i> Awaiting External Verification
                                            </span>
                                            <span style="font-size:11px;color:#6b7280;max-width:200px;line-height:1.3;">
                                                This road report was created by your LGU and must be verified by the Engineering Office.
                                            </span>
                                        <?php elseif ($report['status'] === 'pending'): ?>
                                            <button type="button" onclick="toggleDetails(<?php echo $report['id']; ?>)" class="btn-review">
                                                <i class="fas fa-eye" id="icon-<?php echo $report['id']; ?>"></i>
                                                <span id="text-<?php echo $report['id']; ?>">View Details</span>
                                            </button>
                                            <form method="POST" style="display: inline-flex;">
                                                <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                <input type="hidden" name="source" value="<?php echo htmlspecialchars($report['source']); ?>">
                                                <button type="submit" name="action" value="approve" class="btn-verify">
                                                    <i class="fas fa-check"></i>
                                                    Approve
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline-flex;">
                                                <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                <input type="hidden" name="source" value="<?php echo htmlspecialchars($report['source']); ?>">
                                                <button type="submit" name="action" value="reject" class="btn-reject" onclick="return confirm('Are you sure you want to reject this report?')">
                                                    <i class="fas fa-times"></i>
                                                    Reject
                                                </button>
                                            </form>
                                        <?php elseif ($report['status'] === 'approved'): ?>
                                            <span class="workflow-badge approved" style="margin-right: 10px;">Approved</span>
                                            <?php if (!empty($report['approved_at'])): ?>
                                            <span style="font-size: 12px; color: #6b7280; margin-right: 10px;"><i class="fas fa-clock"></i> <?php echo date('M d, Y g:i A', strtotime($report['approved_at'])); ?></span>
                                            <?php endif; ?>
                                            <button type="button" onclick="toggleDetails(<?php echo $report['id']; ?>)" class="btn-review">
                                                <i class="fas fa-eye" id="icon-<?php echo $report['id']; ?>"></i>
                                                <span id="text-<?php echo $report['id']; ?>">View Details</span>
                                            </button>
                                        <?php elseif ($report['status'] === 'completed'): ?>
                                            <span class="workflow-badge" style="margin-right: 10px; background: #10b981; color: white;">Completed</span>
                                            <?php if (!empty($report['approved_at'])): ?>
                                            <span style="font-size: 12px; color: #6b7280; margin-right: 10px;"><i class="fas fa-clock"></i> Approved: <?php echo date('M d, Y g:i A', strtotime($report['approved_at'])); ?></span>
                                            <?php endif; ?>
                                            <button type="button" onclick="toggleDetails(<?php echo $report['id']; ?>)" class="btn-review">
                                                <i class="fas fa-eye" id="icon-<?php echo $report['id']; ?>"></i>
                                                <span id="text-<?php echo $report['id']; ?>">View Details</span>
                                            </button>
                                        <?php elseif ($report['status'] === 'cancelled'): ?>
                                            <span class="workflow-badge rejected" style="margin-right: 10px;">Rejected</span>
                                            <?php if (!empty($report['rejected_at'])): ?>
                                            <span style="font-size: 12px; color: #6b7280; margin-right: 10px;"><i class="fas fa-clock"></i> <?php echo date('M d, Y g:i A', strtotime($report['rejected_at'])); ?></span>
                                            <?php endif; ?>
                                            <button type="button" onclick="toggleDetails(<?php echo $report['id']; ?>)" class="btn-review">
                                                <i class="fas fa-eye" id="icon-<?php echo $report['id']; ?>"></i>
                                                <span id="text-<?php echo $report['id']; ?>">View Details</span>
                                            </button>
                                        <?php else: ?>
                                            <span class="workflow-badge" style="margin-right: 10px;"><?php echo ucfirst($report['status']); ?></span>
                                            <button type="button" onclick="toggleDetails(<?php echo $report['id']; ?>)" class="btn-review">
                                                <i class="fas fa-eye" id="icon-<?php echo $report['id']; ?>"></i>
                                                <span id="text-<?php echo $report['id']; ?>">View Details</span>
                                            </button>
                                        <?php endif; ?>
                                        <form method="POST" style="display: inline-flex; margin-left: auto;" onsubmit="return confirm('Are you sure you want to remove this report? It will be moved to the archive.');">
                                            <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                            <input type="hidden" name="source" value="<?php echo htmlspecialchars($report['source']); ?>">
                                            <button type="submit" name="action" value="delete" class="btn-remove" title="Remove report">
                                                <i class="fas fa-trash-alt"></i> Remove
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #666;">
                            <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 20px; opacity: 0.5;"></i>
                            <p>No pending verifications at this time.</p>
                        </div>
                    <?php endif; ?>
                </div>


                </div>

            </div>
        </div>

        <!-- CIMM Reports Panel -->
        <div class="dept-reports-panel" id="cimmReportsPanel">
            <div class="dept-reports-header">
                <div class="dept-reports-header-left">
                    <div class="dept-reports-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div>
                        <div class="dept-reports-title-group">
                            <h2 class="dept-reports-title">CIMM Reports</h2>
                            <span class="dept-reports-badge in-progress"><?php echo count($cimm_reports) + ($sql_reports ? $sql_reports->num_rows : 0); ?> Reports</span>
                        </div>
                        <p class="dept-reports-subtitle">Department-submitted infrastructure Projects from CIMM</p>
                    </div>
                </div>
            </div>

            <div class="dept-reports-search">
                <div class="dept-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="dept-search-input" id="deptSearchInput" placeholder="Search by Rep #, Infrastructure, Location, Engineer, Priority...">
                </div>
                <button class="dept-sort-btn" onclick="toggleDeptSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="dept-table-wrapper">
                <table class="dept-table" id="deptTable">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Rep #</th>
                            <th>Infrastructure</th>
                            <th>Location</th>
                            <th>Issue / Notes</th>
                            <th>Engineer</th>
                            <th>Reported By</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Priority</th>
                            <th>Budget</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Dept panel reuses the same filtered $cimm_reports array (no data_seek needed for plain arrays)
                        $hasAnyReports = false;
                        if (!empty($cimm_reports)): 
                        ?>
                        <?php foreach ($cimm_reports as $row): 
                            $hasAnyReports = true;
                            // Map CIMM status to filter categories
                            $cimm_filter_status = 'pending';
                            if (in_array($row['status'], ['completed'])) $cimm_filter_status = 'approved';
                            elseif (in_array($row['status'], ['resolved'])) $cimm_filter_status = 'rejected';
                        ?>
                        <tr data-status="<?php echo $cimm_filter_status; ?>">
                            <td>
                                <div class="dept-action-group">
                                    <button class="dept-action-btn" onclick="viewCimmReport(<?php echo $row['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($row['status'] === 'pending'): ?>
                                    <form method="POST" class="dept-action-form" onsubmit="return confirm('Are you sure you want to verify this CIMM report?');">
                                        <input type="hidden" name="cimm_req_id" value="<?php echo (int)$row['cimm_req_id']; ?>">
                                        <button type="submit" name="action" value="verify_cimm" class="dept-verify-btn" title="Verify report">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="dept-action-form" onsubmit="return confirm('Are you sure you want to reject this CIMM report?');">
                                        <input type="hidden" name="cimm_req_id" value="<?php echo (int)$row['cimm_req_id']; ?>">
                                        <input type="hidden" name="rejection_reason" value="Rejected by admin">
                                        <button type="submit" name="action" value="reject_cimm" class="dept-reject-btn" title="Reject report">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($row['rep_number']); ?></td>
                            <td><?php echo htmlspecialchars($row['infrastructure']); ?></td>
                            <td><?php echo htmlspecialchars($row['location']); ?></td>
                            <td><?php echo htmlspecialchars(strlen($row['issue_notes'] ?? '') > 40 ? substr($row['issue_notes'], 0, 40) . '...' : ($row['issue_notes'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($row['engineer']); ?></td>
                            <td><?php echo htmlspecialchars($row['reported_by']); ?></td>
                            <td><?php echo $row['start_date'] ? date('M d, Y', strtotime($row['start_date'])) : '—'; ?></td>
                            <td><?php echo $row['end_date'] ? date('M d, Y', strtotime($row['end_date'])) : '—'; ?></td>
                            <td><span class="dept-status-badge <?php echo htmlspecialchars($row['priority']); ?>"><?php echo ucfirst(htmlspecialchars($row['priority'])); ?></span></td>
                            <td><?php echo $row['budget'] ? '₱' . number_format($row['budget'], 2) : '—'; ?></td>
                            <td><span class="dept-status-badge <?php echo htmlspecialchars($row['status']); ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', $row['status']))); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>

                        <?php 
                        // Display reports from reports.sql table
                        if ($sql_reports && $sql_reports->num_rows > 0):
                            while ($row = $sql_reports->fetch_assoc()):
                                $hasAnyReports = true;
                                $status = 'pending';
                                if ($row['engineer_accepted'] == 1) {
                                    $status = 'completed';
                                } elseif (!empty($row['decline_reason'])) {
                                    $status = 'cancelled';
                                } elseif (!empty($row['decline_reviewed'])) {
                                    $status = $row['decline_reviewed'] == 1 ? 'in-progress' : 'cancelled';
                                }
                                // Map SQL report status to filter categories
                                $sql_filter_status = 'pending';
                                if (in_array($status, ['completed'])) $sql_filter_status = 'approved';
                                elseif (in_array($status, ['cancelled'])) $sql_filter_status = 'rejected';
                        ?>
                        <tr data-status="<?php echo $sql_filter_status; ?>">
                            <td>
                                <button class="dept-action-btn" onclick="viewSqlReport(<?php echo $row['rep_id']; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </td>
                            <td>REP-<?php echo $row['rep_id']; ?></td>
                            <td><?php echo htmlspecialchars($row['res_id']); ?></td>
                            <td>—</td>
                            <td><?php echo htmlspecialchars(strlen($row['decline_reason'] ?? '') > 40 ? substr($row['decline_reason'], 0, 40) . '...' : ($row['decline_reason'] ?? '—')); ?></td>
                            <td><?php echo $row['engineer_id'] ? 'Engineer #' . htmlspecialchars($row['engineer_id']) : '—'; ?></td>
                            <td><?php echo htmlspecialchars($row['reporter_name'] ?? 'User #' . $row['report_by']); ?></td>
                            <td><?php echo $row['starting_date'] ? date('M d, Y', strtotime($row['starting_date'])) : '—'; ?></td>
                            <td><?php echo $row['estimated_end_date'] ? date('M d, Y', strtotime($row['estimated_end_date'])) : '—'; ?></td>
                            <td><span class="dept-status-badge <?php echo strtolower(htmlspecialchars($row['priority_lvl'])); ?>"><?php echo ucfirst(htmlspecialchars($row['priority_lvl'])); ?></span></td>
                            <td><?php echo $row['budget'] ? '₱' . number_format($row['budget'], 2) : '—'; ?></td>
                            <td><span class="dept-status-badge <?php echo $status; ?>"><?php echo ucfirst(htmlspecialchars($status)); ?></span></td>
                        </tr>
                        <?php 
                            endwhile;
                        endif;
                        ?>

                        <?php if (!$hasAnyReports): ?>
                        <tr>
                            <td colspan="12">
                                <div class="dept-empty-state">
                                    <div class="dept-empty-icon">
                                        <i class="fas fa-sync-alt"></i>
                                    </div>
                                    <p>No department reports at this time.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Infrastructure Reports Panel -->
        <div class="infra-reports-panel" id="infraReportsPanel">
            <div class="infra-reports-header">
                <div class="infra-reports-header-left">
                    <div class="infra-reports-icon">
                        <i class="fas fa-hard-hat"></i>
                    </div>
                    <div>
                        <div class="infra-reports-title-group">
                            <h2 class="infra-reports-title">Infrastructure Projects</h2>
                            <span class="infra-reports-badge in-progress"><?php echo $infra_reports ? $infra_reports->num_rows : 0; ?> Reports</span>
                        </div>
                        <p class="infra-reports-subtitle">Infrastructure maintenance and infrastructure issue reports</p>
                    </div>
                </div>
            </div>

            <div class="infra-reports-search">
                <div class="infra-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="infra-search-input" id="infraSearchInput" placeholder="Search by Report #, Title, Type, Location, Department...">
                </div>
                <button class="infra-sort-btn" onclick="toggleInfraSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="infra-table-wrapper">
                <table class="infra-table" id="infraTable">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Report #</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Department</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Created Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $hasInfraReports = false;
                        if ($infra_reports && $infra_reports->num_rows > 0):
                            while ($irow = $infra_reports->fetch_assoc()):
                                $hasInfraReports = true;
                                $istatus_class = '';
                                if ($irow['status'] === 'approved') $istatus_class = 'approved';
                                elseif ($irow['status'] === 'cancelled') $istatus_class = 'cancelled';
                                elseif ($irow['status'] === 'pending') $istatus_class = 'pending';
                                elseif ($irow['status'] === 'in-progress') $istatus_class = 'in-progress';
                                elseif ($irow['status'] === 'completed') $istatus_class = 'completed';
                                // Map infra status to filter categories
                                $infra_filter_status = 'pending';
                                if (in_array($irow['status'], ['approved', 'completed'])) $infra_filter_status = 'approved';
                                elseif (in_array($irow['status'], ['cancelled'])) $infra_filter_status = 'rejected';
                        ?>
                        <tr data-status="<?php echo $infra_filter_status; ?>">
                            <td>
                                <div class="infra-action-group">
                                    <button class="infra-action-btn" onclick="viewInfraReport(<?php echo $irow['id']; ?>, '<?php echo htmlspecialchars($irow['source'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($irow['status'] === 'pending'): ?>
                                    <form method="POST" class="infra-action-form" onsubmit="return confirm('Are you sure you want to verify this infrastructure report?');">
                                        <input type="hidden" name="report_id" value="<?php echo (int)$irow['id']; ?>">
                                        <input type="hidden" name="source" value="<?php echo htmlspecialchars($irow['source'], ENT_QUOTES); ?>">
                                        <button type="submit" name="action" value="approve" class="infra-verify-btn" title="Verify report">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="infra-action-form" onsubmit="return confirm('Are you sure you want to reject this infrastructure report?');">
                                        <input type="hidden" name="report_id" value="<?php echo (int)$irow['id']; ?>">
                                        <input type="hidden" name="source" value="<?php echo htmlspecialchars($irow['source'], ENT_QUOTES); ?>">
                                        <button type="submit" name="action" value="reject" class="infra-reject-btn" title="Reject report">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($irow['report_id']); ?></td>
                            <td><?php echo htmlspecialchars(strlen($irow['title'] ?? '') > 35 ? substr($irow['title'], 0, 35) . '...' : ($irow['title'] ?? '')); ?></td>
                            <td><?php
                                $type_labels = [
                                    'infrastructure_issue' => 'Infrastructure Issue',
                                    'routine' => 'Routine Maintenance',
                                    'emergency' => 'Emergency Repair',
                                    'preventive' => 'Preventive Maintenance',
                                    'corrective' => 'Corrective Maintenance',
                                    'scheduled' => 'Scheduled Maintenance'
                                ];
                                echo htmlspecialchars($type_labels[$irow['report_type']] ?? ucfirst($irow['report_type']));
                            ?></td>
                            <td><?php echo htmlspecialchars($irow['location'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(ucfirst($irow['department'])); ?></td>
                            <td><span class="infra-status-badge <?php echo htmlspecialchars($irow['priority']); ?>"><?php echo ucfirst(htmlspecialchars($irow['priority'])); ?></span></td>
                            <td><span class="infra-status-badge <?php echo $istatus_class; ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', $irow['status']))); ?></span></td>
                            <td><?php echo $irow['created_at'] ? date('M d, Y', strtotime($irow['created_at'])) : '—'; ?></td>
                        </tr>
                        <?php
                            endwhile;
                        endif;
                        ?>

                        <?php if (!$hasInfraReports): ?>
                        <tr>
                            <td colspan="9">
                                <div class="infra-empty-state">
                                    <div class="infra-empty-icon">
                                        <i class="fas fa-hard-hat"></i>
                                    </div>
                                    <p>No infrastructure projects at this time.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Citizen Reports Panel -->
        <div class="citizen-reports-panel" id="citizenPanel">
            <div class="citizen-reports-header">
                <div class="citizen-reports-header-left">
                    <div class="citizen-reports-icon">
                        <i class="fas fa-users"></i>
                    </div>
                    <div>
                        <div class="citizen-reports-title-group">
                            <h2 class="citizen-reports-title">Citizen Reports</h2>
                            <span class="citizen-reports-badge"><?php echo $citizen_reports ? $citizen_reports->num_rows : 0; ?> Reports</span>
                        </div>
                        <p class="citizen-reports-subtitle">Reports submitted by citizens via the public portal</p>
                    </div>
                </div>
            </div>

            <div class="citizen-reports-search">
                <div class="citizen-search-wrapper">
                    <i class="fas fa-search"></i>
                    <input type="text" class="citizen-search-input" id="citizenSearchInput" placeholder="Search by Report #, Title, Type, Location, Reporter...">
                </div>
                <button class="citizen-sort-btn" onclick="toggleCitizenSort()">
                    <i class="fas fa-sort"></i> Sort
                </button>
            </div>

            <div class="citizen-table-wrapper">
                <table class="citizen-table" id="citizenTable">
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Report #</th>
                            <th>Title</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Reporter</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $hasCitizenReports = false;
                        if ($citizen_reports && $citizen_reports->num_rows > 0):
                            while ($crow = $citizen_reports->fetch_assoc()):
                                $hasCitizenReports = true;
                                $c_status_class = '';
                                if ($crow['status'] === 'approved') $c_status_class = 'approved';
                                elseif ($crow['status'] === 'cancelled') $c_status_class = 'cancelled';
                                elseif ($crow['status'] === 'pending') $c_status_class = 'pending';
                                elseif ($crow['status'] === 'in-progress') $c_status_class = 'in-progress';
                                elseif ($crow['status'] === 'completed') $c_status_class = 'completed';
                                $citizen_filter_status = 'pending';
                                if (in_array($crow['status'], ['approved', 'completed'])) $citizen_filter_status = 'approved';
                                elseif (in_array($crow['status'], ['cancelled'])) $citizen_filter_status = 'rejected';
                        ?>
                        <tr data-status="<?php echo $citizen_filter_status; ?>">
                            <td>
                                <div class="citizen-action-group">
                                    <button class="citizen-action-btn" onclick="viewCitizenReport(<?php echo $crow['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($crow['status'] === 'pending'): ?>
                                    <form method="POST" class="citizen-action-form" onsubmit="return confirm('Are you sure you want to approve this citizen report?');">
                                        <input type="hidden" name="report_id" value="<?php echo (int)$crow['id']; ?>">
                                        <input type="hidden" name="source" value="transport">
                                        <button type="submit" name="action" value="approve" class="citizen-verify-btn" title="Approve report">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="citizen-action-form" onsubmit="return confirm('Are you sure you want to reject this citizen report?');">
                                        <input type="hidden" name="report_id" value="<?php echo (int)$crow['id']; ?>">
                                        <input type="hidden" name="source" value="transport">
                                        <button type="submit" name="action" value="reject" class="citizen-reject-btn" title="Reject report">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($crow['report_id']); ?></td>
                            <td><?php echo htmlspecialchars(strlen($crow['title'] ?? '') > 35 ? substr($crow['title'], 0, 35) . '...' : ($crow['title'] ?? '')); ?></td>
                            <td><?php
                                $c_type_labels = [
                                    'traffic_jam' => 'Traffic Jam',
                                    'accident' => 'Accident',
                                    'road_closure' => 'Road Closure',
                                    'traffic_light_outage' => 'Traffic Light',
                                    'congestion' => 'Congestion',
                                    'parking_violation' => 'Parking Violation',
                                    'public_transport_issue' => 'Public Transport',
                                ];
                                echo htmlspecialchars($c_type_labels[$crow['report_type']] ?? ucfirst($crow['report_type']));
                            ?></td>
                            <td><?php echo htmlspecialchars($crow['location'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($crow['reporter_name'] ?? '—'); ?></td>
                            <td><span class="citizen-status-badge <?php echo htmlspecialchars($crow['priority']); ?>"><?php echo ucfirst(htmlspecialchars($crow['priority'])); ?></span></td>
                            <td><span class="citizen-status-badge <?php echo $c_status_class; ?>"><?php echo ucfirst(htmlspecialchars(str_replace('-', ' ', $crow['status']))); ?></span></td>
                            <td><?php echo $crow['created_at'] ? date('M d, Y', strtotime($crow['created_at'])) : '—'; ?></td>
                        </tr>
                        <?php
                            endwhile;
                        endif;
                        ?>
                        <?php if (!$hasCitizenReports): ?>
                        <tr>
                            <td colspan="9">
                                <div class="citizen-empty-state">
                                    <div class="citizen-empty-icon"><i class="fas fa-users"></i></div>
                                    <p>No citizen reports at this time.</p>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        </div>
    </div>



    <script>
        console.log('Script started executing');
        // Filter functionality
        function filterReports() {
            const status = document.getElementById('statusFilter').value;
            const source = document.getElementById('sourceFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            url.searchParams.set('source', source);
            window.location.href = url.toString();
        }

        function resetFilters() {
            const url = new URL(window.location);
            url.searchParams.delete('status');
            url.searchParams.delete('source');
            window.location.href = url.toString();
        }

        // Apply source filter to show/hide panels on page load
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var source = urlParams.get('source') || 'all';
            var citizenPanel = document.getElementById('citizenReportsPanel');
            var cimmPanel = document.getElementById('cimmReportsPanel');
            var infraPanel = document.getElementById('infraReportsPanel');

            if (source === 'cimm') {
                if (citizenPanel) citizenPanel.style.display = 'none';
                if (cimmPanel) cimmPanel.style.display = '';
                if (infraPanel) infraPanel.style.display = 'none';
            } else if (source === 'maintenance') {
                if (citizenPanel) citizenPanel.style.display = 'none';
                if (cimmPanel) cimmPanel.style.display = 'none';
                if (infraPanel) infraPanel.style.display = '';
            } else if (source === 'transport') {
                if (citizenPanel) citizenPanel.style.display = '';
                if (cimmPanel) cimmPanel.style.display = 'none';
                if (infraPanel) infraPanel.style.display = 'none';
            } else {
                // 'all' or unset — show everything
                if (citizenPanel) citizenPanel.style.display = '';
                if (cimmPanel) cimmPanel.style.display = '';
                if (infraPanel) infraPanel.style.display = '';
            }
        })();

        // Apply status filter to hide/show rows in CIMM and Infra panels on page load
        (function() {
            var urlParams = new URLSearchParams(window.location.search);
            var statusFilter = urlParams.get('status') || 'all';
            if (statusFilter === 'all') return;

            // Filter CIMM panel rows
            var cimmTable = document.getElementById('deptTable');
            if (cimmTable) {
                cimmTable.querySelectorAll('tbody tr[data-status]').forEach(function(row) {
                    row.style.display = (row.getAttribute('data-status') === statusFilter) ? '' : 'none';
                });
            }

            // Filter Infra panel rows
            var infraTable = document.getElementById('infraTable');
            if (infraTable) {
                infraTable.querySelectorAll('tbody tr[data-status]').forEach(function(row) {
                    row.style.display = (row.getAttribute('data-status') === statusFilter) ? '' : 'none';
                });
            }
        })();



        // Toggle expanded details inline
        function toggleDetails(reportId) {
            const detailsDiv = document.getElementById('details-' + reportId);
            const icon = document.getElementById('icon-' + reportId);
            const text = document.getElementById('text-' + reportId);
            
            if (detailsDiv.style.display === 'none') {
                detailsDiv.style.display = 'block';
                icon.className = 'fas fa-eye-slash';
                text.textContent = 'Hide Details';
            } else {
                detailsDiv.style.display = 'none';
                icon.className = 'fas fa-eye';
                text.textContent = 'View Details';
            }
        }

        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'notification ' + type;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Handle form submissions with confirmation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const action = this.querySelector('button[type="submit"]');
                if (action && action.value === 'reject') {
                    if (!confirm('Are you sure you want to reject this report?')) {
                        e.preventDefault();
                    }
                }
            });
        });
        
        // Show success message if available
        <?php if (isset($success_message)): ?>
        showNotification('<?php echo htmlspecialchars($success_message); ?>', 'success');
        <?php endif; ?>

        // CIMM Reports panel is now always visible (no tab filtering)

        // CIMM search functionality
        document.getElementById('cimmSearchInput')?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const table = document.getElementById('cimmTable');
            if (!table) return;
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // CIMM sort functionality
        let cimmSortAsc = true;
        function toggleCimmSort() {
            const table = document.getElementById('cimmTable');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            cimmSortAsc = !cimmSortAsc;
            rows.sort((a, b) => {
                const aText = a.cells[1]?.textContent.trim() || '';
                const bText = b.cells[1]?.textContent.trim() || '';
                return cimmSortAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        // CIMM & SQL report data maps (populated from PHP)
        var cimmDataMap = {};
        try {
            console.log('Initializing cimmDataMap...');
            var cimmDataRaw = JSON.parse('<?php echo addslashes(json_encode(array_column($cimm_reports, null, 'id'))); ?>');
            if (typeof cimmDataRaw === 'object' && cimmDataRaw !== null) {
                cimmDataMap = cimmDataRaw;
            }
            console.log('cimmDataMap initialized successfully');
        } catch(e) {
            console.error('Error initializing cimmDataMap:', e);
            cimmDataMap = {};
        }
        var sqlDataMap = {};
        <?php
        if ($sql_reports && method_exists($sql_reports, 'data_seek')):
            $sql_reports->data_seek(0);
            if ($sql_reports->num_rows > 0):
                while ($sr = $sql_reports->fetch_assoc()):
        ?>
        (function() {
            try {
                sqlDataMap[<?php echo (int)$sr['rep_id']; ?>] = {
                    rep_id: <?php echo (int)$sr['rep_id']; ?>,
                    res_id: <?php echo (int)$sr['res_id']; ?>,
                    starting_date: <?php echo json_encode($sr['starting_date']); ?>,
                    estimated_end_date: <?php echo json_encode($sr['estimated_end_date']); ?>,
                    engineer_id: <?php echo json_encode($sr['engineer_id']); ?>,
                    report_by: <?php echo (int)$sr['report_by']; ?>,
                    priority_lvl: <?php echo json_encode($sr['priority_lvl']); ?>,
                    budget: <?php echo json_encode($sr['budget']); ?>,
                    created_at: <?php echo json_encode($sr['created_at']); ?>,
                    engineer_accepted: <?php echo (int)$sr['engineer_accepted']; ?>,
                    decline_reason: <?php echo json_encode($sr['decline_reason']); ?>,
                    decline_reviewed: <?php echo json_encode($sr['decline_reviewed']); ?>,
                    decline_review_note: <?php echo json_encode($sr['decline_review_note']); ?>,
                    reporter_name: <?php echo json_encode($sr['reporter_name'] ?? 'User #' . $sr['report_by']); ?>
                };
            } catch(e) {
                console.error('Error adding SQL report to map:', e);
            }
        })();
        <?php
                endwhile;
            endif;
        endif;
        ?>

        function setModalField(id, value) {
            var el = document.getElementById(id);
            if (el) el.textContent = value || '—';
        }

        function openCimmDetailModal() {
            var modal = document.getElementById('cimmDetailModal');
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeCimmDetailModal() {
            var modal = document.getElementById('cimmDetailModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        function statusBadgeHtml(status, label) {
            var colors = {
                'pending':        'background:rgba(251,191,36,0.15);color:#f59e0b;',
                'in-progress':    'background:rgba(59,130,246,0.15);color:#3b82f6;',
                'completed':      'background:rgba(34,197,94,0.15);color:#22c55e;',
                'resolved':       'background:rgba(34,197,94,0.15);color:#22c55e;',
                'approved':       'background:rgba(34,197,94,0.15);color:#22c55e;',
                'cancelled':      'background:rgba(220,53,69,0.15);color:#ef4444;'
            };
            var c = colors[status] || '';
            return '<span style="display:inline-block;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;text-transform:capitalize;' + c + '">' + (label || status || '—') + '</span>';
        }

        function priorityBadgeHtml(priority) {
            var colors = {
                'high':   'background:rgba(220,53,69,0.15);color:#ef4444;',
                'medium': 'background:rgba(251,191,36,0.15);color:#f59e0b;',
                'low':    'background:rgba(34,197,94,0.15);color:#22c55e;'
            };
            var p = (priority || 'medium').toLowerCase();
            var c = colors[p] || '';
            return '<span style="display:inline-block;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;text-transform:capitalize;' + c + '">' + (priority || '—') + '</span>';
        }

        function formatDate(dateStr) {
            if (!dateStr) return '—';
            var d = new Date(dateStr);
            if (isNaN(d.getTime())) return dateStr;
            var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
        }

        function formatCurrency(val) {
            if (!val || val == 0) return '—';
            return '₱' + parseFloat(val).toLocaleString('en-PH', {minimumFractionDigits:2, maximumFractionDigits:2});
        }

        // View CIMM report details
        function viewCimmReport(id) {
            var r = cimmDataMap[id];
            if (!r) { alert('Report data not found.'); return; }

            document.getElementById('dm-budget')?.closest('.detail-row')?.style.removeProperty('display');
            document.getElementById('cimmModalTitle').textContent = 'CIMM Report — ' + (r.rep_number || 'Details');
            setModalField('dm-rep-number', r.rep_number);
            setModalField('dm-infrastructure', r.infrastructure);
            setModalField('dm-location', r.location);
            setModalField('dm-issue', r.issue_notes);
            setModalField('dm-engineer', r.engineer);
            setModalField('dm-reported-by', r.reported_by);
            setModalField('dm-start-date', formatDate(r.start_date));
            setModalField('dm-end-date', formatDate(r.end_date));
            document.getElementById('dm-priority').innerHTML = priorityBadgeHtml(r.priority);
            setModalField('dm-budget', formatCurrency(r.budget));
            document.getElementById('dm-status').innerHTML = statusBadgeHtml(r.status, r.status ? r.status.replace(/-/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase();}) : '—');

            var extra = '';
            if (r.verification_status) {
                extra += '<div class="detail-row"><div class="detail-label">Verification Status</div><div class="detail-value">' + statusBadgeHtml(r.status, r.verification_status) + '</div></div>';
            }
            if (r.approval_status) {
                extra += '<div class="detail-row"><div class="detail-label">Approval Status</div><div class="detail-value">' + statusBadgeHtml(r.status, r.approval_status) + '</div></div>';
            }
            if (r.cimm_req_id) {
                extra += '<div class="detail-row"><div class="detail-label">CIMM Request ID</div><div class="detail-value">' + r.cimm_req_id + '</div></div>';
            }
            document.getElementById('dm-extra-fields').innerHTML = extra;

            openCimmDetailModal();
        }

        // View SQL reports table details
        function viewSqlReport(repId) {
            var r = sqlDataMap[repId];
            if (!r) { alert('Report data not found.'); return; }

            var status = 'pending';
            if (r.engineer_accepted == 1) status = 'completed';
            else if (r.decline_reason) status = 'cancelled';
            else if (r.decline_reviewed != null) status = r.decline_reviewed == 1 ? 'in-progress' : 'cancelled';

            document.getElementById('dm-budget')?.closest('.detail-row')?.style.removeProperty('display');
            document.getElementById('cimmModalTitle').textContent = 'Report — REP-' + r.rep_id;
            setModalField('dm-rep-number', 'REP-' + r.rep_id);
            setModalField('dm-infrastructure', 'Resource #' + r.res_id);
            setModalField('dm-location', '—');
            setModalField('dm-issue', r.decline_reason || '—');
            setModalField('dm-engineer', r.engineer_id ? 'Engineer #' + r.engineer_id : '—');
            setModalField('dm-reported-by', r.reporter_name);
            setModalField('dm-start-date', formatDate(r.starting_date));
            setModalField('dm-end-date', formatDate(r.estimated_end_date));
            document.getElementById('dm-priority').innerHTML = priorityBadgeHtml(r.priority_lvl);
            setModalField('dm-budget', formatCurrency(r.budget));
            document.getElementById('dm-status').innerHTML = statusBadgeHtml(status, status.charAt(0).toUpperCase() + status.slice(1));

            var extra = '';
            extra += '<div class="detail-row"><div class="detail-label">Created At</div><div class="detail-value">' + formatDate(r.created_at) + '</div></div>';
            extra += '<div class="detail-row"><div class="detail-label">Engineer Accepted</div><div class="detail-value">' + (r.engineer_accepted ? 'Yes' : 'No') + '</div></div>';
            if (r.decline_reviewed != null) {
                extra += '<div class="detail-row"><div class="detail-label">Decline Reviewed</div><div class="detail-value">' + (r.decline_reviewed == 1 ? 'Valid' : 'Invalid') + '</div></div>';
            }
            if (r.decline_review_note) {
                extra += '<div class="detail-row"><div class="detail-label">Decline Review Note</div><div class="detail-value">' + r.decline_review_note + '</div></div>';
            }
            document.getElementById('dm-extra-fields').innerHTML = extra;

            openCimmDetailModal();
        }

        // Dept Reports search functionality
        document.getElementById('deptSearchInput')?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const table = document.getElementById('deptTable');
            if (!table) return;
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Dept Reports sort functionality
        let deptSortAsc = true;
        function toggleDeptSort() {
            const table = document.getElementById('deptTable');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            deptSortAsc = !deptSortAsc;
            rows.sort((a, b) => {
                const aText = a.cells[1]?.textContent.trim() || '';
                const bText = b.cells[1]?.textContent.trim() || '';
                return deptSortAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        // Infra Reports data map (populated from PHP)
        var infraDataMap = {};
        <?php
        if ($infra_reports && method_exists($infra_reports, 'data_seek') && $infra_reports->num_rows > 0):
            $infra_reports->data_seek(0);
            while ($ir = $infra_reports->fetch_assoc()):
        ?>
        (function() {
            try {
                infraDataMap[<?php echo (int)$ir['id']; ?> + '_' + <?php echo json_encode($ir['source']); ?>] = {
                    id: <?php echo (int)$ir['id']; ?>,
                    source: <?php echo json_encode($ir['source']); ?>,
                    report_id: <?php echo json_encode($ir['report_id']); ?>,
                    title: <?php echo json_encode($ir['title']); ?>,
                    report_type: <?php echo json_encode($ir['report_type']); ?>,
                    department: <?php echo json_encode($ir['department']); ?>,
                    priority: <?php echo json_encode($ir['priority']); ?>,
                    status: <?php echo json_encode($ir['status']); ?>,
                    location: <?php echo json_encode($ir['location']); ?>,
                    description: <?php echo json_encode($ir['description']); ?>,
                    created_date: <?php echo json_encode($ir['created_date']); ?>,
                    created_at: <?php echo json_encode($ir['created_at']); ?>,
                    due_date: <?php echo json_encode($ir['due_date']); ?>,
                    reporter_name: <?php echo json_encode($ir['reporter_name'] ?? '—'); ?>,
                    estimated_cost: <?php echo json_encode($ir['estimated_cost'] ?? null); ?>,
                    actual_cost: <?php echo json_encode($ir['actual_cost'] ?? null); ?>,
                    maintenance_team: <?php echo json_encode($ir['maintenance_team'] ?? '—'); ?>
                };
            } catch(e) {
                console.error('Error adding infra report to map:', e);
            }
        })();
        <?php
            endwhile;
        endif;
        ?>

        // Infra Reports search functionality
        document.getElementById('infraSearchInput')?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const table = document.getElementById('infraTable');
            if (!table) return;
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Infra Reports sort functionality
        let infraSortAsc = true;
        function toggleInfraSort() {
            const table = document.getElementById('infraTable');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            infraSortAsc = !infraSortAsc;
            rows.sort((a, b) => {
                const aText = a.cells[1]?.textContent.trim() || '';
                const bText = b.cells[1]?.textContent.trim() || '';
                return infraSortAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        // View Infra report details (reuses cimmDetailModal)
        function viewInfraReport(id, source) {
            var key = id + '_' + source;
            var r = infraDataMap[key];
            if (!r) { alert('Report data not found.'); return; }

            var typeLabels = {
                'infrastructure_issue': 'Infrastructure Issue',
                'routine': 'Routine Maintenance',
                'emergency': 'Emergency Repair',
                'preventive': 'Preventive Maintenance',
                'corrective': 'Corrective Maintenance',
                'scheduled': 'Scheduled Maintenance'
            };

            document.getElementById('dm-budget')?.closest('.detail-row')?.style.removeProperty('display');
            document.getElementById('cimmModalTitle').textContent = 'Infra Report — ' + (r.report_id || 'Details');
            setModalField('dm-rep-number', r.report_id);
            setModalField('dm-infrastructure', typeLabels[r.report_type] || r.report_type);
            setModalField('dm-location', r.location);
            setModalField('dm-issue', r.description || '—');
            setModalField('dm-engineer', r.maintenance_team || '—');
            setModalField('dm-reported-by', r.reporter_name || '');
            setModalField('dm-start-date', formatDate(r.created_date));
            setModalField('dm-end-date', formatDate(r.due_date));
            document.getElementById('dm-priority').innerHTML = priorityBadgeHtml(r.priority);
            if (source === 'maintenance') {
                setModalField('dm-budget', r.estimated_cost ? formatCurrency(r.estimated_cost) + ' (est)' : '—');
            } else {
                setModalField('dm-budget', '—');
            }
            document.getElementById('dm-status').innerHTML = statusBadgeHtml(r.status, r.status ? r.status.replace(/-/g,' ').replace(/\b\w/g,function(c){return c.toUpperCase();}) : '—');

            var extra = '';
            extra += '<div class="detail-row"><div class="detail-label">Source</div><div class="detail-value">' + (source === 'transport' ? 'Road & Transportation' : 'Maintenance') + '</div></div>';
            extra += '<div class="detail-row"><div class="detail-label">Department</div><div class="detail-value">' + (r.department || '—') + '</div></div>';
            extra += '<div class="detail-row"><div class="detail-label">Created At</div><div class="detail-value">' + formatDate(r.created_at) + '</div></div>';
            if (source === 'maintenance' && r.actual_cost) {
                extra += '<div class="detail-row"><div class="detail-label">Actual Cost</div><div class="detail-value">' + formatCurrency(r.actual_cost) + '</div></div>';
            }
            document.getElementById('dm-extra-fields').innerHTML = extra;

            openCimmDetailModal();
        }

        // Citizen Reports data map
        var citizenDataMap = {};
        <?php
        if ($citizen_reports && method_exists($citizen_reports, 'data_seek') && $citizen_reports->num_rows > 0):
            $citizen_reports->data_seek(0);
            while ($cr = $citizen_reports->fetch_assoc()):
        ?>
        (function() {
            try {
                citizenDataMap[<?php echo (int)$cr['id']; ?>] = {
                    id: <?php echo (int)$cr['id']; ?>,
                    report_id: <?php echo json_encode($cr['report_id']); ?>,
                    title: <?php echo json_encode($cr['title']); ?>,
                    report_type: <?php echo json_encode($cr['report_type']); ?>,
                    report_category: <?php echo json_encode($cr['report_category']); ?>,
                    department: <?php echo json_encode($cr['department']); ?>,
                    priority: <?php echo json_encode($cr['priority']); ?>,
                    status: <?php echo json_encode($cr['status']); ?>,
                    location: <?php echo json_encode($cr['location']); ?>,
                    description: <?php echo json_encode($cr['description']); ?>,
                    created_at: <?php echo json_encode($cr['created_at']); ?>,
                    updated_at: <?php echo json_encode($cr['updated_at']); ?>,
                    approved_at: <?php echo json_encode($cr['approved_at']); ?>,
                    rejected_at: <?php echo json_encode($cr['rejected_at']); ?>,
                    reporter_name: <?php echo json_encode($cr['reporter_name'] ?? '—'); ?>,
                    reporter_email: <?php echo json_encode($cr['reporter_email'] ?? '—'); ?>,
                    reporter_phone: <?php echo json_encode($cr['reporter_phone'] ?? '—'); ?>,
                    image_path: <?php echo json_encode($cr['image_path'] ?? null); ?>
                };
            } catch(e) {
                console.error('Error adding citizen report to map:', e);
            }
        })();
        <?php
            endwhile;
            $citizen_reports->data_seek(0);
        endif;
        ?>

        // Citizen Reports search functionality
        document.getElementById('citizenSearchInput')?.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const table = document.getElementById('citizenTable');
            if (!table) return;
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Citizen Reports sort functionality
        let citizenSortAsc = true;
        function toggleCitizenSort() {
            const table = document.getElementById('citizenTable');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            citizenSortAsc = !citizenSortAsc;
            rows.sort((a, b) => {
                const aText = a.cells[2]?.textContent.trim() || '';
                const bText = b.cells[2]?.textContent.trim() || '';
                return citizenSortAsc ? aText.localeCompare(bText) : bText.localeCompare(aText);
            });
            rows.forEach(row => tbody.appendChild(row));
        }

        // View Citizen report details
        function viewCitizenReport(id) {
            var r = citizenDataMap[id];
            if (!r) { alert('Report data not found.'); return; }

            var typeLabels = {
                'pothole': 'Pothole',
                'flooding': 'Flooding',
                'road_damage': 'Road Damage',
                'accident_hotspot': 'Accident Hotspot',
                'street_light': 'Street Light',
                'illegal_dumping': 'Illegal Dumping',
                'other': 'Other'
            };

            document.getElementById('cimmModalTitle').textContent = 'Citizen Report — ' + (r.report_id || 'Details');
            setModalField('dm-rep-number', r.report_id);
            setModalField('dm-infrastructure', typeLabels[r.report_type] || r.report_type);
            setModalField('dm-location', r.location);
            setModalField('dm-issue', r.description || '—');
            setModalField('dm-engineer', '—');
            setModalField('dm-reported-by', r.reporter_name || '—');
            setModalField('dm-start-date', formatDate(r.created_at));
            setModalField('dm-end-date', '—');
            document.getElementById('dm-priority').innerHTML = priorityBadgeHtml(r.priority);

            var extra = '';
            extra += '<div class="detail-row"><div class="detail-label">Report Category</div><div class="detail-value">' + (r.report_category || '—') + '</div></div>';
            extra += '<div class="detail-row"><div class="detail-label">Department</div><div class="detail-value">' + (r.department || '—') + '</div></div>';
            extra += '<div class="detail-row"><div class="detail-label">Updated At</div><div class="detail-value">' + formatDate(r.updated_at) + '</div></div>';
            extra += '<div class="detail-row"><div class="detail-label">Reporter Email</div><div class="detail-value">' + (r.reporter_email || '—') + '</div></div>';
            extra += '<div class="detail-row"><div class="detail-label">Reporter Phone</div><div class="detail-value">' + (r.reporter_phone || '—') + '</div></div>';
            if (r.approved_at) {
                extra += '<div class="detail-row"><div class="detail-label">Approved At</div><div class="detail-value">' + formatDate(r.approved_at) + '</div></div>';
            }
            if (r.rejected_at) {
                extra += '<div class="detail-row"><div class="detail-label">Rejected At</div><div class="detail-value">' + formatDate(r.rejected_at) + '</div></div>';
            }
            if (r.image_path) {
                extra += '<div class="detail-row"><div class="detail-label">Attachment</div><div class="detail-value"><a href="' + r.image_path + '" target="_blank" class="btn btn-sm btn-outline-primary">View Image</a></div></div>';
            }
            document.getElementById('dm-budget').closest('.detail-row')?.style.setProperty('display', 'none');
            document.getElementById('dm-extra-fields').innerHTML = extra;

            openCimmDetailModal();
        }

        // Close modal after form submission in modal (if element exists)
        var modalFooterEl = document.getElementById('modalFooter');
        if (modalFooterEl) {
            modalFooterEl.addEventListener('submit', function(e) {
                const form = e.target.closest('form');
                if (form) {
                    setTimeout(function() { if (typeof closeModal === 'function') closeModal(); }, 100);
                }
            });
        }

        console.log('Script finished executing');
        console.log('toggleDetails is', typeof toggleDetails);
        console.log('viewCimmReport is', typeof viewCimmReport);
        console.log('viewInfraReport is', typeof viewInfraReport);

    </script>
    

    <!-- CIMM / SQL Report Detail Modal -->
    <div id="cimmDetailModal" class="modal-overlay" onclick="if(event.target===this)closeCimmDetailModal()">
        <div class="modal-content" style="max-width:700px;">
            <div class="modal-header">
                <h2 id="cimmModalTitle">Report Details</h2>
                <button class="modal-close" onclick="closeCimmDetailModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="cimmModalBody">
                    <div class="detail-row">
                        <div class="detail-label">Report #</div>
                        <div class="detail-value" id="dm-rep-number">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Infrastructure</div>
                        <div class="detail-value" id="dm-infrastructure">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Location</div>
                        <div class="detail-value" id="dm-location">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Issue / Notes</div>
                        <div class="detail-value" id="dm-issue">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Engineer</div>
                        <div class="detail-value" id="dm-engineer">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Reported By</div>
                        <div class="detail-value" id="dm-reported-by">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Start Date</div>
                        <div class="detail-value" id="dm-start-date">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">End Date</div>
                        <div class="detail-value" id="dm-end-date">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Priority</div>
                        <div class="detail-value" id="dm-priority">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Budget</div>
                        <div class="detail-value" id="dm-budget">—</div>
                    </div>
                    <div class="detail-row">
                        <div class="detail-label">Status</div>
                        <div class="detail-value" id="dm-status">—</div>
                    </div>
                    <div id="dm-extra-fields"></div>
                </div>
            </div>
            <div class="modal-footer" id="cimmModalFooter">
                <button type="button" class="btn-review" onclick="closeCimmDetailModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Session Timeout Modal -->
    <div id="sessionTimeoutOverlay" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.6); z-index:10000;"></div>
    <div id="sessionTimeoutModal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; border-radius:12px; padding:32px; z-index:10001; width:400px; max-width:90vw; box-shadow:0 16px 48px rgba(0,0,0,0.3); text-align:center;">
        <div style="font-size:48px; color:#e74c3c; margin-bottom:16px;">
            <i class="fas fa-clock"></i>
        </div>
        <h3 style="margin:0 0 8px; font-size:20px; color:#1a1a2e;">Session Expiring</h3>
        <p style="margin:0 0 20px; color:#666; font-size:14px;">
            Your session will expire in <strong><span id="sessionCountdown">60</span></strong> seconds due to inactivity.
        </p>
        <div style="display:flex; gap:12px; justify-content:center;">
            <button id="extendSessionBtn" style="padding:10px 24px; background:#3762c8; color:#fff; border:none; border-radius:8px; font-size:14px; cursor:pointer; font-weight:600;">Extend Session</button>
            <button id="logoutSessionBtn" style="padding:10px 24px; background:#e74c3c; color:#fff; border:none; border-radius:8px; font-size:14px; cursor:pointer; font-weight:600;">Log Out</button>
        </div>
    </div>

    <!-- Session timeout data -->
    <script id="sessionTimeoutData" data-timeout="<?php echo $session_timeout; ?>"></script>
    <script src="../../js/session-timeout.js"></script>
</body>
</html>

<?php
// Helper functions
function getReportIcon($reportType) {
    $icons = [
        'monthly' => 'calendar',
        'traffic' => 'traffic-light',
        'maintenance' => 'tools',
        'safety' => 'shield-alt',
        'budget' => 'dollar-sign',
        'road_damage' => 'road',
        'infrastructure_issue' => 'map-marker-alt',
        'traffic_violation' => 'car-crash',
        'maintenance_request' => 'wrench',
        'routine' => 'wrench',
        'emergency' => 'exclamation-triangle',
        'preventive' => 'shield-alt',
        'corrective' => 'tools',
        'scheduled' => 'calendar-alt'
    ];
    
    return $icons[$reportType] ?? 'file-alt';
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

// Determine if a report can be verified locally
// Road reports created by this LGU (local source) must go to external Engineering Office
// Transportation reports and external reports can be verified here
function canVerifyReport($category, $source) {
    if ($category === 'road' && $source === 'local') {
        return false;
    }
    return true;
}

function getActivityTitle($activity) {
    $status = $activity['status'];
    $title = $activity['title'];
    $source = ucfirst($activity['source']);
    
    switch ($status) {
        case 'completed':
            return $source . ' Report: ' . $title . ' - Completed';
        case 'approved':
            return $source . ' Report: ' . $title . ' - Approved';
        case 'cancelled':
            return $source . ' Report: ' . $title . ' - Cancelled';
        case 'pending':
            return $source . ' Report: ' . $title . ' - Pending';
        case 'in-progress':
            return $source . ' Report: ' . $title . ' - In Progress';
        default:
            return $source . ' Report: ' . $title;
    }
}
?>
<?php
header('Content-Type: application/json');

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/cimm_verification_data.php';

// Auth check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['system_admin', 'lgu_staff', 'road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Transportation Operations Supervisors and Transportation Monitoring Officers
// see only Transportation reports (report_category = 'transportation').
$transport_only = in_array($_SESSION['role'] ?? '', ['trans_ops_supervisor', 'trans_monitoring_officer'], true);

// Road Operations Supervisors and Road Monitoring Officers see only Road
// reports.
$road_only = in_array($_SESSION['role'] ?? '', ['road_ops_supervisor', 'road_monitoring_officer'], true);

// Road Operations Supervisors (Road supervisor portal) also get the Report
// Creator Information (full name, contact number, email) in report details.
$is_road_supervisor = ($_SESSION['role'] ?? '') === 'road_ops_supervisor';

// Road Monitoring Officers see only the reports assigned to them by the
// Road Operations Supervisor.
$is_road_monitoring_officer = ($_SESSION['role'] ?? '') === 'road_monitoring_officer';
$assigned_to_user_id = $is_road_monitoring_officer ? (int)($_SESSION['user_id'] ?? 0) : null;

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';

// Helper function to get recent submissions with pagination
function getRecentSubmissionsPaginated($offset, $limit, $status_filter = 'all', $type_filter = 'all', $transport_only = false, $road_only = false, $assigned_to_user_id = null) {
    global $conn;
    $reports = [];
    if (!$conn) return $reports;

    // Transportation Operations Supervisors see only Transportation reports.
    $transport_category_filter = $transport_only ? " AND report_category = 'transportation'" : '';

    // Road Operations Supervisors see only Road reports.
    $road_category_filter = $road_only ? " AND report_category = 'road'" : '';

    // Helper to append shared WHERE clauses and run a query (no pagination at query level)
    $fetch = function ($sql, $status_filter) use ($conn) {
        $params = [];
        $types = '';
        if ($status_filter !== 'all') {
            // LOWER() so CIMM rows (verification_status stores 'Completed',
            // 'Approved', ... capitalized) match the lowercase dropdown values.
            $sql .= " AND LOWER(status) = LOWER(?)";
            $params[] = $status_filter;
            $types .= 's';
        }
        $sql .= " ORDER BY created_at DESC";
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
        // 1. LGU Monitoring (Road & Transportation Monitoring) + Citizen reports
        $reports = array_merge($reports, $fetch(
            "SELECT t.id, t.report_id, t.title, t.report_type, t.report_category,
                    CASE WHEN t.created_by IS NULL OR t.created_by = 0 THEN 'citizen' ELSE 'lgu' END AS source,
                    t.status, t.priority, t.severity, t.created_at, t.description,
                    t.latitude, t.longitude, t.location, t.reporter_name, t.attachments, t.image_path,
                    t.cimm_status, t.cimm_sync_status, t.cimm_verified_at, t.cimm_verified_by,
                    t.engineer, t.budget_allocation, t.cimm_engineer_name, t.cimm_budget,
                    u.full_name AS creator_full_name, u.phone_number AS creator_phone, u.email AS creator_email,
                    'road_transportation_reports' AS _source_table
             FROM road_transportation_reports t
             LEFT JOIN users u ON u.id = t.created_by
             WHERE t.status IN ('approved', 'in-progress', 'completed')
               AND (t.created_by IS NULL OR t.created_by = 0
                    OR t.cimm_sync_status IS NULL OR t.cimm_sync_status <> 'pushed'
                    OR (t.report_category IN ('transportation', 'road') AND t.report_source = 'local' AND t.created_by != 0)){$transport_category_filter}{$road_category_filter}",
            $status_filter
        ));

        // 2. Infrastructure Projects (ipms_road_projects, locally approved).
        //    Excluded for Transportation Operations Supervisors.
        if (!$transport_only) {
            $reports = array_merge($reports, $fetch(
            "SELECT project_id AS id,
                    CAST(project_id AS CHAR) AS report_id,
                    project_name AS title,
                    COALESCE(NULLIF(road_type, ''), 'infrastructure_issue') AS report_type,
                    'infrastructure' AS source,
                    status,
                    'medium' AS priority,
                    NULL AS severity,
                    created_at,
                    road_status AS description,
                    start_lat AS latitude,
                    start_lng AS longitude,
                    COALESCE(NULLIF(road_name, ''), project_name) AS location,
                    NULL AS reporter_name,
                    NULL AS attachments,
                    NULL AS image_path,
                    NULL AS cimm_sync_status,
                    NULL AS cimm_verified_at,
                    NULL AS cimm_verified_by,
                    'ipms_road_projects' AS _source_table
             FROM ipms_road_projects
             WHERE status = 'approved'",
            $status_filter
        ));
        }

        // 3. CIMM reports (finalized = verification_status 'Verified')
        //    Status reflects CIMM's real, current resolution_status (via
        //    cimm_status_case_sql()) instead of a fixed 'completed'. The
        //    outer SELECT * wrapper turns the mapped value into a real
        //    column so the status_filter appended by fetch() (AND status
        //    = ?) can still match on it.
        if (!$transport_only) {
            try {
                $reports = array_merge($reports, $fetch(
                    "SELECT * FROM (
                        SELECT id, reference_code AS report_id, infrastructure AS title,
                                'infrastructure_issue' AS report_type, 'cimm' AS source,
                                verification_status AS status, priority, NULL AS severity,
                                COALESCE(submitted_at, verified_at, synced_at, NOW()) AS created_at,
                                issue AS description, coord_lat AS latitude, coord_lng AS longitude,
                                location, reporter_name, NULL AS attachments, NULL AS image_path,
                                'approved' AS cimm_sync_status, verified_at AS cimm_verified_at,
                                NULL AS cimm_verified_by, approval_status,
                                engineer, budget_allocation,
                                'cimm_verification_reports' AS _source_table
                         FROM cimm_verification_reports
                         WHERE verification_status IN ('Approved', 'In Progress', 'Completed')
                           AND infrastructure = 'Roads'
                     ) AS cimm_mapped WHERE 1=1",
                    $status_filter
                ));
            } catch (Exception $e) {
                error_log("Recent CIMM reports error: ".$e->getMessage());
            }
        }

        // Road Monitoring Officers see only the reports assigned to them.
        if ($assigned_to_user_id) {
            $reports = filter_reports_assigned_to_user($conn, $reports, $assigned_to_user_id);
        }

        // Filter by type after fetching (since source is a calculated field)
        if ($type_filter !== 'all') {
            $reports = array_filter($reports, function($report) use ($type_filter) {
                return ($report['source'] ?? '') === $type_filter;
            });
            $reports = array_values($reports); // Re-index array
        }

        // Sort combined results by created_at DESC and apply pagination
        usort($reports, function($a, $b) {
            return strtotime($b['created_at'] ?? 'now') - strtotime($a['created_at'] ?? 'now');
        });
        $reports = array_slice($reports, $offset, $limit);
    } catch (Exception $e) {
        error_log("Recent reports error: ".$e->getMessage());
    }
    return $reports;
}

try {
    $reports = getRecentSubmissionsPaginated($offset, $limit, $status_filter, $type_filter, $transport_only, $road_only, $assigned_to_user_id);

    // Display-only Assignment Status (Assigned / Unassigned) for each report,
    // read live from report_assignments so it reflects Assign/Unassign changes.
    annotate_report_assignment_status($conn, $reports);
    
    $source_labels = [
        'lgu' => 'LGU Monitoring',
        'citizen' => 'Citizen',
        'cimm' => 'CIMM',
        'infrastructure' => 'Infrastructure Projects',
    ];
    
    $formatted_reports = [];
    foreach ($reports as $rr) {
        $rr_source_key = $rr['source'] ?? 'citizen';
        $rr_source_label = $source_labels[$rr_source_key] ?? ucfirst($rr_source_key);
        
        $formatted_reports[] = [
            'id' => $rr['id'],
            'report_id' => $rr['report_id'] ?? '—',
            'title' => $rr['title'] ?? 'Untitled',
            'source' => $rr_source_key,
            'source_label' => $rr_source_label,
            'status' => $rr['status'] ?? 'pending',
            'assignment_status' => $rr['assignment_status'] ?? 'unassigned',
            'assignment_officer' => $rr['assignment_officer'] ?? '',
            'assigned_by' => $rr['assigned_by'] ?? '',
            'priority' => $rr['priority'] ?? 'low',
            'created_at' => $rr['created_at'],
            'cimm_sync_status' => $rr['cimm_sync_status'] ?? '',
            'cimm_status' => $rr['cimm_status'] ?? '',
            'cimm_verified_at' => $rr['cimm_verified_at'] ?? '',
            'cimm_verified_by' => $rr['cimm_verified_by'] ?? '',
            'approval_status' => $rr['approval_status'] ?? '',
            'verification_status' => $rr['verification_status'] ?? '',
            'report_category' => $rr['report_category'] ?? '',
            'report_type' => $rr['report_type'] ?? '',
            'table' => $rr['_source_table'] ?? 'road_transportation_reports',
            'details' => [
                'id' => $rr['id'],
                'report_id' => $rr['report_id'],
                'title' => $rr['title'],
                'source' => $rr_source_label,
                'report_type' => $rr['report_type'],
                'status' => $rr['status'],
                'assignment_status' => $rr['assignment_status'] ?? 'unassigned',
                'assignment_officer' => $rr['assignment_officer'] ?? '',
                'assigned_by' => $rr['assigned_by'] ?? '',
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
                'report_category' => $rr['report_category'] ?? '',
                'engineer' => $rr['engineer'] ?? ($rr['cimm_engineer_name'] ?? ''),
                'budget_allocation' => $rr['budget_allocation'] ?? ($rr['cimm_budget'] ?? ''),
                'table' => $rr['_source_table'] ?? 'road_transportation_reports',
            ] + ($is_road_supervisor ? [
                'creator_full_name' => $rr['creator_full_name'] ?? '',
                'creator_phone' => $rr['creator_phone'] ?? '',
                'creator_email' => $rr['creator_email'] ?? '',
            ] : [])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'reports' => $formatted_reports,
        'count' => count($formatted_reports),
        'offset' => $offset,
        'limit' => $limit
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching reports: ' . $e->getMessage()
    ]);
}

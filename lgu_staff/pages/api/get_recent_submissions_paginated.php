<?php
header('Content-Type: application/json');

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// Auth check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['system_admin', 'lgu_staff', 'road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Transportation Operations Supervisors see only Transportation reports.
$transport_only = (($_SESSION['role'] ?? '') === 'trans_ops_supervisor');

$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$type_filter = isset($_GET['type']) ? $_GET['type'] : 'all';

// Helper function to get recent submissions with pagination
function getRecentSubmissionsPaginated($offset, $limit, $status_filter = 'all', $type_filter = 'all', $transport_only = false) {
    global $conn;
    $reports = [];
    if (!$conn) return $reports;

    // Transportation Operations Supervisors see only Transportation reports.
    $transport_category_filter = $transport_only ? " AND report_category = 'transportation'" : '';

    // Helper to append shared WHERE clauses and run a query (no pagination at query level)
    $fetch = function ($sql, $status_filter) use ($conn) {
        $params = [];
        $types = '';
        if ($status_filter !== 'all') {
            $sql .= " AND status = ?";
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
            "SELECT id, report_id, title, report_type,
                    CASE WHEN created_by IS NULL OR created_by = 0 THEN 'citizen' ELSE 'lgu' END AS source,
                    status, priority, severity, created_at, description,
                    latitude, longitude, location, reporter_name, attachments, image_path,
                    cimm_sync_status, cimm_verified_at, cimm_verified_by
             FROM road_transportation_reports
             WHERE report_type != 'infrastructure_issue'
               AND (status = 'approved' OR cimm_sync_status = 'verified')
               AND (created_by IS NULL OR created_by = 0
                    OR cimm_sync_status IS NULL OR cimm_sync_status <> 'pushed'
                    OR (report_category = 'transportation' AND report_source = 'local' AND created_by != 0)){$transport_category_filter}",
            $status_filter
        ));

        // 2. Infrastructure Projects (road_maintenance_reports, finalized)
        //    Excluded for Transportation Operations Supervisors.
        if (!$transport_only) {
            $reports = array_merge($reports, $fetch(
            "SELECT id, report_id, title, report_type,
                    'infrastructure' AS source,
                    status, priority, NULL AS severity, created_at, description,
                    NULL AS latitude, NULL AS longitude, location, NULL AS reporter_name,
                    NULL AS attachments, NULL AS image_path,
                    NULL AS cimm_sync_status, NULL AS cimm_verified_at, NULL AS cimm_verified_by
             FROM road_maintenance_reports
             WHERE status IN ('approved','completed')",
            $status_filter
        ));

        // 2b. Infrastructure issue rows that live inside the transport table
        $reports = array_merge($reports, $fetch(
            "SELECT id, report_id, title, report_type,
                    'infrastructure' AS source,
                    status, priority, severity, created_at, description,
                    latitude, longitude, location, reporter_name, attachments, image_path,
                    cimm_sync_status, cimm_verified_at, cimm_verified_by
             FROM road_transportation_reports
             WHERE report_type = 'infrastructure_issue'
               AND status IN ('approved','completed')",
            $status_filter
        ));

        // 3. CIMM reports (finalized = verification_status 'Verified')
        try {
            $reports = array_merge($reports, $fetch(
                "SELECT id, reference_code AS report_id, infrastructure AS title,
                        'infrastructure_issue' AS report_type, 'cimm' AS source,
                        'completed' AS status, priority, NULL AS severity,
                        COALESCE(submitted_at, verified_at, synced_at, NOW()) AS created_at,
                        issue AS description, coord_lat AS latitude, coord_lng AS longitude,
                        location, reporter_name, NULL AS attachments, NULL AS image_path,
                        'verified' AS cimm_sync_status, verified_at AS cimm_verified_at,
                        NULL AS cimm_verified_by
                 FROM cimm_verification_reports
                 WHERE verification_status = 'Verified'",
                $status_filter
            ));
        } catch (Exception $e) {
            error_log("Recent CIMM reports error: ".$e->getMessage());
        }
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
    $reports = getRecentSubmissionsPaginated($offset, $limit, $status_filter, $type_filter, $transport_only);
    
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
            'priority' => $rr['priority'] ?? 'low',
            'created_at' => $rr['created_at'],
            'cimm_sync_status' => $rr['cimm_sync_status'] ?? '',
            'cimm_verified_at' => $rr['cimm_verified_at'] ?? '',
            'cimm_verified_by' => $rr['cimm_verified_by'] ?? '',
            'report_type' => $rr['report_type'] ?? '',
            'details' => [
                'id' => $rr['id'],
                'report_id' => $rr['report_id'],
                'title' => $rr['title'],
                'source' => $rr_source_label,
                'report_type' => $rr['report_type'],
                'status' => $rr['status'],
                'priority' => $rr['priority'],
                'severity' => $rr['severity'],
                'created_at' => $rr['created_at'],
                'description' => $rr['description'],
                'latitude' => $rr['latitude'],
                'longitude' => $rr['longitude'],
                'location' => $rr['location'],
                'reporter_name' => $rr['reporter_name'],
                'attachments' => $rr['attachments'],
                'image_path' => $rr['image_path'],
            ]
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

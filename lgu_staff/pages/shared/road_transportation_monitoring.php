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

// Check if session has expired
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ../../login.php?timeout=1');
    exit();
}

// Update last activity time
$_SESSION['last_activity'] = time();

// Check if user is logged in
if (
    !isset($_SESSION['user_id']) ||
    !is_admin_or_staff_role($_SESSION['role'] ?? '')
) {
    header('Location: ../../login.php');
    exit();
}

// Auto-archive sweep: move any completed report whose 7-day retention window
// (measured from completed_at, set by the Complete button's complete_status
// action) has passed into the archive. Runs once per page load; it only touches
// reports carrying auto_archive_at, so report_management completions are never
// moved. Completed reports stay visible in Recent Submissions until then.
try {
    rgmap_auto_archive_completed($conn);
} catch (Exception $e) {
    error_log('Road/Transportation monitoring auto-archive sweep: ' . $e->getMessage());
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

// Function to get enhanced dashboard stats
function getEnhancedStats() {
    global $conn, $is_transport_supervisor, $is_road_only_role;
    $stats = ['total' => 0, 'active' => 0, 'critical' => 0, 'resolved_month' => 0];
    if ($conn) {
        try {
            // Transportation Operations Supervisors see only Transportation reports.
            // Road-only roles (Road Ops Supervisor, Road Monitoring Officer) see
            // only Road reports.
            if ($is_transport_supervisor) {
                $cat_filter = " AND report_category = 'transportation'";
            } elseif ($is_road_only_role) {
                $cat_filter = " AND report_category = 'road'";
            } else {
                $cat_filter = '';
            }
            $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE 1=1{$cat_filter}");
            if ($r) $stats['total'] = (int)$r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE status IN ('pending','in-progress'){$cat_filter}");
            if ($r) $stats['active'] = (int)$r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE priority IN ('high','critical') AND status != 'completed'{$cat_filter}");
            if ($r) $stats['critical'] = (int)$r->fetch_assoc()['c'];
            $r = $conn->query("SELECT COUNT(*) as c FROM road_transportation_reports WHERE status='completed' AND MONTH(updated_at)=MONTH(CURDATE()) AND YEAR(updated_at)=YEAR(CURDATE()){$cat_filter}");
            if ($r) $stats['resolved_month'] = (int)$r->fetch_assoc()['c'];
        } catch (Exception $e) { error_log("Enhanced stats error: ".$e->getMessage()); }
    }
    return $stats;
}

// Function to get recent submissions from all report sources managed by
// report_management.php. Only finalized reports are included:
//   - LGU Monitoring / Citizen reports (road_transportation_reports) that are
//     APPROVED or have been VERIFIED by CIMM; LGU ROAD reports that are still
//     Awaiting CIMM Verification are excluded, while LGU Transportation
//     reports (report_category='transportation') do not require CIMM
//     verification and appear once approved
//   - Infrastructure Projects (road_maintenance_reports) that are APPROVED or
//     COMPLETED
//   - CIMM reports whose verification_status is 'Verified'
function getRecentSubmissions($limit = 10, $status_filter = 'all', $type_filter = 'all', $transport_only = false, $road_only = false) {
    global $conn;
    $reports = [];
    if (!$conn) return $reports;

    // Transportation Operations Supervisors see only Transportation reports.
    $transport_category_filter = $transport_only ? " AND report_category = 'transportation'" : '';

    // Road Operations Supervisors see only Road reports.
    $road_category_filter = $road_only ? " AND report_category = 'road'" : '';

    // Helper to append shared WHERE/ORDER/LIMIT clauses and run a query
    $fetch = function ($sql, $status_filter, $type_filter, $limit) use ($conn) {
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
        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;
        $types .= 'i';
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
        //    LGU ROAD rows still Awaiting CIMM Verification ('pushed') are excluded;
        //    LGU Transportation reports (report_category='transportation') do not
        //    require CIMM verification and appear once they are finalized.
        $reports = array_merge($reports, $fetch(
            "SELECT id, report_id, title, report_type, report_category,
                    CASE WHEN created_by IS NULL OR created_by = 0 THEN 'citizen' ELSE 'lgu' END AS source,
                    status, priority, severity, created_at, description,
                    latitude, longitude, location, reporter_name, attachments, image_path,
                    cimm_sync_status, cimm_verified_at, cimm_verified_by,
                    NULL AS approval_status, NULL AS verification_status,
                    'road_transportation_reports' AS _source_table
              FROM road_transportation_reports
             WHERE report_type != 'infrastructure_issue'
               AND status IN ('approved', 'in-progress', 'completed')
               AND (created_by IS NULL OR created_by = 0
                    OR cimm_sync_status IS NULL OR cimm_sync_status <> 'pushed'
                    OR (report_category = 'transportation' AND report_source = 'local' AND created_by != 0))
                   $transport_category_filter{$road_category_filter}",
            $status_filter, $type_filter, $limit
        ));

        // 2. Infrastructure Projects (road_maintenance_reports, finalized).
        //    Excluded for Transportation Operations Supervisors.
        if (!$transport_only) {
            $reports = array_merge($reports, $fetch(
                "SELECT id, report_id, title, report_type,
                        'road' AS report_category,
                        'infrastructure' AS source,
                        status, priority, NULL AS severity, created_at, description,
                        NULL AS latitude, NULL AS longitude, location, NULL AS reporter_name,
                        NULL AS attachments, NULL AS image_path,
                        NULL AS cimm_sync_status, NULL AS cimm_verified_at, NULL AS cimm_verified_by,
                        'road_maintenance_reports' AS _source_table
                 FROM road_maintenance_reports
                 WHERE status IN ('approved','in-progress','completed')",
                $status_filter, $type_filter, $limit
            ));
        }

        // 2b. Infrastructure issue rows that live inside the transport table
        //     are also managed as Infrastructure Projects by report_management.
        if (!$transport_only) {
            $reports = array_merge($reports, $fetch(
                "SELECT id, report_id, title, report_type, report_category,
                        'infrastructure' AS source,
                        status, priority, severity, created_at, description,
                        latitude, longitude, location, reporter_name, attachments, image_path,
                        cimm_sync_status, cimm_verified_at, cimm_verified_by,
                        'road_transportation_reports' AS _source_table
                 FROM road_transportation_reports
                 WHERE report_type = 'infrastructure_issue'
                   AND status IN ('approved','in-progress','completed'){$road_category_filter}",
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
                            issue AS description, coord_lat AS latitude, coord_lng AS longitude,
                            location, reporter_name, NULL AS attachments, NULL AS image_path,
                            'verified' AS cimm_sync_status, verified_at AS cimm_verified_at,
                            NULL AS cimm_verified_by, approval_status,
                            'cimm_verification_reports' AS _source_table
                     FROM cimm_verification_reports
                     WHERE verification_status IN ('Approved', 'In Progress', 'Completed')
                       AND infrastructure = 'Roads'
                 ) AS cimm_mapped WHERE 1=1",
                $status_filter, $type_filter, $limit
            ));
        } catch (Exception $e) {
            error_log("Recent CIMM reports error: ".$e->getMessage());
        }
        }

        // Sort combined results by created_at DESC and cap at the requested limit
        usort($reports, function($a, $b) {
            return strtotime($b['created_at'] ?? 'now') - strtotime($a['created_at'] ?? 'now');
        });
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

                // Combine issue type and specific type for detailed reporting
                $full_issue_type = $specific_type ? $specific_type : $issue_type;
                $report_category = ($issue_type === 'roads') ? 'road' : 'transportation';
                $report_source = 'local';

                // Server-side guard: Transportation Operations Supervisors may
                // only submit Transportation category reports. This prevents
                // bypassing the UI by crafting a request with category Roads.
                if ($is_transport_supervisor && $report_category === 'road') {
                    echo json_encode(['success' => false, 'message' => 'Transportation Operations Supervisors can only submit Transportation reports.']);
                    exit;
                }

                // Also reject road-specific issue types that would otherwise
                // slip through if a crafted request pairs a transportation
                // category with a road issue type.
                $road_issue_types = ['potholes', 'road_damage', 'cracks', 'erosion', 'flooding', 'debris', 'shoulder_damage', 'marking_fade'];
                if ($is_transport_supervisor && in_array($specific_type, $road_issue_types, true)) {
                    echo json_encode(['success' => false, 'message' => 'Transportation Operations Supervisors can only submit Transportation reports.']);
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
                $title = str_replace('_', ' ', ucfirst($full_issue_type));
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
// across the three tables that feed it (transport / maintenance / CIMM).
// Returns null when the id does not exist in any of them.
function resolve_recent_focus_row(int $id, string $source_hint = ''): ?array {
    global $conn;

    $candidates = $source_hint !== ''
        ? [$source_hint]
        : ['transport', 'maintenance', 'cimm'];

    try {
        foreach ($candidates as $src) {
            if ($src === 'transport') {
                $stmt = $conn->prepare("SELECT id, report_id, title, report_type, report_category, status, priority, severity, created_at, description, latitude, longitude, location, reporter_name, attachments, image_path, cimm_sync_status, cimm_verified_at, cimm_verified_by, created_by FROM road_transportation_reports WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($r) {
                    $r['_source_table'] = 'road_transportation_reports';
                    $r['source'] = (($r['report_type'] ?? '') === 'infrastructure_issue')
                        ? 'infrastructure'
                        : ((!empty($r['created_by'])) ? 'lgu' : 'citizen');
                    return $r;
                }
            } elseif ($src === 'maintenance') {
                $stmt = $conn->prepare("SELECT id, report_id, title, report_type, 'road' AS report_category, status, priority, NULL AS severity, created_at, description, NULL AS latitude, NULL AS longitude, location, NULL AS reporter_name, NULL AS attachments, NULL AS image_path, NULL AS cimm_sync_status, NULL AS cimm_verified_at, NULL AS cimm_verified_by FROM road_maintenance_reports WHERE id = ?");
                $stmt->bind_param("i", $id);
                $stmt->execute();
                $r = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($r) {
                    $r['_source_table'] = 'road_maintenance_reports';
                    $r['source'] = 'infrastructure';
                    return $r;
                }
            } elseif ($src === 'cimm') {
                require_once __DIR__ . '/../api/cimm_verification_data.php';
                $pdo = rgmap_verification_pdo();
                rgmap_ensure_cimm_verification_table($pdo);
                $stmt = $pdo->prepare("SELECT id, reference_code AS report_id, infrastructure AS title, 'infrastructure_issue' AS report_type, " . cimm_status_case_sql() . " AS status, priority, NULL AS severity, COALESCE(submitted_at, verified_at, synced_at, NOW()) AS created_at, issue AS description, coord_lat AS latitude, coord_lng AS longitude, location, reporter_name, NULL AS attachments, NULL AS image_path, 'verified' AS cimm_sync_status, verified_at AS cimm_verified_at, NULL AS cimm_verified_by FROM cimm_verification_reports WHERE id = ?");
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
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';

// Deep-link focus: ?focus_report_id= (numeric PK) from a Progress Update
// notification's "View Report" button. The report may live in any of the
// tables that feed Recent Submissions, so we locate it server-side, force the
// filters off so nothing hides it, and inject the row if Recent Submissions
// wouldn't normally show it (e.g. it is not yet in a finalized status).
$focus_report_id = isset($_GET['focus_report_id']) ? (int)$_GET['focus_report_id'] : 0;
$focus_source_hint = (string)($_GET['source'] ?? '');
$focus_target = ['found' => false, 'id' => $focus_report_id, 'source' => '', 'report_id' => ''];

if ($focus_report_id > 0) {
    $status_filter = 'all';
    $type_filter = 'all';
}

// Get data for the page
$alerts = getActiveAlerts();
$roads = getRoadStatus();
$enhanced_stats = getEnhancedStats();
$recent_reports = getRecentSubmissions(10, $status_filter, 'all', $is_transport_supervisor, $is_road_only_role);

if ($focus_report_id > 0) {
    $focus_row = resolve_recent_focus_row($focus_report_id, $focus_source_hint);
    if ($focus_row) {
        $focus_target['found'] = true;
        $focus_target['source'] = $focus_row['source'] ?? '';
        $focus_target['report_id'] = $focus_row['report_id'] ?? '';

        // Respect role-based restrictions: transport supervisors never see
        // infrastructure or CIMM rows in Recent Submissions; road-only roles
        // (Road Operations Supervisor, Road Monitoring Officer) never see
        // non-Road reports.
        $restricted = ($is_transport_supervisor
                && in_array($focus_row['source'] ?? '', ['infrastructure', 'cimm'], true))
            || ($is_road_only_role && (($focus_row['report_category'] ?? '') !== 'road'));

        if (!$restricted) {
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
                usort($recent_reports, function ($a, $b) {
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road and Transportation Monitoring | LGU Staff</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="../../css/progress-updates.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="../../js/progress-updates.js"></script>
    <script src="../../js/progress-updates-common.js"></script>
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
            display: flex; justify-content: space-between; align-items: center;
            flex-wrap: wrap; gap: 10px; margin-bottom: 12px;
        }
        .map-toolbar-left { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .map-toolbar-right { display: flex; gap: 8px; }
        .map-legend {
            display: flex; align-items: center; gap: 14px;
            font-size: 12px; color: #555; padding: 6px 12px;
            background: rgba(255,255,255,0.7); border-radius: 8px;
        }
        .map-legend-item { display: flex; align-items: center; gap: 5px; }
        .map-legend-dot {
            width: 12px; height: 12px; border-radius: 50%;
            border: 2px solid #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .map-fullscreen-btn {
            padding: 6px 14px; background: rgba(55,98,200,0.1); color: #3762c8;
            border: 1px solid rgba(55,98,200,0.3); border-radius: 6px;
            font-size: 12px; cursor: pointer; transition: all 0.2s;
        }
        .map-fullscreen-btn:hover { background: #3762c8; color: #fff; }

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

        .tools-dropdown-item {
            display:block;width:100%;padding:10px 16px;border:none;background:none;
            text-align:left;font-size:13px;color:#333;cursor:pointer;transition:background 0.2s;
            font-family:'Poppins',sans-serif;
        }
        .tools-dropdown-item:hover { background:rgba(55,98,200,0.08); color:#3762c8; }
        .tools-dropdown-item i { width:20px; color:#3762c8; }
        body.dark-mode .tools-dropdown-item { color:#e4e6ea; }
        body.dark-mode .tools-dropdown-item:hover { background:rgba(55,98,200,0.15); }

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
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <!-- Monitoring Header -->
        <div class="monitoring-header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Road and Transportation Reporting</h1>
                    <p>Real-time monitoring of road conditions and traffic flow</p>
                </div>
            </div>
        </div>

        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-road"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['total']); ?></div>
                <div class="stat-label">Total Reports</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['active']); ?></div>
                <div class="stat-label">Active Issues</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon red"><i class="fas fa-bolt"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['critical']); ?></div>
                <div class="stat-label">High / Critical</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo number_format($enhanced_stats['resolved_month']); ?></div>
                <div class="stat-label">Resolved This Month</div>
            </div>
        </div>

        <!-- Main Monitoring Layout -->
        <div class="monitoring-layout">
            <!-- Map Section -->
            <div class="map-section">
                <div class="map-header">
                    <h3 class="map-title">Live Road Map — Quezon City</h3>
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
                        </div>
                        <div class="map-search-box" style="display:flex;align-items:center;gap:6px;margin-left:8px;">
                            <input type="text" id="mapSearchInput" placeholder="Search places..." style="padding:5px 10px;border:1px solid rgba(55,98,200,0.3);border-radius:6px;font-size:12px;width:160px;">
                            <button class="map-fullscreen-btn" onclick="doMapSearch()" title="Search"><i class="fas fa-search"></i></button>
                        </div>
                    </div>
                    <div class="map-toolbar-right">
                        <div class="dropdown" style="position:relative;display:inline-block;">
                            <button class="map-fullscreen-btn" onclick="toggleToolsDropdown()" id="toolsDropdownBtn">
                                <i class="fas fa-tools"></i> Tools
                            </button>
                            <div id="toolsDropdownMenu" class="t-card" style="display:none;position:absolute;top:100%;right:0;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.15);z-index:1000;min-width:200px;padding:8px 0;margin-top:4px;">
                                <button class="tools-dropdown-item" onclick="showRoutePlanner()"><i class="fas fa-route"></i> Route Planner</button>
                                <button class="tools-dropdown-item" onclick="toggleSatelliteLayer()"><i class="fas fa-satellite"></i> Satellite View</button>
                                <button class="tools-dropdown-item" onclick="toggleTrafficIncidentsLayer()" id="toggleIncidentsBtn"><i class="fas fa-exclamation-triangle"></i> Traffic Incidents</button>
                                <button class="tools-dropdown-item" onclick="showEVCharging()"><i class="fas fa-charging-station"></i> EV Stations</button>
                                <button class="tools-dropdown-item" onclick="showReachableRange()"><i class="fas fa-circle"></i> Reachable Range</button>
                                <button class="tools-dropdown-item" onclick="showGeofencingTool()"><i class="fas fa-draw-polygon"></i> Geofence Check</button>
                            </div>
                        </div>
                        <button class="map-fullscreen-btn" id="toggleTrafficBtn" onclick="toggleTrafficLayer()">
                            <i class="fas fa-car"></i> Traffic
                        </button>
                        <button class="map-fullscreen-btn" onclick="toggleMapFullscreen()" id="fullscreenMapBtn">
                            <i class="fas fa-expand"></i> Fullscreen
                        </button>
                    </div>
                </div>
                <div id="map"></div>
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
                        
                        <label>Issue type</label>
                        <?php if ($is_transport_supervisor): ?>
                        <select id="issue-type" name="issue_type" required onchange="updateSpecificTypes()">
                            <option value="transportation" selected>Transportation</option>
                        </select>
                        <?php else: ?>
                        <select id="issue-type" name="issue_type" required onchange="updateSpecificTypes()">
                            <option value="">— Select —</option>
                            <option value="transportation">Transportation</option>
                            <option value="roads">Roads</option>
                        </select>
                        <?php endif; ?>
                        
                        <label id="specific-type-label" style="display: none; margin-top: 10px;">Specific Issue Type</label>
                        <select id="specific-type" name="specific_type" style="display: none;" required>
                            <!-- Transportation specific types -->
                            <optgroup id="transportation-options" label="Transportation Issues" style="display: none;">
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
                            
                            <!-- Roads specific types -->
                            <?php if (!$is_transport_supervisor): ?>
                            <optgroup id="roads-options" label="Road Issues" style="display: none;">
                                <option value="potholes">Potholes</option>
                                <option value="road_damage">Road Damage</option>
                                <option value="cracks">Road Cracks</option>
                                <option value="erosion">Road Erosion</option>
                                <option value="flooding">Street Flooding</option>
                                <option value="debris">Road Debris</option>
                                <option value="shoulder_damage">Shoulder Damage</option>
                                <option value="marking_fade">Faded Road Markings</option>
                            </optgroup>
                            <?php endif; ?>
                        </select>
                        <label>Severity</label>
                        <select id="severity" name="severity" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="severe">Severe</option>
                        </select>
                        <label>Description</label>
                        <textarea id="description" name="description" rows="3" required placeholder="Describe the issue..."></textarea>
                        <label>Upload Photos (Optional)</label>
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
                    <label>Start Location</label>
                    <input type="text" id="routeFrom" placeholder="Click map or type address..." onclick="routeFromClick()">
                    <label>Destination</label>
                    <input type="text" id="routeTo" placeholder="Click map or type address..." onclick="routeToClick()">
                    <label>Travel Mode</label>
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

                <!-- Reachable Range Panel -->
                <div id="reachableRangePanel" class="tomtom-panel">
                    <h5><i class="fas fa-circle"></i> Reachable Range</h5>
                    <p class="t-text-secondary" style="font-size:12px;">Click on the map to set the center point, then calculate.</p>
                    <label>Time Budget (minutes)</label>
                    <input type="number" id="rangeTimeBudget" value="30" min="1" max="120">
                    <div style="display:flex;gap:8px;">
                        <button class="btn-action btn-sm" onclick="calcReachableRange()"><i class="fas fa-calculator"></i> Calculate</button>
                        <button class="btn-action btn-sm btn-secondary" onclick="closePanel('reachableRangePanel')">Close</button>
                    </div>
                    <div id="rangeInfo" class="route-info-box" style="display:none;"></div>
                </div>

                <!-- Geofencing Panel -->
                <div id="geofencingPanel" class="tomtom-panel">
                    <h5><i class="fas fa-draw-polygon"></i> Geofence Check</h5>
                    <p class="t-text-secondary" style="font-size:12px;">Enter coordinates to check if a location is within any geofence.</p>
                    <label>Latitude</label>
                    <input type="number" id="geofenceLat" step="any" placeholder="e.g., 14.65">
                    <label>Longitude</label>
                    <input type="number" id="geofenceLng" step="any" placeholder="e.g., 121.05">
                    <div style="display:flex;gap:8px;">
                        <button class="btn-action btn-sm" onclick="checkGeofence()"><i class="fas fa-check"></i> Check</button>
                        <button class="btn-action btn-sm btn-secondary" onclick="closePanel('geofencingPanel')">Close</button>
                    </div>
                    <div id="geofenceInfo" class="route-info-box" style="display:none;"></div>
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

                <div id="mapSearchResults" class="search-results-dropdown"></div>
            </div>

            <!-- Sidebar -->
            <div class="sidebar-section">
                <!-- Active Alerts -->
                <div class="info-card">
                    <h3 class="info-card-title">
                        <i class="fas fa-exclamation-triangle"></i>
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

        <!-- Road Status List -->
        <div class="info-card">
            <h3 class="info-card-title">
                <i class="fas fa-road"></i>
                Major Road Status
            </h3>
            <div class="road-status-list">
                <?php foreach ($roads as $road): ?>
                <div class="road-item">
                    <div class="road-status status-<?php 
                        echo $road['status'] == 'completed' ? 'clear' : 
                             ($road['status'] == 'in-progress' ? 'moderate' : 'heavy'); 
                    ?>"></div>
                    <div class="road-info">
                        <div class="road-name"><?php echo htmlspecialchars($road['name']); ?></div>
                        <div class="road-condition"><?php echo htmlspecialchars($road['condition']); ?></div>
                        <div class="traffic-indicator">
                            <i class="fas fa-car"></i>
                            <span><?php echo htmlspecialchars($road['traffic']); ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Reports Table -->
        <div class="reports-table-section">
            <div class="table-header">
                <h3><i class="fas fa-list"></i> Recent Submissions</h3>
                <div class="table-header-right">
                    <select class="filter-select" id="statusFilter" onchange="filterReports()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                        <option value="in-progress" <?php echo $status_filter === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                    <select class="filter-select" id="typeFilter" onchange="filterReportsBySource()">
                        <option value="all">All Types</option>
                        <?php if (!$is_road_supervisor): ?>
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
                    <button class="btn-secondary-custom" onclick="resetFilters()" title="Reset Filters">
                        <i class="fas fa-arrow-clockwise"></i>
                    </button>
                    <input type="text" class="road-search" placeholder="Search by title or ID..." id="reportSearchInput" oninput="filterReportsTable(this.value)">
                </div>
            </div>
            <div class="reports-table-wrap">
                <table id="recentReportsTable">
                    <thead>
                        <tr>
                            <th>Report ID</th>
                            <th>Title</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Assignment</th>
                            <th>Priority</th>
                            <th>Date</th>
                            <th>CIMM Verification</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_reports)): ?>
                        <tr><td colspan="9" style="text-align:center;padding:30px;color:#6b7280;">No reports yet.</td></tr>
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
                        <?php $rr_details = htmlspecialchars(json_encode([
                            'id' => $rr['id'],
                            'report_id' => $rr['report_id'],
                            'title' => $rr['title'],
                            'source' => $rr_source_label,
                            'report_type' => $rr['report_type'],
                            'report_category' => $rr['report_category'],
                            'status' => $rr['status'],
                            'assignment_status' => $rr['assignment_status'] ?? 'unassigned',
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
                            'cimm_sync_status' => $rr['cimm_sync_status'] ?? '',
                            'cimm_verified_at' => $rr['cimm_verified_at'] ?? '',
                            'cimm_verified_by' => $rr['cimm_verified_by'] ?? '',
                            'approval_status' => $rr['approval_status'] ?? '',
                            'verification_status' => $rr['verification_status'] ?? '',
                        ]), ENT_QUOTES, 'UTF-8'); ?>
                         <tr class="report-table-row" data-id="<?php echo $rr['id']; ?>" data-title="<?php echo htmlspecialchars(strtolower($rr['title'] ?? '')); ?>" data-report-id="<?php echo htmlspecialchars(strtolower($rr['report_id'] ?? '')); ?>" data-status="<?php echo $rr['status'] ?? 'pending'; ?>" data-source="<?php echo $rr_source_key; ?>" data-details='<?php echo $rr_details; ?>'>
                            <td style="font-family:monospace;font-size:12px;"><?php echo htmlspecialchars($rr['report_id'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($rr['title'] ?? 'Untitled'); ?></td>
                            <td><?php echo htmlspecialchars($rr_source_label); ?></td>
                            <td><span class="badge badge-<?php echo strtolower(str_replace(' ', '-', $rr['status'] ?? 'pending')); ?>"><?php echo ucfirst(str_replace('-',' ',$rr['status'] ?? 'pending')); ?></span></td>
                            <td><?php if ($is_road_supervisor && ($rr['assignment_status'] ?? 'unassigned') === 'assigned' && !empty($rr['assignment_officer'])): ?>
                                <span class="badge assignment-badge assignment-assigned"><?php echo htmlspecialchars($rr['assignment_officer']); ?></span>
                            <?php else: ?>
                                <span class="badge assignment-badge assignment-<?php echo ($rr['assignment_status'] ?? 'unassigned') === 'assigned' ? 'assigned' : 'unassigned'; ?>"><?php echo ($rr['assignment_status'] ?? 'unassigned') === 'assigned' ? 'Assigned' : 'Unassigned'; ?></span>
                            <?php endif; ?></td>
                            <td><span class="badge badge-<?php echo strtolower($rr['priority'] ?? 'low'); ?>"><?php echo ucfirst($rr['priority'] ?? 'low'); ?></span></td>
                            <td><?php echo date('M d, Y H:i', strtotime($rr['created_at'] ?? 'now')); ?></td>
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
                                    <?php if (strtolower($rr['cimm_sync_status'] ?? '') === 'verified'): ?>
                                        <span class="cimm-verify-badge cimm-verify-badge-verified" title="Approved by CIMM">
                                            <i class="fas fa-check-circle"></i> Approved
                                        </span>
                                    <?php else: ?>
                                        <span class="cimm-verify-badge cimm-verify-badge-none">—</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <button class="table-action-btn" title="View Details" onclick="viewReportDetails(<?php echo $rr['id']; ?>, '<?php echo $rr['source']; ?>')"><i class="fas fa-eye"></i></button>
                                <button class="table-action-btn view-map" onclick="focusReportOnMap(<?php echo $rr['id']; ?>)"><i class="fas fa-map-pin"></i> Map</button>
                                <button class="table-action-btn" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;margin-left:4px;" onclick="viewReportUpdates(<?php echo $rr['id']; ?>, '<?php echo $rr['report_type']; ?>', '<?php echo $rr['source']; ?>')"><i class="fas fa-clock"></i> Updates</button>
                                <?php if (strtolower((string)($rr['status'] ?? '')) === 'completed'): ?>
                                <button class="table-action-btn" title="Archive" style="background:linear-gradient(135deg,#6b7280,#4b5563);color:#fff;margin-left:4px;" onclick="archiveReport(<?php echo $rr['id']; ?>, '<?php echo $rr['source']; ?>')"><i class="fas fa-archive"></i> Archive</button>
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
        // Quezon City center
        const QC_CENTER = [14.651417, 121.04917];
        const map = L.map('map').setView(QC_CENTER, 13);

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
        if (QC_POLYGON) map.fitBounds(QC_POLYGON.getBounds());

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
        function populateGISLocationInfo(lat, lng, districtProps, addressData) {
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
            if (!fullAddress && !barangay && !street) {
                html += '<span style="font-size:11px;color:#999;">Address details unavailable for this pin location.</span>';
                document.getElementById('pin-address').value = lat.toFixed(5) + ', ' + lng.toFixed(5) + ', Quezon City';
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

            // Step 2: Reverse geocode via TomTom (async)
            TomTomServices.reverseGeocodeOrbis(lat, lng).then(data => {
                const result = data.data?.results?.[0];
                populateGISLocationInfo(lat, lng, districtProps, result || null);

                // Also update the marker popup with formatted address
                if (pinMarker && result) {
                    const addr = result.address || {};
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
                populateGISLocationInfo(lat, lng, districtProps, null);
            });
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
                map.setView(QC_CENTER, 13);
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
            const btn = document.getElementById('toggleTrafficBtn');
            if (trafficVisible) {
                trafficLayer.addTo(map);
                btn.style.background = 'rgba(55,98,200,0.1)';
                btn.style.color = '#3762c8';
            } else {
                map.removeLayer(trafficLayer);
                btn.style.background = '#6c757d';
                btn.style.color = '#fff';
            }
        }

        // Toggle map fullscreen
        function toggleMapFullscreen() {
            mapFullscreen = !mapFullscreen;
            document.body.classList.toggle('map-fullscreen-active', mapFullscreen);
            const btn = document.getElementById('fullscreenMapBtn');
            btn.innerHTML = mapFullscreen ? '<i class="fas fa-compress"></i> Exit' : '<i class="fas fa-expand"></i> Fullscreen';
            setTimeout(() => map.invalidateSize(), 300);
        }

        // Focus map on a specific report by ID
        function focusReportOnMap(reportId) {
            // First try to find in existing markers (fast path)
            const found = allMarkerObjects.find(m => m._reportId === reportId);
            if (found) {
                map.setView(found.getLatLng(), 16);
                found.openPopup();
                return;
            }
            // Not in current markers — fetch all markers directly and locate it
            activeFilter = 'all';
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            const allBtn = document.querySelector('.filter-btn[data-filter="all"]');
            if (allBtn) allBtn.classList.add('active');
            fetch('?action=get_markers')
                .then(r => r.json())
                .then(markers => {
                    const report = markers.find(m => m.id == reportId);
                    if (report && report.latitude && report.longitude) {
                        const lat = parseFloat(report.latitude);
                        const lng = parseFloat(report.longitude);
                        map.setView([lat, lng], 16);
                        // Also refresh markers on map with all filter
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
            window.location.href = url.toString();
        }

        function filterReportsBySource() {
            const source = document.getElementById('typeFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            const tableBody = document.querySelector('#recentReportsTable tbody');
            
            // Clear existing rows
            tableBody.innerHTML = '';
            
            // Show loading state
            const loadingRow = document.createElement('tr');
            loadingRow.innerHTML = '<td colspan="8" style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Loading reports...</td>';
            tableBody.appendChild(loadingRow);
            
            // Reset pagination state
            currentOffset = 0;
            hasMoreReports = true;
            isLoadingMore = false;
            
            // Fetch filtered data from API
            fetch(`../api/get_recent_submissions_paginated.php?offset=0&limit=10&status=${statusFilter}&type=${source}`)
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
                        tableBody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:#6b7280;">No reports found for this filter.</td></tr>';
                        hasMoreReports = false;
                        hideLoadMoreButton();
                    }
                })
                .catch(error => {
                    tableBody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:#dc3545;">Error loading reports. Please try again.</td></tr>';
                    showNotification('Error loading filtered reports', 'error');
                });
        }

        function resetFilters() {
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('typeFilter').value = 'all';
            document.querySelectorAll('#recentReportsTable .report-table-row').forEach(row => {
                row.style.display = '';
            });
            const url = new URL(window.location);
            url.searchParams.delete('status');
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
        }

        // View Report Details Modal
        function viewReportDetails(id, source) {
            const row = document.querySelector(`#recentReportsTable .report-table-row[data-id="${id}"]`);
            if (!row || !row.dataset.details) {
                showNotification('Report details not available.', 'error');
                return;
            }
            let data;
            try { data = JSON.parse(row.dataset.details); } catch(e) {
                showNotification('Could not parse report details.', 'error');
                return;
            }
            const r = data;

            var statusStyles = {
                'pending':    {bg:'rgba(251,191,36,0.15)', color:'#f59e0b'},
                'approved':   {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                'completed':  {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                'resolved':   {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'},
                'cancelled':  {bg:'rgba(220,53,69,0.15)',  color:'#ef4444'},
                'rejected':   {bg:'rgba(220,53,69,0.15)',  color:'#ef4444'},
                'in-progress':{bg:'rgba(59,130,246,0.15)', color:'#3b82f6'},
                'verified':   {bg:'rgba(34,197,94,0.15)',  color:'#22c55e'}
            };
            var pStyles = {
                'high':   {bg:'rgba(220,53,69,0.15)', color:'#ef4444'},
                'critical':{bg:'rgba(220,53,69,0.15)', color:'#dc2626'},
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
            var reportType = r.report_type || '—';
            if (reportType !== '—') {
                badgesHtml += '<span style="display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600;background:rgba(55,98,200,0.12);color:#3762c8;">' + reportType + '</span>';
            }
            document.getElementById('rm-badges').innerHTML = badgesHtml;

            // Report Information
            var reportGrid = '';
            reportGrid += rmInfoItem('folder', 'Report Type', r.report_type);
            reportGrid += rmInfoItem('tag', 'Category', r.report_category);
            reportGrid += rmInfoItem('exclamation-circle', 'Severity', r.severity);
            reportGrid += rmInfoItem('calendar-alt', 'Created Date', formatDate(r.created_at));
            if (r.assignment_status) {
                reportGrid += rmInfoItem('user-check', 'Assignment', (r.assignment_status === 'assigned') ? 'Assigned' : 'Unassigned');
            }
            document.getElementById('rm-report-grid').innerHTML = reportGrid;

            // Source & Assignment
            var sourceGrid = '';
            sourceGrid += rmInfoItem('server', 'Source', r.source);
            if (r.reporter_name) {
                sourceGrid += rmInfoItem('user', 'Reported By', r.reporter_name);
            }
            if (r.approval_status) {
                sourceGrid += rmInfoItem('clipboard-check', 'Approval Status', r.approval_status);
            }
            if (r.verification_status) {
                sourceGrid += rmInfoItem('shield-alt', 'Verification', r.verification_status);
            }
            document.getElementById('rm-source-grid').innerHTML = sourceGrid;

            // Location
            var locationGrid = '';
            var locVal = r.location || '—';
            if (r.latitude && r.longitude && r.latitude != 0 && r.longitude != 0) {
                locVal += '<br><a href="https://www.openstreetmap.org/?mlat=' + r.latitude + '&mlon=' + r.longitude + '&zoom=15" target="_blank" style="color:#3762c8;font-size:12px;text-decoration:none;"><i class="fas fa-external-link-alt" style="font-size:10px;"></i> View on Map</a>';
            }
            locationGrid += '<div class="rm-info-item rm-info-value-full"><div class="rm-info-icon"><i class="fas fa-map-marker-alt"></i></div><div><div class="rm-info-label">Location</div><div class="rm-info-value">' + locVal + '</div></div></div>';
            document.getElementById('rm-location-grid').innerHTML = locationGrid;

            // Description
            document.getElementById('rm-description').textContent = r.description || 'No description provided.';

            // Attachments
            var images = [];
            var seenPaths = new Set();
            if (r.image_path) {
                const paths = Array.isArray(r.image_path) ? r.image_path : [r.image_path];
                paths.forEach(function(p) {
                    if (p && !seenPaths.has(p)) {
                        images.push('../../' + p);
                        seenPaths.add(p);
                    }
                });
            }
            if (r.attachments) {
                try {
                    const atts = typeof r.attachments === 'string' ? JSON.parse(r.attachments) : r.attachments;
                    if (Array.isArray(atts)) {
                        atts.forEach(function(a) {
                            const path = a.file_path || a.path || a.url || (typeof a === 'string' ? a : '');
                            if (path && !seenPaths.has(path)) {
                                images.push('../../' + path);
                                seenPaths.add(path);
                            }
                        });
                    }
                } catch(e) {}
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
            timelineGrid += rmInfoItem('calendar-check', 'Created', formatDate(r.created_at));
            if (r.cimm_verified_at) {
                timelineGrid += rmInfoItem('check-circle', 'CIMM Verified', formatDate(r.cimm_verified_at));
            }
            document.getElementById('rm-timeline-grid').innerHTML = timelineGrid;

            openViewDetailsModal();
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
            }, 30000);
        }

        // Function to update specific issue types based on main category
        function updateSpecificTypes() {
            const issueType = document.getElementById('issue-type').value;
            const specificTypeLabel = document.getElementById('specific-type-label');
            const specificType = document.getElementById('specific-type');
            const transportOptions = document.getElementById('transportation-options');
            const roadOptions = document.getElementById('roads-options');
            
            // Hide all options first
            if (transportOptions) transportOptions.style.display = 'none';
            if (roadOptions) roadOptions.style.display = 'none';
            
            if (issueType === 'transportation') {
                specificTypeLabel.style.display = 'block';
                specificType.style.display = 'block';
                if (transportOptions) transportOptions.style.display = 'block';
                specificType.required = true;
            } else if (issueType === 'roads' && roadOptions) {
                specificTypeLabel.style.display = 'block';
                specificType.style.display = 'block';
                roadOptions.style.display = 'block';
                specificType.required = true;
            } else {
                specificTypeLabel.style.display = 'none';
                specificType.style.display = 'none';
                specificType.required = false;
                specificType.value = '';
            }
        }

        // Map click: place pin, show form, and run full GIS analysis
        map.on('click', function(e) {
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
                } else {
                    event.target.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            }
        };

        function closeLightbox() {
            document.getElementById('lightboxOverlay').classList.remove('show');
        }

        function viewReportUpdates(id, type, source) {
            currentUpdatesReportId = id;
            currentUpdatesReportType = type;
            currentUpdatesReportSource = source;
            document.getElementById('updateReportInfo').textContent = 'Report #' + id;
            openModal('updatesModal');
            if (typeof loadUpdates === 'function') {
                loadUpdates(id, type);
            }
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
            var role = '';
            var tag = document.getElementById('sessionTimeoutData');
            if (tag) role = tag.getAttribute('data-role') || '';
            var isOfficer = (role === 'road_monitoring_officer' || role === 'trans_monitoring_officer');

            // Non-officers use the direct Complete/Cancel path (no restriction).
            if (!isOfficer) {
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

        function showAddUpdateModal() {
            document.getElementById('addUpdateAction').value = 'create_update';
            document.getElementById('addUpdateId').value = '';
            document.getElementById('addUpdateReportId').value = currentUpdatesReportId;
            document.getElementById('addUpdateReportType').value = currentUpdatesReportType;
            document.getElementById('addUpdateSource').value = currentUpdatesReportSource || '';
            document.getElementById('addUpdateTitle').value = '';
            document.getElementById('addUpdateDescription').value = '';
            document.getElementById('updateFilePreviews').innerHTML = '';
            document.getElementById('existingUpdateMediaSection').style.display = 'none';
            document.getElementById('existingUpdateMedia').innerHTML = '';
            document.getElementById('addUpdateModalTitle').textContent = 'Add Progress Update';
            document.getElementById('addUpdateSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Post Update';
            updateSelectedFiles = [];
            updatePreviewCounter = 0;
            closeModal('updatesModal');
            openModal('addUpdateModal');
        }

        function cancelUpdateForm() {
            closeModal('addUpdateModal');
            openModal('updatesModal');
            if (typeof loadUpdates === 'function') {
                loadUpdates(currentUpdatesReportId, currentUpdatesReportType);
            }
        }

        // Override showUpdateForm from progress-updates.js to use modal
        function showUpdateForm(reportId, reportType, updateData) {
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
            const btn = document.getElementById('addUpdateSubmitBtn');
            const orig = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

            var form = document.getElementById('addUpdateForm');

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
            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: fd
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showNotification(data.message, 'success');
                    closeModal('addUpdateModal');
                    openModal('updatesModal');
                    if (typeof loadUpdates === 'function') {
                        loadUpdates(currentUpdatesReportId, currentUpdatesReportType);
                    }
                } else {
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
            if (e.target && e.target.id === 'completeBtn') {
                completeReport();
            }
        });

        // Cancel button handler
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'cancelBtn') {
                cancelReport();
            }
        });

        let isCompleting = false; // Flag to prevent multiple clicks

        function isOfficerRole() {
            var tag = document.getElementById('sessionTimeoutData');
            if (!tag) return false;
            var role = tag.getAttribute('data-role') || '';
            return (role === 'road_monitoring_officer' || role === 'trans_monitoring_officer');
        }

        // After completing/cancelling a report, reload the page in place.
        function afterStatusActionRedirect() {
            location.reload();
        }

        // Archive button on the Recent Submissions panel. Only shown for
        // reports whose status is COMPLETED — Pending, Approved, In Progress,
        // Cancelled, Rejected, and every other status hide it. Moves the
        // report into the archive keeping its current status, so it leaves
        // Recent Submissions immediately instead of waiting out the 7-day
        // auto-archive window.
        function archiveReport(id, source) {
            if (!id) return;
            if (!confirm('Archive this report? It will be moved out of Recent Submissions into the Archive, keeping its current status.')) return;

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

        function completeReport() {
            if (!currentUpdatesReportId) return;
            // Road/Transportation Monitoring Officers cannot directly complete a
            // project. Instead they submit a completion request that is routed to
            // the appropriate supervisor for review; the status is left unchanged.
            if (isOfficerRole()) {
                submitReviewRequest('completion');
                return;
            }
            if (isCompleting) return; // Prevent multiple clicks
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

            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: updateFormData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    // Now update the status
                    updateStatusOnly();
                } else {
                    throw new Error(data.message || 'Failed to add completion update');
                }
            })
            .catch(function(e) {
                showNotification('Network error', 'error');
                console.error(e);
                isCompleting = false;
            });
        }

        function updateStatusOnly() {
            // complete_status marks the report completed AND stamps a 7-day
            // auto-archive deadline (auto_archive_at) instead of moving it to
            // the archive immediately. It stays on the monitoring page so the
            // officer can still view it; the background sweep
            // (auto_archive_completed / rgmap_auto_archive_completed) moves it
            // to the archive once the deadline (completed_at + 7 days) passes.
            var statusFormData = new FormData();
            statusFormData.append('action', 'complete_status');
            statusFormData.append('report_id', currentUpdatesReportId);
            statusFormData.append('source', currentUpdatesReportSource);

            fetch('../api/progress_update_api.php', {
                method: 'POST',
                body: statusFormData
            })
            .then(function(r) { return r.json(); })
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
                showNotification('Network error', 'error');
                console.error(e);
                isCompleting = false; // Reset flag
            });
        }

        function cancelReport() {
            if (!currentUpdatesReportId) return;
            // Road/Transportation Monitoring Officers cannot directly cancel a
            // project. Instead they submit a cancellation request that is routed
            // to the appropriate supervisor for review; the status is left unchanged.
            if (isOfficerRole()) {
                submitReviewRequest('cancellation');
                return;
            }
            
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

        function exportUpdatesToExcel() {
            // Get all timeline entries
            const timelineEntries = document.querySelectorAll('.timeline-entry');
            if (timelineEntries.length === 0) {
                showNotification('No updates to export', 'error');
                return;
            }

            showNotification('Preparing document...', 'info');

            // Process images first
            processImagesAndExport(timelineEntries);
        }

        function processImagesAndExport(timelineEntries) {
            const updates = [];
            let firstDate = null;
            let lastDate = null;
            let imageLoadPromises = [];
            
            timelineEntries.forEach(function(entry) {
                const dateText = entry.querySelector('.time')?.textContent.trim() || '';
                const title = entry.querySelector('.timeline-title')?.textContent.trim() || '';
                const description = entry.querySelector('.timeline-desc')?.textContent.trim() || '';
                const author = entry.querySelector('.admin-badge')?.textContent.trim() || '';
                
                // Extract images
                const images = [];
                const mediaItems = entry.querySelectorAll('.timeline-media-item');
                mediaItems.forEach(function(media) {
                    const img = media.querySelector('img');
                    if (img && img.src) {
                        // Create promise to load and resize image
                        const imagePromise = resizeImage(img.src, 200);
                        imageLoadPromises.push(imagePromise);
                        images.push(imagePromise);
                    }
                });
                
                updates.push({
                    date: dateText,
                    title: title,
                    description: description,
                    author: author,
                    images: images
                });
                
                // Track dates for summary
                if (!firstDate) firstDate = dateText;
                lastDate = dateText;
            });

            // Wait for all images to be resized
            Promise.all(imageLoadPromises)
                .then(function(resizedImages) {
                    // Replace image promises with resized base64 strings
                    let imageIndex = 0;
                    updates.forEach(function(update) {
                        for (let i = 0; i < update.images.length; i++) {
                            update.images[i] = resizedImages[imageIndex++];
                        }
                    });
                    
                    // Now generate the document
                    generateDocument(updates, firstDate, lastDate);
                })
                .catch(function(error) {
                    console.error('Image processing error:', error);
                    // Fall back to document without images
                    generateDocument(updates, firstDate, lastDate);
                });
        }

        function resizeImage(imageSrc, maxWidth) {
            return new Promise(function(resolve, reject) {
                const img = new Image();
                img.crossOrigin = 'Anonymous';
                img.onload = function() {
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    
                    // Calculate new dimensions
                    const ratio = maxWidth / img.width;
                    const newHeight = img.height * ratio;
                    
                    canvas.width = maxWidth;
                    canvas.height = newHeight;
                    
                    // Draw resized image
                    ctx.drawImage(img, 0, 0, maxWidth, newHeight);
                    
                    // Convert to base64
                    resolve(canvas.toDataURL('image/jpeg', 0.8));
                };
                img.onerror = function() {
                    resolve(null); // Return null if image fails to load
                };
                img.src = imageSrc;
            });
        }

        function generateDocument(updates, firstDate, lastDate) {
            try {
                // Calculate summary
                const totalUpdates = updates.length;
                const timeTaken = firstDate && lastDate ? calculateDaysBetween(firstDate, lastDate) : 0;

                // Create HTML document content
                let htmlContent = `
                <html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word'>
                <head>
                <meta charset="utf-8">
                <title>Progress Updates Report</title>
                <style>
                    body { font-family: 'Calibri', Arial, sans-serif; font-size: 11pt; line-height: 1.5; }
                    h1 { color: #2E74B5; font-size: 18pt; text-align: center; margin-bottom: 20px; }
                    h2 { color: #2E74B5; font-size: 14pt; margin-top: 20px; margin-bottom: 10px; border-bottom: 2px solid #2E74B5; padding-bottom: 5px; }
                    .report-info { text-align: center; color: #666; margin-bottom: 30px; }
                    .summary-table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
                    .summary-table td { border: 1px solid #ddd; padding: 8px 12px; }
                    .summary-table td:first-child { background-color: #f8f9fa; font-weight: bold; width: 150px; }
                    .update-entry { margin-bottom: 25px; padding: 15px; background-color: #f8f9fa; border-left: 4px solid #2E74B5; }
                    .update-header { color: #2E74B5; font-weight: bold; font-size: 12pt; margin-bottom: 5px; }
                    .update-author { color: #666; font-style: italic; font-size: 10pt; margin-bottom: 10px; }
                    .update-description { margin-bottom: 10px; }
                    .update-images { margin-top: 10px; }
                    .update-images img { width: 200px; height: auto; margin: 5px; border: 1px solid #ddd; }
                    .image-count { color: #666; font-style: italic; font-size: 10pt; }
                </style>
                </head>
                <body>
                    <h1>Progress Updates Report</h1>
                    <p class="report-info">Report #${currentUpdatesReportId}</p>
                    
                    <h2>Project Summary</h2>
                    <table class="summary-table">
                        <tr><td>Start Date</td><td>${firstDate || 'N/A'}</td></tr>
                        <tr><td>End Date</td><td>${lastDate || 'N/A'}</td></tr>
                        <tr><td>Total Updates</td><td>${totalUpdates}</td></tr>
                        <tr><td>Duration</td><td>${timeTaken} days</td></tr>
                    </table>
                    
                    <h2>Progress Timeline</h2>
                `;

                // Add each update
                updates.forEach(function(update) {
                    htmlContent += `
                    <div class="update-entry">
                        <div class="update-header">${update.date} - ${update.title || 'Update'}</div>
                        <div class="update-author">By: ${update.author}</div>
                        <div class="update-description">${update.description || 'No description'}</div>
                        <div class="update-images">
                    `;
                    
                    if (update.images.length > 0) {
                        update.images.forEach(function(imgData) {
                            if (imgData) {
                                htmlContent += `<img src="${imgData}" alt="Update image" />`;
                            }
                        });
                    } else {
                        htmlContent += `<div class="image-count">No images attached</div>`;
                    }
                    
                    htmlContent += `
                        </div>
                    </div>
                    `;
                });

                htmlContent += `
                </body>
                </html>
                `;

                // Create blob and download
                const blob = new Blob(['\ufeff', htmlContent], {
                    type: 'application/msword'
                });
                
                const fileName = 'progress_updates_report_' + currentUpdatesReportId + '_' + new Date().toISOString().slice(0,10) + '.doc';
                const link = document.createElement('a');
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
                const date1 = new Date(dateStr1);
                const date2 = new Date(dateStr2);
                const diffTime = Math.abs(date2 - date1);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                return diffDays;
            } catch (e) {
                return 0;
            }
        }

        // Complete button handler
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'completeBtn') {
                completeReport();
            }
        });

        // Cancel button handler
        document.addEventListener('click', function(e) {
            if (e.target && e.target.id === 'cancelBtn') {
                cancelReport();
            }
        });

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
    let routeLayer = null, satelliteLayer = null, incidentsLayer = null;
    let evMarkersLayer = null, rangeLayer = null;
    let toolsDropdownOpen = false;
    let mapClickHandler = null;

    // Tools dropdown
    function toggleToolsDropdown() {
        const menu = document.getElementById('toolsDropdownMenu');
        toolsDropdownOpen = !toolsDropdownOpen;
        menu.style.display = toolsDropdownOpen ? 'block' : 'none';
    }
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.getElementById('toolsDropdownMenu').style.display = 'none';
            toolsDropdownOpen = false;
        }
    });

    function closePanel(panelId) {
        document.getElementById(panelId).style.display = 'none';
        if (mapClickHandler) {
            map.off('click', mapClickHandler);
            mapClickHandler = null;
        }
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
        closeAllPanels();
        document.getElementById('routePlannerPanel').style.display = 'block';
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

    // ===== SATELLITE VIEW =====
    function toggleSatelliteLayer() {
        if (satelliteLayer) {
            map.removeLayer(satelliteLayer);
            satelliteLayer = null;
            showNotification('Satellite view disabled', 'info');
            return;
        }
        satelliteLayer = L.tileLayer('https://api.tomtom.com/map/1/tile/satellite/main/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
            attribution: '© TomTom',
            maxZoom: 18
        }).addTo(map);
        showNotification('Satellite view enabled', 'success');
    }

    // ===== TRAFFIC INCIDENTS =====
    function toggleTrafficIncidentsLayer() {
        const btn = document.getElementById('toggleIncidentsBtn');
        if (incidentsLayer) {
            map.removeLayer(incidentsLayer);
            incidentsLayer = null;
            btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Traffic Incidents';
            showNotification('Traffic incidents layer disabled', 'info');
            return;
        }

        // Fetch incident data and show markers
        const center = map.getCenter();
        TomTomServices.trafficIncidents(center.lat, center.lng, 15).then(data => {
            if (data.success && data.data && data.data.incidents) {
                incidentsLayer = L.layerGroup().addTo(map);
                data.data.incidents.forEach(inc => {
                    const pos = inc.geometry?.point || inc.properties?.geometryCoordinates;
                    if (pos) {
                        const icon = L.divIcon({
                            html: '<div style="background:#ef4444;color:#fff;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;font-size:12px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.3);"><i class="fas fa-exclamation"></i></div>',
                            className: '', iconSize: [24, 24]
                        });
                        const ev = inc.properties || inc.event;
                        L.marker([pos.lat || pos.latitude, pos.lon || pos.longitude], { icon })
                            .bindPopup(`<b>${ev?.type || 'Traffic Incident'}</b><br>${ev?.description || ev?.iconCategory || ''}`)
                            .addTo(incidentsLayer);
                    }
                });
                btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Hide Incidents';
                if (data.data.incidents.length === 0) {
                    showNotification('No traffic incidents in this area', 'info');
                } else {
                    showNotification(data.data.incidents.length + ' traffic incidents found', 'info');
                }
            } else {
                // Use extended tiles as fallback
                incidentsLayer = L.tileLayer('https://api.tomtom.com/traffic/map/4/tile/incidents/absolute/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
                    attribution: '© TomTom Incidents', opacity: 0.7
                }).addTo(map);
                btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Hide Incidents';
                showNotification('Traffic incidents overlay enabled', 'success');
            }
        });
    }

    // ===== EV CHARGING STATIONS =====
    let evMarkerObjects = [];
    function showEVCharging() {
        closeAllPanels();
        document.getElementById('evChargingPanel').style.display = 'block';
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

    // ===== REACHABLE RANGE =====
    let rangeCenterPoint = null;
    function showReachableRange() {
        closeAllPanels();
        document.getElementById('reachableRangePanel').style.display = 'block';
        rangeCenterPoint = null;
        showNotification('Click on the map to set center point', 'info');
        if (mapClickHandler) map.off('click', mapClickHandler);
        mapClickHandler = function(e) {
            rangeCenterPoint = e.latlng;
            L.circleMarker(e.latlng, { color: '#3762c8', radius: 6, fillOpacity: 0.8 }).addTo(map).bindPopup('Center').openPopup();
            map.off('click', mapClickHandler);
            mapClickHandler = null;
            calcReachableRange();
        };
        map.on('click', mapClickHandler);
    }

    function calcReachableRange() {
        const center = rangeCenterPoint || map.getCenter();
        const timeMin = parseInt(document.getElementById('rangeTimeBudget').value) || 30;
        const timeSec = timeMin * 60;

        TomTomServices.reachableRange(center.lat, center.lng, { timeBudget: timeSec }).then(data => {
            const infoDiv = document.getElementById('rangeInfo');
            if (!data.success || !data.data) {
                infoDiv.style.display = 'block';
                infoDiv.innerHTML = 'Could not calculate reachable range.';
                return;
            }
            infoDiv.style.display = 'block';
            const range = data.data;
            infoDiv.innerHTML = `<strong>Reachable Range</strong><br>Time: ${timeMin} minutes`;

            // Draw reachable area polygon
            if (rangeLayer) map.removeLayer(rangeLayer);
            if (range.reachableRange && range.reachableRange.boundary) {
                const coords = range.reachableRange.boundary.map(p => [p.latitude, p.longitude]);
                if (coords.length > 0) {
                    rangeLayer = L.polygon(coords, {
                        color: '#10b981', weight: 2, fillOpacity: 0.15, fillColor: '#10b981'
                    }).addTo(map);
                    map.fitBounds(rangeLayer.getBounds().pad(0.1));
                    infoDiv.innerHTML += `<br>Area polygon drawn on map.`;
                }
            }
        });
    }

    // ===== GEOFENCING =====
    function showGeofencingTool() {
        closeAllPanels();
        document.getElementById('geofencingPanel').style.display = 'block';
        const center = map.getCenter();
        document.getElementById('geofenceLat').value = center.lat.toFixed(5);
        document.getElementById('geofenceLng').value = center.lng.toFixed(5);
    }

    function checkGeofence() {
        const lat = parseFloat(document.getElementById('geofenceLat').value);
        const lng = parseFloat(document.getElementById('geofenceLng').value);
        if (!lat || !lng) { showNotification('Enter valid coordinates', 'error'); return; }

        TomTomServices.geofenceCheck(lat, lng).then(data => {
            const infoDiv = document.getElementById('geofenceInfo');
            infoDiv.style.display = 'block';
            if (data.success && data.data) {
                const fences = data.data.fences || [];
                infoDiv.innerHTML = `<strong>Geofence Check</strong><br>
                    Location: ${lat}, ${lng}<br>
                    Fences: ${fences.length > 0 ? fences.map(f => f.name).join(', ') : 'None found'}`;
            } else {
                infoDiv.innerHTML = `<strong>Geofence Check</strong><br>
                    Location: ${lat}, ${lng}<br>
                    Status: ${data.data?.status || 'No geofences configured'}`;
            }
        });
    }

    // ===== UTILITY =====
    function closeAllPanels() {
        ['routePlannerPanel', 'reachableRangePanel', 'geofencingPanel', 'evChargingPanel'].forEach(id => {
            document.getElementById(id).style.display = 'none';
        });
        if (mapClickHandler) { map.off('click', mapClickHandler); mapClickHandler = null; }
    }

    // ===== LOAD MORE BUTTON FOR RECENT SUBMISSIONS =====
    let currentOffset = 10;
    let isLoadingMore = false;
    let hasMoreReports = true;

    let currentUserRole = '';
    const sessionRoleTag = document.getElementById('sessionTimeoutData');
    if (sessionRoleTag) currentUserRole = sessionRoleTag.getAttribute('data-role') || '';
    const isRoadSupervisor = (currentUserRole === 'road_ops_supervisor');

    function loadMoreReports() {
        if (isLoadingMore || !hasMoreReports) return;
        
        isLoadingMore = true;
        const tableBody = document.querySelector('#recentReportsTable tbody');
        const loadMoreBtn = document.getElementById('loadMoreReportsBtn');
        
        if (loadMoreBtn) {
            loadMoreBtn.disabled = true;
            loadMoreBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading...';
        }
        
        const statusFilter = document.getElementById('statusFilter').value;
        const typeFilter = document.getElementById('typeFilter').value;
        
        fetch(`../api/get_recent_submissions_paginated.php?offset=${currentOffset}&limit=10&status=${statusFilter}&type=${typeFilter}`)
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
        tr.className = 'report-table-row';
        tr.dataset.id = report.id;
        tr.dataset.title = (report.title || '').toLowerCase();
        tr.dataset.reportId = (report.report_id || '').toLowerCase();
        tr.dataset.status = report.status;
        tr.dataset.source = report.source;
        tr.dataset.details = JSON.stringify(report.details);
        
        tr.innerHTML = `
            <td style="font-family:monospace;font-size:12px;">${escapeHtml(report.report_id)}</td>
            <td>${escapeHtml(report.title)}</td>
            <td>${escapeHtml(report.source_label)}</td>
            <td><span class="badge badge-${report.status.toLowerCase().replace(' ', '-')}">${escapeHtml(ucfirst(report.status.replace('-', ' ')))}</span></td>
            <td>${report.assignment_status === 'assigned'
                ? (isRoadSupervisor && report.assignment_officer
                    ? `<span class="badge assignment-badge assignment-assigned">${escapeHtml(report.assignment_officer)}</span>`
                    : `<span class="badge assignment-badge assignment-assigned">Assigned</span>`)
                : `<span class="badge assignment-badge assignment-unassigned">Unassigned</span>`}</td>
            <td><span class="badge badge-${report.priority.toLowerCase()}">${escapeHtml(ucfirst(report.priority))}</span></td>
            <td>${formatDate(report.created_at)}</td>
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
            <td style="white-space:nowrap;">
                <button class="table-action-btn" title="View Details" onclick="viewReportDetails(${report.id}, '${report.source}')"><i class="fas fa-eye"></i></button>
                <button class="table-action-btn view-map" onclick="focusReportOnMap(${report.id})"><i class="fas fa-map-pin"></i> Map</button>
                <button class="table-action-btn" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;margin-left:4px;" onclick="viewReportUpdates(${report.id}, '${report.report_type}', '${report.source}')"><i class="fas fa-clock"></i> Updates</button>
                ${(report.status || '').toLowerCase() === 'completed' ?
                    `<button class="table-action-btn" title="Archive" style="background:linear-gradient(135deg,#6b7280,#4b5563);color:#fff;margin-left:4px;" onclick="archiveReport(${report.id}, '${report.source}')"><i class="fas fa-archive"></i> Archive</button>` : ''}
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
                <!-- Location -->
                <div class="rm-modal-section">
                    <div class="rm-modal-section-title"><i class="fas fa-map-marker-alt"></i> Location</div>
                    <div class="rm-info-grid" id="rm-location-grid"></div>
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
                <button type="button" class="rm-modal-btn-close" onclick="closeViewDetailsModal()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- Progress Updates Modal -->
    <div id="updatesModal" class="modal">
        <div class="modal-content" style="max-width: 750px;">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-clock"></i> Progress Updates</h5>
                <button class="close" onclick="closeModal('updatesModal')">&times;</button>
            </div>
            <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                <div class="timeline-container" id="updatesTimeline">
                    <div class="timeline-empty"><i class="fas fa-spinner fa-spin fa-2x t-text-link"></i></div>
                </div>
            </div>
            <div class="modal-footer" style="display: flex; flex-direction: column; gap: 16px;">
                <span id="updateReportInfo" class="t-text-secondary" style="font-size: 13px;"></span>
                <div id="actionButtons" style="display: flex; justify-content: space-between;">
                    <div style="display: flex; gap: 8px;">
                        <?php if ($is_officer_role): ?>
                        <button type="button" class="btn-success-custom" id="completeBtn">Request Completion</button>
                        <button type="button" class="btn-danger-custom" id="cancelBtn">Request Cancellation</button>
                        <?php elseif ($is_road_supervisor): ?>
                        <button type="button" class="btn-success-custom" id="completeBtn">Complete</button>
                        <button type="button" class="btn-action" id="exportWordBtn" onclick="exportUpdatesToExcel()"><i class="fas fa-file-word"></i> Export as Word</button>
                        <?php else: ?>
                        <button type="button" class="btn-success-custom" id="completeBtn">Complete</button>
                        <button type="button" class="btn-danger-custom" id="cancelBtn">Cancel</button>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="btn-action" id="addUpdateBtn" onclick="showAddUpdateModal()">+ Add Update</button>
                        <button type="button" class="btn-secondary-custom" onclick="closeModal('updatesModal')">Close</button>
                    </div>
                </div>
                <div id="exportButtons" style="display: none; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn-action" onclick="exportUpdatesToExcel()">
                        <i class="fas fa-file-excel"></i> Export
                    </button>
                    <button type="button" class="btn-secondary-custom" onclick="closeModalAndRefresh('updatesModal')">Close</button>
                </div>
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
                    <div class="form-group">
                        <label class="form-label">Title *</label>
                        <input type="text" name="title" id="addUpdateTitle" class="form-control" placeholder="e.g., Inspection completed" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Description *</label>
                        <textarea name="description" id="addUpdateDescription" class="form-control" rows="4" placeholder="Describe the progress made..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Photos / Video</label>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                            <button type="button" id="addUpdatePhotosBtn" style="padding:8px 16px;background:#3762c8;color:white;border:none;border-radius:6px;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;gap:6px;"><i class="fas fa-camera"></i> Add Photos</button>
                            <small class="t-text-secondary" style="font-size:11px;">Accepted: JPG, PNG, GIF, WebP, MP4, WebM</small>
                        </div>
                        <input type="file" name="media[]" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm" multiple style="display:none;">
                        <div class="file-previews" id="updateFilePreviews"></div>
                    </div>
                    <div id="existingUpdateMediaSection" style="display:none;">
                        <div class="form-group">
                            <label class="form-label">Current media (check to remove)</label>
                            <div id="existingUpdateMedia" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;"></div>
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
</body>
</html>

<?php
/**
 * Road Monitoring API Endpoints
 * Provides data for the Road Transportation Monitoring page
 */

require_once '../config/database.php';

// Set CORS headers
setCorsHeaders();

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = new DBHelper();
    
    switch ($method) {
        case 'GET':
            handleGetRequests($db);
            break;
        case 'POST':
            handlePostRequests($db);
            break;
        case 'PUT':
            handlePutRequests($db);
            break;
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    logError('Road Monitoring API Error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}

/**
 * Handle GET requests
 */
function handleGetRequests($db) {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'roads':
            getRoads($db);
            break;
        case 'incidents':
            getIncidents($db);
            break;
        case 'map_data':
            getMapData($db);
            break;
        case 'stats':
            getMonitoringStats($db);
            break;
        case 'alerts':
            getAlerts($db);
            break;
        case 'road_status':
            getRoadStatus($db);
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Handle POST requests
 */
function handlePostRequests($db) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_incident':
            createIncident($db);
            break;
        case 'upload_photo':
            uploadIncidentPhoto($db);
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Handle PUT requests
 */
function handlePutRequests($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'update_incident':
            updateIncident($db, $data);
            break;
        case 'update_road_status':
            updateRoadStatus($db, $data);
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Get all roads
 */
function getRoads($db) {
    $activeOnly = $_GET['active_only'] ?? 'true';
    $type = $_GET['type'] ?? null;
    
    try {
        $sql = "SELECT * FROM roads";
        $params = [];
        
        if ($activeOnly === 'true') {
            $sql .= " WHERE is_active = TRUE";
        }
        
        if ($type) {
            $sql .= ($activeOnly === 'true' ? " AND" : " WHERE") . " road_type = ?";
            $params[] = $type;
        }
        
        $sql .= " ORDER BY road_name";
        
        $db->prepare($sql);
        foreach ($params as $i => $param) {
            $db->bind($i + 1, $param);
        }
        
        $roads = $db->get();
        jsonResponse($roads);
    } catch (Exception $e) {
        logError('Error getting roads: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get roads'], 500);
    }
}

/**
 * Get incidents with filtering
 */
function getIncidents($db) {
    $status = $_GET['status'] ?? null;
    $type = $_GET['type'] ?? null;
    $severity = $_GET['severity'] ?? null;
    $roadId = $_GET['road_id'] ?? null;
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    
    try {
        $sql = "SELECT ri.*, r.road_name, r.road_type, r.location_description,
                CONCAT(su.first_name, ' ', su.last_name) as assigned_staff_name
                FROM road_incidents ri
                JOIN roads r ON ri.road_id = r.road_id
                LEFT JOIN staff_users su ON ri.assigned_staff_id = su.user_id
                WHERE 1=1";
        
        $params = [];
        $paramIndex = 1;
        
        if ($status) {
            $sql .= " AND ri.status = ?";
            $params[] = $status;
        }
        
        if ($type) {
            $sql .= " AND ri.incident_type = ?";
            $params[] = $type;
        }
        
        if ($severity) {
            $sql .= " AND ri.severity_level = ?";
            $params[] = $severity;
        }
        
        if ($roadId) {
            $sql .= " AND ri.road_id = ?";
            $params[] = $roadId;
        }
        
        $sql .= " ORDER BY ri.incident_date DESC LIMIT ? OFFSET ?";
        
        $db->prepare($sql);
        foreach ($params as $i => $param) {
            $db->bind($i + 1, $param);
        }
        $db->bind(count($params) + 1, $limit, PDO::PARAM_INT);
        $db->bind(count($params) + 2, $offset, PDO::PARAM_INT);
        
        $incidents = $db->get();
        
        // Get total count for pagination
        $countSql = "SELECT COUNT(*) as total FROM road_incidents ri WHERE 1=1";
        $countParams = [];
        
        if ($status) {
            $countSql .= " AND ri.status = ?";
            $countParams[] = $status;
        }
        
        if ($type) {
            $countSql .= " AND ri.incident_type = ?";
            $countParams[] = $type;
        }
        
        if ($severity) {
            $countSql .= " AND ri.severity_level = ?";
            $countParams[] = $severity;
        }
        
        if ($roadId) {
            $countSql .= " AND ri.road_id = ?";
            $countParams[] = $roadId;
        }
        
        $db->prepare($countSql);
        foreach ($countParams as $i => $param) {
            $db->bind($i + 1, $param);
        }
        
        $countResult = $db->single();
        $total = $countResult['total'];
        
        jsonResponse([
            'incidents' => $incidents,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    } catch (Exception $e) {
        logError('Error getting incidents: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get incidents'], 500);
    }
}

/**
 * Get map data (incidents with coordinates)
 */
function getMapData($db) {
    $filter = $_GET['filter'] ?? 'all';
    $bounds = $_GET['bounds'] ?? null;
    
    try {
        $sql = "SELECT ri.*, r.road_name, r.road_type,
                CONCAT(su.first_name, ' ', su.last_name) as assigned_staff_name
                FROM road_incidents ri
                JOIN roads r ON ri.road_id = r.road_id
                LEFT JOIN staff_users su ON ri.assigned_staff_id = su.user_id
                WHERE ri.latitude IS NOT NULL AND ri.longitude IS NOT NULL";
        
        $params = [];
        
        if ($filter !== 'all') {
            switch ($filter) {
                case 'incidents':
                    $sql .= " AND ri.incident_type IN ('accident', 'pothole', 'crack', 'erosion')";
                    break;
                case 'construction':
                    $sql .= " AND ri.incident_type IN ('construction', 'maintenance')";
                    break;
                case 'traffic':
                    $sql .= " AND ri.incident_type IN ('traffic_light', 'sign_damage')";
                    break;
            }
        }
        
        // Add geographic bounds if provided
        if ($bounds) {
            $boundsArray = explode(',', $bounds);
            if (count($boundsArray) === 4) {
                $sql .= " AND ri.latitude BETWEEN ? AND ? AND ri.longitude BETWEEN ? AND ?";
                $params[] = $boundsArray[0]; // min_lat
                $params[] = $boundsArray[1]; // max_lat
                $params[] = $boundsArray[2]; // min_lng
                $params[] = $boundsArray[3]; // max_lng
            }
        }
        
        $sql .= " AND ri.status IN ('pending', 'under_review', 'approved', 'in_progress')
                 ORDER BY ri.incident_date DESC
                 LIMIT 100";
        
        $db->prepare($sql);
        foreach ($params as $i => $param) {
            $db->bind($i + 1, $param);
        }
        
        $incidents = $db->get();
        
        // Format for map display
        $mapData = [];
        foreach ($incidents as $incident) {
            $mapData[] = [
                'id' => $incident['incident_id'],
                'lat' => (float) $incident['latitude'],
                'lng' => (float) $incident['longitude'],
                'title' => $incident['title'],
                'type' => $incident['incident_type'],
                'severity' => $incident['severity_level'],
                'status' => $incident['status'],
                'road' => $incident['road_name'],
                'description' => substr($incident['description'], 0, 100) . '...',
                'date' => $incident['incident_date'],
                'icon' => getMapIcon($incident['incident_type']),
                'color' => getSeverityColor($incident['severity_level'])
            ];
        }
        
        jsonResponse($mapData);
    } catch (Exception $e) {
        logError('Error getting map data: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get map data'], 500);
    }
}

/**
 * Get monitoring statistics
 */
function getMonitoringStats($db) {
    try {
        $stats = [];
        
        // Active roads count
        $sql = "SELECT COUNT(*) as count FROM roads WHERE is_active = TRUE";
        $db->prepare($sql);
        $result = $db->single();
        $stats['activeRoads'] = $result['count'];
        
        // Active incidents count
        $sql = "SELECT COUNT(*) as count FROM road_incidents 
                WHERE status IN ('pending', 'under_review', 'approved', 'in_progress')";
        $db->prepare($sql);
        $result = $db->single();
        $stats['activeIncidents'] = $result['count'];
        
        // Under repair count
        $sql = "SELECT COUNT(*) as count FROM maintenance_schedules 
                WHERE status = 'in_progress'";
        $db->prepare($sql);
        $result = $db->single();
        $stats['underRepair'] = $result['count'];
        
        // Clear flow percentage (roads without critical incidents)
        $sql = "SELECT COUNT(DISTINCT r.road_id) as total_roads,
                COUNT(DISTINCT CASE WHEN ri.severity_level = 'critical' THEN r.road_id END) as critical_roads
                FROM roads r
                LEFT JOIN road_incidents ri ON r.road_id = ri.road_id 
                AND ri.status IN ('pending', 'under_review', 'approved', 'in_progress')
                WHERE r.is_active = TRUE";
        $db->prepare($sql);
        $result = $db->single();
        
        $totalRoads = $result['total_roads'];
        $criticalRoads = $result['critical_roads'];
        $clearFlow = $totalRoads > 0 ? round((($totalRoads - $criticalRoads) / $totalRoads) * 100, 0) : 100;
        $stats['clearFlow'] = $clearFlow . '%';
        
        jsonResponse($stats);
    } catch (Exception $e) {
        logError('Error getting monitoring stats: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get monitoring stats'], 500);
    }
}

/**
 * Get active alerts
 */
function getAlerts($db) {
    $limit = intval($_GET['limit'] ?? 10);
    
    try {
        $sql = "SELECT ri.*, r.road_name, r.location_description,
                CONCAT(su.first_name, ' ', su.last_name) as assigned_staff_name
                FROM road_incidents ri
                JOIN roads r ON ri.road_id = r.road_id
                LEFT JOIN staff_users su ON ri.assigned_staff_id = su.user_id
                WHERE ri.severity_level IN ('high', 'critical')
                AND ri.status IN ('pending', 'under_review', 'approved', 'in_progress')
                ORDER BY ri.incident_date DESC
                LIMIT ?";
        
        $db->prepare($sql);
        $db->bind(1, $limit, PDO::PARAM_INT);
        
        $alerts = $db->get();
        
        // Format alerts
        $formattedAlerts = [];
        foreach ($alerts as $alert) {
            $formattedAlerts[] = [
                'id' => $alert['incident_id'],
                'title' => $alert['title'],
                'type' => $alert['incident_type'],
                'severity' => $alert['severity_level'],
                'road' => $alert['road_name'],
                'location' => $alert['location_description'],
                'description' => substr($alert['description'], 0, 150) . '...',
                'time' => formatTimeAgo($alert['incident_date']),
                'assigned_to' => $alert['assigned_staff_name'],
                'alert_type' => $alert['severity_level'] === 'critical' ? 'error' : 'warning'
            ];
        }
        
        jsonResponse($formattedAlerts);
    } catch (Exception $e) {
        logError('Error getting alerts: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get alerts'], 500);
    }
}

/**
 * Get road status list
 */
function getRoadStatus($db) {
    try {
        $sql = "SELECT r.*, 
                COUNT(ri.incident_id) as incident_count,
                MAX(ri.severity_level) as max_severity,
                GROUP_CONCAT(DISTINCT ri.incident_type) as incident_types
                FROM roads r
                LEFT JOIN road_incidents ri ON r.road_id = ri.road_id 
                AND ri.status IN ('pending', 'under_review', 'approved', 'in_progress')
                WHERE r.is_active = TRUE
                GROUP BY r.road_id
                ORDER BY r.road_name";
        
        $db->prepare($sql);
        $roads = $db->get();
        
        // Format road status
        $roadStatus = [];
        foreach ($roads as $road) {
            $status = getRoadConditionStatus($road['condition_rating'], $road['max_severity']);
            $traffic = getTrafficLevel($road['traffic_volume'], $road['incident_count']);
            
            $roadStatus[] = [
                'id' => $road['road_id'],
                'name' => $road['road_name'],
                'type' => $road['road_type'],
                'condition' => $road['condition_rating'],
                'status' => $status,
                'traffic' => $traffic,
                'incidents' => $road['incident_count'],
                'location' => $road['location_description'],
                'last_maintenance' => $road['last_maintenance_date'],
                'surface_type' => $road['surface_type']
            ];
        }
        
        jsonResponse($roadStatus);
    } catch (Exception $e) {
        logError('Error getting road status: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get road status'], 500);
    }
}

/**
 * Create new incident
 */
function createIncident($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        jsonResponse(['error' => 'Invalid JSON data'], 400);
    }
    
    $required = ['road_id', 'incident_type', 'severity_level', 'title', 'description'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        $incidentId = $db->createIncident($data);
        jsonResponse(['success' => true, 'incident_id' => $incidentId]);
    } catch (Exception $e) {
        logError('Error creating incident: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to create incident'], 500);
    }
}

/**
 * Upload incident photo
 */
function uploadIncidentPhoto($db) {
    if (!isset($_FILES['photo'])) {
        jsonResponse(['error' => 'No photo uploaded'], 400);
    }
    
    $incidentId = intval($_POST['incident_id'] ?? 0);
    $uploadedBy = intval($_POST['uploaded_by'] ?? 0);
    
    if ($incidentId === 0 || $uploadedBy === 0) {
        jsonResponse(['error' => 'Missing incident_id or uploaded_by'], 400);
    }
    
    $file = $_FILES['photo'];
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    
    if (!in_array($file['type'], $allowedTypes)) {
        jsonResponse(['error' => 'Invalid file type'], 400);
    }
    
    if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
        jsonResponse(['error' => 'File too large'], 400);
    }
    
    // Create upload directory if it doesn't exist
    $uploadDir = '../uploads/incidents/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique filename
    $filename = 'incident_' . $incidentId . '_' . time() . '_' . uniqid() . '.jpg';
    $filepath = $uploadDir . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        try {
            $sql = "INSERT INTO incident_photos (incident_id, photo_url, photo_description, uploaded_by, file_size, mime_type)
                    VALUES (?, ?, ?, ?, ?, ?)";
            
            $db->prepare($sql);
            $db->bind(1, $incidentId);
            $db->bind(2, '/uploads/incidents/' . $filename);
            $db->bind(3, $_POST['description'] ?? null);
            $db->bind(4, $uploadedBy);
            $db->bind(5, $file['size']);
            $db->bind(6, $file['type']);
            
            $db->execute();
            
            jsonResponse(['success' => true, 'photo_id' => $db->lastInsertId()]);
        } catch (Exception $e) {
            logError('Error saving photo record: ' . $e->getMessage());
            // Delete uploaded file if database insert failed
            unlink($filepath);
            jsonResponse(['error' => 'Failed to save photo record'], 500);
        }
    } else {
        jsonResponse(['error' => 'Failed to upload photo'], 500);
    }
}

/**
 * Update incident
 */
function updateIncident($db, $data) {
    $required = ['incident_id', 'updated_by'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        $sql = "UPDATE road_incidents SET ";
        $updates = [];
        $params = [];
        $paramIndex = 1;
        
        if (isset($data['title'])) {
            $updates[] = "title = ?";
            $params[] = $data['title'];
        }
        
        if (isset($data['description'])) {
            $updates[] = "description = ?";
            $params[] = $data['description'];
        }
        
        if (isset($data['severity_level'])) {
            $updates[] = "severity_level = ?";
            $params[] = $data['severity_level'];
        }
        
        if (isset($data['assigned_staff_id'])) {
            $updates[] = "assigned_staff_id = ?";
            $params[] = $data['assigned_staff_id'];
        }
        
        if (isset($data['estimated_repair_cost'])) {
            $updates[] = "estimated_repair_cost = ?";
            $params[] = $data['estimated_repair_cost'];
        }
        
        $updates[] = "updated_at = CURRENT_TIMESTAMP";
        
        $sql .= implode(', ', $updates);
        $sql .= " WHERE incident_id = ?";
        $params[] = $data['incident_id'];
        
        $db->prepare($sql);
        foreach ($params as $i => $param) {
            $db->bind($i + 1, $param);
        }
        
        $success = $db->execute();
        
        if ($success) {
            // Log activity
            $db->logActivity($data['updated_by'], 'UPDATE', 'road_incidents', $data['incident_id']);
            jsonResponse(['success' => true]);
        } else {
            jsonResponse(['error' => 'Failed to update incident'], 500);
        }
    } catch (Exception $e) {
        logError('Error updating incident: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to update incident'], 500);
    }
}

/**
 * Update road status
 */
function updateRoadStatus($db, $data) {
    $required = ['road_id', 'updated_by'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        $sql = "UPDATE roads SET ";
        $updates = [];
        $params = [];
        
        if (isset($data['condition_rating'])) {
            $updates[] = "condition_rating = ?";
            $params[] = $data['condition_rating'];
        }
        
        if (isset($data['traffic_volume'])) {
            $updates[] = "traffic_volume = ?";
            $params[] = $data['traffic_volume'];
        }
        
        if (isset($data['last_maintenance_date'])) {
            $updates[] = "last_maintenance_date = ?";
            $params[] = $data['last_maintenance_date'];
        }
        
        $updates[] = "updated_at = CURRENT_TIMESTAMP";
        
        $sql .= implode(', ', $updates);
        $sql .= " WHERE road_id = ?";
        $params[] = $data['road_id'];
        
        $db->prepare($sql);
        foreach ($params as $i => $param) {
            $db->bind($i + 1, $param);
        }
        
        $success = $db->execute();
        
        if ($success) {
            // Log activity
            $db->logActivity($data['updated_by'], 'UPDATE', 'roads', $data['road_id']);
            jsonResponse(['success' => true]);
        } else {
            jsonResponse(['error' => 'Failed to update road status'], 500);
        }
    } catch (Exception $e) {
        logError('Error updating road status: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to update road status'], 500);
    }
}

/**
 * Helper functions
 */
function getMapIcon($incidentType) {
    $icons = [
        'accident' => 'fa-car-crash',
        'pothole' => 'fa-road',
        'crack' => 'fa-grip-lines',
        'erosion' => 'fa-water',
        'flooding' => 'fa-water',
        'debris' => 'fa-trash',
        'traffic_light' => 'fa-traffic-light',
        'sign_damage' => 'fa-sign',
        'construction' => 'fa-tools',
        'maintenance' => 'fa-wrench'
    ];
    
    return $icons[$incidentType] ?? 'fa-exclamation-triangle';
}

function getSeverityColor($severity) {
    $colors = [
        'critical' => '#dc3545',
        'high' => '#fd7e14',
        'medium' => '#ffc107',
        'low' => '#28a745'
    ];
    
    return $colors[$severity] ?? '#6c757d';
}

function getRoadConditionStatus($condition, $maxSeverity) {
    if ($maxSeverity === 'critical') return 'critical';
    if ($maxSeverity === 'high') return 'heavy';
    if ($condition === 'poor') return 'moderate';
    if ($condition === 'fair') return 'moderate';
    return 'clear';
}

function getTrafficLevel($volume, $incidentCount) {
    if ($incidentCount > 5 || $volume === 'heavy') return 'Heavy traffic';
    if ($incidentCount > 2 || $volume === 'moderate') return 'Moderate traffic';
    return 'Light traffic';
}

function formatTimeAgo($timestamp) {
    $time = strtotime($timestamp);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        return floor($diff / 60) . ' minutes ago';
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . ' hours ago';
    } else {
        return floor($diff / 86400) . ' days ago';
    }
}

?>

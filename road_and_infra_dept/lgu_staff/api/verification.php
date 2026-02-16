<?php
/**
 * Verification System API Endpoints
 * Provides data for the Verification and Monitoring page
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
    logError('Verification API Error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}

/**
 * Handle GET requests
 */
function handleGetRequests($db) {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'requests':
            getVerificationRequests($db);
            break;
        case 'timeline':
            getVerificationTimeline($db);
            break;
        case 'workload':
            getVerificationWorkload($db);
            break;
        case 'stats':
            getVerificationStats($db);
            break;
        case 'details':
            getVerificationDetails($db);
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
        case 'create_request':
            createVerificationRequest($db);
            break;
        case 'assign_verifier':
            assignVerifier($db);
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
        case 'approve':
            approveVerification($db, $data);
            break;
        case 'reject':
            rejectVerification($db, $data);
            break;
        case 'request_info':
            requestMoreInfo($db, $data);
            break;
        case 'update_priority':
            updatePriority($db, $data);
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Get verification requests with filtering
 */
function getVerificationRequests($db) {
    $status = $_GET['status'] ?? null;
    $priority = $_GET['priority'] ?? null;
    $type = $_GET['type'] ?? null;
    $assignedTo = $_GET['assigned_to'] ?? null;
    $limit = intval($_GET['limit'] ?? 20);
    $offset = intval($_GET['offset'] ?? 0);
    
    try {
        $sql = "SELECT vr.*, ri.title as incident_title, ri.description as incident_description,
                ri.incident_type, ri.severity_level, ri.latitude, ri.longitude,
                r.road_name, r.location_description,
                CONCAT(req.first_name, ' ', req.last_name) as requested_by_name,
                CONCAT(ver.first_name, ' ', ver.last_name) as verifier_name
                FROM verification_requests vr
                JOIN road_incidents ri ON vr.incident_id = ri.incident_id
                JOIN roads r ON ri.road_id = r.road_id
                LEFT JOIN staff_users req ON vr.requested_by = req.user_id
                LEFT JOIN staff_users ver ON vr.assigned_verifier = ver.user_id
                WHERE 1=1";
        
        $params = [];
        $paramIndex = 1;
        
        if ($status) {
            $sql .= " AND vr.status = ?";
            $params[] = $status;
        }
        
        if ($priority) {
            $sql .= " AND vr.priority_level = ?";
            $params[] = $priority;
        }
        
        if ($type) {
            $sql .= " AND vr.request_type = ?";
            $params[] = $type;
        }
        
        if ($assignedTo) {
            $sql .= " AND vr.assigned_verifier = ?";
            $params[] = $assignedTo;
        }
        
        $sql .= " ORDER BY vr.priority_level DESC, vr.created_at ASC LIMIT ? OFFSET ?";
        
        $db->prepare($sql);
        foreach ($params as $i => $param) {
            $db->bind($i + 1, $param);
        }
        $db->bind(count($params) + 1, $limit, PDO::PARAM_INT);
        $db->bind(count($params) + 2, $offset, PDO::PARAM_INT);
        
        $requests = $db->get();
        
        // Get total count for pagination
        $countSql = "SELECT COUNT(*) as total FROM verification_requests vr WHERE 1=1";
        $countParams = [];
        
        if ($status) {
            $countSql .= " AND vr.status = ?";
            $countParams[] = $status;
        }
        
        if ($priority) {
            $countSql .= " AND vr.priority_level = ?";
            $countParams[] = $priority;
        }
        
        if ($type) {
            $countSql .= " AND vr.request_type = ?";
            $countParams[] = $type;
        }
        
        if ($assignedTo) {
            $countSql .= " AND vr.assigned_verifier = ?";
            $countParams[] = $assignedTo;
        }
        
        $db->prepare($countSql);
        foreach ($countParams as $i => $param) {
            $db->bind($i + 1, $param);
        }
        
        $countResult = $db->single();
        $total = $countResult['total'];
        
        // Format requests for frontend
        $formattedRequests = [];
        foreach ($requests as $request) {
            $formattedRequests[] = [
                'request_id' => $request['request_id'],
                'incident_id' => $request['incident_id'],
                'title' => $request['title'],
                'description' => $request['description'],
                'incident_title' => $request['incident_title'],
                'incident_description' => $request['incident_description'],
                'request_type' => $request['request_type'],
                'priority_level' => $request['priority_level'],
                'severity_level' => $request['severity_level'],
                'status' => $request['status'],
                'road_name' => $request['road_name'],
                'location' => $request['location_description'],
                'requested_by' => $request['requested_by_name'],
                'assigned_verifier' => $request['verifier_name'],
                'created_at' => $request['created_at'],
                'verification_date' => $request['verification_date'],
                'verification_notes' => $request['verification_notes'],
                'rejection_reason' => $request['rejection_reason'],
                'has_location' => !is_null($request['latitude']) && !is_null($request['longitude'])
            ];
        }
        
        jsonResponse([
            'requests' => $formattedRequests,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
    } catch (Exception $e) {
        logError('Error getting verification requests: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get verification requests'], 500);
    }
}

/**
 * Get verification timeline for a specific request
 */
function getVerificationTimeline($db) {
    $requestId = intval($_GET['request_id'] ?? 0);
    
    if ($requestId === 0) {
        jsonResponse(['error' => 'Missing request_id'], 400);
    }
    
    try {
        $sql = "SELECT vt.*, CONCAT(su.first_name, ' ', su.last_name) as action_by_name
                FROM verification_timeline vt
                LEFT JOIN staff_users su ON vt.action_by = su.user_id
                WHERE vt.request_id = ?
                ORDER BY vt.timestamp ASC";
        
        $db->prepare($sql);
        $db->bind(1, $requestId);
        
        $timeline = $db->get();
        
        // Format timeline
        $formattedTimeline = [];
        foreach ($timeline as $item) {
            $formattedTimeline[] = [
                'timeline_id' => $item['timeline_id'],
                'action_type' => $item['action_type'],
                'action_by' => $item['action_by_name'],
                'action_notes' => $item['action_notes'],
                'timestamp' => $item['timestamp'],
                'formatted_time' => formatTimelineTime($item['timestamp'])
            ];
        }
        
        jsonResponse($formattedTimeline);
    } catch (Exception $e) {
        logError('Error getting verification timeline: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get verification timeline'], 500);
    }
}

/**
 * Get verification workload for staff
 */
function getVerificationWorkload($db) {
    try {
        $sql = "SELECT su.user_id, su.first_name, su.last_name, su.role,
                COUNT(vr.request_id) as total_requests,
                SUM(CASE WHEN vr.status = 'pending' THEN 1 ELSE 0 END) as pending_requests,
                SUM(CASE WHEN vr.status = 'in_review' THEN 1 ELSE 0 END) as in_review_requests,
                SUM(CASE WHEN vr.status = 'approved' THEN 1 ELSE 0 END) as approved_requests,
                SUM(CASE WHEN vr.status = 'rejected' THEN 1 ELSE 0 END) as rejected_requests,
                AVG(CASE WHEN vr.verification_date IS NOT NULL 
                    THEN TIMESTAMPDIFF(HOUR, vr.created_at, vr.verification_date) 
                    ELSE NULL END) as avg_verification_hours
                FROM staff_users su
                LEFT JOIN verification_requests vr ON su.user_id = vr.assigned_verifier
                WHERE su.role IN ('supervisor', 'staff')
                GROUP BY su.user_id, su.first_name, su.last_name, su.role
                ORDER BY total_requests DESC";
        
        $db->prepare($sql);
        $workload = $db->get();
        
        // Format workload
        $formattedWorkload = [];
        foreach ($workload as $item) {
            $formattedWorkload[] = [
                'user_id' => $item['user_id'],
                'name' => $item['first_name'] . ' ' . $item['last_name'],
                'role' => $item['role'],
                'total_requests' => (int) $item['total_requests'],
                'pending_requests' => (int) $item['pending_requests'],
                'in_review_requests' => (int) $item['in_review_requests'],
                'approved_requests' => (int) $item['approved_requests'],
                'rejected_requests' => (int) $item['rejected_requests'],
                'avg_verification_hours' => round($item['avg_verification_hours'], 1),
                'efficiency_score' => calculateEfficiencyScore($item)
            ];
        }
        
        jsonResponse($formattedWorkload);
    } catch (Exception $e) {
        logError('Error getting verification workload: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get verification workload'], 500);
    }
}

/**
 * Get verification statistics
 */
function getVerificationStats($db) {
    try {
        $stats = [];
        
        // Status counts
        $sql = "SELECT status, COUNT(*) as count
                FROM verification_requests
                GROUP BY status";
        $db->prepare($sql);
        $statusCounts = $db->get();
        
        $stats['status_counts'] = [];
        foreach ($statusCounts as $item) {
            $stats['status_counts'][$item['status']] = (int) $item['count'];
        }
        
        // Priority distribution
        $sql = "SELECT priority_level, COUNT(*) as count
                FROM verification_requests
                WHERE status IN ('pending', 'in_review')
                GROUP BY priority_level";
        $db->prepare($sql);
        $priorityCounts = $db->get();
        
        $stats['priority_distribution'] = [];
        foreach ($priorityCounts as $item) {
            $stats['priority_distribution'][$item['priority_level']] = (int) $item['count'];
        }
        
        // Type distribution
        $sql = "SELECT request_type, COUNT(*) as count
                FROM verification_requests
                WHERE created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
                GROUP BY request_type
                ORDER BY count DESC";
        $db->prepare($sql);
        $typeCounts = $db->get();
        
        $stats['type_distribution'] = [];
        foreach ($typeCounts as $item) {
            $stats['type_distribution'][] = [
                'type' => $item['request_type'],
                'count' => (int) $item['count']
            ];
        }
        
        // Average processing time
        $sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, verification_date)) as avg_hours
                FROM verification_requests
                WHERE verification_date IS NOT NULL
                AND created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)";
        $db->prepare($sql);
        $result = $db->single();
        $stats['avg_processing_hours'] = round($result['avg_hours'], 1);
        
        // Today's activity
        $sql = "SELECT COUNT(*) as today_requests
                FROM verification_requests
                WHERE DATE(created_at) = CURRENT_DATE()";
        $db->prepare($sql);
        $result = $db->single();
        $stats['today_requests'] = (int) $result['today_requests'];
        
        jsonResponse($stats);
    } catch (Exception $e) {
        logError('Error getting verification stats: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get verification stats'], 500);
    }
}

/**
 * Get verification details for a specific request
 */
function getVerificationDetails($db) {
    $requestId = intval($_GET['request_id'] ?? 0);
    
    if ($requestId === 0) {
        jsonResponse(['error' => 'Missing request_id'], 400);
    }
    
    try {
        // Get main request details
        $sql = "SELECT vr.*, ri.title as incident_title, ri.description as incident_description,
                ri.incident_type, ri.severity_level, ri.latitude, ri.longitude,
                r.road_name, r.location_description, r.road_type,
                CONCAT(req.first_name, ' ', req.last_name) as requested_by_name,
                req.email as requested_by_email,
                CONCAT(ver.first_name, ' ', ver.last_name) as verifier_name
                FROM verification_requests vr
                JOIN road_incidents ri ON vr.incident_id = ri.incident_id
                JOIN roads r ON ri.road_id = r.road_id
                LEFT JOIN staff_users req ON vr.requested_by = req.user_id
                LEFT JOIN staff_users ver ON vr.assigned_verifier = ver.user_id
                WHERE vr.request_id = ?";
        
        $db->prepare($sql);
        $db->bind(1, $requestId);
        
        $request = $db->single();
        
        if (!$request) {
            jsonResponse(['error' => 'Verification request not found'], 404);
        }
        
        // Get incident photos
        $sql = "SELECT * FROM incident_photos WHERE incident_id = ? ORDER BY upload_date DESC";
        $db->prepare($sql);
        $db->bind(1, $request['incident_id']);
        $photos = $db->get();
        
        // Get timeline
        $sql = "SELECT vt.*, CONCAT(su.first_name, ' ', su.last_name) as action_by_name
                FROM verification_timeline vt
                LEFT JOIN staff_users su ON vt.action_by = su.user_id
                WHERE vt.request_id = ?
                ORDER BY vt.timestamp ASC";
        $db->prepare($sql);
        $db->bind(1, $requestId);
        $timeline = $db->get();
        
        // Format response
        $details = [
            'request' => [
                'request_id' => $request['request_id'],
                'incident_id' => $request['incident_id'],
                'title' => $request['title'],
                'description' => $request['description'],
                'request_type' => $request['request_type'],
                'priority_level' => $request['priority_level'],
                'status' => $request['status'],
                'verification_notes' => $request['verification_notes'],
                'rejection_reason' => $request['rejection_reason'],
                'created_at' => $request['created_at'],
                'verification_date' => $request['verification_date']
            ],
            'incident' => [
                'incident_id' => $request['incident_id'],
                'title' => $request['incident_title'],
                'description' => $request['incident_description'],
                'incident_type' => $request['incident_type'],
                'severity_level' => $request['severity_level'],
                'latitude' => $request['latitude'],
                'longitude' => $request['longitude'],
                'road_name' => $request['road_name'],
                'location_description' => $request['location_description'],
                'road_type' => $request['road_type']
            ],
            'requested_by' => [
                'name' => $request['requested_by_name'],
                'email' => $request['requested_by_email']
            ],
            'assigned_verifier' => $request['verifier_name'],
            'photos' => $photos,
            'timeline' => $timeline
        ];
        
        jsonResponse($details);
    } catch (Exception $e) {
        logError('Error getting verification details: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get verification details'], 500);
    }
}

/**
 * Create verification request
 */
function createVerificationRequest($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        jsonResponse(['error' => 'Invalid JSON data'], 400);
    }
    
    $required = ['incident_id', 'request_type', 'priority_level', 'title', 'description', 'requested_by'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        $sql = "INSERT INTO verification_requests 
                (incident_id, request_type, priority_level, title, description, requested_by)
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $db->prepare($sql);
        $db->bind(1, $data['incident_id']);
        $db->bind(2, $data['request_type']);
        $db->bind(3, $data['priority_level']);
        $db->bind(4, $data['title']);
        $db->bind(5, $data['description']);
        $db->bind(6, $data['requested_by']);
        
        $db->execute();
        $requestId = $db->lastInsertId();
        
        // Add timeline entry
        $sql = "INSERT INTO verification_timeline (request_id, action_type, action_by, action_notes)
                VALUES (?, 'created', ?, ?)";
        $db->prepare($sql);
        $db->bind(1, $requestId);
        $db->bind(2, $data['requested_by']);
        $db->bind(3, 'Verification request created');
        $db->execute();
        
        // Log activity
        $db->logActivity($data['requested_by'], 'INSERT', 'verification_requests', $requestId);
        
        jsonResponse(['success' => true, 'request_id' => $requestId]);
    } catch (Exception $e) {
        logError('Error creating verification request: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to create verification request'], 500);
    }
}

/**
 * Approve verification request
 */
function approveVerification($db, $data) {
    $required = ['request_id', 'approved_by', 'notes'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        $db->beginTransaction();
        
        // Update verification request
        $sql = "UPDATE verification_requests 
                SET status = 'approved', verification_date = NOW(), verification_notes = ?
                WHERE request_id = ?";
        
        $db->prepare($sql);
        $db->bind(1, $data['notes']);
        $db->bind(2, $data['request_id']);
        $db->execute();
        
        // Update incident status
        $sql = "UPDATE road_incidents 
                SET status = 'approved', updated_at = NOW()
                WHERE incident_id = (SELECT incident_id FROM verification_requests WHERE request_id = ?)";
        
        $db->prepare($sql);
        $db->bind(1, $data['request_id']);
        $db->execute();
        
        // Add timeline entry
        $sql = "INSERT INTO verification_timeline (request_id, action_type, action_by, action_notes)
                VALUES (?, 'approved', ?, ?)";
        $db->prepare($sql);
        $db->bind(1, $data['request_id']);
        $db->bind(2, $data['approved_by']);
        $db->bind(3, $data['notes']);
        $db->execute();
        
        // Log activity
        $db->logActivity($data['approved_by'], 'UPDATE', 'verification_requests', $data['request_id']);
        
        $db->commit();
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        logError('Error approving verification: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to approve verification'], 500);
    }
}

/**
 * Reject verification request
 */
function rejectVerification($db, $data) {
    $required = ['request_id', 'rejected_by', 'reason'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        $db->beginTransaction();
        
        // Update verification request
        $sql = "UPDATE verification_requests 
                SET status = 'rejected', verification_date = NOW(), rejection_reason = ?
                WHERE request_id = ?";
        
        $db->prepare($sql);
        $db->bind(1, $data['reason']);
        $db->bind(2, $data['request_id']);
        $db->execute();
        
        // Update incident status
        $sql = "UPDATE road_incidents 
                SET status = 'rejected', updated_at = NOW()
                WHERE incident_id = (SELECT incident_id FROM verification_requests WHERE request_id = ?)";
        
        $db->prepare($sql);
        $db->bind(1, $data['request_id']);
        $db->execute();
        
        // Add timeline entry
        $sql = "INSERT INTO verification_timeline (request_id, action_type, action_by, action_notes)
                VALUES (?, 'rejected', ?, ?)";
        $db->prepare($sql);
        $db->bind(1, $data['request_id']);
        $db->bind(2, $data['rejected_by']);
        $db->bind(3, $data['reason']);
        $db->execute();
        
        // Log activity
        $db->logActivity($data['rejected_by'], 'UPDATE', 'verification_requests', $data['request_id']);
        
        $db->commit();
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        $db->rollBack();
        logError('Error rejecting verification: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to reject verification'], 500);
    }
}

/**
 * Request more information
 */
function requestMoreInfo($db, $data) {
    $required = ['request_id', 'requested_by', 'notes'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        // Update verification request
        $sql = "UPDATE verification_requests 
                SET status = 'requires_more_info', verification_notes = ?
                WHERE request_id = ?";
        
        $db->prepare($sql);
        $db->bind(1, $data['notes']);
        $db->bind(2, $data['request_id']);
        $db->execute();
        
        // Add timeline entry
        $sql = "INSERT INTO verification_timeline (request_id, action_type, action_by, action_notes)
                VALUES (?, 'resubmitted', ?, ?)";
        $db->prepare($sql);
        $db->bind(1, $data['request_id']);
        $db->bind(2, $data['requested_by']);
        $db->bind(3, $data['notes']);
        $db->execute();
        
        // Log activity
        $db->logActivity($data['requested_by'], 'UPDATE', 'verification_requests', $data['request_id']);
        
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        logError('Error requesting more info: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to request more information'], 500);
    }
}

/**
 * Update priority
 */
function updatePriority($db, $data) {
    $required = ['request_id', 'priority_level', 'updated_by'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        $sql = "UPDATE verification_requests 
                SET priority_level = ?
                WHERE request_id = ?";
        
        $db->prepare($sql);
        $db->bind(1, $data['priority_level']);
        $db->bind(2, $data['request_id']);
        $db->execute();
        
        // Log activity
        $db->logActivity($data['updated_by'], 'UPDATE', 'verification_requests', $data['request_id']);
        
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        logError('Error updating priority: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to update priority'], 500);
    }
}

/**
 * Assign verifier
 */
function assignVerifier($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        jsonResponse(['error' => 'Invalid JSON data'], 400);
    }
    
    $required = ['request_id', 'assigned_verifier', 'assigned_by'];
    foreach ($required as $field) {
        if (!isset($data[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        $sql = "UPDATE verification_requests 
                SET assigned_verifier = ?, status = 'in_review'
                WHERE request_id = ?";
        
        $db->prepare($sql);
        $db->bind(1, $data['assigned_verifier']);
        $db->bind(2, $data['request_id']);
        $db->execute();
        
        // Add timeline entry
        $sql = "INSERT INTO verification_timeline (request_id, action_type, action_by, action_notes)
                VALUES (?, 'assigned', ?, ?)";
        $db->prepare($sql);
        $db->bind(1, $data['request_id']);
        $db->bind(2, $data['assigned_by']);
        $db->bind(3, 'Verifier assigned');
        $db->execute();
        
        // Log activity
        $db->logActivity($data['assigned_by'], 'UPDATE', 'verification_requests', $data['request_id']);
        
        jsonResponse(['success' => true]);
    } catch (Exception $e) {
        logError('Error assigning verifier: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to assign verifier'], 500);
    }
}

/**
 * Helper functions
 */
function formatTimelineTime($timestamp) {
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
        return date('M j, Y g:i A', $time);
    }
}

function calculateEfficiencyScore($workload) {
    $total = (int) $workload['total_requests'];
    $approved = (int) $workload['approved_requests'];
    $avgHours = $workload['avg_verification_hours'];
    
    if ($total === 0) return 100;
    
    $approvalRate = ($approved / $total) * 50; // 50% weight
    $timeScore = $avgHours <= 24 ? 50 : max(0, 50 - ($avgHours - 24)); // 50% weight
    
    return round($approvalRate + $timeScore);
}

?>

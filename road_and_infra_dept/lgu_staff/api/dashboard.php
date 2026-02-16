<?php
/**
 * Dashboard API Endpoints
 * Provides data for the LGU Staff Dashboard
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
        default:
            jsonResponse(['error' => 'Method not allowed'], 405);
    }
} catch (Exception $e) {
    logError('Dashboard API Error: ' . $e->getMessage());
    jsonResponse(['error' => 'Internal server error'], 500);
}

/**
 * Handle GET requests
 */
function handleGetRequests($db) {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'stats':
            getDashboardStats($db);
            break;
        case 'incidents':
            getIncidents($db);
            break;
        case 'activity':
            getActivity($db);
            break;
        case 'tasks':
            getTasks($db);
            break;
        case 'charts':
            getChartData($db);
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
        case 'update_status':
            updateIncidentStatus($db);
            break;
        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
}

/**
 * Get dashboard statistics
 */
function getDashboardStats($db) {
    try {
        $stats = $db->getDashboardStats();
        
        // Format the stats for frontend
        $dashboardStats = [
            'roadReportsToday' => $stats[0]['incidents_today'] ?? 0,
            'pendingVerifications' => $stats[0]['pending_verifications'] ?? 0,
            'underMaintenance' => $stats[0]['active_maintenance'] ?? 0,
            'completedThisMonth' => getCompletedThisMonth($db),
            'activeStaff' => $stats[0]['active_staff'] ?? 0
        ];
        
        jsonResponse($dashboardStats);
    } catch (Exception $e) {
        logError('Error getting dashboard stats: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get dashboard stats'], 500);
    }
}

/**
 * Get completed incidents this month
 */
function getCompletedThisMonth($db) {
    $sql = "SELECT COUNT(*) as completed 
            FROM road_incidents 
            WHERE status = 'resolved' 
            AND MONTH(resolution_date) = MONTH(CURRENT_DATE()) 
            AND YEAR(resolution_date) = YEAR(CURRENT_DATE())";
    
    $db->prepare($sql);
    $result = $db->single();
    return $result['completed'] ?? 0;
}

/**
 * Get recent incidents
 */
function getIncidents($db) {
    $limit = intval($_GET['limit'] ?? 10);
    $status = $_GET['status'] ?? null;
    
    try {
        if ($status) {
            $sql = "SELECT ri.*, r.road_name, r.location_description,
                    CONCAT(su.first_name, ' ', su.last_name) as assigned_staff_name
                    FROM road_incidents ri
                    JOIN roads r ON ri.road_id = r.road_id
                    LEFT JOIN staff_users su ON ri.assigned_staff_id = su.user_id
                    WHERE ri.status = ?
                    ORDER BY ri.incident_date DESC
                    LIMIT ?";
            
            $db->prepare($sql);
            $db->bind(1, $status);
            $db->bind(2, $limit, PDO::PARAM_INT);
        } else {
            $incidents = $db->getActiveIncidents($limit);
            jsonResponse($incidents);
            return;
        }
        
        $incidents = $db->get();
        jsonResponse($incidents);
    } catch (Exception $e) {
        logError('Error getting incidents: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get incidents'], 500);
    }
}

/**
 * Get recent activity
 */
function getActivity($db) {
    $limit = intval($_GET['limit'] ?? 10);
    
    try {
        $sql = "SELECT al.*, su.first_name, su.last_name
                FROM activity_logs al
                LEFT JOIN staff_users su ON al.user_id = su.user_id
                ORDER BY al.timestamp DESC
                LIMIT ?";
        
        $db->prepare($sql);
        $db->bind(1, $limit, PDO::PARAM_INT);
        
        $activities = $db->get();
        
        // Format activities for frontend
        $formattedActivities = [];
        foreach ($activities as $activity) {
            $formattedActivities[] = [
                'title' => formatActivityTitle($activity),
                'time' => formatTimeAgo($activity['timestamp']),
                'type' => getActivityType($activity['action_type']),
                'icon' => getActivityIcon($activity['action_type']),
                'user' => $activity['first_name'] ? $activity['first_name'] . ' ' . $activity['last_name'] : 'System'
            ];
        }
        
        jsonResponse($formattedActivities);
    } catch (Exception $e) {
        logError('Error getting activity: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get activity'], 500);
    }
}

/**
 * Get priority tasks
 */
function getTasks($db) {
    $userId = $_GET['user_id'] ?? null;
    $limit = intval($_GET['limit'] ?? 10);
    
    try {
        $sql = "SELECT vr.*, ri.title as incident_title, ri.severity_level,
                CONCAT(req.first_name, ' ', req.last_name) as requested_by_name
                FROM verification_requests vr
                JOIN road_incidents ri ON vr.incident_id = ri.incident_id
                LEFT JOIN staff_users req ON vr.requested_by = req.user_id
                WHERE vr.status IN ('pending', 'in_review')";
        
        if ($userId) {
            $sql .= " AND vr.assigned_verifier = ?";
            $db->prepare($sql);
            $db->bind(1, $userId);
        } else {
            $db->prepare($sql);
        }
        
        $sql .= " ORDER BY vr.priority_level DESC, vr.created_at ASC LIMIT ?";
        $db->bind(count($userId) ? 2 : 1, $limit, PDO::PARAM_INT);
        
        $tasks = $db->get();
        
        // Format tasks for frontend
        $formattedTasks = [];
        foreach ($tasks as $task) {
            $formattedTasks[] = [
                'id' => $task['request_id'],
                'title' => $task['title'],
                'priority' => $task['priority_level'],
                'type' => $task['request_type'],
                'severity' => $task['severity_level'],
                'requested_by' => $task['requested_by_name'],
                'created_at' => $task['created_at']
            ];
        }
        
        jsonResponse($formattedTasks);
    } catch (Exception $e) {
        logError('Error getting tasks: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get tasks'], 500);
    }
}

/**
 * Get chart data
 */
function getChartData($db) {
    $chartType = $_GET['chart'] ?? 'weekly';
    
    try {
        switch ($chartType) {
            case 'weekly':
                getWeeklyChartData($db);
                break;
            case 'incidents_by_type':
                getIncidentsByTypeChart($db);
                break;
            case 'severity_distribution':
                getSeverityDistributionChart($db);
                break;
            default:
                jsonResponse(['error' => 'Invalid chart type'], 400);
        }
    } catch (Exception $e) {
        logError('Error getting chart data: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to get chart data'], 500);
    }
}

/**
 * Get weekly chart data
 */
function getWeeklyChartData($db) {
    $sql = "SELECT 
                DATE(incident_date) as date,
                COUNT(*) as incidents,
                SUM(CASE WHEN severity_level IN ('high', 'critical') THEN 1 ELSE 0 END) as high_priority
            FROM road_incidents 
            WHERE incident_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)
            GROUP BY DATE(incident_date)
            ORDER BY date";
    
    $db->prepare($sql);
    $data = $db->get();
    
    // Format for Chart.js
    $labels = [];
    $incidentsData = [];
    $highPriorityData = [];
    
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dayName = date('D', strtotime("-$i days"));
        $labels[] = $dayName;
        
        $dayData = array_filter($data, function($item) use ($date) {
            return $item['date'] === $date;
        });
        
        if (!empty($dayData)) {
            $dayData = reset($dayData);
            $incidentsData[] = $dayData['incidents'];
            $highPriorityData[] = $dayData['high_priority'];
        } else {
            $incidentsData[] = 0;
            $highPriorityData[] = 0;
        }
    }
    
    jsonResponse([
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Road Reports',
                'data' => $incidentsData,
                'borderColor' => '#3762c8',
                'backgroundColor' => 'rgba(55, 98, 200, 0.1)',
                'tension' => 0.4,
                'fill' => true
            ],
            [
                'label' => 'High Priority',
                'data' => $highPriorityData,
                'borderColor' => '#dc3545',
                'backgroundColor' => 'rgba(220, 53, 69, 0.1)',
                'tension' => 0.4,
                'fill' => true
            ]
        ]
    ]);
}

/**
 * Get incidents by type chart data
 */
function getIncidentsByTypeChart($db) {
    $sql = "SELECT incident_type, COUNT(*) as count
            FROM road_incidents 
            WHERE incident_date >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
            GROUP BY incident_type
            ORDER BY count DESC";
    
    $db->prepare($sql);
    $data = $db->get();
    
    $labels = [];
    $counts = [];
    
    foreach ($data as $item) {
        $labels[] = ucfirst(str_replace('_', ' ', $item['incident_type']));
        $counts[] = $item['count'];
    }
    
    jsonResponse([
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Incidents by Type',
                'data' => $counts,
                'backgroundColor' => [
                    '#3762c8', '#28a745', '#ffc107', '#dc3545', 
                    '#6f42c1', '#fd7e14', '#20c997', '#6c757d'
                ]
            ]
        ]
    ]);
}

/**
 * Get severity distribution chart data
 */
function getSeverityDistributionChart($db) {
    $sql = "SELECT severity_level, COUNT(*) as count
            FROM road_incidents 
            WHERE status IN ('pending', 'under_review', 'approved', 'in_progress')
            GROUP BY severity_level
            ORDER BY 
                CASE severity_level
                    WHEN 'critical' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'low' THEN 4
                END";
    
    $db->prepare($sql);
    $data = $db->get();
    
    $labels = [];
    $counts = [];
    $colors = [];
    
    $severityColors = [
        'critical' => '#dc3545',
        'high' => '#fd7e14',
        'medium' => '#ffc107',
        'low' => '#28a745'
    ];
    
    foreach ($data as $item) {
        $labels[] = ucfirst($item['severity_level']);
        $counts[] = $item['count'];
        $colors[] = $severityColors[$item['severity_level']] ?? '#6c757d';
    }
    
    jsonResponse([
        'labels' => $labels,
        'datasets' => [
            [
                'label' => 'Severity Distribution',
                'data' => $counts,
                'backgroundColor' => $colors
            ]
        ]
    ]);
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
 * Update incident status
 */
function updateIncidentStatus($db) {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        jsonResponse(['error' => 'Invalid JSON data'], 400);
    }
    
    $required = ['incident_id', 'status', 'updated_by'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            jsonResponse(['error' => "Missing required field: $field"], 400);
        }
    }
    
    try {
        $success = $db->updateIncidentStatus(
            $data['incident_id'],
            $data['status'],
            $data['updated_by'],
            $data['notes'] ?? null
        );
        
        if ($success) {
            jsonResponse(['success' => true]);
        } else {
            jsonResponse(['error' => 'Failed to update status'], 500);
        }
    } catch (Exception $e) {
        logError('Error updating incident status: ' . $e->getMessage());
        jsonResponse(['error' => 'Failed to update status'], 500);
    }
}

/**
 * Helper functions
 */
function formatActivityTitle($activity) {
    $action = $activity['action_type'];
    $table = $activity['table_name'];
    
    switch ($action) {
        case 'INSERT':
            return "New record added to $table";
        case 'UPDATE':
            return "Record updated in $table";
        case 'DELETE':
            return "Record deleted from $table";
        case 'STATUS_UPDATE':
            return "Status updated in $table";
        default:
            return "Action performed on $table";
    }
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

function getActivityType($action) {
    switch ($action) {
        case 'INSERT':
            return 'road';
        case 'UPDATE':
        case 'STATUS_UPDATE':
            return 'verification';
        case 'DELETE':
            return 'report';
        default:
            return 'road';
    }
}

function getActivityIcon($action) {
    switch ($action) {
        case 'INSERT':
            return 'fa-plus';
        case 'UPDATE':
        case 'STATUS_UPDATE':
            return 'fa-edit';
        case 'DELETE':
            return 'fa-trash';
        default:
            return 'fa-cog';
    }
}

?>

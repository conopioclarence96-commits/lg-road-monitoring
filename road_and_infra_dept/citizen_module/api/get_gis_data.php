<?php
// get_gis_data.php - API for fetching GIS mapping data
session_start();
require_once '../../config/auth.php';
require_once '../../config/database.php';

header('Content-Type: application/json');

function sendResponse($success, $message, $data = []) {
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

// Public access - no authentication required for GIS data
try {
    $database = new Database();
    $conn = $database->getConnection();

    // Get filter parameter
    $filter = $_GET['filter'] ?? 'all';
    $bounds = $_GET['bounds'] ?? null;

    // Build base query
    $baseQuery = "
        SELECT 
            'marker' as data_type,
            marker_id as feature_id,
            latitude,
            longitude,
            marker_type as type,
            title,
            description,
            severity,
            status,
            address,
            barangay,
            images,
            properties,
            created_at
        FROM gis_map_markers
        WHERE status = 'active'
    ";

    $constructionQuery = "
        SELECT 
            'construction' as data_type,
            zone_id as feature_id,
            latitude,
            longitude,
            zone_type as type,
            zone_name as title,
            description,
            CASE 
                WHEN traffic_impact = 'severe' THEN 'high'
                WHEN traffic_impact = 'moderate' THEN 'medium'
                ELSE 'low'
            END as severity,
            status,
            NULL as address,
            NULL as barangay,
            NULL as images,
            properties,
            created_at
        FROM gis_construction_zones
        WHERE status IN ('active', 'planned')
    ";

    // Apply filters
    switch ($filter) {
        case 'issues':
            $baseQuery .= " AND marker_type IN ('damage', 'issue')";
            $constructionQuery = ""; // No construction zones for issues filter
            break;
        case 'projects':
            $baseQuery .= " AND marker_type IN ('construction', 'project')";
            break;
        case 'completed':
            $baseQuery .= " AND marker_type = 'completed'";
            $constructionQuery .= " AND status = 'completed'";
            break;
        case 'all':
        default:
            // Show all data
            break;
    }

    // Apply geographic bounds if provided
    if ($bounds) {
        $boundsArray = json_decode($bounds);
        if ($boundsArray && isset($boundsArray->_northEast) && isset($boundsArray->_southWest)) {
            $north = $boundsArray->_northEast->lat;
            $south = $boundsArray->_southWest->lat;
            $east = $boundsArray->_northEast->lng;
            $west = $boundsArray->_southWest->lng;
            
            $boundsCondition = " AND latitude BETWEEN $south AND $north AND longitude BETWEEN $west AND $east";
            $baseQuery .= $boundsCondition;
            if (!empty($constructionQuery)) {
                $constructionQuery .= $boundsCondition;
            }
        }
    }

    // Combine queries
    $fullQuery = $baseQuery;
    if (!empty($constructionQuery)) {
        $fullQuery .= " UNION ALL " . $constructionQuery;
    }
    
    $fullQuery .= " ORDER BY created_at DESC LIMIT 1000"; // Limit for performance

    $result = $conn->query($fullQuery);
    $features = [];

    while ($row = $result->fetch_assoc()) {
        // Parse JSON fields if they exist
        $images = !empty($row['images']) ? json_decode($row['images'], true) : [];
        $properties = !empty($row['properties']) ? json_decode($row['properties'], true) : [];

        $feature = [
            'type' => 'Feature',
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [(float)$row['longitude'], (float)$row['latitude']]
            ],
            'properties' => [
                'id' => $row['feature_id'],
                'data_type' => $row['data_type'],
                'type' => $row['type'],
                'title' => $row['title'],
                'description' => $row['description'],
                'severity' => $row['severity'],
                'status' => $row['status'],
                'address' => $row['address'],
                'barangay' => $row['barangay'],
                'images' => $images,
                'properties' => $properties,
                'created_at' => $row['created_at']
            ]
        ];

        $features[] = $feature;
    }

    // Get statistics
    $statsQuery = "
        SELECT 
            COUNT(*) as total_markers,
            SUM(CASE WHEN marker_type IN ('damage', 'issue') THEN 1 ELSE 0 END) as active_issues,
            SUM(CASE WHEN marker_type IN ('construction', 'project') THEN 1 ELSE 0 END) as active_projects,
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_issues
        FROM gis_map_markers
        WHERE status = 'active'
    ";

    $statsResult = $conn->query($statsQuery);
    $stats = $statsResult->fetch_assoc();

    // Get construction zones count
    $zonesQuery = "
        SELECT COUNT(*) as active_zones
        FROM gis_construction_zones
        WHERE status IN ('active', 'planned')
    ";
    $zonesResult = $conn->query($zonesQuery);
    $zones = $zonesResult->fetch_assoc();

    $statistics = [
        'total_markers' => (int)$stats['total_markers'],
        'active_issues' => (int)$stats['active_issues'],
        'active_projects' => (int)$stats['active_projects'],
        'critical_issues' => (int)$stats['critical_issues'],
        'construction_zones' => (int)$zones['active_zones']
    ];

    sendResponse(true, 'GIS data retrieved successfully', [
        'type' => 'FeatureCollection',
        'features' => $features,
        'statistics' => $statistics
    ]);

} catch (Exception $e) {
    sendResponse(false, 'System error: ' . $e->getMessage());
}
?>

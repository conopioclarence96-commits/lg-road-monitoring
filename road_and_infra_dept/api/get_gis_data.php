<?php
/**
 * API Endpoint for GIS Data
 * Provides GeoJSON data for mapping road damage reports and infrastructure
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../config/database.php';
require_once '../config/auth.php';

// Initialize database connection
try {
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit;
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';

try {
    $features = [];
    $statistics = [
        'total_markers' => 0,
        'active_issues' => 0,
        'construction_zones' => 0,
        'completed_work' => 0
    ];

    // Get damage reports and convert to GIS markers
    $damage_reports_sql = "
        SELECT 
            dr.id,
            dr.report_id,
            dr.location,
            dr.damage_type,
            dr.severity,
            dr.description,
            dr.status,
            dr.created_at,
            dr.photo_path,
            COALESCE(CONCAT(u.first_name, ' ', u.last_name), 'Unknown') as reporter_name
        FROM damage_reports dr
        LEFT JOIN users u ON dr.reported_by = u.id
        ORDER BY dr.created_at DESC
    ";
    
    $result = $conn->query($damage_reports_sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // For demo purposes, assign mock coordinates based on location
            // In production, you'd have actual latitude/longitude fields
            $coords = getMockCoordinates($row['location']);
            
            $marker_type = 'damage';
            $status = $row['status'];
            
            // Apply filter
            if ($filter !== 'all') {
                if ($filter === 'issues' && !in_array($status, ['pending', 'under_review', 'approved'])) continue;
                if ($filter === 'projects' && $marker_type !== 'construction') continue;
                if ($filter === 'completed' && $status !== 'completed') continue;
            }
            
            $feature = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$coords['lng'], $coords['lat']]
                ],
                'properties' => [
                    'id' => $row['id'],
                    'report_id' => $row['report_id'],
                    'title' => 'Road Damage: ' . ucfirst($row['damage_type']),
                    'description' => $row['description'],
                    'type' => $marker_type,
                    'severity' => $row['severity'],
                    'status' => $status,
                    'address' => $row['location'],
                    'barangay' => 'N/A', // You might have this field in your DB
                    'reporter_name' => $row['reporter_name'],
                    'created_at' => $row['created_at'],
                    'photo_path' => $row['photo_path'],
                    'data_type' => 'damage_report'
                ]
            ];
            
            $features[] = $feature;
            $statistics['total_markers']++;
            
            if (in_array($status, ['pending', 'under_review', 'approved'])) {
                $statistics['active_issues']++;
            }
            
            if ($status === 'completed') {
                $statistics['completed_work']++;
            }
        }
    }

    // Get construction zones if they exist
    $construction_sql = "
        SELECT * FROM gis_construction_zones 
        WHERE status IN ('planned', 'active')
        ORDER BY start_date DESC
    ";
    
    $result = $conn->query($construction_sql);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Apply filter
            if ($filter !== 'all' && $filter !== 'projects' && $filter !== 'all') continue;
            
            $feature = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$row['longitude'], $row['latitude']]
                ],
                'properties' => [
                    'id' => $row['id'],
                    'zone_id' => $row['zone_id'],
                    'title' => $row['zone_name'],
                    'description' => $row['description'],
                    'type' => 'construction',
                    'severity' => 'medium',
                    'status' => $row['status'],
                    'address' => $row['zone_name'],
                    'barangay' => 'N/A',
                    'start_date' => $row['start_date'],
                    'end_date' => $row['end_date'],
                    'contractor' => $row['contractor'],
                    'data_type' => 'construction_zone'
                ]
            ];
            
            $features[] = $feature;
            $statistics['total_markers']++;
            $statistics['construction_zones']++;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'type' => 'FeatureCollection',
            'features' => $features
        ],
        'statistics' => $statistics,
        'filter' => $filter
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching GIS data: ' . $e->getMessage()
    ]);
}

// Helper function to generate mock coordinates based on location
function getMockCoordinates($location) {
    // Base coordinates (Manila area as example)
    $base_lat = 14.5995;
    $base_lng = 120.9842;
    
    // Generate pseudo-random coordinates based on location string
    $hash = crc32($location);
    $lat_offset = ($hash % 200 - 100) / 10000; // ±0.01 degrees
    $lng_offset = (($hash >> 16) % 200 - 100) / 10000; // ±0.01 degrees
    
    return [
        'lat' => $base_lat + $lat_offset,
        'lng' => $base_lng + $lng_offset
    ];
}
?>

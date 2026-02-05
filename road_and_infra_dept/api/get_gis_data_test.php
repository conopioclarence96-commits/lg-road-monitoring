<?php
/**
 * API Endpoint for GIS Data - Standalone Test Version
 * Provides sample GeoJSON data for mapping when database is not available
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';

// Sample data for testing
$sampleReports = [
    [
        'id' => 1,
        'report_id' => 'RD-0001',
        'location' => 'Main Street, Barangay Central',
        'damage_type' => 'pothole',
        'severity' => 'critical',
        'description' => 'Large pothole causing traffic hazards and vehicle damage',
        'status' => 'pending',
        'created_at' => '2024-01-15 10:30:00',
        'photo_path' => '/uploads/evidence_photos/pothole1.jpg',
        'reporter_name' => 'Juan Santos'
    ],
    [
        'id' => 2,
        'report_id' => 'RD-0002',
        'location' => 'Highway 101, Barangay North',
        'damage_type' => 'crack',
        'severity' => 'medium',
        'description' => 'Surface cracks spreading across highway lane',
        'status' => 'under_review',
        'created_at' => '2024-01-14 14:20:00',
        'photo_path' => '/uploads/evidence_photos/crack1.jpg',
        'reporter_name' => 'Maria Reyes'
    ],
    [
        'id' => 3,
        'report_id' => 'RD-0003',
        'location' => 'Market Road, Barangay South',
        'damage_type' => 'flooding',
        'severity' => 'high',
        'description' => 'Severe flooding during heavy rain blocking road access',
        'status' => 'approved',
        'created_at' => '2024-01-13 09:15:00',
        'photo_path' => '/uploads/evidence_photos/flood1.jpg',
        'reporter_name' => 'Carlos Mendoza'
    ],
    [
        'id' => 4,
        'report_id' => 'RD-0004',
        'location' => 'School Zone Avenue',
        'damage_type' => 'drainage',
        'severity' => 'low',
        'description' => 'Clogged drainage causing minor water accumulation',
        'status' => 'completed',
        'created_at' => '2024-01-12 16:45:00',
        'photo_path' => '/uploads/evidence_photos/drainage1.jpg',
        'reporter_name' => 'Ana Cruz'
    ],
    [
        'id' => 5,
        'report_id' => 'RD-0005',
        'location' => 'Industrial Road',
        'damage_type' => 'landslide',
        'severity' => 'critical',
        'description' => 'Partial landslide blocking one lane of traffic',
        'status' => 'in_progress',
        'created_at' => '2024-01-11 11:30:00',
        'photo_path' => '/uploads/evidence_photos/landslide1.jpg',
        'reporter_name' => 'Roberto Diaz'
    ]
];

$features = [];
$statistics = [
    'total_markers' => 0,
    'active_issues' => 0,
    'construction_zones' => 0,
    'completed_work' => 0,
    'in_progress' => 0
];

// Base coordinates (adjust to your actual location)
$base_lat = 14.5995;
$base_lng = 120.9842;

foreach ($sampleReports as $index => $report) {
    // Apply filter
    if ($filter !== 'all') {
        if ($filter === 'issues' && !in_array($report['status'], ['pending', 'under_review', 'approved'])) continue;
        if ($filter === 'completed' && $report['status'] !== 'completed') continue;
    }
    
    // Generate coordinates based on report index
    $lat_offset = ($index % 3 - 1) * 0.02; // Spread reports around
    $lng_offset = (floor($index / 3) % 3 - 1) * 0.02;
    
    $feature = [
        'type' => 'Feature',
        'geometry' => [
            'type' => 'Point',
            'coordinates' => [$base_lng + $lng_offset, $base_lat + $lat_offset]
        ],
        'properties' => [
            'id' => $report['id'],
            'report_id' => $report['report_id'],
            'title' => 'Road Damage: ' . ucfirst($report['damage_type']),
            'description' => $report['description'],
            'type' => 'damage',
            'severity' => $report['severity'],
            'status' => $report['status'],
            'address' => $report['location'],
            'barangay' => extractBarangay($report['location']),
            'reporter_name' => $report['reporter_name'],
            'created_at' => $report['created_at'],
            'photo_path' => $report['photo_path'],
            'damage_type' => $report['damage_type'],
            'data_type' => 'damage_report'
        ]
    ];
    
    $features[] = $feature;
    $statistics['total_markers']++;
    
    if (in_array($report['status'], ['pending', 'under_review', 'approved'])) {
        $statistics['active_issues']++;
    }
    
    if ($report['status'] === 'completed') {
        $statistics['completed_work']++;
    }
    
    if ($report['status'] === 'in_progress') {
        $statistics['in_progress']++;
    }
}

echo json_encode([
    'success' => true,
    'data' => [
        'type' => 'FeatureCollection',
        'features' => $features
    ],
    'statistics' => $statistics,
    'filter' => $filter,
    'message' => 'Sample data loaded - Database connection not available'
]);

function extractBarangay($location) {
    if (strpos($location, 'Barangay') !== false) {
        $parts = explode('Barangay', $location);
        if (isset($parts[1])) {
            return 'Barangay' . trim(explode(',', $parts[1])[0]);
        }
    }
    return 'Unknown';
}
?>

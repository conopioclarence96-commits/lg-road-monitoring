<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    die('Unauthorized access');
}

$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';

// Get reports data
function get_export_reports($status_filter, $type_filter) {
    global $conn;
    
    $reports = [];
    
    // Build queries
    $transport_query = "SELECT id, title, description, location, priority, status, assigned_to, created_at, updated_at, 'transportation' as report_type FROM road_transportation_reports";
    $maintenance_query = "SELECT id, title, description, location, priority, status, maintenance_team as assigned_to, created_at, updated_at, 'maintenance' as report_type FROM road_maintenance_reports";
    
    $where_conditions = [];
    $params = [];
    
    if ($status_filter !== 'all') {
        $where_conditions[] = "status = ?";
        $params[] = $status_filter;
    }
    
    if ($type_filter !== 'all') {
        if ($type_filter === 'transportation') {
            $transport_query .= " WHERE " . implode(' AND ', $where_conditions);
            $maintenance_query = "SELECT NULL FROM road_maintenance_reports WHERE 1=0";
        } else {
            $transport_query = "SELECT NULL FROM road_transportation_reports WHERE 1=0";
            $maintenance_query .= " WHERE " . implode(' AND ', $where_conditions);
        }
    } elseif (!empty($where_conditions)) {
        $transport_query .= " WHERE " . implode(' AND ', $where_conditions);
        $maintenance_query .= " WHERE " . implode(' AND ', $where_conditions);
    }
    
    $transport_query .= " ORDER BY created_at DESC";
    $maintenance_query .= " ORDER BY created_at DESC";
    
    // Execute queries
    if (!empty($params)) {
        $stmt = $conn->prepare($transport_query);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $transport_reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $stmt = $conn->prepare($maintenance_query);
        $stmt->bind_param(str_repeat('s', count($params)), ...$params);
        $stmt->execute();
        $maintenance_reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $transport_reports = fetch_all($transport_query);
        $maintenance_reports = fetch_all($maintenance_query);
    }
    
    // Combine and sort
    $all_reports = array_merge($transport_reports ?: [], $maintenance_reports ?: []);
    usort($all_reports, function($a, $b) {
        return strtotime($b['created_at']) - strtotime($a['created_at']);
    });
    
    return $all_reports;
}

$reports = get_export_reports($status_filter, $type_filter);

// Prepare CSV data
$headers = ['ID', 'Title', 'Description', 'Location', 'Type', 'Priority', 'Status', 'Assigned To', 'Created At', 'Updated At'];
$csv_data = [];

foreach ($reports as $report) {
    $csv_data[] = [
        $report['id'],
        $report['title'],
        strip_tags($report['description']),
        $report['location'],
        ucfirst($report['report_type']),
        ucfirst($report['priority']),
        ucfirst(str_replace('_', ' ', $report['status'])),
        $report['assigned_to'] ?? 'Not assigned',
        format_date($report['created_at']),
        $report['updated_at'] ? format_date($report['updated_at']) : 'Not updated'
    ];
}

// Generate filename
$filename = 'reports_export_' . date('Y-m-d_H-i-s') . '.csv';
if ($status_filter !== 'all') $filename = str_replace('.csv', "_{$status_filter}.csv", $filename);
if ($type_filter !== 'all') $filename = str_replace('.csv', "_{$type_filter}.csv", $filename);

// Export to CSV
export_to_csv($csv_data, $filename, $headers);
?>

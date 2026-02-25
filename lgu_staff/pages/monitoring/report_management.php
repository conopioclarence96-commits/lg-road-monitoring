<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    redirect('../../login.php');
}

// Get user information
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'citizen';

// Get user details for reporting
$user_info = fetch_one("SELECT username, full_name, email FROM users WHERE id = ?", [$user_id], "i");
if (!$user_info) {
    $user_info = ['username' => 'Staff', 'full_name' => 'LGU Staff', 'email' => 'staff@lgu.gov.ph'];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!verify_csrf_token($csrf_token)) {
        set_flash_message('error', 'Invalid CSRF token');
        header('Location: ../monitoring/report_management.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'receive_report':
            handle_receive_report();
            break;
        case 'update_report':
            handle_update_report();
            break;
        case 'delete_report':
            handle_delete_report();
            break;
        case 'accept_external_report':
            handle_accept_external_report();
            break;
        case 'accept_department_report':
            handle_accept_department_report();
            break;
    }
}

function handle_receive_report() {
    global $conn, $user_id;
    
    $report_type = sanitize_input($_POST['report_type'] ?? '');
    $title = sanitize_input($_POST['title'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');
    $location = sanitize_input($_POST['location'] ?? '');
    $priority = sanitize_input($_POST['priority'] ?? 'medium');
    $latitude = floatval($_POST['latitude'] ?? 0);
    $longitude = floatval($_POST['longitude'] ?? 0);
    
    // Validation
    $errors = validate_required([
        'report_type' => $report_type,
        'title' => $title,
        'description' => $description,
        'location' => $location
    ]);
    
    if (!empty($errors)) {
        set_flash_message('error', 'Please fill in all required fields');
        return;
    }
    
    // Insert into appropriate table
    if ($report_type === 'transportation') {
        $report_id = generate_unique_id('RTR-');
        $department = 'engineering'; // Default department
        $stmt = $conn->prepare("INSERT INTO road_transportation_reports (report_id, report_type, title, department, priority, status, created_date, description, location, latitude, longitude, reporter_name, reporter_email, created_by, created_at) VALUES (?, 'infrastructure_issue', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("sssssdssssi", $report_id, $title, $department, $priority, $description, $location, $latitude, $longitude, $user_info['full_name'], $user_info['email'], $user_id);
    } else {
        $report_id = generate_unique_id('MNT-');
        $department = 'maintenance'; // Default department
        $stmt = $conn->prepare("INSERT INTO road_maintenance_reports (report_id, report_type, title, department, priority, status, created_date, description, location, created_by, created_at) VALUES (?, 'emergency', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssi", $report_id, $title, $department, $priority, $description, $location, $user_id);
    }
    
    if ($stmt->execute()) {
        log_audit_action($user_id, "Received {$report_type} report", "Title: {$title}, Location: {$location}");
        set_flash_message('success', 'Report received successfully');
    } else {
        set_flash_message('error', 'Failed to receive report: ' . $conn->error);
    }
}

function handle_update_report() {
    global $conn, $user_id;
    
    $report_id = intval($_POST['report_id'] ?? 0);
    $report_type = sanitize_input($_POST['report_type'] ?? '');
    $status = sanitize_input($_POST['status'] ?? '');
    $priority = sanitize_input($_POST['priority'] ?? '');
    $assigned_to = sanitize_input($_POST['assigned_to'] ?? '');
    $estimation = floatval($_POST['estimation'] ?? 0);
    $notes = sanitize_input($_POST['notes'] ?? '');
    
    if ($report_id <= 0 || empty($report_type) || empty($status)) {
        set_flash_message('error', 'Invalid report data');
        return;
    }
    
    // Update the report
    $table = ($report_type === 'transportation') ? 'road_transportation_reports' : 'road_maintenance_reports';
    
    // Check if estimation column exists
    $estimation_column_exists = false;
    $result = $conn->query("SHOW COLUMNS FROM {$table} LIKE 'estimation'");
    if ($result && $result->num_rows > 0) {
        $estimation_column_exists = true;
    }
    
    if ($report_type === 'transportation') {
        if ($estimation_column_exists) {
            $stmt = $conn->prepare("UPDATE {$table} SET status = ?, priority = ?, assigned_to = ?, estimation = ?, resolution_notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sssssi", $status, $priority, $assigned_to, $estimation, $notes, $report_id);
        } else {
            $stmt = $conn->prepare("UPDATE {$table} SET status = ?, priority = ?, assigned_to = ?, resolution_notes = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ssssi", $status, $priority, $assigned_to, $notes, $report_id);
        }
    } else {
        if ($estimation_column_exists) {
            $stmt = $conn->prepare("UPDATE {$table} SET status = ?, priority = ?, maintenance_team = ?, estimation = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("ssssi", $status, $priority, $assigned_to, $estimation, $report_id);
        } else {
            $stmt = $conn->prepare("UPDATE {$table} SET status = ?, priority = ?, maintenance_team = ?, updated_at = NOW() WHERE id = ?");
            $stmt->bind_param("sssi", $status, $priority, $assigned_to, $report_id);
        }
    }
    
    if ($stmt->execute()) {
        log_audit_action($user_id, "Updated {$report_type} report", "Report ID: {$report_id}, New Status: {$status}, Estimation: ₱" . number_format($estimation, 2));
        set_flash_message('success', 'Report updated successfully');
        
        // Return JSON response for AJAX requests
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Report updated successfully']);
            exit;
        }
    } else {
        set_flash_message('error', 'Failed to update report: ' . $conn->error);
        
        // Return JSON response for AJAX requests
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to update report: ' . $conn->error]);
            exit;
        }
    }
}

function handle_delete_report() {
    global $conn, $user_id;
    
    $report_id = intval($_POST['report_id'] ?? 0);
    $report_type = sanitize_input($_POST['report_type'] ?? '');
    
    if ($report_id <= 0 || empty($report_type)) {
        set_flash_message('error', 'Invalid report data');
        return;
    }
    
    // Get report info for logging
    $table = ($report_type === 'transportation') ? 'road_transportation_reports' : 'road_maintenance_reports';
    $stmt = $conn->prepare("SELECT title, location FROM {$table} WHERE id = ?");
    $stmt->bind_param("i", $report_id);
    $stmt->execute();
    $report_info = $stmt->get_result()->fetch_assoc();
    
    // Delete the report
    $stmt = $conn->prepare("DELETE FROM {$table} WHERE id = ?");
    $stmt->bind_param("i", $report_id);
    
    if ($stmt->execute()) {
        $report_title = $report_info['title'] ?? 'Unknown Report';
        log_audit_action($user_id, "Deleted {$report_type} report", "Report ID: {$report_id}, Title: {$report_title}");
    } else {
        set_flash_message('error', 'Failed to delete report: ' . $conn->error);
    }
}

function handle_accept_external_report() {
    global $conn, $user_id;
    
    $external_id = sanitize_input($_POST['report_id'] ?? '');
    $title = sanitize_input($_POST['title'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');
    $location = sanitize_input($_POST['location'] ?? '');
    $priority = sanitize_input($_POST['priority'] ?? 'medium');
    $report_type = sanitize_input($_POST['report_type'] ?? '');
    $source = sanitize_input($_POST['source'] ?? '');
    
    if (empty($title) || empty($description) || empty($location)) {
        set_flash_message('error', 'Missing required report data');
        return;
    }
    
    // Insert into appropriate table
    if ($report_type === 'transportation') {
        $report_id = generate_unique_id('RTR-');
        $department = 'engineering';
        $stmt = $conn->prepare("INSERT INTO road_transportation_reports (report_id, report_type, title, department, priority, status, created_date, description, location, reporter_name, reporter_email, created_by, created_at) VALUES (?, 'infrastructure_issue', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssssi", $report_id, $title, $department, $priority, $description, $location, $source, $source, $user_id);
    } else {
        $report_id = generate_unique_id('MNT-');
        $department = 'maintenance';
        $stmt = $conn->prepare("INSERT INTO road_maintenance_reports (report_id, report_type, title, department, priority, status, created_date, description, location, created_by, created_at) VALUES (?, 'emergency', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssi", $report_id, $title, $department, $priority, $description, $location, $user_id);
    }
    
    if ($stmt->execute()) {
        log_audit_action($user_id, "Accepted external report", "External ID: {$external_id}, Title: {$title}, Source: {$source}");
        set_flash_message('success', 'External report accepted and added to system');
    } else {
        set_flash_message('error', 'Failed to accept external report: ' . $conn->error);
    }
}

function handle_accept_department_report() {
    global $conn, $user_id;
    
    $dept_id = sanitize_input($_POST['report_id'] ?? '');
    $title = sanitize_input($_POST['title'] ?? '');
    $description = sanitize_input($_POST['description'] ?? '');
    $location = sanitize_input($_POST['location'] ?? '');
    $priority = sanitize_input($_POST['priority'] ?? 'medium');
    $report_type = sanitize_input($_POST['report_type'] ?? '');
    $source = sanitize_input($_POST['source'] ?? '');
    
    if (empty($title) || empty($description) || empty($location)) {
        set_flash_message('error', 'Missing required report data');
        return;
    }
    
    // Insert into appropriate table
    if ($report_type === 'transportation') {
        $report_id = generate_unique_id('RTR-');
        $department = 'engineering';
        $stmt = $conn->prepare("INSERT INTO road_transportation_reports (report_id, report_type, title, department, priority, status, created_date, description, location, reporter_name, reporter_email, created_by, created_at) VALUES (?, 'infrastructure_issue', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssssi", $report_id, $title, $department, $priority, $description, $location, $source, $source, $user_id);
    } else {
        $report_id = generate_unique_id('MNT-');
        $department = 'maintenance';
        $stmt = $conn->prepare("INSERT INTO road_maintenance_reports (report_id, report_type, title, department, priority, status, created_date, description, location, created_by, created_at) VALUES (?, 'emergency', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, NOW())");
        $stmt->bind_param("ssssssi", $report_id, $title, $department, $priority, $description, $location, $user_id);
    }
    
    if ($stmt->execute()) {
        log_audit_action($user_id, "Accepted department report", "Dept ID: {$dept_id}, Title: {$title}, Source: {$source}");
        set_flash_message('success', 'Department report accepted and added to system');
    } else {
        set_flash_message('error', 'Failed to accept department report: ' . $conn->error);
    }
}

// Get reports for display
function get_reports($status_filter = 'all', $type_filter = 'all', $limit = 50, $offset = 0) {
    global $conn;
    
    $reports = [];
    
    // Check if estimation column exists
    $transport_estimation_exists = false;
    $maintenance_estimation_exists = false;
    
    $result = $conn->query("SHOW COLUMNS FROM road_transportation_reports LIKE 'estimation'");
    if ($result && $result->num_rows > 0) {
        $transport_estimation_exists = true;
    }
    
    $result = $conn->query("SHOW COLUMNS FROM road_maintenance_reports LIKE 'estimation'");
    if ($result && $result->num_rows > 0) {
        $maintenance_estimation_exists = true;
    }
    
    // Get transportation reports
    if ($transport_estimation_exists) {
        $transport_query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, estimation, resolution_notes as notes, department, created_date, created_at, updated_at, 'transportation' as report_type FROM road_transportation_reports";
    } else {
        $transport_query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, assigned_to, 0 as estimation, resolution_notes as notes, department, created_date, created_at, updated_at, 'transportation' as report_type FROM road_transportation_reports";
    }
    $transport_params = [];
    
    // Get maintenance reports
    if ($maintenance_estimation_exists) {
        $maintenance_query = "SELECT id, report_id, title, description, location, priority, status, maintenance_team as assigned_to, estimation, department, created_date, created_at, updated_at, 'maintenance' as report_type FROM road_maintenance_reports";
    } else {
        $maintenance_query = "SELECT id, report_id, title, description, location, priority, status, maintenance_team as assigned_to, 0 as estimation, department, created_date, created_at, updated_at, 'maintenance' as report_type FROM road_maintenance_reports";
    }
    $maintenance_params = [];
    
    // Apply filters
    $where_conditions = [];
    
    $status_filter = $_GET['status'] ?? 'all';
    $type_filter = $_GET['type'] ?? 'all';
    
    if ($status_filter !== 'all') {
        $where_conditions[] = "status = ?";
        $params[] = $status_filter;
    }
    
    if ($type_filter !== 'all') {
        if ($type_filter === 'transportation') {
            $transport_query .= " WHERE " . implode(' AND ', $where_conditions);
            $maintenance_query = "SELECT NULL FROM road_maintenance_reports WHERE 1=0"; // Empty result
        } else {
            $transport_query = "SELECT NULL FROM road_transportation_reports WHERE 1=0"; // Empty result
            $maintenance_query .= " WHERE " . implode(' AND ', $where_conditions);
        }
    } elseif (!empty($where_conditions)) {
        $transport_query .= " WHERE " . implode(' AND ', $where_conditions);
        $maintenance_query .= " WHERE " . implode(' AND ', $where_conditions);
        $transport_params = $params;
        $maintenance_params = $params;
    }
    
    $transport_query .= " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
    $maintenance_query .= " ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
    
    // Execute queries
    if (!empty($transport_params)) {
        $stmt = $conn->prepare($transport_query);
        $stmt->bind_param(str_repeat('s', count($transport_params)), ...$transport_params);
        $stmt->execute();
        $transport_reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        
        $stmt = $conn->prepare($maintenance_query);
        $stmt->bind_param(str_repeat('s', count($maintenance_params)), ...$maintenance_params);
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
    
    return array_slice($all_reports, 0, $limit);
}

// Get report statistics
function get_report_stats() {
    global $conn;
    
    $stats = [
        'total_reports' => 0,
        'pending_reports' => 0,
        'in_progress_reports' => 0,
        'completed_reports' => 0,
        'high_priority_reports' => 0
    ];
    
    // Transportation stats
    $transport_stats = fetch_one("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_priority_count
        FROM road_transportation_reports");
    
    // Maintenance stats
    $maintenance_stats = fetch_one("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_priority_count
        FROM road_maintenance_reports");
    
    if ($transport_stats) {
        $stats['total_reports'] += $transport_stats['total'];
        $stats['pending_reports'] += $transport_stats['pending'];
        $stats['in_progress_reports'] += $transport_stats['in_progress'];
        $stats['completed_reports'] += $transport_stats['completed'];
        $stats['high_priority_reports'] += $transport_stats['high_priority_count'];
    }
    
    if ($maintenance_stats) {
        $stats['total_reports'] += $maintenance_stats['total'];
        $stats['pending_reports'] += $maintenance_stats['pending'];
        $stats['in_progress_reports'] += $maintenance_stats['in_progress'];
        $stats['completed_reports'] += $maintenance_stats['completed'];
        $stats['high_priority_reports'] += $maintenance_stats['high_priority_count'];
    }
    
    return $stats;
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filters
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';

// Get data
$reports = get_reports($status_filter, $type_filter, $per_page, $offset);
$stats = get_report_stats();
$csrf_token = generate_csrf_token();
$flash_message = get_flash_message();

// Debug: Check if estimation values are present
if (!empty($reports)) {
    foreach ($reports as $index => $report) {
        if (isset($report['estimation']) && $report['estimation'] > 0) {
            // Found a report with estimation
            error_log("Report ID {$report['id']} has estimation: {$report['estimation']}");
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Management - LGU Road Monitoring</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            background: url("../../../assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
            min-height: 100vh;
        }
        
        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(6px);
            background: rgba(0, 0, 0, 0.35);
            z-index: -1;
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

        .dashboard-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 30px;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .welcome-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .welcome-text h1 {
            color: #1e3c72;
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .welcome-text p {
            color: #666;
            font-size: 16px;
        }

        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.7));
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3762c8, #1e3c72);
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(55, 98, 200, 0.2);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-bottom: 15px;
        }

        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
            font-weight: 500;
        }

        .main-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .chart-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
        }

        .report-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .report-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3762c8, #1e3c72);
        }

        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(55, 98, 200, 0.2);
        }

        .priority-high .report-card::before {
            background: linear-gradient(90deg, #dc3545, #c82333);
        }

        .priority-medium .report-card::before {
            background: linear-gradient(90deg, #ffc107, #e0a800);
        }

        .priority-low .report-card::before {
            background: linear-gradient(90deg, #28a745, #1e7e34);
        }

        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .report-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 5px;
        }

        .report-meta {
            font-size: 12px;
            color: #666;
            line-height: 1.4;
        }

        .report-description {
            color: #333;
            font-size: 14px;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .report-actions {
            display: flex;
            gap: 10px;
            opacity: 0.7;
            transition: opacity 0.2s;
        }

        .report-card:hover .report-actions {
            opacity: 1;
        }

        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-view {
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
        }

        .btn-view:hover {
            background: #3762c8;
            color: white;
        }

        .btn-edit {
            background: rgba(255, 193, 7, 0.1);
            color: #ffc107;
        }

        .btn-edit:hover {
            background: #ffc107;
            color: white;
        }

        .btn-delete {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .btn-delete:hover {
            background: #dc3545;
            color: white;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.2);
            color: #856404;
        }

        .status-in-progress {
            background: rgba(23, 162, 184, 0.2);
            color: #0c5460;
        }

        .status-completed {
            background: rgba(40, 167, 69, 0.2);
            color: #155724;
        }

        .filters-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin-bottom: 30px;
        }

        .filter-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            min-width: 150px;
        }

        .btn-primary-custom {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }

        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(55, 98, 200, 0.3);
        }

        .btn-secondary-custom {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
            border: 1px solid #6c757d;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-secondary-custom:hover {
            background: #6c757d;
            color: white;
        }

        .btn-success-custom {
            background: rgba(40, 167, 69, 0.1);
            color: #28a745;
            border: 1px solid #28a745;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-success-custom:hover {
            background: #28a745;
            color: white;
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
            margin: 5% auto;
            padding: 0;
            border-radius: 16px;
            width: 90%;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(100px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
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

        .close:hover {
            opacity: 0.7;
        }

        .modal-body {
            padding: 25px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.1);
        }

        select.form-control {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%23373c72'%3e%3cpath d='M7 10l5 5 5-5z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 0.7rem center;
            background-size: 1.2em;
            padding-right: 2.5rem;
        }

        select.form-control option {
            background: white;
            color: #333;
        }

        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .form-section h6 {
            color: #1e3c72;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .modal-footer {
            padding: 20px 25px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: none;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: #155724;
            border-left: 4px solid #28a745;
        }

        .alert-error {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 10px;
            }
            
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .filter-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .report-actions {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <iframe src="../../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0"
            name="sidebar-frame"
            scrolling="no"
            loading="lazy"
            referrerpolicy="no-referrer">
    </iframe>

    <div class="main-content">
        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-section">
                <div class="welcome-text">
                    <h1><i class="fas fa-clipboard-data"></i> Report Management</h1>
                    <p>Receive, update, and monitor road reports all in one place</p>
                </div>
                <div>
                    <button class="btn-primary-custom" onclick="openModal('receivedReportsModal')">
                        <i class="fas fa-inbox"></i> View Received Reports
                    </button>
                </div>
            </div>
        </div>

        <!-- Flash Message -->
        <?php if ($flash_message): ?>
            <div class="alert alert-<?php echo $flash_message['type']; ?>">
                <?php echo htmlspecialchars($flash_message['message']); ?>
                <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="quick-stats">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['total_reports']); ?></div>
                <div class="stat-label">Total Reports</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['pending_reports']); ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-cogs"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['in_progress_reports']); ?></div>
                <div class="stat-label">In Progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['completed_reports']); ?></div>
                <div class="stat-label">Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['high_priority_reports']); ?></div>
                <div class="stat-label">High Priority</div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <div class="chart-header">
                <h3 class="chart-title">Filters</h3>
            </div>
            <div class="filter-group">
                <div>
                    <label class="form-label">Status Filter</label>
                    <select class="filter-select" id="statusFilter" onchange="filterReports()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Report Type</label>
                    <select class="filter-select" id="typeFilter" onchange="filterReports()">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                        <option value="transportation" <?php echo $type_filter === 'transportation' ? 'selected' : ''; ?>>Transportation</option>
                        <option value="maintenance" <?php echo $type_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button class="btn-secondary-custom" onclick="resetFilters()">
                            <i class="fas fa-arrow-clockwise"></i> Reset
                        </button>
                        <button class="btn-success-custom" onclick="exportReports()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reports List -->
        <div class="main-grid">
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Reports</h3>
                    <span class="text-muted"><?php echo count($reports); ?> reports found</span>
                </div>
                
                <?php if (empty($reports)): ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                        <p>No reports found matching your criteria.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($reports as $report): ?>
                        <div class="report-card priority-<?php echo $report['priority']; ?>">
                            <div class="report-header">
                                <div>
                                    <div class="report-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                    <div class="report-meta">
                                        <?php if (!empty($report['report_id'])): ?>
                                        <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($report['report_id']); ?> • 
                                        <?php endif; ?>
                                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($report['department'] ?? 'Not specified'); ?> • 
                                        <i class="fas fa-tag"></i> <?php echo ucfirst($report['report_type']); ?> • 
                                        <i class="fas fa-geo-alt"></i> <?php echo htmlspecialchars($report['location']); ?> • 
                                        <i class="fas fa-flag"></i> Priority: <?php echo ucfirst($report['priority']); ?> • 
                                        <?php if (!empty($report['estimation']) && isset($report['estimation']) && $report['estimation'] > 0): ?>
                                        <i class="fas fa-peso-sign"></i> Estimation: ₱<?php echo number_format($report['estimation'], 2); ?> • 
                                        <?php else: ?>
                                        <i class="fas fa-peso-sign" style="opacity: 0.3;"></i> No Estimation • 
                                        <?php endif; ?>
                                        <i class="fas fa-clock"></i> <?php echo format_datetime($report['created_at']); ?>
                                    </div>
                                </div>
                                <span class="status-badge status-<?php echo str_replace('_', '-', $report['status']); ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $report['status'])); ?>
                                </span>
                            </div>
                            
                            <div class="report-description">
                                <?php echo htmlspecialchars($report['description']); ?>
                            </div>
                            
                            <?php if (!empty($report['assigned_to'])): ?>
                            <div class="assignment-info" style="margin-bottom: 10px; padding: 8px 12px; background: rgba(55, 98, 200, 0.1); border-radius: 6px; border-left: 3px solid #3762c8;">
                                <i class="fas fa-user-hard-hat"></i> 
                                <strong>Assigned to:</strong> <?php echo htmlspecialchars($report['assigned_to']); ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="report-actions">
                                <button class="btn-action btn-view" onclick="viewReport(<?php echo $report['id']; ?>, '<?php echo $report['report_type']; ?>')">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                <button class="btn-action btn-edit" onclick="editReport(<?php echo $report['id']; ?>, '<?php echo $report['report_type']; ?>')">
                                    <i class="fas fa-pencil"></i> Edit
                                </button>
                                <button class="btn-action btn-delete" onclick="deleteReport(<?php echo $report['id']; ?>, '<?php echo $report['report_type']; ?>')">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Received Reports Modal -->
    <div id="receivedReportsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reports Received from Other Systems</h5>
                <button class="close" onclick="closeModal('receivedReportsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-section">
                    <h6>External System Reports</h6>
                    <div id="externalReportsList">
                        <div style="text-align: center; padding: 20px; color: #666;">
                            <i class="fas fa-download" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                            <p>Loading reports from external systems...</p>
                        </div>
                    </div>
                </div>
                
                <div class="form-section">
                    <h6>Department Reports</h6>
                    <div id="departmentReportsList">
                        <div style="text-align: center; padding: 20px; color: #666;">
                            <i class="fas fa-building" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                            <p>Loading reports from other departments...</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary-custom" onclick="closeModal('receivedReportsModal')">Close</button>
                <button type="button" class="btn-primary-custom" onclick="refreshReceivedReports()">
                    <i class="fas fa-sync"></i> Refresh
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Report Modal -->
    <div id="editReportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Report</h5>
                <button class="close" onclick="closeModal('editReportModal')">&times;</button>
            </div>
            <form method="POST" id="editReportForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="update_report">
                    <input type="hidden" name="report_id" id="editReportId">
                    <input type="hidden" name="report_type" id="editReportType">
                    
                    <div style="display: flex; gap: 15px;">
                        <div class="form-group" style="flex: 1;">
                            <label for="editStatus" class="form-label">Status *</label>
                            <select class="form-control" name="status" id="editStatus" required>
                                <option value="pending">Pending</option>
                                <option value="in-progress">In Progress</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex: 1;">
                            <label for="editPriority" class="form-label">Priority *</label>
                            <select class="form-control" name="priority" id="editPriority" required>
                                <option value="low">Low</option>
                                <option value="medium">Medium</option>
                                <option value="high">High</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="editAssignedTo" class="form-label">Assign To *</label>
                        <select class="form-control" name="assigned_to" id="editAssignedTo" required>
                            <option value="">Select Assignee</option>
                            <option value="CIM Engineer 1">CIM Engineer 1</option>
                            <option value="CIM Engineer 2">CIM Engineer 2</option>
                            <option value="CIM Engineer 3">CIM Engineer 3</option>
                            <option value="CIM Engineer 4">CIM Engineer 4</option>
                            <option value="CIM Engineer 5">CIM Engineer 5</option>
                            <option value="Maintenance Team">Maintenance Team</option>
                            <option value="Road Inspector">Road Inspector</option>
                            <option value="Project Manager">Project Manager</option>
                        </select>
                        <small style="color: #666; font-size: 12px;">Select the team member responsible for this report</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="editEstimation" class="form-label">Cost Estimation (₱)</label>
                        <input type="number" class="form-control" name="estimation" id="editEstimation" placeholder="Enter estimated cost" min="0" step="1000">
                        <small style="color: #666; font-size: 12px;">Provide cost estimation for this project</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="editNotes" class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="editNotes" rows="4" placeholder="Add any notes or updates..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" onclick="closeModal('editReportModal')">Cancel</button>
                    <button type="submit" class="btn-primary-custom">Update Report</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Report Modal -->
    <div id="viewReportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Report Details</h5>
                <button class="close" onclick="closeModal('viewReportModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewReportContent">
                <!-- Content will be loaded dynamically -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary-custom" onclick="closeModal('viewReportModal')">Close</button>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        }

        function filterReports() {
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            url.searchParams.set('type', type);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        function resetFilters() {
            const url = new URL(window.location);
            url.searchParams.delete('status');
            url.searchParams.delete('type');
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }

        function exportReports() {
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;
            const url = `../api/export_reports.php?status=${status}&type=${type}`;
            window.open(url, '_blank');
        }

        function viewReport(id, type) {
            fetch(`../api/get_report_details.php?id=${id}&type=${type}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const content = document.getElementById('viewReportContent');
                        content.innerHTML = `
                            <div style="line-height: 1.6;">
                                <h6 style="color: #1e3c72; margin-bottom: 15px;">${data.report.title}</h6>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                                    ${data.report.report_id ? `<div><strong>Report ID:</strong> ${data.report.report_id}</div>` : ''}
                                    ${data.report.department ? `<div><strong>Department:</strong> ${data.report.department}</div>` : ''}
                                    <div><strong>Type:</strong> ${data.report.report_type}</div>
                                    <div><strong>Status:</strong> <span class="status-badge status-${data.report.status.replace('_', '-')}">${data.report.status}</span></div>
                                    <div><strong>Priority:</strong> ${data.report.priority}</div>
                                    <div><strong>Location:</strong> ${data.report.location}</div>
                                    ${data.report.estimation && data.report.estimation > 0 ? `<div><strong>Cost Estimation:</strong> ₱${parseFloat(data.report.estimation).toLocaleString('en-PH', {minimumFractionDigits: 2})}</div>` : '<div><strong>Cost Estimation:</strong> Not specified</div>'}
                                </div>
                                <div style="margin-bottom: 20px;">
                                    <strong>Description:</strong>
                                    <p style="margin-top: 5px;">${data.report.description}</p>
                                </div>
                                ${data.report.assigned_to ? `<div style="margin-bottom: 10px;"><strong>Assigned To:</strong> ${data.report.assigned_to}</div>` : ''}
                                ${data.report.notes ? `<div style="margin-bottom: 10px;"><strong>Notes:</strong> ${data.report.notes}</div>` : ''}
                                ${data.report.reporter_name ? `<div style="margin-bottom: 10px;"><strong>Reporter:</strong> ${data.report.reporter_name}</div>` : ''}
                                <div style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #eee; font-size: 12px; color: #666;">
                                    <div><strong>Created:</strong> ${data.report.created_at}</div>
                                    <div><strong>Updated:</strong> ${data.report.updated_at || 'Not updated'}</div>
                                </div>
                            </div>
                        `;
                        openModal('viewReportModal');
                    } else {
                        showNotification('Failed to load report details', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error loading report details', 'error');
                });
        }

        function editReport(id, type) {
            fetch(`../api/get_report_details.php?id=${id}&type=${type}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('editReportId').value = data.report.id;
                        document.getElementById('editReportType').value = data.report.report_type;
                        document.getElementById('editStatus').value = data.report.status;
                        document.getElementById('editPriority').value = data.report.priority;
                        
                        // Auto-assign based on priority if no assignment exists
                        const assignedToSelect = document.getElementById('editAssignedTo');
                        if (!data.report.assigned_to || data.report.assigned_to === '') {
                            const priority = data.report.priority;
                            let autoAssign = '';
                            
                            if (priority === 'high') {
                                autoAssign = 'CIM Engineer 1'; // Senior engineer for high priority
                            } else if (priority === 'medium') {
                                autoAssign = 'CIM Engineer 2'; // Mid-level engineer for medium priority
                            } else {
                                autoAssign = 'CIM Engineer 3'; // Junior engineer for low priority
                            }
                            
                            assignedToSelect.value = autoAssign;
                        } else {
                            assignedToSelect.value = data.report.assigned_to;
                        }
                        
                        document.getElementById('editEstimation').value = data.report.estimation || '';
                        document.getElementById('editNotes').value = data.report.notes || '';
                        openModal('editReportModal');
                    } else {
                        showNotification('Failed to load report details', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Error loading report details', 'error');
                });
        }

        function deleteReport(id, type) {
            if (confirm('Are you sure you want to delete this report? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="delete_report">
                    <input type="hidden" name="report_id" value="${id}">
                    <input type="hidden" name="report_type" value="${type}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function refreshReceivedReports() {
            loadExternalReports();
            loadDepartmentReports();
        }

        function loadExternalReports() {
            // Simulate loading reports from external systems
            const externalReports = [
                {
                    id: 'EXT-001',
                    title: 'Traffic Accident on Highway 1',
                    source: 'Traffic Management System',
                    priority: 'high',
                    status: 'pending',
                    received_at: '2024-02-24 10:30:00',
                    description: 'Multi-vehicle accident reported on Highway 1 near KM 45'
                },
                {
                    id: 'EXT-002', 
                    title: 'Road Damage Report',
                    source: 'Citizen Reporting App',
                    priority: 'medium',
                    status: 'pending',
                    received_at: '2024-02-24 09:15:00',
                    description: 'Large pothole reported on Main Street causing traffic disruption'
                }
            ];

            const container = document.getElementById('externalReportsList');
            if (externalReports.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #666;">
                        <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                        <p>No reports received from external systems</p>
                    </div>
                `;
            } else {
                container.innerHTML = externalReports.map(report => `
                    <div class="report-card" style="margin-bottom: 15px; border-left: 4px solid ${getPriorityColor(report.priority)};">
                        <div style="display: flex; justify-content: between; align-items: flex-start; margin-bottom: 10px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #1e3c72; margin-bottom: 5px;">${report.title}</div>
                                <div style="font-size: 12px; color: #666;">
                                    <i class="fas fa-download"></i> ${report.source} • 
                                    <i class="fas fa-clock"></i> ${report.received_at} • 
                                    <i class="fas fa-flag"></i> Priority: ${report.priority}
                                </div>
                            </div>
                            <span class="status-badge status-${report.status.replace('_', '-')}">${report.status}</span>
                        </div>
                        <div style="color: #333; font-size: 14px; margin-bottom: 10px;">${report.description}</div>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn-action btn-view" onclick="acceptExternalReport('${report.id}')">
                                <i class="fas fa-check"></i> Accept
                            </button>
                            <button class="btn-action btn-edit" onclick="reviewExternalReport('${report.id}')">
                                <i class="fas fa-eye"></i> Review
                            </button>
                        </div>
                    </div>
                `).join('');
            }
        }

        function loadDepartmentReports() {
            // Simulate loading reports from other departments
            const departmentReports = [
                {
                    id: 'DEPT-001',
                    title: 'Bridge Inspection Required',
                    source: 'Engineering Department',
                    priority: 'high',
                    status: 'pending',
                    received_at: '2024-02-24 11:00:00',
                    description: 'Quarterly bridge inspection scheduled for City Bridge #3'
                },
                {
                    id: 'DEPT-002',
                    title: 'Street Light Maintenance',
                    source: 'Public Works Department',
                    priority: 'low',
                    status: 'pending',
                    received_at: '2024-02-24 08:45:00',
                    description: 'Routine maintenance request for street lights in District 2'
                }
            ];

            const container = document.getElementById('departmentReportsList');
            if (departmentReports.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #666;">
                        <i class="fas fa-building" style="font-size: 48px; margin-bottom: 15px; opacity: 0.5;"></i>
                        <p>No reports received from other departments</p>
                    </div>
                `;
            } else {
                container.innerHTML = departmentReports.map(report => `
                    <div class="report-card" style="margin-bottom: 15px; border-left: 4px solid ${getPriorityColor(report.priority)};">
                        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #1e3c72; margin-bottom: 5px;">${report.title}</div>
                                <div style="font-size: 12px; color: #666;">
                                    <i class="fas fa-building"></i> ${report.source} • 
                                    <i class="fas fa-clock"></i> ${report.received_at} • 
                                    <i class="fas fa-flag"></i> Priority: ${report.priority}
                                </div>
                            </div>
                            <span class="status-badge status-${report.status.replace('_', '-')}">${report.status}</span>
                        </div>
                        <div style="color: #333; font-size: 14px; margin-bottom: 10px;">${report.description}</div>
                        <div style="display: flex; gap: 10px;">
                            <button class="btn-action btn-view" onclick="acceptDepartmentReport('${report.id}')">
                                <i class="fas fa-check"></i> Accept
                            </button>
                            <button class="btn-action btn-edit" onclick="reviewDepartmentReport('${report.id}')">
                                <i class="fas fa-eye"></i> Review
                            </button>
                        </div>
                    </div>
                `).join('');
            }
        }

        function getPriorityColor(priority) {
            switch(priority) {
                case 'high': return '#dc3545';
                case 'medium': return '#ffc107';
                case 'low': return '#28a745';
                default: return '#6c757d';
            }
        }

        function acceptExternalReport(reportId) {
            if (confirm('Accept this external report and add it to the system?')) {
                // Find the report data
                const externalReports = [
                    {
                        id: 'EXT-001',
                        title: 'Traffic Accident on Highway 1',
                        source: 'Traffic Management System',
                        priority: 'high',
                        status: 'pending',
                        received_at: '2024-02-24 10:30:00',
                        description: 'Multi-vehicle accident reported on Highway 1 near KM 45',
                        location: 'Highway 1, KM 45',
                        report_type: 'transportation'
                    },
                    {
                        id: 'EXT-002', 
                        title: 'Road Damage Report',
                        source: 'Citizen Reporting App',
                        priority: 'medium',
                        status: 'pending',
                        received_at: '2024-02-24 09:15:00',
                        description: 'Large pothole reported on Main Street causing traffic disruption',
                        location: 'Main Street',
                        report_type: 'maintenance'
                    }
                ];
                
                const report = externalReports.find(r => r.id === reportId);
                if (report) {
                    // Submit the accepted report to the server
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="accept_external_report">
                        <input type="hidden" name="report_id" value="${report.id}">
                        <input type="hidden" name="title" value="${report.title}">
                        <input type="hidden" name="description" value="${report.description}">
                        <input type="hidden" name="location" value="${report.location}">
                        <input type="hidden" name="priority" value="${report.priority}">
                        <input type="hidden" name="report_type" value="${report.report_type}">
                        <input type="hidden" name="source" value="${report.source}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }

        function acceptDepartmentReport(reportId) {
            if (confirm('Accept this department report and add it to the system?')) {
                // Find the report data
                const departmentReports = [
                    {
                        id: 'DEPT-001',
                        title: 'Bridge Inspection Required',
                        source: 'Engineering Department',
                        priority: 'high',
                        status: 'pending',
                        received_at: '2024-02-24 11:00:00',
                        description: 'Quarterly bridge inspection scheduled for City Bridge #3',
                        location: 'City Bridge #3',
                        report_type: 'maintenance'
                    },
                    {
                        id: 'DEPT-002',
                        title: 'Street Light Maintenance',
                        source: 'Public Works Department',
                        priority: 'low',
                        status: 'pending',
                        received_at: '2024-02-24 08:45:00',
                        description: 'Routine maintenance request for street lights in District 2',
                        location: 'District 2',
                        report_type: 'maintenance'
                    }
                ];
                
                const report = departmentReports.find(r => r.id === reportId);
                if (report) {
                    // Submit the accepted report to the server
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.innerHTML = `
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="accept_department_report">
                        <input type="hidden" name="report_id" value="${report.id}">
                        <input type="hidden" name="title" value="${report.title}">
                        <input type="hidden" name="description" value="${report.description}">
                        <input type="hidden" name="location" value="${report.location}">
                        <input type="hidden" name="priority" value="${report.priority}">
                        <input type="hidden" name="report_type" value="${report.report_type}">
                        <input type="hidden" name="source" value="${report.source}">
                    `;
                    document.body.appendChild(form);
                    form.submit();
                }
            }
        }

        function reviewExternalReport(reportId) {
            showNotification('Opening external report details...', 'info');
            // In a real implementation, this would open a detailed view modal
        }

        function reviewDepartmentReport(reportId) {
            showNotification('Opening department report details...', 'info');
            // In a real implementation, this would open a detailed view modal
        }

        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `alert alert-${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                z-index: 10001;
                min-width: 300px;
                padding: 15px 20px;
                border-radius: 8px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                animation: slideIn 0.3s ease;
            `;
            
            // Set styles based on type
            switch(type) {
                case 'success':
                    notification.style.background = 'rgba(40, 167, 69, 0.9)';
                    notification.style.color = 'white';
                    notification.style.borderLeft = '4px solid #28a745';
                    break;
                case 'error':
                    notification.style.background = 'rgba(220, 53, 69, 0.9)';
                    notification.style.color = 'white';
                    notification.style.borderLeft = '4px solid #dc3545';
                    break;
                case 'info':
                    notification.style.background = 'rgba(23, 162, 184, 0.9)';
                    notification.style.color = 'white';
                    notification.style.borderLeft = '4px solid #17a2b8';
                    break;
                default:
                    notification.style.background = 'rgba(108, 117, 125, 0.9)';
                    notification.style.color = 'white';
                    notification.style.borderLeft = '4px solid #6c757d';
            }
            
            notification.innerHTML = `
                ${message}
                <button type="button" style="background: none; border: none; color: white; float: right; font-size: 16px; cursor: pointer;" onclick="this.parentElement.remove()">&times;</button>
            `;
            
            document.body.appendChild(notification);
            
            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (notification.parentElement) {
                    notification.remove();
                }
            }, 5000);
        }

        // Auto-load reports when modal opens
        const originalOpenModal = openModal;
        openModal = function(modalId) {
            originalOpenModal(modalId);
            if (modalId === 'receivedReportsModal') {
                setTimeout(() => {
                    loadExternalReports();
                    loadDepartmentReports();
                }, 100);
            }
        }

        // Handle edit report form submission
        document.getElementById('editReportForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
            submitBtn.disabled = true;
            
            fetch('', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                
                if (data.success) {
                    showNotification('Report updated successfully', 'success');
                    closeModal('editReportModal');
                    
                    // Refresh the report list to show updated estimation
                    setTimeout(() => {
                        location.reload(); // Simple page reload to show updated data
                    }, 1000);
                } else {
                    showNotification(data.message || 'Failed to update report', 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Error updating report', 'error');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
    </script>
</body>
</html>

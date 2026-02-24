<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!is_logged_in()) {
    redirect('../login.php');
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
        header('Location: report_management.php');
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
        $stmt->bind_param("ssssddssssi", $report_id, $title, $department, $priority, $description, $location, $latitude, $longitude, $user_info['full_name'], $user_info['email'], $user_id);
    } else {
        $report_id = generate_unique_id('MNT-');
        $department = 'maintenance'; // Default department
        $stmt = $conn->prepare("INSERT INTO road_maintenance_reports (report_id, report_type, title, department, priority, status, created_date, description, location, created_by, created_at) VALUES (?, 'emergency', ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, NOW())");
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
    $notes = sanitize_input($_POST['notes'] ?? '');
    
    if ($report_id <= 0 || empty($report_type) || empty($status)) {
        set_flash_message('error', 'Invalid report data');
        return;
    }
    
    // Update the report
    $table = ($report_type === 'transportation') ? 'road_transportation_reports' : 'road_maintenance_reports';
    
    if ($report_type === 'transportation') {
        $stmt = $conn->prepare("UPDATE {$table} SET status = ?, priority = ?, assigned_to = ?, resolution_notes = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("ssssi", $status, $priority, $assigned_to, $notes, $report_id);
    } else {
        $stmt = $conn->prepare("UPDATE {$table} SET status = ?, priority = ?, maintenance_team = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param("sssi", $status, $priority, $assigned_to, $report_id);
    }
    
    if ($stmt->execute()) {
        log_audit_action($user_id, "Updated {$report_type} report", "Report ID: {$report_id}, New Status: {$status}");
        set_flash_message('success', 'Report updated successfully');
    } else {
        set_flash_message('error', 'Failed to update report: ' . $conn->error);
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
        log_audit_action($user_id, "Deleted {$report_type} report", "Report ID: {$report_id}, Title: {$report_info['title']}");
        set_flash_message('success', 'Report deleted successfully');
    } else {
        set_flash_message('error', 'Failed to delete report: ' . $conn->error);
    }
}

// Get reports for display
function get_reports($status_filter = 'all', $type_filter = 'all', $limit = 50, $offset = 0) {
    global $conn;
    
    $reports = [];
    
    // Get transportation reports
    $transport_query = "SELECT id, title, description, location, latitude, longitude, priority, status, assigned_to, resolution_notes as notes, created_at, updated_at, 'transportation' as report_type FROM road_transportation_reports";
    $transport_params = [];
    
    // Get maintenance reports
    $maintenance_query = "SELECT id, title, description, location, priority, status, maintenance_team as assigned_to, created_at, updated_at, 'maintenance' as report_type FROM road_maintenance_reports";
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
        SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_priority
        FROM road_transportation_reports");
    
    // Maintenance stats
    $maintenance_stats = fetch_one("SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'in-progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN priority = 'high' THEN 1 ELSE 0 END) as high_priority
        FROM road_maintenance_reports");
    
    if ($transport_stats) {
        $stats['total_reports'] += $transport_stats['total'];
        $stats['pending_reports'] += $transport_stats['pending'];
        $stats['in_progress_reports'] += $transport_stats['in_progress'];
        $stats['completed_reports'] += $transport_stats['completed'];
        $stats['high_priority_reports'] += $transport_stats['high_priority'];
    }
    
    if ($maintenance_stats) {
        $stats['total_reports'] += $maintenance_stats['total'];
        $stats['pending_reports'] += $maintenance_stats['pending'];
        $stats['in_progress_reports'] += $maintenance_stats['in_progress'];
        $stats['completed_reports'] += $maintenance_stats['completed'];
        $stats['high_priority_reports'] += $maintenance_stats['high_priority'];
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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Management - LGU Road Monitoring</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stats-card h3 {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0;
        }
        .stats-card p {
            margin: 0;
            opacity: 0.9;
        }
        .report-card {
            border-left: 4px solid #007bff;
            transition: transform 0.2s;
        }
        .report-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .priority-high {
            border-left-color: #dc3545;
        }
        .priority-medium {
            border-left-color: #ffc107;
        }
        .priority-low {
            border-left-color: #28a745;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .report-image {
            max-width: 100px;
            max-height: 100px;
            object-fit: cover;
            border-radius: 8px;
        }
        .action-buttons {
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        .report-card:hover .action-buttons {
            opacity: 1;
        }
        .modal-body {
            max-height: 70vh;
            overflow-y: auto;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="bi bi-clipboard-data"></i> Report Management</h2>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#receiveReportModal">
                        <i class="bi bi-plus-circle"></i> Receive New Report
                    </button>
                </div>

                <!-- Flash Message -->
                <?php if ($flash_message): ?>
                    <div class="alert alert-<?php echo $flash_message['type']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($flash_message['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-2">
                        <div class="stats-card">
                            <h3><?php echo $stats['total_reports']; ?></h3>
                            <p>Total Reports</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stats-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            <h3><?php echo $stats['pending_reports']; ?></h3>
                            <p>Pending</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stats-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <h3><?php echo $stats['in_progress_reports']; ?></h3>
                            <p>In Progress</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stats-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                            <h3><?php echo $stats['completed_reports']; ?></h3>
                            <p>Completed</p>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="stats-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                            <h3><?php echo $stats['high_priority_reports']; ?></h3>
                            <p>High Priority</p>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <label for="statusFilter" class="form-label">Status Filter</label>
                                <select class="form-select" id="statusFilter" onchange="filterReports()">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="typeFilter" class="form-label">Report Type</label>
                                <select class="form-select" id="typeFilter" onchange="filterReports()">
                                    <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="transportation" <?php echo $type_filter === 'transportation' ? 'selected' : ''; ?>>Transportation</option>
                                    <option value="maintenance" <?php echo $type_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">&nbsp;</label>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-outline-secondary" onclick="resetFilters()">
                                        <i class="bi bi-arrow-clockwise"></i> Reset
                                    </button>
                                    <button class="btn btn-success" onclick="exportReports()">
                                        <i class="bi bi-download"></i> Export
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reports List -->
                <div class="row">
                    <?php if (empty($reports)): ?>
                        <div class="col-12">
                            <div class="alert alert-info text-center">
                                <i class="bi bi-info-circle"></i> No reports found matching your criteria.
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($reports as $report): ?>
                            <div class="col-md-6 col-lg-4 mb-3">
                                <div class="card report-card priority-<?php echo $report['priority']; ?>">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="card-title mb-0"><?php echo htmlspecialchars($report['title']); ?></h6>
                                            <span class="badge status-badge bg-<?php 
                                                echo $report['status'] === 'pending' ? 'warning' : 
                                                    ($report['status'] === 'in_progress' ? 'info' : 'success'); 
                                                ?> text-dark">
                                                <?php echo ucfirst(str_replace('_', ' ', $report['status'])); ?>
                                            </span>
                                        </div>
                                        
                                        <p class="card-text small text-muted mb-2">
                                            <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($report['location']); ?><br>
                                            <i class="bi bi-tag"></i> <?php echo ucfirst($report['report_type']); ?><br>
                                            <i class="bi bi-flag"></i> Priority: <?php echo ucfirst($report['priority']); ?><br>
                                            <i class="bi bi-clock"></i> <?php echo format_datetime($report['created_at']); ?>
                                        </p>
                                        
                                        <p class="card-text small">
                                            <?php echo substr(htmlspecialchars($report['description']), 0, 100) . '...'; ?>
                                        </p>
                                        
                                        <div class="action-buttons d-flex gap-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="viewReport(<?php echo $report['id']; ?>, '<?php echo $report['report_type']; ?>')">
                                                <i class="bi bi-eye"></i> View
                                            </button>
                                            <button class="btn btn-sm btn-outline-warning" onclick="editReport(<?php echo $report['id']; ?>, '<?php echo $report['report_type']; ?>')">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <button class="btn btn-sm btn-outline-danger" onclick="deleteReport(<?php echo $report['id']; ?>, '<?php echo $report['report_type']; ?>')">
                                                <i class="bi bi-trash"></i> Delete
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Receive Report Modal -->
    <div class="modal fade" id="receiveReportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Receive New Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="receive_report">
                        
                        <div class="form-section">
                            <h6>Report Information</h6>
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="reportType" class="form-label">Report Type *</label>
                                    <select class="form-select" name="report_type" id="reportType" required>
                                        <option value="">Select Type</option>
                                        <option value="transportation">Transportation</option>
                                        <option value="maintenance">Maintenance</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="priority" class="form-label">Priority *</label>
                                    <select class="form-select" name="priority" id="priority" required>
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h6>Details</h6>
                            <div class="mb-3">
                                <label for="title" class="form-label">Title *</label>
                                <input type="text" class="form-control" name="title" id="title" required>
                            </div>
                            <div class="mb-3">
                                <label for="description" class="form-label">Description *</label>
                                <textarea class="form-control" name="description" id="description" rows="4" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label for="location" class="form-label">Location *</label>
                                <input type="text" class="form-control" name="location" id="location" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="latitude" class="form-label">Latitude</label>
                                    <input type="number" step="any" class="form-control" name="latitude" id="latitude">
                                </div>
                                <div class="col-md-6">
                                    <label for="longitude" class="form-label">Longitude</label>
                                    <input type="number" step="any" class="form-control" name="longitude" id="longitude">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Receive Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Report Modal -->
    <div class="modal fade" id="editReportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Update Report</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editReportForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="update_report">
                        <input type="hidden" name="report_id" id="editReportId">
                        <input type="hidden" name="report_type" id="editReportType">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <label for="editStatus" class="form-label">Status *</label>
                                <select class="form-select" name="status" id="editStatus" required>
                                    <option value="pending">Pending</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="completed">Completed</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="editPriority" class="form-label">Priority *</label>
                                <select class="form-select" name="priority" id="editPriority" required>
                                    <option value="low">Low</option>
                                    <option value="medium">Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <label for="editAssignedTo" class="form-label">Assigned To</label>
                            <input type="text" class="form-control" name="assigned_to" id="editAssignedTo" placeholder="Enter assignee name">
                        </div>
                        
                        <div class="mt-3">
                            <label for="editNotes" class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" id="editNotes" rows="4" placeholder="Add any notes or updates..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Report Modal -->
    <div class="modal fade" id="viewReportModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Report Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="viewReportContent">
                    <!-- Content will be loaded dynamically -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
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
            const url = `export_reports.php?status=${status}&type=${type}`;
            window.open(url, '_blank');
        }

        function viewReport(id, type) {
            fetch(`get_report_details.php?id=${id}&type=${type}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const content = document.getElementById('viewReportContent');
                        content.innerHTML = `
                            <div class="row">
                                <div class="col-md-12">
                                    <h6>${data.report.title}</h6>
                                    <p><strong>Type:</strong> ${data.report.report_type}</p>
                                    <p><strong>Status:</strong> <span class="badge bg-${getStatusColor(data.report.status)}">${data.report.status}</span></p>
                                    <p><strong>Priority:</strong> ${data.report.priority}</p>
                                    <p><strong>Location:</strong> ${data.report.location}</p>
                                    <p><strong>Description:</strong></p>
                                    <p>${data.report.description}</p>
                                    ${data.report.assigned_to ? `<p><strong>Assigned To:</strong> ${data.report.assigned_to}</p>` : ''}
                                    ${data.report.notes ? `<p><strong>Notes:</strong> ${data.report.notes}</p>` : ''}
                                    ${data.report.reporter_name ? `<p><strong>Reporter:</strong> ${data.report.reporter_name}</p>` : ''}
                                    <p><small><strong>Created:</strong> ${data.report.created_at}</small></p>
                                    <p><small><strong>Updated:</strong> ${data.report.updated_at || 'Not updated'}</small></p>
                                </div>
                            </div>
                        `;
                        new bootstrap.Modal(document.getElementById('viewReportModal')).show();
                    } else {
                        alert('Failed to load report details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading report details');
                });
        }

        function editReport(id, type) {
            fetch(`get_report_details.php?id=${id}&type=${type}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('editReportId').value = data.report.id;
                        document.getElementById('editReportType').value = data.report.report_type;
                        document.getElementById('editStatus').value = data.report.status;
                        document.getElementById('editPriority').value = data.report.priority;
                        document.getElementById('editAssignedTo').value = data.report.assigned_to || '';
                        document.getElementById('editNotes').value = data.report.notes || '';
                        new bootstrap.Modal(document.getElementById('editReportModal')).show();
                    } else {
                        alert('Failed to load report details');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error loading report details');
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

        function getStatusColor(status) {
            switch(status) {
                case 'pending': return 'warning';
                case 'in_progress': return 'info';
                case 'completed': return 'success';
                default: return 'secondary';
            }
        }

        // Auto-refresh reports every 30 seconds
        setInterval(() => {
            const currentUrl = new URL(window.location);
            if (currentUrl.searchParams.has('auto_refresh')) {
                window.location.reload();
            }
        }, 30000);
    </script>
</body>
</html>

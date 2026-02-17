<?php
// Session settings (must be set before session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Function to get road transportation reports
function getRoadReports($conn, $filters = [], $page = 1, $limit = 10, $report_category = 'transportation') {
    $offset = ($page - 1) * $limit;
    
    $table = $report_category === 'maintenance' ? 'road_maintenance_reports' : 'road_transportation_reports';
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($table_check->num_rows === 0) {
        // Return empty result if table doesn't exist
        $dummy_result = new stdClass();
        $dummy_result->num_rows = 0;
        return $dummy_result;
    }
    
    $query = "SELECT * FROM $table WHERE 1=1";
    $params = [];
    $types = '';
    
    if (!empty($filters['report_id'])) {
        $query .= " AND report_id LIKE ?";
        $params[] = '%' . $filters['report_id'] . '%';
        $types .= 's';
    }
    
    if (!empty($filters['report_type'])) {
        $query .= " AND report_type = ?";
        $params[] = $filters['report_type'];
        $types .= 's';
    }
    
    if (!empty($filters['status'])) {
        $query .= " AND status = ?";
        $params[] = $filters['status'];
        $types .= 's';
    }
    
    if (!empty($filters['priority'])) {
        $query .= " AND priority = ?";
        $params[] = $filters['priority'];
        $types .= 's';
    }
    
    if (!empty($filters['department'])) {
        $query .= " AND department = ?";
        $params[] = $filters['department'];
        $types .= 's';
    }
    
    if (!empty($filters['date_from'])) {
        $query .= " AND DATE(created_date) >= ?";
        $params[] = $filters['date_from'];
        $types .= 's';
    }
    
    if (!empty($filters['date_to'])) {
        $query .= " AND DATE(created_date) <= ?";
        $params[] = $filters['date_to'];
        $types .= 's';
    }
    
    $query .= " ORDER BY created_date DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    
    return $stmt->get_result();
}

// Function to get report statistics
function getReportStatistics($conn, $report_category = 'transportation') {
    $stats = ['total' => 0, 'pending' => 0, 'completed' => 0, 'this_month' => 0];
    
    $table = $report_category === 'maintenance' ? 'road_maintenance_reports' : 'road_transportation_reports';
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE '$table'");
    if ($table_check->num_rows === 0) {
        return $stats; // Return empty stats if table doesn't exist
    }
    
    // Total reports
    $result = $conn->query("SELECT COUNT(*) as total FROM $table");
    if ($result) {
        $stats['total'] = $result->fetch_assoc()['total'];
    }
    
    // Pending reports
    $result = $conn->query("SELECT COUNT(*) as pending FROM $table WHERE status = 'pending'");
    if ($result) {
        $stats['pending'] = $result->fetch_assoc()['pending'];
    }
    
    // Completed reports
    $result = $conn->query("SELECT COUNT(*) as completed FROM $table WHERE status = 'completed'");
    if ($result) {
        $stats['completed'] = $result->fetch_assoc()['completed'];
    }
    
    // This month
    $result = $conn->query("SELECT COUNT(*) as this_month FROM $table WHERE MONTH(created_date) = MONTH(CURRENT_DATE()) AND YEAR(created_date) = YEAR(CURRENT_DATE())");
    if ($result) {
        $stats['this_month'] = $result->fetch_assoc()['this_month'];
    }
    
    return $stats;
}

// Function to export report data
function exportReportData($conn, $format, $filters = []) {
    $reports = getRoadReports($conn, $filters, 1, 1000); // Get all data for export
    
    switch ($format) {
        case 'csv':
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="road_transportation_reports.csv"');
            $output = fopen('php://output', 'w');
            
            // Header
            fputcsv($output, ['Report ID', 'Report Type', 'Title', 'Department', 'Priority', 'Status', 'Created Date', 'Due Date']);
            
            // Data
            while ($row = $reports->fetch_assoc()) {
                fputcsv($output, [
                    $row['report_id'],
                    $row['report_type'],
                    $row['title'],
                    $row['department'],
                    $row['priority'],
                    $row['status'],
                    $row['created_date'],
                    $row['due_date']
                ]);
            }
            fclose($output);
            exit();
            
        case 'excel':
            // For Excel export, redirect to CSV for now
            exportReportData($conn, 'csv', $filters);
            break;
            
        case 'pdf':
            // Simple PDF export
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="road_transportation_reports.pdf"');
            
            echo "<h1>Road Transportation Reports</h1>";
            echo "<table border='1'>";
            echo "<tr><th>Report ID</th><th>Type</th><th>Title</th><th>Department</th><th>Status</th><th>Created Date</th></tr>";
            
            while ($row = $reports->fetch_assoc()) {
                echo "<tr>";
                echo "<td>{$row['report_id']}</td>";
                echo "<td>{$row['report_type']}</td>";
                echo "<td>{$row['title']}</td>";
                echo "<td>{$row['department']}</td>";
                echo "<td>{$row['status']}</td>";
                echo "<td>{$row['created_date']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            exit();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['export_format'])) {
        exportReportData($conn, $_POST['export_format'], $_POST);
    }
    
    if (isset($_POST['clear_filters'])) {
        unset($_POST);
        header('Location: road_transportation_reporting.php');
        exit();
    }
}

// Get current page and category
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$category = isset($_GET['category']) ? $_GET['category'] : 'transportation';

// Get data for both categories
$transport_stats = getReportStatistics($conn, 'transportation');
$maintenance_stats = getReportStatistics($conn, 'maintenance');
$transport_reports = getRoadReports($conn, $_POST, $page, 10, 'transportation');
$maintenance_reports = getRoadReports($conn, $_POST, $page, 10, 'maintenance');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road Transportation Reporting | LGU Staff</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            min-height: 100vh;
            background: url("../../assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(6px);
            background: rgba(0, 0, 0, 0.35);
            z-index: 0;
        }

        .main-content {
            margin-left: 250px;
            padding: 20px;
            position: relative;
            z-index: 1;
        }

        .reporting-header {
            background: rgba(255, 255, 255, 0.95);
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

        .header-actions {
            display: flex;
            gap: 15px;
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

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #495057);
        }

        .btn-secondary:hover {
            box-shadow: 0 6px 20px rgba(108, 117, 125, 0.3);
        }

        .filters-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .filters-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-label {
            font-size: 14px;
            font-weight: 500;
            color: #333;
        }

        .filter-input {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .filter-input:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.1);
        }

        .filter-select {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .filter-select:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.1);
        }

        .date-range {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .table-stats {
            display: flex;
            gap: 20px;
            align-items: center;
        }

        .table-stat {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            color: #666;
        }

        .table-stat strong {
            color: #3762c8;
            font-weight: 600;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
        }

        .data-table thead {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
        }

        .data-table th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            white-space: nowrap;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: all 0.3s ease;
        }

        .data-table tbody tr:hover {
            background: rgba(55, 98, 200, 0.05);
        }

        .data-table td {
            padding: 15px;
            font-size: 14px;
            color: #333;
        }

        .report-id {
            font-weight: 600;
            color: #3762c8;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-in-progress {
            background: #cce5ff;
            color: #004085;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-cancelled {
            background: #f8d7da;
            color: #721c24;
        }

        .priority-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }

        .priority-high {
            background: #f8d7da;
            color: #721c24;
        }

        .priority-medium {
            background: #fff3cd;
            color: #856404;
        }

        .priority-low {
            background: #d4edda;
            color: #155724;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-table {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .btn-view {
            background: #3762c8;
            color: white;
        }

        .btn-view:hover {
            background: #2a4fa8;
        }

        .btn-edit {
            background: #ffc107;
            color: #333;
        }

        .btn-edit:hover {
            background: #e0a800;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
        }

        .btn-delete:hover {
            background: #c82333;
        }

        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }

        .pagination-btn {
            padding: 8px 12px;
            border: 1px solid #ddd;
            background: white;
            color: #666;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .pagination-btn:hover {
            background: #3762c8;
            color: white;
            border-color: #3762c8;
        }

        .pagination-btn.active {
            background: #3762c8;
            color: white;
            border-color: #3762c8;
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-info {
            font-size: 14px;
            color: #666;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-data i {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .tab-navigation {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
        }

        .tab-btn {
            padding: 12px 24px;
            background: none;
            border: none;
            color: #666;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            position: relative;
        }

        .tab-btn:hover {
            color: #3762c8;
            background: rgba(55, 98, 200, 0.05);
        }

        .tab-btn.active {
            color: #3762c8;
            border-bottom-color: #3762c8;
            font-weight: 600;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .date-range {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .table-stats {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <iframe src="../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0"
            name="sidebar-frame"
            scrolling="no">
    </iframe>

    <div class="main-content">
        <!-- Reporting Header -->
        <div class="reporting-header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Road Transportation Reporting</h1>
                    <p>Generate and manage comprehensive transportation reports</p>
                </div>
                <div class="header-actions">
                    <form method="POST" style="display: flex; gap: 15px;">
                        <button type="submit" name="clear_filters" class="btn-action btn-secondary">
                            <i class="fas fa-filter"></i>
                            Clear Filters
                        </button>
                        <button type="submit" name="export_format" value="csv" class="btn-action">
                            <i class="fas fa-download"></i>
                            Export Data
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Filters Section -->
        <div class="filters-section">
            <h3 class="filters-title">
                <i class="fas fa-search"></i>
                Search & Filter Reports
            </h3>
            <form method="POST" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label">Report ID</label>
                        <input type="text" name="report_id" class="filter-input" placeholder="Enter report ID..." value="<?php echo isset($_POST['report_id']) ? htmlspecialchars($_POST['report_id']) : ''; ?>">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Report Type</label>
                        <select name="report_type" class="filter-select">
                            <option value="">All Types</option>
                            <option value="monthly" <?php echo (isset($_POST['report_type']) && $_POST['report_type'] == 'monthly') ? 'selected' : ''; ?>>Monthly Assessment</option>
                            <option value="traffic" <?php echo (isset($_POST['report_type']) && $_POST['report_type'] == 'traffic') ? 'selected' : ''; ?>>Traffic Analysis</option>
                            <option value="maintenance" <?php echo (isset($_POST['report_type']) && $_POST['report_type'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance Summary</option>
                            <option value="safety" <?php echo (isset($_POST['report_type']) && $_POST['report_type'] == 'safety') ? 'selected' : ''; ?>>Safety Audit</option>
                            <option value="budget" <?php echo (isset($_POST['report_type']) && $_POST['report_type'] == 'budget') ? 'selected' : ''; ?>>Budget Report</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-select">
                            <option value="">All Status</option>
                            <option value="pending" <?php echo (isset($_POST['status']) && $_POST['status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="in-progress" <?php echo (isset($_POST['status']) && $_POST['status'] == 'in-progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="completed" <?php echo (isset($_POST['status']) && $_POST['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="cancelled" <?php echo (isset($_POST['status']) && $_POST['status'] == 'cancelled') ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Priority</label>
                        <select name="priority" class="filter-select">
                            <option value="">All Priorities</option>
                            <option value="high" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'high') ? 'selected' : ''; ?>>High</option>
                            <option value="medium" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'medium') ? 'selected' : ''; ?>>Medium</option>
                            <option value="low" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'low') ? 'selected' : ''; ?>>Low</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Date Range</label>
                        <div class="date-range">
                            <input type="date" name="date_from" class="filter-input" style="width: 150px;" value="<?php echo isset($_POST['date_from']) ? htmlspecialchars($_POST['date_from']) : ''; ?>">
                            <span>to</span>
                            <input type="date" name="date_to" class="filter-input" style="width: 150px;" value="<?php echo isset($_POST['date_to']) ? htmlspecialchars($_POST['date_to']) : ''; ?>">
                        </div>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Department</label>
                        <select name="department" class="filter-select">
                            <option value="">All Departments</option>
                            <option value="engineering" <?php echo (isset($_POST['department']) && $_POST['department'] == 'engineering') ? 'selected' : ''; ?>>Engineering</option>
                            <option value="planning" <?php echo (isset($_POST['department']) && $_POST['department'] == 'planning') ? 'selected' : ''; ?>>Planning</option>
                            <option value="maintenance" <?php echo (isset($_POST['department']) && $_POST['department'] == 'maintenance') ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="finance" <?php echo (isset($_POST['department']) && $_POST['department'] == 'finance') ? 'selected' : ''; ?>>Finance</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 20px;">
                    <button type="submit" class="btn-action">
                        <i class="fas fa-search"></i>
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Data Table -->
        <div class="table-container">
            <div class="table-header">
                <h3 class="table-title">
                    <i class="fas fa-table"></i>
                    Transportation Reports
                </h3>
                <div class="table-stats">
                    <div class="table-stat">
                        <strong><?php echo number_format($transport_stats['total']); ?></strong> Total Reports
                    </div>
                    <div class="table-stat">
                        <strong><?php echo number_format($transport_stats['pending']); ?></strong> Pending
                    </div>
                    <div class="table-stat">
                        <strong><?php echo number_format($transport_stats['completed']); ?></strong> Completed
                    </div>
                </div>
            </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Report ID</th>
                            <th>Report Type</th>
                            <th>Title</th>
                            <th>Department</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Created Date</th>
                            <th>Due Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($transport_reports->num_rows > 0): ?>
                            <?php while ($report = $transport_reports->fetch_assoc()): ?>
                                <tr>
                                    <td class="report-id"><?php echo htmlspecialchars($report['report_id'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($report['report_type'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($report['title'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($report['department'] ?? 'N/A'); ?></td>
                                    <td><span class="priority-badge priority-<?php echo htmlspecialchars($report['priority'] ?? 'medium'); ?>"><?php echo ucfirst(htmlspecialchars($report['priority'] ?? 'Medium')); ?></span></td>
                                    <td><span class="status-badge status-<?php echo htmlspecialchars($report['status'] ?? 'pending'); ?>"><?php echo ucfirst(htmlspecialchars($report['status'] ?? 'Pending')); ?></span></td>
                                    <td><?php echo $report['created_date'] ? date('M d, Y', strtotime($report['created_date'])) : 'N/A'; ?></td>
                                    <td><?php echo $report['due_date'] ? date('M d, Y', strtotime($report['due_date'])) : 'N/A'; ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-table btn-view" onclick="viewReport(<?php echo $report['id']; ?>, 'transportation')">
                                                <i class="fas fa-eye"></i>
                                                View
                                            </button>
                                            <button class="btn-table btn-edit" onclick="editReport(<?php echo $report['id']; ?>, 'transportation')">
                                                <i class="fas fa-edit"></i>
                                                Edit
                                            </button>
                                            <button class="btn-table btn-delete" onclick="deleteReport(<?php echo $report['id']; ?>, 'transportation')">
                                                <i class="fas fa-trash"></i>
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="no-data">
                                    <i class="fas fa-inbox"></i>
                                    <p>No transportation reports found matching your criteria.</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
        </div>

        <!-- Maintenance Reports Section -->
        <div class="table-container" style="margin-top: 30px;">
            <div class="table-header">
                <h3 class="table-title">
                    <i class="fas fa-tools"></i>
                    Maintenance Reports
                </h3>
                <div class="table-stats">
                    <div class="table-stat">
                        <strong><?php echo number_format($maintenance_stats['total']); ?></strong> Total Reports
                    </div>
                    <div class="table-stat">
                        <strong><?php echo number_format($maintenance_stats['pending']); ?></strong> Pending
                    </div>
                    <div class="table-stat">
                        <strong><?php echo number_format($maintenance_stats['completed']); ?></strong> Completed
                    </div>
                </div>
            </div>

            <table class="data-table">
                <thead>
                    <tr>
                        <th>Report ID</th>
                        <th>Report Type</th>
                        <th>Title</th>
                        <th>Department</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Created Date</th>
                        <th>Due Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($maintenance_reports->num_rows > 0): ?>
                        <?php while ($report = $maintenance_reports->fetch_assoc()): ?>
                            <tr>
                                <td class="report-id"><?php echo htmlspecialchars($report['report_id'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($report['report_type'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($report['title'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($report['department'] ?? 'N/A'); ?></td>
                                <td><span class="priority-badge priority-<?php echo htmlspecialchars($report['priority'] ?? 'medium'); ?>"><?php echo ucfirst(htmlspecialchars($report['priority'] ?? 'Medium')); ?></span></td>
                                <td><span class="status-badge status-<?php echo htmlspecialchars($report['status'] ?? 'pending'); ?>"><?php echo ucfirst(htmlspecialchars($report['status'] ?? 'Pending')); ?></span></td>
                                <td><?php echo $report['created_date'] ? date('M d, Y', strtotime($report['created_date'])) : 'N/A'; ?></td>
                                <td><?php echo $report['due_date'] ? date('M d, Y', strtotime($report['due_date'])) : 'N/A'; ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn-table btn-view" onclick="viewReport(<?php echo $report['id']; ?>, 'maintenance')">
                                            <i class="fas fa-eye"></i>
                                            View
                                        </button>
                                        <button class="btn-table btn-edit" onclick="editReport(<?php echo $report['id']; ?>, 'maintenance')">
                                            <i class="fas fa-edit"></i>
                                            Edit
                                        </button>
                                        <button class="btn-table btn-delete" onclick="deleteReport(<?php echo $report['id']; ?>, 'maintenance')">
                                            <i class="fas fa-trash"></i>
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="no-data">
                                <i class="fas fa-wrench"></i>
                                <p>No maintenance reports found matching your criteria.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php 
            $total_reports = max($transport_stats['total'], $maintenance_stats['total']);
            if ($total_reports > 10): 
            ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>" class="pagination-btn">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    <?php else: ?>
                        <button class="pagination-btn" disabled>
                            <i class="fas fa-chevron-left"></i>
                        </button>
                    <?php endif; ?>
                    
                    <?php
                    $total_pages = ceil($total_reports / 10);
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    for ($i = $start_page; $i <= $end_page; $i++):
                    ?>
                        <a href="?page=<?php echo $i; ?>" class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>" class="pagination-btn">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <button class="pagination-btn" disabled>
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    <?php endif; ?>
                    
                    <span class="pagination-info">Showing <?php echo (($page - 1) * 10) + 1; ?>-<?php echo min($page * 10, $total_reports); ?> of <?php echo $total_reports; ?> reports</span>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Filter functionality
        document.querySelectorAll('.filter-input, .filter-select').forEach(input => {
            input.addEventListener('change', function() {
                console.log('Filter changed:', this.value);
                // Auto-submit form on filter change
                document.getElementById('filterForm').submit();
            });
        });

        // Table action buttons
        function viewReport(id, category) {
            console.log('Viewing report:', id, 'Category:', category);
            alert(`Viewing ${category} report ID: ${id}`);
            // In real application, this would open a modal or navigate to report details
        }

        function editReport(id, category) {
            console.log('Editing report:', id, 'Category:', category);
            if (confirm(`Edit ${category} report ID: ${id}?`)) {
                // In real application, this would open an edit form
                alert(`Editing ${category} report ID: ${id}`);
            }
        }

        function deleteReport(id, category) {
            console.log('Deleting report:', id, 'Category:', category);
            if (confirm(`Are you sure you want to delete ${category} report ID: ${id}?`)) {
                // In real application, this would make an AJAX call to delete the report
                alert(`Deleted ${category} report ID: ${id}`);
                location.reload();
            }
        }
                alert(`Deleted report ID: ${id}`);
                location.reload();
            }
        }

        // Export functionality
        document.querySelectorAll('button[name="export_format"]').forEach(btn => {
            btn.addEventListener('click', function(e) {
                const format = this.value;
                if (confirm(`Export reports as ${format.toUpperCase()}?`)) {
                    console.log('Exporting as:', format);
                    // Form will submit automatically
                } else {
                    e.preventDefault();
                }
            });
        });
    </script>
</body>
</html>

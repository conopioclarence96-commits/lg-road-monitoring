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
    header('Location: ../login.html');
    exit();
}

// Function to get audit statistics
function getAuditStatistics($conn) {
    $stats = [];
    
    // Total audits
    $result = $conn->query("SELECT COUNT(*) as total FROM audit_trails");
    $stats['total'] = $result->fetch_assoc()['total'];
    
    // This month
    $result = $conn->query("SELECT COUNT(*) as this_month FROM audit_trails WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
    $stats['this_month'] = $result->fetch_assoc()['this_month'];
    
    // Pending review
    $result = $conn->query("SELECT COUNT(*) as pending FROM audit_trails WHERE status = 'pending'");
    $stats['pending'] = $result->fetch_assoc()['pending'];
    
    // Compliance rate
    $result = $conn->query("SELECT 
        (COUNT(CASE WHEN status = 'approved' THEN 1 END) * 100.0 / COUNT(*)) as compliance_rate 
        FROM audit_trails");
    $compliance = $result->fetch_assoc()['compliance_rate'];
    $stats['compliance_rate'] = round($compliance, 1) . '%';
    
    return $stats;
}

// Function to get audit trails
function getAuditTrails($conn, $filters = []) {
    $query = "SELECT * FROM audit_trails WHERE 1=1";
    $params = [];
    $types = '';
    
    if (!empty($filters['audit_id'])) {
        $query .= " AND audit_id LIKE ?";
        $params[] = '%' . $filters['audit_id'] . '%';
        $types .= 's';
    }
    
    if (!empty($filters['audit_type'])) {
        $query .= " AND audit_type = ?";
        $params[] = $filters['audit_type'];
        $types .= 's';
    }
    
    if (!empty($filters['status'])) {
        $query .= " AND status = ?";
        $params[] = $filters['status'];
        $types .= 's';
    }
    
    if (!empty($filters['date_from'])) {
        $query .= " AND DATE(created_at) >= ?";
        $params[] = $filters['date_from'];
        $types .= 's';
    }
    
    if (!empty($filters['date_to'])) {
        $query .= " AND DATE(created_at) <= ?";
        $params[] = $filters['date_to'];
        $types .= 's';
    }
    
    $query .= " ORDER BY created_at DESC LIMIT 50";
    
    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    
    return $stmt->get_result();
}

// Function to export audit data
function exportAuditData($conn, $format, $filters = []) {
    $trails = getAuditTrails($conn, $filters);
    
    switch ($format) {
        case 'csv':
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="audit_trails.csv"');
            $output = fopen('php://output', 'w');
            
            // Header
            fputcsv($output, ['Audit ID', 'Title', 'Type', 'Status', 'Auditor', 'Date', 'Description']);
            
            // Data
            while ($row = $trails->fetch_assoc()) {
                fputcsv($output, [
                    $row['audit_id'],
                    $row['title'],
                    $row['audit_type'],
                    $row['status'],
                    $row['auditor'],
                    $row['created_at'],
                    $row['description']
                ]);
            }
            fclose($output);
            exit();
            
        case 'excel':
            // For Excel export, you would typically use a library like PHPExcel
            // For now, we'll redirect to CSV
            exportAuditData($conn, 'csv', $filters);
            break;
            
        case 'pdf':
            // For PDF export, you would typically use a library like TCPDF or FPDF
            // For now, we'll create a simple HTML to PDF conversion
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="audit_trails.pdf"');
            
            echo "<h1>Audit Trails Report</h1>";
            echo "<table border='1'>";
            echo "<tr><th>Audit ID</th><th>Title</th><th>Type</th><th>Status</th><th>Auditor</th><th>Date</th></tr>";
            
            while ($row = $trails->fetch_assoc()) {
                echo "<tr>";
                echo "<td>{$row['audit_id']}</td>";
                echo "<td>{$row['title']}</td>";
                echo "<td>{$row['audit_type']}</td>";
                echo "<td>{$row['status']}</td>";
                echo "<td>{$row['auditor']}</td>";
                echo "<td>{$row['created_at']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            exit();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['export_format'])) {
        exportAuditData($conn, $_POST['export_format'], $_POST);
    }
    
    if (isset($_POST['clear_filters'])) {
        unset($_POST);
        header('Location: verification_reporting.php');
        exit();
    }
}

// Get data
$stats = getAuditStatistics($conn);
$audit_trails = getAuditTrails($conn, $_POST);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification Reporting | LGU Staff</title>
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

        .audit-header {
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

        .audit-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .audit-stat {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            text-align: center;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #3762c8;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 14px;
            color: #666;
            font-weight: 500;
        }

        .audit-filters {
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

        .audit-timeline {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .timeline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
        }

        .timeline-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .timeline-actions {
            display: flex;
            gap: 10px;
        }

        .timeline-container {
            position: relative;
            padding-left: 30px;
            max-height: 600px;
            overflow-y: auto;
        }

        .timeline-container::before {
            content: '';
            position: absolute;
            left: 8px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: rgba(55, 98, 200, 0.2);
        }

        .timeline-item {
            position: relative;
            padding-bottom: 30px;
        }

        .timeline-item:last-child {
            padding-bottom: 0;
        }

        .timeline-marker {
            position: absolute;
            left: -22px;
            top: 5px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: #3762c8;
            border: 3px solid white;
            box-shadow: 0 2px 8px rgba(55, 98, 200, 0.3);
        }

        .timeline-marker.approved {
            background: #28a745;
        }

        .timeline-marker.rejected {
            background: #dc3545;
        }

        .timeline-marker.pending {
            background: #ffc107;
        }

        .timeline-marker.completed {
            background: #6c757d;
        }

        .timeline-content {
            background: rgba(255, 255, 255, 0.7);
            padding: 20px;
            border-radius: 12px;
            border: 1px solid rgba(55, 98, 200, 0.1);
            transition: all 0.3s ease;
        }

        .timeline-content:hover {
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.1);
        }

        .timeline-header-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .timeline-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .timeline-meta {
            display: flex;
            gap: 20px;
            margin-bottom: 10px;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #666;
        }

        .meta-item i {
            color: #3762c8;
        }

        .timeline-description {
            font-size: 14px;
            color: #666;
            line-height: 1.5;
            margin-bottom: 15px;
        }

        .timeline-actions {
            display: flex;
            gap: 10px;
        }

        .btn-timeline {
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

        .btn-download {
            background: #28a745;
            color: white;
        }

        .btn-download:hover {
            background: #218838;
        }

        .btn-share {
            background: #6c757d;
            color: white;
        }

        .btn-share:hover {
            background: #5a6268;
        }

        .timeline-footer {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(55, 98, 200, 0.1);
        }

        .timeline-footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: #666;
        }

        .timeline-signature {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .signature-info {
            font-weight: 500;
            color: #333;
        }

        .signature-date {
            color: #999;
        }

        .export-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            margin-top: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .export-header {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .export-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .export-option {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(55, 98, 200, 0.1);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .export-option:hover {
            background: rgba(55, 98, 200, 0.1);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.1);
        }

        .export-icon {
            font-size: 24px;
            color: #3762c8;
            margin-bottom: 10px;
        }

        .export-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .export-description {
            font-size: 14px;
            color: #666;
            line-height: 1.4;
        }

        .date-range {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            z-index: 10000;
            animation: slideIn 0.3s ease;
        }

        .notification.success {
            background: #28a745;
        }

        .notification.error {
            background: #dc3545;
        }

        .notification.info {
            background: #17a2b8;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @media (max-width: 1200px) {
            .audit-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .export-grid {
                grid-template-columns: 1fr;
            }
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
            
            .timeline-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .timeline-actions {
                width: 100%;
                justify-content: flex-start;
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
        <!-- Audit Header -->
        <div class="audit-header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Verification Reporting</h1>
                    <p>Track and manage verification audit trails and compliance reports</p>
                </div>
                <div class="header-actions">
                    <form method="POST" style="display: flex; gap: 15px;">
                        <button type="submit" name="clear_filters" class="btn-action btn-secondary">
                            <i class="fas fa-filter"></i>
                            Clear Filters
                        </button>
                        <button type="submit" name="export_format" value="pdf" class="btn-action">
                            <i class="fas fa-download"></i>
                            Export Audit
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Audit Statistics -->
        <div class="audit-stats">
            <div class="audit-stat">
                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label">Total Audits</div>
            </div>
            <div class="audit-stat">
                <div class="stat-number"><?php echo number_format($stats['this_month']); ?></div>
                <div class="stat-label">This Month</div>
            </div>
            <div class="audit-stat">
                <div class="stat-number"><?php echo number_format($stats['pending']); ?></div>
                <div class="stat-label">Pending Review</div>
            </div>
            <div class="audit-stat">
                <div class="stat-number"><?php echo $stats['compliance_rate']; ?></div>
                <div class="stat-label">Compliance Rate</div>
            </div>
        </div>

        <!-- Audit Filters -->
        <div class="audit-filters">
            <h3 class="filters-title">
                <i class="fas fa-search"></i>
                Filter Audit Trail
            </h3>
            <form method="POST" id="filterForm">
                <div class="filters-grid">
                    <div class="filter-group">
                        <label class="filter-label">Audit ID</label>
                        <input type="text" name="audit_id" class="filter-input" placeholder="Enter audit ID..." value="<?php echo isset($_POST['audit_id']) ? htmlspecialchars($_POST['audit_id']) : ''; ?>">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Audit Type</label>
                        <select name="audit_type" class="filter-select">
                            <option value="">All Types</option>
                            <option value="infrastructure" <?php echo (isset($_POST['audit_type']) && $_POST['audit_type'] == 'infrastructure') ? 'selected' : ''; ?>>Infrastructure</option>
                            <option value="safety" <?php echo (isset($_POST['audit_type']) && $_POST['audit_type'] == 'safety') ? 'selected' : ''; ?>>Safety</option>
                            <option value="compliance" <?php echo (isset($_POST['audit_type']) && $_POST['audit_type'] == 'compliance') ? 'selected' : ''; ?>>Compliance</option>
                            <option value="quality" <?php echo (isset($_POST['audit_type']) && $_POST['audit_type'] == 'quality') ? 'selected' : ''; ?>>Quality</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="filter-select">
                            <option value="">All Status</option>
                            <option value="approved" <?php echo (isset($_POST['status']) && $_POST['status'] == 'approved') ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo (isset($_POST['status']) && $_POST['status'] == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                            <option value="pending" <?php echo (isset($_POST['status']) && $_POST['status'] == 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="completed" <?php echo (isset($_POST['status']) && $_POST['status'] == 'completed') ? 'selected' : ''; ?>>Completed</option>
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
                </div>
                <div style="margin-top: 20px;">
                    <button type="submit" class="btn-action">
                        <i class="fas fa-search"></i>
                        Apply Filters
                    </button>
                </div>
            </form>
        </div>

        <!-- Audit Timeline -->
        <div class="audit-timeline">
            <div class="timeline-header">
                <h3 class="timeline-title">
                    <i class="fas fa-history"></i>
                    Audit Trail Timeline
                </h3>
                <div class="timeline-actions">
                    <button class="btn-action btn-secondary" onclick="window.print()">
                        <i class="fas fa-print"></i>
                        Print
                    </button>
                    <button class="btn-action" onclick="generateReport()">
                        <i class="fas fa-file-pdf"></i>
                        Generate Report
                    </button>
                </div>
            </div>

            <div class="timeline-container">
                <?php if ($audit_trails->num_rows > 0): ?>
                    <?php while ($audit = $audit_trails->fetch_assoc()): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker <?php echo htmlspecialchars($audit['status']); ?>"></div>
                            <div class="timeline-content">
                                <div class="timeline-header-info">
                                    <div class="timeline-title"><?php echo htmlspecialchars($audit['title']); ?></div>
                                    <div class="timeline-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($audit['auditor']); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo date('M d, Y', strtotime($audit['created_at'])); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-clock"></i>
                                            <?php echo date('h:i A', strtotime($audit['created_at'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="timeline-description">
                                    <?php echo htmlspecialchars($audit['description']); ?>
                                </div>
                                <div class="timeline-actions">
                                    <button class="btn-timeline btn-view" onclick="viewAuditDetails(<?php echo $audit['id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                        View Details
                                    </button>
                                    <button class="btn-timeline btn-download" onclick="downloadAuditReport(<?php echo $audit['id']; ?>)">
                                        <i class="fas fa-download"></i>
                                        Download Report
                                    </button>
                                    <button class="btn-timeline btn-share" onclick="shareAudit(<?php echo $audit['id']; ?>)">
                                        <i class="fas fa-share"></i>
                                        Share
                                    </button>
                                </div>
                                <div class="timeline-footer">
                                    <div class="timeline-footer-content">
                                        <div class="timeline-signature">
                                            <span class="signature-info"><?php echo ucfirst(htmlspecialchars($audit['status'] ?? 'Unknown')); ?> by: <?php echo htmlspecialchars($audit['reviewer'] ?? 'Unknown'); ?></span>
                                            <span class="signature-date">• <?php echo $audit['review_date'] ? date('M d, Y', strtotime($audit['review_date'])) : 'Date not set'; ?></span>
                                        </div>
                                        <div class="timeline-signature">
                                            <span class="signature-info">Audit ID: <?php echo htmlspecialchars($audit['audit_id']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <i class="fas fa-search" style="font-size: 48px; margin-bottom: 20px; opacity: 0.5;"></i>
                        <p>No audit trails found matching your criteria.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Export Options -->
        <div class="export-section">
            <h3 class="export-header">
                <i class="fas fa-file-export"></i>
                Export Audit Reports
            </h3>
            <form method="POST">
                <div class="export-grid">
                    <button type="submit" name="export_format" value="pdf" class="export-option" style="border: none; background: none; cursor: pointer;">
                        <div class="export-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="export-title">PDF Report</div>
                        <div class="export-description">Generate comprehensive audit report in PDF format</div>
                    </button>
                    <button type="submit" name="export_format" value="excel" class="export-option" style="border: none; background: none; cursor: pointer;">
                        <div class="export-icon">
                            <i class="fas fa-file-excel"></i>
                        </div>
                        <div class="export-title">Excel Data</div>
                        <div class="export-description">Export audit data for analysis in Excel format</div>
                    </button>
                    <button type="submit" name="export_format" value="csv" class="export-option" style="border: none; background: none; cursor: pointer;">
                        <div class="export-icon">
                            <i class="fas fa-file-csv"></i>
                        </div>
                        <div class="export-title">CSV Export</div>
                        <div class="export-description">Download raw audit data in CSV format</div>
                    </button>
                    <button type="submit" name="export_format" value="archive" class="export-option" style="border: none; background: none; cursor: pointer;">
                        <div class="export-icon">
                            <i class="fas fa-file-archive"></i>
                        </div>
                        <div class="export-title">Archive Bundle</div>
                        <div class="export-description">Complete audit archive with all supporting documents</div>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Show notification function
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Filter form auto-submit on change
        document.querySelectorAll('.filter-input, .filter-select').forEach(input => {
            input.addEventListener('change', function() {
                if (this.name !== 'audit_id') {
                    document.getElementById('filterForm').submit();
                }
            });
        });

        // Audit ID search on Enter key
        document.querySelector('input[name="audit_id"]').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.getElementById('filterForm').submit();
            }
        });

        // View audit details
        function viewAuditDetails(auditId) {
            window.open(`audit_details.php?id=${auditId}`, '_blank', 'width=800,height=600');
        }

        // Download audit report
        function downloadAuditReport(auditId) {
            window.location.href = `download_audit.php?id=${auditId}`;
            showNotification('Downloading audit report...', 'success');
        }

        // Share audit
        function shareAudit(auditId) {
            if (navigator.share) {
                navigator.share({
                    title: 'Audit Report',
                    text: 'Check out this audit report',
                    url: window.location.href + '?audit=' + auditId
                });
            } else {
                // Fallback: copy to clipboard
                const url = window.location.href + '?audit=' + auditId;
                navigator.clipboard.writeText(url).then(() => {
                    showNotification('Audit link copied to clipboard!', 'success');
                });
            }
        }

        // Generate report
        function generateReport() {
            showNotification('Generating comprehensive report...', 'info');
            setTimeout(() => {
                window.open('generate_report.php', '_blank');
            }, 1000);
        }

        // Auto-refresh timeline every 30 seconds
        setInterval(() => {
            // Only refresh if no filters are applied
            const hasFilters = document.querySelector('input[name="audit_id"]').value || 
                             document.querySelector('select[name="audit_type"]').value || 
                             document.querySelector('select[name="status"]').value;
            
            if (!hasFilters) {
                location.reload();
            }
        }, 30000);

        // Print-friendly styles
        window.addEventListener('beforeprint', () => {
            document.body.style.background = 'white';
            document.querySelector('.main-content').style.marginLeft = '0';
        });

        window.addEventListener('afterprint', () => {
            document.body.style.background = '';
            document.querySelector('.main-content').style.marginLeft = '';
        });
    </script>
</body>
</html>

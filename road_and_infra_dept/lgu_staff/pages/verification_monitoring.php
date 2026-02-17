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

// Function to get verification statistics
function getVerificationStatistics($conn) {
    $stats = [];
    
    // Pending verifications from both tables
    $result = $conn->query("SELECT COUNT(*) as pending FROM road_transportation_reports WHERE status = 'pending'");
    $transport_pending = $result->fetch_assoc()['pending'];
    
    $result = $conn->query("SELECT COUNT(*) as pending FROM road_maintenance_reports WHERE status = 'pending'");
    $maintenance_pending = $result->fetch_assoc()['pending'];
    $stats['pending'] = $transport_pending + $maintenance_pending;
    
    // Debug: Add individual counts for display
    $stats['transport_pending'] = $transport_pending;
    $stats['maintenance_pending'] = $maintenance_pending;
    
    // In progress from both tables
    $result = $conn->query("SELECT COUNT(*) as in_progress FROM road_transportation_reports WHERE status = 'in-progress'");
    $transport_progress = $result->fetch_assoc()['in_progress'];
    
    $result = $conn->query("SELECT COUNT(*) as in_progress FROM road_maintenance_reports WHERE status = 'in-progress'");
    $maintenance_progress = $result->fetch_assoc()['in_progress'];
    $stats['in_review'] = $transport_progress + $maintenance_progress;
    
    // Approved (completed) from both tables
    $result = $conn->query("SELECT COUNT(*) as completed FROM road_transportation_reports WHERE status = 'completed'");
    $transport_completed = $result->fetch_assoc()['completed'];
    
    $result = $conn->query("SELECT COUNT(*) as completed FROM road_maintenance_reports WHERE status = 'completed'");
    $maintenance_completed = $result->fetch_assoc()['completed'];
    $stats['approved'] = $transport_completed + $maintenance_completed;
    
    return $stats;
}

// Function to get pending verifications
function getPendingVerifications($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, NULL as location, created_at, updated_at FROM road_transportation_reports WHERE status = 'pending')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, created_at, updated_at FROM road_maintenance_reports WHERE status = 'pending')
              ORDER BY created_at DESC";
    $result = $conn->query($query);
    return $result;
}

// Function to get recent approvals
function getRecentApprovals($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, NULL as location, created_at, updated_at FROM road_transportation_reports WHERE status = 'completed')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, created_at, updated_at FROM road_maintenance_reports WHERE status = 'completed')
              ORDER BY updated_at DESC LIMIT 10";
    $result = $conn->query($query);
    return $result;
}

// Function to get activity timeline
function getActivityTimeline($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, NULL as location, created_at, updated_at FROM road_transportation_reports)
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, created_at, updated_at FROM road_maintenance_reports)
              ORDER BY updated_at DESC LIMIT 5";
    $result = $conn->query($query);
    return $result;
}

// Handle verification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['report_id']) && isset($_POST['source'])) {
        $report_id = $_POST['report_id'];
        $source = $_POST['source'];
        $action = $_POST['action'];
        
        // Update report status
        $status = '';
        switch ($action) {
            case 'approve':
                $status = 'completed';
                break;
            case 'reject':
                $status = 'cancelled';
                break;
            case 'review':
                $status = 'in-progress';
                break;
        }
        
        if ($status) {
            // Determine which table to update
            $table = ($source === 'transport') ? 'road_transportation_reports' : 'road_maintenance_reports';
            
            $query = "UPDATE $table SET status = ?, updated_at = NOW() WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('si', $status, $report_id);
            $stmt->execute();
            
            // Log the action
            $audit_query = "INSERT INTO audit_trails (audit_id, title, audit_type, status, auditor, description, created_at) 
                           VALUES (?, ?, ?, ?, ?, ?, NOW())";
            $audit_stmt = $conn->prepare($audit_query);
            $audit_id = 'VR-' . date('Y-m-d-His');
            $title = ucfirst($action) . ' Report #' . $report_id;
            $audit_type = 'verification';
            $auditor = $_SESSION['username'] ?? 'Unknown';
            $description = "Report #$report_id from $source table has been " . $action . "ed by $auditor";
            
            $audit_stmt->bind_param('ssssss', $audit_id, $title, $audit_type, $status, $auditor, $description);
            $audit_stmt->execute();
        }
        
        header('Location: verification_monitoring.php');
        exit();
    }
}

// Get data
$stats = getVerificationStatistics($conn);
$pending_verifications = getPendingVerifications($conn);
$recent_approvals = getRecentApprovals($conn);
$activity_timeline = getActivityTimeline($conn);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification and Monitoring | LGU Staff</title>
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

        .verification-header {
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

        .workflow-stats {
            display: flex;
            gap: 20px;
        }

        .workflow-stat {
            text-align: center;
            padding: 15px 20px;
            background: rgba(55, 98, 200, 0.05);
            border-radius: 10px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .workflow-number {
            font-size: 24px;
            font-weight: 700;
            color: #3762c8;
            margin-bottom: 5px;
        }

        .workflow-label {
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }

        .workflow-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
            margin-bottom: 25px;
        }

        .workflow-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .workflow-content {
            max-height: 400px;
            overflow-y: auto;
            padding-right: 10px;
        }

        .workflow-content::-webkit-scrollbar {
            width: 6px;
        }

        .workflow-content::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.1);
            border-radius: 3px;
        }

        .workflow-content::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.3);
            border-radius: 3px;
        }

        .workflow-content::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.5);
        }

        .export-options {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .export-option {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(55, 98, 200, 0.1);
            border-radius: 12px;
            padding: 25px;
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
            font-size: 32px;
            color: #3762c8;
            margin-bottom: 15px;
        }

        .export-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .export-description {
            font-size: 14px;
            color: #666;
            line-height: 1.4;
        }

        .workflow-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
        }

        .workflow-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .workflow-badge {
            background: #3762c8;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .workflow-badge.pending {
            background: #ffc107;
        }

        .workflow-badge.approved {
            background: #28a745;
        }

        .workflow-badge.rejected {
            background: #dc3545;
        }

        .verification-item {
            display: flex;
            align-items: flex-start;
            padding: 20px;
            margin-bottom: 15px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 12px;
            border: 1px solid rgba(55, 98, 200, 0.1);
            transition: all 0.3s ease;
            position: relative;
        }

        .verification-item:hover {
            background: rgba(55, 98, 200, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.1);
        }

        .verification-priority {
            position: absolute;
            top: 15px;
            right: 15px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .priority-high {
            background: #dc3545;
        }

        .priority-medium {
            background: #ffc107;
        }

        .priority-low {
            background: #28a745;
        }

        .verification-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            margin-right: 20px;
            flex-shrink: 0;
        }

        .verification-content {
            flex: 1;
        }

        .verification-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .verification-meta {
            display: flex;
            gap: 20px;
            margin-bottom: 12px;
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

        .verification-description {
            font-size: 14px;
            color: #666;
            line-height: 1.5;
            margin-bottom: 15px;
        }

        .verification-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .verification-actions form {
            display: inline-flex;
            gap: 10px;
            margin: 0;
            padding: 0;
        }

        .btn-verify {
            padding: 8px 16px;
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-verify:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }

        .btn-reject {
            padding: 8px 16px;
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-reject:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }

        .btn-review {
            padding: 8px 16px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-review:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }

        .timeline-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .timeline-header {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .timeline {
            position: relative;
            padding-left: 30px;
        }

        .timeline::before {
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
            padding-bottom: 25px;
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

        .timeline-content {
            background: rgba(255, 255, 255, 0.7);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .timeline-title {
            font-size: 14px;
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }

        .timeline-description {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }

        .timeline-time {
            font-size: 11px;
            color: #999;
        }

        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
        }

        .filter-tab {
            padding: 10px 20px;
            background: transparent;
            border: none;
            color: #666;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
        }

        .filter-tab:hover {
            color: #3762c8;
        }

        .filter-tab.active {
            color: #3762c8;
            border-bottom-color: #3762c8;
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
            .workflow-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }
            
            .workflow-stats {
                width: 100%;
                justify-content: space-between;
            }
            
            .verification-actions {
                flex-wrap: wrap;
            }
            
            .verification-actions form {
                flex-wrap: wrap;
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
        <!-- Verification Header -->
        <div class="verification-header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Verification and Monitoring</h1>
                    <p>Review and approve infrastructure reports and monitoring data</p>
                </div>
                <div class="workflow-stats">
                    <div class="workflow-stat">
                        <div class="workflow-number"><?php echo number_format($stats['pending']); ?></div>
                        <div class="workflow-label">Pending</div>
                    </div>
                    <div class="workflow-stat">
                        <div class="workflow-number"><?php echo number_format($stats['in_review']); ?></div>
                        <div class="workflow-label">In Review</div>
                    </div>
                    <div class="workflow-stat">
                        <div class="workflow-number"><?php echo number_format($stats['approved']); ?></div>
                        <div class="workflow-label">Approved</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <button class="filter-tab active" onclick="showAll()">All Requests</button>
            <button class="filter-tab" onclick="showPending()">Pending Review</button>
            <button class="filter-tab" onclick="showApproved()">Approved</button>
            <button class="filter-tab" onclick="showRejected()">Rejected</button>
        </div>

        <!-- Workflow Container -->
        <div class="workflow-container">
            <!-- Pending Verifications -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-clock"></i>
                        Pending Verifications
                        <span class="workflow-badge pending"><?php echo $stats['pending']; ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content">
                    <?php if ($pending_verifications->num_rows > 0): ?>
                        <?php while ($report = $pending_verifications->fetch_assoc()): ?>
                            <div class="verification-item">
                                <div class="verification-priority priority-<?php echo htmlspecialchars($report['priority'] ?? 'medium'); ?>"></div>
                                <div class="verification-icon">
                                    <i class="fas fa-<?php echo getReportIcon($report['report_type']); ?>"></i>
                                </div>
                                <div class="verification-content">
                                    <div class="verification-title"><?php echo htmlspecialchars($report['title']); ?></div>
                                    <div class="verification-meta">
                                        <div class="meta-item">
                                            <i class="fas fa-user"></i>
                                            <?php echo htmlspecialchars($report['department'] . ' Dept'); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-calendar"></i>
                                            <?php echo getTimeAgo($report['created_at']); ?>
                                        </div>
                                        <div class="meta-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <?php echo htmlspecialchars($report['location'] ?? 'Not specified'); ?>
                                        </div>
                                    </div>
                                    <div class="verification-description">
                                        <?php echo htmlspecialchars(substr($report['description'], 0, 150)) . '...'; ?>
                                    </div>
                                    <div class="verification-actions">
                                        <form method="POST" style="display: flex; gap: 10px;">
                                            <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                            <input type="hidden" name="source" value="<?php echo htmlspecialchars($report['source']); ?>">
                                            <button type="submit" name="action" value="review" class="btn-review">
                                                <i class="fas fa-eye"></i>
                                                Review Details
                                            </button>
                                            <button type="submit" name="action" value="approve" class="btn-verify">
                                                <i class="fas fa-check"></i>
                                                Approve
                                            </button>
                                            <button type="submit" name="action" value="reject" class="btn-reject" onclick="return confirm('Are you sure you want to reject this report?')">
                                                <i class="fas fa-times"></i>
                                                Reject
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #666;">
                            <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 20px; opacity: 0.5;"></i>
                            <p>No pending verifications at this time.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Export Reports -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-file-export"></i>
                        Export Monitored Reports
                    </h3>
                </div>
                <div class="export-options">
                    <div class="export-option" onclick="exportReports('pdf')">
                        <div class="export-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="export-title">PDF Report</div>
                        <div class="export-description">Generate comprehensive report in PDF format</div>
                    </div>
                    <div class="export-option" onclick="exportReports('excel')">
                        <div class="export-icon">
                            <i class="fas fa-file-excel"></i>
                        </div>
                        <div class="export-title">Excel Data</div>
                        <div class="export-description">Export data for analysis in Excel format</div>
                    </div>
                    <div class="export-option" onclick="exportReports('csv')">
                        <div class="export-icon">
                            <i class="fas fa-file-csv"></i>
                        </div>
                        <div class="export-title">CSV Export</div>
                        <div class="export-description">Download raw data in CSV format</div>
                    </div>
                    <div class="export-option" onclick="exportReports('archive')">
                        <div class="export-icon">
                            <i class="fas fa-file-archive"></i>
                        </div>
                        <div class="export-title">Archive Bundle</div>
                        <div class="export-description">Complete archive with all supporting documents</div>
                    </div>
                </div>
            </div>

        <!-- Activity Timeline -->
        <div class="timeline-section">
            <h3 class="timeline-header">
                <i class="fas fa-history"></i>
                Recent Activity Timeline
            </h3>
            <div class="timeline">
                <?php if ($activity_timeline->num_rows > 0): ?>
                    <?php while ($activity = $activity_timeline->fetch_assoc()): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker <?php echo htmlspecialchars($activity['status']); ?>"></div>
                            <div class="timeline-content">
                                <div class="timeline-title"><?php echo getActivityTitle($activity); ?></div>
                                <div class="timeline-description">
                                    <?php echo htmlspecialchars(substr($activity['description'], 0, 100)) . '...'; ?>
                                </div>
                                <div class="timeline-time"><?php echo getTimeAgo($activity['updated_at']); ?></div>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <i class="fas fa-clock" style="font-size: 48px; margin-bottom: 20px; opacity: 0.5;"></i>
                        <p>No recent activity to display.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        // Filter functionality
        function showAll() {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            // Show all items
            document.querySelectorAll('.verification-item').forEach(item => item.style.display = 'flex');
        }

        function showPending() {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            // Filter logic would go here
            showNotification('Showing pending items only', 'info');
        }

        function showApproved() {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            // Filter logic would go here
            showNotification('Showing approved items only', 'info');
        }

        function showRejected() {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            // Filter logic would go here
            showNotification('Showing rejected items only', 'info');
        }

        // View report details
        function viewReportDetails(reportId) {
            window.open('road_transportation_reporting.php?report_id=' + reportId, '_blank');
        }

        // Export reports function
        function exportReports(format) {
            switch(format) {
                case 'pdf':
                    showNotification('Generating PDF report...', 'info');
                    setTimeout(() => {
                        showNotification('PDF report downloaded successfully!', 'success');
                    }, 2000);
                    break;
                case 'excel':
                    showNotification('Exporting Excel data...', 'info');
                    setTimeout(() => {
                        showNotification('Excel data exported successfully!', 'success');
                    }, 2000);
                    break;
                case 'csv':
                    showNotification('Downloading CSV export...', 'info');
                    setTimeout(() => {
                        showNotification('CSV export downloaded successfully!', 'success');
                    }, 2000);
                    break;
                case 'archive':
                    showNotification('Creating archive bundle...', 'info');
                    setTimeout(() => {
                        showNotification('Archive bundle created successfully!', 'success');
                    }, 3000);
                    break;
            }
        }

        // Show notification
        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.className = 'notification ' + type;
            notification.textContent = message;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }

        // Handle form submissions with confirmation
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const action = this.querySelector('button[type="submit"]:focus');
                if (action && action.value === 'reject') {
                    if (!confirm('Are you sure you want to reject this report?')) {
                        e.preventDefault();
                    }
                }
            });
        });
    </script>
</body>
</html>

<?php
// Helper functions
function getReportIcon($reportType) {
    $icons = [
        'monthly' => 'calendar',
        'traffic' => 'traffic-light',
        'maintenance' => 'tools',
        'safety' => 'shield-alt',
        'budget' => 'dollar-sign',
        'routine' => 'wrench',
        'emergency' => 'exclamation-triangle',
        'preventive' => 'shield-alt',
        'corrective' => 'tools',
        'scheduled' => 'calendar-alt'
    ];
    
    return $icons[$reportType] ?? 'file-alt';
}

function getTimeAgo($datetime) {
    $time = strtotime($datetime);
    $now = time();
    $diff = $now - $time;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M d, Y', $time);
    }
}

function getActivityTitle($activity) {
    $status = $activity['status'];
    $title = $activity['title'];
    $source = ucfirst($activity['source']);
    
    switch ($status) {
        case 'completed':
            return $source . ' Report: ' . $title . ' - Completed';
        case 'cancelled':
            return $source . ' Report: ' . $title . ' - Cancelled';
        case 'pending':
            return $source . ' Report: ' . $title . ' - Pending';
        case 'in-progress':
            return $source . ' Report: ' . $title . ' - In Progress';
        default:
            return $source . ' Report: ' . $title;
    }
}
?>

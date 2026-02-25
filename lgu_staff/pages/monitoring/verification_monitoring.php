<?php
require_once '../includes/session_config.php';
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
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at FROM road_transportation_reports WHERE status = 'pending')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at FROM road_maintenance_reports WHERE status = 'pending')
              ORDER BY created_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getPendingVerifications: " . $conn->error);
    }
    return $result;
}

// Function to get approved reports
function getApprovedReports($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at FROM road_transportation_reports WHERE status = 'completed')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at FROM road_maintenance_reports WHERE status = 'completed')
              ORDER BY updated_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getApprovedReports: " . $conn->error);
    }
    return $result;
}

// Function to get rejected reports
function getRejectedReports($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at FROM road_transportation_reports WHERE status = 'cancelled')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at FROM road_maintenance_reports WHERE status = 'cancelled')
              ORDER BY updated_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getRejectedReports: " . $conn->error);
    }
    return $result;
}

// Function to get all reports (for filtering)
function getAllReports($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at FROM road_transportation_reports)
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at FROM road_maintenance_reports)
              ORDER BY created_at DESC";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getAllReports: " . $conn->error);
    }
    return $result;
}

// Function to get recent approvals (for timeline)
function getRecentApprovals($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at FROM road_transportation_reports WHERE status = 'completed')
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at FROM road_maintenance_reports WHERE status = 'completed')
              ORDER BY updated_at DESC LIMIT 10";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getRecentApprovals: " . $conn->error);
    }
    return $result;
}

// Function to get activity timeline
function getActivityTimeline($conn) {
    $query = "(SELECT 'transport' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, attachments, latitude, longitude, created_at, updated_at FROM road_transportation_reports)
              UNION ALL
              (SELECT 'maintenance' as source, id, report_id, title, report_type, department, priority, status, created_date, due_date, description, location, NULL as attachments, NULL as latitude, NULL as longitude, created_at, updated_at FROM road_maintenance_reports)
              ORDER BY updated_at DESC LIMIT 5";
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error in getActivityTimeline: " . $conn->error);
    }
    return $result;
}

// Handle verification actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && isset($_POST['report_id']) && isset($_POST['source'])) {
        $report_id = (int) $_POST['report_id'];
        $source = $_POST['source'];
        $action = $_POST['action'];
        $table = ($source === 'transport') ? 'road_transportation_reports' : 'road_maintenance_reports';
        
        // Delete report (remove specific report)
        if ($action === 'delete') {
            $query = "DELETE FROM $table WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->bind_param('i', $report_id);
            $stmt->execute();
            $_SESSION['verification_message'] = 'Report removed successfully.';
            header('Location: ../monitoring/verification_monitoring.php');
            exit();
        }
        
        // Update report status
        $status = '';
        $audit_status = '';
        switch ($action) {
            case 'approve':
                $status = 'completed';
                $audit_status = 'approved';
                break;
            case 'reject':
                $status = 'cancelled';
                $audit_status = 'rejected';
                break;
            case 'review':
                $status = 'in-progress';
                $audit_status = 'pending';
                break;
        }
        
        if ($status) {
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
            $audit_type = 'compliance'; // verification actions logged under compliance
            $auditor = $_SESSION['username'] ?? 'Unknown';
            $description = "Report #$report_id from $source table has been " . $action . "ed by $auditor";
            
            $audit_stmt->bind_param('ssssss', $audit_id, $title, $audit_type, $audit_status, $auditor, $description);
            $audit_stmt->execute();
            
            // Return success message
            $_SESSION['verification_message'] = 'Report ' . $action . 'd successfully!';
        }
        
        header('Location: ../monitoring/verification_monitoring.php');
        exit();
    }
}

// Show success message if set
if (isset($_SESSION['verification_message'])) {
    $success_message = $_SESSION['verification_message'];
    unset($_SESSION['verification_message']);
}

// Get data
$stats = getVerificationStatistics($conn);
$pending_verifications = getPendingVerifications($conn);
$approved_reports = getApprovedReports($conn);
$rejected_reports = getRejectedReports($conn);
$all_reports = getAllReports($conn);
$recent_approvals = getRecentApprovals($conn);
$activity_timeline = getActivityTimeline($conn);

// Handle AJAX request for report details
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_report_details') {
    header('Content-Type: application/json');
    $report_id = isset($_GET['report_id']) ? (int)$_GET['report_id'] : 0;
    $source = isset($_GET['source']) ? $_GET['source'] : '';
    
    if ($report_id && $source) {
        $table = ($source === 'transport') ? 'road_transportation_reports' : 'road_maintenance_reports';
        $query = "SELECT * FROM $table WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param('i', $report_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $report = $result->fetch_assoc();
        
        if ($report) {
            echo json_encode(['success' => true, 'report' => $report]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Report not found']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    }
    exit;
}

// Publish completed project to Public Transparency (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_completed_project') {
    header('Content-Type: application/json');
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $completed_date = trim($_POST['completed_date'] ?? '');
    $cost = isset($_POST['cost']) ? (float) $_POST['cost'] : 0;
    $completed_by = trim($_POST['completed_by'] ?? '');
    $photo = trim($_POST['photo'] ?? '');
    if ($title === '') {
        echo json_encode(['success' => false, 'message' => 'Title is required.']);
        exit;
    }
    // Ensure table exists
    $conn->query("CREATE TABLE IF NOT EXISTS published_completed_projects (
        id int(11) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(255) NOT NULL,
        description text,
        location varchar(255) DEFAULT NULL,
        completed_date date DEFAULT NULL,
        cost decimal(12,2) DEFAULT NULL,
        completed_by varchar(255) DEFAULT NULL,
        photo varchar(500) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $stmt = $conn->prepare("INSERT INTO published_completed_projects (title, description, location, completed_date, cost, completed_by, photo) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $date_val = ($completed_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $completed_date)) ? $completed_date : null;
    $stmt->bind_param('ssssdss', $title, $description, $location, $date_val, $cost, $completed_by, $photo);
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Published to Public Transparency.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to publish: ' . $conn->error]);
    }
    exit;
}

// Upload photo for completed project (AJAX, multipart)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_completed_project_photo' && !empty($_FILES['photo'])) {
    header('Content-Type: application/json');
    $upload_dir = __DIR__ . '/../../uploads/completed_projects';
    $upload_dir = str_replace('\\', '/', $upload_dir);
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $result = handle_file_upload($_FILES['photo'], $upload_dir, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    if ($result['success']) {
        $relative_path = 'uploads/completed_projects/' . $result['filename'];
        echo json_encode(['success' => true, 'path' => $relative_path]);
    } else {
        echo json_encode(['success' => false, 'message' => $result['error'] ?? 'Upload failed']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification & Monitoring Reports | LGU Staff</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        html { scroll-behavior: smooth; }
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
            background: #ffffff;
            padding: 25px 30px;
            border-radius: 16px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
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
            background: #ffffff;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid #e0e0e0;
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

        .btn-maintenance {
            padding: 8px 16px;
            background: linear-gradient(135deg, #17a2b8, #138496);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn-maintenance:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(23, 162, 184, 0.4);
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
        
        .expanded-details {
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                max-height: 0;
            }
            to {
                opacity: 1;
                max-height: 2000px;
            }
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        
        .detail-item {
            padding: 12px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 8px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }
        
        .detail-item.full-width {
            grid-column: 1 / -1;
        }
        
        .detail-item strong {
            color: #1e3c72;
            display: block;
            margin-bottom: 5px;
            font-size: 13px;
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

        .btn-remove {
            padding: 8px 16px;
            background: rgba(108, 117, 125, 0.9);
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

        .btn-remove:hover {
            background: #dc3545;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
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
            padding: 15px 20px;
            background: #ffffff;
            border-radius: 16px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .filter-tab {
            padding: 10px 20px;
            background: transparent;
            border: none;
            color: #1e3c72;
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
            border-bottom: 3px solid #3762c8;
            font-weight: 600;
            background: rgba(55, 98, 200, 0.05);
            border-radius: 8px 8px 0 0;
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

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
            box-sizing: border-box;
            overflow-y: auto;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 16px;
            padding: 30px;
            max-width: 900px;
            width: 100%;
            max-height: calc(100vh - 40px);
            position: relative;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            margin: auto;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
        }
        
        .modal-body {
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            padding-right: 10px;
            margin-right: -10px;
        }
        
        .modal-body::-webkit-scrollbar {
            width: 8px;
        }
        
        .modal-body::-webkit-scrollbar-track {
            background: rgba(55, 98, 200, 0.1);
            border-radius: 4px;
        }
        
        .modal-body::-webkit-scrollbar-thumb {
            background: rgba(55, 98, 200, 0.3);
            border-radius: 4px;
        }
        
        .modal-body::-webkit-scrollbar-thumb:hover {
            background: rgba(55, 98, 200, 0.5);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
            flex-shrink: 0;
        }
        
        .modal-header h2 {
            color: #1e3c72;
            font-size: 24px;
            margin: 0;
            flex: 1;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            color: #666;
            cursor: pointer;
            padding: 0;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s;
            flex-shrink: 0;
            margin-left: 15px;
        }
        
        .modal-close:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .detail-row {
            display: flex;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .detail-label {
            font-weight: 600;
            color: #333;
            width: 150px;
            flex-shrink: 0;
        }
        
        .detail-value {
            color: #666;
            flex: 1;
        }
        
        .modal-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
            margin-top: 10px;
            cursor: pointer;
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid rgba(55, 98, 200, 0.1);
            flex-shrink: 0;
        }

        /* Repaired Reports section */
        .repaired-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid rgba(55, 98, 200, 0.1);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.08);
        }

        .repaired-header {
            font-size: 20px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .repaired-desc {
            font-size: 14px;
            color: #666;
            margin-bottom: 20px;
        }

        /* Single-column feed (Facebook-style): one post per block, scroll to see next */
        .repaired-grid {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 28px;
        }

        .repaired-card {
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 560px;
            background: #fff;
            border: 1px solid rgba(55, 98, 200, 0.12);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.06);
            transition: box-shadow 0.3s ease;
        }

        .repaired-card:hover {
            box-shadow: 0 6px 24px rgba(55, 98, 200, 0.12);
        }

        .repaired-card-image {
            width: 100%;
            height: 220px;
            min-height: 220px;
            flex-shrink: 0;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(55, 98, 200, 0.15), rgba(30, 60, 114, 0.1));
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .repaired-card-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            display: block;
        }

        .repaired-image-placeholder {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: rgba(55, 98, 200, 0.5);
            font-size: 14px;
        }

        .repaired-image-placeholder i {
            font-size: 40px;
            margin-bottom: 8px;
        }

        .repaired-card-body {
            padding: 18px;
        }

        .repaired-card-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 10px;
            line-height: 1.3;
        }

        .repaired-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            font-size: 12px;
            color: #666;
            margin-bottom: 12px;
        }

        .repaired-meta i {
            color: #3762c8;
            margin-right: 4px;
        }

        .repaired-card-desc {
            font-size: 13px;
            color: #555;
            line-height: 1.5;
            margin-bottom: 14px;
        }

        .repaired-card-footer {
            padding-top: 12px;
            border-top: 1px solid rgba(55, 98, 200, 0.1);
            font-size: 13px;
            color: #333;
        }

        .repaired-cost {
            margin-bottom: 6px;
        }

        .repaired-by {
            color: #666;
        }

        .repaired-note {
            font-size: 13px;
            color: #888;
            margin-top: 20px;
            font-style: italic;
        }

        .repaired-card-actions {
            display: flex;
            gap: 10px;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid rgba(55, 98, 200, 0.1);
        }

        .btn-edit-repaired {
            padding: 8px 14px;
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
        }

        .btn-edit-repaired:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(108, 117, 125, 0.4);
        }

        .btn-publish-repaired {
            padding: 8px 14px;
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
        }

        .btn-publish-repaired:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
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
            
            .modal-overlay {
                padding: 10px;
            }
            
            .modal-content {
                width: 100%;
                max-width: 100%;
                padding: 20px;
                max-height: calc(100vh - 20px);
            }
            
            .modal-header h2 {
                font-size: 20px;
            }
            
            .detail-row {
                flex-direction: column;
            }
            
            .detail-label {
                width: 100%;
                margin-bottom: 5px;
            }
            
            .detail-grid {
                grid-template-columns: 1fr;
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
                    <h1>Verification & Monitoring Reports</h1>
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
            <button class="filter-tab" onclick="showStaffReports()">LGU Staff</button>
            <button class="filter-tab" onclick="showCIMReports()">CIM Reports</button>
        </div>

        <!-- Workflow Container -->
        <div class="workflow-container">
            <!-- All Reports (filterable) -->
            <div class="workflow-card">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <i class="fas fa-list"></i>
                        <span id="section-title">All Reports</span>
                        <span class="workflow-badge" id="section-badge"><?php echo $all_reports->num_rows; ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content" id="reports-container">
                    <?php 
                    // Reset pointer and display all reports
                    $all_reports->data_seek(0);
                    if ($all_reports->num_rows > 0): 
                    ?>
                        <?php while ($report = $all_reports->fetch_assoc()): 
                            $status_class = '';
                            if ($report['status'] === 'completed') $status_class = 'approved';
                            elseif ($report['status'] === 'cancelled') $status_class = 'rejected';
                            elseif ($report['status'] === 'pending') $status_class = 'pending';
                            elseif ($report['status'] === 'in-progress') $status_class = 'in-progress';
                        ?>
                            <div class="verification-item" data-status="<?php echo htmlspecialchars($report['status']); ?>" data-source="<?php echo htmlspecialchars($report['source']); ?>" data-created-by="<?php echo htmlspecialchars($report['created_by'] ?? ''); ?>" data-reporter-name="<?php echo htmlspecialchars($report['reporter_name'] ?? ''); ?>">
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
                                            <?php 
                                            if (!empty($report['latitude']) && !empty($report['longitude'])) {
                                                echo '<a href="https://www.google.com/maps?q=' . htmlspecialchars($report['latitude']) . ',' . htmlspecialchars($report['longitude']) . '" target="_blank" style="color: #3762c8; text-decoration: none;">';
                                                echo htmlspecialchars($report['location'] ?? 'View on Map');
                                                echo ' <i class="fas fa-external-link-alt" style="font-size: 10px;"></i></a>';
                                            } else {
                                                echo htmlspecialchars($report['location'] ?? 'Not specified');
                                            }
                                            ?>
                                        </div>
                                    </div>
                                    <div class="verification-description">
                                        <?php 
                                        $desc = $report['description'] ?? '';
                                        echo htmlspecialchars(strlen($desc) > 150 ? substr($desc, 0, 150) . '...' : $desc); 
                                        ?>
                                    </div>
                                    <?php
                                    // Display attached images if any
                                    if (!empty($report['attachments'])) {
                                        $attachments = json_decode($report['attachments'], true);
                                        if (is_array($attachments) && !empty($attachments)) {
                                            foreach ($attachments as $attachment) {
                                                if (isset($attachment['type']) && $attachment['type'] === 'image' && isset($attachment['file_path'])) {
                                                    // Path from pages/ directory: ../../ goes to project root
                                                    echo '<div style="margin-top: 12px;">';
                                                    echo '<img src="../../' . htmlspecialchars($attachment['file_path']) . '" alt="Report Image" style="max-width: 100%; max-height: 200px; border-radius: 8px; border: 1px solid rgba(55, 98, 200, 0.3); cursor: pointer;" onclick="window.open(this.src, \'_blank\')" title="Click to view full size" />';
                                                    echo '</div>';
                                                    break; // Show first image only
                                                }
                                            }
                                        }
                                    }
                                    ?>
                                    <!-- Expandable Details Section -->
                                    <div class="expanded-details" id="details-<?php echo $report['id']; ?>" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 2px solid rgba(55, 98, 200, 0.1);">
                                        <div class="detail-grid">
                                            <div class="detail-item">
                                                <strong>Report ID:</strong> <?php echo htmlspecialchars($report['report_id'] ?? 'N/A'); ?>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Type:</strong> <?php echo htmlspecialchars($report['report_type'] ?? 'N/A'); ?>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Priority:</strong> <span class="workflow-badge priority-<?php echo htmlspecialchars($report['priority'] ?? 'medium'); ?>"><?php echo htmlspecialchars($report['priority'] ?? 'medium'); ?></span>
                                            </div>
                                            <div class="detail-item">
                                                <strong>Status:</strong> <span class="workflow-badge <?php echo $report['status'] === 'completed' ? 'approved' : ($report['status'] === 'cancelled' ? 'rejected' : 'pending'); ?>"><?php echo htmlspecialchars($report['status'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="detail-item full-width">
                                                <strong>Full Description:</strong>
                                                <div style="margin-top: 8px; padding: 12px; background: rgba(55, 98, 200, 0.05); border-radius: 8px;">
                                                    <?php echo nl2br(htmlspecialchars($report['description'] ?? 'No description provided')); ?>
                                                </div>
                                            </div>
                                            <?php if (!empty($report['latitude']) && !empty($report['longitude'])): ?>
                                            <div class="detail-item full-width">
                                                <strong>Location Coordinates:</strong>
                                                <div style="margin-top: 8px;">
                                                    Latitude: <?php echo htmlspecialchars($report['latitude']); ?>, 
                                                    Longitude: <?php echo htmlspecialchars($report['longitude']); ?>
                                                    <a href="https://www.google.com/maps?q=<?php echo htmlspecialchars($report['latitude']); ?>,<?php echo htmlspecialchars($report['longitude']); ?>" target="_blank" style="color: #3762c8; margin-left: 10px;">
                                                        <i class="fas fa-map-marker-alt"></i> View on Map
                                                    </a>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty($report['attachments'])): 
                                                $attachments = json_decode($report['attachments'], true);
                                                if (is_array($attachments) && !empty($attachments)): ?>
                                            <div class="detail-item full-width">
                                                <strong>Attached Images:</strong>
                                                <div style="margin-top: 12px; display: flex; gap: 15px; flex-wrap: wrap;">
                                                    <?php foreach ($attachments as $attachment): 
                                                        if (isset($attachment['type']) && $attachment['type'] === 'image' && isset($attachment['file_path'])): ?>
                                                        <img src="../../<?php echo htmlspecialchars($attachment['file_path']); ?>" 
                                                             alt="Report Image" 
                                                             style="max-width: 300px; max-height: 300px; border-radius: 8px; border: 1px solid rgba(55, 98, 200, 0.3); cursor: pointer;" 
                                                             onclick="window.open(this.src, '_blank')" 
                                                             title="Click to view full size" />
                                                    <?php endif; endforeach; ?>
                                                </div>
                                            </div>
                                            <?php endif; endif; ?>
                                            <div class="detail-item">
                                                <strong>Created:</strong> <?php echo htmlspecialchars($report['created_at'] ?? 'N/A'); ?>
                                            </div>
                                            <?php if (!empty($report['updated_at']) && $report['updated_at'] !== $report['created_at']): ?>
                                            <div class="detail-item">
                                                <strong>Last Updated:</strong> <?php echo htmlspecialchars($report['updated_at']); ?>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="verification-actions">
                                        <?php if ($report['status'] === 'pending' || $report['status'] === 'in-progress'): ?>
                                            <?php if ($report['status'] === 'in-progress'): ?>
                                                <span class="workflow-badge" style="margin-right: 10px;">In Progress</span>
                                            <?php endif; ?>
                                            <button type="button" onclick="toggleDetails(<?php echo $report['id']; ?>)" class="btn-review">
                                                <i class="fas fa-eye" id="icon-<?php echo $report['id']; ?>"></i>
                                                <span id="text-<?php echo $report['id']; ?>">View Details</span>
                                            </button>
                                            <form method="POST" style="display: inline-flex;">
                                                <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                <input type="hidden" name="source" value="<?php echo htmlspecialchars($report['source']); ?>">
                                                <button type="submit" name="action" value="approve" class="btn-verify">
                                                    <i class="fas fa-check"></i>
                                                    Approve
                                                </button>
                                            </form>
                                            <form method="POST" style="display: inline-flex;">
                                                <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                <input type="hidden" name="source" value="<?php echo htmlspecialchars($report['source']); ?>">
                                                <button type="submit" name="action" value="reject" class="btn-reject" onclick="return confirm('Are you sure you want to reject this report?')">
                                                    <i class="fas fa-times"></i>
                                                    Reject
                                                </button>
                                            </form>
                                        <?php elseif ($report['status'] === 'completed'): ?>
                                            <span class="workflow-badge approved" style="margin-right: 10px;">Approved</span>
                                            <button type="button" onclick="toggleDetails(<?php echo $report['id']; ?>)" class="btn-review">
                                                <i class="fas fa-eye" id="icon-<?php echo $report['id']; ?>"></i>
                                                <span id="text-<?php echo $report['id']; ?>">View Details</span>
                                            </button>
                                            <button type="button" onclick="sendToMaintenance()" class="btn-maintenance">
                                                <i class="fas fa-paper-plane"></i> Send to Maintenance
                                            </button>
                                        <?php elseif ($report['status'] === 'cancelled'): ?>
                                            <span class="workflow-badge rejected" style="margin-right: 10px;">Rejected</span>
                                            <button type="button" onclick="toggleDetails(<?php echo $report['id']; ?>)" class="btn-review">
                                                <i class="fas fa-eye" id="icon-<?php echo $report['id']; ?>"></i>
                                                <span id="text-<?php echo $report['id']; ?>">View Details</span>
                                            </button>
                                        <?php else: ?>
                                            <span class="workflow-badge" style="margin-right: 10px;"><?php echo ucfirst($report['status']); ?></span>
                                            <button type="button" onclick="toggleDetails(<?php echo $report['id']; ?>)" class="btn-review">
                                                <i class="fas fa-eye" id="icon-<?php echo $report['id']; ?>"></i>
                                                <span id="text-<?php echo $report['id']; ?>">View Details</span>
                                            </button>
                                        <?php endif; ?>
                                        <form method="POST" style="display: inline-flex; margin-left: auto;" onsubmit="return confirm('Remove this report permanently?');">
                                            <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                            <input type="hidden" name="source" value="<?php echo htmlspecialchars($report['source']); ?>">
                                            <button type="submit" name="action" value="delete" class="btn-remove" title="Remove report">
                                                <i class="fas fa-trash-alt"></i> Remove
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

        <!-- Repaired Reports / Completed Projects -->
        <div class="repaired-section">
            <h3 class="repaired-header">
                <i class="fas fa-tools"></i>
                Repaired Reports &ndash; Completed Projects
            </h3>
            <p class="repaired-desc">Compiled repairs with project details, cost, and completion info.</p>
            <div class="repaired-grid">
                <?php
                // Placeholder repaired/completed projects (replace with DB when backend is ready)
                $repaired_samples = [
                    [
                        'title' => 'Main Street Pothole Repair',
                        'description' => 'Filled and sealed potholes along Main St from Block 1 to 5. Surface leveled and marked for traffic.',
                        'photo' => null,
                        'cost' => 125000,
                        'completed_by' => 'Engr. Mario Reyes',
                        'completed_date' => '2024-02-15',
                        'location' => 'Main Street, Downtown'
                    ],
                    [
                        'title' => 'Drainage Clearing – Barangay San Jose',
                        'description' => 'Cleared clogged canals and replaced damaged grates. Improved runoff flow and reduced flood risk.',
                        'photo' => null,
                        'cost' => 89000,
                        'completed_by' => 'Engr. Maria Santos',
                        'completed_date' => '2024-02-12',
                        'location' => 'Barangay San Jose'
                    ],
                    [
                        'title' => 'Street Lighting Repair – Highway 101',
                        'description' => 'Replaced 12 non-working lamp posts and wiring. All lights tested and operational.',
                        'photo' => null,
                        'cost' => 256000,
                        'completed_by' => 'Engr. Juan Dela Cruz',
                        'completed_date' => '2024-02-10',
                        'location' => 'Highway 101 North'
                    ]
                ];
                foreach ($repaired_samples as $i => $rep): ?>
                <?php $rep_photo = $rep['photo'] ?? ''; ?>
                <div class="repaired-card" data-rep-index="<?php echo $i; ?>"
                     data-rep-title="<?php echo htmlspecialchars($rep['title']); ?>"
                     data-rep-description="<?php echo htmlspecialchars($rep['description']); ?>"
                     data-rep-cost="<?php echo (int)$rep['cost']; ?>"
                     data-rep-completed-by="<?php echo htmlspecialchars($rep['completed_by']); ?>"
                     data-rep-location="<?php echo htmlspecialchars($rep['location']); ?>"
                     data-rep-date="<?php echo htmlspecialchars($rep['completed_date']); ?>"
                     data-rep-photo="<?php echo htmlspecialchars($rep_photo); ?>">
                    <div class="repaired-card-image">
                        <?php if (!empty($rep_photo)): ?>
                            <img src="../../<?php echo htmlspecialchars($rep_photo); ?>" alt="<?php echo htmlspecialchars($rep['title']); ?>">
                        <?php else: ?>
                            <div class="repaired-image-placeholder">
                                <i class="fas fa-road"></i>
                                <span>Project photo</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="repaired-card-body">
                        <h4 class="repaired-card-title"><?php echo htmlspecialchars($rep['title']); ?></h4>
                        <div class="repaired-meta">
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($rep['location']); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($rep['completed_date']); ?></span>
                        </div>
                        <p class="repaired-card-desc"><?php echo nl2br(htmlspecialchars($rep['description'])); ?></p>
                        <div class="repaired-card-footer">
                            <div class="repaired-cost">
                                <strong>Cost:</strong> ₱<?php echo number_format($rep['cost'], 0); ?>
                            </div>
                            <div class="repaired-by">
                                <strong>Completed by:</strong> <?php echo htmlspecialchars($rep['completed_by']); ?>
                            </div>
                        </div>
                        <div class="repaired-card-actions">
                            <button type="button" class="btn-edit-repaired" onclick="openEditRepairedModal(<?php echo $i; ?>)">
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button type="button" class="btn-publish-repaired" onclick="openPublishRepairedModal(<?php echo $i; ?>)">
                                <i class="fas fa-globe"></i> Publish to Public
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="repaired-note">Repaired reports will be compiled here once sent from Maintenance Department.</p>
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

    <!-- Sent to Maintenance confirmation modal -->
    <div id="sentToMaintenanceModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 420px;">
            <div class="modal-header">
                <h2><i class="fas fa-check-circle" style="color: #28a745;"></i> Sent</h2>
                <button type="button" class="modal-close" onclick="closeSentModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size: 16px; color: #333;">Report sent to Maintenance Department.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-verify" onclick="closeSentModal()">OK</button>
            </div>
        </div>
    </div>

    <!-- Edit Completed Project modal -->
    <div id="editRepairedModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 560px;">
            <div class="modal-header">
                <h2><i class="fas fa-edit"></i> Edit Completed Project</h2>
                <button type="button" class="modal-close" onclick="closeEditRepairedModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editRepairedIndex" value="">
                <div class="detail-row">
                    <label class="detail-label" for="editRepairedTitle">Project title</label>
                    <input type="text" id="editRepairedTitle" class="detail-value" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; width: 100%;" placeholder="Project title">
                </div>
                <div class="detail-row">
                    <label class="detail-label" for="editRepairedLocation">Location</label>
                    <input type="text" id="editRepairedLocation" class="detail-value" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; width: 100%;" placeholder="Location">
                </div>
                <div class="detail-row">
                    <label class="detail-label" for="editRepairedDescription">Description</label>
                    <textarea id="editRepairedDescription" class="detail-value" rows="4" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; width: 100%;" placeholder="Description"></textarea>
                </div>
                <div class="detail-row">
                    <label class="detail-label" for="editRepairedCost">Cost (₱)</label>
                    <input type="number" id="editRepairedCost" class="detail-value" min="0" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; width: 100%;" placeholder="0">
                </div>
                <div class="detail-row">
                    <label class="detail-label" for="editRepairedCompletedBy">Completed by</label>
                    <input type="text" id="editRepairedCompletedBy" class="detail-value" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; width: 100%;" placeholder="Name">
                </div>
                <div class="detail-row">
                    <label class="detail-label" for="editRepairedDate">Completion date</label>
                    <input type="date" id="editRepairedDate" class="detail-value" style="padding: 8px; border: 1px solid #ddd; border-radius: 6px; width: 100%;">
                </div>
                <div class="detail-row">
                    <label class="detail-label">Project photo</label>
                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                        <input type="file" id="editRepairedPhoto" accept="image/jpeg,image/png,image/gif,image/webp" style="font-size: 14px;">
                        <span id="editRepairedPhotoName" style="font-size: 13px; color: #666;"></span>
                    </div>
                    <p style="font-size: 12px; color: #888; margin-top: 6px;">Optional. Shows on Public Transparency when published.</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-review" onclick="closeEditRepairedModal()">Cancel</button>
                <button type="button" class="btn-verify" onclick="saveEditRepaired()"><i class="fas fa-save"></i> Save</button>
            </div>
        </div>
    </div>

    <!-- Publish to Public Transparency confirmation modal -->
    <div id="publishRepairedModal" class="modal-overlay">
        <div class="modal-content" style="max-width: 420px;">
            <div class="modal-header">
                <h2><i class="fas fa-globe" style="color: #28a745;"></i> Published</h2>
                <button type="button" class="modal-close" onclick="closePublishRepairedModal()" aria-label="Close">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size: 16px; color: #333;">This completed project has been published to Public Transparency. Citizens can now view it on the public page.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-verify" onclick="closePublishRepairedModal()">OK</button>
            </div>
        </div>
    </div>

    <script>
        // Filter functionality
        function showAll() {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            document.querySelectorAll('.verification-item').forEach(item => item.style.display = 'flex');
            document.getElementById('section-title').textContent = 'All Reports';
            document.getElementById('section-badge').textContent = document.querySelectorAll('.verification-item').length;
        }

        function showPending() {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            const items = document.querySelectorAll('.verification-item');
            let count = 0;
            items.forEach(item => {
                if (item.dataset.status === 'pending') {
                    item.style.display = 'flex';
                    count++;
                } else {
                    item.style.display = 'none';
                }
            });
            document.getElementById('section-title').textContent = 'Pending Reports';
            document.getElementById('section-badge').textContent = count;
        }

        function showApproved() {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            const items = document.querySelectorAll('.verification-item');
            let count = 0;
            items.forEach(item => {
                if (item.dataset.status === 'completed') {
                    item.style.display = 'flex';
                    count++;
                } else {
                    item.style.display = 'none';
                }
            });
            document.getElementById('section-title').textContent = 'Approved Reports';
            document.getElementById('section-badge').textContent = count;
        }

        function showRejected() {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            const items = document.querySelectorAll('.verification-item');
            let count = 0;
            items.forEach(item => {
                if (item.dataset.status === 'cancelled') {
                    item.style.display = 'flex';
                    count++;
                } else {
                    item.style.display = 'none';
                }
            });
            document.getElementById('section-title').textContent = 'Rejected Reports';
            document.getElementById('section-badge').textContent = count;
        }

        function showStaffReports() {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            const items = document.querySelectorAll('.verification-item');
            let count = 0;
            
            items.forEach(item => {
                if (item.dataset.createdBy && item.dataset.createdBy !== '0' && item.dataset.createdBy !== '') {
                    item.style.display = 'flex';
                    count++;
                } else {
                    item.style.display = 'none';
                }
            });
            document.getElementById('section-title').textContent = 'LGU Staff Reports';
            document.getElementById('section-badge').textContent = count;
        }

        function showCIMReports() {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            const items = document.querySelectorAll('.verification-item');
            let count = 0;
            
            items.forEach(item => {
                if (item.dataset.reporterName && item.dataset.reporterName.toLowerCase().includes('cim')) {
                    item.style.display = 'flex';
                    count++;
                } else {
                    item.style.display = 'none';
                }
            });
            document.getElementById('section-title').textContent = 'CIM Reports';
            document.getElementById('section-badge').textContent = count;
        }

        function showLGUOfficerReports() {
            document.querySelectorAll('.filter-tab').forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            const items = document.querySelectorAll('.verification-item');
            let count = 0;
            
            items.forEach(item => {
                if (item.dataset.reporterName && item.dataset.reporterName.toLowerCase().includes('officer')) {
                    item.style.display = 'flex';
                    count++;
                } else {
                    item.style.display = 'none';
                }
            });
            document.getElementById('section-title').textContent = 'LGU Officer Reports';
            document.getElementById('section-badge').textContent = count;
        }

        // Toggle expanded details inline
        function toggleDetails(reportId) {
            const detailsDiv = document.getElementById('details-' + reportId);
            const icon = document.getElementById('icon-' + reportId);
            const text = document.getElementById('text-' + reportId);
            
            if (detailsDiv.style.display === 'none') {
                detailsDiv.style.display = 'block';
                icon.className = 'fas fa-eye-slash';
                text.textContent = 'Hide Details';
            } else {
                detailsDiv.style.display = 'none';
                icon.className = 'fas fa-eye';
                text.textContent = 'View Details';
            }
        }

        function sendToMaintenance() {
            document.getElementById('sentToMaintenanceModal').classList.add('active');
        }

        function closeSentModal() {
            document.getElementById('sentToMaintenanceModal').classList.remove('active');
        }

        function openEditRepairedModal(index) {
            var card = document.querySelector('.repaired-card[data-rep-index="' + index + '"]');
            if (!card) return;
            document.getElementById('editRepairedIndex').value = index;
            document.getElementById('editRepairedTitle').value = card.dataset.repTitle || '';
            document.getElementById('editRepairedLocation').value = card.dataset.repLocation || '';
            document.getElementById('editRepairedDescription').value = card.dataset.repDescription || '';
            document.getElementById('editRepairedCost').value = card.dataset.repCost || '';
            document.getElementById('editRepairedCompletedBy').value = card.dataset.repCompletedBy || '';
            document.getElementById('editRepairedDate').value = card.dataset.repDate || '';
            document.getElementById('editRepairedPhoto').value = '';
            document.getElementById('editRepairedPhotoName').textContent = '';
            document.getElementById('editRepairedModal').classList.add('active');
        }
        document.getElementById('editRepairedPhoto').addEventListener('change', function() {
            var name = this.files && this.files[0] ? this.files[0].name : '';
            document.getElementById('editRepairedPhotoName').textContent = name ? 'Selected: ' + name : '';
        });

        function closeEditRepairedModal() {
            document.getElementById('editRepairedModal').classList.remove('active');
        }

        function updateRepairedCardImage(card, photoPath) {
            var imgWrap = card.querySelector('.repaired-card-image');
            if (!imgWrap) return;
            if (photoPath) {
                imgWrap.innerHTML = '<img src="../../' + photoPath.replace(/^\/+/, '') + '" alt="Project" style="width:100%;height:100%;object-fit:cover;">';
            } else {
                imgWrap.innerHTML = '<div class="repaired-image-placeholder"><i class="fas fa-road"></i><span>Project photo</span></div>';
            }
        }

        function saveEditRepaired() {
            var index = document.getElementById('editRepairedIndex').value;
            var card = document.querySelector('.repaired-card[data-rep-index="' + index + '"]');
            if (!card) { closeEditRepairedModal(); return; }
            var title = document.getElementById('editRepairedTitle').value.trim();
            var location = document.getElementById('editRepairedLocation').value.trim();
            var description = document.getElementById('editRepairedDescription').value.trim();
            var cost = document.getElementById('editRepairedCost').value;
            var completedBy = document.getElementById('editRepairedCompletedBy').value.trim();
            var date = document.getElementById('editRepairedDate').value;
            var photoInput = document.getElementById('editRepairedPhoto');
            var doSave = function(photoPath) {
                if (photoPath) card.dataset.repPhoto = photoPath; else card.dataset.repPhoto = '';
                updateRepairedCardImage(card, photoPath || null);
                card.dataset.repTitle = title;
                card.dataset.repLocation = location;
                card.dataset.repDescription = description;
                card.dataset.repCost = cost;
                card.dataset.repCompletedBy = completedBy;
                card.dataset.repDate = date;
                card.querySelector('.repaired-card-title').textContent = title || 'Untitled Project';
                card.querySelector('.repaired-meta').innerHTML = '<span><i class="fas fa-map-marker-alt"></i> ' + (location || '—') + '</span><span><i class="fas fa-calendar"></i> ' + (date || '—') + '</span>';
                card.querySelector('.repaired-card-desc').innerHTML = (description || 'No description.').replace(/\n/g, '<br>');
                card.querySelector('.repaired-cost').innerHTML = '<strong>Cost:</strong> ₱' + (cost ? parseInt(cost, 10).toLocaleString() : '0');
                card.querySelector('.repaired-by').innerHTML = '<strong>Completed by:</strong> ' + (completedBy || '—');
                closeEditRepairedModal();
                document.getElementById('editRepairedPhoto').value = '';
                document.getElementById('editRepairedPhotoName').textContent = '';
                showNotification('Changes saved.', 'success');
            };
            if (photoInput.files && photoInput.files[0]) {
                var formData = new FormData();
                formData.append('action', 'upload_completed_project_photo');
                formData.append('photo', photoInput.files[0]);
                fetch(window.location.href, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success) doSave(data.path); else { alert(data.message || 'Photo upload failed.'); doSave(null); }
                    })
                    .catch(function() { alert('Upload failed.'); doSave(null); });
            } else {
                doSave(card.dataset.repPhoto || null);
            }
        }

        function openPublishRepairedModal(index) {
            var card = document.querySelector('.repaired-card[data-rep-index="' + index + '"]');
            if (!card) {
                document.getElementById('publishRepairedModal').classList.add('active');
                return;
            }
            var title = (card.dataset.repTitle || '').trim();
            if (!title) {
                alert('Please add a project title before publishing.');
                return;
            }
            var formData = new FormData();
            formData.append('action', 'publish_completed_project');
            formData.append('title', card.dataset.repTitle || '');
            formData.append('description', card.dataset.repDescription || '');
            formData.append('location', card.dataset.repLocation || '');
            formData.append('completed_date', card.dataset.repDate || '');
            formData.append('cost', card.dataset.repCost || '0');
            formData.append('completed_by', card.dataset.repCompletedBy || '');
            formData.append('photo', card.dataset.repPhoto || '');
            var btn = card.querySelector('.btn-publish-repaired');
            if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publishing...'; }
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-globe"></i> Publish to Public'; }
                if (data.success) {
                    document.getElementById('publishRepairedModal').classList.add('active');
                } else {
                    alert(data.message || 'Failed to publish.');
                }
            })
            .catch(function() {
                if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fas fa-globe"></i> Publish to Public'; }
                alert('Network error. Please try again.');
            });
        }

        function closePublishRepairedModal() {
            document.getElementById('publishRepairedModal').classList.remove('active');
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
                const action = this.querySelector('button[type="submit"]');
                if (action && action.value === 'reject') {
                    if (!confirm('Are you sure you want to reject this report?')) {
                        e.preventDefault();
                    }
                }
            });
        });
        
        // Show success message if available
        <?php if (isset($success_message)): ?>
        showNotification('<?php echo htmlspecialchars($success_message); ?>', 'success');
        <?php endif; ?>
        
        // Close modal after form submission in modal (if element exists)
        var modalFooterEl = document.getElementById('modalFooter');
        if (modalFooterEl) {
            modalFooterEl.addEventListener('submit', function(e) {
                const form = e.target.closest('form');
                if (form) {
                    setTimeout(function() { if (typeof closeModal === 'function') closeModal(); }, 100);
                }
            });
        }

        // Close sent-to-maintenance modal when clicking overlay
        document.getElementById('sentToMaintenanceModal').addEventListener('click', function(e) {
            if (e.target === this) closeSentModal();
        });
        document.getElementById('editRepairedModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditRepairedModal();
        });
        document.getElementById('publishRepairedModal').addEventListener('click', function(e) {
            if (e.target === this) closePublishRepairedModal();
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
        'road_damage' => 'road',
        'infrastructure_issue' => 'map-marker-alt',
        'traffic_violation' => 'car-crash',
        'maintenance_request' => 'wrench',
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

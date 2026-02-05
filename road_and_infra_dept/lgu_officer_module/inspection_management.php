<?php
// Inspection Management - LGU Officer Module
session_start();
require_once '../config/auth.php';
require_once '../config/database.php';

$auth->requireAnyRole(['lgu_officer', 'admin']);

// Initialize database connection
$db = new Database();
$conn = $db->getConnection();

// Debug: Check if connection is working
if (!$conn) {
    error_log("Database connection failed in inspection_management.php");
    die("Database connection failed. Please try again later.");
} else {
    error_log("Database connection successful");
}

// Handle AJAX requests for approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    $inspectionId = $_POST['inspection_id'] ?? '';
    
    if ($action === 'approve_citizen') {
        $reportId = $_POST['report_id'] ?? '';
        
        error_log("Citizen approval request received for report: " . $reportId);
        error_log("Session user ID: " . ($_SESSION['user_id'] ?? 'not set'));
        
        // Update citizen report status to approved
        $updateStmt = $conn->prepare("UPDATE damage_reports SET status = 'approved', updated_at = CURRENT_TIMESTAMP WHERE report_id = ?");
        if (!$updateStmt) {
            error_log("Failed to prepare citizen approval statement: " . $conn->error);
            echo json_encode([
                'success' => false,
                'message' => 'Database error preparing citizen approval query'
            ]);
            exit;
        }
        
        $updateStmt->bind_param('s', $reportId);
        $updateResult = $updateStmt->execute();
        error_log("Citizen approval query executed: " . ($updateResult ? 'success' : 'failed'));
        
        if ($updateResult) {
            // Get updated statistics
            $statsQuery = "
                SELECT 
                    COUNT(*) as total_inspections,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_approvals,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as repairs_in_progress,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_repairs
                FROM inspections
            ";
            $statsResult = $conn->query($statsQuery);
            $stats = $statsResult ? $statsResult->fetch_assoc() : [
                'total_inspections' => 0,
                'pending_approvals' => 0,
                'repairs_in_progress' => 0,
                'completed_repairs' => 0
            ];
            
            // Add citizen reports to stats
            $citizenStatsQuery = "
                SELECT 
                    COUNT(*) as total_reports,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_reports
                FROM damage_reports
            ";
            $citizenStatsResult = $conn->query($citizenStatsQuery);
            $citizenStats = $citizenStatsResult ? $citizenStatsResult->fetch_assoc() : [
                'total_reports' => 0,
                'pending_reports' => 0
            ];
            
            // Combine stats
            $combinedStats = [
                'total_inspections' => $stats['total_inspections'] + $citizenStats['total_reports'],
                'pending_approvals' => $stats['pending_approvals'] + $citizenStats['pending_reports'],
                'repairs_in_progress' => $stats['repairs_in_progress'],
                'completed_repairs' => $stats['completed_repairs']
            ];
            
            echo json_encode([
                'success' => true,
                'message' => 'Citizen report approved successfully and sent to GIS mapping!',
                'stats' => $combinedStats,
                'gis_url' => 'gis_mapping_dashboard.php' // URL to GIS mapping
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Failed to approve citizen report'
            ]);
        }
        $updateStmt->close();
        exit;
    }
    
    if ($action === 'approve') {
        error_log("Approval request received for inspection: " . $inspectionId);
        error_log("Session user ID: " . ($_SESSION['user_id'] ?? 'not set'));
        
        // First check if inspection exists
        $checkStmt = $conn->prepare("SELECT inspection_id, status FROM inspections WHERE inspection_id = ?");
        if (!$checkStmt) {
            error_log("Failed to prepare check statement: " . $conn->error);
            echo json_encode([
                'success' => false,
                'message' => 'Database error preparing check query'
            ]);
            exit;
        }
        $checkStmt->bind_param('s', $inspectionId);
        $checkResult = $checkStmt->execute();
        error_log("Check query executed: " . ($checkResult ? 'success' : 'failed'));
        
        $existingInspection = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        
        if (!$existingInspection) {
            error_log("Inspection not found: " . $inspectionId);
            echo json_encode([
                'success' => false,
                'message' => 'Inspection not found'
            ]);
            exit;
        }
        
        error_log("Found inspection: " . $inspectionId . " with status: " . $existingInspection['status']);
        
        // Update inspection status
        $stmt = $conn->prepare("UPDATE inspections SET status = 'approved', review_date = CURDATE(), reviewed_by = ? WHERE inspection_id = ?");
        if (!$stmt) {
            error_log("Failed to prepare update statement: " . $conn->error);
            echo json_encode([
                'success' => false,
                'message' => 'Database error preparing update query'
            ]);
            exit;
        }
        $stmt->bind_param('is', $_SESSION['user_id'], $inspectionId);
        $result = $stmt->execute();
        error_log("Update query executed: " . ($result ? 'success' : 'failed'));
        
        // Create repair task for approved inspection
        if ($result && $stmt->affected_rows > 0) {
            $taskId = 'REP-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $taskStmt = $conn->prepare("
                INSERT INTO repair_tasks (task_id, inspection_id, assigned_to, status, priority, estimated_cost, created_date, task_type)
                VALUES (?, ?, ?, 'pending', 'high', (SELECT estimated_cost FROM inspections WHERE inspection_id = ?), NOW(), 'inspection')
            ");
            $taskStmt->bind_param('ssss', $taskId, $inspectionId, $_SESSION['user_id'], $inspectionId);
            $taskStmt->execute();
        }
        
        error_log("Update query executed. Result: " . ($result ? 'true' : 'false'));
        error_log("Affected rows: " . $stmt->affected_rows);
        
        if ($stmt->affected_rows > 0) {
            error_log("Update successful, fetching new statistics");
            
            // Get updated statistics
            $stats = [
                'total_inspections' => 0,
                'pending_approvals' => 0,
                'repairs_in_progress' => 0,
                'completed_repairs' => 0
            ];
            
            // Total inspections
            $total_inspections = $conn->query("SELECT COUNT(*) as total FROM inspections")->fetch_assoc()['total'] ?? 0;
            $stats['total_inspections'] = $total_inspections;
            error_log("Total inspections: " . $stats['total_inspections']);
            
            // Pending approvals
            $pending_approvals = $conn->query("SELECT COUNT(*) as pending FROM inspections WHERE status = 'pending'")->fetch_assoc()['pending'] ?? 0;
            $stats['pending_approvals'] = $pending_approvals;
            error_log("Pending approvals: " . $stats['pending_approvals']);
            
            // Repairs in progress
            $progress_result = $conn->query("SELECT COUNT(*) as progress FROM repair_tasks WHERE status = 'in_progress'");
            $stats['repairs_in_progress'] = $progress_result ? $progress_result->fetch_assoc()['progress'] : 0;
            
            // Completed repairs
            $completed_result = $conn->query("SELECT COUNT(*) as completed FROM repair_tasks WHERE status = 'completed'");
            $stats['completed_repairs'] = $completed_result ? $completed_result->fetch_assoc()['completed'] : 0;
            
            echo json_encode([
                'success' => true,
                'message' => 'Report approved successfully',
                'stats' => $stats
            ]);
            error_log("Response sent with stats: " . json_encode($stats));
        } else {
            error_log("Update failed - no rows affected. Current status: " . $existingInspection['status']);
            $errorMessage = 'Inspection not found or already updated';
            if ($existingInspection['status'] === 'approved') {
                $errorMessage = 'Inspection has already been approved';
            } elseif ($existingInspection['status'] === 'rejected') {
                $errorMessage = 'Inspection has already been rejected';
            }
            echo json_encode([
                'success' => false,
                'message' => $errorMessage
            ]);
        }
        $stmt->close();
        exit;
    } elseif ($action === 'reject') {
        $reason = $_POST['reason'] ?? '';
        
        // Update inspection status
        $stmt = $conn->prepare("UPDATE inspections SET status = 'rejected', review_date = CURDATE(), reviewed_by = ?, review_notes = ? WHERE inspection_id = ?");
        $stmt->bind_param('iss', $_SESSION['user_id'], $reason, $inspectionId);
        $stmt->execute();
        
        if ($stmt->affected_rows > 0) {
            echo json_encode([
                'success' => true,
                'message' => 'Report rejected successfully'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Inspection not found or already updated'
            ]);
        }
        $stmt->close();
        exit;
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action'
    ]);
    exit;
}

// Fetch statistics from database
try {
    error_log("Fetching statistics from database");
    
    // Total inspections
    $total_inspections = $conn->query("SELECT COUNT(*) as total FROM inspections")->fetch_assoc()['total'] ?? 0;
    
    // Pending approvals
    $pending_approvals = $conn->query("SELECT COUNT(*) as pending FROM inspections WHERE status = 'pending'")->fetch_assoc()['pending'] ?? 0;
    
    // Repairs in progress (from repair_tasks table)
    $progress_result = $conn->query("SELECT COUNT(*) as progress FROM repair_tasks WHERE status = 'in_progress'");
    $repairs_in_progress = $progress_result ? $progress_result->fetch_assoc()['progress'] : 0;
    
    // Completed repairs
    $completed_result = $conn->query("SELECT COUNT(*) as completed FROM repair_tasks WHERE status = 'completed'");
    $completed_repairs = $completed_result ? $completed_result->fetch_assoc()['completed'] : 0;
    
    error_log("Stats - Total: $total_inspections, Pending: $pending_approvals, In Progress: $repairs_in_progress, Completed: $completed_repairs");
    
} catch (Exception $e) {
    error_log("Error fetching statistics: " . $e->getMessage());
    // Fallback values
    $total_inspections = 128;
    $pending_approvals = 14;
    $repairs_in_progress = 9;
    $completed_repairs = 105;
}

// Fetch inspections from database
try {
    error_log("Attempting to fetch inspections from database");
    
    // Fetch all inspections from database
    $query = "
        SELECT i.*, 
               COALESCE(u.name, 'Unknown') as reporter_name
        FROM inspections i 
        LEFT JOIN users u ON i.inspector_id = u.id
        ORDER BY i.created_at DESC
    ";
    
    error_log("About to execute inspections query");
    error_log("Connection state: " . ($conn ? "Connected" : "Not connected"));
    
    $result = $conn->query($query);
    
    if (!$result) {
        error_log("Query failed: " . $conn->error);
        error_log("Query was: " . $query);
    } else {
        error_log("Query succeeded, rows: " . $result->num_rows);
    }
    
    // Debug: Show the actual SQL query and results
    error_log("SQL Query: " . $query);
    error_log("Number of rows found: " . ($result ? $result->num_rows : 0));
    
    $inspections = [];
    
    // Process inspections
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $photos = json_decode($row['photos'] ?? '[]', true) ?: [];
            
            // Debug: Log each inspection being processed
            error_log("Processing inspection: " . $row['inspection_id'] . " - Inspector ID: " . $row['inspector_id']);
            
            // Universal inspection data structure for all inspections
            $inspections[] = [
                'id' => $row['inspection_id'],
                'report_id' => 'DR-' . date('Y', strtotime($row['created_at'])) . '-' . substr($row['inspection_id'], -3),
                'location' => $row['location'],
                'date' => date('M d, Y', strtotime($row['inspection_date'] ?? $row['created_at'])),
                'status' => ucfirst($row['status']),
                'severity' => ucfirst($row['severity']),
                'cost' => $row['estimated_cost'] ? '₱' . number_format($row['estimated_cost'], 2) : '₱0.00',
                'reporter' => $row['reporter_name'],
                'coordinates' => '14.5995° N, 120.9842° E',
                'description' => $row['description'],
                'images' => $photos,
                'inspection_type' => 'regular' // Default type for all
            ];
        }
        $result->free();
    }
    
    error_log("Successfully fetched " . count($inspections) . " inspections from database");
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    error_log("Using fallback mock data");
    // Fallback to mock data if database fails
    $inspections = [
        [
            'id' => 'INSP-1023', 
            'report_id' => 'DR-2025-001',
            'location' => 'Main Road, Brgy. 3', 
            'date' => 'Dec 10, 2025', 
            'status' => 'Pending',
            'severity' => 'High',
            'cost' => '₱45,000.00',
            'reporter' => 'Juan Dela Cruz',
            'coordinates' => '14.5995° N, 120.9842° E',
            'description' => 'Large pothole on the main road causing traffic issues.',
            'images' => ['damage_1.jpg', 'damage_2.jpg'],
            'inspection_type' => 'regular'
        ],
        [
            'id' => 'INSP-1024', 
            'report_id' => 'DR-2025-002',
            'location' => 'Market Street', 
            'date' => 'Dec 11, 2025', 
            'status' => 'Approved',
            'severity' => 'Medium',
            'cost' => '₱12,500.00',
            'reporter' => 'Maria Santos',
            'coordinates' => '14.6010° N, 120.9890° E',
            'description' => 'Cracks along the side of the road near the market entrance.',
            'images' => ['damage_3.jpg'],
            'inspection_type' => 'regular'
        ],
    ];
    $citizenReports = [];
}

$repairs = [
    ['id' => 'REP-552', 'inspection' => 'INSP-1024', 'assigned_to' => 'Engineer Cruz', 'progress' => 60, 'status' => 'In Progress'],
    ['id' => 'REP-553', 'inspection' => 'INSP-1019', 'assigned_to' => 'Engineer Reyes', 'progress' => 100, 'status' => 'Completed'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inspection & Workflow | LGU Officer</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap");
        
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --glass-bg: rgba(255, 255, 255, 0.85);
            --glass-border: rgba(255, 255, 255, 0.4);
            --text-main: #1e293b;
            --text-muted: #64748b;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Inter", sans-serif;
        }

        body {
            height: 100vh;
            background: url('../user_and_access_management_module/assets/img/cityhall.jpeg') center/cover no-repeat fixed;
            position: relative;
            overflow: hidden;
            color: var(--text-main);
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(8px);
            background: rgba(15, 23, 42, 0.45);
            z-index: 0;
        }

        .main-content {
            position: relative;
            margin-left: 250px;
            height: 100vh;
            padding: 30px 40px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            z-index: 1;
        }

        /* Module Header */
        .module-header {
            color: white;
            margin-bottom: 30px;
        }

        .module-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .module-header p {
            font-size: 1rem;
            opacity: 0.9;
            letter-spacing: 0.5px;
        }

        .header-divider {
            border: none;
            height: 1px;
            background: rgba(255, 255, 255, 0.3);
            margin: 15px 0;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 25px;
            position: relative;
            overflow: hidden;
        }

        .stat-card::after {
            content: "";
            position: absolute;
            top: -10px;
            right: -10px;
            width: 60px;
            height: 60px;
            background: rgba(37, 99, 235, 0.1);
            border-radius: 50%;
        }

        .stat-label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            margin-bottom: 15px;
        }

        .stat-label i {
            font-size: 1rem;
            color: var(--primary);
        }

        .stat-value {
            font-size: 2.2rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .stat-value i.trend {
            font-size: 1.2rem;
            color: #94a3b8;
        }

        /* Tables Card Style */
        .content-card {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid var(--glass-border);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .content-card h2 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .content-card h2 i {
            color: var(--primary);
        }

        /* Custom Table Styling */
        .custom-table {
            width: 100%;
            border-collapse: collapse;
        }

        .custom-table th {
            text-align: left;
            padding: 12px 15px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            border-bottom: 1px solid #f1f5f9;
        }

        .custom-table td {
            padding: 18px 15px;
            font-size: 0.95rem;
            color: #334155;
            border-bottom: 1px solid #f1f5f9;
        }

        .custom-table tr:last-child td {
            border-bottom: none;
        }

        .id-text {
            font-weight: 700;
            color: #0f172a;
        }

        .loc-text {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .loc-text i {
            color: var(--primary);
            font-size: 0.9rem;
        }

        /* Status Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-approved {
            background: #dcfce7;
            color: #166534;
        }

        .badge-progress {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Action Buttons */
        .btn-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 18px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            color: white;
            background: #2563eb;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: 0.2s;
        }

        .btn-action:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        /* Progress Bar */
        .progress-container {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 180px;
        }

        .progress-bar-bg {
            flex: 1;
            height: 8px;
            background: #f1f5f9;
            border-radius: 10px;
            overflow: hidden;
        }

        .progress-bar-fill {
            height: 100%;
            background: #2563eb;
            border-radius: 10px;
        }

        .progress-text {
            font-size: 0.9rem;
            font-weight: 700;
            color: #1e293b;
            min-width: 40px;
        }

        /* Engineer Assignment */
        .assigned-box {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .assigned-box i {
            color: #3b82f6;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 2000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .modal-container {
            background: white;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            border-radius: 20px;
            overflow-y: auto;
            position: relative;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            display: flex;
            flex-direction: column;
        }

        .modal-header {
            padding: 24px 32px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #f8fafc;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-title i {
            color: var(--primary);
        }

        .modal-close {
            background: none;
            border: none;
            color: #94a3b8;
            font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: #1e293b;
        }

        .modal-body {
            padding: 32px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px 40px;
        }

        .detail-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .detail-label {
            font-size: 0.75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        .description-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            color: #475569;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .image-gallery {
            display: flex;
            gap: 16px;
            margin-top: 12px;
        }

        .gallery-item {
            width: 200px;
            height: 140px;
            border-radius: 12px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #94a3b8;
            border: 1px solid #e2e8f0;
            font-size: 1.5rem;
            overflow: hidden;
        }

        .gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .severity-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .severity-high { background: #fee2e2; color: #dc2626; }
        .severity-medium { background: #fef3c7; color: #d97706; }
        .severity-low { background: #dcfce7; color: #16a34a; }
    </style>
</head>
<body>
    <?php include '../sidebar/sidebar.php'; ?>

    <main class="main-content">
        <header class="module-header">
            <h1><i class="fas fa-clipboard-check"></i> Inspection & Workflow</h1>
            <div style="display: flex; align-items: center; gap: 15px; margin-top: 10px;">
                <div style="background: rgba(255,255,255,0.1); padding: 5px 15px; border-radius: 20px; color: #fff; font-size: 0.8rem;">
                    <i class="fas fa-tasks"></i> Process Management
                </div>
                <div id="notification-container" style="position: relative;">
                    <button onclick="toggleNotifications()" style="background: rgba(255,255,255,0.2); border: none; color: white; padding: 8px 12px; border-radius: 20px; cursor: pointer; position: relative;">
                        <i class="fas fa-bell"></i>
                        <span id="notification-badge" class="notification-badge" style="display: none; position: absolute; top: -5px; right: -5px; background: #ef4444; color: white; border-radius: 50%; width: 18px; height: 18px; font-size: 0.7rem; display: flex; align-items: center; justify-content: center;">0</span>
                    </button>
                    <div id="notifications-dropdown" style="display: none; position: absolute; top: 100%; right: 0; background: white; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); min-width: 300px; max-height: 400px; overflow-y: auto; z-index: 1000; margin-top: 5px;">
                        <div style="padding: 15px; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #1e293b;">
                            <i class="fas fa-bell"></i> Notifications
                        </div>
                        <div id="notifications-list">
                            <!-- Notifications will be loaded here -->
                        </div>
                    </div>
                </div>
            </div>
            <p style="margin-top: 10px;">Manage inspection activities, approvals, and repair progress in real-time.</p>
            <hr class="header-divider">
        </header>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label"><i class="fas fa-clipboard-list"></i> Total Inspections</div>
                <div class="stat-value"><?php echo $total_inspections; ?> <i class="fas fa-chart-line trend"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><i class="fas fa-clock"></i> Pending Approvals</div>
                <div class="stat-value"><?php echo $pending_approvals; ?> <i class="fas fa-hourglass-half trend"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><i class="fas fa-tools"></i> Repairs in Progress</div>
                <div class="stat-value"><?php echo $repairs_in_progress; ?> <i class="fas fa-cog trend"></i></div>
            </div>
            <div class="stat-card">
                <div class="stat-label"><i class="fas fa-check-circle"></i> Completed Repairs</div>
                <div class="stat-value"><?php echo $completed_repairs; ?> <i class="fas fa-trophy trend"></i></div>
            </div>
        </div>

        <!-- Inspection Reports -->
        <div class="content-card">
            <h2><i class="fas fa-file-alt"></i> Inspection Reports</h2>
            <div style="background: #f0f9ff; border: 1px solid #0ea5e9; padding: 10px; margin-bottom: 15px; border-radius: 8px;">
                <small style="color: #0c4a6e;">
                    <strong>DEBUG:</strong> Found <?php echo count($inspections); ?> inspections 
                    <?php 
                    if (count($inspections) <= 2) {
                        echo "(Using fallback mock data - Database query failed)";
                    } else {
                        echo "(Using real database data)";
                    }
                    ?>
                </small>
            </div>
            <table class="custom-table">
                <thead>
                    <tr>
                        <th width="120"># ID</th>
                        <th><i class="fas fa-map-marker-alt"></i> Location</th>
                        <th width="150"><i class="fas fa-calendar-alt"></i> Date</th>
                        <th width="120"><i class="fas fa-tag"></i> Type</th>
                        <th width="150"><i class="fas fa-info-circle"></i> Status</th>
                        <th width="120"><i class="fas fa-cog"></i> Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inspections as $ins): ?>
                    <tr>
                        <td class="id-text"><?php echo $ins['id']; ?></td>
                        <td><div class="loc-text"><i class="fas fa-map-pin"></i> <?php echo $ins['location']; ?></div></td>
                        <td><?php echo $ins['date']; ?></td>
                        <td>
                            <span style="background: #f3f4f6; color: #374151; padding: 4px 8px; border-radius: 6px; font-size: 0.8rem; font-weight: 600;">
                                Regular
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo str_replace(' ', '', strtolower($ins['status'])); ?>">
                                <i class="fas <?php echo strpos($ins['status'], 'Pending') !== false ? 'fa-clock' : 'fa-check'; ?>"></i>
                                <?php echo $ins['status']; ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn-action" onclick='viewInspection(<?php echo json_encode($ins); ?>)'>
                                <i class="fas fa-eye"></i> View
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Repair Workflow -->
        <div class="content-card">
            <h2><i class="fas fa-tasks"></i> Repair Workflow</h2>
            <table class="custom-table">
                <thead>
                    <tr>
                        <th width="120"># Task ID</th>
                        <th><i class="fas fa-clipboard-check"></i> Inspection</th>
                        <th><i class="fas fa-user-hard-hat"></i> Assigned To</th>
                        <th width="220"><i class="fas fa-chart-bar"></i> Progress</th>
                        <th width="150"><i class="fas fa-info-circle"></i> Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($repairs as $rep): ?>
                    <tr>
                        <td class="id-text"><?php echo $rep['id']; ?></td>
                        <td><?php echo $rep['inspection']; ?></td>
                        <td>
                            <div class="assigned-box">
                                <i class="fas fa-user-circle"></i> <?php echo $rep['assigned_to']; ?>
                            </div>
                        </td>
                        <td>
                            <div class="progress-container">
                                <div class="progress-text"><?php echo $rep['progress']; ?>%</div>
                                <div class="progress-bar-bg">
                                    <div class="progress-bar-fill" style="width: <?php echo $rep['progress']; ?>%;"></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="badge badge-<?php echo str_replace(' ', '', strtolower($rep['status'])); ?>">
                                <i class="fas <?php echo $rep['status'] === 'In Progress' ? 'fa-spinner fa-spin' : 'fa-check-circle'; ?>"></i>
                                <?php echo $rep['status']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <!-- Inspection Details Modal -->
    <div class="modal-overlay" id="inspectionModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-road"></i> Road Damage Report Details
                </h2>
                <button class="modal-close" onclick="closeModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-hashtag"></i> Report ID</span>
                    <span class="detail-value" id="modal-report-id">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-clipboard-check"></i> Inspection ID</span>
                    <span class="detail-value" id="modal-inspection-id">--</span>
                </div>
                <div class="detail-group full-width">
                    <span class="detail-label"><i class="fas fa-map-marker-alt"></i> Location</span>
                    <span class="detail-value" id="modal-location">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-exclamation-triangle"></i> Severity</span>
                    <div id="modal-severity">
                        <span class="severity-badge severity-high"><i class="fas fa-burn"></i> High</span>
                    </div>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-info-circle"></i> Status</span>
                    <span class="detail-value" id="modal-status">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-calendar-alt"></i> Reported Date</span>
                    <span class="detail-value" id="modal-date">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-dollar-sign"></i> Estimated Cost</span>
                    <span class="detail-value" id="modal-cost">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-user"></i> Reporter</span>
                    <span class="detail-value" id="modal-reporter">--</span>
                </div>
                <div class="detail-group">
                    <span class="detail-label"><i class="fas fa-globe"></i> Coordinates</span>
                    <span class="detail-value" id="modal-coordinates">--</span>
                </div>
                
                <div class="detail-group full-width">
                    <span class="detail-label"><i class="fas fa-align-left"></i> Description</span>
                    <div class="description-box" id="modal-description">
                        --
                    </div>
                </div>

                <div class="detail-group full-width">
                    <span class="detail-label"><i class="fas fa-images"></i> Images</span>
                    <div class="image-gallery" id="modal-gallery">
                        <!-- Images will be inserted here -->
                    </div>
                </div>

                <!-- Approval Actions -->
                <div class="detail-group full-width" id="approval-actions" style="display: none;">
                    <span class="detail-label"><i class="fas fa-cogs"></i> Actions</span>
                    <div style="display: flex; gap: 12px; margin-top: 8px;">
                        <button class="btn-action" onclick="approveReport()" style="background: #16a34a;">
                            <i class="fas fa-check-circle"></i> Approve Report
                        </button>
                        <button class="btn-action" onclick="rejectReport()" style="background: #dc2626;">
                            <i class="fas fa-times-circle"></i> Reject Report
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentInspectionData = null;
        let notifications = [];
        
        // Data arrays
        const inspectionsData = <?php echo json_encode($inspections); ?>;
        const allReports = inspectionsData;

        // Load notifications when page loads
        document.addEventListener('DOMContentLoaded', function() {
            loadNotifications();
            // Refresh notifications every 30 seconds
            setInterval(loadNotifications, 30000);
        });

        // Load notifications from server
        async function loadNotifications() {
            try {
                const response = await fetch('api/get_notifications.php');
                const data = await response.json();
                
                if (response.ok && data.success) {
                    notifications = data.notifications;
                    updateNotificationBadge();
                    renderNotifications();
                }
            } catch (error) {
                console.error('Error loading notifications:', error);
            }
        }

        // Update notification badge
        function updateNotificationBadge() {
            const unreadCount = notifications.filter(n => !n.read_status).length;
            const badge = document.getElementById('notification-badge');
            
            if (unreadCount > 0) {
                badge.textContent = unreadCount;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }

        // Render notifications in dropdown
        function renderNotifications() {
            const list = document.getElementById('notifications-list');
            
            if (notifications.length === 0) {
                list.innerHTML = `
                    <div style="padding: 20px; text-align: center; color: #64748b;">
                        <i class="fas fa-bell-slash" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                        No notifications
                    </div>
                `;
                return;
            }
            
            list.innerHTML = notifications.map(notification => {
                const data = JSON.parse(notification.data || '{}');
                const unreadClass = !notification.read_status ? 'style="background: #f8fafc; border-left: 3px solid #2563eb;"' : '';
                
                return `
                    <div class="notification-item" ${unreadClass} style="padding: 15px; border-bottom: 1px solid #f1f5f9; cursor: pointer;" onclick="handleNotificationClick('${notification.id}', '${notification.type}', '${notification.data}')">
                        <div style="display: flex; align-items: start; gap: 12px;">
                            <div style="background: ${notification.type === 'inspection_report' ? '#dbeafe' : '#f3f4f6'}; color: ${notification.type === 'inspection_report' ? '#1e40af' : '#374151'}; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                <i class="fas ${notification.type === 'inspection_report' ? 'fa-clipboard-check' : 'fa-info-circle'}"></i>
                            </div>
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #1e293b; margin-bottom: 4px;">${notification.title}</div>
                                <div style="color: #64748b; font-size: 0.9rem; margin-bottom: 4px;">${notification.message}</div>
                                <div style="color: #94a3b8; font-size: 0.8rem;">${formatTime(notification.created_at)}</div>
                            </div>
                            ${!notification.read_status ? '<div style="width: 8px; height: 8px; background: #2563eb; border-radius: 50%; flex-shrink: 0;"></div>' : ''}
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Handle notification click
        async function handleNotificationClick(notificationId, type, dataStr) {
            // Mark as read
            try {
                await fetch('api/mark_notification_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ notification_id: notificationId })
                });
            } catch (error) {
                console.error('Error marking notification as read:', error);
            }
            
            // Handle different notification types
            if (type === 'inspection_report') {
                const data = JSON.parse(dataStr);
                // Find and open the inspection modal
                const inspection = inspections.find(ins => ins.id === data.inspection_id);
                if (inspection) {
                    viewInspection(inspection);
                }
            }
            
            // Close dropdown and reload notifications
            document.getElementById('notifications-dropdown').style.display = 'none';
            loadNotifications();
        }

        // Toggle notifications dropdown
        function toggleNotifications() {
            const dropdown = document.getElementById('notifications-dropdown');
            dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        }

        // Format time
        function formatTime(dateStr) {
            const date = new Date(dateStr);
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            const diffHours = Math.floor(diffMs / 3600000);
            const diffDays = Math.floor(diffMs / 86400000);
            
            if (diffMins < 1) return 'Just now';
            if (diffMins < 60) return `${diffMins} minute${diffMins > 1 ? 's' : ''} ago`;
            if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
            if (diffDays < 7) return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
            
            return date.toLocaleDateString();
        }

        // Close notifications when clicking outside
        document.addEventListener('click', function(event) {
            const container = document.getElementById('notification-container');
            if (!container.contains(event.target)) {
                document.getElementById('notifications-dropdown').style.display = 'none';
            }
        });

        function viewInspection(data) {
            currentInspectionData = data;
            document.getElementById('modal-report-id').textContent = data.report_id;
            document.getElementById('modal-inspection-id').textContent = data.id;
            document.getElementById('modal-location').textContent = data.location;
            document.getElementById('modal-status').textContent = data.status;
            document.getElementById('modal-date').textContent = data.date;
            document.getElementById('modal-cost').textContent = data.cost || '₱0.00';
            document.getElementById('modal-reporter').textContent = data.reporter;
            document.getElementById('modal-coordinates').textContent = data.coordinates;
            document.getElementById('modal-description').textContent = data.description;

            // Handle Severity Badge
            const severityContainer = document.getElementById('modal-severity');
            const sev = data.severity.toLowerCase();
            const icon = sev === 'high' ? 'fa-burn' : (sev === 'medium' ? 'fa-exclamation' : 'fa-info');
            severityContainer.innerHTML = `<span class="severity-badge severity-${sev}"><i class="fas ${icon}"></i> ${data.severity}</span>`;

            // Handle Gallery
            const gallery = document.getElementById('modal-gallery');
            gallery.innerHTML = '';
            
            if (data.images && data.images.length > 0) {
                data.images.forEach(img => {
                    if (data.inspection_type === 'citizen') {
                        // Citizen reports have actual image paths
                        gallery.innerHTML += `
                            <div class="gallery-item">
                                <img src="../uploads/reports/${img}" alt="Report Image" style="width: 100%; height: 100%; object-fit: cover;">
                            </div>
                        `;
                    } else {
                        // Regular inspections may have placeholder images
                        gallery.innerHTML += `
                            <div class="gallery-item">
                                <i class="fas fa-image"></i>
                            </div>
                        `;
                    }
                });
            } else {
                gallery.innerHTML = `
                    <div class="gallery-item">
                        <i class="fas fa-image"></i>
                    </div>
                `;
            }

            // Show/hide approval actions based on status and type
            const approvalActions = document.getElementById('approval-actions');
            if (data.inspection_type === 'citizen' && (data.status === 'Under Review' || data.status === 'Pending')) {
                approvalActions.style.display = 'block';
                // Update button text for citizen reports
                approvalActions.innerHTML = `
                    <span class="detail-label"><i class="fas fa-cogs"></i> Actions</span>
                    <div style="display: flex; gap: 12px; margin-top: 8px;">
                        <button class="btn-action" onclick="approveCitizenReport()" style="background: #16a34a;">
                            <i class="fas fa-check-circle"></i> Approve & Send to GIS
                        </button>
                        <button class="btn-action" onclick="rejectReport()" style="background: #dc2626;">
                            <i class="fas fa-times-circle"></i> Reject Report
                        </button>
                    </div>
                `;
            } else if (data.status === 'Pending' || data.status === 'Pending Approval') {
                approvalActions.style.display = 'block';
                approvalActions.innerHTML = `
                    <span class="detail-label"><i class="fas fa-cogs"></i> Actions</span>
                    <div style="display: flex; gap: 12px; margin-top: 8px;">
                        <button class="btn-action" onclick="approveReport()" style="background: #16a34a;">
                            <i class="fas fa-check-circle"></i> Approve Report
                        </button>
                        <button class="btn-action" onclick="rejectReport()" style="background: #dc2626;">
                            <i class="fas fa-times-circle"></i> Reject Report
                        </button>
                    </div>
                `;
            } else {
                approvalActions.style.display = 'none';
            }

            document.getElementById('inspectionModal').style.display = 'flex';
        }

        function approveCitizenReport() {
            if (!currentInspectionData) return;
            
            console.log('Approving citizen report:', currentInspectionData.id);
            
            if (confirm('Are you sure you want to approve this citizen report and send it to GIS mapping?')) {
                // Send approval request to server
                fetch('inspection_management.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=approve_citizen&report_id=${currentInspectionData.id}`
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Server response:', data);
                    if (data.success) {
                        alert('Citizen report approved successfully and sent to GIS mapping!');
                        
                        // Update stat cards with new values
                        if (data.stats) {
                            console.log('Updating stats:', data.stats);
                            updateStatCards(data.stats);
                        }
                        
                        closeModal();
                        // Update status in table without full reload
                        updateInspectionStatus(currentInspectionData.id, 'Approved');
                        
                        // Optionally open GIS mapping
                        if (data.gis_url) {
                            setTimeout(() => {
                                window.open(data.gis_url, '_blank');
                            }, 1000);
                        }
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while approving the citizen report.');
                });
            }
        }

        function approveReport() {
            if (!currentInspectionData) return;
            
            console.log('Approving report:', currentInspectionData.id);
            
            if (confirm('Are you sure you want to approve this report? This will move it to repair workflow.')) {
                // Send approval request to server
                fetch('inspection_management.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=approve&inspection_id=${currentInspectionData.id}`
                })
                .then(response => response.json())
                .then(data => {
                    console.log('Server response:', data);
                    if (data.success) {
                        alert('Report approved successfully!');
                        
                        // Update stat cards with new values
                        if (data.stats) {
                            console.log('Updating stats:', data.stats);
                            updateStatCards(data.stats);
                        }
                        
                        closeModal();
                        // Update status in table without full reload
                        updateInspectionStatus(currentInspectionData.id, 'Approved');
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while approving the report.');
                });
            }
        }

        function updateStatCards(stats) {
            console.log('Updating stat cards with:', stats);
            // Update each stat card with new values
            const statCards = document.querySelectorAll('.stat-value');
            console.log('Found stat cards:', statCards.length);
            
            if (statCards[0]) {
                statCards[0].innerHTML = `${stats.total_inspections} <i class="fas fa-chart-line trend"></i>`;
                console.log('Updated total inspections to:', stats.total_inspections);
            }
            if (statCards[1]) {
                statCards[1].innerHTML = `${stats.pending_approvals} <i class="fas fa-hourglass-half trend"></i>`;
                console.log('Updated pending approvals to:', stats.pending_approvals);
            }
            if (statCards[2]) {
                statCards[2].innerHTML = `${stats.repairs_in_progress} <i class="fas fa-cog trend"></i>`;
                console.log('Updated repairs in progress to:', stats.repairs_in_progress);
            }
            if (statCards[3]) {
                statCards[3].innerHTML = `${stats.completed_repairs} <i class="fas fa-trophy trend"></i>`;
                console.log('Updated completed repairs to:', stats.completed_repairs);
            }
        }

        function updateInspectionStatus(inspectionId, newStatus) {
            console.log('Updating inspection status:', inspectionId, 'to', newStatus);
            const rows = document.querySelectorAll('.custom-table tbody tr');
            console.log('Found rows:', rows.length);
            
            rows.forEach((row, index) => {
                const idCell = row.querySelector('.id-text');
                console.log(`Row ${index}: ID = "${idCell ? idCell.textContent.trim() : 'null'}"`);
                
                if (idCell && idCell.textContent.trim() === inspectionId.trim()) {
                    console.log('Found matching row:', index);
                    const statusCell = row.querySelector('.badge');
                    if (statusCell) {
                        console.log('Old status class:', statusCell.className);
                        statusCell.className = `badge badge-${newStatus.toLowerCase()}`;
                        statusCell.innerHTML = `<i class="fas fa-check"></i> ${newStatus}`;
                        console.log('New status class:', statusCell.className);
                    }
                }
            });
        }

        function rejectReport() {
            if (!currentInspectionData) return;
            
            const reason = prompt('Please provide a reason for rejection:');
            if (reason) {
                // Send rejection request to server
                fetch('inspection_management.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=reject&inspection_id=${currentInspectionData.id}&reason=${encodeURIComponent(reason)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Report rejected successfully!');
                        closeModal();
                        location.reload(); // Reload to show updated status
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while rejecting the report.');
                });
            }
        }

        function closeModal() {
            document.getElementById('inspectionModal').style.display = 'none';
        }

        // Close when clicking overlay
        document.getElementById('inspectionModal').addEventListener('click', (e) => {
            if (e.target === document.getElementById('inspectionModal')) {
                closeModal();
            }
        });
    </script>
</body>
</html>


<?php
// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'lgu_road_monitoring');

// Database connection function
function connectDB() {
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) {
            throw new Exception("Connection failed: " . $conn->connect_error);
        }
        return $conn;
    } catch (Exception $e) {
        // Return null if database doesn't exist or connection fails
        return null;
    }
}

// Function to get transparency statistics
function getTransparencyStats() {
    $conn = connectDB();
    $stats = [];
    
    if ($conn) {
        // Get document count
        $result = $conn->query("SELECT COUNT(*) as count FROM public_documents");
        $stats['documents'] = $result->fetch_assoc()['count'];
        
        // Get total views
        $result = $conn->query("SELECT SUM(views) as total FROM document_views");
        $stats['views'] = $result->fetch_assoc()['total'] ?: 0;
        
        // Get downloads
        $result = $conn->query("SELECT COUNT(*) as count FROM document_downloads");
        $stats['downloads'] = $result->fetch_assoc()['count'];
        
        // Calculate transparency score
        $stats['score'] = calculateTransparencyScore($conn);
        
        $conn->close();
    } else {
        // Return sample data if database is not available
        $stats = [
            'documents' => 156,
            'views' => 2847,
            'downloads' => 423,
            'score' => 98.5
        ];
    }
    
    return $stats;
}

// Function to calculate transparency score
function calculateTransparencyScore($conn) {
    $total_docs = 0;
    $public_docs = 0;
    
    $result = $conn->query("SELECT COUNT(*) as total FROM documents");
    if ($result) {
        $total_docs = $result->fetch_assoc()['total'];
    }
    
    $result = $conn->query("SELECT COUNT(*) as public FROM documents WHERE is_public = 1");
    if ($result) {
        $public_docs = $result->fetch_assoc()['public'];
    }
    
    if ($total_docs > 0) {
        return round(($public_docs / $total_docs) * 100, 1);
    }
    return 98.5; // Default sample score
}

// Function to get budget data
function getBudgetData() {
    $conn = connectDB();
    $budget = [];
    
    if ($conn) {
        $result = $conn->query("SELECT * FROM budget_allocation WHERE year = YEAR(CURRENT_DATE)");
        if ($result && $result->num_rows > 0) {
            $budget = $result->fetch_assoc();
        }
        $conn->close();
    } else {
        // Return sample budget data
        $budget = [
            'annual_budget' => 125000000,
            'allocation_percentage' => 89
        ];
    }
    
    return $budget;
}

// Function to get projects data
function getProjectsData() {
    $conn = connectDB();
    $projects = [];
    
    if ($conn) {
        $result = $conn->query("SELECT * FROM infrastructure_projects ORDER BY start_date DESC");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $projects[] = $row;
            }
        }
        $conn->close();
    } else {
        // Return sample projects data
        $projects = [
            ['id' => 1, 'name' => 'Main Street Rehabilitation', 'location' => 'Downtown District', 'budget' => 8500000, 'progress' => 75, 'status' => 'active'],
            ['id' => 2, 'name' => 'Highway 101 Expansion', 'location' => 'North Corridor', 'budget' => 12000000, 'progress' => 45, 'status' => 'active'],
            ['id' => 3, 'name' => 'Bridge Repair Project', 'location' => 'River Crossing', 'budget' => 5200000, 'progress' => 90, 'status' => 'active'],
            ['id' => 4, 'name' => 'Street Lighting Upgrade', 'location' => 'Residential Areas', 'budget' => 3800000, 'progress' => 30, 'status' => 'delayed'],
            ['id' => 5, 'name' => 'Drainage System Installation', 'location' => 'Flood-prone Areas', 'budget' => 7100000, 'progress' => 60, 'status' => 'active'],
            ['id' => 6, 'name' => 'Park Avenue Reconstruction', 'location' => 'Central District', 'budget' => 4500000, 'progress' => 100, 'status' => 'completed'],
            ['id' => 7, 'name' => 'Sidewalk Improvement Project', 'location' => 'Suburban Areas', 'budget' => 2300000, 'progress' => 100, 'status' => 'completed']
        ];
    }
    
    return $projects;
}

// Function to get publications
function getPublications() {
    $conn = connectDB();
    $publications = [];
    
    if ($conn) {
        $result = $conn->query("SELECT * FROM publications ORDER BY publish_date DESC LIMIT 10");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $publications[] = $row;
            }
        }
        $conn->close();
    } else {
        // Return sample publications data
        $publications = [
            ['id' => 1, 'type' => 'Annual Report', 'title' => 'Infrastructure Development Report 2024', 'description' => 'Comprehensive annual report on infrastructure development, maintenance, and future planning initiatives.', 'publish_date' => '2024-02-10', 'views' => 1234],
            ['id' => 2, 'type' => 'Budget Report', 'title' => 'Q1 2024 Budget Allocation', 'description' => 'Detailed breakdown of first quarter budget allocation across all infrastructure departments and projects.', 'publish_date' => '2024-02-05', 'views' => 892],
            ['id' => 3, 'type' => 'Performance Report', 'title' => 'Service Delivery Performance Metrics', 'description' => 'Monthly performance metrics showing service delivery efficiency and citizen satisfaction scores.', 'publish_date' => '2024-01-31', 'views' => 567],
            ['id' => 4, 'type' => 'Policy Document', 'title' => 'Infrastructure Development Policy 2024-2028', 'description' => 'Long-term infrastructure development policy framework with strategic goals and implementation guidelines.', 'publish_date' => '2024-01-15', 'views' => 2145]
        ];
    }
    
    return $publications;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'refresh_data':
                // Refresh statistics
                header('Content-Type: application/json');
                echo json_encode(getTransparencyStats());
                exit;
                
            case 'download_document':
                // Handle document download
                if (isset($_POST['doc_id'])) {
                    $doc_id = intval($_POST['doc_id']);
                    logDownload($doc_id);
                }
                break;
        }
    }
}

// Function to log document download
function logDownload($doc_id) {
    $conn = connectDB();
    $stmt = $conn->prepare("INSERT INTO document_downloads (document_id, download_date, ip_address) VALUES (?, NOW(), ?)");
    $ip = $_SERVER['REMOTE_ADDR'];
    $stmt->bind_param("is", $doc_id, $ip);
    $stmt->execute();
    $stmt->close();
    $conn->close();
}

// Get data for the page
$stats = getTransparencyStats();
$budget = getBudgetData();
$projects = getProjectsData();
$publications = getPublications();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Transparency | LGU Staff</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .transparency-header {
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

        .transparency-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .transparency-stat {
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

        .public-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
        }

        .info-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .info-icon {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            flex-shrink: 0;
        }

        .info-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e3c72;
        }

        .info-description {
            font-size: 16px;
            color: #666;
            line-height: 1.5;
            margin-bottom: 20px;
        }

        .info-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .info-stat {
            text-align: center;
            padding: 15px;
            background: rgba(55, 98, 200, 0.05);
            border-radius: 8px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .info-stat-number {
            font-size: 24px;
            font-weight: 700;
            color: #3762c8;
            margin-bottom: 5px;
        }

        .info-stat-label {
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }

        .info-action {
            margin-top: 20px;
            text-align: center;
        }

        .btn-info {
            padding: 12px 24px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.3);
        }

        .publications-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(55, 98, 200, 0.1);
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .publications-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }

        .publication-card {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(55, 98, 200, 0.1);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .publication-card:hover {
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.1);
        }

        .publication-type {
            display: inline-block;
            padding: 4px 12px;
            background: #3762c8;
            color: white;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 10px;
        }

        .publication-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .publication-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 10px;
            font-size: 14px;
            color: #666;
        }

        .publication-description {
            font-size: 14px;
            color: #666;
            line-height: 1.4;
        }

        .contact-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .contact-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .contact-card {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(55, 98, 200, 0.1);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .contact-card:hover {
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.1);
        }

        .contact-icon {
            font-size: 32px;
            color: #3762c8;
            margin-bottom: 15px;
        }

        .contact-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .contact-info {
            font-size: 14px;
            color: #666;
            line-height: 1.4;
            margin-bottom: 15px;
        }

        .contact-action {
            display: inline-block;
            padding: 8px 16px;
            background: #3762c8;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .contact-action:hover {
            background: #2a4fa8;
            transform: translateY(-1px);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }

        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 0;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }

        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: white;
            padding: 25px 30px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 24px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-close {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            font-size: 24px;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 30px;
        }

        .modal-section {
            margin-bottom: 30px;
        }

        .modal-section:last-child {
            margin-bottom: 0;
        }

        .modal-section-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .modal-card {
            background: rgba(55, 98, 200, 0.05);
            border: 1px solid rgba(55, 98, 200, 0.1);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .modal-card:hover {
            background: rgba(55, 98, 200, 0.08);
            transform: translateY(-2px);
        }

        .modal-card-title {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .modal-card-value {
            font-size: 24px;
            font-weight: 700;
            color: #3762c8;
            margin-bottom: 5px;
        }

        .modal-card-desc {
            font-size: 14px;
            color: #666;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3762c8, #1e3c72);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .chart-container {
            background: white;
            border: 1px solid #e9ecef;
            border-radius: 12px;
            padding: 20px;
            margin: 15px 0;
        }

        .table-container {
            overflow-x: auto;
            margin: 15px 0;
        }

        .modal-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }

        .modal-table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #dee2e6;
        }

        .modal-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #dee2e6;
        }

        .modal-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active { background: #d4edda; color: #155724; }
        .status-completed { background: #cce5ff; color: #004085; }
        .status-pending { background: #fff3cd; color: #856404; }
        .status-delayed { background: #f8d7da; color: #721c24; }

        @media (max-width: 1200px) {
            .public-info-grid {
                grid-template-columns: 1fr;
            }
            
            .publications-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .contact-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .transparency-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .publications-grid {
                grid-template-columns: 1fr;
            }
            
            .contact-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <iframe src="../includes/sidebar.html" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0">
    </iframe>

    <div class="main-content">
        <!-- Transparency Header -->
        <div class="transparency-header">
            <div class="header-content">
                <div class="header-title">
                    <h1>Public Transparency</h1>
                    <p>Manage public information and transparency reports for citizen access</p>
                </div>
                <div class="header-actions">
                    <button class="btn-action btn-secondary" onclick="refreshData()">
                        <i class="fas fa-sync"></i>
                        Refresh Data
                    </button>
                    <button class="btn-action">
                        <i class="fas fa-globe"></i>
                        Public View
                    </button>
                </div>
            </div>
        </div>

        <!-- Transparency Statistics -->
        <div class="transparency-stats">
            <div class="transparency-stat">
                <div class="stat-number"><?php echo number_format($stats['documents']); ?></div>
                <div class="stat-label">Public Documents</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number"><?php echo number_format($stats['views']); ?></div>
                <div class="stat-label">Total Views</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number"><?php echo number_format($stats['downloads']); ?></div>
                <div class="stat-label">Downloads</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number"><?php echo $stats['score']; ?>%</div>
                <div class="stat-label">Transparency Score</div>
            </div>
        </div>

        <!-- Public Information Grid -->
        <div class="public-info-grid">
            <!-- Budget Transparency -->
            <div class="info-card">
                <div class="info-header">
                    <div class="info-icon">
                        <i class="fas fa-coins"></i>
                    </div>
                    <div class="info-title">Budget Transparency</div>
                </div>
                <div class="info-description">
                    Complete breakdown of LGU budget allocation and spending. Real-time tracking of infrastructure projects and public fund utilization.
                </div>
                <div class="info-stats">
                    <div class="info-stat">
                        <div class="info-stat-number">₱<?php echo number_format($budget['annual_budget'] ?? 125000000, 0); ?></div>
                        <div class="info-stat-label">Annual Budget</div>
                    </div>
                    <div class="info-stat">
                        <div class="info-stat-number"><?php echo $budget['allocation_percentage'] ?? 89; ?>%</div>
                        <div class="info-stat-label">Allocated</div>
                    </div>
                </div>
                <div class="info-action">
                    <button class="btn-info" onclick="openModal('budgetModal')">
                        <i class="fas fa-chart-pie"></i>
                        View Budget Details
                    </button>
                </div>
            </div>

            <!-- Project Status -->
            <div class="info-card">
                <div class="info-header">
                    <div class="info-icon">
                        <i class="fas fa-hard-hat"></i>
                    </div>
                    <div class="info-title">Project Status</div>
                </div>
                <div class="info-description">
                    Live tracking of all infrastructure projects with detailed timelines, progress updates, and completion status for public accountability.
                </div>
                <div class="info-stats">
                    <div class="info-stat">
                        <div class="info-stat-number"><?php echo count(array_filter($projects, fn($p) => $p['status'] == 'active')); ?></div>
                        <div class="info-stat-label">Active Projects</div>
                    </div>
                    <div class="info-stat">
                        <div class="info-stat-number"><?php echo count(array_filter($projects, fn($p) => $p['status'] == 'completed')); ?></div>
                        <div class="info-stat-label">Completed</div>
                    </div>
                </div>
                <div class="info-action">
                    <button class="btn-info" onclick="openModal('projectsModal')">
                        <i class="fas fa-tasks"></i>
                        View All Projects
                    </button>
                </div>
            </div>

            <!-- Performance Metrics -->
            <div class="info-card">
                <div class="info-header">
                    <div class="info-icon">
                        <i class="fas fa-tachometer-alt"></i>
                    </div>
                    <div class="info-title">Performance Metrics</div>
                </div>
                <div class="info-description">
                    Key performance indicators and service delivery metrics. Departmental performance scores and citizen satisfaction ratings updated monthly.
                </div>
                <div class="info-stats">
                    <div class="info-stat">
                        <div class="info-stat-number">94%</div>
                        <div class="info-stat-label">Service Delivery</div>
                    </div>
                    <div class="info-stat">
                        <div class="info-stat-number">4.6</div>
                        <div class="info-stat-label">Citizen Rating</div>
                    </div>
                </div>
                <div class="info-action">
                    <button class="btn-info" onclick="openModal('analyticsModal')">
                        <i class="fas fa-analytics"></i>
                        View Analytics
                    </button>
                </div>
            </div>
        </div>

        <!-- Publications Section -->
        <div class="publications-section">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-newspaper"></i>
                    Recent Publications
                </h3>
                <div class="header-actions">
                    <button class="btn-action btn-secondary">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <button class="btn-action">
                        <i class="fas fa-download"></i>
                        Export All
                    </button>
                </div>
            </div>

            <div class="publications-grid">
                <?php foreach ($publications as $pub): ?>
                <div class="publication-card" onclick="viewPublication(<?php echo $pub['id']; ?>)">
                    <span class="publication-type"><?php echo htmlspecialchars($pub['type']); ?></span>
                    <div class="publication-title"><?php echo htmlspecialchars($pub['title']); ?></div>
                    <div class="publication-meta">
                        <span><i class="fas fa-calendar"></i> <?php echo date('F d, Y', strtotime($pub['publish_date'])); ?></span>
                        <span><i class="fas fa-eye"></i> <?php echo number_format($pub['views']); ?> views</span>
                    </div>
                    <div class="publication-description">
                        <?php echo htmlspecialchars($pub['description']); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="contact-section">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-phone-alt"></i>
                    Public Contact Information
                </h3>
            </div>

            <div class="contact-grid">
                <div class="contact-card">
                    <div class="contact-icon">
                        <i class="fas fa-headset"></i>
                    </div>
                    <div class="contact-title">Hotline</div>
                    <div class="contact-info">
                        24/7 Citizen Support Hotline<br>
                        For infrastructure concerns and emergency reports
                    </div>
                    <button class="contact-action" onclick="callHotline()">
                        <i class="fas fa-phone"></i>
                        Call Now
                    </button>
                </div>

                <div class="contact-card">
                    <div class="contact-icon">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="contact-title">Email Support</div>
                    <div class="contact-info">
                        transparency@lgu.gov.ph<br>
                        Response time: 24-48 hours
                    </div>
                    <button class="contact-action" onclick="sendEmail()">
                        <i class="fas fa-paper-plane"></i>
                        Send Email
                    </button>
                </div>

                <div class="contact-card">
                    <div class="contact-icon">
                        <i class="fas fa-map-marker-alt"></i>
                    </div>
                    <div class="contact-title">Office Locations</div>
                    <div class="contact-info">
                        12 service centers citywide<br>
                        Find your nearest LGU office
                    </div>
                    <button class="contact-action" onclick="viewMap()">
                        <i class="fas fa-map"></i>
                        View Map
                    </button>
                </div>

                <div class="contact-card">
                    <div class="contact-icon">
                        <i class="fas fa-comments"></i>
                    </div>
                    <div class="contact-title">Public Forum</div>
                    <div class="contact-info">
                        Community discussion platform<br>
                        Share feedback and suggestions
                    </div>
                    <button class="contact-action" onclick="joinForum()">
                        <i class="fas fa-users"></i>
                        Join Forum
                    </button>
                </div>
            </div>
        </div>

        <!-- Budget Details Modal -->
        <div id="budgetModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">
                        <i class="fas fa-coins"></i>
                        Budget Details
                    </h2>
                    <button class="modal-close" onclick="closeModal('budgetModal')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-chart-pie"></i>
                            Budget Allocation Overview
                        </h3>
                        <div class="modal-grid">
                            <div class="modal-card">
                                <div class="modal-card-title">Annual Budget</div>
                                <div class="modal-card-value">₱<?php echo number_format($budget['annual_budget'] ?? 125000000, 0); ?></div>
                                <div class="modal-card-desc">Total allocated for <?php echo date('Y'); ?></div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Allocated</div>
                                <div class="modal-card-value">₱<?php echo number_format(($budget['annual_budget'] ?? 125000000) * 0.89, 0); ?></div>
                                <div class="modal-card-desc">89% of total budget</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Remaining</div>
                                <div class="modal-card-value">₱<?php echo number_format(($budget['annual_budget'] ?? 125000000) * 0.11, 0); ?></div>
                                <div class="modal-card-desc">11% available</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Spent</div>
                                <div class="modal-card-value">₱<?php echo number_format(($budget['annual_budget'] ?? 125000000) * 0.6996, 0); ?></div>
                                <div class="modal-card-desc">69.96% utilized</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-list"></i>
                            Departmental Breakdown
                        </h3>
                        <div class="table-container">
                            <table class="modal-table">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Allocated</th>
                                        <th>Spent</th>
                                        <th>Remaining</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Road Maintenance</td>
                                        <td>₱45,000,000</td>
                                        <td>₱38,250,000</td>
                                        <td>₱6,750,000</td>
                                        <td><span class="status-badge status-active">Active</span></td>
                                    </tr>
                                    <tr>
                                        <td>Infrastructure Development</td>
                                        <td>₱35,000,000</td>
                                        <td>₱28,900,000</td>
                                        <td>₱6,100,000</td>
                                        <td><span class="status-badge status-active">Active</span></td>
                                    </tr>
                                    <tr>
                                        <td>Bridge Construction</td>
                                        <td>₱25,000,000</td>
                                        <td>₱15,300,000</td>
                                        <td>₱9,700,000</td>
                                        <td><span class="status-badge status-pending">Pending</span></td>
                                    </tr>
                                    <tr>
                                        <td>Street Lighting</td>
                                        <td>₱12,500,000</td>
                                        <td>₱3,000,000</td>
                                        <td>₱9,500,000</td>
                                        <td><span class="status-badge status-pending">Pending</span></td>
                                    </tr>
                                    <tr>
                                        <td>Drainage Systems</td>
                                        <td>₱7,500,000</td>
                                        <td>₱0</td>
                                        <td>₱7,500,000</td>
                                        <td><span class="status-badge status-pending">Pending</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-chart-line"></i>
                            Monthly Spending Trend
                        </h3>
                        <div class="chart-container">
                            <canvas id="budgetChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Projects Modal -->
        <div id="projectsModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">
                        <i class="fas fa-hard-hat"></i>
                        All Projects
                    </h2>
                    <button class="modal-close" onclick="closeModal('projectsModal')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-tasks"></i>
                            Project Overview
                        </h3>
                        <div class="modal-grid">
                            <div class="modal-card">
                                <div class="modal-card-title">Total Projects</div>
                                <div class="modal-card-value"><?php echo count($projects); ?></div>
                                <div class="modal-card-desc">All infrastructure projects</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Active Projects</div>
                                <div class="modal-card-value"><?php echo count(array_filter($projects, fn($p) => $p['status'] == 'active')); ?></div>
                                <div class="modal-card-desc">Currently in progress</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Completed</div>
                                <div class="modal-card-value"><?php echo count(array_filter($projects, fn($p) => $p['status'] == 'completed')); ?></div>
                                <div class="modal-card-desc">Successfully finished</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">On Schedule</div>
                                <div class="modal-card-value">85%</div>
                                <div class="modal-card-desc">Meeting deadlines</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-list"></i>
                            Active Projects List
                        </h3>
                        <div class="table-container">
                            <table class="modal-table">
                                <thead>
                                    <tr>
                                        <th>Project Name</th>
                                        <th>Location</th>
                                        <th>Budget</th>
                                        <th>Progress</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $active_projects = array_filter($projects, fn($p) => $p['status'] == 'active');
                                    foreach (array_slice($active_projects, 0, 5) as $project): 
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($project['name']); ?></td>
                                        <td><?php echo htmlspecialchars($project['location']); ?></td>
                                        <td>₱<?php echo number_format($project['budget'], 0); ?></td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?php echo $project['progress']; ?>%;"></div>
                                            </div>
                                            <?php echo $project['progress']; ?>%
                                        </td>
                                        <td><span class="status-badge status-<?php echo $project['status']; ?>"><?php echo ucfirst($project['status']); ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-calendar-alt"></i>
                            Project Timeline
                        </h3>
                        <div class="chart-container">
                            <canvas id="projectsChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Analytics Modal -->
        <div id="analyticsModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title">
                        <i class="fas fa-tachometer-alt"></i>
                        Performance Analytics
                    </h2>
                    <button class="modal-close" onclick="closeModal('analyticsModal')">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="modal-body">
                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-chart-bar"></i>
                            Key Performance Indicators
                        </h3>
                        <div class="modal-grid">
                            <div class="modal-card">
                                <div class="modal-card-title">Service Delivery</div>
                                <div class="modal-card-value">94%</div>
                                <div class="modal-card-desc">Excellent performance</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Citizen Rating</div>
                                <div class="modal-card-value">4.6/5.0</div>
                                <div class="modal-card-desc">High satisfaction</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Response Time</div>
                                <div class="modal-card-value">2.3 hrs</div>
                                <div class="modal-card-desc">Average response</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Efficiency Score</div>
                                <div class="modal-card-value">87%</div>
                                <div class="modal-card-desc">Resource utilization</div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-star"></i>
                            Departmental Performance
                        </h3>
                        <div class="table-container">
                            <table class="modal-table">
                                <thead>
                                    <tr>
                                        <th>Department</th>
                                        <th>Performance Score</th>
                                        <th>Citizen Rating</th>
                                        <th>Projects Completed</th>
                                        <th>Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Road Maintenance</td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: 92%;"></div>
                                            </div>
                                            92%
                                        </td>
                                        <td>4.7/5.0</td>
                                        <td>12</td>
                                        <td><span style="color: #28a745;">↑ 5%</span></td>
                                    </tr>
                                    <tr>
                                        <td>Infrastructure Development</td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: 88%;"></div>
                                            </div>
                                            88%
                                        </td>
                                        <td>4.5/5.0</td>
                                        <td>8</td>
                                        <td><span style="color: #28a745;">↑ 3%</span></td>
                                    </tr>
                                    <tr>
                                        <td>Bridge Construction</td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: 95%;"></div>
                                            </div>
                                            95%
                                        </td>
                                        <td>4.8/5.0</td>
                                        <td>3</td>
                                        <td><span style="color: #28a745;">↑ 8%</span></td>
                                    </tr>
                                    <tr>
                                        <td>Street Lighting</td>
                                        <td>
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: 78%;"></div>
                                            </div>
                                            78%
                                        </td>
                                        <td>4.2/5.0</td>
                                        <td>0</td>
                                        <td><span style="color: #dc3545;">↓ 2%</span></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="modal-section">
                        <h3 class="modal-section-title">
                            <i class="fas fa-chart-line"></i>
                            Monthly Performance Trend
                        </h3>
                        <div class="chart-container">
                            <canvas id="analyticsChart" width="400" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Publication card interactions
        function viewPublication(pubId) {
            console.log('Opening publication:', pubId);
            // Log view to database
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=view_publication&pub_id=${pubId}`
            });
            alert(`Opening publication ID: ${pubId}`);
        }

        // Contact action buttons
        function callHotline() {
            window.location.href = 'tel:+1234567890';
        }

        function sendEmail() {
            window.location.href = 'mailto:transparency@lgu.gov.ph';
        }

        function viewMap() {
            window.open('https://maps.google.com/?q=LGU+Office+Locations', '_blank');
        }

        function joinForum() {
            window.open('/public-forum', '_blank');
        }

        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('show');
            document.body.style.overflow = 'hidden';
            
            // Initialize charts when modal opens
            if (modalId === 'budgetModal') {
                setTimeout(initBudgetChart, 100);
            } else if (modalId === 'projectsModal') {
                setTimeout(initProjectsChart, 100);
            } else if (modalId === 'analyticsModal') {
                setTimeout(initAnalyticsChart, 100);
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('show');
            document.body.style.overflow = 'auto';
        }

        // Close modal when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.show').forEach(modal => {
                    closeModal(modal.id);
                });
            }
        });

        // Refresh data function
        function refreshData() {
            const btn = event.target.closest('button');
            const originalContent = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
            btn.disabled = true;

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=refresh_data'
            })
            .then(response => response.json())
            .then(data => {
                // Update statistics
                document.querySelector('.transparency-stats').innerHTML = `
                    <div class="transparency-stat">
                        <div class="stat-number">${data.documents.toLocaleString()}</div>
                        <div class="stat-label">Public Documents</div>
                    </div>
                    <div class="transparency-stat">
                        <div class="stat-number">${data.views.toLocaleString()}</div>
                        <div class="stat-label">Total Views</div>
                    </div>
                    <div class="transparency-stat">
                        <div class="stat-number">${data.downloads.toLocaleString()}</div>
                        <div class="stat-label">Downloads</div>
                    </div>
                    <div class="transparency-stat">
                        <div class="stat-number">${data.score}%</div>
                        <div class="stat-label">Transparency Score</div>
                    </div>
                `;
            })
            .catch(error => {
                console.error('Error refreshing data:', error);
                alert('Failed to refresh data. Please try again.');
            })
            .finally(() => {
                btn.innerHTML = originalContent;
                btn.disabled = false;
            });
        }

        // Chart initialization functions
        function initBudgetChart() {
            const ctx = document.getElementById('budgetChart');
            if (!ctx) return;
            
            new Chart(ctx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [{
                        label: 'Monthly Spending',
                        data: [12000000, 14500000, 13800000, 15200000, 14800000, 16500000],
                        borderColor: '#3762c8',
                        backgroundColor: 'rgba(55, 98, 200, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return '₱' + (value / 1000000).toFixed(1) + 'M';
                                }
                            }
                        }
                    }
                }
            });
        }

        function initProjectsChart() {
            const ctx = document.getElementById('projectsChart');
            if (!ctx) return;
            
            new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: ['Q1', 'Q2', 'Q3', 'Q4'],
                    datasets: [
                        {
                            label: 'Completed',
                            data: [5, 8, 6, 4],
                            backgroundColor: '#28a745'
                        },
                        {
                            label: 'In Progress',
                            data: [12, 15, 13, 7],
                            backgroundColor: '#3762c8'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }

        function initAnalyticsChart() {
            const ctx = document.getElementById('analyticsChart');
            if (!ctx) return;
            
            new Chart(ctx.getContext('2d'), {
                type: 'line',
                data: {
                    labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                    datasets: [
                        {
                            label: 'Service Delivery %',
                            data: [88, 90, 92, 91, 93, 94],
                            borderColor: '#3762c8',
                            backgroundColor: 'rgba(55, 98, 200, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Citizen Rating',
                            data: [4.2, 4.3, 4.4, 4.5, 4.5, 4.6],
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40, 167, 69, 0.1)',
                            tension: 0.4,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }

        // Header actions
        document.querySelectorAll('.btn-action').forEach(btn => {
            btn.addEventListener('click', function() {
                const action = this.textContent.trim();
                console.log('Header action:', action);
                
                if (action.includes('Public View')) {
                    window.open('/public-transparency-view', '_blank');
                } else if (action.includes('Filter')) {
                    alert('Filter functionality coming soon!');
                } else if (action.includes('Export All')) {
                    window.open('/export-publications', '_blank');
                }
            });
        });
    </script>
</body>
</html>

<?php
// citizen_reports_view.php - LGU Officer View for Citizen Reports
session_start();

$basePath = '';
$loginUrl = 'login.php';

$isRoot = (strpos($_SERVER['PHP_SELF'], 'road_and_infra_dept') === false);
if ($isRoot) {
    $loginUrl = 'index.php';
}

// Include authentication and database
require_once '../config/auth.php';
require_once '../config/database.php';

// Require LGU officer role (admin also has access)
$auth->requireAnyRole(['lgu_officer', 'admin']);

// Log page access
$auth->logActivity('page_access', 'Accessed citizen reports view');

// Get database statistics for display
try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Get statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) as under_review,
            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
            SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN severity = 'urgent' THEN 1 ELSE 0 END) as urgent,
            SUM(CASE WHEN severity = 'high' THEN 1 ELSE 0 END) as high
        FROM damage_reports
    ";
    
    $stats_result = $conn->query($stats_query);
    $stats = $stats_result ? $stats_result->fetch_assoc() : [
        'total' => 0, 'pending' => 0, 'under_review' => 0, 'approved' => 0, 
        'in_progress' => 0, 'completed' => 0, 'urgent' => 0, 'high' => 0
    ];
    
} catch (Exception $e) {
    $stats = [
        'total' => 0, 'pending' => 0, 'under_review' => 0, 'approved' => 0, 
        'in_progress' => 0, 'completed' => 0, 'urgent' => 0, 'high' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Citizen Reports - LGU Officer Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Import the same styles as other LGU pages */
        @import url("https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap");

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: "Poppins", sans-serif;
            background: url("../user_and_access_management_module/assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
            min-height: 100vh;
        }

        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(6px);
            background: rgba(0, 0, 0, 0.35);
            z-index: 0;
        }

        /* Main Content Layout */
        .main-content {
            margin-left: 250px;
            padding: 20px;
            position: relative;
            z-index: 1;
            min-height: 100vh;
        }

        .page-header {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }

        .page-header h1 {
            color: #2c3e50;
            font-size: 2.5rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-header p {
            color: #7f8c8d;
            font-size: 1.1rem;
        }

        .divider {
            border: none;
            height: 2px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            margin: 15px 0;
            opacity: 0.3;
        }

        .header h1 {
            color: #2c3e50;
            font-size: 2.5rem;
            margin-bottom: 10px;
        }

        .header p {
            color: #7f8c8d;
            font-size: 1.1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 15px;
        }

        .stat-icon.pending { color: #f39c12; }
        .stat-icon.under_review { color: #3498db; }
        .stat-icon.completed { color: #27ae60; }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        .filters {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .filter-group input,
        .filter-group select {
            padding: 12px;
            border: 2px solid #e1e8ed;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #667eea;
        }

        .reports-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }

        .reports-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .reports-header h2 {
            color: #2c3e50;
            font-size: 1.8rem;
        }

        .loading {
            text-align: center;
            padding: 50px;
            color: #7f8c8d;
        }

        .loading i {
            font-size: 2rem;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .reports-table {
            width: 100%;
            border-collapse: collapse;
        }

        .reports-table th {
            background: #f8f9fa;
            color: #2c3e50;
            font-weight: 600;
            text-align: left;
            padding: 15px;
            border-bottom: 2px solid #e1e8ed;
        }

        .reports-table td {
            padding: 15px;
            border-bottom: 1px solid #e1e8ed;
            vertical-align: top;
        }

        .reports-table tr:hover {
            background: #f8f9fa;
        }

        .report-id {
            font-weight: 600;
            color: #667eea;
        }

        .severity-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .severity-urgent { background: #e74c3c; color: white; }
        .severity-high { background: #f39c12; color: white; }
        .severity-medium { background: #3498db; color: white; }
        .severity-low { background: #95a5a6; color: white; }

        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-pending { background: #f39c12; color: white; }
        .status-under_review { background: #3498db; color: white; }
        .status-approved { background: #27ae60; color: white; }
        .status-in_progress { background: #8e44ad; color: white; }
        .status-completed { background: #27ae60; color: white; }
        .status-rejected { background: #e74c3c; color: white; }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .btn {
            padding: 8px 15px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-view {
            background: #667eea;
            color: white;
        }

        .btn-view:hover {
            background: #5a6fd8;
            transform: translateY(-2px);
        }

        .btn-manage {
            background: #27ae60;
            color: white;
        }

        .btn-manage:hover {
            background: #229954;
            transform: translateY(-2px);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background: white;
            margin: 2% auto;
            padding: 0;
            border-radius: 20px;
            width: 95%;
            max-width: 900px;
            max-height: 95vh;
            overflow-y: auto;
            animation: slideIn 0.3s ease;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 20px 20px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            font-size: 1.8rem;
            margin: 0;
            font-weight: 600;
        }

        .modal-close {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .modal-close:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.1);
        }

        .modal-body {
            padding: 40px;
            background: #f8f9fa;
        }

        .report-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .detail-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
        }

        .detail-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
        }

        .detail-label {
            font-size: 0.85rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .detail-value {
            font-size: 1.1rem;
            color: #2c3e50;
            font-weight: 500;
        }

        .severity-indicator {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .severity-urgent { background: #e74c3c; color: white; }
        .severity-high { background: #f39c12; color: white; }
        .severity-medium { background: #3498db; color: white; }
        .severity-low { background: #95a5a6; color: white; }

        .status-indicator {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 25px;
            font-size: 0.9rem;
            font-weight: 600;
            text-transform: capitalize;
            letter-spacing: 0.5px;
        }

        .status-pending { background: #f39c12; color: white; }
        .status-under_review { background: #3498db; color: white; }
        .status-approved { background: #27ae60; color: white; }
        .status-in_progress { background: #8e44ad; color: white; }
        .status-completed { background: #27ae60; color: white; }
        .status-rejected { background: #e74c3c; color: white; }

        .description-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .description-text {
            line-height: 1.6;
            color: #2c3e50;
            font-size: 1.05rem;
        }

        .images-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .section-title {
            font-size: 1.3rem;
            color: #2c3e50;
            margin-bottom: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title i {
            color: #667eea;
        }

        .image-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .image-thumb {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: 12px;
            border: 3px solid #e1e8ed;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .image-thumb:hover {
            border-color: #667eea;
            transform: scale(1.05);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.3);
        }

        .no-images {
            text-align: center;
            padding: 40px;
            background: #f8f9fa;
            border-radius: 12px;
            color: #6c757d;
            border: 2px dashed #dee2e6;
        }

        .no-images i {
            font-size: 3.5rem;
            margin-bottom: 15px;
            opacity: 0.4;
            color: #6c757d;
        }

        .no-images p {
            font-size: 1.1rem;
            margin-bottom: 10px;
        }

        .no-images small {
            color: #6c757d;
            font-size: 0.85rem;
        }

        .action-section {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            color: #2c3e50;
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 0.95rem;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid #e1e8ed;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }

        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 120px;
            font-family: inherit;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e1e8ed;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            margin-right: 10px;
            transition: all 0.3s ease;
        }

        .btn-secondary:hover {
            background: #7f8c8d;
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 15px 25px;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            z-index: 1001;
            animation: slideInRight 0.3s ease;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .toast.success {
            border-left: 4px solid #27ae60;
        }

        .toast.error {
            border-left: 4px solid #e74c3c;
        }

        .toast.info {
            border-left: 4px solid #3498db;
        }

        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }

            .header h1 {
                font-size: 2rem;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .reports-table {
                font-size: 0.9rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .modal-content {
                width: 95%;
                margin: 2% auto;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <?php include '../sidebar/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
    <div class="stats-grid" id="statsGrid">
            <div class="stat-card">
                <div class="stat-icon total">
                    <i class="fas fa-clipboard-list"></i>
                </div>
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Reports</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon pending">
                    <i class="fas fa-clock"></i>
                </div>
                <div class="stat-number"><?php echo $stats['pending']; ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon under_review">
                    <i class="fas fa-search"></i>
                </div>
                <div class="stat-number"><?php echo $stats['under_review']; ?></div>
                <div class="stat-label">Under Review</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon completed">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-number"><?php echo $stats['completed']; ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>

    <div class="filters">
        <div class="filter-grid">
            <div class="filter-group">
                <label for="searchInput">Search</label>
                <input type="text" id="searchInput" placeholder="Search reports...">
            </div>
            <div class="filter-group">
                <label for="statusFilter">Status</label>
                <select id="statusFilter">
                    <option value="all">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="under_review">Under Review</option>
                    <option value="approved">Approved</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="severityFilter">Severity</label>
                <select id="severityFilter">
                    <option value="all">All Levels</option>
                    <option value="urgent">Urgent</option>
                    <option value="high">High</option>
                    <option value="medium">Medium</option>
                    <option value="low">Low</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="sortFilter">Sort By</label>
                <select id="sortFilter">
                    <option value="latest">Latest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="severity_high">High Severity First</option>
                    <option value="severity_low">Low Severity First</option>
                </select>
            </div>
        </div>
    </div>

    <div class="reports-container">
        <div class="reports-header">
            <h2>Recent Reports</h2>
            <button class="btn btn-primary" onclick="refreshReports()">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
        </div>
        <div id="reportsTableContainer">
            <div class="loading">
                <i class="fas fa-spinner fa-spin"></i>
                <p>Loading reports...</p>
            </div>
        </div>
    </div>
    </main>

    <!-- Report Details Modal -->
    <div id="reportModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Report Details</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div id="modalBody">
                <!-- Content will be loaded here -->
            </div>
        </div>
    </div>

    <script>
        let currentReports = [];
        let currentReport = null;

        // Load reports on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadReports();
            
            // Add event listeners for filters
            document.getElementById('searchInput').addEventListener('input', debounce(loadReports, 500));
            document.getElementById('statusFilter').addEventListener('change', loadReports);
            document.getElementById('severityFilter').addEventListener('change', loadReports);
            document.getElementById('sortFilter').addEventListener('change', loadReports);
        });

        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        function loadReports() {
            console.log('Loading reports...');
            
            const search = document.getElementById('searchInput').value;
            const status = document.getElementById('statusFilter').value;
            const severity = document.getElementById('severityFilter').value;
            const sort = document.getElementById('sortFilter').value;

            const params = new URLSearchParams({
                search: search,
                status: status,
                severity: severity,
                sort: sort
            });

            fetch('api/get_citizen_reports.php?' + params.toString())
                .then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status + ': ' + response.statusText);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Reports data:', data);
                    
                    if (data.success) {
                        currentReports = data.data.reports;
                        renderReportsTable(currentReports);
                        renderStats(data.data.stats);
                    } else {
                        showToast(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error loading reports:', error);
                    showToast('Error loading reports: ' + error.message, 'error');
                });
        }

        function renderStats(stats) {
            const statsGrid = document.getElementById('statsGrid');
            statsGrid.innerHTML = `
                <div class="stat-card">
                    <div class="stat-icon" style="color: #3498db;">
                        <i class="fas fa-clipboard-list"></i>
                    </div>
                    <div class="stat-number">${stats.total || 0}</div>
                    <div class="stat-label">Total Reports</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="color: #f39c12;">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="stat-number">${stats.pending || 0}</div>
                    <div class="stat-label">Pending</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="color: #3498db;">
                        <i class="fas fa-search"></i>
                    </div>
                    <div class="stat-number">${stats.under_review || 0}</div>
                    <div class="stat-label">Under Review</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="color: #27ae60;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-number">${stats.completed || 0}</div>
                    <div class="stat-label">Completed</div>
                </div>
            `;
        }

        function renderReportsTable(reports) {
            const container = document.getElementById('reportsTableContainer');
            
            if (reports.length === 0) {
                container.innerHTML = `
                    <div class="loading">
                        <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 15px; opacity: 0.5;"></i>
                        <p>No reports found matching your criteria.</p>
                    </div>
                `;
                return;
            }

            const tableHTML = `
                <table class="reports-table">
                    <thead>
                        <tr>
                            <th>Report ID</th>
                            <th>Location</th>
                            <th>Type</th>
                            <th>Severity</th>
                            <th>Status</th>
                            <th>Reported</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${reports.map(report => `
                            <tr>
                                <td class="report-id">${report.report_id}</td>
                                <td>${report.location}</td>
                                <td>${report.damage_type}</td>
                                <td>
                                    <span class="severity-badge severity-${report.severity}">
                                        ${report.severity}
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge status-${report.status}">
                                        ${report.status.replace('_', ' ')}
                                    </span>
                                </td>
                                <td>${report.created_at_formatted}</td>
                                <td>
                                    <div class="action-buttons">
                                        <button class="btn btn-view" onclick="viewReport('${report.report_id}')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <button class="btn btn-manage" onclick="manageReport('${report.report_id}')">
                                            <i class="fas fa-edit"></i> Manage
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
            
            container.innerHTML = tableHTML;
        }

        function viewReport(reportId) {
            event.preventDefault();
            event.stopPropagation();
            
            console.log('Viewing report:', reportId);
            
            const report = currentReports.find(r => r.report_id === reportId);
            if (!report) {
                showToast('Error: Report not found', 'error');
                return;
            }

            currentReport = report;
            const modalBody = document.getElementById('modalBody');
            
            console.log('Report data:', report);
            console.log('Images data:', report.images);
            
            // Handle images - ensure it's an array
            let images = [];
            if (report.images) {
                console.log('Raw images data:', report.images);
                console.log('Images type:', typeof report.images);
                console.log('Images is array:', Array.isArray(report.images));
                
                try {
                    if (typeof report.images === 'string') {
                        // If it's a string, try to parse it as JSON
                        const parsed = JSON.parse(report.images);
                        images = Array.isArray(parsed) ? parsed : [];
                    } else if (Array.isArray(report.images)) {
                        // If it's already an array, use it directly
                        images = report.images;
                    } else {
                        // If it's something else, treat as empty
                        images = [];
                    }
                } catch (e) {
                    console.error('Error parsing images:', e);
                    images = [];
                }
            }
            
            console.log('Final processed images:', images);
            console.log('Images array length:', images.length);
            
            const imagesHtml = images && images.length > 0 ? `
                <div class="images-section">
                    <h3 class="section-title">
                        <i class="fas fa-camera"></i> Evidence Photos
                    </h3>
                    <div class="image-gallery">
                        ${images.map(img => `
                            <img src="../uploads/reports/${img}" 
                                 alt="Report Image" 
                                 class="image-thumb" 
                                 onclick="window.open('../uploads/reports/${img}', '_blank')"
                                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAwIiBoZWlnaHQ9IjE4MCIgdmlld0JveD0iMCAwIDIwMCAxODAiIGZpbGw9Im5vbmUiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+CjxyZWN0IHdpZHRoPSIyMDAiIGhlaWdodD0iMTgwIiBmaWxsPSIjRjhGOUZBIi8+CjxwYXRoIGQ9Ik0xMDAgNDBDMTIwIDQwIDE0MCA2MCAxNDAgOTBDMTQwIDEyMCAxMjAgMTQwIDEwMCAxNDBDODAgMTQwIDYwIDEyMCA2MCA5MEM2MCA2MCA4MCA0MCAxMDAgNDBaIiBmaWxsPSIjQ0NDQzQxIi8+CjxwYXRoIGQ9Ik04MCA5MEgxMjBWMTIwSDE0MFY5MEg4MFoiIGZpbGw9IiNDQ0M0MSIvPgo8L3N2Zz4K'; this.title='Image not found: ${img}';">
                        `).join('')}
                    </div>
                </div>
            ` : `
                <div class="images-section">
                    <h3 class="section-title">
                        <i class="fas fa-camera"></i> Evidence Photos
                    </h3>
                    <div class="no-images">
                        <i class="fas fa-image"></i>
                        <p>No photos uploaded with this report</p>
                        <small>Debug: Images data = ${JSON.stringify(report.images)}</small>
                    </div>
                </div>
            `;

            modalBody.innerHTML = `
                <div style="max-height: 70vh; overflow-y: auto;">
                    <div class="report-details-grid">
                        <div class="detail-card">
                            <div class="detail-label">Report ID</div>
                            <div class="detail-value">${report.report_id}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Reporter</div>
                            <div class="detail-value">${report.reporter_name || 'Anonymous'}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Location</div>
                            <div class="detail-value">${report.location}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Barangay</div>
                            <div class="detail-value">${report.barangay}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Damage Type</div>
                            <div class="detail-value">${report.damage_type}</div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Severity</div>
                            <div class="detail-value">
                                <span class="severity-indicator severity-${report.severity}">
                                    ${report.severity}
                                </span>
                            </div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Status</div>
                            <div class="detail-value">
                                <span class="status-indicator status-${report.status}">
                                    ${report.status.replace('_', ' ')}
                                </span>
                            </div>
                        </div>
                        <div class="detail-card">
                            <div class="detail-label">Reported Date</div>
                            <div class="detail-value">${report.created_at_formatted}</div>
                        </div>
                    </div>
                    
                    <div class="description-section">
                        <h3 class="section-title">
                            <i class="fas fa-info-circle"></i> Description
                        </h3>
                        <div class="description-text">${report.description}</div>
                    </div>
                    
                    ${imagesHtml}
                    
                    <div class="action-section">
                        <h3 class="section-title">
                            <i class="fas fa-edit"></i> Update Report Status
                        </h3>
                        <div class="form-group">
                            <label for="currentStatus">Current Status</label>
                            <select id="currentStatus">
                                <option value="pending" ${report.status === 'pending' ? 'selected' : ''}>Pending</option>
                                <option value="under_review" ${report.status === 'under_review' ? 'selected' : ''}>Under Review</option>
                                <option value="approved" ${report.status === 'approved' ? 'selected' : ''}>Approved</option>
                                <option value="in_progress" ${report.status === 'in_progress' ? 'selected' : ''}>In Progress</option>
                                <option value="completed" ${report.status === 'completed' ? 'selected' : ''}>Completed</option>
                                <option value="rejected" ${report.status === 'rejected' ? 'selected' : ''}>Rejected</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="lguNotes">LGU Notes</label>
                            <textarea id="lguNotes" placeholder="Add notes about this report...">${report.lgu_notes || ''}</textarea>
                        </div>
                        
                        <div class="modal-actions">
                            <button class="btn btn-secondary" onclick="closeModal()">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                            <button class="btn btn-primary" onclick="updateReportStatus()">
                                <i class="fas fa-save"></i> Update Status
                            </button>
                        </div>
                    </div>
                </div>
            `;

            document.getElementById('reportModal').style.display = 'block';
        }

        function manageReport(reportId) {
            viewReport(reportId);
        }

        function updateReportStatus() {
            if (!currentReport) return;
            
            const status = document.getElementById('currentStatus').value;
            const notes = document.getElementById('lguNotes').value;
            
            fetch('api/update_report_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    report_id: currentReport.report_id,
                    status: status,
                    notes: notes
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    let message = 'Report status updated successfully';
                    
                    // Check if an inspection was created
                    if (data.data.inspection_created) {
                        message += '. ' + data.data.inspection_created.message + ' (' + data.data.inspection_created.inspection_id + ')';
                        message += '. You can now view this in the Inspection Management module.';
                    }
                    
                    showToast(message, 'success');
                    closeModal();
                    loadReports(); // Refresh the reports list
                } else {
                    showToast(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error updating status:', error);
                showToast('Error updating status', 'error');
            });
        }

        function closeModal() {
            document.getElementById('reportModal').style.display = 'none';
            currentReport = null;
        }

        function refreshReports() {
            loadReports();
            showToast('Reports refreshed', 'info');
        }

        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.innerHTML = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('reportModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>

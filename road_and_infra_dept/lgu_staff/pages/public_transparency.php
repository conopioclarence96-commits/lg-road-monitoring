<?php
session_start();
require_once '../config/database.php';

// Authentication check
function requireAuth() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit();
    }
    return $_SESSION['user_id'];
}

// Get current user
function getCurrentUser($db) {
    $userId = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT id, username, full_name, email, department, role FROM staff WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get transparency statistics
function getTransparencyStatistics($db) {
    $stats = [];
    
    // Get publication count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM publications WHERE status = 'published'");
    $stmt->execute();
    $stats['publications'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get total budget
    $stmt = $db->prepare("SELECT SUM(amount) as total FROM budget_allocations WHERE fiscal_year = YEAR(CURRENT_DATE)");
    $stmt->execute();
    $stats['total_budget'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
    
    // Get active projects
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM projects WHERE status = 'active'");
    $stmt->execute();
    $stats['active_projects'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get citizen satisfaction
    $stmt = $db->prepare("SELECT AVG(rating) as avg_rating FROM citizen_feedback WHERE created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)");
    $stmt->execute();
    $stats['satisfaction'] = round($stmt->fetch(PDO::FETCH_ASSOC)['avg_rating'] ?? 0, 1);
    
    return $stats;
}

// Get recent publications
function getRecentPublications($db, $limit = 10) {
    $stmt = $db->prepare("
        SELECT p.*, COUNT(DISTINCT pv.id) as views 
        FROM publications p 
        LEFT JOIN publication_views pv ON p.id = pv.publication_id 
        WHERE p.status = 'published' 
        GROUP BY p.id 
        ORDER BY p.created_at DESC 
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get budget transparency data
function getBudgetTransparency($db) {
    $data = [];
    
    // Monthly spending
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(created_at, '%b') as month, SUM(amount) as total
        FROM budget_expenditures 
        WHERE created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%m-%Y')
        ORDER BY created_at
    ");
    $stmt->execute();
    $data['monthly_spending'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Department allocations
    $stmt = $db->prepare("
        SELECT d.name as department, SUM(ba.amount) as allocation
        FROM budget_allocations ba
        JOIN departments d ON ba.department_id = d.id
        WHERE ba.fiscal_year = YEAR(CURRENT_DATE)
        GROUP BY d.id, d.name
        ORDER BY allocation DESC
    ");
    $stmt->execute();
    $data['department_allocations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $data;
}

// Get project status data
function getProjectStatus($db) {
    $data = [];
    
    // Project counts by status
    $stmt = $db->prepare("
        SELECT status, COUNT(*) as count
        FROM projects
        GROUP BY status
    ");
    $stmt->execute();
    $data['status_counts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent projects
    $stmt = $db->prepare("
        SELECT p.*, d.name as department
        FROM projects p
        JOIN departments d ON p.department_id = d.id
        ORDER BY p.created_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $data['recent_projects'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $data;
}

// Get performance metrics
function getPerformanceMetrics($db) {
    $data = [];
    
    // Department performance
    $stmt = $db->prepare("
        SELECT d.name as department, 
               AVG(ps.service_delivery_score) as avg_service,
               AVG(ps.citizen_rating) as avg_rating,
               COUNT(ps.id) as metrics_count
        FROM performance_scores ps
        JOIN departments d ON ps.department_id = d.id
        WHERE ps.created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)
        GROUP BY d.id, d.name
        ORDER BY avg_service DESC
    ");
    $stmt->execute();
    $data['department_performance'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Monthly trends
    $stmt = $db->prepare("
        SELECT DATE_FORMAT(created_at, '%b') as month,
               AVG(service_delivery_score) as avg_service,
               AVG(citizen_rating) as avg_rating
        FROM performance_scores
        WHERE created_at >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%m-%Y')
        ORDER BY created_at
    ");
    $stmt->execute();
    $data['monthly_trends'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $data;
}

// Initialize database and get data
$db = new Database();
$currentUser = getCurrentUser($db->pdo);
$stats = getTransparencyStatistics($db->pdo);
$publications = getRecentPublications($db->pdo);
$budgetData = getBudgetTransparency($db->pdo);
$projectData = getProjectStatus($db->pdo);
$performanceData = getPerformanceMetrics($db->pdo);

requireAuth();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Transparency | LGU Staff</title>
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
            margin-bottom: 15px;
        }

        .header-title {
            font-size: 28px;
            font-weight: 600;
            color: #1e3c72;
        }

        .header-subtitle {
            color: #666;
            font-size: 16px;
            margin-top: 5px;
        }

        .header-actions {
            display: flex;
            gap: 15px;
        }

        .btn-action {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3762c8, #254399);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.3);
        }

        .btn-secondary {
            background: linear-gradient(135deg, #6c757d, #495057);
            color: white;
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
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid rgba(55, 98, 200, 0.1);
            transition: all 0.3s ease;
        }

        .transparency-stat:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(55, 98, 200, 0.15);
        }

        .stat-icon {
            font-size: 24px;
            color: #3762c8;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #666;
            font-size: 14px;
        }

        .info-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 25px;
        }

        .info-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            border: 1px solid rgba(55, 98, 200, 0.1);
            transition: all 0.3s ease;
        }

        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(55, 98, 200, 0.15);
        }

        .info-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .info-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, #3762c8, #254399);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }

        .info-title {
            font-size: 18px;
            font-weight: 600;
            color: #1e3c72;
        }

        .info-description {
            color: #666;
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .info-stats {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .info-stat {
            text-align: center;
        }

        .info-stat-number {
            font-size: 20px;
            font-weight: 600;
            color: #1e3c72;
        }

        .info-stat-label {
            font-size: 12px;
            color: #666;
        }

        .info-action {
            margin-top: 15px;
        }

        .btn-info {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background: linear-gradient(135deg, #3762c8, #254399);
            color: white;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-info:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.3);
        }

        .publications-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 25px;
            border-radius: 16px;
            border: 1px solid rgba(55, 98, 200, 0.1);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 20px;
            font-weight: 600;
            color: #1e3c72;
        }

        .section-actions {
            display: flex;
            gap: 15px;
        }

        .publications-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }

        .publication-card {
            background: rgba(255, 255, 255, 0.7);
            border: 1px solid rgba(55, 98, 200, 0.1);
            border-radius: 12px;
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .publication-card:hover {
            background: rgba(255, 255, 255, 0.9);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.1);
        }

        .publication-type {
            display: inline-block;
            padding: 4px 8px;
            background: rgba(55, 98, 200, 0.1);
            color: #3762c8;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            margin-bottom: 10px;
        }

        .publication-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 10px;
            line-height: 1.4;
        }

        .publication-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            font-size: 12px;
            color: #666;
        }

        .publication-description {
            color: #666;
            font-size: 14px;
            line-height: 1.5;
        }

        .contact-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 25px;
        }

        .contact-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid rgba(55, 98, 200, 0.1);
            transition: all 0.3s ease;
        }

        .contact-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(55, 98, 200, 0.15);
        }

        .contact-icon {
            font-size: 32px;
            color: #3762c8;
            margin-bottom: 15px;
        }

        .contact-title {
            font-size: 16px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 10px;
        }

        .contact-info {
            color: #666;
            font-size: 14px;
            margin-bottom: 15px;
        }

        .contact-action {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            background: linear-gradient(135deg, #3762c8, #254399);
            color: white;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .contact-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55, 98, 200, 0.3);
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }

            .header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 15px;
            }

            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }

            .info-section {
                grid-template-columns: 1fr;
            }

            .publications-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <iframe src="../includes/sidebar.html" style="position: fixed; top: 0; left: 0; width: 250px; height: 100vh; border: none; z-index: 999;"></iframe>

    <div class="main-content">
        <div class="transparency-header">
            <div class="header-content">
                <div>
                    <h1 class="header-title">Public Transparency Portal</h1>
                    <p class="header-subtitle">Real-time transparency data and public information access</p>
                </div>
                <div class="header-actions">
                    <button class="btn-action btn-primary">
                        <i class="fas fa-download"></i>
                        Export Report
                    </button>
                    <button class="btn-action btn-secondary">
                        <i class="fas fa-cog"></i>
                        Settings
                    </button>
                </div>
            </div>
        </div>

        <div class="transparency-stats">
            <div class="transparency-stat">
                <div class="stat-icon">
                    <i class="fas fa-file-alt"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['publications']); ?></div>
                <div class="stat-label">Public Documents</div>
            </div>

            <div class="transparency-stat">
                <div class="stat-icon">
                    <i class="fas fa-peso-sign"></i>
                </div>
                <div class="stat-number">₱<?php echo number_format($stats['total_budget'], 0); ?></div>
                <div class="stat-label">Total Budget</div>
            </div>

            <div class="transparency-stat">
                <div class="stat-icon">
                    <i class="fas fa-hard-hat"></i>
                </div>
                <div class="stat-number"><?php echo number_format($stats['active_projects']); ?></div>
                <div class="stat-label">Active Projects</div>
            </div>

            <div class="transparency-stat">
                <div class="stat-icon">
                    <i class="fas fa-star"></i>
                </div>
                <div class="stat-number"><?php echo $stats['satisfaction']; ?>/5.0</div>
                <div class="stat-label">Citizen Rating</div>
            </div>
        </div>

        <div class="info-section">
            <!-- Budget Transparency -->
            <div class="info-card">
                <div class="info-header">
                    <div class="info-icon">
                        <i class="fas fa-peso-sign"></i>
                    </div>
                    <div class="info-title">Budget Transparency</div>
                </div>
                <div class="info-description">
                    Real-time budget allocation and expenditure tracking. Complete financial transparency for all infrastructure projects and operational costs.
                </div>
                <div class="info-stats">
                    <div class="info-stat">
                        <div class="info-stat-number">₱<?php echo number_format($stats['total_budget'], 0); ?></div>
                        <div class="info-stat-label">Total Budget</div>
                    </div>
                    <div class="info-stat">
                        <div class="info-stat-number">87%</div>
                        <div class="info-stat-label">Utilization</div>
                    </div>
                </div>
                <div class="info-action">
                    <button class="btn-info">
                        <i class="fas fa-chart-line"></i>
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
                        <div class="info-stat-number"><?php echo number_format($stats['active_projects']); ?></div>
                        <div class="info-stat-label">Active Projects</div>
                    </div>
                    <div class="info-stat">
                        <div class="info-stat-number">23</div>
                        <div class="info-stat-label">Completed</div>
                    </div>
                </div>
                <div class="info-action">
                    <button class="btn-info">
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
                        <div class="info-stat-number"><?php echo $stats['satisfaction']; ?></div>
                        <div class="info-stat-label">Citizen Rating</div>
                    </div>
                </div>
                <div class="info-action">
                    <button class="btn-info">
                        <i class="fas fa-chart-bar"></i>
                        View Analytics
                    </button>
                </div>
            </div>
        </div>

        <div class="publications-section">
            <div class="section-header">
                <h2 class="section-title">Recent Publications</h2>
                <div class="section-actions">
                    <button class="btn-action btn-secondary">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <button class="btn-action btn-secondary">
                        <i class="fas fa-download"></i>
                        Export All
                    </button>
                </div>
            </div>

            <div class="publications-grid">
                <?php foreach ($publications as $publication): ?>
                <div class="publication-card">
                    <span class="publication-type"><?php echo htmlspecialchars($publication['type']); ?></span>
                    <div class="publication-title"><?php echo htmlspecialchars($publication['title']); ?></div>
                    <div class="publication-meta">
                        <span><i class="fas fa-calendar"></i> <?php echo date('F d, Y', strtotime($publication['created_at'])); ?></span>
                        <span><i class="fas fa-eye"></i> <?php echo number_format($publication['views']); ?> views</span>
                    </div>
                    <div class="publication-description">
                        <?php echo htmlspecialchars(substr($publication['description'] ?? 'No description available', 0, 100)) . '...'; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="contact-section">
            <div class="contact-card">
                <div class="contact-icon">
                    <i class="fas fa-phone"></i>
                </div>
                <div class="contact-title">Hotline</div>
                <div class="contact-info">
                    (123) 456-7890<br>
                    Mon-Fri, 8AM-5PM
                </div>
                <button class="contact-action">Call Now</button>
            </div>

            <div class="contact-card">
                <div class="contact-icon">
                    <i class="fas fa-envelope"></i>
                </div>
                <div class="contact-title">Email Support</div>
                <div class="contact-info">
                    transparency@lgu.gov.ph<br>
                    Response within 24hrs
                </div>
                <button class="contact-action">Send Email</button>
            </div>

            <div class="contact-card">
                <div class="contact-icon">
                    <i class="fas fa-map-marker-alt"></i>
                </div>
                <div class="contact-title">Office Visit</div>
                <div class="contact-info">
                    City Hall Building<br>
                    Transparency Office, Room 205
                </div>
                <button class="contact-action">Get Directions</button>
            </div>
        </div>
    </div>

    <script>
        // Publication card interactions
        document.querySelectorAll('.publication-card').forEach(card => {
            card.addEventListener('click', function() {
                const title = this.querySelector('.publication-title').textContent;
                console.log('Opening publication:', title);
                alert(`Opening: ${title}`);
            });
        });

        // Contact action buttons
        document.querySelectorAll('.contact-action').forEach(btn => {
            btn.addEventListener('click', function() {
                const action = this.closest('.contact-card').querySelector('.contact-title').textContent;
                console.log('Contact action:', action);
                alert(`Contact: ${action}`);
            });
        });

        // Info action buttons
        document.querySelectorAll('.btn-info').forEach(btn => {
            btn.addEventListener('click', function() {
                const action = this.textContent.trim();
                console.log('Info action:', action);
                alert(`Action: ${action}`);
            });
        });

        // Header actions
        document.querySelectorAll('.btn-action').forEach(btn => {
            btn.addEventListener('click', function() {
                const action = this.textContent.trim();
                console.log('Header action:', action);
                alert(`Action: ${action}`);
            });
        });
    </script>
</body>
</html>

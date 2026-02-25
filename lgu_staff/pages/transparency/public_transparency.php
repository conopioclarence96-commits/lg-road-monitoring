<?php
// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();
require_once __DIR__ . '/../../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit();
}

// Default stats when transparency tables are missing
function getDefaultTransparencyStats() {
    return [
        'documents' => 156,
        'views' => 2847,
        'downloads' => 423,
        'score' => 98.5
    ];
}

// Function to get transparency statistics (uses new transparency portal tables)
function getTransparencyStats() {
    global $conn;
    $stats = getDefaultTransparencyStats();
    
    if (!$conn) {
        return $stats;
    }
    
    // Get publications count from new publications table
    $r = @$conn->query("SELECT COUNT(*) as count FROM publications WHERE is_published = 1");
    if ($r && $row = $r->fetch_assoc()) {
        $stats['documents'] = (int) $row['count'];
    }
    
    // Get total views from publications table
    $r = @$conn->query("SELECT SUM(view_count) as total FROM publications WHERE is_published = 1");
    if ($r && $row = $r->fetch_assoc()) {
        $total = $row['total'] ?? 0;
        if ($total !== null) {
            $stats['views'] = (int) $total;
        }
    }
    
    // Get total downloads from publications table
    $r = @$conn->query("SELECT SUM(download_count) as total FROM publications WHERE is_published = 1");
    if ($r && $row = $r->fetch_assoc()) {
        $total = $row['total'] ?? 0;
        if ($total !== null) {
            $stats['downloads'] = (int) $total;
        }
    }
    
    // Calculate transparency score from transparency_scores table
    $r = @$conn->query("SELECT overall_score FROM transparency_scores ORDER BY score_date DESC LIMIT 1");
    if ($r && $row = $r->fetch_assoc()) {
        $stats['score'] = (float) $row['overall_score'];
    } else {
        // Fallback calculation using publications data
        $r = @$conn->query("SELECT COUNT(*) as total FROM publications");
        $total_docs = 0;
        $public_docs = 0;
        if ($r && $row = $r->fetch_assoc()) {
            $total_docs = (int) $row['total'];
        }
        $r = @$conn->query("SELECT COUNT(*) as public FROM publications WHERE is_published = 1");
        if ($r && $row = $r->fetch_assoc()) {
            $public_docs = (int) $row['public'];
        }
        if ($total_docs > 0) {
            $stats['score'] = round(($public_docs / $total_docs) * 100, 1);
        }
    }
    
    return $stats;
}

// Function to get budget data
function getBudgetData() {
    global $conn;
    $budget = [
        'annual_budget' => 0,
        'allocation_percentage' => 0,
        'spent_amount' => 0,
        'remaining_amount' => 0
    ];
    
    if ($conn) {
        // Get current year budget data
        $current_year = date('Y');
        $result = @$conn->query("SELECT SUM(allocated_amount) as total_allocated, SUM(spent_amount) as total_spent FROM budget_allocation WHERE fiscal_year = '$current_year'");
        if ($result && $row = $result->fetch_assoc()) {
            $budget['annual_budget'] = (float) ($row['total_allocated'] ?? 0);
            $budget['spent_amount'] = (float) ($row['total_spent'] ?? 0);
            $budget['remaining_amount'] = $budget['annual_budget'] - $budget['spent_amount'];
            $budget['allocation_percentage'] = $budget['annual_budget'] > 0 ? round(($budget['spent_amount'] / $budget['annual_budget']) * 100, 1) : 0;
        }
        
        // If no current year data, get all data
        if ($budget['annual_budget'] == 0) {
            $result = @$conn->query("SELECT SUM(allocated_amount) as total_allocated, SUM(spent_amount) as total_spent FROM budget_allocation");
            if ($result && $row = $result->fetch_assoc()) {
                $budget['annual_budget'] = (float) ($row['total_allocated'] ?? 0);
                $budget['spent_amount'] = (float) ($row['total_spent'] ?? 0);
                $budget['remaining_amount'] = $budget['annual_budget'] - $budget['spent_amount'];
                $budget['allocation_percentage'] = $budget['annual_budget'] > 0 ? round(($budget['spent_amount'] / $budget['annual_budget']) * 100, 1) : 0;
            }
        }
    }
    
    return $budget;
}

// Function to get projects data
function getProjectsData() {
    global $conn;
    $projects = [];
    
    if ($conn) {
        $result = @$conn->query("SELECT * FROM infrastructure_projects ORDER BY created_at DESC");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                // Map database fields to expected format
                $projects[] = [
                    'id' => (int) $row['id'],
                    'name' => $row['project_name'],
                    'location' => $row['location'] ?? 'Not specified',
                    'budget' => (float) ($row['estimated_cost'] ?? 0),
                    'progress' => (float) ($row['progress_percentage'] ?? 0),
                    'status' => $row['status'] == 'ongoing' ? 'active' : $row['status'],
                    'project_code' => $row['project_code'],
                    'description' => $row['description'] ?? '',
                    'department' => $row['department'] ?? '',
                    'start_date' => $row['start_date'],
                    'completion_date' => $row['completion_date'],
                    'contractor' => $row['contractor'] ?? '',
                    'actual_cost' => (float) ($row['actual_cost'] ?? 0)
                ];
            }
        }
    }
    
    return $projects;
}

// Function to get performance metrics from database
function getPerformanceMetrics() {
    global $conn;
    $metrics = [
        'service_delivery' => 0,
        'citizen_rating' => 0,
        'response_time' => 0,
        'efficiency_score' => 0,
        'department_performance' => []
    ];
    
    if ($conn) {
        // Calculate service delivery based on completed vs total reports
        $transport_result = @$conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed FROM road_transportation_reports");
        $maintenance_result = @$conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed FROM road_maintenance_reports");
        
        $total_reports = 0;
        $completed_reports = 0;
        
        if ($transport_result && $row = $transport_result->fetch_assoc()) {
            $total_reports += (int) $row['total'];
            $completed_reports += (int) $row['completed'];
        }
        
        if ($maintenance_result && $row = $maintenance_result->fetch_assoc()) {
            $total_reports += (int) $row['total'];
            $completed_reports += (int) $row['completed'];
        }
        
        $metrics['service_delivery'] = $total_reports > 0 ? round(($completed_reports / $total_reports) * 100) : 0;
        
        // Calculate efficiency based on project progress
        $project_result = @$conn->query("SELECT AVG(progress_percentage) as avg_progress FROM infrastructure_projects WHERE status IN ('ongoing', 'active')");
        if ($project_result && $row = $project_result->fetch_assoc()) {
            $metrics['efficiency_score'] = round((float) ($row['avg_progress'] ?? 0));
        }
        
        // Default values for citizen rating and response time (would need separate tables for real data)
        $metrics['citizen_rating'] = 4.6;
        $metrics['response_time'] = 2.3;
        
        // Get department performance based on project completion rates
        $dept_result = @$conn->query("SELECT department, COUNT(*) as total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed FROM infrastructure_projects GROUP BY department");
        if ($dept_result && $dept_result->num_rows > 0) {
            while ($row = $dept_result->fetch_assoc()) {
                $total = (int) $row['total'];
                $completed = (int) $row['completed'];
                $score = $total > 0 ? round(($completed / $total) * 100) : 0;
                
                $metrics['department_performance'][] = [
                    'department' => $row['department'],
                    'score' => $score,
                    'rating' => 4.0 + ($score / 100), // Simulated rating
                    'projects_completed' => $completed,
                    'trend' => $score >= 80 ? 'up' : ($score >= 60 ? 'stable' : 'down')
                ];
            }
        }
    }
    
    return $metrics;
}

// Function to get publications (from new publications table)
function getPublications() {
    global $conn;
    $publications = [];
    if ($conn) {
        $result = @$conn->query("SELECT * FROM publications WHERE is_published = 1 ORDER BY publish_date DESC LIMIT 10");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $publications[] = [
                    'id' => (int) $row['id'],
                    'publication_id' => $row['publication_id'],
                    'title' => $row['title'],
                    'description' => $row['description'],
                    'publication_type' => $row['publication_type'],
                    'category' => $row['category'],
                    'department' => $row['department'],
                    'author' => $row['author'],
                    'publish_date' => $row['publish_date'],
                    'file_path' => $row['file_path'],
                    'file_name' => $row['file_name'],
                    'view_count' => (int) $row['view_count'],
                    'download_count' => (int) $row['download_count'],
                    'is_featured' => (bool) $row['is_featured']
                ];
            }
        }
    }
    return $publications;
}

// Handle form submissions
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
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

            case 'remove_published_project':
                // Remove (unpublish) a project from public view – staff only
                if (isset($_POST['id']) && $conn) {
                    $id = (int) $_POST['id'];
                    if ($id > 0) {
                        $stmt = $conn->prepare("DELETE FROM published_completed_projects WHERE id = ?");
                        if ($stmt) {
                            $stmt->bind_param("i", $id);
                            $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
                header('Location: ../transparency/public_transparency.php');
                exit;
        }
    }
}

// Function to log document download
function logDownload($doc_id) {
    global $conn;
    if (!$conn) return;
    $stmt = @$conn->prepare("INSERT INTO document_downloads (document_id, download_date, ip_address) VALUES (?, NOW(), ?)");
    if ($stmt) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $stmt->bind_param("is", $doc_id, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

// Get data for the page
$stats = getTransparencyStats();
$budget = getBudgetData();
$projects = getProjectsData();
$performance = getPerformanceMetrics();
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
    <link rel="stylesheet" href="../../css/public_transparency.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../../js/public_transparency.js"></script>
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
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <iframe src="../../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0"
            name="sidebar-frame"
            scrolling="yes">
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
                    <a href="../../public_transparency_view.php" class="btn-action" target="_blank" rel="noopener">
                        <i class="fas fa-globe"></i>
                        Public View
                    </a>
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
                        <div class="info-stat-number"><?php echo count(array_filter($projects, fn($p) => $p['status'] == 'active' || $p['status'] == 'ongoing')); ?></div>
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
                        <div class="info-stat-number"><?php echo $performance['service_delivery']; ?>%</div>
                        <div class="info-stat-label">Service Delivery</div>
                    </div>
                    <div class="info-stat">
                        <div class="info-stat-number"><?php echo $performance['citizen_rating']; ?></div>
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

        <!-- Completed Projects (published from Verification & Monitoring) – scrolling feed -->
        <?php
        $published_projects = [];
        if ($conn) {
            @$conn->query("CREATE TABLE IF NOT EXISTS published_completed_projects (
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
            $res = @$conn->query("SELECT id, title, description, location, completed_date, cost, completed_by, photo, created_at FROM published_completed_projects ORDER BY created_at DESC");
            if ($res && $res->num_rows > 0) {
                while ($row = $res->fetch_assoc()) {
                    $published_projects[] = [
                        'id' => (int) $row['id'],
                        'title' => $row['title'],
                        'description' => $row['description'] ?? '',
                        'location' => $row['location'] ?? '',
                        'completed_date' => $row['completed_date'] ? date('Y-m-d', strtotime($row['completed_date'])) : '',
                        'cost' => (float) ($row['cost'] ?? 0),
                        'completed_by' => $row['completed_by'] ?? '',
                        'photo' => !empty($row['photo']) ? $row['photo'] : null,
                    ];
                }
            }
        }
        ?>

        <!-- Recent Publications – card-style feed (isolated from other publication styles) -->
        <div class="publications-section publications-feed-section">
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

            <div class="publication-feed-list" role="feed" aria-label="Publications">
                <?php if (empty($publications)): ?>
                <div class="publication-feed-empty">
                    <p>No publications yet. Published documents and reports will appear here.</p>
                </div>
                <?php else: ?>
                <?php foreach ($publications as $pub): ?>
                <article class="publication-feed-card">
                    <div class="publication-feed-card__image">
                        <?php if (!empty($pub['file_path'])): ?>
                            <div class="publication-feed-card__file">
                                <i class="fas fa-file-pdf"></i>
                                <span>Document</span>
                            </div>
                        <?php else: ?>
                            <div class="publication-feed-card__placeholder">
                                <i class="fas fa-newspaper"></i>
                                <span>Publication</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="publication-feed-card__body">
                        <h4 class="publication-feed-card__title"><?php echo htmlspecialchars($pub['title']); ?></h4>
                        <div class="publication-feed-card__meta">
                            <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($pub['department'] ?: '—'); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo !empty($pub['publish_date']) ? date('Y-m-d', strtotime($pub['publish_date'])) : '—'; ?></span>
                            <?php if (!empty($pub['category'])): ?>
                            <span><i class="fas fa-tag"></i> <?php echo htmlspecialchars($pub['category']); ?></span>
                            <?php endif; ?>
                        </div>
                        <p class="publication-feed-card__desc"><?php echo nl2br(htmlspecialchars(substr($pub['description'], 0, 200) . (strlen($pub['description']) > 200 ? '...' : ''))); ?></p>
                        <div class="publication-feed-card__footer">
                            <div class="publication-feed-card__stats">
                                <span><i class="fas fa-eye"></i> <?php echo number_format($pub['view_count']); ?> views</span>
                                <span><i class="fas fa-download"></i> <?php echo number_format($pub['download_count']); ?> downloads</span>
                            </div>
                            <div class="publication-feed-card__type">
                                <span class="pub-type-badge pub-type-<?php echo $pub['publication_type']; ?>"><?php echo ucfirst(str_replace('_', ' ', $pub['publication_type'])); ?></span>
                            </div>
                            <?php if (!empty($pub['file_path'])): ?>
                            <div class="publication-feed-card__actions">
                                <a href="../../../<?php echo htmlspecialchars($pub['file_path']); ?>" class="btn-download" target="_blank">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php endif; ?>
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
                                <div class="modal-card-value">₱<?php echo number_format($budget['annual_budget'], 0); ?></div>
                                <div class="modal-card-desc">Total allocated for <?php echo date('Y'); ?></div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Spent</div>
                                <div class="modal-card-value">₱<?php echo number_format($budget['spent_amount'], 0); ?></div>
                                <div class="modal-card-desc"><?php echo $budget['allocation_percentage']; ?>% of total budget</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Remaining</div>
                                <div class="modal-card-value">₱<?php echo number_format($budget['remaining_amount'], 0); ?></div>
                                <div class="modal-card-desc"><?php echo (100 - $budget['allocation_percentage']); ?>% available</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Allocation Rate</div>
                                <div class="modal-card-value"><?php echo $budget['allocation_percentage']; ?>%</div>
                                <div class="modal-card-desc">Budget utilization</div>
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
                                    <?php
                                    if ($conn) {
                                        $dept_result = @$conn->query("SELECT department, SUM(allocated_amount) as allocated, SUM(spent_amount) as spent FROM budget_allocation GROUP BY department ORDER BY allocated DESC");
                                        if ($dept_result && $dept_result->num_rows > 0) {
                                            while ($dept_row = $dept_result->fetch_assoc()) {
                                                $allocated = (float) $dept_row['allocated'];
                                                $spent = (float) $dept_row['spent'];
                                                $remaining = $allocated - $spent;
                                                $percentage = $allocated > 0 ? ($spent / $allocated) * 100 : 0;
                                                $status = $percentage >= 80 ? 'active' : 'pending';
                                                echo "<tr>
                                                    <td>" . htmlspecialchars($dept_row['department']) . "</td>
                                                    <td>₱" . number_format($allocated, 0) . "</td>
                                                    <td>₱" . number_format($spent, 0) . "</td>
                                                    <td>₱" . number_format($remaining, 0) . "</td>
                                                    <td><span class='status-badge status-{$status}'>" . ucfirst($status) . "</span></td>
                                                </tr>";
                                            }
                                        }
                                    } else {
                                        // Fallback if no database connection
                                        echo "<tr><td colspan='5'>No budget data available</td></tr>";
                                    }
                                    ?>
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
                                <div class="modal-card-value"><?php echo count(array_filter($projects, fn($p) => $p['status'] == 'active' || $p['status'] == 'ongoing')); ?></div>
                                <div class="modal-card-desc">Currently in progress</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Completed</div>
                                <div class="modal-card-value"><?php echo count(array_filter($projects, fn($p) => $p['status'] == 'completed')); ?></div>
                                <div class="modal-card-desc">Successfully finished</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">On Schedule</div>
                                <div class="modal-card-value"><?php 
                                    $on_schedule = count(array_filter($projects, fn($p) => $p['progress'] >= 80));
                                    $total_active = count(array_filter($projects, fn($p) => $p['status'] == 'active' || $p['status'] == 'ongoing'));
                                    echo $total_active > 0 ? round(($on_schedule / $total_active) * 100) : 0;
                                ?>%</div>
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
                                    $active_projects = array_filter($projects, fn($p) => $p['status'] == 'active' || $p['status'] == 'ongoing');
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
                                    <?php if (empty($active_projects)): ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; padding: 20px;">No active projects found</td>
                                    </tr>
                                    <?php endif; ?>
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
                                <div class="modal-card-value"><?php echo $performance['service_delivery']; ?>%</div>
                                <div class="modal-card-desc"><?php echo $performance['service_delivery'] >= 80 ? 'Excellent' : ($performance['service_delivery'] >= 60 ? 'Good' : 'Needs Improvement'); ?> performance</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Citizen Rating</div>
                                <div class="modal-card-value"><?php echo $performance['citizen_rating']; ?>/5.0</div>
                                <div class="modal-card-desc"><?php echo $performance['citizen_rating'] >= 4.5 ? 'High satisfaction' : 'Moderate satisfaction'; ?></div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Response Time</div>
                                <div class="modal-card-value"><?php echo $performance['response_time']; ?> hrs</div>
                                <div class="modal-card-desc">Average response</div>
                            </div>
                            <div class="modal-card">
                                <div class="modal-card-title">Efficiency Score</div>
                                <div class="modal-card-value"><?php echo $performance['efficiency_score']; ?>%</div>
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
                                    <?php if (!empty($performance['department_performance'])): ?>
                                        <?php foreach ($performance['department_performance'] as $dept): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($dept['department']); ?></td>
                                                <td>
                                                    <div class="progress-bar">
                                                        <div class="progress-fill" style="width: <?php echo $dept['score']; ?>%;"></div>
                                                    </div>
                                                    <?php echo $dept['score']; ?>%
                                                </td>
                                                <td><?php echo number_format($dept['rating'], 1); ?>/5.0</td>
                                                <td><?php echo $dept['projects_completed']; ?></td>
                                                <td>
                                                    <?php 
                                                    $trend_color = $dept['trend'] == 'up' ? '#28a745' : ($dept['trend'] == 'stable' ? '#ffc107' : '#dc3545');
                                                    $trend_symbol = $dept['trend'] == 'up' ? '↑' : ($dept['trend'] == 'stable' ? '→' : '↓');
                                                    $trend_text = $dept['trend'] == 'up' ? 'Improving' : ($dept['trend'] == 'stable' ? 'Stable' : 'Declining');
                                                    ?>
                                                    <span style="color: <?php echo $trend_color; ?>;"><?php echo $trend_symbol; ?> <?php echo $trend_text; ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" style="text-align: center; padding: 20px;">No department performance data available</td>
                                        </tr>
                                    <?php endif; ?>
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
</body>
</html>

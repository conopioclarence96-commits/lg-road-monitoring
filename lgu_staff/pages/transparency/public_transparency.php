<?php
require_once __DIR__ . '/../includes/config.php';

// Default stats when transparency tables are missing
function getDefaultTransparencyStats() {
    return [
        'documents' => 156,
        'views' => 2847,
        'downloads' => 423,
        'score' => 98.5
    ];
}

// Function to get transparency statistics (uses project DB: lg_road_monitoring)
function getTransparencyStats() {
    global $conn;
    $stats = getDefaultTransparencyStats();
    
    if (!$conn) {
        return $stats;
    }
    
    $r = @$conn->query("SELECT COUNT(*) as count FROM public_documents");
    if ($r && $row = $r->fetch_assoc()) {
        $stats['documents'] = (int) $row['count'];
    }
    
    $r = @$conn->query("SELECT SUM(views) as total FROM document_views");
    if ($r && $row = $r->fetch_assoc() && isset($row['total']) && $row['total'] !== null) {
        $stats['views'] = (int) $row['total'];
    }
    
    $r = @$conn->query("SELECT COUNT(*) as count FROM document_downloads");
    if ($r && $row = $r->fetch_assoc()) {
        $stats['downloads'] = (int) $row['count'];
    }
    
    $r = @$conn->query("SELECT COUNT(*) as total FROM documents");
    $total_docs = 0;
    $public_docs = 0;
    if ($r && $row = $r->fetch_assoc()) {
        $total_docs = (int) $row['total'];
    }
    $r = @$conn->query("SELECT COUNT(*) as public FROM documents WHERE is_published = 1");
    if ($r && $row = $r->fetch_assoc()) {
        $public_docs = (int) $row['public'];
    }
    if ($total_docs > 0) {
        $stats['score'] = round(($public_docs / $total_docs) * 100, 1);
    }
    
    return $stats;
}

// Function to get budget data
function getBudgetData() {
    global $conn;
    $budget = [
        'annual_budget' => 125000000,
        'allocation_percentage' => 89
    ];
    
    if ($conn) {
        $result = @$conn->query("SELECT * FROM budget_allocation WHERE year = YEAR(CURRENT_DATE)");
        if ($result && $result->num_rows > 0) {
            $budget = array_merge($budget, $result->fetch_assoc());
        }
    }
    
    return $budget;
}

// Function to get projects data
function getProjectsData() {
    global $conn;
    $projects = [
        ['id' => 1, 'name' => 'Main Street Rehabilitation', 'location' => 'Downtown District', 'budget' => 8500000, 'progress' => 75, 'status' => 'active'],
        ['id' => 2, 'name' => 'Highway 101 Expansion', 'location' => 'North Corridor', 'budget' => 12000000, 'progress' => 45, 'status' => 'active'],
        ['id' => 3, 'name' => 'Bridge Repair Project', 'location' => 'River Crossing', 'budget' => 5200000, 'progress' => 90, 'status' => 'active'],
        ['id' => 4, 'name' => 'Street Lighting Upgrade', 'location' => 'Residential Areas', 'budget' => 3800000, 'progress' => 30, 'status' => 'delayed'],
        ['id' => 5, 'name' => 'Drainage System Installation', 'location' => 'Flood-prone Areas', 'budget' => 7100000, 'progress' => 60, 'status' => 'active'],
        ['id' => 6, 'name' => 'Park Avenue Reconstruction', 'location' => 'Central District', 'budget' => 4500000, 'progress' => 100, 'status' => 'completed'],
        ['id' => 7, 'name' => 'Sidewalk Improvement Project', 'location' => 'Suburban Areas', 'budget' => 2300000, 'progress' => 100, 'status' => 'completed']
    ];
    
    if ($conn) {
        $result = @$conn->query("SELECT * FROM infrastructure_projects ORDER BY start_date DESC");
        if ($result && $result->num_rows > 0) {
            $projects = [];
            while ($row = $result->fetch_assoc()) {
                $projects[] = $row;
            }
        }
    }
    
    return $projects;
}

// Function to get publications (from publications table if it exists; no dummy data)
function getPublications() {
    global $conn;
    $publications = [];
    if ($conn) {
        $result = @$conn->query("SELECT * FROM publications ORDER BY publish_date DESC LIMIT 10");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $publications[] = $row;
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
    <link rel="stylesheet" href="../css/public_transparency.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="../js/public_transparency.js"></script>
</head>
<body>
    <!-- SIDEBAR -->
    <iframe src="../includes/sidebar.php" 
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

            <div class="publication-feed-list" role="feed" aria-label="Published projects">
                <?php if (empty($published_projects)): ?>
                <div class="publication-feed-empty">
                    <p>No publications yet. Completed projects published from Verification &amp; Monitoring will appear here.</p>
                </div>
                <?php else: ?>
                <?php foreach ($published_projects as $proj): ?>
                <article class="publication-feed-card">
                    <div class="publication-feed-card__image">
                        <?php if (!empty($proj['photo'])): ?>
                            <img src="../../../<?php echo htmlspecialchars($proj['photo']); ?>" alt="<?php echo htmlspecialchars($proj['title']); ?>">
                        <?php else: ?>
                            <div class="publication-feed-card__placeholder">
                                <i class="fas fa-road"></i>
                                <span>Project photo</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="publication-feed-card__body">
                        <h4 class="publication-feed-card__title"><?php echo htmlspecialchars($proj['title']); ?></h4>
                        <div class="publication-feed-card__meta">
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($proj['location'] ?: '—'); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo !empty($proj['completed_date']) ? date('Y-m-d', strtotime($proj['completed_date'])) : '—'; ?></span>
                        </div>
                        <p class="publication-feed-card__desc"><?php echo nl2br(htmlspecialchars($proj['description'])); ?></p>
                        <div class="publication-feed-card__footer">
                            <div class="publication-feed-card__cost"><strong>Cost:</strong> ₱<?php echo number_format($proj['cost'], 0); ?></div>
                            <div class="publication-feed-card__by"><strong>Completed by:</strong> <?php echo htmlspecialchars($proj['completed_by'] ?: '—'); ?></div>
                            <?php if (!empty($proj['id'])): ?>
                            <form method="post" class="publication-feed-card__remove" onsubmit="return confirm('Remove this project from public view?');">
                                <input type="hidden" name="action" value="remove_published_project">
                                <input type="hidden" name="id" value="<?php echo (int) $proj['id']; ?>">
                                <button type="submit" class="btn-remove-publication">
                                    <i class="fas fa-times-circle"></i> Remove from publications
                                </button>
                            </form>
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
</body>
</html>

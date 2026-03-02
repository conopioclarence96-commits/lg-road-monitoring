<?php
/**
 * Public Transparency view – no login. Same data as staff page, blurred cityhall background.
 */
require_once __DIR__ . '/lgu_staff/includes/config.php';

// Import all the functions from the staff page
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
    
    try {
        // Check if publications table exists
        $table_check = @$conn->query("SHOW TABLES LIKE 'publications'");
        if ($table_check && $table_check->num_rows > 0) {
            // Get publications count from publications table
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
        }
        
        // Check if transparency_scores table exists
        $score_check = @$conn->query("SHOW TABLES LIKE 'transparency_scores'");
        if ($score_check && $score_check->num_rows > 0) {
            // Calculate transparency score from transparency_scores table
            $r = @$conn->query("SELECT overall_score FROM transparency_scores ORDER BY score_date DESC LIMIT 1");
            if ($r && $row = $r->fetch_assoc()) {
                $stats['score'] = (float) $row['overall_score'];
            }
        }
        
    } catch (Exception $e) {
        // Log error but continue with default stats
        error_log("Transparency stats error: " . $e->getMessage());
    }
    
    return $stats;
}

// Function to get budget data
function getBudgetData() {
    global $conn;
    $budget = [
        'annual_budget' => 125000000, // Default fallback values
        'allocation_percentage' => 89,
        'spent_amount' => 111250000,
        'remaining_amount' => 13750000
    ];
    
    if ($conn) {
        try {
            // Check if budget_allocation table exists
            $table_check = @$conn->query("SHOW TABLES LIKE 'budget_allocation'");
            if ($table_check && $table_check->num_rows > 0) {
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
        } catch (Exception $e) {
            // Log error but continue with default budget data
            error_log("Budget data error: " . $e->getMessage());
        }
    }
    
    return $budget;
}

// Function to get projects data
function getProjectsData() {
    global $conn;
    $projects = [];
    
    if ($conn) {
        try {
            // Check if infrastructure_projects table exists
            $table_check = @$conn->query("SHOW TABLES LIKE 'infrastructure_projects'");
            if ($table_check && $table_check->num_rows > 0) {
                $result = @$conn->query("SELECT * FROM infrastructure_projects ORDER BY created_at DESC LIMIT 10");
                if ($result && $result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        // Map database fields to expected format
                        $projects[] = [
                            'id' => (int) $row['id'],
                            'name' => $row['project_name'] ?? 'Sample Project',
                            'location' => $row['location'] ?? 'Not specified',
                            'budget' => (float) ($row['estimated_cost'] ?? 1000000),
                            'progress' => (float) ($row['progress_percentage'] ?? 50),
                            'status' => $row['status'] == 'ongoing' ? 'active' : ($row['status'] ?? 'active'),
                            'project_code' => $row['project_code'] ?? 'PROJ-001',
                            'description' => $row['description'] ?? 'Sample infrastructure project description',
                            'department' => $row['department'] ?? 'Road and Transportation',
                            'start_date' => $row['start_date'] ?? date('Y-m-d'),
                            'completion_date' => $row['completion_date'] ?? date('Y-m-d', strtotime('+6 months')),
                            'contractor' => $row['contractor'] ?? 'Sample Contractor',
                            'actual_cost' => (float) ($row['actual_cost'] ?? 500000)
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            // Log error but continue with empty projects array
            error_log("Projects data error: " . $e->getMessage());
        }
    }
    
    return $projects;
}

// Function to get performance metrics from database
function getPerformanceMetrics() {
    global $conn;
    $metrics = [
        'service_delivery' => 85, // Default fallback values
        'citizen_rating' => 4.6,
        'response_time' => 2.3,
        'efficiency_score' => 78,
        'department_performance' => []
    ];
    
    if ($conn) {
        try {
            $total_reports = 0;
            $completed_reports = 0;
            
            // Check if road_transportation_reports table exists
            $transport_check = @$conn->query("SHOW TABLES LIKE 'road_transportation_reports'");
            if ($transport_check && $transport_check->num_rows > 0) {
                $transport_result = @$conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed FROM road_transportation_reports");
                if ($transport_result && $row = $transport_result->fetch_assoc()) {
                    $total_reports += (int) $row['total'];
                    $completed_reports += (int) $row['completed'];
                }
            }
            
            $metrics['service_delivery'] = $total_reports > 0 ? round(($completed_reports / $total_reports) * 100) : 85;
            
            // Check if infrastructure_projects table exists for efficiency calculation
            $project_check = @$conn->query("SHOW TABLES LIKE 'infrastructure_projects'");
            if ($project_check && $project_check->num_rows > 0) {
                $project_result = @$conn->query("SELECT AVG(progress_percentage) as avg_progress FROM infrastructure_projects WHERE status IN ('ongoing', 'active')");
                if ($project_result && $row = $project_result->fetch_assoc()) {
                    $metrics['efficiency_score'] = round((float) ($row['avg_progress'] ?? 78));
                }
            }
        } catch (Exception $e) {
            // Log error but continue with default metrics
            error_log("Performance metrics error: " . $e->getMessage());
        }
    }
    
    return $metrics;
}

// Function to get publications (from new publications table)
function getPublications() {
    global $conn;
    $publications = [];
    
    if ($conn) {
        try {
            // Check if publications table exists
            $table_check = @$conn->query("SHOW TABLES LIKE 'publications'");
            if ($table_check && $table_check->num_rows > 0) {
                $result = @$conn->query("SELECT * FROM publications WHERE is_published = 1 ORDER BY publish_date DESC LIMIT 10");
                if ($result && $result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        $publications[] = [
                            'id' => (int) $row['id'],
                            'publication_id' => $row['publication_id'] ?? 'PUB-' . str_pad($row['id'], 4, '0', STR_PAD_LEFT),
                            'title' => $row['title'] ?? 'Sample Publication',
                            'description' => $row['description'] ?? 'Sample publication description for demonstration purposes.',
                            'publication_type' => $row['publication_type'] ?? 'report',
                            'category' => $row['category'] ?? 'General',
                            'department' => $row['department'] ?? 'Road and Transportation',
                            'author' => $row['author'] ?? 'LGU Staff',
                            'publish_date' => $row['publish_date'] ?? date('Y-m-d'),
                            'file_path' => $row['file_path'] ?? '',
                            'file_name' => $row['file_name'] ?? '',
                            'view_count' => (int) ($row['view_count'] ?? rand(100, 1000)),
                            'download_count' => (int) ($row['download_count'] ?? rand(50, 500)),
                            'is_featured' => (bool) ($row['is_featured'] ?? false)
                        ];
                    }
                }
            }
        } catch (Exception $e) {
            // Log error but continue with sample publications
            error_log("Publications error: " . $e->getMessage());
        }
    }
    
    return $publications;
}

// Get all data
$stats = getTransparencyStats();
$budget = getBudgetData();
$projects = getProjectsData();
$metrics = getPerformanceMetrics();
$publications = getPublications();

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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Transparency | LGU</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="lgu_staff/css/public_transparency.css">
    <style>
        /* Public view: no sidebar, full-width content */
        body { margin: 0; }
        .main-content { margin-left: 0 !important; padding: 24px 20px 48px !important; max-width: 100%; }
        .public-view-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            padding: 20px 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }
        .public-view-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: #1e3c72;
            margin: 0;
        }
        .public-view-header a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
        }
        .public-view-header a:hover { opacity: 0.95; color: #fff; }
    </style>
</head>
<body>
    <div class="main-content">
        <div class="public-view-header">
            <h1><i class="fas fa-university"></i> Public Transparency</h1>
            <a href="index.php"><i class="fas fa-arrow-left"></i> Back to Home</a>
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
                        <div class="info-stat-value">₱<?php echo number_format($budget['annual_budget'], 0); ?></div>
                        <div class="info-stat-label">Annual Budget</div>
                    </div>
                    <div class="info-stat">
                        <div class="info-stat-value"><?php echo $budget['allocation_percentage']; ?>%</div>
                        <div class="info-stat-label">Allocated</div>
                    </div>
                </div>
            </div>

            <!-- Project Status -->
            <div class="info-card">
                <div class="info-header">
                    <div class="info-icon">
                        <i class="fas fa-hard-hat"></i>
                    </div>
                    <div class="info-title">Infrastructure Projects</div>
                </div>
                <div class="info-description">
                    Track ongoing and completed road construction, maintenance, and infrastructure improvement projects across the city.
                </div>
                <div class="info-stats">
                    <div class="info-stat">
                        <div class="info-stat-value"><?php echo count($projects); ?></div>
                        <div class="info-stat-label">Active Projects</div>
                    </div>
                    <div class="info-stat">
                        <div class="info-stat-value"><?php echo $metrics['efficiency_score']; ?>%</div>
                        <div class="info-stat-label">Efficiency Rate</div>
                    </div>
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
                    Service delivery ratings, response times, and overall performance indicators for road and transportation services.
                </div>
                <div class="info-stats">
                    <div class="info-stat">
                        <div class="info-stat-value"><?php echo $metrics['service_delivery']; ?>%</div>
                        <div class="info-stat-label">Service Delivery</div>
                    </div>
                    <div class="info-stat">
                        <div class="info-stat-value"><?php echo $metrics['citizen_rating']; ?></div>
                        <div class="info-stat-label">Citizen Rating</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Publications -->
        <div class="publications-section publications-feed-section">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-newspaper"></i>
                    Recent Publications
                </h3>
            </div>

            <div class="publication-feed-list" role="feed" aria-label="Published projects">
                <?php if (empty($publications)): ?>
                <div class="publication-feed-empty">
                    <p>No publications yet. Documents published by the LGU will appear here.</p>
                </div>
                <?php else: ?>
                <?php foreach ($publications as $pub): ?>
                <article class="publication-feed-card">
                    <div class="publication-feed-card__body">
                        <h4 class="publication-feed-card__title"><?php echo htmlspecialchars($pub['title']); ?></h4>
                        <div class="publication-feed-card__meta">
                            <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($pub['category']); ?></span>
                            <span><i class="fas fa-building"></i> <?php echo htmlspecialchars($pub['department']); ?></span>
                            <span><i class="fas fa-calendar"></i> <?php echo date('Y-m-d', strtotime($pub['publish_date'])); ?></span>
                        </div>
                        <p class="publication-feed-card__desc"><?php echo nl2br(htmlspecialchars($pub['description'])); ?></p>
                        <div class="publication-feed-card__stats">
                            <span><i class="fas fa-eye"></i> <?php echo number_format($pub['view_count']); ?> views</span>
                            <span><i class="fas fa-download"></i> <?php echo number_format($pub['download_count']); ?> downloads</span>
                        </div>
                        <?php if (!empty($pub['file_path'])): ?>
                        <div class="publication-feed-card__actions">
                            <a href="<?php echo htmlspecialchars($pub['file_path']); ?>" class="btn-download" target="_blank">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Completed Projects -->
        <div class="publications-section publications-feed-section">
            <div class="section-header">
                <h3 class="section-title">
                    <i class="fas fa-road"></i>
                    Completed Projects
                </h3>
            </div>

            <div class="publication-feed-list" role="feed" aria-label="Completed projects">
                <?php if (empty($published_projects)): ?>
                <div class="publication-feed-empty">
                    <p>No completed projects yet. Finished infrastructure projects will appear here.</p>
                </div>
                <?php else: ?>
                <?php foreach ($published_projects as $proj): ?>
                <article class="publication-feed-card">
                    <div class="publication-feed-card__image">
                        <?php if (!empty($proj['photo'])): ?>
                            <img src="<?php echo htmlspecialchars(ltrim(str_replace(['../', '..\\'], '', $proj['photo']), '/\\')); ?>" alt="<?php echo htmlspecialchars($proj['title']); ?>">
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
                        </div>
                    </div>
                </article>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

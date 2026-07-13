<?php
require_once 'lgu_staff/includes/config.php';
require_once 'lgu_staff/includes/functions.php';

$status_filter = isset($_GET['status']) ? sanitize_input($_GET['status']) : 'all';
$type_filter = isset($_GET['type']) ? sanitize_input($_GET['type']) : 'all';
$focus_report_id = isset($_GET['report_id']) ? intval($_GET['report_id']) : 0;

$transport_reports = [];
$maintenance_reports = [];
$stats = ['total_reports' => 0, 'problem_roads' => 0, 'under_construction' => 0, 'resolved_issues' => 0];

if ($conn) {
    try {
        $transport_query = "SELECT id, report_id, title, description, location, latitude, longitude, priority, status, severity, image_path, attachments, reporter_name, reported_date, created_at, department, 'transportation' as source FROM road_transportation_reports";
        $conditions = [];
        $params = [];
        $types = '';

        if ($status_filter !== 'all') {
            $conditions[] = "status = ?";
            $params[] = $status_filter;
            $types .= "s";
        }

        if (!empty($conditions)) {
            $transport_query .= " WHERE " . implode(' AND ', $conditions);
        }
        $transport_query .= " ORDER BY created_at DESC LIMIT 50";
        $transport_reports = !empty($params) ? fetch_all($transport_query, $params, $types) : fetch_all($transport_query);

        $maintenance_query = "SELECT id, report_id, title, description, location, priority, status, created_at, department, 'maintenance' as source FROM road_maintenance_reports";
        if (!empty($conditions)) {
            $maintenance_query .= " WHERE " . implode(' AND ', $conditions);
        }
        $maintenance_query .= " ORDER BY created_at DESC LIMIT 50";
        $maintenance_reports = !empty($params) ? fetch_all($maintenance_query, $params, $types) : fetch_all($maintenance_query);

        $stats['total_reports'] = fetch_one("SELECT COUNT(*) as c FROM road_transportation_reports")['c'] + fetch_one("SELECT COUNT(*) as c FROM road_maintenance_reports")['c'];
        $stats['problem_roads'] = fetch_one("SELECT COUNT(*) as c FROM road_transportation_reports WHERE status IN ('pending','in-progress') AND priority IN ('high','critical')")['c'] ?? 0;
        $stats['under_construction'] = fetch_one("SELECT COUNT(*) as c FROM road_transportation_reports WHERE status = 'in-progress'")['c'] ?? 0;
        $stats['resolved_issues'] = fetch_one("SELECT COUNT(*) as c FROM road_transportation_reports WHERE status = 'completed'")['c'] ?? 0;
    } catch (Exception $e) {
        error_log("Public reports error: " . $e->getMessage());
    }
}

$all_reports = array_merge($transport_reports ?: [], $maintenance_reports ?: []);
usort($all_reports, function($a, $b) {
    return strtotime($b['created_at'] ?? 'now') - strtotime($a['created_at'] ?? 'now');
});

function getReportPhoto($report) {
    if (!empty($report['image_path']) && file_exists(__DIR__ . '/' . $report['image_path'])) {
        return $report['image_path'];
    }
    if (!empty($report['attachments'])) {
        $atts = json_decode($report['attachments'], true);
        if (is_array($atts)) {
            foreach ($atts as $att) {
                $path = $att['file_path'] ?? $att['file'] ?? '';
                if ($path && file_exists(__DIR__ . '/' . $path)) return $path;
            }
        }
    }
    return null;
}

function getStatusBadge($status) {
    $map = ['pending' => 'warning', 'in-progress' => 'info', 'completed' => 'success', 'cancelled' => 'secondary', 'approved' => 'success', 'rejected' => 'danger'];
    $class = $map[$status] ?? 'secondary';
    return "<span class=\"badge bg-{$class}\">" . ucfirst(str_replace('-', ' ', $status)) . "</span>";
}

function getPriorityBadge($priority) {
    $map = ['high' => 'danger', 'critical' => 'danger', 'medium' => 'warning', 'low' => 'success'];
    $class = $map[$priority] ?? 'secondary';
    return "<span class=\"badge bg-{$class}\">" . ucfirst($priority) . "</span>";
}

function getSeverityIcon($status) {
    if (in_array($status, ['in-progress', 'pending'])) {
        return '<i class="fas fa-exclamation-triangle text-danger" title="Problem Road"></i>';
    }
    if ($status === 'completed') {
        return '<i class="fas fa-check-circle text-success" title="Resolved"></i>';
    }
    return '<i class="fas fa-minus-circle text-secondary" title="' . ucfirst($status) . '"></i>';
}

function getTimeAgoShort($datetime) {
    if (!$datetime) return '';
    $diff = time() - strtotime($datetime);
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    return date('M d', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road Reports - Quezon City</title>
    <link rel="icon" type="image/png" href="assets/img/logocityhall.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="lgu_staff/css/progress-updates.css">
    <style>
        :root { --primary: #1e3c72; --primary-light: #2a5298; --accent: #4CAF50; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; color: #2c3e50; }
        
        .navbar { background: linear-gradient(135deg, var(--primary), var(--primary-light)); box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 1rem 0; }
        .navbar-brand { font-weight: 600; font-size: 1.5rem; color: white !important; display: flex; align-items: center; gap: 10px; }
        .navbar-nav .nav-link { color: white !important; font-weight: 500; margin: 0 10px; transition: color 0.3s ease; }
        .navbar-nav .nav-link:hover { color: var(--accent) !important; }

        .hero-bar {
            background: linear-gradient(135deg, rgba(30,60,114,0.95), rgba(42,82,152,0.95)), url('assets/img/cityhall.jpeg') center/cover;
            padding: 40px 0 36px; color: white; text-align: center;
        }
        .hero-bar h1 { font-size: 2rem; font-weight: 700; margin-bottom: 6px; }
        .hero-bar p { font-size: 1rem; opacity: 0.9; margin-bottom: 0; }

        .stats-ribbon { background: white; border-bottom: 1px solid #e9ecef; padding: 16px 0; }
        .stat-chip { text-align: center; }
        .stat-chip .num { font-size: 1.6rem; font-weight: 700; color: var(--primary); }
        .stat-chip .lbl { font-size: 0.8rem; color: #6c757d; font-weight: 500; }

        .filters-bar { background: white; border-radius: 12px; padding: 16px 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); margin-bottom: 20px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .filters-bar select { padding: 8px 12px; border: 1px solid #dee2e6; border-radius: 8px; font-size: 0.85rem; min-width: 150px; }
        .filters-bar .result-count { font-size: 0.85rem; color: #6c757d; margin-left: auto; }

        .report-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 18px; }
        
        .report-card {
            background: white; border-radius: 14px; overflow: hidden;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06); transition: all 0.25s ease;
            border: 1px solid #e9ecef;
        }
        .report-card:hover { transform: translateY(-4px); box-shadow: 0 8px 28px rgba(0,0,0,0.1); }

        .report-img {
            width: 100%; height: 180px; object-fit: cover;
            background: #e9ecef; display: flex; align-items: center; justify-content: center;
            color: #adb5bd; font-size: 2.5rem;
        }
        .report-img-placeholder {
            width: 100%; height: 180px; background: linear-gradient(135deg, #e9ecef, #dee2e6);
            display: flex; align-items: center; justify-content: center; color: #adb5bd; font-size: 2.5rem;
        }

        .report-body { padding: 16px 18px 18px; }
        .report-meta { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
        .report-title { font-size: 1rem; font-weight: 600; color: #1e293b; margin-bottom: 6px; line-height: 1.3; }
        .report-desc { font-size: 0.85rem; color: #64748b; margin-bottom: 10px; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .report-location { font-size: 0.8rem; color: #6c757d; margin-bottom: 10px; }
        .report-location i { margin-right: 4px; color: #dc3545; }
        .report-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid #f1f3f5; font-size: 0.8rem; color: #6c757d; }
        .report-source { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #adb5bd; }

        .road-marker { display: inline-flex; align-items: center; gap: 4px; font-size: 0.75rem; font-weight: 600; padding: 2px 8px; border-radius: 12px; }
        .road-marker.problem { background: rgba(220,53,69,0.12); color: #dc3545; }
        .road-marker.construction { background: rgba(255,193,7,0.15); color: #d97706; }
        .road-marker.resolved { background: rgba(40,167,69,0.12); color: #28a745; }

        .back-home { display: inline-flex; align-items: center; gap: 6px; color: rgba(255,255,255,0.8); text-decoration: none; font-size: 0.85rem; font-weight: 500; }
        .back-home:hover { color: white; }

        .detail-modal .modal-header { background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: white; border: none; }
        .detail-modal .modal-header .btn-close { filter: brightness(0) invert(1); }
        .detail-modal .modal-body { padding: 24px; }
        .detail-modal .info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f1f3f5; font-size: 0.9rem; }
        .detail-modal .info-row .label { color: #6c757d; font-weight: 500; }
        .detail-modal .info-row .value { font-weight: 600; color: #1e293b; }

        .no-reports { text-align: center; padding: 60px 20px; color: #6c757d; }
        .no-reports i { font-size: 3.5rem; margin-bottom: 16px; opacity: 0.3; }

        @media (max-width: 768px) {
            .report-grid { grid-template-columns: 1fr; }
            .hero-bar h1 { font-size: 1.5rem; }
        }

        @media (prefers-color-scheme: dark) {
            body { background: #1a1d23; color: #e4e6ea; }
            .stats-ribbon { background: #22262e; border-color: #2d323b; }
            .stat-chip .num { color: #93c5fd; }
            .stat-chip .lbl { color: #9ca3af; }
            .filters-bar { background: #22262e; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
            .filters-bar select { background: #1a1d23; color: #e4e6ea; border-color: #2d323b; }
            .filters-bar .result-count { color: #9ca3af; }
            .filters-bar label { color: #9ca3af !important; }
            .report-card { background: #22262e; border-color: #2d323b; box-shadow: 0 2px 12px rgba(0,0,0,0.2); }
            .report-card:hover { box-shadow: 0 8px 28px rgba(0,0,0,0.4); }
            .report-title { color: #e4e6ea; }
            .report-desc { color: #9ca3af; }
            .report-location { color: #9ca3af; }
            .report-location i { color: #fca5a5; }
            .report-footer { border-top-color: #2d323b; color: #9ca3af; }
            .report-source { color: #6b7280; }
            .report-img { background: #2d323b; color: #6b7280; }
            .report-img-placeholder { background: linear-gradient(135deg, #2d323b, #374151); color: #6b7280; }
            .road-marker.problem { background: rgba(252,165,165,0.15); color: #fca5a5; }
            .road-marker.construction { background: rgba(251,191,36,0.15); color: #fbbf24; }
            .road-marker.resolved { background: rgba(52,211,153,0.15); color: #34d399; }
            .detail-modal .modal-content { background: #22262e; }
            .detail-modal .modal-body { background: #22262e; }
            .detail-modal .info-row { border-bottom-color: #2d323b; }
            .detail-modal .info-row .label { color: #9ca3af; }
            .detail-modal .info-row .value { color: #e4e6ea; }
            #modalDescription { color: #d1d5db !important; }
            .no-reports { color: #9ca3af; }
            .modal-footer { background: #1e2229; border-top-color: #2d323b; }
        }
    </style>
    <?php include __DIR__ . '/includes/a11y_css.php'; ?>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fas fa-road"></i> Road & Transportation Department</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="road-updates.php">Road Updates</a></li>
                    <li class="nav-item"><a class="nav-link" href="public_reports.php">Road Status</a></li>
                    <li class="nav-item"><a class="nav-link" href="about.php">About</a></li>
                    <li class="nav-item"><a class="nav-link" href="contact.php">Contact</a></li>
                    <li class="nav-item"><a class="nav-link" href="public_transparency_view.php">Transparency</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="hero-bar">
        <div class="container">
            <h1><i class="fas fa-map-marked-alt"></i> Road Status & Public Reports</h1>
            <p>Transparent view of all road issues, construction projects, and completed repairs</p>
        </div>
    </div>

    <div class="stats-ribbon">
        <div class="container">
            <div class="row text-center">
                <div class="col-3 col-md-3 stat-chip">
                    <div class="num"><?php echo number_format($stats['total_reports']); ?></div>
                    <div class="lbl">Total Reports</div>
                </div>
                <div class="col-3 col-md-3 stat-chip">
                    <div class="num" style="color:#dc3545;"><?php echo number_format($stats['problem_roads']); ?></div>
                    <div class="lbl">Problem Roads</div>
                </div>
                <div class="col-3 col-md-3 stat-chip">
                    <div class="num" style="color:#d97706;"><?php echo number_format($stats['under_construction']); ?></div>
                    <div class="lbl">Under Construction</div>
                </div>
                <div class="col-3 col-md-3 stat-chip">
                    <div class="num" style="color:#28a745;"><?php echo number_format($stats['resolved_issues']); ?></div>
                    <div class="lbl">Resolved Issues</div>
                </div>
            </div>
        </div>
    </div>

    <div class="container" style="padding-top: 24px; padding-bottom: 48px;">
        <div class="filters-bar">
            <label style="font-weight: 500; font-size: 0.85rem; color: #495057;"><i class="fas fa-filter"></i> Filter:</label>
            <select id="statusFilter" onchange="applyFilters()">
                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="in-progress" <?php echo $status_filter === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
            </select>
            <select id="typeFilter" onchange="applyFilters()">
                <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                <option value="transportation" <?php echo $type_filter === 'transportation' ? 'selected' : ''; ?>>Transportation</option>
                <option value="maintenance" <?php echo $type_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
            </select>
            <span class="result-count"><i class="fas fa-list"></i> <?php echo count($all_reports); ?> report(s) found</span>
        </div>

        <?php if (empty($all_reports)): ?>
        <div class="no-reports">
            <i class="fas fa-inbox"></i>
            <h5>No Reports Found</h5>
            <p>No reports match the current filters. Try adjusting your selection.</p>
        </div>
        <?php else: ?>
        <div class="report-grid">
            <?php foreach ($all_reports as $r):
                $photo = getReportPhoto($r);
                $is_problem = in_array($r['status'] ?? '', ['pending', 'in-progress']);
                $is_construction = ($r['status'] ?? '') === 'in-progress';
                $is_resolved = ($r['status'] ?? '') === 'completed';
            ?>
            <div class="report-card" onclick="openDetail(<?php echo htmlspecialchars(json_encode([
                'title' => $r['title'] ?? 'Untitled',
                'description' => $r['description'] ?? 'No description',
                'location' => $r['location'] ?? 'Not specified',
                'status' => $r['status'] ?? 'pending',
                'priority' => $r['priority'] ?? 'medium',
                'source' => $r['source'] ?? 'transportation',
                'reported_date' => $r['reported_date'] ?? $r['created_at'] ?? '',
                'reporter' => $r['reporter_name'] ?? 'Anonymous',
                'department' => $r['department'] ?? 'Not specified',
                'severity' => $r['severity'] ?? 'Not specified',
                'has_photo' => $photo ? true : false,
                'photo_url' => $photo ?: '',
                'db_id' => $r['id'] ?? 0
            ], JSON_HEX_TAG | JSON_HEX_AMP)); ?>); return false;">
                <?php if ($photo): ?>
                <img src="<?php echo htmlspecialchars($photo); ?>" alt="Report photo" class="report-img" loading="lazy">
                <?php else: ?>
                <div class="report-img-placeholder">
                    <i class="fas fa-road"></i>
                </div>
                <?php endif; ?>
                
                <div class="report-body">
                    <div class="report-meta">
                        <?php echo getSeverityIcon($r['status'] ?? ''); ?>
                        <?php echo getStatusBadge($r['status'] ?? 'pending'); ?>
                        <?php echo getPriorityBadge($r['priority'] ?? 'medium'); ?>
                        <?php if ($is_construction): ?>
                        <span class="road-marker construction"><i class="fas fa-hard-hat"></i> Construction</span>
                        <?php elseif ($is_problem): ?>
                        <span class="road-marker problem"><i class="fas fa-exclamation-circle"></i> Problem</span>
                        <?php elseif ($is_resolved): ?>
                        <span class="road-marker resolved"><i class="fas fa-check"></i> Resolved</span>
                        <?php endif; ?>
                    </div>
                    <div class="report-title"><?php echo htmlspecialchars($r['title'] ?? 'Untitled Report'); ?></div>
                    <div class="report-desc"><?php echo htmlspecialchars($r['description'] ?? ''); ?></div>
                    <div class="report-location">
                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($r['location'] ?? 'Location not specified'); ?>
                    </div>
                    <div class="report-footer">
                        <span><i class="far fa-clock"></i> <?php echo getTimeAgoShort($r['reported_date'] ?? $r['created_at'] ?? ''); ?></span>
                        <span class="report-source"><i class="fas fa-tag"></i> <?php echo ucfirst($r['source'] ?? 'transportation'); ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="modal fade detail-modal" id="reportModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-alt"></i> <span id="modalTitle"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="modalPhoto" style="margin-bottom: 16px; display: none;">
                        <img id="modalPhotoImg" src="" alt="Report Photo" style="width:100%; max-height: 300px; object-fit: cover; border-radius: 8px;">
                    </div>
                    <div id="modalDescription" style="margin-bottom: 20px; line-height: 1.7; font-size: 0.95rem; color: #334155;"></div>
                    <div id="modalInfo"></div>
                    <div id="citizenTimeline" style="margin-top: 24px; display: none;"></div>
                </div>
                <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                    <div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="toggleTimelineBtn" onclick="toggleCitizenTimeline()" style="display:none;">
                            <i class="fas fa-clock"></i> Progress Timeline
                        </button>
                    </div>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times"></i> Close</button>
                </div>
            </div>
        </div>
    </div>

    <footer style="background: #0f2341; color: white; padding: 28px 0 18px; text-align: center;">
        <div class="container">
            <p style="margin-bottom: 6px;">&copy; 2026 Quezon City Road & Transportation Department. All rights reserved.</p>
            <p style="font-size: 0.85rem; opacity: 0.7;">Data presented is for public transparency and informational purposes only.</p>
        </div>
    </footer>

    <!-- Lightbox -->
    <div class="lightbox-overlay" id="lightboxOverlay" onclick="closeLightbox()">
        <button class="lightbox-close" onclick="closeLightbox()">&times;</button>
        <img id="lightboxImage" src="" alt="Enlarged photo">
    </div>

    <?php include __DIR__ . '/includes/a11y_html.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentData = null;

        function applyFilters() {
            const status = document.getElementById('statusFilter').value;
            const type = document.getElementById('typeFilter').value;
            const url = new URL(window.location);
            url.searchParams.set('status', status);
            url.searchParams.set('type', type);
            window.location.href = url.toString();
        }

        function openDetail(data) {
            currentData = data;
            document.getElementById('modalTitle').textContent = data.title;
            
            const descEl = document.getElementById('modalDescription');
            descEl.textContent = data.description || 'No description available.';
            
            const infoEl = document.getElementById('modalInfo');
            const statusClass = (data.status === 'pending' ? 'bg-warning' : data.status === 'in-progress' ? 'bg-info' : data.status === 'completed' ? 'bg-success' : 'bg-secondary');
            const priorityClass = (data.priority === 'high' || data.priority === 'critical') ? 'bg-danger' : data.priority === 'medium' ? 'bg-warning' : 'bg-success';
            
            infoEl.innerHTML = `
                <div class="info-row"><span class="label"><i class="fas fa-flag"></i> Status</span><span class="value"><span class="badge ${statusClass}">${data.status}</span></span></div>
                <div class="info-row"><span class="label"><i class="fas fa-exclamation-circle"></i> Priority</span><span class="value"><span class="badge ${priorityClass}">${data.priority}</span></span></div>
                <div class="info-row"><span class="label"><i class="fas fa-map-marker-alt"></i> Location</span><span class="value">${data.location}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-building"></i> Department</span><span class="value">${data.department}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-tag"></i> Type</span><span class="value">${data.source}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-clock"></i> Reported</span><span class="value">${data.reported_date || 'Not specified'}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-user"></i> Reporter</span><span class="value">${data.reporter}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-tachometer-alt"></i> Severity</span><span class="value">${data.severity}</span></div>
            `;

            if (data.has_photo) {
                document.getElementById('modalPhoto').style.display = 'block';
            } else {
                document.getElementById('modalPhoto').style.display = 'none';
            }

            const modal = new bootstrap.Modal(document.getElementById('reportModal'));
            modal.show();
        }

        document.querySelectorAll('.report-card').forEach(card => {
            card.addEventListener('click', function(e) {
                if (e.target.closest('.badge') || e.target.closest('.road-marker')) return;
            });
        });

        /* Citizen Progress Timeline */
        let citizenTimelineVisible = false;
        let citizenUpdatesLoaded = false;

        function openDetail(data) {
            currentData = data;
            document.getElementById('modalTitle').textContent = data.title;

            const descEl = document.getElementById('modalDescription');
            descEl.textContent = data.description || 'No description available.';

            const infoEl = document.getElementById('modalInfo');
            const statusClass = (data.status === 'pending' ? 'bg-warning' : data.status === 'in-progress' ? 'bg-info' : data.status === 'completed' ? 'bg-success' : 'bg-secondary');
            const priorityClass = (data.priority === 'high' || data.priority === 'critical') ? 'bg-danger' : data.priority === 'medium' ? 'bg-warning' : 'bg-success';

            infoEl.innerHTML = `
                <div class="info-row"><span class="label"><i class="fas fa-flag"></i> Status</span><span class="value"><span class="badge ${statusClass}">${data.status}</span></span></div>
                <div class="info-row"><span class="label"><i class="fas fa-exclamation-circle"></i> Priority</span><span class="value"><span class="badge ${priorityClass}">${data.priority}</span></span></div>
                <div class="info-row"><span class="label"><i class="fas fa-map-marker-alt"></i> Location</span><span class="value">${data.location}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-building"></i> Department</span><span class="value">${data.department}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-tag"></i> Type</span><span class="value">${data.source}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-clock"></i> Reported</span><span class="value">${data.reported_date || 'Not specified'}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-user"></i> Reporter</span><span class="value">${data.reporter}</span></div>
                <div class="info-row"><span class="label"><i class="fas fa-tachometer-alt"></i> Severity</span><span class="value">${data.severity}</span></div>
            `;

            if (data.photo_url) {
                document.getElementById('modalPhotoImg').src = data.photo_url;
                document.getElementById('modalPhoto').style.display = 'block';
            } else {
                document.getElementById('modalPhoto').style.display = 'none';
            }

            // Reset timeline
            citizenTimelineVisible = false;
            citizenUpdatesLoaded = false;
            document.getElementById('citizenTimeline').style.display = 'none';
            document.getElementById('citizenTimeline').innerHTML = '';
            const toggleBtn = document.getElementById('toggleTimelineBtn');
            toggleBtn.style.display = 'inline-block';
            toggleBtn.innerHTML = '<i class="fas fa-clock"></i> Progress Timeline';

            const modal = new bootstrap.Modal(document.getElementById('reportModal'));
            modal.show();
        }

        function toggleCitizenTimeline() {
            const container = document.getElementById('citizenTimeline');
            const btn = document.getElementById('toggleTimelineBtn');
            citizenTimelineVisible = !citizenTimelineVisible;

            if (citizenTimelineVisible) {
                container.style.display = 'block';
                btn.innerHTML = '<i class="fas fa-times"></i> Hide Timeline';
                if (!citizenUpdatesLoaded) {
                    loadCitizenUpdates();
                }
            } else {
                container.style.display = 'none';
                btn.innerHTML = '<i class="fas fa-clock"></i> Progress Timeline';
            }
        }

        function loadCitizenUpdates() {
            const container = document.getElementById('citizenTimeline');
            container.innerHTML = '<div style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin fa-2x" style="color:#3762c8;"></i></div>';

            const reportType = currentData.source === 'maintenance' ? 'maintenance' : 'transportation';
            fetch(`lgu_staff/pages/api/progress_update_api.php?action=get_updates&report_id=${currentData.db_id}&report_type=${reportType}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        renderCitizenTimeline(data.updates);
                    } else {
                        container.innerHTML = '<div class="timeline-empty"><i class="fas fa-exclamation-circle"></i><br>' + escapeHtml(data.message) + '</div>';
                    }
                })
                .catch(() => {
                    container.innerHTML = '<div class="timeline-empty"><i class="fas fa-exclamation-triangle"></i><br>Unable to load timeline.</div>';
                });
        }

        function openLightbox(src) {
            const overlay = document.getElementById('lightboxOverlay');
            const img = document.getElementById('lightboxImage');
            if (overlay && img) {
                img.src = src;
                overlay.classList.add('show');
            }
        }

        function closeLightbox() {
            const overlay = document.getElementById('lightboxOverlay');
            if (overlay) overlay.classList.remove('show');
        }

        function renderCitizenTimeline(updates) {
            const container = document.getElementById('citizenTimeline');
            citizenUpdatesLoaded = true;
            if (!updates || updates.length === 0) {
                container.innerHTML = '<div class="timeline-empty"><i class="fas fa-clock"></i><br>No progress updates yet.</div>';
                return;
            }
            let html = '<div class="timeline-container">';
            updates.forEach(u => {
                const mediaHtml = (u.media || []).map(m => {
                    if (m.file_type === 'video') {
                        return `<div class="timeline-media-item video-thumb" onclick="window.open('${escapeHtmlAttr(m.file_path)}','_blank')"><i class="fas fa-play-circle"></i></div>`;
                    }
                    return `<div class="timeline-media-item" onclick="openLightbox('${escapeHtmlAttr(m.file_path)}')"><img src="${escapeHtmlAttr(m.file_path)}" alt="" loading="lazy"></div>`;
                }).join('');

                html += `
                <div class="timeline-entry">
                    <div class="timeline-dot"><i class="fas fa-check"></i></div>
                    <div class="timeline-card">
                        <div class="timeline-header">
                            <div class="timeline-meta">
                                <span class="admin-badge"><i class="fas fa-user-shield"></i> LGU Staff</span>
                                <span class="time"><i class="far fa-clock"></i> ${escapeHtml(u.created_at_formatted || u.created_at)}</span>
                            </div>
                        </div>
                        ${u.title ? `<div class="timeline-title">${escapeHtml(u.title)}</div>` : ''}
                        <div class="timeline-desc">${escapeHtml(u.description)}</div>
                        ${mediaHtml ? `<div class="timeline-media">${mediaHtml}</div>` : ''}
                    </div>
                </div>`;
            });
            html += '</div>';
            container.innerHTML = html;
        }

        function escapeHtml(t) { if (!t) return ''; const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
        function escapeHtmlAttr(t) { if (!t) return ''; return t.replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }

        // Auto-open specific report from URL parameter
        <?php if ($focus_report_id > 0): ?>
        window.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.report-card');
            for (const card of cards) {
                const attr = card.getAttribute('onclick') || '';
                const m = attr.match(/"db_id"\s*:\s*(\d+)/);
                if (m && parseInt(m[1]) === <?php echo $focus_report_id; ?>) {
                    card.click();
                    break;
                }
            }
        });
        <?php endif; ?>
    </script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
</body>
</html>

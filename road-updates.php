<?php
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);
session_start();

$basePath = '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
if (strpos($scriptName, '/lgu_staff/') !== false) {
    $basePath = '../';
} elseif (strpos($scriptName, '/public/') !== false) {
    $basePath = '../';
}

$database_available = false;
$conn = null;
require_once 'lgu_staff/includes/config.php';
require_once 'lgu_staff/includes/functions.php';
$database_available = true;

$road_updates = [];
if ($database_available && $conn) {
    try {
        $stmt = $conn->prepare("DESCRIBE road_transportation_reports");
        $stmt->execute();
        $result = $stmt->get_result();
        $has_attachments = false;
        $has_title = false;
        $has_description = false;
        $has_reported_date = false;
        while ($row = $result->fetch_assoc()) {
            if ($row['Field'] === 'attachments') $has_attachments = true;
            if ($row['Field'] === 'title') $has_title = true;
            if ($row['Field'] === 'description') $has_description = true;
            if ($row['Field'] === 'reported_date') $has_reported_date = true;
        }
        $stmt->close();
        $select_fields = "id";
        if ($has_title) $select_fields .= ", title";
        if ($has_description) $select_fields .= ", description";
        if ($has_reported_date) $select_fields .= ", reported_date";
        if ($has_attachments) $select_fields .= ", attachments";
        $select_fields .= ", image_path";
        if ($has_title) $select_fields .= ", report_type, priority, status, location";
        $order_field = $has_reported_date ? "reported_date" : "created_at";
        $stmt = $conn->prepare("SELECT $select_fields FROM road_transportation_reports ORDER BY $order_field DESC LIMIT 20");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $road_updates[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        $road_updates = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road Updates - Quezon City</title>
    <link rel="icon" type="image/png" href="assets/img/logocityhall.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles/transition.css">
    <style>
        :root { --primary-color: #1e3c72; --secondary-color: #2a5298; --accent-color: #4CAF50; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; color: #2c3e50; line-height: 1.6; }
        .navbar { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 1rem 0; }
        .navbar-brand { font-weight: 600; font-size: 1.5rem; color: white !important; display: flex; align-items: center; gap: 10px; }
        .navbar-nav .nav-link { color: white !important; font-weight: 500; margin: 0 10px; transition: color 0.3s ease; }
        .navbar-nav .nav-link:hover { color: var(--accent-color) !important; }
        .hero-bar { background: linear-gradient(135deg, rgba(30,60,114,0.95), rgba(42,82,152,0.95)), url('assets/img/cityhall.jpeg') center/cover; padding: 40px 0 36px; color: white; text-align: center; }
        .hero-bar h1 { font-size: 2rem; font-weight: 700; margin-bottom: 6px; }
        .hero-bar p { font-size: 1rem; opacity: 0.9; margin-bottom: 0; }
        .section { padding: 60px 0; }
        .section-title { text-align: center; font-size: 2.2rem; font-weight: 700; color: var(--primary-color); margin-bottom: 20px; }
        .section-subtitle { text-align: center; font-size: 1.05rem; color: #666; margin-bottom: 40px; }
        .update-card { border: none; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.1); transition: transform 0.3s ease, box-shadow 0.3s ease; height: 100%; }
        .update-card:hover { transform: translateY(-5px); box-shadow: 0 10px 25px rgba(0,0,0,0.15); }
        .update-card .card-header { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; border-radius: 15px 15px 0 0 !important; font-weight: 600; }
        .update-badge { position: absolute; top: 10px; right: 10px; padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }
        .badge-maintenance { background: #ff9800; color: white; }
        .badge-advisory { background: #2196f3; color: white; }
        .badge-closure { background: #f44336; color: white; }
        footer { background: #0f2341; color: white; padding: 40px 0 20px; }
        footer a { color: rgba(255,255,255,0.8); text-decoration: none; }
        footer a:hover { color: white; }
        footer .btn-login { margin-left: 15px; vertical-align: middle; padding: 6px 18px; font-size: 0.9rem; }
        .back-home { display: inline-flex; align-items: center; gap: 6px; color: rgba(255,255,255,0.8); text-decoration: none; font-size: 0.85rem; font-weight: 500; }
        .back-home:hover { color: white; }
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
            <h1><i class="fas fa-newspaper"></i> Road Updates & Announcements</h1>
            <p>Stay informed about the latest road conditions and maintenance activities</p>
        </div>
    </div>

    <section class="section">
        <div class="container">
            <div class="row g-4">
                <?php if (!empty($road_updates)): ?>
                    <?php foreach ($road_updates as $update): ?>
                        <div class="col-md-4">
                            <div class="card update-card">
                                <div class="card-header position-relative">
                                    <?php echo htmlspecialchars($update['title'] ?? 'Road Update'); ?>
                                    <span class="update-badge badge-<?php echo strtolower($update['report_type'] ?? 'advisory'); ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $update['report_type'] ?? 'Advisory')); ?>
                                    </span>
                                </div>
                                <div class="card-body">
                                    <p class="card-text">
                                        <?php echo htmlspecialchars(substr($update['description'] ?? 'No description available', 0, 100)) . '...'; ?>
                                    </p>
                                    <?php
                                    $display_image = null;
                                    if (!empty($update['attachments'])):
                                        $attachments = json_decode($update['attachments'], true);
                                        if (is_array($attachments) && !empty($attachments)):
                                            foreach ($attachments as $attachment):
                                                if (isset($attachment['type']) && $attachment['type'] === 'image' && isset($attachment['file_path'])):
                                                    $display_image = $attachment['file_path'];
                                                    break;
                                                endif;
                                            endforeach;
                                        endif;
                                    endif;
                                    if (empty($display_image) && !empty($update['image_path']) && $update['image_path'] !== '0' && $update['image_path'] !== 'null'):
                                        $display_image = $update['image_path'];
                                    endif;
                                    if ($display_image): ?>
                                        <div class="mt-3">
                                            <img src="<?php echo htmlspecialchars($display_image); ?>"
                                                 alt="Report Image"
                                                 class="img-fluid rounded shadow-sm"
                                                 style="max-height: 200px; object-fit: cover; width: 100%; cursor: pointer;"
                                                 onclick="window.open(this.src, '_blank')"
                                                 title="Click to view full size"
                                                 onerror="this.onerror=null;this.src='https://via.placeholder.com/400x200/6c757d/ffffff?text=Image+Not+Available';">
                                        </div>
                                    <?php endif; ?>
                                    <small class="text-muted mt-2 d-block">
                                        <i class="fas fa-calendar"></i>
                                        <?php echo date('M d, Y', strtotime($update['reported_date'] ?? 'now')); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="alert alert-warning text-center">
                            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
                            <h5>No Updates Available</h5>
                            <p class="mb-0">No road updates have been posted yet. Please check back later.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="text-center mt-4">
                <a href="public_reports.php" class="btn btn-primary btn-lg" style="background: var(--accent-color); border: none; padding: 12px 28px; border-radius: 30px;">
                    <i class="fas fa-list"></i> View All Road Reports
                </a>
            </div>
        </div>
    </section>

    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p>&copy; 2026 Road and Transportation Department. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <div class="social-icons d-inline-block">
                        <a href="<?php echo $basePath; ?>lgu_staff/login.php" class="btn btn-login">Login</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('a[href*="login.php"]').forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const overlay = document.getElementById('pageTransitionOverlay');
                if (overlay) { overlay.classList.add('active'); }
                setTimeout(() => { window.location.href = this.href; }, 1000);
            });
        });
    </script>
    <?php include __DIR__ . '/includes/a11y_html.php'; ?>
    <div class="page-transition-overlay" id="pageTransitionOverlay">
        <div class="transition-content">
            <div class="transition-spinner"><i class="fas fa-spinner"></i></div>
            <div class="transition-text">Loading...</div>
        </div>
    </div>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
</body>
</html>
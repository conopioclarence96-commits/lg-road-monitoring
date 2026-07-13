<?php
/**
 * Public Transparency View – Citizen-Facing Page
 * Displays completed road projects with Before & After photo comparisons
 * Syncs to the same published_completed_projects table used by LGU Staff and Admin
 */

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

require_once __DIR__ . '/lgu_staff/includes/config.php';
require_once __DIR__ . '/lgu_staff/includes/functions.php';
$database_available = true;

$projects = [];
$stats = [
    'total' => 0,
    'with_before' => 0,
    'with_after' => 0,
    'total_cost' => 0,
];

if ($database_available && $conn) {
    try {
        $stmt = $conn->prepare("SELECT id, title, description, location, completed_date, cost, completed_by, photo, before_photo FROM published_completed_projects WHERE photo IS NOT NULL AND photo != '' AND is_published = 1 ORDER BY completed_date DESC");
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
        $stmt->close();

        $stats['total'] = count($projects);
        $stats['with_before'] = count(array_filter($projects, fn($p) => !empty($p['before_photo'])));
        $stats['with_after'] = count(array_filter($projects, fn($p) => !empty($p['photo'])));
        $stats['total_cost'] = array_sum(array_column($projects, 'cost'));
    } catch (Exception $e) {
        $projects = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Transparency – Completed Projects | Road & Transportation Department</title>
    <link rel="icon" type="image/png" href="assets/img/logocityhall.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles/transition.css">
    <style>
        :root {
            --primary-color: #1e3c72;
            --secondary-color: #2a5298;
            --accent-color: #4CAF50;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', sans-serif; color: #2c3e50; line-height: 1.6; }

        .navbar { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 1rem 0; }
        .navbar-brand { font-weight: 600; font-size: 1.5rem; color: white !important; display: flex; align-items: center; gap: 10px; }
        .navbar-nav .nav-link { color: white !important; font-weight: 500; margin: 0 10px; transition: color 0.3s ease; }
        .navbar-nav .nav-link:hover { color: var(--accent-color) !important; }

        .hero-bar {
            background: linear-gradient(135deg, rgba(30,60,114,0.95), rgba(42,82,152,0.95)),
                        url('assets/img/cityhall.jpeg') center/cover;
            padding: 40px 0 36px;
            color: white;
            text-align: center;
        }
        .hero-bar h1 { font-size: 2rem; font-weight: 700; margin-bottom: 6px; }
        .hero-bar p { font-size: 1rem; opacity: 0.9; margin-bottom: 0; }

        .section { padding: 60px 0; }
        .section-title { text-align: center; font-size: 2.2rem; font-weight: 700; color: var(--primary-color); margin-bottom: 20px; }
        .section-subtitle { text-align: center; font-size: 1.05rem; color: #666; margin-bottom: 40px; }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            max-width: 900px;
            margin: 0 auto 50px;
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 28px 20px;
            text-align: center;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
            transition: transform 0.3s ease;
        }
        .stat-card:hover { transform: translateY(-4px); }
        .stat-icon { font-size: 2.2rem; color: var(--accent-color); margin-bottom: 12px; }
        .stat-number { font-size: 2rem; font-weight: 700; color: var(--primary-color); margin-bottom: 4px; }
        .stat-label { font-size: 0.9rem; color: #666; font-weight: 500; }

        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(480px, 1fr));
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }
        @media (max-width: 576px) {
            .projects-grid { grid-template-columns: 1fr; }
        }

        .project-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .project-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 14px 40px rgba(0,0,0,0.16);
        }

        .comparison-slider {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 10;
            overflow: hidden;
            cursor: ew-resize;
            user-select: none;
            -webkit-user-select: none;
        }
        .comparison-slider img {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            object-fit: cover;
            pointer-events: none;
        }
        .comparison-slider .img-before {
            z-index: 2;
            clip-path: inset(0 50% 0 0);
        }
        .comparison-slider .img-after { z-index: 1; }

        .comparison-handle {
            position: absolute;
            top: 0; bottom: 0;
            left: 50%;
            width: 4px;
            background: white;
            z-index: 3;
            transform: translateX(-50%);
            box-shadow: 0 0 12px rgba(0,0,0,0.4);
            pointer-events: none;
        }
        .comparison-handle::before {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 44px; height: 44px;
            background: white;
            border-radius: 50%;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .comparison-handle::after {
            content: '◂ ▸';
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            font-size: 14px;
            font-weight: 700;
            color: var(--primary-color);
            z-index: 4;
            letter-spacing: -2px;
            white-space: nowrap;
        }

        .comparison-label {
            position: absolute;
            top: 12px;
            padding: 5px 14px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            z-index: 4;
            pointer-events: none;
        }
        .label-before { left: 12px; background: rgba(244,67,54,0.9); color: white; }
        .label-after { right: 12px; background: rgba(76,175,80,0.9); color: white; }

        .project-info { padding: 20px 24px; }
        .project-info h4 {
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        .project-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 12px;
            font-size: 0.88rem;
            color: #666;
        }
        .project-meta i { color: var(--accent-color); margin-right: 5px; }
        .project-cost {
            display: inline-block;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 600;
        }
        .project-desc {
            font-size: 0.92rem;
            color: #555;
            line-height: 1.55;
            margin-top: 10px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        .empty-state i { font-size: 3.5rem; margin-bottom: 15px; color: #ccc; }
        .empty-state h5 { font-size: 1.2rem; font-weight: 600; color: #666; margin-bottom: 8px; }

        footer { background: #0f2341; color: white; padding: 40px 0 20px; }
        footer a { color: rgba(255,255,255,0.8); text-decoration: none; }
        footer a:hover { color: white; }
        footer .btn-login { margin-left: 15px; vertical-align: middle; padding: 6px 18px; font-size: 0.9rem; }

        .sync-info {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(76,175,80,0.1);
            color: var(--accent-color);
            padding: 8px 18px;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 20px;
        }
        .sync-info i { font-size: 0.75rem; }

        @media (max-width: 768px) {
            .hero-bar h1 { font-size: 1.5rem; }
            .section-title { font-size: 1.7rem; }
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
            <h1><i class="fas fa-eye"></i> Public Transparency Portal</h1>
            <p>View completed road projects and their before & after transformations</p>
        </div>
    </div>

    <section class="section">
        <div class="container">
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-project-diagram"></i></div>
                    <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                    <div class="stat-label">Completed Projects</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-camera"></i></div>
                    <div class="stat-number"><?php echo number_format($stats['with_before']); ?></div>
                    <div class="stat-label">With Before Photos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="stat-number"><?php echo number_format($stats['with_after']); ?></div>
                    <div class="stat-label">With After Photos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon"><i class="fas fa-peso-sign"></i></div>
                    <div class="stat-number">₱<?php echo number_format($stats['total_cost']); ?></div>
                    <div class="stat-label">Total Project Cost</div>
                </div>
            </div>

            <h2 class="section-title">Completed Projects</h2>
            <p class="section-subtitle">Drag the slider to compare before and after our completed road projects</p>

            <?php if (!empty($projects)): ?>
            <div class="projects-grid">
                <?php foreach ($projects as $proj):
                    $after_img = htmlspecialchars(ltrim(str_replace(['../', '..\\'], '', $proj['photo']), '/\\'));
                    $before_img = !empty($proj['before_photo'])
                        ? htmlspecialchars(ltrim(str_replace(['../', '..\\'], '', $proj['before_photo']), '/\\'))
                        : $after_img;
                    $has_before = !empty($proj['before_photo']);
                ?>
                <div class="project-card">
                    <div class="comparison-slider" data-slider>
                        <img src="<?php echo $before_img; ?>" alt="Before" class="img-before" loading="lazy"
                             onerror="this.onerror=null;this.src='https://via.placeholder.com/600x375/dc3545/ffffff?text=Before+Image';">
                        <img src="<?php echo $after_img; ?>" alt="After" class="img-after" loading="lazy"
                             onerror="this.onerror=null;this.src='https://via.placeholder.com/600x375/4CAF50/ffffff?text=After+Image';">
                        <div class="comparison-handle" data-handle></div>
                        <span class="comparison-label label-before">Before</span>
                        <span class="comparison-label label-after">After</span>
                        <?php if (!$has_before): ?>
                        <span style="position:absolute;bottom:12px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.6);color:white;padding:5px 14px;border-radius:20px;font-size:0.75rem;z-index:5;pointer-events:none;">
                            <i class="fas fa-info-circle"></i> No before photo available
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="project-info">
                        <h4><?php echo htmlspecialchars($proj['title']); ?></h4>
                        <div class="project-meta">
                            <?php if (!empty($proj['location'])): ?>
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($proj['location']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($proj['completed_date'])): ?>
                            <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y', strtotime($proj['completed_date'])); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($proj['completed_by'])): ?>
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($proj['completed_by']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($proj['cost'])): ?>
                        <span class="project-cost">₱<?php echo number_format($proj['cost'], 2); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($proj['description'])): ?>
                        <p class="project-desc"><?php echo htmlspecialchars($proj['description']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-images"></i>
                <h5>Projects Coming Soon</h5>
                <p>Completed road projects will be displayed here once published by the LGU staff.</p>
            </div>
            <?php endif; ?>
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
                        <a href="lgu_staff/login.php" class="btn btn-login">Login</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>

    <?php include __DIR__ . '/includes/a11y_html.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('[data-slider]').forEach(slider => {
            const imgBefore = slider.querySelector('.img-before');
            const handle = slider.querySelector('[data-handle]');
            let isDragging = false;

            function updateSlider(x) {
                const rect = slider.getBoundingClientRect();
                let pos = ((x - rect.left) / rect.width) * 100;
                pos = Math.max(0, Math.min(100, pos));
                imgBefore.style.clipPath = 'inset(0 ' + (100 - pos) + '% 0 0)';
                handle.style.left = pos + '%';
            }

            slider.addEventListener('mousedown', (e) => {
                isDragging = true;
                updateSlider(e.clientX);
                slider.style.cursor = 'grabbing';
            });
            document.addEventListener('mousemove', (e) => {
                if (!isDragging) return;
                e.preventDefault();
                updateSlider(e.clientX);
            });
            document.addEventListener('mouseup', () => {
                if (isDragging) {
                    isDragging = false;
                    slider.style.cursor = 'ew-resize';
                }
            });

            slider.addEventListener('touchstart', (e) => {
                isDragging = true;
                updateSlider(e.touches[0].clientX);
            }, { passive: true });
            slider.addEventListener('touchmove', (e) => {
                if (!isDragging) return;
                e.preventDefault();
                updateSlider(e.touches[0].clientX);
            }, { passive: false });
            slider.addEventListener('touchend', () => { isDragging = false; });

            setTimeout(() => {
                let start = 0;
                const target = 50;
                const duration = 800;
                const startTime = performance.now();
                function animate(time) {
                    const elapsed = time - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    const eased = 1 - Math.pow(1 - progress, 3);
                    const current = start + (target - start) * eased;
                    imgBefore.style.clipPath = 'inset(0 ' + (100 - current) + '% 0 0)';
                    handle.style.left = current + '%';
                    if (progress < 1) requestAnimationFrame(animate);
                }
                requestAnimationFrame(animate);
            }, 300);
        });

        document.querySelectorAll('a[href*="login.php"]').forEach(link => {
            link.addEventListener('click', function (e) {
                e.preventDefault();
                const overlay = document.getElementById('pageTransitionOverlay');
                if (overlay) overlay.classList.add('active');
                setTimeout(() => { window.location.href = this.href; }, 1000);
            });
        });
    </script>
    <div class="page-transition-overlay" id="pageTransitionOverlay">
        <div class="transition-content">
            <div class="transition-spinner"><i class="fas fa-spinner"></i></div>
            <div class="transition-text">Loading...</div>
        </div>
    </div>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
</body>
</html>

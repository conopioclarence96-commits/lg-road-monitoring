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
    <?php include __DIR__ . '/includes/a11y_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Transparency – Completed Projects | Road & Transportation Department</title>
    <link rel="icon" type="image/png" href="assets/img/logocityhall.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles/transition.css">
    <style>
        :root {
            --primary-color: #115272;
            --secondary-color: #1d698b;
            --accent-color: #d93939;
            --light-bg: #f4f6f7;
            --qc-primary-50: #f1f9fe;
            --qc-primary-100: #e1f1fc;
            --qc-primary-200: #c3e3f8;
            --qc-primary-300: #96cdf1;
            --qc-primary-400: #62b2e7;
            --qc-primary-500: #21a1d6;
            --qc-primary-600: #1381b6;
            --qc-primary-700: #15689b;
            --qc-primary-800: #115272;
            --qc-primary-900: #143c5e;
            --qc-primary-950: #0e2f43;
            --qc-shades-100: #eef1f3;
            --qc-shades-200: #d6dce1;
            --qc-shades-300: #c3ccd3;
            --qc-shades-400: #9aa7b0;
            --qc-shades-500: #5f6c75;
            --qc-card-border: #e0e8ee;
            --qc-icon-bg: #d6e9f8;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Montserrat', sans-serif; color: #3e454c; line-height: 1.6; }

        .qc-navbar {
            background: #ffffff !important;
            border-bottom: 1px solid var(--qc-shades-100);
            box-shadow: 0 1px 3px rgba(17, 82, 114, 0.06);
            padding: 0.55rem 0;
        }
        .qc-brand { display: flex; align-items: center; gap: 12px; text-decoration: none; padding: 0; }
        .qc-brand img { height: 46px; width: auto; border-radius: 6px; }
        .qc-brand-text { line-height: 1.15; text-align: left; }
        .qc-brand-text strong { display: block; font-size: 1.02rem; font-weight: 800; color: var(--qc-primary-800); }
        .qc-brand-text small { display: block; font-size: 0.7rem; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: var(--qc-primary-600); }

        .btn-login {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--qc-primary-800);
            color: #fff;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-weight: 700;
            transition: all 0.2s ease;
        }
        .btn-login:hover { background: var(--qc-primary-600); color: #fff; transform: translateY(-2px); }

        /* Hamburger button override for the white navbar */
        .qc-navbar .container-fluid { padding-right: 76px; }
        .hamburger-btn {
            top: 11px !important;
            right: 18px !important;
            width: 42px !important;
            height: 42px !important;
            border: 2px solid rgba(17, 82, 114, 0.25) !important;
            background: rgba(17, 82, 114, 0.06) !important;
            box-shadow: 0 4px 12px rgba(17, 82, 114, 0.12) !important;
        }
        .hamburger-btn:hover { background: rgba(17, 82, 114, 0.14) !important; }
        .hamburger-btn .bar { background: var(--qc-primary-800) !important; }

        .hero-bar {
            background:
                linear-gradient(115deg, rgba(11, 42, 62, 0.96) 0%, rgba(17, 82, 114, 0.9) 55%, rgba(19, 129, 182, 0.78) 100%),
                url('assets/img/cityhall.jpeg') center/cover;
            padding: 112px 0 84px;
            color: white;
            text-align: center;
        }
        .hero-bar h1 { font-size: 2.2rem; font-weight: 800; margin-bottom: 8px; text-shadow: 0 2px 10px rgba(0,0,0,0.25); }
        .hero-bar p { font-size: 1.05rem; opacity: 0.92; margin-bottom: 0; }

        .section { padding: 70px 0 60px; background: #ffffff; }
        .section-title { text-align: center; font-size: 1.7rem; font-weight: 800; color: var(--qc-primary-800); margin-bottom: 12px; line-height: 1.25; }
        .section-title::after { content: ''; display: block; width: 56px; height: 4px; margin: 12px auto 0; border-radius: 4px; background: var(--qc-primary-500); }
        .section-subtitle { text-align: center; font-size: 1.02rem; color: var(--qc-shades-500); margin-bottom: 40px; max-width: 620px; margin-left: auto; margin-right: auto; }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            max-width: 900px;
            margin: 0 auto 50px;
        }
        .stat-card {
            background: white;
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            padding: 28px 20px;
            text-align: center;
            box-shadow: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 24px rgba(17, 82, 114, 0.1); }
        .stat-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 12px;
            background: var(--qc-icon-bg);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--qc-primary-800);
        }
        .stat-number { font-size: 2rem; font-weight: 800; color: var(--qc-primary-800); margin-bottom: 4px; }
        .stat-label { font-size: 0.9rem; color: var(--qc-shades-500); font-weight: 500; }

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
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .project-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(17, 82, 114, 0.1);
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
            color: var(--qc-primary-800);
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
        .label-before { left: 12px; background: rgba(217, 57, 57, 0.92); color: white; }
        .label-after { right: 12px; background: rgba(40, 167, 69, 0.92); color: white; }

        .project-info { padding: 20px 24px; }
        .project-info h4 {
            font-size: 1.15rem;
            font-weight: 700;
            color: var(--qc-primary-900);
            margin-bottom: 10px;
        }
        .project-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 12px;
            font-size: 0.88rem;
            color: var(--qc-shades-500);
        }
        .project-meta i { color: var(--qc-primary-500); margin-right: 5px; }
        .project-cost {
            display: inline-block;
            background: var(--qc-primary-800);
            color: white;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 0.82rem;
            font-weight: 600;
        }
        .project-desc {
            font-size: 0.92rem;
            color: var(--qc-shades-500);
            line-height: 1.55;
            margin-top: 10px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--qc-shades-400);
        }
        .empty-state i { font-size: 3.5rem; margin-bottom: 15px; color: var(--qc-shades-200); }
        .empty-state h5 { font-size: 1.2rem; font-weight: 700; color: var(--qc-shades-500); margin-bottom: 8px; }

        /* Footer — QC E-Services style */
        footer.qc-footer {
            background: linear-gradient(135deg, var(--qc-primary-800) 0%, #1d698b 100%);
            color: #fff;
            padding: 34px 0 20px;
        }
        footer.qc-footer a { color: #fff; text-decoration: none; }
        .footer-top-row { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 20px; }
        .footer-follow-label { font-weight: 600; font-size: 0.9rem; margin-bottom: 8px; color: rgba(255,255,255,0.95); }
        .footer-social-row { display: flex; gap: 10px; }
        .footer-social-circle { width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,0.92); color: #165b79; display: inline-flex; align-items: center; justify-content: center; font-size: 15px; transition: transform 0.2s ease, color 0.2s ease; }
        .footer-social-circle:hover { transform: translateY(-2px); color: #0e2f43; }
        .footer-contact-row { display: flex; align-items: center; gap: 14px; font-size: 14px; }
        .footer-contact-item { display: inline-flex; align-items: center; gap: 8px; color: #fff; }
        .footer-contact-item:hover { color: #fff; }
        .footer-contact-item i { color: #fff; font-size: 15px; }
        .contact-separator { width: 1px; height: 18px; background: rgba(255,255,255,0.4); }
        .footer-links-row { display: flex; flex-wrap: wrap; gap: 22px; }
        .footer-links-row a { font-size: 12px; font-weight: 600; letter-spacing: 0.2px; }
        .footer-links-row a:hover { color: #eaf3f9; text-decoration: underline; }
        .footer-divider { height: 1px; background: rgba(255,255,255,0.34); margin: 22px 0 14px; }
        .footer-bottom-row { text-align: center; }
        .footer-copyright { font-size: 13px; color: rgba(244,248,251,0.85); margin: 0; }
        .footer-copyright i { margin-right: 4px; }

        .sync-info {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--qc-primary-50);
            color: var(--qc-primary-700);
            padding: 8px 18px;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 20px;
        }
        .sync-info i { font-size: 0.75rem; }

        @media (max-width: 768px) {
            .hero-bar { padding: 96px 0 64px; }
            .hero-bar h1 { font-size: 1.5rem; }
            .section-title { font-size: 1.4rem; }
            .footer-top-row { flex-direction: column; text-align: center; }
            .footer-social-row { justify-content: center; }
            .footer-contact-row { justify-content: center; flex-wrap: wrap; }
            .footer-links-row { justify-content: center; gap: 16px; }
        }
    </style>
    <?php include __DIR__ . '/includes/a11y_css.php'; ?>
    <?php include __DIR__ . '/includes/hamburger_menu_css.php'; ?>
</head>
<body>
    <nav class="navbar navbar-light fixed-top qc-navbar">
        <div class="container-fluid">
            <a class="navbar-brand qc-brand" href="index.php">
                <img src="assets/img/logocityhall.png" alt="Quezon City Hall Logo">
                <span class="qc-brand-text">
                    <strong>Road &amp; Transportation Department</strong>
                    <small>Quezon City Government</small>
                </span>
            </a>
            <?php include __DIR__ . '/includes/navbar_quicklinks.php'; ?>
        </div>
    </nav>

    <?php include __DIR__ . '/includes/mobile_navbar_css.php'; ?>

    <?php include __DIR__ . '/includes/hamburger_menu.php'; ?>

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
                             onerror="this.onerror=null;this.src='https://via.placeholder.com/600x375/28a745/ffffff?text=After+Image';">
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

    <footer class="qc-footer">
        <div class="container">
            <div class="footer-top-row">
                <div>
                    <div class="footer-follow-label">FOLLOW US</div>
                    <div class="footer-social-row">
                        <a href="#" aria-label="Facebook" class="footer-social-circle"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" aria-label="X" class="footer-social-circle"><i class="fab fa-twitter"></i></a>
                        <a href="#" aria-label="YouTube" class="footer-social-circle"><i class="fab fa-youtube"></i></a>
                        <a href="#" aria-label="Instagram" class="footer-social-circle"><i class="fab fa-instagram"></i></a>
                    </div>
                </div>
                <div class="footer-contact-row">
                    <a href="tel:+63289881234" class="footer-contact-item"><i class="fas fa-phone-alt"></i> (02) 8988-1234</a>
                    <span class="contact-separator"></span>
                    <a href="mailto:roads@lgu.gov.ph" class="footer-contact-item"><i class="fas fa-envelope"></i> roads@lgu.gov.ph</a>
                    <span class="contact-separator"></span>
                    <a href="contact.php" class="footer-contact-item"><i class="fas fa-map-marker-alt"></i> Quezon City Hall</a>
                </div>
            </div>
            <div class="footer-links-row">
                <a href="index.php">Home</a>
                <a href="road-updates.php">Road Updates</a>
                <a href="public_reports.php">Road Status</a>
                <a href="about.php">About</a>
                <a href="contact.php">Contact</a>
                <a href="public_transparency_view.php">Transparency</a>
            </div>
            <div class="footer-divider"></div>
            <div class="footer-bottom-row">
                <p class="footer-copyright"><i class="fas fa-copyright"></i> 2026 Road and Transportation Department. All rights reserved.</p>
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
    </script>

    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
    <?php include __DIR__ . '/includes/hamburger_menu_js.php'; ?>
</body>
</html>

<?php
/**
 * Infrastructure Maintenance Service Detail Page
 */

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

// Dynamic base path detection
$basePath = '';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

// Detect if we're in a subdirectory
if (strpos($scriptName, '/lgu_staff/') !== false) {
    $basePath = '../';
} elseif (strpos($scriptName, '/public/') !== false) {
    $basePath = '../';
} elseif (strpos($requestUri, '/lgu-portal/') !== false) {
    $basePath = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include __DIR__ . '/includes/a11y_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Infrastructure Maintenance - Road and Transportation Department</title>
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
        .hero-bar .service-icon-large { font-size: 3.2rem; color: #ffd166; margin-bottom: 14px; }

        .section { padding: 70px 0 60px; background: #ffffff; }
        .section-title { text-align: center; font-size: 1.7rem; font-weight: 800; color: var(--qc-primary-800); margin-bottom: 12px; line-height: 1.25; }
        .section-title::after { content: ''; display: block; width: 56px; height: 4px; margin: 12px auto 0; border-radius: 4px; background: var(--qc-primary-500); }
        .section-subtitle { text-align: center; font-size: 1.02rem; color: var(--qc-shades-500); margin-bottom: 40px; max-width: 620px; margin-left: auto; margin-right: auto; }

        .content { max-width: 860px; margin: 0 auto; }
        .content h2 { color: var(--qc-primary-800); font-weight: 800; margin-bottom: 16px; }
        .content h3 { color: var(--qc-primary-800); font-weight: 700; margin-top: 40px; }
        .content h5 { color: var(--qc-primary-700); font-weight: 700; }
        .content p { color: var(--qc-shades-500); }
        .content ul li, .content ol li { margin-bottom: 8px; color: var(--qc-shades-500); }

        .feature-card {
            background: white;
            border: 1px solid var(--qc-card-border);
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            box-shadow: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            height: 100%;
        }
        .feature-card:hover { transform: translateY(-4px); box-shadow: 0 10px 24px rgba(17, 82, 114, 0.1); }
        .feature-card h4 { color: var(--qc-primary-900); font-weight: 700; margin-bottom: 12px; }
        .feature-icon { font-size: 2.5rem; color: var(--qc-primary-800); margin-bottom: 15px; }

        /* Footer — QC E-Services style */
        footer.qc-footer {
            background: linear-gradient(135deg, var(--qc-primary-800) 0%, #1d698b 100%);
            color: #fff;
            padding: 34px 0 20px;
        }
        footer.qc-footer a { color: #fff; text-decoration: none; }
        .footer-top-row { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 20px; }
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

        @media (max-width: 768px) {
            .hero-bar { padding: 96px 0 64px; }
            .hero-bar h1 { font-size: 1.5rem; }
            .section-title { font-size: 1.4rem; }
            .footer-top-row { flex-direction: column; text-align: center; }
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
            <a class="navbar-brand qc-brand" href="<?php echo $basePath; ?>index.php">
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
            <div class="service-icon-large">
                <i class="fas fa-hard-hat"></i>
            </div>
            <h1>Infrastructure Maintenance</h1>
            <p>Our officers and field teams keep roads, bridges, and transportation infrastructure safe through regular inspections, maintenance, and repairs.</p>
        </div>
    </div>

    <section class="section">
        <div class="container">
            <div class="content">
                <h2>Officer-Inspected Infrastructure Maintenance</h2>
                <p>
                    Our Infrastructure Maintenance service keeps the city's roads and transportation assets safe through
                    systematic inspection by our officers, preventive maintenance, and timely repairs. Officers report
                    observed damage and project progress through the Road &amp; Transportation monitoring system, guiding
                    maintenance teams to the areas that need attention most and keeping residents informed along the way.
                </p>

                <h3 class="mt-5">Maintenance Services</h3>

                <div class="row g-4 mt-3">
                    <div class="col-md-6">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-tools"></i>
                            </div>
                            <h4>Road Repairs</h4>
                            <p>Pothole filling, crack sealing, surface resurfacing, and pavement reconstruction carried out by field maintenance teams.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-bridge"></i>
                            </div>
                            <h4>Bridge Maintenance</h4>
                            <p>Structural inspections by trained officers, corrosion protection, deck repairs, and load capacity assessments for all bridges.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-traffic-light"></i>
                            </div>
                            <h4>Traffic Systems</h4>
                            <p>Maintenance of traffic signals, road signs, lane markings, and related systems reported by officers on patrol.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-water"></i>
                            </div>
                            <h4>Drainage Systems</h4>
                            <p>Cleaning and repair of drainage infrastructure to prevent flooding and water damage to road surfaces.</p>
                        </div>
                    </div>
                </div>

                <h3 class="mt-5">Inspection &amp; Maintenance Schedule</h3>
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="table-responsive">
                            <table class="table table-bordered">
                                <thead class="table-primary">
                                    <tr>
                                        <th>Frequency</th>
                                        <th>Officer Activities</th>
                                        <th>Tasks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td><strong>Daily</strong></td>
                                        <td>Routine Inspections</td>
                                        <td>Road condition checks, debris removal, traffic signal verification</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Weekly</strong></td>
                                        <td>Preventive Maintenance</td>
                                        <td>Sign cleaning, minor repairs, drainage clearing</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Monthly</strong></td>
                                        <td>Detailed Assessments</td>
                                        <td>Surface condition surveys, bridge inspections, equipment maintenance</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Quarterly</strong></td>
                                        <td>Major Maintenance</td>
                                        <td>Resurfacing projects, structural repairs, system upgrades</td>
                                    </tr>
                                    <tr>
                                        <td><strong>Annually</strong></td>
                                        <td>Comprehensive Reviews</td>
                                        <td>Infrastructure audits, long-term planning, budget assessments</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <h3 class="mt-5">How Our Officers Maintain Infrastructure</h3>
                <div class="row mt-4">
                    <div class="col-md-6">
                        <ul>
                            <li><i class="fas fa-check text-success"></i> <strong>Field Inspections:</strong> Officers inspect roads and bridges and report issues found</li>
                            <li><i class="fas fa-check text-success"></i> <strong>Monitoring Reports:</strong> Damage and repair needs logged through the monitoring portal</li>
                            <li><i class="fas fa-check text-success"></i> <strong>Citizen Reports:</strong> Concerns filed by residents are verified and scheduled for repair</li>
                            <li><i class="fas fa-check text-success"></i> <strong>Coordinated Repairs:</strong> Maintenance teams and officers work together on the ground</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul>
                            <li><i class="fas fa-check text-success"></i> <strong>Progress Updates:</strong> Officers post updates so the public can track project status</li>
                            <li><i class="fas fa-check text-success"></i> <strong>Safety First:</strong> Inspections prioritize hazards that affect motorists and pedestrians</li>
                            <li><i class="fas fa-check text-success"></i> <strong>Long-Lasting Work:</strong> Repairs follow established standards for durability</li>
                            <li><i class="fas fa-check text-success"></i> <strong>GPS Tracking:</strong> Real-time monitoring of maintenance vehicles</li>
                        </ul>
                    </div>
                </div>

                <h3 class="mt-5">Emergency Response by Our Officers</h3>
                <p>
                    Our officers and maintenance teams are available 24/7 for emergency repairs. Critical issues such as road washouts,
                    bridge damage, or hazardous conditions receive immediate attention with dedicated response protocols coordinated
                    through the Road &amp; Transportation monitoring system.
                </p>

                <div class="alert alert-info mt-4">
                    <h5><i class="fas fa-info-circle"></i> Report Infrastructure Issues</h5>
                    <p class="mb-0">
                        Report road and infrastructure issues directly through the portal, or call our hotline:
                        <strong>(02) 8988-1234</strong><br>
                        Available 24/7 for emergency infrastructure problems.
                    </p>
                </div>

                <div class="text-center mt-5">
                    <h3>Request Maintenance Service</h3>
                    <p class="lead">Help our officers maintain our infrastructure by reporting maintenance needs.</p>
                    <a href="<?php echo $basePath; ?>contact.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-phone"></i> Contact Maintenance Team
                    </a>
                </div>
            </div>
        </div>
    </section>

    <footer class="qc-footer">
        <div class="container">
            <div class="footer-top-row">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/includes/a11y_html.php'; ?>

    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
    <?php include __DIR__ . '/includes/hamburger_menu_js.php'; ?>
</body>
</html>

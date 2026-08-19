<?php
/**
 * Traffic Management Service Detail Page
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
    <title>Traffic Management - Road and Transportation Department</title>
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
                <i class="fas fa-traffic-light"></i>
            </div>
            <h1>Traffic Management</h1>
            <p>Our officers keep Quezon City moving — trained traffic and transportation monitoring officers deployed across the city to manage, monitor, and respond to conditions on the road.</p>
        </div>
    </div>

    <section class="section">
        <div class="container">
            <div class="content">
                <h2>Officer-Led Traffic Management</h2>
                <p>
                    Our Traffic Management service is powered by our field officers. Traffic enforcers, Transportation
                    Monitoring Officers, and Transportation Operations Supervisors work together to observe road conditions,
                    respond to congestion and incidents, and keep transportation flowing smoothly throughout Quezon City.
                    Every report our officers submit feeds directly into the Road &amp; Transportation monitoring system,
                    giving residents a clear picture of what is happening on their streets.
                </p>

                <h3 class="mt-5">Our Traffic &amp; Transportation Officers</h3>

                <div class="row g-4 mt-3">
                    <div class="col-md-6">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-traffic-light"></i>
                            </div>
                            <h4>Traffic Enforcers on the Ground</h4>
                            <p>Officers stationed at busy intersections direct traffic, manage peak-hour flow, and respond to congestion as it develops.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-eye"></i>
                            </div>
                            <h4>Transportation Monitoring Officers</h4>
                            <p>Monitoring officers are assigned to specific roads and routes, observing conditions and submitting real-time reports through the monitoring portal.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-users-cog"></i>
                            </div>
                            <h4>Operations Supervisors</h4>
                            <p>Supervisors coordinate officer deployments, review incoming reports, and direct response teams to the areas that need them most.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="feature-card">
                            <div class="feature-icon">
                                <i class="fas fa-car"></i>
                            </div>
                            <h4>Mobile Patrols &amp; Field Teams</h4>
                            <p>Patrol teams on the road cover barangays and major arterials, spotting issues early and keeping traffic moving during special events and emergencies.</p>
                        </div>
                    </div>
                </div>

                <h3 class="mt-5">How Our Officers Manage Traffic</h3>
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h5><i class="fas fa-route text-primary"></i> Route Patrols &amp; Spot Checks</h5>
                        <p>Officers patrol assigned routes, checking road and traffic conditions and taking action on reported problem areas.</p>

                        <h5 class="mt-4"><i class="fas fa-flag-checkered text-primary"></i> Peak-Hour Traffic Direction</h5>
                        <p>Enforcers are positioned at major intersections during rush hours to guide vehicles, manage turns, and ease congestion.</p>
                    </div>
                    <div class="col-md-6">
                        <h5><i class="fas fa-radio text-primary"></i> Coordination with Operations</h5>
                        <p>Officers stay in constant contact with supervisors and dispatch so that help is routed quickly where traffic is heaviest.</p>

                        <h5 class="mt-4"><i class="fas fa-comments text-primary"></i> Citizen Engagement</h5>
                        <p>Officers act on reports filed by residents, verifying concerns on-site and following up until they are resolved.</p>
                    </div>
                </div>

                <h3 class="mt-5">What Our Officers Monitor</h3>
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="alert alert-success">
                            <h5><i class="fas fa-chart-line"></i> Field Monitoring &amp; Reporting</h5>
                            <p class="mb-0">
                                Our officers monitor transportation conditions across the municipality and document what they find:
                            </p>
                            <ul class="mt-2 mb-0">
                                <li>Traffic congestion and slow-moving areas on major roads</li>
                                <li>Accidents, breakdowns, and other incidents blocking the way</li>
                                <li>Traffic light outages and damaged road signs or markings</li>
                                <li>Obstructions, debris, and road work affecting travel</li>
                                <li>Public transport concerns reported by commuters and residents</li>
                                <li>Live submissions filed through the Road &amp; Transportation monitoring portal</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <h3 class="mt-5">Incident Response by Our Officers</h3>
                <p>
                    When an incident occurs, our officers act fast — assessing the situation on the ground and coordinating a response:
                </p>
                <div class="row mt-3">
                    <div class="col-md-4">
                        <div class="text-center">
                            <div class="feature-icon">
                                <i class="fas fa-clipboard-check text-danger"></i>
                            </div>
                            <h6>On-Site Assessment</h6>
                            <p>Officers reach the scene, assess the situation, and verify what is causing the disruption.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <div class="feature-icon">
                                <i class="fas fa-hand-paper text-warning"></i>
                            </div>
                            <h6>Immediate Traffic Direction</h6>
                            <p>Enforcers guide vehicles around the area to keep traffic moving and prevent gridlock.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-center">
                            <div class="feature-icon">
                                <i class="fas fa-bullhorn text-info"></i>
                            </div>
                            <h6>Reporting &amp; Coordination</h6>
                            <p>Officers file reports and coordinate with supervisors, emergency services, and road maintenance teams.</p>
                        </div>
                    </div>
                </div>

                <h3 class="mt-5">Officer Reports &amp; Planning</h3>
                <div class="row mt-4">
                    <div class="col-md-6">
                        <h6>Field Observations</h6>
                        <ul>
                            <li>Reported congestion and traffic flow concerns</li>
                            <li>Documented incidents and their causes</li>
                            <li>Peak-hour patterns observed by officers</li>
                            <li>Transportation issues raised by the public</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Planning Applications</h6>
                        <ul>
                            <li>Officer deployment and staffing decisions</li>
                            <li>Road improvement and maintenance priorities</li>
                            <li>Public transport route adjustments</li>
                            <li>Policies to reduce congestion and improve safety</li>
                        </ul>
                    </div>
                </div>

                <div class="text-center mt-5">
                    <h3>Check Traffic Conditions</h3>
                    <p class="lead">Get real-time traffic updates and plan your journey efficiently.</p>
                    <a href="<?php echo $basePath; ?>road-updates.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-map-marked-alt"></i> View Traffic Updates
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

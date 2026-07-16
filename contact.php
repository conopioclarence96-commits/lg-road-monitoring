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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Us - Road & Transportation Department</title>
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
        .contact-section { background: linear-gradient(135deg, var(--primary-color), var(--secondary-color)); color: white; padding: 60px 0; }
        .contact-info { text-align: center; padding: 30px 20px; background: rgba(255,255,255,0.1); border-radius: 15px; transition: transform 0.3s ease; }
        .contact-info:hover { transform: translateY(-5px); }
        .contact-icon { font-size: 2.5rem; margin-bottom: 15px; color: var(--accent-color); }
        .contact-info h4 { font-weight: 600; margin-bottom: 12px; }
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
            <h1><i class="fas fa-envelope"></i> Contact Us</h1>
            <p>Get in touch with our team for assistance and inquiries</p>
        </div>
    </div>

    <section class="contact-section">
        <div class="container">
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="contact-info">
                        <div class="contact-icon">
                            <i class="fas fa-phone"></i>
                        </div>
                        <h4>Phone</h4>
                        <p>Main Office: (123) 456-7890<br>
                           Emergency Hotline: (123) 456-9999</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="contact-info">
                        <div class="contact-icon">
                            <i class="fas fa-envelope"></i>
                        </div>
                        <h4>Email</h4>
                        <p>General: roads@lgu.gov.ph<br>
                           Emergency: emergency@lgu.gov.ph</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="contact-info">
                        <div class="contact-icon">
                            <i class="fas fa-map-marker-alt"></i>
                        </div>
                        <h4>Office Location</h4>
                        <p>Road & Transportation Dept.<br>
                           City Hall Building<br>
                           Quezon City, Philippines</p>
                    </div>
                </div>
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
    <?php include __DIR__ . '/includes/a11y_html.php'; ?>

    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
</body>
</html>
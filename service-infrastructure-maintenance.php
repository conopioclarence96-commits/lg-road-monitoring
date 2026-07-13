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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Infrastructure Maintenance - Road and Transportation Department</title>
    <link rel="icon" type="image/png" href="assets/img/logocityhall.png">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #1e3c72;
            --secondary-color: #2a5298;
            --accent-color: #4CAF50;
            --light-bg: #f8f9fa;
            --dark-text: #2c3e50;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--dark-text);
            line-height: 1.6;
        }

        /* Navigation */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1rem 0;
        }

        .navbar-brand {
            font-weight: 600;
            font-size: 1.5rem;
            color: white !important;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .navbar-nav .nav-link {
            color: white !important;
            font-weight: 500;
            margin: 0 10px;
            transition: color 0.3s ease;
        }

        .navbar-nav .nav-link:hover {
            color: var(--accent-color) !important;
        }

        /* Hero Section */
        .service-hero {
            background: linear-gradient(rgba(30, 60, 114, 0.9), rgba(42, 82, 152, 0.9)),
                        url('assets/img/infrastructure.jpg') center/cover no-repeat;
            color: white;
            padding: 100px 0 60px;
            text-align: center;
        }

        .service-hero h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .service-icon-large {
            font-size: 4rem;
            color: var(--accent-color);
            margin-bottom: 20px;
        }

        /* Content Section */
        .content-section {
            padding: 80px 0;
        }

        .feature-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
            height: 100%;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }

        .feature-icon {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .back-button {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 25px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            margin-bottom: 20px;
        }

        .back-button:hover {
            background: #45a049;
            transform: translateY(-2px);
            color: white;
        }

        /* Footer */
        footer {
            background: #0f2341;
            color: white;
            padding: 40px 0 20px;
        }
    </style>
    <?php include __DIR__ . '/includes/a11y_css.php'; ?>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark fixed-top">
        <div class="container">
            <a class="navbar-brand" href="<?php echo $basePath; ?>index.php">
                <i class="fas fa-road"></i>
                Road & Transportation Department
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>index.php#services">Services</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo $basePath; ?>index.php#contact">Contact</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Service Hero Section -->
    <section class="service-hero">
        <div class="container">
            <div class="service-icon-large">
                <i class="fas fa-hard-hat"></i>
            </div>
            <h1>Infrastructure Maintenance</h1>
            <p class="lead">
                Regular maintenance and repair of roads, bridges, and transportation infrastructure to extend their lifespan.
            </p>
        </div>
    </section>

    <!-- Main Content -->
    <section class="content-section">
        <div class="container">
            <a href="<?php echo $basePath; ?>index.php#services" class="back-button">
                <i class="fas fa-arrow-left"></i> Back to Services
            </a>

            <div class="row">
                <div class="col-lg-8 mx-auto">
                    <div class="content">
                        <h2>Comprehensive Infrastructure Maintenance</h2>
                        <p>
                            Our Infrastructure Maintenance service ensures the longevity and safety of all transportation assets through systematic inspection, 
                            preventive maintenance, and timely repairs. We employ modern techniques and materials to maintain high standards of road quality 
                            and infrastructure reliability.
                        </p>

                        <h3 class="mt-5">Maintenance Services</h3>
                        
                        <div class="row g-4 mt-3">
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-tools"></i>
                                    </div>
                                    <h4>Road Repairs</h4>
                                    <p>Pothole filling, crack sealing, surface resurfacing, and pavement reconstruction using high-quality materials.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-bridge"></i>
                                    </div>
                                    <h4>Bridge Maintenance</h4>
                                    <p>Structural inspections, corrosion protection, deck repairs, and load capacity assessments for all bridges.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-traffic-light"></i>
                                    </div>
                                    <h4>Traffic Systems</h4>
                                    <p>Maintenance of traffic signals, road signs, lane markings, and intelligent transportation systems.</p>
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

                        <h3 class="mt-5">Maintenance Schedule</h3>
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-primary">
                                            <tr>
                                                <th>Maintenance Type</th>
                                                <th>Frequency</th>
                                                <th>Activities</th>
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

                        <h3 class="mt-5">Advanced Maintenance Technologies</h3>
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <ul>
                                    <li><i class="fas fa-check text-success"></i> <strong>Thermal Imaging:</strong> Detects subsurface defects and moisture issues</li>
                                    <li><i class="fas fa-check text-success"></i> <strong>Ground Penetrating Radar:</strong> Identifies voids and structural weaknesses</li>
                                    <li><i class="fas fa-check text-success"></i> <strong>Automated Patching:</strong> Quick repair systems for minor damages</li>
                                    <li><i class="fas fa-check text-success"></i> <strong>Polymer Materials:</strong> Long-lasting repair solutions</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <ul>
                                    <li><i class="fas fa-check text-success"></i> <strong>Predictive Analytics:</strong> Data-driven maintenance scheduling</li>
                                    <li><i class="fas fa-check text-success"></i> <strong>Drone Inspections:</strong> Safe and efficient bridge and overpass assessments</li>
                                    <li><i class="fas fa-check text-success"></i> <strong>Smart Materials:</strong> Self-healing concrete and asphalt</li>
                                    <li><i class="fas fa-check text-success"></i> <strong>GPS Tracking:</strong> Real-time monitoring of maintenance vehicles</li>
                                </ul>
                            </div>
                        </div>

                        <h3 class="mt-5">Emergency Response</h3>
                        <p>
                            Our maintenance teams are available 24/7 for emergency repairs. Critical issues such as road washouts, bridge damage, 
                            or hazardous conditions receive immediate attention with dedicated emergency response protocols.
                        </p>

                        <div class="alert alert-info mt-4">
                            <h5><i class="fas fa-info-circle"></i> Maintenance Request Hotline</h5>
                            <p class="mb-0">
                                Report maintenance issues immediately: <strong>(123) 456-9999</strong><br>
                                Available 24/7 for emergency infrastructure problems.
                            </p>
                        </div>

                        <div class="text-center mt-5">
                            <h3>Request Maintenance Service</h3>
                            <p class="lead">Help us maintain our infrastructure by reporting maintenance needs.</p>
                            <a href="<?php echo $basePath; ?>contact.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-phone"></i> Contact Maintenance Team
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <p>&copy; 2026 Road and Transportation Department. All rights reserved.</p>
                </div>
                <div class="col-md-6 text-md-end">
                    <a href="#" class="text-white me-3">Privacy Policy</a>
                    <a href="#" class="text-white">Terms of Service</a>
                </div>
            </div>
        </div>
    </footer>

    <?php include __DIR__ . '/includes/a11y_html.php'; ?>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
</body>
</html>

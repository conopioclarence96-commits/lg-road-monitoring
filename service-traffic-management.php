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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traffic Management - Road and Transportation Department</title>
    <link rel="icon" type="image/png" href="assets/img/logocityhall.png">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles/transition.css">
    
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
                        url('assets/img/traffic-management.jpg') center/cover no-repeat;
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
                <i class="fas fa-traffic-light"></i>
            </div>
            <h1>Traffic Management</h1>
            <p class="lead">
                Intelligent traffic control systems and management strategies to optimize traffic flow and reduce congestion.
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
                        <h2>Smart Traffic Management System</h2>
                        <p>
                            Our Traffic Management service employs intelligent transportation systems and advanced analytics to optimize traffic flow, 
                            reduce congestion, and enhance road safety. We use real-time data and adaptive control systems to create efficient 
                            traffic patterns throughout the municipality.
                        </p>

                        <h3 class="mt-5">Traffic Control Technologies</h3>
                        
                        <div class="row g-4 mt-3">
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-robot"></i>
                                    </div>
                                    <h4>Adaptive Signal Control</h4>
                                    <p>AI-powered traffic signals that adjust timing based on real-time traffic conditions and demand patterns.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-video"></i>
                                    </div>
                                    <h4>Video Analytics</h4>
                                    <p>Computer vision systems that monitor traffic flow, detect incidents, and analyze vehicle movements.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-satellite-dish"></i>
                                    </div>
                                    <h4>Vehicle Detection</h4>
                                    <p>Advanced sensors and loop detectors that count vehicles, measure speeds, and classify traffic types.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-mobile-alt"></i>
                                    </div>
                                    <h4>Connected Vehicle Systems</h4>
                                    <p>V2I (Vehicle-to-Infrastructure) communication for real-time traffic information and alerts.</p>
                                </div>
                            </div>
                        </div>

                        <h3 class="mt-5">Traffic Management Strategies</h3>
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <h5><i class="fas fa-route text-primary"></i> Route Optimization</h5>
                                <p>Dynamic routing systems that guide drivers through the most efficient paths based on current traffic conditions.</p>
                                
                                <h5 class="mt-4"><i class="fas fa-parking text-primary"></i> Parking Management</h5>
                                <p>Smart parking systems that guide drivers to available spaces and reduce circling traffic.</p>
                            </div>
                            <div class="col-md-6">
                                <h5><i class="fas fa-bus text-primary"></i> Public Transit Priority</h5>
                                <p>Signal preemption systems that give priority to public transportation to improve transit efficiency.</p>
                                
                                <h5 class="mt-4"><i class="fas fa-bicycle text-primary"></i> Sustainable Mobility</h5>
                                <p>Bike lanes, pedestrian-friendly crossings, and multimodal transportation integration.</p>
                            </div>
                        </div>

                        <h3 class="mt-5">Real-Time Traffic Monitoring</h3>
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <div class="alert alert-success">
                                    <h5><i class="fas fa-chart-line"></i> Live Traffic Dashboard</h5>
                                    <p class="mb-0">
                                        Our 24/7 Traffic Control Center monitors traffic conditions across the municipality using:
                                    </p>
                                    <ul class="mt-2 mb-0">
                                        <li>Real-time traffic cameras at major intersections</li>
                                        <li>Speed and volume sensors on arterial roads</li>
                                        <li>GPS data from connected vehicles and public transit</li>
                                        <li>Weather and environmental condition sensors</li>
                                        <li>Mobile app reports from citizens</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <h3 class="mt-5">Incident Management</h3>
                        <p>
                            We provide rapid response to traffic incidents through automated detection and coordinated response protocols:
                        </p>
                        <div class="row mt-3">
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-exclamation-triangle text-danger"></i>
                                    </div>
                                    <h6>Automatic Detection</h6>
                                    <p>AI systems detect accidents, breakdowns, and unusual traffic patterns automatically.</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-bell text-warning"></i>
                                    </div>
                                    <h6>Instant Alerts</h6>
                                    <p>Immediate notifications to emergency services and traffic management teams.</p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="text-center">
                                    <div class="feature-icon">
                                        <i class="fas fa-route text-info"></i>
                                    </div>
                                    <h6>Traffic Diversion</h6>
                                    <p>Automatic rerouting of traffic and dynamic message sign updates.</p>
                                </div>
                            </div>
                        </div>

                        <h3 class="mt-5">Traffic Analytics & Planning</h3>
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <h6>Data Collection</h6>
                                <ul>
                                    <li>Traffic volume and speed measurements</li>
                                    <li>Peak hour analysis and pattern recognition</li>
                                    <li>Origin-destination studies</li>
                                    <li>Travel time reliability metrics</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6>Planning Applications</h6>
                                <ul>
                                    <li>Infrastructure investment decisions</li>
                                    <li>Traffic signal timing optimization</li>
                                    <li>Future traffic demand forecasting</li>
                                    <li>Policy development and evaluation</li>
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

    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
</body>
</html>

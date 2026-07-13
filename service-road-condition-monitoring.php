<?php
/**
 * Road Condition Monitoring Service Detail Page
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
    <title>Road Condition Monitoring - Road and Transportation Department</title>
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
                        url('assets/img/road-monitoring.jpg') center/cover no-repeat;
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
                <i class="fas fa-road"></i>
            </div>
            <h1>Road Condition Monitoring</h1>
            <p class="lead">
                Real-time monitoring of road conditions using advanced sensors and citizen reports to ensure safe travel.
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
                        <h2>Advanced Road Monitoring System</h2>
                        <p>
                            Our Road Condition Monitoring service utilizes cutting-edge technology to continuously assess and report on the state of our road infrastructure. 
                            Through a network of sensors, automated systems, and citizen engagement, we maintain real-time awareness of road conditions across the entire municipality.
                        </p>

                        <h3 class="mt-5">Key Features</h3>
                        
                        <div class="row g-4 mt-3">
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-satellite"></i>
                                    </div>
                                    <h4>Real-time Sensors</h4>
                                    <p>Advanced IoT sensors deployed across major roads monitor traffic flow, surface conditions, and structural integrity 24/7.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-users"></i>
                                    </div>
                                    <h4>Citizen Reports</h4>
                                    <p>Mobile app and web portal allow residents to report road issues instantly with photos and GPS location data.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-chart-line"></i>
                                    </div>
                                    <h4>Predictive Analytics</h4>
                                    <p>AI-powered analysis predicts maintenance needs and identifies potential issues before they become critical problems.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-card">
                                    <div class="feature-icon">
                                        <i class="fas fa-bell"></i>
                                    </div>
                                    <h4>Instant Alerts</h4>
                                    <p>Automatic notifications sent to maintenance teams and the public about road conditions and closures.</p>
                                </div>
                            </div>
                        </div>

                        <h3 class="mt-5">How It Works</h3>
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <ol>
                                    <li class="mb-3">
                                        <strong>Data Collection:</strong> Sensors continuously collect data on traffic volume, speed, road surface temperature, moisture levels, and vibration patterns.
                                    </li>
                                    <li class="mb-3">
                                        <strong>Citizen Input:</strong> Residents report potholes, debris, flooding, and other hazards through our mobile app or website.
                                    </li>
                                    <li class="mb-3">
                                        <strong>AI Analysis:</strong> Machine learning algorithms analyze data patterns to identify anomalies and predict maintenance requirements.
                                    </li>
                                    <li class="mb-3">
                                        <strong>Automated Dispatch:</strong> Issues are automatically categorized and routed to appropriate maintenance teams based on severity and location.
                                    </li>
                                    <li class="mb-3">
                                        <strong>Public Updates:</strong> Real-time information is made available to the public through our website and mobile applications.
                                    </li>
                                </ol>
                            </div>
                        </div>

                        <h3 class="mt-5">Benefits</h3>
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <ul>
                                    <li><i class="fas fa-check text-success"></i> Reduced accident rates through early hazard detection</li>
                                    <li><i class="fas fa-check text-success"></i> Faster response times to road issues</li>
                                    <li><i class="fas fa-check text-success"></i> Cost-effective maintenance planning</li>
                                    <li><i class="fas fa-check text-success"></i> Improved traffic flow and reduced congestion</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <ul>
                                    <li><i class="fas fa-check text-success"></i> Enhanced public safety and satisfaction</li>
                                    <li><i class="fas fa-check text-success"></i> Data-driven infrastructure investment</li>
                                    <li><i class="fas fa-check text-success"></i> Extended road lifespan through preventive maintenance</li>
                                    <li><i class="fas fa-check text-success"></i> Transparent communication with citizens</li>
                                </ul>
                            </div>
                        </div>

                        <div class="text-center mt-5">
                            <h3>Report a Road Issue</h3>
                            <p class="lead">Help us maintain safe roads by reporting any issues you encounter.</p>
                            <a href="<?php echo $basePath; ?>road-updates.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-exclamation-triangle"></i> Report Road Issue
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

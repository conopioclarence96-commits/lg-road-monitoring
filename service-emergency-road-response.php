<?php
/**
 * Emergency Road Response Service Detail Page
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
    <title>Emergency Road Response - Road and Transportation Department</title>
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
            --danger-color: #dc3545;
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
            background: linear-gradient(rgba(220, 53, 69, 0.9), rgba(30, 60, 114, 0.9)),
                        url('assets/img/emergency-response.jpg') center/cover no-repeat;
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

        .emergency-card {
            background: linear-gradient(135deg, #dc3545, #c82333);
            color: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
            transition: transform 0.3s ease;
        }

        .emergency-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(220, 53, 69, 0.4);
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

        .emergency-hotline {
            background: var(--danger-color);
            color: white;
            padding: 20px;
            border-radius: 15px;
            text-align: center;
            font-size: 1.2rem;
            font-weight: bold;
            margin: 20px 0;
            box-shadow: 0 5px 15px rgba(220, 53, 69, 0.3);
        }

        .emergency-hotline .phone-number {
            font-size: 2rem;
            display: block;
            margin-top: 10px;
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
                <i class="fas fa-ambulance"></i>
            </div>
            <h1>Emergency Road Response</h1>
            <p class="lead">
                24/7 emergency response team for road accidents, hazards, and urgent maintenance needs.
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
                        <h2>Rapid Emergency Response System</h2>
                        <p>
                            Our Emergency Road Response service provides immediate assistance for road-related emergencies, accidents, 
                            and hazardous conditions. Our dedicated teams are available 24/7 to ensure rapid response and resolution 
                            of critical situations that affect road safety and traffic flow.
                        </p>

                        <!-- Emergency Hotline -->
                        <div class="emergency-hotline">
                            <i class="fas fa-phone-alt"></i> Emergency Hotline
                            <span class="phone-number">(123) 456-9999</span>
                            Available 24/7 for road emergencies
                        </div>

                        <h3 class="mt-5">Emergency Response Services</h3>
                        
                        <div class="row g-4 mt-3">
                            <div class="col-md-6">
                                <div class="emergency-card">
                                    <div class="feature-icon text-white">
                                        <i class="fas fa-car-crash"></i>
                                    </div>
                                    <h4 class="text-white">Accident Response</h4>
                                    <p>Immediate deployment to traffic accidents with scene management, debris clearance, and traffic diversion.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="emergency-card">
                                    <div class="feature-icon text-white">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <h4 class="text-white">Hazard Removal</h4>
                                    <p>Rapid response to road hazards including fallen trees, spills, debris, and dangerous road conditions.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="emergency-card">
                                    <div class="feature-icon text-white">
                                        <i class="fas fa-water"></i>
                                    </div>
                                    <h4 class="text-white">Flood Response</h4>
                                    <p>Emergency drainage clearing, road closure management, and water pumping during flooding events.</p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="emergency-card">
                                    <div class="feature-icon text-white">
                                        <i class="fas fa-tools"></i>
                                    </div>
                                    <h4 class="text-white">Urgent Repairs</h4>
                                    <p>Emergency road repairs for critical infrastructure failures including bridge issues and road collapses.</p>
                                </div>
                            </div>
                        </div>

                        <h3 class="mt-5">Response Time Commitments</h3>
                        <div class="row mt-4">
                            <div class="col-md-12">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-danger">
                                            <tr>
                                                <th>Emergency Type</th>
                                                <th>Response Time</th>
                                                <th>Action Required</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td><strong>Life-threatening Situations</strong></td>
                                                <td>< 5 minutes</td>
                                                <td>Immediate dispatch with emergency services coordination</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Major Road Blockages</strong></td>
                                                <td>< 15 minutes</td>
                                                <td>Rapid assessment and traffic diversion setup</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Traffic Accidents</strong></td>
                                                <td>< 10 minutes</td>
                                                <td>Scene management and debris clearance</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Flooding Incidents</strong></td>
                                                <td>< 20 minutes</td>
                                                <td>Drainage clearing and road closure if necessary</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Infrastructure Failures</strong></td>
                                                <td>< 30 minutes</td>
                                                <td>Safety assessment and emergency repairs</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <h3 class="mt-5">Emergency Equipment & Resources</h3>
                        <div class="row mt-4">
                            <div class="col-md-6">
                                <h5><i class="fas fa-truck text-danger"></i> Response Vehicles</h5>
                                <ul>
                                    <li>Emergency repair trucks with heavy equipment</li>
                                    <li>Traffic management vehicles with signs and barriers</li>
                                    <li>Debris removal and cleanup equipment</li>
                                    <li>Mobile lighting and power generators</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h5><i class="fas fa-hard-hat text-danger"></i> Specialized Teams</h5>
                                <ul>
                                    <li>Certified emergency response technicians</li>
                                    <li>Traffic control and management specialists</li>
                                    <li>Heavy equipment operators</li>
                                    <li>Safety and incident commanders</li>
                                </ul>
                            </div>
                        </div>

                        <h3 class="mt-5">Emergency Reporting Guidelines</h3>
                        <div class="alert alert-warning mt-4">
                            <h5><i class="fas fa-info-circle"></i> How to Report Road Emergencies</h5>
                            <p class="mb-2"><strong>When calling the emergency hotline, provide:</strong></p>
                            <ul>
                                <li>Your exact location or nearest landmark</li>
                                <li>Type of emergency (accident, hazard, flood, etc.)</li>
                                <li>Number of vehicles involved (if accident)</li>
                                <li>Any injuries or immediate dangers</li>
                                <li>Your contact information</li>
                            </ul>
                            <p class="mb-0 mt-3"><strong>Stay safe:</strong> Keep your distance from the emergency scene and follow instructions from emergency personnel.</p>
                        </div>

                        <h3 class="mt-5">Coordination with Emergency Services</h3>
                        <p>
                            Our Emergency Road Response team works in close coordination with police, fire departments, 
                            medical services, and other emergency responders to ensure comprehensive emergency management. 
                            We maintain direct communication channels with all emergency services for seamless incident response.
                        </p>

                        <div class="row mt-4">
                            <div class="col-md-3 col-6 text-center">
                                <div class="feature-icon">
                                    <i class="fas fa-shield-alt text-primary"></i>
                                </div>
                                <h6>Police Coordination</h6>
                            </div>
                            <div class="col-md-3 col-6 text-center">
                                <div class="feature-icon">
                                    <i class="fas fa-fire-extinguisher text-danger"></i>
                                </div>
                                <h6>Fire Department</h6>
                            </div>
                            <div class="col-md-3 col-6 text-center">
                                <div class="feature-icon">
                                    <i class="fas fa-ambulance text-success"></i>
                                </div>
                                <h6>Medical Services</h6>
                            </div>
                            <div class="col-md-3 col-6 text-center">
                                <div class="feature-icon">
                                    <i class="fas fa-hospital text-info"></i>
                                </div>
                                <h6>Hospitals</h6>
                            </div>
                        </div>

                        <div class="text-center mt-5">
                            <h3>Report Road Emergency</h3>
                            <p class="lead">For immediate assistance with road-related emergencies.</p>
                            <a href="tel:1234569999" class="btn btn-danger btn-lg me-3">
                                <i class="fas fa-phone-alt"></i> Call Emergency Hotline
                            </a>
                            <a href="<?php echo $basePath; ?>contact.php" class="btn btn-primary btn-lg">
                                <i class="fas fa-envelope"></i> Other Contact Methods
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
    <div class="page-transition-overlay" id="pageTransitionOverlay">
        <div class="transition-content">
            <div class="transition-spinner"><i class="fas fa-spinner"></i></div>
            <div class="transition-text">Loading...</div>
        </div>
    </div>
    <script src="lgu_staff/js/page-transition.js"></script>
    <?php include __DIR__ . '/includes/a11y_js.php'; ?>
</body>
</html>

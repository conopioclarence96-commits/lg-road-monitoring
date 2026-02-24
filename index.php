<?php
/**
 * Main domain root file - redirects to appropriate page
 */

// Check if we should show the home page
$showHome = true;
if (isset($_GET['login']) || isset($_GET['register']) || isset($_GET['public'])) {
    $showHome = false;
}

// If showing home page, include it directly
if ($showHome) {
    $homePagePath = __DIR__ . '/lgu-portal/public/login.php';
    if (file_exists($homePagePath)) {
        require_once $homePagePath;
        exit();
    }
}

// Otherwise, continue with the original landing page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Road &amp; Transportation | LGU</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        html { scroll-behavior: smooth; }
        body {
            min-height: 100vh;
            background: url("assets/img/cityhall.jpeg") center/cover no-repeat fixed;
            position: relative;
        }
        body::before {
            content: "";
            position: absolute;
            inset: 0;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            background: rgba(0, 0, 0, 0.35);
            z-index: 0;
        }
        .landing {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 40px 24px 60px;
        }
        .landing-hero {
            text-align: center;
            max-width: 720px;
            margin-bottom: 48px;
        }
        .landing-hero h1 {
            font-size: clamp(28px, 5vw, 42px);
            font-weight: 700;
            color: #fff;
            text-shadow: 0 2px 20px rgba(0,0,0,0.3);
            margin-bottom: 16px;
            line-height: 1.2;
        }
        .landing-hero p {
            font-size: 18px;
            color: rgba(255,255,255,0.92);
            text-shadow: 0 1px 8px rgba(0,0,0,0.2);
            line-height: 1.5;
        }
        .btn-landing {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 28px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            text-decoration: none;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn-landing-primary {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff;
            border: none;
            box-shadow: 0 4px 20px rgba(55, 98, 200, 0.4);
        }
        .btn-landing-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 28px rgba(55, 98, 200, 0.5);
            color: #fff;
        }
        .btn-landing-secondary {
            background: rgba(255,255,255,0.95);
            color: #1e3c72;
            border: 2px solid rgba(255,255,255,0.8);
            backdrop-filter: blur(10px);
        }
        .btn-landing-secondary:hover {
            background: #fff;
            color: #1e3c72;
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(0,0,0,0.15);
        }
        /* Public Transparency card on landing */
        .transparency-card {
            width: 100%;
            max-width: 560px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255,255,255,0.3);
        }
        .transparency-card h2 {
            font-size: 22px;
            font-weight: 700;
            color: #1e3c72;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .transparency-card h2 i { color: #3762c8; }
        .transparency-card p {
            color: #555;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .transparency-card .btn-landing {
            width: 100%;
            justify-content: center;
        }
        .staff-login-link {
            margin-top: 40px;
            text-align: center;
        }
        .staff-login-link a {
            color: rgba(255,255,255,0.9);
            font-size: 14px;
            text-decoration: none;
        }
        .staff-login-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="landing">
        <div class="landing-hero">
            <h1>Road &amp; Transportation Monitoring</h1>
            <p>Local Government Unit – transparent information on road projects, maintenance, and public reports.</p>
        </div>

        <section id="public-transparency" class="transparency-card" aria-label="Public Transparency">
            <h2><i class="fas fa-university"></i> Public Transparency</h2>
            <p>Access budget information, project status, and recent completed road projects published by the LGU.</p>
            <a href="public_transparency_view.php" class="btn-landing btn-landing-primary">
                <i class="fas fa-external-link-alt"></i>
                Open Public Transparency
            </a>
        </section>

        <div class="staff-login-link">
            <a href="lgu_staff/login.php">LGU Staff Login</a>
        </div>
    </div>
</body>
</html>

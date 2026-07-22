<?php

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'send_otp':
        handleSendOtp();
        break;
    case 'verify_otp':
        handleVerifyOtp();
        break;
    case 'submit_report':
        handleSubmitReport();
        break;
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function handleSendOtp() {
    $email = trim($_POST['email'] ?? '');

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
        return;
    }
    if (!str_ends_with(strtolower($email), '@gmail.com')) {
        echo json_encode(['success' => false, 'message' => 'Please use a Gmail address (@gmail.com) for verification.']);
        return;
    }

    $today = date('Y-m-d');
    $reportData = $_SESSION['citizen_reports'][$email] ?? ['date' => '', 'count' => 0];

    if ($reportData['date'] === $today && $reportData['count'] >= 2) {
        echo json_encode(['success' => false, 'message' => 'You have reached the maximum of 2 reports per day. Please try again tomorrow.']);
        return;
    }

    $_SESSION['citizen_report_email'] = $email;

    $otpCode = generate_otp(6);
    store_otp($email, $otpCode, 'citizen_report');
    send_otp_to_email($email, $otpCode);

    echo json_encode(['success' => true, 'message' => 'Verification code sent to your email.']);
}

function handleVerifyOtp() {
    $otp = trim($_POST['otp'] ?? '');

    if (empty($otp)) {
        echo json_encode(['success' => false, 'message' => 'Please enter the verification code.']);
        return;
    }

    $result = verify_otp_code($otp, 'citizen_report');

    if ($result['success']) {
        $_SESSION['citizen_report_verified'] = true;
    }

    echo json_encode($result);
}

function handleSubmitReport() {
    if (empty($_SESSION['citizen_report_verified'])) {
        echo json_encode(['success' => false, 'message' => 'Email not verified. Please verify your email first.']);
        return;
    }

    $email = $_SESSION['citizen_report_email'] ?? '';
    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Session expired. Please start again.']);
        return;
    }

    $today = date('Y-m-d');
    $reportData = $_SESSION['citizen_reports'][$email] ?? ['date' => '', 'count' => 0];
    if ($reportData['date'] === $today && $reportData['count'] >= 2) {
        echo json_encode(['success' => false, 'message' => 'You have reached the maximum of 2 reports per day.']);
        return;
    }

    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $issueType = trim($_POST['issue_type'] ?? '');
    $severity = trim($_POST['severity'] ?? 'medium');
    $reporterName = trim($_POST['reporter_name'] ?? '');
    $reporterPhone = trim($_POST['phone'] ?? '');

    if (empty($latitude) || empty($longitude)) {
        echo json_encode(['success' => false, 'message' => 'Please pin a location on the map.']);
        return;
    }

    // Validate coordinates are within Quezon City boundary
    $qcGeoJson = json_decode(file_get_contents(__DIR__ . '/qc_boundary.json'), true);
    if (!isInsideQC($latitude, $longitude, $qcGeoJson)) {
        echo json_encode(['success' => false, 'message' => 'Reports can only be submitted within Quezon City.']);
        return;
    }
    if (empty($issueType)) {
        echo json_encode(['success' => false, 'message' => 'Please select an issue type.']);
        return;
    }
    if (empty($severity)) {
        echo json_encode(['success' => false, 'message' => 'Please select a severity level.']);
        return;
    }
    if (empty($reporterName)) {
        echo json_encode(['success' => false, 'message' => 'Please enter your full name.']);
        return;
    }
    if (empty($reporterPhone) || !preg_match('/^[0-9]{11,}$/', $reporterPhone)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid phone number (at least 11 digits).']);
        return;
    }
    if (empty($description)) {
        echo json_encode(['success' => false, 'message' => 'Please describe the issue.']);
        return;
    }

    $validTypes = ['traffic_jam', 'accident', 'road_closure', 'traffic_light_outage', 'congestion', 'parking_violation', 'public_transport_issue'];
    if (!in_array($issueType, $validTypes)) {
        echo json_encode(['success' => false, 'message' => 'Please select a valid issue type.']);
        return;
    }

    $severityMap = ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'severe' => 'critical'];
    $severity = $severityMap[$severity] ?? 'medium';
    $priority = ($severity === 'critical' || $severity === 'high') ? 'high' : ($severity === 'medium' ? 'medium' : 'low');

    $title = ucfirst(str_replace('_', ' ', $issueType)) . ' issue at pinned location';
    $reportId = 'CIT-' . date('Ymd-His') . '-' . substr(uniqid(), -5);

    $attachments = [];
    $imagePath = null;

    if (empty($_FILES['photos']['name'][0])) {
        echo json_encode(['success' => false, 'message' => 'Please upload at least one photo.']);
        return;
    }

    $uploadDir = __DIR__ . '/../../uploads/report_images';
    $allowed = ['jpg', 'jpeg', 'png'];

    $totalFiles = count($_FILES['photos']['name']);
    for ($i = 0; $i < $totalFiles; $i++) {
        $file = [
            'name' => $_FILES['photos']['name'][$i],
            'type' => $_FILES['photos']['type'][$i],
            'tmp_name' => $_FILES['photos']['tmp_name'][$i],
            'error' => $_FILES['photos']['error'][$i],
            'size' => $_FILES['photos']['size'][$i],
        ];

        $result = handle_file_upload($file, $uploadDir, $allowed);
        if ($result['success']) {
            $entry = [
                'filename' => $result['filename'],
                'file_path' => 'uploads/report_images/' . $result['filename'],
                'type' => 'image',
            ];
            $attachments[] = $entry;
            if ($imagePath === null) {
                $imagePath = 'uploads/report_images/' . $result['filename'];
            }
        }
    }

    global $conn;
    try {
        try {
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS reporter_name VARCHAR(100) AFTER reporter_email");
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS reporter_phone VARCHAR(20) AFTER reporter_name");
        } catch (Exception $e) {}

        $stmt = $conn->prepare("INSERT INTO road_transportation_reports 
            (report_id, report_type, report_category, report_source, title, description, 
             latitude, longitude, location, severity, priority, status, created_date, 
             reporter_email, reporter_name, reporter_phone, attachments, image_path, created_by)
            VALUES (?, ?, 'transportation', 'local', ?, ?, ?, ?, ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, 0)");

        $location = $_POST['address'] ?? 'Pinned location';
        $attachmentsJson = json_encode($attachments);
        $stmt->bind_param('sssssssssssssss',
            $reportId,
            $issueType,
            $title,
            $description,
            $latitude,
            $longitude,
            $location,
            $severity,
            $priority,
            $email,
            $reporterName,
            $reporterPhone,
            $attachmentsJson,
            $imagePath
        );

        if ($stmt->execute()) {
            if ($reportData['date'] !== $today) {
                $_SESSION['citizen_reports'][$email] = ['date' => $today, 'count' => 1];
            } else {
                $_SESSION['citizen_reports'][$email]['count']++;
            }

            unset($_SESSION['citizen_report_verified']);
            unset($_SESSION['citizen_report_email']);

            echo json_encode(['success' => true, 'message' => 'Report submitted successfully!', 'redirect_url' => 'verification_monitoring.php']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to submit report. Please try again.']);
        }

        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

function isInsideQC($lat, $lng, $geoJson) {
    if (empty($geoJson) || !isset($geoJson['coordinates'])) return false;

    $rings = $geoJson['coordinates'][0];
    if (empty($rings)) return false;

    $x = (float)$lng;
    $y = (float)$lat;
    $inside = false;

    foreach ($rings as $ring) {
        $n = count($ring);
        $j = $n - 1;
        for ($i = 0; $i < $n; $i++) {
            $xi = $ring[$i][0];
            $yi = $ring[$i][1];
            $xj = $ring[$j][0];
            $yj = $ring[$j][1];

            if (($yi > $y) !== ($yj > $y) && $x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi) {
                $inside = !$inside;
            }
            $j = $i;
        }
    }

    return $inside;
}

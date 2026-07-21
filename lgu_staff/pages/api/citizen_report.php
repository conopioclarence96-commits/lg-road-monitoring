<?php
/**
 * Citizen Report API - handles OTP verification and report submission
 */

// Session settings
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

    // Check rate limit: 2 reports per day per email
    $today = date('Y-m-d');
    $reportData = $_SESSION['citizen_reports'][$email] ?? ['date' => '', 'count' => 0];

    if ($reportData['date'] === $today && $reportData['count'] >= 2) {
        echo json_encode(['success' => false, 'message' => 'You have reached the maximum of 2 reports per day. Please try again tomorrow.']);
        return;
    }

    // Store email in session for the report flow
    $_SESSION['citizen_report_email'] = $email;

    // Generate and send OTP
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

    // Rate limit check again
    $today = date('Y-m-d');
    $reportData = $_SESSION['citizen_reports'][$email] ?? ['date' => '', 'count' => 0];
    if ($reportData['date'] === $today && $reportData['count'] >= 2) {
        echo json_encode(['success' => false, 'message' => 'You have reached the maximum of 2 reports per day.']);
        return;
    }

    // Validate fields
    $latitude = trim($_POST['latitude'] ?? '');
    $longitude = trim($_POST['longitude'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $issueType = trim($_POST['issue_type'] ?? '');
    $severity = trim($_POST['severity'] ?? 'medium');

    if (empty($latitude) || empty($longitude)) {
        echo json_encode(['success' => false, 'message' => 'Please pin a location on the map.']);
        return;
    }
    if (empty($description)) {
        echo json_encode(['success' => false, 'message' => 'Please describe the issue.']);
        return;
    }

    $validTypes = ['traffic_jam', 'accident', 'road_closure', 'traffic_light_outage', 'congestion', 'parking_violation', 'public_transport_issue'];
    if (!in_array($issueType, $validTypes)) {
        $issueType = 'traffic_jam';
    }

    $severityMap = ['low' => 'low', 'medium' => 'medium', 'high' => 'high', 'severe' => 'critical'];
    $severity = $severityMap[$severity] ?? 'medium';
    $priority = ($severity === 'critical' || $severity === 'high') ? 'high' : ($severity === 'medium' ? 'medium' : 'low');

    $title = ucfirst(str_replace('_', ' ', $issueType)) . ' issue at pinned location';
    $reportId = 'CIT-' . date('Ymd-His') . '-' . substr(uniqid(), -5);

    // Handle photo upload
    $attachments = [];
    $imagePath = null;

    if (!empty($_FILES['photos']['name'][0])) {
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
                    'file_path' => $result['filepath'],
                    'type' => 'image',
                ];
                $attachments[] = $entry;
                if ($imagePath === null) {
                    $imagePath = $result['filepath'];
                }
            }
        }
    }

    // Insert into database
    global $conn;
    try {
        $stmt = $conn->prepare("INSERT INTO road_transportation_reports 
            (report_id, report_type, report_category, report_source, title, description, 
             latitude, longitude, location, severity, priority, status, created_date, 
             reporter_email, attachments, image_path, created_by)
            VALUES (?, ?, 'transportation', 'local', ?, ?, ?, ?, ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, 0)");

        $location = $_POST['address'] ?? 'Pinned location';
        $attachmentsJson = json_encode($attachments);
        $stmt->bind_param('ssssssssssss',
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
            $attachmentsJson,
            $imagePath
        );

        if ($stmt->execute()) {
            // Update rate limit
            if ($reportData['date'] !== $today) {
                $_SESSION['citizen_reports'][$email] = ['date' => $today, 'count' => 1];
            } else {
                $_SESSION['citizen_reports'][$email]['count']++;
            }

            // Clear session verification
            unset($_SESSION['citizen_report_verified']);
            unset($_SESSION['citizen_report_email']);

            echo json_encode(['success' => true, 'message' => 'Report submitted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to submit report. Please try again.']);
        }

        $stmt->close();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}

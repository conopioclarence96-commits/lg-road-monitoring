<?php

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

// CSRF protection: every action on this endpoint must carry a token matching
// the one stored in the session (generated when the landing page renders).
$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($csrf_token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please refresh the page and try again.']);
    exit;
}

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
    // Normalize phone: convert +639... to 09...
    $reporterPhone = trim(preg_replace('/\s+/', '', $reporterPhone));
    if (preg_match('/^\+639([0-9]{9})$/', $reporterPhone, $m)) {
        $reporterPhone = '09' . $m[1];
    }
    if (empty($reporterPhone) || !preg_match('/^09[0-9]{9}$/', $reporterPhone)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid Philippine mobile number.']);
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

    if (empty($_FILES['photos']['name'][0]) || count($_FILES['photos']['name']) < 2) {
        echo json_encode(['success' => false, 'message' => 'Please upload at least 2 photos before submitting your report.']);
        return;
    }

    $uploadDir = __DIR__ . '/../../uploads/report_images';
    $allowed = ['jpg', 'jpeg', 'png'];

    $totalFiles = count($_FILES['photos']['name']);
    $uploadErrors = [];
    // Content fingerprints seen in this submission — same photo bytes must only
    // be stored once per report (duplicate picks of the same file are skipped).
    $seenPhotoHashes = [];
    for ($i = 0; $i < $totalFiles; $i++) {
        $name = $_FILES['photos']['name'][$i] ?? '';
        if ($name === '' || $name === null) {
            continue;
        }
        $tmpName = $_FILES['photos']['tmp_name'][$i] ?? '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            continue;
        }

        // Skip identical file content already processed for this report.
        $contentHash = @md5_file($tmpName);
        if ($contentHash !== false && isset($seenPhotoHashes[$contentHash])) {
            continue;
        }

        $file = [
            'name' => $name,
            'type' => $_FILES['photos']['type'][$i],
            'tmp_name' => $tmpName,
            'error' => $_FILES['photos']['error'][$i],
            'size' => $_FILES['photos']['size'][$i],
        ];

        $result = handle_file_upload($file, $uploadDir, $allowed);
        if ($result['success']) {
            if ($contentHash !== false) {
                $seenPhotoHashes[$contentHash] = true;
            }
            $entry = [
                'filename' => $result['filename'],
                'file_path' => 'uploads/report_images/' . $result['filename'],
                'type' => 'image',
            ];
            $attachments[] = $entry;
            if ($imagePath === null) {
                $imagePath = 'uploads/report_images/' . $result['filename'];
            }
        } else {
            $uploadErrors[] = $result['error'];
        }
    }

    if (count($attachments) < 2) {
        // Remove any partial uploads so orphaned files are not left behind.
        foreach ($attachments as $a) {
            $partial = $uploadDir . '/' . $a['filename'];
            if (file_exists($partial)) @unlink($partial);
        }
        $detail = $uploadErrors ? ' ' . implode(' ', array_unique($uploadErrors)) : '';
        echo json_encode(['success' => false, 'message' => 'Please upload at least 2 valid photos before submitting your report.' . $detail]);
        return;
    }

    global $conn;
    try {
        try {
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS reporter_name VARCHAR(100) AFTER reporter_email");
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS reporter_phone VARCHAR(20) AFTER reporter_name");
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS detected_district VARCHAR(50) NULL AFTER longitude");
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS barangay VARCHAR(100) NULL AFTER detected_district");
            $conn->query("ALTER TABLE road_transportation_reports ADD COLUMN IF NOT EXISTS street_name VARCHAR(255) NULL AFTER barangay");
        } catch (Exception $e) {
            error_log("citizen_report column migration: " . $e->getMessage());
        }

        $detected_district = sanitize_input($_POST['detected_district'] ?? '');
        $barangay = sanitize_input($_POST['barangay'] ?? '');
        $street_name = sanitize_input($_POST['street_name'] ?? '');
        $lat = (float)$latitude;
        $lng = (float)$longitude;

        // Server-side district detection (authoritative override of client value)
        $districts_geojson_path = __DIR__ . '/qc_districts.geojson';
        if (file_exists($districts_geojson_path)) {
            $geojson_raw = file_get_contents($districts_geojson_path);
            $geojson_data = json_decode($geojson_raw, true);
            if ($geojson_data && isset($geojson_data['features'])) {
                $server_detected_district = '';
                $best_dist = INF;
                $best_match = '';
                foreach ($geojson_data['features'] as $feature) {
                    $coords = $feature['geometry']['coordinates'] ?? [];
                    $geom_type = $feature['geometry']['type'] ?? '';
                    $matched = false;
                    if ($geom_type === 'Polygon' && !empty($coords)) {
                        $matched = citizen_point_in_polygon($lat, $lng, $coords);
                    } elseif ($geom_type === 'MultiPolygon' && !empty($coords)) {
                        foreach ($coords as $poly) {
                            if (citizen_point_in_polygon($lat, $lng, $poly)) {
                                $matched = true;
                                break;
                            }
                        }
                    }
                    if ($matched) {
                        $server_detected_district = sanitize_input($feature['properties']['district'] ?? $feature['properties']['district_name'] ?? '');
                        break;
                    }
                    $ring = $geom_type === 'Polygon' ? ($coords[0] ?? []) : ($coords[0][0] ?? []);
                    $cnt = count($ring);
                    if ($cnt > 0) {
                        $slng = 0; $slat = 0;
                        foreach ($ring as $c) { $slng += $c[0]; $slat += $c[1]; }
                        $clng = $slng / $cnt; $clat = $slat / $cnt;
                        $dx = $lng - $clng; $dy = $lat - $clat;
                        $dist = $dx * $dx + $dy * $dy;
                        if ($dist < $best_dist) {
                            $best_dist = $dist;
                            $best_match = sanitize_input($feature['properties']['district'] ?? $feature['properties']['district_name'] ?? '');
                        }
                    }
                }
                if ($server_detected_district === '') {
                    $server_detected_district = $best_match;
                }
                $detected_district = $server_detected_district !== '' ? $server_detected_district : '';
            }
        }

        $location = trim($_POST['address'] ?? '');
        if ($location === '') {
            $location_parts = array_filter([$street_name, $barangay, $detected_district]);
            $location = $location_parts ? implode(', ', $location_parts) : 'Pinned location';
        }

        $stmt = $conn->prepare("INSERT INTO road_transportation_reports 
            (report_id, report_type, report_category, report_source, title, description, 
             latitude, longitude, location, detected_district, barangay, street_name,
             severity, priority, status, created_date, 
             reporter_email, reporter_name, reporter_phone, attachments, image_path, created_by)
            VALUES (?, ?, 'transportation', 'local', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', CURDATE(), ?, ?, ?, ?, ?, 0)");

        $attachmentsJson = json_encode($attachments);
        $stmt->bind_param('ssssddsssssssssss',
            $reportId,
            $issueType,
            $title,
            $description,
            $lat,
            $lng,
            $location,
            $detected_district,
            $barangay,
            $street_name,
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

            echo json_encode(['success' => true, 'message' => 'Report submitted successfully!']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to submit report. Please try again.']);
        }

        $stmt->close();
    } catch (Exception $e) {
        error_log("citizen_report submit_report insert error: " . $e->getMessage());
        // Clean up uploaded files so a failed submission does not leave orphans.
        foreach ($attachments as $a) {
            $orphan = $uploadDir . '/' . $a['filename'];
            if (file_exists($orphan)) @unlink($orphan);
        }
        echo json_encode(['success' => false, 'message' => 'We could not submit your report due to a temporary issue. Please try again later.']);
    }
}

function citizen_point_in_polygon($lat, $lng, $coords) {
    $ring = $coords[0] ?? [];
    $n = count($ring);
    if ($n < 3) return false;
    $inside = false;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $ring[$i][1]; $yi = $ring[$i][0];
        $xj = $ring[$j][1]; $yj = $ring[$j][0];
        if ((($yi > $lng) !== ($yj > $lng)) && ($lat < ($xj - $xi) * ($lng - $yi) / ($yj - $yi) + $xi)) {
            $inside = !$inside;
        }
    }
    return $inside;
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

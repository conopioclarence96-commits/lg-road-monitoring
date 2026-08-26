<?php
/**
 * Overall service feedback API (citizen_service_feedback).
 *
 * GET  ?action=status
 * POST action=submit  (rating, comment?, voter_token, page_url?)
 */

header('Content-Type: application/json; charset=utf-8');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

const CSF_COOKIE = 'pr_voter_token';
const CSF_COOKIE_MAX_AGE = 63072000;
const CSF_IP_MAX = 3;

function csf_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function csf_set_voter_cookie($token) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(CSF_COOKIE, $token, [
        'expires' => time() + CSF_COOKIE_MAX_AGE,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[CSF_COOKIE] = $token;
}

function csf_ensure_voter_token() {
    $token = $_COOKIE[CSF_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        csf_set_voter_cookie($token);
    }
    return $token;
}

function csf_ensure_table($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS citizen_service_feedback (
        id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        rating TINYINT NOT NULL,
        comment VARCHAR(500) NULL DEFAULT NULL,
        voter_token CHAR(64) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent VARCHAR(255) NULL DEFAULT NULL,
        page_url VARCHAR(500) NULL DEFAULT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_voter_token (voter_token),
        KEY idx_ip_address (ip_address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function csf_json($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (!$conn) {
    csf_json(['success' => false, 'message' => 'Database unavailable'], 500);
}

csf_ensure_table($conn);
$voterToken = csf_ensure_voter_token();
$ip = csf_client_ip();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'status') {
    $rated = false;
    $myRating = null;
    $stmt = $conn->prepare('SELECT rating FROM citizen_service_feedback WHERE voter_token = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('s', $voterToken);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($row = $res->fetch_assoc())) {
            $rated = true;
            $myRating = (int)$row['rating'];
        }
        $stmt->close();
    }

    $ipBlocked = false;
    $ipStmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM citizen_service_feedback WHERE ip_address = ?');
    if ($ipStmt) {
        $ipStmt->bind_param('s', $ip);
        $ipStmt->execute();
        $ipRes = $ipStmt->get_result();
        $ipRow = $ipRes ? $ipRes->fetch_assoc() : null;
        $ipStmt->close();
        if ((int)($ipRow['cnt'] ?? 0) >= CSF_IP_MAX) {
            $ipBlocked = true;
        }
    }

    csf_json([
        'success' => true,
        'voter_token' => $voterToken,
        'rated' => $rated,
        'my_rating' => $myRating,
        'ip_blocked' => $ipBlocked && !$rated,
    ]);
}

if ($action === 'submit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = trim((string)($_POST['voter_token'] ?? ''));
    if ($postedToken === '' || !hash_equals($voterToken, $postedToken)) {
        csf_json(['success' => false, 'code' => 'token_mismatch', 'message' => 'Invalid session. Please refresh and try again.'], 403);
    }

    $rating = (int)($_POST['rating'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));
    $comment = $comment !== '' ? mb_substr(strip_tags($comment), 0, 500) : '';
    $pageUrl = trim((string)($_POST['page_url'] ?? ''));
    $pageUrl = $pageUrl !== '' ? mb_substr(strip_tags($pageUrl), 0, 500) : null;

    if ($rating < 1 || $rating > 5) {
        csf_json(['success' => false, 'message' => 'Please select a rating from 1 to 5 stars.'], 400);
    }

    $chk = $conn->prepare('SELECT id FROM citizen_service_feedback WHERE voter_token = ? LIMIT 1');
    $chk->bind_param('s', $voterToken);
    $chk->execute();
    $chkRes = $chk->get_result();
    if ($chkRes && $chkRes->fetch_assoc()) {
        $chk->close();
        csf_json([
            'success' => false,
            'code' => 'already_rated',
            'message' => 'You have already submitted feedback. Thank you!',
        ], 409);
    }
    $chk->close();

    $ipStmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM citizen_service_feedback WHERE ip_address = ?');
    $ipStmt->bind_param('s', $ip);
    $ipStmt->execute();
    $ipRes = $ipStmt->get_result();
    $ipRow = $ipRes ? $ipRes->fetch_assoc() : null;
    $ipStmt->close();
    if ((int)($ipRow['cnt'] ?? 0) >= CSF_IP_MAX) {
        csf_json([
            'success' => false,
            'code' => 'spam_limited',
            'message' => 'Rating limit reached on this network. Thanks for your feedback!',
        ], 429);
    }

    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $pageUrlDb = $pageUrl ?? '';
    $ins = $conn->prepare('INSERT INTO citizen_service_feedback (rating, comment, voter_token, ip_address, user_agent, page_url)
        VALUES (?, ?, ?, ?, ?, ?)');
    $ins->bind_param('isssss', $rating, $comment, $voterToken, $ip, $ua, $pageUrlDb);

    try {
        if (!$ins->execute()) {
            $ins->close();
            csf_json(['success' => false, 'message' => 'Failed to save feedback. Please try again.'], 500);
        }
    } catch (Throwable $e) {
        $ins->close();
        if ($conn->errno === 1062) {
            csf_json([
                'success' => false,
                'code' => 'already_rated',
                'message' => 'You have already submitted feedback. Thank you!',
            ], 409);
        }
        error_log('citizen_service_feedback_api insert: ' . $e->getMessage());
        csf_json(['success' => false, 'message' => 'Failed to save feedback. Please try again.'], 500);
    }
    $ins->close();

    csf_json([
        'success' => true,
        'message' => 'Thank you for your feedback!',
        'my_rating' => $rating,
    ]);
}

csf_json(['success' => false, 'message' => 'Invalid action'], 400);

<?php
/**
 * Public project ratings API (citizen_report_feedback table).
 *
 * GET  ?action=summary&ids=1,2,3
 * POST action=submit_rating  (project_id, rating, comment?, voter_token)
 */

header('Content-Type: application/json; charset=utf-8');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

const PR_COOKIE = 'pr_voter_token';
const PR_COOKIE_MAX_AGE = 63072000; // ~2 years
const PR_IP_MAX_PER_PROJECT = 3;

function pr_client_ip() {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function pr_set_voter_cookie($token) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(PR_COOKIE, $token, [
        'expires' => time() + PR_COOKIE_MAX_AGE,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[PR_COOKIE] = $token;
}

function pr_ensure_voter_token() {
    $token = $_COOKIE[PR_COOKIE] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        $token = bin2hex(random_bytes(32));
        pr_set_voter_cookie($token);
    }
    return $token;
}

function pr_ensure_table($conn) {
    $newExists = $conn->query("SHOW TABLES LIKE 'citizen_report_feedback'");
    $hasNew = $newExists && $newExists->num_rows > 0;

    if (!$hasNew) {
        $oldExists = $conn->query("SHOW TABLES LIKE 'citizen_feedback'");
        if ($oldExists && $oldExists->num_rows > 0) {
            $col = $conn->query("SHOW COLUMNS FROM citizen_feedback LIKE 'voter_token'");
            if ($col && $col->num_rows > 0) {
                $conn->query('RENAME TABLE citizen_feedback TO citizen_report_feedback');
                $hasNew = true;
            } else {
                $conn->query('DROP TABLE IF EXISTS citizen_feedback');
            }
        }
    }

    if ($hasNew) {
        $col = $conn->query("SHOW COLUMNS FROM citizen_report_feedback LIKE 'voter_token'");
        if ($col && $col->num_rows > 0) {
            return;
        }
        $conn->query('DROP TABLE IF EXISTS citizen_report_feedback');
    }

    $conn->query("CREATE TABLE IF NOT EXISTS citizen_report_feedback (
        id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
        project_id INT(11) UNSIGNED NOT NULL,
        rating TINYINT NOT NULL,
        comment VARCHAR(500) NULL DEFAULT NULL,
        voter_token CHAR(64) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        user_agent VARCHAR(255) NULL DEFAULT NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_project_voter (project_id, voter_token),
        KEY idx_project_ip (project_id, ip_address)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function pr_json($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (!$conn) {
    pr_json(['success' => false, 'message' => 'Database unavailable'], 500);
}

pr_ensure_table($conn);
$voterToken = pr_ensure_voter_token();
$ip = pr_client_ip();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'summary') {
    $idsRaw = $_GET['ids'] ?? '';
    $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsRaw)), function ($id) {
        return $id > 0;
    })));

    $projects = [];
    foreach ($ids as $id) {
        $projects[(string)$id] = [
            'project_id' => $id,
            'average' => 0,
            'count' => 0,
            'rated' => false,
            'my_rating' => null,
            'ip_blocked' => false,
        ];
    }

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $stmt = $conn->prepare("SELECT project_id, AVG(rating) AS avg_rating, COUNT(*) AS cnt
            FROM citizen_report_feedback WHERE project_id IN ($placeholders) GROUP BY project_id");
        if ($stmt) {
            $stmt->bind_param($types, ...$ids);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $pid = (string)(int)$row['project_id'];
                if (isset($projects[$pid])) {
                    $projects[$pid]['average'] = round((float)$row['avg_rating'], 1);
                    $projects[$pid]['count'] = (int)$row['cnt'];
                }
            }
            $stmt->close();
        }

        $stmt = $conn->prepare("SELECT project_id, rating FROM citizen_report_feedback
            WHERE voter_token = ? AND project_id IN ($placeholders)");
        if ($stmt) {
            $bindTypes = 's' . $types;
            $bindParams = array_merge([$voterToken], $ids);
            $stmt->bind_param($bindTypes, ...$bindParams);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $pid = (string)(int)$row['project_id'];
                if (isset($projects[$pid])) {
                    $projects[$pid]['rated'] = true;
                    $projects[$pid]['my_rating'] = (int)$row['rating'];
                }
            }
            $stmt->close();
        }

        $stmt = $conn->prepare("SELECT project_id, COUNT(*) AS cnt FROM citizen_report_feedback
            WHERE ip_address = ? AND project_id IN ($placeholders) GROUP BY project_id");
        if ($stmt) {
            $bindTypes = 's' . $types;
            $bindParams = array_merge([$ip], $ids);
            $stmt->bind_param($bindTypes, ...$bindParams);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $pid = (string)(int)$row['project_id'];
                if (isset($projects[$pid]) && (int)$row['cnt'] >= PR_IP_MAX_PER_PROJECT) {
                    $projects[$pid]['ip_blocked'] = true;
                }
            }
            $stmt->close();
        }
    }

    pr_json([
        'success' => true,
        'voter_token' => $voterToken,
        'projects' => array_values($projects),
    ]);
}

if ($action === 'submit_rating' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = trim((string)($_POST['voter_token'] ?? ''));
    if ($postedToken === '' || !hash_equals($voterToken, $postedToken)) {
        pr_json(['success' => false, 'code' => 'token_mismatch', 'message' => 'Invalid session. Please refresh and try again.'], 403);
    }

    $projectId = (int)($_POST['project_id'] ?? 0);
    $rating = (int)($_POST['rating'] ?? 0);
    $comment = trim((string)($_POST['comment'] ?? ''));
    $comment = $comment !== '' ? mb_substr(strip_tags($comment), 0, 500) : null;

    if ($projectId <= 0) {
        pr_json(['success' => false, 'message' => 'Invalid project.'], 400);
    }
    if ($rating < 1 || $rating > 5) {
        pr_json(['success' => false, 'message' => 'Please select a rating from 1 to 5 stars.'], 400);
    }

    $projStmt = $conn->prepare('SELECT id FROM published_completed_projects WHERE id = ? LIMIT 1');
    $projStmt->bind_param('i', $projectId);
    $projStmt->execute();
    $projRes = $projStmt->get_result();
    if (!$projRes || !$projRes->fetch_assoc()) {
        $projStmt->close();
        pr_json(['success' => false, 'message' => 'Project not found.'], 404);
    }
    $projStmt->close();

    // 1) Cookie already rated this project
    $chk = $conn->prepare('SELECT id FROM citizen_report_feedback WHERE project_id = ? AND voter_token = ? LIMIT 1');
    $chk->bind_param('is', $projectId, $voterToken);
    $chk->execute();
    $chkRes = $chk->get_result();
    if ($chkRes && $chkRes->fetch_assoc()) {
        $chk->close();
        pr_json([
            'success' => false,
            'code' => 'already_rated',
            'message' => 'You have already rated this project.',
        ], 409);
    }
    $chk->close();

    // 2) IP cap per project (< 3 allowed)
    $ipStmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM citizen_report_feedback WHERE project_id = ? AND ip_address = ?');
    $ipStmt->bind_param('is', $projectId, $ip);
    $ipStmt->execute();
    $ipRes = $ipStmt->get_result();
    $ipRow = $ipRes ? $ipRes->fetch_assoc() : null;
    $ipStmt->close();
    $timesRated = (int)($ipRow['cnt'] ?? 0);
    if ($timesRated >= PR_IP_MAX_PER_PROJECT) {
        pr_json([
            'success' => false,
            'code' => 'spam_limited',
            'message' => 'Rating limit reached for this project on this network. Thanks for your feedback!',
        ], 429);
    }

    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $ins = $conn->prepare('INSERT INTO citizen_report_feedback (project_id, rating, comment, voter_token, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?)');
    $commentDb = $comment !== null ? $comment : '';
    $ins->bind_param('iissss', $projectId, $rating, $commentDb, $voterToken, $ip, $ua);

    try {
        if (!$ins->execute()) {
            $ins->close();
            pr_json(['success' => false, 'message' => 'Failed to save rating. Please try again.'], 500);
        }
    } catch (Throwable $e) {
        $ins->close();
        if ($conn->errno === 1062) {
            pr_json([
                'success' => false,
                'code' => 'already_rated',
                'message' => 'You have already rated this project.',
            ], 409);
        }
        error_log('project_ratings_api insert: ' . $e->getMessage());
        pr_json(['success' => false, 'message' => 'Failed to save rating. Please try again.'], 500);
    }
    $ins->close();

    $avg = 0.0;
    $count = 0;
    $sumStmt = $conn->prepare('SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM citizen_report_feedback WHERE project_id = ?');
    $sumStmt->bind_param('i', $projectId);
    $sumStmt->execute();
    $sumRes = $sumStmt->get_result();
    if ($sumRes && ($sumRow = $sumRes->fetch_assoc())) {
        $avg = round((float)$sumRow['avg_rating'], 1);
        $count = (int)$sumRow['cnt'];
    }
    $sumStmt->close();

    pr_json([
        'success' => true,
        'message' => 'Thank you for your rating!',
        'average' => $avg,
        'count' => $count,
        'my_rating' => $rating,
    ]);
}

pr_json(['success' => false, 'message' => 'Invalid action'], 400);

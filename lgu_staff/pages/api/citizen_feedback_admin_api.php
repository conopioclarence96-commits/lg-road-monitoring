<?php
/**
 * Staff Citizen Feedback viewer API (no role restriction beyond login).
 *
 * GET action=report_summary | report_list | service_summary | service_list
 */

header('Content-Type: application/json; charset=utf-8');

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

function cf_admin_json($payload, $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function cf_admin_ensure_tables($conn) {
    // Report / project ratings
    $newExists = $conn->query("SHOW TABLES LIKE 'citizen_report_feedback'");
    $hasNew = $newExists && $newExists->num_rows > 0;
    if (!$hasNew) {
        $oldExists = $conn->query("SHOW TABLES LIKE 'citizen_feedback'");
        if ($oldExists && $oldExists->num_rows > 0) {
            $col = $conn->query("SHOW COLUMNS FROM citizen_feedback LIKE 'voter_token'");
            if ($col && $col->num_rows > 0) {
                $conn->query('RENAME TABLE citizen_feedback TO citizen_report_feedback');
                $hasNew = true;
            }
        }
    }
    if (!$hasNew) {
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

function cf_admin_summary($conn, $table) {
    $average = 0.0;
    $total = 0;
    $counts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

    $allowed = ['citizen_report_feedback', 'citizen_service_feedback'];
    if (!in_array($table, $allowed, true)) {
        return compact('average', 'total', 'counts');
    }

    $res = $conn->query("SELECT rating, COUNT(*) AS cnt FROM `$table` GROUP BY rating");
    if ($res) {
        $sum = 0;
        while ($row = $res->fetch_assoc()) {
            $r = (int)$row['rating'];
            $c = (int)$row['cnt'];
            if ($r >= 1 && $r <= 5) {
                $counts[$r] = $c;
                $total += $c;
                $sum += $r * $c;
            }
        }
        if ($total > 0) {
            $average = round($sum / $total, 1);
        }
    }

    return [
        'average' => $average,
        'total' => $total,
        'counts' => $counts,
    ];
}

if (!$conn) {
    cf_admin_json(['success' => false, 'message' => 'Database unavailable'], 500);
}

cf_admin_ensure_tables($conn);
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$limit = max(1, min(100, (int)($_GET['limit'] ?? 10)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

$sortBy = strtolower(trim((string)($_GET['sort_by'] ?? 'created_at')));
$sortDir = strtolower(trim((string)($_GET['sort_dir'] ?? 'desc')));
if (!in_array($sortBy, ['rating', 'created_at'], true)) {
    $sortBy = 'created_at';
}
if (!in_array($sortDir, ['asc', 'desc'], true)) {
    $sortDir = 'desc';
}

if ($action === 'report_summary') {
    cf_admin_json(['success' => true, 'data' => cf_admin_summary($conn, 'citizen_report_feedback')]);
}

if ($action === 'service_summary') {
    cf_admin_json(['success' => true, 'data' => cf_admin_summary($conn, 'citizen_service_feedback')]);
}

if ($action === 'report_list') {
    $total = 0;
    $countRes = $conn->query('SELECT COUNT(*) AS cnt FROM citizen_report_feedback');
    if ($countRes && ($crow = $countRes->fetch_assoc())) {
        $total = (int)$crow['cnt'];
    }

    $orderCol = $sortBy === 'rating' ? 'f.rating' : 'f.created_at';
    $orderDir = $sortDir === 'asc' ? 'ASC' : 'DESC';
    $rows = [];
    $sql = "SELECT f.id, f.project_id, f.rating, f.comment, f.ip_address, f.created_at,
                   p.title AS project_title
            FROM citizen_report_feedback f
            LEFT JOIN published_completed_projects p ON p.id = f.project_id
            ORDER BY $orderCol $orderDir, f.id DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    }
    cf_admin_json([
        'success' => true,
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $limit)),
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
        ],
    ]);
}

if ($action === 'service_list') {
    $total = 0;
    $countRes = $conn->query('SELECT COUNT(*) AS cnt FROM citizen_service_feedback');
    if ($countRes && ($crow = $countRes->fetch_assoc())) {
        $total = (int)$crow['cnt'];
    }

    $orderCol = $sortBy === 'rating' ? 'rating' : 'created_at';
    $orderDir = $sortDir === 'asc' ? 'ASC' : 'DESC';
    $rows = [];
    $sql = "SELECT id, rating, comment, page_url, ip_address, created_at
            FROM citizen_service_feedback
            ORDER BY $orderCol $orderDir, id DESC
            LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ii', $limit, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    }
    cf_admin_json([
        'success' => true,
        'data' => $rows,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => max(1, (int)ceil($total / $limit)),
            'sort_by' => $sortBy,
            'sort_dir' => $sortDir,
        ],
    ]);
}

cf_admin_json(['success' => false, 'message' => 'Invalid action'], 400);

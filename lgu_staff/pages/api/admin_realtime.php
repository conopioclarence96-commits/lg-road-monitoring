<?php
/**
 * Admin Dashboard Real-Time SSE Endpoint
 * 
 * Streams dashboard stat/chart/table updates to connected admin clients
 * via Server-Sent Events. Polls the database at a configurable interval
 * and emits only changed data to minimize bandwidth.
 *
 * Protocol:
 *   - Event: stats   → stat card counts (pending, approved, inactive, deactivated)
 *   - Event: charts  → report status breakdown + monthly trend data
 *   - Event: table   → inactive users list (2+ weeks)
 *   - Event: ping    → keepalive (every 15s)
 *   - id:    <timestamp_ms> — used by client for Last-Event-ID reconnection
 */

// ── Session bootstrap ──────────────────────────────────────────────
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);
session_start();

// ── Auth gate — only system_admin may connect ──────────────────────
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'system_admin') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Refresh session activity so it doesn't expire while SSE is open
$_SESSION['last_activity'] = time();

// ── DB connection ──────────────────────────────────────────────────
require_once __DIR__ . '/../../includes/config.php';

// ── SSE headers ────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');          // Disable Nginx buffering
@ini_set('zlib.output_compression', 0);  // Disable PHP output compression
@ini_set('output_buffering', 'Off');

// ── Configuration ──────────────────────────────────────────────────
define('POLL_INTERVAL', 5);    // seconds between DB polls
define('KEEPALIVE_SEC', 15);   // seconds between ping comments

// ── Helpers ────────────────────────────────────────────────────────

/**
 * Send a named SSE event with JSON-encoded data.
 */
function sse_event(string $name, array $data): void {
    echo "event: {$name}\n";
    echo "id: " . (microtime(true) * 1000) . "\n";
    echo "data: " . json_encode($data) . "\n\n";
    // Flush immediately so the client receives it
    if (ob_get_level()) ob_end_flush();
    flush();
}

/**
 * Send a keepalive comment (ignored by EventSource on the client).
 */
function sse_ping(): void {
    echo ": keepalive " . time() . "\n\n";
    if (ob_get_level()) ob_end_flush();
    flush();
}

/**
 * Fetch the current stat-card values.
 */
function fetch_stats(mysqli $conn): array {
    $stats = [];

    $r = $conn->query("SELECT COUNT(*) AS c FROM users WHERE account_status='pending'");
    $stats['pending_users'] = (int)$r->fetch_assoc()['c'];

    $r = $conn->query("SELECT COUNT(*) AS c FROM users WHERE account_status='verified' AND is_active=1");
    $stats['approved_users'] = (int)$r->fetch_assoc()['c'];

    $r = $conn->query("SELECT COUNT(*) AS c FROM users WHERE account_status='deactivated'");
    $stats['deactivated_users'] = (int)$r->fetch_assoc()['c'];

    $r = $conn->query("SELECT COUNT(*) AS c FROM users WHERE account_status='verified' AND is_active=1 AND last_login IS NOT NULL AND last_login < DATE_SUB(NOW(), INTERVAL 14 DAY)");
    $stats['inactive_2weeks'] = (int)$r->fetch_assoc()['c'];

    return $stats;
}

/**
 * Fetch chart data (report status breakdown + monthly trend).
 */
function fetch_charts(mysqli $conn): array {
    $charts = [];

    $r = $conn->query("SELECT status, COUNT(*) AS count FROM road_transportation_reports GROUP BY status ORDER BY count DESC");
    $charts['by_status'] = $r->fetch_all(MYSQLI_ASSOC);

    $r = $conn->query("
        SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_label,
               DATE_FORMAT(created_at, '%b %Y') AS month_name,
               COUNT(*) AS count
        FROM road_transportation_reports
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY month_label, month_name
        ORDER BY month_label ASC
    ");
    $charts['by_month'] = $r->fetch_all(MYSQLI_ASSOC);

    return $charts;
}

/**
 * Fetch the inactive-users table rows.
 */
function fetch_inactive_users(mysqli $conn): array {
    $r = $conn->query("
        SELECT id, username, email, full_name, role, department, last_login, created_at
        FROM users
        WHERE account_status = 'verified'
          AND is_active = 1
          AND last_login IS NOT NULL
          AND last_login < DATE_SUB(NOW(), INTERVAL 14 DAY)
        ORDER BY last_login ASC
    ");
    return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * Compute a content hash for change-detection (avoids re-sending identical data).
 */
function data_hash($data): string {
    return md5(json_encode($data));
}

// ── Main loop ──────────────────────────────────────────────────────

// Disable script time limit for long-running SSE
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

// Ignore user abort so we can clean up
ignore_user_abort(false);

$last_keepalive = time();
$prev_stats_hash  = '';
$prev_charts_hash = '';
$prev_table_hash  = '';

// Send initial snapshot immediately so the client is populated
try {
    $stats  = fetch_stats($conn);
    $charts = fetch_charts($conn);
    $table  = fetch_inactive_users($conn);

    $prev_stats_hash  = data_hash($stats);
    $prev_charts_hash = data_hash($charts);
    $prev_table_hash  = data_hash($table);

    sse_event('stats', $stats);
    sse_event('charts', $charts);
    sse_event('table', $table);
} catch (Exception $e) {
    error_log("admin_realtime initial snapshot error: " . $e->getMessage());
    sse_event('error', ['message' => 'Initial snapshot failed']);
}

// ── Polling loop ───────────────────────────────────────────────────
while (true) {
    // 1) Check if the client is still connected
    if (connection_aborted()) {
        break;
    }

    // 2) Refresh session activity
    $_SESSION['last_activity'] = time();

    // 3) Send keepalive comment periodically
    if ((time() - $last_keepalive) >= KEEPALIVE_SEC) {
        sse_ping();
        $last_keepalive = time();
    }

    // 4) Poll DB and emit only changed data
    try {
        $stats  = fetch_stats($conn);
        $charts = fetch_charts($conn);
        $table  = fetch_inactive_users($conn);

        $sh = data_hash($stats);
        $ch = data_hash($charts);
        $th = data_hash($table);

        if ($sh !== $prev_stats_hash) {
            sse_event('stats', $stats);
            $prev_stats_hash = $sh;
        }
        if ($ch !== $prev_charts_hash) {
            sse_event('charts', $charts);
            $prev_charts_hash = $ch;
        }
        if ($th !== $prev_table_hash) {
            sse_event('table', $table);
            $prev_table_hash = $th;
        }
    } catch (Exception $e) {
        error_log("admin_realtime poll error: " . $e->getMessage());
        // Send error event but keep loop alive
        sse_event('error', ['message' => 'Poll cycle error']);
    }

    // 5) Sleep until next poll
    sleep(POLL_INTERVAL);
}

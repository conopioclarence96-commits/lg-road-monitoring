<?php
/**
 * System announcements — separate from reports / projects / Public Transparency.
 * Admin manages via pages/admin/announcements.php; all roles view on dashboards.
 */

function announcements_ensure_table($conn) {
    if (!$conn) {
        return;
    }
    $conn->query("CREATE TABLE IF NOT EXISTS system_announcements (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        photo VARCHAR(500) DEFAULT NULL,
        posted_at DATE NOT NULL,
        is_published TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_published_posted (is_published, posted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $chk = $conn->query("SHOW COLUMNS FROM system_announcements LIKE 'photo'");
    if ($chk && $chk->num_rows === 0) {
        $conn->query("ALTER TABLE system_announcements ADD COLUMN photo VARCHAR(500) DEFAULT NULL AFTER content");
    }
}

/** Absolute filesystem dir for announcement images. */
function announcements_upload_dir() {
    $dir = str_replace('\\', '/', __DIR__ . '/../../uploads/announcements');
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    if (is_dir($dir)) {
        @chmod($dir, 0777);
    }
    return $dir;
}

/** Web-relative path prefix stored in DB (from project root). */
function announcements_photo_web_path($filename) {
    return 'uploads/announcements/' . ltrim((string)$filename, '/\\');
}

/**
 * Resolve a stored photo path to a browser URL relative to pages/admin or pages/lgu.
 *
 * @param string $photo DB value like uploads/announcements/xyz.jpg
 * @param string $from  'admin'|'lgu'|'shared' — how many levels up to project root
 */
function announcements_photo_src($photo, $from = 'admin') {
    $photo = trim(str_replace(['../', '..\\'], '', (string)$photo));
    if ($photo === '') {
        return '';
    }
    $prefix = ($from === 'shared') ? '../../../' : '../../../';
    return $prefix . ltrim($photo, '/\\');
}

function announcements_delete_photo_file($photo) {
    $photo = trim((string)$photo);
    if ($photo === '') {
        return;
    }
    $base = basename($photo);
    $path = announcements_upload_dir() . '/' . $base;
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Posted announcements for dashboard widgets (newest first).
 *
 * @return array<int, array<string, mixed>>
 */
function announcements_fetch_published($conn, $limit = 8) {
    if (!$conn) {
        return [];
    }
    announcements_ensure_table($conn);
    $limit = max(1, min(50, (int)$limit));
    $rows = [];
    try {
        $stmt = $conn->prepare(
            "SELECT id, title, content, photo, posted_at, created_at
               FROM system_announcements
              WHERE is_published = 1
              ORDER BY posted_at DESC, id DESC
              LIMIT ?"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log('announcements_fetch_published: ' . $e->getMessage());
    }
    return $rows;
}

/**
 * All announcements for admin management (includes drafts).
 *
 * @return array<int, array<string, mixed>>
 */
function announcements_fetch_all($conn, $limit = 100) {
    if (!$conn) {
        return [];
    }
    announcements_ensure_table($conn);
    $limit = max(1, min(200, (int)$limit));
    $rows = [];
    try {
        $stmt = $conn->prepare(
            "SELECT a.id, a.title, a.content, a.photo, a.posted_at, a.is_published, a.created_at, a.updated_at,
                    u.full_name AS created_by_name
               FROM system_announcements a
               LEFT JOIN users u ON u.id = a.created_by
              ORDER BY a.posted_at DESC, a.id DESC
              LIMIT ?"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
    } catch (Exception $e) {
        error_log('announcements_fetch_all: ' . $e->getMessage());
    }
    return $rows;
}

function announcements_parse_published_flag($default = 1) {
    if (!array_key_exists('is_published', $_POST)) {
        return (int)$default;
    }
    return in_array(strtolower(trim((string)$_POST['is_published'])), ['1', 'true', 'on', 'yes'], true) ? 1 : 0;
}

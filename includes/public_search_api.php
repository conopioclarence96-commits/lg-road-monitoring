<?php
// Lightweight JSON search endpoint for the public navbar search bar.
// Searches all public-facing data that appears on the landing page:
//   - road transportation reports
//   - road maintenance reports
//   - IPMS infrastructure projects (approved)
//   - CIMM verification reports (Roads)
//   - public transparency announcements (published)
// Returns a small capped result set grouped-agnostic (client splits by type).
require_once __DIR__ . '/../lgu_staff/includes/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$q = trim($_GET['q'] ?? '');
if ($q === '' || mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$results = [];
$like = '%' . $q . '%';

if ($conn) {
    // 1. Transportation Reports (limit 5)
    try {
        $stmt = $conn->prepare(
            "SELECT id, report_id, title, description, location, status, 'report' AS type, 'transportation' AS source
             FROM road_transportation_reports
             WHERE title LIKE ? OR report_id LIKE ? OR location LIKE ? OR description LIKE ?
             ORDER BY created_at DESC LIMIT 5"
        );
        if ($stmt) {
            $stmt->bind_param('ssss', $like, $like, $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $results[] = $row; }
            $stmt->close();
        }
    } catch (Throwable $e) { error_log("public_search_api transportation: " . $e->getMessage()); }

    // 2. Maintenance Reports (limit 5)
    try {
        $stmt = $conn->prepare(
            "SELECT id, report_id, title, description, location, status, 'report' AS type, 'maintenance' AS source
             FROM road_maintenance_reports
             WHERE title LIKE ? OR report_id LIKE ? OR location LIKE ? OR description LIKE ?
             ORDER BY created_at DESC LIMIT 5"
        );
        if ($stmt) {
            $stmt->bind_param('ssss', $like, $like, $like, $like);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) { $results[] = $row; }
            $stmt->close();
        }
    } catch (Throwable $e) { error_log("public_search_api maintenance: " . $e->getMessage()); }

    // 3. IPMS Infrastructure Projects (approved) — search project_name / road_name / road_status
    try {
        $chk = $conn->query("SHOW TABLES LIKE 'ipms_road_projects'");
        if ($chk && $chk->num_rows > 0) {
            $stmt = $conn->prepare(
                "SELECT project_id AS id, CAST(project_id AS CHAR) AS report_id,
                        project_name AS title,
                        COALESCE(NULLIF(road_status,''), '') AS description,
                        COALESCE(NULLIF(road_name,''), project_name) AS location,
                        'pending' AS status, 'project' AS type, 'infrastructure' AS source
                 FROM ipms_road_projects
                 WHERE status = 'approved'
                   AND (project_name LIKE ? OR road_name LIKE ? OR road_status LIKE ? OR CAST(project_id AS CHAR) LIKE ?)
                 ORDER BY created_at DESC LIMIT 5"
            );
            if ($stmt) {
                $stmt->bind_param('ssss', $like, $like, $like, $like);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) { $results[] = $row; }
                $stmt->close();
            }
        }
        if ($chk) $chk->close();
    } catch (Throwable $e) { error_log("public_search_api ipms: " . $e->getMessage()); }

    // 4. CIMM Verification Reports (Roads only)
    try {
        $chk = $conn->query("SHOW TABLES LIKE 'cimm_verification_reports'");
        if ($chk && $chk->num_rows > 0) {
            $stmt = $conn->prepare(
                "SELECT id, reference_code AS report_id, infrastructure AS title,
                        issue AS description, location,
                        COALESCE(resolution_status,'pending') AS status,
                        'cimm' AS type, 'cimm' AS source
                 FROM cimm_verification_reports
                 WHERE infrastructure = 'Roads'
                   AND (reference_code LIKE ? OR infrastructure LIKE ? OR issue LIKE ? OR location LIKE ?)
                 ORDER BY COALESCE(submitted_at, verified_at, synced_at, NOW()) DESC LIMIT 5"
            );
            if ($stmt) {
                $stmt->bind_param('ssss', $like, $like, $like, $like);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) { $results[] = $row; }
                $stmt->close();
            }
        }
        if ($chk) $chk->close();
    } catch (Throwable $e) { error_log("public_search_api cimm: " . $e->getMessage()); }

    // 5. Public Transparency Announcements (published)
    try {
        $chk = $conn->query("SHOW TABLES LIKE 'public_transparency_announcements'");
        if ($chk && $chk->num_rows > 0) {
            $stmt = $conn->prepare(
                "SELECT id, CAST(id AS CHAR) AS report_id, title, content AS description,
                        '' AS location, 'published' AS status, 'announcement' AS type, 'announcement' AS source
                 FROM public_transparency_announcements
                 WHERE is_published = 1
                   AND (title LIKE ? OR content LIKE ?)
                 ORDER BY posted_at DESC LIMIT 5"
            );
            if ($stmt) {
                $stmt->bind_param('ss', $like, $like);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) { $results[] = $row; }
                $stmt->close();
            }
        }
        if ($chk) $chk->close();
    } catch (Throwable $e) { error_log("public_search_api announcements: " . $e->getMessage()); }
}

echo json_encode($results);

<?php
/**
 * Shared admin notification-badge unread count.
 *
 * Mirrors the persistent admin feed of pages/shared/notifications.php. Every
 * card the admin sees is stored as a report_notifications snapshot row (type
 * 'admin_keep', report_id 0); a card counts as unread when its id is neither in
 * the admin's read set (session + 'admin_read' markers) nor dismissed with the
 * X button. Live pending items that have not been snapshotted yet are counted
 * too, so a brand-new report/change request bumps the badge immediately. Used
 * by the sidebar (initial render) and by the polling endpoint below.
 */
function nc_admin_unread_count($conn, $user_id, $email) {
    if (!$conn || (int)$user_id <= 0) return 0;
    $user_id = (int)$user_id;

    // Read set: session card ids merged with persisted 'admin_read' markers so
    // the badge stays at 0 after a fresh login when the session is empty.
    $read = $_SESSION['nc_admin_read'][$user_id] ?? [];
    $read = array_values(array_unique($read));
    if ($email !== '') {
        try {
            $stmt = $conn->prepare("SELECT message FROM report_notifications WHERE type = 'admin_read' AND recipient_email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($rows as $r) { $read[] = (string)$r['message']; }
        } catch (Exception $e) {}
    }
    $read_map = [];
    foreach ($read as $k) { $read_map[(string)$k] = true; }

    // Cards dismissed with the X button are hidden from the feed too.
    $dismissed = [];
    foreach (($_SESSION['nc_dismissed'][$user_id] ?? []) as $k) { $dismissed[(string)$k] = true; }

    // Candidate card ids (live ∪ persistent snapshots).
    $ids = [];

    // Persistent feed snapshots already recorded for this admin.
    if ($email !== '') {
        try {
            $stmt = $conn->prepare("SELECT message FROM report_notifications WHERE type = 'admin_keep' AND recipient_email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($rows as $r) {
                $m = json_decode((string)$r['message'], true);
                if (is_array($m) && !empty($m['id'])) $ids[(string)$m['id']] = true;
            }
        } catch (Exception $e) {}
    }

    // Live pending reports (road + CIMM pending review) not yet snapshotted.
    try {
        $stmt = $conn->prepare("SELECT id FROM road_transportation_reports WHERE status = 'pending'");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) { $ids['rep' . (int)$r['id']] = true; }
    } catch (Exception $e) {}
    if (function_exists('rgmap_verification_pdo') && function_exists('rgmap_fetch_cimm_verification_reports')) {
        try {
            $cimmPdo = rgmap_verification_pdo();
            $cimmRows = rgmap_fetch_cimm_verification_reports($cimmPdo, ['limit' => 500]);
            $cimmPendingStatus = ['Pending', 'Pending Review'];
            foreach ($cimmRows as $crow) {
                $verification = (string)($crow['verification_status'] ?? 'Pending Review');
                if (!in_array($verification, $cimmPendingStatus, true) && (string)($crow['approval_status'] ?? 'Pending') !== 'Pending') {
                    continue;
                }
                $ids['rep' . (int)($crow['id'] ?? $crow['cimm_req_id'] ?? 0)] = true;
            }
        } catch (Exception $e) {}
    }

    // Live pending change requests.
    try {
        $stmt = $conn->prepare("SELECT id FROM change_requests WHERE status = 'pending'");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) { $ids['cr' . (int)$r['id']] = true; }
    } catch (Exception $e) {}

    // Live unread progress notifications referencing an existing report.
    try {
        $stmt = $conn->prepare("SELECT id FROM report_notifications WHERE is_read = 0 AND EXISTS (
            SELECT 1 FROM road_transportation_reports WHERE id = report_notifications.report_id
            UNION ALL
            SELECT 1 FROM road_maintenance_reports WHERE id = report_notifications.report_id
            UNION ALL
            SELECT 1 FROM cimm_verification_reports WHERE id = report_notifications.report_id
            LIMIT 1
        )");
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) { $ids['pn' . (int)$r['id']] = true; }
    } catch (Exception $e) {}

    // Live active assignments for this admin.
    try {
        $stmt = $conn->prepare("SELECT id FROM report_assignments WHERE user_id = ? AND status = 'active'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as $r) { $ids['asg' . (int)$r['id']] = true; }
    } catch (Exception $e) {}

    $count = 0;
    foreach ($ids as $k => $_) {
        if (isset($dismissed[$k]) || isset($read_map[$k])) continue;
        $count++;
    }
    return $count;
}

<?php
/**
 * Public Transparency – Staff Management Page
 * Manage completed projects with Before & After photos
 * Syncs to landing page "See the Transformation" section
 */

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);
session_start();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/public_announcements.php';

$session_timeout = 30 * 60;
lgu_enforce_idle_timeout($session_timeout, '../../login.php?timeout=1');

if (!isset($_SESSION['user_id']) || !is_admin_or_staff_role($_SESSION['role'] ?? '')) {
    header('Location: ../../login.php');
    exit;
}

$is_admin = ($_SESSION['role'] === 'system_admin');

// Transport Operations Supervisor flag: scopes the mobile-fit CSS below to this portal only.
$is_trans_ops_supervisor = ($_SESSION['role'] ?? '') === 'trans_ops_supervisor';

// Road Monitoring Officer flag: scopes the mobile-fit CSS below to this portal only.
$is_road_monitoring_officer = ($_SESSION['role'] ?? '') === 'road_monitoring_officer';

// Road Ops Supervisor flag: scopes the mobile-fit CSS below to this portal only.
$is_road_supervisor = ($_SESSION['role'] ?? '') === 'road_ops_supervisor';

// Set when the admin arrives here after approving a transparency upload request;
// that project's data is then imported into the form for review.
$prefill_request_id = ($is_admin && isset($_GET['transparency_request']))
    ? max(0, (int)$_GET['transparency_request'])
    : 0;

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json; charset=utf-8');
    $api_file = __DIR__ . '/../api/completed_projects_api.php';
    $_GET['action'] = $_POST['action'] ?? '';
    require $api_file;
    exit;
}

// Fetch projects for display
$projects = [];
if ($conn) {
    try {
        $result = $conn->query("SELECT * FROM published_completed_projects ORDER BY created_at DESC");
        while ($row = $result->fetch_assoc()) {
            $projects[] = $row;
        }
    } catch (Exception $e) {
        // Table might not exist yet
    }
}

// Public landing-page announcements (independent from internal system_announcements)
$announcements = [];
if ($conn) {
    try {
        $announcements = $is_admin
            ? public_announcements_fetch_all($conn)
            : public_announcements_fetch_published($conn, 50);
    } catch (Exception $e) {
        $announcements = [];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Transparency – Completed Projects | LGU Staff</title>
    <link rel="icon" type="image/png" href="../../assets/img/infra-gov-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=6">
    <link rel="stylesheet" href="../../css/public_transparency.css?v=<?php echo filemtime(__DIR__ . '/../../css/public_transparency.css'); ?>">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        body { background: #f7f5f0; min-height: 100vh; color: #1e293b; }
        body.dark-mode { background: var(--bg-page); }
        body.dark-mode .form-group input:not(.publish-checkbox),
        body.dark-mode .form-group textarea,
        body.dark-mode .form-group select { background: var(--bg-input) !important; border-color: var(--border-input) !important; color: var(--text-primary) !important; }
        body.dark-mode .form-group > label:not(.publish-switch) { color: var(--text-secondary) !important; }
        body.dark-mode .form-group label.publish-switch {
            background: var(--bg-input) !important;
            border-color: var(--border-default) !important;
            color: var(--text-primary) !important;
        }
        body.dark-mode .form-group label.publish-switch:hover { border-color: var(--color-primary) !important; }
        body.dark-mode .form-group label.publish-switch:has(.publish-checkbox:checked) {
            background: var(--color-success-bg) !important;
            border-color: var(--color-success) !important;
        }
        body.dark-mode .form-group label.publish-switch .toggle-slider {
            background: var(--border-default) !important;
        }
        body.dark-mode .form-group label.publish-switch .toggle-slider::before {
            background: #fff !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.25) !important;
        }
        body.dark-mode .form-group label.publish-switch:has(.publish-checkbox:checked) .toggle-slider {
            background: var(--color-success) !important;
        }
        body.dark-mode .form-group label.publish-switch:has(.publish-checkbox:checked) .toggle-text {
            color: var(--color-success) !important;
        }
        body.dark-mode .project-preview .preview-handle::after { color: var(--text-primary) !important; }
        body.dark-mode .btn-cancel { background: var(--bg-input-readonly) !important; color: var(--text-primary) !important; border: 1px solid var(--border-default) !important; }
        body.dark-mode .btn-cancel:hover { background: var(--bg-hover) !important; color: var(--text-primary) !important; }
        body.dark-mode .projects-section { background: var(--bg-card) !important; border-color: var(--border-default) !important; }
        body.dark-mode .project-form-card { background: var(--bg-card) !important; border-color: var(--border-default) !important; }
        body.dark-mode .project-item { background: var(--bg-card) !important; border-color: var(--border-default) !important; }
        body.dark-mode .view-only-notice { background: var(--bg-input-readonly) !important; border-left-color: var(--color-primary) !important; }
        body.dark-mode .view-only-notice h4 { color: var(--text-primary) !important; }
        body.dark-mode .view-only-notice p { color: var(--text-secondary) !important; }
        body.dark-mode .import-review-banner { background: var(--color-success-bg) !important; border-left-color: var(--color-success) !important; }
        body.dark-mode .import-review-banner h4 { color: var(--text-primary) !important; }
        body.dark-mode .import-review-banner p { color: var(--text-secondary) !important; }
        body.dark-mode .empty-state { color: var(--text-muted) !important; }
        body.dark-mode .empty-state i { color: var(--text-muted) !important; }
        body.dark-mode .project-details h4 { color: var(--text-primary) !important; }
        body.dark-mode .project-meta span { color: var(--text-secondary) !important; }
        body.dark-mode .project-cost { background: rgba(55, 98, 200, 0.2) !important; color: #93b3e0 !important; }

        /* Tabs + Citizen Feedback */
        .pt-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 0 0 20px;
            padding: 6px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }
        .pt-tab {
            border: none;
            background: transparent;
            color: #64748b;
            font-family: inherit;
            font-size: 13px;
            font-weight: 600;
            padding: 10px 16px;
            border-radius: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .pt-tab:hover { background: rgba(55, 98, 200, 0.08); color: #1e3c72; }
        .pt-tab.active { background: #3762c8; color: #fff; }
        .pt-tab-panel[hidden] { display: none !important; }
        .cf-feedback-wrap { display: flex; flex-direction: column; gap: 22px; }
        .cf-panel {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 18px 20px 20px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }
        .cf-panel-sub { margin: 4px 0 0; font-size: 12px; color: #64748b; font-weight: 500; }
        .cf-dash {
            display: grid;
            grid-template-columns: minmax(100px, 140px) minmax(100px, 140px) 1fr;
            gap: 12px;
            margin: 16px 0 18px;
            align-items: stretch;
        }
        .cf-dash-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100%;
        }
        .cf-dash-card.cf-avg { background: linear-gradient(135deg, rgba(245, 158, 11, 0.12), rgba(55, 98, 200, 0.08)); }
        .cf-dash-value { font-size: 28px; font-weight: 700; color: #1e3c72; line-height: 1.1; }
        .cf-dash-label { font-size: 11px; color: #64748b; margin-top: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
        .cf-star-counts {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .cf-star-row { display: grid; grid-template-columns: 36px 1fr 36px; gap: 8px; align-items: center; font-size: 12px; color: #475569; }
        .cf-star-row b { text-align: right; color: #1e3c72; }
        .cf-bar { height: 8px; background: #e2e8f0; border-radius: 99px; overflow: hidden; }
        .cf-bar i { display: block; height: 100%; background: #f59e0b; border-radius: 99px; font-style: normal; }
        .cf-table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 12px; }
        .cf-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .cf-table th {
            text-align: left;
            padding: 10px 12px;
            background: #f8fafc;
            color: #64748b;
            font-weight: 600;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .cf-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
            vertical-align: top;
        }
        .cf-table tr:last-child td { border-bottom: none; }
        .cf-list-toolbar {
            display: flex;
            justify-content: flex-end;
            margin: 0 0 10px;
        }
        .cf-sort-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
        }
        .cf-sort-select {
            font-family: inherit;
            font-size: 13px;
            font-weight: 500;
            color: #1e3c72;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 6px 10px;
            background: #fff;
            cursor: pointer;
        }
        .cf-pager {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 12px;
            font-size: 12px;
            color: #64748b;
        }
        .cf-pager-btns { display: flex; gap: 6px; align-items: center; }
        .cf-pager button {
            font-family: inherit;
            font-size: 12px;
            font-weight: 600;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #1e3c72;
            border-radius: 8px;
            padding: 6px 12px;
            cursor: pointer;
        }
        .cf-pager button:disabled {
            opacity: 0.45;
            cursor: not-allowed;
        }
        .cf-pager button:hover:not(:disabled) {
            background: rgba(55, 98, 200, 0.08);
        }
        .cf-stars { color: #f59e0b; letter-spacing: 1px; white-space: nowrap; }
        .cf-empty { text-align: center; color: #94a3b8 !important; padding: 24px !important; }
        .cf-muted { color: #94a3b8; font-size: 12px; }
        .cf-project-link {
            color: #3762c8;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
        }
        .cf-project-link:hover { text-decoration: underline; }
        .project-item.project-item-highlight {
            border-color: #3762c8 !important;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.35), 0 8px 24px rgba(30, 60, 114, 0.12) !important;
            transform: translateY(-2px);
            animation: cfProjectPulse 1.2s ease-in-out 2;
        }
        @keyframes cfProjectPulse {
            0%, 100% { box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.35), 0 8px 24px rgba(30, 60, 114, 0.12); }
            50% { box-shadow: 0 0 0 6px rgba(55, 98, 200, 0.2), 0 8px 24px rgba(30, 60, 114, 0.12); }
        }
        @media (max-width: 900px) {
            .cf-dash { grid-template-columns: 1fr 1fr; }
            .cf-star-counts { grid-column: 1 / -1; }
        }
        body.dark-mode .pt-tabs { background: var(--bg-card) !important; border-color: var(--border-default) !important; }
        body.dark-mode .pt-tab { color: var(--text-secondary) !important; }
        body.dark-mode .pt-tab:hover { background: rgba(55, 98, 200, 0.15) !important; color: var(--text-primary) !important; }
        body.dark-mode .pt-tab.active { background: #3762c8 !important; color: #fff !important; }
        body.dark-mode .cf-panel,
        body.dark-mode .cf-dash-card,
        body.dark-mode .cf-star-counts,
        body.dark-mode .cf-table-wrap { background: var(--bg-card) !important; border-color: var(--border-default) !important; }
        body.dark-mode .cf-dash-value,
        body.dark-mode .cf-star-row b,
        body.dark-mode .cf-table td { color: var(--text-primary) !important; }
        body.dark-mode .cf-panel-sub,
        body.dark-mode .cf-dash-label,
        body.dark-mode .cf-star-row,
        body.dark-mode .cf-table th { color: var(--text-secondary) !important; }
        body.dark-mode .cf-table th { background: var(--bg-input-readonly) !important; border-color: var(--border-default) !important; }
        body.dark-mode .cf-table td { border-color: var(--border-default) !important; }
        body.dark-mode .cf-bar { background: #333 !important; }
        body.dark-mode .cf-sort-select,
        body.dark-mode .cf-pager button {
            background: var(--bg-card) !important;
            border-color: var(--border-default) !important;
            color: var(--text-primary) !important;
        }
        body.dark-mode .cf-sort-label,
        body.dark-mode .cf-pager { color: var(--text-secondary) !important; }
        body.dark-mode .cf-project-link { color: #7ba3f0 !important; }
        body.dark-mode .project-item.project-item-highlight {
            border-color: #3762c8 !important;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.45), 0 8px 24px rgba(0, 0, 0, 0.35) !important;
        }

        /* System Admin: header-actions dark-mode */
        body.system-admin-view.dark-mode .header-actions .header-datetime {
            background: rgba(255,255,255,0.05) !important;
            border-color: var(--border-default) !important;
            color: var(--text-primary) !important;
        }
        body.system-admin-view.dark-mode .header-actions .header-datetime i {
            background: rgba(96,165,250,0.15) !important;
            color: #93b3fd !important;
        }
        body.system-admin-view.dark-mode .header-actions .header-datetime #dtDate {
            color: var(--text-primary) !important;
        }
        body.system-admin-view.dark-mode .header-actions .header-datetime #dtTime {
            color: var(--text-secondary) !important;
        }

        /* Road Monitoring Officer: header-datetime dark-mode */
        body.rmo-view.dark-mode .header-actions .header-datetime {
            background: rgba(255,255,255,0.05) !important;
            border-color: var(--border-default) !important;
            color: var(--text-primary) !important;
        }
        body.rmo-view.dark-mode .header-actions .header-datetime i {
            background: rgba(96,165,250,0.15) !important;
            color: #93b3fd !important;
        }
        body.rmo-view.dark-mode .header-actions .header-datetime #dtDate {
            color: var(--text-primary) !important;
        }
        body.rmo-view.dark-mode .header-actions .header-datetime #dtTime {
            color: var(--text-secondary) !important;
        }

        .projects-section {
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 6px 18px rgba(30, 60, 114, 0.05);
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 22px;
        }

        .project-form-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 6px 18px rgba(30, 60, 114, 0.05);
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 22px;
        }

        .panel-project {
            position: relative;
            overflow: hidden;
        }
        .panel-project::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            background: #3762c8;
        }
        .panel-project-header {
            margin: -24px -24px 20px;
            padding: 14px 18px;
            background: #f8fafc;
            border-bottom: 1px solid #eef2f7;
            border-radius: 14px 14px 0 0;
        }
        .panel-project .section-title i {
            width: 32px;
            height: 32px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
            color: #3762c8;
            background: rgba(55, 98, 200, 0.12);
            box-shadow: none;
        }
        body.dark-mode .panel-project-header {
            background: rgba(255, 255, 255, 0.04) !important;
            border-bottom-color: var(--border-default) !important;
        }
        body.dark-mode .panel-project .section-title i {
            background: rgba(55, 98, 200, 0.2) !important;
            color: #93b3e0 !important;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 18px;
        }

        .form-group {
            margin-bottom: 4px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group > label:not(.publish-switch) {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
        }

        .form-group input:not(.publish-checkbox),
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.2s, box-shadow 0.2s;
            background: #fff;
            color: #1e293b;
        }

        .form-group input:not(.publish-checkbox):focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55, 98, 200, 0.12);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .photo-upload-area {
            border: 1.5px dashed #cbd5e1;
            border-radius: 12px;
            padding: 18px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
            position: relative;
            overflow: hidden;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
        }

        .photo-upload-area:hover {
            border-color: #3762c8;
            background: rgba(55, 98, 200, 0.04);
        }

        .photo-upload-area.has-image {
            padding: 0;
            border-style: solid;
            border-color: #10b981;
            background: #fff;
        }

        .photo-upload-area input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .photo-upload-area .upload-icon {
            font-size: 2rem;
            color: #94a3b8;
            margin-bottom: 8px;
        }

        .photo-upload-area .upload-text {
            font-size: 13px;
            color: #64748b;
        }

        .photo-upload-area img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            border-radius: 10px;
        }

        .photo-upload-area .remove-photo {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(239, 68, 68, 0.92);
            color: white;
            border: none;
            border-radius: 50%;
            width: 28px;
            height: 28px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
        }

        .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .btn-save {
            padding: 10px 22px;
            background: #16a34a;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s, transform 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(22, 163, 74, 0.22);
        }

        .btn-save:hover {
            background: #15803d;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(22, 163, 74, 0.28);
        }

        .btn-save:active {
            transform: translateY(0);
            box-shadow: 0 2px 6px rgba(22, 163, 74, 0.2);
        }

        .btn-save:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .btn-cancel {
            padding: 10px 22px;
            background: #fff;
            color: #475569;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s, transform 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: none;
        }

        .btn-cancel:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
        }

        .btn-cancel:active {
            transform: translateY(0);
        }

        .form-header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-import {
            padding: 10px 18px;
            background: #3762c8;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Poppins', sans-serif;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s, transform 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            box-shadow: 0 2px 8px rgba(55, 98, 200, 0.2);
        }

        .btn-import:hover {
            background: #1e3c72;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(55, 98, 200, 0.28);
        }

        .btn-import:active {
            transform: translateY(0);
        }

        .btn-import:disabled {
            opacity: 0.65;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 20px;
        }

        @media (max-width: 480px) {
            .projects-grid { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
        }

        .project-item {
            background: #fff;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04), 0 6px 16px rgba(30, 60, 114, 0.05);
            border: 1px solid #e2e8f0;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .project-item:hover {
            box-shadow: 0 8px 24px rgba(30, 60, 114, 0.08);
            transform: translateY(-2px);
        }

        .project-preview {
            position: relative;
            aspect-ratio: 16/10;
            overflow: hidden;
            cursor: ew-resize;
            background: #f0f0f0;
        }

        .project-preview img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .project-preview .preview-before {
            z-index: 2;
            clip-path: inset(0 50% 0 0);
        }

        .project-preview .preview-after {
            z-index: 1;
        }

        .project-preview .preview-handle {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 50%;
            width: 3px;
            background: white;
            z-index: 3;
            transform: translateX(-50%);
            box-shadow: 0 0 8px rgba(0,0,0,0.4);
            pointer-events: none;
        }

        .project-preview .preview-handle::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 32px;
            height: 32px;
            background: white;
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        .project-preview .preview-handle::after {
            content: '◂ ▸';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 11px;
            font-weight: 700;
            color: #1e3c72;
            z-index: 4;
            letter-spacing: -2px;
        }

        .project-preview .preview-label {
            position: absolute;
            top: 8px;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            z-index: 4;
            pointer-events: none;
        }

        .project-preview .label-before { left: 8px; background: rgba(220, 38, 38, 0.9); color: white; }
        .project-preview .label-after { right: 8px; background: rgba(5, 150, 105, 0.9); color: white; }

        .project-preview .no-before {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(0,0,0,0.3);
            z-index: 5;
            color: white;
            font-size: 12px;
            font-weight: 500;
        }

        .project-details {
            padding: 16px;
        }

        .project-details h4 {
            font-size: 15px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 6px;
        }

        .project-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 8px;
        }

        .project-meta span {
            font-size: 12px;
            color: #64748b;
        }

        .project-meta i {
            color: #059669;
            margin-right: 3px;
        }

        .project-cost {
            display: inline-block;
            background: rgba(55, 98, 200, 0.12);
            color: #3762c8;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            box-shadow: none;
        }

        .project-actions {
            display: flex;
            gap: 8px;
            padding: 0 16px 16px;
        }

        .btn-edit {
            padding: 7px 14px;
            background: #3762c8;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s, transform 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 6px rgba(55, 98, 200, 0.2);
        }

        .btn-edit:hover {
            background: #1e3c72;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.28);
            filter: none;
        }

        .btn-edit:active {
            transform: translateY(0);
        }

        .btn-delete {
            padding: 7px 14px;
            background: #fff;
            color: #dc2626;
            border: 1px solid #fecaca;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, border-color 0.2s, transform 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: none;
        }

        .btn-delete:hover {
            background: #fef2f2;
            border-color: #f87171;
            transform: translateY(-1px);
            filter: none;
        }

        .btn-delete:active {
            transform: translateY(0);
        }
        body.dark-mode .btn-edit { filter: brightness(1.1); }
        body.dark-mode .btn-delete { filter: brightness(1.1); }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #5a6c7d;
        }

        .empty-state i {
            font-size: 3rem;
            color: rgba(30, 60, 114, 0.3);
            margin-bottom: 15px;
        }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 14px 24px;
            border-radius: 10px;
            color: white;
            font-size: 14px;
            font-weight: 500;
            z-index: 9999;
            transform: translateX(120%);
            transition: transform 0.3s ease;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .toast.show { transform: translateX(0); }
        .toast.success { background: #16a34a; }
        .toast.error { background: #dc2626; }

        .view-only-notice {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-left: 3px solid #3762c8;
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }

        .view-only-notice .notice-icon {
            font-size: 1.6rem;
            color: #3762c8;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(55, 98, 200, 0.1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .view-only-notice h4 {
            margin: 0 0 4px 0;
            color: #1e3c72;
            font-size: 15px;
        }

        .view-only-notice p {
            margin: 0;
            color: #64748b;
            font-size: 13px;
        }

        .import-review-banner {
            background: #fff;
            border: 1px solid #d1fae5;
            border-left: 3px solid #16a34a;
            border-radius: 12px;
            padding: 16px 18px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }

        .import-review-banner .notice-icon {
            font-size: 1.4rem;
            color: #16a34a;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: rgba(22, 163, 74, 0.1);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .import-review-banner h4 {
            margin: 0 0 4px 0;
            color: #1e3c72;
            font-size: 15px;
        }

        .import-review-banner p {
            margin: 0;
            color: #64748b;
            font-size: 13px;
        }

        .publish-toggle {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label.publish-switch {
            position: relative;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            padding: 10px 14px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            width: fit-content;
            max-width: 100%;
            user-select: none;
            margin-bottom: 0;
            font-size: 13px;
            font-weight: 600;
            color: #1e3c72;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }

        .form-group label.publish-switch:hover {
            border-color: #3762c8;
            box-shadow: 0 2px 8px rgba(55, 98, 200, 0.1);
            transform: none;
        }

        .publish-checkbox {
            position: absolute;
            opacity: 0;
            width: 1px;
            height: 1px;
            margin: -1px;
            padding: 0;
            border: 0;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            pointer-events: none;
        }

        .form-group label.publish-switch:has(.publish-checkbox:checked) {
            background: rgba(16, 185, 129, 0.08);
            border-color: #10b981;
            color: #047857;
            box-shadow: 0 1px 3px rgba(16, 185, 129, 0.12);
        }

        .form-group label.publish-switch .toggle-slider {
            position: relative;
            display: inline-block;
            flex: 0 0 52px;
            width: 52px;
            min-width: 52px;
            height: 28px;
            min-height: 28px;
            background: #cbd5e1;
            border-radius: 14px;
            transition: background 0.3s ease;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.15);
        }

        .form-group label.publish-switch .toggle-slider::before {
            content: '';
            position: absolute;
            top: 3px;
            left: 3px;
            width: 22px;
            height: 22px;
            background: white;
            border-radius: 50%;
            transition: transform 0.3s ease;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.25);
        }

        .form-group label.publish-switch:has(.publish-checkbox:checked) .toggle-slider {
            background: #16a34a;
        }

        .form-group label.publish-switch:has(.publish-checkbox:checked) .toggle-slider::before {
            transform: translateX(24px);
        }

        .form-group label.publish-switch .toggle-text {
            font-size: 14px;
            font-weight: 600;
            color: inherit;
            line-height: 1.3;
        }

        .form-group label.publish-switch:has(.publish-checkbox:checked) .toggle-text {
            color: #047857;
        }

        .publish-hint {
            font-size: 12px;
            color: #5a6c7d;
            margin: 0;
        }

        .field-hint {
            display: block;
            font-size: 11px;
            color: #64748b;
            margin-top: 4px;
        }

        .publish-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 8px;
        }

        .publish-badge.published {
            background: rgba(22, 163, 74, 0.15);
            color: #15803d;
        }

        .publish-badge.draft {
            background: rgba(245, 158, 11, 0.18);
            color: #d97706;
        }

        .btn-publish {
            padding: 7px 14px;
            background: #16a34a;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, box-shadow 0.2s, transform 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 6px rgba(22, 163, 74, 0.2);
        }

        .btn-publish:hover {
            background: #15803d;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.28);
            filter: none;
        }

        .btn-publish:active {
            transform: translateY(0);
        }
        body.dark-mode .btn-publish { filter: none; }
    </style>
    <?php if ($is_trans_ops_supervisor): ?>
    <!-- Transport Operations Supervisor only: keep all four transparency stat
         cards in ONE row on phones. The shared stylesheet stacks them (2x2 /
         single column) below 768px; compact the tiles instead so the
         4-column grid fits. UI-only CSS scoping — other portals are
         unaffected and no behaviour changes. -->
    <style>
        @media (max-width: 768px) {
            body.trans-supervisor-view .transparency-stats {
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 6px;
                margin-bottom: 14px;
            }
            body.trans-supervisor-view .transparency-stat {
                padding: 8px 6px;
                border-radius: 10px;
                min-width: 0;
                max-width: 100%;
                box-sizing: border-box;
            }
            body.trans-supervisor-view .transparency-stat::before { height: 2px; }
            body.trans-supervisor-view .stat-number {
                font-size: 13px;
                gap: 5px;
                margin-bottom: 3px;
            }
            body.trans-supervisor-view .stat-number .stat-icon {
                width: 20px;
                height: 20px;
                border-radius: 6px;
                font-size: 10px;
                margin-right: 0;
            }
            body.trans-supervisor-view .stat-label {
                font-size: 7.5px;
                line-height: 1.25;
                overflow-wrap: anywhere;
                word-break: break-word;
            }
        }
    </style>
    <?php endif; ?>
    <?php if ($is_road_monitoring_officer): ?>
    <style>
        @media (max-width: 768px) {
            body.rmo-view .projects-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            body.rmo-view .project-item {
                border-radius: 10px;
            }
            body.rmo-view .project-item .project-thumb {
                height: 100px;
            }
            body.rmo-view .project-item .project-info {
                padding: 10px 12px;
            }
            body.rmo-view .project-item .project-info h4 {
                font-size: 12px;
                margin-bottom: 3px;
            }
            body.rmo-view .project-item .project-info .project-meta {
                font-size: 10px;
            }
            body.rmo-view .project-item .project-info .project-cost {
                font-size: 11px;
                padding: 3px 8px;
            }
        }
        @media (max-width: 480px) {
            body.rmo-view .projects-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
        }
    </style>
    <?php endif; ?>
    <?php if ($is_road_supervisor): ?>
    <style>
        @media (max-width: 768px) {
            body.road-supervisor-view .projects-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            body.road-supervisor-view .project-item {
                border-radius: 10px;
            }
            body.road-supervisor-view .project-item .project-thumb {
                height: 100px;
            }
            body.road-supervisor-view .project-item .project-info {
                padding: 10px 12px;
            }
            body.road-supervisor-view .project-item .project-info h4 {
                font-size: 12px;
                margin-bottom: 3px;
            }
            body.road-supervisor-view .project-item .project-info .project-meta {
                font-size: 10px;
            }
            body.road-supervisor-view .project-item .project-info .project-cost {
                font-size: 11px;
                padding: 3px 8px;
            }
        }
        @media (max-width: 480px) {
            body.road-supervisor-view .projects-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 8px;
            }
        }
    </style>
    <?php endif; ?>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?><?php echo $is_trans_ops_supervisor ? ' trans-supervisor-view' : ''; ?><?php echo $is_admin ? ' system-admin-view' : ''; ?><?php echo $is_road_monitoring_officer ? ' rmo-view' : ''; ?><?php echo $is_road_supervisor ? ' road-supervisor-view' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content">
        <!-- Header -->
        <div class="transparency-header">
            <div class="header-content">
                <div class="header-title">
                    <div class="header-icon"><i class="fas fa-exchange-alt"></i></div>
                    <div>
                        <h1>Public Transparency – Completed Projects</h1>
                        <?php if ($is_admin): ?>
                        <p>Welcome, Administrator — manage the before &amp; after photo projects shown on the landing page</p>
                        <?php else: ?>
                        <p>View completed projects with before &amp; after photos (View Only)</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="header-actions">
                    <div class="header-datetime">
                        <i class="fas fa-calendar-day"></i>
                        <div>
                            <div id="dtDate"></div>
                            <div id="dtTime"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="pt-tabs" role="tablist" aria-label="Public transparency sections">
            <button type="button" class="pt-tab active" role="tab" aria-selected="true" data-pt-tab="projects" id="ptTabProjects">
                <i class="fas fa-images"></i> Projects
            </button>
            <button type="button" class="pt-tab" role="tab" aria-selected="false" data-pt-tab="announcements" id="ptTabAnnouncements">
                <i class="fas fa-bullhorn"></i> Announcements
            </button>
            <button type="button" class="pt-tab" role="tab" aria-selected="false" data-pt-tab="feedback" id="ptTabFeedback">
                <i class="fas fa-star"></i> Citizen Feedback
            </button>
        </div>

        <div class="pt-tab-panel active" id="ptPanelProjects" role="tabpanel" aria-labelledby="ptTabProjects">
        <!-- Stats -->
        <div class="transparency-stats">
            <div class="transparency-stat">
                <div class="stat-number" id="statTotal"><span class="stat-icon"><i class="fas fa-folder-open"></i></span><?php echo count($projects); ?></div>
                <div class="stat-label">Total Projects</div>
            </div>
            <div class="transparency-stat stat-amber">
                <div class="stat-number" id="statWithBefore"><span class="stat-icon"><i class="fas fa-hourglass-half"></i></span><?php echo count(array_filter($projects, fn($p) => !empty($p['before_photo']))); ?></div>
                <div class="stat-label">With Before Photo</div>
            </div>
            <div class="transparency-stat stat-emerald">
                <div class="stat-number" id="statWithAfter"><span class="stat-icon"><i class="fas fa-image"></i></span><?php echo count(array_filter($projects, fn($p) => !empty($p['photo']))); ?></div>
                <div class="stat-label">With After Photo</div>
            </div>
            <div class="transparency-stat stat-violet">
                <div class="stat-number" id="statTotalCost"><span class="stat-icon"><i class="fas fa-peso-sign"></i></span>₱<?php echo number_format(array_sum(array_column($projects, 'cost')), 0); ?></div>
                <div class="stat-label">Total Project Cost</div>
            </div>
        </div>

        <?php if ($is_admin): ?>
        <!-- Approved transparency request being imported for review -->
        <div class="import-review-banner" id="importReviewBanner" style="display:none">
            <div class="notice-icon"><i class="fas fa-file-import"></i></div>
            <div>
                <h4 id="importReviewTitle">Imported from an approved transparency request</h4>
                <p id="importReviewText">Review the fields below, then save or publish the project.</p>
            </div>
        </div>

        <!-- Add / Edit Form - Admin Only -->
        <div class="project-form-card panel-project" id="projectForm">
            <div class="section-header panel-project-header">
                <h3 class="section-title" id="formTitle"><i class="fas fa-plus-circle"></i> Add New Project</h3>
                <div class="form-header-actions">
                    <button type="button" class="btn-import" id="btnImportExport" onclick="document.getElementById('progressExportInput').click()"
                            title="Fill this form from a Progress Updates export (.doc)">
                        <i class="fas fa-file-import"></i> Import
                    </button>
                    <input type="file" id="progressExportInput" accept=".doc" style="display:none" onchange="handleProgressExportImport(this)">
                    <button class="btn-cancel" id="btnCancelEdit" style="display:none" onclick="resetForm()">
                        <i class="fas fa-times"></i> Cancel Edit
                    </button>
                </div>
            </div>

            <form id="projectFormEl" enctype="multipart/form-data">
                <input type="hidden" id="projectId" value="">
                <input type="hidden" id="sourceReportId" value="">
                <input type="hidden" id="sourceReportSource" value="">
                <input type="hidden" id="reporterName" value="">
                <input type="hidden" id="reporterEmail" value="">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="projectTitle">Project Title *</label>
                        <input type="text" id="projectTitle" required placeholder="e.g. Road Repair - Barangay Central">
                    </div>

                    <div class="form-group full-width">
                        <label for="projectDesc">Description</label>
                        <textarea id="projectDesc" placeholder="Brief description of the project..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="projectLocation">Location</label>
                        <input type="text" id="projectLocation" placeholder="e.g. Quezon Avenue, Quezon City">
                    </div>

                    <div class="form-group">
                        <label for="projectStartDate">Start Date</label>
                        <input type="date" id="projectStartDate">
                        <span class="field-hint">From the first progress update in the import</span>
                    </div>

                    <div class="form-group">
                        <label for="projectEndDate">End Date</label>
                        <input type="date" id="projectEndDate">
                        <span class="field-hint">From the last progress update in the import</span>
                    </div>

                    <div class="form-group">
                        <label for="projectDate">Completion Date</label>
                        <input type="date" id="projectDate">
                    </div>

                    <div class="form-group">
                        <label for="projectCost">Cost (₱)</label>
                        <input type="number" id="projectCost" min="0" step="0.01" placeholder="0.00">
                    </div>

                    <div class="form-group">
                        <label for="projectCompletedBy">Completed By</label>
                        <input type="text" id="projectCompletedBy" placeholder="e.g. DPWH, Private Contractor">
                        <span class="field-hint">The report's engineer when imported</span>
                    </div>

                    <div class="form-group full-width">
                        <label for="projectConductedBy">Progress Updates Conducted By</label>
                        <input type="text" id="projectConductedBy" placeholder="e.g. Engr. Ramon Cruz, Officer Dela Cruz">
                        <span class="field-hint">Staff who posted the progress updates for this project</span>
                    </div>

                    <div class="form-group">
                        <label>Before Photo</label>
                        <div class="photo-upload-area" id="beforePhotoArea" onclick="document.getElementById('beforePhotoInput').click()">
                            <input type="file" id="beforePhotoInput" accept="image/*" onchange="handlePhotoSelect(this, 'before')">
                            <i class="fas fa-image upload-icon"></i>
                            <span class="upload-text">Click to upload before photo</span>
                            <img id="beforePhotoPreview" style="display:none">
                            <button type="button" class="remove-photo" style="display:none" onclick="event.stopPropagation(); removePhoto('before')"><i class="fas fa-times"></i></button>
                        </div>
                        <input type="hidden" id="beforePhotoPath" value="">
                        <input type="hidden" id="beforePhotoFingerprint" value="">
                    </div>

                    <div class="form-group">
                        <label>After Photo *</label>
                        <div class="photo-upload-area" id="afterPhotoArea" onclick="document.getElementById('afterPhotoInput').click()">
                            <input type="file" id="afterPhotoInput" accept="image/*" onchange="handlePhotoSelect(this, 'after')">
                            <i class="fas fa-image upload-icon"></i>
                            <span class="upload-text">Click to upload after photo</span>
                            <img id="afterPhotoPreview" style="display:none">
                            <button type="button" class="remove-photo" style="display:none" onclick="event.stopPropagation(); removePhoto('after')"><i class="fas fa-times"></i></button>
                        </div>
                        <input type="hidden" id="afterPhotoPath" value="">
                        <input type="hidden" id="afterPhotoFingerprint" value="">
                    </div>

                    <div class="form-group full-width">
                        <div class="publish-toggle">
                            <label class="publish-switch" for="isPublished">
                                <input type="checkbox" id="isPublished" class="publish-checkbox" role="switch" aria-describedby="publishHint">
                                <span class="toggle-slider" aria-hidden="true"></span>
                                <span class="toggle-text" id="publishToggleText" data-off="Save as Draft" data-on="Publish to Public">Save as Draft</span>
                            </label>
                            <p class="publish-hint" id="publishHint">Project will be saved but not visible to the public</p>
                        </div>
                    </div>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn-save" id="btnSave">
                        <i class="fas fa-save"></i> Save Project
                    </button>
                    <button type="button" class="btn-cancel" onclick="resetForm()"><i class="fas fa-xmark"></i> Cancel</button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <!-- View-Only Notice for LGU Staff -->
        <div class="view-only-notice">
            <div class="notice-icon">
                <i class="fas fa-eye"></i>
            </div>
            <div>
                <h4><i class="fas fa-lock"></i> View Only Mode</h4>
                <p>You can view completed projects created by the administrator. Only administrators can create, edit, or delete projects.</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Projects Grid -->
        <div class="projects-section">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-images"></i> Published Projects</h3>
            </div>

            <?php if (empty($projects)): ?>
            <div class="empty-state">
                <i class="fas fa-images"></i>
                <h5>No Projects Yet</h5>
                <p>Add your first completed project above to see it on the landing page.</p>
            </div>
            <?php else: ?>
            <div class="projects-grid" id="projectsGrid">
                <?php foreach ($projects as $proj):
                    $after_img = !empty($proj['photo']) ? htmlspecialchars('../../../' . ltrim(str_replace(['../', '..\\'], '', $proj['photo']), '/\\')) : '';
                    $before_img = !empty($proj['before_photo']) ? htmlspecialchars('../../../' . ltrim(str_replace(['../', '..\\'], '', $proj['before_photo']), '/\\')) : '';
                    $has_before = !empty($proj['before_photo']);
                    $ph_after = 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="375"><rect width="100%" height="100%" fill="#059669"/><g fill="#ffffff" font-family="Poppins, Arial, sans-serif" font-size="28" font-weight="600" text-anchor="middle"><text x="300" y="178">After Photo</text></g></svg>');
                    $ph_before = 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="600" height="375"><rect width="100%" height="100%" fill="#b91c1c"/><g fill="#ffffff" font-family="Poppins, Arial, sans-serif" font-size="28" font-weight="600" text-anchor="middle"><text x="300" y="178">No Before Photo</text></g></svg>');
                ?>
                <div class="project-item" data-id="<?php echo $proj['id']; ?>">
                    <div class="project-preview" data-preview>
                        <?php if ($after_img): ?>
                        <img src="<?php echo $after_img; ?>" alt="After" class="preview-after" onerror="this.src='<?php echo $ph_after; ?>'">
                        <?php else: ?>
                        <img src="<?php echo $ph_after; ?>" alt="After" class="preview-after">
                        <?php endif; ?>

                        <?php if ($has_before): ?>
                        <img src="<?php echo $before_img; ?>" alt="Before" class="preview-before" onerror="this.src='<?php echo $ph_before; ?>'">
                        <?php else: ?>
                        <img src="<?php echo $ph_before; ?>" alt="Before" class="preview-before">
                        <?php endif; ?>

                        <div class="preview-handle"></div>
                        <span class="preview-label label-before">Before</span>
                        <span class="preview-label label-after">After</span>

                        <?php if (!$has_before): ?>
                        <div class="no-before"><i class="fas fa-info-circle"></i> &nbsp;No before photo — showing after only</div>
                        <?php endif; ?>
                    </div>

                    <div class="project-details">
                        <h4><?php echo htmlspecialchars($proj['title']); ?></h4>
                        <div class="project-meta">
                            <?php if (!empty($proj['location'])): ?>
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($proj['location']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($proj['completed_date'])): ?>
                            <span><i class="fas fa-calendar-check"></i> <?php echo date('M d, Y', strtotime($proj['completed_date'])); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($proj['completed_by'])): ?>
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($proj['completed_by']); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($proj['cost'])): ?>
                        <span class="project-cost">₱<?php echo number_format($proj['cost'], 0); ?></span>
                        <?php endif; ?>
                        <?php if ($is_admin): ?>
                        <span class="publish-badge <?php echo !empty($proj['is_published']) ? 'published' : 'draft'; ?>">
                            <i class="fas <?php echo !empty($proj['is_published']) ? 'fa-globe' : 'fa-file-alt'; ?>"></i>
                            <?php echo !empty($proj['is_published']) ? 'Published' : 'Draft'; ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <?php if ($is_admin): ?>
                    <div class="project-actions">
                        <button class="btn-edit" onclick="editProject(<?php echo htmlspecialchars(json_encode($proj)); ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-publish" onclick="togglePublish(<?php echo $proj['id']; ?>, '<?php echo htmlspecialchars(addslashes($proj['title'])); ?>')" title="<?php echo !empty($proj['is_published']) ? 'Unpublish' : 'Publish to public'; ?>">
                            <i class="fas <?php echo !empty($proj['is_published']) ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                            <?php echo !empty($proj['is_published']) ? 'Unpublish' : 'Publish'; ?>
                        </button>
                        <button class="btn-delete" onclick="deleteProject(<?php echo $proj['id']; ?>, '<?php echo htmlspecialchars(addslashes($proj['title'])); ?>')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        </div><!-- /ptPanelProjects -->

        <div class="pt-tab-panel" id="ptPanelAnnouncements" role="tabpanel" aria-labelledby="ptTabAnnouncements" hidden>
        <!-- Public Announcements → index.php only (separate from internal announcements.php) -->
        <div class="announcements-section" id="announcementsSection">
            <div class="section-header">
                <h3 class="section-title"><i class="fas fa-bullhorn"></i> Public Announcements</h3>
            </div>

            <?php if ($is_admin): ?>
            <div class="project-form-card announcement-form-card" id="ptAnnouncementFormCard">
                <div class="section-header">
                    <h3 class="section-title" id="ptAnnouncementFormTitle"><i class="fas fa-plus-circle"></i> Create Announcement</h3>
                    <button type="button" class="btn-cancel" id="ptBtnCancelAnnouncementEdit" style="display:none" onclick="ptResetAnnouncementForm()">
                        <i class="fas fa-times"></i> Cancel Edit
                    </button>
                </div>
                <form id="ptAnnouncementFormEl">
                    <input type="hidden" id="ptAnnouncementId" value="">
                    <input type="hidden" id="ptAnnouncementPhotoPath" value="">
                    <div class="form-grid">
                        <div class="form-group full-width">
                            <label for="ptAnnouncementTitle">Title *</label>
                            <input type="text" id="ptAnnouncementTitle" required maxlength="255" placeholder="e.g. Road closure advisory">
                        </div>
                        <div class="form-group full-width">
                            <label for="ptAnnouncementContent">Message *</label>
                            <textarea id="ptAnnouncementContent" required rows="4" placeholder="Write the public announcement message..."></textarea>
                        </div>
                        <div class="form-group">
                            <label for="ptAnnouncementPostedAt">Date Posted *</label>
                            <input type="date" id="ptAnnouncementPostedAt" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                            <label class="publish-switch publish-switch--sm" for="ptAnnouncementPublished">
                                <input type="checkbox" id="ptAnnouncementPublished" class="publish-checkbox" role="switch" checked>
                                <span class="toggle-slider"></span>
                                <span class="toggle-text" id="ptAnnPublishLabel">Published</span>
                            </label>
                        </div>
                        <div class="form-group full-width">
                            <label>Photo (optional)</label>
                            <div class="photo-upload-area" id="ptAnnouncementPhotoArea" onclick="document.getElementById('ptAnnouncementPhotoInput').click()">
                                <input type="file" id="ptAnnouncementPhotoInput" accept="image/*" onclick="event.stopPropagation()" onchange="ptHandleAnnouncementPhoto(this)">
                                <i class="fas fa-image upload-icon"></i>
                                <span class="upload-text">Click to upload a photo</span>
                                <img id="ptAnnouncementPhotoPreview" alt="" style="display:none">
                                <button type="button" class="remove-photo" id="ptAnnouncementPhotoRemove" style="display:none" onclick="event.stopPropagation(); ptRemoveAnnouncementPhoto()"><i class="fas fa-times"></i></button>
                            </div>
                        </div>
                    </div>
                    <div class="btn-row">
                        <button type="submit" class="btn-save" id="ptBtnSaveAnnouncement">
                            <i class="fas fa-bullhorn"></i> Publish Announcement
                        </button>
                        <button type="button" class="btn-cancel" onclick="ptResetAnnouncementForm()"><i class="fas fa-xmark"></i> Cancel</button>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div class="view-only-notice announcement-view-notice">
                <div class="notice-icon"><i class="fas fa-eye"></i></div>
                <div>
                    <h4><i class="fas fa-lock"></i> View Only</h4>
                    <p>You can view announcements. Only administrators can create, edit, or delete them.</p>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($announcements)): ?>
            <div class="empty-state">
                <i class="fas fa-bullhorn"></i>
                <h5>No Announcements Yet</h5>
                <p><?php echo $is_admin ? 'Create an announcement above to show it on the public landing page.' : 'There are no published announcements right now.'; ?></p>
            </div>
            <?php else: ?>
            <div class="announcements-list">
                <?php foreach ($announcements as $ann):
                    $has_photo = !empty($ann['photo']);
                    $photo_src = $has_photo ? public_announcements_photo_src($ann['photo'], 'shared') : '';
                    $is_pub = $is_admin ? !empty($ann['is_published']) : true;
                ?>
                <article class="announcement-item<?php echo $has_photo ? ' has-photo' : ''; ?><?php echo $is_pub ? '' : ' is-draft'; ?>">
                    <?php if ($has_photo): ?>
                    <div class="announcement-thumb">
                        <img src="<?php echo htmlspecialchars($photo_src); ?>" alt="">
                    </div>
                    <?php endif; ?>
                    <div class="announcement-body">
                        <div class="announcement-item-top">
                            <h4><i class="fas fa-bullhorn"></i> <?php echo htmlspecialchars($ann['title'] ?? ''); ?></h4>
                            <?php if ($is_admin): ?>
                            <span class="publish-badge <?php echo $is_pub ? 'published' : 'draft'; ?>">
                                <i class="fas <?php echo $is_pub ? 'fa-globe' : 'fa-file-alt'; ?>"></i>
                                <?php echo $is_pub ? 'Published' : 'Draft'; ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <p class="announcement-content"><?php echo nl2br(htmlspecialchars($ann['content'] ?? '')); ?></p>
                        <div class="announcement-meta">
                            <span><i class="fas fa-calendar-day"></i> <?php echo !empty($ann['posted_at']) ? date('M d, Y', strtotime($ann['posted_at'])) : '—'; ?></span>
                        </div>
                        <?php if ($is_admin): ?>
                        <div class="project-actions announcement-actions">
                            <button type="button" class="btn-edit" onclick='ptEditAnnouncement(<?php echo htmlspecialchars(json_encode([
                                'id' => (int)$ann['id'],
                                'title' => (string)($ann['title'] ?? ''),
                                'content' => (string)($ann['content'] ?? ''),
                                'photo' => (string)($ann['photo'] ?? ''),
                                'posted_at' => (string)($ann['posted_at'] ?? ''),
                                'is_published' => $is_pub ? 1 : 0,
                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES); ?>)'>
                                <i class="fas fa-edit"></i> Edit
                            </button>
                            <button type="button" class="btn-publish" onclick="ptToggleAnnouncementPublish(<?php echo (int)$ann['id']; ?>)">
                                <i class="fas <?php echo $is_pub ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                <?php echo $is_pub ? 'Unpublish' : 'Publish'; ?>
                            </button>
                            <button type="button" class="btn-delete" onclick="ptDeleteAnnouncement(<?php echo (int)$ann['id']; ?>, <?php echo htmlspecialchars(json_encode((string)($ann['title'] ?? '')), ENT_QUOTES); ?>)">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        </div><!-- /ptPanelAnnouncements -->

        <div class="pt-tab-panel" id="ptPanelFeedback" role="tabpanel" aria-labelledby="ptTabFeedback" hidden>
            <div class="cf-feedback-wrap">
                <div class="cf-panel" id="cfReportPanel">
                    <div class="section-header">
                        <h3 class="section-title"><i class="fas fa-map-marker-alt"></i> Report Feedback</h3>
                        <p class="cf-panel-sub">Ratings on completed transparency projects</p>
                    </div>
                    <div class="cf-dash" id="cfReportDash">
                        <div class="cf-dash-card cf-avg">
                            <div class="cf-dash-value" id="cfReportAvg">—</div>
                            <div class="cf-dash-label">Average</div>
                        </div>
                        <div class="cf-dash-card">
                            <div class="cf-dash-value" id="cfReportTotal">0</div>
                            <div class="cf-dash-label">Total ratings</div>
                        </div>
                        <div class="cf-star-counts" id="cfReportCounts">
                            <div class="cf-star-row" data-star="5"><span>5★</span><div class="cf-bar"><i style="width:0%"></i></div><b>0</b></div>
                            <div class="cf-star-row" data-star="4"><span>4★</span><div class="cf-bar"><i style="width:0%"></i></div><b>0</b></div>
                            <div class="cf-star-row" data-star="3"><span>3★</span><div class="cf-bar"><i style="width:0%"></i></div><b>0</b></div>
                            <div class="cf-star-row" data-star="2"><span>2★</span><div class="cf-bar"><i style="width:0%"></i></div><b>0</b></div>
                            <div class="cf-star-row" data-star="1"><span>1★</span><div class="cf-bar"><i style="width:0%"></i></div><b>0</b></div>
                        </div>
                    </div>
                    <div class="cf-list-toolbar">
                        <label class="cf-sort-label">Sort
                            <select id="cfReportSort" class="cf-sort-select" aria-label="Sort report feedback">
                                <option value="created_at:desc" selected>Date (newest)</option>
                                <option value="created_at:asc">Date (oldest)</option>
                                <option value="rating:desc">Rating (high–low)</option>
                                <option value="rating:asc">Rating (low–high)</option>
                            </select>
                        </label>
                    </div>
                    <div class="cf-table-wrap">
                        <table class="cf-table" id="cfReportTable">
                            <thead>
                                <tr>
                                    <th>Rating</th>
                                    <th>Project ID</th>
                                    <th>Project</th>
                                    <th>Comment</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="cfReportBody">
                                <tr><td colspan="5" class="cf-empty">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="cf-pager" id="cfReportPager"></div>
                </div>

                <div class="cf-panel" id="cfServicePanel">
                    <div class="section-header">
                        <h3 class="section-title"><i class="fas fa-star"></i> Service Feedback</h3>
                        <p class="cf-panel-sub">Overall ratings from the floating Rate button</p>
                    </div>
                    <div class="cf-dash" id="cfServiceDash">
                        <div class="cf-dash-card cf-avg">
                            <div class="cf-dash-value" id="cfServiceAvg">—</div>
                            <div class="cf-dash-label">Average</div>
                        </div>
                        <div class="cf-dash-card">
                            <div class="cf-dash-value" id="cfServiceTotal">0</div>
                            <div class="cf-dash-label">Total ratings</div>
                        </div>
                        <div class="cf-star-counts" id="cfServiceCounts">
                            <div class="cf-star-row" data-star="5"><span>5★</span><div class="cf-bar"><i style="width:0%"></i></div><b>0</b></div>
                            <div class="cf-star-row" data-star="4"><span>4★</span><div class="cf-bar"><i style="width:0%"></i></div><b>0</b></div>
                            <div class="cf-star-row" data-star="3"><span>3★</span><div class="cf-bar"><i style="width:0%"></i></div><b>0</b></div>
                            <div class="cf-star-row" data-star="2"><span>2★</span><div class="cf-bar"><i style="width:0%"></i></div><b>0</b></div>
                            <div class="cf-star-row" data-star="1"><span>1★</span><div class="cf-bar"><i style="width:0%"></i></div><b>0</b></div>
                        </div>
                    </div>
                    <div class="cf-list-toolbar">
                        <label class="cf-sort-label">Sort
                            <select id="cfServiceSort" class="cf-sort-select" aria-label="Sort service feedback">
                                <option value="created_at:desc" selected>Date (newest)</option>
                                <option value="created_at:asc">Date (oldest)</option>
                                <option value="rating:desc">Rating (high–low)</option>
                                <option value="rating:asc">Rating (low–high)</option>
                            </select>
                        </label>
                    </div>
                    <div class="cf-table-wrap">
                        <table class="cf-table" id="cfServiceTable">
                            <thead>
                                <tr>
                                    <th>Rating</th>
                                    <th>Comment</th>
                                    <th>Page</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody id="cfServiceBody">
                                <tr><td colspan="4" class="cf-empty">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="cf-pager" id="cfServicePager"></div>
                </div>
            </div>
        </div><!-- /ptPanelFeedback -->
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <script src="../../js/progress-export-import.js?v=<?php echo filemtime(__DIR__ . '/../../js/progress-export-import.js'); ?>"></script>
    <script>
    const API = '../../pages/api/completed_projects_api.php';
    let isEditing = false;

    // ─── Header Date / Time ─────────────────────────────────────
    function updateHeaderDateTime() {
        const now = new Date();
        const dateOpts = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
        const timeOpts = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
        const dEl = document.getElementById('dtDate');
        const tEl = document.getElementById('dtTime');
        if (dEl) dEl.textContent = now.toLocaleDateString('en-US', dateOpts);
        if (tEl) tEl.textContent = now.toLocaleTimeString('en-US', timeOpts);
    }
    updateHeaderDateTime();
    setInterval(updateHeaderDateTime, 1000);

    // ─── Photo Upload ─────────────────────────────────────
    const DUPLICATE_PHOTO_MSG = 'The same photo cannot be used for both Before and After. Please upload a different image for each field.';

    async function hashFile(file) {
        const buf = await file.arrayBuffer();
        const hash = await crypto.subtle.digest('SHA-256', buf);
        return Array.from(new Uint8Array(hash)).map(b => b.toString(16).padStart(2, '0')).join('');
    }

    function getPhotoFingerprint(type) {
        const el = document.getElementById(type + 'PhotoFingerprint');
        return el ? el.value.trim() : '';
    }

    function setPhotoFingerprint(type, value) {
        const el = document.getElementById(type + 'PhotoFingerprint');
        if (el) el.value = value || '';
    }

    function beforeAfterPhotosAreIdentical() {
        const beforePath = (document.getElementById('beforePhotoPath')?.value || '').trim();
        const afterPath = (document.getElementById('afterPhotoPath')?.value || '').trim();
        if (beforePath !== '' && afterPath !== '' && beforePath === afterPath) {
            return true;
        }
        const beforeFp = getPhotoFingerprint('before');
        const afterFp = getPhotoFingerprint('after');
        return beforeFp !== '' && afterFp !== '' && beforeFp === afterFp;
    }

    async function wouldDuplicatePhoto(type, file) {
        if (!file) return false;
        const otherType = type === 'before' ? 'after' : 'before';
        const otherInput = document.getElementById(otherType + 'PhotoInput');
        const otherFile = otherInput?.files?.[0];
        const fileHash = await hashFile(file);

        if (otherFile) {
            const otherHash = await hashFile(otherFile);
            if (fileHash === otherHash) return true;
        }

        const otherFp = getPhotoFingerprint(otherType);
        if (otherFp !== '' && fileHash === otherFp) return true;

        return false;
    }

    function rejectDuplicatePhoto(type) {
        showToast(DUPLICATE_PHOTO_MSG, 'error');
        removePhoto(type);
    }

    function applyUploadedPhoto(type, path) {
        const area = document.getElementById(type + 'PhotoArea');
        const preview = document.getElementById(type + 'PhotoPreview');
        const pathInput = document.getElementById(type + 'PhotoPath');
        if (!area || !preview || !pathInput) return;

        pathInput.value = path;
        preview.src = '../../../' + path;
        preview.style.display = 'block';
        area.querySelector('.upload-icon').style.display = 'none';
        area.querySelector('.upload-text').style.display = 'none';
        area.classList.add('has-image');
        area.querySelector('.remove-photo').style.display = 'flex';

        if (beforeAfterPhotosAreIdentical()) {
            rejectDuplicatePhoto(type);
        }
    }

    function uploadPhotoFile(type, file) {
        const field = type === 'before' ? 'before_photo' : 'photo';
        const formData = new FormData();
        formData.append('action', 'upload_photo');
        formData.append('field', field);
        formData.append(field, file);

        const area = document.getElementById(type + 'PhotoArea');
        if (area) area.style.opacity = '0.5';

        return fetch(API, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(async data => {
                if (area) area.style.opacity = '1';
                if (data.success) {
                    applyUploadedPhoto(type, data.path);
                    if (file) {
                        try {
                            setPhotoFingerprint(type, await hashFile(file));
                        } catch (e) {
                            setPhotoFingerprint(type, '');
                        }
                    }
                    if (beforeAfterPhotosAreIdentical()) {
                        rejectDuplicatePhoto(type);
                        return false;
                    }
                    return true;
                }
                showToast(data.message || 'Upload failed', 'error');
                return false;
            })
            .catch(() => {
                if (area) area.style.opacity = '1';
                showToast('Upload error', 'error');
                return false;
            });
    }

    async function handlePhotoSelect(input, type) {
        const file = input.files[0];
        if (!file) return;
        try {
            if (await wouldDuplicatePhoto(type, file)) {
                showToast(DUPLICATE_PHOTO_MSG, 'error');
                input.value = '';
                return;
            }
        } catch (e) {
            showToast('Could not verify the selected photo. Please try again.', 'error');
            input.value = '';
            return;
        }
        uploadPhotoFile(type, file);
    }

    function removePhoto(type) {
        const area = document.getElementById(type + 'PhotoArea');
        const preview = document.getElementById(type + 'PhotoPreview');
        const pathInput = document.getElementById(type + 'PhotoPath');
        const input = document.getElementById(type + 'PhotoInput');
        const removeBtn = area.querySelector('.remove-photo');

        pathInput.value = '';
        setPhotoFingerprint(type, '');
        preview.src = '';
        preview.style.display = 'none';
        input.value = '';
        area.querySelector('.upload-icon').style.display = '';
        area.querySelector('.upload-text').style.display = '';
        area.classList.remove('has-image');
        removeBtn.style.display = 'none';
    }

    // ─── Import from Progress Updates Export ──────────────
    // Reads the .doc export shared by completed_projects.php, archive.php and
    // road_transportation_monitoring.php and pre-fills this form from it.
    const IMPORT_IMAGE_EXT = { 'image/jpeg': 'jpg', 'image/jpg': 'jpg', 'image/png': 'png', 'image/gif': 'gif', 'image/webp': 'webp' };

    function dataUrlToFile(dataUrl, baseName) {
        let raw = String(dataUrl || '').trim();
        const dataIdx = raw.toLowerCase().indexOf('data:image');
        if (dataIdx > 0) raw = raw.slice(dataIdx);
        const comma = raw.indexOf(',');
        if (comma < 0) return null;
        const header = raw.slice(0, comma).replace(/\s+/g, '');
        const payload = raw.slice(comma + 1).replace(/\s+/g, '');
        const mimeMatch = /^data:([^;,]+)/i.exec(header);
        if (!mimeMatch || !payload) return null;
        const mime = mimeMatch[1].toLowerCase();
        const ext = IMPORT_IMAGE_EXT[mime];
        if (!ext) return null;
        let bytes;
        try {
            const binary = atob(payload);
            bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        } catch (e) {
            return null;
        }
        return new File([bytes], baseName + '.' + ext, { type: mime });
    }

    function setHiddenField(id, value) {
        const el = document.getElementById(id);
        if (!el) return;
        el.value = value == null ? '' : String(value);
    }

    function setImportedField(id, value) {
        const el = document.getElementById(id);
        if (!el || !value) return false;
        el.value = value;
        return true;
    }

    function showImportedPhotoPreview(type, dataUrl) {
        const area = document.getElementById(type + 'PhotoArea');
        const preview = document.getElementById(type + 'PhotoPreview');
        if (!area || !preview || !dataUrl) return;
        preview.src = dataUrl;
        preview.style.display = 'block';
        const icon = area.querySelector('.upload-icon');
        const text = area.querySelector('.upload-text');
        if (icon) icon.style.display = 'none';
        if (text) text.style.display = 'none';
        area.classList.add('has-image');
        const removeBtn = area.querySelector('.remove-photo');
        if (removeBtn) removeBtn.style.display = 'flex';
    }

    function importPhotoFromExport(type, dataUrl) {
        showImportedPhotoPreview(type, dataUrl);
        const file = dataUrlToFile(dataUrl, 'imported-' + type + '-' + Date.now());
        if (!file) return Promise.resolve(false);
        return uploadPhotoFile(type, file);
    }

    function importPhotosFromExportDoc(data) {
        const jobs = [];
        if (data.beforeImage) jobs.push(importPhotoFromExport('before', data.beforeImage));
        if (data.afterImage) jobs.push(importPhotoFromExport('after', data.afterImage));
        if (!jobs.length) return Promise.resolve(0);
        return Promise.all(jobs).then(results => results.filter(Boolean).length);
    }

    function fetchReportPhotosForImport(reportId, source) {
        const url = API + '?action=import_report_photos&report_id=' + encodeURIComponent(reportId)
            + '&source=' + encodeURIComponent(source || 'transport');
        return fetch(url)
            .then(r => r.json())
            .then(resp => {
                if (!resp || !resp.success || !resp.data) return 0;
                let count = 0;
                if (resp.data.before_photo) {
                    applyUploadedPhoto('before', resp.data.before_photo);
                    count++;
                }
                if (resp.data.photo) {
                    applyUploadedPhoto('after', resp.data.photo);
                    count++;
                }
                if (beforeAfterPhotosAreIdentical()) {
                    showToast(DUPLICATE_PHOTO_MSG, 'error');
                    removePhoto('after');
                }
                return count;
            })
            .catch(() => 0);
    }

    function loadImportedPhotos(data) {
        if (data.sourceReportId) {
            return fetchReportPhotosForImport(data.sourceReportId, data.sourceReportSource || 'transport')
                .then(count => {
                    if (count > 0) return { count: count, fromServer: true };
                    return importPhotosFromExportDoc(data).then(fallback => ({ count: fallback, fromServer: false }));
                });
        }
        return importPhotosFromExportDoc(data).then(count => ({ count: count, fromServer: false }));
    }

    function applyImportedProject(data) {
        let imported = 0;
        if (setImportedField('projectTitle', data.title)) imported++;
        if (setImportedField('projectDesc', data.description)) imported++;
        if (setImportedField('projectLocation', data.location)) imported++;
        // Start/End come from the first and last progress update in the export.
        if (setImportedField('projectStartDate', data.actualStartDate)) imported++;
        if (setImportedField('projectEndDate', data.actualEndDate)) imported++;
        if (setImportedField('projectDate', data.completionDate || data.actualEndDate)) imported++;
        if (setImportedField('projectCost', data.cost)) imported++;
        if (setImportedField('projectCompletedBy', data.completedBy)) imported++;

        if (data.sourceReportId) {
            setHiddenField('sourceReportId', data.sourceReportId);
            setHiddenField('sourceReportSource', data.sourceReportSource || 'transport');
            setHiddenField('reporterName', data.reporterName || '');
            setHiddenField('reporterEmail', data.reporterEmail || '');
            showCitizenImportBanner(data.sourceReportCode || data.reportId || '', data.reporterName);
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });

        return loadImportedPhotos(data).then(photoResult => {
            imported += photoResult.count;
            if (!imported && !data.sourceReportId) {
                showToast('This export had no fields that apply to a project', 'error');
            } else {
                let msg = data.sourceReportId
                    ? 'Imported from citizen report ' + (data.sourceReportCode || data.reportId || '') + ' — review, then save or publish'
                    : 'Imported ' + imported + ' field(s) — review, then save';
                if (data.sourceReportId && photoResult.fromServer) {
                    msg = 'Imported with full-resolution photos from progress updates — review, then save or publish';
                }
                showToast(msg, 'success');
            }
        });
    }

    function mergeCitizenReportIntoImport(data, report) {
        if (!report) return data;
        data.sourceReportId = report.id || '';
        data.sourceReportCode = report.report_id || data.reportId || '';
        const src = String(report.source || '').toLowerCase();
        data.sourceReportSource = src || 'transport';
        data.reporterName = report.reporter_name || '';
        data.reporterEmail = report.reporter_email || '';
        if (!data.title && report.title) data.title = report.title;
        if (!data.description && report.description) data.description = report.description;
        if (!data.location && report.location) data.location = report.location;
        if (!data.cost && report.budget_allocation) {
            const n = parseFloat(String(report.budget_allocation).replace(/[^\d.-]/g, ''));
            if (isFinite(n) && n >= 0) data.cost = n.toFixed(2);
        }
        if (!data.completedBy && report.engineer) data.completedBy = report.engineer;
        return data;
    }

    function lookupCitizenReportForImport(reportId) {
        return fetch(API + '?action=lookup_source_report&report_id=' + encodeURIComponent(reportId))
            .then(r => r.json())
            .then(resp => (resp && resp.success && resp.data) ? resp.data : null)
            .catch(() => null);
    }

    function showCitizenImportBanner(reportId, reporterName) {
        const banner = document.getElementById('importReviewBanner');
        if (!banner) return;
        const who = reporterName ? reporterName : 'the citizen reporter';
        document.getElementById('importReviewTitle').textContent = 'Linked to citizen report ' + reportId;
        document.getElementById('importReviewText').textContent =
            'This export is tied to ' + reportId + '. Text fields come from the export; before/after photos use full-resolution progress-update files when available. '
            + who + ' will be emailed when you publish (not when saving as draft).';
        banner.style.display = 'flex';
    }

    function handleProgressExportImport(input) {
        const file = input.files && input.files[0];
        input.value = ''; // let the same file be picked again
        if (!file) return;

        if (!window.ProgressExportImport) {
            showToast('Import helper failed to load — refresh and try again', 'error');
            return;
        }

        const btn = document.getElementById('btnImportExport');
        const btnLabel = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
        }

        window.ProgressExportImport.readExportFile(file)
            .then(data => {
                const reportId = (data.reportId || '').trim();
                // Resolve RPT-/CIT-/REQ- (and other) codes to numeric id + source so
                // published_completed_projects.source_report_id is stored for Public status.
                if (reportId) {
                    return lookupCitizenReportForImport(reportId).then(report => mergeCitizenReportIntoImport(data, report));
                }
                return data;
            })
            .then(applyImportedProject)
            .catch(err => showToast(err && err.message ? err.message : 'Could not import this file', 'error'))
            .then(() => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = btnLabel;
                }
            });
    }

    // ─── Auto-import an approved transparency request ─────
    // The admin lands here straight from approving the request, so the approved
    // project's own progress-update data is pulled from the server instead of
    // making the admin download and re-upload an export. Nothing is written
    // until the admin reviews the fields and submits the form.
    const TRANSPARENCY_REQUEST_API = '../api/transparency_request_api.php';
    const PREFILL_REQUEST_ID = <?php echo (int)$prefill_request_id; ?>;

    function showImportReviewBanner(req, data) {
        const banner = document.getElementById('importReviewBanner');
        if (!banner) return;
        const id = (req && req.id) ? req.id : PREFILL_REQUEST_ID;
        const who = (req && req.requested_by_name) ? req.requested_by_name : 'the Road Operations Supervisor';
        const project = (req && req.report_title) ? '"' + req.report_title + '"' : 'the approved project';
        const reporter = (data && data.reporter_name) ? data.reporter_name : '';
        const email = (data && data.reporter_email) ? data.reporter_email : '';
        let extra = '';
        if (reporter || email) {
            extra = ' Linked reporter: '
                + (reporter || 'citizen')
                + (email ? ' (' + email + ')' : '')
                + ' — they will be emailed when you publish.';
        }
        document.getElementById('importReviewTitle').textContent = 'Imported from approved request #' + id;
        document.getElementById('importReviewText').textContent =
            'Progress update data for ' + project + ', requested by ' + who
            + ', has been filled in below. Review every field, then save as draft or publish.'
            + extra;
        banner.style.display = 'flex';
    }

    function applyPrefilledProject(data) {
        setImportedField('projectTitle', data.title);
        setImportedField('projectDesc', data.description);
        setImportedField('projectLocation', data.location);
        setImportedField('projectStartDate', data.first_update_date);
        setImportedField('projectEndDate', data.last_update_date);
        setImportedField('projectDate', data.completed_date || data.last_update_date);
        setImportedField('projectCost', data.cost ? Number(data.cost).toFixed(2) : '');
        setImportedField('projectCompletedBy', data.completed_by);
        setImportedField('projectConductedBy', data.progress_conducted_by);

        // Link back to the source report so publish can email the citizen reporter.
        setHiddenField('sourceReportId', data.source_report_id || '');
        setHiddenField('sourceReportSource', data.source_report_source || '');
        setHiddenField('reporterName', data.reporter_name || '');
        setHiddenField('reporterEmail', data.reporter_email || '');

        // Photos were copied into the completed-projects folder server-side, so
        // the form only needs to point at them.
        if (data.before_photo) applyUploadedPhoto('before', data.before_photo);
        if (data.photo) applyUploadedPhoto('after', data.photo);
        if (beforeAfterPhotosAreIdentical()) {
            showToast(DUPLICATE_PHOTO_MSG, 'error');
            removePhoto('after');
        }
    }

    function loadApprovedTransparencyPrefill() {
        if (!PREFILL_REQUEST_ID || !document.getElementById('projectFormEl')) return;

        fetch(TRANSPARENCY_REQUEST_API + '?action=prefill&request_id=' + encodeURIComponent(PREFILL_REQUEST_ID))
            .then(r => r.json())
            .then(resp => {
                if (!resp || !resp.success || !resp.data) {
                    showToast((resp && resp.message) || 'Could not import the approved project', 'error');
                    return;
                }
                applyPrefilledProject(resp.data);
                showImportReviewBanner(resp.request, resp.data);
                // Drop the parameter so the reload after saving does not re-import.
                if (window.history && window.history.replaceState) {
                    window.history.replaceState({}, '', 'public_transparency.php');
                }
                showToast('Approved project imported — review, then save', 'success');
                window.scrollTo({ top: 0, behavior: 'smooth' });
            })
            .catch(() => showToast('Network error while importing the approved project', 'error'));
    }

    loadApprovedTransparencyPrefill();

    // ─── Publish Toggle Text ───────────────────────────────
    const publishCheckbox = document.getElementById('isPublished');
    const publishHint = document.getElementById('publishHint');
    const toggleText = document.getElementById('publishToggleText');

    function syncPublishToggleUI(checked) {
        if (!publishCheckbox) return;
        publishCheckbox.checked = !!checked;
        const on = publishCheckbox.checked;
        if (toggleText) {
            toggleText.textContent = on
                ? (toggleText.dataset.on || 'Publish to Public')
                : (toggleText.dataset.off || 'Save as Draft');
        }
        if (publishHint) {
            publishHint.textContent = on
                ? 'Project will be visible on the public transparency page. Linked citizen reporters are emailed on first publish.'
                : 'Project will be saved but not visible to the public';
        }
    }

    if (publishCheckbox) {
        publishCheckbox.addEventListener('change', function() {
            syncPublishToggleUI(this.checked);
        });
    }

    // ─── Form Submit ──────────────────────────────────────
    function onProjectFormSubmit(e) {
        e.preventDefault();

        const title = document.getElementById('projectTitle').value.trim();
        if (!title) { showToast('Title is required', 'error'); return; }

        const afterPath = document.getElementById('afterPhotoPath').value;
        if (!isEditing && !afterPath) { showToast('After photo is required', 'error'); return; }

        const beforePath = (document.getElementById('beforePhotoPath').value || '').trim();
        if (beforePath !== '' && afterPath !== '' && beforeAfterPhotosAreIdentical()) {
            showToast(DUPLICATE_PHOTO_MSG, 'error');
            return;
        }

        const formData = new FormData();
        formData.append('action', isEditing ? 'update' : 'create');
        formData.append('title', title);
        formData.append('description', document.getElementById('projectDesc').value.trim());
        formData.append('location', document.getElementById('projectLocation').value.trim());
        formData.append('completed_date', document.getElementById('projectDate').value);
        formData.append('start_date', document.getElementById('projectStartDate').value);
        formData.append('end_date', document.getElementById('projectEndDate').value);
        formData.append('cost', document.getElementById('projectCost').value || 0);
        formData.append('completed_by', document.getElementById('projectCompletedBy').value.trim());
        formData.append('progress_conducted_by', document.getElementById('projectConductedBy').value.trim());
        formData.append('photo', afterPath);
        formData.append('before_photo', document.getElementById('beforePhotoPath').value);
        formData.append('source_report_id', document.getElementById('sourceReportId').value.trim());
        formData.append('source_report_source', document.getElementById('sourceReportSource').value.trim());
        formData.append('reporter_name', document.getElementById('reporterName').value.trim());
        formData.append('reporter_email', document.getElementById('reporterEmail').value.trim());

        if (publishCheckbox && publishCheckbox.checked) {
            formData.append('is_published', '1');
        }

        const url = isEditing ? `${API}?action=update&id=${document.getElementById('projectId').value}` : `${API}?action=create`;
        const btnSave = document.getElementById('btnSave');
        btnSave.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        btnSave.disabled = true;

        fetch(url, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                btnSave.innerHTML = '<i class="fas fa-save"></i> Save Project';
                btnSave.disabled = false;
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.message || 'Error', 'error');
                }
            })
            .catch(() => {
                btnSave.innerHTML = '<i class="fas fa-save"></i> Save Project';
                btnSave.disabled = false;
                showToast('Network error', 'error');
            });
    }

    const projectFormEl = document.getElementById('projectFormEl');
    if (projectFormEl) {
        projectFormEl.addEventListener('submit', onProjectFormSubmit);
    }

    // ─── Edit ─────────────────────────────────────────────
    function editProject(project) {
        isEditing = true;
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Project';
        document.getElementById('btnCancelEdit').style.display = 'inline-flex';
        document.getElementById('btnSave').innerHTML = '<i class="fas fa-save"></i> Update Project';

        document.getElementById('projectId').value = project.id;
        document.getElementById('projectTitle').value = project.title || '';
        document.getElementById('projectDesc').value = project.description || '';
        document.getElementById('projectLocation').value = project.location || '';
        document.getElementById('projectDate').value = project.completed_date || '';
        document.getElementById('projectStartDate').value = project.start_date || '';
        document.getElementById('projectEndDate').value = project.end_date || '';
        document.getElementById('projectCost').value = project.cost || '';
        document.getElementById('projectCompletedBy').value = project.completed_by || '';
        document.getElementById('projectConductedBy').value = project.progress_conducted_by || '';
        document.getElementById('sourceReportId').value = project.source_report_id || '';
        document.getElementById('sourceReportSource').value = project.source_report_source || '';
        document.getElementById('reporterName').value = project.reporter_name || '';
        document.getElementById('reporterEmail').value = project.reporter_email || '';

        syncPublishToggleUI(project.is_published == 1);

        // Set after photo
        if (project.photo) {
            const afterPath = document.getElementById('afterPhotoPath');
            const afterPreview = document.getElementById('afterPhotoPreview');
            const afterArea = document.getElementById('afterPhotoArea');
            afterPath.value = project.photo;
            afterPreview.src = '../../../' + project.photo;
            afterPreview.style.display = 'block';
            afterArea.querySelector('.upload-icon').style.display = 'none';
            afterArea.querySelector('.upload-text').style.display = 'none';
            afterArea.classList.add('has-image');
            afterArea.querySelector('.remove-photo').style.display = 'flex';
        }

        // Set before photo
        if (project.before_photo) {
            const beforePath = document.getElementById('beforePhotoPath');
            const beforePreview = document.getElementById('beforePhotoPreview');
            const beforeArea = document.getElementById('beforePhotoArea');
            beforePath.value = project.before_photo;
            beforePreview.src = '../../../' + project.before_photo;
            beforePreview.style.display = 'block';
            beforeArea.querySelector('.upload-icon').style.display = 'none';
            beforeArea.querySelector('.upload-text').style.display = 'none';
            beforeArea.classList.add('has-image');
            beforeArea.querySelector('.remove-photo').style.display = 'flex';
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ─── Delete ───────────────────────────────────────────
    function deleteProject(id, title) {
        if (!confirm(`Delete "${title}"?\n\nThis will also remove the project from the landing page.`)) return;

        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);

        fetch(`${API}?action=delete&id=${id}`, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast('Project deleted', 'success');
                    const card = document.querySelector(`.project-item[data-id="${id}"]`);
                    if (card) { card.style.opacity = '0'; card.style.transform = 'scale(0.9)'; setTimeout(() => card.remove(), 300); }
                } else {
                    showToast(data.message || 'Delete failed', 'error');
                }
            })
            .catch(() => showToast('Network error', 'error'));
    }

    // ─── Reset Form ───────────────────────────────────────
    function resetForm() {
        isEditing = false;
        document.getElementById('projectFormEl').reset();
        document.getElementById('projectId').value = '';
        document.getElementById('sourceReportId').value = '';
        document.getElementById('sourceReportSource').value = '';
        document.getElementById('reporterName').value = '';
        document.getElementById('reporterEmail').value = '';
        const importBanner = document.getElementById('importReviewBanner');
        if (importBanner) importBanner.style.display = 'none';
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Project';
        document.getElementById('btnCancelEdit').style.display = 'none';
        document.getElementById('btnSave').innerHTML = '<i class="fas fa-save"></i> Save Project';
        removePhoto('before');
        removePhoto('after');

        syncPublishToggleUI(false);
    }

    // ─── Toggle Publish Status ────────────────────────────
    function togglePublish(id, title) {
        const formData = new FormData();
        formData.append('action', 'toggle_publish');
        formData.append('id', id);

        fetch(`${API}?action=toggle_publish&id=${id}`, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.message || 'Toggle failed', 'error');
                }
            })
            .catch(() => showToast('Network error', 'error'));
    }

    // ─── Preview Sliders ──────────────────────────────────
    document.querySelectorAll('[data-preview]').forEach(preview => {
        const imgBefore = preview.querySelector('.preview-before');
        const handle = preview.querySelector('.preview-handle');
        let isDragging = false;

        function updateSlider(x) {
            const rect = preview.getBoundingClientRect();
            let pos = ((x - rect.left) / rect.width) * 100;
            pos = Math.max(0, Math.min(100, pos));
            imgBefore.style.clipPath = `inset(0 ${100 - pos}% 0 0)`;
            handle.style.left = pos + '%';
        }

        preview.addEventListener('mousedown', (e) => { isDragging = true; updateSlider(e.clientX); });
        document.addEventListener('mousemove', (e) => { if (isDragging) { e.preventDefault(); updateSlider(e.clientX); } });
        document.addEventListener('mouseup', () => { isDragging = false; });
        preview.addEventListener('touchstart', (e) => { isDragging = true; updateSlider(e.touches[0].clientX); }, { passive: true });
        preview.addEventListener('touchmove', (e) => { if (isDragging) { e.preventDefault(); updateSlider(e.touches[0].clientX); } }, { passive: false });
        preview.addEventListener('touchend', () => { isDragging = false; });
    });

    // ─── Toast ────────────────────────────────────────────
    function showToast(msg, type) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'toast ' + type + ' show';
        setTimeout(() => t.classList.remove('show'), 3000);
    }

    // ─── Announcements (public index.php) ─────────────────
    const ANN_API = '../api/public_announcements_api.php';
    let isEditingAnnouncement = false;

    function ptApplyAnnouncementPhoto(path) {
        const area = document.getElementById('ptAnnouncementPhotoArea');
        const preview = document.getElementById('ptAnnouncementPhotoPreview');
        const removeBtn = document.getElementById('ptAnnouncementPhotoRemove');
        const pathInput = document.getElementById('ptAnnouncementPhotoPath');
        if (!area || !preview || !pathInput) return;
        pathInput.value = path || '';
        if (path) {
            preview.src = '../../../' + String(path).replace(/^\/+/, '');
            preview.style.display = 'block';
            area.querySelector('.upload-icon').style.display = 'none';
            area.querySelector('.upload-text').style.display = 'none';
            area.classList.add('has-image');
            if (removeBtn) removeBtn.style.display = 'flex';
        } else {
            preview.removeAttribute('src');
            preview.style.display = 'none';
            area.querySelector('.upload-icon').style.display = '';
            area.querySelector('.upload-text').style.display = '';
            area.classList.remove('has-image');
            if (removeBtn) removeBtn.style.display = 'none';
        }
    }

    function ptRemoveAnnouncementPhoto() {
        ptApplyAnnouncementPhoto('');
        const input = document.getElementById('ptAnnouncementPhotoInput');
        if (input) input.value = '';
    }

    function ptHandleAnnouncementPhoto(input) {
        const file = input.files && input.files[0];
        if (!file) return;
        if (!/^image\//i.test(file.type || '') && !/\.(jpe?g|png|gif|webp)$/i.test(file.name || '')) {
            showToast('Please choose a JPG, PNG, GIF, or WebP image', 'error');
            input.value = '';
            return;
        }
        const fd = new FormData();
        fd.append('action', 'upload_photo');
        fd.append('photo', file);
        const area = document.getElementById('ptAnnouncementPhotoArea');
        if (area) area.style.opacity = '0.55';
        showToast('Uploading photo…', 'success');
        fetch(ANN_API + '?action=upload_photo', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
            .then(async (r) => {
                const text = await r.text();
                let data;
                try { data = JSON.parse(text); } catch (e) {
                    throw new Error('Server returned an invalid response');
                }
                if (!r.ok && (!data || !data.message)) {
                    throw new Error((data && data.message) || ('Upload failed (' + r.status + ')'));
                }
                return data;
            })
            .then(data => {
                if (area) area.style.opacity = '1';
                if (data && data.success && data.path) {
                    ptApplyAnnouncementPhoto(data.path);
                    showToast('Photo uploaded', 'success');
                } else {
                    showToast((data && data.message) || 'Upload failed', 'error');
                    input.value = '';
                }
            })
            .catch((err) => {
                if (area) area.style.opacity = '1';
                showToast(err && err.message ? err.message : 'Network error', 'error');
                input.value = '';
            });
    }

    function ptResetAnnouncementForm() {
        const form = document.getElementById('ptAnnouncementFormEl');
        if (!form) return;
        isEditingAnnouncement = false;
        form.reset();
        document.getElementById('ptAnnouncementId').value = '';
        document.getElementById('ptAnnouncementPostedAt').value = new Date().toISOString().slice(0, 10);
        document.getElementById('ptAnnouncementPublished').checked = true;
        document.getElementById('ptAnnPublishLabel').textContent = 'Published';
        ptApplyAnnouncementPhoto('');
        document.getElementById('ptAnnouncementFormTitle').innerHTML =
            '<i class="fas fa-plus-circle"></i> Create Announcement';
        document.getElementById('ptBtnSaveAnnouncement').innerHTML =
            '<i class="fas fa-bullhorn"></i> Publish Announcement';
        document.getElementById('ptBtnCancelAnnouncementEdit').style.display = 'none';
    }

    function ptEditAnnouncement(ann) {
        if (!ann || !document.getElementById('ptAnnouncementFormEl')) return;
        isEditingAnnouncement = true;
        document.getElementById('ptAnnouncementId').value = ann.id || '';
        document.getElementById('ptAnnouncementTitle').value = ann.title || '';
        document.getElementById('ptAnnouncementContent').value = ann.content || '';
        document.getElementById('ptAnnouncementPostedAt').value = String(ann.posted_at || '').slice(0, 10);
        document.getElementById('ptAnnouncementPublished').checked = Number(ann.is_published) === 1;
        document.getElementById('ptAnnPublishLabel').textContent =
            Number(ann.is_published) === 1 ? 'Published' : 'Draft';
        ptApplyAnnouncementPhoto(ann.photo || '');
        document.getElementById('ptAnnouncementFormTitle').innerHTML =
            '<i class="fas fa-edit"></i> Edit Announcement';
        document.getElementById('ptBtnSaveAnnouncement').innerHTML =
            '<i class="fas fa-save"></i> Update Announcement';
        document.getElementById('ptBtnCancelAnnouncementEdit').style.display = 'inline-flex';
        document.getElementById('ptAnnouncementFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function ptDeleteAnnouncement(id, title) {
        if (!confirm('Delete announcement "' + (title || '') + '"? This cannot be undone.')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', String(id));
        fetch(ANN_API + '?action=delete', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'Deleted', 'success');
                    setTimeout(() => location.reload(), 700);
                } else {
                    showToast(data.message || 'Delete failed', 'error');
                }
            })
            .catch(() => showToast('Network error', 'error'));
    }

    function ptToggleAnnouncementPublish(id) {
        const fd = new FormData();
        fd.append('action', 'toggle_publish');
        fd.append('id', String(id));
        fetch(ANN_API + '?action=toggle_publish', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'Updated', 'success');
                    setTimeout(() => location.reload(), 700);
                } else {
                    showToast(data.message || 'Update failed', 'error');
                }
            })
            .catch(() => showToast('Network error', 'error'));
    }

    (function initPtAnnouncements() {
        const pub = document.getElementById('ptAnnouncementPublished');
        if (pub) {
            pub.addEventListener('change', function () {
                document.getElementById('ptAnnPublishLabel').textContent = this.checked ? 'Published' : 'Draft';
            });
        }
        const form = document.getElementById('ptAnnouncementFormEl');
        if (!form) return;
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const title = document.getElementById('ptAnnouncementTitle').value.trim();
            const content = document.getElementById('ptAnnouncementContent').value.trim();
            const postedAt = document.getElementById('ptAnnouncementPostedAt').value;
            if (!title || !content) {
                showToast('Title and message are required', 'error');
                return;
            }
            const fd = new FormData();
            fd.append('title', title);
            fd.append('content', content);
            fd.append('posted_at', postedAt);
            fd.append('photo', document.getElementById('ptAnnouncementPhotoPath').value.trim());
            fd.append('is_published', document.getElementById('ptAnnouncementPublished').checked ? '1' : '0');
            if (isEditingAnnouncement) {
                fd.append('id', document.getElementById('ptAnnouncementId').value);
            }
            const action = isEditingAnnouncement ? 'update' : 'create';
            const btn = document.getElementById('ptBtnSaveAnnouncement');
            const prev = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            fetch(ANN_API + '?action=' + action, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    btn.innerHTML = prev;
                    if (data.success) {
                        showToast(data.message || 'Saved', 'success');
                        setTimeout(() => location.reload(), 700);
                    } else {
                        showToast(data.message || 'Save failed', 'error');
                    }
                })
                .catch(() => {
                    btn.disabled = false;
                    btn.innerHTML = prev;
                    showToast('Network error', 'error');
                });
        });
    })();

    // ─── Tabs + Citizen Feedback viewer ─────────────────────
    (function () {
        const FEEDBACK_API = '../../pages/api/citizen_feedback_admin_api.php';
        const PAGE_SIZE = 10;
        let feedbackLoaded = false;
        const state = {
            report: { page: 1, sortBy: 'created_at', sortDir: 'desc' },
            service: { page: 1, sortBy: 'created_at', sortDir: 'desc' }
        };

        function escapeHtml(str) {
            return String(str == null ? '' : str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function starsHtml(n) {
            const rating = Math.max(0, Math.min(5, parseInt(n, 10) || 0));
            let html = '<span class="cf-stars" aria-label="' + rating + ' stars">';
            for (let i = 1; i <= 5; i++) {
                html += '<i class="' + (i <= rating ? 'fas' : 'far') + ' fa-star"></i>';
            }
            return html + '</span>';
        }

        function formatDate(iso) {
            if (!iso) return '—';
            const d = new Date(iso.replace(' ', 'T'));
            if (isNaN(d.getTime())) return escapeHtml(iso);
            return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        function parseSortValue(val) {
            const parts = String(val || 'created_at:desc').split(':');
            return {
                sortBy: parts[0] === 'rating' ? 'rating' : 'created_at',
                sortDir: parts[1] === 'asc' ? 'asc' : 'desc'
            };
        }

        function renderSummary(prefix, data) {
            const avgEl = document.getElementById(prefix + 'Avg');
            const totalEl = document.getElementById(prefix + 'Total');
            const countsWrap = document.getElementById(prefix + 'Counts');
            if (!data) return;
            const total = data.total || 0;
            if (avgEl) avgEl.textContent = total ? Number(data.average || 0).toFixed(1) : '—';
            if (totalEl) totalEl.textContent = String(total);
            if (!countsWrap) return;
            const counts = data.counts || {};
            for (let s = 5; s >= 1; s--) {
                const row = countsWrap.querySelector('[data-star="' + s + '"]');
                if (!row) continue;
                const c = parseInt(counts[s] || 0, 10);
                const pct = total ? Math.round((c / total) * 100) : 0;
                const bar = row.querySelector('.cf-bar i');
                const num = row.querySelector('b');
                if (bar) bar.style.width = pct + '%';
                if (num) num.textContent = String(c);
            }
        }

        function renderPager(pagerId, kind, pagination) {
            const el = document.getElementById(pagerId);
            if (!el) return;
            const total = pagination && pagination.total != null ? pagination.total : 0;
            const page = pagination && pagination.page ? pagination.page : 1;
            const totalPages = pagination && pagination.total_pages ? pagination.total_pages : 1;
            if (!total) {
                el.innerHTML = '';
                return;
            }
            const from = (page - 1) * PAGE_SIZE + 1;
            const to = Math.min(page * PAGE_SIZE, total);
            el.innerHTML =
                '<span>Showing ' + from + '–' + to + ' of ' + total + '</span>' +
                '<div class="cf-pager-btns">' +
                    '<button type="button" data-cf-page="prev" data-cf-kind="' + kind + '"' + (page <= 1 ? ' disabled' : '') + '>Prev</button>' +
                    '<span>Page ' + page + ' / ' + totalPages + '</span>' +
                    '<button type="button" data-cf-page="next" data-cf-kind="' + kind + '"' + (page >= totalPages ? ' disabled' : '') + '>Next</button>' +
                '</div>';
        }

        function renderReportRows(rows) {
            const body = document.getElementById('cfReportBody');
            if (!body) return;
            if (!rows || !rows.length) {
                body.innerHTML = '<tr><td colspan="5" class="cf-empty">No project ratings yet.</td></tr>';
                return;
            }
            body.innerHTML = rows.map(function (r) {
                const pid = parseInt(r.project_id, 10) || 0;
                const idCell = pid
                    ? '<a href="#" class="cf-project-link" data-project-id="' + pid + '">#' + pid + '</a>'
                    : '<span class="cf-muted">—</span>';
                return '<tr>' +
                    '<td>' + starsHtml(r.rating) + '</td>' +
                    '<td>' + idCell + '</td>' +
                    '<td>' + escapeHtml(r.project_title || (pid ? ('Project #' + pid) : '—')) + '</td>' +
                    '<td>' + (r.comment ? escapeHtml(r.comment) : '<span class="cf-muted">—</span>') + '</td>' +
                    '<td>' + formatDate(r.created_at) + '</td>' +
                    '</tr>';
            }).join('');
        }

        function renderServiceRows(rows) {
            const body = document.getElementById('cfServiceBody');
            if (!body) return;
            if (!rows || !rows.length) {
                body.innerHTML = '<tr><td colspan="4" class="cf-empty">No service ratings yet.</td></tr>';
                return;
            }
            body.innerHTML = rows.map(function (r) {
                let page = r.page_url || '';
                try {
                    if (page) {
                        const u = new URL(page, window.location.origin);
                        page = u.pathname + u.search;
                    }
                } catch (e) {}
                if (page.length > 60) page = page.slice(0, 57) + '…';
                return '<tr>' +
                    '<td>' + starsHtml(r.rating) + '</td>' +
                    '<td>' + (r.comment ? escapeHtml(r.comment) : '<span class="cf-muted">—</span>') + '</td>' +
                    '<td>' + (page ? '<span class="cf-muted" title="' + escapeHtml(r.page_url || '') + '">' + escapeHtml(page) + '</span>' : '<span class="cf-muted">—</span>') + '</td>' +
                    '<td>' + formatDate(r.created_at) + '</td>' +
                    '</tr>';
            }).join('');
        }

        function listUrl(action, st) {
            return FEEDBACK_API + '?action=' + action +
                '&limit=' + PAGE_SIZE +
                '&page=' + st.page +
                '&sort_by=' + encodeURIComponent(st.sortBy) +
                '&sort_dir=' + encodeURIComponent(st.sortDir);
        }

        function loadReportList() {
            return fetch(listUrl('report_list', state.report), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        renderReportRows(data.data || []);
                        renderPager('cfReportPager', 'report', data.pagination);
                    } else {
                        renderReportRows([]);
                        renderPager('cfReportPager', 'report', null);
                    }
                })
                .catch(function () {
                    renderReportRows([]);
                    renderPager('cfReportPager', 'report', null);
                });
        }

        function loadServiceList() {
            return fetch(listUrl('service_list', state.service), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        renderServiceRows(data.data || []);
                        renderPager('cfServicePager', 'service', data.pagination);
                    } else {
                        renderServiceRows([]);
                        renderPager('cfServicePager', 'service', null);
                    }
                })
                .catch(function () {
                    renderServiceRows([]);
                    renderPager('cfServicePager', 'service', null);
                });
        }

        function loadCitizenFeedback() {
            return Promise.all([
                fetch(FEEDBACK_API + '?action=report_summary', { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
                fetch(FEEDBACK_API + '?action=service_summary', { credentials: 'same-origin' }).then(function (r) { return r.json(); }),
                loadReportList(),
                loadServiceList()
            ]).then(function (results) {
                const reportSum = results[0];
                const serviceSum = results[1];
                if (reportSum && reportSum.success) renderSummary('cfReport', reportSum.data);
                if (serviceSum && serviceSum.success) renderSummary('cfService', serviceSum.data);
                feedbackLoaded = true;
            }).catch(function () {
                renderReportRows([]);
                renderServiceRows([]);
            });
        }

        function switchTab(name) {
            document.querySelectorAll('.pt-tab').forEach(function (btn) {
                const on = btn.getAttribute('data-pt-tab') === name;
                btn.classList.toggle('active', on);
                btn.setAttribute('aria-selected', on ? 'true' : 'false');
            });
            document.querySelectorAll('.pt-tab-panel').forEach(function (panel) {
                let match = false;
                if (name === 'projects') match = panel.id === 'ptPanelProjects';
                if (name === 'announcements') match = panel.id === 'ptPanelAnnouncements';
                if (name === 'feedback') match = panel.id === 'ptPanelFeedback';
                panel.hidden = !match;
                panel.classList.toggle('active', match);
            });
            if (name === 'feedback' && !feedbackLoaded) {
                loadCitizenFeedback();
            }
        }

        let highlightTimer = null;
        function focusProjectById(id) {
            const pid = parseInt(id, 10) || 0;
            if (!pid) return;
            switchTab('projects');
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', 'projects');
                url.searchParams.set('project', String(pid));
                history.replaceState(null, '', url.pathname + url.search);
            } catch (e) {}
            const card = document.querySelector('.project-item[data-id="' + pid + '"]');
            if (!card) {
                if (typeof showToast === 'function') {
                    showToast('Project not found on this list', 'error');
                }
                return;
            }
            document.querySelectorAll('.project-item.project-item-highlight').forEach(function (el) {
                el.classList.remove('project-item-highlight');
            });
            card.classList.add('project-item-highlight');
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (highlightTimer) clearTimeout(highlightTimer);
            highlightTimer = setTimeout(function () {
                card.classList.remove('project-item-highlight');
                highlightTimer = null;
            }, 2500);
        }

        document.querySelectorAll('.pt-tab').forEach(function (btn) {
            btn.addEventListener('click', function () {
                switchTab(btn.getAttribute('data-pt-tab'));
            });
        });

        const reportSort = document.getElementById('cfReportSort');
        if (reportSort) {
            reportSort.addEventListener('change', function () {
                const s = parseSortValue(this.value);
                state.report.sortBy = s.sortBy;
                state.report.sortDir = s.sortDir;
                state.report.page = 1;
                loadReportList();
            });
        }
        const serviceSort = document.getElementById('cfServiceSort');
        if (serviceSort) {
            serviceSort.addEventListener('change', function () {
                const s = parseSortValue(this.value);
                state.service.sortBy = s.sortBy;
                state.service.sortDir = s.sortDir;
                state.service.page = 1;
                loadServiceList();
            });
        }

        document.addEventListener('click', function (e) {
            const link = e.target.closest('.cf-project-link');
            if (link) {
                e.preventDefault();
                focusProjectById(link.getAttribute('data-project-id'));
                return;
            }
            const btn = e.target.closest('[data-cf-page]');
            if (!btn || btn.disabled) return;
            const kind = btn.getAttribute('data-cf-kind');
            const dir = btn.getAttribute('data-cf-page');
            if (kind !== 'report' && kind !== 'service') return;
            if (dir === 'prev') state[kind].page = Math.max(1, state[kind].page - 1);
            if (dir === 'next') state[kind].page += 1;
            if (kind === 'report') loadReportList();
            else loadServiceList();
        });

        const params = new URLSearchParams(window.location.search);
        const tab = params.get('tab');
        const projectParam = params.get('project');
        if (tab === 'feedback' || tab === 'announcements' || tab === 'projects') {
            switchTab(tab);
        }
        if (projectParam) {
            focusProjectById(projectParam);
        }
    })();

    </script>


</body>
</html>

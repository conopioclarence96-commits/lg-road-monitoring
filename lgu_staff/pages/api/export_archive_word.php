<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/progress_archive_helpers.php';

// Only Road Operations Supervisors can export an archived report as Word.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'road_ops_supervisor') {
    header('Location: ../../login.php');
    exit();
}

$archive_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$archive_table = (string)($_GET['table'] ?? 'road_transportation_reports_archive');
if ($archive_id <= 0) {
    die('Invalid archive ID.');
}

rgmap_archive_ensure_table();
if (!rgmap_archive_allowed_table($archive_table)) {
    $archive_table = 'road_transportation_reports_archive';
}

$from_sql = rgmap_archive_union_sql(true, true);
$stmt = $conn->prepare("SELECT * FROM $from_sql WHERE id = ? AND archive_table = ? LIMIT 1");
$stmt->bind_param('is', $archive_id, $archive_table);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
if (!$row) {
    die('Archived report not found.');
}

$source_labels = [
    'lgu'            => 'LGU Monitoring',
    'citizen'        => 'Citizen',
    'cimm'           => 'CIMM',
    'infrastructure' => 'Infrastructure',
];

$e = function ($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
};

$peso = function ($v) {
    if ($v === null || $v === '' || $v === 0 || $v === '0') {
        return '';
    }
    return 'PHP ' . number_format((float) $v, 2);
};

$fmtDate = function ($v) use ($e) {
    if (empty($v)) {
        return '—';
    }
    $ts = strtotime($v);
    return $ts ? date('M d, Y', $ts) : $e($v);
};

$source_system = $row['source_system'] ?? 'citizen';
$source_label = $source_labels[$source_system] ?? $source_system;
$location = trim($row['location'] ?? '');
if ($row['latitude'] && $row['longitude'] && (float) $row['latitude'] != 0 && (float) $row['longitude'] != 0) {
    $location .= ($location !== '' ? "\n" : '') . 'Coordinates: ' . $row['latitude'] . ', ' . $row['longitude'];
}

$report_id = !empty($row['report_id']) ? $row['report_id'] : $row['id'];
$rows_html = '';

function w_row(&$rows_html, $label, $value, $e) {
    if ($value === '—') {
        return;
    }
    $rows_html .= '<tr><td style="width:200px;padding:6px 12px;background:#f1f3f5;border:1px solid #dee2e6;font-weight:bold;">'
        . $e($label) . '</td><td style="padding:6px 12px;border:1px solid #dee2e6;">'
        . $e($value) . '</td></tr>';
}

w_row($rows_html, 'Report ID', $report_id, $e);
w_row($rows_html, 'Title', $row['title'] ?? '', $e);
w_row($rows_html, 'Report Type', $row['report_type'] ?? '', $e);
w_row($rows_html, 'Category', $row['report_category'] ?? '', $e);
w_row($rows_html, 'Status', ucfirst(str_replace('_', ' ', (string) ($row['status'] ?? ''))), $e);
w_row($rows_html, 'Priority', ucfirst((string) ($row['priority'] ?? '')), $e);
w_row($rows_html, 'Severity', ucfirst((string) ($row['severity'] ?? '')), $e);
w_row($rows_html, 'Source System', $source_label, $e);
w_row($rows_html, 'Department', $row['department'] ?? '', $e);
w_row($rows_html, 'Created Date', $fmtDate($row['created_date'] ?? ''), $e);
w_row($rows_html, 'Due Date', $fmtDate($row['due_date'] ?? ''), $e);
w_row($rows_html, 'Estimation', $peso($row['estimation'] ?? ''), $e);
w_row($rows_html, 'Assigned To', $row['assigned_to'] ?? '', $e);
w_row($rows_html, 'Reporter Name', $row['reporter_name'] ?? '', $e);
w_row($rows_html, 'Reporter Email', $row['reporter_email'] ?? '', $e);
w_row($rows_html, 'Reporter Phone', $row['reporter_phone'] ?? '', $e);
w_row($rows_html, 'Location', $location, $e);
w_row($rows_html, 'District', $row['district'] ?? '', $e);
w_row($rows_html, 'Barangay', $row['barangay'] ?? '', $e);
w_row($rows_html, 'Street Name', $row['street_name'] ?? '', $e);
w_row($rows_html, 'Description', $row['description'] ?? '', $e);
w_row($rows_html, 'Resolution Notes', $row['resolution_notes'] ?? '', $e);
w_row($rows_html, 'Archived From', $row['archived_from'] ?? '', $e);
w_row($rows_html, 'Previous Status', $row['previous_status'] ?? '', $e);
w_row($rows_html, 'Approved At', $fmtDate($row['approved_at'] ?? ''), $e);
w_row($rows_html, 'Rejected At', $fmtDate($row['rejected_at'] ?? ''), $e);
w_row($rows_html, 'Resolved At', $fmtDate($row['resolved_date'] ?? ''), $e);
w_row($rows_html, 'Completed At', $fmtDate($row['completed_at'] ?? ''), $e);
w_row($rows_html, 'Last Updated', $fmtDate($row['updated_at'] ?? ''), $e);
w_row($rows_html, 'Archived At', $fmtDate($row['created_at'] ?? ''), $e);

$doc_html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<title>Archived Report</title>
<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->
<style>
    body { font-family: "Calibri", sans-serif; font-size: 11pt; color: #212529; }
    h1 { font-size: 20pt; margin-bottom: 2px; }
    .subtitle { color: #6c757d; font-size: 10pt; margin-bottom: 18px; }
    h2 { font-size: 13pt; color: #3762c8; border-bottom: 2px solid #3762c8; padding-bottom: 3px; margin-top: 22px; }
    table { border-collapse: collapse; width: 100%; margin-top: 6px; }
</style>
</head>
<body>
    <h1>Archived Report</h1>
    <div class="subtitle">Exported from the Road &amp; Transportation Monitoring System</div>
    <h2>Report Details</h2>
    <table>' . $rows_html . '</table>
</body>
</html>';

header('Content-Type: application/msword; charset=UTF-8');
header('Content-Disposition: attachment; filename="archived_report_' . $report_id . '.doc"');
header('Cache-Control: private, max-age=0, must-revalidate');

echo "\xEF\xBB\xBF"; // UTF-8 BOM so Word renders the document correctly
echo $doc_html;

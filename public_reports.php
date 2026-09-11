<?php
/**
 * public_reports.php — legacy entry point.
 *
 * The public reports page has been refactored into the Road Status module
 * (road_status.php). This endpoint performs a permanent redirect while
 * preserving the original query string (status / type / report_id), so any
 * existing bookmarks, links, or search results continue to work.
 */
session_start();
$destination = 'road_status.php';
$query_string = $_SERVER['QUERY_STRING'] ?? '';
if ($query_string !== '') {
    $destination .= '?' . $query_string;
}
header('Location: ' . $destination, true, 301);
exit;
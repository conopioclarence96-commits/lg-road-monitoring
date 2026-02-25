<?php
// Session settings (must be set before session_start)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS

session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Function to get chart data for different periods
function getChartData($conn, $period) {
    $data = ['reports' => [], 'verifications' => []];
    
    switch ($period) {
        case '7':
            // Last 7 days (weekly view)
            $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
            $current_week = date('W');
            $current_year = date('Y');
            
            foreach ($days as $index => $day) {
                $day_of_week = ($index + 2);
                
                // Get reports for this day
                $transport_query = "SELECT COUNT(*) as count FROM road_transportation_reports 
                                   WHERE DAYOFWEEK(created_at) = $day_of_week 
                                   AND WEEK(created_at, 1) = WEEK(CURRENT_DATE, 1) 
                                   AND YEAR(created_at) = $current_year";
                $result = $conn->query($transport_query);
                $transport_count = $result->fetch_assoc()['count'];
                
                $maintenance_query = "SELECT COUNT(*) as count FROM road_maintenance_reports 
                                     WHERE DAYOFWEEK(created_at) = $day_of_week 
                                     AND WEEK(created_at, 1) = WEEK(CURRENT_DATE, 1) 
                                     AND YEAR(created_at) = $current_year";
                $result = $conn->query($maintenance_query);
                $maintenance_count = $result->fetch_assoc()['count'];
                
                $data['reports'][] = (int)($transport_count + $maintenance_count);
                
                // Get verifications
                $verification_query = "(SELECT COUNT(*) as count FROM road_transportation_reports 
                                       WHERE status IN ('completed', 'approved') 
                                       AND DAYOFWEEK(updated_at) = $day_of_week 
                                       AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE, 1) 
                                       AND YEAR(updated_at) = $current_year)
                                       UNION ALL
                                       (SELECT COUNT(*) as count FROM road_maintenance_reports 
                                       WHERE status IN ('completed', 'approved') 
                                       AND DAYOFWEEK(updated_at) = $day_of_week 
                                       AND WEEK(updated_at, 1) = WEEK(CURRENT_DATE, 1) 
                                       AND YEAR(updated_at) = $current_year)";
                $result = $conn->query($verification_query);
                $verification_count = 0;
                while ($row = $result->fetch_assoc()) {
                    $verification_count += $row['count'];
                }
                
                $data['verifications'][] = (int)$verification_count;
            }
            break;
            
        case '30':
            // Last 30 days (daily view)
            for ($i = 29; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $day_label = date('M d', strtotime($date));
                
                // Get reports for this date
                $transport_query = "SELECT COUNT(*) as count FROM road_transportation_reports 
                                   WHERE DATE(created_at) = '$date'";
                $result = $conn->query($transport_query);
                $transport_count = $result->fetch_assoc()['count'];
                
                $maintenance_query = "SELECT COUNT(*) as count FROM road_maintenance_reports 
                                     WHERE DATE(created_at) = '$date'";
                $result = $conn->query($maintenance_query);
                $maintenance_count = $result->fetch_assoc()['count'];
                
                $data['reports'][] = (int)($transport_count + $maintenance_count);
                
                // Get verifications
                $verification_query = "(SELECT COUNT(*) as count FROM road_transportation_reports 
                                       WHERE status IN ('completed', 'approved') 
                                       AND DATE(updated_at) = '$date')
                                       UNION ALL
                                       (SELECT COUNT(*) as count FROM road_maintenance_reports 
                                       WHERE status IN ('completed', 'approved') 
                                       AND DATE(updated_at) = '$date')";
                $result = $conn->query($verification_query);
                $verification_count = 0;
                while ($row = $result->fetch_assoc()) {
                    $verification_count += $row['count'];
                }
                
                $data['verifications'][] = (int)$verification_count;
            }
            break;
            
        case '90':
            // Last 3 months (weekly view)
            for ($i = 12; $i >= 0; $i--) {
                $week_start = date('Y-m-d', strtotime("-$i weeks"));
                $week_end = date('Y-m-d', strtotime("-$i weeks +6 days"));
                $week_label = date('M j', strtotime($week_start));
                
                // Get reports for this week
                $transport_query = "SELECT COUNT(*) as count FROM road_transportation_reports 
                                   WHERE created_at BETWEEN '$week_start' AND '$week_end 23:59:59'";
                $result = $conn->query($transport_query);
                $transport_count = $result->fetch_assoc()['count'];
                
                $maintenance_query = "SELECT COUNT(*) as count FROM road_maintenance_reports 
                                     WHERE created_at BETWEEN '$week_start' AND '$week_end 23:59:59'";
                $result = $conn->query($maintenance_query);
                $maintenance_count = $result->fetch_assoc()['count'];
                
                $data['reports'][] = (int)($transport_count + $maintenance_count);
                
                // Get verifications
                $verification_query = "(SELECT COUNT(*) as count FROM road_transportation_reports 
                                       WHERE status IN ('completed', 'approved') 
                                       AND updated_at BETWEEN '$week_start' AND '$week_end 23:59:59')
                                       UNION ALL
                                       (SELECT COUNT(*) as count FROM road_maintenance_reports 
                                       WHERE status IN ('completed', 'approved') 
                                       AND updated_at BETWEEN '$week_start' AND '$week_end 23:59:59')";
                $result = $conn->query($verification_query);
                $verification_count = 0;
                while ($row = $result->fetch_assoc()) {
                    $verification_count += $row['count'];
                }
                
                $data['verifications'][] = (int)$verification_count;
            }
            break;
            
        default:
            // Default to 7 days
            return getChartData($conn, '7');
    }
    
    return $data;
}

// Handle AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['period'])) {
    $period = $_GET['period'];
    
    // Validate period
    if (!in_array($period, ['7', '30', '90'])) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid period']);
        exit();
    }
    
    $chart_data = getChartData($conn, $period);
    
    header('Content-Type: application/json');
    echo json_encode($chart_data);
    exit();
}

// If not an AJAX request, redirect
header('Location: ../main/lgu_staff_dashboard.php');
exit();
?>

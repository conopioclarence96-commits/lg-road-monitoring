<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();

// Suppress display_errors for AJAX/POST requests to preserve JSON response
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
    error_reporting(0);
}
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

// Check if user is logged in and is system admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'system_admin') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    header('Location: ../../login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $action = $_POST['action'] ?? null;
    $userId = $_POST['user_id'] ?? null;
    $remarks = $_POST['remarks'] ?? '';

    // Lightweight endpoint: return the freshest last_activity for verified accounts
    // so the page can keep the activity indicators accurate without a full reload.
    if ($action === 'get_activity') {
        $activity = [];
        try {
            $act_stmt = $conn->prepare("
                SELECT id, last_activity
                FROM users
                WHERE role IN ('lgu_staff', 'citizen', 'road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer')
                  AND account_status = 'verified'
                  AND (lock_until IS NULL OR lock_until <= NOW())
            ");
            $act_stmt->execute();
            $act_res = $act_stmt->get_result();
            while ($row = $act_res->fetch_assoc()) {
                $activity[$row['id']] = !empty($row['last_activity']) ? date('c', strtotime($row['last_activity'])) : null;
            }
            $act_stmt->close();
        } catch (Exception $e) {
            error_log("get_activity error: " . $e->getMessage());
        }
        echo json_encode(['success' => true, 'activity' => $activity]);
        exit;
    }

    if (!$action || !$userId) {
        echo json_encode(['success' => false, 'message' => 'Invalid request']);
        exit;
    }

    if ($action === 'deactivate_user') {
        $stmt = $conn->prepare("UPDATE users SET is_active = 0, account_status = 'deactivated' WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        // audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, created_at) VALUES (?, 'Account Deactivated', ?, NOW())");
        $log->bind_param("is", $_SESSION['user_id'], $remarks);
        $log->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'activate_user') {
        $stmt = $conn->prepare("UPDATE users SET is_active = 1, account_status = 'verified' WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        // audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, created_at) VALUES (?, 'Account Activated', ?, NOW())");
        $log->bind_param("is", $_SESSION['user_id'], $remarks);
        $log->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reactivate_user') {
        $stmt = $conn->prepare("UPDATE users SET is_active = 1, account_status = 'verified' WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();

        // audit log
        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, created_at) VALUES (?, 'Account Reactivated', ?, NOW())");
        $log->bind_param("is", $_SESSION['user_id'], $remarks);
        $log->execute();

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'unlock_user') {
        // Clear password-lockout state only — account_status / is_active stay as-is
        // so the user returns to Verified Accounts after unlock.
        $stmt = $conn->prepare("
            UPDATE users
            SET failed_attempts = 0, lock_until = NULL, lock_level = 0
            WHERE id = ?
              AND account_status = 'verified'
              AND lock_until IS NOT NULL
              AND lock_until > NOW()
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $unlocked = $stmt->affected_rows > 0;
        $stmt->close();

        if ($unlocked) {
            $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, created_at) VALUES (?, 'Account Unlocked', ?, NOW())");
            $details = $remarks !== '' ? $remarks : ('Unlocked account #' . (int)$userId);
            $log->bind_param("is", $_SESSION['user_id'], $details);
            $log->execute();
            $log->close();
            echo json_encode(['success' => true, 'message' => 'Account unlocked successfully.']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Account is not currently locked.']);
        }
        exit;
    }

    if ($action === 'update_user') {
        $full_name = $_POST['full_name'] ?? '';
        $role = $_POST['role'] ?? '';
        $department = $_POST['department'] ?? '';
        $address = $_POST['address'] ?? '';
        $birthday = $_POST['birthday'] ?? '';
        $civil_status = $_POST['civil_status'] ?? '';
        $phone_number = $_POST['phone_number'] ?? '';

        if (empty($full_name) || empty($role)) {
            echo json_encode(['success' => false, 'message' => 'Full name and role are required.']);
            exit;
        }

        $stmt = $conn->prepare("UPDATE users SET full_name = ?, role = ?, department = ?, address = ?, birthday = ?, civil_status = ?, phone_number = ?, updated_at = NOW() WHERE id = ?");
        $birthday_val = ($birthday !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) ? $birthday : null;
        $stmt->bind_param("sssssssi", $full_name, $role, $department, $address, $birthday_val, $civil_status, $phone_number, $userId);
        $stmt->execute();
        $stmt->close();

        $log = $conn->prepare("INSERT INTO audit_logs (user_id, action, details, created_at) VALUES (?, 'Account Updated', ?, NOW())");
        $details = "Updated account #$userId: $full_name";
        $log->bind_param("is", $_SESSION['user_id'], $details);
        $log->execute();
        $log->close();

        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

// Roles managed on this page (same set for verified / locked / other panels).
$managed_roles_sql = "role IN ('lgu_staff', 'citizen', 'road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer')";

// Drop expired lockouts so only currently locked accounts remain locked.
try {
    $conn->query("
        UPDATE users
        SET failed_attempts = 0, lock_until = NULL
        WHERE lock_until IS NOT NULL
          AND lock_until <= NOW()
          AND {$managed_roles_sql}
    ");
} catch (Exception $e) {
    error_log("manage_accounts expired lock cleanup: " . $e->getMessage());
}

// Get verified accounts only (exclude accounts that are currently locked)
$stmt = $conn->prepare("
    SELECT id, username, email, full_name, role, department, address, birthday, civil_status, phone_number, is_active, last_activity, created_at, updated_at, approved_at, rejected_at, id_file_path 
    FROM users 
    WHERE {$managed_roles_sql}
      AND account_status = 'verified'
      AND (lock_until IS NULL OR lock_until <= NOW())
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Currently locked verified accounts (lock_until still in the future)
$locked_users = [];
try {
    $locked_stmt = $conn->prepare("
        SELECT id, username, email, full_name, role, department, address, birthday, civil_status, phone_number,
               is_active, account_status, last_activity, created_at, updated_at, approved_at, rejected_at,
               id_file_path, failed_attempts, lock_until, lock_level
        FROM users
        WHERE {$managed_roles_sql}
          AND account_status = 'verified'
          AND lock_until IS NOT NULL
          AND lock_until > NOW()
        ORDER BY lock_until DESC
    ");
    $locked_stmt->execute();
    $locked_users = $locked_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $locked_stmt->close();
} catch (Exception $e) {
    error_log("manage_accounts locked users query: " . $e->getMessage());
    $locked_users = [];
}

// Get unverified/rejected accounts
$stmt2 = $conn->prepare("
    SELECT id, username, email, full_name, role, department, address, birthday, civil_status, phone_number, is_active, account_status, created_at, updated_at, approved_at, rejected_at, id_file_path 
    FROM users 
    WHERE {$managed_roles_sql} AND account_status IN ('pending', 'rejected')
    ORDER BY created_at DESC
");
$stmt2->execute();
$unverified_users = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

// Get deactivated accounts
$stmt3 = $conn->prepare("
    SELECT id, username, email, full_name, role, department, address, birthday, civil_status, phone_number, is_active, account_status, created_at, updated_at, approved_at, rejected_at, id_file_path 
    FROM users 
    WHERE {$managed_roles_sql} AND account_status = 'deactivated'
    ORDER BY updated_at DESC
");
$stmt3->execute();
$deactivated_users = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt3->close();

// Get verified users inactive for 2+ weeks (not currently locked)
$inactive_2weeks_users = [];
try {
    $inactive_stmt = $conn->prepare("
        SELECT id, username, email, full_name, role, department, last_login, created_at, updated_at
        FROM users 
        WHERE account_status = 'verified' 
        AND is_active = 1
        AND (lock_until IS NULL OR lock_until <= NOW())
        AND last_login IS NOT NULL 
        AND last_login < DATE_SUB(NOW(), INTERVAL 14 DAY)
        AND {$managed_roles_sql}
        ORDER BY last_login ASC
    ");
    $inactive_stmt->execute();
    $inactive_2weeks_users = $inactive_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $inactive_stmt->close();
} catch (Exception $e) {
    error_log("Inactive users query error: " . $e->getMessage());
    $inactive_2weeks_users = [];
}

// Calculate stats
$active_accounts = 0;
$inactive_accounts = 0;
foreach ($users as $user) {
    if ($user['is_active']) {
        $active_accounts++;
    } else {
        $inactive_accounts++;
    }
}

// Get audit log for account actions
try {
    $audit_stmt = $conn->prepare("
        SELECT a.*, u.full_name as admin_name 
        FROM audit_logs a 
        LEFT JOIN users u ON a.user_id = u.id 
        WHERE a.action LIKE '%Account%' 
        ORDER BY a.created_at DESC 
        LIMIT 50
    ");
    $audit_stmt->execute();
    $audit_log = $audit_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $audit_stmt->close();
} catch (Exception $e) {
    // Log error for debugging
    error_log("Audit log query error: " . $e->getMessage());
    $audit_log = [];
}

// Format a timestamp into a human-friendly relative "last active" string.
// Used as a server-side fallback for the Verified Accounts activity indicator.
function formatLastActive($timestamp) {
    if (empty($timestamp) || $timestamp === '0000-00-00 00:00:00') {
        return 'Never active';
    }
    $ts = strtotime($timestamp);
    if (!$ts) {
        return 'Never active';
    }
    $diff = max(0, time() - $ts);

    if ($diff < 60) {
        return 'Active just now';
    }
    $mins = floor($diff / 60);
    if ($mins < 60) {
        return 'Active ' . $mins . ' min' . ($mins > 1 ? 's' : '') . ' ago';
    }
    $hours = floor($mins / 60);
    if ($hours < 24) {
        return 'Active ' . $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    }
    $days = floor($hours / 24);
    if ($days < 7) {
        return 'Active ' . $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }
    $weeks = floor($days / 7);
    if ($weeks < 5) {
        return 'Active ' . $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    }
    $months = floor($days / 30);
    if ($months < 12) {
        return 'Active ' . $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
    }
    $years = floor($days / 365);
    return 'Active ' . $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accounts - LGU Road Monitoring</title>
    <link rel="icon" type="image/png" href="../../assets/img/infra-gov-logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=6">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f5f3ee; min-height: 100vh; color: var(--text-primary); }
        body.dark-mode { background: var(--bg-page); }

        .main-content.accounts-dash {
            margin-left: 250px;
            padding: 28px 32px;
            max-width: 100%;
            overflow-x: hidden;
            position: relative;
            z-index: 1;
        }

        /* Dashboard header */
        .accounts-dash .dashboard-header {
            background: #f4f7fb;
            border-radius: 14px;
            padding: 20px 26px;
            margin-bottom: 22px;
            border: 1px solid #d5dce8;
            box-shadow: var(--shadow-card);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .accounts-dash .welcome-text h1 {
            font-size: 22px; font-weight: 700; color: var(--text-primary);
            margin-bottom: 4px; display: flex; align-items: center; gap: 12px;
        }
        .accounts-dash .header-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--color-primary-bg);
            color: var(--color-primary); font-size: 16px;
        }
        .accounts-dash .welcome-text p { color: var(--text-secondary); font-size: 13px; }
        .accounts-dash .date-time { color: var(--text-secondary); font-size: 13px; }
        .accounts-dash .dt-chip {
            display: flex; align-items: center; gap: 10px;
            background: var(--color-primary-bg);
            border: 1px solid var(--border-default);
            border-radius: 14px; padding: 10px 14px;
        }
        .accounts-dash .dt-chip i {
            color: var(--color-primary); font-size: 16px;
            width: 28px; height: 28px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: #f4f7fb;
        }
        .accounts-dash #currentDate { font-weight: 600; color: var(--text-primary); font-size: 13px; }
        .accounts-dash #currentTime { color: var(--text-secondary); font-size: 12px; margin-top: 1px; }

        /* Summary cards */
        .summary-row {
            display: grid; grid-template-columns: repeat(5, 1fr); gap: 16px; margin-bottom: 24px;
        }
        .summary-card {
            background: #f4f7fb; border-radius: 14px; padding: 18px 18px 16px;
            border: 1px solid #d5dce8; position: relative; overflow: hidden;
            box-shadow: var(--shadow-card);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }
        .summary-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
        }
        .summary-card.blue::before { background: #1e3c72; }
        .summary-card.amber::before { background: var(--color-warning); }
        .summary-card.emerald::before { background: var(--color-success); }
        .summary-card.rose::before { background: var(--color-danger); }
        .summary-card.violet::before { background: #5a4e78; }
        .summary-card.blue,
        .summary-card.amber,
        .summary-card.emerald,
        .summary-card.rose,
        .summary-card.violet { background: #f4f7fb; }
        .summary-card .card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .summary-card .card-icon {
            width: 42px; height: 42px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
        }
        .summary-card.blue .card-icon { background: rgba(59,130,246,0.14); color: #2563eb; }
        .summary-card.amber .card-icon { background: rgba(245,158,11,0.16); color: #d97706; }
        .summary-card.emerald .card-icon { background: rgba(16,185,129,0.16); color: #059669; }
        .summary-card.rose .card-icon { background: rgba(244,63,94,0.14); color: #e11d48; }
        .summary-card.violet .card-icon { background: rgba(139,92,246,0.16); color: #7c3aed; }
        .summary-card .card-value { font-size: 28px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.03em; }
        .summary-card .card-label { font-size: 12px; color: var(--text-secondary); font-weight: 600; margin-top: 2px; }

        /* Workflow cards */
        .workflow-container { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 24px; }
        .workflow-card {
            background: #f4f7fb; border-radius: 14px; padding: 20px;
            border: 1px solid #d5dce8; box-shadow: var(--shadow-card);
            position: relative; overflow: hidden;
        }
        .workflow-card.panel-verified::after { background: var(--color-primary); }
        .workflow-card.panel-locked::after { background: var(--color-danger); }
        .workflow-card.panel-deactivated::after { background: var(--color-warning); }
        .workflow-card.panel-inactive::after { background: var(--color-info); }
        .workflow-card::after {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
        }
        .workflow-header {
            display: flex; justify-content: space-between; align-items: center; gap: 10px;
            margin: -20px -20px 16px; padding: 14px 18px 14px 20px;
            border-bottom: 1px solid var(--border-light);
            background: var(--bg-hover);
        }
        .workflow-card.panel-verified .workflow-header { background: var(--color-primary-bg); }
        .workflow-card.panel-locked .workflow-header { background: var(--color-danger-bg); }
        .workflow-card.panel-deactivated .workflow-header { background: var(--color-warning-bg); }
        .workflow-card.panel-inactive .workflow-header { background: var(--color-info-bg); }
        .workflow-title {
            font-size: 14px; font-weight: 600; color: var(--text-primary);
            display: flex; align-items: center; gap: 10px;
        }
        .workflow-title .title-icon {
            width: 30px; height: 30px; border-radius: 9px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0;
        }
        .workflow-card.panel-verified .title-icon { background: var(--color-primary-bg); color: var(--color-primary); }
        .workflow-card.panel-locked .title-icon { background: var(--color-danger-bg); color: var(--color-danger); }
        .workflow-card.panel-deactivated .title-icon { background: var(--color-warning-bg); color: var(--color-warning); }
        .workflow-card.panel-inactive .title-icon { background: var(--color-info-bg); color: var(--color-info); }
        .workflow-badge {
            background: var(--color-primary); color: #fff; padding: 2px 10px;
            border-radius: 12px; font-size: 11px; font-weight: 600;
        }
        .workflow-card.panel-locked .workflow-badge { background: var(--color-danger); }
        .workflow-card.panel-deactivated .workflow-badge { background: var(--color-warning); }
        .workflow-card.panel-inactive .workflow-badge { background: var(--color-info); }
        .workflow-content { max-height: 600px; overflow-y: auto; }

        /* Filter */
        .filter-section {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 10px;
            background: var(--bg-hover);
            border: 1px solid var(--border-light);
            border-radius: 10px;
        }
        .filter-dropdown {
            padding: 8px 12px; border: 1px solid var(--border-default);
            border-radius: 8px; background: #f4f7fb; font-size: 13px;
            color: var(--text-primary);
        }
        .filter-button {
            padding: 8px 16px; border: none; border-radius: 8px; cursor: pointer;
            font-size: 13px; font-weight: 600; color: #fff;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.25);
            transition: filter 0.15s ease, transform 0.15s ease;
        }
        .filter-button:hover { filter: brightness(1.06); transform: translateY(-1px); }

        /* Tables */
        .table-container {
            overflow-x: auto; border: 1px solid var(--border-light);
            border-radius: 12px; background: #f4f7fb;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 11px 12px; text-align: left;
            border-bottom: 1px solid var(--border-light); font-size: 13px;
        }
        th {
            background: var(--bg-hover); font-weight: 600; color: var(--text-secondary);
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.4px;
        }
        td { color: var(--text-primary); }
        tbody tr { transition: background 0.15s ease; }
        tbody tr:hover td { background: var(--bg-hover); }
        tbody tr:last-child td { border-bottom: none; }

        /* Badges */
        .status-badge {
            display: inline-block; padding: 3px 9px; border-radius: 999px;
            font-size: 11px; font-weight: 600; letter-spacing: 0.01em;
        }
        .status-verified,
        .status-active { background: var(--badge-completed-bg); color: var(--badge-completed-text); }
        .status-inactive { background: var(--badge-pending-bg); color: var(--badge-pending-text); }
        .status-pending { background: var(--badge-pending-bg); color: var(--badge-pending-text); }
        .status-rejected { background: var(--badge-cancelled-bg); color: var(--badge-cancelled-text); }
        .status-deactivated { background: var(--badge-cancelled-bg); color: var(--badge-cancelled-text); }
        .status-locked { background: var(--badge-cancelled-bg); color: var(--badge-cancelled-text); }

        /* Activity indicator */
        .activity-indicator {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 9px; border-radius: 999px;
            font-size: 11px; font-weight: 600; white-space: nowrap; line-height: 1.2;
        }
        .activity-indicator .dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; background: currentColor; }
        .activity-recent { background: var(--color-success-bg); color: var(--color-success-text); }
        .activity-moderate { background: var(--color-warning-bg); color: var(--color-warning-text); }
        .activity-idle { background: var(--color-info-bg); color: var(--color-info-text); }
        .activity-never { background: var(--bg-input-readonly); color: var(--text-secondary); }

        /* Buttons */
        .action-buttons { display: flex; gap: 8px; }
        .btn-sm {
            padding: 6px 11px; font-size: 11px; border: none; border-radius: 8px;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            font-weight: 600; text-decoration: none; white-space: nowrap;
            transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
        }
        .btn-sm:hover { transform: translateY(-1px); }
        .btn-placeholder, .btn-manage { background: var(--text-muted); color: #fff; }
        .btn-placeholder:hover, .btn-manage:hover { filter: brightness(1.08); }
        .btn-view, .btn-edit {
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            color: #fff; box-shadow: 0 4px 12px rgba(55, 98, 200, 0.25);
        }
        .btn-view:hover, .btn-edit:hover { filter: brightness(1.06); color: #fff; }
        .btn-approve, .btn-save, .btn-unlock {
            background: var(--color-success); color: #fff;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        .btn-approve:hover, .btn-save:hover, .btn-unlock:hover { filter: brightness(1.06); color: #fff; }
        .btn-deactivate {
            background: var(--color-danger); color: #fff;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.25);
        }
        .btn-deactivate:hover { filter: brightness(1.06); color: #fff; }

        .editable-field { transition: border-color 0.2s, box-shadow 0.2s; }
        .editable-field.editing {
            border-color: var(--color-primary) !important;
            box-shadow: 0 0 0 2px var(--color-primary-bg) !important;
            background: var(--color-primary-bg) !important;
        }
        .editable-field.editing:disabled,
        .editable-field:disabled { cursor: default; }

        /* Enhanced user modal */
        .modal-content.user-modal-content {
            max-width: 600px;
            width: 92vw;
            max-height: calc(100vh - 48px);
            padding: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .modal-content.user-modal-content .user-modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 20px; border-bottom: 1px solid var(--border-light);
            background: var(--bg-hover); flex: 0 0 auto;
        }
        .user-modal-header .modal-title {
            display: flex; align-items: center; gap: 10px; font-size: 15px;
        }
        .user-modal-header .modal-title-icon {
            width: 32px; height: 32px; border-radius: 9px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--color-primary-bg); color: var(--color-primary); font-size: 13px;
        }
        .modal-content.user-modal-content .user-modal-body {
            padding: 16px 20px;
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
        }

        .profile-summary {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 14px; margin-bottom: 14px;
            background: #eef3fa; border: 1px solid var(--border-light);
            border-radius: 12px;
        }
        body.dark-mode .accounts-dash .profile-summary { background: rgba(255,255,255,0.04) !important; border-color: var(--border-default) !important; }
        .profile-avatar {
            width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; font-weight: 700; color: #fff;
            background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark));
            box-shadow: 0 4px 12px rgba(55, 98, 200, 0.3);
        }
        .profile-info { flex: 1; min-width: 0; }
        .profile-name { font-size: 15px; font-weight: 700; color: var(--text-primary); margin-bottom: 2px; }
        .profile-email { font-size: 12px; color: var(--text-secondary); margin-bottom: 7px; }
        .profile-badges { display: flex; gap: 8px; flex-wrap: wrap; }
        .profile-badge {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 600;
        }
        .profile-badge i { font-size: 10px; }
        .profile-badge-role { background: var(--color-primary-bg); color: var(--color-primary); }
        .profile-badge-active { background: var(--color-success-bg); color: var(--color-success-text); }
        .profile-badge-inactive { background: var(--color-warning-bg); color: var(--color-warning-text); }
        .profile-badge-locked { background: var(--color-danger-bg); color: var(--color-danger-text); }
        .profile-badge-deactivated { background: var(--badge-cancelled-bg); color: var(--badge-cancelled-text); }
        body.dark-mode .accounts-dash .profile-badge-role { background: var(--color-primary-bg); color: var(--color-primary); }
        body.dark-mode .accounts-dash .profile-badge-active { background: var(--color-success-bg); color: var(--color-success-text); }
        body.dark-mode .accounts-dash .profile-badge-inactive { background: var(--color-warning-bg); color: var(--color-warning-text); }
        body.dark-mode .accounts-dash .profile-badge-locked { background: var(--color-danger-bg); color: var(--color-danger-text); }

        .modal-section-title {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: var(--text-muted);
            margin: 14px 0 10px; display: flex; align-items: center; gap: 8px;
        }
        .modal-section-title:first-child { margin-top: 0; }
        .modal-section-title::after { content: ''; flex: 1; height: 1px; background: var(--border-light); }

        .modal-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .modal-form-grid .form-group { margin-bottom: 0; }

        .id-file-block {
            display: flex; align-items: center; gap: 14px;
            padding: 10px 12px; margin-top: 14px;
            background: var(--bg-hover); border: 1px solid var(--border-light);
            border-radius: 12px;
        }
        .id-file-thumb {
            width: 56px; height: 56px; border-radius: 10px; overflow: hidden;
            flex-shrink: 0; background: #e2e8f0; display: flex; align-items: center; justify-content: center;
        }
        .id-file-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .id-file-thumb .id-file-empty {
            font-size: 24px; color: var(--text-muted); opacity: 0.6;
        }
        .id-file-meta { flex: 1; min-width: 0; }
        .id-file-label { font-size: 12px; font-weight: 600; color: var(--text-primary); margin-bottom: 2px; }
        .id-file-sub { font-size: 11px; color: var(--text-muted); }

        .modal-content.user-modal-content .user-modal-actions {
            display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;
            padding: 14px 20px; border-top: 1px solid var(--border-light);
            background: var(--bg-hover); flex: 0 0 auto;
        }
        .save-spinner i { animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .unsaved-dot {
            display: none; width: 8px; height: 8px; border-radius: 50%;
            background: var(--color-warning); flex-shrink: 0;
        }
        .form-group.dirty .unsaved-dot { display: inline-block; }

        /* Audit log */
        .audit-log { max-height: 400px; overflow-y: auto; }
        .log-entry {
            padding: 15px; border-bottom: 1px solid var(--border-light);
            border-radius: 10px; transition: background 0.15s ease;
        }
        .log-entry:hover { background: var(--bg-hover); }
        .log-entry:last-child { border-bottom: none; }
        .log-action { font-weight: 500; color: var(--text-primary); }
        .log-details { color: var(--text-secondary); font-size: 0.9em; margin-top: 5px; }
        .log-time { color: var(--text-muted); font-size: 0.85em; }

        /* Modal (blend with dashboard) */
        .modal {
            display: none; position: fixed; z-index: 1000; left: 0; top: 0;
            width: 100%; height: 100%; background-color: var(--bg-overlay);
            align-items: center; justify-content: center;
        }
        .modal-content {
            background-color: #f4f7fb; padding: 28px; border-radius: 14px;
            width: 90%; max-width: 620px; border: 1px solid #d5dce8;
            box-shadow: var(--shadow-lg); color: var(--text-primary);
            position: relative; top: auto; left: auto; transform: none;
            margin: 0 auto;
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-title { font-size: 16px; font-weight: 600; color: var(--text-primary); }
        .close { font-size: 24px; cursor: pointer; color: var(--text-muted); }
        .close:hover { color: var(--color-danger); }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-weight: 600; color: var(--text-secondary); font-size: 12px; }
        .modal-content input,
        .modal-content select {
            width: 100%; padding: 9px 12px;
            border: 1px solid var(--border-default);
            border-radius: 8px; background: #f4f7fb;
            box-sizing: border-box; color: var(--text-primary); font-size: 13px;
        }
        .modal-content input:disabled,
        .modal-content select:disabled { background: var(--bg-input-readonly); color: var(--text-secondary); }
        .modal-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .modal-form-grid .form-group { margin-bottom: 0; }
        .modal-actions {
            display: flex; gap: 10px; justify-content: flex-end;
            margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border-light);
        }
        button { font-family: 'Poppins', sans-serif; }

        /* Dark mode */
        body.dark-mode .accounts-dash .dashboard-header,
        body.dark-mode .accounts-dash .summary-card,
        body.dark-mode .accounts-dash .workflow-card,
        body.dark-mode .accounts-dash .table-container,
        body.dark-mode .accounts-dash .modal-content {
            background: #1c2432 !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .accounts-dash .workflow-header { background: rgba(255,255,255,0.03) !important; border-color: var(--border-default) !important; }
        body.dark-mode .accounts-dash .panel-verified .workflow-header { background: var(--color-primary-bg) !important; }
        body.dark-mode .accounts-dash .panel-locked .workflow-header { background: var(--color-danger-bg) !important; }
        body.dark-mode .accounts-dash .panel-deactivated .workflow-header { background: var(--color-warning-bg) !important; }
        body.dark-mode .accounts-dash .panel-inactive .workflow-header { background: var(--color-info-bg) !important; }
        body.dark-mode .accounts-dash .dt-chip { background: var(--color-primary-bg); border-color: var(--border-default); }
        body.dark-mode .accounts-dash .dt-chip i { background: #1c2432; }
        body.dark-mode .accounts-dash .card-value,
        body.dark-mode .accounts-dash .workflow-title,
        body.dark-mode .accounts-dash th,
        body.dark-mode .accounts-dash td { color: var(--text-primary); }
        body.dark-mode .accounts-dash .card-label,
        body.dark-mode .accounts-dash .welcome-text p,
        body.dark-mode .accounts-dash .filter-dropdown { color: var(--text-secondary) !important; }
        body.dark-mode .accounts-dash .filter-dropdown { background: #1c2432 !important; border-color: var(--border-default) !important; }
        body.dark-mode .accounts-dash .filter-section { background: transparent !important; border-color: var(--border-default) !important; }
        body.dark-mode .accounts-dash .filter-section .filter-button { box-shadow: none; }
        body.dark-mode .accounts-dash .modal-content { background: #1c2432 !important; border-color: rgba(147, 179, 224, 0.22) !important; }
        body.dark-mode .accounts-dash .modal-content input,
        body.dark-mode .accounts-dash .modal-content select { background: #1c2432 !important; border-color: var(--border-default) !important; color: var(--text-primary) !important; }
        body.dark-mode .accounts-dash .modal-content input:disabled,
        body.dark-mode .accounts-dash .modal-content select:disabled { background: var(--bg-input-readonly) !important; }

        @media (max-width: 1400px) {
            .summary-row { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 768px) {
            .main-content.accounts-dash { margin-left: 0; padding: 16px; }
            .summary-row { grid-template-columns: repeat(2, 1fr); }
            .accounts-dash .dashboard-header { flex-direction: column; align-items: flex-start; }
            .accounts-dash .modal-content { max-width: 96vw; }
            .modal-form-grid { grid-template-columns: 1fr; }
            .modal-content.user-modal-content {
                max-height: calc(100vh - 24px);
                width: 96vw;
            }
            .modal-content.user-modal-content .user-modal-body { padding: 12px 16px; }
            .modal-content.user-modal-content .user-modal-header { padding: 12px 16px; }
            .modal-content.user-modal-content .user-modal-actions { padding: 12px 16px; }

            /* Workflow header - mobile compatibility */
            .accounts-dash .workflow-card { padding: 16px; }
            .accounts-dash .workflow-header {
                flex-direction: column;
                align-items: stretch;
                gap: 12px;
                margin: -16px -16px 14px;
                padding: 12px 14px;
            }
            .accounts-dash .workflow-title { flex-wrap: wrap; row-gap: 6px; font-size: 13px; min-width: 0; }
            .accounts-dash .workflow-badge { flex-shrink: 0; }
            /* Filter controls get their own contained box so they never overlap the header container */
            .accounts-dash .filter-section {
                width: 100%;
                min-width: 0;
                max-width: 100%;
                justify-content: space-between;
                flex-wrap: wrap;
            }
            .accounts-dash .filter-dropdown {
                flex: 1 1 auto;
                min-width: 0;
                max-width: none;
            }
            .accounts-dash .workflow-content { max-height: 420px; }
        }
        @media (max-width: 480px) {
            .summary-row { grid-template-columns: 1fr; }

            /* Workflow header - small phones */
            .accounts-dash .workflow-card { padding: 14px; }
            .accounts-dash .workflow-header {
                margin: -14px -14px 12px;
                padding: 10px 12px;
            }
            .accounts-dash .workflow-title { font-size: 12.5px; }
            .accounts-dash .workflow-title .title-icon { width: 26px; height: 26px; font-size: 11px; border-radius: 8px; }
            .accounts-dash .filter-section { gap: 6px; }
            .accounts-dash .filter-dropdown { padding: 7px 8px; font-size: 12px; }
            .accounts-dash .filter-button { padding: 7px 12px; font-size: 12px; }
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <!-- SIDEBAR -->
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content accounts-dash">
        <!-- User Modal (Manage / View) -->
        <div id="userModal" class="modal">
            <div class="modal-content user-modal-content">
                <div class="user-modal-header">
                    <h2 class="modal-title"><span class="modal-title-icon"><i class="fas fa-user"></i></span> <span id="userModalTitle">User Details</span></h2>
                    <span class="close" onclick="closeUserModal()">&times;</span>
                </div>
                <div class="user-modal-body">
                    <!-- Profile summary -->
                    <div class="profile-summary">
                        <div class="profile-avatar" id="modalAvatar">—</div>
                        <div class="profile-info">
                            <div class="profile-name" id="modalDisplayName">—</div>
                            <div class="profile-email" id="modalDisplayEmail">—</div>
                            <div class="profile-badges">
                                <span class="profile-badge profile-badge-role" id="modalRoleBadge"><i class="fas fa-user-tag"></i> <span id="modalRoleBadgeText">—</span></span>
                                <span class="profile-badge profile-badge-active" id="modalStatusBadge"><i class="fas fa-circle"></i> <span id="modalStatusBadgeText">—</span></span>
                            </div>
                        </div>
                    </div>

                    <!-- Basic information -->
                    <div class="modal-section-title">Basic Information</div>
                    <div class="modal-form-grid">
                        <div class="form-group">
                            <label>Full Name <span class="unsaved-dot"></span></label>
                            <input type="text" id="modalFullName" class="editable-field" disabled>
                        </div>
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" id="modalEmail" disabled>
                        </div>
                        <div class="form-group">
                            <label>Role <span class="unsaved-dot"></span></label>
                            <select id="modalRole" class="editable-field" disabled>
                                <option value="">-- Select --</option>
                                <option value="lgu_staff">LGU Staff</option>
                                <option value="citizen">Citizen</option>
                                <option value="road_ops_supervisor">Road Operations Supervisor</option>
                                <option value="trans_ops_supervisor">Transportation Operations Supervisor</option>
                                <option value="road_monitoring_officer">Road Monitoring Officer</option>
                                <option value="trans_monitoring_officer">Transportation Monitoring Officer</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Department <span class="unsaved-dot"></span></label>
                            <input type="text" id="modalDepartment" class="editable-field" disabled>
                        </div>
                        <div class="form-group">
                            <label>Contact Number <span class="unsaved-dot"></span></label>
                            <input type="tel" id="modalPhoneNumber" class="editable-field" maxlength="20" pattern="[0-9+\-\s()]+" title="Enter a valid contact number" disabled>
                        </div>
                        <div class="form-group">
                            <label>Birthday <span class="unsaved-dot"></span></label>
                            <input type="date" id="modalBirthday" class="editable-field" disabled>
                        </div>
                        <div class="form-group">
                            <label>Civil Status <span class="unsaved-dot"></span></label>
                            <select id="modalCivilStatus" class="editable-field" disabled>
                                <option value="">-- Select --</option>
                                <option value="single">Single</option>
                                <option value="married">Married</option>
                                <option value="widowed">Widowed</option>
                                <option value="separated">Separated</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Address <span class="unsaved-dot"></span></label>
                            <input type="text" id="modalAddress" class="editable-field" disabled>
                        </div>
                    </div>

                    <!-- Account information -->
                    <div class="modal-section-title">Account Information</div>
                    <div class="modal-form-grid">
                        <div class="form-group">
                            <label>Account Status</label>
                            <input type="text" id="modalAccountStatus" disabled>
                        </div>
                        <div class="form-group">
                            <label>Last Active</label>
                            <input type="text" id="modalLastActive" disabled>
                        </div>
                        <div class="form-group">
                            <label>Created At</label>
                            <input type="text" id="modalCreatedAt" disabled>
                        </div>
                        <div class="form-group">
                            <label>Approved At</label>
                            <input type="text" id="modalApprovedAt" disabled>
                        </div>
                    </div>

                    <!-- ID file -->
                    <div class="modal-section-title">Government ID</div>
                    <div class="id-file-block">
                        <div class="id-file-thumb">
                            <img id="modalIdFile" src="" alt="ID File" style="display: none;">
                            <span class="id-file-empty" id="modalIdFileEmpty"><i class="fas fa-id-card"></i></span>
                        </div>
                        <div class="id-file-meta">
                            <div class="id-file-label" id="modalIdFileName">No ID file uploaded</div>
                            <div class="id-file-sub">Uploaded identification document for this account.</div>
                        </div>
                    </div>
                </div>
                <div class="user-modal-actions">
                    <button type="button" class="btn-sm btn-placeholder" id="cancelEditBtn" onclick="cancelEditUser()" style="display:none;">Cancel</button>
                    <button type="button" class="btn-sm btn-save save-spinner" id="saveBtn" onclick="saveUser()" style="display:none;"><i class="fas fa-check"></i> Save Changes</button>
                    <button type="button" class="btn-sm btn-view" id="editBtn" onclick="enableEditUser()"><i class="fas fa-pen"></i> Edit Details</button>
                    <button type="button" class="btn-sm btn-deactivate" id="actionButton"></button>
                    <button type="button" class="btn-sm btn-placeholder" onclick="closeUserModal()">Close</button>
                </div>
            </div>
        </div>

        <style>
            .modal {
                display: none;
                position: fixed;
                z-index: 1000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: var(--bg-overlay);
                align-items: center;
                justify-content: center;
            }
            .modal-content {
                background-color: #f4f7fb;
                padding: 28px;
                border-radius: 14px;
                width: 90%;
                max-width: 620px;
                border: 1px solid #d5dce8;
                box-shadow: var(--shadow-lg);
                color: var(--text-primary);
                position: relative;
                top: auto;
                left: auto;
                transform: none;
                margin: 0 auto;
            }
            .modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 16px;
            }
            .modal-title {
                margin: 0;
                font-size: 16px;
                font-weight: 600;
                color: var(--text-primary);
            }
            .close {
                font-size: 24px;
                cursor: pointer;
                color: var(--text-muted);
            }
            .close:hover { color: var(--color-danger); }
            .form-group {
                margin-bottom: 15px;
            }
            label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
                color: var(--text-secondary);
                font-size: 12px;
            }
            .modal-content input,
            .modal-content select {
                width: 100%;
                padding: 9px 12px;
                border: 1px solid var(--border-default);
                border-radius: 8px;
                background: #f4f7fb;
                box-sizing: border-box;
                color: var(--text-primary);
                font-size: 13px;
            }
            .modal-content input:disabled,
            .modal-content select:disabled { background: var(--bg-input-readonly); color: var(--text-secondary); }
            select.editable-field { width: 100%; }
            .modal-form-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
            }
            .modal-form-grid .form-group {
                margin-bottom: 0;
            }
            .modal-actions {
                display: flex;
                gap: 10px;
                justify-content: flex-end;
                margin-top: 20px;
                padding-top: 16px;
                border-top: 1px solid var(--border-light);
            }
            button { font-family: 'Poppins', sans-serif; }
            body.dark-mode .modal-content { background: #1c2432 !important; border-color: rgba(147, 179, 224, 0.22) !important; }
            body.dark-mode .modal-content input,
            body.dark-mode .modal-content select { background: #1c2432 !important; border-color: var(--border-default) !important; color: var(--text-primary) !important; }
            body.dark-mode .modal-content input:disabled,
            body.dark-mode .modal-content select:disabled { background: var(--bg-input-readonly) !important; }
            @media (max-width: 768px) {
                .modal-form-grid { grid-template-columns: 1fr; }
                .modal-content { max-width: 96vw; }
            }
        </style>

        <!-- Dashboard Header -->
        <div class="dashboard-header">
            <div class="welcome-text">
                <h1><span class="header-icon"><i class="fas fa-users"></i></span> Manage Accounts</h1>
                <p>Manage verified LGU Staff accounts</p>
            </div>
            <div class="date-time">
                <div class="dt-chip">
                    <i class="fas fa-calendar-day"></i>
                    <div>
                        <div id="currentDate"></div>
                        <div id="currentTime"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistics -->
        <div class="summary-row">
            <div class="summary-card blue">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-user-check"></i></div>
                </div>
                <div class="card-value"><?php echo $active_accounts; ?></div>
                <div class="card-label">Total Active Accounts</div>
            </div>
            <div class="summary-card rose">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-user-slash"></i></div>
                </div>
                <div class="card-value"><?php echo $inactive_accounts; ?></div>
                <div class="card-label">Total Inactive Accounts</div>
            </div>
            <div class="summary-card amber">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-user-clock"></i></div>
                </div>
                <div class="card-value"><?php echo count($unverified_users); ?></div>
                <div class="card-label">Pending/Rejected Accounts</div>
            </div>
            <div class="summary-card violet">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-user-xmark"></i></div>
                </div>
                <div class="card-value"><?php echo count($deactivated_users); ?></div>
                <div class="card-label">Deactivated Accounts</div>
            </div>
            <div class="summary-card emerald">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-lock"></i></div>
                </div>
                <div class="card-value"><?php echo count($locked_users); ?></div>
                <div class="card-label">Locked Accounts</div>
            </div>
        </div>

        <div class="workflow-container">
            <!-- Verified Accounts -->
            <div class="workflow-card panel-verified">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <span class="title-icon"><i class="fas fa-users"></i></span>
                        <span>Verified Accounts</span>
                        <span class="workflow-badge"><?php echo count($users); ?></span>
                    </h3>
                    <div class="filter-section">
                        <label for="statusFilter" style="font-size: 12px;" class="t-text-secondary">Filter by:</label>
                        <select id="statusFilter" class="filter-dropdown">
                            <option value="all">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        <button class="filter-button" onclick="applyFilter()">Go</button>
                    </div>
                </div>
                
                <div class="workflow-content">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Active</th>
                                    <th>Last Active</th>
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($users)): ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center;" class="t-text-secondary">No verified accounts found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="status-badge status-verified">Verified</span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo $user['is_active'] ? 'verified' : 'inactive'; ?>">
                                                    <?php echo $user['is_active'] ? 'Yes' : 'No'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="activity-indicator activity-recent" data-user-id="<?php echo $user['id']; ?>" data-last-active="<?php echo !empty($user['last_activity']) ? date('c', strtotime($user['last_activity'])) : ''; ?>" title="<?php echo !empty($user['last_activity']) ? date('M d, Y h:i A', strtotime($user['last_activity'])) : 'No activity recorded'; ?>">
                                                    <span class="dot"></span>
                                                    <span class="activity-text"><?php echo formatLastActive($user['last_activity']); ?></span>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-sm btn-placeholder" onclick="showUserModal(<?php echo $user['id']; ?>)">Manage</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Locked Accounts (currently lock_until > NOW()) -->
            <div class="workflow-card panel-locked">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <span class="title-icon"><i class="fas fa-lock"></i></span>
                        <span>Locked Accounts</span>
                        <span class="workflow-badge"><?php echo count($locked_users); ?></span>
                    </h3>
                </div>

                <div class="workflow-content">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Locked Until</th>
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($locked_users)): ?>
                                    <tr>
                                        <td colspan="8" style="text-align: center;" class="t-text-secondary">No locked accounts found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($locked_users as $user): ?>
                                        <tr id="locked-row-<?php echo (int)$user['id']; ?>">
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="status-badge status-locked">Locked</span>
                                            </td>
                                            <td><?php echo !empty($user['lock_until']) ? date('M d, Y h:i A', strtotime($user['lock_until'])) : '—'; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button type="button" class="btn-sm btn-view" onclick="viewLockedUser(<?php echo (int)$user['id']; ?>)">
                                                        <i class="fas fa-eye"></i> View
                                                    </button>
                                                    <button type="button" class="btn-sm btn-unlock" onclick="unlockAccount(<?php echo (int)$user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['full_name']), ENT_QUOTES); ?>')">
                                                        <i class="fas fa-unlock"></i> Unlock
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Deactivated Accounts -->
            <div class="workflow-card panel-deactivated">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <span class="title-icon"><i class="fas fa-user-xmark"></i></span>
                        <span>Deactivated Accounts</span>
                        <span class="workflow-badge"><?php echo count($deactivated_users); ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                    <th>Deactivated On</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($deactivated_users)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center;" class="t-text-secondary">No deactivated accounts found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($deactivated_users as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="status-badge status-deactivated">Deactivated</span>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($user['updated_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-sm btn-approve" onclick="reactivateAccount(<?php echo $user['id']; ?>)"><i class="fas fa-rotate-right"></i> Reactivate</button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Inactive Users (2+ Weeks) -->
            <div class="workflow-card panel-inactive">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <span class="title-icon"><i class="fas fa-user-slash"></i></span>
                        <span>Inactive Users (2+ Weeks)</span>
                        <span class="workflow-badge"><?php echo count($inactive_2weeks_users); ?></span>
                    </h3>
                </div>
                
                <div class="workflow-content">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Department</th>
                                    <th>Last Login</th>
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($inactive_2weeks_users)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center;" class="t-text-secondary">No inactive users found</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($inactive_2weeks_users as $user): ?>
                                        <tr id="inactive-row-<?php echo $user['id']; ?>">
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td><?php echo $user['last_login'] ? date('M d, Y', strtotime($user['last_login'])) : 'Never'; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <button class="btn-sm btn-deactivate" onclick="confirmDeactivate(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['full_name'])); ?>')">
                                                    <i class="fas fa-user-slash"></i> Deactivate
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    <!-- Deactivate Confirmation Modal -->
    <div id="deactivateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" style="color:#dc2626;"><i class="fas fa-exclamation-triangle"></i> Confirm Deactivation</h3>
                <span class="close" onclick="closeDeactivateModal()">&times;</span>
            </div>
            <p id="deactivateModalBody">Are you sure you want to deactivate this account?</p>
            <p style="font-size: 13px; color: #64748b; margin-bottom: 20px;">The user will no longer be able to log in or access the system.</p>
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn-sm btn-deactivate" id="deactivateConfirmBtn" onclick="executeDeactivate()">
                    <i class="fas fa-user-slash"></i> Deactivate Account
                </button>
                <button type="button" class="btn-sm btn-manage" onclick="closeDeactivateModal()">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        let currentUserId = null;
        let isEditing = false;
        let isViewOnly = false;
        let usersData = <?php echo json_encode($users); ?>;
        let lockedUsersData = <?php echo json_encode($locked_users); ?>;
        
        const editableFields = ['modalFullName', 'modalRole', 'modalDepartment', 'modalAddress', 'modalBirthday', 'modalCivilStatus', 'modalPhoneNumber'];

        function roleLabel(role) {
            const map = {
                'system_admin': 'System Admin',
                'lgu_staff': 'LGU Staff',
                'citizen': 'Citizen',
                'road_ops_supervisor': 'Road Operations Supervisor',
                'trans_ops_supervisor': 'Transportation Operations Supervisor',
                'road_monitoring_officer': 'Road Monitoring Officer',
                'trans_monitoring_officer': 'Transportation Monitoring Officer'
            };
            return map[role] || role || '—';
        }

        function formatDateTimeValue(val) {
            if (!val || val === '0000-00-00 00:00:00') return '—';
            const d = new Date(val);
            if (isNaN(d.getTime())) return val;
            return d.toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        function formatDateValue(val) {
            if (!val || val === '0000-00-00') return '—';
            const d = new Date(val);
            if (isNaN(d.getTime())) return val;
            return d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
        }

        function initialsOf(name) {
            if (!name) return '?';
            const parts = name.trim().split(/\s+/).filter(Boolean);
            if (parts.length === 0) return '?';
            return (parts[0][0] + (parts[parts.length - 1][0] || '')).toUpperCase();
        }

        function userStatusInfo(user, viewOnly) {
            let statusText, statusClass, statusIcon;
            if (viewOnly && user.lock_until && new Date(user.lock_until) > new Date()) {
                statusText = 'Locked';
                statusClass = 'profile-badge-locked';
                statusIcon = 'fa-lock';
            } else if (user.account_status === 'deactivated') {
                statusText = 'Deactivated';
                statusClass = 'profile-badge-deactivated';
                statusIcon = 'fa-ban';
            } else if (user.is_active) {
                statusText = 'Active';
                statusClass = 'profile-badge-active';
                statusIcon = 'fa-circle';
            } else {
                statusText = 'Inactive';
                statusClass = 'profile-badge-inactive';
                statusIcon = 'fa-circle';
            }
            return { statusText, statusClass, statusIcon };
        }

        function showUserModal(userId, options) {
            options = options || {};
            const viewOnly = !!options.viewOnly;
            isViewOnly = viewOnly;
            currentUserId = userId;
            isEditing = false;
            const user = usersData.find(u => u.id == userId)
                || lockedUsersData.find(u => u.id == userId);
            
            if (user) {
                // Profile summary
                document.getElementById('modalAvatar').textContent = initialsOf(user.full_name);
                document.getElementById('modalDisplayName').textContent = user.full_name || '—';
                document.getElementById('modalDisplayEmail').textContent = user.email || '—';
                document.getElementById('modalRoleBadgeText').textContent = roleLabel(user.role);
                const status = userStatusInfo(user, viewOnly);
                const statusBadge = document.getElementById('modalStatusBadge');
                statusBadge.className = 'profile-badge ' + status.statusClass;
                statusBadge.innerHTML = '<i class="fas ' + status.statusIcon + '"></i> <span>' + status.statusText + '</span>';
                document.getElementById('userModalTitle').textContent = viewOnly ? 'View User' : 'Manage User';

                // Fields
                document.getElementById('modalEmail').value = user.email || '';
                document.getElementById('modalFullName').value = user.full_name || '';
                document.getElementById('modalRole').value = user.role || '';
                document.getElementById('modalDepartment').value = user.department || '';
                document.getElementById('modalAddress').value = user.address || '';
                document.getElementById('modalBirthday').value = user.birthday || '';
                document.getElementById('modalCivilStatus').value = user.civil_status || '';
                document.getElementById('modalPhoneNumber').value = user.phone_number || '';

                // Account info
                if (viewOnly && user.lock_until && new Date(user.lock_until) > new Date()) {
                    document.getElementById('modalAccountStatus').value = 'Locked until ' + formatDateTimeValue(user.lock_until);
                } else {
                    document.getElementById('modalAccountStatus').value = user.is_active ? 'Active' : 'Inactive';
                }
                document.getElementById('modalLastActive').value = user.last_activity ? formatDateTimeValue(user.last_activity) : 'Never active';
                document.getElementById('modalCreatedAt').value = formatDateTimeValue(user.created_at);
                document.getElementById('modalApprovedAt').value = user.approved_at ? formatDateTimeValue(user.approved_at) : '—';

                // ID file
                const idFileImg = document.getElementById('modalIdFile');
                const idFileEmpty = document.getElementById('modalIdFileEmpty');
                const idFileName = document.getElementById('modalIdFileName');
                if (user.id_file_path) {
                    idFileImg.src = '../../' + user.id_file_path;
                    idFileImg.style.display = 'block';
                    idFileEmpty.style.display = 'none';
                    idFileName.textContent = (user.id_file_path.split('/').pop() || 'ID file') + ' (ID)';
                } else {
                    idFileImg.style.display = 'none';
                    idFileEmpty.style.display = 'flex';
                    idFileName.textContent = 'No ID file uploaded';
                }

                // Edit / action controls
                setFieldsDisabled(true);
                setDirtyTracking(false);
                const editBtn = document.getElementById('editBtn');
                const saveBtn = document.getElementById('saveBtn');
                const cancelBtn = document.getElementById('cancelEditBtn');
                const actionButton = document.getElementById('actionButton');

                if (viewOnly) {
                    editBtn.style.display = 'none';
                    saveBtn.style.display = 'none';
                    cancelBtn.style.display = 'none';
                    actionButton.style.display = 'none';
                    actionButton.onclick = null;
                } else {
                    editBtn.style.display = '';
                    actionButton.style.display = '';
                    if (user.is_active) {
                        actionButton.textContent = 'Deactivate';
                        actionButton.className = 'btn-sm btn-deactivate';
                        actionButton.innerHTML = '<i class="fas fa-user-slash"></i> Deactivate';
                        actionButton.onclick = deactivateAccount;
                    } else {
                        actionButton.textContent = 'Activate';
                        actionButton.className = 'btn-sm btn-approve';
                        actionButton.innerHTML = '<i class="fas fa-user-check"></i> Activate';
                        actionButton.onclick = activateAccount;
                    }
                }

                // Show modal
                const modal = document.getElementById('userModal');
                modal.style.display = 'flex';
            }
        }

        let fieldOriginals = {};

        function captureOriginals() {
            fieldOriginals = {};
            const user = usersData.find(u => u.id == currentUserId) || lockedUsersData.find(u => u.id == currentUserId);
            if (!user) return;
            fieldOriginals['modalFullName'] = user.full_name || '';
            fieldOriginals['modalRole'] = user.role || '';
            fieldOriginals['modalDepartment'] = user.department || '';
            fieldOriginals['modalAddress'] = user.address || '';
            fieldOriginals['modalBirthday'] = user.birthday || '';
            fieldOriginals['modalCivilStatus'] = user.civil_status || '';
            fieldOriginals['modalPhoneNumber'] = user.phone_number || '';
        }

        function refreshDirty() {
            editableFields.forEach(function(id) {
                const el = document.getElementById(id);
                const group = el ? el.closest('.form-group') : null;
                if (!group) return;
                const orig = fieldOriginals[id];
                const cur = el.value || '';
                if (String(orig) !== String(cur)) {
                    group.classList.add('dirty');
                } else {
                    group.classList.remove('dirty');
                }
            });
        }

        function setDirtyTracking(active) {
            const groups = document.querySelectorAll('#userModal .form-group');
            groups.forEach(function(g) { g.classList.remove('dirty'); });
            if (!active) return;
            captureOriginals();
            editableFields.forEach(function(id) {
                const el = document.getElementById(id);
                if (!el) return;
                const handler = function() { refreshDirty(); };
                el.removeEventListener('input', handler);
                el.removeEventListener('change', handler);
                el.addEventListener('input', handler);
                el.addEventListener('change', handler);
            });
        }

        function enableEditUser() {
            if (isViewOnly) return;
            isEditing = true;
            setFieldsDisabled(false);
            document.getElementById('editBtn').style.display = 'none';
            document.getElementById('saveBtn').style.display = '';
            document.getElementById('cancelEditBtn').style.display = '';
            document.getElementById('userModalTitle').textContent = 'Edit User';
            setDirtyTracking(true);
        }

        function cancelEditUser() {
            isEditing = false;
            const user = usersData.find(u => u.id == currentUserId) || lockedUsersData.find(u => u.id == currentUserId);
            if (user) {
                document.getElementById('modalFullName').value = user.full_name || '';
                document.getElementById('modalRole').value = user.role || '';
                document.getElementById('modalDepartment').value = user.department || '';
                document.getElementById('modalAddress').value = user.address || '';
                document.getElementById('modalBirthday').value = user.birthday || '';
                document.getElementById('modalCivilStatus').value = user.civil_status || '';
                document.getElementById('modalPhoneNumber').value = user.phone_number || '';
            }
            setFieldsDisabled(true);
            setDirtyTracking(false);
            document.getElementById('editBtn').style.display = '';
            document.getElementById('saveBtn').style.display = 'none';
            document.getElementById('cancelEditBtn').style.display = 'none';
            document.getElementById('userModalTitle').textContent = 'Manage User';
        }

        function saveUser() {
            if (!currentUserId) return;

            const saveBtn = document.getElementById('saveBtn');
            const originalHtml = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner"></i> Saving...';

            const formData = new FormData();
            formData.append('action', 'update_user');
            formData.append('user_id', currentUserId);
            formData.append('full_name', document.getElementById('modalFullName').value.trim());
            formData.append('role', document.getElementById('modalRole').value.trim());
            formData.append('department', document.getElementById('modalDepartment').value.trim());
            formData.append('address', document.getElementById('modalAddress').value.trim());
            formData.append('birthday', document.getElementById('modalBirthday').value);
            formData.append('civil_status', document.getElementById('modalCivilStatus').value);
            formData.append('phone_number', document.getElementById('modalPhoneNumber').value.trim());

            fetch('', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(result => {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalHtml;
                    if (result.success) {
                        location.reload();
                    } else {
                        alert(result.message || 'Failed to save changes.');
                    }
                })
                .catch(error => {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = originalHtml;
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
        }

        function viewLockedUser(userId) {
            showUserModal(userId, { viewOnly: true });
        }

        function unlockAccount(userId, userName) {
            if (!userId) return;
            const label = userName || 'this account';
            if (!confirm('Unlock the account for ' + label + '? They will return to Verified Accounts.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'unlock_user');
            formData.append('user_id', userId);
            formData.append('remarks', 'Unlocked by admin from Manage Accounts');

            fetch('', { method: 'POST', body: formData })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (result.success) {
                        location.reload();
                    } else {
                        alert(result.message || 'Failed to unlock account.');
                    }
                })
                .catch(function (error) {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
        }

        function setFieldsDisabled(disabled) {
            editableFields.forEach(function(id) {
                const el = document.getElementById(id);
                if (!el) return;
                el.disabled = disabled;
                el.classList.toggle('editing', !disabled);
            });
        }

        function closeUserModal() {
            const modal = document.getElementById('userModal');
            if (modal) {
                modal.style.display = 'none';
            }
            const actionButton = document.getElementById('actionButton');
            if (actionButton) {
                actionButton.style.display = '';
            }
            const editBtn = document.getElementById('editBtn');
            const saveBtn = document.getElementById('saveBtn');
            const cancelBtn = document.getElementById('cancelEditBtn');
            if (editBtn) editBtn.style.display = '';
            if (saveBtn) saveBtn.style.display = 'none';
            if (cancelBtn) cancelBtn.style.display = 'none';
            setDirtyTracking(false);
            currentUserId = null;
            isEditing = false;
            isViewOnly = false;
        }

        function deactivateAccount() {
            if (!currentUserId) return;
            
            if (confirm('Are you sure you want to deactivate this user account?')) {
                // Create form data
                const formData = new FormData();
                formData.append('action', 'deactivate_user');
                formData.append('user_id', currentUserId);
                formData.append('remarks', 'Deactivated by admin from Manage Accounts');
                
                // Send request
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        closeUserModal();
                        location.reload();
                    } else {
                        alert(result.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }

        function activateAccount() {
            if (!currentUserId) return;
            
            if (confirm('Are you sure you want to activate this user account?')) {
                // Create form data
                const formData = new FormData();
                formData.append('action', 'activate_user');
                formData.append('user_id', currentUserId);
                formData.append('remarks', 'Activated by admin from Manage Accounts');
                
                // Send request
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        closeUserModal();
                        location.reload();
                    } else {
                        alert(result.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }

        function reactivateAccount(userId) {
            if (confirm('Are you sure you want to reactivate this account?')) {
                const formData = new FormData();
                formData.append('action', 'reactivate_user');
                formData.append('user_id', userId);
                formData.append('remarks', 'Reactivated by admin from Manage Accounts');
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        location.reload();
                    } else {
                        alert(result.message || 'Failed to reactivate account.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred. Please try again.');
                });
            }
        }

        function applyFilter() {
            const filterValue = document.getElementById('statusFilter').value;
            const rows = document.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                if (filterValue === 'all') {
                    row.style.display = '';
                } else {
                    const activeCell = row.querySelector('td:nth-child(6)'); // Active column
                    const isActive = activeCell && activeCell.textContent.trim() === 'Yes';
                    
                    if (filterValue === 'active' && isActive) {
                        row.style.display = '';
                    } else if (filterValue === 'inactive' && !isActive) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
            
            // Update badge count
            const visibleRows = Array.from(rows).filter(row => row.style.display !== 'none');
            const badge = document.querySelector('.workflow-badge');
            if (badge) {
                badge.textContent = visibleRows.length;
            }
        }

        function updateDateTime() {
            const now = new Date();
            const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
            const timeOptions = { hour: '2-digit', minute: '2-digit', second: '2-digit' };
            
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', dateOptions);
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', timeOptions);
        }
        
        updateDateTime();
        setInterval(updateDateTime, 1000);

        function lastActiveDiff(iso) {
            if (!iso) return null;
            const last = new Date(iso);
            if (isNaN(last.getTime())) return null;
            return Math.max(0, Date.now() - last.getTime());
        }

        function formatLastActiveJS(ms) {
            if (ms === null) return 'Never active';
            const sec = Math.floor(ms / 1000);
            if (sec < 60) return 'Active just now';
            const min = Math.floor(sec / 60);
            if (min < 60) return 'Active ' + min + ' min' + (min > 1 ? 's' : '') + ' ago';
            const hr = Math.floor(min / 60);
            if (hr < 24) return 'Active ' + hr + ' hour' + (hr > 1 ? 's' : '') + ' ago';
            const day = Math.floor(hr / 24);
            if (day < 7) return 'Active ' + day + ' day' + (day > 1 ? 's' : '') + ' ago';
            const wk = Math.floor(day / 7);
            if (wk < 5) return 'Active ' + wk + ' week' + (wk > 1 ? 's' : '') + ' ago';
            const mo = Math.floor(day / 30);
            if (mo < 12) return 'Active ' + mo + ' month' + (mo > 1 ? 's' : '') + ' ago';
            const yr = Math.floor(day / 365);
            return 'Active ' + yr + ' year' + (yr > 1 ? 's' : '') + ' ago';
        }

        function lastActiveClass(ms) {
            if (ms === null) return 'activity-never';
            const hr = ms / 3600000;
            if (hr < 1) return 'activity-recent';
            if (hr < 24) return 'activity-moderate';
            return 'activity-idle';
        }

        function updateActivityIndicators() {
            document.querySelectorAll('[data-last-active]').forEach(function(el) {
                const ms = lastActiveDiff(el.getAttribute('data-last-active') || '');
                const textEl = el.querySelector('.activity-text');
                if (textEl) textEl.textContent = formatLastActiveJS(ms);
                el.classList.remove('activity-recent', 'activity-moderate', 'activity-idle', 'activity-never');
                el.classList.add(lastActiveClass(ms));
            });
        }

        function formatAbsoluteTitle(iso) {
            if (!iso) return 'No activity recorded';
            const d = new Date(iso);
            if (isNaN(d.getTime())) return 'No activity recorded';
            const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            const pad = n => (n < 10 ? '0' : '') + n;
            return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear() + ' ' +
                   pad(d.getHours()) + ':' + pad(d.getMinutes());
        }

        function refreshActivityData() {
            const formData = new FormData();
            formData.append('action', 'get_activity');
            fetch('', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (!data || !data.success || !data.activity) return;
                    const activity = data.activity;
                    document.querySelectorAll('[data-last-active]').forEach(function(el) {
                        const uid = el.getAttribute('data-user-id');
                        if (uid && Object.prototype.hasOwnProperty.call(activity, uid)) {
                            const iso = activity[uid] || '';
                            if (el.getAttribute('data-last-active') !== iso) {
                                el.setAttribute('data-last-active', iso);
                                el.title = formatAbsoluteTitle(iso);
                                const ms = lastActiveDiff(iso);
                                const textEl = el.querySelector('.activity-text');
                                if (textEl) textEl.textContent = formatLastActiveJS(ms);
                                el.classList.remove('activity-recent', 'activity-moderate', 'activity-idle', 'activity-never');
                                el.classList.add(lastActiveClass(ms));
                            }
                        }
                    });
                })
                .catch(function() {
                    // Silently ignore transient network failures; next poll will retry
                });
        }

        updateActivityIndicators();
        setInterval(updateActivityIndicators, 30000);
        refreshActivityData();
        setInterval(refreshActivityData, 30000);

        // Deactivate Account - Modal and AJAX
        let pendingDeactivateUserId = null;

        function confirmDeactivate(userId, userName) {
            pendingDeactivateUserId = userId;
            document.getElementById('deactivateModalBody').innerHTML =
                'Are you sure you want to deactivate the account for <strong>' + userName + '</strong>?';
            document.getElementById('deactivateModal').style.display = 'flex';
        }

        function closeDeactivateModal() {
            document.getElementById('deactivateModal').style.display = 'none';
            pendingDeactivateUserId = null;
        }

        function executeDeactivate() {
            if (!pendingDeactivateUserId) return;

            const btn = document.getElementById('deactivateConfirmBtn');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deactivating...';

            const formData = new FormData();
            formData.append('action', 'deactivate_user');
            formData.append('user_id', pendingDeactivateUserId);
            formData.append('remarks', 'Deactivated by admin from Manage Accounts (inactive users)');

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-slash"></i> Deactivate Account';
                if (result.success) {
                    closeDeactivateModal();
                    const row = document.getElementById('inactive-row-' + pendingDeactivateUserId);
                    if (row) {
                        row.style.transition = 'opacity 0.3s';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 300);
                    }
                } else {
                    alert(result.message || 'Failed to deactivate account.');
                }
            })
            .catch(error => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-user-slash"></i> Deactivate Account';
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
    </script>
    

</body>
</html>

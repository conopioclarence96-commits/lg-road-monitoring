<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
    error_reporting(0);
}

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);

session_start();
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

$session_timeout = 30 * 60;
lgu_enforce_idle_timeout($session_timeout, '../../login.php?timeout=1');

if ($conn->connect_error === null) {
    $check = $conn->query("SHOW COLUMNS FROM users LIKE 'approved_at'");
    if ($check && $check->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
    }
    $check2 = $conn->query("SHOW COLUMNS FROM users LIKE 'rejected_at'");
    if ($check2 && $check2->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN rejected_at TIMESTAMP NULL DEFAULT NULL AFTER approved_at");
    }
}

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

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $user_id = $_POST['user_id'] ?? 0;
    $remarks = $_POST['remarks'] ?? '';

    if ($action === 'approve' && $user_id > 0) {
        $stmt = $conn->prepare("UPDATE users SET is_active = 1, account_status = 'verified', approved_at = NOW() WHERE id = ?");
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to prepare approval query']);
            exit;
        }
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result ? $user_result->fetch_assoc() : null;
            if ($user_data) {
                log_audit_action($_SESSION['user_id'], 'Account Approved', "Approved account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            }
            echo json_encode(['success' => true, 'message' => 'Account approved successfully']);
            if ($user_stmt) $user_stmt->close();
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to approve account']);
        }
        $stmt->close();
        exit;

    } elseif ($action === 'reject' && $user_id > 0) {
        $stmt = $conn->prepare("UPDATE users SET is_active = 0, account_status = 'rejected', rejected_at = NOW() WHERE id = ?");
        if (!$stmt) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to prepare rejection query']);
            exit;
        }
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result ? $user_result->fetch_assoc() : null;
            if ($user_data) {
                log_audit_action($_SESSION['user_id'], 'Account Rejected', "Rejected account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            }
            echo json_encode(['success' => true, 'message' => 'Account rejected successfully']);
            if ($user_stmt) $user_stmt->close();
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to reject account']);
        }
        $stmt->close();
        exit;

    } elseif ($action === 'deactivate_user' && $user_id > 0) {
        $stmt = $conn->prepare("UPDATE users SET is_active = 0, account_status = 'deactivated' WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            log_audit_action($_SESSION['user_id'], 'Account Deactivated', "Deactivated account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            echo json_encode(['success' => true, 'message' => 'Account deactivated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to deactivate account']);
        }
        $stmt->close();
        $user_stmt->close();
        exit;

    } elseif ($action === 'activate_user' && $user_id > 0) {
        $stmt = $conn->prepare("UPDATE users SET is_active = 1, account_status = 'verified' WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $user_stmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ?");
            $user_stmt->bind_param("i", $user_id);
            $user_stmt->execute();
            $user_result = $user_stmt->get_result();
            $user_data = $user_result->fetch_assoc();
            log_audit_action($_SESSION['user_id'], 'Account Activated', "Activated account for {$user_data['full_name']} ({$user_data['email']}). Remarks: $remarks");
            echo json_encode(['success' => true, 'message' => 'Account activated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to activate account']);
        }
        $stmt->close();
        $user_stmt->close();
        exit;

    } elseif ($action === 'approve_change') {
        $request_id = intval($_POST['request_id'] ?? 0);
        $cr_user_id = intval($_POST['cr_user_id'] ?? 0);
        $new_full_name = sanitize_input($_POST['new_full_name'] ?? '');
        $new_email = sanitize_input($_POST['new_email'] ?? '');
        $new_address = sanitize_input($_POST['new_address'] ?? '');
        $new_civil_status = sanitize_input($_POST['new_civil_status'] ?? '');
        $new_birthday = sanitize_input($_POST['new_birthday'] ?? '');
        if ($new_birthday === '') {
            $new_birthday = null;
        }
        $new_phone_number = sanitize_input($_POST['new_phone_number'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $new_id_file = $_POST['new_id_file_path'] ?? '';
        $new_profile_picture = $_POST['new_profile_picture'] ?? '';
        $admin_notes = sanitize_input($_POST['admin_notes'] ?? '');
        $reviewer_id = (int)$_SESSION['user_id'];

        if ($request_id <= 0 || $cr_user_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);
            exit;
        }

        // Only pending requests can be approved. Processed rows stay in
        // change_requests with status approved/rejected for archive.php.
        $pending = fetch_one(
            "SELECT id, user_id, status FROM change_requests WHERE id = ? AND user_id = ? LIMIT 1",
            [$request_id, $cr_user_id],
            'ii'
        );
        if (!$pending) {
            echo json_encode(['success' => false, 'message' => 'Change request not found.']);
            exit;
        }
        if (strtolower((string)($pending['status'] ?? '')) !== 'pending') {
            echo json_encode([
                'success' => true,
                'message' => 'This change request was already processed and is no longer in Staff Change Requests.',
                'status' => (string)$pending['status'],
                'request_id' => $request_id,
                'archived' => true,
            ]);
            exit;
        }

        $conn->begin_transaction();
        try {
            $sql = "UPDATE users SET full_name = ?, email = ?, address = ?, civil_status = ?, birthday = ?, phone_number = ?";
            $params = [$new_full_name, $new_email, $new_address, $new_civil_status, $new_birthday, $new_phone_number];
            $types = "ssssss";

            if (!empty($new_password)) {
                $sql .= ", password = ?";
                $params[] = (str_starts_with($new_password, '$2y$') || str_starts_with($new_password, '$2a$') || str_starts_with($new_password, '$2b$'))
                    ? $new_password
                    : password_hash($new_password, PASSWORD_DEFAULT);
                $types .= "s";
            }
            if (!empty($new_id_file)) {
                $sql .= ", id_file_path = ?";
                $params[] = $new_id_file;
                $types .= "s";
            }
            if (!empty($new_profile_picture)) {
                $sql .= ", profile_picture = ?";
                $params[] = $new_profile_picture;
                $types .= "s";
            }
            $sql .= " WHERE id = ?";
            $params[] = $cr_user_id;
            $types .= "i";

            $stmt = $conn->prepare($sql);
            if (!$stmt) {
                throw new Exception('Failed to prepare user update.');
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                $err = $stmt->error ?: 'Failed to update user info.';
                $stmt->close();
                throw new Exception($err);
            }
            $stmt->close();

            $stmt2 = $conn->prepare(
                "UPDATE change_requests
                 SET status = 'approved', admin_notes = ?, reviewed_at = NOW(), reviewed_by = ?
                 WHERE id = ? AND status = 'pending'"
            );
            if (!$stmt2) {
                throw new Exception('Failed to prepare status update.');
            }
            $stmt2->bind_param("sii", $admin_notes, $reviewer_id, $request_id);
            if (!$stmt2->execute() || $stmt2->affected_rows < 1) {
                $stmt2->close();
                throw new Exception('Failed to finalize change request status.');
            }
            $stmt2->close();

            $conn->commit();
            log_audit_action($reviewer_id, 'Change Request Approved', "Approved change request #$request_id for user #$cr_user_id");
            echo json_encode([
                'success' => true,
                'message' => 'Change request approved. It has been moved to Archive.',
                'status' => 'approved',
                'request_id' => $request_id,
                'archived' => true,
            ]);
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            error_log('approve_change error: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;

    } elseif ($action === 'reject_change') {
        $request_id = intval($_POST['request_id'] ?? 0);
        $admin_notes = sanitize_input($_POST['admin_notes'] ?? '');
        $reviewer_id = (int)$_SESSION['user_id'];

        if ($request_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid request ID.']);
            exit;
        }

        $pending = fetch_one(
            "SELECT id, status FROM change_requests WHERE id = ? LIMIT 1",
            [$request_id],
            'i'
        );
        if (!$pending) {
            echo json_encode(['success' => false, 'message' => 'Change request not found.']);
            exit;
        }
        if (strtolower((string)($pending['status'] ?? '')) !== 'pending') {
            echo json_encode([
                'success' => true,
                'message' => 'This change request was already processed and is no longer in Staff Change Requests.',
                'status' => (string)$pending['status'],
                'request_id' => $request_id,
                'archived' => true,
            ]);
            exit;
        }

        $stmt = $conn->prepare(
            "UPDATE change_requests
             SET status = 'rejected', admin_notes = ?, reviewed_at = NOW(), reviewed_by = ?
             WHERE id = ? AND status = 'pending'"
        );
        if (!$stmt) {
            echo json_encode(['success' => false, 'message' => 'Failed to prepare rejection.']);
            exit;
        }
        $stmt->bind_param("sii", $admin_notes, $reviewer_id, $request_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            log_audit_action($reviewer_id, 'Change Request Rejected', "Rejected change request #$request_id");
            echo json_encode([
                'success' => true,
                'message' => 'Change request rejected. It has been moved to Archive.',
                'status' => 'rejected',
                'request_id' => $request_id,
                'archived' => true,
            ]);
        } else {
            $stmt->close();
            echo json_encode(['success' => false, 'message' => 'Failed to reject change request.']);
        }
        exit;
    }
}

$stmt = $conn->prepare("
    SELECT id, username, email, full_name, role, department, address, birthday, civil_status, phone_number, is_active, created_at, updated_at, approved_at, rejected_at, id_file_path, last_activity 
    FROM users 
    WHERE role IN ('lgu_staff', 'citizen', 'road_ops_supervisor', 'trans_ops_supervisor', 'road_monitoring_officer', 'trans_monitoring_officer') AND account_status = 'pending'
    ORDER BY created_at DESC
");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$role_labels = [
    'lgu_staff' => 'LGU Staff',
    'citizen' => 'Citizen',
    'road_ops_supervisor' => 'Road Operations Supervisor',
    'trans_ops_supervisor' => 'Transportation Operations Supervisor',
    'road_monitoring_officer' => 'Road Monitoring Officer',
    'trans_monitoring_officer' => 'Transportation Monitoring Officer',
];

$change_requests = [];
try {
    // Active Staff Change Requests panel: pending only.
    // Approved/rejected rows remain in change_requests for archive.php.
    $cr_stmt = $conn->prepare("
        SELECT cr.*, u.full_name as user_name, u.email as user_email,
               u.department as user_department, u.address as user_address,
               u.civil_status as user_civil_status, u.birthday as user_birthday,
               u.phone_number as user_phone_number,
               u.id_file_path as user_id_file
        FROM change_requests cr
        LEFT JOIN users u ON cr.user_id = u.id
        WHERE LOWER(TRIM(cr.status)) = 'pending'
        ORDER BY cr.created_at DESC
    ");
    $cr_stmt->execute();
    $change_requests = $cr_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $cr_stmt->close();
} catch (Exception $e) {
    error_log("Change requests query error: " . $e->getMessage());
    $change_requests = [];
}

$stats = [];
try {
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'pending'");
    $stmt->execute();
    $stats['pending_users'] = $stmt->get_result()->fetch_assoc()['count'];

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'verified' AND is_active = 1");
    $stmt->execute();
    $stats['approved_users'] = $stmt->get_result()->fetch_assoc()['count'];

    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE account_status = 'deactivated'");
    $stmt->execute();
    $stats['deactivated_users'] = $stmt->get_result()->fetch_assoc()['count'];
} catch (Exception $e) {
    $stats = ['pending_users' => 0, 'approved_users' => 0, 'deactivated_users' => 0];
}

$pending_changes_count = count($change_requests);

// Deep-link focus: ?cr_id= from a notification "Review" button. The backend
// confirms the request is still listed (pending) so the frontend can scroll to
// it and highlight it — or show a friendly message when it no longer exists.
$focus_cr_id = isset($_GET['cr_id']) ? (int)$_GET['cr_id'] : 0;
$focus_target = ['found' => false, 'id' => $focus_cr_id];
if ($focus_cr_id > 0) {
    foreach ($change_requests as $cr) {
        if ((int)$cr['id'] === $focus_cr_id) {
            $focus_target['found'] = true;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Account Approvals - LGU Road Monitoring</title>
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

        .main-content.approvals-dash {
            margin-left: 250px;
            padding: 28px 32px;
            max-width: 100%;
            overflow-x: hidden;
            position: relative;
            z-index: 1;
        }

        /* Dashboard header */
        .approvals-dash .dashboard-header {
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
        .approvals-dash .welcome-text h1 {
            font-size: 22px; font-weight: 700; color: var(--text-primary);
            margin-bottom: 4px; display: flex; align-items: center; gap: 12px;
        }
        .approvals-dash .header-icon {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--color-primary-bg);
            color: var(--color-primary); font-size: 16px;
        }
        .approvals-dash .welcome-text p { color: var(--text-secondary); font-size: 13px; }
        .approvals-dash .date-time { color: var(--text-secondary); font-size: 13px; }
        .approvals-dash .dt-chip {
            display: flex; align-items: center; gap: 10px;
            background: var(--color-primary-bg);
            border: 1px solid var(--border-default);
            border-radius: 14px; padding: 10px 14px;
        }
        .approvals-dash .dt-chip i {
            color: var(--color-primary); font-size: 16px;
            width: 28px; height: 28px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: #f4f7fb;
        }
        .approvals-dash #currentDate { font-weight: 600; color: var(--text-primary); font-size: 13px; }
        .approvals-dash #currentTime { color: var(--text-secondary); font-size: 12px; margin-top: 1px; }

        .approvals-dash .sync-indicator {
            display: flex; align-items: center; gap: 6px;
            font-size: 11px; color: var(--text-secondary);
            margin-top: 6px;
        }
        .approvals-dash .sync-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--color-success); }
        .approvals-dash .sync-dot.syncing { animation: syncPulse 0.8s ease-in-out infinite; background: var(--color-warning); }
        @keyframes syncPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        /* Summary cards */
        .summary-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        .summary-card {
            background: #f4f7fb; border-radius: 14px; padding: 18px 18px 16px;
            border: 1px solid #d5dce8; position: relative; overflow: hidden;
            box-shadow: var(--shadow-card);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .summary-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; }
        .summary-card.amber::before { background: #d97706; }
        .summary-card.emerald::before { background: #059669; }
        .summary-card.rose::before { background: #e11d48; }
        .summary-card.violet::before { background: #3f3658; }
        .summary-card.amber,
        .summary-card.emerald,
        .summary-card.rose,
        .summary-card.violet { background: #f4f7fb; }
        .summary-card .card-top { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .summary-card .card-icon {
            width: 42px; height: 42px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center; font-size: 16px;
        }
        .summary-card.amber .card-icon { background: rgba(217,119,6,0.18); color: #b45309; }
        .summary-card.emerald .card-icon { background: rgba(5,150,105,0.18); color: #047857; }
        .summary-card.rose .card-icon { background: rgba(225,29,72,0.16); color: #be123c; }
        .summary-card.violet .card-icon { background: rgba(63,54,88,0.20); color: #3f3658; }
        .summary-card .card-value { font-size: 28px; font-weight: 700; color: var(--text-primary); letter-spacing: -0.03em; }
        .summary-card .card-label { font-size: 12px; color: var(--text-secondary); font-weight: 600; margin-top: 2px; }

        /* Workflow cards */
        .workflow-container { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 24px; }
        .workflow-card {
            background: #f4f7fb; border-radius: 14px; padding: 20px;
            border: 1px solid #d5dce8; box-shadow: var(--shadow-card);
            position: relative; overflow: hidden;
        }
        .workflow-card.panel-approvals::after { background: #d97706; }
        .workflow-card.panel-changes::after { background: #4c1d95; }
        .workflow-card::after {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
        }
        .workflow-header {
            display: flex; justify-content: space-between; align-items: center; gap: 10px;
            margin: -20px -20px 16px; padding: 14px 18px 14px 20px;
            border-bottom: 1px solid var(--border-light);
            background: var(--bg-hover);
        }
        .workflow-card.panel-approvals .workflow-header { background: rgba(217,119,6,0.10); }
        .workflow-card.panel-changes .workflow-header { background: rgba(76,29,149,0.12); }
        .workflow-title {
            font-size: 14px; font-weight: 600; color: var(--text-primary);
            display: flex; align-items: center; gap: 10px;
        }
        .workflow-card.panel-approvals .workflow-title { color: #b45309; }
        .workflow-card.panel-changes .workflow-title { color: #4c1d95; }
        .workflow-title .title-icon {
            width: 30px; height: 30px; border-radius: 9px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0;
        }
        .workflow-card.panel-approvals .title-icon { background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; }
        .workflow-card.panel-changes .title-icon { background: linear-gradient(135deg, #7c3aed, #4c1d95); color: #fff; }
        .workflow-badge {
            background: #d97706; color: #fff; padding: 2px 10px;
            border-radius: 12px; font-size: 11px; font-weight: 600;
        }
        .workflow-card.panel-changes .workflow-badge { background: #4c1d95; }
        .workflow-content { max-height: 600px; overflow-y: auto; padding-right: 4px; }
        .workflow-content::-webkit-scrollbar { width: 6px; }
        .workflow-content::-webkit-scrollbar-track { background: var(--border-light); border-radius: 3px; }
        .workflow-content::-webkit-scrollbar-thumb { background: var(--color-primary-bg); border-radius: 3px; }

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

        .action-buttons { display: flex; gap: 8px; }
        .btn-sm {
            padding: 6px 11px; font-size: 11px; border: none; border-radius: 8px;
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            font-weight: 600; text-decoration: none; white-space: nowrap;
            transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
        }
        .btn-sm:hover { transform: translateY(-1px); }
        .btn-approve { background: linear-gradient(135deg, #10b981, #059669); color: #fff; box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3); }
        .btn-approve:hover { filter: brightness(1.06); color: #fff; }
        .btn-reject { background: linear-gradient(135deg, #f43f5e, #e11d48); color: #fff; box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3); }
        .btn-reject:hover { filter: brightness(1.06); color: #fff; }
        .btn-manage {
            background: linear-gradient(135deg, #3762c8, #1e3c72);
            color: #fff; box-shadow: 0 4px 12px rgba(30, 60, 114, 0.3);
        }
        .btn-manage:hover { filter: brightness(1.06); color: #fff; }
        .btn-view { background: linear-gradient(135deg, #3762c8, #1e3c72); color: #fff; }

        .cr-row-focus {
            animation: crFocusPulse 1.2s ease-in-out 4;
            box-shadow: 0 0 0 3px var(--color-primary), 0 8px 32px rgba(55, 98, 200, 0.35);
            border-left: 4px solid var(--color-primary);
            background: var(--color-primary-bg);
        }
        @keyframes crFocusPulse {
            0%, 100% { background-color: var(--color-primary-bg); }
            50% { background-color: rgba(55, 98, 200, 0.28); }
        }
        body.dark-mode .cr-row-focus {
            box-shadow: 0 0 0 3px #6a9bff, 0 8px 32px rgba(106, 155, 255, 0.35);
            border-left: 4px solid #6a9bff;
            background: rgba(106, 155, 255, 0.14);
        }

        /* Change request review modal */
        .cr-modal-section { background: var(--bg-hover); border-radius: 10px; padding: 14px; margin-bottom: 14px; border: 1px solid var(--border-light); }
        .cr-modal-section.cr-requested { background: var(--color-primary-bg); border-color: var(--color-primary-bg); }
        .cr-modal-section.cr-requested .cr-modal-section-title { color: var(--color-primary); }
        .cr-modal-section-title { font-size: 11px; font-weight: 700; color: var(--text-secondary); margin-bottom: 12px; display: flex; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: 0.5px; }
        .cr-modal-section-title i { font-size: 14px; }
        .cr-compare-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .cr-compare-item { display: flex; flex-direction: column; gap: 4px; }
        .cr-compare-label { font-size: 10px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; }
        .cr-compare-old { font-size: 12px; color: var(--text-secondary); padding: 8px 12px; background: var(--bg-input-readonly); border-radius: 8px; border-left: 3px solid var(--border-default); }
        .cr-compare-new { font-size: 12px; color: var(--text-primary); padding: 8px 12px; background: var(--color-primary-bg); border-radius: 8px; border-left: 3px solid var(--color-primary); font-weight: 500; }
        .cr-compare-new.no-change { color: var(--text-muted); background: var(--bg-input-readonly); border-left-color: var(--border-default); font-weight: 400; }
        .cr-media-preview { display: flex; align-items: center; gap: 12px; padding: 8px 12px; background: var(--bg-input-readonly); border-radius: 8px; border-left: 3px solid var(--color-primary); }
        .cr-media-preview img { width: 60px; height: 60px; border-radius: 8px; object-fit: cover; border: 2px solid var(--border-light); }
        .cr-media-preview .cr-media-label { font-size: 12px; color: var(--text-secondary); }
        .cr-staff-header { display: flex; align-items: center; gap: 14px; padding-bottom: 14px; border-bottom: 1px solid var(--border-light); margin-bottom: 16px; }
        .cr-staff-avatar { width: 44px; height: 44px; border-radius: 12px; background: linear-gradient(135deg, var(--color-primary), var(--color-primary-dark)); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; font-weight: 600; flex-shrink: 0; box-shadow: 0 4px 12px rgba(55,98,200,0.3); }
        .cr-staff-info { flex: 1; }
        .cr-staff-name { font-size: 15px; font-weight: 700; color: var(--text-primary); }
        .cr-staff-date { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
        .cr-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border-light); }
        .cr-btn { padding: 8px 16px; border: none; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all 0.2s; display: flex; align-items: center; gap: 8px; color: #fff; }
        .cr-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .cr-btn-reject { background: linear-gradient(135deg, #f43f5e, #e11d48); }
        .cr-btn-approve { background: linear-gradient(135deg, #10b981, #059669); }
        .cr-btn-close { background: var(--text-muted); color: #fff; }
        .cr-btn-close:hover { filter: brightness(1.1); }

        /* Enhanced user modal */
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: var(--bg-overlay); align-items: center; justify-content: center; }
        .modal-content { background-color: #f4f7fb; padding: 28px; border-radius: 14px; width: 90%; max-width: 620px; border: 1px solid #d5dce8; box-shadow: var(--shadow-lg); color: var(--text-primary); position: relative; top: auto; left: auto; transform: none; margin: 0 auto; }
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
        .modal-content.user-modal-content .user-modal-header .modal-title {
            display: flex; align-items: center; gap: 10px; font-size: 15px;
        }
        .modal-content.user-modal-content .user-modal-header .modal-title-icon {
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
        .modal-content.user-modal-content .user-modal-actions {
            display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;
            padding: 14px 20px; border-top: 1px solid var(--border-light);
            background: var(--bg-hover); flex: 0 0 auto;
        }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-title { margin: 0; font-size: 16px; font-weight: 600; color: var(--text-primary); }
        .close { font-size: 24px; cursor: pointer; color: var(--text-muted); }
        .close:hover { color: var(--color-danger); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; color: var(--text-secondary); font-size: 12px; }
        .modal-content input,
        .modal-content select,
        .modal-content textarea { width: 100%; padding: 9px 12px; border: 1px solid var(--border-default); border-radius: 8px; background: #f4f7fb; box-sizing: border-box; color: var(--text-primary); font-size: 13px; }
        .modal-content input:disabled,
        .modal-content select:disabled,
        .modal-content textarea:disabled { background: var(--bg-input-readonly); color: var(--text-secondary); }
        .modal-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .modal-form-grid .form-group { margin-bottom: 0; }

        .profile-summary {
            display: flex; align-items: center; gap: 14px;
            padding: 12px 14px; margin-bottom: 14px;
            background: #eef3fa; border: 1px solid var(--border-light);
            border-radius: 12px;
        }
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
        .profile-badge-pending { background: var(--color-warning-bg); color: var(--color-warning-text); }
        .profile-badge-locked { background: var(--color-danger-bg); color: var(--color-danger-text); }
        .profile-badge-deactivated { background: var(--badge-cancelled-bg); color: var(--badge-cancelled-text); }
        .profile-badge-rejected { background: var(--badge-cancelled-bg); color: var(--badge-cancelled-text); }

        .modal-section-title {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: var(--text-muted);
            margin: 14px 0 10px; display: flex; align-items: center; gap: 8px;
        }
        .modal-section-title:first-child { margin-top: 0; }
        .modal-section-title::after { content: ''; flex: 1; height: 1px; background: var(--border-light); }

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
        .id-file-thumb .id-file-empty { font-size: 22px; color: var(--text-muted); opacity: 0.6; }
        .id-file-meta { flex: 1; min-width: 0; }
        .id-file-label { font-size: 12px; font-weight: 600; color: var(--text-primary); margin-bottom: 2px; }
        .id-file-sub { font-size: 11px; color: var(--text-muted); }

        #changeRequestModal .modal-content.cr-modal-content {
            max-width: 560px;
            width: 92vw;
            max-height: calc(100vh - 48px);
            padding: 0;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        #changeRequestModal .cr-modal-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 20px; border-bottom: 1px solid var(--border-light);
            background: var(--bg-hover); flex: 0 0 auto;
        }
        #changeRequestModal .cr-modal-header .modal-title {
            display: flex; align-items: center; gap: 10px; font-size: 15px;
        }
        #changeRequestModal .cr-modal-header .modal-title-icon {
            width: 32px; height: 32px; border-radius: 9px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--color-primary-bg); color: var(--color-primary); font-size: 13px;
        }
        #changeRequestModal .cr-modal-body {
            padding: 16px 20px;
            flex: 1 1 auto;
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
        }
        #changeRequestModal .cr-modal-footer {
            display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap;
            padding: 14px 20px; border-top: 1px solid var(--border-light);
            background: var(--bg-hover); flex: 0 0 auto;
        }

        /* Dark mode */
        body.dark-mode .approvals-dash .dashboard-header,
        body.dark-mode .approvals-dash .summary-card,
        body.dark-mode .approvals-dash .workflow-card,
        body.dark-mode .approvals-dash .table-container,
        body.dark-mode .approvals-dash .modal-content {
            background: #1c2432 !important;
            border-color: rgba(147, 179, 224, 0.22) !important;
        }
        body.dark-mode .approvals-dash .workflow-header { background: rgba(255,255,255,0.03) !important; border-color: var(--border-default) !important; }
        body.dark-mode .approvals-dash .panel-approvals .workflow-header { background: rgba(217,119,6,0.14) !important; }
        body.dark-mode .approvals-dash .panel-changes .workflow-header { background: rgba(124,58,237,0.16) !important; }
        body.dark-mode .approvals-dash .panel-approvals .workflow-title { color: #fbbf24 !important; }
        body.dark-mode .approvals-dash .panel-changes .workflow-title { color: #c4b5fd !important; }
        body.dark-mode .approvals-dash .dt-chip { background: var(--color-primary-bg); border-color: var(--border-default); }
        body.dark-mode .approvals-dash .dt-chip i { background: #1c2432; }
        body.dark-mode .approvals-dash .card-value,
        body.dark-mode .approvals-dash .workflow-title,
        body.dark-mode .approvals-dash th,
        body.dark-mode .approvals-dash td { color: var(--text-primary); }
        body.dark-mode .approvals-dash .card-label,
        body.dark-mode .approvals-dash .welcome-text p { color: var(--text-secondary) !important; }
        body.dark-mode .approvals-dash .profile-summary { background: rgba(255,255,255,0.04) !important; border-color: var(--border-default) !important; }
        body.dark-mode .approvals-dash .profile-badge-role { background: var(--color-primary-bg); color: var(--color-primary); }
        body.dark-mode .approvals-dash .profile-badge-active { background: var(--color-success-bg); color: var(--color-success-text); }
        body.dark-mode .approvals-dash .profile-badge-inactive,
        body.dark-mode .approvals-dash .profile-badge-pending { background: var(--color-warning-bg); color: var(--color-warning-text); }
        body.dark-mode .approvals-dash .profile-badge-locked { background: var(--color-danger-bg); color: var(--color-danger-text); }
        body.dark-mode .approvals-dash .modal-content { background: #1c2432 !important; border-color: rgba(147, 179, 224, 0.22) !important; }
        body.dark-mode .approvals-dash .modal-content input,
        body.dark-mode .approvals-dash .modal-content select,
        body.dark-mode .approvals-dash .modal-content textarea { background: #1c2432 !important; border-color: var(--border-default) !important; color: var(--text-primary) !important; }
        body.dark-mode .approvals-dash .modal-content input:disabled,
        body.dark-mode .approvals-dash .modal-content select:disabled,
        body.dark-mode .approvals-dash .modal-content textarea:disabled { background: var(--bg-input-readonly) !important; }
        body.dark-mode .approvals-dash .cr-modal-section { background: rgba(255,255,255,0.03) !important; border-color: var(--border-default) !important; }
        body.dark-mode .approvals-dash .cr-modal-section.cr-requested { background: var(--color-primary-bg) !important; border-color: var(--border-default) !important; }
        body.dark-mode .approvals-dash .cr-modal-section.cr-requested .cr-modal-section-title { color: #93c5fd; }
        body.dark-mode .approvals-dash .cr-modal-section-title { color: var(--text-secondary); }
        body.dark-mode .approvals-dash .cr-compare-old { background: var(--bg-input-readonly) !important; color: var(--text-secondary); }
        body.dark-mode .approvals-dash .cr-compare-new { background: var(--color-primary-bg) !important; color: var(--text-primary); }
        body.dark-mode .approvals-dash .cr-compare-new.no-change { color: var(--text-muted); background: var(--bg-input-readonly) !important; }
        body.dark-mode .approvals-dash .cr-media-preview { background: var(--bg-input-readonly) !important; }
        body.dark-mode .approvals-dash .cr-staff-header { border-color: var(--border-default); }
        body.dark-mode .approvals-dash .cr-staff-name { color: var(--text-primary); }
        body.dark-mode .approvals-dash .cr-actions { border-color: var(--border-default); }
        body.dark-mode .approvals-dash .cr-btn-close { background: var(--text-muted); color: #fff; }

        @media (max-width: 1400px) {
            .summary-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .main-content.approvals-dash { margin-left: 0; padding: 16px; }
            .summary-row { grid-template-columns: repeat(2, 1fr); }
            .approvals-dash .dashboard-header { flex-direction: column; align-items: flex-start; }
            .approvals-dash .modal-content { max-width: 96vw; }
            .modal-form-grid { grid-template-columns: 1fr; }
            .cr-compare-grid { grid-template-columns: 1fr; }
            .modal-content.user-modal-content {
                max-height: calc(100vh - 24px);
                width: 96vw;
            }
            .modal-content.user-modal-content .user-modal-body { padding: 12px 16px; }
            .modal-content.user-modal-content .user-modal-header { padding: 12px 16px; }
            .modal-content.user-modal-content .user-modal-actions { padding: 12px 16px; }
            #changeRequestModal .cr-modal-body { padding: 12px 16px; }
            #changeRequestModal .cr-modal-header,
            #changeRequestModal .cr-modal-footer { padding: 12px 16px; }
        }
        @media (max-width: 480px) {
            /* Very narrow screens stay 2x2 (overrides the generic
               stack-to-one-column rule) */
            .summary-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
    </style>
</head>
<body class="<?php echo !empty($_SESSION['darkmode']) ? 'dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content approvals-dash">
        <div class="dashboard-header">
            <div class="welcome-text">
                <h1>
                    <span class="header-icon"><i class="fas fa-check-double"></i></span>
                    Account Approvals
                </h1>
                <p>Review pending user registrations and staff change requests</p>
                <div class="sync-indicator" id="syncIndicator">
                    <div class="sync-dot" id="syncDot"></div>
                    <span id="syncText">Auto-sync on</span>
                </div>
            </div>
            <div class="dt-chip">
                <i class="fas fa-calendar-day"></i>
                <div>
                    <div id="currentDate"></div>
                    <div id="currentTime"></div>
                </div>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <div class="summary-row">
            <div class="summary-card amber">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-user-clock"></i></div>
                </div>
                <div class="card-value" id="statPendingUsers"><?php echo $stats['pending_users']; ?></div>
                <div class="card-label">Pending Users</div>
            </div>
            <div class="summary-card emerald">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-user-check"></i></div>
                </div>
                <div class="card-value" id="statApprovedUsers"><?php echo $stats['approved_users']; ?></div>
                <div class="card-label">Approved Users</div>
            </div>
            <div class="summary-card rose">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-user-slash"></i></div>
                </div>
                <div class="card-value" id="statDeactivated"><?php echo $stats['deactivated_users']; ?></div>
                <div class="card-label">Deactivated</div>
            </div>
            <div class="summary-card violet">
                <div class="card-top">
                    <div class="card-icon"><i class="fas fa-user-edit"></i></div>
                </div>
                <div class="card-value" id="statChangeRequests"><?php echo $pending_changes_count; ?></div>
                <div class="card-label">Change Requests</div>
            </div>
        </div>

        <div class="workflow-container">
            <div class="workflow-card panel-approvals">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <span class="title-icon"><i class="fas fa-user-clock"></i></span>
                        <span>Pending Account Approvals</span>
                    </h3>
                    <span class="workflow-badge" id="pendingUsersBadge"><?php echo count($users); ?></span>
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
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="pendingUsersBody">
                                <?php if (empty($users)): ?>
                                    <tr><td colspan="6" style="text-align:center;" class="t-text-secondary">No pending users</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $user): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                                            <td><?php echo htmlspecialchars($role_labels[$user['role']] ?? $user['role']); ?></td>
                                            <td><?php echo htmlspecialchars($user['department'] ?? 'N/A'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-sm btn-manage" onclick="showUserModal(<?php echo $user['id']; ?>)">Manage</button>
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

            <div class="workflow-card panel-changes">
                <div class="workflow-header">
                    <h3 class="workflow-title">
                        <span class="title-icon"><i class="fas fa-user-edit"></i></span>
                        <span>Staff Change Requests</span>
                    </h3>
                    <span class="workflow-badge" id="changeRequestsBadge"><?php echo $pending_changes_count; ?></span>
                </div>
                <div class="workflow-content">
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Staff</th>
                                    <th>Current Info</th>
                                    <th>Requested Changes</th>
                                    <th>Reason</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="changeRequestsBody">
                                <?php if (empty($change_requests)): ?>
                                    <tr><td colspan="6" style="text-align:center;" class="t-text-secondary">No pending change requests</td></tr>
                                <?php else: ?>
                                    <?php foreach ($change_requests as $cr):
                                        $req_data = json_decode($cr['requested_data'], true);
                                    ?>
                                        <?php
                                            $fields = ['full_name', 'email', 'address', 'civil_status', 'birthday', 'phone_number'];
                                            $current_map = [
                                                'full_name' => $cr['user_name'],
                                                'email' => $cr['user_email'],
                                                'address' => $cr['user_address'],
                                                'civil_status' => $cr['user_civil_status'],
                                                'birthday' => $cr['user_birthday'],
                                                'phone_number' => $cr['user_phone_number'],
                                            ];
                                            $changed_fields = [];
                                            foreach ($fields as $f) {
                                                if (isset($req_data[$f]) && $req_data[$f] !== '' && $req_data[$f] !== ($current_map[$f] ?? '')) {
                                                    $changed_fields[] = $f;
                                                }
                                            }
                                        ?>
                                        <tr data-id="<?php echo (int)$cr['id']; ?>">
                                            <td><?php echo htmlspecialchars($cr['user_name']); ?></td>
                                            <td>
                                                <small class="t-text-secondary">
                                                <?php if (empty($changed_fields) && empty($req_data['new_password']) && empty($req_data['new_password_hash']) && empty($req_data['profile_picture']) && empty($req_data['id_file_path'])): ?>
                                                    No changes
                                                <?php else: ?>
                                                    <?php foreach ($changed_fields as $f): ?>
                                                        <?php $label = ucfirst(str_replace('_', ' ', $f)); ?>
                                                        <strong><?php echo $label; ?>:</strong> <?php echo htmlspecialchars($current_map[$f] ?? 'N/A'); ?><br>
                                                    <?php endforeach; ?>
                                                    <?php if (!empty($req_data['new_password']) || !empty($req_data['new_password_hash'])): ?>
                                                        <span class="t-text-warning"><i class="fas fa-key"></i> Current password</span><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($req_data['profile_picture'])): ?>
                                                        <span class="t-text-purple"><i class="fas fa-user-circle"></i> Current profile picture</span><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($req_data['id_file_path'])): ?>
                                                        <span class="t-text-success"><i class="fas fa-id-card"></i> Current ID photo</span><br>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <small class="t-text-primary">
                                                <?php if (empty($changed_fields) && empty($req_data['new_password']) && empty($req_data['new_password_hash']) && empty($req_data['profile_picture']) && empty($req_data['id_file_path'])): ?>
                                                    No changes
                                                <?php else: ?>
                                                    <?php foreach ($changed_fields as $f): ?>
                                                        <?php $label = ucfirst(str_replace('_', ' ', $f)); ?>
                                                        <strong><?php echo $label; ?>:</strong> <?php echo htmlspecialchars($req_data[$f]); ?><br>
                                                    <?php endforeach; ?>
                                                    <?php if (!empty($req_data['new_password']) || !empty($req_data['new_password_hash'])): ?>
                                                        <span class="t-text-warning" style="font-weight:600;"><i class="fas fa-key"></i> New password requested</span><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($req_data['profile_picture'])): ?>
                                                        <span class="t-text-purple" style="font-weight:600;"><i class="fas fa-user-circle"></i> New profile picture</span><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($req_data['id_file_path'])): ?>
                                                        <span class="t-text-success" style="font-weight:600;"><i class="fas fa-id-card"></i> New ID photo</span><br>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                </small>
                                            </td>
                                            <td><small><?php echo htmlspecialchars($cr['reason'] ?? 'N/A'); ?></small></td>
                                            <td><small><?php echo date('M d, Y', strtotime($cr['created_at'])); ?></small></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-sm btn-approve" onclick="showChangeRequestModal(<?php echo $cr['id']; ?>, <?php echo $cr['user_id']; ?>)">Review</button>
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
        </div>
    </div>

    <!-- User Management Modal -->
    <div id="userModal" class="modal">
        <div class="modal-content user-modal-content">
            <div class="user-modal-header">
                <h2 class="modal-title">
                    <span class="modal-title-icon"><i class="fas fa-user-check"></i></span>
                    Review Account
                </h2>
                <span class="close" onclick="closeUserModal()">&times;</span>
            </div>
            <div class="user-modal-body">
                <div class="profile-summary">
                    <div class="profile-avatar" id="modalProfileAvatar">?</div>
                    <div class="profile-info">
                        <div class="profile-name" id="modalProfileName">--</div>
                        <div class="profile-email" id="modalProfileEmail">--</div>
                        <div class="profile-badges">
                            <span class="profile-badge profile-badge-role" id="modalProfileRole"><i class="fas fa-user-tag"></i> --</span>
                            <span class="profile-badge profile-badge-pending" id="modalProfileStatus"><i class="fas fa-clock"></i> Pending</span>
                        </div>
                    </div>
                </div>

                <div class="modal-section-title"><i class="fas fa-user"></i> Basic Information</div>
                <div class="modal-form-grid">
                    <div class="form-group"><label>Full Name</label><input type="text" id="modalFullName" disabled></div>
                    <div class="form-group"><label>Email</label><input type="email" id="modalEmail" disabled></div>
                    <div class="form-group"><label>Role</label><input type="text" id="modalRole" disabled></div>
                    <div class="form-group"><label>Department</label><input type="text" id="modalDepartment" disabled></div>
                    <div class="form-group"><label>Contact Number</label><input type="text" id="modalPhoneNumber" disabled></div>
                    <div class="form-group"><label>Birthday</label><input type="text" id="modalBirthday" disabled></div>
                    <div class="form-group"><label>Civil Status</label><input type="text" id="modalCivilStatus" disabled></div>
                    <div class="form-group"><label>Address</label><input type="text" id="modalAddress" disabled></div>
                </div>

                <div class="modal-section-title"><i class="fas fa-shield-alt"></i> Account Information</div>
                <div class="modal-form-grid">
                    <div class="form-group"><label>Account Status</label><input type="text" id="modalAccountStatus" disabled></div>
                    <div class="form-group"><label>Created At</label><input type="text" id="modalCreatedAt" disabled></div>
                    <div class="form-group"><label>Approved At</label><input type="text" id="modalApprovedAt" disabled></div>
                    <div class="form-group"><label>Last Active</label><input type="text" id="modalLastActive" disabled></div>
                </div>

                <div class="modal-section-title"><i class="fas fa-id-card"></i> Government ID</div>
                <div class="id-file-block">
                    <div class="id-file-thumb">
                        <img id="modalIdFile" src="" alt="ID File">
                        <span class="id-file-empty" id="modalIdFileNone"><i class="fas fa-id-card"></i></span>
                    </div>
                    <div class="id-file-meta">
                        <div class="id-file-label" id="modalIdFileLabel">No ID file uploaded</div>
                        <div class="id-file-sub">Government-issued identification</div>
                    </div>
                </div>
            </div>
            <div class="user-modal-actions">
                <button type="button" class="btn-sm btn-reject" onclick="rejectUser()"><i class="fas fa-times"></i> Reject</button>
                <button type="button" class="btn-sm btn-approve" onclick="approveUser()"><i class="fas fa-check"></i> Approve</button>
                <button type="button" class="btn-sm btn-manage" onclick="closeUserModal()"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>

    <!-- Change Request Review Modal (read-only review) -->
    <div id="changeRequestModal" class="modal">
        <div class="modal-content cr-modal-content">
            <div class="cr-modal-header">
                <h2 class="modal-title">
                    <span class="modal-title-icon"><i class="fas fa-clipboard-check"></i></span>
                    Review Change Request
                </h2>
                <span class="close" onclick="closeChangeRequestModal()">&times;</span>
            </div>

            <div class="cr-modal-body">
                <div class="cr-staff-header" id="crStaffHeader">
                    <div class="cr-staff-avatar" id="crStaffAvatar">S</div>
                    <div class="cr-staff-info">
                        <div class="cr-staff-name" id="crStaffName">Staff Name</div>
                        <div class="cr-staff-date" id="crStaffDate">Submitted on --</div>
                    </div>
                </div>

                <form id="changeRequestForm">
                    <input type="hidden" id="crAction" name="action">
                    <input type="hidden" id="crRequestId" name="request_id">
                    <input type="hidden" id="crUserId" name="cr_user_id">
                    <input type="hidden" id="crAdminUserId" name="user_id" value="<?php echo $_SESSION['user_id']; ?>">
                    <input type="hidden" id="crFullName" name="new_full_name">
                    <input type="hidden" id="crIdFilePath" name="new_id_file_path">
                    <input type="hidden" id="crProfilePicture" name="new_profile_picture">
                    <input type="hidden" id="crEmail" name="new_email">
                    <input type="hidden" id="crAddress" name="new_address">
                    <input type="hidden" id="crCivilStatus" name="new_civil_status">
                    <input type="hidden" id="crBirthday" name="new_birthday">
                    <input type="hidden" id="crPhoneNumber" name="new_phone_number">
                    <input type="hidden" id="crPassword" name="new_password">

                    <div class="cr-modal-section" id="crCurrentSection">
                        <div class="cr-modal-section-title"><i class="fas fa-user"></i> Current Information</div>
                        <div class="cr-compare-grid" id="crCurrentGrid"></div>
                    </div>

                    <div class="cr-modal-section cr-requested">
                        <div class="cr-modal-section-title"><i class="fas fa-pen"></i> Requested Changes</div>
                        <div class="cr-compare-grid">
                            <div class="cr-compare-item">
                                <span class="cr-compare-label">Full Name</span>
                                <div class="cr-compare-new" id="crFullNameDisplay">--</div>
                            </div>
                            <div class="cr-compare-item">
                                <span class="cr-compare-label">Email</span>
                                <div class="cr-compare-new" id="crEmailDisplay">--</div>
                            </div>
                            <div class="cr-compare-item">
                                <span class="cr-compare-label">Address</span>
                                <div class="cr-compare-new" id="crAddressDisplay">--</div>
                            </div>
                            <div class="cr-compare-item">
                                <span class="cr-compare-label">Civil Status</span>
                                <div class="cr-compare-new" id="crCivilStatusDisplay">--</div>
                            </div>
                            <div class="cr-compare-item">
                                <span class="cr-compare-label">Birthday</span>
                                <div class="cr-compare-new" id="crBirthdayDisplay">--</div>
                            </div>
                            <div class="cr-compare-item">
                                <span class="cr-compare-label">Contact Number</span>
                                <div class="cr-compare-new" id="crPhoneNumberDisplay">--</div>
                            </div>
                            <div class="cr-compare-item">
                                <span class="cr-compare-label">New Password</span>
                                <div class="cr-compare-new" id="crPasswordDisplay">--</div>
                            </div>
                            <div class="cr-compare-item" id="crIdFileGroup" style="display:none;">
                                <span class="cr-compare-label">New ID Photo</span>
                                <div class="cr-media-preview" id="crIdFilePreview"></div>
                            </div>
                            <div class="cr-compare-item" id="crProfilePicGroup" style="display:none;">
                                <span class="cr-compare-label">New Profile Picture</span>
                                <div class="cr-media-preview" id="crProfilePicPreview"></div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="cr-modal-footer">
                <button type="button" class="cr-btn cr-btn-close" onclick="closeChangeRequestModal()"><i class="fas fa-times"></i> Close</button>
                <button type="button" class="cr-btn cr-btn-reject" onclick="rejectChangeRequest()"><i class="fas fa-times"></i> Reject</button>
                <button type="button" class="cr-btn cr-btn-approve" onclick="approveChangeRequest()"><i class="fas fa-check"></i> Approve & Update</button>
            </div>
        </div>
    </div>

    <script>
        let currentUserId = null;
        let usersData = <?php echo json_encode($users); ?>;

        function showUserModal(userId) {
            currentUserId = userId;
            const user = usersData.find(u => u.id == userId);
            if (!user) return;

            const status = user.is_active ? 'Active' : 'Inactive';
            document.getElementById('modalEmail').value = user.email;
            document.getElementById('modalFullName').value = user.full_name;
            document.getElementById('modalRole').value = roleLabel(user.role);
            document.getElementById('modalDepartment').value = user.department || 'N/A';
            document.getElementById('modalAddress').value = user.address || 'N/A';
            document.getElementById('modalBirthday').value = formatDateValue(user.birthday);
            document.getElementById('modalCivilStatus').value = user.civil_status ? user.civil_status.charAt(0).toUpperCase() + user.civil_status.slice(1) : 'N/A';
            document.getElementById('modalPhoneNumber').value = user.phone_number || 'N/A';
            document.getElementById('modalAccountStatus').value = status;
            document.getElementById('modalCreatedAt').value = formatDateTimeValue(user.created_at);
            document.getElementById('modalApprovedAt').value = user.approved_at ? formatDateTimeValue(user.approved_at) : 'N/A';
            document.getElementById('modalLastActive').value = user.last_activity ? formatDateTimeValue(user.last_activity) : 'Never active';

            document.getElementById('modalProfileAvatar').textContent = initialsOf(user.full_name);
            document.getElementById('modalProfileName').textContent = user.full_name;
            document.getElementById('modalProfileEmail').textContent = user.email;
            document.getElementById('modalProfileRole').innerHTML = '<i class="fas fa-user-tag"></i> ' + roleLabel(user.role);

            const statusEl = document.getElementById('modalProfileStatus');
            statusEl.innerHTML = '<i class="fas fa-clock"></i> ' + status;
            statusEl.className = 'profile-badge ' + (user.is_active ? 'profile-badge-active' : 'profile-badge-pending');

            const idFileImg = document.getElementById('modalIdFile');
            const idFileNone = document.getElementById('modalIdFileNone');
            const idFileLabel = document.getElementById('modalIdFileLabel');
            if (user.id_file_path) {
                idFileImg.src = '../../' + user.id_file_path;
                idFileImg.style.display = 'block';
                idFileNone.style.display = 'none';
                idFileLabel.textContent = (user.id_file_path.split('/').pop() || 'ID file').split('?')[0];
            } else {
                idFileImg.style.display = 'none';
                idFileNone.style.display = 'flex';
                idFileLabel.textContent = 'No ID file uploaded';
            }
            document.getElementById('userModal').style.display = 'flex';
        }

        function roleLabel(role) {
            const map = {
                'lgu_staff': 'LGU Staff',
                'citizen': 'Citizen',
                'road_ops_supervisor': 'Road Operations Supervisor',
                'trans_ops_supervisor': 'Transportation Operations Supervisor',
                'road_monitoring_officer': 'Road Monitoring Officer',
                'trans_monitoring_officer': 'Transportation Monitoring Officer',
                'system_admin': 'System Admin'
            };
            return map[role] || (role ? role.charAt(0).toUpperCase() + role.slice(1) : 'N/A');
        }

        function formatDateTimeValue(value) {
            if (!value) return 'N/A';
            const d = new Date(value);
            if (isNaN(d)) return value;
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ', ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }

        function formatDateValue(value) {
            if (!value) return 'N/A';
            const d = new Date(value);
            if (isNaN(d)) return value;
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        function initialsOf(name) {
            if (!name) return '?';
            return name.split(' ').map(w => w.charAt(0)).join('').substring(0, 2).toUpperCase();
        }

        function closeUserModal() {
            document.getElementById('userModal').style.display = 'none';
            currentUserId = null;
        }

        function doUserAction(action) {
            if (!currentUserId) return;
            const msg = action === 'approve' ? 'approve' : 'reject';
            if (!confirm('Are you sure you want to ' + msg + ' this user account?')) return;

            const formData = new FormData();
            formData.append('action', action);
            formData.append('user_id', currentUserId);
            formData.append('remarks', action === 'approve' ? 'Approved by admin' : 'Rejected by admin');

            fetch('', { method: 'POST', body: formData })
            .then(async (response) => {
                const contentType = response.headers.get('content-type') || '';
                if (!response.ok) {
                    const text = await response.text();
                    throw new Error(text || 'Request failed');
                }
                if (!contentType.includes('application/json')) {
                    const text = await response.text();
                    throw new Error(text || 'Not JSON');
                }
                return response.json();
            })
            .then(result => {
                if (result.success) { closeUserModal(); location.reload(); }
                else { alert(result.message); }
            })
            .catch(error => { console.error('Error:', error); alert('An error occurred.'); });
        }

        function approveUser() { doUserAction('approve'); }
        function rejectUser() { doUserAction('reject'); }

        let changeRequestsData = <?php echo json_encode($change_requests); ?>;

        function showChangeRequestModal(requestId, userId) {
            const cr = changeRequestsData.find(r => r.id == requestId);
            if (!cr) return;
            const data = JSON.parse(cr.requested_data);

            document.getElementById('crAction').value = '';
            document.getElementById('crRequestId').value = cr.id;
            document.getElementById('crUserId').value = cr.user_id;

            // Staff header
            var initials = (cr.user_name || 'S').split(' ').map(function(w){ return w.charAt(0); }).join('').substring(0,2).toUpperCase();
            document.getElementById('crStaffAvatar').textContent = initials;
            document.getElementById('crStaffName').textContent = cr.user_name || 'Unknown Staff';
            document.getElementById('crStaffDate').textContent = 'Submitted on ' + new Date(cr.created_at).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });

            // Current information grid
            var currentHtml = '';
            var fields = [
                { key: 'full_name', label: 'Full Name', value: cr.user_name },
                { key: 'email', label: 'Email', value: cr.user_email },
                { key: 'address', label: 'Address', value: cr.user_address },
                { key: 'civil_status', label: 'Civil Status', value: cr.user_civil_status ? cr.user_civil_status.charAt(0).toUpperCase() + cr.user_civil_status.slice(1) : 'N/A' },
                { key: 'birthday', label: 'Birthday', value: cr.user_birthday },
                { key: 'phone_number', label: 'Contact Number', value: cr.user_phone_number }
            ];
            fields.forEach(function(f) {
                currentHtml += '<div class="cr-compare-item"><span class="cr-compare-label">' + f.label + '</span><div class="cr-compare-old">' + escapeHtml(f.value || 'N/A') + '</div></div>';
            });
            if (cr.user_id_file) {
                var ext = cr.user_id_file.split('.').pop().toLowerCase();
                if (['jpg','jpeg','png','gif'].includes(ext)) {
                    currentHtml += '<div class="cr-compare-item" style="grid-column:1/-1;"><span class="cr-compare-label">Current ID Photo</span><div class="cr-media-preview"><img src="../../' + cr.user_id_file + '" alt="Current ID"><span class="cr-media-label">Uploaded ID file</span></div></div>';
                }
            }
            document.getElementById('crCurrentGrid').innerHTML = currentHtml;

            // Requested changes
            document.getElementById('crFullName').value = data.full_name || cr.user_name || '';
            document.getElementById('crFullNameDisplay').textContent = data.full_name || 'N/A';
            document.getElementById('crFullNameDisplay').className = (data.full_name && data.full_name !== cr.user_name) ? 'cr-compare-new' : 'cr-compare-new no-change';

            document.getElementById('crEmail').value = data.email || cr.user_email || '';
            document.getElementById('crEmailDisplay').textContent = data.email || 'N/A';
            document.getElementById('crEmailDisplay').className = (data.email && data.email !== cr.user_email) ? 'cr-compare-new' : 'cr-compare-new no-change';

            document.getElementById('crAddress').value = data.address || cr.user_address || '';
            document.getElementById('crAddressDisplay').textContent = data.address || 'N/A';
            document.getElementById('crAddressDisplay').className = (data.address && data.address !== cr.user_address) ? 'cr-compare-new' : 'cr-compare-new no-change';

            document.getElementById('crCivilStatus').value = data.civil_status || cr.user_civil_status || '';
            var csDisplay = data.civil_status ? data.civil_status.charAt(0).toUpperCase() + data.civil_status.slice(1) : 'N/A';
            document.getElementById('crCivilStatusDisplay').textContent = csDisplay;
            document.getElementById('crCivilStatusDisplay').className = (data.civil_status && data.civil_status !== cr.user_civil_status) ? 'cr-compare-new' : 'cr-compare-new no-change';

            document.getElementById('crBirthday').value = data.birthday || cr.user_birthday || '';
            document.getElementById('crBirthdayDisplay').textContent = data.birthday || 'N/A';
            document.getElementById('crBirthdayDisplay').className = (data.birthday && data.birthday !== cr.user_birthday) ? 'cr-compare-new' : 'cr-compare-new no-change';

            document.getElementById('crPhoneNumber').value = data.phone_number || cr.user_phone_number || '';
            document.getElementById('crPhoneNumberDisplay').textContent = data.phone_number || 'N/A';
            document.getElementById('crPhoneNumberDisplay').className = (data.phone_number && data.phone_number !== cr.user_phone_number) ? 'cr-compare-new' : 'cr-compare-new no-change';

            var pwVal = '';
            if (data.new_password_hash) { pwVal = data.new_password_hash; }
            else if (data.new_password && typeof data.new_password === 'string') { pwVal = data.new_password; }
            document.getElementById('crPassword').value = pwVal;
            var hasPw = !!(data.new_password || data.new_password_hash);
            document.getElementById('crPasswordDisplay').innerHTML = hasPw ? '<i class="fas fa-key" style="color:#f59e0b; margin-right:6px;"></i>New password requested' : 'No change';
            document.getElementById('crPasswordDisplay').className = hasPw ? 'cr-compare-new' : 'cr-compare-new no-change';

            document.getElementById('crIdFilePath').value = data.id_file_path || '';
            document.getElementById('crProfilePicture').value = data.profile_picture || '';

            // ID file preview
            var idFileGroup = document.getElementById('crIdFileGroup');
            var idFilePreview = document.getElementById('crIdFilePreview');
            if (data.id_file_path) {
                idFileGroup.style.display = 'block';
                var ext = data.id_file_path.split('.').pop().toLowerCase();
                if (['jpg','jpeg','png','gif'].includes(ext)) {
                    idFilePreview.innerHTML = '<img src="../../' + data.id_file_path + '" alt="New ID"><span class="cr-media-label">New ID photo uploaded</span>';
                } else {
                    idFilePreview.innerHTML = '<a href="../../' + data.id_file_path + '" target="_blank" style="color:#3762c8; font-size:13px;"><i class="fas fa-file"></i> View uploaded file</a>';
                }
            } else {
                idFileGroup.style.display = 'none';
                idFilePreview.innerHTML = '';
            }

            // Profile picture preview
            var profilePicGroup = document.getElementById('crProfilePicGroup');
            var profilePicPreview = document.getElementById('crProfilePicPreview');
            if (data.profile_picture) {
                profilePicGroup.style.display = 'block';
                profilePicPreview.innerHTML = '<img src="../../uploads/profile_pictures/' + data.profile_picture + '" alt="New Profile Pic" style="border-radius:50%;"><span class="cr-media-label">New profile picture uploaded</span>';
            } else {
                profilePicGroup.style.display = 'none';
                profilePicPreview.innerHTML = '';
            }

            document.getElementById('changeRequestModal').style.display = 'flex';
        }

        function closeChangeRequestModal() {
            document.getElementById('changeRequestModal').style.display = 'none';
        }

        function approveChangeRequest() {
            if (!confirm('Apply these changes to the user account?')) return;
            document.getElementById('crAction').value = 'approve_change';
            submitChangeRequest();
        }

        function rejectChangeRequest() {
            if (!confirm('Reject this change request?')) return;
            document.getElementById('crAction').value = 'reject_change';
            submitChangeRequest();
        }

        function submitChangeRequest() {
            const form = document.getElementById('changeRequestForm');
            const formData = new FormData(form);
            const requestId = formData.get('request_id');
            fetch('', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    closeChangeRequestModal();
                    // Immediately drop the processed row from the active panel.
                    // Approved/rejected rows are excluded by status and live in archive.php.
                    removeChangeRequestRow(requestId);
                    if (result.message) {
                        showFocusMessage(result.message);
                    }
                    setTimeout(function() { location.reload(); }, 600);
                } else {
                    alert(result.message || 'Unable to process change request.');
                }
            })
            .catch(error => { console.error('Error:', error); alert('An error occurred.'); });
        }

        function removeChangeRequestRow(requestId) {
            var id = String(requestId || '');
            if (!id) return;
            var row = document.querySelector('#changeRequestsBody tr[data-id="' + id + '"]');
            if (row) row.remove();
            if (Array.isArray(changeRequestsData)) {
                changeRequestsData = changeRequestsData.filter(function(r) { return String(r.id) !== id; });
            }
            if (Array.isArray(previousChangeRequestIds)) {
                previousChangeRequestIds = previousChangeRequestIds.filter(function(rid) { return String(rid) !== id; });
            }
            var remaining = document.querySelectorAll('#changeRequestsBody tr[data-id]').length;
            var badge = document.getElementById('changeRequestsBadge');
            if (badge) badge.textContent = remaining;
            var stat = document.getElementById('statChangeRequests');
            if (stat) stat.textContent = remaining;
            var body = document.getElementById('changeRequestsBody');
            if (body && remaining === 0) {
                body.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#64748b;">No pending change requests</td></tr>';
            }
        }

        window.onclick = function(event) {
            const m1 = document.getElementById('userModal');
            const m2 = document.getElementById('changeRequestModal');
            if (event.target == m1) closeUserModal();
            if (event.target == m2) closeChangeRequestModal();
        }

        function updateDateTime() {
            const now = new Date();
            document.getElementById('currentDate').textContent = now.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            document.getElementById('currentTime').textContent = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        updateDateTime();
        setInterval(updateDateTime, 1000);

        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(a => {
                a.style.transition = 'opacity 0.5s';
                a.style.opacity = '0';
                setTimeout(() => a.remove(), 500);
            });
        }, 5000);

        let syncInterval = null;
        let previousChangeRequestIds = changeRequestsData.map(r => r.id);
        let previousUserIds = usersData.map(u => u.id);

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        var roleLabels = {
            'lgu_staff': 'LGU Staff',
            'citizen': 'Citizen',
            'road_ops_supervisor': 'Road Operations Supervisor',
            'trans_ops_supervisor': 'Transportation Operations Supervisor',
            'road_monitoring_officer': 'Road Monitoring Officer',
            'trans_monitoring_officer': 'Transportation Monitoring Officer'
        };

        function roleLabel(role) {
            return roleLabels[role] || role;
        }

        function renderPendingUsersRow(user) {
            return '<tr>' +
                '<td>' + escapeHtml(user.full_name) + '</td>' +
                '<td>' + escapeHtml(user.email) + '</td>' +
                '<td>' + escapeHtml(roleLabel(user.role)) + '</td>' +
                '<td>' + escapeHtml(user.department || 'N/A') + '</td>' +
                '<td>' + new Date(user.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + '</td>' +
                '<td><div class="action-buttons"><button class="btn-sm btn-manage" onclick="showUserModal(' + user.id + ')">Manage</button></div></td>' +
                '</tr>';
        }

        function renderChangeRequestRow(cr) {
            var reqData = {};
            try { reqData = JSON.parse(cr.requested_data); } catch(e) {}
            var fields = ['full_name', 'email', 'address', 'civil_status', 'birthday', 'phone_number'];
            var currentMap = {
                'full_name': cr.user_name,
                'email': cr.user_email,
                'address': cr.user_address,
                'civil_status': cr.user_civil_status,
                'birthday': cr.user_birthday,
                'phone_number': cr.user_phone_number
            };
            var changedFields = [];
            fields.forEach(function(f) {
                if (reqData[f] && reqData[f] !== '' && reqData[f] !== (currentMap[f] || '')) {
                    changedFields.push(f);
                }
            });

            var currentHtml = '';
            var requestedHtml = '';
            if (changedFields.length === 0 && !reqData.new_password && !reqData.new_password_hash && !reqData.profile_picture && !reqData.id_file_path) {
                currentHtml = 'No changes';
                requestedHtml = 'No changes';
            } else {
                changedFields.forEach(function(f) {
                    var label = f.charAt(0).toUpperCase() + f.slice(1).replace(/_/g, ' ');
                    currentHtml += '<strong>' + label + ':</strong> ' + escapeHtml(currentMap[f] || 'N/A') + '<br>';
                    requestedHtml += '<strong>' + label + ':</strong> ' + escapeHtml(reqData[f]) + '<br>';
                });
                if (reqData.new_password || reqData.new_password_hash) {
                    currentHtml += '<span style="color:#d97706;"><i class="fas fa-key"></i> Current password</span><br>';
                    requestedHtml += '<span style="color:#f59e0b; font-weight:600;"><i class="fas fa-key"></i> New password requested</span><br>';
                }
                if (reqData.profile_picture) {
                    currentHtml += '<span style="color:#7c3aed;"><i class="fas fa-user-circle"></i> Current profile picture</span><br>';
                    requestedHtml += '<span style="color:#8b5cf6; font-weight:600;"><i class="fas fa-user-circle"></i> New profile picture</span><br>';
                }
                if (reqData.id_file_path) {
                    currentHtml += '<span style="color:#059669;"><i class="fas fa-id-card"></i> Current ID photo</span><br>';
                    requestedHtml += '<span style="color:#10b981; font-weight:600;"><i class="fas fa-id-card"></i> New ID photo</span><br>';
                }
            }

            return '<tr data-id="' + (cr.id || '') + '">' +
                '<td>' + escapeHtml(cr.user_name) + '</td>' +
                '<td><small style="color:#666;">' + currentHtml + '</small></td>' +
                '<td><small style="color:#1e3c72;">' + requestedHtml + '</small></td>' +
                '<td><small>' + escapeHtml(cr.reason || 'N/A') + '</small></td>' +
                '<td><small>' + new Date(cr.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + '</small></td>' +
                '<td><div class="action-buttons"><button class="btn-sm btn-approve" onclick="showChangeRequestModal(' + cr.id + ', ' + cr.user_id + ')">Review</button></div></td>' +
                '</tr>';
        }

        function refreshApprovalsData() {
            var dot = document.getElementById('syncDot');
            var text = document.getElementById('syncText');
            dot.classList.add('syncing');
            text.textContent = 'Syncing...';

            fetch('../api/get_account_approvals_data.php')
            .then(function(response) { return response.json(); })
            .then(function(result) {
                if (!result.success) return;

                var data = result.data;

                // Update stat cards
                document.getElementById('statPendingUsers').textContent = data.stats.pending_users;
                document.getElementById('statApprovedUsers').textContent = data.stats.approved_users;
                document.getElementById('statDeactivated').textContent = data.stats.deactivated_users;
                document.getElementById('statChangeRequests').textContent = data.stats.change_requests;

                // Update workflow badges
                document.getElementById('pendingUsersBadge').textContent = data.pending_users.length;
                document.getElementById('changeRequestsBadge').textContent = data.change_requests.length;

                // Update pending users table
                var pendingUsersBody = document.getElementById('pendingUsersBody');
                if (data.pending_users.length === 0) {
                    pendingUsersBody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#64748b;">No pending users</td></tr>';
                } else {
                    var usersHtml = '';
                    data.pending_users.forEach(function(user) { usersHtml += renderPendingUsersRow(user); });
                    pendingUsersBody.innerHTML = usersHtml;
                }

                // Update change requests table
                var changeRequestsBody = document.getElementById('changeRequestsBody');
                if (data.change_requests.length === 0) {
                    changeRequestsBody.innerHTML = '<tr><td colspan="6" style="text-align:center; color:#64748b;">No pending change requests</td></tr>';
                } else {
                    var crHtml = '';
                    data.change_requests.forEach(function(cr) { crHtml += renderChangeRequestRow(cr); });
                    changeRequestsBody.innerHTML = crHtml;
                }

                // Update the JS data arrays so modals work with fresh data
                usersData = data.pending_users;
                changeRequestsData = data.change_requests;

                // Check for new change requests and flash notification
                var newCrIds = data.change_requests.map(function(r) { return r.id; });
                var hasNewRequests = newCrIds.some(function(id) { return previousChangeRequestIds.indexOf(id) === -1; });
                if (hasNewRequests && previousChangeRequestIds.length > 0) {
                    var badge = document.getElementById('changeRequestsBadge');
                    badge.style.transition = 'background 0.3s';
                    badge.style.background = '#f59e0b';
                    setTimeout(function() { badge.style.background = '#3762c8'; }, 2000);
                }
                previousChangeRequestIds = newCrIds;

                // Check for new pending users
                var newUserIds = data.pending_users.map(function(u) { return u.id; });
                var hasNewUsers = newUserIds.some(function(id) { return previousUserIds.indexOf(id) === -1; });
                if (hasNewUsers && previousUserIds.length > 0) {
                    var badge = document.getElementById('pendingUsersBadge');
                    badge.style.transition = 'background 0.3s';
                    badge.style.background = '#f59e0b';
                    setTimeout(function() { badge.style.background = '#3762c8'; }, 2000);
                }
                previousUserIds = newUserIds;

                dot.classList.remove('syncing');
                text.textContent = 'Synced just now';
            })
            .catch(function(error) {
                console.error('Sync error:', error);
                dot.classList.remove('syncing');
                text.textContent = 'Sync failed';
            });
        }

        syncInterval = setInterval(refreshApprovalsData, 30000);

        // Deep-link focus: ?cr_id= from a notification "Review" button. The
        // backend already confirmed the request is still pending and rendered
        // ($focus_target.found); this scrolls to the row and highlights it, or
        // shows a friendly message when the request no longer exists.
        var focusTarget = <?php echo json_encode($focus_target); ?>;
        if (focusTarget && focusTarget.id) {
            setTimeout(function() {
                var crRow = document.querySelector('#changeRequestsBody tr[data-id="' + focusTarget.id + '"]');
                if (crRow && focusTarget.found) {
                    crRow.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    crRow.classList.add('cr-row-focus');
                    setTimeout(function() { crRow.classList.remove('cr-row-focus'); }, 5000);
                } else {
                    showFocusMessage('The change request referenced by this notification could not be found.');
                }
            }, 500);
        }

        function showFocusMessage(message) {
            var el = document.createElement('div');
            el.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:10001;background:#ef4444;color:#fff;padding:14px 20px;border-radius:8px;font-size:14px;font-weight:500;box-shadow:0 4px 12px rgba(0,0,0,0.2);';
            el.textContent = message;
            document.body.appendChild(el);
            setTimeout(function() { el.remove(); }, 5000);
        }
    </script>


</body>
</html>

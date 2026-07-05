<?php
require_once '../../includes/session_config.php';
require_once '../../includes/config.php';
require_once '../../includes/functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'system_admin') {
    header('Location: ../../login.php');
    exit();
}

// Ensure archive table exists
$conn->query("CREATE TABLE IF NOT EXISTS road_transportation_reports_archive LIKE road_transportation_reports");

// Handle restore action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'restore' && isset($_POST['archive_id'])) {
        $archive_id = (int) $_POST['archive_id'];
        $insert = "INSERT IGNORE INTO road_transportation_reports SELECT * FROM road_transportation_reports_archive WHERE id = ?";
        $stmt = $conn->prepare($insert);
        $stmt->bind_param('i', $archive_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            $delete = "DELETE FROM road_transportation_reports_archive WHERE id = ?";
            $stmt = $conn->prepare($delete);
            $stmt->bind_param('i', $archive_id);
            $stmt->execute();
            $_SESSION['archive_message'] = 'Report restored successfully.';
        } else {
            $_SESSION['archive_message'] = 'Restore failed – the record may already exist.';
        }
        header('Location: archive.php');
        exit();
    }
    if ($_POST['action'] === 'delete_forever' && isset($_POST['archive_id'])) {
        $archive_id = (int) $_POST['archive_id'];
        $delete = "DELETE FROM road_transportation_reports_archive WHERE id = ?";
        $stmt = $conn->prepare($delete);
        $stmt->bind_param('i', $archive_id);
        $stmt->execute();
        $_SESSION['archive_message'] = 'Report permanently deleted.';
        header('Location: archive.php');
        exit();
    }
}

$message = '';
if (isset($_SESSION['archive_message'])) {
    $message = $_SESSION['archive_message'];
    unset($_SESSION['archive_message']);
}

$archives = $conn->query("SELECT * FROM road_transportation_reports_archive ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive | LGU Staff</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: #f7f5f0; min-height: 100vh; }
        html { scroll-behavior: smooth; }
        .main-content { margin-left: 250px; padding: 20px; position: relative; z-index: 1; }
        .archive-header {
            background: #f0f4fa; padding: 25px 30px; border-radius: 16px; margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1); border: 1px solid #e0e0e0;
        }
        .archive-header h1 { color: #1e3c72; font-size: 28px; font-weight: 700; margin-bottom: 5px; }
        .archive-header p { color: #666; font-size: 14px; }
        .archive-card {
            background: #f0f4fa; border-radius: 16px; padding: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1); border: 1px solid #e0e0e0;
        }
        .archive-card-header {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 20px; padding-bottom: 15px;
            border-bottom: 2px solid rgba(55,98,200,0.1);
        }
        .archive-card-title {
            font-size: 18px; font-weight: 600; color: #1e3c72;
            display: flex; align-items: center; gap: 10px;
        }
        .archive-badge {
            background: #6c757d; color: white; padding: 4px 12px;
            border-radius: 20px; font-size: 12px; font-weight: 500;
        }
        .archive-item {
            display: flex; align-items: flex-start; padding: 20px; margin-bottom: 15px;
            background: rgba(255,255,255,0.7); border-radius: 12px;
            border: 1px solid rgba(55,98,200,0.1); transition: all 0.3s ease;
        }
        .archive-item:hover {
            background: rgba(55,98,200,0.05); transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(55,98,200,0.1);
        }
        .archive-icon {
            width: 50px; height: 50px; background: linear-gradient(135deg,#6c757d,#495057);
            border-radius: 12px; display: flex; align-items: center; justify-content: center;
            color: white; font-size: 20px; margin-right: 20px; flex-shrink: 0;
        }
        .archive-content { flex: 1; }
        .archive-title { font-size: 16px; font-weight: 600; color: #333; margin-bottom: 8px; }
        .archive-meta { display: flex; gap: 20px; margin-bottom: 12px; flex-wrap: wrap; }
        .meta-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: #666; }
        .meta-item i { color: #6c757d; }
        .archive-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
        .btn-restore {
            padding: 8px 16px; background: linear-gradient(135deg,#28a745,#20c997);
            color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500;
            cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.3s ease;
        }
        .btn-restore:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(40,167,69,0.3); }
        .btn-delete-forever {
            padding: 8px 16px; background: linear-gradient(135deg,#dc3545,#c82333);
            color: white; border: none; border-radius: 6px; font-size: 14px; font-weight: 500;
            cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all 0.3s ease;
        }
        .btn-delete-forever:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(220,53,69,0.3); }
        .notification {
            position: fixed; top: 20px; right: 20px; padding: 15px 20px; border-radius: 8px;
            color: white; font-weight: 500; z-index: 10000; animation: slideIn 0.3s ease;
        }
        .notification.success { background: #28a745; }
        .notification.error { background: #dc3545; }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .empty-state {
            text-align: center; padding: 60px 20px; color: #666;
        }
        .empty-state i { font-size: 48px; margin-bottom: 15px; opacity: 0.4; color: #6c757d; }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
            .archive-meta { flex-direction: column; gap: 8px; }
        }
    </style>
</head>
<body>
    <iframe src="../../includes/sidebar.php"
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;"
            frameborder="0" name="sidebar-frame" scrolling="no">
    </iframe>

    <div class="main-content">
        <div class="archive-header">
            <h1><i class="fas fa-archive"></i> Archive</h1>
            <p>View and restore archived transportation reports</p>
        </div>

        <div class="archive-card">
            <div class="archive-card-header">
                <h3 class="archive-card-title">
                    <i class="fas fa-folder-open"></i>
                    Archived Reports
                    <span class="archive-badge"><?php echo $archives->num_rows; ?></span>
                </h3>
            </div>

            <?php if ($archives->num_rows > 0): ?>
                <?php while ($row = $archives->fetch_assoc()): ?>
                    <div class="archive-item">
                        <div class="archive-icon">
                            <i class="fas fa-file-archive"></i>
                        </div>
                        <div class="archive-content">
                            <div class="archive-title"><?php echo htmlspecialchars($row['title']); ?></div>
                            <div class="archive-meta">
                                <span class="meta-item"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($row['report_type']); ?></span>
                                <span class="meta-item"><i class="fas fa-building"></i> <?php echo htmlspecialchars($row['department']); ?></span>
                                <span class="meta-item"><i class="fas fa-calendar"></i> <?php echo htmlspecialchars($row['created_at']); ?></span>
                                <span class="meta-item"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($row['location'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="archive-actions">
                                <form method="POST" style="display: inline-flex;" onsubmit="return confirm('Restore this report back to active table?');">
                                    <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="action" value="restore" class="btn-restore">
                                        <i class="fas fa-undo"></i> Restore
                                    </button>
                                </form>
                                <form method="POST" style="display: inline-flex;" onsubmit="return confirm('Permanently delete this archived report? This cannot be undone.');">
                                    <input type="hidden" name="archive_id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" name="action" value="delete_forever" class="btn-delete-forever">
                                        <i class="fas fa-trash"></i> Delete Forever
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-archive"></i>
                    <p>No archived reports yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($message): ?>
    <script>
        (function() {
            var n = document.createElement('div');
            n.className = 'notification success';
            n.textContent = <?php echo json_encode($message); ?>;
            document.body.appendChild(n);
            setTimeout(function() { n.remove(); }, 3000);
        })();
    </script>
    <?php endif; ?>

    <script>
        document.querySelectorAll('.archive-item form').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                var btn = this.querySelector('button[type="submit"]');
                if (btn && btn.name === 'action' && btn.value === 'restore') {
                    if (!confirm('Restore this report back to the active table?')) {
                        e.preventDefault();
                    }
                }
            });
        });
    </script>

    <div class="page-transition-overlay" id="pageTransitionOverlay">
        <div class="transition-content">
            <div class="transition-spinner"><i class="fas fa-spinner"></i></div>
            <div class="transition-text">Loading...</div>
        </div>
    </div>
</body>
</html>

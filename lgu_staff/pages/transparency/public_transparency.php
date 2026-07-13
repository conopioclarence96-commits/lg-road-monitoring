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

$session_timeout = 5 * 60;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header('Location: ../../login.php?timeout=1');
    exit;
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'system_admin' && $_SESSION['role'] !== 'lgu_staff')) {
    header('Location: ../../login.php');
    exit;
}

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Public Transparency – Completed Projects | LGU Staff</title>
    <link rel="icon" type="image/png" href="../../assets/img/logocityhall.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/public_transparency.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
    <style>
        .projects-section {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .project-form-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(15px);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #1e3c72;
            margin-bottom: 6px;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Poppins', sans-serif;
            transition: border-color 0.3s, box-shadow 0.3s;
            background: white;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #3762c8;
            box-shadow: 0 0 0 3px rgba(55,98,200,0.15);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .photo-upload-area {
            border: 2px dashed #ccc;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            min-height: 160px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .photo-upload-area:hover {
            border-color: #3762c8;
            background: rgba(55,98,200,0.03);
        }

        .photo-upload-area.has-image {
            padding: 0;
            border-style: solid;
            border-color: #4CAF50;
        }

        .photo-upload-area input[type="file"] {
            position: absolute;
            inset: 0;
            opacity: 0;
            cursor: pointer;
        }

        .photo-upload-area .upload-icon {
            font-size: 2.5rem;
            color: #bbb;
            margin-bottom: 8px;
        }

        .photo-upload-area .upload-text {
            font-size: 13px;
            color: #888;
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
            background: rgba(244,67,54,0.9);
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
            gap: 12px;
            margin-top: 10px;
        }

        .btn-save {
            padding: 12px 28px;
            background: linear-gradient(135deg, #4CAF50, #388E3C);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(76,175,80,0.3);
        }

        .btn-cancel {
            padding: 12px 28px;
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-cancel:hover {
            background: #5a6268;
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
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #eee;
            transition: all 0.3s;
        }

        .project-item:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.12);
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

        .project-preview .label-before { left: 8px; background: rgba(244,67,54,0.9); color: white; }
        .project-preview .label-after { right: 8px; background: rgba(76,175,80,0.9); color: white; }

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
            color: #888;
        }

        .project-meta i {
            color: #4CAF50;
            margin-right: 3px;
        }

        .project-cost {
            display: inline-block;
            background: linear-gradient(135deg, #1e3c72, #2a5298);
            color: white;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }

        .project-actions {
            display: flex;
            gap: 8px;
            padding: 0 16px 16px;
        }

        .btn-edit {
            padding: 6px 14px;
            background: #3762c8;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .btn-edit:hover { background: #2a4fa8; }

        .btn-delete {
            padding: 6px 14px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .btn-delete:hover { background: #c82333; }

        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #999;
        }

        .empty-state i {
            font-size: 3rem;
            color: #ddd;
            margin-bottom: 15px;
        }

        .sync-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            background: rgba(76,175,80,0.1);
            color: #4CAF50;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .sync-badge i { font-size: 10px; }

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
        .toast.success { background: #4CAF50; }
        .toast.error { background: #dc3545; }
    </style>
</head>
<body>
    <!-- SIDEBAR -->
    <iframe src="../../includes/sidebar.php" 
            style="position: fixed; width: 250px; height: 100vh; border: none; z-index: 1000;" 
            frameborder="0" name="sidebar-frame" scrolling="yes">
    </iframe>

    <div class="main-content">
        <!-- Header -->
        <div class="transparency-header">
            <div class="header-content">
                <div class="header-title">
                    <h1><i class="fas fa-exchange-alt"></i> Public Transparency – Completed Projects</h1>
                    <p>Manage before &amp; after project photos that appear on the landing page</p>
                </div>
                <div class="header-actions">
                    <span class="sync-badge"><i class="fas fa-sync-alt"></i> Syncs to Landing Page</span>
                    <a href="../../../index.php#projects" target="_blank" class="btn-action">
                        <i class="fas fa-external-link-alt"></i> View on Landing Page
                    </a>
                </div>
            </div>
        </div>

        <!-- Stats -->
        <div class="transparency-stats">
            <div class="transparency-stat">
                <div class="stat-number" id="statTotal"><?php echo count($projects); ?></div>
                <div class="stat-label">Total Projects</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number" id="statWithBefore"><?php echo count(array_filter($projects, fn($p) => !empty($p['before_photo']))); ?></div>
                <div class="stat-label">With Before Photo</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number" id="statWithAfter"><?php echo count(array_filter($projects, fn($p) => !empty($p['photo']))); ?></div>
                <div class="stat-label">With After Photo</div>
            </div>
            <div class="transparency-stat">
                <div class="stat-number" id="statTotalCost">₱<?php echo number_format(array_sum(array_column($projects, 'cost')), 0); ?></div>
                <div class="stat-label">Total Project Cost</div>
            </div>
        </div>

        <!-- Add / Edit Form -->
        <div class="project-form-card" id="projectForm">
            <div class="section-header">
                <h3 class="section-title" id="formTitle"><i class="fas fa-plus-circle"></i> Add New Project</h3>
                <button class="btn-cancel" id="btnCancelEdit" style="display:none" onclick="resetForm()">
                    <i class="fas fa-times"></i> Cancel Edit
                </button>
            </div>

            <form id="projectFormEl" enctype="multipart/form-data">
                <input type="hidden" id="projectId" value="">
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
                    </div>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn-save" id="btnSave">
                        <i class="fas fa-save"></i> Save Project
                    </button>
                    <button type="button" class="btn-cancel" onclick="resetForm()">Cancel</button>
                </div>
            </form>
        </div>

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
                    $after_img = !empty($proj['photo']) ? htmlspecialchars(ltrim(str_replace(['../', '..\\'], '', $proj['photo']), '/\\')) : '';
                    $before_img = !empty($proj['before_photo']) ? htmlspecialchars(ltrim(str_replace(['../', '..\\'], '', $proj['before_photo']), '/\\')) : '';
                    $has_before = !empty($proj['before_photo']);
                ?>
                <div class="project-item" data-id="<?php echo $proj['id']; ?>">
                    <div class="project-preview" data-preview>
                        <?php if ($after_img): ?>
                        <img src="<?php echo $after_img; ?>" alt="After" class="preview-after" onerror="this.src='https://via.placeholder.com/600x375/4CAF50/ffffff?text=After'">
                        <?php else: ?>
                        <img src="https://via.placeholder.com/600x375/4CAF50/ffffff?text=After+Photo" alt="After" class="preview-after">
                        <?php endif; ?>

                        <?php if ($has_before): ?>
                        <img src="<?php echo $before_img; ?>" alt="Before" class="preview-before" onerror="this.src='https://via.placeholder.com/600x375/dc3545/ffffff?text=Before'">
                        <?php else: ?>
                        <img src="https://via.placeholder.com/600x375/dc3545/ffffff?text=No+Before+Photo" alt="Before" class="preview-before">
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
                    </div>

                    <div class="project-actions">
                        <button class="btn-edit" onclick="editProject(<?php echo htmlspecialchars(json_encode($proj)); ?>)">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button class="btn-delete" onclick="deleteProject(<?php echo $proj['id']; ?>, '<?php echo htmlspecialchars(addslashes($proj['title'])); ?>')">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <script>
    const API = '../../pages/api/completed_projects_api.php';
    let isEditing = false;

    // ─── Photo Upload ─────────────────────────────────────
    function handlePhotoSelect(input, type) {
        const file = input.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('action', 'upload_photo');
        formData.append('field', type === 'before' ? 'before_photo' : 'photo');
        formData.append(type === 'before' ? 'before_photo' : 'photo', file);

        const area = document.getElementById(type + 'PhotoArea');
        const preview = document.getElementById(type + 'PhotoPreview');
        const pathInput = document.getElementById(type + 'PhotoPath');
        const removeBtn = area.querySelector('.remove-photo');

        area.style.opacity = '0.5';

        fetch(API, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                area.style.opacity = '1';
                if (data.success) {
                    pathInput.value = data.path;
                    preview.src = data.path;
                    preview.style.display = 'block';
                    area.querySelector('.upload-icon').style.display = 'none';
                    area.querySelector('.upload-text').style.display = 'none';
                    area.classList.add('has-image');
                    removeBtn.style.display = 'flex';
                } else {
                    showToast(data.message || 'Upload failed', 'error');
                }
            })
            .catch(() => {
                area.style.opacity = '1';
                showToast('Upload error', 'error');
            });
    }

    function removePhoto(type) {
        const area = document.getElementById(type + 'PhotoArea');
        const preview = document.getElementById(type + 'PhotoPreview');
        const pathInput = document.getElementById(type + 'PhotoPath');
        const input = document.getElementById(type + 'PhotoInput');
        const removeBtn = area.querySelector('.remove-photo');

        pathInput.value = '';
        preview.src = '';
        preview.style.display = 'none';
        input.value = '';
        area.querySelector('.upload-icon').style.display = '';
        area.querySelector('.upload-text').style.display = '';
        area.classList.remove('has-image');
        removeBtn.style.display = 'none';
    }

    // ─── Form Submit ──────────────────────────────────────
    document.getElementById('projectFormEl').addEventListener('submit', function(e) {
        e.preventDefault();

        const title = document.getElementById('projectTitle').value.trim();
        if (!title) { showToast('Title is required', 'error'); return; }

        const afterPath = document.getElementById('afterPhotoPath').value;
        if (!isEditing && !afterPath) { showToast('After photo is required', 'error'); return; }

        const formData = new FormData();
        formData.append('action', isEditing ? 'update' : 'create');
        formData.append('title', title);
        formData.append('description', document.getElementById('projectDesc').value.trim());
        formData.append('location', document.getElementById('projectLocation').value.trim());
        formData.append('completed_date', document.getElementById('projectDate').value);
        formData.append('cost', document.getElementById('projectCost').value || 0);
        formData.append('completed_by', document.getElementById('projectCompletedBy').value.trim());
        formData.append('photo', afterPath);
        formData.append('before_photo', document.getElementById('beforePhotoPath').value);

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
    });

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
        document.getElementById('projectCost').value = project.cost || '';
        document.getElementById('projectCompletedBy').value = project.completed_by || '';

        // Set after photo
        if (project.photo) {
            const afterPath = document.getElementById('afterPhotoPath');
            const afterPreview = document.getElementById('afterPhotoPreview');
            const afterArea = document.getElementById('afterPhotoArea');
            afterPath.value = project.photo;
            afterPreview.src = project.photo;
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
            beforePreview.src = project.before_photo;
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
        document.getElementById('formTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Project';
        document.getElementById('btnCancelEdit').style.display = 'none';
        document.getElementById('btnSave').innerHTML = '<i class="fas fa-save"></i> Save Project';
        removePhoto('before');
        removePhoto('after');
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
    </script>
</body>
</html>

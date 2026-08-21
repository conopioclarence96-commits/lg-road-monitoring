<?php
/**
 * Announcements Management — Admin only (create / edit / delete / publish).
 * UI matches Admin Dashboard. Separate from reports / Public Transparency.
 */

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 0);
session_start();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/announcements.php';

$session_timeout = 30 * 60;
lgu_enforce_idle_timeout($session_timeout, '../../login.php?timeout=1');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'system_admin') {
    header('Location: ../../login.php');
    exit;
}

announcements_ensure_table($conn);
$announcements = announcements_fetch_all($conn);
$total = count($announcements);
$published_count = count(array_filter($announcements, fn($a) => !empty($a['is_published'])));
$draft_count = $total - $published_count;
$with_photo = count(array_filter($announcements, fn($a) => !empty($a['photo'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements | Admin</title>
    <link rel="icon" type="image/png" href="../../assets/img/infra-gov-logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../css/theme-tokens.css">
    <link rel="stylesheet" href="../../css/theme-utilities.css">
    <link rel="stylesheet" href="../../css/sidebar.css?v=6">
    <link rel="stylesheet" href="../../css/announcements.css?v=<?php echo @filemtime(__DIR__ . '/../../css/announcements.css') ?: time(); ?>">
    <link rel="stylesheet" href="../../../styles/transition.css">
    <?php if (!empty($_SESSION['darkmode'])): ?><link rel="stylesheet" href="../../css/dark-mode.css"><?php endif; ?>
</head>
<body class="announcements-page<?php echo !empty($_SESSION['darkmode']) ? ' dark-mode' : ''; ?>">
    <?php include '../../includes/sidebar_nav.php'; ?>

    <div class="main-content admin-dash">
        <div class="dashboard-header">
            <div class="welcome-text">
                <h1><span class="header-icon"><i class="fas fa-bullhorn"></i></span> Announcements</h1>
                <p>Post updates that appear on every role&rsquo;s dashboard</p>
            </div>
        </div>

        <div class="summary-row">
            <div class="summary-card blue">
                <div class="card-top"><div class="card-icon"><i class="fas fa-bullhorn"></i></div></div>
                <div class="card-value"><?php echo (int)$total; ?></div>
                <div class="card-label">Total</div>
            </div>
            <div class="summary-card emerald">
                <div class="card-top"><div class="card-icon"><i class="fas fa-globe"></i></div></div>
                <div class="card-value"><?php echo (int)$published_count; ?></div>
                <div class="card-label">Published</div>
            </div>
            <div class="summary-card amber">
                <div class="card-top"><div class="card-icon"><i class="fas fa-file-alt"></i></div></div>
                <div class="card-value"><?php echo (int)$draft_count; ?></div>
                <div class="card-label">Drafts</div>
            </div>
            <div class="summary-card cyan">
                <div class="card-top"><div class="card-icon"><i class="fas fa-image"></i></div></div>
                <div class="card-value"><?php echo (int)$with_photo; ?></div>
                <div class="card-label">With Photo</div>
            </div>
        </div>

        <div class="card panel-form" id="announcementFormCard">
            <div class="card-header">
                <h3 class="card-title" id="announcementFormTitle">
                    <span class="title-icon"><i class="fas fa-plus"></i></span>
                    Create Announcement
                </h3>
                <button type="button" class="btn-sm btn-muted" id="btnCancelAnnouncementEdit" style="display:none" onclick="resetAnnouncementForm()">
                    <i class="fas fa-times"></i> Cancel Edit
                </button>
            </div>

            <form id="announcementFormEl">
                <input type="hidden" id="announcementId" value="">
                <input type="hidden" id="announcementPhotoPath" value="">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label for="announcementTitle">Title *</label>
                        <input type="text" id="announcementTitle" required maxlength="255" placeholder="e.g. System maintenance notice">
                    </div>
                    <div class="form-group full-width">
                        <label for="announcementContent">Message *</label>
                        <textarea id="announcementContent" required rows="4" placeholder="Write the announcement message..."></textarea>
                    </div>
                    <div class="form-group">
                        <label for="announcementPostedAt">Date Posted *</label>
                        <input type="date" id="announcementPostedAt" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <label class="status-switch" for="announcementPublished">
                            <input type="checkbox" id="announcementPublished" checked>
                            <span id="annPublishLabel">Published</span>
                        </label>
                        <div class="field-hint">Published announcements appear on all dashboards.</div>
                    </div>
                    <div class="form-group full-width">
                        <label>Photo (optional)</label>
                        <div class="photo-upload-area" id="announcementPhotoArea" onclick="document.getElementById('announcementPhotoInput').click()">
                            <input type="file" id="announcementPhotoInput" accept="image/*" onclick="event.stopPropagation()" onchange="handleAnnouncementPhoto(this)">
                            <i class="fas fa-image upload-icon"></i>
                            <span class="upload-text">Click to upload a photo</span>
                            <img id="announcementPhotoPreview" alt="" style="display:none">
                            <button type="button" class="remove-photo" id="announcementPhotoRemove" onclick="event.stopPropagation(); removeAnnouncementPhoto()"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                </div>
                <div class="btn-row">
                    <button type="submit" class="btn-action btn-primary" id="btnSaveAnnouncement">
                        <i class="fas fa-bullhorn"></i> Publish Announcement
                    </button>
                    <button type="button" class="btn-action btn-muted" onclick="resetAnnouncementForm()">Cancel</button>
                </div>
            </form>
        </div>

        <div class="card panel-list">
            <div class="card-header">
                <h3 class="card-title">
                    <span class="title-icon"><i class="fas fa-list"></i></span>
                    All Announcements
                </h3>
            </div>

            <?php if (empty($announcements)): ?>
            <div class="empty-state">
                <i class="fas fa-bullhorn"></i>
                No announcements yet. Create one above to show it on every dashboard.
            </div>
            <?php else: ?>
            <div class="ann-list" id="announcementsList">
                <?php foreach ($announcements as $ann):
                    $has_photo = !empty($ann['photo']);
                    $photo_src = $has_photo ? announcements_photo_src($ann['photo'], 'admin') : '';
                    $is_pub = !empty($ann['is_published']);
                ?>
                <article class="ann-item<?php echo $has_photo ? '' : ' no-photo'; ?><?php echo $is_pub ? '' : ' is-draft'; ?>" data-id="<?php echo (int)$ann['id']; ?>">
                    <?php if ($has_photo): ?>
                    <div class="ann-thumb">
                        <img src="<?php echo htmlspecialchars($photo_src); ?>" alt="">
                    </div>
                    <?php endif; ?>
                    <div class="ann-item-body">
                        <div class="ann-item-top">
                            <div class="ann-item-title"><?php echo htmlspecialchars($ann['title'] ?? ''); ?></div>
                            <span class="badge <?php echo $is_pub ? 'badge-published' : 'badge-draft'; ?>">
                                <i class="fas <?php echo $is_pub ? 'fa-globe' : 'fa-file-alt'; ?>"></i>
                                <?php echo $is_pub ? 'Published' : 'Draft'; ?>
                            </span>
                        </div>
                        <div class="ann-item-text"><?php echo nl2br(htmlspecialchars($ann['content'] ?? '')); ?></div>
                        <div class="ann-item-meta">
                            <span><i class="fas fa-calendar-day"></i> <?php echo !empty($ann['posted_at']) ? date('M d, Y', strtotime($ann['posted_at'])) : '—'; ?></span>
                            <?php if (!empty($ann['created_by_name'])): ?>
                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($ann['created_by_name']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ann-item-actions">
                        <button type="button" class="btn-sm btn-primary" onclick='editAnnouncement(<?php echo htmlspecialchars(json_encode([
                            'id' => (int)$ann['id'],
                            'title' => (string)($ann['title'] ?? ''),
                            'content' => (string)($ann['content'] ?? ''),
                            'photo' => (string)($ann['photo'] ?? ''),
                            'posted_at' => (string)($ann['posted_at'] ?? ''),
                            'is_published' => $is_pub ? 1 : 0,
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES); ?>)'>
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button type="button" class="btn-sm btn-success" onclick="toggleAnnouncementPublish(<?php echo (int)$ann['id']; ?>)">
                            <i class="fas <?php echo $is_pub ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                            <?php echo $is_pub ? 'Unpublish' : 'Publish'; ?>
                        </button>
                        <button type="button" class="btn-sm btn-danger" onclick="deleteAnnouncement(<?php echo (int)$ann['id']; ?>, <?php echo htmlspecialchars(json_encode((string)($ann['title'] ?? '')), ENT_QUOTES); ?>)">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
    const ANN_API = '../api/announcements_api.php';
    let isEditingAnnouncement = false;

    function showToast(msg, type) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'toast ' + (type || 'success') + ' show';
        setTimeout(() => t.classList.remove('show'), 3000);
    }

    function applyPhotoPreview(path) {
        const area = document.getElementById('announcementPhotoArea');
        const preview = document.getElementById('announcementPhotoPreview');
        const removeBtn = document.getElementById('announcementPhotoRemove');
        document.getElementById('announcementPhotoPath').value = path || '';
        if (path) {
            preview.src = '../../../' + path.replace(/^\/+/, '');
            preview.style.display = 'block';
            area.querySelector('.upload-icon').style.display = 'none';
            area.querySelector('.upload-text').style.display = 'none';
            area.classList.add('has-image');
            removeBtn.style.display = 'flex';
        } else {
            preview.removeAttribute('src');
            preview.style.display = 'none';
            area.querySelector('.upload-icon').style.display = '';
            area.querySelector('.upload-text').style.display = '';
            area.classList.remove('has-image');
            removeBtn.style.display = 'none';
        }
    }

    function removeAnnouncementPhoto() {
        applyPhotoPreview('');
        document.getElementById('announcementPhotoInput').value = '';
    }

    function handleAnnouncementPhoto(input) {
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
        const area = document.getElementById('announcementPhotoArea');
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
                    throw new Error(data && data.message ? data.message : ('Upload failed (' + r.status + ')'));
                }
                return data;
            })
            .then(data => {
                if (area) area.style.opacity = '1';
                if (data && data.success && data.path) {
                    applyPhotoPreview(data.path);
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

    function resetAnnouncementForm() {
        isEditingAnnouncement = false;
        document.getElementById('announcementFormEl').reset();
        document.getElementById('announcementId').value = '';
        document.getElementById('announcementPostedAt').value = new Date().toISOString().slice(0, 10);
        document.getElementById('announcementPublished').checked = true;
        document.getElementById('annPublishLabel').textContent = 'Published';
        applyPhotoPreview('');
        document.getElementById('announcementFormTitle').innerHTML =
            '<span class="title-icon"><i class="fas fa-plus"></i></span> Create Announcement';
        document.getElementById('btnSaveAnnouncement').innerHTML = '<i class="fas fa-bullhorn"></i> Publish Announcement';
        document.getElementById('btnCancelAnnouncementEdit').style.display = 'none';
    }

    function editAnnouncement(ann) {
        if (!ann) return;
        isEditingAnnouncement = true;
        document.getElementById('announcementId').value = ann.id || '';
        document.getElementById('announcementTitle').value = ann.title || '';
        document.getElementById('announcementContent').value = ann.content || '';
        document.getElementById('announcementPostedAt').value = String(ann.posted_at || '').slice(0, 10);
        document.getElementById('announcementPublished').checked = Number(ann.is_published) === 1;
        document.getElementById('annPublishLabel').textContent = Number(ann.is_published) === 1 ? 'Published' : 'Draft';
        applyPhotoPreview(ann.photo || '');
        document.getElementById('announcementFormTitle').innerHTML =
            '<span class="title-icon"><i class="fas fa-edit"></i></span> Edit Announcement';
        document.getElementById('btnSaveAnnouncement').innerHTML = '<i class="fas fa-save"></i> Update Announcement';
        document.getElementById('btnCancelAnnouncementEdit').style.display = 'inline-flex';
        document.getElementById('announcementFormCard').scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function deleteAnnouncement(id, title) {
        if (!confirm('Delete announcement "' + (title || '') + '"? This cannot be undone.')) return;
        const fd = new FormData();
        fd.append('action', 'delete');
        fd.append('id', String(id));
        fetch(ANN_API + '?action=delete', { method: 'POST', body: fd })
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

    function toggleAnnouncementPublish(id) {
        const fd = new FormData();
        fd.append('action', 'toggle_publish');
        fd.append('id', String(id));
        fetch(ANN_API + '?action=toggle_publish', { method: 'POST', body: fd })
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

    document.getElementById('announcementPublished').addEventListener('change', function () {
        document.getElementById('annPublishLabel').textContent = this.checked ? 'Published' : 'Draft';
    });

    document.getElementById('announcementFormEl').addEventListener('submit', function (e) {
        e.preventDefault();
        const title = document.getElementById('announcementTitle').value.trim();
        const content = document.getElementById('announcementContent').value.trim();
        const postedAt = document.getElementById('announcementPostedAt').value;
        if (!title || !content) {
            showToast('Title and message are required', 'error');
            return;
        }
        const fd = new FormData();
        fd.append('title', title);
        fd.append('content', content);
        fd.append('posted_at', postedAt);
        fd.append('photo', document.getElementById('announcementPhotoPath').value.trim());
        fd.append('is_published', document.getElementById('announcementPublished').checked ? '1' : '0');
        if (isEditingAnnouncement) {
            fd.append('id', document.getElementById('announcementId').value);
        }
        const action = isEditingAnnouncement ? 'update' : 'create';
        const btn = document.getElementById('btnSaveAnnouncement');
        const prev = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        fetch(ANN_API + '?action=' + action, { method: 'POST', body: fd })
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
    </script>
</body>
</html>

/* Progress Updates Management */

let currentUpdatesReportId = null;
let currentUpdatesReportType = null;

function loadUpdates(reportId, reportType) {
    currentUpdatesReportId = reportId;
    currentUpdatesReportType = reportType;
    const container = document.getElementById('updatesTimeline');
    if (!container) return;
    container.innerHTML = '<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin fa-2x" style="color:#3762c8;"></i></div>';

    fetch(`../api/progress_update_api.php?action=get_updates&report_id=${reportId}&report_type=${reportType}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderTimeline(data.updates);
            } else {
                container.innerHTML = `<div class="timeline-empty"><i class="fas fa-exclamation-circle"></i><br>${escapeHtml(data.message)}</div>`;
            }
        })
        .catch(e => {
            container.innerHTML = '<div class="timeline-empty"><i class="fas fa-exclamation-triangle"></i><br>Failed to load updates.</div>';
            console.error('Load updates error', e);
        });
}

function renderTimeline(updates) {
    const container = document.getElementById('updatesTimeline');
    if (!updates || updates.length === 0) {
        container.innerHTML = '<div class="timeline-empty"><i class="fas fa-clock"></i><br>No progress updates yet.<br><small>Updates will appear here as staff members post them.</small></div>';
        return;
    }
    let html = '';
    updates.forEach((u, idx) => {
        const isAdmin = typeof currentUpdatesReportId !== 'undefined';
        const mediaHtml = renderMedia(u.media || []);
        const actionsHtml = isAdmin ? `
            <div class="timeline-actions">
                <button class="btn-edit-update" onclick="editUpdate(${u.id})"><i class="fas fa-pencil"></i> Edit</button>
                <button class="btn-delete-update" onclick="deleteUpdate(${u.id})"><i class="fas fa-trash"></i> Delete</button>
            </div>` : '';

        html += `
        <div class="timeline-entry">
            <div class="timeline-dot"><i class="fas fa-check"></i></div>
            <div class="timeline-card" id="update-card-${u.id}">
                <div class="timeline-header">
                    <div class="timeline-meta">
                        <span class="admin-badge"><i class="fas fa-user-shield"></i> ${escapeHtml(u.admin_name || 'LGU Staff')}</span>
                        <span class="time"><i class="far fa-clock"></i> ${escapeHtml(u.created_at_formatted || u.created_at)}</span>
                    </div>
                </div>
                ${u.title ? `<div class="timeline-title">${escapeHtml(u.title)}</div>` : ''}
                <div class="timeline-desc">${escapeHtml(u.description)}</div>
                ${mediaHtml}
                ${actionsHtml}
            </div>
        </div>`;
    });
    container.innerHTML = html;
}

function renderMedia(mediaItems) {
    if (!mediaItems || mediaItems.length === 0) return '';
    let html = '<div class="timeline-media">';
    mediaItems.forEach(m => {
        const raw = m.file_path || '';
        const path = raw.startsWith('uploads/') ? '../../' + raw : raw;
        if (m.file_type === 'video') {
            html += `<div class="timeline-media-item video-thumb" onclick="openVideo('${escapeHtmlAttr(path)}')" title="Play video">
                <i class="fas fa-play-circle"></i>
            </div>`;
        } else {
            html += `<div class="timeline-media-item" onclick="openLightbox('${escapeHtmlAttr(path)}')">
                <img src="${escapeHtmlAttr(path)}" alt="Update photo" loading="lazy">
            </div>`;
        }
    });
    html += '</div>';
    return html;
}

function openLightbox(src) {
    const overlay = document.getElementById('lightboxOverlay');
    const img = document.getElementById('lightboxImage');
    if (overlay && img) {
        img.src = src;
        overlay.classList.add('show');
    }
}

function closeLightbox() {
    const overlay = document.getElementById('lightboxOverlay');
    if (overlay) overlay.classList.remove('show');
}

function openVideo(src) {
    window.open(src, '_blank');
}

function showUpdateForm(reportId, reportType, updateData) {
    const container = document.getElementById('updatesTimeline');
    const existingForm = document.getElementById('addUpdateFormContainer');
    if (existingForm) existingForm.remove();

    const isEdit = updateData && updateData.id;
    const formDiv = document.createElement('div');
    formDiv.id = 'addUpdateFormContainer';
    formDiv.className = 'add-update-form';
    formDiv.innerHTML = `
        <h5><i class="fas fa-${isEdit ? 'pencil' : 'plus-circle'}"></i> ${isEdit ? 'Edit Update' : 'Add Progress Update'}</h5>
        <form id="addUpdateForm" enctype="multipart/form-data">
            <input type="hidden" name="action" value="${isEdit ? 'edit_update' : 'create_update'}">
            <input type="hidden" name="update_id" value="${isEdit ? updateData.id : ''}">
            <input type="hidden" name="report_id" value="${reportId}">
            <input type="hidden" name="report_type" value="${reportType}">
            <div class="form-group">
                <label>Title (optional)</label>
                <input type="text" name="title" placeholder="e.g., Inspection completed" value="${isEdit ? escapeHtml(updateData.title || '') : ''}">
            </div>
            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" placeholder="Describe the progress made..." required>${isEdit ? escapeHtml(updateData.description || '') : ''}</textarea>
            </div>
            <div class="form-group">
                <label>Photos / Video</label>
                <input type="file" name="media[]" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm" multiple>
                <small style="color:#666;font-size:11px;">Accepted: JPG, PNG, GIF, WebP, MP4, WebM</small>
                <div class="file-previews" id="updateFilePreviews"></div>
            </div>
            ${isEdit ? `<div class="form-group">
                <label>Current media (check to remove)</label>
                <div id="existingUpdateMedia" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;"></div>
            </div>` : ''}
            <div class="form-actions">
                <button type="button" class="btn-action btn-secondary" onclick="cancelUpdateForm()">Cancel</button>
                <button type="submit" class="btn-action"><i class="fas fa-save"></i> ${isEdit ? 'Save Changes' : 'Post Update'}</button>
            </div>
        </form>`;

    container.parentNode.insertBefore(formDiv, container.nextSibling);

    if (isEdit && updateData.media) {
        const mediaContainer = document.getElementById('existingUpdateMedia');
        updateData.media.forEach(m => {
            const div = document.createElement('div');
            div.style.cssText = 'position:relative;width:80px;height:60px;border-radius:6px;overflow:hidden;border:1px solid rgba(55,98,200,0.15);';
            const isVideo = m.file_type === 'video';
            const mediaPath = (m.file_path || '').startsWith('uploads/') ? '../../' + m.file_path : m.file_path;
            div.innerHTML = isVideo
                ? `<i class="fas fa-video" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:20px;color:#3762c8;opacity:0.5;"></i>`
                : `<img src="${escapeHtmlAttr(mediaPath)}" style="width:100%;height:100%;object-fit:cover;">`;
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'remove_media[]';
            cb.value = m.id;
            cb.style.cssText = 'position:absolute;top:3px;right:3px;width:16px;height:16px;cursor:pointer;';
            div.appendChild(cb);
            mediaContainer.appendChild(div);
        });
    }

    document.getElementById('addUpdateForm').addEventListener('submit', handleUpdateFormSubmit);

    // File preview
    const fileInput = document.querySelector('#addUpdateForm input[type="file"]');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const preview = document.getElementById('updateFilePreviews');
            preview.innerHTML = '';
            Array.from(this.files).forEach(f => {
                if (f.type.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const item = document.createElement('div');
                        item.className = 'file-preview-item';
                        item.innerHTML = `<img src="${e.target.result}"><button type="button" class="remove-preview" onclick="this.parentElement.remove()">&times;</button>`;
                        preview.appendChild(item);
                    };
                    reader.readAsDataURL(f);
                } else {
                    const item = document.createElement('div');
                    item.className = 'file-preview-item';
                    item.style.cssText = item.style.cssText + ';display:flex;align-items:center;justify-content:center;background:#f0f4fa;font-size:11px;color:#3762c8;';
                    item.innerHTML = `<i class="fas fa-video" style="font-size:20px;"></i><button type="button" class="remove-preview" onclick="this.parentElement.remove()">&times;</button>`;
                    preview.appendChild(item);
                }
            });
        });
    }
}

function cancelUpdateForm() {
    const form = document.getElementById('addUpdateFormContainer');
    if (form) form.remove();
}

function handleUpdateFormSubmit(e) {
    e.preventDefault();
    const btn = this.querySelector('button[type="submit"]');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';

    const fd = new FormData(this);
    fetch('../api/progress_update_api.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification(data.message, 'success');
            cancelUpdateForm();
            loadUpdates(currentUpdatesReportId, currentUpdatesReportType);
        } else {
            showNotification(data.message || 'Failed to save update', 'error');
        }
    })
    .catch(e => {
        showNotification('Network error', 'error');
        console.error(e);
    })
    .finally(() => { btn.disabled = false; btn.innerHTML = orig; });
}

function editUpdate(updateId) {
    fetch(`../api/progress_update_api.php?action=get_update&id=${updateId}`)
        .then(r => r.json())
        .then(data => {
            if (data.success && data.update) {
                showUpdateForm(currentUpdatesReportId, currentUpdatesReportType, data.update);
            } else {
                showNotification('Failed to load update details', 'error');
            }
        })
        .catch(e => console.error(e));
}

function deleteUpdate(updateId) {
    if (!confirm('Delete this progress update? This cannot be undone.')) return;
    const fd = new FormData();
    fd.set('action', 'delete_update');
    fd.set('update_id', updateId);

    fetch('../api/progress_update_api.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showNotification('Update deleted', 'success');
            loadUpdates(currentUpdatesReportId, currentUpdatesReportType);
        } else {
            showNotification(data.message || 'Failed to delete', 'error');
        }
    })
    .catch(e => console.error(e));
}

function escapeHtml(t) { if (!t) return ''; const d = document.createElement('div'); d.textContent = t; return d.innerHTML; }
function escapeHtmlAttr(t) { if (!t) return ''; return t.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); }

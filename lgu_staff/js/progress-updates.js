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
        const path = m.file_path ? '../../' + m.file_path : '';
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
    const isEdit = updateData && updateData.id;

    document.getElementById('ufAction').value = isEdit ? 'edit_update' : 'create_update';
    document.getElementById('ufUpdateId').value = isEdit ? updateData.id : '';
    document.getElementById('ufReportId').value = reportId;
    document.getElementById('ufReportType').value = reportType;

    document.getElementById('ufTitle').value = isEdit ? (updateData.title || '') : '';
    document.getElementById('ufDescription').value = isEdit ? (updateData.description || '') : '';

    document.getElementById('updateFilePreviews').innerHTML = '';
    document.getElementById('updateFormModalTitle').innerHTML = isEdit
        ? '<i class="fas fa-pencil"></i> Edit Update'
        : '<i class="fas fa-plus-circle"></i> Add Progress Update';
    document.getElementById('ufSubmitBtn').innerHTML = isEdit
        ? '<i class="fas fa-save"></i> Save Changes'
        : '<i class="fas fa-save"></i> Post Update';

    // Existing media for edit mode
    const existingGroup = document.getElementById('existingMediaGroup');
    const mediaContainer = document.getElementById('existingUpdateMedia');
    mediaContainer.innerHTML = '';
    if (isEdit && updateData.media && updateData.media.length) {
        existingGroup.style.display = 'block';
        updateData.media.forEach(m => {
            const div = document.createElement('div');
            div.style.cssText = 'position:relative;width:80px;height:60px;border-radius:6px;overflow:hidden;border:1px solid rgba(55,98,200,0.15);';
            const isVideo = m.file_type === 'video';
            div.innerHTML = isVideo
                ? `<i class="fas fa-video" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:20px;color:#3762c8;opacity:0.5;"></i>`
                : `<img src="${escapeHtmlAttr('../../' + m.file_path)}" style="width:100%;height:100%;object-fit:cover;">`;
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.name = 'remove_media[]';
            cb.value = m.id;
            cb.style.cssText = 'position:absolute;top:3px;right:3px;width:16px;height:16px;cursor:pointer;';
            div.appendChild(cb);
            mediaContainer.appendChild(div);
        });
    } else {
        existingGroup.style.display = 'none';
    }

    openModal('updateFormModal');
}

function cancelUpdateForm() {
    document.getElementById('addUpdateForm').reset();
    document.getElementById('updateFilePreviews').innerHTML = '';
    document.getElementById('existingUpdateMedia').innerHTML = '';
    closeModal('updateFormModal');
}

function handleUpdateFormSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('ufSubmitBtn');
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

// Set up form submit and file preview listeners once
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('addUpdateForm');
    if (form) {
        form.addEventListener('submit', handleUpdateFormSubmit);
    }

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
                    item.style.cssText = 'display:flex;align-items:center;justify-content:center;background:#f0f4fa;font-size:11px;color:#3762c8;';
                    item.innerHTML = `<i class="fas fa-video" style="font-size:20px;"></i><button type="button" class="remove-preview" onclick="this.parentElement.remove()">&times;</button>`;
                    preview.appendChild(item);
                }
            });
        });
    }
});

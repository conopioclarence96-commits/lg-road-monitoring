/* Common Progress Updates Export Functions for Report Management and Road Monitoring */

function exportUpdatesToExcel() {
    const timelineEntries = document.querySelectorAll('.timeline-entry');
    if (timelineEntries.length === 0) {
        showNotification('No updates to export', 'error');
        return;
    }

    showNotification('Preparing document...', 'info');
    ensureExportReportDetails().then(function() {
        processImagesAndExport(timelineEntries);
    });
}

function ensureExportReportDetails() {
    var existing = getExportReportDetails() || {};
    if (prettyExportValue(existing.title) && (prettyExportValue(existing.description) || prettyExportValue(existing.location))) {
        currentUpdatesReportDetails = existing;
        return Promise.resolve();
    }
    var id = (typeof currentUpdatesReportId !== 'undefined') ? currentUpdatesReportId : null;
    var type = (typeof currentUpdatesReportType !== 'undefined') ? currentUpdatesReportType : '';
    var src = (typeof currentUpdatesReportSource !== 'undefined') ? String(currentUpdatesReportSource || '').toLowerCase() : '';
    if (!id) {
        if (existing && Object.keys(existing).length) currentUpdatesReportDetails = existing;
        return Promise.resolve();
    }
    var table = 'road_transportation_reports';
    if (src === 'infrastructure' || src === 'maintenance') table = 'road_maintenance_reports';
    else if (src === 'cimm' || src === 'external') table = 'cimm_verification_reports';
    var url = '../api/get_report_details.php?id=' + encodeURIComponent(id)
        + '&type=' + encodeURIComponent(type || 'transportation')
        + '&table=' + encodeURIComponent(table);
    return fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var report = (data && (data.report || (data.success && data.report))) ? data.report : null;
            if (data && data.success && report) {
                currentUpdatesReportDetails = Object.assign({}, existing, report, {
                    source: existing.source || src,
                    report_id: report.report_id || existing.report_id,
                    title: report.title || existing.title,
                    description: report.description || existing.description,
                    location: report.location || existing.location,
                    status: report.status || existing.status,
                    priority: report.priority || existing.priority,
                    severity: report.severity || existing.severity,
                    reporter_name: report.reporter_name || existing.reporter_name,
                    department: report.department || existing.department,
                    assigned_to: report.assigned_to || existing.assigned_to || existing.assignment_officer,
                    latitude: report.latitude || existing.latitude,
                    longitude: report.longitude || existing.longitude,
                    report_type: report.report_type || existing.report_type,
                    report_category: report.report_category || existing.report_category,
                    created_at: report.created_at || existing.created_at,
                    engineer: report.engineer || existing.engineer || existing.cimm_engineer_name,
                    budget_allocation: report.budget_allocation || existing.budget_allocation || existing.cimm_budget
                });
            } else if (Object.keys(existing).length) {
                currentUpdatesReportDetails = existing;
            }
        })
        .catch(function() {
            if (Object.keys(existing).length) currentUpdatesReportDetails = existing;
        });
}

function processImagesAndExport(timelineEntries) {
    const updates = [];
    let firstDate = null;
    let lastDate = null;
    let imageLoadPromises = [];
    
    timelineEntries.forEach(function(entry) {
        const dateText = entry.querySelector('.time')?.textContent.trim() || '';
        const title = entry.querySelector('.timeline-title')?.textContent.trim() || '';
        const description = entry.querySelector('.timeline-desc')?.textContent.trim() || '';
        const author = entry.querySelector('.admin-badge')?.textContent.trim() || '';
        
        // Extract images
        const images = [];
        const mediaItems = entry.querySelectorAll('.timeline-media-item');
        mediaItems.forEach(function(media) {
            const img = media.querySelector('img');
            if (img && img.src) {
                // Create promise to load and resize image
                const imagePromise = resizeImage(img.src, 200);
                imageLoadPromises.push(imagePromise);
                images.push(imagePromise);
            }
        });
        
        updates.push({
            date: dateText,
            title: title,
            description: description,
            author: author,
            images: images
        });
        
        // Track dates for summary
        if (!firstDate) firstDate = dateText;
        lastDate = dateText;
    });

    // Wait for all images to be resized
    Promise.all(imageLoadPromises)
        .then(function(resizedImages) {
            // Replace image promises with resized base64 strings
            let imageIndex = 0;
            updates.forEach(function(update) {
                for (let i = 0; i < update.images.length; i++) {
                    update.images[i] = resizedImages[imageIndex++];
                }
            });
            
            // Now generate the document
            generateDocument(updates, firstDate, lastDate);
        })
        .catch(function(error) {
            console.error('Image processing error:', error);
            // Fall back to document without images
            generateDocument(updates, firstDate, lastDate);
        });
}

function resizeImage(imageSrc, maxWidth) {
    return new Promise(function(resolve, reject) {
        const img = new Image();
        img.crossOrigin = 'Anonymous';
        img.onload = function() {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            
            // Calculate new dimensions
            const ratio = maxWidth / img.width;
            const newHeight = img.height * ratio;
            
            canvas.width = maxWidth;
            canvas.height = newHeight;
            
            // Draw resized image
            ctx.drawImage(img, 0, 0, maxWidth, newHeight);
            
            // Convert to base64
            resolve(canvas.toDataURL('image/jpeg', 0.8));
        };
        img.onerror = function() {
            resolve(null); // Return null if image fails to load
        };
        img.src = imageSrc;
    });
}

function escDoc(val) {
    if (typeof escapeHtml === 'function') return escapeHtml(String(val == null ? '' : val));
    return String(val == null ? '' : val)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function prettyExportValue(val) {
    if (val === null || val === undefined) return '';
    var s = String(val).trim();
    if (!s || s === '—' || s === '-' || s.toLowerCase() === 'null') return '';
    return s;
}

function formatExportBudget(val) {
    if (val === null || val === undefined || String(val).trim() === '' || String(val).toLowerCase() === 'null') {
        return '';
    }
    var n = parseFloat(val);
    if (!isFinite(n)) return '';
    return '₱ ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function prettyExportLabel(val) {
    var s = prettyExportValue(val);
    if (!s) return '';
    return s.replace(/[-_]/g, ' ').replace(/\b\w/g, function(c) { return c.toUpperCase(); });
}

function formatExportDate(val) {
    var s = prettyExportValue(val);
    if (!s) return '';
    var d = new Date(s);
    if (isNaN(d.getTime())) return s;
    return d.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function exportDetailRow(label, value) {
    var v = prettyExportValue(value);
    if (!v) return '';
    return '<tr><td class="lbl">' + escDoc(label) + '</td><td>' + escDoc(v) + '</td></tr>';
}

function buildCompactPairRows(pairs) {
    var html = '';
    var buf = [];
    function flush() {
        if (!buf.length) return;
        html += '<tr>';
        buf.forEach(function(p) {
            html += '<td class="lbl">' + escDoc(p[0]) + '</td><td>' + escDoc(p[1]).replace(/\r\n|\r|\n/g, '<br>') + '</td>';
        });
        if (buf.length === 1) html += '<td class="lbl"></td><td></td>';
        html += '</tr>';
        buf = [];
    }
    pairs.forEach(function(p) {
        if (!p) return;
        var always = (p[2] === 'always');
        if (!always && !prettyExportValue(p[1])) return;
        if (p[2] === 'full') {
            flush();
            html += '<tr><td class="lbl">' + escDoc(p[0]) + '</td><td colspan="3">' + escDoc(p[1]).replace(/\r\n|\r|\n/g, '<br>') + '</td></tr>';
            return;
        }
        buf.push(p);
        if (buf.length === 2) flush();
    });
    flush();
    return html;
}

function getExportReportDetails() {
    var details = (typeof currentUpdatesReportDetails !== 'undefined' && currentUpdatesReportDetails)
        ? currentUpdatesReportDetails
        : (window.currentUpdatesReportDetails || null);
    if (details && typeof details === 'object') {
        return details;
    }
    try {
        var row = document.querySelector('#recentReportsTable .report-table-row[data-id="' + currentUpdatesReportId + '"]');
        if (row && row.dataset.details) {
            return JSON.parse(row.dataset.details);
        }
    } catch (e) {}
    if (typeof currentArchiveRow === 'object' && currentArchiveRow) {
        return currentArchiveRow;
    }
    return {};
}

function buildExportDetailsTable(d) {
    var sourceLabels = {
        lgu: 'LGU Monitoring',
        citizen: 'Citizen',
        cimm: 'CIMM',
        infrastructure: 'Infrastructure Projects',
        external: 'CIMM',
        maintenance: 'Maintenance'
    };
    var sourceRaw = prettyExportValue(d.source || d.source_system || d.report_source || currentUpdatesReportSource);
    var sourceLabel = sourceLabels[(sourceRaw || '').toLowerCase()] || prettyExportLabel(sourceRaw);

    var assignment = '';
    if (prettyExportValue(d.assignment_officer)) {
        assignment = d.assignment_officer;
    } else if (prettyExportValue(d.assigned_to)) {
        assignment = d.assigned_to;
    } else if (prettyExportValue(d.assignment_status)) {
        assignment = (String(d.assignment_status).toLowerCase() === 'assigned') ? 'Assigned' : 'Unassigned';
    }

    var coords = '';
    var lat = prettyExportValue(d.latitude || d.coord_lat);
    var lng = prettyExportValue(d.longitude || d.coord_lng);
    if (lat && lng && lat !== '0' && lng !== '0') {
        coords = lat + ', ' + lng;
    }

    var cimmVerify = '';
    if (prettyExportValue(d.approval_status)) {
        cimmVerify = prettyExportLabel(d.approval_status);
    } else if (prettyExportValue(d.cimm_sync_status)) {
        cimmVerify = prettyExportLabel(d.cimm_sync_status);
    } else if (prettyExportValue(d.verification_status)) {
        cimmVerify = prettyExportLabel(d.verification_status);
    }

    var description = prettyExportValue(d.description || d.issue);
    var rows = buildCompactPairRows([
        ['Source', sourceLabel],
        ['Status', prettyExportLabel(d.status || currentUpdatesReportStatus)],
        ['Priority', prettyExportLabel(d.priority)],
        ['Severity', prettyExportLabel(d.severity)],
        ['Category', prettyExportLabel(d.report_category)],
        ['Type', prettyExportLabel(d.report_type)],
        ['Department', prettyExportLabel(d.department)],
        ['Assignment', assignment],
        ['Engineer', prettyExportValue(d.engineer || d.cimm_engineer_name)],
        ['Budget Allocation', formatExportBudget(
            (d.budget_allocation !== null && d.budget_allocation !== undefined && d.budget_allocation !== '')
                ? d.budget_allocation
                : d.cimm_budget
        ), 'always'],
        ['Reported By', prettyExportValue(d.reporter_name)],
        ['Created', formatExportDate(d.created_at || d.created_date || d.submitted_at)],
        ['CIMM Verification', cimmVerify],
        ['Verified By', prettyExportValue(d.cimm_verified_by)],
        ['Verified At', formatExportDate(d.cimm_verified_at)],
        ['Creator', prettyExportValue(d.creator_full_name)],
        ['Contact', prettyExportValue(d.creator_phone)],
        ['Email', prettyExportValue(d.creator_email)],
        ['Location', prettyExportValue(d.location), 'full'],
        ['Coordinates', coords, 'full'],
        ['Description', description, 'full']
    ]);

    if (!rows) return '';
    return `
        <h2>Report Details</h2>
        <table class="details-table">
            ${rows}
        </table>
    `;
}

function generateDocument(updates, firstDate, lastDate) {
    try {
        const totalUpdates = updates.length;
        const timeTaken = firstDate && lastDate ? calculateDaysBetween(firstDate, lastDate) : 0;
        const details = getExportReportDetails();
        const displayId = prettyExportValue(details.report_id) || String(currentUpdatesReportId || '');
        const displayTitle = prettyExportValue(details.title || details.infrastructure);
        const exportedOn = new Date().toLocaleDateString('en-US', {
            month: 'long', day: 'numeric', year: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });

        let htmlContent = `
        <html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word'>
        <head>
        <meta charset="utf-8">
        <title>Progress Updates Report</title>
        <style>
            body { font-family: 'Calibri', Arial, sans-serif; font-size: 10pt; line-height: 1.25; margin: 12px 16px; }
            h1 { color: #2E74B5; font-size: 16pt; text-align: center; margin: 0 0 4px 0; }
            h2 { color: #2E74B5; font-size: 12pt; margin: 12px 0 6px 0; border-bottom: 1px solid #2E74B5; padding-bottom: 3px; }
            .report-info { text-align: center; color: #666; margin: 0; font-size: 9pt; }
            .report-title { text-align: center; font-size: 12pt; font-weight: bold; color: #1f2937; margin: 2px 0 8px 0; }
            .details-table, .summary-table { border-collapse: collapse; width: 100%; margin: 0 0 8px 0; font-size: 10pt; }
            .details-table td, .summary-table td { border: 1px solid #d0d7de; padding: 3px 8px; vertical-align: top; }
            .details-table td.lbl, .summary-table td.lbl { background-color: #f3f6f9; font-weight: bold; width: 16%; color: #334155; white-space: nowrap; }
            .update-entry { margin: 0 0 8px 0; padding: 8px 10px; background-color: #f8f9fa; border-left: 3px solid #2E74B5; }
            .update-header { color: #2E74B5; font-weight: bold; font-size: 10.5pt; margin: 0 0 2px 0; }
            .update-author { color: #666; font-style: italic; font-size: 9pt; margin: 0 0 4px 0; }
            .update-description { margin: 0; }
            .update-images { margin-top: 6px; }
            .update-images img { width: 160px; height: auto; margin: 3px; border: 1px solid #ddd; }
            .image-count { color: #666; font-style: italic; font-size: 9pt; }
        </style>
        </head>
        <body>
            <h1>Progress Updates Report</h1>
            <p class="report-info">Report #${escDoc(displayId)} &nbsp;&middot;&nbsp; Exported ${escDoc(exportedOn)}</p>
            ${displayTitle ? `<p class="report-title">${escDoc(displayTitle)}</p>` : '<div style="height:6px;"></div>'}

            ${buildExportDetailsTable(details)}

            <h2>Project Summary</h2>
            <table class="summary-table">
                <tr>
                    <td class="lbl">Start</td><td>${escDoc(firstDate || 'N/A')}</td>
                    <td class="lbl">End</td><td>${escDoc(lastDate || 'N/A')}</td>
                </tr>
                <tr>
                    <td class="lbl">Updates</td><td>${totalUpdates}</td>
                    <td class="lbl">Duration</td><td>${timeTaken} days</td>
                </tr>
            </table>

            <h2>Progress Timeline</h2>
        `;

        updates.forEach(function(update) {
            htmlContent += `
            <div class="update-entry">
                <div class="update-header">${escDoc(update.date)} - ${escDoc(update.title || 'Update')}</div>
                <div class="update-author">By: ${escDoc(update.author)}</div>
                <div class="update-description">${escDoc(update.description || 'No description').replace(/\r\n|\r|\n/g, '<br>')}</div>
                <div class="update-images">
            `;

            if (update.images.length > 0) {
                update.images.forEach(function(imgData) {
                    if (imgData) {
                        htmlContent += `<img src="${imgData}" alt="Update image" />`;
                    }
                });
            } else {
                htmlContent += `<div class="image-count">No images attached</div>`;
            }

            htmlContent += `
                </div>
            </div>
            `;
        });

        htmlContent += `
        </body>
        </html>
        `;

        const blob = new Blob(['\ufeff', htmlContent], {
            type: 'application/msword'
        });

        const fileLabel = prettyExportValue(details.report_id)
            || String(currentUpdatesReportId || 'Report');
        const fileName = ('Report ' + fileLabel).replace(/[<>:"/\\|?*\u0000-\u001f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120) + '.doc';
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = fileName;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);

        showNotification('Document exported successfully', 'success');

    } catch (error) {
        console.error('Export error:', error);
        showNotification('Failed to export document', 'error');
    }
}

function calculateDaysBetween(dateStr1, dateStr2) {
    try {
        const date1 = new Date(dateStr1);
        const date2 = new Date(dateStr2);
        const diffTime = Math.abs(date2 - date1);
        const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        return diffDays;
    } catch (e) {
        return 0;
    }
}

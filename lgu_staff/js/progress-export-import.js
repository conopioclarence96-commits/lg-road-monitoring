/**
 * Parse Progress Updates Word exports (.doc HTML) from
 * road_transportation_monitoring.php / archive.php (exportUpdatesToExcel).
 * Used by public_transparency.php to pre-fill the Add New Project form.
 */
(function (global) {
    'use strict';

    var MARKER_TITLE = 'Progress Updates Report';
    var MARKER_TIMELINE = 'Progress Timeline';

    function stripBom(text) {
        return String(text || '').replace(/^\ufeff/, '');
    }

    function parseHtmlDocument(html) {
        return new DOMParser().parseFromString(stripBom(html), 'text/html');
    }

    function isValidExportDocument(html) {
        try {
            var doc = parseHtmlDocument(html);
            var h1 = doc.querySelector('h1');
            if (!h1 || h1.textContent.trim().indexOf(MARKER_TITLE) === -1) {
                return false;
            }
            var headings = doc.querySelectorAll('h2');
            var hasTimeline = false;
            for (var i = 0; i < headings.length; i++) {
                if (headings[i].textContent.trim().indexOf(MARKER_TIMELINE) !== -1) {
                    hasTimeline = true;
                    break;
                }
            }
            if (!hasTimeline) return false;
            if (!doc.querySelector('.details-table') && !doc.querySelector('.summary-table')) {
                return false;
            }
            return true;
        } catch (e) {
            return false;
        }
    }

    function cellPlainText(el) {
        if (!el) return '';
        return el.innerHTML
            .replace(/<br\s*\/?>/gi, '\n')
            .replace(/<[^>]+>/g, '')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"')
            .trim();
    }

    function normalizeLabel(text) {
        return String(text || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function getTableValue(doc, tableClass, label) {
        var rows = doc.querySelectorAll('.' + tableClass + ' tr');
        var want = normalizeLabel(label);
        for (var i = 0; i < rows.length; i++) {
            // Each row may contain two label/value pairs (compact export layout).
            var labels = rows[i].querySelectorAll('td.lbl');
            for (var j = 0; j < labels.length; j++) {
                var lbl = labels[j];
                if (normalizeLabel(lbl.textContent) !== want) continue;
                var valueCell = lbl.nextElementSibling;
                if (valueCell && valueCell.tagName === 'TD') {
                    return cellPlainText(valueCell);
                }
            }
        }
        return '';
    }

    function getFirstTableValue(doc, tableClass, labelCandidates) {
        for (var i = 0; i < labelCandidates.length; i++) {
            var val = getTableValue(doc, tableClass, labelCandidates[i]);
            if (val) return val;
        }
        return '';
    }

    function parseExportDate(str) {
        var s = String(str || '').trim();
        if (!s || s === 'N/A' || s === '—' || s === '-') return '';
        var d = new Date(s);
        if (isNaN(d.getTime())) return '';
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var day = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + day;
    }

    function parseBudget(str) {
        var s = String(str || '').trim();
        if (!s) return '';
        // Exported values use formats like "₱ 1,234.56" or "₱1,234.56".
        var n = parseFloat(s.replace(/[^\d.-]/g, ''));
        return isFinite(n) && n >= 0 ? n.toFixed(2) : '';
    }

    function normalizeDataImageUrl(src) {
        if (!src) return null;
        var s = String(src)
            .replace(/&amp;/g, '&')
            .replace(/&#34;/g, '"')
            .trim();
        var dataIdx = s.toLowerCase().indexOf('data:image');
        if (dataIdx < 0) return null;
        s = s.slice(dataIdx);
        // Keep the data: prefix intact; strip whitespace only from the payload
        // so Word/HTML line-wrapped base64 still decodes.
        var comma = s.indexOf(',');
        if (comma < 0) return null;
        var header = s.slice(0, comma).replace(/\s+/g, '');
        var payload = s.slice(comma + 1).replace(/\s+/g, '');
        if (!payload) return null;
        if (!/^data:image\/[a-z0-9.+-]+(;base64)?$/i.test(header)) return null;
        if (header.toLowerCase().indexOf(';base64') === -1) {
            header += ';base64';
        }
        return header + ',' + payload;
    }

    function imagesFromUpdateHtml(html) {
        var out = [];
        var seen = {};
        var chunk = String(html || '');
        var re = /(?:src|SRC)\s*=\s*(["'])(data:image[\s\S]*?)\1/g;
        var m;
        while ((m = re.exec(chunk))) {
            var src = normalizeDataImageUrl(m[2]);
            if (src && !seen[src]) {
                seen[src] = true;
                out.push(src);
            }
        }
        if (!out.length) {
            re = /data:image\/[a-zA-Z0-9.+-]+;base64,[A-Za-z0-9+/=\s]+/g;
            while ((m = re.exec(chunk))) {
                var src2 = normalizeDataImageUrl(m[0]);
                if (src2 && !seen[src2]) {
                    seen[src2] = true;
                    out.push(src2);
                }
            }
        }
        return out;
    }

    function imagesFromUpdateEntry(entry) {
        if (!entry) return [];
        var box = entry.querySelector('.update-images') || entry;
        var fromHtml = imagesFromUpdateHtml(box.innerHTML || '');
        if (fromHtml.length) return fromHtml;
        var imgs = box.querySelectorAll('img');
        var out = [];
        for (var i = 0; i < imgs.length; i++) {
            var src = normalizeDataImageUrl(
                imgs[i].getAttribute('src') || imgs[i].getAttribute('data-src') || ''
            );
            if (src) out.push(src);
        }
        return out;
    }

    function parseUpdateHeaderDate(headerText) {
        var parts = String(headerText || '').trim().split(' - ');
        if (!parts.length) return '';
        return parseExportDate(parts[0].trim());
    }

    function parseUpdateHeaderTimestamp(headerText) {
        var parts = String(headerText || '').trim().split(' - ');
        var raw = parts.length ? parts[0].trim() : '';
        if (!raw) return 0;
        var d = new Date(raw);
        return isNaN(d.getTime()) ? 0 : d.getTime();
    }

    function getProgressTimelineEntries(doc) {
        var headings = doc.querySelectorAll('h2');
        var timelineH2 = null;
        for (var i = 0; i < headings.length; i++) {
            if (headings[i].textContent.trim().indexOf(MARKER_TIMELINE) !== -1) {
                timelineH2 = headings[i];
                break;
            }
        }
        var entries = [];
        if (timelineH2) {
            var node = timelineH2.nextElementSibling;
            while (node && node.tagName !== 'H2') {
                if (node.classList && node.classList.contains('update-entry')) {
                    entries.push(node);
                } else if (node.querySelectorAll) {
                    var nested = node.querySelectorAll('.update-entry');
                    for (var n = 0; n < nested.length; n++) entries.push(nested[n]);
                }
                node = node.nextElementSibling;
            }
        }
        if (!entries.length) {
            entries = Array.prototype.slice.call(doc.querySelectorAll('.update-entry'));
        }
        return entries;
    }

    function collectOrderedUpdatePhotos(entries) {
        var rows = [];
        for (var i = 0; i < entries.length; i++) {
            var header = entries[i].querySelector('.update-header');
            rows.push({
                index: i,
                ts: header ? parseUpdateHeaderTimestamp(header.textContent) : 0,
                photos: imagesFromUpdateEntry(entries[i])
            });
        }
        rows.sort(function (a, b) {
            if (a.ts && b.ts && a.ts !== b.ts) return a.ts - b.ts;
            if (a.ts && !b.ts) return -1;
            if (!a.ts && b.ts) return 1;
            return a.index - b.index;
        });
        var photos = [];
        for (var r = 0; r < rows.length; r++) {
            for (var p = 0; p < rows[r].photos.length; p++) {
                photos.push(rows[r].photos[p]);
            }
        }
        return photos;
    }

    function collectTimelineDates(entries) {
        var dates = [];
        for (var i = 0; i < entries.length; i++) {
            var header = entries[i].querySelector('.update-header');
            if (!header) continue;
            var iso = parseUpdateHeaderDate(header.textContent);
            if (iso) dates.push(iso);
        }
        dates.sort();
        return dates;
    }

    function extractReportId(doc) {
        var info = doc.querySelector('.report-info');
        if (info) {
            var m = cellPlainText(info).match(/Report\s*#\s*([A-Za-z0-9-]+)/);
            if (m) return m[1].trim();
        }
        return '';
    }

    function parseProgressExport(html) {
        if (!isValidExportDocument(html)) {
            throw new Error('This file is not a valid Progress Updates export (.doc from Road Monitoring or Archive).');
        }

        var doc = parseHtmlDocument(html);
        var reportId = extractReportId(doc);
        var titleEl = doc.querySelector('.report-title');
        var title = titleEl ? cellPlainText(titleEl) : '';
        if (!title) {
            title = getTableValue(doc, 'details-table', 'Infrastructure') || '';
        }

        var description = getTableValue(doc, 'details-table', 'Description');
        var location = getTableValue(doc, 'details-table', 'Location');
        var engineer = getFirstTableValue(doc, 'details-table', [
            'Engineer',
            'Engineers',
            'CIMM Engineer',
            'Assigned Engineer'
        ]);
        var budgetRaw = getFirstTableValue(doc, 'details-table', [
            'Budget Allocation',
            'CIMM Budget Allocation',
            'Budget',
            'Est. Cost'
        ]);

        var completionDate = '';
        var entries = getProgressTimelineEntries(doc);
        var orderedPhotos = collectOrderedUpdatePhotos(entries);
        if (!orderedPhotos.length) {
            var timelineHtml = html.split('Progress Timeline')[1] || '';
            var rawBlocks = timelineHtml.split(/class=["']update-entry["']/i);
            for (var b = 1; b < rawBlocks.length; b++) {
                var blockPhotos = imagesFromUpdateHtml(rawBlocks[b]);
                for (var bp = 0; bp < blockPhotos.length; bp++) {
                    orderedPhotos.push(blockPhotos[bp]);
                }
            }
            if (!orderedPhotos.length) {
                orderedPhotos = imagesFromUpdateHtml(timelineHtml || html);
            }
        }
        var beforeImage = orderedPhotos.length ? orderedPhotos[0] : null;
        var afterImage = orderedPhotos.length ? orderedPhotos[orderedPhotos.length - 1] : null;
        var timelineDates = collectTimelineDates(entries);

        for (var i = 0; i < entries.length; i++) {
            var header = entries[i].querySelector('.update-header');
            if (header && !completionDate) {
                var headerText = header.textContent.trim();
                var parts = headerText.split(' - ');
                if (parts.length >= 2) {
                    var entryTitle = parts.slice(1).join(' - ').trim().toLowerCase();
                    if (entryTitle === 'completed') {
                        completionDate = parseExportDate(parts[0].trim());
                    }
                }
            }
        }

        if (!completionDate) {
            completionDate = parseExportDate(getTableValue(doc, 'summary-table', 'End'));
        }

        var proposedStartDate = parseExportDate(getFirstTableValue(doc, 'details-table', [
            'Proposed Start Date',
            'Start Date',
            'CIMM Starting Date',
            'Planned Start Date'
        ]));
        var proposedEndDate = parseExportDate(getFirstTableValue(doc, 'details-table', [
            'Proposed End Date',
            'End Date',
            'Due Date',
            'CIMM Estimated End Date',
            'Planned End Date'
        ]));

        var actualStartDate = timelineDates.length ? timelineDates[0] : '';
        var actualEndDate = timelineDates.length ? timelineDates[timelineDates.length - 1] : '';
        if (!actualStartDate) {
            actualStartDate = parseExportDate(getTableValue(doc, 'summary-table', 'Start'));
        }
        if (!actualEndDate) {
            actualEndDate = parseExportDate(getTableValue(doc, 'summary-table', 'End'));
        }

        return {
            reportId: reportId,
            title: title,
            description: description,
            location: location,
            completionDate: completionDate,
            proposedStartDate: proposedStartDate,
            proposedEndDate: proposedEndDate,
            actualStartDate: actualStartDate,
            actualEndDate: actualEndDate,
            cost: parseBudget(budgetRaw),
            completedBy: engineer,
            beforeImage: beforeImage,
            afterImage: afterImage
        };
    }

    function readExportFile(file) {
        return new Promise(function (resolve, reject) {
            if (!file) {
                reject(new Error('No file selected.'));
                return;
            }
            var name = (file.name || '').toLowerCase();
            if (!name.endsWith('.doc')) {
                reject(new Error('Please select a Progress Updates export file (.doc).'));
                return;
            }
            var reader = new FileReader();
            reader.onload = function (ev) {
                try {
                    resolve(parseProgressExport(ev.target.result));
                } catch (err) {
                    reject(err);
                }
            };
            reader.onerror = function () {
                reject(new Error('Could not read the selected file.'));
            };
            reader.readAsText(file);
        });
    }

    global.ProgressExportImport = {
        isValidExportDocument: isValidExportDocument,
        parseProgressExport: parseProgressExport,
        readExportFile: readExportFile
    };
})(typeof window !== 'undefined' ? window : this);

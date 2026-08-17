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

    function firstImageFromEntry(entry) {
        if (!entry) return null;
        var img = entry.querySelector('.update-images img');
        if (!img) return null;
        var src = img.getAttribute('src') || '';
        if (src.indexOf('data:image') === 0) return src;
        return null;
    }

    function parseUpdateHeaderDate(headerText) {
        var parts = String(headerText || '').trim().split(' - ');
        if (!parts.length) return '';
        return parseExportDate(parts[0].trim());
    }

    function collectTimelineDates(doc) {
        var dates = [];
        var entries = doc.querySelectorAll('.update-entry');
        for (var i = 0; i < entries.length; i++) {
            var header = entries[i].querySelector('.update-header');
            if (!header) continue;
            var iso = parseUpdateHeaderDate(header.textContent);
            if (iso) dates.push(iso);
        }
        return dates;
    }

    function parseProgressExport(html) {
        if (!isValidExportDocument(html)) {
            throw new Error('This file is not a valid Progress Updates export (.doc from Road Monitoring or Archive).');
        }

        var doc = parseHtmlDocument(html);
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
        var entries = doc.querySelectorAll('.update-entry');
        var beforeImage = null;
        var afterImage = null;
        var timelineDates = collectTimelineDates(doc);

        for (var i = 0; i < entries.length; i++) {
            var imgSrc = firstImageFromEntry(entries[i]);
            if (imgSrc) {
                if (!beforeImage) beforeImage = imgSrc;
                afterImage = imgSrc;
            }
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

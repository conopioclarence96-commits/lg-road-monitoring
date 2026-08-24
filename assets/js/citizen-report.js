/**
 * Citizen report modal (index.php) - map, OTP verification and AJAX submission.
 * Depends on: Leaflet, Turf.js, lgu_staff/js/tomtom-services.js and
 * assets/js/qc-boundary.js (window.QC_GEOJSON). Runtime config is injected by
 * index.php into window.LG_ASSET_CONFIG before this file loads.
 */
(function () {
    'use strict';

    var CONFIG = window.LG_ASSET_CONFIG || {};
    var TOMTOM_API_KEY = CONFIG.TOMTOM_API_KEY || '';
    var CITIZEN_API = CONFIG.CITIZEN_API || 'lgu_staff/pages/api/citizen_report.php';
    var QC_GEOJSON = window.QC_GEOJSON || null;

    var citizenMap = null;
    var citizenPin = null;
    var otpVerified = false;
    var photoFiles = [];
    // Prevent re-entrant change handling when syncing/clearing the file input.
    var photoInputSyncing = false;
    var qcDistrictsGeoJSON = null;
    var districtsLoadPromise = null;

    var modalEl = document.getElementById('citizenReportModal');
    var formEl = document.getElementById('citizenReportForm');

    function escapeHtml(str) {
        return String(str == null ? '' : str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function loadQcDistricts() {
        if (qcDistrictsGeoJSON) return Promise.resolve(qcDistrictsGeoJSON);
        if (districtsLoadPromise) return districtsLoadPromise;
        districtsLoadPromise = fetch('lgu_staff/pages/api/qc_districts.geojson')
            .then(function (r) { return r.json(); })
            .then(function (data) {
                qcDistrictsGeoJSON = data;
                return data;
            })
            .catch(function () {
                districtsLoadPromise = null;
                return null;
            });
        return districtsLoadPromise;
    }

    function pointInPolygonCoords(lat, lng, coords) {
        var inside = false;
        var ring = coords[0];
        for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            var xi = ring[i][1], yi = ring[i][0];
            var xj = ring[j][1], yj = ring[j][0];
            if ((yi > lng) !== (yj > lng) && lat < (xj - xi) * (lng - yi) / (yj - yi) + xi) {
                inside = !inside;
            }
        }
        return inside;
    }

    function detectDistrict(lat, lng) {
        if (!qcDistrictsGeoJSON || !qcDistrictsGeoJSON.features) return null;
        var features = qcDistrictsGeoJSON.features;
        for (var i = 0; i < features.length; i++) {
            var feature = features[i];
            if (feature.geometry.type === 'Polygon') {
                if (pointInPolygonCoords(lat, lng, feature.geometry.coordinates)) {
                    return feature.properties;
                }
            } else if (feature.geometry.type === 'MultiPolygon') {
                for (var p = 0; p < feature.geometry.coordinates.length; p++) {
                    if (pointInPolygonCoords(lat, lng, feature.geometry.coordinates[p])) {
                        return feature.properties;
                    }
                }
            }
        }
        var bestDist = Infinity;
        var bestMatch = null;
        for (var f = 0; f < features.length; f++) {
            var feat = features[f];
            if (!feat.properties._centroid) {
                var coords = feat.geometry.type === 'Polygon'
                    ? feat.geometry.coordinates[0]
                    : feat.geometry.coordinates[0][0];
                var slng = 0, slat = 0, cnt = 0;
                for (var c = 0; c < coords.length; c++) {
                    slng += coords[c][0];
                    slat += coords[c][1];
                    cnt++;
                }
                feat.properties._centroid = { lng: slng / cnt, lat: slat / cnt };
            }
            var cen = feat.properties._centroid;
            var dx = lng - cen.lng, dy = lat - cen.lat;
            var dist = dx * dx + dy * dy;
            if (dist < bestDist) {
                bestDist = dist;
                bestMatch = feat.properties;
            }
        }
        return bestMatch;
    }

    function populateCitizenLocationInfo(lat, lng, districtProps, addressData, isLoading) {
        var infoPanel = document.getElementById('cr-location-info');
        var detailsEl = document.getElementById('cr-location-details');
        var loadingBadge = document.getElementById('cr-loading-badge');
        var html = '';

        if (districtProps) {
            var dName = districtProps.district_name || districtProps.district || '';
            document.getElementById('crDistrict').value = dName;
            html += '<span class="gis-field-tag"><span class="gis-tag-label">District:</span> ' + escapeHtml(dName) + '</span>';
        } else {
            document.getElementById('crDistrict').value = '';
            html += '<span class="gis-field-tag" style="background:rgba(220,53,69,0.1);color:#721c24;"><span class="gis-tag-label">District:</span> Not detected</span>';
        }

        var barangay = '';
        var street = '';
        var fullAddress = '';
        var municipality = '';
        if (addressData) {
            var addr = addressData.address || {};
            barangay = addr.subdivision || addr.municipalitySubdivision || addr.neighbourhood || '';
            street = addr.street || '';
            municipality = addr.municipality || '';
            var houseNum = addr.houseNumber || '';
            if (houseNum && street) {
                fullAddress = houseNum + ' ' + street;
            } else if (street) {
                fullAddress = street;
            } else if (addr.freeformAddress) {
                fullAddress = addr.freeformAddress;
            }
        }
        document.getElementById('crBarangay').value = barangay;
        document.getElementById('crStreet').value = street;
        var addressParts = [fullAddress, barangay, municipality, 'Quezon City'].filter(Boolean);
        document.getElementById('crAddress').value = addressParts.join(', ');

        if (barangay) {
            html += '<span class="gis-field-tag"><span class="gis-tag-label">Barangay:</span> ' + escapeHtml(barangay) + '</span>';
        }
        if (street) {
            html += '<span class="gis-field-tag"><span class="gis-tag-label">Street:</span> ' + escapeHtml(street) + '</span>';
        }
        if (municipality) {
            html += '<span class="gis-field-tag"><span class="gis-tag-label">Municipality:</span> ' + escapeHtml(municipality) + '</span>';
        }
        if (!isLoading && !fullAddress && !barangay && !street) {
            html += '<span style="font-size:11px;color:#999;">Address details unavailable for this pin location.</span>';
            document.getElementById('crAddress').value = lat.toFixed(5) + ', ' + lng.toFixed(5) + ', Quezon City';
        }

        detailsEl.innerHTML = html;
        infoPanel.style.display = 'block';
        if (loadingBadge) loadingBadge.style.display = isLoading ? 'inline' : 'none';
    }

    function analyzeCitizenPinnedLocation(lat, lng) {
        var infoPanel = document.getElementById('cr-location-info');
        var loadingBadge = document.getElementById('cr-loading-badge');
        infoPanel.style.display = 'block';
        if (loadingBadge) loadingBadge.style.display = 'inline';

        function runAnalysis() {
            var districtProps = detectDistrict(lat, lng);
            populateCitizenLocationInfo(lat, lng, districtProps, null, true);

            if (!window.TomTomServices || !window.TomTomServices.reverseGeocodeOrbis) {
                populateCitizenLocationInfo(lat, lng, districtProps, null, false);
                return;
            }

            window.TomTomServices.reverseGeocodeOrbis(lat, lng).then(function (data) {
                var geocodeData = (data && data.data && data.data.results && data.data.results[0]) || null;
                populateCitizenLocationInfo(lat, lng, districtProps, geocodeData, false);

                if (citizenPin && geocodeData) {
                    var addr = geocodeData.address || {};
                    var parts = [
                        addr.street && addr.houseNumber ? addr.houseNumber + ' ' + addr.street : (addr.street || ''),
                        addr.municipality || '',
                        addr.countrySubdivision || '',
                        addr.postalCode || ''
                    ].filter(Boolean);
                    var popupHtml = '<b>' + escapeHtml(parts.join(', ') || (lat.toFixed(5) + ', ' + lng.toFixed(5))) + '</b>'
                        + (districtProps ? '<br><small style="color:#10b981;">' + escapeHtml(districtProps.district_name || districtProps.district || '') + '</small>' : '');
                    citizenPin.bindPopup(popupHtml).openPopup();
                }
            }).catch(function () {
                populateCitizenLocationInfo(lat, lng, districtProps, null, false);
            });
        }

        loadQcDistricts().then(runAnalysis);
    }

    /**
     * Same uploaded photo/file identity for this report.
     * Uses name + size + type only — not lastModified — so a re-selected or
     * DataTransfer-cloned File of the same photo is not treated as a new one.
     */
    function isSamePhotoFile(a, b) {
        if (!a || !b) return false;
        return a.name === b.name
            && a.size === b.size
            && (a.type || '') === (b.type || '');
    }

    function photoAlreadySelected(file) {
        return photoFiles.some(function (f) { return isSamePhotoFile(f, file); });
    }

    /** Keep each distinct photo only once in the in-memory list. */
    function dedupePhotoFiles(files) {
        var unique = [];
        (files || []).forEach(function (file) {
            if (!file || !file.name) return;
            if (!unique.some(function (f) { return isSamePhotoFile(f, file); })) {
                unique.push(file);
            }
        });
        return unique;
    }

    function csrfToken() {
        var input = formEl ? formEl.querySelector('input[name="csrf_token"]') : null;
        return input ? input.value : '';
    }

    function appendCsrf(fd) {
        fd.append('csrf_token', csrfToken());
    }

    function isInsideQC(lat, lng) {
        if (!QC_GEOJSON || !QC_GEOJSON.coordinates) return false;
        try {
            var pt = turf.point([lng, lat]);
            var poly = turf.multiPolygon(QC_GEOJSON.coordinates);
            return turf.booleanPointInPolygon(pt, poly);
        } catch (e) {
            return false;
        }
    }

    function showCrStatus(msg, type) {
        var el = document.getElementById('crOtpStatus');
        if (!el) return;
        el.innerHTML = msg;
        el.className = 'cr-status ' + type;
        el.style.display = 'block';
    }

    var qcVisiblePolygon = null;

    function initCitizenMap() {
        var latlngs = QC_GEOJSON.coordinates[0][0].map(function (c) { return [c[1], c[0]]; });
        var bounds = L.latLngBounds(latlngs);

        citizenMap = L.map('citizenMap', {
            maxBounds: bounds.pad(0.05),
            maxBoundsViscosity: 1.0
        }).setView([14.651417, 121.04917], 14);

        L.tileLayer('https://api.tomtom.com/map/1/tile/basic/main/{z}/{x}/{y}.png?view=Unified&key=' + TOMTOM_API_KEY, {
            attribution: '&copy; TomTom',
            maxZoom: 18
        }).addTo(citizenMap);

        qcVisiblePolygon = L.polygon(latlngs, {
            color: '#2a5298',
            weight: 2,
            fill: true,
            fillColor: '#2a5298',
            fillOpacity: 0.08,
            opacity: 0.7
        }).addTo(citizenMap);

        citizenMap.on('click', function (e) {
            var lat = e.latlng.lat;
            var lng = e.latlng.lng;
            if (!isInsideQC(lat, lng)) {
                showCrStatus('Reports can only be submitted within Quezon City.', 'error');
                return;
            }
            placeCitizenPin(lat, lng);
        });
    }

    function placeCitizenPin(lat, lng) {
        if (!isInsideQC(lat, lng)) {
            showCrStatus('Reports can only be submitted within Quezon City.', 'error');
            return;
        }
        if (citizenPin) citizenMap.removeLayer(citizenPin);
        citizenPin = L.marker([lat, lng], {
            draggable: true,
            title: 'Pinned location',
            alt: 'Pinned location'
        }).addTo(citizenMap);
        document.getElementById('crLat').value = lat.toFixed(6);
        document.getElementById('crLng').value = lng.toFixed(6);
        document.getElementById('citizenMap').classList.add('has-pin');
        document.getElementById('crOtpStatus').style.display = 'none';

        analyzeCitizenPinnedLocation(lat, lng);

        citizenPin.on('dragend', function () {
            var pos = citizenPin.getLatLng();
            if (!isInsideQC(pos.lat, pos.lng)) {
                citizenPin.setLatLng([lat, lng]);
                showCrStatus('Reports can only be submitted within Quezon City.', 'error');
                return;
            }
            document.getElementById('crLat').value = pos.lat.toFixed(6);
            document.getElementById('crLng').value = pos.lng.toFixed(6);
            analyzeCitizenPinnedLocation(pos.lat, pos.lng);
        });
    }

    function citizenMapSearch() {
        var q = document.getElementById('citizenMapSearchInput').value.trim();
        if (!q) return;
        var resultsDiv = document.getElementById('citizenMapSearchResults');
        if (!window.TomTomServices) return;
        window.TomTomServices.poiSearch(q, { limit: 8 }).then(function (data) {
            if (!data.success || !data.data || !data.data.results) {
                resultsDiv.style.display = 'none';
                return;
            }
            var results = data.data.results;
            resultsDiv.innerHTML = results.map(function (r) {
                var pos = r.position || {};
                return '<div class="gis-search-result-item" data-lat="' + (pos.lat || 0) + '" data-lng="' + (pos.lon || 0) + '">' +
                    '<i class="fas fa-map-pin" style="color:#3762c8;margin-right:6px;"></i>' + (r.poi && r.poi.name || (r.address && r.address.freeformAddress) || 'Unknown') +
                    '<small>' + ((r.address && r.address.freeformAddress) || '') + '</small></div>';
            }).join('');
            resultsDiv.style.display = 'block';
        });
    }

    function citizenMapSelectResult(lat, lng) {
        document.getElementById('citizenMapSearchResults').style.display = 'none';
        if (!isInsideQC(lat, lng)) {
            showCrStatus('Selected location is outside Quezon City.', 'error');
            return;
        }
        citizenMap.setView([lat, lng], 15);
        placeCitizenPin(lat, lng);
    }

    // Photo selection + previews
    function renderPhotoPreviews() {
        var container = document.getElementById('photoPreview');
        container.innerHTML = '';
        photoFiles.forEach(function (file, index) {
            var reader = new FileReader();
            var wrapper = document.createElement('div');
            wrapper.className = 'photo-preview-item';
            wrapper.setAttribute('role', 'group');
            wrapper.setAttribute('aria-label', 'Photo preview ' + (index + 1));
            wrapper.innerHTML = '<button type="button" class="photo-delete-btn" data-index="' + index + '" aria-label="Remove photo">\u00d7</button>';
            var img = document.createElement('img');
            reader.onload = function (e) {
                img.src = e.target.result;
                img.alt = 'Selected photo ' + (index + 1);
            };
            reader.readAsDataURL(file);
            wrapper.prepend(img);
            container.appendChild(wrapper);
        });

        document.getElementById('fileCount').textContent = photoFiles.length + ' file(s) selected';

        document.querySelectorAll('.photo-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var idx = parseInt(this.dataset.index, 10);
                photoFiles.splice(idx, 1);
                renderPhotoPreviews();
                document.getElementById('fileCount').textContent = photoFiles.length + ' file(s) selected';
                syncPhotoFiles();
            });
        });
    }

    function syncPhotoFiles() {
        var input = document.getElementById('crPhotos');
        if (!input) return;
        photoInputSyncing = true;
        try {
            var dt = new DataTransfer();
            photoFiles.forEach(function (file) { dt.items.add(file); });
            input.files = dt.files;
        } finally {
            // Allow the next user-driven change; programmatic assign must not
            // re-run the change handler and re-add the same photos.
            photoInputSyncing = false;
        }
    }

    // OTP verification
    function sendOtp() {
        var email = document.getElementById('crEmail').value.trim();
        if (!email || !email.includes('@')) {
            showCrStatus('Please enter a valid email address.', 'error');
            return;
        }
        if (!email.toLowerCase().endsWith('@gmail.com')) {
            showCrStatus('Please use a Gmail address (@gmail.com) for verification.', 'error');
            return;
        }

        var btn = document.getElementById('sendOtpBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

        var fd = new FormData();
        fd.append('action', 'send_otp');
        fd.append('email', email);
        appendCsrf(fd);

        fetch(CITIZEN_API, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Code';
                if (data.success) {
                    showCrStatus(data.message, 'success');
                    document.getElementById('verifyOtpBtn').disabled = false;
                    document.getElementById('crOtp').focus();
                } else {
                    showCrStatus(data.message, 'error');
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send Code';
                showCrStatus('Failed to send code. Please try again.', 'error');
            });
    }

    function verifyOtp() {
        var otp = document.getElementById('crOtp').value.trim();
        if (!otp || otp.length < 6) {
            showCrStatus('Please enter the 6-digit verification code.', 'error');
            return;
        }

        var btn = document.getElementById('verifyOtpBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';

        var fd = new FormData();
        fd.append('action', 'verify_otp');
        fd.append('otp', otp);
        appendCsrf(fd);

        fetch(CITIZEN_API, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Verify';
                if (data.success) {
                    otpVerified = true;
                    showCrStatus('Email verified! You can now submit your report.', 'success');
                    document.getElementById('submitReportBtn').disabled = false;
                    document.getElementById('crEmail').readOnly = true;
                    document.getElementById('sendOtpBtn').disabled = true;
                    document.getElementById('verifyOtpBtn').disabled = true;
                } else {
                    showCrStatus(data.message, 'error');
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-check"></i> Verify';
                showCrStatus('Verification failed. Please try again.', 'error');
            });
    }

    // Philippine mobile number validation
    function normalizePhone(val) {
        return val.replace(/\s/g, '');
    }

    function validatePhone(val) {
        var clean = normalizePhone(val);
        var localRe = /^09[0-9]{9}$/;
        var intlRe = /^\+639[0-9]{9}$/;
        if (localRe.test(clean)) return { valid: true, normalized: clean, format: 'local' };
        if (intlRe.test(clean)) return { valid: true, normalized: '09' + clean.slice(3), format: 'intl' };
        return { valid: false, normalized: clean, format: null };
    }

    function applyPhoneFormat(raw) {
        if (raw.startsWith('+')) {
            if (!raw.startsWith('+63')) return '+63';
            var after = raw.slice(3).replace(/[^0-9]/g, '').slice(0, 9);
            var r = '+63';
            if (after.length > 0) r += ' ' + after.slice(0, 2);
            if (after.length > 2) r += ' ' + after.slice(2, 5);
            if (after.length > 5) r += ' ' + after.slice(5);
            return r;
        }
        var d = raw.replace(/[^0-9]/g, '').slice(0, 11);
        var res = d;
        if (d.length > 4) res = d.slice(0, 4) + ' ' + d.slice(4, 7) + ' ' + d.slice(7);
        else if (d.length > 2) res = d.slice(0, 4) + ' ' + d.slice(4);
        return res;
    }

    function showPhoneError(input, show) {
        var errEl = document.getElementById('crPhoneError');
        if (show) {
            input.classList.add('error');
            input.setAttribute('aria-invalid', 'true');
            errEl.classList.add('show');
        } else {
            input.classList.remove('error');
            input.removeAttribute('aria-invalid');
            errEl.classList.remove('show');
        }
    }

    function setPhotoError(show) {
        var label = document.querySelector('.file-upload-label');
        var errEl = document.getElementById('crPhotosError');
        if (show) {
            if (label) label.classList.add('has-error');
            if (errEl) errEl.classList.add('show');
        } else {
            if (label) label.classList.remove('has-error');
            if (errEl) errEl.classList.remove('show');
        }
    }

    // Form submit
    function submitReport() {
        var errors = [];

        var lat = document.getElementById('crLat').value;
        var lng = document.getElementById('crLng').value;
        if (!lat || !lng) errors.push('Please pin a location on the map.');

        var issueType = document.getElementById('crIssueType').value;
        if (!issueType) errors.push('Please select an issue type.');

        var severity = document.getElementById('crSeverity').value;
        if (!severity) errors.push('Please select a severity level.');

        var name = document.getElementById('crName').value.trim();
        if (!name) errors.push('Please enter your full name.');

        var phoneInput = document.getElementById('crPhone');
        var phone = normalizePhone(phoneInput.value);
        if (!phone) {
            errors.push('Please enter your phone number.');
            showPhoneError(phoneInput, true);
        } else {
            var phoneResult = validatePhone(phone);
            if (!phoneResult.valid) {
                errors.push('Please enter a valid Philippine mobile number.');
                showPhoneError(phoneInput, true);
            } else {
                showPhoneError(phoneInput, false);
            }
        }

        var desc = document.getElementById('crDescription').value.trim();
        if (!desc) errors.push('Please describe the issue.');

        if (photoFiles.length < 2) {
            errors.push('Please upload at least 2 photos before submitting your report.');
            setPhotoError(true);
        } else {
            setPhotoError(false);
        }

        if (!otpVerified) errors.push('Please verify your email first.');

        var subLat = parseFloat(document.getElementById('crLat').value);
        var subLng = parseFloat(document.getElementById('crLng').value);
        if (subLat && subLng && !isInsideQC(subLat, subLng)) {
            errors.push('Reports can only be submitted within Quezon City.');
        }

        if (errors.length > 0) {
            showCrStatus(errors.join('<br>'), 'error');
            return;
        }

        var btn = document.getElementById('submitReportBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';

        var fd = new FormData();
        fd.append('latitude', document.getElementById('crLat').value);
        fd.append('longitude', document.getElementById('crLng').value);
        fd.append('address', document.getElementById('crAddress').value);
        fd.append('detected_district', document.getElementById('crDistrict').value);
        fd.append('barangay', document.getElementById('crBarangay').value);
        fd.append('street_name', document.getElementById('crStreet').value);
        fd.append('issue_type', document.getElementById('crIssueType').value);
        fd.append('severity', document.getElementById('crSeverity').value);
        fd.append('reporter_name', document.getElementById('crName').value.trim());
        fd.append('phone', normalizePhone(document.getElementById('crPhone').value));
        fd.append('description', document.getElementById('crDescription').value.trim());
        // One FormData entry per distinct photo — never re-append the same file.
        photoFiles = dedupePhotoFiles(photoFiles);
        photoFiles.forEach(function (file) {
            fd.append('photos[]', file);
        });
        fd.append('action', 'submit_report');
        appendCsrf(fd);

        fetch(CITIZEN_API, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Report';
                if (data.success) {
                    showCrStatus(data.message, 'success');
                    setTimeout(function () {
                        var modal = bootstrap.Modal.getInstance(modalEl);
                        if (modal) modal.hide();
                        resetCitizenForm();
                    }, 2000);
                } else {
                    showCrStatus(data.message, 'error');
                }
            })
            .catch(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Report';
                showCrStatus('Submission failed. Please try again.', 'error');
            });
    }

    function resetCitizenForm() {
        formEl.reset();
        document.getElementById('crOtpStatus').style.display = 'none';
        var searchInput = document.getElementById('citizenMapSearchInput');
        if (searchInput) searchInput.value = '';
        var searchResults = document.getElementById('citizenMapSearchResults');
        if (searchResults) searchResults.style.display = 'none';
        document.getElementById('submitReportBtn').disabled = true;
        document.getElementById('verifyOtpBtn').disabled = true;
        document.getElementById('sendOtpBtn').disabled = false;
        document.getElementById('crPhone').classList.remove('error');
        document.getElementById('crPhone').removeAttribute('aria-invalid');
        document.getElementById('crPhoneError').classList.remove('show');
        document.getElementById('crEmail').readOnly = false;
        document.getElementById('citizenMap').classList.remove('has-pin');
        document.getElementById('crAddress').value = '';
        document.getElementById('crDistrict').value = '';
        document.getElementById('crBarangay').value = '';
        document.getElementById('crStreet').value = '';
        var locInfo = document.getElementById('cr-location-info');
        if (locInfo) locInfo.style.display = 'none';
        var locDetails = document.getElementById('cr-location-details');
        if (locDetails) locDetails.innerHTML = '';
        var locBadge = document.getElementById('cr-loading-badge');
        if (locBadge) locBadge.style.display = 'none';
        document.getElementById('photoPreview').innerHTML = '';
        photoFiles = [];
        syncPhotoFiles();
        document.getElementById('fileCount').textContent = 'No files selected';
        otpVerified = false;
        setPhotoError(false);
        if (citizenPin) { citizenMap.removeLayer(citizenPin); citizenPin = null; }
    }

    // --- Wire up events ---
    if (!modalEl || !formEl) return;

    modalEl.addEventListener('shown.bs.modal', function () {
        if (!citizenMap) {
            initCitizenMap();
        }
        loadQcDistricts();
        setTimeout(function () { if (citizenMap) citizenMap.invalidateSize(); }, 300);
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        resetCitizenForm();
    });

    var searchBtn = document.getElementById('citizenMapSearchBtn');
    if (searchBtn) {
        searchBtn.addEventListener('click', citizenMapSearch);
    }

    document.getElementById('citizenMapSearchInput').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') citizenMapSearch();
    });

    var searchResults = document.getElementById('citizenMapSearchResults');
    if (searchResults) {
        searchResults.addEventListener('click', function (e) {
            var item = e.target.closest('.gis-search-result-item');
            if (item) {
                citizenMapSelectResult(parseFloat(item.dataset.lat), parseFloat(item.dataset.lng));
            }
        });
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.citizen-report-map-wrap')) {
            if (searchResults) searchResults.style.display = 'none';
        }
    });

    document.getElementById('crPhotos').addEventListener('change', function (e) {
        // Ignore programmatic syncs — those must not re-add the same photos.
        if (photoInputSyncing) return;

        var files = Array.from(e.target.files || []);
        files.forEach(function (file) {
            if (!file || !file.name) return;
            // Duplicate checker is for the same uploaded photo within this report,
            // not for treating each pick as a separate report.
            if (!photoAlreadySelected(file)) {
                photoFiles.push(file);
            }
        });
        photoFiles = dedupePhotoFiles(photoFiles);
        renderPhotoPreviews();
        document.getElementById('fileCount').textContent = photoFiles.length + ' file(s) selected';
        // Clear the input so the user can pick additional files again; the
        // canonical list lives in photoFiles (submit reads that, not the input).
        photoInputSyncing = true;
        try {
            this.value = '';
        } finally {
            photoInputSyncing = false;
        }
    });

    document.getElementById('sendOtpBtn').addEventListener('click', sendOtp);
    document.getElementById('verifyOtpBtn').addEventListener('click', verifyOtp);

    var crPhoneInput = document.getElementById('crPhone');
    crPhoneInput.addEventListener('input', function () {
        var raw = this.value.replace(/[^0-9+]/g, '');
        var plusCount = (raw.match(/\+/g) || []).length;
        if (plusCount > 1) {
            raw = '+' + raw.replace(/\+/g, '');
        } else if (plusCount === 1 && !raw.startsWith('+')) {
            raw = '+' + raw.replace(/\+/g, '');
        }
        var cursorPos = this.selectionStart;
        var rawBefore = this.value.slice(0, cursorPos).replace(/[^0-9+]/g, '').length;

        var formatted = applyPhoneFormat(raw);
        this.value = formatted;

        var rawAfter = formatted.replace(/[^0-9+]/g, '');
        var newPos = 0, digitCount = 0;
        for (var i = 0; i < formatted.length && digitCount < rawBefore; i++) {
            if (/[0-9+]/.test(formatted[i])) digitCount++;
            newPos = i + 1;
        }
        if (digitCount < rawBefore) newPos = formatted.length;
        this.setSelectionRange(newPos, newPos);

        var result = validatePhone(normalizePhone(formatted));
        showPhoneError(this, formatted.length > 0 && !result.valid);
    });

    crPhoneInput.addEventListener('blur', function () {
        var val = normalizePhone(this.value);
        if (val.length > 0) {
            var result = validatePhone(val);
            showPhoneError(this, !result.valid);
            if (result.valid) {
                this.value = applyPhoneFormat(result.normalized);
            }
        } else {
            showPhoneError(this, false);
        }
    });

    formEl.addEventListener('submit', function (e) {
        e.preventDefault();
        submitReport();
    });
})();

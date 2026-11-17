// www/js/edit_report.js
// Lets a resident edit one of their Pending reports (waste type, exact location, photo, location pin).
(function () {
    var MAX_LOC = 400;
    var MAX_DESC = 500; // must match api_edit_report.php

    var currentReport = null;
    var newPhotoBase64 = null;
    var newLat = null;
    var newLng = null;
    var editMap = null;
    var editMarker = null;
    var saving = false;

    function $(id) { return document.getElementById(id); }

    function apiUrl() {
        var base = window.API_BASE_URL || 'https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch';
        return base + '/api_edit_report.php';
    }

    // Parse "[Type: Plastic] Location: Near blue gate" back into separate fields
    function parseReportDescription(rawDesc) {
        var text = String(rawDesc || '').trim();
        var match = text.match(/^\[Type:\s*(.+?)\]\s*Location:\s*([\s\S]*)$/i);
        if (match) {
            return { wasteType: match[1].trim(), exactLocation: match[2].trim() };
        }
        return { wasteType: '', exactLocation: text };
    }

    // ---------------------------------------------------------------- Report Details modal hook
    var originalOpenReportModal = window.openReportModal;
    window.openReportModal = function (index) {
        if (typeof originalOpenReportModal === 'function') originalOpenReportModal(index);
        currentReport = (window.historyReports || [])[index] || null;
        var btn = $('editReportBtn');
        if (btn) {
            var status = String((currentReport && currentReport.status) || '').toLowerCase();
            var editable = currentReport && status === 'pending';
            btn.style.display = editable ? '' : 'none';
        }
    };

    // ---------------------------------------------------------------- open / close
    window.openEditReportModal = function () {
        if (!currentReport) return;

        newPhotoBase64 = null;
        newLat = null;
        newLng = null;

        $('editReportPhoto').src = currentReport.image_data || 'https://placehold.co/400x300/e0e0e0/a0a0a0?text=No+Photo';

        // Pre-fill Type of Waste & Exact Location
        var parsed = parseReportDescription(currentReport.description);
        var selectEl = $('editWasteType');
        var customEl = $('editCustomWasteType');

        var matchedOption = Array.from(selectEl.options).find(function (opt) {
            return opt.value && opt.value.toLowerCase() === parsed.wasteType.toLowerCase();
        });

        if (matchedOption) {
            selectEl.value = matchedOption.value;
            customEl.style.display = 'none';
            customEl.required = false;
            customEl.value = '';
        } else if (parsed.wasteType) {
            selectEl.value = 'Other';
            customEl.style.display = 'block';
            customEl.required = true;
            customEl.value = parsed.wasteType;
        } else {
            selectEl.value = '';
            customEl.style.display = 'none';
            customEl.required = false;
            customEl.value = '';
        }

        $('editExactLocation').value = parsed.exactLocation;
        updateCount();
        showLocationText(currentReport.latitude, currentReport.longitude, false);
        showError('');

        $('editReportMapBox').style.display = 'none';
        $('editReportMapBtn').innerHTML = '<i class="ti ti-map-pin"></i> Move Pin on Map';
        $('editReportModal').style.display = 'flex';
    };

    window.closeEditReportModal = function () {
        $('editReportModal').style.display = 'none';
    };

    // ---------------------------------------------------------------- small helpers
    function showError(message) {
        var box = $('editReportError');
        if (!message) { box.style.display = 'none'; box.textContent = ''; return; }
        box.textContent = message;
        box.style.display = 'block';
    }

    function updateCount() {
        var len = $('editExactLocation').value.length;
        var counter = $('editReportCount');
        counter.textContent = len + ' / ' + MAX_LOC;
        counter.style.color = len >= MAX_LOC ? '#b91c1c' : 'var(--text-muted)';
    }

    function showLocationText(lat, lng, changed) {
        var chip = $('editReportLocStatus');
        var la = parseFloat(lat);
        var ln = parseFloat(lng);
        if (!isFinite(la) || !isFinite(ln)) {
            chip.classList.remove('is-pinned');
            chip.innerHTML = '<i class="ti ti-alert-circle"></i> No pin saved.';
            return;
        }
        chip.classList.add('is-pinned');
        var text = la.toFixed(6) + ', ' + ln.toFixed(6);
        chip.innerHTML = changed
            ? '<i class="ti ti-check"></i> New pin: ' + text + ' (not saved yet)'
            : '<i class="ti ti-map-pin"></i> Current pin: ' + text;
    }

    function setNewLocation(lat, lng) {
        newLat = parseFloat(parseFloat(lat).toFixed(6));
        newLng = parseFloat(parseFloat(lng).toFixed(6));
        showLocationText(newLat, newLng, true);
        if (editMap && editMarker) {
            editMarker.setLatLng([newLat, newLng]);
            editMap.setView([newLat, newLng], Math.max(editMap.getZoom(), 16));
        }
    }

    // ---------------------------------------------------------------- photo
    async function pickPhoto(source) {
        try {
            var image = await Capacitor.Plugins.Camera.getPhoto({
                quality: 50,
                width: 800,
                allowEditing: false,
                resultType: 'base64',
                source: source
            });
            newPhotoBase64 = image.base64String;
            $('editReportPhoto').src = 'data:image/jpeg;base64,' + newPhotoBase64;
            showError('');
        } catch (error) {
            console.log('Photo selection cancelled:', error);
        }
    }

    // ---------------------------------------------------------------- location
    async function useGps() {
        var chip = $('editReportLocStatus');
        chip.innerHTML = '<i class="ti ti-loader"></i> Checking permissions...';
        try {
            var permissions = await Capacitor.Plugins.Geolocation.checkPermissions();
            if (permissions.location !== 'granted') {
                var request = await Capacitor.Plugins.Geolocation.requestPermissions();
                if (request.location !== 'granted') {
                    alert('Location permission denied. We need this to move the pin.');
                    showLocationText(newLat !== null ? newLat : currentReport.latitude, newLng !== null ? newLng : currentReport.longitude, newLat !== null);
                    return;
                }
            }
            chip.innerHTML = '<i class="ti ti-satellite"></i> Locating via Satellite...';
            var position = await Capacitor.Plugins.Geolocation.getCurrentPosition({ enableHighAccuracy: true, timeout: 10000 });
            setNewLocation(position.coords.latitude, position.coords.longitude);
        } catch (error) {
            console.error('GPS Error:', error);
            alert('Could not get GPS location. You can tap the map to move the pin instead.');
            showLocationText(newLat !== null ? newLat : currentReport.latitude, newLng !== null ? newLng : currentReport.longitude, newLat !== null);
        }
    }

    function toggleMap() {
        var box = $('editReportMapBox');
        var opening = box.style.display === 'none';
        box.style.display = opening ? 'block' : 'none';
        $('editReportMapBtn').innerHTML = opening
            ? '<i class="ti ti-map-off"></i> Hide Map'
            : '<i class="ti ti-map-pin"></i> Move Pin on Map';
        if (opening) initEditMap();
    }

    function initEditMap() {
        var lat = newLat !== null ? newLat : parseFloat(currentReport.latitude);
        var lng = newLng !== null ? newLng : parseFloat(currentReport.longitude);
        var start = (isFinite(lat) && isFinite(lng)) ? [lat, lng] : [11.45, 123.15];

        if (!editMap) {
            editMap = L.map('editReportMap', { center: start, zoom: 16 });
            if (typeof applyMapTileLayers === 'function') {
                applyMapTileLayers(editMap, 'hybrid');
            }
            editMap.on('click', function (e) { setNewLocation(e.latlng.lat, e.latlng.lng); });
        } else {
            editMap.setView(start, 16);
        }

        if (editMarker) {
            editMarker.setLatLng(start);
        } else {
            editMarker = L.marker(start).addTo(editMap);
        }
        setTimeout(function () { editMap.invalidateSize(); }, 300);
    }

    // ---------------------------------------------------------------- save
    async function saveChanges(e) {
        e.preventDefault();
        if (saving || !currentReport) return;
        showError('');

        var wasteTypeVal = $('editWasteType').value;
        var customWasteVal = $('editCustomWasteType').value.trim();
        var finalWasteType = wasteTypeVal === 'Other' ? customWasteVal : wasteTypeVal;
        var exactLoc = $('editExactLocation').value.trim();

        if (!wasteTypeVal) { showError('Please select the type of waste.'); return; }
        if (wasteTypeVal === 'Other' && !customWasteVal) { showError('Please specify the custom waste type.'); return; }
        if (!exactLoc) { showError('Please enter the exact location.'); return; }

        var combinedDescription = '[Type: ' + finalWasteType + '] Location: ' + exactLoc;
        if (combinedDescription.length > MAX_DESC) {
            showError('Please shorten the exact location so the total text stays within ' + MAX_DESC + ' characters.');
            return;
        }

        var userSession = await localforage.getItem('user_session');
        if (!userSession) { showError('You must be logged in to edit a report.'); return; }
        var residentId = userSession.user_id || userSession.id;

        var payload = { resident_id: residentId, report_id: currentReport.report_id };
        var changed = false;
        if (combinedDescription !== String(currentReport.description || '').trim()) {
            payload.description = combinedDescription;
            changed = true;
        }
        if (newLat !== null && newLng !== null) {
            payload.latitude = newLat;
            payload.longitude = newLng;
            changed = true;
        }
        if (newPhotoBase64) {
            payload.photo = newPhotoBase64;
            changed = true;
        }

        if (!changed) {
            await showAlert('You have not changed anything yet.', { type: 'info', title: 'Nothing to save' });
            return;
        }

        var network = await Capacitor.Plugins.Network.getStatus();
        if (!network.connected) { showError('You need an internet connection to save changes.'); return; }

        var saveBtn = $('editReportSaveBtn');
        var originalLabel = saveBtn.innerHTML;
        saving = true;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="ti ti-loader edit-spin"></i> Saving...';

        try {
            var response = await fetch(apiUrl(), {
                method: 'POST',
                headers: { 'Content-Type': 'text/plain', 'ngrok-skip-browser-warning': 'true' },
                body: JSON.stringify(payload)
            });
            var raw = await response.text();
            var result;
            try {
                result = JSON.parse(raw);
            } catch (parseError) {
                console.error('Raw server answer:', raw);
                showError('The server sent an unexpected answer. Please try again.');
                return;
            }

            if (result.status === 'success') {
                await showAlert(result.message || 'Your report has been updated.', { type: 'success', title: 'Saved' });
                window.closeEditReportModal();
                if (typeof closeReportModal === 'function') closeReportModal();
                refreshLists();
            } else if (result.code === 'not_pending') {
                await showAlert(result.message, { type: 'warning', title: 'Cannot edit' });
                window.closeEditReportModal();
                if (typeof closeReportModal === 'function') closeReportModal();
                refreshLists();
            } else {
                showError(result.message || 'Could not save your changes.');
            }
        } catch (error) {
            console.error('Edit report failed:', error);
            showError('Could not reach the server. Check your connection and try again.');
        } finally {
            saving = false;
            saveBtn.disabled = false;
            saveBtn.innerHTML = originalLabel;
        }
    }

    function refreshLists() {
        if (typeof loadHistory === 'function') loadHistory();
        if (typeof loadCommunityMap === 'function') loadCommunityMap();
        if (typeof loadDashboardStats === 'function') loadDashboardStats();
    }

    // ---------------------------------------------------------------- wire up
    function init() {
        if (!$('editReportModal')) return;

        $('editWasteType').addEventListener('change', function () {
            var customEl = $('editCustomWasteType');
            if (this.value === 'Other') {
                customEl.style.display = 'block';
                customEl.required = true;
            } else {
                customEl.style.display = 'none';
                customEl.required = false;
                customEl.value = '';
            }
        });

        $('editReportCameraBtn').addEventListener('click', function () { pickPhoto('CAMERA'); });
        $('editReportGalleryBtn').addEventListener('click', function () { pickPhoto('PHOTOS'); });
        $('editReportGpsBtn').addEventListener('click', useGps);
        $('editReportMapBtn').addEventListener('click', toggleMap);
        $('editExactLocation').addEventListener('input', updateCount);
        $('editReportForm').addEventListener('submit', saveChanges);

        $('editReportModal').addEventListener('click', function (e) {
            if (e.target === $('editReportModal') && !saving) window.closeEditReportModal();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

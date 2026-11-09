// www/js/edit_report.js
// Lets a resident edit one of their Pending reports (description, photo, location pin).
//
// How it plugs in (nothing in app.js has to change):
//   - It wraps openReportModal() so the "Edit Report" button only shows for Pending reports.
//   - It opens the #editReportModal that is added in index.html.
//   - It saves through api_edit_report.php and then refreshes History, the map and the stats.
//
// Load it AFTER app.js:  <script src="js/app.js"></script>  <script src="js/edit_report.js"></script>
(function () {
    var MAX_DESC = 500; // must match api_edit_report.php

    var currentReport = null;   // the report shown in the Report Details modal
    var newPhotoBase64 = null;  // set when the resident picks a new photo
    var newLat = null;          // set when the resident moves the pin
    var newLng = null;
    var editMap = null;
    var editMarker = null;
    var saving = false;

    function $(id) { return document.getElementById(id); }

    function apiUrl() {
        var base = window.API_BASE_URL || 'https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch';
        return base + '/api_edit_report.php';
    }

    // ---------------------------------------------------------------- Report Details modal hook
    var originalOpenReportModal = window.openReportModal;
    window.openReportModal = function (index) {
        if (typeof originalOpenReportModal === 'function') originalOpenReportModal(index);
        currentReport = (window.historyReports || [])[index] || null;
        var btn = $('editReportBtn');
        if (btn) {
            var editable = currentReport && currentReport.status === 'Pending';
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
        $('editReportDesc').value = currentReport.description || '';
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
        var len = $('editReportDesc').value.length;
        var counter = $('editReportCount');
        counter.textContent = len + ' / ' + MAX_DESC;
        counter.style.color = len >= MAX_DESC ? '#b91c1c' : 'var(--text-muted)';
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
        chip.classList.add('is-pinned'); // same green "pinned" look as the report form
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
            var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 });
            var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 });
            editMap = L.map('editReportMap', { center: start, zoom: 16, layers: [osm] });
            L.control.layers({ 'Street View': osm, 'Satellite View': satellite }).addTo(editMap);
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

        var description = $('editReportDesc').value.trim();
        if (!description) { showError('Please describe the problem.'); return; }
        if (description.length > MAX_DESC) { showError('Please keep the description to ' + MAX_DESC + ' characters or fewer.'); return; }

        var userSession = await localforage.getItem('user_session');
        if (!userSession) { showError('You must be logged in to edit a report.'); return; }
        var residentId = userSession.user_id || userSession.id;

        // send only what changed
        var payload = { resident_id: residentId, report_id: currentReport.report_id };
        var changed = false;
        if (description !== String(currentReport.description || '').trim()) { payload.description = description; changed = true; }
        if (newLat !== null && newLng !== null) { payload.latitude = newLat; payload.longitude = newLng; changed = true; }
        if (newPhotoBase64) { payload.photo = newPhotoBase64; changed = true; }

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
        if (!$('editReportModal')) return; // markup not added to this page

        $('editReportCameraBtn').addEventListener('click', function () { pickPhoto('CAMERA'); });
        $('editReportGalleryBtn').addEventListener('click', function () { pickPhoto('PHOTOS'); });
        $('editReportGpsBtn').addEventListener('click', useGps);
        $('editReportMapBtn').addEventListener('click', toggleMap);
        $('editReportDesc').addEventListener('input', updateCount);
        $('editReportForm').addEventListener('submit', saveChanges);

        // tapping the dark area closes the editor (unless a save is running)
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

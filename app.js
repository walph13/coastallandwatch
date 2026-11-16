// www/js/app.js
const BASE_URL = 'http://192.168.1.50/coastal_land_watch';

if (typeof API_BASE_URL === 'undefined') {
    window.API_BASE_URL = 'https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch';
}

// 1. Variable to hold the photo data
let capturedPhotoBase64 = null;

// Custom Waste Type Dropdown Logic
const wasteTypeSelect = document.getElementById('wasteType');
if (wasteTypeSelect) {
    wasteTypeSelect.addEventListener('change', function() {
        const customInput = document.getElementById('customWasteType');
        if (this.value === 'Other') {
            customInput.style.display = 'block';
            customInput.required = true;
        } else {
            customInput.style.display = 'none';
            customInput.required = false;
            customInput.value = '';
        }
    });
}

// 🛰️ SHARED HYBRID + STREET TILE LAYERS
function applyMapTileLayers(mapInstance, defaultMode = 'hybrid') {
    const streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    });

    const satelliteImagery = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19,
        attribution: '&copy; Esri'
    });

    const roadOverlay = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19
    });

    const placeLabelsOverlay = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19
    });

    const hybridLayer = L.layerGroup([satelliteImagery, roadOverlay, placeLabelsOverlay]);

    if (defaultMode === 'street') {
        streetLayer.addTo(mapInstance);
    } else {
        hybridLayer.addTo(mapInstance);
    }

    L.control.layers({
        'Hybrid View': hybridLayer,
        'Street View': streetLayer
    }, null, { position: 'topright' }).addTo(mapInstance);
}

// 📸 OPEN LIVE CAMERA
document.getElementById('openCameraBtn').addEventListener('click', async () => {
    try {
        const image = await Capacitor.Plugins.Camera.getPhoto({
            quality: 50,
            width: 800,
            allowEditing: false,
            resultType: 'base64', 
            source: 'CAMERA'      
        });
        
        capturedPhotoBase64 = image.base64String;
        
        document.getElementById('photoPreview').src = "data:image/jpeg;base64," + capturedPhotoBase64;
        document.getElementById('photoPreview').style.display = 'block';
        
    } catch (error) {
        console.error('Camera Error:', error);
    }
});

// 🖼️ OPEN PHOTO GALLERY
document.getElementById('openGalleryBtn').addEventListener('click', async () => {
    try {
        const image = await Capacitor.Plugins.Camera.getPhoto({
            quality: 50,          
            width: 800,            
            allowEditing: false,
            resultType: 'base64',
            source: 'PHOTOS'      
        });
        
        capturedPhotoBase64 = image.base64String;
        
        document.getElementById('photoPreview').src = "data:image/jpeg;base64," + capturedPhotoBase64;
        document.getElementById('photoPreview').style.display = 'block';
    } catch (error) {
        console.log('User cancelled gallery');
    }
});

// 📍 OFFLINE GPS: Grab coordinates & update manual map
document.getElementById('getLocationBtn').addEventListener('click', async () => {
    const statusText = document.getElementById("location_status");
    statusText.innerHTML = '<i class="ti ti-loader"></i> Checking permissions...';
    
    if (typeof markLocationPinned === 'function') markLocationPinned(false); 

    try {
        const permissions = await Capacitor.Plugins.Geolocation.checkPermissions();
        if (permissions.location !== 'granted') {
            const request = await Capacitor.Plugins.Geolocation.requestPermissions();
            if (request.location !== 'granted') {
                alert("Location permission denied. We need this to geotag the waste.");
                statusText.innerHTML = '<i class="ti ti-alert-circle"></i> Permission Denied.';
                return;
            }
        }

        statusText.innerHTML = '<i class="ti ti-satellite"></i> Locating via Satellite...';

        const position = await Capacitor.Plugins.Geolocation.getCurrentPosition({ 
            enableHighAccuracy: true,
            timeout: 10000 
        });
        
        let lat = position.coords.latitude.toFixed(6);
        let lng = position.coords.longitude.toFixed(6);

        document.getElementById("lat").value = lat;
        document.getElementById("lng").value = lng;
        
        statusText.innerHTML = `<i class="ti ti-check"></i> GPS Pinned! (${lat}, ${lng})`;
        if (typeof markLocationPinned === 'function') markLocationPinned(true);
        
        if (typeof reportMap !== 'undefined' && reportMap !== null) {
            let latlng = [position.coords.latitude, position.coords.longitude];
            if (reportMarker) {
                reportMarker.setLatLng(latlng);
            } else {
                reportMarker = L.marker(latlng).addTo(reportMap);
            }
            reportMap.setView(latlng, 16);
        }

        document.getElementById("submit_btn").disabled = false;

    } catch (error) {
        console.error("GPS Error:", error);
        alert("Could not get GPS location. You can manually tap the location on the map below instead!");
        statusText.innerHTML = '<i class="ti ti-alert-circle"></i> GPS Failed. Tap map below.';
        if (typeof markLocationPinned === 'function') markLocationPinned(false);
    }
});

// 🚀 SUBMIT REPORT
document.getElementById('offlineReportForm').addEventListener('submit', async (e) => {
    e.preventDefault(); 

    const submitBtn = document.getElementById("submit_btn");
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="ti ti-loader spinner"></i> Processing...';

    if (!capturedPhotoBase64) {
        alert("Please take a photo or upload an image first.");
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="ti ti-send"></i> Submit Report';
        return;
    }

    const userSession = await localforage.getItem('user_session');
    if (!userSession) {
        alert("System Error: You must be logged in to submit a report.");
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="ti ti-send"></i> Submit Report';
        return;
    }

    const activeUserId = userSession.user_id || userSession.id;

    const wasteTypeVal = document.getElementById('wasteType').value;
    const customWasteTypeVal = document.getElementById('customWasteType').value;
    const finalWasteType = wasteTypeVal === 'Other' ? customWasteTypeVal : wasteTypeVal;
    const exactLoc = document.getElementById('exactLocation').value;

    if (wasteTypeVal === 'Other' && !customWasteTypeVal.trim()) {
        alert("Please specify the custom waste type.");
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="ti ti-send"></i> Submit Report';
        return;
    }

    const reportData = {
        id: Date.now(),
        resident_id: activeUserId, 
        user_id: activeUserId,
        description: `[Type: ${finalWasteType}] Location: ${exactLoc}`,
        latitude: document.getElementById('lat').value,
        longitude: document.getElementById('lng').value,
        photo: capturedPhotoBase64, 
        timestamp: new Date().toISOString()
    };

    let offlineReports = await localforage.getItem('pending_waste_reports') || [];
    offlineReports.push(reportData);
    await localforage.setItem('pending_waste_reports', offlineReports);

    await alert('Report saved to phone! It will sync automatically when you connect to the internet.');
    
    document.getElementById('offlineReportForm').reset();
    document.getElementById('customWasteType').style.display = 'none';
    document.getElementById('customWasteType').required = false;
    
    if (reportMarker && reportMap) {
        reportMap.removeLayer(reportMarker);
        reportMarker = null;
    }
    const mapBox = document.getElementById('manualMapContainer');
    const toggleBtn = document.getElementById('toggleManualMapBtn');
    if (mapBox) mapBox.style.display = 'none';
    if (toggleBtn) {
        toggleBtn.innerHTML = '<i class="ti ti-map-pin"></i> Pin Location Manually on Map';
        toggleBtn.style.background = '#fff';
    }
    
    const locChip = document.getElementById("location_status");
    locChip.innerHTML = '<i class="ti ti-alert-circle"></i> Not pinned yet.';
    locChip.style.color = '';
    if (typeof markLocationPinned === 'function') markLocationPinned(false);
    
    document.getElementById('photoPreview').style.display = 'none';
    document.getElementById('photoPreview').src = '';
    capturedPhotoBase64 = null; 

    submitBtn.innerHTML = '<i class="ti ti-send"></i> Submit Report';

    syncReports(); 
});

// 🔄 SYNC LOGIC
let isSyncing = false;

async function syncReports() {
    if (isSyncing) return; 

    const networkStatus = await Capacitor.Plugins.Network.getStatus();
    if (!networkStatus.connected) {
        console.log("No internet. Leaving report in local storage for later.");
        return; 
    }

    let offlineReports = await localforage.getItem('pending_waste_reports') || [];
    if (offlineReports.length === 0) return; 

    isSyncing = true; 

    alert("Found " + offlineReports.length + " saved reports. Attempting to send...");

    for (let i = 0; i < offlineReports.length; i++) {
        let report = offlineReports[i];

        try {
            let response = await fetch(`${API_BASE_URL}/api_receive_report.php`, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'text/plain',
                    'ngrok-skip-browser-warning': 'true' 
                }, 
                body: JSON.stringify(report)
            });

            let rawResponse = await response.text();
            let result;
            try {
                result = JSON.parse(rawResponse);
            } catch (parseError) {
                alert("PHP CRASHED! Here is the exact server error: \n\n" + rawResponse);
                console.error("Raw PHP Error: ", rawResponse);
                isSyncing = false;
                return; 
            }

            if (result.status === 'success') {
                console.log("Successfully synced report!");
            } else {
                alert("Database Error: " + result.message);
                isSyncing = false;
                return; 
            }
        } catch (error) {
            alert("System Error: " + error.message + " | " + error.name);
            console.error("Sync failed for this report: ", error);
            isSyncing = false;
            return; 
        }
    }

    await localforage.setItem('pending_waste_reports', []);
    isSyncing = false; 

    await alert('🎉 SUCCESS! Your reports have successfully synced to the Barangay Cloud!');

    if (typeof loadDashboardStats === 'function') loadDashboardStats();
    if (typeof loadCommunityMap === 'function') loadCommunityMap();
    if (typeof loadHistory === 'function') loadHistory();
}

Capacitor.Plugins.Network.addListener('networkStatusChange', status => {
    if (status.connected) {
        syncReports();
    }
});

// 🚪 LOGOUT FUNCTION
async function logoutUser() {
    const confirmLogout = (typeof showConfirm === 'function')
        ? await showConfirm("Are you sure you want to log out?", { title: 'Log out?', confirmText: 'Log out', cancelText: 'Stay', type: 'danger' })
        : confirm("Are you sure you want to log out?");
    if (confirmLogout) {
        try {
            const sess = await localforage.getItem('user_session');
            if (sess && sess.profile_pic_data) {
                await localforage.setItem('profile_pic_' + (sess.user_id || sess.id), sess.profile_pic_data);
            }
        } catch (e) { console.error('Could not keep profile picture:', e); }
        await localforage.removeItem('user_session');
        window.location.replace("login.html");
    }
}

// 🗺️ COMMUNITY MAP LOGIC
let communityMap = null;
let communityMarkersLayer = null;

async function loadCommunityMap() {
    const mapContainer = document.getElementById('communityMap');
    if (!mapContainer) return; 

    if (!communityMap) {
        communityMap = L.map('communityMap').setView([11.45, 123.15], 13); 
        applyMapTileLayers(communityMap, 'hybrid');
        communityMarkersLayer = L.layerGroup().addTo(communityMap);
        setTimeout(() => {
            communityMap.invalidateSize();
        }, 500);
    }

    try {
        let response = await fetch(`${API_BASE_URL}/api_get_map_data.php`, {
            method: 'GET',
            headers: { 'ngrok-skip-browser-warning': 'true' }
        });
        
        let result = await response.json();

        if (result.status === 'success') {
            if (communityMarkersLayer) {
                communityMarkersLayer.clearLayers();
            } else {
                communityMarkersLayer = L.layerGroup().addTo(communityMap);
            }

            let mapData = result.data;
            var redPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34] });
            var greenPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34] });

            mapData.forEach(function(loc) {
                var isPending = String(loc.status || '').toLowerCase() === 'pending';
                var currentIcon = isPending ? redPin : greenPin; 
                var marker = L.marker([loc.latitude, loc.longitude], {icon: currentIcon}).addTo(communityMarkersLayer);
                
                let imageUrl = loc.image_data ? loc.image_data : 'https://placehold.co/150x150/e0e0e0/a0a0a0?text=No+Photo';

                var popupContent = `
                    <div style="text-align:center; min-width: 150px; font-family: 'Segoe UI', Arial, sans-serif;">
                        <img src="${imageUrl}" style="width: 100%; height: 100px; object-fit: cover; border-radius: 8px; margin-bottom: 8px; border: 1px solid #ccc;">
                        <div style="font-size: 14px; color: ${isPending ? '#dc3545' : '#28a745'}; font-weight: bold; margin-bottom: 4px;">
                            ${isPending ? '🔴 Pending' : '✅ Cleaned'}
                        </div>
                        <div style="font-size: 12px; text-align: left; color: #555;">
                            <b>Desc:</b> "${escapeHtml(loc.description)}"
                        </div>
                    </div>
                `;
                marker.bindPopup(popupContent);
            });
        }
    } catch (error) {
        console.error("Could not load community map: ", error);
    }
}

// ==========================================
// 📊 DASHBOARD STATS COUNTER LOGIC
// ==========================================
async function loadDashboardStats() {
    try {
        const userSession = await localforage.getItem('user_session');
        if (!userSession) return;

        const userId = userSession.user_id || userSession.id;
        setStatsLoading(true);

        let response = await fetch(`${API_BASE_URL}/api_get_dashboard_stats.php?user_id=${userId}`, {
            method: 'GET',
            headers: { 'ngrok-skip-browser-warning': 'true' }
        });

        let result = await response.json();
        if (result.status === 'success') {
            const totalEl = document.getElementById('stat_total');
            const resolvedEl = document.getElementById('stat_resolved');
            
            if (totalEl) totalEl.innerText = result.total;
            if (resolvedEl) resolvedEl.innerText = result.resolved;
        }
    } catch (error) {
        console.error("Could not load dashboard stats: ", error);
    } finally {
        setStatsLoading(false);
    }
}

// 📝 HISTORY TAB LOGIC
async function loadHistory() {
    const historyContainer = document.getElementById('history_container');
    if (!historyContainer) return;

    try {
        const userSession = await localforage.getItem('user_session');
        if (!userSession) return;

        const userId = userSession.user_id || userSession.id;

        showSkeleton(historyContainer, 'history', 4);

        let response = await fetch(`${API_BASE_URL}/api_get_history.php?user_id=${userId}`, {
            method: 'GET',
            headers: { 'ngrok-skip-browser-warning': 'true' }
        });

        let rawText = await response.text();
        let result;
        try {
            result = JSON.parse(rawText);
        } catch (e) {
            alert("PHP Error in History API: \n" + rawText);
            showLoadError(historyContainer, 'Server error. Check the alert pop-up.', loadHistory);
            return;
        }

        if (result.status === 'success') {
            let reports = result.data;
            window.historyReports = reports;
            window.historyUserName = userSession.full_name ? userSession.full_name : userSession.username;

            if (reports.length === 0) {
                historyContainer.innerHTML = `
                    <div class="card text-center" style="padding: 40px 20px; text-align:center; border:none; box-shadow:0 4px 15px rgba(0,0,0,0.03);">
                        <i class="ti ti-clipboard-list" style="font-size: 40px; color: #ccc; margin-bottom: 10px;"></i>
                        <p style="font-size: 18px; font-weight: 600; color: #212529; margin: 0 0 6px;">No reports yet</p>
                        <p style="font-size: 14px; color: #8e8e93; margin: 0;">Your history will appear here.</p>
                    </div>
                `;
                markSkeletonReady(historyContainer);
                return;
            }

            let html = '';
            reports.forEach((report, index) => {
                const isPending = String(report.status || '').toLowerCase() === 'pending';
                let badgeClass = isPending ? 'badge-pending' : 'badge-done';
                let statusText = isPending ? 'Pending' : 'Resolved';
                
                let dateStr = "Recently";
                if(report.created_at) {
                    let d = new Date(report.created_at);
                    dateStr = d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                }

                let imageUrl = report.image_data ? report.image_data : 'https://placehold.co/150x150/e0e0e0/a0a0a0?text=No+Photo';

                html += `
                    <div style="background: #ffffff; border-radius: 24px; padding: 20px; margin-bottom: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); cursor: pointer; display: flex; gap: 16px; align-items: center;" onclick="openReportModal(${index})">
                        <img src="${imageUrl}" style="width: 80px; height: 80px; object-fit: cover; border-radius: 16px; border: 1px solid #e5e5ea;">
                        <div style="flex: 1; overflow: hidden;">
                            <p style="font-size: 18px; font-weight: 600; color: #212529; margin: 0 0 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${escapeHtml(report.description)}</p>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <p style="font-size: 13px; color: #8e8e93; margin: 0;">${dateStr}</p>
                                <span class="badge ${badgeClass}">${statusText}</span>
                            </div>
                        </div>
                    </div>
                `;
            });

            historyContainer.innerHTML = html;
            markSkeletonReady(historyContainer);
        } else {
            showLoadError(historyContainer, 'Database Error: ' + (result.message || 'Could not load your reports.'), loadHistory);
        }
    } catch (error) {
        console.error("Could not load history: ", error);
        showLoadError(historyContainer, 'Could not load your reports. Check your connection.', loadHistory);
    }
}

// POP-UP MODAL LOGIC
function openReportModal(index) {
    let report = window.historyReports[index];
    if (!report) return;

    const afterContainer = document.getElementById('afterPhotoContainer');
    const imgBefore = document.getElementById('modalImageBefore');
    const imgAfter = document.getElementById('modalImageAfter');

    imgBefore.src = report.image_data ? report.image_data : 'https://placehold.co/400x300/e0e0e0/a0a0a0?text=No+Photo';

    let currentStatus = report.status ? report.status.toLowerCase() : 'pending';
    if (currentStatus === 'resolved' || currentStatus === 'cleaned' || currentStatus === 'done') {
        afterContainer.style.display = 'block';
        imgAfter.src = report.after_image_data ? report.after_image_data : 'https://placehold.co/400x300/e0e0e0/a0a0a0?text=No+Photo';
    } else {
        afterContainer.style.display = 'none';
    }

    document.getElementById('modalName').innerText = window.historyUserName;
    document.getElementById('modalDesc').innerText = report.description;

    let dateStr = "Unknown Date";
    if(report.created_at) {
        let d = new Date(report.created_at);
        dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
    document.getElementById('modalDate').innerText = dateStr;
    document.getElementById('modalLocation').innerHTML = `<a href="#" onclick="window.location.href='https://www.openstreetmap.org/?mlat=${report.latitude}&mlon=${report.longitude}#map=16/${report.latitude}/${report.longitude}'; return false;" style="color:#1E88E5; text-decoration:none; font-weight:700;">View on Map 📍</a>`;
    
    const isPending = currentStatus === 'pending';
    let badgeClass = isPending ? 'badge-pending' : 'badge-done';
    let statusText = isPending ? 'Pending' : 'Resolved';
    let badgeEl = document.getElementById('modalStatus');
    badgeEl.className = `badge ${badgeClass}`;
    badgeEl.innerText = statusText;

    document.getElementById('reportModal').style.display = 'flex';
}

function closeReportModal() {
    document.getElementById('reportModal').style.display = 'none';
}

// ==========================================
// 👤 EDIT PROFILE & AVATAR LOGIC
// ==========================================
let editProfilePicBase64 = null;

const changePicBtn = document.getElementById('changeProfilePicBtn');
if (changePicBtn) {
    changePicBtn.addEventListener('click', async () => {
        try {
            const image = await Capacitor.Plugins.Camera.getPhoto({
                quality: 60,
                width: 500,           
                allowEditing: true,   
                resultType: 'base64',
                source: 'PROMPT'      
            });
            
            editProfilePicBase64 = "data:image/jpeg;base64," + image.base64String;
            document.getElementById('editProfilePreview').src = editProfilePicBase64;
        } catch (error) {
            console.log('User cancelled photo selection or camera error:', error);
        }
    });
}

async function openEditProfileModal() {
    const userSession = await localforage.getItem('user_session');
    if (!userSession) {
        alert("Please log in first.");
        return;
    }

    document.getElementById('edit_fullname').value = userSession.full_name || '';
    document.getElementById('edit_username').value = userSession.username || '';
    document.getElementById('edit_phone').value = userSession.phone_number || '';
    document.getElementById('edit_dob').value = userSession.date_of_birth || userSession.dob || '';
    document.getElementById('edit_address').value = userSession.address_purok_sitio || userSession.address || '';
    document.getElementById('edit_password').value = ''; 

    if (userSession.profile_pic_data) {
        document.getElementById('editProfilePreview').src = userSession.profile_pic_data;
    } else {
        document.getElementById('editProfilePreview').src = 'https://placehold.co/100x100/333/fff?text=Photo';
    }
    
    editProfilePicBase64 = null; 
    document.getElementById('editProfileModal').style.display = 'flex';
}

function closeEditProfileModal() {
    document.getElementById('editProfileModal').style.display = 'none';
}

const editForm = document.getElementById('editProfileForm');
if (editForm) {
    editForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const userSession = await localforage.getItem('user_session');
        if (!userSession) return;

        const submitBtn = editForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerText = "Saving...";

        const payload = {
            user_id: userSession.user_id || userSession.id,
            full_name: document.getElementById('edit_fullname').value,
            username: document.getElementById('edit_username').value,
            password: document.getElementById('edit_password').value,
            phone_number: document.getElementById('edit_phone').value,
            date_of_birth: document.getElementById('edit_dob').value,
            address_purok_sitio: document.getElementById('edit_address').value,
            profile_pic: editProfilePicBase64 || userSession.profile_pic_data
        };

        try {
            let response = await fetch(`${API_BASE_URL}/api_update_profile.php`, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'text/plain',
                    'ngrok-skip-browser-warning': 'true' 
                },
                body: JSON.stringify(payload)
            });

            let rawText = await response.text();
            let result;
            try {
                result = JSON.parse(rawText);
            } catch (parseError) {
                alert("PHP Server Error:\n\n" + rawText);
                console.error("Raw PHP Output:", rawText);
                return;
            }

            if (result.status === 'success') {
                await localforage.setItem('user_session', result.user);
                if (result.user && result.user.profile_pic_data) {
                    await localforage.setItem('profile_pic_' + (result.user.user_id || result.user.id), result.user.profile_pic_data);
                }
                
                await alert("🎉 " + result.message);
                closeEditProfileModal();
                
                if (typeof loadDashboardStats === 'function') loadDashboardStats();
                if (typeof loadProfileDisplay === 'function') loadProfileDisplay();
                if (typeof loadHomeProfile === 'function') loadHomeProfile();
            } else {
                alert("Database Error: " + result.message);
            }
        } catch (error) {
            console.error("Update profile error:", error);
            alert("Network Error: " + error.message);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerText = "Save Profile Changes";
        }
    });
}

// ==========================================
// 🎨 DYNAMIC PROFILE HEADER DISPLAY
// ==========================================
async function loadProfileDisplay() {
    const userSession = await localforage.getItem('user_session');
    if (!userSession) return;

    const nameEl = document.getElementById('profile_top_name');
    const initialsEl = document.getElementById('profile_top_initials');
    const imgEl = document.getElementById('profile_top_img');

    if (!nameEl) return;

    let displayName = userSession.full_name || userSession.username || "Citizen";
    nameEl.innerText = displayName;

    if (userSession.profile_pic_data) {
        imgEl.src = userSession.profile_pic_data;
        imgEl.style.display = 'block';
        if (initialsEl) initialsEl.style.display = 'none';
    } else {
        if (imgEl) imgEl.style.display = 'none';
        if (initialsEl) {
            initialsEl.style.display = 'block';
            let parts = displayName.trim().split(/\s+/);
            let initials = parts[0].charAt(0).toUpperCase();
            if (parts.length > 1) {
                initials += parts[parts.length - 1].charAt(0).toUpperCase();
            }
            initialsEl.innerText = initials;
        }
    }
}

// ==========================================
// 🗺️ MANUAL REPORT PIN MAP LOGIC
// ==========================================
let reportMap = null;
let reportMarker = null;

const toggleMapBtn = document.getElementById('toggleManualMapBtn');
if (toggleMapBtn) {
    toggleMapBtn.addEventListener('click', () => {
        const mapBox = document.getElementById('manualMapContainer');
        
        if (mapBox.style.display === 'none' || !mapBox.style.display) {
            mapBox.style.display = 'block';
            toggleMapBtn.innerHTML = '<i class="ti ti-eye-off"></i> Hide Manual Map';
            toggleMapBtn.style.background = '#E8F5E9';
            initReportPinMap();
        } else {
            mapBox.style.display = 'none';
            toggleMapBtn.innerHTML = '<i class="ti ti-map-pin"></i> Pin Location Manually on Map';
            toggleMapBtn.style.background = '#fff';
        }
    });
}

function initReportPinMap() {
    const mapContainer = document.getElementById('reportPinMap');
    if (!mapContainer) return;

    if (!reportMap) {
        reportMap = L.map('reportPinMap').setView([11.45, 123.15], 13);
        applyMapTileLayers(reportMap, 'hybrid');

        reportMap.on('click', function(e) {
            let lat = e.latlng.lat.toFixed(6);
            let lng = e.latlng.lng.toFixed(6);

            if (reportMarker) {
                reportMarker.setLatLng(e.latlng);
            } else {
                reportMarker = L.marker(e.latlng).addTo(reportMap);
            }

            document.getElementById("lat").value = lat;
            document.getElementById("lng").value = lng;

            const statusText = document.getElementById("location_status");
            statusText.innerHTML = `<i class="ti ti-check"></i> Map Pinned! (${lat}, ${lng})`;
            
            if (typeof markLocationPinned === 'function') {
                markLocationPinned(true);
            }
            
            document.getElementById("submit_btn").disabled = false;
        });

        setTimeout(() => { reportMap.invalidateSize(); }, 400);
    } else {
        setTimeout(() => { reportMap.invalidateSize(); }, 300);
    }
}

// 🌟 COMMUNITY FEED
async function loadCommunityFeed() {
    const feedContainer = document.getElementById('feed_container');
    if (!feedContainer) return;

    try {
        showSkeleton(feedContainer, 'feed', 3);

        let response = await fetch(`${API_BASE_URL}/api_get_community_feed.php`, {
            method: 'GET',
            headers: { 'ngrok-skip-browser-warning': 'true' }
        });

        if (!response.ok) {
            showLoadError(feedContainer, 'Server Error. Check Ngrok connection.', loadCommunityFeed);
            return;
        }

        let rawText = await response.text();
        let result;
        try {
            result = JSON.parse(rawText);
        } catch (parseError) {
            console.error("Raw PHP Error: ", rawText);
            showLoadError(feedContainer, 'Server Error. Check Ngrok connection.', loadCommunityFeed);
            return;
        }

        if (result.status === 'success') {
            let reports = result.data;

            if (reports.length === 0) {
                feedContainer.innerHTML = `
                    <div class="card text-center" style="padding: 40px 20px; text-align:center; border:none; box-shadow:0 4px 15px rgba(0,0,0,0.03);">
                        <i class="ti ti-sparkles" style="font-size: 40px; color: #ccc; margin-bottom: 10px;"></i>
                        <p style="font-size: 18px; font-weight: 600; color: #212529; margin: 0 0 6px;">No clean-ups yet</p>
                        <p style="font-size: 14px; color: #8e8e93; margin: 0;">Resolved reports will appear here!</p>
                    </div>
                `;
                markSkeletonReady(feedContainer);
                return;
            }

            let html = '';
            reports.forEach((report) => {
                let dateStr = "Recently";
                if (report.created_at || report.timestamp) {
                    let d = new Date(report.created_at || report.timestamp);
                    dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                }

                let beforeFile = report.before_photo_path || report.image_data || report.photo;
                let beforeImg = 'https://placehold.co/300x200/e0e0e0/a0a0a0?text=No+Photo';
                
                if (beforeFile) {
                    beforeImg = beforeFile.startsWith('data:image') 
                        ? beforeFile 
                        : `${API_BASE_URL}/uploads/reports/${beforeFile}`;
                }

                let afterFile = report.after_photo_path || report.after_image || report.after_photo;
                let afterImg = 'https://placehold.co/300x200/e6f8f3/34d399?text=Cleaned+Area';
                
                if (afterFile) {
                    afterImg = afterFile.startsWith('data:image') 
                        ? afterFile 
                        : `${API_BASE_URL}/uploads/reports/${afterFile}`;
                }

                let reporterName = escapeHtml(report.reporter_name || report.username || "a concerned resident");
                let desc = escapeHtml(report.description || "Resolved Waste Issue");

                html += `
                    <div style="background: #ffffff; border-radius: 28px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                            <span style="background: #E6F8F3; color: #34D399; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                                <i class="ti ti-check"></i> Area Cleaned
                            </span>
                            <span style="font-size: 13px; color: #8e8e93; font-weight: 500;">${dateStr}</span>
                        </div>
                        
                        <p style="font-size: 18px; font-weight: 600; margin: 0 0 6px; color: #212529; line-height: 1.4;">"${desc}"</p>
                        <p style="font-size: 14px; color: #8e8e93; font-style: italic; margin: 0 0 20px;">— Reported by ${reporterName}</p>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                            <div style="text-align: center;">
                                <img src="${beforeImg}" style="width: 100%; height: 140px; object-fit: cover; border-radius: 16px; margin-bottom: 8px; border: 1px solid #e5e5ea;">
                                <span style="font-size: 12px; color: #8e8e93; font-weight: 600; letter-spacing: 0.5px;">● BEFORE</span>
                            </div>
                            <div style="text-align: center;">
                                <img src="${afterImg}" style="width: 100%; height: 140px; object-fit: cover; border-radius: 16px; margin-bottom: 8px; border: 1px solid #e5e5ea;">
                                <span style="font-size: 12px; color: #212529; font-weight: 600; letter-spacing: 0.5px;">● AFTER</span>
                            </div>
                        </div>
                    </div>
                `;
            });

            feedContainer.innerHTML = html;
            markSkeletonReady(feedContainer);
        } else {
            showLoadError(feedContainer, (result && result.message) || 'Could not load the community feed.', loadCommunityFeed);
        }
    } catch (error) {
        console.error("Could not load community feed: ", error);
        showLoadError(feedContainer, 'Failed to connect to server.', loadCommunityFeed);
    }
}

// ==========================================
// 🚚 BASURA-ALERT (ADMIN BROADCAST) LOGIC
// ==========================================
function escapeHtml(text) {
    const div = document.createElement('div');
    div.innerText = (text === null || text === undefined) ? '' : text;
    return div.innerHTML;
}

function updateAlertBadge(show) {
    const badge = document.getElementById('alertBadgeDot') || document.getElementById('unreadBadge');
    if (badge) badge.style.display = show ? 'block' : 'none';
}

async function loadAlerts() {
    const container = document.getElementById('alertsContainer');
    if (!container) return;

    showSkeleton(container, 'alerts', 3);

    try {
        let response = await fetch(`${API_BASE_URL}/api_get_alerts.php`, {
            method: 'GET',
            headers: { 'ngrok-skip-browser-warning': 'true' }
        });

        let result = await response.json();

        if (result.status === 'success' && result.data.length > 0) {
            let html = '';

            result.data.forEach(item => {
                const area = item.purok_area || 'All Areas';
                const message = item.message || '';
                let dateStr = '';
                if (item.created_at) {
                    const parsed = new Date(item.created_at.replace(' ', 'T'));
                    if (!isNaN(parsed)) {
                        dateStr = parsed.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
                    }
                }

                html += `<div style="background: #ffffff; border-radius: 20px; padding: 18px 20px; margin-bottom: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.02); border: 1px solid #F3F4F6;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 8px; gap: 10px;">
                                <span style="background:#FEF3C7; color:#B45309; font-size:11px; font-weight:700; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing:0.5px; white-space: nowrap;">🚚 ${escapeHtml(area)}</span>
                                <span style="color:#8E8E93; font-size:12px; white-space: nowrap;">${dateStr}</span>
                            </div>
                            <p style="margin:0; color: var(--text-dark); font-size: 14px; line-height:1.5;">${escapeHtml(message)}</p>
                         </div>`;
            });

            container.innerHTML = html;
            markSkeletonReady(container);

            const newestId = item_id(result.data[0]);
            if (newestId !== null) {
                await localforage.setItem('last_seen_alert_id', newestId);
            }
            updateAlertBadge(false);
        } else {
            container.innerHTML = `<div style="text-align: center; padding: 30px; color: var(--text-gray);">
                                        <i class="ti ti-bell-off" style="font-size: 28px;"></i>
                                        <p style="margin-top:8px;">No alerts yet.</p>
                                    </div>`;
            markSkeletonReady(container);
        }
    } catch (error) {
        console.error("Could not load alerts: ", error);
        showLoadError(container, 'Could not load alerts. Check your connection.', loadAlerts);
    }
}

async function checkForNewAlerts() {
    try {
        let response = await fetch(`${API_BASE_URL}/api_get_alerts.php`, {
            method: 'GET',
            headers: { 'ngrok-skip-browser-warning': 'true' }
        });
        let result = await response.json();

        if (result.status === 'success' && result.data.length > 0) {
            const newestId = item_id(result.data[0]);
            const lastSeenId = await localforage.getItem('last_seen_alert_id');

            if (newestId !== null && (lastSeenId === null || lastSeenId === undefined || newestId > lastSeenId)) {
                updateAlertBadge(true);
            }
        }
    } catch (error) {
        console.error("Could not check for new alerts: ", error);
    }
}

function item_id(row) {
    if (!row) return null;
    if (row.alert_id !== undefined) return row.alert_id;
    if (row.id !== undefined) return row.id;
    return null;
}

// ==========================================
// ☎️ EMERGENCY & BRGY INFO (AUTO-SYNCED WITH WEBSITE SETTINGS)
// ==========================================
const FALLBACK_HOTLINES = [
    { name: 'National Emergency Hotline', desc: 'Police, fire, medical and disaster response', number: '911', icon: 'ti-phone-call', tone: 'red' },
    { name: 'Philippine Red Cross', desc: 'Ambulance, disaster response and humanitarian aid', number: '143', icon: 'ti-heartbeat', tone: 'red' }
];

function buildHotlineRowsHtml(items) {
    if (!items || !items.length) return '';
    return items
        .filter(item => item && item.number && String(item.number).trim() !== '')
        .map(h => {
            const dial = String(h.number).replace(/[^\d+]/g, '');
            return `
                <a class="hotline-row" href="tel:${dial}">
                    <span class="hotline-icon ${h.tone || ''}"><i class="ti ${h.icon || 'ti-phone'}"></i></span>
                    <span class="hotline-info">
                        <span class="hotline-name">${escapeHtml(h.name)}</span>
                        ${h.desc ? `<span class="hotline-desc">${escapeHtml(h.desc)}</span>` : ''}
                    </span>
                    <span class="hotline-call"><i class="ti ti-phone"></i>${escapeHtml(h.number)}</span>
                </a>`;
        }).join('');
}

function normalizeBarangayRow(raw) {
    if (!raw) return null;
    // Kung naka-wrap sa array (hal. [ { id: 1, ... } ]), kunin ang unang row
    if (Array.isArray(raw)) {
        return raw.length > 0 && typeof raw[0] === 'object' ? raw[0] : null;
    }
    if (typeof raw === 'object') {
        if (raw.data) return normalizeBarangayRow(raw.data);
        return raw;
    }
    return null;
}

function parseBarangayInfoRow(rawInput) {
    const row = normalizeBarangayRow(rawInput);
    if (!row) {
        return { brgyInfo: [], hotlines: FALLBACK_HOTLINES, hasServerData: false };
    }

    const pick = (...keys) => {
        for (const k of keys) {
            if (row[k] !== undefined && row[k] !== null && String(row[k]).trim() !== '') {
                return String(row[k]).trim();
            }
        }
        return '';
    };

    // 1. Barangay Information (kapareho ng nasa Website Settings)
    const brgyInfo = [];
    const brgyName  = pick('barangay_name', 'brgy_name');
    const captain   = pick('captain_name', 'punong_barangay', 'barangay_captain');
    const capPhone  = pick('captain_contact', 'captain_number');
    const secretary = pick('secretary_name', 'barangay_secretary');
    const secPhone  = pick('secretary_contact', 'secretary_number');
    const brgyPhone = pick('contact_number', 'barangay_contact', 'barangay_hotline');
    const hours     = pick('office_hours', 'operating_hours');

    if (brgyName)  brgyInfo.push({ label: 'Barangay', value: brgyName, isPhone: false });
    if (brgyPhone) brgyInfo.push({ label: 'Main Office Contact', value: brgyPhone, isPhone: true });
    if (captain)   brgyInfo.push({ label: 'Barangay Captain', value: capPhone ? `${captain} (${capPhone})` : captain, isPhone: false });
    if (secretary) brgyInfo.push({ label: 'Barangay Secretary', value: secPhone ? `${secretary} (${secPhone})` : secretary, isPhone: false });
    if (hours)     brgyInfo.push({ label: 'Office Hours', value: hours, isPhone: false });

    // 2. Emergency Hotlines (eksaktong pagkakasunod-sunod sa admin_dashboard.php)
    const hotlines = [];
    const police = pick('pnp_hotline', 'police_station', 'police_hotline');
    const fire   = pick('bfp_hotline', 'fire_station', 'fire_hotline');
    const mdrrmo = pick('mdrrmo_hotline', 'mdrrmo');
    const health = pick('health_center_hotline', 'health_center', 'health_hotline');

    if (police) hotlines.push({ name: 'Police Station', desc: 'PNP Emergency Response', number: police, icon: 'ti-shield', tone: 'blue' });
    if (fire)   hotlines.push({ name: 'Fire Station (BFP)', desc: 'Bureau of Fire Protection', number: fire, icon: 'ti-flame', tone: 'red' });
    if (mdrrmo) hotlines.push({ name: 'MDRRMO', desc: 'Disaster Risk Reduction & Rescue', number: mdrrmo, icon: 'ti-ambulance', tone: 'red' });
    if (health) hotlines.push({ name: 'Health Center', desc: 'Medical & First Aid Assistance', number: health, icon: 'ti-heartbeat', tone: 'red' });

    // 3. Other Important Contacts (mula sa "+ Add Contact" / custom_hotlines JSON sa website)
    const rawCustom = pick('custom_hotlines');
    if (rawCustom) {
        try {
            let parsed = JSON.parse(rawCustom);
            if (typeof parsed === 'string') parsed = JSON.parse(parsed);

            if (Array.isArray(parsed)) {
                parsed.forEach(c => {
                    if (!c || typeof c !== 'object') return;
                    const name = String(c.name || c.label || c.title || '').trim();
                    const num  = String(c.number || c.phone || c.contact || '').trim();
                    if (name && num) {
                        hotlines.push({
                            name: name,
                            desc: 'Other Important Contact',
                            number: num,
                            icon: 'ti-phone-call',
                            tone: 'blue'
                        });
                    }
                });
            }
        } catch (e) {
            console.warn('Invalid custom_hotlines JSON:', e);
        }
    }

    return {
        brgyInfo,
        hotlines: hotlines.length > 0 ? hotlines : FALLBACK_HOTLINES,
        hasServerData: true
    };
}

async function fetchBarangayInfoData() {
    try {
        // May cache-buster (?t=...) para laging makuha ang pinakabagong edit sa website
        const response = await fetch(`${API_BASE_URL}/api_get_barangay_info.php?t=${Date.now()}`, {
            method: 'GET',
            headers: {
                'ngrok-skip-browser-warning': 'true',
                'Cache-Control': 'no-cache'
            }
        });
        const result = await response.json();
        const row = normalizeBarangayRow(result.status === 'success' ? result.data : result);

        if (row && (row.pnp_hotline !== undefined || row.barangay_name !== undefined || row.id !== undefined)) {
            await localforage.setItem('cached_barangay_info', row);
            return parseBarangayInfoRow(row);
        }
    } catch (error) {
        console.error('Could not fetch barangay info:', error);
    }

    // Offline fallback: gamitin ang huling na-sync mula sa website
    const cached = await localforage.getItem('cached_barangay_info');
    return parseBarangayInfoRow(cached);
}

async function renderHotlineList(containerId) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = `<div style="text-align:center; padding: 18px; color: var(--text-muted); font-size: 13px;">Syncing hotlines from website...</div>`;

    const { hotlines } = await fetchBarangayInfoData();
    const html = buildHotlineRowsHtml(hotlines);
    container.innerHTML = html || `<div style="text-align:center; padding: 24px 10px; color: var(--text-muted); font-size: 13px;">No hotlines configured on the website yet.</div>`;
}

async function loadDynamicEmergencyInfo() {
    const container = document.getElementById('dynamicEmergencyContainer');
    if (!container) return;

    container.innerHTML = `<div style="text-align:center; padding: 24px; color: var(--text-muted); font-size: 13px;">Loading Emergency & Barangay Info...</div>`;

    const { brgyInfo, hotlines } = await fetchBarangayInfoData();
    let html = '';

    if (brgyInfo.length > 0) {
        html += `<div style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px; margin: 0 0 8px;">Barangay Information</div>`;
        html += `<div class="detail-box" style="margin-bottom: 18px;">`;
        brgyInfo.forEach(item => {
            if (item.isPhone) {
                const dial = String(item.value).replace(/[^\d+]/g, '');
                html += `
                    <div class="detail-row">
                        <span class="detail-key">${escapeHtml(item.label)}</span>
                        <a href="tel:${dial}" style="color: var(--primary-dark); font-weight: 700; text-decoration: none;">${escapeHtml(item.value)}</a>
                    </div>`;
            } else {
                html += `
                    <div class="detail-row">
                        <span class="detail-key">${escapeHtml(item.label)}</span>
                        <span>${escapeHtml(item.value)}</span>
                    </div>`;
            }
        });
        html += `</div>`;
    }

    const hotlinesHtml = buildHotlineRowsHtml(hotlines);
    if (hotlinesHtml) {
        html += `<div style="font-size: 12px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.6px; margin: 0 0 8px;">Emergency Hotlines</div>`;
        html += `<div>${hotlinesHtml}</div>`;
    }

    container.innerHTML = html || `<div style="text-align:center; padding: 24px 10px; color: var(--text-muted); font-size: 13px;">No emergency or barangay information available.</div>`;
}

// Kusang mag-sync ng hotlines pagbukas o pagbalik sa app
window.addEventListener('DOMContentLoaded', () => { fetchBarangayInfoData(); });
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
        fetchBarangayInfoData();
        const hotlinesBox = document.getElementById('home_hotlines_list');
        if (hotlinesBox && hotlinesBox.style.display === 'block') {
            renderHotlineList('home_hotlines_list');
        }
    }
});

function fetchAlerts() {
    return loadAlerts();
}

function filterHomeReports(view, tabEl) {
    document.querySelectorAll('.toggle-tab').forEach(t => t.classList.remove('active'));
    if (tabEl) tabEl.classList.add('active');

    const setDisplay = (id, visible, displayType) => {
        const el = document.getElementById(id);
        if (el) el.style.display = visible ? displayType : 'none';
    };

    setDisplay('box_total', view === 'all', 'flex');
    setDisplay('box_resolved', view === 'resolved', 'flex');
    setDisplay('home_hotlines_list', view === 'hotlines', 'block');

    if (view === 'hotlines') {
        renderHotlineList('home_hotlines_list');
    } else if (typeof loadDashboardStats === 'function') {
        loadDashboardStats();
    }
}

// ==========================================
// 👋 HOME HEADER: USER NAME, PROFILE PICTURE & ADDRESS
// ==========================================
async function loadHomeProfile() {
    try {
        const userSession = await localforage.getItem('user_session');
        if (!userSession) return;

        const greetingEl = document.getElementById('home_greeting');
        const addressEl = document.getElementById('home_address');
        const initialsEl = document.getElementById('home_profile_initials');
        const imgEl = document.getElementById('home_profile_img');

        const fullName = String(userSession.full_name || userSession.fullname || userSession.name || userSession.username || 'User').trim();
        const firstName = fullName.split(/\s+/)[0];

        if (greetingEl) greetingEl.textContent = `Hi, ${firstName} 👋`;

        const address = userSession.address_purok_sitio || userSession.address || userSession.purok;
        if (addressEl && address) addressEl.textContent = address;

        const picture = userSession.profile_pic_data || userSession.profile_picture || userSession.profile_pic;
        if (picture && imgEl) {
            imgEl.src = picture;
            imgEl.style.display = 'block';
            if (initialsEl) initialsEl.style.display = 'none';
        } else {
            if (imgEl) imgEl.style.display = 'none';
            if (initialsEl) {
                const parts = fullName.split(/\s+/);
                let initials = parts[0].charAt(0).toUpperCase();
                if (parts.length > 1) initials += parts[parts.length - 1].charAt(0).toUpperCase();
                initialsEl.textContent = initials;
                initialsEl.style.display = 'block';
            }
        }
    } catch (error) {
        console.error("Could not load home profile: ", error);
    }
}

loadHomeProfile();
window.addEventListener('load', loadHomeProfile);
window.addEventListener('pageshow', loadHomeProfile);
document.addEventListener('visibilitychange', () => { if (!document.hidden) loadHomeProfile(); });

// ==========================================
// 💀 SKELETON LOADING
// ==========================================
const SKELETON_TEMPLATES = {
    history: `
        <div class="sk-card sk-history">
            <div class="sk sk-thumb"></div>
            <div class="sk-lines">
                <div class="sk sk-line" style="width: 72%;"></div>
                <div class="sk-split">
                    <div class="sk sk-line" style="width: 38%; height: 11px;"></div>
                    <div class="sk sk-pill"></div>
                </div>
            </div>
        </div>`,
    feed: `
        <div class="sk-card">
            <div class="sk-split">
                <div class="sk sk-pill" style="width: 104px;"></div>
                <div class="sk sk-line" style="width: 70px; height: 11px;"></div>
            </div>
            <div class="sk sk-line" style="width: 85%; height: 18px; margin-top: 18px;"></div>
            <div class="sk sk-line" style="width: 50%; height: 12px; margin-top: 10px;"></div>
            <div class="sk-pair">
                <div class="sk sk-img"></div>
                <div class="sk sk-img"></div>
            </div>
        </div>`,
    alerts: `
        <div class="sk-card sk-alert">
            <div class="sk-split">
                <div class="sk sk-pill" style="width: 84px; height: 20px;"></div>
                <div class="sk sk-line" style="width: 62px; height: 10px;"></div>
            </div>
            <div class="sk sk-line" style="width: 96%; margin-top: 14px;"></div>
            <div class="sk sk-line" style="width: 64%; margin-top: 8px;"></div>
        </div>`
};

function showSkeleton(container, type, count) {
    if (!container) return;
    if (container.dataset.ready === '1') return;
    if (container.querySelector('.sk')) return;
    const template = SKELETON_TEMPLATES[type] || SKELETON_TEMPLATES.history;
    container.innerHTML = template.repeat(count || 3);
    container.setAttribute('aria-busy', 'true');
}

function markSkeletonReady(container) {
    if (!container) return;
    container.dataset.ready = '1';
    container.removeAttribute('aria-busy');
}

function showLoadError(container, message, retry) {
    if (!container) return;
    container.removeAttribute('aria-busy');
    delete container.dataset.ready;
    container.innerHTML = `
        <div class="load-error">
            <i class="ti ti-wifi-off"></i>
            <p>${escapeHtml(message)}</p>
            <button type="button" class="btn-outline" data-retry><i class="ti ti-refresh"></i> Try again</button>
        </div>`;
    const btn = container.querySelector('[data-retry]');
    if (btn && typeof retry === 'function') btn.addEventListener('click', () => retry());
}

function setStatsLoading(isLoading) {
    document.querySelectorAll('.stat-value').forEach(el => el.classList.toggle('is-loading', !!isLoading));
}

// ==========================================
// 🔍 COMMUNITY FEED FILTERS
// ==========================================
function toggleFeedFilters() {
    const body = document.getElementById('feedFilterBody');
    const icon = document.getElementById('feedFilterIcon');
    if (!body) return;
    const isHidden = body.style.display === 'none' || !body.style.display;
    body.style.display = isHidden ? 'block' : 'none';
    if (icon) icon.className = isHidden ? 'ti ti-chevron-up' : 'ti ti-chevron-down';
}

function clearFeedFilters() {
    const typeEl = document.getElementById('feedFilterType');
    const startEl = document.getElementById('feedFilterStartDate');
    const endEl = document.getElementById('feedFilterEndDate');
    if (typeEl) typeEl.value = 'all';
    if (startEl) startEl.value = '';
    if (endEl) endEl.value = '';
    applyFeedFilters();
}

async function applyFeedFilters() {
    const feedContainer = document.getElementById('feed_container');
    if (!feedContainer) return;

    const filterType = (document.getElementById('feedFilterType')?.value) || 'all';
    const startDate = document.getElementById('feedFilterStartDate')?.value || '';
    const endDate = document.getElementById('feedFilterEndDate')?.value || '';

    try {
        delete feedContainer.dataset.ready;
        showSkeleton(feedContainer, 'feed', 3);

        const userSession = await localforage.getItem('user_session');
        const activeUserId = userSession ? String(userSession.user_id || userSession.id || '') : '';
        const activeName = userSession ? String(userSession.full_name || userSession.username || '').toLowerCase() : '';

        const response = await fetch(`${API_BASE_URL}/api_get_community_feed.php`, {
            method: 'GET',
            headers: { 'ngrok-skip-browser-warning': 'true' }
        });
        const result = await response.json();
        if (result.status !== 'success') {
            showLoadError(feedContainer, result.message || 'Could not load feed.', applyFeedFilters);
            return;
        }

        let reports = result.data || [];

        if (filterType === 'mine' && userSession) {
            reports = reports.filter(r => {
                const ownerId = String(r.resident_id || r.user_id || '');
                if (ownerId && activeUserId) return ownerId === activeUserId;
                const repName = String(r.reporter_name || r.username || '').toLowerCase();
                return activeName && repName === activeName;
            });
        }

        if (startDate) {
            const startMs = new Date(startDate + 'T00:00:00').getTime();
            reports = reports.filter(r => new Date(r.created_at || r.timestamp).getTime() >= startMs);
        }
        if (endDate) {
            const endMs = new Date(endDate + 'T23:59:59').getTime();
            reports = reports.filter(r => new Date(r.created_at || r.timestamp).getTime() <= endMs);
        }

        if (reports.length === 0) {
            feedContainer.innerHTML = `
                <div class="card text-center" style="padding: 40px 20px; text-align:center; border:none; box-shadow:0 4px 15px rgba(0,0,0,0.03);">
                    <i class="ti ti-filter-off" style="font-size: 40px; color: #ccc; margin-bottom: 10px;"></i>
                    <p style="font-size: 18px; font-weight: 600; color: #212529; margin: 0 0 6px;">No matching clean-ups</p>
                    <p style="font-size: 14px; color: #8e8e93; margin: 0;">Try clearing or adjusting your filters.</p>
                </div>`;
            markSkeletonReady(feedContainer);
            return;
        }

        feedContainer.innerHTML = reports.map(report => {
            let dateStr = "Recently";
            if (report.created_at || report.timestamp) {
                dateStr = new Date(report.created_at || report.timestamp).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
            }
            const beforeFile = report.before_photo_path || report.image_data || report.photo;
            const beforeImg = beforeFile
                ? (beforeFile.startsWith('data:image') ? beforeFile : `${API_BASE_URL}/uploads/reports/${beforeFile}`)
                : 'https://placehold.co/300x200/e0e0e0/a0a0a0?text=No+Photo';
            const afterFile = report.after_photo_path || report.after_image || report.after_photo;
            const afterImg = afterFile
                ? (afterFile.startsWith('data:image') ? afterFile : `${API_BASE_URL}/uploads/reports/${afterFile}`)
                : 'https://placehold.co/300x200/e6f8f3/34d399?text=Cleaned+Area';
            const reporterName = escapeHtml(report.reporter_name || report.username || "a concerned resident");
            const desc = escapeHtml(report.description || "Resolved Waste Issue");

            return `
                <div style="background: #ffffff; border-radius: 28px; padding: 24px; margin-bottom: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                        <span style="background: #E6F8F3; color: #34D399; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 4px;">
                            <i class="ti ti-check"></i> Area Cleaned
                        </span>
                        <span style="font-size: 13px; color: #8e8e93; font-weight: 500;">${dateStr}</span>
                    </div>
                    <p style="font-size: 18px; font-weight: 600; margin: 0 0 6px; color: #212529; line-height: 1.4;">"${desc}"</p>
                    <p style="font-size: 14px; color: #8e8e93; font-style: italic; margin: 0 0 20px;">— Reported by ${reporterName}</p>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div style="text-align: center;">
                            <img src="${beforeImg}" style="width: 100%; height: 140px; object-fit: cover; border-radius: 16px; margin-bottom: 8px; border: 1px solid #e5e5ea;">
                            <span style="font-size: 12px; color: #8e8e93; font-weight: 600; letter-spacing: 0.5px;">● BEFORE</span>
                        </div>
                        <div style="text-align: center;">
                            <img src="${afterImg}" style="width: 100%; height: 140px; object-fit: cover; border-radius: 16px; margin-bottom: 8px; border: 1px solid #e5e5ea;">
                            <span style="font-size: 12px; color: #212529; font-weight: 600; letter-spacing: 0.5px;">● AFTER</span>
                        </div>
                    </div>
                </div>`;
        }).join('');
        markSkeletonReady(feedContainer);
    } catch (error) {
        console.error("Feed filter error:", error);
        showLoadError(feedContainer, 'Failed to filter feed.', applyFeedFilters);
    }
}

// ==========================================
// 🗺️ PROFILE FILTERED MAP MODAL
// ==========================================
let profileFilteredMap = null;
let profileFilteredLayer = null;

function parseReportDateMs(rawDate) {
    if (!rawDate) return NaN;
    const str = String(rawDate).trim();
    const normalized = (str.includes(' ') && !str.includes('T')) ? str.replace(' ', 'T') : str;
    return new Date(normalized).getTime();
}

function openFilteredMapModal() {
    const modal = document.getElementById('filteredMapModal');
    if (!modal) return;
    modal.style.display = 'flex';

    if (!profileFilteredMap) {
        profileFilteredMap = L.map('profileFilteredMap').setView([11.45, 123.15], 13);
        applyMapTileLayers(profileFilteredMap, 'hybrid');
        profileFilteredLayer = L.layerGroup().addTo(profileFilteredMap);
    }
    setTimeout(() => {
        profileFilteredMap.invalidateSize();
        applyProfileMapFilters();
    }, 300);
}

function closeFilteredMapModal() {
    const modal = document.getElementById('filteredMapModal');
    if (modal) modal.style.display = 'none';
}

async function applyProfileMapFilters() {
    if (!profileFilteredMap) return;

    const filterType = document.getElementById('profileMapFilterType')?.value || 'all';
    const startDate = document.getElementById('profileMapFilterStartDate')?.value || '';
    const endDate = document.getElementById('profileMapFilterEndDate')?.value || '';
    const applyBtn = document.getElementById('applyProfileMapFiltersBtn');

    if (applyBtn) {
        applyBtn.disabled = true;
        applyBtn.innerHTML = '<i class="ti ti-loader"></i> Filtering...';
    }

    try {
        const userSession = await localforage.getItem('user_session');
        const activeUserId = userSession ? String(userSession.user_id || userSession.id || '') : '';

        let pins = [];

        if (filterType === 'mine') {
            if (!activeUserId) {
                alert('You must be logged in to view your reports.');
                return;
            }
            // Fetch directly from history API so user ownership and created_at are guaranteed
            const res = await fetch(`${API_BASE_URL}/api_get_history.php?user_id=${encodeURIComponent(activeUserId)}`, {
                method: 'GET',
                headers: { 'ngrok-skip-browser-warning': 'true' }
            });
            const data = await res.json();
            if (data.status !== 'success') {
                alert(data.message || 'Could not load your reports.');
                return;
            }
            pins = data.data || [];
        } else {
            const res = await fetch(`${API_BASE_URL}/api_get_map_data.php`, {
                method: 'GET',
                headers: { 'ngrok-skip-browser-warning': 'true' }
            });
            const data = await res.json();
            if (data.status !== 'success') {
                alert(data.message || 'Could not load map reports.');
                return;
            }
            pins = data.data || [];
        }

        // Strict date filtering (never bypasses pins when a date filter is set)
        if (startDate) {
            const startMs = new Date(startDate + 'T00:00:00').getTime();
            pins = pins.filter(p => {
                const reportMs = parseReportDateMs(p.created_at || p.timestamp || p.date_reported);
                return !Number.isNaN(reportMs) && reportMs >= startMs;
            });
        }

        if (endDate) {
            const endMs = new Date(endDate + 'T23:59:59.999').getTime();
            pins = pins.filter(p => {
                const reportMs = parseReportDateMs(p.created_at || p.timestamp || p.date_reported);
                return !Number.isNaN(reportMs) && reportMs <= endMs;
            });
        }

        if (profileFilteredLayer) profileFilteredLayer.clearLayers();

        // Keep only pins with valid coordinates
        pins = pins.filter(p => isFinite(parseFloat(p.latitude)) && isFinite(parseFloat(p.longitude)));

        if (pins.length === 0) {
            if (typeof showAlert === 'function') {
                await showAlert('No reports matched your selected filter and date.', { type: 'info', title: 'No Matching Reports' });
            } else {
                alert('No reports matched your selected filter and date.');
            }
            return;
        }

        const redPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34] });
        const greenPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34] });

        const bounds = [];
        pins.forEach(loc => {
            const lat = parseFloat(loc.latitude);
            const lng = parseFloat(loc.longitude);
            bounds.push([lat, lng]);

            const isPending = String(loc.status || '').toLowerCase() === 'pending';
            const marker = L.marker([lat, lng], { icon: isPending ? redPin : greenPin }).addTo(profileFilteredLayer);
            const imgUrl = loc.image_data || 'https://placehold.co/150x150/e0e0e0/a0a0a0?text=No+Photo';
            const rawDate = loc.created_at || loc.timestamp || '';
            const dateLabel = rawDate ? new Date(String(rawDate).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';

            marker.bindPopup(`
                <div style="text-align:center; min-width:150px;">
                    <img src="${imgUrl}" style="width:100%; height:90px; object-fit:cover; border-radius:8px; margin-bottom:6px;">
                    <div style="font-weight:bold; color:${isPending ? '#dc3545' : '#28a745'};">${isPending ? '🔴 Pending' : '✅ Cleaned'}</div>
                    ${dateLabel ? `<div style="font-size:11px; color:#888; margin-top:2px;">${dateLabel}</div>` : ''}
                    <div style="font-size:12px; text-align:left; color:#555; margin-top:4px;">${escapeHtml(loc.description || '')}</div>
                </div>
            `);
        });

        if (bounds.length > 0) {
            profileFilteredMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 16 });
        }
    } catch (error) {
        console.error("Profile filtered map error:", error);
        alert('Could not filter map reports. Check your connection.');
    } finally {
        if (applyBtn) {
            applyBtn.disabled = false;
            applyBtn.innerHTML = '<i class="ti ti-filter"></i> Apply Filters';
        }
    }
}

// ==========================================
// 🚀 INITIAL STARTUP LOAD (Home Map, Stats & Alerts)
// ==========================================
window.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        if (typeof loadCommunityMap === 'function') loadCommunityMap();
        if (typeof loadDashboardStats === 'function') loadDashboardStats();
        if (typeof checkForNewAlerts === 'function') checkForNewAlerts();
    }, 300);
});

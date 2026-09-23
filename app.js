// www/js/app.js

// 1. Variable to hold the photo data
let capturedPhotoBase64 = null;

// 📸 OPEN LIVE CAMERA
document.getElementById('openCameraBtn').addEventListener('click', async () => {
    try {
        const image = await Capacitor.Plugins.Camera.getPhoto({
            quality: 50,          // Shrinks file size
            width: 800,           // Prevents server crash
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
    
    // Reset to coral warning color while loading
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
        
        // 🚨 TRIGGER YOUR NEW UI CHIP:
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

    // 🚨 FIX 1: INSTANTLY DISABLE BUTTON TO PREVENT DOUBLE-TAPS
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

    const reportData = {
        id: Date.now(),
        resident_id: activeUserId, 
        user_id: activeUserId,
        description: document.getElementById('description').value,
        latitude: document.getElementById('lat').value,
        longitude: document.getElementById('lng').value,
        photo: capturedPhotoBase64, 
        timestamp: new Date().toISOString()
    };

    let offlineReports = await localforage.getItem('pending_waste_reports') || [];
    offlineReports.push(reportData);
    await localforage.setItem('pending_waste_reports', offlineReports);

    alert('Report saved to phone! It will sync automatically when you connect to the internet.');
    
    document.getElementById('offlineReportForm').reset();
    
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
    
    document.getElementById("location_status").innerHTML = "Not pinned yet.";
    document.getElementById("location_status").style.color = "#E53935";
    
    document.getElementById('photoPreview').style.display = 'none';
    document.getElementById('photoPreview').src = '';
    capturedPhotoBase64 = null; 

    // Keep disabled until next time they open the form
    submitBtn.innerHTML = '<i class="ti ti-send"></i> Submit Report';

    syncReports(); 
});

// 🔄 SYNC LOGIC
let isSyncing = false; // 🚨 FIX 2: PREVENT OVERLAPPING SYNCS

async function syncReports() {
    // If a sync is already running, stop and let it finish!
    if (isSyncing) return; 

    const networkStatus = await Capacitor.Plugins.Network.getStatus();
    if (!networkStatus.connected) {
        console.log("No internet. Leaving report in local storage for later.");
        return; 
    }

    let offlineReports = await localforage.getItem('pending_waste_reports') || [];
    if (offlineReports.length === 0) return; 

    // Lock the sync process
    isSyncing = true; 

    alert("Found " + offlineReports.length + " saved reports. Attempting to send...");

    for (let i = 0; i < offlineReports.length; i++) {
        let report = offlineReports[i];

        try {
            let response = await fetch('https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch/api_receive_report.php', {
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
                isSyncing = false; // Unlock on error
                return; 
            }

            if (result.status === 'success') {
                console.log("Successfully synced report!");
            } else {
                alert("Database Error: " + result.message);
                isSyncing = false; // Unlock on error
                return; 
            }
        } catch (error) {
            alert("System Error: " + error.message + " | " + error.name);
            console.error("Sync failed for this report: ", error);
            isSyncing = false; // Unlock on error
            return; 
        }
    }

    // Clear the offline storage ONLY after all are successfully sent
    await localforage.setItem('pending_waste_reports', []);
    
    // Unlock the sync process
    isSyncing = false; 

    alert('🎉 SUCCESS! Your reports have successfully synced to the Barangay Cloud!');

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
    const confirmLogout = confirm("Are you sure you want to log out?");
    if (confirmLogout) {
        await localforage.removeItem('user_session');
        window.location.replace("login.html");
    }
}

// 🗺️ COMMUNITY MAP LOGIC
let communityMap = null;
async function loadCommunityMap() {
    const mapContainer = document.getElementById('communityMap');
    if (!mapContainer) return; 

    if (!communityMap) {
        communityMap = L.map('communityMap').setView([11.45, 123.15], 13); 
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(communityMap);
        setTimeout(() => {
            communityMap.invalidateSize();
        }, 500);
    }

    try {
        let response = await fetch('https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch/api_get_map_data.php', {
            method: 'GET',
            headers: { 'ngrok-skip-browser-warning': 'true' }
        });
        
        let result = await response.json();

        if (result.status === 'success') {
            let mapData = result.data;
            var redPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-red.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34] });
            var greenPin = new L.Icon({ iconUrl: 'https://raw.githubusercontent.com/pointhi/leaflet-color-markers/master/img/marker-icon-2x-green.png', shadowUrl: 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/0.7.7/images/marker-shadow.png', iconSize: [25, 41], iconAnchor: [12, 41], popupAnchor: [1, -34] });

            mapData.forEach(function(loc) {
                var currentIcon = (loc.status === 'Pending') ? redPin : greenPin; 
                var marker = L.marker([loc.latitude, loc.longitude], {icon: currentIcon}).addTo(communityMap);
                
                let imageUrl = loc.image_data ? loc.image_data : 'https://placehold.co/150x150/e0e0e0/a0a0a0?text=No+Photo';

                var popupContent = `
                    <div style="text-align:center; min-width: 150px; font-family: 'Segoe UI', Arial, sans-serif;">
                        <img src="${imageUrl}" style="width: 100%; height: 100px; object-fit: cover; border-radius: 8px; margin-bottom: 8px; border: 1px solid #ccc;">
                        <div style="font-size: 14px; color: ${loc.status === 'Pending' ? '#dc3545' : '#28a745'}; font-weight: bold; margin-bottom: 4px;">
                            ${loc.status === 'Pending' ? '🔴 Pending' : '✅ Cleaned'}
                        </div>
                        <div style="font-size: 12px; text-align: left; color: #555;">
                            <b>Desc:</b> "${loc.description}"
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

        // 🚨 FIXED: Safely grabs user_id OR id:
        const userId = userSession.user_id || userSession.id;

        let response = await fetch(`https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch/api_get_dashboard_stats.php?user_id=${userId}`, {
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

        let response = await fetch(`https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch/api_get_history.php?user_id=${userId}`, {
            method: 'GET',
            headers: { 'ngrok-skip-browser-warning': 'true' }
        });

        let rawText = await response.text();
        let result;
        try {
            result = JSON.parse(rawText);
        } catch (e) {
            alert("PHP Error in History API: \n" + rawText);
            historyContainer.innerHTML = '<div style="text-align:center; padding: 20px; color: #E53935;">Server error. Check the alert pop-up.</div>';
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
                return;
            }

            let html = '';
            reports.forEach((report, index) => {
                let badgeClass = report.status === 'Pending' ? 'badge-pending' : 'badge-done';
                let statusText = report.status === 'Pending' ? 'Pending' : 'Resolved';
                
                let dateStr = "Recently";
                if(report.created_at) {
                    let d = new Date(report.created_at);
                    dateStr = d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
                }

                let imageUrl = report.image_data ? report.image_data : 'https://placehold.co/150x150/e0e0e0/a0a0a0?text=No+Photo';

                // 🚨 BENTO BOX UI + DESCRIPTION AS TITLE FIX
                html += `
                    <div style="background: #ffffff; border-radius: 24px; padding: 20px; margin-bottom: 16px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); cursor: pointer; display: flex; gap: 16px; align-items: center;" onclick="openReportModal(${index})">
                        <img src="${imageUrl}" style="width: 80px; height: 80px; object-fit: cover; border-radius: 16px; border: 1px solid #e5e5ea;">
                        <div style="flex: 1; overflow: hidden;">
                            <p style="font-size: 18px; font-weight: 600; color: #212529; margin: 0 0 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${report.description}</p>
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <p style="font-size: 13px; color: #8e8e93; margin: 0;">${dateStr}</p>
                                <span class="badge ${badgeClass}">${statusText}</span>
                            </div>
                        </div>
                    </div>
                `;
            });

            historyContainer.innerHTML = html;
        } else {
            historyContainer.innerHTML = `<div style="text-align:center; padding: 20px; color: #E53935;">Database Error: ${result.message}</div>`;
        }
    } catch (error) {
        console.error("Could not load history: ", error);
    }
}

// POP-UP MODAL LOGIC
function openReportModal(index) {
    let report = window.historyReports[index];
    if (!report) return;

    // 1. TARGET THE NEW IMAGE CONTAINERS
    const afterContainer = document.getElementById('afterPhotoContainer');
    const imgBefore = document.getElementById('modalImageBefore');
    const imgAfter = document.getElementById('modalImageAfter');

    // 2. SET THE BEFORE PHOTO
    imgBefore.src = report.image_data ? report.image_data : 'https://placehold.co/400x300/e0e0e0/a0a0a0?text=No+Photo';

    // 3. SHOW/HIDE AFTER PHOTO BASED ON STATUS
    let currentStatus = report.status ? report.status.toLowerCase() : 'pending';
    if (currentStatus === 'resolved' || currentStatus === 'cleaned' || currentStatus === 'done') {
        afterContainer.style.display = 'block';
        imgAfter.src = report.after_image_data ? report.after_image_data : 'https://placehold.co/400x300/e0e0e0/a0a0a0?text=No+Photo';
    } else {
        afterContainer.style.display = 'none';
    }

    // 4. POPULATE THE REST OF THE DATA
    document.getElementById('modalName').innerText = window.historyUserName;
    document.getElementById('modalDesc').innerText = report.description;

    let dateStr = "Unknown Date";
    if(report.created_at) {
        let d = new Date(report.created_at);
        dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }
    document.getElementById('modalDate').innerText = dateStr;
    // Switch to OpenStreetMap to bypass Google's aggressive app redirects:
document.getElementById('modalLocation').innerHTML = `<a href="#" onclick="window.location.href='https://www.openstreetmap.org/?mlat=${report.latitude}&mlon=${report.longitude}#map=16/${report.latitude}/${report.longitude}'; return false;" style="color:#1E88E5; text-decoration:none; font-weight:700;">View on Map 📍</a>`;
    let badgeClass = report.status === 'Pending' ? 'badge-pending' : 'badge-done';
    let statusText = report.status === 'Pending' ? 'Pending' : 'Resolved';
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

// 💾 3. Submit Profile Changes to Server
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
            let response = await fetch('https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch/api_update_profile.php', {
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
                // 🚨 THIS WILL SHOW THE REAL PHP/MYSQL ERROR ON YOUR SCREEN:
                alert("PHP Server Error:\n\n" + rawText);
                console.error("Raw PHP Output:", rawText);
                return;
            }

            if (result.status === 'success') {
                await localforage.setItem('user_session', result.user);
                
                alert("🎉 " + result.message);
                closeEditProfileModal();
                
                if (typeof loadDashboardStats === 'function') loadDashboardStats();
                if (typeof loadProfileDisplay === 'function') loadProfileDisplay();
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

// 🔘 TOGGLE BUTTON LISTENER: Shows/Hides the Map
const toggleMapBtn = document.getElementById('toggleManualMapBtn');
if (toggleMapBtn) {
    toggleMapBtn.addEventListener('click', () => {
        const mapBox = document.getElementById('manualMapContainer');
        
        if (mapBox.style.display === 'none' || !mapBox.style.display) {
            // Show Map
            mapBox.style.display = 'block';
            toggleMapBtn.innerHTML = '<i class="ti ti-eye-off"></i> Hide Manual Map';
            toggleMapBtn.style.background = '#E8F5E9'; // Highlights green when open
            
            // Initialize and wake up Leaflet
            initReportPinMap();
        } else {
            // Hide Map
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
        var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 });
        var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', { maxZoom: 19 });

        reportMap = L.map('reportPinMap', {
            center: [11.45, 123.15],
            zoom: 13,
            layers: [osm]
        });

        var baseMaps = {
            "Street View": osm,
            "Satellite View": satellite
        };
        L.control.layers(baseMaps).addTo(reportMap);

        // 🚨 MAP TAP LISTENER: Drop pin and enable Submit button!
        // 🚨 MAP TAP LISTENER: Drop pin and enable Submit button!
        // 🚨 MAP TAP LISTENER: Drop pin and enable Submit button!
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

            // 🚨 FIX: UPDATE UI TO SHOW IT WAS SUCCESSFULLY PINNED
            const statusText = document.getElementById("location_status");
            statusText.innerHTML = `<i class="ti ti-check"></i> Map Pinned! (${lat}, ${lng})`;
            
            if (typeof markLocationPinned === 'function') {
                markLocationPinned(true);
            }
            
            // 🚨 FIX: ENABLE THE SUBMIT BUTTON
            document.getElementById("submit_btn").disabled = false;
        });

        setTimeout(() => { reportMap.invalidateSize(); }, 400);
    } else {
        setTimeout(() => { reportMap.invalidateSize(); }, 300);
    }
}

// 🌟 COMMUNITY FEED DISPLAY LOGIC
async function loadCommunityFeed() {
    const feedContainer = document.getElementById('feed_container');
    if (!feedContainer) return;

    try {
        let response = await fetch('https://unaired-undrilled-shortly.ngrok-free.dev/coastal_land_watch/api_get_community_feed.php', {
            method: 'GET',
            headers: { 'ngrok-skip-browser-warning': 'true' }
        });

        if (!response.ok) return;

        let rawText = await response.text();
        let result;
        try {
            result = JSON.parse(rawText);
        } catch (parseError) {
            console.error("Raw PHP Error: ", rawText);
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
                return;
            }

            let html = '';
            reports.forEach((report) => {
                let dateStr = "Recently";
                if (report.created_at || report.timestamp) {
                    let d = new Date(report.created_at || report.timestamp);
                    dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                }

                let beforeImg = report.before_photo_path || report.image_data || report.photo || 'https://placehold.co/300x200/e0e0e0/a0a0a0?text=No+Photo';
                let afterImg = report.after_photo_path || report.after_image || report.after_photo || 'https://placehold.co/300x200/e6f8f3/34d399?text=Cleaned+Area';

                let reporterName = report.reporter_name || report.username || "a concerned resident";
                let desc = report.description || "Resolved Waste Issue";

                // 🚨 BENTO BOX UI + IMAGE OBJECT-FIT FIX
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
        }
    } catch (error) {
        console.error("Could not load community feed: ", error);
    }
}
let map, incidentLayer, evacLayer; 
let lastTableHTML = ""; 
let evacsVisible = false;
let previousIncidentCount = -1; 
let audioCtx = null;
let soundEnabled = window.soundEnabled ?? false;
let eventSource = null;

const API_PATH = window.location.pathname.includes('/alert/') 
    ? '/alert/admin/admin_actions.php' 
    : 'admin_actions.php';

function initAudio() {
    if (!audioCtx) {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (audioCtx.state === 'suspended') {
        audioCtx.resume();
    }
}

function playSynthesizedSound(severity) {
    if (!soundEnabled) return;
    initAudio(); 
    
    const osc = audioCtx.createOscillator();
    const gainNode = audioCtx.createGain();
    
    osc.connect(gainNode);
    gainNode.connect(audioCtx.destination);
    
    if (severity === 'critical') {
        osc.type = 'square';
        osc.frequency.setValueAtTime(600, audioCtx.currentTime);
        osc.frequency.linearRampToValueAtTime(1000, audioCtx.currentTime + 0.3);
        osc.frequency.linearRampToValueAtTime(600, audioCtx.currentTime + 0.6);
        osc.frequency.linearRampToValueAtTime(1000, audioCtx.currentTime + 0.9);
        osc.frequency.linearRampToValueAtTime(600, audioCtx.currentTime + 1.2);
        gainNode.gain.setValueAtTime(0.5, audioCtx.currentTime);
        osc.start(audioCtx.currentTime);
        osc.stop(audioCtx.currentTime + 1.5);
    } else if (severity === 'major') {
        osc.type = 'sawtooth';
        osc.frequency.setValueAtTime(800, audioCtx.currentTime);
        gainNode.gain.setValueAtTime(0, audioCtx.currentTime);
        gainNode.gain.linearRampToValueAtTime(0.5, audioCtx.currentTime + 0.05);
        gainNode.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.15);
        gainNode.gain.linearRampToValueAtTime(0.5, audioCtx.currentTime + 0.25);
        gainNode.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.35);
        osc.start(audioCtx.currentTime);
        osc.stop(audioCtx.currentTime + 0.4);
    } else {
        osc.type = 'sine';
        osc.frequency.setValueAtTime(880, audioCtx.currentTime);
        gainNode.gain.setValueAtTime(0.5, audioCtx.currentTime);
        gainNode.gain.exponentialRampToValueAtTime(0.01, audioCtx.currentTime + 0.5);
        osc.start(audioCtx.currentTime);
        osc.stop(audioCtx.currentTime + 0.5);
    }
}

function toggleSound() {
    const checkbox = document.getElementById('soundToggleBtn');
    soundEnabled = checkbox.checked;

    if (soundEnabled) {
        try {
            initAudio();
            playSynthesizedSound('minor'); 
        } catch (e) {
            console.error("Audio failed:", e);
            checkbox.checked = false; 
            soundEnabled = false;
            customAlert("Audio Error", "Your browser does not support the Web Audio API.", "bx-error", "#ef4444");
            return;
        }
    }

    let fd = new FormData();
    fd.append('action', 'save_preferences');
    fd.append('sound_alert', soundEnabled ? 1 : 0);

    fetch(API_PATH, { method: 'POST', body: fd })
        .catch(e => console.error("Could not save sound setting:", e));
}

function closeModal(id) { 
    const mod = document.getElementById(id);
    if (mod) mod.style.display = 'none'; 
}

function customAlert(title, message, iconClass = 'bx-info-circle', color = '#3b82f6') {
    document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
    document.getElementById('uniModalIcon').style.color = color;
    document.getElementById('uniModalTitle').innerText = title;
    document.getElementById('uniModalText').innerText = message;
    document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" class="btn-sm" style="flex: 1; background: ${color}; justify-content: center;">OK</button>`;
    document.getElementById('universalModal').style.display = 'flex';
}

function customConfirm(title, message, iconClass, color, confirmCallback) {
    document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
    document.getElementById('uniModalIcon').style.color = color;
    document.getElementById('uniModalTitle').innerText = title;
    document.getElementById('uniModalText').innerText = message;
    document.getElementById('uniModalButtons').innerHTML = `
        <button onclick="closeModal('universalModal')" style="flex:1; padding:10px; border-radius:var(--radius-md); cursor:pointer; border:1px solid var(--border-color); background:transparent; color:var(--text-primary); font-weight:800; font-family:var(--font-family);">Cancel</button>
        <button id="uniConfirmBtn" class="btn-sm" style="flex: 1; padding: 10px; background: ${color}; justify-content: center;">Proceed</button>
    `;
    document.getElementById('universalModal').style.display = 'flex';
    document.getElementById('uniConfirmBtn').onclick = function() { closeModal('universalModal'); confirmCallback(); };
}

function toggleEvacLayer() { 
    const btn = document.getElementById('evac-toggle-btn'); 
    if (map.hasLayer(evacLayer)) {
        map.removeLayer(evacLayer);
        if (btn) { btn.style.background = "var(--surface-card)"; btn.style.color = "var(--text-primary)"; }
    } else {
        map.addLayer(evacLayer);
        if (btn) { btn.style.background = "rgba(16, 185, 129, 0.15)"; btn.style.color = "#10b981"; }
        syncDashboard();
    }
}

function toggleCluster(key) {
    let rows = document.querySelectorAll('.cluster-row-' + key);
    let icon = document.getElementById('icon_' + key);
    
    if (rows.length > 0) {
        let isHidden = rows[0].style.display === 'none';
        rows.forEach(r => r.style.display = isHidden ? 'table-row' : 'none');
        if (icon) icon.className = isHidden ? 'bx bx-folder-minus' : 'bx bx-folder-plus';
    }
}

function toggleBackupRow(incidentId) {
    const row = document.getElementById('backup-row-' + incidentId);
    if (row) {
        row.style.display = (row.style.display === 'none') ? 'table-row' : 'none';
    }
}

function getIncidentIcon(type, severity, backupRequested) { 
    let iconClass = 'bxs-map-pin', iconColor = '#64748b'; 
    let t = (type || '').toLowerCase(); 
    let s = (severity || '').toLowerCase();
    
    let isCritical = (s === 'critical' || backupRequested == 1); 

    if (t.includes('fire')) { iconClass = 'bxs-flame'; iconColor = '#ef4444'; } 
    else if (t.includes('accident')) { iconClass = 'bxs-car-crash'; iconColor = '#f59e0b'; } 
    else if (t.includes('medical')) { iconClass = 'bx-plus-medical'; iconColor = '#10b981'; } 
    else if (t.includes('rescue')) { iconClass = 'bx-support'; iconColor = '#3b82f6'; } 
    else if (t.includes('hazard')) { iconClass = 'bx-error'; iconColor = '#f59e0b'; } 
    else if (t.includes('crime') || t.includes('police')) { iconClass = 'bxs-shield'; iconColor = '#1e293b'; } 
    
    let pulseClass = isCritical ? 'marker-pulse-critical' : '';

    return L.divIcon({ 
        html: `<i class='bx ${iconClass}' style='color: ${iconColor}; font-size: 32px; filter: drop-shadow(0 4px 6px rgba(0,0,0,0.3));'></i>`, 
        className: `custom-leaflet-icon ${pulseClass}`, 
        iconSize: [32, 32], 
        iconAnchor: [16, 32] 
    }); 
}

function applyDashboardUpdates(data) {
    if (!data) return;

    const kpiAct = document.getElementById('kpi-active'); 
    if (kpiAct) {
        let currentCount = parseInt(data.kpi.active) || 0;
        
        if (previousIncidentCount !== -1 && currentCount > previousIncidentCount && soundEnabled) {
            let incidentSeverity = 'minor'; 
            if (data.table) {
                let tempDiv = document.createElement('div');
                tempDiv.innerHTML = data.table;
                let firstRow = tempDiv.querySelector('tr');
                if (firstRow) {
                    let text = firstRow.innerHTML.toLowerCase();
                    if (text.includes('critical')) incidentSeverity = 'critical';
                    else if (text.includes('major') || text.includes('warning')) incidentSeverity = 'major';
                }
            }
            playSynthesizedSound(incidentSeverity);
        }
        
        previousIncidentCount = currentCount; 
        kpiAct.innerText = data.kpi.active; 
    }

    const kpiDep = document.getElementById('kpi-deployed'); if (kpiDep) kpiDep.innerText = data.kpi.deployed; 
    const kpiEvac = document.getElementById('kpi-evacuees'); if (kpiEvac) kpiEvac.innerText = data.kpi.evacuees; 
    
    if (data.kpi_details) {
        const actDet = document.getElementById('kpi-active-details');
        if (actDet) actDet.innerHTML = data.kpi_details.active.length ? data.kpi_details.active.map(d => `<div>${d}</div>`).join('') : '<div>All clear.</div>';
        const depDet = document.getElementById('kpi-deployed-details');
        if (depDet) depDet.innerHTML = data.kpi_details.deployed.length ? data.kpi_details.deployed.map(d => `<div>${d}</div>`).join('') : '<div>No teams active.</div>';
        const evacDet = document.getElementById('kpi-evacuees-details');
        if (evacDet) evacDet.innerHTML = data.kpi_details.evacuees.length ? data.kpi_details.evacuees.map(d => `<div>${d}</div>`).join('') : '<div>All empty.</div>';
    }

    const tBody = document.getElementById('triage-table-body');
    if (tBody && data.table && data.table !== lastTableHTML) { 
        tBody.innerHTML = data.table; 
        lastTableHTML = data.table;
    } 
    
    if (typeof incidentLayer !== 'undefined' && data.map) {
        incidentLayer.clearLayers(); 
        data.map.forEach(inc => { 
            let lat = parseFloat(inc.latitude);
            let lng = parseFloat(inc.longitude);
            let acc = parseFloat(inc.accuracy_meters);
            let hasAccuracy = !isNaN(acc) && acc > 0;
            
            if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
                let accuracyLine = hasAccuracy
                    ? `<br><small style="color:${acc > 30 ? '#f59e0b' : '#3b82f6'};">Location accuracy: \u00b1${Math.round(acc)}m</small>`
                    : '';

                L.marker([lat, lng], { 
                    icon: getIncidentIcon(inc.incident_type, inc.severity, inc.backup_requested) 
                })
                .addTo(incidentLayer)
                .bindPopup(`<b>${inc.incident_type}</b><br>${inc.barangay}<br><small style="color:var(--color-critical); font-weight:bold;">Severity: ${inc.severity || 'Pending'}</small>${accuracyLine}`); 

                if (hasAccuracy) {
                    let displayRadius = Math.min(acc, 200);
                    L.circle([lat, lng], {
                        radius: displayRadius,
                        color: acc > 30 ? '#f59e0b' : '#3b82f6',
                        weight: 1,
                        fillOpacity: 0.08,
                        interactive: false
                    }).addTo(incidentLayer);
                }
            }
        });
    }

    if (typeof evacLayer !== 'undefined' && data.evac_centers) {
        evacLayer.clearLayers();
        data.evac_centers.forEach(evac => {
            let lat = parseFloat(evac.latitude);
            let lng = parseFloat(evac.longitude);
            
            if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
                let eIcon = L.divIcon({ 
                    html: `<i class='bx bxs-home-heart' style='color: #10b981; font-size: 28px; filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));'></i>`, 
                    className: 'custom-leaflet-icon', 
                    iconSize: [28, 28], 
                    iconAnchor: [14, 28] 
                });
                L.marker([lat, lng], { icon: eIcon })
                    .addTo(evacLayer)
                    .bindPopup(`<b>${evac.name}</b><br>Barangay: ${evac.barangay}<br>Occupants: ${evac.current_occupants} / ${evac.capacity}`);
            }
        });
    }
}

function initSSE() {
    if (eventSource) {
        eventSource.close();
        eventSource = null;
    }

    const brgyNode = document.getElementById('table-filter-brgy');
    const typeNode = document.getElementById('map-filter-incident');
    const brgyFilter = brgyNode ? brgyNode.value : ''; 
    const typeFilter = typeNode ? typeNode.value : 'all'; 

    const url = `${API_PATH}?action=sse_stream&brgy=${encodeURIComponent(brgyFilter)}&type=${encodeURIComponent(typeFilter)}`;
    eventSource = new EventSource(url);

    eventSource.onmessage = function(e) {
        try {
            const data = JSON.parse(e.data);
            applyDashboardUpdates(data);
        } catch (err) {
            console.error("SSE parse error:", err);
        }
    };

    eventSource.onerror = function() {
        if (eventSource.readyState === EventSource.CLOSED) {
            setTimeout(initSSE, 3000);
        }
    };
}

function syncDashboard() { 
    const brgyNode = document.getElementById('table-filter-brgy');
    const typeNode = document.getElementById('map-filter-incident');
    const brgyFilter = brgyNode ? brgyNode.value : ''; 
    const typeFilter = typeNode ? typeNode.value : 'all'; 

    fetch(`${API_PATH}?action=master_sync&brgy=${encodeURIComponent(brgyFilter)}&type=${typeFilter}`)
    .then(async res => {
        if (res.status === 401 || res.status === 403) {
            if (eventSource) eventSource.close();
            window.location.href = '../php/login.php';
            return null;
        }
        if (!res.ok) throw new Error(`Network Error: ${res.status}`);
        return res.json();
    })
    .then(data => {
        if (data) applyDashboardUpdates(data);
    })
    .catch(e => {
        console.error(e.message);
    }); 
}

document.addEventListener('DOMContentLoaded', function() { 
    const mapContainer = document.getElementById('dasma-map');
    if (mapContainer) {
        const osmStreet = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: 'OpenStreetMap'
        });

        const darkMatter = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: 'OpenStreetMap',
            className: 'dark-map-tiles'
        });

        const esriSatellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 18, attribution: 'Esri Satellite'
        });

        // Strict Dasmariñas City Bounding Coordinates
        const dasmaBounds = L.latLngBounds(
            [14.2600, 120.9100],
            [14.3750, 121.0100]
        );

        map = L.map('dasma-map', { 
            center: [14.3294, 120.9367], 
            zoom: 13,
            minZoom: 12,
            maxZoom: 18,
            maxBounds: dasmaBounds,
            maxBoundsViscosity: 0.9,
            layers: [osmStreet]
        });

        incidentLayer = L.layerGroup().addTo(map); 
        evacLayer = L.layerGroup().addTo(map);

        const baseMaps = {
            "Street Map": osmStreet,
            "Dark Mode": darkMatter,
            "Satellite": esriSatellite
        };

        const overlayMaps = {
            "Active Incidents": incidentLayer,
            "Evacuation Centers": evacLayer
        };

        L.control.layers(baseMaps, overlayMaps, { position: 'topright' }).addTo(map);

       // Create high-priority pane for the boundary so tiles never obscure it
        map.createPane('boundaryPane');
        map.getPane('boundaryPane').style.zIndex = 450;
        map.getPane('boundaryPane').style.pointerEvents = 'none';

        const boundaryEndpoint = window.location.pathname.includes('/alert/')
            ? '/alert/admin/get_boundary.php'
            : 'get_boundary.php';

        fetch(boundaryEndpoint)
            .then(res => {
                if (!res.ok) throw new Error("HTTP " + res.status);
                return res.json();
            })
            .then(geojsonData => {
                const boundaryLayer = L.geoJSON(geojsonData, {
                    pane: 'boundaryPane',
                    style: {
                        color: '#ff0000',
                        weight: 3,
                        opacity: 1,
                        dashArray: '8, 6',
                        fillColor: '#ff0000',
                        fillOpacity: 0.05
                    }
                }).addTo(map);

                // Fit camera cleanly to the actual perimeter
                map.fitBounds(boundaryLayer.getBounds(), { padding: [15, 15] });
            })
            .catch(err => {
                console.error("Boundary load error:", err);
            });
    }

    initSSE();

    document.getElementById('table-filter-brgy')?.addEventListener('change', initSSE);
    document.getElementById('map-filter-incident')?.addEventListener('change', initSSE);
});

function toggleVerifyDropdown(btn) {
    const wrapper = btn.closest('.verify-btn-wrapper');
    const dropdown = wrapper.querySelector('.verify-dropdown');
    const isHidden = dropdown.style.display === 'none';
    
    document.querySelectorAll('.verify-dropdown').forEach(d => d.style.display = 'none');
    dropdown.style.display = isHidden ? 'block' : 'none';
}

function hideVerifyDropdown(btn) { 
    const drop = btn.closest('.verify-dropdown');
    if (drop) drop.style.display = 'none'; 
}

function confirmVerifyIncident(ids, btn = null) {
    if (btn) {
        const drop = btn.closest('.verify-dropdown');
        if (drop) drop.style.display = 'none';
    }
    const fd = new FormData();
    fd.append('action', 'confirm_verify');
    fd.append('incident_id', ids);

    fetch(API_PATH, { method: 'POST', body: fd })
        .then(async r => {
            const rawText = await r.text();
            try { return JSON.parse(rawText); } 
            catch (e) { throw new Error("Server output: " + rawText.substring(0, 120)); }
        })
        .then(d => { 
            if (d.success) {
                syncDashboard(); 
            } else {
                customAlert("Error", d.message || "Failed to verify.", "bx-error", "#ef4444"); 
            }
        })
        .catch(err => {
            console.error("Verify error:", err);
            customAlert("Server Error", err.message, "bx-error", "#ef4444");
        });
}

function openDeployModal(ids, name) {
    document.getElementById('dispatch_incident_id').value = ids; 
    document.getElementById('dispatch_incident_name').innerText = "Target: " + name;
    
    document.getElementById('available_teams_list').innerHTML = `
        <div style='padding:12px; border-bottom: 1px solid var(--border-color);'>
            <div class="skeleton skeleton-text long"></div>
            <div class="skeleton skeleton-text short"></div>
        </div>
        <div style='padding:12px;'>
            <div class="skeleton skeleton-text long" style="width: 70%;"></div>
            <div class="skeleton skeleton-text short" style="width: 30%;"></div>
        </div>
    `;
    document.getElementById('dispatchModal').style.display = 'flex';

    fetch(API_PATH + "?action=get_available_teams&incident_type=" + encodeURIComponent(name))
        .then(async r => {
            if (!r.ok) throw new Error("HTTP " + r.status);
            return r.json();
        })
        .then(data => {
            let html = '';
            if (!data || data.length === 0) {
                html = "<div style='text-align:center; color:var(--color-critical); font-weight:bold; padding: 20px; background:var(--surface-subtle); border-radius:var(--radius-md);'>No operational units currently available.</div>";
            } else {
                data.forEach(t => {
                    let recBadge = t.is_recommended ? `<span style="background:var(--color-success); color:white; padding: 2px 8px; border-radius: 6px; font-size: 0.65rem; font-weight: 900; margin-left: 8px; vertical-align: middle;">⭐ RECOMMENDED</span>` : "";
                    let recClass = t.is_recommended ? "recommended" : "";
                    
                    html += `<label class="team-label ${recClass}">
                        <input type="checkbox" class="dispatch-team-cb" value="${t.id}" data-name="${t.team_name}">
                        <div style="flex: 1; line-height: 1.35;">
                            <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 4px;">
                                <strong style="color: var(--text-primary); font-size: 0.95rem;">${t.team_name}</strong>
                                <span style="color: var(--text-muted); font-size: 0.8rem; font-weight: 700;">(${t.team_type})</span>
                                ${recBadge}
                            </div>
                            <div style="color: var(--color-info); font-size: 0.78rem; font-weight: 700; margin-top: 3px;">
                                📍 ${t.assigned_barangay || 'City-Wide'}
                            </div>
                        </div>
                    </label>`;
                });
            }
            document.getElementById('available_teams_list').innerHTML = html;
        })
        .catch(err => {
            console.error("Error fetching operational units:", err);
            document.getElementById('available_teams_list').innerHTML = "<div style='text-align:center; color:var(--color-critical); padding: 15px;'>Failed to load operational response teams.</div>";
        });
}

function submitDispatch() {
    let ids = document.getElementById('dispatch_incident_id').value; 
    let cbs = document.querySelectorAll('.dispatch-team-cb:checked');
    if (cbs.length === 0) return customAlert("Selection Required", "Please select at least one unit to deploy.", "bx-error", "#ef4444");

    let teamIds = []; 
    let teamNames = [];
    cbs.forEach(cb => { 
        teamIds.push(cb.value); 
        teamNames.push(cb.getAttribute('data-name')); 
    });

    closeModal('dispatchModal');

    customConfirm("Confirm Dispatch", `Deploy ${teamNames.length} unit(s) to this incident?`, "bxs-truck", "#10b981", function() {
        let fd = new FormData();
        fd.append('action', 'deploy_team');
        fd.append('incident_id', ids); 
        fd.append('team_ids', JSON.stringify(teamIds));
        fd.append('team_names', teamNames.join(", "));

        fetch(API_PATH, { method: 'POST', body: fd })
            .then(async r => {
                const raw = await r.text();
                try { return JSON.parse(raw); } 
                catch (e) { throw new Error("Server output was not JSON: " + raw.substring(0, 100)); }
            })
            .then(d => {
                if (d.success) {
                    syncDashboard();
                } else {
                    customAlert("Dispatch Failed", d.message || "Could not deploy team.", "bx-error", "#ef4444");
                }
            })
            .catch(err => {
                console.error("Dispatch error:", err);
                customAlert("Dispatch Error", err.message || "Failed to communicate with server.", "bx-error", "#ef4444");
            });
    });
}

function cancelDispatch(ids) {
    customConfirm("Recall Units", "Are you sure you want to recall these units and revert the incident status?", "bx-undo", "#ef4444", function() {
        let fd = new FormData();
        fd.append('action', 'recall_team');
        fd.append('incident_id', ids);

        fetch(API_PATH, { method: 'POST', body: fd })
            .then(async r => {
                const raw = await r.text();
                try { return JSON.parse(raw); } 
                catch(e) { throw new Error(raw); }
            })
            .then(d => {
                if (d.success) {
                    syncDashboard();
                } else {
                    customAlert("Error", d.message || "Failed to recall.", "bx-error", "#ef4444");
                }
            })
            .catch(err => {
                console.error(err);
                syncDashboard();
            });
    });
}

function recallIncident(incidentId) {
    cancelDispatch(incidentId);
}

function recallCityBackup(incidentId) {
    customConfirm(
        "Recall City Backup?",
        "Are you sure you want to recall only the City Backup unit? The primary local responder will remain on-scene.",
        "bx-undo",
        "#d32f2f",
        function() {
            let fd = new FormData();
            fd.append('action', 'cancel_backup_dispatch');
            fd.append('incident_id', incidentId);

            fetch(API_PATH, { method: 'POST', body: fd })
                .then(async r => {
                    const raw = await r.text();
                    try { return JSON.parse(raw); } 
                    catch(e) { throw new Error(raw); }
                })
                .then(d => {
                    if (d.success) {
                        syncDashboard();
                    } else {
                        customAlert("Error", d.message || "Could not recall backup unit.", "bx-error", "#ef4444");
                    }
                })
                .catch(err => {
                    console.error("Recall error:", err);
                    syncDashboard();
                });
        }
    );
}

function rejectIncident(ids, typeName = '') {
    const rejectModal = document.getElementById('rejectModal');
    if (rejectModal) {
        const idInput = document.getElementById('reject_incident_ids');
        const disp = document.getElementById('reject_incident_display');
        const cat = document.getElementById('reject_category');
        const notes = document.getElementById('reject_notes');
        
        if (idInput) idInput.value = ids;
        if (disp) disp.innerText = typeName ? `"${typeName}" (ID #${ids})` : `Incident #${ids}`;
        if (cat) cat.value = 'False Alarm';
        if (notes) notes.value = '';
        
        rejectModal.style.display = 'flex';
    } else {
        customConfirm("Reject Incident", "Are you sure you want to reject this incident as a False Alarm?", "bx-x-circle", "#ef4444", function() {
            let fd = new FormData(); 
            fd.append('action', 'reject_incident'); 
            fd.append('incident_id', ids);
            fd.append('reason_category', 'False Alarm');

            fetch(API_PATH, { method: 'POST', body: fd })
                .then(async r => {
                    const raw = await r.text();
                    try { return JSON.parse(raw); } 
                    catch (e) { throw new Error("Server output was not JSON"); }
                })
                .then(d => {
                    if (d.success) {
                        syncDashboard();
                    } else {
                        customAlert("Error", d.message || "Could not reject report.", "bx-error", "#ef4444");
                    }
                })
                .catch(e => {
                    console.error(e);
                    syncDashboard();
                });
        });
    }
}

function submitRejectIncident() {
    const id = document.getElementById('reject_incident_ids')?.value;
    const reasonCategory = document.getElementById('reject_category')?.value || 'False Alarm';
    const notes = document.getElementById('reject_notes')?.value.trim() || '';

    if (!id) return;

    closeModal('rejectModal');

    const fd = new FormData();
    fd.append('action', 'reject_incident');
    fd.append('incident_id', id);
    fd.append('reason_category', reasonCategory);
    fd.append('notes', notes);

    fetch(API_PATH, { method: 'POST', body: fd })
        .then(async r => {
            const raw = await r.text();
            try { return JSON.parse(raw); } 
            catch(e) { throw new Error(raw.substring(0, 100)); }
        })
        .then(d => {
            if (d.success) {
                syncDashboard();
            } else {
                customAlert("Reject Failed", d.message || "Could not reject report.", "bx-error", "#ef4444");
            }
        })
        .catch(err => {
            console.error("Reject error:", err);
            customAlert("Server Error", "Failed to communicate with server.", "bx-error", "#ef4444");
        });
}

function resolveIncident(ids) {
    customConfirm(
        "Mark as Resolved?",
        "This will officially close the incident, automatically archive the connected backup request, and recall any deployed units. Proceed?",
        "bx-check-shield",
        "#10b981",
        function() {
            let fd = new FormData();
            fd.append('action', 'admin_resolve_incident');
            fd.append('incident_id', ids);

            fetch(API_PATH, { method: 'POST', body: fd })
                .then(async r => {
                    const raw = await r.text();
                    try { return JSON.parse(raw); } 
                    catch(e) { throw new Error(raw); }
                })
                .then(d => {
                    if (d.success) {
                        syncDashboard(); 
                    } else {
                        customAlert("Error", d.message || "Failed to resolve.", "bx-error", "#ef4444");
                    }
                })
                .catch(e => {
                    console.error(e);
                    syncDashboard(); 
                });
        }
    );
}

function viewEvidence(imagePath, incidentType = '', brgy = '', date = '', time = '', reporter = '', logs = '', extra = '', backupRequested = 0) { 
    const imgEl = document.getElementById('evidenceImageFull');
    if (!imgEl) return;

    if (imagePath && imagePath !== 'NULL' && imagePath !== 'null' && imagePath.trim() !== '') {
        let cleanPath = imagePath.trim();
        if (cleanPath.startsWith('/http')) cleanPath = cleanPath.substring(1);

        if (cleanPath.startsWith('http://') || cleanPath.startsWith('https://')) {
            imgEl.src = cleanPath;
        } else {
            const stripped = cleanPath.replace(/^\/?(dasma_api\/|dasma-api\/)?/, '');
            imgEl.src = 'https://res.cloudinary.com/wyxsiraw/image/upload/' + stripped;
        }

        const mod = document.getElementById('evidenceModal');
        if (mod) mod.style.display = 'flex';

        if (backupRequested == 1) {
            setTimeout(() => {
                customAlert("🚨 URGENT: BACKUP REQUESTED 🚨", "Immediate assistance requested by local responders!", "bxs-error", "#ef4444");
            }, 300);
        }
    } else {
        customAlert("No Evidence", "No image evidence was submitted for this report.", "bx-image-alt", "#71717a");
    }
}

function dismissBroadcast(id) {
    document.cookie = "dismissed_broadcast_id=" + id + "; path=/; max-age=" + (60 * 60 * 24);
    const banner = document.getElementById('broadcast-banner');
    if (banner) banner.style.display = 'none';
    document.body.classList.remove('has-broadcast');
}

function openAnnouncementModal(id = '', title = '', message = '') {
    document.getElementById('ann_id').value = id;
    document.getElementById('ann_title').value = title;
    document.getElementById('ann_message').value = message.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
    document.getElementById('ann_image').value = ''; 
    
    document.getElementById('annModalTitle').innerHTML = id 
        ? `<i class='bx bx-edit' style='color:var(--color-info);'></i> Edit Announcement` 
        : `<i class='bx bxs-bell-ring' style='color:var(--color-warning);'></i> Create Announcement`;
    
    document.getElementById('announcementModal').style.display = 'flex';
}

function saveAnnouncement() {
    let id = document.getElementById('ann_id').value;
    let title = document.getElementById('ann_title').value.trim();
    let msg = document.getElementById('ann_message').value.trim();
    let img = document.getElementById('ann_image').files[0];

    if (!title || !msg) return customAlert("Required Fields", "Title and Message are required.", "bx-error", "#ef4444");

    let fd = new FormData();
    fd.append('action', 'save_announcement');
    if (id) fd.append('id', id);
    fd.append('title', title);
    fd.append('message', msg);
    if (img) fd.append('image', img);

    fetch(API_PATH, { method: 'POST', body: fd })
    .then(async r => {
        if (!r.ok) {
            let errText = await r.text();
            throw new Error("HTTP " + r.status + ": " + errText);
        }
        return r.text();
    })
    .then(text => {
        if (text.trim() === 'success') {
            closeModal('announcementModal');
            location.reload(); 
        } else {
            customAlert("Server Response", text, "bx-error", "#ef4444");
        }
    }).catch(e => {
        customAlert("Fetch Failed", e.message, "bx-error", "#ef4444");
    });
}

function deleteAnnouncement(id) {
    customConfirm("Delete Announcement", "Are you sure you want to permanently delete this announcement?", "bx-trash", "#ef4444", function() {
        let fd = new FormData();
        fd.append('action', 'delete_announcement');
        fd.append('id', id);

        fetch(API_PATH, { method: 'POST', body: fd })
        .then(r => r.text())
        .then(res => {
            if (res.trim() === 'success') {
                location.reload();
            } else {
                customAlert("Delete Failed", res, "bx-error", "#ef4444");
            }
        })
        .catch(err => customAlert("Error", err.message, "bx-error", "#ef4444"));
    });
}

function openMobileModal(row) {
    if (window.innerWidth > 768) return; 

    const cells = row.querySelectorAll('td');
    if (cells.length < 6) return;

    document.getElementById('m-modal-time').innerHTML = cells[0].innerHTML;
    document.getElementById('m-modal-loc').innerHTML = cells[1].innerHTML;
    document.getElementById('m-modal-info').innerHTML = cells[2].innerHTML;
    document.getElementById('m-modal-ev').innerHTML = cells[3].innerHTML;
    document.getElementById('m-modal-status').innerHTML = cells[4].innerHTML;
    document.getElementById('m-modal-actions').innerHTML = cells[5].innerHTML;

    document.getElementById('mobileIncidentModal').style.display = 'flex';
}

document.querySelectorAll('.kpi-card').forEach(card => {
    card.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
            const isExpanded = this.classList.contains('mobile-expanded');
            document.querySelectorAll('.kpi-card').forEach(c => c.classList.remove('mobile-expanded'));
            if (!isExpanded) this.classList.add('mobile-expanded');
        }
    });
});

window.openDeployModal = openDeployModal;
window.submitDispatch = submitDispatch;
window.cancelDispatch = cancelDispatch;
window.recallIncident = recallIncident;
window.recallCityBackup = recallCityBackup;
window.resolveIncident = resolveIncident;
window.rejectIncident = rejectIncident;
window.submitRejectIncident = submitRejectIncident;
window.viewEvidence = viewEvidence;
window.toggleCluster = toggleCluster;
window.toggleBackupRow = toggleBackupRow;
window.toggleSound = toggleSound;
window.toggleEvacLayer = toggleEvacLayer;
window.syncDashboard = syncDashboard;
window.closeModal = closeModal;
window.openAnnouncementModal = openAnnouncementModal;
window.deleteAnnouncement = deleteAnnouncement;
window.saveAnnouncement = saveAnnouncement;
window.openMobileModal = openMobileModal;
window.dismissBroadcast = dismissBroadcast;
window.toggleVerifyDropdown = toggleVerifyDropdown;
window.hideVerifyDropdown = hideVerifyDropdown;
window.confirmVerifyIncident = confirmVerifyIncident;
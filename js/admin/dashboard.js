
    let map, incidentLayer, evacLayer; 
    let lastTableHTML = "", lastKpiHash = "", lastMapHash = ""; 
    let evacsVisible = false;
    let previousIncidentCount = -1; 
    let audioCtx = null;
    let soundEnabled = window.soundEnabled ?? false;
    const API_PATH = 'admin_actions.php';

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

        // 🚀 Save state to database so it persists across refreshes
        let fd = new FormData();
        fd.append('action', 'save_preferences');
        fd.append('sound_alert', soundEnabled ? 1 : 0);

        fetch(API_PATH, { method: 'POST', body: fd })
            .catch(e => console.error("Could not save sound setting:", e));
    }

    function closeModal(id) { 
        const mod = document.getElementById(id);
        if(mod) mod.style.display = 'none'; 
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
    
   document.addEventListener('DOMContentLoaded', function() { 
    const mapContainer = document.getElementById('dasma-map');
    if (mapContainer) {
        const osmStreet = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: 'OpenStreetMap'
        });

        const darkMatter = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
            maxZoom: 19, attribution: 'CartoDB'
        });

        const esriSatellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
            maxZoom: 18, attribution: 'Esri Satellite'
        });

        const dasmaBounds = L.latLngBounds(
            [14.2500, 120.8900],
            [14.3900, 121.0200]
        );

        map = L.map('dasma-map', { 
            center: [14.3294, 120.9368], 
            zoom: 13,
            minZoom: 13,
            maxBounds: dasmaBounds,
            maxBoundsViscosity: 1.0,
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
    }
    
    // 🚀 Instant bootstrap render (Removes skeleton wait time)
    if (window.initialDashboardData && window.initialDashboardData.kpi) {
        const kpiAct = document.getElementById('kpi-active');
        const kpiDep = document.getElementById('kpi-deployed');
        const kpiEvac = document.getElementById('kpi-evacuees');
        
        if (kpiAct) kpiAct.innerText = window.initialDashboardData.kpi.active;
        if (kpiDep) kpiDep.innerText = window.initialDashboardData.kpi.deployed;
        if (kpiEvac) kpiEvac.innerText = window.initialDashboardData.kpi.evacuees;
    }
    
    fetchWeather();
    syncDashboard(); 
    setInterval(syncDashboard, 5000); 
});
    
    let hasAlertedHeat = sessionStorage.getItem('heat_alerted');

    function applyWeatherData(currentTemp, weatherCode) {
        const tempEl = document.getElementById('weather-temp');
        const alertEl = document.getElementById('weather-alert'); 
        
        if (tempEl) tempEl.innerText = `WEATHER: ${currentTemp}°C`; 
        
        if (alertEl) {
            const cardEl = alertEl.closest('.kpi-card');
            let iconEl = cardEl.querySelector('i');
            
            if (currentTemp >= 40) {
                alertEl.innerText = "Extreme Heat Risk";
                cardEl.className = "kpi-card red";
                if (iconEl) iconEl.className = "bx bxs-hot";
                
                if (!hasAlertedHeat) {
                    sessionStorage.setItem('heat_alerted', 'true');
                    hasAlertedHeat = 'true';
                    
                    let fd = new FormData();
                    fd.append('action', 'send_broadcast');
                    fd.append('title', 'EXTREME HEAT ADVISORY');
                    fd.append('message', `The temperature is currently ${currentTemp}°C. Please stay indoors.`);
                    fd.append('severity', 'critical');
                    
                    fetch(API_PATH, { method: 'POST', body: fd });
                }
            } 
            else if (weatherCode > 50) {
                alertEl.innerText = "Rain / Storm Risk";
                cardEl.className = "kpi-card blue"; 
                if (iconEl) iconEl.className = "bx bxs-cloud-rain";
            } 
            else {
                alertEl.innerText = "Normal Status";
                cardEl.className = "kpi-card yellow";
                if (iconEl) iconEl.className = "bx bxs-cloud";
            }
        }
    }

    function fetchWeather() {
        const cached = localStorage.getItem('weather_cache');
        const cacheExpiry = localStorage.getItem('weather_cache_expiry');
        const now = Date.now();

        // 🚀 Load immediately from cache if valid (< 15 mins)
        if (cached && cacheExpiry && now < parseInt(cacheExpiry, 10)) {
            try {
                const parsed = JSON.parse(cached);
                applyWeatherData(parsed.temp, parsed.code);
                return;
            } catch(e) {}
        }

        fetch('https://api.open-meteo.com/v1/forecast?latitude=14.3294&longitude=120.9368&current_weather=true&timezone=Asia%2FManila')
            .then(res => {
                if (!res.ok) throw new Error("HTTP " + res.status);
                return res.json();
            })
            .then(w => {
                if (w.current_weather) { 
                    const currentTemp = Math.round(w.current_weather.temperature);
                    const weatherCode = w.current_weather.weathercode;
                    
                    // Save to cache for 15 minutes (900,000 ms)
                    localStorage.setItem('weather_cache', JSON.stringify({ temp: currentTemp, code: weatherCode }));
                    localStorage.setItem('weather_cache_expiry', (now + 900000).toString());

                    applyWeatherData(currentTemp, weatherCode);
                }
            }).catch(e => {
                console.log("Weather error:", e);
                const tempEl = document.getElementById('weather-temp');
                const alertEl = document.getElementById('weather-alert');
                if (tempEl) tempEl.innerText = `WEATHER: N/A`; 
                if (alertEl) alertEl.innerText = `Status Offline`;
            });
    }

    function syncDashboard() { 
        const brgyNode = document.getElementById('table-filter-brgy');
        const typeNode = document.getElementById('map-filter-incident');
        const brgyFilter = brgyNode ? brgyNode.value : ''; 
        const typeFilter = typeNode ? typeNode.value : 'all'; 

        fetch(`${API_PATH}?action=master_sync&brgy=${encodeURIComponent(brgyFilter)}&type=${typeFilter}`)
        .then(async res => {
            if(!res.ok) throw new Error(`Network Error: ${res.status} ${res.statusText}`);
            const rawText = await res.text(); 
            try { return JSON.parse(rawText); } 
            catch (err) { throw new Error("PHP Output was not JSON."); }
        })
        .then(data => {
            const kpiAct = document.getElementById('kpi-active'); 
            if(kpiAct) {
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

            const kpiDep = document.getElementById('kpi-deployed'); if(kpiDep) kpiDep.innerText = data.kpi.deployed; 
            const kpiEvac = document.getElementById('kpi-evacuees'); if(kpiEvac) kpiEvac.innerText = data.kpi.evacuees; 
            
            if (data.kpi_details) {
                const actDet = document.getElementById('kpi-active-details');
                if(actDet) actDet.innerHTML = data.kpi_details.active.length ? data.kpi_details.active.map(d => `<div>${d}</div>`).join('') : '<div>All clear.</div>';
                const depDet = document.getElementById('kpi-deployed-details');
                if(depDet) depDet.innerHTML = data.kpi_details.deployed.length ? data.kpi_details.deployed.map(d => `<div>${d}</div>`).join('') : '<div>No teams active.</div>';
                const evacDet = document.getElementById('kpi-evacuees-details');
                if(evacDet) evacDet.innerHTML = data.kpi_details.evacuees.length ? data.kpi_details.evacuees.map(d => `<div>${d}</div>`).join('') : '<div>All empty.</div>';
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
                    
                    if (!isNaN(lat) && !isNaN(lng) && lat !== 0 && lng !== 0) {
                        L.marker([lat, lng], { 
                            icon: getIncidentIcon(inc.incident_type, inc.severity, inc.backup_requested) 
                        })
                        .addTo(incidentLayer)
                        .bindPopup(`<b>${inc.incident_type}</b><br>${inc.barangay}<br><small style="color:var(--color-critical); font-weight:bold;">Severity: ${inc.severity || 'Pending'}</small>`); 
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

        }).catch(e => {
            if(e.message !== "PHP Output was not JSON.") { console.error(e.message); }
        }); 
    }

    function openDeployModal(ids, name) {
        document.getElementById('dispatch_incident_id').value = ids; 
        document.getElementById('dispatch_incident_name').innerText = "Target: " + name;
        
        // Modal skeleton animation
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
            .then(r=>r.json())
            .then(data => {
                let html = '';
                if(data.length === 0) {
                    html = "<div style='text-align:center; color:var(--color-critical); font-weight:bold; padding: 15px;'>No units currently available.</div>";
                } else {
                    data.forEach(t => {
                        let recBadge = t.is_recommended ? `<span style="background:var(--color-success); color:white; padding: 2px 8px; border-radius: 6px; font-size: 0.65rem; font-weight: 900; margin-left: 8px; vertical-align: middle;">⭐ RECOMMENDED</span>` : "";
                        let recClass = t.is_recommended ? "recommended" : "";
                        
                        html += `<label class="team-label ${recClass}">
                            <input type="checkbox" class="dispatch-team-cb" value="${t.id}" data-name="${t.team_name}" style="transform: scale(1.2);">
                            <span style="font-size:1rem;"><b>${t.team_name}</b> <small style="color:var(--text-muted); font-weight:bold;">(${t.team_type})</small> ${recBadge}<br><small style="color:var(--color-info); font-weight:bold;">📍 ${t.assigned_barangay || 'City-Wide'}</small></span>
                        </label>`;
                    });
                }
                document.getElementById('available_teams_list').innerHTML = html;
            });
    }

    function submitDispatch() {
        let ids = document.getElementById('dispatch_incident_id').value; 
        let cbs = document.querySelectorAll('.dispatch-team-cb:checked');
        if(cbs.length === 0) return customAlert("Selection Required", "Please select at least one unit to deploy.", "bx-error", "#ef4444");

        let teamIds = []; let teamNames = [];
        cbs.forEach(cb => { teamIds.push(cb.value); teamNames.push(cb.getAttribute('data-name')); });

        customConfirm("Confirm Dispatch", `Deploy ${teamNames.length} unit(s) to this incident?`, "bxs-truck", "#10b981", function() {
            let fd = new FormData();
            fd.append('action', 'deploy_team');
            fd.append('incident_id', ids); 
            fd.append('team_ids', JSON.stringify(teamIds));
            fd.append('team_names', teamNames.join(", "));

            fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{
                if(d.success) { closeModal('dispatchModal'); syncDashboard(); }
                else customAlert("Error", d.message, "bx-error", "#ef4444");
            });
        });
    }

    function cancelDispatch(ids) {
        customConfirm("Recall Units", "Are you sure you want to recall these units and revert the incident status?", "bx-undo", "#ef4444", function() {
            let fd = new FormData(); fd.append('action', 'cancel_dispatch'); fd.append('id', ids);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.text()).then(d=>syncDashboard());
        });
    }

    function rejectIncident(ids) {
        customConfirm("Reject Incident", "Are you sure you want to reject this incident as a False Alarm?", "bx-x-circle", "#ef4444", function() {
            let fd = new FormData(); fd.append('action', 'reject_incident'); fd.append('incident_id', ids);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.json()).then(d=>syncDashboard());
        });
    }

    function viewEvidence(imagePath, incidentType, brgy, date, time, reporter, logs, extra, backupRequested) { 
        const imgEl = document.getElementById('evidenceImageFull');
        if (imagePath && imagePath !== 'NULL' && imagePath !== '') {
            imgEl.src = '/dasma_api/' + imagePath;
            imgEl.parentElement.style.display = 'flex';
        } else {
            imgEl.parentElement.style.display = 'none';
        }
        
        const typeNode = document.getElementById('evType'); if(typeNode) typeNode.innerText = incidentType;
        const brgyNode = document.getElementById('evBrgy'); if(brgyNode) brgyNode.innerText = brgy;
        const dateNode = document.getElementById('evDateTime'); if(dateNode) dateNode.innerText = `${date} at ${time}`;
        
        const repNode = document.getElementById('evReporter'); 
        if(repNode) repNode.innerHTML = `${reporter} <br><small style="font-size:0.75rem; color:var(--text-muted); font-weight:600;">${extra || ''}</small>`;
        
        const logNode = document.getElementById('evLogs'); 
        if(logNode) logNode.innerText = logs ? `"${logs}"` : "No reporter logs available.";

        const mod = document.getElementById('evidenceModal');
        if(mod) mod.style.display = 'flex'; 

        if (backupRequested == 1) {
            setTimeout(() => {
                customAlert("🚨 URGENT: BACKUP REQUESTED 🚨", "The responder at this location has requested immediate backup/assistance!", "bxs-error", "#ef4444");
            }, 300);
        }
    }

    function dismissBroadcast(id) {
        document.cookie = "dismissed_broadcast_id=" + id + "; path=/; max-age=" + (60 * 60 * 24);
        const banner = document.getElementById('broadcast-banner');
        if(banner) banner.style.display = 'none';
        document.body.classList.remove('has-broadcast');
    }
    document.querySelectorAll('.kpi-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                // Check if already expanded
                const isExpanded = this.classList.contains('mobile-expanded');
                
                // Close all cards first
                document.querySelectorAll('.kpi-card').forEach(c => {
                    c.classList.remove('mobile-expanded');
                });
                
                // If it wasn't expanded, open it now
                if (!isExpanded) {
                    this.classList.add('mobile-expanded');
                }
            }
        });
    });                                       
    function openAnnouncementModal(id = '', title = '', message = '') {
        document.getElementById('ann_id').value = id;
        document.getElementById('ann_title').value = title;
        document.getElementById('ann_message').value = message.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
        document.getElementById('ann_image').value = ''; 
        
        document.getElementById('annModalTitle').innerHTML = id ? `<i class='bx bx-edit' style='color:var(--color-info);'></i> Edit Announcement` : `<i class='bx bxs-bell-ring' style='color:var(--color-warning);'></i> Create Announcement`;
        
        document.getElementById('announcementModal').style.display = 'flex';
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
    function saveAnnouncement() {
        let id = document.getElementById('ann_id').value;
        let title = document.getElementById('ann_title').value.trim();
        let msg = document.getElementById('ann_message').value.trim();
        let img = document.getElementById('ann_image').files[0];

        if(!title || !msg) return customAlert("Required Fields", "Title and Message are required.", "bx-error", "#ef4444");

        let fd = new FormData();
        fd.append('action', 'save_announcement');
        if(id) fd.append('id', id);
        fd.append('title', title);
        fd.append('message', msg);
        if(img) fd.append('image', img);

        fetch('admin_actions.php', { method: 'POST', body: fd })
        .then(async r => {
            if (!r.ok) {
                let errText = await r.text();
                throw new Error("HTTP " + r.status + ": " + errText);
            }
            return r.text();
        })
        .then(text => {
            if(text.trim() === 'success') {
                closeModal('announcementModal');
                location.reload(); 
            } else {
                customAlert("Server Response", text, "bx-error", "#ef4444");
            }
        }).catch(e => {
            customAlert("Fetch Failed", e.message, "bx-error", "#ef4444");
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
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        syncDashboard(); 
                    } else {
                        customAlert("Error", d.message, "bx-error", "#ef4444");
                    }
                }).catch(e => {
                    console.error(e);
                    syncDashboard(); 
                });
            }
        );
    }

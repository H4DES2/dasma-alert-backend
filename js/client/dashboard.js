    const assignedBrgy = window.ASSIGNED_BRGY || "";
    let map, markerLayer;
    let lastTableHTML = "", lastMapHash = "";
    
    const API_PATH = '../admin/admin_actions.php';

    function closeModal(id) { document.getElementById(id).style.display = 'none'; }
    
    function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" class="btn-sm" style="flex: 1; padding: 12px; background: ${color}; justify-content: center; box-shadow: none;">OK</button>`;
        document.getElementById('universalModal').style.display = 'flex';
    }

    function customConfirm(title, message, iconClass, color, confirmCallback) {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" style="flex:1; padding:12px; border-radius:10px; cursor:pointer; border:1px solid #ccc; background:transparent; font-weight:800;">Cancel</button><button id="uniConfirmBtn" class="btn-sm" style="flex: 1; padding: 12px; background: ${color}; justify-content: center;">Proceed</button>`;
        document.getElementById('universalModal').style.display = 'flex';
        document.getElementById('uniConfirmBtn').onclick = function() { closeModal('universalModal'); confirmCallback(); };
    }

    function getIncidentIcon(type) {
        let iconClass = 'bxs-map-pin', iconColor = '#555555'; 
        let t = (type || '').toLowerCase();
        if (t.includes('fire')) { iconClass = 'bxs-flame'; iconColor = '#d32f2f'; } 
        else if (t.includes('accident')) { iconClass = 'bxs-car-crash'; iconColor = '#f57c00'; } 
        else if (t.includes('medical')) { iconClass = 'bx-plus-medical'; iconColor = '#388e3c'; }
        else if (t.includes('rescue')) { iconClass = 'bx-support'; iconColor = '#1976d2'; } 
        else if (t.includes('hazard')) { iconClass = 'bx-error'; iconColor = '#fbc02d'; } 
        else if (t.includes('crime') || t.includes('police')) { iconClass = 'bxs-shield'; iconColor = '#222222'; } 
        return L.divIcon({ html: `<i class='bx ${iconClass}' style='color: ${iconColor}; font-size: 32px;'></i>`, className: 'custom-leaflet-icon', iconSize: [32, 32], iconAnchor: [16, 32] });
    }

    function dismissBroadcast(id) {
        const date = new Date();
        date.setTime(date.getTime() + (24 * 60 * 60 * 1000));
        document.cookie = "dismissed_broadcast_id=" + id + "; expires=" + date.toUTCString() + "; path=/";
        const banner = document.getElementById('global-broadcast-banner');
        if (banner) banner.remove(); 
    }

    let hasAlertedHeat = sessionStorage.getItem('heat_alerted');

    function fetchWeather() {
        fetch('https://api.open-meteo.com/v1/forecast?latitude=14.3294&longitude=120.9368&current=temperature_2m,weather_code&timezone=Asia%2FManila')
            .then(res => res.json())
            .then(w => {
                if(w.current) { 
                    const tempEl = document.getElementById('weather-temp');
                    const labelEl = document.getElementById('weather-label');
                    const currentTemp = Math.round(w.current.temperature_2m);
                    if(tempEl) tempEl.innerText = `Weather: ${currentTemp}°C`; 
                    if(labelEl) {
                        const cardEl = labelEl.closest('.kpi-card');
                        const iconEl = cardEl.querySelector('i');
                        if (currentTemp >= 40) {
                            labelEl.innerText = "Extreme Heat Risk";
                            cardEl.className = "kpi-card red";
                            if(iconEl) { iconEl.className = "bx bxs-hot"; iconEl.style.color = "#d32f2f"; }
                        } 
                        else if (w.current.weather_code > 50) {
                            labelEl.innerText = "Rain / Storm Risk";
                            cardEl.className = "kpi-card blue"; 
                            if(iconEl) { iconEl.className = "bx bxs-cloud-rain"; iconEl.style.color = "#1976d2"; }
                        } 
                        else {
                            labelEl.innerText = "Normal Status";
                            cardEl.className = "kpi-card yellow";
                            if(iconEl) { iconEl.className = "bx bxs-cloud"; iconEl.style.color = "#fbc02d"; }
                        }
                    }
                }
            }).catch(e => console.log("Weather error:", e));
    }

    document.addEventListener('DOMContentLoaded', function() {
        const dasmaBounds = [ [14.2600, 120.9000], [14.3800, 120.9800] ];
        map = L.map('dasma-map', { maxBounds: dasmaBounds, maxBoundsViscosity: 1.0, minZoom: 13 }).setView([14.3294, 120.9368], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
        markerLayer = L.layerGroup().addTo(map);
        fetchWeather();
        fetchLocalData(); 
        setInterval(fetchLocalData, 5000); 
    });

    function fetchLocalData() {
        if (!assignedBrgy) {
            document.getElementById('local-incident-table').innerHTML = '<tr><td colspan="6" style="text-align:center; color:#d32f2f; padding: 40px; font-weight: bold;">No Barangay Assigned to this account.</td></tr>';
            return;
        }
        
        // 1. Fetch Table and Map from admin_actions.php
        fetch(`${API_PATH}?action=master_sync&brgy=${encodeURIComponent(assignedBrgy)}`) 
            .then(async res => {
                if (!res.ok) throw new Error("HTTP Error " + res.status);
                const rawText = await res.text();
                try { return JSON.parse(rawText); } 
                catch (e) { throw new Error("PHP Output was not JSON."); }
            })
            .then(data => {
                if (data.table) {
                    let newTableHTML = data.table;
                    newTableHTML = newTableHTML.replace(/<tr /g, '<tr class="clickable-row" onclick="openMobileModal(this)" ');
                    newTableHTML = newTableHTML.replace(/<td /g, '<td ');
                    
                    if (newTableHTML !== lastTableHTML) {
                        document.getElementById('local-incident-table').innerHTML = newTableHTML;
                        document.querySelectorAll('#local-incident-table tr.clickable-row').forEach(row => {
                            let firstCell = row.querySelector('td:first-child');
                            if(firstCell && !firstCell.querySelector('.mobile-expand-icon') && !row.innerHTML.includes('No active reports')) {
                                firstCell.innerHTML += "<i class='bx bx-chevron-right mobile-expand-icon'></i>";
                            }
                        });
                        lastTableHTML = newTableHTML;
                    }
                }
                if (data.map && JSON.stringify(data.map) !== lastMapHash) {
                    markerLayer.clearLayers();
                    data.map.forEach(inc => {
                        L.marker([inc.latitude, inc.longitude], { icon: getIncidentIcon(inc.incident_type) })
                         .addTo(markerLayer).bindPopup(`<b>${inc.incident_type}</b><br>${inc.barangay}`);
                    });
                    lastMapHash = JSON.stringify(data.map);
                }
            })
            .catch(err => { if (err.message !== "PHP Output was not JSON.") console.error("Sync Error:", err); });

        // 🚀 KPI AJAX FETCH
        fetch(`dashboard.php?ajax_kpi=${encodeURIComponent(assignedBrgy)}`)
            .then(async res => {
                if(!res.ok) throw new Error("KPI Network Error");
                return res.json();
            })
            .then(data => {
                document.getElementById('live-kpi-active').innerText = data.active;
                document.getElementById('live-kpi-deployed').innerText = data.deployed;
                document.getElementById('live-kpi-evac').innerText = data.evac;

                const actDet = document.getElementById('kpi-active-details');
                if(actDet) actDet.innerHTML = data.active_details.length ? data.active_details.join('') : '<div style="background:none; border:none; text-align:center; opacity:0.6; padding:10px;">All clear. No active incidents.</div>';
                
                const depDet = document.getElementById('kpi-deployed-details');
                if(depDet) depDet.innerHTML = data.deployed_details.length ? data.deployed_details.join('') : '<div style="background:none; border:none; text-align:center; opacity:0.6; padding:10px;">No response teams active.</div>';
                
                const evacDet = document.getElementById('kpi-evacuees-details');
                if(evacDet) evacDet.innerHTML = data.evac_details.length ? data.evac_details.join('') : '<div style="background:none; border:none; text-align:center; opacity:0.6; padding:10px;">All evacuation centers empty.</div>';
            }).catch(e => console.log("KPI Sync Error:", e));
    }

    function toggleVerifyDropdown(btn) {
        const wrapper = btn.closest('.verify-btn-wrapper');
        const dropdown = wrapper.querySelector('.verify-dropdown');
        const isHidden = dropdown.style.display === 'none';
        
        document.querySelectorAll('.verify-dropdown').forEach(d => d.style.display = 'none');
        dropdown.style.display = isHidden ? 'block' : 'none';
    }

    function hideVerifyDropdown(btn) { btn.closest('.verify-dropdown').style.display = 'none'; }

    document.addEventListener('click', function(event) {
        if (event.target.classList.contains('confirm-verify-btn')) {
            const ids = event.target.getAttribute('data-confirm-ids');
            const fd = new FormData();
            fd.append('action', 'confirm_verify');
            fd.append('incident_id', ids);

            fetch(API_PATH, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => { if (d.success) fetchLocalData(); })
                .catch(e => console.error(e));
        }
    });

    function openVerifyModal(ids) {
        document.getElementById('verify_incident_ids').value = ids;
        document.getElementById('verifyModal').style.display = 'flex';
    }

    function submitVerify() {
        let ids = document.getElementById('verify_incident_ids').value;
        let severity = document.getElementById('verify_severity').value;
        let remarks = document.getElementById('verify_remarks').value;
        let fd = new FormData();
        fd.append('action', 'verify_incident');
        fd.append('incident_id', ids);
        fd.append('severity', severity);
        fd.append('remarks', remarks);
        fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.text()).then(d=>{ closeModal('verifyModal'); fetchLocalData(); });
    }

    function openDeployModal(id, name) {
        document.getElementById('dispatch_incident_id').value = id; 
        document.getElementById('dispatch_incident_name').innerText = "Target: " + name;
        document.getElementById('available_teams_list').innerHTML = "<div style='text-align:center; padding:10px; color:#888;'><i class='bx bx-loader-alt bx-spin'></i> Loading units...</div>";
        document.getElementById('dispatchModal').style.display = 'flex';
        fetch(API_PATH + "?action=get_available_teams&incident_type=" + encodeURIComponent(name))
            .then(r=>r.json())
            .then(data => {
                let html = '';
                if(data.length === 0) { html = "<div style='text-align:center; color:#d32f2f; font-weight:bold; padding: 15px;'>No units currently available.</div>"; } 
                else {
                    data.forEach(t => {
                        let recBadge = t.is_recommended ? `<span style="background:#388e3c; color:white; padding: 2px 8px; border-radius: 8px; font-size: 0.65rem; font-weight: 900; margin-left: 8px;">⭐ RECOMMENDED</span>` : "";
                        // AFTER
const isDark = document.documentElement.classList.contains('global-dark-mode');
let bgStyle = "";
if (t.is_recommended) {
    bgStyle = isDark ? "background: rgba(56, 142, 60, 0.2); border-left: 4px solid #388e3c;" : "background: #f1f8e9; border-left: 4px solid #388e3c;";
}
html += `<label style="display:flex; align-items:center; gap:12px; padding:12px; border-bottom:1px solid var(--border-color, #edf2f7); cursor:pointer; ${bgStyle}">
    <input type="checkbox" class="dispatch-team-cb" value="${t.id}" data-name="${t.team_name}" style="transform: scale(1.3);">
    <span style="font-size:1.05rem;">
        <b>${t.team_name}</b> <small style="opacity: 0.7;">(${t.team_type})</small> ${recBadge}<br>
        <span style="color:#1976d2; font-size:0.8rem; font-weight:bold;">📍 ${t.assigned_barangay || 'City-Wide'}</span>
    </span>
</label>`;
                    });
                }
                document.getElementById('available_teams_list').innerHTML = html;
            });
    }

    function submitDispatch() {
        let ids = document.getElementById('dispatch_incident_id').value;
        let cbs = document.querySelectorAll('.dispatch-team-cb:checked');
        if(cbs.length === 0) return customAlert("Selection Required", "Select units to deploy.", "bx-error", "#d32f2f");
        let teamIds = []; let teamNames = [];
        cbs.forEach(cb => { teamIds.push(cb.value); teamNames.push(cb.getAttribute('data-name')); });
        closeModal('dispatchModal');
        customConfirm("Confirm Dispatch", `Deploy ${teamNames.length} unit(s)?`, "bxs-truck", "#388e3c", function() {
            let fd = new FormData();
            fd.append('action', 'deploy_team');
            fd.append('incident_id', ids); 
            fd.append('team_ids', JSON.stringify(teamIds));
            fd.append('team_names', teamNames.join(", "));
            fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.json()).then(d=>{ if(d.success) fetchLocalData(); else customAlert("Error", d.message, "bx-error", "#d32f2f"); });
        });
    }

    function cancelDispatch(ids) {
        customConfirm("Recall Units", "Recall units and revert status?", "bx-undo", "#d32f2f", function() {
            let fd = new FormData(); fd.append('action', 'cancel_dispatch'); fd.append('id', ids);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r=>r.text()).then(d=>fetchLocalData());
        });
    }

    function resolveIncident(id) {
        customConfirm("Mark Resolved?", "Close incident and recall units?", "bx-check-shield", "#388e3c", function() {
            let fd = new FormData(); fd.append('action', 'admin_resolve_incident'); fd.append('incident_id', id);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (d.success) fetchLocalData(); }).catch(e => fetchLocalData());
        });
    }

    function viewEvidence(imagePath, incidentType, brgy, date, time, reporter, logs, extra, backupRequested) { 
        const imgEl = document.getElementById('evidenceImageFull');
        if (imagePath && imagePath !== 'NULL' && imagePath !== '') {
            let cleanPath = imagePath.startsWith('/') ? imagePath.substring(1) : imagePath;
            imgEl.src = '/dasma_api/' + cleanPath;
            imgEl.style.display = 'inline-block';
        } else { imgEl.style.display = 'none'; }
        
        document.getElementById('evidenceCaption').innerHTML = `
            <div style="font-weight: 900; font-size: 1.1rem; margin-bottom: 5px;">${incidentType} in Brgy. ${brgy}</div>
            <div style="font-size: 0.85rem; font-weight: 800; margin-bottom: 5px;">Reported by ${reporter} at ${time}</div>
            <div style="font-style: italic; font-size: 0.95rem;">"${logs}"</div>
        `;
        document.getElementById('evidenceModal').style.display = 'flex'; 
        
        if (backupRequested == 1) { setTimeout(() => { customAlert("🚨 URGENT: BACKUP REQUESTED 🚨", "Immediate assistance requested!", "bxs-error", "#d32f2f"); }, 300); }
    }

    function rejectIncident(id) {
        customConfirm("Reject Incident?", "Is this a false alarm?", "bx-x-circle", "#555555", function() {
            let fd = new FormData(); fd.append('action', 'reject_incident'); fd.append('incident_id', id);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (d.success) fetchLocalData(); });
        });
    }

    function requestBackup(id) {
        customConfirm("Request Backup?", "Alert City Superadmin?", "bxs-error-circle", "#f57c00", function() {
            let fd = new FormData(); fd.append('action', 'request_backup'); fd.append('incident_id', id);
            fetch(API_PATH, { method: 'POST', body: fd }).then(r => r.json()).then(d => { if (d.success) fetchLocalData(); });
        });
    }

    document.querySelectorAll('.kpi-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const isExpanded = this.classList.contains('mobile-expanded');
                document.querySelectorAll('.kpi-card').forEach(c => c.classList.remove('mobile-expanded'));
                if (!isExpanded) this.classList.add('mobile-expanded');
            }
        });
    });

    function openMobileModal(row) {
        if (window.innerWidth > 768) return; 
        
        const cells = row.querySelectorAll('td');
        if (cells.length < 6) return;

        const bodyEl = document.getElementById('m-incident-body');
        
        let html = '';
        html += `<div class="mobile-detail-box"><small class="mobile-label">Time</small>${cells[0].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Location</small>${cells[1].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Incident Type</small>${cells[2].innerHTML}</div>`;
        html += `<div class="mobile-detail-box" style="text-align: center;"><small class="mobile-label">Evidence</small>${cells[3].innerHTML}</div>`;
        html += `<div class="mobile-detail-box" style="text-align: center;"><small class="mobile-label">Severity & Status</small>${cells[4].innerHTML}</div>`;
        html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; flex-direction:column; gap:10px; width:100%;">${cells[5].innerHTML}</div></div>`;

        bodyEl.innerHTML = html;
        
        let actionContainer = bodyEl.querySelector('.m-actions-container');
        if (actionContainer) {
            let actionBtnContainer = actionContainer.querySelector('.action-btn-container');
            if (actionBtnContainer) actionBtnContainer.style.width = '100%';
            
            actionContainer.querySelectorAll('button').forEach(b => { 
                b.style.width = '100%'; b.style.justifyContent = 'center'; 
            });
            
            let verifyWrapper = actionContainer.querySelector('.verify-btn-wrapper');
            if(verifyWrapper) verifyWrapper.style.width = '100%';
        }

        bodyEl.querySelectorAll('.mobile-expand-icon').forEach(icon => icon.style.display = 'none');
        document.getElementById('mobileIncidentModal').style.display = 'flex';
    }
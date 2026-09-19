let currentDeployTeamId = null;
let currentDeployTeamName = ""; 

const API_PATH = window.location.pathname.includes('/alert/') 
    ? '/alert/admin/admin_actions.php' 
    : '../admin/admin_actions.php';

function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
    const icon = document.getElementById('uniModalIcon');
    const titleEl = document.getElementById('uniModalTitle');
    const textEl = document.getElementById('uniModalText');
    const buttons = document.getElementById('uniModalButtons');
    const modal = document.getElementById('universalModal');

    if (!modal) {
        alert(`${title}: ${message}`);
        return;
    }

    if (icon) { icon.className = 'bx ' + iconClass; icon.style.color = color; }
    if (titleEl) titleEl.innerText = title;
    if (textEl) textEl.innerText = message;
    if (buttons) {
        buttons.innerHTML = `<button onclick="closeModal('universalModal')" class="btn-sm" style="flex: 1; background: ${color}; justify-content: center; height: 46px; border-radius: 10px;">OK</button>`;
    }
    modal.style.display = 'flex';
}

function customConfirm(title, message, iconClass, color, confirmCallback) {
    const icon = document.getElementById('uniModalIcon');
    const titleEl = document.getElementById('uniModalTitle');
    const textEl = document.getElementById('uniModalText');
    const buttons = document.getElementById('uniModalButtons');
    const modal = document.getElementById('universalModal');

    if (!modal) {
        if (confirm(`${title}\n\n${message}`)) confirmCallback();
        return;
    }

    if (icon) { icon.className = 'bx ' + iconClass; icon.style.color = color; }
    if (titleEl) titleEl.innerText = title;
    if (textEl) textEl.innerText = message;
    if (buttons) {
        buttons.innerHTML = `
            <button onclick="closeModal('universalModal')" class="modal-cancel-btn" style="flex: 1; height: 46px; border-radius: 10px; border: 1px solid #ccc; background: transparent; font-weight: 700; cursor: pointer;">Cancel</button>
            <button id="uniConfirmBtn" class="btn-sm" style="flex: 1; background: ${color}; justify-content: center; height: 46px; border-radius: 10px; font-weight: 800;">Proceed</button>
        `;
    }
    modal.style.display = 'flex';
    
    const confirmBtn = document.getElementById('uniConfirmBtn');
    if (confirmBtn) {
        confirmBtn.onclick = function() {
            closeModal('universalModal');
            confirmCallback();
        };
    }
}

function closeModal(id) { 
    const el = document.getElementById(id);
    if (el) el.style.display = 'none'; 
}

function handleServerResponse(fetchPromise) {
    fetchPromise
        .then(async res => {
            const text = await res.text();
            let data = text.trim();
            if (!data) throw new Error("Empty response from server.");
            
            if (data.startsWith('{')) { 
                let json = JSON.parse(data);
                if (json.success) location.reload();
                else customAlert("Error", json.message || json.error || "Action failed.", "bx-x-circle", "#d32f2f");
            } else { 
                if (data === 'success') location.reload();
                else customAlert("Server Alert", data, "bx-info-circle", "#f57c00");
            }
        })
        .catch(err => {
            customAlert("System Error", err.toString(), "bx-error", "#d32f2f");
        });
}

function updateStatus(id, newStatus) {
    const displayStatus = newStatus === 'operational' ? 'OPERATIONAL' : newStatus.toUpperCase();
    customConfirm("Update Status?", `Mark this unit as ${displayStatus}?`, "bx-refresh", "#1976d2", function() {
        let formData = new FormData();
        formData.append('action', 'update_team_status'); 
        formData.append('id', id); 
        formData.append('status', newStatus);
        
        handleServerResponse(fetch(API_PATH, { method: 'POST', body: formData }));
    });
}

function viewTeamMembers(teamId, teamName) {
    const titleEl = document.getElementById('tm_title');
    const contentEl = document.getElementById('tm_content');
    const modal = document.getElementById('teamMembersModal');

    if (titleEl) titleEl.innerText = teamName;
    if (contentEl) contentEl.innerHTML = '<div style="text-align:center; padding: 20px; opacity:0.6;"><i class="bx bx-loader-alt bx-spin"></i> Loading...</div>';
    if (modal) modal.style.display = 'flex';

    fetch(`${API_PATH}?action=get_team_members&team_id=${teamId}`)
        .then(res => res.json())
        .then(data => {
            if (!contentEl) return;
            if (!data || data.length === 0) {
                contentEl.innerHTML = '<div style="text-align:center; padding: 20px; font-weight: bold; color: #888;">No responders assigned to this unit yet.</div>';
            } else {
                let html = '';
                data.forEach(user => {
                    html += `<div style="background: #f8f9fa; padding: 15px; border-radius: 15px; margin-bottom: 10px; border: 1px solid #edf2f7; display:flex; align-items:center; gap: 15px;">
                        <div style="background: #eef2f7; width: 45px; height: 45px; border-radius: 50%; display:flex; align-items:center; justify-content:center;">
                            <i class='bx bxs-user-badge' style="font-size:1.5rem; color:#1976d2;"></i>
                        </div>
                        <div>
                            <div style="font-weight: 800; color: #333; font-size:1.1rem;">${user.first_name} ${user.last_name}</div>
                            <div style="font-size: 0.8rem; color: #888; text-transform: uppercase; font-weight: 800; margin-top:2px;"><i class='bx bx-radio'></i> ${user.radio_callsign || 'No Callsign'}</div>
                        </div>
                    </div>`;
                });
                contentEl.innerHTML = html;
            }
        })
        .catch(e => {
            if (contentEl) contentEl.innerHTML = '<div style="text-align:center; color: #d32f2f; font-weight:bold;">Failed to load personnel.</div>';
        });
}

function openAddUnitModal() {
    const input = document.getElementById('new_team_name');
    if (input) input.value = "";
    const modal = document.getElementById('addUnitModal');
    if (modal) modal.style.display = 'flex';
}

function submitNewUnit() {
    let name = document.getElementById('new_team_name')?.value.trim();
    let type = document.getElementById('new_team_type')?.value;
    
    if (!name) return customAlert("Missing Name", "Please enter a name for the unit.", "bx-error-circle", "#d32f2f");
    
    customConfirm("Register Unit?", `Are you sure you want to officially register ${name} as a ${type} unit?`, "bx-check-shield", "#228b22", function() {
        let formData = new FormData();
        formData.append('action', 'add_team'); 
        formData.append('team_name', name); 
        formData.append('team_type', type);
        
        handleServerResponse(fetch(API_PATH, { method: 'POST', body: formData }));
    });
}

// KPI Click for Mobile Expansion
document.querySelectorAll('.kpi-card').forEach(card => {
    card.addEventListener('click', function() {
        if (window.innerWidth <= 768) {
            const isExpanded = this.classList.contains('mobile-expanded');
            document.querySelectorAll('.kpi-card').forEach(c => c.classList.remove('mobile-expanded'));
            if (!isExpanded) this.classList.add('mobile-expanded');
        }
    });
});

window.updateStatus = updateStatus;
window.viewTeamMembers = viewTeamMembers;
window.openAddUnitModal = openAddUnitModal;
window.submitNewUnit = submitNewUnit;
window.closeModal = closeModal;
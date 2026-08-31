
    let currentDeployTeamId = null;
    let currentDeployTeamName = ""; 

    function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" class="btn-sm" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">OK</button>`;
        document.getElementById('universalModal').style.display = 'flex';
    }

    function customConfirm(title, message, iconClass, color, confirmCallback) {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        let cancelBtn = `<button onclick="closeModal('universalModal')" class="modal-cancel-btn" style="height: 50px;">Cancel</button>`;
        let confirmBtn = `<button id="uniConfirmBtn" class="btn-sm" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">Proceed</button>`;
        document.getElementById('uniModalButtons').innerHTML = cancelBtn + confirmBtn;
        document.getElementById('universalModal').style.display = 'flex';
        document.getElementById('uniConfirmBtn').onclick = function() { closeModal('universalModal'); confirmCallback(); };
    }

    function closeModal(id) { document.getElementById(id).style.display = 'none'; }

    function handleServerResponse(fetchPromise) {
        fetchPromise.then(res => res.text()).then(text => {
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
        }).catch(err => {
            customAlert("System Error", err.toString(), "bx-error", "#d32f2f");
        });
    }

    function openAddUnitModal() {
        document.getElementById('new_team_name').value = "";
        document.getElementById('addUnitModal').style.display = 'flex';
    }

    function submitNewUnit() {
        let name = document.getElementById('new_team_name').value.trim();
        let type = document.getElementById('new_team_type').value;
        
        if(!name) return customAlert("Missing Name", "Please enter a name for the unit.", "bx-error-circle", "#d32f2f");
        
        customConfirm("Register Unit?", `Are you sure you want to officially register ${name} as a ${type} unit?`, "bx-check-shield", "#228b22", function() {
            let formData = new FormData();
            formData.append('action', 'add_team'); 
            formData.append('team_name', name); 
            formData.append('team_type', type);
            
            handleServerResponse(fetch('../admin/admin_actions.php', { method: 'POST', body: formData }));
        });
    }

    function viewTeamMembers(teamId, teamName) {
        document.getElementById('tm_title').innerText = teamName;
        document.getElementById('tm_content').innerHTML = '<div style="text-align:center; padding: 20px; opacity:0.6;"><i class="bx bx-loader-alt bx-spin"></i> Loading...</div>';
        document.getElementById('teamMembersModal').style.display = 'flex';

        fetch(`../admin/admin_actions.php?action=get_team_members&team_id=${teamId}`)
        .then(res => res.json())
        .then(data => {
            if(data.length === 0) {
                document.getElementById('tm_content').innerHTML = '<div style="text-align:center; padding: 20px; font-weight: bold; color: #888;">No responders assigned to this unit yet.</div>';
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
                document.getElementById('tm_content').innerHTML = html;
            }
        }).catch(e => {
            document.getElementById('tm_content').innerHTML = '<div style="text-align:center; color: #d32f2f; font-weight:bold;">Failed to load personnel.</div>';
        });
    }

    function openDeployModal(teamId, teamName) {
        const incidentCount = document.getElementById('deploy_incident_id').options.length;
        if (incidentCount <= 1) { 
            customAlert("No Targets", "There are no active incidents on the map to deploy units to!", "bx-error-circle", "#fbc02d");
            return;
        }
        currentDeployTeamId = teamId;
        currentDeployTeamName = teamName; 
        document.getElementById('deployTeamName').innerText = "Deploying: " + teamName;
        document.getElementById('deployModal').style.display = 'flex';
    }

    function confirmDeployment() {
        let incidentId = document.getElementById('deploy_incident_id').value;
        if(!incidentId) return customAlert("Selection Required", "Please select a destination from the list.", "bx-error-circle", "#d32f2f");
        let formData = new FormData();
        formData.append('action', 'deploy_team'); 
        formData.append('team_ids', JSON.stringify([currentDeployTeamId])); 
        formData.append('incident_id', incidentId);
        formData.append('team_names', currentDeployTeamName); 
        
        handleServerResponse(fetch('../admin/admin_actions.php', { method: 'POST', body: formData }));
    }

    function updateStatus(id, newStatus) {
        customConfirm("Update Status?", `Mark this unit as ${newStatus.toUpperCase()}?`, "bx-refresh", "#1976d2", function() {
            let formData = new FormData();
            formData.append('action', 'update_team_status'); 
            formData.append('id', id); 
            formData.append('status', newStatus);
            
            handleServerResponse(fetch('../admin/admin_actions.php', { method: 'POST', body: formData }));
        });
    }
    // 🚀 Handle KPI Click for Mobile Expansion
    document.querySelectorAll('.kpi-card').forEach(card => {
        card.addEventListener('click', function(e) {
            if (window.innerWidth <= 768) {
                const isExpanded = this.classList.contains('mobile-expanded');
                document.querySelectorAll('.kpi-card').forEach(c => c.classList.remove('mobile-expanded'));
                if (!isExpanded) this.classList.add('mobile-expanded');
            }
        });
    });
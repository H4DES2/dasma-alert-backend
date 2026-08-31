    
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
                else customAlert("Error", json.message || "Action failed.", "bx-x-circle", "#d32f2f");
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
        let brgy = document.getElementById('new_team_brgy').value;

        if(!name) return customAlert("Missing Name", "Please enter a name for the unit.", "bx-error-circle", "#d32f2f");
        
        let brgyText = brgy ? `assigned to ${brgy}` : "as a City-Wide unit";

        customConfirm("Register Unit?", `Are you sure you want to register ${name} (${type}) ${brgyText}?`, "bx-check-shield", "#228b22", function() {
            let formData = new FormData();
            formData.append('action', 'add_team'); 
            formData.append('team_name', name); 
            formData.append('team_type', type);
            formData.append('assigned_barangay', brgy); 
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
                    // 🚀 FIX: Online/Offline Status Logic
                    let isOnline = (user.is_online == 1 || user.is_online == '1');
                    let statusColor = isOnline ? '#3ada38' : '#888888';
                    let statusText = isOnline ? 'Online' : 'Offline';
                    let statusBg = isOnline ? 'rgba(58, 218, 56, 0.1)' : 'rgba(136, 136, 136, 0.1)';

                    html += `<div class="team-member-card" style="background: #f8f9fa; padding: 15px; border-radius: 15px; margin-bottom: 10px; border: 1px solid #edf2f7; display:flex; align-items:center; gap: 15px;">
                        <div class="team-member-icon-bg" style="background: #eef2f7; width: 45px; height: 45px; border-radius: 50%; display:flex; align-items:center; justify-content:center; position:relative;">
                            <i class='bx bxs-user-badge' style="font-size:1.5rem; color:#1976d2;"></i>
                            <span class="status-border" style="position: absolute; bottom: -2px; right: -2px; width: 14px; height: 14px; background: ${statusColor}; border: 3px solid #f8f9fa; border-radius: 50%;"></span>
                        </div>
                        <div style="flex: 1;">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <div class="team-member-name" style="font-weight: 800; color: #333; font-size:1.1rem;">${user.first_name} ${user.last_name}</div>
                                <div style="background: ${statusBg}; color: ${statusColor}; padding: 4px 8px; border-radius: 8px; font-size: 0.65rem; font-weight: 900; text-transform: uppercase; letter-spacing: 0.5px;">${statusText}</div>
                            </div>
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
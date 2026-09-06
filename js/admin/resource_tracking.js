    
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
   function deleteTeam(teamId, teamName) {
    customConfirm(
        "Delete Response Unit?",
        `Are you sure you want to permanently delete "${teamName}"? This action cannot be undone.`,
        "bx-trash",
        "#d32f2f",
        function() {
            let formData = new FormData();
            formData.append('action', 'delete_team');
            formData.append('id', teamId);

            // Use direct path in the same directory, passing action in query param as a fail-safe
            fetch('admin_actions.php?action=delete_team', {
                method: 'POST',
                body: formData
            })
            .then(res => res.text())
            .then(text => {
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    console.error("Server output:", text);
                    throw new Error("Invalid response from server: " + text.substring(0, 100));
                }

                if (data.success) {
                    location.reload();
                } else {
                    customAlert("Delete Failed", data.message || "Could not delete unit.", "bx-error", "#d32f2f");
                }
            })
            .catch(err => {
                console.error("Delete error:", err);
                customAlert("Server Error", err.message || "An error occurred while deleting the unit.", "bx-error", "#d32f2f");
            });
        }
    );
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
    const modal = document.getElementById('teamMembersModal');
    const title = document.getElementById('tm_title');
    const content = document.getElementById('tm_content');

    if (!modal || !title || !content) return;

    title.innerText = teamName;
    content.innerHTML = '<div style="text-align:center; padding: 25px; opacity:0.6; color:#bbb;"><i class="bx bx-loader-alt bx-spin" style="font-size: 1.8rem;"></i><br>Loading personnel...</div>';
    modal.style.display = 'flex';

    fetch(`admin_actions.php?action=get_team_members&team_id=${encodeURIComponent(teamId)}`)
    .then(res => res.json())
    .then(data => {
        if (!Array.isArray(data) || data.length === 0) {
            content.innerHTML = '<div style="text-align:center; padding: 30px; font-weight: 700; color: #888;">No responders assigned to this unit yet.</div>';
            return;
        }

        let html = '';
        data.forEach(user => {
            const isOnline = (user.is_online == 1 || user.is_online === '1');
            const statusColor = isOnline ? '#3ada38' : '#888888';
            const statusText = isOnline ? 'Online' : 'Offline';
            const statusBg = isOnline ? 'rgba(58, 218, 56, 0.15)' : 'rgba(136, 136, 136, 0.15)';
            const displayName = user.name || `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.username || 'Responder';

            html += `
            <div style="background: #252628; padding: 14px 16px; border-radius: 14px; margin-bottom: 12px; border: 1px solid rgba(255,255,255,0.08); display: flex; align-items: center; gap: 14px;">
                <div style="background: #1e1e1e; width: 46px; height: 46px; border-radius: 50%; display: flex; align-items: center; justify-content: center; position: relative; flex-shrink: 0;">
                    <i class='bx bxs-user-badge' style="font-size: 1.6rem; color: #1976d2;"></i>
                    <span style="position: absolute; bottom: 0px; right: 0px; width: 12px; height: 12px; background: ${statusColor}; border: 2px solid #252628; border-radius: 50%;"></span>
                </div>
                <div style="flex: 1; min-width: 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px;">
                        <div style="font-weight: 800; color: #fff; font-size: 1.05rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${displayName}</div>
                        <div style="background: ${statusBg}; color: ${statusColor}; padding: 3px 8px; border-radius: 6px; font-size: 0.65rem; font-weight: 900; letter-spacing: 0.5px; flex-shrink: 0;">${statusText}</div>
                    </div>
                    <div style="font-size: 0.8rem; color: #aaa; font-weight: 700; margin-top: 3px;">
                        <i class='bx bx-radio' style="vertical-align: middle;"></i> ${user.radio_callsign || 'Unit Responder'}
                    </div>
                </div>
            </div>`;
        });

        content.innerHTML = html;
    })
    .catch(err => {
        console.error("Failed to fetch unit members:", err);
        content.innerHTML = '<div style="text-align:center; padding: 20px; color: #d32f2f; font-weight: bold;">Failed to load personnel.</div>';
    });
}

// Global click delegation for all unit rows
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', () => {
            const teamId = row.getAttribute('data-team-id');
            const teamName = row.getAttribute('data-team-name');
            if (teamId) {
                viewTeamMembers(teamId, teamName);
            }
        });
    });
});
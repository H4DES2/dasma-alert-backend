const API_PATH = window.location.pathname.includes('/alert/') 
    ? '/alert/admin/admin_actions.php' 
    : 'admin_actions.php';

function closeModal(id) { 
    const el = document.getElementById(id);
    if (el) el.style.display = 'none'; 
}

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
        buttons.innerHTML = `<button onclick="closeModal('universalModal')" class="btn-sm" style="flex: 1; background: ${color}; justify-content: center; height: 46px; border-radius: 10px; color: white; border: none; font-weight: 800; cursor: pointer;">OK</button>`;
    }
    modal.style.display = 'flex';
}

function customConfirm(title, message, iconClass, color, confirmCallback, cancelCallback = null) {
    const icon = document.getElementById('uniModalIcon');
    const titleEl = document.getElementById('uniModalTitle');
    const textEl = document.getElementById('uniModalText');
    const buttons = document.getElementById('uniModalButtons');
    const modal = document.getElementById('universalModal');

    if (!modal) {
        if (confirm(`${title}\n\n${message}`)) confirmCallback();
        else if (cancelCallback) cancelCallback();
        return;
    }

    if (icon) { icon.className = 'bx ' + iconClass; icon.style.color = color; }
    if (titleEl) titleEl.innerText = title;
    if (textEl) textEl.innerText = message;
    if (buttons) {
        buttons.innerHTML = `
            <button id="uniCancelBtn" style="flex: 1; height: 46px; border-radius: 10px; border: 1px solid #ccc; background: transparent; font-weight: 700; cursor: pointer;">Cancel</button>
            <button id="uniConfirmBtn" style="flex: 1; background: ${color}; color: white; border: none; justify-content: center; height: 46px; border-radius: 10px; font-weight: 800; cursor: pointer;">Proceed</button>
        `;
    }
    modal.style.display = 'flex';

    document.getElementById('uniConfirmBtn').onclick = function() {
        closeModal('universalModal');
        confirmCallback();
    };

    document.getElementById('uniCancelBtn').onclick = function() {
        closeModal('universalModal');
        if (cancelCallback) cancelCallback();
    };
}

function handleRoleChange(userId, newRole, username) {
    const roleLabels = {
        'superadmin': 'Super Administrator',
        'admin': 'Official (Admin)',
        'responder': 'Emergency Responder',
        'user': 'App Citizen'
    };

    const targetLabel = roleLabels[newRole] || newRole.toUpperCase();

    customConfirm(
        "Change User Role?",
        `Are you sure you want to change the role of "${username}" to ${targetLabel}?`,
        "bx-user-pin",
        "#8e24aa",
        function() {
            const fd = new FormData();
            fd.append('action', 'update_role');
            fd.append('user_id', userId);
            fd.append('role', newRole);

            fetch(API_PATH, { method: 'POST', body: fd })
                .then(async r => {
                    const text = await r.text();
                    try { return JSON.parse(text); } 
                    catch (e) { throw new Error("Invalid server output: " + text.substring(0, 100)); }
                })
                .then(d => {
                    if (d.success) {
                        location.reload();
                    } else {
                        customAlert("Role Change Failed", d.message || "Could not update user role.", "bx-error", "#d32f2f");
                    }
                })
                .catch(err => {
                    console.error("Role change error:", err);
                    customAlert("Server Error", err.message, "bx-error", "#d32f2f");
                });
        },
        function() {
            location.reload();
        }
    );
}

function toggleUserStatus(userId, currentStatus) {
    const isActivating = (currentStatus !== 'Active');
    const actionLabel = isActivating ? 'Activate' : 'Suspend';
    const actionColor = isActivating ? '#388e3c' : '#f57c00';

    customConfirm(
        `${actionLabel} Account?`,
        `Are you sure you want to ${actionLabel.toLowerCase()} user #${userId}?`,
        isActivating ? "bx-check-circle" : "bx-error-circle",
        actionColor,
        function() {
            const fd = new FormData();
            fd.append('action', 'toggle_user_status');
            fd.append('user_id', userId);
            fd.append('current_status', currentStatus);

            fetch(API_PATH, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.success) location.reload();
                    else customAlert("Status Error", d.message || "Failed to update status.", "bx-error", "#d32f2f");
                })
                .catch(err => customAlert("Server Error", err.message, "bx-error", "#d32f2f"));
        }
    );
}

function deleteUserAccount(userId, username) {
    customConfirm(
        "Delete User Account?",
        `Are you sure you want to permanently delete "${username}"? All associated profiles will be removed.`,
        "bx-trash",
        "#d32f2f",
        function() {
            const fd = new FormData();
            fd.append('action', 'delete_user');
            fd.append('user_id', userId);

            fetch(API_PATH, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.success) location.reload();
                    else customAlert("Delete Failed", d.message || "Could not delete user.", "bx-error", "#d32f2f");
                })
                .catch(err => customAlert("Server Error", err.message, "bx-error", "#d32f2f"));
        }
    );
}

function filterUsers() {
    const input = document.getElementById('userSearchInput');
    const filter = input ? input.value.toLowerCase() : '';
    const rows = document.querySelectorAll('.user-row');

    rows.forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
}

function openMobileModal(row) {
    if (window.innerWidth > 768) return;

    const cells = row.querySelectorAll('td');
    if (cells.length < 9) return;

    const titleEl = document.getElementById('m-user-title');
    const bodyEl = document.getElementById('m-user-body');
    const modal = document.getElementById('mobileUserModal');

    if (titleEl) titleEl.innerText = cells[1].innerText.trim();
    if (bodyEl) {
        bodyEl.innerHTML = `
            <div style="margin-bottom: 12px;"><small style="color: #888; font-weight: 800; font-size: 0.75rem;">FULL NAME</small><div style="font-weight:700; margin-top:2px;">${cells[2].innerHTML}</div></div>
            <div style="margin-bottom: 12px;"><small style="color: #888; font-weight: 800; font-size: 0.75rem;">CONTACT NUMBER</small><div style="font-weight:700; margin-top:2px;">${cells[3].innerHTML}</div></div>
            <div style="margin-bottom: 12px;"><small style="color: #888; font-weight: 800; font-size: 0.75rem;">EMAIL</small><div style="margin-top:2px;">${cells[4].innerHTML}</div></div>
            <div style="margin-bottom: 12px;"><small style="color: #888; font-weight: 800; font-size: 0.75rem;">ROLE</small><div style="margin-top:2px;">${cells[5].innerHTML}</div></div>
            <div style="margin-bottom: 12px;"><small style="color: #888; font-weight: 800; font-size: 0.75rem;">DATE REGISTERED</small><div style="margin-top:2px;">${cells[6].innerHTML}</div></div>
            <div style="margin-bottom: 15px;"><small style="color: #888; font-weight: 800; font-size: 0.75rem;">STATUS</small><div style="margin-top:2px;">${cells[7].innerHTML}</div></div>
            <div><small style="color: #888; font-weight: 800; font-size: 0.75rem;">ACTIONS</small><div style="margin-top: 6px;">${cells[8].innerHTML}</div></div>
        `;
    }
    if (modal) modal.style.display = 'flex';
}

window.handleRoleChange = handleRoleChange;
window.toggleUserStatus = toggleUserStatus;
window.deleteUserAccount = deleteUserAccount;
window.filterUsers = filterUsers;
window.openMobileModal = openMobileModal;
window.closeModal = closeModal;
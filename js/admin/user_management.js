
    function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal()" class="btn-status" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">OK</button>`;
        document.getElementById('universalModal').style.display = 'flex';
    }

    function customConfirm(title, message, iconClass, color, confirmCallback, cancelCallback = null) {
        document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
        document.getElementById('uniModalIcon').style.color = color;
        document.getElementById('uniModalTitle').innerText = title;
        document.getElementById('uniModalText').innerText = message;
        
        let cancelBtn = `<button id="uniCancelBtn" class="modal-cancel-btn" style="height: 50px;">Cancel</button>`;
        let confirmBtn = `<button id="uniConfirmBtn" class="btn-status" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">Proceed</button>`;
        
        document.getElementById('uniModalButtons').innerHTML = cancelBtn + confirmBtn;
        document.getElementById('universalModal').style.display = 'flex';
        
        document.getElementById('uniCancelBtn').onclick = function() { 
            closeModal(); 
            if (cancelCallback) cancelCallback(); 
        };
        
        document.getElementById('uniConfirmBtn').onclick = function() { 
            closeModal(); 
            confirmCallback(); 
        };
    }

    function closeModal() { 
        document.getElementById('universalModal').style.display = 'none'; 
    }

    function toggleUserStatus(userId, currentStatus) {
        const actionText = (currentStatus === 'Active') ? 'suspend' : 'activate/approve';
        const color = (currentStatus === 'Active') ? '#d32f2f' : '#228b22';
        const icon = (currentStatus === 'Active') ? 'bx-user-x' : 'bx-user-check';

        customConfirm("Confirm Action", `Are you sure you want to ${actionText} this user?`, icon, color, function() {
            let fd = new FormData();
            fd.append('action', 'toggle_user_status');
            fd.append('user_id', userId);
            fd.append('current_status', currentStatus);

            fetch('admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if(data.success) location.reload();
                else customAlert("Error", data.message, "bx-x-circle", "#d32f2f");
            });
        });
    }

    function handleRoleChange(userId, newRole, username) {
        let roleDisplay = newRole === 'user' ? 'CITIZEN' : newRole.toUpperCase();
        
        customConfirm(
            "Change User Role", 
            `Change ${username}'s access level to ${roleDisplay}?`, 
            "bx-shield-quarter", 
            "#1976d2", 
            function() {
                let fd = new FormData();
                fd.append('action', 'update_role');
                fd.append('user_id', userId);
                fd.append('role', newRole);

                fetch('admin_actions.php', { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if(data.success) location.reload();
                    else {
                        customAlert("Error", data.message, "bx-x-circle", "#d32f2f");
                        setTimeout(() => location.reload(), 1500);
                    }
                });
            }, 
            function() { location.reload(); }
        );
    }

    function rejectUser(userId) {
        customConfirm("Reject Admin", "Are you sure you want to reject and delete this registration?", "bx-trash", "#d32f2f", function() {
            let fd = new FormData();
            fd.append('action', 'delete_user');
            fd.append('user_id', userId);

            fetch('admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if(data.success) location.reload();
                else customAlert("Error", data.message, "bx-x-circle", "#d32f2f");
            });
        });
    }

    function filterUsers() {
        let input = document.getElementById('userSearchInput');
        let filter = input.value.toLowerCase();
        let boxes = document.querySelectorAll('.role-box');

        boxes.forEach(box => {
            let rows = box.querySelectorAll('tbody tr');
            let visibleCount = 0;

            rows.forEach(row => {
                let text = row.innerText.toLowerCase();
                let matches = text.includes(filter);
                row.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });

            box.style.display = (filter === '' || visibleCount > 0) ? '' : 'none';
        });
    }

    // -----------------------------------------------------
    // MOBILE MODAL LOGIC
    // -----------------------------------------------------
    function openMobileModal(row) {
        if (window.innerWidth > 768) return; 

        const cells = row.querySelectorAll('td');
        if (cells.length < 7) return;

        const titleEl = document.getElementById('m-user-title');
        const bodyEl = document.getElementById('m-user-body');

        // Extract Title from Username column (hide chevron)
        titleEl.innerHTML = cells[1].innerHTML; 
        
        let html = '';
        html += `<div class="mobile-detail-box"><small class="mobile-label">User ID</small>${cells[0].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Current Role</small>${cells[2].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Email Contact</small>${cells[3].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Joined Date</small>${cells[4].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Status</small>${cells[5].innerHTML}</div>`;
        html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; flex-direction:column; gap:10px; width:100%;">${cells[6].innerHTML}</div></div>`;

        bodyEl.innerHTML = html;

        // Force buttons and dropdowns inside modal to stretch
        let actionContainer = bodyEl.querySelector('.m-actions-container');
        if (actionContainer) {
            let buttons = actionContainer.querySelectorAll('button, select, span');
            buttons.forEach(el => {
                if(el.tagName === 'BUTTON' || el.tagName === 'SELECT') {
                    el.style.width = '100%';
                    if (el.tagName === 'BUTTON') el.style.justifyContent = 'center';
                }
            });
        }

        // Hide expanding arrows inside the modal copy
        titleEl.querySelectorAll('.mobile-expand-icon').forEach(icon => icon.style.display = 'none');
        bodyEl.querySelectorAll('.mobile-expand-icon').forEach(icon => icon.style.display = 'none');

        document.getElementById('mobileUserModal').style.display = 'flex';
    }
    function deleteUserAccount(userId, username) {
    customConfirm(
        "Delete User Account",
        `Are you sure you want to permanently delete user "${username}"? All associated profile data will be permanently removed from the database. This action cannot be undone.`,
        "bx-trash",
        "#d32f2f",
        function() {
            let fd = new FormData();
            fd.append('action', 'delete_user');
            fd.append('user_id', userId);

            fetch('admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    customAlert("Delete Failed", data.message, "bx-x-circle", "#d32f2f");
                }
            })
            .catch(err => {
                customAlert("Network Error", "Could not complete deletion.", "bx-x-circle", "#d32f2f");
            });
        }
    );
}

        const role = "<?php echo addslashes($role); ?>";
        const assignedBrgy = "<?php echo addslashes($assigned_brgy); ?>";
        let evacMap, evacMarker;

        function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
            document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
            document.getElementById('uniModalIcon').style.color = color;
            document.getElementById('uniModalTitle').innerText = title;
            document.getElementById('uniModalText').innerText = message;
            document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" class="btn-action btn-manage" style="flex: 1; justify-content: center; box-shadow: none; background:${color};">OK</button>`;
            document.getElementById('universalModal').style.display = 'flex';
        }

        function closeModal(id) { document.getElementById(id).style.display = 'none'; }

        // Mobile Row Click Logic
        function handleRowClick(row, id, name, occupants, status) {
            if (window.innerWidth <= 768) {
                const cells = row.querySelectorAll('td');
                if (cells.length < 4) return;

                document.getElementById('m-evac-title').innerHTML = cells[0].innerHTML.replace(/<i.*<\/i>/, ''); 
                
                let bodyEl = document.getElementById('m-evac-body');
                let html = '';
                html += `<div class="mobile-detail-box"><small class="mobile-label">Occupancy Tracker</small>${cells[1].innerHTML}</div>`;
                html += `<div class="mobile-detail-box"><small class="mobile-label">Status</small><div style="margin-top:5px;">${cells[2].innerHTML}</div></div>`;
                html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; flex-direction:column; gap:10px; width:100%;">
                            <button class="btn-action btn-manage" style="width: 100%; justify-content: center; padding: 12px; margin: 0;" data-id="${id}" data-name="${name}" data-occupants="${occupants}" data-status="${status}" onclick="openManageModal(this); closeModal('mobileEvacModal');">
                                <i class='bx bx-edit-alt'></i> Manage Data
                            </button>
                        </div></div>`;

                bodyEl.innerHTML = html;
                document.getElementById('mobileEvacModal').style.display = 'flex';
            }
        }

        function openAddModal() {
            if(role !== 'superadmin' && !assignedBrgy) {
                return customAlert("No Barangay", "Set your jurisdiction in your profile first.", "bx-error-circle", "#d32f2f");
            }

            document.getElementById('addName').value = '';
            document.getElementById('addCapacity').value = '';
            document.getElementById('addLat').value = '';
            document.getElementById('addLng').value = '';
            
            // 🚀 POPULATE AND LOCK BARANGAY FIELD FOR ADMINS
            document.getElementById('addBarangay').value = role === 'superadmin' ? '' : assignedBrgy;
            if(role !== 'superadmin') {
                document.getElementById('addBarangay').readOnly = true;
                document.getElementById('addBarangay').classList.add('readonly-input');
            }

            document.getElementById('addModal').style.display = 'flex';

            setTimeout(() => {
                if(!evacMap) {
                    const dasmaBounds = [ [14.2600, 120.9000], [14.3800, 120.9800] ];
                    evacMap = L.map('add-evac-map', { 
                        maxBounds: dasmaBounds, 
                        maxBoundsViscosity: 1.0, 
                        minZoom: 13 
                    }).setView([14.3294, 120.9368], 13);
                    
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(evacMap);
                    
                    evacMap.on('click', function(e) {
                        let lat = e.latlng.lat;
                        let lng = e.latlng.lng;
                        
                        if(evacMarker) { 
                            evacMarker.setLatLng(e.latlng); 
                        } else { 
                            let eIcon = L.divIcon({ html: `<i class='bx bxs-home-heart' style='color: #388e3c; font-size: 32px;'></i>`, className: 'custom-leaflet-icon', iconSize: [32, 32], iconAnchor: [16, 32] });
                            evacMarker = L.marker(e.latlng, {icon: eIcon}).addTo(evacMap); 
                        }
                        document.getElementById('addLat').value = lat;
                        document.getElementById('addLng').value = lng;
                    });
                } else {
                    evacMap.invalidateSize();
                    if(evacMarker) { evacMap.removeLayer(evacMarker); evacMarker = null; }
                }
            }, 300);
        }

        function submitAdd() {
            let name = document.getElementById('addName').value.trim();
            let brgy = document.getElementById('addBarangay').value.trim();
            let cap = document.getElementById('addCapacity').value;
            let lat = document.getElementById('addLat').value.trim();
            let lng = document.getElementById('addLng').value.trim();
            
            if(!name || !brgy || !cap || !lat || !lng) return customAlert("Fields Required", "Please fill out all fields and tap the map to pin the location.", "bx-error-circle", "#d32f2f");
            
            let fd = new FormData();
            fd.append('action', 'add_center');
            fd.append('name', name);
            fd.append('barangay', brgy);
            fd.append('capacity', cap);
            fd.append('latitude', lat);
            fd.append('longitude', lng);

            fetch('../admin/admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(text => {
                try {
                    let data = JSON.parse(text);
                    if(data.success) location.reload(); 
                    else customAlert("Error", data.message || "Could not add facility.", "bx-x-circle", "#d32f2f");
                } catch(e) {
                    if (text.trim() === 'success') {
                        location.reload();
                    } else {
                        customAlert("Server Error", text, "bx-error", "#d32f2f");
                    }
                }
            }).catch(e => {
                customAlert("Network Error", "Could not process request.", "bx-x-circle", "#d32f2f");
            });
        }
                function openManageModal(btn) {
            document.getElementById('manageTitle').innerHTML = `<i class='bx bx-edit-alt'></i> ${btn.getAttribute('data-name')}`;
            document.getElementById('manageId').value = btn.getAttribute('data-id');
            document.getElementById('manageOccupants').value = btn.getAttribute('data-occupants');
            document.getElementById('manageStatus').value = btn.getAttribute('data-status').toLowerCase();
            document.getElementById('manageModal').style.display = 'flex';
        }                    
        function submitManage() {
            let fd = new FormData();
            fd.append('action', 'update_evac_center'); 
            fd.append('id', document.getElementById('manageId').value);
            fd.append('occupants', document.getElementById('manageOccupants').value);
            fd.append('status', document.getElementById('manageStatus').value);
            
            fetch('../admin/admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(text => {
                try {
                    let data = JSON.parse(text);
                    if(data.success) location.reload(); 
                    else customAlert("Error", data.message || "Could not update facility.", "bx-x-circle", "#d32f2f");
                } catch(e) {
                    if (text.trim() === 'success') {
                        location.reload();
                    } else {
                        customAlert("Server Error", text, "bx-error", "#d32f2f");
                    }
                }
            }).catch(e => {
                customAlert("Network Error", "Could not process request.", "bx-x-circle", "#d32f2f");
            });
        }

        function handleDelete(id) {
            customConfirm("Delete Facility?", "This action is permanent and cannot be undone.", "bxs-trash", "#d32f2f", function() {
                let fd = new FormData();
                fd.append('action', 'delete_center');
                fd.append('id', id);
                
                fetch('../admin/admin_actions.php', { method: 'POST', body: fd })
                    .then(res => res.text())
                    .then(text => {
                        try {
                            let data = JSON.parse(text);
                            if(data.success) location.reload(); 
                            else customAlert("Error", data.message || "Failed to delete.", "bx-x-circle", "#d32f2f");
                        } catch(e) {
                            if (text.trim() === 'success') {
                                location.reload();
                            } else {
                                customAlert("Server Error", text, "bx-error", "#d32f2f");
                            }
                        }
                    })
                    .catch(e => {
                        customAlert("Network Error", "Could not process request.", "bx-error", "#d32f2f");
                    });
            });
        }
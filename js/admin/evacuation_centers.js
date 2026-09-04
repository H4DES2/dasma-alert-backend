
        const role = window.APP_ROLE || "superadmin";
        const assignedBrgy = window.APP_ASSIGNED_BRGY || "";
        let modalMap = null;
        let modalMarker = null;

        function closeModal(id) { 
            document.getElementById(id).style.display = 'none'; 
        }

        function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
            document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
            document.getElementById('uniModalIcon').style.color = color;
            document.getElementById('uniModalTitle').innerText = title;
            document.getElementById('uniModalText').innerText = message;
            document.getElementById('uniModalButtons').innerHTML = `
                <button onclick="closeModal('universalModal')" class="btn-action" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">OK</button>
            `;
            document.getElementById('universalModal').style.display = 'flex';
        }

        function customConfirm(title, message, iconClass, color, confirmCallback) {
            document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
            document.getElementById('uniModalIcon').style.color = color;
            document.getElementById('uniModalTitle').innerText = title;
            document.getElementById('uniModalText').innerText = message;
            
            let cancelBtn = `<button onclick="closeModal('universalModal')" class="modal-cancel-btn" style="height: 50px;">Cancel</button>`;
            let confirmBtn = `<button id="uniConfirmBtn" class="btn-action" style="flex: 1; background: ${color}; justify-content: center; height: 50px;">Proceed</button>`;
            
            document.getElementById('uniModalButtons').innerHTML = cancelBtn + confirmBtn;
            document.getElementById('universalModal').style.display = 'flex';
            
            document.getElementById('uniConfirmBtn').onclick = function() { 
                closeModal('universalModal'); 
                confirmCallback(); 
            };
        }

       function openAddModal() {
            document.getElementById('addName').value = '';
            
            const brgySelect = document.getElementById('addBarangay');
            if (role === 'superadmin' || !assignedBrgy) {
                brgySelect.disabled = false;
                brgySelect.classList.remove('readonly-input');
                brgySelect.selectedIndex = 0;
            } else {
                brgySelect.value = assignedBrgy;
                brgySelect.disabled = true;
                brgySelect.classList.add('readonly-input');
            }

            document.getElementById('addCapacity').value = '';
            document.getElementById('addLat').value = '';
            document.getElementById('addLng').value = '';
            
            document.getElementById('addModal').style.display = 'flex';

            setTimeout(() => {
                if (!modalMap) {
                    const dasmaBounds = [ [14.2600, 120.9000], [14.3800, 120.9800] ];
                    modalMap = L.map('modalMap', { 
                        maxBounds: dasmaBounds, 
                        maxBoundsViscosity: 1.0, 
                        minZoom: 13 
                    }).setView([14.3294, 120.9368], 13);
                    
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(modalMap);

                    modalMap.on('click', function(e) {
                        let lat = e.latlng.lat.toFixed(6);
                        let lng = e.latlng.lng.toFixed(6);

                        document.getElementById('addLat').value = lat;
                        document.getElementById('addLng').value = lng;

                        if (modalMarker) {
                            modalMarker.setLatLng(e.latlng);
                        } else {
                            let evacIcon = L.divIcon({ 
                                html: `<i class='bx bxs-home-heart' style='color: #d32f2f; font-size: 36px; text-shadow: 0 4px 8px rgba(0,0,0,0.4); margin-top: -30px; margin-left: -18px;'></i>`, 
                                className: 'custom-leaflet-icon'
                            });
                            modalMarker = L.marker(e.latlng, { icon: evacIcon }).addTo(modalMap);
                        }
                    });
                } else {
                    modalMap.invalidateSize();
                    if (modalMarker) {
                        modalMap.removeLayer(modalMarker);
                        modalMarker = null;
                    }
                    modalMap.setView([14.3294, 120.9368], 13);
                }
            }, 150);
        }

        function addCenter() {
            let name = document.getElementById('addName').value.trim();
            let brgy = document.getElementById('addBarangay').value.trim();
            let cap = document.getElementById('addCapacity').value;
            let lat = document.getElementById('addLat').value.trim();
            let lng = document.getElementById('addLng').value.trim();
            
            if(!name || !brgy || !cap || !lat || !lng) {
                return customAlert("Location Missing", "Please fill out all details and click on the map to pin the exact location.", "bx-map-pin", "#d32f2f");
            }
            
            customConfirm("Register Facility?", `Are you sure you want to register ${name} as an evacuation center?`, "bx-building-house", "#228b22", function() {
                let fd = new FormData();
                fd.append('action', 'add_center'); 
                fd.append('name', name); 
                fd.append('barangay', brgy); 
                fd.append('capacity', cap);
                fd.append('latitude', lat);
                fd.append('longitude', lng);
                
                fetch('admin_actions.php', { method: 'POST', body: fd })
                    .then(res => res.text())
                    .then(data => {
                        if(data.trim() === 'success') {
                            location.reload(); 
                        } else { 
                            customAlert("Error", data || "Failed to add facility.", "bx-x-circle", "#d32f2f");
                        }
                    })
                    .catch(err => {
                        customAlert("Network Error", err.message, "bx-x-circle", "#d32f2f");
                    });
            });
        }

        // 🚀 THE NEW AUTO-CAPACITY CHECKER FUNCTION
        function checkCapacityStatus() {
            let occupants = parseInt(document.getElementById('manageOccupants').value) || 0;
            let capacity = parseInt(document.getElementById('manageCapacity').value) || 1;
            let statusDropdown = document.getElementById('manageStatus');

            if (occupants >= capacity) {
                // Automatically set to 'full' when capacity is reached
                statusDropdown.value = 'full';
            } else if (occupants < capacity && statusDropdown.value === 'full') {
                // Automatically set back to 'open' if it drops below capacity and was previously marked full
                statusDropdown.value = 'open';
            }
        }

        function openManageModal(btn) {
            document.getElementById('manageTitle').innerHTML = `<i class='bx bx-edit-alt'></i> ${btn.getAttribute('data-name')}`;
            document.getElementById('manageId').value = btn.getAttribute('data-id');
            document.getElementById('manageCapacity').value = btn.getAttribute('data-capacity'); // Pass capacity
            document.getElementById('manageOccupants').value = btn.getAttribute('data-occupants');
            document.getElementById('manageStatus').value = btn.getAttribute('data-status').toLowerCase();
            
            checkCapacityStatus(); // Run check immediately upon opening just in case

            document.getElementById('manageModal').style.display = 'flex';
        }

        function saveManage() {
            let fd = new FormData();
            fd.append('action', 'update_evac_center'); 
            fd.append('id', document.getElementById('manageId').value);
            fd.append('occupants', document.getElementById('manageOccupants').value);
            fd.append('status', document.getElementById('manageStatus').value);
            
            fetch('admin_actions.php', { method: 'POST', body: fd })
                .then(res => res.text())
                .then(data => {
                    if (data.trim() === 'success') { 
                        location.reload(); 
                    } else { 
                        customAlert("Error", data || "Failed to update.", "bx-x-circle", "#d32f2f"); 
                    }
                })
                .catch(e => {
                    customAlert("Network Error", "Could not process request.", "bx-x-circle", "#d32f2f");
                });
        }

        function handleDelete(id) {
            customConfirm("Delete Facility?", "This action is permanent and cannot be undone.", "bxs-trash", "#d32f2f", function() {
                let fd = new FormData();
                fd.append('action', 'delete_center');
                fd.append('id', id);
                
                fetch('admin_actions.php', { method: 'POST', body: fd })
                    .then(res => res.text())
                    .then(data => {
                        if(data.trim() === 'success') {
                            location.reload(); 
                        } else { 
                            customAlert("Error", data || "Failed to delete.", "bx-x-circle", "#d32f2f");
                        }
                    })
                    .catch(e => {
                        customAlert("Network Error", "Could not process request.", "bx-x-circle", "#d32f2f");
                    });
            });
        }
        function openMobileModal(row) {
            // Only trigger on mobile screens
            if (window.innerWidth > 768) return; 

            const cells = row.querySelectorAll('td');
            if (cells.length < 5) return;

            // Extract data from the row
            document.getElementById('m-evac-title').innerHTML = cells[0].innerHTML;
            document.getElementById('m-evac-brgy').innerHTML = cells[1].innerHTML;
            document.getElementById('m-evac-occ').innerHTML = cells[2].innerHTML;
            document.getElementById('m-evac-status').innerHTML = cells[3].innerHTML;
            
            // Extract actions and force the flex container to stack vertically
            let actionsHtml = cells[4].innerHTML;
            let actionsContainer = document.getElementById('m-evac-actions');
            actionsContainer.innerHTML = actionsHtml;
            
            // Format action buttons for mobile modal specifically
            let divWrapper = actionsContainer.querySelector('div');
            if (divWrapper) {
                divWrapper.style.flexDirection = 'column';
                let btns = divWrapper.querySelectorAll('button');
                btns.forEach(b => {
                    b.style.width = '100%';
                    b.style.justifyContent = 'center';
                });
            }

            document.getElementById('mobileEvacModal').style.display = 'flex';
        }
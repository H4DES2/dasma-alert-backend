const role = window.APP_ROLE || "superadmin";
const assignedBrgy = window.APP_ASSIGNED_BRGY || "";
let modalMap = null;
let modalMarker = null;

function closeModal(id) { 
    const el = document.getElementById(id);
    if (el) el.style.display = 'none'; 
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
    if (role !== 'superadmin') {
        return customAlert("Access Denied", "Only CDRRMO Superadmins are authorized to register new evacuation centers.", "bx-shield-x", "#d32f2f");
    }

    const addModal = document.getElementById('addModal');
    if (!addModal) return;

    document.getElementById('addName').value = '';
    document.getElementById('addBarangay').selectedIndex = 0;
    document.getElementById('addCapacity').value = '';
    document.getElementById('addLat').value = '';
    document.getElementById('addLng').value = '';
    document.getElementById('addFacilityType').value = 'temporary';
    
    addModal.style.display = 'flex';

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
    if (role !== 'superadmin') {
        return customAlert("Access Denied", "Only CDRRMO Superadmins can register facilities.", "bx-shield-x", "#d32f2f");
    }

    let name = document.getElementById('addName').value.trim();
    let brgy = document.getElementById('addBarangay').value.trim();
    let cap = document.getElementById('addCapacity').value;
    let lat = document.getElementById('addLat').value.trim();
    let lng = document.getElementById('addLng').value.trim();
    let facilityType = document.getElementById('addFacilityType')?.value || 'temporary';
    
    if (!name || !brgy || !cap || !lat || !lng) {
        return customAlert("Location Missing", "Please fill out all details and click on the map to pin the exact location.", "bx-map-pin", "#d32f2f");
    }
    
    customConfirm("Register Facility?", `Are you sure you want to register ${name} as a ${facilityType.toUpperCase()} evacuation center?`, "bx-building-house", "#228b22", function() {
        let fd = new FormData();
        fd.append('action', 'add_center'); 
        fd.append('name', name); 
        fd.append('barangay', brgy); 
        fd.append('capacity', cap);
        fd.append('latitude', lat);
        fd.append('longitude', lng);
        fd.append('facility_type', facilityType);
        
        fetch('admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(data => {
                if (data.trim() === 'success') {
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

function checkCapacityStatus() {
    let occupants = parseInt(document.getElementById('manageOccupants').value, 10) || 0;
    let capacity = parseInt(document.getElementById('manageCapacity').value, 10) || 1;
    let statusDropdown = document.getElementById('manageStatus');

    if (occupants >= capacity) {
        statusDropdown.value = 'full';
    } else if (occupants < capacity && statusDropdown.value === 'full') {
        statusDropdown.value = 'open';
    }
}

function openManageModal(btn) {
    document.getElementById('manageTitle').innerHTML = `<i class='bx bx-edit-alt'></i> ${btn.getAttribute('data-name')}`;
    document.getElementById('manageId').value = btn.getAttribute('data-id');
    document.getElementById('manageCapacity').value = btn.getAttribute('data-capacity');
    document.getElementById('manageOccupants').value = btn.getAttribute('data-occupants');
    document.getElementById('manageStatus').value = btn.getAttribute('data-status').toLowerCase();
    
    const type = btn.getAttribute('data-facility-type') || 'temporary';
    const typeSelect = document.getElementById('manageFacilityType');
    if (typeSelect) {
        typeSelect.value = type.toLowerCase();
    }
    
    checkCapacityStatus();

    document.getElementById('manageModal').style.display = 'flex';
}

function saveManage() {
    let fd = new FormData();
    fd.append('action', 'update_evac_center'); 
    fd.append('id', document.getElementById('manageId').value);
    fd.append('occupants', document.getElementById('manageOccupants').value);
    fd.append('status', document.getElementById('manageStatus').value);
    fd.append('facility_type', document.getElementById('manageFacilityType')?.value || 'temporary');
    
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
    if (role !== 'superadmin') {
        return customAlert("Access Denied", "Only Superadmins can delete evacuation centers.", "bx-shield-x", "#d32f2f");
    }

    customConfirm("Delete Facility?", "This action is permanent and cannot be undone.", "bxs-trash", "#d32f2f", function() {
        let fd = new FormData();
        fd.append('action', 'delete_center');
        fd.append('id', id);
        
        fetch('admin_actions.php', { method: 'POST', body: fd })
            .then(res => res.text())
            .then(data => {
                if (data.trim() === 'success') {
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
    if (window.innerWidth > 768) return; 

    const cells = row.querySelectorAll('td');
    if (cells.length < 5) return;

    document.getElementById('m-evac-title').innerHTML = cells[0].innerHTML;
    document.getElementById('m-evac-brgy').innerHTML = cells[1].innerHTML;
    document.getElementById('m-evac-occ').innerHTML = cells[2].innerHTML;
    document.getElementById('m-evac-status').innerHTML = cells[3].innerHTML;
    
    let actionsHtml = cells[4].innerHTML;
    let actionsContainer = document.getElementById('m-evac-actions');
    actionsContainer.innerHTML = actionsHtml;
    
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

window.closeModal = closeModal;
window.openAddModal = openAddModal;
window.addCenter = addCenter;
window.checkCapacityStatus = checkCapacityStatus;
window.openManageModal = openManageModal;
window.saveManage = saveManage;
window.handleDelete = handleDelete;
window.openMobileModal = openMobileModal;
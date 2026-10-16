const role = window.APP_ROLE || 'admin';
const assignedBrgy = window.ASSIGNED_BRGY || '';

let facilityMap = null;
let facilityMarker = null;

function customAlert(title, message, iconClass = 'bx-info-circle', color = '#1976d2') {
    document.getElementById('uniModalIcon').className = 'bx ' + iconClass;
    document.getElementById('uniModalIcon').style.color = color;
    document.getElementById('uniModalTitle').innerText = title;
    document.getElementById('uniModalText').innerText = message;
    document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" class="btn-action btn-manage" style="flex: 1; justify-content: center; box-shadow: none; background:${color};">OK</button>`;
    document.getElementById('universalModal').style.display = 'flex';
}

function closeModal(id) { 
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

// -------------------------------------------------------------
// CREATE TEMPORARY FACILITY MODAL & MAP INITIALIZATION
// -------------------------------------------------------------
function openCreateFacilityModal() {
    const modal = document.getElementById('createFacilityModal');
    if (!modal) return;
    
    modal.style.display = 'flex';

    // Default center anchor: Dasmariñas City / Burol coordinates (approx 14.3343, 120.9508)
    const defaultLat = 14.3343;
    const defaultLng = 120.9508;

    setTimeout(() => {
        if (!facilityMap) {
            facilityMap = L.map('facilityMap').setView([defaultLat, defaultLng], 14);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; OpenStreetMap'
            }).addTo(facilityMap);

            facilityMarker = L.marker([defaultLat, defaultLng], { draggable: true }).addTo(facilityMap);

            // Set initial coords
            document.getElementById('facilityLat').value = defaultLat.toFixed(8);
            document.getElementById('facilityLng').value = defaultLng.toFixed(8);

            // On marker drag
            facilityMarker.on('dragend', function (e) {
                const pos = e.target.getLatLng();
                document.getElementById('facilityLat').value = pos.lat.toFixed(8);
                document.getElementById('facilityLng').value = pos.lng.toFixed(8);
            });

            // On map click
            facilityMap.on('click', function (e) {
                facilityMarker.setLatLng(e.latlng);
                document.getElementById('facilityLat').value = e.latlng.lat.toFixed(8);
                document.getElementById('facilityLng').value = e.latlng.lng.toFixed(8);
            });
        } else {
            facilityMap.invalidateSize();
        }
    }, 200);
}

function submitCreateFacility() {
    const name = document.getElementById('facilityName').value.trim();
    const capacity = document.getElementById('facilityCapacity').value.trim();
    const lat = document.getElementById('facilityLat').value.trim();
    const lng = document.getElementById('facilityLng').value.trim();
    const brgy = document.getElementById('facilityBarangay').value.trim();

    if (!name || !capacity || !lat || !lng) {
        customAlert("Missing Information", "Please enter facility name, capacity, and click on the map to pin its coordinates.", "bx-error", "#d32f2f");
        return;
    }

    const btn = document.getElementById('btnSaveFacility');
    btn.disabled = true;
    btn.innerHTML = "<i class='bx bx-loader-alt bx-spin'></i> Saving...";

    const fd = new FormData();
    fd.append('action', 'brgy_add_temporary_center');
    fd.append('name', name);
    fd.append('capacity', capacity);
    fd.append('latitude', lat);
    fd.append('longitude', lng);
    fd.append('barangay', brgy);

    fetch('../admin/admin_actions.php', {
        method: 'POST',
        body: fd
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = "Save Facility";

        if (data.success) {
            closeModal('createFacilityModal');
            location.reload();
        } else {
            customAlert("Error", data.message || "Failed to create facility.", "bx-x-circle", "#d32f2f");
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = "Save Facility";
        customAlert("Network Error", "Could not connect to the server: " + err.message, "bx-error", "#d32f2f");
    });
}

// -------------------------------------------------------------
// MOBILE ROW CLICK LOGIC
// -------------------------------------------------------------
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
            if (data.success) location.reload();
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
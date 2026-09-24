const API_PATH = 'admin_actions.php';

function closeModal(id) { document.getElementById(id).style.display = 'none'; }

function submitGlobalBroadcast() {
    let fd = new FormData();
    fd.append('action', 'send_broadcast');
    fd.append('title', document.getElementById('globalBroadcastTitle').value);
    fd.append('message', document.getElementById('globalBroadcastMessage').value);
    fd.append('severity', document.getElementById('globalBroadcastSeverity').value);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

// Announcements
function openAnnouncementModal(id='', title='', msg='') {
    document.getElementById('ann_id').value = id;
    document.getElementById('ann_title').value = title;
    document.getElementById('ann_message').value = msg.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
    document.getElementById('announcementModal').style.display = 'flex';
}
function saveAnnouncement() {
    let fd = new FormData();
    fd.append('action', 'save_announcement');
    fd.append('id', document.getElementById('ann_id').value);
    fd.append('title', document.getElementById('ann_title').value);
    fd.append('message', document.getElementById('ann_message').value);
    if(document.getElementById('ann_image').files[0]) fd.append('image', document.getElementById('ann_image').files[0]);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}
function deleteAnnouncement(id) {
    if(!confirm('Delete this announcement?')) return;
    let fd = new FormData(); fd.append('action', 'delete_announcement'); fd.append('id', id);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

// Guidelines
function openGuidelineModal(id='', title='', content='') {
    document.getElementById('guide_id').value = id;
    document.getElementById('guide_title').value = title;
    document.getElementById('guide_content').value = content.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
    document.getElementById('guidelineModal').style.display = 'flex';
}
function saveGuideline() {
    let fd = new FormData();
    fd.append('action', 'save_guideline');
    fd.append('id', document.getElementById('guide_id').value);
    fd.append('title', document.getElementById('guide_title').value);
    fd.append('content', document.getElementById('guide_content').value);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}
function deleteGuideline(id) {
    if(!confirm('Delete this guideline?')) return;
    let fd = new FormData(); fd.append('action', 'delete_guideline'); fd.append('id', id);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

// Emergency Types (Parent)
function openTypeModal(id='', name='', icon='bx-error', incidents='') {
    document.getElementById('type_id').value = id;
    document.getElementById('type_name').value = name;
    
    let iconSelect = document.getElementById('type_icon');
    let exists = Array.from(iconSelect.options).some(opt => opt.value === icon);
    if (exists) {
        iconSelect.value = icon;
    } else {
        iconSelect.value = 'bx-error'; // Fallback
    }

    document.getElementById('type_hidden_incidents').value = incidents;
    document.getElementById('typeModal').style.display = 'flex';
}

function saveType() {
    let fd = new FormData();
    fd.append('action', 'save_emergency_type');
    fd.append('id', document.getElementById('type_id').value);
    fd.append('name', document.getElementById('type_name').value);
    fd.append('icon', document.getElementById('type_icon').value);
    
    // Preserve existing incidents when only updating name/icon
    fd.append('incidents', document.getElementById('type_hidden_incidents').value);
    
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

function deleteType(id) {
    if(!confirm('Delete this emergency type?')) return;
    let fd = new FormData(); fd.append('action', 'delete_emergency_type'); fd.append('id', id);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

// Specific Hazards / Sub-Incidents
function openIncidentModal() {
    document.getElementById('inc_parent_id').value = '';
    document.getElementById('inc_list_values').value = '';
    document.getElementById('incidentModal').style.display = 'flex';
}

function openIncidentModalFor(id) {
    openIncidentModal();
    document.getElementById('inc_parent_id').value = id;
    loadIncidentsForParent(id);
}

function loadIncidentsForParent(val) {
    if(!val) {
        document.getElementById('inc_list_values').value = '';
        return;
    }
    let sel = document.getElementById('inc_parent_id');
    let opt = sel.options[sel.selectedIndex];
    document.getElementById('inc_list_values').value = opt.getAttribute('data-incidents') || '';
}

function saveIncidents() {
    let sel = document.getElementById('inc_parent_id');
    if(!sel.value) {
        alert('Please select an Emergency Type first.');
        return;
    }
    
    let opt = sel.options[sel.selectedIndex];
    let fd = new FormData();
    fd.append('action', 'save_emergency_type');
    fd.append('id', sel.value);
    
    // Preserve parent name/icon when updating incidents
    fd.append('name', opt.getAttribute('data-name'));
    fd.append('icon', opt.getAttribute('data-icon'));
    fd.append('incidents', document.getElementById('inc_list_values').value);
    
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}
// Preview Type Details Modal
function showTypeDetails(id, name, icon, incidents) {
    document.getElementById('viewTypeName').innerText = name;
    document.getElementById('viewTypeIcon').className = 'bx ' + (icon || 'bx-error');

    const container = document.getElementById('viewTypeIncidentsList');
    container.innerHTML = '';

    const items = (incidents || '')
        .split(',')
        .map(s => s.trim())
        .filter(s => s.length > 0);

    if (items.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 24px; color: #94a3b8; font-weight: 600; background: #f8fafc; border-radius: 12px; border: 1px dashed #cbd5e1;">
                No specific hazards configured yet.
            </div>`;
    } else {
        items.forEach(hazard => {
            const row = document.createElement('div');
            row.style.cssText = 'display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #f8fafc; border: 1px solid #edf2f7; border-radius: 10px; font-weight: 600; font-size: 0.9rem; color: #334155;';
            row.innerHTML = `<i class='bx bx-chevron-right' style='color: #f59e0b; font-size: 1.2rem;'></i> <span>${hazard}</span>`;
            container.appendChild(row);
        });
    }

    document.getElementById('viewTypeEditHazardsBtn').onclick = function() {
        closeModal('viewTypeDetailsModal');
        openIncidentModalFor(id);
    };

    document.getElementById('viewTypeDetailsModal').style.display = 'flex';
}
// Preview Guideline Modal
function showGuidelineDetails(id, title, content) {
    document.getElementById('viewGuideTitle').innerText = title;
    document.getElementById('viewGuideContent').innerText = content.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
    
    document.getElementById('viewGuideEditBtn').onclick = function() {
        closeModal('viewGuidelineModal');
        openGuidelineModal(id, title, content);
    };

    document.getElementById('viewGuidelineModal').style.display = 'flex';
}
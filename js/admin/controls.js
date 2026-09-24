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

// Emergency Types
function openTypeModal(id='', name='', icon='bx-error', incidents='') {
    document.getElementById('type_id').value = id;
    document.getElementById('type_name').value = name;
    document.getElementById('type_icon').value = icon;
    document.getElementById('type_incidents').value = incidents;
    document.getElementById('typeModal').style.display = 'flex';
}

function saveType() {
    let fd = new FormData();
    fd.append('action', 'save_emergency_type');
    fd.append('id', document.getElementById('type_id').value);
    fd.append('name', document.getElementById('type_name').value);
    fd.append('icon', document.getElementById('type_icon').value);
    fd.append('incidents', document.getElementById('type_incidents').value);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

function deleteType(id) {
    if(!confirm('Delete this emergency type?')) return;
    let fd = new FormData(); fd.append('action', 'delete_emergency_type'); fd.append('id', id);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}
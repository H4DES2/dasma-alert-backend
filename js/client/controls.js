const API_PATH = '../php/admin_actions.php';

function closeModal(id) { 
    const el = document.getElementById(id);
    if (el) el.style.display = 'none'; 
}

// Broadcast
function submitGlobalBroadcast() {
    const title = document.getElementById('globalBroadcastTitle').value.trim();
    const message = document.getElementById('globalBroadcastMessage').value.trim();
    const severity = document.getElementById('globalBroadcastSeverity').value;

    if (!title || !message) {
        alert('Please provide both a title and message.');
        return;
    }

    let fd = new FormData();
    fd.append('action', 'send_broadcast');
    fd.append('title', title);
    fd.append('message', message);
    fd.append('severity', severity);

    fetch(API_PATH, { method: 'POST', body: fd })
        .then(res => res.text())
        .then(() => location.reload())
        .catch(err => console.error(err));
}

// Announcements
function openAnnouncementModal(id='', title='', msg='') {
    document.getElementById('ann_id').value = id;
    document.getElementById('ann_title').value = title;
    document.getElementById('ann_message').value = msg.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
    document.getElementById('annModalTitle').innerText = id ? 'Edit Announcement' : 'New Announcement';
    document.getElementById('announcementModal').style.display = 'flex';
}

function saveAnnouncement() {
    const title = document.getElementById('ann_title').value.trim();
    const message = document.getElementById('ann_message').value.trim();

    if (!title || !message) {
        alert('Please fill out all required fields.');
        return;
    }

    let fd = new FormData();
    fd.append('action', 'save_announcement');
    fd.append('id', document.getElementById('ann_id').value);
    fd.append('title', title);
    fd.append('message', message);

    const img = document.getElementById('ann_image').files[0];
    if (img) fd.append('image', img);

    fetch(API_PATH, { method: 'POST', body: fd })
        .then(res => res.text())
        .then(() => location.reload())
        .catch(err => console.error(err));
}

function deleteAnnouncement(id) {
    if (!confirm('Are you sure you want to delete this announcement?')) return;
    let fd = new FormData(); 
    fd.append('action', 'delete_announcement'); 
    fd.append('id', id);

    fetch(API_PATH, { method: 'POST', body: fd })
        .then(res => res.text())
        .then(() => location.reload())
        .catch(err => console.error(err));
}
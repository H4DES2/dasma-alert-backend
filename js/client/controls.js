const API_PATH = '../admin/admin_actions.php';

function closeModal(id) { 
    const el = document.getElementById(id);
    if (el) el.style.display = 'none'; 
}

// Transmit Geofenced Emergency Broadcast
function submitGlobalBroadcast() {
    const titleEl = document.getElementById('globalBroadcastTitle');
    const msgEl   = document.getElementById('globalBroadcastMessage');
    const sevEl   = document.getElementById('globalBroadcastSeverity');

    const title    = titleEl ? titleEl.value.trim() : '';
    const message  = msgEl ? msgEl.value.trim() : '';
    const severity = sevEl ? sevEl.value : 'info';

    if (!title || !message) {
        alert('Please provide both an alert title and message.');
        return;
    }

    let fd = new FormData();
    fd.append('action', 'send_broadcast');
    fd.append('title', title);
    fd.append('message', message);
    fd.append('severity', severity);

    fetch(API_PATH, { method: 'POST', body: fd })
        .then(res => res.text())
        .then(text => {
            let data;
            try {
                data = JSON.parse(text);
            } catch (e) {
                if (text.trim() === 'success') {
                    location.reload();
                    return;
                }
                alert('Server response: ' + text);
                return;
            }

            if (data.success) {
                location.reload();
            } else {
                alert(data.message || 'Failed to transmit broadcast.');
            }
        })
        .catch(err => {
            console.error(err);
            alert('Transmission error: ' + err.message);
        });
}

function stopBroadcast(id) {
    if (!confirm("Terminate this active broadcast warning?")) return; 

    let fd = new FormData(); 
    fd.append('action', 'end_broadcast'); 
    fd.append('id', id); 

    fetch(API_PATH, { method: 'POST', body: fd }) 
        .then(res => res.text()) 
        .then(data => {
            if (data.trim() === 'success') { 
                location.reload(); 
            } else {
                alert("Failed to stop broadcast."); 
            }
        })
        .catch(err => console.error(err));
}

// Announcements
function openAnnouncementModal(id='', title='', msg='') {
    document.getElementById('ann_id').value = id;
    document.getElementById('ann_title').value = title;
    document.getElementById('ann_message').value = msg.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
    document.getElementById('annModalTitle').innerText = id ? 'Edit Announcement' : 'New Announcement';
    const imgInput = document.getElementById('ann_image');
    if (imgInput) imgInput.value = '';
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
        .then(text => {
            if (text.trim() === 'success') {
                location.reload();
            } else {
                try {
                    let d = JSON.parse(text);
                    if (d.success) { location.reload(); return; }
                    alert(d.message || text);
                } catch (e) {
                    alert('Server response: ' + text);
                }
            }
        })
        .catch(err => {
            console.error(err);
            alert('Upload error: ' + err.message);
        });
}

function deleteAnnouncement(id) {
    if (!confirm('Are you sure you want to delete this announcement?')) return;
    let fd = new FormData(); 
    fd.append('action', 'delete_announcement'); 
    fd.append('id', id);

    fetch(API_PATH, { method: 'POST', body: fd })
        .then(res => res.text())
        .then(data => {
            if (data.trim() === 'success') {
                location.reload();
            } else {
                alert('Failed to delete announcement.');
            }
        })
        .catch(err => console.error(err));
}
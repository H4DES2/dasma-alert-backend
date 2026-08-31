// Dropdown toggle
function toggleDropdown(event) { 
    event.stopPropagation(); 
    const prof = document.getElementById('profileDropdown');
    if (prof) prof.classList.toggle('active');
}

window.addEventListener('click', (e) => { 
    const prof = document.getElementById('profileDropdown');
    if (prof && !prof.contains(e.target)) prof.classList.remove('active'); 
});

// Gooey navbar blob calculation
function updateBlob(activeElement, animate = false) {
    const selector = document.querySelector('.hori-selector');
    if (!activeElement || !selector) return;
    if (!animate) selector.style.transition = 'none';
    else selector.style.transition = 'all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55)';
    selector.style.left = activeElement.offsetLeft + "px";
    selector.style.width = activeElement.offsetWidth + "px";
    selector.style.height = activeElement.offsetHeight + "px";
    selector.style.top = activeElement.offsetTop + "px";
}

document.querySelectorAll('#navbarSupportedContent li').forEach(li => {
    li.addEventListener('click', function(e) {
        const anchor = this.querySelector('a');
        if (!anchor) return;
        const url = anchor.getAttribute('href');
        e.preventDefault();
        document.querySelectorAll('#navbarSupportedContent li').forEach(el => el.classList.remove('active'));
        this.classList.add('active');
        updateBlob(this, true);
        setTimeout(() => { window.location.href = url; }, 400);
    });
});

window.addEventListener('load', () => updateBlob(document.querySelector('#navbarSupportedContent li.active'), false));
window.addEventListener('resize', () => { updateBlob(document.querySelector('#navbarSupportedContent li.active'), false); });

// Confirmation Modal (Backup / Logout)
let pendingAction = null;
function showCustomModal(type) {
    const modal = document.getElementById('customConfirmModal');
    const confirmBtn = document.getElementById('modalConfirmBtn');
    const prof = document.getElementById('profileDropdown');
    if (prof) prof.classList.remove('active');

    if (type === 'backup') {
        document.getElementById('modalTitle').innerText = "BACKUP DATABASE";
        document.getElementById('modalMessage').innerText = "STARTING DOWNLOAD NOW?";
        document.getElementById('modalIcon').className = "bx bxs-data";
        document.getElementById('modalIcon').style.color = "#1976d2";
        confirmBtn.style.background = "#1976d2";
        pendingAction = () => window.location.href = 'backup_db.php';
    } else {
        document.getElementById('modalTitle').innerText = "LOGOUT";
        document.getElementById('modalMessage').innerText = "END CURRENT SESSION?";
        document.getElementById('modalIcon').className = "bx bx-log-out-circle";
        document.getElementById('modalIcon').style.color = "#d32f2f";
        confirmBtn.style.background = "#d32f2f";
        pendingAction = () => {
            const f = document.createElement('form'); f.method = 'POST'; f.action = 'dashboard.php';
            const i = document.createElement('input'); i.type = 'hidden'; i.name = 'logout'; i.value = '1';
            f.appendChild(i); document.body.appendChild(f); f.submit();
        };
    }
    modal.style.display = 'flex';
    confirmBtn.onclick = function() { if (pendingAction) pendingAction(); closeCustomModal(); };
}

function closeCustomModal() { 
    const modal = document.getElementById('customConfirmModal');
    if (modal) modal.style.display = 'none'; 
}

// Superadmin Broadcast Modal
function openGlobalBroadcastModal() { 
    const modal = document.getElementById('globalBroadcastModal');
    if (modal) modal.style.display = 'flex'; 
}

function closeGlobalBroadcastModal() { 
    const modal = document.getElementById('globalBroadcastModal');
    if (modal) modal.style.display = 'none'; 
}

function submitGlobalBroadcast() {
    const titleEl = document.getElementById('globalBroadcastTitle');
    const msgEl = document.getElementById('globalBroadcastMessage');
    const sevEl = document.getElementById('globalBroadcastSeverity');
    
    if (!titleEl || !msgEl || !sevEl) return;

    let fd = new FormData();
    fd.append('action', 'send_broadcast');
    fd.append('title', titleEl.value);
    fd.append('message', msgEl.value);
    fd.append('severity', sevEl.value);
    
    fetch('admin_actions.php', { method: 'POST', body: fd })
        .then(() => location.reload());
}
// Dropdown toggle
function toggleDropdown(event) {
    if (event) {
        event.stopPropagation();
    }
    const dropdown = document.getElementById('profileDropdown');
    if (dropdown) {
        dropdown.classList.toggle('active');
    }
}

// Fast Gooey Selector
function updateBlob(activeElement) {
    const selector = document.querySelector('.hori-selector');
    if (!activeElement || !selector) return;
    selector.style.transition = 'all 0.35s cubic-bezier(0.68, -0.55, 0.265, 1.55)';
    selector.style.left = activeElement.offsetLeft + "px";
    selector.style.width = activeElement.offsetWidth + "px";
    selector.style.height = activeElement.offsetHeight + "px";
    selector.style.top = "0px";
}

// Confirmation Modal (Backup / Logout)
let pendingAction = null;
function showCustomModal(type) {
    const modal = document.getElementById('customConfirmModal');
    const confirmBtn = document.getElementById('modalConfirmBtn');
    const prof = document.getElementById('profileDropdown');
    if (prof) prof.classList.remove('active');

    if (!modal || !confirmBtn) return;

    if (type === 'backup') {
        document.getElementById('modalTitle').innerText = "BACKUP DATABASE";
        document.getElementById('modalMessage').innerText = "STARTING DOWNLOAD NOW?";
        document.getElementById('modalIcon').className = "bx bxs-data";
        document.getElementById('modalIcon').style.color = "#1976d2";
        confirmBtn.style.background = "#1976d2";
        pendingAction = () => { window.location.href = 'backup_db.php'; };
    } else {
        document.getElementById('modalTitle').innerText = "LOGOUT";
        document.getElementById('modalMessage').innerText = "END CURRENT SESSION?";
        document.getElementById('modalIcon').className = "bx bx-log-out-circle";
        document.getElementById('modalIcon').style.color = "#d32f2f";
        confirmBtn.style.background = "#d32f2f";
        pendingAction = () => { window.location.href = '../php/logout.php'; };
    }
    modal.style.display = 'flex';
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

    const fd = new FormData();
    fd.append('action', 'send_broadcast');
    fd.append('title', titleEl.value);
    fd.append('message', msgEl.value);
    fd.append('severity', sevEl.value);
    
    fetch('admin_actions.php', { method: 'POST', body: fd })
        .then(() => location.reload());
}

// Event Listeners (CSP Compliant, Non-Inline)
document.addEventListener('DOMContentLoaded', () => {
    const activeLi = document.querySelector('#navbarSupportedContent li.active');
    if (activeLi) {
        updateBlob(activeLi);
    }

    // Profile Toggle
    const profileToggle = document.getElementById('profileToggleBtn');
    if (profileToggle) {
        profileToggle.addEventListener('click', toggleDropdown);
    }

    // Backup Button
    const backupBtn = document.getElementById('navBackupBtn');
    if (backupBtn) {
        backupBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            showCustomModal('backup');
        });
    }

    // Logout Button
    const logoutBtn = document.getElementById('navLogoutBtn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            showCustomModal('logout');
        });
    }

    // Modal Action Handlers
    const confirmBtn = document.getElementById('modalConfirmBtn');
    if (confirmBtn) {
        confirmBtn.addEventListener('click', () => {
            if (pendingAction) pendingAction();
            closeCustomModal();
        });
    }

    const cancelBtn = document.getElementById('modalCancelBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', closeCustomModal);
    }

    // Close Dropdown Clicking Outside
    document.addEventListener('click', (e) => {
        const dropdown = document.getElementById('profileDropdown');
        if (dropdown && !dropdown.contains(e.target)) {
            dropdown.classList.remove('active');
        }
    });

    // 🚀 Instant Click Navigation + Link Hover Preloader
    const preloadedUrls = new Set();
    document.querySelectorAll('#navbarSupportedContent li a').forEach(link => {
        const url = link.getAttribute('href');

        const preloadPage = () => {
            if (url && !preloadedUrls.has(url)) {
                const prefetchLink = document.createElement('link');
                prefetchLink.rel = 'prefetch';
                prefetchLink.href = url;
                document.head.appendChild(prefetchLink);
                preloadedUrls.add(url);
            }
        };

        link.addEventListener('mouseenter', preloadPage, { passive: true });
        link.addEventListener('touchstart', preloadPage, { passive: true });

        link.addEventListener('click', function() {
            const parentLi = this.parentElement;
            document.querySelectorAll('#navbarSupportedContent li').forEach(el => el.classList.remove('active'));
            parentLi.classList.add('active');
            updateBlob(parentLi);
        });
    });
});

window.addEventListener('load', () => {
    updateBlob(document.querySelector('#navbarSupportedContent li.active'));
});

window.addEventListener('resize', () => { 
    updateBlob(document.querySelector('#navbarSupportedContent li.active')); 
});
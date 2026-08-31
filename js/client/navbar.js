function moveHoriSelector(isInitialLoad = false) {
    const nav = document.getElementById('navbarSupportedContent');
    if (!nav) return;
    const ul = nav.querySelector('ul');
    const activeItem = ul.querySelector('li.active');
    const selector = nav.querySelector('.hori-selector');

    if (activeItem && selector) {
        const activeWidth = activeItem.offsetWidth;
        const activeHeight = activeItem.offsetHeight;
        const topPos = activeItem.offsetTop;
        const leftPos = activeItem.offsetLeft;

        if (isInitialLoad) selector.style.transition = 'none';
        else selector.style.transition = 'all 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55)';
        
        selector.style.top = topPos + "px";
        selector.style.left = leftPos + "px";
        selector.style.height = activeHeight + "px";
        selector.style.width = activeWidth + "px";

        if (isInitialLoad) {
            void selector.offsetWidth; 
            selector.classList.add('ready');
            selector.style.transition = 'all 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55)';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() { moveHoriSelector(true); });
window.addEventListener('load', function() { moveHoriSelector(true); });
window.addEventListener('resize', function() { moveHoriSelector(false); });

document.querySelectorAll('#navbarSupportedContent li').forEach(li => {
    li.addEventListener('click', function(e) {
        e.preventDefault(); 
        const targetLink = this.querySelector('a').getAttribute('href');
        document.querySelectorAll('#navbarSupportedContent li').forEach(el => el.classList.remove('active'));
        this.classList.add('active');
        moveHoriSelector(false);
        setTimeout(() => { window.location.href = targetLink; }, 350); 
    });
});

function toggleDropdown(event) { 
    event.stopPropagation(); 
    const dropdown = document.getElementById('profileDropdown');
    if (dropdown) dropdown.classList.toggle('active'); 
}

window.addEventListener('click', function(e) { 
    const dropdown = document.getElementById('profileDropdown'); 
    if (dropdown && !dropdown.contains(e.target)) { 
        dropdown.classList.remove('active'); 
    } 
});

let pendingAction = null;
function showCustomModal(type) {
    const modal = document.getElementById('customConfirmModal');
    const confirmBtn = document.getElementById('modalConfirmBtn');
    const dropdown = document.getElementById('profileDropdown');
    if (dropdown) dropdown.classList.remove('active');

    if (type === 'logout') {
        pendingAction = () => {
            const form = document.createElement('form'); 
            form.method = 'POST'; 
            form.action = 'dashboard.php';
            const input = document.createElement('input'); 
            input.type = 'hidden'; 
            input.name = 'logout'; 
            input.value = '1';
            form.appendChild(input); 
            document.body.appendChild(form); 
            form.submit();
        };
    }
    if (modal) modal.style.display = 'flex';
    if (confirmBtn) {
        confirmBtn.onclick = function() { 
            if (pendingAction) pendingAction(); 
            closeCustomModal(); 
        };
    }
}

function closeCustomModal() { 
    const modal = document.getElementById('customConfirmModal');
    if (modal) modal.style.display = 'none'; 
    pendingAction = null; 
}
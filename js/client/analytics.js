const allIncidents = window.allIncidents || [];
let lineChartInstance = null;

document.addEventListener("DOMContentLoaded", function() {
    const isDarkMode = document.documentElement.classList.contains('global-dark-mode');
    const textColor = isDarkMode ? '#8b949e' : '#888';

    // 1. PIE CHART INITIALIZATION
    const pieCanvas = document.getElementById('breakdownPieChart');
    if (pieCanvas) {
        new Chart(pieCanvas.getContext('2d'), {
            type: 'pie',
            data: {
                labels: window.pieLabels || [],
                datasets: [{
                    data: window.pieValues || [],
                    backgroundColor: ['#1976d2', '#d32f2f', '#f57c00', '#388e3c', '#8e24aa', '#fbc02d', '#009688', '#795548'],
                    borderWidth: 2,
                    borderColor: isDarkMode ? '#161b22' : '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { position: 'right', labels: { color: textColor, font: { weight: 'bold' } } }
                }
            }
        });
    }

    // 2. INITIALIZE SEASONALITY CHART (Default: All Time)
    renderSeasonality();
});

// DYNAMIC SEASONALITY FUNCTION (Triggers on dropdown change)
function renderSeasonality() {
    const canvasElement = document.getElementById('seasonalityLineChart');
    if (!canvasElement) return;

    const filterEl = document.getElementById('seasonalityFilter');
    const filter = filterEl ? filterEl.value : 'all';
    const now = new Date();
    
    // Filter incidents by time range
    let filtered = allIncidents.filter(inc => {
        if (filter === 'all') return true;
        const incDate = new Date(inc.created_at);
        const diffDays = (now - incDate) / (1000 * 60 * 60 * 24);
        
        if (filter === 'week') return diffDays <= 7;
        if (filter === 'month') return diffDays <= 30;
        if (filter === 'year') return diffDays <= 365;
        return true;
    });

    // Group dates together
    let timelineData = {};
    [...filtered].reverse().forEach(inc => {
        let dateObj = new Date(inc.created_at);
        let day = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        timelineData[day] = (timelineData[day] || 0) + 1;
    });

    const labels = Object.keys(timelineData);
    const data = Object.values(timelineData);

    const ctx = canvasElement.getContext('2d');

    if (lineChartInstance) {
        lineChartInstance.data.labels = labels;
        lineChartInstance.data.datasets[0].data = data;
        lineChartInstance.update();
    } else {
        const isDarkMode = document.documentElement.classList.contains('global-dark-mode');
        const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
        const textColor = isDarkMode ? '#8b949e' : '#888';
        let gradient = ctx.createLinearGradient(0, 0, 0, 400);
        gradient.addColorStop(0, 'rgba(211, 47, 47, 0.5)');
        gradient.addColorStop(1, 'rgba(211, 47, 47, 0.0)');

        lineChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Incidents',
                    data: data,
                    borderColor: '#d32f2f',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#d32f2f',
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: textColor, font: { weight: 'bold' } } },
                    y: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { stepSize: 1, color: textColor, font: { weight: 'bold' } } }
                }
            }
        });
    }
}

function viewLogs(data, title) {
    const titleEl = document.getElementById('logTitle');
    if (titleEl) titleEl.innerHTML = `<i class='bx bx-list-ul'></i> ${title} Timeline`;
    
    const container = document.getElementById('logContainer');
    if (!container) return;
    container.innerHTML = '';
    
    if (!data) { 
        container.innerHTML = '<p style="text-align:center; padding:20px; color:#888;">No logs found.</p>'; 
    } else {
        data.split('|||').forEach(line => {
            let p = line.split('|-|');
            if (p.length === 3) {
                container.innerHTML += `
                    <div style="background:rgba(0,0,0,0.03); padding:15px; border-radius:12px; margin-bottom:12px; border-left:5px solid #1976d2;">
                        <small><b style="color: #1976d2;">${p[0]} - ${p[1]}</b></small><br>
                        <span style="font-weight: 600;">${p[2]}</span>
                    </div>`;
            }
        });
    }
    const modal = document.getElementById('viewLogsModal');
    if (modal) modal.style.display = 'flex';
}

function viewEvidence(imagePath, incidentType, brgy) { 
    if (!imagePath || imagePath === 'null' || imagePath === '') {
        document.getElementById('uniModalIcon').className = 'bx bx-image';
        document.getElementById('uniModalIcon').style.color = '#fbc02d';
        document.getElementById('uniModalTitle').innerText = "No Evidence";
        document.getElementById('uniModalText').innerText = "No photo evidence was uploaded.";
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="document.getElementById('universalModal').style.display='none'" style="flex:1; padding:12px; background:#fbc02d; color:white; border:none; border-radius:10px; cursor:pointer; font-weight:bold;">OK</button>`;
        document.getElementById('universalModal').style.display = 'flex';
        return;
    }
    
    let cleanPath = imagePath.startsWith('/') ? imagePath.substring(1) : imagePath;
    let finalUrl = '/dasma_api/' + cleanPath.replace('dasma-api/', '');
    
    document.getElementById('evidenceImageFull').src = finalUrl; 
    document.getElementById('evidenceCaption').innerText = `Visual Evidence: ${incidentType} in Brgy. ${brgy}`; 
    document.getElementById('evidenceModal').style.display = 'flex'; 
}

function deleteArchived(id) {
    document.getElementById('uniModalIcon').className = 'bx bxs-trash';
    document.getElementById('uniModalIcon').style.color = '#d32f2f';
    document.getElementById('uniModalTitle').innerText = "Delete Record";
    document.getElementById('uniModalText').innerText = "Permanently remove this incident from the vault?";
    
    document.getElementById('uniModalButtons').innerHTML = `
        <button onclick="document.getElementById('universalModal').style.display='none'" style="flex:1; padding:12px; border-radius:10px; cursor:pointer; border:1px solid #ccc; background:transparent; font-weight:800;">Cancel</button>
        <button onclick="confirmDelete(${id})" style="flex:1; padding:12px; border-radius:10px; cursor:pointer; border:none; background:#d32f2f; color:white; font-weight:800;">Delete</button>
    `;
    document.getElementById('universalModal').style.display = 'flex';
}

function confirmDelete(id) {
    let fd = new FormData(); 
    fd.append('action', 'delete_archived'); 
    fd.append('id', id);
    fetch('../admin/admin_actions.php', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(() => location.reload());
}

function openMobileModal(row) {
    if (window.innerWidth > 768) return; 

    const cells = row.querySelectorAll('td');
    if (cells.length < 4) return;

    document.getElementById('m-inc-title').innerHTML = cells[0].innerHTML.replace(/<i.*<\/i>/, ''); 

    const bodyEl = document.getElementById('m-inc-body');
    let html = '';
    html += `<div class="mobile-detail-box"><small class="mobile-label">Barangay</small>${cells[1].innerHTML}</div>`;
    html += `<div class="mobile-detail-box" style="text-align: center;"><small class="mobile-label">Severity</small><div style="margin-top:5px;">${cells[2].innerHTML}</div></div>`;
    html += `<div style="margin-top: 5px;"><small class="mobile-label">Archive Actions</small><div class="m-actions-container" style="display:flex; flex-wrap: wrap; gap:10px; width:100%; justify-content: center; padding-top: 8px;">
                ${cells[3].innerHTML}
            </div></div>`;

    bodyEl.innerHTML = html;
    document.getElementById('mobileDetailsModal').style.display = 'flex';
}
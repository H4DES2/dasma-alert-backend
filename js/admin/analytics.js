const allIncidents = window.allIncidents || [];
const binIncidents = window.binIncidents || [];
const allSeasonDates = window.allSeasonDates || [];
let lineChartInstance = null;

// -----------------------------------------------------
// INITIALIZE CHARTS
// -----------------------------------------------------
document.addEventListener("DOMContentLoaded", function() {
    if (typeof ChartDataLabels !== 'undefined') {
        Chart.register(ChartDataLabels);
    }
    
    const isDarkMode = document.documentElement.classList.contains('global-dark-mode');
    const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
    const textColor = isDarkMode ? '#8b949e' : '#888';

    // 1. Pie Chart
    const pieCanvas = document.getElementById('typePieChart');
    if (pieCanvas) {
        new Chart(pieCanvas.getContext('2d'), {
            type: 'pie',
            data: {
                labels: window.typeLabels || [],
                datasets: [{
                    data: window.typeData || [],
                    backgroundColor: window.typeColors || [],
                    borderWidth: 2,
                    borderColor: isDarkMode ? '#161b22' : '#ffffff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { padding: 20, font: { weight: 'bold' }, color: textColor } },
                    datalabels: {
                        color: '#ffffff',
                        font: { weight: 'bold', size: 16 },
                        formatter: (value, context) => {
                            let dataArr = context.chart.data.datasets[0].data;
                            let total = 0;
                            dataArr.forEach(data => { total += parseInt(data); });
                            let percentage = Math.round((value / total) * 100);
                            return percentage >= 4 ? percentage + '%' : '';
                        }
                    }
                }
            }
        });
    }

    // 2. Seasonality Line Chart Initializer
    renderSeasonality();

    // 3. Evacuation Overflow Bar Chart
    const evacCanvas = document.getElementById('evacOverflowChart');
    if (evacCanvas) {
        new Chart(evacCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: window.evacLabels || [],
                datasets: [
                    { label: 'Current Occupants', data: window.evacOccupants || [], backgroundColor: '#f57c00', borderRadius: 4 },
                    { label: 'Total Capacity', data: window.evacCapacity || [], backgroundColor: '#1976d2', borderRadius: 4 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { datalabels: { display: false }, legend: { labels: { color: textColor } } },
                scales: {
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, precision: 0 } },
                    x: { grid: { display: false }, ticks: { color: textColor } }
                }
            }
        });
    }

    // 4. Leaflet Heatmap
    const heatmapEl = document.getElementById('heatmap');
    if (heatmapEl) {
        let dasmaBounds = L.latLngBounds([14.2700, 120.9150], [14.3750, 121.0100]);
        const map = L.map('heatmap', { center: [14.3294, 120.9368], zoom: 14, minZoom: 13, maxBounds: dasmaBounds });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);
        
        const heatData = window.heatData || [];
        if (heatData.length > 0) {
            L.heatLayer(heatData, { radius: 25, blur: 15, maxZoom: 15 }).addTo(map);
        }
    }
});

// -----------------------------------------------------
// DYNAMIC SEASONALITY FUNCTION
// -----------------------------------------------------
function renderSeasonality() {
    const canvasElement = document.getElementById('seasonalityLineChart');
    if (!canvasElement) return;

    const filter = document.getElementById('seasonalityFilter').value;
    const now = new Date();
    
    let filtered = allSeasonDates.filter(dateStr => {
        if (filter === 'all') return true;
        const incDate = new Date(dateStr.replace(' ', 'T'));
        const diffDays = (now - incDate) / (1000 * 60 * 60 * 24);
        if (filter === 'week') return diffDays <= 7;
        if (filter === 'month') return diffDays <= 30;
        if (filter === 'year') return diffDays <= 365;
        return true;
    });

    let timelineData = {};
    [...filtered].sort().forEach(dateStr => {
        let dateObj = new Date(dateStr.replace(' ', 'T'));
        let day = dateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        timelineData[day] = (timelineData[day] || 0) + 1;
    });

    const labels = Object.keys(timelineData);
    const data = Object.values(timelineData);

    const overlay = document.getElementById('seasonalityOverlay');
    if (overlay) {
        overlay.style.display = (labels.length === 0) ? 'flex' : 'none';
    }

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
                plugins: { legend: { display: false }, datalabels: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: textColor, font: { weight: 'bold' } } },
                    y: { beginAtZero: true, grid: { color: gridColor, drawBorder: false }, ticks: { stepSize: 1, color: textColor, font: { weight: 'bold' } } }
                }
            }
        });
    }
}

// -----------------------------------------------------
// MASTER EXPORT FUNCTIONS (CSV & PDF)
// -----------------------------------------------------
function exportCSV() {
    let csvContent = "data:text/csv;charset=utf-8,";
    
    // 1. Vault Data
    csvContent += "--- INCIDENT ARCHIVE VAULT ---\n";
    csvContent += "ID,Type,Severity,Barangay,Latitude,Longitude,Reported,Arrived,Resolved,Initial Log,Full Timeline Logs\n";
    allIncidents.forEach(inc => {
        let row = [
            inc.id, inc.incident_type, inc.severity, inc.barangay, inc.latitude, inc.longitude,
            inc.created_at, inc.arrived_at || 'N/A', inc.resolved_at || 'N/A',
            `"${(inc.initial_log || '').replace(/"/g, '""')}"`,
            `"${(inc.all_logs || '').replace(/\|\|\|/g, ' \n ').replace(/\|-\|/g, ': ').replace(/"/g, '""')}"`
        ];
        csvContent += row.join(",") + "\n";
    });

    // 2. Spam/Bin Data
    csvContent += "\n--- REPORT BIN (REJECTED/SPAM) ---\n";
    csvContent += "ID,Type,Status,Barangay,Reported Date,Reject Reason,Full Timeline Logs\n";
    binIncidents.forEach(bin => {
        let row = [
            bin.id, bin.incident_type, bin.status, bin.barangay, bin.created_at,
            `"${(bin.spam_reason || bin.admin_remarks || '').replace(/"/g, '""')}"`,
            `"${(bin.all_logs || '').replace(/\|\|\|/g, ' \n ').replace(/\|-\|/g, ': ').replace(/"/g, '""')}"`
        ];
        csvContent += row.join(",") + "\n";
    });

    let encodedUri = encodeURI(csvContent);
    let link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "Citywide_Comprehensive_Report.csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape'); 
    
    doc.setFontSize(18);
    doc.setTextColor(25, 118, 210);
    doc.text("Citywide Comprehensive Report", 14, 15);
    doc.setFontSize(10);
    doc.setTextColor(100, 100, 100);
    doc.text("Generated on: " + new Date().toLocaleString(), 14, 22);

    // Vault Table
    doc.setFontSize(14);
    doc.setTextColor(50, 50, 50);
    doc.text("Incident Archive Vault", 14, 32);
    
    let vaultRows = allIncidents.map(inc => [
        inc.id, inc.incident_type, inc.severity, inc.barangay, 
        inc.created_at, inc.resolved_at || 'N/A', 
        (inc.initial_log || '').substring(0, 50) + "..."
    ]);

    doc.autoTable({
        startY: 36,
        head: [['ID', 'Type', 'Severity', 'Barangay', 'Reported', 'Resolved', 'Initial Log']],
        body: vaultRows,
        theme: 'grid',
        headStyles: { fillColor: [25, 118, 210] },
        styles: { fontSize: 8, overflow: 'linebreak' },
        columnStyles: { 6: { cellWidth: 60 } }
    });

    let finalY = doc.lastAutoTable.finalY || 36;

    // Bin Table
    doc.setFontSize(14);
    doc.setTextColor(50, 50, 50);
    doc.text("Report Bin (Rejected / Spam)", 14, finalY + 15);
    
    let binRows = binIncidents.map(bin => [
        bin.id, bin.incident_type, bin.status.toUpperCase(), bin.barangay, 
        bin.created_at, 
        (bin.spam_reason || bin.admin_remarks || 'No reason').substring(0, 60) + "..."
    ]);

    doc.autoTable({
        startY: finalY + 20,
        head: [['ID', 'Type', 'Status', 'Barangay', 'Reported Date', 'Reject Reason']],
        body: binRows,
        theme: 'grid',
        headStyles: { fillColor: [66, 66, 66] },
        styles: { fontSize: 8, overflow: 'linebreak' }
    });

    doc.save('Citywide_Comprehensive_Report.pdf');
}

function applyFilters() { 
    let typeVal = document.getElementById('typeFilter').value;
    let timeVal = document.getElementById('timeFilter').value;
    let vaultTimeVal = document.getElementById('vaultTimeFilter').value;
    window.location.href = `analytics.php?type=${encodeURIComponent(typeVal)}&time=${encodeURIComponent(timeVal)}&vault_time=${encodeURIComponent(vaultTimeVal)}`; 
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

function viewLogs(logsData, title) {
    document.getElementById('logIncidentTitle').innerText = title + " Timeline";
    let logContainer = document.getElementById('logContainer');
    logContainer.innerHTML = ''; 

    if (logsData === 'No logs recorded.') { 
        logContainer.innerHTML = '<p style="text-align:center; color:#777; padding:20px;">No logs found.</p>'; 
    } else {
        logsData.split('|||').forEach(line => {
            let p = line.split('|-|');
            if (p.length === 3) {
                logContainer.innerHTML += `
                    <div class="timeline-log-card">
                        <small><b class="timeline-log-meta">${p[0]} - ${p[1]}</b></small><br>
                        <span class="timeline-log-msg">${p[2]}</span>
                    </div>`;
            }
        });
    }
    document.getElementById('viewLogsModal').style.display = 'flex';
}

function viewPhoto(rawPath) {
    let cleanPath = rawPath;
    if (cleanPath.startsWith('/')) { 
        cleanPath = cleanPath.substring(1); 
    }
    
    let finalUrl = '/dasma_api/' + cleanPath;
    
    document.getElementById('evidencePhotoViewer').src = finalUrl;
    document.getElementById('viewPhotoModal').style.display = 'flex';
}

function stopBroadcast(id) {
    if (confirm("Stop this active broadcast?")) {
        let fd = new FormData();
        fd.append('action', 'end_broadcast');
        fd.append('id', id);
        fetch('admin_actions.php', { method: 'POST', body: fd })
        .then(res => res.text())
        .then(data => {
            if (data.trim() === 'success') {
                location.reload();
            } else {
                alert("Failed to stop broadcast.");
            }
        });
    }
}

// -----------------------------------------------------
// MOBILE MODAL LOGIC
// -----------------------------------------------------
function openMobileModal(row, type) {
    if (window.innerWidth > 768) return; 
    
    const cells = row.querySelectorAll('td');
    const titleEl = document.getElementById('m-analytics-title');
    const bodyEl = document.getElementById('m-analytics-body');
    
    let html = '';
    
    if (type === 'archive') {
        titleEl.innerHTML = "Archive Details";
        html += `<div class="mobile-detail-box"><small class="mobile-label">Incident & Logs</small>${cells[0].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Location & Status</small>${cells[1].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Response Timeline</small>${cells[2].innerHTML}</div>`;
        html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; gap:10px; width:100%;">${cells[3].innerHTML}</div></div>`;
    } 
    else if (type === 'bin') {
        titleEl.innerHTML = "Report Bin Details";
        html += `<div class="mobile-detail-box"><small class="mobile-label">Incident & Logs</small>${cells[0].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Location & Status</small>${cells[1].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Rejection Reason</small>${cells[2].innerHTML}</div>`;
        html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; gap:10px; width:100%;">${cells[3].innerHTML}</div></div>`;
    }
    else if (type === 'broadcast') {
        titleEl.innerHTML = "Broadcast Details";
        html += `<div class="mobile-detail-box"><small class="mobile-label">Alert Title & Date</small>${cells[0].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Severity</small><div style="margin-top: 5px;">${cells[1].innerHTML}</div></div>`;
        html += `<div style="margin-top: 5px;"><small class="mobile-label">Status & Actions</small><div class="m-actions-container" style="display:flex; flex-direction:column; gap:10px; width:100%;">${cells[2].innerHTML}</div></div>`;
    }

    bodyEl.innerHTML = html;
    
    let btnGroups = bodyEl.querySelectorAll('.btn-action-group, .btn-action');
    btnGroups.forEach(grp => {
        if (grp.classList.contains('btn-action-group')) {
            grp.style.display = 'flex';
            grp.style.width = '100%';
            let btns = grp.querySelectorAll('button');
            btns.forEach(b => { b.style.flex = '1'; b.style.padding = '12px'; });
        } else {
            grp.style.width = '100%';
            grp.style.justifyContent = 'center';
        }
    });

    bodyEl.querySelectorAll('.mobile-expand-icon').forEach(icon => icon.style.display = 'none');

    document.getElementById('mobileAnalyticsModal').style.display = 'flex';
}
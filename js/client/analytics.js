const allIncidents = window.allIncidents || [];
const rejectedIncidents = window.rejectedIncidents || [];
let currentTab = 'vault'; // 'vault' or 'bin'
let lineChartInstance = null;

document.addEventListener("DOMContentLoaded", function() {
    const isDarkMode = document.documentElement.classList.contains('global-dark-mode');
    const textColor = isDarkMode ? '#8b949e' : '#888';

    // 1. Doughnut Chart Breakdown
    const pieCanvas = document.getElementById('breakdownPieChart');
    if (pieCanvas) {
        const labels = window.pieLabels && window.pieLabels.length ? window.pieLabels : ['No Incident Data'];
        const values = window.pieValues && window.pieValues.length ? window.pieValues : [1];
        const colors = window.pieValues && window.pieValues.length 
            ? ['#1976d2', '#d32f2f', '#f57c00', '#388e3c', '#8e24aa', '#fbc02d', '#009688', '#795548']
            : [isDarkMode ? '#21262d' : '#e2e8f0'];

        new Chart(pieCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    backgroundColor: colors,
                    borderWidth: 2,
                    borderColor: isDarkMode ? '#161b22' : '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { 
                    legend: { 
                        position: 'right', 
                        labels: { color: textColor, font: { weight: 'bold' } } 
                    }
                }
            }
        });
    }

    // Populate Incident Types into Filter Dropdown
    populateTypeFilter();

    // 2. Initialize Disaster Seasonality
    renderSeasonality();
});

// Populate Types Dropdown from Active Datasets
function populateTypeFilter() {
    const select = document.getElementById('vaultTypeFilter');
    if (!select) return;

    const typesSet = new Set();
    allIncidents.forEach(i => { if (i.incident_type) typesSet.add(i.incident_type.trim()); });
    rejectedIncidents.forEach(i => { if (i.incident_type) typesSet.add(i.incident_type.trim()); });

    select.innerHTML = '<option value="all">🌍 All Types</option>';
    typesSet.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t;
        opt.innerText = t;
        select.appendChild(opt);
    });
}

// Fixed Disaster Seasonality Chart
function renderSeasonality() {
    const canvasElement = document.getElementById('seasonalityLineChart');
    if (!canvasElement) return;

    const filterEl = document.getElementById('seasonalityFilter');
    const filter = filterEl ? filterEl.value : 'all';
    const now = new Date();
    const dataset = window.allIncidents || [];

    let labels = [];
    let dataPoints = [];

    if (filter === 'all' || filter === 'year') {
        // Full 12-month calendar spectrum
        labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        dataPoints = new Array(12).fill(0);

        dataset.forEach(inc => {
            const incDate = new Date(inc.created_at);
            if (filter === 'year') {
                const diffDays = (now - incDate) / (1000 * 60 * 60 * 24);
                if (diffDays > 365) return;
            }
            const m = incDate.getMonth();
            if (m >= 0 && m < 12) dataPoints[m]++;
        });
    } else if (filter === 'month') {
        // Past 30 consecutive days
        const dateMap = {};
        for (let i = 29; i >= 0; i--) {
            const d = new Date();
            d.setDate(d.getDate() - i);
            const key = d.toISOString().split('T')[0];
            const displayLabel = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            labels.push(displayLabel);
            dateMap[key] = 0;
        }

        dataset.forEach(inc => {
            const key = (inc.created_at || '').substring(0, 10);
            if (dateMap[key] !== undefined) dateMap[key]++;
        });

        dataPoints = Object.values(dateMap);
    } else if (filter === 'week') {
        // Past 7 consecutive days
        const dateMap = {};
        for (let i = 6; i >= 0; i--) {
            const d = new Date();
            d.setDate(d.getDate() - i);
            const key = d.toISOString().split('T')[0];
            const displayLabel = d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
            labels.push(displayLabel);
            dateMap[key] = 0;
        }

        dataset.forEach(inc => {
            const key = (inc.created_at || '').substring(0, 10);
            if (dateMap[key] !== undefined) dateMap[key]++;
        });

        dataPoints = Object.values(dateMap);
    }

    const ctx = canvasElement.getContext('2d');
    const isDarkMode = document.documentElement.classList.contains('global-dark-mode');
    const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.05)';
    const textColor = isDarkMode ? '#8b949e' : '#888';

    let gradient = ctx.createLinearGradient(0, 0, 0, 320);
    gradient.addColorStop(0, 'rgba(211, 47, 47, 0.45)');
    gradient.addColorStop(1, 'rgba(211, 47, 47, 0.0)');

    const maxVal = Math.max(...dataPoints, 0);

    if (lineChartInstance) {
        lineChartInstance.data.labels = labels;
        lineChartInstance.data.datasets[0].data = dataPoints;
        lineChartInstance.options.scales.y.suggestedMax = Math.max(maxVal + 1, 3);
        lineChartInstance.update();
    } else {
        lineChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Recorded Incidents',
                    data: dataPoints,
                    borderColor: '#d32f2f',
                    backgroundColor: gradient,
                    borderWidth: 3,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#d32f2f',
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                return ` ${ctx.parsed.y} Incident(s)`;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { color: textColor, font: { weight: 'bold', size: 11 } }
                    },
                    y: {
                        beginAtZero: true,
                        suggestedMax: Math.max(maxVal + 1, 3),
                        grid: { color: gridColor, drawBorder: false },
                        ticks: { stepSize: 1, precision: 0, color: textColor, font: { weight: 'bold' } }
                    }
                }
            }
        });
    }
}

// Tab Switcher between Archive Vault & Report Bin
function switchAnalyticsTab(tab) {
    currentTab = tab;
    const tabVault = document.getElementById('tabVaultBtn');
    const tabBin = document.getElementById('tabBinBtn');
    const vaultWrapper = document.getElementById('vaultTableWrapper');
    const binWrapper = document.getElementById('binTableWrapper');

    if (tab === 'vault') {
        tabVault.classList.add('active');
        tabBin.classList.remove('active');
        vaultWrapper.style.display = 'block';
        binWrapper.style.display = 'none';
    } else {
        tabBin.classList.add('active');
        tabVault.classList.remove('active');
        vaultWrapper.style.display = 'none';
        binWrapper.style.display = 'block';
    }
    filterVaultData();
}

// Evaluate whether an incident matches time & type filters
function matchesFilters(inc, timeFilter, typeFilter) {
    if (typeFilter !== 'all' && (inc.incident_type || '').toLowerCase() !== typeFilter.toLowerCase()) {
        return false;
    }

    if (timeFilter === 'all') return true;

    const incDate = new Date(inc.created_at);
    const now = new Date();
    const diffDays = (now - incDate) / (1000 * 60 * 60 * 24);

    if (timeFilter === 'today') {
        return incDate.toDateString() === now.toDateString();
    }
    if (timeFilter === 'week') return diffDays <= 7;
    if (timeFilter === 'month') return diffDays <= 30;
    if (timeFilter === 'quarter') return diffDays <= 90;
    if (timeFilter === 'year') return diffDays <= 365;

    return true;
}

// Real-Time Table Filter
function filterVaultData() {
    const timeFilter = document.getElementById('vaultTimeFilter')?.value || 'all';
    const typeFilter = document.getElementById('vaultTypeFilter')?.value || 'all';

    if (currentTab === 'vault') {
        const filtered = allIncidents.filter(i => matchesFilters(i, timeFilter, typeFilter));
        const countEl = document.getElementById('count-vault');
        if (countEl) countEl.innerText = filtered.length;

        const tbody = document.getElementById('vaultTableBody');
        if (!tbody) return;

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:35px; color:#888; font-weight:bold;">No archive records matching this filter query.</td></tr>';
            return;
        }

        tbody.innerHTML = filtered.map(inc => {
            const badge = (inc.severity || '').toLowerCase() === 'critical' ? 'critical' : ((inc.severity || '').toLowerCase() === 'minor' ? 'minor' : 'major');
            const safeImg = (inc.image_path || '').replace(/'/g, "\\'");
            const safeType = (inc.incident_type || '').replace(/'/g, "\\'");
            const safeBrgy = (inc.barangay || '').replace(/'/g, "\\'");
            const logsAttr = (inc.all_logs || '').replace(/"/g, '&quot;');

            return `<tr class="clickable-row" onclick="openMobileModal(this)">
                <td>
                    <div class="incident-title-text" style="font-weight: 800; font-size: 1.05rem;">${inc.incident_type}</div>
                    <div class="incident-date-text" style="font-size: 0.8rem; font-weight: 600;">${inc.date_str}</div>
                    <i class='bx bx-chevron-right mobile-expand-icon'></i>
                </td>
                <td><span class="incident-title-text" style="font-weight: 800; font-size: 1rem;">${inc.barangay}</span></td>
                <td style="text-align: center;"><span class="badge ${badge}">${(inc.severity || 'PENDING').toUpperCase()}</span></td>
                <td>
                    <div class="btn-action-group">
                        <button type="button" class="btn-table-icon bg-green" onclick="event.stopPropagation(); viewEvidence('${safeImg}', '${safeType}', '${safeBrgy}')"><i class='bx bx-image'></i></button>
                        <button type="button" class="btn-table-icon bg-blue" data-logs="${logsAttr}" data-type="${safeType}" onclick="event.stopPropagation(); openLogModal(this)"><i class='bx bx-list-ul'></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');
    } else {
        const filtered = rejectedIncidents.filter(i => matchesFilters(i, timeFilter, typeFilter));
        const countEl = document.getElementById('count-bin');
        if (countEl) countEl.innerText = filtered.length;

        const tbody = document.getElementById('binTableBody');
        if (!tbody) return;

        if (filtered.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:35px; color:#888; font-weight:bold;">No rejected records matching this filter query.</td></tr>';
            return;
        }

        tbody.innerHTML = filtered.map(rinc => {
            const rbadge = (rinc.severity || '').toLowerCase() === 'critical' ? 'critical' : ((rinc.severity || '').toLowerCase() === 'minor' ? 'minor' : 'major');
            const safeImg = (rinc.image_path || '').replace(/'/g, "\\'");
            const safeType = (rinc.incident_type || '').replace(/'/g, "\\'");
            const safeBrgy = (rinc.barangay || '').replace(/'/g, "\\'");
            const logsAttr = (rinc.all_logs || '').replace(/"/g, '&quot;');
            const reason = rinc.admin_remarks || rinc.spam_reason || 'Flagged as false alarm or duplicate.';

            return `<tr class="clickable-row" onclick="openMobileModal(this)">
                <td>
                    <div class="incident-title-text" style="font-weight: 800; font-size: 1.05rem; color: #d32f2f;">${rinc.incident_type}</div>
                    <div class="incident-date-text" style="font-size: 0.8rem; font-weight: 600;">${rinc.date_str}</div>
                    <i class='bx bx-chevron-right mobile-expand-icon'></i>
                </td>
                <td>
                    <div style="font-size: 0.85rem; font-weight: 600; line-height: 1.35; color: var(--text-secondary, #555); max-width: 320px;">
                        <i class='bx bx-error-circle' style="color: #d32f2f; vertical-align: middle;"></i> ${reason}
                    </div>
                </td>
                <td style="text-align: center;"><span class="badge ${rbadge}">${(rinc.severity || 'PENDING').toUpperCase()}</span></td>
                <td>
                    <div class="btn-action-group">
                        <button type="button" class="btn-table-icon bg-green" onclick="event.stopPropagation(); viewEvidence('${safeImg}', '${safeType}', '${safeBrgy}')"><i class='bx bx-image'></i></button>
                        <button type="button" class="btn-table-icon bg-blue" data-logs="${logsAttr}" data-type="${safeType}" onclick="event.stopPropagation(); openLogModal(this)"><i class='bx bx-list-ul'></i></button>
                        <button type="button" class="btn-table-icon bg-red" onclick="event.stopPropagation(); deleteArchived(${rinc.id})" title="Permanently delete"><i class='bx bx-trash'></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }
}

// Download File Handler (CSV & PDF with Active Query)
function exportFilteredData(format) {
    const timeFilter = document.getElementById('vaultTimeFilter')?.value || 'all';
    const typeFilter = document.getElementById('vaultTypeFilter')?.value || 'all';
    const sourceData = (currentTab === 'vault') ? allIncidents : rejectedIncidents;
    const filtered = sourceData.filter(i => matchesFilters(i, timeFilter, typeFilter));

    const scopeName = (currentTab === 'vault') ? 'Incident Archive Vault' : 'Rejection Report Bin';
    const sectorName = window.currentSector || 'Sector';

    if (filtered.length === 0) {
        alert("No records match the current filter query to export.");
        return;
    }

    if (format === 'csv') {
        let csvContent = `\uFEFF"CDRRMO COMMAND CENTER - SECTOR ANALYTICS EXPORT"\n`;
        csvContent += `"Sector","${sectorName}"\n`;
        csvContent += `"Report Scope","${scopeName}"\n`;
        csvContent += `"Time Filter Query","${timeFilter.toUpperCase()}"\n`;
        csvContent += `"Type Filter Query","${typeFilter.toUpperCase()}"\n`;
        csvContent += `"Generated At","${new Date().toLocaleString()}"\n`;
        csvContent += `"Total Records","${filtered.length}"\n\n`;

        if (currentTab === 'vault') {
            csvContent += `"ID","Barangay","Incident Type","Severity","Date & Time","Coordinates","Timeline Logs"\n`;
            filtered.forEach(i => {
                const logs = (i.all_logs || '').replace(/"/g, '""');
                csvContent += `"${i.id}","${i.barangay}","${i.incident_type}","${i.severity}","${i.date_str}","${i.latitude}, ${i.longitude}","${logs}"\n`;
            });
        } else {
            csvContent += `"ID","Barangay","Incident Type","Severity","Date & Time","Audit / Rejection Notes","Coordinates","Timeline Logs"\n`;
            filtered.forEach(i => {
                const reason = (i.admin_remarks || i.spam_reason || 'Rejected').replace(/"/g, '""');
                const logs = (i.all_logs || '').replace(/"/g, '""');
                csvContent += `"${i.id}","${i.barangay}","${i.incident_type}","${i.severity}","${i.date_str}","${reason}","${i.latitude}, ${i.longitude}","${logs}"\n`;
            });
        }

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `CDRRMO_${sectorName.replace(/\s+/g, '_')}_${currentTab === 'vault' ? 'Archive' : 'ReportBin'}_${timeFilter}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } else if (format === 'pdf') {
        const printWindow = window.open('', '_blank', 'width=900,height=700');
        if (!printWindow) {
            alert('Popup blocked. Please allow popups to generate the PDF print report.');
            return;
        }

        let tableRows = '';
        if (currentTab === 'vault') {
            tableRows = filtered.map(i => `
                <tr>
                    <td><b>${i.incident_type}</b><br><small style="color:#666;">${i.date_str}</small></td>
                    <td>${i.barangay}</td>
                    <td style="text-align:center;"><b>${i.severity}</b></td>
                    <td><small style="color:#555;">${(i.all_logs || 'No logs recorded').replace(/\|-\|/g, ' - ').replace(/\|\|\|/g, '<br>')}</small></td>
                </tr>
            `).join('');
        } else {
            tableRows = filtered.map(i => `
                <tr>
                    <td><b style="color:#d32f2f;">${i.incident_type}</b><br><small style="color:#666;">${i.date_str}</small></td>
                    <td><b>${i.admin_remarks || i.spam_reason || 'Rejected'}</b></td>
                    <td style="text-align:center;"><b>${i.severity}</b></td>
                    <td><small style="color:#555;">${(i.all_logs || 'No logs recorded').replace(/\|-\|/g, ' - ').replace(/\|\|\|/g, '<br>')}</small></td>
                </tr>
            `).join('');
        }

        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>CDRRMO Analytics Report - ${sectorName}</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 25px; color: #111; }
                    .header-box { border-bottom: 2px solid #d32f2f; padding-bottom: 12px; margin-bottom: 18px; }
                    .header-box h2 { margin: 0; color: #d32f2f; font-size: 1.5rem; text-transform: uppercase; }
                    .meta-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; margin-bottom: 20px; font-size: 0.85rem; background: #f8f9fa; padding: 12px; border-radius: 8px; border: 1px solid #e9ecef; }
                    table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.85rem; }
                    th, td { border: 1px solid #dee2e6; padding: 10px; text-align: left; vertical-align: top; }
                    th { background: #f1f3f5; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }
                    @media print { body { padding: 0; } @page { margin: 15mm; } }
                </style>
            </head>
            <body>
                <div class="header-box">
                    <h2>City Disaster Risk Reduction and Management Office (CDRRMO)</h2>
                    <div style="font-weight: bold; margin-top: 4px; color: #555;">Sector Analytics Report — Dasmariñas City Command Center</div>
                </div>
                <div class="meta-grid">
                    <div><b>Jurisdiction / Sector:</b> Brgy. ${sectorName}</div>
                    <div><b>Report Scope:</b> ${scopeName}</div>
                    <div><b>Time Range Filter:</b> ${timeFilter.toUpperCase()}</div>
                    <div><b>Incident Type Filter:</b> ${typeFilter}</div>
                    <div><b>Total Matching Records:</b> ${filtered.length}</div>
                    <div><b>Generated On:</b> ${new Date().toLocaleString()}</div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Incident & Date</th>
                            <th>${currentTab === 'vault' ? 'Barangay' : 'Rejection Reason'}</th>
                            <th style="text-align:center;">Severity</th>
                            <th>History & Timeline Logs</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${tableRows}
                    </tbody>
                </table>
                <script>
                    window.onload = function() { window.print(); }
                </script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }
}

function openLogModal(btn) {
    const rawData = btn.getAttribute('data-logs') || '';
    const title = btn.getAttribute('data-type') || 'Incident';
    viewLogs(rawData, title);
}

function viewLogs(data, title) {
    const titleEl = document.getElementById('logTitle');
    if (titleEl) titleEl.innerHTML = `<i class='bx bx-list-ul'></i> ${title} Timeline`;
    
    const container = document.getElementById('logContainer');
    if (!container) return;
    container.innerHTML = '';
    
    if (!data || data.trim() === '') { 
        container.innerHTML = '<p style="text-align:center; padding:25px; opacity:0.6; font-weight:600;">No timeline logs recorded for this incident.</p>'; 
    } else {
        data.split('|||').reverse().forEach(line => {
            let p = line.split('|-|');
            if (p.length === 3) {
                container.innerHTML += `
                    <div style="background: rgba(25, 118, 210, 0.08); padding: 14px; border-radius: 12px; margin-bottom: 10px; border-left: 4px solid #1976d2;">
                        <div style="font-size: 0.8rem; font-weight: 800; color: #1976d2; margin-bottom: 4px;">${p[0]} • ${p[1]}</div>
                        <div style="font-weight: 600; font-size: 0.95rem; line-height: 1.4;">${p[2]}</div>
                    </div>`;
            }
        });
    }
    const modal = document.getElementById('viewLogsModal');
    if (modal) modal.style.display = 'flex';
}

function viewEvidence(imagePath, incidentType, brgy) { 
    if (!imagePath || imagePath === 'null' || imagePath === '' || imagePath === 'NULL') {
        document.getElementById('uniModalIcon').className = 'bx bx-image';
        document.getElementById('uniModalIcon').style.color = '#fbc02d';
        document.getElementById('uniModalTitle').innerText = "No Evidence";
        document.getElementById('uniModalText').innerText = "No photo evidence was uploaded for this report.";
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="document.getElementById('universalModal').style.display='none'" style="flex:1; padding:12px; background:#fbc02d; color:white; border:none; border-radius:10px; cursor:pointer; font-weight:bold;">OK</button>`;
        document.getElementById('universalModal').style.display = 'flex';
        return;
    }
    
    let cleanUrl = imagePath.trim();
    if (!cleanUrl.startsWith('http://') && !cleanUrl.startsWith('https://')) {
        cleanUrl = 'https://res.cloudinary.com/wyxsiraw/image/upload/' + cleanUrl.replace(/^\/?(dasma_api\/|dasma-api\/)?/, '');
    }
    
    document.getElementById('evidenceImageFull').src = cleanUrl; 
    document.getElementById('evidenceCaption').innerText = `Visual Evidence: ${incidentType} in Brgy. ${brgy}`; 
    document.getElementById('evidenceModal').style.display = 'flex'; 
}

function deleteArchived(id) {
    document.getElementById('uniModalIcon').className = 'bx bxs-trash';
    document.getElementById('uniModalIcon').style.color = '#d32f2f';
    document.getElementById('uniModalTitle').innerText = "Delete Record";
    document.getElementById('uniModalText').innerText = "Permanently remove this record from the system?";
    
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
    html += `<div class="mobile-detail-box"><small class="mobile-label">${currentTab === 'vault' ? 'Barangay' : 'Reason / Remarks'}</small>${cells[1].innerHTML}</div>`;
    html += `<div class="mobile-detail-box" style="text-align: center;"><small class="mobile-label">Severity</small><div style="margin-top:5px;">${cells[2].innerHTML}</div></div>`;
    html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; flex-wrap: wrap; gap:10px; width:100%; justify-content: center; padding-top: 8px;">
                ${cells[3].innerHTML}
            </div></div>`;

    bodyEl.innerHTML = html;
    document.getElementById('mobileDetailsModal').style.display = 'flex';
}

window.renderSeasonality = renderSeasonality;
window.switchAnalyticsTab = switchAnalyticsTab;
window.filterVaultData = filterVaultData;
window.exportFilteredData = exportFilteredData;
window.openLogModal = openLogModal;
window.viewEvidence = viewEvidence;
window.deleteArchived = deleteArchived;
window.openMobileModal = openMobileModal;
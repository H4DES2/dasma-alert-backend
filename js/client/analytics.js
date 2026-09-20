const allIncidents = window.allIncidents || [];
const rejectedIncidents = window.rejectedIncidents || [];
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

    populateTypeFilters();
    renderSeasonality();
});

function populateTypeFilters() {
    const vaultSelect = document.getElementById('vaultTypeFilter');
    const modalSelect = document.getElementById('modalReportType');
    const typesSet = new Set();

    allIncidents.forEach(i => { if (i.incident_type) typesSet.add(i.incident_type.trim()); });
    rejectedIncidents.forEach(i => { if (i.incident_type) typesSet.add(i.incident_type.trim()); });

    const buildOptions = () => {
        let html = '<option value="all">All Types</option>';
        typesSet.forEach(t => { html += `<option value="${t}">${t}</option>`; });
        return html;
    };

    if (vaultSelect) vaultSelect.innerHTML = buildOptions();
    if (modalSelect) modalSelect.innerHTML = buildOptions();
}

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
        const dateMap = {};
        for (let i = 29; i >= 0; i--) {
            const d = new Date();
            d.setDate(d.getDate() - i);
            const key = d.toISOString().split('T')[0];
            labels.push(d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
            dateMap[key] = 0;
        }
        dataset.forEach(inc => {
            const key = (inc.created_at || '').substring(0, 10);
            if (dateMap[key] !== undefined) dateMap[key]++;
        });
        dataPoints = Object.values(dateMap);
    } else if (filter === 'week') {
        const dateMap = {};
        for (let i = 6; i >= 0; i--) {
            const d = new Date();
            d.setDate(d.getDate() - i);
            const key = d.toISOString().split('T')[0];
            labels.push(d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' }));
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

    let gradient = ctx.createLinearGradient(0, 0, 0, 300);
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
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) { return ` ${ctx.parsed.y} Incident(s)`; }
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

function matchesFilters(inc, timeFilter, typeFilter) {
    if (typeFilter !== 'all' && (inc.incident_type || '').toLowerCase() !== typeFilter.toLowerCase()) {
        return false;
    }
    if (timeFilter === 'all') return true;

    const incDate = new Date(inc.created_at);
    const now = new Date();
    const diffDays = (now - incDate) / (1000 * 60 * 60 * 24);

    if (timeFilter === 'today') return incDate.toDateString() === now.toDateString();
    if (timeFilter === 'week') return diffDays <= 7;
    if (timeFilter === 'month') return diffDays <= 30;
    if (timeFilter === 'quarter') return diffDays <= 90;
    if (timeFilter === 'year') return diffDays <= 365;

    return true;
}

function filterVaultData() {
    const timeFilter = document.getElementById('vaultTimeFilter')?.value || 'all';
    const typeFilter = document.getElementById('vaultTypeFilter')?.value || 'all';
    const filtered = allIncidents.filter(i => matchesFilters(i, timeFilter, typeFilter));

    const tbody = document.getElementById('vaultTableBody');
    if (!tbody) return;

    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center; padding:35px; color:#888; font-weight:bold;">No historical records matching this query.</td></tr>';
        return;
    }

    tbody.innerHTML = filtered.map(inc => {
        const badge = (inc.severity || '').toLowerCase() === 'critical' ? 'critical' : ((inc.severity || '').toLowerCase() === 'minor' ? 'minor' : 'major');
        const safeImg = (inc.image_path || '').replace(/'/g, "\\'");
        const safeType = (inc.incident_type || '').replace(/'/g, "\\'");
        const safeBrgy = (inc.barangay || '').replace(/'/g, "\\'");
        const logsAttr = (inc.all_logs || '').replace(/"/g, '&quot;');
        const repLog = inc.initial_reporter_log ? '"' + inc.initial_reporter_log.substring(0, 50) + '..."' : 'No initial notes';
        const totalTime = (inc.duration_minutes !== null && inc.duration_minutes !== undefined) ? inc.duration_minutes + 'm' : 'N/A';

        return `<tr class="clickable-row" onclick="openMobileModal(this)">
            <td>
                <div class="incident-title-text">${inc.incident_type}</div>
                <div class="incident-date-text">${inc.date_str}</div>
                <div class="reporter-log-snippet">${repLog}</div>
            </td>
            <td>
                <div style="font-weight: 800; font-size: 0.95rem;">${inc.barangay}</div>
                <span class="badge ${badge}" style="margin-top: 5px;">${(inc.severity || 'PENDING').toUpperCase()}</span>
            </td>
            <td>
                <div class="timeline-meta">
                    <div><b>Reported:</b> <span>${inc.reported_time || 'Unknown'}</span></div>
                    <div><b>Arrived:</b> <span>${inc.arrived_time || 'Unknown'}</span></div>
                    <div><b>Resolved:</b> <span>${inc.resolved_time || 'Pending'}</span></div>
                    <div style="color: var(--color-critical, #d32f2f); font-weight: 800;"><b>Total Time:</b> <span>${totalTime}</span></div>
                </div>
            </td>
            <td style="text-align:center;">
                <div class="btn-action-group">
                    <button type="button" class="btn-table-icon bg-green" onclick="event.stopPropagation(); viewEvidence('${safeImg}', '${safeType}', '${safeBrgy}')"><i class='bx bx-image'></i></button>
                    <button type="button" class="btn-table-icon bg-blue" data-logs="${logsAttr}" data-type="${safeType}" onclick="event.stopPropagation(); openLogModal(this)"><i class='bx bx-list-ul'></i></button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

function openReportModal() {
    const m = document.getElementById('reportModal');
    if (m) m.style.display = 'flex';
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

function runReportExport(format) {
    const source = document.getElementById('modalReportSource')?.value || 'vault';
    const timeFilter = document.getElementById('modalReportTime')?.value || 'all';
    const typeFilter = document.getElementById('modalReportType')?.value || 'all';
    const groupCategories = document.getElementById('modalGroupCategories')?.checked ?? true;
    const sector = window.currentSector || 'Sector';

    let records = [];
    if (source === 'vault') {
        records = allIncidents.filter(i => matchesFilters(i, timeFilter, typeFilter));
    } else if (source === 'bin') {
        records = rejectedIncidents.filter(i => matchesFilters(i, timeFilter, typeFilter));
    } else {
        records = [
            ...allIncidents.filter(i => matchesFilters(i, timeFilter, typeFilter)),
            ...rejectedIncidents.filter(i => matchesFilters(i, timeFilter, typeFilter))
        ];
    }

    if (records.length === 0) {
        alert("No records match the requested report query.");
        return;
    }

    closeModal('reportModal');

    if (format === 'csv') {
        let csv = `\uFEFF"CDRRMO COMMAND CENTER - SECTOR ANALYTICS EXPORT"\n`;
        csv += `"Sector","${sector}"\n`;
        csv += `"Scope","${source.toUpperCase()}"\n`;
        csv += `"Time Query","${timeFilter.toUpperCase()}"\n`;
        csv += `"Type Query","${typeFilter.toUpperCase()}"\n`;
        csv += `"Total Records","${records.length}"\n\n`;

        csv += `"ID","Barangay","Incident Type","Severity","Date Reported","Turnaround / Notes","Coordinates"\n`;
        records.forEach(r => {
            const extra = r.admin_remarks || r.spam_reason || (r.duration_minutes !== undefined ? r.duration_minutes + ' min resolution' : 'N/A');
            csv += `"${r.id}","${r.barangay}","${r.incident_type}","${r.severity}","${r.date_str}","${extra.replace(/"/g, '""')}","${r.latitude}, ${r.longitude}"\n`;
        });

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `CDRRMO_${sector.replace(/\s+/g, '_')}_Analytics_${timeFilter}.csv`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    } else if (format === 'pdf') {
        const printWindow = window.open('', '_blank', 'width=950,height=750');
        if (!printWindow) return alert('Popup blocked. Allow popups to preview report.');

        let groupedHtml = '';
        if (groupCategories) {
            const groups = {};
            records.forEach(r => {
                const cat = r.incident_type || 'Uncategorized';
                if (!groups[cat]) groups[cat] = [];
                groups[cat].push(r);
            });

            for (const [catName, catItems] of Object.entries(groups)) {
                groupedHtml += `
                    <h3 style="margin-top: 25px; margin-bottom: 8px; color: #1976d2; border-bottom: 1.5px solid #e0e0e0; padding-bottom: 4px;">
                        ${catName} (${catItems.length} records)
                    </h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Incident Date</th>
                                <th>Barangay</th>
                                <th>Severity</th>
                                <th>Turnaround / Audit Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${catItems.map(i => `
                                <tr>
                                    <td><b>${i.date_str}</b></td>
                                    <td>${i.barangay}</td>
                                    <td><b>${i.severity}</b></td>
                                    <td>${i.admin_remarks || i.spam_reason || (i.duration_minutes !== undefined ? i.duration_minutes + ' min' : 'Resolved')}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            }
        } else {
            groupedHtml = `
                <table>
                    <thead>
                        <tr>
                            <th>Incident & Date</th>
                            <th>Barangay</th>
                            <th>Severity</th>
                            <th>Turnaround / Audit Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${records.map(i => `
                            <tr>
                                <td><b>${i.incident_type}</b><br><small>${i.date_str}</small></td>
                                <td>${i.barangay}</td>
                                <td><b>${i.severity}</b></td>
                                <td>${i.admin_remarks || i.spam_reason || (i.duration_minutes !== undefined ? i.duration_minutes + ' min' : 'Resolved')}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            `;
        }

        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>CDRRMO Report - Sector ${sector}</title>
                <style>
                    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 25px; color: #111; }
                    .header-box { border-bottom: 2.5px solid #d32f2f; padding-bottom: 10px; margin-bottom: 16px; }
                    .header-box h2 { margin: 0; color: #d32f2f; font-size: 1.4rem; text-transform: uppercase; }
                    .meta-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 6px; margin-bottom: 20px; font-size: 0.85rem; background: #f8f9fa; padding: 12px; border-radius: 8px; border: 1px solid #e9ecef; }
                    table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 0.82rem; }
                    th, td { border: 1px solid #dee2e6; padding: 8px 10px; text-align: left; vertical-align: top; }
                    th { background: #f1f3f5; text-transform: uppercase; font-size: 0.75rem; }
                    @media print { body { padding: 0; } @page { margin: 12mm; } }
                </style>
            </head>
            <body>
                <div class="header-box">
                    <h2>City Disaster Risk Reduction and Management Office (CDRRMO)</h2>
                    <div style="font-weight: bold; margin-top: 3px; color: #555;">Sector Analytics Report — Dasmariñas City Command Center</div>
                </div>
                <div class="meta-grid">
                    <div><b>Jurisdiction / Sector:</b> Brgy. ${sector}</div>
                    <div><b>Report Scope:</b> ${source.toUpperCase()}</div>
                    <div><b>Time Filter Query:</b> ${timeFilter.toUpperCase()}</div>
                    <div><b>Incident Type Query:</b> ${typeFilter}</div>
                    <div><b>Total Matching Records:</b> ${records.length}</div>
                    <div><b>Generated On:</b> ${new Date().toLocaleString()}</div>
                </div>
                ${groupedHtml}
                <script>window.onload = function() { window.print(); };</script>
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
        document.getElementById('uniModalButtons').innerHTML = `<button onclick="closeModal('universalModal')" style="flex:1; padding:12px; background:#fbc02d; color:white; border:none; border-radius:10px; cursor:pointer; font-weight:bold;">OK</button>`;
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
    document.getElementById('evidenceModal')?.addEventListener('click', function(e) {
    if (e.target.id === 'evidenceModal') {
        closeModal('evidenceModal');
    }
});
}

function deleteArchived(id) {
    document.getElementById('uniModalIcon').className = 'bx bxs-trash';
    document.getElementById('uniModalIcon').style.color = '#d32f2f';
    document.getElementById('uniModalTitle').innerText = "Delete Record";
    document.getElementById('uniModalText').innerText = "Permanently remove this record from the database?";
    
    document.getElementById('uniModalButtons').innerHTML = `
        <button onclick="closeModal('universalModal')" style="flex:1; padding:12px; border-radius:10px; cursor:pointer; border:1px solid #ccc; background:transparent; font-weight:800;">Cancel</button>
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
    html += `<div class="mobile-detail-box"><small class="mobile-label">Location & Status</small>${cells[1].innerHTML}</div>`;
    html += `<div class="mobile-detail-box"><small class="mobile-label">Timeline / Reason</small><div style="margin-top:5px;">${cells[2].innerHTML}</div></div>`;
    html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container" style="display:flex; flex-wrap: wrap; gap:10px; width:100%; justify-content: center; padding-top: 8px;">
                ${cells[3].innerHTML}
            </div></div>`;

    bodyEl.innerHTML = html;
    document.getElementById('mobileDetailsModal').style.display = 'flex';
}

window.renderSeasonality = renderSeasonality;
window.filterVaultData = filterVaultData;
window.openReportModal = openReportModal;
window.runReportExport = runReportExport;
window.openLogModal = openLogModal;
window.viewEvidence = viewEvidence;
window.deleteArchived = deleteArchived;
window.openMobileModal = openMobileModal;
window.closeModal = closeModal;
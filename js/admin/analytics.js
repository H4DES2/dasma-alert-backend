const allIncidents = window.allIncidents || [];
const binIncidents = window.binIncidents || [];
const allSeasonDates = window.allSeasonDates || [];
let lineChartInstance = null;

// -----------------------------------------------------
// 1. INITIALIZE CHARTS
// -----------------------------------------------------
document.addEventListener("DOMContentLoaded", function() {
    if (typeof ChartDataLabels !== 'undefined') {
        Chart.register(ChartDataLabels);
    }
    
    const isDarkMode = document.documentElement.classList.contains('global-dark-mode');
    const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
    const textColor = isDarkMode ? '#8b949e' : '#888';

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
                            dataArr.forEach(data => { total += parseInt(data, 10); });
                            let percentage = Math.round((value / total) * 100);
                            return percentage >= 4 ? percentage + '%' : '';
                        }
                    }
                }
            }
        });
    }

    renderSeasonality();

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
// 2. DYNAMIC SEASONALITY FUNCTION
// -----------------------------------------------------
function renderSeasonality() {
    const canvasElement = document.getElementById('seasonalityLineChart');
    if (!canvasElement) return;

    const filterEl = document.getElementById('seasonalityFilter');
    const filter = filterEl ? filterEl.value : 'all';
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
// 3. REPORT BUILDER: TIMEFRAME & INCIDENT TYPE LOGIC
// -----------------------------------------------------
function getTodayBounds() {
    const now = new Date();
    const yyyy = now.getFullYear();
    const mm = String(now.getMonth() + 1).padStart(2, '0');
    const dd = String(now.getDate()).padStart(2, '0');
    return {
        now,
        year: yyyy,
        month: now.getMonth() + 1,
        dateStr: `${yyyy}-${mm}-${dd}`,
        monthStr: `${yyyy}-${mm}`
    };
}

function openReportModal() {
    const b = getTodayBounds();

    const dayInput = document.getElementById('rep_day');
    const weekStart = document.getElementById('rep_week_start');
    const weekEnd = document.getElementById('rep_week_end');
    const monthInput = document.getElementById('rep_month');

    // Future-date locks
    if (dayInput) { dayInput.max = b.dateStr; dayInput.value = b.dateStr; }
    if (weekStart) { weekStart.max = b.dateStr; }
    if (weekEnd) { weekEnd.max = b.dateStr; weekEnd.value = b.dateStr; }
    if (monthInput) { monthInput.max = b.monthStr; monthInput.value = b.monthStr; }

    const priorDate = new Date();
    priorDate.setDate(priorDate.getDate() - 6);
    const priorStr = priorDate.toISOString().split('T')[0];
    if (weekStart) weekStart.value = priorStr;

    const yearSelect = document.getElementById('rep_year');
    const qYearSelect = document.getElementById('rep_quarter_year');
    [yearSelect, qYearSelect].forEach(sel => {
        if (!sel) return;
        sel.innerHTML = '';
        for (let y = b.year; y >= 2024; y--) {
            const opt = document.createElement('option');
            opt.value = y;
            opt.textContent = y;
            sel.appendChild(opt);
        }
    });

    // Populate Incident & Accident Types Dropdown
    const typeSelect = document.getElementById('rep_incident_type');
    if (typeSelect) {
        const typesSet = new Set();
        allIncidents.forEach(i => { if (i.incident_type) typesSet.add(i.incident_type.trim()); });
        binIncidents.forEach(b => { if (b.incident_type) typesSet.add(b.incident_type.trim()); });

        const categoriesSet = new Set();
        typesSet.forEach(t => {
            const mainCat = t.split('-')[0].trim();
            if (mainCat) categoriesSet.add(mainCat);
        });

        // Ensure primary accident/emergency categories are present if matched
        ['Accident', 'Vehicular Accident', 'Fire', 'Medical', 'Rescue', 'Crime'].forEach(cat => {
            let hasMatch = false;
            typesSet.forEach(t => {
                if (t.toLowerCase().includes(cat.toLowerCase())) hasMatch = true;
            });
            if (hasMatch) categoriesSet.add(cat);
        });

        let html = '<option value="all">All Incident & Accident Types</option>';
        if (categoriesSet.size > 0) {
            html += '<optgroup label="Broad Incident Categories">';
            Array.from(categoriesSet).sort().forEach(cat => {
                html += `<option value="cat:${cat}">All ${cat} Incidents</option>`;
            });
            html += '</optgroup>';
        }
        if (typesSet.size > 0) {
            html += '<optgroup label="Specific Subtypes">';
            Array.from(typesSet).sort().forEach(t => {
                html += `<option value="${t}">${t}</option>`;
            });
            html += '</optgroup>';
        }
        typeSelect.innerHTML = html;
    }

    updateQuarterOptions();
    handlePeriodChange();

    const modal = document.getElementById('customReportModal');
    if (modal) modal.style.display = 'flex';
}

function handlePeriodChange() {
    const period = document.getElementById('rep_period')?.value || 'day';
    ['day', 'weekly', 'monthly', 'quarterly', 'yearly'].forEach(p => {
        const el = document.getElementById(`box_${p}`);
        if (el) el.style.display = (p === period) ? 'block' : 'none';
    });
}

function validateWeekRange() {
    const b = getTodayBounds();
    const sInput = document.getElementById('rep_week_start');
    const eInput = document.getElementById('rep_week_end');
    const msg = document.getElementById('week_validation_msg');
    if (!sInput || !eInput || !msg) return false;

    msg.innerText = '';
    if (sInput.value > b.dateStr) sInput.value = b.dateStr;
    if (eInput.value > b.dateStr) eInput.value = b.dateStr;

    const start = new Date(sInput.value);
    const end = new Date(eInput.value);

    if (start > end) {
        msg.innerText = 'Start date cannot be after end date.';
        return false;
    }

    const diffDays = Math.ceil((end - start) / (1000 * 60 * 60 * 24)) + 1;
    if (diffDays > 7) {
        msg.innerText = `Range is ${diffDays} days. Please limit weekly reporting to 7 days maximum.`;
        return false;
    }
    return true;
}

function updateQuarterOptions() {
    const b = getTodayBounds();
    const qYear = parseInt(document.getElementById('rep_quarter_year')?.value || b.year, 10);
    const qSelect = document.getElementById('rep_quarter_q');
    if (!qSelect) return;

    qSelect.innerHTML = '';
    const currentQ = Math.ceil(b.month / 3);

    const quarters = [
        { q: 1, label: 'Q1 (January – March)' },
        { q: 2, label: 'Q2 (April – June)' },
        { q: 3, label: 'Q3 (July – September)' },
        { q: 4, label: 'Q4 (October – December)' }
    ];

    quarters.forEach(item => {
        if (qYear === b.year && item.q > currentQ) return;
        const opt = document.createElement('option');
        opt.value = item.q;
        opt.textContent = item.label;
        qSelect.appendChild(opt);
    });
}

function getDateFilterRange() {
    const b = getTodayBounds();
    const period = document.getElementById('rep_period')?.value || 'day';
    let start = new Date(0);
    let end = new Date();
    let label = '';

    if (period === 'day') {
        const val = document.getElementById('rep_day')?.value || b.dateStr;
        start = new Date(`${val}T00:00:00`);
        end = new Date(`${val}T23:59:59`);
        label = `Day of ${val}`;
    } else if (period === 'weekly') {
        if (!validateWeekRange()) return null;
        const sVal = document.getElementById('rep_week_start')?.value;
        const eVal = document.getElementById('rep_week_end')?.value;
        start = new Date(`${sVal}T00:00:00`);
        end = new Date(`${eVal}T23:59:59`);
        label = `Weekly: ${sVal} to ${eVal}`;
    } else if (period === 'monthly') {
        const mVal = document.getElementById('rep_month')?.value || b.monthStr;
        const [y, m] = mVal.split('-').map(Number);
        start = new Date(y, m - 1, 1, 0, 0, 0);
        end = new Date(y, m, 0, 23, 59, 59);
        label = `Month of ${mVal}`;
    } else if (period === 'quarterly') {
        const y = parseInt(document.getElementById('rep_quarter_year')?.value || b.year, 10);
        const q = parseInt(document.getElementById('rep_quarter_q')?.value || 1, 10);
        const startMonth = (q - 1) * 3;
        start = new Date(y, startMonth, 1, 0, 0, 0);
        end = new Date(y, startMonth + 3, 0, 23, 59, 59);
        label = `${y} Q${q} Report`;
    } else if (period === 'yearly') {
        const y = parseInt(document.getElementById('rep_year')?.value || b.year, 10);
        start = new Date(y, 0, 1, 0, 0, 0);
        end = new Date(y, 11, 31, 23, 59, 59);
        label = `Year ${y} Annual Report`;
    }

    if (end > b.now) end = b.now;

    return { start, end, label };
}

function matchIncidentType(itemType, filterVal) {
    if (!filterVal || filterVal === 'all') return true;
    if (!itemType) return false;
    const iType = itemType.toLowerCase().trim();

    if (filterVal.startsWith('cat:')) {
        const cat = filterVal.replace('cat:', '').toLowerCase().trim();
        if (cat === 'accident' || cat === 'vehicular accident') {
            return iType.includes('accident') || iType.includes('crash') || iType.includes('collision') || iType.includes('vehicular');
        }
        return iType.startsWith(cat) || iType.includes(cat);
    }

    return iType === filterVal.toLowerCase().trim();
}

function processReportGeneration() {
    const range = getDateFilterRange();
    if (!range) return;

    const format = document.querySelector('input[name="rep_format"]:checked')?.value || 'pdf';
    const incVault = document.getElementById('inc_vault')?.checked ?? true;
    const incBin = document.getElementById('inc_bin')?.checked ?? true;
    const groupByType = document.getElementById('inc_group_type')?.checked ?? true;
    const selectedType = document.getElementById('rep_incident_type')?.value || 'all';

    const typeSelectEl = document.getElementById('rep_incident_type');
    const selectedTypeLabel = typeSelectEl && typeSelectEl.selectedIndex >= 0 
        ? typeSelectEl.options[typeSelectEl.selectedIndex].text 
        : 'All Incident Types';

    const filteredVault = incVault ? allIncidents.filter(i => {
        const d = new Date(i.created_at.replace(' ', 'T'));
        return d >= range.start && d <= range.end && matchIncidentType(i.incident_type, selectedType);
    }) : [];

    const filteredBin = incBin ? binIncidents.filter(b => {
        const d = new Date(b.created_at.replace(' ', 'T'));
        return d >= range.start && d <= range.end && matchIncidentType(b.incident_type, selectedType);
    }) : [];

    if (filteredVault.length === 0 && filteredBin.length === 0) {
        alert(`No records found for timeframe: ${range.label} with filter "${selectedTypeLabel}".`);
        return;
    }

    closeModal('customReportModal');

    if (format === 'csv') {
        generateCustomCSV(filteredVault, filteredBin, range.label, selectedTypeLabel, groupByType);
    } else {
        generateCustomPDF(filteredVault, filteredBin, range.label, selectedTypeLabel, groupByType);
    }
}

function generateCustomPDF(vaultRows, binRows, rangeLabel, selectedTypeLabel, groupByType) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('landscape');

    doc.setFontSize(18);
    doc.setTextColor(25, 118, 210);
    doc.text('CDRRMO Dasmariñas Incident Report', 14, 15);

    doc.setFontSize(10);
    doc.setTextColor(100, 100, 100);
    doc.text(`Timeframe: ${rangeLabel}  |  Filter: ${selectedTypeLabel}  |  Generated: ${new Date().toLocaleString()}`, 14, 22);

    let startY = 30;

    // 1. INCIDENT ARCHIVE VAULT
    if (vaultRows.length > 0) {
        doc.setFontSize(14);
        doc.setTextColor(33, 33, 33);
        doc.text(`Incident Archive Vault (${vaultRows.length} Total Records)`, 14, startY);
        startY += 6;

        if (groupByType) {
            const vaultByType = {};
            vaultRows.forEach(i => {
                const t = i.incident_type || 'Uncategorized';
                if (!vaultByType[t]) vaultByType[t] = [];
                vaultByType[t].push(i);
            });

            Object.keys(vaultByType).sort().forEach(typeKey => {
                const rows = vaultByType[typeKey];
                if (startY > 165) { doc.addPage(); startY = 20; }

                doc.setFontSize(10);
                doc.setTextColor(25, 118, 210);
                doc.text(`• ${typeKey} (${rows.length} ${rows.length === 1 ? 'Record' : 'Records'})`, 14, startY);

                const vData = rows.map(i => [
                    i.id,
                    i.severity,
                    i.barangay,
                    i.created_at,
                    i.resolved_at || 'N/A',
                    i.initial_log || 'No details'
                ]);

                doc.autoTable({
                    startY: startY + 3,
                    head: [['ID', 'Severity', 'Barangay', 'Reported', 'Resolved', 'Citizen Description']],
                    body: vData,
                    theme: 'grid',
                    headStyles: { fillColor: [25, 118, 210] },
                    styles: { fontSize: 8, cellPadding: 2.5, overflow: 'linebreak' }
                });

                startY = doc.lastAutoTable.finalY + 8;
            });
        } else {
            const vData = vaultRows.map(i => [
                i.id,
                i.incident_type,
                i.severity,
                i.barangay,
                i.created_at,
                i.resolved_at || 'N/A',
                i.initial_log || 'No details'
            ]);

            doc.autoTable({
                startY: startY + 2,
                head: [['ID', 'Type', 'Severity', 'Barangay', 'Reported', 'Resolved', 'Citizen Description']],
                body: vData,
                theme: 'grid',
                headStyles: { fillColor: [25, 118, 210] },
                styles: { fontSize: 8, cellPadding: 2.5, overflow: 'linebreak' }
            });

            startY = doc.lastAutoTable.finalY + 12;
        }
    }

    // 2. REPORT BIN & REJECTION AUDIT
    if (binRows.length > 0) {
        if (startY > 155) { doc.addPage(); startY = 20; }

        doc.setFontSize(14);
        doc.setTextColor(211, 47, 47);
        doc.text(`Report Bin & Rejection Audit (${binRows.length} Total Records)`, 14, startY);
        startY += 6;

        if (groupByType) {
            const binByType = {};
            binRows.forEach(b => {
                const t = b.incident_type || 'Uncategorized';
                if (!binByType[t]) binByType[t] = [];
                binByType[t].push(b);
            });

            Object.keys(binByType).sort().forEach(typeKey => {
                const rows = binByType[typeKey];
                if (startY > 165) { doc.addPage(); startY = 20; }

                doc.setFontSize(10);
                doc.setTextColor(211, 47, 47);
                doc.text(`• ${typeKey} — Rejections (${rows.length} ${rows.length === 1 ? 'Record' : 'Records'})`, 14, startY);

                const bData = rows.map(b => [
                    b.id,
                    b.barangay,
                    b.parsed_category || 'False Alarm',
                    b.parsed_officer || 'Officer',
                    b.parsed_notes || b.spam_reason || 'N/A'
                ]);

                doc.autoTable({
                    startY: startY + 3,
                    head: [['ID', 'Barangay', 'Rejection Category', 'Audited By', 'Official Explanation']],
                    body: bData,
                    theme: 'grid',
                    headStyles: { fillColor: [211, 47, 47] },
                    styles: { fontSize: 8, cellPadding: 2.5, overflow: 'linebreak' }
                });

                startY = doc.lastAutoTable.finalY + 8;
            });
        } else {
            const bData = binRows.map(b => [
                b.id,
                b.incident_type,
                b.barangay,
                b.parsed_category || 'False Alarm',
                b.parsed_officer || 'Officer',
                b.parsed_notes || b.spam_reason || 'N/A'
            ]);

            doc.autoTable({
                startY: startY + 2,
                head: [['ID', 'Type', 'Barangay', 'Rejection Category', 'Audited By', 'Official Explanation']],
                body: bData,
                theme: 'grid',
                headStyles: { fillColor: [211, 47, 47] },
                styles: { fontSize: 8, cellPadding: 2.5, overflow: 'linebreak' }
            });
        }
    }

    doc.save(`Dasma_Alert_Report_${rangeLabel.replace(/[^a-z0-9]/gi, '_')}.pdf`);
}

function generateCustomCSV(vaultRows, binRows, rangeLabel, selectedTypeLabel, groupByType) {
    let csv = `\uFEFF--- DASMARINAS CITY CDRRMO AUDIT REPORT ---\n`;
    csv += `TIMEFRAME: ${rangeLabel}\n`;
    csv += `FILTER: ${selectedTypeLabel}\n`;
    csv += `GROUPED BY INCIDENT TYPE: ${groupByType ? 'YES' : 'NO'}\n`;
    csv += `GENERATED: ${new Date().toLocaleString()}\n\n`;

    if (vaultRows.length > 0) {
        csv += `--- INCIDENT ARCHIVE VAULT (${vaultRows.length} Total Records) ---\n`;
        if (groupByType) {
            const vaultByType = {};
            vaultRows.forEach(i => {
                const t = i.incident_type || 'Uncategorized';
                if (!vaultByType[t]) vaultByType[t] = [];
                vaultByType[t].push(i);
            });

            Object.keys(vaultByType).sort().forEach(typeKey => {
                csv += `\n[TYPE: ${typeKey.toUpperCase()}]\n`;
                csv += `ID,Severity,Barangay,Reported Date,Resolved Date,Citizen Description\n`;
                vaultByType[typeKey].forEach(i => {
                    csv += [
                        i.id,
                        i.severity,
                        `"${i.barangay}"`,
                        `"${i.created_at}"`,
                        `"${i.resolved_at || 'N/A'}"`,
                        `"${(i.initial_log || '').replace(/"/g, '""')}"`
                    ].join(',') + '\n';
                });
            });
        } else {
            csv += `ID,Type,Severity,Barangay,Reported Date,Resolved Date,Citizen Description\n`;
            vaultRows.forEach(i => {
                csv += [
                    i.id,
                    `"${i.incident_type}"`,
                    i.severity,
                    `"${i.barangay}"`,
                    `"${i.created_at}"`,
                    `"${i.resolved_at || 'N/A'}"`,
                    `"${(i.initial_log || '').replace(/"/g, '""')}"`
                ].join(',') + '\n';
            });
        }
        csv += '\n';
    }

    if (binRows.length > 0) {
        csv += `--- REPORT BIN REJECTION AUDIT (${binRows.length} Total Records) ---\n`;
        if (groupByType) {
            const binByType = {};
            binRows.forEach(b => {
                const t = b.incident_type || 'Uncategorized';
                if (!binByType[t]) binByType[t] = [];
                binByType[t].push(b);
            });

            Object.keys(binByType).sort().forEach(typeKey => {
                csv += `\n[REJECTED TYPE: ${typeKey.toUpperCase()}]\n`;
                csv += `ID,Barangay,Reported Date,Citizen Description,Reviewing Officer,Rejection Category,Official Explanation\n`;
                binByType[typeKey].forEach(b => {
                    csv += [
                        b.id,
                        `"${b.barangay}"`,
                        `"${b.created_at}"`,
                        `"${(b.initial_log || 'N/A').replace(/"/g, '""')}"`,
                        `"${(b.parsed_officer || 'Officer').replace(/"/g, '""')}"`,
                        `"${(b.parsed_category || 'False Alarm').replace(/"/g, '""')}"`,
                        `"${(b.parsed_notes || b.spam_reason || '').replace(/"/g, '""')}"`
                    ].join(',') + '\n';
                });
            });
        } else {
            csv += `ID,Type,Barangay,Reported Date,Citizen Description,Reviewing Officer,Rejection Category,Official Explanation\n`;
            binRows.forEach(b => {
                csv += [
                    b.id,
                    `"${b.incident_type}"`,
                    `"${b.barangay}"`,
                    `"${b.created_at}"`,
                    `"${(b.initial_log || 'N/A').replace(/"/g, '""')}"`,
                    `"${(b.parsed_officer || 'Officer').replace(/"/g, '""')}"`,
                    `"${(b.parsed_category || 'False Alarm').replace(/"/g, '""')}"`,
                    `"${(b.parsed_notes || b.spam_reason || '').replace(/"/g, '""')}"`
                ].join(',') + '\n';
            });
        }
    }

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `Dasma_Alert_Report_${rangeLabel.replace(/[^a-z0-9]/gi, '_')}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function backupAllReports() {
    if (!confirm('Download a complete offline report backup snapshot (Archive Vault + Report Bin + Audit Logs)?')) {
        return;
    }

    const payload = {
        system: "Dasma Alert Emergency Command",
        backup_type: "Full Reports Cold Storage Snapshot",
        generated_at: new Date().toISOString(),
        counts: {
            vault_incidents: allIncidents.length,
            bin_incidents: binIncidents.length,
            total_records: allIncidents.length + binIncidents.length
        },
        archive_vault: allIncidents,
        report_bin_audit: binIncidents
    };

    const jsonStr = JSON.stringify(payload, null, 2);
    const blob = new Blob([jsonStr], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `Dasma_Alert_Reports_Backup_${new Date().toISOString().split('T')[0]}.json`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

// -----------------------------------------------------
// 6. GENERAL CONTROLS & MODALS
// -----------------------------------------------------
function applyFilters() { 
    let typeVal = document.getElementById('typeFilter')?.value || 'all';
    let timeVal = document.getElementById('timeFilter')?.value || 'all';
    let vaultTimeVal = document.getElementById('vaultTimeFilter')?.value || 'all';
    window.location.href = `analytics.php?type=${encodeURIComponent(typeVal)}&time=${encodeURIComponent(timeVal)}&vault_time=${encodeURIComponent(vaultTimeVal)}`; 
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) {
        el.style.display = 'none';
        if (id === 'viewPhotoModal') {
            const img = document.getElementById('evidencePhotoViewer');
            if (img) img.src = '';
        }
    }
}

function viewLogs(logsString, incidentTitle) {
    const titleEl = document.getElementById('logIncidentTitle');
    const container = document.getElementById('logContainer');
    if (titleEl) titleEl.innerText = `${incidentTitle} Timeline`;
    if (!container) return;

    container.innerHTML = '';

    if (!logsString || logsString === 'No logs recorded.' || logsString.trim() === '') {
        container.innerHTML = '<div style="text-align: center; color: #888; padding: 20px;">No timeline logs recorded.</div>';
    } else {
        const entries = logsString.split('|||').map(e => e.trim()).filter(Boolean);
        let html = '<div style="display: flex; flex-direction: column; gap: 12px; padding: 10px 0;">';
        entries.forEach(entry => {
            const parts = entry.split('|-|');
            const time = parts[0] || '';
            const user = parts[1] || 'System';
            const msg = parts[2] || '';
            html += `
                <div class="timeline-log-card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span class="timeline-log-meta">${time} - ${user}</span>
                    </div>
                    <div class="timeline-log-msg">${msg}</div>
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
        container.scrollTop = 0;
    }

    const modal = document.getElementById('viewLogsModal');
    if (modal) modal.style.display = 'flex';
}

function viewPhoto(url) {
    if (!url || url === 'null' || url === 'NULL') return;
    let cleanUrl = url.trim();
    if (cleanUrl.startsWith('/http')) cleanUrl = cleanUrl.substring(1);
    if (!cleanUrl.startsWith('http://') && !cleanUrl.startsWith('https://')) {
        const path = cleanUrl.replace(/^\/?(dasma_api\/|dasma-api\/)?/, '');
        cleanUrl = 'https://res.cloudinary.com/wyxsiraw/image/upload/' + path;
    }

    const imgViewer = document.getElementById('evidencePhotoViewer');
    const modal = document.getElementById('viewPhotoModal');
    if (imgViewer && modal) {
        imgViewer.src = cleanUrl;
        modal.style.display = 'flex';
    }
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
// 7. MOBILE MODAL LOGIC
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
        titleEl.innerHTML = "Granular Rejection Audit";
        html += `<div class="mobile-detail-box"><small class="mobile-label">Incident & Citizen Input</small>${cells[0].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Location & Status</small>${cells[1].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Audit & Explanation</small>${cells[2].innerHTML}</div>`;
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

// Window bindings
window.openReportModal = openReportModal;
window.handlePeriodChange = handlePeriodChange;
window.validateWeekRange = validateWeekRange;
window.updateQuarterOptions = updateQuarterOptions;
window.processReportGeneration = processReportGeneration;
window.backupAllReports = backupAllReports;
window.viewPhoto = viewPhoto;
window.closeModal = closeModal;
window.viewLogs = viewLogs;
window.openMobileModal = openMobileModal;
window.renderSeasonality = renderSeasonality;
window.applyFilters = applyFilters;
window.stopBroadcast = stopBroadcast;
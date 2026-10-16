const allIncidents = window.allIncidents || [];
const binIncidents = window.binIncidents || [];
const allSeasonDates = window.allSeasonDates || [];

let lineChartInstance = null;
let typeChartInstance = null;
let sentimentChartInstance = null;
let evacChartInstance = null;
let heatmapMapInstance = null;

// -----------------------------------------------------
// 1. COLLAPSIBLE ACCORDION CONTROLS (AUTO-RESIZE MAPS & CHARTS)
// -----------------------------------------------------
function toggleTableCollapse(contentId, btnEl) {
    const content = document.getElementById(contentId);
    if (!content) return;

    const isCurrentlyHidden = window.getComputedStyle(content).display === 'none';
    
    if (isCurrentlyHidden) {
        content.style.display = 'block';
        content.classList.add('show');
        if (btnEl) {
            btnEl.classList.add('active');
            const textSpan = btnEl.querySelector('.toggle-text');
            const icon = btnEl.querySelector('i');
            if (textSpan) textSpan.textContent = 'Click to hide data';
            if (icon) icon.className = 'bx bx-chevron-up';
        }

        // Auto-initialize or resize charts/maps when uncollapsed
        setTimeout(() => {
            if (contentId === 'spatialHotspotsBody') {
                if (!heatmapMapInstance) initHeatmap();
                else heatmapMapInstance.invalidateSize();
            } else if (contentId === 'seasonalityBody') {
                if (!lineChartInstance) renderSeasonality();
                else lineChartInstance.resize();
            } else if (contentId === 'incidentDistributionBody') {
                if (!typeChartInstance) initTypeChart();
                else typeChartInstance.resize();
            } else if (contentId === 'sentimentAuditBody') {
                if (!sentimentChartInstance) initSentimentChart();
                else sentimentChartInstance.resize();
            } else if (contentId === 'evacCapacityBody') {
                if (!evacChartInstance) initEvacChart();
                else evacChartInstance.resize();
            }
        }, 80);
    } else {
        content.style.display = 'none';
        content.classList.remove('show');
        if (btnEl) {
            btnEl.classList.remove('active');
            const textSpan = btnEl.querySelector('.toggle-text');
            const icon = btnEl.querySelector('i');
            if (textSpan) textSpan.textContent = 'Click to show data';
            if (icon) icon.className = 'bx bx-chevron-down';
        }
    }
}

function handleHeaderToggle(event, contentId, btnId) {
    if (event.target.closest('select, input, button, a')) {
        return;
    }
    const btn = document.getElementById(btnId);
    toggleTableCollapse(contentId, btn);
}

// -----------------------------------------------------
// 2. CHART INITIALIZERS
// -----------------------------------------------------
function initTypeChart() {
    const typeCanvas = document.getElementById('typePieChart');
    if (!typeCanvas || !(window.typeData || []).length) return;
    if (typeChartInstance) {
        typeChartInstance.resize();
        return;
    }

    const labels = window.typeLabels || [];
    const dataValues = window.typeData || [];
    const bgColors = window.typeColors || [];

    const scrollContainer = typeCanvas.parentElement;
    if (scrollContainer) {
        const calculatedHeight = Math.max(280, labels.length * 30 + 40);
        scrollContainer.style.height = `${calculatedHeight}px`;
    }

    const isDarkMode = document.documentElement.classList.contains('global-dark-mode');
    const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)';
    const textColor = isDarkMode ? '#94a3b8' : '#64748b';

    typeChartInstance = new Chart(typeCanvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Total Incidents',
                data: dataValues,
                backgroundColor: bgColors,
                borderRadius: 4,
                borderSkipped: false,
                barThickness: 16
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                datalabels: {
                    anchor: 'end',
                    align: 'end',
                    color: textColor,
                    font: { weight: 'bold', size: 11 },
                    formatter: (value) => value
                },
                tooltip: {
                    callbacks: {
                        label: (ctx) => ` ${ctx.raw} incident${ctx.raw === 1 ? '' : 's'}`
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    grid: { color: gridColor },
                    ticks: { color: textColor, precision: 0 }
                },
                y: {
                    grid: { display: false },
                    ticks: { color: textColor, font: { size: 10, weight: '600' } }
                }
            }
        }
    });
}

function initSentimentChart() {
    const sentimentCanvas = document.getElementById('sentimentPieChart');
    const sentimentValues = window.sentimentData || [];
    const totalSentiment = sentimentValues.reduce((a, b) => a + parseInt(b, 10), 0);
    if (!sentimentCanvas || totalSentiment <= 0) return;
    if (sentimentChartInstance) {
        sentimentChartInstance.resize();
        return;
    }

    const isDarkMode = document.documentElement.classList.contains('global-dark-mode');
    const textColor = isDarkMode ? '#94a3b8' : '#64748b';

    sentimentChartInstance = new Chart(sentimentCanvas.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: window.sentimentLabels || [],
            datasets: [{
                data: sentimentValues,
                backgroundColor: ['#dc2626', '#ea580c', '#0284c7', '#16a34a'],
                borderWidth: 2,
                borderColor: isDarkMode ? '#1e293b' : '#ffffff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: { 
                    position: 'bottom', 
                    labels: { padding: 10, font: { weight: '600', size: 11 }, color: textColor } 
                },
                datalabels: {
                    color: '#ffffff',
                    font: { weight: 'bold', size: 11 },
                    formatter: (val) => val > 0 ? val : ''
                }
            }
        }
    });
}

function initEvacChart() {
    const evacCanvas = document.getElementById('evacOverflowChart');
    if (!evacCanvas || !(window.evacLabels || []).length) return;
    if (evacChartInstance) {
        evacChartInstance.resize();
        return;
    }

    const isDarkMode = document.documentElement.classList.contains('global-dark-mode');
    const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)';
    const textColor = isDarkMode ? '#94a3b8' : '#64748b';

    evacChartInstance = new Chart(evacCanvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: window.evacLabels || [],
            datasets: [
                { label: 'Current Occupants', data: window.evacOccupants || [], backgroundColor: '#ea580c', borderRadius: 4 },
                { label: 'Total Capacity', data: window.evacCapacity || [], backgroundColor: '#0284c7', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { datalabels: { display: false }, legend: { labels: { color: textColor, font: { weight: '600' } } } },
            scales: {
                y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: textColor, precision: 0 } },
                x: { grid: { display: false }, ticks: { color: textColor, font: { size: 10 } } }
            }
        }
    });
}

function initHeatmap() {
    const heatmapEl = document.getElementById('heatmap');
    if (!heatmapEl || typeof L === 'undefined') return;
    if (heatmapMapInstance) {
        heatmapMapInstance.invalidateSize();
        return;
    }

    const dasmaBounds = L.latLngBounds([14.2400, 120.9000], [14.3800, 121.0200]);
    heatmapMapInstance = L.map('heatmap', { center: [14.3294, 120.9368], zoom: 13, minZoom: 12, maxBounds: dasmaBounds });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(heatmapMapInstance);
    
    const heatData = window.heatData || [];
    if (heatData.length > 0 && typeof L.heatLayer === 'function') {
        L.heatLayer(heatData, { radius: 25, blur: 15, maxZoom: 15 }).addTo(heatmapMapInstance);
    }
}

// -----------------------------------------------------
// 3. LIFECYCLE ATTACHMENT
// -----------------------------------------------------
document.addEventListener("DOMContentLoaded", function() {
    if (typeof ChartDataLabels !== 'undefined') {
        Chart.register(ChartDataLabels);
    }

    // Initialize charts/maps (will size when their accordion is clicked)
    initTypeChart();
    initSentimentChart();
    renderSeasonality();
    initEvacChart();
    initHeatmap();

    // Load AI predictions
    loadAiForecast();
});

// -----------------------------------------------------
// 4. AI PREDICTION WITH LOCALSTORAGE CACHING
// -----------------------------------------------------
const AI_CACHE_KEY = 'dasma_gemini_predictive_cache';
const AI_CACHE_TTL = 6 * 60 * 60 * 1000;

async function loadAiForecast(forceRefresh = false) {
    const tableBody = document.getElementById('aiForecastTableBody');
    const summaryBanner = document.getElementById('aiSummaryBanner');
    if (!tableBody) return;

    if (!forceRefresh) {
        try {
            const rawCache = localStorage.getItem(AI_CACHE_KEY);
            if (rawCache) {
                const cached = JSON.parse(rawCache);
                const isFresh = (Date.now() - cached.timestamp) < AI_CACHE_TTL;
                if (isFresh && cached.data && cached.data.success && cached.data.prediction) {
                    renderAiForecastUI(cached.data);
                    return;
                }
            }
        } catch (e) {
            console.warn('AI Cache read failed:', e);
        }
    }

    tableBody.innerHTML = `
        <tr>
            <td colspan="5" class="table-empty-row text-primary font-weight-bold">
                <i class='bx bx-loader-alt bx-spin' style="font-size: 1.6rem; display: block; margin-bottom: 8px;"></i>
                Synthesizing historical patterns & spatial clusters
            </td>
        </tr>
    `;

    try {
        const response = await fetch(`../php/ai_predictor.php`);
        if (!response.ok) {
            throw new Error(`Server returned HTTP ${response.status}`);
        }

        const result = await response.json();
        if (!result.success || !result.prediction || !result.prediction.forecasts) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="5" class="table-empty-row text-danger font-weight-bold">
                        ${result.message || result.error || 'Unable to generate forecast for the current cycle.'}
                    </td>
                </tr>
            `;
            if (summaryBanner) summaryBanner.style.display = 'none';
            return;
        }

        try {
            localStorage.setItem(AI_CACHE_KEY, JSON.stringify({
                timestamp: Date.now(),
                data: result
            }));
        } catch (e) {
            console.warn('AI Cache write failed:', e);
        }

        renderAiForecastUI(result);

    } catch (err) {
        console.error('Forecast error:', err);
        tableBody.innerHTML = `
            <tr>
                <td colspan="5" class="table-empty-row text-danger font-weight-bold">
                    Connection to AI Predictor failed: ${err.message}.
                </td>
            </tr>
        `;
        if (summaryBanner) summaryBanner.style.display = 'none';
    }
}

function renderAiForecastUI(result) {
    const tableBody = document.getElementById('aiForecastTableBody');
    const summaryBanner = document.getElementById('aiSummaryBanner');
    if (!tableBody) return;

    const data = result.prediction;
    window.currentAiForecasts = data.forecasts || [];

    if (summaryBanner && data.summary) {
        summaryBanner.innerHTML = `<strong><i class='bx bx-info-circle'></i> Executive DRRM Advisory (${result.month}):</strong> ${data.summary}`;
        summaryBanner.style.display = 'block';
    }

    if (data.forecasts.length === 0) {
        tableBody.innerHTML = `
            <tr>
                <td colspan="5" class="table-empty-row text-muted">
                    No elevated risk patterns identified for this cycle.
                </td>
            </tr>
        `;
        return;
    }

    tableBody.innerHTML = data.forecasts.map((item, index) => {
        const risk = (item.risk_level || 'MODERATE').toUpperCase();
        const badgeClass = risk === 'CRITICAL' ? 'critical' : (risk === 'HIGH' ? 'major' : 'info');

        return `
            <tr class="clickable-ai-row" onclick="openAiDetailModal(${index})" title="Click to view full predictive details">
                <td class="col-brgy">
                    <strong class="text-dark font-weight-bold">${item.barangay}</strong>
                </td>
                <td class="col-hazard">
                    <div class="hazard-type-title">${item.hazard_type}</div>
                    <small class="hazard-type-basis desktop-only">${item.historical_basis || ''}</small>
                </td>
                <td class="col-risk" style="text-align: center;">
                    <span class="badge ${badgeClass}">${risk}</span>
                </td>
                <td class="col-confidence desktop-only">
                    <span class="confidence-tag">${item.pattern_confidence || 'N/A'}</span>
                </td>
                <td class="col-protocol desktop-only">
                    <div class="recommended-protocol-box">
                        <i class='bx bx-shield-quarter'></i>
                        <span>${item.recommended_action || ''}</span>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

function openAiDetailModal(index) {
    const forecasts = window.currentAiForecasts || [];
    const item = forecasts[index];
    if (!item) return;

    const modal = document.getElementById('aiDetailModal');
    const titleText = document.getElementById('aiModalBarangay');
    const body = document.getElementById('aiModalBody');
    if (!modal || !body) return;

    if (titleText) {
        titleText.textContent = `${item.barangay} — Hazard Forecast`;
    }

    const risk = (item.risk_level || 'MODERATE').toUpperCase();
    const badgeClass = risk === 'CRITICAL' ? 'critical' : (risk === 'HIGH' ? 'major' : 'info');

    let coordsHtml = '';
    if (item.lat && item.lng) {
        coordsHtml = `
            <div style="font-size: 0.76rem; color: #64748b; margin-top: 6px;">
                <i class='bx bx-map-pin' style="color: #dc2626;"></i> Primary Cluster Anchor: <strong>${item.lat}, ${item.lng}</strong>
            </div>
        `;
    }

    body.innerHTML = `
        <div style="display: flex; flex-direction: column; gap: 14px;">
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap;">
                    <div>
                        <span style="font-size: 0.68rem; font-weight: 700; color: #64748b; text-transform: uppercase;">Anticipated Hazard</span>
                        <h4 style="font-size: 1.15rem; font-weight: 800; color: #0284c7; margin: 2px 0 0;">${item.hazard_type}</h4>
                    </div>
                    <div style="display: flex; gap: 6px; align-items: center;">
                        <span class="badge ${badgeClass}" style="font-size: 0.72rem; padding: 4px 10px;">${risk} RISK</span>
                        <span class="badge badge-tag" style="font-size: 0.72rem; padding: 4px 8px;">${item.pattern_confidence || 'Recurrent'}</span>
                    </div>
                </div>
                ${coordsHtml}
            </div>

            <div style="background: #fff; border: 1px solid #e2e8f0; border-left: 4px solid #d97706; border-radius: 8px; padding: 14px;">
                <div style="font-size: 0.75rem; font-weight: 800; color: #d97706; text-transform: uppercase; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                    <i class='bx bx-history' style="font-size: 1rem;"></i> Historical Basis & Cluster Analysis
                </div>
                <div style="font-size: 0.88rem; color: #334155; line-height: 1.5; font-style: italic;">
                    "${item.historical_basis || 'Identified based on seasonal recurrence patterns.'}"
                </div>
            </div>

            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-left: 4px solid #16a34a; border-radius: 8px; padding: 14px;">
                <div style="font-size: 0.75rem; font-weight: 800; color: #166534; text-transform: uppercase; margin-bottom: 4px; display: flex; align-items: center; gap: 4px;">
                    <i class='bx bx-shield-quarter' style="font-size: 1.1rem;"></i> DRRM Recommended Protocols
                </div>
                <div style="font-size: 0.88rem; color: #14532d; line-height: 1.5; font-weight: 600;">
                    ${item.recommended_action || 'Ensure equipment pre-staging and regular field monitoring.'}
                </div>
            </div>

            <button type="button" class="btn-action btn-secondary-action" style="width: 100%; padding: 12px; margin-top: 4px;" onclick="closeModal('aiDetailModal')">
                Close
            </button>
        </div>
    `;

    modal.style.display = 'flex';
}

// -----------------------------------------------------
// 5. SEASONALITY RENDERING
// -----------------------------------------------------
function renderSeasonality() {
    const canvasElement = document.getElementById('seasonalityLineChart');
    if (!canvasElement) return;

    const filterEl = document.getElementById('seasonalityFilter');
    const filter = filterEl ? filterEl.value : 'all';
    const now = new Date();
    
    let filtered = allSeasonDates.filter(dateStr => {
        if (!dateStr) return false;
        if (filter === 'all') return true;
        const incDate = new Date(dateStr.replace(' ', 'T'));
        const diffDays = (now - incDate) / (1000 * 60 * 60 * 24);
        
        if (filter === 'day') return diffDays <= 1;
        if (filter === 'weekly') return diffDays <= 7;
        if (filter === 'monthly') return diffDays <= 30;
        if (filter === 'quarterly') return diffDays <= 90;
        if (filter === 'yearly') return diffDays <= 365;
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
        const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)';
        const textColor = isDarkMode ? '#94a3b8' : '#64748b';
        let gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(220, 38, 38, 0.3)'); 
        gradient.addColorStop(1, 'rgba(220, 38, 38, 0.0)'); 

        lineChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Incidents',
                    data: data,
                    borderColor: '#dc2626',
                    backgroundColor: gradient,
                    borderWidth: 2,
                    fill: true,
                    tension: 0.3,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#dc2626',
                    pointRadius: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, datalabels: { display: false } },
                scales: {
                    x: { grid: { display: false }, ticks: { color: textColor, font: { weight: '600', size: 10 } } },
                    y: { beginAtZero: true, grid: { color: gridColor }, ticks: { stepSize: 1, color: textColor } }
                }
            }
        });
    }
}

// -----------------------------------------------------
// 6. REPORT BUILDER CONTROLS & EXPORTS
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
        const baseYear = 2026;
        const currentYear = Math.max(b.year, baseYear);
        for (let y = currentYear; y >= baseYear; y--) {
            const opt = document.createElement('option');
            opt.value = y;
            opt.textContent = y;
            sel.appendChild(opt);
        }
    });

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
    doc.setTextColor(2, 132, 199);
    doc.text('CDRRMO Dasmariñas Incident Report', 14, 15);

    doc.setFontSize(10);
    doc.setTextColor(100, 116, 139);
    doc.text(`Timeframe: ${rangeLabel}  |  Filter: ${selectedTypeLabel}  |  Generated: ${new Date().toLocaleString()}`, 14, 22);

    let startY = 30;

    if (vaultRows.length > 0) {
        doc.setFontSize(14);
        doc.setTextColor(15, 23, 42);
        doc.text(`Incident Archive Vault (${vaultRows.length} Total Records)`, 14, startY);
        startY += 6;

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
            headStyles: { fillColor: [2, 132, 199] },
            styles: { fontSize: 8, cellPadding: 2.5, overflow: 'linebreak' }
        });

        startY = doc.lastAutoTable.finalY + 12;
    }

    if (binRows.length > 0) {
        if (startY > 155) { doc.addPage(); startY = 20; }

        doc.setFontSize(14);
        doc.setTextColor(220, 38, 38);
        doc.text(`Report Bin & Rejection Audit (${binRows.length} Total Records)`, 14, startY);
        startY += 6;

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
            headStyles: { fillColor: [220, 38, 38] },
            styles: { fontSize: 8, cellPadding: 2.5, overflow: 'linebreak' }
        });
    }

    doc.save(`Dasma_Alert_Report_${rangeLabel.replace(/[^a-z0-9]/gi, '_')}.pdf`);
}

function generateCustomCSV(vaultRows, binRows, rangeLabel, selectedTypeLabel, groupByType) {
    let csv = `\uFEFF--- DASMARINAS CITY CDRRMO AUDIT REPORT ---\n`;
    csv += `TIMEFRAME: ${rangeLabel}\n`;
    csv += `FILTER: ${selectedTypeLabel}\n`;
    csv += `GENERATED: ${new Date().toLocaleString()}\n\n`;

    if (vaultRows.length > 0) {
        csv += `--- INCIDENT ARCHIVE VAULT (${vaultRows.length} Total Records) ---\n`;
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
        csv += '\n';
    }

    if (binRows.length > 0) {
        csv += `--- REPORT BIN REJECTION AUDIT (${binRows.length} Total Records) ---\n`;
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
    if (!confirm('Download a complete offline report snapshot (Archive Vault + Report Bin + Audit Logs)?')) {
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
// 7. CONTROLS, MODALS & TIMELINES
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
        container.innerHTML = '<div class="p-4 text-center text-muted">No timeline logs recorded.</div>';
    } else {
        const entries = logsString.split('|||').map(e => e.trim()).filter(Boolean);
        let html = '<div class="log-entry-list">';
        entries.forEach(entry => {
            const parts = entry.split('|-|');
            const time = parts[0] || '';
            const user = parts[1] || 'System';
            const msg = parts[2] || '';
            html += `
                <div class="timeline-log-card">
                    <div class="timeline-log-header">
                        <span class="timeline-log-meta">${time} — ${user}</span>
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
    if (confirm("Terminate this active broadcast warning?")) {
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
// 8. MOBILE MODAL LOGIC
// -----------------------------------------------------
function openMobileModal(row, type) {
    if (window.innerWidth > 768) return; 
    
    const cells = row.querySelectorAll('td');
    const titleEl = document.getElementById('m-analytics-title');
    const bodyEl = document.getElementById('m-analytics-body');
    let html = '';
    
    if (type === 'archive') {
        titleEl.innerHTML = "Archive Record";
        html += `<div class="mobile-detail-box"><small class="mobile-label">Incident & Logs</small>${cells[0].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Location & Status</small>${cells[1].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Response Timeline</small>${cells[2].innerHTML}</div>`;
        html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container">${cells[3].innerHTML}</div></div>`;
    } 
    else if (type === 'bin') {
        titleEl.innerHTML = "Rejection Audit Record";
        html += `<div class="mobile-detail-box"><small class="mobile-label">Incident & Citizen Input</small>${cells[0].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Location & Status</small>${cells[1].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Audit & Explanation</small>${cells[2].innerHTML}</div>`;
        html += `<div style="margin-top: 5px;"><small class="mobile-label">Actions</small><div class="m-actions-container">${cells[3].innerHTML}</div></div>`;
    }
    else if (type === 'broadcast') {
        titleEl.innerHTML = "Broadcast Alert Details";
        html += `<div class="mobile-detail-box"><small class="mobile-label">Alert Title & Date</small>${cells[0].innerHTML}</div>`;
        html += `<div class="mobile-detail-box"><small class="mobile-label">Severity</small><div style="margin-top: 5px;">${cells[1].innerHTML}</div></div>`;
        html += `<div style="margin-top: 5px;"><small class="mobile-label">Status & Actions</small><div class="m-actions-container">${cells[2].innerHTML}</div></div>`;
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

    document.getElementById('mobileAnalyticsModal').style.display = 'flex';
}

// Window bindings
window.toggleTableCollapse = toggleTableCollapse;
window.handleHeaderToggle = handleHeaderToggle;
window.openAiDetailModal = openAiDetailModal;
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
window.loadAiForecast = loadAiForecast;
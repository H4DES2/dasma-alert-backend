const API_PATH = 'admin_actions.php';

window.triageState = {
    types: [],
    incidents: [],
    questions: [],
    coverage: null
};

const LEVEL_LABELS = {
    0: 'None',
    1: 'Minor',
    2: 'Major',
    3: 'Critical'
};

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    loadTriageAdminData();
});

// =========================================================================
// TRIAGE ADMIN DATA LOADER
// =========================================================================
async function loadTriageAdminData() {
    try {
        const res = await fetch(`${API_PATH}?action=get_triage_admin_data`);
        const data = await res.json();
        if (!data.success) {
            console.error('Triage load failed:', data.message);
            return;
        }
        window.triageState = {
            types: data.types || [],
            incidents: data.incidents || [],
            questions: data.questions || [],
            coverage: data.coverage || null
        };

        updateCoverageBanner();
        renderTriageQuestionTable();
    } catch (err) {
        console.error('Failed to fetch triage data:', err);
    }
}

function updateCoverageBanner() {
    const el = document.getElementById('triageCoverageSummary');
    const cov = window.triageState.coverage;
    if (!el || !cov) return;

    const warnGap = cov.uncovered > 0
        ? ` • <span style="color:#dc2626;">${cov.uncovered} hazard(s) without questions</span>`
        : ` • <span style="color:#10b981;">All ${cov.total_incidents} hazards covered</span>`;
    const warnLong = cov.over_four > 0
        ? ` • <span style="color:#d97706;">${cov.over_four} hazard(s) with >4 questions</span>`
        : '';

    el.innerHTML = `${cov.total_questions} questions in library${warnGap}${warnLong}`;
}

// =========================================================================
// TRIAGE QUESTIONS TABLE & FILTERS
// =========================================================================
function renderTriageQuestionTable() {
    const tbody = document.getElementById('triageQuestionsTableBody');
    if (!tbody) return;

    const search = (document.getElementById('triageSearchInput')?.value || '').toLowerCase().trim();
    const catFilter = document.getElementById('triageCategoryFilter')?.value || 'all';
    const statusFilter = document.getElementById('triageStatusFilter')?.value || 'all';

    const typeMap = {};
    window.triageState.types.forEach(t => { typeMap[t.id] = t; });

    const incMap = {};
    window.triageState.incidents.forEach(i => { incMap[i.id] = i; });

    const filtered = window.triageState.questions.filter(q => {
        if (statusFilter !== 'all' && q.status !== statusFilter) return false;

        if (catFilter !== 'all') {
            const targetTid = parseInt(catFilter, 10);
            const matchesCat = (q.links || []).some(l => {
                if (l.type_id === targetTid) return true;
                if (l.incident_id && incMap[l.incident_id]?.type_id === targetTid) return true;
                return false;
            });
            if (!matchesCat) return false;
        }

        if (search) {
            const optText = (q.options || []).map(o => o.label).join(' ');
            const linkText = (q.links || []).map(l => {
                if (l.type_id) return typeMap[l.type_id]?.name || '';
                if (l.incident_id) {
                    const inc = incMap[l.incident_id];
                    return inc ? `${typeMap[inc.type_id]?.name || ''} ${inc.name}` : '';
                }
                return '';
            }).join(' ');

            const haystack = `${q.question_text} ${optText} ${linkText}`.toLowerCase();
            if (!haystack.includes(search)) return false;
        }

        return true;
    });

    if (filtered.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" style="text-align:center; padding: 28px; color:#94a3b8; font-weight:600;">No triage questions match your filter.</td></tr>`;
        return;
    }

    tbody.innerHTML = filtered.map(q => {
        const optsHtml = (q.options || []).map(o => {
            const lvl = o.level ?? 0;
            return `<span class="sev-pill sev-${lvl}" style="margin:2px;">${escHtml(o.label)} (${LEVEL_LABELS[lvl]})</span>`;
        }).join('');

        const unsureHtml = q.unsure_level > 0
            ? `<div style="margin-top:4px;"><small style="color:#64748b; font-weight:700;">If skipped/unsure: <span class="sev-pill sev-${q.unsure_level}">${LEVEL_LABELS[q.unsure_level]}</span></small></div>`
            : '';

        const linksHtml = (q.links || []).length === 0
            ? `<span style="color:#94a3b8; font-size:0.78rem; font-style:italic;">Unassigned</span>`
            : (q.links || []).map(l => {
                if (l.type_id) {
                    const tName = typeMap[l.type_id]?.name || `Type #${l.type_id}`;
                    return `<span class="link-chip type-wide"><i class='bx bx-category'></i> All ${escHtml(tName)}</span>`;
                }
                if (l.incident_id) {
                    const inc = incMap[l.incident_id];
                    if (!inc) return '';
                    const tName = typeMap[inc.type_id]?.name || '';
                    return `<span class="link-chip">${escHtml(tName)}: ${escHtml(inc.name)}</span>`;
                }
                return '';
            }).join('');

        const isActive = q.status === 'active';
        const nextStatus = isActive ? 'inactive' : 'active';
        const statusBtn = isActive
            ? `<button class="pill-btn bg-green" style="padding: 4px 10px; font-size:0.72rem;" onclick="toggleTriageQuestionStatus(${q.id}, '${nextStatus}')" title="Click to deactivate">ACTIVE</button>`
            : `<button class="pill-btn bg-slate" style="padding: 4px 10px; font-size:0.72rem;" onclick="toggleTriageQuestionStatus(${q.id}, '${nextStatus}')" title="Click to activate">INACTIVE</button>`;

        return `
            <tr>
                <td>
                    <div style="font-weight:800; color:#1e293b; font-size:0.92rem; margin-bottom:5px;">${escHtml(q.question_text)}</div>
                    <div>${optsHtml}</div>
                    ${unsureHtml}
                </td>
                <td>${linksHtml}</td>
                <td style="text-align:center;">${statusBtn}</td>
                <td style="text-align:center; white-space:nowrap;">
                    <button class="action-circle bg-green" onclick="openTriageQuestionModal(${q.id})" title="Edit Question"><i class='bx bx-edit'></i></button>
                    <button class="action-circle bg-red" onclick="deleteTriageQuestion(${q.id})" title="Delete Question"><i class='bx bx-trash'></i></button>
                </td>
            </tr>
        `;
    }).join('');
}

// =========================================================================
// TRIAGE QUESTION EDITOR MODAL
// =========================================================================
function openTriageQuestionModal(questionId = 0) {
    const modal = document.getElementById('triageQuestionModal');
    const titleEl = document.getElementById('tqModalTitle');
    const idEl = document.getElementById('tq_id');
    const textEl = document.getElementById('tq_text');
    const unsureEl = document.getElementById('tq_unsure');
    const statusEl = document.getElementById('tq_status');
    const optsContainer = document.getElementById('tqOptionsContainer');

    if (!modal) return;
    optsContainer.innerHTML = '';

    const existing = questionId > 0
        ? window.triageState.questions.find(q => q.id === questionId)
        : null;

    if (existing) {
        titleEl.innerText = 'Edit Triage Question';
        idEl.value = existing.id;
        textEl.value = existing.question_text;
        unsureEl.value = String(existing.unsure_level ?? 0);
        statusEl.value = existing.status || 'active';

        (existing.options || []).forEach(o => addTriageOptionRow(o.label, o.level));
    } else {
        titleEl.innerText = 'Add Triage Question';
        idEl.value = '';
        textEl.value = '';
        unsureEl.value = '2';
        statusEl.value = 'active';

        addTriageOptionRow('No', 0);
        addTriageOptionRow('Yes', 3);
    }

    renderAppliesToChecklist(existing ? existing.links : []);
    modal.style.display = 'flex';
}

function addTriageOptionRow(label = '', level = 0) {
    const container = document.getElementById('tqOptionsContainer');
    if (!container) return;

    const row = document.createElement('div');
    row.className = 'opt-editor-row';
    row.innerHTML = `
        <input type="text" class="tq-opt-label" value="${escHtml(label)}" placeholder="Option label (e.g. Someone unconscious)" style="flex: 2; padding: 9px 11px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.85rem;">
        <select class="tq-opt-level" style="flex: 1; padding: 9px 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.85rem;">
            <option value="0" ${level == 0 ? 'selected' : ''}>None (Base)</option>
            <option value="1" ${level == 1 ? 'selected' : ''}>Minor</option>
            <option value="2" ${level == 2 ? 'selected' : ''}>Major</option>
            <option value="3" ${level == 3 ? 'selected' : ''}>Critical</option>
        </select>
        <button type="button" class="action-circle bg-red" style="width:30px; height:30px; flex-shrink:0;" onclick="this.parentElement.remove()" title="Remove option">&times;</button>
    `;
    container.appendChild(row);
}

function renderAppliesToChecklist(existingLinks = []) {
    const container = document.getElementById('tqAppliesToContainer');
    if (!container) return;

    const checkedTypes = new Set();
    const checkedIncs = new Set();
    (existingLinks || []).forEach(l => {
        if (l.type_id) checkedTypes.add(l.type_id);
        if (l.incident_id) checkedIncs.add(l.incident_id);
    });

    const incsByType = {};
    window.triageState.incidents.forEach(inc => {
        if (!incsByType[inc.type_id]) incsByType[inc.type_id] = [];
        incsByType[inc.type_id].push(inc);
    });

    container.innerHTML = window.triageState.types.map(t => {
        const isTypeWide = checkedTypes.has(t.id);
        const subIncs = incsByType[t.id] || [];

        const subHtml = subIncs.map(inc => {
            const isChecked = isTypeWide || checkedIncs.has(inc.id);
            return `
                <label class="applies-item">
                    <input type="checkbox" class="tq-link-inc" data-type-id="${t.id}" value="inc:${inc.id}" ${isChecked ? 'checked' : ''} ${isTypeWide ? 'disabled' : ''}>
                    <span>${escHtml(inc.name)}</span>
                </label>
            `;
        }).join('');

        return `
            <div class="applies-group">
                <label style="display:flex; align-items:center; gap:8px; font-weight:800; font-size:0.86rem; color:#1e293b; cursor:pointer;">
                    <input type="checkbox" class="tq-link-type" data-type-id="${t.id}" value="type:${t.id}" ${isTypeWide ? 'checked' : ''} onchange="onTypeWideToggle(this)">
                    <i class='bx ${escHtml(t.icon || 'bx-error')}' style="color:#ef4444;"></i>
                    <span>All ${escHtml(t.name)} Hazards (Type-Wide)</span>
                </label>
                ${subIncs.length > 0 ? `<div class="applies-sublist">${subHtml}</div>` : ''}
            </div>
        `;
    }).join('');
}

function onTypeWideToggle(checkbox) {
    const tid = checkbox.getAttribute('data-type-id');
    const subBoxes = document.querySelectorAll(`.tq-link-inc[data-type-id="${tid}"]`);
    subBoxes.forEach(cb => {
        cb.checked = checkbox.checked;
        cb.disabled = checkbox.checked;
    });
}

async function saveTriageQuestion() {
    const id = document.getElementById('tq_id').value;
    const questionText = document.getElementById('tq_text').value.trim();
    const unsureLevel = document.getElementById('tq_unsure').value;
    const status = document.getElementById('tq_status').value;

    const options = [];
    document.querySelectorAll('#tqOptionsContainer .opt-editor-row').forEach(row => {
        const label = row.querySelector('.tq-opt-label')?.value.trim() || '';
        const level = parseInt(row.querySelector('.tq-opt-level')?.value || '0', 10);
        if (label) options.push({ label, level });
    });

    if (!questionText) {
        alert('Please enter the question text.');
        return;
    }
    if (options.length < 2) {
        alert('Please add at least 2 answer options.');
        return;
    }

    const links = [];
    document.querySelectorAll('.tq-link-type:checked').forEach(cb => links.push(cb.value));
    document.querySelectorAll('.tq-link-inc:checked:not(:disabled)').forEach(cb => links.push(cb.value));

    const fd = new FormData();
    fd.append('action', 'save_triage_question');
    fd.append('id', id);
    fd.append('question_text', questionText);
    fd.append('unsure_level', unsureLevel);
    fd.append('status', status);
    fd.append('options', JSON.stringify(options));
    fd.append('links', JSON.stringify(links));

    try {
        const res = await fetch(API_PATH, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            closeModal('triageQuestionModal');
            await loadTriageAdminData();
        } else {
            alert(data.message || 'Failed to save question.');
        }
    } catch (err) {
        console.error(err);
        alert('Error saving triage question.');
    }
}

async function deleteTriageQuestion(id) {
    if (!confirm('Delete this triage question permanently?')) return;
    const fd = new FormData();
    fd.append('action', 'delete_triage_question');
    fd.append('id', id);

    try {
        const res = await fetch(API_PATH, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            await loadTriageAdminData();
        } else {
            alert(data.message || 'Failed to delete question.');
        }
    } catch (err) {
        console.error(err);
    }
}

async function toggleTriageQuestionStatus(id, nextStatus) {
    const fd = new FormData();
    fd.append('action', 'toggle_triage_question_status');
    fd.append('id', id);
    fd.append('status', nextStatus);

    try {
        const res = await fetch(API_PATH, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            await loadTriageAdminData();
        } else {
            alert(data.message || 'Failed to update status.');
        }
    } catch (err) {
        console.error(err);
    }
}

// =========================================================================
// REBUILT MANAGE HAZARDS & BASE SEVERITIES MODAL
// =========================================================================
function openIncidentModal() {
    document.getElementById('inc_parent_id').value = '';
    document.getElementById('hazardRowsContainer').innerHTML =
        `<div style="text-align:center; padding: 20px; color:#94a3b8; font-size:0.85rem;">Select an Emergency Type above to edit its hazards.</div>`;
    document.getElementById('incidentModal').style.display = 'flex';
}

function openIncidentModalFor(id) {
    openIncidentModal();
    document.getElementById('inc_parent_id').value = id;
    loadIncidentsForParent(id);
}

function loadIncidentsForParent(val) {
    const container = document.getElementById('hazardRowsContainer');
    if (!container) return;

    if (!val) {
        container.innerHTML = `<div style="text-align:center; padding: 20px; color:#94a3b8; font-size:0.85rem;">Select an Emergency Type above to edit its hazards.</div>`;
        return;
    }

    const typeId = parseInt(val, 10);
    const matching = window.triageState.incidents.filter(i => i.type_id === typeId);
    container.innerHTML = '';

    if (matching.length === 0) {
        addHazardEditorRow(0, '', 1);
    } else {
        matching.forEach(h => addHazardEditorRow(h.id, h.name, h.base_level));
    }
}

function addHazardEditorRow(id = 0, name = '', baseLevel = 1) {
    const container = document.getElementById('hazardRowsContainer');
    if (!container) return;

    if (container.querySelector('div[style*="text-align:center"]')) {
        container.innerHTML = '';
    }

    const row = document.createElement('div');
    row.className = 'hazard-editor-row';
    row.setAttribute('data-hazard-id', id);
    row.innerHTML = `
        <input type="text" class="hz-name" value="${escHtml(name)}" placeholder="Hazard name (e.g. Vehicular Collision)" style="flex: 2; padding: 9px 11px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.85rem;">
        <select class="hz-base-level" style="flex: 1; padding: 9px 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.85rem;" title="Base Severity Floor">
            <option value="1" ${baseLevel == 1 ? 'selected' : ''}>Base: Minor</option>
            <option value="2" ${baseLevel == 2 ? 'selected' : ''}>Base: Major</option>
            <option value="3" ${baseLevel == 3 ? 'selected' : ''}>Base: Critical</option>
        </select>
        <button type="button" class="action-circle bg-red" style="width:30px; height:30px; flex-shrink:0;" onclick="this.parentElement.remove()" title="Remove hazard">&times;</button>
    `;
    container.appendChild(row);
}

async function saveIncidents() {
    const sel = document.getElementById('inc_parent_id');
    if (!sel || !sel.value) {
        alert('Please select an Emergency Type first.');
        return;
    }

    const hazards = [];
    document.querySelectorAll('#hazardRowsContainer .hazard-editor-row').forEach(row => {
        const id = parseInt(row.getAttribute('data-hazard-id') || '0', 10);
        const name = row.querySelector('.hz-name')?.value.trim() || '';
        const base_level = parseInt(row.querySelector('.hz-base-level')?.value || '1', 10);
        if (name) hazards.push({ id, name, base_level });
    });

    const fd = new FormData();
    fd.append('action', 'save_hazard_base_levels');
    fd.append('type_id', sel.value);
    fd.append('hazards', JSON.stringify(hazards));

    try {
        const res = await fetch(API_PATH, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Failed to save hazards.');
        }
    } catch (err) {
        console.error(err);
        alert('Error saving hazards.');
    }
}

// =========================================================================
// EXISTING BROADCAST, ANNOUNCEMENTS, GUIDELINES & PARENT TYPE FUNCTIONS
// =========================================================================
function submitGlobalBroadcast() {
    let fd = new FormData();
    fd.append('action', 'send_broadcast');
    fd.append('title', document.getElementById('globalBroadcastTitle').value);
    fd.append('message', document.getElementById('globalBroadcastMessage').value);
    fd.append('severity', document.getElementById('globalBroadcastSeverity').value);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

function openAnnouncementModal(id='', title='', msg='') {
    document.getElementById('ann_id').value = id;
    document.getElementById('ann_title').value = title;
    document.getElementById('ann_message').value = msg.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
    document.getElementById('announcementModal').style.display = 'flex';
}

function saveAnnouncement() {
    let fd = new FormData();
    fd.append('action', 'save_announcement');
    fd.append('id', document.getElementById('ann_id').value);
    fd.append('title', document.getElementById('ann_title').value);
    fd.append('message', document.getElementById('ann_message').value);
    if (document.getElementById('ann_image').files[0]) {
        fd.append('image', document.getElementById('ann_image').files[0]);
    }
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

function deleteAnnouncement(id) {
    if (!confirm('Delete this announcement?')) return;
    let fd = new FormData();
    fd.append('action', 'delete_announcement');
    fd.append('id', id);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

function openGuidelineModal(id='', title='', content='') {
    document.getElementById('guide_id').value = id;
    document.getElementById('guide_title').value = title;
    document.getElementById('guide_content').value = content.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
    document.getElementById('guidelineModal').style.display = 'flex';
}

function saveGuideline() {
    let fd = new FormData();
    fd.append('action', 'save_guideline');
    fd.append('id', document.getElementById('guide_id').value);
    fd.append('title', document.getElementById('guide_title').value);
    fd.append('content', document.getElementById('guide_content').value);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

function deleteGuideline(id) {
    if (!confirm('Delete this guideline?')) return;
    let fd = new FormData();
    fd.append('action', 'delete_guideline');
    fd.append('id', id);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

function openTypeModal(id='', name='', icon='bx-error', incidents='') {
    document.getElementById('type_id').value = id;
    document.getElementById('type_name').value = name;
    
    let iconSelect = document.getElementById('type_icon');
    let exists = Array.from(iconSelect.options).some(opt => opt.value === icon);
    iconSelect.value = exists ? icon : 'bx-error';

    document.getElementById('type_hidden_incidents').value = incidents;
    document.getElementById('typeModal').style.display = 'flex';
}

function saveType() {
    let fd = new FormData();
    fd.append('action', 'save_emergency_type');
    fd.append('id', document.getElementById('type_id').value);
    fd.append('name', document.getElementById('type_name').value);
    fd.append('icon', document.getElementById('type_icon').value);
    fd.append('incidents', document.getElementById('type_hidden_incidents').value);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

function deleteType(id) {
    if (!confirm('Delete this emergency type?')) return;
    let fd = new FormData();
    fd.append('action', 'delete_emergency_type');
    fd.append('id', id);
    fetch(API_PATH, { method: 'POST', body: fd }).then(() => location.reload());
}

function showTypeDetails(id, name, icon, incidents) {
    document.getElementById('viewTypeName').innerText = name;
    document.getElementById('viewTypeIcon').className = 'bx ' + (icon || 'bx-error');

    const container = document.getElementById('viewTypeIncidentsList');
    container.innerHTML = '';

    const structured = window.triageState.incidents.filter(i => i.type_id === id);

    if (structured.length > 0) {
        structured.forEach(h => {
            const row = document.createElement('div');
            row.style.cssText = 'display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 10px 14px; background: #f8fafc; border: 1px solid #edf2f7; border-radius: 10px; font-weight: 600; font-size: 0.9rem; color: #334155;';
            row.innerHTML = `
                <div style="display:flex; align-items:center; gap:8px;">
                    <i class='bx bx-chevron-right' style='color: #f59e0b; font-size: 1.2rem;'></i>
                    <span>${escHtml(h.name)}</span>
                </div>
                <div style="display:flex; align-items:center; gap:6px;">
                    <span class="sev-pill sev-${h.base_level}">Base: ${LEVEL_LABELS[h.base_level]}</span>
                    <span class="link-chip">${h.active_q_count} Q</span>
                </div>
            `;
            container.appendChild(row);
        });
    } else {
        const items = (incidents || '').split(',').map(s => s.trim()).filter(Boolean);
        if (items.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 24px; color: #94a3b8; font-weight: 600; background: #f8fafc; border-radius: 12px; border: 1px dashed #cbd5e1;">
                    No specific hazards configured yet.
                </div>`;
        } else {
            items.forEach(hazard => {
                const row = document.createElement('div');
                row.style.cssText = 'display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #f8fafc; border: 1px solid #edf2f7; border-radius: 10px; font-weight: 600; font-size: 0.9rem; color: #334155;';
                row.innerHTML = `<i class='bx bx-chevron-right' style='color: #f59e0b; font-size: 1.2rem;'></i> <span>${escHtml(hazard)}</span>`;
                container.appendChild(row);
            });
        }
    }

    document.getElementById('viewTypeEditHazardsBtn').onclick = function() {
        closeModal('viewTypeDetailsModal');
        openIncidentModalFor(id);
    };

    document.getElementById('viewTypeDetailsModal').style.display = 'flex';
}

function showGuidelineDetails(id, title, content) {
    document.getElementById('viewGuideTitle').innerText = title;
    document.getElementById('viewGuideContent').innerText = content.replace(/\\n/g, '\n').replace(/\\r/g, '\r');
    
    document.getElementById('viewGuideEditBtn').onclick = function() {
        closeModal('viewGuidelineModal');
        openGuidelineModal(id, title, content);
    };

    document.getElementById('viewGuidelineModal').style.display = 'flex';
}
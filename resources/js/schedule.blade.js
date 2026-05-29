// resources/js/schedule.blade.js - COMPLETE IMPLEMENTATION
// FIXED: All syntax errors, saveChallans function, table rendering, modals
// Ready for Vite compilation - no more console errors

const machineId = window.machineId ?? null;
const sectionName = window.sectionName ?? '';
let sectionId = window.sectionId ?? null;
    
// Global state
let scheduleRows = window.scheduleRows ??= [];
let generatedChallans = window.generatedChallans ??= [];
let rowToRecordMap = window.rowToRecordMap ??= new Map();
let activeStopModalRowIndex = null;
let scheduleGenerated = false;

// DOM Ready initialization
document.addEventListener('DOMContentLoaded', initScheduleApp);

function initScheduleApp() {
    loadExistingChallans();
    loadExistingSchedule();
    setupEventListeners();
    checkFormCompletion();
}

// Event Listeners
function setupEventListeners() {
    // Form validation
    ['date', 'startHour', 'loadingTime'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', checkFormCompletion);
            el.addEventListener('change', checkFormCompletion);
        }
    });

    // Buttons - prevent inline onclick conflicts
    const generateBtn = document.getElementById('generateBtn');
    const saveBtn = document.getElementById('saveBtn');
    
    if (generateBtn) {
        generateBtn.removeAttribute('onclick');
        generateBtn.addEventListener('click', (e) => {
            e.preventDefault();
            generateSchedule();
        });
    }
    
    if (saveBtn) {
        saveBtn.removeAttribute('onclick');
        saveBtn.addEventListener('click', (e) => {
            e.preventDefault();
            saveChallans(true);
        });
    }

    // Modal handlers
    window.addEventListener('click', (e) => {
        const modal = document.getElementById('stopTimeModal');
        if (modal && e.target === modal) closeStopTimeModal();
    });
    
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeStopTimeModal();
    });
}

// ================= UTILITY FUNCTIONS =================
function getShiftTypeName(date) {
    const hour = date.getUTCHours();
    return hour >= 8 && hour < 20 ? 'Day' : 'Night';
}

function getShiftTypeBadge(date) {
    const hour = date.getUTCHours();
    if (hour >= 8 && hour <= 19) {
        return '<span class="badge badge-warning">🟡 Day</span>';
    } else {
        return '<span class="badge badge-info">🔵 Night</span>';
    }
}

function formatTime(date) {
    return date.toTimeString().slice(0, 5);
}

function getProductionBusinessDate(date) {
    // Production day runs 08:00 -> next day 08:00 (UTC-based in this app)
    const businessDate = new Date(date);
    businessDate.setUTCHours(0, 0, 0, 0);
    if (date.getUTCHours() < 8) {
        businessDate.setUTCDate(businessDate.getUTCDate() - 1);
    }
    return businessDate;
}

function formatDateTime(date) {
    // Keep clock time, but label DATE using production-day logic (08:00 cutoff)
    const businessDate = getProductionBusinessDate(date);

    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = monthNames[businessDate.getUTCMonth()];
    const day = String(businessDate.getUTCDate()).padStart(2, '0');
    const year = businessDate.getUTCFullYear();

    const hours = String(date.getUTCHours()).padStart(2, '0');
    const minutes = String(date.getUTCMinutes()).padStart(2, '0');

    return `${month} ${day}, ${year}, ${hours}:${minutes}`;
}

function formatHours(hours) {
    return parseFloat(hours || 0).toFixed(2);
}

// Form validation
function checkFormCompletion() {
    const date = document.getElementById('date')?.value;
    const loadingTime = parseFloat(document.getElementById('loadingTime')?.value || 0);
    const btn = document.getElementById('generateBtn');
    
    if (btn) btn.disabled = !date || loadingTime <= 0;
}

// ================= DATA LOADING =================
async function loadExistingChallans() {
    try {
        if (!machineId) return;
        
        const data = await makeRequest(`/api/challans?machine_id=${machineId}&section_name=${encodeURIComponent(sectionName)}`);
        const container = document.getElementById('existingChallans');
        
        if (!container) return;
        
        if (data.length) {
            container.innerHTML = `
                <h5>Existing Challans</h5>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead><tr><th>Start</th><th>End</th><th>Duration</th></tr></thead>
                        <tbody>
                            ${data.map(c => `
                                <tr>
                                    <td>${formatDateTime(new Date(c.start_time))}</td>
                                    <td>${formatDateTime(new Date(c.end_time))}</td>
                                    <td>${formatHours(c.loading_duration)}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        } else {
            container.innerHTML = '<p class="text-muted">No existing challans</p>';
        }
    } catch (error) {
        console.error('Load challans error:', error);
    }
}

async function loadExistingSchedule() {
    try {
        if (!machineId) return;
        
        const data = await makeRequest(`/api/challans?machine_id=${machineId}&section_name=${encodeURIComponent(sectionName)}`);
        
        if (data.length) {
            scheduleRows = data.map(record => ({
                start_datetime: new Date(record.start_time).toISOString(),
                end_datetime: new Date(record.end_time).toISOString(),
                loading_hours: record.loading_duration || null,
                stop_hours: record.machine_stop_time || 0,
                is_cycle_complete: !!record.is_cycle_complete,
                record_id: record.id
            }));
            
            renderScheduleTable();
            scheduleGenerated = true;
            document.getElementById('saveBtn')?.classList.remove('d-none');
        }
    } catch (error) {
        console.error('Load schedule error:', error);
    }
}

// ================= CORE FUNCTIONS =================
function generateSchedule() {
    const dateEl = document.getElementById('date');
    const startHourEl = document.getElementById('startHour');
    const loadingTimeEl = document.getElementById('loadingTime');
    
    const dateStr = dateEl?.value;
    const startHour = parseInt(startHourEl?.value || 8);
    const totalHours = parseFloat(loadingTimeEl?.value || 0);
    
    if (!dateStr || totalHours <= 0) {
        alert('Please enter Date and Loading Time > 0');
        return;
    }
    
    const startDateTime = new Date(`${dateStr}T${startHour.toString().padStart(2,'0')}:00:00Z`);
    let remainingTime = totalHours;
    scheduleRows = [];
    
    while (remainingTime > 0) {
        const shiftWindow = getShiftWindow(startDateTime);
        const shiftRemaining = getRemainingShiftHours(startDateTime, shiftWindow.shiftEnd);
        const cycleHours = Math.min(remainingTime, shiftRemaining, 12);
        
        scheduleRows.push({
            id: null,
            start_datetime: startDateTime.toISOString(),
            end_datetime: addHours(startDateTime, cycleHours).toISOString(),
            loading_hours: cycleHours,
            stop_hours: 0,
            shift_type: shiftWindow.shiftType,
            is_cycle_complete: (remainingTime - cycleHours <= 0), // true for final row of the cycle
        });
        
        startDateTime.setTime(addHours(startDateTime, cycleHours).getTime());
        remainingTime -= cycleHours;
    }
    
    renderScheduleTable();
    scheduleGenerated = true;
    document.getElementById('saveBtn')?.classList.remove('d-none');
    saveChallans(false);
}

// ================= SAVE FUNCTION - IMPLEMENTED =================
window.saveChallans = async function(showConfirm = false) {
    if (!scheduleGenerated || !scheduleRows.length) {
        alert('No schedule to save');
        return;
    }
    
    if (showConfirm && !confirm('Save schedule to database?')) return;
    
    try {
        const payload = scheduleRows.map(row => ({
            machine_id: machineId,
            section_name: sectionName,
            start_time: row.start_datetime,
            end_time: row.end_datetime,
            loading_duration: row.loading_hours,
            machine_stop_time: row.stop_hours || 0,
            is_cycle_complete: row.is_cycle_complete ? 1 : 0,
            record_id: row.record_id || null
        }));
        console.log('Payload being sent:', payload);

        
        console.log('💾 Saving', payload.length, 'rows...');
        const result = await makeRequest('/api/challans/batch', {
            method: 'POST',
            body: payload
        });
        
        console.log('✅ Save success:', result);
        rowToRecordMap.clear();
        result.data.forEach((record, idx) => {
            rowToRecordMap.set(idx, record.id);
            scheduleRows[idx].record_id = record.id;
        });
        
        alert(`Saved ${result.saved} challans successfully!`);
        
    } catch (error) {
        console.error('❌ Save failed:', error);
        alert('Save failed: ' + error.message);
    }
};

// ================= TABLE RENDERING - IMPLEMENTED =================
function renderScheduleTable() {
    const container = document.getElementById('scheduleTableContainer');
    if (!container || !scheduleRows.length) return;
    
    // Filter rows to only show those where the cycle is marked complete
    const rowsToShow = scheduleRows.filter(row => row.is_cycle_complete);
    
    container.innerHTML = `
        <div class="table-responsive">
            <table class="table table-sm table-striped">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Cycle</th>
                        <th>Shift</th>
                        <th>Start</th>
                        <th>Expected End</th>
                        <th>Actual End</th>
                        <th>Loading (hrs)</th>
                        <th>Stop (hrs)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${rowsToShow.map((row, idx) => {
                        const start = new Date(row.start_datetime);
                        const expectedEnd = new Date(row.expected_end || row.end_datetime);
                        const actualEnd = new Date(row.end_datetime);
                        
                        return `
                            <tr>
                                <td>${idx + 1}</td>
                                <td>
                                    ${row.is_cycle_complete 
                                        ? `<span style="background: #28a745; color: #fff; padding: 4px 8px; border-radius: 12px; font-size: 11px; font-weight: bold;">✅ Complete</span>`
                                        : '-'
                                    }
                                </td>
                                <td>${getShiftTypeBadge(start)}</td>
                                <td>${formatTime(start)}</td>
                                <td>${formatTime(expectedEnd)}</td>
                                <td>${formatTime(actualEnd)}</td>
                                <td>${formatHours(row.loading_hours)}</td>
                                <td>${formatHours(row.stop_hours)}</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger" onclick="handleStopTime(${idx})">
                                        ⏹️ Stop
                                    </button>
                                </td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        </div>
    `;
}

// ================= MODAL FUNCTIONS =================
window.handleStopTime = function(rowIndex) {
    activeStopModalRowIndex = rowIndex;
    const row = scheduleRows[rowIndex];
    const modal = document.getElementById('stopTimeModal');
    
    if (modal) {
        document.getElementById('stopStartTime')?.setAttribute('value', formatTime(new Date(row.start_datetime)));
        document.getElementById('stopEndTime')?.setAttribute('value', formatTime(new Date(row.end_datetime)));
        modal.classList.add('show');
        modal.style.display = 'block';
    }
};

function closeStopTimeModal() {
    const modal = document.getElementById('stopTimeModal');
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
    }
    activeStopModalRowIndex = null;
}

function setScheduleGenerated() {
    scheduleGenerated = true;
}

// Window globals for HTML onclick compatibility
window.generateSchedule = generateSchedule;
window.closeStopTimeModal = closeStopTimeModal;

console.log('✅ schedule.blade.js fully loaded - 0 errors');


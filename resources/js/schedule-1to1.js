/**
 * Schedule 1:1 UI-to-Database Synchronization (SHIFT MODE)
 * 
 * Every UI row = One shift block (max 12 hours)
 * Day: 08:00–20:00, Night: 20:00–08:00
 * Perfect synchronization with cascade updates
 */

// Global variables
let scheduleRows = []; // Array of row objects matching database structure
let machineNumber = null;
let sectionCode = null;
let autoGenInterval = null; // For real-time monitoring

/**
 * Initialize schedule page
 */
function initSchedulePage(machineNum, section) {
    machineNumber = machineNum;
    sectionCode = section;
    
    // Load existing rows from database
    loadAllRows();
}

/**
 * Load all rows from database (1:1 mapping)
 */
async function loadAllRows() {
    try {
        // Use makeRequest if available, otherwise use fetch with CSRF token
        const url = `/api/schedule/get-all?machine_number=${machineNumber}&section=${sectionCode}`;
        let data;
        if (typeof makeRequest !== 'undefined') {
            data = await makeRequest(url);
        } else {
            const response = await fetch(url, {
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=\"csrf-token\"]')?.getAttribute('content') || ''
                }
            });
            data = await response.json();
        }
        
        if (data.success) {
            scheduleRows = data.rows.map(row => ({
                id: row.id,
                row_no: row.row_no,
                start_datetime: row.start_datetime,
                expected_end_datetime: row.expected_end_datetime,
                end_datetime: row.end_datetime,
                loading_hours: row.loading_hours,
                machine_stop_hours: row.machine_stop_hours
            }));
            
            renderTable();
        }
    } catch (error) {
        console.error('Error loading rows:', error);
    }
}

/**
 * SHIFT MODE: Generate schedule rows - Each row = one shift block
 * Day shift: 08:00–20:00 (12 hours)
 * Night shift: 20:00–08:00 (12 hours)
 */
function generateSchedule(date, startHour, loadingTime) {
    const numberOfRows = 10; // Default initial generation: 10 rows
    scheduleRows = [];
    
    const startDatetime = new Date(`${date}T${String(startHour).padStart(2, '0')}:00:00Z`);
    let currentDatetime = new Date(startDatetime);
    let remainingLoadingTime = parseFloat(loadingTime) || 0;
    
    for (let i = 0; i < numberOfRows; i++) {
        const rowNo = i + 1;
        const machineStopHours = 0;
        
        // Determine current shift type
        const shiftType = getShiftType(currentDatetime);
        const shiftInfo = getShiftBoundaries(currentDatetime, shiftType);
        const remainingInShift = getRemainingShiftHours(currentDatetime, shiftInfo.end);
        
        // Calculate shift hours for this row: min(remainingLoading, remainingInShift, 12)
        let shiftHours = Math.min(remainingLoadingTime, remainingInShift, 12);
        const loadingHours = shiftHours > 0 ? shiftHours : 0; // Empty row after loading ends
        
        // Calculate expected_end_datetime
        const expectedEndDatetime = new Date(currentDatetime.getTime() + loadingHours * 3600000);
        
        // Calculate end_datetime
        const endDatetime = new Date(expectedEndDatetime.getTime() + machineStopHours * 3600000);
        
        scheduleRows.push({
            id: null, // New row, no ID yet
            row_no: rowNo,
            start_datetime: currentDatetime.toISOString(),
            expected_end_datetime: expectedEndDatetime.toISOString(),
            end_datetime: endDatetime.toISOString(),
            loading_hours: loadingHours,
            machine_stop_hours: machineStopHours,
            shift_type: shiftType // UI info only
        });
        
        // Update for next row: always move to the next shift boundary
        remainingLoadingTime -= shiftHours;
        currentDatetime = new Date(shiftInfo.end);
    }
    
    // Auto-save after generation
    saveAllRows().then(() => {
        console.log('Initial schedule generated and auto-saved (10 rows)');
    });
    
    renderTable();
}

/**
 * Get shift type for given time: 'day' or 'night'
 */
function getShiftType(currentTime) {
    const hour = currentTime.getUTCHours();
    return (hour >= 8 && hour < 20) ? 'day' : 'night';
}

/**
 * Get shift start/end boundaries for given date/time
 */
function getShiftBoundaries(currentTime, shiftType) {
    const dateStr = currentTime.toISOString().slice(0, 10);
    
    if (shiftType === 'day') {
        return {
            start: new Date(`${dateStr}T08:00:00Z`),
            end: new Date(`${dateStr}T20:00:00Z`)
        };
    } else {
        // Night shift end is next day 08:00
        const nextDay = new Date(currentTime);
        nextDay.setUTCDate(nextDay.getUTCDate() + 1);
        const nextDayStr = nextDay.toISOString().slice(0, 10);
        return {
            start: new Date(`${dateStr}T20:00:00Z`),
            end: new Date(`${nextDayStr}T08:00:00Z`)
        };
    }
}

/**
 * Get remaining hours in current shift
 */
function getRemainingShiftHours(currentTime, shiftEnd) {
    return (shiftEnd - currentTime) / 3600000; // Convert ms to hours
}

/**
 * Save all rows to database (1:1 mapping)
 */
async function saveAllRows(autoGenerated = false) {
    try {
        const requestData = {
            machine_number: machineNumber,
            section: sectionCode,
            rows: scheduleRows.map(row => ({
                id: row.id,
                row_no: row.row_no,
                start_datetime: row.start_datetime,
                expected_end_datetime: row.expected_end_datetime,
                end_datetime: row.end_datetime,
                loading_hours: row.loading_hours,
                machine_stop_hours: row.machine_stop_hours
            }))
        };
        
        let data;
        if (typeof makeRequest !== 'undefined') {
            data = await makeRequest('/api/schedule/save-all', {
                method: 'POST',
                body: JSON.stringify(requestData)
            });
        } else {
            const csrfToken = document.querySelector('meta[name=\"csrf-token\"]')?.getAttribute('content') || '';
            const response = await fetch('/api/schedule/save-all', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify(requestData)
            });
            data = await response.json();
        }
        
        if (data.success) {
            // Update scheduleRows with database IDs
            scheduleRows = data.rows.map(row => ({
                id: row.id,
                row_no: row.row_no,
                start_datetime: row.start_datetime,
                expected_end_datetime: row.expected_end_datetime,
                end_datetime: row.end_datetime,
                loading_hours: row.loading_hours,
                machine_stop_hours: row.machine_stop_hours
            }));
            
            renderTable();
            if (!autoGenerated) {
                alert('✅ Schedule saved successfully!');
            }
        } else {
            if (!autoGenerated) {
                alert('❌ Error: ' + data.message);
            }
            console.error('Auto-save failed:', data);
        }
    } catch (error) {
        if (!autoGenerated) {
            alert('❌ Error: ' + error.message);
        }
        console.error('Save error:', error);
    }
}

/**
 * Update machine_stop_hours for a row (with cascade)
 */
async function updateMachineStopHours(rowIndex, newStopHours) {
    const row = scheduleRows[rowIndex];
    
    if (!row.id) {
        alert('Please save the schedule first before editing.');
        return;
    }
    
    newStopHours = parseFloat(newStopHours) || 0;
    if (newStopHours < 0) newStopHours = 0;
    
    // Show saving indicator
    const indicator = document.getElementById(`stop-indicator-${rowIndex}`);
    if (indicator) {
        indicator.textContent = '💾';
        indicator.style.color = '#ffc107';
    }
    
    // Update local data immediately (for UI responsiveness)
    row.machine_stop_hours = newStopHours;
    recalculateFromRow(rowIndex);
    renderTable();
    
    // Update database with cascade
    try {
        const requestData = {
            machine_number: machineNumber,
            section: sectionCode,
            row_id: row.id,
            machine_stop_hours: newStopHours
        };
        
        let data;
        if (typeof makeRequest !== 'undefined') {
            data = await makeRequest('/api/schedule/update-stop-hours', {
                method: 'POST',
                body: JSON.stringify(requestData)
            });
        } else {
            const csrfToken = document.querySelector('meta[name=\"csrf-token\"]')?.getAttribute('content') || '';
            const response = await fetch('/api/schedule/update-stop-hours', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken
                },
                body: JSON.stringify(requestData)
            });
            data = await response.json();
        }
        
        if (data.success) {
            // Sync UI with database response (exact values)
            updateRowsFromDatabase(data.rows);
            renderTable();
            
            if (indicator) {
                indicator.textContent = '✓';
                indicator.style.color = '#28a745';
                setTimeout(() => {
                    indicator.textContent = '';
                }, 2000);
            }
        } else {
            if (indicator) {
                indicator.textContent = '✗';
                indicator.style.color = '#dc3545';
            }
            alert('❌ Update failed: ' + data.message);
        }
    } catch (error) {
        if (indicator) {
            indicator.textContent = '✗';
            indicator.style.color = '#dc3545';
        }
        alert('❌ Error: ' + error.message);
        console.error('Update error:', error);
    }
}

/**
 * Recalculate schedule from a specific row
 * CRITICAL: Maintains shift sequence
 */
function recalculateFromRow(fromRowIndex) {
    if (fromRowIndex < 0 || fromRowIndex >= scheduleRows.length) return;
    
    // Start from first row and keep each row aligned to the next shift boundary
    let currentDatetime = new Date(scheduleRows[0].start_datetime);
    
    for (let i = 0; i < scheduleRows.length; i++) {
        const row = scheduleRows[i];
        const shiftType = getShiftType(currentDatetime);
        const shiftInfo = getShiftBoundaries(currentDatetime, shiftType);
        const remainingInShift = getRemainingShiftHours(currentDatetime, shiftInfo.end);
        
        // Set start_datetime for this shift row
        row.start_datetime = currentDatetime.toISOString();
        
        // Recalculate loading within the current shift block only
        const loadingHours = Math.min(row.loading_hours || 0, remainingInShift, 12);
        row.loading_hours = loadingHours;
        row.shift_type = shiftType;

        if (loadingHours > 0) {
            row.expected_end_datetime = new Date(
                currentDatetime.getTime() + loadingHours * 3600000
            ).toISOString();
        } else {
            row.expected_end_datetime = currentDatetime.toISOString();
        }
        
        // Calculate end_datetime
        const stopHours = row.machine_stop_hours || 0;
        const expectedEnd = new Date(row.expected_end_datetime);
        row.end_datetime = new Date(
            expectedEnd.getTime() + stopHours * 3600000
        ).toISOString();
        
        // Next row always starts at the next shift boundary
        currentDatetime = new Date(shiftInfo.end);
    }
}

/**
 * Sync UI rows with database response
 * Uses EXACT database values (no recalculation)
 */
function updateRowsFromDatabase(dbRows) {
    // Create map of row_id -> database row
    const dbRowMap = new Map();
    dbRows.forEach(dbRow => {
        dbRowMap.set(dbRow.id, dbRow);
    });
    
    // Update scheduleRows with EXACT database values
    scheduleRows.forEach((row, index) => {
        if (row.id && dbRowMap.has(row.id)) {
            const dbRow = dbRowMap.get(row.id);
            
            // Use EXACT database values - no recalculation
            row.start_datetime = dbRow.start_datetime;
            row.expected_end_datetime = dbRow.expected_end_datetime;
            row.end_datetime = dbRow.end_datetime;
            row.machine_stop_hours = dbRow.machine_stop_hours;
        }
    });
    
    // Recalculate to ensure continuation rows are updated
    recalculateFromRow(0);
}

/**
 * Render schedule table (enhanced with shift info)
 */
function renderTable() {
    const tableContainer = document.getElementById('scheduleTable');
    if (!tableContainer) return;
    
    if (scheduleRows.length === 0) {
        tableContainer.innerHTML = '<p>No schedule rows. Generate a schedule first.</p>';
        return;
    }
    
    let html = `
        <h3>Schedule Table (SHIFT MODE - 1:1 with Database) <span id="autoGenStatus" style="color: #28a745; font-size: 0.9em;">🔄 Auto-generation active</span></h3>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th>Row #</th>
                    <th>Shift</th>
                    <th>Start Datetime (UTC)</th>
                    <th>Loading Hours</th>
                    <th>Machine Stop Hours</th>
                    <th>Expected End</th>
                    <th>End Datetime</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    scheduleRows.forEach((row, index) => {
        const startDt = new Date(row.start_datetime);
        const expectedEndDt = new Date(row.expected_end_datetime);
        const endDt = new Date(row.end_datetime);
        const shiftType = row.shift_type || getShiftType(startDt);
        const shiftLabel = shiftType === 'day' ? '🌅 Day' : '🌙 Night';
        
        html += `
            <tr>
                <td>${row.row_no}</td>
                <td>${shiftLabel}</td>
                <td>${formatDateTime(startDt)}</td>
                <td>${row.loading_hours !== null ? row.loading_hours.toFixed(1) : '-'}</td>
                <td>
                    <input type="number" 
                           value="${row.machine_stop_hours}" 
                           step="0.1" 
                           min="0"
                           onchange="updateMachineStopHours(${index}, this.value)"
                           style="width: 80px;">
                    <span id="stop-indicator-${index}"></span>
                </td>
                <td>${formatDateTime(expectedEndDt)}</td>
                <td>${formatDateTime(endDt)}</td>
            </tr>
        `;
    });
    
    html += `
            </tbody>
        </table>
        <div style="margin-top: 10px;">
            <button onclick="saveAllRows()" class="btn btn-success me-2">💾 Manual Save</button>
            <button onclick="toggleAutoGen()" class="btn btn-info">${autoGenInterval ? '⏹️ Stop Auto-Gen' : '▶️ Start Auto-Gen'}</button>
        </div>
    `;
    
    tableContainer.innerHTML = html;
}

/**
 * Format datetime for display
 */
function formatDateTime(date) {
    // Keep clock time, but label DATE using production-day logic (08:00 cutoff, UTC)
    const businessDate = new Date(date);
    businessDate.setUTCHours(0, 0, 0, 0);
    if (date.getUTCHours() < 8) {
        businessDate.setUTCDate(businessDate.getUTCDate() - 1);
    }

    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const month = monthNames[businessDate.getUTCMonth()];
    const day = String(businessDate.getUTCDate()).padStart(2, '0');
    const year = businessDate.getUTCFullYear();

    const hours = String(date.getUTCHours()).padStart(2, '0');
    const minutes = String(date.getUTCMinutes()).padStart(2, '0');

    return `${month} ${day}, ${year}, ${hours}:${minutes} UTC`;
}


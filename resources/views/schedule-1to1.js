/**
 * Schedule 1:1 UI-to-Database Synchronization
 * 
 * Every UI row = One database row
 * Perfect synchronization with cascade updates
 */

// Global variables
let scheduleRows = []; // Array of row objects matching database structure
let machineNumber = null;
let sectionCode = null;

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
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
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
 * Generate new schedule rows
 */
function generateSchedule(date, startHour, loadingTime, numberOfRows) {
    scheduleRows = [];
    
    const startDatetime = new Date(`${date}T${String(startHour).padStart(2, '0')}:00:00Z`);
    let currentDatetime = new Date(startDatetime);
    
    for (let i = 0; i < numberOfRows; i++) {
        const rowNo = i + 1;
        const loadingHours = loadingTime;
        const machineStopHours = 0;
        
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
            machine_stop_hours: machineStopHours
        });
        
        // Next row starts where this one ends
        currentDatetime = new Date(endDatetime);
    }
    
    renderTable();
}

/**
 * Save all rows to database (1:1 mapping)
 */
async function saveAllRows() {
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
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
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
            alert('✅ Schedule saved successfully!');
        } else {
            alert('❌ Error: ' + data.message);
        }
    } catch (error) {
        alert('❌ Error: ' + error.message);
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
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
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
 * CRITICAL: Maintains continuous timeline
 */
function recalculateFromRow(fromRowIndex) {
    if (fromRowIndex < 0 || fromRowIndex >= scheduleRows.length) return;
    
    // Start from first row to maintain continuity
    let currentDatetime = new Date(scheduleRows[0].start_datetime);
    
    for (let i = 0; i < scheduleRows.length; i++) {
        const row = scheduleRows[i];
        
        // Set start_datetime (ensures continuity)
        row.start_datetime = currentDatetime.toISOString();
        
        // Calculate expected_end_datetime
        const loadingHours = row.loading_hours || 0;
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
        
        // Next row starts where this one ends (CRITICAL: maintains continuity)
        currentDatetime = new Date(row.end_datetime);
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
 * Render schedule table
 */
function renderTable() {
    const tableContainer = document.getElementById('scheduleTable');
    if (!tableContainer) return;
    
    if (scheduleRows.length === 0) {
        tableContainer.innerHTML = '<p>No schedule rows. Generate a schedule first.</p>';
        return;
    }
    
    let html = `
        <h3>Schedule Table (1:1 with Database)</h3>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th>Row #</th>
                    <th>Start Datetime (UTC)</th>
                    <th>Loading Hours</th>
                    <th>Machine Stop Hours</th>
                    <th>Expected End Datetime</th>
                    <th>End Datetime</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    scheduleRows.forEach((row, index) => {
        const startDt = new Date(row.start_datetime);
        const expectedEndDt = new Date(row.expected_end_datetime);
        const endDt = new Date(row.end_datetime);
        
        html += `
            <tr>
                <td>${row.row_no}</td>
                <td>${formatDateTime(startDt)}</td>
                <td>${row.loading_hours !== null ? row.loading_hours : '-'}</td>
                <td>
                    <input type="number" 
                           value="${row.machine_stop_hours}" 
                           step="0.01" 
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
        <button onclick="saveAllRows()" class="btn btn-success">Save All Rows</button>
    `;
    
    tableContainer.innerHTML = html;
}

/**
 * Format datetime for display
 */
function formatDateTime(date) {
    return date.toISOString().replace('T', ' ').substring(0, 19) + ' UTC';
}

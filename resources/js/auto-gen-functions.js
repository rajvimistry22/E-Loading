// Auto-generation functions for shift schedule

/**
 * Check if we need to generate next batch
 * Returns true if current time >= last row's end_datetime
 */
function shouldGenerateNextBatch() {
    if (scheduleRows.length === 0) return false;
    
    const lastRow = scheduleRows[scheduleRows.length - 1];
    const lastEnd = new Date(lastRow.end_datetime);
    const now = new Date();
    
    return now >= lastEnd;
}

/**
 * Generate next batch of rows (5 rows)
 * Continues from last row's end_datetime
 */
async function generateNextBatch() {
    if (!shouldGenerateNextBatch()) return;
    
    console.log('🕐 Reached end of schedule. Auto-generating next 5 rows...');
    
    const lastRow = scheduleRows[scheduleRows.length - 1];
    const currentDatetime = new Date(lastRow.end_datetime);
    const remainingLoadingTime = 24.0; // Assume 24h more loading - adjust based on inputs if available
    
    const batchSize = 5;
    const startRowNo = scheduleRows.length + 1;
    
    for (let i = 0; i < batchSize; i++) {
        const rowNo = startRowNo + i;
        const machineStopHours = 0; // Default 0 for auto-gen
        
        // Determine current shift type
        const shiftType = getShiftType(currentDatetime);
        const shiftInfo = getShiftBoundaries(currentDatetime, shiftType);
        const remainingInShift = getRemainingShiftHours(currentDatetime, shiftInfo.end);
        
        // Calculate shift hours: min(remainingLoading, remainingInShift, 12)
        let shiftHours = Math.min(remainingLoadingTime, remainingInShift, 12);
        const loadingHours = shiftHours > 0 ? shiftHours : 0;
        
        // Calculate datetimes
        const expectedEndDatetime = new Date(currentDatetime.getTime() + loadingHours * 3600000);
        const endDatetime = new Date(expectedEndDatetime.getTime() + machineStopHours * 3600000);
        
        // Append new row
        scheduleRows.push({
            id: null,
            row_no: rowNo,
            start_datetime: currentDatetime.toISOString(),
            expected_end_datetime: expectedEndDatetime.toISOString(),
            end_datetime: endDatetime.toISOString(),
            loading_hours: loadingHours,
            machine_stop_hours: machineStopHours,
            shift_type: shiftType
        });
        
        // Update for next iteration
        remainingLoadingTime -= shiftHours;
        currentDatetime = new Date(shiftInfo.end);
    }
    
    // Auto-save the new batch
    await saveAllRows(true); // true = auto-generated, no alert
    
    renderTable();
    console.log(`✅ Auto-generated and saved ${batchSize} new rows`);
}

/**
 * Toggle auto-generation monitoring
 */
function toggleAutoGen() {
    if (autoGenInterval) {
        clearInterval(autoGenInterval);
        autoGenInterval = null;
        document.getElementById('autoGenStatus').textContent = '⏹️ Auto-generation stopped';
        document.getElementById('autoGenStatus').style.color = '#dc3545';
        console.log('⏹️ Auto-generation monitoring stopped');
    } else {
        startAutoGeneration();
        console.log('▶️ Auto-generation monitoring started');
    }
}

/**
 * Start auto-generation interval (check every 30 seconds)
 */
function startAutoGeneration() {
    autoGenInterval = setInterval(() => {
        generateNextBatch();
    }, 30000); // Check every 30 seconds
    
    document.getElementById('autoGenStatus').textContent = '🔄 Auto-generation active (30s checks)';
    document.getElementById('autoGenStatus').style.color = '#28a745';
}

/**
 * Stop auto-generation monitoring
 */
function stopAutoGeneration() {
    if (autoGenInterval) {
        clearInterval(autoGenInterval);
        autoGenInterval = null;
        console.log('⏹️ Auto-generation stopped');
    }
}


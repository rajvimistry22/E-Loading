@extends('layouts.app')

@section('title', 'Schedule Generator')

@section('content')
<h2 class="text-center" style="color: blue; font-weight: bold;">
    Schedule for {{ $machine->name }} → {{ $section->name }}
</h2>
@php
    // Extract machine number from machine name (e.g., "M-3" -> 3)
    preg_match('/M-?(\d+)/', $machine->name, $matches);
    $machineNumber = isset($matches[1]) ? (int) $matches[1] : null;
    // Convert section name format: A-OUT -> AOUT
    $sectionCode = str_replace('-', '', strtoupper($section->name));
    $tableName = $machineNumber ? "M{$machineNumber}_{$sectionCode}" : 'N/A';
@endphp
<div style="background-color: #e3f2fd; padding: 10px; border-radius: 4px; margin-bottom: 20px; text-align: center;">
    <strong>Data will be saved to table:</strong> 
    <code style="background-color: #fff; padding: 4px 8px; border-radius: 3px; font-size: 16px;">
        {{ $tableName }}
    </code>
    <span style="color: #666; font-size: 14px; margin-left: 10px;">
        (All schedule data for this machine-section is stored in this table only)
    </span>
</div>
    <div class="form-group">
        <label>Date:</label>
        <input type="date" id="date" class="form-control" required>
    </div>

    <div class="form-group">
        <label for="startHour">Start Hour (0–23):</label>
        <select id="startHour" class="form-control" required>
            @for($i = 0; $i < 24; $i++)
                <option value="{{ $i }}">{{ str_pad($i, 2, '0', STR_PAD_LEFT) }}</option>
            @endfor
        </select>
    </div>

    <div class="form-group">
        <label>Loading Time (hrs):</label>
        <input type="number" id="loadingTime" step="0.01" min="0.01" placeholder="Enter loading time" class="form-control" required>
    </div>

    <div class="form-group">
        <label>Number of Rows:</label>
        <input type="number" id="rows" min="1" max="1000" placeholder="Enter number of rows" class="form-control" required>
    </div>

    <div class="form-group">
        <button id="generateBtn" class="btn btn-success" onclick="generateSchedule()" disabled>Generate</button>
        <button id="saveBtn" class="btn btn-success" onclick="saveChallans()" style="display: none;">Save Challans</button>
    </div>

    <div id="scheduleTable" style="overflow-x: auto; max-height: 600px; overflow-y: auto; margin-top: 20px;"></div>

    <!-- <h3 style="margin-top: 40px;">Delete Schedule Entries</h3>
    <div style="display: flex; flex-wrap: wrap; gap: 10px; align-items: center;">
        <div class="form-group" style="margin: 0;">
            <label>From Date:</label>
            <input type="date" id="deleteFromDate" class="form-control">
        </div>
        <div class="form-group" style="margin: 0;">
            <label>From Time (HH:MM):</label>
            <input type="time" id="deleteFromTime" class="form-control">
        </div>
        <div class="form-group" style="margin: 0;">
            <label>To Date:</label>
            <input type="date" id="deleteToDate" class="form-control">
        </div>
        <div class="form-group" style="margin: 0;">
            <label>To Time (HH:MM):</label>
            <input type="time" id="deleteToTime" class="form-control">
        </div>
        <div class="form-group" style="margin: 0;">
            <button onclick="deleteRange()" class="btn btn-danger">Delete</button>
        </div>
    </div> -->

@endsection

@push('scripts')
<script>
    const machineId = {{ $machine->id }};
    const sectionName = '{{ $section->name }}';
    // ============================================================================
    // DAY-BASED SCHEDULING SYSTEM - SINGLE SOURCE OF TRUTH
    // ============================================================================
    
    // Schedule rows: Each element represents ONE calendar day
    // Structure: { dateKey: 'YYYY-MM-DD', start_datetime: ISO string, loading_hours: number, stop_hours: number, expected_end: ISO string }
    let scheduleRows = [];
    
    // SINGLE SOURCE OF TRUTH: Current datetime flowing through the schedule
    let currentDateTime = null;
    
    // Keep generatedChallans for backward compatibility with save function
    let generatedChallans = [];
    
    // Map to track which database record ID corresponds to which schedule row
    // Format: { rowIndex: databaseRecordId }
    let rowToRecordMap = new Map();

    // Load existing challans on page load
    window.addEventListener('DOMContentLoaded', function() {
        loadExistingChallans();
        loadExistingSchedule(); // Load and regenerate schedule table from database
    });

    // Enable/disable generate button based on form completion
    document.addEventListener('DOMContentLoaded', function() {
        const inputs = ['date', 'startHour', 'loadingTime', 'rows'];
        inputs.forEach(id => {
            const input = document.getElementById(id);
            if (input) {
                input.addEventListener('input', checkFormCompletion);
                input.addEventListener('change', checkFormCompletion);
            }
        });
        checkFormCompletion(); // Check on page load
    });

    // Helper function to format date and time consistently
    // CRITICAL: Use UTC methods to avoid timezone conversion issues
    // Dates from database are in UTC, display them in UTC to match database
    function formatDateTime(date) {
        // Use UTC methods to get date components (prevents timezone conversion)
        const year = date.getUTCFullYear();
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const month = monthNames[date.getUTCMonth()];
        const day = String(date.getUTCDate()).padStart(2, '0');
        const hours = String(date.getUTCHours()).padStart(2, '0');
        const minutes = String(date.getUTCMinutes()).padStart(2, '0');
        return `${month} ${day}, ${year}, ${hours}:${minutes}`;
    }

    // Helper function to format date only (without time)
    // Use UTC methods to avoid timezone conversion
    function formatDateOnly(date) {
        const year = date.getUTCFullYear();
        const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const month = monthNames[date.getUTCMonth()];
        const day = String(date.getUTCDate()).padStart(2, '0');
        return `${month} ${day}, ${year}`;
    }

    function checkFormCompletion() {
        const date = document.getElementById('date').value;
        const loadingTime = document.getElementById('loadingTime').value;
        const rows = document.getElementById('rows').value;
        const generateBtn = document.getElementById('generateBtn');
        
        if (date && loadingTime && rows && parseFloat(loadingTime) > 0 && parseInt(rows) > 0) {
            generateBtn.disabled = false;
        } else {
            generateBtn.disabled = true;
        }
    }

    async function loadExistingChallans() {
        try {
            const data = await makeRequest(`/api/challans?machine_id=${machineId}&section_name=${encodeURIComponent(sectionName)}`);
            
            const existingChallansDiv = document.getElementById('existingChallans');
            
            // Check if element exists before setting innerHTML
            if (!existingChallansDiv) {
                console.warn('existingChallans element not found on page');
                return;
            }
            
            if (data.length > 0) {
                let html = '<h3>Existing Challans</h3><table><tr><th>Start Time</th><th>End Time</th><th>Loading Duration (hrs)</th><th>Actions</th></tr>';
                
                data.forEach(challan => {
                    const startTime = new Date(challan.start_time).toLocaleString();
                    const endTime = new Date(challan.end_time).toLocaleString();
                    html += `
                        <tr>
                            <td>${startTime}</td>
                            <td>${endTime}</td>
                            <td>${challan.loading_duration}</td>
                            <td>
                                <button onclick="editChallan(${challan.id})" class="btn btn-primary" style="padding: 5px 10px; font-size: 12px;">Edit</button>
                            </td>
                        </tr>
                    `;
                });
                
                html += '</table>';
                existingChallansDiv.innerHTML = html;
            } else {
                existingChallansDiv.innerHTML = '<p style="color: #666; font-style: italic;">No existing challans found for this machine and section.</p>';
            }
        } catch (error) {
            console.error('Error loading challans:', error);
            const existingChallansDiv = document.getElementById('existingChallans');
            if (existingChallansDiv) {
                existingChallansDiv.innerHTML = `<p style="color: #dc3545;">Error loading challans: ${error.message}</p>`;
            }
        }
    }

    /**
     * Load existing schedule from database and regenerate the table
     */
    async function loadExistingSchedule() {
        try {
            const data = await makeRequest(`/api/challans?machine_id=${machineId}&section_name=${encodeURIComponent(sectionName)}`);
            
            if (data.length > 0) {
                // CRITICAL: Load ALL rows directly from database (1:1 mapping)
                // No regeneration needed - database rows match UI rows exactly
                scheduleRows = [];
                rowToRecordMap.clear();
                
                // CRITICAL: Load EXACT database values - no recalculation, no modification
                // Convert database records directly to scheduleRows (1:1 mapping)
                data.forEach((record, index) => {
                    // Use EXACT database values - parse ISO strings as UTC
                    const startTime = new Date(record.start_time);
                    const endTime = new Date(record.end_time);
                    
                    // Use EXACT expected_end_datetime from database (if provided)
                    // DO NOT recalculate - use database value exactly
                    let expectedEnd = record.expected_end_time 
                        ? new Date(record.expected_end_time)
                        : startTime; // Fallback only if missing
                    
                    // Extract date key from start_datetime (UTC) - for reference only
                    // CRITICAL: This is ONLY for reference, NOT for display manipulation
                    const dateKey = startTime.toISOString().split('T')[0];
                    
                    // Create row EXACTLY as stored in database - no modification
                    // All datetime values are EXACT database values
                    const row = {
                        dateKey: dateKey, // For reference only, not used for display
                        start_datetime: startTime.toISOString(), // EXACT DB value - display as-is
                        end_datetime: endTime.toISOString(), // EXACT DB value - display as-is
                        expected_end: expectedEnd.toISOString(), // EXACT DB value - display as-is
                        loading_hours: record.loading_duration !== null ? record.loading_duration : null, // EXACT DB value
                        stop_hours: record.machine_stop_time || 0, // EXACT DB value
                        challan_index: null, // Will be determined if needed
                        record_id: record.id
                    };
                    
                    scheduleRows.push(row);
                    rowToRecordMap.set(index, record.id);
                });
                
                // Set form values from first record
                const firstRecord = data[0];
                if (firstRecord && firstRecord.start_time) {
                    const startDate = new Date(firstRecord.start_time);
                    const startHour = startDate.getHours();
                    
                    // Use UTC methods to extract date and hour
                    document.getElementById('date').value = startDate.toISOString().split('T')[0];
                    document.getElementById('startHour').value = startDate.getUTCHours(); // Use UTC hour
                    
                    // Try to determine loading time and number of rows
                    // Count rows with loading_duration > 0 as "challans"
                    const challanRows = data.filter(r => r.loading_duration !== null && r.loading_duration > 0);
                    if (challanRows.length > 0) {
                        document.getElementById('loadingTime').value = challanRows[0].loading_duration;
                        document.getElementById('rows').value = challanRows.length;
                    }
                }
                
                // Render the table with loaded data
                renderScheduleTable();
                
                console.log(`✓ Loaded ${data.length} rows from database (1:1 mapping)`);
            }
        } catch (error) {
            console.error('Error loading existing schedule:', error);
        }
    }

    /**
     * Regenerate scheduleRows from database records
     */
    function regenerateScheduleFromRecords(records) {
        scheduleRows = [];
        generatedChallans = [];
        rowToRecordMap.clear();

        if (records.length === 0) return;

        // Initialize currentDateTime from first record
        const firstRecord = records[0];
        currentDateTime = new Date(firstRecord.start_time);

        // Build generatedChallans from records
        // IMPORTANT: Use the database's start_time and end_time directly
        // The end_time in database already includes machine_stop_time
        // Handle ALL rows (including intermediate rows with null loading_duration)
        records.forEach((record, index) => {
            const startTime = new Date(record.start_time);
            const endTime = new Date(record.end_time);
            // Handle null loading_duration (for intermediate rows)
            const loadingTime = record.loading_duration !== null && record.loading_duration !== undefined 
                ? record.loading_duration 
                : null; // Keep as null, don't convert to 0
            const stopTime = record.machine_stop_time || 0;

            // Calculate expected_end_time - handle null loadingTime
            let expectedEndTime;
            if (loadingTime !== null && loadingTime > 0) {
                expectedEndTime = new Date(startTime.getTime() + loadingTime * 3600000).toISOString();
            } else {
                // For intermediate rows, expected_end = start
                expectedEndTime = startTime.toISOString();
            }
            
            generatedChallans.push({
                start_time: startTime.toISOString(),
                end_time: endTime.toISOString(),
                expected_end_time: expectedEndTime,
                loading_duration: loadingTime, // Can be null for intermediate rows
                machine_stop_time: stopTime,
                challan_index: index,
                record_id: record.id
            });
        });

        // Generate all calendar days from first to last record
        const firstDate = new Date(records[0].start_time);
        const lastRecord = records[records.length - 1];
        const lastDate = new Date(lastRecord.end_time);

        // Build date range
        const allDates = [];
        const checkDate = new Date(firstDate);
        checkDate.setUTCHours(0, 0, 0, 0); // Use UTC methods
        const endDate = new Date(lastDate);
        endDate.setUTCHours(23, 59, 59, 999); // Use UTC methods

        while (checkDate <= endDate) {
            allDates.push(new Date(checkDate));
            checkDate.setUTCDate(checkDate.getUTCDate() + 1); // Use UTC methods
        }

        // Build challansByStartDate and challansByDateRange
        const challansByStartDate = new Map();
        const challansByDateRange = new Map();

        generatedChallans.forEach((challan, idx) => {
            const startDate = new Date(challan.start_time);
            const endDate = new Date(challan.end_time);
            const startDateKey = startDate.toISOString().split('T')[0];
            const endDateKey = endDate.toISOString().split('T')[0];

            challansByStartDate.set(startDateKey, { challan, idx });

            // Mark all dates this challan spans
            const checkDate = new Date(startDate);
            checkDate.setHours(0, 0, 0, 0);
            const checkEndDate = new Date(endDate);
            checkEndDate.setHours(23, 59, 59, 999);

            while (checkDate <= checkEndDate) {
                const dateKey = checkDate.toISOString().split('T')[0];
                if (!challansByDateRange.has(dateKey)) {
                    challansByDateRange.set(dateKey, []);
                }
                const isStart = dateKey === startDateKey;
                const isEnd = dateKey === endDateKey;
                challansByDateRange.get(dateKey).push({ challan, idx, isStart, isEnd });
                checkDate.setUTCDate(checkDate.getUTCDate() + 1); // Use UTC methods
            }
        });

        // Reset currentDateTime to first record's start
        currentDateTime = new Date(firstRecord.start_time);

        // Build scheduleRows for all dates
        // CRITICAL: Use day-by-day cascade to ensure machine_stop_time is applied correctly
        allDates.forEach((dateObj) => {
            const dateKey = dateObj.toISOString().split('T')[0];
            const challanInfo = challansByStartDate.get(dateKey);
            const challansForDate = challansByDateRange.get(dateKey) || [];

            let activeChallan = null;
            let activeChallanIndex = null;

            if (challanInfo) {
                activeChallan = challanInfo.challan;
                activeChallanIndex = challanInfo.idx;
            } else if (challansForDate.length > 0) {
                const activeChallanInfo = challansForDate.find(c => !c.isEnd) || challansForDate[0];
                if (activeChallanInfo) {
                    activeChallan = activeChallanInfo.challan;
                    activeChallanIndex = activeChallanInfo.idx;
                }
            }

            const row = {
                dateKey: dateKey,
                start_datetime: currentDateTime.toISOString(),
                loading_hours: 0,
                stop_hours: 0,
                expected_end: currentDateTime.toISOString(),
                end_datetime: currentDateTime.toISOString(),
                challan_index: activeChallanIndex
            };

            if (challanInfo) {
                // This is the start of a new challan
                // CRITICAL: Use EXACT values from database for synchronization
                const challan = challanInfo.challan;
                
                // Use database values directly
                row.loading_hours = parseFloat(challan.loading_duration) || 0;
                row.stop_hours = parseFloat(challan.machine_stop_time) || 0;
                
                // Use EXACT start_time from database
                const dbStartTime = new Date(challan.start_time);
                row.start_datetime = dbStartTime.toISOString();
                currentDateTime = dbStartTime;
                
                // Use EXACT end_time from database
                const dbEndTime = new Date(challan.end_time);
                row.end_datetime = dbEndTime.toISOString();
                
                // Calculate Expected End = Start + Loading (NOT including stop time)
                // This should match the database's expected_end_time if available
                if (challan.expected_end_time) {
                    row.expected_end = new Date(challan.expected_end_time).toISOString();
                } else {
                    // Calculate: Start + Loading Time only
                    row.expected_end = addHours(dbStartTime, row.loading_hours).toISOString();
                }
                
                // Move currentDateTime to database end_time for next row
                currentDateTime = dbEndTime;
            } else if (activeChallan) {
                // Continuation row - use database values
                const challan = activeChallan;
                
                // Continuation rows don't have loading hours
                row.loading_hours = 0;
                // Stop hours on continuation rows are 0 (can be edited)
                row.stop_hours = 0;
                
                // Expected end is from the challan's expected_end_time
                if (challan.expected_end_time) {
                    row.expected_end = new Date(challan.expected_end_time).toISOString();
                } else {
                    // Calculate from start + loading
                    const challanStart = new Date(challan.start_time);
                    const loadingHours = parseFloat(challan.loading_duration) || 0;
                    row.expected_end = addHours(challanStart, loadingHours).toISOString();
                }
                
                // End datetime continues the chain
                row.end_datetime = addHours(currentDateTime, row.stop_hours).toISOString();
                currentDateTime = parseDate(row.end_datetime);
            } else {
                // Empty day - no challan active
                row.expected_end = currentDateTime.toISOString();
                row.end_datetime = currentDateTime.toISOString();
                // currentDateTime stays the same for empty days
            }

            scheduleRows.push(row);
        });
    }

    /**
     * Generate schedule using DAY-BASED algorithm
     * Each row represents ONE calendar day
     * SINGLE SOURCE OF TRUTH: currentDateTime flows through all rows
     */
    function generateSchedule() {
        const date = document.getElementById('date').value;
        const startHour = parseInt(document.getElementById('startHour').value);
        const loadingTime = parseFloat(document.getElementById('loadingTime').value);
        const numberOfRows = parseInt(document.getElementById('rows').value);

        if (!date || !loadingTime || !numberOfRows) {
            alert("Please fill all fields correctly.");
            return;
        }

        // Initialize: SINGLE SOURCE OF TRUTH
        // CRITICAL: Create date in UTC to avoid timezone conversion issues
        // User input (date + hour) should be interpreted as UTC
        const initialStartDateTime = new Date(`${date}T${String(startHour).padStart(2, '0')}:00:00Z`);
        currentDateTime = new Date(initialStartDateTime);
        
        // Reset schedule rows
        scheduleRows = [];
        generatedChallans = [];

        // Step 1: Generate initial schedule rows using the algorithm
        // For each "challan" (numberOfRows), create day-based rows
        for (let i = 0; i < numberOfRows; i++) {
            // Calculate where this challan will end
            const stopHours = 0; // Initial stop time
            const expectedEndDateTime = addHours(currentDateTime, loadingTime + stopHours);
            
            // Create challan data (for saving to backend)
            generatedChallans.push({
                start_time: currentDateTime.toISOString(),
                end_time: expectedEndDateTime.toISOString(),
                expected_end_time: addHours(currentDateTime, loadingTime).toISOString(),
                loading_duration: loadingTime,
                machine_stop_time: stopHours,
                challan_index: i
            });
            
            // Move currentDateTime forward for next challan
            currentDateTime = new Date(expectedEndDateTime);
        }

        // Step 2: Get all calendar days from first row start to last row end
        // CRITICAL: ALL days must be present, no skipping
        // We need to include ALL calendar days that the schedule spans
        const firstDate = new Date(initialStartDateTime);
        const lastChallan = generatedChallans[generatedChallans.length - 1];
        const lastDate = new Date(lastChallan.end_time); // Use the actual end time of last challan
        
        // Get the date-only portion (YYYY-MM-DD) for comparison
        const firstDateOnly = new Date(firstDate);
        firstDateOnly.setUTCHours(0, 0, 0, 0); // Use UTC methods
        const lastDateOnly = new Date(lastDate);
        lastDateOnly.setUTCHours(0, 0, 0, 0); // Use UTC methods
        
        const allDates = [];
        let checkDate = new Date(firstDateOnly);
        
        // CRITICAL: Include ALL days from first to last (inclusive)
        // This ensures Jan 01, Jan 02, Jan 03, Jan 04 are all shown when a challan spans those days
        // Use <= to include the last day
        while (checkDate <= lastDateOnly) {
            allDates.push(new Date(checkDate));
            // Move to next day
            checkDate = new Date(checkDate);
            checkDate.setUTCDate(checkDate.getUTCDate() + 1); // Use UTC methods
        }
        
        // Verify we have all dates (for debugging)
        if (allDates.length === 0) {
            console.error('ERROR: allDates array is empty!');
            console.error('First date:', firstDateOnly.toISOString());
            console.error('Last date:', lastDateOnly.toISOString());
        } else {
            console.log('✅ Generated', allDates.length, 'calendar days from', 
                allDates[0].toISOString().split('T')[0], 'to', 
                allDates[allDates.length - 1].toISOString().split('T')[0]);
            console.log('All dates:', allDates.map(d => d.toISOString().split('T')[0]).join(', '));
        }

        // Step 3: Build scheduleRows - ONE row per calendar day
        // CRITICAL: Each calendar day gets its own row for day-by-day cascade
        // When stop time changes on Jan 01, it affects Jan 02, then Jan 03, then Jan 04 (not direct jump)
        
        // Reset currentDateTime to start (SINGLE SOURCE OF TRUTH)
        currentDateTime = new Date(initialStartDateTime);
        
        // Map challans to their start dates for lookup
        const challansByStartDate = new Map();
        generatedChallans.forEach((challan, idx) => {
            const startDateKey = new Date(challan.start_time).toISOString().split('T')[0];
            challansByStartDate.set(startDateKey, { challan, idx });
        });
        
        // Create schedule rows for ALL calendar days using day-by-day algorithm
        // KEY: Each day's end_datetime becomes the next day's start_datetime (day-by-day chain)
        // CRITICAL: Track which challan is active on each day (for multi-day challans)
        // Build a map of all dates that fall within each challan's duration
        const challansByDateRange = new Map();
        generatedChallans.forEach((challan, idx) => {
            const startDate = new Date(challan.start_time);
            const endDate = new Date(challan.end_time);
            const startDateOnly = new Date(startDate);
            startDateOnly.setUTCHours(0, 0, 0, 0); // Use UTC methods
            const endDateOnly = new Date(endDate);
            endDateOnly.setUTCHours(0, 0, 0, 0); // Use UTC methods
            
            // Add all dates from start to end (inclusive) to the map
            let checkDate = new Date(startDateOnly);
            while (checkDate <= endDateOnly) {
                const dateKey = checkDate.toISOString().split('T')[0];
                if (!challansByDateRange.has(dateKey)) {
                    challansByDateRange.set(dateKey, []);
                }
                challansByDateRange.get(dateKey).push({
                    challan: challan,
                    idx: idx,
                    isStart: checkDate.getTime() === startDateOnly.getTime(),
                    isEnd: checkDate.getTime() === endDateOnly.getTime()
                });
                checkDate = new Date(checkDate);
                checkDate.setUTCDate(checkDate.getUTCDate() + 1); // Use UTC methods
            }
        });
        
        allDates.forEach((dateObj) => {
            const dateKey = dateObj.toISOString().split('T')[0];
            const challanInfo = challansByStartDate.get(dateKey);
            const challansForDate = challansByDateRange.get(dateKey) || [];
            
            // Find the active challan for this date (prioritize starting challan, then any active challan)
            let activeChallan = null;
            let activeChallanIndex = null;
            
            if (challanInfo) {
                // This date has a challan starting
                activeChallan = challanInfo.challan;
                activeChallanIndex = challanInfo.idx;
            } else if (challansForDate.length > 0) {
                // This date is within a challan's duration (continuation day)
                // Use the first challan that's active on this date
                const activeChallanInfo = challansForDate.find(c => !c.isEnd) || challansForDate[0];
                if (activeChallanInfo) {
                    activeChallan = activeChallanInfo.challan;
                    activeChallanIndex = activeChallanInfo.idx;
                }
            }
            
            // Initialize row with currentDateTime as start (ALGORITHM STEP 1)
            // currentDateTime comes from previous day's end_datetime (day-by-day chain)
            const row = {
                dateKey: dateKey,
                start_datetime: currentDateTime.toISOString(), // Previous day's end becomes this day's start
                loading_hours: 0,
                stop_hours: 0,
                expected_end: currentDateTime.toISOString(), // Expected End (without stop time)
                end_datetime: currentDateTime.toISOString(), // End (with stop time) - used for chain
                challan_index: activeChallanIndex
            };
            
            if (challanInfo) {
                // This date has a challan starting
                const { challan, idx } = challanInfo;
                row.loading_hours = parseFloat(challan.loading_duration) || 0; // Show loading hours on start day
                row.stop_hours = parseFloat(challan.machine_stop_time) || 0; // Initial stop time
                // For start day, use the challan's actual start time
                row.start_datetime = challan.start_time;
                currentDateTime = parseDate(challan.start_time);
            } else if (activeChallan) {
                // This is a continuation day of an active challan
                // CRITICAL: For continuation days, start_datetime should be on the calendar date (dateKey)
                // but the time should continue from the previous day's end time
                // This ensures the date is correct (Jan 22, Jan 23, etc.) while maintaining time continuity
                const currentDateKey = currentDateTime.toISOString().split('T')[0];
                
                // Create a datetime on the calendar date (dateKey) with the time from currentDateTime
                const continuationStart = new Date(dateKey + 'T00:00:00');
                continuationStart.setHours(
                    currentDateTime.getHours(), 
                    currentDateTime.getMinutes(), 
                    currentDateTime.getSeconds(), 
                    currentDateTime.getMilliseconds()
                );
                
                // If the previous day ended on a different calendar date, use the continuation start
                // Otherwise, use currentDateTime (which should already be on the right date)
                if (currentDateKey !== dateKey) {
                    row.start_datetime = continuationStart.toISOString();
                    currentDateTime = continuationStart;
                } else {
                    row.start_datetime = currentDateTime.toISOString();
                }
                
                row.loading_hours = 0; // Continuation days don't show loading hours
                row.stop_hours = 0; // Can be edited per day
                // For continuation days, expected_end should be the challan's expected end time
                row.expected_end = activeChallan.expected_end_time;
            }
            // If no active challan, loading_hours = 0, stop_hours = 0 (empty row)
            
            // ALGORITHM STEP 2: Calculate expected_end and end_datetime
            if (challanInfo) {
                // Start day: Expected End = Start + Loading (NOT affected by stop time)
                row.expected_end = addHours(currentDateTime, row.loading_hours).toISOString();
                // End = Start + Loading + Stop (synchronized with stop time)
                row.end_datetime = addHours(currentDateTime, row.loading_hours + row.stop_hours).toISOString();
            } else if (activeChallan) {
                // Continuation day: Expected end is already set to challan's expected end
                // End datetime continues the chain - just add stop hours to current time
                // This ensures the day-by-day chain continues properly
                row.end_datetime = addHours(currentDateTime, row.stop_hours).toISOString();
            } else {
                // Empty day: No loading or stop time, just pass through
                row.expected_end = currentDateTime.toISOString();
                row.end_datetime = currentDateTime.toISOString();
            }
            
            // ALGORITHM STEP 3: currentDateTime = row.end_datetime (for next day)
            // Next day's start = this day's end (CHAIN) - ensures day-by-day cascade
            // Jan 01 end -> Jan 02 start -> Jan 02 end -> Jan 03 start -> Jan 03 end -> Jan 04 start
            currentDateTime = parseDate(row.end_datetime);
            
            scheduleRows.push(row);
        });
        
        // Debug: Verify all dates are in scheduleRows
        console.log('Total scheduleRows created:', scheduleRows.length);
        console.log('Date keys in scheduleRows:', scheduleRows.map(r => r.dateKey));
        console.log('First 10 rows:', scheduleRows.slice(0, 10).map(r => ({
            dateKey: r.dateKey,
            start_datetime: r.start_datetime,
            loading_hours: r.loading_hours
        })));

        // Step 4: Render table from scheduleRows (ONE row per calendar day)
        renderScheduleTable();
        
        // Show Save Challans button after generation
        document.getElementById('saveBtn').style.display = 'inline-block';
    }

    /**
     * Render the schedule table from scheduleRows array
     * Each row represents ONE calendar day
     */
    function renderScheduleTable() {
        if (!scheduleRows || scheduleRows.length === 0) {
            document.getElementById('scheduleTable').innerHTML = '';
            return;
        }
        
        // Debug: Verify we're rendering all rows
        console.log('Rendering table with', scheduleRows.length, 'rows');

        let tableHTML = `
            <h3>Generated Schedule (All Dates):</h3>
            <table style="width: 100%; border-collapse: collapse; min-width: 800px;">
                <thead style="position: sticky; top: 0; background-color: #f0f0f0; z-index: 10;">
                    <tr>
                        <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">Start Date & Time</th>
                        <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">Loading Time</th>
                        <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">Machine Stop Time</th>
                        <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">Expected End Date and Time</th>
                        <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">End Date and Time</th>
                    </tr>
                </thead>
                <tbody>
        `;

        // Render ONE row per calendar day from scheduleRows
        // Each row in scheduleRows represents ONE calendar day
        // CRITICAL: ALL rows must be rendered, no filtering
        scheduleRows.forEach((row, rowIndex) => {
            // CRITICAL: Always show the calendar date (dateKey) for each row to ensure all dates are visible
            // For continuation days, this ensures Jan 22, Jan 23, etc. are shown even if start_datetime spans dates
            const startDateTime = parseDate(row.start_datetime);
            let displayStartTime;
            
            // Check if this is a continuation day (has active challan but no loading hours)
            const isContinuationDay = row.challan_index !== null && row.challan_index !== undefined && row.loading_hours === 0;
            
            // Get the date from dateKey (calendar date) and time from start_datetime
            // Use UTC methods to avoid timezone conversion
            const calendarDate = new Date(row.dateKey + 'T00:00:00Z'); // Explicitly UTC
            const startDateKey = startDateTime.toISOString().split('T')[0];
            
            // If the start_datetime's date matches the calendar date, use it as is
            // Otherwise, combine calendar date with start_datetime's time (for continuation days)
            if (startDateKey === row.dateKey) {
                displayStartTime = formatDate(startDateTime);
            } else {
                // For continuation days where time has crossed calendar boundaries,
                // show the calendar date with the time from start_datetime (using UTC)
                calendarDate.setUTCHours(
                    startDateTime.getUTCHours(), 
                    startDateTime.getUTCMinutes(), 
                    startDateTime.getUTCSeconds(), 
                    startDateTime.getUTCMilliseconds()
                );
                displayStartTime = formatDate(calendarDate);
            }
            
            // Expected End Date and Time: Start + Loading only (NOT affected by stop time)
            const expectedEndDateTime = parseDate(row.expected_end);
            const expectedEndStr = formatDate(expectedEndDateTime);
            
            // End Date and Time: Start + Loading + Stop (synchronized with stop time)
            // CRITICAL: This end_datetime will become the next row's start_datetime (perfect chain)
            const endDateTime = parseDate(row.end_datetime);
            const endStr = formatDate(endDateTime);
            
            // Verification: For debugging - check if this row's end matches next row's start
            if (rowIndex < scheduleRows.length - 1) {
                const nextRow = scheduleRows[rowIndex + 1];
                const nextStartDateTime = parseDate(nextRow.start_datetime);
                if (endDateTime.getTime() !== nextStartDateTime.getTime()) {
                    console.warn(`⚠️ Row ${rowIndex} end (${endStr}) does not match Row ${rowIndex + 1} start (${formatDate(nextStartDateTime)})`);
                }
            }
            
            // Determine if this row has loading hours (not empty or null)
            const hasLoading = row.loading_hours !== null && row.loading_hours !== undefined && row.loading_hours > 0;
            const loadingDisplay = hasLoading ? row.loading_hours : '-';
            
            // Row styling - empty rows (no loading) are slightly faded
            const rowStyle = `background-color: ${rowIndex % 2 === 0 ? '#fff' : '#f9f9f9'}; ${!hasLoading ? 'opacity: 0.6;' : ''}`;
            
            tableHTML += `
                <tr data-row-index="${rowIndex}" data-date-key="${row.dateKey}" style="${rowStyle}">
                    <td class="start-datetime-cell" style="padding: 8px; border: 1px solid #ccc; text-align: center; white-space: nowrap; font-weight: ${hasLoading ? 'bold' : 'normal'};">
                        ${displayStartTime}
                    </td>
                    <td class="loading-hours-cell" style="padding: 8px; border: 1px solid #ccc; text-align: center;">
                        ${loadingDisplay}
                    </td>
                    <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">
                        <input type="number" 
                               class="machine-stop-time-input" 
                               data-row-index="${rowIndex}"
                               data-date-key="${row.dateKey}"
                               data-record-id="${rowToRecordMap.get(rowIndex) || ''}"
                               value="${row.stop_hours}" 
                               step="0.01" 
                               min="0" 
                               style="width: 100px; padding: 5px; text-align: center; border: 1px solid #ddd; border-radius: 4px;"
                               onchange="updateStopTime(${rowIndex}, this.value)"
                               title="Edit machine stop time - will recalculate this day and all subsequent days (day-by-day cascade). Changes are saved to database automatically.">
                        <span class="save-indicator" id="stop-time-indicator-${rowIndex}" style="margin-left: 5px; font-size: 12px;"></span>
                    </td>
                    <td class="expected-end-time-cell" style="padding: 8px; border: 1px solid #ccc; text-align: center; white-space: nowrap;">
                        ${expectedEndStr}
                    </td>
                    <td class="end-time-cell" style="padding: 8px; border: 1px solid #ccc; text-align: center; white-space: nowrap;">
                        ${endStr}
                    </td>
                </tr>
            `;
        });

        tableHTML += `
                </tbody>
            </table>
            <p style="margin-top: 10px; color: #666; font-size: 14px;">
                <strong>Note:</strong> You can edit the Machine Stop Time in any row to adjust the schedule. 
                Changing machine stop time will update this row's Expected End Date and Time and shift ALL subsequent rows forward.
            </p>
        `;
        document.getElementById('scheduleTable').innerHTML = tableHTML;
        document.getElementById('saveBtn').style.display = 'inline-block';
    }


    async function saveChallans() {
        if (scheduleRows.length === 0) {
            alert('No schedule to save. Please generate schedule first.');
            return;
        }

        const date = document.getElementById('date').value;
        const startHour = parseInt(document.getElementById('startHour').value);
        const loadingTime = parseFloat(document.getElementById('loadingTime').value);
        const numberOfRows = parseInt(document.getElementById('rows').value);

        // CRITICAL: Read machine stop times from the ACTUAL table input fields
        // This ensures we save exactly what the user sees and has edited in the table
        const machineStopTimes = [];
        const challanStopTimes = new Map(); // challan_index -> max_stop_time
        
        scheduleRows.forEach((row, index) => {
            if (row.challan_index !== null && row.challan_index !== undefined) {
                const challanIndex = row.challan_index;
                
                // Get the actual current value from the input field (user may have edited it)
                const input = document.querySelector(`input[data-row-index="${index}"].machine-stop-time-input`);
                const rowStopTime = input ? parseFloat(input.value) || 0 : (parseFloat(row.stop_hours) || 0);
                
                // Update row data with current input value to keep in sync
                row.stop_hours = rowStopTime;
                
                const currentMax = challanStopTimes.get(challanIndex) || 0;
                // Store the maximum stop time from all rows in this challan
                if (rowStopTime > currentMax) {
                    challanStopTimes.set(challanIndex, rowStopTime);
                }
            }
        });
        
        // CRITICAL: Before saving, ensure scheduleRows contains EXACT current UI values
        // Recalculate schedule to ensure end_datetime matches current stop times
        // This ensures the saved data reflects any changes made in the table
        // NOTE: This updates scheduleRows in memory, which will be sent to backend
        recalculateScheduleFromRow(0);

        // Build machineStopTimes array in order of challan_index
        for (let i = 0; i < numberOfRows; i++) {
            machineStopTimes.push(challanStopTimes.get(i) || 0);
        }

        // Send complete scheduleRows data for synchronization
        const scheduleData = scheduleRows.map((row, index) => ({
            row_index: index,
            date_key: row.dateKey,
            start_datetime: row.start_datetime,
            end_datetime: row.end_datetime,
            loading_hours: row.loading_hours,
            stop_hours: row.stop_hours,
            expected_end: row.expected_end,
            challan_index: row.challan_index,
            record_id: rowToRecordMap.get(index) || null
        }));

        try {
            const data = await makeRequest('/api/challans/generate', {
                method: 'POST',
                body: JSON.stringify({
                    machine_id: machineId,
                    section_name: sectionName,
                    date: date,
                    start_hour: startHour,
                    loading_time: loadingTime,
                    number_of_rows: numberOfRows,
                    machine_stop_times: machineStopTimes,
                    schedule_rows: scheduleData // Send all schedule rows for synchronization
                })
            });

            if (data.success) {
                // Show table name in success message
                const tableName = data.table_name || 'database';
                const savedCount = data.saved_count || data.records?.length || 0;
                alert(`✅ ${data.message}\n\nSaved ${savedCount} row(s) to table: ${tableName}\n\nAll visible rows from UI table are now in database (1:1 mapping)`);
                console.log(`✓ Saved ${savedCount} rows to table: ${tableName} (1:1 with UI table)`);
                
                // Map saved records to schedule rows for future updates (1:1 mapping by row_index)
                if (data.records && data.records.length > 0) {
                    rowToRecordMap.clear();
                    
                    // Map each schedule row to its record ID (1:1 mapping)
                    // Match by row_no first, then by index position
                    data.records.forEach((record, recordIndex) => {
                        const recordRowNo = record.row_no || null;
                        const recordId = record.id;
                        let matched = false;
                        
                        // Try to match by row_no first (most accurate)
                        if (recordRowNo !== null) {
                            const targetRowIndex = recordRowNo - 1; // row_no is 1-based, rowIndex is 0-based
                            if (targetRowIndex >= 0 && targetRowIndex < scheduleRows.length) {
                                const row = scheduleRows[targetRowIndex];
                                rowToRecordMap.set(targetRowIndex, recordId);
                                row.record_id = recordId;
                                matched = true;
                                console.log(`Mapped row ${targetRowIndex} (row_no=${recordRowNo}) to record ID ${recordId}`);
                            }
                        }
                        
                        // Fallback: match by index position (1:1 mapping)
                        if (!matched && recordIndex < scheduleRows.length) {
                            const row = scheduleRows[recordIndex];
                            rowToRecordMap.set(recordIndex, recordId);
                            row.record_id = recordId;
                            console.log(`Mapped row ${recordIndex} (by position) to record ID ${recordId}`);
                        }
                    });
                    
                    // Re-render table to update input fields with record IDs
                    renderScheduleTable();
                }
                
                // Keep the table visible so user can continue editing
                // Don't clear the form - allow further edits
            } else {
                alert(`❌ Error: ${data.message || 'Failed to save challans'}`);
            }
        } catch (error) {
            alert(`❌ Error: ${error.message || 'An unexpected error occurred'}`);
            console.error('Save challans error:', error);
        }
    }

    async function deleteRange() {
        const fromDate = document.getElementById('deleteFromDate').value;
        const fromTime = document.getElementById('deleteFromTime').value;
        const toDate = document.getElementById('deleteToDate').value;
        const toTime = document.getElementById('deleteToTime').value;

        if (!fromDate || !fromTime || !toDate || !toTime) {
            alert('❌ Please fill all date/time fields.');
            return;
        }

        if (!confirm('Are you sure you want to delete challans in this range?')) {
            return;
        }

        try {
            const data = await makeRequest('/api/challans/delete-range', {
                method: 'POST',
                body: JSON.stringify({
                    machine_id: machineId,
                    section_id: sectionId,
                    from_date: fromDate,
                    from_time: fromTime,
                    to_date: toDate,
                    to_time: toTime
                })
            });

            if (data.success) {
                alert(`✅ ${data.message}`);
                // Clear delete form fields
                document.getElementById('deleteFromDate').value = '';
                document.getElementById('deleteFromTime').value = '';
                document.getElementById('deleteToDate').value = '';
                document.getElementById('deleteToTime').value = '';
                loadExistingChallans(); // Reload existing challans
            } else {
                alert(`❌ Error: ${data.message || 'Failed to delete challans'}`);
            }
        } catch (error) {
            alert(`❌ Error: ${error.message || 'An unexpected error occurred'}`);
            console.error('Delete range error:', error);
        }
    }

    // ============================================================================
    // DAY-BASED RECALCULATION SYSTEM - SINGLE SOURCE OF TRUTH
    // ============================================================================
    
    /**
     * Parse ISO date string to Date object
     * @param {string} isoString - ISO format date string
     * @returns {Date} Date object
     */
    function parseDate(isoString) {
        return new Date(isoString);
    }

    /**
     * Format Date object to display string "Jan 08, 2026, 03:00"
     * @param {Date} date - Date object
     * @returns {string} Formatted date string
     */
    function formatDate(date) {
        return formatDateTime(date);
    }

    /**
     * Add hours to a Date object
     * @param {Date} date - Base date
     * @param {number} hours - Hours to add
     * @returns {Date} New Date object
     */
    function addHours(date, hours) {
        return new Date(date.getTime() + hours * 60 * 60 * 1000);
    }

    /**
     * Recalculate schedule using DAY-BASED algorithm
     * SINGLE SOURCE OF TRUTH: currentDateTime flows through all rows
     * 
     * ALGORITHM (MUST FOLLOW EXACTLY):
     * - currentDateTime = first row start datetime
     * - For each row from top to bottom:
     *     - row.start_datetime = currentDateTime
     *     - loadingHours = row.loading_hours OR 0
     *     - stopHours = row.stop_hours OR 0
     *     - row.expected_end = currentDateTime + loadingHours (NOT affected by stop time)
     *     - row.end_datetime = currentDateTime + loadingHours + stopHours (synchronized with stop time)
     *     - currentDateTime = row.end_datetime (for next row - chain uses end_datetime)
     * 
     * @param {number} fromRowIndex - Index of the row where stop time changed (0-based)
     */
    function recalculateScheduleFromRow(fromRowIndex) {
        if (!scheduleRows || scheduleRows.length === 0) return;
        if (fromRowIndex < 0 || fromRowIndex >= scheduleRows.length) return;

        // CRITICAL: Preserve original dates (dateKey) - only update times
        // Store original dateKeys before recalculation to preserve calendar dates
        const originalDateKeys = scheduleRows.map(row => row.dateKey);
        
        // SINGLE SOURCE OF TRUTH: Start with first row's start datetime
        // DO NOT read from DOM - use the stored value
        currentDateTime = parseDate(scheduleRows[0].start_datetime);

        // Track the main row's expected_end for continuation rows
        let mainRowExpectedEnd = null;

        // Recalculate ALL rows from top to bottom
        // This ensures no time drift and perfect synchronization
        for (let i = 0; i < scheduleRows.length; i++) {
            const row = scheduleRows[i];
            
            // CRITICAL: Preserve the original date (dateKey) - DO NOT change calendar dates
            // Only update the time portion of start_datetime
            const originalDateKey = originalDateKeys[i] || row.dateKey;
            row.dateKey = originalDateKey; // Preserve original calendar date
            
            // Get the original calendar date for this row
            const originalDate = new Date(originalDateKey + 'T00:00:00Z');
            
            // Update start_datetime: Use original calendar date but with time from currentDateTime
            // This ensures dates stay the same, only times cascade forward
            const newStartDateTime = new Date(originalDate);
            newStartDateTime.setUTCHours(
                currentDateTime.getUTCHours(),
                currentDateTime.getUTCMinutes(),
                currentDateTime.getUTCSeconds(),
                currentDateTime.getUTCMilliseconds()
            );
            row.start_datetime = newStartDateTime.toISOString();
            
            // Update currentDateTime to use the preserved date with new time
            currentDateTime = newStartDateTime;
            
            // Get loading hours (empty = 0)
            const loadingHours = parseFloat(row.loading_hours) || 0;
            
            // Get stop hours (from row data, already updated by updateStopTime)
            const stopHours = parseFloat(row.stop_hours) || 0;
            
            // CRITICAL: Handle expected_end based on whether this is a main row or continuation row
            if (loadingHours > 0) {
                // Main row: Calculate Expected End = Start + Loading only (NOT affected by stop time)
                row.expected_end = addHours(currentDateTime, loadingHours).toISOString();
                // Store this as the main row's expected_end for subsequent continuation rows
                mainRowExpectedEnd = row.expected_end;
            } else {
                // Continuation row: Use the main row's expected_end (shared across continuation days)
                // This maintains the relationship where all continuation days share the same expected_end
                if (mainRowExpectedEnd) {
                    row.expected_end = mainRowExpectedEnd;
                } else {
                    // Fallback: If no main row expected_end found, use start time
                    row.expected_end = currentDateTime.toISOString();
                }
            }
            
            // Calculate End Date and Time: Start + Loading + Stop (synchronized with stop time)
            // For continuation rows: End = Start + Stop (since Loading = 0)
            // For main rows: End = Start + Loading + Stop
            const calculatedEnd = addHours(currentDateTime, loadingHours + stopHours);
            row.end_datetime = calculatedEnd.toISOString();
            
            // CRITICAL: For next row, preserve its original calendar date but use this row's end TIME
            // This ensures dates don't change, only times cascade
            if (i < scheduleRows.length - 1) {
                const nextRow = scheduleRows[i + 1];
                const nextOriginalDateKey = originalDateKeys[i + 1] || nextRow.dateKey;
                const nextOriginalDate = new Date(nextOriginalDateKey + 'T00:00:00Z');
                
                // Use next row's original calendar date, but with the time from this row's end
                // This preserves dates while cascading times
                currentDateTime = new Date(nextOriginalDate);
                currentDateTime.setUTCHours(
                    calculatedEnd.getUTCHours(),
                    calculatedEnd.getUTCMinutes(),
                    calculatedEnd.getUTCSeconds(),
                    calculatedEnd.getUTCMilliseconds()
                );
            } else {
                // Last row - just use the calculated end time
                currentDateTime = calculatedEnd;
            }
        }
        
        // Regenerate table to reflect all changes
        renderScheduleTable();
    }

    /**
     * Update stop time for a specific row and trigger recalculation
     * @param {number} rowIndex - Index of the row (0-based)
     * @param {number} stopTime - New stop time value
     */
    async function updateStopTime(rowIndex, stopTime) {
        stopTime = parseFloat(stopTime) || 0;
        if (stopTime < 0) stopTime = 0;
        
        if (!scheduleRows || rowIndex < 0 || rowIndex >= scheduleRows.length) return;
        
        // Show saving indicator
        const indicator = document.getElementById(`stop-time-indicator-${rowIndex}`);
        if (indicator) {
            indicator.textContent = '💾';
            indicator.style.color = '#ffc107';
        }
        
        // Update the row's stop time locally
        scheduleRows[rowIndex].stop_hours = stopTime;
        
        // Recalculate from the beginning to ensure perfect synchronization
        // This follows the algorithm: currentDateTime flows through all rows
        recalculateScheduleFromRow(0);
        
        // CRITICAL: After updating stop time, save ALL rows to maintain 1:1 sync
        // This ensures database matches UI exactly after cascade recalculation
        // Wait for recalculation to complete, then save all rows
        setTimeout(async () => {
            try {
                // Save ALL rows using the same logic as "Save Challans" button
                // This ensures perfect 1:1 synchronization
                await saveAllRowsAfterStopTimeUpdate();
                
                if (indicator) {
                    indicator.textContent = '✓';
                    indicator.style.color = '#28a745';
                    setTimeout(() => {
                        indicator.textContent = '';
                    }, 2000);
                }
            } catch (error) {
                console.error('Error saving after stop time update:', error);
                if (indicator) {
                    indicator.textContent = '✗';
                    indicator.style.color = '#dc3545';
                }
            }
        }, 100);
    }
    
    /**
     * Save ALL rows after stop time update to maintain 1:1 sync
     * This ensures database matches UI exactly after cascade recalculation
     */
    async function saveAllRowsAfterStopTimeUpdate() {
        // Get form values
        const date = document.getElementById('date').value;
        const startHour = parseInt(document.getElementById('startHour').value);
        const loadingTime = parseFloat(document.getElementById('loadingTime').value);
        const numberOfRows = parseInt(document.getElementById('rows').value);
        
        if (!date || !loadingTime || !numberOfRows) {
            throw new Error('Missing required form values');
        }
        
        // Build machineStopTimes array (for backward compatibility)
        const machineStopTimes = [];
        const challanStopTimes = new Map();
        
        scheduleRows.forEach((row, index) => {
            if (row.challan_index !== null && row.challan_index !== undefined) {
                const challanIndex = row.challan_index;
                const rowStopTime = parseFloat(row.stop_hours) || 0;
                
                const currentMax = challanStopTimes.get(challanIndex) || 0;
                if (rowStopTime > currentMax) {
                    challanStopTimes.set(challanIndex, rowStopTime);
                }
            }
        });
        
        for (let i = 0; i < numberOfRows; i++) {
            machineStopTimes.push(challanStopTimes.get(i) || 0);
        }
        
        // Send complete scheduleRows data for synchronization (EXACT 1:1 mapping)
        const scheduleData = scheduleRows.map((row, index) => ({
            row_index: index,
            date_key: row.dateKey,
            start_datetime: row.start_datetime,      // EXACT recalculated value
            end_datetime: row.end_datetime,          // EXACT recalculated value
            loading_hours: row.loading_hours,        // EXACT value
            stop_hours: row.stop_hours,              // EXACT updated value
            expected_end: row.expected_end,          // EXACT recalculated value
            challan_index: row.challan_index,
            record_id: rowToRecordMap.get(index) || null
        }));
        
        // Save using the same endpoint as "Save Challans"
        const data = await makeRequest('/api/challans/generate', {
            method: 'POST',
            body: JSON.stringify({
                machine_id: machineId,
                section_name: sectionName,
                date: date,
                start_hour: startHour,
                loading_time: loadingTime,
                number_of_rows: numberOfRows,
                machine_stop_times: machineStopTimes,
                schedule_rows: scheduleData // Send all schedule rows for synchronization
            })
        });
        
        if (data.success) {
            // Map saved records back to schedule rows for future updates (1:1 mapping)
            if (data.records && data.records.length > 0) {
                rowToRecordMap.clear();
                
                // Map each schedule row to its record ID (1:1 mapping)
                data.records.forEach((record, recordIndex) => {
                    const recordRowNo = record.row_no || null;
                    const recordId = record.id;
                    let matched = false;
                    
                    // Try to match by row_no first (most accurate)
                    if (recordRowNo !== null) {
                        const targetRowIndex = recordRowNo - 1; // row_no is 1-based, rowIndex is 0-based
                        if (targetRowIndex >= 0 && targetRowIndex < scheduleRows.length) {
                            const row = scheduleRows[targetRowIndex];
                            rowToRecordMap.set(targetRowIndex, recordId);
                            row.record_id = recordId;
                            matched = true;
                        }
                    }
                    
                    // Fallback: match by index position (1:1 mapping)
                    if (!matched && recordIndex < scheduleRows.length) {
                        const row = scheduleRows[recordIndex];
                        rowToRecordMap.set(recordIndex, recordId);
                        row.record_id = recordId;
                    }
                });
            }
            
            console.log('✓ All rows saved after stop time update (1:1 sync maintained)');
        } else {
            throw new Error(data.message || 'Failed to save rows after stop time update');
        }
    }
    
    // OLD CODE - Keep for reference but replaced with full save above
    /*
    // Wait for recalculation to complete, then get updated end_time
    setTimeout(async () => {
            // Find the record ID for this row (may be mapped via challan_index)
            const row = scheduleRows[rowIndex];
            let recordId = rowToRecordMap.get(rowIndex);
            
            // If not found directly, try to find via challan_index
            if (!recordId && row && row.challan_index !== null && row.challan_index !== undefined) {
                // Find the first row with this challan_index that has a record ID
                for (let i = 0; i < scheduleRows.length; i++) {
                    const otherRow = scheduleRows[i];
                    if (otherRow.challan_index === row.challan_index) {
                        recordId = rowToRecordMap.get(i);
                        if (recordId) {
                            // Use this record ID for all rows with this challan_index
                            rowToRecordMap.set(rowIndex, recordId);
                            break;
                        }
                    }
                }
            }
            
            if (recordId) {
                // Get the stop time from the MAIN row (where loading_hours > 0)
                // For continuation rows, find the main row of the same challan
                let mainRowIndex = rowIndex;
                let mainStopTime = stopTime;
                
                if (row && row.challan_index !== null && row.challan_index !== undefined) {
                    // Find the main row (with loading_hours > 0) for this challan
                    const mainRow = scheduleRows.find(r => 
                        r.challan_index === row.challan_index && r.loading_hours > 0
                    );
                    if (mainRow) {
                        mainRowIndex = scheduleRows.indexOf(mainRow);
                        // Get the maximum stop time from all rows in this challan
                        const challanRows = scheduleRows.filter(r => r.challan_index === row.challan_index);
                        challanRows.forEach(r => {
                            const rowStopTime = parseFloat(r.stop_hours) || 0;
                            if (rowStopTime > mainStopTime) {
                                mainStopTime = rowStopTime;
                            }
                        });
                        // Get record ID from main row
                        recordId = rowToRecordMap.get(mainRowIndex) || recordId;
                    }
                }
                
                // Update database with cascade effect
                const result = await updateRecordInDatabaseWithCascade(recordId, mainStopTime);
                if (indicator) {
                    if (result.success) {
                        indicator.textContent = '✓';
                        indicator.style.color = '#28a745';
                        
                        // Update scheduleRows with database response
                        if (result.records && result.records.length > 0) {
                            updateScheduleRowsFromDatabase(result.records);
                            // Re-render table to show updated times
                            renderScheduleTable();
                        }
                        
                        setTimeout(() => {
                            indicator.textContent = '';
                        }, 2000);
                    } else {
                        indicator.textContent = '✗';
                        indicator.style.color = '#dc3545';
                        console.error('Update failed:', result.message);
                    }
                }
            } else {
                // If not saved yet, the change will be saved when user clicks "Save Challans"
                if (indicator) {
                    indicator.textContent = '';
                }
                console.log('Row not yet saved to database. Change will be saved when you click "Save Challans"');
            }
        }, 100);
    }
    
    /**
     * Update a record in the database with cascade effect
     * Updates the record and all subsequent records in a single transaction
     */
    async function updateRecordInDatabaseWithCascade(recordId, machineStopTime) {
        try {
            // Extract machine number and section from current page
            const machineName = '{{ $machine->name }}';
            const sectionName = '{{ $section->name }}';
            
            // Extract machine number (e.g., "M-3" -> 3)
            const machineMatch = machineName.match(/M-?(\d+)/);
            if (!machineMatch) {
                console.error('Invalid machine name format');
                return { success: false, message: 'Invalid machine name format' };
            }
            const machineNumber = parseInt(machineMatch[1]);
            
            // Convert section name (e.g., "A-OUT" -> "AOUT")
            const sectionCode = sectionName.replace('-', '').toUpperCase();
            
            const data = await makeRequest('/api/challans/update-stop-time-cascade', {
                method: 'POST',
                body: JSON.stringify({
                    machine_number: machineNumber,
                    section: sectionCode,
                    record_id: recordId,
                    machine_stop_time: machineStopTime
                })
            });
            
            if (data.success) {
                console.log(`✓ Updated record ID ${recordId} with cascade: machine_stop_time=${machineStopTime}, updated ${data.updated_count} records`);
                return {
                    success: true,
                    records: data.records || [],
                    message: data.message
                };
            } else {
                console.error('Failed to update record:', data.message);
                return {
                    success: false,
                    message: data.message || 'Failed to update record'
                };
            }
        } catch (error) {
            console.error('Error updating record in database:', error);
            return {
                success: false,
                message: error.message || 'Error updating record'
            };
        }
    }
    
    /**
     * Update scheduleRows with data from database after cascade update
     * This ensures UI matches database exactly
     */
    function updateScheduleRowsFromDatabase(updatedRecords) {
        if (!updatedRecords || updatedRecords.length === 0) return;
        
        // Create a map of record_id -> updated record data
        const recordMap = new Map();
        updatedRecords.forEach(record => {
            recordMap.set(record.id, record);
        });
        
        // Update scheduleRows with database values
        scheduleRows.forEach((row, rowIndex) => {
            const recordId = rowToRecordMap.get(rowIndex);
            if (recordId && recordMap.has(recordId)) {
                const dbRecord = recordMap.get(recordId);
                
                // Update only MAIN rows (with loading_hours > 0)
                if (row.loading_hours > 0) {
                    // Update with EXACT database values
                    row.start_datetime = dbRecord.start_time;
                    row.end_datetime = dbRecord.end_time;
                    row.machine_stop_time = dbRecord.machine_stop_time;
                    row.stop_hours = dbRecord.machine_stop_time;
                    
                    // Recalculate expected_end = start + loading (not including stop)
                    const startTime = new Date(dbRecord.start_time);
                    row.expected_end = addHours(startTime, row.loading_hours).toISOString();
                    
                    console.log(`Updated row ${rowIndex} (record ${recordId}) from database`);
                }
            }
        });
        
        // Recalculate all rows to ensure cascade effect is applied
        recalculateScheduleFromRow(0);
    }

    /**
     * Get machine stop time for a challan row
     * Reads from stored daily stop times or falls back to challan's value
     * @param {number} rowIndex - Challan row index
     * @param {string} startDate - Start date key (YYYY-MM-DD)
     * @returns {number} Machine stop time in hours
     */
    function getMachineStopTime(rowIndex, startDate) {
        const stopTimeKey = `stop_${rowIndex}_${startDate}`;
        if (window.dailyStopTimes && window.dailyStopTimes[stopTimeKey] !== undefined) {
            return parseFloat(window.dailyStopTimes[stopTimeKey]) || 0;
        }
        // Fallback to challan's stored value
        if (generatedChallans[rowIndex]) {
            return parseFloat(generatedChallans[rowIndex].machine_stop_time) || 0;
        }
        return 0;
    }

    /**
     * Store machine stop time for a challan row
     * @param {number} rowIndex - Challan row index
     * @param {string} startDate - Start date key (YYYY-MM-DD)
     * @param {number} stopTime - Machine stop time in hours
     */
    function setMachineStopTime(rowIndex, startDate, stopTime) {
        if (!window.dailyStopTimes) window.dailyStopTimes = {};
        const stopTimeKey = `stop_${rowIndex}_${startDate}`;
        window.dailyStopTimes[stopTimeKey] = parseFloat(stopTime) || 0;
    }

    /**
     * Recalculate the ENTIRE chain starting from a specific row
     * This is the CORE function that maintains the chain integrity
     * 
     * ENFORCES ALL RULES:
     * RULE 1: The FIRST row's Start DateTime is fixed (never changes after initial generation)
     * RULE 2: For EVERY row: 
     *   - End Time = Start DateTime + Loading Hours + Stop Hours (synchronized with stop time)
     *   - Expected End Time = Start DateTime + Loading Hours (NOT affected by stop time)
     * RULE 3: For EVERY row except first: Start DateTime = Expected End Time of PREVIOUS row
     * RULE 4: No row is allowed to keep an old Start DateTime after recalculation
     * RULE 5: When stop time changes, recalculate that row, then cascade FORWARD till last row
     * RULE 6: NEVER read Start DateTime from DOM - always compute from previous row's End Time
     * RULE 7: Empty loading-time rows still inherit Start DateTime from previous row
     * RULE 8: Show all dates, days never skip (regenerates table to ensure all dates are shown)
     * 
     * @param {number} fromRowIndex - Index of the row where change occurred (0-based)
     */
    function recalculateChainFromRow(fromRowIndex) {
        if (!generatedChallans || generatedChallans.length === 0) return;
        
        const numberOfRows = generatedChallans.length;
        if (fromRowIndex < 0 || fromRowIndex >= numberOfRows) return;

        // RULE 1: First row's Start DateTime is FIXED (only set once on initial generation)
        let currentStartTime;
        if (fromRowIndex === 0) {
            // For first row, use the stored fixed start time or current value
            if (firstRowStartTime) {
                currentStartTime = parseDate(firstRowStartTime);
            } else {
                // Store it as fixed for future recalculations
                firstRowStartTime = generatedChallans[0].start_time;
                currentStartTime = parseDate(firstRowStartTime);
            }
        } else {
            // RULE 3: For every row except first, Start DateTime = Expected End Time of PREVIOUS row
            // NEVER read from DOM - always compute from previous row's end time
            const previousChallan = generatedChallans[fromRowIndex - 1];
            currentStartTime = parseDate(previousChallan.end_time);
        }

        // Recalculate from the changed row forward to the end
        // IMPORTANT: This ensures ALL subsequent dates are affected when stop time changes
        for (let i = fromRowIndex; i < numberOfRows; i++) {
            const challan = generatedChallans[i];
            
            // Get loading hours from challan data (0 if empty row)
            const loadingHours = parseFloat(challan.loading_duration) || 0;
            
            // Get the NEW start date key (may have changed due to previous row's recalculation)
            const newStartDateKey = parseDate(currentStartTime).toISOString().split('T')[0];
            
            // Get the OLD start date key (before recalculation)
            const oldStartDateKey = parseDate(challan.start_time).toISOString().split('T')[0];
            
            // Get machine stop time - try new date first, then old date, then challan's stored value
            let stopHours = getMachineStopTime(i, newStartDateKey);
            if (stopHours === 0 && oldStartDateKey !== newStartDateKey) {
                // If start date changed, try to get stop time from old date
                const oldStopTime = getMachineStopTime(i, oldStartDateKey);
                if (oldStopTime > 0) {
                    // Migrate stop time from old date to new date
                    stopHours = oldStopTime;
                    setMachineStopTime(i, newStartDateKey, stopHours);
                }
            }
            
            // If still no stop time found, use challan's stored value
            if (stopHours === 0) {
                stopHours = parseFloat(challan.machine_stop_time) || 0;
            }
            
            // Calculate End Time (synchronized with stop time): Start + Loading + Stop
            const endTime = addHours(currentStartTime, loadingHours + stopHours);
            
            // Calculate Expected End Time (NOT affected by stop time): Start + Loading only
            const expectedEndTime = addHours(currentStartTime, loadingHours);
            
            // Update challan data (THIS IS THE SOURCE OF TRUTH)
            challan.start_time = currentStartTime.toISOString();
            challan.end_time = endTime.toISOString();
            challan.expected_end_time = expectedEndTime.toISOString(); // Store expected end time separately
            challan.machine_stop_time = stopHours;
            
            // Ensure stop time is stored for the new start date
            setMachineStopTime(i, newStartDateKey, stopHours);
            
            // RULE 3: Next row's start time = this row's End Time (with stop time)
            // This ensures the chain continues and ALL subsequent dates are affected
            currentStartTime = endTime;
        }
        
        // RULE 8: Show all dates, days never skip
        // Regenerate the entire table to ensure ALL dates between first and last challan are shown
        // This is necessary because recalculation may shift dates, requiring new date rows to be added
        // or existing date rows to be updated. Regeneration ensures no dates are skipped.
        regenerateTableWithAllDates();
    }

    /**
     * Update the displayed values for a row in the DOM
     * RULE 4: No row is allowed to keep an old Start DateTime after recalculation
     * IMPORTANT: Show End Time and Expected End Time in EVERY row that has a challan
     * IMPORTANT: Show updated time in subsequent rows when they become start rows
     * @param {number} rowIndex - Challan row index
     * @param {Date} startDateTime - Start datetime
     * @param {Date} endDateTime - End datetime (includes stop time)
     */
    function updateRowDisplay(rowIndex, startDateTime, endDateTime) {
        const challan = generatedChallans[rowIndex];
        if (!challan) return;
        
        const startDateKey = startDateTime.toISOString().split('T')[0];
        const endDateKey = endDateTime.toISOString().split('T')[0];
        
        // Find all table rows for this challan (may span multiple days)
        const rows = document.querySelectorAll(`tr[data-row-index="${rowIndex}"]`);
        
        rows.forEach(row => {
            const rowDate = row.getAttribute('data-date');
            const isStartRow = rowDate === startDateKey;
            const isEndRow = rowDate === endDateKey;
            
            // Update start datetime cell
            // IMPORTANT: Show the challan's start time on ALL days (including continuation days)
            // This ensures users can see the time component, not just the date
            const startCell = row.querySelector('.start-datetime-cell');
            if (startCell) {
                if (isStartRow) {
                    // This is the start row - show full date and time with bold
                    startCell.textContent = formatDate(startDateTime);
                    startCell.style.fontWeight = 'bold';
                    // Update data attributes (source of truth markers)
                    row.setAttribute('data-start-datetime', startDateTime.toISOString());
                    row.setAttribute('data-loading-hours', challan.loading_duration || 0);
                    
                    // Update machine stop time input value on start row
                    const stopInput = row.querySelector('.machine-stop-time-input');
                    if (stopInput) {
                        const stopTime = getMachineStopTime(rowIndex, startDateKey);
                        stopInput.value = stopTime;
                        stopInput.setAttribute('data-date', startDateKey);
                    }
                } else {
                    // This is a continuation day - show the challan's start time (not just date)
                    // This allows users to see the time component on all days
                    startCell.textContent = formatDate(startDateTime);
                    startCell.style.fontWeight = 'normal';
                    // Still update data attributes for consistency
                    row.setAttribute('data-start-datetime', startDateTime.toISOString());
                }
            }
            
            // Update End Time cell (synchronized with stop time) - SHOW IN EVERY ROW
            const endCell = row.querySelector('.end-time-cell');
            if (endCell) {
                endCell.textContent = formatDate(endDateTime);
                endCell.setAttribute('data-end-datetime', endDateTime.toISOString());
            }
            
            // Update Expected End Time cell (NOT affected by stop time) - SHOW IN EVERY ROW
            const expectedEndCell = row.querySelector('.expected-end-time-cell');
            if (expectedEndCell && challan.expected_end_time) {
                const expectedEndTime = parseDate(challan.expected_end_time);
                expectedEndCell.textContent = formatDate(expectedEndTime);
                expectedEndCell.setAttribute('data-expected-end-datetime', challan.expected_end_time);
            }
        });
    }

    /**
     * Update machine stop time for a row and trigger chain recalculation
     * @param {number} rowIndex - The challan row index
     * @param {string} dateKey - The date key (YYYY-MM-DD) - used for storage
     * @param {boolean} isStartRow - Whether this is the start row of the challan
     * @param {number} stopTime - The new stop time value
     */
    function updateMachineStopTimeForRow(rowIndex, dateKey, isStartRow, stopTime) {
        stopTime = parseFloat(stopTime) || 0;
        if (stopTime < 0) stopTime = 0;
        
        if (!generatedChallans[rowIndex]) return;
        
        const challan = generatedChallans[rowIndex];
        
        // Only update stop time if this is the start row (main row of the challan)
        // Continuation days don't have independent stop times
        if (isStartRow) {
            const challanStartDate = parseDate(challan.start_time).toISOString().split('T')[0];
            
            // Store the stop time for this challan's start date
            setMachineStopTime(rowIndex, challanStartDate, stopTime);
            
            // Update the challan's stored value
            challan.machine_stop_time = stopTime;
            
            // RULE 5: Recalculate from this row forward (cascade)
            recalculateChainFromRow(rowIndex);
        } else {
            // For continuation days, update the start date's stop time instead
            const challanStartDate = parseDate(challan.start_time).toISOString().split('T')[0];
            setMachineStopTime(rowIndex, challanStartDate, stopTime);
            challan.machine_stop_time = stopTime;
            
            // Still trigger recalculation from the start of this challan
            recalculateChainFromRow(rowIndex);
        }
    }

    // Keep the old function for backward compatibility
    function updateMachineStopTime(rowIndex, stopTime) {
        if (generatedChallans[rowIndex]) {
            const challan = generatedChallans[rowIndex];
            const challanStartDate = parseDate(challan.start_time).toISOString().split('T')[0];
            updateMachineStopTimeForRow(rowIndex, challanStartDate, true, stopTime);
        }
    }

    function regenerateTableWithAllDates() {
        // Get all dates from first challan start to last challan end
        const firstDate = new Date(generatedChallans[0].start_time);
        const lastDate = new Date(generatedChallans[generatedChallans.length - 1].end_time);
        
        // Create array of all dates
        const allDates = [];
        let currentDate = new Date(firstDate);
        currentDate.setHours(0, 0, 0, 0);
        const lastDateOnly = new Date(lastDate);
        lastDateOnly.setHours(0, 0, 0, 0);
        
        while (currentDate <= lastDateOnly) {
            allDates.push(new Date(currentDate));
            currentDate.setDate(currentDate.getDate() + 1);
        }

        // Create a map to find which challan(s) are active on each date
        const challansByDate = new Map();
        generatedChallans.forEach((challan, idx) => {
            const startDate = new Date(challan.start_time);
            const endDate = new Date(challan.end_time);
            
            let checkDate = new Date(startDate);
            checkDate.setHours(0, 0, 0, 0);
            const endDateOnly = new Date(endDate);
            endDateOnly.setHours(0, 0, 0, 0);
            
            while (checkDate <= endDateOnly) {
                const dateKey = checkDate.toISOString().split('T')[0];
                if (!challansByDate.has(dateKey)) {
                    challansByDate.set(dateKey, []);
                }
                const startDateOnly = new Date(startDate);
                startDateOnly.setHours(0, 0, 0, 0);
                challansByDate.get(dateKey).push({ 
                    challan, 
                    idx, 
                    isStart: checkDate.getTime() === startDateOnly.getTime(), 
                    isEnd: checkDate.getTime() === endDateOnly.getTime() 
                });
                checkDate.setUTCDate(checkDate.getUTCDate() + 1); // Use UTC methods
            }
        });

        let tableHTML = `
            <h3>Generated Schedule (All Dates):</h3>
            <table style="width: 100%; border-collapse: collapse; min-width: 800px;">
                <thead style="position: sticky; top: 0; background-color: #f0f0f0; z-index: 10;">
                    <tr>
                        <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">Start Date & Time</th>
                        <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">Loading Time</th>
                        <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">Machine Stop Time</th>
                        <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">Expected End Date and Time</th>
                        <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">End Date and Time</th>
                    </tr>
                </thead>
                <tbody>
        `;

        // Generate rows for each date sequentially
        allDates.forEach((date, dateIndex) => {
            const dateKey = date.toISOString().split('T')[0];
            const dateStr = formatDateOnly(date);
            const challansForDate = challansByDate.get(dateKey) || [];
            
            if (challansForDate.length > 0) {
                // IMPORTANT: Only show challan on its START day to avoid duplicate rows
                // Continuation days will be shown as empty rows (to show all dates)
                const startChallans = challansForDate.filter(({ isStart }) => isStart);
                
                if (startChallans.length > 0) {
                    // Show challan information only on start day
                    startChallans.forEach(({ challan, idx, isStart }) => {
                        // Show the challan's start time
                        const startDateTimeStr = formatDateTime(new Date(challan.start_time));
                        // End Time (synchronized with stop time): Start + Loading + Stop
                        const endDateTimeStr = formatDateTime(new Date(challan.end_time));
                        // Expected End Time (NOT affected by stop time): Start + Loading only
                        const expectedEndTimeStr = challan.expected_end_time 
                            ? formatDateTime(new Date(challan.expected_end_time))
                            : formatDateTime(addHours(new Date(challan.start_time), challan.loading_duration || 0));
                        
                        // Get machine stop time for this challan's start date
                        const stopTimeKey = `stop_${idx}_${dateKey}`;
                        let machineStopTime = 0;
                        if (window.dailyStopTimes && window.dailyStopTimes[stopTimeKey] !== undefined) {
                            machineStopTime = window.dailyStopTimes[stopTimeKey];
                        } else {
                            machineStopTime = challan.machine_stop_time || 0;
                            if (!window.dailyStopTimes) window.dailyStopTimes = {};
                            window.dailyStopTimes[stopTimeKey] = machineStopTime;
                        }
                        
                        // Add data attributes for main challan rows (start rows)
                        const dataAttrs = `data-row-index="${idx}" data-start-datetime="${challan.start_time}" data-loading-hours="${challan.loading_duration}"`;
                        
                        tableHTML += `
                            <tr ${dataAttrs} data-date="${dateKey}" style="background-color: ${dateIndex % 2 === 0 ? '#fff' : '#f9f9f9'};">
                                <td class="start-datetime-cell" style="padding: 8px; border: 1px solid #ccc; text-align: center; white-space: nowrap; font-weight: bold;">
                                    ${startDateTimeStr}
                                </td>
                                <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">${challan.loading_duration}</td>
                                <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">
                                    <input type="number" 
                                           class="machine-stop-time-input" 
                                           data-row="${idx}" 
                                           data-date="${dateKey}"
                                           data-stop-key="${stopTimeKey}"
                                           data-is-start="true"
                                           value="${machineStopTime}" 
                                           step="0.01" 
                                           min="0" 
                                           style="width: 100px; padding: 5px; text-align: center; border: 1px solid #ddd; border-radius: 4px;"
                                           onchange="updateMachineStopTimeForRow(${idx}, '${dateKey}', true, this.value)"
                                           title="Edit machine stop time - will recalculate this row and all subsequent rows">
                                </td>
                                <td class="end-time-cell" data-end-datetime="${challan.end_time}" style="padding: 8px; border: 1px solid #ccc; text-align: center; white-space: nowrap;">${endDateTimeStr}</td>
                                <td class="expected-end-time-cell" data-expected-end-datetime="${challan.expected_end_time || ''}" style="padding: 8px; border: 1px solid #ccc; text-align: center; white-space: nowrap;">${expectedEndTimeStr}</td>
                            </tr>
                        `;
                    });
                } else {
                    // This date has continuation days but no start day - show as empty row
                    // This ensures all dates are shown (RULE 8)
                    tableHTML += `
                        <tr data-date="${dateKey}" style="background-color: ${dateIndex % 2 === 0 ? '#fff' : '#f9f9f9'}; opacity: 0.6;">
                            <td style="padding: 8px; border: 1px solid #ccc; text-align: center; white-space: nowrap; font-weight: bold;">${dateStr}, -</td>
                            <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">-</td>
                            <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">
                                <input type="number" 
                                       class="machine-stop-time-input" 
                                       data-date="${dateKey}"
                                       data-no-challan="true"
                                       value="0" 
                                       step="0.01" 
                                       min="0" 
                                       style="width: 100px; padding: 5px; text-align: center; border: 1px solid #ddd; border-radius: 4px; opacity: 0.5;"
                                       disabled
                                       title="Continuation day - no challan starts on this date">
                            </td>
                            <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">-</td>
                            <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">-</td>
                        </tr>
                    `;
                }
            } else {
                // No challan on this date - show the date with empty fields
                tableHTML += `
                    <tr data-date="${dateKey}" style="background-color: ${dateIndex % 2 === 0 ? '#fff' : '#f9f9f9'}; opacity: 0.6;">
                        <td style="padding: 8px; border: 1px solid #ccc; text-align: center; white-space: nowrap; font-weight: bold;">${dateStr}, -</td>
                        <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">-</td>
                        <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">
                            <input type="number" 
                                   class="machine-stop-time-input" 
                                   data-date="${dateKey}"
                                   data-no-challan="true"
                                   value="0" 
                                   step="0.01" 
                                   min="0" 
                                   style="width: 100px; padding: 5px; text-align: center; border: 1px solid #ddd; border-radius: 4px; opacity: 0.5;"
                                   disabled
                                   title="No challan on this date">
                        </td>
                        <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">-</td>
                        <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">-</td>
                    </tr>
                `;
            }
        });

        tableHTML += `
                </tbody>
            </table>
            <p style="margin-top: 10px; color: #666; font-size: 14px;">
                <strong>Note:</strong> This view shows all dates sequentially. You can edit the Machine Stop Time in any row (where available) to adjust the schedule. 
                Changing machine stop time will keep the same row's Expected End Time unchanged, but will update subsequent rows' start and end times automatically.
            </p>
        `;
        document.getElementById('scheduleTable').innerHTML = tableHTML;
    }

    async function editChallan(id) {
        // Get the challan data first to pre-fill the form
        try {
            const challans = await makeRequest(`/api/challans?machine_id=${machineId}&section_name=${encodeURIComponent(sectionName)}`);
            const challan = challans.find(c => c.id === id);
            
            if (!challan) {
                alert('❌ Challan not found');
                return;
            }

            // Format dates for input
            const startDateTime = new Date(challan.start_time);
            const endDateTime = new Date(challan.end_time);
            const startDateStr = startDateTime.toISOString().slice(0, 16);
            const endDateStr = endDateTime.toISOString().slice(0, 16);

            const newStartTime = prompt('Enter new start time (YYYY-MM-DDTHH:MM):', startDateStr);
            if (!newStartTime) return;

            const newEndTime = prompt('Enter new end time (YYYY-MM-DDTHH:MM):', endDateStr);
            if (!newEndTime) return;

            // Calculate duration from times
            const start = new Date(newStartTime);
            const end = new Date(newEndTime);
            const durationHours = (end - start) / (1000 * 60 * 60);

            if (durationHours <= 0) {
                alert('❌ End time must be after start time');
                return;
            }

            const data = await makeRequest(`/api/challans/${id}`, {
                method: 'PUT',
                body: JSON.stringify({
                    start_time: newStartTime,
                    end_time: newEndTime,
                    loading_duration: durationHours
                })
            });

            if (data.success) {
                alert(`✅ ${data.message}`);
                loadExistingChallans();
            }
        } catch (error) {
            alert(`❌ Error: ${error.message || 'An unexpected error occurred'}`);
            console.error('Edit challan error:', error);
        }
    }
</script>
@endpush

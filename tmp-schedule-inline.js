
    window.machineId = {{ $machine->id ?? 'null' }};
    window.sectionName = '{{ addslashes($section->name ?? '') }}';
    window.sectionId = {{ $section->id ?? 'null' }};
</script>
<script>
    
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('stopTimeModal');
            if (modal && event.target === modal) {
                closeStopTimeModal();
            }
        });

        // AUTO-GENERATION LOGIC - NEW
        let autoGenInterval = null;
        let autoGenStatusEl = null;

        /**
         * Get today minus 1 day (UTC date only)
         */
        function getTodayMinusOne() {
            const today = new Date();
            today.setUTCDate(today.getUTCDate() - 1);
            today.setUTCHours(0, 0, 0, 0); // Normalize to date start
            return today;
        }

        /**
         * Check if should auto-generate 20 new rows
         * Triggers when last row's end_datetime date == today-1
         */
        function shouldAutoGenerate() {
            if (scheduleRows.length === 0) return false;
            const lastRow = scheduleRows[scheduleRows.length - 1];
            const lastEndDate = new Date(lastRow.end_datetime);
            lastEndDate.setUTCHours(0, 0, 0, 0); // Date only
            const todayMinusOne = getTodayMinusOne();
            return lastEndDate.getTime() === todayMinusOne.getTime();
        }

        /**
         * Generate next 20 rows continuing from last row (same logic as initial)
         * Uses current form loadingTime, shift-based
         */
        async function generateNext3Cycles() {
            if (scheduleRows.length === 0) return;
            
            const lastRow = scheduleRows[scheduleRows.length - 1];
            const loadingTimeEl = document.getElementById('loadingTime');
            const totalLoadingTime = parseFloat(loadingTimeEl?.value) || 12; // Default shift
            
            console.log(`🕐 Last end date matches today-1. Auto-generating 3 cycles (21 rows) with ${totalLoadingTime}h loading...`);
            
            let currentDatetime = new Date(lastRow.end_datetime);
            let remainingLoadingTime = totalLoadingTime * 20; // Enough for 20 shifts
            const startRowNo = scheduleRows.length + 1;
            
            for (let i = 0; i < 21; i++) {  // 3 cycles x 7 rows
                const rowNo = startRowNo + i;
                const machineStopHours = 0; // Default 0
                
                // Same shift logic as generateSchedule
                const shiftWindow = getShiftWindow(currentDatetime);
                const remainingInShift = getRemainingShiftHours(currentDatetime, shiftWindow.shiftEnd);
                const loadingHours = Math.min(remainingLoadingTime, remainingInShift, 12);
                
                const expectedEndDatetime = new Date(currentDatetime.getTime() + loadingHours * 3600000);
                const endDatetime = new Date(expectedEndDatetime.getTime() + machineStopHours * 3600000);
                
                scheduleRows.push({
                    dateKey: currentDatetime.toISOString().split('T')[0],
                    start_datetime: currentDatetime.toISOString(),
                    expected_end: expectedEndDatetime.toISOString(),
                    end_datetime: endDatetime.toISOString(),
                    loading_hours: loadingHours > 0 ? loadingHours : null,
                    stop_hours: machineStopHours,
                    challan_index: null, // New rows
                    cycle_number: '?',
                    stop_start_datetime: null,
                    stop_end_datetime: null,
                    stop_remarks: ''
                });
                
                remainingLoadingTime -= loadingHours;
                currentDatetime = new Date(shiftWindow.shiftEnd);
            }
            
            // Auto-save silently
            await saveChallans(false);
            renderScheduleTable();
            updateAutoGenStatus('Generated 3 cycles (21 rows)');
            console.log(`✅ Auto-generated 3 cycles (21 rows). Total: ${scheduleRows.length}`);
        }

        /**
         * Update auto-gen status UI
         */
        function updateAutoGenStatus(message) {
            if (autoGenStatusEl) {
                autoGenStatusEl.textContent = message;
                autoGenStatusEl.style.color = '#28a745';
            }
        }

        /**
         * Start real-time auto-gen monitoring (every 30s)
         */
        function startAutoGenMonitor() {
            if (autoGenInterval) clearInterval(autoGenInterval);
            
            autoGenInterval = setInterval(() => {
                if (shouldAutoGenerate()) {            
                    generateNext3Cycles();        
                }
            }, 30000); // 30 seconds
            
            // Create status element if not exists
            if (!autoGenStatusEl) {
                autoGenStatusEl = document.createElement('span');
                autoGenStatusEl.id = 'autoGenStatus';
                autoGenStatusEl.style.cssText = 'color: #28a745; font-size: 0.9em; margin-left: 10px;';
                const h3 = document.querySelector('#scheduleTable h3');
                if (h3) h3.appendChild(autoGenStatusEl);
            }
            
            updateAutoGenStatus('🔄 Active (30s checks)');
            console.log('▶️ Auto-gen monitor started');
        }

        /**
         * Stop auto-gen monitor
         */
        function stopAutoGenMonitor() {
            if (autoGenInterval) {
                clearInterval(autoGenInterval);
                autoGenInterval = null;
            }
            if (autoGenStatusEl) {
                autoGenStatusEl.textContent = '⏹️ Stopped';
                autoGenStatusEl.style.color = '#dc3545';
            }
            console.log('⏹️ Auto-gen monitor stopped');
        }


        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeStopTimeModal();
            }
        });

        // Enable/disable generate button based on form completion
        document.addEventListener('DOMContentLoaded', function() {
            const inputs = ['date', 'startHour', 'loadingTime'];
            inputs.forEach(id => {
                const input = document.getElementById(id);
                if (input) {
                    input.addEventListener('input', checkFormCompletion);
                    input.addEventListener('change', checkFormCompletion);
                }
            });
            // ✅ FIX: Enable with defaults + hide warning + start monitor
            checkFormCompletion();
            hideScheduleWarning();
            loadExistingSchedule(); // Load data first
            startAutoGenMonitor();
            console.log('✅ Page ready: Editing enabled, data loading...');
        });

        // Helper function to determine shift type based on start hour (UTC)
        // Day Shift: 08:00-19:59 (8AM-8PM), Night Shift: 20:00-07:59 (8PM-8AM next day)
        function getShiftType(date) {
            const hour = date.getUTCHours();
            if (hour >= 8 && hour <= 19) {
                return '<span style="background: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">🟡 Day</span>';
            } else if ((hour >= 20 && hour <= 23) || (hour >= 0 && hour < 8)) {
                return '<span style="background: #cce7ff; color: #004085; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">🔵 Night</span>';
            }
            return '<span style="color: #6c757d; font-size: 12px;">—</span>';
        }

        function getShiftTypeName(date) {
            const hour = date.getUTCHours();
            return (hour >= 8 && hour < 20) ? 'Day' : 'Night';
        }



        function getShiftWindow(date) {
            const shiftType = getShiftTypeName(date);
            const shiftStart = new Date(date);
            const shiftEnd = new Date(date);

            if (shiftType === 'Day') {
                shiftStart.setUTCHours(8, 0, 0, 0);
                shiftEnd.setUTCHours(20, 0, 0, 0);
            } else if (date.getUTCHours() >= 20) {
                shiftStart.setUTCHours(20, 0, 0, 0);
                shiftEnd.setUTCDate(shiftEnd.getUTCDate() + 1);
                shiftEnd.setUTCHours(8, 0, 0, 0);
            } else {
                shiftStart.setUTCDate(shiftStart.getUTCDate() - 1);
                shiftStart.setUTCHours(20, 0, 0, 0);
                shiftEnd.setUTCHours(8, 0, 0, 0);
            }

            return { shiftType, shiftStart, shiftEnd };
        }

        function getRemainingShiftHours(currentTime, shiftEnd) {
            return Math.max(0, (shiftEnd.getTime() - currentTime.getTime()) / (60 * 60 * 1000));
        }

        function getNextShiftStart(currentTime) {
            return new Date(getShiftWindow(currentTime).shiftEnd);
        }

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
            const generateBtn = document.getElementById('generateBtn');
            
            if (date && loadingTime && parseFloat(loadingTime) > 0) {
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
                // SAFE DOM ACCESS: Wait for elements to be available
                await new Promise(resolve => setTimeout(resolve, 100));
                
                const dateEl = document.getElementById('date');
                const startHourEl = document.getElementById('startHour');
                const loadingTimeEl = document.getElementById('loadingTime');
                
                if (!dateEl || !startHourEl || !loadingTimeEl) {
                    console.warn('Form elements not ready, retrying...');
                    setTimeout(() => loadExistingSchedule(), 200);
                    return;
                }
                
                const data = await makeRequest(`/api/challans?machine_id=${machineId}&section_name=${encodeURIComponent(sectionName)}`);
                
                if (data.length > 0) {
                    console.log(`✓ Found ${data.length} existing records - loading...`);
                    scheduleRows = [];
                    rowToRecordMap.clear();

                    data.forEach((record, index) => {
                        const startTime = new Date(record.start_time);
                        const endTime = new Date(record.end_time);
                        
                        let expectedEnd = record.expected_end_time 
                            ? new Date(record.expected_end_time)
                            : startTime;
                        
                        const dateKey = startTime.toISOString().split('T')[0];
                        
                        const row = {
                            dateKey: dateKey,
                            start_datetime: startTime.toISOString(),
                            end_datetime: endTime.toISOString(),
                            expected_end: expectedEnd.toISOString(),
                            loading_hours: record.loading_duration !== null ? record.loading_duration : null,
                            stop_hours: record.machine_stop_time || 0,
                            challan_index: null,
                            record_id: record.id,
                            stop_start_datetime: record.stop_start_datetime || null,
                            stop_end_datetime: record.stop_end_datetime || null,
                            stop_remarks: record.stop_remarks || ''
                        };
                        
                        scheduleRows.push(row);
                        rowToRecordMap.set(index, record.id);
                    });
                    
                    // TODO Step 5: Enhanced SAFE FORM POPULATION - Better auto-fill from first record
                    const firstRecord = data[0];
                    if (firstRecord && firstRecord.start_time) {
                        const startDate = new Date(firstRecord.start_time);
                        
                        // Always set date and start hour from first record
                        if (dateEl) dateEl.value = startDate.toISOString().split('T')[0];
                        if (startHourEl) startHourEl.value = startDate.getUTCHours().toString();
                        
                        // Calculate total loading time from all challan rows with loading_duration > 0
                        const challanRows = data.filter(r => r.loading_duration !== null && r.loading_duration > 0);
                        if (challanRows.length > 0 && loadingTimeEl) {
                            const totalLoadingTime = challanRows.reduce((sum, row) => sum + (parseFloat(row.loading_duration) || 0), 0);
                            loadingTimeEl.value = totalLoadingTime.toFixed(1);
                            console.log(`Auto-filled form: Date=${startDate.toISOString().split('T')[0]}, StartHour=${startDate.getUTCHours()}, TotalLoading=${totalLoadingTime.toFixed(1)}h from ${challanRows.length} challans`);
                        }
                    }
                } else {
                    console.log('📭 No existing records - will auto-generate 3 default cycles (21 rows)');
                    // AUTO-GENERATE DEFAULT SCHEDULE (Step 3)
                    generateSchedule();
                }
                    
                    if (data.length > 0) {
                        setScheduleGenerated(); // ✅ Enable editing after loading existing
                    }
                    renderScheduleTable();
                    console.log(`✓ Loaded ${data.length} rows from database`);
                    
                    // START AUTO-GEN MONITOR IF ROWS EXIST
                    if (scheduleRows.length > 0) {
                        startAutoGenMonitor();
                    }
                }
            } catch (error) {
                console.error('Error loading existing schedule:', error);
                console.log('📭 Table empty or error - auto-generating default schedule...');
                // Auto-generate even on error (fallback)
                generateSchedule();
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
         * CONTINUOUS SHIFT SCHEDULING SYSTEM
         * Each row represents ONE shift block (12 hours max: Day 08:00-20:00, Night 20:00-08:00)
         * Breaks total loading time into continuous 12-hour shift blocks
         * Partial last shift if remaining hours < 12
         */
        function generateSchedule() {
            // SAFE DOM ACCESS
            const dateEl = document.getElementById('date');
            const startHourEl = document.getElementById('startHour');
            const loadingTimeEl = document.getElementById('loadingTime');
            
            if (!dateEl || !startHourEl || !loadingTimeEl) {
                console.error('Form elements not found:', { dateEl, startHourEl, loadingTimeEl });
                alert('Form not ready. Please refresh the page.');
                return;
            }
            
            let dateStr = dateEl.value;
            let startHour = parseInt(startHourEl.value);
            const totalLoadingTime = parseFloat(loadingTimeEl.value) || 0;
            
            // CONTINUOUS MODE: If schedule exists, continue from last end datetime
            // This allows multiple cycles without gaps
            if (scheduleRows && scheduleRows.length > 0) {
                const lastRow = scheduleRows[scheduleRows.length - 1];
                if (lastRow && lastRow.end_datetime) {
                    const lastEndTime = new Date(lastRow.end_datetime);
                    // Update form to show continuation start
                    dateStr = lastEndTime.toISOString().split('T')[0];
                    startHour = lastEndTime.getUTCHours();
                    console.log(`Continuing from previous end: ${dateStr} ${startHour}:00`);
                }
            }
            
            if (!dateStr || totalLoadingTime <= 0) {
                alert("Please fill Date and Total Loading Time (>0).");
                return;
            }
            
            // Calculate final completion datetime
            // Example: 27 Apr 2026 08:00 + 84 hours = 30 Apr 2026 12:00
            const startDateTime = new Date(`${dateStr}T${String(startHour).padStart(2, '0')}:00:00Z`);
            let remainingLoadingTime = totalLoadingTime;
            generatedChallans = [];
            scheduleRows = [];
            let currentTime = new Date(startDateTime);
            let rowIndex = 0;
            
            // ============================================================================
            // CYCLE-BASED SCHEDULING (12-hour shifts = Day/Night)
            // ============================================================================
            // 84 hours = 7 rows × 12 hrs = 1 complete cycle
            // After each cycle: mark cycle complete, next row continues immediately
            // First 20 rows visible, auto-append 10 more when schedule reaches current time
            // ============================================================================
            const CYCLE_ROWS = 7; // 7 rows × 12 hrs = 84 hours per cycle
            const INITIAL_VISIBLE_ROWS = 20; // First 20 rows visible
            const AUTO_APPEND_ROWS = 10; // Auto-add 10 more when needed
            
            const initialTargetRows = INITIAL_VISIBLE_ROWS;
            let targetRows = initialTargetRows;
            
            let cycleNumber = 1;
            let generatedRows = 0;
            const MAX_ROWS = 21; // 3 cycles x 7 rows/cycle

            while (generatedRows < MAX_ROWS && remainingLoadingTime > 0) {
                // Safety check: prevent infinite loop
                if (generatedRows > MAX_ROWS) {
                    console.error('Safety: stopping after max rows');
                    break;
                }

                // Get shift window (Day: 08:00-20:00, Night: 20:00-08:00)
                const { shiftType, shiftEnd } = getShiftWindow(currentTime);
                const remainingInShift = getRemainingShiftHours(currentTime, shiftEnd);
                
                // Calculate loading hours for this shift: min(remaining, remaining in shift, 12)
                // This handles partial last shift correctly (e.g., 4 hours for final partial shift)
                const loadingHours = Math.min(remainingLoadingTime, remainingInShift, 12);
                
                // Expected end = Start + Loading (NOT affected by stop time)
                const expectedEndShift = addHours(currentTime, loadingHours);
                // End = Expected + Stop (0 for now)
                const endShift = new Date(expectedEndShift);
                
                const isCycleComplete = false; // Define missing var
                const challan = {
                    start_time: currentTime.toISOString(),
                    end_time: endShift.toISOString(),
                    expected_end_time: expectedEndShift.toISOString(),
                    loading_duration: loadingHours,
                    machine_stop_time: 0,
                    challan_index: rowIndex,
                    shift_type: shiftType,
                    shift_end_time: shiftEnd.toISOString(),
                    cycle_number: cycleNumber,
                    is_cycle_complete: isCycleComplete
                };
                generatedChallans.push(challan);
                
                // Decrement remaining (NOT reset - continuous until exhausted)
                remainingLoadingTime -= loadingHours;
                // Next row starts from this row's end datetime
                currentTime = new Date(endShift);
                rowIndex++;
            }
            
            // Build scheduleRows array
            generatedChallans.forEach((challan, idx) => {
            const row = {
                    dateKey: new Date(challan.start_time).toISOString().split('T')[0],
                    start_datetime: challan.start_time,
                    end_datetime: challan.end_time,
                    expected_end: challan.expected_end_time,
                    loading_hours: challan.loading_duration,
                    stop_hours: 0,
                    challan_index: idx,
                    shift_type_name: challan.shift_type,
                    shift_end_datetime: challan.shift_end_time,
                    cycle_number: challan.cycle_number || null,
                    is_cycle_complete: challan.is_cycle_complete || false,
                    stop_start_datetime: null,
                    stop_end_datetime: null,
                    stop_remarks: ''
                };
                scheduleRows.push(row);
            });
            
            setScheduleGenerated(); // Enable editing after generation
            renderScheduleTable();
            const saveBtn = document.getElementById('saveBtn');
            if (saveBtn) saveBtn.style.display = 'inline-block';
            
            // Log completion datetime for verification
            const lastRow = scheduleRows[scheduleRows.length - 1];
            if (lastRow) {
                console.log(`Schedule generated: ${scheduleRows.length} shift rows`);
                console.log(`Final completion: ${formatDateTime(parseDate(lastRow.end_datetime))}`);
            }
            
            // Auto-save silently so database records are created for editing
            saveChallans(false).catch(err => console.error("Auto-save failed", err));
        }

        function updateCycleNumbers() {
            const loadingTimeEl = document.getElementById('loadingTime');
            const totalLoadingTime = parseFloat(loadingTimeEl?.value); 
            
            let accumulatedLoading = 0;
            let currentCycle = 1;

            if (!scheduleRows) return;

            scheduleRows.forEach(row => {
                const rowLoading = parseFloat(row.loading_hours) || 0;
                
                // If the user hasn't typed a total loading time yet, show placeholder cycles
                if (!totalLoadingTime || isNaN(totalLoadingTime) || totalLoadingTime <= 0) {
                    row.cycle_number = '?';
                    row.is_cycle_complete = false;
                    return;
                }
                
                const remainingInCycle = totalLoadingTime - accumulatedLoading;
                
                row.cycle_number = currentCycle;
                
                if (rowLoading >= remainingInCycle - 0.001 && rowLoading > 0) {
                    row.is_cycle_complete = true;
                    accumulatedLoading = 0;
                    currentCycle++;
                } else {
                    row.is_cycle_complete = false;
                    accumulatedLoading += rowLoading;
                }
            });
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
            
            updateCycleNumbers();
            
            
            // Debug: Verify we're rendering all rows
            console.log('Rendering table with', scheduleRows.length, 'rows');

        let tableHTML = `
            <h3>Generated Shift Schedule (Cycle-Based: 7 rows × 12 hrs = 84 hrs/cycle | Continuous Day/Night) <span id="autoGenStatus" style="color: #28a745; font-size: 0.9em;">⏳ Initializing...</span></h3>
                    <p style="color: #666; font-size: 14px;">
                        <strong>Cycle:</strong> 7 rows × 12 hrs = 84 hrs | 
                        <strong>Day Shift:</strong> 08:00-20:00 | 
                        <strong>Night Shift:</strong> 20:00-08:00 | 
                Auto-gen 3 cycles when end date = today-1
                    </p>
                    <table style="width: 100%; border-collapse: collapse; min-width: 920px;">

                    <thead style="position: sticky; top: 0; background-color: #f0f0f0; z-index: 10;">
                        <tr>
                <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">Start Date & Time</th>
                            <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0;">Cycle</th>
                            <th style="padding: 10px; border: 1px solid #ccc; text-align: center; background-color: #f0f0f0; min-width: 120px;">Shift</th>
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
                // FIXED: Use start_datetime date for BOTH shifts (01/04 for day & night)
                const startDateTime = parseDate(row.start_datetime);
                const shiftDateKey = getShiftDateKey(startDateTime);
                const shiftDate = getShiftDate(startDateTime);  // "Jan 04, 2026"
                const startTimeStr = formatTime(startDateTime);  // "20:00"
                let displayStartTime = `${shiftDate}, ${startTimeStr}`;
                
                // Expected End Date and Time (moved below - now using formatDateTime for timeline-based display)
                
                // End Date and Time: Use ACTUAL timeline date, NOT shift display date
                // The end time should show the real calendar date (timeline-based), not the "shift display" date
                const endDateTime = parseDate(row.end_datetime);
                const endStr = formatDateTime(endDateTime);
                
                // Expected End Date and Time: Also use ACTUAL timeline for consistency
                const expectedEndDateTime = parseDate(row.expected_end);
                const expectedEndStr = formatDateTime(expectedEndDateTime);
                
                
                // Determine if this row has loading hours (not empty or null)
                const hasLoading = row.loading_hours !== null && row.loading_hours !== undefined && row.loading_hours > 0;
                const loadingDisplay = hasLoading ? row.loading_hours : '-';
                const stopTriggerTitle = row.stop_remarks
                    ? `${formatStopHours(row.stop_hours)}h | ${row.stop_remarks.replace(/"/g, '&quot;')}`
                    : 'Click to set machine stop start/end and remark';
                
                // Row styling - empty rows (no loading) are slightly faded
                const rowStyle = `background-color: ${rowIndex % 2 === 0 ? '#fff' : '#f9f9f9'}; ${!hasLoading ? 'opacity: 0.6;' : ''}`;
                
                tableHTML += `
                    <tr data-row-index="${rowIndex}" data-date-key="${row.dateKey}" style="${rowStyle}">
<td class="start-datetime-cell" style="padding: 8px; border: 1px solid #ccc; text-align: center; white-space: nowrap; font-weight: ${hasLoading ? 'bold' : 'normal'};">
                            ${displayStartTime}
                        </td>
                        <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">
                            ${row.is_cycle_complete 
                                ? `<span style="background: #28a745; color: #fff; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">✅ C${row.cycle_number}</span>`
                                : `<span style="background: #e9ecef; color: #495057; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">C${row.cycle_number}</span>`
                            }
                        </td>
                        <td class="shift-cell" style="padding: 8px; border: 1px solid #ccc; text-align: center; min-width: 120px;">
                            ${getShiftType(parseDate(row.start_datetime))}
                        </td>
                        <td class="loading-hours-cell" style="padding: 8px; border: 1px solid #ccc; text-align: center;">
                            ${loadingDisplay}
                        </td>
    <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">
                        <input type="text" 
                               class="machine-stop-time-input" 
                               data-row-index="${rowIndex}"
                               data-date-key="${row.dateKey}"
                               data-record-id="${rowToRecordMap.get(rowIndex) || ''}"
                               value="${formatStopHours(row.stop_hours)}" 
                               readonly
                               style="width: 100px; padding: 5px; text-align: center; border: 1px solid #ddd; border-radius: 4px; background-color: ${scheduleGenerated ? '#fff' : '#f0f0f0'}; cursor: ${scheduleGenerated ? 'pointer' : 'not-allowed'}; opacity: ${scheduleGenerated ? '1' : '0.5'};"
                               onclick="${scheduleGenerated ? 'openStopTimeModal(' + rowIndex + ')' : 'showScheduleWarning(&quot;Generate schedule first!&quot;)'}"
                               title="${scheduleGenerated ? stopTriggerTitle : '🚫 Generate Schedule first to enable editing'}">
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
                <strong>Note:</strong> Click any Machine Stop Time field to open the popup, choose stop start/end date-time, and save a remark.
                The stop duration is calculated automatically and all following rows are recalculated to keep the schedule continuous.
            </p>
        `;
        document.getElementById('scheduleTable').innerHTML = tableHTML;
        document.getElementById('saveBtn').style.display = 'inline-block';
    }


async function saveChallans(showAlert = true) {
        if (scheduleRows.length === 0) {
            showScheduleWarning('No schedule rows to save. Please generate schedule first.');
            return;
        }

        const date = document.getElementById('date').value;
        const startHour = parseInt(document.getElementById('startHour').value);
        const loadingTime = parseFloat(document.getElementById('loadingTime').value);
        // Use scheduleRows.length instead of trying to get from non-existent input
        const numberOfRows = Math.min(20, scheduleRows.length || 20); // Cap for backend max 1000, default 20 as confirmed

        // CRITICAL: Read machine stop times from the ACTUAL table input fields
        // This ensures we save exactly what the user sees and has edited in the table
        const machineStopTimes = [];
        const challanStopTimes = new Map(); // challan_index -> max_stop_time
        
        scheduleRows.forEach((row, index) => {
            if (row.challan_index !== null && row.challan_index !== undefined) {
                const challanIndex = row.challan_index;
                
        // Safe value from data (no DOM query - prevents null.value error)
                const rowStopTime = parseFloat(row.stop_hours) || 0;
                
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
            record_id: rowToRecordMap.get(index) || null,
            stop_start_datetime: row.stop_start_datetime || null,
            stop_end_datetime: row.stop_end_datetime || null,
            stop_remarks: row.stop_remarks || ''
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
                if (showAlert) {
                    alert(`✅ ${data.message}\n\nSaved ${savedCount} row(s) to table: ${tableName}\n\nAll visible rows from UI table are now in database (1:1 mapping)`);
                }
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

    function formatStopHours(hours) {
        const value = parseFloat(hours) || 0;
        return value.toFixed(2).replace(/\.?0+$/, '') || '0';
    }

    function toDateTimeLocalValue(date) {
        const year = date.getUTCFullYear();
        const month = String(date.getUTCMonth() + 1).padStart(2, '0');
        const day = String(date.getUTCDate()).padStart(2, '0');
        const hours = String(date.getUTCHours()).padStart(2, '0');
        const minutes = String(date.getUTCMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${minutes}`;
    }

    function parseDateTimeLocalAsUtc(value) {
        if (!value || !value.includes('T')) return null;
        const [datePart, timePart] = value.split('T');
        const [year, month, day] = datePart.split('-').map(Number);
        const [hours, minutes] = timePart.split(':').map(Number);
        return new Date(Date.UTC(year, month - 1, day, hours || 0, minutes || 0, 0, 0));
    }

    function updateStopDurationPreview() {
        const preview = document.getElementById('stopDurationPreview');
        const startValue = document.getElementById('stopStartDateTime').value;
        const endValue = document.getElementById('stopEndDateTime').value;
        const startDate = parseDateTimeLocalAsUtc(startValue);
        const endDate = parseDateTimeLocalAsUtc(endValue);

        if (!preview) return;
        if (!startDate || !endDate) {
            preview.textContent = '0 hours';
            return;
        }

        const durationHours = Math.max(0, (endDate.getTime() - startDate.getTime()) / (60 * 60 * 1000));
        preview.textContent = `${formatStopHours(durationHours)} hours`;
    }

    function openStopTimeModal(rowIndex) {
        if (!scheduleRows || scheduleRows.length === 0 || rowIndex < 0 || rowIndex >= scheduleRows.length) {
            showScheduleWarning('No schedule rows available. Please generate schedule first.');
            return;
        }
        
        if (!scheduleGenerated) {
            showScheduleWarning('⚠️ Please click "Generate Schedule" first to enable Machine Stop Time editing.');
            return;
        }
        
        const dateEl = document.getElementById('date');
        const loadingTimeEl = document.getElementById('loadingTime');
        if (!dateEl?.value || !loadingTimeEl?.value || parseFloat(loadingTimeEl.value) <= 0) {
            showScheduleWarning('Please complete the form (Date & Loading Time > 0) first.');
            return;
        }

        activeStopModalRowIndex = rowIndex;
        const row = scheduleRows[rowIndex];
        const modal = document.getElementById('stopTimeModal');
        const rowLabel = document.getElementById('stopTimeModalRowLabel');
        const startInput = document.getElementById('stopStartDateTime');
        const endInput = document.getElementById('stopEndDateTime');
        const remarksInput = document.getElementById('stopRemarks');

        const defaultStart = row.stop_start_datetime
            ? parseDate(row.stop_start_datetime)
            : parseDate(row.expected_end || row.start_datetime);
        const defaultEnd = row.stop_end_datetime
            ? parseDate(row.stop_end_datetime)
            : addHours(defaultStart, parseFloat(row.stop_hours) || 0);

        rowLabel.textContent = `Row ${rowIndex + 1} | ${getShiftTypeName(parseDate(row.start_datetime))} Shift`;
        startInput.value = toDateTimeLocalValue(defaultStart);
        endInput.value = toDateTimeLocalValue(defaultEnd);
        remarksInput.value = row.stop_remarks || '';
        updateStopDurationPreview();

        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    // Show warning with schedule banner
    function showScheduleWarning(message) {
        // Create/update warning banner
        let banner = document.getElementById('schedule-warning-banner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'schedule-warning-banner';
            banner.style.cssText = `
                background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
                border: 2px solid #ffc107;
                border-radius: 8px;
                padding: 16px 20px;
                margin: 20px 0;
                text-align: center;
                font-weight: bold;
                box-shadow: 0 4px 12px rgba(255,193,7,0.3);
                animation: pulse 2s infinite;
            `;
            document.querySelector('.form-group.mt-3').parentNode.insertBefore(banner, document.querySelector('.form-group.mt-3'));
        }
        banner.innerHTML = `
            <div style="font-size: 18px; margin-bottom: 8px;">🚫 Machine Stop Time Editing Disabled</div>
            <div style="font-size: 14px; color: #856404;">${message}</div>
            <div style="margin-top: 12px;">
                <button onclick="scrollToGenerate()" class="btn btn-warning" style="font-weight: bold;">
                    📅 Generate Schedule First
                </button>
            </div>
            <style>
                @keyframes pulse {
                    0%, 100% { transform: scale(1); }
                    50% { transform: scale(1.02); }
                }
            </style>
        `;
        banner.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function scrollToGenerate() {
        document.getElementById('generateBtn')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        hideScheduleWarning();
    }

    function hideScheduleWarning() {
        const banner = document.getElementById('schedule-warning-banner');
        if (banner) banner.remove();
    }

    // Global flag for schedule generation state - declared earlier in file
let scheduleGenerated = true; // ✅ FIXED: Editing enabled by default

function setScheduleGenerated() {
        scheduleGenerated = true;
        hideScheduleWarning();
    }

    function closeStopTimeModal() {
        activeStopModalRowIndex = null;
        const modal = document.getElementById('stopTimeModal');
        if (modal) {
            modal.style.display = 'none';
        }
        document.body.style.overflow = '';
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

        let currentShiftStart = fromRowIndex === 0
            ? parseDate(scheduleRows[0].start_datetime)
            : parseDate(scheduleRows[fromRowIndex - 1].end_datetime);

        for (let i = fromRowIndex; i < scheduleRows.length; i++) {
            const row = scheduleRows[i];
            row.start_datetime = currentShiftStart.toISOString();
            row.dateKey = currentShiftStart.toISOString().split('T')[0];
            const shiftWindow = getShiftWindow(currentShiftStart);
            const shiftEnd = shiftWindow.shiftEnd;
            const loadingHours = parseFloat(row.loading_hours) || 0;
            const stopHours = parseFloat(row.stop_hours) || 0;
            row.shift_type_name = shiftWindow.shiftType;
            row.shift_end_datetime = shiftEnd.toISOString();
            row.loading_hours = loadingHours;
            row.expected_end = addHours(currentShiftStart, loadingHours).toISOString();
            row.end_datetime = addHours(parseDate(row.expected_end), stopHours).toISOString();
            currentShiftStart = parseDate(row.end_datetime);
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
        // FIXED: Use dedicated endpoint - no full resave needed after stop time updates
        // Changes sync on next "Save Challans"
        if (indicator) {
            indicator.textContent = '✓ Local';
            indicator.style.color = '#28a745';
            setTimeout(() => {
                indicator.textContent = '';
            }, 2000);
        }
    }

    async function saveStopTimeModal() {
        if (activeStopModalRowIndex === null || !scheduleRows[activeStopModalRowIndex]) {
            closeStopTimeModal();
            return;
        }

        const rowIndex = activeStopModalRowIndex;
        const row = scheduleRows[rowIndex];
        const recordId = row.record_id || rowToRecordMap.get(rowIndex);
        
        if (!recordId) {
            alert('No database record found. Please save schedule first.');
            return;
        }

        const startInput = document.getElementById('stopStartDateTime');
        const endInput = document.getElementById('stopEndDateTime');
        const remarksInput = document.getElementById('stopRemarks');

        const stopStart = parseDateTimeLocalAsUtc(startInput.value);
        const stopEnd = parseDateTimeLocalAsUtc(endInput.value);

        if (!stopStart || !stopEnd) {
            alert('Please select both Start Date & Time and End Date & Time.');
            return;
        }

        if (stopEnd.getTime() < stopStart.getTime()) {
            alert('End Date & Time must be after Start Date & Time.');
            return;
        }

        const durationHours = Math.max(0, (stopEnd.getTime() - stopStart.getTime()) / (60 * 60 * 1000));
        
        // Show saving
        const indicator = document.getElementById(`stop-time-indicator-${rowIndex}`);
        if (indicator) {
            indicator.textContent = 'Saving...';
            indicator.style.color = '#0d6efd';
        }

        try {
            const response = await makeRequest('/api/challans/update-stop-time-details', {
                method: 'POST',
                body: JSON.stringify({
                    machine_id: {{ $machine->id }},
                    section_name: '{{ $section->name }}',
                    record_id: recordId,
                    stop_start_datetime: stopStart.toISOString(),
                    stop_end_datetime: stopEnd.toISOString(),
                    machine_stop_hours: durationHours,
                    remarks: remarksInput.value.trim()
                })
            });
            
            if (response.success) {
                row.stop_hours = durationHours;
                row.stop_start_datetime = stopStart.toISOString();
                row.stop_end_datetime = stopEnd.toISOString();
                row.stop_remarks = remarksInput.value.trim();
                
                recalculateScheduleFromRow(rowIndex);
                closeStopTimeModal();
                
                if (indicator) {
                    indicator.textContent = '✓ Saved';
                    indicator.style.color = '#198754';
                    setTimeout(() => indicator.textContent = '', 3000);
                }
                
                console.log(`Stop time saved: ${durationHours}h (${response.updated_count} rows cascaded)`);
            } else {
                throw new Error(response.message || 'Update failed');
            }
        } catch (error) {
            console.error('Stop time save error:', error);
            if (indicator) {
                indicator.textContent = 'Error';
                indicator.style.color = '#dc3545';
            }
            alert(`Error: ${error.message}`);
        }
    }   
    
    /**
     * Save ALL rows after stop time update to maintain 1:1 sync
     * This ensures database matches UI exactly after cascade recalculation
     */
    async function saveAllRowsAfterStopTimeUpdate() {
        // TODO Step 2: Add defensive null-checking for form elements
        const dateEl = document.getElementById('date');
        const startHourEl = document.getElementById('startHour');
        const loadingTimeEl = document.getElementById('loadingTime');
        const rowsEl = document.getElementById('rows');
        
        if (!dateEl || !startHourEl || !loadingTimeEl) {
            throw new Error('Required form elements not found. Please refresh the page.');
        }
        
        const date = dateEl.value || '';
        const startHour = parseInt(startHourEl.value) || 0;
        const loadingTime = parseFloat(loadingTimeEl.value) || 0;
        const numberOfRows = rowsEl ? parseInt(rowsEl.value) || 10 : 10;
        
        if (!date || loadingTime <= 0) {
            throw new Error('Please complete the form (Date and Loading Time > 0) before saving stop times.');
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
            record_id: rowToRecordMap.get(index) || null,
            stop_start_datetime: row.stop_start_datetime || null,
            stop_end_datetime: row.stop_end_datetime || null,
            stop_remarks: row.stop_remarks || ''
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
                                           step="1" 
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
                                       step="1" 
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
                                   step="1" 
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

@extends('layouts.app')

@section('title', "Machine Report - {$machine->name}")

@section('content')
<h1>Machine Report: {{ $machine->name }}</h1>

<div style="background-color: #e3f2fd; padding: 10px; border-radius: 4px; margin-bottom: 20px; text-align: center;">
    <strong>Current Table:</strong> 
    <code style="background-color: #fff; padding: 4px 8px; border-radius: 3px; font-size: 16px;">
        M{{ $machineNumber }}_{{ $section }}
    </code>
    <span style="color: #666; font-size: 14px; margin-left: 10px;">
        (All data for this machine-section is stored in this table only)
    </span>
</div>

<div class="section-buttons">
    @foreach($sections as $sec)
        <button class="section-btn {{ $sec === $section ? 'active' : '' }}" 
                onclick="switchSection('{{ $sec }}')">
            {{ $sec }}
        </button>
    @endforeach
</div>

<div class="report-controls" style="margin: 20px 0; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
    <button class="btn btn-success" onclick="addNewRow()">
        <span style="margin-right: 5px;">+</span> Add New Row
    </button>
    <button class="btn btn-primary" onclick="loadReport()">
        🔄 Refresh
    </button>
</div>

<div id="alertContainer"></div>

<div id="loadingIndicator" style="display: none; text-align: center; padding: 20px;">
    <div style="display: inline-block; padding: 10px 20px; background: #f0f0f0; border-radius: 4px;">
        Loading...
    </div>
</div>

<div id="cycleSummaryContainer"></div>

<div id="reportTableContainer" style="overflow-x: auto;">
    <table id="reportTable" style="width: 100%; border-collapse: collapse; margin-top: 20px; display: none;">
        <thead style="position: sticky; top: 0; background-color: #f0f0f0; z-index: 10;">
            <tr>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 150px;">Start Date & Time</th>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 80px;">Cycle</th>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 100px;">Shift</th>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 100px;">Loading Time</th>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 120px;">Machine Stop Time</th>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 150px;">Expected End Date and Time</th>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 150px;">End Date and Time</th>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 80px;">Actions</th>
            </tr>
        </thead>
        <tbody id="reportTableBody">
            <!-- Rows will be dynamically inserted here -->
        </tbody>
    </table>
</div>

<div id="emptyState" style="text-align: center; padding: 40px; color: #666; display: none;">
    <p style="font-size: 18px; margin-bottom: 10px;">No records found</p>
    <p style="font-size: 14px;">Click "Add New Row" to create a new entry</p>
</div>

@endsection

@push('styles')
<style>
    .editable-cell {
        padding: 4px;
        border: 1px solid transparent;
        border-radius: 3px;
        transition: all 0.2s;
        min-width: 80px;
    }
    .editable-cell:hover {
        background-color: #f8f9fa;
        border-color: #dee2e6;
    }
    .editable-cell.editing {
        background-color: #fff3cd;
        border-color: #ffc107;
    }
    .editable-input {
        width: 100%;
        padding: 4px 6px;
        border: 1px solid #007bff;
        border-radius: 3px;
        font-size: 14px;
    }
    .editable-textarea {
        width: 100%;
        padding: 4px 6px;
        border: 1px solid #007bff;
        border-radius: 3px;
        font-size: 14px;
        resize: vertical;
        min-height: 60px;
    }
    .save-indicator {
        display: inline-block;
        margin-left: 5px;
        font-size: 12px;
    }
    .save-indicator.saving {
        color: #ffc107;
    }
    .save-indicator.saved {
        color: #28a745;
    }
    .save-indicator.error {
        color: #dc3545;
    }
    .row-actions {
        display: flex;
        gap: 5px;
        justify-content: center;
    }
    .btn-sm {
        padding: 4px 8px;
        font-size: 12px;
        border-radius: 4px;
        border: none;
        cursor: pointer;
    }
    .section-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 10px;
        margin-bottom: 20px;
    }
    .section-btn {
        padding: 8px 16px;
        border: 1px solid #0d6efd;
        background: #fff;
        color: #0d6efd;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s;
    }
    .section-btn.active {
        background: #0d6efd;
        color: #fff;
    }
    .section-btn:hover {
        background: #e9ecef;
    }
    .section-btn.active:hover {
        background: #0b5ed7;
    }
</style>
@endpush

@push('scripts')
<script>
    const machineNumber = {{ $machineNumber }};
    const currentSection = '{{ $section }}';
    let editingCell = null;
    let autoSaveTimeout = null;

    // Load report on page load
    document.addEventListener('DOMContentLoaded', function() {
        loadReport();
    });

    function formatDisplayDate(dateString) {
        if (!dateString) return '-';
        try {
            const date = new Date(dateString);
            if (isNaN(date.getTime())) return dateString;
            
            // CRITICAL: Use UTC for consistent display with database and schedule
            const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            const month = monthNames[date.getUTCMonth()];
            const day = String(date.getUTCDate()).padStart(2, '0');
            const year = date.getUTCFullYear();
            const hours = String(date.getUTCHours()).padStart(2, '0');
            const minutes = String(date.getUTCMinutes()).padStart(2, '0');
            
            return `${month} ${day}, ${year}, ${hours}:${minutes}`;
        } catch(e) {
            return dateString;
        }
    }

    /**
     * Switch to a different section
     */
    function switchSection(section) {
        window.location.href = `/reports/machine/{{ $machine->name }}/${section}`;
    }

    /**
     * Load report data for current section
     */
    async function loadReport() {
        const table = document.getElementById('reportTable');
        const tableBody = document.getElementById('reportTableBody');
        const loadingIndicator = document.getElementById('loadingIndicator');
        const emptyState = document.getElementById('emptyState');

        // Show loading
        table.style.display = 'none';
        emptyState.style.display = 'none';
        loadingIndicator.style.display = 'block';
        tableBody.innerHTML = '';

        try {
            const data = await makeRequest(`/api/reports/machine/get?machine_number=${machineNumber}&section=${currentSection}`);
            
            loadingIndicator.style.display = 'none';

            if (data.success && data.data.length > 0) {
                renderTable(data.data);
                table.style.display = 'table';
                emptyState.style.display = 'none';
            } else {
                table.style.display = 'none';
                emptyState.style.display = 'block';
            }
        } catch (error) {
            loadingIndicator.style.display = 'none';
            showAlert('Failed to load report: ' + error.message, 'error');
            console.error('Error loading report:', error);
        }
    }

    /**
     * Render table with data
     */
    function renderTable(records) {
        const tableBody = document.getElementById('reportTableBody');
        tableBody.innerHTML = '';

        let accumulatedLoading = 0;
        let cycleNumber = 1;
        let hasCycles = false;
        let cycleStartDisplay = '';
        
        let cycleSummaryHTML = `<div style="background-color: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #ddd; margin-bottom: 20px;">
            <h4 style="margin-top:0; margin-bottom: 10px; color: #0d6efd;">Cycle Summary (84 Hrs)</h4>
            <ul style="margin:0; padding-left: 20px; list-style-type: none;">`;

        records.forEach((record, index) => {
            const rowLoading = parseFloat(record.loading_duration) || 0;
            
            if (accumulatedLoading === 0) {
                cycleStartDisplay = formatDisplayDate(record.start_time);
            }
            
            accumulatedLoading += rowLoading;
            
            let isCycleComplete = false;
            let currentCycleNumber = cycleNumber;
            
            // Cycle boundary logic: 
            // 1. Partial shift (loading < 11.9) indicating a batch end
            // 2. Hits 84 hours (full cycle)
            if (rowLoading > 0 && (rowLoading < 11.9 || accumulatedLoading >= 84 - 0.001)) {
                isCycleComplete = true;
                hasCycles = true;
                const cycleEndDisplay = formatDisplayDate(record.end_time);
                
                cycleSummaryHTML += `<li style="margin-bottom: 8px; font-size: 15px;">
                    <strong>Cycle ${cycleNumber}:</strong> ${cycleStartDisplay} <span style="color:#6c757d; margin:0 10px;">➔</span> <span style="color: #198754; font-weight: bold;">✅ End Date & Time: ${cycleEndDisplay}</span>
                </li>`;
                
                accumulatedLoading = 0; // reset for next cycle
                cycleNumber++;
            }

            const row = createTableRow(record, isCycleComplete, currentCycleNumber);
            if (isCycleComplete) {
                row.classList.add('cycle-complete-row');
                row.style.backgroundColor = '#f1f8f5'; // Light green tint for cycle ends
            }
            
            tableBody.appendChild(row);
        });
        
        cycleSummaryHTML += `</ul></div>`;
        
        const summaryContainer = document.getElementById('cycleSummaryContainer');
        if (summaryContainer) {
            summaryContainer.innerHTML = hasCycles ? cycleSummaryHTML : '';
        }
    }

    function getShiftMarkup(datetimeValue) {
        if (!datetimeValue) {
            return '<span style="color: #6c757d;">-</span>';
        }

        const date = new Date(datetimeValue);
        if (isNaN(date.getTime())) {
            return '<span style="color: #6c757d;">-</span>';
        }

        const hour = date.getHours();

        if (hour >= 8 && hour < 20) {
            return '<span style="background: #fff3cd; color: #856404; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">Day</span>';
        }

        return '<span style="background: #cce7ff; color: #004085; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">Night</span>';
    }

    function createShiftCell(startTimeValue) {
        const cell = document.createElement('td');
        cell.style.padding = '8px';
        cell.style.border = '1px solid #ccc';
        cell.style.textAlign = 'center';
        cell.className = 'shift-cell';
        cell.innerHTML = getShiftMarkup(startTimeValue);
        return cell;
    }

    /**
     * Create a table row for a record
     */
    function createTableRow(record, isCycleComplete = false, cycleNum = null) {
        const row = document.createElement('tr');
        row.setAttribute('data-id', record.id);
        row.setAttribute('data-record-id', record.id);

        // Start Time (full datetime)
        let startTimeDisplay = '';
        if (record.start_time) {
            try {
                const startDate = new Date(record.start_time);
                if (!isNaN(startDate.getTime())) {
                    const year = startDate.getFullYear();
                    const month = String(startDate.getMonth() + 1).padStart(2, '0');
                    const day = String(startDate.getDate()).padStart(2, '0');
                    const hours = String(startDate.getHours()).padStart(2, '0');
                    const minutes = String(startDate.getMinutes()).padStart(2, '0');
                    startTimeDisplay = `${year}-${month}-${day}T${hours}:${minutes}`;
                }
            } catch (e) {
                startTimeDisplay = record.start_time;
            }
        }
        const startTimeCell = createEditableCell(startTimeDisplay, 'datetime-local', 'start_time', record.id);
        startTimeCell.style.fontWeight = 'bold';
        row.appendChild(startTimeCell);

        // Cycle Cell
        const cycleCell = document.createElement('td');
        cycleCell.style.padding = '8px';
        cycleCell.style.border = '1px solid #ccc';
        cycleCell.style.textAlign = 'center';
        if (cycleNum) {
            if (isCycleComplete) {
                cycleCell.innerHTML = `<span style="background: #28a745; color: #fff; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">✅ C${cycleNum}</span>`;
            } else {
                cycleCell.innerHTML = `<span style="background: #e9ecef; color: #495057; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">C${cycleNum}</span>`;
            }
        } else {
            cycleCell.innerHTML = '-';
        }
        row.appendChild(cycleCell);

        const shiftCell = createShiftCell(record.start_time || startTimeDisplay);
        row.appendChild(shiftCell);

        // Loading Time
        const loadingCell = createEditableCell(record.loading_duration || '0', 'number', 'loading_duration', record.id);
        row.appendChild(loadingCell);

        // Machine Stop Time
        const stopTimeCell = createEditableCell(record.machine_stop_time || '0', 'number', 'machine_stop_time', record.id);
        row.appendChild(stopTimeCell);

        // Expected End Date and Time (from database)
        let expectedEndTimeDisplay = formatDisplayDate(record.expected_end_datetime || record.end_time);
        const expectedEndCell = document.createElement('td');
        expectedEndCell.style.padding = '8px';
        expectedEndCell.style.border = '1px solid #ccc';
        expectedEndCell.style.textAlign = 'center';
        expectedEndCell.textContent = expectedEndTimeDisplay;
        row.appendChild(expectedEndCell);

        // End Date and Time
        let endTimeDisplay = formatDisplayDate(record.end_time);
        let rawEndTime = record.end_time || '';
        
        // Only show end time if the cycle is complete
        const finalEndTimeDisplay = isCycleComplete ? endTimeDisplay : '-';
        
        const endTimeCell = createEditableCell(finalEndTimeDisplay, 'datetime-local', 'end_time', record.id, rawEndTime);
        if (isCycleComplete) {
            endTimeCell.style.color = '#198754';
            endTimeCell.style.fontWeight = 'bold';
            // Add a small calendar icon prefix if complete, matching Image 3
            const valSpan = endTimeCell.querySelector('.cell-value');
            if (valSpan && valSpan.textContent !== '-') {
                valSpan.innerHTML = '📅 ' + valSpan.innerHTML;
            }
        }
        row.appendChild(endTimeCell);

        // Actions
        const actionsCell = document.createElement('td');
        actionsCell.style.padding = '8px';
        actionsCell.style.border = '1px solid #ccc';
        actionsCell.innerHTML = `
            <div class="row-actions">
                <button class="btn-sm btn-danger" onclick="deleteRecord(${record.id})" title="Delete">
                    🗑️
                </button>
            </div>
        `;
        row.appendChild(actionsCell);

        return row;
    }

    /**
     * Create an editable cell
     */
    function createEditableCell(value, type, fieldName, recordId, rawValue = null) {
        const cell = document.createElement('td');
        cell.style.padding = '4px';
        cell.style.border = '1px solid #ccc';
        cell.className = 'editable-cell';
        cell.setAttribute('data-field', fieldName);
        cell.setAttribute('data-record-id', recordId);

        let displayValue = value || '';
        
        if ((fieldName === 'start_time' || fieldName === 'end_time') && displayValue && displayValue !== '-') {
            try {
                const date = new Date(displayValue);
                if (!isNaN(date.getTime())) {
                    // Display format: MMM dd, YYYY, HH:mm (UTC)
                    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    const month = monthNames[date.getUTCMonth()];
                    const day = String(date.getUTCDate()).padStart(2, '0');
                    const year = date.getUTCFullYear();
                    const hours = String(date.getUTCHours()).padStart(2, '0');
                    const minutes = String(date.getUTCMinutes()).padStart(2, '0');
                    displayValue = `${month} ${day}, ${year}, ${hours}:${minutes}`;
                }
            } catch (e) {}
        }
        
        const actualRawValue = rawValue !== null ? rawValue : (value || '');
        cell.innerHTML = `<span class="cell-value" data-raw-value="${actualRawValue}">${displayValue}</span><span class="save-indicator" id="indicator-${recordId}-${fieldName}"></span>`;

        cell.addEventListener('click', function(e) {
            if (editingCell && editingCell !== cell) {
                cancelEdit();
            }
            if (!cell.classList.contains('editing')) {
                startEdit(cell, type);
            }
        });

        return cell;
    }

    /**
     * Start editing a cell
     */
    function startEdit(cell, type) {
        if (editingCell) {
            cancelEdit();
        }

        editingCell = cell;
        cell.classList.add('editing');
        const valueSpan = cell.querySelector('.cell-value');
        
        let currentValue = '';
        if (valueSpan) {
            currentValue = valueSpan.getAttribute('data-raw-value');
            if (currentValue === null || currentValue === undefined || currentValue === '') {
                currentValue = valueSpan.textContent.trim();
                if (currentValue === '-') currentValue = '';
            }
        }
        
        if (type === 'datetime-local' && currentValue && !currentValue.includes('T')) {
            try {
                const date = new Date(currentValue);
                if (!isNaN(date.getTime())) {
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    const hours = String(date.getHours()).padStart(2, '0');
                    const minutes = String(date.getMinutes()).padStart(2, '0');
                    currentValue = `${year}-${month}-${day}T${hours}:${minutes}`;
                }
            } catch (e) {}
        }
        
        let input;
        if (type === 'textarea') {
            input = document.createElement('textarea');
            input.className = 'editable-textarea';
            input.value = currentValue;
        } else {
            input = document.createElement('input');
            input.type = type;
            input.className = 'editable-input';
            input.value = currentValue;
            if (type === 'number') {
                input.step = '0.01';
                input.min = '0';
            }
        }

        if (valueSpan) {
            valueSpan.style.display = 'none';
        }
        cell.insertBefore(input, cell.firstChild);
        input.focus();
        if (type !== 'textarea') {
            input.select();
        }

        input.addEventListener('blur', function() {
            finishEdit(cell, input.value);
        });

        input.addEventListener('keydown', function(e) {
            if (type === 'textarea') {
                if (e.key === 'Escape') {
                    cancelEdit();
                }
            } else {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    finishEdit(cell, input.value);
                } else if (e.key === 'Escape') {
                    cancelEdit();
                }
            }
        });
    }

    function cancelEdit() {
        if (editingCell) {
            editingCell.classList.remove('editing');
            const input = editingCell.querySelector('input, textarea');
            if (input) {
                input.remove();
            }
            const valueSpan = editingCell.querySelector('.cell-value');
            if (valueSpan) {
                valueSpan.style.display = '';
            }
            editingCell = null;
        }
    }

    function finishEdit(cell, newValue) {
        const fieldName = cell.getAttribute('data-field');
        const recordId = cell.getAttribute('data-record-id');
        
        cancelEdit();
        
        const valueSpan = cell.querySelector('.cell-value');
        if (valueSpan) {
            let displayValue = newValue || '';
            if ((fieldName === 'start_time' || fieldName === 'end_time') && displayValue && displayValue !== '-') {
                try {
                    const date = new Date(displayValue);
                    if (!isNaN(date.getTime())) {
                        const year = date.getFullYear();
                        const month = String(date.getMonth() + 1).padStart(2, '0');
                        const day = String(date.getDate()).padStart(2, '0');
                        const hours = String(date.getHours()).padStart(2, '0');
                        const minutes = String(date.getMinutes()).padStart(2, '0');
                        displayValue = `${year}-${month}-${day} ${hours}:${minutes}`;
                    }
                } catch (e) {}
            }
            valueSpan.setAttribute('data-raw-value', newValue || '');
            valueSpan.textContent = displayValue;
        }

        if (fieldName === 'start_time') {
            const row = cell.closest('tr');
            const shiftCell = row ? row.querySelector('.shift-cell') : null;
            if (shiftCell) {
                shiftCell.innerHTML = getShiftMarkup(newValue);
            }
        }

        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
        }
        autoSaveTimeout = setTimeout(() => {
            saveField(recordId, fieldName, newValue);
        }, 500);
    }

    async function saveField(recordId, fieldName, value) {
        if (recordId.toString().startsWith('new-')) return;

        const indicator = document.getElementById(`indicator-${recordId}-${fieldName}`);
        if (indicator) {
            indicator.textContent = '...';
            indicator.className = 'save-indicator saving';
        }

        try {
            const data = await makeRequest('/api/reports/machine/save', {
                method: 'POST',
                body: JSON.stringify({
                    machine_number: machineNumber,
                    section: currentSection,
                    id: recordId,
                    [fieldName === 'machine_stop_time' ? 'machine_stop_time' : fieldName]: value
                })
            });

            if (data.success) {
                if (indicator) {
                    indicator.textContent = '✓';
                    indicator.className = 'save-indicator saved';
                    setTimeout(() => { indicator.textContent = ''; }, 2000);
                }
                if (fieldName === 'loading_duration' || fieldName === 'start_time') {
                    loadReport();
                }
            } else {
                throw new Error(data.message);
            }
        } catch (error) {
            if (indicator) {
                indicator.textContent = '✗';
                indicator.className = 'save-indicator error';
            }
            showAlert('Failed to save: ' + error.message, 'error');
        }
    }

    function addNewRow() {
        const tableBody = document.getElementById('reportTableBody');
        const table = document.getElementById('reportTable');
        const emptyState = document.getElementById('emptyState');

        table.style.display = 'table';
        emptyState.style.display = 'none';

        const newRecord = {
            id: 'new-' + Date.now(),
            start_time: '',
            end_time: '',
            loading_duration: '0',
            machine_stop_time: '0'
        };

        const row = createTableRow(newRecord);
        tableBody.appendChild(row);
        row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        setTimeout(() => {
            saveNewRow(newRecord.id, row);
        }, 100);
    }

    async function saveNewRow(tempId, row) {
        const formData = {
            machine_number: machineNumber,
            section: currentSection,
        };

        const cells = row.querySelectorAll('[data-field]');
        cells.forEach(cell => {
            const field = cell.getAttribute('data-field');
            const valueSpan = cell.querySelector('.cell-value');
            if (valueSpan) {
                formData[field] = valueSpan.getAttribute('data-raw-value') || valueSpan.textContent.trim();
            }
        });

        try {
            const data = await makeRequest('/api/reports/machine/save', {
                method: 'POST',
                body: JSON.stringify(formData)
            });

            if (data.success && data.data) {
                row.setAttribute('data-id', data.data.id);
                row.setAttribute('data-record-id', data.data.id);
                row.querySelectorAll('[data-record-id]').forEach(el => {
                    el.setAttribute('data-record-id', data.data.id);
                });
                showAlert('Record created successfully', 'success');
                loadReport();
            }
        } catch (error) {
            showAlert('Failed to create record: ' + error.message, 'error');
        }
    }

    async function deleteRecord(id) {
        if (!confirm('Are you sure you want to delete this record?')) return;

        try {
            const data = await makeRequest('/api/reports/machine/delete', {
                method: 'POST',
                body: JSON.stringify({
                    machine_number: machineNumber,
                    section: currentSection,
                    id: id
                })
            });

            if (data.success) {
                showAlert('Record deleted successfully', 'success');
                loadReport();
            } else {
                throw new Error(data.message);
            }
        } catch (error) {
            showAlert('Failed to delete record: ' + error.message, 'error');
        }
    }

    function showAlert(message, type) {
        const container = document.getElementById('alertContainer');
        const alert = document.createElement('div');
        alert.className = `alert alert-${type === 'success' ? 'success' : 'danger'}`;
        alert.style.padding = '10px 15px';
        alert.style.marginBottom = '15px';
        alert.style.borderRadius = '4px';
        alert.style.backgroundColor = type === 'success' ? '#d4edda' : '#f8d7da';
        alert.style.color = type === 'success' ? '#155724' : '#721c24';
        alert.style.border = `1px solid ${type === 'success' ? '#c3e6cb' : '#f5c6cb'}`;
        alert.textContent = message;
        
        container.innerHTML = '';
        container.appendChild(alert);
        
        setTimeout(() => { alert.remove(); }, 3000);
    }

    async function makeRequest(url, options = {}) {
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            }
        };
        
        const response = await fetch(url, { ...defaultOptions, ...options });
        if (!response.ok) {
            const error = await response.json();
            throw new Error(error.message || 'Request failed');
        }
        return await response.json();
    }
</script>
@endpush

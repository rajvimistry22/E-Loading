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

<div id="reportTableContainer" style="overflow-x: auto;">
    <table id="reportTable" style="width: 100%; border-collapse: collapse; margin-top: 20px; display: none;">
        <thead style="position: sticky; top: 0; background-color: #f0f0f0; z-index: 10;">
            <tr>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 80px;">Start Time</th>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 80px;">End Time</th>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 100px;">Loading Duration (hrs)</th>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 100px;">Stop Time (hrs)</th>
                <th style="padding: 10px; border: 1px solid #ccc; min-width: 120px;">Actions</th>
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

        records.forEach(record => {
            const row = createTableRow(record);
            tableBody.appendChild(row);
        });
    }

    /**
     * Create a table row for a record
     */
    function createTableRow(record) {
        const row = document.createElement('tr');
        row.setAttribute('data-id', record.id);
        row.setAttribute('data-record-id', record.id);

        // Start Time (full datetime)
        // Parse ISO string to display format, or use as-is if already formatted
        let startTimeDisplay = '';
        if (record.start_time) {
            try {
                const startDate = new Date(record.start_time);
                if (!isNaN(startDate.getTime())) {
                    // Format as YYYY-MM-DDTHH:mm for datetime-local input
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
        row.appendChild(startTimeCell);

        // End Time (full datetime)
        let endTimeDisplay = '';
        if (record.end_time) {
            try {
                const endDate = new Date(record.end_time);
                if (!isNaN(endDate.getTime())) {
                    // Format as YYYY-MM-DDTHH:mm for datetime-local input
                    const year = endDate.getFullYear();
                    const month = String(endDate.getMonth() + 1).padStart(2, '0');
                    const day = String(endDate.getDate()).padStart(2, '0');
                    const hours = String(endDate.getHours()).padStart(2, '0');
                    const minutes = String(endDate.getMinutes()).padStart(2, '0');
                    endTimeDisplay = `${year}-${month}-${day}T${hours}:${minutes}`;
                }
            } catch (e) {
                endTimeDisplay = record.end_time;
            }
        }
        const endTimeCell = createEditableCell(endTimeDisplay, 'datetime-local', 'end_time', record.id);
        row.appendChild(endTimeCell);

        // Loading Duration
        const loadingCell = createEditableCell(record.loading_duration || '0', 'number', 'loading_duration', record.id);
        row.appendChild(loadingCell);

        // Stop Time
        const stopTimeCell = createEditableCell(record.machine_stop_time || '0', 'number', 'machine_stop_time', record.id);
        row.appendChild(stopTimeCell);

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
    function createEditableCell(value, type, fieldName, recordId) {
        const cell = document.createElement('td');
        cell.style.padding = '4px';
        cell.style.border = '1px solid #ccc';
        cell.className = 'editable-cell';
        cell.setAttribute('data-field', fieldName);
        cell.setAttribute('data-record-id', recordId);

        let displayValue = value || '';
        
        // For datetime fields, format for display
        if ((fieldName === 'start_time' || fieldName === 'end_time') && displayValue) {
            try {
                const date = new Date(displayValue);
                if (!isNaN(date.getTime())) {
                    // Display in readable format: YYYY-MM-DD HH:mm
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');
                    const hours = String(date.getHours()).padStart(2, '0');
                    const minutes = String(date.getMinutes()).padStart(2, '0');
                    displayValue = `${year}-${month}-${day} ${hours}:${minutes}`;
                }
            } catch (e) {
                // Keep original value if parsing fails
            }
        }
        
        cell.innerHTML = `<span class="cell-value">${displayValue}</span><span class="save-indicator" id="indicator-${recordId}-${fieldName}"></span>`;

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
        const currentValue = valueSpan ? valueSpan.textContent.trim() : '';
        
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
            } else if (type === 'datetime-local') {
                // Ensure proper format for datetime-local input
                if (currentValue && !currentValue.includes('T')) {
                    // If value is just time, try to combine with today's date
                    const today = new Date();
                    const [hours, minutes] = currentValue.split(':');
                    if (hours && minutes) {
                        today.setHours(parseInt(hours), parseInt(minutes), 0, 0);
                        const year = today.getFullYear();
                        const month = String(today.getMonth() + 1).padStart(2, '0');
                        const day = String(today.getDate()).padStart(2, '0');
                        input.value = `${year}-${month}-${day}T${hours}:${minutes}`;
                    }
                }
            }
        }

        // Replace content
        if (valueSpan) {
            valueSpan.style.display = 'none';
        }
        cell.insertBefore(input, cell.firstChild);
        input.focus();
        if (type !== 'textarea') {
            input.select();
        }

        // Save on Enter (for non-textarea) or blur
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

    /**
     * Cancel editing
     */
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

    /**
     * Finish editing and save
     */
    function finishEdit(cell, newValue) {
        const fieldName = cell.getAttribute('data-field');
        const recordId = cell.getAttribute('data-record-id');
        
        cancelEdit();
        
        // Update display - format datetime fields for display
        const valueSpan = cell.querySelector('.cell-value');
        if (valueSpan) {
            let displayValue = newValue || '';
            
            // For datetime fields, format for display
            if ((fieldName === 'start_time' || fieldName === 'end_time') && displayValue) {
                try {
                    const date = new Date(displayValue);
                    if (!isNaN(date.getTime())) {
                        // Display in readable format: YYYY-MM-DD HH:mm
                        const year = date.getFullYear();
                        const month = String(date.getMonth() + 1).padStart(2, '0');
                        const day = String(date.getDate()).padStart(2, '0');
                        const hours = String(date.getHours()).padStart(2, '0');
                        const minutes = String(date.getMinutes()).padStart(2, '0');
                        displayValue = `${year}-${month}-${day} ${hours}:${minutes}`;
                    }
                } catch (e) {
                    // Keep original value if parsing fails
                }
            }
            
            valueSpan.textContent = displayValue;
        }

        // Auto-save with debounce
        if (autoSaveTimeout) {
            clearTimeout(autoSaveTimeout);
        }
        
        autoSaveTimeout = setTimeout(() => {
            // For datetime fields, ensure we send ISO format
            let valueToSave = newValue;
            if ((fieldName === 'start_time' || fieldName === 'end_time') && newValue) {
                try {
                    const date = new Date(newValue);
                    if (!isNaN(date.getTime())) {
                        valueToSave = date.toISOString().slice(0, 16); // YYYY-MM-DDTHH:mm
                    }
                } catch (e) {
                    // Keep original value
                }
            }
            saveField(recordId, fieldName, valueToSave);
        }, 500);
    }

    /**
     * Save a single field
     */
    async function saveField(recordId, fieldName, value) {
        const indicator = document.getElementById(`indicator-${recordId}-${fieldName}`);
        if (indicator) {
            indicator.textContent = '💾';
            indicator.className = 'save-indicator saving';
        }

        try {
            // Get current row data
            const row = document.querySelector(`tr[data-record-id="${recordId}"]`);
            if (!row) return;

            const formData = {
                machine_number: machineNumber,
                section: currentSection,
                id: recordId,
            };

            // Get all field values from the row
            const cells = row.querySelectorAll('[data-field]');
            cells.forEach(cell => {
                const field = cell.getAttribute('data-field');
                const valueSpan = cell.querySelector('.cell-value');
                if (valueSpan) {
                    let value = valueSpan.textContent.trim();
                    
                    // For datetime fields, convert datetime-local format to ISO string
                    if ((field === 'start_time' || field === 'end_time') && value) {
                        // If value is in datetime-local format (YYYY-MM-DDTHH:mm), convert to ISO
                        if (value.includes('T')) {
                            // Already in correct format, just ensure it's a valid datetime
                            const date = new Date(value);
                            if (!isNaN(date.getTime())) {
                                formData[field] = date.toISOString().slice(0, 16); // YYYY-MM-DDTHH:mm format
                            } else {
                                formData[field] = value;
                            }
                        } else {
                            formData[field] = value;
                        }
                    } else {
                        formData[field] = value;
                    }
                }
            });

            // Override with the changed field
            // For datetime fields, ensure proper format
            if ((fieldName === 'start_time' || fieldName === 'end_time') && value) {
                // If value is in datetime-local format (YYYY-MM-DDTHH:mm), convert to ISO
                if (value.includes('T')) {
                    const date = new Date(value);
                    if (!isNaN(date.getTime())) {
                        formData[fieldName] = date.toISOString().slice(0, 16); // YYYY-MM-DDTHH:mm
                    } else {
                        formData[fieldName] = value;
                    }
                } else {
                    formData[fieldName] = value;
                }
            } else {
                formData[fieldName] = value;
            }

            const data = await makeRequest('/api/reports/machine/save', {
                method: 'POST',
                body: JSON.stringify(formData)
            });

            if (data.success) {
                // Log table name for verification (visible in browser console)
                if (data.table_name) {
                    console.log(`✓ Data saved to table: ${data.table_name}`);
                }
                
                if (indicator) {
                    indicator.textContent = '✓';
                    indicator.className = 'save-indicator saved';
                    setTimeout(() => {
                        indicator.textContent = '';
                        indicator.className = 'save-indicator';
                    }, 2000);
                }
            } else {
                throw new Error(data.message || 'Save failed');
            }
        } catch (error) {
            if (indicator) {
                indicator.textContent = '✗';
                indicator.className = 'save-indicator error';
            }
            showAlert('Failed to save: ' + error.message, 'error');
            console.error('Error saving field:', error);
        }
    }

    /**
     * Add a new row
     */
    function addNewRow() {
        const tableBody = document.getElementById('reportTableBody');
        const table = document.getElementById('reportTable');
        const emptyState = document.getElementById('emptyState');

        // Show table if hidden
        table.style.display = 'table';
        emptyState.style.display = 'none';

        // Create new record
        const newRecord = {
            id: 'new-' + Date.now(),
            start_time: '',
            end_time: '',
            loading_duration: '0',
            machine_stop_time: '0'
        };

        const row = createTableRow(newRecord);
        tableBody.appendChild(row);

        // Scroll to new row
        row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

        // Auto-save new row
        setTimeout(() => {
            saveNewRow(newRecord.id, row);
        }, 100);
    }

    /**
     * Save a new row
     */
    async function saveNewRow(tempId, row) {
        const formData = {
            machine_number: machineNumber,
            section: currentSection,
        };

        // Get all field values
        const cells = row.querySelectorAll('[data-field]');
        cells.forEach(cell => {
            const field = cell.getAttribute('data-field');
            const valueSpan = cell.querySelector('.cell-value');
            if (valueSpan) {
                formData[field] = valueSpan.textContent.trim();
            }
        });

        try {
            const data = await makeRequest('/api/reports/machine/save', {
                method: 'POST',
                body: JSON.stringify(formData)
            });

            if (data.success && data.data) {
                // Update row with real ID
                row.setAttribute('data-id', data.data.id);
                row.setAttribute('data-record-id', data.data.id);
                row.querySelectorAll('[data-record-id]').forEach(el => {
                    el.setAttribute('data-record-id', data.data.id);
                });
                showAlert('Record created successfully', 'success');
            }
        } catch (error) {
            showAlert('Failed to create record: ' + error.message, 'error');
            console.error('Error creating record:', error);
        }
    }

    /**
     * Delete a record
     */
    async function deleteRecord(recordId) {
        if (!confirm('Are you sure you want to delete this record?')) {
            return;
        }

        try {
            const data = await makeRequest('/api/reports/machine/delete', {
                method: 'POST',
                body: JSON.stringify({
                    machine_number: machineNumber,
                    section: currentSection,
                    id: recordId
                })
            });

            if (data.success) {
                const row = document.querySelector(`tr[data-record-id="${recordId}"]`);
                if (row) {
                    row.remove();
                }
                showAlert('Record deleted successfully', 'success');
                
                // Check if table is empty
                const tableBody = document.getElementById('reportTableBody');
                if (tableBody.children.length === 0) {
                    document.getElementById('reportTable').style.display = 'none';
                    document.getElementById('emptyState').style.display = 'block';
                }
            } else {
                throw new Error(data.message || 'Delete failed');
            }
        } catch (error) {
            showAlert('Failed to delete record: ' + error.message, 'error');
            console.error('Error deleting record:', error);
        }
    }

    /**
     * Show alert message
     */
    function showAlert(message, type = 'success') {
        const container = document.getElementById('alertContainer');
        const alert = document.createElement('div');
        alert.className = `alert alert-${type === 'error' ? 'error' : 'success'}`;
        alert.textContent = message;
        container.innerHTML = '';
        container.appendChild(alert);

        // Auto-hide after 5 seconds
        setTimeout(() => {
            alert.remove();
        }, 5000);
    }
</script>
@endpush

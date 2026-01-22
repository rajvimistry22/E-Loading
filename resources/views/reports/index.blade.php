@extends('layouts.app')

@section('title', 'Daily Machine-Section Report')

@section('content')
    <h2>Daily Machine-Section Report</h2>

    <div class="form-group" style="margin: 20px 0;">
        <label for="reportDate" style="font-weight: bold; margin-right: 10px;">Select Date: </label>
        <input type="date" id="reportDate" class="form-control" required style="display: inline-block; width: auto; margin-right: 10px;">
        <button onclick="fetchReport()" class="btn btn-primary">Fetch Data</button>
    </div>

    <div id="reportTable"></div>
@endsection

@push('scripts')
<script>
    // Set default date to today on page load
    document.addEventListener('DOMContentLoaded', function() {
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('reportDate').value = today;
    });
    
    async function fetchReport() {
        const date = document.getElementById('reportDate').value;
        
        if (!date) {
            alert("Please select a date");
            return;
        }

        const reportDiv = document.getElementById('reportTable');
        reportDiv.innerHTML = `<p style="text-align: center; padding: 20px;">Loading report for <strong>${date}</strong>...</p>`;

        try {
            // Get CSRF token
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            
            if (!csrfToken) {
                throw new Error('CSRF token not found. Please refresh the page.');
            }
            
            // Use fetch directly with proper error handling
            const response = await fetch('/api/reports/daily', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ date: date })
            });
            
            if (!response.ok) {
                let errorMessage = `Server error: ${response.status}`;
                try {
                    const errorData = await response.json();
                    errorMessage = errorData.message || errorData.error || errorMessage;
                } catch (e) {
                    const errorText = await response.text();
                    errorMessage = errorText || errorMessage;
                }
                throw new Error(errorMessage);
            }
            
            const data = await response.json();

            if (data.success) {
                if (!data.data || data.data.length === 0) {
                    reportDiv.innerHTML = `<p style="color: #666; font-style: italic;">No data found for ${date}</p>`;
                    return;
                }

                // Format date for display (convert YYYY-MM-DD to DD-MM-YYYY)
                const dateParts = data.date.split('-');
                const displayDate = `${dateParts[2]}-${dateParts[1]}-${dateParts[0]}`;
                
                let html = `
                    <h3>Report for ${displayDate}</h3>
                    <style>
                        .report-table {
                            width: 100%;
                            border-collapse: collapse;
                            margin-top: 20px;
                            font-size: 14px;
                        }
                        .report-table th, .report-table td {
                            padding: 10px;
                            border: 1px solid #ddd;
                            text-align: left;
                        }
                        .report-table th {
                            background-color: #f0f0f0;
                            font-weight: bold;
                            text-align: center;
                        }
                        .report-table td {
                            text-align: center;
                        }
                        .machine-header {
                            background-color: #e0e0e0;
                            font-weight: bold;
                            font-size: 16px;
                        }
                        .section-header {
                            background-color: #f5f5f5;
                            font-weight: bold;
                        }
                    </style>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Machine</th>
                                <th>Section</th>
                                <th>End Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                // Group data by machine and section for rowspan calculation
                const grouped = {};
                data.data.forEach(record => {
                    const key = `${record.machine}_${record.section}`;
                    if (!grouped[key]) {
                        grouped[key] = {
                            machine: record.machine,
                            section: record.section,
                            records: []
                        };
                    }
                    grouped[key].records.push(record);
                });

                // Calculate rowspans
                const machineRowSpans = {};
                Object.values(grouped).forEach(group => {
                    if (!machineRowSpans[group.machine]) {
                        machineRowSpans[group.machine] = 0;
                    }
                    machineRowSpans[group.machine] += group.records.length;
                });

                // Render table rows
                let currentMachine = null;
                let currentSection = null;
                let machineRowSpanUsed = 0;
                let sectionRowSpanUsed = 0;

                data.data.forEach((record, index) => {
                    const isNewMachine = currentMachine !== record.machine;
                    const isNewSection = currentSection !== record.section || isNewMachine;
                    
                    html += `<tr>`;
                    
                    // Machine name (only in first row of machine)
                    if (isNewMachine) {
                        currentMachine = record.machine;
                        currentSection = null; // Reset section when machine changes
                        machineRowSpanUsed = 0;
                        const rowspan = machineRowSpans[record.machine];
                        html += `<td class="machine-header" rowspan="${rowspan}">${record.machine}</td>`;
                    }
                    
                    // Section name (only in first row of section)
                    if (isNewSection) {
                        currentSection = record.section;
                        sectionRowSpanUsed = 0;
                        const sectionRecords = grouped[`${record.machine}_${record.section}`].records;
                        html += `<td class="section-header" rowspan="${sectionRecords.length}">${record.section}</td>`;
                    }
                    
                    // Record details - only end date & time
                    html += `
                        <td>${record.end_datetime_display}</td>
                    `;
                    
                    html += `</tr>`;
                    
                    machineRowSpanUsed++;
                    sectionRowSpanUsed++;
                });

                html += `
                        </tbody>
                    </table>
                `;
                reportDiv.innerHTML = html;
            } else {
                reportDiv.innerHTML = `<p style="color:red;">Error: ${data.message || 'Failed to fetch report'}</p>`;
            }
        } catch (error) {
            console.error("Error fetching report:", error);
            reportDiv.innerHTML = `<p style="color:red;">Error loading data: ${error.message || 'An unexpected error occurred'}</p>`;
        }
    }
</script>
@endpush

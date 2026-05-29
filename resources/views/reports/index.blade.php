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

    function getShiftLabel(datetimeValue) {
        if (!datetimeValue) {
            return '-';
        }

        const date = new Date(datetimeValue);
        if (isNaN(date.getTime())) {
            return '-';
        }

        const hour = date.getUTCHours();
        return (hour >= 8 && hour < 20) ? 'Day' : 'Night';
    }
    
    async function fetchReport() {
        const date = document.getElementById('reportDate').value;
        
        if (!date) {
            alert("Please select a date");
            return;
        }

        const reportDiv = document.getElementById('reportTable');
        reportDiv.innerHTML = `<p style="text-align: center; padding: 20px;">Loading report for <strong>${date}</strong>...</p>`;

        try {
            const data = await makeRequest('/api/reports/daily', {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({ date })
            });

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
                                <th>Cycle</th>
                                <th>Shift</th>
                                <th>End Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                data.data.forEach((record, index) => {
                    html += `
                        <tr>
                            <td class="machine-header">${record.machine}</td>
                            <td class="section-header">${record.section}</td>
                            <td><span style="background: #28a745; color: #fff; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: bold;">✅ ${record.cycle || '-'}</span></td>
                            <td>${getShiftLabel(record.end_datetime)}</td>
                            <td style="white-space: nowrap;"><span style="color: #198754; font-weight: bold;">📅 ${record.end_datetime_display}</span></td>
                        </tr>
                    `;
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

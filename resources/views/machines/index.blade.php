@extends('layouts.app')

@section('title', 'Select Machine & Section')

@section('content')
<style>
    body { background-color: #f4f7f9; font-family: sans-serif; }
    .selection-container { text-align: center; padding-top: 50px; }
    h1 { color: #0033ff; font-weight: bold; font-size: 32px; margin-bottom: 40px; }
    
    .machine-select-wrapper { 
        margin-bottom: 40px; 
        display: flex; 
        justify-content: center; 
        align-items: center; 
        gap: 15px; 
    }
    .machine-select-wrapper label { font-size: 18px; font-weight: bold; }
    .form-select-custom { padding: 8px 15px; border-radius: 5px; border: 1px solid #ccc; width: 150px; font-size: 16px; }

    .section-buttons {
        display: flex;
        justify-content: center;
        align-items: center;
        flex-wrap: wrap; 
        gap: 15px;
        margin: 40px auto;
        max-width: 90%;
    }

    .section-btn {
        background-color: #2196f3;
        color: white;
        border: none;
        padding: 12px 20px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: bold;
        cursor: pointer;
        min-width: 100px;
        transition: background-color 0.2s;
    }

    .section-btn:hover { background-color: #1976d2; }
    .section-btn.active { background-color: #0d47a1; outline: 2px solid #000; }

    .report-btn-wrapper { margin-top: 60px; }
    .btn-report { 
        background-color: #2196f3; 
        color: white; 
        padding: 12px 60px; 
        border-radius: 8px; 
        text-decoration: none; 
        font-weight: bold; 
        font-size: 18px; 
        display: inline-block; 
    }
</style>

<div class="selection-container">
    <h1>SELECT MACHINE & SECTION</h1>

    <div class="machine-select-wrapper">
        <label>Select Machine:</label>
        <select id="machineSelect" class="form-select-custom">
            <option value="">Select</option>
            @foreach($machines as $machine)
                <option value="{{ $machine->id }}" data-name="{{ $machine->name }}">{{ $machine->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="section-buttons" id="sectionButtons">
        @php 
            $standardSections = ['A-OUT','A-IN','B-OUT','B-IN','C-OUT','C-IN','D-OUT','D-IN'];
        @endphp

        @foreach($standardSections as $name)
            <button type="button" class="section-btn" onclick="navigateToSection('{{ $name }}', this)">
                {{ $name }}
            </button>
        @endforeach
    </div>

    <div class="report-btn-wrapper" style="display: flex; gap: 20px; justify-content: center;">
        <a href="{{ route('reports.index') }}" class="btn-report">REPORT (Legacy)</a>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function navigateToSection(sectionName, element) {
        const select = document.getElementById('machineSelect');
        const machineName = select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '';
        
        if (!machineName || machineName === "Select") {
            alert("Please select a machine first!");
            return;
        }

        // Redirects to /schedule/M-1/A-OUT
        window.location.href = `/schedule/${machineName}/${sectionName}`;
    }
</script>
@endpush
<?php

use App\Http\Controllers\ChallanController;
use App\Http\Controllers\MachineController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\MachineReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Machine selection page (home)
Route::get('/', [MachineController::class, 'index'])->name('machines.index');

// Report page
Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

// Machine Report System (NEW - Separate tables per machine-section)
Route::get('/reports/machine/{machineName}', [MachineReportController::class, 'index'])
    ->name('reports.machine.index');
Route::get('/reports/machine/{machineName}/{section}', [MachineReportController::class, 'index'])
    ->name('reports.machine.section');

/*
|--------------------------------------------------------------------------
| AJAX Routes
|--------------------------------------------------------------------------
*/

// Machine routes
Route::get('/api/machines', [MachineController::class, 'getMachines'])->name('api.machines');

// Challan routes
Route::get('/api/challans', [ChallanController::class, 'getChallans'])->name('api.challans.get');
Route::post('/api/challans/generate', [ChallanController::class, 'generate'])->name('api.challans.generate');
Route::post('/api/challans/update-stop-time', [ChallanController::class, 'updateStopTime'])->name('api.challans.update-stop-time');
Route::post('/api/challans/update-stop-time-cascade', [ChallanController::class, 'updateStopTimeWithCascade'])->name('api.challans.update-stop-time-cascade');
Route::post('/api/challans/delete-range', [ChallanController::class, 'deleteRange'])->name('api.challans.delete-range');
Route::put('/api/challans/{id}', [ChallanController::class, 'update'])->name('api.challans.update');

// Schedule routes (1:1 UI-to-Database mapping)
Route::post('/api/schedule/save-all', [\App\Http\Controllers\ScheduleController::class, 'saveAll'])->name('api.schedule.save-all');
Route::get('/api/schedule/get-all', [\App\Http\Controllers\ScheduleController::class, 'getAll'])->name('api.schedule.get-all');
Route::post('/api/schedule/update-stop-hours', [\App\Http\Controllers\ScheduleController::class, 'updateStopHours'])->name('api.schedule.update-stop-hours');

// Report routes (Legacy)
Route::post('/api/reports/daily', [ReportController::class, 'fetchDailyReport'])->name('api.reports.daily');

// Machine Report System API Routes (NEW)
Route::get('/api/reports/machine/get', [MachineReportController::class, 'getReport'])->name('api.reports.machine.get');
Route::post('/api/reports/machine/save', [MachineReportController::class, 'saveReport'])->name('api.reports.machine.save');
Route::post('/api/reports/machine/delete', [MachineReportController::class, 'deleteReport'])->name('api.reports.machine.delete');
Route::get('/api/reports/machine/sections', [MachineReportController::class, 'getSections'])->name('api.reports.machine.sections');

// Schedule routes
Route::get('/schedule/{machineName}/{sectionName}', [ChallanController::class, 'schedule'])
    ->name('challans.schedule');
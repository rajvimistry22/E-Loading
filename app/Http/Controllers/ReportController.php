<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\MachineSectionReport;
use App\Services\TableManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ReportController extends Controller
{
    /**
     * Display the daily report page
     */
    public function index()
    {
        return view('reports.index');
    }

    /**
     * Fetch daily report data (AJAX)
     * Returns only the final end_datetime per machine-section when the selected
     * date matches that final end date.
     */
    public function fetchDailyReport(Request $request)
    {
        try {
            $request->validate([
                'date' => 'required|date',
            ]);

            Log::info('Daily report requested for date: ' . $request->date);

            // Selected date is treated as the application's timezone day boundaries.
            // Then we convert that window to UTC for querying `end_datetime` (stored in UTC).
            $appTimezone = $request->input('timezone')
                ?: (config('app.timezone') ?: 'UTC');

            $selectedDateLocal = Carbon::parse($request->date, $appTimezone)->startOfDay();

            // Convert local-day window to UTC window for DB query.
            $selectedDate = $selectedDateLocal->copy()->setTimezone('UTC');


            Log::info('Daily report local window (app tz): ' . $selectedDateLocal->toDateTimeString() . ' .. ' . $selectedDateLocal->copy()->endOfDay()->toDateTimeString());
            Log::info('Daily report UTC window: ' . $selectedDate->toDateTimeString() . ' .. ' . $selectedDate->copy()->endOfDay()->toDateTimeString());



        // Get all machines
        $machines = Machine::where('is_active', true)->orderBy('name')->get();
        
        // Get all sections
        $sections = TableManager::getSections();
        
        // Array to store all report data in sequence
        $reportData = [];
        
        // Iterate through all machines and sections
        foreach ($machines as $machine) {
            // Extract machine number from machine name (e.g., "M-7" -> 7, "M7" -> 7)
            $machineNumber = $this->extractMachineNumber($machine->name);
            if (!$machineNumber) {
                continue; // Skip invalid machine names
            }
            
            foreach ($sections as $section) {
                // Generate table name
                $tableName = TableManager::getTableName($machineNumber, $section);
                
                // Check if table exists
                if (!TableManager::tableExists($tableName)) {
                    continue; // Skip non-existent tables
                }
                
                // Ensure table contains necessary columns (e.g., is_cycle_complete)
                TableManager::ensureStopDetailColumns($tableName);
                
                // Get model for this table
                $model = MachineSectionReport::forTableName($tableName);
                
            // Requirement: show only rows where cycle is marked completed (✅)
            // The DB column might be either `is_cycle_complete` or `is_cycle_completed`.
            $includeAll = $request->input('include_all', false);

            // 1. Fetch cycles that end within the selected report date (user timezone)
            // Shift windows (same report date key):
            // - Day:   08:00 -> 20:00 (same date)
            // - Night: 20:00 -> 08:00 (next date) but still belongs to the same report date.
            $dayStartUtc = $selectedDate->copy()->setTime(8, 0, 0)->format('Y-m-d H:i:s');
            $dayEndUtc   = $selectedDate->copy()->setTime(20, 0, 0)->format('Y-m-d H:i:s');

            $nightStartUtc = $selectedDate->copy()->setTime(20, 0, 0)->format('Y-m-d H:i:s');
            $nightEndUtc   = $selectedDate->copy()->addDay()->setTime(8, 0, 0)->format('Y-m-d H:i:s');

            $query = $model->where(function($q) use ($dayStartUtc, $dayEndUtc, $nightStartUtc, $nightEndUtc) {
                $q->whereBetween('end_datetime', [$dayStartUtc, $dayEndUtc])
                  ->orWhereBetween('end_datetime', [$nightStartUtc, $nightEndUtc]);
            });

            if (!$includeAll) {
                $columns = $model->getConnection()
                    ->getSchemaBuilder()
                    ->getColumnListing($model->getTable());

                if (in_array('is_cycle_complete', $columns, true)) {
                    $query->where('is_cycle_complete', true);
                } elseif (in_array('is_cycle_completed', $columns, true)) {
                    $query->where('is_cycle_completed', true);
                } else {
                    // Fallback: do not filter if neither column exists.
                    // (But your tables should have one of these.)
                }
            }

            
            $recordsOnDate = $query->orderBy('end_datetime')
                                   ->orderBy('row_no')
                                   ->get();
            
            // Decide timezone for display. Prefer request->timezone, else app.timezone, else UTC.
            $userTimezone = $request->input('timezone')
                ?: config('app.timezone')
                ?: 'UTC';

            foreach ($recordsOnDate as $record) {
                $endDateTimeUtc = $record->end_datetime
                    ? $record->end_datetime->copy()->setTimezone('UTC')
                    : null;



                if (!$endDateTimeUtc) continue;

                // 2. Determine cycle number by counting all previous completions in this table
                // Cycle number is based on previous completed cycles in the same table.
                $cycleQuery = $model->where(function($cycleQ) use ($record) {
                    $cycleQ->where(function($q) use ($record) {
                        $q->where('end_datetime', '<', $record->end_datetime)
                          ->orWhere(function ($qq) use ($record) {
                              $qq->where('end_datetime', '=', $record->end_datetime)
                                 ->where('row_no', '<=', $record->row_no);
                          });
                    });
                });

                // Apply whichever completion flag column exists.
                $columns = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());
                if (in_array('is_cycle_complete', $columns, true)) {
                    $cycleQuery->where('is_cycle_complete', true);
                } elseif (in_array('is_cycle_completed', $columns, true)) {
                    $cycleQuery->where('is_cycle_completed', true);
                }

                $cycleNumber = $cycleQuery->count();

                $sectionDisplayName = $this->formatSectionName($section);
                $endDisplay = $this->formatShiftDateTime($endDateTimeUtc);

                // Report display expects only completed rows.
                // Support both column spellings.
                $isCompleted = null;
                if (isset($record->is_cycle_complete)) {
                    $isCompleted = (bool) $record->is_cycle_complete;
                } elseif (isset($record->is_cycle_completed)) {
                    $isCompleted = (bool) $record->is_cycle_completed;
                }

                $reportData[] = [
                    'machine' => $machine->name,
                    'section' => $sectionDisplayName,
                    'cycle' => 'C' . $cycleNumber,
                    'end_datetime' => $endDateTimeUtc->toISOString(),
                    'end_datetime_display' => $endDisplay,
                    'row_no' => $record->row_no,
                    'loading_hours' => (float) $record->loading_hours,
                    'is_cycle_complete' => (bool) $isCompleted,
                ];
            }
        }
    }
    
    // Sort by machine name, then section, then row_no
    usort($reportData, function($a, $b) {
        // First sort by machine name
        $machineCompare = strcmp($a['machine'], $b['machine']);
        if ($machineCompare !== 0) {
            return $machineCompare;
        }
        
        // Then by section
        $sectionCompare = strcmp($a['section'], $b['section']);
        if ($sectionCompare !== 0) {
            return $sectionCompare;
        }
        
        // Finally by row_no
        return ($a['row_no'] ?? 0) <=> ($b['row_no'] ?? 0);
    });

        Log::info('Daily report returning ' . count($reportData) . ' records for date: ' . $request->date);

        return response()->json([
            'success' => true,
            'date' => $request->date,
            'include_all' => $includeAll,
            'data' => $reportData // Flat array, not grouped
        ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error in daily report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: ' . $e->getMessage(),
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error in daily report: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Error fetching report: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Extract machine number from machine name
     * 
     * @param string $machineName
     * @return int|null
     */
    private function extractMachineNumber(string $machineName): ?int
    {
        if (preg_match('/M-?(\d+)/', $machineName, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }
    
    /**
     * Format section name for display
     * AOUT -> A-OUT, AIN -> A-IN, etc.
     * 
     * @param string $section
     * @return string
     */
    private function formatSectionName(string $section): string
    {
        // If section is 4 characters (e.g., AOUT), insert hyphen after first character
        if (strlen($section) === 4) {
            return substr($section, 0, 1) . '-' . substr($section, 1);
        }
        // If section is 3 characters (e.g., AIN), insert hyphen after first character
        if (strlen($section) === 3) {
            return substr($section, 0, 1) . '-' . substr($section, 1);
        }
        return $section;
    }

    private function getShiftReportDate(Carbon $dateTime): Carbon
    {
        $shiftDate = $dateTime->copy()->startOfDay();

        if ($dateTime->hour < 8) {
            $shiftDate->subDay();
        }

        return $shiftDate;
    }

    /**
     * Get "shift business date" (for labeling) based on 08:00 cutoff.
     * Business day runs 08:00 -> 08:00.
     */
    private function getShiftBusinessDate(Carbon $dateTime): Carbon
    {
        $shiftDate = $dateTime->copy()->startOfDay();

        // Before 08:00 belongs to previous business day
        if ($dateTime->hour < 8) {
            $shiftDate->subDay();
        }

        return $shiftDate;
    }

    /**
     * Format datetime for display.
     * Keep the ORIGINAL clock time, but adjust only the DATE part to match
     * shift business date labeling (08:00 cutoff).
     */
    private function formatShiftDateTime(Carbon $dateTime): string
    {
        $businessDate = $this->getShiftBusinessDate($dateTime);

        // Preserve clock time from the original datetime
        $formatted = $businessDate->copy()
            ->setTimeFromTimeString($dateTime->format('H:i:s'));

        return $formatted->format('M d, Y, H:i');
    }
}

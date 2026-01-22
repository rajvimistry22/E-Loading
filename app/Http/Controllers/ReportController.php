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
     * Returns ALL records from ALL machine-section tables for the selected date
     * Only includes records where the end_datetime date matches the selected date
     * Only returns end_datetime (removed: loading_hours, machine_stop_hours, start_datetime, expected_end_datetime)
     */
    public function fetchDailyReport(Request $request)
    {
        try {
            $request->validate([
                'date' => 'required|date',
            ]);

            Log::info('Daily report requested for date: ' . $request->date);

            $selectedDate = Carbon::parse($request->date)->startOfDay();

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
                
                // Get model for this table
                $model = MachineSectionReport::forTableName($tableName);
                
                // Query records where the end date matches the selected date only
                $records = $model->whereDate('end_datetime', $selectedDate->toDateString())
                ->orderBy('row_no') // Order by row_no to maintain sequence
                ->orderBy('end_datetime') // Secondary sort by end_datetime
                ->get();
                
                // Add records to report data
                foreach ($records as $record) {
                    // Format section name for display (AOUT -> A-OUT)
                    $sectionDisplayName = $this->formatSectionName($section);
                    
                    // Format end date for display (matching image format: "Jan 22, 2026, 01:00")
                    $endDisplay = $record->end_datetime 
                        ? $record->end_datetime->setTimezone('UTC')->format('M d, Y, H:i')
                        : 'N/A';
                    
                    $reportData[] = [
                        'machine' => $machine->name,
                        'section' => $sectionDisplayName,
                        'end_datetime' => $record->end_datetime ? $record->end_datetime->setTimezone('UTC')->toISOString() : null,
                        'end_datetime_display' => $endDisplay,
                        'row_no' => $record->row_no,
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
}

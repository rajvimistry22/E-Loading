<?php

namespace App\Http\Controllers;

use App\Models\MachineSectionReport;
use App\Models\Machine;
use App\Services\TableManager;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * MachineReportController
 * 
 * Handles CRUD operations for machine-section reports with real-time AJAX synchronization
 */
class MachineReportController extends Controller
{
    /**
     * Display the machine report page
     * 
     * @param string $machineName
     * @param string|null $section
     * @return \Illuminate\View\View
     */
    public function index(string $machineName, ?string $section = null)
    {
        // Extract machine number
        $machineNumber = $this->extractMachineNumber($machineName);
        
        if (!$machineNumber) {
            abort(404, 'Invalid machine name');
        }

        // Get machine details
        $machine = Machine::where('name', $machineName)->first();
        
        if (!$machine) {
            abort(404, 'Machine not found');
        }

        // Get all sections
        $sections = TableManager::getSections();
        
        // Default to first section if not specified
        if (!$section || !TableManager::isValidSection($section)) {
            $section = $sections[0];
        }

        return view('reports.machine-report', compact('machine', 'machineNumber', 'sections', 'section'));
    }

    /**
     * Get report data for a specific machine-section-date (AJAX)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'machine_number' => 'required|integer|min:1',
            'section' => 'required|string|in:' . implode(',', TableManager::getSections()),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $machineNumber = $request->machine_number;
            $section = strtoupper($request->section);

            // CRITICAL: Generate table name based on machine number and section
            $tableName = TableManager::getTableName($machineNumber, $section);
            
            // Log which table is being queried
            Log::info("Fetching report from table: {$tableName} (Machine: M{$machineNumber}, Section: {$section})");

            // Ensure table exists (create if it doesn't)
            if (!TableManager::tableExists($tableName)) {
                Log::info("Table {$tableName} does not exist. Creating it now...");
                TableManager::createTable($machineNumber, $section);
            }

            // Get all records from the specific table
            $records = MachineSectionReport::getAll($machineNumber, $section);

            return response()->json([
                'success' => true,
                'data' => $records->map(function ($record) {
                    return [
                        'id' => $record->id,
                        'start_time' => $record->start_datetime ? $record->start_datetime->toISOString() : null,
                        'end_time' => $record->end_datetime ? $record->end_datetime->toISOString() : null,
                        'loading_duration' => (float) ($record->loading_hours ?? 0),
                        'machine_stop_time' => (float) ($record->machine_stop_hours ?? 0),
                        'created_at' => $record->created_at->format('Y-m-d H:i:s'),
                        'updated_at' => $record->updated_at->format('Y-m-d H:i:s'),
                    ];
                }),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch report data',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create or update a report record (AJAX)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function saveReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'machine_number' => 'required|integer|min:1',
            'section' => 'required|string|in:' . implode(',', TableManager::getSections()),
            'start_time' => 'nullable|date', // Accept full datetime
            'end_time' => 'nullable|date', // Accept full datetime
            'loading_duration' => 'nullable|numeric|min:0',
            'machine_stop_time' => 'nullable|numeric|min:0',
            'id' => 'nullable|integer', // For updates
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $machineNumber = $request->machine_number;
            $section = strtoupper($request->section);
            
            // CRITICAL: Generate table name based on machine number and section
            // Format: M{machine_number}_{SECTION}
            // Example: M1_AOUT, M25_BIN, M1000_DIN
            $tableName = TableManager::getTableName($machineNumber, $section);
            
            // Log which table is being used
            Log::info("Saving report to table: {$tableName} (Machine: M{$machineNumber}, Section: {$section})");
            
            // Ensure table exists (create if it doesn't)
            if (!TableManager::tableExists($tableName)) {
                Log::info("Table {$tableName} does not exist. Creating it now...");
                $created = TableManager::createTable($machineNumber, $section);
                
                if (!$created) {
                    throw new \Exception("Failed to create table {$tableName}. Please check database permissions and logs.");
                }
                
                // Verify table was actually created
                if (!TableManager::tableExists($tableName)) {
                    throw new \Exception("Table {$tableName} creation reported success but table does not exist. Please check database.");
                }
                
                Log::info("Table {$tableName} created and verified successfully");
            } else {
                Log::info("Table {$tableName} already exists, proceeding with save");
            }

            // Use the specific table for this machine-section combination
            $model = MachineSectionReport::forTableName($tableName);

            // Prepare data - ensure full datetime is stored
            // Handle datetime in various formats: ISO string, datetime-local format, etc.
            $startTime = null;
            $endTime = null;
            
            if ($request->start_time) {
                try {
                    // Try parsing as datetime (handles ISO, datetime-local, etc.)
                    $startTime = Carbon::parse($request->start_time);
                    Log::info("Parsed start_time: {$startTime->toDateTimeString()} from input: {$request->start_time}");
                } catch (\Exception $e) {
                    Log::error("Failed to parse start_time: {$request->start_time} - " . $e->getMessage());
                }
            }
            
            if ($request->end_time) {
                try {
                    // Try parsing as datetime (handles ISO, datetime-local, etc.)
                    $endTime = Carbon::parse($request->end_time);
                    Log::info("Parsed end_time: {$endTime->toDateTimeString()} from input: {$request->end_time}");
                } catch (\Exception $e) {
                    Log::error("Failed to parse end_time: {$request->end_time} - " . $e->getMessage());
                }
            }
            
            // Calculate expected_end_datetime if we have start_time and loading_duration
            $expectedEndDatetime = null;
            if ($startTime && $request->loading_duration) {
                $expectedEndDatetime = $startTime->copy()->addHours((float) $request->loading_duration);
            }
            
            $data = [
                'start_datetime' => $startTime,
                'end_datetime' => $endTime,
                'expected_end_datetime' => $expectedEndDatetime,
                'loading_hours' => $request->loading_duration ?? 0,
                'machine_stop_hours' => $request->machine_stop_time ?? 0,
            ];

            // Update existing record or create new one
            if ($request->id) {
                $record = $model->find($request->id);
                if ($record) {
                    $record->update($data);
                    $message = 'Report updated successfully';
                    Log::info("Updated record ID {$request->id} in table {$tableName}");
                } else {
                    return response()->json([
                    'success' => false,
                    'message' => 'Record not found'
                ], 404);
                }
            } else {
                $record = $model->create($data);
                $message = 'Report created successfully';
                Log::info("Created new record ID {$record->id} in table {$tableName}");
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'table_name' => $tableName, // Include table name in response for verification
                'data' => [
                    'id' => $record->id,
                    'start_time' => $record->start_datetime ? $record->start_datetime->toISOString() : null,
                    'end_time' => $record->end_datetime ? $record->end_datetime->toISOString() : null,
                    'loading_duration' => (float) ($record->loading_hours ?? 0),
                    'machine_stop_time' => (float) ($record->machine_stop_hours ?? 0),
                    'updated_at' => $record->updated_at->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error saving report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to save report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a report record (AJAX)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteReport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'machine_number' => 'required|integer|min:1',
            'section' => 'required|string|in:' . implode(',', TableManager::getSections()),
            'id' => 'required|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $machineNumber = $request->machine_number;
            $section = strtoupper($request->section);
            $id = $request->id;

            // CRITICAL: Generate table name for deletion
            $tableName = TableManager::getTableName($machineNumber, $section);
            Log::info("Deleting record ID {$id} from table: {$tableName} (Machine: M{$machineNumber}, Section: {$section})");

            $deleted = MachineSectionReport::deleteFromTable($machineNumber, $section, $id);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Report deleted successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Record not found or could not be deleted'
                ], 404);
            }
        } catch (\Exception $e) {
            Log::error('Error deleting report: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete report',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all sections for a machine (AJAX)
     * 
     * @return JsonResponse
     */
    public function getSections(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'sections' => TableManager::getSections()
        ]);
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
}

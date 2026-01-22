<?php

namespace App\Http\Controllers;

use App\Models\Challan;
use App\Models\Machine;
use App\Models\Section;
use App\Models\MachineSectionReport;
use App\Services\TableManager;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ChallanController extends Controller
{
    /**
     * Display the schedule page for a specific machine and section
     */
    public function schedule($machineName, $sectionName) {
        $machine = Machine::where('name', $machineName)->firstOrFail();
        
        // Get or create the section for this machine
        $section = Section::firstOrCreate(
            [
                'name' => $sectionName,
                'machine_id' => $machine->id,
            ],
            [
                'name' => $sectionName,
                'machine_id' => $machine->id,
            ]
        );

        // Extract machine number and section code for table name
        $machineNumber = $this->extractMachineNumber($machineName);
        $sectionCode = str_replace('-', '', strtoupper($sectionName));
        
        // Ensure table exists
        if ($machineNumber && TableManager::isValidSection($sectionCode)) {
            $tableName = TableManager::getTableName($machineNumber, $sectionCode);
            if (!TableManager::tableExists($tableName)) {
                TableManager::createTable($machineNumber, $sectionCode);
            }
        }

        return view('challans.schedule', compact('machine', 'section'));
    }

    /**
     * Get existing records from machine-section table (AJAX)
     * Returns ALL rows in exact UI order (1:1 mapping with database)
     */
    public function getChallans(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'machine_id' => 'required|exists:machines,id',
            'section_name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()->first()], 400);
        }

        try {
            // Get machine to extract machine number
            $machine = Machine::findOrFail($request->machine_id);
            $machineNumber = $this->extractMachineNumber($machine->name);
            
            if (!$machineNumber) {
                return response()->json(['error' => 'Invalid machine name format'], 400);
            }

            // Convert section name format: A-OUT -> AOUT, A-IN -> AIN, etc.
            $sectionCode = str_replace('-', '', strtoupper($request->section_name));
            
            // Validate section code
            if (!TableManager::isValidSection($sectionCode)) {
                return response()->json(['error' => "Invalid section: {$request->section_name}"], 400);
            }

            // Get table name and model
            $tableName = TableManager::getTableName($machineNumber, $sectionCode);
            
            if (!TableManager::tableExists($tableName)) {
                return response()->json([]); // Return empty array if table doesn't exist
            }

            $model = MachineSectionReport::forTableName($tableName);
            
            // Get ALL rows ordered by row_no (maintains exact UI order)
            // CRITICAL: Use row_no for ordering to preserve exact UI sequence
            // This ensures 1:1 mapping with UI table
            $records = $model->orderBy('row_no', 'asc')->get();

            // Format records to match expected format - ALL rows returned in exact order
            // CRITICAL: Return EXACT database values - no modification, no recalculation
            // Maintains 1:1 mapping with UI table
            $formattedRecords = $records->map(function ($record) {
                // CRITICAL: Parse database datetime values and ensure UTC
                // Database stores as DATETIME, we need to convert to ISO string (UTC)
                $startTime = $record->start_datetime 
                    ? Carbon::parse($record->start_datetime)->setTimezone('UTC') 
                    : null;
                $endTime = $record->end_datetime 
                    ? Carbon::parse($record->end_datetime)->setTimezone('UTC') 
                    : null;
                $expectedEndTime = $record->expected_end_datetime 
                    ? Carbon::parse($record->expected_end_datetime)->setTimezone('UTC') 
                    : null;
                
                // Log first few records for debugging
                if ($record->row_no <= 3) {
                    Log::info("Returning record row_no={$record->row_no}:", [
                        'DB start_datetime' => $record->start_datetime,
                        'ISO start_time' => $startTime ? $startTime->toISOString() : null,
                        'DB expected_end_datetime' => $record->expected_end_datetime,
                        'ISO expected_end_time' => $expectedEndTime ? $expectedEndTime->toISOString() : null,
                        'DB end_datetime' => $record->end_datetime,
                        'ISO end_time' => $endTime ? $endTime->toISOString() : null,
                    ]);
                }
                
                return [
                    'id' => $record->id,
                    'row_no' => $record->row_no,
                    'start_time' => $startTime ? $startTime->toISOString() : null,
                    'end_time' => $endTime ? $endTime->toISOString() : null,
                    'expected_end_time' => $expectedEndTime ? $expectedEndTime->toISOString() : null, // Include expected_end
                    'loading_duration' => $record->loading_hours !== null ? (float) $record->loading_hours : null, // Preserve null
                    'machine_stop_time' => (float) ($record->machine_stop_hours ?? 0),
                ];
            });
            
            return response()->json($formattedRecords);

        } catch (\Exception $e) {
            Log::error('Error fetching records: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch records'], 500);
        }
    }

    /**
     * Save schedule - stores ALL rows from UI table (1:1 mapping)
     * Every visible row in UI = One database row
     * Maintains exact order and values as shown in UI
     */
    public function generate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'machine_id' => 'required|exists:machines,id',
            'section_name' => 'required|string',
            'date' => 'required|date',
            'start_hour' => 'required|integer|min:0|max:23',
            'loading_time' => 'required|numeric|min:0.01',
            'number_of_rows' => 'required|integer|min:1|max:1000',
            'machine_stop_times' => 'nullable|array',
            'machine_stop_times.*' => 'numeric|min:0',
            'schedule_rows' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);
        }

        try {
            DB::beginTransaction();

            // Get machine to extract machine number
            $machine = Machine::findOrFail($request->machine_id);
            $machineNumber = $this->extractMachineNumber($machine->name);
            
            if (!$machineNumber) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid machine name format'
                ], 400);
            }

            // Convert section name format: A-OUT -> AOUT, A-IN -> AIN, etc.
            $sectionName = $request->section_name;
            $sectionCode = str_replace('-', '', strtoupper($sectionName));
            
            // Validate section code
            if (!TableManager::isValidSection($sectionCode)) {
                return response()->json([
                    'success' => false,
                    'message' => "Invalid section: {$sectionName}. Valid sections: " . implode(', ', TableManager::getSections())
                ], 400);
            }

            // Generate table name: M{machine_number}_{SECTION}
            $tableName = TableManager::getTableName($machineNumber, $sectionCode);
            
            Log::info("Saving schedule to table: {$tableName} (Machine: {$machine->name}, Section: {$sectionName})");

            // Ensure table exists - create if it doesn't exist
            if (!TableManager::tableExists($tableName)) {
                Log::info("Table {$tableName} does not exist. Creating it now...");
                $created = TableManager::createTable($machineNumber, $sectionCode);
                
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

            // Get model for this specific table
            $model = MachineSectionReport::forTableName($tableName);

            $date = Carbon::parse($request->date);
            $startHour = (int) $request->start_hour;
            $loadingTime = (float) $request->loading_time;
            $numberOfRows = (int) $request->number_of_rows;
            $machineStopTimes = $request->machine_stop_times ?? [];
            $scheduleRows = $request->schedule_rows ?? [];

            $savedRecords = [];

            // ============================================================================
            // CRITICAL: EXACT 1:1 MAPPING - NO RECALCULATION
            // ============================================================================
            // Frontend is the SINGLE SOURCE OF TRUTH
            // Backend stores EXACTLY what frontend sends - no modification, no recalculation
            // ============================================================================
            
            if (!empty($scheduleRows)) {
                Log::info("Received " . count($scheduleRows) . " schedule rows to save (UPDATE or INSERT based on row_no)");
                
                // Sort by row_index to maintain exact UI order
                usort($scheduleRows, function($a, $b) {
                    return ($a['row_index'] ?? 0) <=> ($b['row_index'] ?? 0);
                });

                // CRITICAL: Use updateOrInsert to prevent duplicates
                // Each row is uniquely identified by row_no within the table
                // If row_no exists → UPDATE
                // If row_no doesn't exist → INSERT
                $now = Carbon::now('UTC');
                $tableNameForInsert = $model->getTable();
                $updatedCount = 0;
                $insertedCount = 0;
                
                foreach ($scheduleRows as $rowData) {
                    $rowIndex = $rowData['row_index'] ?? null;
                    
                    // Validate required fields
                    if (empty($rowData['start_datetime'])) {
                        Log::warning("Skipping row at index {$rowIndex}: missing start_datetime", ['row' => $rowData]);
                        continue;
                    }
                    
                    // CRITICAL: Use EXACT values from frontend - NO RECALCULATION
                    // Parse ISO strings as UTC (frontend sends UTC)
                    $startTime = Carbon::parse($rowData['start_datetime'])->setTimezone('UTC');
                    
                    // Use EXACT end_datetime from frontend (if provided)
                    $endTime = !empty($rowData['end_datetime']) 
                        ? Carbon::parse($rowData['end_datetime'])->setTimezone('UTC')
                        : null;
                    
                    // Use EXACT expected_end from frontend (if provided)
                    $expectedEndTime = !empty($rowData['expected_end']) 
                        ? Carbon::parse($rowData['expected_end'])->setTimezone('UTC')
                        : null;
                    
                    // Use EXACT stop_hours from frontend
                    $machineStopTime = (float) ($rowData['stop_hours'] ?? $rowData['machine_stop_hours'] ?? 0);
                    
                    // Handle loading_hours: can be null, "-", empty string, or a number
                    $loadingHours = $rowData['loading_hours'] ?? null;
                    if ($loadingHours === null || $loadingHours === '' || $loadingHours === '-') {
                        $loadingHours = null; // NULL for intermediate rows
                    } else {
                        $loadingHours = (float) $loadingHours;
                    }

                    // Validation: Prevent negative times
                    if ($machineStopTime < 0) {
                        throw new \Exception("Machine stop time cannot be negative at row index {$rowIndex}");
                    }
                    
                    // CRITICAL: If frontend didn't provide end_datetime or expected_end_datetime,
                    // we MUST throw an error - we don't recalculate
                    if (!$endTime) {
                        throw new \Exception("Row at index {$rowIndex} is missing end_datetime. Frontend must provide all datetime values.");
                    }
                    
                    if (!$expectedEndTime) {
                        throw new \Exception("Row at index {$rowIndex} is missing expected_end. Frontend must provide all datetime values.");
                    }

                    // Determine row_no (1-based, matches UI row order)
                    $rowNo = $rowIndex !== null ? ($rowIndex + 1) : null;
                    
                    if ($rowNo === null) {
                        Log::warning("Skipping row: missing row_index", ['row' => $rowData]);
                        continue;
                    }
                    
                    // Prepare data for update/insert
                    $updateData = [
                        'start_datetime' => $startTime->format('Y-m-d H:i:s'),
                        'end_datetime' => $endTime->format('Y-m-d H:i:s'),
                        'expected_end_datetime' => $expectedEndTime->format('Y-m-d H:i:s'),
                        'loading_hours' => $loadingHours, // Can be NULL
                        'machine_stop_hours' => $machineStopTime,
                        'updated_at' => $now->format('Y-m-d H:i:s'),
                    ];
                    
                    // Check if row exists to determine if it's an update or insert
                    $existingRow = $model->where('row_no', $rowNo)->first();
                    
                    if ($existingRow) {
                        // UPDATE existing row
                        $model->where('row_no', $rowNo)->update($updateData);
                        $updatedCount++;
                        Log::debug("Updated row_no {$rowNo} in table {$tableName}");
                    } else {
                        // INSERT new row
                        $insertData = array_merge($updateData, [
                            'row_no' => $rowNo,
                            'created_at' => $now->format('Y-m-d H:i:s'),
                        ]);
                        DB::table($tableNameForInsert)->insert($insertData);
                        $insertedCount++;
                        Log::debug("Inserted row_no {$rowNo} in table {$tableName}");
                    }
                }
                
                Log::info("Processed rows in table {$tableName}: {$updatedCount} updated, {$insertedCount} inserted");
                
                // Fetch all records to return IDs (ordered by row_no)
                $savedRecords = $model->orderBy('row_no')->get();
                
                Log::info("Total records in table {$tableName}: " . count($savedRecords));
            } else {
                // Fallback: Create records from basic parameters
                Log::info("No schedule_rows provided, using fallback method with basic parameters");
                $currentStartTime = $date->copy()->setTime($startHour, 0, 0);
                
                for ($i = 0; $i < $numberOfRows; $i++) {
                    $machineStopTime = isset($machineStopTimes[$i]) ? (float) $machineStopTimes[$i] : 0;
                    
                    if ($machineStopTime < 0) {
                        throw new \Exception("Machine stop time cannot be negative");
                    }
                    
                    // Calculate end time: start + loading time + machine stop time
                    $totalDuration = $loadingTime + $machineStopTime;
                    $endTime = $currentStartTime->copy()->addHours($totalDuration);
                    $expectedEndDatetime = $currentStartTime->copy()->addHours($loadingTime);

                    // Save to machine-section table
                    $record = $model->create([
                        'start_datetime' => $currentStartTime,
                        'end_datetime' => $endTime,
                        'expected_end_datetime' => $expectedEndDatetime,
                        'loading_hours' => $loadingTime,
                        'machine_stop_hours' => $machineStopTime,
                    ]);

                    $savedRecords[] = $record;
                    Log::info("Created record ID {$record->id} in table {$tableName} (fallback method)");

                    // Next start time is the end time of current record
                    $currentStartTime = $endTime->copy();
                }
            }
            
            Log::info("Total records saved to {$tableName}: " . count($savedRecords));

            // Check if any records were actually saved
            if (count($savedRecords) === 0) {
                DB::rollBack();
                $reason = "No rows were saved. Please ensure schedule_rows data is provided and contains valid start_datetime values.";
                if (!empty($scheduleRows)) {
                    $totalRows = count($scheduleRows);
                    $reason = "Received {$totalRows} rows but none were saved. Please check that all rows have valid start_datetime values.";
                }
                Log::warning("No records were saved to {$tableName}. {$reason}");
                return response()->json([
                    'success' => false,
                    'message' => "No records were saved. {$reason}",
                    'table_name' => $tableName,
                    'records' => []
                ], 400);
            }

            DB::commit();
            
            // Verify table exists and has records after commit
            if (!TableManager::tableExists($tableName)) {
                Log::error("CRITICAL: Table {$tableName} does not exist after save operation!");
                return response()->json([
                    'success' => false,
                    'message' => "Table {$tableName} was not found after saving. Please check database.",
                    'table_name' => $tableName,
                    'records' => []
                ], 500);
            }
            
            // Verify records were actually saved
            $actualRecordCount = $model->count();
            if ($actualRecordCount < count($savedRecords)) {
                Log::warning("Record count mismatch: Expected " . count($savedRecords) . " but found {$actualRecordCount} in table {$tableName}");
            }
            
            Log::info("Verified: Table {$tableName} exists with {$actualRecordCount} total records");

            // Convert array to Collection for mapping - maintain order by row_no
            // Return EXACT database values (no modification)
            $recordsData = collect($savedRecords)
                ->sortBy('row_no')
                ->map(function ($record) {
                    // Use EXACT database values - parse as UTC and convert to ISO
                    $startTime = $record->start_datetime ? Carbon::parse($record->start_datetime)->setTimezone('UTC') : null;
                    $endTime = $record->end_datetime ? Carbon::parse($record->end_datetime)->setTimezone('UTC') : null;
                    $expectedEndTime = $record->expected_end_datetime ? Carbon::parse($record->expected_end_datetime)->setTimezone('UTC') : null;
                    
                    return [
                        'id' => $record->id,
                        'row_no' => $record->row_no,
                        'start_time' => $startTime ? $startTime->toISOString() : null,
                        'end_time' => $endTime ? $endTime->toISOString() : null,
                        'expected_end_time' => $expectedEndTime ? $expectedEndTime->toISOString() : null, // Include expected_end
                        'loading_duration' => $record->loading_hours !== null ? (float) $record->loading_hours : null,
                        'machine_stop_time' => (float) ($record->machine_stop_hours ?? 0),
                    ];
                })
                ->values()
                ->toArray();

            Log::info("Successfully committed " . count($savedRecords) . " records to {$tableName} (1:1 with UI table)");

            return response()->json([
                'success' => true,
                'message' => "Successfully saved " . count($savedRecords) . " row(s) to table {$tableName} (exact match with UI table)",
                'table_name' => $tableName,
                'saved_count' => count($savedRecords),
                'records' => $recordsData
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saving schedule: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error saving schedule: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update machine stop time with CASCADE effect
     * Updates the row and all subsequent rows in a single transaction
     * CRITICAL: Uses UPDATE queries only, no delete/reinsert
     */
    public function updateStopTimeWithCascade(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'machine_number' => 'required|integer|min:1',
            'section' => 'required|string|in:' . implode(',', TableManager::getSections()),
            'record_id' => 'required|integer',
            'machine_stop_time' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);
        }

        try {
            DB::beginTransaction();

            $machineNumber = $request->machine_number;
            $section = strtoupper($request->section);
            $recordId = $request->record_id;
            $newMachineStopTime = (float) $request->machine_stop_time;

            // Generate table name
            $tableName = TableManager::getTableName($machineNumber, $section);
            
            if (!TableManager::tableExists($tableName)) {
                return response()->json([
                    'success' => false,
                    'message' => "Table {$tableName} does not exist"
                ], 404);
            }

            $model = MachineSectionReport::forTableName($tableName);
            
            // Get the record to update
            $record = $model->find($recordId);
            if (!$record) {
                return response()->json([
                    'success' => false,
                    'message' => 'Record not found'
                ], 404);
            }

            // Get all MAIN records (with loading_hours > 0) ordered by start_datetime
            // CRITICAL: Only process MAIN rows - these are the actual database records
            $allRecords = $model->where('loading_hours', '>', 0)
                ->orderBy('start_datetime')
                ->get();

            // Find the index of the record being updated
            $updateIndex = -1;
            foreach ($allRecords as $index => $r) {
                if ($r->id == $recordId) {
                    $updateIndex = $index;
                    break;
                }
            }

            if ($updateIndex === -1) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Record not found in ordered list'
                ], 404);
            }

            // Update the target record
            $oldStartTime = $record->start_datetime->copy();
            $oldEndTime = $record->end_datetime->copy();
            $oldStopTime = (float) ($record->machine_stop_hours ?? 0);
            
            // Calculate new end_datetime: start_datetime + loading_hours + new_machine_stop_hours
            // CRITICAL: Keep start_datetime unchanged, only update end_datetime and machine_stop_hours
            $loadingHours = (float) ($record->loading_hours ?? 0);
            $newEndTime = $oldStartTime->copy()->addHours($loadingHours + $newMachineStopTime);
            $expectedEndDatetime = $oldStartTime->copy()->addHours($loadingHours);
            
            // Update the record (maintains same ID)
            $record->machine_stop_hours = $newMachineStopTime;
            $record->end_datetime = $newEndTime;
            $record->expected_end_datetime = $expectedEndDatetime;
            $record->save();

            Log::info("Updated record ID {$recordId} in table {$tableName}: machine_stop_time={$oldStopTime}->{$newMachineStopTime}, end_time={$oldEndTime}->{$newEndTime}");

            $updatedRecords = [$record];

            // CASCADE: Update all subsequent records in order
            // CRITICAL: Each subsequent record's start_datetime = previous record's end_datetime
            // This maintains continuous timeline without gaps
            for ($i = $updateIndex + 1; $i < $allRecords->count(); $i++) {
                $subsequentRecord = $allRecords[$i];
                
                // New start_datetime = previous record's end_datetime (ensures no gaps)
                $previousRecord = $updatedRecords[count($updatedRecords) - 1];
                $newStartTime = $previousRecord->end_datetime->copy();
                
                // New end_datetime = new_start_datetime + loading_hours + machine_stop_hours
                // CRITICAL: Use the subsequent record's existing machine_stop_hours (don't change it)
                $subsequentLoadingHours = (float) ($subsequentRecord->loading_hours ?? 0);
                $subsequentStopHours = (float) ($subsequentRecord->machine_stop_hours ?? 0);
                $newEndTime = $newStartTime->copy()->addHours($subsequentLoadingHours + $subsequentStopHours);
                
                // Calculate expected_end_datetime
                $expectedEndDatetime = $newStartTime->copy()->addHours($subsequentLoadingHours);
                
                // Update the record (maintains same ID)
                $subsequentRecord->start_datetime = $newStartTime;
                $subsequentRecord->end_datetime = $newEndTime;
                $subsequentRecord->expected_end_datetime = $expectedEndDatetime;
                $subsequentRecord->save();
                
                $updatedRecords[] = $subsequentRecord;
                
                Log::info("Cascaded update to record ID {$subsequentRecord->id} in table {$tableName}: start_datetime={$newStartTime}, end_datetime={$newEndTime}");
            }

            DB::commit();

            // Return all updated records
            $recordsData = collect($updatedRecords)->map(function ($record) {
                return [
                    'id' => $record->id,
                    'start_time' => $record->start_datetime ? $record->start_datetime->toISOString() : null,
                    'end_time' => $record->end_datetime ? $record->end_datetime->toISOString() : null,
                    'loading_duration' => (float) ($record->loading_hours ?? 0),
                    'machine_stop_time' => (float) ($record->machine_stop_hours ?? 0),
                ];
            })->toArray();

            return response()->json([
                'success' => true,
                'message' => 'Machine stop time updated with cascade effect',
                'updated_count' => count($updatedRecords),
                'records' => $recordsData
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating stop time with cascade: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error updating stop time: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update machine stop time for a record (legacy method - kept for backward compatibility)
     */
    public function updateStopTime(Request $request): JsonResponse
    {
        // Redirect to cascade update method
        return $this->updateStopTimeWithCascade($request);
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
     * Delete challans in a time range (AJAX)
     */
    public function deleteRange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'machine_id' => 'required|exists:machines,id',
            'section_id' => 'required|exists:sections,id',
            'from_date' => 'required|date',
            'from_time' => 'required|date_format:H:i',
            'to_date' => 'required|date',
            'to_time' => 'required|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);
        }

        try {
            DB::beginTransaction();

            $machineId = $request->machine_id;
            $sectionId = $request->section_id;
            $fromDateTime = Carbon::parse($request->from_date . ' ' . $request->from_time);
            $toDateTime = Carbon::parse($request->to_date . ' ' . $request->to_time);

            if ($fromDateTime >= $toDateTime) {
                return response()->json([
                    'success' => false,
                    'message' => 'From date/time must be before To date/time'
                ], 400);
            }

            // Find and soft delete (mark as deleted) challans in the range
            $deleted = Challan::where('machine_id', $machineId)
                ->where('section_id', $sectionId)
                ->where('status', 'active')
                ->where(function ($query) use ($fromDateTime, $toDateTime) {
                    $query->where(function ($q) use ($fromDateTime, $toDateTime) {
                        // Challans that overlap with the delete range
                        $q->where('start_time', '<', $toDateTime)
                          ->where('end_time', '>', $fromDateTime);
                    });
                })
                ->update(['status' => 'deleted']);

            // Recalculate remaining challans if needed
            // This ensures no gaps and proper sequencing
            $this->recalculateRemainingChallans($machineId, $sectionId);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => "Successfully deleted {$deleted} challan(s)",
                'deleted_count' => $deleted
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error deleting challans: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Recalculate remaining challans after deletion
     * Ensures continuous timeline without gaps
     */
    private function recalculateRemainingChallans($machineId, $sectionId)
    {
        $challans = Challan::where('machine_id', $machineId)
            ->where('section_id', $sectionId)
            ->where('status', 'active')
            ->orderBy('start_time')
            ->get();

        if ($challans->isEmpty()) {
            return;
        }

        // Recalculate end times and ensure sequential ordering
        // Each challan's end time should match the next challan's start time
        foreach ($challans as $index => $challan) {
            $machineStopTime = $challan->machine_stop_time ?? 0;
            $totalDuration = $challan->loading_duration + $machineStopTime;
            $correctEndTime = $challan->start_time->copy()->addHours($totalDuration);
            
            // Update end time if incorrect
            if ($challan->end_time != $correctEndTime) {
                $challan->end_time = $correctEndTime;
                $challan->save();
            }
            
            // If this isn't the last challan, ensure next challan starts at this end time
            if ($index < $challans->count() - 1) {
                $nextChallan = $challans[$index + 1];
                if ($nextChallan->start_time != $correctEndTime) {
                    $nextChallan->start_time = $correctEndTime;
                    $nextMachineStopTime = $nextChallan->machine_stop_time ?? 0;
                    $nextTotalDuration = $nextChallan->loading_duration + $nextMachineStopTime;
                    $nextChallan->end_time = $correctEndTime->copy()->addHours($nextTotalDuration);
                    $nextChallan->date = $correctEndTime->toDateString();
                    $nextChallan->save();
                }
            }
        }
    }

    /**
     * Update a challan (AJAX)
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'start_time' => 'required|date',
            'end_time' => 'required|date|after:start_time',
            'loading_duration' => 'required|numeric|min:0.01',
            'machine_stop_time' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);
        }

        try {
            $challan = Challan::findOrFail($id);
            
            $challan->start_time = Carbon::parse($request->start_time);
            $challan->end_time = Carbon::parse($request->end_time);
            $challan->loading_duration = $request->loading_duration;
            $challan->machine_stop_time = $request->machine_stop_time;
            $challan->save();

            return response()->json([
                'success' => true,
                'message' => 'Challan updated successfully',
                'challan' => $challan
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating challan: ' . $e->getMessage()
            ], 500);
        }
    }
}

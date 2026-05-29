<?php

namespace App\Services;

use App\Models\MachineSectionReport;
use App\Services\TableManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ScheduleCascadeService
 * 
 * Handles cascade updates for machine schedule rows.
 * CRITICAL: Uses UPDATE queries only, maintains row IDs and row_no.
 */
class ScheduleCascadeService
{
    /**
     * Update machine_stop_hours for a row and cascade to all subsequent rows
     * 
     * @param int $machineNumber
     * @param string $section
     * @param int $rowId Database row ID
     * @param float $newMachineStopHours
     * @return array ['success' => bool, 'updated_rows' => array, 'message' => string]
     */
    public static function updateStopHoursWithCascade(
        int $machineNumber,
        string $section,
        int $rowId,
        float $newMachineStopHours,
        ?string $remarks = null
    ): array {
        if ($newMachineStopHours < 0) {
            return [
                'success' => false,
                'message' => 'Machine stop hours cannot be negative'
            ];
        }

        $tableName = TableManager::getTableName($machineNumber, $section);
        
        // Ensure table exists - create if it doesn't exist
        if (!TableManager::tableExists($tableName)) {
            Log::info("Table {$tableName} does not exist. Creating it now...");
            $created = TableManager::createTable($machineNumber, $section);
            
            if (!$created) {
                return [
                    'success' => false,
                    'message' => "Failed to create table {$tableName}. Please check database permissions and logs."
                ];
            }
            
            // Verify table was actually created
            if (!TableManager::tableExists($tableName)) {
                return [
                    'success' => false,
                    'message' => "Table {$tableName} creation reported success but table does not exist. Please check database."
                ];
            }
            
            Log::info("Table {$tableName} created and verified successfully");
        }

        $model = MachineSectionReport::forTableName($tableName);
        
        return DB::transaction(function () use ($machineNumber, $section, $rowId, $newMachineStopHours, $remarks, $model, $tableName) {
            // Get all rows ordered by row_no (maintains UI order)
            $allRows = $model->orderBy('row_no')->get();
            
            // Find the row to update
            $targetRow = $allRows->firstWhere('id', $rowId);
            
            if (!$targetRow) {
                throw new \Exception('Row not found');
            }

            $updatedRows = [];
            $targetRowIndex = $allRows->search(function ($row) use ($rowId) {
                return $row->id === $rowId;
            });

            // STEP 1: Update target row
            $oldEndDatetime = $targetRow->end_datetime->copy();
            
            // Recalculate expected_end_datetime and end_datetime
            $startDatetime = $targetRow->start_datetime;
            $loadingHours = (float) ($targetRow->loading_hours ?? 0);
            Log::debug('Cascade target row', ['loading_hours_type' => gettype($loadingHours), 'loading_hours_value' => $loadingHours]);
            
            // Expected end = start + loading_hours (if loading_hours exists)
            $expectedEndDatetime = $loadingHours > 0 
                ? $startDatetime->copy()->addHours($loadingHours)
                : $startDatetime->copy();
            
            // End = expected_end + machine_stop_hours
            $newEndDatetime = $expectedEndDatetime->copy()->addHours($newMachineStopHours);
            
            // Update target row
            $targetRow->machine_stop_hours = $newMachineStopHours;
            $targetRow->stop_remarks = $remarks;
            $targetRow->expected_end_datetime = $expectedEndDatetime;
            $targetRow->end_datetime = $newEndDatetime;
            $targetRow->save();
            
            $updatedRows[] = $targetRow;
            
            Log::info("Updated row ID {$rowId} in table {$tableName}: machine_stop_hours={$newMachineStopHours}");

            // STEP 2: Cascade to all subsequent rows
            // Each subsequent row's start_datetime = previous row's end_datetime
            for ($i = $targetRowIndex + 1; $i < $allRows->count(); $i++) {
                $currentRow = $allRows[$i];
                $previousRow = $updatedRows[count($updatedRows) - 1];
                
                // New start = previous row's end
                $newStartDatetime = $previousRow->end_datetime->copy();
                
                // Recalculate expected_end and end
                $currentLoadingHours = (float) ($currentRow->loading_hours ?? 0);
                $currentStopHours = (float) ($currentRow->machine_stop_hours ?? 0);
                Log::debug('Cascade current row', ['loading_hours_type' => gettype($currentLoadingHours), 'value' => $currentLoadingHours, 'stop_type' => gettype($currentStopHours), 'stop_value' => $currentStopHours]);
                
                // Expected end = start + loading_hours (if loading_hours exists)
                $newExpectedEndDatetime = $currentLoadingHours > 0
                    ? $newStartDatetime->copy()->addHours($currentLoadingHours)
                    : $newStartDatetime->copy();
                
                // End = expected_end + machine_stop_hours
                $newEndDatetime = $newExpectedEndDatetime->copy()->addHours($currentStopHours);
                
                // Update the row (maintains same ID and row_no)
                $currentRow->start_datetime = $newStartDatetime;
                $currentRow->expected_end_datetime = $newExpectedEndDatetime;
                $currentRow->end_datetime = $newEndDatetime;
                $currentRow->save();
                
                $updatedRows[] = $currentRow;
                
                Log::info("Cascaded update to row ID {$currentRow->id} in table {$tableName}");
            }

            return [
                'success' => true,
                'updated_rows' => $updatedRows,
                'updated_count' => count($updatedRows),
                'message' => "Successfully updated {$rowId} and cascaded to " . (count($updatedRows) - 1) . " subsequent rows"
            ];
        });
    }

    /**
     * Save all schedule rows (1:1 mapping with UI)
     * 
     * @param int $machineNumber
     * @param string $section
     * @param array $rows Array of row data from UI
     * @return array ['success' => bool, 'saved_rows' => array, 'message' => string]
     */
    public static function saveAllRows(int $machineNumber, string $section, array $rows): array
    {
        $tableName = TableManager::getTableName($machineNumber, $section);
        
        // Ensure table exists - create if it doesn't exist
        if (!TableManager::tableExists($tableName)) {
            Log::info("Table {$tableName} does not exist. Creating it now...");
            $created = TableManager::createTable($machineNumber, $section);
            
            if (!$created) {
                return [
                    'success' => false,
                    'message' => "Failed to create table {$tableName}. Please check database permissions and logs."
                ];
            }
            
            // Verify table was actually created
            if (!TableManager::tableExists($tableName)) {
                return [
                    'success' => false,
                    'message' => "Table {$tableName} creation reported success but table does not exist. Please check database."
                ];
            }
            
            Log::info("Table {$tableName} created and verified successfully");
        }

        $model = MachineSectionReport::forTableName($tableName);
        
        return DB::transaction(function () use ($model, $tableName, $rows) {
            $savedRows = [];
            
            foreach ($rows as $index => $rowData) {
                $rowNo = $index + 1; // 1-based row number
                $rowId = $rowData['id'] ?? null;
                
                // Parse datetime values
                $startDatetime = Carbon::parse($rowData['start_datetime']);
                $loadingHours = isset($rowData['loading_hours']) && $rowData['loading_hours'] !== null && $rowData['loading_hours'] !== '' 
                    ? (float) $rowData['loading_hours'] 
                    : null;
                Log::debug('SaveAllRows loading_hours', ['raw' => $rowData['loading_hours'], 'casted' => $loadingHours, 'type' => gettype($loadingHours)]);
                $machineStopHours = (float) ($rowData['machine_stop_hours'] ?? 0);
                
                // Calculate expected_end_datetime
                $expectedEndDatetime = $loadingHours !== null && $loadingHours > 0
                    ? $startDatetime->copy()->addHours($loadingHours)
                    : $startDatetime->copy();
                
                // Calculate end_datetime
                $endDatetime = $expectedEndDatetime->copy()->addHours($machineStopHours);
                
                if ($rowId) {
                    // Update existing row (maintains same ID)
                    $row = $model->find($rowId);
                    if ($row) {
                        $row->row_no = $rowNo;
                        $row->start_datetime = $startDatetime;
                        $row->expected_end_datetime = $expectedEndDatetime;
                        $row->end_datetime = $endDatetime;
                        $row->loading_hours = $loadingHours;
                        $row->machine_stop_hours = $machineStopHours;
                        $row->stop_remarks = $rowData['stop_remarks'] ?? null;
                        $row->save();
                        $savedRows[] = $row;
                    }
                } else {
                    // Create new row
                    $row = $model->create([
                        'row_no' => $rowNo,
                        'start_datetime' => $startDatetime,
                        'expected_end_datetime' => $expectedEndDatetime,
                        'end_datetime' => $endDatetime,
                        'loading_hours' => $loadingHours,
                        'machine_stop_hours' => $machineStopHours,
                        'stop_remarks' => $rowData['stop_remarks'] ?? null,
                    ]);
                    $savedRows[] = $row;
                }
            }

            return [
                'success' => true,
                'saved_rows' => $savedRows,
                'message' => "Successfully saved " . count($savedRows) . " rows to table {$tableName}"
            ];
        });
    }

    /**
     * Validate continuous timeline (no gaps)
     * 
     * @param array $rows
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validateContinuousTimeline(array $rows): array
    {
        $errors = [];
        
        for ($i = 0; $i < count($rows) - 1; $i++) {
            $currentRow = $rows[$i];
            $nextRow = $rows[$i + 1];
            
            $currentEnd = Carbon::parse($currentRow['end_datetime']);
            $nextStart = Carbon::parse($nextRow['start_datetime']);
            
            if ($currentEnd->ne($nextStart)) {
                $errors[] = "Row " . ($i + 1) . " end_datetime ({$currentEnd}) does not match Row " . ($i + 2) . " start_datetime ({$nextStart})";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
}

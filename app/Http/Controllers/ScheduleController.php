<?php

namespace App\Http\Controllers;

use App\Services\ScheduleCascadeService;
use App\Services\TableManager;
use App\Models\MachineSectionReport;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

/**
 * ScheduleController
 * 
 * Handles 1:1 UI-to-Database schedule operations.
 * Every UI row = One database row.
 */
class ScheduleController extends Controller
{
    /**
     * Save all schedule rows (1:1 mapping with UI)
     * 
     * POST /api/schedule/save-all
     * Body: {
     *   machine_number: int,
     *   section: string,
     *   rows: [
     *     {
     *       id: int|null,
     *       row_no: int,
     *       start_datetime: string (ISO),
     *       loading_hours: float|null,
     *       machine_stop_hours: float,
     *       expected_end_datetime: string (ISO),
     *       end_datetime: string (ISO)
     *     },
     *     ...
     *   ]
     * }
     */
    public function saveAll(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'machine_number' => 'required|integer|min:1',
            'section' => 'required|string|in:' . implode(',', TableManager::getSections()),
            'rows' => 'required|array|min:1',
            'rows.*.start_datetime' => 'required|date',
            'rows.*.end_datetime' => 'required|date',
            'rows.*.expected_end_datetime' => 'required|date',
            'rows.*.loading_hours' => 'nullable|numeric|min:0',
            'rows.*.machine_stop_hours' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);
        }

        // Validate continuous timeline
        $timelineValidation = ScheduleCascadeService::validateContinuousTimeline($request->rows);
        if (!$timelineValidation['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'Timeline validation failed: ' . implode(', ', $timelineValidation['errors'])
            ], 400);
        }

        $result = ScheduleCascadeService::saveAllRows(
            $request->machine_number,
            $request->section,
            $request->rows
        );

        if ($result['success']) {
            $recordsData = collect($result['saved_rows'])->map(function ($row) {
                return [
                    'id' => $row->id,
                    'row_no' => $row->row_no,
                    'start_datetime' => $row->start_datetime->toISOString(),
                    'expected_end_datetime' => $row->expected_end_datetime->toISOString(),
                    'end_datetime' => $row->end_datetime->toISOString(),
                    'loading_hours' => $row->loading_hours !== null ? (float) $row->loading_hours : null,
                    'machine_stop_hours' => (float) $row->machine_stop_hours,
                ];
            })->toArray();

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'rows' => $recordsData
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message']
        ], 500);
    }

    /**
     * Get all schedule rows (1:1 with database)
     * 
     * GET /api/schedule/get-all?machine_number=X&section=Y
     */
    public function getAll(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'machine_number' => 'required|integer|min:1',
            'section' => 'required|string|in:' . implode(',', TableManager::getSections()),
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);
        }

        $tableName = TableManager::getTableName($request->machine_number, $request->section);
        
        // If table doesn't exist, return empty array (don't create table on GET request)
        if (!TableManager::tableExists($tableName)) {
            return response()->json([
                'success' => true,
                'rows' => []
            ]);
        }

        $model = MachineSectionReport::forTableName($tableName);
        $rows = $model->orderBy('row_no')->get();

        $rowsData = $rows->map(function ($row) {
            return [
                'id' => $row->id,
                'row_no' => $row->row_no,
                'start_datetime' => $row->start_datetime->toISOString(),
                'expected_end_datetime' => $row->expected_end_datetime->toISOString(),
                'end_datetime' => $row->end_datetime->toISOString(),
                'loading_hours' => $row->loading_hours !== null ? (float) $row->loading_hours : null,
                'machine_stop_hours' => (float) $row->machine_stop_hours,
            ];
        })->toArray();

        return response()->json([
            'success' => true,
            'rows' => $rowsData
        ]);
    }

    /**
     * Update machine_stop_hours with cascade effect
     * 
     * POST /api/schedule/update-stop-hours
     * Body: {
     *   machine_number: int,
     *   section: string,
     *   row_id: int,
     *   machine_stop_hours: float
     * }
     */
    public function updateStopHours(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'machine_number' => 'required|integer|min:1',
            'section' => 'required|string|in:' . implode(',', TableManager::getSections()),
            'row_id' => 'required|integer',
            'machine_stop_hours' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first()
            ], 400);
        }

        $result = ScheduleCascadeService::updateStopHoursWithCascade(
            $request->machine_number,
            $request->section,
            $request->row_id,
            (float) $request->machine_stop_hours
        );

        if ($result['success']) {
            $rowsData = collect($result['updated_rows'])->map(function ($row) {
                return [
                    'id' => $row->id,
                    'row_no' => $row->row_no,
                    'start_datetime' => $row->start_datetime->toISOString(),
                    'expected_end_datetime' => $row->expected_end_datetime->toISOString(),
                    'end_datetime' => $row->end_datetime->toISOString(),
                    'loading_hours' => $row->loading_hours !== null ? (float) $row->loading_hours : null,
                    'machine_stop_hours' => (float) $row->machine_stop_hours,
                ];
            })->toArray();

            return response()->json([
                'success' => true,
                'message' => $result['message'],
                'updated_count' => $result['updated_count'],
                'rows' => $rowsData
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => $result['message']
        ], 500);
    }
}

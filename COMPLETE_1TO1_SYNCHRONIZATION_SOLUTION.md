# Complete 1:1 UI-to-Database Synchronization Solution

## ✅ Implementation Complete

Perfect 1:1 mapping between UI schedule table and database with cascade updates.

## 1. MySQL Table Schema

### Exact Schema

```sql
CREATE TABLE M{machine_number}_{SECTION} (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    row_no INT UNSIGNED NULL,                    -- For maintaining row order (1:1 with UI)
    start_datetime DATETIME NULL,                -- UTC datetime
    expected_end_datetime DATETIME NULL,         -- start_datetime + loading_hours
    end_datetime DATETIME NULL,                  -- expected_end_datetime + machine_stop_hours
    loading_hours DECIMAL(10,2) NULL,             -- NULL for intermediate rows
    machine_stop_hours DECIMAL(10,2) DEFAULT 0,  -- Editable per row
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    
    INDEX idx_row_no (row_no),
    INDEX idx_start_datetime (start_datetime),
    INDEX idx_end_datetime (end_datetime),
    INDEX idx_expected_end_datetime (expected_end_datetime),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Key Points:
- **row_no**: Maintains UI row order (1, 2, 3, ...)
- **loading_hours**: NULL for intermediate rows, > 0 for loading rows
- **All datetimes in UTC**: Stored as DATETIME type
- **1:1 mapping**: Every UI row = One database row

## 2. Laravel Eloquent Model

### Updated Model (`app/Models/MachineSectionReport.php`)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Services\TableManager;

class MachineSectionReport extends Model
{
    protected $fillable = [
        'row_no',
        'start_datetime',
        'expected_end_datetime',
        'end_datetime',
        'loading_hours',
        'machine_stop_hours',
    ];

    protected $casts = [
        'row_no' => 'integer',
        'start_datetime' => 'datetime',
        'expected_end_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'loading_hours' => 'decimal:2',
        'machine_stop_hours' => 'decimal:2',
    ];

    public static function forTableName(string $tableName): static
    {
        $instance = new static();
        $instance->setTable($tableName);
        return $instance;
    }
}
```

## 3. Recalculation Algorithm (Step-by-Step)

### When machine_stop_hours is edited in row N:

```
STEP 1: Update Target Row (Row N)
    ├─ Calculate expected_end_datetime:
    │   └─ IF loading_hours > 0:
    │       expected_end_datetime = start_datetime + loading_hours
    │   ELSE:
    │       expected_end_datetime = start_datetime
    │
    ├─ Calculate end_datetime:
    │   └─ end_datetime = expected_end_datetime + new_machine_stop_hours
    │
    └─ UPDATE row N in database (same ID, same row_no)

STEP 2: Cascade to Subsequent Rows (Row N+1, N+2, ...)
    FOR each row from N+1 to last:
        ├─ Calculate new start_datetime:
        │   └─ start_datetime = previous_row.end_datetime
        │
        ├─ Calculate expected_end_datetime:
        │   └─ IF loading_hours > 0:
        │       expected_end_datetime = start_datetime + loading_hours
        │   ELSE:
        │       expected_end_datetime = start_datetime
        │
        ├─ Calculate end_datetime:
        │   └─ end_datetime = expected_end_datetime + machine_stop_hours
        │
        └─ UPDATE row in database (same ID, same row_no)

STEP 3: Return All Updated Rows
    └─ Return JSON with all updated rows for UI sync
```

### PHP Implementation:

```php
// 1. Update target row
$targetRow->machine_stop_hours = $newMachineStopHours;
$expectedEnd = $loadingHours > 0 
    ? $startDatetime->copy()->addHours($loadingHours)
    : $startDatetime->copy();
$targetRow->expected_end_datetime = $expectedEnd;
$targetRow->end_datetime = $expectedEnd->copy()->addHours($newMachineStopHours);
$targetRow->save();

// 2. Cascade to subsequent rows
for ($i = $targetRowIndex + 1; $i < count($allRows); $i++) {
    $currentRow = $allRows[$i];
    $previousRow = $updatedRows[count($updatedRows) - 1];
    
    // New start = previous end
    $newStart = $previousRow->end_datetime->copy();
    
    // Recalculate expected_end and end
    $currentLoadingHours = $currentRow->loading_hours ?? 0;
    $currentStopHours = $currentRow->machine_stop_hours ?? 0;
    
    $newExpectedEnd = $currentLoadingHours > 0
        ? $newStart->copy()->addHours($currentLoadingHours)
        : $newStart->copy();
    
    $newEnd = $newExpectedEnd->copy()->addHours($currentStopHours);
    
    // Update (maintains same ID and row_no)
    $currentRow->start_datetime = $newStart;
    $currentRow->expected_end_datetime = $newExpectedEnd;
    $currentRow->end_datetime = $newEnd;
    $currentRow->save();
}
```

## 4. Laravel Service Function

### `ScheduleCascadeService::updateStopHoursWithCascade()`

**Location**: `app/Services/ScheduleCascadeService.php`

**Key Features**:
- ✅ Uses UPDATE queries only (no delete/reinsert)
- ✅ Maintains row IDs and row_no
- ✅ Transaction-based (all or nothing)
- ✅ Validates continuous timeline
- ✅ Returns all updated rows

**Usage**:
```php
$result = ScheduleCascadeService::updateStopHoursWithCascade(
    machineNumber: 3,
    section: 'AOUT',
    rowId: 15,
    newMachineStopHours: 5.5
);

if ($result['success']) {
    $updatedRows = $result['updated_rows']; // All rows that were updated
}
```

## 5. JavaScript Logic for Inline Editing

### Frontend Implementation

```javascript
/**
 * Update machine_stop_hours for a row with cascade effect
 */
async function updateMachineStopHours(rowIndex, newStopHours) {
    const row = scheduleRows[rowIndex];
    const rowId = row.id; // Database row ID
    
    // Show saving indicator
    showSavingIndicator(rowIndex);
    
    // Update local data immediately (for UI responsiveness)
    row.machine_stop_hours = parseFloat(newStopHours) || 0;
    recalculateFromRow(rowIndex);
    renderTable();
    
    // Update database with cascade
    try {
        const response = await fetch('/api/schedule/update-stop-hours', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                machine_number: machineNumber,
                section: sectionCode,
                row_id: rowId,
                machine_stop_hours: row.machine_stop_hours
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Sync UI with database response (exact values)
            updateRowsFromDatabase(data.rows);
            showSuccessIndicator(rowIndex);
        } else {
            showErrorIndicator(rowIndex);
            console.error('Update failed:', data.message);
        }
    } catch (error) {
        showErrorIndicator(rowIndex);
        console.error('Error:', error);
    }
}

/**
 * Recalculate schedule from a specific row
 */
function recalculateFromRow(fromRowIndex) {
    // Start from first row
    let currentDatetime = new Date(scheduleRows[0].start_datetime);
    
    for (let i = 0; i < scheduleRows.length; i++) {
        const row = scheduleRows[i];
        
        // Set start_datetime
        row.start_datetime = currentDatetime.toISOString();
        
        // Calculate expected_end_datetime
        const loadingHours = row.loading_hours || 0;
        if (loadingHours > 0) {
            row.expected_end_datetime = addHours(currentDatetime, loadingHours).toISOString();
        } else {
            row.expected_end_datetime = currentDatetime.toISOString();
        }
        
        // Calculate end_datetime
        const stopHours = row.machine_stop_hours || 0;
        row.end_datetime = addHours(
            new Date(row.expected_end_datetime), 
            stopHours
        ).toISOString();
        
        // Next row starts where this one ends
        currentDatetime = new Date(row.end_datetime);
    }
}

/**
 * Sync UI rows with database response
 */
function updateRowsFromDatabase(dbRows) {
    // Create map of row_id -> database row
    const dbRowMap = new Map();
    dbRows.forEach(dbRow => {
        dbRowMap.set(dbRow.id, dbRow);
    });
    
    // Update scheduleRows with EXACT database values
    scheduleRows.forEach((row, index) => {
        if (row.id && dbRowMap.has(row.id)) {
            const dbRow = dbRowMap.get(row.id);
            
            // Use EXACT database values
            row.start_datetime = dbRow.start_datetime;
            row.expected_end_datetime = dbRow.expected_end_datetime;
            row.end_datetime = dbRow.end_datetime;
            row.machine_stop_hours = dbRow.machine_stop_hours;
        }
    });
    
    // Re-render table
    renderTable();
}
```

## 6. Example: Before & After Data

### Before Update:

| row_no | id | start_datetime | loading_hours | machine_stop_hours | expected_end_datetime | end_datetime |
|--------|----|----------------|---------------|--------------------|-----------------------|--------------|
| 1 | 10 | 2026-01-01 01:00:00 | 12.00 | 0.00 | 2026-01-01 13:00:00 | 2026-01-01 13:00:00 |
| 2 | 11 | 2026-01-01 13:00:00 | NULL | 0.00 | 2026-01-01 13:00:00 | 2026-01-01 13:00:00 |
| 3 | 12 | 2026-01-01 13:00:00 | NULL | 0.00 | 2026-01-01 13:00:00 | 2026-01-01 13:00:00 |
| 4 | 13 | 2026-01-01 13:00:00 | 12.00 | 0.00 | 2026-01-02 01:00:00 | 2026-01-02 01:00:00 |
| 5 | 14 | 2026-01-02 01:00:00 | NULL | 0.00 | 2026-01-02 01:00:00 | 2026-01-02 01:00:00 |

### User Action:
- Edits row_no 2: machine_stop_hours = 0 → 5

### After Cascade Update:

| row_no | id | start_datetime | loading_hours | machine_stop_hours | expected_end_datetime | end_datetime |
|--------|----|----------------|---------------|--------------------|-----------------------|--------------|
| 1 | 10 | 2026-01-01 01:00:00 | 12.00 | 0.00 | 2026-01-01 13:00:00 | 2026-01-01 13:00:00 |
| 2 | 11 | 2026-01-01 13:00:00 | NULL | **5.00** | 2026-01-01 13:00:00 | **2026-01-01 18:00:00** |
| 3 | 12 | **2026-01-01 18:00:00** | NULL | 0.00 | **2026-01-01 18:00:00** | **2026-01-01 18:00:00** |
| 4 | 13 | **2026-01-01 18:00:00** | 12.00 | 0.00 | **2026-01-02 06:00:00** | **2026-01-02 06:00:00** |
| 5 | 14 | **2026-01-02 06:00:00** | NULL | 0.00 | **2026-01-02 06:00:00** | **2026-01-02 06:00:00** |

**Key Changes**:
- ✅ Row 2: machine_stop_hours updated, end_datetime shifted by 5 hours
- ✅ Row 3: start_datetime = Row 2's new end_datetime (cascaded)
- ✅ Row 4: start_datetime = Row 3's end_datetime (cascaded)
- ✅ Row 5: start_datetime = Row 4's end_datetime (cascaded)
- ✅ All row IDs remain unchanged (10, 11, 12, 13, 14)
- ✅ All row_no remain unchanged (1, 2, 3, 4, 5)
- ✅ Timeline is continuous (no gaps)

## 7. API Endpoints

### Save All Rows
```
POST /api/schedule/save-all
Body: {
    machine_number: 3,
    section: "AOUT",
    rows: [
        {
            id: null,  // null for new rows
            row_no: 1,
            start_datetime: "2026-01-01T01:00:00Z",
            loading_hours: 12.00,
            machine_stop_hours: 0.00,
            expected_end_datetime: "2026-01-01T13:00:00Z",
            end_datetime: "2026-01-01T13:00:00Z"
        },
        ...
    ]
}
```

### Get All Rows
```
GET /api/schedule/get-all?machine_number=3&section=AOUT
Response: {
    success: true,
    rows: [
        {
            id: 10,
            row_no: 1,
            start_datetime: "2026-01-01T01:00:00Z",
            ...
        },
        ...
    ]
}
```

### Update Stop Hours (with Cascade)
```
POST /api/schedule/update-stop-hours
Body: {
    machine_number: 3,
    section: "AOUT",
    row_id: 11,
    machine_stop_hours: 5.00
}
Response: {
    success: true,
    updated_count: 4,
    rows: [
        { id: 11, ... },  // Updated row
        { id: 12, ... },  // Cascaded
        { id: 13, ... },  // Cascaded
        { id: 14, ... }   // Cascaded
    ]
}
```

## 8. Validation Rules

### Backend Validation

```php
// Prevent negative stop hours
if ($machineStopHours < 0) {
    throw new \Exception("Machine stop hours cannot be negative");
}

// Validate continuous timeline
$validation = ScheduleCascadeService::validateContinuousTimeline($rows);
if (!$validation['valid']) {
    throw new \Exception(implode(', ', $validation['errors']));
}
```

### Frontend Validation

```javascript
// Prevent negative values
if (stopHours < 0) stopHours = 0;

// Ensure row exists
if (!row.id) {
    console.log('Row not yet saved. Save schedule first.');
    return;
}
```

## 9. Error Handling

### Transaction Safety

```php
try {
    DB::beginTransaction();
    // ... update operations ...
    DB::commit();
} catch (\Exception $e) {
    DB::rollBack();
    return ['success' => false, 'message' => $e->getMessage()];
}
```

### Frontend Error Handling

```javascript
try {
    const result = await updateMachineStopHours(rowIndex, stopHours);
    if (result.success) {
        // Success
    } else {
        // Show error
        alert('Update failed: ' + result.message);
    }
} catch (error) {
    // Network error
    alert('Network error: ' + error.message);
}
```

## Summary

✅ **1:1 Mapping**: Every UI row = One database row
✅ **All Columns Stored**: row_no, start_datetime, loading_hours, machine_stop_hours, expected_end_datetime, end_datetime
✅ **Continuous Timeline**: Row N+1 start = Row N end (no gaps)
✅ **Cascade Updates**: Changes propagate to all subsequent rows
✅ **ID Preservation**: Row IDs and row_no never change
✅ **UPDATE Only**: No delete/reinsert - maintains data integrity
✅ **Transaction Safety**: All updates in single transaction

**The system now maintains perfect 1:1 synchronization between UI and database!**

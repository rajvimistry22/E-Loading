<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\TableManager;
use Carbon\Carbon;

/**
 * MachineSectionReport Model
 * 
 * Dynamic model that works with machine-section specific tables.
 * Table naming: M{machine_number}_{SECTION}
 */
class MachineSectionReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'row_no',
        'start_datetime',
        'expected_end_datetime',
        'end_datetime',
        'loading_hours',
        'machine_stop_hours',
        'stop_start_datetime',
        'stop_end_datetime',
        'stop_remarks',
        'is_cycle_complete',
    ];

protected $casts = [
        'row_no' => 'integer',
        'start_datetime' => 'datetime',
        'expected_end_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'loading_hours' => 'float',
        'machine_stop_hours' => 'float',
        'is_cycle_complete' => 'boolean',
    ];

    /**
     * Force numeric casting for loading_hours even if DB column is VARCHAR
     */
    protected function casts(): array
    {
        return parent::casts();
    }

    /**
     * Mutator to ensure loading_hours is always numeric
     */
    public function setLoadingHoursAttribute($value): void
    {
        $this->attributes['loading_hours'] = $value === null || $value === '' ? null : (float) $value;
    }

    /**
     * Accessor to ensure loading_hours is numeric
     */
    public function getLoadingHoursAttribute($value)
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * Mutator to ensure machine_stop_hours is always numeric
     */
    public function setMachineStopHoursAttribute($value): void
    {
        $this->attributes['machine_stop_hours'] = (float) $value;
    }

    /**
     * Accessor to ensure machine_stop_hours is numeric
     */
    public function getMachineStopHoursAttribute($value)
    {
        return (float) $value;
    }

    /**
     * Set the table name dynamically based on machine number and section
     * 
     * @param int $machineNumber
     * @param string $section
     * @return static
     */
    public static function forTable(int $machineNumber, string $section): static
    {
        $tableName = TableManager::getTableName($machineNumber, $section);
        $instance = new static();
        $instance->setTable($tableName);
        return $instance;
    }

    /**
     * Set table name directly
     * 
     * @param string $tableName
     * @return static
     */
    public static function forTableName(string $tableName): static
    {
        $instance = new static();
        $instance->setTable($tableName);
        return $instance;
    }


    /**
     * Get all records for a machine-section
     * 
     * @param int $machineNumber
     * @param string $section
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getAll(int $machineNumber, string $section)
    {
        $tableName = TableManager::getTableName($machineNumber, $section);
        
        if (!TableManager::tableExists($tableName)) {
            return collect([]);
        }

        $model = static::forTableName($tableName);
        
        return $model->orderBy('start_datetime')->get();
    }

    /**
     * Delete record by ID from a specific table
     * 
     * @param int $machineNumber
     * @param string $section
     * @param int $id
     * @return bool
     */
    public static function deleteFromTable(int $machineNumber, string $section, int $id): bool
    {
        $tableName = TableManager::getTableName($machineNumber, $section);
        
        if (!TableManager::tableExists($tableName)) {
            return false;
        }

        $model = static::forTableName($tableName);
        $record = $model->find($id);
        
        if ($record) {
            return $record->delete();
        }

        return false;
    }
}

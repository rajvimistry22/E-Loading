<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Challan extends Model
{
    use HasFactory;

    protected $fillable = [
        'machine_id',
        'section_name',
        'date',
        'start_time',
        'end_time',
        'loading_duration',
        'machine_stop_time',
        'status',
    ];

    protected $casts = [
        'date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'loading_duration' => 'decimal:2',
        'machine_stop_time' => 'decimal:2',
    ];

    /**
     * Get the machine that owns this challan
     */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * Get the section that owns this challan
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /**
     * Scope to get only active challans
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Check if this challan overlaps with another challan for the same machine and section
     */
    public static function hasOverlap(int $machineId, string $sectionName, Carbon $startTime, Carbon $endTime, ?int $excludeId = null): bool
    {
        $query = static::where('machine_id', $machineId)
            ->where('section_name', $sectionName)
            ->where('status', 'active')
            ->where(function ($q) use ($startTime, $endTime) {
                $q->where(function ($q2) use ($startTime, $endTime) {
                    // Overlap condition: start_time < new_end_time AND end_time > new_start_time
                    $q2->where('start_time', '<', $endTime)
                       ->where('end_time', '>', $startTime);
                });
            });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Machine extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get all challans for this machine
     */
    public function challans(): HasMany
    {
        return $this->hasMany(Challan::class);
    }

    /**
     * Get active challans only
     */
    public function activeChallans(): HasMany
    {
        return $this->challans()->where('status', 'active');
    }
}

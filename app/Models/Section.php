<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Section extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'machine_id'];

    /**
     * Get all challans for this section
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

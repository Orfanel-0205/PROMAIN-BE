<?php
// app/Models/RhuFacility.php
//
// One Rural Health Unit. Named RhuFacility rather than Rhu so it cannot be
// confused with App\Support\Rhu, the helper that answers "which facility does
// this user belong to" and is used all over the code.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RhuFacility extends Model
{
    protected $table = 'rhus';

    protected $fillable = [
        'code',
        'name',
        'short_name',
        'address',
        'contact_number',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** The barangays this facility serves (barangays.rhu_id). */
    public function barangays(): HasMany
    {
        return $this->hasMany(Barangay::class, 'rhu_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

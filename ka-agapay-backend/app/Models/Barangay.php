<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Barangay extends Model
{
    protected $primaryKey = 'barangay_id';

    protected $fillable = ['name'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'barangay_id', 'barangay_id');
    }

    public function residentProfiles(): HasMany
    {
        return $this->hasMany(ResidentProfile::class, 'barangay_id', 'barangay_id');
    }
}
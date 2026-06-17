<?php
// app/Models/ResidentProfile.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResidentProfile extends Model
{
    protected $table = 'resident_profiles';

    protected $fillable = [
        'user_id',
        'barangay_id',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'birthdate',
        'date_of_birth',
        'sex',
        'gender',
        'civil_status',
        'mobile_number',
        'contact_number',
        'address',
        'street',
        'purok',
        'household_number',
        'philhealth_number',
        'philhealth_pin',
        'emergency_contact_name',
        'emergency_contact_number',
        'medical_history',
        'allergies',
        'maintenance_medications',
        'is_senior',
        'is_pwd',
        'is_pregnant',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'date_of_birth' => 'date',
        'medical_history' => 'array',
        'allergies' => 'array',
        'maintenance_medications' => 'array',
        'is_senior' => 'boolean',
        'is_pwd' => 'boolean',
        'is_pregnant' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id', 'barangay_id');
    }

    public function telemedicineRequests(): HasMany
    {
        return $this->hasMany(TelemedicineRequest::class, 'resident_profile_id');
    }

    public function telemedicineReferrals(): HasMany
    {
        return $this->hasMany(TelemedicineReferral::class, 'resident_profile_id');
    }
}
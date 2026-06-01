<?php
// app/Models/User.php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'role_id',
        'first_name',
        'last_name',
        'email',
        'mobile_number',
        'password',
        'barangay',
        'birthday',
        'sex',
        'account_status',
        'id_verified',
        'biometric_enabled',
        'biometric_token_hash',
        'profile_picture',
        'avatar',
        'failed_login_count',
        'locked_until',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'biometric_token_hash',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'birthday' => 'date',
        'id_verified' => 'boolean',
        'biometric_enabled' => 'boolean',
        'locked_until' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function role()
    {
        return $this->belongsTo(UserRole::class, 'role_id', 'role_id');
    }
}
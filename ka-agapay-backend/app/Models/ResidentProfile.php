<?php
//app/models/ResidentProfile.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResidentProfile extends Model
{
    protected $fillable = [
        'user_id',
        'barangay_id',
        'birth_date',
        'sex',
        'address',
        'philhealth_no',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'philhealth_no' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id', 'barangay_id');
    }
}
<?php
// app/Models/PrescriptionDispensingLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrescriptionDispensingLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'prescription_id',
        'dispensed_by',
        'dispensed_items',
        'is_partial_dispense',
        'notes',
        'dispensed_at',
    ];

    protected $casts = [
        'dispensed_items'    => 'array',
        'is_partial_dispense'=> 'boolean',
        'dispensed_at'       => 'datetime',
    ];

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function dispensedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispensed_by', 'user_id');
    }
}

<?php
// app/Models/InventoryPersonnel.php
//
// The staff member formally assigned to manage inventory at one RHU.
// Accountability/display only — see the migration for why this is not an
// authorization gate.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryPersonnel extends Model
{
    protected $table = 'inventory_personnel';

    protected $fillable = [
        'rhu_id',
        'user_id',
        'assigned_by',
        'assigned_at',
    ];

    protected $casts = [
        'rhu_id' => 'integer',
        'user_id' => 'integer',
        'assigned_by' => 'integer',
        'assigned_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by', 'user_id');
    }

    /** Designated user id for an RHU, or null when nobody is assigned. */
    public static function assignedUserIdFor(?int $rhuId): ?int
    {
        if (!$rhuId) {
            return null;
        }

        return static::query()->where('rhu_id', $rhuId)->value('user_id');
    }
}

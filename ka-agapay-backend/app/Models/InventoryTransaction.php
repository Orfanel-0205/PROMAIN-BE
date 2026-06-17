<?php
// app/Models/InventoryTransaction.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryTransaction extends Model
{
    protected $table = 'inventory_transactions';

    public $timestamps = true;

    protected $fillable = [
        'inventory_item_id',
        'performed_by',
        'transaction_type',
        'quantity_before',
        'quantity_changed',
        'quantity_after',
        'reference_number',
        'prescription_id',
        'reason',
        'notes',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'inventory_item_id' => 'integer',
        'performed_by' => 'integer',
        'quantity_before' => 'integer',
        'quantity_changed' => 'integer',
        'quantity_after' => 'integer',
        'prescription_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function item(): BelongsTo
    {
        return $this->inventoryItem();
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by', 'user_id');
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'prescription_id');
    }
}
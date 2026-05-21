<?php
// app/Models/InventoryItem.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    use SoftDeletes;

    public const CATEGORIES = ['medicine', 'vaccine', 'supply', 'equipment'];
    public const TRANSACTION_TYPES = [
        'stock_in', 'stock_out', 'adjustment', 'expiry_removal', 'transfer'
    ];

    protected $fillable = [
        'rhu_id', 'item_code', 'name', 'generic_name', 'category',
        'unit_of_measure', 'dosage_form', 'current_stock',
        'minimum_stock_level', 'maximum_stock_level', 'reorder_point',
        'expiration_date', 'last_restocked_at',
        'is_controlled_substance', 'requires_prescription',
        'is_active', 'notes',
    ];

    protected $casts = [
        'expiration_date'          => 'date',
        'last_restocked_at'        => 'date',
        'is_controlled_substance'  => 'boolean',
        'requires_prescription'    => 'boolean',
        'is_active'                => 'boolean',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeLowStock($query)
    {
        return $query->whereColumn('current_stock', '<=', 'minimum_stock_level');
    }

    public function scopeExpiringSoon($query, int $days = 30)
    {
        return $query->where('expiration_date', '<=', now()->addDays($days))
                     ->where('expiration_date', '>=', today());
    }

    public function scopeForRhu($query, int $rhuId)
    {
        return $query->where('rhu_id', $rhuId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isLowStock(): bool
    {
        return $this->current_stock <= $this->minimum_stock_level;
    }

    public function isOutOfStock(): bool
    {
        return $this->current_stock <= 0;
    }

    public function isExpired(): bool
    {
        return $this->expiration_date && $this->expiration_date->isPast();
    }

    public function getAuditLabel(): string
    {
        return "{$this->name} ({$this->item_code})";
    }
}
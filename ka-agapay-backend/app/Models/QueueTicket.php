<?php
// app/Models/QueueTicket.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QueueTicket extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'ticket_number',
        'resident_profile_id',
        'appointment_id',
        'rhu_id',
        'issued_by',
        'served_by',
        'service_type',
        'queue_type',
        'source',
        'priority_score',
        'priority_category',
        'is_senior',
        'is_pregnant',
        'is_pwd',
        'is_pediatric',
        'is_emergency',
        'is_bhw_endorsed',
        'status',
        'queue_position',
        'call_attempt',
        'issued_at',
        'called_at',
        'service_started_at',
        'service_ended_at',
        'cancelled_at',
        'wait_time_minutes',
        'service_time_minutes',
        'notes',
        'cancellation_reason',
    ];

    protected $casts = [
        'is_senior'          => 'boolean',
        'is_pregnant'        => 'boolean',
        'is_pwd'             => 'boolean',
        'is_pediatric'       => 'boolean',
        'is_emergency'       => 'boolean',
        'is_bhw_endorsed'    => 'boolean',
        'issued_at'          => 'datetime',
        'called_at'          => 'datetime',
        'service_started_at' => 'datetime',
        'service_ended_at'   => 'datetime',
        'cancelled_at'       => 'datetime',
    ];

    public function residentProfile(): BelongsTo
    {
        return $this->belongsTo(ResidentProfile::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function rhu(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'rhu_id', 'barangay_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by', 'user_id');
    }

    public function servedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'served_by', 'user_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(QueueLog::class, 'queue_ticket_id');
    }

    public function scopeWaiting($query)
    {
        return $query->where('status', 'waiting');
    }

    public function scopeForToday($query)
    {
        return $query->whereDate('issued_at', today());
    }

    public function scopeForRhu($query, int $rhuId)
    {
        return $query->where('rhu_id', $rhuId);
    }

    public function scopeByServiceType($query, string $serviceType)
    {
        return $query->where('service_type', $serviceType);
    }

    public function scopePrioritized($query)
    {
        return $query->orderByDesc('priority_score')->orderBy('issued_at');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, ['completed', 'cancelled', 'no_show']);
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $allowedTransitions = [
            'waiting'    => ['called', 'cancelled'],
            'called'     => ['in_service', 'skipped', 'no_show', 'cancelled'],
            'in_service' => ['completed', 'cancelled'],
            'skipped'    => ['waiting', 'cancelled'],
            'completed'  => [],
            'cancelled'  => [],
            'no_show'    => [],
        ];

        return in_array($newStatus, $allowedTransitions[$this->status] ?? []);
    }
}

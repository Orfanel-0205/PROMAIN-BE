<?php
// app/Models/AuditLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    protected $table = 'audit_logs';

    protected $fillable = [
        'user_id',
        'user_role',
        'action',
        'module',
        'severity',
        'subject_type',
        'subject_id',
        'subject_label',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        'device_type',
        'http_method',
        'route_name',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function getActorNameAttribute(): ?string
    {
        if ($this->relationLoaded('user') && $this->user) {
            $name = trim((string) $this->user->first_name . ' ' . (string) $this->user->last_name);

            if ($name !== '') {
                return $name;
            }

            return $this->user->email ?? $this->user->mobile_number ?? null;
        }

        return null;
    }

    public function getRecordIdAttribute(): ?int
    {
        if (!empty($this->subject_id)) {
            return (int) $this->subject_id;
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];

        foreach (['record_id', 'restore_id', 'subject_id', 'user_id', 'id'] as $key) {
            if (!empty($metadata[$key])) {
                return (int) $metadata[$key];
            }
        }

        return null;
    }

    public function getRecordNameAttribute(): ?string
    {
        if (!empty($this->subject_label)) {
            return (string) $this->subject_label;
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];

        foreach (['record_name', 'subject_label', 'name', 'title'] as $key) {
            if (!empty($metadata[$key])) {
                return (string) $metadata[$key];
            }
        }

        return null;
    }
}
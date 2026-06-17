<?php

namespace App\Models;

use RuntimeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    protected $table = 'audit_logs';

    public $timestamps = true;

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
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ActivityLog $log) {
            $log->module = $log->module ?: 'system';
            $log->severity = $log->severity ?: 'info';
            $log->metadata = $log->metadata ?? [];
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException('Activity logs are immutable and cannot be updated.');
        }

        return parent::save($options);
    }
}
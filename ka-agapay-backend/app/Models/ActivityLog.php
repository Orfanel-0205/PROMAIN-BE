<?php

namespace App\Models;

use RuntimeException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'audit_logs';

    public $timestamps = false;

    const CREATED_AT = 'created_at';

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
        'http_method',
        'route_name',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new RuntimeException(
                'Activity logs are immutable and cannot be updated.'
            );
        }

        return parent::save($options);
    }
}
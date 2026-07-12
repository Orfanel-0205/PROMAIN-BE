<?php
// app/Models/Event.php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Event extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',

        'event_type',
        'category',

        'event_date',
        'starts_at',
        'ends_at',

        'location',
        'latitude',
        'longitude',

        'barangay_target',
        'target_audience',

        'tags',
        'services',

        'max_slots',
        'slots_available',

        'banner_image',
        'image_url',

        'sms_summary',

        'priority',
        'visibility',

        'is_published',
        'published_at',

        'sms_sent_at',
        'reminder_sms_sent_at',

        'created_by',

        // Delete / archive tracking
        'deleted_by',
        'delete_reason',
        'archived_at',
        'archived_by',
        'archive_reason',
    ];

    protected $casts = [
        'event_date' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published_at' => 'datetime',
        'sms_sent_at' => 'datetime',
        'reminder_sms_sent_at' => 'datetime',

        'deleted_at' => 'datetime',
        'archived_at' => 'datetime',

        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',

        'tags' => 'array',
        'services' => 'array',

        'max_slots' => 'integer',
        'slots_available' => 'integer',

        'is_published' => 'boolean',
    ];

    protected $appends = [
        'banner_url',
    ];

    // =========================================================================
    // Relationships
    // =========================================================================

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by', 'user_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by', 'user_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by', 'user_id');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'event_id', 'id');
    }

    public function activeRegistrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class, 'event_id', 'id')
            ->where('status', EventRegistration::STATUS_REGISTERED);
    }

    // =========================================================================
    // Query Scopes
    // =========================================================================

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('is_published', false);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('starts_at', '>=', now())
                ->orWhere('event_date', '>=', now());
        });
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    // =========================================================================
    // Accessors
    // =========================================================================

    public function getBannerUrlAttribute(): ?string
    {
        if (!$this->banner_image) {
            return null;
        }

        if (
            str_starts_with($this->banner_image, 'http://') ||
            str_starts_with($this->banner_image, 'https://')
        ) {
            return $this->banner_image;
        }

        return asset('storage/' . $this->banner_image);
    }
}
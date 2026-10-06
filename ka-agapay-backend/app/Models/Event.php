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
        'report_generated_at' => 'datetime',
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

    /**
     * WHEN AN EVENT HAS ENDED -- one rule for the residents' list, the
     * heatmap, registration and the event report.
     *
     * At its end time if it has one; otherwise at the end of the day it
     * starts on, in the Philippines (an 8:00 AM event without an end time
     * stays up until midnight). A post with no date at all never ends.
     * Ended events are not deleted: the dashboard keeps them under
     * "Past / History" for the records.
     */
    public function endTime(): ?\Illuminate\Support\Carbon
    {
        if ($this->ends_at) {
            return $this->ends_at->copy();
        }

        $start = $this->starts_at ?? $this->event_date;

        if (!$start) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($start)
            ->setTimezone(\App\Support\LocalTime::zone())
            ->endOfDay()
            ->utc();
    }

    public function hasEnded(): bool
    {
        $end = $this->endTime();

        return $end !== null && $end->isPast();
    }

    /** The same rule as hasEnded(), in SQL: events that have not ended yet. */
    public function scopeNotEnded(Builder $query): Builder
    {
        $startOfToday = \App\Support\LocalTime::today()->utc();

        return $query->where(function (Builder $q) use ($startOfToday) {
            $q->where('ends_at', '>=', now())
                ->orWhere(function (Builder $q) use ($startOfToday) {
                    $q->whereNull('ends_at')->where(function (Builder $d) use ($startOfToday) {
                        $d->where('starts_at', '>=', $startOfToday)
                            ->orWhere(fn (Builder $e) => $e->whereNull('starts_at')->where('event_date', '>=', $startOfToday))
                            ->orWhere(fn (Builder $e) => $e->whereNull('starts_at')->whereNull('event_date'));
                    });
                });
        });
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
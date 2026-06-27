<?php
// app/Models/Appointment.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    protected $fillable = [
        'user_id',
        'handled_by',
        'rhu_id',
        'appointment_date',
        'appointment_time',
        'purpose',
        'status',
        'notes',

        'consultation_type',
        'reason',
        'symptoms',

        'rejection_reason',
        'approved_at',
        'scheduled_at',

        'completed_at',
        'board_visible_until',
        'archived_at',
        'archive_reason',
        'has_pending_follow_up',
    ];

    protected $casts = [
        'appointment_date' => 'date',
        'approved_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
        'board_visible_until' => 'datetime',
        'archived_at' => 'datetime',
        'has_pending_follow_up' => 'boolean',
    ];

    public function resident(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by', 'user_id');
    }

    public function rhu(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'rhu_id', 'barangay_id');
    }

    public function consultation(): HasOne
    {
        return $this->hasOne(Consultation::class, 'appointment_id');
    }

    public function telemedicineRequest(): HasOne
    {
        return $this->hasOne(TelemedicineRequest::class, 'appointment_id');
    }

    public function queueTicket(): HasOne
    {
        return $this->hasOne(QueueTicket::class, 'appointment_id');
    }

    /**
     * Most recent follow-up reminder linked to this appointment. Lets the board
     * show a "Has follow-up" indicator without the closed appointment having to
     * stay on the active board.
     */
    public function latestFollowUp(): HasOne
    {
        return $this->hasOne(FollowUpReminder::class, 'appointment_id')->latestOfMany();
    }
}

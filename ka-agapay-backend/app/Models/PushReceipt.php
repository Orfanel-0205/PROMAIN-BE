<?php
// app/Models/PushReceipt.php
//
// One row per Expo push ticket. See the create_push_receipts_table migration
// for why this exists: an Expo ticket only means the message was queued, and
// the receipt fetched minutes later is the only thing that reports whether
// Google actually accepted it.

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushReceipt extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_OK = 'ok';
    public const STATUS_ERROR = 'error';

    /**
     * Expo's documented receipt error codes.
     *
     * DEVICE_NOT_REGISTERED is the only one that says anything about the
     * device itself; the rest describe the message or our own credentials, and
     * must never be treated as a reason to deactivate someone's device.
     */
    public const ERROR_DEVICE_NOT_REGISTERED = 'DeviceNotRegistered';
    public const ERROR_MESSAGE_TOO_BIG = 'MessageTooBig';
    public const ERROR_MESSAGE_RATE_EXCEEDED = 'MessageRateExceeded';
    public const ERROR_MISMATCH_SENDER_ID = 'MismatchSenderId';
    public const ERROR_INVALID_CREDENTIALS = 'InvalidCredentials';

    /**
     * Errors that mean the push credentials or Firebase configuration are
     * wrong for every device, not just this one. These are the ones worth
     * waking someone up for: they do not resolve on their own.
     */
    public const FATAL_CONFIG_ERRORS = [
        self::ERROR_MISMATCH_SENDER_ID,
        self::ERROR_INVALID_CREDENTIALS,
    ];

    protected $fillable = [
        'ticket_id',
        'user_device_token_id',
        'user_id',
        'channel_id',
        'notification_type',
        'status',
        'error_code',
        'error_message',
        'sent_at',
        'checked_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'checked_at' => 'datetime',
    ];

    public function deviceToken(): BelongsTo
    {
        return $this->belongsTo(UserDeviceToken::class, 'user_device_token_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}

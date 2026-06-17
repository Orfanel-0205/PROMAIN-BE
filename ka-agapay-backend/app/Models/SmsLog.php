<?php
// app/Models/SmsLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SmsLog extends Model
{
    use HasFactory;

    protected $table = 'sms_logs';

    public $timestamps = true;

    protected $fillable = [
        'user_id',
        'sent_by',
        'recipient_name',
        'mobile_number',
        'message',
        'mode',
        'target_filters',
        'notification_type',
        'provider',
        'provider_message_id',
        'status',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'target_filters' => 'array',
        'sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by', 'user_id');
    }
}
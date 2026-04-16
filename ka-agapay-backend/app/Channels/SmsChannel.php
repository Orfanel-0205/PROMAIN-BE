<?php

namespace App\Channels;

use App\Models\SmsLog;
use App\Services\Notification\SmsService;
use Illuminate\Notifications\Notification;

class SmsChannel
{
    public function __construct(private readonly SmsService $smsService) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (!method_exists($notification, 'toSms')) {
            return;
        }

        $mobile = $notifiable->mobile_number ?? null;
        if (!$mobile) return;

        $message = $notification->toSms($notifiable);

        $this->smsService->send($mobile, $message, get_class($notification), $notifiable->user_id ?? null);
    }
}

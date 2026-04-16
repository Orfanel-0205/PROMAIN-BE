<?php

namespace App\Notifications;

class NotificationTypes
{
    // Queue
    const QUEUE_TICKET_ISSUED   = 'queue_ticket_issued';
    const QUEUE_TICKET_CALLED   = 'queue_ticket_called';
    const QUEUE_TICKET_SKIPPED  = 'queue_ticket_skipped';
    const QUEUE_TICKET_CANCELLED= 'queue_ticket_cancelled';

    // Telemedicine
    const TELE_REQUEST_RECEIVED  = 'telemedicine_request_received';
    const TELE_REQUEST_SCREENED  = 'telemedicine_request_screened';
    const TELE_REQUEST_REJECTED  = 'telemedicine_request_rejected';
    const TELE_SESSION_SCHEDULED = 'telemedicine_session_scheduled';
    const TELE_SESSION_REMINDER  = 'telemedicine_session_reminder';
    const TELE_SESSION_STARTED   = 'telemedicine_session_started';
    const TELE_SESSION_ENDED     = 'telemedicine_session_ended';
    const TELE_REFERRAL_ISSUED   = 'telemedicine_referral_issued';

    // Appointments
    const APPOINTMENT_CONFIRMED  = 'appointment_confirmed';
    const APPOINTMENT_REMINDER   = 'appointment_reminder';
    const APPOINTMENT_CANCELLED  = 'appointment_cancelled';

    // Referrals
    const REFERRAL_RECEIVED      = 'referral_received';
    const REFERRAL_ACKNOWLEDGED  = 'referral_acknowledged';

    // System
    const ACCOUNT_ACTIVATED      = 'account_activated';
    const PASSWORD_RESET         = 'password_reset';
}

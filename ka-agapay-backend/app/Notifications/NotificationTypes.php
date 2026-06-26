<?php
// app/Notifications/NotificationTypes.php

namespace App\Notifications;

class NotificationTypes
{
    // Queue
    public const QUEUE_TICKET_ISSUED = 'queue_ticket_issued';
    public const QUEUE_TICKET_CALLED = 'queue_ticket_called';
    public const QUEUE_TICKET_SKIPPED = 'queue_ticket_skipped';
    public const QUEUE_TICKET_CANCELLED = 'queue_ticket_cancelled';

    // Appointments
    public const APPOINTMENT_REQUEST_RECEIVED = 'appointment_request_received';
    public const APPOINTMENT_CONFIRMED = 'appointment_confirmed';
    public const APPOINTMENT_UPDATED = 'appointment_updated';
    public const APPOINTMENT_REMINDER = 'appointment_reminder';
    public const APPOINTMENT_CANCELLED = 'appointment_cancelled';
    public const APPOINTMENT_REJECTED = 'appointment_rejected';
    public const APPOINTMENT_COMPLETED = 'appointment_completed';

    // Consultations
    public const CONSULTATION_STARTED = 'consultation_started';
    public const CONSULTATION_COMPLETED = 'consultation_completed';

    // Telemedicine
    public const TELE_REQUEST_RECEIVED = 'telemedicine_request_received';
    public const TELE_REQUEST_SCREENED = 'telemedicine_request_screened';
    public const TELE_REQUEST_REJECTED = 'telemedicine_request_rejected';
    public const TELE_SESSION_SCHEDULED = 'telemedicine_session_scheduled';
    public const TELE_SESSION_REMINDER = 'telemedicine_session_reminder';
    public const TELE_SESSION_STARTED = 'telemedicine_session_started';
    public const TELEMEDICINE_CALLING = 'telemedicine_calling';
    public const TELE_SESSION_ENDED = 'telemedicine_session_ended';
    public const TELE_REFERRAL_ISSUED = 'telemedicine_referral_issued';

    // Follow-ups
    public const FOLLOWUP_REMINDER = 'followup_reminder';

    // Prescriptions
    public const PRESCRIPTION_ISSUED = 'prescription_issued';
    public const PRESCRIPTION_DISPENSED = 'prescription_dispensed';

    // Events / Announcements
    public const EVENT_PUBLISHED = 'event_published';
    public const ANNOUNCEMENT_PUBLISHED = 'announcement_published';
    public const PROGRAM_PUBLISHED = 'program_published';

    // SMS
    public const SMS_SENT = 'sms_sent';
    public const SMS_FAILED = 'sms_failed';

    // Referrals
    public const REFERRAL_RECEIVED = 'referral_received';
    public const REFERRAL_ACKNOWLEDGED = 'referral_acknowledged';

    // Inventory
    public const INVENTORY_LOW_STOCK = 'inventory_low_stock';
    public const INVENTORY_EXPIRED = 'inventory_expired';

    // System
    public const ACCOUNT_ACTIVATED = 'account_activated';
    public const ACCOUNT_REJECTED = 'account_rejected';
    public const PASSWORD_RESET = 'password_reset';
    public const SYSTEM_ALERT = 'system_alert';
}

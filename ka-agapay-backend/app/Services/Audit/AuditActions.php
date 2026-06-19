<?php
// app/Services/Audit/AuditActions.php

namespace App\Services\Audit;

class AuditActions
{
    // Auth
    public const AUTH_LOGIN = 'auth.login';
    public const AUTH_LOGOUT = 'auth.logout';
    public const AUTH_LOGIN_FAILED = 'auth.login_failed';
    public const AUTH_TOKEN_REVOKED = 'auth.token_revoked';

    // Users
    public const USER_CREATED = 'user.created';
    public const USER_ACTIVATED = 'user.activated';
    public const USER_DEACTIVATED = 'user.deactivated';
    public const USER_DISABLED = 'user.disabled';
    public const USER_SUSPENDED = 'user.suspended';
    public const USER_DELETED = 'user.deleted';
    public const USER_RESTORED = 'user.restored';
    public const USER_ROLE_CHANGED = 'user.role_changed';
    public const USER_PASSWORD_RESET = 'user.password_reset';
    public const USER_PROFILE_UPDATED = 'user.profile_updated';

    // Announcements
    public const ANNOUNCEMENT_CREATED = 'announcement.created';
    public const ANNOUNCEMENT_UPDATED = 'announcement.updated';
    public const ANNOUNCEMENT_PUBLISHED = 'announcement.published';
    public const ANNOUNCEMENT_ARCHIVED = 'announcement.archived';
    public const ANNOUNCEMENT_DELETED = 'announcement.deleted';
    public const ANNOUNCEMENT_RESTORED = 'announcement.restored';

    // Events
    public const EVENT_CREATED = 'event.created';
    public const EVENT_UPDATED = 'event.updated';
    public const EVENT_PUBLISHED = 'event.published';
    public const EVENT_ARCHIVED = 'event.archived';
    public const EVENT_DELETED = 'event.deleted';
    public const EVENT_RESTORED = 'event.restored';

    // Queue
    public const QUEUE_TICKET_ISSUED = 'queue_ticket.issued';
    public const QUEUE_TICKET_CALLED = 'queue_ticket.called';
    public const QUEUE_TICKET_IN_SERVICE = 'queue_ticket.in_service';
    public const QUEUE_TICKET_COMPLETED = 'queue_ticket.completed';
    public const QUEUE_TICKET_CANCELLED = 'queue_ticket.cancelled';
    public const QUEUE_TICKET_NO_SHOW = 'queue_ticket.no_show';
    public const QUEUE_TICKET_SKIPPED = 'queue_ticket.skipped';

    // Telemedicine
    public const TELE_REQUEST_SUBMITTED = 'telemedicine_request.submitted';
    public const TELE_REQUEST_SCREENED = 'telemedicine_request.screened';
    public const TELE_REQUEST_REJECTED = 'telemedicine_request.rejected';
    public const TELE_SESSION_CREATED = 'telemedicine_session.created';
    public const TELE_SESSION_STARTED = 'telemedicine_session.started';
    public const TELE_SESSION_ENDED = 'telemedicine_session.ended';
    public const TELE_NOTES_FINALIZED = 'telemedicine_notes.finalized';
    public const TELE_REFERRAL_ISSUED = 'telemedicine_referral.issued';

    // Prescriptions
    public const PRESCRIPTION_ISSUED = 'prescription.issued';
    public const PRESCRIPTION_DISPENSED = 'prescription.dispensed';
    public const PRESCRIPTION_VOIDED = 'prescription.voided';
    public const PRESCRIPTION_CANCELLED = 'prescription.cancelled';
    public const PRESCRIPTION_EXPIRED = 'prescription.expired';

    // Referrals
    public const REFERRAL_CREATED = 'referral.created';
    public const REFERRAL_ACKNOWLEDGED = 'referral.acknowledged';
    public const REFERRAL_COMPLETED = 'referral.completed';
    public const REFERRAL_CANCELLED = 'referral.cancelled';
    public const REFERRAL_BHW_REPORT = 'referral.bhw_report_submitted';

    // Inventory
    public const INVENTORY_CREATED = 'inventory.created';
    public const INVENTORY_UPDATED = 'inventory.updated';
    public const INVENTORY_ARCHIVED = 'inventory.archived';
    public const INVENTORY_DELETED = 'inventory.deleted';
    public const INVENTORY_RESTORED = 'inventory.restored';

    // Notifications
    public const NOTIFICATION_READ = 'notification.read';
    public const NOTIFICATION_ALL_READ = 'notification.all_read';
    public const PREFERENCES_UPDATED = 'notification.preferences_updated';

    // Data access / PHI compliance
    public const RECORD_VIEWED = 'record.viewed';
    public const RECORD_EXPORTED = 'record.exported';
}
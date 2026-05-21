<?php
// app/Services/Audit/AuditActions.php

namespace App\Services\Audit;

class AuditActions
{
    // Auth
    const AUTH_LOGIN              = 'auth.login';
    const AUTH_LOGOUT             = 'auth.logout';
    const AUTH_LOGIN_FAILED       = 'auth.login_failed';
    const AUTH_TOKEN_REVOKED      = 'auth.token_revoked';

    // Users
    const USER_CREATED            = 'user.created';
    const USER_ACTIVATED          = 'user.activated';
    const USER_DEACTIVATED        = 'user.deactivated';
    const USER_ROLE_CHANGED       = 'user.role_changed';
    const USER_PASSWORD_RESET     = 'user.password_reset';
    const USER_PROFILE_UPDATED    = 'user.profile_updated';

    // Queue
    const QUEUE_TICKET_ISSUED     = 'queue_ticket.issued';
    const QUEUE_TICKET_CALLED     = 'queue_ticket.called';
    const QUEUE_TICKET_IN_SERVICE = 'queue_ticket.in_service';
    const QUEUE_TICKET_COMPLETED  = 'queue_ticket.completed';
    const QUEUE_TICKET_CANCELLED  = 'queue_ticket.cancelled';
    const QUEUE_TICKET_NO_SHOW    = 'queue_ticket.no_show';
    const QUEUE_TICKET_SKIPPED    = 'queue_ticket.skipped';

    // Telemedicine
    const TELE_REQUEST_SUBMITTED  = 'telemedicine_request.submitted';
    const TELE_REQUEST_SCREENED   = 'telemedicine_request.screened';
    const TELE_REQUEST_REJECTED   = 'telemedicine_request.rejected';
    const TELE_SESSION_CREATED    = 'telemedicine_session.created';
    const TELE_SESSION_STARTED    = 'telemedicine_session.started';
    const TELE_SESSION_ENDED      = 'telemedicine_session.ended';
    const TELE_NOTES_FINALIZED    = 'telemedicine_notes.finalized';
    const TELE_REFERRAL_ISSUED    = 'telemedicine_referral.issued';

    // Prescriptions
    const PRESCRIPTION_ISSUED     = 'prescription.issued';
    const PRESCRIPTION_DISPENSED  = 'prescription.dispensed';
    const PRESCRIPTION_VOIDED     = 'prescription.voided';
    const PRESCRIPTION_EXPIRED    = 'prescription.expired';

    // Referrals
    const REFERRAL_CREATED        = 'referral.created';
    const REFERRAL_ACKNOWLEDGED   = 'referral.acknowledged';
    const REFERRAL_COMPLETED      = 'referral.completed';
    const REFERRAL_CANCELLED      = 'referral.cancelled';
    const REFERRAL_BHW_REPORT     = 'referral.bhw_report_submitted';

    // Notifications
    const NOTIFICATION_READ       = 'notification.read';
    const NOTIFICATION_ALL_READ   = 'notification.all_read';
    const PREFERENCES_UPDATED     = 'notification.preferences_updated';

    // Data access (for PHI compliance)
    const RECORD_VIEWED           = 'record.viewed';
    const RECORD_EXPORTED         = 'record.exported';
}

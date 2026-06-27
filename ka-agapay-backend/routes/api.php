<?php
// routes/api.php

use Illuminate\Support\Facades\Route;

// =============================================================================
// CONTROLLERS
// =============================================================================

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\AdminProfileController;
use App\Http\Controllers\Api\AdminRegistrationController;
use App\Http\Controllers\Api\StaffAnnouncementNotificationController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\WebRtcController;
use App\Http\Controllers\Api\OcrController;
use App\Http\Controllers\Api\ApprovalController;
use App\Http\Controllers\Api\Ai\AiController;
use App\Http\Controllers\Api\Analytics\AnalyticsController;
use App\Http\Controllers\Api\Queue\QueueController;
use App\Http\Controllers\Api\Telemedicine\TelemedicineController;
use App\Http\Controllers\Api\Telemedicine\SessionController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminSmsController;
use App\Http\Controllers\Api\AiSettingsController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AdminDeletedRecordController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\BiometricController;
use App\Http\Controllers\Api\BarangayController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\HomeVisitController;
use App\Http\Controllers\Api\FeedbackController;
use App\Http\Controllers\Api\FollowUpReminderController;
use App\Http\Controllers\Api\ReportController;

// =============================================================================
// API V1
// =============================================================================

Route::prefix('v1')->group(function () {

    // =========================================================================
    // PUBLIC ROUTES
    // =========================================================================

    Route::middleware('throttle:5,1')->group(function () {

        // ---------------------------------------------------------------------
        // AUTHENTICATION
        // ---------------------------------------------------------------------

        Route::post('/register',        [AuthController::class, 'register']);
        Route::post('/login',           [AuthController::class, 'login']);

        // WEB ADMIN LOGIN / REGISTRATION
        Route::post('/admin/login',     [AuthController::class, 'adminLogin']);
        Route::post('/admin/register',  [AdminRegistrationController::class, 'store']);

        Route::post('/verify-otp',      [AuthController::class, 'verifyOtp']);
        Route::post('/resend-otp',      [AuthController::class, 'resendOtp']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password',  [AuthController::class, 'resetPassword']);

        // ---------------------------------------------------------------------
        // BIOMETRIC LOGIN
        // ---------------------------------------------------------------------

        Route::post('/biometric/login', [AuthController::class, 'biometricLogin']);
    });

    // =========================================================================
    // PUBLIC UTILITIES
    // =========================================================================

    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    Route::get('/barangays', [BarangayController::class, 'index'])
        ->middleware('throttle:30,1');

    // =========================================================================
    // AUTHENTICATED ROUTES
    // =========================================================================

    Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

        // =====================================================================
        // AUTH
        // =====================================================================

        Route::get('/user',             [AuthController::class, 'me']);
        Route::get('/me',               [AuthController::class, 'me']);
        Route::post('/logout',          [AuthController::class, 'logout']);
        Route::put('/me',               [AuthController::class, 'updateProfile']);
        Route::put('/change-password',  [AuthController::class, 'changePassword']);

        // =====================================================================
        // ADMIN PROFILE
        // Final URLs:
        // GET   /api/v1/admin/me
        // PATCH /api/v1/admin/profile
        // PATCH /api/v1/admin/profile/password
        // =====================================================================

        Route::middleware('role:admin,staff,staff_admin,rhu_admin,super_admin,superadmin,mho,mho_admin,it_staff,doctor,nurse,midwife')
            ->group(function () {
                Route::get('/admin/me', [AdminProfileController::class, 'show']);
                Route::patch('/admin/profile', [AdminProfileController::class, 'update']);
                Route::patch('/admin/profile/password', [AdminProfileController::class, 'changePassword']);
            });

        // =====================================================================
        // ADMIN USERS
        // Final URLs:
        // GET    /api/v1/admin/users
        // POST   /api/v1/admin/users
        // PATCH  /api/v1/admin/users/{id}
        // DELETE /api/v1/admin/users/{id}
        // PATCH  /api/v1/admin/users/{id}/status
        // PATCH  /api/v1/admin/users/{id}/assign-role
        // PATCH  /api/v1/admin/users/{id}/approve
        // PATCH  /api/v1/admin/users/{id}/reject
        // =====================================================================

        Route::prefix('admin/users')
            ->middleware('role:admin,staff_admin,rhu_admin,mho,municipal_mayor,it_staff,super_admin,superadmin')
            ->group(function () {
                Route::get('/',                    [AdminUserController::class, 'index']);
                Route::post('/',                   [AdminUserController::class, 'store']);
                Route::patch('/{id}',              [AdminUserController::class, 'update']);
                Route::put('/{id}',                [AdminUserController::class, 'update']);
                Route::delete('/{id}',             [AdminUserController::class, 'destroy']);

                Route::patch('/{id}/status',       [AdminUserController::class, 'status']);
                Route::patch('/{id}/assign-role',  [AdminUserController::class, 'assignRole']);
                Route::patch('/{id}/approve',      [AdminUserController::class, 'approve']);
                Route::patch('/{id}/reject',       [AdminUserController::class, 'reject']);
            });

        // =====================================================================
        // ADMIN SMS
        // Final URLs:
        // GET  /api/v1/admin/sms/account
        // GET  /api/v1/admin/sms/logs
        // POST /api/v1/admin/sms/preview
        // POST /api/v1/admin/sms/send
        // =====================================================================

        Route::prefix('admin/sms')->group(function () {
            Route::get('/account',  [AdminSmsController::class, 'account']);
            Route::get('/logs',     [AdminSmsController::class, 'logs']);
            Route::post('/preview', [AdminSmsController::class, 'preview']);
            Route::post('/send',    [AdminSmsController::class, 'send']);
        });

        // =====================================================================
        // ACTIVITY LOGS
        // =====================================================================

        Route::post('/activity-logs', [ActivityLogController::class, 'store']);

        Route::get('/activity-logs', [ActivityLogController::class, 'index'])
            ->middleware('role:super_admin,admin,rhu_admin');

        // =====================================================================
        // BIOMETRICS
        // =====================================================================

        Route::post('/biometric/enable',  [BiometricController::class, 'enable']);
        Route::post('/biometric/disable', [BiometricController::class, 'disable']);

        // =====================================================================
        // PROFILE
        // =====================================================================

        Route::get('/profile',         [ProfileController::class, 'show']);
        Route::patch('/profile',       [ProfileController::class, 'update']);
        Route::put('/profile',         [ProfileController::class, 'update']);
        Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar']);

        // =====================================================================
        // PATIENT SELF SERVICE
        // =====================================================================

        Route::get('/patients/search', [PatientController::class, 'searchForPrescription']);
        Route::get('/patient/me',      [PatientController::class, 'me']);
        Route::patch('/patient/me',    [PatientController::class, 'update']);

        // =====================================================================
        // DASHBOARD
        // =====================================================================

        Route::get('/dashboard',       [DashboardController::class, 'index']);
        Route::get('/dashboard/admin', [DashboardController::class, 'admin']);

        // =====================================================================
        // ANNOUNCEMENTS — PUBLIC / MOBILE RESIDENT SIDE
        // =====================================================================

        Route::get('/announcements',      [AnnouncementController::class, 'index']);
        Route::get('/announcements/{id}', [AnnouncementController::class, 'show']);

        // =====================================================================
        // AUDIT LOGS
        // =====================================================================

        Route::post('/logs', [AuditController::class, 'store']);

        // =====================================================================
        // ADMIN AUDIT / DELETE HISTORY
        // Final URLs:
        // GET  /api/v1/admin/audit
        // GET  /api/v1/admin/audit/delete-history
        // GET  /api/v1/admin/audit/users/{userId}
        // GET  /api/v1/admin/audit/subject-history
        // POST /api/v1/admin/audit
        // =====================================================================

        Route::prefix('admin/audit')
            ->middleware('role:admin,staff_admin,rhu_admin,super_admin,superadmin,mho,it_staff')
            ->group(function () {
                Route::get('/',                [AuditController::class, 'index']);
                Route::get('/delete-history',  [AuditController::class, 'deleteHistory']);

                /*
                 * DELETE HISTORY RESTORE + EXPIRATION
                 *
                 * Final URLs:
                 * POST /api/v1/admin/audit/delete-history/expire
                 * POST /api/v1/admin/audit/delete-history/{auditLogId}/restore
                 *
                 * Keep "expire" above "{auditLogId}/restore" so Laravel does not treat
                 * the word "expire" as an audit log ID.
                 */
                Route::post(
                    '/delete-history/expire',
                    [AdminDeletedRecordController::class, 'expire']
                );

                Route::post(
                    '/delete-history/{auditLogId}/restore',
                    [AdminDeletedRecordController::class, 'restore']
                );

                Route::get('/users/{userId}',  [AuditController::class, 'userTimeline']);
                Route::get('/subject-history', [AuditController::class, 'subjectHistory']);
                Route::post('/',               [AuditController::class, 'store']);
            });

        // =====================================================================
        // CHATBOT
        // =====================================================================

        Route::prefix('chat')->group(function () {
            Route::post('/message',               [ChatController::class, 'sendMessage']);
            Route::get('/history',                [ChatController::class, 'history']);
            Route::delete('/history/{sessionId}', [ChatController::class, 'destroySession']);
            Route::post('/end',                   [ChatController::class, 'endSession']);
            Route::post('/escalate',              [ChatController::class, 'escalateToDoctor']);
        });

        // =====================================================================
        // RESOURCES
        // =====================================================================

        Route::get('/prescriptions/mine',           [PrescriptionController::class, 'mine']);
        Route::get('/prescriptions/{id}/pdf',       [PrescriptionController::class, 'downloadPdf']);
        Route::post('/prescriptions/{id}/release',  [PrescriptionController::class, 'release']);
        Route::post('/prescriptions/{id}/dispense', [PrescriptionController::class, 'dispense']);
        Route::apiResource('prescriptions', PrescriptionController::class);
        Route::post('/consultations/{id}/prescriptions', [PrescriptionController::class, 'fromConsultation']);

        Route::apiResource('referrals', ReferralController::class);

        Route::get('/medicines/search',              [InventoryController::class, 'searchMedicines']);
        Route::get('/inventory/alerts',              [InventoryController::class, 'alerts']);
        Route::post('/inventory/{item}/stock-in',    [InventoryController::class, 'stockIn']);
        Route::post('/inventory/{item}/stock-out',   [InventoryController::class, 'stockOut']);
        Route::post('/inventory/{item}/adjust',      [InventoryController::class, 'adjust']);
        Route::get('/inventory/{item}/transactions', [InventoryController::class, 'transactions']);
        Route::apiResource('inventory', InventoryController::class);

        // =====================================================================
        // CONSULTATIONS — MOBILE PATIENT
        // =====================================================================

        Route::get('/consultations',      [ConsultationController::class, 'mine']);
        Route::get('/consultations/all',  [ConsultationController::class, 'mine']);
        Route::get('/consultations/{id}', [ConsultationController::class, 'mineShow']);

        // =====================================================================
        // APPOINTMENTS — MOBILE PATIENT
        // =====================================================================

        Route::prefix('appointments')->group(function () {
            Route::get('/',              [AppointmentController::class, 'index']);
            Route::post('/',             [AppointmentController::class, 'store']);
            Route::get('/my',            [AppointmentController::class, 'myAppointments']);
            Route::get('/show/{id}',     [AppointmentController::class, 'show']);
            Route::get('/detail/{id}',   [AppointmentController::class, 'show']);
            Route::get('/{userId}',      [AppointmentController::class, 'userAppointments']);
            Route::patch('/{id}/status', [AppointmentController::class, 'updateStatus']);
        });

        // =====================================================================
        // ADMIN CONSULTATIONS / APPOINTMENTS — WEB ADMIN
        // =====================================================================

        Route::middleware('role:admin,staff,rhu_admin,super_admin,mho,doctor,nurse,midwife')
            ->prefix('admin')
            ->group(function () {
                Route::get('/appointments',                          [AppointmentController::class, 'adminIndex']);
                Route::get('/appointments/{id}',                     [AppointmentController::class, 'adminShow']);
                Route::patch('/appointments/{id}/status',            [AppointmentController::class, 'adminUpdateStatus']);
                Route::post('/appointments/{id}/add-to-queue',       [AppointmentController::class, 'addToQueueFromAppointment']);
                Route::post('/appointments/{id}/start-consultation', [AppointmentController::class, 'startConsultationFromAppointment']);

                Route::get('/consultations',                 [ConsultationController::class, 'index']);
                Route::post('/consultations',                [ConsultationController::class, 'store']);
                Route::get('/consultations/{id}',            [ConsultationController::class, 'show']);
                Route::put('/consultations/{id}/soap',       [ConsultationController::class, 'updateSoap']);
                Route::patch('/consultations/{id}/complete', [ConsultationController::class, 'complete']);
            });

        // =====================================================================
        // PROGRAMS / EVENTS — MOBILE / RESIDENT SIDE
        // =====================================================================

        Route::get('/programs',                  [EventController::class, 'index']);
        Route::get('/programs/{id}',             [EventController::class, 'show']);
        Route::post('/programs/{id}/register',   [EventController::class, 'register']);
        Route::delete('/programs/{id}/register', [EventController::class, 'cancelRegistration']);

        Route::get('/my-event-registrations', [EventController::class, 'myEventRegistrations']);

        // =====================================================================
        // NOTIFICATIONS
        // =====================================================================

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/notifications', [NotificationController::class, 'index']);
            Route::post('/notifications/device-token', [NotificationController::class, 'storeDeviceToken']);
            Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
            Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
            Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

            Route::get('/notifications/preferences', [NotificationController::class, 'preferences']);
            Route::put('/notifications/preferences', [NotificationController::class, 'updatePreferences']);
        });

        // =====================================================================
        // QUEUE
        // =====================================================================

        Route::prefix('queue')->group(function () {
            Route::get('/',          [QueueController::class, 'index']);
            Route::post('/issue',    [QueueController::class, 'issue']);

            Route::get('/live',      [QueueController::class, 'live']);
            Route::get('/summary',   [QueueController::class, 'summary']);
            Route::get('/my-ticket', [QueueController::class, 'myTicket']);

            Route::post('/call-next', [QueueController::class, 'callNext']);
            Route::post('/call-priority-next', [QueueController::class, 'callPriorityNext']);

            // IMPORTANT: use {ticket}, not {id}
            Route::post('/{ticket}/start-service', [QueueController::class, 'startService']);
            Route::patch('/{ticket}/status', [QueueController::class, 'updateStatus']);
            Route::put('/{ticket}/status',   [QueueController::class, 'updateStatus']);

            Route::get('/{ticket}', [QueueController::class, 'show']);
        });

        // =====================================================================
        // FEEDBACK (PHASE 5)
        // GET   /api/v1/feedback
        // POST  /api/v1/feedback
        // GET   /api/v1/feedback/{id}
        // PATCH /api/v1/feedback/{id}/respond
        // =====================================================================

        Route::prefix('feedback')->group(function () {
            Route::get('/',               [FeedbackController::class, 'index']);
            Route::post('/',              [FeedbackController::class, 'store']);
            Route::get('/{id}',           [FeedbackController::class, 'show']);
            Route::patch('/{id}/respond', [FeedbackController::class, 'respond']);
        });

        // =====================================================================
        // FOLLOW-UP REMINDERS (staff SOAP follow-ups + SMS)
        // GET   /api/v1/follow-up-reminders
        // POST  /api/v1/follow-up-reminders
        // PATCH /api/v1/follow-up-reminders/{id}/status
        // =====================================================================

        Route::prefix('follow-up-reminders')->group(function () {
            Route::get('/',                 [FollowUpReminderController::class, 'index']);
            Route::post('/',                [FollowUpReminderController::class, 'store']);
            Route::patch('/{id}',           [FollowUpReminderController::class, 'update']);
            Route::patch('/{id}/status',    [FollowUpReminderController::class, 'updateStatus']);
            Route::post('/{id}/resend-sms', [FollowUpReminderController::class, 'resendSms']);
        });

        // =====================================================================
        // REPORTS — WEB ADMIN
        // =====================================================================

        Route::prefix('reports')
            ->middleware('role:admin,staff,rhu_admin,super_admin,mho,doctor,nurse,midwife')
            ->group(function () {
                Route::get('/consultations/diagnosis-itr', [
                    ReportController::class,
                    'diagnosisItr',
                ]);

                Route::get('/consultations/export', [
                    ReportController::class,
                    'exportDiagnosisItrCsv',
                ]);
            });

        // =====================================================================
        // TELEMEDICINE
        // Final URLs:
        // GET    /api/v1/telemedicine/requests
        // POST   /api/v1/telemedicine/requests
        // GET    /api/v1/telemedicine/requests/mine
        // GET    /api/v1/telemedicine/requests/{request}
        // PATCH  /api/v1/telemedicine/requests/{request}/screen
        // DELETE /api/v1/telemedicine/requests/{telemedicineRequest}
        //
        // GET    /api/v1/telemedicine/sessions
        // GET    /api/v1/telemedicine/sessions/{session}
        // PATCH  /api/v1/telemedicine/sessions/{session}/status
        // PUT    /api/v1/telemedicine/sessions/{session}/notes
        // =====================================================================

        Route::prefix('telemedicine')->group(function () {

            // -----------------------------------------------------------------
            // TELEMEDICINE REQUESTS
            // -----------------------------------------------------------------

            Route::get('/requests',                         [TelemedicineController::class, 'index']);
            Route::post('/requests',                        [TelemedicineController::class, 'store']);
            Route::get('/requests/mine',                    [TelemedicineController::class, 'mine']);
            Route::get('/requests/{request}',               [TelemedicineController::class, 'show']);
            Route::patch('/requests/{request}/screen',      [TelemedicineController::class, 'screen']);
            Route::delete('/requests/{telemedicineRequest}', [TelemedicineController::class, 'destroy']);

            // -----------------------------------------------------------------
            // TELEMEDICINE SESSIONS
            // -----------------------------------------------------------------

            Route::get('/sessions',                    [SessionController::class, 'index']);
            Route::get('/sessions/{session}',          [SessionController::class, 'show']);
            Route::patch('/sessions/{session}/status', [SessionController::class, 'updateStatus']);
            Route::patch('/sessions/{session}/end',    [SessionController::class, 'end']);
            Route::put('/sessions/{session}/notes',    [SessionController::class, 'saveNotes']);

            Route::post('/requests/{telemedicineRequest}/session', [SessionController::class, 'store']);

            // -----------------------------------------------------------------
            // WEBRTC / SIGNALING
            // -----------------------------------------------------------------

            Route::get('/sessions/{id}/join',    [WebRtcController::class, 'getJoinToken']);
            Route::post('/sessions/{id}/signal', [WebRtcController::class, 'signal']);
            Route::get('/sessions/{id}/signals', [WebRtcController::class, 'getSignals']);
        });

        // =====================================================================
        // OCR / ID VERIFICATION
        // Final URLs:
        // POST /api/v1/ocr/upload
        // GET  /api/v1/ocr/results/{id}
        // Also kept old URL:
        // GET  /api/v1/ocr/result/{id}
        // =====================================================================

        Route::prefix('ocr')->group(function () {
            Route::post('/upload',      [OcrController::class, 'upload']);
            Route::post('/philhealth',  [OcrController::class, 'scanPhilHealth']);
            Route::get('/results/{id}', [OcrController::class, 'result']);
            Route::get('/result/{id}',  [OcrController::class, 'result']);
            Route::post('/retry/{id}',  [OcrController::class, 'retry']);

            Route::post('/prescription/{consultationId}', [
                OcrController::class,
                'scanPrescription',
            ]);
        });

        // =====================================================================
        // HOME VISITS
        // =====================================================================

        Route::get('/home-visits',      [HomeVisitController::class, 'index']);
        Route::post('/home-visits',     [HomeVisitController::class, 'store']);
        Route::get('/home-visits/{id}', [HomeVisitController::class, 'show']);

        Route::patch('/home-visits/{id}/cancel', [
            HomeVisitController::class,
            'cancel',
        ]);

        // =====================================================================
        // AI
        // =====================================================================

        Route::prefix('ai')->group(function () {
            Route::post('/triage',                   [AiController::class, 'triage']);
            Route::post('/triage/telemedicine/{id}', [AiController::class, 'triageTelemedicine']);
            Route::post('/triage/queue/{id}',        [AiController::class, 'triageQueue']);
            Route::get('/history',                   [AiController::class, 'history']);
            Route::patch('/triage/{id}/override',    [AiController::class, 'override']);
            Route::post('/summarize-events',         [AiController::class, 'summarizeEvents']);

            Route::post('/summarize-consultation/{id}', [
                AiController::class,
                'summarizeConsultation',
            ]);

            Route::post('/summarize-telemedicine-session/{id}', [
                AiController::class,
                'summarizeTelemedicineSession',
            ]);
        });

        // =====================================================================
        // CLINICAL STAFF
        // =====================================================================

        Route::middleware('role:doctor,nurse,midwife,rhu_admin,super_admin')
            ->group(function () {
                Route::apiResource('patients', PatientController::class);
            });

        // =====================================================================
        // ANALYTICS
        // =====================================================================

        Route::prefix('analytics')
            ->middleware('role:admin,staff,rhu_admin,super_admin,mho')
            ->group(function () {
                Route::get('/overview',                [AnalyticsController::class, 'overview']);
                Route::get('/heatmap',                 [AnalyticsController::class, 'heatmap']);
                Route::get('/queue-performance',       [AnalyticsController::class, 'queuePerformance']);
                Route::get('/telemedicine-summary',    [AnalyticsController::class, 'telemedicineSummary']);
                Route::get('/barangay-health-profile', [AnalyticsController::class, 'barangayHealthProfile']);
                Route::get('/ai-accuracy',             [AnalyticsController::class, 'aiAccuracy']);
                Route::get('/registration-stats',      [AnalyticsController::class, 'registrationStats']);
                Route::get('/chatbot-usage',           [AnalyticsController::class, 'chatbotUsage']);
                Route::get('/realtime',                [AnalyticsController::class, 'realtime']);

                Route::get('/diagnosis-itr-summary', [
                    AnalyticsController::class,
                    'diagnosisItrSummary',
                ]);

                Route::get('/heatmap/diagnosis-itr-signals', [
                    AnalyticsController::class,
                    'heatmapDiagnosisItrSignals',
                ]);

                Route::get('/queue-heatmap',    [AnalyticsController::class, 'queueHeatmap']);
                Route::get('/barangay-risk',    [AnalyticsController::class, 'barangayRisk']);
                Route::get('/queue-density',    [AnalyticsController::class, 'queueDensity']);
                Route::get('/disease-clusters', [AnalyticsController::class, 'diseaseClusters']);

                Route::get('/outbreak-alerts', [
                    AnalyticsController::class,
                    'outbreakAlerts',
                ]);

                Route::post('/outbreak-alerts/{id}/resolve', [
                    AnalyticsController::class,
                    'resolveAlert',
                ]);

                Route::get('/priority-dashboard', [
                    AnalyticsController::class,
                    'priorityDashboard',
                ]);
            });

        // =====================================================================
        // ADMIN WEB CMS
        // =====================================================================

        Route::middleware('role:admin,staff,rhu_admin,super_admin,mho')
            ->prefix('admin')
            ->group(function () {

                Route::get('/announcements',                [AnnouncementController::class, 'adminIndex']);
                Route::post('/announcements',               [AnnouncementController::class, 'store']);

                Route::post('/announcements/{announcement}/notify-staff', [
                    StaffAnnouncementNotificationController::class,
                    'notify',
                ]);

                Route::put('/announcements/{id}',           [AnnouncementController::class, 'update']);
                Route::post('/announcements/{id}',          [AnnouncementController::class, 'update']);
                Route::patch('/announcements/{id}/publish', [AnnouncementController::class, 'publish']);
                Route::patch('/announcements/{id}/archive', [AnnouncementController::class, 'archive']);
                Route::delete('/announcements/{id}',        [AnnouncementController::class, 'destroy']);

                Route::get('/system-stats', [AdminController::class, 'systemStats']);

                Route::get('/ai-settings', [AiSettingsController::class, 'index']);
                Route::put('/ai-settings', [AiSettingsController::class, 'update']);

                Route::get('/events',                [EventController::class, 'adminIndex']);
                Route::post('/events',               [EventController::class, 'store']);
                Route::put('/events/{id}',           [EventController::class, 'update']);
                Route::post('/events/{id}',          [EventController::class, 'update']);
                Route::patch('/events/{id}/publish', [EventController::class, 'publish']);
                Route::delete('/events/{id}',        [EventController::class, 'destroy']);

                Route::get('/events/{id}/registrants', [
                    EventController::class,
                    'registrants',
                ]);

                Route::post('/programs', [EventController::class, 'store']);
            });

        // =====================================================================
        // SUPER ADMIN
        // =====================================================================

        Route::middleware('role:super_admin')
            ->prefix('superadmin')
            ->group(function () {
                Route::put('/users/{id}/role', [
                    AdminUserController::class,
                    'assignRole',
                ]);

                // Kept old route:
                // GET /api/v1/superadmin/audit-logs
                Route::get('/audit-logs', [
                    AuditController::class,
                    'index',
                ]);
            });

        // =====================================================================
        // APPROVALS
        // =====================================================================

        Route::middleware('role:super_admin,mho_admin,it_staff')
            ->prefix('approvals')
            ->group(function () {
                Route::get('/',        [ApprovalController::class, 'index']);
                Route::get('/pending', [ApprovalController::class, 'pending']);
                Route::get('/{id}',    [ApprovalController::class, 'show']);

                Route::patch('/{id}/approve',      [ApprovalController::class, 'approve']);
                Route::patch('/{id}/reject',       [ApprovalController::class, 'reject']);
                Route::patch('/{id}/request-info', [ApprovalController::class, 'requestInfo']);
            });
    });
});
<?php
// routes/api.php

use Illuminate\Support\Facades\Route;

// =============================================================================
// CONTROLLERS
// =============================================================================

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\PrescriptionController;
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
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiSettingsController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\BiometricController;
use App\Http\Controllers\Api\BarangayController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\HomeVisitController;

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

        // WEB ADMIN LOGIN
        Route::post('/admin/login',     [AuthController::class, 'adminLogin']);

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

    Route::get('/health', [HealthController::class, 'check']);

    Route::get('/barangays', [BarangayController::class, 'index'])
        ->middleware('throttle:30,1');

    // =========================================================================
    // ADMIN EVENTS ROUTES
    // =========================================================================
    // Added as requested.
    // Full URL examples:
    // GET    /api/v1/admin/events
    // POST   /api/v1/admin/events
    // POST   /api/v1/admin/events/{id}
    // PATCH  /api/v1/admin/events/{id}/publish
    // DELETE /api/v1/admin/events/{id}
    // GET    /api/v1/admin/events/{id}/registrants

    Route::middleware(['auth:sanctum', 'role:admin,staff,super_admin'])
        ->group(function () {
            Route::get('/admin/events', [EventController::class, 'adminIndex']);
            Route::post('/admin/events', [EventController::class, 'store']);
            Route::post('/admin/events/{id}', [EventController::class, 'update']);
            Route::patch('/admin/events/{id}/publish', [EventController::class, 'publish']);
            Route::delete('/admin/events/{id}', [EventController::class, 'destroy']);

            Route::get('/admin/events/{id}/registrants', [EventController::class, 'registrants']);
        });

    // =========================================================================
    // AUTHENTICATED ROUTES
    // =========================================================================

    Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

        // =====================================================================
        // ACTIVITY LOGS
        // =====================================================================

        Route::post('/activity-logs', [ActivityLogController::class, 'store']);

        Route::get('/activity-logs', [ActivityLogController::class, 'index'])
            ->middleware('role:super_admin,admin,rhu_admin');

        // =====================================================================
        // AUTH
        // =====================================================================

        Route::post('/logout',         [AuthController::class, 'logout']);
        Route::get('/me',              [AuthController::class, 'me']);
        Route::put('/me',              [AuthController::class, 'updateProfile']);
        Route::put('/change-password', [AuthController::class, 'changePassword']);

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

        Route::get('/patient/me',   [PatientController::class, 'me']);
        Route::patch('/patient/me', [PatientController::class, 'update']);

        // =====================================================================
        // DASHBOARD
        // =====================================================================

        Route::get('/dashboard',       [DashboardController::class, 'index']);
        Route::get('/dashboard/admin', [DashboardController::class, 'admin']);

        // =====================================================================
        // ANNOUNCEMENTS
        // =====================================================================

        Route::get('/announcements',      [AnnouncementController::class, 'index']);
        Route::get('/announcements/{id}', [AnnouncementController::class, 'show']);

        // =====================================================================
        // AUDIT LOGS
        // =====================================================================

        Route::post('/logs', [AuditController::class, 'store']);

        // =====================================================================
        // CHATBOT
        // =====================================================================

        Route::prefix('chat')->group(function () {
            Route::post('/message',  [ChatController::class, 'sendMessage']);
            Route::get('/history',   [ChatController::class, 'history']);
            Route::post('/end',      [ChatController::class, 'endSession']);
            Route::post('/escalate', [ChatController::class, 'escalateToDoctor']);
        });

        // =====================================================================
        // CONSULTATIONS
        // =====================================================================

        Route::get('/consultations', [ConsultationController::class, 'mine']);

        // =====================================================================
        // APPOINTMENTS
        // =====================================================================

        Route::prefix('appointments')->group(function () {
            Route::get('/',              [AppointmentController::class, 'index']);
            Route::post('/',             [AppointmentController::class, 'store']);
            Route::get('/my',            [AppointmentController::class, 'myAppointments']);
            Route::get('/show/{id}',     [AppointmentController::class, 'show']);
            Route::get('/{userId}',      [AppointmentController::class, 'userAppointments']);
            Route::patch('/{id}/status', [AppointmentController::class, 'updateStatus']);
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

        Route::get('/notifications',           [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        // =====================================================================
        // QUEUE
        // =====================================================================

        Route::prefix('queue')->group(function () {
            Route::get('/',              [QueueController::class, 'index']);
            Route::post('/issue',        [QueueController::class, 'issue']);
            Route::get('/my-ticket',     [QueueController::class, 'myTicket']);
            Route::get('/live',          [QueueController::class, 'live']);
            Route::get('/summary',       [QueueController::class, 'summary']);
            Route::post('/call-next',    [QueueController::class, 'callNext']);
            Route::patch('/{id}/status', [QueueController::class, 'updateStatus']);
        });

        // =====================================================================
        // TELEMEDICINE
        // =====================================================================

        Route::prefix('telemedicine')->group(function () {
            Route::get('/sessions',                     [SessionController::class, 'index']);
            Route::get('/sessions/{id}',                [SessionController::class, 'show']);
            Route::patch('/sessions/{id}/status',       [SessionController::class, 'updateStatus']);
            Route::put('/sessions/{id}/notes',          [SessionController::class, 'saveNotes']);
            Route::post('/sessions/{id}/prescriptions', [SessionController::class, 'issuePrescription']);
            Route::post('/sessions/{id}/referrals',     [SessionController::class, 'issueReferral']);
            Route::post('/sessions/{id}/transcribe',    [SessionController::class, 'transcribe']);
            Route::post('/sessions/{id}/summarize',     [SessionController::class, 'summarize']);

            Route::get('/requests',               [TelemedicineController::class, 'indexRequests']);
            Route::post('/requests',              [TelemedicineController::class, 'createRequest']);
            Route::get('/requests/mine',          [TelemedicineController::class, 'myRequests']);
            Route::get('/requests/{id}',          [TelemedicineController::class, 'showRequest']);
            Route::patch('/requests/{id}/screen', [TelemedicineController::class, 'screenRequest']);
            Route::delete('/requests/{id}',       [TelemedicineController::class, 'cancelRequest']);

            Route::post('/requests/{id}/session', [SessionController::class, 'create']);

            Route::get('/sessions/{id}/join',           [WebRtcController::class, 'getJoinToken']);
            Route::post('/sessions/{id}/signal',        [WebRtcController::class, 'signal']);
            Route::get('/sessions/{id}/signals',        [WebRtcController::class, 'getSignals']);
            Route::post('/sessions/{id}/ice-candidate', [WebRtcController::class, 'iceCandidate']);
        });

        // =====================================================================
        // OCR / ID VERIFICATION
        // =====================================================================

        Route::prefix('ocr')->group(function () {
            Route::post('/upload',     [OcrController::class, 'upload']);
            Route::get('/result/{id}', [OcrController::class, 'result']);
            Route::post('/retry/{id}', [OcrController::class, 'retry']);

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
            Route::post('/triage/telemedicine/{id}', [AiController::class, 'triageTelemedicine']);
            Route::post('/triage/queue/{id}',        [AiController::class, 'triageQueue']);
            Route::get('/history',                   [AiController::class, 'history']);
            Route::patch('/triage/{id}/override',    [AiController::class, 'override']);
            Route::post('/summarize-events',         [AiController::class, 'summarizeEvents']);
        });

        // =====================================================================
        // CLINICAL STAFF
        // =====================================================================

        Route::middleware('role:doctor,nurse,midwife,rhu_admin,super_admin')
            ->group(function () {
                Route::apiResource('/patients', PatientController::class);

                Route::get('/consultations/all', [
                    ConsultationController::class,
                    'index',
                ]);
            });

        // =====================================================================
        // ANALYTICS
        // =====================================================================

        Route::prefix('analytics')
            ->middleware('role:admin,staff,rhu_admin,super_admin,mho')
            ->group(function () {
                Route::get('/overview',                [AnalyticsController::class, 'overview']);
                Route::get('/queue-performance',       [AnalyticsController::class, 'queuePerformance']);
                Route::get('/telemedicine-summary',    [AnalyticsController::class, 'telemedicineSummary']);
                Route::get('/barangay-health-profile', [AnalyticsController::class, 'barangayHealthProfile']);
                Route::get('/ai-accuracy',             [AnalyticsController::class, 'aiAccuracy']);
                Route::get('/registration-stats',      [AnalyticsController::class, 'registrationStats']);
                Route::get('/chatbot-usage',           [AnalyticsController::class, 'chatbotUsage']);

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
        // RESOURCES
        // =====================================================================

        Route::apiResource('prescriptions', PrescriptionController::class);
        Route::apiResource('referrals',     ReferralController::class);
        Route::apiResource('inventory',     InventoryController::class);

        // =====================================================================
        // ADMIN WEB CMS
        // =====================================================================

        Route::middleware('role:admin,staff,rhu_admin,super_admin,mho')
            ->prefix('admin')
            ->group(function () {

                Route::get('/users',                 [AdminUserController::class, 'index']);
                Route::post('/users',                [AdminUserController::class, 'store']);
                Route::put('/users/{id}',            [AdminUserController::class, 'update']);
                Route::delete('/users/{id}',         [AdminUserController::class, 'destroy']);
                Route::patch('/users/{id}/suspend',  [AdminUserController::class, 'suspend']);
                Route::patch('/users/{id}/activate', [AdminUserController::class, 'activate']);

                Route::get('/system-stats', [AdminController::class, 'systemStats']);

                Route::get('/ai-settings', [AiSettingsController::class, 'index']);
                Route::put('/ai-settings', [AiSettingsController::class, 'update']);

                Route::get('/events',                 [EventController::class, 'adminIndex']);
                Route::post('/events',                [EventController::class, 'store']);
                Route::put('/events/{id}',            [EventController::class, 'update']);
                Route::post('/events/{id}',           [EventController::class, 'update']);
                Route::patch('/events/{id}/publish',  [EventController::class, 'publish']);
                Route::delete('/events/{id}',         [EventController::class, 'destroy']);

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
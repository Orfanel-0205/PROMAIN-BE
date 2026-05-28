<?php

use Illuminate\Support\Facades\Route;
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

Route::prefix('v1')->group(function () {

    // =============================================================================
    // PUBLIC — Tight rate limits to prevent abuse
    // =============================================================================

    Route::middleware('throttle:5,1')->group(function () {

        // ── Authentication ────────────────────────────────────────────────
        Route::post('/register',          [AuthController::class, 'register']);
        Route::post('/login',             [AuthController::class, 'login']);
        Route::post('/verify-otp',        [AuthController::class, 'verifyOtp']);
        Route::post('/resend-otp',        [AuthController::class, 'resendOtp']);
        Route::post('/forgot-password',   [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password',    [AuthController::class, 'resetPassword']);

        // ── Biometric Login ──────────────────────────────────────────────
        Route::post('/biometric/login',   [AuthController::class, 'biometricLogin']);
    });

    // ── Public utilities ─────────────────────────────────────────────────
    Route::get('/health', [HealthController::class, 'check']);

    // Barangay list — public for registration forms
    Route::get('/barangays', [BarangayController::class, 'index'])
        ->middleware('throttle:30,1');

    // =============================================================================
    // AUTHENTICATED — Requires Sanctum token
    // =============================================================================

    Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

        // ── Auth ──────────────────────────────────────────────────────────
        Route::post('/logout',         [AuthController::class, 'logout']);
        Route::get('/me',              [AuthController::class, 'me']);
        Route::put('/me',              [AuthController::class, 'updateProfile']);
        Route::put('/change-password', [AuthController::class, 'changePassword']);

        // ── Biometrics ────────────────────────────────────────────────────
        Route::post('/biometric/enable',  [BiometricController::class, 'enable']);
        Route::post('/biometric/disable', [BiometricController::class, 'disable']);

        // ── Profile ───────────────────────────────────────────────────────
        Route::get('/profile',         [ProfileController::class, 'show']);
        Route::patch('/profile',       [ProfileController::class, 'update']);
        Route::put('/profile',         [ProfileController::class, 'update']);
        Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar']);

        // ── Dashboard ─────────────────────────────────────────────────────
        Route::get('/dashboard',       [DashboardController::class, 'index']);
        Route::get('/dashboard/admin', [DashboardController::class, 'admin']);

        // ── Announcements ─────────────────────────────────────────────────
        Route::get('/announcements',      [AnnouncementController::class, 'index']);
        Route::get('/announcements/{id}', [AnnouncementController::class, 'show']);

        // ── Activity Logs ────────────────────────────────────────────────
        Route::post('/logs', [AuditController::class, 'store']);

        // Optional dedicated ActivityLogController endpoint
        Route::post('/activity-logs', [ActivityLogController::class, 'store']);

        // ── Chat / Chatbot ────────────────────────────────────────────────
        Route::prefix('chat')->group(function () {
            Route::post('/message',  [ChatController::class, 'sendMessage']);
            Route::get('/history',   [ChatController::class, 'history']);
            Route::post('/end',      [ChatController::class, 'endSession']);
            Route::post('/escalate', [ChatController::class, 'escalateToDoctor']);
        });

        // ── Consultations ─────────────────────────────────────────────────
        Route::get('/consultations', [ConsultationController::class, 'mine']);

        // ── Appointments ──────────────────────────────────────────────────
        Route::prefix('appointments')->group(function () {
            Route::get('/',              [AppointmentController::class, 'index']);
            Route::post('/',             [AppointmentController::class, 'store']);
            Route::get('/my',            [AppointmentController::class, 'myAppointments']);
            Route::get('/show/{id}',     [AppointmentController::class, 'show']);
            Route::get('/{userId}',      [AppointmentController::class, 'userAppointments']);
            Route::patch('/{id}/status', [AppointmentController::class, 'updateStatus']);
        });

        // ── Programs / Events ─────────────────────────────────────────────
        Route::get('/programs',                [EventController::class, 'index']);
        Route::get('/programs/{id}',           [EventController::class, 'show']);
        Route::post('/programs/{id}/register', [EventController::class, 'register']);

        // ── Notifications ─────────────────────────────────────────────────
        Route::get('/notifications',           [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

        // ── Queue ─────────────────────────────────────────────────────────
        Route::prefix('queue')->group(function () {
            Route::get('/',              [QueueController::class, 'index']);
            Route::post('/issue',        [QueueController::class, 'issue']);
            Route::get('/my-ticket',     [QueueController::class, 'myTicket']);
            Route::get('/live',          [QueueController::class, 'live']);
            Route::get('/summary',       [QueueController::class, 'summary']);
            Route::post('/call-next',    [QueueController::class, 'callNext']);
            Route::patch('/{id}/status', [QueueController::class, 'updateStatus']);
        });

        // ── Telemedicine ──────────────────────────────────────────────────
        Route::prefix('telemedicine')->group(function () {

            // Sessions
            Route::get('/sessions',                      [SessionController::class, 'index']);
            Route::get('/sessions/{id}',                 [SessionController::class, 'show']);
            Route::patch('/sessions/{id}/status',        [SessionController::class, 'updateStatus']);
            Route::put('/sessions/{id}/notes',           [SessionController::class, 'saveNotes']);
            Route::post('/sessions/{id}/prescriptions',  [SessionController::class, 'issuePrescription']);
            Route::post('/sessions/{id}/referrals',      [SessionController::class, 'issueReferral']);
            Route::post('/sessions/{id}/transcribe',     [SessionController::class, 'transcribe']);
            Route::post('/sessions/{id}/summarize',      [SessionController::class, 'summarize']);

            // Requests
            Route::get('/requests',               [TelemedicineController::class, 'indexRequests']);
            Route::post('/requests',              [TelemedicineController::class, 'createRequest']);
            Route::get('/requests/mine',          [TelemedicineController::class, 'myRequests']);
            Route::get('/requests/{id}',          [TelemedicineController::class, 'showRequest']);
            Route::patch('/requests/{id}/screen', [TelemedicineController::class, 'screenRequest']);
            Route::delete('/requests/{id}',       [TelemedicineController::class, 'cancelRequest']);

            // Session creation
            Route::post('/requests/{id}/session', [SessionController::class, 'create']);

            // WebRTC
            Route::get('/sessions/{id}/join',           [WebRtcController::class, 'getJoinToken']);
            Route::post('/sessions/{id}/signal',        [WebRtcController::class, 'signal']);
            Route::get('/sessions/{id}/signals',        [WebRtcController::class, 'getSignals']);
            Route::post('/sessions/{id}/ice-candidate', [WebRtcController::class, 'iceCandidate']);
        });

        // ── OCR / ID Verification ─────────────────────────────────────────
        Route::prefix('ocr')->group(function () {
            Route::post('/upload',     [OcrController::class, 'upload']);
            Route::get('/{id}',        [OcrController::class, 'result']);
            Route::post('/{id}/retry', [OcrController::class, 'retry']);
        });

        // ── AI ────────────────────────────────────────────────────────────
        Route::prefix('ai')->group(function () {
            Route::post('/triage/telemedicine/{id}', [AiController::class, 'triageTelemedicine']);
            Route::post('/triage/queue/{id}',        [AiController::class, 'triageQueue']);
            Route::get('/history',                   [AiController::class, 'history']);
            Route::patch('/triage/{id}/override',    [AiController::class, 'override']);
        });

        // ── Clinical staff routes ─────────────────────────────────────────
        Route::middleware('role:doctor,nurse,midwife,rhu_admin,super_admin')->group(function () {
            Route::apiResource('/patients', PatientController::class);

            Route::get('/consultations/all', [
                ConsultationController::class,
                'index'
            ]);
        });

        // ── BHW routes ────────────────────────────────────────────────────
        Route::middleware('role:bhw,rhu_admin,super_admin')->group(function () {
            Route::get('/home-visits', [HomeVisitController::class, 'index']);
        });

        // ── Analytics ─────────────────────────────────────────────────────
        Route::prefix('analytics')
            ->middleware('role:admin,staff,super_admin')
            ->group(function () {

                // Core analytics dashboard
                Route::get('/overview',                [AnalyticsController::class, 'overview']);
                Route::get('/queue-performance',       [AnalyticsController::class, 'queuePerformance']);
                Route::get('/telemedicine-summary',    [AnalyticsController::class, 'telemedicineSummary']);
                Route::get('/barangay-health-profile', [AnalyticsController::class, 'barangayHealthProfile']);
                Route::get('/ai-accuracy',             [AnalyticsController::class, 'aiAccuracy']);
                Route::get('/registration-stats',      [AnalyticsController::class, 'registrationStats']);
                Route::get('/chatbot-usage',           [AnalyticsController::class, 'chatbotUsage']);

                // GIS / Heatmaps
                Route::get('/queue-heatmap',    [AnalyticsController::class, 'queueHeatmap']);
                Route::get('/barangay-risk',    [AnalyticsController::class, 'barangayRisk']);
                Route::get('/queue-density',    [AnalyticsController::class, 'queueDensity']);
                Route::get('/disease-clusters', [AnalyticsController::class, 'diseaseClusters']);

                // Alerts
                Route::get('/outbreak-alerts',               [AnalyticsController::class, 'outbreakAlerts']);
                Route::post('/outbreak-alerts/{id}/resolve', [AnalyticsController::class, 'resolveAlert']);

                // AI + Priority
                Route::get('/priority-dashboard', [AnalyticsController::class, 'priorityDashboard']);
            });

        // ── Resources ─────────────────────────────────────────────────────
        Route::apiResource('prescriptions', PrescriptionController::class);
        Route::apiResource('referrals',     ReferralController::class);
        Route::apiResource('inventory',     InventoryController::class);

        // ── Admin Routes ──────────────────────────────────────────────────
        Route::middleware('role:rhu_admin,super_admin')
            ->prefix('admin')
            ->group(function () {

                Route::get('/users',                 [AdminUserController::class, 'index']);
                Route::post('/users',                [AdminUserController::class, 'store']);
                Route::put('/users/{id}',            [AdminUserController::class, 'update']);
                Route::delete('/users/{id}',         [AdminUserController::class, 'destroy']);

                Route::patch('/users/{id}/suspend',  [AdminUserController::class, 'suspend']);
                Route::patch('/users/{id}/activate', [AdminUserController::class, 'activate']);

                Route::get('/system-stats',          [AdminController::class, 'systemStats']);
                Route::get('/ai-settings',           [AiSettingsController::class, 'index']);
                Route::put('/ai-settings',           [AiSettingsController::class, 'update']);

                Route::post('/programs',             [EventController::class, 'store']);
            });

        // ── Super Admin ───────────────────────────────────────────────────
        Route::middleware('role:super_admin')
            ->prefix('superadmin')
            ->group(function () {

                Route::put('/users/{id}/role', [AdminUserController::class, 'assignRole']);

                Route::get('/audit-logs', [
                    AuditController::class,
                    'index'
                ]);
            });

        // ── Approvals ─────────────────────────────────────────────────────
        Route::middleware('role:super_admin,mho_admin,it_staff')
            ->prefix('approvals')
            ->group(function () {

                Route::get('/',                    [ApprovalController::class, 'index']);
                Route::get('/pending',             [ApprovalController::class, 'pending']);
                Route::get('/{id}',                [ApprovalController::class, 'show']);

                Route::patch('/{id}/approve',      [ApprovalController::class, 'approve']);
                Route::patch('/{id}/reject',       [ApprovalController::class, 'reject']);
                Route::patch('/{id}/request-info', [ApprovalController::class, 'requestInfo']);
            });
    });
});
<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\MedicalReportController;
use App\Http\Controllers\Api\ResidentProfileController;
use App\Http\Controllers\Api\Queue\QueueController;
use App\Http\Controllers\Api\Telemedicine\TelemedicineController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\ReferralController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $dbOk    = false;
    $redisOk = false;

    try {
        \Illuminate\Support\Facades\DB::connection()->getPdo();
        $dbOk = true;
    } catch (\Exception $e) {}

    try {
        \Illuminate\Support\Facades\Cache::store('redis')->put('health_check', 1, 5);
        $redisOk = \Illuminate\Support\Facades\Cache::store('redis')->get('health_check') === 1;
    } catch (\Exception $e) {}

    $status = $dbOk && $redisOk ? 200 : 503;

    return response()->json([
        'status'    => $status === 200 ? 'ok' : 'degraded',
        'database'  => $dbOk ? 'ok' : 'error',
        'redis'     => $redisOk ? 'ok' : 'error',
        'timestamp' => now()->toIso8601String(),
        'version'   => config('app.version', '1.0.0'),
    ], $status);
});


// ─── Public Auth Routes ───────────────────────────────────────────────────────
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);
});

// ─── Authenticated Routes ─────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

    // ── Queue Routes ──────────────────────────────────────────────────────────
    Route::prefix('v1/queue')->name('queue.')->group(function () {
        Route::get('/',                  [QueueController::class, 'index'])->name('index');
        Route::get('/live',              [QueueController::class, 'live'])->name('live');
        Route::get('/summary',           [QueueController::class, 'summary'])->name('summary');
        Route::post('/issue',            [QueueController::class, 'issue'])->name('issue');
        Route::post('/call-next',        [QueueController::class, 'callNext'])->name('callNext');
        Route::get('/my-ticket',         [QueueController::class, 'myTicket'])->name('myTicket');
        Route::get('/{ticket}',          [QueueController::class, 'show'])->name('show');
        Route::patch('/{ticket}/status', [QueueController::class, 'updateStatus'])->name('updateStatus');
    });

    // ── Resident Routes ───────────────────────────────────────────────────────
    Route::middleware('role:resident')->group(function () {
        Route::get('/profile',  [ResidentProfileController::class, 'show']);
        Route::put('/profile',  [ResidentProfileController::class, 'update']);

        Route::get('/appointments',          [AppointmentController::class, 'myAppointments']);
        Route::post('/appointments',         [AppointmentController::class, 'store']);

        Route::post('/events/{event}/register', [EventController::class, 'register']);

        Route::get('/consultations/mine',    [ConsultationController::class, 'mine']);
    });

    // ── Public-read routes (any authenticated user) ───────────────────────────
    Route::get('/announcements',         [AnnouncementController::class, 'index']);
    Route::get('/announcements/{id}',    [AnnouncementController::class, 'show']);

    Route::get('/events',                [EventController::class, 'index']);
    Route::get('/events/{id}',           [EventController::class, 'show']);

    // ── Staff / Admin Routes ──────────────────────────────────────────────────
    Route::middleware('role:staff_admin,super_admin,mho')->group(function () {

        // Announcements CRUD
        Route::post('/announcements',              [AnnouncementController::class, 'store']);
        Route::put('/announcements/{id}',          [AnnouncementController::class, 'update']);
        Route::post('/announcements/{id}/publish', [AnnouncementController::class, 'publish']);

        // Events CRUD
        Route::post('/events',           [EventController::class, 'store']);
        Route::put('/events/{id}',       [EventController::class, 'update']);

        // Appointments management
        Route::get('/appointments',                    [AppointmentController::class, 'index']);
        Route::put('/appointments/{id}/status',        [AppointmentController::class, 'updateStatus']);

        // Consultations
        Route::post('/consultations',                  [ConsultationController::class, 'store']);
        Route::get('/consultations',                   [ConsultationController::class, 'index']);
        Route::get('/consultations/{id}',              [ConsultationController::class, 'show']);
        Route::put('/consultations/{id}',              [ConsultationController::class, 'update']);

        // Medical Reports
        Route::post('/medical-reports',                [MedicalReportController::class, 'store']);
        Route::get('/medical-reports/{id}',            [MedicalReportController::class, 'show']);
        Route::get('/users/{userId}/medical-reports',  [MedicalReportController::class, 'forResident']);
    });

    // ── Super Admin Only ──────────────────────────────────────────────────────
    Route::middleware('role:super_admin')->group(function () {
        Route::get('/admin/users', function () {
            return response()->json(\App\Models\User::with(['role', 'barangay'])->paginate(20));
        });
    });
});
Route::prefix('v1')->middleware(['auth:sanctum'])->group(function () {

    // Telemedicine Module
    Route::prefix('telemedicine')->name('telemedicine.')->group(function () {

        // -- Request Lifecycle --
        Route::get('/requests',          [TelemedicineController::class, 'indexRequests'])->name('requests.index');
        Route::post('/requests',         [TelemedicineController::class, 'createRequest'])->name('requests.create');
        Route::get('/requests/mine',     [TelemedicineController::class, 'myRequests'])->name('requests.mine');
        Route::get('/requests/{request}', [TelemedicineController::class, 'showRequest'])->name('requests.show');
        Route::patch('/requests/{request}/screen',  [TelemedicineController::class, 'screenRequest'])->name('requests.screen');
        Route::delete('/requests/{request}',        [TelemedicineController::class, 'cancelRequest'])->name('requests.cancel');

        // -- Session Lifecycle --
        Route::post('/requests/{request}/session',          [TelemedicineController::class, 'createSession'])->name('sessions.create');
        Route::get('/sessions',                             [TelemedicineController::class, 'mySessions'])->name('sessions.mine');
        Route::get('/sessions/{session}',                   [TelemedicineController::class, 'showSession'])->name('sessions.show');
        Route::patch('/sessions/{session}/status',          [TelemedicineController::class, 'updateSessionStatus'])->name('sessions.status');

        // -- Clinical Notes --
        Route::put('/sessions/{session}/notes',             [TelemedicineController::class, 'saveNotes'])->name('sessions.notes');

        // -- Referrals --
        Route::post('/sessions/{session}/referrals',        [TelemedicineController::class, 'createReferral'])->name('sessions.referrals.create');

        // -- Dashboard --
        Route::get('/summary',                              [TelemedicineController::class, 'summary'])->name('summary');
    });

    // Notification Module
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/',                  [NotificationController::class, 'index']);
        Route::get('/unread-count',      [NotificationController::class, 'unreadCount']);
        Route::get('/preferences',       [NotificationController::class, 'preferences']);
        Route::put('/preferences',       [NotificationController::class, 'updatePreferences']);
        Route::post('/read-all',         [NotificationController::class, 'markAllRead']);
        Route::patch('/{id}/read',       [NotificationController::class, 'markRead']);
        Route::delete('/{id}',           [NotificationController::class, 'destroy']);
    });

    // Audit Module
    Route::prefix('audit')->group(function () {
        Route::get('/',                         [AuditController::class, 'index']);
        Route::get('/user/{userId}',            [AuditController::class, 'userTimeline']);
        Route::get('/subject',                  [AuditController::class, 'subjectHistory']);
    });

    // Prescriptions Module
    Route::prefix('prescriptions')->name('prescriptions.')->group(function () {
        Route::get('/',                              [PrescriptionController::class, 'index']);
        Route::post('/',                             [PrescriptionController::class, 'store']);
        Route::get('/mine',                          [PrescriptionController::class, 'mine']);
        Route::get('/resident/{residentProfileId}',  [PrescriptionController::class, 'forResident']);
        Route::get('/{prescription}',                [PrescriptionController::class, 'show']);
        Route::post('/{prescription}/dispense',      [PrescriptionController::class, 'dispense']);
        Route::patch('/{prescription}/void',         [PrescriptionController::class, 'void']);
    });

    // Referrals Module
    Route::prefix('referrals')->name('referrals.')->group(function () {
        Route::get('/',                          [ReferralController::class, 'index']);
        Route::post('/',                         [ReferralController::class, 'store']);
        Route::get('/mine',                      [ReferralController::class, 'mine']);
        Route::get('/bhw-assigned',              [ReferralController::class, 'bhwAssigned']);
        Route::get('/{referral}',                [ReferralController::class, 'show']);
        Route::patch('/{referral}/status',       [ReferralController::class, 'updateStatus']);
        Route::post('/{referral}/bhw-report',    [ReferralController::class, 'bhwReport']);
    });

});
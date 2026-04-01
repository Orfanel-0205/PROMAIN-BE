<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AnnouncementController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\MedicalReportController;
use App\Http\Controllers\Api\ResidentProfileController;
use Illuminate\Support\Facades\Route;

// ─── Public Auth Routes ───────────────────────────────────────────────────────
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// ─── Authenticated Routes ─────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',      [AuthController::class, 'me']);

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
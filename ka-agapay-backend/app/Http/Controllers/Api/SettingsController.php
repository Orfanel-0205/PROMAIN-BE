<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Support\AppSettings;
use App\Support\Rhu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Real persistence for the admin Settings page.
 *
 * Before this existed, Facility Information, Notifications & SMS and Security
 * Rules were written to one browser localStorage key and read back from it, so
 * every value was per-laptop and no value ever reached the server.
 *
 * WHAT IS ENFORCED VS. WHAT IS ONLY STORED
 * ----------------------------------------
 * This distinction is reported to the client in `meta` so the UI can say so on
 * screen, rather than leaving a reader to assume a saved value does something:
 *
 *   ENFORCED  security.max_login_attempts -- read by the auth-login rate
 *             limiter in RouteServiceProvider on every login attempt.
 *
 *   STORED    security.session_timeout_minutes -- Sanctum's token lifetime is
 *             config('sanctum.expiration'); changing this row does not alter
 *             it. Wiring it would change the lifetime of every existing token,
 *             which is a deliberate decision rather than a side effect.
 *
 *   STORED    every notifications field -- the Semaphore pipeline reads its
 *             credentials from config/env, not from here. Making these live
 *             would mean modifying SemaphoreSmsService, which is out of bounds
 *             under this project's standing rule about the SMS pipeline.
 *
 * THE SMS API KEY IS NOT STORED HERE AND NEVER LEAVES THE SERVER.
 * The old UI shipped `smsApiKeyConfigured: true` as a hardcoded default, so a
 * browser that had never been configured still displayed "Configured". What
 * `meta.sms_api_key_configured` reports instead is whether the server actually
 * has a non-empty credential. The value itself is never read into a response.
 */
class SettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rhuId = Rhu::scopeRhuId($request->user(), $request->integer('rhu_id') ?: null);

        return response()->json($this->payload($rhuId));
    }

    public function updateFacility(Request $request): JsonResponse
    {
        $rhuId = Rhu::scopeRhuId($request->user(), $request->integer('rhu_id') ?: null);

        $validated = $request->validate([
            'facility_name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],

            // Digits and separators only. This also rejects the placeholder the
            // old page shipped as a default -- "+63 75 XXX XXXX" -- which read
            // as a configured phone number on a fresh browser.
            'contact_number' => ['required', 'string', 'regex:/^[0-9+()\-\s]{7,20}$/'],

            'email' => ['nullable', 'email:rfc', 'max:150'],
            'operating_hours' => ['required', 'string', 'max:120'],
        ], [
            'contact_number.regex' => 'Contact Number may only contain digits, spaces, +, -, and parentheses.',
        ]);

        AppSettings::putSection(
            AppSetting::GROUP_FACILITY,
            $rhuId,
            $validated,
            $request->user()?->id,
        );

        return response()->json($this->payload($rhuId));
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sms_provider' => ['nullable', 'string', 'max:60'],
            'appointment_reminder_hours' => [
                'required', 'integer',
                'between:' . AppSettings::REMINDER_HOURS_MIN . ',' . AppSettings::REMINDER_HOURS_MAX,
            ],
            'queue_alert_ahead' => [
                'required', 'integer',
                'between:' . AppSettings::QUEUE_ALERT_MIN . ',' . AppSettings::QUEUE_ALERT_MAX,
            ],
        ]);

        AppSettings::putSection(
            AppSetting::GROUP_NOTIFICATIONS,
            null,
            $validated,
            $request->user()?->id,
        );

        $rhuId = Rhu::scopeRhuId($request->user(), $request->integer('rhu_id') ?: null);

        return response()->json($this->payload($rhuId));
    }

    public function updateSecurity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'max_login_attempts' => [
                'required', 'integer',
                'between:' . AppSettings::MAX_LOGIN_ATTEMPTS_MIN . ',' . AppSettings::MAX_LOGIN_ATTEMPTS_MAX,
            ],
            'session_timeout_minutes' => [
                'nullable', 'integer',
                'between:' . AppSettings::SESSION_TIMEOUT_MIN . ',' . AppSettings::SESSION_TIMEOUT_MAX,
            ],
        ]);

        AppSettings::putSection(
            AppSetting::GROUP_SECURITY,
            null,
            $validated,
            $request->user()?->id,
        );

        $rhuId = Rhu::scopeRhuId($request->user(), $request->integer('rhu_id') ?: null);

        return response()->json($this->payload($rhuId));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $rhuId): array
    {
        return [
            'rhu_id' => $rhuId,
            'rhu_label' => Rhu::rhuLabel($rhuId),
            'facility' => AppSettings::section(AppSetting::GROUP_FACILITY, $rhuId),
            'notifications' => AppSettings::section(AppSetting::GROUP_NOTIFICATIONS),
            'security' => AppSettings::section(AppSetting::GROUP_SECURITY),
            'meta' => [
                // Server truth, not a browser default. Never the key itself.
                'sms_api_key_configured' => trim((string) config('services.semaphore.api_key')) !== '',

                // Not secret: this appears as the sender on every message a
                // resident receives. Read-only here -- it comes from env.
                'sms_sender_name' => (string) config('services.semaphore.sendername'),
                'sms_provider_actual' => (string) config('services.sms_provider'),

                // What the server really does, so the panel can be honest about
                // the gap between a stored value and enforced behaviour.
                'session_lifetime_minutes_actual' => (int) config('sanctum.expiration'),
                'session_timeout_enforced' => false,
                'max_login_attempts_enforced' => true,
                'sms_settings_enforced' => false,
            ],
        ];
    }
}

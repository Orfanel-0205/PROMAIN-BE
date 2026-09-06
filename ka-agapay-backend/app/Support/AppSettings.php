<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The one place that knows what a settings field is, what type it has, and
 * what it falls back to when nobody has set it.
 *
 * Validation rules in SettingsController reference the bounds declared here so
 * that "what the API accepts" and "what enforcement clamps to" cannot drift
 * apart. That drift is precisely how the old panel ended up offering a
 * Max Login Attempts field between 3 and 10 while the server enforced a
 * hardcoded 5 that no one could see.
 */
final class AppSettings
{
    /** Bounds shared by validation and enforcement. */
    public const MAX_LOGIN_ATTEMPTS_MIN = 3;
    public const MAX_LOGIN_ATTEMPTS_MAX = 10;
    public const MAX_LOGIN_ATTEMPTS_FALLBACK = 5;

    public const SESSION_TIMEOUT_MIN = 10;
    public const SESSION_TIMEOUT_MAX = 480;

    public const REMINDER_HOURS_MIN = 1;
    public const REMINDER_HOURS_MAX = 168;

    public const QUEUE_ALERT_MIN = 1;
    public const QUEUE_ALERT_MAX = 20;

    private const CACHE_TTL_SECONDS = 60;

    /**
     * Field definitions per section.
     *
     * DEFAULTS ARE DELIBERATELY HONEST. Facility fields default to null --
     * "never configured" -- rather than to the plausible-looking strings the
     * old page shipped ("RHU Malasiqui 1", "rhu@malasiqui.gov.ph", and a
     * contact number of "+63 75 XXX XXXX", which read as real configuration on
     * a browser where nobody had configured anything).
     *
     * Where a default IS given it is the value the server genuinely uses:
     * max_login_attempts 5 is the number the rate limiter actually enforced
     * before this table existed.
     *
     * @var array<string, array<string, array{type:string, default:mixed}>>
     */
    private const SCHEMA = [
        AppSetting::GROUP_FACILITY => [
            'facility_name'    => ['type' => 'string', 'default' => null],
            'address'          => ['type' => 'string', 'default' => null],
            'contact_number'   => ['type' => 'string', 'default' => null],
            'email'            => ['type' => 'string', 'default' => null],
            'operating_hours'  => ['type' => 'string', 'default' => null],
        ],

        AppSetting::GROUP_NOTIFICATIONS => [
            // Stored only. Nothing in the sending pipeline reads these yet --
            // see SettingsController for the full note.
            'sms_provider'               => ['type' => 'string', 'default' => null],
            'appointment_reminder_hours' => ['type' => 'int', 'default' => 24],
            'queue_alert_ahead'          => ['type' => 'int', 'default' => 3],
        ],

        AppSetting::GROUP_SECURITY => [
            // Enforced: read by the auth-login rate limiter.
            'max_login_attempts'      => ['type' => 'int', 'default' => self::MAX_LOGIN_ATTEMPTS_FALLBACK],
            // Stored only: Sanctum's token lifetime is a config value, not this.
            'session_timeout_minutes' => ['type' => 'int', 'default' => null],
        ],
    ];

    /** Sections that are municipality-wide rather than per-facility. */
    private const SHARED_GROUPS = [
        AppSetting::GROUP_NOTIFICATIONS,
        AppSetting::GROUP_SECURITY,
    ];

    /** @return array<int, string> */
    public static function groups(): array
    {
        return array_keys(self::SCHEMA);
    }

    public static function isGroup(string $group): bool
    {
        return array_key_exists($group, self::SCHEMA);
    }

    /** @return array<string, array{type:string, default:mixed}> */
    public static function fields(string $group): array
    {
        return self::SCHEMA[$group] ?? [];
    }

    /**
     * Facility settings live per RHU; everything else is shared.
     * Returns the rhu_id to store against.
     */
    public static function storageRhuId(string $group, ?int $rhuId): int
    {
        if (in_array($group, self::SHARED_GROUPS, true)) {
            return AppSetting::SHARED_RHU_ID;
        }

        return Rhu::normalizeRhuId($rhuId) ?? Rhu::DEFAULT_ID;
    }

    /**
     * A section's current values, defaults filled in for anything unset.
     *
     * @return array<string, string|int|bool|null>
     */
    public static function section(string $group, ?int $rhuId = null): array
    {
        $fields = self::fields($group);

        if ($fields === []) {
            return [];
        }

        $values = [];

        foreach ($fields as $key => $definition) {
            $values[$key] = $definition['default'];
        }

        $storageRhuId = self::storageRhuId($group, $rhuId);

        $rows = AppSetting::query()
            ->where('group', $group)
            ->where('rhu_id', $storageRhuId)
            ->get();

        foreach ($rows as $row) {
            if (! array_key_exists($row->key, $values)) {
                continue; // a key removed from the schema; ignore rather than leak it
            }

            $values[$row->key] = $row->typedValue();
        }

        return $values;
    }

    /**
     * Write a section. Only keys declared in the schema are persisted.
     *
     * @param array<string, mixed> $values
     */
    public static function putSection(string $group, ?int $rhuId, array $values, ?int $userId = null): void
    {
        $fields = self::fields($group);
        $storageRhuId = self::storageRhuId($group, $rhuId);

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }

            $type = $fields[$key]['type'];

            AppSetting::updateOrCreate(
                ['group' => $group, 'rhu_id' => $storageRhuId, 'key' => $key],
                [
                    'value' => self::encode($value, $type),
                    'type' => $type,
                    'updated_by' => $userId,
                ],
            );
        }

        self::forgetCache();
    }

    private static function encode(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($type === 'bool') {
            return $value ? '1' : '0';
        }

        if ($type === 'int') {
            return (string) (int) $value;
        }

        $value = trim((string) $value);

        // An emptied text field means "unset", not an empty string, so the UI
        // returns to its honest "not configured" state instead of showing "".
        return $value === '' ? null : $value;
    }

    /**
     * Max failed logins per minute, for the auth-login rate limiter.
     *
     * Deliberately total: authentication must not break because a settings row
     * is missing, malformed, or the table has not been migrated yet. Any
     * problem falls back to the value the limiter used before this was
     * configurable, and the result is always clamped to the same bounds the
     * API validates against.
     */
    public static function maxLoginAttempts(): int
    {
        try {
            $value = Cache::remember(
                'app_settings.security.max_login_attempts',
                self::CACHE_TTL_SECONDS,
                static function (): ?int {
                    $row = AppSetting::query()
                        ->where('group', AppSetting::GROUP_SECURITY)
                        ->where('rhu_id', AppSetting::SHARED_RHU_ID)
                        ->where('key', 'max_login_attempts')
                        ->first();

                    $typed = $row?->typedValue();

                    return is_int($typed) ? $typed : null;
                },
            );
        } catch (Throwable) {
            // No table, no cache store, no database -- never a locked door.
            return self::MAX_LOGIN_ATTEMPTS_FALLBACK;
        }

        if (! is_int($value)) {
            return self::MAX_LOGIN_ATTEMPTS_FALLBACK;
        }

        return max(
            self::MAX_LOGIN_ATTEMPTS_MIN,
            min(self::MAX_LOGIN_ATTEMPTS_MAX, $value),
        );
    }

    public static function forgetCache(): void
    {
        try {
            Cache::forget('app_settings.security.max_login_attempts');
        } catch (Throwable) {
            // Cache unavailable; the TTL above bounds any staleness anyway.
        }
    }
}

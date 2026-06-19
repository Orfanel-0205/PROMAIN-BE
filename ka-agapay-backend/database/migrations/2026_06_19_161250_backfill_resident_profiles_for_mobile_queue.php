<?php
// database/migrations/2026_06_20_000003_backfill_resident_profiles_for_mobile_queue.php
//
// Fixes:
// - "No resident profile linked to your account."
// - Creates missing resident_profiles rows for existing users.
// - Does not depend on queue_tickets.user_id because your queue_tickets table
//   does NOT have a user_id column.
// - Backfills queue_tickets.resident_profile_id through appointments.user_id
//   when appointment_id is available.
// - Safe for different column versions of resident_profiles.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasTable('resident_profiles')) {
            return;
        }

        $userColumns = Schema::getColumnListing('users');
        $profileColumns = Schema::getColumnListing('resident_profiles');

        if (!in_array('user_id', $profileColumns, true)) {
            return;
        }

        $userIdColumn = in_array('user_id', $userColumns, true) ? 'user_id' : 'id';

        if (!in_array($userIdColumn, $userColumns, true)) {
            return;
        }

        $users = DB::table('users')->get();

        foreach ($users as $user) {
            $userId = (int) ($user->{$userIdColumn} ?? 0);

            if ($userId <= 0) {
                continue;
            }

            $alreadyHasProfile = DB::table('resident_profiles')
                ->where('user_id', $userId)
                ->exists();

            if ($alreadyHasProfile) {
                continue;
            }

            $profile = [
                'user_id' => $userId,
            ];

            $this->putIfColumnAndValueExist($profile, $profileColumns, 'first_name', $user->first_name ?? null);
            $this->putIfColumnAndValueExist($profile, $profileColumns, 'middle_name', $user->middle_name ?? null);
            $this->putIfColumnAndValueExist($profile, $profileColumns, 'last_name', $user->last_name ?? null);
            $this->putIfColumnAndValueExist($profile, $profileColumns, 'suffix', $user->suffix ?? null);

            $barangayId = $this->resolveBarangayId($user, $userColumns);

            $this->putIfColumnAndValueExist($profile, $profileColumns, 'barangay_id', $barangayId);

            $birthday =
                $user->birth_date
                ?? $user->birthdate
                ?? $user->birthday
                ?? $user->date_of_birth
                ?? null;

            $this->putIfColumnAndValueExist($profile, $profileColumns, 'birth_date', $birthday);
            $this->putIfColumnAndValueExist($profile, $profileColumns, 'birthdate', $birthday);
            $this->putIfColumnAndValueExist($profile, $profileColumns, 'date_of_birth', $birthday);

            $sex = $user->sex ?? $user->gender ?? null;

            $this->putIfColumnAndValueExist($profile, $profileColumns, 'sex', $sex);
            $this->putIfColumnAndValueExist($profile, $profileColumns, 'gender', $sex);

            $mobile =
                $user->mobile_number
                ?? $user->phone
                ?? $user->contact_number
                ?? null;

            $this->putIfColumnAndValueExist($profile, $profileColumns, 'mobile_number', $mobile);
            $this->putIfColumnAndValueExist($profile, $profileColumns, 'contact_number', $mobile);
            $this->putIfColumnAndValueExist($profile, $profileColumns, 'phone_number', $mobile);

            $this->putIfColumnAndValueExist($profile, $profileColumns, 'address', $user->address ?? null);

            if (in_array('is_senior', $profileColumns, true)) {
                $profile['is_senior'] = false;
            }

            if (in_array('is_pwd', $profileColumns, true)) {
                $profile['is_pwd'] = false;
            }

            if (in_array('is_pregnant', $profileColumns, true)) {
                $profile['is_pregnant'] = false;
            }

            if (in_array('created_at', $profileColumns, true)) {
                $profile['created_at'] = now();
            }

            if (in_array('updated_at', $profileColumns, true)) {
                $profile['updated_at'] = now();
            }

            try {
                DB::table('resident_profiles')->insert($profile);
            } catch (\Throwable $e) {
                logger()->warning('[Resident profile backfill] Could not create resident profile.', [
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->backfillQueueTicketsFromAppointments();
    }

    public function down(): void
    {
        // Intentionally no destructive rollback.
        // These resident profiles may become used by queue, appointments,
        // consultations, prescriptions, or records.
    }

    private function putIfColumnAndValueExist(
        array &$target,
        array $targetColumns,
        string $targetColumn,
        mixed $value
    ): void {
        if (!in_array($targetColumn, $targetColumns, true)) {
            return;
        }

        if ($value === null || $value === '') {
            return;
        }

        $target[$targetColumn] = $value;
    }

    private function resolveBarangayId(object $user, array $userColumns): ?int
    {
        if (in_array('barangay_id', $userColumns, true) && !empty($user->barangay_id)) {
            return (int) $user->barangay_id;
        }

        if (in_array('rhu_id', $userColumns, true) && !empty($user->rhu_id)) {
            return (int) $user->rhu_id;
        }

        if (
            !Schema::hasTable('barangays') ||
            !in_array('barangay', $userColumns, true) ||
            empty($user->barangay)
        ) {
            return null;
        }

        $barangayName = trim((string) $user->barangay);

        if ($barangayName === '') {
            return null;
        }

        $barangayColumns = Schema::getColumnListing('barangays');

        $query = DB::table('barangays');

        $hasWhere = false;

        if (in_array('name', $barangayColumns, true)) {
            $query->orWhere('name', $barangayName);
            $hasWhere = true;
        }

        if (in_array('barangay_name', $barangayColumns, true)) {
            $query->orWhere('barangay_name', $barangayName);
            $hasWhere = true;
        }

        if (!$hasWhere) {
            return null;
        }

        $id = $query->value('barangay_id');

        return $id ? (int) $id : null;
    }

    private function backfillQueueTicketsFromAppointments(): void
    {
        if (
            !Schema::hasTable('queue_tickets') ||
            !Schema::hasTable('appointments') ||
            !Schema::hasTable('resident_profiles')
        ) {
            return;
        }

        $queueColumns = Schema::getColumnListing('queue_tickets');
        $appointmentColumns = Schema::getColumnListing('appointments');
        $profileColumns = Schema::getColumnListing('resident_profiles');

        if (
            !in_array('resident_profile_id', $queueColumns, true) ||
            !in_array('appointment_id', $queueColumns, true) ||
            !in_array('id', $appointmentColumns, true) ||
            !in_array('user_id', $appointmentColumns, true) ||
            !in_array('id', $profileColumns, true) ||
            !in_array('user_id', $profileColumns, true)
        ) {
            return;
        }

        try {
            DB::statement("
                UPDATE queue_tickets qt
                SET resident_profile_id = rp.id
                FROM appointments a
                INNER JOIN resident_profiles rp
                    ON rp.user_id = a.user_id
                WHERE qt.appointment_id = a.id
                  AND qt.resident_profile_id IS NULL
            ");
        } catch (\Throwable $e) {
            logger()->warning('[Queue ticket backfill] Could not backfill from appointments.', [
                'error' => $e->getMessage(),
            ]);
        }
    }
};
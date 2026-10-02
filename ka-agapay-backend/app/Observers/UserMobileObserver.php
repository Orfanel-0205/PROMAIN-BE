<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Notification\AccountSmsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Notices when an account's mobile number changes, however it changes.
 *
 * An SMS code proves the person has the account holder's phone only while
 * the number on the account is the account holder's. Two self-service
 * endpoints (PUT /me and PUT /profile) and the admin Users page can all
 * change it, so this sits on the model rather than in each of them -- a new
 * endpoint added later is covered without anyone remembering to.
 *
 * On a change it records when (users.mobile_changed_at, which pauses viewing
 * API keys for a day) and texts the OLD number, so the real owner finds out
 * even if someone else is now receiving the codes.
 */
class UserMobileObserver
{
    public function updating(User $user): void
    {
        if (!$user->isDirty('mobile_number') || !Schema::hasColumn('users', 'mobile_changed_at')) {
            return;
        }

        $before = $this->digits($user->getOriginal('mobile_number'));
        $after = $this->digits($user->mobile_number);

        // Setting a number for the first time is not a change of owner.
        if ($before === '' || $before === $after) {
            return;
        }

        $user->mobile_changed_at = now();
    }

    public function updated(User $user): void
    {
        if (!$user->wasChanged('mobile_number')) {
            return;
        }

        // Still the pre-save value here: originals are synced after "saved".
        $old = $this->digits($user->getOriginal('mobile_number'));

        if ($old === '' || $old === $this->digits($user->mobile_number)) {
            return;
        }

        try {
            app(AccountSmsService::class)->sendMobileChangedNotice($user, $old);
        } catch (\Throwable $e) {
            // A failed notice must never undo or block the change itself.
            Log::warning('[UserMobileObserver] Could not text the old number', [
                'user_id' => $user->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function digits(mixed $value): string
    {
        return preg_replace('/\D/', '', (string) $value) ?? '';
    }
}

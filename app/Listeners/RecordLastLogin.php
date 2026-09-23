<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Бележи кога човек последен пат се најавил — за таблото на админот.
 */
class RecordLastLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        // saveQuietly: најавата не смее да предизвика други настани врз корисникот.
        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
    }
}

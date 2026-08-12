<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/**
 * Remembers which company a user last had open, so an accountant with
 * several assigned companies lands back where they were instead of on a
 * chooser every time. See App\Livewire\Dashboard.
 *
 * Deliberately session-scoped, not persisted: "where I was last time" is a
 * convenience, not a setting, and it should reset with a new session.
 */
class CurrentCompany
{
    public static function sessionKey(int $userId): string
    {
        return "last_company.{$userId}";
    }

    public static function remember(Company $company): void
    {
        session([self::sessionKey((int) auth()->id()) => $company->id]);
    }

    public static function lastFor(User $user): ?int
    {
        $id = session(self::sessionKey($user->id));

        return is_int($id) ? $id : null;
    }
}

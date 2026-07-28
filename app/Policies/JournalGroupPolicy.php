<?php

namespace App\Policies;

use App\Models\JournalGroup;
use App\Models\User;

class JournalGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JournalGroup $journalGroup): bool
    {
        return $user->visibleCompanies()->whereKey($journalGroup->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant']);
    }

    public function update(User $user, JournalGroup $journalGroup): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($journalGroup->company_id)->exists();
    }

    public function delete(User $user, JournalGroup $journalGroup): bool
    {
        return $this->update($user, $journalGroup);
    }
}

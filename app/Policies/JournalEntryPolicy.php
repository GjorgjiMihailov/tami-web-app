<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, JournalEntry $journalEntry): bool
    {
        return $user->visibleCompanies()->whereKey($journalEntry->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant']);
    }

    public function update(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($journalEntry->company_id)->exists();
    }

    public function delete(User $user, JournalEntry $journalEntry): bool
    {
        return $this->update($user, $journalEntry);
    }
}

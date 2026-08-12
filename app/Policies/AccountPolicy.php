<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    // The bookkeeping screens are admin/accountant only — see
    // App\Http\Middleware\EnsureAccountingAccess. A client's own company
    // being visible to them is not, by itself, permission to read its books.
    public function view(User $user, Account $account): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($account->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant']);
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($account->company_id)->exists();
    }
}

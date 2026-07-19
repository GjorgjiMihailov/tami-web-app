<?php

namespace App\Policies;

use App\Models\Item;
use App\Models\User;

class ItemPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Item $item): bool
    {
        return $user->visibleCompanies()->whereKey($item->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client']);
    }

    public function update(User $user, Item $item): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client'])
            && $user->visibleCompanies()->whereKey($item->company_id)->exists();
    }
}

<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;

class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        return $user->visibleCompanies()->whereKey($warehouse->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'internal_client', 'freelancer_client']);
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'internal_client', 'freelancer_client'])
            && $user->visibleCompanies()->whereKey($warehouse->company_id)->exists();
    }
}

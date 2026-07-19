<?php

namespace App\Policies;

use App\Models\Partner;
use App\Models\User;

class PartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Partner $partner): bool
    {
        return $user->visibleCompanies()->whereKey($partner->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant']);
    }

    public function update(User $user, Partner $partner): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($partner->company_id)->exists();
    }
}

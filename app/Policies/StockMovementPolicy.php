<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StockMovement $stockMovement): bool
    {
        return $user->visibleCompanies()->whereKey($stockMovement->item->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client']);
    }
}

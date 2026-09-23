<?php

namespace App\Policies;

use App\Models\PurchaseInvoice;
use App\Models\User;

class PurchaseInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PurchaseInvoice $purchaseInvoice): bool
    {
        return $user->visibleCompanies()->whereKey($purchaseInvoice->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'internal_client', 'freelancer_client']);
    }

    public function update(User $user, PurchaseInvoice $purchaseInvoice): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'internal_client', 'freelancer_client'])
            && $user->visibleCompanies()->whereKey($purchaseInvoice->company_id)->exists();
    }
}

<?php

namespace App\Policies;

use App\Models\ProformaInvoice;
use App\Models\User;

class ProformaInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProformaInvoice $proforma): bool
    {
        return $user->visibleCompanies()->whereKey($proforma->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'internal_client', 'freelancer_client']);
    }

    public function update(User $user, ProformaInvoice $proforma): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'internal_client', 'freelancer_client'])
            && $user->visibleCompanies()->whereKey($proforma->company_id)->exists();
    }
}

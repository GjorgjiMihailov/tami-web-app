<?php

namespace App\Policies;

use App\Models\Form743;
use App\Models\User;

class Form743Policy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Form743 $form743): bool
    {
        return $user->visibleCompanies()->whereKey($form743->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client']);
    }

    /**
     * Обработката на образецот значи внесување на пријавата во е-ПДД, а тоа го
     * прави канцеларијата. Клиентот го качува фајлот и толку.
     */
    public function update(User $user, Form743 $form743): bool
    {
        return $user->hasAnyRole(['admin', 'accountant'])
            && $user->visibleCompanies()->whereKey($form743->company_id)->exists();
    }
}

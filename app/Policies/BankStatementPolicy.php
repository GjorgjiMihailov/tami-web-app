<?php

namespace App\Policies;

use App\Models\BankStatement;
use App\Models\User;

class BankStatementPolicy
{
    /**
     * DocumentController::__invoke го проверува правото `view` врз записот
     * на кој е закачен документот. Без оваа политика проверката секогаш
     * паѓаше, па ниту админ не можеше да преземе фајл од извод.
     */
    public function view(User $user, BankStatement $statement): bool
    {
        return $user->visibleCompanies()->whereKey($statement->company_id)->exists();
    }
}

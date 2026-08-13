<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->visibleCompanies()->whereKey($employee->company_id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client']);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasAnyRole(['admin', 'accountant', 'client'])
            && $user->visibleCompanies()->whereKey($employee->company_id)->exists();
    }
}

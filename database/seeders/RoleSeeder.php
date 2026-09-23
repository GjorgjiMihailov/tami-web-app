<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['admin', 'accountant', 'internal_client', 'freelancer_client'] as $role) {
            Role::findOrCreate($role);
        }
    }
}

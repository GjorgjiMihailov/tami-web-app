<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $companyA = Company::factory()->create(['name' => 'Demo Firm Alpha DOO']);
        $companyB = Company::factory()->create(['name' => 'Demo Firm Beta DOO']);

        $admin = User::factory()->create([
            'name' => 'Demo Admin',
            'email' => 'admin@tami.test',
            'password' => bcrypt('password'),
        ]);
        $admin->assignRole('admin');

        $accountant = User::factory()->create([
            'name' => 'Demo Accountant',
            'email' => 'accountant@tami.test',
            'password' => bcrypt('password'),
        ]);
        $accountant->assignRole('accountant');
        $accountant->assignedCompanies()->attach([$companyA->id, $companyB->id]);

        $client = User::factory()->create([
            'name' => 'Demo Client',
            'email' => 'client@tami.test',
            'password' => bcrypt('password'),
            'company_id' => $companyA->id,
        ]);
        $client->assignRole('client');
    }
}

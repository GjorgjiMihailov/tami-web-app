<?php

namespace Tests\Feature;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_three_core_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $this->assertTrue(Role::where('name', 'admin')->exists());
        $this->assertTrue(Role::where('name', 'accountant')->exists());
        $this->assertTrue(Role::where('name', 'client')->exists());
    }
}

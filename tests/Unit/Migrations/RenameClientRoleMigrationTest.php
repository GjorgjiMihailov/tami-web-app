<?php

namespace Tests\Unit\Migrations;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RenameClientRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RefreshDatabase веќе ги пушти сите миграции (вклучувајќи ја оваа) на
     * празна база, па 'client' не постои за да се преименува таму. За да се
     * докаже дека самата UPDATE-логика работи врз ВИСТИНСКИ ред (како на
     * продукција), тестот рачно го враќа редот во старата состојба и повторно
     * ја повикува migration-класата.
     */
    public function test_a_user_on_the_old_client_role_keeps_their_role_through_the_rename(): void
    {
        // Seed to create roles as they would exist in production before the migration
        $this->seed(RoleSeeder::class);

        DB::table('roles')->where('name', 'internal_client')->update(['name' => 'client']);
        DB::table('roles')->where('name', 'freelancer_client')->delete();

        $user = User::factory()->create();
        $user->assignRole('client');
        $this->assertTrue($user->fresh()->hasRole('client'));

        $migration = require database_path('migrations/2026_09_23_120000_rename_client_role_and_add_freelancer_client_role.php');
        $migration->up();

        // Мора да прочита свежо: Spatie ги кешира улогите на моделот во меморија.
        $fresh = User::find($user->id);
        $this->assertTrue($fresh->hasRole('internal_client'));
        $this->assertFalse($fresh->hasRole('client'));
        $this->assertTrue(Role::where('name', 'freelancer_client')->exists());
    }
}

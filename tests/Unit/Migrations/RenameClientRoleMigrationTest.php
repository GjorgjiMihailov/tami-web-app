<?php

namespace Tests\Unit\Migrations;

use App\Models\Company;
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

    private const MIGRATION = 'migrations/2026_09_23_120000_rename_client_role_and_add_freelancer_client_role.php';

    /** Враќа ролите во состојбата пред миграцијата и создава по еден корисник на физичко и правно лице. */
    private function preMigrationUsers(): array
    {
        $this->seed(RoleSeeder::class);
        DB::table('roles')->where('name', 'internal_client')->update(['name' => 'client']);
        DB::table('model_has_roles')->where('role_id', Role::where('name', 'freelancer_client')->value('id'))->delete();
        DB::table('roles')->where('name', 'freelancer_client')->delete();

        $individual = User::factory()->create(['company_id' => Company::factory()->create(['type' => 'individual'])->id]);
        $legal = User::factory()->create(['company_id' => Company::factory()->create(['type' => 'legal'])->id]);
        $individual->assignRole('client');
        $legal->assignRole('client');

        return [$individual, $legal];
    }

    public function test_users_of_individual_companies_move_to_freelancer_client_and_legal_ones_to_internal_client(): void
    {
        [$individual, $legal] = $this->preMigrationUsers();

        (require database_path(self::MIGRATION))->up();

        $this->assertTrue(User::find($individual->id)->hasRole('freelancer_client'));
        $this->assertFalse(User::find($individual->id)->hasRole('internal_client'));
        $this->assertTrue(User::find($legal->id)->hasRole('internal_client'));
        $this->assertFalse(User::find($legal->id)->hasRole('freelancer_client'));
    }

    public function test_up_is_idempotent(): void
    {
        [$individual, $legal] = $this->preMigrationUsers();
        $migration = require database_path(self::MIGRATION);

        $migration->up();
        $before = DB::table('model_has_roles')->orderBy('model_id')->get()->toArray();
        $migration->up();

        $this->assertEquals($before, DB::table('model_has_roles')->orderBy('model_id')->get()->toArray());
        $this->assertSame(1, DB::table('roles')->where('name', 'freelancer_client')->count());
        $this->assertTrue(User::find($individual->id)->hasRole('freelancer_client'));
    }

    public function test_down_returns_both_users_to_the_client_role_without_leaving_anyone_roleless(): void
    {
        [$individual, $legal] = $this->preMigrationUsers();
        $migration = require database_path(self::MIGRATION);

        $migration->up();
        $migration->down();

        $this->assertTrue(User::find($individual->id)->hasRole('client'));
        $this->assertTrue(User::find($legal->id)->hasRole('client'));
        $this->assertFalse(Role::where('name', 'freelancer_client')->exists());
        $this->assertSame(2, DB::table('model_has_roles')->count());
    }

    public function test_up_is_a_no_op_on_an_empty_database(): void
    {
        DB::table('model_has_roles')->delete();
        DB::table('roles')->delete();

        (require database_path(self::MIGRATION))->up();

        $this->assertSame(0, DB::table('model_has_roles')->count());
    }
}

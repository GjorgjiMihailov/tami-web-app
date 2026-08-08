<?php

namespace Tests\Feature;

use App\Livewire\Accounting\JournalGroupIndex;
use App\Models\Company;
use App\Models\JournalGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalGroupIndexTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_lists_the_companys_journal_groups(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalGroup::factory()->for($company)->create(['code' => '10', 'name' => 'Изводи-Денарски']);

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->assertSee('10')
            ->assertSee('Изводи-Денарски');
    }

    public function test_admin_can_add_a_journal_group(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->set('newCode', '20')
            ->set('newName', 'Купувачи')
            ->call('addGroup')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('journal_groups', ['company_id' => $company->id, 'code' => '20', 'name' => 'Купувачи']);
    }

    public function test_a_duplicate_code_for_the_same_company_is_rejected(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalGroup::factory()->for($company)->create(['code' => '20']);

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->set('newCode', '20')
            ->set('newName', 'Друга група')
            ->call('addGroup')
            ->assertHasErrors('newCode');

        $this->assertDatabaseCount('journal_groups', 1);
    }

    public function test_client_cannot_add_a_journal_group(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');

        $this->actingAs($client);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->set('newCode', '20')
            ->set('newName', 'Купувачи')
            ->call('addGroup')
            ->assertForbidden();
    }

    public function test_deleting_an_unused_group_removes_it(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $group = JournalGroup::factory()->for($company)->create();

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->call('deleteGroup', $group->id);

        $this->assertDatabaseCount('journal_groups', 0);
    }

    public function test_deleting_a_group_with_entries_is_blocked(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $group = JournalGroup::factory()->for($company)->create();
        \App\Models\JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'created_by' => $admin->id]);

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->call('deleteGroup', $group->id)
            ->assertHasErrors('delete');

        $this->assertDatabaseCount('journal_groups', 1);
    }

    public function test_admin_can_rename_a_journal_group(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $group = JournalGroup::factory()->for($company)->create(['code' => '10', 'name' => 'Стар назив']);

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->call('startEditingGroup', $group->id, 'Стар назив')
            ->set('editName', 'Нов назив')
            ->call('updateGroupName', $group->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('journal_groups', ['id' => $group->id, 'code' => '10', 'name' => 'Нов назив']);
    }

    public function test_client_cannot_rename_a_journal_group(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $group = JournalGroup::factory()->for($company)->create(['name' => 'Оригинален назив']);

        $this->actingAs($client);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->set('editName', 'Обид за измена')
            ->call('updateGroupName', $group->id)
            ->assertForbidden();

        $this->assertDatabaseHas('journal_groups', ['id' => $group->id, 'name' => 'Оригинален назив']);
    }

    public function test_the_code_cannot_be_changed_via_the_edit_path(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $group = JournalGroup::factory()->for($company)->create(['code' => '10', 'name' => 'Назив']);

        $this->actingAs($admin);

        // updateGroupName() only ever validates/writes the 'editName' Livewire
        // property to the group's 'name' column -- there is no public method
        // or exposed property that lets a client-side request influence
        // 'code', matching the design intent that code is immutable once set.
        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->call('startEditingGroup', $group->id, 'Назив')
            ->set('editName', 'Нов назив')
            ->call('updateGroupName', $group->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('journal_groups', ['id' => $group->id, 'code' => '10']);
    }

    public function test_the_journal_group_table_has_the_header_and_hover_treatment(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        JournalGroup::factory()->for($company)->create(['code' => '10', 'name' => 'Test Group']);

        $this->actingAs($admin);

        Livewire::test(JournalGroupIndex::class, ['company' => $company])
            ->assertSee('bg-gray-50', false)
            ->assertSee('hover:bg-orange-50', false);
    }
}

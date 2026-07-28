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
}

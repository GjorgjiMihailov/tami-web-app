<?php

namespace Tests\Feature;

use App\Livewire\DocumentManager;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalEntryDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_a_client_cannot_upload_a_document_to_a_journal_entry(): void
    {
        $company = Company::factory()->create();
        $entry = JournalEntry::factory()->for($company)->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        Livewire::test(DocumentManager::class, ['documentable' => $entry])
            ->call('upload')
            ->assertForbidden();
    }

    public function test_the_edit_page_for_an_existing_entry_renders_the_document_manager(): void
    {
        $company = Company::factory()->create();
        $entry = JournalEntry::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('accounting.journal-entries.edit', [$company, $entry]))
            ->assertOk()
            ->assertSeeLivewire('document-manager');
    }

    public function test_the_new_entry_page_does_not_render_the_document_manager(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('accounting.journal-entries.create', $company))
            ->assertOk()
            ->assertDontSeeLivewire('document-manager');
    }
}

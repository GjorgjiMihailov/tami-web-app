<?php

namespace Tests\Feature\Posting;

use App\Livewire\OfficePostingSchemes;
use App\Models\Company;
use App\Models\User;
use App\Services\Posting\PostingSchemes;
use App\Services\Posting\PostingSchemeSets;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OfficePostingSchemesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('internal_client');
    }

    private function accountant(): User
    {
        $user = User::factory()->create();
        $user->assignRole('accountant');

        return $user;
    }

    public function test_the_page_opens_for_an_accountant_and_an_admin_but_not_a_client(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $client = User::factory()->create();
        $client->assignRole('internal_client');

        $this->actingAs($this->accountant())->get(route('office.posting-schemes'))->assertOk();
        $this->actingAs($admin)->get(route('office.posting-schemes'))->assertOk();
        $this->actingAs($client)->get(route('office.posting-schemes'))->assertForbidden();
    }

    public function test_it_shows_which_documents_have_a_personal_suggestion(): void
    {
        $accountant = $this->accountant();
        PostingSchemeSets::remember($accountant, PostingSchemes::for(Company::factory()->create(), PostingDocType::SALES_INVOICE));

        Livewire::actingAs($accountant)->test(OfficePostingSchemes::class)
            ->assertSee('Излезна фактура')
            ->assertSee('Личен предлог')
            ->assertSee('Стандарден');
    }

    public function test_a_suggestion_can_be_reset_to_the_standard(): void
    {
        $accountant = $this->accountant();
        PostingSchemeSets::remember($accountant, PostingSchemes::for(Company::factory()->create(), PostingDocType::SALES_INVOICE));

        Livewire::actingAs($accountant)->test(OfficePostingSchemes::class)->call('forgetSet', 'sales_invoice');

        $this->assertFalse(PostingSchemeSets::has($accountant, PostingDocType::SALES_INVOICE));
    }

    public function test_an_unknown_type_is_a_404(): void
    {
        Livewire::actingAs($this->accountant())->test(OfficePostingSchemes::class)->call('forgetSet', 'nesto')->assertNotFound();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IncomingEfakturaIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    public function test_page_lists_incoming_documents(): void
    {
        $company = Company::factory()->create(['efaktura_credential_mode' => Company::EFAKTURA_MODE_OWN]);
        $document = IncomingEfakturaDocument::factory()->for($company)->create(['seller_name' => 'Тест Добавувач ДООЕЛ']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('incoming-efaktura.index', $company))
            ->assertOk()
            ->assertSee('Тест Добавувач ДООЕЛ');
    }

    public function test_page_shows_empty_state_with_no_documents(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('incoming-efaktura.index', $company))
            ->assertOk()
            ->assertSee('Нема пронајдени влезни е-фактури.');
    }
}

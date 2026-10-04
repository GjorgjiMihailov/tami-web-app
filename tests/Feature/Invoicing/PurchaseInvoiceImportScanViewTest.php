<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseInvoiceImportScanViewTest extends TestCase
{
    use RefreshDatabase;

    private function form(bool $withKey, bool $admin = true)
    {
        Role::findOrCreate('admin');
        config(['services.anthropic.key' => $withKey ? 'test-key' : '']);
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        if ($admin) {
            $user->assignRole('admin');
        }
        $this->actingAs($user);

        return Livewire::test(PurchaseInvoiceForm::class, ['company' => $company]);
    }

    public function test_the_three_upload_slots_show_only_when_import_is_ticked_and_scanning_is_available(): void
    {
        $this->form(true)
            ->assertDontSee('Царинска декларација (ЕЦД)')
            ->set('isImport', true)
            ->assertSee('Фактура од добавувач')
            ->assertSee('Царинска декларација (ЕЦД)')
            ->assertSee('Шпедитерска фактура')
            ->assertSee('Прочитај ги документите');
    }

    public function test_no_upload_slots_without_a_key(): void
    {
        $this->form(false)->set('isImport', true)->assertDontSee('Прочитај ги документите');
    }

    public function test_the_regular_scan_card_is_hidden_while_import_is_ticked(): void
    {
        $this->form(true)
            ->assertSee('Прикачи скенирана фактура')
            ->set('isImport', true)
            ->assertDontSee('Прикачи скенирана фактура');
    }

    public function test_scan_warnings_are_listed(): void
    {
        $this->form(true)
            ->set('isImport', true)
            ->set('importScanWarnings', ['Тест предупредување број еден.'])
            ->assertSee('Тест предупредување број еден.');
    }

    public function test_the_bulk_item_button_shows_when_a_line_has_a_description_but_no_item(): void
    {
        $this->form(true)
            ->set('lines.0.description', 'Нешто')
            ->assertSee('Внеси ги сите непознати како артикли');
    }
}

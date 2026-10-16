<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementBook;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use App\Support\Bank\LineDirection;
use App\Support\Bank\LineKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Партнерот на ставка од извод се бира со пишување и автоматско довршување
 * (како контото) — без паѓачки список со сите партнери.
 */
class BankStatementBookPartnerPickerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private BankStatement $statement;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        $this->company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->statement = BankStatement::factory()->for($this->company)->create(['statement_date' => '2026-03-05']);
        BankStatementLine::factory()->for($this->statement)->create([
            'line_date' => '2026-03-05', 'direction' => LineDirection::IN, 'amount' => '500.00', 'kind' => LineKind::ACCOUNT,
        ]);
    }

    private function screen()
    {
        return Livewire::test(BankStatementBook::class, ['company' => $this->company, 'statement' => $this->statement]);
    }

    public function test_the_table_partner_cell_is_a_typeahead_not_a_select(): void
    {
        Partner::factory()->for($this->company)->create(['name' => 'Делта Лимитед']);

        $this->screen()
            ->assertSeeHtml('journalEntryPicker(jepPartners')
            ->assertSeeHtml('data-wire-model="lines.0.partner_id"')
            ->assertDontSeeHtml('<select wire:model.live="lines.0.partner_id"');
    }

    public function test_the_company_partners_are_offered_to_the_picker_and_no_others(): void
    {
        Partner::factory()->for($this->company)->create(['name' => 'Делта Лимитед']);
        Partner::factory()->create(['name' => 'Туѓа Фирма']);

        $html = $this->screen()->html();

        $this->assertStringContainsString('jepPartners', $html);
        $this->assertStringContainsString('Делта Лимитед', $html);
        $this->assertStringNotContainsString('Туѓа Фирма', $html);
    }

    public function test_an_already_chosen_partner_shows_its_name_as_the_starting_text(): void
    {
        $partner = Partner::factory()->for($this->company)->create(['name' => 'Избрана ДООЕЛ']);
        $this->statement->lines()->first()->update(['partner_id' => $partner->id]);

        $this->screen()->assertSeeHtml('data-label="Избрана ДООЕЛ"');
    }

    public function test_choosing_a_partner_through_the_picker_path_saves_it_on_the_line(): void
    {
        $partner = Partner::factory()->for($this->company)->create(['name' => 'Делта Лимитед']);

        // Изборникот го повикува $wire.set('lines.0.partner_id', id) — истата патека како порано.
        $this->screen()->set('lines.0.partner_id', $partner->id)->assertSet('lines.0.partner_id', $partner->id);
    }

    public function test_a_partner_of_another_company_is_still_refused_when_saving(): void
    {
        $foreign = Partner::factory()->create(['name' => 'Туѓа Фирма']);

        $this->screen()
            ->set('lines.0.partner_id', $foreign->id)
            ->call('save')
            ->assertHasErrors(['lines.0.partner_id']);
    }
}

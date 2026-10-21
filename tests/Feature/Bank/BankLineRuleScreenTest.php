<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankLineRuleIndex;
use App\Models\Account;
use App\Models\BankLineRule;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BankLineRuleScreenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
        $this->company = Company::factory()->create();
    }

    private function konto(string $code = '7400', ?Company $company = null): Account
    {
        return Account::where('company_id', ($company ?? $this->company)->id)->where('code', $code)->firstOrFail();
    }

    private function rule(array $attributes = [], ?Company $company = null): BankLineRule
    {
        return BankLineRule::create(array_merge([
            'company_id' => ($company ?? $this->company)->id,
            'account_key' => '100000000000002', 'direction' => 'out',
            'pattern' => 'mesecna provizija za mesec #', 'example_text' => 'Mesecna provizija za mesec 02/2026',
            'name_seen' => 'KOMERCIJALNA BANKA', 'account_id' => $this->konto()->id, 'times_used' => 3,
        ], $attributes));
    }

    public function test_the_screen_lists_the_rules_of_the_company(): void
    {
        $this->rule();
        $other = Company::factory()->create();
        $this->rule(['name_seen' => 'DRUGA FIRMA PARTNER'], $other);

        $this->get(route('bank-statements.rules', $this->company))
            ->assertOk()
            ->assertSee('mesecna provizija za mesec #')
            ->assertSee('KOMERCIJALNA BANKA')
            ->assertDontSee('DRUGA FIRMA PARTNER');
    }

    public function test_the_bank_documents_screen_links_to_it(): void
    {
        $this->get(route('bank-statements.index', $this->company))
            ->assertOk()
            ->assertSee(route('bank-statements.rules', $this->company), false);
    }

    public function test_search_narrows_the_list(): void
    {
        $this->rule();
        $this->rule(['pattern' => 'vrakane na sredstva', 'name_seen' => 'GORGI MIHAILOV']);

        Livewire::test(BankLineRuleIndex::class, ['company' => $this->company])
            ->set('search', 'gorgi')
            ->assertSee('vrakane na sredstva')
            ->assertDontSee('mesecna provizija');
    }

    public function test_changing_the_account_saves_it_at_once(): void
    {
        $rule = $this->rule();
        $other = $this->konto('7410');

        Livewire::test(BankLineRuleIndex::class, ['company' => $this->company])
            ->set("accountIds.{$rule->id}", $other->id)
            ->assertHasNoErrors();

        $this->assertSame($other->id, $rule->fresh()->account_id);
    }

    public function test_an_account_of_another_company_or_an_inactive_one_is_refused(): void
    {
        $rule = $this->rule();
        $other = Company::factory()->create();
        $foreign = $this->konto('7410', $other);

        Livewire::test(BankLineRuleIndex::class, ['company' => $this->company])
            ->set("accountIds.{$rule->id}", $foreign->id)
            ->assertHasErrors("accountIds.{$rule->id}");

        $this->assertSame($this->konto()->id, $rule->fresh()->account_id);

        $inactive = $this->konto('7410');
        $inactive->update(['is_active' => false]);

        Livewire::test(BankLineRuleIndex::class, ['company' => $this->company])
            ->set("accountIds.{$rule->id}", $inactive->id)
            ->assertHasErrors("accountIds.{$rule->id}");

        $this->assertSame($this->konto()->id, $rule->fresh()->account_id);
    }

    public function test_a_rule_of_another_company_cannot_be_changed_or_deleted(): void
    {
        $other = Company::factory()->create();
        $foreign = $this->rule([], $other);

        Livewire::test(BankLineRuleIndex::class, ['company' => $this->company])
            ->call('deleteRule', $foreign->id);

        $this->assertNotNull($foreign->fresh());

        $this->expectException(ModelNotFoundException::class);
        Livewire::test(BankLineRuleIndex::class, ['company' => $this->company])
            ->set("accountIds.{$foreign->id}", $this->konto('7410')->id);
    }

    public function test_deleting_a_rule_removes_it(): void
    {
        $rule = $this->rule();

        Livewire::test(BankLineRuleIndex::class, ['company' => $this->company])
            ->call('deleteRule', $rule->id);

        $this->assertNull($rule->fresh());
    }

    public function test_a_client_cannot_open_the_screen(): void
    {
        $client = User::factory()->create();
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get(route('bank-statements.rules', $this->company))->assertForbidden();
    }
}

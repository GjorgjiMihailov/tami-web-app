<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\BankStatementIndex;
use App\Models\BankStatement;
use App\Models\Company;
use App\Models\User;
use App\Support\BankStatementKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ForeignStatementUploadTest extends TestCase
{
    use RefreshDatabase;

    private function upload(string $kind, string $currency)
    {
        Storage::fake('google');
        Role::findOrCreate('admin');
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $company = Company::factory()->create(['type' => 'legal']);

        $component = Livewire::test(BankStatementIndex::class, ['company' => $company])
            ->set('bank', 'Комерцијална банка АД Скопје')
            ->set('account', '300000000000001')
            ->set('kind', $kind)
            ->set('currency', $currency)
            ->set('number', '3')
            ->set('statementDate', '2026-02-14')
            ->set('newFile', UploadedFile::fake()->create('izvod-3.pdf', 30))
            ->call('saveStatement');

        return [$component, $company];
    }

    public function test_a_foreign_statement_is_saved_with_its_currency(): void
    {
        [$component, $company] = $this->upload(BankStatementKind::FOREIGN->value, 'USD');

        $component->assertHasNoErrors();
        $this->assertSame('USD', BankStatement::where('company_id', $company->id)->firstOrFail()->currency);
    }

    public function test_a_foreign_statement_without_a_valid_currency_is_refused(): void
    {
        foreach (['GBP', ''] as $currency) {
            [$component, $company] = $this->upload(BankStatementKind::FOREIGN->value, $currency);

            $component->assertHasErrors('currency');
            $this->assertSame(0, BankStatement::where('company_id', $company->id)->count());
        }
    }

    public function test_a_denar_statement_keeps_no_currency(): void
    {
        [$component, $company] = $this->upload(BankStatementKind::DENAR->value, 'EUR');

        $component->assertHasNoErrors();
        $this->assertNull(BankStatement::where('company_id', $company->id)->firstOrFail()->currency);
    }
}

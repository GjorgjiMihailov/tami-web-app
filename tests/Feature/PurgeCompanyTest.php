<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Invoicing\SalesInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeCompanyTest extends TestCase
{
    use RefreshDatabase;

    /** Фирма со потврдена фактура: сметки, партнер, налог и ставки од книжење. */
    private function workedCompany(User $user, string $name): Company
    {
        $company = Company::factory()->create(['name' => $name]);
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'invoice_date' => '2026-03-01']);
        $invoice->lines()->create(['description' => 'Line', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '0']);
        app(SalesInvoiceService::class)->confirm($invoice->fresh(), $user->id);

        return $company;
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $user = User::factory()->create();
        $company = $this->workedCompany($user, 'Fajnens');

        $this->artisan('company:purge', ['id' => $company->id])->assertSuccessful();

        $this->assertNotNull(Company::find($company->id));
        $this->assertGreaterThan(0, JournalEntryLine::count());
    }

    public function test_a_wrong_name_deletes_nothing(): void
    {
        $user = User::factory()->create();
        $company = $this->workedCompany($user, 'Fajnens');

        $this->artisan('company:purge', ['id' => $company->id, '--apply' => true])
            ->expectsQuestion('За да потврдиш, напиши го точното име на фирмата', 'нешто друго')
            ->assertFailed();

        $this->assertNotNull(Company::find($company->id));
    }

    public function test_apply_removes_the_company_and_everything_of_it_and_nothing_else(): void
    {
        $user = User::factory()->create();
        $target = $this->workedCompany($user, 'Fajnens');
        $other = $this->workedCompany($user, 'Druga firma');
        $user->assignedCompanies()->attach([$target->id, $other->id]);

        $otherAccounts = Account::where('company_id', $other->id)->count();
        $otherEntries = JournalEntry::where('company_id', $other->id)->count();
        $otherLines = JournalEntryLine::whereIn('journal_entry_id', JournalEntry::where('company_id', $other->id)->pluck('id'))->count();

        $this->artisan('company:purge', ['id' => $target->id, '--apply' => true])
            ->expectsQuestion('За да потврдиш, напиши го точното име на фирмата', 'Fajnens')
            ->assertSuccessful();

        $this->assertNull(Company::find($target->id));
        $this->assertSame(0, Account::where('company_id', $target->id)->count());
        $this->assertSame(0, Partner::where('company_id', $target->id)->count());
        $this->assertSame(0, SalesInvoice::where('company_id', $target->id)->count());
        $this->assertSame(0, JournalEntry::where('company_id', $target->id)->count());

        // Другата фирма е недопрена, а корисникот останува.
        $this->assertNotNull(Company::find($other->id));
        $this->assertSame($otherAccounts, Account::where('company_id', $other->id)->count());
        $this->assertSame($otherEntries, JournalEntry::where('company_id', $other->id)->count());
        $this->assertSame($otherLines, JournalEntryLine::count());
        $this->assertSame(1, SalesInvoice::where('company_id', $other->id)->count());
        $this->assertNotNull(User::find($user->id));
        $this->assertSame([$other->id], $user->assignedCompanies()->pluck('companies.id')->all());
    }
}

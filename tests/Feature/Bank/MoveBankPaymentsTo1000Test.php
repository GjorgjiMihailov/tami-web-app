<?php

namespace Tests\Feature\Bank;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MoveBankPaymentsTo1000Test extends TestCase
{
    use RefreshDatabase;

    private function entry(Company $company, string $groupCode, string $description, string $accountCode): JournalEntry
    {
        $group = JournalGroup::firstOrCreate(['company_id' => $company->id, 'code' => $groupCode], ['name' => 'G', 'sort_order' => 1]);
        $entry = JournalEntry::create([
            'company_id' => $company->id,
            'journal_group_id' => $group->id,
            'entry_date' => '2026-03-10',
            'description' => $description,
            'created_by' => User::factory()->create()->id,
        ]);
        $account = Account::where('company_id', $company->id)->where('code', $accountCode)->firstOrFail();
        $entry->lines()->create(['account_id' => $account->id, 'line_date' => '2026-03-10', 'debit' => '50.00', 'credit' => '0']);

        return $entry;
    }

    public function test_dry_run_changes_nothing(): void
    {
        $company = Company::factory()->create();
        $entry = $this->entry($company, '99', 'Payment for invoice 5', '100');

        $this->artisan('bank:move-payments-to-1000')->assertSuccessful();

        $this->assertSame('100', $entry->lines()->first()->account->code);
    }

    public function test_apply_moves_only_automatic_payment_lines(): void
    {
        $company = Company::factory()->create();
        $auto = $this->entry($company, '99', 'Payment for invoice 5', '100');
        $manual = $this->entry($company, '05', 'Рачен налог', '100');
        $other = $this->entry($company, '99', 'Sales invoice 5', '120');

        $this->artisan('bank:move-payments-to-1000', ['--apply' => true])->assertSuccessful();

        $this->assertSame('1000', $auto->lines()->first()->account->code);
        $this->assertSame('100', $manual->lines()->first()->account->code);
        $this->assertSame('120', $other->lines()->first()->account->code);
    }
}

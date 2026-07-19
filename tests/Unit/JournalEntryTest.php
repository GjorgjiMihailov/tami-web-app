<?php

namespace Tests\Unit;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_number_and_fiscal_year_are_assigned_automatically(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        $first = JournalEntry::create([
            'company_id' => $company->id,
            'entry_date' => '2026-03-15',
            'description' => 'First entry',
            'created_by' => $user->id,
        ]);

        $second = JournalEntry::create([
            'company_id' => $company->id,
            'entry_date' => '2026-06-01',
            'description' => 'Second entry',
            'created_by' => $user->id,
        ]);

        $this->assertSame(2026, $first->fiscal_year);
        $this->assertSame(1, $first->entry_number);
        $this->assertSame(2, $second->entry_number);
    }

    public function test_entry_numbering_resets_per_fiscal_year(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();

        JournalEntry::create(['company_id' => $company->id, 'entry_date' => '2025-12-31', 'description' => 'Old year', 'created_by' => $user->id]);
        $newYearEntry = JournalEntry::create(['company_id' => $company->id, 'entry_date' => '2026-01-01', 'description' => 'New year', 'created_by' => $user->id]);

        $this->assertSame(1, $newYearEntry->entry_number);
        $this->assertSame(2026, $newYearEntry->fiscal_year);
    }

    public function test_entry_numbering_is_independent_per_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $user = User::factory()->create();

        JournalEntry::create(['company_id' => $companyA->id, 'entry_date' => '2026-01-01', 'description' => 'A1', 'created_by' => $user->id]);
        $bEntry = JournalEntry::create(['company_id' => $companyB->id, 'entry_date' => '2026-01-01', 'description' => 'B1', 'created_by' => $user->id]);

        $this->assertSame(1, $bEntry->entry_number);
    }

    public function test_is_balanced_returns_true_when_debits_equal_credits(): void
    {
        $company = Company::factory()->create();
        $cash = Account::factory()->for($company)->create(['code' => '1001']);
        $revenue = Account::factory()->for($company)->create(['code' => '7401']);
        $entry = JournalEntry::factory()->for($company)->create();

        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 1000]);

        $this->assertTrue($entry->isBalanced());
    }

    public function test_is_balanced_returns_false_when_debits_do_not_equal_credits(): void
    {
        $company = Company::factory()->create();
        $cash = Account::factory()->for($company)->create(['code' => '1001']);
        $revenue = Account::factory()->for($company)->create(['code' => '7401']);
        $entry = JournalEntry::factory()->for($company)->create();

        $entry->lines()->create(['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'debit' => 0, 'credit' => 900]);

        $this->assertFalse($entry->isBalanced());
    }
}

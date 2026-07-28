<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalGroup;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class JournalEntryPdfTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_renders_the_header_and_line_items(): void
    {
        $company = Company::factory()->create(['name' => 'Fajnens Badi DOOEL']);
        $group = JournalGroup::factory()->for($company)->create(['code' => '10', 'name' => 'Изводи']);
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $revenue = Account::where('company_id', $company->id)->where('code', '740')->first();
        $partner = Partner::factory()->for($company)->create(['name' => 'ABC Trading']);
        $entry = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-03-15', 'description' => 'Cash sale']);
        $entry->lines()->create(['account_id' => $cash->id, 'partner_id' => $partner->id, 'line_date' => '2026-03-15', 'debit' => 1000, 'credit' => 0]);
        $entry->lines()->create(['account_id' => $revenue->id, 'line_date' => '2026-03-15', 'debit' => 0, 'credit' => 1000]);

        $html = view('pdf.journal-entry', ['entry' => $entry->fresh(['lines.account', 'lines.partner', 'journalGroup', 'company'])])->render();

        $this->assertStringContainsString('Fajnens Badi DOOEL', $html);
        $this->assertStringContainsString('10-'.str_pad((string) $entry->entry_number, 4, '0', STR_PAD_LEFT), $html);
        $this->assertStringContainsString('Изводи', $html);
        $this->assertStringContainsString('Cash sale', $html);
        $this->assertStringContainsString('ABC Trading', $html);
        $this->assertStringContainsString(\App\Support\Format::money('1000.00'), $html);
    }

    public function test_admin_can_download_the_pdf(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $entry = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id]);
        $entry->lines()->create(['account_id' => $cash->id, 'line_date' => $entry->entry_date, 'debit' => 100, 'credit' => 0]);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('accounting.journal-entries.pdf', [$company, $entry]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_a_different_companys_entry_is_not_found(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $entry = JournalEntry::factory()->for($otherCompany)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('accounting.journal-entries.pdf', [$company, $entry]));

        $response->assertNotFound();
    }

    public function test_client_cannot_download_a_pdf_for_another_companys_entry(): void
    {
        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $entry = JournalEntry::factory()->for($otherCompany)->create();
        $client = User::factory()->create(['company_id' => $ownCompany->id]);
        $client->assignRole('client');

        $response = $this->actingAs($client)->get(route('accounting.journal-entries.pdf', [$otherCompany, $entry]));

        $response->assertForbidden();
    }

    public function test_a_null_line_date_renders_as_a_dash_instead_of_todays_date(): void
    {
        $company = Company::factory()->create();
        $group = JournalGroup::factory()->for($company)->create();
        $cash = Account::where('company_id', $company->id)->where('code', '100')->first();
        $entry = JournalEntry::factory()->for($company)->create(['journal_group_id' => $group->id, 'entry_date' => '2026-03-15']);
        $entry->lines()->create(['account_id' => $cash->id, 'line_date' => null, 'debit' => 100, 'credit' => 0]);

        $html = view('pdf.journal-entry', ['entry' => $entry->fresh(['lines.account', 'lines.partner', 'journalGroup', 'company'])])->render();

        $this->assertStringNotContainsString(\App\Support\Format::date(now()), $html);
    }
}

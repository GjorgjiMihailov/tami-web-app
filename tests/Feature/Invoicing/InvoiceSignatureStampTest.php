<?php

namespace Tests\Feature\Invoicing;

use App\Livewire\Invoicing\InvoiceSettings;
use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceSignatureStampTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
    }

    private function client(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('internal_client');

        return $user;
    }

    private function settings(Company $company)
    {
        return Livewire::actingAs($this->client($company))->test(InvoiceSettings::class, ['company' => $company]);
    }

    private function html(Company $company): string
    {
        $partner = Partner::factory()->for($company)->create();
        $invoice = SalesInvoice::factory()->for($company)->create(['partner_id' => $partner->id, 'status' => 'confirmed']);
        $invoice->lines()->create(['description' => 'Item', 'quantity' => '1', 'unit_price' => '100.00', 'vat_rate' => '18.00']);

        return view('pdf.sales-invoice', [
            'invoice' => $invoice->fresh(['lines', 'partner', 'company.bankAccounts']),
        ])->render();
    }

    public function test_the_signature_and_the_stamp_are_uploaded_and_saved(): void
    {
        $company = Company::factory()->create();

        $this->settings($company)
            ->set('newSignature', UploadedFile::fake()->image('potpis.png'))
            ->set('newStamp', UploadedFile::fake()->image('pecat.png'))
            ->call('saveImages')
            ->assertHasNoErrors();

        $company->refresh();
        $this->assertNotNull($company->signature_path);
        $this->assertNotNull($company->stamp_path);
        Storage::disk('public')->assertExists($company->signature_path);
        Storage::disk('public')->assertExists($company->stamp_path);
        $this->assertNotSame($company->signature_path, $company->stamp_path);
    }

    public function test_saving_with_only_one_image_leaves_the_other_untouched(): void
    {
        $company = Company::factory()->create(['stamp_path' => 'signatures/1/old-stamp.png']);
        Storage::disk('public')->put('signatures/1/old-stamp.png', 'x');

        $this->settings($company)
            ->set('newSignature', UploadedFile::fake()->image('potpis.png'))
            ->call('saveImages');

        $company->refresh();
        $this->assertNotNull($company->signature_path);
        $this->assertSame('signatures/1/old-stamp.png', $company->stamp_path);
        Storage::disk('public')->assertExists('signatures/1/old-stamp.png');
    }

    public function test_something_that_is_not_an_image_is_refused(): void
    {
        $company = Company::factory()->create();

        $this->settings($company)
            ->set('newSignature', UploadedFile::fake()->create('potpis.pdf', 20, 'application/pdf'))
            ->call('saveImages')
            ->assertHasErrors('newSignature');

        $this->assertNull($company->fresh()->signature_path);
    }

    public function test_a_replaced_image_is_deleted_from_disk(): void
    {
        $company = Company::factory()->create(['signature_path' => 'signatures/1/old.png']);
        Storage::disk('public')->put('signatures/1/old.png', 'x');

        $this->settings($company)
            ->set('newSignature', UploadedFile::fake()->image('novo.png'))
            ->call('saveImages');

        Storage::disk('public')->assertMissing('signatures/1/old.png');
        $this->assertNotSame('signatures/1/old.png', $company->fresh()->signature_path);
    }

    public function test_the_signature_and_the_stamp_can_be_removed(): void
    {
        $company = Company::factory()->create(['signature_path' => 'signatures/1/s.png', 'stamp_path' => 'signatures/1/p.png']);
        Storage::disk('public')->put('signatures/1/s.png', 'x');
        Storage::disk('public')->put('signatures/1/p.png', 'x');

        $this->settings($company)->call('removeSignature')->call('removeStamp');

        $company->refresh();
        $this->assertNull($company->signature_path);
        $this->assertNull($company->stamp_path);
        Storage::disk('public')->assertMissing('signatures/1/s.png');
        Storage::disk('public')->assertMissing('signatures/1/p.png');
    }

    public function test_another_companys_client_cannot_open_the_screen_to_change_it(): void
    {
        $own = Company::factory()->create();
        $other = Company::factory()->create();

        $this->actingAs($this->client($own))
            ->get(route('invoice-settings.index', $other))
            ->assertForbidden();
    }

    public function test_the_invoice_prints_the_signature_and_the_stamp_above_the_issuer_line(): void
    {
        Storage::disk('public')->put('signatures/1/s.png', 'x');
        Storage::disk('public')->put('signatures/1/p.png', 'x');
        $company = Company::factory()->create(['signature_path' => 'signatures/1/s.png', 'stamp_path' => 'signatures/1/p.png']);

        $html = $this->html($company);

        $this->assertStringContainsString(Storage::disk('public')->path('signatures/1/s.png'), $html);
        $this->assertStringContainsString(Storage::disk('public')->path('signatures/1/p.png'), $html);
        // Сликите стојат во колоната на издавачот, пред линијата за потпис.
        $this->assertLessThan(strpos($html, 'class="sig-line"'), strpos($html, Storage::disk('public')->path('signatures/1/s.png')));
    }

    public function test_an_invoice_without_them_prints_exactly_as_before(): void
    {
        $html = $this->html(Company::factory()->create());

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('margin-top: 90px', $html);
    }

    public function test_a_path_whose_file_is_gone_is_ignored_instead_of_a_broken_image(): void
    {
        $company = Company::factory()->create(['signature_path' => 'signatures/1/gone.png', 'stamp_path' => 'signatures/1/gone2.png']);

        $html = $this->html($company);

        $this->assertStringNotContainsString('<img', $html);
    }
}

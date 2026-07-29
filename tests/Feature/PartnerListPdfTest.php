<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerListPdfTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_downloads_a_pdf_of_the_companys_partners(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Acme DOOEL']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('partners.pdf', $company));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_a_client_of_another_company_cannot_download_the_pdf(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $otherCompany->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('partners.pdf', $company))
            ->assertForbidden();
    }

    public function test_it_lists_name_type_tax_id_phone_and_email(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create([
            'name' => 'Acme DOOEL',
            'type' => 'legal_entity',
            'tax_id' => '4030012345678',
            'phone' => '070123456',
            'email' => 'contact@acme.mk',
        ]);
        Partner::factory()->for($company)->individual()->create(['name' => 'Марко Петровски']);

        $html = view('pdf.partner-list', [
            'company' => $company,
            'partners' => Partner::where('company_id', $company->id)->orderBy('name')->get(),
        ])->render();

        $this->assertStringContainsString('Acme DOOEL', $html);
        $this->assertStringContainsString('Правно лице', $html);
        $this->assertStringContainsString('4030012345678', $html);
        $this->assertStringContainsString('070123456', $html);
        $this->assertStringContainsString('contact@acme.mk', $html);
        $this->assertStringContainsString('Марко Петровски', $html);
        $this->assertStringContainsString('Физичко лице', $html);
    }

    public function test_the_downloaded_response_is_an_actual_rendered_pdf(): void
    {
        $company = Company::factory()->create();
        Partner::factory()->for($company)->create(['name' => 'Acme DOOEL']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('partners.pdf', $company));

        $response->assertOk();

        $bytes = $response->getContent();

        $this->assertNotFalse($bytes, 'Expected the response to expose raw PDF bytes.');
        $this->assertStringStartsWith('%PDF-', $bytes, 'Response body does not look like a real PDF document.');
        $this->assertGreaterThan(1000, strlen($bytes), 'Rendered PDF is suspiciously small to be a real document.');
        $this->assertStringContainsString('%%EOF', $bytes, 'Rendered PDF is missing its end-of-file marker.');
    }

    public function test_the_partner_index_links_to_the_pdf_download(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->get(route('partners.index', $company))
            ->assertOk()
            ->assertSee(route('partners.pdf', $company), false);
    }
}

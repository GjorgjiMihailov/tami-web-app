<?php

namespace Tests\Feature\Invoicing;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SalesInvoiceImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
    }

    public function test_the_template_is_an_xlsx_with_the_agreed_headings(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.bulk-import.template', $company));

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );

        $path = tempnam(sys_get_temp_dir(), 'tpl-').'.xlsx';
        file_put_contents($path, $response->streamedContent() ?: $response->getContent());

        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame(
            ['Број на фактура', 'Датум на издавање', 'Кооперант', 'ЕДБ на кооперант', 'Вкупно од хартија (незадолжително)'],
            [$sheet->getCell('A1')->getValue(), $sheet->getCell('B1')->getValue(), $sheet->getCell('C1')->getValue(), $sheet->getCell('D1')->getValue(), $sheet->getCell('E1')->getValue()]
        );
    }

    public function test_the_number_and_tax_id_columns_are_text_so_excel_keeps_leading_zeros(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get(route('sales-invoices.bulk-import.template', $company));

        $path = tempnam(sys_get_temp_dir(), 'tpl-').'.xlsx';
        file_put_contents($path, $response->streamedContent() ?: $response->getContent());

        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('@', $sheet->getStyle('A5')->getNumberFormat()->getFormatCode());
        $this->assertSame('@', $sheet->getStyle('D5')->getNumberFormat()->getFormatCode());
    }

    public function test_a_client_cannot_download_the_template(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('internal_client');

        $this->actingAs($client)
            ->get(route('sales-invoices.bulk-import.template', $company))
            ->assertForbidden();
    }
}

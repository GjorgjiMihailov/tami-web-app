<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StockOnHandPdfTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_it_downloads_a_pdf_of_totals_across_all_warehouses(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'selling_price' => '80.00']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $admin->id);

        $response = $this->actingAs($admin)->get(route('inventory.reports.stock-on-hand.pdf', $company));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_it_downloads_a_pdf_scoped_to_one_warehouse(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['selling_price' => '80.00']);
        $warehouseA = Warehouse::factory()->for($company)->create(['name' => 'Main']);
        $warehouseB = Warehouse::factory()->for($company)->create(['name' => 'Annex']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouseA, '10', '50.00', '2026-01-01', $admin->id);
        app(StockMovementService::class)->receipt($item, $warehouseB, '5', '50.00', '2026-01-01', $admin->id);

        $response = $this->actingAs($admin)
            ->get(route('inventory.reports.stock-on-hand.pdf', $company).'?warehouseId='.$warehouseA->id);

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_an_unknown_or_foreign_warehouse_id_is_rejected(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->for($otherCompany)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.reports.stock-on-hand.pdf', $company).'?warehouseId='.$foreignWarehouse->id)
            ->assertNotFound();
    }

    public function test_a_client_of_another_company_cannot_download_the_pdf(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $otherCompany->id]);
        $client->assignRole('client');

        $this->actingAs($client)
            ->get(route('inventory.reports.stock-on-hand.pdf', $company))
            ->assertForbidden();
    }

    public function test_it_shows_cost_and_selling_value_columns(): void
    {
        $company = Company::factory()->create();

        $html = view('pdf.stock-on-hand', [
            'company' => $company,
            'rows' => collect([
                ['item_code' => 'SKU-1', 'item_name' => 'Widget', 'quantity' => 10.0, 'cost_value' => 500.0, 'selling_value' => 800.0],
            ]),
            'warehouseName' => null,
        ])->render();

        $this->assertStringContainsString('Widget', $html);
        $this->assertStringContainsString('Набавна вредност', $html);
        $this->assertStringContainsString('Продажна вредност', $html);
        $this->assertStringContainsString(\App\Support\Format::money(500.0, currency: ''), $html);
        $this->assertStringContainsString(\App\Support\Format::money(800.0, currency: ''), $html);
    }

    public function test_it_shows_the_warehouse_name_in_the_title_when_scoped(): void
    {
        $company = Company::factory()->create();

        $html = view('pdf.stock-on-hand', [
            'company' => $company,
            'rows' => collect(),
            'warehouseName' => 'Главен магацин',
        ])->render();

        $this->assertStringContainsString('Главен магацин', $html);
    }

    public function test_the_downloaded_response_is_an_actual_rendered_pdf(): void
    {
        $company = Company::factory()->create();
        $item = Item::factory()->for($company)->create(['name' => 'Widget', 'selling_price' => '80.00']);
        $warehouse = Warehouse::factory()->for($company)->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        app(StockMovementService::class)->receipt($item, $warehouse, '10', '50.00', '2026-01-01', $admin->id);

        $response = $this->actingAs($admin)->get(route('inventory.reports.stock-on-hand.pdf', $company));

        $response->assertOk();
        $bytes = $response->getContent();

        $this->assertNotFalse($bytes, 'Expected the response to expose raw PDF bytes.');
        $this->assertStringStartsWith('%PDF-', $bytes, 'Response body does not look like a real PDF document.');
        $this->assertGreaterThan(1000, strlen($bytes), 'Rendered PDF is suspiciously small to be a real document.');
        $this->assertStringContainsString('%%EOF', $bytes, 'Rendered PDF is missing its end-of-file marker.');

        $text = '';
        preg_match_all('/stream\r?\n?(.*?)endstream/s', $bytes, $streams);
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream) ?: @gzinflate(substr($stream, 2)) ?: $stream;
            if (! str_contains($decoded, 'BT ')) {
                continue;
            }
            if (preg_match_all('/\[\(((?:[^()\\\\]|\\\\.)*)\)\]\s*TJ/', $decoded, $matches)) {
                foreach ($matches[1] as $raw) {
                    $text .= mb_convert_encoding(stripcslashes($raw), 'UTF-8', 'UTF-16BE')."\n";
                }
            }
        }

        $this->assertStringContainsString('Продажна вредност', $text, 'Selling value column header was not found in the rendered PDF text.');
        $this->assertStringContainsString(\App\Support\Format::money(800.0, currency: ''), $text, 'Selling value (10 units at 80.00) was not found in the rendered PDF text.');
    }
}

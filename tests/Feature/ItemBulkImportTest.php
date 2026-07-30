<?php

namespace Tests\Feature;

use App\Livewire\Inventory\ItemBulkImport;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ItemBulkImportTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('client');
    }

    public function test_the_bulk_import_page_renders_successfully_over_http(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('inventory.items.bulk-import', $company))
            ->assertOk();
    }

    public function test_downloading_the_template_returns_an_xlsx_file(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)
            ->get(route('inventory.items.bulk-import.template', $company));

        $response->assertOk();
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }

    private function makeXlsxUpload(array $rows): \Illuminate\Http\UploadedFile
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows, null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'items-import-').'.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        // Livewire's test helper (Testable::upload()) expects the uploaded file to expose
        // a public $name property, which only Illuminate\Http\Testing\File provides (a plain
        // Illuminate\Http\UploadedFile does not). createWithContent() gives us that wrapper
        // while still writing the real spreadsheet bytes to disk, so Excel::toArray() can
        // parse genuine spreadsheet content rather than fake padded bytes.
        return \Illuminate\Http\UploadedFile::fake()->createWithContent('items.xlsx', file_get_contents($path));
    }

    public function test_uploading_a_file_shows_a_preview_with_new_and_update_rows(): void
    {
        $company = Company::factory()->create();
        \App\Models\Item::factory()->for($company)->create(['code' => 'SKU-EXISTING', 'name' => 'Old Name']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-NEW', 'Brand New Item', 'парче', '', '18', '50.00', 'производ', 'Не', ''],
            ['SKU-EXISTING', 'Updated Name', 'парче', '', '', '', '', '', ''],
        ];

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview')
            ->assertSet('parsedRows.0.action', 'new')
            ->assertSet('parsedRows.1.action', 'update')
            ->assertSee('Brand New Item')
            ->assertSee('Updated Name');
    }

    public function test_confirming_saves_valid_rows_and_skips_invalid_ones(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-GOOD', 'Good Item', 'парче', '', '18', '50.00', 'производ', 'Не', ''],
            ['', 'No Code', 'парче', '', '', '', '', '', ''],
        ];

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview')
            ->call('confirmImport')
            ->assertSet('summary', '1 додадени, 0 ажурирани, 1 прескокнати.');

        $this->assertDatabaseHas('items', ['company_id' => $company->id, 'code' => 'SKU-GOOD', 'name' => 'Good Item']);
        $this->assertDatabaseCount('items', 1);
    }

    public function test_confirming_an_update_row_with_a_blank_vat_rate_keeps_the_existing_rate(): void
    {
        $company = Company::factory()->create();
        \App\Models\Item::factory()->for($company)->create(['code' => 'SKU-KEEP', 'vat_rate' => '5.00']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-KEEP', 'Renamed', 'парче', '', '', '', '', '', ''],
        ];

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview')
            ->call('confirmImport');

        $item = \App\Models\Item::where('company_id', $company->id)->where('code', 'SKU-KEEP')->first();
        $this->assertSame('Renamed', $item->name);
        $this->assertSame('5.00', (string) $item->vat_rate);
    }

    public function test_uploading_a_non_spreadsheet_file_shows_a_friendly_error(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $badFile = \Illuminate\Http\UploadedFile::fake()->createWithContent('items.xlsx', 'this is plain text, not a real xlsx file');

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $badFile)
            ->call('preview')
            ->assertHasErrors(['importFile']);
    }

    public function test_the_preview_shows_the_resolved_values_for_a_new_row(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-SHOW', 'Show Values', 'парче', 'Алат', '5', '99.90', 'услуга', 'Да', '3800000000099'],
        ];

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview')
            ->assertSee('Алат')
            ->assertSee('5.00')
            ->assertSee('Услуга')
            ->assertSee('3800000000099');
    }

    public function test_the_preview_shows_no_change_for_untouched_fields_on_an_update_row(): void
    {
        $company = Company::factory()->create();
        \App\Models\Item::factory()->for($company)->create(['code' => 'SKU-NOCHANGE', 'vat_rate' => '5.00']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-NOCHANGE', 'Renamed Only', 'парче', '', '', '', '', '', ''],
        ];

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview')
            ->assertSee('(без промена)');
    }

    public function test_confirming_retains_error_rows_with_their_reasons_for_review(): void
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-OK', 'Good Item', 'парче', '', '', '', '', '', ''],
            ['', 'No Code', 'парче', '', '', '', '', '', ''],
        ];

        $component = Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview')
            ->call('confirmImport');

        $component->assertSee('Шифрата е задолжителна.');
        $this->assertCount(1, $component->get('parsedRows'));
        $this->assertSame('error', $component->get('parsedRows')[0]['action']);
    }

    public function test_a_database_failure_during_confirm_shows_a_friendly_error_and_rolls_back(): void
    {
        $company = Company::factory()->create();
        \App\Models\Item::factory()->for($company)->create(['code' => 'SKU-RACE']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-NEW', 'Created First', 'парче', '', '', '', '', '', ''],
            ['SKU-RACE', 'Will race', 'парче', '', '', '', '', '', ''],
        ];

        $component = Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview');

        // Simulate a concurrent delete of the matched item between preview() and confirmImport()
        // so confirmImport()'s findOrFail() throws mid-transaction — proves the try/catch
        // produces a friendly error instead of a raw 500, and nothing partial persists. SKU-NEW
        // is processed before SKU-RACE in the same transaction, so if the rollback didn't
        // genuinely work, SKU-NEW would have survived as a committed row.
        \App\Models\Item::where('code', 'SKU-RACE')->delete();

        $component->call('confirmImport')->assertHasErrors(['confirm']);

        $this->assertDatabaseCount('items', 0);
    }

    public function test_a_client_can_upload_and_confirm_for_their_own_company(): void
    {
        $company = Company::factory()->create();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $rows = [
            ['Шифра', 'Назив', 'Мерна единица', 'Категорија', 'ДДВ стапка', 'Продажна цена', 'Тип', 'МК-производство', 'Баркод'],
            ['SKU-CLIENT', 'Client Item', 'парче', '', '', '', '', '', ''],
        ];

        Livewire::test(ItemBulkImport::class, ['company' => $company])
            ->set('importFile', $this->makeXlsxUpload($rows))
            ->call('preview')
            ->call('confirmImport')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('items', ['company_id' => $company->id, 'code' => 'SKU-CLIENT']);
    }
}

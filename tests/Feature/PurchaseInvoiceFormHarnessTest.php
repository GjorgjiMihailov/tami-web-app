<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\PurchaseInvoiceForm;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Not a test of behaviour — a way to look at the form. `artisan serve` is too
 * slow for a browser check on this machine, so this renders the component and
 * dumps the HTML next to the built stylesheet. Runs only when HARNESS_OUT is
 * set, so the normal suite never touches it.
 */
class PurchaseInvoiceFormHarnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_dump_the_rendered_form(): void
    {
        $out = env('HARNESS_OUT');

        if (! $out) {
            $this->markTestSkipped('HARNESS_OUT not set.');
        }

        Role::findOrCreate('admin');

        $company = Company::factory()->create(['name' => 'Тами ДООЕЛ', 'is_vat_registered' => true]);
        $partner = Partner::factory()->for($company)->create(['name' => 'Комерцијална банка АД']);
        Partner::factory()->for($company)->create(['name' => 'Макпетрол АД']);
        Warehouse::factory()->for($company)->create(['name' => 'Главен магацин']);
        $product = Item::factory()->for($company)->create(['code' => 'A-100', 'name' => 'Хартија А4', 'vat_rate' => '18.00']);
        $service = Item::factory()->for($company)->service()->create(['code' => 'U-010', 'name' => 'Сметководствени услуги', 'vat_rate' => '18.00']);
        $account = Account::where('company_id', $company->id)->where('code', '462')->first();

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $html = Livewire::test(PurchaseInvoiceForm::class, ['company' => $company])
            ->set('partnerId', (string) $partner->id)
            ->set('supplierInvoiceNumber', '0412-2026')
            ->set('lines.0.item_id', (string) $product->id)
            ->set('lines.0.description', 'Хартија А4, 500 листа')
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_price', '245.00')
            ->set('lines.0.vat_rate', '18.00')
            ->call('addLine')
            ->set('lines.1.item_id', (string) $service->id)
            ->set('lines.1.account_id', (string) $account->id)
            ->set('lines.1.description', 'Водење на книги за септември')
            ->set('lines.1.quantity', '1')
            ->set('lines.1.vat_rate', '18.00')
            ->set('lines.1.unit_price_gross', '100.00')
            ->call('addLine')
            ->set('lines.2.account_id', (string) $account->id)
            ->set('lines.2.description', 'Репрезентација')
            ->set('lines.2.quantity', '1')
            ->set('lines.2.unit_price', '1500.00')
            ->set('lines.2.vat_rate', '18.00')
            ->set('lines.2.vat_deductible', false)
            ->html();

        $css = basename(collect(glob(public_path('build/assets/*.css')))->first());

        file_put_contents($out, <<<HTML
        <!doctype html>
        <html lang="mk"><head><meta charset="utf-8">
        <link rel="stylesheet" href="/build/assets/{$css}">
        <link rel="stylesheet" href="https://fonts.bunny.net/css?family=manrope:400,500,600,700">
        <style>body{background:#F5F1EA;padding:24px;font-family:Manrope,system-ui,sans-serif}</style>
        </head><body>{$html}</body></html>
        HTML);

        $this->assertFileExists($out);
    }
}

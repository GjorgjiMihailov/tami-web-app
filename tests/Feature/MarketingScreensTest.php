<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeSalary;
use App\Models\Item;
use App\Models\Partner;
use App\Models\PayrollMonthHours;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesInvoicePayment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementService;
use App\Services\Payroll\PayrollRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Генератор на сликите за јавната страна — НЕ Е ТЕСТ, и намерно не трча со
 * останатите. Се пали само рачно:
 *
 *     MARKETING_SCREENS=1 php artisan test --filter=MarketingScreensTest
 *
 * Ги рендерира ВИСТИНСКИТЕ екрани на апликацијата, полни со измислени
 * демо-податоци (никаде податок на вистински клиент), и ги запишува како
 * самостојни HTML фајлови со вграден CSS. Потоа тие се сликаат со headless
 * Chrome во public/images/screens/. Оваа заобиколка постои зашто
 * `php artisan serve` е еднонитен и пребавен за вакво нешто.
 *
 * Стои во репото за да може сликите да се освежат кога екраните ќе се сменат,
 * без целата постапка да се измислува одново.
 */
class MarketingScreensTest extends TestCase
{
    use RefreshDatabase;

    private const OUT = 'marketing-screens';

    public function test_generate(): void
    {
        if (! env('MARKETING_SCREENS')) {
            $this->markTestSkipped('Се пали рачно со MARKETING_SCREENS=1.');
        }

        $dir = storage_path('app/'.self::OUT);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');

        $admin = User::factory()->create(['name' => 'Тамара Михаилова', 'email' => 'demo@financebuddy.mk']);
        $admin->assignRole('admin');

        $company = Company::factory()->create([
            'name' => 'ТЕХНО СОЛУШНС ДООЕЛ СКОПЈЕ',
            'tax_id' => '4080024500123',
            'email' => 'info@tehnosolusns.mk',
            'phone' => '02 3123 456',
            'address' => 'ул. Македонија 15, Скопје',
        ]);

        $partners = collect([
            'МАКЕДОНИЈА ТРЕЈД ДОО',
            'ВАРДАР ИНЖЕНЕРИНГ ДООЕЛ',
            'ПЕЛАГОНИЈА АГРАР ДОО',
            'СКОПЈЕ ЛОГИСТИК ДООЕЛ',
            'БИТОЛА ТЕКСТИЛ ДОО',
        ])->map(fn (string $name) => Partner::factory()->for($company)->create([
            'name' => $name,
            'is_vat_registered' => true,
        ]));

        // Излезни фактури низ 2026 — различни статуси, различни износи, дел од
        // нив пратени на УЈП, за екранот да изгледа како вистинска работна
        // година, а не како еден ред.
        $rows = [
            // Двете од тековниот месец се тука за плочката „ДДВ за тековниот
            // период" да покаже бројка — таа гледа само во месецот во тек.
            ['2026-09-02', 1, '96000.00', 'confirmed', '05 Испратена', false, false],
            ['2026-09-01', 4, '73500.00', 'confirmed', '03 Прифатена', true, false],
            ['2026-08-28', 3, '118000.00', 'confirmed', '05 Испратена', false, false],
            ['2026-08-19', 0, '47500.00', 'confirmed', '03 Прифатена', true, true],
            ['2026-08-11', 1, '236000.00', 'confirmed', '03 Прифатена', true, false],
            ['2026-07-30', 4, '62000.00', 'confirmed', null, false, true],
            ['2026-07-22', 2, '89400.00', 'confirmed', '03 Прифатена', true, true],
            ['2026-07-08', 0, '154000.00', 'confirmed', null, false, true],
            ['2026-06-25', 3, '31200.00', 'draft', null, false, false],
        ];

        $number = count($rows);

        foreach ($rows as [$date, $partnerIndex, $amount, $status, $ujpStatus, $accepted, $paid]) {
            $invoice = SalesInvoice::factory()->for($company)->create([
                'partner_id' => $partners[$partnerIndex]->id,
                // Броевите се доделуваат при потврдување во вистинскиот тек;
                // тука се внесуваат рачно за да не стои „—" во колоната „Број".
                'fiscal_year' => 2026,
                'invoice_number' => $status === 'draft' ? null : $number--,
                'invoice_date' => $date,
                'due_date' => date('Y-m-d', strtotime($date.' +15 days')),
                'status' => $status,
                'created_by' => $admin->id,
                'efaktura_status' => $ujpStatus ? 'sent' : 'not_sent',
                'efaktura_sent_at' => $ujpStatus ? $date.' 09:14:00' : null,
                'efaktura_ujp_status_code' => $accepted ? '03' : ($ujpStatus ? '05' : null),
                'efaktura_ujp_status_name' => $ujpStatus,
            ]);

            SalesInvoiceLine::factory()->for($invoice, 'salesInvoice')->create([
                'description' => 'Услуги по договор',
                'quantity' => '1.000',
                'unit_price' => $amount,
                'vat_rate' => '18.00',
                'vat_treatment' => 'standard',
            ]);

            if ($paid) {
                SalesInvoicePayment::factory()->for($invoice, 'salesInvoice')->create([
                    'payment_date' => date('Y-m-d', strtotime($date.' +9 days')),
                    'amount' => bcmul($amount, '1.18', 2),
                ]);
            }
        }

        // Влезни фактури — без нив плочките за трошоци и обврски стојат на нула
        // и екранот изгледа како празен систем.
        foreach ([
            ['2026-09-01', 0, '34000.00', 'confirmed'],
            ['2026-08-20', 2, '58200.00', 'confirmed'],
            ['2026-08-05', 1, '21500.00', 'confirmed'],
            ['2026-07-18', 4, '92000.00', 'confirmed'],
        ] as [$date, $partnerIndex, $amount, $status]) {
            $purchase = PurchaseInvoice::factory()->for($company)->create([
                'partner_id' => $partners[$partnerIndex]->id,
                'invoice_date' => $date,
                'due_date' => date('Y-m-d', strtotime($date.' +20 days')),
                'status' => $status,
                'created_by' => $admin->id,
            ]);

            PurchaseInvoiceLine::factory()->for($purchase, 'purchaseInvoice')->create([
                'description' => 'Материјали и услуги',
                'quantity' => '1.000',
                'unit_price' => $amount,
                'vat_rate' => '18.00',
            ]);
        }

        // Залиха, за да не стои „0,00 ден" на плочката.
        $warehouse = Warehouse::factory()->for($company)->create(['name' => 'Главен магацин']);
        $stockService = app(StockMovementService::class);

        foreach ([
            ['Лаптоп Dell Latitude', '12', '38500.00'],
            ['Монитор 27"', '18', '9800.00'],
            ['Мрежен свич 24 порти', '6', '14200.00'],
        ] as [$name, $qty, $price]) {
            $item = Item::factory()->for($company)->create(['name' => $name]);
            $stockService->receipt($item, $warehouse, $qty, $price, '2026-02-14', $admin->id);
        }

        // Плата за јули 2026 — четворица вработени со реални бруто износи.
        PayrollMonthHours::firstOrCreate(['year' => 2026, 'month' => 7], ['hours' => 184]);

        // ЕМБГ-ата се измислени, ама поминуваат проверка на контролна цифра —
        // истите вредности што ги користат постоечките тестови.
        $staff = [
            ['Ана', 'Николовска', '3101980455019', 58000],
            ['Марко', 'Стојановски', '0101990450006', 72000],
            ['Елена', 'Трајкова', '1503880410003', 45000],
            ['Дејан', 'Петровски', '2207900450002', 38507],
        ];

        foreach ($staff as [$first, $last, $embg, $gross]) {
            $employee = Employee::factory()->for($company)->create([
                'first_name' => $first,
                'last_name' => $last,
                'embg' => $embg,
                'employed_on' => '2024-03-01',
                'prior_service_months' => 0,
            ]);

            EmployeeSalary::create([
                'employee_id' => $employee->id,
                'effective_from' => '2026-01-01',
                'amount' => $gross,
                'basis' => 'gross',
            ]);
        }

        $run = app(PayrollRunService::class)->open($company, 2026, 7);

        $screens = [
            'dashboard' => "/companies/{$company->id}/dashboard",
            'sales-invoices' => "/companies/{$company->id}/sales-invoices",
            'payroll' => "/companies/{$company->id}/payroll-runs/{$run->id}",
            'employees' => "/companies/{$company->id}/employees",
        ];

        // Вградениот CSS ја прави страницата самостојна: се отвора како обичен
        // фајл, без сервер и без надворешни патеки.
        $manifest = json_decode(file_get_contents(public_path('build/manifest.json')), true);
        $css = file_get_contents(public_path('build/'.$manifest['resources/css/app.css']['file']));

        foreach ($screens as $name => $url) {
            $response = $this->actingAs($admin)->get($url);

            $html = $response->getContent();
            $html = preg_replace('#<link[^>]+/build/[^>]+>#', '<style>'.$css.'</style>', $html, 1);
            $html = preg_replace('#<script[^>]+/build/[^>]+></script>#', '', $html);

            file_put_contents($dir.DIRECTORY_SEPARATOR.$name.'.html', $html);

            fwrite(STDERR, sprintf("%-16s %d  %d bytes\n", $name, $response->status(), strlen($html)));
        }

        $this->assertTrue(true);
    }
}

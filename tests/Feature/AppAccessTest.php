<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\Form743;
use App\Models\PurchaseInvoice;
use App\Models\User;
use App\Support\CompanyType;
use App\Support\PortalApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
        Role::findOrCreate('accountant');
    }

    public function test_a_new_user_may_enter_every_app(): void
    {
        $user = User::factory()->create();
        $user->assignRole('internal_client');

        foreach (PortalApp::workApps() as $app) {
            $this->assertTrue($user->canAccessApp($app), "Стандардно {$app->value} треба да е дозволен.");
        }
    }

    public function test_an_unticked_app_is_closed(): void
    {
        $user = User::factory()->create(['app_finansii' => false]);
        $user->assignRole('internal_client');

        $this->assertFalse($user->canAccessApp(PortalApp::FINANSII));
        $this->assertTrue($user->canAccessApp(PortalApp::PRODAZBA));
    }

    public function test_an_admin_ignores_the_ticks(): void
    {
        $user = User::factory()->create([
            'app_prodazba' => false,
            'app_finansii' => false,
            'app_plata' => false,
        ]);
        $user->assignRole('admin');

        foreach (PortalApp::workApps() as $app) {
            $this->assertTrue($user->canAccessApp($app), 'Админ не смее да се заклучи сам.');
        }
    }

    public function test_the_portal_is_open_to_every_signed_in_user(): void
    {
        $user = User::factory()->create([
            'app_prodazba' => false,
            'app_finansii' => false,
            'app_plata' => false,
        ]);
        $user->assignRole('internal_client');

        $this->assertTrue($user->canAccessApp(PortalApp::PORTAL));
    }

    public function test_a_fresh_unsaved_user_already_reads_as_allowed(): void
    {
        // Стандардната вредност на колоната во базата НЕ полни модел во меморија.
        $this->assertTrue((new User)->canAccessApp(PortalApp::PRODAZBA));
    }

    public function test_an_unticked_app_returns_the_no_access_screen(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id, 'app_finansii' => false]);
        $user->assignRole('accountant');
        $company->accountants()->attach($user->id);

        $response = $this->actingAs($user)->get(route('accounting.journal-groups.index', $company));

        $response->assertStatus(403);
        $response->assertSee('Немате пристап до оваа апликација');
    }

    public function test_a_switched_off_module_returns_the_no_access_screen(): void
    {
        $company = Company::factory()->create(['uses_payroll' => false]);
        $user = User::factory()->create();
        $user->assignRole('admin');

        $response = $this->actingAs($user)->get(route('employees.index', $company));

        $response->assertStatus(403);
        $response->assertSee('Оваа апликација нема ништо достапно за оваа фирма и за овој корисник');
    }

    public function test_a_ticked_app_opens_normally(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $user->assignRole('admin');

        $this->actingAs($user)->get(route('sales-invoices.index', $company))->assertOk();
    }

    /**
     * form743.download е преземање датотека на порталот (743 обрасци работниот
     * список е портална страна), не екран на Финансии — правото за finansii не
     * смее да го затвора. Пред поправката рутата седеше кај finansii, па
     * EnsureAppAccess:finansii ја одбиваше оваа истата задача.
     */
    public function test_an_accountant_without_finansii_right_can_still_download_a_743_file(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
        $form = Form743::factory()->for($company)->create();
        $document = Document::factory()->for($form, 'documentable')->create(['company_id' => $company->id, 'path' => 'documents/test/743.pdf']);
        Storage::disk('google')->put($document->path, 'fake-pdf-content');

        $accountant = User::factory()->create(['app_finansii' => false]);
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('form743.download', [$company, $form]))
            ->assertOk();
    }

    /**
     * documents.download е исто така преземање датотека на порталот, не екран
     * на Продажба — Банкарски документи (finansii) го линкува истиот URL. Пред
     * поправката рутата седеше кај prodazba, па EnsureAppAccess:prodazba ја
     * одбиваше оваа истата задача.
     */
    public function test_an_accountant_without_prodazba_right_can_still_download_an_attached_document(): void
    {
        Storage::fake('google');
        $company = Company::factory()->create();
        $invoice = PurchaseInvoice::factory()->for($company)->create();
        $document = Document::factory()->for($invoice, 'documentable')->create(['company_id' => $company->id, 'path' => 'documents/test/bill.pdf']);
        Storage::disk('google')->put($document->path, 'fake-pdf-content');

        $accountant = User::factory()->create(['app_finansii' => true, 'app_prodazba' => false]);
        $accountant->assignRole('accountant');
        $company->accountants()->attach($accountant);

        $this->actingAs($accountant)
            ->get(route('documents.download', [$company, $document]))
            ->assertOk();
    }
}

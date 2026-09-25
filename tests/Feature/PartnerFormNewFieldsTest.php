<?php

namespace Tests\Feature;

use App\Livewire\Invoicing\SalesInvoiceForm;
use App\Livewire\PartnerForm;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PartnerFormNewFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('internal_client');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        return $admin;
    }

    public function test_the_edit_page_renders_and_a_foreign_partner_is_404(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $foreign = Partner::factory()->for(Company::factory()->create())->create();
        $this->admin();

        $this->get(route('partners.edit', [$company, $partner]))->assertOk()->assertSee('Уреди кооперант');
        $this->get(route('partners.edit', [$company, $foreign]))->assertNotFound();
    }

    public function test_the_form_has_the_three_tabs(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        $this->get(route('partners.create', $company))
            ->assertSee('Други податоци')
            ->assertSee('Адреса')
            ->assertSee('Контакт лица');
    }

    public function test_it_saves_the_primary_contact_phones_terms_and_both_addresses(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('name', 'Петровски ДООЕЛ')
            ->set('contactSalutation', 'Г-дин')
            ->set('contactFirstName', 'Марко')
            ->set('contactLastName', 'Петровски')
            ->set('phone', '+389 2 3123 456')
            ->set('mobile', '+389 70 123 456')
            ->set('paymentTermsDays', '30')
            ->set('country', 'Македонија')
            ->set('streetAddress', 'Илинденска')
            ->set('streetNumber', '12')
            ->set('postalCode', '1000')
            ->set('city', 'Скопје')
            ->set('shippingStreetAddress', 'Индустриска')
            ->set('shippingCity', 'Тетово')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('partners', [
            'name' => 'Петровски ДООЕЛ',
            'contact_salutation' => 'Г-дин',
            'contact_first_name' => 'Марко',
            'contact_last_name' => 'Петровски',
            'mobile' => '+389 70 123 456',
            'payment_terms_days' => 30,
            'street_address' => 'Илинденска',
            'city' => 'Скопје',
            'shipping_street_address' => 'Индустриска',
            'shipping_city' => 'Тетово',
        ]);
    }

    public function test_payment_terms_zero_means_on_receipt_and_blank_means_unset(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])->set('name', 'А')->set('paymentTermsDays', '0')->call('save');
        Livewire::test(PartnerForm::class, ['company' => $company])->set('name', 'Б')->call('save');

        $this->assertSame(0, Partner::where('name', 'А')->first()->payment_terms_days);
        $this->assertNull(Partner::where('name', 'Б')->first()->payment_terms_days);
    }

    public function test_copy_billing_fills_the_shipping_address(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('country', 'Македонија')
            ->set('streetAddress', 'Илинденска')
            ->set('streetNumber', '12')
            ->set('postalCode', '1000')
            ->set('city', 'Скопје')
            ->call('copyBillingToShipping')
            ->assertSet('shippingCountry', 'Македонија')
            ->assertSet('shippingStreetAddress', 'Илинденска')
            ->assertSet('shippingStreetNumber', '12')
            ->assertSet('shippingPostalCode', '1000')
            ->assertSet('shippingCity', 'Скопје');
    }

    public function test_contact_persons_can_be_added_removed_and_are_saved_in_order(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('name', 'Со контакти')
            ->call('addContact')
            ->call('addContact')
            ->call('addContact')
            ->set('contacts.0.first_name', 'Ана')
            ->set('contacts.0.email', 'ana@example.mk')
            ->set('contacts.1.first_name', 'Избришан')
            ->set('contacts.2.first_name', 'Игор')
            ->set('contacts.2.mobile', '070111222')
            ->call('removeContact', 1)
            ->call('save')
            ->assertHasNoErrors();

        $contacts = Partner::where('name', 'Со контакти')->first()->contacts;
        $this->assertSame(['Ана', 'Игор'], $contacts->pluck('first_name')->all());
        $this->assertSame([0, 1], $contacts->pluck('position')->all());
        $this->assertSame('ana@example.mk', $contacts[0]->email);
    }

    public function test_a_completely_blank_contact_row_is_not_saved(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('name', 'Празен контакт')
            ->call('addContact')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('partner_contacts', 0);
    }

    public function test_an_invalid_contact_email_is_rejected(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('name', 'X')
            ->call('addContact')
            ->set('contacts.0.email', 'nije-mejl')
            ->call('save')
            ->assertHasErrors(['contacts.0.email']);
    }

    public function test_editing_loads_and_replaces_the_contacts(): void
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();
        $partner->contacts()->create(['position' => 0, 'first_name' => 'Стар', 'last_name' => 'Контакт']);
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company, 'partner' => $partner])
            ->assertSet('contacts.0.first_name', 'Стар')
            ->set('contacts.0.first_name', 'Нов')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['Нов'], $partner->contacts()->pluck('first_name')->all());
    }

    public function test_name_suggestions_come_from_the_contact_name(): void
    {
        $company = Company::factory()->create();
        $this->admin();

        Livewire::test(PartnerForm::class, ['company' => $company])
            ->set('contactFirstName', 'Марко')
            ->set('contactLastName', 'Петровски')
            ->assertSeeHtml('<option value="Марко Петровски">')
            ->assertSeeHtml('<option value="Петровски Марко">');
    }

    public function test_the_printed_address_prefers_free_text_and_falls_back_to_the_structured_fields(): void
    {
        $partner = new Partner([
            'street_address' => 'Илинденска', 'street_number' => '12', 'postal_code' => '1000', 'city' => 'Скопје', 'country' => 'Македонија',
        ]);
        $this->assertSame('Илинденска 12, 1000 Скопје, Македонија', $partner->printedAddress());

        $partner->address = 'Слободен текст 1';
        $this->assertSame('Слободен текст 1', $partner->printedAddress());

        $this->assertNull((new Partner)->printedAddress());
    }

    public function test_the_sales_invoice_due_date_follows_the_buyers_payment_terms(): void
    {
        $company = Company::factory()->create();
        $withTerms = Partner::factory()->for($company)->create(['payment_terms_days' => 30]);
        $onReceipt = Partner::factory()->for($company)->create(['payment_terms_days' => 0]);
        $noTerms = Partner::factory()->for($company)->create(['payment_terms_days' => null]);
        $this->admin();

        Livewire::test(SalesInvoiceForm::class, ['company' => $company])
            ->set('invoiceDate', '2026-03-01')
            ->set('partnerId', (string) $withTerms->id)
            ->assertSet('dueDate', '2026-03-31')
            ->set('partnerId', (string) $onReceipt->id)
            ->assertSet('dueDate', '2026-03-01')
            ->set('dueDate', '2026-03-20')
            ->set('partnerId', (string) $noTerms->id)
            ->assertSet('dueDate', '2026-03-20');
    }
}

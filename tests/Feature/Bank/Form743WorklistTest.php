<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\Form743Worklist;
use App\Models\Company;
use App\Models\Form743;
use App\Models\User;
use App\Support\CompanyType;
use App\Support\Form743Status;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Form743WorklistTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
        Role::findOrCreate('accountant');
        Role::findOrCreate('client');
    }

    private function individual(): Company
    {
        return Company::factory()->create(['type' => CompanyType::INDIVIDUAL]);
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole('admin');

        return $user;
    }

    /**
     * Ова е поентата на екранот: обрасците од сите клиенти на едно место.
     * Ако некогаш се врзе за една фирма, канцеларијата ќе мора да отвора
     * дваесет екрани за да види што чека.
     */
    public function test_the_worklist_gathers_pending_forms_from_every_client(): void
    {
        $first = $this->individual();
        $second = $this->individual();
        Form743::factory()->for($first)->create();
        Form743::factory()->for($second)->create();
        $this->actingAs($this->admin());

        Livewire::test(Form743Worklist::class)
            ->assertViewHas('forms', fn ($forms) => $forms->count() === 2
                && $forms->pluck('company_id')->sort()->values()->all()
                    === collect([$first->id, $second->id])->sort()->values()->all());
    }

    public function test_a_form_already_filed_leaves_the_worklist(): void
    {
        $company = $this->individual();
        Form743::factory()->for($company)->create();
        Form743::factory()->for($company)->filed()->create();
        $this->actingAs($this->admin());

        Livewire::test(Form743Worklist::class)
            ->assertViewHas('forms', fn ($forms) => $forms->count() === 1);
    }

    /**
     * Најстариот прв — тој е најблиску до рокот за пријава.
     */
    public function test_the_oldest_upload_comes_first(): void
    {
        $company = $this->individual();
        $newer = Form743::factory()->for($company)->create(['created_at' => now()->subDay()]);
        $older = Form743::factory()->for($company)->create(['created_at' => now()->subMonth()]);
        $this->actingAs($this->admin());

        Livewire::test(Form743Worklist::class)
            ->assertViewHas('forms', fn ($forms) => $forms->first()->id === $older->id
                && $forms->last()->id === $newer->id);
    }

    /**
     * Сметководителот ги гледа само своите клиенти. Без ова, работниот список
     * би бил единственото место каде туѓи приходи се гледаат сосема слободно.
     */
    public function test_an_accountant_sees_only_the_clients_they_keep(): void
    {
        $mine = $this->individual();
        $theirs = $this->individual();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $mine->accountants()->attach($accountant);
        Form743::factory()->for($mine)->create();
        Form743::factory()->for($theirs)->create();
        $this->actingAs($accountant);

        Livewire::test(Form743Worklist::class)
            ->assertViewHas('forms', fn ($forms) => $forms->count() === 1
                && $forms->first()->company_id === $mine->id);
    }

    public function test_a_client_cannot_open_the_worklist(): void
    {
        $company = $this->individual();
        $client = User::factory()->create(['company_id' => $company->id]);
        $client->assignRole('client');
        $this->actingAs($client);

        $this->get(route('form743.worklist'))->assertForbidden();
    }

    public function test_an_empty_processing_form_is_refused(): void
    {
        $company = $this->individual();
        $form = Form743::factory()->for($company)->create();
        $this->actingAs($this->admin());

        Livewire::test(Form743Worklist::class)
            ->call('edit', $form->id)
            ->call('save')
            ->assertHasErrors(['payer', 'amount', 'currency', 'paymentDate', 'basis']);

        $this->assertSame(Form743Status::PENDING, $form->fresh()->status);
    }

    /**
     * Полна форма ја затвора задачата: податоците од образецот се запишуваат,
     * состојбата станува „внесен" и се знае кој и кога ја внел пријавата.
     */
    public function test_a_complete_form_closes_the_task(): void
    {
        $company = $this->individual();
        $form = Form743::factory()->for($company)->create();
        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(Form743Worklist::class)
            ->call('edit', $form->id)
            ->set('payer', 'UPWORK GLOBAL INC')
            ->set('amount', '61500.00')
            ->set('currency', 'eur')
            ->set('paymentDate', '2026-03-10')
            ->set('basis', 'Услуги извршени во странство')
            ->call('save')
            ->assertHasNoErrors()
            ->assertViewHas('forms', fn ($forms) => $forms->isEmpty());

        $form = $form->fresh();

        $this->assertSame(Form743Status::FILED, $form->status);
        $this->assertSame('UPWORK GLOBAL INC', $form->payer);
        $this->assertSame('61500.00', $form->amount);
        $this->assertSame('EUR', $form->currency);
        $this->assertSame('2026-03-10', $form->payment_date->toDateString());
        $this->assertSame('Услуги извршени во странство', $form->basis);
        $this->assertSame($admin->id, $form->filed_by);
        $this->assertNotNull($form->filed_at);
    }

    /**
     * Датум во иднина на потврда за веќе примена исплата значи испечатена
     * грешка — најчесто погрешна година.
     */
    public function test_a_payment_date_in_the_future_is_refused(): void
    {
        $company = $this->individual();
        $form = Form743::factory()->for($company)->create();
        $this->actingAs($this->admin());

        Livewire::test(Form743Worklist::class)
            ->call('edit', $form->id)
            ->set('payer', 'UPWORK GLOBAL INC')
            ->set('amount', '100')
            ->set('currency', 'EUR')
            ->set('paymentDate', now()->addDay()->toDateString())
            ->set('basis', 'Услуги')
            ->call('save')
            ->assertHasErrors('paymentDate');

        $this->assertSame(Form743Status::PENDING, $form->fresh()->status);
    }

    /**
     * Id испратено рачно не смее да стигне до туѓ клиент — списокот е филтриран,
     * ама повикот доаѓа од прелистувачот.
     */
    public function test_an_accountant_cannot_reach_a_form_outside_their_clients(): void
    {
        $theirs = $this->individual();
        $form = Form743::factory()->for($theirs)->create();
        $accountant = User::factory()->create();
        $accountant->assignRole('accountant');
        $this->actingAs($accountant);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(Form743Worklist::class)->call('edit', $form->id);
    }
}

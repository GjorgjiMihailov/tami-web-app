<?php

namespace Tests\Feature\Bank;

use App\Livewire\Bank\Form743Upload;
use App\Models\Company;
use App\Models\Form743;
use App\Models\User;
use App\Support\CompanyType;
use App\Support\Form743Status;
use App\Support\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Form743UploadTest extends TestCase
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

    private function clientOf(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);
        $user->assignRole('client');

        return $user;
    }

    /**
     * Ова е целата работа на клиентот: еден фајл влегува, еден необработен
     * запис излегува. Ниту едно поле од образецот не смее да се појави —
     * канцеларијата ги чита од самиот образец.
     */
    public function test_an_upload_creates_a_pending_form_with_the_file_attached(): void
    {
        Storage::fake('google');
        $company = $this->individual();
        $this->actingAs($this->clientOf($company));

        Livewire::test(Form743Upload::class, ['company' => $company])
            ->set('newFile', UploadedFile::fake()->create('743-januari.pdf', 40))
            ->call('upload')
            ->assertHasNoErrors();

        $form = Form743::where('company_id', $company->id)->firstOrFail();

        $this->assertSame(Form743Status::PENDING, $form->status);
        $this->assertNull($form->payer);
        $this->assertNull($form->amount);
        $this->assertNull($form->payment_date);

        $document = $form->documents()->firstOrFail();
        $this->assertSame('743-januari.pdf', $document->original_filename);
        $this->assertSame($company->id, $document->company_id);
        Storage::disk('google')->assertExists($document->path);
    }

    public function test_an_upload_without_a_file_is_refused(): void
    {
        Storage::fake('google');
        $company = $this->individual();
        $this->actingAs($this->clientOf($company));

        Livewire::test(Form743Upload::class, ['company' => $company])
            ->call('upload')
            ->assertHasErrors('newFile');

        $this->assertSame(0, Form743::count());
    }

    /**
     * Списокот е на еден клиент. Ако некогаш се откачи од фирмата, туѓи приходи
     * од странство ќе се гледаат на погрешен екран.
     */
    public function test_the_list_shows_only_this_companys_forms(): void
    {
        $company = $this->individual();
        $other = $this->individual();
        Form743::factory()->for($company)->create();
        Form743::factory()->for($other)->create();
        $this->actingAs($this->clientOf($company));

        Livewire::test(Form743Upload::class, ['company' => $company])
            ->assertViewHas('forms', fn ($forms) => $forms->count() === 1
                && $forms->first()->company_id === $company->id);
    }

    public function test_the_client_can_download_the_form_they_uploaded(): void
    {
        Storage::fake('google');
        $company = $this->individual();
        $this->actingAs($this->clientOf($company));

        Livewire::test(Form743Upload::class, ['company' => $company])
            ->set('newFile', UploadedFile::fake()->create('743.pdf', 10))
            ->call('upload');

        $form = Form743::where('company_id', $company->id)->firstOrFail();

        $this->get(route('form743.download', [$company, $form]))->assertOk();
    }

    /**
     * Ставката во менито мора да води на екранот, не на страницата „наскоро".
     */
    public function test_the_menu_points_at_the_screen_instead_of_the_coming_soon_page(): void
    {
        $company = $this->individual();
        $user = $this->clientOf($company);

        $items = collect(Menu::for($user, $company))
            ->firstWhere('key', 'bank')['items'];

        $item = collect($items)->firstWhere('label', '743 обрасци');

        $this->assertNotNull($item);
        $this->assertFalse($item['soon'] ?? false);
        $this->assertSame(route('form743.index', $company), $item['url']);
    }
}

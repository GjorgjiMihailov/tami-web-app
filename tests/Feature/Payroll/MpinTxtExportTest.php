<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\User;
use App\Support\Payroll\Mpin\MpinTxtBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Payroll\Concerns\BuildsMpinRuns;
use Tests\TestCase;

class MpinTxtExportTest extends TestCase
{
    use BuildsMpinRuns, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('admin');
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /** @return array<string, string> */
    private function profile(): array
    {
        return [
            'registration_number' => '1234567',
            'street_address' => 'ЌУПРИЈА',
            'street_number' => '1/2',
            'city' => 'Охрид',
            'postal_code' => '6000',
            'payroll_municipality_code' => '152',
            'payroll_authorized_person' => 'Петар Петровски',
        ];
    }

    public function test_a_confirmed_run_downloads_as_windows_1251_text(): void
    {
        $run = $this->mpinRun($this->profile());

        $response = $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export-txt', [$run->company, $run]));

        $response->assertOk();
        $this->assertStringContainsString('windows-1251', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString(
            'MPI3_4080000000000_2026_05_101_110.txt',
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertSame(MpinTxtBuilder::build($run), $response->getContent());
    }

    public function test_the_export_is_recorded(): void
    {
        $run = $this->mpinRun($this->profile());
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('payroll.mpin-export-txt', [$run->company, $run]));

        $run->refresh();
        $this->assertNotNull($run->mpin_exported_at);
        $this->assertSame($admin->id, $run->mpin_exported_by);
    }

    public function test_a_company_profile_without_the_txt_fields_does_not_download(): void
    {
        $run = $this->mpinRun([...$this->profile(), 'registration_number' => null]);

        $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export-txt', [$run->company, $run]))
            ->assertRedirect();

        $this->assertNull($run->fresh()->mpin_exported_at);
    }

    public function test_the_screen_explains_what_is_missing_in_the_profile(): void
    {
        $run = $this->mpinRun([...$this->profile(), 'registration_number' => null]);

        $this->actingAs($this->admin())
            ->get(route('payroll-runs.show', [$run->company, $run]))
            ->assertOk()
            ->assertSee('МПИН .txt не е можен')
            ->assertSee('матичен број');
    }

    public function test_the_screen_offers_both_files_when_everything_is_in_place(): void
    {
        $run = $this->mpinRun($this->profile());

        $this->actingAs($this->admin())
            ->get(route('payroll-runs.show', [$run->company, $run]))
            ->assertOk()
            ->assertSee(route('payroll.mpin-export', [$run->company, $run]), false)
            ->assertSee(route('payroll.mpin-export-txt', [$run->company, $run]), false);
    }

    public function test_a_run_from_another_company_is_not_reachable(): void
    {
        $run = $this->mpinRun($this->profile());
        $other = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export-txt', [$other, $run]))
            ->assertNotFound();
    }
}

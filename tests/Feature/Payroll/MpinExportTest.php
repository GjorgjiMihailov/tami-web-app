<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Feature\Payroll\Concerns\BuildsMpinRuns;
use Tests\TestCase;

class MpinExportTest extends TestCase
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

    public function test_a_confirmed_run_downloads_as_xml(): void
    {
        $run = $this->mpinRun();

        $response = $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export', [$run->company, $run]));

        $response->assertOk();

        $this->assertStringContainsString(
            'DESIGNIA DOOEL_2026_05_101.xml',
            (string) $response->headers->get('content-disposition'),
        );

        $this->assertSame(
            file_get_contents(base_path('tests/Fixtures/mpin/obvrznik-110.xml')),
            $response->getContent(),
        );
    }

    public function test_the_export_is_recorded(): void
    {
        $run = $this->mpinRun();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('payroll.mpin-export', [$run->company, $run]));

        $run->refresh();

        $this->assertNotNull($run->mpin_exported_at);
        $this->assertSame($admin->id, $run->mpin_exported_by);
    }

    public function test_a_run_with_errors_does_not_download(): void
    {
        $run = $this->mpinRun(['mpin_obvrznik_code' => null]);

        $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export', [$run->company, $run]))
            ->assertRedirect();

        $this->assertNull($run->fresh()->mpin_exported_at);
    }

    public function test_a_run_from_another_company_is_not_reachable(): void
    {
        $run = $this->mpinRun();
        $other = Company::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export', [$other, $run]))
            ->assertNotFound();
    }
}

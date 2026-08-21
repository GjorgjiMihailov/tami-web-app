<?php

namespace Tests\Feature\Payroll;

use App\Models\Company;
use App\Models\User;
use App\Support\Payroll\Mpin\MpinDocumentBuilder;
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

    /**
     * Ги извлекува двата дела на Content-Disposition: ASCII резервата од
     * filename=, и точното (можно некодирано) име декодирано од filename*.
     *
     * @return array{fallback: string, filename: string}
     */
    private function parseDisposition(string $header): array
    {
        $this->assertMatchesRegularExpression('/filename=([^;]+)/', $header);
        $this->assertMatchesRegularExpression('/filename\*=UTF-8\'\'([^;]+)/i', $header);

        preg_match('/filename=([^;]+)/', $header, $fallbackMatch);
        preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $header, $filenameMatch);

        return [
            'fallback' => trim($fallbackMatch[1]),
            'filename' => rawurldecode(trim($filenameMatch[1])),
        ];
    }

    public function test_a_confirmed_run_downloads_as_xml(): void
    {
        $run = $this->mpinRun();

        $response = $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export', [$run->company, $run]));

        $response->assertOk();

        $parts = $this->parseDisposition((string) $response->headers->get('content-disposition'));

        // Името е ASCII без наводници, па филпинг директно во RFC 5987
        // енкодирањето мора да го врати истото име по декодирање.
        $this->assertSame('DESIGNIA DOOEL_2026_05_101.xml', $parts['filename']);
        $this->assertSame('mpin-2026-05-101.xml', $parts['fallback']);

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

    public function test_a_cyrillic_company_name_survives_the_disposition_header(): void
    {
        $run = $this->mpinRun(['name' => 'ФАЈНЕНС БАДИ ДООЕЛ СКОПЈЕ']);

        $response = $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export', [$run->company, $run]));

        $response->assertOk();

        $parts = $this->parseDisposition((string) $response->headers->get('content-disposition'));

        // Точното име, со кирилица, мора да преживее непроменето во
        // филнеим*=UTF-8''... делот на заглавието.
        $this->assertSame(MpinDocumentBuilder::fileName($run), $parts['filename']);

        // ASCII резервата мора да е чисто ASCII — RFC 6266 бара тоа, а
        // Symfony ќе фрли исклучок ако не е, но проверуваме и експлицитно
        // дека не протекла сурова кирилица во таа страна на заглавието.
        $this->assertMatchesRegularExpression('/^[\x20-\x7e]*$/', $parts['fallback']);
        $this->assertSame('mpin-2026-05-101.xml', $parts['fallback']);
    }

    public function test_a_quote_in_the_company_name_does_not_break_the_disposition_header(): void
    {
        $run = $this->mpinRun(['name' => 'АБЦ "ДОО" ДООЕЛ']);

        $response = $this->actingAs($this->admin())
            ->get(route('payroll.mpin-export', [$run->company, $run]));

        $response->assertOk();

        $header = (string) $response->headers->get('content-disposition');

        // Со само една заглавна линија присутна за responsе, ако наводникот
        // би излегол некодиран, header-от би бил прекинат/невалиден. Ова е
        // индиректна проверка дека PHP/Symfony прифаќаат единствена, валидна
        // вредност на заглавието.
        $this->assertNotFalse($header);
        $this->assertSame(1, substr_count($header, 'filename*='));

        $parts = $this->parseDisposition($header);

        $this->assertSame(MpinDocumentBuilder::fileName($run), $parts['filename']);
        $this->assertMatchesRegularExpression('/^[\x20-\x7e]*$/', $parts['fallback']);
    }
}

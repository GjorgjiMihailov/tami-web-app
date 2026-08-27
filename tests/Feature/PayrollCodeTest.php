<?php

namespace Tests\Feature;

use App\Models\PayrollCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_seeds_all_four_codebooks(): void
    {
        $this->assertSame(86, PayrollCode::ofType('opstina')->count());
        $this->assertSame(30, PayrollCode::ofType('vid_staz')->count());
        $this->assertSame(8, PayrollCode::ofType('sifra_dviz')->count());
        $this->assertSame(10, PayrollCode::ofType('osloboduvanje')->count());
    }

    public function test_it_keeps_zero_padded_codes_as_strings(): void
    {
        // 0050 "Време поминато во работен однос со полно работно време" is the
        // ordinary full-time code and the one nearly every employee carries.
        // Stored as an integer it would become "50" and МПИН would reject it.
        $code = PayrollCode::ofType('vid_staz')->firstWhere('code', '0050');

        $this->assertNotNull($code, 'The full-time insurance code 0050 is missing.');
        $this->assertSame('Време поминато во работен однос со полно работно време', $code->name);
    }

    public function test_it_exposes_a_known_municipality(): void
    {
        $this->assertSame(
            'АЕРОДРОМ',
            PayrollCode::ofType('opstina')->firstWhere('code', '175')?->name
        );
    }

    public function test_it_seeds_the_working_hour_types(): void
    {
        $codes = PayrollCode::ofType('rab_cas');

        $this->assertCount(60, $codes);
        $this->assertSame('Редовни работни часови', $codes->firstWhere('code', '001')->name);
        $this->assertSame('Годишен одмор', $codes->firstWhere('code', '009')->name);
    }

    public function test_it_seeds_the_compensation_types(): void
    {
        $codes = PayrollCode::ofType('vid_nadomestoci');

        $this->assertCount(17, $codes);
        $this->assertStringContainsString('ФЗО', $codes->firstWhere('code', '129')->name);
    }

    public function test_the_two_codebooks_share_no_code(): void
    {
        // The line editor puts both codebooks into one SifraTipRabotenCas
        // dropdown. That is only sound while they do not collide.
        $hours = PayrollCode::ofType('rab_cas')->pluck('code');
        $compensations = PayrollCode::ofType('vid_nadomestoci')->pluck('code');

        $this->assertEmpty($hours->intersect($compensations));
    }

    public function test_the_obvrznik_codebook_is_seeded(): void
    {
        $codes = PayrollCode::ofType('vid_obvrznik');

        $this->assertGreaterThan(0, $codes->count());
        $this->assertSame('Работодавач, правно лице', $codes->firstWhere('code', '110')?->name);
        $this->assertStringContainsString(
            'Самостоен вршител',
            (string) $codes->firstWhere('code', '111')?->name,
        );
    }

    public function test_the_health_area_codebook_is_seeded(): void
    {
        $codes = PayrollCode::ofType('podracno_zdravstvo');

        $this->assertGreaterThan(0, $codes->count());
        $this->assertSame('Скопје', $codes->firstWhere('code', '4061')?->name);
    }
}

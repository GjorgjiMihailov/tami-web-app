<?php

namespace Tests\Feature\Ppf;

use App\Models\Company;
use App\Models\PpfCalculation;
use App\Models\PpfPayee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Исплатите внесени пред пресметките (групирани по датум) мора да преминат во пресметки без губење. */
class PpfCalculationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_payments_become_one_calculation_per_company_and_date_numbered_per_year(): void
    {
        $migration = require base_path('database/migrations/2026_10_26_100000_create_ppf_calculations.php');
        $migration->down();

        $this->assertTrue(Schema::hasColumn('ppf_payments', 'exported_at'));
        $this->assertFalse(Schema::hasTable('ppf_calculations'));

        $a = Company::factory()->create();
        $b = Company::factory()->create();
        $payeeA = PpfPayee::factory()->create(['company_id' => $a->id]);
        $payeeB = PpfPayee::factory()->create(['company_id' => $b->id]);

        $row = fn (Company $company, PpfPayee $payee, string $date, ?string $exported = null) => DB::table('ppf_payments')->insertGetId([
            'company_id' => $company->id, 'ppf_payee_id' => $payee->id, 'type_income_code' => 'T1', 'subtype_income_code' => '1014',
            'gross_income' => 1000, 'payment_date' => $date, 'payment_type_code' => '00001', 'exported_at' => $exported,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $later = $row($a, $payeeA, '2026-03-05', '2026-03-06 10:00:00');
        $sameDay = $row($a, $payeeA, '2026-03-05');
        $earlier = $row($a, $payeeA, '2026-01-10');
        $nextYear = $row($a, $payeeA, '2027-01-02');
        $other = $row($b, $payeeB, '2026-03-05');

        $migration->up();

        $this->assertTrue(Schema::hasTable('ppf_calculations'));
        $this->assertFalse(Schema::hasColumn('ppf_payments', 'exported_at'));
        $this->assertFalse(Schema::hasColumn('ppf_payments', 'exported_by'));

        $calc = fn (int $paymentId) => PpfCalculation::findOrFail(DB::table('ppf_payments')->where('id', $paymentId)->value('ppf_calculation_id'));

        // Исти датум и фирма → иста пресметка; нумерација по датум во годината.
        $this->assertSame($calc($later)->id, $calc($sameDay)->id);
        $this->assertSame(1, $calc($earlier)->number);
        $this->assertSame(2, $calc($later)->number);
        $this->assertSame(1, $calc($nextYear)->number);
        $this->assertSame(2027, $calc($nextYear)->year);
        $this->assertSame(1, $calc($other)->number);
        $this->assertNotSame($calc($other)->id, $calc($later)->id);
        $this->assertSame('2026-03-05', $calc($later)->period_date->toDateString());
        $this->assertSame(4, PpfCalculation::where('company_id', $a->id)->count() + 1);
        $this->assertNotNull($calc($later)->exported_at);
        $this->assertNull($calc($earlier)->exported_at);
    }
}

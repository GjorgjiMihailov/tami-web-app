<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppf_calculations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // „Пресметка бр. N“ во годината на датумот на пресметката — како нумерацијата во MyGPM.
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('number');
            // periodfrom = periodto во XML; основно денот на исплатата, но се поднесува и подоцна.
            $table->date('period_date');
            $table->timestamp('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'year', 'number'], 'ppf_calc_company_year_number_unique');
        });

        Schema::table('ppf_payments', function (Blueprint $table) {
            $table->foreignId('ppf_calculation_id')->nullable()->after('company_id')->constrained('ppf_calculations')->cascadeOnDelete();
        });

        $this->backfill();

        // Печатот на извозот сега е на пресметката, не на исплатата.
        Schema::table('ppf_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exported_by');
            $table->dropColumn('exported_at');
        });
    }

    public function down(): void
    {
        Schema::table('ppf_payments', function (Blueprint $table) {
            $table->timestamp('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dropConstrainedForeignId('ppf_calculation_id');
        });

        Schema::dropIfExists('ppf_calculations');
    }

    /** Досегашното групирање (по датум на исплата) станува по една пресметка за датум, нумерирани по датум во годината. */
    private function backfill(): void
    {
        $payments = DB::table('ppf_payments')->orderBy('company_id')->orderBy('payment_date')->orderBy('id')->get();
        $counters = [];
        $calculations = [];

        foreach ($payments as $payment) {
            $key = $payment->company_id.'|'.$payment->payment_date;
            $year = (int) substr((string) $payment->payment_date, 0, 4);

            if (! isset($calculations[$key])) {
                $counter = $payment->company_id.'|'.$year;
                $counters[$counter] = ($counters[$counter] ?? 0) + 1;

                $calculations[$key] = DB::table('ppf_calculations')->insertGetId([
                    'company_id' => $payment->company_id,
                    'year' => $year,
                    'number' => $counters[$counter],
                    'period_date' => $payment->payment_date,
                    'exported_at' => $payment->exported_at,
                    'exported_by' => $payment->exported_by,
                    'created_by' => $payment->created_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('ppf_payments')->where('id', $payment->id)->update(['ppf_calculation_id' => $calculations[$key]]);
        }
    }
};

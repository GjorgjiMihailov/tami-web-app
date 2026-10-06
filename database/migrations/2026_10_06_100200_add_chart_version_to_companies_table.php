<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Отпечаток на предложениот контен план што фирмата го има преземено.
            $table->string('chart_version', 40)->nullable();
        });

        // Досегашните фирми беа усогласувани при секое пуштање, па се на
        // тековниот план — без ова сите би добиле лажна понуда „нова верзија“.
        $current = sha1_file(base_path('docs/reference/official-chart-of-accounts.json'));
        DB::table('companies')->update(['chart_version' => $current]);
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('chart_version');
        });
    }
};

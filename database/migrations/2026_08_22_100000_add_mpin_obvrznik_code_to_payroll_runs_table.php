<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Видот обврзник по кој е пресметана оваа пресметка.
     *
     * Истата шифра стои и на фирмата, но таа е слободно променлива во секој
     * момент, а потврдена пресметка одбива да се пресмета повторно. Без копија
     * на самата пресметка, извозот би читал една шифра, а цифрите би биле
     * пресметани по друга — заглавие 111 врз придонес за вработување и данок
     * од 110.
     *
     * Nullable зашто редовите отворени пред оваа миграција немаат што да
     * носат. Читачите паѓаат на 110, што е точно она по што тие се
     * пресметани — види MpinDocumentBuilder::build().
     */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('mpin_obvrznik_code', 8)->nullable()->after('payroll_parameter_id');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn('mpin_obvrznik_code');
        });
    }
};

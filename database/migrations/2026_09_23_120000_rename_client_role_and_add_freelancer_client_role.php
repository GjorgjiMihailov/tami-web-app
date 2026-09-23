<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // UPDATE, не delete+insert — секој веќе доделен корисник е поврзан
        // преку role_id во model_has_roles, не преку името. Ова е единствениот
        // начин преименувањето да не бара миграција на луѓе.
        $guard = DB::table('roles')->where('name', 'admin')->value('guard_name') ?? 'web';

        DB::table('roles')
            ->where('name', 'client')
            ->update(['name' => 'internal_client', 'updated_at' => now()]);

        if (! DB::table('roles')->where('name', 'freelancer_client')->exists()) {
            DB::table('roles')->insert([
                'name' => 'freelancer_client',
                'guard_name' => $guard,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'internal_client')->update(['name' => 'client', 'updated_at' => now()]);
        DB::table('roles')->where('name', 'freelancer_client')->delete();
    }
};

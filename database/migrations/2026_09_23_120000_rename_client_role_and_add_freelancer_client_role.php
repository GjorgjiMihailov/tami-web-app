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

        // Правило: улогата следи од типот на фирмата. Постојните корисници на
        // фирми од физички лица (companies.type = individual) до сега беа
        // „client" — сега мора да станат freelancer_client, не internal_client.
        // Без филтер на model_type (морф-мапата може да чува друг стринг;
        // улоги држат само корисници). Повторно извршување не менува ништо,
        // бидејќи бара редови што сè уште се на internal_client.
        $internal = DB::table('roles')->where('name', 'internal_client')->value('id');
        $freelancer = DB::table('roles')->where('name', 'freelancer_client')->value('id');

        if ($internal && $freelancer) {
            $userIds = DB::table('users')
                ->whereIn('company_id', DB::table('companies')->where('type', 'individual')->select('id'))
                ->pluck('id');

            DB::table('model_has_roles')
                ->where('role_id', $internal)
                ->whereIn('model_id', $userIds)
                ->update(['role_id' => $freelancer]);
        }
    }

    public function down(): void
    {
        // Најпрво ги враќаме корисниците од freelancer_client на internal_client
        // (кое се преименува во client), па дури потоа ја бришеме улогата —
        // инаку корисниците би останале без улога.
        $internal = DB::table('roles')->where('name', 'internal_client')->value('id');
        $freelancer = DB::table('roles')->where('name', 'freelancer_client')->value('id');

        if ($internal && $freelancer) {
            DB::table('model_has_roles')->where('role_id', $freelancer)->update(['role_id' => $internal]);
        }
        if ($freelancer) {
            DB::table('model_has_roles')->where('role_id', $freelancer)->delete();
            DB::table('roles')->where('id', $freelancer)->delete();
        }

        DB::table('roles')->where('name', 'internal_client')->update(['name' => 'client', 'updated_at' => now()]);
    }
};

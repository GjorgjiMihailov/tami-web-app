<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Task 1 (2026_07_22_090000) registered a strict morph map via
     * Relation::enforceMorphMap() in AppServiceProvider, but only for the
     * Document-related models (purchase_invoice, sales_invoice,
     * journal_entry, partner). This broke spatie/permission's polymorphic
     * model_has_roles / model_has_permissions pivots, which morph against
     * App\Models\User: any new role/permission assignment threw
     * "No morph map defined for model [App\Models\User]." because User had
     * no registered alias.
     *
     * The fix (in AppServiceProvider) registers 'user' => App\Models\User.
     * This migration normalizes any pre-existing rows that were written
     * before the fix, which stored the full class name, so lookups against
     * the new 'user' alias continue to find them.
     */
    public function up(): void
    {
        DB::table('model_has_roles')
            ->where('model_type', 'App\\Models\\User')
            ->update(['model_type' => 'user']);

        DB::table('model_has_permissions')
            ->where('model_type', 'App\\Models\\User')
            ->update(['model_type' => 'user']);
    }

    public function down(): void
    {
        DB::table('model_has_roles')
            ->where('model_type', 'user')
            ->update(['model_type' => 'App\\Models\\User']);

        DB::table('model_has_permissions')
            ->where('model_type', 'user')
            ->update(['model_type' => 'App\\Models\\User']);
    }
};

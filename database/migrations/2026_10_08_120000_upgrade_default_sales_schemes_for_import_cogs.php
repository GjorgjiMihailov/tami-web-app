<?php

use App\Models\Account;
use App\Models\PostingScheme;
use App\Services\Posting\DefaultPostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Стариот стандарден излезен налог: исти редови како во дел 1 (6600 Побарува НАБАВНА_ВРЕДНОСТ). */
    private function oldRows(): array
    {
        return [
            ['fixed', '1200', null, 'debit', 'ВКУПНО', true, '{фактура}', null],
            ['matrix', null, 'revenue', 'credit', 'ОСНОВИЦА', true, '{фактура}', null],
            ['matrix', null, 'output_vat', 'credit', 'ДДВ', true, 'VAT on {фактура}', null],
            ['fixed', '7010', null, 'debit', 'НАБАВНА_ВРЕДНОСТ', false, 'COGS for {фактура}', 'has_goods'],
            ['fixed', '6600', null, 'credit', 'НАБАВНА_ВРЕДНОСТ', false, 'COGS for {фактура}', 'has_goods'],
        ];
    }

    public function up(): void
    {
        $new = DefaultPostingSchemes::definition(PostingDocType::SALES_INVOICE)['rows'];

        foreach (PostingScheme::where('doc_type', 'sales_invoice')->with('rows.account', 'company')->get() as $scheme) {
            $current = $scheme->rows->map(fn ($r) => [
                $r->account_mode, $r->account?->code, $r->matrix_key, $r->side, $r->formula, (bool) $r->with_partner, $r->description, $r->condition,
            ])->all();

            if ($current !== $this->oldRows()) {
                continue; // менувана од сметководител — не се допира
            }

            $six = Account::where('company_id', $scheme->company_id)->where('is_analytical', true)->where('code', '6601')->first();
            if ($six === null) {
                continue;
            }

            DB::transaction(function () use ($scheme, $new) {
                $scheme->rows()->where('account_mode', 'fixed')->where('formula', 'НАБАВНА_ВРЕДНОСТ')->where('side', 'credit')->update(['formula' => 'НАБАВНА_ДОМАШНА']);

                $scheme->rows()->create([
                    'position' => count($new),
                    'account_mode' => 'fixed',
                    'account_id' => Account::where('company_id', $scheme->company_id)->where('code', '6601')->value('id'),
                    'side' => 'credit',
                    'formula' => 'НАБАВНА_УВОЗ',
                    'with_partner' => false,
                    'description' => 'COGS for {фактура}',
                    'condition' => 'has_goods',
                ]);
            });
        }
    }

    public function down(): void {}
};

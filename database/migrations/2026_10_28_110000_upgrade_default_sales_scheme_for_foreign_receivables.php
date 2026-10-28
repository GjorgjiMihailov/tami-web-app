<?php

use App\Models\Account;
use App\Models\PostingScheme;
use App\Services\Posting\DefaultPostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Стандардниот излезен налог пред девизните побарувања (1200 без услов). */
    private function oldRows(): array
    {
        return [
            ['fixed', '1200', null, 'debit', 'ВКУПНО', true, '{фактура}', null],
            ['matrix', null, 'revenue', 'credit', 'ОСНОВИЦА', true, '{фактура}', null],
            ['matrix', null, 'output_vat', 'credit', 'ДДВ', true, 'VAT on {фактура}', null],
            ['fixed', '7010', null, 'debit', 'НАБАВНА_ВРЕДНОСТ', false, 'COGS for {фактура}', 'has_goods'],
            ['fixed', '6600', null, 'credit', 'НАБАВНА_ДОМАШНА', false, 'COGS for {фактура}', 'has_goods'],
            ['fixed', '6601', null, 'credit', 'НАБАВНА_УВОЗ', false, 'COGS for {фактура}', 'has_goods'],
        ];
    }

    public function up(): void
    {
        $new = DefaultPostingSchemes::definition(PostingDocType::SALES_INVOICE)['rows'];

        foreach (PostingScheme::where('doc_type', 'sales_invoice')->with('rows.account', 'company')->get() as $scheme) {
            $current = $scheme->rows->sortBy('position')->map(fn ($r) => [
                $r->account_mode, $r->account?->code, $r->matrix_key, $r->side, $r->formula, (bool) $r->with_partner, $r->description, $r->condition,
            ])->values()->all();

            // Менувана од сметководител (или веќе надградена) — не се допира.
            if ($current !== $this->oldRows()) {
                continue;
            }

            $accounts = Account::where('company_id', $scheme->company_id)->where('is_analytical', true)->whereIn('code', ['1210', '1211'])->count();

            if ($accounts < 2) {
                continue;
            }

            // Фирма на која и едно од стандардните конта не е аналитичко се прескокнува
            // (шемата останува непроменета, девизната фактура паѓа на 1200): една
            // таква фирма не смее да ја запре миграцијата, а со неа и пуштањето.
            try {
                DB::transaction(function () use ($scheme, $new) {
                    $scheme->rows()->delete();
                    DefaultPostingSchemes::populateRows($scheme, $scheme->company, $new);
                });
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function down(): void {}
};

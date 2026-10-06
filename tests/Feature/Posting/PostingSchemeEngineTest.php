<?php

namespace Tests\Feature\Posting;

use App\Exceptions\PostingSchemeException;
use App\Models\Account;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\PostingSchemeMatrixAccount;
use App\Models\PostingSchemeRow;
use App\Services\Posting\PostingSchemeEngine;
use App\Support\Posting\ItemKind;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingMatrix;
use App\Support\Posting\PostingSlice;
use App\Support\Posting\VatGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingSchemeEngineTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PostingScheme $scheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->scheme = PostingScheme::create(['company_id' => $this->company->id, 'doc_type' => PostingDocType::SALES_INVOICE, 'name' => 't']);
    }

    private function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function row(int $position, string $mode, string $side, string $formula, array $extra = []): PostingSchemeRow
    {
        return PostingSchemeRow::create(array_merge([
            'posting_scheme_id' => $this->scheme->id, 'position' => $position, 'account_mode' => $mode,
            'side' => $side, 'formula' => $formula,
        ], $extra));
    }

    private function matrix(string $key, ?string $kind, string $group, string $code): void
    {
        PostingSchemeMatrixAccount::create(['posting_scheme_id' => $this->scheme->id, 'matrix_key' => $key, 'item_kind' => $kind, 'vat_group' => $group, 'account_id' => $this->account($code)->id]);
    }

    private function context(array $totals, array $slices = [], array $flags = [], array $extra = []): PostingContext
    {
        return new PostingContext(...array_merge([
            'totals' => $totals,
            'slices' => $slices,
            'flags' => $flags + ['has_goods' => false, 'cash' => false, 'import' => false],
            'partnerId' => 7,
            'documentLabel' => 'Invoice 5',
        ], $extra));
    }

    private function engine(): PostingSchemeEngine
    {
        return new PostingSchemeEngine;
    }

    public function test_fixed_rows_post_the_formula_amount_with_partner_and_description(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id, 'with_partner' => true, 'description' => '{фактура}']);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74000')->id, 'description' => 'Приход']);

        $lines = $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '118.00']));

        $this->assertCount(2, $lines);
        $this->assertSame('1200', $lines[0]->account->code);
        $this->assertSame('debit', $lines[0]->side);
        $this->assertSame('118.00', $lines[0]->amount);
        $this->assertSame(7, $lines[0]->partnerId);
        $this->assertSame('Invoice 5', $lines[0]->description);
        $this->assertNull($lines[1]->partnerId);
    }

    public function test_a_matrix_row_repeats_per_slice_and_reads_the_right_account(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id]);
        $this->row(2, 'matrix', 'credit', 'ОСНОВИЦА', ['matrix_key' => PostingMatrix::REVENUE]);
        $this->row(3, 'matrix', 'credit', 'ДДВ', ['matrix_key' => PostingMatrix::OUTPUT_VAT]);
        $this->matrix(PostingMatrix::REVENUE, 'service', 'general', '74000');
        $this->matrix(PostingMatrix::REVENUE, 'goods', 'reduced', '74101');
        $this->matrix(PostingMatrix::OUTPUT_VAT, null, 'general', '2300');
        $this->matrix(PostingMatrix::OUTPUT_VAT, null, 'reduced', '2301');

        $slices = [
            new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '100.00', '18.00'),
            new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '200.00', '10.00'),
            new PostingSlice(ItemKind::GOODS, VatGroup::GENERAL, '0.00', '0.00'),
        ];
        // ВКУПНО = 100 + 18 + 200 + 10
        $lines = $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '328.00'], $slices));

        $byAccount = collect($lines)->keyBy(fn ($l) => $l->account->code);
        $this->assertSame('100.00', $byAccount['74000']->amount);
        $this->assertSame('200.00', $byAccount['74101']->amount);
        $this->assertSame('18.00', $byAccount['2300']->amount);
        $this->assertSame('10.00', $byAccount['2301']->amount);
        $this->assertCount(5, $lines);
    }

    public function test_the_vat_matrix_is_read_by_group_and_sums_goods_and_services(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id]);
        $this->row(2, 'fixed', 'credit', 'ОСНОВИЦА', ['account_id' => $this->account('74000')->id]);
        $this->row(3, 'matrix', 'credit', 'ДДВ', ['matrix_key' => PostingMatrix::OUTPUT_VAT]);
        $this->matrix(PostingMatrix::OUTPUT_VAT, null, 'general', '2300');
        $slices = [
            new PostingSlice(ItemKind::GOODS, VatGroup::GENERAL, '50.00', '9.00'),
            new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '50.00', '9.00'),
        ];

        $lines = $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '118.00', 'ОСНОВИЦА' => '100.00'], $slices));

        $vat = collect($lines)->firstWhere(fn ($l) => $l->account->code === '2300');
        $this->assertSame('18.00', $vat->amount);
    }

    public function test_conditions_skip_rows(): void
    {
        // Секој услов има свој балансиран пар, па секоја комбинација поминува баланс.
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id]);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74000')->id]);
        $this->row(3, 'fixed', 'debit', 'НАБАВНА_ВРЕДНОСТ', ['account_id' => $this->account('7010')->id, 'condition' => 'has_goods']);
        $this->row(4, 'fixed', 'credit', 'НАБАВНА_ВРЕДНОСТ', ['account_id' => $this->account('6600')->id, 'condition' => 'has_goods']);
        $this->row(5, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1020')->id, 'condition' => 'cash']);
        $this->row(6, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74001')->id, 'condition' => 'cash']);
        $this->row(7, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1000')->id, 'condition' => 'not_cash']);
        $this->row(8, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74002')->id, 'condition' => 'not_cash']);

        $totals = ['ВКУПНО' => '10.00', 'НАБАВНА_ВРЕДНОСТ' => '4.00'];
        $codes = fn (array $lines) => collect($lines)->map(fn ($l) => $l->account->code)->sort()->values()->all();

        $plain = $this->engine()->lines($this->scheme->fresh(), $this->context($totals));
        $this->assertSame(['1000', '1200', '74000', '74002'], $codes($plain));

        $goods = $this->engine()->lines($this->scheme->fresh(), $this->context($totals, [], ['has_goods' => true]));
        $this->assertSame(['1000', '1200', '6600', '7010', '74000', '74002'], $codes($goods));

        $cash = $this->engine()->lines($this->scheme->fresh(), $this->context($totals, [], ['cash' => true]));
        $this->assertSame(['1020', '1200', '74000', '74001'], $codes($cash));
    }

    public function test_an_unknown_condition_is_refused(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id, 'condition' => 'weekend']);

        $this->expectException(PostingSchemeException::class);

        $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '1.00']));
    }

    public function test_zero_amounts_are_skipped_and_negative_amounts_flip_the_side(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id]);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО - ДДВ', ['account_id' => $this->account('74000')->id]);
        $this->row(3, 'fixed', 'credit', 'ДДВ', ['account_id' => $this->account('2300')->id]);
        $this->row(4, 'fixed', 'credit', 'НЕЛА', ['account_id' => $this->account('74001')->id]);
        $this->row(5, 'fixed', 'credit', '-ПОПУСТ', ['account_id' => $this->account('7419')->id]);
        $this->row(6, 'fixed', 'credit', 'ПОПУСТ', ['account_id' => $this->account('1020')->id]);

        $lines = $this->engine()->lines($this->scheme->fresh(), $this->context([
            'ВКУПНО' => '118.00', 'ДДВ' => '18.00', 'НЕЛА' => '0.00', 'ПОПУСТ' => '5.00',
        ]));

        $accounts = collect($lines)->map(fn ($l) => $l->account->code)->all();
        $this->assertNotContains('74001', $accounts); // нула се прескокнува
        $flipped = collect($lines)->firstWhere(fn ($l) => $l->account->code === '7419');
        $this->assertSame('debit', $flipped->side); // −5 побарува = 5 должи
        $this->assertSame('5.00', $flipped->amount);
    }

    public function test_the_invoice_mode_uses_the_account_of_the_document_even_a_legacy_heading(): void
    {
        $this->row(1, 'fixed', 'debit', 'ИЗНОС', ['account_id' => $this->account('1000')->id]);
        $this->row(2, 'invoice', 'credit', 'ИЗНОС');

        // Нова фактура: аналитичко 1200; стара фактура книжена на 120 пред шемите.
        foreach (['1200', '120'] as $code) {
            $lines = $this->engine()->lines(
                $this->scheme->fresh(),
                $this->context(['ИЗНОС' => '30.00'], [], [], ['invoiceAccount' => $this->account($code)])
            );

            $this->assertSame($code, $lines[1]->account->code);
        }
    }

    public function test_the_invoice_mode_without_an_account_is_refused(): void
    {
        $this->row(1, 'invoice', 'credit', 'ИЗНОС');

        $this->expectException(PostingSchemeException::class);

        $this->engine()->lines($this->scheme->fresh(), $this->context(['ИЗНОС' => '30.00']));
    }

    public function test_an_unbalanced_scheme_is_refused(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id]);
        $this->row(2, 'fixed', 'credit', 'ОСНОВИЦА', ['account_id' => $this->account('74000')->id]);

        $this->expectException(PostingSchemeException::class);
        $this->expectExceptionMessage('не се балансира');

        $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '118.00', 'ОСНОВИЦА' => '100.00']));
    }

    public function test_a_heading_account_is_refused(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('120')->id]);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74000')->id]);

        $this->expectException(PostingSchemeException::class);
        $this->expectExceptionMessage('не е аналитичко');

        $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '1.00']));
    }

    public function test_a_slice_without_a_matrix_account_is_refused(): void
    {
        $this->row(1, 'matrix', 'credit', 'ОСНОВИЦА', ['matrix_key' => PostingMatrix::REVENUE]);

        $this->expectException(PostingSchemeException::class);
        $this->expectExceptionMessage('нема конто');

        $this->engine()->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '1.00'], [new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '1.00', '0.00')]));
    }

    public function test_foreign_columns_go_only_on_partner_rows_of_a_foreign_document(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('1200')->id, 'with_partner' => true]);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('74000')->id]);

        $context = new PostingContext(
            totals: ['ВКУПНО' => '615.12'],
            foreignTotals: ['ВКУПНО' => '10.00'],
            flags: ['has_goods' => false, 'cash' => false, 'import' => false],
            partnerId: 7,
            foreign: ['currency_code' => 'EUR', 'exchange_rate' => '61.512000'],
        );

        $lines = $this->engine()->lines($this->scheme->fresh(), $context);

        $this->assertSame('10.00', $lines[0]->foreignAmount);
        $this->assertSame('EUR', $lines[0]->currencyCode);
        $this->assertNull($lines[1]->foreignAmount);
        $columns = $lines[0]->journalColumns('2026-03-01');
        $this->assertSame('615.12', $columns['debit']);
        $this->assertSame('0', $columns['credit']);
        $this->assertSame('10.00', $columns['foreign_amount']);
        $this->assertArrayNotHasKey('foreign_amount', $lines[1]->journalColumns('2026-03-01'));
    }
}

<?php

namespace Tests\Feature\Posting;

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

class PostingSchemeEngineLineModeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PostingScheme $scheme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->scheme = PostingScheme::create(['company_id' => $this->company->id, 'doc_type' => PostingDocType::PURCHASE_INVOICE, 'name' => 't']);
    }

    private function account(string $code): Account
    {
        return Account::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    private function row(int $position, string $mode, string $side, string $formula, array $extra = []): void
    {
        PostingSchemeRow::create(array_merge([
            'posting_scheme_id' => $this->scheme->id, 'position' => $position, 'account_mode' => $mode,
            'side' => $side, 'formula' => $formula,
        ], $extra));
    }

    private function context(array $totals, array $extra = []): PostingContext
    {
        return new PostingContext(...array_merge([
            'totals' => $totals,
            'flags' => ['has_goods' => false, 'cash' => false, 'import' => false],
            'partnerId' => 9,
            'documentLabel' => 'Purchase bill X #1',
        ], $extra));
    }

    public function test_a_line_row_repeats_per_account_bucket(): void
    {
        $this->row(1, 'line', 'debit', 'ТРОШОК_СТАВКА', ['with_partner' => true, 'description' => '{фактура}']);
        $this->row(2, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('2200')->id, 'with_partner' => true]);

        $lines = (new PostingSchemeEngine)->lines($this->scheme->fresh(), $this->context(
            ['ВКУПНО' => '1500.00'],
            ['accountBuckets' => [
                ['account' => $this->account('4620'), 'amount' => '1000.00'],
                ['account' => $this->account('4621'), 'amount' => '500.00'],
            ]]
        ));

        $this->assertCount(3, $lines);
        $this->assertSame('4620', $lines[0]->account->code);
        $this->assertSame('1000.00', $lines[0]->amount);
        $this->assertSame('debit', $lines[0]->side);
        $this->assertSame(9, $lines[0]->partnerId);
        $this->assertSame('Purchase bill X #1', $lines[0]->description);
        $this->assertSame('4621', $lines[1]->account->code);
        $this->assertSame('500.00', $lines[1]->amount);
    }

    public function test_a_line_row_on_a_heading_account_is_refused(): void
    {
        $this->row(1, 'line', 'debit', 'ТРОШОК_СТАВКА');
        $this->row(2, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('2200')->id]);

        $this->expectException(\App\Exceptions\PostingSchemeException::class);
        $this->expectExceptionMessage('не е аналитичко');

        (new PostingSchemeEngine)->lines($this->scheme->fresh(), $this->context(
            ['ВКУПНО' => '100.00'],
            ['accountBuckets' => [['account' => $this->account('462'), 'amount' => '100.00']]]
        ));
    }

    public function test_a_line_row_with_no_buckets_posts_nothing(): void
    {
        $this->row(1, 'line', 'debit', 'ТРОШОК_СТАВКА');
        $this->row(2, 'fixed', 'debit', 'ВКУПНО', ['account_id' => $this->account('6600')->id]);
        $this->row(3, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('2200')->id]);

        $lines = (new PostingSchemeEngine)->lines($this->scheme->fresh(), $this->context(['ВКУПНО' => '10.00']));

        $this->assertCount(2, $lines);
    }

    public function test_the_input_vat_matrix_is_read_by_group_and_zero_vat_needs_no_account(): void
    {
        $this->row(1, 'fixed', 'debit', 'ВКУПНО - ДДВ', ['account_id' => $this->account('6600')->id]);
        $this->row(2, 'matrix', 'debit', 'ДДВ', ['matrix_key' => PostingMatrix::INPUT_VAT]);
        $this->row(3, 'fixed', 'credit', 'ВКУПНО', ['account_id' => $this->account('2200')->id]);
        PostingSchemeMatrixAccount::create(['posting_scheme_id' => $this->scheme->id, 'matrix_key' => PostingMatrix::INPUT_VAT, 'item_kind' => null, 'vat_group' => 'general', 'account_id' => $this->account('1300')->id]);
        PostingSchemeMatrixAccount::create(['posting_scheme_id' => $this->scheme->id, 'matrix_key' => PostingMatrix::INPUT_VAT, 'item_kind' => null, 'vat_group' => 'reduced', 'account_id' => $this->account('1301')->id]);

        // 300 (ВКУПНО − ДДВ) + 36 + 5 = 341 = ВКУПНО. Во редот 1 `ДДВ` е вкупниот од `totals`,
        // во редот 2 е ДДВ на кришката.
        $lines = (new PostingSchemeEngine)->lines($this->scheme->fresh(), $this->context(
            ['ВКУПНО' => '341.00', 'ДДВ' => '41.00'],
            ['slices' => [
                new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '200.00', '36.00'),
                new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '100.00', '5.00'),
                new PostingSlice(ItemKind::GOODS, VatGroup::EXEMPT_WITH_CREDIT, '9.00', '0.00'),
            ]]
        ));

        $byAccount = collect($lines)->keyBy(fn ($l) => $l->account->code);
        $this->assertSame('36.00', $byAccount['1300']->amount);
        $this->assertSame('5.00', $byAccount['1301']->amount);
        $this->assertCount(4, $lines); // 6600, 1300, 1301, 2200 — ослободената кришка е со ДДВ 0 и нема конто
    }
}

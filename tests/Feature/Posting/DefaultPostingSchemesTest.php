<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Services\Posting\DefaultPostingSchemes;
use App\Services\Posting\PostingSchemeEngine;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\ItemKind;
use App\Support\Posting\PostingContext;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingSlice;
use App\Support\Posting\VatGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DefaultPostingSchemesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_default_account_exists_and_is_analytical_in_the_chart(): void
    {
        $company = Company::factory()->create();

        foreach (PostingDocType::cases() as $type) {
            $definition = DefaultPostingSchemes::definition($type);
            $codes = array_filter(array_merge(
                array_column($definition['rows'], 'account'),
                array_column($definition['matrix'], 'account'),
            ));

            foreach ($codes as $code) {
                $account = Account::where('company_id', $company->id)->where('code', $code)->first();
                $this->assertNotNull($account, "Конто {$code} не постои во официјалниот план.");
                $this->assertTrue($account->is_analytical, "Конто {$code} не е аналитичко.");
            }
        }
    }

    public function test_the_scheme_is_created_lazily_once(): void
    {
        $company = Company::factory()->create();
        $this->assertSame(0, PostingScheme::where('company_id', $company->id)->count());

        $first = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $second = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PostingScheme::where('company_id', $company->id)->count());
        $this->assertSame(5, $first->rows()->count());
        $this->assertSame(12, $first->matrixAccounts()->count());
    }

    public function test_a_changed_scheme_is_not_overwritten(): void
    {
        $company = Company::factory()->create();
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $scheme->rows()->first()->update(['formula' => 'ВКУПНО - ДДВ']);

        PostingSchemes::for($company, PostingDocType::SALES_INVOICE);

        $this->assertSame('ВКУПНО - ДДВ', $scheme->rows()->first()->fresh()->formula);
    }

    /** @return array<string, array{0: list<PostingSlice>, 1: string, 2: string, 3: string, 4: string}> */
    public static function documents(): array
    {
        return [
            'услуга 18%' => [[new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '1000.00', '180.00')], '1180.00', '1000.00', '180.00', '0.00'],
            'стока 18% со залиха' => [[new PostingSlice(ItemKind::GOODS, VatGroup::GENERAL, '1000.00', '180.00')], '1180.00', '1000.00', '180.00', '600.00'],
            'мешано 18/5/извоз' => [[
                new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '200.00', '10.00'),
                new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '300.00', '54.00'),
                new PostingSlice(ItemKind::SERVICE, VatGroup::EXPORT, '50.00', '0.00'),
            ], '614.00', '550.00', '64.00', '120.00'],
            'ослободено' => [[
                new PostingSlice(ItemKind::GOODS, VatGroup::EXEMPT_WITH_CREDIT, '70.00', '0.00'),
                new PostingSlice(ItemKind::SERVICE, VatGroup::EXEMPT_WITHOUT_CREDIT, '30.00', '0.00'),
            ], '100.00', '100.00', '0.00', '0.00'],
        ];
    }

    #[DataProvider('documents')]
    public function test_the_default_sales_scheme_balances_for_typical_documents(array $slices, string $gross, string $net, string $vat, string $cogs): void
    {
        $company = Company::factory()->create();
        $scheme = PostingSchemes::for($company, PostingDocType::SALES_INVOICE);
        $context = new PostingContext(
            totals: ['ВКУПНО' => $gross, 'ОСНОВИЦА' => $net, 'ДДВ' => $vat, 'НАБАВНА_ВРЕДНОСТ' => $cogs],
            slices: $slices,
            flags: ['has_goods' => bccomp($cogs, '0', 2) > 0, 'cash' => false, 'import' => false],
            partnerId: 1,
            documentLabel: 'Invoice 1',
        );

        $lines = (new PostingSchemeEngine)->lines($scheme, $context);

        $debit = collect($lines)->where('side', 'debit')->reduce(fn ($c, $l) => bcadd($c, $l->amount, 2), '0.00');
        $this->assertSame($gross, collect($lines)->firstWhere(fn ($l) => $l->account->code === '1200')->amount);
        $this->assertTrue(bccomp($debit, '0', 2) > 0);
    }

    public function test_the_purchase_schemes_are_created_lazily_with_their_rows(): void
    {
        $company = Company::factory()->create();

        $invoice = PostingSchemes::for($company, PostingDocType::PURCHASE_INVOICE);
        $payment = PostingSchemes::for($company, PostingDocType::PURCHASE_PAYMENT);

        $this->assertSame(7, $invoice->rows()->count());
        $this->assertSame(2, $invoice->matrixAccounts()->count());
        $this->assertSame(3, $payment->rows()->count());
    }

    public function test_the_import_stock_account_is_read_from_the_scheme(): void
    {
        $company = Company::factory()->create();

        $this->assertSame('6601', PostingSchemes::importStockAccount($company)->code);
    }

    public function test_the_default_purchase_scheme_balances_for_domestic_and_import_documents(): void
    {
        $company = Company::factory()->create();
        $scheme = PostingSchemes::for($company, PostingDocType::PURCHASE_INVOICE);
        $expense = Account::where('company_id', $company->id)->where('code', '4620')->firstOrFail();
        $slices = [
            new PostingSlice(ItemKind::GOODS, VatGroup::REDUCED, '500.00', '25.00'),
            new PostingSlice(ItemKind::SERVICE, VatGroup::GENERAL, '100.00', '18.00'),
        ];

        foreach ([false, true] as $import) {
            $context = new PostingContext(
                totals: ['ВКУПНО' => '643.00', 'ЗАЛИХА' => '500.00', 'ТРОШОК_СТАВКА' => '100.00', 'ОДБИВЛИВ_ДДВ' => '43.00'],
                slices: $slices,
                flags: ['has_goods' => true, 'cash' => false, 'import' => $import],
                partnerId: 1,
                documentLabel: 'Purchase bill X #1',
                accountBuckets: [['account' => $expense, 'amount' => '100.00']],
            );

            $codes = collect((new PostingSchemeEngine)->lines($scheme, $context))->map(fn ($l) => $l->account->code.($l->side === 'debit' ? ' D ' : ' C ').$l->amount)->all();

            $expected = $import
                ? ['4620 D 100.00', '6601 D 500.00', '1302 D 43.00', '2210 C 643.00']
                : ['4620 D 100.00', '6600 D 500.00', '1300 D 18.00', '1301 D 25.00', '2200 C 643.00'];
            $this->assertEqualsCanonicalizing($expected, $codes);
        }
    }
}

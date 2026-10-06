<?php

namespace Tests\Feature\Posting;

use App\Models\Account;
use App\Models\Company;
use App\Models\PostingScheme;
use App\Models\PostingSchemeMatrixAccount;
use App\Models\PostingSchemeRow;
use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingMatrix;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingSchemeModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scheme_has_ordered_rows_and_matrix_accounts(): void
    {
        $company = Company::factory()->create();
        $scheme = PostingScheme::create(['company_id' => $company->id, 'doc_type' => PostingDocType::SALES_INVOICE, 'name' => 'Излезна фактура']);
        $bank = Account::where('company_id', $company->id)->where('code', '1000')->firstOrFail();
        PostingSchemeRow::create(['posting_scheme_id' => $scheme->id, 'position' => 2, 'account_mode' => 'fixed', 'account_id' => $bank->id, 'side' => 'credit', 'formula' => 'ВКУПНО']);
        PostingSchemeRow::create(['posting_scheme_id' => $scheme->id, 'position' => 1, 'account_mode' => 'fixed', 'account_id' => $bank->id, 'side' => 'debit', 'formula' => 'ВКУПНО']);
        PostingSchemeMatrixAccount::create(['posting_scheme_id' => $scheme->id, 'matrix_key' => PostingMatrix::OUTPUT_VAT, 'item_kind' => null, 'vat_group' => 'general', 'account_id' => $bank->id]);

        $fresh = $scheme->fresh();

        $this->assertSame(PostingDocType::SALES_INVOICE, $fresh->doc_type);
        $this->assertSame(['debit', 'credit'], $fresh->rows->pluck('side')->all());
        $this->assertCount(1, $fresh->matrixAccounts);
        $this->assertSame('1000', $fresh->rows->first()->account->code);
    }

    public function test_a_row_defaults_to_no_partner(): void
    {
        $company = Company::factory()->create();
        $scheme = PostingScheme::create(['company_id' => $company->id, 'doc_type' => PostingDocType::SALES_PAYMENT, 'name' => 'x']);
        $row = PostingSchemeRow::create(['posting_scheme_id' => $scheme->id, 'position' => 1, 'account_mode' => 'invoice', 'side' => 'credit', 'formula' => 'ИЗНОС']);

        $this->assertFalse($row->with_partner);
        $this->assertFalse($row->fresh()->with_partner);
    }

    public function test_a_company_has_one_scheme_per_document_type(): void
    {
        $company = Company::factory()->create();
        PostingScheme::create(['company_id' => $company->id, 'doc_type' => PostingDocType::SALES_INVOICE, 'name' => 'a']);

        $this->expectException(QueryException::class);

        PostingScheme::create(['company_id' => $company->id, 'doc_type' => PostingDocType::SALES_INVOICE, 'name' => 'b']);
    }

    public function test_matrix_dimensions(): void
    {
        $this->assertTrue(PostingMatrix::byKind(PostingMatrix::REVENUE));
        $this->assertFalse(PostingMatrix::byKind(PostingMatrix::OUTPUT_VAT));
    }
}

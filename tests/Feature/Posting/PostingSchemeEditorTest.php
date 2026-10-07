<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Models\PostingScheme;
use App\Services\Posting\PostingSchemeEditor;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingSchemeEditorTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private PostingSchemeEditor $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->editor = app(PostingSchemeEditor::class);
    }

    private function scheme(PostingDocType $type = PostingDocType::SALES_INVOICE): PostingScheme
    {
        return PostingSchemes::for($this->company, $type);
    }

    public function test_the_draft_of_the_default_scheme_has_codes_and_validates_clean(): void
    {
        $scheme = $this->scheme();

        $draft = $this->editor->draftOf($scheme);

        $this->assertCount(6, $draft['rows']);
        $this->assertSame('1200', $draft['rows'][0]['account_code']);
        $this->assertSame('ВКУПНО', $draft['rows'][0]['formula']);
        $this->assertTrue($draft['rows'][0]['with_partner']);
        $this->assertCount(12, $draft['matrix']);
        $this->assertSame([], $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, $scheme->name, $draft['rows'], $draft['matrix']));
    }

    public function test_every_default_scheme_validates_clean(): void
    {
        foreach (PostingDocType::cases() as $type) {
            $scheme = $this->scheme($type);
            $draft = $this->editor->draftOf($scheme);

            $this->assertSame([], $this->editor->validate($this->company, $type, $scheme->name, $draft['rows'], $draft['matrix']), $type->value);
        }
    }

    public function test_an_unknown_variable_and_bad_syntax_are_reported_with_the_row_number(): void
    {
        $draft = $this->editor->draftOf($this->scheme());
        $draft['rows'][0]['formula'] = 'ВКУПНО + ЦАРИНА';
        $draft['rows'][1]['formula'] = 'ОСНОВИЦА +';

        $errors = implode(' | ', $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']));

        $this->assertStringContainsString('Ред 1', $errors);
        $this->assertStringContainsString('ЦАРИНА', $errors);
        $this->assertStringContainsString('Ред 2', $errors);
    }

    public function test_a_heading_and_an_unknown_account_are_refused(): void
    {
        $draft = $this->editor->draftOf($this->scheme());

        foreach (['120' => 'не е аналитичко', '9999999' => 'не постои'] as $code => $expected) {
            $draft['rows'][0]['account_code'] = $code;
            $errors = implode(' | ', $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']));
            $this->assertStringContainsString($expected, $errors, $code);
        }
    }

    public function test_an_inactive_account_is_refused(): void
    {
        $draft = $this->editor->draftOf($this->scheme());
        \App\Models\Account::where('company_id', $this->company->id)->where('code', '1200')->update(['is_active' => false]);

        $errors = implode(' | ', $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']));

        $this->assertStringContainsString('не е активно', $errors);
    }

    public function test_an_unbalanced_scheme_is_refused_and_names_the_sample(): void
    {
        $draft = $this->editor->draftOf($this->scheme());
        $draft['rows'][0]['formula'] = 'ВКУПНО + 1';

        $errors = $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, 'Излезна фактура', $draft['rows'], $draft['matrix']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('не се балансира', implode(' | ', $errors));
        $this->assertStringContainsString('Услуга со 18% ДДВ', implode(' | ', $errors));
    }

    public function test_a_missing_matrix_account_for_a_slice_is_reported(): void
    {
        $draft = $this->editor->draftOf($this->scheme());
        $draft['matrix'] = array_values(array_filter($draft['matrix'], fn ($m) => ! ($m['matrix_key'] === 'revenue' && $m['item_kind'] === 'service' && $m['vat_group'] === 'general')));

        $errors = implode(' | ', $this->editor->validate($this->company, PostingDocType::SALES_INVOICE, 'x', $draft['rows'], $draft['matrix']));

        $this->assertStringContainsString('нема конто', $errors);
    }

    public function test_a_mode_a_condition_and_a_matrix_not_allowed_for_the_type_are_refused(): void
    {
        $draft = $this->editor->draftOf($this->scheme(PostingDocType::SALES_PAYMENT));
        $draft['rows'][0]['account_mode'] = 'line';
        $draft['rows'][1]['condition'] = 'import';

        $errors = implode(' | ', $this->editor->validate($this->company, PostingDocType::SALES_PAYMENT, 'x', $draft['rows'], $draft['matrix']));

        $this->assertStringContainsString('Ред 1', $errors);
        $this->assertStringContainsString('Ред 2', $errors);
    }

    public function test_save_replaces_the_rows_in_order_and_refuses_a_bad_draft_without_touching_the_scheme(): void
    {
        $scheme = $this->scheme();
        $draft = $this->editor->draftOf($scheme);

        $bad = $draft['rows'];
        $bad[0]['formula'] = 'ВКУПНО + 1';
        $this->assertNotEmpty($this->editor->save($scheme, $bad, $draft['matrix']));
        $this->assertSame('ВКУПНО', $scheme->rows()->first()->formula);

        $good = $draft['rows'];
        $good[0]['description'] = 'Нов опис {фактура}';
        $this->assertSame([], $this->editor->save($scheme, $good, $draft['matrix']));
        $fresh = $scheme->fresh()->rows;
        $this->assertCount(6, $fresh);
        $this->assertSame([1, 2, 3, 4, 5, 6], $fresh->pluck('position')->all());
        $this->assertSame('Нов опис {фактура}', $fresh->first()->description);
        $this->assertSame(12, $scheme->matrixAccounts()->count());
    }

    public function test_reset_to_default_restores_the_suggested_scheme(): void
    {
        $scheme = $this->scheme();
        $draft = $this->editor->draftOf($scheme);
        $draft['rows'][0]['description'] = 'Променет';
        $this->editor->save($scheme, $draft['rows'], $draft['matrix']);

        $this->editor->resetToDefault($scheme);

        $this->assertSame('{фактура}', $scheme->fresh()->rows->first()->description);
        $this->assertSame(6, $scheme->rows()->count());
        $this->assertSame(12, $scheme->matrixAccounts()->count());
    }
}

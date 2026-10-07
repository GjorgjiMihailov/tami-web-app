<?php

namespace Tests\Unit;

use App\Support\Posting\PostingDocType;
use App\Support\Posting\PostingMatrix;
use App\Support\Posting\PostingVocabulary;
use PHPUnit\Framework\TestCase;

class PostingVocabularyTest extends TestCase
{
    public function test_every_document_type_has_variables_and_modes(): void
    {
        foreach (PostingDocType::cases() as $type) {
            $this->assertNotEmpty(PostingVocabulary::variables($type), $type->value);
            $this->assertNotEmpty(PostingVocabulary::modes($type), $type->value);
        }
    }

    public function test_the_sales_invoice_variables_and_modes(): void
    {
        $type = PostingDocType::SALES_INVOICE;

        $this->assertSame(['ВКУПНО', 'ОСНОВИЦА', 'ДДВ', 'НАБАВНА_ВРЕДНОСТ', 'НАБАВНА_УВОЗ', 'НАБАВНА_ДОМАШНА'], array_keys(PostingVocabulary::variables($type)));
        $this->assertSame(['fixed', 'matrix'], array_keys(PostingVocabulary::modes($type)));
        $this->assertSame([PostingMatrix::REVENUE, PostingMatrix::OUTPUT_VAT], array_keys(PostingVocabulary::matrices($type)));
    }

    public function test_allowed_variables_depend_on_the_row_mode(): void
    {
        $purchase = PostingDocType::PURCHASE_INVOICE;

        $this->assertNotContains('ТРОШОК_СТАВКА', PostingVocabulary::allowedVariables(PostingDocType::SALES_INVOICE, 'fixed'));
        $this->assertContains('ТРОШОК_СТАВКА', PostingVocabulary::allowedVariables($purchase, 'line'));
        $this->assertContains('ДДВ', PostingVocabulary::allowedVariables($purchase, 'matrix'));
        $this->assertContains('ЗАЛИХА', PostingVocabulary::allowedVariables($purchase, 'fixed'));
        $this->assertSame(['ИЗНОС'], PostingVocabulary::allowedVariables(PostingDocType::SALES_PAYMENT, 'invoice'));
    }

    public function test_payments_have_the_cash_condition_and_the_not_forms(): void
    {
        $conditions = PostingVocabulary::conditions(PostingDocType::PURCHASE_PAYMENT);

        $this->assertArrayHasKey('cash', $conditions);
        $this->assertArrayHasKey('not_cash', $conditions);
        $this->assertArrayNotHasKey('import', $conditions);
    }

    public function test_the_matrix_cells(): void
    {
        $revenue = PostingVocabulary::matrixCells(PostingMatrix::REVENUE);
        $vat = PostingVocabulary::matrixCells(PostingMatrix::INPUT_VAT);

        $this->assertCount(10, $revenue); // 2 вида × 5 групи
        $this->assertSame(['goods', 'service'], array_values(array_unique(array_column($revenue, 'item_kind'))));
        $this->assertCount(2, $vat);
        $this->assertNull($vat[0]['item_kind']);
        $this->assertSame('general', $vat[0]['vat_group']);
    }
}

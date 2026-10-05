<?php

namespace Tests\Feature\Bank;

use App\Models\BankStatement;
use App\Models\Document;
use App\Support\BankStatementKind;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankStatementTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_statement_keeps_the_bank_the_account_and_its_place_in_the_sequence(): void
    {
        $statement = BankStatement::factory()->create([
            'bank' => 'Стопанска банка АД Скопје',
            'account' => '200000000000000',
            'number' => 47,
            'statement_date' => '2026-03-11',
        ])->fresh();

        $this->assertSame('Стопанска банка АД Скопје', $statement->bank);
        $this->assertSame('200000000000000', $statement->account);
        $this->assertSame(47, $statement->number);
        $this->assertSame('2026-03-11', $statement->statement_date->toDateString());
        $this->assertSame(BankStatementKind::DENAR, $statement->kind);
        $this->assertNotNull($statement->company);
        $this->assertNotNull($statement->uploader);
    }

    /**
     * Состојбите порано намерно ги немаше. Од книжењето на изводи (2026-10-05)
     * се внесуваат — но само за контрола, никогаш не се книжат.
     */
    public function test_a_statement_carries_balances_only_for_the_check(): void
    {
        $statement = BankStatement::factory()->create(['opening_balance' => '1000.00', 'closing_balance' => '1500.00'])->fresh();

        $this->assertSame('1000.00', $statement->opening_balance);
        $this->assertSame('1500.00', $statement->closing_balance);
    }

    public function test_a_foreign_statement_is_told_apart_from_a_denar_one(): void
    {
        $denar = BankStatement::factory()->create();
        $foreign = BankStatement::factory()->foreign()->create();

        $this->assertTrue($denar->kind->isDenar());
        $this->assertTrue($foreign->kind->isForeign());
        $this->assertSame('Денарски', BankStatementKind::DENAR->label());
        $this->assertSame('Девизен', BankStatementKind::FOREIGN->label());
    }

    public function test_the_statement_holds_its_file_through_the_shared_documents_table(): void
    {
        $statement = BankStatement::factory()->create();

        $this->assertInstanceOf(MorphMany::class, $statement->documents());

        Document::create([
            'company_id' => $statement->company_id,
            'documentable_type' => 'bank_statement',
            'documentable_id' => $statement->id,
            'category' => 'Bank Statement',
            'path' => 'documents/izvod-test.pdf',
            'original_filename' => 'izvod-47.pdf',
            'uploaded_by' => $statement->uploaded_by,
        ]);

        $this->assertSame('izvod-47.pdf', $statement->fresh()->documents->first()->original_filename);
    }
}

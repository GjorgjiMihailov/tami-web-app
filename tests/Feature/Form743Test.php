<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Form743;
use App\Support\Form743Status;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Form743Test extends TestCase
{
    use RefreshDatabase;

    /**
     * Она што клиентот го качува е фајл и ништо повеќе. Ако фабриката некогаш
     * почне да ги полни полињата од образецот, секој тест за работниот список
     * ќе поминува врз состојба што во продукција не постои.
     */
    public function test_a_freshly_uploaded_form_carries_nothing_from_the_form_itself(): void
    {
        $form = Form743::factory()->create();

        $this->assertSame(Form743Status::PENDING, $form->status);
        $this->assertNull($form->payer);
        $this->assertNull($form->amount);
        $this->assertNull($form->currency);
        $this->assertNull($form->payment_date);
        $this->assertNull($form->basis);
        $this->assertNull($form->filed_by);
        $this->assertNull($form->filed_at);
    }

    public function test_a_filed_form_carries_the_figures_and_who_filed_them(): void
    {
        $form = Form743::factory()->filed()->create()->fresh();

        $this->assertSame(Form743Status::FILED, $form->status);
        $this->assertSame('61500.00', $form->amount);
        $this->assertSame('EUR', $form->currency);
        $this->assertSame('2026-03-10', $form->payment_date->toDateString());
        $this->assertNotNull($form->filed_by);
        $this->assertNotNull($form->filed_at);
    }

    public function test_the_status_predicates_agree_with_the_stored_value(): void
    {
        $this->assertTrue(Form743Status::PENDING->isPending());
        $this->assertFalse(Form743Status::PENDING->isFiled());
        $this->assertTrue(Form743Status::FILED->isFiled());
        $this->assertFalse(Form743Status::FILED->isPending());

        $this->assertSame('Необработен', Form743Status::PENDING->label());
        $this->assertSame('Внесен', Form743Status::FILED->label());
    }

    /**
     * Фајлот виси на записот преку истата полиморфна табела што ја користат
     * фактурите — оваа фаза не пишува ново складиште.
     */
    public function test_the_form_holds_its_file_through_the_shared_documents_table(): void
    {
        $form = Form743::factory()->create();

        $this->assertInstanceOf(MorphMany::class, $form->documents());

        Document::create([
            'company_id' => $form->company_id,
            'documentable_type' => 'form743',
            'documentable_id' => $form->id,
            'category' => 'Other',
            'path' => 'documents/743-test.pdf',
            'original_filename' => '743.pdf',
            'uploaded_by' => $form->uploaded_by,
        ]);

        $this->assertSame('743.pdf', $form->fresh()->documents->first()->original_filename);
    }

    public function test_a_form_belongs_to_its_client_and_its_uploader(): void
    {
        $form = Form743::factory()->create();

        $this->assertNotNull($form->company);
        $this->assertNotNull($form->uploader);
        $this->assertNull($form->filer);
    }
}

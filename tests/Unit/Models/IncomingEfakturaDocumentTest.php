<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomingEfakturaDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_belongs_to_company(): void
    {
        $company = Company::factory()->create();
        $document = IncomingEfakturaDocument::factory()->for($company)->create();

        $this->assertTrue($document->company->is($company));
    }

    public function test_payload_json_casts_to_array(): void
    {
        $document = IncomingEfakturaDocument::factory()->create([
            'payload_json' => ['document' => ['header' => ['docNumber' => '2026-1']]],
        ]);

        $this->assertSame('2026-1', $document->fresh()->payload_json['document']['header']['docNumber']);
    }

    public function test_decision_defaults_to_null(): void
    {
        $document = IncomingEfakturaDocument::factory()->create();

        $this->assertNull($document->decision);
    }

    public function test_reject_reasons_constant_preserves_mixed_script_codes(): void
    {
        $this->assertArrayHasKey('O-1', IncomingEfakturaDocument::REJECT_REASONS);
        $this->assertArrayHasKey("\u{041E}-6", IncomingEfakturaDocument::REJECT_REASONS);
        $this->assertSame("\u{041E}-7", IncomingEfakturaDocument::REJECT_REASON_OTHER);
        $this->assertArrayHasKey(IncomingEfakturaDocument::REJECT_REASON_OTHER, IncomingEfakturaDocument::REJECT_REASONS);
    }
}

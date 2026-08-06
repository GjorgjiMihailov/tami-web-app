<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\IncomingEfakturaDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyIncomingEfakturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_efaktura_purchase_last_checked_at_casts_to_datetime(): void
    {
        $company = Company::factory()->create(['efaktura_purchase_last_checked_at' => '2026-08-01 10:00:00']);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $company->fresh()->efaktura_purchase_last_checked_at);
    }

    public function test_has_many_incoming_efaktura_documents(): void
    {
        $company = Company::factory()->create();
        IncomingEfakturaDocument::factory()->for($company)->count(2)->create();

        $this->assertCount(2, $company->incomingEfakturaDocuments);
    }
}

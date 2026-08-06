<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Partner;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesInvoiceEfakturaStatusTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvoice(?string $ujpStatusCode = null): SalesInvoice
    {
        $company = Company::factory()->create();
        $partner = Partner::factory()->for($company)->create();

        return SalesInvoice::factory()->for($company)->create([
            'partner_id' => $partner->id,
            'efaktura_ujp_status_code' => $ujpStatusCode,
        ]);
    }

    public function test_is_efaktura_accepted_is_false_when_no_status_yet(): void
    {
        $this->assertFalse($this->makeInvoice(null)->isEfakturaAccepted());
    }

    public function test_is_efaktura_accepted_is_false_for_a_non_terminal_status(): void
    {
        $this->assertFalse($this->makeInvoice('01')->isEfakturaAccepted());
    }

    public function test_is_efaktura_accepted_is_true_for_code_03(): void
    {
        $this->assertTrue($this->makeInvoice('03')->isEfakturaAccepted());
    }

    public function test_is_efaktura_accepted_is_true_for_code_04(): void
    {
        $this->assertTrue($this->makeInvoice('04')->isEfakturaAccepted());
    }
}

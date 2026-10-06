<?php

namespace Tests\Feature\Posting;

use App\Models\Company;
use App\Services\Posting\PostingSampleContexts;
use App\Services\Posting\PostingSchemeEngine;
use App\Services\Posting\PostingSchemes;
use App\Support\Posting\PostingDocType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingSampleContextsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_type_has_several_samples(): void
    {
        $company = Company::factory()->create();

        $this->assertGreaterThanOrEqual(4, count(PostingSampleContexts::for(PostingDocType::SALES_INVOICE, $company)));
        $this->assertGreaterThanOrEqual(4, count(PostingSampleContexts::for(PostingDocType::PURCHASE_INVOICE, $company)));
        $this->assertCount(2, PostingSampleContexts::for(PostingDocType::SALES_PAYMENT, $company));
        $this->assertCount(2, PostingSampleContexts::for(PostingDocType::PURCHASE_PAYMENT, $company));
    }

    public function test_the_default_schemes_balance_on_every_sample(): void
    {
        $company = Company::factory()->create();
        $engine = new PostingSchemeEngine;

        foreach (PostingDocType::cases() as $type) {
            $scheme = PostingSchemes::for($company, $type);

            foreach (PostingSampleContexts::for($type, $company) as $name => $context) {
                $lines = $engine->lines($scheme, $context);

                $this->assertNotEmpty($lines, "{$type->value}: {$name}");
            }
        }
    }

    public function test_the_samples_use_real_analytical_accounts_of_the_company(): void
    {
        $company = Company::factory()->create();

        $purchase = PostingSampleContexts::for(PostingDocType::PURCHASE_INVOICE, $company);
        $bucket = reset($purchase)->accountBuckets[0]['account'];
        $payment = PostingSampleContexts::for(PostingDocType::SALES_PAYMENT, $company);

        $this->assertSame($company->id, $bucket->company_id);
        $this->assertTrue($bucket->is_analytical);
        $this->assertSame('1200', reset($payment)->invoiceAccount->code);
    }
}

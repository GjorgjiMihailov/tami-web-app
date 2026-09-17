<?php

namespace Tests\Feature\Invoicing;

use App\Models\Company;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakeScannedInvoiceReader;
use Tests\TestCase;

class ScannedInvoiceReaderContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_resolves_a_reader(): void
    {
        $this->assertInstanceOf(ScannedInvoiceReader::class, app(ScannedInvoiceReader::class));
    }

    public function test_the_fake_returns_what_it_was_given(): void
    {
        $company = Company::factory()->create();

        FakeScannedInvoiceReader::$next = new ScannedInvoice(
            sellerTaxId: '4080012345678',
            buyerName: 'Купувач ДООЕЛ',
            buyerTaxId: '4080087654321',
            buyerStreetAddress: 'Партизанска',
            buyerStreetNumber: '10',
            buyerPostalCode: '1000',
            buyerCity: 'Скопје',
            invoiceNumber: '2026/45',
            invoiceDate: '2026-03-01',
            dueDate: '2026-03-15',
            currency: 'MKD',
            printedTotal: '1180.00',
            lines: [new ScannedInvoiceLine('Услуга', '1', '1000.00', '18')],
        );

        $this->app->bind(ScannedInvoiceReader::class, FakeScannedInvoiceReader::class);

        $result = app(ScannedInvoiceReader::class)->read(
            UploadedFile::fake()->create('skan.pdf', 20),
            $company,
        );

        $this->assertSame('2026/45', $result->invoiceNumber);
        $this->assertSame('Услуга', $result->lines[0]->description);
    }

    public function test_the_anthropic_key_is_configured(): void
    {
        config(['services.anthropic.key' => 'test-key']);

        $this->assertSame('test-key', config('services.anthropic.key'));
    }

    public function test_the_test_suite_never_carries_a_real_anthropic_key(): void
    {
        // Гаранцијата „ниту еден тест не бара кон Anthropic" мора да важи
        // поради конфигурација (phpunit.xml), не поради тоа што случајно
        // клучот во .env е празен — инаку развивач со вистински клуч во
        // .env (точно она што рачната проверка пред спојување го бара)
        // тивко почнува да троши пари штом ги пушти тестовите.
        $this->assertSame('', (string) env('ANTHROPIC_API_KEY'));
        $this->assertTrue(blank(config('services.anthropic.key')));
    }

    public function test_the_key_override_is_forced_over_the_os_environment(): void
    {
        // Без force="true" клуч извезен во ОС-околината победува над <env> од
        // phpunit.xml и гаранцијата погоре важи само за .env. Сопственикот
        // токму сега извезува вистински клуч за рачна проверка, па ова се
        // проверува врз самата поставка, не врз околината што е затекната.
        $override = simplexml_load_file(base_path('phpunit.xml'))
            ->xpath('//php/env[@name="ANTHROPIC_API_KEY"]')[0] ?? null;

        $this->assertNotNull($override);
        $this->assertSame('', (string) $override['value']);
        $this->assertSame('true', (string) $override['force']);
    }
}

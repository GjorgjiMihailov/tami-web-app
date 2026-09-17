<?php

namespace App\Services\Invoicing;

use Anthropic\Client;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;

/**
 * Го чита скенот преку Claude и го враќа прочитаното како обичен објект.
 *
 * Единственото место во апликацијата што знае дека постои надворешен сервис.
 * Моделот е Haiku 4.5 свесно: секоја фактура и онака ја прегледува човек пред
 * потврда, па поевтиниот модел не носи ризик што евтиниот тон не го покрива.
 */
class ClaudeScannedInvoiceReader implements ScannedInvoiceReader
{
    private const MODEL = 'claude-haiku-4-5';

    /** Цена по милион токени во УСД, за приближната сметка во дневникот. */
    private const INPUT_PRICE = 1.0;

    private const OUTPUT_PRICE = 5.0;

    public function read(UploadedFile $file, Company $company): ScannedInvoice
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            throw new ScannedInvoiceReadException('Нема клуч за Anthropic.');
        }

        $data = base64_encode($file->get());
        $mime = $file->getMimeType();

        $block = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $data]]
            : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]];

        try {
            $message = (new Client(apiKey: $key))->messages->create(
                model: self::MODEL,
                maxTokens: 4096,
                messages: [[
                    'role' => 'user',
                    'content' => [$block, ['type' => 'text', 'text' => $this->prompt($company)]],
                ]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $this->schema()]],
            );
        } catch (\Throwable $e) {
            throw new ScannedInvoiceReadException('Читањето не успеа: '.$e->getMessage(), previous: $e);
        }

        $this->logCost($company, $message);

        foreach ($message->content as $contentBlock) {
            if ($contentBlock->type === 'text') {
                $payload = json_decode($contentBlock->text, true);

                if (! is_array($payload)) {
                    throw new ScannedInvoiceReadException('Одговорот не е употреблив.');
                }

                return self::toScannedInvoice($payload);
            }
        }

        throw new ScannedInvoiceReadException('Одговорот не содржи текст.');
    }

    /**
     * Преводот е јавен и статичен за да може да се тестира без мрежа — тоа е
     * делот што најверојатно ќе се менува, а најскапо е да се тестира преку API.
     */
    public static function toScannedInvoice(array $payload): ScannedInvoice
    {
        $text = static fn (string $key) => isset($payload[$key]) && $payload[$key] !== ''
            ? (string) $payload[$key]
            : null;

        $lines = [];

        if (isset($payload['lines']) && is_array($payload['lines'])) {
            foreach ($payload['lines'] as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $lineText = static fn (string $key) => isset($line[$key]) && $line[$key] !== ''
                    ? (string) $line[$key]
                    : null;

                $lines[] = new ScannedInvoiceLine(
                    description: $lineText('description'),
                    quantity: $lineText('quantity'),
                    unitPrice: $lineText('unit_price'),
                    vatRate: $lineText('vat_rate'),
                );
            }
        }

        return new ScannedInvoice(
            sellerTaxId: $text('seller_tax_id'),
            buyerName: $text('buyer_name'),
            buyerTaxId: $text('buyer_tax_id'),
            buyerStreetAddress: $text('buyer_street_address'),
            buyerStreetNumber: $text('buyer_street_number'),
            buyerPostalCode: $text('buyer_postal_code'),
            buyerCity: $text('buyer_city'),
            invoiceNumber: $text('invoice_number'),
            invoiceDate: $text('invoice_date'),
            dueDate: $text('due_date'),
            currency: $text('currency'),
            printedTotal: $text('printed_total'),
            lines: $lines,
        );
    }

    private function prompt(Company $company): string
    {
        return <<<TEXT
        Ова е фактура издадена од фирмата "{$company->name}" со ЕДБ {$company->tax_id}.
        Таа фирма е ПРОДАВАЧОТ. Извади ја ДРУГАТА страна како купувач.

        Извади го само она што навистина е испишано на документот. Ако нешто го
        нема или не можеш да го прочиташ со сигурност, врати празен стринг за тоа
        поле — не погодувај.

        Датумите врати ги во формат ГГГГ-ММ-ДД.
        Износите врати ги како броеви со точка за децимала, без ознака за валута
        и без разделник за илјади.
        ДДВ стапката врати ја како број без знакот за процент (на пример: 18).
        Во "printed_total" врати го ВКУПНИОТ износ за плаќање како што е испишан
        на документот, со ДДВ.
        TEXT;
    }

    private function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'seller_tax_id' => $string,
                'buyer_name' => $string,
                'buyer_tax_id' => $string,
                'buyer_street_address' => $string,
                'buyer_street_number' => $string,
                'buyer_postal_code' => $string,
                'buyer_city' => $string,
                'invoice_number' => $string,
                'invoice_date' => $string,
                'due_date' => $string,
                'currency' => $string,
                'printed_total' => $string,
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'description' => $string,
                            'quantity' => $string,
                            'unit_price' => $string,
                            'vat_rate' => $string,
                        ],
                        'required' => ['description', 'quantity', 'unit_price', 'vat_rate'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'seller_tax_id', 'buyer_name', 'buyer_tax_id', 'buyer_street_address',
                'buyer_street_number', 'buyer_postal_code', 'buyer_city', 'invoice_number',
                'invoice_date', 'due_date', 'currency', 'printed_total', 'lines',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * Трошокот се запишува за да може на крајот на месецот да се прочита од
     * дневникот, наместо да се чека сметката.
     */
    private function logCost(Company $company, mixed $message): void
    {
        $input = (int) ($message->usage->inputTokens ?? 0);
        $output = (int) ($message->usage->outputTokens ?? 0);
        $usd = ($input / 1_000_000 * self::INPUT_PRICE) + ($output / 1_000_000 * self::OUTPUT_PRICE);

        Log::info('Прочитана скенирана фактура', [
            'company_id' => $company->id,
            'user_id' => auth()->id(),
            'model' => self::MODEL,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'usd' => round($usd, 5),
        ]);
    }
}

<?php

namespace App\Services\Invoicing;

use Anthropic\Client;
use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Ја чита скенираната царинска декларација (ЕЦД) преку Claude.
 *
 * Моделот е Sonnet, не Haiku, свесно: врз вистински скен на ЕЦД (5 страни,
 * фотографија) Haiku погрешно го прочита ЕЦД бројот, пропушти ставки и врати
 * збир на царина 32.835 наместо 35.451. Sonnet ги прочита сите 11 ставки и
 * збировите излегоа точни. Цената е неколку центи по декларација.
 */
class ClaudeCustomsDeclarationReader implements CustomsDeclarationReader
{
    private const MODEL = 'claude-sonnet-5-5';

    private const TIMEOUT_SECONDS = 90.0;

    private const MAX_RETRIES = 1;

    public function read(UploadedFile $file, Company $company): ScannedCustomsDeclaration
    {
        $key = config('services.anthropic.key');

        if (blank($key)) {
            throw new ScannedInvoiceReadException('Нема клуч за Anthropic.');
        }

        try {
            $data = base64_encode($file->get());
            $mime = $file->getMimeType();

            $block = $mime === 'application/pdf'
                ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $data]]
                : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $data]];

            $client = new Client(apiKey: $key, requestOptions: [
                'transporter' => new \GuzzleHttp\Client(['timeout' => self::TIMEOUT_SECONDS]),
                'maxRetries' => self::MAX_RETRIES,
            ]);

            $message = $client->messages->create(
                model: self::MODEL,
                maxTokens: 12000,
                messages: [[
                    'role' => 'user',
                    'content' => [$block, ['type' => 'text', 'text' => self::prompt()]],
                ]],
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => self::schema()]],
            );
        } catch (\Throwable $e) {
            throw new ScannedInvoiceReadException('Читањето не успеа: '.$e->getMessage(), previous: $e);
        }

        Log::info('Прочитана царинска декларација', [
            'company_id' => $company->id,
            'user_id' => auth()->id(),
            'model' => self::MODEL,
            'input_tokens' => (int) ($message->usage->inputTokens ?? 0),
            'output_tokens' => (int) ($message->usage->outputTokens ?? 0),
        ]);

        foreach ($message->content as $contentBlock) {
            if ($contentBlock->type === 'text') {
                $payload = json_decode($contentBlock->text, true);

                if (! is_array($payload)) {
                    throw new ScannedInvoiceReadException('Одговорот не е употреблив.');
                }

                $declaration = self::toDeclaration($payload);

                if (self::isBlank($declaration)) {
                    throw new ScannedInvoiceReadException('Од скенот не можеше да се прочита ништо.');
                }

                return $declaration;
            }
        }

        throw new ScannedInvoiceReadException('Одговорот не содржи текст.');
    }

    public static function isBlank(ScannedCustomsDeclaration $declaration): bool
    {
        if ($declaration->items !== [] || $declaration->referencedInvoiceNumbers !== []) {
            return false;
        }

        foreach (get_object_vars($declaration) as $property => $value) {
            if (! in_array($property, ['items', 'referencedInvoiceNumbers'], true) && $value !== null) {
                return false;
            }
        }

        return true;
    }

    /** Јавна и статична за да може да се тестира без мрежа. */
    public static function toDeclaration(array $payload): ScannedCustomsDeclaration
    {
        $text = static fn (array $from, string $key) => isset($from[$key]) && $from[$key] !== ''
            ? (string) $from[$key]
            : null;

        $amount = static fn (?string $value, bool $thousands = true) => ClaudeScannedInvoiceReader::normalizeAmount($value, $thousands);

        $items = [];

        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $charges = [];

            foreach ((array) ($item['charges'] ?? []) as $charge) {
                if (is_array($charge) && isset($charge['code']) && $charge['code'] !== '') {
                    $charges[strtoupper(trim((string) $charge['code']))] = $amount($text($charge, 'amount')) ?? '';
                }
            }

            $tariff = $text($item, 'tariff_code');

            $items[] = new ScannedCustomsItem(
                tariffCode: $tariff === null ? null : (preg_replace('/\D+/', '', $tariff) ?: $tariff),
                description: $text($item, 'description'),
                invoiceValueForeign: $amount($text($item, 'invoice_value_foreign')),
                statisticalValue: $amount($text($item, 'statistical_value')),
                charges: $charges,
            );
        }

        $referenced = array_values(array_filter(
            array_map(fn ($n) => ClaudeScannedInvoiceReader::normalizeInvoiceNumber(is_string($n) && $n !== '' ? $n : null), (array) ($payload['referenced_invoice_numbers'] ?? [])),
            fn ($n) => $n !== null,
        ));

        return new ScannedCustomsDeclaration(
            declarationNumber: $text($payload, 'ecd_number'),
            date: $text($payload, 'date'),
            importerName: $text($payload, 'importer_name'),
            importerTaxId: $text($payload, 'importer_tax_id'),
            declarantName: $text($payload, 'declarant_name'),
            currency: ClaudeScannedInvoiceReader::normalizeCurrency($text($payload, 'currency')),
            invoiceTotalForeign: $amount($text($payload, 'invoice_total_foreign')),
            exchangeRate: $amount($text($payload, 'exchange_rate'), false),
            totalDuty: $amount($text($payload, 'total_duty')),
            totalVat: $amount($text($payload, 'total_vat')),
            referencedInvoiceNumbers: $referenced,
            items: $items,
        );
    }

    public static function prompt(): string
    {
        return <<<'TEXT'
        Ова е скенирана царинска декларација (ЕЦД) од Северна Македонија. Првата
        страна е заглавието со првата ставка, следните страни се продолжение со
        по до три ставки. Може да е фотографија — чекај го секој број внимателно.

        Врати ги податоците од документот:
        - "ecd_number": бројот од полето „А. РДБ" на врвот (на пример 26MKIM10130001C799) — препиши го знак по знак;
        - "date": датумот на декларацијата во формат ГГГГ-ММ-ДД;
        - "importer_name" и "importer_tax_id": примачот (поле 8), со ДАНОЧНИОТ број (ЕДБ);
        - "declarant_name": подносителот/застапникот (поле 14);
        - "currency": валутата од поле 22 (три латински букви, на пример EUR);
        - "invoice_total_foreign": вкупниот износ на фактурата од поле 22;
        - "exchange_rate": курсот од поле 23, со сите децимали;
        - "referenced_invoice_numbers": бројот(евите) на фактурите наведени во поле 44 (Прилож. док.), на пример R-0003/26 — само бројот на фактурата;
        - "total_duty" и "total_vat": збировите од ВКУПНО на последната страна: збирот на сите A00 (царина) и збирот на сите B00 (ДДВ).

        За СЕКОЈА ставка (поле 32, Р.бр.) врати ред во "items":
        - "tariff_code": тарифната ознака од поле 33 (8 цифри);
        - "description": описот на стоката (поле 31);
        - "invoice_value_foreign": фактурната вредност од поле 42 (во странска валута);
        - "statistical_value": статистичката вредност од поле 46 (во денари);
        - "charges": секој ред од поле 47 за таа ставка: "code" (Вид, на пример A00 или B00) и "amount" (Износ — последната колона, не Основица и не Процент).

        Не ги меша ставките меѓу себе и не ги прескокнувај: ставките се нумерирани
        по ред (1, 2, 3 ...) низ сите страни. Ако нешто не можеш да го прочиташ со
        сигурност, врати празен стринг за тоа поле — не погодувај.

        СИТЕ износи врати ги како чисти броеви: точка за децимала, БЕЗ разделник
        за илјади и без ознака за валута.
        TEXT;
    }

    private static function schema(): array
    {
        $string = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'ecd_number' => $string,
                'date' => $string,
                'importer_name' => $string,
                'importer_tax_id' => $string,
                'declarant_name' => $string,
                'currency' => $string,
                'invoice_total_foreign' => $string,
                'exchange_rate' => $string,
                'referenced_invoice_numbers' => ['type' => 'array', 'items' => $string],
                'total_duty' => $string,
                'total_vat' => $string,
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'tariff_code' => $string,
                            'description' => $string,
                            'invoice_value_foreign' => $string,
                            'statistical_value' => $string,
                            'charges' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => ['code' => $string, 'amount' => $string],
                                    'required' => ['code', 'amount'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['tariff_code', 'description', 'invoice_value_foreign', 'statistical_value', 'charges'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => [
                'ecd_number', 'date', 'importer_name', 'importer_tax_id', 'declarant_name', 'currency',
                'invoice_total_foreign', 'exchange_rate', 'referenced_invoice_numbers', 'total_duty', 'total_vat', 'items',
            ],
            'additionalProperties' => false,
        ];
    }
}

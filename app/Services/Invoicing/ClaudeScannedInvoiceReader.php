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

    /**
     * `RequestOptions::$timeout` од SDK-то е само советодавен — никаде во
     * vendor/ не се чита (види `RequestOptions.php`), границата ја наметнува
     * транспортот. 30 секунди се доволни со голем резерв за скен од 1-2
     * страници со ограничен JSON излез; со maxRetries=1 (наместо
     * стандардните 2) најлошиот случај е 2 обиди × 30с = 60с, наместо
     * неограничено чекање што ќе го убие php-fpm работникот пред catch-от
     * воопшто да стигне до него.
     */
    private const TIMEOUT_SECONDS = 30.0;

    private const MAX_RETRIES = 1;

    public function read(UploadedFile $file, Company $company): ScannedInvoice
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

            $message = (new Client(apiKey: $key, requestOptions: self::transportOptions()))->messages->create(
                model: self::MODEL,
                maxTokens: 4096,
                messages: [[
                    'role' => 'user',
                    'content' => [$block, ['type' => 'text', 'text' => self::prompt($company)]],
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

                $scanned = self::toScannedInvoice($payload);

                // Промптот изрично бара празен стринг кога моделот не е
                // сигурен — сите полиња празни значи скенот не можел
                // воопшто да се прочита, не дека фактурата навистина е
                // празна. Формата веќе знае да го прикаже рачниот пат кога
                // читањето фрла исклучок.
                if (self::isBlank($scanned)) {
                    throw new ScannedInvoiceReadException('Од скенот не можеше да се прочита ништо.');
                }

                return $scanned;
            }
        }

        throw new ScannedInvoiceReadException('Одговорот не содржи текст.');
    }

    /**
     * Транспортот е одвоен за да може да се тестира без мрежа — тестот
     * проверува дека клучот и лимитот се вистински зададени, не дека
     * барањето навистина трае онолку.
     *
     * @return array{transporter: \GuzzleHttp\Client, maxRetries: int}
     */
    public static function transportOptions(): array
    {
        return [
            'transporter' => new \GuzzleHttp\Client(['timeout' => self::TIMEOUT_SECONDS]),
            'maxRetries' => self::MAX_RETRIES,
        ];
    }

    /**
     * Јавна и статична од истата причина како `toScannedInvoice()` —
     * проверката дали резултатот е празен мора да се тестира без мрежа.
     */
    public static function isBlank(ScannedInvoice $invoice): bool
    {
        if ($invoice->lines !== []) {
            return false;
        }

        foreach (get_object_vars($invoice) as $property => $value) {
            if ($property !== 'lines' && $value !== null) {
                return false;
            }
        }

        return true;
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
                    // Кај количината точката е речиси секогаш децимала — „1.500“
                    // значи еден и пол парчиња, не илјада и пол. Кај парите е
                    // обратно. Затоа двете не поминуваат низ исто правило.
                    quantity: self::normalizeAmount($lineText('quantity'), thousands: false),
                    unitPrice: self::normalizeAmount($lineText('unit_price'), thousands: true),
                    vatRate: self::normalizeAmount($lineText('vat_rate'), thousands: false),
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
            printedTotal: self::normalizeAmount($text('printed_total'), thousands: true),
            lines: $lines,
        );
    }

    /**
     * Го сведува бројот од скенот на обликот што формата може да го смета.
     *
     * Промптот бара точка за децимала и без разделник за илјади, но моделот не
     * е доследен: истата фактура еднаш врати „3540.00“, другпат „3,540.00“.
     * Врз вистинска фактура тоа значеше дека најсилната проверка — збирот од
     * ставките наспроти испишаното вкупно — воопшто не се извршуваше. Затоа
     * упатството бара убаво, а кодот гарантира.
     *
     * Кога обликот останува двосмислен, се враќа ИЗВОРНИОТ стринг непроменет.
     * Формата тогаш предупредува дека износите не се читливи — подобро отколку
     * овде да се измисли бројка во која никој не се посомневал.
     */
    public static function normalizeAmount(?string $value, bool $thousands): ?string
    {
        if ($value === null) {
            return null;
        }

        // Секаков размак, вклучително тврдиот и тесниот што ги носат PDF-овите.
        $s = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', trim($value)) ?? '';

        if ($s === '') {
            return $value;
        }

        $sign = '';

        if ($s[0] === '+' || $s[0] === '-') {
            $sign = $s[0] === '-' ? '-' : '';
            $s = substr($s, 1);
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');

        if ($hasComma && $hasDot) {
            // Двата знака заедно се недвосмислени: десниот е децималниот.
            $decimal = strrpos($s, ',') > strrpos($s, '.') ? ',' : '.';
            $s = str_replace($decimal === ',' ? '.' : ',', '', $s);
            $s = str_replace($decimal, '.', $s);
        } elseif ($hasComma) {
            // Кај нас запирката е децимала. Како разделник за илјади се
            // препознава само јасниот облик 1,234 или 1,234,567.
            $s = ($thousands && preg_match('/^\d{1,3}(,\d{3})+$/', $s) === 1)
                ? str_replace(',', '', $s)
                : str_replace(',', '.', $s);
        } elseif ($hasDot) {
            if ($thousands && preg_match('/^\d{1,3}(\.\d{3})+$/', $s) === 1) {
                $s = str_replace('.', '', $s);
            }
        }

        // Ако по сето ова не е чист број, ништо не се тврди — се враќа изворното.
        return preg_match('/^\d+(\.\d+)?$/', $s) === 1 ? $sign.$s : $value;
    }

    /**
     * Јавна и статична за да може да се тестира без мрежа.
     *
     * ЕДБ-то на фирмата НАМЕРНО не се спомнува тука. Кога му го даваше, моделот
     * го враќаше назад како `seller_tax_id` на документи каде такво ЕДБ нема
     * воопшто — па проверката „ова изгледа како влезна фактура" секогаш велеше
     * дека се совпаѓа, токму кога требаше да предупреди. Сега бројот се чита од
     * документот, па споредбата во формата навистина значи нешто.
     */
    public static function prompt(Company $company): string
    {
        return <<<TEXT
        Ова е фактура издадена од фирмата "{$company->name}" — таа е ПРОДАВАЧОТ.
        Извади ја ДРУГАТА страна како купувач.

        Извади го само она што навистина е испишано на документот. Ако нешто го
        нема или не можеш да го прочиташ со сигурност, врати празен стринг за тоа
        поле — не погодувај и не земај броеви од ова упатство.

        За "seller_tax_id" и "buyer_tax_id" врати го ДАНОЧНИОТ број. На
        македонските фактури тој е означен со „Е.Д.Б." или „ЕДБ" и има 13 цифри.
        НЕ го враќај матичниот број („М.број", „Мат. бр."), кој често стои веднаш
        до него и е пократок. Ако таков број го нема на документот, врати празен
        стринг.

        Датумите врати ги во формат ГГГГ-ММ-ДД.

        СИТЕ износи врати ги како чисти броеви: точка за децимала, БЕЗ разделник
        за илјади и без ознака за валута. Ова важи и кога на документот се
        испишани поинаку:
          на документот 3,540.00  →  врати 3540.00
          на документот 3.540,00  →  врати 3540.00
          на документот 3 540,00  →  врати 3540.00
        ДДВ стапката врати ја како број без знакот за процент (на пример: 18).
        Во "printed_total" врати го ВКУПНИОТ износ за плаќање со ДДВ, во истиот
        облик како другите износи.
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

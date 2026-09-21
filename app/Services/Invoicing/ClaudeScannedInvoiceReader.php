<?php

namespace App\Services\Invoicing;

use Anthropic\Client;
use App\Models\Company;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

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
                    'content' => [$block, ['type' => 'text', 'text' => self::prompt((string) $company->name)]],
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
            // Бројот на фактури е податок ЗА документот, не прочитана содржина
            // — „1“ без ништо друго сепак е празно читање.
            if (! in_array($property, ['lines', 'invoiceCount', 'ourCompanyRole'], true) && $value !== null) {
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
            invoiceNumber: self::normalizeInvoiceNumber($text('invoice_number')),
            invoiceDate: $text('invoice_date'),
            dueDate: $text('due_date'),
            currency: self::normalizeCurrency($text('currency')),
            printedTotal: self::normalizeAmount($text('printed_total'), thousands: true),
            lines: $lines,
            sellerName: $text('seller_name'),
            invoiceCount: isset($payload['invoice_count']) && is_numeric($payload['invoice_count'])
                ? (int) $payload['invoice_count']
                : null,
            ourCompanyRole: in_array($payload['our_company_role'] ?? null, ['seller', 'buyer', 'absent'], true)
                ? $payload['our_company_role']
                : null,
        );
    }

    /**
     * Бројот на фактурата заминува кон УЈП како `docNumber`, па „бр.25“ не
     * смее да помине таму наместо „25“. Врз вистинска фактура моделот го
     * врати зборот заедно со бројот, иако упатството го бара без него.
     */
    public static function normalizeInvoiceNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $number = preg_replace(
            // „број“ пред „бр“ — инаку „бр“ се совпаѓа прв и остава „ој 19“.
            // „6р.“ е „бр.“ прочитано погрешно: во фонтот на вистински СТП скен
            // „б“ изгледа како шестка. Никогаш не е дел од вистински број.
            '/^\s*(?:фактура[\s\-–]*)?(?:испратница[\s\-–]*)?(?:број|бр\.?|6р\.|№|no\.?)[\s:.]*/iu',
            '',
            $value
        ) ?? $value;

        $number = trim($number);

        // Ако ништо не остане, подобро е изворното отколку празен број.
        return $number === '' ? $value : $number;
    }

    /**
     * Формата прифаќа само кодови од `SalesInvoice::CURRENCIES`. Непознат запис
     * тивко се игнорира и останува MKD — што за „евра“ би значело погрешна
     * валута без никаков знак. Затоа македонските и симболичките записи се
     * сведуваат на код тука; сè друго се враќа непроменето.
     */
    public static function normalizeCurrency(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $code = rtrim(mb_strtoupper(trim($value)), '.');

        return match (true) {
            in_array($code, ['MKD', 'МКД', 'ДЕН', 'ДЕНАР', 'ДЕНАРИ'], true) => 'MKD',
            in_array($code, ['EUR', 'ЕУР', 'ЕВРО', 'ЕВРА', '€'], true) => 'EUR',
            in_array($code, ['USD', 'УСД', 'ДОЛАР', 'ДОЛАРИ', '$'], true) => 'USD',
            in_array($code, ['GBP', '£'], true) => 'GBP',
            in_array($code, ['CHF'], true) => 'CHF',
            default => $value,
        };
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
            if ($thousands && preg_match('/^\d{1,3}(,\d{3})+,\d{1,2}$/', $s) === 1) {
                // „210,831,00“ — истиот знак и за илјади и за децимала.
                $last = strrpos($s, ',');
                $s = str_replace(',', '', substr($s, 0, $last)).'.'.substr($s, $last + 1);
            } else {
                $s = ($thousands && preg_match('/^\d{1,3}(,\d{3})+$/', $s) === 1)
                    ? str_replace(',', '', $s)
                    : str_replace(',', '.', $s);
            }
        } elseif ($hasDot) {
            if ($thousands && preg_match('/^\d{1,3}(\.\d{3})+\.\d{1,2}$/', $s) === 1) {
                // „210.831.00“ — врз вистински СТП скен моделот ја врати
                // точката и за илјади и за децимала. Последната група од една
                // или две цифри е децимала, сите други точки се илјади.
                $last = strrpos($s, '.');
                $s = str_replace('.', '', substr($s, 0, $last)).'.'.substr($s, $last + 1);
            } elseif ($thousands && preg_match('/^\d{1,3}(\.\d{3})+$/', $s) === 1) {
                $s = str_replace('.', '', $s);
            }
        }

        // Ако по сето ова не е чист број, ништо не се тврди — се враќа изворното.
        return preg_match('/^\d+(\.\d+)?$/', $s) === 1 ? $sign.$s : $value;
    }

    /**
     * Јавна и статична за да може да се тестира без мрежа.
     *
     * Упатството го носи ИМЕТО на фирмата, но никогаш ЕДБ-то и никогаш тврдење
     * која страна е таа. Секоја од трите претходни верзии научи по нешто:
     *
     * - со ЕДБ-то во упатството, моделот го препишуваше назад таму каде такво
     *   нема;
     * - со „таа е продавачот", на влезна фактура го свиткуваше документот за
     *   да се согласи;
     * - без ништо за фирмата, името на продавачот го читаше точно на сите
     *   четири вистински фактури, но ЕДБ-то го мешаше со купувачовото на две.
     *
     * Затоа улогата на фирмата се прашува директно, како прашање со три
     * одговори, а формата се потпира на тој одговор — не на ЕДБ-то, кое е
     * најнесигурното поле.
     */
    public static function prompt(string $companyName): string
    {
        return <<<TEXT
        ПРВО одговори на ова прашање, во "our_company_role", гледајќи ја само
        хартијата: каде на документот се спомнува фирмата „{$companyName}"?
          - "seller" — таа ја ИЗДАЛА фактурата (нејзиното име е во заглавието,
            покрај жиро сметката, контактот или потписот на издавачот);
          - "buyer" — фактурата е издадена НА неа (купувач, примач, „Партнер",
            „До");
          - "absent" — ја нема на документот.
        Не претпоставувај. Името може да е напишано поинаку — скратено, со
        или без „ДООЕЛ", со друг град — но мора навистина да стои таму.

        Потоа извади ги податоците од фактурата. Двете страни извади ги ТОЧНО онака
        како што се означени на документот — не претпоставувај која е која по
        местото на страницата:

        - ПРОДАВАЧ: фирмата што ја ИЗДАЛА фактурата (издавач, добавувач).
          Ако ознаката ја нема, тоа е фирмата чија жиро сметка, контакт или
          потпис стојат на фактурата, најчесто во заглавието покрај логото.
        - КУПУВАЧ: фирмата или лицето на кое е ИЗДАДЕНА (купувач, примач,
          „Партнер", „До").

        За "buyer_name" и "seller_name" врати го НАЗИВОТ на фирмата или името на
        лицето (на пример „... ДООЕЛ Скопје"), НИКОГАШ улица. Улицата оди во
        "buyer_street_address", бројот во "buyer_street_number".

        Ако документот содржи ПОВЕЌЕ одделни фактури — на пример по една на
        секоја страница, секоја со свој број — во "invoice_count" врати колку
        се, а сите други полиња пополни ги САМО од првата. За една фактура врати
        1. Ако документот воопшто не е фактура, врати 0.

        Извади го само она што навистина е испишано на документот. Ако нешто го
        нема или не можеш да го прочиташ со сигурност, врати празен стринг за тоа
        поле — не погодувај. Ако некој број го гледаш само делумно, врати празен
        стринг, не половина број.

        За "invoice_number" врати го само бројот, без зборови како „Фактура",
        „бр.", „број" или „№".

        За "currency" врати ознака од три латински букви: MKD, EUR, USD, GBP или
        CHF. Ако износите се во денари, врати MKD.

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
                // Прво по ред намерно: одговорот се дава од хартијата, пред
                // моделот да ги пополни страните и да се врзе за нив.
                'our_company_role' => ['type' => 'string', 'enum' => ['seller', 'buyer', 'absent']],
                'invoice_count' => ['type' => 'integer'],
                'seller_name' => $string,
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
                'our_company_role', 'invoice_count', 'seller_name',
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

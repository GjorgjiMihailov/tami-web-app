<?php

namespace App\Livewire\Invoicing;

use App\Models\Company;
use App\Models\Item;
use App\Models\Partner;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Warehouse;
use App\Services\DocumentStorage;
use App\Services\ExchangeRateService;
use App\Services\Invoicing\ScannedInvoice;
use App\Services\Invoicing\ScannedInvoiceLine;
use App\Services\Invoicing\ScannedInvoiceReader;
use App\Support\InvoiceLanguage;
use App\Support\WorkingYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class SalesInvoiceForm extends Component
{
    use WithFileUploads;

    public Company $company;

    public ?SalesInvoice $salesInvoice = null;

    public string $partnerId = '';

    public string $warehouseId = '';

    public string $invoiceDate = '';

    public string $dueDate = '';

    public string $notes = '';

    /**
     * Бројот што фактурата веќе го носи на хартија.
     *
     * Празно значи обична фактура — бројот го дава серијата на фирмата при
     * потврда. Пополнето значи фактура внесена од скен и тој број оди и на
     * печатената фактура и кон УЈП.
     */
    public string $paperNumber = '';

    public $scanFile = null;

    /**
     * Дали формата е пополнета од скен. Го отклучува полето за хартиениот број
     * и жолтата лента „провери пред потврда“.
     */
    public bool $scanRead = false;

    /** @var string[] Предупредувања од проверките врз прочитаното. */
    public array $scanWarnings = [];

    /**
     * Купувач прочитан од скен што го нема во шифрарникот.
     *
     * Стои само во меморија додека човекот не кликне „Создај партнер“ — лошо
     * прочитано име не смее тивко да се залепи во шифрарникот и да се чисти
     * подоцна.
     *
     * @var array{name: string, tax_id: string, street_address: string, street_number: string, postal_code: string, city: string}|null
     */
    public ?array $suggestedPartner = null;

    public string $paymentTypeCode = 'P12';

    public string $currency = 'MKD';

    public string $exchangeRate = '1';

    public array $lines = [];

    public int $workingYear = 0;

    /**
     * Горна граница за СЛИКА, во килобајти.
     *
     * Anthropic дозволува 10 МБ по слика, но мерено врз base64 записот, не врз
     * фајлот. Base64 го дува фајлот за околу една третина, па слика од 10 МБ
     * (колку што пушта `max:10240`) станува ~13,3 МБ и барањето се одбива со
     * сурова грешка што кај нас се гледа само како „Не можев да ја прочитам
     * фактурата". 6 МБ сурово даваат точно 8 МБ по кодирањето — под границата
     * и кога МБ се брои како 1.000.000 и кога се брои како 1.048.576 бајти, со
     * простор за остатокот од барањето. PDF не поминува низ оваа граница:
     * документите ги ограничува само големината на целото барање (32 МБ).
     */
    private const MAX_IMAGE_KILOBYTES = 6144;

    public function mount(Company $company, ?SalesInvoice $salesInvoice = null): void
    {
        Gate::authorize('view', $company);

        $this->company = $company;
        $this->workingYear = WorkingYear::for($company);

        Gate::authorize($salesInvoice ? 'update' : 'create', $salesInvoice ?? SalesInvoice::class);

        if ($salesInvoice) {
            if ($salesInvoice->company_id !== $company->id) {
                abort(404);
            }

            if ($salesInvoice->status !== 'draft') {
                abort(403, 'Можат да се менуваат само нацрт фактури.');
            }
        }

        $this->salesInvoice = $salesInvoice;

        if ($salesInvoice) {
            $this->partnerId = (string) $salesInvoice->partner_id;
            $this->warehouseId = $salesInvoice->warehouse_id === null ? '' : (string) $salesInvoice->warehouse_id;
            $this->invoiceDate = $salesInvoice->invoice_date->toDateString();
            $this->dueDate = $salesInvoice->due_date->toDateString();
            $this->notes = (string) $salesInvoice->notes;
            $this->paperNumber = (string) $salesInvoice->invoice_number_formatted;
            $this->paymentTypeCode = $salesInvoice->payment_type_code;
            $this->currency = $salesInvoice->currency;
            $this->exchangeRate = (string) $salesInvoice->exchange_rate;
            $this->lines = $salesInvoice->lines->map(fn ($line) => [
                'item_id' => $line->item_id === null ? '' : (string) $line->item_id,
                'description' => (string) $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'vat_rate' => (string) $line->vat_rate,
                'vat_treatment' => (string) $line->vat_treatment,
            ])->toArray();
        } else {
            $this->invoiceDate = WorkingYear::defaultDate($this->workingYear);
            $this->dueDate = WorkingYear::defaultDate($this->workingYear);
            $this->lines = [$this->emptyLine()];
        }
    }

    protected function emptyLine(): array
    {
        return [
            'item_id' => '',
            'description' => '',
            'quantity' => '1',
            'unit_price' => '0',
            'vat_rate' => $this->company->is_vat_registered ? '18.00' : '0.00',
            'vat_treatment' => 'standard',
        ];
    }

    /**
     * Кога се менува валутата, се нуди последниот курс што фирмата го користела
     * за неа. Курсот останува рачен — ова е само понуда, не автоматика.
     */
    public function updatedCurrency(string $value): void
    {
        // Livewire ја памети грешката на exchangeRate меѓу барањата — стар
        // неуспех од НБРМ не смее да остане прикажан откако валутата (или
        // курсот што ѝ следи) е веќе сменета.
        $this->resetErrorBag('exchangeRate');

        if ($value === 'MKD') {
            $this->exchangeRate = '1';

            return;
        }

        $last = SalesInvoice::where('company_id', $this->company->id)
            ->where('currency', $value)
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->value('exchange_rate');

        $this->exchangeRate = $last === null ? '' : (string) $last;
    }

    /**
     * Копчето „НБРМ“ — истиот сервис што го користи формата за рачно книжење.
     *
     * Полето останува рачно и по ова: паднат НБРМ не смее да блокира издавање
     * фактура, па неуспехот се прикажува како грешка на полето, а корисникот
     * впишува курс сам.
     */
    public function fetchRate(): void
    {
        if ($this->currency === 'MKD') {
            $this->exchangeRate = '1';

            return;
        }

        try {
            $rate = app(ExchangeRateService::class)->getRate(
                $this->currency,
                Carbon::parse($this->invoiceDate)
            );
        } catch (\Throwable $e) {
            $this->addError('exchangeRate', 'Курсот не се презеде од НБРМ — впиши го рачно.');

            return;
        }

        // Успешен обид по претходен неуспех не смее да остави стара грешка
        // под точниот курс.
        $this->resetErrorBag('exchangeRate');
        $this->exchangeRate = (string) $rate;
    }

    public function addLine(): void
    {
        $this->lines[] = $this->emptyLine();
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function selectItem(int $index, string $itemId): void
    {
        $this->lines[$index]['item_id'] = $itemId;

        if ($itemId === '') {
            return;
        }

        $item = Item::where('company_id', $this->company->id)->find($itemId);

        if ($item) {
            $this->lines[$index]['description'] = $item->name;
            $this->lines[$index]['vat_rate'] = $this->company->is_vat_registered ? (string) $item->vat_rate : '0.00';

            if ($item->selling_price !== null) {
                $this->lines[$index]['unit_price'] = (string) $item->selling_price;
            }
        }
    }

    public function setVatTreatment(int $index, string $treatment): void
    {
        $this->lines[$index]['vat_treatment'] = $treatment;

        if ($treatment !== 'standard') {
            $this->lines[$index]['vat_rate'] = '0.00';
        }
    }

    /**
     * Избран нов фајл го обезвреднува претходното читање — инаку `save()` би
     * го закачил новиот фајл на партнер, датуми и ставки прочитани од стариот,
     * а прикачениот „оригинал" повеќе не би одговарал на фактурата.
     * Секое поле мора повторно да мине низ „Прочитај ја фактурата".
     */
    public function updatedScanFile(): void
    {
        $this->scanRead = false;
        $this->scanWarnings = [];
        $this->suggestedPartner = null;
    }

    /**
     * Читањето чини пари од буџетот на канцеларијата, па клиентите остануваат
     * надвор иако смеат да создаваат фактури. Без клуч функцијата воопшто ја
     * нема — сервер без клуч работи како досега.
     */
    public function canReadScans(): bool
    {
        return filled(config('services.anthropic.key'))
            && auth()->user()?->hasAnyRole(['admin', 'accountant']);
    }

    public function readScan(): void
    {
        abort_unless($this->canReadScans(), 403);

        $this->resetErrorBag('scanFile');
        $this->scanWarnings = [];
        // Неуспешно читање излегува пред `applyScan()` да стигне да го исчисти —
        // понудениот партнер од претходен, успешен скен не смее да преживее
        // на екранот и да се создаде со клик на застарени податоци.
        $this->suggestedPartner = null;

        $this->validate([
            'scanFile' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
        ]);

        // Слика преку границата на API-то паѓа дури во читачот, а таму сè
        // изгледа исто — генеричката порака не му кажува на човекот што да
        // направи. Фотографија од телефон редовно е над границата, па пораката
        // мора да е конкретна.
        if ($this->scanFile->getMimeType() !== 'application/pdf'
            && $this->scanFile->getSize() > self::MAX_IMAGE_KILOBYTES * 1024) {
            $this->addError('scanFile', 'Сликата е преголема — прати помала слика (до 6 МБ) или PDF.');

            return;
        }

        try {
            $scanned = app(ScannedInvoiceReader::class)->read($this->scanFile, $this->company);
        } catch (\Throwable $e) {
            report($e);
            $this->addError('scanFile', 'Не можев да ја прочитам фактурата — внеси ја рачно.');

            return;
        }

        $this->applyScan($scanned);
        $this->scanRead = true;
    }

    public function createSuggestedPartner(): void
    {
        abort_unless($this->canReadScans(), 403);

        if ($this->suggestedPartner === null) {
            return;
        }

        // Истите правила што важат за рачно внесување партнер (PartnerIndex) —
        // непрочитано или предолго име од АИ не смее да заобиколи проверка
        // што важи насекаде во шифрарникот, ниту да падне со грешка на база.
        $this->validate([
            'suggestedPartner.name' => 'required|string|max:255',
            'suggestedPartner.tax_id' => 'required|string|max:255',
            'suggestedPartner.street_address' => 'nullable|string|max:255',
            'suggestedPartner.street_number' => 'nullable|string|max:255',
            'suggestedPartner.postal_code' => 'nullable|string|max:255',
            'suggestedPartner.city' => 'nullable|string|max:255',
        ]);

        $partner = Partner::create([
            'company_id' => $this->company->id,
            'name' => $this->suggestedPartner['name'],
            'tax_id' => $this->suggestedPartner['tax_id'],
            'street_address' => $this->suggestedPartner['street_address'] ?: null,
            'street_number' => $this->suggestedPartner['street_number'] ?: null,
            'postal_code' => $this->suggestedPartner['postal_code'] ?: null,
            'city' => $this->suggestedPartner['city'] ?: null,
        ]);

        $this->partnerId = (string) $partner->id;
        $this->suggestedPartner = null;
    }

    /**
     * Прочитаното се прелива во формата. Секое поле е незадолжително: ако Claude
     * не прочитал нешто, старата вредност останува и човекот ја дополнува.
     */
    private function applyScan(ScannedInvoice $scanned): void
    {
        $this->suggestedPartner = null;

        // Проверка 1: качен погрешен фајл. Ако продавачот на хартијата не е оваа
        // фирма, најверојатно е влезна фактура во папката на излезните.
        if (filled($scanned->sellerTaxId) && filled($this->company->tax_id)
            && $this->digits($scanned->sellerTaxId) !== $this->digits($this->company->tax_id)) {
            $this->scanWarnings[] = 'Изгледа дека ова е влезна, не излезна фактура — провери го фајлот.';
        }

        if (filled($scanned->invoiceNumber)) {
            // По знаци, не по бајти: кирилична буква зазема два бајта, па
            // сечење по бајти ја крши последната буква на половина и базата го
            // одбива таквиот текст.
            $this->paperNumber = mb_substr($scanned->invoiceNumber, 0, 40);
        }

        if (filled($scanned->invoiceDate)) {
            $this->invoiceDate = $scanned->invoiceDate;
        }

        if (filled($scanned->dueDate)) {
            $this->dueDate = $scanned->dueDate;
        }

        if (filled($scanned->currency) && in_array($scanned->currency, SalesInvoice::CURRENCIES, true)) {
            $this->currency = $scanned->currency;
        }

        // Проверка 2: партнер по ЕДБ. Точно совпаѓање, без погодување по име —
        // две фирми со слично име се почеста грешка од непостоечки ЕДБ.
        // Се споредуваат само цифрите, на двете страни: скен што прочитал
        // „МК4080…" мора да го најде истиот партнер, а и запишаното во
        // шифрарникот може да носи префикс или празни места. Ако не се
        // нормализира, се нуди дупликат партнер и неговото неканонско ЕДБ
        // заминува кон УЈП како купувач.
        if (filled($scanned->buyerTaxId)) {
            $buyerDigits = $this->digits($scanned->buyerTaxId);

            $partner = $buyerDigits === '' ? null : Partner::where('company_id', $this->company->id)
                ->whereNotNull('tax_id')
                ->get()
                ->first(fn (Partner $candidate) => $this->digits((string) $candidate->tax_id) === $buyerDigits);

            if ($partner) {
                $this->partnerId = (string) $partner->id;
            } else {
                $this->suggestedPartner = [
                    'name' => (string) $scanned->buyerName,
                    'tax_id' => (string) $scanned->buyerTaxId,
                    'street_address' => (string) $scanned->buyerStreetAddress,
                    'street_number' => (string) $scanned->buyerStreetNumber,
                    'postal_code' => (string) $scanned->buyerPostalCode,
                    'city' => (string) $scanned->buyerCity,
                ];
            }
        }

        if ($scanned->lines !== []) {
            $this->lines = array_map(fn (ScannedInvoiceLine $line) => [
                // Ставките од скен се секогаш слободен текст. Врзувањето за
                // артикл од шифрарникот би повлекло и поместување залиха за
                // стока што веќе е издадена надвор од Тами.
                'item_id' => '',
                'description' => (string) $line->description,
                'quantity' => filled($line->quantity) ? $line->quantity : '1',
                'unit_price' => filled($line->unitPrice) ? $line->unitPrice : '0',
                'vat_rate' => filled($line->vatRate) ? $line->vatRate : '0.00',
                // Ослободувањата и преносот на обврска не ги погодува машина.
                'vat_treatment' => 'standard',
            ], $scanned->lines);
        }

        // Проверка 3: сметката мора да се сложи. Пресметаното од ставките се
        // спротивставува со вкупното испишано на хартијата — најсилната
        // заштита од погрешно прочитана бројка. Секоја бројка од скенот е
        // непроверен текст од АИ-модел — bcmath и decimal-кастот фрлаат
        // исклучок на нешто како „1.180,00", а тивко превртено во float би
        // ѝ покажало на сметководителката измислена бројка. Прво се проверува
        // дека секоја бројка е читлива; ако не е, се предупредува без да се
        // пресметува или прикажува ништо.
        if (filled($scanned->printedTotal)) {
            $printed = $this->usableAmount($scanned->printedTotal);
            $unreadable = $printed === null;
            $computed = '0.00';

            foreach ($this->lines as $line) {
                if ($unreadable) {
                    break;
                }

                $unitPrice = $this->usableAmount((string) $line['unit_price']);
                $quantity = $this->usableAmount((string) $line['quantity']);
                $vatRate = $this->usableAmount((string) $line['vat_rate']);

                if ($unitPrice === null || $quantity === null || $vatRate === null) {
                    $unreadable = true;

                    break;
                }

                // Иста аритметика (scale 10, заокружување half-up на 2) со која
                // веќе смета `SalesInvoiceLine` — вкупното мора да се совпадне
                // со она што ќе го носи потврдената фактура, не приближно.
                $lineModel = new SalesInvoiceLine([
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'vat_rate' => $vatRate,
                ]);

                $computed = bcadd($computed, bcadd($lineModel->lineTotal(), $lineModel->vatAmount(), 2), 2);
            }

            if ($unreadable) {
                $this->scanWarnings[] = 'Некои бројки од скенот не можеа да се прочитаат — провери ги ставките и вкупниот износ рачно.';
            } elseif (abs((float) $computed - (float) $printed) > 1.0) {
                $this->scanWarnings[] = "Пресметаното вкупно е {$computed}, а на скенот пишува {$printed} — провери ги ставките.";
            }
        } else {
            // Без испишано вкупно најсилната проверка воопшто не се случува.
            // Тоа мора да се каже: инаку сметководителот гледа само жолта лента
            // и мисли дека износите се проверени спрема нешто.
            $this->scanWarnings[] = 'Вкупниот износ не се прочита од скенот, па ништо не е споредено со него — провери ги ставките и износите рачно.';
        }
    }

    public function save(): void
    {
        Gate::authorize($this->salesInvoice ? 'update' : 'create', $this->salesInvoice ?? SalesInvoice::class);

        // Девизна фактура важи само за физичко лице. Скриено поле во Blade не е
        // заклучување — тоа се прави овде, пред валидацијата.
        if (! $this->company->type->isIndividual()) {
            $this->currency = 'MKD';
            $this->exchangeRate = '1';
        }

        // Истото важи и за хартиениот број: тој оди кон УЈП како `docNumber`,
        // а политиката пушта и `client` да создава и менува нацрти. Скриено
        // поле во Blade не го спречува да го постави преку Livewire, па се
        // заклучува овде. Условот е РОЛЈА, не `canReadScans()` — таа бара и
        // клуч, па на сервер без клуч администратор што менува нацрт би му го
        // избришал бројот. Клиентот што зачувува туѓ нацрт со веќе впишан
        // број не смее ни да го смени, ни да го избрише: се враќа она што е
        // веќе запишано.
        if (! auth()->user()?->hasAnyRole(['admin', 'accountant'])) {
            $this->paperNumber = (string) ($this->salesInvoice?->invoice_number_formatted ?? '');
        }

        $this->validate([
            'partnerId' => ['required', Rule::exists('partners', 'id')->where('company_id', $this->company->id)],
            'warehouseId' => ['nullable', Rule::exists('warehouses', 'id')->where('company_id', $this->company->id)],
            'invoiceDate' => 'required|date',
            'dueDate' => 'required|date|after_or_equal:invoiceDate',
            'paperNumber' => 'nullable|string|max:40',
            'paymentTypeCode' => ['required', Rule::in(array_keys(SalesInvoice::PAYMENT_TYPES))],
            'currency' => ['required', Rule::in(SalesInvoice::CURRENCIES)],
            'exchangeRate' => [
                $this->currency === 'MKD' ? 'nullable' : 'required',
                'numeric',
                'gt:0',
            ],
            'lines' => 'required|array|min:1',
            'lines.*.item_id' => ['nullable', Rule::exists('items', 'id')->where('company_id', $this->company->id)],
            'lines.*.description' => 'nullable|string|max:255',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.vat_rate' => 'required|numeric|min:0|max:100',
            'lines.*.vat_treatment' => ['required', Rule::in(SalesInvoiceLine::TREATMENTS)],
        ]);

        // Двоен испишан број во иста фирма и иста година е вистински проблем —
        // кон УЈП би заминале две фактури со ист `docNumber`. Годината се зема
        // од датумот на фактурата, бидејќи `fiscal_year` се полни дури при
        // потврда, а проверката мора да важи и на нацрт.
        if ($this->paperNumber !== '') {
            $clash = SalesInvoice::where('company_id', $this->company->id)
                ->where('invoice_number_formatted', $this->paperNumber)
                ->whereYear('invoice_date', Carbon::parse($this->invoiceDate)->year)
                ->when($this->salesInvoice, fn ($query) => $query->whereKeyNot($this->salesInvoice->id))
                ->exists();

            if ($clash) {
                $this->addError('paperNumber', "Веќе постои фактура со број {$this->paperNumber} во таа година.");

                return;
            }
        }

        foreach ($this->lines as $index => $line) {
            if (($line['vat_treatment'] ?? 'standard') !== 'standard') {
                $this->lines[$index]['vat_rate'] = '0.00';
            }
        }

        foreach ($this->lines as $index => $line) {
            if (($line['item_id'] ?? '') === '' && trim((string) ($line['description'] ?? '')) === '') {
                $this->addError("lines.{$index}.description", 'Секоја ставка мора да содржи артикл или опис.');

                return;
            }
        }

        $hasItemLines = collect($this->lines)->contains(fn ($line) => ($line['item_id'] ?? '') !== '');

        if ($hasItemLines && $this->warehouseId === '') {
            $this->addError('warehouseId', 'Потребен е магацин кога некоја ставка содржи артикл.');

            return;
        }

        DB::transaction(function () {
            $invoice = $this->salesInvoice ?? new SalesInvoice([
                'company_id' => $this->company->id,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);
            $invoice->company_id = $this->company->id;
            $invoice->partner_id = $this->partnerId;
            $invoice->warehouse_id = $this->warehouseId ?: null;
            $invoice->invoice_date = $this->invoiceDate;
            $invoice->due_date = $this->dueDate;
            $invoice->notes = $this->notes ?: null;
            $invoice->invoice_number_formatted = $this->paperNumber ?: null;
            $invoice->payment_type_code = $this->paymentTypeCode;
            $invoice->currency = $this->currency;
            $invoice->exchange_rate = $this->currency === 'MKD' ? '1' : $this->exchangeRate;

            // Јазикот се презема од кооперантот на секое зачувување на нацртот,
            // па промена на купувачот го носи и неговиот јазик. По потврда
            // фактурата повеќе не поминува одовде и текстот останува замрзнат.
            $partner = Partner::where('company_id', $this->company->id)->find($this->partnerId);
            $invoice->language = $this->company->type->isIndividual() && $partner
                ? $partner->invoice_language
                : InvoiceLanguage::MK;

            if (! $invoice->exists) {
                $invoice->status = 'draft';
                $invoice->created_by = auth()->id();
            }

            $invoice->save();
            $invoice->lines()->delete();

            foreach ($this->lines as $line) {
                $invoice->lines()->create([
                    'item_id' => $line['item_id'] ?: null,
                    'description' => $line['description'] ?: null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'vat_rate' => $line['vat_rate'],
                    'vat_treatment' => $line['vat_treatment'] ?? 'standard',
                ]);
            }

            $this->salesInvoice = $invoice;
        });

        // Качувањето оди по трансакцијата намерно: складот е Google Drive, а
        // мрежен повик внатре во отворена трансакција ја држи базата заклучена
        // додека трае. Ако качувањето падне, фактурата е веќе зачувана и
        // прилогот може да се додаде рачно од екранот на фактурата.
        if ($this->scanFile !== null && $this->scanRead) {
            try {
                DocumentStorage::store($this->salesInvoice, $this->scanFile, 'Invoice', 'Скенирана фактура');
            } catch (\Throwable $e) {
                report($e);
                session()->flash('warning', 'Фактурата е зачувана, но скенот не се прикачи — додај го рачно од екранот на фактурата.');
            }

            $this->scanFile = null;
        }

        $this->redirect(route('sales-invoices.show', [$this->company, $this->salesInvoice]));
    }

    public function render()
    {
        return view('livewire.invoicing.sales-invoice-form', [
            'partners' => Partner::where('company_id', $this->company->id)->orderBy('name')->get(),
            'warehouses' => Warehouse::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
            'items' => Item::where('company_id', $this->company->id)->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /**
     * ЕДБ-то на хартија понекогаш носи префикс „МК“ или празни места. Се
     * споредуваат само цифрите.
     */
    private function digits(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * Бројка прочитана од скен е непроверен текст од АИ-модел — може да носи
     * илјадници одвоени со точка, запирка наместо точка, или воопшто да не
     * личи на број. bcmath и decimal-кастот на моделите фрлаат исклучок на
     * таква низа, па се проверува овде, пред да стигне до нив. Враќа
     * нормализирана бројка или null ако низата не личи на број.
     */
    private function usableAmount(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $value)) {
            return null;
        }

        return $value;
    }
}

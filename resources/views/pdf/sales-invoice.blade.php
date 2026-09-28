<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $company = $invoice->company;
        $lang = $invoice->language;
        $currency = $invoice->currency;
        $logoPosition = ($company->logo_position ?? null) ?: 'left';
        $vatRegistered = (bool) $company->is_vat_registered;
        // Колоната за рабат се појавува само кога барем една ставка има рабат —
        // фактура без рабат се печати точно како порано.
        $hasDiscount = $invoice->lines->contains(fn ($line) => $line->hasDiscount());
        // Only treat the logo as usable when the configured file actually exists on
        // disk — a stale logo_path would otherwise render a broken-image placeholder.
        $logoPath = $company->logo_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path)
            : null;
        $hasLogo = $logoPath && file_exists($logoPath);
        // Распределба на ДДВ по стапки — само кога навистина има повеќе од
        // една стапка на фактурата (со само една стапка е чист повтор на
        // кутијата со вкупни износи).
        $vatByRate = [];
        if ($vatRegistered) {
            $vatByRate = $invoice->lines->groupBy(fn ($line) => (string) $line->vat_rate)
                ->map(function ($lines) {
                    $base = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->lineTotal(), 2), '0.00');
                    $vat = $lines->reduce(fn ($carry, $line) => bcadd($carry, $line->vatAmount(), 2), '0.00');

                    return ['rate' => $lines->first()->vat_rate, 'base' => $base, 'vat' => $vat, 'total' => bcadd($base, $vat, 2)];
                })
                ->sortKeys()
                ->all();
        }
    @endphp
    <style>
        /*
         * dompdf 3.1.6 has no flex layout implementation: Css/Style.php::_compute_display()
         * downgrades `flex` to `block` and `inline-flex` to `inline-block`. Every
         * multi-column region below is therefore built with <table>/<td> and explicit
         * widths, which is dompdf's best-supported layout primitive.
         */
        body { font-family: 'DejaVu Sans'; font-size: 11px; color: #000000; margin: 0; padding: 0; }
        .accent-bar { height: 6px; background-color: #000000; }
        .content { padding: 18px 24px; }
        .badge { display: inline-block; font-weight: bold; font-size: 18px; color: #000000; }
        .muted { color: #000000; }
        .small { font-size: 10px; }
        .label { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #ff6600; margin: 0 0 4px; }
        table.info-table { border-collapse: collapse; }
        table.info-table td { padding: 0 0 1px; vertical-align: top; }
        td.info-label { color: #000000; padding-right: 6px; white-space: nowrap; }
        table.letterhead { width: 100%; border-collapse: collapse; }
        table.letterhead td { vertical-align: top; padding: 0; }
        {{-- Купувач и Издавач — иста широчина, центрирани на страницата, една
             до друга, во нормален тек (без апсолутно позиционирање). --}}
        table.party-row { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.party-row td { vertical-align: top; width: 47%; }
        table.party-row td.party-gap { width: 6%; }
        .address-window, .issuer-box { height: 40mm; font-size: 10px; border: 1px solid #d1d5db; border-radius: 6px; padding: 6px 10px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th { text-align: left; font-size: 10px; color: #000000; font-weight: bold; border-bottom: 1.5px solid #111827; padding: 6px; }
        table.items td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
        table.totals-row { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.vat-breakdown { border-collapse: collapse; font-size: 9px; }
        table.vat-breakdown th { text-align: right; font-weight: normal; color: #000000; padding: 0 8px 3px 0; border-bottom: 1px solid #d1d5db; }
        table.vat-breakdown th:first-child, table.vat-breakdown td:first-child { text-align: left; padding-left: 0; }
        table.vat-breakdown td { text-align: right; padding: 2px 8px 2px 0; }
        .totals-box { border: 1px solid #d1d5db; border-radius: 6px; padding: 10px 14px; font-size: 11px; }
        table.totals { width: 100%; border-collapse: collapse; font-size: 11px; }
        .totals-box tr.grand td { border-top: 1px solid #111827; font-weight: bold; color: #000000; }
        .pay-box { border: 1px solid #d1d5db; border-radius: 6px; padding: 10px 12px; font-size: 10px; margin-top: 12px; }
        .pay-box h4 { font-size: 9px; text-transform: uppercase; color: #000000; margin: 0 0 6px; }
        table.pay-table { width: 100%; border-collapse: collapse; }
        table.pay-table td { padding: 1px 0; vertical-align: top; }
        td.pay-label { width: 108px; color: #000000; }
        /* Размакот е намерно голем: линијата треба да има простор над себе за
           вистински потпис, и да се одвои од фуснотата и од износите. */
        table.signatures { width: 100%; border-collapse: collapse; margin-top: 90px; page-break-inside: avoid; }
        table.signatures td { width: 50%; padding: 0 24px; vertical-align: bottom; }
        .sig-line { border-top: 1px solid #9ca3af; height: 0; font-size: 0; }
        .sig-label { text-align: center; font-size: 9px; color: #000000; margin-top: 4px; letter-spacing: .05em; }
        @if (! $vatRegistered || $company->invoice_footer_note)
            /* position: fixed е специјален случај во dompdf — единствениот начин
               навистина да се залепи нешто на дното на страницата (не под
               содржината каде и да падне таа), исто како стандардна фуснота во
               печатен документ. Се повторува на секоја страница по конструкција. */
            .footnotes { position: fixed; bottom: 0; left: 0; right: 0; padding: 0 24px 14px; font-size: 9px; color: #000000; }
            .footnotes p { margin: 2px 0; }
        @endif
        @if ($hasLogo && $logoPosition === 'center')
            .logo-row { text-align: center; margin-bottom: 10px; }
        @endif
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="content">
        @if ($hasLogo && $logoPosition === 'center')
            <div class="logo-row">
                <img src="{{ $logoPath }}" style="max-height: 40px;">
            </div>
        @endif

        {{-- Писмо-заглавие: само логото и бројот/датумите на фактурата —
             компактно, без рамка. Издавачот и купувачот се подолу, во две
             рамки една до друга, центрирани. --}}
        <table class="letterhead">
            <tr>
                @if ($logoPosition === 'right')
                    <td style="width: 50%;">
                        <span class="badge">{{ $lang->t('invoice') }} {{ $invoice->formattedNumber() }}</span>
                        <div class="small muted" style="margin-top: 6px;">
                            {{ $lang->t('invoice_date') }}: {{ $lang->date($invoice->invoice_date) }}<br>
                            {{ $lang->t('due_date') }}: {{ $lang->date($invoice->due_date) }}
                        </div>
                    </td>
                    <td style="width: 50%; text-align: right;">
                        @if ($hasLogo)
                            <img src="{{ $logoPath }}" style="max-height: 40px;">
                        @endif
                    </td>
                @else
                    <td style="width: 50%;">
                        @if ($hasLogo && $logoPosition !== 'center')
                            <img src="{{ $logoPath }}" style="max-height: 40px;">
                        @endif
                    </td>
                    <td style="width: 50%; text-align: right;">
                        <span class="badge">{{ $lang->t('invoice') }} {{ $invoice->formattedNumber() }}</span>
                        <div class="small muted" style="margin-top: 6px;">
                            {{ $lang->t('invoice_date') }}: {{ $lang->date($invoice->invoice_date) }}<br>
                            {{ $lang->t('due_date') }}: {{ $lang->date($invoice->due_date) }}
                        </div>
                    </td>
                @endif
            </tr>
        </table>

        {{-- Купувач и Издавач — иста широчина, центрирани на страницата. --}}
        <table class="party-row">
            <tr>
                <td>
                    <div class="address-window">
                        <div class="label">{{ $lang->t('buyer') }}</div>
                        <div><strong>{{ $invoice->partner->name }}</strong></div>
                        <div>{{ $invoice->partner->printedAddress() }}</div>
                        @if ($lang === \App\Support\InvoiceLanguage::EN && $invoice->partner->country)
                            <div>{{ $invoice->partner->country }}</div>
                        @endif
                        @if ($invoice->partner->tax_id)
                            <div style="margin-top: 4px;">{{ $lang->t('tax_id') }}: {{ $invoice->partner->tax_id }}</div>
                        @endif
                    </div>
                </td>
                <td class="party-gap"></td>
                <td>
                    {{-- Контактот е листа назив:вредност (не сплескан на еден
                         ред со точки) — полесно се чита, исто како стандардна
                         деловна писмо-глава. --}}
                    <div class="issuer-box">
                        <div class="label">{{ $lang->t('seller') }}</div>
                        <div style="margin-bottom: 3px;"><strong>{{ $company->name }}</strong></div>
                        <table class="info-table small">
                            <tr>
                                <td class="info-label">{{ $lang->t('address') }}:</td>
                                <td>{{ $company->address }}</td>
                            </tr>
                            <tr>
                                <td class="info-label">{{ $lang->t('tax_id') }}:</td>
                                <td>{{ $company->tax_id }}</td>
                            </tr>
                            @if ($company->registration_number)
                                <tr>
                                    <td class="info-label">{{ $lang->t('registration_number') }}:</td>
                                    <td>{{ $company->registration_number }}</td>
                                </tr>
                            @endif
                            @if ($company->phone)
                                <tr>
                                    <td class="info-label">{{ $lang->t('phone') }}:</td>
                                    <td>{{ $company->phone }}</td>
                                </tr>
                            @endif
                            @if ($company->email)
                                <tr>
                                    <td class="info-label">{{ $lang->t('email') }}:</td>
                                    <td>{{ $company->email }}</td>
                                </tr>
                            @endif
                            @if ($company->website)
                                <tr>
                                    <td class="info-label">{{ $lang->t('website') }}:</td>
                                    <td>{{ $company->website }}</td>
                                </tr>
                            @endif
                            @if ($lang === \App\Support\InvoiceLanguage::EN)
                                <tr>
                                    <td></td>
                                    <td>{{ $lang->t('seller_country') }}</td>
                                </tr>
                            @endif
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 22px;">{{ $lang->t('line_no') }}</th>
                    <th style="width: 46px;">{{ $lang->t('code') }}</th>
                    <th>{{ $lang->t('description') }}</th>
                    <th style="width: 34px;">{{ $lang->t('unit') }}</th>
                    <th style="width: 42px;">{{ $lang->t('quantity') }}</th>
                    <th style="width: 68px;">{{ $lang->t('unit_price') }}</th>
                    @if ($hasDiscount)
                        <th style="width: 48px;">{{ $lang->t('discount_percent') }}</th>
                    @endif
                    @if ($vatRegistered)
                        <th style="width: 62px;">{{ $lang->t('vat_percent') }}</th>
                        <th style="width: 72px;">{{ $lang->t('vat_amount') }}</th>
                    @endif
                    <th style="width: 82px;">{{ $vatRegistered ? $lang->t('total_with_vat') : $lang->t('total') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $index => $line)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $line->item?->code }}</td>
                        <td>
                            {{ $line->description }}
                            @if ($line->vat_treatment !== 'standard')
                                <div class="small muted">{{ $lang->vatTreatment($line->vat_treatment) }}</div>
                            @endif
                        </td>
                        <td>{{ $line->item?->unit_of_measure ?: ($lang === \App\Support\InvoiceLanguage::EN ? 'pcs' : 'бр.') }}</td>
                        <td>{{ $line->quantity }}</td>
                        <td>{{ $lang->money($hasDiscount ? $line->originalUnitPrice() : $line->effectiveUnitPrice(), $currency, $line->isGrossEntered() ? 4 : 2) }}</td>
                        @if ($hasDiscount)
                            <td>{{ $line->hasDiscount() ? \App\Support\Format::rate($line->discount_percent) : '' }}</td>
                        @endif
                        @if ($vatRegistered)
                            <td>{{ $line->vat_rate }}</td>
                            <td>{{ $lang->money($line->vatAmount(), $currency) }}</td>
                        @endif
                        <td>{{ $lang->money(bcadd($line->lineTotal(), $line->vatAmount(), 2), $currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals-row">
            <tr>
                <td style="vertical-align: bottom;">
                    @if (count($vatByRate) > 1)
                        <table class="vat-breakdown">
                            <tr>
                                <th>{{ $lang->t('vat_percent') }}</th>
                                <th>{{ $lang->t('vat_base') }}</th>
                                <th>{{ $lang->t('vat') }}</th>
                                <th>{{ $lang->t('total') }}</th>
                            </tr>
                            @foreach ($vatByRate as $row)
                                <tr>
                                    <td>{{ \App\Support\Format::rate($row['rate']) }}</td>
                                    <td>{{ $lang->money($row['base'], $currency) }}</td>
                                    <td>{{ $lang->money($row['vat'], $currency) }}</td>
                                    <td>{{ $lang->money($row['total'], $currency) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                </td>
                <td style="width: 210px;">
                    <div class="totals-box">
                        <table class="totals">
                            <tr>
                                <td style="text-align: left; padding: 2px 0;">{{ $lang->t('subtotal') }}</td>
                                <td style="text-align: right; padding: 2px 0;">{{ $lang->money($invoice->subtotal(), $currency) }}</td>
                            </tr>
                            <tr>
                                <td style="text-align: left; padding: 2px 0;">{{ $lang->t('vat') }}</td>
                                <td style="text-align: right; padding: 2px 0;">{{ $lang->money($invoice->vatTotal(), $currency) }}</td>
                            </tr>
                            <tr class="grand">
                                <td style="text-align: left; padding: 6px 0 2px;">{{ $lang->t('total') }}</td>
                                <td style="text-align: right; padding: 6px 0 2px;">{{ $lang->money($invoice->grandTotal(), $currency) }}</td>
                            </tr>
                            <tr>
                                <td style="text-align: left; padding: 2px 0;">{{ $lang->t('balance_due') }}</td>
                                <td style="text-align: right; padding: 2px 0;">{{ $lang->money($invoice->balanceDue(), $currency) }}</td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <div class="pay-box">
            <h4>{{ $lang->t('payment_details') }}</h4>
            @php $mainAccount = $company->bankAccounts->first(); @endphp
            <table class="pay-table">
                <tr>
                    <td class="pay-label">{{ $lang->t('beneficiary') }}</td>
                    <td>{{ $company->name }}</td>
                </tr>
                @if ($mainAccount && $mainAccount->bank_name)
                    <tr>
                        <td class="pay-label">{{ $lang->t('beneficiary_bank') }}</td>
                        <td>{{ $mainAccount->bank_name }}</td>
                    </tr>
                @endif
                @if ($mainAccount && $mainAccount->account_number)
                    <tr>
                        <td class="pay-label">{{ $lang->t('account') }}</td>
                        <td>{{ $mainAccount->account_number }}</td>
                    </tr>
                @endif
                @if ($lang === \App\Support\InvoiceLanguage::EN)
                    @php $ibanValue = $mainAccount?->iban ?: $mainAccount?->account_number; @endphp
                    @if ($ibanValue)
                        <tr>
                            <td class="pay-label">{{ $lang->t('iban') }}</td>
                            <td>{{ $ibanValue }}</td>
                        </tr>
                    @endif
                    @if ($mainAccount && $mainAccount->swift)
                        <tr>
                            <td class="pay-label">{{ $lang->t('swift') }}</td>
                            <td>{{ $mainAccount->swift }}</td>
                        </tr>
                    @endif
                @endif
                <tr>
                    <td class="pay-label">{{ $lang->t('amount') }}</td>
                    <td>{{ $lang->money($invoice->grandTotal(), $currency) }}</td>
                </tr>
                <tr>
                    <td class="pay-label">{{ $lang->t('payment_reference') }}</td>
                    <td>{{ $invoice->formattedNumber() }}</td>
                </tr>
            </table>
            @unless ($mainAccount)
                <div class="muted" style="margin-top: 4px;">{{ $lang->t('no_bank_account') }}</div>
            @endunless
        </div>
        <table class="signatures">
            <tr>
                <td>
                    <div class="sig-line"></div>
                    <div class="sig-label">{{ $lang->t('signature_issuer') }}</div>
                </td>
                <td>
                    <div class="sig-line"></div>
                    <div class="sig-label">{{ $lang->t('signature_receiver') }}</div>
                </td>
            </tr>
        </table>
    </div>
    @php
        $footnotes = [];
        if (! $vatRegistered) {
            $footnotes[] = $lang->t('not_vat_registered');
        }
        if ($company->invoice_footer_note) {
            $footnotes[] = $company->invoice_footer_note;
        }
    @endphp
    @if (count($footnotes))
        <div class="footnotes">
            @foreach ($footnotes as $note)
                <p>{{ $note }}</p>
            @endforeach
        </div>
    @endif
</body>
</html>

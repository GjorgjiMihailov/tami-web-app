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
        // Only treat the logo as usable when the configured file actually exists on
        // disk — a stale logo_path would otherwise render a broken-image placeholder.
        $logoPath = $company->logo_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path)
            : null;
        $hasLogo = $logoPath && file_exists($logoPath);
    @endphp
    <style>
        /*
         * dompdf 3.1.6 has no flex layout implementation: Css/Style.php::_compute_display()
         * downgrades `flex` to `block` and `inline-flex` to `inline-block`. Every
         * multi-column region below is therefore built with <table>/<td> and explicit
         * widths, which is dompdf's best-supported layout primitive.
         */
        body { font-family: 'DejaVu Sans'; font-size: 11px; color: #1f2937; margin: 0; padding: 0; }
        .accent-bar { height: 6px; background-color: #ff6600; }
        .content { padding: 18px 24px; }
        .badge { display: inline-block; background-color: #fff3ea; color: #ff6600; font-weight: bold; font-size: 14px; padding: 6px 14px; border-radius: 8px; }
        .muted { color: #6b7280; }
        .small { font-size: 10px; }
        table.header-row { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.header-row td { vertical-align: top; width: 50%; padding: 0; }
        table.parties-row { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.parties-row td { vertical-align: top; width: 50%; }
        .party-box { background-color: #f9fafb; border-radius: 8px; padding: 8px 12px; }
        .party-box h4 { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #ff6600; margin: 0 0 4px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th { text-align: left; font-size: 10px; color: #6b7280; background-color: #f9fafb; padding: 6px; }
        table.items td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
        table.bottom-row { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.bottom-row td { vertical-align: top; }
        .pay-box { background-color: #f9fafb; border-radius: 8px; padding: 10px 12px; font-size: 10px; }
        .pay-box h4 { font-size: 9px; text-transform: uppercase; color: #6b7280; margin: 0 0 6px; }
        table.pay-table { width: 100%; border-collapse: collapse; }
        table.pay-table td { padding: 1px 0; vertical-align: top; }
        td.pay-label { width: 108px; color: #6b7280; }
        .totals-box { background-color: #fff3ea; border-radius: 8px; padding: 10px 14px; font-size: 11px; }
        table.totals { width: 100%; border-collapse: collapse; font-size: 11px; }
        .totals-box tr.grand td { border-top: 1px solid #ffd4b0; font-weight: bold; color: #b34700; }
        /* Размакот е намерно голем: линијата треба да има простор над себе за
           вистински потпис, и да се одвои од фуснотата и од износите. */
        table.signatures { width: 100%; border-collapse: collapse; margin-top: 90px; page-break-inside: avoid; }
        table.signatures td { width: 50%; padding: 0 24px; vertical-align: bottom; }
        .sig-line { border-top: 1px solid #9ca3af; height: 0; font-size: 0; }
        .sig-label { text-align: center; font-size: 9px; color: #6b7280; margin-top: 4px; letter-spacing: .05em; }
        @if (! $vatRegistered || $company->invoice_footer_note)
            .footnotes { margin-top: 16px; font-size: 9px; color: #6b7280; }
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
                <img src="{{ $logoPath }}" style="max-height: 56px;">
            </div>
        @endif

        <table class="header-row">
            <tr>
                @if ($logoPosition === 'right')
                    <td style="text-align: left;">
                        <span class="badge">{{ $lang->t('invoice') }} {{ $invoice->formattedNumber() }}</span>
                        <div class="small muted" style="margin-top: 6px;">
                            {{ $lang->t('invoice_date') }}: {{ $lang->date($invoice->invoice_date) }}<br>
                            {{ $lang->t('due_date') }}: {{ $lang->date($invoice->due_date) }}
                        </div>
                    </td>
                    <td style="text-align: right;">
                        @if ($hasLogo)
                            <img src="{{ $logoPath }}" style="max-height: 56px;">
                        @endif
                    </td>
                @else
                    <td style="text-align: left;">
                        @if ($hasLogo && $logoPosition !== 'center')
                            <img src="{{ $logoPath }}" style="max-height: 56px;">
                        @endif
                    </td>
                    <td style="text-align: right;">
                        <span class="badge">{{ $lang->t('invoice') }} {{ $invoice->formattedNumber() }}</span>
                        <div class="small muted" style="margin-top: 6px;">
                            {{ $lang->t('invoice_date') }}: {{ $lang->date($invoice->invoice_date) }}<br>
                            {{ $lang->t('due_date') }}: {{ $lang->date($invoice->due_date) }}
                        </div>
                    </td>
                @endif
            </tr>
        </table>

        <table class="parties-row">
            <tr>
                <td style="padding-right: 6px;">
                    <div class="party-box">
                        <h4>{{ $lang->t('seller') }}</h4>
                        <div><strong>{{ $company->name }}</strong></div>
                        <div class="small muted">{{ $company->address }}</div>
                        <div class="small muted">
                            {{ $lang->t('tax_id') }}: {{ $company->tax_id }}
                            @if ($company->registration_number)
                                · {{ $lang->t('registration_number') }}: {{ $company->registration_number }}
                            @endif
                        </div>
                        @if ($company->phone || $company->email)
                            <div class="small muted">{{ collect([$company->phone, $company->email])->filter()->implode(' · ') }}</div>
                        @endif
                        @if ($lang === \App\Support\InvoiceLanguage::EN)
                            <div class="small muted">{{ $lang->t('seller_country') }}</div>
                        @endif
                    </div>
                </td>
                <td style="padding-left: 6px;">
                    <div class="party-box">
                        <h4>{{ $lang->t('buyer') }}</h4>
                        <div><strong>{{ $invoice->partner->name }}</strong></div>
                        <div class="small muted">{{ $invoice->partner->address }}</div>
                        @if ($lang === \App\Support\InvoiceLanguage::EN && $invoice->partner->country)
                            <div class="small muted">{{ $invoice->partner->country }}</div>
                        @endif
                        @if ($invoice->partner->tax_id)
                            <div class="small muted">{{ $lang->t('tax_id') }}: {{ $invoice->partner->tax_id }}</div>
                        @endif
                    </div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 22px;">{{ $lang->t('line_no') }}</th>
                    <th>{{ $lang->t('description') }}</th>
                    <th style="width: 42px;">{{ $lang->t('quantity') }}</th>
                    <th style="width: 68px;">{{ $lang->t('unit_price') }}</th>
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
                        <td>
                            {{ $line->description }}
                            @if ($line->vat_treatment !== 'standard')
                                <div class="small muted">{{ $lang->vatTreatment($line->vat_treatment) }}</div>
                            @endif
                        </td>
                        <td>{{ $line->quantity }}</td>
                        <td>{{ $lang->money($line->effectiveUnitPrice(), $currency, $line->isGrossEntered() ? 4 : 2) }}</td>
                        @if ($vatRegistered)
                            <td>{{ $line->vat_rate }}</td>
                            <td>{{ $lang->money($line->vatAmount(), $currency) }}</td>
                        @endif
                        <td>{{ $lang->money(bcadd($line->lineTotal(), $line->vatAmount(), 2), $currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="bottom-row">
            <tr>
                <td style="padding-right: 12px;">
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
</body>
</html>

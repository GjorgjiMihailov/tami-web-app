<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $partner = $proforma->partner;
        $currency = $proforma->currency;
        $vatRegistered = (bool) $company->is_vat_registered;
    @endphp
    <style>
        /* dompdf нема flex — сите колони се табели со фиксни широчини. */
        body { font-family: 'DejaVu Sans'; font-size: 11px; color: #1f2937; margin: 0; padding: 0; }
        .accent-bar { height: 6px; background-color: #ff6600; }
        .content { padding: 18px 24px; }
        .badge { display: inline-block; background-color: #fff3ea; color: #ff6600; font-weight: bold; font-size: 14px; padding: 6px 14px; border-radius: 8px; }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        table.two td { vertical-align: top; width: 50%; padding: 0; }
        .party-box { background-color: #f9fafb; border-radius: 8px; padding: 8px 12px; }
        .party-box h4 { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #ff6600; margin: 0 0 4px; }
        table.items { margin-top: 14px; }
        table.items th { text-align: left; font-size: 10px; color: #6b7280; background-color: #f9fafb; padding: 6px; }
        table.items th.right { text-align: right; }
        table.items td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
        .totals-box { background-color: #fff3ea; border-radius: 8px; padding: 10px 14px; }
        table.totals td { padding: 2px 0; }
        table.totals tr.grand td { border-top: 1px solid #ffd4b0; font-weight: bold; color: #b34700; }
        h4.section { font-size: 9px; text-transform: uppercase; color: #6b7280; margin: 14px 0 3px; }
        .footnote { margin-top: 18px; font-size: 9px; color: #6b7280; }
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="content">
        <table class="two" style="margin-bottom: 14px;">
            <tr>
                <td>
                    <strong style="font-size: 13px;">{{ $company->name }}</strong>
                    @if ($company->tax_id)<div class="muted">{{ $lang->t('tax_id') }}: {{ $company->tax_id }}</div>@endif
                </td>
                <td class="right">
                    <span class="badge">{{ $lang->t('proforma') }}</span>
                    <div style="margin-top: 6px; font-size: 13px; font-weight: bold;">{{ $proforma->proforma_number_formatted }}</div>
                </td>
            </tr>
        </table>

        <table class="two">
            <tr>
                <td style="padding-right: 8px;">
                    <div class="party-box">
                        <h4>{{ $lang->t('buyer') }}</h4>
                        <strong>{{ $partner->name }}</strong>
                        @if ($partner->tax_id)<div>{{ $lang->t('tax_id') }}: {{ $partner->tax_id }}</div>@endif
                        @if ($partner->printedAddress())<div>{{ $partner->printedAddress() }}</div>@endif
                    </div>
                </td>
                <td style="padding-left: 8px;">
                    <table>
                        <tr><td class="muted">{{ $lang->t('proforma_date') }}</td><td class="right">{{ $lang->date($proforma->proforma_date) }}</td></tr>
                        @if ($proforma->reference)
                            <tr><td class="muted">{{ $lang->t('reference') }}</td><td class="right">{{ $proforma->reference }}</td></tr>
                        @endif
                        @if ($proforma->expected_delivery_date)
                            <tr><td class="muted">{{ $lang->t('expected_delivery') }}</td><td class="right">{{ $lang->date($proforma->expected_delivery_date) }}</td></tr>
                        @endif
                        @if ($proforma->payment_terms_days !== null)
                            <tr><td class="muted">{{ $lang->t('payment_terms') }}</td><td class="right">{{ $proforma->payment_terms_days === 0 ? $lang->t('on_receipt') : $proforma->payment_terms_days.' '.$lang->t('days') }}</td></tr>
                        @endif
                    </table>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>{{ $lang->t('line_no') }}</th>
                    <th>{{ $lang->t('description') }}</th>
                    <th class="right">{{ $lang->t('quantity') }}</th>
                    <th class="right">{{ $lang->t('unit_price') }}</th>
                    @if ($vatRegistered)<th class="right">{{ $lang->t('vat_percent') }}</th>@endif
                    <th class="right">{{ $lang->t('amount') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($proforma->lines as $line)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td>{{ $line->description }}</td>
                        <td class="right">{{ rtrim(rtrim(number_format((float) $line->quantity, 3, $lang->value === 'mk' ? ',' : '.', $lang->value === 'mk' ? '.' : ','), '0'), $lang->value === 'mk' ? ',' : '.') }}@if ($line->item) {{ $line->item->unit_of_measure }}@endif</td>
                        <td class="right">{{ $lang->money($line->unit_price, $currency) }}</td>
                        @if ($vatRegistered)<td class="right">{{ \App\Support\Format::rate($line->vat_rate) }}</td>@endif
                        <td class="right">{{ $lang->money($line->lineTotal(), $currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table style="margin-top: 14px;">
            <tr>
                <td style="width: 55%;"></td>
                <td>
                    <div class="totals-box">
                        <table class="totals">
                            <tr><td>{{ $lang->t('subtotal') }}</td><td class="right">{{ $lang->money($proforma->subtotal(), $currency) }}</td></tr>
                            @if ($vatRegistered)
                                <tr><td>{{ $lang->t('vat') }}</td><td class="right">{{ $lang->money($proforma->vatTotal(), $currency) }}</td></tr>
                            @endif
                            <tr class="grand"><td>{{ $lang->t($vatRegistered ? 'total_with_vat' : 'total') }}</td><td class="right">{{ $lang->money($proforma->grandTotal(), $currency) }}</td></tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        @if ($proforma->notes)
            <h4 class="section">{{ $lang->t('notes') }}</h4>
            <div>{!! nl2br(e($proforma->notes)) !!}</div>
        @endif
        @if ($proforma->terms)
            <h4 class="section">{{ $lang->t('terms') }}</h4>
            <div>{!! nl2br(e($proforma->terms)) !!}</div>
        @endif

        <div class="footnote">
            <p>{{ $lang->t('not_a_tax_invoice') }}</p>
            @unless ($vatRegistered)<p>{{ $lang->t('not_vat_registered') }}</p>@endunless
        </div>
    </div>
</body>
</html>

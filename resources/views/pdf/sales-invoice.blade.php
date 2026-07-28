<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $company = $invoice->company;
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
        .totals-box { background-color: #fff3ea; border-radius: 8px; padding: 10px 14px; font-size: 11px; }
        table.totals { width: 100%; border-collapse: collapse; font-size: 11px; }
        .totals-box tr.grand td { border-top: 1px solid #ffd4b0; font-weight: bold; color: #b34700; }
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
                        <span class="badge">ФАКТУРА {{ $invoice->fiscal_year }}/{{ $invoice->invoice_number }}</span>
                        <div class="small muted" style="margin-top: 6px;">
                            Датум на фактура: {{ \App\Support\Format::date($invoice->invoice_date) }}<br>
                            Датум на доспевање: {{ \App\Support\Format::date($invoice->due_date) }}
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
                        <span class="badge">ФАКТУРА {{ $invoice->fiscal_year }}/{{ $invoice->invoice_number }}</span>
                        <div class="small muted" style="margin-top: 6px;">
                            Датум на фактура: {{ \App\Support\Format::date($invoice->invoice_date) }}<br>
                            Датум на доспевање: {{ \App\Support\Format::date($invoice->due_date) }}
                        </div>
                    </td>
                @endif
            </tr>
        </table>

        <table class="parties-row">
            <tr>
                <td style="padding-right: 6px;">
                    <div class="party-box">
                        <h4>Издавач</h4>
                        <div><strong>{{ $company->name }}</strong></div>
                        <div class="small muted">{{ $company->address }}</div>
                        <div class="small muted">
                            ЕДБ: {{ $company->tax_id }}
                            @if ($company->registration_number)
                                · ЕМБС: {{ $company->registration_number }}
                            @endif
                        </div>
                        @if ($company->phone || $company->email)
                            <div class="small muted">{{ collect([$company->phone, $company->email])->filter()->implode(' · ') }}</div>
                        @endif
                    </div>
                </td>
                <td style="padding-left: 6px;">
                    <div class="party-box">
                        <h4>Купувач</h4>
                        <div><strong>{{ $invoice->partner->name }}</strong></div>
                        <div class="small muted">{{ $invoice->partner->address }}</div>
                        @if ($invoice->partner->tax_id)
                            <div class="small muted">ЕДБ: {{ $invoice->partner->tax_id }}</div>
                        @endif
                    </div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 24px;">Р.б.</th>
                    <th>Опис</th>
                    <th style="width: 50px;">Кол.</th>
                    <th style="width: 80px;">Ед. цена</th>
                    @if ($vatRegistered)
                        <th style="width: 100px;">ДДВ %</th>
                    @endif
                    <th style="width: 100px;">{{ $vatRegistered ? 'Вкупно со ДДВ' : 'Вкупно' }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $index => $line)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $line->description }}</td>
                        <td>{{ $line->quantity }}</td>
                        <td>{{ \App\Support\Format::money($line->unit_price) }}</td>
                        @if ($vatRegistered)
                            <td>{{ $line->vat_rate }}{{ $line->vat_treatment !== 'standard' ? ' ('.\App\Support\Format::vatTreatment($line->vat_treatment).')' : '' }}</td>
                        @endif
                        <td>{{ \App\Support\Format::money(bcadd($line->lineTotal(), $line->vatAmount(), 2)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="bottom-row">
            <tr>
                <td style="padding-right: 12px;">
                    <div class="pay-box">
                        <h4>Начин на плаќање</h4>
                        @forelse ($company->bankAccounts as $bankAccount)
                            <div>{{ $bankAccount->bank_name ? $bankAccount->bank_name.': ' : '' }}{{ $bankAccount->account_number }}</div>
                        @empty
                            <div class="muted">Нема внесена банкарска сметка.</div>
                        @endforelse
                    </div>
                </td>
                <td style="width: 210px;">
                    <div class="totals-box">
                        <table class="totals">
                            <tr>
                                <td style="text-align: left; padding: 2px 0;">Основа</td>
                                <td style="text-align: right; padding: 2px 0;">{{ \App\Support\Format::money($invoice->subtotal()) }}</td>
                            </tr>
                            <tr>
                                <td style="text-align: left; padding: 2px 0;">ДДВ</td>
                                <td style="text-align: right; padding: 2px 0;">{{ \App\Support\Format::money($invoice->vatTotal()) }}</td>
                            </tr>
                            <tr class="grand">
                                <td style="text-align: left; padding: 6px 0 2px;">Вкупно</td>
                                <td style="text-align: right; padding: 6px 0 2px;">{{ \App\Support\Format::money($invoice->grandTotal()) }}</td>
                            </tr>
                            <tr>
                                <td style="text-align: left; padding: 2px 0;">За доплата</td>
                                <td style="text-align: right; padding: 2px 0;">{{ \App\Support\Format::money($invoice->balanceDue()) }}</td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
        @php
            $footnotes = [];
            if (! $vatRegistered) {
                $footnotes[] = 'Фирмава не е ДДВ обврзник.';
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
    </div>
</body>
</html>

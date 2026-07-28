<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $logoPosition = ($invoice->company->logo_position ?? null) ?: 'left';
    @endphp
    <style>
        body { font-family: 'DejaVu Sans'; font-size: 11px; color: #1f2937; margin: 0; padding: 0; }
        .accent-bar { height: 6px; background-color: #ff6600; }
        .content { padding: 18px 24px; }
        .badge { display: inline-block; background-color: #fff3ea; color: #ff6600; font-weight: bold; font-size: 14px; padding: 6px 14px; border-radius: 8px; }
        .muted { color: #6b7280; }
        .small { font-size: 10px; }
        .header-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
        .parties-row { display: flex; gap: 12px; margin-bottom: 14px; }
        .party-box { flex: 1; background-color: #f9fafb; border-radius: 8px; padding: 8px 12px; }
        .party-box h4 { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #ff6600; margin: 0 0 4px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th { text-align: left; font-size: 10px; color: #6b7280; background-color: #f9fafb; padding: 6px; }
        table.items td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
        .bottom-row { display: flex; gap: 12px; margin-top: 14px; }
        .pay-box { flex: 1; background-color: #f9fafb; border-radius: 8px; padding: 10px 12px; font-size: 10px; }
        .pay-box h4 { font-size: 9px; text-transform: uppercase; color: #6b7280; margin: 0 0 6px; }
        .totals-box { width: 210px; background-color: #fff3ea; border-radius: 8px; padding: 10px 14px; font-size: 11px; }
        .totals-box .row { display: flex; justify-content: space-between; padding: 2px 0; }
        .totals-box .grand { border-top: 1px solid #ffd4b0; font-weight: bold; margin-top: 4px; padding-top: 4px; color: #b34700; }
        .footnotes { margin-top: 16px; font-size: 9px; color: #6b7280; }
        .footnotes p { margin: 2px 0; }
        @if ($logoPosition === 'center')
            .logo-row { text-align: center; margin-bottom: 10px; }
        @endif
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="content">
        @php
            $company = $invoice->company;
            $hasLogo = (bool) $company->logo_path;
            $logoPath = $hasLogo ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path) : null;
            $vatRegistered = (bool) $company->is_vat_registered;
        @endphp

        @if ($hasLogo && $logoPosition === 'center')
            <div class="logo-row">
                <img src="{{ $logoPath }}" style="max-height: 56px;">
            </div>
        @endif

        <div class="header-row" style="{{ $logoPosition === 'right' ? 'flex-direction: row-reverse;' : '' }}">
            <div>
                @if ($hasLogo && $logoPosition !== 'center')
                    <img src="{{ $logoPath }}" style="max-height: 56px;">
                @endif
            </div>
            <div style="text-align: right;">
                <span class="badge">ФАКТУРА {{ $invoice->fiscal_year }}/{{ $invoice->invoice_number }}</span>
                <div class="small muted" style="margin-top: 6px;">
                    Датум на фактура: {{ \App\Support\Format::date($invoice->invoice_date) }}<br>
                    Датум на доспевање: {{ \App\Support\Format::date($invoice->due_date) }}
                </div>
            </div>
        </div>

        <div class="parties-row">
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
            <div class="party-box">
                <h4>Купувач</h4>
                <div><strong>{{ $invoice->partner->name }}</strong></div>
                <div class="small muted">{{ $invoice->partner->address }}</div>
                @if ($invoice->partner->tax_id)
                    <div class="small muted">ЕДБ: {{ $invoice->partner->tax_id }}</div>
                @endif
            </div>
        </div>

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
        {{-- Task 3 adds the bottom-row (payment info + totals) here --}}
        {{-- Task 4 adds footnotes here --}}
    </div>
</body>
</html>

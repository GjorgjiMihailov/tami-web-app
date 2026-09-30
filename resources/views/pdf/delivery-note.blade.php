<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @php
        $logoPosition = ($company->logo_position ?? null) ?: 'left';
        $logoPath = $company->logo_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path)
            : null;
        $hasLogo = $logoPath && file_exists($logoPath);
    @endphp
    <style>
        /* Ист принцип како pdf/sales-invoice.blade.php: dompdf 3.1.6 нема flex,
           па секој повеќеколонски дел е табела со фиксни широчини. */
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
        table.party-row { width: 100%; border-collapse: collapse; margin-top: 16px; }
        table.party-row td { vertical-align: top; width: 47%; }
        table.party-row td.party-gap { width: 6%; }
        .address-window, .issuer-box { height: 40mm; font-size: 10px; border: 1px solid #d1d5db; border-radius: 6px; padding: 6px 10px; }
        .reference { margin-top: 10px; font-size: 10px; color: #000000; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
        table.items th { text-align: left; font-size: 10px; color: #000000; font-weight: bold; border-bottom: 1.5px solid #111827; padding: 6px; }
        table.items td { padding: 6px; border-bottom: 1px solid #e5e7eb; }
        table.signatures { width: 100%; border-collapse: collapse; margin-top: 90px; page-break-inside: avoid; }
        table.signatures td { width: 50%; padding: 0 24px; vertical-align: bottom; }
        .sig-line { border-top: 1px solid #9ca3af; height: 0; font-size: 0; }
        .sig-label { text-align: center; font-size: 9px; color: #000000; margin-top: 4px; letter-spacing: .05em; }
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

        <table class="letterhead">
            <tr>
                @if ($logoPosition === 'right')
                    <td style="width: 50%;">
                        <span class="badge">Испратница {{ $deliveryNote->delivery_note_number_formatted }}</span>
                        <div class="small muted" style="margin-top: 6px;">Датум: {{ $deliveryNote->delivery_date->format('d.m.Y') }}</div>
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
                        <span class="badge">Испратница {{ $deliveryNote->delivery_note_number_formatted }}</span>
                        <div class="small muted" style="margin-top: 6px;">Датум: {{ $deliveryNote->delivery_date->format('d.m.Y') }}</div>
                    </td>
                @endif
            </tr>
        </table>

        <table class="party-row">
            <tr>
                <td>
                    <div class="address-window">
                        <div class="label">Примач</div>
                        <div><strong>{{ $partner->name }}</strong></div>
                        <div>{{ $partner->printedAddress() }}</div>
                        @if ($partner->tax_id)
                            <div style="margin-top: 4px;">ЕДБ: {{ $partner->tax_id }}</div>
                        @endif
                    </div>
                </td>
                <td class="party-gap"></td>
                <td>
                    <div class="issuer-box">
                        <div class="label">Издавач</div>
                        <div style="margin-bottom: 3px;"><strong>{{ $company->name }}</strong></div>
                        <table class="info-table small">
                            <tr>
                                <td class="info-label">Адреса:</td>
                                <td>{{ $company->address }}</td>
                            </tr>
                            <tr>
                                <td class="info-label">ЕДБ:</td>
                                <td>{{ $company->tax_id }}</td>
                            </tr>
                            @if ($company->registration_number)
                                <tr>
                                    <td class="info-label">Матичен број:</td>
                                    <td>{{ $company->registration_number }}</td>
                                </tr>
                            @endif
                        </table>
                    </div>
                </td>
            </tr>
        </table>

        <div class="reference">По {{ $sourceLabel }} бр. {{ $sourceNumber }}</div>

        <table class="items">
            <thead>
                <tr>
                    <th style="width: 30px;">Ред. бр.</th>
                    <th style="width: 70px;">Шифра</th>
                    <th>Опис</th>
                    <th style="width: 60px;">Ед. мера</th>
                    <th style="width: 70px;">Количина</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $index => $line)
                    <tr>
                        <td>{{ $index + 1 }}</td>
                        <td>{{ $line->item?->code }}</td>
                        <td>{{ $line->description }}</td>
                        <td>{{ $line->item?->unit_of_measure ?: 'бр.' }}</td>
                        <td>{{ $line->quantity }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="signatures">
            <tr>
                <td>
                    <div class="sig-line"></div>
                    <div class="sig-label">ПРЕДАЛ</div>
                </td>
                <td>
                    <div class="sig-line"></div>
                    <div class="sig-label">ПРИМИЛ</div>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>

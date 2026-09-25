<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans'; font-size: 11px; color: #1f2937; margin: 0; padding: 0; }
        .accent-bar { height: 6px; background-color: #ff6600; }
        .content { padding: 18px 24px; }
        .muted { color: #6b7280; }
        .right { text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        table.header-table td { vertical-align: top; }
        table.rows { margin-top: 10px; }
        table.rows th { text-align: left; font-size: 10px; color: #6b7280; background-color: #f9fafb; padding: 6px; }
        table.rows th.right { text-align: right; }
        table.rows td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
        table.summary { width: 45%; margin-top: 8px; }
        table.summary td { padding: 3px 0; }
        .total td { border-top: 1px solid #1f2937; font-weight: bold; }
        h2 { font-size: 13px; margin: 18px 0 4px 0; }
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="content">
        <table class="header-table">
            <tr>
                <td>
                    <strong>{{ $company->name }}</strong>
                </td>
                <td class="right">
                    <div style="font-size: 16px; font-weight: bold;">Извод на сметка</div>
                    <div class="muted">
                        {{ $statement['from'] ? \App\Support\Format::date($statement['from']).' — ' : 'до ' }}{{ \App\Support\Format::date($statement['to']) }}
                    </div>
                </td>
            </tr>
        </table>

        <p style="margin-top: 14px;">
            <span class="muted">За:</span><br>
            <strong>{{ $partner->name }}</strong>
            @if ($partner->tax_id)<br>ЕДБ: {{ $partner->tax_id }}@endif
            @if ($partner->printedAddress())<br>{{ $partner->printedAddress() }}@endif
        </p>

        @foreach ($statement['currencies'] as $currency => $block)
            @php $label = \App\Support\Format::currencyLabel($currency); @endphp

            @if (count($statement['currencies']) > 1 || $currency !== 'MKD')
                <h2>Валута: {{ $currency }}</h2>
            @endif

            <table class="summary">
                <tr><td>Почетно салдо</td><td class="right">{{ \App\Support\Format::money($block['opening'], $label) }}</td></tr>
                <tr><td>Фактурирано</td><td class="right">{{ \App\Support\Format::money($block['invoiced'], $label) }}</td></tr>
                <tr><td>Наплатено</td><td class="right">{{ \App\Support\Format::money($block['received'], $label) }}</td></tr>
                <tr class="total"><td>Салдо за наплата</td><td class="right">{{ \App\Support\Format::money($block['closing'], $label) }}</td></tr>
            </table>

            <table class="rows">
                <thead>
                    <tr>
                        <th>Датум</th>
                        <th>Документ</th>
                        <th>Детали</th>
                        <th class="right">Износ</th>
                        <th class="right">Уплата</th>
                        <th class="right">Салдо</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="muted">
                        <td>{{ $statement['from'] ? \App\Support\Format::date($statement['from']) : '' }}</td>
                        <td colspan="4">*** Почетно салдо ***</td>
                        <td class="right">{{ \App\Support\Format::money($block['opening'], $label) }}</td>
                    </tr>
                    @foreach ($block['rows'] as $row)
                        <tr>
                            <td>{{ \App\Support\Format::date($row['date']) }}</td>
                            <td>{{ $row['type'] === 'invoice' ? 'Фактура' : 'Уплата' }} {{ $row['document'] }}</td>
                            <td>{{ $row['details'] }}</td>
                            <td class="right">{{ $row['amount'] !== null ? \App\Support\Format::money($row['amount'], $label) : '' }}</td>
                            <td class="right">{{ $row['payment'] !== null ? \App\Support\Format::money($row['payment'], $label) : '' }}</td>
                            <td class="right">{{ \App\Support\Format::money($row['balance'], $label) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    </div>
</body>
</html>

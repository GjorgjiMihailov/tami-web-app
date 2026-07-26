<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans'; font-size: 12px; color: #1f2937; }
        h1 { font-size: 18px; color: #ff6600; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th { padding: 4px 6px; text-align: left; background: #f3f4f6; }
        td { padding: 4px 6px; border-bottom: 1px solid #e5e7eb; text-align: left; }
        .totals { text-align: right; margin-top: 12px; }
        .totals strong { color: #ff6600; }
        .header { display: flex; justify-content: space-between; margin-bottom: 16px; }
    </style>
</head>
<body>
    <h1>Фактура {{ $invoice->fiscal_year }}/{{ $invoice->invoice_number }}</h1>

    <div class="header">
        <div>
            <strong>{{ $invoice->company->name }}</strong><br>
            {{ $invoice->company->address }}<br>
            ЕДБ: {{ $invoice->company->tax_id }}<br>
            @if ($invoice->company->bank_account)
                Трансакциска сметка: {{ $invoice->company->bank_account }}<br>
            @endif
        </div>
        <div>
            <strong>Купувач:</strong><br>
            {{ $invoice->partner->name }}<br>
            {{ $invoice->partner->address }}<br>
            @if ($invoice->partner->tax_id)
                ЕДБ: {{ $invoice->partner->tax_id }}<br>
            @endif
        </div>
        <div>
            Датум на фактура: {{ \App\Support\Format::date($invoice->invoice_date) }}<br>
            Датум на доспевање: {{ \App\Support\Format::date($invoice->due_date) }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Опис</th>
                <th>Кол.</th>
                <th>Ед. цена</th>
                <th>ДДВ %</th>
                <th>Вкупно</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->quantity }}</td>
                    <td>{{ \App\Support\Format::money($line->unit_price) }}</td>
                    <td>{{ $line->vat_rate }}</td>
                    <td>{{ \App\Support\Format::money($line->lineTotal()) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div>Основа: {{ \App\Support\Format::money($invoice->subtotal()) }}</div>
        <div>ДДВ: {{ \App\Support\Format::money($invoice->vatTotal()) }}</div>
        <div><strong>Вкупно: {{ \App\Support\Format::money($invoice->grandTotal()) }}</strong></div>
    </div>
</body>
</html>

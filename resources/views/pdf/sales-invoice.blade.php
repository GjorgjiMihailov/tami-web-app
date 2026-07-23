<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #1f2937; }
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
    <h1>Invoice {{ $invoice->fiscal_year }}/{{ $invoice->invoice_number }}</h1>

    <div class="header">
        <div>
            <strong>{{ $invoice->company->name }}</strong><br>
            {{ $invoice->company->address }}<br>
            Tax ID: {{ $invoice->company->tax_id }}<br>
            @if ($invoice->company->bank_account)
                Bank account: {{ $invoice->company->bank_account }}<br>
            @endif
        </div>
        <div>
            <strong>Bill to:</strong><br>
            {{ $invoice->partner->name }}<br>
            {{ $invoice->partner->address }}<br>
            @if ($invoice->partner->tax_id)
                Tax ID: {{ $invoice->partner->tax_id }}<br>
            @endif
        </div>
        <div>
            Invoice date: {{ $invoice->invoice_date->toDateString() }}<br>
            Due date: {{ $invoice->due_date->toDateString() }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Description</th>
                <th>Qty</th>
                <th>Unit price</th>
                <th>VAT %</th>
                <th>Line total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->lines as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->quantity }}</td>
                    <td>{{ $line->unit_price }}</td>
                    <td>{{ $line->vat_rate }}</td>
                    <td>{{ $line->lineTotal() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <div>Subtotal: {{ $invoice->subtotal() }}</div>
        <div>VAT: {{ $invoice->vatTotal() }}</div>
        <div><strong>Total: {{ $invoice->grandTotal() }}</strong></div>
    </div>
</body>
</html>

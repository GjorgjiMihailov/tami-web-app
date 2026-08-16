<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 15px; margin: 0 0 2px 0; }
        .muted { color: #666; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #ddd; padding: 4px 6px; text-align: left; }
        th { background: #f5f5f5; font-weight: bold; }
        .right { text-align: right; }
        .total td { border-top: 2px solid #333; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Исплатна листа</h1>
    <div class="muted">
        {{ $company->name }} — {{ $run->monthName() }} {{ $run->year }}<br>
        {{ $runEmployee->employee->full_name }}, ЕМБГ {{ $runEmployee->employee->embg }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Ставка</th>
                <th class="right">Часови</th>
                <th class="right">%</th>
                <th class="right">Износ</th>
                <th>Товар</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($runEmployee->lines->where('kind', '!=', 'deduction') as $line)
                <tr>
                    <td>{{ $line->description }}</td>
                    <td class="right">{{ $line->hours }}</td>
                    <td class="right">{{ $line->percent ? number_format($line->percent, 0) : '' }}</td>
                    <td class="right">{{ number_format($line->amount, 2, ',', '.') }}</td>
                    <td>{{ $line->borne_by === 'fzo' ? 'ФЗО' : 'Работодавач' }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="3">Бруто</td>
                <td class="right">{{ number_format($runEmployee->gross, 2, ',', '.') }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <table>
        <tbody>
            <tr><td>Придонес ПИО</td><td class="right">{{ number_format($runEmployee->pension, 2, ',', '.') }}</td></tr>
            <tr><td>Придонес ФЗО</td><td class="right">{{ number_format($runEmployee->health, 2, ',', '.') }}</td></tr>
            <tr><td>Придонес повреда</td><td class="right">{{ number_format($runEmployee->injury, 2, ',', '.') }}</td></tr>
            <tr><td>Придонес невработеност</td><td class="right">{{ number_format($runEmployee->unemployment, 2, ',', '.') }}</td></tr>
            <tr><td>Даночна основица</td><td class="right">{{ number_format($runEmployee->tax_base, 2, ',', '.') }}</td></tr>
            <tr><td>Персонален данок</td><td class="right">{{ number_format($runEmployee->tax, 2, ',', '.') }}</td></tr>
            <tr><td>Нето</td><td class="right">{{ number_format($runEmployee->net, 2, ',', '.') }}</td></tr>
            @foreach ($runEmployee->lines->where('kind', 'deduction') as $line)
                <tr><td>Задршка — {{ $line->description }}</td><td class="right">−{{ number_format($line->amount, 2, ',', '.') }}</td></tr>
            @endforeach
            <tr class="total"><td>За исплата</td><td class="right">{{ number_format($runEmployee->effective_net, 2, ',', '.') }}</td></tr>
        </tbody>
    </table>

    @if ($runEmployee->top_up > 0)
        {{-- Not .muted: this is the one sentence that stops a worker
             believing the top-up was taken out of their pay, so it gets
             normal body weight and size, not the faint grey used for
             incidental captions elsewhere on this page. --}}
        <p>Доплата до најниска основица на товар на работодавачот: {{ number_format($runEmployee->top_up, 2, ',', '.') }}. Не се одзема од платата на работникот.</p>
    @endif
</body>
</html>

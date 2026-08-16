<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 14px; margin: 0 0 2px 0; }
        .muted { color: #666; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border-bottom: 1px solid #ddd; padding: 3px 5px; text-align: left; }
        th { background: #f5f5f5; font-weight: bold; }
        .right { text-align: right; }
        .total td { border-top: 2px solid #333; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Рекапитулар на плата</h1>
    <div class="muted">{{ $company->name }} — {{ $run->monthName() }} {{ $run->year }}, фонд {{ $run->month_hours }} часа</div>

    <table>
        <thead>
            <tr>
                <th>Вработен</th>
                <th class="right">Бруто</th>
                <th class="right">ПИО</th>
                <th class="right">ФЗО</th>
                <th class="right">Повреда</th>
                <th class="right">Невработеност</th>
                <th class="right">Данок</th>
                <th class="right">Нето</th>
                <th class="right">Задршки</th>
                <th class="right">За исплата</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($run->employees as $row)
                <tr>
                    <td>{{ $row->employee->full_name }}</td>
                    <td class="right">{{ number_format($row->gross, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->pension, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->health, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->injury, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->unemployment, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->tax, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->net, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->deductions_total, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($row->effective_net, 2, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td>Вкупно</td>
                <td class="right">{{ number_format($run->employees->sum('gross'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('pension'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('health'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('injury'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('unemployment'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('tax'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('net'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('deductions_total'), 2, ',', '.') }}</td>
                <td class="right">{{ number_format($run->employees->sum('effective_net'), 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>
</body>
</html>

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

    <h2 style="font-size: 12px; margin: 14px 0 0 0;">Книжено во главна книга</h2>

    @if ($run->journalEntry)
        <div class="muted">Налог од {{ $run->endOfMonth() }}</div>
        <table>
            <thead>
                <tr>
                    <th>Конто</th>
                    <th>Опис</th>
                    <th class="right">Должи</th>
                    <th class="right">Побарува</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($run->journalEntry->lines as $line)
                    <tr>
                        <td>{{ $line->account->code }}</td>
                        <td>{{ $line->account->name }}</td>
                        <td class="right">{{ number_format($line->debit, 2, ',', '.') }}</td>
                        <td class="right">{{ number_format($line->credit, 2, ',', '.') }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="2">Вкупно</td>
                    <td class="right">{{ number_format($run->journalEntry->lines->sum(fn ($l) => (float) $l->debit), 2, ',', '.') }}</td>
                    <td class="right">{{ number_format($run->journalEntry->lines->sum(fn ($l) => (float) $l->credit), 2, ',', '.') }}</td>
                </tr>
            </tbody>
        </table>
        <p class="muted">Книжен е само делот на товар на работодавачот. Кај вработен чие боледување го носи ФЗО, разликата спрема горната табела е токму тој дел — тој се пресметува и се пријавува, но не е трошок на фирмата.</p>
    @elseif ($run->isDraft())
        <p class="muted">Пресметката е во нацрт и сè уште не е книжена.</p>
    @else
        <p class="muted">Пресметката е потврдена, но нема што да се книжи кај фирмата — целата плата за овој месец е на товар на друг.</p>
    @endif
</body>
</html>

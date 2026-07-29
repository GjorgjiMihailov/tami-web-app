<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'DejaVu Sans'; font-size: 11px; color: #1f2937; margin: 0; padding: 0; }
        .accent-bar { height: 6px; background-color: #ff6600; }
        .content { padding: 18px 24px; }
        .muted { color: #6b7280; }
        table.header-table { width: 100%; margin-bottom: 10px; }
        table.header-table td { vertical-align: top; }
        .badge { display: inline-block; background-color: #fff3ea; color: #ff6600; font-weight: bold; font-size: 14px; padding: 6px 14px; border-radius: 8px; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.lines th { text-align: left; font-size: 10px; color: #6b7280; background-color: #f9fafb; padding: 6px; }
        table.lines td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
        table.totals-table { width: 260px; margin-top: 12px; margin-left: auto; background-color: #fff3ea; border-radius: 8px; }
        table.totals-table td { padding: 6px 14px; font-size: 11px; }
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="content">
        <table class="header-table">
            <tr>
                <td><strong>{{ $entry->company->name }}</strong></td>
                <td style="text-align: right;">
                    <span class="badge">НАЛОГ {{ $entry->displayNumber() }}</span>
                </td>
            </tr>
        </table>

        <table class="header-table">
            <tr>
                <td class="muted">Журнал: @if ($entry->journalGroup) {{ $entry->journalGroup->code }} — {{ $entry->journalGroup->name }} @else — @endif</td>
                <td class="muted" style="text-align: right;">Датум: {{ \App\Support\Format::date($entry->entry_date) }}</td>
            </tr>
            @if ($entry->description)
                <tr>
                    <td colspan="2">Опис: {{ $entry->description }}</td>
                </tr>
            @endif
        </table>

        <table class="lines">
            <thead>
                <tr>
                    <th>Сметка</th>
                    <th>Партнер</th>
                    <th>Опис</th>
                    <th>Датум</th>
                    <th style="text-align: right;">Должи</th>
                    <th style="text-align: right;">Побарува</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($entry->lines as $line)
                    <tr>
                        <td>{{ $line->account->code }} — {{ $line->account->name }}</td>
                        <td>{{ $line->partner?->name }}</td>
                        <td>{{ $line->description }}</td>
                        <td>{{ $line->line_date ? \App\Support\Format::date($line->line_date) : '—' }}</td>
                        <td style="text-align: right;">{{ (float) $line->debit > 0 ? \App\Support\Format::money($line->debit) : '' }}</td>
                        <td style="text-align: right;">{{ (float) $line->credit > 0 ? \App\Support\Format::money($line->credit) : '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals-table">
            <tr>
                <td>Вкупно</td>
                <td style="text-align: right;">{{ \App\Support\Format::money($entry->lines->sum('debit')) }}</td>
                <td style="text-align: right;">{{ \App\Support\Format::money($entry->lines->sum('credit')) }}</td>
            </tr>
        </table>
    </div>
</body>
</html>

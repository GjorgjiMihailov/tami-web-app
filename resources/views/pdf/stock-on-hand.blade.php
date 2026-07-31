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
        table.stock { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.stock th { text-align: left; font-size: 10px; color: #6b7280; background-color: #f9fafb; padding: 6px; }
        table.stock th.text-right, table.stock td.text-right { text-align: right; }
        table.stock td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="content">
        <table class="header-table">
            <tr>
                <td><strong>{{ $company->name }}</strong></td>
                <td class="muted" style="text-align: right;">
                    Залиха @if($warehouseName) — {{ $warehouseName }} @else — сите магацини @endif
                </td>
            </tr>
        </table>

        <table class="stock">
            <thead>
                <tr>
                    <th>Шифра</th>
                    <th>Артикл</th>
                    <th class="text-right">Количина</th>
                    <th class="text-right">Набавна вредност</th>
                    <th class="text-right">Продажна вредност</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>{{ $row['item_code'] }}</td>
                        <td>{{ $row['item_name'] }}</td>
                        <td class="text-right">{{ number_format($row['quantity'], 3, ',', '.') }}</td>
                        <td class="text-right">{{ \App\Support\Format::money($row['cost_value'], currency: '') }}</td>
                        <td class="text-right">{{ \App\Support\Format::money($row['selling_value'], currency: '') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Нема евидентирана залиха.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>

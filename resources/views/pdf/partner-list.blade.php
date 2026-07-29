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
        table.partners { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.partners th { text-align: left; font-size: 10px; color: #6b7280; background-color: #f9fafb; padding: 6px; }
        table.partners td { padding: 6px; border-bottom: 1px solid #f3f4f6; }
    </style>
</head>
<body>
    <div class="accent-bar"></div>
    <div class="content">
        <table class="header-table">
            <tr>
                <td><strong>{{ $company->name }}</strong></td>
                <td class="muted" style="text-align: right;">Листа на партнери</td>
            </tr>
        </table>

        <table class="partners">
            <thead>
                <tr>
                    <th>Назив</th>
                    <th>Тип</th>
                    <th>ЕДБ</th>
                    <th>Телефон</th>
                    <th>Е-пошта</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($partners as $partner)
                    <tr>
                        <td>{{ $partner->name }}</td>
                        <td>{{ \App\Support\Format::partnerType($partner->type) }}</td>
                        <td>{{ $partner->tax_id }}</td>
                        <td>{{ $partner->phone }}</td>
                        <td>{{ $partner->email }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Нема додадено партнери.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</body>
</html>

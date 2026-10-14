{{--
    Политика за приватност на порталот. Јавна, без најава.

    Содржи само она што е технички точно за системот. Правниот текст и
    контакт-податоците нека ги прегледа сопственикот пред да ги потпише.
--}}
<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Политика за приватност — ТАМИ, FinanceBuddy.mk</title>
    <meta name="robots" content="index,follow">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,600,700|inter:400,500,600,700&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body class="font-body bg-paper text-ink antialiased">
    <main class="max-w-3xl mx-auto px-6 py-12 leading-relaxed">
        <p class="mb-6"><a href="{{ route('home') }}" class="text-brand hover:underline">← ТАМИ — FinanceBuddy.mk Портал</a></p>

        <h1 class="text-3xl font-bold mb-2">Политика за приватност</h1>
        <p class="text-sm text-gray-500 mb-8">Важи за порталот portal.financebuddy.mk (апликацијата „ТАМИ“).</p>

        <h2 class="text-xl font-semibold mt-8 mb-2">Што е ТАМИ</h2>
        <p>ТАМИ е веб-апликација за сметководство на FinanceBuddy.mk: фактури, е-Фактура, книжења, банкарски изводи,
            плати и извештаи. Ја користат сметководители и клиенти на FinanceBuddy.mk, секој со своја сметка.</p>

        <h2 class="text-xl font-semibold mt-8 mb-2">Кои податоци ги чуваме</h2>
        <ul class="list-disc pl-6">
            <li>податоци за корисниците на порталот: име, е-пошта, улога и фирма на која припаѓаат;</li>
            <li>сметководствени податоци што ги внесуваат корисниците: партнери, артикли, фактури, книжења, изводи, плати;</li>
            <li>документи што корисниците ги прикачуваат (скенови на фактури, банкарски изводи, образци).</li>
        </ul>

        <h2 class="text-xl font-semibold mt-8 mb-2">Google Drive</h2>
        <p>Прикачените документи се чуваат во Google Drive сметка и папка на FinanceBuddy.mk, преку пристап што го
            дава самиот оператор на системот. Апликацијата пристапува само до таа папка за да ги зачува и да ги
            прикаже документите на корисниците што имаат право да ги гледаат. Не пристапува до туѓи Google
            податоци и не бара пристап од крајните корисници на порталот.</p>

        <h2 class="text-xl font-semibold mt-8 mb-2">Кој има пристап</h2>
        <p>Податоците на една фирма ги гледаат само нејзините корисници и сметководителите на FinanceBuddy.mk што
            ја водат. Податоците не се продаваат и не се споделуваат со трети лица, освен кога тоа го бара закон или
            е дел од барана услуга (на пример, испраќање е-Фактура до УЈП по налог на корисникот).</p>

        <h2 class="text-xl font-semibold mt-8 mb-2">Чување и бришење</h2>
        <p>Податоците се чуваат додека фирмата го користи порталот. На барање на фирмата, податоците и документите
            се бришат, освен оние што законот ги бара да се чуваат.</p>

        <h2 class="text-xl font-semibold mt-8 mb-2">Контакт</h2>
        <p>За прашања за приватноста обратете се преку <a href="https://financebuddy.mk" class="text-brand hover:underline">financebuddy.mk</a>.</p>

        <p class="text-sm text-gray-500 mt-10">Последно ажурирање: {{ now()->format('d.m.Y') }}</p>
    </main>
</body>
</html>

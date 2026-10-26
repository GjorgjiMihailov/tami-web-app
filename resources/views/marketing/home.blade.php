{{--
    Јавната влезна страна на portal.financebuddy.mk.

    Намерно НЕ го користи layouts/app.blade.php: тој е школката на самата
    апликација (странична лента, работна година, избор на фирма) и ништо од тоа
    нема смисла пред најава. Оваа страница има сопствен <head> и сопствени
    фонтови (Fraunces + Inter), исти како на financebuddy.mk, за двете да
    изгледаат како едно семејство.
--}}
<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>ТАМИ — FinanceBuddy.mk Портал</title>
    <meta name="description" content="Порталот на FinanceBuddy.mk: фактури и е-Фактура до УЈП, сметководство, ДДВ-04, плати и МПИН, залихи и документи — на едно место, заедно со твојот сметководител.">

    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <meta property="og:type" content="website">
    <meta property="og:title" content="ТАМИ — FinanceBuddy.mk Портал">
    <meta property="og:description" content="Фактури, е-Фактура, сметководство, плати и извештаи на едно место.">
    <meta property="og:url" content="{{ url('/') }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=fraunces:400,600,700|inter:400,500,600,700&display=swap" rel="stylesheet" />

    {{-- Мора да е ТУКА, пред стилот: `.has-js` е она што ја вклучува скриената
         почетна состојба на анимациите. Ако оваа скрипта не се изврши (JS
         исклучен, блокиран, стар прелистувач), класата ја нема и страницата се
         прикажува целосно видлива, само без појавување. --}}
    <script>document.documentElement.className += ' has-js';</script>

    @vite(['resources/css/app.css'])
</head>
<body class="font-body bg-paper text-ink antialiased @guest pb-20 md:pb-0 @endguest">

    {{-- ───────────────────────── Горна лента ───────────────────────── --}}
    <header class="sticky top-0 z-50 border-b border-white/10 bg-night/85 backdrop-blur">
        <div class="mx-auto max-w-6xl px-5 h-16 flex items-center justify-between gap-4">
            <a href="{{ url('/') }}" class="flex items-center gap-2.5 shrink-0">
                <img src="/images/logo-icon.png" alt="" class="h-8 w-8" width="32" height="32">
                <span class="font-display text-lg font-semibold tracking-tight text-white">
                    ТАМИ<span class="text-white/60 font-body text-sm font-normal ms-2 hidden sm:inline">FinanceBuddy.mk Портал</span>
                </span>
            </a>

            <nav class="hidden md:flex items-center gap-7 text-sm text-white/70">
                <a href="#moznosti" class="hover:text-white transition">Можности</a>
                <a href="#kako" class="hover:text-white transition">Како работи</a>
                <a href="#efaktura" class="hover:text-white transition">е-Фактура</a>
                <a href="#kontakt" class="hover:text-white transition">Контакт</a>
            </nav>

            @auth
                <a href="{{ route('dashboard') }}"
                   class="shrink-0 inline-flex items-center rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-dark">
                    Влези во порталот
                </a>
            @else
                <a href="{{ route('login') }}"
                   class="shrink-0 inline-flex items-center rounded-full bg-brand px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-dark">
                    Најави се
                </a>
            @endauth
        </div>
    </header>

    {{-- ───────────────────────────── Херој ───────────────────────────── --}}
    <section class="relative overflow-hidden bg-night text-white">
        {{-- Светла што лебдат и мрежа од точки, сè цртано со CSS: без слика,
             без вчитување, никогаш не се распикселува. --}}
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div class="hero-orb hero-orb-a -top-28 -right-16 h-[26rem] w-[26rem] bg-brand/35"></div>
            <div class="hero-orb hero-orb-b -bottom-32 left-1/4 h-80 w-80 bg-brand-light/20"></div>
            <div class="hero-dots absolute inset-0"></div>
        </div>

        <div class="relative mx-auto max-w-6xl px-5 pt-16 pb-20 sm:pt-24 sm:pb-28 grid lg:grid-cols-2 gap-14 lg:gap-10 items-center">
            <div class="hero-rise">
                <span class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3.5 py-1.5 text-xs font-medium text-white/70">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand"></span>
                    Портал за клиенти на FinanceBuddy.mk
                </span>

                <h1 class="mt-6 font-display text-4xl sm:text-5xl lg:text-[3.4rem] leading-[1.08] font-semibold tracking-tight">
                    Сметководството на твојата фирма <span class="text-brand whitespace-nowrap">на едно место</span>.<span aria-hidden="true" class="hero-cur ms-1 inline-block h-[0.8em] w-[3px] translate-y-[0.08em] bg-brand"></span>
                </h1>

                <p class="mt-7 text-lg leading-relaxed text-white/70 max-w-xl">
                    Издавај фактури, испраќај е-Фактури до УЈП, следи плати, залихи и
                    документи — и гледај ја состојбата на фирмата во секој момент.
                    Твојот сметководител работи во истиот систем, во исто време.
                </p>

                <div class="mt-9 flex flex-wrap items-center gap-3">
                    @auth
                        <a href="{{ route('dashboard') }}"
                           class="hero-glow inline-flex items-center gap-2 rounded-full bg-brand px-8 py-4 text-base font-bold text-white transition hover:bg-brand-light hover:-translate-y-0.5">
                            Влези во порталот <span aria-hidden="true">→</span>
                        </a>
                    @else
                        <a href="{{ route('login') }}"
                           class="hero-glow inline-flex items-center gap-2 rounded-full bg-brand px-8 py-4 text-base font-bold text-white transition hover:bg-brand-light hover:-translate-y-0.5">
                            Најави се <span aria-hidden="true">→</span>
                        </a>
                    @endauth
                    <a href="#kontakt"
                       class="inline-flex items-center gap-2 rounded-full border border-white/25 px-7 py-3.5 text-sm font-semibold text-white transition hover:bg-white/10 hover:-translate-y-0.5">
                        Побарај пристап
                    </a>
                </div>

                <p class="mt-6 text-sm text-white/50">
                    Работи и на телефон и на таблет — без инсталација.
                </p>
            </div>

            {{-- „Жив" приказ на апликацијата. Бројките се пример, не податоци на
                 клиент, и тоа пишува под приказот. Вистинските екрани се подолу. --}}
            <div class="hero-rise hero-rise-2">
                <div class="rounded-2xl border border-white/12 bg-white/[0.06] p-4 backdrop-blur-sm shadow-2xl shadow-black/50">
                    <div class="grid grid-cols-2 gap-2.5">
                        <div class="rounded-xl bg-white/[0.06] px-3.5 py-3">
                            <span class="block text-[11px] text-white/55">Побарувања</span>
                            <span class="block mt-0.5 text-lg font-semibold">1.254.920 ден</span>
                        </div>
                        <div class="rounded-xl bg-white/[0.06] px-3.5 py-3">
                            <span class="block text-[11px] text-white/55">Обврски</span>
                            <span class="block mt-0.5 text-lg font-semibold">362.735 ден</span>
                        </div>
                    </div>

                    <div aria-hidden="true" class="mt-4 flex h-24 items-end gap-2">
                        @foreach ([35, 55, 42, 70, 60, 88, 76] as $i => $h)
                            <i class="hero-bar flex-1 rounded-t bg-gradient-to-b from-brand-light to-brand"
                               style="height: {{ $h }}%; animation-delay: {{ 100 + $i * 100 }}ms"></i>
                        @endforeach
                    </div>

                    <div class="mt-3 space-y-1.5">
                        @foreach ([
                            ['Фактура 2026-0142', 'Прифатена од УЈП', 0],
                            ['Фактура 2026-0143', 'Испратена до УЈП', 3],
                            ['Плата · октомври', 'МПИН подготвен', 6],
                        ] as [$what, $status, $delay])
                            <div class="relative h-8">
                                <div class="hero-row absolute inset-0 flex items-center justify-between rounded-lg bg-white/[0.06] px-3 text-xs"
                                     style="animation-delay: {{ $delay }}s">
                                    <span>{{ $what }}</span>
                                    <span class="text-emerald-300">● {{ $status }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
                <p class="mt-3 text-center text-xs text-white/40">Пример · вистинските екрани се подолу</p>
            </div>
        </div>
    </section>

    {{-- ─────────────────────── Лента на доверба ─────────────────────── --}}
    <section class="border-y border-sand bg-white">
        <div class="mx-auto max-w-6xl px-5 py-8 grid gap-6 sm:grid-cols-3 text-center sm:text-left">
            @foreach ([
                ['е-Фактура (УЈП)', 'Праќање и примање директно од апликацијата'],
                ['МПИН', 'XML за плати, спремен за е-ПДД'],
                ['ДДВ-04', 'Пополнет од книжењата, без препишување'],
            ] as [$title, $sub])
                <div class="reveal flex flex-col items-center sm:items-start gap-1">
                    <span class="font-display text-lg font-semibold">{{ $title }}</span>
                    <span class="text-sm text-stone">{{ $sub }}</span>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ───────────────────────────  Можности  ───────────────────────── --}}
    <section id="moznosti" class="mx-auto max-w-6xl px-5 py-20 sm:py-28">
        <div class="reveal max-w-2xl">
            <h2 class="font-display text-3xl sm:text-4xl font-semibold tracking-tight">Сè што ти треба, во една апликација</h2>
            <p class="mt-4 text-lg text-stone">
                Без Excel по папки, без фајлови по вибер. Секој документ седи таму каде што
                му е местото, и е видлив и за тебе и за сметководителот.
            </p>
        </div>

        <div class="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                ['Фактурирање и е-Фактура', 'Издај фактура за минута и испрати ја до УЈП потпишана со твојот токен. Статусот и официјалниот ПДФ се враќаат назад во листата.', 'M2.25 6.75A2.25 2.25 0 0 1 4.5 4.5h15a2.25 2.25 0 0 1 2.25 2.25v10.5A2.25 2.25 0 0 1 19.5 19.5h-15a2.25 2.25 0 0 1-2.25-2.25V6.75Zm3.75 3h6m-6 3.75h9'],
                ['Сметководство', 'Двојно книговодство по македонски стандард: групи на сметки, аналитички картици и бруто биланс што секогаш се сложува.', 'M3.75 3v16.5h16.5M7.5 15.75V9m4.5 6.75V6.75m4.5 9v-4.5'],
                ['ДДВ-04 и извештаи', 'Пресметката на ДДВ се полни од книжењата. Извештаите се спремни за печатење и за ПДФ, со точните полиња на образецот.', 'M8.25 3.75h7.5a1.5 1.5 0 0 1 1.5 1.5v13.5a1.5 1.5 0 0 1-1.5 1.5h-7.5a1.5 1.5 0 0 1-1.5-1.5V5.25a1.5 1.5 0 0 1 1.5-1.5Zm1.5 5.25h4.5m-4.5 3h4.5m-4.5 3h3'],
                ['Плати и МПИН', 'Пресметка од бруто или од нето, придонеси по важечките параметри, минат труд и делумни месеци, платни листови во ПДФ и МПИН XML.', 'M12 6.75a2.625 2.625 0 1 0 0-5.25 2.625 2.625 0 0 0 0 5.25Zm-7.5 15v-1.5a5.25 5.25 0 0 1 5.25-5.25h4.5a5.25 5.25 0 0 1 5.25 5.25v1.5'],
                ['Залихи', 'Влез, излез и состојба по магацин, со аналитичка картица за секој артикл и извештај за вредноста на залихата.', 'M3.75 8.25 12 3.75l8.25 4.5v7.5L12 20.25 3.75 15.75v-7.5Zm0 0L12 12.75m0 0 8.25-4.5M12 12.75v7.5'],
                ['Документи и изводи', 'Банкарски изводи, 743 обрасци и документите на фирмата — качени, средени и достапни во секое време, од секаде.', 'M4.5 4.5h9l6 6v9a1.5 1.5 0 0 1-1.5 1.5h-13.5A1.5 1.5 0 0 1 3 19.5v-13.5a1.5 1.5 0 0 1 1.5-1.5Zm9 0v6h6'],
            ] as $i => [$title, $text, $icon])
                <div class="reveal reveal-delay-{{ $i % 3 }} group rounded-2xl border border-sand bg-white p-6 transition duration-300 hover:-translate-y-1 hover:shadow-xl hover:shadow-ink/5">
                    <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand/10 text-brand transition group-hover:bg-brand group-hover:text-white">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icon }}"/>
                        </svg>
                    </span>
                    <h3 class="mt-5 font-display text-xl font-semibold">{{ $title }}</h3>
                    <p class="mt-2.5 text-sm leading-relaxed text-stone">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ────────────────────────  Како работи  ───────────────────────── --}}
    <section id="kako" class="bg-paper-warm/50 border-y border-sand">
        <div class="mx-auto max-w-6xl px-5 py-20 sm:py-28">
            <h2 class="reveal font-display text-3xl sm:text-4xl font-semibold tracking-tight text-center">Како работи</h2>

            <div class="mt-14 grid gap-10 md:grid-cols-3">
                @foreach ([
                    ['Ти ја работиш фирмата', 'Издаваш фактури, качуваш изводи и документи. Толку.'],
                    ['Ние книжиме', 'Сметководителот работи во истиот систем — ништо не се препраќа напред-назад.'],
                    ['Гледаш во секое време', 'Состојба, побарувања, плати и извештаи — од компјутер или од телефон.'],
                ] as $i => [$title, $text])
                    <div class="reveal reveal-delay-{{ $i }} relative">
                        <span class="font-display text-5xl font-semibold text-brand/25">0{{ $i + 1 }}</span>
                        <h3 class="mt-3 font-display text-xl font-semibold">{{ $title }}</h3>
                        <p class="mt-2 text-stone leading-relaxed">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ────────────────────  Приказ 1 — е-Фактура  ──────────────────── --}}
    <section id="efaktura" class="mx-auto max-w-6xl px-5 py-20 sm:py-28 grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
        <div class="reveal order-2 lg:order-1">
            <div class="rounded-2xl border border-sand bg-white shadow-xl shadow-ink/5 overflow-hidden">
                <img src="/images/screens/sales-invoices.png"
                     alt="Листа на излезни фактури со статуси од е-Фактура"
                     width="1440" height="700" loading="lazy" class="block w-full">
            </div>
        </div>

        <div class="reveal order-1 lg:order-2">
            <span class="text-sm font-semibold uppercase tracking-wider text-brand">е-Фактура</span>
            <h2 class="mt-3 font-display text-3xl sm:text-4xl font-semibold tracking-tight">
                До УЈП, без излегување од апликацијата
            </h2>
            <p class="mt-5 text-lg leading-relaxed text-stone">
                Фактурата се потпишува со твојот токен, на твојот компјутер. Приватниот
                клуч никогаш не го напушта токенот — на серверот оди само веќе
                потпишаната фактура.
            </p>
            <ul class="mt-7 space-y-3.5">
                @foreach ([
                    'Праќање на излезни фактури до УЈП со едно копче',
                    'Статусите на сите фактури се освежуваат наеднаш',
                    'Официјалниот ПДФ со QR код се презема и се чува',
                    'Влезните е-Фактури се прифаќаат или одбиваат од истиот екран',
                ] as $point)
                    <li class="flex gap-3">
                        <svg class="mt-0.5 h-5 w-5 shrink-0 text-forest" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                        </svg>
                        <span class="text-stone">{{ $point }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- ──────────────────────  Приказ 2 — Плати  ────────────────────── --}}
    <section class="bg-white border-y border-sand">
        <div class="mx-auto max-w-6xl px-5 py-20 sm:py-28 grid lg:grid-cols-2 gap-12 lg:gap-16 items-center">
            <div class="reveal">
                <span class="text-sm font-semibold uppercase tracking-wider text-brand">Плати</span>
                <h2 class="mt-3 font-display text-3xl sm:text-4xl font-semibold tracking-tight">
                    Плата пресметана до денар
                </h2>
                <p class="mt-5 text-lg leading-relaxed text-stone">
                    Внеси бруто или нето — другото се пресметува само, по важечките
                    параметри за придонеси и данок. Минатиот труд, делумните месеци и
                    деновите стаж се земени предвид.
                </p>
                <ul class="mt-7 space-y-3.5">
                    @foreach ([
                        'Месечна пресметка за сите вработени наеднаш',
                        'Платни листови и рекапитулар во ПДФ',
                        'МПИН XML спремен за качување во е-ПДД',
                        'Книжењето на платата оди право во дневникот',
                    ] as $point)
                        <li class="flex gap-3">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-forest" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/>
                            </svg>
                            <span class="text-stone">{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="reveal reveal-delay-1">
                <div class="rounded-2xl border border-sand bg-white shadow-xl shadow-ink/5 overflow-hidden">
                    <img src="/images/screens/payroll.png"
                         alt="Екран за месечна пресметка на плата"
                         width="1440" height="560" loading="lazy" class="block w-full">
                </div>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────  Повик  ────────────────────────────── --}}
    <section id="kontakt" class="mx-auto max-w-6xl px-5 py-20 sm:py-28">
        <div class="reveal relative overflow-hidden rounded-3xl bg-ink px-7 py-14 sm:px-14 sm:py-16 text-center">
            <div aria-hidden="true" class="pointer-events-none absolute -top-24 -right-16 h-72 w-72 rounded-full bg-brand/25 blur-3xl"></div>

            <h2 class="relative font-display text-3xl sm:text-4xl font-semibold tracking-tight text-paper">
                Сакаш пристап до порталот?
            </h2>
            <p class="relative mt-4 text-lg text-paper/70 max-w-2xl mx-auto">
                Пристапот го отвораме ние. Јави се или пиши ни, и ќе ти ја подготвиме
                фирмата во порталот.
            </p>

            <div class="relative mt-9 flex flex-wrap justify-center gap-3">
                <a href="tel:+38977881701"
                   class="inline-flex items-center gap-2 rounded-full bg-brand px-7 py-3.5 text-sm font-semibold text-white transition hover:bg-brand-dark hover:-translate-y-0.5">
                    +389 77 881 701
                </a>
                <a href="mailto:contact@financebuddy.mk"
                   class="inline-flex items-center gap-2 rounded-full border border-paper/25 px-7 py-3.5 text-sm font-semibold text-paper transition hover:bg-paper/10 hover:-translate-y-0.5">
                    contact@financebuddy.mk
                </a>
            </div>

            <p class="relative mt-8 text-sm text-paper/50">
                Веќе си клиент?
                <a href="{{ route('login') }}" class="text-paper underline underline-offset-4 hover:text-brand-light transition">Најави се тука</a>.
            </p>
        </div>
    </section>

    {{-- ────────────────────────── Подножје ──────────────────────────── --}}
    <footer class="border-t border-sand bg-white">
        <div class="mx-auto max-w-6xl px-5 py-10 flex flex-col sm:flex-row items-center justify-between gap-6">
            <div class="flex items-center gap-2.5">
                <img src="/images/logo-icon.png" alt="" class="h-7 w-7" width="28" height="28">
                <span class="text-sm text-stone">© {{ date('Y') }} FinanceBuddy.mk</span>
            </div>

            <nav class="flex flex-wrap justify-center gap-x-7 gap-y-2 text-sm text-stone">
                <a href="https://financebuddy.mk" class="hover:text-ink transition">financebuddy.mk</a>
                <a href="https://financebuddy.mk/uslugi" class="hover:text-ink transition">Услуги</a>
                <a href="https://financebuddy.mk/kontakt" class="hover:text-ink transition">Контакт</a>
                <a href="{{ route('login') }}" class="hover:text-ink transition">Најави се</a>
            </nav>
        </div>
    </footer>

    {{-- Лепливо копче за најава на телефон: горното копче е мало и се губи при
         скролање. Се појавува откако ќе се помине херојот (види app.css). --}}
    @guest
        <div id="login-bar" class="login-bar fixed inset-x-0 bottom-0 z-40 md:hidden border-t border-white/10 bg-night/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur">
            <a href="{{ route('login') }}"
               class="flex w-full items-center justify-center gap-2 rounded-full bg-brand px-6 py-3.5 text-base font-bold text-white transition hover:bg-brand-dark">
                Најави се <span aria-hidden="true">→</span>
            </a>
        </div>
    @endguest

    {{-- Појавување при скролање. Ако прелистувачот нема IntersectionObserver
         или JS е исклучен, .reveal останува видлив — видливоста ја пали CSS-от,
         скриптата само го додава класот што ја анимира. --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var bar = document.getElementById('login-bar');

            if (bar) {
                var toggleBar = function () {
                    bar.classList.toggle('is-on', window.scrollY > 480);
                };

                window.addEventListener('scroll', toggleBar, { passive: true });
                toggleBar();
            }

            var items = document.querySelectorAll('.reveal');

            if (! ('IntersectionObserver' in window)) {
                items.forEach(function (el) { el.classList.add('is-visible'); });
                return;
            }

            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, { rootMargin: '0px 0px -10% 0px', threshold: 0.05 });

            items.forEach(function (el) { observer.observe(el); });

            /*
             * Кога страницата се отвора со сидро во адресата (/#kontakt) или
             * кога прелистувачот ја враќа старата позиција при освежување,
             * скокот се случува ПОСЛЕ првото мерење на набљудувачот — тој веќе
             * запишал „не е во видното поле" и повеќе не се јавува, па делот
             * останува невидлив засекогаш. Затоа: уште едно рачно поминување
             * откако сè ќе се вчита, и по секоја промена на сидрото.
             */
            var sweep = function () {
                document.querySelectorAll('.reveal:not(.is-visible)').forEach(function (el) {
                    var box = el.getBoundingClientRect();

                    if (box.top < window.innerHeight && box.bottom > 0) {
                        el.classList.add('is-visible');
                        observer.unobserve(el);
                    }
                });
            };

            window.addEventListener('load', sweep);
            window.addEventListener('hashchange', function () { setTimeout(sweep, 50); });
        });
    </script>
</body>
</html>

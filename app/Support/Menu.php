<?php

namespace App\Support;

use App\Models\Company;
use App\Models\User;

/**
 * The single source of truth for the sidebar's structure.
 *
 * Kept as data rather than Blade markup so the spec's role table can be
 * asserted directly (see tests/Unit/Support/MenuTest.php) instead of by
 * scraping rendered HTML.
 *
 * Never calls request() — the caller passes in whatever request state it
 * needs matched. See docs/superpowers/plans/2026-08-12-menu-restructure-and-role-visibility.md
 * for why.
 */
class Menu
{
    /**
     * Menu entries whose feature does not exist yet. The key is the URL slug
     * used by the shared placeholder route (see App\Livewire\ComingSoon).
     *
     * @var array<string, array{label: string, sentence: string}>
     */
    public const SOON_FEATURES = [
        'profakturi' => [
            'label' => 'Профактури',
            'sentence' => 'Овде ќе се издаваат профактури кои подоцна се претвораат во фактури.',
        ],
        'popis' => [
            'label' => 'Попис',
            'sentence' => 'Овде ќе се прави годишен попис на залихите и ќе се книжат разликите.',
        ],
        'e-pdd' => [
            'label' => 'е-ПДД',
            'sentence' => 'Овде ќе се подготвуваат и извезуваат е-ПДД пресметките.',
        ],
    ];

    /**
     * The filtered menu tree for this user and company.
     *
     * @return list<array{key: string, label: string, items: list<array{label: string, url: string, pattern: string, soon: bool}>}>
     */
    public static function for(User $user, Company $company): array
    {
        $groups = [];

        foreach (self::tree($company) as $group) {
            $items = array_values(array_filter(
                $group['items'],
                fn (array $item) => self::itemVisible($user, $company, $item)
            ));

            // A group with nothing left in it is dropped entirely — never
            // render a bare heading. This is derived, not declared, so a
            // group returns on its own once its items exist.
            if ($items === []) {
                continue;
            }

            $groups[] = [
                'key' => $group['key'],
                'label' => $group['label'],
                'items' => array_map(
                    fn (array $item) => [
                        'label' => $item['label'],
                        'url' => $item['url'],
                        'pattern' => $item['pattern'],
                        'soon' => $item['soon'] ?? false,
                    ],
                    $items
                ),
            ];
        }

        return $groups;
    }

    private static function itemVisible(User $user, Company $company, array $item): bool
    {
        // Unbuilt entries double as the admin's remaining-work map. A client
        // only ever sees working features.
        if (($item['soon'] ?? false) && ! $user->hasAnyRole(['admin', 'accountant'])) {
            return false;
        }

        // Модулот се проверува пред улогата: ставка од исклучен модул не ја
        // гледа никој, ниту админ. Модулот е поставка на фирмата, не право на
        // корисникот. Групата потоа сама исчезнува кога ќе остане празна —
        // правилото веќе постои во `for()` и не се менува.
        $module = $item['module'] ?? null;

        if ($module instanceof CompanyModule && ! $company->usesModule($module)) {
            return false;
        }

        $roles = $item['roles'] ?? null;

        return $roles === null || $user->hasAnyRole($roles);
    }

    private static function soon(Company $company, string $slug, ?CompanyModule $module = null): array
    {
        return [
            'label' => self::SOON_FEATURES[$slug]['label'],
            'url' => route('coming-soon', [$company, $slug]),
            'pattern' => 'coming-soon',
            'soon' => true,
            'module' => $module,
        ];
    }

    /**
     * The tree branches on the client's type — a legal entity and an
     * individual see almost entirely different applications. See
     * legalTree() and individualTree() below.
     */
    private static function tree(Company $company): array
    {
        return $company->type->isIndividual()
            ? self::individualTree($company)
            : self::legalTree($company);
    }

    /**
     * The full, unfiltered tree for a правно лице (company). 'roles' => null
     * means every role.
     *
     * Прием / Излез / Пренос share one route (inventory.stock-movements.create)
     * distinguished only by a path parameter, so a route-name glob cannot tell
     * them apart. They are given the shared pattern deliberately: all three
     * highlight together on any stock-movement screen, rather than the sidebar
     * reading request()->route('type'), which it must never do.
     */
    private static function legalTree(Company $company): array
    {
        return [
            [
                'key' => 'finance',
                'label' => 'ФИНАНСИИ',
                'items' => [
                    ['label' => 'Главна книга', 'url' => route('accounting.journal-groups.index', $company), 'pattern' => 'accounting.journal-groups.*', 'roles' => ['admin', 'accountant'], 'module' => CompanyModule::FINANCE],
                    ['label' => 'Извештаи и обрасци', 'url' => route('reports.index', $company), 'pattern' => 'reports.*', 'roles' => ['admin', 'accountant'], 'module' => CompanyModule::FINANCE],
                    ['label' => 'Банкарски документи', 'url' => route('bank-statements.index', $company), 'pattern' => 'bank-statements.*', 'roles' => ['admin', 'accountant'], 'module' => CompanyModule::FINANCE],
                ],
            ],
            [
                'key' => 'sales',
                'label' => 'ПРОДАЖБА',
                'items' => [
                    ['label' => 'Излезни фактури', 'url' => route('sales-invoices.index', $company), 'pattern' => 'sales-invoices.*', 'roles' => null, 'module' => CompanyModule::MATERIAL],
                    self::soon($company, 'profakturi', CompanyModule::MATERIAL),
                    // Кооперанти намерно немаат модул: партнерите ги бара и
                    // книжењето, не само фактурирањето. Кога Материјално е
                    // исклучено, оваа ставка останува сама во групата.
                    ['label' => 'Кооперанти', 'url' => route('partners.index', $company), 'pattern' => 'partners.*', 'roles' => null],
                ],
            ],
            [
                'key' => 'costs',
                'label' => 'ТРОШОЦИ',
                'items' => [
                    ['label' => 'Влезни фактури', 'url' => route('purchase-invoices.index', $company), 'pattern' => 'purchase-invoices.*', 'roles' => null, 'module' => CompanyModule::MATERIAL],
                    ['label' => 'Други трошоци', 'url' => route('other-costs.index', $company), 'pattern' => 'other-costs.*', 'roles' => null, 'module' => CompanyModule::MATERIAL],
                ],
            ],
            [
                'key' => 'stock',
                'label' => 'ЗАЛИХА',
                'items' => [
                    ['label' => 'Магацини', 'url' => route('inventory.warehouses.index', $company), 'pattern' => 'inventory.warehouses.*', 'roles' => null, 'module' => CompanyModule::STOCK],
                    ['label' => 'Артикли', 'url' => route('inventory.items.index', $company), 'pattern' => 'inventory.items.*', 'roles' => null, 'module' => CompanyModule::STOCK],
                    ['label' => 'Состојба', 'url' => route('inventory.reports.stock-on-hand', $company), 'pattern' => 'inventory.reports.*', 'roles' => null, 'module' => CompanyModule::STOCK],
                    ['label' => 'Прием', 'url' => route('inventory.stock-movements.create', [$company, 'receipt']), 'pattern' => 'inventory.stock-movements.create', 'roles' => null, 'module' => CompanyModule::STOCK],
                    ['label' => 'Излез', 'url' => route('inventory.stock-movements.create', [$company, 'issue']), 'pattern' => 'inventory.stock-movements.create', 'roles' => null, 'module' => CompanyModule::STOCK],
                    ['label' => 'Пренос', 'url' => route('inventory.stock-movements.create', [$company, 'transfer']), 'pattern' => 'inventory.stock-movements.create', 'roles' => null, 'module' => CompanyModule::STOCK],
                    self::soon($company, 'popis', CompanyModule::STOCK),
                ],
            ],
            [
                'key' => 'payroll',
                'label' => 'ПЛАТИ И ЧОВЕЧКИ РЕСУРСИ',
                'items' => [
                    ['label' => 'Вработени', 'url' => route('employees.index', $company), 'pattern' => 'employees.*', 'roles' => null, 'module' => CompanyModule::PAYROLL],
                    ['label' => 'Плата (МПИН)', 'url' => route('payroll-runs.index', $company), 'pattern' => 'payroll-runs.*', 'roles' => ['admin', 'accountant'], 'module' => CompanyModule::PAYROLL],
                    self::soon($company, 'e-pdd', CompanyModule::PAYROLL),
                ],
            ],
            [
                'key' => 'settings',
                'label' => 'ПОСТАВКИ',
                'items' => [
                    ['label' => 'Компанија', 'url' => route('companies.profile', $company), 'pattern' => 'companies.profile', 'roles' => null],
                    // Контниот план и параметрите за плата седат во Поставки, но
                    // припаѓаат на својот модул и заминуваат заедно со него.
                    ['label' => 'Контен план', 'url' => route('accounting.accounts.index', $company), 'pattern' => 'accounting.accounts.*', 'roles' => ['admin', 'accountant'], 'module' => CompanyModule::FINANCE],
                    ['label' => 'е-Фактура барања', 'url' => route('efaktura.access-requests'), 'pattern' => 'efaktura.access-requests', 'roles' => ['admin']],
                    ['label' => 'Параметри за плата', 'url' => route('payroll-parameters.index', $company), 'pattern' => 'payroll-parameters.*', 'roles' => ['admin'], 'module' => CompanyModule::PAYROLL],
                ],
            ],
        ];
    }

    /**
     * The full, unfiltered tree for a физичко лице (individual). No главна
     * книга, ДДВ, залиха или плати — тие групи не важат за овој тип клиент.
     */
    private static function individualTree(Company $company): array
    {
        return [
            [
                'key' => 'sales',
                'label' => 'ПРОДАЖБА',
                'items' => [
                    ['label' => 'Излезни фактури', 'url' => route('sales-invoices.index', $company), 'pattern' => 'sales-invoices.*', 'roles' => null],
                    ['label' => 'Кооперанти', 'url' => route('partners.index', $company), 'pattern' => 'partners.*', 'roles' => null],
                ],
            ],
            [
                'key' => 'bank',
                'label' => 'БАНКАРСКИ ДОКУМЕНТИ',
                'items' => [
                    ['label' => '743 обрасци', 'url' => route('form743.index', $company), 'pattern' => 'form743.*', 'roles' => null],
                ],
            ],
            [
                'key' => 'filings',
                'label' => 'ПРИЈАВИ',
                'items' => [
                    self::soon($company, 'e-pdd') + ['roles' => ['admin', 'accountant']],
                ],
            ],
            [
                'key' => 'settings',
                'label' => 'ПОСТАВКИ',
                'items' => [
                    ['label' => 'Профил', 'url' => route('companies.profile', $company), 'pattern' => 'companies.profile', 'roles' => null],
                ],
            ],
        ];
    }
}

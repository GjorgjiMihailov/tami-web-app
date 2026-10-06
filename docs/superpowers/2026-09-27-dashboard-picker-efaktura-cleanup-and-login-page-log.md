# Сесиски лог: табло+избирач за сметководител, целосно бришење на фирмениот е-Фактура токен, страница за најава

**Датум:** 2026-09-27 до 2026-09-28.
**Гранки:** директно на `main`, спојувано без прашање (правилото „Ship without asking").

## 1. Сметководителот секогаш слета на порталот (комит `cac5cb9`)

Порано `Dashboard::mount()` автоматски пренасочуваше сметководител право во фирма штом имаше точно една видлива фирма ИЛИ запаметена последна (`CurrentCompany`) — немаше начин да се врати на „дома", ниту да види список пред да избере.

Сопственикот побара: при најава сметководителот прво да го отвора порталното табло, а не веднаш да избира фирма; горе да има опција за избор на фирма, „фенси" изглед — дизајнерска слобода оставена мене.

- `Dashboard::mount()` веќе НЕ редиректира сметководител кон фирма во НИТУ еден случај (само уште нема ниту еден клиент → `onboarding.first-client`, непроменето).
- Клиент (`internal_client`/`freelancer_client`) непроменето автоматски влегува во сопствената единствена фирма — барањето беше изречно само за сметководителот.
- Нов изглед на `resources/views/livewire/dashboard.blade.php`: поздрав, Alpine-пребарувач што филтрира клиентски врз веќе вчитаниот список (нема повторно барање до серверот), „Продолжи со ⟨последна фирма⟩" картичка (ако постои запаметена и сè уште видлива), мрежа од картички во истиот `app-tile` визуелен јазик како таблата на фирма (иста CSS класа, само со иницијали на фирмата наместо икона на апликација).
- `CurrentCompany::lastFor()` сега само за означување „Продолжи", никогаш повеќе за пренасочување на сметководител.

## 2. е-Фактура: фирмениот токен целосно избришан, не само сокриен (комит `29f8c3d`)

Сопственикот забележа: профилот на фирма СЀ УШТЕ имаше целосна картичка за регистрирање потпишувачки уред (мод „свој"/„фирмено", eUJP ID, USB токен) — остаток од пред преработката 2026-09-25/27 кон личен токен по корисник, покрај веќе изградениот личен токен на `/profile`. Двете постоеја паралелно, збунувачки.

Прашано: старите company-level колони (некои фирми веќе имаат сериски број/eUJP-ID запишан на самата фирма) да останат како тивка резерва (`User::efakturaSignerFor()` веќе паѓаше назад на нив ако корисникот нема свој токен) или целосно да се избришат. **Сопственикот избра целосно бришење.**

Отстрането:
- Картичката за регистрирање уред + мод-радио + eUJP-поле од `company-profile.blade.php` — заменета со кратка порака + врска до „твојот профил".
- `CompanyProfile::registerSigningDevice()`, `$editEfakturaMode`, `$editEfakturaEujpId` и нивната валидација/зачувување.
- `CompanyPolicy::manageEfakturaDevice()` (единствениот повикувач бришан заедно со УИ-то).
- `Company::hasEfakturaAccess()`, `Company::decidedBy()`, константите `EFAKTURA_MODE_*`/`EFAKTURA_STATUS_*` — станаа целосно мртви откако `User::efakturaSignerFor()` веќе не чита ништо од фирмата.
- `EfakturaSigner::SOURCE_COMPANY` (останува само `SOURCE_USER` — веќе нема втор извор).
- Миграција `2026_09_30_100000_drop_company_level_efaktura_credential_columns` ги брише од `companies`: `efaktura_credential_mode`, `efaktura_eujp_id`, `efaktura_firm_access_status`, `efaktura_firm_access_decided_by` (со странски клуч), `efaktura_firm_access_decided_at`, `efaktura_token_serial_number`, `efaktura_token_subject_name`, `efaktura_token_not_before`, `efaktura_token_not_after`, `efaktura_token_registered_at` — 9 колони + FK. Пуштена на продукција без проблем (MySQL, CI 36318673988).

**Резултат: `User::efakturaSignerFor(Company $company)` сега е тривијален — без личен токен на глумецот, нема идентитет, никогаш, без исклучок.**

### Готча за паметење
Секој постар тест што „потпишуваше" преку company-level `own`-mode fallback (или преку company-level поле само за да пополни NOT NULL колона) требаше личен токен на ГЛУМЕЦОТ наместо на фирмата — засегнати ~15 тест-фајлови (`EfakturaSendControllerTest`, `EfakturaStatusControllerTest`, `EfakturaIncoming*ControllerTest` ×4, `EfakturaPdfControllerTest`, `SalesInvoiceShowEfakturaTest`, `SalesInvoiceIndexTest`, `PurchaseInvoiceIndexTest`, `CompanyPolicyTest`, `EfakturaPersonalTokenTest`, `EfakturaJwsServiceTest`, `ForeignCurrencyInvoiceTest`, `CompanyProfileFieldsTest`). Секаде додаден мал `giveToken(User $user): User` помошник по тест-класа наместо еден заеднички (тест-класите не делат база). Два фајла целосно тргнати зашто ја тестираа само отстранетата функција: `CompanyProfileSigningDeviceTest`, `Unit/CompanyEfakturaAccessTest`.

Прв full-suite круг откри 8 паднати тестови во `EfakturaJwsServiceTest` — company-level полиња оставени како „чист шум" (не влијаат на потпишувачот таму, но колоните веќе не постојат) — поправено со едноставно бришење на тие полиња од factory-повиците.

## 3. Страница за најава: вистински бренд (комит `d5962e7`)

Побарано одделно: „/login да не е генеричка на Laravel". `layouts/guest.blade.php` беше стандардната Breeze обвивка — сиво поле (`bg-gray-100`), генеричкото Laravel „L" SVG лого, гола бела картичка, **без favicon**. Истиот layout го делат СИТЕ auth-екрани (најава, заборавена/ресетирање лозинка, потврда на лозинка, потврда на е-пошта, прифаќање покана), па поправката важи за сите одеднаш.

Ново: `bg-canvas`, вистинско лого (`/images/logo-icon.png`, истото што стои на јавната страница) + „ТАМИ / FinanceBuddy App" текст, картичка со `border-sand` + `shadow-card` + тенка бренд-портокалова лента на врвот, `favicon.svg` + `apple-touch-icon.png` (претходно ги немаше воопшто во `<head>`). Наменски **не** е користен `font-display` (Fraunces) — тој фонт е само за јавната страница по изречна одлука од порано; внатре во апликацијата (вклучително auth-екраните) фонтот останува Manrope/`font-sans`.

Проверено во вистински прелистувач (`php artisan serve` преку browser-тул, НЕ artisan serve директно — видливо побрзо од порано забележаните 59s најави, веројатно поради `SESSION_DRIVER=database` наместо file/session lock).

### Готча: browser-тулот и работната папка
`preview_start` внатрешно го користел серверскиот `cwd` на СЕСИЈАТА (тогаш сè уште `Documents\Claude sessions`, не `tami-web-app`), па првиот обид засекогаш остана „starting" (нема `.claude/launch.json` таму). Поправка: `mcp__ccd_directory__change_directory` до `tami-web-app` па повторен `preview_start`. За идни сесии што почнуваат надвор од самиот repo: смени ја работната папка ПРЕД first `preview_start`.

## Резиме на комити и CI

| Комит | Опис | CI |
|---|---|---|
| `cac5cb9` | Табло+избирач за сметководител | 36318673988 (заедно со следниов) |
| `29f8c3d` | е-Фактура: фирмен токен целосно избришан | 36318673988 — test (MySQL) + deploy, двете success, 2044/2047 |
| `d5962e7` | Страница за најава: бренд наместо генерика | 36329417691 — test + deploy, двете success |

Види [[tami-efaktura-internal-client]] и [[tami-web-app-project]].

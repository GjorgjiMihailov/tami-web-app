#!/usr/bin/env python3
"""
Gradi docs/reference/official-chart-of-accounts.json od Excel-ot na sopstvenikot
(kolonite: Konto, Ime na kontoto, Nivo, fir, z.d., z.p.).

  python docs/reference/build-official-chart.py <konten-plan.xlsx> [--xlsx-out korigiran.xlsx]

- gi popravuva pravopisnite greski i zalepenite zborovi (CORRECTIONS / WORDS),
- gi dopolnuva imenata sto Excel gi sekol na 120 znaci (OVERRIDES),
- gi zadrzuva starite konta (klasi/grupi/podgrupi) sto gi nema vo Excel-ot,
  dodeka sopstvenikot ne gi dodade (ne se brisat nikogas),
- ja ignorira kolonata "fir".
Povtorno pusteno so poinakov Excel, istite popravki se primenuvaat povtorno.
"""
import collections
import json
import re
import sys
from pathlib import Path

import openpyxl

ROOT = Path(__file__).resolve().parent
OUT = ROOT / 'official-chart-of-accounts.json'

LEVELS = {1: 'class', 2: 'group', 3: 'subgroup'}

# Cel tekst se zamenuva (ime sto Excel go skratil ili go skrsil).
OVERRIDES = {
    '01211': 'Канцелариска опрема (фотокопири, телефони, скенери, сефови, уреди за климатизација, телевизори, ДВД музички системи и сл.)',
    '01217': 'Земјоделска опрема (за обработка на земјиштето, опрема во млекарството, рибарството, овоштарството, лозарството, сточарството)',
    '0360': 'Хартии од вредност со кои се тргува (акции, обврзници, благајнички записи и др. кои се набавени да се продадат за добивка)',
    '4416': 'Трошоци за образование и усовршување на вработените (семинари, симпозиуми, стручно усовршување, преквалификации, јазици)',
    '23501': 'Обврски за персонален данок на надоместоци и награди на членови на управни и надзорни одбори, одбор на директори и управители',
    '41612': 'Дизајн, изградба и операционализација на експериментален погон кој не е во состојба на економски изводливо комерцијално производство',
    '2301': 'Обврски за данок на додадена вредност по излезни фактури за продадени добра и извршени услуги по повластена даночна стапка',
    '4751': 'Пресметани курсни разлики по финансиски пласмани и побарувања и обврски во странска валута на денот на Извештајот за финансиската состојба',
    '4752': 'Пресметани курсни разлики по среден курс на НБРМ на паричните средства во странска валута (девизна сметка, девизна благајна, хартии од вредност)',
    '1352': 'Побарувања за повеќе платен дополнителен придонес за здравствено осигурување во случај на повреда на работа и професионално заболување',
    '4603': 'Неотпишана вредност на нематеријални средства, недвижности, постројки, алат, погонски инвентар, транспортни средства пренесени без надомест',
    '4604': 'Неотпишана вредност на нематеријални средства, недвижности, постројки, алат, погонски инвентар, транспортни средства отуѓени по други основи',
    '5': 'СЛОБОДНА (ЗА ИНТЕРНА УПОТРЕБА-ПОГОНСКИ ПРЕСМЕТКИ И ДРУГИ ПОТРЕБИ)',
    '2305': 'Обврски за данок на додадена вредност за друга крајна потрошувачка (репрезентација, кало, растур, кршење, кусок на добра)',
}

# Zbor po zbor (cel zbor, ne bitno golema/mala bukva).
WORDS = {
    'продазба': 'продажба', 'замјиште': 'земјиште', 'дтуштва': 'друштва',
    'друштвои': 'друштво и', 'нетекновни': 'нетековни', 'стедства': 'средства',
    'среанство': 'средство', 'вкожување': 'вложување', 'сестеми': 'системи',
    'дадеки': 'дадени', 'раборење': 'работење', 'проход': 'приход',
    'надоместои': 'надоместоци', 'надомесоци': 'надоместоци', 'надоместци': 'надоместоци',
    'надомести': 'надоместоци', 'регресс': 'регрес', 'инвалитско': 'инвалидско',
    'инвентср': 'инвентар', 'ипродажба': 'и продажба', 'истранство': 'и странство',
    'договот': 'договор', 'непризнатирасходи': 'непризнати расходи',
    'надоместоцина': 'надоместоци на', 'останатикраткорочни': 'останати краткорочни',
    'нетековнисредства': 'нетековни средства', 'средствакои': 'средства кои',
    'даденипримероци': 'дадени примероци', 'сечуваат': 'се чуваат', 'чуват': 'чуваат',
    'који': 'кои', 'пднапред': 'однапред', 'ннвестиционо': 'инвестиционо',
    'одморалишта': 'одмаралишта', 'атмосверски': 'атмосферски', 'оцаарски': 'оџаќарски',
    'косултантски': 'консултантски', 'преселуваењена': 'преселување на',
    'трошови': 'трошоци', 'употрбната': 'употребната', 'маричи': 'матични',
    'шпедиторски': 'шпедитерски', 'виjадукти': 'виадукти', 'залика': 'залиха',
    'ескотни': 'есконтни', 'воденият': 'водниот', 'goodwil': 'goodwill',
    'каменоари': 'каменоломи',
    'коe': 'кое',  # latinicno "e" vo stariot plan (671)
}
WORD_RE = re.compile(r'(?<![А-Яа-яЃѓЅѕЈјЉљЊњЌќЏџA-Za-z])(' + '|'.join(map(re.escape, WORDS)) + r')(?![А-Яа-яЃѓЅѕЈјЉљЊњЌќЏџA-Za-z])', re.IGNORECASE)


def _swap(m):
    w = m.group(1)
    r = WORDS[w.lower()]
    if w.isupper() and len(w) > 1:
        return r.upper()
    if w[0].isupper():
        return r[0].upper() + r[1:]
    return r


def fix(code, name):
    if code in OVERRIDES:
        return OVERRIDES[code]
    n = WORD_RE.sub(_swap, name)
    n = re.sub(r'\s+', ' ', n).strip()
    n = re.sub(r'\s+,', ',', n)
    n = re.sub(r'(?<=[A-Za-zА-Яа-яЃѓЅѕЈјЉљЊњЌќЏџ0-9])\(', ' (', n)
    n = re.sub(r'\)(?=[A-Za-zА-Яа-яЃѓЅѕЈјЉљЊњЌќЏџ])', ') ', n)
    n = re.sub(r',(?=[^\s\d])', ', ', n)
    n = re.sub(r'(?<=[А-Яа-яЃѓЅѕЈјЉљЊњЌќЏџ])\.(?=[А-Яа-яЃѓЅѕЈјЉљЊњЌќЏџ]{2})', '. ', n)
    return n[0].upper() + n[1:] if n else n


def main():
    src = Path(sys.argv[1])
    xlsx_out = sys.argv[sys.argv.index('--xlsx-out') + 1] if '--xlsx-out' in sys.argv else None

    wb = openpyxl.load_workbook(src)
    ws = wb.active
    old = json.loads(OUT.read_text(encoding='utf-8')) if OUT.exists() else []
    old_by_code = {x['code']: x for x in old if 'level' not in x or x['level'] == 'subgroup'}
    old_class_names = {}
    old_group_names = {}
    for x in old:
        if 'class_name' in x:
            old_class_names[x['class']] = x['class_name']
            old_group_names[x['group']] = x['group_name']
        elif x.get('level') == 'class':
            old_class_names[x['code']] = x['name']
        elif x.get('level') == 'group':
            old_group_names[x['code']] = x['name']

    rows, changes, blank = {}, [], []
    for r in ws.iter_rows(min_row=2):
        code = str(r[0].value).strip() if r[0].value is not None else ''
        name = r[1].value
        if not code:
            continue
        if not name or not str(name).strip():
            blank.append(code)
            continue
        name = str(name)
        fixed = fix(code, name)
        # Imeto sto Excel go skratil, a starata baza go ima celo — zemi go celoto.
        o = old_by_code.get(code)
        if o and len(code) == 3 and o['name'].startswith(fixed.rstrip(' (')) and len(o['name']) > len(fixed):
            fixed = o['name']
        lvl = LEVELS.get(len(code), 'account')
        if len(code) <= 2 and (old_class_names or old_group_names):
            full = (old_class_names if len(code) == 1 else old_group_names).get(code)
            if full and full.upper().startswith(fixed.upper().rstrip(', ')) and len(full) > len(fixed):
                fixed = full
        rows[code] = {
            'code': code, 'name': fixed, 'level': lvl,
            'must_debit': bool(r[4].value), 'must_credit': bool(r[5].value),
        }
        if fixed != name:
            changes.append((code, name, fixed))
        if xlsx_out and fixed != name:
            r[1].value = fixed

    # Stari konta sto gi nema vo Excel-ot (klasi 8, 9, grupi 75-99, podgrupi 745-790 ...)
    carried = []
    for x in old:
        if 'level' in x:
            # nov format (prethoden build) — zadrzi go ako go nema vo Excel-ot
            if x['code'] not in rows and x.get('carried'):
                rows[x['code']] = x
                carried.append(x['code'])
            continue
        for code, name, lvl in ((x['class'], x['class_name'], 'class'),
                                (x['group'], x['group_name'], 'group'),
                                (x['code'], x['name'], 'subgroup')):
            if code not in rows:
                rows[code] = {'code': code, 'name': fix(code, name), 'level': lvl,
                              'must_debit': False, 'must_credit': False, 'carried': True}
                carried.append(code)

    out = []
    for code in sorted(rows):
        a = rows[code]
        parent = None
        for i in range(len(code) - 1, 0, -1):
            if code[:i] in rows:
                parent = code[:i]
                break
        entry = {'code': code, 'name': a['name'], 'level': a['level'], 'parent_code': parent,
                 'must_debit': a['must_debit'], 'must_credit': a['must_credit']}
        if a.get('carried'):
            entry['carried'] = True
        out.append(entry)

    OUT.write_text(json.dumps(out, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    if xlsx_out:
        wb.save(xlsx_out)

    print(f'{len(out)} redovi: ' + ', '.join(f'{k}={v}' for k, v in collections.Counter(e['level'] for e in out).items()))
    print(f'preneseni od stariot plan (ne se vo Excel): {len(carried)}')
    print(f'bez ime (preskoknati): {blank}')
    print(f'popraveni iminja: {len(changes)}')
    (ROOT / 'chart-corrections.tsv').write_text(
        'konto\tbilo\tsega\n' + ''.join(f'{c}\t{a}\t{b}\n' for c, a, b in changes), encoding='utf-8')
    latin = [(e['code'], e['name']) for e in out if re.search(r'[A-Za-z]', e['name'])]
    print('latinica:', latin)
    cut = [(e['code'], e['name'][-40:]) for e in out if len(e['name']) >= 118 and not e['name'].endswith((')', '.', 'слично'))]
    print(f'mozno skrateni na 120 znaci ({len(cut)}):')
    for c in cut:
        print('  ', c)


if __name__ == '__main__':
    main()

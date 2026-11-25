#!/usr/bin/env python3
"""Build WordPress translation files from tools/translations/<locale>.json.

Creates in languages/:
  kalendora-<locale>.po / .mo          PHP strings (load_plugin_textdomain)
  kalendora-<locale>-<md5>.json        JS strings per built script (wp_set_script_translations)
  kalendora.pot                        template for translators

Usage: python3 tools/i18n-build.py [locale ...]   (default: all files in tools/translations)
"""
import hashlib, json, pathlib, struct, subprocess, sys

ROOT = pathlib.Path(__file__).resolve().parent.parent
LANG = ROOT / 'languages'
SCRIPTS = ['build/admin/index.js', 'build/frontend/index.js']
EOT = '\u0004'
PLURAL_FORMS = {'de_DE': 'nplurals=2; plural=(n != 1);', 'bg_BG': 'nplurals=2; plural=(n != 1);'}


def strings():
    out = subprocess.run([sys.executable, str(ROOT / 'tools/i18n-extract.py')], capture_output=True, text=True, check=True).stdout
    data = json.loads(out)
    key = lambda x: EOT.join(x) if isinstance(x, list) else x
    return [key(x) for x in data['php']], [key(x) for x in data['js']]


def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


def write_po(path, header, entries):
    lines = ['msgid ""', 'msgstr ""'] + [f'"{po_escape(h)}\\n"' for h in header] + ['']
    for msgid, msgstr in entries:
        if EOT in msgid:
            single, plural = msgid.split(EOT)
            forms = msgstr if isinstance(msgstr, list) else ['', '']
            lines += [f'msgid "{po_escape(single)}"', f'msgid_plural "{po_escape(plural)}"']
            lines += [f'msgstr[{i}] "{po_escape(f)}"' for i, f in enumerate(forms)]
        else:
            lines += [f'msgid "{po_escape(msgid)}"', f'msgstr "{po_escape(msgstr or "")}"']
        lines.append('')
    path.write_text('\n'.join(lines), encoding='utf-8')


def write_mo(path, header, entries):
    # GNU gettext .mo: plural msgids are "singular\0plural", translations joined by \0.
    items = [('', '\n'.join(header) + '\n')]
    for msgid, msgstr in entries:
        if EOT in msgid:
            items.append((msgid.replace(EOT, '\0'), '\0'.join(msgstr)))
        else:
            items.append((msgid, msgstr))
    items.sort(key=lambda kv: kv[0].encode('utf-8'))
    ids = [k.encode('utf-8') for k, _ in items]
    strs = [v.encode('utf-8') for _, v in items]
    n = len(items)
    key_start = 7 * 4 + 16 * n
    value_start = key_start + sum(len(k) + 1 for k in ids)
    koffsets, voffsets, o = [], [], 0
    for k in ids:
        koffsets += [len(k), key_start + o]
        o += len(k) + 1
    o = 0
    for v in strs:
        voffsets += [len(v), value_start + o]
        o += len(v) + 1
    # Empty hash table placed right after the index tables (required by older readers).
    out = struct.pack('<Iiiiiii', 0x950412de, 0, n, 7 * 4, 7 * 4 + n * 8, 0, 7 * 4 + 16 * n)
    out += struct.pack(f'<{len(koffsets)}i', *koffsets) + struct.pack(f'<{len(voffsets)}i', *voffsets)
    out += b''.join(k + b'\0' for k in ids) + b''.join(v + b'\0' for v in strs)
    path.write_bytes(out)


def main():
    php, js = strings()
    LANG.mkdir(exist_ok=True)
    pot_header = ['Project-Id-Version: Kalendora', 'Content-Type: text/plain; charset=UTF-8', 'X-Domain: kalendora']
    write_po(LANG / 'kalendora.pot', pot_header, [(k, ['', ''] if EOT in k else '') for k in dict.fromkeys(php + js)])

    locales = sys.argv[1:] or [p.stem for p in (ROOT / 'tools/translations').glob('*.json')]
    for locale in locales:
        tr = json.loads((ROOT / f'tools/translations/{locale}.json').read_text(encoding='utf-8'))
        missing = [k for k in dict.fromkeys(php + js) if k not in tr]
        header = ['Project-Id-Version: Kalendora', f'Language: {locale}', 'MIME-Version: 1.0',
                  'Content-Type: text/plain; charset=UTF-8', f'Plural-Forms: {PLURAL_FORMS.get(locale, "nplurals=2; plural=(n != 1);")}']

        entries = [(k, tr[k]) for k in dict.fromkeys(php + js) if k in tr]
        write_po(LANG / f'kalendora-{locale}.po', header, entries)
        write_mo(LANG / f'kalendora-{locale}.mo', header, [(k, v) for k, v in entries if k in set(php)])

        messages = {'': {'domain': 'messages', 'lang': locale, 'plural-forms': PLURAL_FORMS.get(locale, 'nplurals=2; plural=(n != 1);')}}
        for k in dict.fromkeys(js):
            if k in tr:
                messages[k.split(EOT)[0] if EOT in k else k] = tr[k] if isinstance(tr[k], list) else [tr[k]]
        payload = json.dumps({'domain': 'messages', 'locale_data': {'messages': messages}}, ensure_ascii=False)
        for script in SCRIPTS:
            md5 = hashlib.md5(script.encode()).hexdigest()
            (LANG / f'kalendora-{locale}-{md5}.json').write_text(payload, encoding='utf-8')

        print(f'{locale}: {len(entries)} translated, {len(missing)} missing')
        for m in missing:
            print('  missing:', m.replace(EOT, ' | '))


if __name__ == '__main__':
    main()

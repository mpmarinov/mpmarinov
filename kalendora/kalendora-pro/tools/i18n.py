#!/usr/bin/env python3
"""Translations of Kalendora Pro (PHP only, text domain 'kalendora-pro').

Reads tools/translations/<locale>.json and writes languages/kalendora-pro-<locale>.po/.mo and kalendora-pro.pot.
Reuses the .po/.mo writer of the Kalendora core (../kalendora/tools/i18n-build.py).
Usage: python3 tools/i18n.py            (prints missing strings per locale)
"""
import importlib.util, json, pathlib, re

ROOT = pathlib.Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location('core', ROOT.parent / 'kalendora/tools/i18n-build.py')
core = importlib.util.module_from_spec(spec)
spec.loader.exec_module(core)

Q = r"""(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")"""
SIMPLE = re.compile(r"\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*" + Q + r"\s*,\s*'kalendora-pro'\s*\)", re.S)
PLURAL = re.compile(r"\b_n\(\s*" + Q + r"\s*,\s*" + Q + r"\s*,[^;]*?'kalendora-pro'\s*\)", re.S)


def unescape(single, double=None):
    if double is not None:
        return double.replace('\\n', '\n').replace('\\"', '"').replace('\\$', '$').replace('\\\\', '\\')
    return single.replace("\\'", "'").replace('\\\\', '\\')


def strings():
    out = []
    for f in sorted([ROOT / 'kalendora-pro.php'] + list((ROOT / 'src').glob('*.php'))):
        text = f.read_text(encoding='utf-8')
        for m in SIMPLE.finditer(text):
            s = unescape(m.group(1), m.group(2))
            out.append(s)
        for m in PLURAL.finditer(text):
            out.append(unescape(m.group(1), m.group(2)) + core.EOT + unescape(m.group(3), m.group(4)))
    seen, uniq = set(), []
    for s in out:
        if s not in seen:
            seen.add(s)
            uniq.append(s)
    return uniq


def main():
    keys = strings()
    lang = ROOT / 'languages'
    core.write_po(lang / 'kalendora-pro.pot', ['Project-Id-Version: Kalendora Pro', 'Content-Type: text/plain; charset=UTF-8'], [(k, '' if core.EOT not in k else ['', '']) for k in keys])
    for path in sorted((ROOT / 'tools/translations').glob('*.json')):
        locale = path.stem
        tr = json.loads(path.read_text(encoding='utf-8'))
        entries = [(k, tr[k]) for k in keys if k in tr and tr[k]]
        missing = [k for k in keys if k not in tr or not tr[k]]
        header = ['Project-Id-Version: Kalendora Pro', f'Language: {locale}', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Plural-Forms: nplurals=2; plural=(n != 1);']
        core.write_po(lang / f'kalendora-pro-{locale}.po', header, entries)
        core.write_mo(lang / f'kalendora-pro-{locale}.mo', header, entries)
        print(f'{locale}: {len(entries)} translated, {len(missing)} missing')
        for k in missing:
            print('  missing: ' + k.replace(core.EOT, ' | '))
    print(f'{len(keys)} strings')


if __name__ == '__main__':
    main()

#!/usr/bin/env python3
"""Extract translatable strings (text domain 'bookora') from PHP and JS sources into JSON.

Usage: python3 tools/i18n-extract.py > strings.json
Output: {"php": [...], "js": [...]} where each entry is a string or [singular, plural].
"""
import json, re, sys, pathlib

ROOT = pathlib.Path(__file__).resolve().parent.parent
Q = r"'((?:[^'\\]|\\.)*)'"
SIMPLE = re.compile(r"\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*" + Q + r"\s*,\s*'bookora'\s*\)", re.S)
PLURAL = re.compile(r"\b_n\(\s*" + Q + r"\s*,\s*" + Q + r"\s*,[^;]*?'bookora'\s*\)", re.S)

def unescape(s):
    return s.replace("\\'", "'").replace("\\\\", "\\")

def scan(globs):
    found = []
    for g in globs:
        for f in sorted(ROOT.glob(g)):
            text = f.read_text(encoding='utf-8')
            for m in SIMPLE.finditer(text):
                found.append(unescape(m.group(1)))
            for m in PLURAL.finditer(text):
                found.append([unescape(m.group(1)), unescape(m.group(2))])
    out, seen = [], set()
    for s in found:
        k = json.dumps(s)
        if k not in seen:
            seen.add(k)
            out.append(s)
    return out

print(json.dumps({
    'php': scan(['bookora.php', 'src/**/*.php']),
    'js': scan(['assets/**/*.js']),
}, ensure_ascii=False, indent=1))

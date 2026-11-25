#!/usr/bin/env python3
"""Strict RFC 5545 format check for an iCalendar file or URL (no dependencies).

Usage: python3 tests/Fuzz/ics-lint.py <file-or-url>
"""
import re, sys, urllib.request

src = sys.argv[1]
raw = urllib.request.urlopen(src).read() if src.startswith('http') else open(src, 'rb').read()
errors = []
if b'\n' in raw.replace(b'\r\n', b''):
    errors.append('bare LF line ending')
lines = raw.split(b'\r\n')
for i, l in enumerate(lines, 1):
    if len(l) > 75:
        errors.append(f'line {i}: {len(l)} octets > 75')
    try:
        l.decode('utf-8')
    except UnicodeDecodeError:
        errors.append(f'line {i}: folding split a UTF-8 character')
text = raw.decode('utf-8', 'replace').replace('\r\n ', '').replace('\r\n\t', '')
props = [l for l in text.split('\r\n') if l]
stack, events = [], []
for p in props:
    if not re.match(r'^[A-Z0-9-]+(;[^:]*)?:', p):
        errors.append(f'malformed content line: {p[:60]}')
    if p.startswith('BEGIN:'):
        stack.append(p[6:]); events.append(set()) if p == 'BEGIN:VEVENT' else None
    elif p.startswith('END:'):
        if not stack or stack.pop() != p[4:]:
            errors.append(f'unbalanced {p}')
    elif stack and stack[-1] == 'VEVENT':
        events[-1].add(p.split(':')[0].split(';')[0])
if stack:
    errors.append(f'unclosed {stack}')
for n, e in enumerate(events, 1):
    for req in ('UID', 'DTSTAMP', 'DTSTART'):
        if req not in e:
            errors.append(f'event {n}: missing {req}')
for p in props:
    m = re.match(r'^(DTSTART|DTEND|DTSTAMP|LAST-MODIFIED):(\d{8}T\d{6}Z)$', p)
    if p.startswith(('DTSTART', 'DTEND')) and not m:
        errors.append(f'bad date: {p}')
uids = [p for p in props if p.startswith('UID:')]
if len(uids) != len(set(uids)):
    errors.append('duplicate UID')
print(f'{len(events)} events, {len(lines)} lines, {len(errors)} problems')
for e in errors[:20]:
    print('  -', e)
sys.exit(1 if errors else 0)

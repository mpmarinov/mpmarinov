#!/usr/bin/env python3
"""Differential test: Kalendora's PHP Rrule vs python-dateutil on random recurrence rules.

Usage: python3 tests/Fuzz/rrule-fuzz.py [cases] [seed]
"""
import json, random, subprocess, sys, pathlib
from datetime import datetime, timedelta
from zoneinfo import ZoneInfo
from dateutil.rrule import rrulestr

N = int(sys.argv[1]) if len(sys.argv) > 1 else 2000
random.seed(int(sys.argv[2]) if len(sys.argv) > 2 else 42)
ZONES = ['Europe/Berlin', 'Europe/Sofia', 'America/New_York', 'UTC', 'Australia/Sydney']
DAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']

def rule():
    freq = random.choice(['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'])
    parts = ['FREQ=' + freq]
    if random.random() < .5: parts.append('INTERVAL=%d' % random.randint(1, 4))
    if freq in ('WEEKLY', 'DAILY') and random.random() < .6:
        parts.append('BYDAY=' + ','.join(random.sample(DAYS, random.randint(1, 3))))
    if freq in ('MONTHLY', 'YEARLY'):
        r = random.random()
        if r < .35:
            # Either all with ordinal or all without: dateutil deviates from RFC 5545 for mixes like WE,-2WE.
            ords = ['1', '2', '3', '4', '-1', '-2'] if random.random() < .7 else ['']
            parts.append('BYDAY=' + ','.join(sorted({'%s%s' % (random.choice(ords), random.choice(DAYS)) for _ in range(random.randint(1, 2))})))
        elif r < .65:
            parts.append('BYMONTHDAY=' + ','.join(str(random.choice([1, 5, 15, 28, 29, 30, 31, -1, -2])) for _ in range(random.randint(1, 2))))
        elif r < .75:
            parts.append('BYDAY=MO,TU,WE,TH,FR;BYSETPOS=' + random.choice(['1', '-1', '2', '1,-1']))
    if freq == 'YEARLY' and random.random() < .5:
        parts.append('BYMONTH=' + ','.join(str(m) for m in sorted(random.sample(range(1, 13), random.randint(1, 3)))))
    if freq in ('DAILY', 'MONTHLY') and random.random() < .2:
        parts.append('BYMONTH=' + ','.join(str(m) for m in sorted(random.sample(range(1, 13), random.randint(1, 4)))))
    end = random.random()
    if end < .3: parts.append('COUNT=%d' % random.choice([random.randint(1, 40), random.randint(100, 2500)]))
    elif end < .5: parts.append('UNTIL=%s' % (datetime(2026, 1, 1) + timedelta(days=random.randint(0, 900))).strftime('%Y%m%dT%H%M%SZ'))
    if freq == 'WEEKLY' and random.random() < .2: parts.append('WKST=SU')
    return ';'.join(parts)

cases, expected = [], []
for _ in range(N):
    tz = random.choice(ZONES)
    hour = random.choice([0, 7, 9, 12, 13, 18, 23])
    base = datetime(2025, 1, 1) if random.random() < .6 else datetime(2008, 1, 1)  # old series test skip-ahead with COUNT
    start = base.replace(hour=hour, minute=random.choice([0, 15, 30, 45])) + timedelta(days=random.randint(0, 700))
    r = rule()
    frm = datetime(2026, 1, 1, tzinfo=ZoneInfo('UTC')) + timedelta(days=random.randint(0, 300))
    to = frm + timedelta(days=random.randint(1, 400))
    aware = start.replace(tzinfo=ZoneInfo(tz))
    rr = rrulestr('RRULE:' + r.replace('Z', '') if 'UNTIL' not in r else 'RRULE:' + r, dtstart=aware, forceset=False)
    try:
        inst = rr.between(frm - timedelta(seconds=1), to + timedelta(seconds=1), inc=True)
    except Exception as e:  # dateutil rejects some combinations
        continue
    expected.append(sorted({int(d.timestamp()) for d in inst if frm.timestamp() <= d.timestamp() <= to.timestamp()}))
    cases.append({'rule': r, 'start': start.strftime('%Y-%m-%d %H:%M:%S'), 'tz': tz, 'from': int(frm.timestamp()), 'to': int(to.timestamp())})

php = subprocess.run(['php', str(pathlib.Path(__file__).with_name('rrule-harness.php'))], input='\n'.join(json.dumps(c) for c in cases) + '\n', capture_output=True, text=True)
if php.returncode:
    print(php.stderr); sys.exit(1)
got = [json.loads(l) for l in php.stdout.splitlines()]
bad = 0
for c, e, g in zip(cases, expected, got):
    if e != g:
        bad += 1
        if bad <= 8:
            fmt = lambda xs: [datetime.fromtimestamp(x, ZoneInfo(c['tz'])).strftime('%Y-%m-%d %a %H:%M') for x in xs[:6]]
            print('MISMATCH', c['rule'], c['start'], c['tz'], '\n  dateutil:', len(e), fmt(e), '\n  kalendora: ', len(g), fmt(g))
print(f'{len(cases)} rules compared, {bad} mismatches, {sum(map(len, expected))} instances')
sys.exit(1 if bad else 0)

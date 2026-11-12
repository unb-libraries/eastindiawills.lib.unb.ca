#!/usr/bin/env python3
"""Build the CSV sources for the eiw_3_voyage_stops and eiw_4_voyages migrations.

Input is the merged 17th-century voyage research (voyages.csv, voyage_stops.csv).
Ports are not generated: ../data/csv/eiw_ports.csv is the maintained port list
(with map coordinates) for eiw_2_ports, and every port a stop uses must be in it.
Output goes to ../data/csv/:

  eiw_voyage_stops.csv  one row per eiw_voyage_stop paragraph
  eiw_voyages.csv       one row per eiw_voyage node, with its ordered stop ids

Both cover the whole research period. The years actually imported are set in
eiw_migrate.settings (voyage_years) and applied by VoyageYearFilter at import,
using the voyage's departure year (year / voyage_year columns).

The site gives each voyage a single itinerary, but a 17th-century voyage was a fleet
whose ships often split up after leaving England. So each voyage's stops are reduced
to one port call per port per stage (outward, then in Asia, then homeward), in the
order first visited. Each stop's notes record which ships called there and when.
Stops at sea (losses, engagements) are not port calls: they go in the voyage notes.

Usage:
  python3 build_voyage_csv.py /path/to/research/final
"""
import csv
import html
import os
import re
import sys
from collections import OrderedDict, defaultdict

STAGES = ('outward', 'in_asia', 'homeward')
STAGE_LABEL = {'outward': 'Outward', 'in_asia': 'In Asia', 'homeward': 'Homeward'}
CONF_RANK = {'confirmed': 0, 'probable': 1, 'unverified': 2}
MAX_DATE = 10  # field_arrival_date / field_departure_date are string(10)


def slug(text):
    return re.sub(r'[^a-z0-9]+', '-', text.lower()).strip('-')


def read(path):
    with open(path, newline='', encoding='utf-8') as fh:
        return list(csv.DictReader(fh))


def write(path, fields, rows):
    with open(path, 'w', newline='', encoding='utf-8') as fh:
        w = csv.DictWriter(fh, fieldnames=fields)
        w.writeheader()
        w.writerows(rows)


def short_ship(identity):
    """'Hector (1), c.300 tons (Markham ...)' -> 'Hector (1)'; 'Ascension (260 tons, ...)' -> 'Ascension'."""
    m = re.match(r'^(.+?\(\d+\))', identity)
    if m:
        return m.group(1).strip()
    return re.split(r',| \(| c\.| \d{2,4} ?t', identity, maxsplit=1)[0].strip()


def date_ok(value):
    value = (value or '').strip()
    return value if re.fullmatch(r'\d{4}(-\d{2}(-\d{2})?)?', value) and len(value) <= MAX_DATE else ''


def visit_date(x):
    """A visit's own date: arrival if known, otherwise departure."""
    return date_ok(x['arrival']) or date_ok(x['departure'])


def sort_key(value, stage, prev=''):
    """Comparable key for a partial ISO date.

    A bare year can't be placed within its year, so it doesn't jump ahead of
    the call before it: if that call is in the same year it takes that call's
    key (ties keep first-seen order). Otherwise it sorts to the start of the
    year on the outward leg and to the end of it on the homeward leg (a
    voyage's 'England 1603' is its return)."""
    if len(value) == 4:
        if prev.startswith(value):
            return prev
        return value + ('-13' if stage == 'homeward' else '-00')
    return value


def home_water(lat, lon):
    """Britain, Ireland and the Channel: where every voyage began and ended."""
    return lat > 48 and -11 < lon < 3


def order_stage(ports, stage, home=frozenset()):
    """Order one stage's port calls by the earliest date any ship called there.
    Undated ports keep their place after the port first seen before them.

    Home-water ports come first on the outward stage and last on the homeward
    stage: a fleet's ships came home in different seasons, but its arrival in
    England ends the voyage even when one ship got there before another called
    at St Helena."""
    group = {'outward': lambda p: 0 if p in home else 1,
             'homeward': lambda p: 1 if p in home else 0}.get(stage, lambda p: 0)
    keyed, prev = [], ''
    for i, (port, visits) in enumerate(ports.items()):
        dates = [d for d in map(visit_date, visits) if d]
        # A full date places a port better than a bare year from another ship.
        dates = [sort_key(d, stage, prev) for d in ([d for d in dates if len(d) > 4] or dates)]
        key = min(dates) if dates else prev
        prev = key
        keyed.append((group(port), key, i, port, visits))
    return [(port, visits) for _, _, _, port, visits in sorted(keyed)]


def main(src):
    voyages = read(os.path.join(src, 'voyages.csv'))
    stops = read(os.path.join(src, 'voyage_stops.csv'))

    out = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'data', 'csv')

    # Ports are maintained by hand in eiw_ports.csv (names, descriptions, map
    # coordinates); this script only reads it to resolve each stop's port_id.
    port_list = read(os.path.join(out, 'eiw_ports.csv'))
    port_id = {p['name']: p['port_id'] for p in port_list}
    home = frozenset(p['name'] for p in port_list
                     if p['latitude'] and home_water(float(p['latitude']), float(p['longitude'])))
    missing = sorted({s['port'] for s in stops if s['port_type'] != 'at_sea' and s['port']} - set(port_id))
    if missing:
        sys.exit('Ports missing from eiw_ports.csv (add them first): ' + '; '.join(missing))

    by_voyage = defaultdict(list)
    for s in stops:
        by_voyage[s['voyage_id']].append(s)

    stop_rows, voyage_rows = [], []
    for v in voyages:
        rows = by_voyage[v['voyage_id']]
        vslug = slug(v['voyage'])[:120]
        # Ships with the longest itinerary first, so its route sets the order of undated
        # calls; each ship's stops keep their recorded order. Dated calls are then sorted.
        counts = defaultdict(int)
        for r in rows:
            counts[r['ship']] += 1
        rows = sorted(rows, key=lambda r: (-counts[r['ship']], r['ship'], int(r['order_in_voyage'])))

        calls = OrderedDict((st, OrderedDict()) for st in STAGES)
        at_sea = []
        for r in rows:
            if r['port_type'] == 'at_sea' or not r['port']:
                at_sea.append(r)
                continue
            stage = r['direction'] if r['direction'] in STAGES else 'in_asia'
            calls[stage].setdefault(r['port'], []).append(r)

        stop_ids = []
        n = 0
        for stage in STAGES:
            for port, visits in order_stage(calls[stage], stage, home):
                n += 1
                # Dates come from one ship's visit (the earliest fully dated one), so arrival and
                # departure stay consistent; every ship's own dates are in the notes.
                dated = min((x for x in visits if visit_date(x)),
                            key=lambda x: (len(visit_date(x)) <= 4, visit_date(x)), default=None)
                arrival = date_ok(dated['arrival']) if dated else ''
                departure = date_ok(dated['departure']) if dated else ''
                lines = []
                for x in visits:
                    when = ', '.join(filter(None, [
                        'arr. ' + x['arrival'] if x['arrival'] else '',
                        'dep. ' + x['departure'] if x['departure'] else '']))
                    recorded = f" (as \"{x['port_as_recorded']}\")" if x['port_as_recorded'] != port else ''
                    line = f"{short_ship(x['ship'])}{recorded}: {when or 'date unknown'} [{x['confidence'] or 'unrated'}]"
                    if x['notes']:
                        line += '. ' + x['notes'].rstrip('.') + '.'
                    if x['source']:
                        line += ' Source: ' + x['source']
                    lines.append(line)
                sid = f'{vslug}--{n:03d}'
                stop_ids.append(sid)
                stop_rows.append({
                    'stop_id': sid, 'voyage_id': vslug, 'voyage_year': v['year'], 'delta': n - 1,
                    'port_id': port_id.get(port, ''), 'port': port,
                    'arrival': arrival,
                    'departure': departure,
                    'homeward': 1 if stage == 'homeward' else 0,
                    'notes': '\n'.join(lines),
                })

        ships = [s.strip() for s in v['fleet_ships'].split(';') if s.strip()]
        e = html.escape
        desc = [f"<p><strong>Ships:</strong> {e(', '.join(ships) or v['ships_researched'])}</p>"]
        stage_cols = {'outward': 'ports_outward', 'in_asia': 'ports_in_asia', 'homeward': 'ports_homeward'}
        items = [f"<li><strong>{STAGE_LABEL[st]}:</strong> {e(v[col].replace('; ', ', '))}</li>"
                 for st, col in stage_cols.items() if v[col]]
        if items:
            desc.append('<p><strong>Ports of call</strong></p><ul>' + ''.join(items) + '</ul>')
        sources = [s.strip() for s in v['sources'].split(' | ') if s.strip()]
        if sources:
            desc.append('<p><strong>Sources</strong></p><ul>' + ''.join(f'<li>{e(s)}</li>' for s in sources) + '</ul>')

        notes = [
            f"Ships identified: {v['ships_researched']}.",
            f"Ship names in the project list: {v['names_in_list']}.",
            f"Lowest confidence of any stop: {v['lowest_confidence']}.",
            'Itinerary condensed to one call per port per stage; see each stop\'s notes for per-ship dates.',
        ]
        if at_sea:
            notes.append('At sea: ' + '; '.join(
                f"{short_ship(x['ship'])}: {x['port_as_recorded'] or 'at sea'}"
                f"{' (' + (x['arrival'] or x['departure']) + ')' if (x['arrival'] or x['departure']) else ''}"
                f"{' - ' + x['notes'].rstrip('.') if x['notes'] else ''}" for x in at_sea) + '.')
        notes.append('Compiled Nov 2026 from AI-assisted research; verify against Farrington, '
                     'Catalogue of East India Company Ships\' Journals and Logs (1999).')

        voyage_rows.append({'voyage_id': vslug, 'year': v['year'], 'title': v['voyage'][:255],
                            'description': ''.join(desc), 'notes': '\n'.join(notes),
                            'stop_ids': '|'.join(stop_ids)})

    write(os.path.join(out, 'eiw_voyage_stops.csv'),
          ['stop_id', 'voyage_id', 'voyage_year', 'delta', 'port_id', 'port', 'arrival', 'departure', 'homeward', 'notes'],
          stop_rows)
    write(os.path.join(out, 'eiw_voyages.csv'), ['voyage_id', 'year', 'title', 'description', 'notes', 'stop_ids'],
          voyage_rows)
    dup = len(voyage_rows) - len({r['voyage_id'] for r in voyage_rows})
    print(f'ports={len(port_id)} stops={len(stop_rows)} voyages={len(voyage_rows)} duplicate_voyage_ids={dup}')


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])

#!/usr/bin/env python3
"""Build the CSV sources for the eiw_5_ship_images, eiw_6_ship_identities and
eiw_7_ships migrations.

Input is the merged 17th-century ship research in the research "final"
directory: ship_details.csv (one row per physical ship), type_images.csv (one
representative picture per ship type), images/ (the picture files) and
voyages.csv (to turn research voyage IDs into the voyage migration's IDs).
Output goes to ../data/csv/ and ../data/images/:

  eiw_ship_images.csv       one row per picture file (named-ship and type pictures)
  eiw_ship_identities.csv   one row per eiw_ship_identity paragraph
  eiw_ships.csv             one row per ship name, with its ships' keys

All cover the whole research period. The years actually imported are set in
eiw_migrate.settings (voyage_years) and applied by VoyageYearFilter at import,
using each ship's service span and voyage years.

Only ship names in eiw_migrate.settings (ships, the names in the wills) are
output, with the ships that sailed under them; the research also covers a few
other names (e.g. Reformation), which stay in the research data.

A ship with no picture of its own gets its type's representative picture,
flagged as representative so the site captions it as "a ship of this type".

Usage:
  python3 build_ship_csv.py /path/to/research/final
"""
import html
import os
import re
import shutil
import sys

import yaml

from build_voyage_csv import read, short_ship, slug, write

TYPES = ('ship', 'pinnace', 'victualler', 'galliot', 'frigate', 'sloop', 'country ship', 'other')
ACQUIRED = ('built', 'bought', 'hired/freighted', 'prize', 'licensed', 'interloper')


def integer(value):
    """'c.300' -> '300'; '' or junk -> ''."""
    m = re.search(r'\d+', value or '')
    return m.group(0) if m else ''


def sources_html(text):
    """Semicolon-separated citations -> <ul>, with bare URLs linked."""
    # "X via final/ship_notes.md", "Earlier research final/ship_notes.md (X)":
    # keep the source X, drop the pointer to the research file.
    text = re.sub(r'Earlier research final/ship_notes\.md \(([^;]*?)\)?(?=;|$)', r'\1', text or '')
    text = re.sub(r'\s*\(?(?:via|per) final/ship_notes\.md\)?', '', text)
    items = [s.strip() for s in re.split(r';\s+(?=[A-Za-z(\'"]|https?:)', text or '') if s.strip()]
    # Citations of the research files themselves mean nothing on the site.
    items = [s for s in items if not s.startswith(('final/', 'Earlier research'))]
    # Tidy what the pointer removal leaves: a trailing comma, or a closing
    # bracket whose opening one went with the pointer.
    items = [re.sub(r',$', '', s[:-1] if s.endswith(')') and s.count(')') > s.count('(') else s) for s in items]
    if not items:
        return ''

    def link(s):
        s = html.escape(s)
        return re.sub(r'(https?://[^\s<]+[^\s<.,;)])', r'<a href="\1">\1</a>', s)
    return '<ul>' + ''.join(f'<li>{link(s)}</li>' for s in items) + '</ul>'


def label(ship):
    """'Hector (1), c.300 tons ...' + 1600-1616 -> 'Hector (1), 1600–1616'."""
    name = short_ship(ship['ship_identity'])
    start, end = integer(ship['service_from']), integer(ship['service_to'])
    span = f'{start}–{end}' if start and end and start != end else (start or end)
    return f'{name}, {span}' if span else name


def main(src):
    here = os.path.dirname(os.path.abspath(__file__))
    out = os.path.join(here, '..', 'data', 'csv')
    image_out = os.path.join(here, '..', 'data', 'images')
    os.makedirs(image_out, exist_ok=True)

    with open(os.path.join(here, '..', 'config', 'install', 'eiw_migrate.settings.yml'), encoding='utf-8') as fh:
        site_names = set(yaml.safe_load(fh)['ships'])

    def listed(ship):
        return [n for n in (x.strip() for x in ship['name_in_list'].split(';')) if n in site_names]

    # Rows marked import "no: <reason>" identify no ship (see merge_ships.py).
    ships = [s for s in read(os.path.join(src, 'ship_details.csv'))
             if s.get('import', 'yes') == 'yes' and listed(s)]
    types = {t['type']: t for t in read(os.path.join(src, 'type_images.csv'))}
    voyages = {v['voyage_id']: v for v in read(os.path.join(src, 'voyages.csv'))}

    images = {}

    def add_image(image_id, file, alt):
        path = os.path.join(src, 'images', file)
        if not os.path.isfile(path):
            sys.exit(f'Missing image file: {path}')
        shutil.copyfile(path, os.path.join(image_out, file))
        images[image_id] = {'image_id': image_id, 'file': file, 'alt': alt}

    identity_rows, names = [], {}
    problems = []
    for s in ships:
        key = s['ship_key']
        ship_type = s['ship_type'] if s['ship_type'] in TYPES else ('other' if s['ship_type'] else '')
        acquired = s['acquired'] if s['acquired'] in ACQUIRED else ''
        if s['acquired'] and not acquired:
            problems.append(f'{key}: acquired "{s["acquired"]}" not one of {ACQUIRED}')

        vids, vyears = [], []
        for vid in filter(None, (x.strip() for x in re.split(r'[;|,]', s.get('voyage_ids', '')))):
            if vid not in voyages:
                problems.append(f'{key}: unknown voyage {vid}')
                continue
            vids.append(slug(voyages[vid]['voyage'])[:120])
            vyears.append(voyages[vid]['year'])

        # The ship's own picture if research found one (file in images/ named
        # by ship key), else its type's representative picture.
        image_id, caption, representative = '', '', 0
        own = s.get('image_file', '')
        if own:
            image_id = key
            add_image(key, own, s.get('image_alt') or f'{label(s)}')
            caption = s.get('image_caption', '')
        else:
            t = types.get(ship_type or 'ship')
            if t:
                image_id = 'type-' + slug(t['type'])
                if image_id not in images:
                    add_image(image_id, t['file'], f"{t['title']}, {t['artist']}, {t['date']}".strip(', '))
                # The site labels representative pictures itself (see
                # paragraph--eiw-ship-identity.html.twig), so drop the
                # research caption's own disclaimer.
                caption = html.escape(re.sub(r'\s*A ship of this kind, not this ship\.?\s*$', '', t['caption']))
                if t.get('page_url'):
                    caption += f' <a href="{html.escape(t["page_url"])}">{html.escape(t["institution"] or "Source")}</a>'
                representative = 1

        identity_rows.append({
            'ship_key': key,
            'label': label(s)[:255],
            'former_names': s['former_names'][:255],
            'later_names': s['later_names'][:255],
            'ship_type': ship_type,
            'burthen_tons': integer(s['burthen_tons']),
            'burthen_note': s['burthen_note'][:255],
            'acquired': acquired,
            'builder_yard': s['builder_yard'][:255],
            'year_built': integer(s['year_built']),
            'year_acquired': integer(s['year_acquired']),
            'guns': integer(s['guns']),
            'men': integer(s['men']),
            'service_from': integer(s['service_from']),
            'service_to': integer(s['service_to']),
            'fate': s['fate'][:255],
            'fate_place': s['fate_place'][:255],
            'fate_year': integer(s['fate_year']),
            'voyage_ids': '|'.join(dict.fromkeys(vids)),
            'voyage_years': '|'.join(vyears),
            'image_id': image_id,
            'image_alt': images[image_id]['alt'][:512] if image_id else '',
            'image_caption': f'<p>{caption}</p>' if caption else '',
            'image_representative': representative,
            'sources': sources_html(s['sources']),
            'notes': '\n'.join(filter(None, [
                f"Confidence: {s['confidence']}." if s['confidence'] else '',
                s['notes'],
            ])),
        })
        for name in listed(s):
            names.setdefault(name, []).append(key)

    write(os.path.join(out, 'eiw_ship_images.csv'), ['image_id', 'file', 'alt'], images.values())
    write(os.path.join(out, 'eiw_ship_identities.csv'), list(identity_rows[0]), identity_rows)
    write(os.path.join(out, 'eiw_ships.csv'), ['name', 'ship_keys'],
          [{'name': n, 'ship_keys': '|'.join(dict.fromkeys(k))} for n, k in sorted(names.items())])

    dup = len(identity_rows) - len({r['ship_key'] for r in identity_rows})
    print(f'images={len(images)} ships={len(identity_rows)} names={len(names)} duplicate_keys={dup}')
    for p in problems:
        print('warning:', p)


if __name__ == '__main__':
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    main(sys.argv[1])

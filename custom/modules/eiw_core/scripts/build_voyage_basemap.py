#!/usr/bin/env python3
"""Build the engraved-style base map for voyage maps.

Reads Natural Earth land polygons (public domain) as TopoJSON, as packaged
by the world-atlas npm package, and writes assets/voyage-map-base.svg:
land with a hatch fill, "water lining" (concentric lines following the
coast out to sea, as on engraved charts), a graticule and a frame.

The projection here must match VoyageMapBuilder (equirectangular, same
bounds and scale), since routes are drawn over this image in the same
coordinate space.

Usage:
  curl -o land-50m.json https://cdn.jsdelivr.net/npm/world-atlas@2/land-50m.json
  python3 build_voyage_basemap.py land-50m.json ../assets/voyage-map-base.svg
"""

import json
import sys

# Keep in sync with \Drupal\eiw_core\VoyageMapBuilder.
LON_MIN, LON_MAX = -80.0, 150.0
LAT_MIN, LAT_MAX = -46.0, 60.0
SCALE = 5.0  # px per degree.
WIDTH = (LON_MAX - LON_MIN) * SCALE
HEIGHT = (LAT_MAX - LAT_MIN) * SCALE

PAPER = '#f6f3ec'
INK = '#303030'
WATER_LINE = '#9aa7b2'
HATCH = '#a8a090'
GRID = '#c9c2b4'


def project(lon, lat):
    return ((lon - LON_MIN) * SCALE, (LAT_MAX - lat) * SCALE)


def decode_arcs(topo):
    sx, sy = topo['transform']['scale']
    tx, ty = topo['transform']['translate']
    arcs = []
    for arc in topo['arcs']:
        x = y = 0
        pts = []
        for dx, dy in arc:
            x += dx
            y += dy
            pts.append((x * sx + tx, y * sy + ty))
        arcs.append(pts)
    return arcs


def ring_from(arc_ids, arcs):
    pts = []
    for i in arc_ids:
        a = arcs[i] if i >= 0 else list(reversed(arcs[~i]))
        pts.extend(a if not pts else a[1:])
    return pts


def clip(ring, edge_in, intersect):
    out = []
    for i, cur in enumerate(ring):
        prev = ring[i - 1]
        if edge_in(cur):
            if not edge_in(prev):
                out.append(intersect(prev, cur))
            out.append(cur)
        elif edge_in(prev):
            out.append(intersect(prev, cur))
    return out


def clip_to_bounds(ring, pad=3.0):
    """Sutherland-Hodgman clip of a lon/lat ring to the (padded) bounds."""
    x0, x1 = LON_MIN - pad, LON_MAX + pad
    y0, y1 = LAT_MIN - pad, LAT_MAX + pad

    def ix(x):
        return lambda p, q: (x, p[1] + (q[1] - p[1]) * (x - p[0]) / (q[0] - p[0]))

    def iy(y):
        return lambda p, q: (p[0] + (q[0] - p[0]) * (y - p[1]) / (q[1] - p[1]), y)

    for edge_in, inter in (
        (lambda p: p[0] >= x0, ix(x0)),
        (lambda p: p[0] <= x1, ix(x1)),
        (lambda p: p[1] >= y0, iy(y0)),
        (lambda p: p[1] <= y1, iy(y1)),
    ):
        if not ring:
            break
        ring = clip(ring, edge_in, inter)
    return ring


def simplify(pts, tol=0.6):
    """Drop points closer than tol px to the last kept one."""
    out = [pts[0]]
    for p in pts[1:]:
        if abs(p[0] - out[-1][0]) + abs(p[1] - out[-1][1]) >= tol:
            out.append(p)
    return out


def ring_path(pts):
    d = 'M' + ' '.join('%.1f %.1f' % p for p in pts)
    return d.replace('.0 ', ' ').replace('.0M', 'M') + 'Z'


def main(src, dest):
    topo = json.load(open(src))
    arcs = decode_arcs(topo)
    parts = []
    for geom in topo['objects']['land']['geometries']:
        polys = geom['arcs'] if geom['type'] == 'MultiPolygon' else [geom['arcs']]
        for poly in polys:
            for ring_ids in poly:
                ring = ring_from(ring_ids, arcs)
                # Rings that wrap across the antimeridian (Eurasia via
                # Chukotka, Fiji) would be joined by a line across the whole
                # map; unwrap their far-western points past +180 instead,
                # where clipping then drops them.
                if any(abs(q[0] - p[0]) > 180 for p, q in zip(ring, ring[1:])):
                    ring = [(x + 360 if x < -100 else x, y) for x, y in ring]
                ring = clip_to_bounds(ring)
                if len(ring) < 3:
                    continue
                pts = simplify([project(*p) for p in ring])
                if len(pts) >= 3:
                    parts.append(ring_path(pts))
    land = ''.join(parts)

    # Water lining: wide coastline strokes alternating line/paper colour,
    # drawn widest first; the land drawn on top hides the inland half.
    lining = []
    for i, w in enumerate((22, 16, 11, 7, 4)):
        lining.append(
            '<use href="#land" fill="none" stroke="%s" stroke-width="%d" '
            'stroke-linejoin="round" stroke-opacity="%.2f"/>'
            % (WATER_LINE, w, 0.18 + 0.1 * i))
        lining.append(
            '<use href="#land" fill="none" stroke="%s" stroke-width="%.1f" '
            'stroke-linejoin="round"/>' % (PAPER, w - 1.2))

    grid = []
    for lon in range(-60, 151, 20):
        x = (lon - LON_MIN) * SCALE
        grid.append('M%.0f 0V%.0f' % (x, HEIGHT))
    for lat in range(-40, 61, 20):
        y = (LAT_MAX - lat) * SCALE
        grid.append('M0 %.0fH%.0f' % (y, WIDTH))

    svg = f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {WIDTH:.0f} {HEIGHT:.0f}" width="{WIDTH:.0f}" height="{HEIGHT:.0f}">
<!-- Generated by eiw_core/scripts/build_voyage_basemap.py from Natural Earth land (public domain). Equirectangular, lon {LON_MIN:g}..{LON_MAX:g}, lat {LAT_MIN:g}..{LAT_MAX:g}, {SCALE:g}px/deg. -->
<defs>
<path id="land" d="{land}"/>
<pattern id="hatch" width="3" height="3" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
<rect width="3" height="3" fill="{PAPER}"/><line x1="0" y1="0" x2="0" y2="3" stroke="{HATCH}" stroke-width=".9"/>
</pattern>
</defs>
<rect width="100%" height="100%" fill="{PAPER}"/>
<path d="{''.join(grid)}" stroke="{GRID}" stroke-width=".6" stroke-dasharray="2 3" fill="none"/>
{''.join(lining)}
<use href="#land" fill="url(#hatch)" stroke="{INK}" stroke-width=".7" stroke-linejoin="round"/>
<rect x=".75" y=".75" width="{WIDTH - 1.5:.1f}" height="{HEIGHT - 1.5:.1f}" fill="none" stroke="{INK}" stroke-width="1.5"/>
<rect x="5" y="5" width="{WIDTH - 10:.0f}" height="{HEIGHT - 10:.0f}" fill="none" stroke="{INK}" stroke-width=".5"/>
</svg>
'''
    with open(dest, 'w') as f:
        f.write(svg)
    print('%s: %d rings, %d bytes' % (dest, len(parts), len(svg)))


if __name__ == '__main__':
    main(*sys.argv[1:3])

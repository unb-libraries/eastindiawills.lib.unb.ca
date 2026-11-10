#!/usr/bin/env python3
"""Rasterise the voyage base map's land outline into a land/sea bitmask.

VoyageMapBuilder uses the mask to steer routes around land. It reads the
"land" path from assets/voyage-map-base.svg (map units: 5 per degree,
1150 x 530) and writes assets/voyage-map-land.bin: one bit per map unit,
row by row from the top, most significant bit first; 1 = land (even-odd fill,
sampled at each cell's centre).

Re-run whenever the base map is rebuilt:
  python3 build_voyage_landmask.py
"""
import os
import re

HERE = os.path.dirname(os.path.abspath(__file__))
SVG = os.path.join(HERE, '..', 'assets', 'voyage-map-base.svg')
OUT = os.path.join(HERE, '..', 'assets', 'voyage-map-land.bin')
WIDTH, HEIGHT = 1150, 530


def rings(svg):
    d = re.search(r'<path id="land" d="([^"]*)"', svg).group(1)
    for ring in d.split('M'):
        nums = [float(n) for n in re.split(r'[\s,]+', ring.replace('Z', '').strip()) if n]
        if len(nums) >= 6:
            yield list(zip(nums[0::2], nums[1::2]))


def main():
    edges = []
    for ring in rings(open(SVG, encoding='utf-8').read()):
        for (x1, y1), (x2, y2) in zip(ring, ring[1:] + ring[:1]):
            if y1 != y2:
                edges.append((x1, y1, x2, y2))
    # Bucket edges by the rows they span, for a fast scanline fill.
    rows = [[] for _ in range(HEIGHT)]
    for e in edges:
        lo, hi = sorted((e[1], e[3]))
        for r in range(max(0, int(lo - 0.5)), min(HEIGHT, int(hi + 0.5) + 1)):
            rows[r].append(e)
    bits = bytearray((WIDTH * HEIGHT + 7) // 8)
    land = 0
    for r in range(HEIGHT):
        y = r + 0.5
        xs = sorted(x1 + (y - y1) * (x2 - x1) / (y2 - y1)
                    for x1, y1, x2, y2 in rows[r] if (y1 > y) != (y2 > y))
        for a, b in zip(xs[0::2], xs[1::2]):
            for c in range(max(0, int(a + 0.5)), min(WIDTH, int(b + 0.5))):
                i = r * WIDTH + c
                bits[i >> 3] |= 0x80 >> (i & 7)
                land += 1
    with open(OUT, 'wb') as fh:
        fh.write(bits)
    print(f'{OUT}: {len(bits)} bytes, {land / (WIDTH * HEIGHT):.0%} land')


if __name__ == '__main__':
    main()

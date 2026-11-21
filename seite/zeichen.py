#!/usr/bin/env python3
"""zeichen.py – Projektzeichen „Rede mit Meinungsmache“ (Logo-Familie „Rede mit …“: Sprechblase = Chat mit einer
Quelle, innen das Projekt; hier eine Zeitungsseite). Schreibt favicon.svg und die PNG-Größen nach server/web/."""
import asyncio
from pathlib import Path

BLASE = 'M7.5 3.5h9a4 4 0 0 1 4 4v6a4 4 0 0 1-4 4h-5.5l-4.5 3.5v-3.5a4 4 0 0 1-4-4v-6a4 4 0 0 1 4-4z'
ZEITUNG = 'M7.7 7.4h3.6v3.2H7.7zM13.3 7.6h3M13.3 10.4h3M7.7 13.3h8.6'
FARBE = '#C5A46D'   # Kennfarbe von meinungsmache.de
LINIE = '#1b1f27'   # dunkle Linien: weiße erreichen auf dieser hellen Fläche nur 2,4 : 1, dunkle 7 : 1
ZIEL = Path(__file__).resolve().parent.parent / 'server' / 'web'


def kachel():
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24" rx="5.5" fill="{FARBE}"/>'
            f'<g fill="none" stroke="{LINIE}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" '
            f'transform="translate(1.2 1) scale(.9)"><path d="{BLASE}"/><path d="{ZEITUNG}"/></g></svg>')


def linie():
    """Strichfassung für die Seite (Farbe aus currentColor)."""
    return f'<path d="{BLASE}"/><path d="{ZEITUNG}"/>'


async def pngs():
    from playwright.async_api import async_playwright
    async with async_playwright() as p:
        b = await p.chromium.launch()
        for name, px, rand in (('favicon-32.png', 32, 0), ('icon-192.png', 192, 0), ('icon-512.png', 512, 0),
                               ('apple-touch-icon.png', 180, 0)):
            pg = await b.new_page(viewport={'width': px, 'height': px})
            svg = kachel().replace('<svg ', f'<svg width="{px}" height="{px}" ', 1)
            if name == 'apple-touch-icon.png':   # iOS rundet selbst ab: volle Fläche
                svg = svg.replace('rx="5.5"', 'rx="0"')
            await pg.set_content(f'<html><body style="margin:0;background:transparent">{svg}</body></html>')
            await pg.screenshot(path=str(ZIEL / name), omit_background=True)
            await pg.close()
        await b.close()


if __name__ == '__main__':
    ZIEL.mkdir(parents=True, exist_ok=True)
    (ZIEL / 'favicon.svg').write_text(kachel() + '\n', encoding='utf-8')
    asyncio.run(pngs())
    print('geschrieben:', ZIEL)

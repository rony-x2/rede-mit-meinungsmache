#!/usr/bin/env python3
"""
vorschaubild.py – Vorschaubild für Open Graph (1200 × 630 px), wenn die Seite geteilt wird.

  python3 vorschaubild.py            schreibt server/web/og.png (braucht Playwright mit Chromium)

Das Bild wird als kleine HTML-Seite gesetzt und mit Chromium abfotografiert: Projektzeichen, Titel,
Untertitel und Adresse, im dunklen Farbschema der Seite.
"""
import asyncio
from pathlib import Path

from playwright.async_api import async_playwright

HIER = Path(__file__).parent
from zeichen import kachel

ZEICHEN = kachel().replace('<svg ', '<svg width="168" height="168" ', 1)

HTML = f'''<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><style>
html, body {{ margin: 0; width: 1200px; height: 630px; }}
body {{ box-sizing: border-box; padding: 72px 84px; display: flex; flex-direction: column; justify-content: space-between;
  font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #e6e8ec;
  background: radial-gradient(90% 120% at 0% 0%, #2a2114 0%, #0f1115 55%), #0f1115; }}
.kopf {{ display: flex; align-items: center; gap: 44px; }}
.kicker {{ margin: 0 0 14px; font-size: 24px; font-weight: 600; letter-spacing: .1em; text-transform: uppercase; color: #C5A46D; }}
h1 {{ margin: 0; font-size: 80px; line-height: 1.02; letter-spacing: -.01em; }}
p.unter {{ margin: 0; max-width: 980px; font-size: 32px; line-height: 1.35; color: #b4bcc8; }}
.fuss {{ display: flex; justify-content: space-between; align-items: center; font-size: 26px; color: #9aa3b2; }}
.fuss code {{ font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; color: #e6e8ec; }}
.pillen span {{ margin-left: 10px; padding: 6px 16px; border: 1.5px solid #2c3340; border-radius: 999px; font-size: 22px; }}
</style></head><body>
<div class="kopf">{ZEICHEN}<div><p class="kicker">Proof of Concept</p><h1>Rede mit<br>Meinungsmache</h1></div></div>
<p class="unter">Die Nachrichten von meinungsmache.de im eigenen KI&#8209;Assistenten befragen – mit Morgen- und Abendbriefing und Links zum Beitrag und zur Originalquelle.</p>
<div class="fuss"><code>poc.deutschkaemer.info/meinungsmache</code><div class="pillen"><span>stündlich aktuell</span><span>seit Dez. 2025</span></div></div>
</body></html>'''


async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch()
        pg = await b.new_page(viewport={'width': 1200, 'height': 630})
        await pg.set_content(HTML)
        await pg.screenshot(path=str(HIER.parent / 'server' / 'web' / 'og.png'))
        await b.close()
    try:  # verlustfrei kleiner speichern
        from PIL import Image
        Image.open(HIER.parent / 'server' / 'web' / 'og.png').save(HIER.parent / 'server' / 'web' / 'og.png', optimize=True)
    except ImportError:
        pass


asyncio.run(main())
print('geschrieben:', HIER.parent / 'server' / 'web' / 'og.png')

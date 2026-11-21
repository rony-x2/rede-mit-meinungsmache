#!/usr/bin/env python3
"""
diagramm.py – Ablaufdiagramm „Wie es gemacht ist“ als SVG, aus einer Quelle für Projektseite und GitHub.

  python3 diagramm.py            schreibt docs/bilder/ablauf-hell.svg und ablauf-dunkel.svg (README)

bauen.py bettet über diagramm_seite() beide Fassungen (quer, hoch) inline in die Projektseite ein; dort kommen
die Farben aus den CSS-Variablen der Seite (hell/dunkel, Display P3), im README aus festen Farben.
Aufbau der Zeichenhilfe wie im Schwesterprojekt rede-mit-dem-afd-gutachten.
"""
from html import escape
from pathlib import Path

# ------------------------------------------------------------------ Inhalt
KASTEN = {
    'feed':    ('RSS-Feed', 'neue und geänderte Artikel', None),
    'sitemap': ('Sitemap', 'Archiv seit Dezember 2025', None),
    'abruf':   ('Abruf', 'Artikelseite lesen und zerlegen', 'abruf.php · quelle.php'),
    'artikel': ('Artikel', 'Text, Quelle, Autor, Links', None),
    'vektor':  ('Vektoren', 'Qwen3-Embedding-0.6B', None),
    'anr':     ('Anreicherung', 'Fragen, Suchbegriffe, Themen', 'Gemma 4 · mm_anreichern.py'),
    'assist':  ('KI-Assistent', 'Claude, ChatGPT, Grok, LM Studio', None),
    'server':  ('MCP-Schnittstelle', '9 Werkzeuge, Briefing', 'mcp_server.php'),
    'einbett': ('Einbettung der Frage', 'Qwen3 auf der CPU', None),
}
DB = ('MariaDB', 'Artikel · Links', 'Volltext · Vektoren')
BAHN_A = 'Stündlich: Abruf von meinungsmache.de'
BAHN_B = 'Bei jeder Frage'
TITEL = 'Aufbau von „Rede mit Meinungsmache“'
BESCHREIBUNG = ('Stündlich prüft ein Abruf den RSS-Feed von meinungsmache.de, für das Archiv einmalig die Sitemap. '
                'Neue und geänderte Artikel werden von ihrer Seite gelesen und in Titel, Dachzeile, Text, Quelle, Autor '
                'und Links zerlegt. Ein lokales Sprachmodell schreibt zu jedem Artikel Fragen, Suchbegriffe und Themen; '
                'ein lokales Einbettungsmodell berechnet Vektoren für Text und Fragen. Alles landet in MariaDB. Bei jeder '
                'Frage ruft ein KI-Assistent die MCP-Schnittstelle auf; sie sucht in der Datenbank und liefert Artikel '
                'mit Links zu meinungsmache.de und zur Originalquelle zurück.')

# ------------------------------------------------------------------ Farben
STIL_KLASSEN = '''
.d-bahn{fill:%(bahn)s;stroke:%(linie)s;stroke-dasharray:4 4}
.d-bahntitel{fill:%(leise)s;font-size:11.5px;font-weight:600;letter-spacing:.09em;text-transform:uppercase}
.d-kasten{fill:%(flaeche)s;stroke:%(linie)s;stroke-width:1.2}
.d-t{fill:%(text)s;font-size:15px;font-weight:600}
.d-d{fill:%(leise)s;font-size:12.5px}
.d-f{fill:%(projekt)s;font-size:11.5px;font-family:%(mono)s}
.d-p{fill:none;stroke:%(leise)s;stroke-width:1.4;stroke-linejoin:round}
.d-p.b{stroke:%(akzent)s;stroke-width:1.6}
.d-s{fill:%(leise)s}.d-s.b{fill:%(akzent)s}
.d-l{fill:%(leise)s;font-size:11.5px}.d-l.b{fill:%(akzenttext)s}
.d-chip{fill:%(flaeche)s;stroke:%(akzent)s;stroke-width:1.1}
.d-chiptext{fill:%(akzenttext)s;font-size:10.5px;font-weight:600;letter-spacing:.02em}
.d-hinweis{fill:%(akzenttext)s;font-size:11.5px;font-weight:600}
'''
SCHRIFT = '-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans",Helvetica,Arial,sans-serif'
MONO = 'ui-monospace,SFMono-Regular,"Cascadia Mono",Consolas,monospace'
THEMEN = {
    'seite': dict(bahn='color-mix(in oklab,var(--flaeche) 40%,transparent)', linie='var(--linie)', leise='var(--text-2)',
                  flaeche='var(--flaeche)', text='var(--text)', projekt='var(--projekt)', akzent='var(--akzent)', akzenttext='var(--akzent-text, var(--akzent))',
                  mono='var(--mono)'),
    'hell':  dict(bahn='#f6f8fa', linie='#d1d9e0', leise='#59636e', flaeche='#ffffff', text='#1f2328',
                  projekt='#8f5a0e', akzent='#2f8a7b', akzenttext='#23756a', mono=MONO),
    'dunkel': dict(bahn='#151b23', linie='#3d444d', leise='#9198a1', flaeche='#0d1117', text='#e6edf3',
                   projekt='#e0a94f', akzent='#5eb8a8', akzenttext='#5eb8a8', mono=MONO),
}

H = 70  # Höhe eines Kastens


class Zeichnung:
    def __init__(self, praefix):
        self.teile = []
        self.p = praefix

    def bahn(self, x, y, w, h, titel):
        self.teile.append(f'<rect class="d-bahn" x="{x}" y="{y}" width="{w}" height="{h}" rx="14"/>'
                          f'<text class="d-bahntitel" x="{x + 16}" y="{y + 22}">{escape(titel)}</text>')

    def kasten(self, schluessel, x, y, w):
        t, d, f = KASTEN[schluessel] if isinstance(schluessel, str) else schluessel
        breite = max(len(t) * 15 * .6, len(d) * 12.5 * .53, len(f or '') * 11.5 * .62)
        assert breite <= w - 16, f'{schluessel}: Text zu breit ({breite:.0f} > {w - 16})'
        cx = x + w / 2
        zeilen = [(t, 'd-t'), (d, 'd-d')] + ([(f, 'd-f')] if f else [])
        y0 = y + (24 if f else 31)
        s = [f'<rect class="d-kasten" x="{x}" y="{y}" width="{w}" height="{H}" rx="10"/>']
        for i, (txt, k) in enumerate(zeilen):
            s.append(f'<text class="{k}" x="{cx:g}" y="{y0 + i * 18:g}" text-anchor="middle">{escape(txt)}</text>')
        self.teile.append(''.join(s))

    def chip(self, x, y, w, txt='lokale KI'):
        """Kleines Schild oben rechts auf einem Kasten (x, y, w: Lage des Kastens)."""
        b = len(txt) * 6.2 + 16
        cx = x + w - 12 - b
        self.teile.append(f'<rect class="d-chip" x="{cx:g}" y="{y - 9}" width="{b:g}" height="18" rx="9"/>'
                          f'<text class="d-chiptext" x="{cx + b / 2:g}" y="{y + 4}" text-anchor="middle">{escape(txt)}</text>')

    def hinweis(self, x, y, txt, anker='end'):
        self.teile.append(f'<text class="d-hinweis" x="{x}" y="{y}" text-anchor="{anker}">{escape(txt)}</text>')

    def zylinder(self, x, y, w, h):
        ry = 13
        cx = x + w / 2
        s = [f'<path class="d-kasten" d="M{x},{y + ry} v{h - 2 * ry} a{w / 2:g},{ry} 0 0 0 {w},0 v{-(h - 2 * ry)}"/>',
             f'<ellipse class="d-kasten" cx="{cx:g}" cy="{y + ry}" rx="{w / 2:g}" ry="{ry}"/>']
        mitte = y + ry + (h - ry) / 2
        for i, (txt, k) in enumerate(zip(DB, ('d-t', 'd-d', 'd-d'))):
            s.append(f'<text class="{k}" x="{cx:g}" y="{mitte - 12 + i * 18:g}" text-anchor="middle">{escape(txt)}</text>')
        self.teile.append(''.join(s))

    def pfeil(self, punkte, betrieb=False, beide=False, spitze=True):
        k = 'd-p b' if betrieb else 'd-p'
        m = f'{self.p}-sb' if betrieb else f'{self.p}-s'
        pts = ' '.join(f'{x:g},{y:g}' for x, y in punkte)
        attr = (f' marker-end="url(#{m})"' if spitze else '') + (f' marker-start="url(#{m})"' if beide else '')
        self.teile.append(f'<polyline class="{k}" points="{pts}"{attr}/>')

    def text(self, x, y, txt, betrieb=False, anker='middle'):
        k = 'd-l b' if betrieb else 'd-l'
        self.teile.append(f'<text class="{k}" x="{x}" y="{y}" text-anchor="{anker}">{escape(txt)}</text>')

    def svg(self, w, h, thema, klasse='', gross=False):
        stil = STIL_KLASSEN % THEMEN[thema]
        if gross:   # Hochformat fürs Smartphone: etwas größere Schrift, damit sie verkleinert lesbar bleibt
            stil += '.d-t{font-size:15.5px}.d-d{font-size:13px}.d-f{font-size:12px}.d-l{font-size:12.5px}'
        if thema != 'seite':
            stil += 'text{font-family:%s}' % SCHRIFT
        marker = ''.join(
            f'<marker id="{self.p}-{n}" viewBox="0 0 8 8" refX="7" refY="4" markerWidth="8" markerHeight="8" '
            f'orient="auto-start-reverse"><path class="{k}" d="M0,0.6 L7.6,4 L0,7.4 z"/></marker>'
            for n, k in (('s', 'd-s'), ('sb', 'd-s b')))
        k = f' class="{klasse}"' if klasse else ''
        return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w} {h}"{k} role="img" '
                f'aria-labelledby="{self.p}-titel {self.p}-text">'
                f'<title id="{self.p}-titel">{escape(TITEL)}</title><desc id="{self.p}-text">{escape(BESCHREIBUNG)}</desc>'
                f'<style>{stil}</style><defs>{marker}</defs>' + ''.join(self.teile) + '</svg>')


def quer(thema, praefix='dq'):
    z = Zeichnung(praefix)
    z.bahn(4, 4, 856, 330, BAHN_A)
    z.kasten('feed', 20, 70, 200)
    z.kasten('sitemap', 20, 200, 200)
    z.kasten('abruf', 250, 135, 230)
    z.kasten('artikel', 510, 44, 230)
    z.kasten('anr', 510, 144, 230)
    z.kasten('vektor', 510, 244, 230)
    z.chip(510, 144, 230)
    z.chip(510, 244, 230)
    z.hinweis(844, 26, 'Sprach- und Einbettungsmodell lokal auf eigener Hardware, ohne Cloud-Dienst')
    z.zylinder(880, 200, 150, 270)
    z.pfeil([(220, 105), (235, 105), (235, 160), (248, 160)])
    z.pfeil([(220, 235), (235, 235), (235, 180), (248, 180)])
    z.pfeil([(480, 170), (495, 170), (495, 79), (508, 79)])
    z.pfeil([(625, 114), (625, 142)])
    z.pfeil([(625, 214), (625, 242)])
    z.pfeil([(740, 79), (800, 79), (800, 240), (878, 240)])
    z.pfeil([(740, 279), (800, 279), (800, 270), (878, 270)])
    z.bahn(4, 354, 856, 244, BAHN_B)
    z.kasten('assist', 20, 413, 240)
    z.kasten('server', 670, 413, 170)
    z.kasten('einbett', 655, 513, 200)
    z.chip(655, 513, 200)
    z.pfeil([(260, 438), (668, 438)], betrieb=True)
    z.pfeil([(670, 460), (262, 460)], betrieb=True)
    z.text(465, 430, 'Frage, Briefing-Wunsch · MCP über HTTPS', betrieb=True)
    z.text(465, 478, 'Artikel mit Links zu Beitrag und Original', betrieb=True)
    z.pfeil([(842, 448), (878, 448)], betrieb=True, beide=True)
    z.pfeil([(755, 483), (755, 511)], betrieb=True)
    z.text(745, 502, 'Frage', betrieb=True, anker='end')
    return z.svg(1040, 602, thema, 'd-quer')


def hoch(thema, praefix='dh'):
    z = Zeichnung(praefix)
    z.bahn(4, 4, 452, 424, BAHN_A)
    z.kasten('feed', 20, 40, 200)
    z.kasten('sitemap', 240, 40, 200)
    z.kasten('abruf', 110, 135, 240)
    z.kasten(('Artikel', 'Text, Quelle, Links', None), 20, 240, 200)
    z.kasten(('Anreicherung', 'Fragen, Themen', 'Gemma 4'), 240, 240, 200)
    z.kasten('vektor', 130, 345, 200)
    z.chip(240, 240, 200)
    z.chip(130, 345, 200)
    z.hinweis(440, 24, 'KI-Modelle lokal')
    z.zylinder(150, 440, 160, 104)
    z.pfeil([(120, 110), (120, 122), (230, 122), (230, 133)])
    z.pfeil([(340, 110), (340, 122), (230, 122)], spitze=False)
    z.pfeil([(230, 205), (230, 222), (120, 222), (120, 238)])
    z.pfeil([(220, 275), (238, 275)])
    z.pfeil([(120, 310), (120, 327), (230, 327), (230, 343)])
    z.pfeil([(340, 310), (340, 327), (230, 327)], spitze=False)
    z.pfeil([(230, 415), (230, 438)])
    z.pfeil([(20, 275), (12, 275), (12, 500), (148, 500)])
    z.bahn(4, 560, 452, 248, BAHN_B)
    z.kasten('server', 20, 600, 200)
    z.kasten('einbett', 240, 600, 200)
    z.chip(240, 600, 200)
    z.kasten('assist', 20, 722, 300)
    z.pfeil([(190, 598), (190, 546)], betrieb=True, beide=True)
    z.pfeil([(220, 635), (238, 635)], betrieb=True)
    z.pfeil([(90, 720), (90, 672)], betrieb=True)
    z.pfeil([(130, 672), (130, 720)], betrieb=True)
    z.text(142, 700, 'MCP über HTTPS', betrieb=True, anker='start')
    return z.svg(460, 812, thema, 'd-hoch', gross=True)


def diagramm_seite():
    """Beide Fassungen für die Projektseite; CSS zeigt je nach Breite eine davon."""
    return quer('seite') + hoch('seite')


if __name__ == '__main__':
    ziel = Path(__file__).resolve().parent.parent / 'docs' / 'bilder'
    ziel.mkdir(parents=True, exist_ok=True)
    for thema in ('hell', 'dunkel'):
        (ziel / f'ablauf-{thema}.svg').write_text(quer(thema) + '\n', encoding='utf-8')
    print('geschrieben:', ziel)

#!/usr/bin/env python3
"""
bauen.py – baut index.html der Projektseite aus vorlage.html: Logos inline, Piktogramme, Beispiel-Unterhaltung,
Diagramm und strukturierte Daten.

  python3 bauen.py               liest den aktuellen Stand (Kennzahlen, neueste Artikel) von der Schnittstelle

Kennzahlen und „Zuletzt erschienen“ stehen damit schon im HTML (für Suchmaschinen und ohne JavaScript);
seite.js aktualisiert sie beim Aufruf aus stand.php.
"""
import html, json, re, sys, urllib.request
from datetime import date
from pathlib import Path

from diagramm import diagramm_seite, suche_seite, probelauf_html
from zeichen import BLASE, ZEITUNG

HIER = Path(__file__).parent
LOGOS = HIER / 'logos'
SEITE = 'https://poc.deutschkaemer.info/meinungsmache/'
ADRESSE = SEITE + 'mcp'
GITHUB = 'https://github.com/rony-x2/rede-mit-meinungsmache'
V_CSS, V_JS = 4, 1
BESCHREIBUNG = ('Die Nachrichten von meinungsmache.de mit Claude, ChatGPT, Grok oder einem lokalen Modell befragen: '
                'MCP-Schnittstelle mit stündlich aktualisierten Artikeln, Morgen- und Abendbriefing, Antworten mit Link '
                'zum Beitrag und zur Originalquelle.')

IKONEN = {
    'frage': '<path d="M4 5.5h16v10.5H10l-5 4v-4H4z"/><path d="M8.5 10.8h.01M12 10.8h.01M15.5 10.8h.01"/>',
    'assistent': '<path d="M11 3.5l1.9 5.1 5.1 1.9-5.1 1.9L11 17.5l-1.9-5.1L4 10.5l5.1-1.9z"/><path d="M18 14.5l.8 1.9 1.9.8-1.9.8-.8 1.9-.8-1.9-1.9-.8 1.9-.8z"/>',
    'zeitung': '<path d="M5 4.5h12v14a2 2 0 0 0 2 2H7a2 2 0 0 1-2-2z"/><path d="M17 8.5h2.5v10a2 2 0 0 1-2 2"/><path d="M8 8h6v3.5H8zM8 14.5h6M8 17.3h6"/>',
    'beleg': '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    'hinweis': '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.6v.2"/>',
    'pruefen': '<circle cx="10.5" cy="10.5" r="6.5"/><path d="M15.4 15.4L21 21"/><path d="M7.8 10.6l2 2 3.5-3.7"/>',
    'post': '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.8 6.5L12 12.8l8.2-6.3"/>',
    'code': '<path d="M8 7l-5 5 5 5M16 7l5 5-5 5M13.5 4.5l-3 15"/>',
    'werkzeug': '<path d="M14.7 6.3a4 4 0 0 0 5 5l-8.9 8.9a2.1 2.1 0 0 1-3-3z"/><path d="M14.7 6.3l2.8-2.8a4 4 0 0 1 3 3l-2.8 2.8"/>',
    'menue': '<path d="M4 7h16M4 12h16M4 17h16"/>',
    'schliessen': '<path d="M6 6l12 12M18 6L6 18"/>',
    'haken': '<circle cx="12" cy="12" r="9"/><path d="M8 12.4l2.8 2.8L16.2 9.6"/>',
    # Briefing: Sonnenaufgang, Mond, Kalender, Uhr
    'morgen': '<path d="M3 18h18M7.5 18a4.5 4.5 0 0 1 9 0"/><path d="M12 5v3M5.6 9.6l2 2M18.4 9.6l-2 2M3.5 14.5h2M18.5 14.5h2"/>',
    'abend': '<path d="M19.5 14.5A8 8 0 0 1 9.5 4.5a8 8 0 1 0 10 10z"/>',
    'tag': '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4M8 14h2M14 14h2M8 17h2"/>',
    'uhr': '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    # Projektzeichen „Rede mit Meinungsmache“: Sprechblase mit Zeitungsseite (Logo-Familie, siehe zeichen.py)
    'projekt': f'<path d="{BLASE}"/><path d="{ZEITUNG}"/>',
}


def ikon(name, klasse='ikon'):
    return (f'<svg class="{klasse}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" '
            f'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{IKONEN[name]}</svg>')


def logo(datei, klasse='logo'):
    svg = (LOGOS / datei).read_text(encoding='utf-8')
    assert not re.search(r'<script|\son\w+=', svg, re.I)
    svg = re.sub(r'<\?xml.*?\?>', '', svg, flags=re.S)
    svg = re.sub(r'<title>.*?</title>', '', svg, flags=re.S)
    svg = re.sub(r'\s(?:class|data-name)="[^"]*"', '', svg)
    benutzt = set(re.findall(r'url\(#([^)]+)\)', svg))
    svg = re.sub(r'\sid="([^"]*)"', lambda m: m[0] if m[1] in benutzt else '', svg)
    if not re.search(r'<svg[^>]*\sfill=', svg):
        svg = svg.replace('<svg', '<svg fill="currentColor"', 1)
    svg = svg.replace('<svg', f'<svg class="{klasse}" aria-hidden="true" focusable="false"', 1)
    return re.sub(r'\s*\n\s*', ' ', svg.strip())


h = lambda s: html.escape(s, quote=True)


def werkzeug(name, args):
    """Ruft ein Werkzeug der Schnittstelle auf (für den Stand beim Bauen)."""
    anfrage = json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call',
                          'params': {'name': name, 'arguments': args}}).encode()
    req = urllib.request.Request(ADRESSE, anfrage, {'Content-Type': 'application/json',
                                                    'Accept': 'application/json, text/event-stream'})
    with urllib.request.urlopen(req, timeout=30) as r:
        antwort = json.load(r)
    return json.loads(antwort['result']['content'][0]['text'])


def zahl(n):
    return f'{n:,}'.replace(',', '.')


def zeit(t):
    m = re.match(r'(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2})', t or '')
    return f'{m[3]}.{m[2]}.{m[1]}, {m[4]} Uhr' if m else (t or '')


# Antworten über die Schnittstelle (abgefragt am 21.11.2026), gekürzt. Links zeigen auf den Beitrag bei meinungsmache.de.
STAND_BEISPIELE = 'Stand 21. November 2026'
GESPRAECHE = []   # wird unten aus beispiele.json gelesen
BEISPIELE = [
    'Mach mir ein Morgenbriefing.',
    'Was war heute los? Bitte nach Themen geordnet.',
    'Was schreibt Meinungsmache über das Bürgergeld?',
    'Welche eigenen Artikel hat die Redaktion diese Woche veröffentlicht?',
    'Aus welchen Medien stammen die Meldungen am häufigsten?',
    'Fass mir alles zur Wahl in Serbien zusammen, mit Links zu den Originalen.',
    'Was gab es seit Montag zum Klimaschutz?',
    'Zeig mir die letzten Meldungen aus der Tagesschau.',
]


def gespraech(n, g):
    erst = n == 0
    return f'''          <div class="g{' aktiv' if erst else ''}"{'' if erst else ' aria-hidden="true"'}>
            <div class="blase frage"><span class="frage-text" data-text="{h(g['frage'])}">{h(g['frage'])}</span></div>
            <div class="werkzeug-zeile">{ikon('zeitung')}<span class="nw">sieht bei Meinungsmache nach</span><code>{h(g['werkzeug'])}</code><span class="tippen"><i></i><i></i><i></i></span></div>
            <div class="antwort-zeile">
              <span class="avatar">{ikon('assistent')}</span>
              <div class="blase antwort">{h(g['antwort'])}<br><a class="beleg" href="{h(g['beleg'][1])}"{'' if erst else ' tabindex="-1"'}>{ikon('beleg')}{h(g['beleg'][0])}</a></div>
            </div>
          </div>'''


def neueste_html(treffer):
    zeilen = []
    for a in treffer:
        von = a.get('autor') if a['art'] == 'eigen' else a.get('quelle')
        art = 'eigener Artikel' if a['art'] == 'eigen' else 'Meldung'
        zeilen.append(f'        <li><a href="{h(a["url"])}">{h(a["titel"])}</a><span class="n-meta"><span class="n-art">{art}</span>'
                      f'<span>{h(von or "")}</span><time datetime="{h(a["zeit"].replace(" ", "T"))}">{h(zeit(a["zeit"]))}</time></span></li>')
    return '\n'.join(zeilen)


def schema(stand):
    """Strukturierte Daten nach schema.org (JSON-LD) für die Projektseite."""
    person = {'@type': 'Person', '@id': 'https://deutschkaemer.info/#person', 'name': 'Dennis Deutschkämer',
              'url': 'https://deutschkaemer.info'}
    quelle = {'@type': 'NewsMediaOrganization', '@id': 'https://meinungsmache.de/#organisation', 'name': 'Meinungsmache',
              'url': 'https://meinungsmache.de'}
    daten_ = {'@type': 'Dataset', '@id': SEITE + '#artikel', 'name': 'Artikel von meinungsmache.de (aufbereitet)',
              'description': 'Meldungen und eigene Artikel von meinungsmache.de seit Dezember 2025, stündlich abgerufen '
                             'und für die Suche aufbereitet; nur über die MCP-Schnittstelle zugänglich.',
              'creator': {'@id': quelle['@id']}, 'isBasedOn': 'https://meinungsmache.de/rss/all',
              'temporalCoverage': '2025-12/..', 'inLanguage': 'de', 'isAccessibleForFree': True}
    schnittstelle = {'@type': ['SoftwareApplication', 'WebAPI'], '@id': SEITE + '#schnittstelle',
                     'name': 'Rede mit Meinungsmache (MCP-Schnittstelle)', 'url': ADRESSE,
                     'description': 'MCP-Server (Model Context Protocol, Streamable HTTP) mit Werkzeugen zum Suchen und '
                                    'Lesen der Artikel und für Morgen- und Abendbriefings; nur lesend, ohne Anmeldung.',
                     'applicationCategory': 'ReferenceApplication', 'operatingSystem': 'Web',
                     'isAccessibleForFree': True, 'inLanguage': 'de',
                     'offers': {'@type': 'Offer', 'price': '0', 'priceCurrency': 'EUR'},
                     'documentation': GITHUB + '/blob/main/docs/werkzeuge.md',
                     'isBasedOn': {'@id': daten_['@id']}, 'author': {'@id': person['@id']},
                     'provider': {'@id': person['@id']}}
    quelltext = {'@type': 'SoftwareSourceCode', '@id': SEITE + '#quelltext', 'name': 'rede-mit-meinungsmache',
                 'codeRepository': GITHUB, 'programmingLanguage': ['PHP', 'Python', 'JavaScript'],
                 'license': 'https://opensource.org/license/mit', 'author': {'@id': person['@id']},
                 'targetProduct': {'@id': schnittstelle['@id']}}
    seite = {'@type': 'WebPage', '@id': SEITE + '#seite', 'url': SEITE, 'name': 'Rede mit Meinungsmache',
             'description': BESCHREIBUNG, 'inLanguage': 'de', 'dateModified': date.today().isoformat(),
             'isPartOf': {'@type': 'WebSite', '@id': 'https://poc.deutschkaemer.info/#website',
                          'name': 'Proof of Concepts – Dennis Deutschkämer', 'url': 'https://poc.deutschkaemer.info/',
                          'author': {'@id': person['@id']}},
             'about': {'@id': quelle['@id']}, 'mainEntity': {'@id': schnittstelle['@id']},
             'author': {'@id': person['@id']}, 'primaryImageOfPage': SEITE + 'og.png',
             'breadcrumb': {'@type': 'BreadcrumbList', 'itemListElement': [
                 {'@type': 'ListItem', 'position': 1, 'name': 'Proof of Concepts', 'item': 'https://poc.deutschkaemer.info/'},
                 {'@type': 'ListItem', 'position': 2, 'name': 'Rede mit Meinungsmache', 'item': SEITE}]}}
    graph = {'@context': 'https://schema.org', '@graph': [seite, schnittstelle, daten_, quelltext, quelle, person]}
    roh = json.dumps(graph, ensure_ascii=False, indent=1).replace('</', '<\\/')
    return '  <script type="application/ld+json">\n' + roh + '\n  </script>'


if __name__ == '__main__':
    GESPRAECHE = json.loads((HIER / 'beispiele.json').read_text(encoding='utf-8'))
    ue = werkzeug('ueberblick', {})
    neu = werkzeug('neueste', {'anzahl': 6})['treffer']
    st = ue['stand']
    text = (HIER / 'vorlage.html').read_text(encoding='utf-8')
    ersetzen = {
        'GESPRAECHE': '\n'.join(gespraech(n, g) for n, g in enumerate(GESPRAECHE)),
        'PUNKTE': '\n'.join(f'            <button class="g-punkt" type="button" aria-label="Beispiel {n + 1}" '
                            f'aria-current="{"true" if n == 0 else "false"}"></button>' for n in range(len(GESPRAECHE))),
        'BEISPIELE': '\n'.join(f'          <li>{ikon("frage")}<span>{h(b)}</span></li>' for b in BEISPIELE),
        'STAND_BEISPIELE': STAND_BEISPIELE,
        'NEUESTE': neueste_html(neu),
        'SCHEMA': schema(st), 'BESCHREIBUNG': h(BESCHREIBUNG), 'ADRESSE': ADRESSE, 'GITHUB': GITHUB,
        'DIAGRAMM': diagramm_seite(), 'SUCHE': suche_seite(), 'PROBELAUF': probelauf_html(), 'V_CSS': str(V_CSS), 'V_JS': str(V_JS),
        'Z_ARTIKEL': zahl(st['artikel']), 'Z_MELDUNGEN': zahl(ue['meldungen']), 'Z_EIGENE': zahl(ue['eigene_artikel']),
        'Z_QUELLEN': zahl(len(werkzeug('quellen', {})['quellen'])),
        'Z_ABRUF': h(zeit(st['letzter_abruf'] or st['bis'])), 'Z_BIS': h(zeit(st['bis'])),
    }
    for k, v in ersetzen.items():
        text = text.replace('{{' + k + '}}', v)
    text = re.sub(r'\{\{logo:([^:}]+)(?::([^}]+))?\}\}', lambda m: logo(m[1], m[2] or 'logo'), text)
    text = re.sub(r'\{\{ikon:([^:}]+)(?::([^}]+))?\}\}', lambda m: ikon(m[1], m[2] or 'ikon'), text)
    assert '{{' not in text, re.findall(r'\{\{[^}]*\}\}', text)
    assert '—' not in text, 'Geviertstrich gefunden'
    (HIER / 'index.html').write_text(text, encoding='utf-8')
    print('ok', len(text), st)

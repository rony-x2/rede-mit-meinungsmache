#!/usr/bin/env python3
"""messen.py – misst bedeutung_suchen mit dem Testsatz über die öffentliche Schnittstelle.

  python3 messen.py [Bezeichnung]     schreibt ergebnis-<Bezeichnung>.json und gibt die Kennzahlen aus

Je Frage zählt der beste Platz eines als passend markierten Artikels (testsatz.json, von Hand festgelegt).
"""
import json, sys, urllib.request
from pathlib import Path

HIER = Path(__file__).parent
U = 'https://poc.deutschkaemer.info/meinungsmache/mcp'


def suche(frage):
    r = urllib.request.Request(U, json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'tools/call', 'params': {
        'name': 'bedeutung_suchen', 'arguments': {'frage': frage, 'anzahl': 30}}}).encode(),
        {'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream'})
    return [t['id'] for t in json.loads(json.load(urllib.request.urlopen(r, timeout=60))['result']['content'][0]['text'])['treffer']]


def main():
    name = sys.argv[1] if len(sys.argv) > 1 else 'stand'
    satz = json.loads((HIER / 'testsatz.json').read_text(encoding='utf-8'))
    zeilen = []
    for t in satz:
        ids = suche(t['frage'])
        platz = next((i + 1 for i, x in enumerate(ids) if x in t['treffer']), None)
        zeilen.append({'frage': t['frage'], 'platz': platz, 'top5': ids[:5]})
        print(f"{platz or '–':>3}  {t['frage']}")
    n = len(zeilen)
    p = [z['platz'] for z in zeilen]
    werte = {'fragen': n, 'platz1': sum(1 for x in p if x == 1), 'top5': sum(1 for x in p if x and x <= 5),
             'top10': sum(1 for x in p if x and x <= 10), 'mrr': round(sum(1 / x for x in p if x) / n, 3)}
    print(werte)
    (HIER / f'ergebnis-{name}.json').write_text(json.dumps({'werte': werte, 'fragen': zeilen}, ensure_ascii=False, indent=1), encoding='utf-8')


if __name__ == '__main__':
    main()

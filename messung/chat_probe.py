#!/usr/bin/env python3
"""
chat_probe.py – Probelauf für einen Chat mit eigenem Modell (Ausblick auf der Projektseite).

Ein lokales Modell hinter llama-server (OpenAI-artige Schnittstelle mit Werkzeugaufrufen) beantwortet Fragen wie ein
Chat auf meinungsmache.de und benutzt dafür die Werkzeuge der MCP-Schnittstelle. Das Skript ist der „Vermittler“:
Es reicht die Werkzeugaufrufe des Modells per MCP weiter und spielt die Ergebnisse zurück. Wie beim Schwesterprojekt
rede-mit-dem-afd-gutachten (messung/chat_probe.py), nur mit der Python-Standardbibliothek.

  python3 chat_probe.py --url http://127.0.0.1:1243 --name gemma [--denken]

Je Frage: Gesamtzeit, Zeit bis zum Beginn der Antwort, Schritte, Werkzeuge, Token und die verlinkten Artikel.
„Fundstelle“: Die Antwort verlinkt einen der passenden Artikel aus testsatz.json (bei Briefings: mindestens drei Artikel
des Zeitraums). Ausgabe: probe_<name>.jsonl
"""
import argparse, json, re, time, urllib.request
from pathlib import Path

HIER = Path(__file__).parent
MCP = 'https://poc.deutschkaemer.info/meinungsmache/mcp'

ap = argparse.ArgumentParser()
ap.add_argument('--url', default='http://127.0.0.1:1243')
ap.add_argument('--name', required=True)
ap.add_argument('--denken', action='store_true', help='Denkmodus des Modells eingeschaltet lassen')
ap.add_argument('--schritte', type=int, default=8)
a = ap.parse_args()

# Zwei Briefings und acht Fragen aus dem Testsatz (Nummern dort), darunter solche mit anderen Wörtern als im Artikel
FRAGEN = [
    {'frage': 'Mach mir ein kurzes Abendbriefing: Was war heute los?', 'briefing': 'abend'},
    {'frage': 'Was gab es in der letzten Woche zu Klima und Umwelt?', 'briefing': 'woche'},
    {'testsatz': 7}, {'testsatz': 1}, {'testsatz': 13}, {'testsatz': 6},
    {'testsatz': 8}, {'testsatz': 25}, {'testsatz': 26}, {'testsatz': 27},
]


def post(url, daten, timeout):
    r = urllib.request.Request(url, json.dumps(daten).encode(), {'Content-Type': 'application/json',
                                                                'Accept': 'application/json, text/event-stream'})
    with urllib.request.urlopen(r, timeout=timeout) as f:
        return json.load(f)


def mcp(methode, params):
    j = post(MCP, {'jsonrpc': '2.0', 'id': 1, 'method': methode, 'params': params}, 90)
    if 'error' in j:
        raise RuntimeError(j['error'])
    return j['result']


def werkzeug(name, args):
    return json.loads(mcp('tools/call', {'name': name, 'arguments': args})['content'][0]['text'])


init = mcp('initialize', {'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 'chat_probe', 'version': '1'}})
TOOLS = [{'type': 'function', 'function': {'name': t['name'], 'description': t['description'], 'parameters': t['inputSchema']}}
         for t in mcp('tools/list', {})['tools'] if t['name'] not in ('search', 'fetch')]
SYSTEM = ('Du bist der Chat auf der Seite von meinungsmache.de. Beantworte Fragen ausschließlich mit den Werkzeugen, '
          'nicht aus eigenem Wissen. Suche zuerst, lies bei Bedarf nach und antworte dann auf Deutsch in höchstens 200 Wörtern '
          '(ein Briefing bis 350 Wörter), mit Links zu den Artikeln auf meinungsmache.de.\n\n' + init.get('instructions', ''))

satz = json.loads((HIER / 'testsatz.json').read_text(encoding='utf-8'))
slug = lambda u: (re.search(r'/article/([a-z0-9-]+)', u or '') or [None, None])[1]

aus = open(HIER / f'probe_{a.name}.jsonl', 'w', encoding='utf-8')
for nr, f in enumerate(FRAGEN):
    if 'testsatz' in f:
        t = satz[f['testsatz']]
        frage = t['frage']
        erwartet = {slug(werkzeug('artikel_lesen', {'id': i})['url']) for i in t['treffer']}
        mindestens = 1
    else:
        frage = f['frage']
        b = werkzeug('briefing', {'zeitraum': f['briefing'], **({'thema': 'Klima und Umwelt'} if 'Klima' in frage else {})})
        erwartet = {slug(x['url']) for x in b['meldungen'] + b['eigene_artikel']}
        mindestens = min(3, len(erwartet))
    msgs = [{'role': 'system', 'content': SYSTEM}, {'role': 'user', 'content': frage}]
    t0 = time.time()
    schritte, aufrufe, tok_ein, tok_aus = [], [], 0, 0
    antwort, fehler, beginn = '', None, None
    try:
        for _ in range(a.schritte):
            body = {'model': a.name, 'messages': msgs, 'tools': TOOLS, 'temperature': 0.3, 'max_tokens': 3000}
            if not a.denken:
                body['chat_template_kwargs'] = {'enable_thinking': False}
            ts = time.time()
            j = post(a.url + '/v1/chat/completions', body, 1200)
            tm, u = j.get('timings', {}), j.get('usage', {})
            tok_ein = u.get('prompt_tokens', tok_ein)
            tok_aus += u.get('completion_tokens', 0)
            schritte.append({'s': round(time.time() - ts, 1), 'prompt_n': tm.get('prompt_n'), 'predicted_n': tm.get('predicted_n')})
            m = j['choices'][0]['message']
            calls = m.get('tool_calls') or []
            if not calls:
                antwort = m.get('content') or ''
                beginn = round(time.time() - t0 - (tm.get('predicted_ms') or 0) / 1000, 1)
                break
            msgs.append({'role': 'assistant', 'content': m.get('content') or '', 'tool_calls': calls})
            for c in calls:
                name = c['function']['name']
                try:
                    args = json.loads(c['function'].get('arguments') or '{}')
                    inhalt = mcp('tools/call', {'name': name, 'arguments': args})['content'][0]['text']
                except Exception as e:  # noqa: BLE001 – Fehler an das Modell zurückgeben, wie ein Client es täte
                    args, inhalt = {}, f'Fehler: {e}'
                aufrufe.append({'werkzeug': name, 'args': args, 'zeichen': len(inhalt)})
                msgs.append({'role': 'tool', 'tool_call_id': c.get('id', ''), 'content': inhalt})
        else:
            fehler = f'keine Antwort nach {a.schritte} Schritten'
    except Exception as e:  # noqa: BLE001
        fehler = str(e)[:400]
    ges = round(time.time() - t0, 1)
    verlinkt = {s for s in re.findall(r'meinungsmache\.de/article/([a-z0-9-]+)', antwort)}
    treffer = len(verlinkt & erwartet)
    erg = {'nr': nr, 'frage': frage, 'briefing': 'briefing' in f, 'sek': ges, 'beginn': beginn, 'schritte': len(schritte),
           'werkzeuge': aufrufe, 'tok_ein_letzt': tok_ein, 'tok_aus': tok_aus, 'zeiten': schritte,
           'links': len(verlinkt), 'treffer': treffer, 'fundstelle': treffer >= mindestens,
           'woerter': len(antwort.split()), 'fehler': fehler, 'antwort': antwort}
    aus.write(json.dumps(erg, ensure_ascii=False) + '\n')
    aus.flush()
    print(f"{nr:>2} {ges:>6}s Beginn {beginn}s Schritte {len(schritte)} Links {len(verlinkt)} Fundstelle {erg['fundstelle']!s:5} "
          f"{'FEHLER ' + fehler if fehler else ''}  {frage[:50]}", flush=True)

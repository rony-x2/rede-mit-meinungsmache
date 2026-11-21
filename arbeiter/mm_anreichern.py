#!/usr/bin/env python3
"""
mm_anreichern.py – reichert neue Artikel von „Rede mit Meinungsmache“ mit einem lokalen Sprachmodell an.

Läuft auf dem Rechner mit der Grafikkarte, etwa stündlich per Cron. Holt über
https://poc.deutschkaemer.info/meinungsmache/anreicherung.php die Artikel ohne Anreicherung samt Anweisung und
Antwortschema, lässt sie von Gemma 4 (llama-server im Router-Modus) beantworten und gibt die Ergebnisse zurück.
Die Fragen bettet danach der stündliche Abruf auf dem Webserver ein.

Rücksicht auf andere Arbeit auf der Karte: Ist im Router ein anderes Modell geladen oder belegt ein anderes
Programm Grafikspeicher, wartet der Lauf auf die nächste Stunde. Lädt er das Modell selbst, entlädt er es am Ende wieder.

  python3 mm_anreichern.py [--max 600] [--parallel 4] [--router http://127.0.0.1:1235]

Kennwort: ~/.config/mm_anreichern/token (nur lesbar für den Benutzer); auf dem Server liegt nur sein SHA-256.
Nur Python-Standardbibliothek.
"""
import argparse, concurrent.futures as cf, json, os, re, subprocess, sys, time, urllib.request
from pathlib import Path

SERVER = 'https://poc.deutschkaemer.info/meinungsmache/anreicherung.php'
TOKEN = Path.home() / '.config' / 'mm_anreichern' / 'token'
FREMD_MB = 1500   # so viel Grafikspeicher darf ein anderes Programm belegen, ohne dass der Lauf aussetzt

ap = argparse.ArgumentParser()
ap.add_argument('--max', type=int, default=600, help='höchstens so viele Artikel je Lauf')
ap.add_argument('--parallel', type=int, default=4)
ap.add_argument('--router', default='http://127.0.0.1:1235')
a = ap.parse_args()


def log(*t):
    print(time.strftime('%H:%M:%S'), *t, flush=True)


def rufen(url, daten=None, kopf=None, timeout=120):
    r = urllib.request.Request(url, json.dumps(daten).encode() if daten is not None else None,
                               {'Content-Type': 'application/json', **(kopf or {})})
    with urllib.request.urlopen(r, timeout=timeout) as f:
        return json.load(f)


def status(modell):
    for m in rufen(a.router + '/models', timeout=15).get('data', []):
        if m.get('id') == modell:
            s = m.get('status')
            return (s.get('value') if isinstance(s, dict) else s) or ''
    return ''


def andere_geladen(modell):
    return [m.get('id') for m in rufen(a.router + '/models', timeout=15).get('data', [])
            if m.get('id') != modell and ((m.get('status') or {}).get('value') if isinstance(m.get('status'), dict) else m.get('status')) in ('loaded', 'loading')]


def grafikspeicher_mb():
    try:
        aus = subprocess.run(['nvidia-smi', '--query-gpu=memory.used', '--format=csv,noheader,nounits'],
                             capture_output=True, text=True, timeout=20).stdout
        return max(int(x) for x in aus.split())
    except Exception:  # noqa: BLE001
        return 0


def main():
    token = TOKEN.read_text().strip()
    kopf = {'X-Token': token}
    d = rufen(SERVER + '?n=40', kopf=kopf, timeout=60)
    if not d['artikel']:
        log('nichts zu tun')
        return
    modell = d['modell']
    s = status(modell)
    selbst = False
    if s != 'loaded':
        fremd = andere_geladen(modell)
        if fremd:
            log('Karte belegt durch', ', '.join(fremd), '– nächster Versuch beim nächsten Lauf')
            return
        mb = grafikspeicher_mb()
        if mb > FREMD_MB:
            log(f'Grafikspeicher belegt ({mb} MB) – nächster Versuch beim nächsten Lauf')
            return
        log('lade', modell)
        rufen(a.router + '/models/load', {'model': modell}, timeout=60)
        ende = time.time() + 600
        while status(modell) != 'loaded':
            if time.time() > ende or status(modell) in ('failed', 'error'):
                log('Laden fehlgeschlagen')
                return
            time.sleep(3)
        selbst = True
    try:
        erledigt, fehler = 0, 0
        while d['artikel'] and erledigt < a.max:
            def eins(art):
                daten = {'model': modell, 'temperature': float(d['parameter'].get('temperatur', 0.2)),
                         'max_tokens': int(d['parameter'].get('max_tokens', 1200)),
                         'messages': [{'role': 'system', 'content': d['system']}, {'role': 'user', 'content': art['prompt']}],
                         'response_format': {'type': 'json_schema', 'json_schema': {'name': 'antwort', 'strict': True, 'schema': d['json_schema']}}}
                w = rufen(a.router + '/v1/chat/completions', daten, timeout=300)['choices'][0]
                text = re.sub(r'<think>.*?</think>', '', (w.get('message') or {}).get('content') or '', flags=re.S)
                m = re.search(r'\{.*\}', text, flags=re.S)
                return {'id': art['id'], 'fassung': art['fassung'], **json.loads(m.group(0))}
            ergebnisse = []
            with cf.ThreadPoolExecutor(a.parallel) as ex:
                for f in cf.as_completed([ex.submit(eins, x) for x in d['artikel']]):
                    try:
                        ergebnisse.append(f.result())
                    except Exception as e:  # noqa: BLE001
                        fehler += 1
                        log('Fehler:', str(e)[:160])
            if not ergebnisse:
                break
            n = rufen(SERVER, {'modell': modell, 'ergebnisse': ergebnisse}, kopf=kopf, timeout=120)['uebernommen']
            erledigt += n
            log(f'{n} übernommen, zusammen {erledigt}')
            d = rufen(SERVER + '?n=40', kopf=kopf, timeout=60)
        log(f'fertig: {erledigt} Artikel, {fehler} Fehler')
    finally:
        if selbst:
            rufen(a.router + '/models/unload', {'model': modell}, timeout=60)
            log('entladen', modell)


if __name__ == '__main__':
    try:
        main()
    except Exception as e:  # noqa: BLE001
        log('Abbruch:', e)
        sys.exit(1)

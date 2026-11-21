/* Rede mit Meinungsmache – Kopieren, Beispiel-Unterhaltung, Menü, aktueller Stand */
for (const b of document.querySelectorAll('[data-kopie]')) {
  b.addEventListener('click', async () => {
    const t = document.getElementById(b.dataset.kopie)?.textContent ?? '';
    const vorher = b.dataset.vorher ??= b.textContent;
    try { await navigator.clipboard.writeText(t); b.textContent = 'Kopiert'; setTimeout(() => { b.textContent = vorher; }, 1800); } catch {}
  });
}
(() => {
  const box = document.querySelector('.gespraech');
  if (!box) return;
  const gs = [...box.querySelectorAll('.g')], punkte = [...box.querySelectorAll('.g-punkt')];
  const ruhig = matchMedia('(prefers-reduced-motion: reduce)').matches;
  let i = 0, uhr = 0, tipp = 0, halt = false;
  const weiter = () => { clearTimeout(uhr); uhr = setTimeout(() => (halt || document.hidden) ? weiter() : zeige(i + 1), 10500); };
  const zeige = (n) => {
    clearTimeout(uhr); clearInterval(tipp);
    const alt = gs[i];
    alt.classList.remove('aktiv', 'laeuft', 'gefragt'); alt.setAttribute('aria-hidden', 'true');
    alt.querySelector('.beleg')?.setAttribute('tabindex', '-1');
    punkte[i]?.setAttribute('aria-current', 'false');
    i = (n + gs.length) % gs.length;
    const g = gs[i], f = g.querySelector('.frage-text'), voll = f.dataset.text;
    punkte[i]?.setAttribute('aria-current', 'true');
    g.removeAttribute('aria-hidden'); g.querySelector('.beleg')?.removeAttribute('tabindex');
    g.classList.add('aktiv');
    if (ruhig) { f.textContent = voll; return; }
    void g.offsetWidth;
    g.classList.add('laeuft');
    f.textContent = '';
    let k = 0;
    setTimeout(() => {
      tipp = setInterval(() => {
        k += 1; f.textContent = voll.slice(0, k);
        if (k >= voll.length) { clearInterval(tipp); g.classList.add('gefragt'); weiter(); }
      }, 32);
    }, 650);
  };
  punkte.forEach((p, n) => p.addEventListener('click', () => zeige(n)));
  box.addEventListener('mouseenter', () => { halt = true; });
  box.addEventListener('mouseleave', () => { halt = false; });
  box.addEventListener('focusin', () => { halt = true; });
  box.addEventListener('focusout', () => { halt = false; });
  if (!ruhig) {
    const start = () => zeige(0);
    if ('IntersectionObserver' in window) {
      const io = new IntersectionObserver((e) => { if (e[0].isIntersecting) { io.disconnect(); start(); } }, { threshold: .4 });
      io.observe(box);
    } else start();
  }
})();

// Menü auf dem Smartphone
(() => {
  const kopf = document.querySelector('.kopf'), knopf = kopf?.querySelector('.menue-knopf');
  if (!knopf) return;
  const setze = (auf) => { kopf.classList.toggle('offen', auf); knopf.setAttribute('aria-expanded', String(auf)); };
  knopf.addEventListener('click', () => {
    const auf = !kopf.classList.contains('offen');
    setze(auf);
    if (auf) kopf.querySelector('nav a')?.focus({ preventScroll: true });
  });
  kopf.querySelectorAll('nav a').forEach((a) => a.addEventListener('click', () => setze(false)));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && kopf.classList.contains('offen')) { setze(false); knopf.focus(); } });
  document.addEventListener('click', (e) => { if (!kopf.contains(e.target)) setze(false); });
})();

// Kennzahlen und „Zuletzt erschienen“ aktuell aus stand.php (fünf Minuten zwischengespeichert); ohne Skript bleibt der Stand vom Bauen
(() => {
  const liste = document.querySelector('[data-stand-liste]');
  const quelle = document.querySelector('[data-stand]')?.dataset.stand;
  if (!quelle || !('fetch' in window)) return;
  const zahl = (n) => Number(n).toLocaleString('de-DE');
  const zeit = (t) => { const m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2})/.exec(t || ''); return m ? `${m[3]}.${m[2]}.${m[1]}, ${m[4]} Uhr` : (t || ''); };
  const el = (tag, cls, text) => { const e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; };
  fetch(quelle, { headers: { Accept: 'application/json' } }).then((r) => (r.ok ? r.json() : null)).then((d) => {
    if (!d) return;
    for (const f of document.querySelectorAll('[data-feld]')) {
      const k = f.dataset.feld;
      if (d[k] == null) continue;
      f.textContent = /abruf|bis/.test(k) ? zeit(d[k]) : zahl(d[k]);
    }
    if (!liste || !Array.isArray(d.neueste) || !d.neueste.length) return;
    const bekannt = new Set([...liste.querySelectorAll('a')].map((a) => a.href));
    liste.replaceChildren(...d.neueste.map((a) => {
      const li = el('li', bekannt.has(a.url) ? '' : 'frisch');
      const link = el('a', '', a.titel); link.href = a.url;
      const meta = el('span', 'n-meta');
      meta.append(el('span', 'n-art', a.art === 'eigen' ? 'eigener Artikel' : 'Meldung'), el('span', '', a.von || ''), el('time', '', zeit(a.zeit)));
      meta.lastChild.dateTime = (a.zeit || '').replace(' ', 'T');
      li.append(link, meta);
      return li;
    }));
  }).catch(() => {});
})();

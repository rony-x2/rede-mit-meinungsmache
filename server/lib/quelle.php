<?php
/**
 * Abruf und Zerlegung der öffentlichen Inhalte von meinungsmache.de:
 * RSS-Feed (neue Artikel), Sitemap (Archiv) und Artikelseiten (Text, Dachzeile, Quelle, Link zum Original, Autor).
 * Verwendet nur, was jede Leserin im Browser sieht; die Abfragen sind gedrosselt und als PoC gekennzeichnet.
 */
declare(strict_types=1);

const MM_SEITE     = 'https://meinungsmache.de';
const MM_FEED      = 'https://meinungsmache.de/rss/all';
const MM_SITEMAP   = 'https://meinungsmache.de/sitemap.xml';
const MM_KENNUNG   = 'Mozilla/5.0 (compatible; RedeMitMeinungsmache/1.0; +https://poc.deutschkaemer.info/meinungsmache/)';

/** HTTP-GET mit Zeitlimit. @return array{code:int, text:string} */
function mm_holen(string $url, int $zeit = 20): array
{
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => $zeit, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => MM_KENNUNG,
        CURLOPT_ENCODING => '', CURLOPT_HTTPHEADER => ['Accept-Language: de'],
    ]);
    $text = curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    return ['code' => $code, 'text' => is_string($text) ? $text : ''];
}

function mm_slug(string $url): ?string
{
    return preg_match('~^https://meinungsmache\.de/article/([a-z0-9][a-z0-9-]{0,250})/?$~', trim($url), $m) ? $m[1] : null;
}

/** Feed: @return list<array{slug:string, url:string, titel:string, datum:string}> (neueste zuerst) */
function mm_feed(): array
{
    $r = mm_holen(MM_FEED);
    if ($r['code'] !== 200) {
        throw new RuntimeException('Feed: HTTP ' . $r['code']);
    }
    $x = @simplexml_load_string($r['text']);
    if (!$x) {
        throw new RuntimeException('Feed: kein gültiges XML');
    }
    $aus = [];
    foreach ($x->channel->item as $it) {
        $slug = mm_slug((string)$it->link);
        if ($slug) {
            $aus[] = ['slug' => $slug, 'url' => (string)$it->link, 'titel' => trim((string)$it->title),
                      'datum' => gmdate('Y-m-d H:i:s', strtotime((string)$it->pubDate) ?: time())];
        }
    }
    return $aus;
}

/** Sitemap: alle Artikeladressen. @return array<string,string> slug => lastmod (UTC) */
function mm_sitemap(): array
{
    $r = mm_holen(MM_SITEMAP);
    if ($r['code'] !== 200) {
        throw new RuntimeException('Sitemap: HTTP ' . $r['code']);
    }
    $x = @simplexml_load_string($r['text']);
    $aus = [];
    foreach ($x->url ?? [] as $u) {
        $slug = mm_slug((string)$u->loc);
        if ($slug) {
            $aus[$slug] = gmdate('Y-m-d H:i:s', strtotime((string)$u->lastmod) ?: time());
        }
    }
    return $aus;
}

function mm_iso(string $s): ?string
{
    $t = strtotime($s);
    return $t ? gmdate('Y-m-d H:i:s', $t) : null;
}

function mm_leer(string $s): string
{
    $s = str_replace(["\u{2139}\u{FE0E}", "\u{2139}\u{FE0F}", "\u{2139}", "\u{00A0}", "\u{FE0E}"], ['', '', '', ' ', ''], $s);
    $s = preg_replace('/(?<!\p{So})\x{FE0F}/u', '', $s);   // übrig gebliebene Variantenwähler (z. B. von entfernten ℹ︎-Links)
    $s = preg_replace('/[ \t\r\n]+/u', ' ', $s);
    return trim(preg_replace('/ ([,.;:!?)])/u', '$1', $s));   // Leerzeichen vor Satzzeichen (entfernte ℹ︎-Links)
}

/**
 * Eine Artikelseite holen und zerlegen.
 * @return array{slug:string,url:string,titel:string,unterzeile:?string,text:string,art:string,quelle:?string,
 *   original_url:?string,autor:?string,autor_url:?string,bildquelle:?string,veroeffentlicht:string,geaendert:?string,links:list<array{url:string,text:string}>}
 */
function mm_artikel_holen(string $slug): array
{
    $url = MM_SEITE . '/article/' . $slug;
    $r = mm_holen($url);
    if ($r['code'] !== 200 || $r['text'] === '') {
        throw new RuntimeException("Artikel $slug: HTTP " . $r['code']);
    }
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $r['text'], LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    $meta = static function (string $name) use ($xp): ?string {
        $n = $xp->query("//meta[@property='$name' or @name='$name']/@content")->item(0);
        return $n ? trim($n->nodeValue) : null;
    };
    $art = $xp->query('//main//article')->item(0);
    if (!$art) {
        throw new RuntimeException("Artikel $slug: kein <article>");
    }
    $erstes = static fn(string $q) => ($n = $xp->query($q, $art)->item(0)) ? mm_leer($n->textContent) : null;

    $titel = $erstes('.//h1') ?? (string)$meta('og:title');
    $unter = $erstes('.//h3[1]');
    $quelle = null; $autor = null; $autorUrl = null; $bild = null; $original = null;
    foreach ($xp->query('.//span', $art) as $sp) {
        $t = mm_leer($sp->textContent);
        if (str_starts_with($t, 'Quelle:')) {
            $quelle = trim(substr($t, 7)) ?: null;
            break;
        }
    }
    foreach ($xp->query(".//a[contains(@href,'/autor/')]", $art) as $a) {
        $autor = mm_leer($a->textContent) ?: null;
        $autorUrl = MM_SEITE . parse_url($a->getAttribute('href'), PHP_URL_PATH);
        break;
    }
    foreach ($xp->query('.//a', $art) as $a) {
        $t = mm_leer($a->textContent);
        if ($bild === null && str_starts_with($t, 'Bildquelle:')) {
            $bild = trim(substr($t, 11)) ?: null;
        }
        if ($original === null && $t === 'Zum Originalartikel') {
            $original = $a->getAttribute('href') ?: null;
        }
    }
    // Artikeltext: der „prose“-Block
    $prose = $xp->query(".//div[contains(concat(' ', normalize-space(@class), ' '), ' prose ')]", $art)->item(0);
    $absaetze = [];
    $links = [];
    if ($prose) {
        foreach ($xp->query('./*', $prose) as $el) {
            $tag = strtolower($el->nodeName);
            if (in_array($tag, ['ul', 'ol'], true)) {
                foreach ($xp->query('./li', $el) as $li) {
                    $absaetze[] = '– ' . mm_leer($li->textContent);
                }
            } else {
                $t = mm_leer($el->textContent);
                if ($t !== '') {
                    $absaetze[] = $t;
                }
            }
        }
        foreach ($xp->query('.//a[@href]', $prose) as $a) {
            $h = $a->getAttribute('href');
            if (preg_match('~^https?://~', $h)) {
                $links[] = ['url' => $h, 'text' => mb_substr(mm_leer($a->textContent), 0, 300)];
            }
        }
    }
    $text = implode("\n\n", $absaetze);
    if ($text === '') {
        $text = (string)($meta('og:description') ?? '');
    }
    $section = $meta('article:section');
    $quelle ??= ($section && $section !== 'Eigene Artikel') ? $section : null;
    $eigen = $section === 'Eigene Artikel' || ($autor !== null && $original === null);
    return [
        'slug' => $slug, 'url' => $url, 'titel' => $titel, 'unterzeile' => $unter, 'text' => $text,
        'art' => $eigen ? 'eigen' : 'meldung', 'quelle' => $eigen ? null : $quelle, 'original_url' => $original,
        'autor' => $autor, 'autor_url' => $autorUrl, 'bildquelle' => $bild,
        'veroeffentlicht' => mm_iso((string)$meta('article:published_time')) ?? gmdate('Y-m-d H:i:s'),
        'geaendert' => mm_iso((string)$meta('article:modified_time')),
        'links' => $links,
    ];
}

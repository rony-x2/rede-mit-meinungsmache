-- Datenbank poc_db: Artikel von meinungsmache.de für „Rede mit Meinungsmache“.
-- Tabellen mit Vorsilbe mm_, angelegt von abruf.php beim ersten Lauf (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS mm_artikel (
    id            INT UNSIGNED     NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug          VARCHAR(255)     NOT NULL COMMENT 'Pfad nach /article/',
    url           VARCHAR(400)     NOT NULL,
    titel         VARCHAR(500)     NOT NULL,
    unterzeile    VARCHAR(600)     NULL COMMENT 'Dachzeile über dem Titel',
    text          MEDIUMTEXT       NOT NULL COMMENT 'Artikeltext als einfacher Text, Absätze durch Leerzeile getrennt',
    zeichen       INT UNSIGNED     NOT NULL DEFAULT 0,
    art           ENUM('meldung','eigen') NOT NULL DEFAULT 'meldung' COMMENT 'meldung: Zusammenfassung mit Quelle; eigen: eigener Artikel der Redaktion',
    quelle        VARCHAR(200)     NULL COMMENT 'Medium der Originalmeldung, z. B. Tagesschau (article:section)',
    original_url  VARCHAR(800)     NULL COMMENT 'Link „Zum Originalartikel“',
    autor         VARCHAR(120)     NULL,
    autor_url     VARCHAR(300)     NULL,
    bildquelle    VARCHAR(400)     NULL,
    veroeffentlicht DATETIME       NOT NULL COMMENT 'UTC',
    geaendert     DATETIME         NULL COMMENT 'UTC',
    abgerufen     DATETIME         NOT NULL COMMENT 'UTC',
    UNIQUE KEY uq_slug (slug),
    KEY ix_zeit (veroeffentlicht),
    KEY ix_quelle (quelle),
    FULLTEXT KEY ft_text (titel, unterzeile, text)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS mm_link (
    artikel_id INT UNSIGNED NOT NULL,
    nr         SMALLINT UNSIGNED NOT NULL,
    url        VARCHAR(800) NOT NULL,
    text       VARCHAR(300) NULL,
    PRIMARY KEY (artikel_id, nr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT 'Quellenlinks im Artikeltext';

CREATE TABLE IF NOT EXISTS mm_vektor (
    artikel_id INT UNSIGNED      NOT NULL,
    teil       SMALLINT UNSIGNED NOT NULL COMMENT '0 = Titel, Dachzeile und Anfang; ab 1 weitere Abschnitte langer Artikel',
    start      INT UNSIGNED      NOT NULL,
    laenge     INT UNSIGNED      NOT NULL,
    vektor     VECTOR(384)       NOT NULL,
    PRIMARY KEY (artikel_id, teil)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci COMMENT 'Qwen3-Embedding-0.6B, 384 Dim., normiert';

CREATE TABLE IF NOT EXISTS mm_abruf (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    zeit      DATETIME     NOT NULL,
    art       VARCHAR(20)  NOT NULL COMMENT 'feed, nachholen, einbetten',
    neu       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    geaendert SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    eingebettet SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    fehler    TEXT         NULL,
    dauer     FLOAT        NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- Anreicherung durch ein lokales Sprachmodell (Gemma 4 auf eigener Hardware, siehe docs/anreicherung.md):
-- typische Fragen, Suchbegriffe und Themen je Artikel. Dient nur der Suche und der Gliederung von Briefings;
-- Antworten kommen immer aus dem Artikeltext.
CREATE TABLE IF NOT EXISTS mm_anreicherung (
    artikel_id   INT UNSIGNED NOT NULL PRIMARY KEY,
    modell       VARCHAR(120) NOT NULL,
    zeit         DATETIME     NOT NULL COMMENT 'UTC',
    fragen       TEXT         NOT NULL COMMENT 'JSON-Liste',
    stichwoerter TEXT         NOT NULL COMMENT 'JSON-Liste',
    themen       VARCHAR(300) NOT NULL COMMENT 'JSON-Liste aus MM_THEMEN'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS mm_thema (
    artikel_id INT UNSIGNED NOT NULL,
    thema      VARCHAR(60)  NOT NULL,
    PRIMARY KEY (artikel_id, thema),
    KEY ix_thema (thema)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

CREATE TABLE IF NOT EXISTS mm_frage (
    artikel_id INT UNSIGNED      NOT NULL,
    nr         TINYINT UNSIGNED  NOT NULL COMMENT '0 = Suchbegriffe, ab 1 erzeugte Fragen',
    text       VARCHAR(600)      NOT NULL,
    vektor     VECTOR(384)       NULL COMMENT 'mit derselben Anweisung eingebettet wie Suchanfragen',
    PRIMARY KEY (artikel_id, nr)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

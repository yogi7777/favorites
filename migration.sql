-- ============================================================
-- Migration: Tabs-Feature
--
-- Fuehrt das Tab-System ein fuer bestehende Installationen.
--
-- Aenderungen:
--   1) Neue Tabelle `tabs`
--   2) n:m-Zuordnung `categories` <-> `tabs`  (Tabelle `category_tabs`)
--   3) Tab-spezifische Kachel-Reihenfolge     (Tabelle `category_tab_positions`)
--   4) Default-Tab "Alle" pro Benutzer anlegen
--   5) Alle bestehenden Kategorien dem Default-Tab zuordnen
--
-- Voraussetzung: Urspruengliches Schema (users, remember_tokens,
--               categories, favorites) ist vorhanden.
--
-- Sicher mehrfach ausfuehrbar (IF NOT EXISTS / INSERT IGNORE).
-- ============================================================

USE favorites;

-- Schritt 1: Tabs-Tabelle anlegen
CREATE TABLE IF NOT EXISTS tabs (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    name       VARCHAR(100) NOT NULL,
    slug       VARCHAR(120) NOT NULL,
    icon       VARCHAR(32) DEFAULT 'T',
    position   INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tabs_user_slug (user_id, slug),
    UNIQUE KEY uq_tabs_user_name (user_id, name),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Schritt 2: n:m-Verbindungstabelle categories <-> tabs
CREATE TABLE IF NOT EXISTS category_tabs (
    category_id INT NOT NULL,
    tab_id      INT NOT NULL,
    PRIMARY KEY (category_id, tab_id),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (tab_id) REFERENCES tabs(id) ON DELETE CASCADE
);

-- Schritt 3: Tab-spezifische Reihenfolge fuer Kacheln
CREATE TABLE IF NOT EXISTS category_tab_positions (
    tab_id      INT NOT NULL,
    category_id INT NOT NULL,
    position    INT DEFAULT 0,
    PRIMARY KEY (tab_id, category_id),
    FOREIGN KEY (tab_id)      REFERENCES tabs(id)       ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- Schritt 4: Default-Tab "Alle" pro Benutzer anlegen (falls noch nicht vorhanden)
INSERT INTO tabs (user_id, name, slug, icon, position)
SELECT u.id, 'Alle', 'alle', 'A', 0
FROM users u
LEFT JOIN tabs t ON t.user_id = u.id AND t.slug = 'alle'
WHERE t.id IS NULL;

-- Schritt 5: Alle bestehenden Kategorien dem Default-Tab zuordnen
INSERT IGNORE INTO category_tabs (category_id, tab_id)
SELECT c.id, t.id
FROM categories c
JOIN tabs t ON t.user_id = c.user_id AND t.slug = 'alle';

-- Schritt 6: Positionen aus categories.position fuer den Default-Tab uebernehmen
INSERT INTO category_tab_positions (tab_id, category_id, position)
SELECT t.id, c.id, COALESCE(c.position, 0)
FROM categories c
JOIN tabs t ON t.user_id = c.user_id AND t.slug = 'alle'
ON DUPLICATE KEY UPDATE position = VALUES(position);

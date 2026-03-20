-- ============================================================
-- Migration v3: Tabs fuer Kategorien
--
-- Ziel:
-- 1) Neue Tabelle tabs
-- 2) n:m Zuordnung categories <-> tabs
-- 3) Tab-spezifische Kachel-Reihenfolge
-- 4) Bestehende Daten in Default-Tab "Alle" ueberfuehren
-- ============================================================

USE favorites;

CREATE TABLE IF NOT EXISTS tabs (
		id INT AUTO_INCREMENT PRIMARY KEY,
		user_id INT NOT NULL,
		name VARCHAR(100) NOT NULL,
		slug VARCHAR(120) NOT NULL,
		icon VARCHAR(32) DEFAULT 'T',
		position INT DEFAULT 0,
		created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
		UNIQUE KEY uq_tabs_user_slug (user_id, slug),
		UNIQUE KEY uq_tabs_user_name (user_id, name),
		FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS category_tabs (
		category_id INT NOT NULL,
		tab_id INT NOT NULL,
		PRIMARY KEY (category_id, tab_id),
		FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
		FOREIGN KEY (tab_id) REFERENCES tabs(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS category_tab_positions (
		tab_id INT NOT NULL,
		category_id INT NOT NULL,
		position INT DEFAULT 0,
		PRIMARY KEY (tab_id, category_id),
		FOREIGN KEY (tab_id) REFERENCES tabs(id) ON DELETE CASCADE,
		FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- Default-Tab "Alle" pro Benutzer erstellen
INSERT INTO tabs (user_id, name, slug, icon, position)
SELECT u.id, 'Alle', 'alle', 'A', 0
FROM users u
LEFT JOIN tabs t
	ON t.user_id = u.id
 AND t.slug = 'alle'
WHERE t.id IS NULL;

-- Alle bestehenden Kategorien dem Default-Tab zuordnen
INSERT IGNORE INTO category_tabs (category_id, tab_id)
SELECT c.id, t.id
FROM categories c
JOIN tabs t
	ON t.user_id = c.user_id
 AND t.slug = 'alle';

-- Anfangspositionen fuer den Default-Tab aus categories.position uebernehmen
INSERT INTO category_tab_positions (tab_id, category_id, position)
SELECT t.id, c.id, COALESCE(c.position, 0)
FROM categories c
JOIN tabs t
	ON t.user_id = c.user_id
 AND t.slug = 'alle'
ON DUPLICATE KEY UPDATE position = VALUES(position);

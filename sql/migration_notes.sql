-- ============================================================
-- Migration: Notes-Feature
-- Für bestehende Installationen einmalig ausführen.
-- Neue Installationen verwenden DB_Script.sql (wird dort separat ergänzt).
-- ============================================================

-- Notes-Tabelle
CREATE TABLE IF NOT EXISTS notes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL DEFAULT 'Note',
    content TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note-Tab-Zuordnungen inkl. Positionsdaten (Grid + freie Positionierung)
CREATE TABLE IF NOT EXISTS note_tabs (
    note_id INT NOT NULL,
    tab_id  INT NOT NULL,
    position INT DEFAULT 0,      -- Reihenfolge im Grid-/Spalten-Layout
    pos_x INT DEFAULT NULL,      -- Freie X-Position (Desktop-Edit-Modus)
    pos_y INT DEFAULT NULL,      -- Freie Y-Position (Desktop-Edit-Modus)
    width  INT DEFAULT 360,      -- Breite der Note-Kachel (px)
    height INT DEFAULT 200,      -- Höhe der Note-Kachel (px)
    PRIMARY KEY (note_id, tab_id),
    FOREIGN KEY (note_id) REFERENCES notes(id) ON DELETE CASCADE,
    FOREIGN KEY (tab_id)  REFERENCES tabs(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Freie Positionierungsspalten für Favoriten-Kategorien
ALTER TABLE category_tab_positions
    ADD COLUMN IF NOT EXISTS pos_x INT DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS pos_y INT DEFAULT NULL;

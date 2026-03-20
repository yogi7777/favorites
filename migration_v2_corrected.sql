-- ============================================================
-- Migration v2: New Tabs Table Structure (CORRECTED)
-- 
-- Renames old 'categories' to 'tabs', creates proper n:m for favorites<->tabs
-- This preserves your existing data and implements the tab feature correctly.
-- ============================================================

USE favorites;

-- Step 1: Rename the old categories table to tabs
-- (This is what you originally called "categories" for grouping Favoriten)
ALTER TABLE favorites DROP FOREIGN KEY favorites_ibfk_2;
ALTER TABLE categories RENAME TO tabs;

-- Step 2: Rename the column name in favorites for clarity
ALTER TABLE favorites CHANGE COLUMN category_id tab_id INT NULL;

-- Step 3: Re-add the foreign key with new table name
ALTER TABLE favorites ADD CONSTRAINT favorites_ibfk_2 
  FOREIGN KEY (tab_id) REFERENCES tabs(id) ON DELETE SET NULL;

-- Step 4: Create the many-to-many junction table
CREATE TABLE IF NOT EXISTS favorite_tabs (
    favorite_id INT NOT NULL,
    tab_id INT NOT NULL,
    PRIMARY KEY (favorite_id, tab_id),
    FOREIGN KEY (favorite_id) REFERENCES favorites(id) ON DELETE CASCADE,
    FOREIGN KEY (tab_id) REFERENCES tabs(id) ON DELETE CASCADE
);

-- Step 5: Migrate existing single-tab assignments to the junction table
INSERT IGNORE INTO favorite_tabs (favorite_id, tab_id)
SELECT id, tab_id FROM favorites WHERE tab_id IS NOT NULL;

-- Done. All your existing data is preserved:
-- - tabs table contains all your groupings (formerly "categories")
-- - favorites.tab_id still exists as the "primary" tab for backward compat
-- - favorite_tabs holds the n:m relationships for multi-tab favoriten

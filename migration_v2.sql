-- ============================================================
-- Migration v2: Many-to-many categories for favorites (Tabs)
-- Run this ONCE on your existing "favorites" database.
-- Safe to run after DB_Script.sql was applied.
-- ============================================================

USE favorites;

-- Step 1: Create junction table for favorite ↔ category (n:m)
CREATE TABLE IF NOT EXISTS favorite_categories (
    favorite_id INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (favorite_id, category_id),
    FOREIGN KEY (favorite_id) REFERENCES favorites(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

-- Step 2: Migrate existing single-category assignments into junction table
INSERT IGNORE INTO favorite_categories (favorite_id, category_id)
SELECT id, category_id
FROM favorites
WHERE category_id IS NOT NULL;

-- Step 3: Remove the old FK constraint on favorites.category_id
--         and make the column nullable so favorites can exist without
--         a "primary" category (they live through the junction table).
--
--         This finds the FK constraint name dynamically and drops it.

SET @fk_name = (
    SELECT CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'favorites'
      AND COLUMN_NAME = 'category_id'
      AND REFERENCED_TABLE_NAME = 'categories'
    LIMIT 1
);

-- Drop FK only if it exists
SET @sql = IF(
    @fk_name IS NOT NULL,
    CONCAT('ALTER TABLE favorites DROP FOREIGN KEY `', @fk_name, '`'),
    'SELECT 1 -- no FK to drop'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Make category_id nullable
ALTER TABLE favorites MODIFY COLUMN category_id INT NULL;

-- Done.
-- Your existing favorites data is fully preserved.
-- Each favorite now has its original category in the junction table.

-- Bundle backward-compatibility migration
-- Safe to run once on installations where item_bundles already exists.
SET @db = DATABASE();
SET @has_sort_order = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=@db AND TABLE_NAME='item_bundles' AND COLUMN_NAME='sort_order');
SET @sql = IF(@has_sort_order=0, 'ALTER TABLE item_bundles ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER quantity', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

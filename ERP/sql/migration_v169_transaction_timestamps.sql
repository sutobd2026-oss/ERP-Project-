-- v169: Preserve transaction creation time for transaction list display.
-- Existing txn_date values remain unchanged. New transactions receive CURRENT_TIMESTAMP.
SET @db := DATABASE();
SET @has_created_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=@db AND TABLE_NAME='transactions' AND COLUMN_NAME='created_at'
);
SET @sql := IF(@has_created_at=0,
  'ALTER TABLE transactions ADD COLUMN created_at DATETIME NULL DEFAULT NULL AFTER txn_date',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE transactions
SET created_at = txn_date
WHERE created_at IS NULL AND txn_date IS NOT NULL;

ALTER TABLE transactions
  MODIFY COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

CREATE INDEX IF NOT EXISTS idx_transactions_created_at ON transactions(company_id, created_at);

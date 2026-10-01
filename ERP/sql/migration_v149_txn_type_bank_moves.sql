-- v149: allow dedicated bank transaction types without MySQL ENUM truncation warnings.
-- Existing values are preserved; the column is changed to VARCHAR so future transaction types
-- (bank_transfer, bank_deposit, bank_withdraw, etc.) are accepted safely.
ALTER TABLE transactions
  MODIFY COLUMN txn_type VARCHAR(50) NOT NULL;

ALTER TABLE expense_categories ADD COLUMN expense_type ENUM('direct','indirect') NOT NULL DEFAULT 'indirect' AFTER name;
UPDATE expense_categories SET expense_type='indirect' WHERE expense_type IS NULL OR expense_type='';
CREATE TABLE IF NOT EXISTS expense_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  category_id BIGINT UNSIGNED NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_exp_item_company_name (company_id,name),
  KEY idx_exp_item_category (category_id),
  FOREIGN KEY(company_id) REFERENCES companies(id) ON DELETE CASCADE,
  FOREIGN KEY(category_id) REFERENCES expense_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS expense_item_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  transaction_id BIGINT UNSIGNED NOT NULL,
  expense_item_id BIGINT UNSIGNED NOT NULL,
  qty DECIMAL(18,3) NOT NULL DEFAULT 1,
  unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
  amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_eil_txn (transaction_id),
  KEY idx_eil_item (expense_item_id),
  FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
  FOREIGN KEY(expense_item_id) REFERENCES expense_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

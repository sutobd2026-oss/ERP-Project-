-- Suto Accounting ERP v118: Item Serial Number Tracking
ALTER TABLE items ADD COLUMN IF NOT EXISTS serial_tracked TINYINT(1) NOT NULL DEFAULT 0 AFTER barcode;

CREATE TABLE IF NOT EXISTS item_serials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  serial_number VARCHAR(191) NOT NULL,
  status ENUM('available','sold','void') NOT NULL DEFAULT 'available',
  purchase_transaction_id INT UNSIGNED NULL,
  sale_transaction_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_item_serial (company_id,item_id,serial_number),
  KEY idx_item_serial_status (company_id,item_id,status),
  KEY idx_item_serial_number (company_id,serial_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transaction_item_serials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  transaction_id INT UNSIGNED NOT NULL,
  transaction_item_id INT UNSIGNED NOT NULL,
  serial_id INT UNSIGNED NOT NULL,
  movement_type ENUM('purchase','sale','purchase_return','sale_return') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_txn_serial (transaction_id,serial_id),
  KEY idx_tis_company (company_id,transaction_id),
  KEY idx_tis_serial (serial_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

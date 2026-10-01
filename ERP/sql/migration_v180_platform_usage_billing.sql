ALTER TABLE companies MODIFY COLUMN plan_name VARCHAR(80) NOT NULL DEFAULT 'Free';
ALTER TABLE companies ADD COLUMN IF NOT EXISTS billing_cycle VARCHAR(20) NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS subscription_amount DECIMAL(14,2) NOT NULL DEFAULT 0;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS subscription_started_at DATETIME NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS plan_converted_at DATETIME NULL;
ALTER TABLE companies ADD COLUMN IF NOT EXISTS plan_converted_by INT UNSIGNED NULL;
UPDATE companies SET plan_name='Free' WHERE plan_name IS NULL OR plan_name='' OR plan_name='Trial';
CREATE TABLE IF NOT EXISTS company_billing_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  plan_name VARCHAR(80) NOT NULL,
  billing_cycle VARCHAR(20) NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  converted_by INT UNSIGNED NULL,
  note TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id),
  KEY idx_cbr_company(company_id,created_at),
  KEY idx_cbr_status(payment_status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

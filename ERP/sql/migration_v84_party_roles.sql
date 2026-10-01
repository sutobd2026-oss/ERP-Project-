-- Suto Accounting v84: unified Party roles
-- Run once before using the updated Party page.

ALTER TABLE parties ADD COLUMN opening_balance_type ENUM('receivable','payable','capital','loan_given','loan_taken') NOT NULL DEFAULT 'receivable' AFTER opening_balance;

-- Adds flexible role support while keeping the existing party_type
-- column compatible with Sales/Purchase flows.

CREATE TABLE IF NOT EXISTS party_roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  party_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(40) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_party_role (party_id, role),
  INDEX idx_party_role (role),
  CONSTRAINT fk_party_roles_party FOREIGN KEY (party_id) REFERENCES parties(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO party_roles (party_id, role)
SELECT id, 'customer' FROM parties WHERE party_type IN ('customer','both');

INSERT IGNORE INTO party_roles (party_id, role)
SELECT id, 'supplier' FROM parties WHERE party_type IN ('supplier','both');

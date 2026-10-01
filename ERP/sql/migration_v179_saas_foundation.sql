ALTER TABLE companies ADD COLUMN IF NOT EXISTS logo_path VARCHAR(255) NULL AFTER address;

CREATE TABLE IF NOT EXISTS company_subscriptions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscriber_company_id INT UNSIGNED NOT NULL,
  target_company_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  requested_by_user_id INT UNSIGNED NULL,
  approved_by_user_id INT UNSIGNED NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  approved_at DATETIME NULL,
  rejected_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uq_company_subscriber_target(subscriber_company_id,target_company_id),
  KEY idx_cs_target_status(target_company_id,status), KEY idx_cs_subscriber_status(subscriber_company_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_updates (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  update_type VARCHAR(30) NOT NULL DEFAULT 'text',
  title VARCHAR(191) NOT NULL,
  body TEXT NULL,
  product_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_cu_company(company_id,created_at), KEY idx_cu_status(status,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_update_reads (
  update_id BIGINT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(update_id,user_id), KEY idx_cur_company(company_id,read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS party_company_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  party_id INT UNSIGNED NOT NULL,
  linked_company_id INT UNSIGNED NOT NULL,
  relation_type VARCHAR(20) NOT NULL DEFAULT 'customer',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(id), UNIQUE KEY uq_party_company_link(company_id,party_id), KEY idx_pcl_linked(linked_company_id,relation_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_reviews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reviewer_company_id INT UNSIGNED NOT NULL,
  subject_company_id INT UNSIGNED NOT NULL,
  party_id INT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(id), KEY idx_cr_subject(subject_company_id,status,created_at), KEY idx_cr_reviewer(reviewer_company_id,created_at), KEY idx_cr_party(party_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

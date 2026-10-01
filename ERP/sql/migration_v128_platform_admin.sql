-- v128: dedicated Platform Control administrator
CREATE TABLE IF NOT EXISTS platform_admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    email VARCHAR(191) NULL,
    name VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY(id),
    UNIQUE KEY uq_pa_username(username),
    UNIQUE KEY uq_pa_email(email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial dedicated Platform Control account. Change the password after first login.
INSERT INTO platform_admins(username,email,name,password_hash,status)
SELECT 'platformadmin','platform@suto.bd','Suto Platform Admin','$2y$12$ewE20wVSVoyaqBc7EkR8NODwLUxiMpfHLelqnu/sdmuZckpoHMDd.','active'
WHERE NOT EXISTS (SELECT 1 FROM platform_admins);

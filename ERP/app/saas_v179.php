<?php
/**
 * sense SaaS foundation v179.
 * Internal company-to-company subscriptions, updates/notifications,
 * company logo, and customer/company review foundation.
 */
function ensure_saas_v179_schema(): void {
    static $done=false; if($done) return; $done=true; $pdo=db();
    try {
        $cols=$pdo->query("SHOW COLUMNS FROM companies")->fetchAll(PDO::FETCH_COLUMN,0);
        if(!in_array('logo_path',$cols,true)) $pdo->exec("ALTER TABLE companies ADD COLUMN logo_path VARCHAR(255) NULL AFTER address");
    } catch(Throwable $e) {}
    $tables = [
        "CREATE TABLE IF NOT EXISTS company_subscriptions (
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
            PRIMARY KEY(id),
            UNIQUE KEY uq_company_subscriber_target(subscriber_company_id,target_company_id),
            KEY idx_cs_target_status(target_company_id,status),
            KEY idx_cs_subscriber_status(subscriber_company_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS company_updates (
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
            PRIMARY KEY(id),
            KEY idx_cu_company(company_id,created_at),
            KEY idx_cu_status(status,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS company_update_reads (
            update_id BIGINT UNSIGNED NOT NULL,
            company_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(update_id,user_id),
            KEY idx_cur_company(company_id,read_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS party_company_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            party_id INT UNSIGNED NOT NULL,
            linked_company_id INT UNSIGNED NOT NULL,
            relation_type VARCHAR(20) NOT NULL DEFAULT 'customer',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_party_company_link(company_id,party_id),
            KEY idx_pcl_linked(linked_company_id,relation_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS company_reviews (
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
            PRIMARY KEY(id),
            KEY idx_cr_subject(subject_company_id,status,created_at),
            KEY idx_cr_reviewer(reviewer_company_id,created_at),
            KEY idx_cr_party(party_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    ];
    foreach($tables as $sql){ try{$pdo->exec($sql);}catch(Throwable $e){} }
}
function saas_company_search(int $cid,string $q,int $limit=20): array {
    $q=trim($q); if($q==='') return [];
    try {
        $pdo=db();
        $cols=$pdo->query("SHOW COLUMNS FROM companies")->fetchAll(PDO::FETCH_COLUMN,0);
        $hasStatus=in_array('account_status',$cols,true);
        $hasLogo=in_array('logo_path',$cols,true);
        $select='id,name,phone,business_type'.($hasLogo?',logo_path':'');
        $statusWhere=$hasStatus?" AND account_status<>'suspended'":'';
        $like='%'.$q.'%';
        $st=$pdo->prepare("SELECT $select FROM companies WHERE id<>?$statusWhere AND (name LIKE ? OR phone LIKE ? OR business_type LIKE ?) ORDER BY name LIMIT ".max(1,min(50,$limit)));
        $st->execute([$cid,$like,$like,$like]);
        $rows=$st->fetchAll();
        if(!$hasLogo){foreach($rows as &$r)$r['logo_path']=null;unset($r);}
        return $rows;
    } catch(Throwable $e) {
        return [];
    }
}
function saas_subscriptions_for(int $cid): array {
    $st=db()->prepare("SELECT cs.*,c.name target_name,c.logo_path target_logo FROM company_subscriptions cs JOIN companies c ON c.id=cs.target_company_id WHERE cs.subscriber_company_id=? ORDER BY cs.id DESC");
    $st->execute([$cid]); return $st->fetchAll();
}
function saas_incoming_requests(int $cid): array {
    $st=db()->prepare("SELECT cs.*,c.name subscriber_name,c.logo_path subscriber_logo FROM company_subscriptions cs JOIN companies c ON c.id=cs.subscriber_company_id WHERE cs.target_company_id=? AND cs.status='pending' ORDER BY cs.id DESC");
    $st->execute([$cid]); return $st->fetchAll();
}
function saas_approved_subscriber_company_ids(int $targetCid): array {
    $st=db()->prepare("SELECT subscriber_company_id FROM company_subscriptions WHERE target_company_id=? AND status='approved'");
    $st->execute([$targetCid]); return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
}
function saas_notify_company(int $targetCid,string $type,string $title,string $body,string $link=''): void {
    try{
        $st=db()->prepare("SELECT id FROM users WHERE company_id=? AND status='active'"); $st->execute([$targetCid]);
        $ins=db()->prepare('INSERT INTO notifications(company_id,user_id,type,title,body,link) VALUES(?,?,?,?,?,?)');
        foreach($st as $r){ try{$ins->execute([$targetCid,(int)$r['id'],$type,$title,$body,$link]);}catch(Throwable $e){ db()->prepare('INSERT INTO notifications(company_id,user_id,type,title,body) VALUES(?,?,?,?,?)')->execute([$targetCid,(int)$r['id'],$type,$title,$body]); } }
    }catch(Throwable $e){}
}
function saas_logotype(string $type): string {
    return match($type){'product'=>'Product','offer'=>'Offer','notice'=>'Notice','announcement'=>'Announcement',default=>'Post'};
}
function saas_company_logo_url(?string $path): ?string {
    if(!$path) return null;
    return url(ltrim($path,'/'));
}


/** v182: internal party notes and customer review support. */
function ensure_saas_v182_schema(): void {
    static $done=false; if($done) return; $done=true; $pdo=db();
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS party_notes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id INT UNSIGNED NOT NULL,
        party_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NULL,
        note TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(id),
        KEY idx_pn_party(company_id,party_id,created_at),
        KEY idx_pn_user(company_id,user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
}

<?php
declare(strict_types=1);
/* sense master: locked-sales-preview-toolbar-fixed-v1 + company-messaging-v230; preserves all prior updates */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/saas_v179.php';
require __DIR__ . '/saas_v184.php';
require __DIR__ . '/finance.php';
require __DIR__ . '/bundle.php';
ensure_saas_v179_schema();
ensure_saas_v182_schema();
ensure_saas_v184_schema();

/** v201: public customer reviews keyed by customer phone and backed by verified transactions. */
function ensure_customer_public_reviews_v201_schema(): void {
    static $done=false; if($done) return; $done=true; $pdo=db();
    $addCol=function(string $table,string $col,string $definition)use($pdo):void{
        try{ $cols=$pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN,0); if(!in_array($col,$cols,true))$pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition"); }
        catch(Throwable $e){ error_log('v201 schema '.$table.'.'.$col.': '.$e->getMessage()); }
    };
    $addCol('company_reviews','customer_phone','VARCHAR(32) NULL');
    $addCol('company_reviews','verified_transaction_type','VARCHAR(32) NULL');
    $addCol('company_reviews','verified_transaction_id','BIGINT UNSIGNED NULL');
    try{$pdo->exec('ALTER TABLE company_reviews MODIFY COLUMN subject_company_id INT UNSIGNED NULL');}catch(Throwable $e){}
    try{$pdo->exec("UPDATE company_reviews cr JOIN parties p ON p.id=cr.party_id SET cr.customer_phone=p.phone WHERE (cr.customer_phone IS NULL OR cr.customer_phone='') AND p.phone IS NOT NULL AND p.phone<>''");}catch(Throwable $e){error_log('v201 backfill customer_phone: '.$e->getMessage());}
    try{$pdo->exec('CREATE INDEX idx_cr_customer_phone ON company_reviews(customer_phone,status,created_at)');}catch(Throwable $e){}
}
ensure_customer_public_reviews_v201_schema();

/** v203: party private notes table used by the Parties list/detail UI.
 * Older installs may not have this table yet; create it defensively so the
 * Parties page cannot fail when note status/counts are queried.
 */
function ensure_party_notes_v203_schema(): void {
    static $done=false; if($done) return; $done=true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS party_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            party_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NULL,
            note TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_party_notes_company_party (company_id, party_id),
            KEY idx_party_notes_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch(Throwable $e) {
        error_log('v203 party_notes schema: '.$e->getMessage());
    }
}
ensure_party_notes_v203_schema();


/** v230: company-to-company messaging, conversation oversight, and defensive schema. */
function ensure_messages_v230_schema(): void {
    static $done=false; if($done) return; $done=true;
    try {
        $pdo=db();
        $cols=$pdo->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_COLUMN,0);
        if(!in_array('receiver_company_id',$cols,true)){
            $pdo->exec("ALTER TABLE messages ADD COLUMN receiver_company_id INT UNSIGNED NULL AFTER company_id");
        }
        try{$pdo->exec("CREATE INDEX idx_messages_receiver_company ON messages(receiver_company_id,created_at)");}catch(Throwable $e){}
        try{$pdo->exec("CREATE INDEX idx_messages_company_users ON messages(company_id,sender_id,receiver_id,created_at)");}catch(Throwable $e){}
        // Existing messages were company-internal, so their receiver company is the sender company.
        try{$pdo->exec("UPDATE messages SET receiver_company_id=company_id WHERE receiver_company_id IS NULL") ;}catch(Throwable $e){}
    } catch(Throwable $e) {
        // Older installs may not have the table yet. Create a compatible table so Messages does not fail.
        try {
            db()->exec("CREATE TABLE IF NOT EXISTS messages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                company_id INT UNSIGNED NOT NULL,
                receiver_company_id INT UNSIGNED NULL,
                sender_id INT UNSIGNED NOT NULL,
                receiver_id INT UNSIGNED NOT NULL,
                body TEXT NOT NULL,
                read_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY(id),
                KEY idx_messages_company(company_id,created_at),
                KEY idx_messages_receiver_company(receiver_company_id,created_at),
                KEY idx_messages_receiver(receiver_id,read_at),
                KEY idx_messages_sender(sender_id,created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch(Throwable $e2) { error_log('v230 messages schema: '.$e2->getMessage()); }
    }
}
ensure_messages_v230_schema();

function verified_customer_transaction(PDO $pdo,int $companyId,int $partyId): ?array {
    $q=$pdo->prepare('SELECT id,txn_type,document_no FROM transactions WHERE company_id=? AND party_id=? AND deleted_at IS NULL AND txn_type IN ("sale","purchase","payment_in","payment_out") ORDER BY id DESC LIMIT 1');
    $q->execute([$companyId,$partyId]); if($r=$q->fetch()) return ['id'=>(int)$r['id'],'type'=>(string)$r['txn_type'],'document_no'=>(string)($r['document_no']??'')];
    $q=$pdo->prepare('SELECT t.id,t.txn_type,t.document_no FROM transactions t WHERE t.company_id=? AND t.party_id=? AND t.txn_type="sale_order" AND t.deleted_at IS NULL AND (t.status="converted" OR EXISTS (SELECT 1 FROM transaction_links tl JOIN transactions dc ON dc.id=tl.to_transaction_id AND dc.company_id=tl.company_id AND dc.txn_type="delivery_challan" AND dc.deleted_at IS NULL JOIN transaction_links tl2 ON tl2.company_id=dc.company_id AND tl2.from_transaction_id=dc.id AND tl2.relation_type="challan_to_sale" JOIN transactions si ON si.id=tl2.to_transaction_id AND si.company_id=dc.company_id AND si.txn_type="sale" AND si.deleted_at IS NULL WHERE tl.company_id=t.company_id AND tl.from_transaction_id=t.id AND tl.relation_type="order_to_challan")) ORDER BY t.id DESC LIMIT 1');
    $q->execute([$companyId,$partyId]); if($r=$q->fetch()) return ['id'=>(int)$r['id'],'type'=>(string)$r['txn_type'],'document_no'=>(string)($r['document_no']??'')];
    $q=$pdo->prepare('SELECT t.id,t.txn_type,t.document_no FROM transactions t WHERE t.company_id=? AND t.party_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL AND (t.status="converted" OR EXISTS (SELECT 1 FROM transaction_links tl JOIN transactions si ON si.id=tl.to_transaction_id AND si.company_id=tl.company_id AND si.txn_type="sale" AND si.deleted_at IS NULL WHERE tl.company_id=t.company_id AND tl.from_transaction_id=t.id AND tl.relation_type="challan_to_sale")) ORDER BY t.id DESC LIMIT 1');
    $q->execute([$companyId,$partyId]); if($r=$q->fetch()) return ['id'=>(int)$r['id'],'type'=>(string)$r['txn_type'],'document_no'=>(string)($r['document_no']??'')];
    return null;
}

/** v196: defensive company-network schema repair.  Existing installs may have older
 * versions of the SaaS tables; normalize the columns needed by company-network without
 * failing the whole request. */
function ensure_company_network_v196_schema(): void {
    static $done = false; if ($done) return; $done = true;
    $pdo = db();
    $addCol = function(string $table, string $col, string $definition) use ($pdo): void {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN,0);
            if (!in_array($col, $cols, true)) $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $definition");
        } catch (Throwable $e) { error_log('v196 schema '.$table.'.'.$col.': '.$e->getMessage()); }
    };
    // Core company fields used by network search and logos.
    $addCol('companies','logo_path','VARCHAR(255) NULL');
    $addCol('companies','account_status',"VARCHAR(20) NOT NULL DEFAULT 'active'");

    // Existing tables may predate the SaaS fields used by the current network UI.
    foreach ([
        ['company_updates','product_id','INT UNSIGNED NULL'],
        ['company_updates','status',"VARCHAR(20) NOT NULL DEFAULT 'published'"],
        ['company_updates','updated_at','DATETIME NULL'],
        ['company_update_reads','company_id','INT UNSIGNED NOT NULL'],
        ['company_update_reads','user_id','INT UNSIGNED NOT NULL'],
        ['party_company_links','relation_type',"VARCHAR(20) NOT NULL DEFAULT 'customer'"],
        ['party_company_links','created_by','INT UNSIGNED NULL'],
        ['company_reviews','status',"VARCHAR(20) NOT NULL DEFAULT 'published'"],
        ['company_reviews','created_by','INT UNSIGNED NULL'],
        ['company_reviews','updated_at','DATETIME NULL'],
    ] as $c) $addCol($c[0],$c[1],$c[2]);

    // Make sure small foundation tables exist. Existing data is left untouched.
    $creates = [
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
            PRIMARY KEY(id), UNIQUE KEY uq_company_subscriber_target(subscriber_company_id,target_company_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
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
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY idx_cu_company(company_id,created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS company_update_reads (
            update_id BIGINT UNSIGNED NOT NULL,
            company_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(update_id,user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS party_company_links (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            party_id INT UNSIGNED NOT NULL,
            linked_company_id INT UNSIGNED NOT NULL,
            relation_type VARCHAR(20) NOT NULL DEFAULT 'customer',
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id), UNIQUE KEY uq_party_company_link(company_id,party_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
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
            updated_at DATETIME NULL,
            PRIMARY KEY(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    foreach($creates as $sql){ try{$pdo->exec($sql);}catch(Throwable $e){error_log('v196 table repair: '.$e->getMessage());} }
}
ensure_company_network_v196_schema();

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = trim($requestPath, '/');
$base = trim((string)($config['app']['base_url'] ?? ''), '/');
if ($base !== '') {
    if ($route === $base) $route = '';
    elseif (str_starts_with($route, $base.'/')) $route = trim(substr($route, strlen($base)), '/');
}
$route = $route ?: 'dashboard';

/** v125: platform control / SaaS operations schema. */
function ensure_platform_schema(): void {
    static $done=false; if($done)return; $done=true; $pdo=db();
    try{
        $cols=$pdo->query("SHOW COLUMNS FROM companies")->fetchAll(PDO::FETCH_COLUMN,0);
        if(!in_array('account_status',$cols,true))$pdo->exec("ALTER TABLE companies ADD COLUMN account_status VARCHAR(20) NOT NULL DEFAULT 'active'");
        if(!in_array('plan_name',$cols,true))$pdo->exec("ALTER TABLE companies ADD COLUMN plan_name VARCHAR(80) NOT NULL DEFAULT 'Trial'");
        if(!in_array('subscription_expires_at',$cols,true))$pdo->exec("ALTER TABLE companies ADD COLUMN subscription_expires_at DATETIME NULL");
    }catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS platform_admins (id INT UNSIGNED NOT NULL AUTO_INCREMENT,username VARCHAR(100) NOT NULL,email VARCHAR(191) NULL,name VARCHAR(150) NOT NULL,password_hash VARCHAR(255) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',last_login_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY uq_pa_username(username),UNIQUE KEY uq_pa_email(email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");$n=(int)$pdo->query('SELECT COUNT(*) FROM platform_admins')->fetchColumn();if($n===0){$pdo->prepare('INSERT INTO platform_admins(username,email,name,password_hash,status) VALUES(?,?,?,?,"active")')->execute(['platformadmin','platform@suto.bd','Suto Platform Admin','$2y$12$ewE20wVSVoyaqBc7EkR8NODwLUxiMpfHLelqnu/sdmuZckpoHMDd.']);}}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS platform_login_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id INT UNSIGNED NOT NULL,company_id INT UNSIGNED NOT NULL,ip_address VARCHAR(64) NULL,user_agent VARCHAR(500) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_ple_company(company_id,created_at),KEY idx_ple_user(user_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS platform_sessions (session_id VARCHAR(191) NOT NULL,user_id INT UNSIGNED NOT NULL,company_id INT UNSIGNED NOT NULL,last_seen_at DATETIME NOT NULL,created_at DATETIME NOT NULL,ip_address VARCHAR(64) NULL,user_agent VARCHAR(500) NULL,PRIMARY KEY(session_id),KEY idx_ps_company(company_id,last_seen_at),KEY idx_ps_last_seen(last_seen_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS platform_announcements (id INT UNSIGNED NOT NULL AUTO_INCREMENT,title VARCHAR(191) NOT NULL,body TEXT NOT NULL,type VARCHAR(20) NOT NULL DEFAULT 'notice',target_type VARCHAR(20) NOT NULL DEFAULT 'all',target_company_id INT UNSIGNED NULL,priority INT NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,starts_at DATETIME NOT NULL,ends_at DATETIME NULL,created_by INT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_pa_active(is_active,starts_at,ends_at),KEY idx_pa_company(target_company_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS platform_announcement_reads (announcement_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,read_at DATETIME NOT NULL,PRIMARY KEY(announcement_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NULL,subject VARCHAR(191) NOT NULL,category VARCHAR(40) NOT NULL DEFAULT 'bug',message TEXT NOT NULL,priority VARCHAR(20) NOT NULL DEFAULT 'normal',status VARCHAR(20) NOT NULL DEFAULT 'open',admin_reply TEXT NULL,admin_replied_by INT UNSIGNED NULL,admin_replied_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_st_company(company_id,created_at),KEY idx_st_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
}
ensure_platform_schema();
platform_touch_current_session();


/** v118: serial-number tracking schema. */
function ensure_serial_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo = db();
    try {
        if (!$pdo->query("SHOW COLUMNS FROM items LIKE 'serial_tracked'")->fetch()) {
            $pdo->exec("ALTER TABLE items ADD COLUMN serial_tracked TINYINT(1) NOT NULL DEFAULT 0 AFTER barcode");
        }
    } catch (Throwable $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS item_serials (id INT UNSIGNED NOT NULL AUTO_INCREMENT, company_id INT UNSIGNED NOT NULL, item_id INT UNSIGNED NOT NULL, serial_number VARCHAR(191) NOT NULL, status ENUM('available','sold','void') NOT NULL DEFAULT 'available', purchase_transaction_id INT UNSIGNED NULL, sale_transaction_id INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uq_item_serial(company_id,item_id,serial_number), KEY idx_item_serial_status(company_id,item_id,status), KEY idx_item_serial_number(company_id,serial_number)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS transaction_item_serials (id INT UNSIGNED NOT NULL AUTO_INCREMENT, company_id INT UNSIGNED NOT NULL, transaction_id INT UNSIGNED NOT NULL, transaction_item_id INT UNSIGNED NOT NULL, serial_id INT UNSIGNED NOT NULL, movement_type ENUM('purchase','sale','purchase_return','sale_return') NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id), UNIQUE KEY uq_txn_serial(transaction_id,serial_id), KEY idx_tis_company(company_id,transaction_id), KEY idx_tis_serial(serial_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {}
}
ensure_serial_schema();

/** v175: Product Request module. Request-only records do not post accounting/stock until conversion to Sale. */
function ensure_product_request_schema(): void {
    static $done=false;
    if($done)return;
    $done=true;
    $pdo=db();
    try{
        $pdo->exec("CREATE TABLE IF NOT EXISTS product_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            company_id INT UNSIGNED NOT NULL,
            party_id INT UNSIGNED NOT NULL,
            request_no VARCHAR(100) NOT NULL,
            request_date DATETIME NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'requested',
            notes TEXT NULL,
            sale_transaction_id INT UNSIGNED NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            UNIQUE KEY uq_pr_company_no(company_id,request_no),
            KEY idx_pr_company_status(company_id,status),
            KEY idx_pr_company_date(company_id,request_date),
            KEY idx_pr_party(company_id,party_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS product_request_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            request_id BIGINT UNSIGNED NOT NULL,
            company_id INT UNSIGNED NOT NULL,
            item_id INT UNSIGNED NOT NULL,
            qty DECIMAL(18,6) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_pri_request(request_id),
            KEY idx_pri_item(company_id,item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }catch(Throwable $e){}
}
ensure_product_request_schema();
function normalize_serials(string $raw): array {
    $parts = preg_split('/[\r\n,;]+/', $raw) ?: []; $out=[]; $seen=[];
    foreach($parts as $part){$v=trim((string)$part); if($v==='')continue; $key=function_exists('mb_strtolower')?mb_strtolower($v,'UTF-8'):strtolower($v); if(isset($seen[$key]))continue; $seen[$key]=true; $out[]=$v;}
    return $out;
}

function nav(string $title, string $href, bool $active=false, string $icon='•'): void { echo '<a class="nav-item '.($active?'active':''). '" href="'.e(url($href)).'"><span class="nav-icon">'.e($icon).'</span><span>'.e($title).'</span></a>'; }
function page_start(string $title): void {
    global $config;
    $u = user();
    global $route;
    $active = $route;
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> · <?=e($config['app']['name'])?></title><meta name="base-url" content="<?=e(base_url())?>"><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>?v=144">
<style>
.party-status-pills-v203{display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-top:3px;line-height:1}
.party-status-pill-v203{display:inline-flex;align-items:center;gap:2px;padding:2px 6px;border-radius:999px;font-size:9.5px;font-weight:600;white-space:nowrap;line-height:1.2}
.party-status-pill-v203.note{background:#f1f8ff;color:#1474c4;border:1px solid #d7ebfb}
.party-status-pill-v203.review{background:#fff8e7;color:#a86d00;border:1px solid #f7e4ae}
.party-status-pill-v203.review.muted{background:#f6f8fa;color:#8a97a8;border-color:#e5e9ee}
@media(max-width:700px){.party-status-pills-v203{gap:3px}.party-status-pill-v203{font-size:9px;padding:2px 5px}}
</style>
<style>.row-remove-btn{width:28px;height:28px;border:1px solid #d9e0ea;border-radius:7px;background:#fff;color:#6b7280;cursor:pointer;font-size:18px;line-height:24px;display:inline-flex;align-items:center;justify-content:center;padding:0}.row-remove-btn:hover{background:#fff1f2;border-color:#fecdd3;color:#dc2626}</style></head><body><div class="app-shell">
    <aside class="sidebar"><div class="brandbar"><div class="brandmark">S</div><div class="brandinfo"><div class="brandtitle"><?=e($u['company_name'] ?? $config['app']['name'])?></div><div class="branduser"><?=e($u['name'] ?? '')?></div><div class="brandrole"><?=e(ucwords(str_replace('_',' ',(string)($u['role'] ?? 'User'))))?></div><a class="brandlogout" href="<?=e(url('logout'))?>">Logout</a></div><div class="brandchev">›</div></div><nav class="nav">
    <?php nav('Home','dashboard',$active==='dashboard','⌂'); nav('Parties','parties',$active==='parties','♟'); nav('Items','items',$active==='items','▣'); ?>
    <details class="nav-section" <?=in_array($active,['sales','sale-new','payment-in','delivery-challans','product-requests','product-request-new','sale-order','quotations','sale-return'],true)?'open':''?>><summary><span class="nav-icon">▤</span><span>Sale</span></summary><div class="submenu"><?php nav('Sales Invoices','sales',$active==='sales',''); nav('Estimate / Quotation','quotations',$active==='quotations',''); nav('Payment In','payment-in',$active==='payment-in',''); nav('Sale Order','sale-order',$active==='sale-order',''); nav('Delivery Challan','delivery-challans',$active==='delivery-challans',''); nav('Product Request','product-requests',$active==='product-requests',''); nav('Sale Return / Cr. Note','sale-return',$active==='sale-return',''); ?></div></details>
    <details class="nav-section" <?=in_array($active,['purchase','purchase-new','payment-out','purchase-order','purchase-return'],true)?'open':''?>><summary><span class="nav-icon">🛒</span><span>Purchase</span></summary><div class="submenu"><?php nav('Purchase Bills','purchase',$active==='purchase',''); nav('Payment Out','payment-out',$active==='payment-out',''); nav('Purchase Order','purchase-order',$active==='purchase-order',''); nav('Purchase Return / Dr. Note','purchase-return',$active==='purchase-return',''); ?></div></details>
    <?php nav('Expense','expense',$active==='expense','▣'); ?>
    <details class="nav-section" <?=in_array($active,['bank-accounts','cash','cheques','loans'],true)?'open':''?>><summary><span class="nav-icon">▤</span><span>Cash & Bank</span></summary><div class="submenu"><?php nav('Bank Account','bank-accounts',$active==='bank-accounts',''); nav('Cash In Hand','cash',$active==='cash',''); nav('Cheques','cheques',$active==='cheques',''); nav('Loan Accounts','loans',$active==='loans',''); ?></div></details>
    <?php nav('Company Network','company-network',$active==='company-network','◎'); ?>
    <details class="nav-section" <?=in_array($active,['reports','transactions','audit-log'],true)?'open':''?>><summary><span class="nav-icon">▥</span><span>Reports</span></summary><div class="submenu"><?php nav('All Reports','reports',$active==='reports',''); nav('Transactions','transactions',$active==='transactions',''); nav('Audit Log','audit-log',$active==='audit-log',''); ?></div></details>
    <details class="nav-section" <?=in_array($active,['utilities','barcode','import-items','bulk-update','import-parties','export-items','recycle-bin','backup','financial-year'],true)?'open':''?>><summary><span class="nav-icon">🔧</span><span>Utilities</span></summary><div class="submenu"><?php nav('Generate Barcode','barcode',$active==='barcode',''); nav('Import Items','import-items',$active==='import-items',''); nav('Bulk Update Item','bulk-update',$active==='bulk-update',''); nav('Import Parties','import-parties',$active==='import-parties',''); nav('Export Item','export-items',$active==='export-items',''); nav('Recycle Bin','recycle-bin',$active==='recycle-bin',''); nav('Backup / Restore','backup',$active==='backup',''); nav('Close Financial Year','financial-year',$active==='financial-year',''); ?></div></details>
    <?php nav('Support','support',$active==='support','?'); nav('Setting','settings',$active==='settings','⚙'); ?></nav><div class="sidebar-collapse-wrap"><button type="button" id="sidebarCollapseBtn" class="sidebar-collapse-btn" aria-expanded="true" aria-label="Collapse menu"><span class="collapse-icon">‹</span><span class="collapse-label">Collapse Menu</span></button></div></aside>
    <main class="main"><header class="topbar"><div class="searchbar"><span>⌕</span><input id="globalSearch" placeholder="Search Transactions"></div><div class="top-actions"><a class="header-notify" href="<?=e(url('messages'))?>">✉ <span><?=unread_messages_count((int)$u['id'])?></span></a><a class="header-notify" href="<?=e(url('notifications'))?>">🔔 <span><?=unread_notifications_count((int)$u['id'])?></span></a><a class="btn sale" href="<?=e(url('sale-new'))?>">⊕ Add Sale</a><a class="btn purchase" href="<?=e(url('purchase-new'))?>">⊕ Add Purchase</a><button class="btn more" id="addMoreBtn" type="button">⊕ Add More</button><a class="gear" href="<?=e(url('settings'))?>">⚙</a></div><div id="addMore" class="add-more"><div><strong>SALE</strong><a href="<?=e(url('sale-new'))?>">▸ Sale Invoice <span class="kbd">ALT + S</span></a><a href="<?=e(url('payment-in'))?>">▸ Payment-In <span class="kbd">ALT + I</span></a><a href="<?=e(url('sale-return'))?>">▸ Sale Return <span class="kbd">ALT + R</span></a><a href="<?=e(url('sale-order'))?>">▸ Sale Order <span class="kbd">ALT + F</span></a><a href="<?=e(url('quotations'))?>">▸ Estimate / Quotation <span class="kbd">ALT + M</span></a><a href="<?=e(url('delivery-challans'))?>">▸ Delivery Challan <span class="kbd">ALT + D</span></a><a href="<?=e(url('product-requests'))?>">▸ Product Request</a></div><div><strong>PURCHASE</strong><a href="<?=e(url('purchase-new'))?>">▸ Purchase Bill <span class="kbd">ALT + P</span></a><a href="<?=e(url('payment-out'))?>">▸ Payment-Out <span class="kbd">ALT + O</span></a><a href="<?=e(url('purchase-return'))?>">▸ Purchase Return <span class="kbd">ALT + L</span></a><a href="<?=e(url('purchase-order'))?>">▸ Purchase Order <span class="kbd">ALT + G</span></a></div><div><strong>OTHERS</strong><a href="<?=e(url('expense'))?>">▸ Expenses <span class="kbd">ALT + E</span></a><a href="#">▸ Party To Party Transfer <span class="kbd">ALT + J</span></a></div><div class="menu-footer">Shortcut to open this menu: <b>Ctrl</b> + <b>Enter</b></div></div></header><div class="content"><?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach; }
function party_search_field(string $label,string $role,int $selectedId=0,string $selectedName='',string $selectedPhone='',bool $required=true): void {
    if($selectedId>0 && $selectedName===''){
        $st=db()->prepare('SELECT name,phone FROM parties WHERE id=? AND company_id=? AND deleted_at IS NULL LIMIT 1');
        $u=user(); $st->execute([$selectedId,(int)$u['company_id']]); $r=$st->fetch();
        if($r){$selectedName=(string)$r['name'];$selectedPhone=(string)($r['phone']??'');}
    }
    $display=trim($selectedName.($selectedPhone!==''?' — '.$selectedPhone:''));
    ?>
    <div class="form-group party-live-search" data-party-role="<?=e($role)?>">
      <label><?=e($label)?><?= $required?'*':'' ?></label>
      <div class="party-search-wrap">
        <input type="text" class="party-search-input" placeholder="Search by Name / Phone" value="<?=e($display)?>" autocomplete="off">
        <input type="hidden" name="party_id" id="<?=e($role==='supplier'?'paymentParty':'partyPartyId')?>" class="party-search-id" value="<?= $selectedId>0?(int)$selectedId:'' ?>" <?= $required?'required':'' ?>>
        <button type="button" class="party-search-clear" title="Clear" aria-label="Clear" <?= $selectedId>0?'':'style="display:none"'?>>×</button>
      </div>
      <div class="party-search-results" hidden></div>
      <div class="subtle party-search-hint">Type at least 2 characters to search.</div>
    </div>
    <?php
}
function item_search_field(int $selectedId=0,string $selectedName='',string $selectedCode='',string $priceMode='sale'): void {
    if($selectedId>0 && $selectedName===''){
        $st=db()->prepare('SELECT name,code,barcode FROM items WHERE id=? AND company_id=? AND active=1 LIMIT 1');
        $u=user(); $st->execute([$selectedId,(int)$u['company_id']]); $r=$st->fetch();
        if($r){$selectedName=(string)$r['name'];$selectedCode=(string)($r['code']??'');}
    }
    $display=$selectedName . ($selectedCode!==''?' · '.$selectedCode:'');
    ?>
    <div class="item-live-search" data-price-mode="<?=e($priceMode)?>">
      <div class="item-search-wrap">
        <input type="text" class="item-search-input" placeholder="Search item by name, code or barcode" value="<?=e($display)?>" autocomplete="off">
        <button type="button" class="item-search-clear" title="Clear" aria-label="Clear" <?= $selectedId>0?'':'style="display:none"'?>>×</button>
      </div>
      <div class="item-search-results" hidden></div>
    </div>
    <?php
}

function render_inline_creation_modals(): void {
    global $u;
    $cid=(int)$u['company_id'];
    $pdo=db();
    $cats=$pdo->query('SELECT id,name,type FROM categories WHERE company_id='.(int)$cid.' ORDER BY type,name')->fetchAll();
    $units=$pdo->query('SELECT id,name,symbol FROM units WHERE company_id='.(int)$cid.' ORDER BY name')->fetchAll();
    $roles=[
      'customer'=>'Customer','supplier'=>'Supplier','investor'=>'Investor','lender'=>'Lender',
      'borrower'=>'Borrower','employee'=>'Employee','other'=>'Other'
    ];
    ?>
    <div class="modal-backdrop" id="senseInlinePartyModal" onclick="if(event.target===this)senseCloseInlineModal('senseInlinePartyModal')">
      <div class="modal" style="width:min(760px,calc(100vw - 28px));max-height:calc(100vh - 28px);overflow:auto">
        <div class="modal-head"><h2 id="senseInlinePartyTitle">Add Party</h2><button class="close" type="button" onclick="senseCloseInlineModal('senseInlinePartyModal')">×</button></div>
        <form id="senseInlinePartyForm" method="post" action="<?=e(url('inline-party-create'))?>">
          <div class="form-body">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <div class="grid2">
              <div class="form-group"><label>Party Name*</label><input name="name" required></div>
              <div class="form-group"><label>Phone Number*</label><input name="phone" required maxlength="11" inputmode="numeric" pattern="(013|014|015|016|017|018|019)[0-9]{8}" placeholder="01712345678"></div>
              <div class="form-group"><label>Email ID</label><input type="email" name="email"></div>
              <div class="form-group span2"><label>Party Role(s)*</label><div class="party-role-grid"><?php foreach($roles as $rv=>$rl):?><label class="check-role"><input type="checkbox" name="party_roles[]" value="<?=e($rv)?>"><span><?=e($rl)?></span></label><?php endforeach;?></div><div class="subtle">A party can have multiple roles.</div></div>
              <div class="form-group span2"><label>Billing / Contact Address</label><textarea name="address" rows="3"></textarea></div>
              <div class="form-group"><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="0"></div>
              <div class="form-group"><label>Opening Balance Type</label><select name="opening_balance_type"><option value="receivable">Receivable</option><option value="payable">Payable</option><option value="capital">Investment / Capital</option><option value="loan_given">Loan Given</option><option value="loan_taken">Loan Taken</option></select></div>
              <div class="form-group"><label>Credit Limit</label><input type="number" step="0.01" name="credit_limit" value="0"></div>
            </div>
            <div class="subtle sense-inline-error" id="senseInlinePartyError" style="display:none;color:#b91c1c;margin-top:10px"></div>
          </div>
          <div class="form-footer"><button type="button" class="btn" onclick="senseCloseInlineModal('senseInlinePartyModal')">Cancel</button><button class="btn primary" type="submit" id="senseInlinePartySubmit">Save Party</button></div>
        </form>
      </div>
    </div>

    <div class="modal-backdrop" id="senseInlineProductModal" onclick="if(event.target===this)senseCloseInlineModal('senseInlineProductModal')">
      <div class="modal" style="width:min(780px,calc(100vw - 28px));max-height:calc(100vh - 28px);overflow:auto">
        <div class="modal-head"><h2>Add Product</h2><button class="close" type="button" onclick="senseCloseInlineModal('senseInlineProductModal')">×</button></div>
        <form id="senseInlineProductForm" method="post" action="<?=e(url('inline-product-create'))?>">
          <div class="form-body">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <div class="grid2">
              <div class="form-group span2"><label>Product Name*</label><input name="name" required></div>
              <div class="form-group"><label>Category</label><select name="category_id"><option value="">Select Category</option><?php foreach($cats as $c):?><option value="<?=((int)$c['id'])?>"><?=e($c['name'])?><?=!empty($c['type'])?' ('.e($c['type']).')':''?></option><?php endforeach;?></select></div>
              <div class="form-group"><label>Unit</label><select name="unit_id"><option value="">Select Unit</option><?php foreach($units as $x):?><option value="<?=((int)$x['id'])?>"><?=e($x['name'])?><?=!empty($x['symbol'])?' ('.e($x['symbol']).')':''?></option><?php endforeach;?></select></div>
              <div class="form-group"><label>Item Code</label><input name="code"></div>
              <div class="form-group"><label>Sale Price</label><input type="number" step="0.01" min="0" name="sale_price" value="0"></div>
              <div class="form-group"><label>Wholesale Price</label><input type="number" step="0.01" min="0" name="wholesale_price" value="0"></div>
              <div class="form-group"><label>Minimum Wholesale Qty</label><input type="number" step="0.01" min="0" name="min_wholesale_qty" value="0"></div>
              <div class="form-group"><label>Purchase Price</label><input type="number" step="0.01" min="0" name="purchase_price" value="0"></div>
              <div class="form-group"><label>Opening Stock</label><input type="number" step="0.01" min="0" name="opening_stock" value="0"></div>
              <div class="form-group"><label>Low Stock Limit</label><input type="number" step="0.01" min="0" name="low_stock_limit" value="0"></div>
              <div class="form-group span2"><label><input type="checkbox" name="serial_tracked" value="1"> Enable Serial Number Tracking</label></div>
              <div class="form-group span2"><label>Description</label><textarea name="description" rows="3"></textarea></div>
            </div>
            <div class="subtle sense-inline-error" id="senseInlineProductError" style="display:none;color:#b91c1c;margin-top:10px"></div>
          </div>
          <div class="form-footer"><button type="button" class="btn" onclick="senseCloseInlineModal('senseInlineProductModal')">Cancel</button><button class="btn primary" type="submit" id="senseInlineProductSubmit">Save Product</button></div>
        </form>
      </div>
    </div>
    <script>
    (function(){
      const $=s=>document.querySelector(s);
      function showModal(id){const m=document.getElementById(id);if(!m)return;m.classList.add('show');m.style.display='flex';m.style.position='fixed';m.style.inset='0';m.style.zIndex='2147483000';m.style.alignItems='center';m.style.justifyContent='center';}
      window.senseCloseInlineModal=function(id){const m=document.getElementById(id);if(!m)return;m.classList.remove('show');m.style.display='none';};
      window.senseOpenInlinePartyModal=function(role){
        const f=document.getElementById('senseInlinePartyForm');if(!f)return;
        f.reset();
        document.querySelectorAll('#senseInlinePartyForm input[name="party_roles[]"]').forEach(x=>x.checked=(x.value===role));
        const err=document.getElementById('senseInlinePartyError');if(err){err.style.display='none';err.textContent='';}
        showModal('senseInlinePartyModal');
        setTimeout(()=>f.querySelector('input[name="name"]')?.focus(),60);
      };
      window.senseOpenInlineProductModal=function(rowsSelector,addAction){
        window.SenseInlineProductTarget={rowsSelector:String(rowsSelector||''),addAction:String(addAction||'')};
        const f=document.getElementById('senseInlineProductForm');if(!f)return;f.reset();
        const err=document.getElementById('senseInlineProductError');if(err){err.style.display='none';err.textContent='';}
        showModal('senseInlineProductModal');
        setTimeout(()=>f.querySelector('input[name="name"]')?.focus(),60);
      };
      function currentTarget(){
        const cfg=window.SenseInlineProductTarget||{};const body=document.querySelector(cfg.rowsSelector||'');
        if(!body)return null;
        let row=[...body.querySelectorAll('tr')].find(r=>!r.querySelector('.item-source-select')?.value);
        if(!row && cfg.addAction){
          if(cfg.addAction==='sale' && typeof window.addRow==='function')window.addRow('sale');
          else if(cfg.addAction==='purchase' && typeof window.addRow==='function')window.addRow('purchase');
          else if(cfg.addAction==='delivery' && typeof window.dcAddRow==='function')window.dcAddRow();
          else if(cfg.addAction==='doc' && typeof window.addDocRow==='function')window.addDocRow();
          else if(cfg.addAction==='pr' && typeof window.prAddRow==='function')window.prAddRow();
          row=[...body.querySelectorAll('tr')].find(r=>!r.querySelector('.item-source-select')?.value) || body.querySelector('tr:last-child');
        }
        return row||null;
      }
      function applyItem(item){
        const row=currentTarget(); if(!row)return;
        if(window.SutoInitItemSearch)window.SutoInitItemSearch(row);
        const sel=row.querySelector('.item-source-select');if(!sel)return;
        let opt=[...sel.options].find(o=>String(o.value)===String(item.id));
        if(!opt){opt=document.createElement('option');opt.value=String(item.id);sel.appendChild(opt);}
        opt.textContent=String(item.name||'');
        opt.dataset.sale=String(item.sale_price||0);opt.dataset.buy=String(item.purchase_price||0);const mode=row.querySelector('.item-live-search')?.dataset?.priceMode || (row.closest('.purchase-entry-form')?'purchase':'sale');opt.dataset.price=String(mode==='purchase'?(item.purchase_price||0):(item.sale_price||0));
        opt.dataset.unit=String(item.unit_symbol||'');opt.dataset.type='product';opt.dataset.serialTracked=String(item.serial_tracked||0);
        if(sel.classList.contains('dc-item')){opt.dataset.price=String(item.sale_price||0);opt.dataset.unit=String(item.unit_symbol||'');}
        sel.value=String(item.id);sel.dispatchEvent(new Event('change',{bubbles:true}));
        const inp=row.querySelector('.item-search-input');if(inp){inp.value=String(item.name||'')+(item.code?' · '+item.code:'');}
        const clear=row.querySelector('.item-search-clear');if(clear)clear.style.display='block';
        if(row.querySelector('.dc-price'))row.querySelector('.dc-price').value=String(item.sale_price||0);
        if(row.querySelector('.dc-unit'))row.querySelector('.dc-unit').textContent=String(item.unit_symbol||'—');
        if(row.querySelector('.pr-available') && typeof window.prCheckAvailability==='function')window.prCheckAvailability(row);
        if(typeof window.recalc==='function')window.recalc();
        if(typeof window.dcRecalc==='function')window.dcRecalc();
      }
      async function submitForm(form,errorId,submitId,onOk){
        const err=document.getElementById(errorId), btn=document.getElementById(submitId);if(err){err.style.display='none';err.textContent='';}
        if(btn)btn.disabled=true;
        try{
          const res=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}});
          const text=await res.text();let data={};try{data=JSON.parse(text);}catch(_){throw new Error('Server returned an unexpected response.');}
          if(!res.ok||!data.ok)throw new Error(data.error||('Request failed with HTTP '+res.status));
          onOk(data);
        }catch(e){if(err){err.textContent=e.message||'Could not save.';err.style.display='block';}}
        finally{if(btn)btn.disabled=false;}
      }
      document.getElementById('senseInlinePartyForm')?.addEventListener('submit',function(e){
        e.preventDefault();
        const f=this; submitForm(f,'senseInlinePartyError','senseInlinePartySubmit',data=>{
          const p=data.party, role=[...f.querySelectorAll('input[name="party_roles[]"]:checked')][0]?.value||'customer';
          document.querySelectorAll('.party-live-search[data-party-role="'+role+'"]').forEach(box=>{
            const input=box.querySelector('.party-search-input'),hidden=box.querySelector('.party-search-id'),clear=box.querySelector('.party-search-clear');
            if(hidden)hidden.value=String(p.id); if(input)input.value=p.name+(p.phone?' — '+p.phone:''); if(clear)clear.style.display='block'; if(hidden)hidden.dispatchEvent(new Event('change',{bubbles:true}));
          });
          senseCloseInlineModal('senseInlinePartyModal');
        });
      });
      document.getElementById('senseInlineProductForm')?.addEventListener('submit',function(e){
        e.preventDefault(); submitForm(this,'senseInlineProductError','senseInlineProductSubmit',data=>{applyItem(data.item);senseCloseInlineModal('senseInlineProductModal');});
      });
    })();
    </script>

    <script>
    // v2 fallback: make inline Add Party/Add Product reliable on both /ERP and sense.suto.bd root deployments, including sale-new?edit=... pages.
    (function(){
      const appRoot = location.pathname.startsWith('/ERP/') ? '/ERP/' : '/';
      const abs = p => new URL(appRoot + String(p).replace(/^\/+/,''), location.origin).href;
      const q = s => document.querySelector(s);
      const qa = s => Array.from(document.querySelectorAll(s));

      const partyForm = document.getElementById('senseInlinePartyForm');
      const productForm = document.getElementById('senseInlineProductForm');
      if (partyForm) partyForm.action = abs('inline-party-create');
      if (productForm) productForm.action = abs('inline-product-create');

      function show(id){
        const el=document.getElementById(id); if(!el)return;
        el.classList.add('show'); el.hidden=false; el.style.display='flex';
        el.style.position='fixed'; el.style.inset='0'; el.style.zIndex='2147483000';
        el.style.alignItems='center'; el.style.justifyContent='center';
      }
      function hide(id){
        const el=document.getElementById(id); if(!el)return;
        el.classList.remove('show'); el.hidden=true; el.style.display='none';
      }
      function setError(id,msg){const e=document.getElementById(id);if(!e)return;e.textContent=msg||'';e.style.display=msg?'block':'none';}

      if(typeof window.senseOpenInlinePartyModal !== 'function'){
        window.senseOpenInlinePartyModal=function(role){
          if(!partyForm)return;
          partyForm.reset();
          qa('#senseInlinePartyForm input[name="party_roles[]"]').forEach(x=>x.checked=(x.value===String(role||'customer')));
          setError('senseInlinePartyError',''); show('senseInlinePartyModal');
          setTimeout(()=>partyForm.querySelector('input[name="name"]')?.focus(),50);
        };
      }
      if(typeof window.senseCloseInlineModal !== 'function') window.senseCloseInlineModal=hide;

      if(typeof window.senseOpenInlineProductModal !== 'function'){
        window.senseOpenInlineProductModal=function(rowsSelector,addAction){
          window.SenseInlineProductTarget={rowsSelector:String(rowsSelector||''),addAction:String(addAction||'')};
          if(!productForm)return;
          productForm.reset(); setError('senseInlineProductError',''); show('senseInlineProductModal');
          setTimeout(()=>productForm.querySelector('input[name="name"]')?.focus(),50);
        };
      }

      function rowTarget(){
        const cfg=window.SenseInlineProductTarget||{};
        const body=q(cfg.rowsSelector||''); if(!body)return null;
        let row=qa((cfg.rowsSelector||'')+' tr').find(r=>!r.querySelector('.item-source-select')?.value);
        if(!row && cfg.addAction){
          try{
            if(cfg.addAction==='sale' || cfg.addAction==='purchase') window.addRow?.(cfg.addAction);
            else if(cfg.addAction==='delivery') window.dcAddRow?.();
            else if(cfg.addAction==='doc') window.addDocRow?.();
            else if(cfg.addAction==='pr') window.prAddRow?.();
          }catch(_){ }
          row=qa((cfg.rowsSelector||'')+' tr').find(r=>!r.querySelector('.item-source-select')?.value) || body.querySelector('tr:last-child');
        }
        return row||null;
      }
      function applyItem(item){
        const row=rowTarget(); if(!row)return;
        if(window.SutoInitItemSearch) try{window.SutoInitItemSearch(row);}catch(_){ }
        const sel=row.querySelector('.item-source-select'); if(!sel)return;
        let opt=Array.from(sel.options||[]).find(o=>String(o.value)===String(item.id));
        if(!opt){opt=document.createElement('option');opt.value=String(item.id);sel.appendChild(opt);}
        opt.textContent=String(item.name||'');
        opt.dataset.sale=String(item.sale_price||0); opt.dataset.buy=String(item.purchase_price||0);
        opt.dataset.price=String(row.closest('.purchase-entry-form') ? (item.purchase_price||0) : (item.sale_price||0));
        opt.dataset.unit=String(item.unit_symbol||''); opt.dataset.type='product'; opt.dataset.serialTracked=String(item.serial_tracked||0);
        if(sel.classList.contains('dc-item')) opt.dataset.price=String(item.sale_price||0);
        sel.value=String(item.id); sel.dispatchEvent(new Event('change',{bubbles:true}));
        const inp=row.querySelector('.item-search-input'); if(inp) inp.value=String(item.name||'')+(item.code?' · '+item.code:'');
        const clear=row.querySelector('.item-search-clear'); if(clear) clear.style.display='block';
        row.querySelector('.dc-price')?.setAttribute('value',String(item.sale_price||0));
        const dcP=row.querySelector('.dc-price'); if(dcP)dcP.value=String(item.sale_price||0);
        const dcU=row.querySelector('.dc-unit'); if(dcU)dcU.textContent=String(item.unit_symbol||'—');
        try{window.recalc?.();}catch(_){ } try{window.dcRecalc?.();}catch(_){ }
      }

      async function handle(form,errorId,buttonId,onOk){
        const btn=document.getElementById(buttonId), old=btn?.textContent; if(btn){btn.disabled=true;btn.textContent='Saving...';}
        setError(errorId,'');
        try{
          const r=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
          const txt=await r.text(); let data=null; try{data=JSON.parse(txt);}catch(_){throw new Error('Server returned an unexpected response.');}
          if(!r.ok||!data?.ok)throw new Error(data?.error||('Request failed with HTTP '+r.status));
          onOk(data);
        }catch(e){setError(errorId,e?.message||'Could not save.');}
        finally{if(btn){btn.disabled=false;btn.textContent=old||'Save';}}
      }

      if(partyForm && !partyForm.dataset.fallbackBound){
        partyForm.dataset.fallbackBound='1';
        partyForm.addEventListener('submit',function(e){
          e.preventDefault();
          handle(this,'senseInlinePartyError','senseInlinePartySubmit',data=>{
            const p=data.party||{};
            const role=qa('#senseInlinePartyForm input[name="party_roles[]"]:checked')[0]?.value||'customer';
            qa('.party-live-search[data-party-role="'+role+'"]').forEach(box=>{
              box.querySelector('.party-search-id')?.setAttribute('value',String(p.id||''));
              const hid=box.querySelector('.party-search-id'); if(hid)hid.value=String(p.id||'');
              const inp=box.querySelector('.party-search-input'); if(inp)inp.value=String(p.name||'')+(p.phone?' — '+p.phone:'');
              const clear=box.querySelector('.party-search-clear'); if(clear)clear.style.display='block';
            });
            hide('senseInlinePartyModal');
          });
        },true);
      }
      if(productForm && !productForm.dataset.fallbackBound){
        productForm.dataset.fallbackBound='1';
        productForm.addEventListener('submit',function(e){
          e.preventDefault(); handle(this,'senseInlineProductError','senseInlineProductSubmit',data=>{applyItem(data.item||{});hide('senseInlineProductModal');});
        },true);
      }
    })();
    </script>
    <?php
}

function page_end(): void {
    echo <<<'HTML'
<style>
.item-picker-cell{position:relative;min-width:250px}
.item-live-search{position:relative;width:100%}
.item-search-wrap{position:relative;display:flex;align-items:center}
.item-live-search .item-search-input{width:100%;box-sizing:border-box;padding:9px 36px 9px 10px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;color:#172033;outline:none}
.item-live-search .item-search-input:focus{border-color:#1d8cf8;box-shadow:0 0 0 2px rgba(29,140,248,.10)}
.item-search-clear{position:absolute;right:7px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#64748b;font-size:20px;line-height:1;cursor:pointer;padding:2px 6px}
.item-search-results{position:fixed;width:1120px;max-width:calc(100vw - 20px);max-height:340px;overflow-y:auto;overflow-x:auto;background:#fff;border:1px solid #d8e0ea;border-radius:8px;box-shadow:0 12px 28px rgba(15,23,42,.18);z-index:2147483647}
.item-search-grid{display:grid;width:100%;min-width:1080px;grid-template-columns:minmax(220px,1.6fr) 92px 105px 72px 115px 145px minmax(170px,1.4fr) 110px;gap:0;align-items:center;box-sizing:border-box}
.item-search-head{position:sticky;top:0;background:#f7f9fc;border-bottom:1px solid #e6ebf2;font-size:11px;color:#708090;font-weight:700;text-transform:uppercase;letter-spacing:.02em}
.item-search-cell{padding:8px 9px;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-right:1px solid #edf1f5}
.item-search-head .item-search-cell:last-child,.item-search-result .item-search-cell:last-child{border-right:0}
.item-search-result{display:block;width:100%;border:0;border-bottom:1px solid #eef2f7;background:#fff;text-align:left;padding:0;cursor:pointer;color:#1f2937}
.item-search-result:hover{background:#f4f8ff}
.item-search-note-text{color:#dc2626!important;font-weight:600}
.item-search-result:last-child{border-bottom:0}
.item-search-name{font-weight:600;color:#18324f}
.item-search-meta{font-size:11px;color:#7b8794;margin-top:2px}
.item-search-empty{padding:12px;color:#718096;font-size:13px}
.item-search-meta-cell{color:#536477;font-size:11px}
.item-stock-negative{color:#ef4444}.item-stock-positive{color:#10b981}.item-price{font-variant-numeric:tabular-nums}
.item-source-select{display:none!important}
.item-selected-summary{display:flex;align-items:center;gap:8px;min-height:20px;font-size:13px;color:#203047}
.item-selected-summary .item-selected-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.item-selected-summary .item-selected-code{font-size:11px;color:#7b8794;white-space:nowrap}
@media(max-width:900px){.item-search-results{width:calc(100vw - 20px);max-width:calc(100vw - 20px)}.item-search-grid{grid-template-columns:minmax(170px,1fr) 88px 96px 82px 70px 70px}.item-search-cell{padding:7px 6px;font-size:12px}}
.serial-entry-box{margin-top:7px;padding:8px 9px;border:1px solid #d9e3ef;border-radius:7px;background:#f8fbff}.serial-entry-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:5px}.serial-entry-head strong{font-size:12px;color:#18324f}.serial-number-input{width:100%;box-sizing:border-box;border:1px solid #cfd8e3;border-radius:6px;padding:6px 8px;font-size:12px;line-height:1.35;resize:vertical;min-height:42px}.serial-entry-meta{font-size:11px;color:#738297;margin-top:4px}.serial-entry-box[hidden]{display:none!important}.serial-tracking-toggle{margin:10px 0 6px;font-size:13px}.serial-tracking-toggle label{cursor:pointer;color:#203047}.serial-tracking-toggle .subtle{font-weight:400}
.serial-controls{display:flex;align-items:flex-start;gap:7px}.serial-entry-controls{display:flex;gap:7px;align-items:flex-start}.serial-entry-controls .serial-number-input{flex:1}.serial-picker-btn{white-space:nowrap;padding:7px 10px!important;font-size:12px!important}.serial-picker-panel{position:relative;margin-top:7px;border:1px solid #cfd8e3;border-radius:7px;background:#fff;box-shadow:0 8px 22px rgba(15,23,42,.10);padding:8px;z-index:20}.serial-picker-head{display:flex;justify-content:space-between;align-items:center;gap:8px;font-size:12px;color:#18324f;margin-bottom:7px}.serial-picker-close{border:0;background:transparent;font-size:18px;cursor:pointer;color:#64748b}.serial-picker-search{width:100%;box-sizing:border-box;border:1px solid #cfd8e3;border-radius:6px;padding:7px 8px;margin-bottom:7px}.serial-picker-results{max-height:190px;overflow:auto}.serial-picker-option{width:100%;display:flex;justify-content:space-between;align-items:center;padding:7px 8px;border:0;border-bottom:1px solid #eef2f7;background:#fff;cursor:pointer;text-align:left}.serial-picker-option:hover,.serial-picker-option.selected{background:#f4f8ff}.serial-picker-option span{font-size:12px;color:#203047}.serial-picker-option b{font-size:11px;color:#1d8cf8}.serial-picker-empty{padding:9px;font-size:12px;color:#718096}
.serial-entry-box{display:none!important}
.serial-trigger-btn{display:none;align-items:center;justify-content:center;gap:5px;min-width:66px;height:34px;padding:0 10px!important;border:1px solid #dbe5ef!important;background:#f8fbff!important;color:#1677e8!important;border-radius:6px!important;font-size:12px!important;font-weight:600!important;cursor:pointer}
.item-picker-cell.serial-ready .item-live-search{width:calc(100% - 74px)}
.item-picker-cell.serial-ready{display:flex;align-items:center;gap:8px}
.item-picker-cell.serial-ready .serial-trigger-btn{display:flex}
.item-picker-cell.serial-ready .item-live-search{flex:1;min-width:0}
.serial-entry-modal{position:fixed;inset:0;z-index:2147483600;display:flex;align-items:center;justify-content:center}
.serial-entry-modal[hidden]{display:none!important}
.serial-entry-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.48)}
.serial-entry-dialog{position:relative;width:min(400px,calc(100vw - 36px));min-height:560px;background:#fff;border-radius:8px;box-shadow:0 18px 60px rgba(15,23,42,.26);padding:24px;box-sizing:border-box;display:flex;flex-direction:column}
.serial-entry-x{position:absolute;top:14px;right:16px;border:0;background:transparent;color:#8b98a8;font-size:31px;line-height:1;cursor:pointer;padding:0}
.serial-entry-title{font-size:19px;font-weight:500;color:#27374a;padding-right:36px}
.serial-entry-item{margin-top:8px;color:#8a98aa;font-size:14px;font-weight:500;min-height:18px}
.serial-entry-sep{height:1px;background:#edf1f5;margin:12px 0 18px}
.serial-entry-label-row{display:flex;justify-content:space-between;align-items:center;font-size:13px;color:#1f2937;margin-bottom:5px}
.serial-entry-label-row strong{font-size:12px;color:#263240}
.serial-input-line{display:flex;gap:5px;align-items:center}
.serial-modal-input{flex:1;min-width:0;height:34px;box-sizing:border-box;border:1px solid #e3e7ed;background:#f7f7f7;border-radius:3px;padding:7px 9px;font-size:13px;color:#1f2937;outline:none}
.serial-modal-input:focus{border-color:#1680ea;background:#fff;box-shadow:0 0 0 1px rgba(22,128,234,.12)}
.serial-available-wrap{display:none;margin-top:2px}.serial-available-wrap.show{display:block}.serial-available-search{width:100%;height:34px;box-sizing:border-box;border:1px solid #dfe6ee;border-radius:4px;padding:7px 9px;font-size:13px;margin-bottom:8px;outline:none}.serial-available-search:focus{border-color:#1680ea;box-shadow:0 0 0 1px rgba(22,128,234,.12)}.serial-available-list{max-height:340px;overflow:auto;border:1px solid #edf1f5;border-radius:5px;background:#fff}.serial-available-option{display:flex;align-items:center;gap:10px;padding:9px 10px;border-bottom:1px solid #edf1f5;cursor:pointer;font-size:13px;color:#26384d}.serial-available-option:last-child{border-bottom:0}.serial-available-option:hover{background:#f6f9fc}.serial-available-option input{width:16px;height:16px;accent-color:#0f7dea}.serial-available-empty{padding:14px 10px;font-size:12px;color:#778599}.serial-available-loading{padding:14px 10px;font-size:12px;color:#778599}
.serial-add-btn{width:64px;height:34px;border:0;border-radius:4px;background:#0f7dea;color:#fff;font-size:20px;cursor:pointer;font-weight:700}
.serial-entered-list{display:flex;flex-direction:column;gap:6px;margin-top:10px;max-height:245px;overflow:auto}
.serial-entered-item{display:flex;align-items:center;justify-content:space-between;gap:8px;border:1px solid #e6ebf2;border-radius:5px;padding:7px 8px;background:#fafcff;font-size:12px;color:#26384d}
.serial-entered-item button{border:0;background:transparent;color:#8b98a8;font-size:17px;cursor:pointer;line-height:1}
.serial-entry-help{font-size:11px;color:#7e8b9a;margin-top:8px;min-height:16px}
.serial-modal-footer{margin-top:auto;padding-top:16px;display:flex;justify-content:flex-end;gap:18px}
.serial-modal-footer .btn{min-width:80px;justify-content:center}
@media(max-width:600px){.serial-entry-dialog{min-height:520px;padding:20px}.serial-entry-title{font-size:18px}.item-picker-cell.serial-ready{gap:5px}.serial-trigger-btn{min-width:60px;padding:0 8px!important}}
</style>
<style>
.platform-notices{display:flex;flex-direction:column;gap:10px;margin-bottom:12px}.platform-notice{display:flex;gap:10px;align-items:flex-start;background:#fff;border:1px solid #dce3ec;border-radius:10px;padding:11px 14px;box-shadow:0 3px 12px rgba(15,23,42,.05)}.platform-notice-icon{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;background:#1d8cf8;font-size:10px;flex:none}.platform-notice-title{font-weight:700;color:#18253a;margin-bottom:3px}.platform-notice-text{font-size:13px;color:#617086;line-height:1.45}.platform-notice-ad .platform-notice-icon{background:#f59e0b}.platform-notice-warning .platform-notice-icon{background:#ef4444}.platform-notice-info .platform-notice-icon{background:#10b981}
.party-edit-link-v110{font:inherit;color:inherit;background:transparent;border:0;padding:0;margin:0;text-align:left;display:block;width:100%;cursor:pointer}
</style>
<style>
.party-detail-card-v202{padding-bottom:12px}
.party-heading-v204{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.party-heading-v204 h2{margin:0}.party-contact-actions-v204{display:flex;align-items:center;gap:5px}.party-contact-actions-v204 a{display:inline-flex;align-items:center;gap:4px;text-decoration:none;color:#334155;font-size:12px;line-height:1;padding:2px 0}.party-contact-actions-v204 a:hover{color:#1680ea}.party-contact-actions-v204 .call{color:#334155}.party-contact-actions-v204 .whatsapp{color:#334155}.party-contact-actions-v204 .email{color:#334155}
.party-detail-top-v202{margin-bottom:8px}
.party-action-row-v202{display:flex;justify-content:flex-end;align-items:center;gap:6px;margin:0 0 7px}
.party-compact-btn-v202{padding:6px 9px!important;font-size:12px!important;min-height:30px}
.party-private-notes-v202{border:1px solid #e6edf5;border-radius:8px;background:#fafcff;padding:8px 10px;margin:4px 0 9px}
.party-compact-head-v202{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:5px}
.party-compact-head-v202>div{display:flex;align-items:baseline;gap:7px;min-width:0}
.party-compact-head-v202 b{font-size:12px;color:#1f334a;letter-spacing:.15px}
.party-compact-head-v202 span{font-size:11px;color:#8391a3;white-space:nowrap}
.party-link-btn-v202{border:0;background:transparent;padding:2px 0;color:#1976d2;font-size:11px;font-weight:600;cursor:pointer;white-space:nowrap}
.party-notes-list-v202{display:flex;flex-direction:column;gap:5px}
.party-note-row-v202{padding:5px 0 4px;border-top:1px solid #edf2f7;min-width:0}
.party-note-row-v202:first-child{border-top:0}
.party-note-text-v202{font-size:12px;line-height:1.35;color:#334155;white-space:pre-wrap;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden}
.party-note-row-v202 small{display:block;margin-top:2px;font-size:10px;color:#8a97a8}
.party-note-empty-v202,.party-more-v202{font-size:11px;color:#8a97a8;padding:2px 0}
.party-detail-grid-v202{gap:7px 22px!important}
.party-reviews-compact-v202{margin-top:9px;border:1px solid #e6edf5;border-radius:8px;background:#fff;overflow:hidden}
.party-reviews-compact-v202 .party-compact-head-v202{padding:8px 10px;margin:0;border-bottom:1px solid #edf2f7;background:#fafcff}
.party-reviews-list-v202{max-height:208px;overflow:auto}
.party-review-row-v202{padding:7px 10px;border-bottom:1px solid #edf2f7}
.party-review-row-v202:last-child{border-bottom:0}
.party-review-top-v202{display:flex;align-items:center;gap:7px;line-height:1.1}
.party-review-top-v202 strong{color:#f59e0b;font-size:12px;letter-spacing:.2px;white-space:nowrap}
.party-review-top-v202 span{font-size:11px;font-weight:600;color:#46566b;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.party-review-top-v202 small{margin-left:auto;font-size:10px;color:#8a97a8;white-space:nowrap}
.party-review-comment-v202{margin-top:3px;font-size:12px;line-height:1.35;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.party-review-meta-v202{display:block;margin-top:2px;color:#8a97a8;font-size:10px}
.party-review-empty-v202{padding:9px 10px;font-size:11px;color:#8a97a8}
@media(max-width:760px){.party-detail-top-v202{gap:8px}.party-compact-head-v202>div{gap:5px}.party-compact-head-v202 span{white-space:normal}.party-review-top-v202{gap:5px}.party-review-top-v202 small{margin-left:0}}
</style>
<style>
.party-search-wrap{position:relative;display:flex;align-items:center}
.party-live-search .party-search-input{width:100%;padding-right:38px}
.party-search-clear{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#64748b;font-size:20px;line-height:1;cursor:pointer;padding:2px 6px}
.party-search-results{position:fixed;min-width:280px;max-width:min(520px,calc(100vw - 24px));max-height:260px;overflow:auto;background:#fff;border:1px solid #d8e0ea;border-radius:10px;box-shadow:0 12px 30px rgba(15,23,42,.16);z-index:2147483647}
.party-search-results .party-result{display:block;width:100%;padding:10px 12px;text-align:left;border:0;border-bottom:1px solid #eef2f7;background:#fff;cursor:pointer}
.party-search-results .party-result:last-child{border-bottom:0}
.party-search-results .party-result:hover{background:#f5f9ff}
.party-result-name{font-weight:600;color:#18324f}
.party-result-meta{font-size:12px;color:#718096;margin-top:2px}
.party-search-empty{padding:12px;color:#718096;font-size:13px}
</style>
HTML;
    $itemApi=e(url('item-search-api'));
    $bundleApi=e(url('bundle-components-api'));
    echo '<script>window.SutoItemSearchConfig='.json_encode(['url'=>$itemApi],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';window.SutoBundleComponentsConfig='.json_encode(['url'=>$bundleApi],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';</script>';
    echo <<<'ITEMHTML'
<script>
function closeCashBankTransferV150(){var m=document.getElementById('cashBankTransferModalV150');if(!m)return;m.classList.remove('show');m.style.display='none';m.setAttribute('aria-hidden','true');}
function openCashBankTransferV150(dir){var m=document.getElementById('cashBankTransferModalV150');var f=document.getElementById('cashBankFromV150'),t=document.getElementById('cashBankToV150'),fh=document.getElementById('cashBankFromHiddenV150'),th=document.getElementById('cashBankToHiddenV150'),title=document.getElementById('cashBankTransferTitleV150');if(!m||!f||!t||!fh||!th)return;if(m.parentElement!==document.body)document.body.appendChild(m);if(dir==='deposit'){title.textContent='Deposit';f.value='cash';f.disabled=true;fh.value='cash';t.disabled=false;t.value='';th.value='';}else{title.textContent='Withdraw';f.disabled=false;f.value='';fh.value='';t.value='cash';t.disabled=true;th.value='cash';}m.classList.add('show');m.setAttribute('aria-hidden','false');m.style.display='flex';m.style.position='fixed';m.style.inset='0';m.style.zIndex='2147483640';m.style.alignItems='center';m.style.justifyContent='center';m.style.background='rgba(15,23,42,.58)';m.style.padding='24px 18px';m.style.boxSizing='border-box';}
(function(){var f=document.getElementById('cashBankFromV150'),t=document.getElementById('cashBankToV150'),fh=document.getElementById('cashBankFromHiddenV150'),th=document.getElementById('cashBankToHiddenV150');if(f)f.addEventListener('change',function(){if(!f.disabled)fh.value=f.value;});if(t)t.addEventListener('change',function(){if(!t.disabled)th.value=t.value;});})();
(function(){
  const cfg=window.SutoItemSearchConfig||{};
  let active=null;
  const timers=new WeakMap();
  const seqs=new WeakMap();
  function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
  function moneyNum(n){return Number(n||0).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2});}
  function placeResults(box){const input=box.querySelector('.item-search-input'),results=box.querySelector('.item-search-results');if(!input||!results)return;const r=input.getBoundingClientRect();const viewport=window.innerWidth||document.documentElement.clientWidth;const width=Math.min(1120,Math.max(760,viewport-20));const maxLeft=Math.max(10,viewport-width-10);const left=Math.min(Math.max(10,Math.round(r.left)),maxLeft);results.style.left=left+'px';results.style.top=Math.round(r.bottom+5)+'px';results.style.width=Math.round(width)+'px';}
  function closeResults(){if(active){const r=active.querySelector('.item-search-results');if(r)r.hidden=true;}active=null;}
  function source(box){return box.closest('.item-picker-cell')?.querySelector('.item-source-select')||null;}
  function setSelectedSummary(box,opt){}
  function clearSummary(box){box.querySelector('.item-selected-summary')?.remove();}
  function setSourceOption(box,item){
    const sel=source(box);if(!sel)return;
    const value=String(item.id||'');
    let opt=[...sel.options].find(o=>String(o.value)===value);
    if(!opt){opt=document.createElement('option');opt.value=value;sel.appendChild(opt);}
    opt.textContent=String(item.name||'');
    opt.dataset.sale=String(item.sale_price||0);opt.dataset.buy=String(item.purchase_price||0);opt.dataset.price=String((box.dataset.priceMode||'sale')==='purchase'?(item.purchase_price||0):(item.sale_price||0));
    opt.dataset.unit=String(item.unit_symbol||'');opt.dataset.type=String(item.item_type||'');opt.dataset.serialTracked=String(item.serial_tracked||0);opt.dataset.code=String(item.code||'');opt.dataset.barcode=String(item.barcode||'');opt.dataset.location=String(item.location||'');opt.dataset.itemNote=String(item.item_note||'');opt.dataset.description=String(item.description||'');opt.dataset.warranty=String(item.warranty||'');
    // Do not erase bundle metadata already rendered by the transaction page
    // when the live-search response omits bundle_components.
    const incomingBundle=Array.isArray(item.bundle_components)?item.bundle_components:[];
    if(incomingBundle.length>0 || !opt.dataset.bundle) opt.dataset.bundle=JSON.stringify(incomingBundle);
    opt.selected=true;
    setSelectedSummary(box,opt);
    applyItemLineMetadata(box,item);
    const row=box.closest('.sale-row');
    if(row && typeof window.updatePrice==='function'){
      // Transaction rows use the same update pipeline as a native select
      // change, so price/unit/metadata, auto-next-row and bundle sync all run
      // immediately after choosing an item from live search.
      try{window.updatePrice(sel);}catch(err){console.error('Transaction item update failed:',err);}
    }else{
      sel.dispatchEvent(new Event('change',{bubbles:true}));
      if(row && document.body.dataset.txntype==='sale'){
        const directBundle=Array.isArray(item?.bundle_components)?item.bundle_components:[];
        if(directBundle.length && typeof window.renderBundleChildrenForRow==='function'){
          try{window.renderBundleChildrenForRow(row,directBundle);}
          catch(err){console.error('Direct bundle row render failed:',err);}
        }else if(typeof window.syncBundleForRow==='function'){
          try{window.syncBundleForRow(row);}catch(err){console.error('Bundle sync failed:',err);}
        }
      }
    }
    return opt;
  }
  async function search(box,q){
    const input=box.querySelector('.item-search-input'),results=box.querySelector('.item-search-results');if(!input||!results)return;
    const query=q.trim();
    if(query.length<2){results.innerHTML='<div class="item-search-empty">Type at least 2 characters to search.</div>';results.hidden=false;placeResults(box);active=box;return;}
    const seq=(seqs.get(box)||0)+1;seqs.set(box,seq);
    results.innerHTML='<div class="item-search-empty">Searching…</div>';results.hidden=false;placeResults(box);active=box;
    try{
      const u=new URL(cfg.url,location.origin);u.searchParams.set('q',query);
      const res=await fetch(u.toString(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      const data=await res.json();if(seq!==(seqs.get(box)||0))return;
      if(!res.ok||!data.ok)throw new Error(data.error||('HTTP '+res.status));
      if(!Array.isArray(data.items)||!data.items.length){results.innerHTML='<div class="item-search-empty">No matching item found.</div>';results.hidden=false;placeResults(box);return;}
      const rows=data.items.map(it=>{
        const stock=Number(it.current_stock||0), stockCls=stock<0?'item-stock-negative':(stock>0?'item-stock-positive':'');
        const code=it.code||it.barcode||''; const trunc=(s,n)=>{s=String(s||'');return s.length>n?s.slice(0,n-1)+'…':s;};
        const location=String(it.location||''), note=String(it.item_note||''), description=String(it.description||''), warranty=String(it.warranty||'');
        return '<button type="button" class="item-search-result" data-item="'+esc(JSON.stringify(it))+'"><div class="item-search-grid"><div class="item-search-cell"><div class="item-search-name">'+esc(it.name)+(it.item_type==='service'?' <span class="item-selected-code">(Service)</span>':'')+'</div>'+(code?'<div class="item-search-meta">'+esc(code)+'</div>':'')+'</div><div class="item-search-cell item-price">৳'+moneyNum(it.sale_price)+'</div><div class="item-search-cell item-price">৳'+moneyNum(it.purchase_price)+'</div><div class="item-search-cell '+stockCls+'">'+moneyNum(stock)+'</div><div class="item-search-cell item-search-meta-cell" title="'+esc(location)+'">'+esc(trunc(location,22)||'—')+'</div><div class="item-search-cell item-search-meta-cell item-search-note-text" title="'+esc(note)+'">'+esc(trunc(note,26)||'—')+'</div><div class="item-search-cell item-search-meta-cell" title="'+esc(description)+'">'+esc(trunc(description,34)||'—')+'</div><div class="item-search-cell item-search-meta-cell" title="'+esc(warranty)+'">'+esc(trunc(warranty,18)||'—')+'</div></div></button>';
      }).join('');
      results.innerHTML='<div class="item-search-grid item-search-head"><div class="item-search-cell">ITEM</div><div class="item-search-cell">SALE PRICE</div><div class="item-search-cell">PURCHASE PRICE</div><div class="item-search-cell">STOCK</div><div class="item-search-cell">LOCATION</div><div class="item-search-cell">NOTE</div><div class="item-search-cell">DESCRIPTION</div><div class="item-search-cell">WARRANTY</div></div>'+rows;
      results.hidden=false;placeResults(box);
    }catch(err){if(seq!==(seqs.get(box)||0))return;results.innerHTML='<div class="item-search-empty">Search failed. Please try again.</div>';results.hidden=false;placeResults(box);console.error('Item live search:',err);}
  }
  function applyItemLineMetadata(box,item){
    const root=box.closest('.item-picker-cell')||box.parentElement; if(!root)return;
    const wrap=root.querySelector('.item-line-meta'); if(wrap)wrap.style.display=item&&item.id?'block':'none';
    const map={location:'.item-line-location',item_note:'.item-line-note',description:'.item-line-description',warranty:'.item-line-warranty'};
    Object.keys(map).forEach(k=>{const el=root.querySelector(map[k]);if(!el)return;el.value=String(item?.[k]||'');});
  }
  function init(box){
    if(!box||box.dataset.itemSearchBound==='1')return;box.dataset.itemSearchBound='1';
    const input=box.querySelector('.item-search-input'),clear=box.querySelector('.item-search-clear'),sel=source(box);if(!input||!sel)return;
    const sync=function(){const opt=sel.selectedOptions?.[0];if(opt&&opt.value){if(!input.value.trim())input.value=(opt.textContent||'').trim();setSelectedSummary(box,opt);if(clear)clear.style.display='block';applyItemLineMetadata(box,{location:opt.dataset.location||'',item_note:opt.dataset.itemNote||'',description:opt.dataset.description||'',warranty:opt.dataset.warranty||''});}else{input.value='';clearSummary(box);if(clear)clear.style.display='none';applyItemLineMetadata(box,{location:'',item_note:'',description:'',warranty:''});}};
    sync();
    input.addEventListener('input',()=>{const val=input.value.trim();sel.value='';clearSummary(box);if(clear)clear.style.display='none';window.clearTimeout(timers.get(box));timers.set(box,window.setTimeout(()=>search(box,val),220));});
    input.addEventListener('focus',()=>{const val=input.value.trim();if(val.length>=2)search(box,val);});
    input.addEventListener('keydown',e=>{if(e.key==='Escape'){e.preventDefault();closeResults();return;}if(e.key==='Enter'){const first=box.querySelector('.item-search-result');if(first){e.preventDefault();first.click();}}});
    sel.addEventListener('change',sync);
    box.addEventListener('click',e=>{
      const btn=e.target.closest('.item-search-result');if(!btn)return;
      e.preventDefault();
      let item=null;try{item=JSON.parse(btn.dataset.item||'{}')}catch(_){return;}
      setSourceOption(box,item);input.value=String(item.name||'')+(item.code?' · '+item.code:'');if(clear)clear.style.display='block';closeResults();
    });
    clear?.addEventListener('click',()=>{sel.value='';sel.selectedIndex=0;input.value='';clearSummary(box);clear.style.display='none';input.focus();closeResults();sel.dispatchEvent(new Event('change',{bubbles:true}));});
  }
  window.SutoInitItemSearch=function(container){
    const root=container||document;
    root.querySelectorAll?.('.item-live-search').forEach(init);
    if(root.matches?.('.item-live-search'))init(root);
  };
  document.addEventListener('DOMContentLoaded',()=>window.SutoInitItemSearch(document));
  document.addEventListener('click',e=>{if(active&&!e.target.closest('.item-live-search'))closeResults();});
  window.addEventListener('resize',()=>{if(active)placeResults(active);});window.addEventListener('scroll',()=>{if(active)placeResults(active);},true);
})();
(function(){
  document.addEventListener('wheel',function(e){
    const el=e.target.closest && e.target.closest('input[type=number]');
    if(el && document.activeElement===el){ e.preventDefault(); }
  },{passive:false});
})();
</script>
ITEMHTML;
    $api=e(url('party-search-api'));
    echo '<script>window.SutoPartySearchConfig='.json_encode(['url'=>$api],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';</script>';
    echo <<<'HTML'
<script>
(function(){
  const cfg=window.SutoPartySearchConfig||{};
  let active=null, timer=null;
  function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
  function closeResults(){if(active){const r=active.querySelector('.party-search-results');if(r)r.hidden=true;}active=null;}
  function placeResults(box){const input=box.querySelector('.party-search-input'),results=box.querySelector('.party-search-results');if(!input||!results)return;const r=input.getBoundingClientRect();results.style.left=Math.round(r.left)+'px';results.style.top=Math.round(r.bottom+5)+'px';results.style.width=Math.max(280,Math.round(r.width))+'px';}
  async function search(box){
    const input=box.querySelector('.party-search-input'), results=box.querySelector('.party-search-results'), role=box.dataset.partyRole||'customer';
    if(!input||!results)return;
    const q=input.value.trim();
    if(q.length<2){results.innerHTML='<div class="party-search-empty">Type at least 2 characters to search.</div>';results.hidden=false;placeResults(box);active=box;return;}
    results.innerHTML='<div class="party-search-empty">Searching…</div>';results.hidden=false;placeResults(box);active=box;
    try{
      const u=new URL(cfg.url,location.origin);u.searchParams.set('q',q);u.searchParams.set('role',role);
      const res=await fetch(u.toString(),{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});
      const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||('HTTP '+res.status));
      if(!Array.isArray(data.items)||!data.items.length){results.innerHTML='<div class="party-search-empty">No matching party found.</div>';results.hidden=false;placeResults(box);return;}
      results.innerHTML=data.items.map(it=>'<button type="button" class="party-result" data-id="'+esc(it.id)+'" data-name="'+esc(it.name)+'" data-phone="'+esc(it.phone||'')+'" data-due="'+esc(it.outstanding||0)+'" data-roles="'+esc(it.roles||'')+'"><div class="party-result-name">'+esc(it.name)+'</div><div class="party-result-meta">'+esc(it.phone||'')+(it.outstanding!==undefined?' · Due '+Number(it.outstanding||0).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2}):'')+'</div></button>').join('');
      results.hidden=false;placeResults(box);
    }catch(err){results.innerHTML='<div class="party-search-empty">Search failed. Please try again.</div>';results.hidden=false;placeResults(box);console.error('Party live search:',err);}
  }
  function selectResult(btn,box){
    const input=box.querySelector('.party-search-input'),hidden=box.querySelector('.party-search-id'),clear=box.querySelector('.party-search-clear');
    hidden.value=btn.dataset.id||'';hidden.dataset.due=btn.dataset.due||'0';hidden.dataset.roles=btn.dataset.roles||'';
    input.value=(btn.dataset.name||'')+(btn.dataset.phone?' — '+btn.dataset.phone:'');
    if(clear)clear.style.display='block';
    const results=box.querySelector('.party-search-results');if(results)results.hidden=true;
    hidden.dispatchEvent(new Event('change',{bubbles:true}));
    box.dispatchEvent(new CustomEvent('party-selected',{bubbles:true,detail:{id:btn.dataset.id||'',name:btn.dataset.name||'',phone:btn.dataset.phone||'',due:btn.dataset.due||'0',roles:btn.dataset.roles||''}}));
    active=null;
  }
  function init(box){
    const input=box.querySelector('.party-search-input'), hidden=box.querySelector('.party-search-id'), clear=box.querySelector('.party-search-clear');if(!input||!hidden)return;
    input.addEventListener('input',()=>{hidden.value='';hidden.dataset.due='0';hidden.dataset.roles='';if(clear)clear.style.display='none';clearTimeout(timer);timer=setTimeout(()=>search(box),220);});
    input.addEventListener('focus',()=>{if(input.value.trim().length>=2)search(box);});
    input.addEventListener('keydown',e=>{if(e.key==='Escape')closeResults();if(e.key==='Enter'){const first=box.querySelector('.party-result');if(first){e.preventDefault();selectResult(first,box);}}});
    box.addEventListener('click',e=>{const b=e.target.closest('.party-result');if(b){e.preventDefault();selectResult(b,box);}});
    clear?.addEventListener('click',()=>{input.value='';hidden.value='';hidden.dataset.due='0';hidden.dataset.roles='';clear.style.display='none';input.focus();closeResults();hidden.dispatchEvent(new Event('change',{bubbles:true}));});
  }
  document.addEventListener('DOMContentLoaded',()=>document.querySelectorAll('.party-live-search').forEach(init));
  document.addEventListener('click',e=>{if(active&&!e.target.closest('.party-live-search'))closeResults();});
  window.addEventListener('resize',()=>{if(active)placeResults(active);});window.addEventListener('scroll',()=>{if(active)placeResults(active);},true);
})();
</script>
HTML;
    $serialApi=e(url('serial-search-api')); echo '<script>window.SutoSerialSearchConfig='.json_encode(['url'=>$serialApi],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';</script>'; echo '</div></main></div><script src="'.e(url('assets/app.js')).'?v=150"></script></body></html>';
}
function get_items(int $cid): array {
    $pdo=db();
    // Keep the item list itself independent from stock/transaction tables so a
    // missing optional ledger column/table can never blank the whole Items page.
    $st=$pdo->prepare('SELECT i.* FROM items i WHERE i.company_id=? AND i.active=1 ORDER BY i.name');
    $st->execute([$cid]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as &$row){
        $row['current_stock']=0.0;
    }
    unset($row);

    // Best-effort stock enrichment. Any schema mismatch leaves stock at 0 and
    // the item list still renders normally.
    try{
        $stock=$pdo->prepare('SELECT sm.item_id, COALESCE(SUM(sm.quantity),0) qty
            FROM stock_movements sm
            WHERE sm.company_id=?
              AND (sm.transaction_id IS NULL OR sm.transaction_id IN (
                    SELECT t.id FROM transactions t WHERE t.company_id=?
                ))
            GROUP BY sm.item_id');
        $stock->execute([$cid,$cid]);
        $map=[];
        foreach($stock->fetchAll(PDO::FETCH_ASSOC) as $s){
            $map[(int)$s['item_id']]=(float)$s['qty'];
        }
        foreach($rows as &$row){
            $iid=(int)$row['id'];
            $row['current_stock']=$map[$iid] ?? 0.0;
        }
        unset($row);
    }catch(Throwable $e){
        error_log('Items stock enrichment failed: '.$e->getMessage());
    }
    return $rows;
}
function next_document_in_transaction(PDO $pdo,int $cid,string $type,string $prefix): string { $st=$pdo->prepare('SELECT next_number FROM document_sequences WHERE company_id=? AND document_type=? FOR UPDATE'); $st->execute([$cid,$type]); $n=$st->fetchColumn(); if($n===false){$n=1;$pdo->prepare('INSERT INTO document_sequences(company_id,document_type,next_number) VALUES(?,?,2)')->execute([$cid,$type]);}else{$pdo->prepare('UPDATE document_sequences SET next_number=next_number+1 WHERE company_id=? AND document_type=?')->execute([$cid,$type]);} return $prefix.str_pad((string)$n,2,'0',STR_PAD_LEFT); }
function post_ledger(PDO $pdo,int $cid,int $tid,string $date,array $lines): void { $st=$pdo->prepare('INSERT INTO ledger_entries(company_id,transaction_id,entry_date,account_code,account_name,debit,credit,memo) VALUES(?,?,?,?,?,?,?,?)'); foreach($lines as $l)$st->execute([$cid,$tid,$date,$l[0],$l[1],$l[2],$l[3],$l[4]??null]); }


<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/saas_v179.php';
ensure_saas_v179_schema();
require_once __DIR__ . '/../app/notifications_v191.php';
ensure_notifications_v191_schema();

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$route = trim($requestPath, '/');
$base = trim((string)($config['app']['base_url'] ?? ''), '/');
if ($base !== '') {
    if ($route === $base) $route = '';
    elseif (str_starts_with($route, $base.'/')) $route = trim(substr($route, strlen($base)), '/');
}
$route = $route ?: '';

/** v125: platform control / SaaS operations schema. */
function ensure_platform_schema(): void {
    static $done=false; if($done)return; $done=true; $pdo=db();
    try{
        $cols=$pdo->query("SHOW COLUMNS FROM companies")->fetchAll(PDO::FETCH_COLUMN,0);
        if(!in_array('account_status',$cols,true))$pdo->exec("ALTER TABLE companies ADD COLUMN account_status VARCHAR(20) NOT NULL DEFAULT 'active'");
        if(!in_array('plan_name',$cols,true))$pdo->exec("ALTER TABLE companies ADD COLUMN plan_name VARCHAR(80) NOT NULL DEFAULT 'Free'");
        if(!in_array('subscription_expires_at',$cols,true))$pdo->exec("ALTER TABLE companies ADD COLUMN subscription_expires_at DATETIME NULL");if(!in_array('billing_cycle',$cols,true))$pdo->exec("ALTER TABLE companies ADD COLUMN billing_cycle VARCHAR(20) NULL");if(!in_array('subscription_amount',$cols,true))$pdo->exec("ALTER TABLE companies ADD COLUMN subscription_amount DECIMAL(14,2) NOT NULL DEFAULT 0");if(!in_array('subscription_started_at',$cols,true))$pdo->exec("ALTER TABLE companies ADD COLUMN subscription_started_at DATETIME NULL");if(!in_array('plan_converted_at',$cols,true))$pdo->exec("ALTER TABLE companies ADD COLUMN plan_converted_at DATETIME NULL");if(!in_array('plan_converted_by',$cols,true))$pdo->exec("ALTER TABLE companies ADD COLUMN plan_converted_by INT UNSIGNED NULL");
    }catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS platform_admins (id INT UNSIGNED NOT NULL AUTO_INCREMENT,username VARCHAR(100) NOT NULL,email VARCHAR(191) NULL,name VARCHAR(150) NOT NULL,password_hash VARCHAR(255) NOT NULL,status VARCHAR(20) NOT NULL DEFAULT 'active',last_login_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),UNIQUE KEY uq_pa_username(username),UNIQUE KEY uq_pa_email(email)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");$n=(int)$pdo->query('SELECT COUNT(*) FROM platform_admins')->fetchColumn();if($n===0){$pdo->prepare('INSERT INTO platform_admins(username,email,name,password_hash,status) VALUES(?,?,?,?,"active")')->execute(['platformadmin','platform@suto.bd','Suto Platform Admin','$2y$12$ewE20wVSVoyaqBc7EkR8NODwLUxiMpfHLelqnu/sdmuZckpoHMDd.']);}}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS platform_login_events (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,user_id INT UNSIGNED NOT NULL,company_id INT UNSIGNED NOT NULL,ip_address VARCHAR(64) NULL,user_agent VARCHAR(500) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_ple_company(company_id,created_at),KEY idx_ple_user(user_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS platform_sessions (session_id VARCHAR(191) NOT NULL,user_id INT UNSIGNED NOT NULL,company_id INT UNSIGNED NOT NULL,last_seen_at DATETIME NOT NULL,created_at DATETIME NOT NULL,ip_address VARCHAR(64) NULL,user_agent VARCHAR(500) NULL,PRIMARY KEY(session_id),KEY idx_ps_company(company_id,last_seen_at),KEY idx_ps_last_seen(last_seen_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS platform_announcements (id INT UNSIGNED NOT NULL AUTO_INCREMENT,title VARCHAR(191) NOT NULL,body TEXT NOT NULL,type VARCHAR(20) NOT NULL DEFAULT 'notice',target_type VARCHAR(20) NOT NULL DEFAULT 'all',target_company_id INT UNSIGNED NULL,priority INT NOT NULL DEFAULT 0,is_active TINYINT(1) NOT NULL DEFAULT 1,starts_at DATETIME NOT NULL,ends_at DATETIME NULL,created_by INT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_pa_active(is_active,starts_at,ends_at),KEY idx_pa_company(target_company_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS platform_announcement_reads (announcement_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,read_at DATETIME NOT NULL,PRIMARY KEY(announcement_id,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS support_tickets (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NULL,subject VARCHAR(191) NOT NULL,category VARCHAR(40) NOT NULL DEFAULT 'bug',message TEXT NOT NULL,priority VARCHAR(20) NOT NULL DEFAULT 'normal',status VARCHAR(20) NOT NULL DEFAULT 'open',admin_reply TEXT NULL,admin_replied_by INT UNSIGNED NULL,admin_replied_at DATETIME NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_st_company(company_id,created_at),KEY idx_st_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
    try{$pdo->exec("UPDATE companies SET plan_name='Free' WHERE plan_name IS NULL OR plan_name='' OR plan_name='Trial'");}catch(Throwable $e){}
    try{$pdo->exec("CREATE TABLE IF NOT EXISTS company_billing_records (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,company_id INT UNSIGNED NOT NULL,plan_name VARCHAR(80) NOT NULL,billing_cycle VARCHAR(20) NOT NULL,amount DECIMAL(14,2) NOT NULL DEFAULT 0,payment_status VARCHAR(20) NOT NULL DEFAULT 'pending',starts_at DATETIME NOT NULL,ends_at DATETIME NULL,converted_by INT UNSIGNED NULL,note TEXT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(id),KEY idx_cbr_company(company_id,created_at),KEY idx_cbr_status(payment_status,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");}catch(Throwable $e){}
}
ensure_platform_schema();
platform_touch_current_session();

if($route===''){
    $appName=(string)($config['app']['name']??'Suto Accounting');
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Suto Accounting — Online Accounting, Inventory & Business Management</title>
    <meta name="description" content="Suto Accounting is an online business management and accounting platform for sales, purchase, inventory, customers, suppliers, cash, bank and reports.">
    <meta name="robots" content="index,follow"><link rel="canonical" href="<?=e(url(''))?>">
    <meta property="og:title" content="Suto Accounting — Business Accounting & Management"><meta property="og:description" content="Manage sales, purchases, inventory, customers, suppliers, cash, bank and reports online.">
    <style>
      :root{--brand:#1688f7;--ink:#10243a;--muted:#6f7f90;--bg:#f4f7fb;--card:#fff;--line:#e3eaf2}
      *{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--ink)}
      .lp-nav{height:70px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 6vw;position:sticky;top:0;z-index:5}.lp-brand{font-weight:800;font-size:22px}.lp-actions{display:flex;gap:10px}.lp-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:12px;text-decoration:none;border:1px solid var(--line);color:var(--ink);background:#fff}.lp-btn.primary{background:var(--brand);border-color:var(--brand);color:#fff}
      .lp-hero{padding:84px 6vw 70px;display:grid;grid-template-columns:1.25fr .75fr;gap:46px;align-items:center}.lp-hero h1{font-size:52px;line-height:1.04;margin:0 0 18px}.lp-hero p{font-size:18px;color:var(--muted);max-width:720px;line-height:1.7}.lp-hero-card{background:linear-gradient(145deg,#0e5ccf,#1aa7ff);color:#fff;border-radius:26px;padding:28px;box-shadow:0 20px 50px rgba(9,63,128,.22)}.lp-hero-card h3{margin:0 0 16px;font-size:21px}.lp-stat{display:grid;grid-template-columns:1fr 1fr;gap:12px}.lp-stat>div{padding:14px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:14px}
      .lp-section{padding:30px 6vw 80px}.lp-section h2{font-size:32px;margin:0 0 10px}.lp-section>p{color:var(--muted)}.lp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:24px}.lp-card{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:22px}.lp-card h3{margin:0 0 8px}.lp-card p{color:var(--muted);line-height:1.6;margin:0}.lp-footer{padding:26px 6vw;border-top:1px solid var(--line);color:var(--muted);background:#fff}
      @media(max-width:900px){.lp-hero{grid-template-columns:1fr}.lp-grid{grid-template-columns:1fr}.lp-hero h1{font-size:40px}.lp-nav{padding:0 4vw}.lp-hero,.lp-section{padding-left:4vw;padding-right:4vw}}
    </style></head><body>
      <header class="lp-nav"><div class="lp-brand">Suto Accounting</div><div class="lp-actions"><a class="lp-btn" href="<?=e(url('login'))?>">Login</a><a class="lp-btn primary" href="<?=e(url('register'))?>">Create Company</a></div></header>
      <section class="lp-hero"><div><div style="color:var(--brand);font-weight:700;margin-bottom:10px">Online Accounting & Business Management</div><h1>Run sales, purchases, inventory and accounts from one place.</h1><p>Suto Accounting helps businesses manage invoices, purchase bills, customers, suppliers, stock, cash, bank accounts, payments, reports and day-to-day business operations.</p><div class="lp-actions" style="margin-top:24px"><a class="lp-btn primary" href="<?=e(url('register'))?>">Start Free</a><a class="lp-btn" href="#features">Explore Features</a></div></div><div class="lp-hero-card"><h3>Built for growing businesses</h3><div class="lp-stat"><div><b>Sales</b><br><span>Invoices & returns</span></div><div><b>Inventory</b><br><span>Stock & serials</span></div><div><b>Cash & Bank</b><br><span>Payments & transfers</span></div><div><b>Reports</b><br><span>Business visibility</span></div></div></div></section>
      <section class="lp-section" id="features"><h2>Everything your business needs</h2><p>Organize your operations with a single web-based system.</p><div class="lp-grid"><div class="lp-card"><h3>Sales & Customers</h3><p>Sales invoices, quotations, sale orders, delivery challans, returns, customer balances and payment tracking.</p></div><div class="lp-card"><h3>Purchase & Suppliers</h3><p>Purchase bills, purchase orders, supplier balances, returns, payments and due tracking.</p></div><div class="lp-card"><h3>Inventory</h3><p>Products and services, stock movements, low stock, serial-number tracking and item history.</p></div><div class="lp-card"><h3>Cash & Bank</h3><p>Cash in hand, bank accounts, deposits, withdrawals and account-to-account transfers.</p></div><div class="lp-card"><h3>Reports</h3><p>Operational and accounting reports for sales, purchases, stock, receivables, payables and cash.</p></div><div class="lp-card"><h3>Multi-user Company</h3><p>Invite team members and control access with roles and permissions.</p></div></div></section>
      <section class="lp-section"><h2>Start with a free company account</h2><p>Companies can start free and expand as their usage grows. Paid plans can be managed manually when required.</p><a class="lp-btn primary" href="<?=e(url('register'))?>">Create Company</a></section>
      <footer class="lp-footer">© <?=date('Y')?> Suto Accounting · Online Accounting & Business Management</footer></body></html><?php exit;
}


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
    $quickNotifications=[];
    try {
        $qn=db()->prepare("SELECT id,type,title,body,link,read_at,created_at FROM notifications WHERE user_id=? AND company_id=? AND (deleted_at IS NULL OR deleted_at='0000-00-00 00:00:00') ORDER BY id DESC LIMIT 6");
        $qn->execute([(int)$u['id'],(int)$u['company_id']]);
        $quickNotifications=$qn->fetchAll();
    } catch(Throwable $e) { $quickNotifications=[]; }
    $quickUnread=0;
    foreach($quickNotifications as $qnr){ if(empty($qnr['read_at'])) $quickUnread++; }
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> · <?=e($config['app']['name'])?></title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>?v=145"></head><body><div class="app-shell">
    <aside class="sidebar"><div class="brandbar"><div class="brandmark">SA</div><div class="brandinfo"><div class="brandtitle"><?=e($u['company_name'] ?? $config['app']['name'])?></div><div class="branduser"><?=e($u['name'] ?? '')?></div><div class="brandrole"><?=e(ucwords(str_replace('_',' ',(string)($u['role'] ?? 'User'))))?></div><a class="brandlogout" href="<?=e(url('logout'))?>">Logout</a></div><div class="brandchev">›</div></div><nav class="nav">
    <?php nav('Home','dashboard',$active==='dashboard','⌂'); nav('Transactions','transactions',$active==='transactions','▤'); nav('Parties','parties',$active==='parties','♟'); nav('Items','items',$active==='items','▣'); ?>
    <details class="nav-section" <?=in_array($active,['sales','sale-new','payment-in','delivery-challans','sale-order','quotations','sale-return'],true)?'open':''?>><summary><span class="nav-icon">▤</span><span>Sale</span></summary><div class="submenu"><?php nav('Sales Invoices','sales',$active==='sales',''); nav('Estimate / Quotation','quotations',$active==='quotations',''); nav('Payment In','payment-in',$active==='payment-in',''); nav('Sale Order','sale-order',$active==='sale-order',''); nav('Delivery Challan','delivery-challans',$active==='delivery-challans',''); nav('Sale Return / Cr. Note','sale-return',$active==='sale-return',''); ?></div></details>
    <details class="nav-section" <?=in_array($active,['purchase','purchase-new','payment-out','purchase-order','purchase-return'],true)?'open':''?>><summary><span class="nav-icon">🛒</span><span>Purchase</span></summary><div class="submenu"><?php nav('Purchase Bills','purchase',$active==='purchase',''); nav('Payment Out','payment-out',$active==='payment-out',''); nav('Purchase Order','purchase-order',$active==='purchase-order',''); nav('Purchase Return / Dr. Note','purchase-return',$active==='purchase-return',''); ?></div></details>
    <?php nav('Expense','expense',$active==='expense','▣'); ?>
    <details class="nav-section" <?=in_array($active,['bank-accounts','cash','cheques','loans'],true)?'open':''?>><summary><span class="nav-icon">▤</span><span>Cash & Bank</span></summary><div class="submenu"><?php nav('Bank Account','bank-accounts',$active==='bank-accounts',''); nav('Cash In Hand','cash',$active==='cash',''); nav('Cheques','cheques',$active==='cheques',''); nav('Loan Accounts','loans',$active==='loans',''); ?></div></details>
    <?php nav('Reports','reports',$active==='reports','▥'); nav('Accounting Health','reports?report=accounting_health',$route==='reports' && (($_GET['report']??'')==='accounting_health'),'✓'); ?><div class="nav-divider"></div><?php nav('Backup / Restore','backup',$active==='backup','⟳'); nav('Audit Log','audit-log',$active==='audit-log','◷'); nav('Support / Feedback','support',$active==='support','?'); if(is_platform_admin()) nav('Platform Control','platform-control',$active==='platform-control','⌘'); ?>
    <details class="nav-section" <?=in_array($active,['utilities','barcode','import-items','bulk-update','import-parties','export-items','recycle-bin','financial-year'],true)?'open':''?>><summary><span class="nav-icon">🔧</span><span>Utilities</span></summary><div class="submenu"><?php nav('Generate Barcode','barcode',$active==='barcode',''); nav('Import Items','import-items',$active==='import-items',''); nav('Bulk Update Item','bulk-update',$active==='bulk-update',''); nav('Import Parties','import-parties',$active==='import-parties',''); nav('Export Item','export-items',$active==='export-items',''); nav('Recycle Bin','recycle-bin',$active==='recycle-bin',''); nav('Close Financial Year','financial-year',$active==='financial-year',''); ?></div></details>
    <?php nav('Setting','settings',$active==='settings','⚙'); ?></nav><div class="sidebar-collapse-wrap"><button type="button" id="sidebarCollapseBtn" class="sidebar-collapse-btn" aria-expanded="true" aria-label="Collapse menu"><span class="collapse-icon">‹</span><span class="collapse-label">Collapse Menu</span></button></div></aside>
    <main class="main"><header class="topbar"><div class="searchbar"><span>⌕</span><input id="globalSearch" placeholder="Search Transactions"></div><div class="top-actions"><a class="header-notify" href="<?=e(url('messages'))?>">✉ <span><?=unread_messages_count((int)$u['id'])?></span></a><div class="notification-quick-wrap"><button class="header-notify notification-quick-btn" id="notificationQuickBtn" type="button" aria-expanded="false" aria-haspopup="true">🔔 <span><?=unread_notifications_count((int)$u['id'])?></span></button><div id="notificationQuickPanel" class="notification-quick-panel" hidden><div class="notification-quick-head"><div><b>Notifications</b><?php if($quickUnread>0): ?><small><?=($quickUnread)?> new</small><?php else: ?><small>Up to date</small><?php endif; ?></div><a href="<?=e(url('notifications'))?>">View all</a></div><div class="notification-quick-list"><?php foreach($quickNotifications as $qn): ?><a class="notification-quick-item <?=empty($qn['read_at'])?'is-unread':''?>" href="<?=e(url('notifications?read='.(int)$qn['id']))?>"><div class="notification-quick-meta"><span><?=e(ucwords(str_replace('_',' ',(string)$qn['type'])))?></span><time><?=e(date('d/m H:i',strtotime($qn['created_at'])))?></time></div><b><?=e($qn['title'])?></b><p><?=e(mb_strimwidth((string)($qn['body']??''),0,120,'…','UTF-8'))?></p></a><?php endforeach; if(!$quickNotifications): ?><div class="notification-quick-empty">No notifications yet.</div><?php endif; ?></div><div class="notification-quick-footer"><a href="<?=e(url('notifications?read_all=1'))?>">Mark all as read</a><a href="<?=e(url('notifications'))?>">Open Notification Center</a></div></div></div><a class="btn sale" href="<?=e(url('sale-new'))?>">⊕ Add Sale</a><a class="btn purchase" href="<?=e(url('purchase-new'))?>">⊕ Add Purchase</a><button class="btn more" id="addMoreBtn" type="button">⊕ Add More</button><a class="gear" href="<?=e(url('settings'))?>">⚙</a></div><div id="addMore" class="add-more"><div><strong>SALE</strong><a href="<?=e(url('sale-new'))?>">▸ Sale Invoice <span class="kbd">ALT + S</span></a><a href="<?=e(url('payment-in'))?>">▸ Payment-In <span class="kbd">ALT + I</span></a><a href="<?=e(url('sale-return'))?>">▸ Sale Return <span class="kbd">ALT + R</span></a><a href="<?=e(url('sale-order'))?>">▸ Sale Order <span class="kbd">ALT + F</span></a><a href="<?=e(url('quotations'))?>">▸ Estimate / Quotation <span class="kbd">ALT + M</span></a><a href="<?=e(url('delivery-challans'))?>">▸ Delivery Challan <span class="kbd">ALT + D</span></a></div><div><strong>PURCHASE</strong><a href="<?=e(url('purchase-new'))?>">▸ Purchase Bill <span class="kbd">ALT + P</span></a><a href="<?=e(url('payment-out'))?>">▸ Payment-Out <span class="kbd">ALT + O</span></a><a href="<?=e(url('purchase-return'))?>">▸ Purchase Return <span class="kbd">ALT + L</span></a><a href="<?=e(url('purchase-order'))?>">▸ Purchase Order <span class="kbd">ALT + G</span></a></div><div><strong>OTHERS</strong><a href="<?=e(url('expense'))?>">▸ Expenses <span class="kbd">ALT + E</span></a><a href="#">▸ Party To Party Transfer <span class="kbd">ALT + J</span></a></div><div class="menu-footer">Shortcut to open this menu: <b>Ctrl</b> + <b>Enter</b></div></div></header><div class="content"><?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach; }
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
function page_end(): void {
    echo <<<'HTML'
<style>
.item-picker-cell{position:relative;min-width:250px}
.item-live-search{position:relative;width:100%}
.item-search-wrap{position:relative;display:flex;align-items:center}
.item-live-search .item-search-input{width:100%;box-sizing:border-box;padding:9px 36px 9px 10px;border:1px solid #cfd8e3;border-radius:6px;background:#fff;color:#172033;outline:none}
.item-live-search .item-search-input:focus{border-color:#1d8cf8;box-shadow:0 0 0 2px rgba(29,140,248,.10)}
.item-search-clear{position:absolute;right:7px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#64748b;font-size:20px;line-height:1;cursor:pointer;padding:2px 6px}
.item-search-results{position:fixed;width:820px;max-width:calc(100vw - 20px);max-height:320px;overflow-y:auto;overflow-x:hidden;background:#fff;border:1px solid #d8e0ea;border-radius:8px;box-shadow:0 12px 28px rgba(15,23,42,.18);z-index:2147483647}
.item-search-grid{display:grid;width:100%;grid-template-columns:minmax(250px,1fr) 110px 120px 105px 90px 90px;gap:0;align-items:center;box-sizing:border-box}
.item-search-head{position:sticky;top:0;background:#f7f9fc;border-bottom:1px solid #e6ebf2;font-size:11px;color:#708090;font-weight:700;text-transform:uppercase;letter-spacing:.02em}
.item-search-cell{padding:8px 9px;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;border-right:1px solid #edf1f5}
.item-search-head .item-search-cell:last-child,.item-search-result .item-search-cell:last-child{border-right:0}
.item-search-result{display:block;width:100%;border:0;border-bottom:1px solid #eef2f7;background:#fff;text-align:left;padding:0;cursor:pointer;color:#1f2937}
.item-search-result:hover{background:#f4f8ff}
.item-search-result:last-child{border-bottom:0}
.item-search-name{font-weight:600;color:#18324f}
.item-search-meta{font-size:11px;color:#7b8794;margin-top:2px}
.item-search-empty{padding:12px;color:#718096;font-size:13px}
.item-stock-negative{color:#ef4444}.item-stock-positive{color:#10b981}.item-price{font-variant-numeric:tabular-nums}
.item-source-select{display:none!important}
.item-selected-summary{display:flex;align-items:center;gap:8px;min-height:20px;font-size:13px;color:#203047}
.item-selected-summary .item-selected-name{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.item-selected-summary .item-selected-code{font-size:11px;color:#7b8794;white-space:nowrap}
@media(max-width:900px){.item-search-results{width:calc(100vw - 20px);max-width:calc(100vw - 20px)}.item-search-grid{grid-template-columns:minmax(170px,1fr) 88px 96px 82px 70px 70px}.item-search-cell{padding:7px 6px;font-size:12px}}
@media print{.print-hide{display:none!important}.invoice-print .invoice-meta{grid-template-columns:1fr!important}.invoice-print .invoice-meta>div{min-width:0}.invoice-print{break-inside:avoid}.invoice-print .table-wrap{overflow:visible!important}.invoice-print .table-wrap table{width:100%!important}.invoice-print{min-height:0!important;height:auto!important}}

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
    echo '<script>window.SutoItemSearchConfig='.json_encode(['url'=>$itemApi],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';</script>';
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
  function placeResults(box){const input=box.querySelector('.item-search-input'),results=box.querySelector('.item-search-results');if(!input||!results)return;const r=input.getBoundingClientRect();const viewport=window.innerWidth||document.documentElement.clientWidth;const width=Math.min(820,Math.max(640,viewport-20));const maxLeft=Math.max(10,viewport-width-10);const left=Math.min(Math.max(10,Math.round(r.left)),maxLeft);results.style.left=left+'px';results.style.top=Math.round(r.bottom+5)+'px';results.style.width=Math.round(width)+'px';}
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
    opt.dataset.unit=String(item.unit_symbol||'');opt.dataset.type=String(item.item_type||'');opt.dataset.serialTracked=String(item.serial_tracked||0);opt.dataset.code=String(item.code||'');opt.dataset.barcode=String(item.barcode||'');opt.selected=true;
    setSelectedSummary(box,opt);
    sel.dispatchEvent(new Event('change',{bubbles:true}));
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
        const code=it.code||it.barcode||'';
        return '<button type="button" class="item-search-result" data-item="'+esc(JSON.stringify(it))+'"><div class="item-search-grid"><div class="item-search-cell"><div class="item-search-name">'+esc(it.name)+(it.item_type==='service'?' <span class="item-selected-code">(Service)</span>':'')+'</div>'+(code?'<div class="item-search-meta">'+esc(code)+'</div>':'')+'</div><div class="item-search-cell item-price">৳'+moneyNum(it.sale_price)+'</div><div class="item-search-cell item-price">৳'+moneyNum(it.purchase_price)+'</div><div class="item-search-cell item-price">—</div><div class="item-search-cell '+stockCls+'">'+moneyNum(stock)+'</div><div class="item-search-cell">—</div></div></button>';
      }).join('');
      results.innerHTML='<div class="item-search-grid item-search-head"><div class="item-search-cell">ITEM</div><div class="item-search-cell">SALE PRICE</div><div class="item-search-cell">PURCHASE PRICE</div><div class="item-search-cell">MFG COST</div><div class="item-search-cell">STOCK</div><div class="item-search-cell">LOCATION</div></div>'+rows;
      results.hidden=false;placeResults(box);
    }catch(err){if(seq!==(seqs.get(box)||0))return;results.innerHTML='<div class="item-search-empty">Search failed. Please try again.</div>';results.hidden=false;placeResults(box);console.error('Item live search:',err);}
  }
  function init(box){
    if(!box||box.dataset.itemSearchBound==='1')return;box.dataset.itemSearchBound='1';
    const input=box.querySelector('.item-search-input'),clear=box.querySelector('.item-search-clear'),sel=source(box);if(!input||!sel)return;
    const sync=function(){const opt=sel.selectedOptions?.[0];if(opt&&opt.value){if(!input.value.trim())input.value=(opt.textContent||'').trim();setSelectedSummary(box,opt);if(clear)clear.style.display='block';}else{input.value='';clearSummary(box);if(clear)clear.style.display='none';}};
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
    $serialApi=e(url('serial-search-api')); echo '<script>window.SutoSerialSearchConfig='.json_encode(['url'=>$serialApi],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE).';</script>'; echo '</div></main></div><script src="'.e(url('assets/app.js')).'?v=139"></script></body></html>';
}
function get_items(int $cid): array {
    $st=db()->prepare('SELECT i.*,COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm WHERE sm.company_id=i.company_id AND sm.item_id=i.id
                     AND (sm.transaction_id IS NULL OR EXISTS (
                         SELECT 1 FROM transactions st
                         WHERE st.id=sm.transaction_id
                           AND st.company_id=sm.company_id
                           AND st.deleted_at IS NULL
                     ))),0) AS current_stock
                       FROM items i WHERE i.company_id=? AND i.active=1 ORDER BY i.name');
    $st->execute([$cid]); return $st->fetchAll();
}
function next_document_in_transaction(PDO $pdo,int $cid,string $type,string $prefix): string { $st=$pdo->prepare('SELECT next_number FROM document_sequences WHERE company_id=? AND document_type=? FOR UPDATE'); $st->execute([$cid,$type]); $n=$st->fetchColumn(); if($n===false){$n=1;$pdo->prepare('INSERT INTO document_sequences(company_id,document_type,next_number) VALUES(?,?,2)')->execute([$cid,$type]);}else{$pdo->prepare('UPDATE document_sequences SET next_number=next_number+1 WHERE company_id=? AND document_type=?')->execute([$cid,$type]);} return $prefix.str_pad((string)$n,6,'0',STR_PAD_LEFT); }
function post_ledger(PDO $pdo,int $cid,int $tid,string $date,array $lines): void { $st=$pdo->prepare('INSERT INTO ledger_entries(company_id,transaction_id,entry_date,account_code,account_name,debit,credit,memo) VALUES(?,?,?,?,?,?,?,?)'); foreach($lines as $l)$st->execute([$cid,$tid,$date,$l[0],$l[1],$l[2],$l[3],$l[4]??null]); }

if ($route==='login') { if(user()) redirect('dashboard'); if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();$email=trim($_POST['email']??'');$pass=$_POST['password']??'';$st=db()->prepare('SELECT * FROM users WHERE email=? AND status="active" LIMIT 1');$st->execute([$email]);$x=$st->fetch();if($x&&password_verify($pass,$x['password_hash'])){try{$cs=db()->prepare('SELECT account_status FROM companies WHERE id=? LIMIT 1');$cs->execute([(int)$x['company_id']]);if((string)($cs->fetchColumn()??'active')==='suspended'){flash('error','This company account is suspended. Please contact Suto Accounting support.');redirect('login');}}catch(Throwable $e){}session_regenerate_id(true);$_SESSION['uid']=$x['id'];db()->prepare('UPDATE users SET last_login_at=NOW() WHERE id=?')->execute([$x['id']]);platform_record_login((int)$x['id']);redirect('dashboard');}flash('error','Invalid email or password.');redirect('login');} ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login · Suto Accounting</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>"></head><body class="auth"><div class="auth-card"><div class="auth-brand"><span class="brandmark">SA</span><span>Suto Accounting</span></div><h1>Welcome back</h1><p>Sign in to your company account.</p><?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach;?><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>Email</label><input type="email" name="email" required></div><div class="form-group"><label>Password</label><input type="password" name="password" required></div><button class="btn primary" style="width:100%;justify-content:center">Login</button></form><a class="small-link" href="<?=e(url('register'))?>">Create company account</a></div></body></html><?php exit; }
if ($route==='platform-login') {
    if(platform_admin()) redirect('platform-control');
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $identity=trim((string)($_POST['identity']??''));
        $pass=(string)($_POST['password']??'');
        $st=db()->prepare('SELECT * FROM platform_admins WHERE status="active" AND (username=? OR email=?) LIMIT 1');
        $st->execute([$identity,$identity]);
        $a=$st->fetch();
        if($a && password_verify($pass,(string)$a['password_hash'])){
            session_regenerate_id(true);
            $_SESSION['platform_admin_id']=(int)$a['id'];
            db()->prepare('UPDATE platform_admins SET last_login_at=NOW() WHERE id=?')->execute([(int)$a['id']]);
            redirect('platform-control');
        }
        flash('error','Invalid Platform Control username/email or password.');
        redirect('platform-login');
    }
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Platform Control Login · Suto Accounting</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>?v=128"></head><body class="auth"><div class="auth-card"><div class="auth-brand"><span class="brandmark">SA</span><span>Suto Accounting</span></div><h1>Platform Control</h1><p>Separate administrator access for managing all company accounts and SaaS operations.</p><?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach;?><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>Username or Email</label><input name="identity" autocomplete="username" required></div><div class="form-group"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div><button class="btn primary" style="width:100%;justify-content:center">Sign in to Platform Control</button></form><div class="pc-note" style="margin-top:14px">This login is separate from company users. A company Super Admin cannot access Platform Control unless explicitly created as a Platform Admin.</div></div></body></html><?php exit;
}
if ($route==='platform-logout') { unset($_SESSION['platform_admin_id']); redirect('platform-login'); }

if ($route==='register') { if(user())redirect('dashboard'); if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();$name=trim($_POST['name']??'');$email=trim($_POST['email']??'');$pass=$_POST['password']??'';$company=trim($_POST['company_name']??'');$phone=trim($_POST['phone']??'');$address=trim($_POST['address']??'');$biz=trim($_POST['business_type']??'');$fy=$_POST['financial_year_mode']??'july_june';$currency=$_POST['currency_code']??'BDT';if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<8||!$company){flash('error','Name, company, valid email and password (8+ chars) are required.');redirect('register');}$pdo=db();try{$pdo->beginTransaction();$pdo->prepare('INSERT INTO companies(name,email,phone,address,business_type,currency_code,financial_year_mode,financial_year_start) VALUES(?,?,?,?,?,?,?,?)')->execute([$company,$email,$phone,$address,$biz,$currency,$fy,$fy==='jan_dec'?1:7]);$cid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO users(company_id,name,email,password_hash,role,status) VALUES(?,?,?,?,"super_admin","active")')->execute([$cid,$name,$email,password_hash($pass,PASSWORD_DEFAULT)]);$uid=(int)$pdo->lastInsertId();$pdo->prepare('INSERT INTO roles(company_id,name) VALUES(? ,"Super Admin")')->execute([$cid]);foreach([['view','View'],['add','Add'],['edit','Edit'],['delete','Delete'],['print','Print'],['export','Export'],['approve','Approve']] as $p)$pdo->prepare('INSERT INTO permissions(code,label) VALUES(?,?) ON DUPLICATE KEY UPDATE label=VALUES(label)')->execute($p);$pdo->prepare('INSERT INTO branches(company_id,name,code,is_default) VALUES(? ,"Main Branch","MAIN",1)')->execute([$cid]);$pdo->prepare('INSERT INTO units(company_id,name,symbol) VALUES(? ,"Piece","pcs")')->execute([$cid]);$pdo->commit();session_regenerate_id(true);$_SESSION['uid']=$uid;redirect('dashboard');}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getCode()==='23000'?'This email may already be registered.':'Registration failed.');redirect('register');}} ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Create Company</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>"></head><body class="auth"><div class="auth-card wide"><div class="auth-brand"><span class="brandmark">SA</span><span>Suto Accounting</span></div><h1>Create your company</h1><p>The first account becomes the Super Admin / Company Owner.</p><?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach;?><form method="post" class="grid2"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>Admin Name*</label><input name="name" required></div><div class="form-group"><label>Admin Email*</label><input name="email" type="email" required></div><div class="form-group"><label>Password*</label><input name="password" type="password" minlength="8" required></div><div class="form-group"><label>Company Name*</label><input name="company_name" required></div><div class="form-group"><label>Company Phone</label><input name="phone"></div><div class="form-group"><label>Business Type</label><input name="business_type"></div><div class="form-group"><label>Currency</label><select name="currency_code"><option value="BDT">BDT — ৳</option><option value="USD">USD — $</option><option value="EUR">EUR — €</option></select></div><div class="form-group"><label>Financial Year</label><select name="financial_year_mode"><option value="july_june">1 July – 30 June</option><option value="jan_dec">1 January – 31 December</option></select></div><div class="form-group span2"><label>Company Address</label><textarea name="address"></textarea></div><div class="span2"><button class="btn primary" style="width:100%;justify-content:center">Create Company & Admin Account</button></div></form></div></body></html><?php exit; }

// Public entry points: login, company registration, and invitation acceptance.
// All other routes require an authenticated user before route-specific code accesses $u.
$u = null;
if (!in_array($route, ['login','register','accept-invite','platform-login','platform-logout','platform-control'], true)) {
    $u = require_login();
}


if($route==='company-network'){
    // v200: HTTP 500 stability hotfix - all optional SaaS reads are isolated.
    $u=require_login(); $cid=(int)$u['company_id']; $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf(); $action=$_POST['network_action']??'';
        try{
            if($action==='subscribe'){
                $target=(int)($_POST['target_company_id']??0);
                if($target<=0||$target===$cid)throw new RuntimeException('Choose a valid company.');
                $st=$pdo->prepare('SELECT id,name FROM companies WHERE id=? AND account_status<>"suspended" LIMIT 1');$st->execute([$target]);$tc=$st->fetch();if(!$tc)throw new RuntimeException('Company not found.');
                $st=$pdo->prepare('SELECT id,status FROM company_subscriptions WHERE subscriber_company_id=? AND target_company_id=? LIMIT 1');$st->execute([$cid,$target]);$existing=$st->fetch();
                if($existing){ if($existing['status']==='approved')throw new RuntimeException('You are already subscribed.'); if($existing['status']==='pending')throw new RuntimeException('Subscription request is already pending.'); $pdo->prepare('UPDATE company_subscriptions SET status="pending",requested_by_user_id=?,requested_at=NOW(),updated_at=NOW(),rejected_at=NULL WHERE id=?')->execute([$u['id'],(int)$existing['id']]); }
                else $pdo->prepare('INSERT INTO company_subscriptions(subscriber_company_id,target_company_id,status,requested_by_user_id) VALUES(?,?,"pending",?)')->execute([$cid,$target,$u['id']]);
                saas_notify_company($target,'subscription','New company subscription request',$u['company_name'].' wants to subscribe to your company.',url('company-network'));
                flash('success','Subscription request sent.'); redirect('company-network');
            }
            if($action==='subscription_decision'){
                $id=(int)($_POST['subscription_id']??0); $decision=$_POST['decision']??'';
                $st=$pdo->prepare('SELECT * FROM company_subscriptions WHERE id=? AND target_company_id=? LIMIT 1');$st->execute([$id,$cid]);$row=$st->fetch();if(!$row)throw new RuntimeException('Subscription request not found.');
                if($row['status']!=='pending')throw new RuntimeException('This request is no longer pending.');
                if($decision==='approve'){$pdo->prepare('UPDATE company_subscriptions SET status="approved",approved_by_user_id=?,approved_at=NOW(),updated_at=NOW() WHERE id=?')->execute([$u['id'],$id]);saas_notify_company((int)$row['subscriber_company_id'],'subscription','Subscription approved',$u['company_name'].' approved your subscription request.',url('company-network'));flash('success','Subscription approved.');}
                elseif($decision==='reject'){$pdo->prepare('UPDATE company_subscriptions SET status="rejected",updated_at=NOW(),rejected_at=NOW() WHERE id=?')->execute([$id]);saas_notify_company((int)$row['subscriber_company_id'],'subscription','Subscription request rejected',$u['company_name'].' rejected your subscription request.',url('company-network'));flash('success','Subscription request rejected.');}
                redirect('company-network');
            }
            if($action==='publish_update'){
                $type=$_POST['update_type']??'text';$title=trim($_POST['title']??'');$body=trim($_POST['body']??'');
                $allowed=['text','product','offer','notice','announcement'];if(!in_array($type,$allowed,true)||$title==='')throw new RuntimeException('Update type and title are required.');
                $pdo->prepare('INSERT INTO company_updates(company_id,user_id,update_type,title,body,status) VALUES(?,?,?,?,?,"published")')->execute([$cid,$u['id'],$type,$title,$body]);
                $uid=(int)$pdo->lastInsertId(); foreach(saas_approved_subscriber_company_ids($cid) as $sub){ saas_notify_company($sub,'company_update',$u['company_name'].' · '.saas_logotype($type),$title,url('company-network?update='.$uid)); }
                flash('success','Update published and subscribers notified.'); redirect('company-network');
            }
            if($action==='link_party_company'){
                $partyId=(int)($_POST['party_id']??0);$linked=(int)($_POST['linked_company_id']??0);$rel=$_POST['relation_type']??'customer';
                if($rel!=='customer')throw new RuntimeException('Only Customer relationships can be linked for reviews.');
                $st=$pdo->prepare('SELECT id FROM parties WHERE id=? AND company_id=? AND deleted_at IS NULL LIMIT 1');$st->execute([$partyId,$cid]);if(!$st->fetchColumn())throw new RuntimeException('Customer not found.');
                $st=$pdo->prepare('SELECT id FROM companies WHERE id=? AND id<>? AND account_status<>"suspended" LIMIT 1');$st->execute([$linked,$cid]);if(!$st->fetchColumn())throw new RuntimeException('Linked company not found.');
                $pdo->prepare('INSERT INTO party_company_links(company_id,party_id,linked_company_id,relation_type,created_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE linked_company_id=VALUES(linked_company_id),relation_type=VALUES(relation_type),created_by=VALUES(created_by)')->execute([$cid,$partyId,$linked,$rel,$u['id']]);
                flash('success','Customer linked to company.'); redirect('company-network#relationships');
            }
            if($action==='review_customer'){
                $linked=(int)($_POST['subject_company_id']??0);$partyId=(int)($_POST['party_id']??0);$rating=(int)($_POST['rating']??0);$comment=trim($_POST['comment']??'');
                if($rating<1||$rating>5||$comment==='')throw new RuntimeException('Rating and review comment are required.');
                $st=$pdo->prepare('SELECT id FROM party_company_links WHERE company_id=? AND party_id=? AND linked_company_id=? AND relation_type="customer" LIMIT 1');$st->execute([$cid,$partyId,$linked]);if(!$st->fetchColumn())throw new RuntimeException('Review is only available for a linked Customer company.');
                $pdo->prepare('INSERT INTO company_reviews(reviewer_company_id,subject_company_id,party_id,rating,comment,created_by) VALUES(?,?,?,?,?,?)')->execute([$cid,$linked,$partyId,$rating,$comment,$u['id']]);
                saas_notify_company($linked,'review','New customer review',$u['company_name'].' added a '.$rating.'-star review about your company.',url('company-network'));
                flash('success','Review submitted.'); redirect('company-network');
            }
        }catch(Throwable $e){flash('error',$e->getMessage());redirect('company-network');}
    }
    if(isset($_GET['mark_update_read'])){ $rid=(int)$_GET['mark_update_read']; try{ $pdo->prepare('INSERT INTO company_update_reads(update_id,company_id,user_id,read_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE read_at=NOW()')->execute([$rid,$cid,$u['id']]); }catch(Throwable $e){} redirect('company-network'); }
    $search=trim($_GET['q']??'');$companyResults=[];try{$companyResults=saas_company_search($cid,$search);}catch(Throwable $e){$companyResults=[];} $viewCompanyId=(int)($_GET['view_company']??0);$viewCompany=null;$viewCompanyReviews=[];$viewCompanyLinked=false;$searchCompanyMeta=[];$subscriberRows=[];
    try {
        if($search!=='' && $companyResults){
            $ids=array_values(array_unique(array_map('intval',array_column($companyResults,'id'))));
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $args=array_merge([$cid],$ids);
            $st=$pdo->prepare("SELECT cs.target_company_id,cs.status,cs.requested_at,cs.approved_at FROM company_subscriptions cs WHERE cs.subscriber_company_id=? AND cs.target_company_id IN ($ph)");$st->execute($args);
            foreach($st->fetchAll() as $r){$searchCompanyMeta[(int)$r['target_company_id']] = $r;}
            $st=$pdo->prepare("SELECT cs.subscriber_company_id,cs.status FROM company_subscriptions cs WHERE cs.target_company_id=? AND cs.subscriber_company_id IN ($ph)");$st->execute(array_merge([$cid],$ids));
            foreach($st->fetchAll() as $r){$id=(int)$r['subscriber_company_id'];$searchCompanyMeta[$id]['incoming_status']=$r['status'];}
        }
    }catch(Throwable $e){$searchCompanyMeta=[];}
    try {
        $st=$pdo->prepare("SELECT cs.*,c.name subscriber_name,c.phone,c.business_type,c.logo_path FROM company_subscriptions cs JOIN companies c ON c.id=cs.subscriber_company_id WHERE cs.target_company_id=? AND cs.status='approved' ORDER BY c.name");$st->execute([$cid]);$subscriberRows=$st->fetchAll();
    }catch(Throwable $e){$subscriberRows=[];}
    if($viewCompanyId>0&&$viewCompanyId!==$cid){try{$st=$pdo->prepare('SELECT id,name,phone,address,business_type,logo_path FROM companies WHERE id=? LIMIT 1');$st->execute([$viewCompanyId]);$viewCompany=$st->fetch();if($viewCompany){$st=$pdo->prepare('SELECT 1 FROM party_company_links WHERE company_id=? AND linked_company_id=? AND relation_type="customer" LIMIT 1');$st->execute([$cid,$viewCompanyId]);$viewCompanyLinked=(bool)$st->fetchColumn();if($viewCompanyLinked){$st=$pdo->prepare('SELECT cr.*,c.name reviewer_name,p.name party_name FROM company_reviews cr JOIN companies c ON c.id=cr.reviewer_company_id JOIN parties p ON p.id=cr.party_id WHERE cr.subject_company_id=? AND cr.status="published" ORDER BY cr.id DESC LIMIT 30');$st->execute([$viewCompanyId]);$viewCompanyReviews=$st->fetchAll();}}}catch(Throwable $e){$viewCompany=null;$viewCompanyReviews=[];$viewCompanyLinked=false;}}
    // v195: never let optional SaaS network tables break the whole ERP route.
    $subs=[];$incoming=[];$updates=[];$customers=[];$receivedReviews=[];
    try{$subs=saas_subscriptions_for($cid);}catch(Throwable $e){$subs=[];}
    try{$incoming=saas_incoming_requests($cid);}catch(Throwable $e){$incoming=[];}
    try{
        $q=$pdo->prepare("SELECT cu.*,c.name company_name,c.logo_path FROM company_updates cu JOIN companies c ON c.id=cu.company_id WHERE cu.status='published' AND (cu.company_id=? OR EXISTS(SELECT 1 FROM company_subscriptions cs WHERE cs.target_company_id=cu.company_id AND cs.subscriber_company_id=? AND cs.status='approved')) ORDER BY cu.id DESC LIMIT 50");
        $q->execute([$cid,$cid]);$updates=$q->fetchAll();
    }catch(Throwable $e){$updates=[];}
    try{
        $q=$pdo->prepare("SELECT p.id,p.name,p.phone,pcl.linked_company_id,c.name linked_company_name FROM parties p LEFT JOIN party_company_links pcl ON pcl.party_id=p.id AND pcl.company_id=p.company_id AND pcl.relation_type='customer' LEFT JOIN companies c ON c.id=pcl.linked_company_id WHERE p.company_id=? AND p.deleted_at IS NULL AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role='customer') ORDER BY p.name");
        $q->execute([$cid]);$customers=$q->fetchAll();
    }catch(Throwable $e){$customers=[];}
    try{
        $q=$pdo->prepare("SELECT cr.*,c.name reviewer_name,p.name party_name FROM company_reviews cr JOIN companies c ON c.id=cr.reviewer_company_id JOIN parties p ON p.id=cr.party_id WHERE cr.subject_company_id=? AND cr.status='published' ORDER BY cr.id DESC LIMIT 50");
        $q->execute([$cid]);$receivedReviews=$q->fetchAll();
    }catch(Throwable $e){$receivedReviews=[];}
    page_start('Company Network');
    ?>
<style>.network-company-card{display:flex;justify-content:space-between;gap:14px;align-items:center;padding:12px 0;border-bottom:1px solid #edf1f5}.network-company-main{display:flex;align-items:center;gap:10px;min-width:0}.network-company-main b{display:block}.network-company-main small{display:block;color:#6b7280;font-size:12px}.network-company-logo{width:42px;height:42px;border-radius:10px;object-fit:cover;flex:0 0 42px;border:1px solid #e8edf3}.network-company-logo-fallback{display:flex;align-items:center;justify-content:center;background:#f3f5f8;font-weight:700}.network-company-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end}@media(max-width:820px){.network-company-card{align-items:flex-start;flex-direction:column}.network-company-actions{justify-content:flex-start}}</style>

    <div class="page-title"><div><h1>Company Network</h1><p>Internal company subscriptions, updates, notifications and customer reviews.</p></div></div>
    <div class="grid2">
      <div class="panel"><div class="panel-head"><h2>Find Company</h2></div><form method="get" class="search-inline"><input name="q" value="<?=e($search)?>" placeholder="Search company name"><button class="btn primary">Search</button></form>
      <?php if($search!==''):?><div class="settings-list" style="margin-top:12px"><?php foreach($companyResults as $cr): $meta=$searchCompanyMeta[(int)$cr['id']]??[]; $outStatus=$meta['status']??'none'; $inStatus=$meta['incoming_status']??null; ?><div class="network-company-card"><div class="network-company-main"><?php if(!empty($cr['logo_path'])):?><img src="<?=e(saas_company_logo_url($cr['logo_path']))?>" class="network-company-logo" alt=""><?php else:?><div class="network-company-logo network-company-logo-fallback"><?=e(strtoupper(substr($cr['name'],0,1)))?></div><?php endif;?><div><b><?=e($cr['name'])?></b><small><?=e($cr['business_type']??'')?></small><small><?=e($cr['phone']??'')?></small></div></div><div class="network-company-actions"><a class="btn small-btn" href="<?=e(url('company-network?view_company='.(int)$cr['id']))?>">View</a><?php if($outStatus==='approved'):?><span class="status paid">Subscribed</span><?php elseif($outStatus==='pending'):?><span class="status open">Request Pending</span><?php else:?><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="subscribe"><input type="hidden" name="target_company_id" value="<?=$cr['id']?>"><button class="btn small-btn">Subscribe</button></form><?php endif;?><?php if($inStatus==='approved'):?><span class="status paid">Subscriber</span><?php endif;?></div></div><?php endforeach;if(!$companyResults):?><p class="subtle">No companies found.</p><?php endif;?></div><?php endif;?></div>
      <div class="panel"><div class="panel-head"><h2>Publish Update</h2></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="publish_update"><div class="form-group"><label>Type</label><select name="update_type"><option value="text">Text Post</option><option value="product">Product</option><option value="offer">Offer</option><option value="notice">Notice</option><option value="announcement">Announcement</option></select></div><div class="form-group"><label>Title</label><input name="title" required></div><div class="form-group"><label>Message</label><textarea name="body" rows="5"></textarea></div><button class="btn primary">Publish Update</button></form></div>
    </div>
    <?php if($viewCompany): ?><div class="panel" style="margin-top:14px"><div class="panel-head"><h2><?=e($viewCompany['name'])?></h2><a class="btn" href="<?=e(url('company-network'))?>">Close</a></div><div class="grid2"><div><p><b>Phone:</b> <?=e($viewCompany['phone']??'—')?></p><p><b>Business:</b> <?=e($viewCompany['business_type']??'—')?></p><p><b>Relationship:</b> <?= $viewCompanyLinked?'<span class="status paid">Customer relationship found</span>':'<span class="status open">Not linked as customer</span>' ?></p></div><div><h3>Customer Reviews</h3><?php if($viewCompanyLinked&&$viewCompanyReviews): foreach($viewCompanyReviews as $rv): ?><div style="padding:10px 0;border-bottom:1px solid #edf1f5"><b><?=str_repeat('★',(int)$rv['rating']).str_repeat('☆',5-(int)$rv['rating'])?></b><div><?=nl2br(e($rv['comment']))?></div><small class="subtle">From <?=e($rv['reviewer_name'])?> · <?=e(date('d/m/Y',strtotime($rv['created_at'])))?></small></div><?php endforeach; elseif($viewCompanyLinked): ?><p class="subtle">No reviews yet.</p><?php else: ?><p class="subtle">Reviews are visible after this company is added as a Customer.</p><?php endif; ?></div></div></div><?php endif; ?>
    <div class="grid3" style="margin-top:14px"><div class="panel"><div class="panel-head"><h2>My Subscribers</h2><span class="subtle">Approved</span></div><?php foreach($subscriberRows as $sr):?><div class="settings-list-row"><div style="display:flex;align-items:center;gap:9px"><?php if(!empty($sr['logo_path'])):?><img src="<?=e(saas_company_logo_url($sr['logo_path']))?>" style="width:34px;height:34px;border-radius:8px;object-fit:cover" alt=""><?php else:?><div style="width:34px;height:34px;border-radius:8px;background:#eef2f7;display:flex;align-items:center;justify-content:center;font-weight:700"><?=e(strtoupper(substr($sr['subscriber_name'],0,1)))?></div><?php endif;?><div><b><?=e($sr['subscriber_name'])?></b><small><?=e($sr['business_type']??'')?></small><small><?=e($sr['phone']??'')?></small></div></div><a class="btn small-btn" href="<?=e(url('company-network?view_company='.(int)$sr['subscriber_company_id']))?>">View</a></div><?php endforeach;if(!$subscriberRows):?><p class="subtle">No approved subscribers yet.</p><?php endif;?></div><div class="panel"><div class="panel-head"><h2>Subscription Requests</h2></div><?php foreach($incoming as $rq):?><div class="settings-list-row"><div><b><?=e($rq['subscriber_name'])?></b><small>Wants to subscribe</small></div><div style="display:flex;gap:6px"><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="subscription_decision"><input type="hidden" name="subscription_id" value="<?=$rq['id']?>"><input type="hidden" name="decision" value="approve"><button class="btn small-btn">Approve</button></form><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="subscription_decision"><input type="hidden" name="subscription_id" value="<?=$rq['id']?>"><input type="hidden" name="decision" value="reject"><button class="btn small-btn">Reject</button></form></div></div><?php endforeach;if(!$incoming):?><p class="subtle">No pending requests.</p><?php endif;?></div>
      <div class="panel"><div class="panel-head"><h2>My Subscriptions</h2></div><?php foreach($subs as $sr):?><div class="settings-list-row"><div><b><?=e($sr['target_name'])?></b><small><?=e(ucfirst($sr['status']))?></small></div></div><?php endforeach;if(!$subs):?><p class="subtle">No subscriptions yet.</p><?php endif;?></div>
      <div class="panel" id="relationships"><div class="panel-head"><h2>Customer Relationships</h2></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="link_party_company"><div class="form-group"><label>Customer</label><select name="party_id" required><option value="">Select customer</option><?php foreach($customers as $cc):?><option value="<?=$cc['id']?>"><?=e($cc['name'])?><?= $cc['linked_company_id']?' · Linked: '.e($cc['linked_company_name']):'' ?></option><?php endforeach;?></select></div><div class="form-group"><label>Linked Company</label><select name="linked_company_id" required><option value="">Select company</option><?php foreach($companyResults as $cr):?><option value="<?=$cr['id']?>"><?=e($cr['name'])?></option><?php endforeach;?></select><small class="subtle">Search a company above before linking if it is not listed.</small></div><button class="btn">Link Customer Company</button></form><hr><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="review_customer"><div class="form-group"><label>Linked Customer Company</label><select name="subject_company_id" required><option value="">Select</option><?php foreach($customers as $cc):if($cc['linked_company_id']):?><option value="<?=$cc['linked_company_id']?>" data-party="<?=$cc['id']?>"><?=e($cc['linked_company_name'])?> · <?=e($cc['name'])?></option><?php endif;endforeach;?></select></div><div class="form-group"><label>Rating</label><select name="rating"><option value="5">★★★★★</option><option value="4">★★★★☆</option><option value="3">★★★☆☆</option><option value="2">★★☆☆☆</option><option value="1">★☆☆☆☆</option></select></div><div class="form-group"><label>Review</label><textarea name="comment" rows="3" required></textarea></div><input type="hidden" name="party_id" id="reviewPartyIdV179"><button class="btn primary">Submit Review</button></form><script>document.querySelector('select[name="subject_company_id"]')?.addEventListener('change',function(){document.getElementById('reviewPartyIdV179').value=this.options[this.selectedIndex].dataset.party||''});</script></div></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Updates</h2></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>COMPANY</th><th>TYPE</th><th>TITLE</th><th>MESSAGE</th></tr></thead><tbody><?php foreach($updates as $up):?><tr><td><?=e(date('d/m/Y h:i A',strtotime($up['created_at'])))?></td><td><?=e($up['company_name'])?></td><td><?=e(saas_logotype($up['update_type']))?></td><td><b><?=e($up['title'])?></b></td><td><?=nl2br(e($up['body']??''))?></td></tr><?php endforeach;if(!$updates):?><tr><td colspan="5" class="subtle">No updates yet.</td></tr><?php endif;?></tbody></table></div></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Customer Reviews About Your Company</h2></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>FROM COMPANY</th><th>RATING</th><th>REVIEW</th><th>CUSTOMER</th></tr></thead><tbody><?php foreach($receivedReviews as $rv):?><tr><td><?=e(date('d/m/Y',strtotime($rv['created_at'])))?></td><td><?=e($rv['reviewer_name'])?></td><td><?=str_repeat('★',(int)$rv['rating']).str_repeat('☆',5-(int)$rv['rating'])?></td><td><?=nl2br(e($rv['comment']))?></td><td><?=e($rv['party_name'])?></td></tr><?php endforeach;if(!$receivedReviews):?><tr><td colspan="5" class="subtle">No reviews yet.</td></tr><?php endif;?></tbody></table></div></div>
    <?php page_end(); exit;
}

if($route==='party-search-api'){
    $u=require_login(); $cid=(int)$u['company_id'];
    header('Content-Type: application/json; charset=utf-8');
    $q=trim((string)($_GET['q']??''));
    $role=trim((string)($_GET['role']??'customer'));
    if($q===''){echo json_encode(['ok'=>true,'items'=>[]],JSON_UNESCAPED_UNICODE);exit;}
    $rolesMap=[
      'customer'=>['customer'],
      'supplier'=>['supplier'],
      'both'=>['customer','supplier'],
      'all'=>['customer','supplier','investor','lender','other'],
      'customer_all'=>['customer','investor','lender','other'],
      'supplier_all'=>['supplier']
    ];
    $wanted=$rolesMap[$role]??['customer'];
    $ph=implode(',',array_fill(0,count($wanted),'?'));
    $sql='SELECT p.id,p.name,p.phone,
      COALESCE((SELECT GROUP_CONCAT(pr.role ORDER BY pr.role SEPARATOR ",") FROM party_roles pr WHERE pr.party_id=p.id),"") roles,
      COALESCE((SELECT SUM(CASE WHEN t.txn_type="sale" THEN t.due WHEN t.txn_type="payment_in" THEN -t.total WHEN t.txn_type="purchase" THEN -t.due WHEN t.txn_type="payment_out" THEN t.total ELSE 0 END) FROM transactions t WHERE t.company_id=p.company_id AND t.party_id=p.id AND t.deleted_at IS NULL),0) outstanding
      FROM parties p WHERE p.company_id=? AND p.deleted_at IS NULL
      AND EXISTS(SELECT 1 FROM party_roles xr WHERE xr.party_id=p.id AND xr.role IN ('.$ph.'))
      AND (p.name LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)
      ORDER BY p.name LIMIT 20';
    $params=[$cid,...$wanted,'%'.$q.'%','%'.$q.'%','%'.$q.'%'];
    $st=db()->prepare($sql);$st->execute($params);$items=$st->fetchAll();
    echo json_encode(['ok'=>true,'items'=>$items],JSON_UNESCAPED_UNICODE);exit;
}

if($route==='item-search-api'){
    $u=require_login(); $cid=(int)$u['company_id'];
    header('Content-Type: application/json; charset=utf-8');
    $q=trim((string)($_GET['q']??''));
    if($q===''){echo json_encode(['ok'=>true,'items'=>[]],JSON_UNESCAPED_UNICODE);exit;}
    $like='%'.$q.'%';
    $sql='SELECT i.id,i.name,i.code,i.barcode,i.item_type,i.serial_tracked,i.sale_price,i.wholesale_price,i.purchase_price,i.unit_id,
      COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm WHERE sm.company_id=i.company_id AND sm.item_id=i.id
                     AND (sm.transaction_id IS NULL OR EXISTS (
                         SELECT 1 FROM transactions st
                         WHERE st.id=sm.transaction_id
                           AND st.company_id=sm.company_id
                           AND st.deleted_at IS NULL
                     ))),0) current_stock,
      COALESCE(u.symbol,"") unit_symbol
      FROM items i LEFT JOIN units u ON u.id=i.unit_id
      WHERE i.company_id=? AND i.active=1
      AND (i.name LIKE ? OR i.code LIKE ? OR i.barcode LIKE ?)
      ORDER BY CASE WHEN i.name LIKE ? THEN 0 WHEN i.code LIKE ? THEN 1 WHEN i.barcode LIKE ? THEN 2 ELSE 3 END, i.name
      LIMIT 20';
    $params=[$cid,$like,$like,$like,$q.'%',$q.'%',$q.'%'];
    try{
      $st=db()->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
      foreach($rows as &$r){$r['current_stock']=(float)$r['current_stock'];$r['sale_price']=(float)$r['sale_price'];$r['wholesale_price']=(float)$r['wholesale_price'];$r['purchase_price']=(float)$r['purchase_price'];}
      unset($r);
      echo json_encode(['ok'=>true,'items'=>$rows],JSON_UNESCAPED_UNICODE);exit;
    }catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>'Item search failed.'],JSON_UNESCAPED_UNICODE);exit;}
}

if($route==='logout'){try{if(session_id()!=='')db()->prepare('DELETE FROM platform_sessions WHERE session_id=?')->execute([session_id()]);}catch(Throwable $e){} session_unset();session_destroy();redirect('login');}


if($route==='support'){
    require __DIR__.'/../app/support_v125.php'; exit;
}
if($route==='platform-control'){
    require __DIR__.'/../app/platform_control_v125.php'; exit;
}

if($route==='serial-search-api'){
    $u=require_login();$cid=(int)$u['company_id'];$itemId=(int)($_GET['item_id']??0);$q=trim((string)($_GET['q']??''));
    if($itemId<=0){http_response_code(422);echo json_encode(['ok'=>false,'error'=>'Invalid item.']);exit;}
    $st=db()->prepare('SELECT id,serial_tracked FROM items WHERE id=? AND company_id=? AND active=1 LIMIT 1');$st->execute([$itemId,$cid]);$it=$st->fetch();
    if(!$it||!(int)$it['serial_tracked']){echo json_encode(['ok'=>true,'items'=>[]],JSON_UNESCAPED_UNICODE);exit;}
    $sql='SELECT id,serial_number FROM item_serials WHERE company_id=? AND item_id=? AND status="available"';$params=[$cid,$itemId];
    if($q!==''){$sql.=' AND serial_number LIKE ?';$params[]='%'.$q.'%';}$sql.=' ORDER BY id DESC LIMIT 50';
    $st=db()->prepare($sql);$st->execute($params);echo json_encode(['ok'=>true,'items'=>$st->fetchAll()],JSON_UNESCAPED_UNICODE);exit;
}

if($route==='transactions'){
    page_start('Transactions');
    $cid=(int)$u['company_id'];
    $q=trim((string)($_GET['q']??''));
    $where='company_id=? AND deleted_at IS NULL'; $params=[$cid];
    if($q!==''){
        $like='%'.$q.'%';
        $where.=' AND (document_no LIKE ? OR txn_type LIKE ? OR CAST(total AS CHAR) LIKE ?)';
        array_push($params,$like,$like,$like);
    }
    $rows=[];
    try{
        $st=db()->prepare("SELECT txn_date,created_at,document_no,txn_type,total,paid,due FROM transactions WHERE $where ORDER BY txn_date DESC,id DESC LIMIT 250");
        $st->execute($params); $rows=$st->fetchAll();
    }catch(Throwable $e){
        $st=db()->prepare("SELECT txn_date,document_no,txn_type,total,paid,due FROM transactions WHERE $where ORDER BY txn_date DESC,id DESC LIMIT 250");
        $st->execute($params); $rows=$st->fetchAll();
    }
    ?><div class="page-title"><div><h1>Transactions</h1><p>All transaction records for <?=e($u['company_name'])?></p></div></div>
    <div class="panel">
      <form method="get" class="table-search"><input name="q" value="<?=e($q)?>" placeholder="Search transaction"></form>
      <div class="table-wrap"><table><thead><tr><th>Date</th><th>Document</th><th>Type</th><th>Total</th><th>Paid</th><th>Due</th></tr></thead><tbody>
      <?php foreach($rows as $r): ?>
      <?php $txTs = !empty($r['txn_date']) ? strtotime((string)$r['txn_date']) : false; $txLabel='—'; if($txTs!==false){ $baseDate=date('d/m/Y',$txTs); $timeTs=(!empty($r['created_at'])?strtotime((string)$r['created_at']):false); if($timeTs!==false && date('H:i:s',$timeTs)!=='00:00:00'){ $txLabel=$baseDate.' '.date('h:i A',$timeTs); } else { $txLabel=$baseDate; } } ?>
      <tr><td><?=e($txLabel)?></td><td><?=e((string)$r['document_no'])?></td><td><?=e(ucwords(str_replace('_',' ',(string)$r['txn_type'])))?></td><td><?=money((float)$r['total'])?></td><td><?=money((float)$r['paid'])?></td><td><?=money((float)$r['due'])?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="6" class="subtle">No transactions found.</td></tr><?php endif; ?>
      </tbody></table></div>
    </div><?php page_end(); exit;
}

if($route==='dashboard'){
    page_start('Home');
    $cid=(int)$u['company_id'];
    $currency=$u['currency_code']==='BDT'?'৳':$u['currency_code'];
    $rangeOptions=[
        'this_month'=>'This Month',
        'last_month'=>'Last Month',
        'last_30_days'=>'Last 30 Days',
        'this_quarter'=>'This Quarter',
        'this_year'=>'This Year',
    ];
    $normalizeRange=function(string $key)use($rangeOptions):string{return isset($rangeOptions[$key])?$key:'this_month';};
    $saleRange=$normalizeRange((string)($_GET['sale_range']??'this_month'));
    $expenseRange=$normalizeRange((string)($_GET['expense_range']??'this_month'));
    $rangeDates=function(string $key):array{
        $today=date('Y-m-d');
        if($key==='last_month'){
            $start=date('Y-m-01',strtotime('first day of last month'));
            $end=date('Y-m-t',strtotime('first day of last month'));
        }elseif($key==='last_30_days'){
            $start=date('Y-m-d',strtotime('-29 days')); $end=$today;
        }elseif($key==='this_quarter'){
            $m=(int)date('n'); $qStart=$m-((($m-1)%3));
            $start=date('Y-m-d',strtotime(date('Y').'-'.str_pad((string)$qStart,2,'0',STR_PAD_LEFT).'-01')); $end=$today;
        }elseif($key==='this_year'){
            $start=date('Y-01-01'); $end=$today;
        }else{
            $start=date('Y-m-01'); $end=$today;
        }
        return [$start,$end];
    };
    $buildSeries=function(string $type,string $rangeKey)use($cid,$rangeDates):array{
        [$start,$end]=$rangeDates($rangeKey);
        $startTs=strtotime($start); $endTs=strtotime($end);
        $days=(int)floor(($endTs-$startTs)/86400)+1;
        $labels=[]; $values=[];
        if($rangeKey==='this_year'){
            $first=strtotime(date('Y-01-01', $startTs));
            $last=strtotime(date('Y-m-01', $endTs));
            for($ts=$first;$ts<=$last;$ts=strtotime('+1 month', $ts)){$labels[]=date('M',$ts);$values[]=0.0;}
            $st=db()->prepare('SELECT DATE_FORMAT(txn_date,"%Y-%m") period,COALESCE(SUM(total),0) total FROM transactions WHERE company_id=? AND txn_type=? AND txn_date>=? AND txn_date<? AND deleted_at IS NULL GROUP BY DATE_FORMAT(txn_date,"%Y-%m") ORDER BY period');
            $endExclusive=date('Y-m-d',strtotime($end.' +1 day')); $st->execute([$cid,$type,$start,$endExclusive]);
            $map=[];foreach($st as $r)$map[(string)$r['period']]=(float)$r['total'];
            foreach($labels as $i=>$lbl){$key=date('Y-m',strtotime(date('Y').'-01-01 +'.$i.' months'));$values[$i]=$map[$key]??0.0;}
        }else{
            for($ts=$startTs;$ts<=$endTs;$ts+=86400){$labels[]=date('d M',$ts);$values[]=0.0;}
            $st=db()->prepare('SELECT DATE(txn_date) period,COALESCE(SUM(total),0) total FROM transactions WHERE company_id=? AND txn_type=? AND txn_date>=? AND txn_date<? AND deleted_at IS NULL GROUP BY DATE(txn_date) ORDER BY period');
            $endExclusive=date('Y-m-d',strtotime($end.' +1 day')); $st->execute([$cid,$type,$start,$endExclusive]);
            $idx=[];foreach($labels as $i=>$lbl){$idx[date('Y-m-d',$startTs+($i*86400))]=$i;}
            foreach($st as $r){$k=(string)$r['period'];if(isset($idx[$k]))$values[$idx[$k]]=(float)$r['total'];}
        }
        return ['start'=>$start,'end'=>$end,'labels'=>$labels,'values'=>$values,'total'=>array_sum($values)];
    };
    $saleData=$buildSeries('sale',$saleRange); $expenseData=$buildSeries('expense',$expenseRange);
    $saleGrowth=0.0;
    $periodDays=max(1,(int)floor((strtotime($saleData['end'])-strtotime($saleData['start']))/86400)+1);
    $prevEnd=date('Y-m-d',strtotime($saleData['start'].' -1 day')); $prevStart=date('Y-m-d',strtotime($prevEnd.' -'.($periodDays-1).' days'));
    $st=db()->prepare('SELECT COALESCE(SUM(total),0) FROM transactions WHERE company_id=? AND txn_type="sale" AND txn_date>=? AND txn_date<? AND deleted_at IS NULL');
    $st->execute([$cid,$prevStart,date('Y-m-d',strtotime($prevEnd.' +1 day'))]); $prevSale=(float)$st->fetchColumn();
    $saleGrowth=$prevSale>0?(($saleData['total']-$prevSale)/$prevSale*100):($saleData['total']>0?100:0);
    $sumType=function(string $type)use($cid){$st=db()->prepare('SELECT COALESCE(SUM(total),0) FROM transactions WHERE company_id=? AND txn_type=? AND deleted_at IS NULL');$st->execute([$cid,$type]);return(float)$st->fetchColumn();};
    $purchase=$sumType('purchase');
    $partyBalanceRows=[]; $receive=0.0; $pay=0.0;
    try {
        // Keep the dashboard resilient: calculate transaction balance first and
        // add opening balance in PHP where possible. This avoids taking the
        // entire dashboard down when an older tenant schema is missing one
        // optional party column.
        $pst=db()->prepare('SELECT p.id,p.name,COALESCE(p.opening_balance,0) opening_balance,
              (SELECT COALESCE(SUM(CASE
                WHEN t.txn_type="sale" THEN t.due
                WHEN t.txn_type="payment_in" THEN -t.total
                WHEN t.txn_type="purchase" THEN -t.due
                WHEN t.txn_type="payment_out" THEN t.total
                WHEN t.txn_type="sale_return" THEN -t.total
                WHEN t.txn_type="purchase_return" THEN t.total
                ELSE 0 END),0)
               FROM transactions t
               WHERE t.company_id=p.company_id AND t.party_id=p.id AND t.deleted_at IS NULL) AS txn_balance
            FROM parties p
            WHERE p.company_id=? AND p.deleted_at IS NULL
            ORDER BY p.name ASC');
        $pst->execute([$cid]);
        $rows=$pst->fetchAll();
        foreach($rows as $pr){
            $bal=(float)($pr['txn_balance']??0);
            // opening_balance is kept as a positive amount by the existing party form.
            // Prefer the configured opening type when the column exists.
            try {
                $typ=db()->prepare('SELECT opening_balance_type FROM parties WHERE id=? AND company_id=? LIMIT 1');
                $typ->execute([(int)$pr['id'],$cid]);
                $ot=(string)($typ->fetchColumn()??'receivable');
            } catch(Throwable $ignore) {
                $ot='receivable';
            }
            $ob=(float)($pr['opening_balance']??0);
            if(in_array($ot,['payable','loan_taken'],true)) $bal-=$ob;
            elseif(in_array($ot,['receivable','loan_given'],true)) $bal+=$ob;
            if(abs($bal)<=0.009) continue;
            $partyBalanceRows[]=['id'=>(int)$pr['id'],'name'=>(string)$pr['name'],'balance'=>$bal];
            if($bal>0)$receive+=$bal; else $pay+=abs($bal);
        }
    } catch(Throwable $e) {
        // Never let the dashboard become blank because of optional party data.
        // Fall back to transaction-only balances and keep the rest of the dashboard usable.
        $partyBalanceRows=[]; $receive=0.0; $pay=0.0;
        try {
            $fst=db()->prepare('SELECT p.id,p.name,COALESCE(SUM(CASE
                WHEN t.txn_type="sale" THEN t.due
                WHEN t.txn_type="payment_in" THEN -t.total
                WHEN t.txn_type="purchase" THEN -t.due
                WHEN t.txn_type="payment_out" THEN t.total
                WHEN t.txn_type="sale_return" THEN -t.total
                WHEN t.txn_type="purchase_return" THEN t.total
                ELSE 0 END),0) balance
              FROM parties p LEFT JOIN transactions t
                ON t.company_id=p.company_id AND t.party_id=p.id AND t.deleted_at IS NULL
              WHERE p.company_id=? AND p.deleted_at IS NULL
              GROUP BY p.id,p.name
              HAVING ABS(balance)>0.009
              ORDER BY ABS(balance) DESC,p.name ASC');
            $fst->execute([$cid]);
            foreach($fst as $pr){
                $bal=(float)$pr['balance'];
                $partyBalanceRows[]=['id'=>(int)$pr['id'],'name'=>(string)$pr['name'],'balance'=>$bal];
                if($bal>0)$receive+=$bal; else $pay+=abs($bal);
            }
        } catch(Throwable $ignore) {}
    }
    $cash=company_cash_balance($cid);
    $st=db()->prepare('SELECT i.name,COALESCE(sm.qty,0) stock,i.low_stock_limit FROM items i LEFT JOIN (SELECT sm2.item_id,SUM(sm2.quantity) qty
             FROM stock_movements sm2
             WHERE sm2.company_id=?
               AND (sm2.transaction_id IS NULL OR EXISTS (
                   SELECT 1 FROM transactions st2
                   WHERE st2.id=sm2.transaction_id
                     AND st2.company_id=sm2.company_id
                     AND st2.deleted_at IS NULL
               ))
             GROUP BY sm2.item_id) sm ON sm.item_id=i.id WHERE i.company_id=? AND i.item_type="product" AND i.active=1 HAVING stock<=i.low_stock_limit ORDER BY stock LIMIT 5');$st->execute([$cid,$cid]);$low=$st->fetchAll();
    $st=db()->prepare('SELECT COALESCE(SUM(COALESCE(sm.qty,0)*COALESCE(NULLIF(i.purchase_price,0),(SELECT ti.unit_price FROM transaction_items ti JOIN transactions pt ON pt.id=ti.transaction_id WHERE ti.item_id=i.id AND pt.company_id=i.company_id AND pt.txn_type="purchase" AND pt.deleted_at IS NULL ORDER BY pt.txn_date DESC,pt.id DESC,ti.id DESC LIMIT 1),0)),0) FROM items i LEFT JOIN (SELECT sm2.item_id,SUM(sm2.quantity) qty
             FROM stock_movements sm2
             WHERE sm2.company_id=?
               AND (sm2.transaction_id IS NULL OR EXISTS (
                   SELECT 1 FROM transactions st2
                   WHERE st2.id=sm2.transaction_id
                     AND st2.company_id=sm2.company_id
                     AND st2.deleted_at IS NULL
               ))
             GROUP BY sm2.item_id) sm ON sm.item_id=i.id WHERE i.company_id=? AND i.item_type="product" AND i.active=1');$st->execute([$cid,$cid]);$stockValue=(float)$st->fetchColumn();
    $chartSvg=function(array $vals,int $w,int $h,string $stroke):string{
        $n=count($vals);if($n<2)return '';$max=max($vals);$min=min($vals);if(abs($max-$min)<0.000001){$min=0;$max=max(1,$max);} $range=$max-$min;
        $pad=6;$pw=$w-12;$ph=$h-24;$pts=[];foreach($vals as $i=>$v){$x=$pad+($i/max(1,$n-1))*$pw;$y=10+($ph-(($v-$min)/$range)*$ph);$pts[]=round($x,1).','.round($y,1);} $poly=implode(' ',$pts);$baseY=10+$ph;$area=$pad.','.$baseY.' '.$poly.' '.($w-$pad).','.$baseY;$gid='c'.substr(md5($poly.$stroke.$w.$h),0,10);
        return '<svg class="dashboard-chart-svg" viewBox="0 0 '.$w.' '.$h.'" preserveAspectRatio="none" aria-hidden="true"><defs><linearGradient id="'.$gid.'" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="'.$stroke.'" stop-opacity="0.18"/><stop offset="100%" stop-color="'.$stroke.'" stop-opacity="0.02"/></linearGradient></defs><polygon points="'.$area.'" fill="url(#'.$gid.')"></polygon><polyline points="'.$poly.'" fill="none" stroke="'.$stroke.'" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"></polyline></svg>';
    };
    $report=function(array $d):string{return date('d M',strtotime($d['start'])).' to '.date('d M',strtotime($d['end']));};
    $rangeForm=function(string $name,string $current,string $otherName,string $other)use($rangeOptions):string{
        $html='<form method="get" class="dashboard-range-form"><input type="hidden" name="'.e($otherName).'" value="'.e($other).'">';
        $html.='<select class="range" name="'.e($name).'" onchange="this.form.submit()">';
        foreach($rangeOptions as $k=>$label)$html.='<option value="'.e($k).'"'.($k===$current?' selected':'').'>'.e($label).'</option>';
        $html.='</select></form>';return $html;
    };
    $saleRangeLabel=$rangeOptions[$saleRange]; $expenseRangeLabel=$rangeOptions[$expenseRange];
?><div class="dashboard-page" id="dashboardPage">
  <?php $platformNotices=platform_active_announcements($cid); if($platformNotices): ?>
    <div class="platform-notices">
      <?php foreach($platformNotices as $pn): ?>
        <div class="platform-notice platform-notice-<?=e((string)$pn['type'])?>">
          <div class="platform-notice-icon">●</div>
          <div class="platform-notice-body"><div class="platform-notice-title"><?=e($pn['title'])?></div><div class="platform-notice-text"><?=nl2br(e($pn['body']))?></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div class="dashboard-grid">
    <section class="dashboard-blur-target">
      <div class="cards-top">
        <div class="sales-card">
          <div class="card-head"><h3>▱ Sale</h3><?=$rangeForm('sale_range',$saleRange,'expense_range',$expenseRange)?></div>
          <div class="big-money"><?=money($saleData['total'],$currency)?></div>
          <div class="subtle">Total Sale (<?=e($saleRangeLabel)?>)</div>
          <div class="growth <?= $saleGrowth<0?'negative':'' ?>"><?=($saleGrowth>=0?'↑ ':'↓ ').number_format(abs($saleGrowth),2)?> % <span class="subtle">Growth vs previous period</span></div>
          <div class="chart dashboard-chart-wrap"><?= $chartSvg($saleData['values'],620,170,'#10b981') ?><div class="chart-baseline"></div></div>
          <div class="subtle dashboard-report">Report: From <?=e($report($saleData))?></div>
        </div>
        <div class="expense-card">
          <div class="card-head"><h3>▤ Expenses</h3><?=$rangeForm('expense_range',$expenseRange,'sale_range',$saleRange)?></div>
          <div class="big-money"><?=money($expenseData['total'],$currency)?></div>
          <div class="subtle">Total Expenses (<?=e($expenseRangeLabel)?>)</div>
          <div class="chart dashboard-chart-wrap expense-chart"><?= $chartSvg($expenseData['values'],460,150,'#10b981') ?><div class="chart-baseline"></div></div>
          <div class="subtle dashboard-report">Report: From <?=e($report($expenseData))?></div>
        </div>
      </div>
      <div class="mid-cards">
        <div class="metric-card receivable-card">
          <div class="label" style="color:#16a34a">↓ You'll Receive</div>
          <div class="value"><?=money((float)$receive,$currency)?></div>
          <div class="party-balance-list">
            <?php $receivableRows=array_values(array_filter($partyBalanceRows,static fn($r)=>(float)$r['balance']>0)); $rShown=0; foreach($receivableRows as $pr): if($rShown>=4) break; $rShown++; ?>
              <a class="party-balance-row" href="<?=e(url('parties?id='.(int)$pr['id']))?>"><span><?=e($pr['name'])?></span><strong><?=money((float)$pr['balance'],$currency)?></strong></a>
            <?php endforeach; ?>
            <?php $rMore=max(0,count($receivableRows)-$rShown); if($rMore>0): ?><div class="party-more">+ <?=$rMore?> More</div><?php elseif(!$rShown): ?><div class="empty">You don't have any pending amount to be received</div><?php endif; ?>
          </div>
        </div>
        <div class="metric-card payable-card">
          <div class="label" style="color:#ef4444">↑ You'll Pay</div>
          <div class="value"><?=money((float)$pay,$currency)?></div>
          <div class="party-balance-list">
            <?php $payableRows=array_values(array_filter($partyBalanceRows,static fn($r)=>(float)$r['balance']<0)); $pShown=0; foreach($payableRows as $pr): if($pShown>=4) break; $pShown++; ?>
              <a class="party-balance-row" href="<?=e(url('parties?id='.(int)$pr['id']))?>"><span><?=e($pr['name'])?></span><strong class="payable-amount"><?=money(abs((float)$pr['balance']),$currency)?></strong></a>
            <?php endforeach; ?>
            <?php $pMore=max(0,count($payableRows)-$pShown); if($pMore>0): ?><div class="party-more">+ <?=$pMore?> More</div><?php elseif(!$pShown): ?><div class="empty">You don't have any pending amount to be paid</div><?php endif; ?>
          </div>
        </div>
        <div class="metric-card"><div class="label">🛒 Purchase <span class="subtle">This Month</span></div><div class="value"><?=money($purchase,$currency)?></div><div class="empty"><?= $purchase>0?'Purchase transactions entered this month.':'You have no purchased items entered for selected time.' ?></div></div>
      </div>
      <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Recent Transactions</h2><a href="<?=e(url('transactions'))?>">View all</a></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Document</th><th>Type</th><th>Total</th><th>Due</th></tr></thead><tbody><?php $st=db()->prepare('SELECT txn_date,document_no,txn_type,total,due FROM transactions WHERE company_id=? AND deleted_at IS NULL ORDER BY id DESC LIMIT 8');$st->execute([$cid]);foreach($st as $r):?><tr><td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td><td><?=e($r['document_no'])?></td><td><?=e(ucwords(str_replace('_',' ',$r['txn_type'])))?></td><td><?=money((float)$r['total'])?></td><td><?=money((float)$r['due'])?></td></tr><?php endforeach;if(!$st->rowCount()):?><tr><td colspan="5" class="subtle">No recent transactions.</td></tr><?php endif;?></tbody></table></div></div>
    </section>
    <aside class="right-stack">
      <div class="privacy"><span>Privacy</span><button type="button" class="privacy-toggle" id="privacyToggle" aria-pressed="false"><span class="privacy-dot"></span><span class="privacy-state">Off</span></button></div>
      <div class="dashboard-sensitive-right dashboard-blur-target">
        <div class="right-head">Pinned cards</div><div class="right-card"><span class="pin-star">★</span><div class="title">Stock Value</div><div class="value"><?=money($stockValue,$currency)?></div></div>
        <a href="<?=e(url('cash'))?>" class="right-card dashboard-cash-link"><span class="pin-star">★</span><div class="title">Cash In hand</div><div class="value" style="color:#10b981"><?=money($cash,$currency)?></div></a>
        <div class="right-head">Stock Inventory</div><div class="right-card low"><div class="title">Low Stocks</div><?php if($low):foreach($low as $l):?><div style="display:flex;justify-content:space-between;margin-top:10px;font-size:13px"><span><?=e($l['name'])?></span><span style="color:#ef4444"><?=number_format((float)$l['stock'],0)?></span></div><?php endforeach;else:?><div class="subtle" style="margin-top:10px">No low stock items.</div><?php endif;?></div>
        <div class="right-head">Cash & Bank</div><div class="right-card"><div class="title">Bank Accounts</div><div class="value"><?=(int)db()->query('SELECT COUNT(*) FROM bank_accounts WHERE company_id='.(int)$cid)->fetchColumn()?></div></div>
        <div class="right-card"><div class="title">Loan Accounts</div><div class="value">0</div></div>
        <div class="right-card"><div class="title">Sale</div><div class="value"><?=money($saleData['total'],$currency)?></div></div>
        <div class="right-card"><div class="title">Sale Orders</div><div class="value"><?php $st=db()->prepare('SELECT COUNT(*) FROM transactions WHERE company_id=? AND txn_type="sale_order" AND deleted_at IS NULL');$st->execute([$cid]);echo (int)$st->fetchColumn();?></div></div>
        <div class="right-card"><div class="title">Delivery Challan</div><div class="value"><?php $st=db()->prepare('SELECT COUNT(*) FROM transactions WHERE company_id=? AND txn_type="delivery_challan" AND deleted_at IS NULL');$st->execute([$cid]);echo (int)$st->fetchColumn();?></div></div>
        <div class="right-card"><div class="title">Purchase</div><div class="value"><?=money($purchase,$currency)?></div></div>
      </div>
    </aside>
  </div>
</div>
<script>
(function(){
  const page=document.getElementById('dashboardPage');
  const btn=document.getElementById('privacyToggle');
  if(!page||!btn)return;
  const key='suto_dashboard_privacy';
  const apply=(on)=>{page.classList.toggle('privacy-on',on);btn.setAttribute('aria-pressed',on?'true':'false');const state=btn.querySelector('.privacy-state');if(state)state.textContent=on?'On':'Off';localStorage.setItem(key,on?'1':'0');};
  let on=false;try{on=localStorage.getItem(key)==='1';}catch(e){}
  apply(on);btn.addEventListener('click',()=>apply(!page.classList.contains('privacy-on')));
})();
</script><?php page_end();exit;}

if($route==='parties'){
    page_start('Parties');
    $cid=(int)$u['company_id'];
    $roleLabels=[
        'customer'=>'Customer','supplier'=>'Supplier','investor'=>'Investor','lender'=>'Lender',
        'borrower'=>'Borrower','employee'=>'Employee','other'=>'Other'
    ];
    $validRoles=array_keys($roleLabels);
    $derivePartyType=function(array $roles){
        $hasCustomer=in_array('customer',$roles,true);
        $hasSupplier=in_array('supplier',$roles,true);
        return $hasCustomer&&$hasSupplier?'both':($hasSupplier?'supplier':'customer');
    };
    $roleText=function(array $roles)use($roleLabels){
        $out=[]; foreach($roles as $r){if(isset($roleLabels[$r]))$out[]=$roleLabels[$r];}
        return implode(', ',$out);
    };
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $action=$_POST['party_action']??'create';
        $name=trim($_POST['name']??'');
        $phone=preg_replace('/\D+/','',$_POST['phone']??'');
        $email=trim($_POST['email']??'');
        $roles=array_values(array_unique(array_intersect($validRoles,(array)($_POST['party_roles']??[]))));
        $address=trim($_POST['address']??'');
        $opening=(float)($_POST['opening_balance']??0);
        $openingType=$_POST['opening_balance_type']??'receivable';
        $limit=(float)($_POST['credit_limit']??0);
        if(!$name){flash('error','Party name is required.');redirect('parties');}
        if(!preg_match('/^(013|014|015|016|017|018|019)\d{8}$/',$phone)){flash('error','Phone must be 11 digits and start with 013–019.');redirect('parties');}
        if(!$roles){flash('error','Select at least one party role.');redirect('parties');}
        if(!in_array($openingType,['receivable','payable','capital','loan_given','loan_taken'],true))$openingType='receivable';
        $ptype=$derivePartyType($roles);
        $pdo=db();
        try{
            if($action==='delete'){
                $id=(int)($_POST['id']??0);
                if($id<=0)throw new RuntimeException('Invalid party.');
                $pdo->prepare('UPDATE parties SET deleted_at=NOW() WHERE id=? AND company_id=?')->execute([$id,$cid]);
                audit('delete','party',$id); flash('success','Party moved to Recycle Bin.'); redirect('parties');
            }
            if($action==='update'){
                $id=(int)($_POST['id']??0);
                $check=$pdo->prepare('SELECT id FROM parties WHERE company_id=? AND phone=? AND id<>? LIMIT 1');$check->execute([$cid,$phone,$id]);
                if($check->fetchColumn()){flash('error','This phone number already belongs to another party.');redirect('parties');}
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE parties SET name=?,phone=?,email=?,party_type=?,address=?,opening_balance=?,opening_balance_type=?,credit_limit=? WHERE id=? AND company_id=?')->execute([$name,$phone,$email,$ptype,$address,$opening,$openingType,$limit,$id,$cid]);
                $pdo->prepare('DELETE FROM party_roles WHERE party_id=?')->execute([$id]);
                $ins=$pdo->prepare('INSERT INTO party_roles(party_id,role) VALUES(?,?)'); foreach($roles as $r)$ins->execute([$id,$r]);
                $pdo->commit();
                audit('update','party',$id,['name'=>$name,'phone'=>$phone,'roles'=>$roles]);flash('success','Party updated successfully.');redirect('parties');
            }
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO parties(company_id,name,phone,email,party_type,address,opening_balance,opening_balance_type,credit_limit) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$cid,$name,$phone,$email,$ptype,$address,$opening,$openingType,$limit]);
            $id=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare('INSERT INTO party_roles(party_id,role) VALUES(?,?)'); foreach($roles as $r)$ins->execute([$id,$r]);
            $pdo->commit();
            audit('create','party',$id,['name'=>$name,'phone'=>$phone,'roles'=>$roles]); flash('success','Party added successfully.');
        }catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getCode()==='23000'?'This phone number already belongs to another party.':'Could not save party.');}
        redirect('parties');
    }
    $q=trim($_GET['q']??'');
    $type=$_GET['type']??'all';
    $selectedId=(int)($_GET['id']??0);
    $txq=trim($_GET['txq']??'');
    $sql='SELECT p.*, COALESCE((SELECT GROUP_CONCAT(pr.role ORDER BY pr.role SEPARATOR ", ") FROM party_roles pr WHERE pr.party_id=p.id),"") role_list,
      (SELECT COALESCE(SUM(CASE WHEN t.txn_type="sale" THEN t.due WHEN t.txn_type="payment_in" THEN -t.total WHEN t.txn_type="purchase" THEN -t.due WHEN t.txn_type="payment_out" THEN t.total ELSE 0 END),0)
       FROM transactions t WHERE t.company_id=p.company_id AND t.party_id=p.id AND t.deleted_at IS NULL) AS calculated_balance
      FROM parties p WHERE p.company_id=? AND p.deleted_at IS NULL';
    $params=[$cid];
    if($q!==''){$sql.=' AND (p.name LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like);}
    if(in_array($type,$validRoles,true)){$sql.=' AND EXISTS(SELECT 1 FROM party_roles fr WHERE fr.party_id=p.id AND fr.role=?)';$params[]=$type;}
    if($type==='both'){$sql.=' AND EXISTS(SELECT 1 FROM party_roles fc WHERE fc.party_id=p.id AND fc.role="customer") AND EXISTS(SELECT 1 FROM party_roles fs WHERE fs.party_id=p.id AND fs.role="supplier")';}
    $sql.=' ORDER BY p.name ASC';
    $st=db()->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
    foreach($rows as &$r){$r['roles']=$r['role_list']!==''?array_map('trim',explode(',',$r['role_list'])):[];}unset($r);
    $selected=null;
    foreach($rows as $r){if((int)$r['id']===$selectedId){$selected=$r;break;}}
    if(!$selected && $rows){$selected=$rows[0];$selectedId=(int)$selected['id'];}
    if($selectedId && !$selected){
        $pst=db()->prepare('SELECT p.*,COALESCE((SELECT GROUP_CONCAT(pr.role ORDER BY pr.role SEPARATOR ", ") FROM party_roles pr WHERE pr.party_id=p.id),"") role_list FROM parties p WHERE p.id=? AND p.company_id=? AND p.deleted_at IS NULL LIMIT 1');
        $pst->execute([$selectedId,$cid]); $selected=$pst->fetch() ?: null;
        if($selected){$selected['roles']=$selected['role_list']!==''?array_map('trim',explode(',',$selected['role_list'])):[];}
    }
    $transactions=[];
    if($selected){
        $txSql='SELECT t.id,t.txn_type,t.document_no,t.txn_date,t.total,t.paid,t.due,t.status FROM transactions t WHERE t.company_id=? AND t.party_id=? AND t.deleted_at IS NULL';
        $txParams=[$cid,$selectedId];
        if($txq!==''){$txSql.=' AND (t.document_no LIKE ? OR t.txn_type LIKE ? OR t.status LIKE ?)';$tl='%'.$txq.'%';array_push($txParams,$tl,$tl,$tl);}
        $txSql.=' ORDER BY t.txn_date DESC,t.id DESC LIMIT 200';
        $txs=db()->prepare($txSql);$txs->execute($txParams);$transactions=$txs->fetchAll();
    }
    $partyUrl=function($extra=[])use($type,$q){$base=['type'=>$type];if($q!=='')$base['q']=$q;return url('parties?'.http_build_query(array_merge($base,$extra)));};
    $selectedRoles=$selected['roles']??[];
    $balance=(float)($selected['opening_balance']??0)+(float)($selected['calculated_balance']??0);
    $roleNames=[];foreach($selectedRoles as $rr){$roleNames[]=$roleLabels[$rr]??ucfirst($rr);}
    $roleNamesText=implode(' · ',$roleNames);
    ?>
    <div class="parties-tabs-v110">
      <?php $tabs=[
        'all'=>'All Party','customer'=>'Customer','supplier'=>'Supplier','investor'=>'Investor','lender'=>'Lender','borrower'=>'Borrower','employee'=>'Employee','other'=>'Other'
      ]; foreach($tabs as $tv=>$tl): ?>
        <a class="parties-tab-v110 <?=$type===$tv?'active':''?>" href="<?=e($partyUrl(['type'=>$tv,'id'=>$selectedId]))?>"><?=e($tl)?></a>
      <?php endforeach; ?>
    </div>
    <div class="parties-layout-v110">
      <aside class="parties-sidebar-v110">
        <div class="party-import-card-v110"><a href="<?=e(url('import-parties'))?>"><span class="party-import-icon">↥</span><span><b>Import Parties</b><small>Use contacts from your Phone or Gmail to create parties.</small></span><span class="party-import-arrow">›</span></a></div>
        <div class="party-list-tools-v110 party-tools-v111-search">
          <form class="party-list-search-v110 party-search-always-v111" method="get" id="partyListSearchForm">
            <input type="hidden" name="type" value="<?=e($type)?>"><?php if($selectedId):?><input type="hidden" name="id" value="<?=$selectedId?>"><?php endif;?>
            <input class="input party-search-input-v111" name="q" value="<?=e($q)?>" placeholder="Search Party" autocomplete="off">
          </form>
          <a class="btn party-add-v110" href="javascript:void(0)" onclick="resetPartyForm();openModal('partyModal')">⊕ Add Party <span>＋</span></a>
        </div>
        <div class="party-list-head-v110"><span>PARTY</span><span>AMOUNT</span></div>
        <div class="party-list-v110">
          <?php foreach($rows as $r): $rb=(float)$r['opening_balance']+(float)$r['calculated_balance']; $href=$partyUrl(['id'=>(int)$r['id']]); ?>
            <div class="party-list-row-v110 <?=((int)$r['id']===$selectedId?'active':'')?>">
              <a class="party-row-link-v111" href="<?=e($href)?>">
                <span class="party-avatar-v110">@</span>
                <span class="party-main-v110"><b><?=e($r['name'])?></b><small><?=e($r['phone'])?></small></span>
                <span class="party-amount-v110 <?=$rb<0?'negative':''?>"><?=money(abs($rb))?></span>
              </a>
              <button type="button" class="party-dots-v110" aria-label="Party actions" onclick="event.preventDefault();event.stopPropagation();const m=this.nextElementSibling;document.querySelectorAll('.party-row-menu-v110.show').forEach(x=>{if(x!==m)x.classList.remove('show')});m.classList.toggle('show')">⋮</button>
              <div class="party-row-menu-v110" onclick="event.stopPropagation();">
                <button type="button" onclick='editParty(<?=json_encode($r,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>);this.parentElement.classList.remove('show')'>View / Edit</button>
                <a href="<?=e(url('party-ledger?id='.(int)$r['id']))?>">Ledger</a>
                <form method="post" onsubmit="return confirm('Move this party to Recycle Bin?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="party_action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button type="submit">Delete</button></form>
              </div>
            </div>
          <?php endforeach; if(!$rows): ?><div class="party-empty-v110">No parties found.</div><?php endif; ?>
        </div>
      </aside>
      <section class="party-detail-v110">
        <?php if($selected): ?>
          <div class="party-detail-card-v110">
            <div class="party-detail-top-v110"><div><h2>@ <?=e($selected['name'])?></h2><div class="party-role-line-v110"><?=e($roleNamesText)?></div></div><div class="party-address-v110">Address: <?=e($selected['address']??'')?></div></div>
            <div class="party-detail-grid-v110">
              <div><span>Phone:</span> <?=e($selected['phone'])?></div>
              <div><span>Email:</span> <?=e($selected['email']??'')?></div>
              <div><span>Credit Limit:</span> <?=money((float)$selected['credit_limit'])?></div>
              <div><span>Current Balance:</span> <strong class="<?=$balance<0?'balance-negative':'balance-positive'?>"><?=money(abs($balance))?></strong></div>
            </div>
          </div>
          <div class="party-transactions-v110">
            <div class="party-trans-head-v110"><h2>TRANSACTIONS</h2><form method="get"><input type="hidden" name="type" value="<?=e($type)?>"><input type="hidden" name="id" value="<?=$selectedId?>"><?php if($q!==''):?><input type="hidden" name="q" value="<?=e($q)?>"><?php endif;?><input class="input" name="txq" value="<?=e($txq)?>" placeholder="Search"></form></div>
            <div class="party-tx-wrap-v110"><table><thead><tr><th></th><th>TYPE</th><th>NUMBER</th><th>DATE</th><th>TOTAL</th><th>BALANCE / UNUSED</th><th>STATUS</th><th></th></tr></thead><tbody>
              <?php foreach($transactions as $tr): $isIn=in_array($tr['txn_type'],['payment_in','sale_return'],true); $bal=(float)$tr['due']; ?>
                <tr><td><span class="party-tx-dot-v110 <?=$isIn?'in':'out'?>"></span></td><td><?=e(ucwords(str_replace('_',' ',$tr['txn_type'])))?></td><td><?=e($tr['document_no'])?></td><td><?=e(!empty($tr['txn_date'])?date('d/m/Y',strtotime($tr['txn_date'])):'—')?></td><td class="<?=$isIn?'tx-in':'tx-out'?>"><?=money((float)$tr['total'])?></td><td><?=money(abs($bal))?></td><td><span class="status <?=$tr['status']==='paid'?'paid':'open'?>"><?=e(ucfirst($tr['status']))?></span></td><td class="party-tx-action-v110"><button type="button" class="dots" aria-label="Actions">⋮</button><div class="row-menu"><a href="<?=e(url('party-ledger?id='.$selectedId))?>">View Ledger</a></div></td></tr>
              <?php endforeach; if(!$transactions): ?><tr><td colspan="8" class="subtle">No transactions for this party yet.</td></tr><?php endif; ?>
            </tbody></table></div>
          </div>
        <?php else: ?>
          <div class="party-no-selection-v110"><h2>No Party Selected</h2><p>Add a party or select one from the list.</p><button class="btn primary" onclick="resetPartyForm();openModal('partyModal')">⊕ Add Party</button></div>
        <?php endif; ?>
      </section>
    </div>
    <div class="modal-backdrop" id="partyModal" onclick="if(event.target===this)closeModal('partyModal')"><div class="modal"><div class="modal-head"><h2 id="partyModalTitle">Add Party</h2><button class="close" type="button" onclick="closeModal('partyModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="party_action" id="party_action" value="create"><input type="hidden" name="id" id="party_id"><div class="grid2"><div class="form-group"><label>Party Name*</label><input name="name" id="party_name" required></div><div class="form-group"><label>Phone Number*</label><input name="phone" id="party_phone" required maxlength="11" inputmode="numeric" pattern="(013|014|015|016|017|018|019)[0-9]{8}" placeholder="01712345678"></div><div class="form-group"><label>Email ID</label><input type="email" name="email" id="party_email"></div><div class="form-group span2"><label>Party Role(s)*</label><div class="party-role-grid"><?php foreach($roleLabels as $rv=>$rl):?><label class="check-role"><input type="checkbox" name="party_roles[]" value="<?=e($rv)?>" id="party_role_<?=$rv?>"><span><?=e($rl)?></span></label><?php endforeach;?></div><div class="subtle">A party can have multiple roles.</div></div><div class="form-group span2"><label>Billing / Contact Address</label><textarea name="address" id="party_address"></textarea></div><div class="form-group"><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" id="party_opening" value="0"></div><div class="form-group"><label>Opening Balance Type</label><select name="opening_balance_type" id="party_opening_type"><option value="receivable">Receivable</option><option value="payable">Payable</option><option value="capital">Investment / Capital</option><option value="loan_given">Loan Given</option><option value="loan_taken">Loan Taken</option></select></div><div class="form-group"><label>Credit Limit</label><input type="number" step="0.01" name="credit_limit" id="party_limit" value="0"></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('partyModal')">Cancel</button><button class="btn" type="submit" name="save_new" onclick="document.getElementById('party_action').value='create'">Save & New</button><button class="btn primary" type="submit">Save</button></div></form></div></div>
    <script>
    document.getElementById('partyListSearchBtn')?.addEventListener('click',()=>{const f=document.getElementById('partyListSearchForm');f?.classList.toggle('show');f?.querySelector('input[name="q"]')?.focus();});
    document.addEventListener('click',e=>{const menu=e.target.closest('.party-row-menu-v110,.party-dots-v110');if(menu)return;document.querySelectorAll('.party-row-menu-v110.show').forEach(x=>x.classList.remove('show'));});
    function resetPartyForm(){document.getElementById('partyModalTitle').textContent='Add Party';document.getElementById('party_action').value='create';document.getElementById('party_id').value='';document.getElementById('party_name').value='';document.getElementById('party_phone').value='';document.getElementById('party_email').value='';document.getElementById('party_address').value='';document.getElementById('party_opening').value='0';document.getElementById('party_opening_type').value='receivable';document.getElementById('party_limit').value='0';document.querySelectorAll('#partyModal input[name="party_roles[]"]').forEach(x=>x.checked=false);}
    function editParty(p){document.getElementById('partyModalTitle').textContent='Edit Party';document.getElementById('party_action').value='update';document.getElementById('party_id').value=p.id;document.getElementById('party_name').value=p.name||'';document.getElementById('party_phone').value=p.phone||'';document.getElementById('party_email').value=p.email||'';document.getElementById('party_address').value=p.address||'';document.getElementById('party_opening').value=p.opening_balance||0;document.getElementById('party_opening_type').value=p.opening_balance_type||'receivable';document.getElementById('party_limit').value=p.credit_limit||0;document.querySelectorAll('#partyModal input[name="party_roles[]"]').forEach(x=>x.checked=Array.isArray(p.roles)&&p.roles.includes(x.value));openModal('partyModal');}
    </script><?php page_end();exit;}
if($route==='party-ledger'){
    $cid=(int)$u['company_id'];$pid=(int)($_GET['id']??0);$st=db()->prepare('SELECT p.*,COALESCE((SELECT GROUP_CONCAT(pr.role ORDER BY pr.role SEPARATOR ", ") FROM party_roles pr WHERE pr.party_id=p.id),"") role_list FROM parties p WHERE p.id=? AND p.company_id=? AND p.deleted_at IS NULL');$st->execute([$pid,$cid]);$party=$st->fetch();if(!$party){http_response_code(404);exit('Party not found.');}
    $roles=$party['role_list']!==''?array_map('trim',explode(',',$party['role_list'])):[];$roleLabels=['customer'=>'Customer','supplier'=>'Supplier','investor'=>'Investor','lender'=>'Lender','borrower'=>'Borrower','employee'=>'Employee','other'=>'Other'];$roleText=function(array $rs)use($roleLabels){$out=[];foreach($rs as $rr){if(isset($roleLabels[$rr]))$out[]=$roleLabels[$rr];}return implode(', ',$out);};
    page_start('Party Ledger');$tx=db()->prepare('SELECT t.txn_date,t.document_no,t.txn_type,t.total,t.paid,t.due FROM transactions t WHERE t.company_id=? AND t.party_id=? AND t.deleted_at IS NULL ORDER BY t.txn_date DESC,t.id DESC');$tx->execute([$cid,$pid]);$rows=$tx->fetchAll();
    ?><div class="page-title"><div><h1><?=e($party['name'])?></h1><p><?=e($party['phone'])?> · <?=e($roleText($roles))?></p></div><a class="btn" href="<?=e(url('parties'))?>">← Back to Parties</a></div><div class="cards-top"><div class="metric-card"><div class="label">Opening Balance</div><div class="value"><?=money((float)$party['opening_balance'])?></div><div class="subtle"><?=e(ucwords(str_replace('_',' ',$party['opening_balance_type']??'receivable')))?></div></div><div class="metric-card"><div class="label">Credit Limit</div><div class="value"><?=money((float)$party['credit_limit'])?></div></div><div class="metric-card"><div class="label">Transactions</div><div class="value"><?=count($rows)?></div></div></div><div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Party Roles</h2></div><div class="panel-body"><div class="party-role-list"><?php foreach($roles as $rr):?><span class="status open"><?=e($roleLabels[$rr]??ucfirst($rr))?></span><?php endforeach;?></div></div></div><div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Ledger</h2></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>DOCUMENT</th><th>TYPE</th><th>TOTAL</th><th>PAID</th><th>DUE</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td><td><?=e($r['document_no'])?></td><td><?=e(ucwords(str_replace('_',' ',$r['txn_type'])))?></td><td><?=money((float)$r['total'])?></td><td><?=money((float)$r['paid'])?></td><td><?=money((float)$r['due'])?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6" class="subtle">No transactions for this party yet.</td></tr><?php endif;?></tbody></table></div></div><?php page_end();exit;}
if($route==='item-ledger'){
    $u=require_login(); $cid=(int)$u['company_id']; $pdo=db();
    $itemId=(int)($_GET['item']??0);
    $st=$pdo->prepare('SELECT i.*,c.name category_name,u.name unit_name FROM items i LEFT JOIN categories c ON c.id=i.category_id LEFT JOIN units u ON u.id=i.unit_id WHERE i.id=? AND i.company_id=? AND i.active=1 LIMIT 1');
    $st->execute([$itemId,$cid]); $item=$st->fetch();
    if(!$item) throw new RuntimeException('Item not found.');
    $st=$pdo->prepare('SELECT sm.id,sm.movement_date,sm.quantity,sm.unit_price,sm.movement_type,sm.note
            FROM stock_movements sm
            WHERE sm.company_id=? AND sm.item_id=?
              AND (sm.transaction_id IS NULL OR EXISTS (
                  SELECT 1 FROM transactions st
                  WHERE st.id=sm.transaction_id
                    AND st.company_id=sm.company_id
                    AND st.deleted_at IS NULL
              ))
            ORDER BY sm.movement_date,sm.id');
    $st->execute([$cid,$itemId]); $moves=$st->fetchAll();
    $running=0.0; foreach($moves as &$mv){$running+=(float)$mv['quantity'];$mv['running_stock']=$running;} unset($mv);
    page_start('Item Stock Ledger'); ?>
    <div class="page-title"><div><h1>Item Stock Ledger</h1><p><?=e($item['name'])?></p></div><a class="btn" href="<?=e(url('items?tab='.($item['item_type']==='service'?'services':'products').'&view='.$itemId))?>">Back to Item</a></div>
    <div class="panel item-ledger-head">
      <div><b><?=e($item['name'])?></b><span class="subtle"><?=e($item['code']??'')?></span></div>
      <div class="ledger-summary">Current Stock <b><?=qty($running)?></b> <?=e($item['unit_name']??'')?></div>
    </div>
    <div class="panel item-ledger-panel">
      <div class="panel-head"><h2>STOCK LEDGER</h2><div style="display:flex;gap:8px;align-items:center"><input class="input" id="ledgerSearch" placeholder="Search ledger" oninput="filterLedger(this.value)"><button class="btn" type="button" onclick="window.print()">Print</button></div></div>
      <div class="table-wrap"><table id="itemLedgerTable"><thead><tr><th>DATE</th><th>TYPE</th><th>NOTE</th><th>QUANTITY</th><th>PRICE/UNIT</th><th>RUNNING STOCK</th></tr></thead><tbody>
      <?php foreach($moves as $mv): ?>
        <tr data-ledger-search="<?=e(strtolower($mv['movement_date'].' '.$mv['movement_type'].' '.$mv['note'].' '.$mv['quantity']))?>">
          <td><?=e(!empty($mv['movement_date'])?date('d/m/Y',strtotime($mv['movement_date'])):'—')?></td>
          <td><?=e(ucwords(str_replace('_',' ',$mv['movement_type'])))?></td>
          <td><?=e($mv['note']??'')?></td>
          <td class="<?=((float)$mv['quantity']>=0?'ledger-plus':'ledger-minus')?>"><?=((float)$mv['quantity']>=0?'+':'')?><?=qty((float)$mv['quantity'])?> <?=e($item['unit_name']??'')?></td>
          <td><?=money((float)$mv['unit_price'])?></td>
          <td><b><?=qty((float)$mv['running_stock'])?> <?=e($item['unit_name']??'')?></b></td>
        </tr>
      <?php endforeach; if(!$moves): ?><tr><td colspan="6" class="subtle">No stock movements yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    </div>
    <script>
    function filterLedger(q){q=(q||'').toLowerCase().trim();document.querySelectorAll('[data-ledger-search]').forEach(function(r){r.style.display=(!q||r.dataset.ledgerSearch.indexOf(q)!==-1)?'':'none';});}
    </script>
    <?php page_end(); exit;
}

if($route==='items'){
    $u=require_login();
    $cid=(int)$u['company_id'];
    $pdo=db();
    $tab=$_GET['tab']??'products';
    if(!in_array($tab,['products','services','categories','units'],true))$tab='products';

    if($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['item_tx_action']??'', ['delete_stock','duplicate_stock'], true)){
        check_csrf();
        $stockId=(int)($_POST['stock_id']??0);
        $itemId=(int)($_POST['item_id']??0);
        try{
            $st=$pdo->prepare('SELECT sm.*,i.name item_name FROM stock_movements sm JOIN items i ON i.id=sm.item_id WHERE sm.id=? AND sm.item_id=? AND sm.company_id=? LIMIT 1');
            $st->execute([$stockId,$itemId,$cid]); $sm=$st->fetch();
            if(!$sm) throw new RuntimeException('Stock transaction not found.');
            $act=$_POST['item_tx_action'];
            if($act==='delete_stock'){
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM stock_movements WHERE id=? AND item_id=? AND company_id=?')->execute([$stockId,$itemId,$cid]);
                $pdo->commit(); audit('delete','stock_movement',$stockId,['item_id'=>$itemId,'movement_type'=>$sm['movement_type'],'quantity'=>$sm['quantity']]); flash('success','Stock transaction deleted.');
            }else{
                $qty=(float)$sm['quantity'];
                $pdo->beginTransaction();
                $copyQty=$qty;
                $note='Duplicate of STK-'.$stockId;
                $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?,?)')->execute([$cid,$itemId,$sm['transaction_id'],$sm['movement_date'],$copyQty,$sm['unit_price'],$sm['movement_type'],$note]);
                $newId=(int)$pdo->lastInsertId();
                $pdo->commit(); audit('duplicate','stock_movement',$newId,['source_stock_movement'=>$stockId,'item_id'=>$itemId]); flash('success','Stock transaction duplicated as STK-'.$newId.'.');
            }
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
        redirect('items?tab='.$tab.'&view='.$itemId);
    }
page_start('Items');
    $cid=(int)$u['company_id'];
    $pdo=db();
    $tab=$_GET['tab']??'products';
    if(!in_array($tab,['products','services','categories','units'],true))$tab='products';
    $editId=(int)($_GET['edit']??0);
    $selected=null;
    if(isset($_GET['view'])){
        $vid=(int)$_GET['view'];
        $st=$pdo->prepare('SELECT i.*,c.name category_name,u.name unit_name,u.symbol unit_symbol,COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm WHERE sm.company_id=i.company_id AND sm.item_id=i.id
                     AND (sm.transaction_id IS NULL OR EXISTS (
                         SELECT 1 FROM transactions st
                         WHERE st.id=sm.transaction_id
                           AND st.company_id=sm.company_id
                           AND st.deleted_at IS NULL
                     ))),0) current_stock FROM items i LEFT JOIN categories c ON c.id=i.category_id LEFT JOIN units u ON u.id=i.unit_id WHERE i.id=? AND i.company_id=? LIMIT 1');
        $st->execute([$vid,$cid]);$selected=$st->fetch()?:null;
    }
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $action=$_POST['action']??'';
        try{
            if($action==='save_item'){
                $type=($_POST['item_type']??'product')==='service'?'service':'product';
                $name=trim($_POST['name']??'');
                $code=trim($_POST['code']??'');
                // Barcode is managed separately from Add/Edit Item. Preserve an existing barcode on edit.
                $barcode='';
                if($editId){
                    $keep=$pdo->prepare('SELECT barcode FROM items WHERE id=? AND company_id=? LIMIT 1');
                    $keep->execute([$editId,$cid]);
                    $keepRow=$keep->fetch();
                    $barcode=(string)($keepRow['barcode']??'');
                }
                if($name==='')throw new RuntimeException('Item name is required.');
                $categoryId=(int)($_POST['category_id']??0)?:null;
                $unitId=(int)($_POST['unit_id']??0)?:null;
                $sale=(float)($_POST['sale_price']??0);$wh=(float)($_POST['wholesale_price']??0);$minWh=(float)($_POST['min_wholesale_qty']??0);$buy=(float)($_POST['purchase_price']??0);
                $opening=$type==='product'?(float)($_POST['opening_stock']??0):0;$low=$type==='product'?(float)($_POST['low_stock_limit']??0):0;
                if($editId){
                    $st=$pdo->prepare('SELECT * FROM items WHERE id=? AND company_id=? LIMIT 1');$st->execute([$editId,$cid]);$old=$st->fetch();if(!$old)throw new RuntimeException('Item not found.');
                    $pdo->prepare('UPDATE items SET item_type=?,name=?,code=?,barcode=?,serial_tracked=?,category_id=?,unit_id=?,sale_price=?,wholesale_price=?,min_wholesale_qty=?,purchase_price=?,low_stock_limit=?,description=? WHERE id=? AND company_id=?')->execute([$type,$name,$code?:null,$barcode?:null,($type==='product' && !empty($_POST['serial_tracked']))?1:0,$categoryId,$unitId,$sale,$wh,$minWh,$buy,$low,trim($_POST['description']??''),$editId,$cid]);
                    $id=$editId;
                    if($type==='product' && abs($opening-(float)$old['opening_stock'])>0.0001){
                        $delta=$opening-(float)$old['opening_stock'];
                        $pdo->prepare('UPDATE items SET opening_stock=? WHERE id=? AND company_id=?')->execute([$opening,$id,$cid]);
                        if(abs($delta)>0.0001){
                            $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?)')
                               ->execute([$cid,$id,date('Y-m-d'),$delta,$buy>0?$buy:(float)$old['purchase_price'],'opening_adjustment','Opening stock edited']);
                        }
                    }
                    audit('update','item',$id,['name'=>$name,'type'=>$type]);flash('success','Item updated successfully.');
                }else{
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('INSERT INTO items(company_id,item_type,name,code,barcode,serial_tracked,category_id,unit_id,sale_price,wholesale_price,min_wholesale_qty,purchase_price,opening_stock,low_stock_limit,description) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$type,$name,$code?:null,$barcode?:null,($type==='product' && !empty($_POST['serial_tracked']))?1:0,$categoryId,$unitId,$sale,$wh,$minWh,$buy,$opening,$low,trim($_POST['description']??'')]);
                        $id=(int)$pdo->lastInsertId();
                        if($type==='product' && abs($opening)>0.0001){
                            $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?)')
                                ->execute([$cid,$id,date('Y-m-d'),$opening,$buy,'opening_stock','Opening Stock']);
                        }
                        $pdo->commit();
                    } catch(Throwable $txe) {
                        if($pdo->inTransaction())$pdo->rollBack();
                        throw $txe;
                    }
                    audit('create','item',$id,['name'=>$name,'type'=>$type,'opening_stock'=>$opening]);flash('success','Item added successfully.');
                }
                redirect('items?tab='.($type==='service'?'services':'products'));
            }
            if($action==='save_category'){
                $name=trim($_POST['name']??'');$type=$_POST['type']==='service'?'service':'product';if($name==='')throw new RuntimeException('Category name is required.');
                $pdo->prepare('INSERT INTO categories(company_id,name,type) VALUES(?,?,?)')->execute([$cid,$name,$type]);flash('success','Category added.');redirect('items?tab=categories');
            }
            if($action==='save_unit'){
                $name=trim($_POST['name']??'');$symbol=trim($_POST['symbol']??'');if($name==='')throw new RuntimeException('Unit name is required.');
                $pdo->prepare('INSERT INTO units(company_id,name,symbol) VALUES(?,?,?)')->execute([$cid,$name,$symbol?:null]);flash('success','Unit added.');redirect('items?tab=units');
            }
            if($action==='edit_category'){
                $id=(int)($_POST['id']??0);
                $name=trim($_POST['name']??'');
                $type=$_POST['type']==='service'?'service':'product';
                if($id<=0||$name==='') throw new RuntimeException('Category name is required.');
                $st=$pdo->prepare('SELECT id FROM categories WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$id,$cid]); if(!$st->fetch()) throw new RuntimeException('Category not found.');
                $pdo->prepare('UPDATE categories SET name=?, type=? WHERE id=? AND company_id=?')->execute([$name,$type,$id,$cid]);
                audit('update','category',$id,['name'=>$name,'type'=>$type]);
                flash('success','Category updated.');
                redirect('items?tab=categories');
            }
            if($action==='delete_category'){
                $id=(int)($_POST['id']??0);
                $st=$pdo->prepare('SELECT id,name FROM categories WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$id,$cid]); $cat=$st->fetch();
                if(!$cat) throw new RuntimeException('Category not found.');
                $st=$pdo->prepare('SELECT COUNT(*) FROM items WHERE category_id=? AND company_id=? AND active=1');
                $st->execute([$id,$cid]); $count=(int)$st->fetchColumn();
                if($count>0) throw new RuntimeException('This category cannot be deleted because '.$count.' active item(s) use it. Reassign those items first.');
                $pdo->prepare('DELETE FROM categories WHERE id=? AND company_id=?')->execute([$id,$cid]);
                audit('delete','category',$id,['name'=>$cat['name']]);
                flash('success','Category deleted.');
                redirect('items?tab=categories');
            }
            if($action==='edit_unit'){
                $id=(int)($_POST['id']??0);
                $name=trim($_POST['name']??'');
                $symbol=trim($_POST['symbol']??'');
                if($id<=0||$name==='') throw new RuntimeException('Unit name is required.');
                $st=$pdo->prepare('SELECT id FROM units WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$id,$cid]); if(!$st->fetch()) throw new RuntimeException('Unit not found.');
                $pdo->prepare('UPDATE units SET name=?, symbol=? WHERE id=? AND company_id=?')->execute([$name,$symbol?:null,$id,$cid]);
                audit('update','unit',$id,['name'=>$name,'symbol'=>$symbol]);
                flash('success','Unit updated.');
                redirect('items?tab=units');
            }
            if($action==='delete_unit'){
                $id=(int)($_POST['id']??0);
                $st=$pdo->prepare('SELECT id,name FROM units WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$id,$cid]); $unit=$st->fetch();
                if(!$unit) throw new RuntimeException('Unit not found.');
                $st=$pdo->prepare('SELECT COUNT(*) FROM items WHERE unit_id=? AND company_id=? AND active=1');
                $st->execute([$id,$cid]); $count=(int)$st->fetchColumn();
                if($count>0) throw new RuntimeException('This unit cannot be deleted because '.$count.' active item(s) use it. Reassign those items first.');
                $pdo->prepare('DELETE FROM units WHERE id=? AND company_id=?')->execute([$id,$cid]);
                audit('delete','unit',$id,['name'=>$unit['name']]);
                flash('success','Unit deleted.');
                redirect('items?tab=units');
            }
            if($action==='adjust_stock'){
                $iid=(int)($_POST['item_id']??0);$qty=(float)($_POST['quantity']??0);$mode=$_POST['mode']==='reduce'?'reduce':'add';$note=trim($_POST['note']??'');$adjustmentDate=$_POST['adjustment_date']??date('Y-m-d');$atPrice=(float)($_POST['at_price']??0);
                if($iid<=0||$qty<=0)throw new RuntimeException('Enter a quantity greater than zero.');
                if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$adjustmentDate))$adjustmentDate=date('Y-m-d');
                $st=$pdo->prepare('SELECT id,item_type,name,purchase_price FROM items WHERE id=? AND company_id=? AND active=1');$st->execute([$iid,$cid]);$it=$st->fetch();if(!$it||$it['item_type']!=='product')throw new RuntimeException('Only product stock can be adjusted.');
                $movementQty=$mode==='reduce' ? -$qty : $qty;
                $movementType=$mode==='reduce' ? 'manual_reduce' : 'manual_add';
                $detail=$note?:($mode==='reduce'?'Manual stock reduction':'Manual stock addition');
                $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$iid,$adjustmentDate,$movementQty,$atPrice>0?$atPrice:(float)$it['purchase_price'],$movementType,$detail]);
                audit('adjust','item',$iid,['mode'=>$mode,'quantity'=>$qty,'signed_quantity'=>$movementQty,'at_price'=>$atPrice,'adjustment_date'=>$adjustmentDate,'note'=>$note]);flash('success',$mode==='reduce'?'Stock reduced successfully.':'Stock added successfully.');redirect('items?tab='.$tab.'&view='.$iid);
            }
            if($action==='generate_barcode'){
                $iid=(int)($_POST['item_id']??0);
                if($iid<=0) throw new RuntimeException('Item not found.');
                $st=$pdo->prepare('SELECT id,name,barcode FROM items WHERE id=? AND company_id=? AND active=1 LIMIT 1');
                $st->execute([$iid,$cid]); $it=$st->fetch();
                if(!$it) throw new RuntimeException('Item not found.');
                if(trim((string)($it['barcode']??''))!==''){
                    flash('success','This item already has a barcode.');
                    redirect('items?tab='.($it['item_type']==='service'?'services':'products').'&view='.$iid);
                }
                do {
                    $barcode='20'.str_pad((string)$cid,4,'0',STR_PAD_LEFT).date('ymdHis').random_int(0,9);
                    $chk=$pdo->prepare('SELECT COUNT(*) FROM items WHERE barcode=? AND company_id=?');
                    $chk->execute([$barcode,$cid]);
                } while((int)$chk->fetchColumn()>0);
                $pdo->prepare('UPDATE items SET barcode=? WHERE id=? AND company_id=?')->execute([$barcode,$iid,$cid]);
                audit('generate_barcode','item',$iid,['barcode'=>$barcode]);
                flash('success','Barcode assigned: '.$barcode);
                redirect('items?tab='.($it['item_type']==='service'?'services':'products').'&view='.$iid);
            }
            if($action==='delete_item'){
                $iid=(int)($_POST['item_id']??0);
                $st=$pdo->prepare('SELECT id,name,item_type,active FROM items WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$iid,$cid]);$item=$st->fetch();
                if(!$item) throw new RuntimeException('Item not found.');
                $txc=$pdo->prepare('SELECT COUNT(*) FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id WHERE ti.item_id=? AND t.company_id=?');
                $txc->execute([$iid,$cid]);$transactionCount=(int)$txc->fetchColumn();
                if($transactionCount>0) throw new RuntimeException('This item cannot be deleted because it has '.$transactionCount.' transaction(s). Delete all transactions linked to this item first.');
                $pdo->prepare('UPDATE items SET active=0 WHERE id=? AND company_id=?')->execute([$iid,$cid]);audit('delete','item',$iid);flash('success','Item moved to Recycle Bin.');redirect('items');
            }
        }catch(Throwable $e){
            flash('error',$e->getCode()==='23000'?'This name already exists.':$e->getMessage());
            redirect('items'.($editId?'?edit='.$editId:''));
        }
    }
    $items=get_items($cid);
    if (!$selected && in_array($tab,['products','services'],true)) {
        foreach ($items as $r0) {
            if (($tab==='products' && $r0['item_type']==='product') || ($tab==='services' && $r0['item_type']==='service')) {
                $selected=$r0;
                break;
            }
        }
    }
    $cats=$pdo->prepare('SELECT id,name,type FROM categories WHERE company_id=? ORDER BY type,name');$cats->execute([$cid]);$catRows=$cats->fetchAll();
    $units=$pdo->prepare('SELECT id,name,symbol FROM units WHERE company_id=? ORDER BY name');$units->execute([$cid]);$unitRows=$units->fetchAll();
    $edit=null;
    if($editId){$st=$pdo->prepare('SELECT * FROM items WHERE id=? AND company_id=? AND active=1');$st->execute([$editId,$cid]);$edit=$st->fetch()?:null;}
    if($tab==='categories'){
        ?><div class="page-title"><div><h1>Categories</h1><p>Product and service categories</p></div><button class="btn primary" onclick="openModal('categoryModal')">⊕ Add Category</button></div>
        <div class="panel"><div class="table-wrap"><table><thead><tr><th>NAME</th><th>TYPE</th><th>ACTION</th></tr></thead><tbody><?php foreach($catRows as $c):?><tr><td><?=e($c['name'])?></td><td><?=e(ucfirst($c['type']))?></td><td><button type="button" class="btn small" onclick="openCategoryEdit(<?= (int)$c['id']?>,<?=json_encode($c['name'])?>,<?=json_encode($c['type'])?>)">Edit</button> <form method="post" style="display:inline" onsubmit="return confirm('Delete this category?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="btn small danger" type="submit">Delete</button></form></td></tr><?php endforeach;if(!$catRows):?><tr><td colspan="3" class="subtle">No categories yet.</td></tr><?php endif;?></tbody></table></div></div>
        <div class="modal-backdrop" id="categoryModal" onclick="if(event.target===this)closeModal('categoryModal')"><div class="modal"><div class="modal-head"><h2>Add Category</h2><button class="close" onclick="closeModal('categoryModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_category"><div class="grid2"><div class="form-group"><label>Category Name*</label><input name="name" required></div><div class="form-group"><label>Type</label><select name="type"><option value="product">Product</option><option value="service">Service</option></select></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('categoryModal')">Cancel</button><button class="btn primary">Save</button></div></form>
        <div class="modal-backdrop" id="categoryEditModal" onclick="if(event.target===this)closeModal('categoryEditModal')"><div class="modal"><div class="modal-head"><h2>Edit Category</h2><button class="close" onclick="closeModal('categoryEditModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="edit_category"><input type="hidden" name="id" id="categoryEditId"><div class="grid2"><div class="form-group"><label>Category Name*</label><input name="name" id="categoryEditName" required></div><div class="form-group"><label>Type</label><select name="type" id="categoryEditType"><option value="product">Product</option><option value="service">Service</option></select></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('categoryEditModal')">Cancel</button><button class="btn primary">Update</button></div></form></div></div>
        <script>function openCategoryEdit(id,name,type){document.getElementById('categoryEditId').value=id;document.getElementById('categoryEditName').value=name;document.getElementById('categoryEditType').value=type;openModal('categoryEditModal')}</script></div></div><?php page_end();exit;
    }
    if($tab==='units'){
        ?><div class="page-title"><div><h1>Units</h1><p>Measurement units for items</p></div><button class="btn primary" onclick="openModal('unitModal')">⊕ Add Unit</button></div>
        <div class="panel"><div class="table-wrap"><table><thead><tr><th>NAME</th><th>SYMBOL</th><th>ACTION</th></tr></thead><tbody><?php foreach($unitRows as $x):?><tr><td><?=e($x['name'])?></td><td><?=e($x['symbol']??'')?></td><td><button type="button" class="btn small" onclick="openUnitEdit(<?= (int)$x['id']?>,<?=json_encode($x['name'])?>,<?=json_encode($x['symbol']??'')?>)">Edit</button> <form method="post" style="display:inline" onsubmit="return confirm('Delete this unit?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_unit"><input type="hidden" name="id" value="<?=$x['id']?>"><button class="btn small danger" type="submit">Delete</button></form></td></tr><?php endforeach;if(!$unitRows):?><tr><td colspan="3" class="subtle">No units yet.</td></tr><?php endif;?></tbody></table></div></div>
        <div class="modal-backdrop" id="unitModal" onclick="if(event.target===this)closeModal('unitModal')"><div class="modal"><div class="modal-head"><h2>Add Unit</h2><button class="close" onclick="closeModal('unitModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_unit"><div class="grid2"><div class="form-group"><label>Unit Name*</label><input name="name" required></div><div class="form-group"><label>Symbol</label><input name="symbol" placeholder="pcs"></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('unitModal')">Cancel</button><button class="btn primary">Save</button></div></form>
        <div class="modal-backdrop" id="unitEditModal" onclick="if(event.target===this)closeModal('unitEditModal')"><div class="modal"><div class="modal-head"><h2>Edit Unit</h2><button class="close" onclick="closeModal('unitEditModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="edit_unit"><input type="hidden" name="id" id="unitEditId"><div class="grid2"><div class="form-group"><label>Unit Name*</label><input name="name" id="unitEditName" required></div><div class="form-group"><label>Symbol</label><input name="symbol" id="unitEditSymbol"></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('unitEditModal')">Cancel</button><button class="btn primary">Update</button></div></form></div></div>
        <script>function openUnitEdit(id,name,symbol){document.getElementById('unitEditId').value=id;document.getElementById('unitEditName').value=name;document.getElementById('unitEditSymbol').value=symbol;openModal('unitEditModal')}</script></div></div><?php page_end();exit;
    }
    ?><div class="items-shell">
      <div class="items-tabsbar">
        <a class="<?= $tab==='products'?'active':'' ?>" href="<?=e(url('items?tab=products'))?>">PRODUCTS</a>
        <a class="<?= $tab==='services'?'active':'' ?>" href="<?=e(url('items?tab=services'))?>">SERVICES</a>
        <a class="<?= $tab==='categories'?'active':'' ?>" href="<?=e(url('items?tab=categories'))?>">CATEGORY</a>
        <a class="<?= $tab==='units'?'active':'' ?>" href="<?=e(url('items?tab=units'))?>">UNITS</a>
      </div>
      <div class="items-workspace">
        <aside class="item-master panel">
          <div class="item-master-toolbar">
            <button type="button" class="icon-circle" aria-label="Search">⌕</button>
            <div class="add-item-split"><button type="button" class="btn item-add-btn" onclick="openModal('itemModal')">＋ Add Item</button><button type="button" class="btn item-add-caret" onclick="openModal('itemModal')">⌄</button></div>
            <button type="button" class="icon-more" aria-label="More">⋮</button>
          </div>
          <div class="item-master-head"><span>ITEM</span><span>QUANTITY</span></div>
          <div class="item-search-wrap"><input id="itemSearch" placeholder="Search items" oninput="filterItems()"></div>
          <div id="itemListBody" class="item-master-list">
            <?php $visibleCount=0; foreach($items as $r): if(($tab==='products'&&$r['item_type']!=='product')||($tab==='services'&&$r['item_type']!=='service')||$r['active']!=1)continue; $visibleCount++; ?>
              <div class="item-master-row <?=($selected&&$selected['id']==$r['id'])?'selected':''?>" data-name="<?=e(strtolower($r['name'].' '.$r['code'].' '.$r['barcode']))?>">
                <?php $itemTxCountSt=$pdo->prepare('SELECT COUNT(*) FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id WHERE ti.item_id=? AND t.company_id=?');$itemTxCountSt->execute([(int)$r['id'],$cid]);$itemTxCount=(int)$itemTxCountSt->fetchColumn(); ?>
                <a class="item-master-main" href="<?=e(url('items?tab='.$tab.'&view='.$r['id']))?>">
                  <span class="item-master-name"><?=e($r['name'])?></span>
                  <?php if($r['code']||$r['barcode']): ?><span class="item-master-meta"><?=e($r['code']?:$r['barcode'])?></span><?php endif; ?>
                </a>
                <span class="item-master-qty <?=((float)$r['current_stock']<0)?'neg':'positive'?> <?=((float)$r['current_stock']>0)?'pos':''?>"><?= $r['item_type']==='service' ? '—' : qty((float)$r['current_stock']) ?></span>
                <div class="item-master-actions">
                  <button type="button" class="item-menu-trigger-v52" aria-label="Item actions" aria-expanded="false" onclick="SutoItemMenu.open(this,event)">⋮</button>
                  <div class="item-menu-panel-v52" hidden>
                    <a href="<?=e(url('items?tab='.$tab.'&edit='.$r['id']))?>">View/Edit</a>
                    <?php if($itemTxCount>0): ?>
                      <div class="item-delete-blocked" title="Delete all linked transactions first">Delete unavailable · <?=$itemTxCount?> transaction<?=($itemTxCount===1?'':'s')?> </div>
                    <?php else: ?>
                      <form method="post" onsubmit="return confirm('Move this item to Recycle Bin?')">
                        <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
                        <input type="hidden" name="action" value="delete_item">
                        <input type="hidden" name="item_id" value="<?=$r['id']?>">
                        <button type="submit">Delete</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; if(!$visibleCount):?><div class="item-empty">No <?= $tab==='services'?'services':'products' ?> yet.</div><?php endif; ?>
          </div>
        </aside>
        <section class="item-detail-area">
        <?php if($selected):
          $tx=$pdo->prepare('(SELECT t.id source_transaction_id,NULL stock_movement_id,t.txn_date,t.txn_type,t.document_no,ti.qty,ti.unit_price,t.status,p.name party_name FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id LEFT JOIN parties p ON p.id=t.party_id WHERE ti.item_id=? AND t.company_id=? AND t.deleted_at IS NULL) UNION ALL (SELECT NULL source_transaction_id,sm.id stock_movement_id,sm.movement_date txn_date,sm.movement_type txn_type,CONCAT("STK-",sm.id) document_no,sm.quantity qty,COALESCE(sm.unit_price,i.purchase_price) unit_price,"Final" status,COALESCE(NULLIF(sm.note,""),CASE sm.movement_type WHEN "manual_add" THEN "Stock Adjustment (Add)" WHEN "manual_reduce" THEN "Stock Adjustment (Reduce)" WHEN "opening_adjustment" THEN "Opening Stock Adjustment" ELSE REPLACE(sm.movement_type,"_"," ") END) party_name FROM stock_movements sm JOIN items i ON i.id=sm.item_id WHERE sm.item_id=? AND sm.company_id=? AND sm.movement_type NOT IN ("sale","purchase","sale_return","purchase_return")) ORDER BY txn_date DESC LIMIT 100');$tx->execute([$selected['id'],$cid,$selected['id'],$cid]);$txRows=$tx->fetchAll();
        ?><div class="item-detail-card panel">
            <div class="item-detail-top">
              <div><h2><?=e($selected['name'])?> <span class="item-share">↗</span></h2><div class="item-subline"><?=e($selected['code']?:($selected['barcode']?:''))?></div></div>
              <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <?php if(empty($selected['barcode'])): ?><?php else: ?><span class="subtle">Barcode: <b><?=e($selected['barcode'])?></b></span><?php endif; ?>
              <a class="btn" href="<?=e(url('item-ledger?item='.(int)$selected['id']))?>">ITEM STOCK LEDGER</a>
              <button type="button" class="btn primary adjust-btn" onclick="openModal('adjustModal')">☷ ADJUST ITEM</button>
            </div>
            </div>
            <div class="item-price-grid">
              <div><span>SALE PRICE:</span> <b><?=money((float)$selected['sale_price'])?></b></div>
              <div class="stock-right"><span>STOCK QUANTITY:</span> <b class="<?=((float)$selected['current_stock']<0)?'negative-value':'positive-value'?>"><?= $selected['item_type']==='service' ? '—' : qty((float)$selected['current_stock']) ?></b></div>
              <div><span>PURCHASE PRICE:</span> <b><?=money((float)$selected['purchase_price'])?></b></div>
              <div class="stock-right"><span>STOCK VALUE:</span> <b><?=money($selected['item_type']==='service'?0:(float)$selected['purchase_price']*(float)$selected['current_stock'])?></b></div>
            </div>
          </div>
          <div class="item-transactions panel">
            <div class="panel-head"><h2>TRANSACTIONS</h2><div class="tx-tools"><input class="input" id="itemTxSearch" placeholder="⌕ Search"><span class="export-icon">▣</span></div></div>
            <div class="table-wrap"><table><thead><tr><th></th><th>TYPE</th><th>NO</th><th>NAME</th><th>DATE</th><th>QUANTITY</th><th>PRICE/UNIT</th><th>STATUS</th><th></th></tr></thead><tbody><?php foreach($txRows as $r): $tt=$r['txn_type']; $typeLabel=['sale'=>'Sale','purchase'=>'Purchase','payment_in'=>'Payment In','payment_out'=>'Payment Out','manual_add'=>'Stock Adjustment','manual_reduce'=>'Stock Adjustment','opening_adjustment'=>'Opening Stock Adjustment','opening_stock'=>'Opening Stock'][$tt]??ucwords(str_replace('_',' ',$tt)); $doc=(string)($r['document_no']??''); $docLink=''; if($tt==='sale' && !empty($r['source_transaction_id'])) $docLink=url('sales?view='.(int)$r['source_transaction_id']); elseif($tt==='purchase' && !empty($r['source_transaction_id'])) $docLink=url('purchase?view='.(int)$r['source_transaction_id']); $displayNo=$doc!==''?$doc:'—'; $displayName=(string)($r['party_name']??''); if($displayName==='') $displayName=$typeLabel; ?><tr><td><span class="tx-dot"></span></td><td><?=e($typeLabel)?></td><td><?php if($docLink):?><a class="tx-doc-link" href="<?=e($docLink)?>"><?=e($displayNo)?></a><?php else:?><?=e($displayNo)?><?php endif;?></td><td><?=e($displayName)?></td><td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td><td><?=qty((float)$r['qty'])?> <?=e($selected['unit_symbol']??'')?></td><td><?=money((float)$r['unit_price'])?></td><td><span class="tx-status"><?=e(ucfirst($r['status']))?></span></td><td class="tx-more" style="width:52px;min-width:52px;text-align:center;position:relative;overflow:visible!important;">
<?php $isDocTx=in_array($tt,['sale','purchase'],true)&&!empty($r['source_transaction_id']); $sourceRoute=$tt==='sale'?'sales':'purchase'; ?>
<button type="button" aria-label="Transaction actions" aria-expanded="false" onclick="return SutoTxV72.toggle(this,event)"
style="width:32px;height:32px;padding:0;border:1px solid #cfd8e3;background:#fff!important;color:#334155;border-radius:7px;cursor:pointer;font-size:18px;line-height:30px;display:inline-flex;align-items:center;justify-content:center;box-shadow:0 1px 3px rgba(0,0,0,.08);">⋮</button>
<div class="tx-action-menu-v72" hidden style="position:fixed;display:none;min-width:165px;padding:6px;background:#fff!important;color:#1f2937!important;border:1px solid #dbe3ee;border-radius:9px;box-shadow:0 14px 36px rgba(15,23,42,.18);z-index:2147483647;">
<?php if($isDocTx): ?>
<a href="<?=e(url($sourceRoute.'?view='.(int)$r['source_transaction_id']))?>" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border-radius:6px;background:#fff!important;color:#1f2937!important;text-decoration:none;">View/Edit</a>
<form method="post" action="<?=e(url($sourceRoute))?>" style="margin:0" onsubmit="return confirm('Delete this transaction? It will move to Recycle Bin.');"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="transaction_id" value="<?=$r['source_transaction_id']?>"><button type="submit" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border:0;border-radius:6px;background:#fff!important;color:#1f2937!important;text-align:left;cursor:pointer;">Delete</button></form>
<form method="post" action="<?=e(url($sourceRoute))?>" style="margin:0"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="transaction_id" value="<?=$r['source_transaction_id']?>"><button type="submit" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border:0;border-radius:6px;background:#fff!important;color:#1f2937!important;text-align:left;cursor:pointer;">Duplicate</button></form>
<?php else: ?>
<a href="<?=e(url('items?tab='.$tab.'&view='.$selected['id'].'&stock_view='.(int)($r['stock_movement_id']??0)))?>" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border-radius:6px;background:#fff!important;color:#1f2937!important;text-decoration:none;">View/Edit</a>
<form method="post" action="<?=e(url('items?tab='.$tab.'&view='.$selected['id']))?>" style="margin:0" onsubmit="return confirm('Delete this stock transaction?');"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="item_tx_action" value="delete_stock"><input type="hidden" name="item_id" value="<?=$selected['id']?>"><input type="hidden" name="stock_id" value="<?=$r['stock_movement_id']?>"><button type="submit" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border:0;border-radius:6px;background:#fff!important;color:#1f2937!important;text-align:left;cursor:pointer;">Delete</button></form>
<form method="post" action="<?=e(url('items?tab='.$tab.'&view='.$selected['id']))?>" style="margin:0"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="item_tx_action" value="duplicate_stock"><input type="hidden" name="item_id" value="<?=$selected['id']?>"><input type="hidden" name="stock_id" value="<?=$r['stock_movement_id']?>"><button type="submit" style="display:block;width:100%;box-sizing:border-box;padding:9px 11px;border:0;border-radius:6px;background:#fff!important;color:#1f2937!important;text-align:left;cursor:pointer;">Duplicate</button></form>
<?php endif; ?>
</div>
</td></tr><?php endforeach;if(!$txRows):?><tr><td colspan="9" class="subtle">No transactions for this item yet.</td></tr><?php endif;?></tbody></table></div>
          </div>
          <div class="modal-backdrop" id="adjustModal" onclick="if(event.target===this)closeModal('adjustModal')"><div class="stock-adjust-dialog"><div class="stock-adjust-head"><h2>Stock Adjustment</h2><button class="close" type="button" onclick="closeModal('adjustModal')">×</button></div><form method="post" id="stockAdjustForm"><div class="stock-adjust-body"><div class="stock-adjust-top"><div><div class="subtle stock-item-label">Item Name</div><strong><?=e($selected['name'])?></strong></div><div class="form-group adjustment-date-wrap"><label>Adjustment Date</label><input type="date" name="adjustment_date" value="<?=date('Y-m-d')?>"></div></div><div class="stock-mode-switch" role="group" aria-label="Stock adjustment mode"><button type="button" class="stock-mode active" data-mode="add" onclick="setStockMode('add')">Add Stock</button><span class="switch-knob" aria-hidden="true"></span><button type="button" class="stock-mode" data-mode="reduce" onclick="setStockMode('reduce')">Reduce Stock</button></div><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="adjust_stock"><input type="hidden" name="item_id" value="<?=$selected['id']?>"><input type="hidden" name="mode" id="stockMode" value="add"><div class="stock-adjust-grid"><div class="form-group"><label>Total Qty</label><input class="stock-qty-input" type="number" step="0.01" min="0.01" name="quantity" required placeholder="Enter quantity"></div><div class="form-group"><label>Unit</label><input value="<?=e($selected['unit_symbol']??'')?>" disabled></div><div class="form-group"><label>At Price</label><input type="number" step="0.01" min="0" name="at_price" value="<?=e($selected['purchase_price'])?>" placeholder="0.00"></div><div class="form-group details-field"><label>Details</label><input name="note" placeholder="Details"></div></div><div class="current-stock-strip"><span>Current Stock</span><strong><?=qty((float)$selected['current_stock'])?> <?=e($selected['unit_symbol']??'')?></strong></div></div><div class="stock-adjust-footer"><button type="button" class="btn" onclick="closeModal('adjustModal')">Cancel</button><button type="submit" class="btn primary">Save</button></div></form></div></div><?php
        else:
        ?><div class="panel empty-detail"><div class="empty-icon">▣</div><h2>Select an item</h2><p class="subtle">Choose a product or service from the list to see pricing, stock and transaction history.</p></div><?php endif; ?></section>
      </div>
    </div>
    <div class="modal-backdrop" id="itemModal" onclick="if(event.target===this)closeModal('itemModal')"><div class="modal"><div class="modal-head"><h2><?= $edit?'Edit Item':'Add Item' ?></h2><button class="close" onclick="closeModal('itemModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_item"><div class="item-form-top"><div class="form-group"><label>Item Name*</label><input name="name" required value="<?=e($edit['name']??'')?>"></div><div class="form-group"><label>Category</label><select name="category_id"><option value="">Select Category</option><?php foreach($catRows as $c):?><option value="<?=$c['id']?>" <?=($edit&&$edit['category_id']==$c['id'])?'selected':''?>><?=e($c['name'])?> (<?=e($c['type'])?>)</option><?php endforeach;?></select></div><div class="form-group"><label>Select Unit</label><select name="unit_id"><option value="">Select Unit</option><?php foreach($unitRows as $x):?><option value="<?=$x['id']?>" <?=($edit&&$edit['unit_id']==$x['id'])?'selected':''?>><?=e($x['name'].' '.($x['symbol']?'('.$x['symbol'].')':''))?></option><?php endforeach;?></select></div></div><div class="item-type-toggle"><label><input type="radio" name="item_type" value="product" <?=(!$edit||$edit['item_type']==='product')?'checked':''?> onchange="toggleStock()"> Product</label><label><input type="radio" name="item_type" value="service" <?=($edit&&$edit['item_type']==='service')?'checked':''?> onchange="toggleStock()"> Service</label></div><div class="serial-tracking-toggle"><label><input type="checkbox" name="serial_tracked" value="1" <?=($edit&&((int)($edit['serial_tracked']??0)===1))?'checked':''?>> Enable Serial Number Tracking</label><span class="subtle"> Purchase each unit with a unique serial; sale can auto-pick or use specific serials.</span></div><div class="grid2"><div class="form-group span2"><label>Item Code</label><input name="code" value="<?=e($edit['code']??'')?>"></div></div><div class="tabs"><button type="button" class="active">PRICING</button><button type="button">STOCK</button></div><div class="pricing-section"><div class="grid3"><div class="form-group"><label>Sale Price</label><input type="number" step="0.01" name="sale_price" value="<?=e($edit['sale_price']??'0')?>"></div><div class="form-group"><label>Wholesale Price</label><input type="number" step="0.01" name="wholesale_price" value="<?=e($edit['wholesale_price']??'0')?>"></div><div class="form-group"><label>Minimum Wholesale Qty</label><input type="number" step="0.01" name="min_wholesale_qty" value="<?=e($edit['min_wholesale_qty']??'0')?>"></div><div class="form-group"><label>Purchase Price</label><input type="number" step="0.01" name="purchase_price" value="<?=e($edit['purchase_price']??'0')?>"></div></div></div><div class="stock-section"><div class="grid2"><div class="form-group stock-field"><label>Opening Stock</label><input type="number" step="0.01" name="opening_stock" value="<?=e($edit['opening_stock']??'0')?>"></div><div class="form-group stock-field"><label>Low Stock Limit</label><input type="number" step="0.01" name="low_stock_limit" value="<?=e($edit['low_stock_limit']??'0')?>"></div><div class="form-group span2"><label>Description</label><textarea name="description"><?=e($edit['description']??'')?></textarea></div></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('itemModal')">Cancel</button><button class="btn primary"><?= $edit?'Update':'Save' ?></button></div></form></div></div>
    <script>
    function setStockMode(mode){
      var hidden=document.getElementById('stockMode');
      if(hidden) hidden.value=(mode==='reduce'?'reduce':'add');
      document.querySelectorAll('.stock-mode').forEach(function(btn){btn.classList.toggle('active',btn.getAttribute('data-mode')===(mode==='reduce'?'reduce':'add'));});
      var dlg=document.querySelector('#adjustModal .stock-adjust-dialog'); if(dlg) dlg.classList.toggle('reduce-mode',mode==='reduce');
    }
        
window.SutoTxMenu=(function(){
  var active=null;
  function restore(){
    if(!active)return;
    var p=active.p, parent=active.parent, next=active.next, b=active.b;
    p.hidden=true; p.removeAttribute('style');
    if(parent){
      if(next && next.parentNode===parent) parent.insertBefore(p,next); else parent.appendChild(p);
    }
    b.setAttribute('aria-expanded','false');
    active=null;
  }
  function place(b,p){
    var r=b.getBoundingClientRect();
    var w=Math.max(154, Math.min(220, p.scrollWidth||180));
    var h=p.offsetHeight||90;
    var left=r.right+8, top=r.top;
    if(left+w>window.innerWidth-8) left=Math.max(8,r.left-w-8);
    if(top+h>window.innerHeight-8) top=Math.max(8,window.innerHeight-h-8);
    p.style.position='fixed';
    p.style.left=Math.round(left)+'px';
    p.style.top=Math.round(top)+'px';
    p.style.width=w+'px';
    p.style.zIndex='2147483647';
    p.style.display='block';
  }
  function open(b,e){
    if(e){e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();}
    var wrap=b.closest('.tx-more');
    var p=wrap&&wrap.querySelector('.tx-action-menu-v66');
    if(!p)return false;
    if(active && active.b===b){restore();return false;}
    restore();
    var parent=p.parentNode, next=p.nextSibling;
    document.body.appendChild(p);
    p.hidden=false;
    b.setAttribute('aria-expanded','true');
    active={b:b,p:p,parent:parent,next:next};
    place(b,p);
    requestAnimationFrame(function(){if(active&&active.p===p)place(b,p);});
    return false;
  }
  document.addEventListener('click',function(e){
    var b=e.target.closest('.tx-action-trigger-v66');
    if(b){open(b,e);return;}
    if(e.target.closest('.tx-action-menu-v66')) return;
    restore();
  },true);
  document.addEventListener('keydown',function(e){if(e.key==='Escape')restore();});
  window.addEventListener('resize',restore);
  window.addEventListener('scroll',function(){if(active)place(active.b,active.p);},true);
  return {open:open,close:restore};
})();

window.SutoItemMenu=(function(){
      var active=null, originalParent=new WeakMap(), originalNext=new WeakMap();
      function close(){
        if(!active)return; var b=active.b,p=active.p,parent=originalParent.get(p),next=originalNext.get(p);
        if(p){p.hidden=true;p.removeAttribute('style'); if(parent){ if(next&&next.parentNode===parent) parent.insertBefore(p,next); else parent.appendChild(p); }}
        if(b)b.setAttribute('aria-expanded','false'); active=null;
      }
      function place(b,p){
        var r=b.getBoundingClientRect(), w=150, h=p.offsetHeight||82, gap=8;
        var left=r.right+gap;
        var top=r.top;
        if(left+w>window.innerWidth-8) left=Math.max(8,r.left-w-gap);
        if(top+h>window.innerHeight-8) top=Math.max(8,window.innerHeight-h-8);
        p.style.position='fixed';p.style.left=Math.round(left)+'px';p.style.top=Math.round(top)+'px';p.style.width=w+'px';p.style.zIndex='2147483647';p.style.display='block';
      }
      function open(b,e){
        if(e){e.preventDefault();e.stopPropagation();e.stopImmediatePropagation();}
        if(active&&active.b===b){close();return false;}
        close(); var wrap=b.closest('.item-master-actions'); var p=wrap&&wrap.querySelector('.item-menu-panel-v52'); if(!p)return false;
        originalParent.set(p,p.parentNode); originalNext.set(p,p.nextSibling); document.body.appendChild(p); p.hidden=false; b.setAttribute('aria-expanded','true'); active={b:b,p:p};
        place(b,p); requestAnimationFrame(function(){if(active&&active.p===p)place(b,p);}); return false;
      }
      document.addEventListener('click',function(e){
        if(e.target.closest('.item-menu-panel-v52')) return;
        if(!e.target.closest('.item-menu-trigger-v52')) close();
      },true);
      window.addEventListener('resize',function(){if(active)place(active.b,active.p);});
      window.addEventListener('scroll',function(){if(active)place(active.b,active.p);},true);
      return {open:open,close:close};
    })();
    </script>
    <script>
window.SutoTxV72=(function(){
  let active=null;
  function close(){
    if(!active)return;
    active.panel.hidden=true;
    active.panel.style.display='none';
    active.button.setAttribute('aria-expanded','false');
    active=null;
  }
  function position(){
    if(!active)return;
    const r=active.button.getBoundingClientRect(), p=active.panel, w=165, gap=8;
    let left=r.right+gap, top=r.top;
    if(left+w>window.innerWidth-8) left=Math.max(8,r.left-w-gap);
    const h=p.offsetHeight||110;
    if(top+h>window.innerHeight-8) top=Math.max(8,window.innerHeight-h-8);
    p.style.left=Math.round(left)+'px';
    p.style.top=Math.round(top)+'px';
    p.style.width=w+'px';
    p.style.display='block';
  }
  function toggle(button,e){
    if(e){e.preventDefault();e.stopPropagation();}
    if(active&&active.button===button){close();return false;}
    close();
    const panel=button.parentElement.querySelector('.tx-action-menu-v72');
    if(!panel)return false;
    panel.hidden=false;
    document.body.appendChild(panel);
    active={button:button,panel:panel};
    button.setAttribute('aria-expanded','true');
    position();
    requestAnimationFrame(position);
    return false;
  }
  document.addEventListener('click',function(e){
    if(e.target.closest('.tx-action-menu-v72')||e.target.closest('[aria-label="Transaction actions"]')) return;
    close();
  },true);
  document.addEventListener('keydown',function(e){if(e.key==='Escape')close();});
  window.addEventListener('resize',position);
  window.addEventListener('scroll',position,true);
  return {toggle:toggle,close:close};
})();
</script>
<?php if($edit):?><script>document.addEventListener('DOMContentLoaded',()=>openModal('itemModal'))</script><?php endif; page_end();exit;
}

if(in_array($route,['sale-new','purchase-new'],true)){
    $u=require_login();
    page_start($route==='sale-new'?'Sale':'Purchase');
    $isSale=$route==='sale-new'; $cid=(int)$u['company_id']; $pdo=db();
    $type=$isSale?'sale':'purchase'; $prefix=$isSale?'SI-':'PB-';
    $editId=(int)($_GET['edit']??0);
    $editTx=null; $editLines=[]; $editPayments=[]; $serialMap=[];
    if($editId>0){
        $st=db()->prepare('SELECT t.*,p.name party_name,p.phone party_phone FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL LIMIT 1');
        $st->execute([$editId,$cid,$type]); $editTx=$st->fetch();
        if(!$editTx){http_response_code(404);echo '<div class="panel"><h1>'.e($isSale?'Sale transaction not found':'Purchase transaction not found').'</h1></div>';page_end();exit;}
        $st=db()->prepare('SELECT ti.*,i.item_type,i.serial_tracked,u.symbol unit_symbol FROM transaction_items ti JOIN items i ON i.id=ti.item_id LEFT JOIN units u ON u.id=i.unit_id WHERE ti.transaction_id=? ORDER BY ti.id');
        $st->execute([$editId]); $editLines=$st->fetchAll();
        $serialMap=[]; $ss=$pdo->prepare('SELECT tis.transaction_item_id,isx.serial_number FROM transaction_item_serials tis JOIN item_serials isx ON isx.id=tis.serial_id WHERE tis.transaction_id=? AND tis.company_id=? ORDER BY tis.id');$ss->execute([$editId,$cid]);foreach($ss->fetchAll() as $sr){$serialMap[(int)$sr['transaction_item_id']][]=(string)$sr['serial_number'];}
        $st=db()->prepare('SELECT * FROM payment_lines WHERE transaction_id=? ORDER BY id');
        $st->execute([$editId]); $editPayments=$st->fetchAll();
    }
    $parties=db()->prepare('SELECT id,name,phone,party_type FROM parties WHERE company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=parties.id AND pr.role IN (?,?,?)) ORDER BY name');
    $parties->execute([$cid,'customer','both','supplier']); $partyRows=$parties->fetchAll();
    $items=get_items($cid);
    $banks=db()->prepare('SELECT id,name,bank_name,account_number FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY name'); $banks->execute([$cid]); $bankRows=$banks->fetchAll();
    $pageHeading=$editTx?($isSale?'Edit Sale Invoice':'Edit Purchase Bill'):($isSale?'Sale':'Purchase');
    $pageSub=$editTx?('Edit '.($isSale?'Sales Invoice':'Purchase Bill').' '.$editTx['document_no']):($isSale?'Create Sales Invoice':'Create Purchase Bill');
    $rows=$editLines ?: [null];
    $payRows=$editPayments ?: [null];
?>
<div class="page-title"><div><h1><?=e($pageHeading)?></h1><p><?=e($pageSub)?></p></div><a class="btn" href="<?=e(url($isSale?'sales':'purchase'))?>">Back to List</a></div>
<form id="txnForm" class="transaction-form txn-compact <?= $isSale ? '' : 'purchase-entry-form' ?>" method="post" action="<?=e(url('transaction-save'))?>" onsubmit="return validateTransactionForm()">
<input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="txn_type" value="<?=$type?>"><?php if($editTx):?><input type="hidden" name="transaction_id" value="<?=$editTx['id']?>"><?php endif; ?>
<div class="panel">
  <div class="entry-top <?= $isSale?'sale-entry-top':'purchase-entry-top' ?>">
    <div>
      <?php party_search_field($isSale?'Customer':'Supplier',$isSale?'customer':'supplier',(int)($editTx['party_id']??0),$editTx['party_name']??'',(($editTx['party_phone']??'')),$isSale?false:true); ?>
      <div class="subtle" style="margin-top:6px"><a href="<?=e(url('parties'))?>">+ Add Party</a><?= $isSale?' <span style="margin-left:6px">(Customer optional for Cash Sale)</span>':'' ?></div>
    </div>
    <div class="entry-right <?= $isSale?'sale-invoice-meta':'' ?>">
      <div class="form-group"><label><?=$isSale?'Invoice':'Bill'?> Number</label><input name="document_no" value="<?=e($editTx['document_no']??'')?>" placeholder="Auto: <?=$prefix?>000001"></div>
      <div class="form-group"><label><?=$isSale?'Invoice':'Bill'?> Date*</label><?php $editDateTime=(!empty($editTx['txn_date']))?date('Y-m-d\TH:i',strtotime((string)$editTx['txn_date'])):date('Y-m-d\TH:i'); ?><input type="<?= $isSale?'datetime-local':'date' ?>" name="txn_date" value="<?=e($isSale?$editDateTime:($editTx['txn_date']??date('Y-m-d')))?>" required></div>
      <?php if(!$isSale): ?><div class="subtle sale-purchase-note" style="margin-top:8px">BDT · Negative stock allowed</div><?php endif; ?>
    </div>
  </div>
  <div class="entry-table">
    <table><thead><tr><th>#</th><th>ITEM</th><th>QTY</th><th>UNIT</th><th>PRICE/UNIT</th><th>DISCOUNT</th><th>AMOUNT</th><th></th></tr></thead>
    <tbody id="entryRows">
<?php foreach($rows as $ri=>$r): $rid=(int)($r['item_id']??0); $rv=(float)($r['qty']??1); $rp=(float)($r['unit_price']??0); $rd=(float)($r['discount']??0); ?>
      <tr class="sale-row"><td><?=($ri+1)?></td><td><div class="item-picker-cell"><?php item_search_field($rid,'','',$isSale?'sale':'purchase'); ?><button type="button" class="serial-trigger-btn" hidden title="Enter serial numbers">Serial</button><select class="item-select item-source-select" name="item_id[]" required onchange="updatePrice(this)"><option value="">Select item</option><?php foreach($items as $it):?><option value="<?=$it['id']?>" data-sale="<?=$it['sale_price']?>" data-buy="<?=$it['purchase_price']?>" data-unit="<?=e($unitSymbols[(int)($it['unit_id']??0)]??'')?>" data-type="<?=$it['item_type']?>" data-serial-tracked="<?=((int)($it['serial_tracked']??0))?>" <?=($rid===(int)$it['id'])?'selected':''?>><?=e($it['name'])?></option><?php endforeach;?></select><div class="serial-entry-box" hidden data-mode="<?=$type?>"><textarea name="serial_numbers[]" class="serial-number-input" tabindex="-1" aria-hidden="true"><?=e(implode("\n",$serialMap[$r['id']]??[]))?></textarea></div></div></td><td><input class="qty" type="number" min="0.01" step="0.01" name="qty[]" value="<?=e((string)$rv)?>" required></td><td><span class="unit-label subtle"><?=e($r['unit_symbol']??'—')?></span></td><td><input class="price" type="number" step="0.01" min="0" name="price[]" value="<?=e((string)$rp)?>" required></td><td><input class="line-disc" type="number" step="0.01" min="0" name="discount[]" value="<?=e((string)$rd)?>"></td><td class="amount"><?=money(max(0,$rv*$rp-$rd))?></td><td><button type="button" class="dots" onclick="removeRow(this)">×</button></td></tr>
<?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="entry-actions"><button type="button" class="btn" onclick="addRow('<?=$type?>')">+ Add Row</button><span><b>Items Total</b> <strong id="subTotal"><?=money((float)($editTx['subtotal']??0))?></strong></span></div>
  <?php if($isSale): ?>
  <div class="sale-adjustments">
    <div class="form-group"><label>Tax / VAT <span class="subtle">(optional)</span></label><input id="tax" type="number" step="0.01" min="0" name="tax" value="<?=e((string)($editTx['tax']??0))?>"></div>
    <div class="form-group"><label>Direct Expense</label><input id="directExpense" type="number" step="0.01" min="0" name="direct_expense" value="<?=e((string)($editTx['direct_expense']??0))?>"></div>
    <div class="form-group"><label>Invoice Discount</label><input id="invoiceDiscount" type="number" step="0.01" min="0" name="invoice_discount" value="<?=e((string)($editTx['invoice_discount']??0))?>"></div>
  </div>
  <?php else: ?>
  <div class="grid3">
    <div class="form-group"><label>Invoice Discount</label><input id="invoiceDiscount" type="number" step="0.01" min="0" name="invoice_discount" value="<?=e((string)($editTx['invoice_discount']??0))?>"></div>
    <div class="form-group"><label>Tax / VAT <span class="subtle">(optional)</span></label><input id="tax" type="number" step="0.01" min="0" name="tax" value="<?=e((string)($editTx['tax']??0))?>"></div>
    <div class="form-group"><label>Direct Expense</label><input id="directExpense" type="number" step="0.01" min="0" name="direct_expense" value="<?=e((string)($editTx['direct_expense']??0))?>"></div>
  </div>
  <?php endif; ?>
  <div class="grid2" style="margin-top:12px">
    <div class="form-group"><label>Description / Note</label><textarea name="notes" rows="3" placeholder="Add description"><?=e($editTx['notes']??'')?></textarea></div>
    <div class="payment-box"><div class="panel-head payment-panel-head"><div class="payment-title-wrap"><h2>Payment</h2><?php if($isSale): ?><label class="received-toggle"><input type="checkbox" id="receivedToggle" name="received" value="1" <?=($editTx && (float)$editTx['due']<=0.009 && (float)$editTx['total']>0)?'checked':''?>> <span>Received</span></label><?php endif; ?></div><span class="subtle">Multiple methods allowed</span></div>
      <div id="paymentRows">
<?php foreach($payRows as $pi=>$pr): $pm=(string)($pr['method']??'cash'); $pa=(float)($pr['amount']??0); ?>
        <div class="payment-line" data-payment-row><select name="pay_method[]" onchange="togglePaymentFields(this)"><?=payment_select_options($bankRows,$pm,(string)($pr['account_name']??''))?></select><input type="date" name="pay_cheque_date[]" class="pay-cheque-date" style="display:none" value="<?=e($pr['cheque_date']??'')?>"><input type="number" min="0" step="0.01" name="pay_amount[]" value="<?=e((string)$pa)?>" placeholder="Amount"><input name="pay_ref[]" value="<?=e($pr['reference_no']??'')?>" placeholder="Reference / Cheque No."><button type="button" class="payment-remove" onclick="removePayment(this)" aria-label="Remove payment">×</button></div>
<?php endforeach; ?>
      </div>
      <button type="button" class="btn" onclick="addPayment()">+ Add Payment</button>
    </div>
  </div>
  <div class="grand"><span>Total</span><b id="grandTotal"><?=money((float)($editTx['total']??0))?></b></div>
  <div class="payment-summary"><span>Paid <b id="paidPreview"><?=money((float)($editTx['paid']??0))?></b></span><span>Due <b id="duePreview"><?=money((float)($editTx['due']??0))?></b></span></div>
  <div class="form-footer" style="margin:0 -16px -16px"><button type="button" class="btn" onclick="window.print()">Print / Preview</button><a class="btn" href="<?=e(url($isSale?'sales':'purchase'))?>">Cancel</a><button type="submit" class="btn primary"><?= $editTx?'Update':'Save' ?></button></div>
</div>
<div id="serialEntryModal" class="serial-entry-modal" data-mode="<?=$type?>" hidden aria-hidden="true">
  <div class="serial-entry-backdrop"></div>
  <div class="serial-entry-dialog" role="dialog" aria-modal="true" aria-labelledby="serialEntryTitle">
    <button type="button" class="serial-entry-x" id="serialModalClose" aria-label="Close">×</button>
    <div class="serial-entry-title" id="serialEntryTitle">Sale Item - Serial No.</div>
    <div class="serial-entry-item" id="serialEntryItemName"></div>
    <div class="serial-entry-sep"></div>
    <div class="serial-entry-label-row"><span>Enter Serial No.:</span><strong id="serialEntryCount">0/0 Entered</strong></div>
    <div class="serial-input-line" id="serialManualInputLine">
      <input type="text" id="serialEntryInput" class="serial-modal-input" placeholder="Enter/Scan" autocomplete="off">
      <button type="button" class="serial-add-btn" id="serialEntryAdd" aria-label="Add serial">✓</button>
    </div>
    <div class="serial-available-wrap" id="serialAvailableWrap">
      <input type="text" id="serialAvailableSearch" class="serial-available-search" placeholder="Search available serial..." autocomplete="off">
      <div class="serial-available-list" id="serialAvailableList"></div>
    </div>
    <div class="serial-entered-list" id="serialEnteredList"></div>
    <div class="serial-entry-help" id="serialEntryHelp"></div>
    <div class="serial-modal-footer"><button type="button" class="btn" id="serialModalCancel">Close</button><button type="button" class="btn primary" id="serialModalSave">Save</button></div>
  </div>
</div>
</form>
<script>document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.sale-row').forEach(function(r){bindEntryRow(r)});document.querySelectorAll('.payment-line').forEach(function(r){bindPaymentLine(r);var m=r.querySelector('[name="pay_method[]"]');if(m)togglePaymentFields(m);});recalc();});</script>
<?php page_end(); exit; }

if($route==='transaction-save'&&$_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf(); $type=$_POST['txn_type']??''; $txId=(int)($_POST['transaction_id']??0);
    if(!in_array($type,['sale','purchase'],true)){http_response_code(422);exit('Unsupported transaction type.');}
    $cid=(int)$u['company_id']; $party=(int)($_POST['party_id']??0); $items=$_POST['item_id']??[]; $qtys=$_POST['qty']??[]; $prices=$_POST['price']??[]; $discs=$_POST['discount']??[]; $serialRows=$_POST['serial_numbers']??[];
    if($type==='purchase' && $party<=0){flash('error','Supplier is required.');redirect('purchase-new');}
    $txnDateRaw=trim((string)($_POST['txn_date']??''));
    $txnDate=$txnDateRaw!==''?$txnDateRaw:date('Y-m-d H:i:s');
    if($type==='sale'){
        $dt=DateTime::createFromFormat('Y-m-d\TH:i',$txnDateRaw) ?: DateTime::createFromFormat('Y-m-d H:i:s',$txnDateRaw) ?: DateTime::createFromFormat('Y-m-d',$txnDateRaw);
        if(!$dt)throw new RuntimeException('Invalid invoice date/time.');
        $txnDate=$dt->format('Y-m-d H:i:s');
    }
    $dueDate=$type==='sale'?null:(($_POST['due_date']??'')?:null);
    $partyParam=$party>0?$party:null;
    $sub=0;$itemDisc=0;$pdo=db();
    try{
        $pdo->beginTransaction();
        $roleNeeded=$type==='sale'?'customer':'supplier';
        if($party>0){
            $stParty=$pdo->prepare('SELECT p.id FROM parties p WHERE p.id=? AND p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role=?) LIMIT 1');$stParty->execute([$party,$cid,$roleNeeded]);$partyRow=$stParty->fetch();if(!$partyRow)throw new RuntimeException($type==='sale'?'Invalid customer.':'Invalid supplier.');
        } elseif($type==='purchase'){
            throw new RuntimeException('Supplier is required.');
        }
        $validItems=[];
        foreach($items as $i=>$iid){$iid=(int)$iid;$q=(float)($qtys[$i]??0);$p=(float)($prices[$i]??0);$d=max(0,(float)($discs[$i]??0));if($iid<=0||$q<=0)continue;$st=$pdo->prepare('SELECT * FROM items WHERE id=? AND company_id=? AND active=1 LIMIT 1');$st->execute([$iid,$cid]);$it=$st->fetch();if(!$it)throw new RuntimeException('Invalid item selected.');if($it['item_type']==='service'&&$q<=0)throw new RuntimeException('Invalid quantity.');$lineGross=$q*$p;if($d>$lineGross)$d=$lineGross;$sub+=$lineGross;$itemDisc+=$d;$serials=normalize_serials((string)($serialRows[$i]??'')); if((int)($it['serial_tracked']??0)===1 && $type==='purchase'){if(abs($q-round($q))>0.000001)throw new RuntimeException('Serial-tracked purchase quantity must be a whole number.');if(count($serials)!==(int)round($q))throw new RuntimeException('Serial numbers must match the purchase quantity for '.$it['name'].'.');} if((int)($it['serial_tracked']??0)===1 && $type==='sale' && $serials && count($serials)!==(int)round($q))throw new RuntimeException('Selected serial numbers must match the sale quantity for '.$it['name'].'.'); $validItems[]=[$iid,$q,$p,$d,$it,$serials];}
        if(!$validItems)throw new RuntimeException('Add at least one item.');
        $invDisc=max(0,(float)($_POST['invoice_discount']??0));$tax=max(0,(float)($_POST['tax']??0));$direct=max(0,(float)($_POST['direct_expense']??0));
        $afterItem=max(0,$sub-$itemDisc);if($invDisc>$afterItem)$invDisc=$afterItem;$total=round(max(0,$afterItem-$invDisc+$tax+$direct),2);
        $paid=0;$paymentRows=[];$methods=$_POST['pay_method']??[];$amounts=$_POST['pay_amount']??[];$accounts=$_POST['pay_account']??[];$refs=$_POST['pay_ref']??($_POST['pay_reference']??[]);$cheqDates=$_POST['pay_cheque_date']??[];
        if($type==='sale' && !empty($_POST['received'])){
            if(!$methods)$methods=['cash'];
            if(!$amounts)$amounts=[0];
            $amounts[0]=$total;
            for($mi=1;$mi<count($methods);$mi++)$amounts[$mi]=0;
        }
        foreach($methods as $i=>$rawMethod){$a=max(0,(float)($amounts[$i]??0));if($a<=0)continue;[$m,$acct]=normalize_payment_method($pdo,$cid,(string)$rawMethod,trim($accounts[$i]??''));$ref=trim($refs[$i]??'');if($m==='cheque'&&$ref==='')throw new RuntimeException('Cheque number is required.');$paymentRows[]=[$m,$acct,$ref,$a];$paid+=$a;}
        if($paid>$total+0.01)throw new RuntimeException('Payment cannot be greater than invoice total.');
        $due=round(max(0,$total-$paid),2);
        if($type==='sale' && $party<=0 && $due>0.01)throw new RuntimeException('Cash Sale without Customer must be fully received. Tick Received to adjust the full payment.');
        $doc=trim($_POST['document_no']??'');
        if($txId>0){
            $st=$pdo->prepare('SELECT * FROM transactions WHERE id=? AND company_id=? AND txn_type=? AND deleted_at IS NULL LIMIT 1');$st->execute([$txId,$cid,$type]);$existingTx=$st->fetch();
            if(!$existingTx)throw new RuntimeException($type==='sale'?'Sales invoice not found.':'Purchase bill not found.');
            if($doc==='')$doc=$existingTx['document_no'];
            $pdo->prepare('UPDATE transactions SET party_id=?,document_no=?,txn_date=?,due_date=?,subtotal=?,item_discount=?,invoice_discount=?,tax=?,direct_expense=?,total=?,paid=?,due=?,status=?,notes=? WHERE id=? AND company_id=?')->execute([$partyParam,$doc,$txnDate,$dueDate,$sub,$itemDisc,$invDisc,$tax,$direct,$total,$paid,$due,$due>0?'open':'final',trim($_POST['notes']??''),$txId,$cid]);
            // Rebuild all child accounting/stock/payment rows from the edited form.
            $pdo->prepare('DELETE FROM payment_lines WHERE transaction_id=?')->execute([$txId]);
            $oldSerials=$pdo->prepare('SELECT tis.serial_id,isx.sale_transaction_id,isx.purchase_transaction_id FROM transaction_item_serials tis JOIN item_serials isx ON isx.id=tis.serial_id WHERE tis.transaction_id=? AND tis.company_id=?');$oldSerials->execute([$txId,$cid]);$oldSerialRows=$oldSerials->fetchAll();
            foreach($oldSerialRows as $os){if($type==='purchase' && !empty($os['sale_transaction_id']))throw new RuntimeException('This purchase cannot be edited because a serial number from it has already been sold.');}
            if($oldSerialRows){$pdo->prepare('UPDATE item_serials SET status=CASE WHEN purchase_transaction_id=? THEN "available" ELSE status END,sale_transaction_id=NULL WHERE id IN (SELECT serial_id FROM transaction_item_serials WHERE transaction_id=? AND company_id=?)')->execute([$txId,$txId,$cid]);}
            $pdo->prepare('DELETE FROM transaction_item_serials WHERE transaction_id=? AND company_id=?')->execute([$txId,$cid]);
            $pdo->prepare('DELETE FROM transaction_items WHERE transaction_id=?')->execute([$txId]);
            $pdo->prepare('DELETE FROM stock_movements WHERE company_id=? AND transaction_id=?')->execute([$cid,$txId]);
            $pdo->prepare('DELETE FROM ledger_entries WHERE company_id=? AND transaction_id=?')->execute([$cid,$txId]);
            $tid=$txId; $auditAction='update';
        }else{
            if($doc==='')$doc=next_document_in_transaction($pdo,$cid,$type,$type==='sale'?'SI-':'PB-');
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$partyParam,$type,$doc,$txnDate,$dueDate,$sub,$itemDisc,$invDisc,$tax,$direct,$total,$paid,$due,$u['currency_code'],'final',trim($_POST['notes']??''),$u['id']]);
            $tid=(int)$pdo->lastInsertId(); $auditAction='create';
        }
        $ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');$stock=$pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,movement_type,note) VALUES(?,?,?,?,?,?,?)');$serialLink=$pdo->prepare('INSERT INTO transaction_item_serials(company_id,transaction_id,transaction_item_id,serial_id,movement_type) VALUES(?,?,?,?,?)');
        $cogs=0;
        foreach($validItems as [$iid,$q,$p,$d,$it,$serials]){$lineTax=0;$amount=max(0,$q*$p-$d);$ins->execute([$tid,$iid,$q,$p,$d,$lineTax,$amount]);$tiId=(int)$pdo->lastInsertId();if($it['item_type']==='product'){
                $movementQty = $type==='sale' ? -1*$q : $q;
                $movementType = $type==='sale' ? 'sale' : 'purchase';
                $stock->execute([$cid,$iid,$tid,$txnDate,$movementQty,$movementType,$doc]);
                if($type==='sale') $cogs+=max(0,$q*(float)$it['purchase_price']);
                if((int)($it['serial_tracked']??0)===1){
                    if($type==='purchase'){
                        foreach($serials as $sn){$qsn=$pdo->prepare('SELECT id,status FROM item_serials WHERE company_id=? AND item_id=? AND serial_number=? FOR UPDATE');$qsn->execute([$cid,$iid,$sn]);$ex=$qsn->fetch();if($ex && $ex['status']!=='void')throw new RuntimeException('Serial number already exists for '.$it['name'].': '.$sn);if($ex){$pdo->prepare('UPDATE item_serials SET status="available",purchase_transaction_id=?,sale_transaction_id=NULL WHERE id=?')->execute([$tid,$ex['id']]);$sid=(int)$ex['id'];}else{$pdo->prepare('INSERT INTO item_serials(company_id,item_id,serial_number,status,purchase_transaction_id) VALUES(?,?,?,?,?)')->execute([$cid,$iid,$sn,'available',$tid]);$sid=(int)$pdo->lastInsertId();}$serialLink->execute([$cid,$tid,$tiId,$sid,'purchase']);}
                    }else{$needed=(int)round($q);$chosen=[];if($serials){foreach($serials as $sn){$qsn=$pdo->prepare('SELECT id,status FROM item_serials WHERE company_id=? AND item_id=? AND serial_number=? FOR UPDATE');$qsn->execute([$cid,$iid,$sn]);$sr=$qsn->fetch();if(!$sr||$sr['status']!=='available')throw new RuntimeException('Serial not available: '.$sn);$chosen[]=(int)$sr['id'];}}else{$qsn=$pdo->prepare('SELECT id FROM item_serials WHERE company_id=? AND item_id=? AND status="available" ORDER BY id LIMIT '.$needed.' FOR UPDATE');$qsn->execute([$cid,$iid]);$chosen=array_map('intval',$qsn->fetchAll(PDO::FETCH_COLUMN));if(count($chosen)<$needed)throw new RuntimeException('Not enough available serial numbers for '.$it['name'].'; available serial stock: '.count($chosen));}foreach($chosen as $sid){$pdo->prepare('UPDATE item_serials SET status="sold",sale_transaction_id=? WHERE id=? AND company_id=?')->execute([$tid,$sid,$cid]);$serialLink->execute([$cid,$tid,$tiId,$sid,'sale']);}}
                }
            }}
        $pl=$pdo->prepare('INSERT INTO payment_lines(transaction_id,method,account_name,reference_no,amount,status) VALUES(?,?,?,?,?,?)');foreach($paymentRows as [$m,$acct,$ref,$a]){$pl->execute([$tid,$m,$acct?:null,$ref?:null,$a,'completed']);}
        $lines=[];
        foreach($paymentRows as [$m,$acct,$ref,$a]){
            [$payCode,$payName]=payment_account_code($m,$acct);
            if($type==='sale'){
                $lines[]=[$payCode,$payName,$a,0,$doc];
            }else{
                $lines[]=[$payCode,$payName,0,$a,$doc];
            }
        }
        if($type==='sale'){
            if($due>0)$lines[]=['1200','Accounts Receivable',$due,0,$doc];
            $netSales=max(0,$afterItem-$invDisc);$lines[]=['4000','Sales Revenue',0,$netSales,$doc];
            if($tax>0)$lines[]=['2100','Tax Payable',0,$tax,$doc];
            if($direct>0)$lines[]=['4200','Direct Expense Recovery',0,$direct,$doc];
            if($cogs>0){$lines[]=['5100','Cost of Goods Sold',$cogs,0,$doc];$lines[]=['1300','Inventory',0,$cogs,$doc];}
        }else{
            if($due>0)$lines[]=['2100','Accounts Payable',0,$due,$doc];
            $netPurchase=max(0,$afterItem-$invDisc);
            $serviceCost=0;$productCost=0;
            foreach($validItems as [$iid,$q,$p,$d,$it,$serials]){ $amt=max(0,$q*$p-$d); if($it['item_type']==='product')$productCost+=$amt; else $serviceCost+=$amt; }
            if($productCost+$direct>0)$lines[]=['1300','Inventory',$productCost+$direct,0,$doc];
            if($serviceCost>0)$lines[]=['5200','Purchase / Service Cost',$serviceCost,0,$doc];
            if($tax>0)$lines[]=['1400','Input VAT / Tax',$tax,0,$doc];
            if($invDisc>0)$lines[]=['4300','Purchase Discount',0,$invDisc,$doc];
        }
        $debit=0;$credit=0;foreach($lines as $l){$debit+=round((float)$l[2],2);$credit+=round((float)$l[3],2);} $diff=round($debit-$credit,2);if(abs($diff)>0.01)throw new RuntimeException('Accounting entry is not balanced.');
        post_ledger($pdo,$cid,$tid,$txnDate,$lines);audit($auditAction,'transaction',$tid,['type'=>$type,'document'=>$doc,'total'=>$total,'paid'=>$paid,'due'=>$due]);
        $pdo->commit();flash('success',($type==='sale'?'Sale ':'Purchase Bill ').$doc.($auditAction==='update'?' updated successfully.':' saved successfully.'));redirect($type==='sale'?'sales?view='.$tid:'purchase?view='.$tid);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        flash('error',$e->getMessage());
        $back=($type==='sale'?'sale-new':'purchase-new');
        redirect($txId>0 ? $back.'?edit='.$txId : $back);
    }
}


if($route==='payment-in'){
    $cid=(int)$u['company_id']; $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        try{
            $party=(int)($_POST['party_id']??0);
            if($party<=0)throw new RuntimeException('Party is required.');
            $st=db()->prepare('SELECT p.id,p.name,COALESCE((SELECT GROUP_CONCAT(pr.role ORDER BY pr.role SEPARATOR ",") FROM party_roles pr WHERE pr.party_id=p.id),"") role_list FROM parties p WHERE p.id=? AND p.company_id=? LIMIT 1');$st->execute([$party,$cid]);$pr=$st->fetch();
            if(!$pr)throw new RuntimeException('Invalid party.');
            $partyRoles=$pr['role_list']!==''?array_map('trim',explode(',',$pr['role_list'])):[];
            if(!array_intersect($partyRoles,['customer','investor','lender','other']))throw new RuntimeException('This party is not enabled for Payment In.');
            $methods=$_POST['pay_method']??[];$amounts=$_POST['pay_amount']??[];$accounts=$_POST['pay_account']??[];$refs=$_POST['pay_ref']??($_POST['pay_reference']??[]);$cheqDates=$_POST['pay_cheque_date']??[];
            $rows=[];$received=0;
            foreach($methods as $i=>$rawMethod){$a=max(0,(float)($amounts[$i]??0));if($a<=0)continue;[$m,$acct]=normalize_payment_method($pdo,$cid,(string)$rawMethod,trim($accounts[$i]??''));$ref=trim($refs[$i]??'');$cd=$cheqDates[$i]??null;if($m==='cheque'&&$ref==='')throw new RuntimeException('Cheque number is required.');$rows[]=[$m,$acct?:null,$ref?:null,$cd?:null,$a];$received+=$a;}
            if($received<=0)throw new RuntimeException('Enter received amount.');
            $st=db()->prepare('SELECT COALESCE(SUM(CASE WHEN txn_type="sale" THEN due WHEN txn_type="payment_in" THEN -total ELSE 0 END),0) FROM transactions WHERE company_id=? AND party_id=? AND deleted_at IS NULL');$st->execute([$cid,$party]);$outstanding=max(0,(float)$st->fetchColumn());
            $isDueBasedParty=in_array('customer',$partyRoles,true) && !in_array('investor',$partyRoles,true) && !in_array('lender',$partyRoles,true);
            if($isDueBasedParty && $received>$outstanding+0.01)throw new RuntimeException('Received amount cannot be greater than the customer outstanding due ('.money($outstanding).').');
            $date=$_POST['txn_date']??date('Y-m-d');$doc=trim($_POST['document_no']??'');$pdo=db();$pdo->beginTransaction();
            if($doc==='')$doc=next_document_in_transaction($pdo,$cid,'payment_in','PI-');
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$party,'payment_in',$doc,$date,null,$received,$received,$received,0,$u['currency_code'],'final',trim($_POST['notes']??''),$u['id']]);
            $tid=(int)$pdo->lastInsertId();
            $pl=$pdo->prepare('INSERT INTO payment_lines(transaction_id,method,account_name,reference_no,cheque_date,amount,status) VALUES(?,?,?,?,?,?,?)');
            $ledger=[];
            foreach($rows as [$m,$acct,$ref,$cd,$a]){
                $pl->execute([$tid,$m,$acct,$ref,$cd,$a,'completed']);
                [$code,$name]=payment_account_code($m,$acct);
                $ledger[]=[$code,$name,$a,0,$doc];
            }
            if(in_array('investor',$partyRoles,true)){
                $ledger[]=['3100','Investor Capital',0,$received,$doc];
            }elseif(in_array('lender',$partyRoles,true)){
                $ledger[]=['2100','Loan Payable',0,$received,$doc];
            }else{
                $ledger[]=['1200','Accounts Receivable',0,$received,$doc];
            }
            post_ledger($pdo,$cid,$tid,$date,$ledger);
            audit('create','transaction',$tid,['type'=>'payment_in','document'=>$doc,'total'=>$received,'party_id'=>$party]);
            $pdo->commit();flash('success','Payment-In '.$doc.' saved successfully.');redirect('payment-in?view='.$tid);
        }catch(Throwable $e){if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect('payment-in');}
    }
    $partyRows=db()->prepare('SELECT p.id,p.name,p.phone,COALESCE((SELECT GROUP_CONCAT(pr.role ORDER BY pr.role SEPARATOR ", ") FROM party_roles pr WHERE pr.party_id=p.id),"") role_list,COALESCE((SELECT SUM(CASE WHEN t.txn_type="sale" THEN t.due WHEN t.txn_type="payment_in" THEN -t.total ELSE 0 END) FROM transactions t WHERE t.company_id=? AND t.party_id=p.id AND t.deleted_at IS NULL),0) outstanding FROM parties p WHERE p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role IN ("customer","investor","lender","other")) ORDER BY p.name');
    $partyRows->execute([$cid,$cid]);$parties=$partyRows->fetchAll();
    $banks=db()->prepare('SELECT id,name,bank_name,account_number FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY name');$banks->execute([$cid]);$bankRows=$banks->fetchAll();
    page_start('Payment In');
    if(isset($_GET['view'])){
        $tid=(int)$_GET['view'];$st=db()->prepare('SELECT t.*,p.name party_name,p.phone party_phone FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type="payment_in" LIMIT 1');$st->execute([$tid,$cid]);$tx=$st->fetch();
        if($tx){$ps=db()->prepare('SELECT * FROM payment_lines WHERE transaction_id=? ORDER BY id');$ps->execute([$tid]);$payments=$ps->fetchAll();
        ?><div class="page-title"><div><h1>Payment-In <?=e($tx['document_no'])?></h1><p><?=e($tx['txn_date'])?> · <?=e($tx['party_name'])?></p></div><div><button class="btn" onclick="window.print()">Print</button><a class="btn primary" href="<?=e(url('payment-in'))?>">+ New Payment</a></div></div>
        <div class="cards-top"><div class="metric-card"><div class="label">Received</div><div class="value"><?=money((float)$tx['total'])?></div></div><div class="metric-card"><div class="label">Party</div><div class="value" style="font-size:20px"><?=e($tx['party_name'])?></div></div></div>
        <div class="panel"><div class="panel-head"><h2>Payment Details</h2></div><div class="table-wrap"><table><thead><tr><th>METHOD</th><th>ACCOUNT</th><th>REFERENCE</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($payments as $r):?><tr><td><?=e(ucwords(str_replace('_',' ',$r['method'])))?></td><td><?=e($r['account_name']??'-')?></td><td><?=e($r['reference_no']??'-')?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div></div><?php page_end();exit;}
    }
    $st=db()->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="payment_in" AND t.deleted_at IS NULL ORDER BY t.id DESC LIMIT 50');$st->execute([$cid]);$rows=$st->fetchAll();
    ?><div class="page-title"><div><h1>Payment In</h1><p>Receive payments from customers, investors, lenders and other parties</p></div><a class="btn primary" href="#newPayment">⊕ Add Payment-In</a></div>
    <div class="panel" id="newPayment"><div class="panel-head"><h2>New Payment-In</h2><span class="subtle">Multiple payment methods allowed</span></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="entry-top"><div><div class="form-group"><label>Party*</label><?php party_search_field('Party','customer_all',0,'',''); ?></div><div class="subtle" id="partyDue" style="margin-top:6px">Select a party to see outstanding due.</div></div><div><div class="form-group"><label>Receipt Number</label><input name="document_no" placeholder="Auto: PI-000001"></div><div class="form-group"><label>Date*</label><input type="date" name="txn_date" value="<?=date('Y-m-d')?>" required></div></div><div class="entry-right"><div class="right-card"><div class="title">CURRENT DUE</div><div class="value" id="currentDue">৳0.00</div></div></div></div>
    <div class="payment-box"><div class="panel-head"><h2>Payment Methods</h2><span class="subtle">Split one receipt across multiple methods</span></div><div id="paymentRows">
      <div class="payment-line"><select name="pay_method[]" onchange="togglePaymentFields(this)"><?=payment_select_options($bankRows,'cash','')?></select><input type="date" name="pay_cheque_date[]" class="pay-cheque-date" style="display:none"><input name="pay_ref[]" placeholder="Reference / Cheque No."><input type="number" min="0" step="0.01" name="pay_amount[]" value="0" placeholder="Amount"></div>
    </div><button type="button" class="btn" onclick="addPayment()">+ Add Payment</button></div><div class="grid2" style="margin-top:12px"><div class="form-group"><label>Notes</label><textarea name="notes" rows="3" placeholder="Add description"></textarea></div><div class="metric-card"><div class="label">Total Received</div><div class="value" id="receivedPreview">৳0.00</div></div></div><div class="form-footer" style="margin:0 -16px -16px"><button type="button" class="btn" onclick="window.print()">Print / Preview</button><button class="btn primary">Save Payment-In</button></div></form></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>TRANSACTIONS</h2><input class="input" style="max-width:240px" placeholder="Search"></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>RECEIPT NO.</th><th>PARTY NAME</th><th>PAYMENT TYPE</th><th>AMOUNT</th><th>ACTION</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td><td><?=e($r['document_no'])?></td><td><?=e($r['party_name']??'')?></td><td>Multiple / See receipt</td><td><?=money((float)$r['total'])?></td><td class="action"><a class="btn" href="<?=e(url('payment-in?view='.(int)$r['id']))?>">View</a></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6" class="subtle">No payment-in transactions yet.</td></tr><?php endif;?></tbody></table></div></div>
    <script>
    document.addEventListener('DOMContentLoaded',()=>{const party=document.getElementById('paymentParty'),due=document.getElementById('currentDue'),note=document.getElementById('partyDue');function upd(){const d=parseFloat(party?.dataset.due||0);const roles=(party?.dataset.roles||'').split(',').map(x=>x.trim());const special=roles.includes('investor')||roles.includes('lender')||roles.includes('other');due.textContent='৳'+d.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});note.textContent=party?.value?(special?'Payment In is available for this party; amount is not limited by customer due.':'Outstanding due: '+due.textContent):'Select a party to see outstanding due.';}party?.addEventListener('change',upd);document.addEventListener('party-selected',upd);function sum(){let t=0;document.querySelectorAll('input[name="pay_amount[]"]').forEach(i=>t+=parseFloat(i.value||0));const x=document.getElementById('receivedPreview');if(x)x.textContent='৳'+t.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}document.querySelectorAll('input[name="pay_amount[]"]').forEach(i=>i.addEventListener('input',sum));window.addEventListener('input',e=>{if(e.target.matches('input[name="pay_amount[]"]'))sum();});upd();sum();});
    </script><?php page_end();exit;
}

if($route==='sales' && isset($_GET['view'])){
    $tid=(int)$_GET['view'];$cid=(int)$u['company_id'];
    $st=db()->prepare('SELECT t.*,p.name party_name,p.phone party_phone,p.address party_address FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type="sale" LIMIT 1');$st->execute([$tid,$cid]);$tx=$st->fetch();
    if(!$tx){http_response_code(404);page_start('Invoice Not Found');echo '<div class="panel"><h1>Invoice not found</h1></div>';page_end();exit;}
    $itSt=db()->prepare('SELECT ti.*,i.name item_name,i.item_type,u.symbol unit_symbol FROM transaction_items ti JOIN items i ON i.id=ti.item_id LEFT JOIN units u ON u.id=i.unit_id WHERE ti.transaction_id=? ORDER BY ti.id');$itSt->execute([$tid]);$lines=$itSt->fetchAll();
    $pay=db()->prepare('SELECT * FROM payment_lines WHERE transaction_id=? ORDER BY id');$pay->execute([$tid]);$payments=$pay->fetchAll();
    page_start('Sale Invoice');
    ?><script>document.title='Sale Invoice · '+<?=json_encode((string)($u['company_name']??''),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;</script>
    <div class="page-title"><div><h1>Sale Invoice <?=e($tx['document_no'])?></h1><p><?=e($tx['txn_date'])?></p></div><div><a class="btn" href="<?=e(url('sales?edit='.(int)$tx['id']))?>">Edit</a><button class="btn" onclick="window.print()">Print</button><a class="btn" href="<?=e(url('sale-new'))?>">+ Add Sale</a></div></div>
    <div class="panel invoice-print"><div class="invoice-head"><div><?php $logo=saas_company_logo_url($u['logo_path']??null); if($logo): ?><img src="<?=e($logo)?>" alt="Company logo" style="max-height:56px;max-width:180px;object-fit:contain;margin-bottom:6px"><br><?php endif; ?><h2><?=e($u['company_name'] ?? '')?></h2><p>Sale Invoice</p></div><div><strong><?=e($tx['document_no'])?></strong><br><?=e(date('d/m/Y',strtotime($tx['txn_date'])))?></div></div>
    <div class="grid3 invoice-meta"><div><div class="subtle">Customer</div><strong><?=e($tx['party_name']??'Walk-in')?></strong><div class="subtle"><?=e($tx['party_phone']??'')?></div></div><div class="print-hide"><div class="subtle">Due Date</div><strong><?=e($tx['due_date']?date('d/m/Y',strtotime($tx['due_date'])):'—')?></strong></div><div class="print-hide"><div class="subtle">Status</div><span class="status <?=($tx['due']>0?'open':'paid')?>"><?=e($tx['due']>0?'Due':'Paid')?></span></div></div>
    <div class="table-wrap" style="margin-top:16px"><table><thead><tr><th>#</th><th>ITEM</th><th>QTY</th><th>PRICE/UNIT</th><th>DISCOUNT</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($lines as $i=>$r):?><tr><td><?=$i+1?></td><td><?=e($r['item_name'])?></td><td><?=qty((float)$r['qty']).' '.e($r['unit_symbol']??'')?></td><td><?=money((float)$r['unit_price'])?></td><td><?=money((float)$r['discount'])?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div>
    <div class="invoice-totals"><div>Subtotal <b><?=money((float)$tx['subtotal'])?></b></div><?php if((float)$tx['item_discount']>0):?><div>Item Discount <b><?=money((float)$tx['item_discount'])?></b></div><?php endif;?><?php if((float)$tx['invoice_discount']>0):?><div>Invoice Discount <b><?=money((float)$tx['invoice_discount'])?></b></div><?php endif;?><?php if((float)$tx['tax']>0):?><div>Tax/VAT <b><?=money((float)$tx['tax'])?></b></div><?php endif;?><?php if((float)$tx['direct_expense']>0):?><div>Direct Expense <b><?=money((float)$tx['direct_expense'])?></b></div><?php endif;?><div class="total-line">Total <b><?=money((float)$tx['total'])?></b></div><div>Paid <b><?=money((float)$tx['paid'])?></b></div><div>Due <b><?=money((float)$tx['due'])?></b></div></div>
    <div style="margin-top:16px"><h3>Payments</h3><?php if($payments):?><div class="table-wrap"><table><thead><tr><th>METHOD</th><th>ACCOUNT</th><th>REFERENCE</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($payments as $p):?><tr><td><?=e(ucwords(str_replace('_',' ',$p['method'])))?></td><td><?=e($p['account_name']??'')?></td><td><?=e($p['reference_no']??'')?></td><td><?=money((float)$p['amount'])?></td></tr><?php endforeach;?></tbody></table></div><?php else:?><p class="subtle">No payment recorded.</p><?php endif;?></div>
    <?php if(!empty($tx['notes'])):?><div style="margin-top:16px"><div class="subtle">Notes</div><div><?=nl2br(e($tx['notes']))?></div></div><?php endif;?></div><?php page_end();exit;
}

function transaction_list(string $type,string $title,string $addRoute,string $prefix): void {
    global $u;
    $cid=(int)$u['company_id'];
    $q=trim($_GET['q']??'');
    $from=$_GET['from']??date('Y-m-01');
    $to=$_GET['to']??date('Y-m-d');
    $valid=function($d){$x=DateTime::createFromFormat('Y-m-d',$d);return $x&&$x->format('Y-m-d')===$d;};
    if(!$valid($from))$from=date('Y-m-01');
    if(!$valid($to))$to=date('Y-m-d');
    if($from>$to){[$from,$to]=[$to,$from];}

    $params=[$cid,$type,$from,$to];
    $sql='SELECT t.*,p.name party_name,p.phone party_phone,(SELECT GROUP_CONCAT(DISTINCT pl.method ORDER BY pl.id SEPARATOR ",") FROM payment_lines pl WHERE pl.transaction_id=t.id) payment_methods FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL AND t.txn_date BETWEEN ? AND ?';
    if($q!==''){$sql.=' AND (t.document_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like);}
    $sql.=' ORDER BY t.txn_date DESC,t.id DESC';
    $st=db()->prepare($sql);$st->execute($params);$rows=$st->fetchAll();

    $sumSql='SELECT COALESCE(SUM(t.paid),0),COALESCE(SUM(t.due),0),COALESCE(SUM(t.total),0) FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL AND t.txn_date BETWEEN ? AND ?';
    $sumParams=[$cid,$type,$from,$to];
    if($q!==''){$sumSql.=' AND (t.document_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)';array_push($sumParams,'%'.$q.'%','%'.$q.'%','%'.$q.'%');}
    $qsum=db()->prepare($sumSql);$qsum->execute($sumParams);[$paid,$due,$total]=$qsum->fetch(PDO::FETCH_NUM);
    $base=url($type==='sale'?'sales':'purchase');
    $addLabel=$type==='sale'?'Sale':'Purchase';

    page_start($title);
    ?>
    <div class="page-title"><div><h1><?=e($title)?></h1><p><?=e(date('d M Y',strtotime($from)))?> → <?=e(date('d M Y',strtotime($to)))?><?= $q!==''?' · Search: '.e($q):''?></p></div><a class="btn primary" href="<?=e(url($addRoute))?>">⊕ Add <?=e($addLabel)?></a></div>
    <div class="panel">
      <form class="filterbar" method="get">
        <div class="between"><span>Between</span><input type="date" name="from" value="<?=e($from)?>"><span>To</span><input type="date" name="to" value="<?=e($to)?>"></div>
        <input class="input" style="max-width:260px" name="q" value="<?=e($q)?>" placeholder="Search invoice, party or phone">
        <button class="btn primary" type="submit">Apply</button><a class="btn" href="<?=e($base)?>">Reset</a><button class="btn" type="button" onclick="window.print()">▤ Print</button>
      </form>
      <div class="summary-strip"><div class="summary-box paid"><div class="lbl">Paid</div><div class="val"><?=money((float)$paid)?></div></div><b>+</b><div class="summary-box unpaid"><div class="lbl">Unpaid</div><div class="val"><?=money((float)$due)?></div></div><b>=</b><div class="summary-box total"><div class="lbl">Total</div><div class="val"><?=money((float)$total)?></div></div></div>
      <div class="panel-head"><h2>TRANSACTIONS</h2><span class="subtle"><?=count($rows)?> result<?=count($rows)===1?'':'s'?></span></div>
      <div class="table-wrap"><table><thead><tr><th>DATE</th><th>INVOICE NO.</th><th>PARTY NAME</th><th>TRANSACTION</th><th>PAYMENT TYPE</th><th>AMOUNT</th><th>BALANCE DUE</th><th>ACTION</th></tr></thead><tbody>
      <?php foreach($rows as $r):
        $methods=array_filter(array_map('trim',explode(',',(string)($r['payment_methods']??''))));
        $paymentLabel=$methods?ucwords(str_replace('_',' ',implode(' + ',$methods))):($r['due']>0?($r['paid']>0?'Partial/Credit':'Credit'):'Paid');
      ?>
      <tr>
        <td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td>
        <td><a class="doc-link" href="<?=e($base.'?view='.(int)$r['id'])?>"><strong><?=e($r['document_no'])?></strong></a></td>
        <td><?=e($r['party_name']??'Walk-in')?><div class="subtle"><?=e($r['party_phone']??'')?></div></td>
        <td><?=e(ucwords(str_replace('_',' ',$r['txn_type'])))?></td>
        <td><span class="status <?=$r['due']>0?'open':'paid'?>"><?=e($paymentLabel)?></span></td>
        <td><?=money((float)$r['total'])?></td><td><?=money((float)$r['due'])?></td>
        <td class="action"><div style="display:flex;align-items:center;gap:6px;justify-content:flex-end">
          <a class="btn small-btn" href="<?=e($base.'?view='.(int)$r['id'])?>">View</a>
          <?php if($type==='purchase' && (float)$r['due']>0):?><a class="btn primary small-btn" href="<?=e(url('payment-out?party='.(int)$r['party_id']))?>">PAYMENT OUT</a><?php endif; ?>
          <details class="row-actions"><summary class="dots" aria-label="Actions">⋮</summary>
            <div class="row-menu">
              <a href="<?=e($base.'?edit='.(int)$r['id'])?>">View / Edit</a>
              <?php if($type==='purchase' && (float)$r['due']>0):?><a href="<?=e(url('payment-out?party='.(int)$r['party_id']))?>">Payment Out</a><?php endif; ?>
              <form method="post" onsubmit="return confirm('Delete this transaction? It will move to Recycle Bin.')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Delete</button></form>
              <form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Duplicate</button></form>
              <a href="<?=e($base.'?view='.(int)$r['id'].'&print=1')?>">Open PDF</a><a href="<?=e($base.'?view='.(int)$r['id'])?>">Preview</a><a href="<?=e($base.'?view='.(int)$r['id'].'&print=1')?>">Print</a>
            </div>
          </details>
        </div></td>
      </tr>
      <?php endforeach;if(!$rows):?><tr><td colspan="8" class="subtle">No transactions found for the selected period.</td></tr><?php endif;?></tbody></table></div>
    </div>
    <?php page_end();
}

if($route==='sales'){
    $u=require_login();
    $cid=(int)$u['company_id'];
    $pdo=db();

    // Edit links from the Sales list must open the existing Sales editor route.
    if($_SERVER['REQUEST_METHOD']!=='POST' && isset($_GET['edit'])){
        $editId=(int)$_GET['edit'];
        if($editId>0){ redirect('sale-new?edit='.$editId); }
    }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $action=$_POST['action']??'';
        $txId=(int)($_POST['transaction_id']??0);
        try{
            if($action==='delete'){
                if($txId<=0) throw new RuntimeException('Invalid sales invoice.');
                $st=$pdo->prepare('SELECT * FROM transactions WHERE id=? AND company_id=? AND txn_type="sale" AND deleted_at IS NULL LIMIT 1');
                $st->execute([$txId,$cid]); $tx=$st->fetch();
                if(!$tx) throw new RuntimeException('Sales invoice not found for deletion.');

                $pdo->beginTransaction();

                // Soft delete the source transaction.
                $pdo->prepare('UPDATE transactions SET deleted_at=NOW() WHERE id=? AND company_id=?')->execute([$txId,$cid]);

                // Reverse stock movements linked to this transaction.
                $st=$pdo->prepare('SELECT * FROM stock_movements WHERE company_id=? AND transaction_id=?');
                $st->execute([$cid,$txId]); $moves=$st->fetchAll();
                foreach($moves as $mv){
                    $reverse=-1*(float)$mv['quantity'];
                    if(abs($reverse)>0.0000001){
                        $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?,?)')
                            ->execute([$cid,$mv['item_id'],null,date('Y-m-d'),$reverse,$mv['unit_price'],'reversal','Reversal of deleted sale SI#'.$txId]);
                    }
                }

                // Soft delete transaction lines if supported; otherwise leave them attached to the deleted transaction.
                try{
                    $pdo->prepare('UPDATE transaction_items SET deleted_at=NOW() WHERE transaction_id=?')->execute([$txId]);
                }catch(Throwable $ignore){}

                audit('delete','transaction',$txId,['type'=>'sale','document_no'=>$tx['document_no']??null,'reason'=>'Sales invoice moved to Recycle Bin']);
                $pdo->commit();
                flash('success','Sales invoice moved to Recycle Bin.');
                redirect('sales');
            }

            if($action==='duplicate'){
                if($txId<=0) throw new RuntimeException('Invalid sales invoice.');
                $st=$pdo->prepare('SELECT * FROM transactions WHERE id=? AND company_id=? AND txn_type="sale" AND deleted_at IS NULL LIMIT 1');
                $st->execute([$txId,$cid]); $tx=$st->fetch();
                if(!$tx) throw new RuntimeException('Sales invoice not found.');
                flash('success','Open the invoice to duplicate it.');
                redirect('sales?view='.$txId.'&duplicate=1');
            }
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error',$e->getMessage());
            redirect('sales');
        }
    }

transaction_list('sale','Sales Invoice','sale-new','SI-');exit;}
if($route==='purchase'){
    $u=require_login();
    $cid=(int)$u['company_id'];
    $pdo=db();

    // Edit links from the Purchase list must open the existing Purchase editor route.
    if($_SERVER['REQUEST_METHOD']!=='POST' && isset($_GET['edit'])){
        $editId=(int)$_GET['edit'];
        if($editId>0){ redirect('purchase-new?edit='.$editId); }
    }

    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $action=$_POST['action']??'';
        $txId=(int)($_POST['transaction_id']??0);

        try{
            if($action==='delete'){
                if($txId<=0) throw new RuntimeException('Invalid purchase bill.');

                $st=$pdo->prepare('SELECT * FROM transactions WHERE id=? AND company_id=? AND txn_type="purchase" AND deleted_at IS NULL LIMIT 1');
                $st->execute([$txId,$cid]);
                $tx=$st->fetch();
                if(!$tx) throw new RuntimeException('Purchase bill not found for deletion.');

                $pdo->beginTransaction();

                // Move the purchase bill to Recycle Bin. Serial-tracked purchases cannot be deleted after a serial has been sold.
                $chk=$pdo->prepare('SELECT COUNT(*) FROM item_serials WHERE purchase_transaction_id=? AND company_id=? AND status="sold"');$chk->execute([$txId,$cid]);if((int)$chk->fetchColumn()>0)throw new RuntimeException('This purchase cannot be deleted because one or more serial numbers have already been sold.');
                $pdo->prepare('UPDATE item_serials SET status="void" WHERE purchase_transaction_id=? AND company_id=?')->execute([$txId,$cid]);
                $pdo->prepare('UPDATE transactions SET deleted_at=NOW() WHERE id=? AND company_id=?')->execute([$txId,$cid]);

                // Reverse every stock movement created by this purchase.
                $st=$pdo->prepare('SELECT * FROM stock_movements WHERE company_id=? AND transaction_id=?');
                $st->execute([$cid,$txId]);
                $moves=$st->fetchAll();

                foreach($moves as $mv){
                    $reverse=-1*(float)$mv['quantity'];
                    if(abs($reverse)>0.0000001){
                        $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?,?)')
                            ->execute([
                                $cid,
                                $mv['item_id'],
                                null,
                                date('Y-m-d'),
                                $reverse,
                                $mv['unit_price'],
                                'reversal',
                                'Reversal of deleted purchase '.$tx['document_no']
                            ]);
                    }
                }

                // Soft-delete line records when the column exists.
                try{
                    $pdo->prepare('UPDATE transaction_items SET deleted_at=NOW() WHERE transaction_id=?')->execute([$txId]);
                }catch(Throwable $ignore){}

                audit('delete','transaction',$txId,[
                    'type'=>'purchase',
                    'document_no'=>$tx['document_no']??null,
                    'reason'=>'Purchase Bill moved to Recycle Bin'
                ]);

                $pdo->commit();
                flash('success','Purchase Bill moved to Recycle Bin.');
                redirect('purchase');
            }

            if($action==='duplicate'){
                if($txId<=0) throw new RuntimeException('Invalid purchase bill.');

                $st=$pdo->prepare('SELECT * FROM transactions WHERE id=? AND company_id=? AND txn_type="purchase" AND deleted_at IS NULL LIMIT 1');
                $st->execute([$txId,$cid]);
                $tx=$st->fetch();
                if(!$tx) throw new RuntimeException('Purchase bill not found.');

                // Keep duplication inside the existing Purchase Bill flow.
                redirect('purchase?view='.$txId.'&duplicate=1');
            }
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error',$e->getMessage());
            redirect('purchase');
        }
    }

    transaction_list('purchase','Purchase Bills','purchase-new','PB-');
    exit;
}



function report_date_bounds(): array {
    $from=$_GET['from']??date('Y-m-01');
    $to=$_GET['to']??date('Y-m-d');
    $valid=function($d){$x=DateTime::createFromFormat('Y-m-d',$d);return $x&&$x->format('Y-m-d')===$d;};
    if(!$valid($from))$from=date('Y-m-01'); if(!$valid($to))$to=date('Y-m-d');
    if($from>$to){[$from,$to]=[$to,$from];}
    return [$from,$to];
}
function report_rows(string $report,int $cid,string $from,string $to,?int $partyId=null): array {
    $pdo=db();
    return match($report){
        'sales' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,p.name party,t.total,t.paid,t.due,t.status FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="sale" AND t.deleted_at IS NULL AND t.txn_date BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'purchase' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,p.name party,t.total,t.paid,t.due,t.status FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="purchase" AND t.deleted_at IS NULL AND t.txn_date BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'expenses' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,ed.expense_type type,ed.category,t.total,t.paid,t.due FROM transactions t JOIN expense_details ed ON ed.transaction_id=t.id WHERE t.company_id=? AND t.txn_type="expense" AND t.deleted_at IS NULL AND t.txn_date BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'payment_in' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,p.name party,t.total amount,t.status FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="payment_in" AND t.deleted_at IS NULL AND t.txn_date BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'payment_out' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,p.name party,t.total amount,t.status FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="payment_out" AND t.deleted_at IS NULL AND t.txn_date BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'customer_outstanding' => (function()use($pdo,$cid){$q=$pdo->prepare('SELECT p.name party,p.phone,ROUND(p.opening_balance + COALESCE((SELECT SUM(CASE WHEN t.txn_type="sale" THEN t.due WHEN t.txn_type="payment_in" THEN -t.total WHEN t.txn_type="sale_return" THEN -t.total ELSE 0 END) FROM transactions t WHERE t.company_id=p.company_id AND t.party_id=p.id AND t.deleted_at IS NULL AND t.txn_date<=CURDATE()),0),2) outstanding FROM parties p WHERE p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role="customer") ORDER BY outstanding DESC,p.name');$q->execute([$cid]);return $q->fetchAll();})(),
        'supplier_outstanding' => (function()use($pdo,$cid){$q=$pdo->prepare('SELECT p.name party,p.phone,ROUND(p.opening_balance + COALESCE((SELECT SUM(CASE WHEN t.txn_type="purchase" THEN t.due WHEN t.txn_type="payment_out" THEN -t.total WHEN t.txn_type="purchase_return" THEN -t.total ELSE 0 END) FROM transactions t WHERE t.company_id=p.company_id AND t.party_id=p.id AND t.deleted_at IS NULL AND t.txn_date<=CURDATE()),0),2) outstanding FROM parties p WHERE p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role="supplier") ORDER BY outstanding DESC,p.name');$q->execute([$cid]);return $q->fetchAll();})(),
        'party_ledger' => (function()use($pdo,$cid,$from,$to,$partyId){if(!$partyId)return []; $q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,t.txn_type type,t.total,t.paid,t.due FROM transactions t WHERE t.company_id=? AND t.party_id=? AND t.deleted_at IS NULL AND t.txn_date BETWEEN ? AND ? ORDER BY t.txn_date,t.id');$q->execute([$cid,$partyId,$from,$to]);return $q->fetchAll();})(),
        'stock' => (function()use($pdo,$cid){$q=$pdo->prepare('SELECT i.name item,i.code,i.item_type,COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm WHERE sm.company_id=i.company_id AND sm.item_id=i.id
                     AND (sm.transaction_id IS NULL OR EXISTS (
                         SELECT 1 FROM transactions st
                         WHERE st.id=sm.transaction_id
                           AND st.company_id=sm.company_id
                           AND st.deleted_at IS NULL
                     ))),0) stock,i.purchase_price,ROUND(COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm WHERE sm.company_id=i.company_id AND sm.item_id=i.id
                     AND (sm.transaction_id IS NULL OR EXISTS (
                         SELECT 1 FROM transactions st
                         WHERE st.id=sm.transaction_id
                           AND st.company_id=sm.company_id
                           AND st.deleted_at IS NULL
                     ))),0)*i.purchase_price,2) stock_value,i.low_stock_limit FROM items i WHERE i.company_id=? AND i.active=1 ORDER BY i.name');$q->execute([$cid]);return $q->fetchAll();})(),
        'cash_book' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT entry_date date,account_name account,debit,credit,memo FROM ledger_entries WHERE company_id=? AND account_code IN("1000","1100") AND entry_date BETWEEN ? AND ? ORDER BY entry_date,id');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'bank_book' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT entry_date date,account_name account,debit,credit,memo FROM ledger_entries WHERE company_id=? AND (account_code IN("1010","1110") OR account_name LIKE "Bank - %") AND entry_date BETWEEN ? AND ? ORDER BY entry_date,id');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'cheque' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,pl.reference_no cheque_no,pl.cheque_date,pl.amount,t.txn_type type,p.name party,pl.status FROM payment_lines pl JOIN transactions t ON t.id=pl.transaction_id LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND pl.method="cheque" AND t.deleted_at IS NULL AND t.txn_date BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'loan' => (function()use($pdo,$cid){$q=$pdo->prepare('SELECT name,lender,opening_balance,currency_code,active FROM loan_accounts WHERE company_id=? ORDER BY name');$q->execute([$cid]);return $q->fetchAll();})(),
        'day_book' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,t.txn_type type,COALESCE(p.name,"") party,t.total,t.paid,t.due,t.status FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.deleted_at IS NULL AND t.txn_date BETWEEN ? AND ? ORDER BY t.txn_date,t.id');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'general_ledger' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT entry_date date,account_code code,account_name account,debit,credit,memo,transaction_id FROM ledger_entries WHERE company_id=? AND entry_date BETWEEN ? AND ? ORDER BY entry_date,id');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'trial_balance' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT account_code code,account_name account,ROUND(SUM(debit),2) debit,ROUND(SUM(credit),2) credit,ROUND(SUM(debit-credit),2) balance FROM ledger_entries WHERE company_id=? AND entry_date BETWEEN ? AND ? GROUP BY account_code,account_name ORDER BY account_code');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'profit_loss' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT account_code code,account_name account,ROUND(SUM(debit),2) debit,ROUND(SUM(credit),2) credit FROM ledger_entries WHERE company_id=? AND entry_date BETWEEN ? AND ? GROUP BY account_code,account_name ORDER BY account_code');$q->execute([$cid,$from,$to]);$rows=$q->fetchAll();$out=[];foreach($rows as $r){if((string)$r['code'][0]<'4')continue;if(in_array($r['code'],['4000','4200','4300','5100','5200'],true) || (strpos(strtolower($r['account']),'expense')!==false) || strpos(strtolower($r['account']),'rent')!==false || strpos(strtolower($r['account']),'salary')!==false || strpos(strtolower($r['account']),'utilities')!==false || strpos(strtolower($r['account']),'transport')!==false || strpos(strtolower($r['account']),'marketing')!==false)$out[]=$r;}return $out;})(),
        'balance_sheet' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT account_code code,account_name account,ROUND(SUM(debit-credit),2) balance FROM ledger_entries WHERE company_id=? AND entry_date<=? GROUP BY account_code,account_name ORDER BY account_code');$q->execute([$cid,$to]);$rows=$q->fetchAll();$out=[];foreach($rows as $r){$code=(string)$r['code'];if(str_starts_with($code,'1')||str_starts_with($code,'2')||str_starts_with($code,'3'))$out[]=$r;}return $out;})(),
        'accounting_health' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.id,t.txn_date date,t.document_no document,t.txn_type type,ROUND(COALESCE(SUM(le.debit),0),2) debit,ROUND(COALESCE(SUM(le.credit),0),2) credit,ROUND(COALESCE(SUM(le.debit),0)-COALESCE(SUM(le.credit),0),2) difference FROM transactions t LEFT JOIN ledger_entries le ON le.transaction_id=t.id WHERE t.company_id=? AND t.deleted_at IS NULL AND t.txn_date BETWEEN ? AND ? GROUP BY t.id,t.txn_date,t.document_no,t.txn_type HAVING ABS(difference)>0.01 ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        default => []
    };
}
function report_title(string $report): string { return ['sales'=>'Sales Report','purchase'=>'Purchase Report','expenses'=>'Expense Report','payment_in'=>'Payment In Report','payment_out'=>'Payment Out Report','customer_outstanding'=>'Customer Outstanding','supplier_outstanding'=>'Supplier Outstanding','party_ledger'=>'Party Ledger','stock'=>'Stock Report','cash_book'=>'Cash Book','bank_book'=>'Bank Book','cheque'=>'Cheque Report','loan'=>'Loan Report','day_book'=>'Day Book','general_ledger'=>'General Ledger','trial_balance'=>'Trial Balance','profit_loss'=>'Profit & Loss','balance_sheet'=>'Balance Sheet','accounting_health'=>'Accounting Health'][$report]??'Report'; }

if($route==='reports'){
    $u=require_login();$cid=(int)$u['company_id'];[$from,$to]=report_date_bounds();
    $allowed=['sales','purchase','expenses','payment_in','payment_out','customer_outstanding','supplier_outstanding','party_ledger','stock','cash_book','bank_book','cheque','loan','day_book','general_ledger','trial_balance','profit_loss','balance_sheet','accounting_health'];
    $report=$_GET['report']??'sales';if(!in_array($report,$allowed,true))$report='sales';
    $partyId=isset($_GET['party_id'])?(int)$_GET['party_id']:null;
    $rows=report_rows($report,$cid,$from,$to,$partyId);
    $columns=$rows?array_keys($rows[0]):[];
    if(!$columns){
        $columns=match($report){'party_ledger'=>['date','document','type','total','paid','due'],'stock'=>['item','code','item_type','stock','purchase_price','stock_value','low_stock_limit'],'cash_book'=>['date','account','debit','credit','memo'],'bank_book'=>['date','account','debit','credit','memo'],'cheque'=>['date','document','cheque_no','cheque_date','amount','type','party','status'],'loan'=>['name','lender','opening_balance','currency_code','active'],'trial_balance'=>['code','account','debit','credit','balance'],'profit_loss'=>['code','account','debit','credit'],'balance_sheet'=>['code','account','balance'],'accounting_health'=>['date','document','type','debit','credit','difference'],default=>['date','document','party','total','paid','due']};
    }
    if(($_GET['export']??'')==='csv'){
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9_-]+/','-',strtolower($report)).'-'.date('Ymd-His').'.csv"');
        $out=fopen('php://output','w');fputcsv($out,$columns);foreach($rows as $r){$line=[];foreach($columns as $c)$line[]=$r[$c]??'';fputcsv($out,$line);}fclose($out);exit;
    }
    $parties=[];$q=db()->prepare('SELECT p.id,p.name,p.party_type FROM parties p WHERE p.company_id=? ORDER BY p.name');$q->execute([$cid]);$parties=$q->fetchAll();
    $sumTotal=0;$sumPaid=0;$sumDue=0;$sumDebit=0;$sumCredit=0;foreach($rows as $r){$sumTotal+=(float)($r['total']??$r['amount']??0);$sumPaid+=(float)($r['paid']??0);$sumDue+=(float)($r['due']??0);$sumDebit+=(float)($r['debit']??0);$sumCredit+=(float)($r['credit']??0);}
    page_start('Reports');
    ?><div class="page-title"><div><h1>Reports</h1><p><?=e(report_title($report))?> · <?=e($from)?> to <?=e($to)?></p></div><div class="top-actions"><a class="btn" href="<?=e(url('reports?report='.urlencode($report).'&from='.$from.'&to='.$to.'&party_id='.(int)($partyId??0).'&export=csv'))?>">Export CSV</a><button class="btn primary" onclick="window.print()">Print</button></div></div>
    <div class="panel report-filters"><form method="get" class="filterbar"><input type="hidden" name="report" value="<?=e($report)?>"><label>Report</label><select name="report" onchange="this.form.submit()"><?php foreach($allowed as $r):?><option value="<?=e($r)?>" <?=$r===$report?'selected':''?>><?=e(report_title($r))?></option><?php endforeach;?></select><label>From</label><input type="date" name="from" value="<?=e($from)?>"><label>To</label><input type="date" name="to" value="<?=e($to)?>"><?php if($report==='party_ledger'):?><label>Party</label><select name="party_id"><option value="">Select party</option><?php foreach($parties as $p):?><option value="<?=$p['id']?>" <?=$partyId===(int)$p['id']?'selected':''?>><?=e($p['name'])?> · <?=e(ucfirst($p['party_type']))?></option><?php endforeach;?></select><?php endif;?><button class="btn primary">Apply</button></form></div>
    <div class="cards-top" style="margin-top:14px"><div class="metric-card"><div class="label">Rows</div><div class="value"><?=count($rows)?></div></div><div class="metric-card"><div class="label">Summary</div><div class="value" style="font-size:18px"><?php if($report==='accounting_health'):?><?php if(!$rows):?><span class="health-ok">✓ All selected transactions are balanced</span><?php else:?><span class="health-bad">⚠ <?=count($rows)?> unbalanced transaction(s)</span><?php endif;?><?php elseif(in_array($report,['general_ledger','trial_balance','cash_book','bank_book'],true)):?>Dr <?=money($sumDebit)?> · Cr <?=money($sumCredit)?><?php elseif($report==='stock'):?>Stock <?=qty((float)$sumTotal)?><?php else:?>Total <?=money($sumTotal)?><?php endif;?></div></div></div>
    <div class="panel report-panel" style="margin-top:14px"><div class="panel-head"><h2><?=e(strtoupper(report_title($report)))?></h2><span class="subtle"><?=e($from)?> → <?=e($to)?></span></div><div class="table-wrap"><table><thead><tr><?php foreach($columns as $c):?><th><?=e(strtoupper(str_replace('_',' ',$c)))?></th><?php endforeach;?></tr></thead><tbody><?php if($rows):foreach($rows as $r):?><tr><?php foreach($columns as $c):$v=$r[$c]??'';if(is_numeric($v)&&in_array($c,['total','paid','due','amount','debit','credit','balance','purchase_price','stock_value','opening_balance'],true))$v=money((float)$v);?><td><?=e((string)$v)?></td><?php endforeach;?></tr><?php endforeach;else:?><tr><td colspan="<?=count($columns)?>" class="subtle">No data found for the selected report/filter.</td></tr><?php endif;?></tbody></table></div></div><?php page_end();exit;
}

function placeholder_page(string $title,string $desc): void { page_start($title);?><div class="page-title"><div><h1><?=e($title)?></h1><p><?=e($desc)?></p></div></div><div class="panel"><div style="padding:30px;text-align:center"><h2><?=e($title)?></h2><p class="subtle">This module is scaffolded in v2. The next development pass will add its full workflow and reports.</p></div></div><?php page_end(); }

if($route==='payment-out'){
    $cid=(int)$u['company_id']; $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        try{
            $party=(int)($_POST['party_id']??0);
            if($party<=0)throw new RuntimeException('Supplier is required.');
            $st=db()->prepare("SELECT p.id,p.name FROM parties p WHERE p.id=? AND p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role='supplier') LIMIT 1");$st->execute([$party,$cid]);$pr=$st->fetch();
            if(!$pr)throw new RuntimeException('Invalid supplier.');
            $methods=$_POST['pay_method']??[];$amounts=$_POST['pay_amount']??[];$accounts=$_POST['pay_account']??[];$refs=$_POST['pay_ref']??($_POST['pay_reference']??[]);$cheqDates=$_POST['pay_cheque_date']??[];
            $rows=[];$paidOut=0;
            foreach($methods as $i=>$rawMethod){$a=max(0,(float)($amounts[$i]??0));if($a<=0)continue;[$m,$acct]=normalize_payment_method($pdo,$cid,(string)$rawMethod,trim($accounts[$i]??''));$ref=trim($refs[$i]??'');$cd=$cheqDates[$i]??null;if($m==='cheque'&&$ref==='')throw new RuntimeException('Cheque number is required.');$rows[]=[$m,$acct?:null,$ref?:null,$cd?:null,$a];$paidOut+=$a;}
            if($paidOut<=0)throw new RuntimeException('Enter paid amount.');
            $st=db()->prepare('SELECT COALESCE(SUM(CASE WHEN txn_type="purchase" THEN due WHEN txn_type="payment_out" THEN -total ELSE 0 END),0) FROM transactions WHERE company_id=? AND party_id=? AND deleted_at IS NULL');$st->execute([$cid,$party]);$outstanding=max(0,(float)$st->fetchColumn());
            if($paidOut>$outstanding+0.01)throw new RuntimeException('Paid amount cannot be greater than the supplier outstanding due ('.money($outstanding).').');
            $date=$_POST['txn_date']??date('Y-m-d');$doc=trim($_POST['document_no']??'');$pdo=db();$pdo->beginTransaction();
            if($doc==='')$doc=next_document_in_transaction($pdo,$cid,'payment_out','PO-');
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$party,'payment_out',$doc,$date,null,$paidOut,$paidOut,$paidOut,0,$u['currency_code'],'final',trim($_POST['notes']??''),$u['id']]);
            $tid=(int)$pdo->lastInsertId();
            $pl=$pdo->prepare('INSERT INTO payment_lines(transaction_id,method,account_name,reference_no,cheque_date,amount,status) VALUES(?,?,?,?,?,?,?)');
            $ledger=[];
            foreach($rows as [$m,$acct,$ref,$cd,$a]){
                $pl->execute([$tid,$m,$acct,$ref,$cd,$a,'completed']);
                [$code,$name]=payment_account_code($m,$acct);
                $ledger[]=[$code,$name,0,$a,$doc];
            }
            $ledger[]=['2100','Accounts Payable',$paidOut,0,$doc];
            post_ledger($pdo,$cid,$tid,$date,$ledger);
            audit('create','transaction',$tid,['type'=>'payment_out','document'=>$doc,'total'=>$paidOut,'party_id'=>$party]);
            $pdo->commit();flash('success','Payment-Out '.$doc.' saved successfully.');redirect('payment-out?view='.$tid);
        }catch(Throwable $e){if($pdo&&$pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect('payment-out');}
    }
    $partyRows=db()->prepare('SELECT p.id,p.name,p.phone,COALESCE((SELECT SUM(CASE WHEN t.txn_type="purchase" THEN t.due WHEN t.txn_type="payment_out" THEN -t.total ELSE 0 END) FROM transactions t WHERE t.company_id=? AND t.party_id=p.id AND t.deleted_at IS NULL),0) outstanding FROM parties p WHERE p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role="supplier") ORDER BY p.name');
    $partyRows->execute([$cid,$cid]);$parties=$partyRows->fetchAll();
    $banks=db()->prepare('SELECT id,name,bank_name,account_number FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY name');$banks->execute([$cid]);$bankRows=$banks->fetchAll();
    page_start('Payment Out');
    if(isset($_GET['view'])){
        $tid=(int)$_GET['view'];$st=db()->prepare('SELECT t.*,p.name party_name,p.phone party_phone FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type="payment_out" LIMIT 1');$st->execute([$tid,$cid]);$tx=$st->fetch();
        if($tx){$ps=db()->prepare('SELECT * FROM payment_lines WHERE transaction_id=? ORDER BY id');$ps->execute([$tid]);$payments=$ps->fetchAll();
        ?><div class="page-title"><div><h1>Payment-Out <?=e($tx['document_no'])?></h1><p><?=e($tx['txn_date'])?> · <?=e($tx['party_name'])?></p></div><div><button class="btn" onclick="window.print()">Print</button><a class="btn primary" href="<?=e(url('payment-out'))?>">+ New Payment</a></div></div>
        <div class="cards-top"><div class="metric-card"><div class="label">Paid</div><div class="value"><?=money((float)$tx['total'])?></div></div><div class="metric-card"><div class="label">Supplier</div><div class="value" style="font-size:20px"><?=e($tx['party_name'])?></div></div></div>
        <div class="panel"><div class="panel-head"><h2>Payment Details</h2></div><div class="table-wrap"><table><thead><tr><th>METHOD</th><th>ACCOUNT</th><th>REFERENCE</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($payments as $r):?><tr><td><?=e(ucwords(str_replace('_',' ',$r['method'])))?></td><td><?=e($r['account_name']??'-')?></td><td><?=e($r['reference_no']??'-')?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div></div><?php page_end();exit;}
    }
    $st=db()->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="payment_out" AND t.deleted_at IS NULL ORDER BY t.id DESC LIMIT 50');$st->execute([$cid]);$rows=$st->fetchAll();
    ?><div class="page-title"><div><h1>Payment Out</h1><p>Make payments to suppliers</p></div><a class="btn primary" href="#newPayment">⊕ Add Payment-Out</a></div>
    <div class="panel" id="newPayment"><div class="panel-head"><h2>New Payment-Out</h2><span class="subtle">Multiple payment methods allowed</span></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="entry-top"><div><div class="form-group"><label>Supplier*</label><?php party_search_field('Supplier','supplier',0,'',''); ?></div><div class="subtle" id="partyDue" style="margin-top:6px">Select a supplier to see outstanding due.</div></div><div><div class="form-group"><label>Payment Number</label><input name="document_no" placeholder="Auto: PO-000001"></div><div class="form-group"><label>Date*</label><input type="date" name="txn_date" value="<?=date('Y-m-d')?>" required></div></div><div class="entry-right"><div class="right-card"><div class="title">CURRENT DUE</div><div class="value" id="currentDue">৳0.00</div></div></div></div>
    <div class="payment-box"><div class="panel-head"><h2>Payment Methods</h2><span class="subtle">Split one payment across multiple methods</span></div><div id="paymentRows"><div class="payment-line"><select name="pay_method[]" onchange="togglePaymentFields(this)"><?=payment_select_options($bankRows,'cash','')?></select><input type="date" name="pay_cheque_date[]" class="pay-cheque-date" style="display:none"><input name="pay_ref[]" placeholder="Reference / Cheque No."><input type="number" min="0" step="0.01" name="pay_amount[]" value="0" placeholder="Amount"></div></div><button type="button" class="btn" onclick="addPayment()">+ Add Payment</button></div><div class="grid2" style="margin-top:12px"><div class="form-group"><label>Notes</label><textarea name="notes" rows="3" placeholder="Add description"></textarea></div><div class="metric-card"><div class="label">Total Paid</div><div class="value" id="receivedPreview">৳0.00</div></div></div><div class="form-footer" style="margin:0 -16px -16px"><button type="button" class="btn" onclick="window.print()">Print / Preview</button><button class="btn primary">Save Payment-Out</button></div></form></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>TRANSACTIONS</h2><input class="input" style="max-width:240px" placeholder="Search"></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>PAYMENT NO.</th><th>PARTY NAME</th><th>PAYMENT TYPE</th><th>AMOUNT</th><th>ACTION</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td><td><?=e($r['document_no'])?></td><td><?=e($r['party_name']??'')?></td><td>Multiple / See receipt</td><td><?=money((float)$r['total'])?></td><td class="action"><a class="btn" href="<?=e(url('payment-out?view='.(int)$r['id']))?>">View</a></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6" class="subtle">No payment-out transactions yet.</td></tr><?php endif;?></tbody></table></div></div>
    <script>document.addEventListener('DOMContentLoaded',()=>{const party=document.getElementById('paymentParty'),due=document.getElementById('currentDue'),note=document.getElementById('partyDue');function upd(){const d=parseFloat(party?.dataset.due||0);due.textContent='৳'+d.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});note.textContent=party?.value?'Outstanding due: '+due.textContent:'Select a supplier to see outstanding due.';}party?.addEventListener('change',upd);document.addEventListener('party-selected',upd);function sum(){let t=0;document.querySelectorAll('input[name="pay_amount[]"]').forEach(i=>t+=parseFloat(i.value||0));const x=document.getElementById('receivedPreview');if(x)x.textContent='৳'+t.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}document.querySelectorAll('input[name="pay_amount[]"]').forEach(i=>i.addEventListener('input',sum));window.addEventListener('input',e=>{if(e.target.matches('input[name="pay_amount[]"]'))sum();});upd();sum();});</script><?php page_end();exit;
}


function return_module(string $returnType): void {
    global $u;
    $cid=(int)$u['company_id'];
    $isSale=$returnType==='sale_return';
    $baseType=$isSale?'sale':'purchase';
    $title=$isSale?'Sale Return / Cr. Note':'Purchase Return / Dr. Note';
    $prefix=$isSale?'SR-':'PR-';
    $partyLabel=$isSale?'Customer':'Supplier';
    $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        try{
            $sourceId=(int)($_POST['source_id']??0);
            $date=$_POST['txn_date']??date('Y-m-d');
            $notes=trim($_POST['notes']??'');
            $st=$pdo->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL');
            $st->execute([$sourceId,$cid,$baseType]); $source=$st->fetch();
            if(!$source) throw new RuntimeException('Select a valid original '.strtolower($baseType).'.');
            $its=$pdo->prepare('SELECT ti.*,i.name item_name,i.item_type FROM transaction_items ti JOIN items i ON i.id=ti.item_id WHERE ti.transaction_id=? ORDER BY ti.id');
            $its->execute([$sourceId]); $sourceItems=$its->fetchAll();
            if(!$sourceItems) throw new RuntimeException('The original transaction has no items.');
            $returnQty=$_POST['return_qty']??[]; $rows=[]; $subtotal=0; $itemDiscount=0;
            foreach($sourceItems as $r){
                $qid=(string)$r['item_id']; $q=max(0,(float)($returnQty[$qid]??0));
                if($q<=0) continue;
                $available=(float)$r['qty'];
                if($q>$available+0.0001) throw new RuntimeException('Return quantity for '. $r['item_name'] .' cannot exceed the original quantity.');
                 $unit=(float)$r['unit_price'];
                $discPerUnit=$available>0 ? ((float)$r['discount']/$available) : 0;
                $lineGross=$q*$unit;
                $lineDisc=$q*$discPerUnit;
                $amount=max(0,$lineGross-$lineDisc);
                $rows[]=[$r['item_id'],$q,$unit,$lineDisc,$amount]; $subtotal+=$lineGross; $itemDiscount+=$lineDisc;
            }
            if(!$rows) throw new RuntimeException('Enter a return quantity for at least one item.');
            $tax=0; $invoiceDiscount=0; $directExpense=0; $total=max(0,$subtotal-$itemDiscount);
            $pdo->beginTransaction();
            $doc=trim($_POST['document_no']??'');
            if($doc==='') $doc=next_document_in_transaction($pdo,$cid,$returnType,$prefix);
            $paid=0; $due=$total;
            $status='final';
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$cid,$source['party_id'],$returnType,$doc,$date,null,$subtotal,$itemDiscount,$invoiceDiscount,$tax,$directExpense,$total,$paid,$due,$u['currency_code'],$status,$notes,$u['id']]);
            $tid=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,amount) VALUES(?,?,?,?,?,?)');
            foreach($rows as [$itemId,$q,$unit,$disc,$amount]){
                $ins->execute([$tid,$itemId,$q,$unit,$disc,$amount]);
                $itemTypeSt=$pdo->prepare('SELECT item_type FROM items WHERE id=? AND company_id=?');
                $itemTypeSt->execute([$itemId,$cid]);
                $itemType=(string)$itemTypeSt->fetchColumn();
                if ($itemType==='product') {
                    $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,movement_type,note) VALUES(?,?,?,?,?,?,?)')
                        ->execute([$cid,$itemId,$tid,$date,$isSale?$q:-$q,$isSale?'sale_return':'purchase_return',$doc]);
                }
            }
            $pdo->prepare('INSERT INTO transaction_links(company_id,from_transaction_id,to_transaction_id,relation_type,quantity) VALUES(?,?,?,?,NULL)')
                ->execute([$cid,$sourceId,$tid,$isSale?'sale_to_return':'purchase_to_return']);
            if($isSale){
                $ledger=[['4200','Sales Returns',$total,0,$doc],['1200','Accounts Receivable',0,$total,$doc]];
                $inventoryValue=0;
                foreach($rows as [$itemId,$q]){
                    $x=$pdo->prepare('SELECT purchase_price FROM items WHERE id=? AND company_id=?');$x->execute([$itemId,$cid]);$cost=(float)$x->fetchColumn();$inventoryValue += $q*$cost;
                }
                if($inventoryValue>0){$ledger[]=['1300','Inventory',$inventoryValue,0,$doc];$ledger[]=['5100','Cost of Goods Sold',0,$inventoryValue,$doc];}
            }else{
                $ledger=[['2100','Accounts Payable',$total,0,$doc],['1300','Inventory',0,$total,$doc]];
            }
            post_ledger($pdo,$cid,$tid,$date,$ledger);
            audit('create','transaction',$tid,['type'=>$returnType,'document'=>$doc,'source_id'=>$sourceId,'total'=>$total]);
            $pdo->commit(); flash('success',$title.' '.$doc.' saved successfully.'); redirect($returnType==='sale_return'?'sale-return':'purchase-return');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect($returnType==='sale_return'?'sale-return':'purchase-return');}
    }
    $sourceSt=$pdo->prepare('SELECT t.id,t.document_no,t.txn_date,t.total,t.due,t.party_id,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL ORDER BY t.id DESC LIMIT 100');
    $sourceSt->execute([$cid,$baseType]);$sources=$sourceSt->fetchAll();
    page_start($title);
    if(isset($_GET['view'])){
        $tid=(int)$_GET['view'];
        $st=$pdo->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type=?');$st->execute([$tid,$cid,$returnType]);$tx=$st->fetch();
        if($tx){$it=$pdo->prepare('SELECT ti.*,i.name item_name FROM transaction_items ti JOIN items i ON i.id=ti.item_id WHERE ti.transaction_id=?');$it->execute([$tid]);$lines=$it->fetchAll();$ln=$pdo->prepare('SELECT * FROM ledger_entries WHERE transaction_id=? ORDER BY id');$ln->execute([$tid]);$led=$ln->fetchAll();
        ?><div class="page-title"><div><h1><?=e($title)?> <?=e($tx['document_no'])?></h1><p><?=e($tx['party_name']??'')?> · <?=e($tx['txn_date'])?></p></div><div><button class="btn" onclick="window.print()">Print</button><a class="btn primary" href="<?=e(url($returnType==='sale_return'?'sale-return':'purchase-return'))?>">+ New Return</a></div></div>
        <div class="grid3"><div class="metric-card"><div class="label">Total</div><div class="value"><?=money((float)$tx['total'])?></div></div><div class="metric-card"><div class="label">Reference</div><div class="value" style="font-size:20px"><?=e($tx['document_no'])?></div></div><div class="metric-card"><div class="label">Party</div><div class="value" style="font-size:20px"><?=e($tx['party_name']??'')?></div></div></div>
        <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>RETURN ITEMS</h2></div><div class="table-wrap"><table><thead><tr><th>ITEM</th><th>QTY</th><th>PRICE/UNIT</th><th>DISCOUNT</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($lines as $r):?><tr><td><?=e($r['item_name'])?></td><td><?=qty((float)$r['qty'])?></td><td><?=money((float)$r['unit_price'])?></td><td><?=money((float)$r['discount'])?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div></div>
        <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>LEDGER</h2></div><div class="table-wrap"><table><thead><tr><th>ACCOUNT</th><th>DEBIT</th><th>CREDIT</th></tr></thead><tbody><?php foreach($led as $r):?><tr><td><?=e($r['account_name'])?></td><td><?=money((float)$r['debit'])?></td><td><?=money((float)$r['credit'])?></td></tr><?php endforeach;?></tbody></table></div></div>
        <?php page_end(); exit;}
    }
    $addTitle=$isSale?'Add Sale Return':'Add Purchase Return';
    ?><div class="page-title"><div><h1><?=e($title)?></h1><p><?=e($partyLabel)?> return against an original <?=e($baseType)?></p></div><button class="btn primary" onclick="document.getElementById('returnForm').scrollIntoView({behavior:'smooth'})">⊕ <?=$addTitle?></button></div>
    <div class="panel standard-entry-form" id="returnForm"><div class="panel-head"><h2><?=$addTitle?></h2><span class="subtle">Partial or full return supported · Return amount updates automatically</span></div>
    <form method="post" class="standard-return-form"><input type="hidden" name="_csrf" value="<?=csrf_token()?>">
      <div class="entry-top standard-entry-top return-entry-top"><div class="form-group"><label>Original <?=ucfirst($baseType)?>*</label><select name="source_id" id="returnSource" required><option value="">Select original <?=e($baseType)?></option><?php foreach($sources as $s):?><option value="<?=$s['id']?>" data-party="<?=e($s['party_name']??'')?>" data-total="<?=e((string)$s['total'])?>"><?=e($s['document_no'])?> · <?=e($s['party_name']??'')?> · <?=e(date('d/m/Y',strtotime($s['txn_date'])))?> · <?=money((float)$s['total'])?></option><?php endforeach;?></select></div><div class="form-group"><label>Return Number</label><input name="document_no" placeholder="Auto: <?=$prefix?>000001"></div><div class="form-group"><label>Return Date*</label><input type="date" name="txn_date" value="<?=date('Y-m-d')?>" required></div></div>
      <div class="return-meta-strip"><span class="subtle" id="sourceParty">Select an original transaction.</span><strong id="sourceTotal">৳0.00</strong></div>
      <div class="table-wrap"><table><thead><tr><th>ITEM</th><th>ORIGINAL QTY</th><th>PRICE/UNIT</th><th>RETURN QTY</th><th>RETURN AMOUNT</th></tr></thead><tbody id="returnItems"><tr><td colspan="5" class="subtle">Select an original transaction to load its items.</td></tr></tbody></table></div>
      <div class="grid2" style="margin-top:12px"><div class="form-group"><label>Notes</label><textarea name="notes" rows="3" placeholder="Reason for return"></textarea></div><div class="metric-card"><div class="label">Return Total</div><div class="value" id="returnTotal">৳0.00</div></div></div>
      <div class="form-footer" style="margin:0 -16px -16px"><a class="btn" href="<?=e(url($returnType))?>">Cancel</a><button class="btn primary">Save <?=$isSale?'Credit Note':'Debit Note'?></button></div>
    </form></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>TRANSACTIONS</h2><input class="input" style="max-width:240px" placeholder="Search"></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>RETURN NO.</th><th>PARTY</th><th>ORIGINAL</th><th>TOTAL</th><th>ACTION</th></tr></thead><tbody><?php $rs=$pdo->prepare('SELECT r.*,p.name party_name,s.document_no source_doc FROM transactions r LEFT JOIN parties p ON p.id=r.party_id LEFT JOIN transaction_links l ON l.to_transaction_id=r.id AND l.relation_type=? LEFT JOIN transactions s ON s.id=l.from_transaction_id WHERE r.company_id=? AND r.txn_type=? AND r.deleted_at IS NULL ORDER BY r.id DESC LIMIT 100');$rs->execute([$isSale?'sale_to_return':'purchase_to_return',$cid,$returnType]);foreach($rs as $r):?><tr><td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td><td><?=e($r['document_no'])?></td><td><?=e($r['party_name']??'')?></td><td><?=e($r['source_doc']??'-')?></td><td><?=money((float)$r['total'])?></td><td><a class="btn" href="<?=e(url($returnType.'?view='.(int)$r['id']))?>">View</a></td></tr><?php endforeach;if(!$rs):?><tr><td colspan="6" class="subtle">No returns yet.</td></tr><?php endif;?></tbody></table></div></div>
    <script>
    const source=document.getElementById('returnSource');
    const itemsBox=document.getElementById('returnItems');
    const totalBox=document.getElementById('returnTotal'); const sourceTotal=document.getElementById('sourceTotal'); const sourceParty=document.getElementById('sourceParty');
    function fmt(n){return '৳'+Number(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
    function returnRecalc(){
      let t=0;
      document.querySelectorAll('.return-qty').forEach(i=>{
        const max=Number(i.dataset.max||0);
        let q=Number(i.value||0);
        if(!Number.isFinite(q)) q=0;
        q=Math.max(0,Math.min(max,q));
        const unit=Number(i.dataset.unit||0);
        const a=Math.round((q*unit + Number.EPSILON)*100)/100;
        t+=a;
        const cell=i.closest('tr')?.querySelector('.return-amount');
        if(cell) cell.textContent=fmt(a);
      });
      t=Math.round((t + Number.EPSILON)*100)/100;
      if(totalBox) totalBox.textContent=fmt(t);
    }
    async function loadReturnItems(){
      const id=source?.value;
      const opt=source?.selectedOptions[0];
      sourceTotal.textContent=fmt(opt?.dataset.total||0);
      sourceParty.textContent=opt?.dataset.party?('Party: '+opt.dataset.party):'Select an original transaction.';
      if(!id){itemsBox.innerHTML='<tr><td colspan="5" class="subtle">Select an original transaction to load its items.</td></tr>';returnRecalc();return;}
      try{
        const r=await fetch('<?=e(url('return-items'))?>?source_id='+encodeURIComponent(id),{headers:{'Accept':'application/json'}});
        if(!r.ok) throw new Error('Request failed');
        const data=await r.json();
        itemsBox.innerHTML=data.map(x=>{
          const qty=Number(x.qty||0);
          const gross=Number(x.unit_price||0);
          const lineAmount=Number(x.amount||0);
          const sourceUnit=Number(x.unit_price||0);
          const netUnit=qty>0 && lineAmount>0 ? lineAmount/qty : sourceUnit;
          return `<tr>
          <td>${x.name}</td>
          <td>${qty.toLocaleString('en-US',{minimumFractionDigits:3,maximumFractionDigits:3})}</td>
          <td>${fmt(gross)}</td>
          <td><input type="number" min="0" max="${qty}" step="0.01" name="return_qty[${x.item_id}]" value="0" class="return-qty" data-unit="${netUnit}" data-max="${qty}" inputmode="decimal" oninput="returnRecalc()"></td>
          <td class="return-amount">${fmt(0)}</td>
        </tr>`;
        }).join('')||'<tr><td colspan="5" class="subtle">No items.</td></tr>';
        returnRecalc();
      }catch(e){itemsBox.innerHTML='<tr><td colspan="5" class="subtle">Could not load items.</td></tr>';returnRecalc();}
    }
    itemsBox?.addEventListener('input',e=>{if(e.target.matches('.return-qty'))returnRecalc();});
    itemsBox?.addEventListener('change',e=>{if(e.target.matches('.return-qty'))returnRecalc();});
    source?.addEventListener('change',loadReturnItems);
    </script><?php page_end(); exit;
}

if($route==='return-items'){
    header('Content-Type: application/json; charset=utf-8');
    $cid=(int)$u['company_id'];$sourceId=(int)($_GET['source_id']??0);
    $st=db()->prepare('SELECT ti.item_id,i.name,ti.qty,ti.unit_price,ti.discount,ti.amount,CASE WHEN ti.qty=0 THEN 0 ELSE ti.discount/ti.qty END discount_per_unit,CASE WHEN ti.qty=0 THEN 0 ELSE ti.amount/ti.qty END net_unit_price FROM transaction_items ti JOIN items i ON i.id=ti.item_id JOIN transactions t ON t.id=ti.transaction_id WHERE ti.transaction_id=? AND t.company_id=? ORDER BY ti.id');$st->execute([$sourceId,$cid]);echo json_encode(array_map(function($r){$r['qty']=(float)$r['qty'];$r['unit_price']=(float)$r['unit_price'];$r['discount']=(float)$r['discount'];$r['amount']=(float)$r['amount'];$r['discount_per_unit']=(float)$r['discount_per_unit'];$r['net_unit_price']=(float)$r['net_unit_price'];return $r;}, $st->fetchAll()),JSON_UNESCAPED_UNICODE);exit;
}

if($route==='sale-return'){return_module('sale_return');}
if($route==='purchase-return'){return_module('purchase_return');}


function delivery_challan_new(): void {
    global $u;
    $cid=(int)$u['company_id']; $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_delivery_challan'){
        check_csrf();
        try{
            $party=(int)($_POST['party_id']??0);
            $st=$pdo->prepare('SELECT p.id FROM parties p WHERE p.id=? AND p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role="customer")');
            $st->execute([$party,$cid]); $pr=$st->fetch();
            if(!$pr) throw new RuntimeException('Customer is required.');
            $txnDate=$_POST['txn_date']??date('Y-m-d'); $dueDate=$_POST['due_date']?:null; $notes=trim($_POST['notes']??'');
            $itemIds=$_POST['item_id']??[]; $qtys=$_POST['qty']??[]; $prices=$_POST['price']??[]; $discs=$_POST['discount']??[];
            $rows=[]; $subtotal=0; $itemDisc=0;
            foreach($itemIds as $i=>$iid){
                $iid=(int)$iid; $q=(float)($qtys[$i]??0); $price=(float)($prices[$i]??0); $disc=max(0,(float)($discs[$i]??0));
                if($iid<=0 || $q<=0) continue;
                $itSt=$pdo->prepare('SELECT * FROM items WHERE id=? AND company_id=? AND active=1'); $itSt->execute([$iid,$cid]); $it=$itSt->fetch();
                if(!$it) throw new RuntimeException('Invalid item selected.');
                $gross=$q*$price; if($disc>$gross)$disc=$gross; $rows[]=[$iid,$q,$price,$disc,$it]; $subtotal+=$gross; $itemDisc+=$disc;
            }
            if(!$rows) throw new RuntimeException('Add at least one item.');
            $invDisc=max(0,(float)($_POST['invoice_discount']??0)); $tax=max(0,(float)($_POST['tax']??0)); $direct=max(0,(float)($_POST['direct_expense']??0));
            $after=max(0,$subtotal-$itemDisc); if($invDisc>$after)$invDisc=$after; $total=round(max(0,$after-$invDisc+$tax+$direct),2);
            $pdo->beginTransaction();
            $doc=trim($_POST['document_no']??''); if($doc==='')$doc=next_document_in_transaction($pdo,$cid,'delivery_challan','DC-');
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$cid,$party,'delivery_challan',$doc,$txnDate,$dueDate,$subtotal,$itemDisc,$invDisc,$tax,$direct,$total,0,$total,$u['currency_code'],'open',$notes,$u['id']]);
            $tid=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');
            foreach($rows as [$iid,$q,$price,$disc,$it]) $ins->execute([$tid,$iid,$q,$price,$disc,0,max(0,$q*$price-$disc)]);
            audit('create','transaction',$tid,['type'=>'delivery_challan','document'=>$doc,'total'=>$total]); $pdo->commit();
            flash('success','Delivery Challan '.$doc.' saved successfully.'); redirect('delivery-challans');
        }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); flash('error',$e->getMessage()); redirect('delivery-challan-new'); }
    }
    page_start('Add Delivery Challan');
    if(isset($_GET['view'])){
        $tid=(int)$_GET['view'];
        $st=$pdo->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL');
        $st->execute([$tid,$cid]); $tx=$st->fetch();
        if(!$tx){ flash('error','Delivery Challan not found.'); redirect('delivery-challans'); }
        $lines=$pdo->prepare('SELECT ti.*,i.name item_name FROM transaction_items ti JOIN items i ON i.id=ti.item_id WHERE ti.transaction_id=? ORDER BY ti.id');$lines->execute([$tid]);$lines=$lines->fetchAll();
        $lk=$pdo->prepare('SELECT tl.to_transaction_id FROM transaction_links tl WHERE tl.company_id=? AND tl.from_transaction_id=? AND tl.relation_type="challan_to_sale" LIMIT 1');$lk->execute([$cid,$tid]);$saleId=(int)($lk->fetchColumn()?:0);
        ?>
        <div class="page-title"><div><h1>Delivery Challan <?=e($tx['document_no'])?></h1><p><?=e($tx['party_name']??'')?> · <?=e($tx['txn_date'])?></p></div><div><a class="btn" href="<?=e(url('delivery-challans'))?>">Back</a><?php if(!$saleId):?><a class="btn primary" href="<?=e(url('delivery-challans?convert='.(int)$tx['id']))?>" onclick="return confirm('Convert this Delivery Challan to Sale?')">Convert to Sale</a><?php else:?><a class="btn primary" href="<?=e(url('sales?view='.$saleId))?>">View Sale</a><?php endif;?></div></div>
        <div class="cards-top"><div class="metric-card"><div class="label">Total</div><div class="value"><?=money((float)$tx['total'])?></div></div><div class="metric-card"><div class="label">Party</div><div class="value" style="font-size:20px"><?=e($tx['party_name']??'')?></div></div><div class="metric-card"><div class="label">Status</div><div class="value" style="font-size:20px"><?=e($saleId?'Converted':'Open')?></div></div></div>
        <div class="panel"><div class="panel-head"><h2>ITEMS</h2></div><div class="table-wrap"><table><thead><tr><th>#</th><th>ITEM</th><th>QTY</th><th>PRICE/UNIT</th><th>DISCOUNT</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($lines as $i=>$r):?><tr><td><?=$i+1?></td><td><?=e($r['item_name'])?></td><td><?=qty((float)$r['qty'])?></td><td><?=money((float)$r['unit_price'])?></td><td><?=money((float)$r['discount'])?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div></div>

<script>
/* v108: repair the live expense row DOM and bind the exact row controls. */
(function(){
  function repairRows(){
    const body=document.getElementById('expenseItemRows');
    if(!body) return;
    body.querySelectorAll('tr.v107-expense-row').forEach(function(row){
      const cells=row.children;
      while(row.children.length<5){ row.appendChild(document.createElement('td')); }
      const itemCell=row.children[1];
      const qtyCell=row.children[2];
      const priceCell=row.children[3];
      const amountCell=row.children[4];
      itemCell.className=itemCell.className||'v107-item-cell';
      qtyCell.className='v107-qty-cell';
      priceCell.className='v107-price-cell';
      amountCell.className='v107-amount-cell';
      if(!qtyCell.querySelector('input')){
        qtyCell.innerHTML='<input class="v107-qty v94-qty" name="item_qty[]" type="number" min="0.001" step="0.001" value="1">';
      }
      if(!priceCell.querySelector('input')){
        priceCell.innerHTML='<input class="v107-price v94-price" name="item_price[]" type="number" min="0" step="0.01" value="0">';
      }
      if(!amountCell.querySelector('.v107-amount')){
        amountCell.innerHTML='<span class="v107-amount v94-amount">৳0.00</span>';
      }
    });
  }
  function recalc(){
    let total=0;
    document.querySelectorAll('#expenseItemRows tr.v107-expense-row').forEach(function(row){
      const q=Math.max(0,parseFloat(row.querySelector('.v107-qty,.v94-qty')?.value||0));
      const p=Math.max(0,parseFloat(row.querySelector('.v107-price,.v94-price')?.value||0));
      const a=Math.round(q*p*100)/100;
      total+=a;
      const out=row.querySelector('.v107-amount,.v94-amount');
      if(out) out.textContent='৳'+a.toFixed(2);
    });
    total=Math.round(total*100)/100;
    const amount=document.getElementById('expenseAmount'); if(amount) amount.value=total.toFixed(2);
    const pay=document.getElementById('expensePayAmount'); if(pay) pay.value=total.toFixed(2);
    const grand=document.getElementById('expenseGrandTotal'); if(grand) grand.textContent='৳'+total.toFixed(2);
    const preview=document.getElementById('expenseTotalPreview'); if(preview) preview.textContent='৳'+total.toFixed(2);
  }
  window.v94Recalc=recalc;
  window.v94AddRow=function(){
    const body=document.getElementById('expenseItemRows');
    const first=body?.querySelector('tr.v107-expense-row');
    if(!body||!first) return;
    const clone=first.cloneNode(true);
    const sel=clone.querySelector('.v107-item-select,.v94-item-select');
    if(sel){ sel.value=''; sel.dataset.selected=''; }
    const q=clone.querySelector('.v107-qty,.v94-qty'); if(q) q.value='1';
    const p=clone.querySelector('.v107-price,.v94-price'); if(p) p.value='0';
    const a=clone.querySelector('.v107-amount,.v94-amount'); if(a) a.textContent='৳0.00';
    body.appendChild(clone);
    if(sel && typeof window.v98PopulateItemSelect==='function') window.v98PopulateItemSelect(sel);
    recalc();
  };
  function bind(){
    repairRows();
    document.querySelectorAll('#expenseItemRows').forEach(function(body){
      body.addEventListener('input',function(e){
        if(e.target.matches('.v107-qty,.v107-price,.v94-qty,.v94-price')) recalc();
      });
    });
    recalc();
  }
  document.addEventListener('DOMContentLoaded',bind);
  setTimeout(bind,50);
})();
</script>
        <?php page_end();exit;
    }
    $partySt=$pdo->prepare('SELECT p.id,p.name,p.phone FROM parties p WHERE p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role="customer") ORDER BY p.name'); $partySt->execute([$cid]); $parties=$partySt->fetchAll();
    // Delivery Challan uses a dedicated, minimal item query so item selection
    // does not depend on stock-movement calculation or any optional joins.
    $itemSt=$pdo->prepare('SELECT id,item_type,name,sale_price,unit_id FROM items WHERE company_id=? AND active=1 ORDER BY name');
    $itemSt->execute([$cid]);
    $items=$itemSt->fetchAll(PDO::FETCH_ASSOC);
    $unitSymbols=[];
    $unitIds=[];
    foreach($items as $it){ if(isset($it['unit_id']) && $it['unit_id']!==null && $it['unit_id']!=='') $unitIds[]=(int)$it['unit_id']; }
    $unitIds=array_values(array_unique(array_filter($unitIds,fn($v)=>$v>0)));
    if($unitIds){
        $ph=implode(',',array_fill(0,count($unitIds),'?'));
        $ust=$pdo->prepare("SELECT id,symbol FROM units WHERE company_id=? AND id IN ($ph)");
        $ust->execute(array_merge([$cid],$unitIds));
        foreach($ust->fetchAll(PDO::FETCH_ASSOC) as $ur) $unitSymbols[(int)$ur['id']]=$ur['symbol'];
    }
    ?>
    <div class="page-title"><div><h1>Delivery Challan</h1><p>Create a delivery challan without posting sale/accounting until it is converted.</p></div><a class="btn" href="<?=e(url('delivery-challans'))?>">Back to Delivery Challans</a></div>
    <form method="post" id="dcForm" class="panel standard-entry-form delivery-entry-form">
      <input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_delivery_challan">
      <div class="entry-top standard-entry-top">
        <div class="standard-party-field"><?php party_search_field('Customer','customer',0,'',''); ?></div>
        <div class="form-group"><label>Challan Number</label><input name="document_no" placeholder="Auto: DC-000001"></div>
        <div class="form-group"><label>Challan Date*</label><input type="date" name="txn_date" value="<?=date('Y-m-d')?>" required></div>
      </div>
      <div class="entry-table"><table><thead><tr><th>#</th><th>ITEM</th><th>QTY</th><th>UNIT</th><th>PRICE/UNIT</th><th>AMOUNT</th></tr></thead><tbody id="dcRows">
        <tr><td>1</td><td><div class="item-picker-cell"><?php item_search_field(0,'','','sale'); ?><select name="item_id[]" class="dc-item item-source-select" required><option value="">Select item</option></select></div></td><td><input type="number" class="dc-qty" name="qty[]" step="0.01" min="0.01" value="1" required></td><td class="dc-unit">—</td><td><input type="number" class="dc-price" name="price[]" step="0.01" min="0" value="0" required></td><td class="dc-amt">৳0.00</td></tr>
      </tbody></table></div>
      <div id="dcItemStatus" class="subtle" style="margin-top:8px"></div>
      <div class="entry-actions"><button type="button" class="btn" onclick="dcAddRow()">+ Add Row</button><span><b>Total</b> <strong id="dcTotal">৳0.00</strong></span></div>
      <div class="grid3"><div class="form-group"><label>Discount</label><input id="dcInvDisc" type="number" name="invoice_discount" min="0" step="0.01" value="0"></div><div class="form-group"><label>Tax / VAT</label><input id="dcTax" type="number" name="tax" min="0" step="0.01" value="0"></div><div class="form-group"><label>Direct Expense</label><input id="dcDirect" type="number" name="direct_expense" min="0" step="0.01" value="0"></div></div>
      <div class="form-group"><label>Add Description</label><textarea name="notes" rows="4" placeholder="Optional delivery note"></textarea></div>
      <div class="form-footer" style="margin:0 -16px -16px"><a class="btn" href="<?=e(url('delivery-challans'))?>">Cancel</a><button type="button" class="btn" onclick="window.print()">Print Preview</button><button class="btn primary">Save</button></div>
    </form>
    <script>
    function dcFmt(v){return '৳'+Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
    let dcItemOptions=[];
    function dcPopulateSelect(sel){ return sel; }
    function dcSetPrice(el){const o=el?.selectedOptions?.[0],r=el?.closest('tr'); if(!r)return; r.querySelector('.dc-price').value=o?.dataset.price||0; r.querySelector('.dc-unit').textContent=o?.dataset.unit||'—'; dcRecalc();}
    document.addEventListener('input',e=>{if(e.target.closest('#dcRows')||['dcInvDisc','dcTax','dcDirect'].includes(e.target.id))dcRecalc();});
    document.addEventListener('change',e=>{if(e.target.matches('.dc-item'))dcSetPrice(e.target); if(e.target.closest('#dcRows'))dcRecalc();});
    dcRecalc();
    </script>
    <?php page_end();
}

function document_items(int $companyId,int $tid): array {
    $st=db()->prepare('SELECT ti.*,i.name item_name,i.item_type,u.symbol unit_symbol FROM transaction_items ti JOIN items i ON i.id=ti.item_id LEFT JOIN units u ON u.id=i.unit_id WHERE ti.transaction_id=? ORDER BY ti.id');
    $st->execute([$tid]); return $st->fetchAll();
}
function document_module(string $type,string $title,string $prefix,string $partyLabel,array $nextTypes=[]): void {
    // Quotation/Sale Order shared document engine.
    // These documents do not post accounting entries until a Sale conversion occurs.
    global $u;
    $cid=(int)$u['company_id']; $pdo=db();
    $isPartyRequired=true;
    $neededRole=$partyLabel==='Customer'?'customer':'supplier';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $action=$_POST['action']??'';
        if(in_array($action,['delete_document','duplicate_document'],true)){
            try{
                $docId=(int)($_POST['transaction_id']??0);
                $st=$pdo->prepare('SELECT * FROM transactions WHERE id=? AND company_id=? AND txn_type=? AND deleted_at IS NULL');
                $st->execute([$docId,$cid,$type]); $src=$st->fetch();
                if(!$src) throw new RuntimeException('Document not found.');
                if($action==='delete_document'){
                    if($src['status']==='converted') throw new RuntimeException('Converted document cannot be deleted.');
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE transactions SET deleted_at=NOW(),status=\"deleted\" WHERE id=? AND company_id=?')->execute([$docId,$cid]);
                    audit('delete','transaction',$docId,['type'=>$type,'document'=>$src['document_no']]);
                    $pdo->commit(); flash('success',$title.' '.$src['document_no'].' moved to Recycle Bin.');
                } else {
                    $it=$pdo->prepare('SELECT * FROM transaction_items WHERE transaction_id=? ORDER BY id'); $it->execute([$docId]); $itemsRows=$it->fetchAll();
                    if(!$itemsRows) throw new RuntimeException('Source document has no items.');
                    $pdo->beginTransaction();
                    $newDoc=next_document_in_transaction($pdo,$cid,$type,$prefix);
                    $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                      ->execute([$cid,$src['party_id'],$type,$newDoc,date('Y-m-d'),$src['due_date'],$src['subtotal'],$src['item_discount'],$src['invoice_discount'],$src['tax'],$src['direct_expense'],$src['total'],0,$src['total'],$src['currency_code'],'open','Duplicated from '.$src['document_no'],$u['id']]);
                    $newId=(int)$pdo->lastInsertId();
                    $ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');
                    foreach($itemsRows as $r) $ins->execute([$newId,$r['item_id'],$r['qty'],$r['unit_price'],$r['discount'],$r['tax'],$r['amount']]);
                    audit('duplicate','transaction',$newId,['source_transaction'=>$docId,'source_document'=>$src['document_no'],'type'=>$type,'document'=>$newDoc]);
                    $pdo->commit(); flash('success',$title.' '.$src['document_no'].' duplicated as '.$newDoc.'.');
                }
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
            redirect($type);
        }
        if($action==='save_document'){
        try{
            $party=(int)($_POST['party_id']??0); if($isPartyRequired && $party<=0) throw new RuntimeException($partyLabel.' is required.');
            if($isPartyRequired){$neededRole=$partyLabel==='Customer'?'customer':'supplier';$st=$pdo->prepare('SELECT p.id FROM parties p WHERE p.id=? AND p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role=?)');$st->execute([$party,$cid,$neededRole]);$pr=$st->fetch();if(!$pr)throw new RuntimeException('Invalid '.$partyLabel.'.');}
            $txnDate=$_POST['txn_date']??date('Y-m-d');$dueDate=$_POST['due_date']?:null;$notes=trim($_POST['notes']??'');
            $itemIds=$_POST['item_id']??[];$qtys=$_POST['qty']??[];$prices=$_POST['price']??[];$discs=$_POST['discount']??[];
            $rows=[];$subtotal=0;$itemDisc=0;
            foreach($itemIds as $i=>$iid){$iid=(int)$iid;$q=(float)($qtys[$i]??0);$price=(float)($prices[$i]??0);$disc=max(0,(float)($discs[$i]??0));if($iid<=0||$q<=0)continue;$st=$pdo->prepare('SELECT * FROM items WHERE id=? AND company_id=? AND active=1');$st->execute([$iid,$cid]);$it=$st->fetch();if(!$it)throw new RuntimeException('Invalid item selected.');$gross=$q*$price;if($disc>$gross)$disc=$gross;$rows[]=[$iid,$q,$price,$disc,$it];$subtotal+=$gross;$itemDisc+=$disc;}
            if(!$rows) throw new RuntimeException('Add at least one item.');
            $invDisc=max(0,(float)($_POST['invoice_discount']??0));$tax=max(0,(float)($_POST['tax']??0));$direct=max(0,(float)($_POST['direct_expense']??0));$after=max(0,$subtotal-$itemDisc);if($invDisc>$after)$invDisc=$after;$total=round(max(0,$after-$invDisc+$tax+$direct),2);
            $pdo->beginTransaction();$doc=trim($_POST['document_no']??'');if($doc==='')$doc=next_document_in_transaction($pdo,$cid,$type,$prefix);
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$party,$type,$doc,$txnDate,$dueDate,$subtotal,$itemDisc,$invDisc,$tax,$direct,$total,0,$total,$u['currency_code'],'open',$notes,$u['id']]);
            $tid=(int)$pdo->lastInsertId();$ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');foreach($rows as [$iid,$q,$price,$disc,$it])$ins->execute([$tid,$iid,$q,$price,$disc,0,max(0,$q*$price-$disc)]);
            if($type==='delivery_challan'){ /* challan does not change accounting/stock until converted to sale */ }
            audit('create','transaction',$tid,['type'=>$type,'document'=>$doc,'total'=>$total]);$pdo->commit();flash('success',$title.' '.$doc.' saved successfully.');redirect($type==='quotation'?'quotations':($type==='sale_order'?'sale-order':($type==='purchase_order'?'purchase-order':'delivery-challans')));
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect($type==='quotation'?'quotations':($type==='sale_order'?'sale-order':($type==='purchase_order'?'purchase-order':'delivery-challans')));}
        }
    }
    if(isset($_GET['convert'])){
        $sourceId=(int)$_GET['convert'];
        try{
            $sourceType=$type;
            $target=is_array($nextTypes)?($nextTypes[0]??''):((string)$nextTypes);
            if(!$target)throw new RuntimeException('Conversion is not configured.');
            $relation=match($sourceType){'quotation'=>'quotation_to_order','sale_order'=>'order_to_challan','delivery_challan'=>'challan_to_sale','purchase_order'=>'purchase_order_to_purchase_bill',default=>'document_conversion'};
            $existing=$pdo->prepare('SELECT to_transaction_id FROM transaction_links WHERE company_id=? AND from_transaction_id=? AND relation_type=? LIMIT 1');$existing->execute([$cid,$sourceId,$relation]);if($existing->fetchColumn())throw new RuntimeException('This document has already been converted.');
            $st=$pdo->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL');$st->execute([$sourceId,$cid,$sourceType]);$source=$st->fetch();if(!$source)throw new RuntimeException('Source document not found.');
            $its=$pdo->prepare('SELECT ti.* FROM transaction_items ti WHERE ti.transaction_id=?');$its->execute([$sourceId]);$sourceItems=$its->fetchAll();if(!$sourceItems)throw new RuntimeException('Source document has no items.');
            $targetType=$target;$targetPrefix=match($targetType){'sale_order'=>'SO-','delivery_challan'=>'DC-','sale'=>'SI-','purchase'=>'PB-',default=>'DOC-'};$targetDoc=next_document_in_transaction($pdo,$cid,$targetType,$targetPrefix);
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
              ->execute([$cid,$source['party_id'],$targetType,$targetDoc,date('Y-m-d'),$source['due_date'],$source['subtotal'],$source['item_discount'],$source['invoice_discount'],$source['tax'],$source['direct_expense'],$source['total'],0,$source['total'],$u['currency_code'],in_array($targetType,['sale','purchase'],true)?'final':'open','Converted from '.$source['document_no'],$u['id']]);
            $tid=(int)$pdo->lastInsertId();$ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');
            foreach($sourceItems as $r){$ins->execute([$tid,$r['item_id'],$r['qty'],$r['unit_price'],$r['discount'],$r['tax'],$r['amount']]);}
            if($targetType==='sale'){
                $cogs=0;$st2=$pdo->prepare('SELECT i.purchase_price,i.item_type FROM items i WHERE i.id=? AND i.company_id=?');$stock=$pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,movement_type,note) VALUES(?,?,?,?,?,?,?)');
                foreach($sourceItems as $r){$st2->execute([$r['item_id'],$cid]);$it=$st2->fetch();if($it&&$it['item_type']==='product'){$cogs+=max(0,(float)$r['qty']*(float)$it['purchase_price']);$stock->execute([$cid,$r['item_id'],$tid,date('Y-m-d'),-1*(float)$r['qty'],'sale',$targetDoc]);}}
                $net=max(0,(float)$source['subtotal']-(float)$source['item_discount']-(float)$source['invoice_discount']+(float)$source['direct_expense']);
                $lines=[['1200','Accounts Receivable',(float)$source['total'],0,$targetDoc],['4000','Sales Revenue',0,$net,$targetDoc]];if((float)$source['tax']>0)$lines[]=['2100','Tax Payable',0,(float)$source['tax'],$targetDoc];if($cogs>0){$lines[]=['5100','Cost of Goods Sold',$cogs,0,$targetDoc];$lines[]=['1300','Inventory',0,$cogs,$targetDoc];}$debit=0;$credit=0;foreach($lines as $l){$debit+=round((float)$l[2],2);$credit+=round((float)$l[3],2);}if(abs(round($debit-$credit,2))>0.01)throw new RuntimeException('Converted sale accounting entry is not balanced.');post_ledger($pdo,$cid,$tid,date('Y-m-d'),$lines);
            }
            if($targetType==='purchase'){
                $stock=$pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,movement_type,note) VALUES(?,?,?,?,?,?,?)');
                $itq=$pdo->prepare('SELECT item_type FROM items WHERE id=? AND company_id=?');
                $productCost=0;$serviceCost=0;
                foreach($sourceItems as $r){$itq->execute([(int)$r['item_id'],$cid]);$it=$itq->fetch();$amt=max(0,(float)$r['amount']);if($it&&$it['item_type']==='product'){$productCost+=$amt;$stock->execute([$cid,(int)$r['item_id'],$tid,date('Y-m-d'),(float)$r['qty'],'purchase',$targetDoc]);}else{$serviceCost+=$amt;}}
                $lines=[];
                if($productCost>0)$lines[]=['1300','Inventory',$productCost,0,$targetDoc];
                if($serviceCost>0)$lines[]=['5200','Purchase / Service Cost',$serviceCost,0,$targetDoc];
                if((float)$source['direct_expense']>0)$lines[]=['1300','Inventory',(float)$source['direct_expense'],0,$targetDoc];
                if((float)$source['tax']>0)$lines[]=['1400','Input VAT / Tax',(float)$source['tax'],0,$targetDoc];
                if((float)$source['invoice_discount']>0)$lines[]=['4300','Purchase Discount',0,(float)$source['invoice_discount'],$targetDoc];
                $lines[]=['2100','Accounts Payable',0,(float)$source['total'],$targetDoc];
                $debit=0;$credit=0;foreach($lines as $l){$debit+=round((float)$l[2],2);$credit+=round((float)$l[3],2);}if(abs(round($debit-$credit,2))>0.01)throw new RuntimeException('Converted purchase accounting entry is not balanced.');post_ledger($pdo,$cid,$tid,date('Y-m-d'),$lines);
            }
            $pdo->prepare('INSERT INTO transaction_links(company_id,from_transaction_id,to_transaction_id,relation_type,quantity) VALUES(?,?,?,?,NULL)')->execute([$cid,$sourceId,$tid,$relation]);
            $pdo->prepare('UPDATE transactions SET status="converted" WHERE id=? AND company_id=?')->execute([$sourceId,$cid]);
            audit('convert','transaction',$sourceId,['to_transaction'=>$tid,'relation'=>$relation,'target_type'=>$targetType,'target_document'=>$targetDoc]);$pdo->commit();flash('success',$source['document_no'].' converted to '.$targetDoc.'.');
            redirect($targetType==='sale_order'?'sale-order':($targetType==='delivery_challan'?'delivery-challans':($targetType==='purchase'?'purchase?view='.$tid:'sales?view='.$tid)));
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());$list=$type==='quotation'?'quotations':($type==='sale_order'?'sale-order':($type==='purchase_order'?'purchase-order':'delivery-challans'));redirect($list);}
    }
    page_start($title);
    $partySt=$pdo->prepare('SELECT p.id,p.name,p.phone FROM parties p WHERE p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role=? ) ORDER BY p.name');$partySt->execute([$cid,$partyLabel==='Customer'?'customer':'supplier']);$parties=$partySt->fetchAll();
    $items=get_items($cid);
    if(isset($_GET['view'])){
        $tid=(int)$_GET['view'];$st=$pdo->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type=?');$st->execute([$tid,$cid,$type]);$tx=$st->fetch();
        if($tx){$lines=document_items($cid,$tid);$can=$tx['status']!=='converted';$convertLabel=$type==='quotation'?'Convert to Sale Order':($type==='sale_order'?'Convert to Delivery Challan':($type==='purchase_order'?'Convert to Purchase Bill':'Convert to Sale'));$urlType = ($type==='purchase_order'?'purchase-order':$type); $convertUrl=$can?url($urlType.'?convert='.(int)$tx['id']):'#';
        ?><div class="page-title"><div><h1><?=e($title)?> <?=e($tx['document_no'])?></h1><p><?=e($tx['party_name']??'')?> · <?=e($tx['txn_date'])?></p></div><div><a class="btn" href="<?=e(url($type))?>">Back</a><?php if($can):?><a class="btn primary" href="<?=$convertUrl?>"><?=e($convertLabel)?></a><?php endif;?></div></div>
        <div class="cards-top"><div class="metric-card"><div class="label">Total</div><div class="value"><?=money((float)$tx['total'])?></div></div><div class="metric-card"><div class="label"><?=e($partyLabel)?></div><div class="value" style="font-size:20px"><?=e($tx['party_name']??'')?></div></div><div class="metric-card"><div class="label">Status</div><div class="value" style="font-size:20px"><?=e(ucfirst($tx['status']))?></div></div></div>
        <div class="panel"><div class="panel-head"><h2>ITEMS</h2></div><div class="table-wrap"><table><thead><tr><th>#</th><th>ITEM</th><th>QTY</th><th>PRICE/UNIT</th><th>DISCOUNT</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($lines as $i=>$r):?><tr><td><?=$i+1?></td><td><?=e($r['item_name'])?></td><td><?=qty((float)$r['qty']).' '.e($r['unit_symbol']??'')?></td><td><?=money((float)$r['unit_price'])?></td><td><?=money((float)$r['discount'])?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div></div><?php page_end();exit;}
    }
    $q=trim($_GET['q']??'');
    $sql='SELECT t.*,p.name party_name,p.phone party_phone FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL';
    $params=[$cid,$type];
    if($q!==''){ $sql.=' AND (t.document_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)'; $like='%'.$q.'%'; $params[]=$like; $params[]=$like; $params[]=$like; }
    $sql.=' ORDER BY t.txn_date DESC,t.id DESC';
    $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
    ?><div class="page-title"><div><h1><?=e($title)?></h1><p>Manage <?=e(strtolower($title))?></p></div><a class="btn primary" href="#newDoc">⊕ Add <?=e($title==='Estimate / Quotation'?'Quotation':($title==='Sale Order'?'Sale Order':'Purchase Order'))?></a></div>
    <div class="panel standard-entry-form" id="newDoc"><div class="panel-head"><h2>New <?=e($title==='Estimate / Quotation'?'Quotation':$title)?></h2><span class="subtle">No accounting/stock posting until conversion to the next document.</span></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_document"><div class="entry-top standard-entry-top"><div class="standard-party-field"><?php party_search_field($partyLabel,$partyLabel==='Customer'?'customer':'supplier',0,'',''); ?></div><div class="form-group"><label><?=e($title==='Estimate / Quotation'?'Quotation':$title)?> Number</label><input name="document_no" placeholder="Auto: <?=e($prefix)?>000001"></div><div class="form-group"><label><?=e($title==='Estimate / Quotation'?'Quotation':$title)?> Date*</label><input type="date" name="txn_date" value="<?=date('Y-m-d')?>" required></div></div>
    <div class="entry-table"><table><thead><tr><th>#</th><th>ITEM</th><th>QTY</th><th>PRICE/UNIT</th><th>DISCOUNT</th><th>AMOUNT</th></tr></thead><tbody id="docRows"><tr><td>1</td><td><div class="item-picker-cell"><?php item_search_field(0,'','',($type==='purchase_order'?'purchase':'sale')); ?><select name="item_id[]" class="item-select item-source-select" onchange="docPrice(this)" required><option value="">Select item</option><?php foreach($items as $it):?><option value="<?=$it['id']?>" data-price="<?=$it[$type==='purchase_order'?'purchase_price':'sale_price']?>" data-unit="<?=e($unitSymbols[(int)($it['unit_id']??0)]??'')?>"><?=e($it['name'])?></option><?php endforeach;?></select></div></td><td><input type="number" name="qty[]" class="doc-qty" step="0.01" min="0.001" value="1" required></td><td><input type="number" name="price[]" class="doc-price" step="0.01" min="0" value="0" required></td><td><input type="number" name="discount[]" class="doc-disc" step="0.01" min="0" value="0"></td><td class="doc-amt"><?=money(0)?></td></tr></tbody></table></div>
    <div class="entry-actions"><button type="button" class="btn" onclick="addDocRow()">+ Add Row</button><span><b>Total</b> <strong id="docTotal">৳0.00</strong></span></div>
    <div class="grid3"><div class="form-group"><label>Invoice Discount</label><input id="docInvDisc" type="number" name="invoice_discount" min="0" step="0.01" value="0"></div><div class="form-group"><label>Tax / VAT</label><input id="docTax" type="number" name="tax" min="0" step="0.01" value="0"></div><div class="form-group"><label>Direct Expense</label><input id="docDirect" type="number" name="direct_expense" min="0" step="0.01" value="0"></div></div>
    <div class="form-group"><label>Description / Note</label><textarea name="notes" rows="3"></textarea></div><div class="form-footer" style="margin:0 -16px -16px"><a class="btn" href="<?=e(url($type))?>">Cancel</a><button class="btn primary">Save</button></div></form></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>TRANSACTIONS</h2><form method="get" style="display:flex;gap:8px"><input class="input" name="q" value="<?=e($q)?>" style="max-width:280px" placeholder="Search by document, party, phone"><button class="btn">Search</button></form></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>DOCUMENT NO.</th><th><?=e(strtoupper($partyLabel))?></th><th>TOTAL</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td><td><?=e($r['document_no'])?></td><td><?=e($r['party_name']??'')?></td><td><?=money((float)$r['total'])?></td><td><span class="status <?=($r['status']==='converted'?'paid':'open')?>"><?=e(ucfirst($r['status']))?></span></td><td class="action"><a class="btn" href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?view='.(int)$r['id'])))?>">View</a><?php if($r['status']!=='converted'):?><a class="btn primary small-btn" href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?convert='.(int)$r['id'])))?>" onclick="return confirm('<?=e($title)?> will be converted. Continue?')"><?=e($type==='quotation'?'CONVERT TO SALE ORDER':($type==='sale_order'?'CONVERT TO DELIVERY CHALLAN':($type==='purchase_order'?'CONVERT TO PURCHASE BILL':'CONVERT TO SALE')))?></a><?php endif;?><button type="button" class="dots" aria-label="Actions">⋮</button><div class="row-menu"><a href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?view='.(int)$r['id'])))?>">View</a><?php if($r['status']!=='converted'):?><a href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?convert='.(int)$r['id'])))?>" onclick="return confirm('Convert this document?')"><?=e($type==='quotation'?'Convert to Sale Order':($type==='sale_order'?'Convert to Delivery Challan':($type==='purchase_order'?'Convert to Purchase Bill':'Convert to Sale')))?></a><form method="post" onsubmit="return confirm('Delete this document? It will move to Recycle Bin.')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_document"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Delete</button></form><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="duplicate_document"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Duplicate</button></form><a href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?view='.(int)$r['id'].'&print=1')))?>">Open PDF</a><a href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?view='.(int)$r['id'])))?>">Preview</a><a href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?view='.(int)$r['id'].'&print=1')))?>">Print</a></div></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6" class="subtle">No documents yet.</td></tr><?php endif;?></tbody></table></div></div>
    <script>
    function docPrice(el){const o=el.selectedOptions[0];const row=el.closest('tr');if(row)row.querySelector('.doc-price').value=o?.dataset.price||0;docRecalc();}
    function docRecalc(){let t=0;document.querySelectorAll('#docRows tr').forEach(r=>{const q=parseFloat(r.querySelector('.doc-qty')?.value||0),p=parseFloat(r.querySelector('.doc-price')?.value||0),d=parseFloat(r.querySelector('.doc-disc')?.value||0);const a=Math.max(0,q*p-Math.min(d,q*p));t+=a;r.querySelector('.doc-amt').textContent='৳'+a.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});});const inv=parseFloat(document.getElementById('docInvDisc')?.value||0),tax=parseFloat(document.getElementById('docTax')?.value||0),direct=parseFloat(document.getElementById('docDirect')?.value||0);t=Math.max(0,t-Math.min(inv,t)+tax+direct);document.getElementById('docTotal').textContent='৳'+t.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
    function addDocRow(){const body=document.getElementById('docRows'),n=body.querySelectorAll('tr').length+1;const tpl=body.querySelector('tr').cloneNode(true);tpl.querySelectorAll('input').forEach(i=>{if(i.classList.contains('doc-qty'))i.value='1';else if(i.classList.contains('doc-price')||i.classList.contains('doc-disc'))i.value='0';else if(i.classList.contains('item-search-input'))i.value='';});tpl.querySelectorAll('select').forEach(s=>s.selectedIndex=0);const clear=tpl.querySelector('.item-search-clear');if(clear)clear.style.display='none';tpl.querySelector('.doc-amt').textContent='৳0.00';tpl.firstElementChild.textContent=n;body.appendChild(tpl);if(typeof window.SutoInitItemSearch==='function')window.SutoInitItemSearch(tpl);}
    document.addEventListener('input',e=>{if(e.target.closest('#docRows')||['docInvDisc','docTax','docDirect'].includes(e.target.id))docRecalc();});document.addEventListener('change',e=>{if(e.target.closest('#docRows'))docRecalc();});docRecalc();
    </script><?php page_end();
}

if($route==='delivery-challans'){delivery_challans_list();exit;}

if($route==='delivery-challan-items'){
    header('Content-Type: application/json; charset=utf-8');
    try{
        $cid=(int)$u['company_id'];
        // Intentionally avoid any dependency on units, stock_movements, or get_items().
        // We load every item belonging to the logged-in company, and filter inactive items
        // only in PHP so a schema/data mismatch on the active flag cannot produce an empty selector.
        $st=db()->prepare("SELECT id,name,item_type,sale_price,purchase_price,unit_id,active FROM items WHERE company_id=? ORDER BY name,id");
        $st->execute([$cid]);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC);
        // Resolve unit symbols independently and safely.
        $unitIds=[];
        foreach($rows as $r){ if(isset($r['unit_id']) && $r['unit_id']!==null && $r['unit_id']!=='') $unitIds[]=(int)$r['unit_id']; }
        $symbols=[];
        $unitIds=array_values(array_unique(array_filter($unitIds,fn($v)=>$v>0)));
        if($unitIds){
            $ph=implode(',',array_fill(0,count($unitIds),'?'));
            $ust=db()->prepare("SELECT id,symbol FROM units WHERE company_id=? AND id IN ($ph)");
            $ust->execute(array_merge([$cid],$unitIds));
            foreach($ust->fetchAll(PDO::FETCH_ASSOC) as $ur) $symbols[(int)$ur['id']]=$ur['symbol'];
        }
        $out=[];
        foreach($rows as $r){
            if((int)($r['active']??1)!==1) continue;
            $out[]=[
                'id'=>(int)$r['id'],
                'name'=>(string)$r['name'],
                'item_type'=>(string)$r['item_type'],
                'sale_price'=>(float)$r['sale_price'],
                'purchase_price'=>(float)$r['purchase_price'],
                'unit_id'=>$r['unit_id']===null?null:(int)$r['unit_id'],
                'unit_symbol'=>(string)($symbols[(int)($r['unit_id']??0)]??'')
            ];
        }
        echo json_encode(['ok'=>true,'count'=>count($out),'items'=>$out],JSON_UNESCAPED_UNICODE); exit;
    }catch(Throwable $e){
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'items'=>[]],JSON_UNESCAPED_UNICODE); exit;
    }
}

function delivery_challans_list(): void {
    global $u;
    $cid=(int)$u['company_id']; $pdo=db();

    // Delete a Delivery Challan: move it to Recycle Bin.
    // Converted challans are protected because they are linked to a Sale.
    if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_document'){
        check_csrf();
        $docId=(int)($_POST['transaction_id']??0);
        try{
            if($docId<=0) throw new RuntimeException('Invalid Delivery Challan.');
            $st=$pdo->prepare('SELECT t.*,(SELECT tl.to_transaction_id FROM transaction_links tl WHERE tl.company_id=t.company_id AND tl.from_transaction_id=t.id AND tl.relation_type="challan_to_sale" LIMIT 1) sale_id FROM transactions t WHERE t.id=? AND t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL LIMIT 1');
            $st->execute([$docId,$cid]);
            $doc=$st->fetch();
            if(!$doc) throw new RuntimeException('Delivery Challan not found.');
            if(!empty($doc['sale_id']) || ($doc['status']??'')==='converted'){
                throw new RuntimeException('Converted Delivery Challan cannot be deleted.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE transactions SET deleted_at=NOW(),status="deleted" WHERE id=? AND company_id=? AND txn_type="delivery_challan" AND deleted_at IS NULL')->execute([$docId,$cid]);
            try{
                $pdo->prepare('UPDATE transaction_items SET deleted_at=NOW() WHERE transaction_id=?')->execute([$docId]);
            }catch(Throwable $ignore){}
            audit('delete','transaction',$docId,[
                'type'=>'delivery_challan',
                'document_no'=>$doc['document_no']??null,
                'reason'=>'Delivery Challan moved to Recycle Bin'
            ]);
            $pdo->commit();
            flash('success','Delivery Challan '.$doc['document_no'].' moved to Recycle Bin.');
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error',$e->getMessage());
        }
        redirect('delivery-challans');
    }

    // Convert a challan to a sale from the list page.
    if(isset($_GET['convert']) && $_GET['convert']!==''){
        $sourceId=(int)$_GET['convert'];
        try{
            $st=$pdo->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL');
            $st->execute([$sourceId,$cid]); $source=$st->fetch();
            if(!$source) throw new RuntimeException('Delivery Challan not found.');
            $lk=$pdo->prepare('SELECT to_transaction_id FROM transaction_links WHERE company_id=? AND from_transaction_id=? AND relation_type="challan_to_sale" LIMIT 1');
            $lk->execute([$cid,$sourceId]);
            if($lk->fetchColumn()) throw new RuntimeException('This Delivery Challan has already been converted to Sale.');
            $its=$pdo->prepare('SELECT ti.* FROM transaction_items ti WHERE ti.transaction_id=? ORDER BY ti.id'); $its->execute([$sourceId]); $sourceItems=$its->fetchAll();
            if(!$sourceItems) throw new RuntimeException('Delivery Challan has no items.');
            $pdo->beginTransaction();
            $doc=next_document_in_transaction($pdo,$cid,'sale','SI-');
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
              ->execute([$cid,$source['party_id'],'sale',$doc,$source['txn_date'],$source['due_date'],$source['subtotal'],$source['item_discount'],$source['invoice_discount'],$source['tax'],$source['direct_expense'],$source['total'],0,$source['total'],$source['currency_code'],'final','Converted from '.$source['document_no'],$u['id']]);
            $tid=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');
            foreach($sourceItems as $r){$ins->execute([$tid,$r['item_id'],$r['qty'],$r['unit_price'],$r['discount'],$r['tax'],$r['amount']]);}
            $cogs=0;
            $itq=$pdo->prepare('SELECT item_type,purchase_price FROM items WHERE id=? AND company_id=?');
            $stock=$pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,movement_type,note) VALUES(?,?,?,?,?,?,?)');
            foreach($sourceItems as $r){
                $itq->execute([(int)$r['item_id'],$cid]); $it=$itq->fetch();
                if($it && $it['item_type']==='product'){
                    $qty=(float)$r['qty']; $cogs += max(0,$qty*(float)$it['purchase_price']);
                    $stock->execute([$cid,(int)$r['item_id'],$tid,$source['txn_date'],-$qty,'sale',$doc]);
                }
            }
            $net=max(0,(float)$source['subtotal']-(float)$source['item_discount']-(float)$source['invoice_discount']);
            $lines=[['1200','Accounts Receivable',(float)$source['total'],0,$doc],['4000','Sales Revenue',0,$net,$doc]];
            if((float)$source['tax']>0)$lines[]=['2100','Tax Payable',0,(float)$source['tax'],$doc];
            if((float)$source['direct_expense']>0)$lines[]=['4200','Direct Expense Recovery',0,(float)$source['direct_expense'],$doc];
            if($cogs>0){$lines[]=['5100','Cost of Goods Sold',$cogs,0,$doc];$lines[]=['1300','Inventory',0,$cogs,$doc];}
            $debit=0;$credit=0;foreach($lines as $l){$debit+=round((float)$l[2],2);$credit+=round((float)$l[3],2);}if(abs(round($debit-$credit,2))>0.01)throw new RuntimeException('Converted sale accounting entry is not balanced.');post_ledger($pdo,$cid,$tid,$source['txn_date'],$lines);
            $pdo->prepare('INSERT INTO transaction_links(company_id,from_transaction_id,to_transaction_id,relation_type,quantity) VALUES(?,?,?,?,NULL)')->execute([$cid,$sourceId,$tid,'challan_to_sale']);
            $pdo->prepare('UPDATE transactions SET status="converted" WHERE id=? AND company_id=?')->execute([$sourceId,$cid]);
            audit('convert','transaction',$sourceId,['to_transaction'=>$tid,'relation'=>'challan_to_sale','target_document'=>$doc]);
            $pdo->commit(); flash('success',$source['document_no'].' converted to '.$doc.'.'); redirect('delivery-challans');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect('delivery-challans');}
    }
    $q=trim($_GET['q']??'');
    $sql='SELECT t.*,p.name party_name,(SELECT tl.to_transaction_id FROM transaction_links tl WHERE tl.company_id=t.company_id AND tl.from_transaction_id=t.id AND tl.relation_type="challan_to_sale" LIMIT 1) sale_id FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL';
    $params=[$cid];
    if($q!==''){ $sql.=' AND (t.document_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)'; $like='%'.$q.'%'; $params[]=$like;$params[]=$like;$params[]=$like; }
    $sql.=' ORDER BY t.txn_date DESC,t.id DESC'; $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
    page_start('Delivery Challans');
    ?>
    <div class="page-title"><div><h1>Delivery Challan</h1><p>Track delivery challans and convert them to sales.</p></div><a class="btn primary" href="<?=e(url('delivery-challan-new'))?>">⊕ Add Delivery Challan</a></div>
    <div class="panel">
      <div class="panel-head"><h2>TRANSACTIONS</h2><form method="get" style="display:flex;gap:8px"><input class="input" name="q" value="<?=e($q)?>" placeholder="⌕ Search by challan, party, phone"><button class="btn" type="submit">Search</button></form></div>
      <div class="table-wrap"><table><thead><tr><th>DATE</th><th>PARTY</th><th>CHALLAN NO.</th><th>DUE DATE</th><th>TOTAL AMOUNT</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
      <?php foreach($rows as $r): $converted=!empty($r['sale_id']); ?>
      <tr>
        <td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td>
        <td><?=e($r['party_name']??'')?></td>
        <td><?=e($r['document_no'])?></td>
        <td><?=e($r['due_date']?date('d/m/Y',strtotime($r['due_date'])):'—')?></td>
        <td><?=money((float)$r['total'])?></td>
        <td><span class="status <?=$converted?'paid':'open'?>"><?=e($converted?'Converted':ucfirst($r['status']))?></span></td>
        <td class="action"><div style="display:flex;align-items:center;gap:6px;justify-content:flex-end">
          <?php if(!$converted):?><a class="btn small-btn" href="<?=e(url('delivery-challans?convert='.(int)$r['id']))?>" onclick="return confirm('Convert this Delivery Challan to Sale?')">CONVERT TO SALE</a><?php elseif($r['sale_id']):?><a class="btn small-btn" href="<?=e(url('sales?view='.(int)$r['sale_id']))?>">VIEW SALE</a><?php endif; ?>
          <a class="btn small-btn" href="<?=e(url('delivery-challan-new?view='.(int)$r['id']))?>">View/Edit</a>
          <button type="button" class="dots" aria-label="Actions">⋮</button>
          <div class="row-menu"><a href="<?=e(url('delivery-challan-new?view='.(int)$r['id']))?>">View/Edit</a><?php if(!$converted):?><a href="<?=e(url('delivery-challan-new?duplicate='.(int)$r['id']))?>">Duplicate</a><form method="post" onsubmit="return confirm('Delete this Delivery Challan? It will move to Recycle Bin.')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_document"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Delete</button></form><?php else:?><span style="display:block;padding:9px 12px;color:#9ca3af">Delete unavailable</span><?php endif;?><a href="<?=e(url('delivery-challan-new?view='.(int)$r['id'].'&print=1'))?>">Open PDF</a><a href="<?=e(url('delivery-challan-new?view='.(int)$r['id']))?>">Preview</a><a href="<?=e(url('delivery-challan-new?view='.(int)$r['id'].'&print=1'))?>">Print</a></div>
        </div></td>
      </tr>
      <?php endforeach; if(!$rows):?><tr><td colspan="7" class="subtle">No Delivery Challans found.</td></tr><?php endif; ?></tbody></table></div>
    </div>
    <style>.action{position:relative}.row-menu{position:absolute;right:0;top:38px;display:none;background:#fff;border:1px solid #d8dee8;box-shadow:0 8px 20px rgba(0,0,0,.12);z-index:20;min-width:150px}.row-menu.show{display:block}.row-menu a{display:block;padding:9px 12px;white-space:nowrap}.row-menu a:hover{background:#f3f6fa}.row-menu form{margin:0}.row-menu form button{display:block;width:100%;border:0;background:#fff;text-align:left;padding:9px 12px;font:inherit;color:#dc2626;cursor:pointer}.row-menu form button:hover{background:#fef2f2}</style>
    <?php page_end();exit;
}

if($route==='delivery-challan-new'){delivery_challan_new();exit;}

if($route==='quotations'){document_module('quotation','Estimate / Quotation','QT-','Customer',['sale_order']);exit;}
if($route==='sale-order'){document_module('sale_order','Sale Order','SO-','Customer',['delivery_challan']);exit;}
if($route==='purchase-order'){document_module('purchase_order','Purchase Order','PO-','Supplier',['purchase']);exit;}
if($route==='purchase_order'){redirect('purchase-order');}





function normalize_payment_method(PDO $pdo,int $cid,string $rawMethod,?string $legacyAccount=null): array {
    $rawMethod=trim($rawMethod);
    if($rawMethod==='cash') return ['cash',null];
    if($rawMethod==='cheque') return ['cheque',null];
    if(str_starts_with($rawMethod,'bank|')) {
        $bankId=(int)substr($rawMethod,5);
        if($bankId<=0) throw new RuntimeException('Select a valid bank account.');
        $st=$pdo->prepare('SELECT id,name FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');
        $st->execute([$bankId,$cid]);
        $bank=$st->fetch();
        if(!$bank) throw new RuntimeException('Selected bank account is invalid or inactive.');
        return ['bank',(string)$bank['name']];
    }
    if($rawMethod==='bank' && trim((string)$legacyAccount)!=='') {
        $st=$pdo->prepare('SELECT id,name FROM bank_accounts WHERE company_id=? AND name=? AND active=1 LIMIT 1');
        $st->execute([$cid,trim((string)$legacyAccount)]);
        $bank=$st->fetch();
        if(!$bank) throw new RuntimeException('Selected bank account is invalid or inactive.');
        return ['bank',(string)$bank['name']];
    }
    throw new RuntimeException('Invalid payment method.');
}
function payment_select_options(array $bankRows,string $selectedMethod='cash',string $selectedAccount=''): string {
    $selected=$selectedMethod==='cheque' ? 'cheque' : ($selectedMethod==='cash' ? 'cash' : '');
    if($selectedMethod==='bank') {
        foreach($bankRows as $b){if((string)$b['name']===$selectedAccount){$selected='bank|'.(int)$b['id'];break;}}
    }
    $html='<option value="cash"'.($selected==='cash'?' selected':'').'>Cash</option>';
    $html.='<option value="cheque"'.($selected==='cheque'?' selected':'').'>Cheque</option>';
    foreach($bankRows as $b){
        $v='bank|'.(int)$b['id']; $label=(string)$b['name'];
        if(!empty($b['bank_name'])) $label.=' · '.(string)$b['bank_name'];
        $html.='<option value="'.e($v).'"'.($selected===$v?' selected':'').'>'.e($label).'</option>';
    }
    return $html;
}
function payment_account_code(string $method, ?string $account=null): array {
    $account=trim((string)($account??''));
    return match($method) {
        'cash' => ['1000','Cash In Hand'],
        'bank' => ['1010','Bank - '.($account ?: 'Bank Account')],
        'cheque' => ['1020','Cheque / Clearing'],
        default => throw new RuntimeException('Unsupported payment method.'),
    };
}
function company_cash_balance(int $cid): float {
    /* Exclude ledger rows belonging to transactions that have been moved to Recycle Bin.
       The ledger rows are intentionally retained for audit history, so the balance query
       must ignore them after the source transaction is deleted. */
    $st=db()->prepare("SELECT
        COALESCE((SELECT opening_balance FROM cash_accounts WHERE company_id=? AND active=1 LIMIT 1),0)
        + COALESCE((
            SELECT SUM(le.debit-le.credit)
            FROM ledger_entries le
            LEFT JOIN transactions t ON t.id=le.transaction_id AND t.company_id=le.company_id
            WHERE le.company_id=?
              AND le.account_code IN ('1000','1100')
              AND (le.transaction_id IS NULL OR (t.id IS NOT NULL AND t.deleted_at IS NULL))
        ),0) AS bal");
    $st->execute([$cid,$cid]);
    return (float)$st->fetchColumn();
}
function bank_balance(int $cid,int $bankId): float {
    $st=db()->prepare('SELECT b.opening_balance + COALESCE(SUM(CASE WHEN le.account_code=? THEN le.debit-le.credit ELSE 0 END),0) FROM bank_accounts b LEFT JOIN ledger_entries le ON le.company_id=b.company_id AND le.transaction_id IS NOT NULL AND le.account_name=CONCAT("Bank - ",b.name) LEFT JOIN transactions t ON t.id=le.transaction_id AND t.company_id=le.company_id WHERE b.company_id=? AND b.id=? AND (le.transaction_id IS NULL OR (t.id IS NOT NULL AND t.deleted_at IS NULL)) GROUP BY b.id');
    $st->execute(['1010',$cid,$bankId]); return (float)$st->fetchColumn();
}

if($route==='expense-new' || $route==='expense'){
    $u=require_login(); $cid=(int)$u['company_id']; $pdo=db();

    /* v87: Expense masters. */
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $masterAction=$_POST['action']??'';
        if(in_array($masterAction,['save_expense_category','edit_expense_category','delete_expense_category','save_expense_item','edit_expense_item','delete_expense_item'],true)){
            try{
                if($masterAction==='save_expense_category'){
                    $name=trim($_POST['name']??''); $type=$_POST['expense_type']??'indirect';
                    if($name==='') throw new RuntimeException('Category name is required.');
                    if(!in_array($type,['direct','indirect'],true)) throw new RuntimeException('Invalid expense category type.');
                    $pdo->prepare('INSERT INTO expense_categories(company_id,name,expense_type,active) VALUES(?,?,?,1)')->execute([$cid,$name,$type]);
                    audit('create','expense_category',(int)$pdo->lastInsertId(),['name'=>$name,'expense_type'=>$type]); flash('success','Expense category added.'); redirect('expense');
                }
                if($masterAction==='edit_expense_category'){
                    $id=(int)($_POST['id']??0); $name=trim($_POST['name']??''); $type=$_POST['expense_type']??'indirect';
                    if($id<=0||$name==='') throw new RuntimeException('Invalid expense category.');
                    if(!in_array($type,['direct','indirect'],true)) throw new RuntimeException('Invalid expense category type.');
                    $pdo->prepare('UPDATE expense_categories SET name=?,expense_type=? WHERE id=? AND company_id=?')->execute([$name,$type,$id,$cid]); audit('update','expense_category',$id,['name'=>$name,'expense_type'=>$type]); flash('success','Expense category updated.'); redirect('expense');
                }
                if($masterAction==='delete_expense_category'){
                    $id=(int)($_POST['id']??0); if($id<=0) throw new RuntimeException('Invalid expense category.');
                    $q=$pdo->prepare('SELECT name FROM expense_categories WHERE id=? AND company_id=? AND active=1');$q->execute([$id,$cid]);$name=$q->fetchColumn(); if($name===false) throw new RuntimeException('Expense category not found.');
                    $use=$pdo->prepare('SELECT COUNT(*) FROM expense_details WHERE company_id=? AND category=?');$use->execute([$cid,$name]);
                    if((int)$use->fetchColumn()>0){$pdo->prepare('UPDATE expense_categories SET active=0 WHERE id=? AND company_id=?')->execute([$id,$cid]);$msg='Category is used by existing expenses, so it was deactivated.';} else {$pdo->prepare('DELETE FROM expense_categories WHERE id=? AND company_id=?')->execute([$id,$cid]);$msg='Expense category deleted.';}
                    audit('delete','expense_category',$id,['name'=>$name]); flash('success',$msg); redirect('expense');
                }
                if($masterAction==='save_expense_item'){
                    $name=trim($_POST['name']??'');
                    if($name==='') throw new RuntimeException('Expense item name is required.');
                    $pdo->prepare('INSERT INTO expense_items(company_id,name,category_id,active) VALUES(?,?,NULL,1)')->execute([$cid,$name]);
                    audit('create','expense_item',(int)$pdo->lastInsertId(),['name'=>$name]); flash('success','Expense item added.'); redirect('expense?tab=items');
                }
                if($masterAction==='edit_expense_item'){
                    $id=(int)($_POST['id']??0); $name=trim($_POST['name']??'');
                    if($id<=0||$name==='') throw new RuntimeException('Invalid expense item.');
                    $pdo->prepare('UPDATE expense_items SET name=?,category_id=NULL WHERE id=? AND company_id=?')->execute([$name,$id,$cid]);
                    audit('update','expense_item',$id,['name'=>$name]); flash('success','Expense item updated.'); redirect('expense?tab=items');
                }
                if($masterAction==='delete_expense_item'){
                    $id=(int)($_POST['id']??0); if($id<=0) throw new RuntimeException('Invalid expense item.');
                    $q=$pdo->prepare('SELECT name FROM expense_items WHERE id=? AND company_id=? AND active=1');$q->execute([$id,$cid]);$name=$q->fetchColumn(); if($name===false) throw new RuntimeException('Expense item not found.');
                    $use=$pdo->prepare('SELECT COUNT(*) FROM expense_item_links WHERE expense_item_id=?');$use->execute([$id]); $used=(int)$use->fetchColumn();
                    $pdo->prepare('UPDATE expense_items SET active=0 WHERE id=? AND company_id=?')->execute([$id,$cid]); audit('delete','expense_item',$id,['name'=>$name]); flash('success',$used>0?'Expense item moved to inactive because it has transactions.':'Expense item deleted.'); redirect('expense?tab=items');
                }
            }catch(Throwable $e){ flash('error',$e->getCode()==='23000'?'This name already exists.':$e->getMessage()); redirect('expense'.(in_array($masterAction,['save_expense_item','edit_expense_item','delete_expense_item'],true)?'?tab=items':'')); }
        }
    }

    /* Expense master screens: Categories and Items. */
    $masterTab=$_GET['tab']??'';
    if($masterTab==='categories'){
        $catQ=$pdo->prepare('SELECT id,name,expense_type,active FROM expense_categories WHERE company_id=? ORDER BY active DESC,name');
        $catQ->execute([$cid]); $masterCategories=$catQ->fetchAll(PDO::FETCH_ASSOC);
        page_start('Expense Categories');
        ?>
        <style>
        .exp-master{max-width:1180px;margin:0 auto;padding:4px 0 28px}.exp-master-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}.exp-master-head h1{margin:0;color:#17324d;font-size:26px}.exp-master-head p{margin:5px 0 0;color:#718096;font-size:13px}.exp-tabs{display:flex;gap:6px;margin-bottom:14px}.exp-tab{padding:9px 14px;border:1px solid #d7dee7;border-radius:7px;text-decoration:none;color:#475569;background:#fff;font-size:13px}.exp-tab.active{background:#1877d2;border-color:#1877d2;color:#fff}.exp-master-grid{display:grid;grid-template-columns:330px 1fr;gap:14px}.exp-master-card{background:#fff;border:1px solid #e2e8f0;border-radius:9px;overflow:hidden}.exp-master-card-head{padding:14px 16px;border-bottom:1px solid #e2e8f0}.exp-master-card-head h2{margin:0;font-size:15px;color:#334155}.exp-master-body{padding:16px}.exp-field{margin-bottom:12px}.exp-field label{display:block;font-size:12px;color:#64748b;margin-bottom:6px}.exp-field input,.exp-field select{width:100%;height:40px;border:1px solid #cbd5e1;border-radius:6px;padding:0 10px;box-sizing:border-box;background:#fff}.exp-btn{height:38px;padding:0 15px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;cursor:pointer}.exp-btn.primary{background:#1877d2;color:#fff;border-color:#1877d2}.exp-list{width:100%;border-collapse:collapse}.exp-list th{background:#f8fafc;color:#64748b;font-size:11px;text-align:left;padding:11px 12px;border-bottom:1px solid #e2e8f0}.exp-list td{padding:11px 12px;border-bottom:1px solid #edf2f7;font-size:13px;color:#334155;vertical-align:middle}.exp-actions{display:flex;gap:6px;flex-wrap:wrap}.exp-inline-form{display:flex;gap:6px;align-items:center}.exp-inline-form input,.exp-inline-form select{height:34px;border:1px solid #cbd5e1;border-radius:5px;padding:0 8px;box-sizing:border-box}.exp-inline-form input{width:220px}.exp-inline-form select{width:125px}.exp-danger{color:#b42318;border-color:#f1b5b0}.exp-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:11px}.exp-badge.direct{background:#e0f2fe;color:#0369a1}.exp-badge.indirect{background:#f1f5f9;color:#475569}.exp-empty{text-align:center;color:#64748b;padding:28px}
        @media(max-width:850px){.exp-master-grid{grid-template-columns:1fr}.exp-master-head{flex-direction:column}}
        </style>
        <div class="exp-master">
          <div class="exp-master-head"><div><h1>Expense Categories</h1><p>Create and manage the categories used by your expense entries.</p></div><a class="exp-btn" href="<?=e(url('expense'))?>">← Back to Expenses</a></div>
          <div class="exp-tabs"><a class="exp-tab active" href="<?=e(url('expense?tab=categories'))?>">Categories</a><a class="exp-tab" href="<?=e(url('expense?tab=items'))?>">Items</a></div>
          <div class="exp-master-grid">
            <div class="exp-master-card"><div class="exp-master-card-head"><h2>ADD CATEGORY</h2></div><div class="exp-master-body"><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_expense_category"><div class="exp-field"><label>Category Name *</label><input name="name" required maxlength="100" placeholder="e.g. Office Expense"></div><div class="exp-field"><label>Expense Type *</label><select name="expense_type"><option value="indirect">Indirect Expense</option><option value="direct">Direct Expense</option></select></div><button class="exp-btn primary" type="submit">+ Add Category</button></form></div></div>
            <div class="exp-master-card"><div class="exp-master-card-head"><h2>CATEGORIES</h2></div><div style="overflow:auto"><table class="exp-list"><thead><tr><th>NAME</th><th>TYPE</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
            <?php foreach($masterCategories as $c): ?>
              <tr><td><strong><?=e($c['name'])?></strong></td><td><span class="exp-badge <?=e($c['expense_type'])?>"><?=e(ucfirst($c['expense_type']))?></span></td><td><?=((int)$c['active']===1)?'Active':'Inactive'?></td><td><div class="exp-actions"><form method="post" class="exp-inline-form"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="edit_expense_category"><input type="hidden" name="id" value="<?=$c['id']?>"><input name="name" value="<?=e($c['name'])?>" required><select name="expense_type"><option value="indirect" <?=($c['expense_type']==='indirect'?'selected':'')?>>Indirect</option><option value="direct" <?=($c['expense_type']==='direct'?'selected':'')?>>Direct</option></select><button class="exp-btn" type="submit">Save</button></form><form method="post" onsubmit="return confirm('Delete this category?');"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_expense_category"><input type="hidden" name="id" value="<?=$c['id']?>"><button class="exp-btn exp-danger" type="submit">Delete</button></form></div></td></tr>
            <?php endforeach; if(!$masterCategories): ?><tr><td colspan="4" class="exp-empty">No expense categories yet.</td></tr><?php endif; ?>
            </tbody></table></div></div>
          </div>
        </div>
        <?php page_end();exit;
    }
    if($masterTab==='items'){
        $itemQ=$pdo->prepare('SELECT id,name,active FROM expense_items WHERE company_id=? ORDER BY active DESC,name');
        $itemQ->execute([$cid]); $masterItems=$itemQ->fetchAll(PDO::FETCH_ASSOC);
        page_start('Expense Items');
        ?>
        <style>
        .exp-master{max-width:1180px;margin:0 auto;padding:4px 0 28px}.exp-master-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px}.exp-master-head h1{margin:0;color:#17324d;font-size:26px}.exp-master-head p{margin:5px 0 0;color:#718096;font-size:13px}.exp-tabs{display:flex;gap:6px;margin-bottom:14px}.exp-tab{padding:9px 14px;border:1px solid #d7dee7;border-radius:7px;text-decoration:none;color:#475569;background:#fff;font-size:13px}.exp-tab.active{background:#1877d2;border-color:#1877d2;color:#fff}.exp-master-grid{display:grid;grid-template-columns:330px 1fr;gap:14px}.exp-master-card{background:#fff;border:1px solid #e2e8f0;border-radius:9px;overflow:hidden}.exp-master-card-head{padding:14px 16px;border-bottom:1px solid #e2e8f0}.exp-master-card-head h2{margin:0;font-size:15px;color:#334155}.exp-master-body{padding:16px}.exp-field{margin-bottom:12px}.exp-field label{display:block;font-size:12px;color:#64748b;margin-bottom:6px}.exp-field input{width:100%;height:40px;border:1px solid #cbd5e1;border-radius:6px;padding:0 10px;box-sizing:border-box;background:#fff}.exp-btn{height:38px;padding:0 15px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;color:#334155;cursor:pointer}.exp-btn.primary{background:#1877d2;color:#fff;border-color:#1877d2}.exp-list{width:100%;border-collapse:collapse}.exp-list th{background:#f8fafc;color:#64748b;font-size:11px;text-align:left;padding:11px 12px;border-bottom:1px solid #e2e8f0}.exp-list td{padding:11px 12px;border-bottom:1px solid #edf2f7;font-size:13px;color:#334155;vertical-align:middle}.exp-actions{display:flex;gap:6px;flex-wrap:wrap}.exp-inline-form{display:flex;gap:6px;align-items:center}.exp-inline-form input{height:34px;border:1px solid #cbd5e1;border-radius:5px;padding:0 8px;width:240px;box-sizing:border-box}.exp-danger{color:#b42318;border-color:#f1b5b0}.exp-empty{text-align:center;color:#64748b;padding:28px}
        @media(max-width:850px){.exp-master-grid{grid-template-columns:1fr}.exp-master-head{flex-direction:column}}
        </style>
        <div class="exp-master">
          <div class="exp-master-head"><div><h1>Expense Items</h1><p>Create and manage the items used on expense entries.</p></div><a class="exp-btn" href="<?=e(url('expense'))?>">← Back to Expenses</a></div>
          <div class="exp-tabs"><a class="exp-tab" href="<?=e(url('expense?tab=categories'))?>">Categories</a><a class="exp-tab active" href="<?=e(url('expense?tab=items'))?>">Items</a></div>
          <div class="exp-master-grid">
            <div class="exp-master-card"><div class="exp-master-card-head"><h2>ADD ITEM</h2></div><div class="exp-master-body"><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_expense_item"><div class="exp-field"><label>Expense Item Name *</label><input name="name" required maxlength="150" placeholder="e.g. Hosting"></div><button class="exp-btn primary" type="submit">+ Add Item</button></form></div></div>
            <div class="exp-master-card"><div class="exp-master-card-head"><h2>ITEMS</h2></div><div style="overflow:auto"><table class="exp-list"><thead><tr><th>NAME</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
            <?php foreach($masterItems as $it): ?>
              <tr><td><strong><?=e($it['name'])?></strong></td><td><?=((int)$it['active']===1)?'Active':'Inactive'?></td><td><div class="exp-actions"><form method="post" class="exp-inline-form"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="edit_expense_item"><input type="hidden" name="id" value="<?=$it['id']?>"><input name="name" value="<?=e($it['name'])?>" required><button class="exp-btn" type="submit">Save</button></form><form method="post" onsubmit="return confirm('Delete this expense item?');"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_expense_item"><input type="hidden" name="id" value="<?=$it['id']?>"><button class="exp-btn exp-danger" type="submit">Delete</button></form></div></td></tr>
            <?php endforeach; if(!$masterItems): ?><tr><td colspan="3" class="exp-empty">No expense items yet.</td></tr><?php endif; ?>
            </tbody></table></div></div>
          </div>
        </div>
        <?php page_end();exit;
    }

    /* Expense editor / saver.  v86 keeps the old accounting model but presents
       the entry screen as a full-page invoice-style form. */
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        try{
            $category=trim($_POST['category']??'');
            $expenseType=$_POST['expense_type']??'indirect';
            $date=$_POST['txn_date']??date('Y-m-d');
            $dueDate=($_POST['due_date']??'')!=='' ? $_POST['due_date'] : null;
            $notes=trim($_POST['notes']??'');
            $amount=round((float)($_POST['amount']??0),2);
            $editId=(int)($_POST['edit_id']??0);
            if($category==='') throw new RuntimeException('Expense category is required.');
            if(!in_array($expenseType,['direct','indirect'],true)) throw new RuntimeException('Invalid expense type.');

            /* v86 supports multiple item lines.  The total is calculated from
               the rows when present; otherwise the legacy amount field works. */
            $itemNames=$_POST['item_name']??[];
            $itemQty=$_POST['item_qty']??[];
            $itemPrice=$_POST['item_price']??[];
            $expenseRows=[]; $calculated=0;
            foreach($itemNames as $i=>$name){
                $name=trim((string)$name); $q=round((float)($itemQty[$i]??0),3); $pr=round((float)($itemPrice[$i]??0),2);
                if($name==='' && $q<=0 && $pr<=0) continue;
                if($name==='') continue;
                if($q<=0) $q=1;
                if($pr<0) $pr=0;
                $line=round($q*$pr,2); $calculated+= $line;
                $expenseRows[]=[$name,$q,$pr,$line];
            }
            if($calculated>0) $amount=round($calculated,2);
            if($amount<=0) throw new RuntimeException('Expense amount must be greater than zero.');

            $methods=$_POST['pay_method']??[];
            $pamount=$_POST['pay_amount']??[];
            $accounts=$_POST['pay_account']??[];
            $refs=$_POST['pay_reference']??($_POST['pay_ref']??[]);
            $dates=$_POST['pay_cheque_date']??[];
            $paid=0; $payments=[];
            foreach($methods as $i=>$rawMethod){$a=round((float)($pamount[$i]??0),2);if($a<=0)continue;[$m,$acct]=normalize_payment_method($pdo,$cid,(string)$rawMethod,trim($accounts[$i]??''));$ref=trim($refs[$i]??'');$cd=($dates[$i]??null)?:null;if($m==='cheque'&&$ref==='')throw new RuntimeException('Cheque number is required.');$paid+=$a;$payments[]=[$m,$a,$acct,$ref,$cd];}
            if($paid>$amount+0.01) throw new RuntimeException('Payment cannot be greater than expense amount.');
            $due=round($amount-$paid,2); $pdo=db(); $pdo->beginTransaction();

            if($editId>0){
                $q=$pdo->prepare('SELECT t.id,t.document_no FROM transactions t WHERE t.id=? AND t.company_id=? AND t.txn_type="expense" AND t.deleted_at IS NULL');
                $q->execute([$editId,$cid]); $existing=$q->fetch();
                if(!$existing) throw new RuntimeException('Expense not found.');
                $doc=$existing['document_no'];
                $pdo->prepare('UPDATE transactions SET txn_date=?,due_date=?,subtotal=?,total=?,paid=?,due=?,status=?,notes=? WHERE id=?')->execute([$date,$dueDate,$amount,$amount,$paid,$due,$due>0?'open':'final',$expenseType.' Expense: '.$category.'; '.$notes,$editId]);
                $pdo->prepare('UPDATE expense_details SET expense_type=?,category=? WHERE transaction_id=?')->execute([$expenseType,$category,$editId]);
                $pdo->prepare('DELETE FROM payment_lines WHERE transaction_id=?')->execute([$editId]);
                $pdo->prepare('DELETE FROM transaction_items WHERE transaction_id=?')->execute([$editId]);
                $pdo->prepare('DELETE FROM expense_item_links WHERE transaction_id=?')->execute([$editId]);
                $pdo->prepare('DELETE FROM ledger_entries WHERE transaction_id=?')->execute([$editId]);
                $tid=$editId;
                $auditAction='update';
            }else{
                $doc=next_document_in_transaction($pdo,$cid,'expense','EX-');
                $pdo->prepare('INSERT INTO transactions(company_id,txn_type,document_no,txn_date,due_date,subtotal,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,'expense',$doc,$date,$dueDate,$amount,$amount,$paid,$due,$u['currency_code'],$due>0?'open':'final',$expenseType.' Expense: '.$category.'; '.$notes,$u['id']]);
                $tid=(int)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO expense_details(transaction_id,company_id,expense_type,category) VALUES(?,?,?,?)')->execute([$tid,$cid,$expenseType,$category]);
                $auditAction='create';
            }
            foreach($payments as [$m,$a,$acct,$ref,$cd]){
                $pdo->prepare('INSERT INTO payment_lines(transaction_id,method,account_name,reference_no,cheque_date,amount,status) VALUES(?,?,?,?,?,?,?)')->execute([$tid,$m,$acct?:null,$ref?:null,$cd,$a,'completed']);
            }
            $itemInsert=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');
            $itemLookup=$pdo->prepare('SELECT id FROM items WHERE company_id=? AND name=? AND active=1 LIMIT 1');
            $expenseItemLookup=$pdo->prepare('SELECT id FROM expense_items WHERE company_id=? AND name=? AND active=1 LIMIT 1');
            $expenseItemLink=$pdo->prepare('INSERT INTO expense_item_links(transaction_id,expense_item_id,qty,unit_price,amount) VALUES(?,?,?,?,?)');
            $itemNotes=[];
            foreach($expenseRows as [$name,$q,$pr,$line]){
                $itemLookup->execute([$cid,$name]); $iid=(int)($itemLookup->fetchColumn()?:0);
                if($iid>0) $itemInsert->execute([$tid,$iid,$q,$pr,0,0,$line]);
                $expenseItemLookup->execute([$cid,$name]); $eid=(int)($expenseItemLookup->fetchColumn()?:0);
                if($eid>0) $expenseItemLink->execute([$tid,$eid,$q,$pr,$line]);
                if($iid===0 && $eid===0) $itemNotes[]=$name.' × '.$q.' @ '.$pr;
            }
            if($itemNotes){ $extra=' Items: '.implode(', ',$itemNotes); $pdo->prepare('UPDATE transactions SET notes=CONCAT(COALESCE(notes,""),?) WHERE id=?')->execute([$extra,$tid]); }
            $lines=[['6000','Expense - '.$category,$amount,0,$doc]];
            foreach($payments as [$m,$a,$acct]){$ac=payment_account_code($m,$acct);$lines[]=[$ac[0],$ac[1],0,$a,$doc];}
            if($due>0)$lines[]=['2100','Expense Payable',0,$due,$doc];
            post_ledger($pdo,$cid,$tid,$date,$lines);
            audit($auditAction,'transaction',$tid,['type'=>'expense','document'=>$doc,'category'=>$category,'expense_type'=>$expenseType,'amount'=>$amount,'paid'=>$paid]);
            $pdo->commit(); flash('success','Expense '.$doc.($editId?' updated':' saved').' successfully.'); redirect('expense');
        }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();flash('error',$e->getMessage());redirect($route==='expense-new'?'expense-new':'expense');}
    }

    /* v120: standard expense module UI. */
    $editId=(int)($_GET['edit']??0);
    if($route==='expense-new'){
        $cats=$pdo->prepare('SELECT id,name,expense_type FROM expense_categories WHERE company_id=? AND active=1 ORDER BY name');
        $cats->execute([$cid]); $categories=$cats->fetchAll(PDO::FETCH_ASSOC);
        $itemsQ=$pdo->prepare('SELECT id,name FROM expense_items WHERE company_id=? AND active=1 ORDER BY name');
        $itemsQ->execute([$cid]); $expenseItems=$itemsQ->fetchAll(PDO::FETCH_ASSOC);
        $edit=null; $editLines=[]; $editPayments=[];
        if($editId){
            $q=$pdo->prepare('SELECT t.*,ed.expense_type,ed.category FROM transactions t JOIN expense_details ed ON ed.transaction_id=t.id WHERE t.id=? AND t.company_id=? AND t.txn_type="expense" AND t.deleted_at IS NULL');
            $q->execute([$editId,$cid]); $edit=$q->fetch();
            if($edit){
                $qq=$pdo->prepare('SELECT eil.qty,eil.unit_price,ei.name item_name FROM expense_item_links eil JOIN expense_items ei ON ei.id=eil.expense_item_id WHERE eil.transaction_id=? ORDER BY eil.id');
                $qq->execute([$editId]); $editLines=$qq->fetchAll(PDO::FETCH_ASSOC);
                $pp=$pdo->prepare('SELECT method,account_name,reference_no,cheque_date,amount FROM payment_lines WHERE transaction_id=? ORDER BY id');
                $pp->execute([$editId]); $editPayments=$pp->fetchAll(PDO::FETCH_ASSOC);
            }
        }
        $rows=$editLines ?: [['item_name'=>'','qty'=>1,'unit_price'=>0]];
        $selectedCategory=(string)($edit['category']??'');
        $bankQ=$pdo->prepare('SELECT id,name,bank_name,account_number FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY name');$bankQ->execute([$cid]);$bankRows=$bankQ->fetchAll(PDO::FETCH_ASSOC);
        page_start('Add Expense');
        ?>
        <style>
        .std-expense{background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 2px 10px rgba(15,23,42,.05);overflow:hidden}
        .std-expense-head{display:flex;justify-content:space-between;align-items:flex-start;padding:22px 24px;border-bottom:1px solid #e5e7eb}
        .std-expense-head h1{margin:0;font-size:24px;color:#17324d}.std-expense-head p{margin:6px 0 0;color:#718096;font-size:13px}
        .std-expense-actions{display:flex;gap:8px}.std-btn{display:inline-flex;align-items:center;justify-content:center;height:38px;padding:0 16px;border-radius:7px;border:1px solid #cbd5e1;background:#fff;color:#334155;text-decoration:none;cursor:pointer;font-size:13px}.std-btn.primary{background:#1877d2;border-color:#1877d2;color:#fff}.std-btn.danger{color:#b42318;border-color:#fecaca}
        .std-expense-body{padding:20px 24px 90px}.std-grid3{display:grid;grid-template-columns:1.3fr 1fr 1fr;gap:16px}.std-grid4{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:16px}.std-field label{display:block;font-size:12px;font-weight:600;color:#64748b;margin:0 0 6px}.std-field input,.std-field select,.std-field textarea{width:100%;height:40px;border:1px solid #cbd5e1;border-radius:6px;padding:0 11px;font-size:14px;box-sizing:border-box;background:#fff;color:#1f2937}.std-field textarea{height:84px;padding-top:10px;resize:vertical}.std-section{margin-top:20px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden}.std-section-head{display:flex;justify-content:space-between;align-items:center;padding:13px 15px;background:#f8fafc;border-bottom:1px solid #e2e8f0}.std-section-head h2{font-size:14px;margin:0;color:#334155}.std-table{width:100%;border-collapse:collapse}.std-table th{font-size:11px;color:#64748b;text-align:left;background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:10px}.std-table td{padding:9px 10px;border-bottom:1px solid #edf2f7;vertical-align:middle}.std-table input,.std-table select{height:36px;width:100%;border:1px solid #cbd5e1;border-radius:5px;padding:0 9px;box-sizing:border-box;background:#fff}.std-table .num{width:96px}.std-table .amt{width:120px;text-align:right}.std-row-action{width:42px;text-align:center}.std-remove{width:30px;height:30px;border:1px solid #fecaca;background:#fff;border-radius:5px;color:#b42318;cursor:pointer}.std-totalbar{display:flex;justify-content:flex-end;align-items:center;gap:28px;padding:13px 15px;background:#fff}.std-totalbar span{color:#64748b;font-size:13px}.std-totalbar b{font-size:19px;color:#0f172a;min-width:130px;text-align:right}.std-pay-grid{display:grid;grid-template-columns:1.2fr 1fr 1.2fr 1fr;gap:12px;align-items:end}.std-summary{display:flex;justify-content:flex-end;gap:26px;padding:18px 0 0}.std-summary .box{min-width:150px;text-align:right}.std-summary .label{font-size:12px;color:#64748b}.std-summary .val{font-size:18px;font-weight:700;color:#0f172a;margin-top:5px}.std-summary .due{color:#b42318}.std-form-footer{position:sticky;bottom:0;display:flex;justify-content:flex-end;gap:9px;padding:14px 24px;background:rgba(255,255,255,.97);border-top:1px solid #e2e8f0}.std-help{font-size:12px;color:#64748b;margin-top:7px}
        .cash-head-actions-v150{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.cash-bank-transfer-modal-v150{display:none;position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.48);align-items:center;justify-content:center;padding:24px 18px;box-sizing:border-box;overflow-y:auto}.cash-bank-transfer-modal-v150.show{display:flex}.cash-move-btn-v150{border:0!important;border-radius:8px!important;min-height:38px;padding:10px 15px!important;font-weight:600;box-shadow:0 2px 6px rgba(20,110,190,.12)}.cash-deposit-v150{background:#168fe9!important;color:#fff!important}.cash-withdraw-v150{background:#fff!important;color:#1683ea!important;border:1px solid #cfe0ef!important}.cash-bank-transfer-modal-v150{padding-top:54px}.cash-bank-transfer-box-v150{width:min(550px,100%);background:#fff;border-radius:8px;box-shadow:0 18px 55px rgba(15,23,42,.28);overflow:hidden}.cash-bank-transfer-head-v150{display:flex;justify-content:space-between;align-items:center;padding:17px 20px;border-bottom:1px solid #e6eaf0}.cash-bank-transfer-head-v150 h2{font-size:18px;font-weight:500;margin:0;color:#315367}.cash-bank-transfer-body-v150{padding:22px 32px 18px}.cash-bank-transfer-grid-v150{display:grid;grid-template-columns:1fr 1fr;gap:20px 24px}.cash-bank-transfer-field-v150 label{display:block;font-size:12px;color:#68768a;margin:0 0 6px}.cash-bank-transfer-field-v150 select,.cash-bank-transfer-field-v150 input{width:100%;height:38px;box-sizing:border-box;border:1px solid #d5dbe4;border-radius:5px;background:#fff;color:#27313f;padding:0 10px;outline:0;font-size:14px}.cash-bank-transfer-field-v150 select:disabled{background:#f5f7f9;color:#6b7685;cursor:not-allowed}.cash-bank-transfer-full-v150{grid-column:1/-1}.cash-bank-transfer-foot-v150{padding:10px 32px 16px;display:flex;justify-content:flex-end;gap:8px}.cash-bank-transfer-box-v150 form{margin:0}.cash-bank-transfer-modal-v150 .bank-secondary-v122,.cash-bank-transfer-modal-v150 .bank-primary-v122{min-width:90px}
/* v154: Cash In Hand modal intentionally reuses the exact Bank Accounts transfer modal skin. */
#cashBankTransferModalV150{display:none;}
#cashBankTransferModalV150.show{display:flex;}
#cashBankTransferModalV150 .bank-transfer-box-v147{width:min(550px,calc(100vw - 36px));max-height:calc(100vh - 48px);overflow:auto;}
/* v153: force Cash Deposit/Withdraw to render as a top-level modal; avoids legacy bank-modal CSS conflicts. */
.cash-bank-transfer-modal-v150{display:none!important;position:fixed!important;inset:0!important;z-index:2147483640!important;align-items:center!important;justify-content:center!important;padding:24px 18px!important;box-sizing:border-box!important;background:rgba(15,23,42,.58)!important;}
.cash-bank-transfer-modal-v150.show{display:flex!important;}
.cash-bank-transfer-box-v150{position:relative!important;z-index:2147483641!important;width:min(550px,calc(100vw - 36px))!important;max-height:calc(100vh - 48px)!important;overflow:auto!important;background:#fff!important;border-radius:8px!important;box-shadow:0 18px 55px rgba(15,23,42,.30)!important;}
    @media(max-width:900px){.std-grid3,.std-grid4,.std-pay-grid{grid-template-columns:1fr 1fr}.std-expense-head{flex-direction:column;gap:14px}.std-expense-body{padding-bottom:100px}.std-table{min-width:820px}.std-section{overflow:auto}}
        @media(max-width:560px){.std-grid3,.std-grid4,.std-pay-grid{grid-template-columns:1fr}}
        </style>
        <div class="std-expense">
          <div class="std-expense-head">
            <div><h1><?= $edit ? 'Edit Expense' : 'New Expense' ?></h1><p>Record a business expense, payment and outstanding amount in one place.</p></div>
            <div class="std-expense-actions"><a class="std-btn" href="<?=e(url('expense'))?>">Back</a><button type="button" class="std-btn" onclick="window.print()">Print</button></div>
          </div>
          <form method="post" id="stdExpenseForm">
            <div class="std-expense-body">
              <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="edit_id" value="<?=$editId?>">
              <input type="hidden" name="amount" id="stdExpTotalInput" value="<?=e((string)($edit['total']??0))?>">
              <input type="hidden" name="expense_type" id="stdExpType" value="<?=e($edit['expense_type']??'indirect')?>">
              <div class="std-grid3">
                <div class="std-field"><label>Expense Category *</label><select name="category" id="stdExpCategory" required><option value="">Select category</option><?php foreach($categories as $c):?><option value="<?=e($c['name'])?>" data-type="<?=e($c['expense_type'])?>" <?=($selectedCategory===$c['name'])?'selected':''?>><?=e($c['name'])?> (<?=e(ucfirst($c['expense_type']))?>)</option><?php endforeach;?></select></div>
                <div class="std-field"><label>Expense Date *</label><input type="date" name="txn_date" value="<?=e($edit['txn_date']??date('Y-m-d'))?>" required></div>
                <div class="std-field"><label>Due Date</label><input type="date" name="due_date" value="<?=e($edit['due_date']??'')?>"></div>
              </div>
              <div class="std-grid4" style="margin-top:16px">
                <div class="std-field"><label>Expense No.</label><input value="<?=e($edit['document_no']??'Auto generated')?>" readonly></div>
                <div class="std-field"><label>Reference / Bill No.</label><input name="reference_no" value="" placeholder="Optional reference"></div>
                <div class="std-field"><label>Expense Type</label><input id="stdExpTypeLabel" value="<?=e(ucfirst($edit['expense_type']??'indirect'))?> Expense" readonly></div>
                <div class="std-field"><label>Notes</label><input name="notes" value="<?=e($edit ? preg_replace('/^.*?; /','',$edit['notes']??'') : '')?>" placeholder="Optional note"></div>
              </div>

              <div class="std-section">
                <div class="std-section-head"><h2>Expense Items</h2><button type="button" class="std-btn" id="stdAddExpenseRow">+ Add Row</button></div>
                <div style="overflow:auto">
                  <table class="std-table"><thead><tr><th style="width:46px">#</th><th>Expense Item / Description</th><th style="width:110px">QTY</th><th style="width:150px">PRICE / UNIT</th><th style="width:140px">AMOUNT</th><th style="width:50px"></th></tr></thead>
                  <tbody id="stdExpenseRows">
                  <?php foreach($rows as $i=>$r): ?><tr>
                    <td class="std-row-no"><?=($i+1)?></td>
                    <td><select name="item_name[]" required><option value="">Select expense item</option><?php foreach($expenseItems as $it):?><option value="<?=e($it['name'])?>" <?=((string)($r['item_name']??'')===$it['name'])?'selected':''?>><?=e($it['name'])?></option><?php endforeach;?></select></td>
                    <td><input class="stdQty" name="item_qty[]" type="number" min="0.01" step="0.01" value="<?=e((string)($r['qty']??1))?>"></td>
                    <td><input class="stdPrice" name="item_price[]" type="number" min="0" step="0.01" value="<?=e((string)($r['unit_price']??0))?>"></td>
                    <td><input class="stdAmount" type="number" step="0.01" value="0.00"></td>
                    <td class="std-row-action"><button type="button" class="std-remove" title="Remove row">×</button></td>
                  </tr><?php endforeach; ?>
                  </tbody></table>
                </div>
                <div class="std-totalbar"><span>Expense Total</span><b id="stdExpenseTotal">৳0.00</b></div>
              </div>

              <div class="std-section">
                <div class="std-section-head"><h2>Payment</h2><span class="std-help">Payment may be partial; remaining amount becomes due.</span></div>
                <div style="padding:15px">
                  <div id="stdPaymentRows">
                    <?php $payRow=$editPayments[0]??['method'=>'cash','account_name'=>'','reference_no'=>'','cheque_date'=>'','amount'=>0]; ?>
                    <div class="std-pay-grid std-payment-row">
                      <div class="std-field"><label>Payment Method</label><select name="pay_method[]" onchange="toggleExpensePayment(this)"><?=payment_select_options($bankRows,(string)($payRow['method']??'cash'),(string)($payRow['account_name']??''))?></select></div>
                      <div class="std-field std-cheque-ref" style="<?=($payRow['method']??'')==='cheque'?'':'display:none'?>"><label>Cheque No.</label><input name="pay_reference[]" value="<?=e($payRow['reference_no']??'')?>" placeholder="Cheque number"></div>
                      <div class="std-field"><label>Paid Amount</label><input class="stdPaid" name="pay_amount[]" type="number" min="0" step="0.01" value="<?=e((string)($payRow['amount']??0))?>"></div>
                    </div>
                  </div>
                  <button type="button" class="std-btn" id="stdAddPayment" style="margin-top:10px">+ Add Payment</button>
                </div>
              </div>

              <div class="std-summary">
                <div class="box"><div class="label">TOTAL</div><div class="val" id="stdSumTotal">৳0.00</div></div>
                <div class="box"><div class="label">PAID</div><div class="val" id="stdSumPaid">৳0.00</div></div>
                <div class="box"><div class="label">DUE</div><div class="val due" id="stdSumDue">৳0.00</div></div>
              </div>
            </div>
            <div class="std-form-footer"><a class="std-btn" href="<?=e(url('expense'))?>">Cancel</a><button class="std-btn primary" type="submit">Save Expense</button></div>
          </form>
        </div>
        <script>
        (function(){
          const rows=document.getElementById('stdExpenseRows'), totalEl=document.getElementById('stdExpenseTotal'), totalInput=document.getElementById('stdExpTotalInput');
          const sumTotal=document.getElementById('stdSumTotal'), sumPaid=document.getElementById('stdSumPaid'), sumDue=document.getElementById('stdSumDue');
          const money=n=>'৳'+Number(n||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
          function recalc(){let total=0;rows.querySelectorAll('tr').forEach((r,i)=>{r.querySelector('.std-row-no').textContent=i+1;const q=Math.max(0,parseFloat(r.querySelector('.stdQty')?.value||0));const p=Math.max(0,parseFloat(r.querySelector('.stdPrice')?.value||0));const a=Math.round(q*p*100)/100;total+=a;const amt=r.querySelector('.stdAmount');if(amt)amt.value=a.toFixed(2);});total=Math.round(total*100)/100;totalInput.value=total.toFixed(2);totalEl.textContent=money(total);sumTotal.textContent=money(total);let paid=0;document.querySelectorAll('.stdPaid').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));sumPaid.textContent=money(paid);sumDue.textContent=money(Math.max(0,total-paid));}
          function addRow(){const first=rows.querySelector('tr');const r=first.cloneNode(true);r.querySelectorAll('input').forEach(i=>{if(i.classList.contains('stdQty'))i.value='1';else if(i.classList.contains('stdPrice'))i.value='0';else if(i.classList.contains('stdAmount'))i.value='0.00';});const s=r.querySelector('select');if(s)s.selectedIndex=0;rows.appendChild(r);recalc();}
          function addPayment(){const wrap=document.getElementById('stdPaymentRows'), first=wrap.querySelector('.std-payment-row');const r=first.cloneNode(true);r.querySelectorAll('input').forEach(i=>i.value=i.classList.contains('stdPaid')?'0':'');r.querySelectorAll('select').forEach(s=>s.selectedIndex=0);wrap.appendChild(r);}
          rows.addEventListener('input',recalc);rows.addEventListener('change',recalc);rows.addEventListener('click',e=>{const b=e.target.closest('.std-remove');if(!b)return;const all=rows.querySelectorAll('tr');if(all.length===1){all[0].querySelector('select').selectedIndex=0;all[0].querySelector('.stdQty').value='1';all[0].querySelector('.stdPrice').value='0';}else b.closest('tr').remove();recalc();});
          document.getElementById('stdAddExpenseRow').addEventListener('click',addRow);document.getElementById('stdAddPayment').addEventListener('click',addPayment);document.getElementById('stdPaymentRows').addEventListener('input',recalc);
          const cat=document.getElementById('stdExpCategory'), type=document.getElementById('stdExpType'), label=document.getElementById('stdExpTypeLabel');cat.addEventListener('change',()=>{const t=cat.selectedOptions[0]?.dataset.type||'indirect';type.value=t;if(label)label.value=(t==='direct'?'Direct':'Indirect')+' Expense';});
          document.getElementById('stdExpenseForm').addEventListener('submit',e=>{recalc();const total=parseFloat(totalInput.value||0);let paid=0;document.querySelectorAll('.stdPaid').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(!cat.value){e.preventDefault();alert('Please select an expense category.');return;}if(total<=0){e.preventDefault();alert('Please add an expense item with a valid amount.');return;}if(paid>total+0.01){e.preventDefault();alert('Paid amount cannot be greater than the expense total.');return;}});
          const initial=cat.selectedOptions[0]?.dataset.type||type.value||'indirect';type.value=initial;label.value=(initial==='direct'?'Direct':'Indirect')+' Expense';recalc();
        })();
        </script>
        <?php page_end();exit;
    }

    if(isset($_GET['delete'])){
        $did=(int)$_GET['delete'];
        try{
            $q=$pdo->prepare('SELECT id,document_no FROM transactions WHERE id=? AND company_id=? AND txn_type="expense" AND deleted_at IS NULL');$q->execute([$did,$cid]);$tx=$q->fetch();
            if(!$tx) throw new RuntimeException('Expense not found.');
            $pdo->beginTransaction();$pdo->prepare('UPDATE transactions SET deleted_at=NOW() WHERE id=?')->execute([$did]);audit('delete','transaction',$did,['type'=>'expense','document'=>$tx['document_no']]);$pdo->commit();flash('success','Expense '.$tx['document_no'].' moved to Recycle Bin.');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
        redirect('expense');
    }

    $q=trim($_GET['q']??''); $from=trim($_GET['from']??date('Y-m-01')); $to=trim($_GET['to']??date('Y-m-d'));
    $where='';$params=[$cid];
    if($q!==''){ $where.=' AND (t.document_no LIKE ? OR ed.category LIKE ? OR t.notes LIKE ?)'; $like='%'.$q.'%'; array_push($params,$like,$like,$like); }
    if($from!==''){ $where.=' AND t.txn_date>=?'; $params[]=$from; }
    if($to!==''){ $where.=' AND t.txn_date<=?'; $params[]=$to; }
    $st=$pdo->prepare('SELECT t.id,t.document_no,t.txn_date,t.total,t.paid,t.due,t.status,ed.expense_type,ed.category,(SELECT GROUP_CONCAT(DISTINCT CASE WHEN pl.method="cash" THEN "Cash" WHEN pl.method="bank" THEN COALESCE(pl.account_name,"Bank") WHEN pl.method="cheque" THEN "Cheque" WHEN pl.method="mobile_banking" THEN "Mobile Banking" WHEN pl.method="card" THEN "Card" ELSE "Other" END ORDER BY pl.id SEPARATOR ", ") FROM payment_lines pl WHERE pl.transaction_id=t.id) payment_methods FROM transactions t JOIN expense_details ed ON ed.transaction_id=t.id WHERE t.company_id=? AND t.txn_type="expense" AND t.deleted_at IS NULL'.$where.' ORDER BY t.txn_date DESC,t.id DESC LIMIT 300');
    $st->execute($params); $expenses=$st->fetchAll(PDO::FETCH_ASSOC);
    $totMonth=(float)$pdo->query('SELECT COALESCE(SUM(total),0) FROM transactions WHERE company_id='.(int)$cid.' AND txn_type="expense" AND deleted_at IS NULL AND DATE_FORMAT(txn_date,"%Y-%m")="'.date('Y-m').'"')->fetchColumn();
    $paidMonth=(float)$pdo->query('SELECT COALESCE(SUM(paid),0) FROM transactions WHERE company_id='.(int)$cid.' AND txn_type="expense" AND deleted_at IS NULL AND DATE_FORMAT(txn_date,"%Y-%m")="'.date('Y-m').'"')->fetchColumn();
    $dueMonth=round($totMonth-$paidMonth,2);
    $catStmt=$pdo->prepare('SELECT id,name,expense_type FROM expense_categories WHERE company_id=? AND active=1 ORDER BY name');$catStmt->execute([$cid]);$categories=$catStmt->fetchAll(PDO::FETCH_ASSOC);
    $itemStmt=$pdo->prepare('SELECT id,name FROM expense_items WHERE company_id=? AND active=1 ORDER BY name');$itemStmt->execute([$cid]);$expenseItems=$itemStmt->fetchAll(PDO::FETCH_ASSOC);
    page_start('Expenses');
    ?>
    <style>
    .expense-standard{padding:4px 0 24px}.expense-standard-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}.expense-standard-head h1{margin:0;font-size:26px;color:#17324d}.expense-standard-head p{margin:5px 0 0;color:#718096;font-size:13px}.expense-head-actions{display:flex;gap:8px}.es-btn{height:38px;padding:0 15px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;color:#334155;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;font-size:13px}.es-btn.primary{background:#1877d2;color:#fff;border-color:#1877d2}.es-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:16px}.es-card{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:16px 18px}.es-card .k{font-size:12px;color:#64748b}.es-card .v{font-size:23px;font-weight:700;color:#0f172a;margin-top:4px}.es-card .v.due{color:#b42318}.es-toolbar{background:#fff;border:1px solid #e2e8f0;border-radius:9px;padding:14px;display:flex;justify-content:space-between;align-items:end;gap:10px;margin-bottom:14px}.es-filters{display:grid;grid-template-columns:1.4fr 150px 150px auto;gap:10px;flex:1}.es-field label{display:block;font-size:11px;color:#64748b;margin-bottom:5px}.es-field input{height:38px;width:100%;border:1px solid #cbd5e1;border-radius:6px;padding:0 10px;box-sizing:border-box}.es-panel{background:#fff;border:1px solid #e2e8f0;border-radius:9px;overflow:hidden}.es-panel-head{display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border-bottom:1px solid #e2e8f0}.es-panel-head h2{font-size:15px;margin:0;color:#334155}.es-table-wrap{overflow:auto}.es-table{width:100%;min-width:900px;border-collapse:collapse}.es-table th{font-size:11px;color:#64748b;text-align:left;background:#f8fafc;padding:11px 10px;border-bottom:1px solid #e2e8f0}.es-table td{font-size:13px;color:#334155;padding:12px 10px;border-bottom:1px solid #edf2f7}.es-table .amount{text-align:right;font-weight:600}.es-table .actions{white-space:nowrap}.es-action{display:inline-flex;align-items:center;justify-content:center;width:32px;height:30px;border:1px solid #cbd5e1;border-radius:5px;background:#fff;color:#475569;text-decoration:none;margin-left:4px}.es-status{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:600}.es-status.paid{background:#ecfdf3;color:#15803d}.es-status.open{background:#fff7ed;color:#c2410c}.es-mini{padding:16px;border-top:1px solid #e2e8f0}.es-mini h3{font-size:13px;margin:0 0 10px;color:#334155}.es-chips{display:flex;flex-wrap:wrap;gap:7px}.es-chip{font-size:12px;border:1px solid #e2e8f0;background:#f8fafc;padding:6px 9px;border-radius:999px;color:#475569}
    @media(max-width:900px){.es-cards{grid-template-columns:1fr}.es-toolbar{flex-direction:column;align-items:stretch}.es-filters{grid-template-columns:1fr 1fr}.expense-standard-head{align-items:flex-start;gap:10px;flex-direction:column}}
    </style>
    <div class="expense-standard">
      <div class="expense-standard-head"><div><h1>Expenses</h1><p>Record, track and manage your business expenses.</p></div><div class="expense-head-actions"><a class="es-btn" href="<?=e(url('expense?tab=categories'))?>">Manage Categories</a><a class="es-btn" href="<?=e(url('expense?tab=items'))?>">Manage Items</a><a class="es-btn primary" href="<?=e(url('expense-new'))?>">+ Add Expense</a></div></div>
      <div class="es-cards"><div class="es-card"><div class="k">THIS MONTH</div><div class="v"><?=money($totMonth)?></div></div><div class="es-card"><div class="k">PAID THIS MONTH</div><div class="v"><?=money($paidMonth)?></div></div><div class="es-card"><div class="k">DUE THIS MONTH</div><div class="v due"><?=money($dueMonth)?></div></div></div>
      <div class="es-toolbar"><form method="get" class="es-filters"><input type="hidden" name="q" value="<?=e($q)?>"><div class="es-field"><label>SEARCH</label><input name="q" value="<?=e($q)?>" placeholder="Expense no, category, notes"></div><div class="es-field"><label>FROM</label><input type="date" name="from" value="<?=e($from)?>"></div><div class="es-field"><label>TO</label><input type="date" name="to" value="<?=e($to)?>"></div><div><button class="es-btn primary" type="submit">Filter</button></div></form><a class="es-btn" href="<?=e(url('expense'))?>">Reset</a></div>
      <div class="es-panel"><div class="es-panel-head"><h2>Expense Transactions</h2><span style="font-size:12px;color:#64748b"><?=count($expenses)?> record(s)</span></div><div class="es-table-wrap"><table class="es-table"><thead><tr><th>DATE</th><th>EXPENSE NO.</th><th>CATEGORY</th><th>TYPE</th><th>PAYMENT</th><th>AMOUNT</th><th>PAID</th><th>DUE</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
      <?php foreach($expenses as $r): ?><tr><td><?=e(date('d/m/Y',strtotime($r['txn_date'])))?></td><td><b><?=e($r['document_no'])?></b></td><td><?=e($r['category'])?></td><td><?=e(ucfirst($r['expense_type']))?></td><td><?=e($r['payment_methods']?:'—')?></td><td class="amount"><?=money((float)$r['total'])?></td><td class="amount"><?=money((float)$r['paid'])?></td><td class="amount"><?=money((float)$r['due'])?></td><td><span class="es-status <?=((float)$r['due']>0)?'open':'paid'?>"><?=((float)$r['due']>0)?'Open':'Paid'?></span></td><td class="actions"><a class="es-action" href="<?=e(url('expense-new?edit='.(int)$r['id']))?>" title="Edit">✎</a><a class="es-action" href="<?=e(url('expense?delete='.(int)$r['id']))?>" onclick="return confirm('Move this expense to Recycle Bin?')" title="Delete">🗑</a></td></tr><?php endforeach; if(!$expenses):?><tr><td colspan="10" style="text-align:center;padding:28px;color:#64748b">No expense transactions found.</td></tr><?php endif; ?></tbody></table></div></div>
      <div class="es-panel" style="margin-top:14px"><div class="es-panel-head"><h2>Expense Setup</h2></div><div class="es-mini"><h3>Categories</h3><div class="es-chips"><?php foreach($categories as $c):?><span class="es-chip"><?=e($c['name'])?> · <?=e(ucfirst($c['expense_type']))?></span><?php endforeach;if(!$categories):?><span class="es-chip">No categories</span><?php endif;?></div></div><div class="es-mini"><h3>Expense Items</h3><div class="es-chips"><?php foreach($expenseItems as $it):?><span class="es-chip"><?=e($it['name'])?></span><?php endforeach;if(!$expenseItems):?><span class="es-chip">No expense items</span><?php endif;?></div></div></div>
    </div>
    <?php page_end();exit;
}

if($route==='cash'){
    $u=require_login(); $cid=(int)$u['company_id']; $pdo=db();

    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $action=$_POST['action']??'';

        try{
            if($action==='adjust_cash'){
                $opening=(float)($_POST['opening_balance']??0);
                $pdo->prepare('INSERT INTO cash_accounts(company_id,opening_balance,active) VALUES(?,?,1)
                               ON DUPLICATE KEY UPDATE opening_balance=VALUES(opening_balance),active=1')
                   ->execute([$cid,$opening]);
                flash('success','Cash opening balance updated.');
                redirect('cash');
            }

            if(in_array($action,['delete_cash','duplicate_cash'],true)){
                $entryId=(int)($_POST['entry_id']??0);
                if($entryId<=0) throw new RuntimeException('Invalid cash transaction.');

                $st=$pdo->prepare('SELECT le.*,t.id transaction_id,t.txn_type,t.document_no,t.deleted_at
                                   FROM ledger_entries le
                                   LEFT JOIN transactions t ON t.id=le.transaction_id
                                   WHERE le.id=? AND le.company_id=? AND le.account_code IN ("1000","1100")
                                   LIMIT 1');
                $st->execute([$entryId,$cid]);
                $entry=$st->fetch();
                if(!$entry) throw new RuntimeException('Cash transaction not found.');

                if($action==='delete_cash'){
                    if(!empty($entry['transaction_id'])){
                        $pdo->beginTransaction();
                        $pdo->prepare('UPDATE transactions SET deleted_at=NOW() WHERE id=? AND company_id=? AND deleted_at IS NULL')
                            ->execute([(int)$entry['transaction_id'],$cid]);
                        try{
                            $pdo->prepare('UPDATE transaction_items SET deleted_at=NOW() WHERE transaction_id=?')
                                ->execute([(int)$entry['transaction_id']]);
                        }catch(Throwable $ignore){}
                        audit('delete','transaction',(int)$entry['transaction_id'],[
                            'type'=>$entry['txn_type']??null,
                            'document_no'=>$entry['document_no']??null,
                            'reason'=>'Cash ledger transaction moved to Recycle Bin'
                        ]);
                        $pdo->commit();
                    }else{
                        // For standalone cash ledger entries, preserve audit history and remove the ledger row.
                        $pdo->beginTransaction();
                        $pdo->prepare('DELETE FROM ledger_entries WHERE id=? AND company_id=?')->execute([$entryId,$cid]);
                        audit('delete','ledger_entry',$entryId,['account_code'=>'1000','reason'=>'Cash ledger adjustment deleted']);
                        $pdo->commit();
                    }
                    flash('success','Cash transaction moved to Recycle Bin.');
                    redirect('cash');
                }

                // Duplicate: for a linked transaction, send it to the existing source document flow.
                if(!empty($entry['transaction_id'])){
                    redirect('cash?view='.(int)$entry['transaction_id'].'&duplicate=1');
                }
                throw new RuntimeException('This cash entry cannot be duplicated because it has no source transaction.');
            }

            if($action==='bank_to_bank_transfer'){
                $fromRef=trim((string)($_POST['from_account']??''));
                $toRef=trim((string)($_POST['to_account']??''));
                $amount=round((float)($_POST['amount']??0),2);
                $date=(string)($_POST['adjustment_date']??date('Y-m-d'));
                $notes=trim((string)($_POST['notes']??''));

                if($fromRef==='' || $toRef==='') throw new RuntimeException('Select both source and destination accounts.');
                if($fromRef===$toRef) throw new RuntimeException('Source and destination accounts must be different.');
                if($amount<=0) throw new RuntimeException('Amount must be greater than zero.');

                $fromType='cash'; $fromId=0; $fromName='Cash In Hand';
                $toType='cash'; $toId=0; $toName='Cash In Hand';

                if(strpos($fromRef,'bank:')===0){
                    $fromType='bank';
                    $fromId=(int)substr($fromRef,5);
                    $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');
                    $st->execute([$fromId,$cid]);
                    $from=$st->fetch(PDO::FETCH_ASSOC);
                    if(!$from) throw new RuntimeException('Source bank account not found.');
                    $fromName=$from['name'];
                }elseif($fromRef!=='cash'){
                    throw new RuntimeException('Invalid source account.');
                }

                if(strpos($toRef,'bank:')===0){
                    $toType='bank';
                    $toId=(int)substr($toRef,5);
                    $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');
                    $st->execute([$toId,$cid]);
                    $to=$st->fetch(PDO::FETCH_ASSOC);
                    if(!$to) throw new RuntimeException('Destination bank account not found.');
                    $toName=$to['name'];
                }elseif($toRef!=='cash'){
                    throw new RuntimeException('Invalid destination account.');
                }

                if($fromType==='bank' && $toType==='bank' && $fromId===$toId){
                    throw new RuntimeException('Source and destination accounts must be different.');
                }

                $sourceBalance=$fromType==='cash' ? company_cash_balance($cid) : bank_balance($cid,$fromId);
                if($sourceBalance + 0.0001 < $amount){
                    throw new RuntimeException('Insufficient balance in the source account.');
                }

                $pdo->beginTransaction();
                $type='bank_transfer';
                $doc=next_document_in_transaction($pdo,$cid,$type,'BT-');
                $summary='Transfer: '.$fromName.' → '.$toName;
                if($notes!=='') $summary.=' · '.$notes;
                $pdo->prepare('INSERT INTO transactions(company_id,txn_type,document_no,txn_date,due_date,subtotal,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$cid,$type,$doc,$date,null,$amount,$amount,$amount,0,$u['currency_code'],'final',$summary,$u['id']]);
                $tid=(int)$pdo->lastInsertId();

                $toCode=$toType==='cash' ? '1000' : '1010';
                $toLabel=$toType==='cash' ? 'Cash In Hand' : 'Bank - '.$toName;
                $fromCode=$fromType==='cash' ? '1000' : '1010';
                $fromLabel=$fromType==='cash' ? 'Cash In Hand' : 'Bank - '.$fromName;
                $lines=[
                    [$toCode,$toLabel,$amount,0,'From: '.$fromName.' · '.$doc],
                    [$fromCode,$fromLabel,0,$amount,'To: '.$toName.' · '.$doc],
                ];
                post_ledger($pdo,$cid,$tid,$date,$lines);
                audit('create','transaction',$tid,[
                    'type'=>$type,
                    'from'=>$fromRef,
                    'to'=>$toRef,
                    'from_name'=>$fromName,
                    'to_name'=>$toName,
                    'amount'=>$amount,
                    'document'=>$doc,
                    'source'=>'cash_page',
                ]);
                $pdo->commit();
                flash('success','Transfer recorded as '.$doc.'.');
                redirect('cash');
            }

            throw new RuntimeException('Invalid cash action.');
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error',$e->getMessage());
            redirect('cash');
        }
    }

    page_start('Cash In Hand');

    $st=$pdo->prepare('SELECT * FROM cash_accounts WHERE company_id=? AND active=1 LIMIT 1');
    $st->execute([$cid]);
    $c=$st->fetch();

    $bal=company_cash_balance($cid);
    $cashBanksQ=$pdo->prepare('SELECT id,name,bank_name FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY name');
    $cashBanksQ->execute([$cid]);
    $cashBanks=$cashBanksQ->fetchAll(PDO::FETCH_ASSOC);

    /* Cash movements: one row per cash ledger entry, newest first. */
    $q=$pdo->prepare('
        SELECT le.id, le.entry_date, le.debit, le.credit, le.memo,
               t.txn_type, t.document_no,
               p.name party_name
        FROM ledger_entries le
        LEFT JOIN transactions t ON t.id=le.transaction_id
        LEFT JOIN parties p ON p.id=t.party_id
        WHERE le.company_id=? AND le.account_code IN ("1000","1100")
          AND (t.deleted_at IS NULL OR t.id IS NULL)
        ORDER BY le.id DESC
        LIMIT 300
    ');
    $q->execute([$cid]);
    $cashRows=$q->fetchAll();

    $opening=(float)($c['opening_balance']??0);

    ?>
    <style id="cash-v119-style">
    /* v119: Cash In Hand visual redesign — scoped only to the Cash page. */
    .cash-page{
      background:#f3f6f9;
      margin:-20px -20px -40px;
      padding:18px 12px 40px;
      min-height:calc(100vh - 70px);
      color:#23384a;
    }
    .cash-page-head{
      display:flex;justify-content:space-between;align-items:flex-start;
      padding:8px 8px 16px;
      border-bottom:0;
    }
    .cash-page-head h1{
      margin:0 0 5px;font-size:24px;line-height:1.15;letter-spacing:.2px;
      font-weight:700;color:#1e3950;
    }
    .cash-current{
      font-size:18px;font-weight:700;color:#09b978;
      letter-spacing:.2px;
    }
    .cash-adjust-btn{
      margin-top:2px;border:0!important;border-radius:8px!important;
      padding:10px 15px!important;min-height:38px;
      box-shadow:0 2px 6px rgba(20,110,190,.18);
      font-weight:600;
    }
    .cash-transactions-panel{
      background:#fff!important;border:1px solid #d7e0e8!important;
      border-radius:5px!important;padding:0!important;
      box-shadow:0 2px 8px rgba(20,43,62,.06);
      overflow:visible!important;
    }
    .cash-panel-head{
      display:flex;justify-content:space-between;align-items:center;
      min-height:61px;padding:10px 12px 9px;
      border-bottom:1px solid #dce4ea;
      background:#fff;
    }
    .cash-panel-head h2{
      margin:0;font-size:14px;font-weight:500;color:#354c5e;
      letter-spacing:.1px;
    }
    .cash-toolbar{display:flex;align-items:center;gap:10px;}
    .cash-search-wrap{
      width:230px;height:34px;display:flex;align-items:center;
      border:1px solid #cfd8e1;background:#fff;border-radius:3px;
      color:#8a98a6;padding:0 9px;box-sizing:border-box;
    }
    .cash-search-wrap span{font-size:16px;line-height:1;margin-right:7px;}
    .cash-search-wrap input{
      width:100%;border:0!important;outline:0!important;background:transparent!important;
      box-shadow:none!important;padding:0!important;height:30px!important;
      font-size:13px;color:#2f4353;
    }
    .cash-table-wrap{overflow-x:auto!important;overflow-y:visible!important;padding:0 6px 6px;}
    .cash-ledger-table{
      width:100%;min-width:760px;border-collapse:separate!important;border-spacing:0;
      table-layout:fixed;font-size:13px;
    }
    .cash-ledger-table thead th{
      height:40px;padding:0 10px!important;
      background:#f5f7f9!important;color:#51697c!important;
      border-top:0!important;border-bottom:1px solid #dce3e9!important;
      font-size:11px!important;font-weight:500!important;text-transform:uppercase;
      letter-spacing:.15px;white-space:nowrap;
    }
    .cash-ledger-table thead th:nth-child(1){width:34px;text-align:center;}
    .cash-ledger-table thead th:nth-child(2){width:145px;}
    .cash-ledger-table thead th:nth-child(3){width:300px;}
    .cash-ledger-table thead th:nth-child(4){width:165px;}
    .cash-ledger-table thead th:nth-child(5){width:150px;text-align:right;}
    .cash-ledger-table thead th:nth-child(6){width:60px;text-align:center;}
    .cash-ledger-table tbody tr{background:#fff!important;}
    .cash-ledger-table tbody tr:nth-child(even){background:#fafbfc!important;}
    .cash-ledger-table tbody td{
      height:41px;padding:0 10px!important;
      border-bottom:1px solid #edf1f4!important;
      color:#344b5d!important;vertical-align:middle!important;
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .cash-ledger-table tbody td:nth-child(5){text-align:right;font-weight:600;}
    .cash-ledger-table tbody td:nth-child(6){text-align:center;overflow:visible!important;}
    .cash-dot{display:inline-block;width:8px;height:8px;border-radius:50%;vertical-align:middle;}
    .cash-dot.in{background:#59d989;}.cash-dot.out{background:#ef7777;}
    .cash-in{color:#08ba78!important;}.cash-out{color:#e45d67!important;}
    .cash-action-v83{position:relative;overflow:visible!important;}
    .cash-action-button-v83{
      width:30px!important;height:30px!important;border:0!important;background:transparent!important;
      color:#435667!important;border-radius:4px!important;font-size:18px!important;
      line-height:28px!important;padding:0!important;cursor:pointer;
    }
    .cash-action-button-v83:hover{background:#edf3f7!important;}
    .cash-action-menu-v83{
      position:fixed!important;display:none;min-width:155px;
      padding:5px!important;background:#fff!important;color:#24384a!important;
      border:1px solid #d7e1e8!important;border-radius:7px!important;
      box-shadow:0 12px 30px rgba(20,38,54,.18)!important;
      z-index:2147483647!important;
    }
    .cash-action-menu-v83.cash-open{display:block!important;}
    .cash-action-menu-v83 a,.cash-action-menu-v83 button{
      display:block;width:100%;box-sizing:border-box;text-align:left;
      padding:8px 10px!important;border:0!important;background:#fff!important;
      color:#31485a!important;text-decoration:none!important;font:inherit;
      border-radius:4px;cursor:pointer;font-size:12px!important;
    }
    .cash-action-menu-v83 a:hover,.cash-action-menu-v83 button:hover{background:#f1f5f8!important;}
    .cash-action-menu-v83 form{margin:0!important;}
    .cash-adjust-modal{max-width:460px!important;border-radius:10px!important;overflow:hidden;}
    @media(max-width:900px){
      .cash-page{margin:-12px -12px -30px;padding:12px 8px 30px;}
      .cash-page-head h1{font-size:21px;}
      .cash-search-wrap{width:200px;}
    }
    @media(max-width:650px){
      .cash-page-head{gap:12px;align-items:flex-start;}
      .cash-adjust-btn{white-space:nowrap;}
      .cash-panel-head{gap:10px;align-items:flex-start;flex-direction:column;}
      .cash-search-wrap{width:100%;}
      .cash-toolbar{width:100%;}
    }
    </style>
    <div class="cash-page">
      <div class="cash-page-head">
        <div>
          <h1>CASH IN HAND</h1>
          <div class="cash-current"><?=money($bal)?></div>
        </div>
        <div class="cash-head-actions-v150"><button class="btn cash-move-btn-v150 cash-deposit-v150" type="button" onclick="openCashBankTransferV150('deposit')">Deposit</button><button class="btn cash-move-btn-v150 cash-withdraw-v150" type="button" onclick="openCashBankTransferV150('withdraw')">Withdraw</button><button class="btn primary cash-adjust-btn" type="button" onclick="openModal('cashModal')">☷ Adjust Cash</button></div>
      </div>

      <div class="panel cash-transactions-panel">
        <div class="cash-panel-head">
          <h2>TRANSACTIONS</h2>
          <div class="cash-toolbar">
            <div class="cash-search-wrap">
              <span>⌕</span>
              <input id="cashSearch" type="search" placeholder="Search" oninput="filterCashRows(this.value)">
            </div>
          </div>
        </div>

        <div class="table-wrap cash-table-wrap">
          <table class="cash-ledger-table">
            <thead>
              <tr>
                <th></th>
                <th>TYPE</th>
                <th>NAME</th>
                <th>DATE</th>
                <th>AMOUNT</th>
                <th>ACTION</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach($cashRows as $r):
                $tt=(string)($r['txn_type']??'');
                $label=$tt!==''?ucwords(str_replace('_',' ',$tt)):'Cash Adjustment';
                $name=(string)($r['party_name']??'');
                if($name==='')$name=(string)($r['memo']??'Cash');
                $amount=(float)$r['debit']-(float)$r['credit'];
                $positive=$amount>=0;
                $dateRaw=(string)($r['entry_date']??'');
                $dateLabel='—';
                if($dateRaw!==''){
                    $ts=strtotime($dateRaw);
                    if($ts!==false)$dateLabel=date('d/m/Y, h:i A',$ts);
                }
                $searchText=strtolower($label.' '.$name.' '.$dateLabel.' '.$amount);
              ?>
              <tr data-cash-search="<?=e($searchText)?>">
                <td><span class="cash-dot <?=$positive?'in':'out'?>"></span></td>
                <td><?=e($label)?></td>
                <td><?=e($name)?></td>
                <td><?=e($dateLabel)?></td>
                <td class="<?=$positive?'cash-in':'cash-out'?>"><?=money(abs($amount))?></td>
                <td class="cash-action-v83">
  <button type="button" class="cash-action-button-v83" aria-haspopup="true" aria-expanded="false" title="Actions">
    ⋮
  </button>
  <div class="cash-action-menu-v83">
    <a href="<?=e(url('cash?view='.(int)$r['id']))?>">View/Edit</a>
    <form method="post" style="margin:0;">
      <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="action" value="delete_cash">
      <input type="hidden" name="entry_id" value="<?=$r['id']?>">
      <button type="submit" onclick="return confirm('Delete this cash transaction? It will move to Recycle Bin.');">Delete</button>
    </form>
    <form method="post" style="margin:0;">
      <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="action" value="duplicate_cash">
      <input type="hidden" name="entry_id" value="<?=$r['id']?>">
      <button type="submit">Duplicate</button>
    </form>
    <a href="<?=e(url('cash?print='.(int)$r['id']))?>">Print</a>
  </div>
</td>
              </tr>
              <?php endforeach; ?>
              <?php if(!$cashRows): ?>
                <tr><td colspan="6" class="subtle">No cash transactions found.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="bank-modal-v122 bank-transfer-modal-v147" id="cashBankTransferModalV150" onclick="if(event.target===this)closeCashBankTransferV150()" aria-hidden="true"><div class="bank-transfer-box-v147"><div class="bank-transfer-head-v147"><h2 id="cashBankTransferTitleV150">Deposit</h2><button type="button" class="bank-modal-close-v122" onclick="closeCashBankTransferV150()">×</button></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="bank_to_bank_transfer"><input type="hidden" name="from_account" id="cashBankFromHiddenV150" value="cash"><input type="hidden" name="to_account" id="cashBankToHiddenV150" value=""><div class="bank-transfer-body-v147"><div class="bank-transfer-grid-v147"><div class="bank-transfer-field-v147"><label>From</label><select id="cashBankFromV150" required disabled><option value="cash">Cash In Hand</option><?php foreach($cashBanks as $b): ?><option value="bank:<?=e((string)$b['id'])?>"><?=e($b['name'])?></option><?php endforeach; ?></select></div><div class="bank-transfer-field-v147"><label>To</label><select id="cashBankToV150" required><option value="">Select bank account</option><?php foreach($cashBanks as $b): ?><option value="bank:<?=e((string)$b['id'])?>"><?=e($b['name'])?></option><?php endforeach; ?></select></div><div class="bank-transfer-field-v147"><label>Amount</label><input type="number" name="amount" min="0.01" step="0.01" placeholder="Amount" required onwheel="this.blur()"></div><div class="bank-transfer-field-v147"><label>Adjustment Date</label><input type="date" name="adjustment_date" value="<?=e(date('Y-m-d'))?>" required></div><div class="bank-transfer-field-v147 bank-transfer-full-v147"><label>Add Description</label><input type="text" name="notes" maxlength="255" placeholder="Optional"></div></div></div><div class="bank-transfer-foot-v147"><button type="button" class="bank-secondary-v122" onclick="closeCashBankTransferV150()">Cancel</button><button class="bank-primary-v122">Save</button></div></form></div></div>

      <div class="modal-backdrop" id="cashModal" onclick="if(event.target===this)closeModal('cashModal')">
        <div class="modal cash-adjust-modal">
          <div class="modal-head">
            <h2>Adjust Cash</h2>
            <button class="close" type="button" onclick="closeModal('cashModal')">×</button>
          </div>
          <form method="post">
            <div class="form-body">
              <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="action" value="adjust_cash">
              <div class="form-group">
                <label>Opening Balance</label>
                <input type="number" step="0.01" name="opening_balance" value="<?=e((string)$opening)?>">
              </div>
            </div>
            <div class="form-footer">
              <button type="button" class="btn" onclick="closeModal('cashModal')">Cancel</button>
              <button type="submit" class="btn primary">Save</button>
            </div>
          </form>
        </div>
      </div>

      
<script>
(function(){
  function closeAll(except){
    document.querySelectorAll('.cash-action-menu-v83.cash-open').forEach(function(menu){
      if(menu !== except){
        menu.classList.remove('cash-open');
        var btn = menu.parentElement.querySelector('.cash-action-button-v83');
        if(btn) btn.setAttribute('aria-expanded','false');
      }
    });
  }

  document.addEventListener('click', function(e){
    var btn = e.target.closest('.cash-action-button-v83');
    if(btn){
      e.preventDefault();
      e.stopPropagation();
      var menu = btn.parentElement.querySelector('.cash-action-menu-v83');
      if(!menu) return;

      var isOpen = menu.classList.contains('cash-open');
      closeAll(null);

      if(!isOpen){
        menu.classList.add('cash-open');
        btn.setAttribute('aria-expanded','true');

        var rect = btn.getBoundingClientRect();
        var menuWidth = 160;
        var left = rect.right + 8;
        var top = rect.top;

        if(left + menuWidth > window.innerWidth - 8){
          left = Math.max(8, rect.left - menuWidth - 8);
        }

        var menuHeight = menu.offsetHeight || 170;
        if(top + menuHeight > window.innerHeight - 8){
          top = Math.max(8, window.innerHeight - menuHeight - 8);
        }

        menu.style.left = Math.round(left) + 'px';
        menu.style.top = Math.round(top) + 'px';
      }
      return;
    }

    if(e.target.closest('.cash-action-menu-v83')) return;
    closeAll(null);
  }, true);

  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') closeAll(null);
  });
})();
</script>

<?php page_end(); exit;
}
if($route==='cheques'){
    $u=require_login();$cid=(int)$u['company_id'];page_start('Cheques');$q=db()->prepare("SELECT pl.*,t.document_no,t.txn_date,t.txn_type FROM payment_lines pl JOIN transactions t ON t.id=pl.transaction_id WHERE t.company_id=? AND pl.method='cheque' AND t.deleted_at IS NULL ORDER BY pl.id DESC");$q->execute([$cid]);$rows=$q->fetchAll();?><div class="page-title"><div><h1>Cheques</h1><p>Cheque receipts and payments</p></div></div><div class="panel table-wrap"><table><thead><tr><th>DATE</th><th>DOCUMENT</th><th>TYPE</th><th>CHEQUE NO.</th><th>CHEQUE DATE</th><th>AMOUNT</th><th>STATUS</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['txn_date'])?></td><td><?=e($r['document_no'])?></td><td><?=e(str_replace('_',' ',ucfirst($r['txn_type'])))?></td><td><?=e($r['reference_no']??'-')?></td><td><?=e($r['cheque_date']??'-')?></td><td><?=money((float)$r['amount'])?></td><td><?=e(ucfirst($r['status']))?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="7" class="subtle">No cheques found.</td></tr><?php endif;?></tbody></table></div><?php page_end();exit;
}

if($route==='loans'){
    $u=require_login();$cid=(int)$u['company_id'];
    if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();try{$name=trim($_POST['name']??'');$lender=trim($_POST['lender']??'');$opening=(float)($_POST['opening_balance']??0);if($name==='')throw new RuntimeException('Loan account name is required.');db()->prepare('INSERT INTO loan_accounts(company_id,name,lender,opening_balance,currency_code,active) VALUES(?,?,?,?,?,1)')->execute([$cid,$name,$lender,$opening,$u['currency_code']]);flash('success','Loan account added.');}catch(Throwable $e){flash('error',$e->getMessage());}redirect('loans');}
    page_start('Loan Accounts');$q=db()->prepare('SELECT * FROM loan_accounts WHERE company_id=? AND active=1 ORDER BY name');$q->execute([$cid]);$loans=$q->fetchAll();?><div class="page-title"><div><h1>Loan Accounts</h1><p>Track loans received by the business</p></div><button class="btn primary" onclick="openModal('loanModal')">⊕ Add Loan Account</button></div><div class="panel table-wrap"><table><thead><tr><th>NAME</th><th>LENDER</th><th>OPENING BALANCE</th><th>STATUS</th></tr></thead><tbody><?php foreach($loans as $r):?><tr><td><?=e($r['name'])?></td><td><?=e($r['lender']??'')?></td><td><?=money((float)$r['opening_balance'])?></td><td>Active</td></tr><?php endforeach;if(!$loans):?><tr><td colspan="4" class="subtle">No loan accounts yet.</td></tr><?php endif;?></tbody></table></div><div class="modal-backdrop" id="loanModal" onclick="if(event.target===this)closeModal('loanModal')"><div class="modal"><div class="modal-head"><h2>Add Loan Account</h2><button class="close" onclick="closeModal('loanModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="grid2"><div class="form-group"><label>Loan Account Name*</label><input name="name" required></div><div class="form-group"><label>Lender</label><input name="lender"></div><div class="form-group"><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="0"></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('loanModal')">Cancel</button><button class="btn primary">Save</button></div></form></div></div><?php page_end();exit;
}

if($route==='bank-accounts'){
    $u=require_login();
    $cid=(int)$u['company_id'];
    $pdo=db();
    $selectedId=(int)($_GET['select']??0);
    $editId=(int)($_GET['edit']??0);
    $search=trim((string)($_GET['q']??''));

    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $action=(string)($_POST['action']??'');
        try{
            if($action==='save_bank_account'){
                $name=trim((string)($_POST['name']??''));
                $bankName=trim((string)($_POST['bank_name']??''));
                $accountNo=trim((string)($_POST['account_number']??''));
                $opening=(float)($_POST['opening_balance']??0);
                if($name==='') throw new RuntimeException('Account name is required.');
                $pdo->prepare('INSERT INTO bank_accounts(company_id,name,bank_name,account_number,opening_balance,currency_code,active) VALUES(?,?,?,?,?,?,1)')->execute([$cid,$name,$bankName,$accountNo,$opening,$u['currency_code']]);
                $newId=(int)$pdo->lastInsertId();
                audit('create','bank_account',$newId,['name'=>$name,'bank_name'=>$bankName,'account_number'=>$accountNo,'opening_balance'=>$opening]);
                flash('success','Bank account added successfully.');
                redirect('bank-accounts?select='.$newId);
            }
            if($action==='update_bank_account'){
                $id=(int)($_POST['id']??0);
                $name=trim((string)($_POST['name']??''));
                $bankName=trim((string)($_POST['bank_name']??''));
                $accountNo=trim((string)($_POST['account_number']??''));
                $opening=(float)($_POST['opening_balance']??0);
                if($id<=0||$name==='') throw new RuntimeException('Invalid bank account.');
                $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$id,$cid]); $old=$st->fetch();
                if(!$old) throw new RuntimeException('Bank account not found.');
                $usage=$pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE company_id=? AND account_name=?');
                $usage->execute([$cid,'Bank - '.$old['name']]);
                $hasLedger=(int)$usage->fetchColumn()>0;
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE bank_accounts SET name=?,bank_name=?,account_number=?,opening_balance=?,currency_code=? WHERE id=? AND company_id=?')->execute([$name,$bankName,$accountNo,$opening,$u['currency_code'],$id,$cid]);
                if($hasLedger && $old['name']!==$name){
                    $pdo->prepare('UPDATE ledger_entries SET account_name=? WHERE company_id=? AND account_name=?')->execute(['Bank - '.$name,$cid,'Bank - '.$old['name']]);
                    $pdo->prepare('UPDATE payment_lines SET account_name=? WHERE account_name=? AND transaction_id IN (SELECT id FROM transactions WHERE company_id=?)')->execute([$name,$old['name'],$cid]);
                }
                $pdo->commit();
                audit('update','bank_account',$id,['name'=>$name,'bank_name'=>$bankName,'account_number'=>$accountNo,'opening_balance'=>$opening,'renamed'=>($old['name']!==$name)]);
                flash('success','Bank account updated successfully.');
                redirect('bank-accounts?select='.$id);
            }
            if($action==='delete_bank_account'){
                $id=(int)($_POST['id']??0);
                $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? LIMIT 1');$st->execute([$id,$cid]);$bank=$st->fetch();
                if(!$bank) throw new RuntimeException('Bank account not found.');
                $use=$pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE company_id=? AND account_name=?');$use->execute([$cid,'Bank - '.$bank['name']]);
                $ledgerUsed=(int)$use->fetchColumn();
                $use2=$pdo->prepare('SELECT COUNT(*) FROM payment_lines pl JOIN transactions t ON t.id=pl.transaction_id WHERE t.company_id=? AND pl.account_name=?');$use2->execute([$cid,$bank['name']]);
                $paymentUsed=(int)$use2->fetchColumn();
                if($ledgerUsed>0||$paymentUsed>0) throw new RuntimeException('This bank account has transactions and cannot be deleted.');
                $pdo->prepare('DELETE FROM bank_accounts WHERE id=? AND company_id=?')->execute([$id,$cid]);
                audit('delete','bank_account',$id,['name'=>$bank['name']]);
                flash('success','Bank account deleted.');
                redirect('bank-accounts');
            }
            if($action==='bank_cash_move'){
                $id=(int)($_POST['bank_id']??0);
                $direction=(string)($_POST['direction']??'deposit');
                $amount=round((float)($_POST['amount']??0),2);
                $date=(string)($_POST['txn_date']??date('Y-m-d'));
                $notes=trim((string)($_POST['notes']??''));
                if(!in_array($direction,['deposit','withdraw'],true)) throw new RuntimeException('Invalid transaction type.');
                if($amount<=0) throw new RuntimeException('Amount must be greater than zero.');
                $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$st->execute([$id,$cid]);$bank=$st->fetch();
                if(!$bank) throw new RuntimeException('Bank account not found.');
                if($direction==='withdraw' && company_cash_balance($cid)+0.01 < $amount) throw new RuntimeException('Cash in Hand is not sufficient for this withdrawal.');
                $pdo->beginTransaction();
                $type=$direction==='deposit'?'bank_deposit':'bank_withdraw';
                $prefix=$direction==='deposit'?'BD-':'BW-';
                $doc=next_document_in_transaction($pdo,$cid,$type,$prefix);
                $label=$direction==='deposit'?'Cash Deposit to '.$bank['name']:'Cash Withdrawal from '.$bank['name'];
                $pdo->prepare('INSERT INTO transactions(company_id,txn_type,document_no,txn_date,due_date,subtotal,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$type,$doc,$date,null,$amount,$amount,$amount,0,$u['currency_code'],'final',$label.($notes?' · '.$notes:''),$u['id']]);
                $tid=(int)$pdo->lastInsertId();
                if($direction==='deposit'){
                    post_ledger($pdo,$cid,$tid,$date,[['1010','Bank - '.$bank['name'],$amount,0,$doc],['1000','Cash In Hand',0,$amount,$doc]]);
                }else{
                    post_ledger($pdo,$cid,$tid,$date,[['1000','Cash In Hand',$amount,0,$doc],['1010','Bank - '.$bank['name'],0,$amount,$doc]]);
                }
                audit('create','transaction',$tid,['type'=>$type,'bank_id'=>$id,'bank'=>$bank['name'],'amount'=>$amount,'document'=>$doc]);
                $pdo->commit();
                flash('success',($direction==='deposit'?'Deposit':'Withdraw').' recorded as '.$doc.'.');
                redirect('bank-accounts?select='.$id);
            }
            if($action==='bank_to_bank_transfer'){
                $fromRef=trim((string)($_POST['from_account']??''));
                $toRef=trim((string)($_POST['to_account']??''));
                $amount=round((float)($_POST['amount']??0),2);
                $date=(string)($_POST['adjustment_date']??date('Y-m-d'));
                $notes=trim((string)($_POST['notes']??''));
                if($fromRef==='' || $toRef==='') throw new RuntimeException('Select both source and destination accounts.');
                if($fromRef===$toRef) throw new RuntimeException('Source and destination accounts must be different.');
                if($amount<=0) throw new RuntimeException('Amount must be greater than zero.');

                $fromType='cash'; $fromId=0; $fromName='Cash In Hand';
                $toType='cash'; $toId=0; $toName='Cash In Hand';
                if(strpos($fromRef,'bank:')===0){
                    $fromType='bank'; $fromId=(int)substr($fromRef,5);
                    $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$st->execute([$fromId,$cid]);$from=$st->fetch(PDO::FETCH_ASSOC);
                    if(!$from) throw new RuntimeException('Source bank account not found.');
                    $fromName=$from['name'];
                }elseif($fromRef!=='cash'){
                    throw new RuntimeException('Invalid source account.');
                }
                if(strpos($toRef,'bank:')===0){
                    $toType='bank'; $toId=(int)substr($toRef,5);
                    $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$st->execute([$toId,$cid]);$to=$st->fetch(PDO::FETCH_ASSOC);
                    if(!$to) throw new RuntimeException('Destination bank account not found.');
                    $toName=$to['name'];
                }elseif($toRef!=='cash'){
                    throw new RuntimeException('Invalid destination account.');
                }
                if($fromType==='bank' && $toType==='bank' && $fromId===$toId) throw new RuntimeException('Source and destination accounts must be different.');

                $sourceBalance=$fromType==='cash' ? company_cash_balance($cid) : bank_balance($cid,$fromId);
                if($sourceBalance + 0.0001 < $amount){
                    throw new RuntimeException('Insufficient balance in the source account.');
                }

                $pdo->beginTransaction();
                $type='bank_transfer';
                $doc=next_document_in_transaction($pdo,$cid,$type,'BT-');
                $summary='Transfer: '.$fromName.' → '.$toName;
                if($notes!=='') $summary.=' · '.$notes;
                $pdo->prepare('INSERT INTO transactions(company_id,txn_type,document_no,txn_date,due_date,subtotal,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$cid,$type,$doc,$date,null,$amount,$amount,$amount,0,$u['currency_code'],'final',$summary,$u['id']]);
                $tid=(int)$pdo->lastInsertId();
                $lines=[];
                $toCode=$toType==='cash' ? '1000' : '1010';
                $toLabel=$toType==='cash' ? 'Cash In Hand' : 'Bank - '.$toName;
                $fromCode=$fromType==='cash' ? '1000' : '1010';
                $fromLabel=$fromType==='cash' ? 'Cash In Hand' : 'Bank - '.$fromName;
                $lines[] = [$toCode,$toLabel,$amount,0,'From: '.$fromName.' · '.$doc];
                $lines[] = [$fromCode,$fromLabel,0,$amount,'To: '.$toName.' · '.$doc];
                post_ledger($pdo,$cid,$tid,$date,$lines);
                audit('create','transaction',$tid,['type'=>$type,'from'=>$fromRef,'to'=>$toRef,'from_name'=>$fromName,'to_name'=>$toName,'amount'=>$amount,'document'=>$doc]);
                $pdo->commit();
                flash('success','Transfer recorded as '.$doc.'.');
                redirect('bank-accounts?select='.($selectedId?:$fromId));
            }
            throw new RuntimeException('Unsupported action.');
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error',$e->getMessage());
            redirect('bank-accounts'.($selectedId?'?select='.$selectedId:''));
        }
    }

    $banksQ=$pdo->prepare('SELECT * FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY name');
    $banksQ->execute([$cid]); $banks=$banksQ->fetchAll(PDO::FETCH_ASSOC);
    if(!$banks){
        $selectedId=0;
    }elseif($selectedId<=0 || !array_filter($banks,fn($b)=>(int)$b['id']===$selectedId)){
        $selectedId=(int)$banks[0]['id'];
    }
    $selected=null;
    foreach($banks as $b){if((int)$b['id']===$selectedId){$selected=$b;break;}}

    $editBank=null;
    if($editId>0){
        $q=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$q->execute([$editId,$cid]);$editBank=$q->fetch(PDO::FETCH_ASSOC)?:null;
    }

    $transactions=[];
    if($selected){
        $sql='SELECT le.id,le.entry_date,le.debit,le.credit,le.memo,t.document_no,t.txn_type,t.txn_date,t.notes,p.name party_name
              FROM ledger_entries le
              JOIN transactions t ON t.id=le.transaction_id AND t.company_id=le.company_id
              LEFT JOIN parties p ON p.id=t.party_id
              WHERE le.company_id=? AND le.account_name=? AND t.deleted_at IS NULL';
        $args=[$cid,'Bank - '.$selected['name']];
        if($search!==''){
            $sql.=' AND (t.document_no LIKE ? OR t.txn_type LIKE ? OR COALESCE(p.name,"") LIKE ? OR COALESCE(t.notes,"") LIKE ? OR COALESCE(le.memo,"") LIKE ?)';
            $like='%'.$search.'%'; array_push($args,$like,$like,$like,$like,$like);
        }
        $sql.=' ORDER BY le.entry_date DESC, le.id DESC LIMIT 500';
        $q=$pdo->prepare($sql);$q->execute($args);$transactions=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    $selectedBalance=$selected?bank_balance($cid,(int)$selected['id']):0.0;

    $viewCount=[];
    foreach($banks as $b){
        $q=$pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE company_id=? AND account_name=?');$q->execute([$cid,'Bank - '.$b['name']]);
        $ledgerCount=(int)$q->fetchColumn();
        $q=$pdo->prepare('SELECT COUNT(*) FROM payment_lines pl JOIN transactions t ON t.id=pl.transaction_id WHERE t.company_id=? AND pl.account_name=?');$q->execute([$cid,$b['name']]);
        $viewCount[(int)$b['id']]=$ledgerCount+(int)$q->fetchColumn();
    }

    page_start('Bank Account');
    ?>
    <style>
      .bank-page-v122{height:calc(100vh - 80px);min-height:520px;display:grid;grid-template-columns:420px minmax(0,1fr);gap:10px;overflow:hidden;margin:-1px -2px -2px}
      .bank-left-v122,.bank-right-v122{background:#fff;border:1px solid #dfe5ec;min-width:0;min-height:0;box-shadow:0 1px 4px rgba(20,33,48,.06)}
      .bank-left-v122{display:flex;flex-direction:column;overflow:hidden}
      .bank-left-top-v122{padding:16px 14px;border-bottom:1px solid #e4e8ed;display:flex;justify-content:flex-end;align-items:center;background:#fff}
      .bank-add-v122{background:#f7a61a;border:1px solid #f7a61a;color:#fff;border-radius:22px;padding:10px 16px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px}
      .bank-add-v122:hover{filter:brightness(.98)}
      .bank-list-head-v122{display:grid;grid-template-columns:1fr 110px 30px;padding:11px 14px;background:#f8fafc;border-bottom:1px solid #dfe5ec;color:#596779;font-size:12px;font-weight:700}
      .bank-list-v122{overflow:auto;min-height:0}
      .bank-row-v122{display:grid;grid-template-columns:1fr 110px 30px;align-items:center;padding:14px 12px;border-bottom:1px solid #e8edf2;min-height:72px;cursor:pointer;position:relative}
      .bank-row-v122:hover{background:#f7fbff}.bank-row-v122.active{background:#dff3ff;box-shadow:inset 3px 0 0 #1683ea}
      .bank-main-v122{display:flex;gap:12px;align-items:center;min-width:0}.bank-icon-v122{width:36px;height:36px;border-radius:50%;background:#e8f3ff;color:#1683ea;display:grid;place-items:center;font-size:16px;flex:0 0 36px}
      .bank-name-v122{font-size:14px;font-weight:600;color:#27313f;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.bank-sub-v122{font-size:12px;color:#8a96a6;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .bank-amount-v122{text-align:right;color:#10b981;font-size:13px;font-weight:600}.bank-menu-wrap-v122{position:relative;text-align:right}.bank-dot-v122{border:0;background:transparent;color:#687588;font-size:21px;line-height:1;cursor:pointer;padding:4px 3px}
      .bank-menu-v122{display:none;position:absolute;right:0;top:32px;width:150px;background:#fff;border:1px solid #d8dee7;border-radius:6px;box-shadow:0 10px 24px rgba(20,33,48,.16);z-index:100}.bank-menu-v122.show{display:block}
      .bank-menu-v122 a,.bank-menu-v122 button{display:block;width:100%;box-sizing:border-box;padding:10px 12px;border:0;background:#fff;text-align:left;color:#334155;text-decoration:none;font-size:13px;cursor:pointer}.bank-menu-v122 a:hover,.bank-menu-v122 button:hover{background:#f4f8fb}.bank-menu-v122 .danger{color:#dc2626}.bank-menu-v122 .disabled{color:#a3acb8;cursor:default}
      .bank-right-v122{display:flex;flex-direction:column;overflow:hidden}
      .bank-detail-head-v122{padding:18px 22px;border-bottom:1px solid #dfe5ec;box-shadow:0 1px 5px rgba(20,33,48,.07);display:grid;grid-template-columns:minmax(0,1fr) auto;gap:20px;align-items:center}
      .bank-detail-grid-v122{display:grid;grid-template-columns:repeat(2,minmax(140px,1fr));gap:8px 34px}.bank-detail-label-v122{color:#8a96a6;font-size:12px}.bank-detail-value-v122{font-size:14px;color:#27313f;font-weight:600;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .bank-detail-actions-v122{display:flex;flex-direction:column;align-items:flex-end;gap:12px}.bank-balance-v122{text-align:right}.bank-balance-label-v122{font-size:12px;color:#8a96a6}.bank-balance-value-v122{font-size:20px;font-weight:700;color:#10b981;margin-top:3px}
      .bank-move-wrap-v122{position:relative}.bank-move-btn-v122{border:0;background:#168fe9;color:#fff;border-radius:7px;padding:10px 14px;font-weight:600;cursor:pointer}.bank-action-split-v147{position:relative;display:inline-flex;align-items:stretch}.bank-move-main-v147{border-radius:7px 0 0 7px;padding-left:16px;padding-right:16px}.bank-move-caret-v147{border:0;border-left:1px solid rgba(255,255,255,.28);background:#168fe9;color:#fff;padding:0 10px;border-radius:0 7px 7px 0;cursor:pointer;font-size:13px}.bank-move-caret-v147:hover,.bank-move-main-v147:hover{filter:brightness(.98)}.bank-move-menu-v122{display:none;position:absolute;right:0;top:42px;width:170px;background:#fff;border:1px solid #d8dee7;border-radius:6px;box-shadow:0 10px 24px rgba(20,33,48,.16);z-index:100;overflow:hidden}.bank-move-menu-v122.show{display:block}.bank-move-menu-v122 button{width:100%;border:0;background:#fff;text-align:left;padding:11px 13px;cursor:pointer;font-size:13px}.bank-move-menu-v122 button:hover{background:#f4f8fb}
      .bank-action-pair-v149{display:inline-flex;gap:8px;align-items:center}.bank-action-pair-v149 .bank-move-btn-v122{border:0;color:#fff;background:#168fe9;border-radius:7px;padding:10px 18px;font-weight:600;cursor:pointer}.bank-action-pair-v149 .bank-move-btn-v122:hover{filter:brightness(.97)}.bank-action-pair-v149 .bank-withdraw-v149{background:#168fe9}.bank-action-pair-v149 .bank-deposit-v149{background:#168fe9}

      .bank-tx-v122{display:flex;flex-direction:column;min-height:0;flex:1}.bank-tx-head-v122{padding:16px 12px 10px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px}.bank-tx-title-v122{font-size:16px;font-weight:700;color:#334155}.bank-tx-search-v122{width:min(300px,45%);border:1px solid #d5dce5;border-radius:4px;height:34px;padding:0 10px;outline:0}.bank-tx-search-v122:focus{border-color:#1683ea;box-shadow:0 0 0 2px rgba(22,131,234,.08)}
      .bank-tx-scroll-v122{overflow:auto;min-height:0;flex:1;border-top:1px solid #e2e8ef}.bank-tx-table-v122{width:100%;min-width:680px;border-collapse:collapse}.bank-tx-table-v122 th,.bank-tx-table-v122 td{padding:11px 10px;border-bottom:1px solid #edf1f4;font-size:13px;white-space:nowrap}.bank-tx-table-v122 th{position:sticky;top:0;background:#fafbfd;color:#657286;font-size:11px;text-align:left;z-index:2}.bank-tx-table-v122 tbody tr:hover{background:#f8fbfe}.bank-in-v122{color:#10b981;font-weight:700}.bank-out-v122{color:#ef4444;font-weight:700}.bank-empty-v122{padding:34px;text-align:center;color:#8a96a6}
      .bank-modal-v122{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:900;align-items:flex-start;justify-content:center;padding:80px 18px 20px}.bank-modal-v122.show{display:flex}.bank-modal-box-v122{width:min(560px,100%);background:#fff;border-radius:9px;box-shadow:0 18px 55px rgba(15,23,42,.25);overflow:hidden}.bank-modal-head-v122{display:flex;justify-content:space-between;align-items:center;padding:15px 18px;border-bottom:1px solid #e3e8ee}.bank-modal-head-v122 h2{font-size:17px;margin:0;color:#27313f}.bank-modal-close-v122{border:0;background:none;font-size:24px;color:#7c8795;cursor:pointer}.bank-modal-body-v122{padding:18px}.bank-form-grid-v122{display:grid;grid-template-columns:1fr 1fr;gap:12px 16px}.bank-form-field-v122 label{display:block;font-size:12px;color:#657286;margin-bottom:6px}.bank-form-field-v122 input{width:100%;height:40px;border:1px solid #d5dce5;border-radius:5px;padding:0 10px;box-sizing:border-box;outline:0}.bank-form-field-v122 input:focus{border-color:#1683ea}.bank-form-span-v122{grid-column:1/-1}.bank-modal-foot-v122{padding:10px 18px;border-top:1px solid #e3e8ee;display:flex;justify-content:flex-end;gap:8px}.bank-secondary-v122,.bank-primary-v122{border-radius:6px;padding:9px 15px;cursor:pointer;border:1px solid #d5dce5;background:#fff;color:#334155}.bank-primary-v122{background:#1683ea;color:#fff;border-color:#1683ea}
      .bank-transfer-modal-v147{padding-top:54px}.bank-transfer-box-v147{width:min(550px,100%);background:#fff;border-radius:6px;box-shadow:0 18px 55px rgba(15,23,42,.28);overflow:hidden}.bank-transfer-head-v147{display:flex;justify-content:space-between;align-items:center;padding:17px 20px;border-bottom:1px solid #e6eaf0}.bank-transfer-head-v147 h2{font-size:18px;font-weight:500;margin:0;color:#315367}.bank-transfer-body-v147{padding:22px 32px 18px}.bank-transfer-grid-v147{display:grid;grid-template-columns:1fr 1fr;gap:20px 24px}.bank-transfer-field-v147{position:relative;min-width:0}.bank-transfer-field-v147 label{display:block;font-size:12px;color:#68768a;margin:0 0 6px}.bank-transfer-field-v147 select,.bank-transfer-field-v147 input{width:100%;height:38px;box-sizing:border-box;border:1px solid #d5dbe4;border-radius:5px;background:#fff;color:#27313f;padding:0 10px;outline:0;font-size:14px}.bank-transfer-field-v147 select:focus,.bank-transfer-field-v147 input:focus{border-color:#1683ea;box-shadow:0 0 0 2px rgba(22,131,234,.08)}.bank-transfer-field-v147 input::placeholder{color:#9aa6b6}.bank-transfer-full-v147{grid-column:1/-1}.bank-transfer-foot-v147{padding:10px 32px 16px;display:flex;justify-content:flex-end;gap:8px}.bank-transfer-box-v147 form{margin:0}.bank-transfer-box-v147 .bank-primary-v122{min-width:110px}.bank-transfer-box-v147 .bank-secondary-v122{min-width:90px}.bank-transfer-box-v147 .bank-modal-close-v122{font-size:25px}.bank-transfer-box-v147 .bank-modal-close-v122:hover{color:#4b5563}
      @media(max-width:900px){.bank-page-v122{grid-template-columns:1fr;height:auto;overflow:visible}.bank-left-v122,.bank-right-v122{min-height:420px}.bank-detail-head-v122{grid-template-columns:1fr}.bank-detail-actions-v122{align-items:flex-start}.bank-balance-v122{text-align:left}.bank-tx-search-v122{width:100%;max-width:none}}
      @media(max-width:620px){.bank-list-head-v122,.bank-row-v122{grid-template-columns:1fr 90px 26px}.bank-detail-grid-v122,.bank-form-grid-v122{grid-template-columns:1fr}.bank-form-span-v122{grid-column:auto}.bank-tx-head-v122{flex-direction:column;align-items:stretch}}
    </style>
    <div class="bank-page-v122">
      <section class="bank-left-v122">
        <div class="bank-left-top-v122"><button type="button" class="bank-add-v122" onclick="document.getElementById('bankAddModalV122').classList.add('show')">⊕ Add Bank</button></div>
        <div class="bank-list-head-v122"><span>ACCOUNT NAME</span><span style="text-align:right">AMOUNT</span><span></span></div>
        <div class="bank-list-v122">
          <?php foreach($banks as $b): $bid=(int)$b['id']; $bal=bank_balance($cid,$bid); $used=($viewCount[$bid]??0)>0; ?>
            <div class="bank-row-v122 <?=$bid===$selectedId?'active':''?>" data-bank-row="<?=$bid?>" onclick="window.location.href='<?=e(url('bank-accounts?select='.$bid))?>'">
              <div class="bank-main-v122"><span class="bank-icon-v122">▤</span><div style="min-width:0"><div class="bank-name-v122"><?=e($b['name'])?></div><div class="bank-sub-v122"><?=e($b['bank_name']?:'Bank Account')?></div></div></div>
              <div class="bank-amount-v122"><?=money($bal)?></div>
              <div class="bank-menu-wrap-v122" onclick="event.stopPropagation();">
                <button type="button" class="bank-dot-v122" aria-label="Bank actions" onclick="this.nextElementSibling.classList.toggle('show');document.querySelectorAll('.bank-menu-v122').forEach(m=>{if(m!==this.nextElementSibling)m.classList.remove('show')});">⋮</button>
                <div class="bank-menu-v122">
                  <a href="<?=e(url('bank-accounts?select='.$bid))?>">View</a>
                  <a href="<?=e(url('bank-accounts?edit='.$bid.'&select='.$bid))?>">Edit</a>
                  <?php if(!$used): ?><form method="post" onsubmit="return confirm('Delete this bank account?');"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_bank_account"><input type="hidden" name="id" value="<?=$bid?>"><button type="submit" class="danger">Delete</button></form><?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; if(!$banks): ?><div class="bank-empty-v122">No bank accounts yet.</div><?php endif; ?>
        </div>
      </section>

      <section class="bank-right-v122">
        <?php if($selected): ?>
          <div class="bank-detail-head-v122">
            <div class="bank-detail-grid-v122">
              <div><div class="bank-detail-label-v122">Bank Name</div><div class="bank-detail-value-v122"><?=e($selected['bank_name']?:'—')?></div></div>
              <div><div class="bank-detail-label-v122">Account Number</div><div class="bank-detail-value-v122"><?=e($selected['account_number']?:'—')?></div></div>
              <div><div class="bank-detail-label-v122">Account Name</div><div class="bank-detail-value-v122"><?=e($selected['name'])?></div></div>
            </div>
            <div class="bank-detail-actions-v122">
              <div class="bank-action-pair-v149"><button type="button" class="bank-move-btn-v122 bank-deposit-v149" onclick="openBankTransferV149('deposit')">Deposit</button><button type="button" class="bank-move-btn-v122 bank-withdraw-v149" onclick="openBankTransferV149('withdraw')">Withdraw</button></div>
              <div class="bank-balance-v122"><div class="bank-balance-label-v122">Balance</div><div class="bank-balance-value-v122"><?=money($selectedBalance)?></div></div>
            </div>
          </div>
          <div class="bank-tx-v122">
            <div class="bank-tx-head-v122"><div class="bank-tx-title-v122">TRANSACTIONS</div><form method="get" style="margin:0;display:flex;gap:8px;align-items:center"><input type="hidden" name="select" value="<?=$selectedId?>"><input class="bank-tx-search-v122" name="q" value="<?=e($search)?>" placeholder="Search transactions"></form></div>
            <div class="bank-tx-scroll-v122"><table class="bank-tx-table-v122"><thead><tr><th>TYPE</th><th>NAME</th><th>DATE</th><th>AMOUNT</th><th>REFERENCE</th></tr></thead><tbody>
            <?php foreach($transactions as $tr): $isIn=(float)$tr['debit']>0; $amt=$isIn?(float)$tr['debit']:(float)$tr['credit']; $typeLabel=match((string)$tr['txn_type']){'payment_in'=>'Payment In','payment_out'=>'Payment Out','sale'=>'Sale','purchase'=>'Purchase','expense'=>'Expense','bank_deposit'=>'Cash Deposit','bank_withdraw'=>'Cash Withdraw','bank_transfer'=>'Bank Transfer',default=>ucwords(str_replace('_',' ',(string)$tr['txn_type']))}; $name=$tr['party_name']?:($tr['memo']?:($tr['notes']?:'—')); ?>
              <tr><td><?=e($typeLabel)?></td><td><?=e($name)?></td><td><?=e(!empty($tr['entry_date'])?date('d/m/Y',strtotime($tr['entry_date'])):'—')?></td><td class="<?=$isIn?'bank-in-v122':'bank-out-v122'?>"><?=($isIn?'+':'-').money($amt)?></td><td><?=e($tr['document_no']?:'—')?></td></tr>
            <?php endforeach; if(!$transactions): ?><tr><td colspan="5" class="bank-empty-v122">No transactions found for this bank account.</td></tr><?php endif; ?>
            </tbody></table></div>
          </div>
        <?php else: ?>
          <div class="bank-empty-v122" style="flex:1;display:grid;place-items:center">Select a bank account.</div>
        <?php endif; ?>
      </section>
    </div>

    <div class="bank-modal-v122" id="bankAddModalV122" onclick="if(event.target===this)this.classList.remove('show')"><div class="bank-modal-box-v122"><div class="bank-modal-head-v122"><h2>Add Bank</h2><button type="button" class="bank-modal-close-v122" onclick="document.getElementById('bankAddModalV122').classList.remove('show')">×</button></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_bank_account"><div class="bank-modal-body-v122"><div class="bank-form-grid-v122"><div class="bank-form-field-v122"><label>Account Name *</label><input name="name" required></div><div class="bank-form-field-v122"><label>Bank Name</label><input name="bank_name"></div><div class="bank-form-field-v122"><label>Account Number</label><input name="account_number"></div><div class="bank-form-field-v122"><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="0" onwheel="this.blur()"></div></div></div><div class="bank-modal-foot-v122"><button type="button" class="bank-secondary-v122" onclick="document.getElementById('bankAddModalV122').classList.remove('show')">Cancel</button><button class="bank-primary-v122">Save</button></div></form></div></div></div>

    <?php if($editBank): ?>
    <div class="bank-modal-v122 show" id="bankEditModalV122" onclick="if(event.target===this)this.classList.remove('show')"><div class="bank-modal-box-v122"><div class="bank-modal-head-v122"><h2>Edit Bank Account</h2><button type="button" class="bank-modal-close-v122" onclick="window.location.href='<?=e(url('bank-accounts?select='.(int)$editBank['id']))?>'">×</button></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update_bank_account"><input type="hidden" name="id" value="<?=e((string)$editBank['id'])?>"><div class="bank-modal-body-v122"><div class="bank-form-grid-v122"><div class="bank-form-field-v122"><label>Account Name *</label><input name="name" value="<?=e($editBank['name'])?>" required></div><div class="bank-form-field-v122"><label>Bank Name</label><input name="bank_name" value="<?=e($editBank['bank_name']??'')?>"></div><div class="bank-form-field-v122"><label>Account Number</label><input name="account_number" value="<?=e($editBank['account_number']??'')?>"></div><div class="bank-form-field-v122"><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="<?=e((string)$editBank['opening_balance'])?>" onwheel="this.blur()"></div></div></div><div class="bank-modal-foot-v122"><a class="bank-secondary-v122" href="<?=e(url('bank-accounts?select='.(int)$editBank['id']))?>">Cancel</a><button class="bank-primary-v122">Save Changes</button></div></form></div></div>
    <?php endif; ?>


    <div class="bank-modal-v122 bank-transfer-modal-v147" id="bankTransferModalV147" onclick="if(event.target===this)this.classList.remove('show')"><div class="bank-transfer-box-v147"><div class="bank-transfer-head-v147"><h2 id="bankTransferTitleV150">Deposit</h2><button type="button" class="bank-modal-close-v122" onclick="document.getElementById('bankTransferModalV147').classList.remove('show')">×</button></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="bank_to_bank_transfer"><input type="hidden" name="from_account" id="bankTransferFromHiddenV150" value=""><input type="hidden" name="to_account" id="bankTransferToHiddenV150" value=""><div class="bank-transfer-body-v147"><div class="bank-transfer-grid-v147"><div class="bank-transfer-field-v147"><label>From</label><select id="bankTransferFromV147" required><option value="cash">Cash In Hand</option><?php foreach($banks as $b): ?><option value="bank:<?=e((string)$b['id'])?>" <?=$selectedId===(int)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach; ?></select></div><div class="bank-transfer-field-v147"><label>To</label><select id="bankTransferToV147" required><option value="cash">Cash In Hand</option><?php foreach($banks as $b): ?><?php if($selectedId!==(int)$b['id']): ?><option value="bank:<?=e((string)$b['id'])?>"><?=e($b['name'])?></option><?php endif; ?><?php endforeach; ?></select></div><div class="bank-transfer-field-v147"><label>Amount</label><input type="number" name="amount" min="0.01" step="0.01" placeholder="Amount" required onwheel="this.blur()"></div><div class="bank-transfer-field-v147"><label>Adjustment Date</label><input type="date" name="adjustment_date" value="<?=e(date('Y-m-d'))?>" required></div><div class="bank-transfer-field-v147 bank-transfer-full-v147"><label>Add Description</label><input type="text" name="notes" maxlength="255" placeholder="Optional"></div></div></div><div class="bank-transfer-foot-v147"><button type="button" class="bank-secondary-v122" onclick="document.getElementById('bankTransferModalV147').classList.remove('show')">Cancel</button><button class="bank-primary-v122">Save</button></div></form></div></div>

    <div class="bank-modal-v122" id="bankMoveModalV122" onclick="if(event.target===this)this.classList.remove('show')"><div class="bank-modal-box-v122"><div class="bank-modal-head-v122"><h2 id="bankMoveTitleV122">Deposit</h2><button type="button" class="bank-modal-close-v122" onclick="document.getElementById('bankMoveModalV122').classList.remove('show')">×</button></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="bank_cash_move"><input type="hidden" name="bank_id" value="<?=e((string)$selectedId)?>"><input type="hidden" name="direction" id="bankMoveDirectionV122" value="deposit"><div class="bank-modal-body-v122"><div class="bank-form-grid-v122"><div class="bank-form-field-v122"><label>Date</label><input type="date" name="txn_date" value="<?=e(date('Y-m-d'))?>"></div><div class="bank-form-field-v122"><label>Amount *</label><input type="number" step="0.01" min="0.01" name="amount" required onwheel="this.blur()"></div><div class="bank-form-field-v122 bank-form-span-v122"><label>Reference / Note</label><input name="notes" maxlength="255" placeholder="Optional"></div></div></div><div class="bank-modal-foot-v122"><button type="button" class="bank-secondary-v122" onclick="document.getElementById('bankMoveModalV122').classList.remove('show')">Cancel</button><button class="bank-primary-v122" id="bankMoveSubmitV122">Save</button></div></form></div></div>
    <script>
      (function(){var f=document.getElementById('bankTransferFromV147'),t=document.getElementById('bankTransferToV147'),fh=document.getElementById('bankTransferFromHiddenV150'),th=document.getElementById('bankTransferToHiddenV150');if(f){f.addEventListener('change',function(){if(!f.disabled)fh.value=f.value;});}if(t){t.addEventListener('change',function(){if(!t.disabled)th.value=t.value;});}})();
      function openBankTransferV149(dir){var m=document.getElementById('bankTransferModalV147');if(!m)return;var from=document.getElementById('bankTransferFromV147'),to=document.getElementById('bankTransferToV147');var fh=document.getElementById('bankTransferFromHiddenV150'),th=document.getElementById('bankTransferToHiddenV150');var title=document.getElementById('bankTransferTitleV150');if(!from||!to||!fh||!th)return;var current='<?=e((string)$selectedId)?>';from.disabled=false;to.disabled=false;fh.value='';th.value='';Array.prototype.forEach.call(from.options,function(o){o.disabled=false;});Array.prototype.forEach.call(to.options,function(o){o.disabled=false;});if(dir==='deposit'){title.textContent='Deposit';from.value='cash';fh.value='cash';if(current){var sameFrom=from.querySelector('option[value=\"bank:'+current+'\"]');if(sameFrom)sameFrom.disabled=true;to.value='bank:'+current;to.disabled=true;th.value='bank:'+current;}else{to.value='cash';to.disabled=true;th.value='cash';}}else{title.textContent='Withdraw';if(current){from.value='bank:'+current;from.disabled=true;fh.value='bank:'+current;}else{from.value='cash';from.disabled=true;fh.value='cash';}to.value='cash';to.disabled=true;th.value='cash';}m.classList.add('show');}
      function openBankTransferV147(){openBankTransferV149('deposit');}
      function openBankMoveV122(dir){var m=document.getElementById('bankMoveModalV122');if(!m)return;document.getElementById('bankMoveDirectionV122').value=dir;document.getElementById('bankMoveTitleV122').textContent=dir==='deposit'?'Deposit':'Withdraw';document.getElementById('bankMoveSubmitV122').textContent=dir==='deposit'?'Save Deposit':'Save Withdraw';m.classList.add('show');}
    </script>
    <?php page_end();exit;
}

if($route==='messages'){
    $u=require_login(); $cid=(int)$u['company_id'];
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $receiver=(int)($_POST['receiver_id']??0); $body=trim($_POST['body']??'');
        if($receiver<1 || $receiver===$u['id'] || $body===''){flash('error','Choose another user and enter a message.');redirect('messages');}
        $st=db()->prepare('SELECT id FROM users WHERE id=? AND company_id=? AND status="active" LIMIT 1'); $st->execute([$receiver,$cid]);
        if(!$st->fetchColumn()){flash('error','Invalid recipient.');redirect('messages');}
        db()->prepare('INSERT INTO messages(company_id,sender_id,receiver_id,body) VALUES(?,?,?,?)')->execute([$cid,$u['id'],$receiver,$body]);
        $mid=(int)db()->lastInsertId();
        db()->prepare('INSERT INTO notifications(company_id,user_id,type,title,body) VALUES(?,?,?,?,?)')->execute([$cid,$receiver,'message','New message from '.$u['name'],mb_substr($body,0,160)]);
        audit('send','message',$mid,['receiver_id'=>$receiver]);
        flash('success','Message sent.'); redirect('messages');
    }
    if(isset($_GET['read'])){ $mid=(int)$_GET['read']; db()->prepare('UPDATE messages SET read_at=NOW() WHERE id=? AND receiver_id=?')->execute([$mid,$u['id']]); }
    $users=db()->prepare('SELECT id,name,email,role FROM users WHERE company_id=? AND status="active" AND id<>? ORDER BY name'); $users->execute([$cid,$u['id']]); $users=$users->fetchAll();
    $msgs=db()->prepare('SELECT m.*,su.name sender_name,ru.name receiver_name FROM messages m JOIN users su ON su.id=m.sender_id JOIN users ru ON ru.id=m.receiver_id WHERE m.company_id=? AND (m.sender_id=? OR m.receiver_id=?) ORDER BY m.created_at DESC LIMIT 100'); $msgs->execute([$cid,$u['id'],$u['id']]); $msgs=$msgs->fetchAll();
    page_start('Messages');
    ?><div class="page-title"><div><h1>Messages</h1><p>Private text messages between company users</p></div><span class="status paid"><?=unread_messages_count((int)$u['id'])?> unread</span></div>
    <div class="grid2"><div class="panel"><div class="panel-head"><h2>Send Message</h2></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>To</label><select name="receiver_id" required><option value="">Select user</option><?php foreach($users as $x):?><option value="<?=$x['id']?>"><?=e($x['name'])?> · <?=e(ucwords(str_replace('_',' ',$x['role'])))?> · <?=e($x['email'])?></option><?php endforeach;?></select></div><div class="form-group"><label>Message</label><textarea name="body" rows="6" required maxlength="5000" placeholder="Write a message..."></textarea></div><button class="btn primary">Send</button></form></div>
    <div class="panel"><div class="panel-head"><h2>Conversations</h2></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>FROM</th><th>TO</th><th>MESSAGE</th><th>STATUS</th></tr></thead><tbody><?php foreach($msgs as $m):?><tr><td><?=e($m['created_at'])?></td><td><?=e($m['sender_name'])?></td><td><?=e($m['receiver_name'])?></td><td style="max-width:360px;white-space:normal"><?=nl2br(e($m['body']))?></td><td><?php if((int)$m['receiver_id']===$u['id'] && !$m['read_at']):?><a class="btn small-btn" href="<?=e(url('messages?read='.(int)$m['id']))?>">Mark read</a><?php else:?><span class="status paid">Read</span><?php endif;?></td></tr><?php endforeach;if(!$msgs):?><tr><td colspan="5" class="subtle">No messages yet.</td></tr><?php endif;?></tbody></table></div></div></div><?php page_end();exit;
}

if($route==='notifications'){
    $u=require_login(); $cid=(int)$u['company_id']; $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST') {
        check_csrf(); $act=$_POST['notification_action']??''; $nid=(int)($_POST['notification_id']??0);
        if($act==='read' && $nid>0){ $pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE id=? AND user_id=? AND company_id=?')->execute([$nid,$u['id'],$cid]); }
        elseif($act==='unread' && $nid>0){ $pdo->prepare('UPDATE notifications SET read_at=NULL WHERE id=? AND user_id=? AND company_id=?')->execute([$nid,$u['id'],$cid]); }
        elseif($act==='delete' && $nid>0){ notifications_v191_soft_delete($nid,(int)$u['id'],$cid); }
        elseif($act==='read_all'){ $pdo->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND company_id=?')->execute([$u['id'],$cid]); }
        elseif($act==='delete_read'){ notifications_v191_delete_read((int)$u['id'],$cid); }
        redirect('notifications'.(!empty($_POST['return_q'])?('?'.ltrim((string)$_POST['return_q'],'?')):''));
    }
    $type=trim((string)($_GET['type']??'')); $state=trim((string)($_GET['state']??'all')); $search=trim((string)($_GET['q']??''));
    $where=['user_id=?','company_id=?','(deleted_at IS NULL OR deleted_at=\'0000-00-00 00:00:00\')']; $args=[$u['id'],$cid];
    if($type!==''){ $where[]='type=?'; $args[]=$type; }
    if($state==='unread') $where[]='read_at IS NULL'; elseif($state==='read') $where[]='read_at IS NOT NULL';
    if($search!==''){ $where[]='(title LIKE ? OR body LIKE ?)'; $args[]='%'.$search.'%'; $args[]='%'.$search.'%'; }
    $sql='SELECT id,type,title,body,link,read_at,created_at FROM notifications WHERE '.implode(' AND ',$where).' ORDER BY id DESC LIMIT 250';
    $q=$pdo->prepare($sql); $q->execute($args); $rows=$q->fetchAll();
    $allTypes=['company_update'=>'Company Update','subscription'=>'Subscription','review'=>'Review','support'=>'Support','platform'=>'Platform Notice','message'=>'Message'];
    $qs=http_build_query(['q'=>$search,'type'=>$type,'state'=>$state]);
    page_start('Notifications'); ?>
    <div class="page-title"><div><h1>Notifications</h1><p>Company updates, subscriptions, reviews, support and platform notifications.</p></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="notification_action" value="read_all"><input type="hidden" name="return_q" value="<?=e($qs)?>"><button class="btn">Mark all as read</button></form>
        <form method="post" style="display:inline" onsubmit="return confirm('Delete all read notifications?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="notification_action" value="delete_read"><input type="hidden" name="return_q" value="<?=e($qs)?>"><button class="btn">Delete read</button></form>
      </div></div>
    <div class="panel" style="padding:12px;margin-bottom:12px"><form method="get" style="display:grid;grid-template-columns:minmax(220px,1fr) 180px 160px auto;gap:8px;align-items:end">
      <div class="form-group"><label>Search</label><input type="text" name="q" value="<?=e($search)?>" placeholder="Search notifications..."></div>
      <div class="form-group"><label>Type</label><select name="type"><option value="">All types</option><?php foreach($allTypes as $k=>$v):?><option value="<?=e($k)?>" <?=$type===$k?'selected':''?>><?=e($v)?></option><?php endforeach;?></select></div>
      <div class="form-group"><label>Status</label><select name="state"><option value="all" <?=$state==='all'?'selected':''?>>All</option><option value="unread" <?=$state==='unread'?'selected':''?>>Unread</option><option value="read" <?=$state==='read'?'selected':''?>>Read</option></select></div>
      <button class="btn primary">Filter</button></form></div>
    <div class="panel table-wrap"><table><thead><tr><th>DATE</th><th>TYPE</th><th>TITLE</th><th>MESSAGE</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
    <?php foreach($rows as $r): ?>
      <tr class="<?=empty($r['read_at'])?'notice-unread':''?>"><td><?=e($r['created_at'])?></td><td><span class="status open"><?=e($allTypes[$r['type']]??$r['type'])?></span></td><td><b><?=e($r['title'])?></b></td><td><?=nl2br(e($r['body']??''))?><?php if(!empty($r['link'])):?><div style="margin-top:4px"><a class="small-link" href="<?=e($r['link'])?>">Open related page</a></div><?php endif;?></td><td><?=empty($r['read_at'])?'<span class="status warn">Unread</span>':'<span class="subtle">Read</span>'?></td><td style="white-space:nowrap">
        <form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="notification_id" value="<?=((int)$r['id'])?>"><input type="hidden" name="return_q" value="<?=e($qs)?>"><?=empty($r['read_at'])?'<button class="btn small-btn" name="notification_action" value="read">Mark read</button>':'<button class="btn small-btn" name="notification_action" value="unread">Mark unread</button>'?></form>
        <form method="post" style="display:inline;margin-left:4px"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="notification_id" value="<?=((int)$r['id'])?>"><input type="hidden" name="return_q" value="<?=e($qs)?>"><button class="btn small-btn" name="notification_action" value="delete" onclick="return confirm('Delete this notification?')">Delete</button></form>
      </td></tr>
    <?php endforeach; if(!$rows):?><tr><td colspan="6" class="subtle">No notifications found.</td></tr><?php endif;?></tbody></table></div>
    <div class="subtle" style="margin-top:10px">Unread notifications: <b><?=notifications_v191_unread_count((int)$u['id'],$cid)?></b></div>
    <?php page_end();exit;
}

if($route==='team'){
    $u=require_super_admin(); $cid=(int)$u['company_id'];
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf(); $action=$_POST['team_action']??'';
        if($action==='invite'){
            $name=trim($_POST['name']??''); $email=strtolower(trim($_POST['email']??'')); $role=$_POST['role']??'viewer';
            $allowed=['admin','manager','accountant','sales','purchase','viewer','custom'];
            if(!$name || !filter_var($email,FILTER_VALIDATE_EMAIL) || !in_array($role,$allowed,true)){flash('error','Enter a valid name, email and role.');redirect('team');}
            $chk=db()->prepare('SELECT id FROM users WHERE company_id=? AND email=? LIMIT 1');$chk->execute([$cid,$email]);
            if($chk->fetchColumn()){flash('error','This email is already a user in the company.');redirect('team');}
            $chk=db()->prepare('SELECT id FROM user_invites WHERE company_id=? AND email=? AND accepted_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1');$chk->execute([$cid,$email]);
            if($chk->fetchColumn()){flash('error','An active invitation already exists for this email.');redirect('team');}
            $token=bin2hex(random_bytes(32)); $expires=date('Y-m-d H:i:s',time()+7*86400);
            db()->prepare('INSERT INTO user_invites(company_id,invited_by,email,name,role,token,expires_at) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$u['id'],$email,$name,$role,$token,$expires]);
            $link=url('accept-invite?token='.$token); $subject='Invitation to '.$u['company_name'].' · Suto Accounting'; $msg="You have been invited to join {$u['company_name']} on Suto Accounting.\n\nOpen this link to accept: https://".$_SERVER['HTTP_HOST'].$link."\n\nThis invitation expires in 7 days."; $sent=false; if(function_exists('mail')){$headers='From: Suto Accounting <no-reply@'.preg_replace('/[^a-z0-9.-]/i','',$_SERVER['HTTP_HOST']).'>\r\nContent-Type: text/plain; charset=UTF-8'; $sent=@mail($email,$subject,$msg,$headers);}
            audit('invite','user_invite',(int)db()->lastInsertId(),['email'=>$email,'role'=>$role]); flash('success',$sent?'Invitation email sent.':'Invitation created. Copy the invitation link from the pending invitations table.'); redirect('team');
        }
        if($action==='role_permissions'){
            $roleId=(int)($_POST['role_id']??0); $permIds=array_map('intval',$_POST['permissions']??[]);
            $st=db()->prepare('SELECT id,name FROM roles WHERE id=? AND company_id=?');$st->execute([$roleId,$cid]);$roleRow=$st->fetch();
            if(!$roleRow){flash('error','Invalid role.');redirect('team');}
            db()->prepare('DELETE FROM role_permissions WHERE role_id=?')->execute([$roleId]); $ins=db()->prepare('INSERT INTO role_permissions(role_id,permission_id) VALUES(?,?)'); foreach($permIds as $pid)$ins->execute([$roleId,$pid]); audit('update','role',$roleId,['permissions'=>$permIds]); flash('success','Role permissions updated.'); redirect('team');
        }
    }
    $roles=db()->prepare('SELECT * FROM roles WHERE company_id=? ORDER BY name');$roles->execute([$cid]);$roles=$roles->fetchAll();
    $perms=db()->query('SELECT * FROM permissions ORDER BY id')->fetchAll();
    $us=db()->prepare('SELECT u.id,u.name,u.email,u.role,u.status,u.last_login_at,r.name role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.company_id=? ORDER BY u.id');$us->execute([$cid]);$us=$us->fetchAll();
    $inv=db()->prepare('SELECT i.*,u.name inviter_name FROM user_invites i JOIN users u ON u.id=i.invited_by WHERE i.company_id=? AND i.accepted_at IS NULL AND i.expires_at>NOW() ORDER BY i.id DESC');$inv->execute([$cid]);$inv=$inv->fetchAll();
    page_start('Team & Permissions');
    ?><div class="page-title"><div><h1>Team & Permissions</h1><p>Invite users and control role-based access</p></div><span class="status paid"><?=count($us)?> users</span></div>
    <div class="grid2"><div class="panel"><div class="panel-head"><h2>Invite User</h2></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="team_action" value="invite"><div class="grid2"><div class="form-group"><label>Name*</label><input name="name" required></div><div class="form-group"><label>Email*</label><input name="email" type="email" required></div><div class="form-group"><label>Role</label><select name="role"><option value="admin">Admin</option><option value="manager">Manager</option><option value="accountant">Accountant</option><option value="sales">Sales</option><option value="purchase">Purchase</option><option value="viewer" selected>Viewer</option><option value="custom">Custom</option></select></div></div><button class="btn primary">Send Invite</button></form></div>
    <div class="panel"><div class="panel-head"><h2>Pending Invitations</h2></div><div class="table-wrap"><table><thead><tr><th>NAME</th><th>EMAIL</th><th>ROLE</th><th>EXPIRES</th><th>LINK</th></tr></thead><tbody><?php foreach($inv as $i):$link=url('accept-invite?token='.$i['token']);?><tr><td><?=e($i['name']??'-')?></td><td><?=e($i['email'])?></td><td><?=e(ucfirst($i['role']))?></td><td><?=e($i['expires_at'])?></td><td><input class="copy-link" readonly value="https://<?=e($_SERVER['HTTP_HOST'].$link)?>"></td></tr><?php endforeach;if(!$inv):?><tr><td colspan="5" class="subtle">No pending invitations.</td></tr><?php endif;?></tbody></table></div></div></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Users</h2></div><div class="table-wrap"><table><thead><tr><th>NAME</th><th>EMAIL</th><th>ROLE</th><th>STATUS</th><th>LAST LOGIN</th></tr></thead><tbody><?php foreach($us as $x):?><tr><td><?=e($x['name'])?></td><td><?=e($x['email'])?></td><td><?=e(ucwords(str_replace('_',' ',($x['role_name']?:$x['role']))))?></td><td><?=e(ucfirst($x['status']))?></td><td><?=e($x['last_login_at']?:'-')?></td></tr><?php endforeach;?></tbody></table></div></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Role Permissions</h2><span class="subtle">View · Add · Edit · Delete · Print · Export · Approve</span></div><?php foreach($roles as $rr):$rp=db()->prepare('SELECT permission_id FROM role_permissions WHERE role_id=?');$rp->execute([$rr['id']]);$chosen=array_map('intval',$rp->fetchAll(PDO::FETCH_COLUMN));?><form method="post" class="role-perm-form"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="team_action" value="role_permissions"><input type="hidden" name="role_id" value="<?=$rr['id']?>"><div class="role-perm-head"><b><?=e($rr['name'])?></b><button class="btn small-btn">Save Permissions</button></div><div class="perm-grid"><?php foreach($perms as $pp):?><label><input type="checkbox" name="permissions[]" value="<?=$pp['id']?>" <?=in_array((int)$pp['id'],$chosen,true)?'checked':''?>> <?=e($pp['label'])?></label><?php endforeach;?></div></form><?php endforeach;?></div><?php page_end();exit;
}

if($route==='accept-invite'){
    if(user())redirect('dashboard'); $token=trim($_GET['token']??'');
    $st=db()->prepare('SELECT i.*,c.name company_name FROM user_invites i JOIN companies c ON c.id=i.company_id WHERE i.token=? AND i.accepted_at IS NULL AND i.expires_at>NOW() LIMIT 1');$st->execute([$token]);$inv=$st->fetch();
    if(!$inv){http_response_code(404);exit('Invitation is invalid or expired.');}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf(); $name=trim($_POST['name']??$inv['name']??'');$pass=$_POST['password']??'';$pass2=$_POST['password_confirmation']??'';
        if(!$name || strlen($pass)<8 || $pass!==$pass2){flash('error','Enter your name and matching password (8+ characters).');redirect('accept-invite?token='.urlencode($token));}
        $pdo=db(); try{$pdo->beginTransaction();$role=$inv['role'];$valid=['admin','manager','accountant','sales','purchase','viewer','custom']; if(!in_array($role,$valid,true))$role='viewer'; $pdo->prepare('INSERT INTO users(company_id,name,email,password_hash,role,status) VALUES(?,?,?, ?,?,"active")')->execute([$inv['company_id'],$name,$inv['email'],password_hash($pass,PASSWORD_DEFAULT),$role]);$uid=(int)$pdo->lastInsertId(); if($role==='custom'){ $r=$pdo->prepare('SELECT id FROM roles WHERE company_id=? AND name="Custom" LIMIT 1');$r->execute([$inv['company_id']]); if($rid=$r->fetchColumn())$pdo->prepare('UPDATE users SET role_id=? WHERE id=?')->execute([$rid,$uid]); } $pdo->prepare('UPDATE user_invites SET accepted_at=NOW() WHERE id=?')->execute([$inv['id']]); audit('accept','user_invite',(int)$inv['id'],['user_id'=>$uid]); $pdo->commit(); session_regenerate_id(true); $_SESSION['uid']=$uid; redirect('dashboard');}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error','Could not create the invited user account.');redirect('accept-invite?token='.urlencode($token));}
    }
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Accept Invitation · Suto Accounting</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>"></head><body class="auth"><div class="auth-card"><div class="auth-brand"><span class="brandmark">SA</span><span>Suto Accounting</span></div><h1>Join <?=e($inv['company_name'])?></h1><p>Complete your account setup.</p><?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach;?><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>Name</label><input name="name" value="<?=e($inv['name']??'')?>" required></div><div class="form-group"><label>Email</label><input value="<?=e($inv['email'])?>" disabled></div><div class="form-group"><label>Password</label><input type="password" name="password" minlength="8" required></div><div class="form-group"><label>Confirm Password</label><input type="password" name="password_confirmation" minlength="8" required></div><button class="btn primary" style="width:100%;justify-content:center">Accept Invitation</button></form></div></body></html><?php exit;
}


if($route==='backup'){
    $u=require_super_admin(); $cid=(int)$u['company_id'];
    $backupDir=__DIR__.'/../storage/backups'; if(!is_dir($backupDir))@mkdir($backupDir,0750,true);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf(); $action=$_POST['backup_action']??'';
        if($action==='create'){
            try{
                $pdo=db(); $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                $sql="-- Suto Accounting backup\n-- Company: ".(int)$cid."\n-- Created: ".date('Y-m-d H:i:s')."\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n";
                foreach($tables as $table){
                    $t=str_replace('`','``',$table); $sql.="DROP TABLE IF EXISTS `{$t}`;\n";
                    $create=$pdo->query("SHOW CREATE TABLE `{$t}`")->fetch(); $sql.=$create['Create Table'].";\n";
                    $rows=$pdo->query("SELECT * FROM `{$t}`")->fetchAll();
                    if($rows){ $cols=array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',array_keys($rows[0]));
                        foreach($rows as $row){ $vals=[]; foreach($row as $v){ if($v===null)$vals[]='NULL'; elseif(is_bool($v))$vals[]=$v?'1':'0'; else $vals[]=$pdo->quote((string)$v); } $sql.='INSERT INTO `'.$t.'` ('.implode(',',$cols).') VALUES ('.implode(',',$vals).');\n'; }
                    }
                }
                $sql.="COMMIT;\nSET FOREIGN_KEY_CHECKS=1;\n";
                $file='suto-accounting-backup-'.date('Ymd-His').'-company-'.$cid.'.sql'; file_put_contents($backupDir.'/'.$file,$sql,LOCK_EX); audit('backup','company',$cid,['file'=>$file]); flash('success','Backup created successfully.');
            }catch(Throwable $e){ flash('error','Backup failed: '.$e->getMessage()); }
            redirect('backup');
        }
        if($action==='restore'){
            if(empty($_FILES['backup_file']['tmp_name']) || ($_FILES['backup_file']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){flash('error','Please choose a valid SQL backup file.');redirect('backup');}
            $name=$_FILES['backup_file']['name']??'backup.sql'; $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
            if($ext!=='sql'){flash('error','Only .sql backup files are supported.');redirect('backup');}
            $sql=file_get_contents($_FILES['backup_file']['tmp_name']); if($sql===false || strlen($sql)>50*1024*1024){flash('error','Backup file is invalid or too large (50 MB max).');redirect('backup');}
            try{ $pdo=db(); $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
                foreach(preg_split('/;\s*(?:\r?\n|$)/',$sql) as $stmt){$stmt=trim($stmt); if($stmt==='' || str_starts_with($stmt,'--'))continue; $pdo->exec($stmt.';');}
                $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); audit('restore','company',$cid,['file'=>$name]); flash('success','Backup restored successfully.');
            }catch(Throwable $e){$pdo->exec("SET FOREIGN_KEY_CHECKS=1"); flash('error','Restore failed: '.$e->getMessage());}
            redirect('backup');
        }
    }
    $files=[]; if(is_dir($backupDir)){ foreach(glob($backupDir.'/*.sql')?:[] as $f){$files[]= ['name'=>basename($f),'size'=>filesize($f),'time'=>filemtime($f)];} usort($files,fn($a,$b)=>$b['time']<=>$a['time']); }
    page_start('Backup / Restore');
    ?><div class="page-title"><div><h1>Backup / Restore</h1><p>Create a manual SQL backup or restore a previous backup.</p></div></div>
    <div class="grid2"><div class="panel"><div class="panel-head"><h2>Create Backup</h2></div><p class="subtle">Manual backup of the current application database.</p><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="backup_action" value="create"><button class="btn primary">Create Backup</button></form></div>
    <div class="panel"><div class="panel-head"><h2>Restore Backup</h2></div><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="backup_action" value="restore"><div class="form-group"><label>SQL Backup File</label><input type="file" name="backup_file" accept=".sql" required></div><button class="btn danger" onclick="return confirm('Restore this backup? Current database data may be overwritten.')">Restore Backup</button></form><p class="subtle" style="margin-top:8px">Restore replaces database objects contained in the backup file.</p></div></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Available Backups</h2></div><div class="table-wrap"><table><thead><tr><th>FILE</th><th>SIZE</th><th>CREATED</th></tr></thead><tbody><?php foreach($files as $f):?><tr><td><?=e($f['name'])?></td><td><?=e(number_format($f['size']/1024,1))?> KB</td><td><?=e(date('d/m/Y H:i',$f['time']))?></td></tr><?php endforeach;if(!$files):?><tr><td colspan="3" class="subtle">No backups created yet.</td></tr><?php endif;?></tbody></table></div></div><?php page_end();exit;
}

if($route==='recycle-bin'){
    $u=require_login(); if(!has_permission('delete') && $u['role']!=='super_admin'){http_response_code(403);exit('You do not have permission.');} $cid=(int)$u['company_id']; $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();$action=$_POST['recycle_action']??'';$type=$_POST['entity_type']??'';$id=(int)($_POST['id']??0);
        try{
            if($action==='restore'){
                if($type==='transaction'){$pdo->prepare('UPDATE transactions SET deleted_at=NULL WHERE id=? AND company_id=?')->execute([$id,$cid]);}
                elseif($type==='item'){$pdo->prepare('UPDATE items SET active=1 WHERE id=? AND company_id=?')->execute([$id,$cid]);}
                elseif($type==='party'){$pdo->prepare('UPDATE parties SET deleted_at=NULL WHERE id=? AND company_id=?')->execute([$id,$cid]);}
                audit('restore',$type,$id); flash('success','Restored successfully.');
            } elseif($action==='purge'){
                if($type==='transaction'){$pdo->prepare('DELETE FROM transactions WHERE id=? AND company_id=? AND deleted_at IS NOT NULL')->execute([$id,$cid]);}
                elseif($type==='item'){
                    $st=$pdo->prepare('SELECT COUNT(*) FROM transaction_items ti JOIN transactions t ON t.id=ti.transaction_id WHERE ti.item_id=? AND t.company_id=?');$st->execute([$id,$cid]);$transactionCount=(int)$st->fetchColumn();
                    if($transactionCount>0) throw new RuntimeException('This item cannot be permanently deleted because it still has '.$transactionCount.' transaction(s). Delete all linked transactions first.');
                    $pdo->prepare('DELETE FROM items WHERE id=? AND company_id=? AND active=0')->execute([$id,$cid]);
                }
                elseif($type==='party'){$pdo->prepare('DELETE FROM parties WHERE id=? AND company_id=? AND deleted_at IS NOT NULL')->execute([$id,$cid]);}
                audit('permanent_delete',$type,$id); flash('success','Deleted permanently.');
            }
        }catch(Throwable $e){flash('error','Action failed: '.$e->getMessage());}
        redirect('recycle-bin');
    }
    $tx=$pdo->prepare('SELECT id,document_no,txn_type,txn_date,total,deleted_at FROM transactions WHERE company_id=? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC');$tx->execute([$cid]);$tx=$tx->fetchAll();
    $items=$pdo->prepare('SELECT id,name,item_type,updated_at FROM items WHERE company_id=? AND active=0 ORDER BY updated_at DESC');$items->execute([$cid]);$items=$items->fetchAll();
    $partyRows=[]; try{$st=$pdo->prepare('SELECT id,name,phone,party_type FROM parties WHERE company_id=? AND deleted_at IS NOT NULL ORDER BY id DESC');$st->execute([$cid]);$partyRows=$st->fetchAll();}catch(Throwable $e){}
    page_start('Recycle Bin'); ?><div class="page-title"><div><h1>Recycle Bin</h1><p>Restore deleted records or permanently remove them.</p></div></div>
    <div class="panel"><div class="panel-head"><h2>Deleted Transactions</h2></div><div class="table-wrap"><table><thead><tr><th>DOCUMENT</th><th>TYPE</th><th>DATE</th><th>TOTAL</th><th>ACTIONS</th></tr></thead><tbody><?php foreach($tx as $r):?><tr><td><?=e($r['document_no'])?></td><td><?=e(ucwords(str_replace('_',' ',$r['txn_type'])))?></td><td><?=e($r['txn_date'])?></td><td><?=money((float)$r['total'])?></td><td><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="recycle_action" value="restore"><input type="hidden" name="entity_type" value="transaction"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn small-btn">Restore</button></form> <form method="post" style="display:inline" onsubmit="return confirm('Delete permanently?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="recycle_action" value="purge"><input type="hidden" name="entity_type" value="transaction"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn small-btn danger">Delete</button></form></td></tr><?php endforeach;if(!$tx):?><tr><td colspan="5" class="subtle">No deleted transactions.</td></tr><?php endif;?></tbody></table></div></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Deleted Items</h2></div><div class="table-wrap"><table><thead><tr><th>NAME</th><th>TYPE</th><th>UPDATED</th><th>ACTIONS</th></tr></thead><tbody><?php foreach($items as $r):?><tr><td><?=e($r['name'])?></td><td><?=e(ucfirst($r['item_type']))?></td><td><?=e($r['updated_at'])?></td><td><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="recycle_action" value="restore"><input type="hidden" name="entity_type" value="item"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn small-btn">Restore</button></form> <form method="post" style="display:inline" onsubmit="return confirm('Delete permanently?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="recycle_action" value="purge"><input type="hidden" name="entity_type" value="item"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn small-btn danger">Delete</button></form></td></tr><?php endforeach;if(!$items):?><tr><td colspan="4" class="subtle">No deleted items.</td></tr><?php endif;?></tbody></table></div></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Deleted Parties</h2></div><div class="table-wrap"><table><thead><tr><th>NAME</th><th>PHONE</th><th>TYPE</th><th>ACTIONS</th></tr></thead><tbody><?php foreach($partyRows as $r):?><tr><td><?=e($r['name'])?></td><td><?=e($r['phone'])?></td><td><?=e(ucfirst($r['party_type']))?></td><td><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="recycle_action" value="restore"><input type="hidden" name="entity_type" value="party"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn small-btn">Restore</button></form> <form method="post" style="display:inline" onsubmit="return confirm('Delete permanently?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="recycle_action" value="purge"><input type="hidden" name="entity_type" value="party"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn small-btn danger">Delete</button></form></td></tr><?php endforeach;if(!$partyRows):?><tr><td colspan="4" class="subtle">No deleted parties.</td></tr><?php endif;?></tbody></table></div></div><?php page_end();exit;
}

if($route==='audit-log'){
    $u=require_super_admin();$cid=(int)$u['company_id'];$from=$_GET['from']??date('Y-m-01');$to=$_GET['to']??date('Y-m-d');$q=trim($_GET['q']??'');
    $sql='SELECT a.*,u.name user_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.company_id=? AND DATE(a.created_at) BETWEEN ? AND ?';$params=[$cid,$from,$to];if($q!==''){$sql.=' AND (a.action LIKE ? OR a.entity_type LIKE ? OR u.name LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like);} $sql.=' ORDER BY a.id DESC LIMIT 500';$st=db()->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
    page_start('Audit Log'); ?><div class="page-title"><div><h1>Audit Log</h1><p>Track important changes made by company users.</p></div></div><div class="panel"><form class="filters" method="get"><div><label>From</label><input type="date" name="from" value="<?=e($from)?>"></div><div><label>To</label><input type="date" name="to" value="<?=e($to)?>"></div><div><label>Search</label><input name="q" value="<?=e($q)?>" placeholder="Action, entity, user"></div><button class="btn primary" style="align-self:end">Filter</button></form></div><div class="panel" style="margin-top:14px"><div class="table-wrap"><table><thead><tr><th>DATE</th><th>USER</th><th>ACTION</th><th>ENTITY</th><th>ID</th><th>DETAILS</th><th>IP</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['created_at'])?></td><td><?=e($r['user_name']??'System')?></td><td><?=e($r['action'])?></td><td><?=e($r['entity_type']??'-')?></td><td><?=e((string)($r['entity_id']??'-'))?></td><td style="max-width:420px;white-space:normal"><?=e((string)($r['details']??''))?></td><td><?=e($r['ip_address']??'-')?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="7" class="subtle">No audit entries found.</td></tr><?php endif;?></tbody></table></div></div><?php page_end();exit;
}

if($route==='financial-year'){
    $u=require_super_admin();$cid=(int)$u['company_id'];$pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();$fyStart=(int)($_POST['start_year']??date('Y'));$mode=$_POST['mode']??$u['financial_year_mode'];$start=$mode==='jan_dec'?sprintf('%04d-01-01',$fyStart):sprintf('%04d-07-01',$fyStart);$end=$mode==='jan_dec'?sprintf('%04d-12-31',$fyStart):sprintf('%04d-06-30',$fyStart+1); try{$pdo->prepare('INSERT INTO financial_year_closures(company_id,financial_year_mode,start_date,end_date,closed_by,notes) VALUES(?,?,?,?,?,?)')->execute([$cid,$mode,$start,$end,$u['id'],trim($_POST['notes']??'')]);audit('close','financial_year',(int)$pdo->lastInsertId(),['start'=>$start,'end'=>$end]);flash('success','Financial year closed and archived. Existing transaction data remains available for reporting.');}catch(Throwable $e){flash('error','Could not close financial year: '.$e->getMessage());} redirect('financial-year');}
    $rows=$pdo->prepare('SELECT f.*,u.name closed_by_name FROM financial_year_closures f LEFT JOIN users u ON u.id=f.closed_by WHERE f.company_id=? ORDER BY f.id DESC');$rows->execute([$cid]);$rows=$rows->fetchAll();
    page_start('Close Financial Year'); ?><div class="page-title"><div><h1>Close Financial Year</h1><p>Archive a financial year while keeping historical transactions available.</p></div></div><div class="panel"><form method="post" class="grid2"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>Financial Year Mode</label><select name="mode"><option value="july_june" <?=$u['financial_year_mode']==='july_june'?'selected':''?>>1 July – 30 June</option><option value="jan_dec" <?=$u['financial_year_mode']==='jan_dec'?'selected':''?>>1 January – 31 December</option></select></div><div class="form-group"><label>Start Year</label><input type="number" name="start_year" value="<?=e(date('Y'))?>" min="2000" max="2100" required></div><div class="form-group span2"><label>Notes</label><textarea name="notes" placeholder="Optional closing note"></textarea></div><div class="span2"><button class="btn danger" onclick="return confirm('Close and archive this financial year?')">Close Financial Year</button></div></form></div><div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Closed Years</h2></div><div class="table-wrap"><table><thead><tr><th>START</th><th>END</th><th>MODE</th><th>CLOSED BY</th><th>DATE</th><th>NOTES</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['start_date'])?></td><td><?=e($r['end_date'])?></td><td><?=e($r['financial_year_mode'])?></td><td><?=e($r['closed_by_name']??'-')?></td><td><?=e($r['closed_at'])?></td><td><?=e($r['notes']??'')?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6" class="subtle">No financial years closed yet.</td></tr><?php endif;?></tbody></table></div></div><?php page_end();exit;
}

if($route==='export-items'){
    $u=require_login();$cid=(int)$u['company_id'];if(isset($_GET['template'])){ $fp=fopen('php://temp','w+'); fputcsv($fp,['Item Name','Type','Code','Barcode','Category','Unit','Sale Price','Wholesale Price','Minimum Wholesale Qty','Purchase Price','Opening Stock','Low Stock Limit']); rewind($fp); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="suto-item-template.csv"'); fpassthru($fp); exit; } $rows=db()->prepare('SELECT i.name item_name,i.item_type,i.code,i.barcode,c.name category,u.name unit,i.sale_price,i.wholesale_price,i.min_wholesale_qty,i.purchase_price,i.opening_stock,i.low_stock_limit,COALESCE((SELECT SUM(sm.quantity) FROM stock_movements sm WHERE sm.company_id=i.company_id AND sm.item_id=i.id
                     AND (sm.transaction_id IS NULL OR EXISTS (
                         SELECT 1 FROM transactions st
                         WHERE st.id=sm.transaction_id
                           AND st.company_id=sm.company_id
                           AND st.deleted_at IS NULL
                     ))),0) current_stock FROM items i LEFT JOIN categories c ON c.id=i.category_id LEFT JOIN units u ON u.id=i.unit_id WHERE i.company_id=? AND i.active=1 ORDER BY i.name');$rows->execute([$cid]);$rows=$rows->fetchAll();$fp=fopen('php://temp','w+');fputcsv($fp,['Item Name','Type','Code','Barcode','Category','Unit','Sale Price','Wholesale Price','Minimum Wholesale Qty','Purchase Price','Opening Stock','Low Stock Limit','Current Stock']);foreach($rows as $r)fputcsv($fp,$r);rewind($fp);header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="suto-items-'.date('Ymd-His').'.csv"');fpassthru($fp);exit;
}

if($route==='import-items'){
    $u=require_login();$cid=(int)$u['company_id'];$pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        if(empty($_FILES['csv']['tmp_name'])){flash('error','Choose a CSV file.');redirect('import-items');}
        $fh=fopen($_FILES['csv']['tmp_name'],'r'); if(!$fh){flash('error','Unable to read CSV file.');redirect('import-items');}
        $header=fgetcsv($fh);
        $count=0;$skipped=0;$errors=[];$line=1;
        try{
            $pdo->beginTransaction();
            while(($r=fgetcsv($fh))!==false){
                $line++;
                $name=trim($r[0]??''); if($name===''){ $skipped++; continue; }
                $type=in_array(strtolower(trim($r[1]??'product')),['product','service'],true)?strtolower(trim($r[1])):'product';
                $code=trim($r[2]??'')?:null; $barcode=trim($r[3]??'')?:null;
                $categoryName=trim($r[4]??''); $unitName=trim($r[5]??'');
                $sale=(float)($r[6]??0); $wh=(float)($r[7]??0); $minWh=(float)($r[8]??0);
                $buy=(float)($r[9]??0); $opening=$type==='product'?(float)($r[10]??0):0; $low=$type==='product'?(float)($r[11]??0):0;
                $catId=null; $unitId=null;
                if($categoryName!==''){
                    $st=$pdo->prepare('SELECT id,type FROM categories WHERE company_id=? AND name=? LIMIT 1');$st->execute([$cid,$categoryName]);$cat=$st->fetch();
                    if($cat && $cat['type']!==$type){$errors[]="Line $line: category '$categoryName' belongs to {$cat['type']} items.";continue;}
                    if($cat){$catId=(int)$cat['id'];}
                    else{$pdo->prepare('INSERT INTO categories(company_id,name,type) VALUES(?,?,?)')->execute([$cid,$categoryName,$type]);$catId=(int)$pdo->lastInsertId();}
                }
                if($unitName!==''){
                    $st=$pdo->prepare('SELECT id FROM units WHERE company_id=? AND name=? LIMIT 1');$st->execute([$cid,$unitName]);$unit=$st->fetch();
                    if($unit)$unitId=(int)$unit['id']; else{$pdo->prepare('INSERT INTO units(company_id,name,symbol) VALUES(?,?,?)')->execute([$cid,$unitName,$unitName]);$unitId=(int)$pdo->lastInsertId();}
                }
                if($code!==null){$st=$pdo->prepare('SELECT id FROM items WHERE company_id=? AND code=? AND active=1 LIMIT 1');$st->execute([$cid,$code]);if($st->fetch()){$errors[]="Line $line: duplicate item code '$code'.";continue;}}
                if($barcode!==null){$st=$pdo->prepare('SELECT id FROM items WHERE company_id=? AND barcode=? AND active=1 LIMIT 1');$st->execute([$cid,$barcode]);if($st->fetch()){$errors[]="Line $line: duplicate barcode '$barcode'.";continue;}}
                $pdo->prepare('INSERT INTO items(company_id,item_type,name,code,barcode,category_id,unit_id,sale_price,wholesale_price,min_wholesale_qty,purchase_price,opening_stock,low_stock_limit) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$cid,$type,$name,$code,$barcode,$catId,$unitId,$sale,$wh,$minWh,$buy,$opening,$low]);
                $id=(int)$pdo->lastInsertId();
                if($type==='product' && abs($opening)>0.0001){
                    $pdo->prepare('INSERT INTO stock_movements(company_id,item_id,movement_date,quantity,unit_price,movement_type,note) VALUES(?,?,?,?,?,?,?)')
                        ->execute([$cid,$id,date('Y-m-d'),$opening,$buy,'opening_stock','Opening Stock']);
                }
                $count++; audit('import','item',$id,['name'=>$name,'line'=>$line]);
            }
            $pdo->commit();
            $msg=$count.' items imported.';
            if($skipped)$msg.=' '.$skipped.' blank line(s) skipped.';
            flash($errors?'success':'success',$msg.($errors?' Some rows were skipped. Check import notes in Audit Log.':''));
            foreach($errors as $err) audit('import_error','item',null,['message'=>$err]);
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error','Import failed: '.$e->getMessage());
        }
        redirect('import-items');
    }
    page_start('Import Items'); ?><div class="page-title"><div><h1>Import Items</h1><p>Import products/services from CSV.</p></div><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn" href="<?=e(url('export-items'))?>">Download current items CSV</a><a class="btn" href="<?=e(url('export-items').'?template=1')?>">CSV Template</a></div></div><div class="panel"><p class="subtle">CSV columns: Item Name, Type, Code, Barcode, Category, Unit, Sale Price, Wholesale Price, Minimum Wholesale Qty, Purchase Price, Opening Stock, Low Stock Limit.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>CSV file</label><input type="file" name="csv" accept=".csv,text/csv" required></div><button class="btn primary">Import Items</button></form></div><?php page_end();exit;
}
if($route==='import-parties'){
    $u=require_login();$cid=(int)$u['company_id'];if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();if(empty($_FILES['csv']['tmp_name'])){flash('error','Choose a CSV file.');redirect('import-parties');} $fh=fopen($_FILES['csv']['tmp_name'],'r');$header=fgetcsv($fh);$count=0;$pdo=db();try{$pdo->beginTransaction();while(($r=fgetcsv($fh))!==false){$name=trim($r[0]??'');$phone=preg_replace('/\D+/','',$r[1]??'');if($name==='' || !preg_match('/^(013|014|015|016|017|018|019)\d{8}$/',$phone))continue;$rawRoles=trim($r[3]??'customer');$roleMap=['customer','supplier','investor','lender','borrower','employee','other'];$roles=array_values(array_unique(array_intersect($roleMap,array_filter(array_map('trim',preg_split('/[,|]+/',$rawRoles))))));if(!$roles){$roles=['customer'];} $ptype=in_array('customer',$roles,true)&&in_array('supplier',$roles,true)?'both':(in_array('supplier',$roles,true)?'supplier':'customer');$pdo->prepare('INSERT INTO parties(company_id,name,phone,email,party_type,address,opening_balance,opening_balance_type,credit_limit) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$cid,$name,$phone,trim($r[2]??'')?:null,$ptype,trim($r[4]??'')?:null,(float)($r[5]??0),'receivable',(float)($r[6]??0)]);$pid=(int)$pdo->lastInsertId();$pri=$pdo->prepare('INSERT INTO party_roles(party_id,role) VALUES(?,?)');foreach($roles as $rr)$pri->execute([$pid,$rr]);$count++;audit('import','party',$pid,['name'=>$name,'phone'=>$phone,'roles'=>$roles]);} $pdo->commit();flash('success',$count.' parties imported.');}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error','Import failed: '.$e->getMessage());}redirect('import-parties');}
    page_start('Import Parties'); ?><div class="page-title"><div><h1>Import Parties</h1><p>Import customers/suppliers from CSV.</p></div></div><div class="panel"><p class="subtle">CSV columns: Party Name, Phone, Email, Party Type, Address, Opening Balance, Credit Limit.</p><form method="post" enctype="multipart/form-data"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>CSV file</label><input type="file" name="csv" accept=".csv,text/csv" required></div><button class="btn primary">Import Parties</button></form></div><?php page_end();exit;
}

if($route==='barcode'){
    $u=require_login();$cid=(int)$u['company_id'];$q=trim($_GET['q']??'');$rows=[];if($q!==''){$st=db()->prepare('SELECT id,name,code,barcode,sale_price FROM items WHERE company_id=? AND active=1 AND (name LIKE ? OR code LIKE ? OR barcode LIKE ?) ORDER BY name LIMIT 100');$like='%'.$q.'%';$st->execute([$cid,$like,$like,$like]);$rows=$st->fetchAll();}
    page_start('Generate Barcode'); ?><div class="page-title"><div><h1>Generate Barcode</h1><p>Find an item and generate a printable barcode label.</p></div></div><div class="panel"><form class="search-inline" method="get"><input name="q" value="<?=e($q)?>" placeholder="Search item name, code or barcode"><button class="btn primary">Search</button></form></div><div class="grid3" style="margin-top:14px"><?php foreach($rows as $r):?><div class="panel barcode-card"><h2><?=e($r['name'])?></h2><div class="barcode-lines"><?=e($r['barcode']?:($r['code']?:'NO-CODE'))?></div><p class="subtle"><?=e($r['barcode']?:($r['code']?:'Generate code in item settings'))?></p><button class="btn" onclick="window.print()">Print Label</button></div><?php endforeach;if($q!==''&&!$rows):?><div class="panel"><p class="subtle">No matching items.</p></div><?php endif;?></div><?php page_end();exit;
}

if($route==='bulk-update'){
    $u=require_login();$cid=(int)$u['company_id'];$pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $id=(int)($_POST['id']??0);
        $name=trim((string)($_POST['name']??''));
        $sale=(float)($_POST['sale_price']??0);
        $purchase=(float)($_POST['purchase_price']??0);
        $low=(float)($_POST['low_stock_limit']??0);
        if($id){
            if($name===''){
                flash('error','Item name is required.');
                redirect('bulk-update');
            }
            $st=$pdo->prepare('UPDATE items SET name=?,sale_price=?,purchase_price=?,low_stock_limit=? WHERE id=? AND company_id=?');
            $st->execute([$name,$sale,$purchase,$low,$id,$cid]);
            audit('bulk_update','item',$id,['name'=>$name,'sale_price'=>$sale,'purchase_price'=>$purchase,'low_stock_limit'=>$low]);
            flash('success','Item updated.');
        }
        redirect('bulk-update');
    }
    $st=$pdo->prepare('SELECT id,name,item_type,sale_price,purchase_price,low_stock_limit FROM items WHERE company_id=? AND active=1 ORDER BY name');$st->execute([$cid]);$rows=$st->fetchAll();
    page_start('Bulk Update Item'); ?>
    <div class="page-title"><div><h1>Bulk Update Item</h1><p>Edit item name, prices and stock alerts quickly.</p></div></div>
    <div class="panel table-wrap"><table><thead><tr><th>ITEM</th><th>TYPE</th><th>SALE PRICE</th><th>PURCHASE PRICE</th><th>LOW STOCK LIMIT</th><th></th></tr></thead><tbody>
    <?php foreach($rows as $r): $formId='bulkItem'.(int)$r['id']; ?>
      <tr>
        <td>
          <form id="<?=$formId?>" method="post" class="inline-form">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="id" value="<?=$r['id']?>">
            <input name="name" type="text" value="<?=e($r['name'])?>" required style="min-width:220px;width:100%;">
          </form>
        </td>
        <td><?=e($r['item_type'])?></td>
        <td><input form="<?=$formId?>" name="sale_price" type="number" step="0.01" value="<?=e($r['sale_price'])?>"></td>
        <td><input form="<?=$formId?>" name="purchase_price" type="number" step="0.01" value="<?=e($r['purchase_price'])?>"></td>
        <td><input form="<?=$formId?>" name="low_stock_limit" type="number" step="0.01" value="<?=e($r['low_stock_limit'])?>"></td>
        <td><button form="<?=$formId?>" type="submit" class="btn small-btn primary">Save</button></td>
      </tr>
    <?php endforeach;if(!$rows):?><tr><td colspan="6" class="subtle">No items.</td></tr><?php endif;?></tbody></table></div><?php page_end();exit;
}

if($route==='settings'){
$u=require_super_admin();$cid=(int)$u['company_id'];$pdo=db();
$tab=$_GET['tab']??'company'; if(!in_array($tab,['company','features','numbering','tax','currency','branches','warehouses'],true))$tab='company';
if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf(); $action=$_POST['action']??'';
    try{
        if($action==='company'){
            $name=trim($_POST['name']??'');$email=trim($_POST['email']??'');$phone=trim($_POST['phone']??'');$address=trim($_POST['address']??'');$biz=trim($_POST['business_type']??'');$currency=$_POST['currency_code']??'BDT';$fy=$_POST['financial_year_mode']??'july_june';
            if($name==='') throw new RuntimeException('Company name is required.');
            if(!in_array($fy,['july_june','jan_dec'],true))$fy='july_june';
            if(!preg_match('/^[A-Z]{3}$/',$currency))$currency='BDT';
            $logoPath=null;
            $oldLogo=(string)($pdo->query('SELECT logo_path FROM companies WHERE id='.(int)$cid)->fetchColumn()??'');
            if(!empty($_FILES['company_logo']['tmp_name'])){
                $file=$_FILES['company_logo'];
                if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Could not upload company logo.');
                if(($file['size']??0)>2*1024*1024)throw new RuntimeException('Company logo must be 2 MB or smaller.');
                $finfo=new finfo(FILEINFO_MIME_TYPE);$mime=$finfo->file($file['tmp_name']);$allowed=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
                if(!isset($allowed[$mime]))throw new RuntimeException('Logo must be PNG, JPG or WebP.');
                $dir=__DIR__.'/../public/uploads/company-logos';if(!is_dir($dir))@mkdir($dir,0755,true);
                $logoPath='uploads/company-logos/company-'.$cid.'-'.bin2hex(random_bytes(6)).'.'.$allowed[$mime];
                if(!move_uploaded_file($file['tmp_name'],__DIR__.'/../public/'.$logoPath))throw new RuntimeException('Could not save company logo.');
                if($oldLogo!=='' && is_file(__DIR__.'/../public/'.ltrim($oldLogo,'/')))@unlink(__DIR__.'/../public/'.ltrim($oldLogo,'/'));
            }
            if($logoPath!==null)$pdo->prepare('UPDATE companies SET name=?,email=?,phone=?,address=?,business_type=?,currency_code=?,financial_year_mode=?,logo_path=? WHERE id=?')->execute([$name,$email,$phone,$address,$biz,$currency,$fy,$logoPath,$cid]);
            else $pdo->prepare('UPDATE companies SET name=?,email=?,phone=?,address=?,business_type=?,currency_code=?,financial_year_mode=? WHERE id=?')->execute([$name,$email,$phone,$address,$biz,$currency,$fy,$cid]);
            flash('success','Company profile updated.');
        } elseif($action==='features'){
            $keys=['enable_branches','enable_warehouses','enable_multicurrency','enable_tax_vat','negative_stock','sale_discount_mode','purchase_discount_mode'];
            foreach($keys as $k){save_setting($cid,$k,(string)($_POST[$k]??''));}
            flash('success','System preferences updated.');
        } elseif($action==='numbering'){
            foreach(['sale_prefix','purchase_prefix','payment_in_prefix','payment_out_prefix','quotation_prefix','sale_order_prefix','delivery_challan_prefix','sale_return_prefix','purchase_return_prefix','expense_prefix'] as $k){$v=trim($_POST[$k]??'');if($v!==''&&!preg_match('/^[A-Za-z0-9_-]{1,12}$/',$v))throw new RuntimeException('Invalid prefix for '.$k.'.');save_setting($cid,$k,$v);}
            flash('success','Document numbering settings updated.');
        } elseif($action==='currency'){
            $code=strtoupper(trim($_POST['code']??''));$name=trim($_POST['name']??'');$symbol=trim($_POST['symbol']??'');$rate=(float)($_POST['exchange_rate']??1);if(!preg_match('/^[A-Z]{3}$/',$code)||$name==='')throw new RuntimeException('Valid currency code and name are required.');$pdo->prepare('INSERT INTO currencies(company_id,code,name,symbol,exchange_rate) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),symbol=VALUES(symbol),exchange_rate=VALUES(exchange_rate),active=1')->execute([$cid,$code,$name,$symbol,$rate>0?$rate:1]);flash('success','Currency saved.');
        } elseif($action==='tax'){
            $name=trim($_POST['name']??'');$rate=(float)($_POST['rate']??0);$type=$_POST['tax_type']??'vat';if($name==='')throw new RuntimeException('Tax/VAT name is required.');if(!in_array($type,['tax','vat'],true))$type='vat';$pdo->prepare('INSERT INTO tax_rates(company_id,name,rate,tax_type) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE rate=VALUES(rate),tax_type=VALUES(tax_type),active=1')->execute([$cid,$name,$rate,$type]);flash('success','Tax/VAT rate saved.');
        } elseif($action==='branch'){
            $name=trim($_POST['name']??'');$code=trim($_POST['code']??'');if($name==='')throw new RuntimeException('Branch name is required.');$pdo->prepare('INSERT INTO branches(company_id,name,code,is_default) VALUES(?,?,?,0)')->execute([$cid,$name,$code]);flash('success','Branch added.');
        } elseif($action==='warehouse'){
            $name=trim($_POST['name']??'');$code=trim($_POST['code']??'');$branch=(int)($_POST['branch_id']??0);$address=trim($_POST['address']??'');if($name==='')throw new RuntimeException('Warehouse name is required.');$pdo->prepare('INSERT INTO warehouses(company_id,branch_id,name,code,address) VALUES(?,?,?,?,?)')->execute([$cid,$branch?:null,$name,$code,$address]);flash('success','Warehouse added.');
        }
    }catch(Throwable $e){flash('error',$e->getMessage());}
    redirect('settings?tab='.rawurlencode($tab));
}
$st=$pdo->prepare('SELECT * FROM companies WHERE id=?');$st->execute([$cid]);$c=$st->fetch();
$defaults=['sale_prefix'=>'SI','purchase_prefix'=>'PB','payment_in_prefix'=>'PI','payment_out_prefix'=>'PO','quotation_prefix'=>'QT','sale_order_prefix'=>'SO','delivery_challan_prefix'=>'DC','sale_return_prefix'=>'SR','purchase_return_prefix'=>'PR','expense_prefix'=>'EX','enable_branches'=>'0','enable_warehouses'=>'0','enable_multicurrency'=>'0','enable_tax_vat'=>'0','negative_stock'=>'1','sale_discount_mode'=>'both','purchase_discount_mode'=>'both'];
foreach($defaults as $k=>$v){$defaults[$k]=setting($k,$v,$cid);}
page_start('Settings'); ?>
<div class="settings-shell"><div class="page-title"><div><h1>Settings</h1><p>Company profile and system configuration</p></div></div>
<div class="settings-tabs">
<a class="<?= $tab==='company'?'active':'' ?>" href="<?=e(url('settings?tab=company'))?>">Company</a><a class="<?= $tab==='features'?'active':'' ?>" href="<?=e(url('settings?tab=features'))?>">Features</a><a class="<?= $tab==='numbering'?'active':'' ?>" href="<?=e(url('settings?tab=numbering'))?>">Invoice Numbering</a><a class="<?= $tab==='tax'?'active':'' ?>" href="<?=e(url('settings?tab=tax'))?>">Tax / VAT</a><a class="<?= $tab==='currency'?'active':'' ?>" href="<?=e(url('settings?tab=currency'))?>">Currencies</a><a class="<?= $tab==='branches'?'active':'' ?>" href="<?=e(url('settings?tab=branches'))?>">Branches</a><a class="<?= $tab==='warehouses'?'active':'' ?>" href="<?=e(url('settings?tab=warehouses'))?>">Warehouses</a><a href="<?=e(url('team'))?>">Users & Roles</a></div>
<?php if($tab==='company'): ?>
<form method="post" enctype="multipart/form-data" class="panel settings-card"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="company"><div class="settings-card-head"><div><h2>Company Profile</h2><p class="subtle">Default information used across invoices and reports.</p></div><button class="btn primary">Save Changes</button></div><div class="settings-form-grid"><div class="form-group"><label>Company Name*</label><input name="name" value="<?=e($c['name']??'')?>" required></div><div class="form-group"><label>Company Email</label><input type="email" name="email" value="<?=e($c['email']??'')?>"></div><div class="form-group"><label>Phone</label><input name="phone" value="<?=e($c['phone']??'')?>"></div><div class="form-group"><label>Business Type</label><input name="business_type" value="<?=e($c['business_type']??'')?>"></div><div class="form-group"><label>Default Currency</label><select name="currency_code"><option value="BDT" <?=($c['currency_code']==='BDT'?'selected':'')?>>BDT — ৳</option><option value="USD" <?=($c['currency_code']==='USD'?'selected':'')?>>USD — $</option><option value="EUR" <?=($c['currency_code']==='EUR'?'selected':'')?>>EUR — €</option><option value="GBP" <?=($c['currency_code']==='GBP'?'selected':'')?>>GBP — £</option></select></div><div class="form-group"><label>Financial Year</label><select name="financial_year_mode"><option value="july_june" <?=($c['financial_year_mode']==='july_june'?'selected':'')?>>1 July – 30 June</option><option value="jan_dec" <?=($c['financial_year_mode']==='jan_dec'?'selected':'')?>>1 January – 31 December</option></select></div><div class="form-group"><label>Primary Logo</label><input type="file" name="company_logo" accept="image/png,image/jpeg,image/webp"><?php if(!empty($c['logo_path'])): ?><div style="margin-top:8px"><img src="<?=e(saas_company_logo_url($c['logo_path']))?>" alt="Company logo" style="max-height:64px;max-width:220px;border:1px solid #e2e8f0;border-radius:8px;padding:6px;background:#fff"></div><?php endif; ?></div><div class="form-group full"><label>Address</label><textarea name="address" rows="3"><?=e($c['address']??'')?></textarea></div></div></form>
<div class="grid3 settings-info"><div class="panel"><h3>Admin Account</h3><p><b><?=e($u['name'])?></b></p><p><?=e($u['email'])?></p><span class="status paid">Super Admin</span></div><div class="panel"><h3>Security</h3><p>CSRF protection enabled</p><p>Password hashing enabled</p><p>Company data isolation enabled</p></div><div class="panel"><h3>Team</h3><p>Manage invited users and their access.</p><a class="btn" href="<?=e(url('team'))?>">Manage Users & Roles</a></div></div>
<?php elseif($tab==='features'): ?>
<form method="post" class="panel settings-card"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="features"><div class="settings-card-head"><div><h2>Business Features</h2><p class="subtle">Optional modules can be enabled when your business needs them.</p></div><button class="btn primary">Save Preferences</button></div><div class="feature-grid"><?php foreach([['enable_branches','Multiple Branches','Enable branch-wise transactions and stock.'],['enable_warehouses','Multiple Warehouses','Enable warehouse-wise stock tracking.'],['enable_multicurrency','Multi Currency','Allow currencies other than the default company currency.'],['enable_tax_vat','Tax / VAT','Show optional tax and VAT controls on invoices.'],['negative_stock','Negative Stock','Allow sales to continue when stock goes below zero.'] ] as $f):?><label class="feature-toggle"><input type="checkbox" name="<?=$f[0]?>" value="1" <?= $defaults[$f[0]]==='1'?'checked':'' ?>><span><b><?=e($f[1])?></b><small><?=e($f[2])?></small></span></label><?php endforeach;?><div class="form-group"><label>Sale Discount</label><select name="sale_discount_mode"><option value="both" <?=($defaults['sale_discount_mode']==='both'?'selected':'')?>>Item + Invoice level</option><option value="item" <?=($defaults['sale_discount_mode']==='item'?'selected':'')?>>Item level only</option><option value="invoice" <?=($defaults['sale_discount_mode']==='invoice'?'selected':'')?>>Invoice level only</option></select></div><div class="form-group"><label>Purchase Discount</label><select name="purchase_discount_mode"><option value="both" <?=($defaults['purchase_discount_mode']==='both'?'selected':'')?>>Item + Invoice level</option><option value="item" <?=($defaults['purchase_discount_mode']==='item'?'selected':'')?>>Item level only</option><option value="invoice" <?=($defaults['purchase_discount_mode']==='invoice'?'selected':'')?>>Invoice level only</option></select></div></div></form>
<?php elseif($tab==='numbering'): ?>
<form method="post" class="panel settings-card"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="numbering"><div class="settings-card-head"><div><h2>Document Numbering</h2><p class="subtle">Prefixes are used with the existing six-digit sequence.</p></div><button class="btn primary">Save Numbering</button></div><div class="settings-form-grid numbering-grid"><?php foreach([['sale_prefix','Sales Invoice'],['purchase_prefix','Purchase Bill'],['payment_in_prefix','Payment In'],['payment_out_prefix','Payment Out'],['quotation_prefix','Quotation'],['sale_order_prefix','Sale Order'],['delivery_challan_prefix','Delivery Challan'],['sale_return_prefix','Sale Return / Credit Note'],['purchase_return_prefix','Purchase Return / Debit Note'],['expense_prefix','Expense']] as $f):?><div class="form-group"><label><?=e($f[1])?></label><div class="prefix-input"><input name="<?=$f[0]?>" value="<?=e($defaults[$f[0]])?>"><span>-000001</span></div></div><?php endforeach;?></div></form>
<?php elseif($tab==='currency'): $cur=$pdo->prepare('SELECT * FROM currencies WHERE company_id=? ORDER BY code');$cur->execute([$cid]);$currencyRows=$cur->fetchAll(); ?>
<div class="grid2"><form method="post" class="panel settings-card"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="currency"><h2>Add Currency</h2><div class="form-group"><label>Code*</label><input name="code" maxlength="3" required placeholder="USD"></div><div class="form-group"><label>Name*</label><input name="name" required placeholder="US Dollar"></div><div class="form-group"><label>Symbol</label><input name="symbol" placeholder="$"></div><div class="form-group"><label>Exchange Rate</label><input name="exchange_rate" type="number" step="0.000001" value="1"></div><button class="btn primary">Save Currency</button></form><div class="panel settings-card"><h2>Configured Currencies</h2><div class="settings-list"><?php foreach($currencyRows as $cr):?><div class="settings-list-row"><div><b><?=e($cr['code'])?> — <?=e($cr['name'])?></b><small><?=e($cr['symbol'])?> · Rate <?=e((string)$cr['exchange_rate'])?></small></div></div><?php endforeach;?></div></div></div>
<?php elseif($tab==='tax'): $tr=$pdo->prepare('SELECT * FROM tax_rates WHERE company_id=? ORDER BY name');$tr->execute([$cid]);$taxRows=$tr->fetchAll(); ?>
<div class="grid2"><form method="post" class="panel settings-card"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="tax"><h2>Add Tax / VAT</h2><div class="form-group"><label>Name*</label><input name="name" required placeholder="VAT 15%"></div><div class="form-group"><label>Rate %</label><input name="rate" type="number" min="0" step="0.01" value="15"></div><div class="form-group"><label>Type</label><select name="tax_type"><option value="vat">VAT</option><option value="tax">Tax</option></select></div><button class="btn primary">Save Rate</button></form><div class="panel settings-card"><h2>Configured Rates</h2><div class="settings-list"><?php foreach($taxRows as $tx):?><div class="settings-list-row"><div><b><?=e($tx['name'])?></b><small><?=e((string)$tx['rate'])?>% · <?=e(strtoupper($tx['tax_type']))?></small></div></div><?php endforeach;?></div></div></div>
<?php elseif($tab==='branches'): $br=$pdo->prepare('SELECT * FROM branches WHERE company_id=? ORDER BY name');$br->execute([$cid]);$branchRows=$br->fetchAll(); ?>
<div class="grid2"><form method="post" class="panel settings-card"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="branch"><h2>Add Branch</h2><div class="form-group"><label>Branch Name*</label><input name="name" required></div><div class="form-group"><label>Code</label><input name="code"></div><button class="btn primary">Add Branch</button></form><div class="panel settings-card"><h2>Branches</h2><div class="settings-list"><?php foreach($branchRows as $brx):?><div class="settings-list-row"><div><b><?=e($brx['name'])?></b><small><?=e($brx['code']??'')?> <?=e($brx['phone']??'')?></small></div></div><?php endforeach;if(!$branchRows):?><p class="subtle">No branches added.</p><?php endif;?></div></div></div>
<?php elseif($tab==='warehouses'): $wr=$pdo->prepare('SELECT w.*,b.name branch_name FROM warehouses w LEFT JOIN branches b ON b.id=w.branch_id WHERE w.company_id=? ORDER BY w.name');$wr->execute([$cid]);$warehouseRows=$wr->fetchAll();$branchOptions=$pdo->prepare('SELECT id,name FROM branches WHERE company_id=? AND active=1 ORDER BY name');$branchOptions->execute([$cid]);$bo=$branchOptions->fetchAll(); ?>
<div class="grid2"><form method="post" class="panel settings-card"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="warehouse"><h2>Add Warehouse</h2><div class="form-group"><label>Warehouse Name*</label><input name="name" required></div><div class="form-group"><label>Code</label><input name="code"></div><div class="form-group"><label>Branch</label><select name="branch_id"><option value="0">No branch / Main</option><?php foreach($bo as $b):?><option value="<?=$b['id']?>"><?=e($b['name'])?></option><?php endforeach;?></select></div><div class="form-group"><label>Address</label><textarea name="address" rows="3"></textarea></div><button class="btn primary">Add Warehouse</button></form><div class="panel settings-card"><h2>Warehouses</h2><div class="settings-list"><?php foreach($warehouseRows as $wx):?><div class="settings-list-row"><div><b><?=e($wx['name'])?></b><small><?=e($wx['branch_name']??'Main')?></small></div></div><?php endforeach;if(!$warehouseRows):?><p class="subtle">No warehouses added.</p><?php endif;?></div></div></div>
<?php endif; ?></div><?php page_end();exit;
}
http_response_code(404);page_start('Not Found');echo '<div class="panel"><h1>Page not found</h1></div>';page_end();
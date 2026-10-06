<?php
declare(strict_types=1);
$configFile = __DIR__ . '/../config.php';
if (!file_exists($configFile)) { http_response_code(500); exit('Application is not configured.'); }
$config = require $configFile;
// sense rebrand: keep one codebase working on both the legacy /ERP path and the new subdomain.
$config['app']['name'] = 'sense';
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Dhaka');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Lax']);
    session_start();
}
function db(): PDO {
    static $pdo=null;
    static $schemaReady=false;
    global $config;
    if($pdo)return $pdo;
    $dsn=sprintf('mysql:host=%s;dbname=%s;charset=%s',$config['db']['host'],$config['db']['name'],$config['db']['charset']);
    $pdo=new PDO($dsn,$config['db']['user'],$config['db']['pass'],[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false
    ]);
    // Keep database-generated timestamps aligned with the ERP timezone.
    try{$pdo->exec("SET time_zone = '+06:00'");}catch(Throwable $e){}

    // Make transaction timestamps work even when the migration was not run manually.
    if(!$schemaReady){
        $schemaReady=true;
        try{
            $exists=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transactions' AND COLUMN_NAME='created_at'")->fetchColumn();
            if(!$exists){
                $pdo->exec("ALTER TABLE transactions ADD COLUMN created_at DATETIME NULL DEFAULT NULL AFTER txn_date");
            }
            // Recover real creation times from the audit trail where available.
            $pdo->exec("UPDATE transactions t
                JOIN (
                    SELECT company_id, entity_id, MIN(created_at) created_at
                    FROM audit_logs
                    WHERE entity_type='transaction' AND action='create'
                    GROUP BY company_id, entity_id
                ) a ON a.company_id=t.company_id AND a.entity_id=t.id
                SET t.created_at=a.created_at
                WHERE t.created_at IS NULL OR TIME(t.created_at)='00:00:00'");

            // Always give newly-created transactions an actual creation timestamp.
            // The trigger is the final safety net for legacy INSERT statements that
            // omit the created_at column.
            try{$pdo->exec("ALTER TABLE transactions MODIFY COLUMN created_at DATETIME NULL DEFAULT CURRENT_TIMESTAMP");}catch(Throwable $e){}
            try{$pdo->exec("CREATE INDEX idx_transactions_created_at ON transactions(company_id,created_at)");}catch(Throwable $e){}
            try{
                $tr=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='trg_transactions_created_at'")->fetchColumn();
                if(!$tr){
                    $pdo->exec("CREATE TRIGGER trg_transactions_created_at BEFORE INSERT ON transactions FOR EACH ROW SET NEW.created_at = COALESCE(NEW.created_at, NOW())");
                }
            }catch(Throwable $e){}
        }catch(Throwable $e){}
    }
    return $pdo;
}
function e(?string $v): string { return htmlspecialchars($v??'',ENT_QUOTES,'UTF-8'); }
function base_url(): string {
    global $config;
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    return $host === 'sense.suto.bd' ? '' : rtrim((string)($config['app']['base_url']??'/ERP'),'/');
}
function url(string $path=''): string { $b=base_url(); $p=ltrim($path,'/'); return $b . ($p?'/'.$p:''); }
function redirect(string $path): never { header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); header('Location: '.url($path), true, 303); exit; }
function csrf_token(): string { if(empty($_SESSION['_csrf']))$_SESSION['_csrf']=bin2hex(random_bytes(32)); return $_SESSION['_csrf']; }
function check_csrf(): void { if(!hash_equals($_SESSION['_csrf']??'',$_POST['_csrf']??'')){http_response_code(419);exit('Invalid CSRF token.');} }
function flash(string $type,string $message): void { $_SESSION['_flash'][]=[$type,$message]; }
function flashes(): array { $x=$_SESSION['_flash']??[]; unset($_SESSION['_flash']); return $x; }
function user(): ?array { static $u=false; if($u!==false)return $u; if(empty($_SESSION['uid']))return $u=null; $st=db()->prepare('SELECT u.*,c.name company_name,c.currency_code,c.financial_year_mode,c.logo_path FROM users u JOIN companies c ON c.id=u.company_id WHERE u.id=? AND u.status="active" LIMIT 1'); $st->execute([$_SESSION['uid']]); return $u=$st->fetch()?:null; }
function require_login(): array { $u=user(); if(!$u)redirect('login'); return $u; }
function require_super_admin(): array { $u=require_login(); if($u['role']!=='super_admin'){http_response_code(403);exit('Forbidden');} return $u; }
function has_permission(string $code): bool { return sense_has_permission_v203($code); }
function require_permission(string $code): void { require_login(); if(!has_permission($code)){http_response_code(403);exit('You do not have permission to perform this action.');} }
function unread_messages_count(int $uid): int { $st=db()->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id=? AND read_at IS NULL'); $st->execute([$uid]); return (int)$st->fetchColumn(); }
function unread_notifications_count(int $uid): int { try{$st=db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL AND (deleted_at IS NULL OR deleted_at='0000-00-00 00:00:00')"); $st->execute([$uid]); return (int)$st->fetchColumn();}catch(Throwable $e){$st=db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL'); $st->execute([$uid]); return (int)$st->fetchColumn();} }
function money(float $n,string $currency='৳'): string { return $currency.number_format($n,2); }
function transaction_datetime(?string $raw=null): string {
    global $config;
    $tz=new DateTimeZone($config['app']['timezone'] ?? 'Asia/Dhaka');
    $now=new DateTimeImmutable('now',$tz);
    $raw=trim((string)$raw);
    if($raw==='') return $now->format('Y-m-d H:i:s');
    if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$raw)){
        return $raw.' '.$now->format('H:i:s');
    }
    foreach(['Y-m-d\TH:i','Y-m-d\TH:i:s','Y-m-d H:i','Y-m-d H:i:s'] as $fmt){
        $dt=DateTimeImmutable::createFromFormat($fmt,$raw,$tz);
        if($dt instanceof DateTimeImmutable) return $dt->format('Y-m-d H:i:s');
    }
    $ts=strtotime($raw);
    return $ts!==false ? (new DateTimeImmutable('@'.$ts))->setTimezone($tz)->format('Y-m-d H:i:s') : $now->format('Y-m-d H:i:s');
}
function qty(float $n): string {
    if (abs($n) < 0.0000001) return '0';
    $s = number_format($n, 2, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}
function audit(string $action,string $entity,int $id,?array $details=null): void { $u=user(); if(!$u)return; db()->prepare('INSERT INTO audit_logs(company_id,user_id,action,entity_type,entity_id,details,ip_address) VALUES(?,?,?,?,?,?,?)')->execute([$u['company_id'],$u['id'],$action,$entity,$id,$details?json_encode($details,JSON_UNESCAPED_UNICODE):null,$_SERVER['REMOTE_ADDR']??null]); }
function company_id(): int { $u=require_login(); return (int)$u['company_id']; }
function setting(string $key, ?string $default=null, ?int $cid=null): ?string { $u=user(); $cid=$cid??($u['company_id']??null); if(!$cid)return $default; try{$st=db()->prepare('SELECT setting_value FROM company_settings WHERE company_id=? AND setting_key=? LIMIT 1');$st->execute([(int)$cid,$key]);$v=$st->fetchColumn();return $v===false?$default:(string)$v;}catch(Throwable $e){return $default;} }
function save_setting(int $cid,string $key,string $value): void { db()->prepare('INSERT INTO company_settings(company_id,setting_key,setting_value) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)')->execute([$cid,$key,$value]); }


function platform_admin(): ?array {
    static $a=false;
    if($a!==false)return $a;
    if(empty($_SESSION['platform_admin_id']))return $a=null;
    try{
        $st=db()->prepare('SELECT * FROM platform_admins WHERE id=? AND status="active" LIMIT 1');
        $st->execute([(int)$_SESSION['platform_admin_id']]);
        return $a=$st->fetch()?:null;
    }catch(Throwable $e){return $a=null;}
}
function is_platform_admin(): bool { return platform_admin()!==null; }
function require_platform_admin(): array { $a=platform_admin(); if(!$a){redirect('platform-login');} return $a; }
function format_bytes(float $bytes): string { $units=['B','KB','MB','GB','TB']; $i=0; while($bytes>=1024 && $i<count($units)-1){$bytes/=1024;$i++;} return number_format($bytes,$i?1:0).' '.$units[$i]; }
function platform_active_announcements(int $companyId, int $limit=6): array {
    try{
        $st=db()->prepare("SELECT a.id,a.title,a.body,a.type,a.priority,a.starts_at,a.ends_at FROM platform_announcements a WHERE a.is_active=1 AND a.starts_at<=NOW() AND (a.ends_at IS NULL OR a.ends_at>=NOW()) AND (a.target_type='all' OR a.target_company_id=?) ORDER BY a.priority DESC,a.id DESC LIMIT ".max(1,(int)$limit));
        $st->execute([$companyId]); return $st->fetchAll();
    }catch(Throwable $e){return [];}
}
function platform_record_login(int $userId): void {
    try{
        $st=db()->prepare('SELECT company_id FROM users WHERE id=? LIMIT 1');$st->execute([$userId]);$cid=(int)$st->fetchColumn();
        if($cid<=0)return;
        db()->prepare('INSERT INTO platform_login_events(user_id,company_id,ip_address,user_agent,created_at) VALUES(?,?,?,?,NOW())')->execute([$userId,$cid,$_SERVER['REMOTE_ADDR']??null,substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);
        $sid=session_id(); if($sid!=='') db()->prepare('INSERT INTO platform_sessions(session_id,user_id,company_id,last_seen_at,created_at,ip_address,user_agent) VALUES(?,?,?,?,NOW(),?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),company_id=VALUES(company_id),last_seen_at=VALUES(last_seen_at),ip_address=VALUES(ip_address),user_agent=VALUES(user_agent)')->execute([$sid,$userId,$cid,date('Y-m-d H:i:s'),$_SERVER['REMOTE_ADDR']??null,substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);
    }catch(Throwable $e){}
}
function platform_touch_current_session(): void {
    static $done=false; if($done)return; $done=true; if(empty($_SESSION['uid']))return;
    try{
        $sid=session_id(); if($sid==='')return;
        $st=db()->prepare('SELECT company_id FROM users WHERE id=? LIMIT 1');$st->execute([(int)$_SESSION['uid']]);$cid=(int)$st->fetchColumn(); if($cid<=0)return;
        $now=date('Y-m-d H:i:s');
        $q=db()->prepare('SELECT last_seen_at FROM platform_sessions WHERE session_id=? LIMIT 1');$q->execute([$sid]);$last=$q->fetchColumn();
        if($last===false){db()->prepare('INSERT INTO platform_sessions(session_id,user_id,company_id,last_seen_at,created_at,ip_address,user_agent) VALUES(?,?,?,?,NOW(),?,?)')->execute([$sid,(int)$_SESSION['uid'],$cid,$now,$_SERVER['REMOTE_ADDR']??null,substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);}
        elseif(strtotime((string)$last)<time()-60){db()->prepare('UPDATE platform_sessions SET last_seen_at=?,user_id=?,company_id=? WHERE session_id=?')->execute([$now,(int)$_SESSION['uid'],$cid,$sid]);}
    }catch(Throwable $e){}
}

function next_document(string $type,string $prefix): string { $u=user(); $pdo=db(); $pdo->beginTransaction(); try { $st=$pdo->prepare('SELECT next_number FROM document_sequences WHERE company_id=? AND document_type=? FOR UPDATE'); $st->execute([$u['company_id'],$type]); $n=$st->fetchColumn(); if($n===false){$n=1;$pdo->prepare('INSERT INTO document_sequences(company_id,document_type,next_number) VALUES(?,?,2)')->execute([$u['company_id'],$type]);} else {$pdo->prepare('UPDATE document_sequences SET next_number=next_number+1 WHERE company_id=? AND document_type=?')->execute([$u['company_id'],$type]);} $pdo->commit(); return $prefix.str_pad((string)$n,6,'0',STR_PAD_LEFT);} catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;} }

/**
 * v203: enforce role permissions at the request/backend layer.
 * The earlier Team & Permissions UI only stored role_permissions, while many
 * application routes did not consult them. This guard makes View/Add/Edit/Delete/
 * Print/Export/Approve effective even when a user bypasses the UI and calls a URL
 * or POST endpoint directly.
 */
function sense_has_permission_v203(string $code): bool {
    $u = user();
    if (!$u) return false;
    if (($u['role'] ?? '') === 'super_admin') return true;

    $pdo = db();
    $cid = (int)($u['company_id'] ?? 0);
    $roleId = (int)($u['role_id'] ?? 0);
    if ($cid <= 0) return false;

    try {
        if ($roleId > 0) {
            $st = $pdo->prepare('SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id JOIN roles r ON r.id=rp.role_id WHERE r.company_id=? AND r.id=? AND p.code=? LIMIT 1');
            $st->execute([$cid, $roleId, $code]);
        } else {
            $roleNameMap = [
                'admin'=>'Admin', 'manager'=>'Manager', 'accountant'=>'Accountant',
                'sales'=>'Sales', 'purchase'=>'Purchase', 'viewer'=>'Viewer', 'custom'=>'Custom'
            ];
            $roleName = $roleNameMap[(string)($u['role'] ?? '')] ?? trim((string)($u['role'] ?? ''));
            $st = $pdo->prepare('SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id JOIN roles r ON r.id=rp.role_id WHERE r.company_id=? AND r.name=? AND p.code=? LIMIT 1');
            $st->execute([$cid, $roleName, $code]);
        }
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        error_log('v203 permission check: '.$e->getMessage());
        return false;
    }
}

function sense_v203_route_path(): string {
    $path = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    $path = rawurldecode($path);
    $path = preg_replace('#^/ERP(?:/public)?/?#', '/', $path);
    $path = preg_replace('#/index\.php$#', '', $path);
    return trim($path, '/');
}

function sense_v203_deny(string $permission, string $message = ''): never {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    $label = $message !== '' ? $message : ('You do not have permission to '.$permission.' this action.');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>403 · Forbidden</title>';
    echo '<style>body{font-family:Arial,sans-serif;background:#f5f7fa;margin:0;padding:50px;color:#1f2937}.box{max-width:620px;margin:0 auto;background:#fff;border:1px solid #dfe5ec;border-radius:12px;padding:28px;box-shadow:0 8px 24px rgba(0,0,0,.06)}h1{margin:0 0 8px;font-size:24px}.muted{color:#64748b}.btn{display:inline-block;margin-top:18px;background:#1688f6;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px}</style></head><body><div class="box"><h1>Permission denied</h1><div class="muted">'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</div><a class="btn" href="'.htmlspecialchars(url('dashboard'),ENT_QUOTES,'UTF-8').'">Back to Dashboard</a></div></body></html>';
    exit;
}

function sense_v203_enforce_permissions(): void {
    $u = user();
    if (!$u) return;
    if (($u['role'] ?? '') === 'super_admin') return;

    $route = sense_v203_route_path();
    if ($route === '' || in_array($route, ['login','register','logout','platform-login','platform-logout','platform-control','platform-audit','accept-invite'], true)) return;

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $query = $_GET;

    // Team management is already restricted to Super Admin by the route itself.
    if ($route === 'team') return;

    // Explicit print/export actions.
    if (!empty($query['print']) && !sense_has_permission_v203('print')) sense_v203_deny('print', 'You do not have permission to print documents.');
    if (isset($query['export']) && !sense_has_permission_v203('export')) sense_v203_deny('export', 'You do not have permission to export data.');

    if ($method === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $teamAction = (string)($_POST['team_action'] ?? '');

        // Admin-only team actions are handled by require_super_admin().
        if ($teamAction !== '') return;

        // Destructive actions always require Delete.
        $deleteActions = ['delete','delete_item','delete_category','delete_unit','delete_document','delete_product_request','delete_cash','delete_bank_account','delete_party','delete_stock','delete_transaction','recycle_delete'];
        if (in_array($action, $deleteActions, true) || str_starts_with($action, 'delete_')) {
            if (!sense_has_permission_v203('delete')) sense_v203_deny('delete');
            return;
        }

        // Approval/workflow conversions require Approve.
        if (str_contains($action, 'approve') || str_contains($action, 'convert') || $route === 'delivery-challans' && isset($_POST['convert'])) {
            if (!sense_has_permission_v203('approve')) sense_v203_deny('approve');
            return;
        }

        // Duplicate creates a new record.
        if (in_array($action, ['duplicate','duplicate_cash','duplicate_document','duplicate_item','duplicate_party'], true) || str_starts_with($action, 'duplicate_')) {
            if (!sense_has_permission_v203('add')) sense_v203_deny('add');
            return;
        }

        // Known edit/update actions.
        if (str_starts_with($action, 'update_') || str_starts_with($action, 'edit_')) {
            if (!sense_has_permission_v203('edit')) sense_v203_deny('edit');
            return;
        }

        if ($route === 'transaction-save') {
            $isEdit = (int)($_POST['transaction_id'] ?? 0) > 0;
            if (!sense_has_permission_v203($isEdit ? 'edit' : 'add')) sense_v203_deny($isEdit ? 'edit' : 'add');
            return;
        }

        // Inline creation from transaction screens.
        if (in_array($route, ['inline-party-create','inline-product-create'], true)) {
            if (!sense_has_permission_v203('add')) sense_v203_deny('add');
            return;
        }

        // Save/create actions. If an existing record id is present, treat it as Edit.
        $saveActions = [
            'save_item','save_category','save_unit','save_product_request','save_document',
            'save_party','save_payment','save_expense','save_bank_account','save_loan',
            'save_branch','save_warehouse','adjust_cash','bank_to_bank_transfer','company',
            'features','numbering','tax','currency','branch','warehouse','print','generate_barcode',
        ];
        if (in_array($action, $saveActions, true) || ($action !== '' && (str_starts_with($action, 'save_') || str_starts_with($action, 'create_')))) {
            $hasExistingId = false;
            foreach (['id','item_id','party_id','transaction_id','entry_id','product_request_id','edit_id'] as $key) {
                if ((int)($_POST[$key] ?? 0) > 0) { $hasExistingId = true; break; }
            }
            if (!sense_has_permission_v203($hasExistingId ? 'edit' : 'add')) sense_v203_deny($hasExistingId ? 'edit' : 'add');
            return;
        }

        // Most remaining authenticated POSTs are data-creation forms (payments,
        // expense entries, imports, etc.). Block them for View-only users.
        $readOnlyPostRoutes = ['party-search-api','item-search-api','serial-search-api'];
        if (!in_array($route, $readOnlyPostRoutes, true)) {
            if (!sense_has_permission_v203('add')) sense_v203_deny('add');
        }
        return;
    }

    // GET route intent.
    if (in_array($route, ['sale-new','purchase-new','product-request-new','expense-new'], true)) {
        if (isset($query['edit'])) {
            if (!sense_has_permission_v203('edit')) sense_v203_deny('edit');
        } elseif (isset($query['view'])) {
            if (!sense_has_permission_v203('view')) sense_v203_deny('view');
        } else {
            if (!sense_has_permission_v203('add')) sense_v203_deny('add');
        }
        return;
    }

    $editViewRoutes = ['parties','items','sales','purchase','delivery-challan-new'];
    if (in_array($route, $editViewRoutes, true)) {
        if (isset($query['edit'])) {
            if (!sense_has_permission_v203('edit')) sense_v203_deny('edit');
        } elseif (isset($query['duplicate'])) {
            if (!sense_has_permission_v203('add')) sense_v203_deny('add');
        } else {
            if (!sense_has_permission_v203('view')) sense_v203_deny('view');
        }
        return;
    }

    // Document list/detail pages: view by default, edit/duplicate on query parameters.
    $viewRoutes = [
        'dashboard','transactions','payment-in','payment-out','reports','return-items','sale-return',
        'purchase-return','delivery-challans','product-requests','quotations','sale-order','purchase-order',
        'expense','cash','cheques','loans','bank-accounts','party-ledger','item-ledger','company-network',
        'notifications','messages','support','recycle-bin','audit-log','barcode','bulk-update','export-items',
        'import-items','import-parties','backup','financial-year','settings'
    ];
    if (in_array($route, $viewRoutes, true)) {
        if (isset($query['edit'])) {
            if (!sense_has_permission_v203('edit')) sense_v203_deny('edit');
        } elseif (isset($query['duplicate'])) {
            if (!sense_has_permission_v203('add')) sense_v203_deny('add');
        } elseif ($route === 'delivery-challans' && isset($query['convert'])) {
            if (!sense_has_permission_v203('approve')) sense_v203_deny('approve');
        } elseif (!sense_has_permission_v203('view')) {
            sense_v203_deny('view');
        }
    }
}

sense_v203_enforce_permissions();

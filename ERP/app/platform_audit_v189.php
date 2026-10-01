<?php
$admin=require_platform_admin();
$pdo=db();
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_admin_audit_logs (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        platform_admin_id BIGINT UNSIGNED NULL,
        action VARCHAR(100) NOT NULL,
        entity_type VARCHAR(100) NULL,
        entity_id BIGINT NULL,
        company_id BIGINT NULL,
        details LONGTEXT NULL,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_paal_created (created_at),
        INDEX idx_paal_action (action),
        INDEX idx_paal_company (company_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Throwable $e) {}

$q=trim((string)($_GET['q']??''));
$action=trim((string)($_GET['action']??''));
$params=[];
$where=[];
if($q!==''){
    $like='%'.$q.'%';
    $where[]='(c.name LIKE ? OR pa.action LIKE ? OR pa.entity_type LIKE ? OR pa.details LIKE ? OR pa.ip_address LIKE ?)';
    array_push($params,$like,$like,$like,$like,$like);
}
if($action!==''){
    $where[]='pa.action=?';$params[]=$action;
}
$sql="SELECT pa.*,p.username AS admin_username,p.name AS admin_name,c.name AS company_name
       FROM platform_admin_audit_logs pa
       LEFT JOIN platform_admins p ON p.id=pa.platform_admin_id
       LEFT JOIN companies c ON c.id=pa.company_id";
if($where)$sql.=' WHERE '.implode(' AND ',$where);
$sql.=' ORDER BY pa.id DESC LIMIT 500';
$rows=[];
try{$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();}catch(Throwable $e){$rows=[];}
$actions=[];
try{$actions=$pdo->query('SELECT DISTINCT action FROM platform_admin_audit_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);}catch(Throwable $e){}
function pae($v){return e((string)$v);}
function padetails($v){
    if(!$v)return '';
    $j=json_decode((string)$v,true);
    return is_array($j)?json_encode($j,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):((string)$v);
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin Activity · sense</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>?v=189"><style>
body{background:#f6f8fb}.audit-shell{display:grid;grid-template-columns:260px 1fr;min-height:100vh}.audit-sidebar{background:#0f172a;color:#fff;padding:20px 14px}.audit-brand{display:flex;gap:10px;align-items:center;margin:4px 6px 24px}.audit-brand b{display:block}.audit-brand small{opacity:.7}.audit-nav{display:block;color:#dbeafe;text-decoration:none;padding:10px 12px;border-radius:10px;margin:4px 0}.audit-nav:hover,.audit-nav.active{background:#1e293b;color:#fff}.audit-main{padding:28px;min-width:0}.audit-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:18px}.audit-head h1{margin:0}.audit-head p{margin:6px 0 0;color:#64748b}.audit-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 3px 16px rgba(15,23,42,.05);padding:16px}.audit-filters{display:grid;grid-template-columns:1.5fr 1fr auto;gap:10px;align-items:end}.audit-table{width:100%;border-collapse:collapse}.audit-table th,.audit-table td{padding:10px 8px;border-bottom:1px solid #eef2f7;text-align:left;vertical-align:top;font-size:13px}.audit-table th{font-size:11px;color:#64748b}.audit-action{font-weight:700}.audit-meta{color:#64748b;font-size:12px}.audit-details{max-width:420px;white-space:pre-wrap;word-break:break-word;color:#334155;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:11px}.pill{display:inline-block;padding:3px 8px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:11px}.muted{color:#94a3b8}@media(max-width:900px){.audit-shell{grid-template-columns:1fr}.audit-sidebar{display:none}.audit-main{padding:16px}.audit-filters{grid-template-columns:1fr}.audit-table{min-width:980px}.audit-card{overflow:auto}}
</style></head><body><div class="audit-shell"><aside class="audit-sidebar"><div class="audit-brand"><div class="brandmark">S</div><div><b>sense</b><small>Platform Admin</small></div></div><a class="audit-nav" href="<?=e(url('platform-control'))?>">⌂ Platform Control</a><a class="audit-nav active" href="<?=e(url('platform-audit'))?>">◷ Admin Activity</a><a class="audit-nav" href="<?=e(url('dashboard'))?>">← Company ERP</a><a class="audit-nav" href="<?=e(url('platform-logout'))?>">Logout</a></aside><main class="audit-main"><div class="audit-head"><div><h1>Admin Activity</h1><p>Platform-level actions performed by Platform Admin accounts.</p></div><div class="audit-meta"><?=count($rows)?> records shown</div></div><div class="audit-card" style="margin-bottom:16px"><form method="get" class="audit-filters"><div class="form-group"><label>Search</label><input name="q" value="<?=pae($q)?>" placeholder="Company, action, entity, IP or details"></div><div class="form-group"><label>Action</label><select name="action"><option value="">All actions</option><?php foreach($actions as $a):?><option value="<?=pae($a)?>" <?=$action===$a?'selected':''?>><?=pae(ucwords(str_replace('_',' ',$a)))?></option><?php endforeach;?></select></div><button class="btn primary">Filter</button></form></div><div class="audit-card"><div class="table-wrap" style="overflow:auto"><table class="audit-table"><thead><tr><th>DATE / TIME</th><th>ADMIN</th><th>ACTION</th><th>ENTITY</th><th>COMPANY</th><th>DETAILS</th><th>IP</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=pae($r['created_at'])?></td><td><b><?=pae($r['admin_name']?:$r['admin_username']?:'Platform Admin')?></b><div class="audit-meta"><?=pae($r['admin_username']??'')?></div></td><td><span class="audit-action"><?=pae(ucwords(str_replace('_',' ',$r['action'])))?></span></td><td><?=pae($r['entity_type']??'—')?><?=($r['entity_id']??null)!==null?'<div class="audit-meta">#'.pae($r['entity_id']).'</div>':''?></td><td><?=pae($r['company_name']??($r['company_id']?'Company #'.$r['company_id']:'—'))?></td><td><div class="audit-details"><?=pae(padetails($r['details']))?></div></td><td><?=pae($r['ip_address']??'—')?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="7" class="muted">No admin activity recorded yet. New Platform Control actions will appear here.</td></tr><?php endif;?></tbody></table></div></div></main></div></body></html>

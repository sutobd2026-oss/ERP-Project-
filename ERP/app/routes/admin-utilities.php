<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='accept-invite'){
    if(user())redirect('dashboard'); $token=trim($_GET['token']??'');
    $st=db()->prepare('SELECT i.*,c.name company_name FROM user_invites i JOIN companies c ON c.id=i.company_id WHERE i.token=? AND i.accepted_at IS NULL AND i.expires_at>NOW() LIMIT 1');$st->execute([$token]);$inv=$st->fetch();
    if(!$inv){http_response_code(404);exit('Invitation is invalid or expired.');}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf(); $name=trim($_POST['name']??$inv['name']??'');$pass=$_POST['password']??'';$pass2=$_POST['password_confirmation']??'';
        if(!$name || strlen($pass)<8 || $pass!==$pass2){flash('error','Enter your name and matching password (8+ characters).');redirect('accept-invite?token='.urlencode($token));}
        $pdo=db(); try{$pdo->beginTransaction();$role=$inv['role'];$valid=['admin','manager','accountant','sales','purchase','viewer','custom']; if(!in_array($role,$valid,true))$role='viewer'; $pdo->prepare('INSERT INTO users(company_id,name,email,password_hash,role,status) VALUES(?,?,?, ?,?,"active")')->execute([$inv['company_id'],$name,$inv['email'],password_hash($pass,PASSWORD_DEFAULT),$role]);$uid=(int)$pdo->lastInsertId(); $roleNameMap=['admin'=>'Admin','manager'=>'Manager','accountant'=>'Accountant','sales'=>'Sales','purchase'=>'Purchase','viewer'=>'Viewer','custom'=>'Custom','super_admin'=>'Super Admin']; $roleName=$roleNameMap[$role]??'Viewer'; $r=$pdo->prepare('SELECT id FROM roles WHERE company_id=? AND name=? LIMIT 1');$r->execute([$inv['company_id'],$roleName]); if($rid=$r->fetchColumn())$pdo->prepare('UPDATE users SET role_id=? WHERE id=?')->execute([$rid,$uid]); $pdo->prepare('UPDATE user_invites SET accepted_at=NOW() WHERE id=?')->execute([$inv['id']]); audit('accept','user_invite',(int)$inv['id'],['user_id'=>$uid]); $pdo->commit(); session_regenerate_id(true); $_SESSION['uid']=$uid; redirect('dashboard');}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error','Could not create the invited user account.');redirect('accept-invite?token='.urlencode($token));}
    }
    ?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Accept Invitation · sense</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>"></head><body class="auth"><div class="auth-card"><div class="auth-brand"><span class="brandmark">SA</span><span>sense</span></div><h1>Join <?=e($inv['company_name'])?></h1><p>Complete your account setup.</p><?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach;?><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="form-group"><label>Name</label><input name="name" value="<?=e($inv['name']??'')?>" required></div><div class="form-group"><label>Email</label><input value="<?=e($inv['email'])?>" disabled></div><div class="form-group"><label>Password</label><input type="password" name="password" minlength="8" required></div><div class="form-group"><label>Confirm Password</label><input type="password" name="password_confirmation" minlength="8" required></div><button class="btn primary" style="width:100%;justify-content:center">Accept Invitation</button></form></div></body></html><?php exit;
}


if($route==='backup'){
    $u=require_super_admin(); $cid=(int)$u['company_id'];
    $backupDir=__DIR__.'/../../storage/backups'; if(!is_dir($backupDir))@mkdir($backupDir,0750,true);
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf(); $action=$_POST['backup_action']??'';
        if($action==='create'){
            try{
                $pdo=db(); $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
                $sql="-- sense backup\n-- Company: ".(int)$cid."\n-- Created: ".date('Y-m-d H:i:s')."\nSET FOREIGN_KEY_CHECKS=0;\nSTART TRANSACTION;\n";
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
        if(!$name){ flash('error','Item name is required.'); redirect('bulk-update'); }
        if($id){
            $chk=$pdo->prepare('SELECT name FROM items WHERE id=? AND company_id=?');
            $chk->execute([$id,$cid]);
            $before=$chk->fetch();
            if(!$before){ flash('error','Item not found.'); redirect('bulk-update'); }
            $pdo->prepare('UPDATE items SET name=?,sale_price=?,purchase_price=?,low_stock_limit=? WHERE id=? AND company_id=?')->execute([$name,$sale,$purchase,$low,$id,$cid]);
            audit('bulk_update','item',$id,['name_from'=>(string)$before['name'],'name_to'=>$name,'sale_price'=>$sale,'purchase_price'=>$purchase,'low_stock_limit'=>$low]);
            flash('success','Item updated.');
        }
        redirect('bulk-update');
    }
    $st=$pdo->prepare('SELECT id,name,item_type,sale_price,purchase_price,low_stock_limit FROM items WHERE company_id=? AND active=1 ORDER BY name');$st->execute([$cid]);$rows=$st->fetchAll();
    page_start('Bulk Update Item'); ?><div class="page-title"><div><h1>Bulk Update Item</h1><p>Quickly update item name, prices and stock alerts.</p></div></div><div class="panel table-wrap"><table><thead><tr><th>ITEM</th><th>TYPE</th><th>SALE PRICE</th><th>PURCHASE PRICE</th><th>LOW STOCK LIMIT</th><th></th></tr></thead><tbody><?php foreach($rows as $r):$fid='bulk-item-'.$r['id'];?><tr><td><form id="<?=$fid?>" method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="id" value="<?=$r['id']?>"></form><input form="<?=$fid?>" name="name" type="text" value="<?=e($r['name'])?>" required style="min-width:210px;width:100%;"></td><td><?=e($r['item_type'])?></td><td><input form="<?=$fid?>" name="sale_price" type="number" step="0.01" value="<?=e($r['sale_price'])?>"></td><td><input form="<?=$fid?>" name="purchase_price" type="number" step="0.01" value="<?=e($r['purchase_price'])?>"></td><td><input form="<?=$fid?>" name="low_stock_limit" type="number" step="0.01" value="<?=e($r['low_stock_limit'])?>"></td><td><button form="<?=$fid?>" type="submit" class="btn small-btn primary">Save</button></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6" class="subtle">No items.</td></tr><?php endif;?></tbody></table></div><?php page_end();exit;
}

if($route==='settings'){
$u=require_super_admin();$cid=(int)$u['company_id'];$pdo=db();
$tab=$_GET['tab']??'company'; if(!in_array($tab,['company','features','numbering','tax','currency','branches','warehouses','print'],true))$tab='company';
if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf(); $action=$_POST['action']??'';
    try{
        if($action==='company'){
            $name=trim($_POST['name']??'');$email=trim($_POST['email']??'');$phone=trim($_POST['phone']??'');$address=trim($_POST['address']??'');$biz=trim($_POST['business_type']??'');$currency=$_POST['currency_code']??'BDT';$fy=$_POST['financial_year_mode']??'july_june';
            if($name==='') throw new RuntimeException('Company name is required.');
            if(!in_array($fy,['july_june','jan_dec'],true))$fy='july_june';
            if(!preg_match('/^[A-Z]{3}$/',$currency))$currency='BDT';
            $pdo->prepare('UPDATE companies SET name=?,email=?,phone=?,address=?,business_type=?,currency_code=?,financial_year_mode=? WHERE id=?')->execute([$name,$email,$phone,$address,$biz,$currency,$fy,$cid]);
            flash('success','Company profile updated.');
        } elseif($action==='features'){
            $keys=['enable_branches','enable_warehouses','enable_multicurrency','enable_tax_vat','negative_stock','sale_discount_mode','purchase_discount_mode'];
            foreach($keys as $k){save_setting($cid,$k,(string)($_POST[$k]??''));}
            flash('success','System preferences updated.');
        } elseif($action==='numbering'){
            foreach(['sale_prefix','purchase_prefix','payment_in_prefix','payment_out_prefix','quotation_prefix','sale_order_prefix','delivery_challan_prefix','sale_return_prefix','purchase_return_prefix','expense_prefix'] as $k){$v=trim($_POST[$k]??'');if($v!==''&&!preg_match('/^[A-Za-z0-9_-]{1,12}$/',$v))throw new RuntimeException('Invalid prefix for '.$k.'.');save_setting($cid,$k,$v);}
            flash('success','Document numbering settings updated.');
        } elseif($action==='print'){
            $paper=$_POST['print_paper_size']??'A4';
            $companySize=$_POST['print_company_name_size']??'medium';
            $invoiceSize=$_POST['print_invoice_text_size']??'medium';
            if(!in_array($paper,['A4','A5'],true))$paper='A4';
            if(!in_array($companySize,['small','medium','large'],true))$companySize='medium';
            if(!in_array($invoiceSize,['small','medium','large'],true))$invoiceSize='medium';
            save_setting($cid,'print_paper_size',$paper);
            save_setting($cid,'print_company_name_size',$companySize);
            save_setting($cid,'print_invoice_text_size',$invoiceSize);
            flash('success','Print settings updated.');
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
$defaults=['sale_prefix'=>'SI','purchase_prefix'=>'PB','payment_in_prefix'=>'PI','payment_out_prefix'=>'PO','quotation_prefix'=>'QT','sale_order_prefix'=>'SO','delivery_challan_prefix'=>'DC','sale_return_prefix'=>'SR','purchase_return_prefix'=>'PR','expense_prefix'=>'EX','enable_branches'=>'0','enable_warehouses'=>'0','enable_multicurrency'=>'0','enable_tax_vat'=>'0','negative_stock'=>'1','sale_discount_mode'=>'both','purchase_discount_mode'=>'both','print_paper_size'=>'A4','print_company_name_size'=>'medium','print_invoice_text_size'=>'medium'];
foreach($defaults as $k=>$v){$defaults[$k]=setting($k,$v,$cid);}
page_start('Settings'); ?>
<div class="settings-shell"><div class="page-title"><div><h1>Settings</h1><p>Company profile and system configuration</p></div></div>
<div class="settings-tabs">
<a class="<?= $tab==='company'?'active':'' ?>" href="<?=e(url('settings?tab=company'))?>">Company</a><a class="<?= $tab==='features'?'active':'' ?>" href="<?=e(url('settings?tab=features'))?>">Features</a><a class="<?= $tab==='numbering'?'active':'' ?>" href="<?=e(url('settings?tab=numbering'))?>">Invoice Numbering</a><a class="<?= $tab==='tax'?'active':'' ?>" href="<?=e(url('settings?tab=tax'))?>">Tax / VAT</a><a class="<?= $tab==='currency'?'active':'' ?>" href="<?=e(url('settings?tab=currency'))?>">Currencies</a><a class="<?= $tab==='branches'?'active':'' ?>" href="<?=e(url('settings?tab=branches'))?>">Branches</a><a class="<?= $tab==='warehouses'?'active':'' ?>" href="<?=e(url('settings?tab=warehouses'))?>">Warehouses</a><a class="<?= $tab==='print'?'active':'' ?>" href="<?=e(url('settings?tab=print'))?>">Print</a><a href="<?=e(url('team'))?>">Users & Roles</a></div>
<?php if($tab==='company'): ?>
<form method="post" class="panel settings-card"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="company"><div class="settings-card-head"><div><h2>Company Profile</h2><p class="subtle">Default information used across invoices and reports.</p></div><button class="btn primary">Save Changes</button></div><div class="settings-form-grid"><div class="form-group"><label>Company Name*</label><input name="name" value="<?=e($c['name']??'')?>" required></div><div class="form-group"><label>Company Email</label><input type="email" name="email" value="<?=e($c['email']??'')?>"></div><div class="form-group"><label>Phone</label><input name="phone" value="<?=e($c['phone']??'')?>"></div><div class="form-group"><label>Business Type</label><input name="business_type" value="<?=e($c['business_type']??'')?>"></div><div class="form-group"><label>Default Currency</label><select name="currency_code"><option value="BDT" <?=($c['currency_code']==='BDT'?'selected':'')?>>BDT — ৳</option><option value="USD" <?=($c['currency_code']==='USD'?'selected':'')?>>USD — $</option><option value="EUR" <?=($c['currency_code']==='EUR'?'selected':'')?>>EUR — €</option><option value="GBP" <?=($c['currency_code']==='GBP'?'selected':'')?>>GBP — £</option></select></div><div class="form-group"><label>Financial Year</label><select name="financial_year_mode"><option value="july_june" <?=($c['financial_year_mode']==='july_june'?'selected':'')?>>1 July – 30 June</option><option value="jan_dec" <?=($c['financial_year_mode']==='jan_dec'?'selected':'')?>>1 January – 31 December</option></select></div><div class="form-group full"><label>Address</label><textarea name="address" rows="3"><?=e($c['address']??'')?></textarea></div></div></form>
<div class="grid3 settings-info"><div class="panel"><h3>Admin Account</h3><p><b><?=e($u['name'])?></b></p><p><?=e($u['email'])?></p><span class="status paid">Super Admin</span></div><div class="panel"><h3>Security</h3><p>CSRF protection enabled</p><p>Password hashing enabled</p><p>Company data isolation enabled</p></div><div class="panel"><h3>Team</h3><p>Manage invited users and their access.</p><a class="btn" href="<?=e(url('team'))?>">Manage Users & Roles</a></div></div>
<?php elseif($tab==='features'): ?>
<form method="post" class="panel settings-card"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="features"><div class="settings-card-head"><div><h2>Business Features</h2><p class="subtle">Optional modules can be enabled when your business needs them.</p></div><button class="btn primary">Save Preferences</button></div><div class="feature-grid"><?php foreach([['enable_branches','Multiple Branches','Enable branch-wise transactions and stock.'],['enable_warehouses','Multiple Warehouses','Enable warehouse-wise stock tracking.'],['enable_multicurrency','Multi Currency','Allow currencies other than the default company currency.'],['enable_tax_vat','Tax / VAT','Show optional tax and VAT controls on invoices.'],['negative_stock','Negative Stock','Allow sales to continue when stock goes below zero.'] ] as $f):?><label class="feature-toggle"><input type="checkbox" name="<?=$f[0]?>" value="1" <?= $defaults[$f[0]]==='1'?'checked':'' ?>><span><b><?=e($f[1])?></b><small><?=e($f[2])?></small></span></label><?php endforeach;?><div class="form-group"><label>Sale Discount</label><select name="sale_discount_mode"><option value="both" <?=($defaults['sale_discount_mode']==='both'?'selected':'')?>>Item + Invoice level</option><option value="item" <?=($defaults['sale_discount_mode']==='item'?'selected':'')?>>Item level only</option><option value="invoice" <?=($defaults['sale_discount_mode']==='invoice'?'selected':'')?>>Invoice level only</option></select></div><div class="form-group"><label>Purchase Discount</label><select name="purchase_discount_mode"><option value="both" <?=($defaults['purchase_discount_mode']==='both'?'selected':'')?>>Item + Invoice level</option><option value="item" <?=($defaults['purchase_discount_mode']==='item'?'selected':'')?>>Item level only</option><option value="invoice" <?=($defaults['purchase_discount_mode']==='invoice'?'selected':'')?>>Invoice level only</option></select></div></div></form>
<?php elseif($tab==='numbering'): ?>
<form method="post" class="panel settings-card"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="numbering"><div class="settings-card-head"><div><h2>Document Numbering</h2><p class="subtle">Prefixes are used with a minimum two-digit sequence.</p></div><button class="btn primary">Save Numbering</button></div><div class="settings-form-grid numbering-grid"><?php foreach([['sale_prefix','Sales Invoice'],['purchase_prefix','Purchase Bill'],['payment_in_prefix','Payment In'],['payment_out_prefix','Payment Out'],['quotation_prefix','Quotation'],['sale_order_prefix','Sale Order'],['delivery_challan_prefix','Delivery Challan'],['sale_return_prefix','Sale Return / Credit Note'],['purchase_return_prefix','Purchase Return / Debit Note'],['expense_prefix','Expense']] as $f):?><div class="form-group"><label><?=e($f[1])?></label><div class="prefix-input"><input name="<?=$f[0]?>" value="<?=e($defaults[$f[0]])?>"><span>-01</span></div></div><?php endforeach;?></div></form>
<?php elseif($tab==='print'): ?>
<form method="post" class="panel settings-card">
<input type="hidden" name="_csrf" value="<?=csrf_token()?>">
<input type="hidden" name="action" value="print">
<div class="settings-card-head"><div><h2>Print</h2><p class="subtle">Choose the paper size and text sizes used when printing invoices.</p></div><button class="btn primary">Save Print Settings</button></div>
<div class="settings-form-grid">
  <div class="form-group"><label>Default Paper Size</label><select name="print_paper_size"><option value="A4" <?=($defaults['print_paper_size']==='A4'?'selected':'')?>>A4</option><option value="A5" <?=($defaults['print_paper_size']==='A5'?'selected':'')?>>A5</option></select></div>
  <div class="form-group"><label>Company Name Text Size</label><select name="print_company_name_size"><option value="small" <?=($defaults['print_company_name_size']==='small'?'selected':'')?>>Small</option><option value="medium" <?=($defaults['print_company_name_size']==='medium'?'selected':'')?>>Medium</option><option value="large" <?=($defaults['print_company_name_size']==='large'?'selected':'')?>>Large</option></select></div>
  <div class="form-group"><label>Invoice Text Size</label><select name="print_invoice_text_size"><option value="small" <?=($defaults['print_invoice_text_size']==='small'?'selected':'')?>>Small</option><option value="medium" <?=($defaults['print_invoice_text_size']==='medium'?'selected':'')?>>Medium</option><option value="large" <?=($defaults['print_invoice_text_size']==='large'?'selected':'')?>>Large</option></select></div>
</div>
</form>
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
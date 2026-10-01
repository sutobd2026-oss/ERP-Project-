<?php
$u=require_platform_admin();
$pdo=db();

function pc_company_record_counts(): array {
    $pdo=db(); $counts=[];
    try {
        $tables=$pdo->query("SELECT DISTINCT c.TABLE_NAME FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.COLUMN_NAME='company_id' AND c.TABLE_NAME NOT LIKE 'platform\\_%'")->fetchAll(PDO::FETCH_COLUMN,0);
        foreach($tables as $t){
            $safe='`'.str_replace('`','``',$t).'`';
            try { $st=$pdo->query("SELECT company_id,COUNT(*) cnt FROM $safe GROUP BY company_id"); foreach($st as $r){$cid=(int)$r['company_id'];$counts[$cid]=($counts[$cid]??0)+(int)$r['cnt'];} } catch(Throwable $e) {}
        }
    } catch(Throwable $e) {}
    return $counts;
}
function pc_table_counts_for_company(int $cid): array {
    $pdo=db(); $out=[];
    try {
        $tables=$pdo->query("SELECT DISTINCT c.TABLE_NAME FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA=DATABASE() AND c.COLUMN_NAME='company_id' AND c.TABLE_NAME NOT LIKE 'platform\\_%' ORDER BY c.TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        foreach($tables as $t){$safe='`'.str_replace('`','``',$t).'`';try{$st=$pdo->prepare("SELECT COUNT(*) FROM $safe WHERE company_id=?");$st->execute([$cid]);$n=(int)$st->fetchColumn();if($n>0)$out[$t]=$n;}catch(Throwable $e){}}
    } catch(Throwable $e) {}
    return $out;
}
function pc_bytes(): float { try{$v=db()->query('SELECT COALESCE(SUM(data_length+index_length),0) FROM information_schema.TABLES WHERE table_schema=DATABASE()')->fetchColumn();return(float)$v;}catch(Throwable $e){return 0.0;} }
function pc_usage_for_company(int $cid): array {
    $pdo=db();
    $tables=pc_table_counts_for_company($cid);
    $total=array_sum($tables);
    $tx=0;$parties=0;$items=0;$users=0;
    try{$st=$pdo->prepare('SELECT COUNT(*) FROM transactions WHERE company_id=? AND deleted_at IS NULL');$st->execute([$cid]);$tx=(int)$st->fetchColumn();}catch(Throwable $e){}
    try{$st=$pdo->prepare('SELECT COUNT(*) FROM parties WHERE company_id=? AND deleted_at IS NULL');$st->execute([$cid]);$parties=(int)$st->fetchColumn();}catch(Throwable $e){}
    try{$st=$pdo->prepare('SELECT COUNT(*) FROM items WHERE company_id=? AND active=1');$st->execute([$cid]);$items=(int)$st->fetchColumn();}catch(Throwable $e){}
    try{$st=$pdo->prepare("SELECT COUNT(*) FROM users WHERE company_id=? AND status='active'");$st->execute([$cid]);$users=(int)$st->fetchColumn();}catch(Throwable $e){}
    $db=pc_bytes();
    $allCounts=pc_company_record_counts(); $all=array_sum($allCounts);
    $share=$all>0?($total/$all):0;
    return ['records'=>$total,'transactions'=>$tx,'parties'=>$parties,'items'=>$items,'users'=>$users,'shared_db_bytes'=>$db,'record_share'=>$share,'tables'=>$tables];
}
function pc_send_company_notice(int $companyId,string $title,string $body,string $type='warning'): void {
    $pdo=db();
    $allowed=['notice','ad','info','warning']; if(!in_array($type,$allowed,true))$type='warning';
    $pdo->prepare('INSERT INTO platform_announcements(title,body,type,target_type,target_company_id,priority,is_active,starts_at,created_by) VALUES(?,?,?,?,?,?,1,NOW(),?)')->execute([$title,$body,$type,'company',$companyId,5,(int)(platform_admin()['id']??0)]);
    $st=$pdo->prepare("SELECT id FROM users WHERE company_id=? AND status='active'");$st->execute([$companyId]);
    $ins=$pdo->prepare('INSERT INTO notifications(company_id,user_id,type,title,body,link) VALUES(?,?,?,?,?,?)');
    foreach($st as $r){try{$ins->execute([$companyId,(int)$r['id'],'platform',$title,$body,url('company-network')]);}catch(Throwable $e){try{$ins->execute([$companyId,(int)$r['id'],'platform',$title,$body,url('dashboard')]);}catch(Throwable $e2){}}}
}
function pc_cycle_end(string $start,string $cycle): string {
    $tz=new DateTimeZone('Asia/Dhaka');
    $d=new DateTimeImmutable($start,$tz);
    if($cycle==='yearly') $d=$d->modify('+1 year'); else $d=$d->modify('+1 month');
    return $d->format('Y-m-d H:i:s');
}

function pc_audit_safe(string $action,string $entity,?int $entityId=null,?int $companyId=null,?array $details=null): void {
    try {
        $pdo=db();
        $pdo->exec("CREATE TABLE IF NOT EXISTS platform_admin_audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, platform_admin_id BIGINT UNSIGNED NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(100) NULL, entity_id BIGINT NULL, company_id BIGINT NULL, details LONGTEXT NULL, ip_address VARCHAR(45) NULL, user_agent VARCHAR(500) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_paal_created (created_at), INDEX idx_paal_action (action), INDEX idx_paal_company (company_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $a=platform_admin();
        $st=$pdo->prepare('INSERT INTO platform_admin_audit_logs(platform_admin_id,action,entity_type,entity_id,company_id,details,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?)');
        $st->execute([$a['id']??null,$action,$entity,$entityId,$companyId,$details?json_encode($details,JSON_UNESCAPED_UNICODE):null,$_SERVER['REMOTE_ADDR']??null,substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);
    } catch(Throwable $e) {}
}
function pc_company_label(array $c): string {
    $name=(string)($c['name']??'Company');$plan=(string)($c['plan_name']??'Free');$cycle=(string)($c['billing_cycle']??'');
    return $name.' · '.$plan.($cycle?' · '.ucfirst($cycle):'');
}

$tab=(string)($_GET['tab']??'overview');
$companyId=(int)($_GET['id']??0);
$q=trim((string)($_GET['q']??''));

if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf();
    $action=(string)($_POST['platform_action']??'');
    try {
        if($action==='create_notice'){
            $title=trim((string)($_POST['title']??''));$body=trim((string)($_POST['body']??''));$type=(string)($_POST['type']??'notice');$target=(string)($_POST['target_type']??'all');$targetCid=(int)($_POST['target_company_id']??0);$priority=(int)($_POST['priority']??0);$starts=trim((string)($_POST['starts_at']??date('Y-m-d H:i')));$ends=trim((string)($_POST['ends_at']??''));
            $allowed=['notice','ad','info','warning'];if(!in_array($type,$allowed,true))$type='notice';$target=$target==='company'&&$targetCid>0?'company':'all';
            if($title===''||$body==='')throw new RuntimeException('Title and message are required.');
            $s=date('Y-m-d H:i:s',strtotime($starts));$e=$ends!==''?date('Y-m-d H:i:s',strtotime($ends)):null;
            $pdo->prepare('INSERT INTO platform_announcements(title,body,type,target_type,target_company_id,priority,is_active,starts_at,ends_at,created_by) VALUES(?,?,?,?,?,?,1,?,?,?)')->execute([$title,$body,$type,$target,$target==='company'?$targetCid:null,$priority,$s,$e,(int)$u['id']]);
            if($target==='company') { /* targeted platform announcement above also becomes the company notice */ $stn=$pdo->prepare("SELECT id FROM users WHERE company_id=? AND status='active'");$stn->execute([$targetCid]);$insn=$pdo->prepare('INSERT INTO notifications(company_id,user_id,type,title,body,link) VALUES(?,?,?,?,?,?)');foreach($stn as $nr){try{$insn->execute([$targetCid,(int)$nr['id'],'platform',$title,$body,url('company-network')]);}catch(Throwable $ignore){}} }
            pc_audit_safe('create_notice','platform_announcement',(int)$pdo->lastInsertId(),$target==='company'?$targetCid:null,['title'=>$title,'type'=>$type,'target_type'=>$target]); flash('success','Notice published.'); redirect('platform-control?tab=notices');
        }
        if($action==='toggle_notice'){$id=(int)($_POST['id']??0);$pdo->prepare('UPDATE platform_announcements SET is_active=IF(is_active=1,0,1) WHERE id=?')->execute([$id]);pc_audit_safe('toggle_notice','platform_announcement',$id,null);flash('success','Notice status updated.');redirect('platform-control?tab=notices');}
        if($action==='delete_notice'){$id=(int)($_POST['id']??0);$pdo->prepare('DELETE FROM platform_announcements WHERE id=?')->execute([$id]);$pdo->prepare('DELETE FROM platform_announcement_reads WHERE announcement_id=?')->execute([$id]);pc_audit_safe('delete_notice','platform_announcement',$id,null);flash('success','Notice deleted.');redirect('platform-control?tab=notices');}
        if($action==='send_upgrade_notice'){
            $cid=(int)($_POST['company_id']??0);$companySt=$pdo->prepare('SELECT name,plan_name FROM companies WHERE id=? LIMIT 1');$companySt->execute([$cid]);$company=$companySt->fetch();if(!$company)throw new RuntimeException('Company not found.');
            $title=trim((string)($_POST['notice_title']??'Usage review for '.$company['name']));$body=trim((string)($_POST['notice_body']??''));
            if($body==='')throw new RuntimeException('Notice message is required before converting this company to Paid.');
            pc_send_company_notice($cid,$title,$body,'warning');
            pc_audit_safe('send_upgrade_notice','company',$cid,$cid,['title'=>$title]); flash('success','Upgrade notice sent to '.$company['name'].'. You can now convert the company to Paid.'); redirect('platform-control?tab=company&id='.$cid);
        }
        if($action==='company_plan_update'){
            $cid=(int)($_POST['company_id']??0);$plan=(string)($_POST['plan_name']??'Free');$cycle=(string)($_POST['billing_cycle']??'monthly');$amount=(float)($_POST['subscription_amount']??0);$paymentStatus=(string)($_POST['payment_status']??'pending');$note=trim((string)($_POST['billing_note']??''));$notified=(int)($_POST['company_notified']??0);
            $st=$pdo->prepare('SELECT * FROM companies WHERE id=? LIMIT 1');$st->execute([$cid]);$company=$st->fetch();if(!$company)throw new RuntimeException('Company not found.');
            if(!in_array($plan,['Free','Paid'],true))$plan='Free';
            if($plan==='Paid'){
                if($notified!==1)throw new RuntimeException('Please notify the company before converting it to Paid.');
                if(!in_array($cycle,['monthly','yearly'],true))$cycle='monthly';
                if($amount<0)throw new RuntimeException('Subscription amount cannot be negative.');
                if(!in_array($paymentStatus,['pending','paid','partial','unpaid'],true))$paymentStatus='pending';
                $start=date('Y-m-d H:i:s');$end=pc_cycle_end($start,$cycle);
                $pdo->prepare("UPDATE companies SET plan_name='Paid',billing_cycle=?,subscription_amount=?,subscription_started_at=?,subscription_expires_at=?,plan_converted_at=NOW(),plan_converted_by=? WHERE id=?")->execute([$cycle,$amount,$start,$end,(int)$u['id'],$cid]);
                $pdo->prepare('INSERT INTO company_billing_records(company_id,plan_name,billing_cycle,amount,payment_status,starts_at,ends_at,converted_by,note) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$cid,'Paid',$cycle,$amount,$paymentStatus,$start,$end,(int)$u['id'],$note?:null]);
                pc_send_company_notice($cid,'Your sense plan is now Paid','Your company has been converted to the Paid plan. Billing cycle: '.ucfirst($cycle).'. Please contact support if you have any questions.','info');
                pc_audit_safe('convert_to_paid','company',$cid,$cid,['plan'=>'Paid','cycle'=>$cycle,'amount'=>$amount,'payment_status'=>$paymentStatus]); flash('success','Company converted to Paid.');
            } else {
                $pdo->prepare("UPDATE companies SET plan_name='Free',billing_cycle=NULL,subscription_amount=0,subscription_started_at=NULL,subscription_expires_at=NULL,plan_converted_at=NOW(),plan_converted_by=? WHERE id=?")->execute([(int)$u['id'],$cid]);
                $pdo->prepare('INSERT INTO company_billing_records(company_id,plan_name,billing_cycle,amount,payment_status,starts_at,ends_at,converted_by,note) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$cid,'Free','monthly',0,'paid',date('Y-m-d H:i:s'),null,(int)$u['id'],$note?:'Converted back to Free plan']);
                pc_send_company_notice($cid,'Your sense plan is Free again','Your company is now on the Free plan.','info');
                pc_audit_safe('convert_to_free','company',$cid,$cid,['plan'=>'Free']); flash('success','Company converted to Free.');
            }
            redirect('platform-control?tab=company&id='.$cid);
        }

        if($action==='subscription_suspend'){
            $id=(int)($_POST['subscription_id']??0);
            $st=$pdo->prepare('SELECT cs.*,s.name subscriber_name,t.name target_name FROM company_subscriptions cs JOIN companies s ON s.id=cs.subscriber_company_id JOIN companies t ON t.id=cs.target_company_id WHERE cs.id=? LIMIT 1');
            $st->execute([$id]);$row=$st->fetch();
            if(!$row) throw new RuntimeException('Subscription not found.');
            if((string)$row['status']!=='approved') throw new RuntimeException('Only approved subscriptions can be suspended.');
            $pdo->prepare('UPDATE company_subscriptions SET status="suspended",updated_at=NOW() WHERE id=?')->execute([$id]);
            saas_notify_company((int)$row['subscriber_company_id'],'subscription','Subscription suspended','Platform Control suspended your subscription to '.$row['target_name'].'.',url('company-network'));
            saas_notify_company((int)$row['target_company_id'],'subscription','Subscriber relationship suspended','Platform Control suspended the subscription from '.$row['subscriber_name'].'.',url('company-network'));
            pc_audit_safe('suspend_subscription','company_subscription',$id,(int)$row['target_company_id'],['subscriber_company_id'=>(int)$row['subscriber_company_id']]); flash('success','Subscription suspended.'); redirect('platform-control?tab=network');
        }
        if($action==='subscription_restore'){
            $id=(int)($_POST['subscription_id']??0);
            $st=$pdo->prepare('SELECT cs.*,s.name subscriber_name,t.name target_name FROM company_subscriptions cs JOIN companies s ON s.id=cs.subscriber_company_id JOIN companies t ON t.id=cs.target_company_id WHERE cs.id=? LIMIT 1');
            $st->execute([$id]);$row=$st->fetch();
            if(!$row) throw new RuntimeException('Subscription not found.');
            if((string)$row['status']!=='suspended') throw new RuntimeException('Only suspended subscriptions can be restored.');
            $pdo->prepare('UPDATE company_subscriptions SET status="approved",updated_at=NOW(),approved_at=COALESCE(approved_at,NOW()) WHERE id=?')->execute([$id]);
            saas_notify_company((int)$row['subscriber_company_id'],'subscription','Subscription restored','Platform Control restored your subscription to '.$row['target_name'].'.',url('company-network'));
            saas_notify_company((int)$row['target_company_id'],'subscription','Subscriber relationship restored','Platform Control restored the subscriber relationship from '.$row['subscriber_name'].'.',url('company-network'));
            pc_audit_safe('restore_subscription','company_subscription',$id,(int)$row['target_company_id'],['subscriber_company_id'=>(int)$row['subscriber_company_id']]); flash('success','Subscription restored.'); redirect('platform-control?tab=network');
        }
        if($action==='update_moderate'){
            $id=(int)($_POST['update_id']??0);$newStatus=(string)($_POST['new_status']??'removed');
            if(!in_array($newStatus,['published','removed'],true))$newStatus='removed';
            $st=$pdo->prepare('SELECT cu.*,c.name company_name FROM company_updates cu JOIN companies c ON c.id=cu.company_id WHERE cu.id=? LIMIT 1');$st->execute([$id]);$row=$st->fetch();
            if(!$row) throw new RuntimeException('Update not found.');
            $pdo->prepare('UPDATE company_updates SET status=?,updated_at=NOW() WHERE id=?')->execute([$newStatus,$id]);
            $msg=$newStatus==='removed'?'Platform Control removed one of your company updates.':'Platform Control restored one of your company updates.';
            saas_notify_company((int)$row['company_id'],'company_update','Company update moderation',$msg,url('company-network'));
            pc_audit_safe('moderate_update','company_update',$id,(int)$row['company_id'],['status'=>$newStatus]); flash('success',$newStatus==='removed'?'Update removed.':'Update restored.'); redirect('platform-control?tab=network');
        }
        if($action==='review_moderate'){
            $id=(int)($_POST['review_id']??0);$newStatus=(string)($_POST['new_status']??'hidden');
            if(!in_array($newStatus,['published','hidden'],true))$newStatus='hidden';
            $st=$pdo->prepare('SELECT cr.*,c.name reviewer_company,p.name party_name,s.name subject_company FROM company_reviews cr JOIN companies c ON c.id=cr.reviewer_company_id JOIN parties p ON p.id=cr.party_id JOIN companies s ON s.id=cr.subject_company_id WHERE cr.id=? LIMIT 1');$st->execute([$id]);$row=$st->fetch();
            if(!$row) throw new RuntimeException('Review not found.');
            $pdo->prepare('UPDATE company_reviews SET status=?,updated_at=NOW() WHERE id=?')->execute([$newStatus,$id]);
            $msg=$newStatus==='hidden'?'Platform Control hid a review involving '.$row['subject_company'].'.':'Platform Control restored a review involving '.$row['subject_company'].'.';
            saas_notify_company((int)$row['reviewer_company_id'],'review','Review moderation',$msg,url('company-network'));
            saas_notify_company((int)$row['subject_company_id'],'review','Review moderation',$msg,url('company-network'));
            pc_audit_safe('moderate_review','company_review',$id,(int)$row['reviewer_company_id'],['status'=>$newStatus]); flash('success',$newStatus==='hidden'?'Review hidden.':'Review restored.'); redirect('platform-control?tab=reviews');
        }
        if($action==='ticket_update'){
            $id=(int)($_POST['ticket_id']??0);
            $status=(string)($_POST['status']??'open');
            $reply=trim((string)($_POST['admin_reply']??''));
            $allowed=['open','in_progress','resolved','closed'];
            if(!in_array($status,$allowed,true))$status='open';
            $ts=$pdo->prepare('SELECT company_id,subject FROM support_tickets WHERE id=? LIMIT 1');
            $ts->execute([$id]);
            $ticket=$ts->fetch();
            if(!$ticket)throw new RuntimeException('Support ticket not found.');
            $pdo->prepare("UPDATE support_tickets SET status=?,admin_reply=?,admin_replied_by=?,admin_replied_at=IF(?<>'',NOW(),admin_replied_at),updated_at=NOW() WHERE id=?")
                ->execute([$status,$reply!==''?$reply:null,(int)$u['id'],$reply,$id]);
            try{
                $pdo->exec("CREATE TABLE IF NOT EXISTS support_ticket_messages (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    ticket_id BIGINT UNSIGNED NOT NULL,
                    sender_type VARCHAR(20) NOT NULL DEFAULT 'company',
                    sender_user_id BIGINT UNSIGNED NULL,
                    body TEXT NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY(id), KEY idx_stm_ticket(ticket_id,created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            }catch(Throwable $ignore){}
            if($reply!==''){
                try{
                    $pdo->prepare('INSERT INTO support_ticket_messages(ticket_id,sender_type,sender_user_id,body) VALUES(?,?,?,?)')
                        ->execute([$id,'platform',(int)$u['id'],$reply]);
                }catch(Throwable $ignore){}
            }
            $companyId=(int)($ticket['company_id']??0);
            if($companyId>0){
                try{
                    $us=$pdo->prepare("SELECT id FROM users WHERE company_id=? AND status='active'");
                    $us->execute([$companyId]);
                    $ni=$pdo->prepare('INSERT INTO notifications(company_id,user_id,type,title,body,link) VALUES(?,?,?,?,?,?)');
                    $nTitle='Support request #'.$id.' updated';
                    $nBody=$reply!==''?$reply:('Status changed to '.ucwords(str_replace('_',' ',$status)).'.');
                    foreach($us as $ur){
                        try{$ni->execute([$companyId,(int)$ur['id'],'support',$nTitle,$nBody,url('support')]);}
                        catch(Throwable $ignore){try{$ni->execute([$companyId,(int)$ur['id'],'support',$nTitle,$nBody,null]);}catch(Throwable $ignore2){}}
                    }
                }catch(Throwable $ignore){}
            }
            pc_audit_safe('ticket_update','support_ticket',$id,$companyId,['status'=>$status,'reply_added'=>$reply!=='']);
            flash('success','Support ticket updated.');
            redirect('platform-control?tab=tickets');
        }
    } catch(Throwable $e){ flash('error',$e->getMessage()); redirect('platform-control?tab=' . ($companyId?'company&id='.$companyId:($tab?:'overview'))); }
}

$companies=[];$company=null;$stats=['companies'=>0,'free_companies'=>0,'paid_companies'=>0,'active_companies'=>0,'users'=>0,'logged_today'=>0,'online'=>0,'open_tickets'=>0,'db_size'=>0,'total_records'=>0];$recordCounts=[];$companyUsage=[];$billingHistory=[];$usageByCompany=[];$notices=[];$tickets=[];
try{
    if(in_array($tab,['overview','companies','company'],true)){
        try{
            $sql='SELECT c.id,c.name,c.email,c.phone,c.account_status,c.plan_name,c.billing_cycle,c.subscription_amount,c.subscription_started_at,c.subscription_expires_at,c.plan_converted_at,COUNT(DISTINCT u.id) user_count,MAX(u.last_login_at) last_login FROM companies c LEFT JOIN users u ON u.company_id=c.id';$params=[];
            if($q!==''){$sql.=' WHERE c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?';$like='%'.$q.'%';$params=[$like,$like,$like];}
            $sql.=' GROUP BY c.id ORDER BY c.id DESC';$st=$pdo->prepare($sql);$st->execute($params);$companies=$st->fetchAll();
        }catch(Throwable $e){$companies=[];}
        try{$recordCounts=pc_company_record_counts();}catch(Throwable $e){$recordCounts=[];}
        if($tab==='overview'){foreach($companies as $cc){try{$usageByCompany[(int)$cc['id']]=pc_usage_for_company((int)$cc['id']);}catch(Throwable $e){$usageByCompany[(int)$cc['id']]=['records'=>0,'transactions'=>0,'parties'=>0,'items'=>0,'users'=>0,'shared_db_bytes'=>0,'record_share'=>0,'tables'=>[]];}}}
    }
    if($tab==='company'&&$companyId>0){try{$st=$pdo->prepare('SELECT * FROM companies WHERE id=? LIMIT 1');$st->execute([$companyId]);$company=$st->fetch();}catch(Throwable $e){$company=null;}if($company){try{$companyUsage=pc_usage_for_company($companyId);}catch(Throwable $e){$companyUsage=['records'=>0,'transactions'=>0,'parties'=>0,'items'=>0,'users'=>0,'shared_db_bytes'=>0,'record_share'=>0,'tables'=>[]];}try{$st=$pdo->prepare('SELECT * FROM company_billing_records WHERE company_id=? ORDER BY id DESC LIMIT 20');$st->execute([$companyId]);$billingHistory=$st->fetchAll();}catch(Throwable $e){$billingHistory=[];}}}
    foreach(['companies'=>'SELECT COUNT(*) FROM companies','users'=>"SELECT COUNT(*) FROM users WHERE status='active'",'logged_today'=>'SELECT COUNT(DISTINCT company_id) FROM platform_login_events WHERE created_at>=CURDATE()','online'=>'SELECT COUNT(DISTINCT company_id) FROM platform_sessions WHERE last_seen_at>=NOW()-INTERVAL 10 MINUTE','open_tickets'=>"SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','in_progress')"] as $k=>$sql){try{$stats[$k]=(int)$pdo->query($sql)->fetchColumn();}catch(Throwable $e){$stats[$k]=0;}}
    foreach(['free_companies'=>"SELECT COUNT(*) FROM companies WHERE COALESCE(plan_name,'Free')='Free'",'paid_companies'=>"SELECT COUNT(*) FROM companies WHERE COALESCE(plan_name,'Free')='Paid'",'active_companies'=>"SELECT COUNT(*) FROM companies WHERE COALESCE(account_status,'active')='active'"] as $k=>$sql){try{$stats[$k]=(int)$pdo->query($sql)->fetchColumn();}catch(Throwable $e){$stats[$k]=0;}}
    $stats['db_size']=pc_bytes();$stats['total_records']=array_sum($recordCounts);
    if($tab==='notices'){try{$notices=$pdo->query('SELECT a.*,c.name target_company FROM platform_announcements a LEFT JOIN companies c ON c.id=a.target_company_id ORDER BY a.id DESC LIMIT 200')->fetchAll();}catch(Throwable $e){$notices=[];}}
    if($tab==='tickets'){try{$tickets=$pdo->query('SELECT s.*,c.name company_name,u.name user_name,u.email user_email FROM support_tickets s JOIN companies c ON c.id=s.company_id LEFT JOIN users u ON u.id=s.user_id ORDER BY FIELD(s.status,"open","in_progress","resolved","closed"),s.id DESC LIMIT 200')->fetchAll();}catch(Throwable $e){$tickets=[];}}
}catch(Throwable $e){ /* keep Platform Control renderable even when an optional SaaS table is unavailable */ }
$networkSubscriptions=[];$networkUpdates=[];$networkReviews=[];
if($tab==='network'){
    try{$networkSubscriptions=$pdo->query('SELECT cs.*,s.name subscriber_name,t.name target_name FROM company_subscriptions cs JOIN companies s ON s.id=cs.subscriber_company_id JOIN companies t ON t.id=cs.target_company_id ORDER BY FIELD(cs.status,"pending","approved","suspended","rejected","cancelled"),cs.id DESC LIMIT 300')->fetchAll();}catch(Throwable $e){$networkSubscriptions=[];}
    try{$networkUpdates=$pdo->query('SELECT cu.*,c.name company_name FROM company_updates cu JOIN companies c ON c.id=cu.company_id ORDER BY cu.id DESC LIMIT 200')->fetchAll();}catch(Throwable $e){$networkUpdates=[];}
}
if($tab==='reviews'){
    try{$networkReviews=$pdo->query('SELECT cr.*,r.name reviewer_company,s.name subject_company,p.name party_name FROM company_reviews cr JOIN companies r ON r.id=cr.reviewer_company_id JOIN companies s ON s.id=cr.subject_company_id JOIN parties p ON p.id=cr.party_id ORDER BY cr.id DESC LIMIT 300')->fetchAll();}catch(Throwable $e){$networkReviews=[];}
}

function pc_url(string $tab): string {return url('platform-control?tab='.$tab);} 
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Platform Control · sense</title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>?v=180"><style>
.platform-body{margin:0;background:#f3f6fa;color:#1c2a3d;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.platform-shell{display:flex;min-height:100vh}.platform-sidebar{width:235px;background:#172332;color:#fff;padding:18px 12px;box-sizing:border-box}.platform-brand{display:flex;align-items:center;gap:10px;padding:8px 8px 20px;border-bottom:1px solid rgba(255,255,255,.1);margin-bottom:12px}.platform-brand small{display:block;color:#9eb0c7;font-size:11px;margin-top:2px}.pc-nav{display:block;color:#cbd6e3;text-decoration:none;padding:11px 12px;border-radius:8px;margin:3px 0}.pc-nav:hover,.pc-nav.active{background:#243548;color:#fff}.platform-main{flex:1;min-width:0}.platform-head{display:flex;justify-content:space-between;gap:20px;align-items:center;padding:22px 28px;background:#fff;border-bottom:1px solid #dfe5ed;position:sticky;top:0;z-index:20}.platform-head h1{margin:0;font-size:22px}.platform-head p{margin:5px 0 0;color:#7a8798;font-size:13px}.pc-admin{font-size:13px;color:#607089}.platform-content{padding:18px 28px 40px}.pc-stats{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px}.pc-stat,.pc-panel{background:#fff;border:1px solid #dfe5ed;border-radius:10px;box-shadow:0 3px 12px rgba(15,23,42,.04)}.pc-stat{padding:14px}.pc-stat b{display:block;color:#718096;font-size:12px;font-weight:600}.pc-stat strong{display:block;font-size:26px;margin-top:8px}.pc-stat small{display:block;color:#8b98a8;margin-top:2px;font-size:10px}.pc-panel{padding:15px}.pc-panel-head{display:flex;justify-content:space-between;gap:15px;align-items:center;margin-bottom:10px}.pc-panel-head h2,.pc-panel-head h3{margin:0}.pc-panel-head p{margin:4px 0 0;color:#77869a;font-size:12px}.pc-note{padding:10px 12px;background:#f7f9fc;border-radius:8px;margin-top:12px;color:#6c7b8f;font-size:12px;line-height:1.5}.pc-badge{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;background:#edf2f7;color:#5d6b7e}.pc-badge.active,.pc-badge.resolved{background:#e8f8ef;color:#16834e}.pc-badge.suspended,.pc-badge.cancelled,.pc-badge.closed{background:#fff0f0;color:#b42318}.pc-badge.open,.pc-badge.in_progress,.pc-badge.trial{background:#fff8e6;color:#9a6700}.pc-toolbar{margin-bottom:12px}.pc-toolbar form{display:flex;gap:8px}.pc-toolbar input{min-width:300px}.pc-ticket-list{display:flex;flex-direction:column;gap:12px}.pc-ticket{border:1px solid #dfe5ed;border-radius:9px;padding:13px}.pc-ticket-head{display:flex;justify-content:space-between;gap:15px}.pc-ticket-meta{margin-top:7px;font-size:12px;color:#718096}.pc-ticket-message{margin-top:9px;padding:10px;background:#f8fafc;border-radius:7px;color:#34445c;font-size:13px;line-height:1.5}.pc-reply{margin-top:10px}.small-btn{padding:6px 9px!important;font-size:11px!important}.platform-content .grid2{gap:14px}.pc-usage-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin:10px 0}.pc-usage-card{border:1px solid #e2e8f0;border-radius:9px;padding:12px;background:#fbfdff}.pc-usage-card b{display:block;color:#718096;font-size:11px}.pc-usage-card strong{display:block;font-size:20px;margin-top:6px}.pc-meter{height:7px;background:#edf2f7;border-radius:10px;overflow:hidden;margin-top:8px}.pc-meter span{display:block;height:100%;background:#1688f7;border-radius:10px}.pc-plan-free{color:#15803d}.pc-plan-paid{color:#2563eb}.pc-form-note{font-size:12px;color:#6b7b90;background:#f8fafc;border:1px solid #e5eaf0;padding:9px;border-radius:8px;margin:10px 0}.pc-inline{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.pc-mini{font-size:11px;color:#78879a}.pc-warning{background:#fff8e7;border-color:#f5d78b}.pc-table-tight td,.pc-table-tight th{padding:8px 9px}
@media(max-width:1100px){.pc-stats{grid-template-columns:repeat(3,minmax(0,1fr))}.pc-usage-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.platform-sidebar{width:190px}.platform-head{padding:16px}.platform-content{padding:14px}.pc-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.pc-usage-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pc-toolbar input{min-width:0;width:100%}.pc-toolbar form{flex-wrap:wrap}.pc-admin{display:none}}@media(max-width:600px){.platform-shell{display:block}.platform-sidebar{width:100%;position:static}.platform-head{position:static}.pc-stats,.pc-usage-grid{grid-template-columns:1fr}.platform-content .grid2{grid-template-columns:1fr}}
</style></head><body><div class="platform-shell"><aside class="platform-sidebar"><div class="platform-brand"><div class="brandmark">S</div><div><b>sense</b><small>Platform Control</small></div></div><a class="pc-nav <?= $tab==='overview'?'active':''?>" href="<?=e(pc_url('overview'))?>">⌂ Overview</a><a class="pc-nav <?=in_array($tab,['companies','company'],true)?'active':''?>" href="<?=e(pc_url('companies'))?>">▤ Companies & Usage</a><a class="pc-nav <?= $tab==='notices'?'active':''?>" href="<?=e(pc_url('notices'))?>">✦ Ads / Notices</a><a class="pc-nav <?= $tab==='network'?'active':''?>" href="<?=e(pc_url('network'))?>">◎ Network Moderation</a><a class="pc-nav <?= $tab==='reviews'?'active':''?>" href="<?=e(pc_url('reviews'))?>">★ Reviews</a><a class="pc-nav <?= $tab==='tickets'?'active':''?>" href="<?=e(pc_url('tickets'))?>">? Feedback / Bugs</a><a class="pc-nav" href="<?=e(url('platform-audit'))?>">◷ Admin Activity</a><a class="pc-nav" href="<?=e(url('dashboard'))?>">← Company ERP</a><a class="pc-nav" href="<?=e(url('platform-logout'))?>">Logout</a></aside><main class="platform-main"><div class="platform-head"><div><h1><?=e(match($tab){'companies'=>'Companies & Usage','company'=>'Company Usage & Plan','notices'=>'Ads / Notices','network'=>'Network Moderation','reviews'=>'Reviews','tickets'=>'Feedback / Bugs',default=>'Platform Control'})?></h1><p>Monitor company usage and manually manage Free/Paid access.</p></div><div class="pc-admin"><?=e($u['name'])?> · Platform Admin</div></div><div class="platform-content">
<?php foreach(flashes() as $f):?><div class="alert <?=$f[0]?>"><?=e($f[1])?></div><?php endforeach;?>
<?php if($tab==='overview'):?>
<div class="pc-stats"><div class="pc-stat"><b>Total Companies</b><strong><?=$stats['companies']?></strong></div><div class="pc-stat"><b>Free</b><strong><?=$stats['free_companies']?></strong><small>Unlimited while free</small></div><div class="pc-stat"><b>Paid</b><strong><?=$stats['paid_companies']?></strong><small>Manual billing</small></div><div class="pc-stat"><b>Online Now</b><strong><?=$stats['online']?></strong><small>active in last 10 min</small></div><div class="pc-stat"><b>Logged In Today</b><strong><?=$stats['logged_today']?></strong></div><div class="pc-stat"><b>Total Records</b><strong><?=number_format($stats['total_records'])?></strong><small>company-scoped records</small></div></div>
<div class="pc-panel" style="margin-top:14px"><div class="pc-panel-head"><div><h2>Usage Overview</h2><p>Free companies are not automatically blocked. Review usage here before any manual Paid conversion.</p></div><a class="btn" href="<?=e(pc_url('companies'))?>">View Companies</a></div><div class="pc-note pc-warning"><b>Manual conversion rule:</b> notify the company first, then convert it to Paid. No automatic blocking is applied.</div><div class="table-wrap" style="margin-top:10px"><table class="pc-table-tight"><thead><tr><th>COMPANY</th><th>PLAN</th><th>USERS</th><th>TRANSACTIONS</th><th>PARTIES</th><th>ITEMS</th><th>RECORDS</th><th>LAST LOGIN</th></tr></thead><tbody>
<?php foreach($companies as $c):$rid=(int)$c['id'];$rec=(int)($recordCounts[$rid]??0);?><tr><td><a href="<?=e(url('platform-control?tab=company&id='.$rid))?>"><b><?=e($c['name'])?></b></a><div class="subtle"><?=e($c['email'])?></div></td><td><b class="<?=($c['plan_name']??'Free')==='Paid'?'pc-plan-paid':'pc-plan-free'?>"><?=e($c['plan_name']??'Free')?></b></td><td><?=e((string)$c['user_count'])?></td><td><?=number_format((int)($usageByCompany[$rid]['transactions']??0))?></td><td><?=number_format((int)($usageByCompany[$rid]['parties']??0))?></td><td><?=number_format((int)($usageByCompany[$rid]['items']??0))?></td><td><?=number_format($rec)?></td><td><?=e($c['last_login']??'-')?></td></tr><?php endforeach;if(!$companies):?><tr><td colspan="8" class="subtle">No companies found.</td></tr><?php endif;?></tbody></table></div></div>
<?php elseif($tab==='companies'):?>
<div class="pc-toolbar"><form method="get"><input type="hidden" name="tab" value="companies"><input name="q" value="<?=e($q)?>" placeholder="Search company name, email or phone"><button class="btn primary">Search</button></form></div>
<div class="pc-panel"><div class="table-wrap"><table class="pc-table-tight"><thead><tr><th>COMPANY</th><th>PLAN</th><th>CYCLE</th><th>USERS</th><th>RECORDS</th><th>STATUS</th><th>LAST LOGIN</th><th></th></tr></thead><tbody><?php foreach($companies as $c):$rid=(int)$c['id'];$rec=(int)($recordCounts[$rid]??0);?><tr><td><b><?=e($c['name'])?></b><div class="subtle"><?=e($c['email'])?> · <?=e($c['phone'])?></div></td><td><b class="<?=($c['plan_name']??'Free')==='Paid'?'pc-plan-paid':'pc-plan-free'?>"><?=e($c['plan_name']??'Free')?></b></td><td><?=e(ucfirst((string)($c['billing_cycle']??'—')))?></td><td><?=e((string)$c['user_count'])?></td><td><?=number_format($rec)?></td><td><span class="pc-badge <?=e((string)($c['account_status']??'active'))?>"><?=e(ucfirst((string)($c['account_status']??'active')))?> </span></td><td><?=e($c['last_login']??'-')?></td><td><a class="btn small-btn" href="<?=e(url('platform-control?tab=company&id='.$rid))?>">Manage</a></td></tr><?php endforeach;if(!$companies):?><tr><td colspan="8" class="subtle">No companies found.</td></tr><?php endif;?></tbody></table></div></div>
<?php elseif($tab==='company'):?>
<?php if($company):?>
<div class="pc-panel"><div class="pc-panel-head"><div><h2><?=e(pc_company_label($company))?></h2><p><?=e((string)($company['email']??''))?> · <?=e((string)($company['phone']??''))?></p></div><a class="btn" href="<?=e(pc_url('companies'))?>">Back to Companies</a></div><div class="pc-usage-grid"><div class="pc-usage-card"><b>Total Records</b><strong><?=number_format((int)$companyUsage['records'])?></strong></div><div class="pc-usage-card"><b>Transactions</b><strong><?=number_format((int)$companyUsage['transactions'])?></strong></div><div class="pc-usage-card"><b>Parties</b><strong><?=number_format((int)$companyUsage['parties'])?></strong></div><div class="pc-usage-card"><b>Items</b><strong><?=number_format((int)$companyUsage['items'])?></strong></div><div class="pc-usage-card"><b>Users</b><strong><?=number_format((int)$companyUsage['users'])?></strong></div></div><div class="pc-note"><b>Shared database:</b> <?=e(format_bytes((float)$companyUsage['shared_db_bytes']))?> total. The company meter above is a record-count footprint; exact per-company disk MB is not separately measurable in a shared InnoDB database.</div></div>
<div class="grid2" style="margin-top:14px"><div class="pc-panel"><div class="pc-panel-head"><h2>Usage by Table / Module</h2></div><div class="table-wrap"><table class="pc-table-tight"><thead><tr><th>TABLE / MODULE</th><th>RECORDS</th></tr></thead><tbody><?php foreach($companyUsage['tables'] as $tn=>$cnt):?><tr><td><?=e($tn)?></td><td><?=number_format((int)$cnt)?></td></tr><?php endforeach;if(!$companyUsage['tables']):?><tr><td colspan="2" class="subtle">No company-scoped records found.</td></tr><?php endif;?></tbody></table></div></div>
<div class="pc-panel"><div class="pc-panel-head"><div><h2>Plan Management</h2><p>Free is unlimited. Paid conversion is manual.</p></div></div><div class="pc-form-note pc-warning"><b>Required workflow:</b> send a notice to this company before converting it to Paid.</div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="platform_action" value="send_upgrade_notice"><input type="hidden" name="company_id" value="<?=e((string)$company['id'])?>"><div class="form-group"><label>Upgrade Notice Title</label><input name="notice_title" value="Usage review for <?=e((string)$company['name'])?>"></div><div class="form-group"><label>Message*</label><textarea name="notice_body" rows="4" required>We reviewed your sense usage. Please contact us to discuss the next plan for your company.</textarea></div><button class="btn">Send Notice</button></form><hr style="border:0;border-top:1px solid #e6ebf1;margin:16px 0"><form method="post" onsubmit="return confirm('Apply this plan change?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="platform_action" value="company_plan_update"><input type="hidden" name="company_id" value="<?=e((string)$company['id'])?>"><div class="grid2"><div class="form-group"><label>Plan</label><select name="plan_name" id="pcPlan"><option value="Free" <?=($company['plan_name']??'Free')==='Free'?'selected':''?>>Free — Unlimited</option><option value="Paid" <?=($company['plan_name']??'Free')==='Paid'?'selected':''?>>Paid</option></select></div><div class="form-group"><label>Billing Cycle</label><select name="billing_cycle"><option value="monthly" <?=($company['billing_cycle']??'monthly')==='monthly'?'selected':''?>>Monthly</option><option value="yearly" <?=($company['billing_cycle']??'monthly')==='yearly'?'selected':''?>>Yearly</option></select></div><div class="form-group"><label>Amount</label><input type="number" step="0.01" min="0" name="subscription_amount" value="<?=e((string)($company['subscription_amount']??0))?>"></div><div class="form-group"><label>Payment Status</label><select name="payment_status"><option value="pending">Pending</option><option value="unpaid">Unpaid</option><option value="partial">Partial</option><option value="paid">Paid</option></select></div></div><div class="form-group"><label>Billing Note</label><textarea name="billing_note" rows="3" placeholder="Manual payment / plan note"></textarea></div><label class="pc-inline"><input type="checkbox" name="company_notified" value="1"> <span>I have notified the company before this Paid conversion.</span></label><div style="margin-top:10px"><button class="btn primary">Save Plan</button></div></form></div></div>
<div class="pc-panel" style="margin-top:14px"><div class="pc-panel-head"><h2>Billing History</h2></div><div class="table-wrap"><table class="pc-table-tight"><thead><tr><th>PLAN</th><th>CYCLE</th><th>AMOUNT</th><th>PAYMENT</th><th>START</th><th>END</th><th>NOTE</th></tr></thead><tbody><?php foreach($billingHistory as $b):?><tr><td><?=e($b['plan_name'])?></td><td><?=e(ucfirst($b['billing_cycle']))?></td><td><?=money((float)$b['amount'],(string)($company['currency_code']??'৳'))?></td><td><?=e(ucfirst($b['payment_status']))?></td><td><?=e($b['starts_at'])?></td><td><?=e($b['ends_at']??'—')?></td><td><?=e($b['note']??'')?></td></tr><?php endforeach;if(!$billingHistory):?><tr><td colspan="7" class="subtle">No billing history yet.</td></tr><?php endif;?></tbody></table></div></div>
<?php else: ?><div class="alert error">Company not found.</div><?php endif; ?>
<?php elseif($tab==='notices'):?>
<div class="grid2"><div class="pc-panel"><div class="pc-panel-head"><h2>Create Ad / Notice</h2></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="platform_action" value="create_notice"><div class="form-group"><label>Title*</label><input name="title" required></div><div class="form-group"><label>Message*</label><textarea name="body" rows="7" required></textarea></div><div class="grid2"><div class="form-group"><label>Type</label><select name="type"><option value="notice">Notice</option><option value="ad">Ad</option><option value="info">Info</option><option value="warning">Warning</option></select></div><div class="form-group"><label>Priority</label><input type="number" name="priority" value="0"></div><div class="form-group"><label>Audience</label><select name="target_type" id="noticeTarget"><option value="all">All Companies</option><option value="company">One Company</option></select></div><div class="form-group"><label>Company</label><select name="target_company_id"><option value="0">Select company</option><?php foreach($companies as $c):?><option value="<?=e((string)$c['id'])?>"><?=e($c['name'])?></option><?php endforeach;?></select></div><div class="form-group"><label>Starts at</label><input type="datetime-local" name="starts_at" value="<?=date('Y-m-d\TH:i')?>"></div><div class="form-group"><label>Ends at</label><input type="datetime-local" name="ends_at"></div></div><button class="btn primary">Publish</button></form></div><div class="pc-panel"><div class="pc-panel-head"><h2>Published</h2><span class="status paid"><?=count($notices)?> records</span></div><div class="table-wrap"><table><thead><tr><th>TITLE</th><th>TYPE</th><th>TARGET</th><th>STATUS</th><th>DATES</th><th></th></tr></thead><tbody><?php foreach($notices as $n):?><tr><td><b><?=e($n['title'])?></b><div class="subtle"><?=nl2br(e(mb_strimwidth((string)$n['body'],0,120,'…')))?></div></td><td><?=e(ucfirst($n['type']))?></td><td><?=e($n['target_type']==='all'?'All Companies':($n['target_company']??'Company'))?></td><td><?=((int)$n['is_active']===1)?'Active':'Off'?></td><td><?=e($n['starts_at'])?><br><?=e($n['ends_at']??'No end')?></td><td><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="platform_action" value="toggle_notice"><input type="hidden" name="id" value="<?=e((string)$n['id'])?>"><button class="btn small-btn">Toggle</button></form> <form method="post" style="display:inline" onsubmit="return confirm('Delete this notice?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="platform_action" value="delete_notice"><input type="hidden" name="id" value="<?=e((string)$n['id'])?>"><button class="btn small-btn">Delete</button></form></td></tr><?php endforeach;if(!$notices):?><tr><td colspan="6" class="subtle">No notices yet.</td></tr><?php endif;?></tbody></table></div></div></div>

<?php endif; if($tab==='network'):?>
<div class="grid2">
  <div class="pc-panel"><div class="pc-panel-head"><div><h2>Company Subscriptions</h2><p>Platform moderation only. Company approval rules remain in effect.</p></div><span class="status paid"><?=count($networkSubscriptions)?></span></div>
    <div class="table-wrap"><table class="pc-table-tight"><thead><tr><th>SUBSCRIBER</th><th>TARGET</th><th>STATUS</th><th>REQUESTED</th><th></th></tr></thead><tbody>
    <?php foreach($networkSubscriptions as $ns): ?><tr><td><b><?=e($ns['subscriber_name'])?></b></td><td><?=e($ns['target_name'])?></td><td><span class="pc-badge <?=e((string)$ns['status'])?>"><?=e(ucfirst((string)$ns['status']))?></span></td><td><?=e((string)$ns['requested_at'])?></td><td><?php if($ns['status']==='approved'): ?><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="platform_action" value="subscription_suspend"><input type="hidden" name="subscription_id" value="<?=e((string)$ns['id'])?>"><button class="btn small-btn">Suspend</button></form><?php elseif($ns['status']==='suspended'): ?><form method="post" style="display:inline"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="platform_action" value="subscription_restore"><input type="hidden" name="subscription_id" value="<?=e((string)$ns['id'])?>"><button class="btn small-btn">Restore</button></form><?php else: ?><span class="subtle">—</span><?php endif; ?></td></tr><?php endforeach; if(!$networkSubscriptions): ?><tr><td colspan="5" class="subtle">No subscription records.</td></tr><?php endif; ?></tbody></table></div>
  </div>
  <div class="pc-panel"><div class="pc-panel-head"><div><h2>Company Updates</h2><p>Hide or restore updates that violate platform rules.</p></div><span class="status due"><?=count($networkUpdates)?></span></div>
    <div class="table-wrap"><table class="pc-table-tight"><thead><tr><th>COMPANY</th><th>TYPE</th><th>TITLE</th><th>STATUS</th><th></th></tr></thead><tbody>
    <?php foreach($networkUpdates as $nu): ?><tr><td><?=e($nu['company_name'])?></td><td><?=e(ucfirst((string)$nu['update_type']))?></td><td><b><?=e($nu['title'])?></b><div class="subtle"><?=e(mb_strimwidth((string)($nu['body']??''),0,90,'…'))?></div></td><td><span class="pc-badge <?=e((string)$nu['status'])?>"><?=e(ucfirst((string)$nu['status']))?></span></td><td><form method="post" style="display:inline" onsubmit="return confirm('Change this update moderation status?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="platform_action" value="update_moderate"><input type="hidden" name="update_id" value="<?=e((string)$nu['id'])?>"><input type="hidden" name="new_status" value="<?=((string)$nu['status']==='published'?'removed':'published')?>"><button class="btn small-btn"><?=((string)$nu['status']==='published'?'Hide':'Restore')?></button></form></td></tr><?php endforeach; if(!$networkUpdates): ?><tr><td colspan="5" class="subtle">No company updates.</td></tr><?php endif; ?></tbody></table></div>
  </div>
</div>
<?php elseif($tab==='reviews'):?>
<div class="pc-panel"><div class="pc-panel-head"><div><h2>Customer Reviews</h2><p>Moderate relationship-gated company reviews.</p></div><span class="status paid"><?=count($networkReviews)?></span></div>
<div class="table-wrap"><table class="pc-table-tight"><thead><tr><th>REVIEWER</th><th>SUBJECT</th><th>CUSTOMER</th><th>RATING</th><th>REVIEW</th><th>STATUS</th><th></th></tr></thead><tbody>
<?php foreach($networkReviews as $nr): ?><tr><td><?=e($nr['reviewer_company'])?></td><td><?=e($nr['subject_company'])?></td><td><?=e($nr['party_name'])?></td><td><?=str_repeat('★',(int)$nr['rating']).str_repeat('☆',5-(int)$nr['rating'])?></td><td><?=e(mb_strimwidth((string)$nr['comment'],0,140,'…'))?></td><td><span class="pc-badge <?=e((string)$nr['status'])?>"><?=e(ucfirst((string)$nr['status']))?></span></td><td><form method="post" style="display:inline" onsubmit="return confirm('Change this review moderation status?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="platform_action" value="review_moderate"><input type="hidden" name="review_id" value="<?=e((string)$nr['id'])?>"><input type="hidden" name="new_status" value="<?=((string)$nr['status']==='published'?'hidden':'published')?>"><button class="btn small-btn"><?=((string)$nr['status']==='published'?'Hide':'Restore')?></button></form></td></tr><?php endforeach; if(!$networkReviews): ?><tr><td colspan="7" class="subtle">No reviews.</td></tr><?php endif; ?></tbody></table></div></div>
<?php elseif($tab==='tickets'):?><div class="pc-panel"><div class="pc-panel-head"><div><h2>Customer Feedback / Bugs</h2><p>Review and reply to requests from all companies.</p></div><span class="status due"><?=count($tickets)?> records</span></div><div class="pc-ticket-list"><?php foreach($tickets as $t):?><div class="pc-ticket"><div class="pc-ticket-head"><div><b>#<?=e((string)$t['id'])?> · <?=e($t['subject'])?></b><div class="subtle"><?=e($t['company_name'])?> · <?=e($t['user_name']??'Unknown')?><br><?=e($t['user_email']??'')?></div></div><span class="pc-badge <?=e($t['status'])?>"><?=e(ucfirst(str_replace('_',' ',$t['status'])))?></span></div><div class="pc-ticket-meta"><?=e(ucfirst($t['category']))?> · <?=e(ucfirst($t['priority']))?> · <?=e($t['created_at'])?></div><div class="pc-ticket-message"><?=nl2br(e($t['message']))?></div><form method="post" class="pc-reply"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="platform_action" value="ticket_update"><input type="hidden" name="ticket_id" value="<?=e((string)$t['id'])?>"><div class="grid2"><div class="form-group"><label>Status</label><select name="status"><?php foreach(['open','in_progress','resolved','closed'] as $s):?><option value="<?=$s?>" <?=($t['status']===$s?'selected':'')?>><?=ucwords(str_replace('_',' ',$s))?></option><?php endforeach;?></select></div><div class="form-group"><label>Reply</label><textarea name="admin_reply" rows="3" placeholder="Write a reply to the company."><?=e($t['admin_reply']??'')?></textarea></div></div><button class="btn primary">Save Reply</button></form></div><?php endforeach;if(!$tickets):?><div class="subtle">No support tickets found.</div><?php endif;?></div></div><?php endif;
?></div></main></div></body></html>

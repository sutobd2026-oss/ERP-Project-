<?php
require_login();
$u=user();
$cid=(int)$u['company_id'];
$pdo=db();

function ensure_support_thread_table(): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS support_ticket_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            sender_type VARCHAR(20) NOT NULL DEFAULT 'company',
            sender_user_id BIGINT UNSIGNED NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(id),
            KEY idx_stm_ticket(ticket_id,created_at),
            KEY idx_stm_sender(sender_type,sender_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch(Throwable $e) {}
}
ensure_support_thread_table();

function support_notify_company(int $companyId, string $title, string $body, ?string $link=null): void {
    try {
        $st=db()->prepare("SELECT id FROM users WHERE company_id=? AND status='active'");
        $st->execute([$companyId]);
        $ins=db()->prepare('INSERT INTO notifications(company_id,user_id,type,title,body,link) VALUES(?,?,?,?,?,?)');
        foreach($st as $r){
            try{$ins->execute([$companyId,(int)$r['id'],'support',$title,$body,$link]);}
            catch(Throwable $e){try{$ins->execute([$companyId,(int)$r['id'],'support',$title,$body,null]);}catch(Throwable $e2){}}
        }
    } catch(Throwable $e) {}
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf();
    $action=(string)($_POST['support_action']??'create');
    if($action==='create'){
        $subject=trim((string)($_POST['subject']??''));
        $category=trim((string)($_POST['category']??'bug'));
        $priority=trim((string)($_POST['priority']??'normal'));
        $message=trim((string)($_POST['message']??''));
        $allowedCat=['bug','feedback','feature','billing','other']; $allowedPri=['low','normal','high','urgent'];
        if($subject===''||$message===''){flash('error','Subject and message are required.');redirect('support');}
        if(!in_array($category,$allowedCat,true))$category='bug';
        if(!in_array($priority,$allowedPri,true))$priority='normal';
        $pdo->prepare('INSERT INTO support_tickets(company_id,user_id,subject,category,message,priority,status) VALUES(?,?,?,?,?,? ,"open")')
            ->execute([$cid,$u['id'],$subject,$category,$message,$priority]);
        $ticketId=(int)$pdo->lastInsertId();
        try{$pdo->prepare('INSERT INTO support_ticket_messages(ticket_id,sender_type,sender_user_id,body) VALUES(?,?,?,?)')->execute([$ticketId,'company',(int)$u['id'],$message]);}catch(Throwable $e){}
        flash('success','Your feedback/support ticket has been submitted.'); redirect('support');
    }
    if($action==='reply'){
        $ticketId=(int)($_POST['ticket_id']??0);
        $body=trim((string)($_POST['message']??''));
        $st=$pdo->prepare('SELECT id,status,subject FROM support_tickets WHERE id=? AND company_id=? LIMIT 1');
        $st->execute([$ticketId,$cid]); $ticket=$st->fetch();
        if(!$ticket){flash('error','Support request not found.');redirect('support');}
        if($body===''){flash('error','Reply message is required.');redirect('support');}
        $pdo->prepare('INSERT INTO support_ticket_messages(ticket_id,sender_type,sender_user_id,body) VALUES(?,?,?,?)')->execute([$ticketId,'company',(int)$u['id'],$body]);
        $pdo->prepare("UPDATE support_tickets SET status='open',updated_at=NOW() WHERE id=? AND company_id=?")->execute([$ticketId,$cid]);
        flash('success','Your reply has been sent.'); redirect('support');
    }
}

$st=$pdo->prepare('SELECT id,subject,category,priority,status,message,admin_reply,admin_replied_at,created_at,updated_at FROM support_tickets WHERE company_id=? ORDER BY id DESC LIMIT 100');
$st->execute([$cid]); $tickets=$st->fetchAll();

foreach($tickets as &$t){
    try{
        $ms=$pdo->prepare('SELECT sender_type,body,created_at FROM support_ticket_messages WHERE ticket_id=? ORDER BY id ASC');
        $ms->execute([(int)$t['id']]); $t['messages']=$ms->fetchAll();
    }catch(Throwable $e){$t['messages']=[];}
    if(!$t['messages'] && trim((string)$t['message'])!==''){
        $t['messages']=[['sender_type'=>'company','body'=>$t['message'],'created_at'=>$t['created_at']]];
        if(trim((string)$t['admin_reply'])!=='') $t['messages'][]=['sender_type'=>'platform','body'=>$t['admin_reply'],'created_at'=>$t['admin_replied_at']?:$t['updated_at']];
    }
}
unset($t);

?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Support & Feedback · <?=e($u['company_name'])?></title><link rel="stylesheet" href="<?=e(url('assets/app.css'))?>?v=190"></head>
<body><div class="app-shell"><main class="main" style="width:100%"><header class="topbar"><div class="searchbar"><span>⌕</span><span style="font-size:14px">Support & Feedback</span></div><div class="top-actions"><a class="btn" href="<?=e(url('dashboard'))?>">← Dashboard</a><a class="gear" href="<?=e(url('logout'))?>">Logout</a></div></header>
<div class="content"><div class="page-title"><div><h1>Support & Feedback</h1><p>Report a bug, send feedback, or request a feature. Continue the conversation from the same ticket.</p></div></div>
<div class="grid2">
<div class="panel"><div class="panel-head"><h2>New Request</h2></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="support_action" value="create"><div class="form-group"><label>Subject*</label><input name="subject" required></div><div class="grid2"><div class="form-group"><label>Category</label><select name="category"><option value="bug">Bug / Problem</option><option value="feedback">Feedback</option><option value="feature">Feature Request</option><option value="billing">Billing</option><option value="other">Other</option></select></div><div class="form-group"><label>Priority</label><select name="priority"><option value="normal">Normal</option><option value="low">Low</option><option value="high">High</option><option value="urgent">Urgent</option></select></div></div><div class="form-group"><label>Message*</label><textarea name="message" rows="8" required placeholder="Describe the problem, steps to reproduce, or your feedback."></textarea></div><button class="btn primary">Send Request</button></form></div>
<div class="panel"><div class="panel-head"><h2>My Requests</h2><span class="status paid"><?=count($tickets)?> total</span></div><div class="support-list">
<?php foreach($tickets as $t):?><div class="support-ticket"><div class="support-ticket-head"><strong>#<?=e((string)$t['id'])?> · <?=e($t['subject'])?></strong><span class="status <?=in_array($t['status'],['closed','resolved'],true)?'paid':'due'?>"><?=e(ucfirst(str_replace('_',' ',$t['status'])))?></span></div><div class="subtle"><?=e(ucfirst($t['category']))?> · <?=e(ucfirst($t['priority']))?> · <?=e($t['created_at'])?></div>
<div class="support-thread"><?php foreach($t['messages'] as $m):?><div class="support-message <?=($m['sender_type']==='platform'?'platform':'company')?>"><div class="support-message-meta"><b><?=($m['sender_type']==='platform'?'sense Support':'You')?></b><span><?=e((string)$m['created_at'])?></span></div><div><?=nl2br(e((string)$m['body']))?></div></div><?php endforeach;?></div>
<?php if($t['status']!=='closed'):?><form method="post" class="support-reply-form"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="support_action" value="reply"><input type="hidden" name="ticket_id" value="<?=e((string)$t['id'])?>"><textarea name="message" rows="3" placeholder="Write a reply..." required></textarea><div style="display:flex;justify-content:flex-end;margin-top:7px"><button class="btn small-btn">Reply</button></div></form><?php else:?><div class="subtle" style="margin-top:8px">This request is closed. Create a new request for a new issue.</div><?php endif;?></div><?php endforeach;if(!$tickets):?><div class="subtle">No requests yet.</div><?php endif;?></div></div></div></div></main></div>
<style>
.support-list{display:flex;flex-direction:column;gap:10px}.support-ticket{border:1px solid #dde4ee;border-radius:12px;padding:13px;background:#fff}.support-ticket-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.support-thread{display:flex;flex-direction:column;gap:8px;margin-top:10px}.support-message{padding:9px 11px;border-radius:9px;font-size:13px;line-height:1.5}.support-message.company{background:#f7f9fc;border:1px solid #e7ecf3}.support-message.platform{background:#f4fbf7;border-left:3px solid #10b981}.support-message-meta{display:flex;justify-content:space-between;gap:12px;margin-bottom:4px;color:#6b778c;font-size:11px}.support-reply-form{margin-top:10px;border-top:1px solid #edf1f5;padding-top:10px}.support-reply-form textarea{width:100%;box-sizing:border-box}
</style></body></html>

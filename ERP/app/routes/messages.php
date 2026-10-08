<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='messages'){
    $u=require_login(); $cid=(int)$u['company_id']; $pdo=db();
    $role=strtolower((string)($u['role']??''));
    $isMessageAdmin=in_array($role,['super_admin','admin'],true);

    // v230: return whether two companies are connected for messaging.
    $messageCompanyAllowed=function(int $otherCompanyId)use($cid,$pdo):bool{
        if($otherCompanyId<=0 || $otherCompanyId===$cid) return true;
        try{
            $st=$pdo->prepare("SELECT 1 FROM company_subscriptions WHERE status='approved' AND ((subscriber_company_id=? AND target_company_id=?) OR (subscriber_company_id=? AND target_company_id=?)) LIMIT 1");
            $st->execute([$cid,$otherCompanyId,$otherCompanyId,$cid]);
            if($st->fetchColumn()) return true;
        }catch(Throwable $e){error_log('v230 message subscription check: '.$e->getMessage());}
        try{
            $st=$pdo->prepare("SELECT 1 FROM party_company_links WHERE relation_type IN ('customer','supplier') AND ((company_id=? AND linked_company_id=?) OR (company_id=? AND linked_company_id=?)) LIMIT 1");
            $st->execute([$cid,$otherCompanyId,$otherCompanyId,$cid]);
            if($st->fetchColumn()) return true;
        }catch(Throwable $e){error_log('v230 message party-company check: '.$e->getMessage());}
        return false;
    };

    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $receiver=(int)($_POST['receiver_id']??0);
        $receiverCompany=(int)($_POST['receiver_company_id']??0);
        $body=trim($_POST['body']??'');
        if($receiver<1 || $receiver===$u['id'] || $body===''){flash('error','Choose another user and enter a message.');redirect('messages');}
        try{
            $st=$pdo->prepare('SELECT id,company_id,name,email,role FROM users WHERE id=? AND status="active" LIMIT 1');
            $st->execute([$receiver]); $recipient=$st->fetch();
            if(!$recipient) throw new RuntimeException('Invalid recipient.');
            $receiverCompany=(int)$recipient['company_id'];
            if($receiverCompany!==$cid && !$messageCompanyAllowed($receiverCompany)){
                throw new RuntimeException('You can message only approved/network-connected or customer/supplier companies.');
            }
            $pdo->prepare('INSERT INTO messages(company_id,receiver_company_id,sender_id,receiver_id,body) VALUES(?,?,?,?,?)')->execute([$cid,$receiverCompany,$u['id'],$receiver,$body]);
            $mid=(int)$pdo->lastInsertId();
            try{
                $notifyCompany=$receiverCompany;
                $pdo->prepare('INSERT INTO notifications(company_id,user_id,type,title,body) VALUES(?,?,?,?,?)')->execute([$notifyCompany,$receiver,'message','New message from '.$u['name'],mb_substr($body,0,160)]);
            }catch(Throwable $e){error_log('v230 message notification: '.$e->getMessage());}
            audit('send','message',$mid,['receiver_id'=>$receiver,'receiver_company_id'=>$receiverCompany]);
            flash('success','Message sent.');
            redirect('messages?thread='.$u['id'].'-'.$receiver);
        }catch(Throwable $e){flash('error',$e->getMessage());redirect('messages');}
    }

    if(isset($_GET['read'])){
        $mid=(int)$_GET['read'];
        try{$pdo->prepare('UPDATE messages SET read_at=NOW() WHERE id=? AND receiver_id=?')->execute([$mid,$u['id']]);}catch(Throwable $e){error_log('v230 message read: '.$e->getMessage());}
        $thread=(string)($_GET['thread']??'');
        redirect('messages'.($thread!==''?'?thread='.rawurlencode($thread):''));
    }

    // Allowed recipient companies: own company plus approved network connections and customer/supplier links.
    $allowedCompanyIds=[$cid=>true];
    try{
        $st=$pdo->prepare("SELECT target_company_id FROM company_subscriptions WHERE subscriber_company_id=? AND status='approved' UNION SELECT subscriber_company_id FROM company_subscriptions WHERE target_company_id=? AND status='approved'");
        $st->execute([$cid,$cid]);
        foreach($st->fetchAll(PDO::FETCH_COLUMN) as $x){$allowedCompanyIds[(int)$x]=true;}
    }catch(Throwable $e){error_log('v230 message allowed subscriptions: '.$e->getMessage());}
    try{
        $st=$pdo->prepare("SELECT linked_company_id FROM party_company_links WHERE company_id=? AND relation_type IN ('customer','supplier') UNION SELECT company_id FROM party_company_links WHERE linked_company_id=? AND relation_type IN ('customer','supplier')");
        $st->execute([$cid,$cid]);
        foreach($st->fetchAll(PDO::FETCH_COLUMN) as $x){$allowedCompanyIds[(int)$x]=true;}
    }catch(Throwable $e){error_log('v230 message allowed party links: '.$e->getMessage());}
    $allowedCompanyIdList=array_values(array_filter(array_map('intval',array_keys($allowedCompanyIds)),fn($x)=>$x>0));

    $allowedCompanies=[];
    if($allowedCompanyIdList){
        $ph=implode(',',array_fill(0,count($allowedCompanyIdList),'?'));
        try{
            $st=$pdo->prepare("SELECT id,name,logo_path FROM companies WHERE id IN ($ph) AND account_status<>'suspended' ORDER BY name");
            $st->execute($allowedCompanyIdList);$allowedCompanies=$st->fetchAll();
        }catch(Throwable $e){$allowedCompanies=[];error_log('v230 message companies: '.$e->getMessage());}
    }

    $recipientUsers=[];
    if($allowedCompanyIdList){
        $ph=implode(',',array_fill(0,count($allowedCompanyIdList),'?'));
        try{
            $args=$allowedCompanyIdList;
            $sql="SELECT id,company_id,name,email,role FROM users WHERE status='active' AND company_id IN ($ph)";
            if(!$isMessageAdmin){$sql.=' AND id<>?';$args[]=$u['id'];}
            $sql.=' ORDER BY company_id,name';
            $st=$pdo->prepare($sql);$st->execute($args);$recipientUsers=$st->fetchAll();
        }catch(Throwable $e){$recipientUsers=[];error_log('v230 message users: '.$e->getMessage());}
    }

    $q=trim((string)($_GET['q']??''));
    $rows=[];
    try{
        $base="SELECT m.*,su.name sender_name,su.email sender_email,su.role sender_role,sc.name sender_company_name,ru.name receiver_name,ru.email receiver_email,ru.role receiver_role,rc.name receiver_company_name FROM messages m JOIN users su ON su.id=m.sender_id JOIN companies sc ON sc.id=m.company_id JOIN users ru ON ru.id=m.receiver_id JOIN companies rc ON rc.id=COALESCE(m.receiver_company_id,ru.company_id)";
        $where=[];$args=[];
        if($isMessageAdmin){
            $where[]='(m.company_id=? OR COALESCE(m.receiver_company_id,ru.company_id)=?)';$args[]=$cid;$args[]=$cid;
        }else{
            $where[]='(m.sender_id=? OR m.receiver_id=?)';$args[]=$u['id'];$args[]=$u['id'];
            $where[]='(m.company_id=? OR COALESCE(m.receiver_company_id,ru.company_id)=?)';$args[]=$cid;$args[]=$cid;
        }
        if($q!==''){
            $where[]='(su.name LIKE ? OR ru.name LIKE ? OR sc.name LIKE ? OR rc.name LIKE ? OR m.body LIKE ?)';
            $like='%'.$q.'%'; for($i=0;$i<5;$i++)$args[]=$like;
        }
        $sql=$base.' WHERE '.implode(' AND ',$where).' ORDER BY m.id DESC LIMIT 300';
        $st=$pdo->prepare($sql);$st->execute($args);$rows=$st->fetchAll();
    }catch(Throwable $e){$rows=[];error_log('v230 message rows: '.$e->getMessage());}

    // Build conversation summaries and threads. A thread is the same pair of users regardless of direction.
    $threads=[];$threadMessages=[];
    foreach($rows as $m){
        $a=(int)$m['sender_id'];$b=(int)$m['receiver_id'];$key=min($a,$b).'-'.max($a,$b);
        if(!isset($threadMessages[$key]))$threadMessages[$key]=[];
        $threadMessages[$key][]=$m;
        if(!isset($threads[$key])){
            $currentInSender=(int)$m['sender_id']===$u['id'];
            $partnerUser=$currentInSender?$m['receiver_name']:$m['sender_name'];
            $partnerCompany=$currentInSender?$m['receiver_company_name']:$m['sender_company_name'];
            $partnerCompanyId=$currentInSender?(int)($m['receiver_company_id']??0):(int)$m['company_id'];
            $threads[$key]=[
                'key'=>$key,'latest_id'=>(int)$m['id'],'created_at'=>$m['created_at'],'body'=>$m['body'],
                'partner_name'=>$partnerUser,'partner_company'=>$partnerCompany,'partner_company_id'=>$partnerCompanyId,
                'unread'=>0
            ];
        }
        if((int)$m['receiver_id']===$u['id'] && empty($m['read_at']))$threads[$key]['unread']++;
    }
    usort($threads,function($a,$b){return $b['latest_id']<=>$a['latest_id'];});

    $selectedThread=(string)($_GET['thread']??'');
    $selectedMessages=[];
    if($selectedThread!=='' && isset($threadMessages[$selectedThread])){
        $selectedMessages=array_reverse($threadMessages[$selectedThread]);
    }elseif($threads){
        $selectedThread=$threads[0]['key'];
        $selectedMessages=array_reverse($threadMessages[$selectedThread]);
    }
    $selectedPartnerUserId=0;
    $selectedPartnerCompanyId=0;
    if($selectedThread!=='' && preg_match('/^(\d+)-(\d+)$/',$selectedThread,$tm)){
        $aa=(int)$tm[1];$bb=(int)$tm[2];
        if($aa===$u['id'])$selectedPartnerUserId=$bb;elseif($bb===$u['id'])$selectedPartnerUserId=$aa;
        if($selectedPartnerUserId>0){
            foreach($recipientUsers as $ru){if((int)$ru['id']===$selectedPartnerUserId){$selectedPartnerCompanyId=(int)$ru['company_id'];break;}}
            if($selectedPartnerCompanyId===0 && $selectedMessages){
                $lm=$selectedMessages[count($selectedMessages)-1];
                $selectedPartnerCompanyId=((int)$lm['sender_id']===$u['id'])?(int)($lm['receiver_company_id']??0):(int)$lm['company_id'];
            }
        }
    }

    // JavaScript data for Company -> User dependent selector.
    $recipientUserJson=[];
    foreach($recipientUsers as $ru){$recipientUserJson[]=['id'=>(int)$ru['id'],'company_id'=>(int)$ru['company_id'],'name'=>(string)$ru['name'],'email'=>(string)$ru['email'],'role'=>(string)$ru['role']];}

    page_start('Messages');
    ?>
    <style>
      .sense-msg-layout{display:grid;grid-template-columns:310px minmax(0,1fr);gap:14px;align-items:stretch}
      .sense-msg-panel{background:#fff;border:1px solid #e4eaf0;border-radius:12px;overflow:hidden}
      .sense-msg-list{max-height:620px;overflow:auto}
      .sense-msg-thread{display:block;padding:12px 13px;border-bottom:1px solid #edf1f5;text-decoration:none;color:#243247}
      .sense-msg-thread:hover,.sense-msg-thread.active{background:#f7fbff}
      .sense-msg-thread-title{display:flex;justify-content:space-between;gap:8px;align-items:center;font-weight:700}
      .sense-msg-thread-sub{font-size:12px;color:#708096;margin-top:3px}
      .sense-msg-thread-last{font-size:12px;color:#56657a;margin-top:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .sense-msg-unread{display:inline-flex;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#0d8a54;color:#fff;align-items:center;justify-content:center;font-size:11px}
      .sense-msg-header{padding:14px 16px;border-bottom:1px solid #e8edf3}
      .sense-msg-company{font-size:12px;color:#718095;margin-top:2px}
      .sense-msg-body{padding:16px;min-height:430px;max-height:590px;overflow:auto;background:#f7f9fc}
      .sense-msg-bubble{max-width:76%;padding:10px 12px;border-radius:12px;margin:0 0 10px;box-shadow:0 1px 2px rgba(15,23,42,.06);background:#fff;border:1px solid #e4eaf0}
      .sense-msg-bubble.mine{margin-left:auto;background:#eaf4ff;border-color:#cfe5ff}
      .sense-msg-meta{font-size:11px;color:#718095;margin-bottom:4px}
      .sense-msg-text{white-space:pre-wrap;word-break:break-word;font-size:14px;line-height:1.45}
      .sense-msg-compose{padding:12px 14px;border-top:1px solid #e8edf3;background:#fff}
      .sense-msg-compose textarea{min-height:82px;resize:vertical}
      .sense-msg-empty{padding:40px 20px;text-align:center;color:#7a8799}
      @media(max-width:920px){.sense-msg-layout{grid-template-columns:1fr}.sense-msg-list{max-height:360px}.sense-msg-body{max-height:none}}
    </style>
    <div class="page-title">
      <div><h1>Messages</h1><p>Private messages between users and connected companies</p></div>
      <span class="status paid"><?=unread_messages_count((int)$u['id'])?> unread</span>
    </div>
    <div class="panel" style="margin-bottom:14px">
      <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input name="q" value="<?=e($q)?>" placeholder="Search messages, users or companies..." style="flex:1;min-width:240px">
        <button class="btn">Search</button>
        <?php if($q!==''): ?><a class="btn" href="<?=e(url('messages'))?>">Clear</a><?php endif; ?>
      </form>
    </div>
    <div class="sense-msg-layout">
      <div class="sense-msg-panel">
        <div class="panel-head" style="padding:14px 14px 10px"><h2 style="margin:0">Conversations</h2><span class="subtle"><?=count($threads)?></span></div>
        <div class="sense-msg-list">
          <?php foreach($threads as $t): ?>
            <a class="sense-msg-thread <?=($selectedThread===$t['key']?'active':'')?>" href="<?=e(url('messages?thread='.rawurlencode($t['key']).($q!==''?'&q='.rawurlencode($q):'')))?>">
              <div class="sense-msg-thread-title"><span><?=e($t['partner_name'])?></span><?php if($t['unread']>0): ?><span class="sense-msg-unread"><?=$t['unread']?></span><?php endif; ?></div>
              <div class="sense-msg-thread-sub"><?=e($t['partner_company'])?></div>
              <div class="sense-msg-thread-last"><?=e($t['body'])?></div>
            </a>
          <?php endforeach; ?>
          <?php if(!$threads): ?><div class="sense-msg-empty">No conversations yet.</div><?php endif; ?>
        </div>
      </div>

      <div class="sense-msg-panel">
        <?php if($selectedThread!==''): ?>
          <?php $activeThread=$threads?array_values(array_filter($threads,fn($x)=>$x['key']===$selectedThread)):[]; $activeThread=$activeThread[0]??null; ?>
          <div class="sense-msg-header">
            <div style="font-weight:700;font-size:16px"><?=e($activeThread['partner_name']??'Conversation')?></div>
            <div class="sense-msg-company"><?=e($activeThread['partner_company']??'')?></div>
          </div>
          <div class="sense-msg-body">
            <?php foreach($selectedMessages as $m): $mine=(int)$m['sender_id']===$u['id']; ?>
              <div class="sense-msg-bubble <?=$mine?'mine':''?>">
                <div class="sense-msg-meta"><?=e($m['sender_name'])?> · <?=e($m['sender_company_name'])?> · <?=e($m['created_at'])?> <?php if(!$m['read_at'] && (int)$m['receiver_id']===$u['id']): ?><a href="<?=e(url('messages?read='.(int)$m['id'].'&thread='.rawurlencode($selectedThread)))?>" style="margin-left:8px">Mark read</a><?php endif; ?></div>
                <div class="sense-msg-text"><?=e($m['body'])?></div>
              </div>
            <?php endforeach; ?>
            <?php if(!$selectedMessages): ?><div class="sense-msg-empty">No messages in this conversation.</div><?php endif; ?>
          </div>
        <?php else: ?>
          <div class="sense-msg-empty">Select a conversation to view messages.</div>
        <?php endif; ?>
        <div class="sense-msg-compose">
          <div class="panel-head" style="padding:0 0 10px"><h2 style="margin:0">New Message</h2><?php if($isMessageAdmin): ?><span class="subtle">Admin view: your company's users' conversations are visible.</span><?php endif; ?></div>
          <form method="post" id="senseMessageComposeForm">
            <input type="hidden" name="_csrf" value="<?=csrf_token()?>">
            <div class="grid2">
              <div class="form-group">
                <label>Company</label>
                <select id="senseMessageCompany" name="receiver_company_id" required>
                  <option value="">Select company</option>
                  <?php foreach($allowedCompanies as $co): ?>
                    <option value="<?=$co['id']?>" <?=((int)$selectedPartnerCompanyId===(int)$co['id']?'selected':'')?>><?=e($co['name'])?><?=((int)$co['id']===$cid?' · Your company':'')?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>User</label>
                <select id="senseMessageUser" name="receiver_id" required><option value="">Select user</option></select>
              </div>
            </div>
            <div class="form-group"><label>Message</label><textarea name="body" rows="4" maxlength="5000" required placeholder="Write a message..."></textarea></div>
            <button class="btn primary">Send</button>
          </form>
        </div>
      </div>
    </div>
    <script>
      (function(){
        const users=<?=json_encode($recipientUserJson,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
        const company=document.getElementById('senseMessageCompany');
        const user=document.getElementById('senseMessageUser');
        const selectedUser=<?=json_encode($selectedPartnerUserId)?>;
        function fillUsers(){
          if(!company||!user)return;
          const cid=Number(company.value||0); user.innerHTML='<option value="">Select user</option>';
          users.filter(x=>Number(x.company_id)===cid && Number(x.id)!==<?=json_encode((int)$u['id'])?>).forEach(x=>{
            const o=document.createElement('option'); o.value=x.id; o.textContent=x.name;
            if(Number(selectedUser)===Number(x.id))o.selected=true; user.appendChild(o);
          });
        }
        if(company){company.addEventListener('change',function(){fillUsers();});fillUsers();}
      })();
    </script>
    <?php page_end();exit;
}


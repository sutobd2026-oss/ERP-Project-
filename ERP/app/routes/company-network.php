<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='company-network'){

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
            if($action==='subscription_cancel'){
                $id=(int)($_POST['subscription_id']??0);
                $st=$pdo->prepare('SELECT * FROM company_subscriptions WHERE id=? AND subscriber_company_id=? LIMIT 1');$st->execute([$id,$cid]);$row=$st->fetch();if(!$row)throw new RuntimeException('Subscription not found.');
                if(!in_array($row['status'],['pending','approved'],true))throw new RuntimeException('This subscription cannot be cancelled.');
                $pdo->prepare('UPDATE company_subscriptions SET status="cancelled",updated_at=NOW() WHERE id=?')->execute([$id]);
                saas_notify_company((int)$row['target_company_id'],'subscription','Company subscription cancelled',$u['company_name'].' cancelled the company subscription.',url('company-network'));
                flash('success','Subscription cancelled.'); redirect('company-network');
            }
            if($action==='subscription_remove'){
                $id=(int)($_POST['subscription_id']??0);
                $st=$pdo->prepare('SELECT * FROM company_subscriptions WHERE id=? AND target_company_id=? LIMIT 1');$st->execute([$id,$cid]);$row=$st->fetch();if(!$row)throw new RuntimeException('Subscription not found.');
                if($row['status']!=='approved')throw new RuntimeException('Only approved subscriptions can be removed.');
                $pdo->prepare('UPDATE company_subscriptions SET status="cancelled",updated_at=NOW() WHERE id=?')->execute([$id]);
                saas_notify_company((int)$row['subscriber_company_id'],'subscription','Subscription removed',$u['company_name'].' removed your subscription from their company.',url('company-network'));
                flash('success','Subscriber removed.'); redirect('company-network');
            }
            if($action==='publish_update'){
                $type=$_POST['update_type']??'text';$title=trim($_POST['title']??'');$body=trim($_POST['body']??'');
                $allowed=['text','product','offer','notice','announcement'];if(!in_array($type,$allowed,true)||$title==='')throw new RuntimeException('Update type and title are required.');
                $pdo->prepare('INSERT INTO company_updates(company_id,user_id,update_type,title,body,status) VALUES(?,?,?,?,?,"published")')->execute([$cid,$u['id'],$type,$title,$body]);
                $uid=(int)$pdo->lastInsertId(); foreach(saas_approved_subscriber_company_ids($cid) as $sub){ saas_notify_company($sub,'company_update',$u['company_name'].' · '.saas_logotype($type),$title,url('company-network?update='.$uid)); }
                flash('success','Update published and subscribers notified.'); redirect('company-network');
            }
            if($action==='update_edit'){
                $id=(int)($_POST['update_id']??0);$type=$_POST['update_type']??'text';$title=trim($_POST['title']??'');$body=trim($_POST['body']??'');$productId=(int)($_POST['product_id']??0);
                $allowed=['text','product','offer','notice','announcement'];
                if($id<=0||!in_array($type,$allowed,true)||$title==='')throw new RuntimeException('Update type and title are required.');
                $st=$pdo->prepare('SELECT id,status FROM company_updates WHERE id=? AND company_id=? LIMIT 1');$st->execute([$id,$cid]);$row=$st->fetch();if(!$row)throw new RuntimeException('Update not found.');
                if($row['status']!=='published')throw new RuntimeException('This update cannot be edited.');
                if($productId>0){$st=$pdo->prepare('SELECT id FROM items WHERE id=? AND company_id=? AND deleted_at IS NULL LIMIT 1');$st->execute([$productId,$cid]);if(!$st->fetchColumn())$productId=0;}
                $pdo->prepare('UPDATE company_updates SET update_type=?,title=?,body=?,product_id=?,updated_at=NOW() WHERE id=? AND company_id=?')->execute([$type,$title,$body,$productId?:null,$id,$cid]);
                foreach(saas_approved_subscriber_company_ids($cid) as $sub){ saas_notify_company($sub,'company_update','Company update edited',$u['company_name'].' updated: '.$title,url('company-network?update='.$id)); }
                flash('success','Update updated and subscribers notified.'); redirect('company-network');
            }
            if($action==='update_delete'){
                $id=(int)($_POST['update_id']??0);$st=$pdo->prepare('SELECT id,status,title FROM company_updates WHERE id=? AND company_id=? LIMIT 1');$st->execute([$id,$cid]);$row=$st->fetch();if(!$row)throw new RuntimeException('Update not found.');
                if($row['status']==='deleted')throw new RuntimeException('Update already deleted.');
                $pdo->prepare('UPDATE company_updates SET status="deleted",updated_at=NOW() WHERE id=? AND company_id=?')->execute([$id,$cid]);
                foreach(saas_approved_subscriber_company_ids($cid) as $sub){ saas_notify_company($sub,'company_update','Company update removed',$u['company_name'].' removed the update: '.$row['title'],url('company-network')); }
                flash('success','Update removed and subscribers notified.'); redirect('company-network');
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
                $partyId=(int)($_POST['party_id']??0);$rating=(int)($_POST['rating']??0);$comment=trim($_POST['comment']??'');
                if($partyId<=0||$rating<1||$rating>5||$comment==='')throw new RuntimeException('Rating and review comment are required.');
                $st=$pdo->prepare('SELECT p.id,p.phone FROM parties p WHERE p.id=? AND p.company_id=? AND p.deleted_at IS NULL LIMIT 1');$st->execute([$partyId,$cid]);$party=$st->fetch();if(!$party)throw new RuntimeException('Customer not found.');
                $st=$pdo->prepare('SELECT 1 FROM party_roles WHERE party_id=? AND role="customer" LIMIT 1');$st->execute([$partyId]);if(!$st->fetchColumn())throw new RuntimeException('Reviews can only be given to Customers.');
                $verified=verified_customer_transaction($pdo,$cid,$partyId);if(!$verified)throw new RuntimeException('A verified transaction is required before giving a public review.');
                $phone=preg_replace('/\D+/','',(string)$party['phone']);if($phone==='')throw new RuntimeException('Customer phone number is required for public reviews.');
                $st=$pdo->prepare('SELECT id FROM company_reviews WHERE reviewer_company_id=? AND customer_phone=? AND status<>"deleted" LIMIT 1');$st->execute([$cid,$phone]);if($st->fetchColumn())throw new RuntimeException('Your company has already reviewed this customer.');
                $pdo->prepare('INSERT INTO company_reviews(reviewer_company_id,subject_company_id,party_id,customer_phone,rating,comment,status,created_by,verified_transaction_type,verified_transaction_id) VALUES(?,NULL,?,?,?,? ,"published",?,?,?)')->execute([$cid,$partyId,$phone,$rating,$comment,$u['id'],$verified['type'],$verified['id']]);
                flash('success','Customer public review submitted.'); redirect('company-network');
            }
        }catch(Throwable $e){flash('error',$e->getMessage());redirect('company-network');}
    }
    if(isset($_GET['mark_update_read'])){
        $rid=(int)$_GET['mark_update_read'];
        try{
            $pdo->prepare('INSERT INTO company_update_reads(update_id,company_id,user_id,read_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE read_at=NOW()')->execute([$rid,$cid,$u['id']]);
        }catch(Throwable $e){ error_log('company-network mark-read: '.$e->getMessage()); }
        redirect('company-network');
    }

    // v201: Company Network route hardening.
    // Optional / newly-added SaaS tables must never make the whole ERP page return HTTP 500.
    $search=trim($_GET['q']??'');
    try{$companyResults=saas_company_search($cid,$search);}catch(Throwable $e){$companyResults=[];error_log('company-network search: '.$e->getMessage());}
    $viewCompanyId=(int)($_GET['view_company']??0);
    $viewCompany=null;
    $viewCompanyReviews=[];
    $viewCompanyLinked=false;

    if($viewCompanyId>0&&$viewCompanyId!==$cid){
        try{
            $st=$pdo->prepare('SELECT id,name,phone,address,business_type,logo_path FROM companies WHERE id=? LIMIT 1');
            $st->execute([$viewCompanyId]);
            $viewCompany=$st->fetch()?:null;
            if($viewCompany){
                try{
                    $st=$pdo->prepare('SELECT 1 FROM party_company_links WHERE company_id=? AND linked_company_id=? AND relation_type="customer" LIMIT 1');
                    $st->execute([$cid,$viewCompanyId]);
                    $viewCompanyLinked=(bool)$st->fetchColumn();
                }catch(Throwable $e){$viewCompanyLinked=false;error_log('company-network view link: '.$e->getMessage());}
                if($viewCompanyLinked){
                    try{
                        $st=$pdo->prepare('SELECT cr.*,c.name reviewer_name,p.name party_name FROM company_reviews cr JOIN companies c ON c.id=cr.reviewer_company_id JOIN parties p ON p.id=cr.party_id WHERE cr.subject_company_id=? AND cr.status="published" ORDER BY cr.id DESC LIMIT 30');
                        $st->execute([$viewCompanyId]);
                        $viewCompanyReviews=$st->fetchAll();
                    }catch(Throwable $e){$viewCompanyReviews=[];error_log('company-network view reviews: '.$e->getMessage());}
                }
            }
        }catch(Throwable $e){$viewCompany=null;$viewCompanyReviews=[];$viewCompanyLinked=false;error_log('company-network view company: '.$e->getMessage());}
    }

    try{$subs=saas_subscriptions_for($cid);}catch(Throwable $e){$subs=[];error_log('company-network subscriptions: '.$e->getMessage());}
    try{$incoming=saas_incoming_requests($cid);}catch(Throwable $e){$incoming=[];error_log('company-network incoming: '.$e->getMessage());}

    $subscribers=[];
    try{
        $st=$pdo->prepare("SELECT cs.*,c.name subscriber_name,c.logo_path subscriber_logo FROM company_subscriptions cs JOIN companies c ON c.id=cs.subscriber_company_id WHERE cs.target_company_id=? AND cs.status='approved' ORDER BY cs.id DESC");
        $st->execute([$cid]);$subscribers=$st->fetchAll();
    }catch(Throwable $e){$subscribers=[];error_log('company-network subscribers: '.$e->getMessage());}

    $editUpdate=null;
    if(isset($_GET['edit_update'])){
        try{
            $eid=(int)$_GET['edit_update'];
            $st=$pdo->prepare('SELECT * FROM company_updates WHERE id=? AND company_id=? AND status="published" LIMIT 1');
            $st->execute([$eid,$cid]);$editUpdate=$st->fetch()?:null;
        }catch(Throwable $e){$editUpdate=null;error_log('company-network edit update: '.$e->getMessage());}
    }

    try{
        $itemsForUpdates=$pdo->prepare('SELECT id,name FROM items WHERE company_id=? AND deleted_at IS NULL ORDER BY name LIMIT 500');
        $itemsForUpdates->execute([$cid]);$itemsForUpdates=$itemsForUpdates->fetchAll();
    }catch(Throwable $e){$itemsForUpdates=[];error_log('company-network items: '.$e->getMessage());}

    $updateTypeFilter=trim($_GET['update_type']??'');
    $updateCompanyFilter=(int)($_GET['update_company']??0);
    $validUpdateTypes=['','text','product','offer','notice','announcement'];
    if(!in_array($updateTypeFilter,$validUpdateTypes,true))$updateTypeFilter='';

    $allowedCompanyFilterIds=[$cid];
    if($updateCompanyFilter>0){
        try{
            $allowedCompanyFilterIds=array_map('intval',$pdo->query("SELECT target_company_id FROM company_subscriptions WHERE subscriber_company_id=".(int)$cid." AND status='approved'")->fetchAll(PDO::FETCH_COLUMN));
            $allowedCompanyFilterIds[]=$cid;
            $allowedCompanyFilterIds=array_values(array_unique($allowedCompanyFilterIds));
            if(!in_array($updateCompanyFilter,$allowedCompanyFilterIds,true))$updateCompanyFilter=0;
        }catch(Throwable $e){$updateCompanyFilter=0;error_log('company-network company filter: '.$e->getMessage());}
    }

    $updates=[];
    try{
        $sql="SELECT cu.*,c.name company_name,c.logo_path FROM company_updates cu JOIN companies c ON c.id=cu.company_id WHERE cu.status='published' AND (cu.company_id=? OR EXISTS(SELECT 1 FROM company_subscriptions cs WHERE cs.target_company_id=cu.company_id AND cs.subscriber_company_id=? AND cs.status='approved'))";
        $args=[$cid,$cid];
        if($updateTypeFilter!==''){ $sql.=" AND cu.update_type=?"; $args[]=$updateTypeFilter; }
        if($updateCompanyFilter>0){ $sql.=" AND cu.company_id=?"; $args[]=$updateCompanyFilter; }
        $sql.=" ORDER BY cu.id DESC LIMIT 50";
        $updates=$pdo->prepare($sql);$updates->execute($args);$updates=$updates->fetchAll();
    }catch(Throwable $e){$updates=[];error_log('company-network updates: '.$e->getMessage());}

    $networkFeedCompanies=[];
    try{
        $st=$pdo->prepare("SELECT id,name,logo_path FROM companies WHERE id=? UNION ALL SELECT c.id,c.name,c.logo_path FROM company_subscriptions cs JOIN companies c ON c.id=cs.target_company_id WHERE cs.subscriber_company_id=? AND cs.status='approved' ORDER BY name");
        $st->execute([$cid,$cid]);$networkFeedCompanies=$st->fetchAll();
    }catch(Throwable $e){$networkFeedCompanies=[];error_log('company-network feed companies: '.$e->getMessage());}

    $readMap=[];
    if($updates){
        try{
            $ids=array_map(fn($x)=>(int)$x['id'],$updates);
            $ph=implode(',',array_fill(0,count($ids),'?'));
            $args=array_merge([$u['id']],$ids);
            $rr=$pdo->prepare("SELECT update_id FROM company_update_reads WHERE user_id=? AND update_id IN ($ph)");
            $rr->execute($args);
            foreach($rr->fetchAll(PDO::FETCH_COLUMN) as $rid){$readMap[(int)$rid]=true;}
        }catch(Throwable $e){$readMap=[];error_log('company-network read map: '.$e->getMessage());}
    }

    $customers=[];
    try{
        $customers=$pdo->prepare("SELECT p.id,p.name,p.phone,pcl.linked_company_id,c.name linked_company_name FROM parties p LEFT JOIN party_company_links pcl ON pcl.party_id=p.id AND pcl.company_id=p.company_id AND pcl.relation_type='customer' LEFT JOIN companies c ON c.id=pcl.linked_company_id WHERE p.company_id=? AND p.deleted_at IS NULL AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role='customer') ORDER BY p.name");
        $customers->execute([$cid]);$customers=$customers->fetchAll();
    }catch(Throwable $e){$customers=[];error_log('company-network customers: '.$e->getMessage());}

    $receivedReviews=[];
    try{
        $receivedReviews=$pdo->prepare("SELECT cr.*,c.name reviewer_name,p.name party_name FROM company_reviews cr JOIN companies c ON c.id=cr.reviewer_company_id JOIN parties p ON p.id=cr.party_id WHERE cr.subject_company_id=? AND cr.status='published' ORDER BY cr.id DESC LIMIT 20");
        $receivedReviews->execute([$cid]);$receivedReviews=$receivedReviews->fetchAll();
    }catch(Throwable $e){$receivedReviews=[];error_log('company-network received reviews: '.$e->getMessage());}

    page_start('Company Network');
    ?>
    <div class="page-title"><div><h1>Company Network</h1><p>Internal company subscriptions, updates, notifications and customer reviews.</p></div></div>
    <div class="grid2">
      <div class="panel"><div class="panel-head"><h2>Find Company</h2></div><form method="get" class="search-inline"><input name="q" value="<?=e($search)?>" placeholder="Search company name"><button class="btn primary">Search</button></form>
      <?php if($search!==''):?><div class="settings-list" style="margin-top:12px"><?php foreach($companyResults as $cr):?><div class="settings-list-row"><div style="display:flex;gap:10px;align-items:center"><?php if(!empty($cr['logo_path'])):?><img src="<?=e(url($cr['logo_path']))?>" alt="" style="width:34px;height:34px;border-radius:8px;object-fit:cover;border:1px solid #e8edf3" onerror="this.style.display='none'"> <?php endif;?><div><b><?=e($cr['name'])?></b><small><?=e($cr['business_type']??'')?> <?=e($cr['phone']??'')?></small></div></div><div style="display:flex;gap:6px"><a class="btn small-btn" href="<?=e(url('company-network?view_company='.(int)$cr['id']))?>">View</a><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="subscribe"><input type="hidden" name="target_company_id" value="<?=$cr['id']?>"><button class="btn small-btn">Subscribe</button></form></div></div><?php endforeach;if(!$companyResults):?><p class="subtle">No companies found.</p><?php endif;?></div><?php endif;?></div>
      <div class="panel"><div class="panel-head"><h2>Publish Update</h2></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="publish_update"><div class="form-group"><label>Type</label><select name="update_type"><option value="text">Text Post</option><option value="product">Product</option><option value="offer">Offer</option><option value="notice">Notice</option><option value="announcement">Announcement</option></select></div><div class="form-group"><label>Title</label><input name="title" required></div><div class="form-group"><label>Message</label><textarea name="body" rows="5"></textarea></div><button class="btn primary">Publish Update</button></form></div>
    </div>
    <?php if($viewCompany): ?><div class="panel" style="margin-top:14px"><div class="panel-head"><h2><?=e($viewCompany['name'])?></h2><a class="btn" href="<?=e(url('company-network'))?>">Close</a></div><div class="grid2"><div><p><b>Phone:</b> <?=e($viewCompany['phone']??'—')?></p><p><b>Business:</b> <?=e($viewCompany['business_type']??'—')?></p><p><b>Relationship:</b> <?= $viewCompanyLinked?'<span class="status paid">Customer relationship found</span>':'<span class="status open">Not linked as customer</span>' ?></p></div><div><h3>Customer Reviews</h3><?php if($viewCompanyLinked&&$viewCompanyReviews): foreach($viewCompanyReviews as $rv): ?><div style="padding:10px 0;border-bottom:1px solid #edf1f5"><b><?=str_repeat('★',(int)$rv['rating']).str_repeat('☆',5-(int)$rv['rating'])?></b><div><?=nl2br(e($rv['comment']))?></div><small class="subtle">From <?=e($rv['reviewer_name'])?> · <?=e(date('d/m/Y',strtotime($rv['created_at'])))?></small></div><?php endforeach; elseif($viewCompanyLinked): ?><p class="subtle">No reviews yet.</p><?php else: ?><p class="subtle">Reviews are visible after this company is added as a Customer.</p><?php endif; ?></div></div></div><?php endif; ?>
    <div class="grid3" style="margin-top:14px"><div class="panel"><div class="panel-head"><h2>Subscription Requests</h2></div><?php foreach($incoming as $rq):?><div class="settings-list-row"><div><b><?=e($rq['subscriber_name'])?></b><small>Wants to subscribe</small></div><div style="display:flex;gap:6px"><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="subscription_decision"><input type="hidden" name="subscription_id" value="<?=$rq['id']?>"><input type="hidden" name="decision" value="approve"><button class="btn small-btn">Approve</button></form><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="subscription_decision"><input type="hidden" name="subscription_id" value="<?=$rq['id']?>"><input type="hidden" name="decision" value="reject"><button class="btn small-btn">Reject</button></form></div></div><?php endforeach;if(!$incoming):?><p class="subtle">No pending requests.</p><?php endif;?></div>
      <div class="panel"><div class="panel-head"><h2>My Subscriptions</h2></div><?php foreach($subs as $sr):?><div class="settings-list-row"><div><b><?=e($sr['target_name'])?></b><small><?=e(ucfirst($sr['status']))?></small></div><?php if(in_array($sr['status'],['pending','approved'],true)):?><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="subscription_cancel"><input type="hidden" name="subscription_id" value="<?=$sr['id']?>"><button class="btn small-btn">Cancel</button></form><?php endif;?></div><?php endforeach;if(!$subs):?><p class="subtle">No subscriptions yet.</p><?php endif;?></div>
      <div class="panel"><div class="panel-head"><h2>My Subscribers</h2><span class="subtle"><?=count($subscribers)?></span></div><?php foreach($subscribers as $sr):?><div class="settings-list-row"><div><b><?=e($sr['subscriber_name'])?></b><small>Approved subscriber</small></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="subscription_remove"><input type="hidden" name="subscription_id" value="<?=$sr['id']?>"><button class="btn small-btn">Remove</button></form></div><?php endforeach;if(!$subscribers):?><p class="subtle">No approved subscribers yet.</p><?php endif;?></div>
      <div class="panel" id="relationships"><div class="panel-head"><h2>Customer Reviews</h2></div><p class="subtle">Customer public reviews no longer require Company Network linking. A review is tied to the customer phone number and requires a verified transaction.</p></div>
    <?php if($editUpdate): ?><div class="panel" style="margin-top:14px" id="edit-update"><div class="panel-head"><h2>Edit Company Update</h2><a class="btn" href="<?=e(url('company-network'))?>">Cancel</a></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="update_edit"><input type="hidden" name="update_id" value="<?=$editUpdate['id']?>"><div class="grid2"><div class="form-group"><label>Type</label><select name="update_type"><option value="text" <?=($editUpdate['update_type']==='text'?'selected':'')?>>Text Post</option><option value="product" <?=($editUpdate['update_type']==='product'?'selected':'')?>>Product</option><option value="offer" <?=($editUpdate['update_type']==='offer'?'selected':'')?>>Offer</option><option value="notice" <?=($editUpdate['update_type']==='notice'?'selected':'')?>>Notice</option><option value="announcement" <?=($editUpdate['update_type']==='announcement'?'selected':'')?>>Announcement</option></select></div><div class="form-group"><label>Product (optional)</label><select name="product_id"><option value="0">None</option><?php foreach($itemsForUpdates as $it): ?><option value="<?=$it['id']?>" <?=((int)($editUpdate['product_id']??0)===(int)$it['id']?'selected':'')?>><?=e($it['name'])?></option><?php endforeach; ?></select></div></div><div class="form-group"><label>Title</label><input name="title" required maxlength="191" value="<?=e($editUpdate['title'])?>"></div><div class="form-group"><label>Message</label><textarea name="body" rows="5"><?=e($editUpdate['body']??'')?></textarea></div><button class="btn primary">Save Changes</button></form></div><?php endif; ?>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Updates</h2><form method="get" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><select name="update_type" style="min-width:140px"><option value="">All Types</option><option value="text" <?= $updateTypeFilter==='text'?'selected':'' ?>>Text Post</option><option value="product" <?= $updateTypeFilter==='product'?'selected':'' ?>>Product</option><option value="offer" <?= $updateTypeFilter==='offer'?'selected':'' ?>>Offer</option><option value="notice" <?= $updateTypeFilter==='notice'?'selected':'' ?>>Notice</option><option value="announcement" <?= $updateTypeFilter==='announcement'?'selected':'' ?>>Announcement</option></select><select name="update_company" style="min-width:170px"><option value="0">All Companies</option><?php foreach($networkFeedCompanies as $nfc): ?><option value="<?=$nfc['id']?>" <?=((int)$updateCompanyFilter===(int)$nfc['id'])?'selected':''?>><?=e($nfc['name'])?></option><?php endforeach; ?></select><button class="btn small-btn">Filter</button><?php if($updateTypeFilter!==''||$updateCompanyFilter>0): ?><a class="btn small-btn" href="<?=e(url('company-network'))?>">Clear</a><?php endif; ?></form></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>COMPANY</th><th>TYPE</th><th>TITLE</th><th>MESSAGE</th><th></th></tr></thead><tbody><?php foreach($updates as $up):?><tr><td><?=e(date('d/m/Y h:i A',strtotime($up['created_at'])))?></td><td><div style="display:flex;align-items:center;gap:8px"><?php if(!empty($up['logo_path'])):?><img src="<?=e(url($up['logo_path']))?>" alt="" style="width:28px;height:28px;border-radius:7px;object-fit:cover;border:1px solid #e8edf3" onerror="this.style.display='none'"> <?php endif;?><span><?=e($up['company_name'])?></span></div></td><td><?=e(saas_logotype($up['update_type']))?></td><td><b><?=e($up['title'])?></b><?php if((int)$up['company_id']!==$cid && empty($readMap[(int)$up['id']])): ?><span class="status open" style="margin-left:6px">New</span><?php endif; ?></td><td style="max-width:420px;white-space:normal"><?=nl2br(e($up['body']??''))?><?php if((int)$up['company_id']!==$cid && empty($readMap[(int)$up['id']])): ?><div style="margin-top:6px"><a class="btn small-btn" href="<?=e(url('company-network?mark_update_read='.(int)$up['id']))?>">Mark read</a></div><?php endif; ?></td><td><?php if((int)$up['company_id']===$cid): ?><details><summary style="cursor:pointer;list-style:none;font-size:20px">⋮</summary><div style="position:absolute;background:#fff;border:1px solid #e8edf3;border-radius:10px;padding:6px;min-width:140px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:5"><a class="btn small-btn" style="display:block;margin:2px 0" href="<?=e(url('company-network?edit_update='.(int)$up['id']))?>">Edit</a><form method="post" onsubmit="return confirm('Delete this update?');" style="margin:0"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="network_action" value="update_delete"><input type="hidden" name="update_id" value="<?=$up['id']?>"><button class="btn small-btn" style="width:100%;margin:2px 0">Delete</button></form></div></details><?php endif; ?></td></tr><?php endforeach;if(!$updates):?><tr><td colspan="6" class="subtle">No updates match the selected filters.</td></tr><?php endif;?></tbody></table></div></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>Customer Public Reviews</h2><span class="subtle">Open a Customer from Parties to view reviews</span></div><p class="subtle">All published customer reviews are shared by customer phone number across companies. Customer approval and Company Network linking are not required.</p></div>
    <?php page_end(); exit;
}



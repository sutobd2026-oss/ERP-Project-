<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
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
        if($action==='add_note'){
            $partyId=(int)($_POST['id']??0); $note=trim((string)($_POST['note']??''));
            if($partyId<=0 || $note===''){ flash('error','Party and note are required.'); redirect('parties'); }
            $chk=db()->prepare('SELECT id FROM parties WHERE id=? AND company_id=? AND deleted_at IS NULL LIMIT 1'); $chk->execute([$partyId,$cid]);
            if(!$chk->fetchColumn()){ flash('error','Party not found.'); redirect('parties'); }
            db()->prepare('INSERT INTO party_notes(company_id,party_id,user_id,note) VALUES(?,?,?,?)')->execute([$cid,$partyId,$u['id'],$note]);
            audit('create','party_note',$partyId,['note'=>$note]); flash('success','Private note added.'); redirect('parties?id='.$partyId);
        }
        if($action==='review_customer'){
            $partyId=(int)($_POST['party_id']??0); $rating=(int)($_POST['rating']??0); $comment=trim((string)($_POST['comment']??''));
            if($partyId<=0 || $rating<1 || $rating>5 || $comment===''){ flash('error','Customer review details are incomplete.'); redirect('parties?id='.$partyId); }
            $chk=db();
            $st=$chk->prepare('SELECT id,phone FROM parties WHERE id=? AND company_id=? AND deleted_at IS NULL LIMIT 1'); $st->execute([$partyId,$cid]); $partyForReview=$st->fetch();
            if(!$partyForReview){ flash('error','Customer not found.'); redirect('parties?id='.$partyId); }
            $st=$chk->prepare('SELECT 1 FROM party_roles pr WHERE pr.party_id=? AND pr.role="customer" LIMIT 1'); $st->execute([$partyId]);
            if(!$st->fetchColumn()){ flash('error','Reviews can only be given to Customers.'); redirect('parties?id='.$partyId); }
            $verified=verified_customer_transaction($chk,$cid,$partyId);
            if(!$verified){ flash('error','A verified transaction is required before giving a public review.'); redirect('parties?id='.$partyId); }
            $phone=(string)preg_replace('/\D+/','',(string)($partyForReview['phone']??''));
            if($phone===''){ flash('error','Customer phone number is required for public reviews.'); redirect('parties?id='.$partyId); }
            $st=$chk->prepare('SELECT id FROM company_reviews WHERE reviewer_company_id=? AND customer_phone=? AND status<>"deleted" LIMIT 1'); $st->execute([$cid,$phone]);
            if($st->fetchColumn()){ flash('error','Your company has already reviewed this customer.'); redirect('parties?id='.$partyId); }
            $chk->prepare('INSERT INTO company_reviews(reviewer_company_id,subject_company_id,party_id,customer_phone,rating,comment,status,created_by,verified_transaction_type,verified_transaction_id) VALUES(?,NULL,?,?,?,? ,"published",?,?,?)')->execute([$cid,$partyId,$phone,$rating,$comment,$u['id'],$verified['type'],$verified['id']]);
            audit('create','company_review',$partyId,['customer_phone'=>$phone,'rating'=>$rating,'verified_transaction_type'=>$verified['type'],'verified_transaction_id'=>$verified['id']]);
            flash('success','Customer public review submitted.'); redirect('parties?id='.$partyId);
        }
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
    $editRequested=(int)($_GET['edit']??0);
    if($editRequested>0)$selectedId=$editRequested;
    $txq=trim($_GET['txq']??'');
    /* v204: keep the core Parties query independent from optional Notes/Review tables.
       Older installations may have older SaaS schemas; a missing optional table/column
       must never blank the entire Parties page. Counts are hydrated separately below. */
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
    foreach($rows as &$r){
        $r['roles']=$r['role_list']!==''?array_map('trim',explode(',',$r['role_list'])):[];
        $r['note_count']=0;
        $r['public_review_count']=0;
    }unset($r);

    /* Hydrate optional status counts independently. Any failure is contained. */
    if($rows){
        try{
            $nsql='SELECT party_id,COUNT(*) cnt FROM party_notes WHERE company_id=? AND party_id IN ('.implode(',',array_fill(0,count($rows),'?')).') GROUP BY party_id';
            $np=[$cid]; foreach($rows as $rr)$np[]=(int)$rr['id'];
            $nst=db()->prepare($nsql); $nst->execute($np);
            $noteMap=[]; while($nr=$nst->fetch())$noteMap[(int)$nr['party_id']]=(int)$nr['cnt'];
            foreach($rows as &$rr)$rr['note_count']=$noteMap[(int)$rr['id']]??0; unset($rr);
        }catch(Throwable $e){ error_log('v204 party note counts: '.$e->getMessage()); }

        try{
            $phoneMap=[]; $phones=[];
            foreach($rows as $rr){$ph=(string)preg_replace('/\D+/','',(string)($rr['phone']??'')); if($ph!=='')$phones[$ph]=true;}
            if($phones){
                $phList=array_keys($phones);
                $rSql='SELECT customer_phone,COUNT(*) cnt FROM company_reviews WHERE status="published" AND customer_phone IS NOT NULL AND customer_phone<>"" AND customer_phone IN ('.implode(',',array_fill(0,count($phList),'?')).') GROUP BY customer_phone';
                $rst=db()->prepare($rSql); $rst->execute($phList);
                while($rv=$rst->fetch()){$phoneMap[(string)$rv['customer_phone']]=(int)$rv['cnt'];}
                foreach($rows as &$rr){$ph=(string)preg_replace('/\D+/','',(string)($rr['phone']??'')); $rr['public_review_count']=$ph!==''?($phoneMap[$ph]??0):0;} unset($rr);
            }
        }catch(Throwable $e){ error_log('v204 party review counts: '.$e->getMessage()); }
    }
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
    $partyNotes=[]; $partyReviews=[]; $partyReviewLoadError=''; $selectedReviewPhone=''; $selectedVerifiedTransaction=null; $selectedHasPublicReview=false;
    if($selected){
        try{
            $ns=db()->prepare('SELECT pn.*,u.name user_name FROM party_notes pn LEFT JOIN users u ON u.id=pn.user_id WHERE pn.company_id=? AND pn.party_id=? ORDER BY pn.id DESC LIMIT 50');
            $ns->execute([$cid,$selectedId]); $partyNotes=$ns->fetchAll();
        }catch(Throwable $e){}
        if(in_array('customer',$selected['roles']??[],true)){
            try{
                $selectedReviewPhone=(string)preg_replace('/\D+/','',(string)($selected['phone']??''));
                if($selectedReviewPhone!==''){
                    $rs=db()->prepare('SELECT cr.*,c.name reviewer_name FROM company_reviews cr JOIN companies c ON c.id=cr.reviewer_company_id WHERE cr.customer_phone=? AND cr.status="published" ORDER BY cr.id DESC LIMIT 50');
                    $rs->execute([$selectedReviewPhone]); $partyReviews=$rs->fetchAll();
                    foreach($partyReviews as $rv){ if((int)$rv['reviewer_company_id']===$cid){$selectedHasPublicReview=true;break;} }
                }
                $selectedVerifiedTransaction=verified_customer_transaction(db(),$cid,$selectedId);
            }catch(Throwable $e){ $partyReviewLoadError='Public review data could not be loaded.'; }
        }
    }
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
                <span class="party-main-v110"><span class="party-name-row-v203"><b><?=e($r['name'])?></b><span class="party-status-pills-v203"><?php if((int)($r['note_count']??0)>0): ?><span class="party-status-pill-v203 note"><?=((int)$r['note_count'])?> Note<?=((int)$r['note_count'])===1?'':'s'?></span><?php endif; ?><?php if(in_array('customer',$r['roles']??[],true)): ?><?php $rc=(int)($r['public_review_count']??0); if($rc>0): ?><span class="party-status-pill-v203 review">★ <?=$rc?> Review<?=($rc===1?'':'s')?></span><?php endif; ?><?php endif; ?></span></span><small><?=e($r['phone'])?></small></span>
                <span class="party-amount-v110 <?=$rb<0?'negative':''?>"><?=money(abs($rb))?></span>
              </a>
              <button type="button" class="party-dots-v110" aria-label="Party actions" onclick="event.preventDefault();event.stopPropagation();const m=this.nextElementSibling;document.querySelectorAll('.party-row-menu-v110.show').forEach(x=>{if(x!==m)x.classList.remove('show')});m.classList.toggle('show')">⋮</button>
              <div class="party-row-menu-v110" onclick="event.stopPropagation();">
                <button type="button" class="party-edit-link-v110" onclick='event.preventDefault();event.stopPropagation();editParty(<?=json_encode($r,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>);this.parentElement.classList.remove("show")'>View / Edit</button>
                <a href="<?=e(url('party-ledger?id='.(int)$r['id']))?>">Ledger</a>
                <form method="post" onsubmit="return confirm('Move this party to Recycle Bin?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="party_action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button type="submit">Delete</button></form>
              </div>
            </div>
          <?php endforeach; if(!$rows): ?><div class="party-empty-v110">No parties found.</div><?php endif; ?>
        </div>
      </aside>
      <section class="party-detail-v110">
        <?php if($selected): ?>
          <div class="party-detail-card-v110 party-detail-card-v202">
            <div class="party-detail-top-v110 party-detail-top-v202">
              <div>
                <div class="party-heading-v204">
                  <h2>@ <?=e($selected['name'])?></h2>
                  <?php $partyPhoneRaw=(string)($selected['phone']??''); $partyWaPhone=preg_replace('/\D+/','',$partyPhoneRaw); ?>
                  <div class="party-contact-actions-v204">
                    <?php if($partyPhoneRaw!==''): ?><a class="call" href="tel:<?=e($partyPhoneRaw)?>" title="Call">☎ Call</a><?php endif; ?>
                    <?php if($partyWaPhone!==''): ?><a class="whatsapp" href="https://wa.me/<?=e($partyWaPhone)?>" target="_blank" rel="noopener" title="WhatsApp">◔ WhatsApp</a><?php endif; ?>
                    <?php if(trim((string)($selected['email']??''))!==''): ?><a class="email" href="mailto:<?=e($selected['email'])?>" title="Email">✉ Email</a><?php endif; ?>
                  </div>
                </div>
                <div class="party-role-line-v110"><?=e($roleNamesText)?></div>
              </div>
              <div class="party-address-v110">Address: <?=e($selected['address']??'')?></div>
            </div>
            <div class="party-action-row-v202">
              <button type="button" class="btn small-btn party-compact-btn-v202" onclick="openModal('partyNoteModal')">+ Add Note</button>
              <?php if(in_array('customer',$selectedRoles,true)): ?>
                <button type="button" class="btn small-btn party-compact-btn-v202" onclick="openModal('partyReviewModal')">★ Add Review</button>
              <?php endif; ?>
            </div>

            <?php if($partyNotes): ?>
            <div class="party-private-notes-v202">
              <div class="party-compact-head-v202">
                <div><b>PRIVATE NOTES</b><span>Only your company can see these.</span></div>
                <button type="button" class="party-link-btn-v202" onclick="openModal('partyNoteModal')">+ Note</button>
              </div>
              <div class="party-notes-list-v202">
                <?php foreach(array_slice($partyNotes,0,2) as $pn): ?>
                  <div class="party-note-row-v202"><div class="party-note-text-v202"><?=e($pn['note'])?></div><small><?=e($pn['user_name']??'User')?> · <?=e(date('d/m/Y h:i A',strtotime($pn['created_at'])))?></small></div>
                <?php endforeach; if(count($partyNotes)>2): ?><div class="party-more-v202">+ <?=count($partyNotes)-2?> more note<?=count($partyNotes)-2===1?'':'s'?></div><?php endif; ?>
              </div>
            </div>
            <?php endif; ?>

            <div class="party-detail-grid-v110 party-detail-grid-v202">
              <div><span>Phone:</span> <?=e($selected['phone'])?></div>
              <div><span>Email:</span> <?=e($selected['email']??'')?></div>
              <div><span>Credit Limit:</span> <?=money((float)$selected['credit_limit'])?></div>
              <div><span>Current Balance:</span> <strong class="<?=$balance<0?'balance-negative':'balance-positive'?>"><?=money(abs($balance))?></strong></div>
            </div>
          </div>

          <?php if(in_array('customer',$selectedRoles,true)): ?>
            <div class="party-reviews-compact-v202">
              <div class="party-compact-head-v202">
                <div><b>CUSTOMER PUBLIC REVIEWS</b><span><?=count($partyReviews)?> Review<?=count($partyReviews)===1?'':'s'?> · Public</span></div>
                <button type="button" class="party-link-btn-v202" onclick="openModal('partyReviewModal')">★ Review</button>
              </div>
              <div class="party-reviews-list-v202">
                <?php if($partyReviewLoadError): ?><div class="party-review-empty-v202" style="color:#b45309"><?=e($partyReviewLoadError)?></div>
                <?php elseif($partyReviews): foreach($partyReviews as $rv): ?>
                  <div class="party-review-row-v202">
                    <div class="party-review-top-v202"><strong><?=str_repeat('★',(int)$rv['rating']).str_repeat('☆',5-(int)$rv['rating'])?></strong><span><?=e($rv['reviewer_name'])?></span><small><?=e(date('d/m/Y',strtotime($rv['created_at'])))?></small></div>
                    <div class="party-review-comment-v202"><?=e($rv['comment'])?></div>
                    <small class="party-review-meta-v202">Verified <?=e(ucwords(str_replace('_',' ',(string)($rv['verified_transaction_type']??''))))?></small>
                  </div>
                <?php endforeach; else: ?><div class="party-review-empty-v202">No public reviews yet.</div><?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
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
    <div class="modal-backdrop" id="partyNoteModal" onclick="if(event.target===this)closeModal('partyNoteModal')"><div class="modal"><div class="modal-head"><h2>Add Private Note</h2><button class="close" type="button" onclick="closeModal('partyNoteModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="party_action" value="add_note"><input type="hidden" name="id" value="<?=$selectedId?>"><div class="form-group"><label>Private Note*</label><textarea name="note" rows="6" required placeholder="Write a private note about this party..."></textarea></div><div class="subtle">This note is visible only inside your company.</div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('partyNoteModal')">Cancel</button><button class="btn primary">Save Note</button></div></form></div></div>
    <div class="modal-backdrop" id="partyReviewModal" onclick="if(event.target===this)closeModal('partyReviewModal')"><div class="modal"><div class="modal-head"><h2>Add Customer Public Review</h2><button class="close" type="button" onclick="closeModal('partyReviewModal')">×</button></div><?php if(!$selectedVerifiedTransaction): ?><div class="form-body"><div class="subtle" style="font-size:14px;line-height:1.55"><b>A verified transaction is required before you can publish a review for this Customer.</b><br><br>Eligible transactions:<br>• Sale Invoice<br>• Confirmed Sale Order / Delivery Challan<br>• Purchase Bill<br>• Payment In / Payment Out</div><?php if($partyReviewLoadError): ?><div class="subtle" style="margin-top:8px;color:#b45309"><?=e($partyReviewLoadError)?></div><?php endif; ?></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('partyReviewModal')">Close</button></div><?php else: ?><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="party_action" value="review_customer"><input type="hidden" name="party_id" value="<?=$selectedId?>"><div class="form-group"><label>Customer</label><input value="<?=e($selected['name']??'')?> · <?=e($selected['phone']??'')?>" disabled></div><div class="form-group"><label>Verified Transaction</label><input value="<?=e(ucwords(str_replace('_',' ',(string)$selectedVerifiedTransaction['type'])))?> · <?=e($selectedVerifiedTransaction['document_no']??'')?>" disabled></div><div class="form-group"><label>Rating*</label><select name="rating" required><option value="5">★★★★★</option><option value="4">★★★★☆</option><option value="3">★★★☆☆</option><option value="2">★★☆☆☆</option><option value="1">★☆☆☆☆</option></select></div><div class="form-group"><label>Review*</label><textarea name="comment" rows="5" required placeholder="Write your review..."></textarea></div><div class="subtle">This review is public to other sense companies that use the same customer phone number. Customer approval or company linking is not required.</div><?php if($selectedHasPublicReview): ?><div class="subtle" style="margin-top:8px;color:#b45309">Your company has already reviewed this customer. You cannot submit another review.</div><?php endif; ?></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('partyReviewModal')">Cancel</button><button class="btn primary" <?= $selectedHasPublicReview?'disabled':'' ?>>Submit Review</button></div></form><?php endif; ?></div></div>
    <div class="modal-backdrop" id="partyModal" onclick="if(event.target===this)closeModal('partyModal')"><div class="modal"><div class="modal-head"><h2 id="partyModalTitle">Add Party</h2><button class="close" type="button" onclick="closeModal('partyModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="party_action" id="party_action" value="create"><input type="hidden" name="id" id="party_id"><div class="grid2"><div class="form-group"><label>Party Name*</label><input name="name" id="party_name" required></div><div class="form-group"><label>Phone Number*</label><input name="phone" id="party_phone" required maxlength="11" inputmode="numeric" pattern="(013|014|015|016|017|018|019)[0-9]{8}" placeholder="01712345678"></div><div class="form-group"><label>Email ID</label><input type="email" name="email" id="party_email"></div><div class="form-group span2"><label>Party Role(s)*</label><div class="party-role-grid"><?php foreach($roleLabels as $rv=>$rl):?><label class="check-role"><input type="checkbox" name="party_roles[]" value="<?=e($rv)?>" id="party_role_<?=$rv?>"><span><?=e($rl)?></span></label><?php endforeach;?></div><div class="subtle">A party can have multiple roles.</div></div><div class="form-group span2"><label>Billing / Contact Address</label><textarea name="address" id="party_address"></textarea></div><div class="form-group"><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" id="party_opening" value="0"></div><div class="form-group"><label>Opening Balance Type</label><select name="opening_balance_type" id="party_opening_type"><option value="receivable">Receivable</option><option value="payable">Payable</option><option value="capital">Investment / Capital</option><option value="loan_given">Loan Given</option><option value="loan_taken">Loan Taken</option></select></div><div class="form-group"><label>Credit Limit</label><input type="number" step="0.01" name="credit_limit" id="party_limit" value="0"></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('partyModal')">Cancel</button><button class="btn" type="submit" name="save_new" onclick="document.getElementById('party_action').value='create'">Save & New</button><button class="btn primary" type="submit">Save</button></div></form></div></div>
    <script>
    document.getElementById('partyListSearchBtn')?.addEventListener('click',()=>{const f=document.getElementById('partyListSearchForm');f?.classList.toggle('show');f?.querySelector('input[name="q"]')?.focus();});
    document.addEventListener('click',e=>{const menu=e.target.closest('.party-row-menu-v110,.party-dots-v110');if(menu)return;document.querySelectorAll('.party-row-menu-v110.show').forEach(x=>x.classList.remove('show'));});
    function resetPartyForm(){document.getElementById('partyModalTitle').textContent='Add Party';document.getElementById('party_action').value='create';document.getElementById('party_id').value='';document.getElementById('party_name').value='';document.getElementById('party_phone').value='';document.getElementById('party_email').value='';document.getElementById('party_address').value='';document.getElementById('party_opening').value='0';document.getElementById('party_opening_type').value='receivable';document.getElementById('party_limit').value='0';document.querySelectorAll('#partyModal input[name="party_roles[]"]').forEach(x=>x.checked=false);}
    function editParty(p){document.getElementById('partyModalTitle').textContent='Edit Party';document.getElementById('party_action').value='update';document.getElementById('party_id').value=p.id;document.getElementById('party_name').value=p.name||'';document.getElementById('party_phone').value=p.phone||'';document.getElementById('party_email').value=p.email||'';document.getElementById('party_address').value=p.address||'';document.getElementById('party_opening').value=p.opening_balance||0;document.getElementById('party_opening_type').value=p.opening_balance_type||'receivable';document.getElementById('party_limit').value=p.credit_limit||0;document.querySelectorAll('#partyModal input[name="party_roles[]"]').forEach(x=>x.checked=Array.isArray(p.roles)&&p.roles.includes(x.value));openModal('partyModal');}
    const partyEditRequested=<?=json_encode($editRequested)?>; if(partyEditRequested>0 && partyEditRequested===<?=json_encode($selectedId)?>){setTimeout(()=>editParty(<?=json_encode($selected??null,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>),0);}
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


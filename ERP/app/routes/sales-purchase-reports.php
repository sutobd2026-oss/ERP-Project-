<?php
function ensure_transaction_item_metadata_schema_report(PDO $pdo): void {
    static $done=false; if($done)return; $done=true;
    try { $cols=$pdo->query('SHOW COLUMNS FROM transaction_items')->fetchAll(PDO::FETCH_COLUMN,0); foreach(['item_description'=>'TEXT NULL','item_warranty'=>'VARCHAR(255) NULL'] as $c=>$def){if(!in_array($c,$cols,true))$pdo->exec('ALTER TABLE transaction_items ADD COLUMN `'.$c.'` '.$def);} } catch(Throwable $e) { error_log('report transaction item metadata schema: '.$e->getMessage()); }
}

/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='payment-in'){
    $cid=(int)$u['company_id']; $pdo=db();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        try{
            $editId=(int)($_POST['transaction_id']??0);
            $existingTx=null;
            if($editId>0){
                $st=$pdo->prepare('SELECT t.* FROM transactions t WHERE t.id=? AND t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL LIMIT 1');
                $st->execute([$editId,$cid]); $existingTx=$st->fetch();
                if(!$existingTx) throw new RuntimeException('Delivery Challan not found.');
                $lk=$pdo->prepare('SELECT tl.to_transaction_id FROM transaction_links tl WHERE tl.company_id=? AND tl.from_transaction_id=? AND tl.relation_type="challan_to_sale" LIMIT 1');
                $lk->execute([$cid,$editId]);
                if($lk->fetchColumn()) throw new RuntimeException('Converted Delivery Challan cannot be edited.');
            }
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
            $date=transaction_datetime($_POST['txn_date']??null);$doc=trim($_POST['document_no']??'');$pdo=db();$pdo->beginTransaction();
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
        ?><div class="panel print-company-header" style="margin-bottom:14px"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px"><div><?php $logo=saas_company_logo_url($u['logo_path']??null); if($logo): ?><img src="<?=e($logo)?>" alt="Company logo" style="max-height:56px;max-width:180px;object-fit:contain;margin-bottom:6px"><br><?php endif; ?><h2 style="margin:0"><?=e($u['company_name']??'')?></h2><div class="subtle">Payment Receipt</div></div><div style="text-align:right"><strong><?=e($tx['document_no'])?></strong><br><?=e(date('d/m/Y',strtotime($tx['txn_date'])))?></div></div></div><div class="page-title"><div><h1>Payment-In <?=e($tx['document_no'])?></h1><p><?=e($tx['txn_date'])?> · <?=e($tx['party_name'])?></p></div><div><button class="btn" onclick="window.print()">Print</button><a class="btn primary" href="<?=e(url('payment-in'))?>">+ New Payment</a></div></div>
        <div class="cards-top"><div class="metric-card"><div class="label">Received</div><div class="value"><?=money((float)$tx['total'])?></div></div><div class="metric-card"><div class="label">Party</div><div class="value" style="font-size:20px"><?=e($tx['party_name'])?></div></div></div>
        <div class="panel"><div class="panel-head"><h2>Payment Details</h2></div><div class="table-wrap"><table><thead><tr><th>METHOD</th><th>ACCOUNT</th><th>REFERENCE</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($payments as $r):?><tr><td><?=e(ucwords(str_replace('_',' ',$r['method'])))?></td><td><?=e($r['account_name']??'-')?></td><td><?=e($r['reference_no']??'-')?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div></div><?php page_end();exit;}
    }
    $st=db()->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="payment_in" AND t.deleted_at IS NULL ORDER BY t.id DESC LIMIT 50');$st->execute([$cid]);$rows=$st->fetchAll();
    ?><div class="page-title"><div><h1>Payment In</h1><p>Receive payments from customers, investors, lenders and other parties</p></div><a class="btn primary" href="#newPayment">⊕ Add Payment-In</a></div>
    <div class="panel" id="newPayment"><div class="panel-head"><h2>New Payment-In</h2><span class="subtle">Multiple payment methods allowed</span></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="entry-top"><div><div class="form-group"><label>Party*</label><?php party_search_field('Party','customer_all',0,'',''); ?></div><div class="subtle" id="partyDue" style="margin-top:6px">Select a party to see outstanding due.</div></div><div><div class="form-group"><label>Receipt Number</label><input name="document_no" placeholder="Auto: PI-01"></div><div class="form-group"><label>Date*</label><input type="date" name="txn_date" value="<?=date('Y-m-d')?>" required></div></div><div class="entry-right"><div class="right-card"><div class="title">CURRENT DUE</div><div class="value" id="currentDue">৳0.00</div></div></div></div>
    <div class="payment-box"><div class="panel-head"><h2>Payment Methods</h2><span class="subtle">Split one receipt across multiple methods</span></div><div id="paymentRows">
      <div class="payment-line"><select name="pay_method[]" onchange="togglePaymentFields(this)"><?=payment_select_options($bankRows,'cash','')?></select><input type="date" name="pay_cheque_date[]" class="pay-cheque-date" style="display:none"><input name="pay_ref[]" placeholder="Reference / Cheque No."><input type="number" min="0" step="0.01" name="pay_amount[]" value="0" placeholder="Amount"></div>
    </div><button type="button" class="btn" onclick="addPayment()">+ Add Payment</button></div><div class="grid2" style="margin-top:12px"><div class="form-group"><label>Notes</label><textarea name="notes" rows="3" placeholder="Add description"></textarea></div><div class="metric-card"><div class="label">Total Received</div><div class="value" id="receivedPreview">৳0.00</div></div></div><div class="form-footer" style="margin:0 -16px -16px"><button type="button" class="btn" onclick="window.print()">Print / Preview</button><button class="btn primary">Save Payment-In</button></div></form></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>TRANSACTIONS</h2><input class="input" style="max-width:240px" placeholder="Search"></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>RECEIPT NO.</th><th>PARTY NAME</th><th>PAYMENT TYPE</th><th>AMOUNT</th><th>ACTION</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td><td><?=e($r['document_no'])?></td><td><?=e($r['party_name']??'')?></td><td>Multiple / See receipt</td><td><?=money((float)$r['total'])?></td><td class="action"><a class="btn" href="<?=e(url('payment-in?view='.(int)$r['id']))?>">View</a></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6" class="subtle">No payment-in transactions yet.</td></tr><?php endif;?></tbody></table></div></div>
    <script>
    document.addEventListener('DOMContentLoaded',()=>{const party=document.getElementById('paymentParty'),due=document.getElementById('currentDue'),note=document.getElementById('partyDue');function upd(){const d=parseFloat(party?.dataset.due||0);const roles=(party?.dataset.roles||'').split(',').map(x=>x.trim());const special=roles.includes('investor')||roles.includes('lender')||roles.includes('other');due.textContent='৳'+d.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});note.textContent=party?.value?(special?'Payment In is available for this party; amount is not limited by customer due.':'Outstanding due: '+due.textContent):'Select a party to see outstanding due.';}party?.addEventListener('change',upd);document.addEventListener('party-selected',upd);function sum(){let t=0;document.querySelectorAll('input[name="pay_amount[]"]').forEach(i=>t+=parseFloat(i.value||0));const x=document.getElementById('receivedPreview');if(x)x.textContent='৳'+t.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}document.querySelectorAll('input[name="pay_amount[]"]').forEach(i=>i.addEventListener('input',sum));window.addEventListener('input',e=>{if(e.target.matches('input[name="pay_amount[]"]'))sum();});upd();sum();});
    </script><?php page_end();exit;
}


/** v220: dedicated invoice print preview with A4/A5 toggle and company-default paper size. */
function render_invoice_print_preview(array $tx,array $lines,array $payments,array $u,string $defaultPaper,string $companySize,string $invoiceSize,string $docType,string $partyLabel,string $companyName,string $companyEmail,string $companyPhone,string $companyAddress,bool $direct=false): void {
    $paper=$defaultPaper==='A5'?'A5':'A4';
    $companySize=in_array($companySize,['small','medium','large'],true)?$companySize:'medium';
    $invoiceSize=in_array($invoiceSize,['small','medium','large'],true)?$invoiceSize:'medium';
    $logo=saas_company_logo_url($u['logo_path']??null);
    $title=$docType==='Purchase Bill'?'PURCHASE BILL':'SALE INVOICE';
    $partyName=(string)($tx['party_name']??($partyLabel==='Customer'?'Walk-in':'—'));
    $partyPhone=(string)($tx['party_phone']??'');
    $partyAddress=(string)($tx['party_address']??'');
    $date=!empty($tx['txn_date'])?date('d/m/Y',strtotime((string)$tx['txn_date'])):'—';
    $dueDate=!empty($tx['due_date'])?date('d/m/Y',strtotime((string)$tx['due_date'])):'—';
    $companyLines=array_values(array_filter([$companyAddress,$companyPhone,$companyEmail],fn($x)=>trim((string)$x)!==''));
    $companyMeta=implode(' · ',array_map('strval',$companyLines));
    $previewCloseUrl=$docType==='Purchase Bill'?url('purchase'):url('sales');
    ?>
    <style>
      .sense-invoice-preview-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.76);z-index:2147483642;overflow:auto;padding:18px;box-sizing:border-box}
      .sense-invoice-preview-overlay.open{display:block}
      .sense-invoice-preview-direct{display:block;position:static;inset:auto;z-index:auto;background:#f1f5f9;overflow:visible;padding:0 0 82px;min-height:calc(100vh - 1px);box-sizing:border-box}
      .sense-invoice-preview-direct .sense-invoice-preview-toolbar{border-radius:0;box-shadow:0 -2px 10px rgba(15,23,42,.14);max-width:none;padding:12px 18px;margin:0}
      .sense-invoice-preview-direct .sense-invoice-preview-frame{min-height:calc(100vh - 62px);padding:20px 12px 96px}
      .sense-invoice-preview-direct .sense-invoice-preview-sheet{margin:0 auto}
      .sense-invoice-preview-toolbar{position:fixed;left:0;right:0;bottom:0;z-index:2147483600;display:flex;justify-content:space-between;align-items:center;gap:10px;max-width:none;margin:0;padding:10px 18px;background:#fff;border:1px solid #e5e7eb;border-left:0;border-right:0;border-bottom:0;border-radius:0;box-shadow:0 -4px 18px rgba(15,23,42,.16)}
      .sense-invoice-preview-toolbar-left,.sense-invoice-preview-toolbar-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
      .sense-invoice-paper-toggle{display:flex;align-items:center;gap:4px;padding:3px;background:#f3f4f6;border-radius:10px;border:1px solid #e5e7eb}
      .sense-invoice-paper-btn{border:0;background:transparent;border-radius:8px;padding:7px 12px;font:600 13px/1 Arial,sans-serif;color:#475569;cursor:pointer}
      .sense-invoice-paper-btn.active{background:#111827;color:#fff;box-shadow:0 1px 4px rgba(15,23,42,.18)}
      .sense-invoice-default-label{font-size:12px;color:#64748b}
      .sense-invoice-preview-frame{display:flex;justify-content:center;align-items:flex-start;overflow:auto;padding:12px 4px 36px;min-height:calc(100vh - 100px);box-sizing:border-box}
      .sense-invoice-preview-sheet{width:210mm;min-height:297mm;background:#fff;box-shadow:0 4px 26px rgba(15,23,42,.28);box-sizing:border-box;color:#172033;font-family:Arial,Helvetica,sans-serif;padding:12mm;position:relative;transition:width .15s ease,min-height .15s ease,padding .15s ease;font-size:12px}
      .sense-invoice-preview-sheet.paper-a5{width:148mm;min-height:210mm;padding:9mm;font-size:10px}
      .sense-invoice-preview-sheet .sip-head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #111827;padding-bottom:12px;margin-bottom:12px}
      .sense-invoice-preview-sheet .sip-brand{display:flex;gap:10px;align-items:flex-start;min-width:0}
      .sense-invoice-preview-sheet .sip-logo{max-width:58mm;max-height:24mm;object-fit:contain}
      .sense-invoice-preview-sheet.paper-a5 .sip-logo{max-width:42mm;max-height:17mm}
      .sense-invoice-preview-sheet .sip-company-name{font-size:24px;font-weight:800;line-height:1.1;margin-bottom:5px}
      .sense-invoice-preview-sheet.paper-a5 .sip-company-name{font-size:18px}
      .sense-invoice-preview-sheet .sip-company-meta{font-size:10px;line-height:1.5;color:#64748b;white-space:pre-line;max-width:105mm}
      .sense-invoice-preview-sheet.paper-a5 .sip-company-meta{font-size:8px;max-width:70mm}
      .sense-invoice-preview-sheet .sip-title{text-align:right;min-width:38mm}
      .sense-invoice-preview-sheet .sip-title h1{font-size:20px;line-height:1.1;margin:0 0 7px;font-weight:800}
      .sense-invoice-preview-sheet.paper-a5 .sip-title h1{font-size:15px}
      .sense-invoice-preview-sheet .sip-title .sip-docno{font-size:15px;font-weight:800}
      .sense-invoice-preview-sheet.paper-a5 .sip-title .sip-docno{font-size:12px}
      .sense-invoice-preview-sheet .sip-title .sip-date{margin-top:3px;color:#64748b;font-size:10px}
      .sense-invoice-preview-sheet.paper-a5 .sip-title .sip-date{font-size:8px}
      .sense-invoice-preview-sheet .sip-info-grid{display:grid;grid-template-columns:1.45fr 1fr 1fr;gap:9px;margin-bottom:14px}
      .sense-invoice-preview-sheet.paper-a5 .sip-info-grid{gap:6px;margin-bottom:9px}
      .sense-invoice-preview-sheet .sip-info-box{border:1px solid #dfe3e8;border-radius:7px;padding:9px 10px;min-height:46px;box-sizing:border-box}
      .sense-invoice-preview-sheet.paper-a5 .sip-info-box{padding:6px 7px;min-height:34px}
      .sense-invoice-preview-sheet .sip-label{font-size:8px;text-transform:uppercase;letter-spacing:.08em;color:#7b8794;margin-bottom:4px;font-weight:700}
      .sense-invoice-preview-sheet.paper-a5 .sip-label{font-size:7px}
      .sense-invoice-preview-sheet .sip-value{font-weight:700;font-size:11px;line-height:1.35}
      .sense-invoice-preview-sheet.paper-a5 .sip-value{font-size:9px}
      .sense-invoice-preview-sheet .sip-sub{font-size:9px;color:#64748b;margin-top:2px;line-height:1.35}
      .sense-invoice-preview-sheet.paper-a5 .sip-sub{font-size:7.5px}
      .sense-invoice-preview-sheet table{width:100%;border-collapse:collapse}
      .sense-invoice-preview-sheet .sip-items thead th{background:#f3f4f6;border-top:1px solid #dfe3e8;border-bottom:1px solid #dfe3e8;padding:8px 7px;font-size:8px;text-align:left;text-transform:uppercase;letter-spacing:.05em;color:#475569}
      .sense-invoice-preview-sheet.paper-a5 .sip-items thead th{padding:6px 5px;font-size:6.5px}
      .sense-invoice-preview-sheet .sip-items tbody td{border-bottom:1px solid #e7eaee;padding:8px 7px;vertical-align:top;font-size:10px}
      .sense-invoice-preview-sheet.paper-a5 .sip-items tbody td{padding:6px 5px;font-size:8px}
      .sense-invoice-preview-sheet .sip-item-meta{display:flex;align-items:baseline;gap:8px;flex-wrap:wrap;margin-top:3px}.sense-invoice-preview-sheet .sip-item-description{font-size:8px;line-height:1.35;color:#64748b;white-space:pre-line}.sense-invoice-preview-sheet .sip-item-warranty{font-size:8px;font-weight:700;color:#475569;white-space:nowrap}.sense-invoice-preview-sheet.paper-a5 .sip-item-description,.sense-invoice-preview-sheet.paper-a5 .sip-item-warranty{font-size:6.8px}
      .sense-invoice-preview-sheet .num{text-align:right;white-space:nowrap}.sense-invoice-preview-sheet .sip-items thead th.num,.sense-invoice-preview-sheet .sip-items tbody td.num{text-align:right!important}
      .sense-invoice-preview-sheet .sip-bottom{display:grid;grid-template-columns:minmax(0,1fr) 74mm;gap:18px;margin-top:14px}
      .sense-invoice-preview-sheet.paper-a5 .sip-bottom{grid-template-columns:minmax(0,1fr) 54mm;gap:10px;margin-top:9px}
      .sense-invoice-preview-sheet .sip-notes{font-size:9px;line-height:1.45;color:#475569;white-space:pre-line}
      .sense-invoice-preview-sheet.paper-a5 .sip-notes{font-size:7.5px}
      .sense-invoice-preview-sheet .sip-summary{border-top:1px solid #111827}
      .sense-invoice-preview-sheet .sip-summary-row{display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid #e7eaee;font-size:10px}
      .sense-invoice-preview-sheet.paper-a5 .sip-summary-row{padding:4px 0;font-size:8px}
      .sense-invoice-preview-sheet .sip-summary-row.total{font-size:13px;font-weight:800;border-bottom:2px solid #111827;padding:8px 0}
      .sense-invoice-preview-sheet.paper-a5 .sip-summary-row.total{font-size:10px;padding:6px 0}
      .sense-invoice-preview-sheet .sip-payment{margin-top:12px}
      .sense-invoice-preview-sheet .sip-payment-title{font-size:8px;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.06em;margin-bottom:4px}
      .sense-invoice-preview-sheet.paper-a5 .sip-payment-title{font-size:7px}
      .sense-invoice-preview-sheet .sip-payment-row{display:flex;justify-content:space-between;gap:10px;font-size:9px;padding:3px 0;color:#475569}
      .sense-invoice-preview-sheet.paper-a5 .sip-payment-row{font-size:7.5px}
      .sense-invoice-preview-sheet .sip-footer{display:grid;grid-template-columns:1fr 1fr;gap:36px;margin-top:34px}
      .sense-invoice-preview-sheet.paper-a5 .sip-footer{gap:20px;margin-top:22px}
      .sense-invoice-preview-sheet .sip-sign{padding-top:18px;border-top:1px solid #94a3b8;text-align:center;font-size:8px;color:#64748b}
      .sense-invoice-preview-sheet.paper-a5 .sip-sign{padding-top:12px;font-size:6.5px}
      .sense-invoice-preview-sheet .sip-thanks{text-align:center;margin-top:18px;font-size:8px;color:#94a3b8}
      .sense-invoice-preview-sheet.paper-a5 .sip-thanks{margin-top:10px;font-size:6.5px}
      @media(max-width:800px){.sense-invoice-preview-overlay{padding:10px}.sense-invoice-preview-direct{padding-bottom:126px}.sense-invoice-preview-toolbar{align-items:flex-start;flex-direction:column;padding:9px 12px}.sense-invoice-preview-toolbar-left,.sense-invoice-preview-toolbar-right{width:100%;justify-content:flex-end}.sense-invoice-preview-direct .sense-invoice-preview-frame{padding-bottom:136px}}
      @media print{.sense-invoice-preview-overlay{display:none!important}.sense-invoice-preview-direct{background:#fff!important}.sense-invoice-preview-toolbar{display:none!important}.sense-invoice-preview-frame{padding:0!important;min-height:0!important}.sense-invoice-preview-sheet{box-shadow:none!important;margin:0!important}}
    .bundle-invoice-child td{border-top:0;color:#475569}.bundle-invoice-child td:nth-child(2){padding-left:18px;font-size:.96em}.bundle-invoice-child strong{font-weight:600}.bundle-invoice-child .num{white-space:nowrap}</style>
    <div class="sense-invoice-preview-overlay<?= $direct ? ' sense-invoice-preview-direct open' : '' ?>" id="senseInvoicePreviewOverlay" aria-hidden="<?= $direct ? 'false' : 'true' ?>" data-direct="<?= $direct ? '1' : '0' ?>" data-default-paper="<?=e($paper)?>" data-doc-type="<?=e($docType)?>" data-document="<?=e((string)$tx['document_no'])?>">
      <div class="sense-invoice-preview-toolbar">
        <div class="sense-invoice-preview-toolbar-left"><strong style="font-size:14px">Invoice Print Preview</strong><span class="sense-invoice-default-label">Default: <?=e($paper)?></span><div class="sense-invoice-paper-toggle" role="group" aria-label="Paper size"><button type="button" class="sense-invoice-paper-btn <?=($paper==='A4'?'active':'')?>" data-paper="A4" onclick="senseInvoicePreviewSetSize('A4')">A4</button><button type="button" class="sense-invoice-paper-btn <?=($paper==='A5'?'active':'')?>" data-paper="A5" onclick="senseInvoicePreviewSetSize('A5')">A5</button></div></div>
        <?php if($direct): ?><div class="sense-invoice-preview-toolbar-right"><a class="btn" href="<?=e($previewCloseUrl)?>">Close Preview</a><button type="button" class="btn primary" onclick="senseInvoicePreviewPrint()">Print</button></div><?php else: ?><div class="sense-invoice-preview-toolbar-right"><button type="button" class="btn" onclick="senseInvoicePreviewClose()">Close Preview</button><button type="button" class="btn primary" onclick="senseInvoicePreviewPrint()">Print</button></div><?php endif; ?>
      </div>
      <div class="sense-invoice-preview-frame"><section class="sense-invoice-preview-sheet <?=($paper==='A5'?'paper-a5':'paper-a4')?>" id="senseInvoicePreviewSheet">
        <div class="sip-head"><div class="sip-brand"><?php if($logo): ?><img class="sip-logo" src="<?=e($logo)?>" alt="Company logo"><?php endif; ?><div><div class="sip-company-name"><?=e($companyName)?></div><?php if($companyMeta!==''): ?><div class="sip-company-meta"><?=e($companyMeta)?></div><?php endif; ?></div></div><div class="sip-title"><h1><?=e($title)?></h1><div class="sip-docno"><?=e((string)$tx['document_no'])?></div><div class="sip-date"><?=e($date)?></div></div></div>
        <div class="sip-info-grid"><div class="sip-info-box"><div class="sip-label"><?=e($partyLabel)?></div><div class="sip-value"><?=e($partyName)?></div><?php if($partyPhone!==''): ?><div class="sip-sub"><?=e($partyPhone)?></div><?php endif; ?><?php if($partyAddress!==''): ?><div class="sip-sub"><?=e($partyAddress)?></div><?php endif; ?></div><div class="sip-info-box"><div class="sip-label">Invoice Date</div><div class="sip-value"><?=e($date)?></div></div><div class="sip-info-box"><div class="sip-label">Due Date</div><div class="sip-value"><?=e($dueDate)?></div><div class="sip-sub">Status: <?=e(((float)($tx['due']??0)>0)?'Due':'Paid')?></div></div></div>
        <div class="sip-items"><table><thead><tr><th style="width:6%">#</th><th>ITEM</th><th class="num">QTY</th><th class="num">PRICE/UNIT</th><th class="num">DISCOUNT</th><th class="num">AMOUNT</th></tr></thead><tbody><?php if($lines): foreach($lines as $i=>$r): $isBundleChild=(int)($r['bundle_parent_transaction_item_id']??0)>0; ?><tr class="<?= $isBundleChild?'bundle-invoice-child':'' ?>"><td><?=($i+1)?></td><td><strong><?=e(($isBundleChild?'└─ ':'').(string)$r['item_name'])?></strong><?php if(!$isBundleChild && (trim((string)($r['item_description']??''))!=='' || trim((string)($r['item_warranty']??''))!=='')): ?><div class="sip-item-meta"><?php if(trim((string)($r['item_description']??''))!==''): ?><span class="sip-item-description"><?=nl2br(e((string)$r['item_description']))?></span><?php endif; ?><?php if(trim((string)($r['item_warranty']??''))!==''): ?><span class="sip-item-warranty">Warranty: <?=e((string)$r['item_warranty'])?></span><?php endif; ?></div><?php endif; ?></td><td class="num"><?=e(qty((float)$r['qty']).' '.((string)($r['unit_symbol']??'')))?></td><td class="num"><?=e($isBundleChild?'Free':money((float)$r['unit_price']))?></td><td class="num"><?=e($isBundleChild?'—':money((float)$r['discount']))?></td><td class="num"><?=e($isBundleChild?'Free':money((float)$r['amount']))?></td></tr><?php endforeach; else: ?><tr><td colspan="6" style="text-align:center;color:#94a3b8">No items.</td></tr><?php endif; ?></tbody></table></div>
        <div class="sip-bottom"><div><?php if(!empty($tx['notes'])): ?><div class="sip-label">Notes</div><div class="sip-notes"><?=nl2br(e((string)$tx['notes']))?></div><?php endif; ?><?php if($payments): ?><div class="sip-payment"><div class="sip-payment-title">Payments</div><?php foreach($payments as $pay): ?><div class="sip-payment-row"><span><?=e(ucwords(str_replace('_',' ',(string)($pay['method']??''))))?><?=!empty($pay['reference_no'])?' · '.e((string)$pay['reference_no']):''?></span><strong><?=e(money((float)$pay['amount']))?></strong></div><?php endforeach; ?></div><?php endif; ?></div>
          <div class="sip-summary"><div class="sip-summary-row"><span>Subtotal</span><strong><?=e(money((float)$tx['subtotal']))?></strong></div><?php if((float)$tx['item_discount']>0): ?><div class="sip-summary-row"><span>Item Discount</span><strong><?=e(money((float)$tx['item_discount']))?></strong></div><?php endif; ?><?php if((float)$tx['invoice_discount']>0): ?><div class="sip-summary-row"><span>Invoice Discount</span><strong><?=e(money((float)$tx['invoice_discount']))?></strong></div><?php endif; ?><?php if((float)$tx['tax']>0): ?><div class="sip-summary-row"><span>Tax / VAT</span><strong><?=e(money((float)$tx['tax']))?></strong></div><?php endif; ?><?php if((float)$tx['direct_expense']>0): ?><div class="sip-summary-row"><span>Direct Expense</span><strong><?=e(money((float)$tx['direct_expense']))?></strong></div><?php endif; ?><div class="sip-summary-row total"><span>Total</span><strong><?=e(money((float)$tx['total']))?></strong></div><div class="sip-summary-row"><span>Paid</span><strong><?=e(money((float)$tx['paid']))?></strong></div><div class="sip-summary-row"><span>Due</span><strong><?=e(money((float)$tx['due']))?></strong></div></div></div>
        <div class="sip-footer"><div class="sip-sign">Customer Signature</div><div class="sip-sign">Authorized Signature</div></div><div class="sip-thanks">Thank you for your business.</div>
      </section></div>
    </div>
    <script>
    (function(){
      function sheet(){return document.getElementById('senseInvoicePreviewSheet');}
      function overlay(){return document.getElementById('senseInvoicePreviewOverlay');}
      var directPage=overlay() && overlay().dataset.direct==='1';
      window.senseInvoicePreviewSetSize=function(size){size=size==='A5'?'A5':'A4';var s=sheet(),o=overlay();if(!s||!o)return;s.classList.toggle('paper-a5',size==='A5');s.classList.toggle('paper-a4',size==='A4');o.dataset.currentPaper=size;document.querySelectorAll('#senseInvoicePreviewOverlay [data-paper]').forEach(function(btn){btn.classList.toggle('active',btn.dataset.paper===size);});};
      window.senseInvoicePreviewOpen=function(){var o=overlay();if(!o)return;senseInvoicePreviewSetSize(o.dataset.currentPaper||o.dataset.defaultPaper||'A4');o.classList.add('open');o.setAttribute('aria-hidden','false');document.body.style.overflow='hidden';};
      window.senseInvoicePreviewClose=function(){var o=overlay();if(!o)return;if(directPage){history.back();return;}o.classList.remove('open');o.setAttribute('aria-hidden','true');document.body.style.overflow='';};
      window.senseInvoicePreviewPrint=function(){var o=overlay(),s=sheet();if(!o||!s)return;var paper=(o.dataset.currentPaper||o.dataset.defaultPaper||'A4')==='A5'?'A5':'A4';var iframe=document.createElement('iframe');iframe.style.position='fixed';iframe.style.right='0';iframe.style.bottom='0';iframe.style.width='0';iframe.style.height='0';iframe.style.border='0';iframe.setAttribute('aria-hidden','true');document.body.appendChild(iframe);var doc=iframe.contentDocument||iframe.contentWindow.document;var links=Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(function(l){return l.outerHTML;}).join('');var styles=Array.from(document.querySelectorAll('style')).map(function(st){return st.textContent||'';}).join('\n');var extra='@page{size:'+paper+' portrait;margin:0}html,body{margin:0!important;padding:0!important;background:#fff!important}body{font-family:Arial,Helvetica,sans-serif}.sense-invoice-preview-sheet{display:block!important;position:relative!important;width:'+(paper==='A5'?'148mm':'210mm')+'!important;min-height:'+(paper==='A5'?'210mm':'297mm')+'!important;margin:0 auto!important;padding:'+(paper==='A5'?'9mm':'12mm')+'!important;box-shadow:none!important;background:#fff!important}.sense-invoice-preview-sheet.paper-a5{font-size:10px}.sense-invoice-preview-overlay{display:block!important;position:static!important;padding:0!important;background:#fff!important}.sense-invoice-preview-frame{overflow:visible!important;padding:0!important;min-height:0!important}.sense-invoice-preview-toolbar{display:none!important}';doc.open();doc.write('<!doctype html><html><head><meta charset="utf-8">'+links+'<style>'+styles+'\n'+extra+'</style></head><body><div class="sense-invoice-preview-overlay open"><div class="sense-invoice-preview-frame">'+s.outerHTML+'</div></div></body></html>');doc.close();setTimeout(function(){try{iframe.contentWindow.focus();iframe.contentWindow.print();}catch(e){}setTimeout(function(){iframe.remove();},1200);},180);};
      document.addEventListener('keydown',function(ev){if(ev.key==='Escape' && !directPage)senseInvoicePreviewClose();});
      <?php if($direct): ?>window.addEventListener('load',function(){senseInvoicePreviewSetSize(<?=json_encode($paper)?>);<?php if(isset($_GET['autoprint'])): ?>setTimeout(function(){senseInvoicePreviewPrint();},350);<?php endif; ?>});<?php elseif(isset($_GET['print_preview']) || isset($_GET['preview'])): ?>window.addEventListener('load',function(){setTimeout(function(){senseInvoicePreviewOpen();},80);});<?php endif; ?>
    })();
    </script>
    <?php
}

if($route==='sales' && isset($_GET['view'])){
    $tid=(int)$_GET['view'];$cid=(int)$u['company_id'];
    $pdo=db(); ensure_transaction_item_metadata_schema_report($pdo);
    $st=db()->prepare('SELECT t.*,p.name party_name,p.phone party_phone,p.address party_address FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type="sale" LIMIT 1');$st->execute([$tid,$cid]);$tx=$st->fetch();
    if(!$tx){http_response_code(404);page_start('Invoice Not Found');echo '<div class="panel"><h1>Invoice not found</h1></div>';page_end();exit;}
    $itSt=db()->prepare('SELECT ti.*,i.name item_name,i.item_type,COALESCE(NULLIF(ti.item_description,""),i.description) item_description,COALESCE(NULLIF(ti.item_warranty,""),i.warranty) item_warranty,u.symbol unit_symbol FROM transaction_items ti JOIN items i ON i.id=ti.item_id LEFT JOIN units u ON u.id=i.unit_id WHERE ti.transaction_id=? ORDER BY ti.id');$itSt->execute([$tid]);$lines=$itSt->fetchAll();
    $pay=db()->prepare('SELECT * FROM payment_lines WHERE transaction_id=? ORDER BY id');$pay->execute([$tid]);$payments=$pay->fetchAll();
    $printPaper=setting('print_paper_size','A4',(int)$u['company_id']);
    $printCompanySize=setting('print_company_name_size','medium',(int)$u['company_id']);
    $printInvoiceSize=setting('print_invoice_text_size','medium',(int)$u['company_id']);
    $companySt=db()->prepare('SELECT name,email,phone,address,logo_path FROM companies WHERE id=? LIMIT 1');$companySt->execute([$cid]);$companyMetaRow=$companySt->fetch()?:[];
    if(!in_array($printPaper,['A4','A5'],true))$printPaper='A4';
    if(!in_array($printCompanySize,['small','medium','large'],true))$printCompanySize='medium';
    if(!in_array($printInvoiceSize,['small','medium','large'],true))$printInvoiceSize='medium';
    page_start('Invoice Print Preview');
    ?><style>
      .sense-direct-page-title{max-width:1180px;margin:12px auto 0;padding:0 12px;color:#475569;font-size:12px}
      .sense-direct-page-title strong{color:#0f172a;font-size:13px}
      @media print{body{background:#fff!important}.topbar,.sidebar,.gear,.sense-direct-page-title{display:none!important}}
    </style>
    <div class="sense-direct-page-title"><strong>Invoice Print Preview</strong> · <?=e($tx['document_no'])?></div>
    <?php render_invoice_print_preview($tx,$lines,$payments,$u,$printPaper,$printCompanySize,$printInvoiceSize,'Sale Invoice','Customer',(string)($companyMetaRow['name']??($u['company_name']??'')),(string)($companyMetaRow['email']??''),(string)($companyMetaRow['phone']??''),(string)($companyMetaRow['address']??''),true); page_end();exit;
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
    $sql='SELECT t.*,p.name party_name,p.phone party_phone,(SELECT GROUP_CONCAT(DISTINCT pl.method ORDER BY pl.id SEPARATOR ",") FROM payment_lines pl WHERE pl.transaction_id=t.id) payment_methods FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL AND DATE(t.txn_date) BETWEEN ? AND ?';
    if($q!==''){$sql.=' AND (t.document_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)';$like='%'.$q.'%';array_push($params,$like,$like,$like);}
    $sql.=' ORDER BY t.txn_date DESC,t.id DESC';
    $st=db()->prepare($sql);$st->execute($params);$rows=$st->fetchAll();

    $sumSql='SELECT COALESCE(SUM(t.paid),0),COALESCE(SUM(t.due),0),COALESCE(SUM(t.total),0) FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL AND DATE(t.txn_date) BETWEEN ? AND ?';
    $sumParams=[$cid,$type,$from,$to];
    if($q!==''){$sumSql.=' AND (t.document_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)';array_push($sumParams,'%'.$q.'%','%'.$q.'%','%'.$q.'%');}
    $qsum=db()->prepare($sumSql);$qsum->execute($sumParams);[$paid,$due,$total]=$qsum->fetch(PDO::FETCH_NUM);
    $base=url($type==='sale'?'sales':'purchase');
    $addLabel=$type==='sale'?'Sale':'Purchase';

    page_start($title);
    ?>
    <?php if($type!=='sale'): ?>
    <div class="page-title"><div><h1><?=e($title)?></h1><p><?=e(date('d M Y',strtotime($from)))?> → <?=e(date('d M Y',strtotime($to)))?><?= $q!==''?' · Search: '.e($q):''?></p></div><a class="btn primary" href="<?=e(url($addRoute))?>">⊕ Add <?=e($addLabel)?></a></div>
    <?php endif; ?>
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
          <?php $viewEditUrl=$type==='sale'?url('sale-new?edit='.(int)$r['id']):$base.'?view='.(int)$r['id']; ?>
          <a class="btn small-btn" href="<?=e($viewEditUrl)?>">View / Edit</a>
          <?php if($type==='purchase' && (float)$r['due']>0):?><a class="btn primary small-btn" href="<?=e(url('payment-out?party='.(int)$r['party_id']))?>">PAYMENT OUT</a><?php endif; ?>
          <details class="row-actions"><summary class="dots" aria-label="Actions">⋮</summary>
            <div class="row-menu">
              <?php if($type==='purchase' && (float)$r['due']>0):?><a href="<?=e(url('payment-out?party='.(int)$r['party_id']))?>">Payment Out</a><?php endif; ?>
              <form method="post" onsubmit="return confirm('Delete this transaction? It will move to Recycle Bin.')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Delete</button></form>
              <form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Duplicate</button></form>
              <a href="<?=e($base.'?view='.(int)$r['id'].'&print=1')?>">Open PDF</a><a href="<?=e($base.'?view='.(int)$r['id'].'&print_preview=1')?>">Preview</a><a href="<?=e($base.'?view='.(int)$r['id'].'&print_preview=1')?>">Print</a>
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

    if($_SERVER['REQUEST_METHOD']!=='POST' && isset($_GET['view'])){
        $tid=(int)$_GET['view'];
        $st=$pdo->prepare('SELECT t.*,p.name party_name,p.phone party_phone,p.address party_address FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type="purchase" LIMIT 1');
        $st->execute([$tid,$cid]); $tx=$st->fetch();
        if(!$tx){http_response_code(404);page_start('Purchase Bill Not Found');echo '<div class="panel"><h1>Purchase Bill not found</h1></div>';page_end();exit;}
        ensure_transaction_item_metadata_schema_report($pdo); $itSt=$pdo->prepare('SELECT ti.*,i.name item_name,i.item_type,COALESCE(NULLIF(ti.item_description,""),i.description) item_description,COALESCE(NULLIF(ti.item_warranty,""),i.warranty) item_warranty,u.symbol unit_symbol FROM transaction_items ti JOIN items i ON i.id=ti.item_id LEFT JOIN units u ON u.id=i.unit_id WHERE ti.transaction_id=? ORDER BY ti.id');$itSt->execute([$tid]);$lines=$itSt->fetchAll();
        $pay=$pdo->prepare('SELECT * FROM payment_lines WHERE transaction_id=? ORDER BY id');$pay->execute([$tid]);$payments=$pay->fetchAll();
        $printPaper=setting('print_paper_size','A4',$cid);$printCompanySize=setting('print_company_name_size','medium',$cid);$printInvoiceSize=setting('print_invoice_text_size','medium',$cid);
        $companySt=db()->prepare('SELECT name,email,phone,address,logo_path FROM companies WHERE id=? LIMIT 1');$companySt->execute([$cid]);$companyMetaRow=$companySt->fetch()?:[];
        if(!in_array($printPaper,['A4','A5'],true))$printPaper='A4'; if(!in_array($printCompanySize,['small','medium','large'],true))$printCompanySize='medium'; if(!in_array($printInvoiceSize,['small','medium','large'],true))$printInvoiceSize='medium';
        $printMargin=$printPaper==='A5'?'10mm':'12mm'; $invoiceFont=$printInvoiceSize==='small'?'10px':($printInvoiceSize==='large'?'14px':'12px'); $invoiceSubFont=$printInvoiceSize==='small'?'9px':($printInvoiceSize==='large'?'13px':'11px'); $companyFont=$printCompanySize==='small'?'18px':($printCompanySize==='large'?'30px':'24px');
        page_start('Purchase Bill');
        ?><style>@media print{@page{size:<?=e($printPaper)?>;margin:<?=e($printMargin)?>}.invoice-print{font-size:<?=e($invoiceFont)?>}.invoice-print .invoice-head h2{font-size:<?=e($companyFont)?>}.invoice-print .invoice-head p{font-size:<?=e($invoiceSubFont)?>}.print-company-header{display:block!important}.page-title{display:none!important}.btn,.gear,.topbar,.sidebar{display:none!important}}</style><script>document.title='Purchase Bill · '+<?=json_encode((string)($u['company_name']??''),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;</script>
        <div class="page-title"><div><h1>Purchase Bill <?=e($tx['document_no'])?></h1><p><?=e($tx['txn_date'])?></p></div><div><a class="btn" href="<?=e(url('purchase-new?edit='.(int)$tx['id']))?>">Edit</a><button class="btn primary" type="button" onclick="senseInvoicePreviewOpen()">Print Preview</button><a class="btn" href="<?=e(url('purchase-new'))?>">+ Add Purchase</a></div></div>
        <div class="panel invoice-print"><div class="invoice-head"><div><?php $logo=saas_company_logo_url($u['logo_path']??null); if($logo): ?><img src="<?=e($logo)?>" alt="Company logo" class="print-company-logo" style="max-height:56px;max-width:180px;object-fit:contain;margin-bottom:6px"><br><?php endif; ?><h2><?=e($u['company_name'] ?? '')?></h2><p>Purchase Bill</p></div><div><strong><?=e($tx['document_no'])?></strong><br><?=e(date('d/m/Y',strtotime($tx['txn_date'])))?></div></div>
        <div class="grid3"><div><div class="subtle">Supplier</div><strong><?=e($tx['party_name']??'—')?></strong><div class="subtle"><?=e($tx['party_phone']??'')?></div></div><div><div class="subtle">Total</div><strong><?=money((float)$tx['total'])?></strong></div><div><div class="subtle">Paid / Due</div><strong><?=money((float)$tx['paid'])?> / <?=money((float)$tx['due'])?></strong></div></div>
        <div class="table-wrap" style="margin-top:16px"><table><thead><tr><th>#</th><th>ITEM</th><th>QTY</th><th>PRICE/UNIT</th><th>DISCOUNT</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($lines as $i=>$r): $isBundleChild=(int)($r['bundle_parent_transaction_item_id']??0)>0;?><tr><td><?=$i+1?></td><td><?=e(($isBundleChild?'└─ ':'').$r['item_name'])?></td><td><?=qty((float)$r['qty']).' '.e($r['unit_symbol']??'')?></td><td><?=$isBundleChild?'Free':money((float)$r['unit_price'])?></td><td><?=$isBundleChild?'—':money((float)$r['discount'])?></td><td><?=$isBundleChild?'Free':money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div>
        <div class="invoice-totals"><div>Subtotal <b><?=money((float)$tx['subtotal'])?></b></div><?php if((float)$tx['item_discount']>0):?><div>Item Discount <b><?=money((float)$tx['item_discount'])?></b></div><?php endif;?><?php if((float)$tx['invoice_discount']>0):?><div>Invoice Discount <b><?=money((float)$tx['invoice_discount'])?></b></div><?php endif;?><?php if((float)$tx['tax']>0):?><div>Tax/VAT <b><?=money((float)$tx['tax'])?></b></div><?php endif;?><?php if((float)$tx['direct_expense']>0):?><div>Direct Expense <b><?=money((float)$tx['direct_expense'])?></b></div><?php endif;?><div class="total-line">Total <b><?=money((float)$tx['total'])?></b></div><div>Paid <b><?=money((float)$tx['paid'])?></b></div><div>Due <b><?=money((float)$tx['due'])?></b></div></div>
        <div style="margin-top:16px"><h3>Payments</h3><?php if($payments):?><div class="table-wrap"><table><thead><tr><th>METHOD</th><th>ACCOUNT</th><th>REFERENCE</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($payments as $p):?><tr><td><?=e(ucwords(str_replace('_',' ',$p['method'])))?></td><td><?=e($p['account_name']??'')?></td><td><?=e($p['reference_no']??'')?></td><td><?=money((float)$p['amount'])?></td></tr><?php endforeach;?></tbody></table></div><?php else:?><p class="subtle">No payment recorded.</p><?php endif;?></div>
        <?php if(!empty($tx['notes'])):?><div style="margin-top:16px"><div class="subtle">Notes</div><div><?=nl2br(e($tx['notes']))?></div></div><?php endif;?></div><?php render_invoice_print_preview($tx,$lines,$payments,$u,$printPaper,$printCompanySize,$printInvoiceSize,'Purchase Bill','Supplier',(string)($companyMetaRow['name']??($u['company_name']??'')),(string)($companyMetaRow['email']??''),(string)($companyMetaRow['phone']??''),(string)($companyMetaRow['address']??'')); page_end();exit;
    }

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
        'sales' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,p.name party,t.total,t.paid,t.due,t.status FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="sale" AND t.deleted_at IS NULL AND DATE(t.txn_date) BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'purchase' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,p.name party,t.total,t.paid,t.due,t.status FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="purchase" AND t.deleted_at IS NULL AND DATE(t.txn_date) BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'expenses' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,ed.expense_type type,ed.category,t.total,t.paid,t.due FROM transactions t JOIN expense_details ed ON ed.transaction_id=t.id WHERE t.company_id=? AND t.txn_type="expense" AND t.deleted_at IS NULL AND DATE(t.txn_date) BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'payment_in' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,p.name party,t.total amount,t.status FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="payment_in" AND t.deleted_at IS NULL AND DATE(t.txn_date) BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'payment_out' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,p.name party,t.total amount,t.status FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="payment_out" AND t.deleted_at IS NULL AND DATE(t.txn_date) BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'customer_outstanding' => (function()use($pdo,$cid){$q=$pdo->prepare('SELECT p.name party,p.phone,ROUND(p.opening_balance + COALESCE((SELECT SUM(CASE WHEN t.txn_type="sale" THEN t.due WHEN t.txn_type="payment_in" THEN -t.total WHEN t.txn_type="sale_return" THEN -t.total ELSE 0 END) FROM transactions t WHERE t.company_id=p.company_id AND t.party_id=p.id AND t.deleted_at IS NULL AND t.txn_date<=CURDATE()),0),2) outstanding FROM parties p WHERE p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role="customer") ORDER BY outstanding DESC,p.name');$q->execute([$cid]);return $q->fetchAll();})(),
        'supplier_outstanding' => (function()use($pdo,$cid){$q=$pdo->prepare('SELECT p.name party,p.phone,ROUND(p.opening_balance + COALESCE((SELECT SUM(CASE WHEN t.txn_type="purchase" THEN t.due WHEN t.txn_type="payment_out" THEN -t.total WHEN t.txn_type="purchase_return" THEN -t.total ELSE 0 END) FROM transactions t WHERE t.company_id=p.company_id AND t.party_id=p.id AND t.deleted_at IS NULL AND t.txn_date<=CURDATE()),0),2) outstanding FROM parties p WHERE p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role="supplier") ORDER BY outstanding DESC,p.name');$q->execute([$cid]);return $q->fetchAll();})(),
        'party_ledger' => (function()use($pdo,$cid,$from,$to,$partyId){if(!$partyId)return []; $q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,t.txn_type type,t.total,t.paid,t.due FROM transactions t WHERE t.company_id=? AND t.party_id=? AND t.deleted_at IS NULL AND DATE(t.txn_date) BETWEEN ? AND ? ORDER BY t.txn_date,t.id');$q->execute([$cid,$partyId,$from,$to]);return $q->fetchAll();})(),
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
        'cheque' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,pl.reference_no cheque_no,pl.cheque_date,pl.amount,t.txn_type type,p.name party,pl.status FROM payment_lines pl JOIN transactions t ON t.id=pl.transaction_id LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND pl.method="cheque" AND t.deleted_at IS NULL AND DATE(t.txn_date) BETWEEN ? AND ? ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'loan' => (function()use($pdo,$cid){$q=$pdo->prepare('SELECT name,lender,opening_balance,currency_code,active FROM loan_accounts WHERE company_id=? ORDER BY name');$q->execute([$cid]);return $q->fetchAll();})(),
        'day_book' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.txn_date date,t.document_no document,t.txn_type type,COALESCE(p.name,"") party,t.total,t.paid,t.due,t.status FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.deleted_at IS NULL AND DATE(t.txn_date) BETWEEN ? AND ? ORDER BY t.txn_date,t.id');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'general_ledger' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT entry_date date,account_code code,account_name account,debit,credit,memo,transaction_id FROM ledger_entries WHERE company_id=? AND entry_date BETWEEN ? AND ? ORDER BY entry_date,id');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'trial_balance' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT account_code code,account_name account,ROUND(SUM(debit),2) debit,ROUND(SUM(credit),2) credit,ROUND(SUM(debit-credit),2) balance FROM ledger_entries WHERE company_id=? AND entry_date BETWEEN ? AND ? GROUP BY account_code,account_name ORDER BY account_code');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
        'profit_loss' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT account_code code,account_name account,ROUND(SUM(debit),2) debit,ROUND(SUM(credit),2) credit FROM ledger_entries WHERE company_id=? AND entry_date BETWEEN ? AND ? GROUP BY account_code,account_name ORDER BY account_code');$q->execute([$cid,$from,$to]);$rows=$q->fetchAll();$out=[];foreach($rows as $r){if((string)$r['code'][0]<'4')continue;if(in_array($r['code'],['4000','4200','4300','5100','5200'],true) || (strpos(strtolower($r['account']),'expense')!==false) || strpos(strtolower($r['account']),'rent')!==false || strpos(strtolower($r['account']),'salary')!==false || strpos(strtolower($r['account']),'utilities')!==false || strpos(strtolower($r['account']),'transport')!==false || strpos(strtolower($r['account']),'marketing')!==false)$out[]=$r;}return $out;})(),
        'balance_sheet' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT account_code code,account_name account,ROUND(SUM(debit-credit),2) balance FROM ledger_entries WHERE company_id=? AND entry_date<=? GROUP BY account_code,account_name ORDER BY account_code');$q->execute([$cid,$to]);$rows=$q->fetchAll();$out=[];foreach($rows as $r){$code=(string)$r['code'];if(str_starts_with($code,'1')||str_starts_with($code,'2')||str_starts_with($code,'3'))$out[]=$r;}return $out;})(),
        'accounting_health' => (function()use($pdo,$cid,$from,$to){$q=$pdo->prepare('SELECT t.id,t.txn_date date,t.document_no document,t.txn_type type,ROUND(COALESCE(SUM(le.debit),0),2) debit,ROUND(COALESCE(SUM(le.credit),0),2) credit,ROUND(COALESCE(SUM(le.debit),0)-COALESCE(SUM(le.credit),0),2) difference FROM transactions t LEFT JOIN ledger_entries le ON le.transaction_id=t.id WHERE t.company_id=? AND t.deleted_at IS NULL AND DATE(t.txn_date) BETWEEN ? AND ? GROUP BY t.id,t.txn_date,t.document_no,t.txn_type HAVING ABS(difference)>0.01 ORDER BY t.txn_date DESC,t.id DESC');$q->execute([$cid,$from,$to]);return $q->fetchAll();})(),
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
            $date=transaction_datetime($_POST['txn_date']??null);$doc=trim($_POST['document_no']??'');$pdo=db();$pdo->beginTransaction();
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
        ?><div class="panel print-company-header" style="margin-bottom:14px"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px"><div><?php $logo=saas_company_logo_url($u['logo_path']??null); if($logo): ?><img src="<?=e($logo)?>" alt="Company logo" style="max-height:56px;max-width:180px;object-fit:contain;margin-bottom:6px"><br><?php endif; ?><h2 style="margin:0"><?=e($u['company_name']??'')?></h2><div class="subtle">Payment Receipt</div></div><div style="text-align:right"><strong><?=e($tx['document_no'])?></strong><br><?=e(date('d/m/Y',strtotime($tx['txn_date'])))?></div></div></div><div class="page-title"><div><h1>Payment-Out <?=e($tx['document_no'])?></h1><p><?=e($tx['txn_date'])?> · <?=e($tx['party_name'])?></p></div><div><button class="btn" onclick="window.print()">Print</button><a class="btn primary" href="<?=e(url('payment-out'))?>">+ New Payment</a></div></div>
        <div class="cards-top"><div class="metric-card"><div class="label">Paid</div><div class="value"><?=money((float)$tx['total'])?></div></div><div class="metric-card"><div class="label">Supplier</div><div class="value" style="font-size:20px"><?=e($tx['party_name'])?></div></div></div>
        <div class="panel"><div class="panel-head"><h2>Payment Details</h2></div><div class="table-wrap"><table><thead><tr><th>METHOD</th><th>ACCOUNT</th><th>REFERENCE</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($payments as $r):?><tr><td><?=e(ucwords(str_replace('_',' ',$r['method'])))?></td><td><?=e($r['account_name']??'-')?></td><td><?=e($r['reference_no']??'-')?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div></div><?php page_end();exit;}
    }
    $st=db()->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="payment_out" AND t.deleted_at IS NULL ORDER BY t.id DESC LIMIT 50');$st->execute([$cid]);$rows=$st->fetchAll();
    ?><div class="page-title"><div><h1>Payment Out</h1><p>Make payments to suppliers</p></div><a class="btn primary" href="#newPayment">⊕ Add Payment-Out</a></div>
    <div class="panel" id="newPayment"><div class="panel-head"><h2>New Payment-Out</h2><span class="subtle">Multiple payment methods allowed</span></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="entry-top"><div><div class="form-group"><label>Supplier*</label><?php party_search_field('Supplier','supplier',0,'',''); ?></div><div class="subtle" id="partyDue" style="margin-top:6px">Select a supplier to see outstanding due.</div></div><div><div class="form-group"><label>Payment Number</label><input name="document_no" placeholder="Auto: PO-01"></div><div class="form-group"><label>Date*</label><input type="date" name="txn_date" value="<?=date('Y-m-d')?>" required></div></div><div class="entry-right"><div class="right-card"><div class="title">CURRENT DUE</div><div class="value" id="currentDue">৳0.00</div></div></div></div>
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
            $date=transaction_datetime($_POST['txn_date']??null);
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
      <div class="entry-top standard-entry-top return-entry-top"><div class="form-group"><label>Original <?=ucfirst($baseType)?>*</label><select name="source_id" id="returnSource" required><option value="">Select original <?=e($baseType)?></option><?php foreach($sources as $s):?><option value="<?=$s['id']?>" data-party="<?=e($s['party_name']??'')?>" data-total="<?=e((string)$s['total'])?>"><?=e($s['document_no'])?> · <?=e($s['party_name']??'')?> · <?=e(date('d/m/Y',strtotime($s['txn_date'])))?> · <?=money((float)$s['total'])?></option><?php endforeach;?></select></div><div class="form-group"><label>Return Number</label><input name="document_no" placeholder="Auto: <?=$prefix?>01"></div><div class="form-group"><label>Return Date*</label><input type="date" name="txn_date" value="<?=date('Y-m-d')?>" required></div></div>
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


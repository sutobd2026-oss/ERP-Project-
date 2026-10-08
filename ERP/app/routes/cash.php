<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
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
                $date=transaction_datetime($_POST['adjustment_date']??null);
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
    /* v151: Deposit/Withdraw popup redesign — scoped to the Cash page. */
    .cash-head-actions-v150{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
    .cash-move-btn-v150{
      min-width:88px;min-height:38px;padding:9px 15px!important;
      border:1px solid transparent!important;border-radius:8px!important;
      font-size:13px!important;font-weight:700!important;line-height:1!important;
      box-shadow:0 2px 6px rgba(15,23,42,.08);cursor:pointer;
    }
    .cash-deposit-v150{background:#10b981!important;color:#fff!important;border-color:#10b981!important}
    .cash-withdraw-v150{background:#ef6b73!important;color:#fff!important;border-color:#ef6b73!important}
    .cash-move-btn-v150:hover{filter:brightness(.98);transform:translateY(-1px)}
    .cash-transfer-modal-v151{padding:18px!important}
    .cash-transfer-modal-v151 .cash-transfer-box-v151{
      width:min(560px,100%);
      max-height:min(88vh,680px);
      display:flex;flex-direction:column;
      background:#fff;border:1px solid #e2e8f0;border-radius:14px;
      box-shadow:0 24px 70px rgba(15,23,42,.24);
      overflow:hidden;
    }
    .cash-transfer-head-v151{
      display:flex;align-items:center;justify-content:space-between;
      gap:16px;padding:18px 22px;
      border-bottom:1px solid #edf1f5;background:#fff;
    }
    .cash-transfer-title-wrap-v151{display:flex;align-items:center;gap:11px;min-width:0}
    .cash-transfer-icon-v151{
      width:34px;height:34px;display:grid;place-items:center;flex:0 0 34px;
      border-radius:9px;font-size:17px;font-weight:800;
      background:#ecfdf5;color:#059669;
    }
    .cash-transfer-modal-v151.cash-transfer-withdraw-v151 .cash-transfer-icon-v151{
      background:#fff1f2;color:#e11d48;
    }
    .cash-transfer-head-v151 h2{
      margin:0;font-size:18px;line-height:1.2;font-weight:700;color:#1f3445;
    }
    .cash-transfer-subtitle-v151{
      margin-top:3px;font-size:11px;line-height:1.35;color:#8a97a6;
      white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    }
    .cash-transfer-close-v151{
      width:34px!important;height:34px!important;min-width:34px!important;
      border:0!important;border-radius:8px!important;background:transparent!important;
      color:#64748b!important;font-size:24px!important;line-height:1!important;
      padding:0!important;cursor:pointer;
    }
    .cash-transfer-close-v151:hover{background:#f1f5f9!important;color:#334155!important}
    .cash-transfer-body-v151{padding:22px}
    .cash-transfer-grid-v151{display:grid;grid-template-columns:1fr 1fr;gap:16px 18px}
    .cash-transfer-field-v151{min-width:0}
    .cash-transfer-field-v151 label{
      display:block;margin:0 0 6px;font-size:11px;font-weight:600;
      color:#637387;letter-spacing:.1px;
    }
    .cash-transfer-field-v151 select,
    .cash-transfer-field-v151 input{
      width:100%;height:42px;box-sizing:border-box;
      border:1px solid #d8e0e8;border-radius:8px;background:#fff;
      color:#24384a;padding:0 11px;outline:0;font-size:13px;
      transition:border-color .15s,box-shadow .15s,background .15s;
    }
    .cash-transfer-field-v151 select:focus,
    .cash-transfer-field-v151 input:focus{
      border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.10);
    }
    .cash-transfer-field-v151 select:disabled,
    .cash-transfer-field-v151 input:disabled{
      background:#f8fafc;color:#8190a1;cursor:not-allowed;
    }
    .cash-transfer-field-v151 input::placeholder{color:#a0acba}
    .cash-transfer-full-v151{grid-column:1/-1}
    .cash-transfer-help-v151{margin-top:5px;font-size:10px;color:#9aa6b4}
    .cash-transfer-foot-v151{
      padding:14px 22px 18px;border-top:1px solid #edf1f5;
      display:flex;justify-content:flex-end;gap:8px;background:#fff;
    }
    .cash-transfer-secondary-v151,
    .cash-transfer-primary-v151{
      min-width:92px;height:38px;padding:0 15px;border-radius:8px;
      font-size:13px;font-weight:600;cursor:pointer;
    }
    .cash-transfer-secondary-v151{
      border:1px solid #d7e0e8;background:#fff;color:#475569;
    }
    .cash-transfer-secondary-v151:hover{background:#f8fafc}
    .cash-transfer-primary-v151{
      border:1px solid #10b981;background:#10b981;color:#fff;
      box-shadow:0 2px 6px rgba(16,185,129,.18);
    }
    .cash-transfer-primary-v151:hover{filter:brightness(.98)}
    .cash-transfer-modal-v151.cash-transfer-withdraw-v151 .cash-transfer-primary-v151{
      border-color:#ef6b73;background:#ef6b73;box-shadow:0 2px 6px rgba(239,107,115,.18);
    }
    @media(max-width:620px){
      .cash-transfer-modal-v151{padding:12px!important}
      .cash-transfer-modal-v151 .cash-transfer-box-v151{max-height:94vh;border-radius:12px}
      .cash-transfer-head-v151,.cash-transfer-body-v151{padding-left:16px;padding-right:16px}
      .cash-transfer-grid-v151{grid-template-columns:1fr;gap:13px}
      .cash-transfer-full-v151{grid-column:auto}
      .cash-transfer-foot-v151{padding-left:16px;padding-right:16px}
      .cash-transfer-subtitle-v151{max-width:240px}
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

      <div class="bank-modal-v122 bank-transfer-modal-v147 cash-transfer-modal-v151" id="cashBankTransferModalV150" onclick="if(event.target===this)closeCashBankTransferV150()" aria-hidden="true"><div class="cash-transfer-box-v151"><div class="cash-transfer-head-v151"><div class="cash-transfer-title-wrap-v151"><span class="cash-transfer-icon-v151" id="cashBankTransferIconV150">↓</span><div><h2 id="cashBankTransferTitleV150">Deposit</h2><div class="cash-transfer-subtitle-v151" id="cashBankTransferSubtitleV150">Move money from Cash In Hand to a bank account.</div></div></div><button type="button" class="cash-transfer-close-v151" onclick="closeCashBankTransferV150()">×</button></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="bank_to_bank_transfer"><input type="hidden" name="from_account" id="cashBankFromHiddenV150" value="cash"><input type="hidden" name="to_account" id="cashBankToHiddenV150" value=""><div class="cash-transfer-body-v151"><div class="cash-transfer-grid-v151"><div class="cash-transfer-field-v151"><label>From</label><select id="cashBankFromV150" required disabled><option value="cash">Cash In Hand</option><?php foreach($cashBanks as $b): ?><option value="bank:<?=e((string)$b['id'])?>"><?=e($b['name'])?></option><?php endforeach; ?></select></div><div class="cash-transfer-field-v151"><label>To</label><select id="cashBankToV150" required><option value="">Select bank account</option><?php foreach($cashBanks as $b): ?><option value="bank:<?=e((string)$b['id'])?>"><?=e($b['name'])?></option><?php endforeach; ?></select></div><div class="cash-transfer-field-v151"><label>Amount</label><input type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required onwheel="this.blur()"></div><div class="cash-transfer-field-v151"><label>Adjustment Date</label><input type="date" name="adjustment_date" value="<?=e(date('Y-m-d'))?>" required></div><div class="cash-transfer-field-v151 cash-transfer-full-v151"><label>Add Description</label><input type="text" name="notes" maxlength="255" placeholder="Optional description"><div class="cash-transfer-help-v151">Optional note for this cash transfer.</div></div></div></div><div class="cash-transfer-foot-v151"><button type="button" class="cash-transfer-secondary-v151" onclick="closeCashBankTransferV150()">Cancel</button><button class="cash-transfer-primary-v151" id="cashBankTransferSaveV150">Deposit</button></div></form></div></div>

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

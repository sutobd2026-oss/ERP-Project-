<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='cheques'){
    $u=require_login();$cid=(int)$u['company_id'];page_start('Cheques');$q=db()->prepare("SELECT pl.*,t.document_no,t.txn_date,t.txn_type FROM payment_lines pl JOIN transactions t ON t.id=pl.transaction_id WHERE t.company_id=? AND pl.method='cheque' AND t.deleted_at IS NULL ORDER BY pl.id DESC");$q->execute([$cid]);$rows=$q->fetchAll();?><div class="page-title"><div><h1>Cheques</h1><p>Cheque receipts and payments</p></div></div><div class="panel table-wrap"><table><thead><tr><th>DATE</th><th>DOCUMENT</th><th>TYPE</th><th>CHEQUE NO.</th><th>CHEQUE DATE</th><th>AMOUNT</th><th>STATUS</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e($r['txn_date'])?></td><td><?=e($r['document_no'])?></td><td><?=e(str_replace('_',' ',ucfirst($r['txn_type'])))?></td><td><?=e($r['reference_no']??'-')?></td><td><?=e($r['cheque_date']??'-')?></td><td><?=money((float)$r['amount'])?></td><td><?=e(ucfirst($r['status']))?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="7" class="subtle">No cheques found.</td></tr><?php endif;?></tbody></table></div><?php page_end();exit;
}

if($route==='loans'){
    $u=require_login();$cid=(int)$u['company_id'];
    if($_SERVER['REQUEST_METHOD']==='POST'){check_csrf();try{$name=trim($_POST['name']??'');$lender=trim($_POST['lender']??'');$opening=(float)($_POST['opening_balance']??0);if($name==='')throw new RuntimeException('Loan account name is required.');db()->prepare('INSERT INTO loan_accounts(company_id,name,lender,opening_balance,currency_code,active) VALUES(?,?,?,?,?,1)')->execute([$cid,$name,$lender,$opening,$u['currency_code']]);flash('success','Loan account added.');}catch(Throwable $e){flash('error',$e->getMessage());}redirect('loans');}
    page_start('Loan Accounts');$q=db()->prepare('SELECT * FROM loan_accounts WHERE company_id=? AND active=1 ORDER BY name');$q->execute([$cid]);$loans=$q->fetchAll();?><div class="page-title"><div><h1>Loan Accounts</h1><p>Track loans received by the business</p></div><button class="btn primary" onclick="openModal('loanModal')">⊕ Add Loan Account</button></div><div class="panel table-wrap"><table><thead><tr><th>NAME</th><th>LENDER</th><th>OPENING BALANCE</th><th>STATUS</th></tr></thead><tbody><?php foreach($loans as $r):?><tr><td><?=e($r['name'])?></td><td><?=e($r['lender']??'')?></td><td><?=money((float)$r['opening_balance'])?></td><td>Active</td></tr><?php endforeach;if(!$loans):?><tr><td colspan="4" class="subtle">No loan accounts yet.</td></tr><?php endif;?></tbody></table></div><div class="modal-backdrop" id="loanModal" onclick="if(event.target===this)closeModal('loanModal')"><div class="modal"><div class="modal-head"><h2>Add Loan Account</h2><button class="close" onclick="closeModal('loanModal')">×</button></div><form method="post"><div class="form-body"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><div class="grid2"><div class="form-group"><label>Loan Account Name*</label><input name="name" required></div><div class="form-group"><label>Lender</label><input name="lender"></div><div class="form-group"><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="0"></div></div></div><div class="form-footer"><button type="button" class="btn" onclick="closeModal('loanModal')">Cancel</button><button class="btn primary">Save</button></div></form></div></div><?php page_end();exit;
}

if($route==='bank-accounts'){
    $u=require_login();
    $cid=(int)$u['company_id'];
    $pdo=db();
    $selectedId=(int)($_GET['select']??0);
    $editId=(int)($_GET['edit']??0);
    $search=trim((string)($_GET['q']??''));

    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $action=(string)($_POST['action']??'');
        try{
            if($action==='save_bank_account'){
                $name=trim((string)($_POST['name']??''));
                $bankName=trim((string)($_POST['bank_name']??''));
                $accountNo=trim((string)($_POST['account_number']??''));
                $opening=(float)($_POST['opening_balance']??0);
                if($name==='') throw new RuntimeException('Account name is required.');
                $pdo->prepare('INSERT INTO bank_accounts(company_id,name,bank_name,account_number,opening_balance,currency_code,active) VALUES(?,?,?,?,?,?,1)')->execute([$cid,$name,$bankName,$accountNo,$opening,$u['currency_code']]);
                $newId=(int)$pdo->lastInsertId();
                audit('create','bank_account',$newId,['name'=>$name,'bank_name'=>$bankName,'account_number'=>$accountNo,'opening_balance'=>$opening]);
                flash('success','Bank account added successfully.');
                redirect('bank-accounts?select='.$newId);
            }
            if($action==='update_bank_account'){
                $id=(int)($_POST['id']??0);
                $name=trim((string)($_POST['name']??''));
                $bankName=trim((string)($_POST['bank_name']??''));
                $accountNo=trim((string)($_POST['account_number']??''));
                $opening=(float)($_POST['opening_balance']??0);
                if($id<=0||$name==='') throw new RuntimeException('Invalid bank account.');
                $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? LIMIT 1');
                $st->execute([$id,$cid]); $old=$st->fetch();
                if(!$old) throw new RuntimeException('Bank account not found.');
                $usage=$pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE company_id=? AND account_name=?');
                $usage->execute([$cid,'Bank - '.$old['name']]);
                $hasLedger=(int)$usage->fetchColumn()>0;
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE bank_accounts SET name=?,bank_name=?,account_number=?,opening_balance=?,currency_code=? WHERE id=? AND company_id=?')->execute([$name,$bankName,$accountNo,$opening,$u['currency_code'],$id,$cid]);
                if($hasLedger && $old['name']!==$name){
                    $pdo->prepare('UPDATE ledger_entries SET account_name=? WHERE company_id=? AND account_name=?')->execute(['Bank - '.$name,$cid,'Bank - '.$old['name']]);
                    $pdo->prepare('UPDATE payment_lines SET account_name=? WHERE account_name=? AND transaction_id IN (SELECT id FROM transactions WHERE company_id=?)')->execute([$name,$old['name'],$cid]);
                }
                $pdo->commit();
                audit('update','bank_account',$id,['name'=>$name,'bank_name'=>$bankName,'account_number'=>$accountNo,'opening_balance'=>$opening,'renamed'=>($old['name']!==$name)]);
                flash('success','Bank account updated successfully.');
                redirect('bank-accounts?select='.$id);
            }
            if($action==='delete_bank_account'){
                $id=(int)($_POST['id']??0);
                $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? LIMIT 1');$st->execute([$id,$cid]);$bank=$st->fetch();
                if(!$bank) throw new RuntimeException('Bank account not found.');
                $use=$pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE company_id=? AND account_name=?');$use->execute([$cid,'Bank - '.$bank['name']]);
                $ledgerUsed=(int)$use->fetchColumn();
                $use2=$pdo->prepare('SELECT COUNT(*) FROM payment_lines pl JOIN transactions t ON t.id=pl.transaction_id WHERE t.company_id=? AND pl.account_name=?');$use2->execute([$cid,$bank['name']]);
                $paymentUsed=(int)$use2->fetchColumn();
                if($ledgerUsed>0||$paymentUsed>0) throw new RuntimeException('This bank account has transactions and cannot be deleted.');
                $pdo->prepare('DELETE FROM bank_accounts WHERE id=? AND company_id=?')->execute([$id,$cid]);
                audit('delete','bank_account',$id,['name'=>$bank['name']]);
                flash('success','Bank account deleted.');
                redirect('bank-accounts');
            }
            if($action==='bank_cash_move'){
                $id=(int)($_POST['bank_id']??0);
                $direction=(string)($_POST['direction']??'deposit');
                $amount=round((float)($_POST['amount']??0),2);
                $date=transaction_datetime($_POST['txn_date']??null);
                $notes=trim((string)($_POST['notes']??''));
                if(!in_array($direction,['deposit','withdraw'],true)) throw new RuntimeException('Invalid transaction type.');
                if($amount<=0) throw new RuntimeException('Amount must be greater than zero.');
                $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$st->execute([$id,$cid]);$bank=$st->fetch();
                if(!$bank) throw new RuntimeException('Bank account not found.');
                if($direction==='withdraw' && company_cash_balance($cid)+0.01 < $amount) throw new RuntimeException('Cash in Hand is not sufficient for this withdrawal.');
                $pdo->beginTransaction();
                $type=$direction==='deposit'?'bank_deposit':'bank_withdraw';
                $prefix=$direction==='deposit'?'BD-':'BW-';
                $doc=next_document_in_transaction($pdo,$cid,$type,$prefix);
                $label=$direction==='deposit'?'Cash Deposit to '.$bank['name']:'Cash Withdrawal from '.$bank['name'];
                $pdo->prepare('INSERT INTO transactions(company_id,txn_type,document_no,txn_date,due_date,subtotal,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$type,$doc,$date,null,$amount,$amount,$amount,0,$u['currency_code'],'final',$label.($notes?' · '.$notes:''),$u['id']]);
                $tid=(int)$pdo->lastInsertId();
                if($direction==='deposit'){
                    post_ledger($pdo,$cid,$tid,$date,[['1010','Bank - '.$bank['name'],$amount,0,$doc],['1000','Cash In Hand',0,$amount,$doc]]);
                }else{
                    post_ledger($pdo,$cid,$tid,$date,[['1000','Cash In Hand',$amount,0,$doc],['1010','Bank - '.$bank['name'],0,$amount,$doc]]);
                }
                audit('create','transaction',$tid,['type'=>$type,'bank_id'=>$id,'bank'=>$bank['name'],'amount'=>$amount,'document'=>$doc]);
                $pdo->commit();
                flash('success',($direction==='deposit'?'Deposit':'Withdraw').' recorded as '.$doc.'.');
                redirect('bank-accounts?select='.$id);
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
                    $fromType='bank'; $fromId=(int)substr($fromRef,5);
                    $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$st->execute([$fromId,$cid]);$from=$st->fetch(PDO::FETCH_ASSOC);
                    if(!$from) throw new RuntimeException('Source bank account not found.');
                    $fromName=$from['name'];
                }elseif($fromRef!=='cash'){
                    throw new RuntimeException('Invalid source account.');
                }
                if(strpos($toRef,'bank:')===0){
                    $toType='bank'; $toId=(int)substr($toRef,5);
                    $st=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$st->execute([$toId,$cid]);$to=$st->fetch(PDO::FETCH_ASSOC);
                    if(!$to) throw new RuntimeException('Destination bank account not found.');
                    $toName=$to['name'];
                }elseif($toRef!=='cash'){
                    throw new RuntimeException('Invalid destination account.');
                }
                if($fromType==='bank' && $toType==='bank' && $fromId===$toId) throw new RuntimeException('Source and destination accounts must be different.');

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
                $lines=[];
                $toCode=$toType==='cash' ? '1000' : '1010';
                $toLabel=$toType==='cash' ? 'Cash In Hand' : 'Bank - '.$toName;
                $fromCode=$fromType==='cash' ? '1000' : '1010';
                $fromLabel=$fromType==='cash' ? 'Cash In Hand' : 'Bank - '.$fromName;
                $lines[] = [$toCode,$toLabel,$amount,0,'From: '.$fromName.' · '.$doc];
                $lines[] = [$fromCode,$fromLabel,0,$amount,'To: '.$toName.' · '.$doc];
                post_ledger($pdo,$cid,$tid,$date,$lines);
                audit('create','transaction',$tid,['type'=>$type,'from'=>$fromRef,'to'=>$toRef,'from_name'=>$fromName,'to_name'=>$toName,'amount'=>$amount,'document'=>$doc]);
                $pdo->commit();
                flash('success','Transfer recorded as '.$doc.'.');
                redirect('bank-accounts?select='.($selectedId?:$fromId));
            }
            throw new RuntimeException('Unsupported action.');
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error',$e->getMessage());
            redirect('bank-accounts'.($selectedId?'?select='.$selectedId:''));
        }
    }

    $banksQ=$pdo->prepare('SELECT * FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY name');
    $banksQ->execute([$cid]); $banks=$banksQ->fetchAll(PDO::FETCH_ASSOC);
    if(!$banks){
        $selectedId=0;
    }elseif($selectedId<=0 || !array_filter($banks,fn($b)=>(int)$b['id']===$selectedId)){
        $selectedId=(int)$banks[0]['id'];
    }
    $selected=null;
    foreach($banks as $b){if((int)$b['id']===$selectedId){$selected=$b;break;}}

    $editBank=null;
    if($editId>0){
        $q=$pdo->prepare('SELECT * FROM bank_accounts WHERE id=? AND company_id=? AND active=1 LIMIT 1');$q->execute([$editId,$cid]);$editBank=$q->fetch(PDO::FETCH_ASSOC)?:null;
    }

    $transactions=[];
    if($selected){
        $sql='SELECT le.id,le.entry_date,le.debit,le.credit,le.memo,t.document_no,t.txn_type,t.txn_date,t.notes,p.name party_name
              FROM ledger_entries le
              JOIN transactions t ON t.id=le.transaction_id AND t.company_id=le.company_id
              LEFT JOIN parties p ON p.id=t.party_id
              WHERE le.company_id=? AND le.account_name=? AND t.deleted_at IS NULL';
        $args=[$cid,'Bank - '.$selected['name']];
        if($search!==''){
            $sql.=' AND (t.document_no LIKE ? OR t.txn_type LIKE ? OR COALESCE(p.name,"") LIKE ? OR COALESCE(t.notes,"") LIKE ? OR COALESCE(le.memo,"") LIKE ?)';
            $like='%'.$search.'%'; array_push($args,$like,$like,$like,$like,$like);
        }
        $sql.=' ORDER BY le.entry_date DESC, le.id DESC LIMIT 500';
        $q=$pdo->prepare($sql);$q->execute($args);$transactions=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    $selectedBalance=$selected?bank_balance($cid,(int)$selected['id']):0.0;

    $viewCount=[];
    foreach($banks as $b){
        $q=$pdo->prepare('SELECT COUNT(*) FROM ledger_entries WHERE company_id=? AND account_name=?');$q->execute([$cid,'Bank - '.$b['name']]);
        $ledgerCount=(int)$q->fetchColumn();
        $q=$pdo->prepare('SELECT COUNT(*) FROM payment_lines pl JOIN transactions t ON t.id=pl.transaction_id WHERE t.company_id=? AND pl.account_name=?');$q->execute([$cid,$b['name']]);
        $viewCount[(int)$b['id']]=$ledgerCount+(int)$q->fetchColumn();
    }

    page_start('Bank Account');
    ?>
    <style>
      .bank-page-v122{height:calc(100vh - 80px);min-height:520px;display:grid;grid-template-columns:420px minmax(0,1fr);gap:10px;overflow:hidden;margin:-1px -2px -2px}
      .bank-left-v122,.bank-right-v122{background:#fff;border:1px solid #dfe5ec;min-width:0;min-height:0;box-shadow:0 1px 4px rgba(20,33,48,.06)}
      .bank-left-v122{display:flex;flex-direction:column;overflow:hidden}
      .bank-left-top-v122{padding:16px 14px;border-bottom:1px solid #e4e8ed;display:flex;justify-content:flex-end;align-items:center;background:#fff}
      .bank-add-v122{background:#f7a61a;border:1px solid #f7a61a;color:#fff;border-radius:22px;padding:10px 16px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px}
      .bank-add-v122:hover{filter:brightness(.98)}
      .bank-list-head-v122{display:grid;grid-template-columns:1fr 110px 30px;padding:11px 14px;background:#f8fafc;border-bottom:1px solid #dfe5ec;color:#596779;font-size:12px;font-weight:700}
      .bank-list-v122{overflow:auto;min-height:0}
      .bank-row-v122{display:grid;grid-template-columns:1fr 110px 30px;align-items:center;padding:14px 12px;border-bottom:1px solid #e8edf2;min-height:72px;cursor:pointer;position:relative}
      .bank-row-v122:hover{background:#f7fbff}.bank-row-v122.active{background:#dff3ff;box-shadow:inset 3px 0 0 #1683ea}
      .bank-main-v122{display:flex;gap:12px;align-items:center;min-width:0}.bank-icon-v122{width:36px;height:36px;border-radius:50%;background:#e8f3ff;color:#1683ea;display:grid;place-items:center;font-size:16px;flex:0 0 36px}
      .bank-name-v122{font-size:14px;font-weight:600;color:#27313f;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.bank-sub-v122{font-size:12px;color:#8a96a6;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
      .bank-amount-v122{text-align:right;color:#10b981;font-size:13px;font-weight:600}.bank-menu-wrap-v122{position:relative;text-align:right}.bank-dot-v122{border:0;background:transparent;color:#687588;font-size:21px;line-height:1;cursor:pointer;padding:4px 3px}
      .bank-menu-v122{display:none;position:absolute;right:0;top:32px;width:150px;background:#fff;border:1px solid #d8dee7;border-radius:6px;box-shadow:0 10px 24px rgba(20,33,48,.16);z-index:100}.bank-menu-v122.show{display:block}
      .bank-menu-v122 a,.bank-menu-v122 button{display:block;width:100%;box-sizing:border-box;padding:10px 12px;border:0;background:#fff;text-align:left;color:#334155;text-decoration:none;font-size:13px;cursor:pointer}.bank-menu-v122 a:hover,.bank-menu-v122 button:hover{background:#f4f8fb}.bank-menu-v122 .danger{color:#dc2626}.bank-menu-v122 .disabled{color:#a3acb8;cursor:default}
      .bank-right-v122{display:flex;flex-direction:column;overflow:hidden}
      .bank-detail-head-v122{padding:18px 22px;border-bottom:1px solid #dfe5ec;box-shadow:0 1px 5px rgba(20,33,48,.07);display:grid;grid-template-columns:minmax(0,1fr) auto;gap:20px;align-items:center}
      .bank-detail-grid-v122{display:grid;grid-template-columns:repeat(2,minmax(140px,1fr));gap:8px 34px}.bank-detail-label-v122{color:#8a96a6;font-size:12px}.bank-detail-value-v122{font-size:14px;color:#27313f;font-weight:600;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .bank-detail-actions-v122{display:flex;flex-direction:column;align-items:flex-end;gap:12px}.bank-balance-v122{text-align:right}.bank-balance-label-v122{font-size:12px;color:#8a96a6}.bank-balance-value-v122{font-size:20px;font-weight:700;color:#10b981;margin-top:3px}
      .bank-move-wrap-v122{position:relative}.bank-move-btn-v122{border:0;background:#168fe9;color:#fff;border-radius:7px;padding:10px 14px;font-weight:600;cursor:pointer}.bank-action-split-v147{position:relative;display:inline-flex;align-items:stretch}.bank-move-main-v147{border-radius:7px 0 0 7px;padding-left:16px;padding-right:16px}.bank-move-caret-v147{border:0;border-left:1px solid rgba(255,255,255,.28);background:#168fe9;color:#fff;padding:0 10px;border-radius:0 7px 7px 0;cursor:pointer;font-size:13px}.bank-move-caret-v147:hover,.bank-move-main-v147:hover{filter:brightness(.98)}.bank-move-menu-v122{display:none;position:absolute;right:0;top:42px;width:170px;background:#fff;border:1px solid #d8dee7;border-radius:6px;box-shadow:0 10px 24px rgba(20,33,48,.16);z-index:100;overflow:hidden}.bank-move-menu-v122.show{display:block}.bank-move-menu-v122 button{width:100%;border:0;background:#fff;text-align:left;padding:11px 13px;cursor:pointer;font-size:13px}.bank-move-menu-v122 button:hover{background:#f4f8fb}
      .bank-action-pair-v149{display:inline-flex;gap:8px;align-items:center}.bank-action-pair-v149 .bank-move-btn-v122{border:0;color:#fff;background:#168fe9;border-radius:7px;padding:10px 18px;font-weight:600;cursor:pointer}.bank-action-pair-v149 .bank-move-btn-v122:hover{filter:brightness(.97)}.bank-action-pair-v149 .bank-withdraw-v149{background:#168fe9}.bank-action-pair-v149 .bank-deposit-v149{background:#168fe9}

      .bank-tx-v122{display:flex;flex-direction:column;min-height:0;flex:1}.bank-tx-head-v122{padding:16px 12px 10px 12px;display:flex;align-items:center;justify-content:space-between;gap:10px}.bank-tx-title-v122{font-size:16px;font-weight:700;color:#334155}.bank-tx-search-v122{width:min(300px,45%);border:1px solid #d5dce5;border-radius:4px;height:34px;padding:0 10px;outline:0}.bank-tx-search-v122:focus{border-color:#1683ea;box-shadow:0 0 0 2px rgba(22,131,234,.08)}
      .bank-tx-scroll-v122{overflow:auto;min-height:0;flex:1;border-top:1px solid #e2e8ef}.bank-tx-table-v122{width:100%;min-width:680px;border-collapse:collapse}.bank-tx-table-v122 th,.bank-tx-table-v122 td{padding:11px 10px;border-bottom:1px solid #edf1f4;font-size:13px;white-space:nowrap}.bank-tx-table-v122 th{position:sticky;top:0;background:#fafbfd;color:#657286;font-size:11px;text-align:left;z-index:2}.bank-tx-table-v122 tbody tr:hover{background:#f8fbfe}.bank-in-v122{color:#10b981;font-weight:700}.bank-out-v122{color:#ef4444;font-weight:700}.bank-empty-v122{padding:34px;text-align:center;color:#8a96a6}
      .bank-modal-v122{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:900;align-items:flex-start;justify-content:center;padding:80px 18px 20px}.bank-modal-v122.show{display:flex}.bank-modal-box-v122{width:min(560px,100%);background:#fff;border-radius:9px;box-shadow:0 18px 55px rgba(15,23,42,.25);overflow:hidden}.bank-modal-head-v122{display:flex;justify-content:space-between;align-items:center;padding:15px 18px;border-bottom:1px solid #e3e8ee}.bank-modal-head-v122 h2{font-size:17px;margin:0;color:#27313f}.bank-modal-close-v122{border:0;background:none;font-size:24px;color:#7c8795;cursor:pointer}.bank-modal-body-v122{padding:18px}.bank-form-grid-v122{display:grid;grid-template-columns:1fr 1fr;gap:12px 16px}.bank-form-field-v122 label{display:block;font-size:12px;color:#657286;margin-bottom:6px}.bank-form-field-v122 input{width:100%;height:40px;border:1px solid #d5dce5;border-radius:5px;padding:0 10px;box-sizing:border-box;outline:0}.bank-form-field-v122 input:focus{border-color:#1683ea}.bank-form-span-v122{grid-column:1/-1}.bank-modal-foot-v122{padding:10px 18px;border-top:1px solid #e3e8ee;display:flex;justify-content:flex-end;gap:8px}.bank-secondary-v122,.bank-primary-v122{border-radius:6px;padding:9px 15px;cursor:pointer;border:1px solid #d5dce5;background:#fff;color:#334155}.bank-primary-v122{background:#1683ea;color:#fff;border-color:#1683ea}
      .bank-transfer-modal-v147{padding-top:54px}.bank-transfer-box-v147{width:min(550px,100%);background:#fff;border-radius:6px;box-shadow:0 18px 55px rgba(15,23,42,.28);overflow:hidden}.bank-transfer-head-v147{display:flex;justify-content:space-between;align-items:center;padding:17px 20px;border-bottom:1px solid #e6eaf0}.bank-transfer-head-v147 h2{font-size:18px;font-weight:500;margin:0;color:#315367}.bank-transfer-body-v147{padding:22px 32px 18px}.bank-transfer-grid-v147{display:grid;grid-template-columns:1fr 1fr;gap:20px 24px}.bank-transfer-field-v147{position:relative;min-width:0}.bank-transfer-field-v147 label{display:block;font-size:12px;color:#68768a;margin:0 0 6px}.bank-transfer-field-v147 select,.bank-transfer-field-v147 input{width:100%;height:38px;box-sizing:border-box;border:1px solid #d5dbe4;border-radius:5px;background:#fff;color:#27313f;padding:0 10px;outline:0;font-size:14px}.bank-transfer-field-v147 select:focus,.bank-transfer-field-v147 input:focus{border-color:#1683ea;box-shadow:0 0 0 2px rgba(22,131,234,.08)}.bank-transfer-field-v147 input::placeholder{color:#9aa6b6}.bank-transfer-full-v147{grid-column:1/-1}.bank-transfer-foot-v147{padding:10px 32px 16px;display:flex;justify-content:flex-end;gap:8px}.bank-transfer-box-v147 form{margin:0}.bank-transfer-box-v147 .bank-primary-v122{min-width:110px}.bank-transfer-box-v147 .bank-secondary-v122{min-width:90px}.bank-transfer-box-v147 .bank-modal-close-v122{font-size:25px}.bank-transfer-box-v147 .bank-modal-close-v122:hover{color:#4b5563}
      @media(max-width:900px){.bank-page-v122{grid-template-columns:1fr;height:auto;overflow:visible}.bank-left-v122,.bank-right-v122{min-height:420px}.bank-detail-head-v122{grid-template-columns:1fr}.bank-detail-actions-v122{align-items:flex-start}.bank-balance-v122{text-align:left}.bank-tx-search-v122{width:100%;max-width:none}}
      @media(max-width:620px){.bank-list-head-v122,.bank-row-v122{grid-template-columns:1fr 90px 26px}.bank-detail-grid-v122,.bank-form-grid-v122{grid-template-columns:1fr}.bank-form-span-v122{grid-column:auto}.bank-tx-head-v122{flex-direction:column;align-items:stretch}}
    </style>
    <div class="bank-page-v122">
      <section class="bank-left-v122">
        <div class="bank-left-top-v122"><button type="button" class="bank-add-v122" onclick="document.getElementById('bankAddModalV122').classList.add('show')">⊕ Add Bank</button></div>
        <div class="bank-list-head-v122"><span>ACCOUNT NAME</span><span style="text-align:right">AMOUNT</span><span></span></div>
        <div class="bank-list-v122">
          <?php foreach($banks as $b): $bid=(int)$b['id']; $bal=bank_balance($cid,$bid); $used=($viewCount[$bid]??0)>0; ?>
            <div class="bank-row-v122 <?=$bid===$selectedId?'active':''?>" data-bank-row="<?=$bid?>" onclick="window.location.href='<?=e(url('bank-accounts?select='.$bid))?>'">
              <div class="bank-main-v122"><span class="bank-icon-v122">▤</span><div style="min-width:0"><div class="bank-name-v122"><?=e($b['name'])?></div><div class="bank-sub-v122"><?=e($b['bank_name']?:'Bank Account')?></div></div></div>
              <div class="bank-amount-v122"><?=money($bal)?></div>
              <div class="bank-menu-wrap-v122" onclick="event.stopPropagation();">
                <button type="button" class="bank-dot-v122" aria-label="Bank actions" onclick="this.nextElementSibling.classList.toggle('show');document.querySelectorAll('.bank-menu-v122').forEach(m=>{if(m!==this.nextElementSibling)m.classList.remove('show')});">⋮</button>
                <div class="bank-menu-v122">
                  <a href="<?=e(url('bank-accounts?select='.$bid))?>">View</a>
                  <a href="<?=e(url('bank-accounts?edit='.$bid.'&select='.$bid))?>">Edit</a>
                  <?php if(!$used): ?><form method="post" onsubmit="return confirm('Delete this bank account?');"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_bank_account"><input type="hidden" name="id" value="<?=$bid?>"><button type="submit" class="danger">Delete</button></form><?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; if(!$banks): ?><div class="bank-empty-v122">No bank accounts yet.</div><?php endif; ?>
        </div>
      </section>

      <section class="bank-right-v122">
        <?php if($selected): ?>
          <div class="bank-detail-head-v122">
            <div class="bank-detail-grid-v122">
              <div><div class="bank-detail-label-v122">Bank Name</div><div class="bank-detail-value-v122"><?=e($selected['bank_name']?:'—')?></div></div>
              <div><div class="bank-detail-label-v122">Account Number</div><div class="bank-detail-value-v122"><?=e($selected['account_number']?:'—')?></div></div>
              <div><div class="bank-detail-label-v122">Account Name</div><div class="bank-detail-value-v122"><?=e($selected['name'])?></div></div>
            </div>
            <div class="bank-detail-actions-v122">
              <div class="bank-action-pair-v149"><button type="button" class="bank-move-btn-v122 bank-deposit-v149" onclick="openBankTransferV149('deposit')">Deposit</button><button type="button" class="bank-move-btn-v122 bank-withdraw-v149" onclick="openBankTransferV149('withdraw')">Withdraw</button></div>
              <div class="bank-balance-v122"><div class="bank-balance-label-v122">Balance</div><div class="bank-balance-value-v122"><?=money($selectedBalance)?></div></div>
            </div>
          </div>
          <div class="bank-tx-v122">
            <div class="bank-tx-head-v122"><div class="bank-tx-title-v122">TRANSACTIONS</div><form method="get" style="margin:0;display:flex;gap:8px;align-items:center"><input type="hidden" name="select" value="<?=$selectedId?>"><input class="bank-tx-search-v122" name="q" value="<?=e($search)?>" placeholder="Search transactions"></form></div>
            <div class="bank-tx-scroll-v122"><table class="bank-tx-table-v122"><thead><tr><th>TYPE</th><th>NAME</th><th>DATE</th><th>AMOUNT</th><th>REFERENCE</th></tr></thead><tbody>
            <?php foreach($transactions as $tr): $isIn=(float)$tr['debit']>0; $amt=$isIn?(float)$tr['debit']:(float)$tr['credit']; $typeLabel=match((string)$tr['txn_type']){'payment_in'=>'Payment In','payment_out'=>'Payment Out','sale'=>'Sale','purchase'=>'Purchase','expense'=>'Expense','delivery_challan'=>'Delivery Challan Advance','bank_deposit'=>'Cash Deposit','bank_withdraw'=>'Cash Withdraw','bank_transfer'=>'Bank Transfer',default=>ucwords(str_replace('_',' ',(string)$tr['txn_type']))}; $name=$tr['party_name']?:($tr['memo']?:($tr['notes']?:'—')); ?>
              <tr><td><?=e($typeLabel)?></td><td><?=e($name)?></td><td><?=e(!empty($tr['entry_date'])?date('d/m/Y',strtotime($tr['entry_date'])):'—')?></td><td class="<?=$isIn?'bank-in-v122':'bank-out-v122'?>"><?=($isIn?'+':'-').money($amt)?></td><td><?=e($tr['document_no']?:'—')?></td></tr>
            <?php endforeach; if(!$transactions): ?><tr><td colspan="5" class="bank-empty-v122">No transactions found for this bank account.</td></tr><?php endif; ?>
            </tbody></table></div>
          </div>
        <?php else: ?>
          <div class="bank-empty-v122" style="flex:1;display:grid;place-items:center">Select a bank account.</div>
        <?php endif; ?>
      </section>
    </div>

    <div class="bank-modal-v122" id="bankAddModalV122" onclick="if(event.target===this)this.classList.remove('show')"><div class="bank-modal-box-v122"><div class="bank-modal-head-v122"><h2>Add Bank</h2><button type="button" class="bank-modal-close-v122" onclick="document.getElementById('bankAddModalV122').classList.remove('show')">×</button></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_bank_account"><div class="bank-modal-body-v122"><div class="bank-form-grid-v122"><div class="bank-form-field-v122"><label>Account Name *</label><input name="name" required></div><div class="bank-form-field-v122"><label>Bank Name</label><input name="bank_name"></div><div class="bank-form-field-v122"><label>Account Number</label><input name="account_number"></div><div class="bank-form-field-v122"><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="0" onwheel="this.blur()"></div></div></div><div class="bank-modal-foot-v122"><button type="button" class="bank-secondary-v122" onclick="document.getElementById('bankAddModalV122').classList.remove('show')">Cancel</button><button class="bank-primary-v122">Save</button></div></form></div></div></div>

    <?php if($editBank): ?>
    <div class="bank-modal-v122 show" id="bankEditModalV122" onclick="if(event.target===this)this.classList.remove('show')"><div class="bank-modal-box-v122"><div class="bank-modal-head-v122"><h2>Edit Bank Account</h2><button type="button" class="bank-modal-close-v122" onclick="window.location.href='<?=e(url('bank-accounts?select='.(int)$editBank['id']))?>'">×</button></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="update_bank_account"><input type="hidden" name="id" value="<?=e((string)$editBank['id'])?>"><div class="bank-modal-body-v122"><div class="bank-form-grid-v122"><div class="bank-form-field-v122"><label>Account Name *</label><input name="name" value="<?=e($editBank['name'])?>" required></div><div class="bank-form-field-v122"><label>Bank Name</label><input name="bank_name" value="<?=e($editBank['bank_name']??'')?>"></div><div class="bank-form-field-v122"><label>Account Number</label><input name="account_number" value="<?=e($editBank['account_number']??'')?>"></div><div class="bank-form-field-v122"><label>Opening Balance</label><input type="number" step="0.01" name="opening_balance" value="<?=e((string)$editBank['opening_balance'])?>" onwheel="this.blur()"></div></div></div><div class="bank-modal-foot-v122"><a class="bank-secondary-v122" href="<?=e(url('bank-accounts?select='.(int)$editBank['id']))?>">Cancel</a><button class="bank-primary-v122">Save Changes</button></div></form></div></div>
    <?php endif; ?>


    <div class="bank-modal-v122 bank-transfer-modal-v147" id="bankTransferModalV147" onclick="if(event.target===this)this.classList.remove('show')"><div class="bank-transfer-box-v147"><div class="bank-transfer-head-v147"><h2 id="bankTransferTitleV150">Deposit</h2><button type="button" class="bank-modal-close-v122" onclick="document.getElementById('bankTransferModalV147').classList.remove('show')">×</button></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="bank_to_bank_transfer"><input type="hidden" name="from_account" id="bankTransferFromHiddenV150" value=""><input type="hidden" name="to_account" id="bankTransferToHiddenV150" value=""><div class="bank-transfer-body-v147"><div class="bank-transfer-grid-v147"><div class="bank-transfer-field-v147"><label>From</label><select id="bankTransferFromV147" required><option value="cash">Cash In Hand</option><?php foreach($banks as $b): ?><option value="bank:<?=e((string)$b['id'])?>" <?=$selectedId===(int)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach; ?></select></div><div class="bank-transfer-field-v147"><label>To</label><select id="bankTransferToV147" required><option value="cash">Cash In Hand</option><?php foreach($banks as $b): ?><option value="bank:<?=e((string)$b['id'])?>" <?=$selectedId===(int)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach; ?></select></div><div class="bank-transfer-field-v147"><label>Amount</label><input type="number" name="amount" min="0.01" step="0.01" placeholder="Amount" required onwheel="this.blur()"></div><div class="bank-transfer-field-v147"><label>Adjustment Date</label><input type="date" name="adjustment_date" value="<?=e(date('Y-m-d'))?>" required></div><div class="bank-transfer-field-v147 bank-transfer-full-v147"><label>Add Description</label><input type="text" name="notes" maxlength="255" placeholder="Optional"></div></div></div><div class="bank-transfer-foot-v147"><button type="button" class="bank-secondary-v122" onclick="document.getElementById('bankTransferModalV147').classList.remove('show')">Cancel</button><button class="bank-primary-v122">Save</button></div></form></div></div>

    <div class="bank-modal-v122" id="bankMoveModalV122" onclick="if(event.target===this)this.classList.remove('show')"><div class="bank-modal-box-v122"><div class="bank-modal-head-v122"><h2 id="bankMoveTitleV122">Deposit</h2><button type="button" class="bank-modal-close-v122" onclick="document.getElementById('bankMoveModalV122').classList.remove('show')">×</button></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="bank_cash_move"><input type="hidden" name="bank_id" value="<?=e((string)$selectedId)?>"><input type="hidden" name="direction" id="bankMoveDirectionV122" value="deposit"><div class="bank-modal-body-v122"><div class="bank-form-grid-v122"><div class="bank-form-field-v122"><label>Date</label><input type="date" name="txn_date" value="<?=e(date('Y-m-d'))?>"></div><div class="bank-form-field-v122"><label>Amount *</label><input type="number" step="0.01" min="0.01" name="amount" required onwheel="this.blur()"></div><div class="bank-form-field-v122 bank-form-span-v122"><label>Reference / Note</label><input name="notes" maxlength="255" placeholder="Optional"></div></div></div><div class="bank-modal-foot-v122"><button type="button" class="bank-secondary-v122" onclick="document.getElementById('bankMoveModalV122').classList.remove('show')">Cancel</button><button class="bank-primary-v122" id="bankMoveSubmitV122">Save</button></div></form></div></div>
    <script>
      (function(){var f=document.getElementById('bankTransferFromV147'),t=document.getElementById('bankTransferToV147'),fh=document.getElementById('bankTransferFromHiddenV150'),th=document.getElementById('bankTransferToHiddenV150');if(f){f.addEventListener('change',function(){if(!f.disabled)fh.value=f.value;});}if(t){t.addEventListener('change',function(){if(!t.disabled)th.value=t.value;});}})();
      function openBankTransferV149(dir){var m=document.getElementById('bankTransferModalV147');if(!m)return;var from=document.getElementById('bankTransferFromV147'),to=document.getElementById('bankTransferToV147');var fh=document.getElementById('bankTransferFromHiddenV150'),th=document.getElementById('bankTransferToHiddenV150');var title=document.getElementById('bankTransferTitleV150');if(!from||!to||!fh||!th)return;var current='<?=e((string)$selectedId)?>';from.disabled=false;to.disabled=false;fh.value='';th.value='';Array.prototype.forEach.call(from.options,function(o){o.disabled=false;});Array.prototype.forEach.call(to.options,function(o){o.disabled=false;});if(dir==='deposit'){title.textContent='Deposit';from.disabled=false;from.value='cash';fh.value='cash';if(current){var sourceOpt=from.querySelector('option[value="bank:'+current+'"]');if(sourceOpt)sourceOpt.disabled=true;to.value='bank:'+current;to.disabled=true;th.value='bank:'+current;}else{to.value='cash';to.disabled=true;th.value='cash';}}else{title.textContent='Withdraw';if(current){from.value='bank:'+current;from.disabled=true;fh.value='bank:'+current;}else{from.value='cash';from.disabled=true;fh.value='cash';}to.disabled=false;to.value='cash';th.value='cash';}m.classList.add('show');}
      function openBankTransferV147(){openBankTransferV149('deposit');}
      function openBankMoveV122(dir){var m=document.getElementById('bankMoveModalV122');if(!m)return;document.getElementById('bankMoveDirectionV122').value=dir;document.getElementById('bankMoveTitleV122').textContent=dir==='deposit'?'Deposit':'Withdraw';document.getElementById('bankMoveSubmitV122').textContent=dir==='deposit'?'Save Deposit':'Save Withdraw';m.classList.add('show');}
    </script>
    <?php page_end();exit;
}


<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='delivery-challans'){delivery_challans_list();exit;}

if($route==='delivery-challan-items'){
    header('Content-Type: application/json; charset=utf-8');
    try{
        $cid=(int)$u['company_id'];
        // Intentionally avoid any dependency on units, stock_movements, or get_items().
        // We load every item belonging to the logged-in company, and filter inactive items
        // only in PHP so a schema/data mismatch on the active flag cannot produce an empty selector.
        $st=db()->prepare("SELECT id,name,item_type,sale_price,purchase_price,unit_id,active FROM items WHERE company_id=? ORDER BY name,id");
        $st->execute([$cid]);
        $rows=$st->fetchAll(PDO::FETCH_ASSOC);
        // Resolve unit symbols independently and safely.
        $unitIds=[];
        foreach($rows as $r){ if(isset($r['unit_id']) && $r['unit_id']!==null && $r['unit_id']!=='') $unitIds[]=(int)$r['unit_id']; }
        $symbols=[];
        $unitIds=array_values(array_unique(array_filter($unitIds,fn($v)=>$v>0)));
        if($unitIds){
            $ph=implode(',',array_fill(0,count($unitIds),'?'));
            $ust=db()->prepare("SELECT id,symbol FROM units WHERE company_id=? AND id IN ($ph)");
            $ust->execute(array_merge([$cid],$unitIds));
            foreach($ust->fetchAll(PDO::FETCH_ASSOC) as $ur) $symbols[(int)$ur['id']]=$ur['symbol'];
        }
        $out=[];
        foreach($rows as $r){
            if((int)($r['active']??1)!==1) continue;
            $out[]=[
                'id'=>(int)$r['id'],
                'name'=>(string)$r['name'],
                'item_type'=>(string)$r['item_type'],
                'sale_price'=>(float)$r['sale_price'],
                'purchase_price'=>(float)$r['purchase_price'],
                'unit_id'=>$r['unit_id']===null?null:(int)$r['unit_id'],
                'unit_symbol'=>(string)($symbols[(int)($r['unit_id']??0)]??'')
            ];
        }
        echo json_encode(['ok'=>true,'count'=>count($out),'items'=>$out],JSON_UNESCAPED_UNICODE); exit;
    }catch(Throwable $e){
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'items'=>[]],JSON_UNESCAPED_UNICODE); exit;
    }
}

function delivery_challans_list(): void {
    global $u;
    $cid=(int)$u['company_id']; $pdo=db();

    // Delete a Delivery Challan: move it to Recycle Bin.
    // Converted challans are protected because they are linked to a Sale.
    if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='delete_document'){
        check_csrf();
        $docId=(int)($_POST['transaction_id']??0);
        try{
            if($docId<=0) throw new RuntimeException('Invalid Delivery Challan.');
            $st=$pdo->prepare('SELECT t.*,(SELECT tl.to_transaction_id FROM transaction_links tl WHERE tl.company_id=t.company_id AND tl.from_transaction_id=t.id AND tl.relation_type="challan_to_sale" LIMIT 1) sale_id FROM transactions t WHERE t.id=? AND t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL LIMIT 1');
            $st->execute([$docId,$cid]);
            $doc=$st->fetch();
            if(!$doc) throw new RuntimeException('Delivery Challan not found.');
            if(!empty($doc['sale_id']) || ($doc['status']??'')==='converted'){
                throw new RuntimeException('Converted Delivery Challan cannot be deleted.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE transactions SET deleted_at=NOW(),status="deleted" WHERE id=? AND company_id=? AND txn_type="delivery_challan" AND deleted_at IS NULL')->execute([$docId,$cid]);
            try{
                $pdo->prepare('UPDATE transaction_items SET deleted_at=NOW() WHERE transaction_id=?')->execute([$docId]);
            }catch(Throwable $ignore){}
            audit('delete','transaction',$docId,[
                'type'=>'delivery_challan',
                'document_no'=>$doc['document_no']??null,
                'reason'=>'Delivery Challan moved to Recycle Bin'
            ]);
            $pdo->commit();
            flash('success','Delivery Challan '.$doc['document_no'].' moved to Recycle Bin.');
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            flash('error',$e->getMessage());
        }
        redirect('delivery-challans');
    }

    // Convert a challan to a sale from the list page.
    if(isset($_GET['convert']) && $_GET['convert']!==''){
        $sourceId=(int)$_GET['convert'];
        try{
            $st=$pdo->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL');
            $st->execute([$sourceId,$cid]); $source=$st->fetch();
            if(!$source) throw new RuntimeException('Delivery Challan not found.');
            $lk=$pdo->prepare('SELECT to_transaction_id FROM transaction_links WHERE company_id=? AND from_transaction_id=? AND relation_type="challan_to_sale" LIMIT 1');
            $lk->execute([$cid,$sourceId]);
            if($lk->fetchColumn()) throw new RuntimeException('This Delivery Challan has already been converted to Sale.');
            $its=$pdo->prepare('SELECT ti.* FROM transaction_items ti WHERE ti.transaction_id=? ORDER BY ti.id'); $its->execute([$sourceId]); $sourceItems=$its->fetchAll();
            if(!$sourceItems) throw new RuntimeException('Delivery Challan has no items.');
            $sourceAdvance=(float)($source['paid']??0);
            $sourceAdvance=max(0,min((float)$source['total'],$sourceAdvance));
            $sourcePayments=$pdo->prepare('SELECT method,account_name,reference_no,cheque_date,amount,status FROM payment_lines WHERE transaction_id=? ORDER BY id');
            $sourcePayments->execute([$sourceId]); $sourcePaymentRows=$sourcePayments->fetchAll();
            if($sourceAdvance>0 && !$sourcePaymentRows){
                throw new RuntimeException('This Delivery Challan has an advance amount but no payment method records. Please review the challan before conversion.');
            }
            $pdo->beginTransaction();
            $doc=next_document_in_transaction($pdo,$cid,'sale','SI-');
            $saleDue=round(max(0,(float)$source['total']-$sourceAdvance),2);
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
              ->execute([$cid,$source['party_id'],'sale',$doc,$source['txn_date'],null,$source['subtotal'],$source['item_discount'],$source['invoice_discount'],$source['tax'],$source['direct_expense'],$source['total'],$sourceAdvance,$saleDue,$source['currency_code'],$saleDue>0?'open':'final','Converted from '.$source['document_no'],$u['id']]);
            $tid=(int)$pdo->lastInsertId();
            $ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');
            foreach($sourceItems as $r){$ins->execute([$tid,$r['item_id'],$r['qty'],$r['unit_price'],$r['discount'],$r['tax'],$r['amount']]);}
            $cogs=0;
            $itq=$pdo->prepare('SELECT item_type,purchase_price FROM items WHERE id=? AND company_id=?');
            $stock=$pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,movement_type,note) VALUES(?,?,?,?,?,?,?)');
            foreach($sourceItems as $r){
                $itq->execute([(int)$r['item_id'],$cid]); $it=$itq->fetch();
                if($it && $it['item_type']==='product'){
                    $qty=(float)$r['qty']; $cogs += max(0,$qty*(float)$it['purchase_price']);
                    $stock->execute([$cid,(int)$r['item_id'],$tid,$source['txn_date'],-$qty,'sale',$doc]);
                }
            }
            $net=max(0,(float)$source['subtotal']-(float)$source['item_discount']-(float)$source['invoice_discount']);
            $lines=[['1200','Accounts Receivable',(float)$source['total'],0,$doc],['4000','Sales Revenue',0,$net,$doc]];
            if((float)$source['tax']>0)$lines[]=['2100','Tax Payable',0,(float)$source['tax'], $doc];
            if((float)$source['direct_expense']>0)$lines[]=['4200','Direct Expense Recovery',0,(float)$source['direct_expense'],$doc];
            if($cogs>0){$lines[]=['5100','Cost of Goods Sold',$cogs,0,$doc];$lines[]=['1300','Inventory',0,$cogs,$doc];}
            // Apply the already-received challan advance against the new Sale.
            // Cash/Bank was recorded on the original challan, so do NOT debit
            // Cash/Bank again here; simply reduce the customer's receivable and
            // release the customer-advance liability.
            if($sourceAdvance>0){
                $lines[]=['2200','Customer Advances',$sourceAdvance,0,$doc];
                $lines[]=['1200','Accounts Receivable',0,$sourceAdvance,$doc];
            }
            $debit=0;$credit=0;foreach($lines as $l){$debit+=round((float)$l[2],2);$credit+=round((float)$l[3],2);}if(abs(round($debit-$credit,2))>0.01)throw new RuntimeException('Converted sale accounting entry is not balanced.');post_ledger($pdo,$cid,$tid,$source['txn_date'],$lines);
            if($sourcePaymentRows){
                $plSale=$pdo->prepare('INSERT INTO payment_lines(transaction_id,method,account_name,reference_no,cheque_date,amount,status) VALUES(?,?,?,?,?,?,?)');
                foreach($sourcePaymentRows as $pr){
                    $plSale->execute([$tid,$pr['method'],$pr['account_name'],$pr['reference_no'],$pr['cheque_date'],$pr['amount'],$pr['status']]);
                }
            }
            $pdo->prepare('INSERT INTO transaction_links(company_id,from_transaction_id,to_transaction_id,relation_type,quantity) VALUES(?,?,?,?,NULL)')->execute([$cid,$sourceId,$tid,'challan_to_sale']);
            $pdo->prepare('UPDATE transactions SET status="converted" WHERE id=? AND company_id=?')->execute([$sourceId,$cid]);
            audit('convert','transaction',$sourceId,['to_transaction'=>$tid,'relation'=>'challan_to_sale','target_document'=>$doc]);
            $pdo->commit(); flash('success',$source['document_no'].' converted to '.$doc.'.'); redirect('delivery-challans');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect('delivery-challans');}
    }
    $q=trim($_GET['q']??'');
    $sql='SELECT t.*,p.name party_name,(SELECT tl.to_transaction_id FROM transaction_links tl WHERE tl.company_id=t.company_id AND tl.from_transaction_id=t.id AND tl.relation_type="challan_to_sale" LIMIT 1) sale_id FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL';
    $params=[$cid];
    if($q!==''){ $sql.=' AND (t.document_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)'; $like='%'.$q.'%'; $params[]=$like;$params[]=$like;$params[]=$like; }
    $sql.=' ORDER BY t.txn_date DESC,t.id DESC'; $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
    page_start('Delivery Challans');
    ?>
    <div class="page-title"><div><h1>Delivery Challan</h1><p>Track delivery challans and convert them to sales.</p></div><a class="btn primary" href="<?=e(url('delivery-challan-new'))?>">⊕ Add Delivery Challan</a></div>
    <div class="panel">
      <div class="panel-head"><h2>TRANSACTIONS</h2><form method="get" style="display:flex;gap:8px"><input class="input" name="q" value="<?=e($q)?>" placeholder="⌕ Search by challan, party, phone"><button class="btn" type="submit">Search</button></form></div>
      <div class="table-wrap"><table><thead><tr><th>DATE</th><th>PARTY</th><th>CHALLAN NO.</th><th>DUE DATE</th><th>TOTAL AMOUNT</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
      <?php foreach($rows as $r): $converted=!empty($r['sale_id']); ?>
      <tr>
        <td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td>
        <td><?=e($r['party_name']??'')?></td>
        <td><?=e($r['document_no'])?></td>
        <td><?=e($r['due_date']?date('d/m/Y',strtotime($r['due_date'])):'—')?></td>
        <td><?=money((float)$r['total'])?></td>
        <td><span class="status <?=$converted?'paid':'open'?>"><?=e($converted?'Converted':ucfirst($r['status']))?></span></td>
        <td class="action"><div style="display:flex;align-items:center;gap:6px;justify-content:flex-end">
          <?php if(!$converted):?><a class="btn small-btn" href="<?=e(url('delivery-challans?convert='.(int)$r['id']))?>" onclick="return confirm('Convert this Delivery Challan to Sale?')">CONVERT TO SALE</a><?php elseif($r['sale_id']):?><a class="btn small-btn" href="<?=e(url('sales?view='.(int)$r['sale_id']))?>">VIEW SALE</a><?php endif; ?>
          <a class="btn small-btn" href="<?=e(url('delivery-challan-new?edit='.(int)$r['id']))?>">View/Edit</a>
          <button type="button" class="dots" aria-label="Actions">⋮</button>
          <div class="row-menu"><a href="<?=e(url('delivery-challan-new?edit='.(int)$r['id']))?>">View/Edit</a><?php if(!$converted):?><a href="<?=e(url('delivery-challan-new?duplicate='.(int)$r['id']))?>">Duplicate</a><form method="post" onsubmit="return confirm('Delete this Delivery Challan? It will move to Recycle Bin.')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_document"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Delete</button></form><?php else:?><span style="display:block;padding:9px 12px;color:#9ca3af">Delete unavailable</span><?php endif;?><a href="<?=e(url('delivery-challan-new?view='.(int)$r['id'].'&print=1'))?>">Open PDF</a><a href="<?=e(url('delivery-challan-new?dc_print_view='.(int)$r['id']))?>">Preview</a><a href="<?=e(url('delivery-challan-new?view='.(int)$r['id'].'&print=1'))?>">Print</a></div>
        </div></td>
      </tr>
      <?php endforeach; if(!$rows):?><tr><td colspan="7" class="subtle">No Delivery Challans found.</td></tr><?php endif; ?></tbody></table></div>
    </div>
    <style>.action{position:relative}.row-menu{position:absolute;right:0;top:38px;display:none;background:#fff;border:1px solid #d8dee8;box-shadow:0 8px 20px rgba(0,0,0,.12);z-index:20;min-width:150px}.row-menu.show{display:block}.row-menu a{display:block;padding:9px 12px;white-space:nowrap}.row-menu a:hover{background:#f3f6fa}.row-menu form{margin:0}.row-menu form button{display:block;width:100%;border:0;background:#fff;text-align:left;padding:9px 12px;font:inherit;color:#dc2626;cursor:pointer}.row-menu form button:hover{background:#fef2f2}</style>
    <?php page_end();exit;
}

if($route==='delivery-challan-new'){delivery_challan_new();exit;}
if($route==='product-requests'){product_requests_list();exit;}
if($route==='product-request-new'){product_request_new();exit;}

if($route==='quotations'){document_module('quotation','Estimate / Quotation','QT-','Customer',['sale_order']);exit;}
if($route==='sale-order'){document_module('sale_order','Sale Order','SO-','Customer',['delivery_challan']);exit;}
if($route==='purchase-order'){document_module('purchase_order','Purchase Order','PO-','Supplier',['purchase']);exit;}
if($route==='purchase_order'){redirect('purchase-order');}








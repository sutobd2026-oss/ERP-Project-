<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if($route==='return-items'){
    header('Content-Type: application/json; charset=utf-8');
    $cid=(int)$u['company_id'];$sourceId=(int)($_GET['source_id']??0);
    $st=db()->prepare('SELECT ti.item_id,i.name,ti.qty,ti.unit_price,ti.discount,ti.amount,CASE WHEN ti.qty=0 THEN 0 ELSE ti.discount/ti.qty END discount_per_unit,CASE WHEN ti.qty=0 THEN 0 ELSE ti.amount/ti.qty END net_unit_price FROM transaction_items ti JOIN items i ON i.id=ti.item_id JOIN transactions t ON t.id=ti.transaction_id WHERE ti.transaction_id=? AND t.company_id=? ORDER BY ti.id');$st->execute([$sourceId,$cid]);echo json_encode(array_map(function($r){$r['qty']=(float)$r['qty'];$r['unit_price']=(float)$r['unit_price'];$r['discount']=(float)$r['discount'];$r['amount']=(float)$r['amount'];$r['discount_per_unit']=(float)$r['discount_per_unit'];$r['net_unit_price']=(float)$r['net_unit_price'];return $r;}, $st->fetchAll()),JSON_UNESCAPED_UNICODE);exit;
}

if($route==='sale-return'){return_module('sale_return');}
if($route==='purchase-return'){return_module('purchase_return');}


function advance_payment_options(array $bankRows,string $selectedMethod='cash',string $selectedAccount=''): string {
    $selected='cash';
    if($selectedMethod==='bank'){
        foreach($bankRows as $b){if((string)$b['name']===$selectedAccount){$selected='bank|'.(int)$b['id'];break;}}
    }
    $html='<option value="cash"'.($selected==='cash'?' selected':'').'>Cash</option>';
    foreach($bankRows as $b){
        $label=(string)$b['name'];
        if(!empty($b['bank_name'])) $label.=' · '.(string)$b['bank_name'];
        $v='bank|'.(int)$b['id'];
        $html.='<option value="'.e($v).'"'.($selected===$v?' selected':'').'>'.e($label).'</option>';
    }
    return $html;
}


/** Direct Delivery Challan print preview, following the Sale Invoice A4/A5 preview style. */
function render_delivery_challan_print_preview_direct(array $tx,array $lines,array $payments,array $u,string $defaultPaper,string $companyName,string $companyEmail,string $companyPhone,string $companyAddress,bool $direct=true): void {
    $paper=$defaultPaper==='A5'?'A5':'A4';
    $logo=saas_company_logo_url($u['logo_path']??null);
    $partyName=(string)($tx['party_name']??'—');
    $partyPhone=(string)($tx['party_phone']??'');
    $partyAddress=(string)($tx['party_address']??'');
    $date=!empty($tx['txn_date'])?date('d/m/Y',strtotime((string)$tx['txn_date'])):'—';
    $companyLines=array_values(array_filter([$companyAddress,$companyPhone,$companyEmail],fn($x)=>trim((string)$x)!==''));
    $companyMeta=implode(' · ',array_map('strval',$companyLines));
    $closeUrl=url('delivery-challans');
    ?>
    <style>
      .sense-dc-preview-direct{display:block;position:static;background:#f1f5f9;overflow:visible;padding:0 0 82px;min-height:calc(100vh - 1px);box-sizing:border-box}
      .sense-dc-preview-direct .sense-dc-preview-toolbar{border-radius:0;box-shadow:0 -2px 10px rgba(15,23,42,.14);max-width:none;padding:12px 18px;margin:0}
      .sense-dc-preview-toolbar{position:fixed;left:0;right:0;bottom:0;z-index:2147483600;display:flex;justify-content:space-between;align-items:center;gap:10px;max-width:none;margin:0;padding:10px 18px;background:#fff;border:1px solid #e5e7eb;border-left:0;border-right:0;border-bottom:0;border-radius:0;box-shadow:0 -4px 18px rgba(15,23,42,.16)}
      .sense-dc-preview-toolbar-left,.sense-dc-preview-toolbar-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
      .sense-dc-paper-toggle{display:flex;align-items:center;gap:4px;padding:3px;background:#f3f4f6;border-radius:10px;border:1px solid #e5e7eb}
      .sense-dc-paper-btn{border:0;background:transparent;border-radius:8px;padding:7px 12px;font:600 13px/1 Arial,sans-serif;color:#475569;cursor:pointer}
      .sense-dc-paper-btn.active{background:#111827;color:#fff;box-shadow:0 1px 4px rgba(15,23,42,.18)}
      .sense-dc-default-label{font-size:12px;color:#64748b}
      .sense-dc-preview-frame{display:flex;justify-content:center;align-items:flex-start;overflow:auto;padding:12px 4px 96px;min-height:calc(100vh - 100px);box-sizing:border-box}
      .sense-dc-preview-sheet{width:210mm;min-height:297mm;background:#fff;box-shadow:0 4px 26px rgba(15,23,42,.28);box-sizing:border-box;color:#172033;font-family:Arial,Helvetica,sans-serif;padding:12mm;position:relative;transition:width .15s ease,min-height .15s ease,padding .15s ease;font-size:12px}
      .sense-dc-preview-sheet.paper-a5{width:148mm;min-height:210mm;padding:9mm;font-size:10px}
      .sense-dc-preview-sheet .sdp-head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #111827;padding-bottom:12px;margin-bottom:12px}
      .sense-dc-preview-sheet .sdp-brand{display:flex;gap:10px;align-items:flex-start;min-width:0}
      .sense-dc-preview-sheet .sdp-logo{max-width:58mm;max-height:24mm;object-fit:contain}
      .sense-dc-preview-sheet.paper-a5 .sdp-logo{max-width:42mm;max-height:17mm}
      .sense-dc-preview-sheet .sdp-company-name{font-size:24px;font-weight:800;line-height:1.1;margin-bottom:5px}
      .sense-dc-preview-sheet.paper-a5 .sdp-company-name{font-size:18px}
      .sense-dc-preview-sheet .sdp-company-meta{font-size:10px;line-height:1.5;color:#64748b;white-space:pre-line;max-width:105mm}
      .sense-dc-preview-sheet.paper-a5 .sdp-company-meta{font-size:8px;max-width:70mm}
      .sense-dc-preview-sheet .sdp-title{text-align:right;min-width:38mm}
      .sense-dc-preview-sheet .sdp-title h1{font-size:20px;line-height:1.1;margin:0 0 7px;font-weight:800}
      .sense-dc-preview-sheet.paper-a5 .sdp-title h1{font-size:15px}
      .sense-dc-preview-sheet .sdp-title .sdp-docno{font-size:15px;font-weight:800}
      .sense-dc-preview-sheet.paper-a5 .sdp-title .sdp-docno{font-size:12px}
      .sense-dc-preview-sheet .sdp-title .sdp-date{margin-top:3px;color:#64748b;font-size:10px}
      .sense-dc-preview-sheet.paper-a5 .sdp-title .sdp-date{font-size:8px}
      .sense-dc-preview-sheet .sdp-info-grid{display:grid;grid-template-columns:1.45fr 1fr 1fr;gap:9px;margin-bottom:14px}
      .sense-dc-preview-sheet.paper-a5 .sdp-info-grid{gap:6px;margin-bottom:9px}
      .sense-dc-preview-sheet .sdp-info-box{border:1px solid #dfe3e8;border-radius:7px;padding:9px 10px;min-height:46px;box-sizing:border-box}
      .sense-dc-preview-sheet.paper-a5 .sdp-info-box{padding:6px 7px;min-height:34px}
      .sense-dc-preview-sheet .sdp-label{font-size:8px;text-transform:uppercase;letter-spacing:.08em;color:#7b8794;margin-bottom:4px;font-weight:700}
      .sense-dc-preview-sheet.paper-a5 .sdp-label{font-size:7px}
      .sense-dc-preview-sheet .sdp-value{font-weight:700;font-size:11px;line-height:1.35}
      .sense-dc-preview-sheet.paper-a5 .sdp-value{font-size:9px}
      .sense-dc-preview-sheet .sdp-sub{font-size:9px;color:#64748b;margin-top:2px;line-height:1.35}
      .sense-dc-preview-sheet.paper-a5 .sdp-sub{font-size:7.5px}
      .sense-dc-preview-sheet table{width:100%;border-collapse:collapse}
      .sense-dc-preview-sheet .sdp-items thead th{background:#f3f4f6;border-top:1px solid #dfe3e8;border-bottom:1px solid #dfe3e8;padding:8px 7px;font-size:8px;text-align:left;text-transform:uppercase;letter-spacing:.05em;color:#475569}
      .sense-dc-preview-sheet.paper-a5 .sdp-items thead th{padding:6px 5px;font-size:6.5px}
      .sense-dc-preview-sheet .sdp-items tbody td{border-bottom:1px solid #e7eaee;padding:8px 7px;vertical-align:top;font-size:10px}
      .sense-dc-preview-sheet.paper-a5 .sdp-items tbody td{padding:6px 5px;font-size:8px}
      .sense-dc-preview-sheet .num{text-align:right;white-space:nowrap}
      .sense-dc-preview-sheet .sdp-bottom{display:grid;grid-template-columns:minmax(0,1fr) 74mm;gap:18px;margin-top:14px}
      .sense-dc-preview-sheet.paper-a5 .sdp-bottom{grid-template-columns:minmax(0,1fr) 54mm;gap:10px;margin-top:9px}
      .sense-dc-preview-sheet .sdp-notes{font-size:9px;line-height:1.45;color:#475569;white-space:pre-line}
      .sense-dc-preview-sheet.paper-a5 .sdp-notes{font-size:7.5px}
      .sense-dc-preview-sheet .sdp-summary{border-top:1px solid #111827}
      .sense-dc-preview-sheet .sdp-summary-row{display:flex;justify-content:space-between;gap:10px;padding:5px 0;border-bottom:1px solid #e7eaee;font-size:10px}
      .sense-dc-preview-sheet.paper-a5 .sdp-summary-row{padding:4px 0;font-size:8px}
      .sense-dc-preview-sheet .sdp-summary-row.total{font-size:13px;font-weight:800;border-bottom:2px solid #111827;padding:8px 0}
      .sense-dc-preview-sheet.paper-a5 .sdp-summary-row.total{font-size:10px;padding:6px 0}
      .sense-dc-preview-sheet .sdp-payment{margin-top:12px}
      .sense-dc-preview-sheet .sdp-payment-title{font-size:8px;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:.06em;margin-bottom:4px}
      .sense-dc-preview-sheet.paper-a5 .sdp-payment-title{font-size:7px}
      .sense-dc-preview-sheet .sdp-payment-row{display:flex;justify-content:space-between;gap:10px;font-size:9px;padding:3px 0;color:#475569}
      .sense-dc-preview-sheet.paper-a5 .sdp-payment-row{font-size:7.5px}
      .sense-dc-preview-sheet .sdp-footer{display:grid;grid-template-columns:1fr 1fr;gap:36px;margin-top:34px}
      .sense-dc-preview-sheet.paper-a5 .sdp-footer{gap:20px;margin-top:22px}
      .sense-dc-preview-sheet .sdp-sign{padding-top:18px;border-top:1px solid #94a3b8;text-align:center;font-size:8px;color:#64748b}
      .sense-dc-preview-sheet.paper-a5 .sdp-sign{padding-top:12px;font-size:6.5px}
      .sense-dc-preview-sheet .sdp-note{text-align:center;margin-top:18px;font-size:8px;color:#94a3b8}
      .sense-dc-preview-sheet.paper-a5 .sdp-note{margin-top:10px;font-size:6.5px}
      @media(max-width:800px){.sense-dc-preview-direct{padding-bottom:126px}.sense-dc-preview-toolbar{align-items:flex-start;flex-direction:column;padding:9px 12px}.sense-dc-preview-toolbar-left,.sense-dc-preview-toolbar-right{width:100%;justify-content:flex-end}.sense-dc-preview-frame{padding-bottom:136px}}
      @media print{body{background:#fff!important}.topbar,.sidebar,.gear,.sense-direct-page-title{display:none!important}.sense-dc-preview-direct{background:#fff!important}.sense-dc-preview-toolbar{display:none!important}.sense-dc-preview-frame{padding:0!important;min-height:0!important}.sense-dc-preview-sheet{box-shadow:none!important;margin:0!important}}
    </style>
    <div class="sense-dc-preview-direct" id="senseDcPreview" data-default-paper="<?=e($paper)?>" data-current-paper="<?=e($paper)?>">
      <div class="sense-dc-preview-toolbar">
        <div class="sense-dc-preview-toolbar-left"><strong style="font-size:14px">Delivery Challan Print Preview</strong><span class="sense-dc-default-label">Default: <?=e($paper)?></span><div class="sense-dc-paper-toggle" role="group" aria-label="Paper size"><button type="button" class="sense-dc-paper-btn <?=($paper==='A4'?'active':'')?>" data-paper="A4">A4</button><button type="button" class="sense-dc-paper-btn <?=($paper==='A5'?'active':'')?>" data-paper="A5">A5</button></div></div>
        <div class="sense-dc-preview-toolbar-right"><a class="btn" href="<?=e($closeUrl)?>">Close Preview</a><button type="button" class="btn primary" onclick="senseDcPreviewPrint()">Print</button></div>
      </div>
      <div class="sense-dc-preview-frame">
        <section class="sense-dc-preview-sheet <?=($paper==='A5'?'paper-a5':'paper-a4')?>" id="senseDcPreviewSheet">
          <div class="sdp-head"><div class="sdp-brand"><?php if($logo): ?><img class="sdp-logo" src="<?=e($logo)?>" alt="Company logo"><?php endif; ?><div><div class="sdp-company-name"><?=e($companyName)?></div><?php if($companyMeta!==''): ?><div class="sdp-company-meta"><?=e($companyMeta)?></div><?php endif; ?></div></div><div class="sdp-title"><h1>DELIVERY CHALLAN</h1><div class="sdp-docno"><?=e((string)$tx['document_no'])?></div><div class="sdp-date"><?=e($date)?></div></div></div>
          <div class="sdp-info-grid">
            <div class="sdp-info-box"><div class="sdp-label">Customer</div><div class="sdp-value"><?=e($partyName)?></div><?php if($partyPhone!==''): ?><div class="sdp-sub"><?=e($partyPhone)?></div><?php endif; ?><?php if($partyAddress!==''): ?><div class="sdp-sub"><?=e($partyAddress)?></div><?php endif; ?></div>
            <div class="sdp-info-box"><div class="sdp-label">COD</div><div class="sdp-value"><?=e(money((float)($tx['due']??0)))?></div></div>
            <div class="sdp-info-box"><div class="sdp-label">Status</div><div class="sdp-value"><?=e(((float)($tx['due']??0)>0)?'Cash on Delivery': 'Advance Paid')?></div><div class="sdp-sub">Advance: <?=e(money((float)($tx['paid']??0)))?></div></div>
          </div>
          <div class="sdp-items"><table><thead><tr><th style="width:6%">#</th><th>ITEM</th><th class="num">QTY</th><th>UNIT</th><th class="num">PRICE/UNIT</th><th class="num">AMOUNT</th></tr></thead><tbody><?php if($lines): foreach($lines as $i=>$r): ?><tr><td><?=($i+1)?></td><td><strong><?=e((string)$r['item_name'])?></strong></td><td class="num"><?=e(qty((float)$r['qty']))?></td><td><?=e((string)($r['unit_symbol']??''))?></td><td class="num"><?=e(money((float)$r['unit_price']))?></td><td class="num"><?=e(money((float)$r['amount']))?></td></tr><?php endforeach; else: ?><tr><td colspan="6" style="text-align:center;color:#94a3b8">No items.</td></tr><?php endif; ?></tbody></table></div>
          <div class="sdp-bottom"><div><?php if(!empty($tx['notes'])): ?><div class="sdp-label">Delivery Note</div><div class="sdp-notes"><?=nl2br(e((string)$tx['notes']))?></div><?php endif; ?><?php if($payments): ?><div class="sdp-payment"><div class="sdp-payment-title">Advance Payment</div><?php foreach($payments as $pay): ?><div class="sdp-payment-row"><span><?=e(ucwords(str_replace('_',' ',(string)($pay['method']??''))))?><?=!empty($pay['account_name'])?' · '.e((string)$pay['account_name']):''?></span><strong><?=e(money((float)$pay['amount']))?></strong></div><?php endforeach; ?></div><?php endif; ?></div>
            <div class="sdp-summary"><div class="sdp-summary-row"><span>Subtotal</span><strong><?=e(money((float)$tx['subtotal']))?></strong></div><?php if((float)$tx['item_discount']>0): ?><div class="sdp-summary-row"><span>Item Discount</span><strong><?=e(money((float)$tx['item_discount']))?></strong></div><?php endif; ?><?php if((float)$tx['invoice_discount']>0): ?><div class="sdp-summary-row"><span>Invoice Discount</span><strong><?=e(money((float)$tx['invoice_discount']))?></strong></div><?php endif; ?><?php if((float)$tx['direct_expense']>0): ?><div class="sdp-summary-row"><span>Courier / Shipping</span><strong><?=e(money((float)$tx['direct_expense']))?></strong></div><?php endif; ?><div class="sdp-summary-row total"><span>Total</span><strong><?=e(money((float)$tx['total']))?></strong></div><div class="sdp-summary-row"><span>Advance Paid</span><strong><?=e(money((float)$tx['paid']))?></strong></div><div class="sdp-summary-row"><span>Cash on Delivery</span><strong><?=e(money((float)$tx['due']))?></strong></div></div></div>
          <div class="sdp-footer"><div class="sdp-sign">Received By</div><div class="sdp-sign">Authorized Signature</div></div><div class="sdp-note">This document is a delivery challan and is not a tax invoice.</div>
        </section>
      </div>
    </div>
    <script>
    (function(){
      var root=document.getElementById('senseDcPreview'),sheet=document.getElementById('senseDcPreviewSheet');
      function setSize(size){size=size==='A5'?'A5':'A4';root.dataset.currentPaper=size;sheet.classList.toggle('paper-a5',size==='A5');sheet.classList.toggle('paper-a4',size==='A4');root.querySelectorAll('[data-paper]').forEach(function(b){b.classList.toggle('active',b.dataset.paper===size);});}
      root.querySelectorAll('[data-paper]').forEach(function(b){b.addEventListener('click',function(){setSize(b.dataset.paper);});});
      window.senseDcPreviewPrint=function(){var paper=(root.dataset.currentPaper||root.dataset.defaultPaper||'A4')==='A5'?'A5':'A4';var iframe=document.createElement('iframe');iframe.style.position='fixed';iframe.style.right='0';iframe.style.bottom='0';iframe.style.width='0';iframe.style.height='0';iframe.style.border='0';iframe.setAttribute('aria-hidden','true');document.body.appendChild(iframe);var doc=iframe.contentDocument||iframe.contentWindow.document;var links=Array.from(document.querySelectorAll('link[rel="stylesheet"]')).map(function(l){return l.outerHTML;}).join('');var styles=Array.from(document.querySelectorAll('style')).map(function(st){return st.textContent||'';}).join('\n');var extra='@page{size:'+paper+' portrait;margin:0}html,body{margin:0!important;padding:0!important;background:#fff!important}body{font-family:Arial,Helvetica,sans-serif}.sense-dc-preview-sheet{display:block!important;position:relative!important;width:'+(paper==='A5'?'148mm':'210mm')+'!important;min-height:'+(paper==='A5'?'210mm':'297mm')+'!important;margin:0 auto!important;padding:'+(paper==='A5'?'9mm':'12mm')+'!important;box-shadow:none!important;background:#fff!important}.sense-dc-preview-sheet.paper-a5{font-size:10px}.sense-dc-preview-frame{overflow:visible!important;padding:0!important;min-height:0!important}.sense-dc-preview-toolbar{display:none!important}.sense-dc-preview-direct{background:#fff!important}';doc.open();doc.write('<!doctype html><html><head><meta charset="utf-8">'+links+'<style>'+styles+'\n'+extra+'</style></head><body>'+sheet.outerHTML+'</body></html>');doc.close();setTimeout(function(){try{iframe.contentWindow.focus();iframe.contentWindow.print();}catch(e){}setTimeout(function(){iframe.remove();},1200);},180);};
      <?php if(isset($_GET['autoprint'])): ?>window.addEventListener('load',function(){setTimeout(function(){senseDcPreviewPrint();},350);});<?php endif; ?>
    })();
    </script>
    <?php
}

function delivery_challan_new(): void {
    global $u;
    $cid=(int)$u['company_id']; $pdo=db();
    $previewId=(int)($_GET['preview']??0);
    $previewMode=$previewId>0;
    if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save_delivery_challan'){
        check_csrf();
        $saveAndPrint = !empty($_POST['save_and_print']);
        $editId=(int)($_POST['transaction_id']??0);
        $existingTx=null;
        if($editId>0){
            $existingSt=$pdo->prepare('SELECT t.* FROM transactions t WHERE t.id=? AND t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL LIMIT 1');
            $existingSt->execute([$editId,$cid]);
            $existingTx=$existingSt->fetch();
            if(!$existingTx) throw new RuntimeException('Delivery Challan not found.');
            $linkSt=$pdo->prepare('SELECT tl.to_transaction_id FROM transaction_links tl WHERE tl.company_id=? AND tl.from_transaction_id=? AND tl.relation_type="challan_to_sale" LIMIT 1');
            $linkSt->execute([$cid,$editId]);
            if($linkSt->fetchColumn()) throw new RuntimeException('Converted Delivery Challan cannot be edited.');
        }
        try{
            $party=(int)($_POST['party_id']??0);
            $st=$pdo->prepare('SELECT p.id FROM parties p WHERE p.id=? AND p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role="customer")');
            $st->execute([$party,$cid]); $pr=$st->fetch();
            if(!$pr) throw new RuntimeException('Customer is required.');
            $txnDate=transaction_datetime($_POST['txn_date']??null); $dueDate=$_POST['due_date']?:null; $notes=trim($_POST['notes']??'');
            $itemIds=$_POST['item_id']??[]; $qtys=$_POST['qty']??[]; $prices=$_POST['price']??[]; $discs=$_POST['discount']??[];
            $rowKeys=(array)($_POST['bundle_row_key']??[]); $parentKeys=(array)($_POST['bundle_parent_key']??[]); $childFlags=(array)($_POST['bundle_child']??[]);
            $rows=[]; $subtotal=0; $itemDisc=0; $seenRowKeys=[]; $parentRowsByKey=[];
            foreach($itemIds as $i=>$iid){
                $iid=(int)$iid; $q=(float)($qtys[$i]??0); $price=(float)($prices[$i]??0); $disc=max(0,(float)($discs[$i]??0));
                if($iid<=0 || $q<=0) continue;
                $rowKey=trim((string)($rowKeys[$i]??'')); if($rowKey==='') $rowKey='dc-row-'.$i.'-'.bin2hex(random_bytes(4));
                if(isset($seenRowKeys[$rowKey])) throw new RuntimeException('Duplicate bundle row key.');
                $seenRowKeys[$rowKey]=true;
                $parentKey=trim((string)($parentKeys[$i]??'')); $isChild=((int)($childFlags[$i]??0)===1);
                $itSt=$pdo->prepare('SELECT * FROM items WHERE id=? AND company_id=? AND active=1'); $itSt->execute([$iid,$cid]); $it=$itSt->fetch();
                if(!$it) throw new RuntimeException('Invalid item selected.');
                $parentItemId=0;
                if($isChild){
                    if($parentKey==='' || !isset($parentRowsByKey[$parentKey])) throw new RuntimeException('A bundle child item is missing its parent product.');
                    $parentItemId=(int)$parentRowsByKey[$parentKey]['item_id'];
                    $component=bundle_component_lookup($pdo,$cid,$parentItemId,$iid);
                    if(!$component) throw new RuntimeException('Invalid included free item for the selected bundle product.');
                    $parentQty=(float)$parentRowsByKey[$parentKey]['qty'];
                    $expectedQty=round($parentQty*(float)$component['quantity'],6);
                    if(abs($q-$expectedQty)>0.000001) throw new RuntimeException('Included free item quantity does not match the bundle quantity.');
                    $price=0; $disc=0;
                }
                $gross=$q*$price; if($disc>$gross)$disc=$gross;
                $rowData=['index'=>$i,'rowKey'=>$rowKey,'parentKey'=>$parentKey,'isChild'=>$isChild,'item_id'=>$iid,'qty'=>$q,'price'=>$price,'discount'=>$disc,'it'=>$it,'parentItemId'=>$parentItemId];
                $rows[]=$rowData;
                if(!$isChild){$parentRowsByKey[$rowKey]=$rowData;$subtotal+=$gross;$itemDisc+=$disc;}
            }
            if(!$rows) throw new RuntimeException('Add at least one item.');
            $invDisc=max(0,(float)($_POST['invoice_discount']??0));
            $shipping=max(0,(float)($_POST['shipping_charge']??0));
            $after=max(0,$subtotal-$itemDisc); if($invDisc>$after)$invDisc=$after;
            $total=round(max(0,$after-$invDisc+$shipping),2);
            // Advance payments are actual cash/bank receipts. They must be
            // recorded on payment_lines and posted to the selected account.
            $payMethods=$_POST['advance_method']??[];
            $payAmounts=$_POST['advance_amount']??[];
            $paymentRows=[]; $advance=0;
            foreach($payMethods as $pi=>$rawMethod){
                $a=max(0,(float)($payAmounts[$pi]??0));
                if($a<=0) continue;
                [$m,$acct]=normalize_payment_method($pdo,$cid,(string)$rawMethod,null);
                $paymentRows[]=[$m,$acct,$a];
                $advance += $a;
            }
            $advance=round($advance,2);
            if($advance>$total+0.01) throw new RuntimeException('Advance payment cannot be greater than total amount.');
            $cod=round(max(0,$total-$advance),2);
            $pdo->beginTransaction();
            $doc=trim($_POST['document_no']??'');
            // On edit, preserve the existing Delivery Challan number when the
            // field is blank; on create, generate the next available number.
            if($doc==='') $doc=$editId>0?(string)$existingTx['document_no']:next_document_in_transaction($pdo,$cid,'delivery_challan','DC-');
            $dup=$pdo->prepare('SELECT id FROM transactions WHERE company_id=? AND txn_type="delivery_challan" AND document_no=? AND deleted_at IS NULL'.($editId>0?' AND id<>?':''));
            $dupArgs=[$cid,$doc]; if($editId>0)$dupArgs[]=$editId; $dup->execute($dupArgs);
            if($dup->fetchColumn()) throw new RuntimeException('Delivery Challan number already exists.');
            if($editId>0){
                $pdo->prepare('DELETE FROM ledger_entries WHERE transaction_id=? AND company_id=?')->execute([$editId,$cid]);
                $pdo->prepare('DELETE FROM payment_lines WHERE transaction_id=?')->execute([$editId]);
                $pdo->prepare('DELETE FROM transaction_items WHERE transaction_id=?')->execute([$editId]);
                $pdo->prepare('UPDATE transactions SET party_id=?,document_no=?,txn_date=?,due_date=?,subtotal=?,item_discount=?,invoice_discount=?,tax=?,direct_expense=?,total=?,paid=?,due=?,currency_code=?,status=?,notes=? WHERE id=? AND company_id=? AND txn_type="delivery_challan" AND deleted_at IS NULL')->execute([$party,$doc,$txnDate,$dueDate,$subtotal,$itemDisc,$invDisc,0,$shipping,$total,$advance,$cod,$u['currency_code'],$cod<=0.0001?'paid':'open',$notes,$editId,$cid]);
                $tid=$editId;
            }else{
                $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$cid,$party,'delivery_challan',$doc,$txnDate,$dueDate,$subtotal,$itemDisc,$invDisc,0,$shipping,$total,$advance,$cod,$u['currency_code'],$cod<=0.0001?'paid':'open',$notes,$u['id']]);
                $tid=(int)$pdo->lastInsertId();
            }
            $insParent=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,bundle_parent_transaction_item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?,?)');
            $parentTxItemIds=[];
            foreach($rows as $rr){
                $parentTxId=null;
                if($rr['isChild']){
                    $parentTxId=$parentTxItemIds[$rr['parentKey']]??null;
                    if(!$parentTxId) throw new RuntimeException('Bundle parent item could not be linked.');
                }
                $insParent->execute([$tid,$rr['item_id'],$parentTxId,$rr['qty'],$rr['price'],$rr['discount'],0,max(0,$rr['qty']*$rr['price']-$rr['discount'])]);
                $newTi=(int)$pdo->lastInsertId();
                if(!$rr['isChild']) $parentTxItemIds[$rr['rowKey']]=$newTi;
            }

            // Store each actual advance method (Cash / Bank Account) against
            // the Delivery Challan so it appears in the account history.
            if($paymentRows){
                $pl=$pdo->prepare('INSERT INTO payment_lines(transaction_id,method,account_name,reference_no,amount,status) VALUES(?,?,?,?,?,?)');
                foreach($paymentRows as [$m,$acct,$a]) $pl->execute([$tid,$m,$acct?:null,null,$a,'completed']);

                $ledger=[];
                foreach($paymentRows as [$m,$acct,$a]){
                    [$code,$name]=payment_account_code($m,$acct);
                    $ledger[]=[$code,$name,$a,0,$doc];
                }
                $ledger[]=['2200','Customer Advances',0,$advance,$doc];
                $debit=0;$credit=0;
                foreach($ledger as $l){$debit+=round((float)$l[2],2);$credit+=round((float)$l[3],2);}
                if(abs(round($debit-$credit,2))>0.01) throw new RuntimeException('Delivery Challan advance accounting entry is not balanced.');
                post_ledger($pdo,$cid,$tid,$txnDate,$ledger);
            }
            audit($editId>0?'update':'create','transaction',$tid,['type'=>'delivery_challan','document'=>$doc,'total'=>$total,'advance'=>$advance,'cod'=>$cod]); $pdo->commit();
            flash('success',$editId>0?'Delivery Challan '.$doc.' updated successfully.':'Delivery Challan '.$doc.' saved successfully.');
            // Normal Save now opens the dedicated direct print-preview page (no popup).
            // Save and Print keeps the existing one-click save -> print behavior.
            if($saveAndPrint){ redirect('delivery-challan-new?dc_print_view='.$tid.'&autoprint=1'); }
            redirect('delivery-challan-new?dc_print_view='.$tid);
        }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); flash('error',$e->getMessage()); $backId=(int)($_POST['transaction_id']??0); redirect($backId>0?'delivery-challan-new?edit='.$backId:'delivery-challan-new'); }
    }
    page_start($previewMode?'Delivery Challan Preview':'Add Delivery Challan');
    if(isset($_GET['dc_print_view'])){
        $tid=(int)$_GET['dc_print_view'];
        $st=$pdo->prepare('SELECT t.*,p.name party_name,p.phone party_phone,p.address party_address FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL');
        $st->execute([$tid,$cid]); $tx=$st->fetch();
        if(!$tx){http_response_code(404);page_start('Delivery Challan Not Found');echo '<div class="panel"><h1>Delivery Challan not found</h1></div>';page_end();exit;}
        $itSt=$pdo->prepare('SELECT ti.*,i.name item_name,u.symbol unit_symbol FROM transaction_items ti JOIN items i ON i.id=ti.item_id LEFT JOIN units u ON u.id=i.unit_id WHERE ti.transaction_id=? ORDER BY ti.id');$itSt->execute([$tid]);$lines=$itSt->fetchAll();
        $pay=$pdo->prepare('SELECT * FROM payment_lines WHERE transaction_id=? ORDER BY id');$pay->execute([$tid]);$payments=$pay->fetchAll();
        $printPaper=setting('print_paper_size','A4',(int)$u['company_id']); if(!in_array($printPaper,['A4','A5'],true))$printPaper='A4';
        $companySt=$pdo->prepare('SELECT name,email,phone,address,logo_path FROM companies WHERE id=? LIMIT 1');$companySt->execute([$cid]);$companyMetaRow=$companySt->fetch()?:[];
        /* The surrounding delivery-challan page already called page_start();
           do not start the full app shell again here or the top header is duplicated. */
        ?><style>.sense-direct-page-title{max-width:1180px;margin:12px auto 0;padding:0 12px;color:#475569;font-size:12px}.sense-direct-page-title strong{color:#0f172a;font-size:13px}</style>
        <div class="sense-direct-page-title"><strong>Delivery Challan Print Preview</strong> · <?=e($tx['document_no'])?></div>
        <?php render_delivery_challan_print_preview_direct($tx,$lines,$payments,$u,$printPaper,(string)($companyMetaRow['name']??($u['company_name']??'')),(string)($companyMetaRow['email']??''),(string)($companyMetaRow['phone']??''),(string)($companyMetaRow['address']??''),true); page_end();exit;
    }
    if(isset($_GET['view'])){
        $tid=(int)$_GET['view'];
        $st=$pdo->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL');
        $st->execute([$tid,$cid]); $tx=$st->fetch();
        if(!$tx){ flash('error','Delivery Challan not found.'); redirect('delivery-challans'); }
        $lines=$pdo->prepare('SELECT ti.*,i.name item_name FROM transaction_items ti JOIN items i ON i.id=ti.item_id WHERE ti.transaction_id=? ORDER BY ti.id');$lines->execute([$tid]);$lines=$lines->fetchAll();
        $lk=$pdo->prepare('SELECT tl.to_transaction_id FROM transaction_links tl WHERE tl.company_id=? AND tl.from_transaction_id=? AND tl.relation_type="challan_to_sale" LIMIT 1');$lk->execute([$cid,$tid]);$saleId=(int)($lk->fetchColumn()?:0);
        ?>
        <div class="panel print-company-header" style="margin-bottom:14px"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px"><div><?php $logo=saas_company_logo_url($u['logo_path']??null); if($logo): ?><img src="<?=e($logo)?>" alt="Company logo" style="max-height:56px;max-width:180px;object-fit:contain;margin-bottom:6px"><br><?php endif; ?><h2 style="margin:0"><?=e($u['company_name']??'')?></h2><div class="subtle">Delivery Challan</div></div><div style="text-align:right"><strong><?=e($tx['document_no'])?></strong><br><?=e(date('d/m/Y',strtotime($tx['txn_date'])))?></div></div></div><div class="page-title"><div><h1>Delivery Challan <?=e($tx['document_no'])?></h1><p><?=e($tx['party_name']??'')?> · <?=e($tx['txn_date'])?></p></div><div><a class="btn" href="<?=e(url('delivery-challans'))?>">Back</a><?php if(!$saleId):?><a class="btn primary" href="<?=e(url('delivery-challans?convert='.(int)$tx['id']))?>" onclick="return confirm('Convert this Delivery Challan to Sale?')">Convert to Sale</a><?php else:?><a class="btn primary" href="<?=e(url('sales?view='.$saleId))?>">View Sale</a><?php endif;?></div></div>
        <div class="cards-top"><div class="metric-card"><div class="label">Total Amount</div><div class="value"><?=money((float)$tx['total'])?></div></div><div class="metric-card"><div class="label">Advance Payment</div><div class="value"><?=money((float)$tx['paid'])?></div></div><div class="metric-card"><div class="label">Cash on Delivery</div><div class="value"><?=money((float)$tx['due'])?></div></div><div class="metric-card"><div class="label">Status</div><div class="value" style="font-size:20px"><?=e($saleId?'Converted':(($tx['status']??'open')==='paid'?'Paid':'Open'))?></div></div></div>
        <div class="panel"><div class="panel-head"><h2>ITEMS</h2></div><div class="table-wrap"><table><thead><tr><th>#</th><th>ITEM</th><th>QTY</th><th>PRICE/UNIT</th><th>DISCOUNT</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($lines as $i=>$r):?><tr><td><?=$i+1?></td><td><?=e($r['item_name'])?></td><td><?=qty((float)$r['qty'])?></td><td><?=money((float)$r['unit_price'])?></td><td><?=money((float)$r['discount'])?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div></div>
        <?php $payQ=$pdo->prepare('SELECT * FROM payment_lines WHERE transaction_id=? ORDER BY id');$payQ->execute([$tid]);$viewPayments=$payQ->fetchAll(); if($viewPayments):?>
        <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>ADVANCE PAYMENTS</h2></div><div class="table-wrap"><table><thead><tr><th>METHOD</th><th>ACCOUNT</th><th>REFERENCE</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($viewPayments as $pr):?><tr><td><?=e(ucwords(str_replace('_',' ',$pr['method'])))?></td><td><?=e($pr['account_name']??'—')?></td><td><?=e($pr['reference_no']??'—')?></td><td><?=money((float)$pr['amount'])?></td></tr><?php endforeach;?></tbody></table></div></div>
        <?php endif;?>

<script>
/* v108: repair the live expense row DOM and bind the exact row controls. */
(function(){
  function repairRows(){
    const body=document.getElementById('expenseItemRows');
    if(!body) return;
    body.querySelectorAll('tr.v107-expense-row').forEach(function(row){
      const cells=row.children;
      while(row.children.length<5){ row.appendChild(document.createElement('td')); }
      const itemCell=row.children[1];
      const qtyCell=row.children[2];
      const priceCell=row.children[3];
      const amountCell=row.children[4];
      itemCell.className=itemCell.className||'v107-item-cell';
      qtyCell.className='v107-qty-cell';
      priceCell.className='v107-price-cell';
      amountCell.className='v107-amount-cell';
      if(!qtyCell.querySelector('input')){
        qtyCell.innerHTML='<input class="v107-qty v94-qty" name="item_qty[]" type="number" min="0.001" step="0.001" value="1">';
      }
      if(!priceCell.querySelector('input')){
        priceCell.innerHTML='<input class="v107-price v94-price" name="item_price[]" type="number" min="0" step="0.01" value="0">';
      }
      if(!amountCell.querySelector('.v107-amount')){
        amountCell.innerHTML='<span class="v107-amount v94-amount">৳0.00</span>';
      }
    });
  }
  function recalc(){
    let total=0;
    document.querySelectorAll('#expenseItemRows tr.v107-expense-row').forEach(function(row){
      const q=Math.max(0,parseFloat(row.querySelector('.v107-qty,.v94-qty')?.value||0));
      const p=Math.max(0,parseFloat(row.querySelector('.v107-price,.v94-price')?.value||0));
      const a=Math.round(q*p*100)/100;
      total+=a;
      const out=row.querySelector('.v107-amount,.v94-amount');
      if(out) out.textContent='৳'+a.toFixed(2);
    });
    total=Math.round(total*100)/100;
    const amount=document.getElementById('expenseAmount'); if(amount) amount.value=total.toFixed(2);
    const pay=document.getElementById('expensePayAmount'); if(pay) pay.value=total.toFixed(2);
    const grand=document.getElementById('expenseGrandTotal'); if(grand) grand.textContent='৳'+total.toFixed(2);
    const preview=document.getElementById('expenseTotalPreview'); if(preview) preview.textContent='৳'+total.toFixed(2);
  }
  window.v94Recalc=recalc;
  window.v94AddRow=function(){
    const body=document.getElementById('expenseItemRows');
    const first=body?.querySelector('tr.v107-expense-row');
    if(!body||!first) return;
    const clone=first.cloneNode(true);
    const sel=clone.querySelector('.v107-item-select,.v94-item-select');
    if(sel){ sel.value=''; sel.dataset.selected=''; }
    const q=clone.querySelector('.v107-qty,.v94-qty'); if(q) q.value='1';
    const p=clone.querySelector('.v107-price,.v94-price'); if(p) p.value='0';
    const a=clone.querySelector('.v107-amount,.v94-amount'); if(a) a.textContent='৳0.00';
    body.appendChild(clone);
    if(sel && typeof window.v98PopulateItemSelect==='function') window.v98PopulateItemSelect(sel);
    recalc();
  };
  function bind(){
    repairRows();
    document.querySelectorAll('#expenseItemRows').forEach(function(body){
      body.addEventListener('input',function(e){
        if(e.target.matches('.v107-qty,.v107-price,.v94-qty,.v94-price')) recalc();
      });
    });
    recalc();
  }
  document.addEventListener('DOMContentLoaded',bind);
  setTimeout(bind,50);
})();
</script>
        <?php page_end();exit;
    }
    $editMode=false; $editTx=null; $editLines=[]; $editPayments=[];
    if(isset($_GET['edit']) || $previewMode){
        $editId=$previewMode ? $previewId : (int)$_GET['edit'];
        $st=$pdo->prepare('SELECT t.*,p.name party_name,p.phone party_phone FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type="delivery_challan" AND t.deleted_at IS NULL LIMIT 1');
        $st->execute([$editId,$cid]); $editTx=$st->fetch();
        if(!$editTx){ flash('error','Delivery Challan not found.'); redirect('delivery-challans'); }
        $lk=$pdo->prepare('SELECT tl.to_transaction_id FROM transaction_links tl WHERE tl.company_id=? AND tl.from_transaction_id=? AND tl.relation_type="challan_to_sale" LIMIT 1');$lk->execute([$cid,$editId]);
        if($lk->fetchColumn() && !$previewMode){ flash('error','Converted Delivery Challan cannot be edited.'); redirect('delivery-challans'); }
        $iq=$pdo->prepare('SELECT ti.*,i.name item_name,u.symbol unit_symbol FROM transaction_items ti JOIN items i ON i.id=ti.item_id LEFT JOIN units u ON u.id=i.unit_id WHERE ti.transaction_id=? ORDER BY ti.id');$iq->execute([$editId]);$editLines=$iq->fetchAll();
        $pq=$pdo->prepare('SELECT * FROM payment_lines WHERE transaction_id=? ORDER BY id');$pq->execute([$editId]);$editPayments=$pq->fetchAll();
        $editMode=true;
    }
    $partySt=$pdo->prepare('SELECT p.id,p.name,p.phone FROM parties p WHERE p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role="customer") ORDER BY p.name'); $partySt->execute([$cid]); $parties=$partySt->fetchAll();
    $bankSt=$pdo->prepare('SELECT id,name,bank_name,account_number FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY name'); $bankSt->execute([$cid]); $advanceBanks=$bankSt->fetchAll();
    // Delivery Challan uses a dedicated, minimal item query so item selection
    // does not depend on stock-movement calculation or any optional joins.
    $itemSt=$pdo->prepare('SELECT id,item_type,name,sale_price,unit_id FROM items WHERE company_id=? AND active=1 ORDER BY name');
    $itemSt->execute([$cid]);
    $items=$itemSt->fetchAll(PDO::FETCH_ASSOC);
    $unitSymbols=[];
    $unitIds=[];
    foreach($items as $it){ if(isset($it['unit_id']) && $it['unit_id']!==null && $it['unit_id']!=='') $unitIds[]=(int)$it['unit_id']; }
    $unitIds=array_values(array_unique(array_filter($unitIds,fn($v)=>$v>0)));
    if($unitIds){
        $ph=implode(',',array_fill(0,count($unitIds),'?'));
        $ust=$pdo->prepare("SELECT id,symbol FROM units WHERE company_id=? AND id IN ($ph)");
        $ust->execute(array_merge([$cid],$unitIds));
        foreach($ust->fetchAll(PDO::FETCH_ASSOC) as $ur) $unitSymbols[(int)$ur['id']]=$ur['symbol'];
    }
    ?>
    <style>
      .delivery-entry-form{padding-bottom:88px}.delivery-entry-form .standard-entry-top{align-items:end}.delivery-entry-form .entry-actions{display:flex;justify-content:space-between;align-items:center;padding:10px 0 6px}.delivery-entry-form .entry-actions .dc-items-total{font-size:14px;color:#334155}.delivery-entry-form .entry-actions .dc-items-total strong{font-size:18px;margin-left:6px;color:#0f172a}.dc-summary-grid{display:grid;grid-template-columns:1fr minmax(360px,520px);gap:24px;align-items:start;margin-top:4px}.dc-summary-box{border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:14px 16px 16px;box-shadow:0 1px 3px rgba(15,23,42,.04)}.dc-field{display:grid;grid-template-columns:1fr 180px;gap:14px;align-items:center;margin:8px 0}.dc-field label{font-size:12px;color:#64748b}.dc-field input{height:38px;border:1px solid #cbd5e1;border-radius:6px;padding:0 10px;box-sizing:border-box;text-align:right;background:#fff}.dc-total-row{display:flex;justify-content:space-between;align-items:center;padding:11px 0;border-top:1px solid #e2e8f0;margin-top:8px}.dc-total-row span{font-weight:600;color:#334155}.dc-total-row strong{font-size:18px;color:#0f172a}.dc-total-row.cod{border-top:2px solid #334155}.dc-total-row.cod strong{color:#0b8f55}.dc-advance-panel{margin-top:10px;padding:10px 0 4px;border-top:1px solid #e2e8f0}.dc-advance-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}.dc-advance-head label{font-size:12px;color:#64748b}.dc-advance-row{display:grid;grid-template-columns:1fr 150px 32px;gap:8px;align-items:center;margin-bottom:8px}.dc-advance-row select,.dc-advance-row input{height:36px;border:1px solid #cbd5e1;border-radius:6px;padding:0 9px;box-sizing:border-box;background:#fff}.dc-payment-remove{height:36px;width:32px;border:1px solid #cbd5e1;background:#fff;border-radius:6px;cursor:pointer;color:#64748b}.dc-payment-remove:hover{color:#dc2626;border-color:#fca5a5}.adv-total{border-top:1px solid #e2e8f0}.delivery-entry-form .form-group textarea{min-height:72px}.dc-description-box{min-height:100%;padding:0 4px}.dc-description-box .form-group{margin:0}.dc-description-box label{display:block;font-size:12px;color:#64748b;margin-bottom:6px}.dc-description-box textarea{width:100%;min-height:126px;resize:vertical;border:1px solid #cbd5e1;border-radius:8px;padding:10px 11px;box-sizing:border-box;background:#fff;color:#334155;outline:none}.dc-description-box textarea:focus{border-color:#1687ea;box-shadow:0 0 0 2px rgba(22,135,234,.10)}.delivery-entry-form .form-footer{position:sticky;bottom:0;z-index:20;background:rgba(255,255,255,.96);backdrop-filter:blur(4px);border-top:1px solid #e2e8f0}.dc-form-footer{display:flex;justify-content:flex-end;align-items:center;gap:8px;padding:10px 16px}.dc-footer-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px}.dc-footer-cod{display:inline-flex;align-items:center;gap:4px;padding:9px 2px;color:#334155;font-size:20px;white-space:nowrap}.dc-footer-cod strong{color:#0b8f55;font-size:20px}@media(max-width:600px){.dc-form-footer{padding:9px 10px}.dc-footer-actions{gap:6px;flex-wrap:wrap}.dc-footer-cod{font-size:12px}}.delivery-entry-form .entry-table th,.delivery-entry-form .entry-table td{padding:7px 8px}
.delivery-entry-form .entry-table table{width:100%;table-layout:auto}
.delivery-entry-form .entry-table th:nth-child(1),.delivery-entry-form .entry-table td:nth-child(1){width:40px;min-width:40px}
.delivery-entry-form .entry-table th:nth-child(2),.delivery-entry-form .entry-table td:nth-child(2){min-width:260px;width:36%}
.delivery-entry-form .entry-table th:nth-child(3),.delivery-entry-form .entry-table td:nth-child(3){min-width:72px;width:9%}
.delivery-entry-form .entry-table th:nth-child(4),.delivery-entry-form .entry-table td:nth-child(4){min-width:55px;width:7%}
.delivery-entry-form .entry-table th:nth-child(5),.delivery-entry-form .entry-table td:nth-child(5){min-width:110px;width:14%}
.delivery-entry-form .entry-table th:nth-child(6),.delivery-entry-form .entry-table td:nth-child(6){min-width:110px;width:14%}
.delivery-entry-form .entry-table th:nth-child(7),.delivery-entry-form .entry-table td:nth-child(7){min-width:120px;width:16%;border-right:0}
.delivery-entry-form .entry-table td:nth-child(3),.delivery-entry-form .entry-table td:nth-child(4),.delivery-entry-form .entry-table td:nth-child(5),.delivery-entry-form .entry-table td:nth-child(6),.delivery-entry-form .entry-table td:nth-child(7){vertical-align:top;padding-top:7px}
.delivery-entry-form .entry-table .dc-price,.delivery-entry-form .entry-table .dc-line-discount,.delivery-entry-form .entry-table .dc-qty{width:100%;min-width:0;max-width:none;box-sizing:border-box}
.delivery-entry-form .entry-table .dc-line-discount{display:block}
.delivery-entry-form .entry-table .dc-amt{padding-top:7px;display:block;font-variant-numeric:tabular-nums;white-space:nowrap;text-align:right}
.delivery-entry-form .entry-table .txn-row-index-cell{vertical-align:top}
.delivery-entry-form .entry-table .dc-bundle-child-row .bundle-child-label{display:flex;justify-content:space-between;gap:8px;align-items:center}
.delivery-entry-form .entry-table .dc-bundle-child-row .bundle-child-label>span:last-child{color:#0b8f55;font-weight:700;font-size:10px}.delivery-entry-form .entry-table input{height:36px}.delivery-entry-form .entry-table .dc-qty{width:78px;min-width:78px;max-width:78px;display:block;box-sizing:border-box}.delivery-entry-form .item-picker-cell{min-width:260px}@media(max-width:900px){.dc-summary-grid{grid-template-columns:1fr}.dc-summary-spacer{display:none}.dc-field{grid-template-columns:1fr 160px}}@media(max-width:600px){.dc-field{grid-template-columns:1fr}.dc-field input{width:100%}.delivery-entry-form .entry-table{overflow:auto}.delivery-entry-form .entry-table table{min-width:850px}}</style>
    <div class="page-title"><div><h1><?= $previewMode?'Delivery Challan Print Preview':($editMode?'Edit Delivery Challan':'Delivery Challan') ?></h1><p><?= $previewMode?'A4 print-ready preview of this Delivery Challan.':($editMode?'Update the Delivery Challan before it is converted.':'Create a delivery challan without posting sale/accounting until it is converted.') ?></p></div><a class="btn" href="<?=e(url('delivery-challans'))?>">Back to Delivery Challans</a></div>
    <form method="post" id="dcForm" class="panel standard-entry-form delivery-entry-form">
      <input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_delivery_challan"><?php if($editMode): ?><input type="hidden" name="transaction_id" value="<?=e((string)$editTx['id'])?>"><?php endif; ?>
      <div class="entry-top standard-entry-top">
        <div class="standard-party-field"><?php party_search_field('Customer','customer',$editMode?(int)$editTx['party_id']:0,$editMode?(string)($editTx['party_name']??''):'',$editMode?(string)($editTx['party_phone']??''):'',true); ?><div style="margin-top:6px"><button type="button" class="btn small-btn" onclick="senseOpenInlinePartyModal('customer')">+ Add Party</button></div></div>
        <div class="form-group"><label>Challan Number</label><input name="document_no" value="<?=e($editMode?(string)$editTx['document_no']:'')?>" placeholder="Auto: DC-01"></div>
        <div class="form-group"><label>Challan Date*</label><input type="date" name="txn_date" value="<?=e($editMode?date('Y-m-d',strtotime($editTx['txn_date'])):date('Y-m-d'))?>" required></div>
      </div>
      <div class="entry-table"><table><thead><tr><th></th><th>ITEM</th><th>QTY</th><th>UNIT</th><th>PRICE/UNIT</th><th>DISCOUNT</th><th>AMOUNT</th></tr></thead><tbody id="dcRows">
        <?php $renderLines=$editMode&&$editLines?$editLines:[null]; foreach($renderLines as $idx=>$ln):
          $liId=(int)($ln['item_id']??0);
          $liName=(string)($ln['item_name']??'');
          $liQty=(float)($ln['qty']??1);
          $liPrice=(float)($ln['unit_price']??0);
          $liDisc=(float)($ln['discount']??0);
          $liUnit=(string)($ln['unit_symbol']??'');
          $childParentId=(int)($ln['bundle_parent_transaction_item_id']??0);
          $isChild=$childParentId>0;
          $rowKey=$ln?'tx-ti-'.(int)$ln['id']:'dc-new-'.bin2hex(random_bytes(5));
          $parentKey=$isChild?'tx-ti-'.$childParentId:'';
        ?>
        <tr class="<?= $isChild?'dc-bundle-child-row':'' ?>" data-bundle-child="<?= $isChild?'1':'0' ?>" data-bundle-parent-key="<?=e($parentKey)?>" data-bundle-row-key="<?=e($rowKey)?>">
          <td class="txn-row-index-cell">
            <button type="button" class="row-remove-btn txn-row-remove" onclick="dcRemoveRow(this)" aria-label="Remove item row" title="Remove row">×</button>
            <span class="txn-row-number"><?=($idx+1)?></span>
          </td>
          <td>
            <input type="hidden" name="bundle_row_key[]" value="<?=e($rowKey)?>">
            <input type="hidden" name="bundle_parent_key[]" value="<?=e($parentKey)?>">
            <input type="hidden" name="bundle_child[]" value="<?= $isChild?'1':'0'?>">
            <div class="item-picker-cell">
              <?php if($isChild): ?>
                <div class="bundle-child-label"><span>└─ <strong><?=e($liName)?></strong></span><span>FREE</span></div>
              <?php else: ?>
                <?php item_search_field($liId,$liName,'','sale'); ?>
                <select name="item_id[]" class="dc-item item-source-select" tabindex="-1" aria-hidden="true">
                  <?php if($liId>0): $liBundle=bundle_components_for_parent($pdo,$cid,$liId); ?>
                    <option value="<?=$liId?>" data-price="<?=e((string)$liPrice)?>" data-unit="<?=e($liUnit)?>" data-sale="<?=e((string)$liPrice)?>" data-buy="0" data-bundle="<?=e(bundle_option_json($liBundle))?>" selected><?=e($liName)?></option>
                  <?php endif; ?>
                </select>
              <?php endif; ?>
            </div>
          </td>
          <td><input type="number" class="dc-qty" name="qty[]" step="1" min="1" value="<?=e((string)$liQty)?>" <?= $isChild?'readonly':''?>></td>
          <td class="dc-unit"><?=e($liUnit!==''?$liUnit:'—')?></td>
          <td><input type="number" class="dc-price" name="price[]" step="0.01" min="0" value="<?=e((string)$liPrice)?>" <?= $isChild?'readonly':''?>></td>
          <td><input type="number" class="dc-line-discount" name="discount[]" step="1" min="0" value="<?=e((string)$liDisc)?>" <?= $isChild?'readonly':''?>></td>
          <td class="dc-amt" data-discount="<?=e((string)$liDisc)?>">৳<?=number_format(max(0,$liQty*$liPrice-$liDisc),2,'.',',')?></td>
        </tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <div id="dcItemStatus" class="subtle" style="margin-top:8px"></div>
      <div class="entry-actions"><div style="display:flex;gap:8px"><button type="button" class="btn" onclick="dcAddRow()">+ Add Row</button><button type="button" class="btn" onclick="senseOpenInlineProductModal('#dcRows','delivery')">+ Add Product</button></div><span class="dc-items-total"><b>Items Total</b> <strong id="dcSubtotal">৳0.00</strong></span></div>
      <div class="dc-summary-grid">
        <div class="dc-summary-spacer dc-description-box">
          <div class="form-group"><label>Add Description</label><textarea name="notes" rows="3" placeholder="Optional delivery note"><?=e($editMode?(string)($editTx['notes']??''):'')?></textarea></div>
        </div>
        <div class="dc-summary-box">
          <div class="dc-field"><label>Discount</label><input id="dcInvDisc" type="number" name="invoice_discount" min="0" step="0.01" value="<?=e((string)($editMode?(float)$editTx['invoice_discount']:0))?>"></div>
          <div class="dc-field"><label>Courier / Shipping Charge</label><input id="dcShipping" type="number" name="shipping_charge" min="0" step="0.01" value="<?=e((string)($editMode?(float)$editTx['direct_expense']:0))?>"></div>
          <div class="dc-total-row"><span>Total Amount</span><strong id="dcTotal">৳0.00</strong></div>
          <div class="dc-advance-panel">
            <div class="dc-advance-head"><label>Advance Payment</label><span class="subtle">Cash / Bank Account</span></div>
            <div id="dcAdvanceRows">
              <?php $renderPays=$editMode&&$editPayments?$editPayments:[['method'=>'cash','account_name'=>null,'amount'=>0]]; foreach($renderPays as $pr): ?>
              <div class="dc-advance-row"><select name="advance_method[]"><?=advance_payment_options($advanceBanks,(string)($pr['method']??'cash'),(string)($pr['account_name']??''))?></select><input class="dc-advance-amt" type="number" name="advance_amount[]" min="0" step="0.01" value="<?=e((string)(float)($pr['amount']??0))?>"><button type="button" class="dc-payment-remove" onclick="dcRemoveAdvance(this)" aria-label="Remove advance">×</button></div>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn small-btn" onclick="dcAddAdvance()">+ Add Payment</button>
          </div>
          <div class="dc-total-row adv-total"><span>Advance Paid</span><strong id="dcAdvanceTotal">৳0.00</strong></div>
          <div class="dc-total-row cod"><span>Cash on Delivery</span><strong id="dcCod">৳0.00</strong></div>
        </div>
      </div>
      <div class="form-footer dc-form-footer" style="margin:0 -16px -16px">
        <div class="dc-footer-actions">
          <span class="dc-footer-cod">COD: <strong id="dcCodFooter">৳0.00</strong></span>
          <a class="btn" href="<?=e(url('delivery-challans'))?>">Cancel</a>
          <button type="submit" name="save_and_print" value="1" class="btn">Save and Print</button>
          <button class="btn primary"><?= $editMode?'Update':'Save' ?></button>
        </div>
      </div>
    </form>
    <?php
    $printCompany = ['name'=>(string)($u['company_name']??''),'phone'=>'','email'=>'','address'=>''];
    try{
        $pcs=$pdo->prepare('SELECT name,phone,email,address,logo_path FROM companies WHERE id=? LIMIT 1');
        $pcs->execute([$cid]);
        if($pc=$pcs->fetch()){
            $printCompany['name']=(string)($pc['name']??$printCompany['name']);
            $printCompany['phone']=(string)($pc['phone']??'');
            $printCompany['email']=(string)($pc['email']??'');
            $printCompany['address']=(string)($pc['address']??'');
        }
    }catch(Throwable $e){}
    $printLogo=saas_company_logo_url($u['logo_path']??null);
    ?>

    <style>
      .dc-print-sheet{display:block;background:#fff;box-shadow:0 1px 8px rgba(15,23,42,.10);}
      .dc-print-sheet *{box-sizing:border-box}
      .dc-print-inner{width:100%;max-width:820px;margin:0 auto;background:#fff;color:#172033;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.45}
      .dc-print-header{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;padding-bottom:12px;border-bottom:2px solid #172033}
      .dc-print-brand{display:flex;gap:12px;align-items:flex-start;min-width:0}
      .dc-print-logo{max-width:150px;max-height:58px;object-fit:contain}
      .dc-print-company-name{font-size:22px;font-weight:700;line-height:1.2;margin:0 0 3px}
      .dc-print-company-meta{font-size:10.5px;color:#5f6b7a;line-height:1.45;max-width:430px;white-space:pre-line}
      .dc-print-title{text-align:right;min-width:180px}
      .dc-print-title h1{font-size:20px;letter-spacing:.4px;margin:0 0 5px}
      .dc-print-title .dc-no{font-size:14px;font-weight:700}
      .dc-print-title .dc-date{font-size:11px;color:#5f6b7a;margin-top:2px}
      .dc-print-customer{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:14px 0}
      .dc-print-box{border:1px solid #dbe2ea;border-radius:7px;padding:9px 11px}
      .dc-print-label{font-size:9px;text-transform:uppercase;color:#778398;letter-spacing:.45px;margin-bottom:3px}
      .dc-print-value{font-size:13px;font-weight:600;color:#172033}
      .dc-print-table{width:100%;border-collapse:collapse;margin-top:8px}
      .dc-print-table th{background:#f3f6f9;color:#526174;font-size:9.5px;text-transform:uppercase;letter-spacing:.35px;padding:8px 7px;border:1px solid #dbe2ea;text-align:left}
      .dc-print-table td{padding:8px 7px;border:1px solid #dbe2ea;vertical-align:top}
      .dc-print-table .num{text-align:right;white-space:nowrap}
      .dc-print-summary-wrap{display:flex;justify-content:flex-end;margin-top:12px}
      .dc-print-summary{width:310px}
      .dc-print-summary-row{display:flex;justify-content:space-between;gap:20px;padding:6px 0;border-bottom:1px solid #e8edf2}
      .dc-print-summary-row span{color:#5f6b7a}
      .dc-print-summary-row strong{font-weight:600}
      .dc-print-summary-row.total{font-size:14px;border-top:1.5px solid #172033;border-bottom:1px solid #172033;padding:9px 0}
      .dc-print-summary-row.cod strong{color:#0b8f55}
      .dc-print-payments{margin-top:14px}
      .dc-print-payments-title{font-weight:700;margin-bottom:5px}
      .dc-print-payment{display:flex;justify-content:space-between;padding:4px 0;color:#3e4a5a}
      .dc-print-notes{margin-top:15px;border:1px solid #dbe2ea;border-radius:7px;padding:10px 11px;min-height:52px}
      .dc-print-footer{display:grid;grid-template-columns:1fr 1fr;gap:60px;margin-top:36px;padding-top:8px}
      .dc-print-sign{border-top:1px solid #9aa6b4;text-align:center;padding-top:6px;font-size:10px;color:#657183}
      .dc-print-disclaimer{margin-top:18px;text-align:center;font-size:9px;color:#7b8794}
      .dc-print-preview-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.72);z-index:2147483640;overflow:auto;padding:20px}
      .dc-print-preview-overlay.open{display:block}
      .dc-print-preview-toolbar{position:sticky;top:0;z-index:2;display:flex;justify-content:flex-end;gap:8px;padding:0 0 12px}
      .dc-print-preview-toolbar .btn{box-shadow:0 2px 6px rgba(0,0,0,.15)}
      .dc-print-preview-frame{width:min(210mm,100%);min-height:calc(100vh - 80px);margin:0 auto;background:#eef1f5;padding:14px;box-sizing:border-box}
      .dc-print-sheet{display:block;background:#fff;box-shadow:0 1px 8px rgba(15,23,42,.10);}
      @page{size:A4 portrait;margin:10mm 12mm}
      @media print{
        @page{size:A4 portrait;margin:10mm 12mm}
        html,body{background:#fff!important;margin:0!important;padding:0!important}
        body.dc-printing{margin:0!important;padding:0!important;min-height:0!important;background:#fff!important}
        /* The editor stays in normal layout on screen, but must not reserve print pages.
           Only the dedicated challan sheet is rendered by the print routine. */
        body.dc-printing > .app-shell{display:none!important}
        body.dc-printing .dc-print-preview-overlay{display:none!important}
      }
    </style>

    <div class="dc-print-preview-overlay" id="dcPrintPreviewOverlay" aria-hidden="true">
      <div class="dc-print-preview-toolbar">
        <button type="button" class="btn" onclick="dcClosePrintPreview()">Close Preview</button>
        <button type="button" class="btn primary" onclick="dcPrintNow()">Print</button>
      </div>
      <div class="dc-print-preview-frame">
        <section class="dc-print-sheet" id="dcPrintSheet" aria-hidden="true">
      <div class="dc-print-inner">
        <div class="dc-print-header">
          <div class="dc-print-brand">
            <?php if($printLogo): ?><img class="dc-print-logo" src="<?=e($printLogo)?>" alt="Company logo"><?php endif; ?>
            <div>
              <div class="dc-print-company-name" data-dc-print-company><?=e($printCompany['name'])?></div>
              <div class="dc-print-company-meta" data-dc-print-company-meta><?=e(trim(implode("\n",array_filter([$printCompany['address'],$printCompany['phone'],$printCompany['email']]))) )?></div>
            </div>
          </div>
          <div class="dc-print-title">
            <h1>DELIVERY CHALLAN</h1>
            <div class="dc-no" data-dc-print-number>—</div>
            <div class="dc-date" data-dc-print-date>—</div>
          </div>
        </div>

        <div class="dc-print-customer">
          <div class="dc-print-box">
            <div class="dc-print-label">Customer</div>
            <div class="dc-print-value" data-dc-print-customer>—</div>
          </div>
          <div class="dc-print-box">
            <div class="dc-print-label">Purpose</div>
            <div class="dc-print-value">Goods Delivery</div>
          </div>
        </div>

        <table class="dc-print-table">
          <thead>
            <tr><th style="width:36px">#</th><th>Item</th><th style="width:70px">Qty</th><th style="width:70px">Unit</th><th style="width:110px">Price/Unit</th><th style="width:120px">Amount</th></tr>
          </thead>
          <tbody data-dc-print-items><tr><td colspan="6">No items.</td></tr></tbody>
        </table>

        <div class="dc-print-summary-wrap">
          <div class="dc-print-summary">
            <div class="dc-print-summary-row"><span>Items Total</span><strong data-dc-print-subtotal>৳0.00</strong></div>
            <div class="dc-print-summary-row"><span>Discount</span><strong data-dc-print-discount>৳0.00</strong></div>
            <div class="dc-print-summary-row"><span>Courier / Shipping</span><strong data-dc-print-shipping>৳0.00</strong></div>
            <div class="dc-print-summary-row total"><span>Total Amount</span><strong data-dc-print-total>৳0.00</strong></div>
            <div class="dc-print-summary-row"><span>Advance Paid</span><strong data-dc-print-advance>৳0.00</strong></div>
            <div class="dc-print-summary-row cod"><span>Cash on Delivery</span><strong data-dc-print-cod>৳0.00</strong></div>
          </div>
        </div>

        <div class="dc-print-payments" data-dc-print-payments-wrap style="display:none">
          <div class="dc-print-payments-title">Advance Payment</div>
          <div data-dc-print-payments></div>
        </div>

        <div class="dc-print-notes">
          <div class="dc-print-label">Delivery Note</div>
          <div data-dc-print-notes>—</div>
        </div>

        <div class="dc-print-footer">
          <div class="dc-print-sign">Received By</div>
          <div class="dc-print-sign">Authorized Signature</div>
        </div>
        <div class="dc-print-disclaimer">This document is a delivery challan and is not a tax invoice.</div>
      </div>
    </section>
      </div>
    </div>

    <script>
    function dcSyncPrintSheet(){
      const sheet=document.getElementById('dcPrintSheet'); if(!sheet)return;
      const val=(sel,root=document)=>root.querySelector(sel)?.value??'';
      const text=(sel,root=document)=>root.querySelector(sel)?.textContent?.trim()??'';
      const set=(sel,v)=>{const el=sheet.querySelector(sel);if(el)el.textContent=v;};
      const partyInput=document.querySelector('#dcForm .party-search-input');
      const customer=(partyInput?.value||'').trim()||'—';
      set('[data-dc-print-customer]',customer);
      set('[data-dc-print-number]',val('#dcForm [name="document_no"]')||'—');
      const rawDate=val('#dcForm [name="txn_date"]'); let niceDate='—';
      if(rawDate){const d=new Date(rawDate+'T00:00:00');if(!Number.isNaN(d.getTime()))niceDate=String(d.getDate()).padStart(2,'0')+'/'+String(d.getMonth()+1).padStart(2,'0')+'/'+d.getFullYear();}
      set('[data-dc-print-date]',niceDate);

      const rows=[];
      document.querySelectorAll('#dcRows tr').forEach((r,i)=>{
        const item=(r.querySelector('.item-search-input')?.value||r.querySelector('.dc-item')?.selectedOptions?.[0]?.textContent||'').trim();
        const qty=Number(r.querySelector('.dc-qty')?.value||0);
        const unit=(r.querySelector('.dc-unit')?.textContent||'—').trim();
        const price=Number(r.querySelector('.dc-price')?.value||0);
        const amount=Number((r.querySelector('.dc-amt')?.textContent||'').replace(/[^\d.-]/g,'')||0);
        if(item||qty||price||amount) rows.push({i:i+1,item,qty,unit,price,amount});
      });
      const tbody=sheet.querySelector('[data-dc-print-items]');
      if(tbody){
        tbody.innerHTML=rows.length?rows.map(r=>`<tr><td>${r.i}</td><td>${dcPrintEsc(r.item||'—')}</td><td class="num">${dcPrintQty(r.qty)}</td><td>${dcPrintEsc(r.unit||'—')}</td><td class="num">${dcFmt(r.price)}</td><td class="num">${dcFmt(r.amount)}</td></tr>`).join(''):'<tr><td colspan="6">No items.</td></tr>';
      }
      set('[data-dc-print-subtotal]',text('#dcSubtotal')||'৳0.00');
      set('[data-dc-print-discount]',dcFmt(Number(val('#dcInvDisc')||0)));
      set('[data-dc-print-shipping]',dcFmt(Number(val('#dcShipping')||0)));
      set('[data-dc-print-total]',text('#dcTotal')||'৳0.00');
      set('[data-dc-print-advance]',text('#dcAdvanceTotal')||'৳0.00');
      set('[data-dc-print-cod]',text('#dcCod')||'৳0.00');
      const notes=val('#dcForm [name="notes"]').trim();
      set('[data-dc-print-notes]',notes||'—');

      const payWrap=sheet.querySelector('[data-dc-print-payments-wrap]');
      const payBox=sheet.querySelector('[data-dc-print-payments]');
      if(payWrap&&payBox){
        const pays=[];
        document.querySelectorAll('#dcAdvanceRows .dc-advance-row').forEach(row=>{
          const m=row.querySelector('select')?.selectedOptions?.[0]?.textContent?.trim()||'Cash';
          const a=Number(row.querySelector('.dc-advance-amt')?.value||0);
          if(a>0)pays.push({m,a});
        });
        payBox.innerHTML=pays.map(p=>`<div class="dc-print-payment"><span>${dcPrintEsc(p.m)}</span><strong>${dcFmt(p.a)}</strong></div>`).join('');
        payWrap.style.display=pays.length?'block':'none';
      }
    }
    function dcPrintEsc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
    function dcPrintQty(v){const n=Number(v||0);return Number.isFinite(n)?n.toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:2}):'0';}
    function dcPrintPreview(){
      dcSyncPrintSheet();
      const overlay=document.getElementById('dcPrintPreviewOverlay');
      if(!overlay)return;
      overlay.classList.add('open');
      overlay.setAttribute('aria-hidden','false');
      document.body.style.overflow='hidden';
      window.scrollTo(0,0);
    }
    function dcClosePrintPreview(){
      if(window.__senseDcPreviewMode){
        window.location.href=<?=json_encode(url('delivery-challans'))?>;
        return;
      }
      const overlay=document.getElementById('dcPrintPreviewOverlay');
      if(!overlay)return;
      overlay.classList.remove('open');
      overlay.setAttribute('aria-hidden','true');
      document.body.style.overflow='';
      document.body.classList.remove('dc-printing');
    }
    function dcPrintNow(){
      dcSyncPrintSheet();
      const sheet=document.getElementById('dcPrintSheet');
      if(!sheet)return;
      // Print only the dedicated A4 sheet in an isolated iframe. This prevents the
      // hidden editor/app shell from reserving a blank first page in the PDF.
      const iframe=document.createElement('iframe');
      iframe.setAttribute('aria-hidden','true');
      iframe.style.position='fixed';
      iframe.style.right='0';
      iframe.style.bottom='0';
      iframe.style.width='0';
      iframe.style.height='0';
      iframe.style.border='0';
      iframe.style.visibility='hidden';
      document.body.appendChild(iframe);
      const doc=iframe.contentDocument;
      const headStyles=[];
      document.querySelectorAll('style').forEach(st=>{
        const css=st.textContent||'';
        if(css.includes('.dc-print-sheet') || css.includes('.dc-print-preview')) headStyles.push(css);
      });
      doc.open();
      doc.write('<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Delivery Challan</title>');
      doc.write('<style>html,body{margin:0;padding:0;background:#fff!important} @page{size:A4 portrait;margin:10mm 12mm} body{font-family:Arial,sans-serif} .dc-print-sheet{display:block!important;box-shadow:none!important;background:#fff!important;width:100%!important;margin:0!important} .dc-print-inner{max-width:none!important}</style>');
      headStyles.forEach(css=>doc.write('<style>'+css.replace(/<\/style/gi,'<\\/style')+'</style>'));
      doc.write('</head><body></body></html>');
      doc.close();
      const clone=sheet.cloneNode(true);
      clone.removeAttribute('aria-hidden');
      doc.body.appendChild(clone);
      const finish=()=>{
        try{ iframe.contentWindow.focus(); iframe.contentWindow.print(); }
        finally{ setTimeout(()=>iframe.remove(),1200); }
      };
      setTimeout(finish,250);
    }
    window.addEventListener('afterprint',function(){
      document.body.classList.remove('dc-printing');
    });
    </script>

    <?php if($previewMode): ?>
    <script>
      window.__senseDcPreviewMode = true;
      document.addEventListener('DOMContentLoaded',function(){
        if(typeof dcPrintPreview==='function') dcPrintPreview();
      });
    </script>
    <?php endif; ?>

    <script>
    function dcFmt(v){return '৳'+Number(v||0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
    function dcMakeRowKey(){return 'dc-row-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,10);}
    function dcGetBundle(row){
      const sel=row?.querySelector('.dc-item');
      if(!sel||!sel.value)return [];
      try{
        const raw=sel.selectedOptions?.[0]?.dataset?.bundle || row?.dataset?.bundleJson || '[]';
        return (JSON.parse(raw||'[]')||[]).map(function(c){return {
          item_id:Number(c.item_id||c.component_item_id||0),
          name:String(c.name||''),
          quantity:Number(c.quantity||1),
          unit_symbol:String(c.unit_symbol||'')
        };}).filter(function(c){return c.item_id>0;});
      }catch(e){return [];}
    }
    function dcRemoveBundleChildren(parentKey){
      if(!parentKey)return;
      document.querySelectorAll('#dcRows .dc-bundle-child-row').forEach(function(r){
        if(String(r.dataset.bundleParentKey||'')===String(parentKey)) r.remove();
      });
    }
    function dcRenumberRows(){
      document.querySelectorAll('#dcRows tr').forEach(function(r,i){
        const n=r.querySelector('.txn-row-number'); if(n)n.textContent=String(i+1);
      });
    }
    function dcCreateBundleChildRow(parentRow,component,parentKey){
      const tr=document.createElement('tr');
      tr.className='dc-bundle-child-row';
      tr.dataset.bundleChild='1';
      tr.dataset.bundleParentKey=String(parentKey);
      tr.dataset.bundleRowKey=dcMakeRowKey();
      const parentQty=Math.max(0,parseFloat(parentRow.querySelector('.dc-qty')?.value||0)||0);
      const totalQty=parentQty*Math.max(0,parseFloat(component.quantity||1)||1);
      const esc=function(v){return String(v||'').replace(/[&<>"]/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m];});};
      const name=esc(component.name),unit=esc(component.unit_symbol),itemId=Number(component.item_id||0);
      tr.innerHTML=
        '<td class="txn-row-index-cell"><button type="button" class="row-remove-btn txn-row-remove" onclick="dcRemoveRow(this)" aria-label="Remove free item" title="Remove free item">×</button><span class="txn-row-number"></span></td>'+
        '<td><input type="hidden" name="bundle_row_key[]" value="'+tr.dataset.bundleRowKey+'"><input type="hidden" name="bundle_parent_key[]" value="'+esc(parentKey)+'"><input type="hidden" name="bundle_child[]" value="1"><input type="hidden" name="item_id[]" value="'+itemId+'"><div class="item-picker-cell"><div class="bundle-child-label"><span>└─ <strong>'+name+'</strong></span><span>FREE</span></div></div></td>'+
        '<td><input type="number" class="dc-qty" name="qty[]" step="1" min="1" value="'+totalQty+'" readonly></td>'+
        '<td class="dc-unit">'+unit+'</td>'+
        '<td><input type="number" class="dc-price" name="price[]" step="0.01" min="0" value="0" readonly></td>'+
        '<td><input type="number" class="dc-line-discount" name="discount[]" step="1" min="0" value="0" readonly></td>'+
        '<td class="dc-amt" data-discount="0">৳0.00</td>';
      return tr;
    }
    function dcRenderBundleChildren(parentRow,components){
      const body=document.getElementById('dcRows'); if(!body)return;
      const parentKey=parentRow.querySelector('input[name="bundle_row_key[]"]')?.value||parentRow.dataset.bundleRowKey||'';
      if(!parentKey)return;
      dcRemoveBundleChildren(parentKey);
      parentRow.dataset.bundleJson=JSON.stringify(components||[]);
      if(!(components||[]).length){dcRenumberRows();return;}
      let anchor=parentRow;
      components.forEach(function(component){
        const child=dcCreateBundleChildRow(parentRow,component,parentKey);
        anchor.parentNode.insertBefore(child,anchor.nextSibling);
        anchor=child;
      });
      dcRenumberRows();
    }
    function dcUpdateBundleQuantities(parentRow){
      const parentKey=parentRow.querySelector('input[name="bundle_row_key[]"]')?.value||parentRow.dataset.bundleRowKey||'';
      if(!parentKey)return;
      const parentQty=Math.max(0,parseFloat(parentRow.querySelector('.dc-qty')?.value||0)||0);
      const comps=dcGetBundle(parentRow);
      if(!comps.length){dcSyncBundleForRow(parentRow);return;}
      const factorMap={};
      comps.forEach(function(c){factorMap[String(c.item_id)]=Number(c.quantity||1);});
      document.querySelectorAll('#dcRows .dc-bundle-child-row').forEach(function(r){
        if(String(r.dataset.bundleParentKey||'')!==String(parentKey))return;
        const id=r.querySelector('input[name="item_id[]"]')?.value||'';
        const q=r.querySelector('.dc-qty'); if(q)q.value=String(parentQty*(factorMap[String(id)]||1));
      });
    }
    function dcSyncBundleForRow(row){
      if(!row || row.dataset.bundleChild==='1')return;
      const itemId=Number(row.querySelector('.dc-item')?.value||0); if(!itemId)return;
      const parentKey=row.querySelector('input[name="bundle_row_key[]"]')?.value||row.dataset.bundleRowKey||'';
      if(!parentKey)return;
      let list=dcGetBundle(row);
      if(list.length){
        row.dataset.bundleFetchedItem=String(itemId);
        dcRenderBundleChildren(row,list);
        return;
      }
      const api=(window.SutoBundleComponentsConfig||{}).url;
      if(!api)return;
      if(row.dataset.bundleFetchPending===String(itemId))return;
      row.dataset.bundleFetchPending=String(itemId);
      const u=new URL(api,location.origin);u.searchParams.set('item_id',String(itemId));
      fetch(u.toString(),{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}})
        .then(function(res){return res.json().then(function(data){if(!res.ok||!data.ok)throw new Error(data.error||('HTTP '+res.status));return data;});})
        .then(function(data){
          row.dataset.bundleFetchPending='';
          row.dataset.bundleFetchedItem=String(itemId);
          const current=row.querySelector('.dc-item'); if(!current||Number(current.value||0)!==itemId)return;
          const comps=Array.isArray(data.items)?data.items:[];
          row.dataset.bundleJson=JSON.stringify(comps);
          dcRenderBundleChildren(row,comps);
        })
        .catch(function(err){row.dataset.bundleFetchPending='';console.error('Delivery Bundle sync failed:',err);});
    }
    function dcSetPrice(el){
      const o=el?.selectedOptions?.[0],r=el?.closest('tr'); if(!r)return;
      r.querySelector('.dc-price').value=o?.dataset.price||0;
      r.querySelector('.dc-unit').textContent=o?.dataset.unit||'—';
      const body=document.getElementById('dcRows');
      const rows=body?.querySelectorAll('tr');
      if(body && rows && rows.length && r===rows[rows.length-1] && el.value){dcAddRow();}
      dcSyncBundleForRow(r);
      dcRecalc();
    }

    function dcAdvanceTotal(){
      let sum=0;
      document.querySelectorAll('#dcAdvanceRows .dc-advance-amt').forEach(function(inp){
        const v=Math.max(0,parseFloat(inp.value||0)); if(Number.isFinite(v)) sum+=v;
      });
      return Math.round(sum*100)/100;
    }
    window.dcRemoveAdvance=function(btn){
      const row=btn?.closest('.dc-advance-row');
      const rows=document.querySelectorAll('#dcAdvanceRows .dc-advance-row');
      if(rows.length>1 && row) row.remove();
      else if(row){const amt=row.querySelector('.dc-advance-amt'); if(amt) amt.value='0';}
      dcRecalc();
    };
    window.dcAddAdvance=function(){
      const box=document.getElementById('dcAdvanceRows'); if(!box)return;
      const first=box.querySelector('.dc-advance-row'); if(!first)return;
      const row=first.cloneNode(true);
      const amt=row.querySelector('.dc-advance-amt'); if(amt) amt.value='0';
      const sel=row.querySelector('select'); if(sel) sel.selectedIndex=0;
      box.appendChild(row); dcRecalc();
    };
    // Delivery Challan prices accept decimal values at 0.01 precision.
    function dcEnsureDecimalPriceInputs(){
      document.querySelectorAll('#dcRows .dc-price').forEach(function(input){
        input.setAttribute('step','0.01');
        input.step='0.01';
      });
    }
    function dcRecalc(){
      let subtotal=0;
      document.querySelectorAll('#dcRows tr').forEach(function(r){
        const q=Math.max(0,parseFloat(r.querySelector('.dc-qty')?.value||0));
        const p=Math.max(0,parseFloat(r.querySelector('.dc-price')?.value||0));
        const gross=q*p;
        const disc=Math.max(0,parseFloat(r.querySelector('.dc-amt')?.dataset.discount||0));
        const net=Math.max(0,gross-disc);
        subtotal+=net;
        const out=r.querySelector('.dc-amt'); if(out) out.textContent=dcFmt(net);
      });
      subtotal=Math.round(subtotal*100)/100;
      const invDisc=Math.min(Math.max(0,parseFloat(document.getElementById('dcInvDisc')?.value||0)),subtotal);
      const shipping=Math.max(0,parseFloat(document.getElementById('dcShipping')?.value||0));
      const total=Math.max(0,Math.round((subtotal-invDisc+shipping)*100)/100);
      let advance=dcAdvanceTotal();
      if(advance>total){
        const firstAmt=document.querySelector('#dcAdvanceRows .dc-advance-amt');
        const excess=advance-total;
        if(firstAmt){firstAmt.value=Math.max(0,parseFloat(firstAmt.value||0)-excess).toFixed(2);}
        advance=dcAdvanceTotal();
      }
      const cod=Math.max(0,Math.round((total-advance)*100)/100);
      const s=document.getElementById('dcSubtotal'); if(s)s.textContent=dcFmt(subtotal);
      const t=document.getElementById('dcTotal'); if(t)t.textContent=dcFmt(total);
      const a=document.getElementById('dcAdvanceTotal'); if(a)a.textContent=dcFmt(advance);
      const c=document.getElementById('dcCod'); if(c)c.textContent=dcFmt(cod);
      const cf=document.getElementById('dcCodFooter'); if(cf)cf.textContent=dcFmt(cod);
    }
    window.dcRemoveRow=function(btn){
      const row=btn?.closest('#dcRows tr'); const body=document.getElementById('dcRows'); if(!row||!body)return;
      const wasParent=row.dataset.bundleChild!=='1';
      const parentKey=row.querySelector('input[name="bundle_row_key[]"]')?.value||row.dataset.bundleRowKey||'';
      if(wasParent) dcRemoveBundleChildren(parentKey);
      row.remove(); dcRenumberRows(); dcRecalc();
    };
    window.dcAddRow=function(){
      const body=document.getElementById('dcRows'); const first=body?.querySelector('tr'); if(!body||!first)return;
      const row=first.cloneNode(true);
      row.className='';
      row.dataset.bundleChild='0'; row.dataset.bundleParentKey=''; row.dataset.bundleRowKey=dcMakeRowKey(); row.dataset.bundleJson='[]';
      row.querySelectorAll('input[name="bundle_row_key[]"]').forEach(function(i){i.value=row.dataset.bundleRowKey;});
      row.querySelectorAll('input[name="bundle_parent_key[]"]').forEach(function(i){i.value='';});
      row.querySelectorAll('input[name="bundle_child[]"]').forEach(function(i){i.value='0';});
      row.querySelectorAll('.item-live-search').forEach(function(box){ delete box.dataset.itemSearchBound; box.dataset.itemSearchBound=''; });
      row.querySelectorAll('input').forEach(function(inp){
        if(inp.classList.contains('dc-qty')) inp.value='1';
        else if(inp.classList.contains('dc-price')||inp.classList.contains('dc-line-discount')) inp.value='0';
        else if(inp.classList.contains('item-search-input')) inp.value='';
        else if(inp.name==='item_id[]') inp.value='';
      });
      row.querySelectorAll('select').forEach(function(sel){sel.value='';});
      const search=row.querySelector('.item-search-input'); if(search) search.value='';
      const clear=row.querySelector('.item-search-clear'); if(clear) clear.style.display='none';
      const results=row.querySelector('.item-search-results'); if(results){results.hidden=true;results.innerHTML='';}
      const unit=row.querySelector('.dc-unit'); if(unit) unit.textContent='—';
      const amt=row.querySelector('.dc-amt'); if(amt){amt.textContent='৳0.00';amt.dataset.discount='0';}
      const n=row.querySelector('.txn-row-number'); if(n)n.textContent=String(body.querySelectorAll('tr').length+1);
      body.appendChild(row);
      if(window.SutoInitItemSearch) window.SutoInitItemSearch(row);
      dcEnsureDecimalPriceInputs();
      dcRecalc();
    };
    document.getElementById('dcForm')?.addEventListener('submit',function(e){
      const rows=[...document.querySelectorAll('#dcRows tr')]; let valid=0;
      rows.forEach(function(row){
        const isChild=row.dataset.bundleChild==='1';
        const item=isChild
          ? (row.querySelector('input[name="item_id[]"]')?.value||'')
          : (row.querySelector('.dc-item')?.value||row.querySelector('input[name="item_id[]"]')?.value||'');
        const qty=parseFloat(row.querySelector('.dc-qty')?.value||0)||0;
        const price=parseFloat(row.querySelector('.dc-price')?.value||0)||0;
        if(!item){
          row.remove();
          return;
        }
        if(qty<=0){
          e.preventDefault();
          row.querySelector('.dc-qty')?.focus();
          alert('Please enter a valid quantity.');
          return;
        }
        if(price<0){
          e.preventDefault();
          row.querySelector('.dc-price')?.focus();
          alert('Please enter a valid price.');
          return;
        }
        valid++;
      });
      dcRenumberRows();
      if(valid<1){ e.preventDefault(); alert('Add at least one item.'); }
    });
    document.addEventListener('input',e=>{
      if(e.target.closest('#dcRows')){
        const row=e.target.closest('#dcRows tr');
        if(row && e.target.classList.contains('dc-qty') && row.dataset.bundleChild!=='1') dcUpdateBundleQuantities(row);
        dcRecalc();
      }else if(e.target.closest('#dcAdvanceRows')||['dcInvDisc','dcShipping'].includes(e.target.id))dcRecalc();
    });
    document.addEventListener('change',e=>{if(e.target.matches('.dc-item'))dcSetPrice(e.target); if(e.target.closest('#dcRows'))dcRecalc();});
    (function(){
      function bootDeliveryItemSearch(){
        const rows=document.getElementById('dcRows');
        if(!rows)return false;
        if(typeof window.SutoInitItemSearch==='function'){
          window.SutoInitItemSearch(rows);
          return true;
        }
        return false;
      }
      if(!bootDeliveryItemSearch()){
        document.addEventListener('DOMContentLoaded',bootDeliveryItemSearch,{once:false});
        setTimeout(bootDeliveryItemSearch,100);
        setTimeout(bootDeliveryItemSearch,400);
        setTimeout(bootDeliveryItemSearch,1000);
      }
    })();
    dcRenumberRows();
    dcEnsureDecimalPriceInputs();
    dcRecalc();
    window.SutoBundleComponentsConfig={url:<?=json_encode(url('bundle-components-api'),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)?>};
    </script>
    <?php render_inline_creation_modals(); page_end();
}

function document_items(int $companyId,int $tid): array {
    $st=db()->prepare('SELECT ti.*,i.name item_name,i.item_type,u.symbol unit_symbol FROM transaction_items ti JOIN items i ON i.id=ti.item_id LEFT JOIN units u ON u.id=i.unit_id WHERE ti.transaction_id=? ORDER BY ti.id');
    $st->execute([$tid]); return $st->fetchAll();
}
function document_module(string $type,string $title,string $prefix,string $partyLabel,array $nextTypes=[],bool $listOnly=false,string $listRoute=''): void {
    // Quotation/Sale Order shared document engine.
    // These documents do not post accounting entries until a Sale conversion occurs.
    global $u;
    $cid=(int)$u['company_id']; $pdo=db();
    $isPartyRequired=true;
    $neededRole=$partyLabel==='Customer'?'customer':'supplier';
    if($_SERVER['REQUEST_METHOD']==='POST'){
        check_csrf();
        $action=$_POST['action']??'';
        if(in_array($action,['delete_document','duplicate_document'],true)){
            try{
                $docId=(int)($_POST['transaction_id']??0);
                $st=$pdo->prepare('SELECT * FROM transactions WHERE id=? AND company_id=? AND txn_type=? AND deleted_at IS NULL');
                $st->execute([$docId,$cid,$type]); $src=$st->fetch();
                if(!$src) throw new RuntimeException('Document not found.');
                if($action==='delete_document'){
                    if($src['status']==='converted') throw new RuntimeException('Converted document cannot be deleted.');
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE transactions SET deleted_at=NOW(),status=\"deleted\" WHERE id=? AND company_id=?')->execute([$docId,$cid]);
                    audit('delete','transaction',$docId,['type'=>$type,'document'=>$src['document_no']]);
                    $pdo->commit(); flash('success',$title.' '.$src['document_no'].' moved to Recycle Bin.');
                } else {
                    $it=$pdo->prepare('SELECT * FROM transaction_items WHERE transaction_id=? ORDER BY id'); $it->execute([$docId]); $itemsRows=$it->fetchAll();
                    if(!$itemsRows) throw new RuntimeException('Source document has no items.');
                    $pdo->beginTransaction();
                    $newDoc=next_document_in_transaction($pdo,$cid,$type,$prefix);
                    $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                      ->execute([$cid,$src['party_id'],$type,$newDoc,transaction_datetime(null),$src['due_date'],$src['subtotal'],$src['item_discount'],$src['invoice_discount'],$src['tax'],$src['direct_expense'],$src['total'],0,$src['total'],$src['currency_code'],'open','Duplicated from '.$src['document_no'],$u['id']]);
                    $newId=(int)$pdo->lastInsertId();
                    $ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');
                    foreach($itemsRows as $r) $ins->execute([$newId,$r['item_id'],$r['qty'],$r['unit_price'],$r['discount'],$r['tax'],$r['amount']]);
                    audit('duplicate','transaction',$newId,['source_transaction'=>$docId,'source_document'=>$src['document_no'],'type'=>$type,'document'=>$newDoc]);
                    $pdo->commit(); flash('success',$title.' '.$src['document_no'].' duplicated as '.$newDoc.'.');
                }
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
            redirect($listRoute!==''?$listRoute:$type);
        }
        if($action==='save_document'){
        try{
            $party=(int)($_POST['party_id']??0); if($isPartyRequired && $party<=0) throw new RuntimeException($partyLabel.' is required.');
            if($isPartyRequired){$neededRole=$partyLabel==='Customer'?'customer':'supplier';$st=$pdo->prepare('SELECT p.id FROM parties p WHERE p.id=? AND p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role=?)');$st->execute([$party,$cid,$neededRole]);$pr=$st->fetch();if(!$pr)throw new RuntimeException('Invalid '.$partyLabel.'.');}
            $txnDate=transaction_datetime($_POST['txn_date']??null);$dueDate=$_POST['due_date']?:null;$notes=trim($_POST['notes']??'');
            $itemIds=$_POST['item_id']??[];$qtys=$_POST['qty']??[];$prices=$_POST['price']??[];$discs=$_POST['discount']??[];
            $rows=[];$subtotal=0;$itemDisc=0;
            foreach($itemIds as $i=>$iid){$iid=(int)$iid;$q=(float)($qtys[$i]??0);$price=(float)($prices[$i]??0);$disc=max(0,(float)($discs[$i]??0));if($iid<=0||$q<=0)continue;$st=$pdo->prepare('SELECT * FROM items WHERE id=? AND company_id=? AND active=1');$st->execute([$iid,$cid]);$it=$st->fetch();if(!$it)throw new RuntimeException('Invalid item selected.');$gross=$q*$price;if($disc>$gross)$disc=$gross;$rows[]=[$iid,$q,$price,$disc,$it];$subtotal+=$gross;$itemDisc+=$disc;}
            if(!$rows) throw new RuntimeException('Add at least one item.');
            $invDisc=max(0,(float)($_POST['invoice_discount']??0));$tax=max(0,(float)($_POST['tax']??0));$direct=max(0,(float)($_POST['direct_expense']??0));$after=max(0,$subtotal-$itemDisc);if($invDisc>$after)$invDisc=$after;$total=round(max(0,$after-$invDisc+$tax+$direct),2);
            $pdo->beginTransaction();$doc=trim($_POST['document_no']??'');if($doc==='')$doc=next_document_in_transaction($pdo,$cid,$type,$prefix);
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$party,$type,$doc,$txnDate,$dueDate,$subtotal,$itemDisc,$invDisc,$tax,$direct,$total,0,$total,$u['currency_code'],'open',$notes,$u['id']]);
            $tid=(int)$pdo->lastInsertId();$ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');foreach($rows as [$iid,$q,$price,$disc,$it])$ins->execute([$tid,$iid,$q,$price,$disc,0,max(0,$q*$price-$disc)]);
            if($type==='delivery_challan'){ /* challan does not change accounting/stock until converted to sale */ }
            audit('create','transaction',$tid,['type'=>$type,'document'=>$doc,'total'=>$total]);$pdo->commit();flash('success',$title.' '.$doc.' saved successfully.');redirect($type==='quotation'?'quotations':($type==='sale_order'?'sale-order':($type==='purchase_order'?'purchase-order':'delivery-challans')));
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect($type==='quotation'?'quotations':($type==='sale_order'?'sale-order':($type==='purchase_order'?'purchase-order':'delivery-challans')));}
        }
    }
    if(isset($_GET['convert'])){
        $sourceId=(int)$_GET['convert'];
        try{
            $sourceType=$type;
            $target=is_array($nextTypes)?($nextTypes[0]??''):((string)$nextTypes);
            if(!$target)throw new RuntimeException('Conversion is not configured.');
            $relation=match($sourceType){'quotation'=>'quotation_to_order','sale_order'=>'order_to_challan','delivery_challan'=>'challan_to_sale','purchase_order'=>'purchase_order_to_purchase_bill',default=>'document_conversion'};
            $existing=$pdo->prepare('SELECT to_transaction_id FROM transaction_links WHERE company_id=? AND from_transaction_id=? AND relation_type=? LIMIT 1');$existing->execute([$cid,$sourceId,$relation]);if($existing->fetchColumn())throw new RuntimeException('This document has already been converted.');
            $st=$pdo->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL');$st->execute([$sourceId,$cid,$sourceType]);$source=$st->fetch();if(!$source)throw new RuntimeException('Source document not found.');
            $its=$pdo->prepare('SELECT ti.* FROM transaction_items ti WHERE ti.transaction_id=?');$its->execute([$sourceId]);$sourceItems=$its->fetchAll();if(!$sourceItems)throw new RuntimeException('Source document has no items.');
            $targetType=$target;$targetPrefix=match($targetType){'sale_order'=>'SO-','delivery_challan'=>'DC-','sale'=>'SI-','purchase'=>'PB-',default=>'DOC-'};$targetDoc=next_document_in_transaction($pdo,$cid,$targetType,$targetPrefix);
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
              ->execute([$cid,$source['party_id'],$targetType,$targetDoc,transaction_datetime(null),$source['due_date'],$source['subtotal'],$source['item_discount'],$source['invoice_discount'],$source['tax'],$source['direct_expense'],$source['total'],0,$source['total'],$u['currency_code'],in_array($targetType,['sale','purchase'],true)?'final':'open','Converted from '.$source['document_no'],$u['id']]);
            $tid=(int)$pdo->lastInsertId();$ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');
            foreach($sourceItems as $r){$ins->execute([$tid,$r['item_id'],$r['qty'],$r['unit_price'],$r['discount'],$r['tax'],$r['amount']]);}
            if($targetType==='sale'){
                $cogs=0;$st2=$pdo->prepare('SELECT i.purchase_price,i.item_type FROM items i WHERE i.id=? AND i.company_id=?');$stock=$pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,movement_type,note) VALUES(?,?,?,?,?,?,?)');
                foreach($sourceItems as $r){$st2->execute([$r['item_id'],$cid]);$it=$st2->fetch();if($it&&$it['item_type']==='product'){$cogs+=max(0,(float)$r['qty']*(float)$it['purchase_price']);$stock->execute([$cid,$r['item_id'],$tid,date('Y-m-d'),-1*(float)$r['qty'],'sale',$targetDoc]);}}
                $net=max(0,(float)$source['subtotal']-(float)$source['item_discount']-(float)$source['invoice_discount']+(float)$source['direct_expense']);
                $lines=[['1200','Accounts Receivable',(float)$source['total'],0,$targetDoc],['4000','Sales Revenue',0,$net,$targetDoc]];if((float)$source['tax']>0)$lines[]=['2100','Tax Payable',0,(float)$source['tax'],$targetDoc];if($cogs>0){$lines[]=['5100','Cost of Goods Sold',$cogs,0,$targetDoc];$lines[]=['1300','Inventory',0,$cogs,$targetDoc];}$debit=0;$credit=0;foreach($lines as $l){$debit+=round((float)$l[2],2);$credit+=round((float)$l[3],2);}if(abs(round($debit-$credit,2))>0.01)throw new RuntimeException('Converted sale accounting entry is not balanced.');post_ledger($pdo,$cid,$tid,date('Y-m-d'),$lines);
            }
            if($targetType==='purchase'){
                $stock=$pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,movement_type,note) VALUES(?,?,?,?,?,?,?)');
                $itq=$pdo->prepare('SELECT item_type FROM items WHERE id=? AND company_id=?');
                $productCost=0;$serviceCost=0;
                foreach($sourceItems as $r){$itq->execute([(int)$r['item_id'],$cid]);$it=$itq->fetch();$amt=max(0,(float)$r['amount']);if($it&&$it['item_type']==='product'){$productCost+=$amt;$stock->execute([$cid,(int)$r['item_id'],$tid,date('Y-m-d'),(float)$r['qty'],'purchase',$targetDoc]);}else{$serviceCost+=$amt;}}
                $lines=[];
                if($productCost>0)$lines[]=['1300','Inventory',$productCost,0,$targetDoc];
                if($serviceCost>0)$lines[]=['5200','Purchase / Service Cost',$serviceCost,0,$targetDoc];
                if((float)$source['direct_expense']>0)$lines[]=['1300','Inventory',(float)$source['direct_expense'],0,$targetDoc];
                if((float)$source['tax']>0)$lines[]=['1400','Input VAT / Tax',(float)$source['tax'],0,$targetDoc];
                if((float)$source['invoice_discount']>0)$lines[]=['4300','Purchase Discount',0,(float)$source['invoice_discount'],$targetDoc];
                $lines[]=['2100','Accounts Payable',0,(float)$source['total'],$targetDoc];
                $debit=0;$credit=0;foreach($lines as $l){$debit+=round((float)$l[2],2);$credit+=round((float)$l[3],2);}if(abs(round($debit-$credit,2))>0.01)throw new RuntimeException('Converted purchase accounting entry is not balanced.');post_ledger($pdo,$cid,$tid,date('Y-m-d'),$lines);
            }
            $pdo->prepare('INSERT INTO transaction_links(company_id,from_transaction_id,to_transaction_id,relation_type,quantity) VALUES(?,?,?,?,NULL)')->execute([$cid,$sourceId,$tid,$relation]);
            $pdo->prepare('UPDATE transactions SET status="converted" WHERE id=? AND company_id=?')->execute([$sourceId,$cid]);
            audit('convert','transaction',$sourceId,['to_transaction'=>$tid,'relation'=>$relation,'target_type'=>$targetType,'target_document'=>$targetDoc]);$pdo->commit();flash('success',$source['document_no'].' converted to '.$targetDoc.'.');
            redirect($targetType==='sale_order'?'sale-order':($targetType==='delivery_challan'?'delivery-challans':($targetType==='purchase'?'purchase?view='.$tid:'sales?view='.$tid)));
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());$list=$type==='quotation'?'quotations':($type==='sale_order'?'sale-order':($type==='purchase_order'?'purchase-order':'delivery-challans'));redirect($list);}
    }
    page_start($title);
    $partySt=$pdo->prepare('SELECT p.id,p.name,p.phone FROM parties p WHERE p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role=? ) ORDER BY p.name');$partySt->execute([$cid,$partyLabel==='Customer'?'customer':'supplier']);$parties=$partySt->fetchAll();
    $items=get_items($cid);
    if(isset($_GET['view'])){
        $tid=(int)$_GET['view'];$st=$pdo->prepare('SELECT t.*,p.name party_name FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type=?');$st->execute([$tid,$cid,$type]);$tx=$st->fetch();
        if($tx){$lines=document_items($cid,$tid);$can=$tx['status']!=='converted';$convertLabel=$type==='quotation'?'Convert to Sale Order':($type==='sale_order'?'Convert to Delivery Challan':($type==='purchase_order'?'Convert to Purchase Bill':'Convert to Sale'));$urlType = ($type==='purchase_order'?'purchase-order':$type); $convertUrl=$can?url($urlType.'?convert='.(int)$tx['id']):'#';
        ?><div class="panel print-company-header" style="margin-bottom:14px"><div style="display:flex;justify-content:space-between;align-items:flex-start;gap:20px"><div><?php $logo=saas_company_logo_url($u['logo_path']??null); if($logo): ?><img src="<?=e($logo)?>" alt="Company logo" style="max-height:56px;max-width:180px;object-fit:contain;margin-bottom:6px"><br><?php endif; ?><h2 style="margin:0"><?=e($u['company_name']??'')?></h2><div class="subtle"><?=e($title)?></div></div><div style="text-align:right"><strong><?=e($tx['document_no'])?></strong><br><?=e(date('d/m/Y',strtotime($tx['txn_date'])))?></div></div></div><div class="page-title"><div><h1><?=e($title)?> <?=e($tx['document_no'])?></h1><p><?=e($tx['party_name']??'')?> · <?=e($tx['txn_date'])?></p></div><div><a class="btn" href="<?=e(url($listRoute!==''?$listRoute:$type))?>">Back</a><?php if($can):?><a class="btn primary" href="<?=$convertUrl?>"><?=e($convertLabel)?></a><?php endif;?></div></div>
        <div class="cards-top"><div class="metric-card"><div class="label">Total</div><div class="value"><?=money((float)$tx['total'])?></div></div><div class="metric-card"><div class="label"><?=e($partyLabel)?></div><div class="value" style="font-size:20px"><?=e($tx['party_name']??'')?></div></div><div class="metric-card"><div class="label">Status</div><div class="value" style="font-size:20px"><?=e(ucfirst($tx['status']))?></div></div></div>
        <div class="panel"><div class="panel-head"><h2>ITEMS</h2></div><div class="table-wrap"><table><thead><tr><th>#</th><th>ITEM</th><th>QTY</th><th>PRICE/UNIT</th><th>DISCOUNT</th><th>AMOUNT</th></tr></thead><tbody><?php foreach($lines as $i=>$r):?><tr><td><?=$i+1?></td><td><?=e($r['item_name'])?></td><td><?=qty((float)$r['qty']).' '.e($r['unit_symbol']??'')?></td><td><?=money((float)$r['unit_price'])?></td><td><?=money((float)$r['discount'])?></td><td><?=money((float)$r['amount'])?></td></tr><?php endforeach;?></tbody></table></div></div><?php page_end();exit;}
    }
    $q=trim($_GET['q']??'');
    $sql='SELECT t.*,p.name party_name,p.phone party_phone FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL';
    $params=[$cid,$type];
    if($q!==''){ $sql.=' AND (t.document_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)'; $like='%'.$q.'%'; $params[]=$like; $params[]=$like; $params[]=$like; }
    $sql.=' ORDER BY t.txn_date DESC,t.id DESC';
    $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
    if($listOnly): ?>
    <div class="panel">
      <div class="panel-head">
        <h2>TRANSACTIONS</h2>
        <div style="display:flex;align-items:center;gap:8px">
          <form method="get" style="display:flex;gap:8px">
            <input class="input" name="q" value="<?=e($q)?>" style="max-width:280px" placeholder="Search by document, party, phone">
            <button class="btn" type="submit">Search</button>
          </form>
          <a class="btn primary" href="<?=e(url($listRoute!==''?$listRoute.'-new':'sale-order-new'))?>">⊕ Add Sale Order</a>
        </div>
      </div>
      <div class="table-wrap"><table><thead><tr><th>DATE</th><th>DOCUMENT NO.</th><th><?=e(strtoupper($partyLabel))?></th><th>TOTAL</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
      <?php foreach($rows as $r):?>
        <tr>
          <td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td>
          <td><?=e($r['document_no'])?></td>
          <td><?=e($r['party_name']??'')?></td>
          <td><?=money((float)$r['total'])?></td>
          <td><span class="status <?=($r['status']==='converted'?'paid':'open')?>"><?=e(ucfirst($r['status']))?></span></td>
          <td class="action">
            <a class="btn" href="<?=e(url(($listRoute!==''?$listRoute:$type).'?view='.(int)$r['id']))?>">View</a>
            <?php if($r['status']!=='converted'):?>
              <a class="btn primary small-btn" href="<?=e(url(($listRoute!==''?$listRoute:$type).'?convert='.(int)$r['id']))?>" onclick="return confirm('<?=e($title)?> will be converted. Continue?')"><?=e($type==='sale_order'?'CONVERT TO DELIVERY CHALLAN':($type==='quotation'?'CONVERT TO SALE ORDER':'CONVERT TO PURCHASE BILL'))?></a>
            <?php endif;?>
            <button type="button" class="dots" aria-label="Actions">⋮</button>
            <div class="row-menu">
              <a href="<?=e(url(($listRoute!==''?$listRoute:$type).'?view='.(int)$r['id']))?>">View</a>
              <?php if($r['status']!=='converted'):?>
                <a href="<?=e(url(($listRoute!==''?$listRoute:$type).'?convert='.(int)$r['id']))?>" onclick="return confirm('Convert this document?')"><?=e($type==='sale_order'?'Convert to Delivery Challan':($type==='quotation'?'Convert to Sale Order':'Convert to Purchase Bill'))?></a>
                <form method="post" onsubmit="return confirm('Delete this document? It will move to Recycle Bin.')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_document"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Delete</button></form>
              <?php endif;?>
              <form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="duplicate_document"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Duplicate</button></form>
              <a href="<?=e(url(($listRoute!==''?$listRoute:$type).'?view='.(int)$r['id'].'&print=1'))?>">Open PDF</a>
              <a href="<?=e(url(($listRoute!==''?$listRoute:$type).'?view='.(int)$r['id']))?>">Preview</a>
              <a href="<?=e(url(($listRoute!==''?$listRoute:$type).'?view='.(int)$r['id'].'&print=1'))?>">Print</a>
            </div>
          </td>
        </tr>
      <?php endforeach;?>
      <?php if(!$rows):?><tr><td colspan="6" class="subtle">No documents yet.</td></tr><?php endif;?>
      </tbody></table></div>
    </div>
    <script>
    document.addEventListener('click',function(e){
      const btn=e.target.closest('.dots');
      if(!btn) document.querySelectorAll('.row-menu').forEach(m=>m.classList.remove('show'));
    });
    </script>
    <?php page_end();exit;
    <?php endif; ?>
    <div class="page-title"><div><h1><?=e($title)?></h1><p>Manage <?=e(strtolower($title))?></p></div><a class="btn primary" href="#newDoc">⊕ Add <?=e($title==='Estimate / Quotation'?'Quotation':($title==='Sale Order'?'Sale Order':'Purchase Order'))?></a></div>
    <div class="panel standard-entry-form" id="newDoc"><div class="panel-head"><h2>New <?=e($title==='Estimate / Quotation'?'Quotation':$title)?></h2><span class="subtle">No accounting/stock posting until conversion to the next document.</span></div><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_document"><div class="entry-top standard-entry-top"><div class="standard-party-field"><?php party_search_field($partyLabel,$partyLabel==='Customer'?'customer':'supplier',0,'',''); ?><div style="margin-top:6px"><button type="button" class="btn small-btn" onclick="senseOpenInlinePartyModal('<?=$neededRole?>')">+ Add Party</button></div></div><div class="form-group"><label><?=e($title==='Estimate / Quotation'?'Quotation':$title)?> Number</label><input name="document_no" placeholder="Auto: <?=e($prefix)?>01"></div><div class="form-group"><label><?=e($title==='Estimate / Quotation'?'Quotation':$title)?> Date*</label><input type="date" name="txn_date" value="<?=date('Y-m-d')?>" required></div></div>
    <div class="entry-table"><table><thead><tr><th>#</th><th>ITEM</th><th>QTY</th><th>PRICE/UNIT</th><th>DISCOUNT</th><th>AMOUNT</th></tr></thead><tbody id="docRows"><tr><td>1</td><td><div class="item-picker-cell"><?php item_search_field(0,'','',($type==='purchase_order'?'purchase':'sale')); ?><select name="item_id[]" class="item-select item-source-select" onchange="docPrice(this)" required><option value="">Select item</option><?php foreach($items as $it):?><option value="<?=$it['id']?>" data-price="<?=$it[$type==='purchase_order'?'purchase_price':'sale_price']?>" data-unit="<?=e($unitSymbols[(int)($it['unit_id']??0)]??'')?>"><?=e($it['name'])?></option><?php endforeach;?></select></div></td><td><input type="number" name="qty[]" class="doc-qty" step="<?= $type==='sale_order' ? '1' : '0.01' ?>" min="<?= $type==='sale_order' ? '1' : '0.001' ?>" value="1" required></td><td><input type="number" name="price[]" class="doc-price" step="0.01" min="0" value="0" required></td><td><input type="number" name="discount[]" class="doc-disc" step="0.01" min="0" value="0"></td><td class="doc-amt"><?=money(0)?></td></tr></tbody></table></div>
    <div class="entry-actions"><div style="display:flex;gap:8px"><button type="button" class="btn" onclick="addDocRow()">+ Add Row</button><button type="button" class="btn" onclick="senseOpenInlineProductModal('#docRows','doc')">+ Add Product</button></div><span><b>Total</b> <strong id="docTotal">৳0.00</strong></span></div>
    <div class="grid3"><div class="form-group"><label>Invoice Discount</label><input id="docInvDisc" type="number" name="invoice_discount" min="0" step="0.01" value="0"></div><div class="form-group"><label>Tax / VAT</label><input id="docTax" type="number" name="tax" min="0" step="0.01" value="0"></div><div class="form-group"><label>Direct Expense</label><input id="docDirect" type="number" name="direct_expense" min="0" step="0.01" value="0"></div></div>
    <div class="form-group"><label>Description / Note</label><textarea name="notes" rows="3"></textarea></div><div class="form-footer" style="margin:0 -16px -16px"><a class="btn" href="<?=e(url($listRoute!==''?$listRoute:$type))?>">Cancel</a><button class="btn primary">Save</button></div></form></div>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>TRANSACTIONS</h2><form method="get" style="display:flex;gap:8px"><input class="input" name="q" value="<?=e($q)?>" style="max-width:280px" placeholder="Search by document, party, phone"><button class="btn">Search</button></form></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>DOCUMENT NO.</th><th><?=e(strtoupper($partyLabel))?></th><th>TOTAL</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=e(!empty($r['txn_date'])?date('d/m/Y',strtotime($r['txn_date'])):'—')?></td><td><?=e($r['document_no'])?></td><td><?=e($r['party_name']??'')?></td><td><?=money((float)$r['total'])?></td><td><span class="status <?=($r['status']==='converted'?'paid':'open')?>"><?=e(ucfirst($r['status']))?></span></td><td class="action"><a class="btn" href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?view='.(int)$r['id'])))?>">View</a><?php if($r['status']!=='converted'):?><a class="btn primary small-btn" href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?convert='.(int)$r['id'])))?>" onclick="return confirm('<?=e($title)?> will be converted. Continue?')"><?=e($type==='quotation'?'CONVERT TO SALE ORDER':($type==='sale_order'?'CONVERT TO DELIVERY CHALLAN':($type==='purchase_order'?'CONVERT TO PURCHASE BILL':'CONVERT TO SALE')))?></a><?php endif;?><button type="button" class="dots" aria-label="Actions">⋮</button><div class="row-menu"><a href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?view='.(int)$r['id'])))?>">View</a><?php if($r['status']!=='converted'):?><a href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?convert='.(int)$r['id'])))?>" onclick="return confirm('Convert this document?')"><?=e($type==='quotation'?'Convert to Sale Order':($type==='sale_order'?'Convert to Delivery Challan':($type==='purchase_order'?'Convert to Purchase Bill':'Convert to Sale')))?></a><form method="post" onsubmit="return confirm('Delete this document? It will move to Recycle Bin.')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_document"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Delete</button></form><?php endif;?><form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="duplicate_document"><input type="hidden" name="transaction_id" value="<?=$r['id']?>"><button type="submit">Duplicate</button></form><a href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?view='.(int)$r['id'].'&print=1')))?>">Open PDF</a><a href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?view='.(int)$r['id'])))?>">Preview</a><a href="<?=e(url((($type==='purchase_order'?'purchase-order':$type).'?view='.(int)$r['id'].'&print=1')))?>">Print</a></div></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6" class="subtle">No documents yet.</td></tr><?php endif;?></tbody></table></div></div>
    <script>
    function docPrice(el){const o=el.selectedOptions[0];const row=el.closest('tr');if(row)row.querySelector('.doc-price').value=o?.dataset.price||0;docRecalc();}
    function docRecalc(){let t=0;document.querySelectorAll('#docRows tr').forEach(r=>{const q=parseFloat(r.querySelector('.doc-qty')?.value||0),p=parseFloat(r.querySelector('.doc-price')?.value||0),d=parseFloat(r.querySelector('.doc-disc')?.value||0);const a=Math.max(0,q*p-Math.min(d,q*p));t+=a;r.querySelector('.doc-amt').textContent='৳'+a.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});});const inv=parseFloat(document.getElementById('docInvDisc')?.value||0),tax=parseFloat(document.getElementById('docTax')?.value||0),direct=parseFloat(document.getElementById('docDirect')?.value||0);t=Math.max(0,t-Math.min(inv,t)+tax+direct);document.getElementById('docTotal').textContent='৳'+t.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});}
    function docEnsureQtyStep(){if(<?=json_encode($type==='sale_order')?>){document.querySelectorAll('#docRows .doc-qty').forEach(function(i){i.setAttribute('step','1');i.setAttribute('min','1');i.step='1';i.min='1';});}}
    function addDocRow(){const body=document.getElementById('docRows'),n=body.querySelectorAll('tr').length+1;const tpl=body.querySelector('tr').cloneNode(true);tpl.querySelectorAll('input').forEach(i=>{if(i.classList.contains('doc-qty'))i.value='1';else if(i.classList.contains('doc-price')||i.classList.contains('doc-disc'))i.value='0';else if(i.classList.contains('item-search-input'))i.value='';});tpl.querySelectorAll('select').forEach(s=>s.selectedIndex=0);const clear=tpl.querySelector('.item-search-clear');if(clear)clear.style.display='none';tpl.querySelector('.doc-amt').textContent='৳0.00';tpl.firstElementChild.textContent=n;body.appendChild(tpl);docEnsureQtyStep();if(typeof window.SutoInitItemSearch==='function')window.SutoInitItemSearch(tpl);}
    document.addEventListener('input',e=>{if(e.target.closest('#docRows')||['docInvDisc','docTax','docDirect'].includes(e.target.id))docRecalc();});document.addEventListener('change',e=>{if(e.target.closest('#docRows'))docRecalc();});docEnsureQtyStep();docRecalc();
    </script><?php render_inline_creation_modals(); page_end();
}


function product_request_stock(PDO $pdo,int $cid,int $itemId): float {
    $st=$pdo->prepare('SELECT COALESCE(SUM(sm.quantity),0) FROM stock_movements sm WHERE sm.company_id=? AND sm.item_id=? AND (sm.transaction_id IS NULL OR EXISTS(SELECT 1 FROM transactions t WHERE t.id=sm.transaction_id AND t.company_id=sm.company_id AND t.deleted_at IS NULL))');
    $st->execute([$cid,$itemId]);
    return (float)$st->fetchColumn();
}

function product_request_status(PDO $pdo,int $cid,int $requestId, ?array $items=null): array {
    $st=$pdo->prepare('SELECT status,sale_transaction_id FROM product_requests WHERE id=? AND company_id=? LIMIT 1');$st->execute([$requestId,$cid]);$req=$st->fetch();
    if(!$req)return ['status'=>'missing','items'=>[],'converted'=>false];
    if($req['sale_transaction_id'])return ['status'=>'converted','items'=>$items??[],'converted'=>true];
    if($items===null){$q=$pdo->prepare('SELECT pri.item_id,pri.qty,i.name,i.code FROM product_request_items pri JOIN items i ON i.id=pri.item_id WHERE pri.request_id=? AND pri.company_id=? ORDER BY pri.id');$q->execute([$requestId,$cid]);$items=$q->fetchAll();}
    $all=true;$any=false;
    foreach($items as &$it){$it['requested_qty']=(float)$it['qty'];$it['available_qty']=product_request_stock($pdo,$cid,(int)$it['item_id']);$it['is_available']=$it['available_qty']+0.000001 >= $it['requested_qty'];if($it['available_qty']>0.000001)$any=true;if(!$it['is_available'])$all=false;}
    unset($it);
    $status=$all&&$items?'available':($any?'partially_available':'requested');
    return ['status'=>$status,'items'=>$items,'converted'=>false];
}

function product_request_new(): void {
    global $u;
    $cid=(int)$u['company_id'];$pdo=db();

    // Create / Edit / Delete / Duplicate Product Request
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $action=$_POST['action']??'';
        if($action==='duplicate_product_request'){
            check_csrf();
            $rid=(int)($_POST['request_id']??0);
            try{
                $st=$pdo->prepare('SELECT * FROM product_requests WHERE id=? AND company_id=? LIMIT 1');$st->execute([$rid,$cid]);$src=$st->fetch();
                if(!$src)throw new RuntimeException('Product Request not found.');
                $itemsSt=$pdo->prepare('SELECT * FROM product_request_items WHERE request_id=? AND company_id=? ORDER BY id');$itemsSt->execute([$rid,$cid]);$srcItems=$itemsSt->fetchAll();
                if(!$srcItems)throw new RuntimeException('Product Request has no items to duplicate.');
                $doc=next_document_in_transaction($pdo,$cid,'product_request','PR-');
                $pdo->beginTransaction();
                $pdo->prepare('INSERT INTO product_requests(company_id,party_id,request_no,request_date,status,notes,created_by) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$src['party_id'],$doc,date('Y-m-d H:i:s'),'requested',(string)($src['notes']??''),$u['id']]);
                $newId=(int)$pdo->lastInsertId();
                $ins=$pdo->prepare('INSERT INTO product_request_items(company_id,request_id,item_id,qty) VALUES(?,?,?,?)');
                foreach($srcItems as $it){$ins->execute([$cid,$newId,(int)$it['item_id'],(float)$it['qty']]);}
                audit('duplicate','product_request',$newId,['source_request_id'=>$rid,'source_document'=>$src['request_no'],'document'=>$doc]);
                $pdo->commit();
                flash('success','Product Request '.$doc.' duplicated successfully.');
                redirect('product-request-new?edit='.$newId);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect('product-requests');}
        }
        if($action==='delete_product_request'){
            check_csrf();
            $rid=(int)($_POST['request_id']??0);
            try{
                $st=$pdo->prepare('SELECT * FROM product_requests WHERE id=? AND company_id=? LIMIT 1');$st->execute([$rid,$cid]);$req=$st->fetch();
                if(!$req)throw new RuntimeException('Product Request not found.');
                if(!empty($req['sale_transaction_id']) || $req['status']==='converted')throw new RuntimeException('Converted Product Request cannot be deleted.');
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM product_request_items WHERE request_id=? AND company_id=?')->execute([$rid,$cid]);
                $pdo->prepare('DELETE FROM product_requests WHERE id=? AND company_id=?')->execute([$rid,$cid]);
                audit('delete','product_request',$rid,['document'=>$req['request_no'],'party_id'=>$req['party_id']]);
                $pdo->commit();
                flash('success','Product Request '.$req['request_no'].' deleted successfully.');
                redirect('product-requests');
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect('product-requests');}
        }
        if($action==='save_product_request'){
            check_csrf();
            $rid=(int)($_POST['request_id']??0);
            try{
                $party=(int)($_POST['party_id']??0);
                $st=$pdo->prepare('SELECT p.id,p.name FROM parties p WHERE p.id=? AND p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role="customer") LIMIT 1');$st->execute([$party,$cid]);$partyRow=$st->fetch();
                if(!$partyRow)throw new RuntimeException('Customer is required.');
                $items=$_POST['item_id']??[];$qtys=$_POST['qty']??[];$valid=[];$seen=[];
                foreach($items as $i=>$iid){
                    $iid=(int)$iid;$q=(float)($qtys[$i]??0);if($iid<=0||$q<=0)continue;
                    if(isset($seen[$iid])){ $idx=$seen[$iid]; $valid[$idx][1]+=$q; continue; }
                    $itq=$pdo->prepare('SELECT id,name,item_type,active FROM items WHERE id=? AND company_id=? LIMIT 1');$itq->execute([$iid,$cid]);$it=$itq->fetch();
                    if(!$it||!(int)$it['active'])throw new RuntimeException('Invalid item selected.');
                    if($it['item_type']!=='product')throw new RuntimeException('Product Request can contain products only.');
                    $seen[$iid]=count($valid);$valid[]=[$iid,$q,$it];
                }
                if(!$valid)throw new RuntimeException('Add at least one product.');
                $doc=trim($_POST['document_no']??'');
                if($rid>0){
                    $st=$pdo->prepare('SELECT * FROM product_requests WHERE id=? AND company_id=? LIMIT 1');$st->execute([$rid,$cid]);$old=$st->fetch();
                    if(!$old)throw new RuntimeException('Product Request not found.');
                    if(!empty($old['sale_transaction_id']) || $old['status']==='converted')throw new RuntimeException('Converted Product Request cannot be edited.');
                    if($doc==='')$doc=$old['request_no'];
                    $dup=$pdo->prepare('SELECT id FROM product_requests WHERE company_id=? AND request_no=? AND id<>? LIMIT 1');$dup->execute([$cid,$doc,$rid]);if($dup->fetch())throw new RuntimeException('Request Number already exists.');
                    $reqDate=transaction_datetime($_POST['request_date']??null);
                    $pdo->beginTransaction();
                    $pdo->prepare('UPDATE product_requests SET party_id=?,request_no=?,request_date=?,status="requested",notes=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND company_id=?')->execute([$party,$doc,$reqDate,trim($_POST['notes']??''),$rid,$cid]);
                    $pdo->prepare('DELETE FROM product_request_items WHERE request_id=? AND company_id=?')->execute([$rid,$cid]);
                    $ins=$pdo->prepare('INSERT INTO product_request_items(request_id,company_id,item_id,qty) VALUES(?,?,?,?)');
                    foreach($valid as [$iid,$q])$ins->execute([$rid,$cid,$iid,$q]);
                    audit('update','product_request',$rid,['document'=>$doc,'party_id'=>$party,'items'=>count($valid)]);
                    $pdo->commit();flash('success','Product Request '.$doc.' updated successfully.');redirect('product-request-new?view='.$rid);
                }
                $pdo->beginTransaction();
                if($doc==='')$doc=next_document_in_transaction($pdo,$cid,'product_request','PR-');
                $dup=$pdo->prepare('SELECT id FROM product_requests WHERE company_id=? AND request_no=? LIMIT 1');$dup->execute([$cid,$doc]);if($dup->fetch())throw new RuntimeException('Request Number already exists.');
                $reqDate=transaction_datetime($_POST['request_date']??null);
                $pdo->prepare('INSERT INTO product_requests(company_id,party_id,request_no,request_date,status,notes,created_by) VALUES(?,?,?,?,?,?,?)')->execute([$cid,$party,$doc,$reqDate,'requested',trim($_POST['notes']??''),$u['id']]);
                $rid=(int)$pdo->lastInsertId();$ins=$pdo->prepare('INSERT INTO product_request_items(request_id,company_id,item_id,qty) VALUES(?,?,?,?)');
                foreach($valid as [$iid,$q])$ins->execute([$rid,$cid,$iid,$q]);
                audit('create','product_request',$rid,['document'=>$doc,'party_id'=>$party]);
                $pdo->commit();flash('success','Product Request '.$doc.' saved successfully.');redirect('product-request-new?view='.$rid);
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect($rid>0?'product-request-new?edit='.$rid:'product-request-new');}
        }
    }

    // Convert to Sale
    if(isset($_GET['convert'])){
        $rid=(int)$_GET['convert'];
        try{
            $st=$pdo->prepare('SELECT r.*,p.name party_name FROM product_requests r JOIN parties p ON p.id=r.party_id WHERE r.id=? AND r.company_id=? AND r.status<>"cancelled" LIMIT 1');$st->execute([$rid,$cid]);$req=$st->fetch();if(!$req)throw new RuntimeException('Product Request not found.');
            if(!empty($req['sale_transaction_id'])){redirect('product-request-new?view='.$rid);}
            $q=$pdo->prepare('SELECT pri.*,i.name item_name,i.sale_price,i.purchase_price,i.item_type,i.serial_tracked FROM product_request_items pri JOIN items i ON i.id=pri.item_id WHERE pri.request_id=? AND pri.company_id=? ORDER BY pri.id');$q->execute([$rid,$cid]);$reqItems=$q->fetchAll();if(!$reqItems)throw new RuntimeException('Product Request has no items.');
            $prepared=[];$total=0;$pdo->beginTransaction();
            foreach($reqItems as $r){
                if($r['item_type']!=='product')throw new RuntimeException('Only products can be converted to Sale.');
                $stock=product_request_stock($pdo,$cid,(int)$r['item_id']);if($stock+0.000001 < (float)$r['qty'])throw new RuntimeException($r['item_name'].' is not fully available yet. Available: '.qty($stock).' / Requested: '.qty((float)$r['qty']));
                $price=max(0,(float)$r['sale_price']);$amount=round($price*(float)$r['qty'],2);$total+=$amount;$prepared[]=[$r,$price,$amount];
            }
            $total=round($total,2);$doc=next_document_in_transaction($pdo,$cid,'sale','SI-');$saleDate=transaction_datetime(null);
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$cid,$req['party_id'],'sale',$doc,$saleDate,null,$total,0,0,0,0,$total,0,$total,$u['currency_code'],'open','Converted from Product Request '.$req['request_no'],$u['id']]);
            $tid=(int)$pdo->lastInsertId();$insItem=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,qty,unit_price,discount,tax,amount) VALUES(?,?,?,?,?,?,?)');$stockIns=$pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,movement_type,note) VALUES(?,?,?,?,?,?,?)');$serialLink=$pdo->prepare('INSERT INTO transaction_item_serials(company_id,transaction_id,transaction_item_id,serial_id,movement_type) VALUES(?,?,?,?,?)');$cogs=0;
            foreach($prepared as [$r,$price,$amount]){
                $insItem->execute([$tid,$r['item_id'],$r['qty'],$price,0,0,$amount]);$tiId=(int)$pdo->lastInsertId();$q=(float)$r['qty'];$stockIns->execute([$cid,(int)$r['item_id'],$tid,$saleDate,-$q,'sale',$doc]);$cogs+=max(0,$q*(float)$r['purchase_price']);
                if((int)$r['serial_tracked']===1){if(abs($q-round($q))>0.000001)throw new RuntimeException('Serial-tracked product request quantity must be a whole number for '.$r['item_name'].'.');$needed=(int)round($q);$sq=$pdo->prepare('SELECT id FROM item_serials WHERE company_id=? AND item_id=? AND status="available" ORDER BY id LIMIT '.$needed.' FOR UPDATE');$sq->execute([$cid,(int)$r['item_id']]);$serialIds=array_map('intval',$sq->fetchAll(PDO::FETCH_COLUMN));if(count($serialIds)<$needed)throw new RuntimeException('Not enough available serial numbers for '.$r['item_name'].'.');foreach($serialIds as $sid){$pdo->prepare('UPDATE item_serials SET status="sold",sale_transaction_id=? WHERE id=? AND company_id=?')->execute([$tid,$sid,$cid]);$serialLink->execute([$cid,$tid,$tiId,$sid,'sale']);}}
            }
            $lines=[['1200','Accounts Receivable',$total,0,$doc],['4000','Sales Revenue',0,$total,$doc]];if($cogs>0){$lines[]=['5100','Cost of Goods Sold',$cogs,0,$doc];$lines[]=['1300','Inventory',0,$cogs,$doc];}post_ledger($pdo,$cid,$tid,$saleDate,$lines);
            $pdo->prepare('INSERT INTO transaction_links(company_id,from_transaction_id,to_transaction_id,relation_type,quantity) VALUES(?,?,?,?,NULL)')->execute([$cid,$rid,$tid,'product_request_to_sale']);
            $pdo->prepare('UPDATE product_requests SET status="converted",sale_transaction_id=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND company_id=?')->execute([$tid,$rid,$cid]);audit('convert','product_request',$rid,['to_transaction'=>$tid,'target_document'=>$doc]);$pdo->commit();flash('success',$req['request_no'].' converted to '.$doc.'.');redirect('sales?view='.$tid);
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());redirect('product-request-new?view='.$rid);}
    }

    page_start('Product Request');

    // View / Edit existing request
    if(isset($_GET['view']) || isset($_GET['edit'])){
        $rid=(int)($_GET['edit']??$_GET['view']??0);
        $st=$pdo->prepare('SELECT r.*,p.name party_name,p.phone party_phone FROM product_requests r JOIN parties p ON p.id=r.party_id WHERE r.id=? AND r.company_id=? LIMIT 1');$st->execute([$rid,$cid]);$req=$st->fetch();if(!$req){flash('error','Product Request not found.');redirect('product-requests');}
        if(isset($_GET['edit'])){
            if(!empty($req['sale_transaction_id']) || $req['status']==='converted'){flash('error','Converted Product Request cannot be edited.');redirect('product-request-new?view='.$rid);}
            $iq=$pdo->prepare('SELECT pri.*,i.name item_name,i.code FROM product_request_items pri JOIN items i ON i.id=pri.item_id WHERE pri.request_id=? AND pri.company_id=? ORDER BY pri.id');$iq->execute([$rid,$cid]);$editItems=$iq->fetchAll();
            $itemRows=$editItems?:[['item_id'=>0,'item_name'=>'','code'=>'','qty'=>1]];
            $status=product_request_status($pdo,$cid,$rid);
            ?><style>
            .pr-form{padding-bottom:88px}.pr-form .entry-table th,.pr-form .entry-table td{padding:7px 8px}.pr-form .entry-table input{height:36px}.pr-status-pill{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;font-size:11px;font-weight:700}.pr-requested{background:#f3f4f6;color:#6b7280}.pr-partial{background:#fff7ed;color:#c2410c}.pr-available{background:#ecfdf5;color:#047857}.pr-converted{background:#eff6ff;color:#1d4ed8}.pr-form .form-footer{position:sticky;bottom:0;z-index:20;background:rgba(255,255,255,.96);backdrop-filter:blur(4px);border-top:1px solid #e2e8f0}
            </style>
            <div class="page-title"><div><h1>Edit Product Request <?=e($req['request_no'])?></h1><p><?=e($req['party_name'])?> · <?=e($req['request_date'])?></p></div><a class="btn" href="<?=e(url('product-requests'))?>">Back</a></div>
            <form method="post" class="panel standard-entry-form pr-form"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_product_request"><input type="hidden" name="request_id" value="<?=$rid?>">
              <div class="entry-top standard-entry-top"><div class="standard-party-field"><?php party_search_field('Customer','customer',(int)$req['party_id'],(string)$req['party_name'],(string)($req['party_phone']??''),true); ?><div style="margin-top:6px"><button type="button" class="btn small-btn" onclick="senseOpenInlinePartyModal('customer')">+ Add Party</button></div></div><div class="form-group"><label>Request Number</label><input name="document_no" value="<?=e($req['request_no'])?>" required></div><div class="form-group"><label>Request Date*</label><input type="date" name="request_date" value="<?=e(date('Y-m-d',strtotime($req['request_date'])))?>" required></div></div>
              <div class="entry-table"><table><thead><tr><th>#</th><th>ITEM / PRODUCT</th><th>QTY</th><th>AVAILABLE NOW</th><th></th></tr></thead><tbody id="prRows">
              <?php foreach($itemRows as $idx=>$ir): ?><tr><td><?=($idx+1)?></td><td><div class="item-picker-cell"><?php item_search_field((int)$ir['item_id'],(string)($ir['item_name']??''),(string)($ir['code']??''),'sale'); ?><select name="item_id[]" class="item-source-select pr-item" required><option value="">Select product</option><?php if((int)$ir['item_id']>0):?><option value="<?=((int)$ir['item_id'])?>" selected><?=e($ir['item_name'])?></option><?php endif;?></select></div></td><td><input type="number" name="qty[]" class="pr-qty" step="0.01" min="0.01" value="<?=e((string)$ir['qty'])?>" required></td><td class="pr-available"><?=((int)$ir['item_id']>0)?e(qty(product_request_stock($pdo,$cid,(int)$ir['item_id']))):'—'?></td><td><button type="button" class="btn small-btn" onclick="prRemoveRow(this)">×</button></td></tr><?php endforeach; ?></tbody></table></div>
              <div class="entry-actions" style="display:flex;justify-content:space-between;align-items:center;padding:10px 0"><div style="display:flex;gap:8px"><button type="button" class="btn" onclick="prAddRow()">+ Add Row</button><button type="button" class="btn" onclick="senseOpenInlineProductModal('#prRows','pr')">+ Add Product</button></div><span class="subtle">Availability is checked automatically from current stock.</span></div>
              <div class="form-group"><label>Description / Note</label><textarea name="notes" rows="3" placeholder="Optional request note"><?=e((string)($req['notes']??''))?></textarea></div>
              <div class="form-footer" style="margin:0 -16px -16px"><a class="btn" href="<?=e(url('product-request-new?view='.$rid))?>">Cancel</a><button class="btn primary">Update Product Request</button></div>
            </form>
            <script>
            function prRemoveRow(btn){const tr=btn.closest('tr'),b=document.getElementById('prRows');if(!tr||!b)return;if(b.querySelectorAll('tr').length<=1){const s=tr.querySelector('.pr-item');if(s){s.value='';}const inp=tr.querySelector('.item-search-input');if(inp)inp.value='';const q=tr.querySelector('.pr-qty');if(q)q.value='1';return;}tr.remove();[...b.querySelectorAll('tr')].forEach((r,i)=>r.firstElementChild.textContent=i+1);}
            function prAddRow(){const b=document.getElementById('prRows'),first=b?.querySelector('tr');if(!b||!first)return;const clone=first.cloneNode(true);clone.querySelectorAll('.item-live-search').forEach(x=>{x.dataset.itemSearchBound='';x.removeAttribute('data-item-search-bound');const inp=x.querySelector('.item-search-input');if(inp)inp.value='';const sr=x.querySelector('.item-search-results');if(sr){sr.hidden=true;sr.innerHTML='';}});const sel=clone.querySelector('.pr-item');if(sel){sel.innerHTML='<option value="">Select product</option>';sel.value='';}const q=clone.querySelector('.pr-qty');if(q)q.value='1';const av=clone.querySelector('.pr-available');if(av)av.textContent='—';clone.firstElementChild.textContent=b.querySelectorAll('tr').length+1;b.appendChild(clone);if(typeof window.SutoInitItemSearch==='function')window.SutoInitItemSearch(clone);}
            async function prCheckAvailability(row){const sel=row?.querySelector('.pr-item'),cell=row?.querySelector('.pr-available');if(!sel||!cell)return;const id=parseInt(sel.value||'0',10);if(!id){cell.textContent='—';return;}try{const r=await fetch('<?=e(url('product-request-stock'))?>?item_id='+id,{headers:{'Accept':'application/json'}});const j=await r.json();cell.textContent=(j.available??0).toLocaleString('en-US',{minimumFractionDigits:0,maximumFractionDigits:3});}catch(e){cell.textContent='—';}}
            document.addEventListener('change',e=>{if(e.target.matches('.pr-item'))prCheckAvailability(e.target.closest('tr'));});document.addEventListener('DOMContentLoaded',()=>document.querySelectorAll('#prRows tr').forEach(prCheckAvailability));
            </script><?php render_inline_creation_modals(); page_end();exit;
        }
        $status=product_request_status($pdo,$cid,$rid);$saleId=(int)($req['sale_transaction_id']??0);$printMode=isset($_GET['print']);
        ?><style><?php if($printMode):?>@media print{body{background:#fff!important}.sidebar,.topbar,.page-title .btn,.no-print{display:none!important}.main{margin:0!important}.content{padding:0!important}.print-doc{box-shadow:none!important;border:0!important;margin:0!important;max-width:none!important}}<?php endif;?>.pr-print-wrap{max-width:920px;margin:0 auto}.pr-print-doc{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:24px}.pr-print-head{display:flex;justify-content:space-between;gap:20px;border-bottom:2px solid #0f172a;padding-bottom:14px;margin-bottom:18px}.pr-print-table{width:100%;border-collapse:collapse}.pr-print-table th,.pr-print-table td{border-bottom:1px solid #e2e8f0;padding:9px 8px;text-align:left}.pr-print-actions{display:flex;gap:8px;margin-bottom:12px;justify-content:flex-end}</style>
        <div class="page-title no-print"><div><h1>Product Request <?=e($req['request_no'])?></h1><p><?=e($req['party_name'])?> · <?=e($req['request_date'])?></p></div><div class="no-print"><a class="btn" href="<?=e(url('product-requests'))?>">Back</a><?php if(!$saleId):?><a class="btn primary" href="<?=e(url('product-request-new?edit='.$rid))?>">View / Edit</a><?php endif;?><?php if($status['status']==='available'&&!$saleId):?><a class="btn primary" href="<?=e(url('product-request-new?convert='.$rid))?>" onclick="return confirm('Convert this Product Request to Sale?')">Convert to Sale</a><?php elseif($saleId):?><a class="btn primary" href="<?=e(url('sales?view='.$saleId))?>">View Sale</a><?php endif;?></div></div>
        <?php if($printMode): ?><div class="pr-print-actions no-print"><button class="btn primary" type="button" onclick="window.print()">Print</button><a class="btn" href="<?=e(url('product-requests'))?>">Close</a></div><?php endif;?>
        <div class="pr-print-wrap"><div class="pr-print-doc print-doc"><div class="pr-print-head"><div><strong><?=e($u['company_name']??'')?></strong><div class="subtle">Product Request</div></div><div style="text-align:right"><strong><?=e($req['request_no'])?></strong><br><?=e(date('d/m/Y',strtotime($req['request_date'])))?></div></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px"><div class="metric-card"><div class="label">CUSTOMER</div><div class="value" style="font-size:18px"><?=e($req['party_name'])?></div><div class="subtle"><?=e($req['party_phone']??'')?></div></div><div class="metric-card"><div class="label">STATUS</div><div class="value" style="font-size:18px"><?=e(ucwords(str_replace('_',' ',$status['status'])))?></div></div></div>
        <table class="pr-print-table"><thead><tr><th>#</th><th>ITEM</th><th>REQUESTED QTY</th><th>AVAILABLE</th><th>STATUS</th></tr></thead><tbody><?php foreach($status['items'] as $i=>$it):?><tr><td><?=$i+1?></td><td><?=e($it['name'])?></td><td><?=qty((float)$it['requested_qty'])?></td><td><?=qty((float)$it['available_qty'])?></td><td><?=e($it['is_available']?'Available':'Pending')?></td></tr><?php endforeach;?></tbody></table><?php if(!empty($req['notes'])):?><div style="margin-top:16px;padding:12px;border:1px solid #e2e8f0;border-radius:8px"><strong>NOTE</strong><div style="margin-top:6px"><?=nl2br(e($req['notes']))?></div></div><?php endif;?></div></div>
        <?php if($printMode): ?><script>window.addEventListener('load',()=>setTimeout(()=>window.print(),250));</script><?php endif; ?>
        <?php page_end();exit;
    }

    $partiesSt=$pdo->prepare('SELECT p.id,p.name,p.phone FROM parties p WHERE p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role="customer") ORDER BY p.name');$partiesSt->execute([$cid]);$parties=$partiesSt->fetchAll();
    $items=$pdo->prepare('SELECT id,name,code FROM items WHERE company_id=? AND active=1 AND item_type="product" ORDER BY name');$items->execute([$cid]);$productItems=$items->fetchAll();
    $q=trim($_GET['q']??'');$sql='SELECT r.*,p.name party_name,p.phone party_phone FROM product_requests r JOIN parties p ON p.id=r.party_id WHERE r.company_id=?';$params=[$cid];if($q!==''){ $sql.=' AND (r.request_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)';$like='%'.$q.'%';$params[]=$like;$params[]=$like;$params[]=$like; }$sql.=' ORDER BY r.request_date DESC,r.id DESC';$st=$pdo->prepare($sql);$st->execute($params);$requests=$st->fetchAll();
    ?><style>
    .pr-form{padding-bottom:88px}.pr-form .entry-table th,.pr-form .entry-table td{padding:7px 8px}.pr-form .entry-table input{height:36px}.pr-note{margin-top:12px}.pr-status-pill{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;font-size:11px;font-weight:700}.pr-requested{background:#f3f4f6;color:#6b7280}.pr-partial{background:#fff7ed;color:#c2410c}.pr-available{background:#ecfdf5;color:#047857}.pr-converted{background:#eff6ff;color:#1d4ed8}.pr-table small{color:#7b8794}.pr-form .form-footer{position:sticky;bottom:0;z-index:20;background:rgba(255,255,255,.96);backdrop-filter:blur(4px);border-top:1px solid #e2e8f0}.pr-availability-note{margin-top:8px;font-size:12px;color:#64748b}.pr-menu-wrap{position:relative;display:inline-block}.pr-menu{position:absolute;right:0;top:34px;display:none;min-width:150px;background:#fff;border:1px solid #d8e0ea;border-radius:8px;box-shadow:0 10px 24px rgba(15,23,42,.16);z-index:50;padding:4px}.pr-menu.open{display:block}.pr-menu a,.pr-menu button{display:block;width:100%;padding:8px 10px;border:0;background:#fff;text-align:left;text-decoration:none;color:#243247;font:inherit;cursor:pointer;border-radius:5px}.pr-menu a:hover,.pr-menu button:hover{background:#f3f6fa}.pr-menu .danger{color:#c0392b}
    </style>
    <div class="page-title"><div><h1>Product Request</h1><p>Request products from a customer and convert when all requested items become available.</p></div><a class="btn" href="<?=e(url('product-requests'))?>">Back to Product Requests</a></div>
    <form method="post" class="panel standard-entry-form pr-form"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="save_product_request">
      <div class="entry-top standard-entry-top"><div class="standard-party-field"><?php party_search_field('Customer','customer',0,'',''); ?><div style="margin-top:6px"><button type="button" class="btn small-btn" onclick="senseOpenInlinePartyModal('customer')">+ Add Party</button></div></div><div class="form-group"><label>Request Number</label><input name="document_no" placeholder="Auto: PR-01"></div><div class="form-group"><label>Request Date*</label><input type="date" name="request_date" value="<?=date('Y-m-d')?>" required></div></div>
      <div class="entry-table"><table><thead><tr><th>#</th><th>ITEM / PRODUCT</th><th>QTY</th><th>AVAILABLE NOW</th><th></th></tr></thead><tbody id="prRows"><tr><td>1</td><td><div class="item-picker-cell"><?php item_search_field(0,'','','sale'); ?><select name="item_id[]" class="item-source-select pr-item" required><option value="">Select product</option></select></div></td><td><input type="number" name="qty[]" class="pr-qty" step="0.01" min="0.01" value="1" required></td><td class="pr-available">—</td><td></td></tr></tbody></table></div>
      <div class="entry-actions" style="display:flex;justify-content:space-between;align-items:center;padding:10px 0"><div style="display:flex;gap:8px"><button type="button" class="btn" onclick="prAddRow()">+ Add Row</button><button type="button" class="btn" onclick="senseOpenInlineProductModal('#prRows','pr')">+ Add Product</button></div><span class="subtle">Availability is checked automatically from current stock.</span></div>
      <div class="form-group pr-note"><label>Description / Note</label><textarea name="notes" rows="3" placeholder="Optional request note"></textarea></div>
      <div class="form-footer" style="margin:0 -16px -16px"><a class="btn" href="<?=e(url('product-requests'))?>">Cancel</a><button class="btn primary">Save Product Request</button></div>
    </form>
    <div class="panel" style="margin-top:14px"><div class="panel-head"><h2>PRODUCT REQUESTS</h2><form method="get" style="display:flex;gap:8px"><input class="input" name="q" value="<?=e($q)?>" style="max-width:280px" placeholder="Search by request, customer, phone"><button class="btn">Search</button></form></div><div class="table-wrap pr-table"><table><thead><tr><th>DATE</th><th>REQUEST NO.</th><th>CUSTOMER</th><th>STATUS</th><th>ACTION</th></tr></thead><tbody>
    <?php foreach($requests as $r): $stx=product_request_status($pdo,$cid,(int)$r['id']);$status=$stx['status'];$label=ucwords(str_replace('_',' ',$status));$cls=$status==='available'?'pr-available':($status==='partially_available'?'pr-partial':($status==='converted'?'pr-converted':'pr-requested')); ?><tr><td><?=e(date('d/m/Y h:i A',strtotime($r['request_date'])))?></td><td><?=e($r['request_no'])?></td><td><?=e($r['party_name'])?><br><small><?=e($r['party_phone']??'')?></small></td><td><span class="pr-status-pill <?=$cls?>"><?=e($label)?></span></td><td><div class="action" style="display:flex;align-items:center;gap:6px;justify-content:flex-end"><a class="btn small-btn" href="<?=e(url('product-request-new?view='.(int)$r['id']))?>">View</a><?php if($status==='available'&&!$r['sale_transaction_id']):?><a class="btn primary small-btn" href="<?=e(url('product-request-new?convert='.(int)$r['id']))?>" onclick="return confirm('Convert this Product Request to Sale?')">CONVERT TO SALE</a><?php elseif($r['sale_transaction_id']):?><a class="btn small-btn" href="<?=e(url('sales?view='.(int)$r['sale_transaction_id']))?>">VIEW SALE</a><?php endif;?><div class="pr-menu-wrap"><button type="button" class="dots" onclick="this.nextElementSibling.classList.toggle('open')" aria-label="Actions">⋮</button><div class="pr-menu"><a href="<?=e(url('product-request-new?view='.(int)$r['id']))?>">View</a><?php if(!$r['sale_transaction_id'] && $status!=='converted'):?><a href="<?=e(url('product-request-new?edit='.(int)$r['id']))?>">Edit</a><form method="post" onsubmit="return confirm('Delete this Product Request?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_product_request"><input type="hidden" name="request_id" value="<?=((int)$r['id'])?>"><button type="submit" class="danger">Delete</button></form><?php else:?><span style="display:block;padding:8px 10px;color:#94a3b8;font-size:12px">Converted request is locked</span><?php endif;?></div></div></div></td></tr><?php endforeach; if(!$requests):?><tr><td colspan="5" class="subtle">No Product Requests found.</td></tr><?php endif;?></tbody></table></div></div>
    <script>
    document.addEventListener('click',e=>{document.querySelectorAll('.pr-menu.open').forEach(m=>{if(!m.parentElement.contains(e.target))m.classList.remove('open');});});
    function prAddRow(){const b=document.getElementById('prRows');const first=b?.querySelector('tr');if(!b||!first)return;const clone=first.cloneNode(true);clone.querySelectorAll('.item-live-search').forEach(x=>{x.dataset.itemSearchBound='';x.removeAttribute('data-item-search-bound');const inp=x.querySelector('.item-search-input');if(inp)inp.value='';const sr=x.querySelector('.item-search-results');if(sr){sr.hidden=true;sr.innerHTML='';}});const sel=clone.querySelector('.pr-item');if(sel){sel.innerHTML='<option value="">Select product</option>';sel.value='';}const inp=clone.querySelector('.pr-qty');if(inp)inp.value='1';const av=clone.querySelector('.pr-available');if(av)av.textContent='—';clone.firstElementChild.textContent=b.querySelectorAll('tr').length+1;b.appendChild(clone);if(typeof window.SutoInitItemSearch==='function')window.SutoInitItemSearch(clone);}
    </script><?php render_inline_creation_modals(); page_end();exit;
}

if($route==='product-request-stock'){
    header('Content-Type: application/json; charset=utf-8');
    $cid=(int)$u['company_id'];$itemId=(int)($_GET['item_id']??0);$itemSt=db()->prepare('SELECT id,name,item_type FROM items WHERE id=? AND company_id=? AND active=1 LIMIT 1');$itemSt->execute([$itemId,$cid]);$item=$itemSt->fetch();if(!$item){http_response_code(404);echo json_encode(['error'=>'Item not found']);exit;}echo json_encode(['item_id'=>$itemId,'available'=>product_request_stock(db(),$cid,$itemId)],JSON_UNESCAPED_UNICODE);exit;
}

function product_requests_list(): void {
    global $u;$cid=(int)$u['company_id'];$pdo=db();
    $q=trim($_GET['q']??'');$sql='SELECT r.*,p.name party_name,p.phone party_phone FROM product_requests r JOIN parties p ON p.id=r.party_id WHERE r.company_id=?';$params=[$cid];
    if($q!==''){ $sql.=' AND (r.request_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)';$like='%'.$q.'%';$params[]=$like;$params[]=$like;$params[]=$like; }
    $sql.=' ORDER BY r.request_date DESC,r.id DESC';$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
    page_start('Product Requests');
    ?><style>
    .pr-status-pill{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;font-size:11px;font-weight:700}.pr-note-cell{max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#475569}.pr-note-cell span{display:block;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.pr-requested{background:#f3f4f6;color:#6b7280}.pr-partial{background:#fff7ed;color:#c2410c}.pr-available{background:#ecfdf5;color:#047857}.pr-converted{background:#eff6ff;color:#1d4ed8}.pr-table small{color:#7b8794}.pr-menu-wrap{position:relative;display:inline-block}.pr-menu{position:absolute;right:0;top:34px;display:none;min-width:150px;background:#fff;border:1px solid #d8e0ea;border-radius:8px;box-shadow:0 10px 24px rgba(15,23,42,.16);z-index:50;padding:4px}.pr-menu.open{display:block}.pr-menu a,.pr-menu button{display:block;width:100%;padding:8px 10px;border:0;background:#fff;text-align:left;text-decoration:none;color:#243247;font:inherit;cursor:pointer;border-radius:5px}.pr-menu a:hover,.pr-menu button:hover{background:#f3f6fa}.pr-menu .danger{color:#c0392b}
    </style>
    <div class="page-title"><div><h1>Product Request</h1><p>Customer product requests that become available over time.</p></div><a class="btn primary" href="<?=e(url('product-request-new'))?>">⊕ Add Product Request</a></div>
    <div class="panel"><div class="panel-head"><h2>PRODUCT REQUESTS</h2><form method="get" style="display:flex;gap:8px"><input class="input" name="q" value="<?=e($q)?>" style="max-width:280px" placeholder="Search by request, customer, phone"><button class="btn">Search</button></form></div><div class="table-wrap"><table><thead><tr><th>DATE</th><th>REQUEST NO.</th><th>CUSTOMER</th><th>STATUS</th><th>NOTE</th><th>ACTION</th></tr></thead><tbody>
    <?php foreach($rows as $r):$info=product_request_status($pdo,$cid,(int)$r['id']);$status=$info['status'];$cls=$status==='available'?'pr-available':($status==='partially_available'?'pr-partial':($status==='converted'?'pr-converted':'pr-requested'));$label=ucwords(str_replace('_',' ',$status));$canEdit=!$r['sale_transaction_id'] && $status!=='converted';?><tr><td><?=e(date('d/m/Y h:i A',strtotime($r['request_date'])))?></td><td><?=e($r['request_no'])?></td><td><?=e($r['party_name'])?><br><small><?=e($r['party_phone']??'')?></small></td><td><span class="pr-status-pill <?=$cls?>"><?=e($label)?></span></td><td class="pr-note-cell">
    <?php $note=trim((string)($r['notes']??'')); if($note!==''): ?><span title="<?=e($note)?>"><?=e(mb_strimwidth(preg_replace('/\s+/',' ',$note),0,70,'…','UTF-8'))?></span><?php else: ?><span class="subtle">—</span><?php endif; ?>
    </td><td><div style="display:flex;gap:6px;justify-content:flex-end;align-items:center">
    <?php if($canEdit):?><a class="btn small-btn" href="<?=e(url('product-request-new?edit='.(int)$r['id']))?>">View / Edit</a><?php else: ?><a class="btn small-btn" href="<?=e(url('product-request-new?view='.(int)$r['id']))?>">View</a><?php endif;?>
    <?php if($status==='available'&&!$r['sale_transaction_id']):?><a class="btn primary small-btn" href="<?=e(url('product-request-new?convert='.(int)$r['id']))?>" onclick="return confirm('Convert this Product Request to Sale?')">CONVERT TO SALE</a><?php elseif($r['sale_transaction_id']):?><a class="btn small-btn" href="<?=e(url('sales?view='.(int)$r['sale_transaction_id']))?>">VIEW SALE</a><?php endif;?>
    <?php $phone=preg_replace('/\D+/','',(string)($r['party_phone']??'')); $waText=rawurlencode('Product Request '.$r['request_no'].' for '.$r['party_name'].' is '.$label.'.'); ?>
    <div class="pr-menu-wrap"><button type="button" class="dots" onclick="this.nextElementSibling.classList.toggle('open')" aria-label="Actions">⋮</button><div class="pr-menu">
      <?php if($canEdit):?><form method="post" onsubmit="return confirm('Delete this Product Request?')"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="delete_product_request"><input type="hidden" name="request_id" value="<?=((int)$r['id'])?>"><button type="submit" class="danger">Delete</button></form><?php else:?><span style="display:block;padding:8px 10px;color:#94a3b8;font-size:12px">Converted request is locked</span><?php endif;?>
      <a href="<?=e(url('product-request-new?view='.(int)$r['id']))?>">Preview</a>
      <a href="<?=e(url('product-request-new?view='.(int)$r['id'].'&print=1'))?>">Print</a>
      <?php if($phone!==''):?><a href="https://wa.me/<?=e($phone)?>?text=<?=$waText?>" target="_blank" rel="noopener">Notify Customer</a><?php else:?><span style="display:block;padding:8px 10px;color:#94a3b8;font-size:12px">No phone number</span><?php endif;?>
      <form method="post"><input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="action" value="duplicate_product_request"><input type="hidden" name="request_id" value="<?=((int)$r['id'])?>"><button type="submit">Duplicate</button></form>
    </div></div>
    </div></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6" class="subtle">No Product Requests found.</td></tr><?php endif;?></tbody></table></div></div>
    <script>document.addEventListener('click',e=>{document.querySelectorAll('.pr-menu.open').forEach(m=>{if(!m.parentElement.contains(e.target))m.classList.remove('open');});});</script>
    <?php page_end();exit;
}


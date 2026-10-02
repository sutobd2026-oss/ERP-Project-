<?php
/* sense modular v1 route module extracted from the current public/index.php master. */
if(in_array($route,['sale-new','purchase-new'],true)){
    $u=require_login();
    page_start($route==='sale-new'?'Sale':'Purchase');
    $isSale=$route==='sale-new'; $cid=(int)$u['company_id']; $pdo=db(); ensure_bundle_schema();
    try { $cols=$pdo->query('SHOW COLUMNS FROM transaction_items')->fetchAll(PDO::FETCH_COLUMN,0); foreach(['item_description'=>'TEXT NULL','item_warranty'=>'VARCHAR(255) NULL'] as $c=>$def){if(!in_array($c,$cols,true))$pdo->exec('ALTER TABLE transaction_items ADD COLUMN `'.$c.'` '.$def);} } catch(Throwable $e) { error_log('transaction item metadata schema: '.$e->getMessage()); }
    $type=$isSale?'sale':'purchase'; $prefix=$isSale?'SI-':'PB-';
    $editId=(int)($_GET['edit']??0);
    $editTx=null; $editLines=[]; $editPayments=[]; $serialMap=[];
    if($editId>0){
        $st=db()->prepare('SELECT t.*,p.name party_name,p.phone party_phone FROM transactions t LEFT JOIN parties p ON p.id=t.party_id WHERE t.id=? AND t.company_id=? AND t.txn_type=? AND t.deleted_at IS NULL LIMIT 1');
        $st->execute([$editId,$cid,$type]); $editTx=$st->fetch();
        if(!$editTx){http_response_code(404);echo '<div class="panel"><h1>'.e($isSale?'Sale transaction not found':'Purchase transaction not found').'</h1></div>';page_end();exit;}
        $st=db()->prepare('SELECT ti.*,i.item_type,i.serial_tracked,i.description item_master_description,i.warranty item_master_warranty,i.location item_master_location,i.item_note item_master_note,u.symbol unit_symbol FROM transaction_items ti JOIN items i ON i.id=ti.item_id LEFT JOIN units u ON u.id=i.unit_id WHERE ti.transaction_id=? ORDER BY ti.id');
        $st->execute([$editId]); $editLines=$st->fetchAll();
        $serialMap=[]; $ss=$pdo->prepare('SELECT tis.transaction_item_id,isx.serial_number FROM transaction_item_serials tis JOIN item_serials isx ON isx.id=tis.serial_id WHERE tis.transaction_id=? AND tis.company_id=? ORDER BY tis.id');$ss->execute([$editId,$cid]);foreach($ss->fetchAll() as $sr){$serialMap[(int)$sr['transaction_item_id']][]=(string)$sr['serial_number'];}
        $st=db()->prepare('SELECT * FROM payment_lines WHERE transaction_id=? ORDER BY id');
        $st->execute([$editId]); $editPayments=$st->fetchAll();
    }
    $parties=db()->prepare('SELECT id,name,phone,party_type FROM parties WHERE company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=parties.id AND pr.role IN (?,?,?)) ORDER BY name');
    $parties->execute([$cid,'customer','both','supplier']); $partyRows=$parties->fetchAll();
    $items=get_items($cid);
    $itemMetaById=[]; foreach($items as $it0){$itemMetaById[(int)$it0['id']]=$it0;}
    $banks=db()->prepare('SELECT id,name,bank_name,account_number FROM bank_accounts WHERE company_id=? AND active=1 ORDER BY name'); $banks->execute([$cid]); $bankRows=$banks->fetchAll();
    $pageHeading=$editTx?($isSale?'Edit Sale Invoice':'Edit Purchase Bill'):($isSale?'Sale':'Purchase');
    $pageSub=$editTx?('Edit '.($isSale?'Sales Invoice':'Purchase Bill').' '.$editTx['document_no']):($isSale?'Create Sales Invoice':'Create Purchase Bill');
    $rows=$editLines ?: [null];
    $bundleMapTxn=bundle_map_for_company($pdo,$cid);
    $payRows=$editPayments ?: [null];
?>
<script>document.body.dataset.txntype=<?=json_encode($type)?>;</script>
<div class="page-title"><div><h1><?=e($pageHeading)?></h1><p><?=e($pageSub)?></p></div><a class="btn" href="<?=e(url($isSale?'sales':'purchase'))?>">Back to List</a></div>
<form id="txnForm" class="transaction-form txn-compact <?= $isSale ? '' : 'purchase-entry-form' ?>" method="post" action="<?=e(url('transaction-save'))?>" onsubmit="return validateTransactionForm()">
<input type="hidden" name="_csrf" value="<?=csrf_token()?>"><input type="hidden" name="txn_type" value="<?=$type?>"><?php if($editTx):?><input type="hidden" name="transaction_id" value="<?=$editTx['id']?>"><?php endif; ?>
<div class="panel">
  <div class="entry-top <?= $isSale?'sale-entry-top':'purchase-entry-top' ?>">
    <div>
      <?php party_search_field($isSale?'Customer':'Supplier',$isSale?'customer':'supplier',(int)($editTx['party_id']??0),$editTx['party_name']??'',(($editTx['party_phone']??'')),$isSale?false:true); ?>
      <div class="subtle" style="margin-top:6px;display:flex;gap:8px;align-items:center"><button type="button" class="btn small-btn" onclick="senseOpenInlinePartyModal('<?=$isSale?'customer':'supplier'?>')">+ Add Party</button><?= $isSale?' <span>(Customer optional for Cash Sale)</span>':'' ?></div>
    </div>
    <div class="entry-right <?= $isSale?'sale-invoice-meta':'' ?>">
      <div class="form-group"><label><?=$isSale?'Invoice':'Bill'?> Number</label><input name="document_no" value="<?=e($editTx['document_no']??'')?>" placeholder="Auto: <?=$prefix?>01"></div>
      <div class="form-group"><label><?=$isSale?'Invoice':'Bill'?> Date*</label><?php $editDateTime=(!empty($editTx['txn_date']))?date('Y-m-d\TH:i',strtotime((string)$editTx['txn_date'])):date('Y-m-d\TH:i'); ?><input type="<?= $isSale?'datetime-local':'date' ?>" name="txn_date" value="<?=e($isSale?$editDateTime:($editTx['txn_date']??date('Y-m-d')))?>" required></div>
      <?php if(!$isSale): ?><div class="subtle sale-purchase-note" style="margin-top:8px">BDT · Negative stock allowed</div><?php endif; ?>
    </div>
  </div>
  <div class="entry-table">
    <table><thead><tr><th></th><th>ITEM</th><th>QTY</th><th>UNIT</th><th>PRICE/UNIT</th><th>AMOUNT</th></tr></thead>
    <tbody id="entryRows">
<?php foreach($rows as $ri=>$r): $rid=(int)($r['item_id']??0); $rv=(float)($r['qty']??1); $rp=(float)($r['unit_price']??0); $rd=(float)($r['discount']??0); $meta=$rid&&isset($itemMetaById[$rid])?$itemMetaById[$rid]:[]; $bundleChildId=(int)($r['bundle_parent_transaction_item_id']??0); $bundleRowKey=$r?'tx-ti-'.(int)$r['id']:'new-row-'.bin2hex(random_bytes(4)); $bundleParentKey=$bundleChildId>0?'tx-ti-'.$bundleChildId:''; $isBundleChild=$bundleChildId>0; $lineDesc=trim((string)($r['item_description']??'')); if($lineDesc==='')$lineDesc=trim((string)($r['item_master_description']??($meta['description']??''))); $lineWarranty=trim((string)($r['item_warranty']??'')); if($lineWarranty==='')$lineWarranty=trim((string)($r['item_master_warranty']??($meta['warranty']??''))); ?>
      <tr class="sale-row <?= $isBundleChild?'bundle-child-row':'' ?>" data-bundle-child="<?= $isBundleChild?'1':'0' ?>" data-bundle-parent-key="<?=e($bundleParentKey)?>" data-bundle-row-key="<?=e($bundleRowKey)?>"><td class="txn-row-index-cell"><button type="button" class="row-remove-btn txn-row-remove" onclick="removeRow(this)" aria-label="Remove item row" title="Remove row">×</button><span class="txn-row-number"><?=($ri+1)?></span></td><td><input type="hidden" name="bundle_row_key[]" value="<?=e($bundleRowKey)?>"><input type="hidden" name="bundle_parent_key[]" value="<?=e($bundleParentKey)?>"><input type="hidden" name="bundle_child[]" value="<?= $isBundleChild?'1':'0' ?>"><div class="item-picker-cell"><?php if($isBundleChild): ?><div class="bundle-child-label"><span>└─ <strong><?=e($r['item_name']??($meta['name']??''))?></strong></span><span>FREE</span></div><input type="hidden" name="item_id[]" value="<?=((int)$rid)?>"><input type="hidden" name="item_description[]" value="<?=e($lineDesc)?>"><input type="hidden" name="item_warranty[]" value="<?=e($lineWarranty)?>"><?php else: ?><?php item_search_field($rid,'','',$isSale?'sale':'purchase'); ?><div class="item-line-meta" style="<?= $rid?'':'display:none' ?>"><div class="item-line-meta-grid"><div><input type="text" class="item-line-description" name="item_description[]" value="<?=e($lineDesc)?>" placeholder="Optional description"></div><div><input type="text" class="item-line-warranty" name="item_warranty[]" value="<?=e($lineWarranty)?>" placeholder="Warranty"></div></div></div><?php endif; ?><button type="button" class="serial-trigger-btn" hidden title="Enter serial numbers">Serial</button><?php if(!$isBundleChild): ?><select class="item-select item-source-select" name="item_id[]" onchange="updatePrice(this)"><option value="">Select item</option><?php foreach($items as $it):?><option value="<?=$it['id']?>" data-sale="<?=$it['sale_price']?>" data-buy="<?=$it['purchase_price']?>" data-unit="<?=e($unitSymbols[(int)($it['unit_id']??0)]??'')?>" data-type="<?=$it['item_type']?>" data-serial-tracked="<?=((int)($it['serial_tracked']??0))?>" data-description="<?=e($it['description']??'')?>" data-warranty="<?=e($it['warranty']??'')?>" data-bundle="<?=e(bundle_option_json($bundleMapTxn[(int)$it['id']]??[]))?>" <?=($rid===(int)$it['id'])?'selected':''?>><?=e($it['name'])?></option><?php endforeach;?></select><?php else: ?><input type="hidden" name="price[]" value="0"><input type="hidden" name="discount[]" value="0"><?php endif; ?><div class="serial-entry-box" hidden data-mode="<?=$type?>"><textarea name="serial_numbers[]" class="serial-number-input" tabindex="-1" aria-hidden="true"><?=e(implode("\n",$serialMap[$r['id']]??[]))?></textarea></div></div></td><td><input class="qty" type="number" min="1" step="1" name="qty[]" value="<?=e((string)$rv)?>" <?= $isBundleChild?'readonly':'' ?>></td><td><span class="unit-label subtle"><?=e($r['unit_symbol']??'—')?></span></td><td><?php if(!$isBundleChild): ?><input class="price" type="number" step="1" min="0" name="price[]" value="<?=e((string)$rp)?>"><?php else: ?><span class="bundle-free-label">Free</span><?php endif; ?></td><td><?php if(!$isBundleChild): ?><input class="line-disc" type="number" step="1" min="0" name="discount[]" value="<?=e((string)$rd)?>"><?php else: ?><span>—</span><?php endif; ?></td><td class="amount"><?=money(max(0,$rv*$rp-$rd))?></td></tr>
<?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="entry-actions"><div style="display:flex;gap:8px"><button type="button" class="btn" onclick="addRow('<?=$type?>')">+ Add Row</button><button type="button" class="btn" onclick="senseOpenInlineProductModal('#entryRows','<?=$type?>')">+ Add Product</button></div><span><b>Items Total</b <strong id="subTotal"><?=money((float)($editTx['subtotal']??0))?></strong></span></div>
  <?php if($isSale): ?>
  <div class="sale-adjustments">
    <div class="form-group"><label>Tax / VAT <span class="subtle">(optional)</span></label><input id="tax" type="number" step="0.01" min="0" name="tax" value="<?=e((string)($editTx['tax']??0))?>"></div>
    <div class="form-group"><label>Direct Expense</label><input id="directExpense" type="number" step="0.01" min="0" name="direct_expense" value="<?=e((string)($editTx['direct_expense']??0))?>"></div>
    <div class="form-group"><label>Invoice Discount</label><input id="invoiceDiscount" type="number" step="0.01" min="0" name="invoice_discount" value="<?=e((string)($editTx['invoice_discount']??0))?>"></div>
  </div>
  <?php else: ?>
  <div class="grid3">
    <div class="form-group"><label>Invoice Discount</label><input id="invoiceDiscount" type="number" step="0.01" min="0" name="invoice_discount" value="<?=e((string)($editTx['invoice_discount']??0))?>"></div>
    <div class="form-group"><label>Tax / VAT <span class="subtle">(optional)</span></label><input id="tax" type="number" step="0.01" min="0" name="tax" value="<?=e((string)($editTx['tax']??0))?>"></div>
    <div class="form-group"><label>Direct Expense</label><input id="directExpense" type="number" step="0.01" min="0" name="direct_expense" value="<?=e((string)($editTx['direct_expense']??0))?>"></div>
  </div>
  <?php endif; ?>
  <div class="grid2" style="margin-top:12px">
    <div class="form-group"><label>Description / Note</label><textarea name="notes" rows="3" placeholder="Add description"><?=e($editTx['notes']??'')?></textarea></div>
    <div class="payment-box"><div class="panel-head payment-panel-head"><div class="payment-title-wrap"><h2>Payment</h2><?php if($isSale): ?><label class="received-toggle"><input type="checkbox" id="receivedToggle" name="received" value="1" <?=($editTx && (float)$editTx['due']<=0.009 && (float)$editTx['total']>0)?'checked':''?>> <span>Received</span></label><?php endif; ?></div><span class="subtle">Multiple methods allowed</span></div>
      <div id="paymentRows">
<?php foreach($payRows as $pi=>$pr): $pm=(string)($pr['method']??'cash'); $pa=(float)($pr['amount']??0); ?>
        <div class="payment-line" data-payment-row><select name="pay_method[]" onchange="togglePaymentFields(this)"><?=payment_select_options($bankRows,$pm,(string)($pr['account_name']??''))?></select><input type="date" name="pay_cheque_date[]" class="pay-cheque-date" style="display:none" value="<?=e($pr['cheque_date']??'')?>"><input type="number" min="0" step="0.01" name="pay_amount[]" value="<?=e((string)$pa)?>" placeholder="Amount"><input name="pay_ref[]" value="<?=e($pr['reference_no']??'')?>" placeholder="Reference / Cheque No."><button type="button" class="payment-remove" onclick="removePayment(this)" aria-label="Remove payment">×</button></div>
<?php endforeach; ?>
      </div>
      <button type="button" class="btn" onclick="addPayment()">+ Add Payment</button>
    </div>
  </div>
  <div class="form-footer txn-form-footer">
    <div class="txn-footer-summary">
      <span class="txn-footer-total">Total <b id="grandTotal"><?=money((float)($editTx['total']??0))?></b></span>
      <span>Paid <b id="paidPreview"><?=money((float)($editTx['paid']??0))?></b></span>
      <span>Due <b id="duePreview"><?=money((float)($editTx['due']??0))?></b></span>
    </div>
    <div class="txn-footer-actions">
      <a class="btn" href="<?=e(url($isSale?'sales':'purchase'))?>">Cancel</a>
      <?php if($isSale): ?><button type="submit" name="save_and_print" value="1" class="btn">Save and Print</button><?php endif; ?>
      <button type="submit" class="btn primary"><?= $editTx?'Update':'Save' ?></button>
    </div>
  </div>
</div>
<div id="serialEntryModal" class="serial-entry-modal" data-mode="<?=$type?>" hidden aria-hidden="true">
  <div class="serial-entry-backdrop"></div>
  <div class="serial-entry-dialog" role="dialog" aria-modal="true" aria-labelledby="serialEntryTitle">
    <button type="button" class="serial-entry-x" id="serialModalClose" aria-label="Close">×</button>
    <div class="serial-entry-title" id="serialEntryTitle">Sale Item - Serial No.</div>
    <div class="serial-entry-item" id="serialEntryItemName"></div>
    <div class="serial-entry-sep"></div>
    <div class="serial-entry-label-row"><span>Enter Serial No.:</span><strong id="serialEntryCount">0/0 Entered</strong></div>
    <div class="serial-input-line" id="serialManualInputLine">
      <input type="text" id="serialEntryInput" class="serial-modal-input" placeholder="Enter/Scan" autocomplete="off">
      <button type="button" class="serial-add-btn" id="serialEntryAdd" aria-label="Add serial">✓</button>
    </div>
    <div class="serial-available-wrap" id="serialAvailableWrap">
      <input type="text" id="serialAvailableSearch" class="serial-available-search" placeholder="Search available serial..." autocomplete="off">
      <div class="serial-available-list" id="serialAvailableList"></div>
    </div>
    <div class="serial-entered-list" id="serialEnteredList"></div>
    <div class="serial-entry-help" id="serialEntryHelp"></div>
    <div class="serial-modal-footer"><button type="button" class="btn" id="serialModalCancel">Close</button><button type="button" class="btn primary" id="serialModalSave">Save</button></div>
  </div>
</div>
</form>
<script>
function renumberTxnRows(){document.querySelectorAll('#entryRows .sale-row').forEach(function(r,i){const n=r.querySelector('.txn-row-number');if(n)n.textContent=i+1;});}
function rowHasItem(row){return !!row?.querySelector('.item-select')?.value;}
function removeRow(btn){
  const row=btn?.closest('.sale-row'); const body=document.getElementById('entryRows'); if(!row||!body)return;
  if(row.dataset.bundleChild==='1'){ row.remove(); renumberTxnRows(); if(typeof recalc==='function') recalc(); return; }
  const key=row.querySelector('input[name=\"bundle_row_key[]\"]')?.value||row.dataset.bundleRowKey||'';
  if(key&&typeof removeBundleChildren==='function') removeBundleChildren(key);
  const remaining=[...body.querySelectorAll('.sale-row')].filter(r=>r.dataset.bundleChild!=='1');
  if(remaining.length<=1){
    const r=remaining[0];
    r.querySelectorAll('select').forEach(x=>x.selectedIndex=0);
    r.querySelectorAll('input').forEach(x=>{if(x.classList.contains('qty'))x.value='1';else if(x.classList.contains('price')||x.classList.contains('line-disc'))x.value='0';else if(x.classList.contains('item-search-input'))x.value=''});
    const k=r.querySelector('input[name=\"bundle_row_key[]\"]'); if(k){k.value=makeBundleRowKey();r.dataset.bundleRowKey=k.value;}
    r.querySelector('.item-search-clear')?.style && (r.querySelector('.item-search-clear').style.display='none');
    r.querySelector('.item-selected-summary')?.remove();
    const u=r.querySelector('.unit-label');if(u)u.textContent='—';const a=r.querySelector('.amount');if(a)a.textContent='৳0.00';
  }else{row.remove();}
  renumberTxnRows(); if(typeof recalc==='function') recalc();
}
function addRow(type){
  const body=document.getElementById('entryRows'); const first=body?.querySelector('.sale-row:not(.bundle-child-row)'); if(!body||!first)return;
  const clone=first.cloneNode(true);
  clone.querySelectorAll('input').forEach(function(i){
    if(i.classList.contains('qty')) i.value='1';
    else if(i.classList.contains('price')||i.classList.contains('line-disc')) i.value='0';
    else if(i.name==='serial_numbers[]') i.value='';
  });
  clone.querySelectorAll('select').forEach(function(s){s.selectedIndex=0;});
  const bundleRowKey=clone.querySelector('input[name=\"bundle_row_key[]\"]'); if(bundleRowKey){bundleRowKey.value=makeBundleRowKey();clone.dataset.bundleRowKey=bundleRowKey.value;}
  const bundleParentKey=clone.querySelector('input[name=\"bundle_parent_key[]\"]'); if(bundleParentKey)bundleParentKey.value='';
  const bundleChild=clone.querySelector('input[name=\"bundle_child[]\"]'); if(bundleChild)bundleChild.value='0'; clone.dataset.bundleChild='0'; clone.dataset.bundleParentKey='';
  const itemSearch=clone.querySelector('.item-search-input'); if(itemSearch)itemSearch.value='';
  const meta=clone.querySelector('.item-line-meta'); if(meta)meta.style.display='none';
  const desc=clone.querySelector('.item-line-description'); if(desc)desc.value='';
  const warranty=clone.querySelector('.item-line-warranty'); if(warranty)warranty.value='';
  const clear=clone.querySelector('.item-search-clear'); if(clear)clear.style.display='none';
  const results=clone.querySelector('.item-search-results'); if(results){results.hidden=true;results.innerHTML='';}
  const unit=clone.querySelector('.unit-label'); if(unit)unit.textContent='—';
  const amt=clone.querySelector('.amount'); if(amt)amt.textContent='৳0.00';
  body.appendChild(clone); renumberTxnRows();
  if(typeof bindEntryRow==='function') bindEntryRow(clone);
  if(typeof window.SutoInitItemSearch==='function') window.SutoInitItemSearch(clone);
  if(typeof recalc==='function') recalc();
}
</script><style>

.bundle-child-row{background:#fbfdff}.bundle-child-row td{border-top:0}.bundle-child-label{display:flex;justify-content:space-between;gap:8px;padding:7px 9px;border:1px solid #e3e9f0;border-radius:6px;background:#f8fbff}.bundle-child-label span:last-child,.bundle-free-label{font-size:11px;font-weight:800;color:#0f8a5a;text-transform:uppercase}.bundle-child-row .row-remove-btn{color:#c0392b}
.item-line-meta{margin-top:6px;padding:6px 7px;border:1px solid #e5ebf2;border-radius:7px;background:#fbfdff}.item-line-meta-grid{display:flex;flex-direction:row;gap:6px;align-items:end;width:100%;flex-wrap:nowrap}.item-line-meta-grid>div{min-width:0}.item-line-meta-grid>div:first-child{flex:1 1 auto;min-width:0}.item-line-meta-grid>div:last-child{flex:0 0 110px;width:110px}.item-line-meta label{display:none}.item-line-meta input,.item-line-meta textarea{width:100%;box-sizing:border-box;border:1px solid #dce4ed;border-radius:5px;background:#fff;color:#334155;padding:5px 6px;font-size:10px;line-height:1.25;min-height:28px}.item-line-meta textarea{resize:vertical;min-height:30px}.item-line-meta input[readonly],.item-line-meta textarea[readonly]{background:#f7f9fc;color:#64748b}.item-line-description{background:#fffef8!important}.item-line-meta input:focus,.item-line-meta textarea:focus{outline:none;border-color:#7aaee8;box-shadow:0 0 0 2px rgba(122,174,232,.12)}
.transaction-form.txn-compact{padding-bottom:78px}
.txn-form-footer{position:fixed!important;left:235px;right:0;bottom:0;z-index:55;margin:0!important;min-height:64px;padding:10px 18px!important;box-shadow:0 -4px 14px rgba(20,33,48,.10);align-items:center;justify-content:flex-end!important;gap:18px}
.txn-footer-summary{display:flex;align-items:center;gap:18px;min-width:0;color:#667385;font-size:12px}
.txn-footer-summary>span{display:inline-flex;align-items:center;gap:5px;white-space:nowrap}
.txn-footer-summary b{color:#27313f;font-size:13px;font-weight:700}
.txn-footer-summary .txn-footer-total{color:#526276}
.txn-footer-summary .txn-footer-total b{font-size:18px}
.txn-footer-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-left:0;flex:none}
@media(max-width:1180px){
  .txn-form-footer{left:215px}
}
@media(max-width:850px){
  .transaction-form.txn-compact{padding-bottom:118px}
  .txn-form-footer{left:0;right:0;min-height:104px;padding:10px 12px!important;flex-direction:column;align-items:stretch;gap:10px}
  .txn-footer-summary{justify-content:flex-start;gap:14px}
  .txn-footer-actions{width:100%;justify-content:flex-end}
  .txn-footer-actions .btn{flex:0 0 auto}
}
</style><script>
function makeBundleRowKey(){return 'bundle-row-'+Date.now().toString(36)+'-'+Math.random().toString(36).slice(2,8);}
function getRowBundle(row){
  const sel=row?.querySelector('.item-select');
  if(!sel||!sel.value)return [];
  try{
    const rawText=sel.selectedOptions?.[0]?.dataset?.bundle || row?.dataset?.bundleJson || '[]';
    const raw=JSON.parse(rawText||'[]')||[];
    return raw.map(function(c){return {
      item_id:Number(c.item_id||c.component_item_id||0),
      name:String(c.name||''),
      quantity:Number(c.quantity||1),
      unit_symbol:String(c.unit_symbol||''),
      serial_tracked:Number(c.serial_tracked||0)
    };}).filter(function(c){return c.item_id>0;});
  }catch(e){return [];} }
function removeBundleChildren(parentKey){
  if(!parentKey)return;
  document.querySelectorAll('#entryRows .bundle-child-row').forEach(function(r){if((r.dataset.bundleParentKey||'')===String(parentKey))r.remove();});
}
function insertAfter(row,newRow){if(row?.after)row.after(newRow);else row.parentNode.insertBefore(newRow,row.nextSibling);}
function createBundleChildRow(parentRow,component,parentKey,index){
  const tr=document.createElement('tr');tr.className='sale-row bundle-child-row';tr.dataset.bundleChild='1';tr.dataset.bundleParentKey=String(parentKey);tr.dataset.bundleRowKey=makeBundleRowKey();
  const qty=parseFloat(parentRow.querySelector('.qty')?.value||0)||0, factor=parseFloat(component.quantity||1)||1, totalQty=qty*factor;
  const esc=function(v){return String(v||'').replace(/[&<>]/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[m];});};
  const name=esc(component.name), unit=esc(component.unit_symbol), itemId=Number(component.item_id||0);
  tr.innerHTML='<td class="txn-row-index-cell"><button type="button" class="row-remove-btn txn-row-remove" onclick="removeRow(this)" aria-label="Remove item row" title="Remove row">×</button><span class="txn-row-number"></span></td>'+\
    '<td><input type="hidden" name="bundle_row_key[]" value="'+tr.dataset.bundleRowKey+'">'+
    '<input type="hidden" name="bundle_parent_key[]" value="'+String(parentKey).replace(/"/g,'&quot;')+'">'+
    '<input type="hidden" name="bundle_child[]" value="1">'+
    '<input type="hidden" name="item_id[]" value="'+itemId+'">'+
    '<input type="hidden" name="item_description[]" value="">'+
    '<input type="hidden" name="item_warranty[]" value="">'+
    '<div class="item-picker-cell"><div class="bundle-child-label"><span>└─ <strong>'+name+'</strong></span><span>FREE</span></div><button type="button" class="serial-trigger-btn" hidden title="Enter serial numbers">Serial</button><div class="serial-entry-box" hidden data-mode="sale"><textarea name="serial_numbers[]" class="serial-number-input" tabindex="-1" aria-hidden="true"></textarea><div class="serial-entry-meta"></div></div></div></td>'+
    '<td><input class="qty" type="number" min="0.001" step="0.001" name="qty[]" value="'+totalQty+'" readonly></td>'+ 
    '<td><span class="unit-label subtle">'+unit+'</span></td>'+ 
    '<td><input type="hidden" name="price[]" value="0"><span class="bundle-free-label">Free</span></td>'+ 
    '<td><input type="hidden" name="discount[]" value="0"><span>—</span></td>'+ 
    '<td class="amount">৳0.00</td>'+ 
    '<td><button type="button" class="row-remove-btn" onclick="removeRow(this)" aria-label="Remove free item" title="Remove free item">×</button></td>';
  return tr;
}
function renderBundleChildrenForRow(row,components){
  const body=document.getElementById('entryRows');if(!body)return;
  const parentKey=row.querySelector('input[name="bundle_row_key[]"]')?.value||row.dataset.bundleRowKey||'';if(!parentKey)return;
  removeBundleChildren(parentKey);
  row.dataset.bundleJson=JSON.stringify(components||[]);
  let anchor=row;
  (components||[]).forEach(function(component,index){const child=createBundleChildRow(row,component,parentKey,index);insertAfter(anchor,child);anchor=child;});
  renumberTxnRows();if(typeof recalc==='function')recalc();
}
function syncBundleForRow(row){
  if(!row || row.dataset.bundleChild==='1')return;
  const body=document.getElementById('entryRows');if(!body)return;
  const parentKey=row.querySelector('input[name="bundle_row_key[]"]')?.value||row.dataset.bundleRowKey||'';if(!parentKey)return;row.dataset.bundleRowKey=parentKey;
  removeBundleChildren(parentKey);
  if(document.body.dataset.txntype!=='sale')return;
  const sel=row.querySelector('.item-select'), itemId=Number(sel?.value||0);if(!itemId){row.dataset.bundleJson='[]';return;}
  let components=getRowBundle(row);
  if(components.length){row.dataset.bundleFetchedItem=String(itemId);renderBundleChildrenForRow(row,components);return;}
  if(row.dataset.bundleFetchedItem===String(itemId))return;
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
      const latestSel=row.querySelector('.item-select');if(!latestSel||Number(latestSel.value||0)!==itemId)return;
      const list=Array.isArray(data.items)?data.items:[];
      row.dataset.bundleJson=JSON.stringify(list);
      if(list.length)renderBundleChildrenForRow(row,list);
      else {removeBundleChildren(parentKey);if(typeof recalc==='function')recalc();}
    })
    .catch(function(err){row.dataset.bundleFetchPending='';console.error('Bundle API sync failed:',err);});
}
function updatePrice(sel){
  const row=sel?.closest('.sale-row'), opt=sel?.selectedOptions?.[0];
  if(row&&opt){
    const price=qs('.price',row),unit=qs('.unit-label',row);
    const txType=document.body.dataset.txntype||'sale';
    if(price)price.value=(txType==='purchase'?opt.dataset.buy:opt.dataset.sale)||0;
    if(unit)unit.textContent=opt.dataset.unit||'—';
    const desc=qs('.item-line-description',row),war=qs('.item-line-warranty',row);
    if(desc&&!desc.value)desc.value=opt.dataset.description||'';
    if(war&&!war.value)war.value=opt.dataset.warranty||'';
    renderSerialMeta(row);

    // Automatically keep one blank item row ready after the last selected
    // item, for both Sale and Purchase. Bundle child rows (Sale only) are
    // inserted before this blank row when applicable.
    if(row.dataset.bundleChild!=='1' && txType!==''){
      const mainRows=[...document.querySelectorAll('#entryRows .sale-row:not(.bundle-child-row)')];
      if(mainRows[mainRows.length-1]===row && typeof addRow==='function') addRow(txType);
    }

    if(txType==='sale')syncBundleForRow(row);
    recalc();
  }
}
function validateTransactionForm(){
  const rows=[...document.querySelectorAll('#entryRows .sale-row')];
  let validCount=0;
  for(const row of rows){
    const isChild=row.dataset.bundleChild==='1';
    const item=isChild ? (row.querySelector('input[type="hidden"][name="item_id[]"]')?.value||'') : (row.querySelector('.item-select')?.value||'');
    const qty=parseFloat(row.querySelector('.qty')?.value||0)||0;
    const price=parseFloat(row.querySelector('.price')?.value||0)||0;
    if(!item){ row.remove(); continue; }
    if(qty<=0){ row.querySelector('.qty')?.focus(); alert('Please enter a valid quantity.'); return false; }
    if(!isChild && price<0){ row.querySelector('.price')?.focus(); alert('Please enter a valid price.'); return false; }
    validCount++;
  }
  renumberTxnRows();
  if(validCount<1){ alert('Add at least one item.'); return false; }
  try{
    if(typeof window._validateTransactionFormOriginal==='function') return window._validateTransactionFormOriginal();
  }catch(e){ alert(e.message||String(e)); return false; }
  return true;
}
document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.sale-row').forEach(function(r){bindEntryRow(r)});
  document.querySelectorAll('.payment-line').forEach(function(r){bindPaymentLine(r);var m=r.querySelector('[name="pay_method[]"]');if(m)togglePaymentFields(m);});
  recalc();
});
</script>
<?php render_inline_creation_modals(); page_end(); exit; }

if($route==='transaction-save'&&$_SERVER['REQUEST_METHOD']==='POST'){
    check_csrf(); $type=$_POST['txn_type']??''; $txId=(int)($_POST['transaction_id']??0);
    if(!in_array($type,['sale','purchase'],true)){http_response_code(422);exit('Unsupported transaction type.');}
    $cid=(int)$u['company_id']; $saveAndPrint=!empty($_POST['save_and_print']) && $type==='sale'; $party=(int)($_POST['party_id']??0); $items=$_POST['item_id']??[]; $qtys=$_POST['qty']??[]; $prices=$_POST['price']??[]; $discs=$_POST['discount']??[]; $serialRows=$_POST['serial_numbers']??[]; $itemDescriptions=$_POST['item_description']??[]; $itemWarranties=$_POST['item_warranty']??[];
    if($type==='purchase' && $party<=0){flash('error','Supplier is required.');redirect('purchase-new');}
    $txnDate=transaction_datetime($_POST['txn_date']??null);
    $dueDate=$type==='sale'?null:(($_POST['due_date']??'')?:null);
    $partyParam=$party>0?$party:null;
    $sub=0;$itemDisc=0;$pdo=db();
    try{
        $pdo->beginTransaction();
        $roleNeeded=$type==='sale'?'customer':'supplier';
        if($party>0){
            $stParty=$pdo->prepare('SELECT p.id FROM parties p WHERE p.id=? AND p.company_id=? AND EXISTS(SELECT 1 FROM party_roles pr WHERE pr.party_id=p.id AND pr.role=?) LIMIT 1');$stParty->execute([$party,$cid,$roleNeeded]);$partyRow=$stParty->fetch();if(!$partyRow)throw new RuntimeException($type==='sale'?'Invalid customer.':'Invalid supplier.');
        } elseif($type==='purchase'){
            throw new RuntimeException('Supplier is required.');
        }
        $rowKeys=(array)($_POST['bundle_row_key']??[]); $parentKeys=(array)($_POST['bundle_parent_key']??[]); $childFlags=(array)($_POST['bundle_child']??[]);
        $rawRows=[]; $rowKeySeen=[];
        foreach($items as $i=>$iid){
            $iid=(int)$iid; $q=(float)($qtys[$i]??0); $p=(float)($prices[$i]??0); $d=max(0,(float)($discs[$i]??0)); if($iid<=0||$q<=0)continue;
            $rowKey=trim((string)($rowKeys[$i]??'')); if($rowKey==='')$rowKey='row-'.$i.'-'.bin2hex(random_bytes(4)); if(isset($rowKeySeen[$rowKey]))throw new RuntimeException('Duplicate bundle row key.'); $rowKeySeen[$rowKey]=true;
            $parentKey=trim((string)($parentKeys[$i]??'')); $isBundleChild=((int)($childFlags[$i]??0)===1);
            if($isBundleChild && $type!=='sale')throw new RuntimeException('Bundle free items are available only on sales.');
            $st=$pdo->prepare('SELECT * FROM items WHERE id=? AND company_id=? AND active=1 LIMIT 1');$st->execute([$iid,$cid]);$it=$st->fetch();if(!$it)throw new RuntimeException('Invalid item selected.');
            $rawRows[]=['index'=>$i,'iid'=>$iid,'q'=>$q,'p'=>$p,'d'=>$d,'rowKey'=>$rowKey,'parentKey'=>$parentKey,'isChild'=>$isBundleChild,'it'=>$it,'serials'=>normalize_serials((string)($serialRows[$i]??''))];
        }
        if(!$rawRows)throw new RuntimeException('Add at least one item.');
        $parentByKey=[]; foreach($rawRows as $rr){if(!$rr['isChild'])$parentByKey[$rr['rowKey']]=$rr['iid'];}
        $validItems=[]; $sub=0; $itemDisc=0;
        foreach($rawRows as $rr){
            $iid=$rr['iid'];$q=$rr['q'];$p=$rr['p'];$d=$rr['d'];$it=$rr['it'];$serials=$rr['serials'];$isBundleChild=$rr['isChild'];$parentItemId=0;
            if($isBundleChild){
                $parentKey=$rr['parentKey']; if($parentKey===''||!isset($parentByKey[$parentKey]))throw new RuntimeException('A bundle child item is missing its parent product.');
                $parentItemId=(int)$parentByKey[$parentKey]; $component=bundle_component_lookup($pdo,$cid,$parentItemId,$iid); if(!$component)throw new RuntimeException('Invalid included free item for the selected bundle product.');
                $parentQty=0; foreach($rawRows as $pr){if(!$pr['isChild']&&$pr['rowKey']===$parentKey){$parentQty=(float)$pr['q'];break;}}
                $expectedQty=round($parentQty*(float)$component['quantity'],6); if(abs($q-$expectedQty)>0.000001)throw new RuntimeException('Included free item quantity does not match the bundle quantity.'); $p=0;$d=0;
            }
            if($it['item_type']==='service'&&$q<=0)throw new RuntimeException('Invalid quantity.');
            $lineGross=$q*$p;if($d>$lineGross)$d=$lineGross;$sub+=$lineGross;$itemDisc+=$d;
            if((int)($it['serial_tracked']??0)===1&&$type==='purchase'){if(abs($q-round($q))>0.000001)throw new RuntimeException('Serial-tracked purchase quantity must be a whole number.');if(count($serials)!==(int)round($q))throw new RuntimeException('Serial numbers must match the purchase quantity for '.$it['name'].'.');}
            if((int)($it['serial_tracked']??0)===1&&$type==='sale'&&$serials&&count($serials)!==(int)round($q))throw new RuntimeException('Selected serial numbers must match the sale quantity for '.$it['name'].'.');
            $lineDescription=trim((string)($itemDescriptions[$rr['index']]??''));if($lineDescription==='')$lineDescription=trim((string)($it['description']??''));
            $lineWarranty=trim((string)($itemWarranties[$rr['index']]??''));if($lineWarranty==='')$lineWarranty=trim((string)($it['warranty']??''));
            $validItems[]=[$iid,$q,$p,$d,$it,$serials,$lineDescription,$lineWarranty,$rr['rowKey'],$rr['parentKey'],$isBundleChild,$parentItemId];
        }
        if(!$validItems)throw new RuntimeException('Add at least one item.');
        $invDisc=max(0,(float)($_POST['invoice_discount']??0));$tax=max(0,(float)($_POST['tax']??0));$direct=max(0,(float)($_POST['direct_expense']??0));
        $afterItem=max(0,$sub-$itemDisc);if($invDisc>$afterItem)$invDisc=$afterItem;$total=round(max(0,$afterItem-$invDisc+$tax+$direct),2);
        $paid=0;$paymentRows=[];$methods=$_POST['pay_method']??[];$amounts=$_POST['pay_amount']??[];$accounts=$_POST['pay_account']??[];$refs=$_POST['pay_ref']??($_POST['pay_reference']??[]);$cheqDates=$_POST['pay_cheque_date']??[];
        if($type==='sale' && !empty($_POST['received'])){
            if(!$methods)$methods=['cash'];
            if(!$amounts)$amounts=[0];
            $amounts[0]=$total;
            for($mi=1;$mi<count($methods);$mi++)$amounts[$mi]=0;
        }
        foreach($methods as $i=>$rawMethod){$a=max(0,(float)($amounts[$i]??0));if($a<=0)continue;[$m,$acct]=normalize_payment_method($pdo,$cid,(string)$rawMethod,trim($accounts[$i]??''));$ref=trim($refs[$i]??'');if($m==='cheque'&&$ref==='')throw new RuntimeException('Cheque number is required.');$paymentRows[]=[$m,$acct,$ref,$a];$paid+=$a;}
        if($paid>$total+0.01)throw new RuntimeException('Payment cannot be greater than invoice total.');
        $due=round(max(0,$total-$paid),2);
        if($type==='sale' && $party<=0 && $due>0.01)throw new RuntimeException('Cash Sale without Customer must be fully received. Tick Received to adjust the full payment.');
        $doc=trim($_POST['document_no']??'');
        if($txId>0){
            $st=$pdo->prepare('SELECT * FROM transactions WHERE id=? AND company_id=? AND txn_type=? AND deleted_at IS NULL LIMIT 1');$st->execute([$txId,$cid,$type]);$existingTx=$st->fetch();
            if(!$existingTx)throw new RuntimeException($type==='sale'?'Sales invoice not found.':'Purchase bill not found.');
            if($doc==='')$doc=$existingTx['document_no'];
            $pdo->prepare('UPDATE transactions SET party_id=?,document_no=?,txn_date=?,due_date=?,subtotal=?,item_discount=?,invoice_discount=?,tax=?,direct_expense=?,total=?,paid=?,due=?,status=?,notes=? WHERE id=? AND company_id=?')->execute([$partyParam,$doc,$txnDate,$dueDate,$sub,$itemDisc,$invDisc,$tax,$direct,$total,$paid,$due,$due>0?'open':'final',trim($_POST['notes']??''),$txId,$cid]);
            // Rebuild all child accounting/stock/payment rows from the edited form.
            $pdo->prepare('DELETE FROM payment_lines WHERE transaction_id=?')->execute([$txId]);
            $oldSerials=$pdo->prepare('SELECT tis.serial_id,isx.sale_transaction_id,isx.purchase_transaction_id FROM transaction_item_serials tis JOIN item_serials isx ON isx.id=tis.serial_id WHERE tis.transaction_id=? AND tis.company_id=?');$oldSerials->execute([$txId,$cid]);$oldSerialRows=$oldSerials->fetchAll();
            foreach($oldSerialRows as $os){if($type==='purchase' && !empty($os['sale_transaction_id']))throw new RuntimeException('This purchase cannot be edited because a serial number from it has already been sold.');}
            if($oldSerialRows){$pdo->prepare('UPDATE item_serials SET status=CASE WHEN purchase_transaction_id=? THEN "available" ELSE status END,sale_transaction_id=NULL WHERE id IN (SELECT serial_id FROM transaction_item_serials WHERE transaction_id=? AND company_id=?)')->execute([$txId,$txId,$cid]);}
            $pdo->prepare('DELETE FROM transaction_item_serials WHERE transaction_id=? AND company_id=?')->execute([$txId,$cid]);
            $pdo->prepare('DELETE FROM transaction_items WHERE transaction_id=?')->execute([$txId]);
            $pdo->prepare('DELETE FROM stock_movements WHERE company_id=? AND transaction_id=?')->execute([$cid,$txId]);
            $pdo->prepare('DELETE FROM ledger_entries WHERE company_id=? AND transaction_id=?')->execute([$cid,$txId]);
            $tid=$txId; $auditAction='update';
        }else{
            if($doc==='')$doc=next_document_in_transaction($pdo,$cid,$type,$type==='sale'?'SI-':'PB-');
            $pdo->prepare('INSERT INTO transactions(company_id,party_id,txn_type,document_no,txn_date,due_date,subtotal,item_discount,invoice_discount,tax,direct_expense,total,paid,due,currency_code,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$partyParam,$type,$doc,$txnDate,$dueDate,$sub,$itemDisc,$invDisc,$tax,$direct,$total,$paid,$due,$u['currency_code'],'final',trim($_POST['notes']??''),$u['id']]);
            $tid=(int)$pdo->lastInsertId(); $auditAction='create';
        }
        $ins=$pdo->prepare('INSERT INTO transaction_items(transaction_id,item_id,bundle_parent_transaction_item_id,qty,unit_price,discount,tax,amount,item_description,item_warranty) VALUES(?,?,?,?,?,?,?,?,?,?)');$stock=$pdo->prepare('INSERT INTO stock_movements(company_id,item_id,transaction_id,movement_date,quantity,movement_type,note) VALUES(?,?,?,?,?,?,?)');$serialLink=$pdo->prepare('INSERT INTO transaction_item_serials(company_id,transaction_id,transaction_item_id,serial_id,movement_type) VALUES(?,?,?,?,?)');
        $cogs=0;
        $savedParentByRowKey=[]; foreach($validItems as [$iid,$q,$p,$d,$it,$serials,$lineDescription,$lineWarranty,$rowKey,$parentKey,$isBundleChild,$parentItemId]){$lineTax=0;$amount=max(0,$q*$p-$d);$parentTxnItemId=($isBundleChild&&isset($savedParentByRowKey[$parentKey]))?(int)$savedParentByRowKey[$parentKey]:null;if($isBundleChild&&$parentTxnItemId===null)throw new RuntimeException('Bundle parent must appear before its included free item.');$ins->execute([$tid,$iid,$parentTxnItemId,$q,$p,$d,$lineTax,$amount,$lineDescription?:null,$lineWarranty?:null]);$tiId=(int)$pdo->lastInsertId();if(!$isBundleChild)$savedParentByRowKey[$rowKey]=$tiId;if($it['item_type']==='product'){
                $movementQty = $type==='sale' ? -1*$q : $q;
                $movementType = $type==='sale' ? 'sale' : 'purchase';
                $stock->execute([$cid,$iid,$tid,$txnDate,$movementQty,$movementType,$doc]);
                if($type==='sale') $cogs+=max(0,$q*(float)$it['purchase_price']);
                if((int)($it['serial_tracked']??0)===1){
                    if($type==='purchase'){
                        foreach($serials as $sn){$qsn=$pdo->prepare('SELECT id,status FROM item_serials WHERE company_id=? AND item_id=? AND serial_number=? FOR UPDATE');$qsn->execute([$cid,$iid,$sn]);$ex=$qsn->fetch();if($ex && $ex['status']!=='void')throw new RuntimeException('Serial number already exists for '.$it['name'].': '.$sn);if($ex){$pdo->prepare('UPDATE item_serials SET status="available",purchase_transaction_id=?,sale_transaction_id=NULL WHERE id=?')->execute([$tid,$ex['id']]);$sid=(int)$ex['id'];}else{$pdo->prepare('INSERT INTO item_serials(company_id,item_id,serial_number,status,purchase_transaction_id) VALUES(?,?,?,?,?)')->execute([$cid,$iid,$sn,'available',$tid]);$sid=(int)$pdo->lastInsertId();}$serialLink->execute([$cid,$tid,$tiId,$sid,'purchase']);}
                    }else{$needed=(int)round($q);$chosen=[];if($serials){foreach($serials as $sn){$qsn=$pdo->prepare('SELECT id,status FROM item_serials WHERE company_id=? AND item_id=? AND serial_number=? FOR UPDATE');$qsn->execute([$cid,$iid,$sn]);$sr=$qsn->fetch();if(!$sr||$sr['status']!=='available')throw new RuntimeException('Serial not available: '.$sn);$chosen[]=(int)$sr['id'];}}else{$qsn=$pdo->prepare('SELECT id FROM item_serials WHERE company_id=? AND item_id=? AND status="available" ORDER BY id LIMIT '.$needed.' FOR UPDATE');$qsn->execute([$cid,$iid]);$chosen=array_map('intval',$qsn->fetchAll(PDO::FETCH_COLUMN));if(count($chosen)<$needed)throw new RuntimeException('Not enough available serial numbers for '.$it['name'].'; available serial stock: '.count($chosen));}foreach($chosen as $sid){$pdo->prepare('UPDATE item_serials SET status="sold",sale_transaction_id=? WHERE id=? AND company_id=?')->execute([$tid,$sid,$cid]);$serialLink->execute([$cid,$tid,$tiId,$sid,'sale']);}}
                }
            }}
        $pl=$pdo->prepare('INSERT INTO payment_lines(transaction_id,method,account_name,reference_no,amount,status) VALUES(?,?,?,?,?,?)');foreach($paymentRows as [$m,$acct,$ref,$a]){$pl->execute([$tid,$m,$acct?:null,$ref?:null,$a,'completed']);}
        $lines=[];
        foreach($paymentRows as [$m,$acct,$ref,$a]){
            [$payCode,$payName]=payment_account_code($m,$acct);
            if($type==='sale'){
                $lines[]=[$payCode,$payName,$a,0,$doc];
            }else{
                $lines[]=[$payCode,$payName,0,$a,$doc];
            }
        }
        if($type==='sale'){
            if($due>0)$lines[]=['1200','Accounts Receivable',$due,0,$doc];
            $netSales=max(0,$afterItem-$invDisc);$lines[]=['4000','Sales Revenue',0,$netSales,$doc];
            if($tax>0)$lines[]=['2100','Tax Payable',0,$tax,$doc];
            if($direct>0)$lines[]=['4200','Direct Expense Recovery',0,$direct,$doc];
            if($cogs>0){$lines[]=['5100','Cost of Goods Sold',$cogs,0,$doc];$lines[]=['1300','Inventory',0,$cogs,$doc];}
        }else{
            if($due>0)$lines[]=['2100','Accounts Payable',0,$due,$doc];
            $netPurchase=max(0,$afterItem-$invDisc);
            $serviceCost=0;$productCost=0;
            foreach($validItems as [$iid,$q,$p,$d,$it,$serials,$lineDescription,$lineWarranty]){ $amt=max(0,$q*$p-$d); if($it['item_type']==='product')$productCost+=$amt; else $serviceCost+=$amt; }
            if($productCost+$direct>0)$lines[]=['1300','Inventory',$productCost+$direct,0,$doc];
            if($serviceCost>0)$lines[]=['5200','Purchase / Service Cost',$serviceCost,0,$doc];
            if($tax>0)$lines[]=['1400','Input VAT / Tax',$tax,0,$doc];
            if($invDisc>0)$lines[]=['4300','Purchase Discount',0,$invDisc,$doc];
        }
        $debit=0;$credit=0;foreach($lines as $l){$debit+=round((float)$l[2],2);$credit+=round((float)$l[3],2);} $diff=round($debit-$credit,2);if(abs($diff)>0.01)throw new RuntimeException('Accounting entry is not balanced.');
        post_ledger($pdo,$cid,$tid,$txnDate,$lines);audit($auditAction,'transaction',$tid,['type'=>$type,'document'=>$doc,'total'=>$total,'paid'=>$paid,'due'=>$due]);
        $pdo->commit();flash('success',($type==='sale'?'Sale ':'Purchase Bill ').$doc.($auditAction==='update'?' updated successfully.':' saved successfully.'));if($saveAndPrint){redirect('sales?view='.$tid.'&autoprint=1');}redirect($type==='sale'?'sales?view='.$tid:'purchase?view='.$tid);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();
        flash('error',$e->getMessage());
        $back=($type==='sale'?'sale-new':'purchase-new');
        redirect($txId>0 ? $back.'?edit='.$txId : $back);
    }
}



function qs(s,p=document){return p.querySelector(s)}
function qsa(s,p=document){return [...p.querySelectorAll(s)]}
function toggleMenu(id){const el=qs('#'+id);if(el)el.classList.toggle('show')}
function closeModal(id){const el=qs('#'+id);if(el)el.classList.remove('show')}
function openModal(id){const el=qs('#'+id);if(el)el.classList.add('show')}
function bindDots(){qsa('.dots').forEach(b=>b.addEventListener('click',e=>{e.stopPropagation();const m=b.nextElementSibling;qsa('.row-menu').forEach(x=>{if(x!==m)x.classList.remove('show')});m?.classList.toggle('show')}))}
function fmt(n){return '৳'+Number(n||0).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2})}
function currentInvoiceTotal(){
  const el=qs('#grandTotal');
  const txt=el?.textContent||'';
  const n=Number(String(txt).replace(/[^0-9.-]/g,''));
  return Number.isFinite(n)?Math.max(0,n):0;
}
function applyReceivedPayment(total){
  const toggle=qs('#receivedToggle');
  if(!toggle?.checked) return;
  const wrap=qs('#paymentRows');
  if(!wrap) return;
  let inputs=qsa('input[name=\"pay_amount[]\"]',wrap);
  if(!inputs.length){
    const row=qs('.payment-line',wrap);
    if(row){
      const input=document.createElement('input');
      input.type='number'; input.min='0'; input.step='0.01'; input.name='pay_amount[]'; input.placeholder='Amount';
      row.appendChild(input); inputs=[input];
    }
  }
  if(inputs[0]) inputs[0].value=(Math.max(0,Number(total)||0)).toFixed(2);
  inputs.slice(1).forEach(i=>{i.value='0';});
}
function recalc(){
  let sub=0;
  qsa('.sale-row').forEach(r=>{
    const qty=parseFloat(qs('.qty',r)?.value||0),price=parseFloat(qs('.price',r)?.value||0),disc=Math.max(0,parseFloat(qs('.line-disc',r)?.value||0));
    const amt=Math.max(0,qty*price-disc);
    if(qs('.amount',r))qs('.amount',r).textContent=fmt(amt);
    sub+=amt;
  });
  const inv=Math.max(0,parseFloat(qs('#invoiceDiscount')?.value||0));
  const tax=Math.max(0,parseFloat(qs('#tax')?.value||0));
  const direct=Math.max(0,parseFloat(qs('#directExpense')?.value||0));
  const total=Math.max(0,sub-inv+tax+direct);
  if(qs('#grandTotal'))qs('#grandTotal').textContent=fmt(total);
  if(qs('#subTotal'))qs('#subTotal').textContent=fmt(sub);
  applyReceivedPayment(total);
  let paid=0;
  qsa('[name=\"pay_amount[]\"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));
  if(qs('#paidPreview'))qs('#paidPreview').textContent=fmt(paid);
  if(qs('#duePreview'))qs('#duePreview').textContent=fmt(Math.max(0,total-paid));
}
function forceApplyReceived(){
  if(!qs('#receivedToggle')?.checked) return;
  const total=currentInvoiceTotal();
  applyReceivedPayment(total);
  let paid=0;
  qsa('[name=\"pay_amount[]\"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));
  if(qs('#paidPreview'))qs('#paidPreview').textContent=fmt(paid);
  if(qs('#duePreview'))qs('#duePreview').textContent=fmt(Math.max(0,total-paid));
}
function renumberRows(){qsa('#entryRows .sale-row').forEach((r,i)=>{const n=qs('.txn-row-number',r);if(n)n.textContent=i+1})}
function addRow(type){const rows=qs('#entryRows');if(!rows)return;const first=qs('.sale-row',rows);const clone=first.cloneNode(true);delete clone.dataset.autoNextRowCreated;qsa('.item-live-search',clone).forEach(b=>{delete b.dataset.itemSearchBound;});qsa('input',clone).forEach(i=>{if(i.classList.contains('qty'))i.value='1';else if(i.classList.contains('price')||i.classList.contains('line-disc'))i.value='0';else if(i.classList.contains('item-search-input'))i.value='';});qsa('select',clone).forEach(s=>s.selectedIndex=0);const clear=qs('.item-search-clear',clone);if(clear)clear.style.display='none';qs('.item-selected-summary',clone)?.remove();const unit=qs('.unit-label',clone);if(unit)unit.textContent='—';const st=qs('.serial-number-input',clone);if(st)st.value='';qs('.item-picker-cell',clone)?.classList.remove('serial-ready');const sb=qs('.serial-trigger-btn',clone);if(sb){sb.hidden=true;sb.textContent='Serial';}qs('.amount',clone).textContent=fmt(0);rows.appendChild(clone);bindEntryRow(clone);renumberRows();if(typeof window.SutoInitItemSearch==='function')window.SutoInitItemSearch(clone);}
function removeRow(btn){const rows=qsa('#entryRows .sale-row');if(rows.length<=1){const r=rows[0];qsa('select',r).forEach(x=>x.selectedIndex=0);qsa('input',r).forEach(x=>{if(x.classList.contains('qty'))x.value='1';else if(x.classList.contains('price')||x.classList.contains('line-disc'))x.value='0';else if(x.classList.contains('item-search-input'))x.value=''});qs('.item-search-clear',r)&&(qs('.item-search-clear',r).style.display='none');qs('.item-selected-summary',r)?.remove();const st=qs('.serial-number-input',r);if(st)st.value='';qs('.item-picker-cell',r)?.classList.remove('serial-ready');const sb=qs('.serial-trigger-btn',r);if(sb){sb.hidden=true;sb.textContent='Serial';}const u=qs('.unit-label',r);if(u)u.textContent='—';qs('.amount',r).textContent=fmt(0)}else{btn.closest('.sale-row')?.remove();renumberRows();}recalc()}
function removePayment(btn){const wrap=qs('#paymentRows');if(!wrap)return;const rows=qsa('.payment-line',wrap);if(rows.length<=1){const r=rows[0];if(r){qsa('input',r).forEach(i=>{if(i.name==='pay_amount[]')i.value='0';else if(i.name==='pay_cheque_date[]'||i.name==='pay_ref[]'||i.name==='pay_account[]')i.value='';});const m=qs('select[name=\"pay_method[]\"]',r);if(m){m.selectedIndex=0;togglePaymentFields(m);}}}else{const row=btn?.closest('.payment-line');if(row)row.remove();}recalc()}
function addPayment(){const wrap=qs('#paymentRows');if(!wrap)return;const first=qs('.payment-line',wrap);const clone=first.cloneNode(true);qsa('input',clone).forEach(i=>i.value='');qsa('select',clone).forEach(s=>s.selectedIndex=0);const acct=qs('.pay-account',clone);if(acct){acct.style.display='none';acct.name='pay_account[]'}const cd=qs('.pay-cheque-date',clone);if(cd){cd.style.display='none';cd.value=''}wrap.appendChild(clone);bindPaymentLine(clone);if(qs('#receivedToggle'))qs('#receivedToggle').checked=false;recalc()}
function togglePaymentFields(sel){const row=sel&&sel.closest('.payment-line,.std-payment-row,.std-pay-grid');if(!row)return;const method=sel.value||'';const cd=qs('.pay-cheque-date',row);const ref=qs('.pay-ref',row)||qs('[name="pay_reference[]"]',row)||qs('[name="pay_ref[]"]',row)||qs('.std-cheque-ref',row);const isCheque=method==='cheque';if(cd)cd.style.display=isCheque?'block':'none';if(ref)ref.style.display=isCheque?'block':'none';if(!isCheque&&ref&&ref.querySelector('input'))ref.querySelector('input').value='';}
function serialConfig(){return window.SutoSerialSearchConfig||{};}
function selectedSerialValues(row){const ta=qs('.serial-number-input',row);if(!ta)return [];return ta.value.split(/[\r\n,;]+/).map(v=>v.trim()).filter(Boolean)}
function serialMaxQty(row){const q=parseFloat(qs('.qty',row)?.value||0)||0;return Math.max(0,Math.round(q));}
function isSerialTracked(row){return qs('.item-select',row)?.selectedOptions?.[0]?.dataset?.serialTracked==='1';}
function renderSerialMeta(row){
  const cell=qs('.item-picker-cell',row),btn=qs('.serial-trigger-btn',row),ta=qs('.serial-number-input',row),sel=qs('.item-select',row);
  if(!cell||!btn||!ta||!sel)return;
  const tracked=isSerialTracked(row);
  cell.classList.toggle('serial-ready',tracked);
  btn.hidden=!tracked;
  if(!tracked){ta.value='';return;}
  const count=selectedSerialValues(row).length,max=serialMaxQty(row);
  btn.textContent='Serial'+(count?` (${count}/${max})`:'');
  btn.dataset.count=String(count);btn.dataset.max=String(max);
}
let serialModalState={row:null,values:[],available:[],mode:'sale'};
function serialModal(){return qs('#serialEntryModal');}
function closeSerialModal(){const m=serialModal();if(!m)return;m.hidden=true;m.setAttribute('aria-hidden','true');serialModalState={row:null,values:[],available:[],mode:'sale'};}
function serialApiUrl(itemId,q=''){const cfg=serialConfig();if(!cfg.url)return '';const u=new URL(cfg.url,window.location.origin);u.searchParams.set('item_id',String(itemId));if(q)u.searchParams.set('q',q);return u.toString();}
async function loadAvailableSaleSerials(q=''){
  const row=serialModalState.row,list=qs('#serialAvailableList');if(!row||!list)return;const sel=qs('.item-select',row),itemId=sel?.value||'';
  if(!itemId){list.innerHTML='<div class="serial-available-empty">Select an item first.</div>';return;}
  list.innerHTML='<div class="serial-available-loading">Loading available serial numbers...</div>';
  try{const url=serialApiUrl(itemId,q);if(!url)throw new Error('Serial API not configured');const res=await fetch(url,{headers:{'Accept':'application/json'},credentials:'same-origin'});const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Request failed');serialModalState.available=Array.isArray(data.items)?data.items:[];renderAvailableSaleSerials();}catch(e){list.innerHTML='<div class="serial-available-empty">Could not load serial numbers.</div>';}
}
function renderAvailableSaleSerials(){
  const list=qs('#serialAvailableList');if(!list)return;const selected=new Set(serialModalState.values.map(v=>v.toLowerCase()));const vals=serialModalState.available||[];
  if(!vals.length){list.innerHTML='<div class="serial-available-empty">No available serial numbers found.</div>';return;}
  list.innerHTML=vals.map(it=>{const sn=String(it.serial_number||'');const checked=selected.has(sn.toLowerCase());const safe=sn.replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));return '<label class="serial-available-option"><input type="checkbox" value="'+safe+'" '+(checked?'checked':'')+'><span>'+safe+'</span></label>';}).join('');
  list.querySelectorAll('input[type="checkbox"]').forEach(cb=>cb.addEventListener('change',()=>{const max=serialMaxQty(serialModalState.row),v=cb.value.trim();if(cb.checked){if(max>0&&serialModalState.values.length>=max){cb.checked=false;return;}serialModalState.values.push(v);}else{serialModalState.values=serialModalState.values.filter(x=>x.toLowerCase()!==v.toLowerCase());}renderSerialModal();renderAvailableSaleSerials();}));
}
async function openSerialModal(row){
  const m=serialModal(),sel=qs('.item-select',row),nameEl=qs('#serialEntryItemName'),title=qs('#serialEntryTitle'),input=qs('#serialEntryInput');if(!m||!sel||!isSerialTracked(row))return;
  const itemName=sel.selectedOptions?.[0]?.textContent?.trim()||'Item',mode=m.dataset.mode||document.body.dataset.txntype||'sale';serialModalState={row,values:selectedSerialValues(row),available:[],mode};
  if(title)title.textContent=(mode==='purchase'?'Purchase Item - Serial No.':'Sale Item - Serial No.');if(nameEl)nameEl.textContent=itemName;
  const help=qs('#serialEntryHelp');if(help)help.textContent=mode==='purchase'?'Enter one serial number for each purchased unit. Duplicate serial numbers are not allowed.':'Select the serial numbers to sell. Only currently available serial numbers for this item are shown.';
  const manual=qs('#serialManualInputLine'),picker=qs('#serialAvailableWrap');if(manual)manual.style.display=mode==='sale'?'none':'flex';if(picker)picker.classList.toggle('show',mode==='sale');const search=qs('#serialAvailableSearch');if(search)search.value='';renderSerialModal();m.hidden=false;m.setAttribute('aria-hidden','false');
  if(mode==='sale')await loadAvailableSaleSerials('');else if(input){input.value='';window.setTimeout(()=>input.focus(),50);}
}
function renderSerialModal(){
  const row=serialModalState.row,m=serialModal(),countEl=qs('#serialEntryCount'),list=qs('#serialEnteredList');if(!m||!row||!countEl||!list)return;const vals=serialModalState.values,max=serialMaxQty(row),mode=serialModalState.mode||m.dataset.mode||'sale';countEl.textContent=vals.length+'/'+max+(mode==='sale'?' Selected':' Entered');if(mode==='sale'){list.innerHTML='';return;}
  list.innerHTML=vals.map((v,i)=>'<div class="serial-entered-item"><span>'+String(v).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))+'</span><button type="button" data-index="'+i+'" aria-label="Remove serial">×</button></div>').join('');list.querySelectorAll('button[data-index]').forEach(b=>b.addEventListener('click',()=>{const i=Number(b.dataset.index);if(Number.isInteger(i)){serialModalState.values.splice(i,1);renderSerialModal();}}));const input=qs('#serialEntryInput'),add=qs('#serialEntryAdd');const disabled=max>0&&vals.length>=max;if(input)input.disabled=disabled;if(add)add.disabled=disabled;
}
function addSerialFromModal(){const row=serialModalState.row,input=qs('#serialEntryInput');if(!row||!input||serialModalState.mode==='sale')return;const value=input.value.trim();if(!value)return;const max=serialMaxQty(row);if(max>0&&serialModalState.values.length>=max)return;const vals=serialModalState.values;if(vals.some(v=>v.toLowerCase()===value.toLowerCase())){input.value='';input.focus();return;}vals.push(value);input.value='';renderSerialModal();input.focus();}
function saveSerialModal(){const row=serialModalState.row,m=serialModal();if(!row||!m)return;const vals=serialModalState.values.slice(),max=serialMaxQty(row),mode=serialModalState.mode||m.dataset.mode||'sale';if(mode==='purchase'&&max>0&&vals.length!==max){alert('Please enter '+max+' serial number(s) for this item.');return;}if(mode==='sale'&&vals.length>0&&max>0&&vals.length!==max){alert('Please select exactly '+max+' serial number(s), or leave all unselected for automatic selection.');return;}const ta=qs('.serial-number-input',row);if(ta)ta.value=vals.join('\n');renderSerialMeta(row);closeSerialModal();}
function bindSerialModal(){const m=serialModal();if(!m||m.dataset.bound==='1')return;m.dataset.bound='1';qs('#serialModalClose')?.addEventListener('click',closeSerialModal);qs('#serialModalCancel')?.addEventListener('click',closeSerialModal);qs('#serialEntryAdd')?.addEventListener('click',addSerialFromModal);qs('#serialModalSave')?.addEventListener('click',saveSerialModal);m.querySelector('.serial-entry-backdrop')?.addEventListener('click',closeSerialModal);qs('#serialEntryInput')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();addSerialFromModal();}else if(e.key==='Escape'){e.preventDefault();closeSerialModal();}});qs('#serialAvailableSearch')?.addEventListener('input',e=>{clearTimeout(window.__serialSearchTimer);window.__serialSearchTimer=setTimeout(()=>loadAvailableSaleSerials(e.target.value.trim()),220);});document.addEventListener('keydown',e=>{if(e.key==='Escape'&&serialModal()&&!serialModal().hidden)closeSerialModal();});}
function ensureNextTxnRow(row,txType){
  if(!row||row.dataset.bundleChild==='1'||!txType)return;
  if(row.dataset.autoNextRowCreated==='1')return;
  const mainRows=qsa('#entryRows .sale-row:not(.bundle-child-row)');
  if(!mainRows.length||mainRows[mainRows.length-1]!==row)return;
  row.dataset.autoNextRowCreated='1';
  const create=()=>{
    const latestRows=qsa('#entryRows .sale-row:not(.bundle-child-row)');
    if(!latestRows.length||latestRows[latestRows.length-1]!==row)return;
    const addButton=[...document.querySelectorAll('#entryRows + .entry-actions button, .entry-actions button')].find(btn=>String(btn.getAttribute('onclick')||'').includes("addRow('"+txType+"')"));
    if(addButton){addButton.click();return;}
    if(typeof addRow==='function')addRow(txType);
  };
  // Wait until the current live-search click/change cycle is complete.
  // This keeps the first automatically-created row completely idle and
  // prevents its search AJAX from firing during the parent selection event.
  window.setTimeout(create,0);
}
function updatePrice(sel){const row=sel?.closest('.sale-row');const opt=sel?.selectedOptions?.[0];if(row&&opt){const price=qs('.price',row);const unit=qs('.unit-label',row);const txType=document.body.dataset.txntype||'sale';if(price)price.value=(txType==='purchase'?opt.dataset.buy:opt.dataset.sale)||0;if(unit)unit.textContent=opt.dataset.unit||'—';const desc=qs('.item-line-description',row),war=qs('.item-line-warranty',row);if(desc&&!desc.value)desc.value=opt.dataset.description||'';if(war&&!war.value)war.value=opt.dataset.warranty||'';if(typeof window.SutoUpdateSerialUI==='function')window.SutoUpdateSerialUI(row,opt);ensureNextTxnRow(row,txType);if(txType==='sale'&&typeof window.syncBundleForRow==='function'&&row.dataset.bundleChild!=='1')window.syncBundleForRow(row);if(typeof recalc==='function')recalc()}}
function bindEntryRow(row){qsa('.qty,.price,.line-disc',row).forEach(x=>x.addEventListener('input',()=>{if(x.classList.contains('qty')){renderSerialMeta(row);if(document.body.dataset.txntype==='sale'&&row.dataset.bundleChild!=='1'&&typeof window.syncBundleForRow==='function')window.syncBundleForRow(row);}recalc()}));qs('.item-select',row)?.addEventListener('change',e=>updatePrice(e.target));qs('.serial-number-input',row)?.addEventListener('input',()=>renderSerialMeta(row));qs('.serial-trigger-btn',row)?.addEventListener('click',()=>openSerialModal(row));renderSerialMeta(row);}
function bindPaymentLine(row){qs('select[name="pay_method[]"]',row)?.addEventListener('change',e=>togglePaymentFields(e.target));qs('input[name="pay_amount[]"]',row)?.addEventListener('input',recalc)}
function validateTransactionForm(){const totalText=qs('#grandTotal')?.textContent||'৳0.00';const total=Number(totalText.replace(/[^0-9.-]/g,''))||0;let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(paid>total+0.01){alert('Payment cannot be greater than invoice total.');return false}return true}
function toggleStock(){const svc=qs('input[name=item_type]:checked')?.value==='service'||qs('#item_type')?.value==='service';qsa('.stock-field').forEach(x=>x.style.display=svc?'none':'block')}
document.addEventListener('click',e=>{
  const dot=e.target.closest('.dots');
  if(dot){
    e.preventDefault();
    e.stopPropagation();
    const menu=dot.parentElement?.querySelector('.row-menu') || dot.nextElementSibling;
    if(menu){
      qsa('.row-menu').forEach(x=>{if(x!==menu)x.classList.remove('show')});
      menu.classList.toggle('show');
    }
    return;
  }
  if(e.target.closest('.row-menu')) return;
  qsa('.row-menu').forEach(x=>x.classList.remove('show'));
  qs('#addMore')?.classList.remove('show');
});
document.addEventListener('change',e=>{const sel=e.target;if(!sel?.matches?.('#entryRows .item-source-select'))return;const row=sel.closest('.sale-row');if(!row||row.dataset.bundleChild==='1'||!sel.value)return;ensureNextTxnRow(row,document.body.dataset.txntype||'sale');});
document.addEventListener('DOMContentLoaded',()=>{bindDots();qsa('.qty,.price,.line-disc,[name="invoice_discount"],[name="tax"],[name="direct_expense"]').forEach(x=>x.addEventListener('input',recalc));qsa('.sale-row').forEach(bindEntryRow);qsa('.payment-line').forEach(bindPaymentLine);const received=qs('#receivedToggle');if(received){received.addEventListener('change',()=>{recalc();setTimeout(forceApplyReceived,0);});}document.addEventListener('change',e=>{if(e.target?.id==='receivedToggle')setTimeout(forceApplyReceived,0);});document.addEventListener('input',e=>{if(e.target?.matches?.('[name="pay_amount[]"]')&&qs('#receivedToggle')?.checked)forceApplyReceived();});qsa('#addMoreBtn').forEach(x=>x.addEventListener('click',e=>{e.stopPropagation();toggleMenu('addMore')}));qs('#item_type')?.addEventListener('change',toggleStock);toggleStock();recalc();setTimeout(forceApplyReceived,0)})
function addExpensePayment(){const wrap=qs('#expensePaymentRows');if(!wrap)return;const first=qs('.expense-payment-line',wrap);const clone=first.cloneNode(true);qsa('input',clone).forEach(i=>i.value='');qsa('select',clone).forEach(s=>s.selectedIndex=0);wrap.appendChild(clone);toggleExpensePayment(qs('select',clone));expenseRecalc();}
function toggleExpensePayment(sel){const row=sel?.closest('.expense-payment-line')||sel?.closest('.expense-entry-payment-row')||sel?.closest('.std-payment-row');if(!row)return;const ref=qs('.std-cheque-ref',row)||qs('.pay-ref',row)||qs('[name="pay_reference[]"]',row);const show=(sel.value||'')==='cheque';if(ref)ref.style.display=show?'block':'none';}
function expenseRecalc(){const total=parseFloat(qs('#expenseAmount')?.value||0)||0;let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(qs('#expenseTotalPreview'))qs('#expenseTotalPreview').textContent=fmt(total);if(qs('#expensePaidPreview'))qs('#expensePaidPreview').textContent=fmt(paid);if(qs('#expenseDuePreview'))qs('#expenseDuePreview').textContent=fmt(Math.max(0,total-paid));}
function validateExpenseForm(){const total=parseFloat(qs('#expenseAmount')?.value||0)||0;let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(paid>total+0.01){alert('Payment cannot be greater than expense amount.');return false}return true}
document.addEventListener('DOMContentLoaded',()=>{qs('#expenseAmount')?.addEventListener('input',expenseRecalc);qsa('[name="pay_amount[]"]').forEach(x=>x.addEventListener('input',expenseRecalc));qsa('.expense-payment-line select').forEach(toggleExpensePayment);expenseRecalc()});

/* v51: robust item action popup - always anchored to the 3-dot button and rendered in body */
(function(){
  var active = null;
  var originalParent = new WeakMap();
  var originalNext = new WeakMap();

  function closeMenu(){
    if(!active) return;
    var btn=active.btn, panel=active.panel, parent=originalParent.get(panel), next=originalNext.get(panel);
    if(panel){
      panel.hidden=true;
      panel.style.cssText='';
      if(parent){
        if(next && next.parentNode===parent) parent.insertBefore(panel,next);
        else parent.appendChild(panel);
      }
    }
    if(btn) btn.setAttribute('aria-expanded','false');
    active=null;
  }
  function placeMenu(btn,panel){
    if(!btn || !panel) return;
    panel.hidden=false;
    var r=btn.getBoundingClientRect();
    var gap=8, width=150, height=panel.offsetHeight||80;
    var left=r.right+gap;
    var top=r.top;
    if(left+width > window.innerWidth-8) left=Math.max(8,r.left-width-gap);
    if(top+height > window.innerHeight-8) top=Math.max(8,window.innerHeight-height-8);
    panel.style.position='fixed';
    panel.style.left=Math.round(left)+'px';
    panel.style.top=Math.round(top)+'px';
    panel.style.width=width+'px';
    panel.style.zIndex='2147483647';
  }
  function openMenu(btn){
    if(active && active.btn===btn){ closeMenu(); return; }
    closeMenu();
    var wrap=btn.closest('.item-master-actions');
    var panel=wrap && wrap.querySelector('.item-master-menu-panel');
    if(!panel) return;
    originalParent.set(panel,panel.parentNode);
    originalNext.set(panel,panel.nextSibling);
    panel.hidden=false;
    document.body.appendChild(panel);
    btn.setAttribute('aria-expanded','true');
    active={btn:btn,panel:panel};
    placeMenu(btn,panel);
    requestAnimationFrame(function(){ if(active && active.panel===panel) placeMenu(btn,panel); });
  }
  document.addEventListener('click',function(e){
    var btn=e.target.closest('.item-action-btn');
    if(btn){ e.preventDefault(); e.stopPropagation(); openMenu(btn); return; }
    if(active && e.target.closest('.item-master-menu-panel')) return;
    closeMenu();
  },true);
  window.addEventListener('resize',function(){if(active)placeMenu(active.btn,active.panel);});
  window.addEventListener('scroll',function(){if(active)placeMenu(active.btn,active.panel);},true);
})();



/* v104: Expense Item selector is handled from the shared external JS bundle.
   The previous page used inline onchange handlers, but the inline page script was
   not executing in the live page, producing "v98CategoryChanged is not defined".
   Keep these functions on window/global scope so inline HTML handlers can resolve. */
(function(){
  let expenseItemsCache = [];
  let expenseItemsLoaded = false;
  let expenseItemsLoading = null;

  function getExpenseCategoryId(){
    const cat = document.getElementById('expenseCategorySelect');
    return cat?.selectedOptions?.[0]?.dataset?.catId || '';
  }

  function populateExpenseItemSelect(sel, items){
    if(!sel) return;
    const current = sel.value || sel.dataset.selected || '';
    const catId = getExpenseCategoryId();
    let list = Array.isArray(items) ? items.slice() : [];
    if(catId){
      const linked = list.filter(it => String(it.category_id || '') === String(catId));
      if(linked.length) list = linked;
    }
    sel.innerHTML = '';
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = 'Select expense item';
    sel.appendChild(placeholder);
    for(const it of list){
      const o = document.createElement('option');
      o.value = String(it.name || '');
      o.textContent = String(it.name || '');
      o.dataset.catId = String(it.category_id || '');
      if(String(it.name || '') === String(current)) o.selected = true;
      sel.appendChild(o);
    }
    if(!list.length){
      const empty = document.createElement('option');
      empty.value = '';
      empty.disabled = true;
      empty.textContent = expenseItemsLoaded ? 'No expense items found' : 'Loading...';
      sel.appendChild(empty);
    }
  }

  async function loadExpenseItems(categoryId=''){
    if(expenseItemsLoaded && !categoryId){
      qsa('.v94-item-select').forEach(s => populateExpenseItemSelect(s, expenseItemsCache));
      return expenseItemsCache;
    }
    if(expenseItemsLoading && !categoryId) return expenseItemsLoading;
    const baseRoot = (document.querySelector('meta[name="base-url"]')?.getAttribute('content') || '').replace(/\/+$/, '');
    const base = baseRoot + '/public/expense-items-api.php';
    const url = categoryId ? `${base}?category_id=${encodeURIComponent(categoryId)}` : base;
    const req = fetch(url, {credentials:'same-origin', cache:'no-store', headers:{'Accept':'application/json'}})
      .then(async r => {
        const text = await r.text();
        if(!r.ok) throw new Error(`Expense item API HTTP ${r.status}`);
        let data;
        try { data = JSON.parse(text); } catch(e) { throw new Error('Expense item API returned non-JSON data'); }
        if(!data?.ok) throw new Error(data?.error || 'Expense item API failed');
        const items = Array.isArray(data.items) ? data.items : [];
        if(!categoryId) { expenseItemsCache = items; expenseItemsLoaded = true; }
        // The category-scoped API is authoritative for the selected category.
        const list = categoryId ? items : expenseItemsCache;
        qsa('.v94-item-select').forEach(s => populateExpenseItemSelect(s, list));
        return list;
      })
      .catch(err => {
        console.error('Expense item selector:', err);
        qsa('.v94-item-select').forEach(s => {
          s.innerHTML = '';
          const o = document.createElement('option');
          o.value = '';
          o.textContent = 'No expense items found';
          o.disabled = true;
          s.appendChild(o);
        });
        return [];
      });
    if(!categoryId) expenseItemsLoading = req;
    try { return await req; } finally { if(!categoryId) expenseItemsLoading = null; }
  }

  window.v98CategoryChanged = function(sel){
    const cat = sel || document.getElementById('expenseCategorySelect');
    const type = cat?.selectedOptions?.[0]?.dataset?.expenseType || 'indirect';
    const hidden = document.getElementById('expenseTypeHidden');
    if(hidden) hidden.value = type;
    const catId = cat?.selectedOptions?.[0]?.dataset?.catId || '';
    loadExpenseItems(catId);
    if(typeof window.v94Recalc === 'function') window.v94Recalc();
  };
  window.v98ItemChanged = function(){
    if(typeof window.v94Recalc === 'function') window.v94Recalc();
  };
  window.v94CategoryChanged = window.v98CategoryChanged;
  window.v94ItemChanged = window.v98ItemChanged;

  document.addEventListener('DOMContentLoaded', function(){
    if(!document.getElementById('expenseEntryForm')) return;
    loadExpenseItems('');
    const cat = document.getElementById('expenseCategorySelect');
    if(cat){
      window.v98CategoryChanged(cat);
      cat.addEventListener('change', function(){ window.v98CategoryChanged(this); });
    }
  });
})();

/* v86 expense editor helpers */
function addExpenseRow(){
  const body=qs('#expenseItemRows'); if(!body) return;
  const row=qs('.expense-item-row',body); if(!row) return;
  const clone=row.cloneNode(true);
  qsa('input',clone).forEach((x,i)=>{ if(x.name==='item_name[]') x.value=''; else if(x.name==='item_qty[]') x.value='1'; else x.value='0'; });
  const amount=qs('.expense-line-amount',clone); if(amount) amount.textContent='৳0.00';
  body.appendChild(clone); renumberExpenseRows(); expenseEntryRecalc();
}
function removeExpenseRow(btn){
  const rows=qsa('#expenseItemRows .expense-item-row');
  if(rows.length<=1){ qsa('input',rows[0]||document).forEach(x=>{if(x.name==='item_name[]')x.value='';else if(x.name==='item_qty[]')x.value='1';else if(x.name==='item_price[]')x.value='0'}); }
  else btn.closest('.expense-item-row')?.remove();
  renumberExpenseRows(); expenseEntryRecalc();
}
function renumberExpenseRows(){qsa('#expenseItemRows .expense-item-row').forEach((r,i)=>{const n=qs('.row-no',r);if(n)n.textContent=i+1})}
function addExpensePaymentEntry(){
  const wrap=qs('#expenseEntryPayments'); if(!wrap) return;
  const row=qs('.expense-entry-payment-row',wrap); if(!row) return;
  const clone=row.cloneNode(true); qsa('input',clone).forEach(x=>x.value=x.name==='pay_amount[]'?'0':''); qsa('select',clone).forEach((x,i)=>x.selectedIndex=0);
  const acct=qs('.expense-entry-bank',clone); if(acct)acct.style.display='none'; wrap.appendChild(clone); bindExpenseEntryPayment(clone); expenseEntryRecalc();
}
function removeExpensePaymentEntry(btn){const rows=qsa('#expenseEntryPayments .expense-entry-payment-row');if(rows.length<=1){const r=rows[0];if(r){qsa('input',r).forEach(x=>x.value=x.name==='pay_amount[]'?'0':'');}}else btn.closest('.expense-entry-payment-row')?.remove();expenseEntryRecalc()}
function bindExpenseEntryPayment(row){qs('select[name="pay_method[]"]',row)?.addEventListener('change',e=>toggleExpensePayment(e.target));qs('input[name="pay_amount[]"]',row)?.addEventListener('input',expenseEntryRecalc)}
function expenseEntryRecalc(){
  let total=0;
  qsa('#expenseItemRows .expense-item-row').forEach(r=>{const q=Math.max(0,parseFloat(qs('.expense-line-qty',r)?.value||0)),p=Math.max(0,parseFloat(qs('.expense-line-price',r)?.value||0));const a=Math.round(q*p*100)/100;total+=a;const out=qs('.expense-line-amount',r);if(out)out.textContent=fmt(a)});
  const amt=qs('#expenseAmount');if(amt)amt.value=total.toFixed(2);
  let paid=0;qsa('#expenseEntryPayments [name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));
  const due=Math.max(0,total-paid);
  if(qs('#expenseGrandTotal'))qs('#expenseGrandTotal').textContent=fmt(total);
  if(qs('#expenseTotalPreview'))qs('#expenseTotalPreview').textContent=fmt(total);
  if(qs('#expensePaidPreview'))qs('#expensePaidPreview').textContent=fmt(paid);
  if(qs('#expenseDuePreview'))qs('#expenseDuePreview').textContent=fmt(due);
}
function toggleExpensePayment(sel){const row=sel?.closest('.expense-entry-payment-row') || sel?.closest('.expense-payment-line');const acct=row&&qs('.expense-entry-bank',row);if(acct)acct.style.display=sel.value==='bank'?'block':'none';const old=row&&qs('.expense-bank-field',row);if(old)old.style.display=sel.value==='bank'?'block':'none'}
function validateExpenseForm(){expenseEntryRecalc();const total=parseFloat(qs('#expenseAmount')?.value||0)||0;let paid=0;qsa('[name="pay_amount[]"]').forEach(x=>paid+=Math.max(0,parseFloat(x.value||0)));if(total<=0){alert('Please add at least one expense item with a valid price.');return false}if(paid>total+0.01){alert('Payment cannot be greater than expense amount.');return false}return true}
document.addEventListener('DOMContentLoaded',()=>{if(qs('#expenseEntryForm')){qsa('#expenseItemRows .expense-line-qty,#expenseItemRows .expense-line-price').forEach(x=>x.addEventListener('input',expenseEntryRecalc));qsa('#expenseEntryPayments .expense-entry-payment-row').forEach(bindExpenseEntryPayment);qsa('#expenseEntryPayments select[name="pay_method[]"]').forEach(toggleExpensePayment);renumberExpenseRows();expenseEntryRecalc();}});

/* v130 payment-method UI: Cash, Cheque, active Bank Accounts only. */
document.addEventListener('DOMContentLoaded',()=>{bindSerialModal();qsa('select[name="pay_method[]"]').forEach(s=>togglePaymentFields(s));});

/* v140: sidebar collapse handler bound to the actual globally-loaded assets/app.js */
(function(){
  function applySidebarState(collapsed){
    document.body.classList.toggle('sidebar-collapsed', !!collapsed);
    var b=document.getElementById('sidebarCollapseBtn');
    if(b){
      b.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      b.setAttribute('aria-label', collapsed ? 'Expand menu' : 'Collapse menu');
      var icon=b.querySelector('.collapse-icon'); if(icon) icon.textContent = collapsed ? '›' : '‹';
    }
  }
  function initSidebarCollapse(){
    var b=document.getElementById('sidebarCollapseBtn');
    if(!b) return;
    if(b.dataset.sidebarCollapseBound==='1') return;
    b.dataset.sidebarCollapseBound='1';
    var collapsed=false;
    try{ collapsed=localStorage.getItem('suto_sidebar_collapsed')==='1'; }catch(e){}
    applySidebarState(collapsed);
    b.addEventListener('click', function(e){
      e.preventDefault();
      e.stopPropagation();
      var next=!document.body.classList.contains('sidebar-collapsed');
      try{ localStorage.setItem('suto_sidebar_collapsed', next ? '1' : '0'); }catch(e){}
      applySidebarState(next);
    });
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',initSidebarCollapse); else initSidebarCollapse();
})();
